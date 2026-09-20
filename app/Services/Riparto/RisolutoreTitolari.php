<?php

namespace App\Services\Riparto;

use App\Support\InsiemePeriodi;
use App\Support\PeriodoCompetenza;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Chi è titolare di un'unità — risolto in un posto solo.
 *
 * Fino alla 1.11.0-beta.29 la domanda era sparsa in una ventina di punti (`CalcoloQuoteService`,
 * `GenerateSaldiAction`, `SituazioneDebitoriaController`, l'importatore, i verificatori…), ognuno con
 * il suo `->where('attivo', true)`. B1 del progetto `docs/subentro_e_competenza_temporale.md` (§4.3)
 * li fa passare tutti da qui, in due forme sulla stessa regola:
 *
 * - {@see attiviAlla()} per le collection già caricate — il motore lavora su relazioni eager-loaded,
 *   non su query, e una query per unità sarebbe un N+1 su 44 unità e 8 tabelle;
 * - {@see vincolaQuery()} per i punti che interrogano `anagrafica_immobile` con `DB::table` o con un
 *   join.
 *
 * `RisolutoreTitolariTest` (invariante 4) pretende che rispondano identico sullo stesso dataset.
 *
 * **Dalla 1.11.0-beta.31 (B2, S4) il periodo entra nella regola — D7 del progetto:**
 *
 * - senza periodo, la regola è quella di prima: `attivo === true`, e basta (è ciò che i chiamanti
 *   che non passano il periodo continuano a ottenere: identico al centesimo alla beta.30);
 * - con un periodo (o un insieme di tratti, decisione 20), `data_fine` filtra **sempre** — chi ha
 *   chiuso prima del primo giorno non c'è — e `data_inizio` filtra **solo con un predecessore chiuso**
 *   sulla stessa coppia (immobile, tipologia): senza predecessore la riga è «aperta da sempre», perché
 *   sui dati reali quella data è il giorno del censimento, non della decorrenza (§6.1 del progetto).
 *
 * Chi passa il filtro **partecipa**; quanto pesa lo dice {@see giorniDiTitolarita()} (D8, il pro rata),
 * che il motore usa solo se {@see cambiaTitolaritaNelPeriodo()} è vero. In B1 il periodo era accettato
 * e ignorato, di proposito; qui il cancello di B2 è l'invariante 1: con nessuna `data_fine` valorizzata
 * **e nessun passaggio registrato in `subentri`** il riparto non cambia di un centesimo (un inizio di locazione
 * registrato dà alla riga una decorrenza anche senza date di fine: D7 stretto, via b).
 *
 * Decisione 10 del progetto: `attivo` resta una condizione AND dentro questo risolutore fino alla
 * 2.0, letta e mai scritta da qui: nessun punto che **decide** chi è titolare o chi paga la legge più
 * direttamente. La leggono ancora, e devono: la diagnosi `VerificaTitolaritaCommand` (guarda anche le
 * righe spente, A4), la presentazione (`ImmobileAnagraficaResource:48`, `AnagraficaController:202`) e
 * i sei punti dell'inventario §4.3 che di proposito non filtrano, ciascuno con il suo commento.
 */
class RisolutoreTitolari
{
    /**
     * Filtra una collection di anagrafiche caricate con la pivot (`$immobile->anagrafiche`).
     *
     * La collection deve portare `->pivot` (relazione `belongsToMany` di `Immobile` o `Anagrafica`);
     * per le righe grezze di `DB::table('anagrafica_immobile')->get()` si vincola la query prima, con
     * {@see vincolaQuery()}, non la collection dopo.
     */
    public function attiviAlla(Collection $anagrafiche, PeriodoCompetenza|InsiemePeriodi|null $periodo = null): Collection
    {
        $attive = $anagrafiche->filter(fn ($a) => (bool) ($a->pivot?->attivo ?? false));

        if ($periodo === null) {
            return $attive;
        }

        $insieme = $periodo instanceof InsiemePeriodi ? $periodo : InsiemePeriodi::uno($periodo);
        $dal = $insieme->dal();
        $al = $insieme->al();
        // Le righe della stessa coppia si cercano fra le **attive** della stessa collection: la collection
        // è quella di un'unità, e la tipologia sta nella pivot.
        $pivotAttive = $attive->map(fn ($a) => $a->pivot)->values();

        return $attive->filter(function ($a) use ($dal, $al, $pivotAttive) {
            $riga = $a->pivot;
            $fine = $this->giorno($riga->data_fine ?? null);
            if ($fine !== null && $fine->lt($dal)) {
                return false;
            }
            $inizio = $this->giorno($riga->data_inizio ?? null);
            if ($inizio !== null && $inizio->gt($al) && $this->haPredecessoreChiuso($riga, $pivotAttive)) {
                return false;
            }

            return true;
        });
    }

    /**
     * Aggiunge alla query il vincolo di titolarità. `$tabella` è l'alias con cui `anagrafica_immobile`
     * compare nella query (`'ai'` in un join), vuoto quando la query è sulla tabella nuda.
     *
     * @template T of QueryBuilder|EloquentBuilder
     * @param T $query
     * @return T
     */
    public function vincolaQuery(QueryBuilder|EloquentBuilder $query, PeriodoCompetenza|InsiemePeriodi|null $periodo = null, string $tabella = ''): QueryBuilder|EloquentBuilder
    {
        $query->where($this->colonna('attivo', $tabella), true);

        if ($periodo === null) {
            return $query;
        }

        $insieme = $periodo instanceof InsiemePeriodi ? $periodo : InsiemePeriodi::uno($periodo);
        $dal = $insieme->dal()->toDateString();
        $al = $insieme->al()->toDateString();
        // Il nome con cui la riga «esterna» si cita dentro la sottoquery del predecessore.
        $esterna = $tabella === '' ? 'anagrafica_immobile' : $tabella;

        return $query
            // D7: `data_fine` filtra sempre.
            ->where(fn ($q) => $q->whereNull($this->colonna('data_fine', $tabella))
                ->orWhereRaw($this->dataSql($this->colonna('data_fine', $tabella)) . ' >= ?', [$dal]))
            // D7 stretto (decisione 23): `data_inizio` filtra solo con un predecessore — chiuso il giorno prima sulla
            // stessa coppia, oppure la riga è entrata con un passaggio registrato — come `haPredecessoreChiuso()`.
            ->where(fn ($q) => $q->whereNull($this->colonna('data_inizio', $tabella))
                ->orWhereRaw($this->dataSql($this->colonna('data_inizio', $tabella)) . ' <= ?', [$al])
                ->orWhere(fn ($senza) => $senza
                    ->whereNotExists(fn ($sub) => $sub->from('anagrafica_immobile as predecessore')
                        ->whereColumn('predecessore.immobile_id', "{$esterna}.immobile_id")
                        ->whereColumn('predecessore.tipologia', "{$esterna}.tipologia")
                        ->whereColumn('predecessore.id', '!=', "{$esterna}.id")
                        ->where('predecessore.attivo', true)
                        ->whereNotNull('predecessore.data_fine')
                        ->whereRaw("{$this->dataSql('predecessore.data_fine')} = {$this->giornoPrimaSql($this->dataSql("{$esterna}.data_inizio"))}"))
                    ->whereNotExists(fn ($sub) => $sub->from('subentri')
                        ->where(fn ($w) => $w->whereColumn('subentri.riga_entrante_id', "{$esterna}.id")
                            // La tripla solo per l'usufrutto, come `entrataConPassaggio()` (B1-2).
                            ->orWhere(fn ($t) => $t->where('subentri.tipo_passaggio', 'usufrutto')
                                ->whereColumn('subentri.immobile_id', "{$esterna}.immobile_id")
                                ->whereColumn('subentri.tipologia', "{$esterna}.tipologia")
                                ->whereRaw("{$this->dataSql('subentri.decorrenza')} = {$this->dataSql("{$esterna}.data_inizio")}"))))));
    }

    /**
     * La colonna ridotta alla sola data, dove il database può portare anche l'ora in una colonna `date`: su sqlite
     * (nei test e come driver supportato) una data scritta da un Carbon arriva come «2026-04-30 00:00:00» e il
     * confronto con «2026-04-30» fallisce — la collection tronca con `giorno()`, la SQL deve rispondere identico
     * (invariante 4; verifica strada b, B1-1). Su MySQL e PostgreSQL le colonne `date` non portano l'ora: si lascia
     * la colonna nuda, così gli indici restano usabili.
     */
    private function dataSql(string $colonna): string
    {
        return DB::connection()->getDriverName() === 'sqlite' ? "date({$colonna})" : $colonna;
    }

    /** `data_inizio − 1 giorno` nel dialetto del database in uso (la forma SQL deve rispondere come la collection). */
    private function giornoPrimaSql(string $colonna): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "date({$colonna}, '-1 day')",
            'pgsql'  => "({$colonna}::date - interval '1 day')",
            default  => "DATE_SUB({$colonna}, INTERVAL 1 DAY)",
        };
    }

    /**
     * Rende deterministica una lettura che oggi si affida a «la prima riga che capita».
     *
     * `PianoRateController` e `IncassoRateService` facevano, fino alla beta.29, `->value('tipologia')`
     * sulla coppia (anagrafica, immobile) senza filtrare su `attivo` e senza `orderBy`; oggi passano
     * da qui, come `SituazioneDebitoriaController` e `VerificaSaldiSolidaliCommand`. Non era un
     * difetto, perché la guardia 1 di `ValidatesImmobileAnagraficaPivot` garantisce una riga per
     * coppia **dall'interfaccia** (il trait è agganciato alle sole FormRequest); l'importatore può
     * scriverne due con ruoli diversi, e lì l'ordine di prima era indefinito. Lo diventa nel momento
     * esatto in cui B2 toglie la guardia per registrare due periodi della stessa persona. L'ordine è
     * quello dell'invariante 6-bis del progetto: prima la riga attiva, poi la più recente
     * (`data_inizio` desc), poi l'`id` come spareggio stabile.
     *
     * Solo su letture puntuali (`->value()`, `->first()`). Con `distinct()` o `groupBy()` MySQL 8
     * rifiuta l'`ORDER BY` su colonne non selezionate (3065/1055) e SQLite — il motore della suite —
     * non lo rileva: per gli insiemi si usa `vincolaQuery()` e basta.
     *
     * @template T of QueryBuilder|EloquentBuilder
     * @param T $query
     * @return T
     */
    public function ordinePreferenza(QueryBuilder|EloquentBuilder $query, string $tabella = ''): QueryBuilder|EloquentBuilder
    {
        return $query
            ->orderByDesc($this->colonna('attivo', $tabella))
            ->orderByDesc($this->colonna('data_inizio', $tabella))
            ->orderBy($this->colonna('id', $tabella));
    }

    // -----------------------------------------------------------------------------------------------
    // B2 — D7 e D8 come funzioni pure (S1); dalla S4 le usano `attiviAlla()` qui sopra e il motore
    // (`CalcoloQuoteService::distribuisciSuTabelle()` e `addebitaDiretto()`).
    // -----------------------------------------------------------------------------------------------

    /**
     * I giorni in cui una riga di titolarità è in vigore dentro un periodo (o un insieme di tratti),
     * secondo la regola D7 del progetto `docs/subentro_e_competenza_temporale.md`:
     *
     * - `data_fine` filtra **sempre**: dopo quel giorno la riga non c'è più;
     * - `data_inizio` filtra **solo se** sulla stessa coppia (immobile, tipologia) esiste una riga chiusa
     *   che la precede. Senza predecessore chiuso la riga è «aperta da sempre»: sui dati reali quella
     *   data è il giorno del **censimento**, non della decorrenza del diritto (§6.1 del progetto), e
     *   trattarla come decorrenza svuoterebbe mezzo palazzo.
     *
     * È il numeratore del pro rata (peso = quota × giorni): con un insieme a più tratti i giorni si
     * sommano tratto per tratto. Le righe possono essere pivot Eloquent (`TitolaritaImmobile`) o
     * `stdClass` da `DB::table`: si leggono `data_inizio`, `data_fine`, `tipologia`, `id`.
     *
     * @param Collection<int, object> $righeStessaCoppia tutte le righe della stessa (immobile, tipologia), compresa `$riga`
     */
    public function giorniDiTitolarita(object $riga, PeriodoCompetenza|InsiemePeriodi $periodi, Collection $righeStessaCoppia): int
    {
        $insieme = $periodi instanceof InsiemePeriodi ? $periodi : InsiemePeriodi::uno($periodi);
        $effettivo = $this->periodoEffettivo($riga, $righeStessaCoppia, $insieme);

        return $effettivo === null ? 0 : $insieme->giorniDiSovrapposizione($effettivo);
    }

    /**
     * La domanda dell'uscita anticipata di D8: in questo periodo, su questa coppia (immobile,
     * tipologia), qualcuno entra o esce? Se no, il motore esegue **letteralmente** `quota / somma_quote`
     * — la garanzia d'identità in virgola mobile — e non tocca i pesi. Guarda solo le righe attive:
     * una riga spenta non partecipa comunque (decisione 10).
     *
     * @param Collection<int, object> $righeStessaCoppia
     */
    public function cambiaTitolaritaNelPeriodo(Collection $righeStessaCoppia, PeriodoCompetenza|InsiemePeriodi $periodi): bool
    {
        $insieme = $periodi instanceof InsiemePeriodi ? $periodi : InsiemePeriodi::uno($periodi);
        $dal = $insieme->dal();
        $al = $insieme->al();

        foreach ($righeStessaCoppia as $r) {
            if (! (bool) ($r->attivo ?? false)) {
                continue;
            }
            $fine = $this->giorno($r->data_fine ?? null);
            // Chiude dentro il periodo, prima dell'ultimo giorno: dopo di lei qualcun altro (o nessuno).
            if ($fine !== null && $fine->gte($dal) && $fine->lt($al)) {
                return true;
            }
            // Decorre dentro il periodo, dopo il primo giorno — e la decorrenza conta solo con un predecessore chiuso.
            $inizio = $this->giorno($r->data_inizio ?? null);
            if ($inizio !== null && $inizio->gt($dal) && $inizio->lte($al) && $this->haPredecessoreChiuso($r, $righeStessaCoppia)) {
                return true;
            }
        }

        return false;
    }

    /**
     * I giorni del periodo in cui **nessuna** riga attiva della coppia (quota > 0) è in vigore — il vuoto fra
     * un titolare che esce e uno che entra, o dopo l'ultimo che esce (decisione 22, Fase 1-bis S8-8). Nullo se
     * ogni giorno è coperto da almeno una riga. Le righe valgono per il loro tratto effettivo (D7).
     *
     * @param Collection<int, object> $righeCoppia tutte le righe della stessa (immobile, tipologia)
     */
    public function giorniScoperti(Collection $righeCoppia, PeriodoCompetenza|InsiemePeriodi $periodi): ?InsiemePeriodi
    {
        $insieme = $periodi instanceof InsiemePeriodi ? $periodi : InsiemePeriodi::uno($periodi);
        $coperti = [];
        foreach ($righeCoppia as $r) {
            if (! (bool) ($r->attivo ?? false) || (float) ($r->quota ?? 0) <= 0.0) {
                continue;
            }
            $eff = $this->periodoEffettivo($r, $righeCoppia, $insieme);
            if ($eff !== null) {
                $coperti[] = [$eff->dal, $eff->al];
            }
        }
        usort($coperti, fn ($a, $b) => $a[0] <=> $b[0]);
        $fusi = [];
        foreach ($coperti as [$dal, $al]) {
            $ultimo = $fusi === [] ? null : array_key_last($fusi);
            if ($ultimo !== null && $dal->lte($fusi[$ultimo][1]->addDay())) {
                $fusi[$ultimo][1] = $al->gt($fusi[$ultimo][1]) ? $al : $fusi[$ultimo][1];
            } else {
                $fusi[] = [$dal, $al];
            }
        }

        $scoperti = [];
        foreach ($insieme->periodi() as $tratto) {
            $cursore = $tratto->dal;
            foreach ($fusi as [$dal, $al]) {
                if ($al->lt($cursore)) {
                    continue;
                }
                if ($dal->gt($tratto->al)) {
                    break;
                }
                if ($dal->gt($cursore)) {
                    $scoperti[] = new PeriodoCompetenza($cursore, $dal->subDay());
                }
                $dopo = $al->addDay();
                $cursore = $dopo->gt($cursore) ? $dopo : $cursore;
            }
            if ($cursore->lte($tratto->al)) {
                $scoperti[] = new PeriodoCompetenza($cursore, $tratto->al);
            }
        }

        return $scoperti === [] ? null : new InsiemePeriodi(...$scoperti);
    }

    /**
     * Il tratto in cui la riga vale, ritagliato sull'estensione dei periodi (D7); `null` se non lo tocca. È ciò
     * che il motore congela in `righe_riparto.titolarita_dal/al` (migrazione 11): il conguaglio del passaggio
     * divide su questo tratto, non su un conteggio di giorni assunti «in coda».
     *
     * @param Collection<int, object> $righeStessaCoppia
     */
    public function trattoEffettivo(object $riga, PeriodoCompetenza|InsiemePeriodi $periodi, Collection $righeStessaCoppia): ?PeriodoCompetenza
    {
        return $this->periodoEffettivo($riga, $righeStessaCoppia, $periodi instanceof InsiemePeriodi ? $periodi : InsiemePeriodi::uno($periodi));
    }

    /** Il tratto in cui la riga vale, ritagliato sull'estensione dei periodi; `null` se non lo tocca. */
    private function periodoEffettivo(object $riga, Collection $righeStessaCoppia, InsiemePeriodi $periodi): ?PeriodoCompetenza
    {
        $fine = $this->giorno($riga->data_fine ?? null);
        $inizio = $this->haPredecessoreChiuso($riga, $righeStessaCoppia) ? $this->giorno($riga->data_inizio ?? null) : null;

        $dal = $inizio !== null && $inizio->gt($periodi->dal()) ? $inizio : $periodi->dal();
        $al = $fine !== null && $fine->lt($periodi->al()) ? $fine : $periodi->al();

        return $al->lt($dal) ? null : new PeriodoCompetenza($dal, $al);
    }

    /**
     * La riga ha un **predecessore** — e quindi la sua `data_inizio` è una decorrenza, non un censimento?
     *
     * D7 stretto (decisione 23, Fase 1-bis S8-9). Due forme, in OR:
     *
     * - (a) sulla stessa coppia (immobile, tipologia) esiste una riga diversa chiusa **il giorno prima** della
     *   sua `data_inizio` — è ciò che «Registra passaggio» scrive (uscente a decorrenza − 1) e ciò che
     *   «Associa/Modifica» a mano possono scrivere senza sovrapporsi (l'invariante 11 rifiuta un giorno in comune
     *   a 200);
     * - (b) la riga è entrata con un passaggio registrato — `subentri.riga_entrante_id`, oppure, per il solo
     *   **usufrutto**, stessa unità, stessa tipologia e `data_inizio` = `decorrenza` del record (i più nudi che
     *   tornano pieni insieme con un record solo): copre il nuovo inquilino dopo un vuoto di mesi, che per (a) non
     *   avrebbe un predecessore contiguo.
     *
     * Prima bastava una riga chiusa **prima o il giorno stesso** — qualunque, anche di una persona estranea al
     * passaggio: un comproprietario censito a giugno accanto a una vendita di febbraio fra altri due perdeva 151
     * giorni che non aveva mai ceduto (S8-9). Il giorno stesso non conta più: con l'invariante 11 una chiusura
     * il giorno della decorrenza è una comproprietà di un giorno, non un passaggio.
     */
    private function haPredecessoreChiuso(object $riga, Collection $righeStessaCoppia): bool
    {
        $inizio = $this->giorno($riga->data_inizio ?? null);
        if ($inizio === null) {
            return false;
        }
        if ($this->entrataConPassaggio($riga)) {
            return true;
        }

        $giornoPrima = $inizio->subDay();
        foreach ($righeStessaCoppia as $r) {
            if ($r === $riga || (isset($r->id, $riga->id) && (int) $r->id === (int) $riga->id)) {
                continue;
            }
            if (($r->tipologia ?? null) !== ($riga->tipologia ?? null)) {
                continue;
            }
            $fine = $this->giorno($r->data_fine ?? null);
            if ($fine !== null && $fine->equalTo($giornoPrima)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Le righe entrate con un passaggio registrato, caricate una volta per istanza (niente N+1 per unità): gli id
     * di `riga_entrante_id` e le triple `immobile|tipologia|decorrenza`. La tripla serve perché `riga_entrante_id`
     * è una colonna sola: nell'estinzione dell'usufrutto con due nudi tornano pieni entrambi lo stesso giorno, ma
     * solo il primo è «l'entrante» del record — il secondo per D7 sarebbe «aperto da sempre» e pagherebbe 365 giorni
     * (verifica S8-bis, L2-2). Stessa unità, stessa tipologia, `data_inizio` = `decorrenza`: è entrato con quello.
     */
    private ?array $righeEntrateConPassaggio = null;
    private ?array $tripleDiPassaggio = null;

    private function entrataConPassaggio(object $riga): bool
    {
        if ($this->righeEntrateConPassaggio === null) {
            $subentri = DB::table('subentri')->get(['riga_entrante_id', 'immobile_id', 'tipologia', 'decorrenza', 'tipo_passaggio']);
            $this->righeEntrateConPassaggio = $subentri->pluck('riga_entrante_id')->filter()->map(fn ($id) => (int) $id)->flip()->all();
            // La tripla solo per l'usufrutto, l'unico passaggio che apre più righe con un record solo (l'estinzione con più
            // nudi): per vendita e locazione `riga_entrante_id` è esatto, e una riga censita a mano con la stessa data di
            // una vendita fra altri due non deve diventare «entrata con quel passaggio» (verifica strada b, B1-2).
            $this->tripleDiPassaggio = $subentri->where('tipo_passaggio', 'usufrutto')->map(fn ($s) => (int) $s->immobile_id . '|' . $s->tipologia . '|' . substr((string) $s->decorrenza, 0, 10))->flip()->all();
        }
        if (isset($riga->id) && isset($this->righeEntrateConPassaggio[(int) $riga->id])) {
            return true;
        }
        $inizio = $this->giorno($riga->data_inizio ?? null);
        if ($inizio === null || ! isset($riga->immobile_id)) {
            return false;
        }

        return isset($this->tripleDiPassaggio[(int) $riga->immobile_id . '|' . ($riga->tipologia ?? '') . '|' . $inizio->toDateString()]);
    }

    /** Una data di calendario dalla pivot (stringa `Y-m-d`, Carbon o nullo), normalizzata come `PeriodoCompetenza`. */
    private function giorno(mixed $valore): ?CarbonImmutable
    {
        if ($valore === null || $valore === '') {
            return null;
        }
        $data = $valore instanceof \DateTimeInterface ? $valore->format('Y-m-d') : substr((string) $valore, 0, 10);

        return CarbonImmutable::parse($data, 'UTC')->startOfDay();
    }

    private function colonna(string $nome, string $tabella): string
    {
        return $tabella === '' ? $nome : "{$tabella}.{$nome}";
    }
}
