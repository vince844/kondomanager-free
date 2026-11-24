<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale\Accessori;

use App\Helpers\MoneyHelper;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\RegolaRecupero;
use App\Models\Gestione;
use App\Services\Gestionale\Addebiti\AddebitiIndividuali;
use App\Services\Gestionale\Debiti\Scadenza;
use App\Services\Gestionale\Incassi\OrdineImputazione;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * La proposta degli interessi di mora a una data, per le persone scelte, e la loro registrazione. Il programma propone, l'amministratore decide: niente
 * si registra senza il suo clic, e quello che registra diventa un addebito, una rata per persona, unità e gestione, «Interessi di mora al …» e, con un
 * tasso scritto, il tasso con il suo riferimento.
 *
 * La base, riga per riga (rata e unità): il capitale delle rate scadute e non versate, letto dal giornale come la lettura comune dei debiti (le righe
 * della persona su «Crediti verso condomini» con la rata e l'unità, e sugli «Anticipi», fino alla data; lo storno alla data della sua rettifica). Dentro:
 * le rate dei piani e i contributi addebitati. Fuori: gli interessi e le spese addebitati (art. 1283 c.c.), e le quote di un inquilino, che si contano a
 * parte e non si propongono. I crediti e gli anticipi della persona coprono le rate più vecchie dal giorno dopo quello in cui sono entrati. Una rata con
 * interessi già registrati riparte dal giorno dopo l'ultima data registrata: gli stessi giorni non si chiedono due volte. Una rata coperta da un accordo di
 * rientro in regola resta nella proposta fino al giorno prima dell'accordo (decisione 438, {@see \App\Services\Gestionale\Debiti\AccordiRientro::senzaInteressi()});
 * coperta solo in parte, sulla parte fuori dall'accordo gli interessi continuano, salvo la scelta dell'accordo di sospenderli (decisione 450). Con la
 * successione e la scelta «si fermano al decesso» (decisione 483), le rate del defunto sulle unità del passaggio si fermano al giorno prima del decesso.
 *
 * Il calcolo è {@see InteressiDiMora}; il tasso e i giorni sono le regole del recupero del condominio ({@see RegoleDelRecupero}). Il tasso legale di un
 * anno scritto a mano dallo studio ({@see TassoLegaleAMano}) la proposta lo dice, con chi l'ha scritto, quando e la fonte.
 */
final class PropostaInteressi
{
    /** Le persone lette insieme: la memoria resta quella di un gruppo. */
    private const BLOCCO = 250;

    /** La causale degli interessi più lunga: la scrittura di emissione la ripete dopo «Addebito: », in 255 caratteri. */
    private const CAUSALE_MASSIMA = 245;

    public function __construct(private readonly AddebitiIndividuali $addebiti) {}

    /**
     * La lettura del debito ereditato di questa proposta: una per chiamata (P1 della lente P della beta.12), o quella di chi chiama nella stessa pagina
     * («Rate scadute», {@see \App\Services\Gestionale\Debiti\ElencoRateScadute::debitoEreditato()}). Mai tenuta fra due chiamate: la registrazione rifà la
     * proposta dopo i blocchi, e una lettura di prima darebbe cifre vecchie.
     */
    private ?DebitoEreditato $ereditato = null;

    /**
     * La proposta alla data per le persone date (tutte del condominio, già controllate da chi chiama).
     *
     * @param list<int> $persone
     * @return array{alla: string, persone: list<array<string, mixed>>, totale: int, gestioni: list<array<string, mixed>>, regole: array<string, mixed>, frase_domanda_giudiziale: string, tassi_a_mano: list<string>}
     */
    public function proponi(Condominio $condominio, string $alla, array $persone): array
    {
        $regole = (new RegoleDelRecupero())->delCondominio((int) $condominio->id);
        $this->ereditato = new DebitoEreditato();
        // Decisione 489: gli eredi entrano nella proposta quando entra il defunto; un erede porta il defunto, per i suoi interessi fino al decesso.
        ['persone' => $persone, 'solo_unita' => $soloUnita] = $this->ereditato->perLaProposta((int) $condominio->id, array_values(array_unique(array_map('intval', $persone))));
        $esito = [];
        foreach (array_chunk(array_values(array_unique($persone)), self::BLOCCO) as $blocco) {
            foreach ($this->perBlocco((int) $condominio->id, $alla, $blocco, $regole) as $p) {
                if (($p = self::soloSulleUnita($p, $soloUnita)) !== null) {
                    $esito[] = $p;
                }
            }
        }
        usort($esito, fn ($a, $b) => [-$a['totale'], $a['nome'], $a['anagrafica_id']] <=> [-$b['totale'], $b['nome'], $b['anagrafica_id']]);
        $gestioni = collect($esito)->flatMap(fn ($p) => array_column($p['righe'], 'gestione_id'))->unique()->values()->all();

        return [
            'alla' => $alla,
            'persone' => $esito,
            'totale' => (int) array_sum(array_column($esito, 'totale')),
            'gestioni' => $this->doveSiScrive($condominio, $alla, $gestioni),
            'regole' => [
                'righe' => RegoleDelRecupero::perLaPagina($regole),
                'di_oggi' => RegoleDelRecupero::alGiorno($regole, $alla) === null ? RegoleDelRecupero::FRASE_SENZA_REGOLE : null,
                // L'avviso delle fonti dei tassi scritti del condominio (la delibera, il regolamento): accanto, mai un blocco.
                'avviso' => $regole->filter(fn (RegolaRecupero $r) => $r->tasso_tipo === RegolaRecupero::TASSO_SCRITTO)
                    ->map(fn (RegolaRecupero $r) => RegoleDelRecupero::avvisoDellaFonte($r->fonte))->filter()->unique()->sort()->implode(' ') ?: null,
            ],
            'frase_domanda_giudiziale' => RegoleDelRecupero::FRASE_DOMANDA_GIUDIZIALE,
            'tassi_a_mano' => self::tassiAMano($esito),
        ];
    }

    /**
     * I tassi legali scritti a mano dallo studio che la proposta usa: per ogni anno la frase con il tasso, chi l'ha scritto, quando e la fonte.
     *
     * @param list<array<string, mixed>> $persone
     * @return list<string>
     */
    private static function tassiAMano(array $persone): array
    {
        $anni = [];
        foreach ($persone as $p) {
            foreach ($p['righe'] as $r) {
                foreach ($r['tratti'] as $t) {
                    if ($t['fonte'] === RegoleDelRecupero::FONTE_LEGALE_A_MANO) {
                        $anni[(int) substr((string) $t['dal'], 0, 4)] = true;
                    }
                }
            }
        }
        if ($anni === []) {
            return [];
        }
        ksort($anni);
        $scritti = TassoLegaleAMano::perAnno();

        return array_values(array_map(fn (int $anno) => TassoLegaleAMano::frase($scritti[$anno]), array_filter(array_keys($anni), fn (int $anno) => isset($scritti[$anno]))));
    }

    /**
     * Quante delle persone date hanno interessi da proporre alla data: la stessa proposta, contata.
     *
     * @param list<int> $persone
     * @return list<int> le persone con interessi da proporre
     */
    public function conInteressi(Condominio $condominio, string $alla, array $persone): array
    {
        return $this->perSuggerimenti($condominio, $alla, $persone)['con'];
    }

    /**
     * La stessa proposta, contata per i suggerimenti: chi ha interessi da proporre, e chi ha solo righe ferme (un anno senza il tasso legale), con la
     * prima frase del perché.
     *
     * @param list<int> $persone
     * @param DebitoEreditato|null $ereditato la lettura del debito ereditato della pagina che chiede, se c'è (P1)
     * @return array{con: list<int>, fermi: list<int>, perche: ?string}
     */
    public function perSuggerimenti(Condominio $condominio, string $alla, array $persone, ?DebitoEreditato $ereditato = null): array
    {
        $regole = (new RegoleDelRecupero())->delCondominio((int) $condominio->id);
        $esito = ['con' => [], 'fermi' => [], 'perche' => null];
        $this->ereditato = $ereditato ?? new DebitoEreditato();
        // Decisione 489: come la proposta, con il defunto e i suoi eredi.
        ['persone' => $persone, 'solo_unita' => $soloUnita] = $this->ereditato->perLaProposta((int) $condominio->id, array_values(array_unique(array_map('intval', $persone))));
        foreach (array_chunk(array_values(array_unique($persone)), self::BLOCCO) as $blocco) {
            foreach ($this->perBlocco((int) $condominio->id, $alla, $blocco, $regole) as $p) {
                if (($p = self::soloSulleUnita($p, $soloUnita)) === null) {
                    continue;
                }
                if ($p['totale'] > 0) {
                    $esito['con'][] = (int) $p['anagrafica_id'];
                } elseif (($fermo = collect($p['righe'])->pluck('fermo')->filter()->first()) !== null) {
                    $esito['fermi'][] = (int) $p['anagrafica_id'];
                    $esito['perche'] ??= (string) $fermo;
                }
            }
        }

        return $esito;
    }

    /**
     * Il defunto portato nella proposta da un suo erede ({@see DebitoEreditato::perLaProposta()}): solo le righe sulle unità della successione, i suoi
     * interessi fino al decesso; senza, non c'è. Le altre persone come sono.
     *
     * @param array<string, mixed> $p
     * @param array<int, list<int>> $soloUnita
     * @return array<string, mixed>|null
     */
    private static function soloSulleUnita(array $p, array $soloUnita): ?array
    {
        $unita = $soloUnita[(int) $p['anagrafica_id']] ?? null;
        if ($unita === null) {
            return $p;
        }
        $p['righe'] = array_values(array_filter($p['righe'], fn ($r) => in_array((int) ($r['immobile_id'] ?? 0), $unita, true)));
        if ($p['righe'] === []) {
            return null;
        }
        $p['totale'] = (int) array_sum(array_column(array_filter($p['righe'], fn ($r) => $r['fermo'] === null), 'interessi'));

        return $p;
    }

    /** Il perché delle cifre cambiate: da cinque a 255 caratteri (decisione 493 (10)). */
    public const MOTIVO_MINIMO = 5;

    public const MOTIVO_MASSIMO = 255;

    /** Le frasi dell'ultima registrazione oltre al conto degli addebiti: gli interessi del defunto passati agli eredi (decisione 493 (13)). */
    public array $frasiDellaRegistrazione = [];

    /**
     * Registra le righe tenute della proposta: la proposta si rifà sul server alla stessa data, e se le cifre delle righe tenute non sono quelle che
     * l'amministratore ha visto, niente si scrive. Una rata per persona, unità e gestione, nel piano degli addebiti della gestione dove si scrive, sulla
     * voce scelta per quella gestione.
     *
     * Decisione 493 (10)–(11): l'amministratore può registrare una riga con una cifra più bassa di quella proposta, da 1 centesimo alla proposta, con il
     * perché scritto; la riga tiene la cifra registrata (`interessi`) e quella proposta (`proposti`), l'addebito il perché, chi e quando (`cambiati`). I
     * giorni della riga restano registrati: la differenza non si chiede più.
     *
     * Decisione 493 (13): gli interessi del defunto fermi al decesso, con l'arretrato agli eredi e la scelta «vanno agli eredi», si registrano al defunto e
     * nello stesso passo passano agli eredi per quota, come gli interessi già addebitati alla successione ({@see \App\Services\Subentro\AccessoriAgliEredi}):
     * il registro del passaggio li tiene, e l'annullamento della successione li rimette al defunto.
     *
     * @param list<string> $righe le chiavi delle righe tenute («persona|rata|unità», con «|s…» per il debito ereditato)
     * @param array<int, int> $voci gestione dove si scrive => voce che riceve il denaro
     * Decisione 512: al posto della cifra, il tasso (in decimillesimi di punto, 1,00 % = 10.000): la riga si rifà sugli stessi giorni e tratti con quel tasso,
     * mai più alto di quello delle regole in nessun tratto; la riga tiene anche il tasso usato (`tasso`).
     *
     * @param array<string, int> $importi chiave => la cifra da registrare, in centesimi, per le sole righe cambiate
     * @param array<string, int> $tassi chiave => il tasso da usare, in decimillesimi di punto, per le sole righe cambiate con il tasso
     * @return list<\App\Models\Gestionale\Rata>
     */
    public function registra(Condominio $condominio, string $alla, array $righe, int $atteso, array $voci, ?int $utenteId, array $importi = [], ?string $motivo = null,
        array $tassi = []): array
    {
        $this->frasiDellaRegistrazione = [];
        $persone = array_values(array_unique(array_map(fn (string $k) => (int) explode('|', $k)[0], $righe)));
        // La proposta rifatta, e le righe tenute con le cifre che l'amministratore ha visto; altrimenti niente si scrive.
        $rifai = function () use ($condominio, $alla, $persone, $righe, $atteso): array {
            $proposta = $this->proponi($condominio, $alla, $persone);
            $tenute = [];
            foreach ($proposta['persone'] as $p) {
                foreach ($p['righe'] as $r) {
                    if (in_array($r['chiave'], $righe, true) && $r['interessi'] > 0 && $r['fermo'] === null) {
                        $tenute[$r['chiave']] = $r + ['anagrafica_id' => $p['anagrafica_id']];
                    }
                }
            }
            if (count($tenute) !== count(array_unique($righe)) || (int) array_sum(array_column($tenute, 'interessi')) !== $atteso) {
                throw ValidationException::withMessages(['interessi' => 'Nel frattempo le cifre sono cambiate (un versamento, un credito, una regola, interessi già registrati): rileggi la proposta.']);
            }

            return [$proposta, $tenute];
        };
        [$proposta, $tenute] = $rifai();
        $tenute = self::cifreCambiate($tenute, $importi, $motivo, $tassi);
        $cambiati = collect($tenute)->contains(fn ($r) => isset($r['proposti']));
        $chi = $cambiati ? (string) (\App\Models\User::find($utenteId)?->name ?? '') : '';
        $dove = collect($proposta['gestioni'])->keyBy('gestione_id');
        $gruppi = [];
        foreach ($tenute as $r) {
            $d = $dove[$r['gestione_id']] ?? null;
            if ($d === null || $d['scrive'] === null) {
                throw ValidationException::withMessages(['interessi' => $d['perche'] ?? 'Nessun esercizio aperto contiene il ' . self::giorno($alla) . ': gli interessi non si scrivono.']);
            }
            $g = $d['scrive'];
            $gruppi[$g['gestione_id'] . '|' . $g['esercizio_id']][$r['anagrafica_id'] . '|' . $r['immobile_id']][] = $r;
        }
        $causali = $this->causali($condominio, $alla, $gruppi, $proposta['persone']);
        $oggi = \App\Helpers\DateHelper::oggiUtente();

        $rate = [];
        DB::transaction(function () use ($condominio, $alla, $gruppi, $causali, $voci, $utenteId, $rifai, $motivo, $chi, $oggi, &$rate) {
            // Due registrazioni insieme degli stessi giorni: la seconda aspetta i piani degli addebiti della prima, poi rifà la proposta e trova gli
            // interessi già registrati.
            PianoRate::degliAddebiti()->where('condominio_id', $condominio->id)->lockForUpdate()->get(['id']);
            $rifai();
            foreach ($gruppi as $chiave => $perUnita) {
                [$gestioneId, $esercizioId] = array_map('intval', explode('|', $chiave));
                $gestione = Gestione::findOrFail($gestioneId);
                $esercizio = Esercizio::findOrFail($esercizioId);
                if (! isset($voci[$gestioneId])) {
                    throw ValidationException::withMessages(['voci' => 'Scegli la voce di entrata che riceve gli interessi di «' . $gestione->nome . '» quando sono versati.']);
                }
                $daScrivere = [];
                foreach ($perUnita as $personaUnita => $lista) {
                    [$persona, $unita] = array_map('intval', explode('|', $personaUnita));
                    $fonti = collect($lista)->flatMap(fn ($r) => array_column($r['tratti'], 'fonte'))->unique()->values();
                    $regola = $this->regolaScritta($condominio, $lista);
                    $cambiate = collect($lista)->contains(fn ($r) => isset($r['proposti']));
                    $daScrivere[] = [
                        'anagrafica_id' => $persona, 'immobile_id' => $unita, 'importo' => (int) array_sum(array_column($lista, 'interessi')),
                        'causale' => $causali[$chiave][$personaUnita], 'scadenza' => $alla, 'natura' => AddebitiIndividuali::NATURA_ACCESSORIO,
                        'tipo_accessorio' => 'interessi', 'conto_id' => (int) $voci[$gestioneId],
                        'base' => $regola === null ? 'legge' : (string) $regola->fonte,
                        'riferimento' => $regola === null ? 'artt. 1224 e 1284 c.c.' : RegoleDelRecupero::fonteScritta($regola),
                        'interessi' => [
                            'alla' => $alla,
                            'fonti' => $fonti->all(),
                            'fonte_delibera' => $regola?->fonte === 'delibera',
                            // `al`: l'ultimo giorno della riga, il giorno prima di un accordo di rientro che la tiene fuori (decisione 438) o la data.
                            // `ereditata`: la riga del debito ereditato (decisione 489); `proposti`: la cifra proposta, se l'amministratore l'ha cambiata.
                            // `tasso`: il tasso scelto al posto di quello delle regole (decisione 512), con la sua etichetta.
                            'righe' => array_map(fn ($r) => array_filter(array_intersect_key($r, array_flip(['rata_id', 'immobile_id', 'rata', 'scadenza', 'dal', 'al',
                                'regola_inizio', 'tratti', 'interessi', 'proposti', 'ereditata', 'tasso'])), fn ($v, $k) => ! in_array($k, ['proposti', 'ereditata', 'tasso'], true) || $v !== null,
                                ARRAY_FILTER_USE_BOTH), $lista),
                        ] + ($cambiate ? ['cambiati' => ['motivo' => trim((string) $motivo), 'da' => $chi, 'il' => $oggi]] : []),
                    ];
                }
                foreach ($this->addebiti->registra($condominio, $gestione, $esercizio, $daScrivere, $utenteId, $alla) as $r) {
                    $rate[] = $r;
                }
            }
            $this->interessiDelDefuntoAgliEredi($condominio, $alla, $rate, $utenteId);
        });

        return $rate;
    }

    /**
     * Le cifre cambiate prima di registrare (decisione 493 (10)): solo in meno, da 1 centesimo alla cifra proposta, e con il perché. Una cifra uguale alla
     * proposta non è cambiata. Le righe cambiate tengono la cifra proposta in `proposti`.
     *
     * Decisione 512: o il tasso, solo in meno: non più alto di quello delle regole in nessun tratto della riga. La cifra è la riga rifatta sugli stessi giorni
     * e tratti con quel tasso ({@see InteressiDiMora::arrotonda()}); la riga tiene il tasso usato (`tasso`).
     *
     * @param array<string, array<string, mixed>> $tenute
     * @param array<string, int> $importi
     * @param array<string, int> $tassi
     * @return array<string, array<string, mixed>>
     */
    private static function cifreCambiate(array $tenute, array $importi, ?string $motivo, array $tassi = []): array
    {
        $cambiate = 0;
        foreach ($tassi as $chiave => $e4) {
            if (isset($importi[$chiave])) {
                throw ValidationException::withMessages(['tassi' => 'Per una riga cambia la cifra o il tasso, non tutti e due.']);
            }
            if (! isset($tenute[$chiave])) {
                throw ValidationException::withMessages(['tassi' => 'Hai cambiato il tasso di una riga che non registri: tienila, o lascia il suo tasso.']);
            }
            $r = $tenute[$chiave];
            if ((int) $e4 <= 0) {
                throw ValidationException::withMessages(['tassi' => 'Per non chiedere niente, togli la riga.']);
            }
            $massimo = (int) min(array_map(fn ($t) => (int) $t['tasso_e4'], $r['tratti']));
            if ((int) $e4 > $massimo) {
                throw ValidationException::withMessages(['tassi' => 'Più del tasso delle regole no: per questa riga è ' . RegoleDelRecupero::percentuale($massimo)
                    . '. Per un tasso diverso su tutte le righe cambia la regola in «Recupero dei crediti».']);
            }
            $cifra = InteressiDiMora::arrotonda(array_map(fn ($t) => ['tasso_e4' => (int) $e4] + $t, $r['tratti']));
            if ($cifra <= 0) {
                throw ValidationException::withMessages(['tassi' => 'Con questo tasso la riga dà € 0,00: per non chiedere niente, togli la riga.']);
            }
            // D5 della lente del denaro della 1-bis della beta.12: un tasso più basso resta scritto anche quando dà la stessa cifra (1,99 % invece del 2,00 %, € 0,37
            // tutti e due): la causale e l'estratto conto dicono il tasso scelto, e serve il perché. Il tasso uguale a quello delle regole non è un cambio.
            if ((int) $e4 < $massimo) {
                $tenute[$chiave]['proposti'] = (int) $r['interessi'];
                $tenute[$chiave]['interessi'] = $cifra;
                $tenute[$chiave]['tasso'] = ['e4' => (int) $e4, 'etichetta' => RegoleDelRecupero::percentuale((int) $e4)];
                $cambiate++;
            }
        }
        foreach ($importi as $chiave => $cifra) {
            if (! isset($tenute[$chiave])) {
                throw ValidationException::withMessages(['importi' => 'Hai cambiato la cifra di una riga che non registri: tienila, o lascia la sua cifra.']);
            }
            $proposti = (int) $tenute[$chiave]['interessi'];
            if ((int) $cifra <= 0) {
                throw ValidationException::withMessages(['importi' => 'Per non chiedere niente, togli la riga.']);
            }
            if ((int) $cifra > $proposti) {
                throw ValidationException::withMessages(['importi' => 'Più della proposta no: è il massimo che le regole del condominio danno. Per un tasso diverso cambia la regola in «Recupero dei crediti».']);
            }
            if ((int) $cifra < $proposti) {
                $tenute[$chiave]['proposti'] = $proposti;
                $tenute[$chiave]['interessi'] = (int) $cifra;
                $cambiate++;
            }
        }
        $motivo = trim((string) $motivo);
        if ($cambiate > 0 && mb_strlen($motivo) < self::MOTIVO_MINIMO) {
            throw ValidationException::withMessages(['motivo' => 'Scrivi perché cambi le cifre, in almeno ' . self::MOTIVO_MINIMO . ' caratteri: resta scritto nell\'addebito.']);
        }
        if (mb_strlen($motivo) > self::MOTIVO_MASSIMO) {
            throw ValidationException::withMessages(['motivo' => 'Il perché sta in ' . self::MOTIVO_MASSIMO . ' caratteri.']);
        }

        return $tenute;
    }

    /**
     * Decisione 493 (13): gli addebiti appena scritti di un defunto, sulle unità di una successione con l'arretrato agli eredi, «si fermano al decesso» e la
     * scelta «gli interessi del defunto registrati dopo vanno agli eredi», passano agli eredi per quota con la strada degli interessi già addebitati alla
     * successione: una scrittura sulla rata, AVERE «Crediti verso condomini» a nome del defunto, DARE a nome di ogni erede, e una quota per erede. Il registro
     * del passaggio li aggiunge a quelli dell'arretrato (l'annullamento della successione li rimette al defunto, e l'addebito non si annulla finché il passaggio
     * c'è).
     *
     * @param list<\App\Models\Gestionale\Rata> $rate
     */
    private function interessiDelDefuntoAgliEredi(Condominio $condominio, string $alla, array $rate, ?int $utenteId): void
    {
        $quote = RataQuote::with('rata.pianoRate')->whereIn('rata_id', array_map(fn ($r) => (int) $r->id, $rate) ?: [0])->get();
        $fermi = self::fermiAlDecesso((int) $condominio->id, $quote->pluck('anagrafica_id')->map(fn ($id) => (int) $id)->unique()->values()->all());
        foreach ($quote as $q) {
            $f = $fermi[(int) $q->anagrafica_id][(int) $q->immobile_id] ?? null;
            if ($f === null || ! $f['agli_eredi'] || ! $f['agli_eredi_dopo']) {
                continue;
            }
            $padre = \App\Models\Gestionale\Subentro::query()->lockForUpdate()->find($f['padre_id']);
            if ($padre === null) {
                continue;
            }
            $pesi = [];
            foreach ((array) ($padre->registro['eredi'] ?? []) as $e) {
                $pesi[(int) $e['anagrafica_id']] = (float) $e['quota'];
            }
            $utente = \App\Models\User::find($utenteId) ?? auth()->user();
            if ($pesi === [] || $utente === null) {
                continue;
            }
            $avvisi = [];
            $divisione = \App\Services\Subentro\AccessoriAgliEredi::divisione(collect([$q]), $pesi);
            $scritti = app(\App\Services\Subentro\AccessoriAgliEredi::class)->scrivi($padre, $divisione, (int) $q->anagrafica_id, CarbonImmutable::parse($alla, 'UTC'), $avvisi, $utente);
            if ($scritti === []) {
                continue;
            }
            $registro = $padre->registro ?? [];
            $registro['arretrato']['accessori'] = [...(array) ($registro['arretrato']['accessori'] ?? []),
                ...array_map(fn (array $r) => $r + ['dopo_la_successione' => $alla], $scritti)];
            $padre->forceFill(['registro' => $registro])->save();
            $this->frasiDellaRegistrazione[] = sprintf('Gli interessi di %s fino al decesso, %s, sono passati a %s per quota, come quelli addebitati prima della successione.',
                (string) ($padre->registro['nomi']['uscente'] ?? 'il defunto'), MoneyHelper::format((int) $divisione[0]['aperto']), $f['eredi'] !== '' ? $f['eredi'] : 'gli eredi');
        }
    }

    /**
     * La causale di ogni rata da scrivere: «Interessi di mora al …» e, se un giorno è a un tasso scritto, i tassi con il riferimento per intero
     * ({@see RegoleDelRecupero::tassiNellaCausale}). La scrittura di emissione la ripete dopo «Addebito: » in una colonna di 255 caratteri: una causale più
     * lunga non si scrive, e niente si registra.
     *
     * @param array<string, array<string, list<array<string, mixed>>>> $gruppi gestione|esercizio => persona|unità => righe
     * @param list<array<string, mixed>> $persone le persone della proposta, per il nome
     * @return array<string, array<string, string>>
     */
    private function causali(Condominio $condominio, string $alla, array $gruppi, array $persone): array
    {
        $regole = (new RegoleDelRecupero())->delCondominio((int) $condominio->id);
        $causali = [];
        foreach ($gruppi as $chiave => $perUnita) {
            foreach ($perUnita as $personaUnita => $lista) {
                $tratti = array_merge(...array_map(fn ($r) => $r['tratti'], $lista));
                $tassi = RegoleDelRecupero::tassiNellaCausale($tratti, $regole);
                // Decisione 512: con il tasso cambiato su una riga, la causale dice i tassi usati davvero, non quello delle regole. Decisione 526: e da quale tasso è
                // ridotto, come lo direbbe la causale senza «Cambia» («1,00 % annuo, scelto dall'amministratore, ridotto dal 2,00 % annuo, art. 12 del
                // regolamento»); oltre la lunghezza massima, con il solo tasso («ridotto dal 2,00 %»). N2 della terza prova: le righe sono rate diverse, non un
                // tasso che cambia nel tempo, e non si uniscono con «poi».
                $breve = null;
                if (collect($lista)->contains(fn ($r) => isset($r['tasso']))) {
                    $usati = [];
                    $usatiBrevi = [];
                    foreach (collect($lista)->sortBy('dal')->values() as $r) {
                        $delleRegole = RegoleDelRecupero::tassiNellaCausale($r['tratti'], $regole) ?? 'tasso legale';
                        if (isset($r['tasso'])) {
                            $scelto = $r['tasso']['etichetta'] . ' annuo, scelto dall\'amministratore';
                            $pezzo = $scelto . ', ridotto dal ' . $delleRegole;
                            $pezzoBreve = $scelto . ', ridotto dal ' . implode(' e dal ', PropostaInteressi::tassiDeiTratti($r['tratti']));
                        } else {
                            $pezzo = $pezzoBreve = $delleRegole;
                        }
                        if (! in_array($pezzo, $usati, true)) {
                            $usati[] = $pezzo;
                            $usatiBrevi[] = $pezzoBreve;
                        }
                    }
                    $tassi = implode('; ', $usati);
                    $breve = implode('; ', array_values(array_unique($usatiBrevi)));
                }
                $causale = 'Interessi di mora al ' . self::giorno($alla) . ($tassi === null ? '' : ' (' . $tassi . ')');
                if (mb_strlen($causale) > self::CAUSALE_MASSIMA && $breve !== null) {
                    $causale = 'Interessi di mora al ' . self::giorno($alla) . ' (' . $breve . ')';
                }
                if (mb_strlen($causale) > self::CAUSALE_MASSIMA) {
                    $nome = (string) (collect($persone)->firstWhere('anagrafica_id', (int) explode('|', $personaUnita)[0])['nome'] ?? '');
                    $scritte = $regole->whereIn('id', array_column($tratti, 'regola_id'))->where('tasso_tipo', RegolaRecupero::TASSO_SCRITTO);
                    throw ValidationException::withMessages(['interessi' => $scritte->count() > 1
                        ? 'Per ' . $nome . ' gli interessi passano per più tassi scritti, e con i loro riferimenti la riga dell\'addebito è troppo lunga. Registrali in '
                            . 'due volte: prima a una data prima del ' . $scritte->last()->dal->format('d/m/Y') . ', poi alla data che vuoi.'
                        : 'Per ' . $nome . ' la riga dell\'addebito, con il tasso e il suo riferimento, è troppo lunga: il riferimento sta in '
                            . \App\Http\Controllers\Gestionale\Struttura\RegoleRecuperoController::RIFERIMENTO_MASSIMO . ' caratteri.']);
                }
                $causali[$chiave][$personaUnita] = $causale;
            }
        }

        return $causali;
    }

    /** La regola scritta usata dai tratti delle righe, l'ultima se sono più d'una: la base dell'interesse; senza, la legge. */
    private function regolaScritta(Condominio $condominio, array $righe): ?RegolaRecupero
    {
        $regole = (new RegoleDelRecupero())->delCondominio((int) $condominio->id)->keyBy('id');
        foreach (array_reverse($righe) as $r) {
            foreach (array_reverse($r['tratti']) as $t) {
                $regola = $regole[$t['regola_id'] ?? 0] ?? null;
                if ($regola !== null && $regola->tasso_tipo === RegolaRecupero::TASSO_SCRITTO) {
                    return $regola;
                }
            }
        }

        return null;
    }

    /**
     * Il giornale di un gruppo di persone fino alla data, letto una volta: le righe sui due mastri, le rate, le quote, gli interessi già registrati, i
     * titolari e i ruoli del riparto, i nomi e le unità. Lo usano la proposta e, per il defunto di una successione, la base del debito ereditato
     * ({@see DebitoEreditato}).
     *
     * @param list<int> $persone
     * @return array<string, mixed>
     */
    private function leggiGiornale(int $condominioId, string $alla, array $persone): array
    {
        $dopo = CarbonImmutable::parse($alla, 'UTC')->addDay()->format('Y-m-d');
        $righe = DB::table('righe_scritture as rs')
            ->join('scritture_contabili as sc', 'sc.id', '=', 'rs.scrittura_id')
            ->join('conti_contabili as cc', 'cc.id', '=', 'rs.conto_contabile_id')
            ->whereNull('sc.deleted_at')->where('sc.condominio_id', $condominioId)->where('sc.data_competenza', '<', $dopo)
            ->whereIn('cc.ruolo', ['crediti_condomini', 'anticipi_condomini'])->whereIn('rs.anagrafica_id', $persone ?: [0])
            ->groupBy('rs.anagrafica_id', 'rs.rata_id', 'rs.immobile_id', 'cc.ruolo', 'sc.data_competenza', 'sc.tipo_movimento')
            ->select(['rs.anagrafica_id', 'rs.rata_id', 'rs.immobile_id', 'cc.ruolo', 'sc.data_competenza', 'sc.tipo_movimento'])
            ->selectRaw("SUM(CASE WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE -rs.importo END) as netto")
            ->get();

        $rateIds = $righe->pluck('rata_id')->filter()->unique()->values()->all();
        $rate = $rateIds === [] ? collect() : DB::table('rate')->join('piani_rate as p', 'p.id', '=', 'rate.piano_rate_id')->join('gestioni as g', 'g.id', '=', 'p.gestione_id')
            ->whereIn('rate.id', $rateIds)->get(['rate.id', 'rate.piano_rate_id', 'rate.numero_rata', 'rate.data_scadenza', 'rate.descrizione', 'p.nome as piano', 'p.natura', 'p.gestione_id', 'g.nome as gestione'])
            ->keyBy('id');
        $quote = $rateIds === [] ? collect() : RataQuote::whereIn('rata_id', $rateIds)->whereIn('anagrafica_id', $persone)->get(['id', 'rata_id', 'anagrafica_id', 'immobile_id', 'tipo', 'regole_calcolo']);
        $accessori = $quote->filter(fn (RataQuote $q) => $q->tipo === RataQuote::TIPO_ACCESSORIO)
            ->mapWithKeys(fn (RataQuote $q) => [$q->anagrafica_id . '|' . $q->rata_id . '|' . $q->immobile_id => true]);
        ['al' => $giaRegistrati, 'registrazioni' => $registrazioni] = $this->giaRegistrati($condominioId, $persone);

        return [
            'righe' => $righe->groupBy('anagrafica_id'),
            'rate' => $rate,
            'quote' => $quote,
            'accessori' => $accessori,
            'gia' => $giaRegistrati,
            'registrazioni' => $registrazioni,
            'titolari' => $this->titolari($righe->pluck('immobile_id')->filter()->unique()->values()->all()),
            'ruoli' => $this->ruoliDelRiparto($quote, $rate),
        ];
    }

    /**
     * Le chiavi del giornale di una persona e i suoi movimenti: una chiave per rata e unità (la base, gli accessori, le quote di inquilino), gli anticipi e
     * le righe senza rata; il primo giorno degli interessi di ogni rata della base. Senza le regole degli accordi e delle successioni, che chi chiama
     * aggiunge.
     *
     * @param array<string, mixed> $letto {@see leggiGiornale()}
     * @return array{chiavi: array<string, array<string, mixed>>, movimenti: list<array{giorno: string, chiave: string, importo: int, causa: string}>}
     */
    private function chiaviDellaPersona(int $persona, array $letto, Collection $regole): array
    {
        $rate = $letto['rate'];
        $chiavi = [];
        $movimenti = [];
        foreach ($letto['righe'][$persona] ?? [] as $r) {
            $giorno = substr((string) $r->data_competenza, 0, 10);
            $netto = (int) $r->netto;
            if ($netto === 0) {
                continue;
            }
            if ($r->ruolo === 'anticipi_condomini') {
                $k = 'anticipi';
                $chiavi[$k] ??= ['inizio' => null, 'base' => false, 'ordine' => ['9999-12-31', 9, 0], 'tipo' => 'anticipi', 'immobile_id' => null];
            } elseif ($r->rata_id === null || ! isset($rate[$r->rata_id])) {
                $k = 'senza_rata|' . (int) $r->immobile_id;
                $chiavi[$k] ??= ['inizio' => null, 'base' => false, 'ordine' => ['9999-12-31', 9, 0], 'tipo' => 'senza_rata', 'immobile_id' => $r->immobile_id === null ? null : (int) $r->immobile_id];
            } else {
                $rata = $rate[$r->rata_id];
                $k = $persona . '|' . (int) $r->rata_id . '|' . (int) $r->immobile_id;
                if (! isset($chiavi[$k])) {
                    $scadenza = Scadenza::giorno((string) $rata->data_scadenza);
                    $accessorio = isset($letto['accessori'][$k]);
                    // Il ruolo della quota nel riparto del suo piano; senza riparto (la rata 0, un contributo), chi non ha mai avuto un diritto reale.
                    $diInquilino = $r->immobile_id !== null && (isset($letto['ruoli'][$k]) ? $letto['ruoli'][$k] === 'inquilino'
                        : ! in_array($persona, $letto['titolari'][(int) $r->immobile_id] ?? [], true));
                    $inizio = $inizioPieno = RegoleDelRecupero::primoGiorno($regole, $scadenza);
                    // La regola che ha spostato il primo giorno con i suoi giorni di decorrenza: anche lei è usata da questi interessi.
                    $regolaInizio = RegoleDelRecupero::alGiorno($regole, CarbonImmutable::parse($scadenza, 'UTC')->addDay()->format('Y-m-d'));
                    $regolaInizio = $regolaInizio !== null && (int) $regolaInizio->giorni_decorrenza > 0 ? (int) $regolaInizio->id : null;
                    if (isset($letto['gia'][$k]) && $letto['gia'][$k] >= $inizio) {
                        $inizio = CarbonImmutable::parse($letto['gia'][$k], 'UTC')->addDay()->format('Y-m-d');
                        $regolaInizio = null;
                    }
                    $chiavi[$k] = ['inizio' => $accessorio || $diInquilino ? null : $inizio, 'base' => ! $accessorio && ! $diInquilino,
                        'ordine' => OrdineImputazione::chiave($scadenza, (int) $rata->numero_rata, true, (int) $r->rata_id),
                        'tipo' => $accessorio ? 'accessorio' : ($diInquilino ? 'inquilino' : 'base'), 'scadenza' => $scadenza, 'rata' => $rata,
                        'immobile_id' => $r->immobile_id === null ? null : (int) $r->immobile_id, 'gia_al' => $letto['gia'][$k] ?? null,
                        'regola_inizio' => $regolaInizio, 'inizio_pieno' => $accessorio || $diInquilino ? null : $inizioPieno];
                }
            }
            $causa = match (true) {
                $netto < 0 => 'versamento',
                $r->tipo_movimento === 'emissione_rata' => 'emissione',
                default => 'storno',
            };
            $movimenti[] = ['giorno' => $giorno, 'chiave' => $k, 'importo' => $netto, 'causa' => $causa];
        }

        return ['chiavi' => $chiavi, 'movimenti' => $movimenti];
    }

    /**
     * Il giornale delle persone date per la base del debito ereditato: le chiavi, i movimenti e il debito che un accordo di rientro tiene fuori, per persona.
     * {@see DebitoEreditato} lo chiede per il defunto di ogni successione.
     *
     * @param list<int> $persone
     * @return array<int, array{chiavi: array<string, array<string, mixed>>, movimenti: list<array<string, mixed>>, accordi: array<string, array<string, mixed>>, gia: array<string, string>}>
     */
    public function giornaleDelDefunto(int $condominioId, string $alla, array $persone, Collection $regole): array
    {
        $letto = $this->leggiGiornale($condominioId, $alla, $persone);
        $coperteDa = app(\App\Services\Gestionale\Debiti\AccordiRientro::class)->senzaInteressi($condominioId, $persone, $alla);
        $esito = [];
        foreach ($persone as $persona) {
            $esito[$persona] = $this->chiaviDellaPersona($persona, $letto, $regole) + ['accordi' => $coperteDa[$persona] ?? [], 'gia' => $letto['gia']];
        }

        return $esito;
    }

    /**
     * La proposta di un gruppo di persone: il loro giornale fino alla data, le chiavi della base, il calcolo.
     *
     * Con la successione (decisioni 483 e 489): le rate del defunto sulle unità del passaggio si fermano al giorno prima del decesso con «si fermano al decesso»;
     * con l'arretrato agli eredi il credito che le copriva quel giorno resta dov'era (D1 della prova della Coda 663) e i movimenti di quelle unità dopo il
     * decesso non toccano più le sue altre rate; agli eredi il debito ereditato è una chiave in più ({@see DebitoEreditato}), anche senza righe nel giornale.
     *
     * @param list<int> $persone
     * @return list<array<string, mixed>>
     */
    private function perBlocco(int $condominioId, string $alla, array $persone, Collection $regole): array
    {
        if ($persone === []) {
            return [];
        }
        $letto = $this->leggiGiornale($condominioId, $alla, $persone);
        ['registrazioni' => $registrazioni, 'rate' => $rate] = $letto;
        // Il debito coperto da un accordo di rientro non decaduto, finché il calendario non è finito o se è tutto versato: niente interessi su quello dal
        // giorno dell'accordo, anche sulla rata coperta già versata (decisioni 389 (2) e 433); i giorni prima dell'accordo restano (decisione 438). Con la
        // decadenza, o finito il calendario con qualcosa da versare, tornano anche i giorni dell'accordo. Per persona, «rata|unità» => la parte coperta e
        // il primo giorno fuori.
        $coperteDa = app(\App\Services\Gestionale\Debiti\AccordiRientro::class)->senzaInteressi($condominioId, $persone, $alla);
        // Decisione 483: le successioni con la scelta «si fermano al decesso». Per persona, unità => l'ultimo giorno contato, il giorno prima del decesso.
        $alDecesso = self::fermiAlDecesso($condominioId, $persone);
        // Decisione 489: il debito ereditato, per erede: le chiavi in più, i loro movimenti, e i movimenti che spostano una parte nella rata nuova.
        $ereditati = ($this->ereditato ?? new DebitoEreditato())->perEredi($condominioId, $alla, $persone, $regole, $coperteDa,
            fn (array $defunti) => $this->giornaleDelDefunto($condominioId, $alla, $defunti, $regole));
        $tassoAl = RegoleDelRecupero::tassoAl($regole);
        $cambi = RegoleDelRecupero::cambi($regole);
        $chi = array_values(array_unique([...array_map('intval', $letto['righe']->keys()->all()), ...array_keys($ereditati)]));
        $nomi = DB::table('anagrafiche')->whereIn('id', $persone)->pluck('nome', 'id');
        $unitaIds = $letto['righe']->flatten(1)->pluck('immobile_id')->merge(collect($ereditati)->flatMap(fn ($e) => array_column($e['chiavi'], 'immobile_id')))
            ->filter()->unique()->values()->all();
        $unita = \App\Models\Immobile::whereIn('id', $unitaIds ?: [0])->get()->mapWithKeys(fn (\App\Models\Immobile $i) => [(int) $i->id => (string) $i->etichetta]);

        $esito = [];
        foreach ($chi as $persona) {
            ['chiavi' => $chiavi, 'movimenti' => $movimenti] = $this->chiaviDellaPersona($persona, $letto, $regole);
            $inquilino = [];
            // Decisione 489: le chiavi del debito ereditato e i loro movimenti; la rata nuova che lo porta lo prende dal suo giorno (`aggiusta`). Gli interessi
            // già registrati su una chiave ereditata (con il suffisso del passaggio) la fanno ripartire dal giorno dopo.
            foreach ($ereditati[$persona]['chiavi'] ?? [] as $k => $c) {
                if (isset($letto['gia'][$k]) && $letto['gia'][$k] >= $c['inizio']) {
                    $c['inizio'] = CarbonImmutable::parse($letto['gia'][$k], 'UTC')->addDay()->format('Y-m-d');
                }
                $chiavi[$k] = $c + ['gia_al' => $letto['gia'][$k] ?? null];
            }
            foreach ($ereditati[$persona]['movimenti'] ?? [] as $m) {
                $movimenti[] = $m;
            }
            // La rata nuova prende la parte ereditata solo se è nel giornale dell'erede: una quota a zero (il debito ereditato pareggiato dagli altri suoi saldi
            // nello stesso piano) non si scrive, e la parte esce dalla riga ereditata e basta.
            foreach ($ereditati[$persona]['aggiusta'] ?? [] as $m) {
                if (isset($chiavi[$m['chiave']])) {
                    $movimenti[] = $m;
                }
            }
            // Decisione 493 (1): con «interessi anche sugli interessi», gli interessi e le spese del defunto passati all'erede con la successione entrano
            // nella sua base dal giorno del decesso.
            foreach ($ereditati[$persona]['accessori'] ?? [] as $k => $info) {
                if (isset($chiavi[$k]) && $chiavi[$k]['tipo'] === 'accessorio') {
                    // E8 della seconda prova della beta.12: l'addebito passato all'erede è nel suo giornale dal giorno del passaggio, e un movimento conta dal
                    // giorno dopo: la riga comincia lì, e la frase non dice un giorno in cui il capitale era zero.
                    $entrato = collect($movimenti)->filter(fn ($m) => $m['chiave'] === $k && $m['importo'] > 0)->min('giorno');
                    $primo = $entrato === null ? $info['inizio'] : max($info['inizio'], CarbonImmutable::parse($entrato, 'UTC')->addDay()->format('Y-m-d'));
                    $inizio = isset($letto['gia'][$k]) && $letto['gia'][$k] >= $primo ? CarbonImmutable::parse($letto['gia'][$k], 'UTC')->addDay()->format('Y-m-d') : $primo;
                    $chiavi[$k] = ['inizio' => $inizio, 'base' => true, 'tipo' => 'ereditata_accessorio', 'inizio_pieno' => $primo, 'ereditata' => $info['ereditata']] + $chiavi[$k];
                }
            }
            if ($chiavi === []) {
                continue;
            }
            // Le rate coperte dall'accordo: fino al giorno prima dell'accordo restano nella base, con i loro giorni e i crediti che le coprono (decisione
            // 438). Dal giorno dell'accordo esce tutta la rata (`fine`); di una rata coperta in parte, con gli interessi che continuano sulla parte fuori
            // (decisione 450, di serie), esce solo la parte nell'accordo (`protetto`), e la riga arriva alla data. La regola è in `AccordiRientro`.
            $nellAccordo = 0;
            foreach ($coperteDa[$persona] ?? [] as $chiaveAccordo => $fuori) {
                [$rataCoperta, $unitaCoperta] = explode('|', $chiaveAccordo);
                $k = $persona . '|' . (int) $rataCoperta . '|' . (int) $unitaCoperta;
                if (isset($chiavi[$k]) && $chiavi[$k]['base']) {
                    if (($fuori['protetto'] ?? null) !== null) {
                        $chiavi[$k]['protetto'] = $fuori['protetto'];
                        $chiavi[$k]['in_parte'] = true;
                        // L'accordo chiuso dalla successione in regola: dal decesso niente interessi neanche sulla parte fuori (decisione 454).
                        if (($fuori['fine'] ?? null) !== null) {
                            $chiavi[$k]['fine'] = $fuori['fine'];
                        }
                    } else {
                        $chiavi[$k]['fine'] = CarbonImmutable::parse($fuori['dal'], 'UTC')->subDay()->format('Y-m-d');
                        // Coda 678 (decisione 485): con gli interessi sospesi resta fuori tutta la rata, non solo la parte nell'accordo: la frase in testa alla persona lo
                        // dice. Solo quando i «sospesi» li ha scelti l'amministratore e valgono alla data (Coda 712): una rata coperta per intero, o di un accordo
                        // chiuso dalla successione, esce tutta per un'altra ragione, e la frase direbbe una scelta che nessuno ha fatto.
                        if ($fuori['sospesi'] ?? false) {
                            $chiavi[$k]['fuori_tutta'] = (int) $fuori['parte'];
                        }
                    }
                    $chiavi[$k]['accordo_dal'] = $fuori['dal'];
                    $chiavi[$k]['tipo'] = 'accordo';
                    $nellAccordo += (int) $fuori['parte'];
                }
            }
            // Decisione 483: con la successione e «si fermano al decesso», sulle rate delle unità del passaggio l'ultimo giorno contato è il giorno prima del
            // decesso, coperte o no da un accordo: un `fine` già più vicino (il giorno prima di un accordo in regola, decisione 454) resta com'è.
            $fermi = $alDecesso[$persona] ?? [];
            if ($fermi !== []) {
                [$chiavi, $movimenti] = self::tieniIlCreditoDovEra($chiavi, $movimenti, $fermi, $alla);
            }
            // Decisione 530: anche la chiave ereditata di un erede che muore a sua volta si ferma al suo decesso, sulle unità della sua successione; da lì la conta
            // il suo erede ({@see DebitoEreditato::baseEreditataDelDefunto()}). Con «continuano a suo nome» nella seconda successione la chiave continua, come le
            // sue rate (non è fra i fermi).
            foreach ($chiavi as $k => $c) {
                $info = $c['base'] && ($c['immobile_id'] ?? null) !== null ? ($fermi[$c['immobile_id']] ?? null) : null;
                if ($info !== null) {
                    $chiavi[$k]['fine'] = min($c['fine'] ?? $info['ultimo'], $info['ultimo']);
                    $chiavi[$k]['decesso'] = $info;
                }
            }
            $calcolo = InteressiDiMora::calcola(array_map(fn ($c) => array_intersect_key($c, array_flip(['inizio', 'base', 'ordine', 'fine', 'protetto', 'inizio_pieno'])), $chiavi), $movimenti, $tassoAl, $cambi, $alla);
            $riallineo = $this->riallineo($chiavi, $movimenti, $registrazioni, $tassoAl, $cambi);

            // Il saldo di ogni chiave alla data: i crediti e gli anticipi accanto alla persona, e le quote di inquilini scadute.
            $saldi = [];
            foreach ($movimenti as $m) {
                $saldi[$m['chiave']] = ($saldi[$m['chiave']] ?? 0) + $m['importo'];
            }
            $crediti = 0;
            $anticipi = 0;
            foreach ($saldi as $k => $s) {
                if (in_array($chiavi[$k]['tipo'] ?? null, ['tenuto', 'ereditata'], true)) {
                    continue;
                }
                if ($s < 0) {
                    $chiavi[$k]['tipo'] === 'anticipi' ? $anticipi += -$s : $crediti += -$s;
                }
                if ($s > 0 && $chiavi[$k]['tipo'] === 'inquilino' && $chiavi[$k]['scadenza'] < $alla) {
                    $inquilino[] = $s;
                }
            }

            // Coda 678 (decisione 485): le rate fuori per intero dal giorno dell'accordo (gli interessi sospesi) con il loro saldo alla data, e la parte che l'accordo
            // ne copre. Sono cifre già calcolate: servono solo alla frase, che le dice quando l'accordo ne copre meno della rata.
            $fuoriPerIntero = 0;
            $diCuiNellAccordo = 0;
            foreach ($chiavi as $k => $c) {
                if (isset($c['fuori_tutta'])) {
                    $fuoriPerIntero += max(0, (int) ($saldi[$k] ?? 0));
                    $diCuiNellAccordo += (int) $c['fuori_tutta'];
                }
            }
            if ($fuoriPerIntero <= $diCuiNellAccordo) {
                $fuoriPerIntero = $diCuiNellAccordo = 0;
            }

            $righePersona = [];
            $fermiAlDecesso = [];
            foreach ($calcolo as $k => $c) {
                // La chiave che tiene il credito dov'era al decesso non è una riga: lavora solo nel calcolo.
                if (($chiavi[$k]['tipo'] ?? null) === 'tenuto') {
                    continue;
                }
                // Una riga senza interessi da proporre resta se gli interessi già registrati di quei giorni, con il giornale di oggi, darebbero un'altra cifra:
                // la frase del riallineo si legge anche quando non ci sono giorni nuovi (un accordo in regola, i giorni prima già registrati). La
                // registrazione la lascia fuori: si registrano solo le righe con interessi.
                if ($c['interessi'] <= 0 && $c['fermo'] === null && ! isset($riallineo[$k])) {
                    continue;
                }
                $meta = $chiavi[$k];
                $rata = $meta['rata'];
                $al = min($alla, $meta['fine'] ?? $alla);
                $tratti = isset($meta['ereditata']) ? DebitoEreditato::causeDeiTratti($c['tratti'], $meta['ereditata']) : $c['tratti'];
                // D4 della prova della Coda 663: la riga ferma al decesso dice perché.
                $decesso = isset($meta['decesso']) && ($meta['fine'] ?? null) === $meta['decesso']['ultimo'] && $al < $alla ? $meta['decesso'] : null;
                // E2 della seconda prova della beta.12: la registrazione passa agli eredi gli interessi del defunto su ogni rata delle unità del passaggio, anche
                // di una riga che si ferma prima del decesso per un accordo di rientro: l'avviso accanto al defunto vale anche per lei.
                if (isset($meta['decesso']) && $c['interessi'] > 0 && $c['fermo'] === null) {
                    $fermiAlDecesso[] = $meta['decesso'];
                }
                $righePersona[] = [
                    'chiave' => $k,
                    'rata_id' => (int) $rata->id,
                    'immobile_id' => $meta['immobile_id'],
                    'unita' => $meta['immobile_id'] === null ? null : ($unita[$meta['immobile_id']] ?? null),
                    'rata' => self::nomeDellaRata($rata),
                    'piano' => (string) $rata->piano,
                    'gestione_id' => (int) $rata->gestione_id,
                    'gestione' => (string) $rata->gestione,
                    'scadenza' => $meta['scadenza'],
                    'dal' => $meta['inizio'],
                    // L'ultimo giorno della riga: la data, o il giorno prima di un accordo di rientro che tiene fuori i giorni dopo (`accordo_dal`).
                    'al' => $al,
                    'accordo_dal' => $meta['accordo_dal'] ?? null,
                    // La rata è coperta solo in parte e gli interessi continuano sulla parte fuori dall'accordo (decisione 450): la riga arriva alla data.
                    'accordo_in_parte' => $meta['in_parte'] ?? false,
                    'gia_registrati_al' => $meta['gia_al'],
                    'riallineo' => $riallineo[$k] ?? null,
                    'regola_inizio' => $meta['regola_inizio'] ?? null,
                    // Decisione 489: la riga del debito ereditato, con da chi, per quale quota, dal decesso; e la frase sotto la riga.
                    'ereditata' => isset($meta['ereditata']) ? DebitoEreditato::perLaRiga($meta['ereditata']) : null,
                    'frase_ereditata' => isset($meta['ereditata']) ? DebitoEreditato::frase($meta['ereditata'], $meta['inizio'], $al, $alla) : null,
                    'fino_al_decesso' => $decesso === null ? null : self::fraseFinoAlDecesso($decesso),
                    'tratti' => $tratti,
                    // Decisione 511: il tasso accanto alla cifra, e i tassi dei tratti se nel periodo cambia («1,60 % → 2,00 %»).
                    'tassi' => self::tassiDeiTratti($tratti),
                    'interessi' => $c['interessi'],
                    'fermo' => $c['fermo'],
                ];
            }
            usort($righePersona, fn ($a, $b) => [$a['scadenza'], $a['rata_id'], $a['immobile_id'], $a['chiave']] <=> [$b['scadenza'], $b['rata_id'], $b['immobile_id'], $b['chiave']]);
            $diInquilini = (int) array_sum($inquilino);
            if ($righePersona === [] && $diInquilini === 0 && $nellAccordo === 0) {
                continue;
            }
            $esito[] = [
                'anagrafica_id' => $persona,
                'nome' => (string) ($nomi[$persona] ?? ''),
                'righe' => $righePersona,
                'totale' => (int) array_sum(array_column(array_filter($righePersona, fn ($r) => $r['fermo'] === null), 'interessi')),
                'crediti_sulle_rate' => $crediti,
                'anticipi' => $anticipi,
                'quote_di_inquilino' => $diInquilini,
                // Il debito coperto da un accordo di rientro in corso, che la proposta lascia fuori.
                'in_accordo' => $nellAccordo,
                // Di quel debito, la parte delle rate che l'accordo copre solo in parte ma che con gli interessi sospesi escono per intero (`fuori_per_intero` è la rata
                // intera, `di_cui_nell_accordo` quanto ne copre l'accordo); zero se non c'è il caso.
                'fuori_per_intero' => $fuoriPerIntero,
                'di_cui_nell_accordo' => $diCuiNellAccordo,
                // Decisione 493 (13), D5 della prova: che cosa succede registrando gli interessi del defunto fermi al decesso, con l'arretrato agli eredi.
                'dopo_il_decesso' => self::fraseDopoIlDecesso($fermiAlDecesso, (string) ($nomi[$persona] ?? '')),
            ];
        }

        return $esito;
    }

    /**
     * D1 della prova della Coda 663: con «si fermano al decesso» e l'arretrato agli eredi, dal decesso le unità del passaggio escono dal calcolo del defunto
     * insieme al credito che quel giorno copriva le loro rate: l'arretrato, calcolato al netto, l'ha già usato. Il credito resta dov'era, e non riduce gli
     * interessi delle altre sue unità. Una chiave in più (`tenuto`), la più vecchia nell'ordine, porta dal decesso la parte di credito che le rate del passaggio
     * avevano il giorno prima: la prende per prima, e non è una riga (i suoi interessi non si contano). I movimenti delle unità del passaggio dal decesso in poi
     * (la quota a credito del defunto nel piano che assorbe l'arretrato, per esempio) non toccano più le altre rate.
     *
     * @param array<int, array{ultimo: string, decesso: string, agli_eredi: bool}> $fermi unità => la successione
     * @return array{0: array<string, array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private static function tieniIlCreditoDovEra(array $chiavi, array $movimenti, array $fermi, string $alla): array
    {
        $agliEredi = array_filter($fermi, fn (array $f) => $f['agli_eredi']);
        if ($agliEredi === []) {
            return [$chiavi, $movimenti];
        }
        $perDecesso = [];
        foreach ($agliEredi as $unita => $f) {
            $perDecesso[$f['decesso']][] = (int) $unita;
        }
        ksort($perDecesso);
        foreach ($perDecesso as $decesso => $unitaIds) {
            if ($decesso > $alla) {
                continue;
            }
            // Decisione 530: con le chiavi ereditate dell'erede che muore a sua volta: il credito che le copriva resta con loro, e i loro movimenti dopo il decesso
            // (l'uscita nella rata nuova, un versamento) sono della successione dopo.
            $delPassaggio = fn (array $c) => in_array((int) ($c['immobile_id'] ?? 0), $unitaIds, true);
            // Il credito che le rate del passaggio avevano il giorno del decesso, se fossero rimaste: il capitale si legge senza il loro ultimo giorno.
            $prima = array_map(fn (array $c) => $delPassaggio($c) && ($c['fine'] ?? null) !== null && $c['fine'] >= CarbonImmutable::parse($decesso, 'UTC')->subDay()->format('Y-m-d')
                ? array_merge($c, ['fine' => null]) : $c, $chiavi);
            $letto = InteressiDiMora::capitaliAl(array_map(fn ($c) => array_intersect_key($c, array_flip(['inizio', 'base', 'ordine', 'fine', 'protetto', 'inizio_pieno'])), $prima), $movimenti, $decesso, $alla);
            $tenuto = 0;
            foreach ($letto['coperti'] as $k => $coperto) {
                if ($delPassaggio($chiavi[$k])) {
                    $tenuto += $coperto;
                }
            }
            // Dal decesso i movimenti delle unità del passaggio restano fuori: sono dell'arretrato, che è passato agli eredi.
            $movimenti = array_values(array_filter($movimenti, fn (array $m) => $m['giorno'] < $decesso || ! isset($chiavi[$m['chiave']]) || ! $delPassaggio($chiavi[$m['chiave']])));
            if ($tenuto > 0) {
                $k = 'tenuto|' . $decesso;
                $chiavi[$k] = ['inizio' => $decesso, 'inizio_pieno' => $decesso, 'base' => true, 'ordine' => [-1, '0000-00-00', 0, 0], 'tipo' => 'tenuto', 'immobile_id' => null];
                $movimenti[] = ['giorno' => CarbonImmutable::parse($decesso, 'UTC')->subDay()->format('Y-m-d'), 'chiave' => $k, 'importo' => $tenuto, 'causa' => 'credito'];
            }
        }

        return [$chiavi, $movimenti];
    }

    /** D4 della prova della Coda 663: perché la riga del defunto si ferma al giorno prima del decesso. */
    private static function fraseFinoAlDecesso(array $decesso): string
    {
        $fino = self::giorno($decesso['ultimo']);
        $dal = self::giorno($decesso['decesso']);

        return $decesso['agli_eredi']
            ? sprintf('Fino al %s: dal %s il debito è passato a %s.', $fino, $dal, $decesso['eredi'] !== '' ? $decesso['eredi'] : 'gli eredi')
            : sprintf('Fino al %s: dal %s l\'arretrato resta a suo nome, senza interessi dopo il decesso.', $fino, $dal);
    }

    /**
     * Decisione 493 (13): accanto al defunto con righe sulle unità di una successione con «si fermano al decesso» e l'arretrato agli eredi (ferme al decesso,
     * o prima per un accordo di rientro: E2 della seconda prova della beta.12), che cosa fa la registrazione: con «gli interessi del defunto registrati dopo
     * vanno agli eredi» passano agli eredi per quota; altrimenti restano a suo nome. Null senza.
     *
     * @param list<array<string, mixed>> $fermi
     */
    private static function fraseDopoIlDecesso(array $fermi, string $nome): ?string
    {
        $agliEredi = array_values(array_filter($fermi, fn (array $f) => $f['agli_eredi']));
        if ($agliEredi === []) {
            return null;
        }
        $f = $agliEredi[0];

        return $f['agli_eredi_dopo']
            ? sprintf('Registrati, gli interessi di %s fino al decesso passano a %s per quota, come quelli già addebitati prima della successione.', $nome, $f['eredi'] !== '' ? $f['eredi'] : 'gli eredi')
            : sprintf('Registrati, gli interessi di %s fino al decesso restano a suo nome («eredi di %s»): la sua posizione, chiusa con la successione, si riapre.', $nome, $nome);
    }

    /**
     * Decisione 483: le successioni registrate (non annullate) delle persone date con la scelta «si fermano al decesso»: per il defunto, sulle rate delle
     * unità del passaggio (l'unità e le pertinenze spuntate, un passaggio ciascuna), l'ultimo giorno che la proposta conta è il giorno prima del decesso.
     * Con il giorno del decesso, se l'arretrato è passato agli eredi (con i loro nomi) e se gli interessi del defunto registrati dopo vanno a loro (decisione
     * 493 (13)).
     *
     * @param list<int> $persone
     * @return array<int, array<int, array{ultimo: string, decesso: string, agli_eredi: bool, eredi: string, padre_id: int, agli_eredi_dopo: bool}>> persona => unità => la successione
     */
    public static function fermiAlDecesso(int $condominioId, array $persone): array
    {
        $esito = [];
        $successioni = \App\Models\Gestionale\Subentro::query()->where('condominio_id', $condominioId)->where('tipo_passaggio', 'successione')
            ->where('interessi_del_defunto', \App\Models\Gestionale\Subentro::INTERESSI_AL_DECESSO)->whereIn('anagrafica_uscente_id', $persone ?: [0])
            ->get(['id', 'condominio_id', 'tipo_passaggio', 'subentro_padre_id', 'anagrafica_uscente_id', 'immobile_id', 'decorrenza', 'registro', 'interessi_eredi']);
        $padri = \App\Models\Gestionale\Subentro::query()->whereIn('id', $successioni->pluck('subentro_padre_id')->filter()->unique()->values()->all() ?: [0])->get()->keyBy('id');
        $delCondominio = null;
        foreach ($successioni as $s) {
            $padre = $s->subentro_padre_id === null ? $s : ($padri[(int) $s->subentro_padre_id] ?? $s);
            $decesso = $s->decorrenza->format('Y-m-d');
            $ultimo = CarbonImmutable::parse($decesso, 'UTC')->subDay()->format('Y-m-d');
            $agliEredi = $padre->arretratoAgliEredi();
            $delCondominio ??= RegoleDegliEredi::delCondominio($condominioId);
            $voce = ['ultimo' => $ultimo, 'decesso' => $decesso, 'agli_eredi' => $agliEredi, 'padre_id' => (int) $padre->id,
                'eredi' => $agliEredi ? RegoleDegliEredi::elenco(array_values(array_map('strval', (array) ($padre->registro['nomi']['eredi'] ?? [])))) : '',
                'agli_eredi_dopo' => $agliEredi && RegoleDegliEredi::perSuccessione($padre, $delCondominio)['interessi_defunto_agli_eredi']];
            $gia = $esito[(int) $s->anagrafica_uscente_id][(int) $s->immobile_id] ?? null;
            if ($gia === null || $ultimo < $gia['ultimo']) {
                $esito[(int) $s->anagrafica_uscente_id][(int) $s->immobile_id] = $voce;
            }
        }

        return $esito;
    }

    /**
     * Gli interessi registrati che il giornale di oggi darebbe diversi: un versamento o uno storno registrato dopo con una data di prima cambia i giorni
     * già chiesti. Per ogni registrazione il calcolo si rifà con il giornale di oggi, dal suo primo giorno al suo ultimo; se la somma non è quella
     * proposta allora, la riga lo dice. Gli interessi registrati restano come sono: li cambia l'amministratore, annullando l'addebito. La frase dice il giorno
     * dell'addebito, «Interessi di mora al …», anche quando la riga registrata si fermava prima, al giorno prima di un accordo di rientro: è il nome
     * dell'addebito da annullare.
     *
     * La frase dice la ragione vera (D2 della prova della Coda 663): rifatto senza l'ultimo giorno e senza la parte protetta della chiave, il calcolo torna
     * con la cifra di allora? Allora la cifra cambia per la successione («si fermano al decesso») o per un accordo di rientro in regola (anche riaperto da un
     * annullamento), non per il giornale. Con la cifra cambiata prima di registrare (decisione 493 (10)), il confronto è con la cifra proposta, e la frase lo
     * dice.
     *
     * @param array<string, array<string, mixed>> $chiavi
     * @param list<array<string, mixed>> $movimenti
     * @param array<string, list<array{alla: string, registrata: string, dal: ?string, interessi: int, proposti: int}>> $registrazioni
     * @return array<string, string> la frase, per chiave
     */
    private function riallineo(array $chiavi, array $movimenti, array $registrazioni, \Closure $tassoAl, array $cambi): array
    {
        $diQui = array_intersect_key($registrazioni, $chiavi);
        if ($diQui === []) {
            return [];
        }
        $perGiorno = [];
        foreach ($diQui as $k => $lista) {
            foreach ($lista as $r) {
                $perGiorno[$r['alla']][$k] = $r;
            }
        }
        $darebbero = [];
        $puri = [];
        $fermi = [];
        foreach ($perGiorno as $giorno => $registrate) {
            $chiaviDelGiorno = [];
            foreach ($chiavi as $k => $c) {
                $chiaviDelGiorno[$k] = ['inizio' => isset($registrate[$k]) ? ($registrate[$k]['dal'] ?? $c['inizio_pieno'] ?? null) : ($c['inizio_pieno'] ?? null),
                    'base' => $c['base'], 'ordine' => $c['ordine'], 'fine' => $c['fine'] ?? null, 'protetto' => $c['protetto'] ?? []];
            }
            $delGiorno = array_values(array_filter($movimenti, fn ($m) => $m['giorno'] <= $giorno));
            $esito = InteressiDiMora::calcola($chiaviDelGiorno, $delGiorno, $tassoAl, $cambi, $giorno);
            // Lo stesso calcolo senza l'ultimo giorno e senza la parte protetta delle chiavi registrate: il solo giornale.
            $senza = $chiaviDelGiorno;
            foreach (array_keys($registrate) as $k) {
                $senza[$k]['fine'] = null;
                $senza[$k]['protetto'] = [];
            }
            $puro = InteressiDiMora::calcola($senza, $delGiorno, $tassoAl, $cambi, $giorno);
            foreach (array_keys($registrate) as $k) {
                if (($esito[$k]['fermo'] ?? null) !== null) {
                    $fermi[$k] = true;
                }
                $darebbero[$k] = ($darebbero[$k] ?? 0) + (int) ($esito[$k]['interessi'] ?? 0);
                $puri[$k] = ($puri[$k] ?? 0) + (int) ($puro[$k]['interessi'] ?? 0);
            }
        }
        $frasi = [];
        foreach ($diQui as $k => $lista) {
            $proposti = (int) array_sum(array_column($lista, 'proposti'));
            $registrati = (int) array_sum(array_column($lista, 'interessi'));
            if (isset($fermi[$k]) || $darebbero[$k] === $proposti) {
                continue;
            }
            $quando = self::giorno((string) max(array_column($lista, 'registrata')));
            $erano = $proposti === $registrati
                ? sprintf('Gli interessi registrati al %s erano %s', $quando, MoneyHelper::format($registrati))
                : sprintf('Gli interessi proposti al %s erano %s (registrati %s, per tua scelta)', $quando, MoneyHelper::format($proposti), MoneyHelper::format($registrati));
            $c = $chiavi[$k];
            $perche = match (true) {
                $puri[$k] === $proposti && isset($c['decesso']) && ($c['fine'] ?? null) === $c['decesso']['ultimo'] => sprintf('con la successione del %s, che ferma la '
                    . 'proposta al %s, quei giorni darebbero', self::giorno($c['decesso']['decesso']), self::giorno($c['decesso']['ultimo'])),
                $puri[$k] === $proposti && ($c['accordo_dal'] ?? null) !== null => sprintf('con l\'accordo di rientro in regola dal %s, che tiene fuori i giorni da lì in '
                    . 'poi, quei giorni darebbero', self::giorno((string) $c['accordo_dal'])),
                default => 'con il giornale di oggi quei giorni darebbero',
            };
            $frasi[$k] = sprintf('%s; %s %s: per cambiarli, annulla l\'addebito e rifai la proposta.', $erano, $perche, MoneyHelper::format($darebbero[$k]));
        }

        return $frasi;
    }

    /**
     * L'ultima data fino a cui gli interessi di ogni riga (persona, rata, unità) sono già registrati, e ogni registrazione con il suo primo giorno e la
     * sua cifra: dalle quote degli interessi non annullate. L'ultimo giorno è quello della riga (`al`), quando la riga si fermava prima della data: il
     * giorno prima di un accordo di rientro (decisione 438); i giorni dell'accordo non sono registrati, e tornano se l'accordo decade. Le righe registrate
     * prima che ci fosse `al` arrivano alla data. La riga del debito ereditato (decisione 489) ha la chiave con il suffisso del passaggio, «|s…». Con la
     * cifra cambiata prima di registrare (decisione 493 (10)), `proposti` è quella proposta, `interessi` quella registrata.
     *
     * @param list<int> $persone
     * @return array{al: array<string, string>, registrazioni: array<string, list<array{alla: string, registrata: string, dal: ?string, interessi: int, proposti: int}>>}
     */
    private function giaRegistrati(int $condominioId, array $persone): array
    {
        $esito = [];
        $registrazioni = [];
        $quote = RataQuote::whereIn('anagrafica_id', $persone)->where('tipo', RataQuote::TIPO_ACCESSORIO)
            ->whereIn('rata_id', DB::table('rate')->whereIn('piano_rate_id', PianoRate::degliAddebiti()->where('condominio_id', $condominioId)->select('id'))->select('id'))
            ->get(['id', 'anagrafica_id', 'regole_calcolo']);
        foreach ($quote as $q) {
            $d = AddebitiIndividuali::dati($q);
            if (($d['tipo'] ?? null) !== 'interessi' || ! isset($d['interessi']['alla'])) {
                continue;
            }
            // Gli interessi del defunto passati agli eredi (decisione 493 (13)) restano registrati per i giorni del defunto: le quote degli eredi ne sono la
            // copia per quota, e non registrano i loro giorni.
            $regole = is_string($q->regole_calcolo) ? (json_decode($q->regole_calcolo, true) ?: []) : (array) $q->regole_calcolo;
            if (isset($regole['dagli_eredi'])) {
                continue;
            }
            foreach ($d['interessi']['righe'] ?? [] as $r) {
                $k = (int) $q->anagrafica_id . '|' . (int) $r['rata_id'] . '|' . (int) ($r['immobile_id'] ?? 0)
                    . (isset($r['ereditata']['subentro_id']) ? '|s' . (int) $r['ereditata']['subentro_id'] : '');
                $al = (string) ($r['al'] ?? $d['interessi']['alla']);
                $esito[$k] = max($esito[$k] ?? '', $al);
                // `alla` è l'ultimo giorno che la riga conta, fin dove il riallineo rifà il calcolo; `registrata` la data dell'addebito, che ne porta il nome.
                $registrazioni[$k][] = ['alla' => $al, 'registrata' => (string) $d['interessi']['alla'], 'dal' => $r['dal'] ?? null, 'interessi' => (int) ($r['interessi'] ?? 0),
                    'proposti' => (int) ($r['proposti'] ?? $r['interessi'] ?? 0)];
            }
        }

        return ['al' => $esito, 'registrazioni' => $registrazioni];
    }

    /**
     * Il ruolo di ogni quota nel riparto del suo piano (`righe_riparto.ruolo_risolto` del soggetto del riparto sulla sua unità): «inquilino» se tutte le
     * sue righe sono dell'inquilino, «titolare» altrimenti; la quota senza righe non c'è.
     *
     * @param Collection<int, RataQuote> $quote
     * @param Collection<int, object> $rate
     * @return array<string, string> persona|rata|unità => ruolo
     */
    private function ruoliDelRiparto(Collection $quote, Collection $rate): array
    {
        $soggetto = [];
        foreach ($quote as $q) {
            if ($q->tipo === RataQuote::TIPO_ACCESSORIO || $q->immobile_id === null || ! isset($rate[$q->rata_id])) {
                continue;
            }
            $regole = is_string($q->regole_calcolo) ? (json_decode($q->regole_calcolo, true) ?: []) : (array) $q->regole_calcolo;
            $soggetto[(int) $q->anagrafica_id . '|' . (int) $q->rata_id . '|' . (int) $q->immobile_id] = [(int) ($regole['riassegnazione']['righe_di'] ?? $q->anagrafica_id),
                (int) $rate[$q->rata_id]->piano_rate_id, (int) $q->immobile_id];
        }
        if ($soggetto === []) {
            return [];
        }
        $inquilino = \App\Enums\RuoloAnagraficaImmobile::INQUILINO->value;
        $righe = DB::table('righe_riparto')->whereIn('piano_rate_id', array_unique(array_column($soggetto, 1)))->whereIn('immobile_id', array_unique(array_column($soggetto, 2)))
            ->whereIn('anagrafica_id', array_unique(array_column($soggetto, 0)))->whereNotNull('ruolo_risolto')->where('importo', '!=', 0)
            ->select(['piano_rate_id', 'anagrafica_id', 'immobile_id'])->selectRaw("MIN(CASE WHEN ruolo_risolto = ? THEN 1 ELSE 0 END) as solo_inquilino", [$inquilino])
            ->groupBy('piano_rate_id', 'anagrafica_id', 'immobile_id')->get()
            ->mapWithKeys(fn ($r) => [(int) $r->anagrafica_id . '|' . (int) $r->piano_rate_id . '|' . (int) $r->immobile_id => (int) $r->solo_inquilino === 1]);
        $esito = [];
        foreach ($soggetto as $k => [$chi, $piano, $unita]) {
            if (isset($righe[$chi . '|' . $piano . '|' . $unita])) {
                $esito[$k] = $righe[$chi . '|' . $piano . '|' . $unita] ? 'inquilino' : 'titolare';
            }
        }

        return $esito;
    }

    /**
     * Chi ha avuto un diritto reale su ogni unità, anche con la riga spenta: per una quota senza riparto, quella di chi non l'ha mai avuto è di un
     * inquilino.
     *
     * @param list<int> $unita
     * @return array<int, list<int>>
     */
    private function titolari(array $unita): array
    {
        if ($unita === []) {
            return [];
        }
        $ruoli = array_map(fn ($r) => $r->value, \App\Enums\RuoloAnagraficaImmobile::titolariDiDirittoReale());

        return DB::table('anagrafica_immobile')->whereIn('immobile_id', $unita)->whereIn('tipologia', $ruoli)
            ->get(['immobile_id', 'anagrafica_id'])->groupBy('immobile_id')
            ->map(fn ($g) => $g->pluck('anagrafica_id')->map(fn ($id) => (int) $id)->unique()->values()->all())->all();
    }

    /**
     * Dove si scrivono gli interessi delle rate di ogni gestione: la stessa gestione, nell'esercizio aperto che contiene la data; senza, una gestione
     * dello stesso tipo di quell'esercizio. Con le voci che possono ricevere il denaro (le voci di entrata della gestione, con il mastro) e l'ultima scelta.
     *
     * @param list<int> $gestioni
     * @return list<array{gestione_id: int, gestione: string, scrive: ?array, perche: ?string, voci: list<array{id: int, nome: string}>, voce_proposta: ?int}>
     */
    private function doveSiScrive(Condominio $condominio, string $alla, array $gestioni): array
    {
        if ($gestioni === []) {
            return [];
        }
        $aperti = DB::table('esercizio_gestione as eg')->join('esercizi as e', 'e.id', '=', 'eg.esercizio_id')->join('gestioni as g', 'g.id', '=', 'eg.gestione_id')
            ->where('e.condominio_id', $condominio->id)->where('e.stato', 'aperto')->where('e.data_inizio', '<=', $alla . ' 23:59:59')->where('e.data_fine', '>=', $alla)
            // Dal più recente: fra due esercizi aperti che contengono il giorno si scrive in quello che comincia dopo (Coda 646, decisione 707 (4), `HasEsercizio`).
            ->orderByDesc('e.data_inizio')->orderByDesc('e.id')->orderBy('g.id')
            ->get(['eg.gestione_id', 'eg.esercizio_id', 'g.nome as gestione', 'g.tipo', 'e.nome as esercizio']);
        $esito = [];
        foreach (Gestione::whereIn('id', $gestioni)->get() as $g) {
            $scelta = $aperti->first(fn ($a) => (int) $a->gestione_id === (int) $g->id) ?? $aperti->first(fn ($a) => $a->tipo === $g->tipo);
            // `piano_conto_id`: il piano dei conti della gestione, dove la finestra manda a creare la voce che manca (H6 della beta.12, Coda 678).
            $scrive = $scelta === null ? null : ['gestione_id' => (int) $scelta->gestione_id, 'esercizio_id' => (int) $scelta->esercizio_id,
                'nome' => $scelta->gestione . ' (' . $scelta->esercizio . ')',
                'piano_conto_id' => ($pc = DB::table('piani_conti')->where('condominio_id', $condominio->id)->where('gestione_id', $scelta->gestione_id)->orderBy('id')->value('id')) === null ? null : (int) $pc];
            $voci = $scrive === null ? [] : Conto::query()->join('piani_conti as pc', 'pc.id', '=', 'conti.piano_conto_id')
                ->where('pc.gestione_id', $scrive['gestione_id'])->where('pc.condominio_id', $condominio->id)
                ->whereNotIn('conti.tipo', Conto::TIPI_DI_SPESA)->whereNotNull('conti.conto_contabile_id')->whereDoesntHave('sottoconti')->whereHas('tabelleMillesimali')
                ->orderBy('conti.nome')->get(['conti.id', 'conti.nome'])->map(fn ($c) => ['id' => (int) $c->id, 'nome' => (string) $c->nome])->values()->all();
            $esito[] = [
                'gestione_id' => (int) $g->id,
                'gestione' => (string) $g->nome,
                'scrive' => $scrive,
                'perche' => $scrive === null ? 'Nessun esercizio aperto contiene il ' . self::giorno($alla) . ' con una gestione come «' . $g->nome . '»: gli interessi delle sue rate non si scrivono.' : null,
                'voci' => $voci,
                'voce_proposta' => $scrive === null ? null : $this->ultimaVoce((int) $condominio->id, $scrive['gestione_id'], array_column($voci, 'id')),
            ];
        }

        return $esito;
    }

    /** La voce degli ultimi interessi registrati in quella gestione, se è ancora fra quelle possibili: l'ultima scelta dell'amministratore. */
    private function ultimaVoce(int $condominioId, int $gestioneId, array $possibili): ?int
    {
        $quote = RataQuote::where('tipo', RataQuote::TIPO_ACCESSORIO)
            ->whereIn('rata_id', DB::table('rate')->whereIn('piano_rate_id', PianoRate::degliAddebiti()->where('condominio_id', $condominioId)->where('gestione_id', $gestioneId)->select('id'))->select('id'))
            ->orderByDesc('id')->limit(50)->get(['id', 'regole_calcolo']);
        foreach ($quote as $q) {
            $d = AddebitiIndividuali::dati($q);
            if (($d['tipo'] ?? null) === 'interessi' && in_array((int) ($d['conto_id'] ?? 0), $possibili, true)) {
                return (int) $d['conto_id'];
            }
        }

        return null;
    }

    /**
     * Decisione 511: i tassi di una riga, in ordine di giorno, senza ripetere quello del tratto prima: «1,60 %», o «1,60 %» e «2,00 %» se nel periodo cambia.
     *
     * @param list<array<string, mixed>> $tratti
     * @return list<string>
     */
    public static function tassiDeiTratti(array $tratti): array
    {
        $tassi = [];
        foreach ($tratti as $t) {
            $etichetta = (string) ($t['tasso'] ?? '');
            if ($etichetta !== '' && end($tassi) !== $etichetta) {
                $tassi[] = $etichetta;
            }
        }

        return $tassi;
    }

    /** «Rata 2 — Preventivo 2026»; un addebito con la sua causale; la rata 0 «Rata 0 (pregresso)». */
    private static function nomeDellaRata(object $rata): string
    {
        if ($rata->natura === PianoRate::NATURA_ADDEBITI) {
            return trim((string) $rata->descrizione) !== '' ? (string) $rata->descrizione : 'Addebito';
        }

        return ((int) $rata->numero_rata === 0 ? 'Rata 0 (pregresso)' : 'Rata ' . (int) $rata->numero_rata) . ' — ' . $rata->piano;
    }

    private static function giorno(string $data): string
    {
        return substr($data, 8, 2) . '/' . substr($data, 5, 2) . '/' . substr($data, 0, 4);
    }

    /** Un importo per chi legge. */
    public static function euro(int $centesimi): string
    {
        return MoneyHelper::format($centesimi);
    }
}
