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
 * interessi già registrati riparte dal giorno dopo l'ultima data registrata: gli stessi giorni non si chiedono due volte.
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
     * La proposta alla data per le persone date (tutte del condominio, già controllate da chi chiama).
     *
     * @param list<int> $persone
     * @return array{alla: string, persone: list<array<string, mixed>>, totale: int, gestioni: list<array<string, mixed>>, regole: array<string, mixed>, frase_domanda_giudiziale: string, tassi_a_mano: list<string>}
     */
    public function proponi(Condominio $condominio, string $alla, array $persone): array
    {
        $regole = (new RegoleDelRecupero())->delCondominio((int) $condominio->id);
        $esito = [];
        foreach (array_chunk(array_values(array_unique($persone)), self::BLOCCO) as $blocco) {
            foreach ($this->perBlocco((int) $condominio->id, $alla, $blocco, $regole) as $p) {
                $esito[] = $p;
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
     * @return array{con: list<int>, fermi: list<int>, perche: ?string}
     */
    public function perSuggerimenti(Condominio $condominio, string $alla, array $persone): array
    {
        $regole = (new RegoleDelRecupero())->delCondominio((int) $condominio->id);
        $esito = ['con' => [], 'fermi' => [], 'perche' => null];
        foreach (array_chunk(array_values(array_unique($persone)), self::BLOCCO) as $blocco) {
            foreach ($this->perBlocco((int) $condominio->id, $alla, $blocco, $regole) as $p) {
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
     * Registra le righe tenute della proposta: la proposta si rifà sul server alla stessa data, e se le cifre delle righe tenute non sono quelle che
     * l'amministratore ha visto, niente si scrive. Una rata per persona, unità e gestione, nel piano degli addebiti della gestione dove si scrive, sulla
     * voce scelta per quella gestione.
     *
     * @param list<string> $righe le chiavi delle righe tenute («persona|rata|unità»)
     * @param array<int, int> $voci gestione dove si scrive => voce che riceve il denaro
     * @return list<\App\Models\Gestionale\Rata>
     */
    public function registra(Condominio $condominio, string $alla, array $righe, int $atteso, array $voci, ?int $utenteId): array
    {
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

        $rate = [];
        DB::transaction(function () use ($condominio, $alla, $gruppi, $causali, $voci, $utenteId, $rifai, &$rate) {
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
                            'righe' => array_map(fn ($r) => array_intersect_key($r, array_flip(['rata_id', 'immobile_id', 'rata', 'scadenza', 'dal', 'regola_inizio', 'tratti', 'interessi'])), $lista),
                        ],
                    ];
                }
                foreach ($this->addebiti->registra($condominio, $gestione, $esercizio, $daScrivere, $utenteId, $alla) as $r) {
                    $rate[] = $r;
                }
            }
        });

        return $rate;
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
                $causale = 'Interessi di mora al ' . self::giorno($alla) . ($tassi === null ? '' : ' (' . $tassi . ')');
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
     * La proposta di un gruppo di persone: il loro giornale fino alla data, le chiavi della base, il calcolo.
     *
     * @param list<int> $persone
     * @return list<array<string, mixed>>
     */
    private function perBlocco(int $condominioId, string $alla, array $persone, Collection $regole): array
    {
        if ($persone === []) {
            return [];
        }
        $dopo = CarbonImmutable::parse($alla, 'UTC')->addDay()->format('Y-m-d');
        $righe = DB::table('righe_scritture as rs')
            ->join('scritture_contabili as sc', 'sc.id', '=', 'rs.scrittura_id')
            ->join('conti_contabili as cc', 'cc.id', '=', 'rs.conto_contabile_id')
            ->whereNull('sc.deleted_at')->where('sc.condominio_id', $condominioId)->where('sc.data_competenza', '<', $dopo)
            ->whereIn('cc.ruolo', ['crediti_condomini', 'anticipi_condomini'])->whereIn('rs.anagrafica_id', $persone)
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
        $titolari = $this->titolari($righe->pluck('immobile_id')->filter()->unique()->values()->all());
        $ruoli = $this->ruoliDelRiparto($quote, $rate);
        $nomi = DB::table('anagrafiche')->whereIn('id', $persone)->pluck('nome', 'id');
        $unita = \App\Models\Immobile::whereIn('id', $righe->pluck('immobile_id')->filter()->unique()->values()->all() ?: [0])->get()
            ->mapWithKeys(fn (\App\Models\Immobile $i) => [(int) $i->id => (string) $i->etichetta]);
        // Il debito coperto da un accordo di rientro non decaduto, finché il calendario non è finito o se è tutto versato: niente interessi su quello,
        // anche sulla rata coperta già versata (decisioni 389 (2) e 433). Con la decadenza, o finito il calendario con qualcosa da versare, la proposta
        // torna. Per persona, «rata|unità» => la parte coperta.
        $coperteDa = app(\App\Services\Gestionale\Debiti\AccordiRientro::class)->senzaInteressi($condominioId, $persone, $alla);
        $tassoAl = RegoleDelRecupero::tassoAl($regole);
        $cambi = RegoleDelRecupero::cambi($regole);

        $esito = [];
        foreach ($righe->groupBy('anagrafica_id') as $persona => $sue) {
            $persona = (int) $persona;
            $chiavi = [];
            $movimenti = [];
            $inquilino = [];
            foreach ($sue as $r) {
                $giorno = substr((string) $r->data_competenza, 0, 10);
                $netto = (int) $r->netto;
                if ($netto === 0) {
                    continue;
                }
                if ($r->ruolo === 'anticipi_condomini') {
                    $k = 'anticipi';
                    $chiavi[$k] ??= ['inizio' => null, 'base' => false, 'ordine' => ['9999-12-31', 9, 0], 'tipo' => 'anticipi'];
                } elseif ($r->rata_id === null || ! isset($rate[$r->rata_id])) {
                    $k = 'senza_rata|' . (int) $r->immobile_id;
                    $chiavi[$k] ??= ['inizio' => null, 'base' => false, 'ordine' => ['9999-12-31', 9, 0], 'tipo' => 'senza_rata'];
                } else {
                    $rata = $rate[$r->rata_id];
                    $k = $persona . '|' . (int) $r->rata_id . '|' . (int) $r->immobile_id;
                    if (! isset($chiavi[$k])) {
                        $scadenza = Scadenza::giorno((string) $rata->data_scadenza);
                        $accessorio = isset($accessori[$k]);
                        // Il ruolo della quota nel riparto del suo piano; senza riparto (la rata 0, un contributo), chi non ha mai avuto un diritto reale.
                        $diInquilino = $r->immobile_id !== null && (isset($ruoli[$k]) ? $ruoli[$k] === 'inquilino'
                            : ! in_array($persona, $titolari[(int) $r->immobile_id] ?? [], true));
                        $inizio = $inizioPieno = RegoleDelRecupero::primoGiorno($regole, $scadenza);
                        // La regola che ha spostato il primo giorno con i suoi giorni di decorrenza: anche lei è usata da questi interessi.
                        $regolaInizio = RegoleDelRecupero::alGiorno($regole, CarbonImmutable::parse($scadenza, 'UTC')->addDay()->format('Y-m-d'));
                        $regolaInizio = $regolaInizio !== null && (int) $regolaInizio->giorni_decorrenza > 0 ? (int) $regolaInizio->id : null;
                        if (isset($giaRegistrati[$k]) && $giaRegistrati[$k] >= $inizio) {
                            $inizio = CarbonImmutable::parse($giaRegistrati[$k], 'UTC')->addDay()->format('Y-m-d');
                            $regolaInizio = null;
                        }
                        $chiavi[$k] = ['inizio' => $accessorio || $diInquilino ? null : $inizio, 'base' => ! $accessorio && ! $diInquilino,
                            'ordine' => OrdineImputazione::chiave($scadenza, (int) $rata->numero_rata, true, (int) $r->rata_id),
                            'tipo' => $accessorio ? 'accessorio' : ($diInquilino ? 'inquilino' : 'base'), 'scadenza' => $scadenza, 'rata' => $rata,
                            'immobile_id' => $r->immobile_id === null ? null : (int) $r->immobile_id, 'gia_al' => $giaRegistrati[$k] ?? null,
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
            if ($chiavi === []) {
                continue;
            }
            // Le rate coperte dall'accordo escono dalla base, tutte: anche la parte fuori di una rata coperta in parte.
            $nellAccordo = 0;
            foreach ($coperteDa[$persona] ?? [] as $chiaveAccordo => $parte) {
                [$rataCoperta, $unitaCoperta] = explode('|', $chiaveAccordo);
                $k = $persona . '|' . (int) $rataCoperta . '|' . (int) $unitaCoperta;
                if (isset($chiavi[$k]) && $chiavi[$k]['base']) {
                    $chiavi[$k]['base'] = false;
                    $chiavi[$k]['inizio'] = null;
                    $chiavi[$k]['tipo'] = 'accordo';
                    $nellAccordo += (int) $parte;
                }
            }
            $calcolo = InteressiDiMora::calcola(array_map(fn ($c) => array_intersect_key($c, array_flip(['inizio', 'base', 'ordine'])), $chiavi), $movimenti, $tassoAl, $cambi, $alla);
            $riallineo = $this->riallineo($chiavi, $movimenti, $registrazioni, $tassoAl, $cambi);

            // Il saldo di ogni chiave alla data: i crediti e gli anticipi accanto alla persona, e le quote di inquilini scadute.
            $saldi = [];
            foreach ($movimenti as $m) {
                $saldi[$m['chiave']] = ($saldi[$m['chiave']] ?? 0) + $m['importo'];
            }
            $crediti = 0;
            $anticipi = 0;
            foreach ($saldi as $k => $s) {
                if ($s < 0) {
                    $chiavi[$k]['tipo'] === 'anticipi' ? $anticipi += -$s : $crediti += -$s;
                }
                if ($s > 0 && $chiavi[$k]['tipo'] === 'inquilino' && $chiavi[$k]['scadenza'] < $alla) {
                    $inquilino[] = $s;
                }
            }

            $righePersona = [];
            foreach ($calcolo as $k => $c) {
                if ($c['interessi'] <= 0 && $c['fermo'] === null) {
                    continue;
                }
                $meta = $chiavi[$k];
                $rata = $meta['rata'];
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
                    'gia_registrati_al' => $meta['gia_al'],
                    'riallineo' => $riallineo[$k] ?? null,
                    'regola_inizio' => $meta['regola_inizio'],
                    'tratti' => $c['tratti'],
                    'interessi' => $c['interessi'],
                    'fermo' => $c['fermo'],
                ];
            }
            usort($righePersona, fn ($a, $b) => [$a['scadenza'], $a['rata_id'], $a['immobile_id']] <=> [$b['scadenza'], $b['rata_id'], $b['immobile_id']]);
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
            ];
        }

        return $esito;
    }

    /**
     * Gli interessi registrati che il giornale di oggi darebbe diversi: un versamento o uno storno registrato dopo con una data di prima cambia i giorni
     * già chiesti. Per ogni registrazione il calcolo si rifà con il giornale di oggi, dal suo primo giorno al suo ultimo; se la somma non è quella
     * registrata, la riga lo dice. Gli interessi registrati restano come sono: li cambia l'amministratore, annullando l'addebito.
     *
     * @param array<string, array<string, mixed>> $chiavi
     * @param list<array<string, mixed>> $movimenti
     * @param array<string, list<array{alla: string, dal: ?string, interessi: int}>> $registrazioni
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
        $fermi = [];
        foreach ($perGiorno as $giorno => $registrate) {
            $chiaviDelGiorno = [];
            foreach ($chiavi as $k => $c) {
                $chiaviDelGiorno[$k] = ['inizio' => isset($registrate[$k]) ? ($registrate[$k]['dal'] ?? $c['inizio_pieno'] ?? null) : ($c['inizio_pieno'] ?? null),
                    'base' => $c['base'], 'ordine' => $c['ordine']];
            }
            $esito = InteressiDiMora::calcola($chiaviDelGiorno, array_values(array_filter($movimenti, fn ($m) => $m['giorno'] <= $giorno)), $tassoAl, $cambi, $giorno);
            foreach (array_keys($registrate) as $k) {
                if (($esito[$k]['fermo'] ?? null) !== null) {
                    $fermi[$k] = true;
                }
                $darebbero[$k] = ($darebbero[$k] ?? 0) + (int) ($esito[$k]['interessi'] ?? 0);
            }
        }
        $frasi = [];
        foreach ($diQui as $k => $lista) {
            $erano = (int) array_sum(array_column($lista, 'interessi'));
            if (isset($fermi[$k]) || $darebbero[$k] === $erano) {
                continue;
            }
            $frasi[$k] = sprintf('Gli interessi registrati al %s erano %s; con il giornale di oggi quei giorni darebbero %s: per cambiarli, annulla l\'addebito e rifai '
                . 'la proposta.', self::giorno((string) max(array_column($lista, 'alla'))), MoneyHelper::format($erano), MoneyHelper::format($darebbero[$k]));
        }

        return $frasi;
    }

    /**
     * L'ultima data fino a cui gli interessi di ogni riga (persona, rata, unità) sono già registrati, e ogni registrazione con il suo primo giorno e la
     * sua cifra: dalle quote degli interessi non annullate.
     *
     * @param list<int> $persone
     * @return array{al: array<string, string>, registrazioni: array<string, list<array{alla: string, dal: ?string, interessi: int}>>}
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
            foreach ($d['interessi']['righe'] ?? [] as $r) {
                $k = (int) $q->anagrafica_id . '|' . (int) $r['rata_id'] . '|' . (int) ($r['immobile_id'] ?? 0);
                $esito[$k] = max($esito[$k] ?? '', (string) $d['interessi']['alla']);
                $registrazioni[$k][] = ['alla' => (string) $d['interessi']['alla'], 'dal' => $r['dal'] ?? null, 'interessi' => (int) ($r['interessi'] ?? 0)];
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
            ->orderBy('e.data_inizio')->orderBy('g.id')
            ->get(['eg.gestione_id', 'eg.esercizio_id', 'g.nome as gestione', 'g.tipo', 'e.nome as esercizio']);
        $esito = [];
        foreach (Gestione::whereIn('id', $gestioni)->get() as $g) {
            $scelta = $aperti->first(fn ($a) => (int) $a->gestione_id === (int) $g->id) ?? $aperti->first(fn ($a) => $a->tipo === $g->tipo);
            $scrive = $scelta === null ? null : ['gestione_id' => (int) $scelta->gestione_id, 'esercizio_id' => (int) $scelta->esercizio_id,
                'nome' => $scelta->gestione . ' (' . $scelta->esercizio . ')'];
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
