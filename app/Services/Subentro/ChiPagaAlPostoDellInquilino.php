<?php

namespace App\Services\Subentro;

use App\Enums\RuoloAnagraficaImmobile;
use App\Helpers\MoneyHelper;
use App\Models\Anagrafica;
use App\Models\Gestionale\Subentro;
use App\Models\TitolaritaImmobile;
use App\Services\Riparto\RisolutoreTitolari;
use App\Services\Riparto\UnitaMista;
use App\Support\InsiemePeriodi;
use App\Support\PeriodoCompetenza;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Decisione 77 (1.11.0-beta.50), con le decisioni 21 e 32: chi paga al posto dell'inquilino nei giorni dopo la sua uscita, per
 * persona e per giorno.
 *
 * È la catena del ripiego della generazione (`CalcoloQuoteService::ripiegoGiorniScoperti`, decisione 22) sugli stessi giorni:
 * l'usufruttuario, poi il proprietario, poi il nudo proprietario, ogni anello sui giorni che il precedente non copre, con il ruolo
 * gemello sull'unità mista (decisione 31.1). Dentro l'anello ogni riga pesa quota × giorni, e una persona pesa con **tutte** le sue
 * righe: la stessa persona con due righe da proprietario non vale una riga sola, e chi entra con una vendita già registrata, con una
 * data dopo l'uscita, prende i giorni dalla vendita (l'ordine inverso si chiude da sé, senza fermate). Fra anelli i pesi sono giorni.
 *
 * Una funzione sola per tre cose: la coppia della fine di una locazione senza un nuovo inquilino (`AnteprimaPassaggio`), la fermata
 * dopo «Scrivi il conguaglio» (`FermataDopoScrivi`) e la frase «tornano a …» (`FrasiObbligati`). La coppia arrotonda in due giri — la
 * parte di chi esce con `ProRataTemporis`, poi la divisione per persona con i resti maggiori — e il piano generato dopo la fine in uno
 * solo: fra i due resta al più un centesimo per unità, ed è la tolleranza dichiarata (verbale della .50, «Le scelte tecniche»).
 */
final class ChiPagaAlPostoDellInquilino
{
    /** La chiave della parte dei giorni che nessuno copre, nella divisione della coppia (`dividiLaCoppia`): resta nelle rate di chi esce. */
    private const NESSUNO = 'nessuno';

    private readonly UnitaMista $unitaMista;

    public function __construct(private readonly RisolutoreTitolari $titolari = new RisolutoreTitolari())
    {
        $this->unitaMista = new UnitaMista($this->titolari);
    }

    /**
     * Le righe di titolarità di un'unità nella forma che legge il motore (`attivo`, `tipologia`, `quota`, le date): tutte, anche fuori
     * periodo, perché la regola D7 guarda il predecessore della riga.
     *
     * @return Collection<int, object>
     */
    public function righe(int $immobileId): Collection
    {
        return DB::table('anagrafica_immobile')->where('immobile_id', $immobileId)->orderBy('id')
            ->get(['id', 'anagrafica_id', 'immobile_id', 'tipologia', 'quota', 'attivo', 'data_inizio', 'data_fine']);
    }

    /**
     * I pesi per persona sui giorni dati (anagrafica → peso), con la regola del ripiego della generazione. I giorni che nessun anello
     * copre non pesano: la generazione li lascia scoperti, e qui non si tira a indovinare chi li paga. Vuoto se nessuno li copre. La
     * coppia della fine tiene a parte la parte di quei giorni (`dividiLaCoppia`); qui servono solo le persone, per i nomi della frase
     * «tornano a …» e per la fermata.
     *
     * @param Collection<int, object> $righeUnita le righe dell'unità (`righe()`); chi calcola la titolarità di dopo un passaggio non
     *                                           ancora registrato passa le sue
     * @return array<int, float>
     */
    public function pesi(Collection $righeUnita, InsiemePeriodi $giorni): array
    {
        return $this->pesiEScoperti($righeUnita, $giorni)[0];
    }

    /**
     * I pesi di `pesi()` e i giorni che nessun anello copre (null se li coprono tutti).
     *
     * @param Collection<int, object> $righeUnita
     * @return array{0: array<int, float>, 1: ?InsiemePeriodi}
     */
    private function pesiEScoperti(Collection $righeUnita, InsiemePeriodi $giorni): array
    {
        $pesi = [];
        $residuo = $giorni;
        $scoperti = $giorni;
        foreach (RuoloAnagraficaImmobile::catenaRipiego(RuoloAnagraficaImmobile::INQUILINO->value) as $ruolo) {
            $coppia = $righeUnita->filter(fn ($r) => ($r->tipologia ?? null) === $ruolo->value && (bool) ($r->attivo ?? false))->values();
            $anello = [];
            $aggiungi = function (object $riga, Collection $stessaCoppia) use (&$anello, $residuo): void {
                if ((float) ($riga->quota ?? 0) <= 0.0) {
                    return;
                }
                $g = $this->titolari->giorniDiTitolarita($riga, $residuo, $stessaCoppia);
                if ($g > 0) {
                    $anello[] = ['riga' => $riga, 'anagrafica_id' => (int) $riga->anagrafica_id, 'peso' => (float) $riga->quota * $g];
                }
            };
            foreach ($coppia as $riga) {
                $aggiungi($riga, $coppia);
            }
            if ($anello === []) {
                continue;
            }
            // Decisione 31.1 (Coda 170): sull'unità mista l'anello si divide con il ruolo gemello, come nella generazione.
            $gemelle = $this->unitaMista->righeGemelle($righeUnita, collect($anello)->pluck('riga'), $ruolo->value, $residuo);
            if ($gemelle->isNotEmpty()) {
                $tipologiaGemella = (string) $gemelle->first()->tipologia;
                $coppiaGemella = $righeUnita->filter(fn ($r) => ($r->tipologia ?? null) === $tipologiaGemella && (bool) ($r->attivo ?? false))->values();
                foreach ($gemelle as $riga) {
                    $aggiungi($riga, $coppiaGemella);
                }
                $coppia = $coppia->concat($coppiaGemella)->values();
            }
            $dopo = $this->titolari->giorniScoperti($coppia, $residuo);
            $coperti = $residuo->giorni() - ($dopo?->giorni() ?? 0);
            $somma = array_sum(array_column($anello, 'peso'));
            foreach ($anello as $r) {
                $pesi[$r['anagrafica_id']] = ($pesi[$r['anagrafica_id']] ?? 0.0) + ($somma > 0 ? $r['peso'] / $somma * $coperti : 0.0);
            }
            $scoperti = $dopo;
            if ($dopo === null) {
                break;
            }
            $residuo = $dopo;
        }

        return [array_filter($pesi, fn (float $p) => $p > 0.0), $scoperti];
    }

    /**
     * La coppia della fine di una locazione senza un nuovo inquilino, divisa fra chi paga al posto dell'inquilino. La coppia può essere
     * fatta di più pezzi, uno per competenza (più piani con competenze diverse sulla stessa gestione, o le voci a tratti per capitolo):
     * ogni pezzo pesa con il suo importo, **con il segno** — un capitolo di entrata toglie dalla parte di chi ha i suoi giorni, e non pesa
     * come una spesa (Fase 1-bis della .50, R3) —, diviso fra le persone dei suoi giorni. Le parti esatte si arrotondano una volta sola,
     * con i resti maggiori (`MoneyHelper::ripartisciPerQuote`); quando la somma dei pesi è negativa (una coppia negativa, l'entrata più
     * grande delle spese) i pesi cambiano segno, e il segno lo rimette la divisione dal totale. Con pesi di segno diverso (una persona
     * con più giorni di entrata che di spesa) la somma torna lo stesso, e la parte di quella persona può uscire negativa.
     *
     * Fase 1-bis della .50 (R2): i giorni dopo l'uscita che nessuno copre (la titolarità scoperta per una parte di quei giorni) non si
     * spalmano su chi copre gli altri: la loro parte (`scoperta`) resta nelle rate di chi esce, come il gruppo in cui nessuno sta al posto
     * dell'inquilino, e la generazione lascerebbe quei giorni scoperti. A pari resto il centesimo va a quella parte, cioè a chi esce. I
     * pezzi che si restituiscono (`pezzi`) hanno solo i tratti coperti, con il loro importo: il registro della coppia li conserva, e la
     * fermata dopo «Scrivi» ridivide la coppia su quei giorni soli.
     *
     * @param Collection<int, object> $righeUnita
     * @param list<array{tratti: InsiemePeriodi, importo: int}> $pezzi
     * @return array{parti: array<int, int>, scoperta: int, scoperti: ?InsiemePeriodi, coperti: ?InsiemePeriodi, pezzi: list<array{tratti: InsiemePeriodi, importo: int}>}
     */
    public function dividiLaCoppia(int $importo, Collection $righeUnita, array $pezzi): array
    {
        $pesi = [];
        $buco = 0.0;
        $scoperti = null;
        $coperti = null;
        $suoiPezzi = [];
        foreach ($pezzi as $pezzo) {
            $giorni = $pezzo['tratti']->giorni();
            if ($giorni <= 0) {
                continue;
            }
            [$suoi, $restano] = $this->pesiEScoperti($righeUnita, $pezzo['tratti']);
            // Con un pezzo solo i pesi sono i giorni, come la divisione di sempre: niente arrotondamenti in più sui resti pari.
            $scala = count($pezzi) === 1 ? fn (float $g): float => $g : fn (float $g): float => (int) $pezzo['importo'] * $g / $giorni;
            foreach ($suoi as $anagraficaId => $peso) {
                $pesi[$anagraficaId] = ($pesi[$anagraficaId] ?? 0.0) + $scala($peso);
            }
            $tratti = $pezzo['tratti'];
            $esatto = null;
            if ($restano !== null) {
                $buco += $scala((float) $restano->giorni());
                $scoperti = $scoperti === null ? $restano : $scoperti->unione($restano);
                foreach ($restano->periodi() as $t) {
                    $tratti = $tratti?->meno($t);
                }
                $esatto = (int) $pezzo['importo'] * ($giorni - $restano->giorni()) / $giorni;
            }
            if ($tratti !== null) {
                $coperti = $coperti === null ? $tratti : $coperti->unione($tratti);
            }
            $suoiPezzi[] = ['tratti' => $tratti, 'importo' => (int) $pezzo['importo'], 'esatto' => $esatto];
        }
        $tutti = ($scoperti !== null ? [self::NESSUNO => $buco] : []) + $pesi;
        if (array_sum($tutti) < 0) {
            $tutti = array_map(fn (float $p) => -$p, $tutti);
        }
        $divisi = $tutti === [] ? [] : array_map('intval', MoneyHelper::ripartisciPerQuote($importo, $tutti));
        $scoperta = (int) ($divisi[self::NESSUNO] ?? 0);
        unset($divisi[self::NESSUNO]);

        return [
            'parti' => array_filter($divisi, fn (int $c) => $c !== 0),
            'scoperta' => $scoperta,
            'scoperti' => $scoperti,
            'coperti' => $coperti,
            'pezzi' => self::pezziCoperti($suoiPezzi, $scoperta),
        ];
    }

    /**
     * I pezzi della coppia con i soli tratti coperti (R2). Un pezzo senza giorni scoperti resta com'è; quelli con giorni scoperti prendono
     * la loro parte coperta, in centesimi, e insieme valgono il loro importo meno la parte scoperta della coppia (il centesimo che avanza al
     * pezzo più grande); un pezzo tutto scoperto non c'è più.
     *
     * @param list<array{tratti: ?InsiemePeriodi, importo: int, esatto: ?float}> $pezzi
     * @return list<array{tratti: InsiemePeriodi, importo: int}>
     */
    private static function pezziCoperti(array $pezzi, int $scoperta): array
    {
        $conIlBuco = array_keys(array_filter($pezzi, fn (array $p) => $p['esatto'] !== null));
        if ($conIlBuco !== []) {
            $resta = (int) array_sum(array_map(fn (int $i) => $pezzi[$i]['importo'], $conIlBuco)) - $scoperta;
            foreach ($conIlBuco as $i) {
                $pezzi[$i]['importo'] = (int) round($pezzi[$i]['esatto']);
                $resta -= $pezzi[$i]['importo'];
            }
            $grande = collect($conIlBuco)->filter(fn (int $i) => $pezzi[$i]['tratti'] !== null)->sortByDesc(fn (int $i) => abs($pezzi[$i]['esatto']))->first();
            if ($grande !== null) {
                $pezzi[$grande]['importo'] += $resta;
            }
        }

        return array_values(array_map(fn (array $p) => ['tratti' => $p['tratti'], 'importo' => $p['importo']],
            array_filter($pezzi, fn (array $p) => $p['tratti'] !== null && $p['importo'] !== 0)));
    }

    /**
     * La regola della coppia fra le persone: a ognuno la parte intera, il centesimo che avanza a chi ha il resto più grande
     * (`MoneyHelper::ripartisciPerQuote`). A pari resto vince chi viene prima, e `$primo`, se è fra le persone, viene prima di tutti:
     * nella fermata è chi esce (verbale della .50, «a pari resto a chi esce»). Le parti a zero non si tengono.
     *
     * @param array<int, float> $pesi
     * @return array<int, int>
     */
    public function dividi(int $importo, array $pesi, ?int $primo = null): array
    {
        if ($pesi === []) {
            return [];
        }
        if ($primo !== null && array_key_exists($primo, $pesi)) {
            $pesi = [$primo => $pesi[$primo]] + $pesi;
        }

        return array_filter(array_map('intval', MoneyHelper::ripartisciPerQuote($importo, $pesi)), fn (int $c) => $c !== 0);
    }

    /**
     * I giorni dopo l'uscita dentro una competenza: dal giorno del passaggio alla fine dei tratti. Null se la competenza finisce prima.
     * I tratti possono sovrapporsi (le voci di un gruppo con competenze diverse): si uniscono.
     *
     * @param list<array{dal: string, al: string}> $tratti
     */
    public static function giorniDopo(array $tratti, CarbonImmutable $decorrenza): ?InsiemePeriodi
    {
        $insieme = null;
        foreach ($tratti as $t) {
            $uno = InsiemePeriodi::uno(new PeriodoCompetenza($t['dal'], $t['al']));
            $insieme = $insieme === null ? $uno : $insieme->unione($uno);
        }
        if ($insieme === null || $decorrenza->gt($insieme->al())) {
            return null;
        }

        return $insieme->intersezione(new PeriodoCompetenza($decorrenza->lt($insieme->dal()) ? $insieme->dal() : $decorrenza, $insieme->al()));
    }

    /**
     * I giorni della frase «tornano a …», che vale per i piani generati o ricalcolati dopo il passaggio: dal giorno del passaggio alla
     * fine dell'esercizio che lo contiene (o un anno, se l'esercizio non c'è).
     */
    public static function giorniDellaFrase(int $condominioId, CarbonImmutable $decorrenza): InsiemePeriodi
    {
        $fine = DB::table('esercizi')->where('condominio_id', $condominioId)
            ->whereDate('data_inizio', '<=', $decorrenza->toDateString())->whereDate('data_fine', '>=', $decorrenza->toDateString())
            ->orderBy('data_inizio')->value('data_fine');

        return InsiemePeriodi::uno(new PeriodoCompetenza($decorrenza, $fine !== null ? CarbonImmutable::parse(substr((string) $fine, 0, 10)) : $decorrenza->addYear()->subDay()));
    }

    /**
     * Chi paga al posto dell'inquilino su un'unità, nei giorni dati: i nomi, nell'ordine dei pesi (il più grande prima), una volta per
     * persona.
     *
     * @return list<string>
     */
    public function nomi(int $immobileId, InsiemePeriodi $giorni): array
    {
        $pesi = $this->pesi($this->righe($immobileId), $giorni);
        arsort($pesi);
        $nomi = Anagrafica::whereIn('id', array_keys($pesi))->pluck('nome', 'id');

        return array_values(array_filter(array_map(fn ($id) => $nomi[$id] ?? null, array_keys($pesi))));
    }

    /**
     * Decisione 79 (2): le righe d'inquilino di altre persone, con una quota, in corso il giorno prima del passaggio e anche quel giorno.
     * Con loro la locazione continua, e il programma non indovina a chi vanno i giorni dopo l'uscita: nessuna coppia, nessuna scelta. Chi
     * entra il giorno stesso, registrato prima o dopo, non è un coinquilino che resta: è il cambio d'inquilino in due passi (la regola del
     * prospetto degli oneri accessori, una sola per il pannello, lo storico e il prospetto).
     *
     * @return Collection<int, TitolaritaImmobile>
     */
    public static function coinquiliniCheRestano(int $immobileId, int $uscenteId, CarbonImmutable $decorrenza, ?int $rigaMax = null): Collection
    {
        return TitolaritaImmobile::with('anagrafica')->where('immobile_id', $immobileId)->where('tipologia', 'inquilino')
            ->when($rigaMax !== null, fn ($q) => $q->where('id', '<=', $rigaMax))->orderBy('id')->get()
            ->filter(fn (TitolaritaImmobile $t) => (int) $t->anagrafica_id !== $uscenteId && (float) $t->quota > 0
                && $t->inCorsoIl($decorrenza) && $t->inCorsoIl($decorrenza->subDay()))
            ->values();
    }

    /**
     * Decisione 79 (2), per un passaggio registrato: chi restava inquilino sull'unità quando è uscito chi esce. Dove il registro della fine
     * lo dice (la fermata del coinquilino, scritta dal pannello unità per unità) vale il registro, anche se poi il coinquilino è uscito lo
     * stesso giorno; poi i coinquilini letti alla registrazione (`coinquilini_letti`, la fine con la scelta sul conguaglio); per i passaggi
     * che non hanno né l'uno né l'altro (registrati prima della .50, o senza rate da fermare) si guardano le righe, con la regola di
     * `coinquiliniCheRestano`. Lo usano il prospetto, lo storico e il pannello dell'inizio: una regola sola.
     *
     * @return list<string> i nomi, una volta per persona; vuoto se la locazione non continuava
     */
    public static function coinquiliniDelPassaggio(Subentro $capo, int $immobileId, int $uscenteId, CarbonImmutable $decorrenza): array
    {
        $dalRegistro = collect($capo->registro['fermata_coinquilino'] ?? [])->filter(fn ($f) => (int) ($f['immobile_id'] ?? 0) === $immobileId);
        if ($dalRegistro->isNotEmpty()) {
            return $dalRegistro->flatMap(fn ($f) => (array) ($f['restano'] ?? []))->map(fn ($n) => (string) $n)->filter()->unique()->values()->all() ?: ['un coinquilino'];
        }

        // Fase 1-bis della .50 (R6): una fine con la scelta sul conguaglio nel registro si legge com'era quando è stata registrata. Un
        // coinquilino associato dopo, anche con una data prima (l'inizio dimenticato e registrato dopo), non cambia la sua lettura: la coppia è
        // già scritta. Giro stretto della .50 (G4): lo dice il registro, che conserva i coinquilini letti alla registrazione unità per unità,
        // anche quando non ce n'erano; le righe non si rileggono.
        $letti = collect($capo->registro['coinquilini_letti'] ?? [])->first(fn ($v) => (int) ($v['immobile_id'] ?? 0) === $immobileId);
        if ($letti !== null) {
            return array_values(array_unique(array_filter(array_map('strval', (array) ($letti['restano'] ?? [])), fn (string $n) => $n !== '')));
        }
        // Senza quella traccia (un registro scritto prima che ci fosse) contano le righe che c'erano allora (`riga_max_id`): un id ripreso
        // dopo un annullamento, o una riga corretta dopo, la ingannano, ed è il limite di quei registri.
        $rigaMax = isset($capo->registro['conguaglio']['scelta'], $capo->registro['riga_max_id']) ? (int) $capo->registro['riga_max_id'] : null;
        $righe = self::coinquiliniCheRestano($immobileId, $uscenteId, $decorrenza, $rigaMax);

        return $righe->isEmpty() ? [] : ($righe->map(fn (TitolaritaImmobile $t) => $t->anagrafica?->nome)->filter()->unique()->values()->all() ?: ['un coinquilino']);
    }
}
