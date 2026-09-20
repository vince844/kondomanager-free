<?php

namespace App\Services;

use App\Enums\RuoloAnagraficaImmobile;
use App\Helpers\MoneyHelper;
use App\Models\Gestione;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContributoVersato;
use App\Models\Gestionale\PianoRate;
use App\Enums\NaturaGestione;
use App\Exceptions\Gestionale\RichiedeDeliberaException;
use App\Models\Gestionale\CompetenzaCapitolo;
use App\Services\Riparto\EsitoCompetenza;
use App\Services\Riparto\GradinoCompetenza;
use App\Services\Riparto\RisolutoreCompetenza;
use App\Services\Riparto\RisolutoreTitolari;
use App\Support\InsiemePeriodi;
use App\Support\PeriodoCompetenza;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Servizio per il calcolo delle quote di spesa/entrata per ogni gestione.
 *
 * VERSION: 1.9.6 (CASCADE RESOLUTION + SCOPERTO BUCKET)
 *
 * =========================================================================
 * ARCHITETTURA DEL RULE ENGINE (Motore di Ripartizione)
 * =========================================================================
 * Livello 1: OVERRIDE STRUTTURALE (immobile_id NOT NULL su riga_fattura).
 * Livello 2: REGOLA NATURA SPESA (conto_id NOT NULL su riga_fattura).
 * Livello 3: FALLBACK LEGALE (Soggetto Pagatore).
 * Livello 4: PENNY-PERFECT ALGORITHM.
 */
class CalcoloQuoteService
{
    /** Le due nature della riga `netting` nel dettaglio (decisione 17), lette dal conguaglio del passaggio. */
    public const NETTING_DELLA_PERSONA = 'già versato della persona';
    public const NETTING_DELL_UNITA = 'già versato dell\'unità';

    /**
     * Quanto può mancare alla somma dei coefficienti prima che sia un difetto e non un
     * arrotondamento — espressa in frazione, cioè 0,0005 = 0,05 punti percentuali.
     *
     * ⚠️ **Non è zero, e la ragione è nella colonna.** `conto_tabella_millesimale.coefficiente`
     * è `decimal(5,2)`: tre platee in parti uguali sommano 99,99 e non si può scrivere meglio.
     * Con due decimali, N platee in parti uguali perdono al massimo N × 0,005 punti — quindi
     * questa soglia copre fino a **dieci** tabelle sullo stesso capitolo senza gridare.
     *
     * Vedi la nota estesa accanto alla guardia, in `distribuisciSuTabelle()`.
     */
    /**
     * Quanti **punti percentuali** possono mancare all'appello senza che sia un difetto.
     *
     * Espressa in punti e non in frazione di proposito: è l'unità in cui è scritta la colonna
     * `conto_tabella_millesimale.coefficiente` (`decimal(5,2)`), e il confronto fatto lì è esatto.
     * Nella prima stesura la soglia era `0.0005` in frazione e il confronto avveniva su una somma
     * di divisioni per 100: a **esattamente 99,95** — cioè il limite che questa costante dichiara
     * accettabile — l'esito dipendeva dal rumore in virgola mobile invece che da questa riga.
     */
    private const TOLLERANZA_COEFFICIENTI_PUNTI = 0.05;

    private ?Gestione $gestioneCorrente = null;
    private array $pivotOverrides = [];
    private ?\Carbon\Carbon $pianoRateCreatedAt = null;

    /**
     * Il periodo di competenza del calcolo in corso: la base passata dal chiamante (`competenzaBase`),
     * raffinata per capitolo (`impostaCompetenzaPerConto`) o per fattura straordinaria. Da B2 (1.11.0-beta.31,
     * S4) governa `RisolutoreTitolari::attiviAlla()` (D7) e `pesiPerGiorni()` (D8); con `null` il calcolo
     * è atemporale e identico alla beta.30 (invariante 1). Progetto `subentro_e_competenza_temporale.md`, §4.
     */
    private PeriodoCompetenza|InsiemePeriodi|null $periodo = null;

    /**
     * B2 (1.11.0-beta.31, S4): **la competenza di base del calcolo**, o `null` per il calcolo atemporale
     * della beta.30. È ciò che il chiamante passa: per l'ordinario la cascata gestione → esercizio
     * (`RisolutoreCompetenza::perOrdinaria(null, …)`), per lo straordinario la data della delibera
     * (`perStraordinaria(null, null, $dataDelibera)`). Il motore la **raffina** da sé: per capitolo con i
     * tratti di `competenze_capitolo` (decisione 20), per fattura straordinaria con la competenza
     * dichiarata (decisione 11/12). `$periodo` qui sopra è il risultato di quel raffinamento, e cambia
     * conto per conto.
     */
    private ?EsitoCompetenza $competenzaBase = null;

    /** Il gradino della cascata che ha deciso `$periodo` per il conto in corso (`GradinoCompetenza`), o `null`. */
    private ?string $gradinoCorrente = null;

    /** `conto_id => id della riga di piano_rate_capitoli`, per leggere i tratti del capitolo (decisione 20). */
    private array $pivotCapitoloIds = [];

    /** Nello straordinario la competenza è per fattura e non per capitolo: il raffinamento per conto si spegne. */
    private bool $competenzaPerConto = true;

    /**
     * Dove la risoluzione **temporale** ha cambiato qualcosa rispetto a quella atemporale: un titolare
     * escluso dal periodo o un pro rata per giorni. È la base del cancello (2) della decisione 14 e di
     * `titolarita_alla` nelle quote (decisione 15).
     *
     * @var list<array<string,mixed>>
     */
    private array $destinatariCambiati = [];
    /** Sola lettura: una fattura straordinaria senza competenza né delibera è stata ripartita atemporale, e va detto. */
    private bool $competenzaNonRisolta = false;
    /** Straordinario: almeno una fattura ha avuto una competenza risolta (dichiarata o delibera). */
    private bool $competenzaRisoltaPerFattura = false;
    /**
     * Straordinario: il totale del piano per conto, sommato su tutte le competenze. Il netting del già
     * versato si applica in proporzione a «questa chiamata / nominale»: con due chiamate sullo stesso conto
     * (due fatture con competenze diverse) e `conti.importo` a zero o più basso della somma, senza questo
     * minimo la copertura si scontava per intero a ogni chiamata (verifica indipendente S4, critico).
     *
     * @var array<int, int> conto_id => centesimi
     */
    private array $nominaleMinimoPerConto = [];

    /** Chi risolve «chi è titolare di questa unità», in un posto solo. */
    private readonly RisolutoreTitolari $titolari;
    
    /** @var array Accumulatore per le quote non assegnabili per mancanza di anagrafiche attive */
    private array $scopertiAccumulati = [];

    /**
     * Le righe di tabella millesimale che esistono ma **non hanno ancora un valore**.
     *
     * ⚠️ Non sono scoperti, e tenerle separate è deliberato. Uno scoperto è denaro che non si
     * riesce ad attribuire, e porta con sé un importo; qui l'importo è **esattamente il numero
     * che manca**, quindi non è calcolabile senza inventarlo. Registrarle nel secchio degli
     * scoperti avrebbe voluto dire scrivere un euro finto per far funzionare il cancello.
     *
     * ## Perché ci sono, da questa beta
     *
     * Fino alla .60 `quote.*.valore` era `required` e questo stato non esisteva a database — zero
     * righe NULL su 98, misurate. Il `required` però non proteggeva: chi spuntava «associa tutti
     * gli immobili esistenti» otteneva una tabella non salvabile finché non l'aveva compilata
     * tutta, e la via d'uscita rapida era scrivere `0`. Il motore legge lo zero come «non
     * partecipa» — e quel condòmino sparisce dal piano, mentre gli altri pagano la sua quota.
     * Misurato: dieci unità, nove compilate e una dimenticata, ciascuno dei nove paga
     * **€ 1.111,11 invece di € 1.000,00**, col centesimo di resto su uno solo, e nessun controllo contabile ha niente da segnalare
     * perché il totale del piano resta identico al preventivo.
     *
     * ## Cosa NON fa
     *
     * Non tocca un solo peso e non cambia un solo importo: se l'amministratore accetta e procede,
     * l'aritmetica è quella di sempre. Serve a **dirlo prima**, e a metterlo agli atti nella nota
     * degli scoperti. Il rimedio vero è compilare il millesimo.
     *
     * ⚠️ Guarda **solo il NULL, mai lo zero**. Lo zero significa «non partecipa» ed è legittimo:
     * è così che sono fatte le tabelle parziali vere — ascensore senza i piani terra, scale senza
     * i negozi con ingresso su strada. Avvisare anche lì significherebbe urlare su nove tabelle
     * su sedici che sono corrette, e in due settimane nessuno leggerebbe più l'avviso.
     *
     * @var list<array{immobile_id:int, tabella_id:int, conto_id:int}>
     */
    private array $millesimiNonCompilati = [];

    /** Unità che hanno versato più di quanto la spesa richiedeva loro. */
    private array $eccedenzeCopertura = [];

    /**
     * Quanto netting del già-versato è stato **davvero applicato**, per capitolo e per unità:
     * `conto_id => [immobile_id => centesimi]`, sempre positivo.
     *
     * **Applicato non è registrato**, e la differenza non è teorica: la copertura è limitata dal
     * lordo dell'unità (`min($copertura, $lordoImmobile)`), quindi un'unità che ha versato più
     * della sua parte compare qui con l'importo *assorbito*, non con quello versato — l'eccedenza
     * vive in `$eccedenzeCopertura`. Ed è scalata dalla quota proporzionale fra chiamate
     * indipendenti e dal fattore di copertura degli scoperti: chi volesse ricostruire questo
     * numero da `contributi_versati` dovrebbe riscrivere tre aggiustamenti, che è il modo in cui
     * due copie della stessa aritmetica cominciano a divergere.
     *
     * **Esisteva come promessa prima che come codice.** Il docblock di `getImportiPerConto()`
     * rimandava a `getNettingApplicato()` dalla beta.49 per spiegare perché la colonna di una
     * tabella resta al deliberato — e quell'accessore non era mai stato scritto. Costruito nella
     * beta.76, chiudendo la Coda 76.
     */
    private array $nettingApplicato = [];

    /**
     * Registro per conto (beta.49, coda ⑩): quanto è stato **davvero** portato a riparto, per
     * capitolo. `conto_id => centesimi con segno`, già al netto della decurtazione scoperti e al
     * lordo del netting.
     *
     * È nato perché `RipartoTabelleService` — il servizio che costruisce il PDF del riparto — se
     * lo ricalcolava da solo, e sbagliava: sullo straordinario leggeva `righe_fattura` con venti
     * righe che ignoravano `importo_collegato`, le coperture delle pregresse e la distinzione fra
     * righe ordinarie e sopravvenienze. Fino alla 1.11.0-beta.28 lo leggeva quella stampa; dalla
     * .29 le stampe leggono `righe_riparto`, e questo registro resta la somma di controllo delle
     * righe `riparto` per conto (`RigheRipartoInvariantiTest`).
     */
    private array $importiRipartiti = [];

    /**
     * Gli addebiti ad personam: spese di una sola unità, che **non appartengono a nessuna tabella
     * millesimale** e quindi non possono stare in una colonna del riparto.
     */
    private array $addebitiDiretti = [];

    /**
     * Il dettaglio del riparto (1.11.0-beta.29): **una riga per componente**, costruita nello stesso
     * punto in cui tabella, valore del millesimo, ruolo e quota sono ancora in scope — a
     * `distribuisciSuTabelle()` il motore li fonde in un peso solo e da lì in poi non esistono
     * più. Le righe nascono **accanto** ai totali e ne sono la spiegazione: per ogni
     * (anagrafica, immobile) la somma delle righe con soggetto è esattamente il totale del motore.
     *
     * Tipi: `riparto` (tabella × ruolo richiesto, importo con il segno del conto), `netting` (già
     * versato, negativo, per conto e soggetto), `ad_personam` (con la riga di fattura),
     * `quota_zero` (unità a 0 o NULL in una tabella: nessun soggetto, nessun importo).
     *
     * `GenerateRateQuotesAction` le scrive in `righe_riparto` insieme alle quote; le stampe le
     * leggono invece di ricalcolare. Vuoto finché non si è chiamato uno dei due motori.
     *
     * @var list<array<string,mixed>>
     */
    private array $righeDettaglio = [];

    /** Zeri documentati già registrati, per «tabella|immobile»: una riga sola, non una per conto. */
    private array $zeriRegistrati = [];

    /** Il netting per chiave «aid|iid» dell'ultima chiamata a `nettingGiaVersato()`. */
    private array $ultimoNettingPerChiave = [];

    /**
     * La parte «della persona» dell'ultimo netting, per chiave (decisione 17): si congela come riga a sé nel
     * dettaglio, così il conguaglio del passaggio la rilegge dal registro e non dalla tabella dei contributi di
     * oggi (verifica S8-bis, L1-5).
     */
    private array $ultimoNettingPersonaPerChiave = [];

    /** @var array<int,Conto> radice per conto foglia, entro una chiamata */
    private array $radiciCache = [];

    // =========================================================================
    // MOTORE ORDINARIO
    // =========================================================================

    public function __construct(?RisolutoreTitolari $titolari = null)
    {
        // Default esplicito e non iniezione obbligatoria: il motore è costruito a mano in una
        // trentina di punti di quattro file di test (`new CalcoloQuoteService()`) e non ha
        // dipendenze con stato.
        $this->titolari = $titolari ?? new RisolutoreTitolari();
    }

    /**
     * Calcola le quote per un'intera gestione (preventivo o rateizzazione).
     *
     * @param Gestione $gestione La gestione di riferimento
     * @param PianoRate|null $pianoRate Opzionale piano rate per determinare il momento di validità degli overrides
     * @return array Quote calcolate, raggruppate per anagrafica_id e immobile_id
     */
    /**
     * @param bool $soloLettura Invocazione dalla **stampa**, non dalla generazione: salta la
     *                          guardia di sovra-finanziamento. Un riparto già generato deve poter
     *                          essere ristampato anche se nel frattempo i dati sono cambiati — la
     *                          guardia serve a impedire di *generare* male, non a impedire di
     *                          rileggere. Senza questo, un documento d'assemblea diventerebbe un
     *                          foglio bianco per una modifica avvenuta dopo l'emissione.
     */
    public function calcolaPerGestione(Gestione $gestione, ?PianoRate $pianoRate = null, bool $soloLettura = false, PeriodoCompetenza|EsitoCompetenza|null $periodo = null): array
    {
        $this->gestioneCorrente = $gestione;
        // Decisione 12 anche dal ramo dei capitoli: una gestione **straordinaria** ripartita senza la data
        // della delibera non scende al periodo dell'esercizio, si ferma. Alla generazione; la stampa in
        // sola lettura non riceve mai un esito non risolto (`DettaglioRiparto` lo azzera prima).
        $periodoNonRisolto = false;
        if ($periodo instanceof EsitoCompetenza && $periodo->richiedeDelibera) {
            if ($pianoRate !== null && ! $soloLettura) {
                throw new RichiedeDeliberaException($pianoRate);
            }
            $periodo = null;
            $periodoNonRisolto = true;
        }
        $natura = NaturaGestione::daStringa($gestione->tipo);
        $this->impostaCompetenzaBase($periodo, $natura);
        $this->competenzaNonRisolta = $periodoNonRisolto ?? false;
        $this->competenzaRisoltaPerFattura = false;
        $this->nominaleMinimoPerConto = [];
        // I tratti di `competenze_capitolo` sono un gradino della sola cascata ORDINARIA (D3, decisioni 12 e 20:
        // «lo straordinario resta un giorno»). Su una gestione straordinaria la competenza è la delibera — o
        // quella dichiarata sulla fattura — e chi era titolare quel giorno risponde dell'intera spesa: un tratto
        // scritto sulla pivot (a mano, da un import, da una UI che non lo ha fermato) non deve farla scivolare
        // nel pro rata per giorni. Verifica S6, R1: prima di S6 nessuna riga scriveva i tratti e il ramo era
        // irraggiungibile; la Request rifiuta i tratti sullo straordinario, e qui il motore non li legge comunque.
        $this->competenzaPerConto = $natura !== NaturaGestione::Straordinaria;
        $this->pivotCapitoloIds = [];
        $this->destinatariCambiati = [];
        $this->pivotOverrides   = [];
        $this->pianoRateCreatedAt = $pianoRate?->created_at;
        $this->scopertiAccumulati = [];
        $this->millesimiNonCompilati = [];
        $this->eccedenzeCopertura = [];
        $this->importiRipartiti = [];
        $this->nettingApplicato = [];
        $this->addebitiDiretti  = [];
        $this->righeDettaglio   = [];
        $this->zeriRegistrati   = [];
        $this->radiciCache      = [];
        $totali = [];
        $pianoConto = $gestione->pianoConto;

        if (!$pianoConto) {
            Log::error("calcolaPerGestione: gestione ID={$gestione->id} non ha un piano dei conti associato.");
            return [];
        }

        $capitoliIds = [];
        if ($pianoRate) {
            $pianoRate->load('capitoli');
            foreach ($pianoRate->capitoli as $capitolo) {
                $capitoliIds[] = $capitolo->id;
                if (!is_null($capitolo->pivot->importo)) {
                    $this->pivotOverrides[$capitolo->id] = (int) $capitolo->pivot->importo;
                }
                // La riga di `piano_rate_capitoli` è la chiave dei tratti di competenza (decisione 20).
                if (isset($capitolo->pivot->id)) {
                    $this->pivotCapitoloIds[$capitolo->id] = (int) $capitolo->pivot->id;
                }
            }

            if (! $soloLettura) {
                $this->guardiaSovraFinanziamentoGiaVersato($pianoRate);
            }

            // [DIAG] Log capitoli senza override (importo pivot NULL)
            $senzaOverride = $pianoRate->capitoli->filter(fn($c) => is_null($c->pivot->importo));
            if ($senzaOverride->isNotEmpty()) {
                Log::debug("calcolaPerGestione: capitoli senza override (pivot.importo NULL)", [
                    'piano_rate_id' => $pianoRate->id,
                    'capitoli_senza_override' => $senzaOverride->pluck('nome', 'id')->toArray(),
                ]);
            }
        }

        $query = $pianoConto->conti()
            ->with([
                'tabelleMillesimali.tabella.quote.immobile.anagrafiche',
                'tabelleMillesimali.ripartizioni',
                'sottoconti.tabelleMillesimali.tabella.quote.immobile.anagrafiche',
                'sottoconti.tabelleMillesimali.ripartizioni',
                'sottoconti.sottoconti',
            ]);

        $contiImpegnatiIds = [];
        if (!empty($capitoliIds)) {
            $query->whereIn('id', $capitoliIds);
        } else {
            $query->whereNull('parent_id');
            if ($pianoRate) {
                $contiImpegnatiIds = \Illuminate\Support\Facades\DB::table('piano_rate_capitoli')
                    ->join('piani_rate', 'piano_rate_capitoli.piano_rate_id', '=', 'piani_rate.id')
                    ->where('piani_rate.gestione_id', $gestione->id)
                    ->where('piani_rate.attivo', true)
                    ->where('piani_rate.id', '!=', $pianoRate->id)
                    ->pluck('conto_id')
                    ->toArray();
            }
        }

        $conti = $query->get();

        // [DIAG] Log se non vengono trovati conti
        if ($conti->isEmpty()) {
            Log::warning("calcolaPerGestione: nessun conto trovato per il piano dei conti ID={$pianoConto->id}", [
                'piano_rate_id' => $pianoRate?->id,
                'capitoli_cercati' => $capitoliIds,
            ]);
            return [];
        }

        Log::info("=== INIZIO CALCOLO QUOTE ORDINARIO V1.9.6 ===", [
            'piano_rate_id' => $pianoRate?->id,
            'overrides'     => count($this->pivotOverrides),
            'conti_caricati' => $conti->count(),
        ]);

        $processatiIds = [];
        $this->processaConti($conti, $totali, $contiImpegnatiIds, $processatiIds);

        // [DIAG] Riepilogo delta: importo pianificato vs importo effettivamente distribuito
        $totaleOverrides   = array_sum($this->pivotOverrides);
        $totaleDistribuito = 0;
        foreach ($totali as $immobili) {
            foreach ($immobili as $importo) {
                $totaleDistribuito += $importo;
            }
        }
        $delta = $totaleOverrides - $totaleDistribuito;

        Log::info("Riepilogo Ripartizione", [
            'piano_rate_id'              => $pianoRate?->id,
            'importo_override_pianificato' => $totaleOverrides,
            'importo_effettivamente_distribuito' => $totaleDistribuito,
            'delta_non_ripartito'        => $delta,
            'soggetti_trovati'           => count($totali),
        ]);

        if ($delta !== 0) {
            Log::warning("calcolaPerGestione: delta non ripartito = {$delta} centesimi. Verificare tabelle millesimali.", [
                'piano_rate_id' => $pianoRate?->id,
            ]);
        }

        return $totali;
    }

    /**
     * Guardia di sicurezza: un conto con già-versato registrato non deve mai
     * essere finanziato in modo incoerente con `conto->importo`.
     *
     * Il netting proporzionale (`nettingGiaVersato`) presuppone che
     * `conto->importo` sia il vero fabbisogno totale, e che la somma delle
     * chiamate indipendenti sullo stesso conto non lo ecceda — è il caso
     * testato e corretto di acconto+saldo, dove i due pivot sommano
     * esattamente al budget. Due modi distinti in cui questa premessa può
     * saltare, entrambi verificati con un test reale prima di scrivere questa
     * guardia:
     *
     *   1. SOMMA CHE ECCEDE — es. un piano "base" da 100.000 più uno
     *      "integrativo" da 10.000 sullo stesso conto da 100.000: ogni
     *      chiamata calcola la propria quota contro lo STESSO denominatore
     *      statico, e la stessa copertura viene accreditata due volte —
     *      entrambi i piani chiedono zero invece di sommare ai 10.000 dovuti.
     *   2. BUDGET STANTIO — un conto rimasto a 100.000 mentre una fattura REALE
     *      da 110.000 è già stata registrata (lo sforo): un piano "solo per lo
     *      sforo" (pivot 10.000, il 10% del budget VECCHIO) fa calcolare al
     *      netting una quota del 10% sulla copertura, azzerando quei 10.000
     *      invece di chiederli — il primo caso cattura il pivot troppo
     *      GRANDE, questo cattura il budget troppo PICCOLO rispetto al vero
     *      speso, indipendentemente da quanto vale il pivot in sé.
     *
     * Si blocca PRIMA di generare, con un messaggio che dice cosa correggere,
     * sullo stesso principio di `ScopertiNonAccettatiException`: meglio un
     * errore esplicito che un buco silenzioso nello Stato Patrimoniale.
     *
     * @throws \RuntimeException
     */
    private function guardiaSovraFinanziamentoGiaVersato(PianoRate $pianoRate): void
    {
        foreach ($this->pivotOverrides as $contoId => $pivotImporto) {
            $haCopertura = ContributoVersato::where('target_type', Conto::class)
                ->where('target_id', $contoId)
                ->exists();

            if (!$haCopertura) {
                continue;
            }

            $conto = Conto::find($contoId);
            if (!$conto) {
                continue;
            }

            // Caso 2 — budget stantio: una fattura REALE già registrata (non
            // pregressa: stesso filtro già usato da FatturaPassivaController
            // per rilevare lo sforamento) supera conto->importo. Blocca a
            // prescindere dal pivot: qualunque frazione calcolata contro un
            // denominatore troppo piccolo è sbagliata, sia per eccesso che
            // per difetto.
            $spesoReale = (int) DB::table('righe_fattura')
                ->join('fatture_passive', 'righe_fattura.fattura_passiva_id', '=', 'fatture_passive.id')
                ->where('righe_fattura.conto_id', $contoId)
                ->where('fatture_passive.is_pregresso', false)
                ->selectRaw('COALESCE(SUM(righe_fattura.importo_imponibile + righe_fattura.importo_iva), 0) as tot')
                ->value('tot');

            $budgetConto = max(abs((int) $conto->importo), $spesoReale);

            if ($spesoReale > abs((int) $conto->importo)) {
                throw new \RuntimeException(
                    "Impossibile generare: la voce di spesa \"{$conto->nome}\" ha un già-versato registrato, "
                    ."ma risulta una fattura reale (€ ".number_format($spesoReale / 100, 2, ',', '.').") "
                    ."superiore al suo budget (€ ".number_format(abs((int) $conto->importo) / 100, 2, ',', '.')."). "
                    ."Aggiorna prima l'importo della voce al costo reale della spesa: altrimenti il già "
                    ."versato viene applicato contro un budget sbagliato, e la quota calcolata — qualunque "
                    ."sia il piano rate — non corrisponde a quanto realmente dovuto."
                );
            }

            // Caso 1 — somma che eccede: come prima di questa correzione.
            $altriPivot = (int) DB::table('piano_rate_capitoli')
                ->join('piani_rate', 'piano_rate_capitoli.piano_rate_id', '=', 'piani_rate.id')
                ->where('piano_rate_capitoli.conto_id', $contoId)
                ->where('piani_rate.attivo', true)
                ->where('piani_rate.id', '!=', $pianoRate->id)
                ->whereNotNull('piano_rate_capitoli.importo')
                ->sum('piano_rate_capitoli.importo');

            $totaleImpegnato = $altriPivot + $pivotImporto;

            if ($totaleImpegnato > $budgetConto) {
                throw new \RuntimeException(
                    "Impossibile generare: la voce di spesa \"{$conto->nome}\" ha un già-versato registrato, "
                    ."ma la somma degli importi richiesti dai piani rate attivi su questa voce (€ "
                    .number_format($totaleImpegnato / 100, 2, ',', '.').") supera il suo budget (€ "
                    .number_format($budgetConto / 100, 2, ',', '.')."). "
                    ."Aggiorna prima l'importo della voce al fabbisogno reale, oppure disattiva uno dei piani "
                    ."rate in conflitto: altrimenti il già versato verrebbe conteggiato più volte e parte "
                    ."della spesa non verrebbe mai richiesta ai condòmini."
                );
            }
        }
    }

    // =========================================================================
    // MOTORE STRAORDINARIO
    // =========================================================================

    /**
     * Calcola le quote per una rateizzazione di fatture straordinarie.
     *
     * @param PianoRate $pianoRate Il piano rate contenente le fatture straordinarie
     * @return array Quote calcolate, raggruppate per anagrafica_id e immobile_id
     */
    public function calcolaDaFattureStraordinarie(PianoRate $pianoRate, PeriodoCompetenza|EsitoCompetenza|null $periodo = null, bool $soloLettura = false): array
    {
        $this->impostaCompetenzaBase($periodo, NaturaGestione::Straordinaria);
        $this->competenzaNonRisolta = false;
        $this->competenzaRisoltaPerFattura = false;
        $this->nominaleMinimoPerConto = [];
        // Nello straordinario la competenza è **per fattura** (dichiarata, o la delibera): si decide qui
        // sotto, fattura per fattura, e `distribuisciSuTabelle()` non deve raffinarla per capitolo.
        $this->competenzaPerConto = false;
        $this->pivotCapitoloIds = [];
        $this->destinatariCambiati = [];
        $this->scopertiAccumulati = [];
        $this->millesimiNonCompilati = [];
        $this->eccedenzeCopertura = [];
        $this->importiRipartiti = [];
        $this->nettingApplicato = [];
        $this->addebitiDiretti  = [];
        $this->righeDettaglio   = [];
        $this->zeriRegistrati   = [];
        $this->radiciCache      = [];

        // Carichiamo le fatture col pivot: importo_collegato è la quota della
        // fattura effettivamente finanziata da QUESTO piano (residuo/split).
        $fatture = $pianoRate->fattureStraordinarie()->get();

        if ($fatture->isEmpty()) {
            throw new \RuntimeException(
                "Piano straordinario ID {$pianoRate->id} non ha fatture collegate. Impossibile calcolare le quote."
            );
        }

        $totali             = [];
        $righeElaborate     = 0;
        $copertureElaborate = 0;

        // Accumulatore per conto: più fatture (o più componenti della stessa
        // fattura) possono puntare allo STESSO conto imprevisto — due sopravvenienze
        // pregresse sul medesimo capitolo, ad esempio. Il netting del già-versato
        // (dentro distribuisciSuTabelle) va applicato UNA sola volta per conto per
        // chiamata: chiamarlo una volta per componente lo sottrarrebbe più volte
        // nella stessa esecuzione, anche a monte di qualunque split fra piani.
        $importiPerConto = [];
        /** @var array<int, EsitoCompetenza> la competenza con cui ogni conto va ripartito; deve essere una sola per conto */
        $competenzePerConto = [];

        foreach ($fatture as $fattura) {
            // Decisioni 11 e 12: competenza dichiarata sulla fattura → data della delibera → **stop**.
            // Senza competenza e senza delibera il motore non scende al periodo della gestione: si ferma
            // e chiede la data, che è un fatto dell'assemblea e non del programma. In sola lettura
            // (l'anteprima di `DettaglioRiparto`) non si ferma: quella fattura va atemporale e lo dice
            // con `competenza_non_risolta`, mentre le fatture con la competenza dichiarata restano
            // temporali come alla generazione (verifica S4: prima l'anteprima azzerava tutto). Vale per
            // la natura **straordinaria** (che decide la gestione, decisione 11): un piano da fatture su
            // una gestione ordinaria resta ordinario e pro rata — la competenza dichiarata sulla fattura,
            // lì, non guida le rate (decisione 19) e si dichiara ignorata (`competenzaFatturaIgnorata`).
            $esitoFattura = null;
            if ($this->competenzaBase !== null) {
                if ($this->competenzaBase->natura === NaturaGestione::Straordinaria) {
                    $esitoFattura = (new RisolutoreCompetenza())->perStraordinaria(
                        $fattura->competenza_dal,
                        $fattura->competenza_al,
                        $pianoRate->data_delibera_assemblea,
                        divergenzaTipoPiano: $this->competenzaBase->divergenzaTipoPiano,
                    );
                    if ($esitoFattura->richiedeDelibera) {
                        if (! $soloLettura) {
                            throw new RichiedeDeliberaException($pianoRate, $fattura);
                        }
                        $esitoFattura = null;
                        $this->competenzaNonRisolta = true;
                    } else {
                        $this->competenzaRisoltaPerFattura = true;
                    }
                } else {
                    $esitoFattura = $fattura->competenza_dal !== null && $fattura->competenza_al !== null
                        ? $this->competenzaBase->conFatturaIgnorata()
                        : $this->competenzaBase;
                }
            }
            $this->impostaCompetenzaCorrente($esitoFattura);

            // -----------------------------------------------------------------
            // 1. COMPONENTI STRAORDINARI DELLA FATTURA
            //    - Fattura corrente: SOLO righe imprevisto/ad personam
            //      (is_sopravvenienza OR immobile_id). Le righe ordinarie
            //      (capitolo a preventivo) NON fanno parte dello straordinario.
            //      Stesso filtro usato dal carrello (FetchFattureStraordinarie 1a)
            //      e dalla marcatura is_rateizzata nel PianoRateController.
            //    - Fattura pregressa: nessuna riga_fattura → si usa la copertura
            //      'sopravvenienza' (fattura_coperture), agganciata a un Conto
            //      con tabelle millesimali (FetchFattureStraordinarie 1b).
            // -----------------------------------------------------------------
            $componenti = [];

            if ($fattura->is_pregresso) {
                $coperture = DB::table('fattura_coperture')
                    ->where('fattura_passiva_id', $fattura->id)
                    ->where('tipo_copertura', 'sopravvenienza')
                    ->whereNotNull('conto_id')
                    ->get();

                foreach ($coperture as $cop) {
                    $imp = abs((int) $cop->importo);
                    if ($imp === 0) continue;

                    $componenti[] = ['immobile_id' => null, 'conto_id' => (int) $cop->conto_id, 'importo' => $imp];
                    $copertureElaborate++;
                }
            } else {
                $righe = DB::table('righe_fattura')
                    ->where('fattura_passiva_id', $fattura->id)
                    ->where(function ($q) {
                        $q->where('is_sopravvenienza', true)
                          ->orWhereNotNull('immobile_id');
                    })
                    ->get();

                foreach ($righe as $riga) {
                    // ⚠️ **Il segno si tiene: qui si generano le quote VERE che i condòmini
                    // pagano** (Fase 1-bis della beta.18, rilievo 5). Con `abs()` una rettifica
                    // in diminuzione veniva ADDEBITATA invece che accreditata: righe di
                    // sopravvenienza +€ 1.200,00 e −€ 200,00 sullo stesso capitolo davano un
                    // naturale di € 1.400,00 invece di € 1.000,00, e il piano rate chiedeva
                    // € 400,00 più del documento — due volte lo storno, la stessa firma
                    // aritmetica del difetto corretto nel motore contabile.
                    //
                    // Il valore negativo non ha bisogno di trattamento speciale a valle:
                    // l'accumulo per conto qui sotto somma già con il segno
                    // (`$importiPerConto[...] += $importoComp`), la guardia `$naturale <= 0`
                    // ferma il caso in cui gli storni superano gli addebiti, e la
                    // distribuzione proporzionale è indifferente al segno del singolo peso.
                    $imp = (int) ($riga->importo_imponibile + $riga->importo_iva);
                    if ($imp === 0) continue;

                    if (is_null($riga->immobile_id) && is_null($riga->conto_id)) {
                        Log::warning("calcolaDaFattureStraordinarie: riga senza conto_id e senza immobile_id, saltata.", [
                            'riga_id'            => $riga->id,
                            'fattura_passiva_id' => $riga->fattura_passiva_id,
                        ]);
                        continue;
                    }

                    $componenti[] = [
                        'immobile_id' => is_null($riga->immobile_id) ? null : (int) $riga->immobile_id,
                        'conto_id'    => is_null($riga->conto_id) ? null : (int) $riga->conto_id,
                        'importo'     => $imp,
                        'riga_id'     => (int) $riga->id,
                        'descrizione' => $riga->descrizione ?? null,
                    ];
                    $righeElaborate++;
                }
            }

            if (empty($componenti)) continue;

            // -----------------------------------------------------------------
            // 2. IMPORTO EFFETTIVO DA RIPARTIRE (rispetta importo_collegato)
            //    Un piano può finanziare solo una parte della fattura (residuo,
            //    split su più piani): importo_collegato è la quota reale a carico
            //    di QUESTO piano. Fallback difensivo al totale naturale se il
            //    pivot è mancante/0 (dati storici o piani ante-colonna) → in quel
            //    caso si distribuisce l'intero, esattamente come prima.
            // -----------------------------------------------------------------
            $naturale = array_sum(array_column($componenti, 'importo'));
            if ($naturale <= 0) continue;

            $collegato = (int) ($fattura->pivot->importo_collegato ?? 0);
            $target    = $collegato > 0 ? $collegato : $naturale;

            // Finanziamento intero (target == naturale): ogni componente mantiene
            // il suo importo esatto → identico alla distribuzione riga-per-riga.
            // Solo il finanziamento parziale attiva lo scaling penny-perfect.
            if ($target === $naturale) {
                $importiComponenti = array_column($componenti, 'importo');
            } else {
                $pesi = [];
                foreach ($componenti as $i => $c) {
                    $pesi[$i] = $c['importo'] / $naturale;
                }
                $importiComponenti = MoneyHelper::distribuisciPesiNormalizzati($pesi, $target);
            }

            // -----------------------------------------------------------------
            // 3. ACCUMULO PER CONTO (l'addebito diretto invece resta immediato:
            //    non passa dal netting, ogni immobile ha la sua riga_fattura)
            // -----------------------------------------------------------------
            foreach ($componenti as $i => $c) {
                $importoComp = (int) ($importiComponenti[$i] ?? 0);
                if ($importoComp === 0) continue;

                if (!is_null($c['immobile_id'])) {
                    $this->addebitaDiretto($c['immobile_id'], $importoComp, $totali, $c['riga_id'] ?? null, $c['conto_id'], $c['descrizione'] ?? null);
                    continue;
                }

                // B2: l'accumulo è per (conto, competenza). Due fatture sullo stesso conto imprevisto con
                // competenze diverse — una dichiarata, una alla delibera — non si fondono in un periodo che
                // non è di nessuna delle due: sono due chiamate di `distribuisciSuTabelle()` sullo stesso
                // conto, che il netting regge già (è proporzionale alla quota di ogni chiamata sul
                // nominale: acconto e saldo). Senza competenza la chiave è il solo conto, come prima.
                $chiave = $c['conto_id'] . '|' . ($esitoFattura === null ? '' : json_encode($esitoFattura->toArray()));
                $importiPerConto[$chiave] = ($importiPerConto[$chiave] ?? 0) + $importoComp;
                $competenzePerConto[$chiave] = $esitoFattura;
            }
        }

        // Il totale per conto su tutte le competenze: è il nominale minimo del netting (vedi la proprietà).
        foreach ($importiPerConto as $chiave => $importoComp) {
            $cid = (int) explode('|', (string) $chiave, 2)[0];
            $this->nominaleMinimoPerConto[$cid] = ($this->nominaleMinimoPerConto[$cid] ?? 0) + abs($importoComp);
        }

        // Distribuzione: UNA sola chiamata per conto **e competenza** su tutto il piano, sul totale
        // accumulato da tutte le fatture/componenti che lo riguardano.
        foreach ($importiPerConto as $chiave => $importoComp) {
            if ($importoComp === 0) continue;

            $contoId = (int) explode('|', (string) $chiave, 2)[0];
            $this->impostaCompetenzaCorrente($competenzePerConto[$chiave] ?? null);

            $conto = Conto::with([
                'tabelleMillesimali.tabella.quote.immobile.anagrafiche',
                'tabelleMillesimali.ripartizioni',
            ])->find($contoId);

            if (!$conto) {
                Log::warning("calcolaDaFattureStraordinarie: conto_id={$contoId} non trovato, componente saltato.", [
                    'piano_rate_id' => $pianoRate->id,
                ]);
                continue;
            }

            $importoConto = in_array($conto->tipo, ['spesa', 'uscita'])
                ? $importoComp
                : -$importoComp;

            $this->distribuisciSuTabelle($conto, $importoConto, $totali);
        }

        Log::info("=== CALCOLO STRAORDINARIO COMPLETATO ===", [
            'piano_rate_id'       => $pianoRate->id,
            'fatture'             => $fatture->count(),
            'righe_elaborate'     => $righeElaborate,
            'coperture_pregresse' => $copertureElaborate,
            'soggetti_trovati'    => count($totali),
        ]);

        return $totali;
    }

    /**
     * @return array Dati relativi agli importi scoperti durante il calcolo.
     */
    public function getScoperti(): array
    {
        return $this->scopertiAccumulati;
    }

    /**
     * Le righe senza millesimo incontrate durante il calcolo, senza ripetizioni.
     *
     * @return list<array{immobile_id:int, tabella_id:int, conto_id:int}>
     */
    public function getMillesimiNonCompilati(): array
    {
        $viste = [];
        $unici = [];

        foreach ($this->millesimiNonCompilati as $riga) {
            $chiave = $riga['tabella_id'].':'.$riga['immobile_id'];

            if (isset($viste[$chiave])) {
                continue;
            }

            $viste[$chiave] = true;
            $unici[] = $riga;
        }

        return $unici;
    }

    /**
     * Quanto è stato davvero portato a riparto, per capitolo: `conto_id => centesimi con segno`.
     *
     * **Già al netto della decurtazione degli scoperti, ancora al lordo del netting** del
     * già-versato. La distinzione non è pedanteria: la colonna di una tabella deve continuare a
     * valere il budget deliberato, mentre lo sconto a un'unità che aveva già versato è una
     * grandezza per immobile e vive in una colonna sua — vedi `getNettingApplicato()`, costruito nella
     * beta.76 dopo essere stato citato da qui, senza esistere, dalla beta.49.
     *
     * Vuoto finché non si è chiamato `calcolaPerGestione()` o `calcolaDaFattureStraordinarie()`.
     *
     * @return array<int,int>
     */
    public function getImportiPerConto(): array
    {
        return $this->importiRipartiti;
    }

    /**
     * Gli addebiti ad personam, che non appartengono a nessuna tabella millesimale.
     *
     * @return array<int,array{immobile_id:int,anagrafica_id:int,importo:int}>
     */
    public function getAddebitiDiretti(): array
    {
        return $this->addebitiDiretti;
    }

    /**
     * Il dettaglio del riparto, una riga per componente (vedi `$righeDettaglio`).
     *
     * @return list<array<string,mixed>>
     */
    public function getRigheDettaglio(): array
    {
        return $this->righeDettaglio;
    }

    /**
     * Una riga del dettaglio con tutte le chiavi, così chi la scrive o la legge non deve
     * chiedersi quali manchino.
     *
     * @param array<string,mixed> $campi
     * @return array<string,mixed>
     */
    private function rigaDettaglio(string $tipo, array $campi): array
    {
        return array_merge([
            'tipo'              => $tipo,
            'anagrafica_id'     => null,
            'immobile_id'       => null,
            'conto_id'          => null,
            'conto_nome'        => null,
            'conto_radice_id'   => null,
            'conto_radice_nome' => null,
            'tabella_id'        => null,
            'tabella_nome'      => null,
            'tabella_quota'     => null,
            'coefficiente'      => null,
            'valore_millesimo'  => null,
            'somma_valori'      => null,
            'ruolo_richiesto'   => null,
            'ruolo_risolto'     => null,
            'quota_possesso'    => null,
            'riga_fattura_id'   => null,
            'riga_descrizione'  => null,
            'importo'           => 0,
            // B2 (decisione 15): il congelato temporale, per riga. Nulli sulle righe atemporali.
            'competenza_dal'    => null,
            'competenza_al'     => null,
            'gradino_competenza' => null,
            'giorni_titolarita' => null,
            'titolarita_dal'    => null,
            'titolarita_al'     => null,
        ], $campi);
    }

    // =========================================================================
    // B2 — la competenza temporale: base, raffinamento per conto, pesi per giorni
    // =========================================================================

    /** Il chiamante può passare un periodo nudo (i test) o l'esito della cascata: qui diventano una cosa sola. */
    private function impostaCompetenzaBase(PeriodoCompetenza|EsitoCompetenza|null $periodo, NaturaGestione $natura): void
    {
        $this->competenzaBase = match (true) {
            $periodo === null => null,
            $periodo instanceof EsitoCompetenza => $periodo,
            default => new EsitoCompetenza($natura, InsiemePeriodi::uno($periodo), GradinoCompetenza::Dichiarata),
        };
        $this->impostaCompetenzaCorrente($this->competenzaBase);
    }

    private function impostaCompetenzaCorrente(?EsitoCompetenza $esito): void
    {
        $this->periodo = $esito?->periodi;
        $this->gradinoCorrente = $esito?->gradino?->value;
    }

    /**
     * La competenza del capitolo che si sta ripartendo (decisione 20): se la riga di `piano_rate_capitoli`
     * del conto — o della sua radice — dichiara dei tratti in `competenze_capitolo`, valgono quelli
     * (gradino «capitolo»); altrimenti la base che il chiamante ha passato (gestione o esercizio).
     * Con la base a `null` il calcolo resta atemporale e non si legge nulla.
     */
    private function impostaCompetenzaPerConto(Conto $conto): void
    {
        if ($this->competenzaBase === null || ! $this->competenzaPerConto) {
            return;
        }

        $pivotId = $this->pivotCapitoloIds[$conto->id] ?? $this->pivotCapitoloIds[$this->radiceDi($conto)->id] ?? null;
        $tratti = $pivotId !== null ? CompetenzaCapitolo::insiemePer($pivotId) : null;

        $this->impostaCompetenzaCorrente($tratti !== null
            ? new EsitoCompetenza($this->competenzaBase->natura, $tratti, GradinoCompetenza::Capitolo, divergenzaTipoPiano: $this->competenzaBase->divergenzaTipoPiano)
            : $this->competenzaBase);
    }

    /**
     * I pesi per giorni di una coppia (immobile, tipologia) — D8 del progetto — o `null` quando il motore
     * deve eseguire **letteralmente** `quota / somma_quote`.
     *
     * Ritorna `null` in due casi, entrambi «niente pro rata»: calcolo atemporale; nessun titolare della
     * coppia entra o esce nel periodo (l'uscita anticipata di D8, che è la garanzia d'identità in
     * virgola mobile con la beta.30). Altrimenti la mappa `id riga → [peso, giorni]` con la somma dei
     * pesi — che può essere **zero** se nessuno copre un giorno dei tratti: in quel caso chi chiama
     * registra uno scoperto, non ripiega sulle quote — e la coppia finisce fra i destinatari cambiati
     * (cancello 2).
     *
     * @param Collection<int, object> $righeCoppia   tutte le righe **attive** della coppia, anche fuori periodo
     * @param Collection<int, object> $righeInPeriodo quelle che il risolutore ha lasciato passare
     * @return array{pesi: array<int,float>, giorni: array<int,int>, somma: float}|null
     */
    private function pesiPerGiorni(Collection $righeCoppia, Collection $righeInPeriodo, int $immobileId, string $tipologia, ?int $contoId): ?array
    {
        if ($this->periodo === null) {
            return null;
        }
        if (! $this->titolari->cambiaTitolaritaNelPeriodo($righeCoppia, $this->periodo)) {
            return null;
        }

        $pesi = [];
        $giorni = [];
        $tratti = [];
        foreach ($righeInPeriodo as $riga) {
            $g = $this->titolari->giorniDiTitolarita($riga, $this->periodo, $righeCoppia);
            $giorni[(int) $riga->id] = $g;
            $pesi[(int) $riga->id] = (float) $riga->quota * $g;
            // Il tratto che quei giorni coprono (migrazione 11): il conguaglio del passaggio divide su questo.
            $tratti[(int) $riga->id] = $this->titolari->trattoEffettivo($riga, $this->periodo, $righeCoppia);
        }
        $somma = array_sum($pesi);
        // Decisione 22 (S8-8): i giorni del periodo in cui nessuno della coppia è in vigore — chi esce senza un
        // successore. Non si spalmano su chi c'era (pagherebbe giorni in cui non era titolare): chi chiama li
        // manda all'anello successivo della cascata su quei giorni, o li registra come scoperto.
        $scoperto = $this->titolari->giorniScoperti($righeCoppia, $this->periodo);
        $giorniScoperti = $scoperto?->giorni() ?? 0;
        // Tutti a zero giorni: i titolari passano il filtro del periodo ma nessuno copre un giorno dei
        // tratti (unità posseduta solo nel buco fra due tratti di riscaldamento). Non si ripiega sulle
        // quote — sarebbe chiedere una spesa a chi non l'ha maturata — e si torna con la somma a zero:
        // chi chiama la registra come scoperto, con il suo motivo.
        $this->registraCambiamento([
            'immobile_id' => $immobileId,
            'tipologia'   => $tipologia,
            'conto_id'    => $contoId,
            'motivo'      => 'pro_rata_giorni',
            'gradino'     => $this->gradinoCorrente,
            'periodo'     => $this->periodo->toArray(),
            'giorni_periodo'  => $this->periodo->giorni(),
            'giorni_scoperti' => $giorniScoperti,
            'righe'       => array_map(fn ($id) => ['riga_id' => $id, 'giorni' => $giorni[$id]], array_keys($pesi)),
        ]);

        return ['pesi' => $pesi, 'giorni' => $giorni, 'tratti' => $tratti, 'somma' => $somma, 'giorni_scoperti' => $giorniScoperti, 'scoperto' => $scoperto, 'giorni_periodo' => $this->periodo->giorni()];
    }

    /**
     * Decisione 22 (S8-8): a chi vanno i giorni in cui nessun titolare del ruolo risolto è in vigore. Agli anelli
     * successivi della cascata, **valutati su quei giorni** (inquilino → usufruttuario → proprietario → nudo;
     * proprietario → nudo e viceversa: sono lo stesso soggetto economico con l'usufrutto staccato o attaccato,
     * e dopo una costituzione o un'estinzione la persona continua sull'anello gemello — verifica S8-bis, L2-1).
     * La cascata **avanza sul residuo** (L2-3): ciò che il primo anello non copre passa al successivo, e solo
     * ciò che nessun anello copre resta **scoperto** (`giorni_senza_titolare`) — chi esce senza nessun successore
     * lascia giorni di nessuno, e la generazione si ferma con il suo motivo: non si tira a indovinare chi paga.
     *
     * I pesi delle righe sono normalizzati **per anello sui giorni che l'anello copre** (quota × giorni / somma
     * dell'anello × giorni coperti): fra anelli la spesa si divide per giorni, non per quote, e con un anello solo
     * i rapporti sono quelli di prima. `somma` è il totale dei giorni coperti.
     *
     * @param Collection<int, object> $righeUnita tutte le righe della pivot dell'unità (`attivo`, `tipologia`, `quota`, `data_inizio`, `data_fine`, `id`, `anagrafica_id`)
     * @return array{ruolo: ?string, righe: list<array{riga: object, anagrafica_id: int, ruolo: string, giorni: int, peso: float, tratto: ?PeriodoCompetenza}>, somma: float, residuo_giorni: int}
     */
    private function ripiegoGiorniScoperti(Collection $righeUnita, string $ruoloRisolto, InsiemePeriodi $scoperto): array
    {
        $residuo = $scoperto;
        $righe = [];
        $somma = 0.0;
        $primo = null;
        foreach (RuoloAnagraficaImmobile::catenaRipiego($ruoloRisolto) as $ruolo) {
            $righeCoppia = $righeUnita->filter(fn ($r) => ($r->tipologia ?? null) === $ruolo->value && (bool) ($r->attivo ?? false))->values();
            $anello = [];
            $sommaAnello = 0.0;
            foreach ($righeCoppia as $riga) {
                if ((float) ($riga->quota ?? 0) <= 0.0) {
                    continue;
                }
                $g = $this->titolari->giorniDiTitolarita($riga, $residuo, $righeCoppia);
                if ($g <= 0) {
                    continue;
                }
                $anello[] = ['riga' => $riga, 'anagrafica_id' => (int) $riga->anagrafica_id, 'ruolo' => $ruolo->value, 'giorni' => $g, 'peso' => (float) $riga->quota * $g, 'tratto' => $this->titolari->trattoEffettivo($riga, $residuo, $righeCoppia)];
                $sommaAnello += (float) $riga->quota * $g;
            }
            if ($anello === []) {
                continue;
            }
            $primo ??= $ruolo->value;
            $dopo = $this->titolari->giorniScoperti($righeCoppia, $residuo);
            $copertiAnello = $residuo->giorni() - ($dopo?->giorni() ?? 0);
            foreach ($anello as $r) {
                $r['peso'] = $sommaAnello > 0 ? $r['peso'] / $sommaAnello * $copertiAnello : 0.0;
                $righe[] = $r;
            }
            $somma += $copertiAnello;
            if ($dopo === null) {
                $residuo = null;
                break;
            }
            $residuo = $dopo;
        }

        return ['ruolo' => $primo, 'righe' => $righe, 'somma' => $somma, 'residuo_giorni' => $residuo?->giorni() ?? 0];
    }

    /** Annota nel cancello (2) dove sono andati i giorni scoperti della coppia (decisione 22). */
    private function annotaRipiego(int $immobileId, string $tipologia, ?int $contoId, array $ripiego): void
    {
        $chiave = implode('|', [$immobileId, $tipologia, $contoId ?? '', 'pro_rata_giorni']);
        if (! isset($this->destinatariCambiati[$chiave])) {
            return;
        }
        $this->destinatariCambiati[$chiave]['ripiego'] = [
            'ruolo'  => $ripiego['ruolo'],
            'righe'  => array_map(fn ($r) => ['riga_id' => (int) $r['riga']->id, 'giorni' => $r['giorni'], 'ruolo' => $r['ruolo']], $ripiego['righe']),
            'giorni_residui' => $ripiego['residuo_giorni'],
        ];
    }

    /**
     * Il congelato temporale da scrivere sulla riga del dettaglio (decisione 15); vuoto se il calcolo è atemporale.
     * Con il tratto (migrazione 11) la riga dice anche **quali** giorni copre, non solo quanti.
     */
    private function congelatoTemporale(?int $giorni, ?PeriodoCompetenza $tratto = null): array
    {
        if ($this->periodo === null) {
            return [];
        }

        return [
            'competenza_dal'     => $this->periodo->dal()->toDateString(),
            'competenza_al'      => $this->periodo->al()->toDateString(),
            'gradino_competenza' => $this->gradinoCorrente,
            'giorni_titolarita'  => $giorni,
            'titolarita_dal'     => $tratto?->dal->toDateString(),
            'titolarita_al'      => $tratto?->al->toDateString(),
        ];
    }

    /** Registra un titolare che il periodo ha escluso e che il calcolo atemporale avrebbe pagato (cancello 2). */
    private function registraEsclusiDalPeriodo(Collection $attiviSenzaPeriodo, Collection $attiviNelPeriodo, int $immobileId, string $tipologia, ?int $contoId): void
    {
        if ($this->periodo === null) {
            return;
        }
        $esclusi = $attiviSenzaPeriodo->pluck('id')->diff($attiviNelPeriodo->pluck('id'))->values();
        if ($esclusi->isEmpty()) {
            return;
        }
        $this->registraCambiamento([
            'immobile_id' => $immobileId,
            'tipologia'   => $tipologia,
            'conto_id'    => $contoId,
            'motivo'      => 'fuori_periodo',
            'gradino'     => $this->gradinoCorrente,
            'periodo'     => $this->periodo->toArray(),
            'anagrafiche_escluse' => $esclusi->all(),
        ]);
    }

    /**
     * Gli esclusi dal periodo misurati sulla **cascata** (`distribuisciSuTabelle`): la stessa cascata dei
     * ruoli, rifatta senza periodo, dice chi il calcolo atemporale avrebbe pagato; la differenza con chi
     * il periodo ha lasciato è ciò che il cancello (2) deve mostrare. Registra sul ruolo risolto (o su
     * quello atemporale, se il periodo ha esaurito la cascata).
     *
     * @param Collection<int, \App\Models\Anagrafica> $nelPeriodo i destinatari trovati con il periodo (può essere vuota)
     */
    private function registraEsclusiDopoCascata(\App\Models\Immobile $immobile, string $ruoloRichiesto, string $ruoloRisolto, Collection $nelPeriodo, int $contoId): void
    {
        if ($this->periodo === null) {
            return;
        }
        $senzaPeriodo = collect();
        $ruoloAtemporale = $ruoloRichiesto;
        $candidati = [$ruoloRichiesto, ...array_map(fn ($r) => $r->value, RuoloAnagraficaImmobile::catenaRipiego($ruoloRichiesto))];
        foreach ($candidati as $ruolo) {
            $senzaPeriodo = $this->titolari->attiviAlla($immobile->anagrafiche)
                ->where('pivot.tipologia', $ruolo)
                ->filter(fn ($a) => (float) $a->pivot->quota > 0.0);
            if ($senzaPeriodo->isNotEmpty()) {
                $ruoloAtemporale = $ruolo;
                break;
            }
        }
        // Stesso ruolo: la differenza sono i singoli esclusi. Ruolo diverso: il periodo ha spostato la
        // spesa a un altro anello della catena — tutti quelli dell'anello atemporale sono «fuori periodo».
        $tipologia = $nelPeriodo->isEmpty() ? $ruoloAtemporale : $ruoloRisolto;
        $confronto = $ruoloAtemporale === $ruoloRisolto && $nelPeriodo->isNotEmpty() ? $nelPeriodo : collect();
        $this->registraEsclusiDalPeriodo($senzaPeriodo, $confronto, (int) $immobile->id, $tipologia, $contoId);
    }

    /**
     * Una voce sola per (unità, ruolo, conto, motivo): `distribuisciSuTabelle` passa per ogni tabella e
     * per ogni ripartizione dello stesso conto, e senza questa chiave il pannello del cancello (2)
     * mostrava la stessa coppia due volte e `coppie` la contava due volte (verifica S4).
     */
    private function registraCambiamento(array $voce): void
    {
        $chiave = implode('|', [$voce['immobile_id'], $voce['tipologia'], $voce['conto_id'] ?? '', $voce['motivo']]);
        $this->destinatariCambiati[$chiave] = $voce;
    }

    /**
     * Dove la risoluzione temporale ha cambiato destinatari o pesi rispetto a quella atemporale.
     * Vuoto sia con il calcolo atemporale sia quando nessun titolare cambia nel periodo: è la
     * condizione del cancello (2) della decisione 14.
     *
     * `competenza_non_risolta` è vero solo in sola lettura, quando una parte del calcolo è andata
     * atemporale perché la competenza non era risolvibile (straordinario senza delibera, decisione 12).
     *
     * @return array{temporale: bool, destinatari_cambiati: list<array<string,mixed>>, competenza_non_risolta: bool}
     */
    public function getRisoluzioneTemporale(): array
    {
        return [
            // Una base non risolta (straordinario senza delibera) conta come temporale solo se almeno una
            // fattura ha portato la sua competenza dichiarata.
            'temporale'            => $this->competenzaBase !== null
                && (! $this->competenzaBase->richiedeDelibera || $this->competenzaRisoltaPerFattura),
            'destinatari_cambiati' => array_values($this->destinatariCambiati),
            'competenza_non_risolta' => $this->competenzaNonRisolta,
        ];
    }

    /**
     * La radice del conto **al momento della generazione**: la stampa per capitolo aggrega sulla
     * radice, e spostare un sottoconto dopo l'assemblea non deve spostare una colonna di un
     * documento registrato — per questo la riga porta `conto_radice_id` (1.11.0-beta.29). Sale per `parent` con una guardia sui cicli.
     */
    private function radiceDi(Conto $conto): Conto
    {
        if (isset($this->radiciCache[$conto->id])) {
            return $this->radiciCache[$conto->id];
        }

        $radice = $conto;
        $visti  = [$conto->id => true];
        while ($radice->parent_id) {
            $padre = $radice->parent;
            if (!$padre || isset($visti[$padre->id])) break;
            $visti[$padre->id] = true;
            $radice = $padre;
        }

        return $this->radiciCache[$conto->id] = $radice;
    }

    /** Lo zero documentato di un'unità in una tabella: una riga per (tabella, immobile), qualunque sia il numero dei conti. */
    private function registraZero(object $tabella, object $immobile, ?float $valore): void
    {
        $chiave = $tabella->id.'|'.$immobile->id;
        if (isset($this->zeriRegistrati[$chiave])) return;
        $this->zeriRegistrati[$chiave] = true;

        $this->righeDettaglio[] = $this->rigaDettaglio('quota_zero', [
            'immobile_id'      => (int) $immobile->id,
            'tabella_id'       => (int) $tabella->id,
            'tabella_nome'     => $tabella->nome,
            'tabella_quota'    => self::etichettaQuota($tabella),
            'valore_millesimo' => $valore,
        ]);
    }

    private static function etichettaQuota(object $tabella): ?string
    {
        $quota = $tabella->quota ?? null;

        return $quota instanceof \BackedEnum ? (string) $quota->value : ($quota === null ? null : (string) $quota);
    }

    // =========================================================================
    // METODI PRIVATI
    // =========================================================================

    /**
     * Esegue un addebito diretto forzato su un singolo immobile (es. addebito personale).
     *
     * @param int $immobileId L'ID dell'immobile
     * @param int $importoCents L'importo in centesimi
     * @param array &$totali Array in cui accumulare la quota
     */
    private function addebitaDiretto(int $immobileId, int $importoCents, array &$totali, ?int $rigaFatturaId = null, ?int $contoId = null, ?string $descrizione = null): void
    {
        $occupanti = $this->titolari->vincolaQuery(
            DB::table('anagrafica_immobile')->where('immobile_id', $immobileId),
            $this->periodo
        )->get();
        // Tutte le righe attive dell'unità, anche fuori periodo: servono a D7 (il predecessore) e a D8.
        $occupantiSenzaPeriodo = $this->periodo === null
            ? $occupanti
            : $this->titolari->vincolaQuery(DB::table('anagrafica_immobile')->where('immobile_id', $immobileId))->get();

        // Il ripiego di prima era **piatto**: non trovando un proprietario prendeva qualunque
        // occupante attivo, quindi anche l'inquilino — che verso il condominio non è debitore.
        // È lo stesso difetto del riparto dei saldi solidali, un grado più mite perché qui
        // serve che il proprietario non sia censito; la regola ora è una sola per entrambi.
        // `titolariDiDirittoReale()` è già in ordine di preferenza: proprietario, nudo
        // proprietario, usufruttuario.
        $destinatari = collect();
        foreach (RuoloAnagraficaImmobile::titolariDiDirittoReale() as $ruolo) {
            $destinatari = $occupanti->where('tipologia', $ruolo->value)->values();

            if ($destinatari->isNotEmpty()) {
                break;
            }
        }

        if ($destinatari->isEmpty()) {
            Log::warning("addebitaDiretto: nessun titolare di diritto reale attivo per immobile_id={$immobileId}. Importo {$importoCents} centesimi registrato come scoperto.", [
                'ruoli_attivi' => $occupanti->pluck('tipologia')->unique()->values()->all(),
            ]);

            // ⚠️ Fino alla beta.28 qui c'era solo il warning e l'importo **spariva dal piano**: la
            // riparazione del balcone di un'unità senza proprietario censito non veniva chiesta a
            // nessuno, e la generazione passava. È la stessa forma delle beta.32 e .47 (denaro che
            // se ne va con un log): ora è uno scoperto con il suo motivo, la generazione si ferma e
            // chiede la motivazione come per gli altri (1.11.0-beta.29).
            $this->scopertiAccumulati[] = [
                'immobile_id'     => $immobileId,
                'conto_id'        => $contoId,
                'tabella_id'      => null,
                'ruolo_richiesto' => null,
                'importo'         => abs($importoCents),
                'motivo'          => 'ad_personam_senza_titolare',
                // La riga con l'immobile non porta il conto (`FatturaPassivaService` lo azzera):
                // a video la spesa si riconosce dalla sua descrizione, non da «Conto #».
                'riga_descrizione' => $descrizione,
            ];

            return;
        }

        // B2: chi il periodo ha escluso, e i pesi per giorni se qualcuno della coppia cambia (D7, D8).
        // Gli esclusi si misurano sulla cascata: chi la stessa cascata avrebbe scelto SENZA periodo e
        // qui non c'è più — non solo sul ruolo risolto nel periodo, altrimenti il proprietario uscito
        // prima del periodo con il solo usufruttuario in corso non viene registrato (verifica S4).
        $ruoloDestinatari = (string) $destinatari->first()->tipologia;
        $righeCoppia = $occupantiSenzaPeriodo->where('tipologia', $ruoloDestinatari)->values();
        $destinatariSenzaPeriodo = collect();
        foreach (RuoloAnagraficaImmobile::titolariDiDirittoReale() as $ruolo) {
            $destinatariSenzaPeriodo = $occupantiSenzaPeriodo->where('tipologia', $ruolo->value)->values();
            if ($destinatariSenzaPeriodo->isNotEmpty()) {
                break;
            }
        }
        $this->registraEsclusiDalPeriodo(
            $destinatariSenzaPeriodo->map(fn ($r) => (object) ['id' => (int) $r->anagrafica_id]),
            $destinatari->map(fn ($r) => (object) ['id' => (int) $r->anagrafica_id]),
            $immobileId, $ruoloDestinatari, $contoId,
        );
        $perGiorni = $this->pesiPerGiorni($righeCoppia, $destinatari, $immobileId, $ruoloDestinatari, $contoId);
        // Tutti a zero giorni sui tratti (l'unico titolare del ruolo sta nel buco fra due tratti): il vuoto è l'intero
        // periodo e passa per il ripiego della decisione 22 come ogni altro vuoto (S8-bis, L2-4); scoperto
        // «titolari_fuori_competenza» solo se neanche la cascata copre nulla.

        // Il conto e la sua radice dipendono dalla riga, non dal destinatario: si risolvono una volta.
        $conto  = $contoId ? Conto::find($contoId) : null;
        $radice = $conto ? $this->radiceDi($conto) : null;
        $scrivi = function (object $destinatario, int $quotaDaPagare, array $congelato) use (&$totali, $immobileId, $conto, $radice, $rigaFatturaId, $descrizione): void {
            if ($quotaDaPagare === 0) {
                return;
            }
            $aid = (int) $destinatario->anagrafica_id;
            if (!isset($totali[$aid])) $totali[$aid] = [];
            if (!isset($totali[$aid][$immobileId])) $totali[$aid][$immobileId] = 0;
            $totali[$aid][$immobileId] += $quotaDaPagare;

            // Registro degli addebiti diretti: questa spesa è di una sola unità e non passa da nessuna
            // tabella millesimale. La stampa la escludeva del tutto (documento vuoto quando la
            // fattura era tutta ad personam) oppure, se la riga aveva anche un conto, la
            // spalmava sulla tabella — cioè la riparazione del balcone dell'interno 4 risultava
            // ripartita su tutti. Vive in una colonna sua, fuori dalle tabelle.
            $this->addebitiDiretti[] = [
                'immobile_id'   => $immobileId,
                'anagrafica_id' => $aid,
                'importo'       => $quotaDaPagare,
            ];

            $this->righeDettaglio[] = $this->rigaDettaglio('ad_personam', [
                'anagrafica_id'     => $aid,
                'immobile_id'       => $immobileId,
                'conto_id'          => $conto?->id,
                'conto_nome'        => $conto?->nome,
                'conto_radice_id'   => $radice?->id,
                'conto_radice_nome' => $radice?->nome,
                'ruolo_risolto'     => (string) $destinatario->tipologia,
                'quota_possesso'    => (float) $destinatario->quota,
                'riga_fattura_id'   => $rigaFatturaId,
                'riga_descrizione'  => $descrizione,
                'importo'           => $quotaDaPagare,
            ] + $congelato);
        };

        if ($perGiorni === null) {
            // Atemporale (beta.30, letterale): pesi per persona, sommati se la stessa persona ha due periodi
            // sull'unità; una riga del dettaglio per persona. Stessa primitiva del riparto dei saldi: resti
            // maggiori, somma esatta — prima l'arrotondamento lo assorbiva «l'ultimo», cioè chi capitava ultimo
            // nell'ordine di ritorno del database, una regola che non si sa spiegare a chi la paga.
            $pesi = [];
            foreach ($destinatari as $d) {
                $pesi[(int) $d->anagrafica_id] = ($pesi[(int) $d->anagrafica_id] ?? 0.0) + (float) $d->quota;
            }
            $quote = MoneyHelper::ripartisciPerQuote($importoCents, $pesi);
            foreach ($destinatari->unique('anagrafica_id')->values() as $destinatario) {
                $scrivi($destinatario, (int) ($quote[(int) $destinatario->anagrafica_id] ?? 0), $this->congelatoTemporale(null));
            }

            return;
        }

        // Temporale: **una riga del dettaglio per riga della pivot** (migrazione 11: ogni riga porta il suo tratto —
        // la stessa persona con due periodi sull'unità ha due tratti, non una somma di giorni). Decisione 22: i
        // giorni senza nessuno del ruolo vanno agli anelli successivi su quei giorni; il resto è scoperto.
        $pesiRiga = [];
        foreach ($destinatari as $d) {
            $pesiRiga[(int) $d->id] = $perGiorni['pesi'][(int) $d->id] ?? 0.0;
        }
        $importoCoperto = $importoCents;
        $quoteRipiego = [];
        $righeRipiego = [];
        if ($perGiorni['giorni_scoperti'] > 0) {
            $importoScoperto = (int) round($importoCents * $perGiorni['giorni_scoperti'] / $perGiorni['giorni_periodo']);
            $importoCoperto = $importoCents - $importoScoperto;
            $ripiego = $this->ripiegoGiorniScoperti($occupantiSenzaPeriodo, $ruoloDestinatari, $perGiorni['scoperto']);
            $importoResiduo = (int) round($importoCents * $ripiego['residuo_giorni'] / $perGiorni['giorni_periodo']);
            if ($ripiego['righe'] !== []) {
                $pesiRipiego = [];
                foreach ($ripiego['righe'] as $r) {
                    $pesiRipiego[(int) $r['riga']->id] = ($pesiRipiego[(int) $r['riga']->id] ?? 0.0) + $r['peso'];
                    $righeRipiego[(int) $r['riga']->id] = $r;
                }
                $quoteRipiego = MoneyHelper::ripartisciPerQuote($importoScoperto - $importoResiduo, $pesiRipiego);
            }
            if ($importoResiduo !== 0) {
                $this->scopertiAccumulati[] = [
                    'immobile_id'     => $immobileId,
                    'conto_id'        => $contoId,
                    'tabella_id'      => null,
                    'ruolo_richiesto' => $ruoloDestinatari,
                    'importo'         => abs($importoResiduo),
                    'motivo'          => $perGiorni['somma'] <= 0.0 && $ripiego['righe'] === [] ? 'titolari_fuori_competenza' : 'giorni_senza_titolare',
                    'giorni'          => $ripiego['residuo_giorni'],
                    'riga_descrizione' => $descrizione,
                ];
            }
            $this->annotaRipiego($immobileId, $ruoloDestinatari, $contoId, $ripiego);
        }

        $quote = MoneyHelper::ripartisciPerQuote($importoCoperto, $pesiRiga);
        foreach ($destinatari as $d) {
            $rid = (int) $d->id;
            $scrivi($d, (int) ($quote[$rid] ?? 0), $this->congelatoTemporale($perGiorni['giorni'][$rid] ?? 0, $perGiorni['tratti'][$rid] ?? null));
        }
        foreach ($righeRipiego as $rid => $r) {
            $scrivi($r['riga'], (int) ($quoteRipiego[$rid] ?? 0), $this->congelatoTemporale($r['giorni'], $r['tratto']));
        }
    }

    /**
     * Processa iterativamente una collezione di conti e calcola la ripartizione su ognuno.
     *
     * @param Collection $conti La collezione di conti da elaborare
     * @param array &$totali Array in cui accumulare i risultati
     */
    private function processaConti(Collection $conti, array &$totali, array $contiImpegnatiIds = [], array &$processatiIds = []): void
    {
        foreach ($conti as $conto) {
            if (in_array($conto->id, $contiImpegnatiIds)) continue;
            if (in_array($conto->id, $processatiIds)) continue;
            $processatiIds[] = $conto->id;

            $hasOverride = isset($this->pivotOverrides[$conto->id]);

            if ($hasOverride) {
                $importoOverride = $this->pivotOverrides[$conto->id];

                if ($conto->tabelleMillesimali->isNotEmpty()) {
                    $importoConto = in_array($conto->tipo, ['spesa', 'uscita'])
                        ? abs($importoOverride)
                        : -abs($importoOverride);

                    $this->distribuisciSuTabelle($conto, $importoConto, $totali);
                    continue;
                }

                elseif ($conto->sottoconti->isNotEmpty()) {

                    $sottocontiFiltrati = $this->pianoRateCreatedAt
                        ? $conto->sottoconti->filter(
                            fn($s) => $s->created_at->lte($this->pianoRateCreatedAt)
                        )
                        : $conto->sottoconti;

                    if ($sottocontiFiltrati->isEmpty() && $importoOverride > 0) {
                        Log::warning("processaConti: snapshot vuoto per conto ID={$conto->id} ('{$conto->nome}'), fallback su tutti i sottoconti correnti.", [
                            'importo_override' => $importoOverride,
                        ]);
                        $sottocontiFiltrati = $conto->sottoconti;
                    }

                    if ($sottocontiFiltrati->isEmpty()) continue;

                    $totaleOriginaleFigli = (int) $sottocontiFiltrati->sum('importo');
                    $totaleFigli  = $sottocontiFiltrati->count();
                    $sommaAssegnata = 0;
                    $counter = 0;

                    foreach ($sottocontiFiltrati as $figlio) {
                        $counter++;
                        if ($counter === $totaleFigli) {
                            $quotaFiglio = $importoOverride - $sommaAssegnata;
                        } elseif ($totaleOriginaleFigli > 0) {
                            $quotaFiglio = (int) round($importoOverride * ($figlio->importo / $totaleOriginaleFigli));
                        } else {
                            $quotaFiglio = (int) round($importoOverride / $totaleFigli);
                        }
                        $sommaAssegnata += $quotaFiglio;
                        $this->pivotOverrides[$figlio->id] = $quotaFiglio;
                    }

                    $this->processaConti($sottocontiFiltrati, $totali, $contiImpegnatiIds, $processatiIds);
                    continue;
                }

                // Capitolo con importo forzato dal piano, senza tabelle millesimali e senza
                // sottoconti: quell'importo non è assegnabile a nessuno.
                //
                // Fino alla beta.47 qui c'era un `continue` con un Log::warning. L'importo
                // spariva dal piano e il prodotto non lo diceva: il cruscotto chiedeva un
                // ricalcolo, «Ricalcola» rispondeva «Operazione Completata» e non cambiava
                // niente — per sempre, perché nessun numero di clic poteva risolverlo.
                //
                // La guardia della beta.32 esisteva già ma vive DENTRO distribuisciSuTabelle
                // (vedi il ramo `tabelleMillesimali->isEmpty()`), e questo ramo lì non ci
                // arriva mai. Era la stessa guardia, corretta in un verso solo — e il verso
                // scoperto era quello di *tutti* i piani rate, perché un piano forza sempre
                // l'importo dei suoi capitoli.
                //
                // Stesso bucket, stesso motivo, stessa richiesta di motivazione scritta.
                Log::warning("processaConti: conto ID={$conto->id} ('{$conto->nome}') ha override ma nessuna tabella millesimale e nessun sottoconto. Importo registrato come scoperto.", [
                    'importo_override_cents' => $importoOverride,
                    'conto_tipo'  => $conto->tipo,
                    'conto_nome'  => $conto->nome,
                ]);

                if ($importoOverride != 0) {
                    $this->scopertiAccumulati[] = [
                        'immobile_id'     => null,   // l'intero capitolo, non la quota di qualcuno
                        'conto_id'        => $conto->id,
                        'tabella_id'      => null,
                        'ruolo_richiesto' => null,
                        'importo'         => abs($importoOverride),
                        'motivo'          => 'conto_senza_tabella',
                    ];
                }

                continue;
            }

            // Branch senza override: usa importo live del conto
            $importoLordo = (int) $conto->importo;

            if ($importoLordo !== 0) {
                $tipo = $conto->tipo ?? 'spesa';
                $importoConto = in_array($tipo, ['spesa', 'uscita'])
                    ? abs($importoLordo)
                    : -abs($importoLordo);

                $this->distribuisciSuTabelle($conto, $importoConto, $totali);
            }

            if ($conto->sottoconti && $conto->sottoconti->count() > 0) {
                $this->processaConti($conto->sottoconti, $totali, $contiImpegnatiIds, $processatiIds);
            }
        }
    }

    /**
     * Algoritmo core: Ripartisce l'importo di un conto sugli immobili collegati alle tabelle,
     * risolvendo la cascata del ruolo e mantenendo traccia degli scoperti.
     *
     * @param Conto $conto Conto di spesa da ripartire
     * @param int $importoConto Importo in centesimi
     * @param array &$totali Array in cui accumulare i risultati
     */
    private function distribuisciSuTabelle(Conto $conto, int $importoConto, array &$totali): void
    {
        // B2: la competenza del capitolo (tratti dichiarati → gestione → esercizio), o niente se atemporale.
        $this->impostaCompetenzaPerConto($conto);

        $weights      = [];
        $pesiScoperti = [];
        /** @var array<string,list<array<string,mixed>>> i componenti di ogni chiave «aid|iid», per il dettaglio */
        $componenti   = [];

        /**
         * La quota di spesa che i coefficienti delle tabelle collegate **dichiarano** di coprire.
         *
         * Serve alla guardia in fondo al metodo: `AssociaTabellaController` impedisce che la somma
         * superi il 100, ma sotto non guarda nessuno — e la rinormalizzazione finale
         * (`$w / $pesoSoggetti`) distribuisce comunque tutto, quindi la parte non dichiarata
         * finiva addosso ai partecipanti delle tabelle che c'erano.
         */
        $quotaDichiarata = 0.0;

        // Nessuna tabella millesimale collegata al conto.
        //
        // Fino alla beta.32 qui c'era un `return` con un Log::warning: l'importo
        // spariva dal piano rate in silenzio — nessun errore, nessuno scoperto,
        // solo una riga nel file di log. I condòmini venivano addebitati di meno
        // e nessuno se ne accorgeva.
        //
        // Quell'importo è per definizione SCOPERTO: non è assegnabile a nessuno.
        // Va quindi nello stesso bucket delle quote senza destinatario, che già
        // blocca la generazione e chiede all'amministratore una motivazione
        // scritta per procedere. Nessun meccanismo nuovo: quello giusto esisteva
        // già venti righe più in basso.
        if ($conto->tabelleMillesimali->isEmpty()) {
            Log::warning("distribuisciSuTabelle: conto ID={$conto->id} ('{$conto->nome}') non ha tabelle millesimali collegate. Importo {$importoConto} registrato come scoperto.");

            if ($importoConto != 0) {
                $this->scopertiAccumulati[] = [
                    'immobile_id'     => null,   // l'intero capitolo, non una singola unità
                    'conto_id'        => $conto->id,
                    'tabella_id'      => null,
                    'ruolo_richiesto' => null,
                    'importo'         => abs($importoConto),
                    'motivo'          => 'conto_senza_tabella',
                ];
            }

            return;
        }

        foreach ($conto->tabelleMillesimali as $ctm) {
            $tabella = $ctm->tabella ?? null;

            if (!$tabella) {
                Log::warning("distribuisciSuTabelle: conto_tabella_millesimale ID={$ctm->id} non ha una tabella collegata.", [
                    'conto_id' => $conto->id,
                ]);
                continue;
            }

            $coeff = (float) $ctm->coefficiente;
            if ($coeff <= 0) {
                Log::debug("distribuisciSuTabelle: coefficiente <= 0 per tabella ID={$tabella->id}, conto ID={$conto->id}. Saltata.");
                continue;
            }

            $weightCoeff = $coeff / 100.0;
            $quotaDichiarata += $weightCoeff;
            $quote = $tabella->quote;

            // Tabella collegata al capitolo ma inutilizzabile: nessun immobile assegnato,
            // oppure tutti i millesimi a zero. In entrambi i casi la sua fetta di spesa non
            // è ripartibile su nessuno.
            //
            // Fino alla beta.47 erano due `continue` con un Log::warning, e il danno
            // dipendeva da quante tabelle avesse il capitolo:
            //
            //  - tabella unica → il peso restava vuoto e :765 usciva con un `return` nudo:
            //    l'importo spariva dal piano;
            //  - più tabelle → il peso della tabella saltata non entrava né in $weights né
            //    in $pesiScoperti, quindi la rinormalizzazione finale (`$w / $pesoSoggetti`)
            //    faceva pagare la sua fetta ai partecipanti delle ALTRE tabelle. Peggio che
            //    perderla: la pagava chi non c'entrava.
            //
            // Registrandola fra i pesi scoperti si ottengono entrambe le cose giuste: la
            // generazione si ferma e chiede la motivazione, e se l'amministratore forza,
            // l'aritmetica esistente decurta la fetta invece di scaricarla sugli altri.
            $sommaValori = (float) $quote->sum('valore');
            $tabellaInutilizzabile = $quote->isEmpty() || $sommaValori <= 0.0;

            if ($tabellaInutilizzabile) {
                Log::warning("distribuisciSuTabelle: tabella ID={$tabella->id} ('{$tabella->nome}') non ha millesimi utilizzabili. Fetta del conto ID={$conto->id} registrata come scoperto.", [
                    'conto_nome'   => $conto->nome,
                    'tabella_nome' => $tabella->nome,
                    'num_quote'    => $quote->count(),
                    'somma_valori' => $sommaValori,
                ]);

                $pesiScoperti[] = [
                    'immobile_id'     => null,   // l'intera fetta della tabella
                    'tabella_id'      => $tabella->id,
                    'ruolo_richiesto' => null,
                    'peso'            => $weightCoeff,
                    'motivo'          => 'tabella_senza_millesimi',
                ];

                continue;
            }

            foreach ($quote as $quota) {
                $immobile = $quota->immobile ?? null;

                if (!$immobile) {
                    Log::debug("distribuisciSuTabelle: quota ID={$quota->id} in tabella ID={$tabella->id} non ha immobile associato. Saltata.");
                    continue;
                }

                // ⚠️ **NULL e zero non sono la stessa cosa, da questa beta.** Il valore assente
                // significa «non ancora compilato» e va detto; lo zero significa «non partecipa»
                // ed è legittimo. Sotto, l'aritmetica li tratta identici come ha sempre fatto:
                // qui si annota soltanto, senza toccare nessun peso.
                if ($quota->valore === null) {
                    Log::warning("distribuisciSuTabelle: immobile ID={$immobile->id} non ha ancora un millesimo nella tabella ID={$tabella->id} ('{$tabella->nome}'). La sua quota verrebbe ripartita fra le altre unità.", [
                        'conto_id'     => $conto->id,
                        'tabella_nome' => $tabella->nome,
                    ]);

                    $this->millesimiNonCompilati[] = [
                        'immobile_id' => $immobile->id,
                        'tabella_id'  => $tabella->id,
                        'conto_id'    => $conto->id,
                    ];
                    $this->registraZero($tabella, $immobile, null);

                    continue;
                }

                $valore = (float) $quota->valore;

                // [DIAG] Valore millesimale zero per immobile specifico
                if ($valore <= 0.0) {
                    Log::debug("distribuisciSuTabelle: immobile ID={$immobile->id} ha valore millesimale zero nella tabella ID={$tabella->id}. Saltato.");
                    $this->registraZero($tabella, $immobile, 0.0);
                    continue;
                }

                $weightImmobile = $weightCoeff * ($valore / $sommaValori);

                $ripartizioni = $ctm->ripartizioni->isNotEmpty()
                    ? $ctm->ripartizioni
                    : collect([(object) ['soggetto' => 'proprietario', 'percentuale' => 100.0]]);

                /*
                 * ## ⚠️ Anello 3 — la parte che le ripartizioni per ruolo non dichiarano
                 *
                 * **Chiuso nella beta.69, ed è la stessa forma dell'anello 1.** Le ripartizioni
                 * dicono quanta parte della quota dell'unità tocca al proprietario, quanta
                 * all'inquilino, quanta all'usufruttuario. Se sommano a meno di 100, una parte non
                 * è attribuita a nessun ruolo — e fino alla beta.68 veniva **assorbita** dalla
                 * rinormalizzazione finale e spalmata su chi c'era.
                 *
                 * Misurato con una sonda il 22/08/2026: due unità da 500 millesimi, spesa
                 * € 1.000,00, ripartizioni che dichiarano il **60%** → addebitati **€ 1.000,00**,
                 * cioè il 100%. Nessun controllo contabile aveva niente da segnalare, perché il
                 * totale del piano coincideva col preventivo.
                 *
                 * ⚠️ **La correzione sta qui e non solo nella porta che scriveva male.** Le porte
                 * che scrivono le ripartizioni sono quattro, tre avevano il controllo sulla somma e
                 * una no (`ContoController@update`, corretta anch'essa nella beta.69). Ma una porta
                 * nuova domani rifarebbe lo stesso buco, e i dati scritti prima di oggi restano.
                 * Il motore non deve mai far quadrare una base incompleta.
                 */
                $percentualeDichiarata = (float) $ripartizioni->sum(fn ($r) => max(0.0, (float) $r->percentuale));
                $puntiRipartizioneMancanti = round(100.0 - $percentualeDichiarata, 2);

                if ($puntiRipartizioneMancanti > self::TOLLERANZA_COEFFICIENTI_PUNTI) {
                    $pesoNonAttribuito = $weightImmobile * ($puntiRipartizioneMancanti / 100.0);

                    Log::warning("distribuisciSuTabelle: le ripartizioni per ruolo del conto ID={$conto->id} "
                        . "sulla tabella ID={$tabella->id} dichiarano solo il {$percentualeDichiarata}%. "
                        . "Il resto è registrato come scoperto.", [
                        'conto_id'    => $conto->id,
                        'tabella_id'  => $tabella->id,
                        'immobile_id' => $immobile->id,
                    ]);

                    $pesiScoperti[] = [
                        'immobile_id'     => $immobile->id,
                        'tabella_id'      => $tabella->id,
                        'ruolo_richiesto' => null,
                        'peso'            => $pesoNonAttribuito,
                        'motivo'          => 'ripartizioni_sotto_il_cento',
                    ];
                }

                foreach ($ripartizioni as $rip) {
                    $percent = (float) $rip->percentuale;
                    if ($percent <= 0.0) continue;

                    $weightRip = $weightImmobile * ($percent / 100.0);

                    /*
                     * ## ⚠️ Anello 4 — `quota > 0`, e non è un dettaglio (beta.69)
                     *
                     * Un intestatario con quota **zero** è registrato ma non paga: più sotto il
                     * ciclo lo salta con `if ($quotaAnag <= 0.0) continue`. Finché il filtro non
                     * lo escludeva **anche qui**, una riga a quota zero rendeva `$anagrafiche` non
                     * vuoto, quindi:
                     *
                     * - la **cascata** non scattava (il ruolo «c'è»),
                     * - il ramo dello **scoperto** nemmeno (la collezione non è vuota),
                     * - e il peso di quel ruolo **evaporava**, per poi essere ridistribuito dalla
                     *   rinormalizzazione finale — su **altre unità**.
                     *
                     * Misurato il 22/08/2026: due unità da 500 millesimi, spesa € 1.000,00,
                     * ripartizioni 50/50 fra proprietario e inquilino, e sull'unità 2 un inquilino
                     * a quota zero → **unità 1 € 666,67, unità 2 € 333,33**. Cioè € 166,67 spostati
                     * da un'unità all'altra, con il totale del piano perfettamente esatto.
                     *
                     * ⚠️ **La prova che ha deciso la forma della correzione** è il confronto con lo
                     * stesso caso senza nessun inquilino: là la cascata risolve sul proprietario e
                     * l'unità 2 paga € 500,00, che è il comportamento voluto. Le due situazioni
                     * sono la stessa cosa — nessuno che paghi quella metà — e devono dare lo stesso
                     * risultato. Da qui: **un ruolo le cui quote sono tutte a zero è un ruolo
                     * assente**, e prende la stessa strada.
                     *
                     * Il presidio è `tests/Feature/Riparto/CatenaProporzioniAnelli34Test.php`.
                     */
                    $anagrafiche = $this->titolari->attiviAlla($immobile->anagrafiche, $this->periodo)
                        ->where('pivot.tipologia', $rip->soggetto)
                        ->filter(fn ($a) => (float) $a->pivot->quota > 0.0);
                    $ruoloRisolto = (string) $rip->soggetto;

                    // Rule Engine Livello 3: Risoluzione a cascata del ruolo (catena per natura).
                    // La catena vive in RuoloAnagraficaImmobile::catenaRiparto() — unico posto
                    // in cui è scritta — e include il ruolo richiesto in testa, quindi qui si
                    // parte dal secondo. Il vecchio `&& $rip->soggetto !== 'proprietario'` è
                    // caduto con la beta.43: da quando `nuda_proprietario` è registrabile,
                    // anche un coefficiente sul proprietario ha un ripiego da cercare.
                    if ($anagrafiche->isEmpty()) {
                        // `catenaRipiego` e non `array_slice(catenaRiparto(), 1)`: su un soggetto
                        // fuori catalogo la catena non comincia con il ruolo richiesto, e tagliare
                        // la testa buttava via `proprietario` — il terminale che l'enum dichiara
                        // di garantire proprio per i dati sporchi. Vedi la nota sul metodo.
                        $candidati = RuoloAnagraficaImmobile::catenaRipiego($rip->soggetto);

                        foreach ($candidati as $ruoloFallback) {
                            // Stesso filtro sulla quota: un ripiego su un ruolo che non paga non
                            // è un ripiego, e la cascata deve poter proseguire fino al prossimo.
                            $anagrafiche = $this->titolari->attiviAlla($immobile->anagrafiche, $this->periodo)
                                ->where('pivot.tipologia', $ruoloFallback->value)
                                ->filter(fn ($a) => (float) $a->pivot->quota > 0.0);

                            if ($anagrafiche->isNotEmpty()) {
                                Log::debug("distribuisciSuTabelle: ruolo '{$rip->soggetto}' assente su immobile "
                                    . "ID={$immobile->id}, risolto a cascata su '{$ruoloFallback->value}'.");
                                $ruoloRisolto = $ruoloFallback->value;
                                break;
                            }
                        }
                    }

                    // B2, cancello (2): chi il periodo ha escluso si registra DOPO la cascata, sul ruolo che il
                    // periodo ha risolto, confrontandolo con chi la stessa cascata avrebbe trovato senza periodo.
                    // Registrarlo prima, sul ruolo richiesto, taceva il caso comune: spesa «inquilino» senza
                    // inquilino, cascata sul proprietario, e il proprietario uscito prima del periodo sparisce
                    // dai destinatari senza che nessuno lo dica (verifica indipendente S4, 19/09).
                    $this->registraEsclusiDopoCascata($immobile, (string) $rip->soggetto, $ruoloRisolto, $anagrafiche, (int) $conto->id);

                    // Tracciamento e bucket dello scoperto se cascata esaurita
                    if ($anagrafiche->isEmpty()) {
                        $pesiScoperti[] = [
                            'immobile_id'     => $immobile->id,
                            'tabella_id'      => $tabella->id,
                            'ruolo_richiesto' => $rip->soggetto,
                            'peso'            => $weightRip,
                        ];
                        Log::warning("distribuisciSuTabelle: cascata esaurita — nessun soggetto "
                            . "per ruolo '{$rip->soggetto}' su immobile ID={$immobile->id}. "
                            . "Peso {$weightRip} tracciato come scoperto.", [
                            'conto_id'    => $conto->id,
                            'tabella_id'  => $tabella->id,
                            'immobile_id' => $immobile->id,
                        ]);
                        continue;
                    }

                    $sommaQuote = (float) $anagrafiche->sum('pivot.quota');
                    if ($sommaQuote <= 0.0) $sommaQuote = 1.0;

                    /*
                     * B2 — il tempo entra nei pesi (D8). Se nel periodo del capitolo qualcuno di questa
                     * coppia (immobile, ruolo risolto) entra o esce, il peso di ogni titolare è
                     * `quota × giorni` normalizzato sulla somma della coppia. Se **nessuno cambia**,
                     * `$perGiorni` è nullo e l'espressione eseguita è **letteralmente** quella della
                     * beta.30, `quota / somma_quote`: è la garanzia d'identità in virgola mobile che
                     * l'invariante 1 pretende, e per questo è un `if` e non una formula unica con
                     * i giorni a 1.
                     */
                    $perGiorni = $this->pesiPerGiorni(
                        $this->titolari->attiviAlla($immobile->anagrafiche)->where('pivot.tipologia', $ruoloRisolto)->map(fn ($a) => $a->pivot)->values(),
                        $anagrafiche->map(fn ($a) => $a->pivot)->values(),
                        (int) $immobile->id, $ruoloRisolto, (int) $conto->id,
                    );
                    // Tutti a zero giorni sui tratti: il vuoto è l'intero periodo e passa per il ripiego qui sotto (L2-4).
                    // Decisione 22: la fetta dei giorni in cui nessuno della coppia è in vigore va all'anello successivo
                    // della cascata su quei giorni; ciò che neanche la cascata copre è scoperto, con il suo motivo.
                    $weightCoperto = $weightRip;
                    if ($perGiorni !== null && $perGiorni['giorni_scoperti'] > 0) {
                        $weightScoperto = $weightRip * $perGiorni['giorni_scoperti'] / $perGiorni['giorni_periodo'];
                        $weightCoperto = $weightRip - $weightScoperto;
                        $ripiego = $this->ripiegoGiorniScoperti(
                            $immobile->anagrafiche->map(fn ($a) => $a->pivot)->values(),
                            $ruoloRisolto, $perGiorni['scoperto'],
                        );
                        $weightResiduo = $weightRip * $ripiego['residuo_giorni'] / $perGiorni['giorni_periodo'];
                        $weightRipiego = $weightScoperto - $weightResiduo;
                        foreach ($ripiego['righe'] as $r) {
                            $w = $weightRipiego * $r['peso'] / $ripiego['somma'];
                            if ($w <= 0.0) continue;
                            $key = $r['anagrafica_id'] . '|' . $immobile->id;
                            $weights[$key] = ($weights[$key] ?? 0.0) + $w;
                            $componenti[$key][] = [
                                'tabella_id'       => (int) $tabella->id,
                                'tabella_nome'     => $tabella->nome,
                                'tabella_quota'    => self::etichettaQuota($tabella),
                                'coefficiente'     => $coeff,
                                'valore_millesimo' => $valore,
                                'somma_valori'     => $sommaValori,
                                'ruolo_richiesto'  => (string) $rip->soggetto,
                                'ruolo_risolto'    => $r['ruolo'],
                                'quota_possesso'   => (float) $r['riga']->quota,
                                'peso'             => $w,
                            ] + $this->congelatoTemporale($r['giorni'], $r['tratto']);
                        }
                        if ($weightResiduo > 0.0) {
                            $pesiScoperti[] = [
                                'immobile_id'     => $immobile->id,
                                'tabella_id'      => $tabella->id,
                                'ruolo_richiesto' => $rip->soggetto,
                                'peso'            => $weightResiduo,
                                // Nessun titolare del ruolo copre un giorno dei tratti e neanche la cascata: era il motivo di S4.
                                'motivo'          => $perGiorni['somma'] <= 0.0 && $ripiego['righe'] === [] ? 'titolari_fuori_competenza' : 'giorni_senza_titolare',
                                'giorni'          => $ripiego['residuo_giorni'],
                            ];
                        }
                        $this->annotaRipiego((int) $immobile->id, $ruoloRisolto, (int) $conto->id, $ripiego);
                    }

                    foreach ($anagrafiche as $anag) {
                        $quotaAnag = (float) $anag->pivot->quota;
                        if ($quotaAnag <= 0.0) continue;

                        $giorniRiga = null;
                        $trattoRiga = null;
                        if ($perGiorni === null) {
                            $weightAnagrafica = $weightRip * ($quotaAnag / $sommaQuote);
                        } else {
                            $rigaId = (int) $anag->pivot->id;
                            $giorniRiga = $perGiorni['giorni'][$rigaId] ?? 0;
                            $trattoRiga = $perGiorni['tratti'][$rigaId] ?? null;
                            $weightAnagrafica = $perGiorni['somma'] > 0.0 ? $weightCoperto * (($perGiorni['pesi'][$rigaId] ?? 0.0) / $perGiorni['somma']) : 0.0;
                            if ($weightAnagrafica <= 0.0) continue; // zero giorni: presente ma non paga questo tratto
                        }
                        $key = $anag->id . '|' . $immobile->id;
                        $weights[$key] = ($weights[$key] ?? 0.0) + $weightAnagrafica;

                        // Il componente, con tutto ciò che il peso sta per dimenticare (beta.29).
                        $componenti[$key][] = [
                            'tabella_id'       => (int) $tabella->id,
                            'tabella_nome'     => $tabella->nome,
                            'tabella_quota'    => self::etichettaQuota($tabella),
                            'coefficiente'     => $coeff,
                            'valore_millesimo' => $valore,
                            'somma_valori'     => $sommaValori,
                            'ruolo_richiesto'  => (string) $rip->soggetto,
                            'ruolo_risolto'    => $ruoloRisolto,
                            'quota_possesso'   => $quotaAnag,
                            'peso'             => $weightAnagrafica,
                        ] + $this->congelatoTemporale($giorniRiga, $trattoRiga);
                    }
                }
            }
        }

        /*
         * La parte di spesa che **nessuna tabella dichiara di coprire**.
         *
         * ## Il difetto che questa guardia esiste per prendere
         *
         * Una voce di spesa si collega alle tabelle con un coefficiente percentuale: è la forma
         * con cui il gestionale rappresenta le ripartizioni a quote fisse fra platee diverse, e la
         * materia condominiale ne è piena — un terzo e due terzi dell'art. 1126, metà e metà
         * dell'art. 1124, l'art. 1125.
         *
         * `AssociaTabellaController:58` blocca la somma **sopra** il 100. Sotto non guardava
         * nessuno, e la rinormalizzazione qui sotto (`$w / $pesoSoggetti`) porta comunque i pesi a
         * 1: qualunque cosa i coefficienti sommino, veniva distribuito il **100%** della spesa
         * sulle sole unità delle tabelle collegate.
         *
         * Misurato sul caso più naturale: rifacimento del lastrico da € 9.000, la sola tabella
         * «uso esclusivo» collegata al 33,33% perché la seconda si aggiunge dopo. Il titolare
         * riceveva **€ 9.000 invece di € 3.000** — tre volte — e nessun controllo contabile aveva
         * niente da segnalare, perché il totale del piano quadrava col preventivo.
         *
         * ⚠️ **Era una guardia scritta in un verso solo**: *cosa succede se dichiaro più del
         * 100%* era stato chiesto, *cosa succede se dichiaro meno* no. È la famiglia della
         * beta.41 e della beta.45.
         *
         * ## Perché uno scoperto e non un blocco
         *
         * Il canale esiste già e fa esattamente le due cose che servono: **decurta** l'importo da
         * distribuire e **ferma** la generazione chiedendo una motivazione scritta, lasciando
         * all'amministratore l'ultima parola. È lo stesso trattamento del capitolo senza nessuna
         * tabella (`conto_senza_tabella`, beta.32): quello è il caso allo 0%, questo è il caso
         * fra l'1% e il 99%. Nessun meccanismo nuovo.
         *
         * ## La tolleranza, e perché non è zero
         *
         * `conto_tabella_millesimale.coefficiente` è `decimal(5,2)`: tre platee in parti uguali
         * sommano **99,99** e non c'è modo di scriverlo meglio. Una guardia severa segnalerebbe
         * ogni ripartizione in terzi, cioè griderebbe al lupo — la lezione della beta.60: *una
         * guardia che grida troppo si spegne, ed è peggio di una che non c'è*.
         *
         * La soglia è scelta sulla precisione della colonna, non a occhio: con due decimali, N
         * platee in parti uguali perdono al massimo N × 0,005 punti, quindi **0,05 punti**
         * coprono fino a dieci tabelle sullo stesso capitolo. Sopra quella soglia non è
         * arrotondamento: è una tabella che manca.
         *
         * ⚠️ **Il confronto si fa in punti percentuali arrotondati a due decimali, non in
         * frazione.** `$quotaDichiarata` è una somma di divisioni per 100 e porta con sé il
         * rumore: a esattamente 99,95 dichiarati, `1.0 - $quotaDichiarata` vale
         * `0.0005000000000000004` e supererebbe la soglia — cioè il caso limite che la costante
         * dichiara **accettabile** verrebbe segnalato, per una cifra binaria e non per una
         * decisione. Arrotondare alla precisione della colonna toglie di mezzo la questione.
         */
        $puntiMancanti = round(100.0 - ($quotaDichiarata * 100.0), 2);
        $nonDichiarato = 1.0 - $quotaDichiarata;

        if ($puntiMancanti > self::TOLLERANZA_COEFFICIENTI_PUNTI) {
            Log::warning("distribuisciSuTabelle: i coefficienti del conto ID={$conto->id} ('{$conto->nome}') dichiarano solo il ".round($quotaDichiarata * 100, 2)."% della spesa. Il resto è registrato come scoperto.", [
                'conto_id'          => $conto->id,
                'quota_dichiarata'  => $quotaDichiarata,
                'non_dichiarato'    => $nonDichiarato,
            ]);

            $pesiScoperti[] = [
                'immobile_id'     => null,   // non è di nessuna unità: è la fetta che nessuno copre
                'tabella_id'      => null,   // e non è di nessuna tabella: sono quelle che mancano
                'ruolo_richiesto' => null,
                'peso'            => $nonDichiarato,
                'motivo'          => 'coefficienti_sotto_il_cento',
            ];
        }

        // Pesi finali vuoti: nessuna quota sarà generata per questo conto (se neanche pesiScoperti è popolato)
        if (empty($weights) && empty($pesiScoperti)) {
            Log::warning("distribuisciSuTabelle: nessun peso calcolato per conto ID={$conto->id} ('{$conto->nome}'). Importo {$importoConto} centesimi NON distribuito. Causa probabile: tabelle millesimali vuote o anagrafiche mancanti.", [
                'conto_id'    => $conto->id,
                'conto_nome'  => $conto->nome,
                'importo'     => $importoConto,
            ]);
            return;
        }

        $pesoSoggetti = array_sum($weights);
        $pesoScopertoTotale = array_sum(array_column($pesiScoperti, 'peso'));
        
        $pesoTotaleInclScoperto = $pesoSoggetti + $pesoScopertoTotale;
        
        if ($pesoTotaleInclScoperto <= 0.0) return;

        // Tracciatura degli importi scoperti
        if (!empty($pesiScoperti)) {
            foreach ($pesiScoperti as $ps) {
                $importoScoperto = (int) round(abs($importoConto) * ($ps['peso'] / $pesoTotaleInclScoperto));
                if ($importoScoperto > 0) {
                    $this->scopertiAccumulati[] = [
                        'immobile_id'     => $ps['immobile_id'],
                        'conto_id'        => $conto->id,
                        'tabella_id'      => $ps['tabella_id'],
                        'ruolo_richiesto' => $ps['ruolo_richiesto'],
                        'importo'         => $importoScoperto,
                        // La quota orfana storica non ha motivo e continua a non averlo:
                        // è il caso originale della v1.9.1, e cambiarlo qui cambierebbe
                        // il significato delle righe già in archivio.
                        'motivo'          => $ps['motivo'] ?? null,
                        // Decisione 22: i giorni senza nessun titolare, quando è quello il motivo.
                        'giorni'          => $ps['giorni'] ?? null,
                    ];
                }
            }
        }

        if (empty($weights)) return; // Se tutto è scoperto, non procediamo con la distribuzione penny-perfect

        // Peso normalizzato solo sui soggetti reali; l'importo da distribuire è già al netto dello scoperto,
        // garantendo che la somma dei pesi dia un risultato congruo con l'importo decurtato.
        
        $importoDaDistribuirePennyPerfect = abs($importoConto);
        if ($pesoScopertoTotale > 0.0) {
            $totaleScopertoInt = (int) round(abs($importoConto) * ($pesoScopertoTotale / $pesoTotaleInclScoperto));
            $importoDaDistribuirePennyPerfect = abs($importoConto) - $totaleScopertoInt;
        }

        $importoContoSegno = $importoConto < 0 ? -$importoDaDistribuirePennyPerfect : $importoDaDistribuirePennyPerfect;

        // ─── Il punto in cui «quanto va su questo conto» è deciso (beta.49, coda ⑩) ────────
        //
        // Fino alla 1.11.0-beta.28 `RipartoTabelleService` leggeva questo registro invece di
        // ricostruirselo da sé da `righe_fattura` — venti righe che erano un rimpiazzo ingenuo di
        // `calcolaDaFattureStraordinarie()` e sbagliavano quattro cose: prendevano anche le righe
        // ordinarie, ignoravano `importo_collegato`, non conoscevano le coperture delle pregresse
        // (documento bianco) e spalmavano su tutti gli addebiti ad personam. Dalla .29 le stampe
        // leggono le righe del dettaglio, e il registro resta la loro somma di controllo.
        //
        // ⚠️ **Il punto è qui e non prima della decurtazione**, ed è la correzione che la
        // revisione avversariale ha imposto al primo progetto: registrando `$importoConto` grezzo
        // la stampa avrebbe dovuto rifare per conto suo il calcolo degli scoperti — cioè
        // mantenere allineata una seconda copia, che è esattamente il difetto da cui nasce questa
        // voce. `$importoContoSegno` è già al netto: la stampa può cancellare la sua.
        if ($importoContoSegno !== 0) {
            $this->importiRipartiti[$conto->id] =
                ($this->importiRipartiti[$conto->id] ?? 0) + $importoContoSegno;
        }

        foreach ($weights as $key => $w) {
            $weights[$key] = $w / $pesoSoggetti; // Qui normalizziamo a 1 per il penny-perfect sull'importo decurtato
        }

        $importiDistributi = MoneyHelper::distribuisciPesiNormalizzati($weights, $importoContoSegno);

        // ─── Il dettaglio (beta.29): l'intero di ogni chiave, spaccato sui suoi componenti ─────
        //
        // Qui l'intero del Hare per (anagrafica, immobile) è deciso ed è quello che finirà nei
        // totali, al lordo del netting. Con un componente solo la riga vale l'intero; con più
        // componenti (conto su due tabelle, ripartizione per ruolo, cascata sulla stessa persona)
        // un secondo Hare sui pesi dei componenti spacca l'intero senza crearne né perderne un
        // centesimo: Σ righe = intero. La stampa per tabella fino alla 1.11.0-beta.28 lo faceva
        // dal vivo sui pesi **aggregati per tabella**: qui è per componente, quindi quando una
        // tabella porta più componenti sulla stessa chiave (una ripartizione per ruolo risolta a
        // cascata sulla stessa persona) e il conto ha più tabelle, la spaccatura fra le colonne
        // può differire di un centesimo dalla .28 — a totali per soggetto e per conto invariati,
        // anche nella ristampa «ricostruita» di un piano vecchio.
        $radice = $this->radiceDi($conto);
        foreach ($importiDistributi as $key => $importoChiave) {
            [$aid, $iid] = array_map('intval', explode('|', $key));
            $comp = $componenti[$key] ?? [];
            if ($comp === []) continue;

            if (count($comp) === 1) {
                $importiComponenti = [0 => $importoChiave];
            } else {
                $pesi = array_column($comp, 'peso');
                $sommaPesi = array_sum($pesi);
                $pesiNormalizzati = [];
                foreach ($pesi as $i => $peso) {
                    $pesiNormalizzati[$i] = $sommaPesi > 0 ? $peso / $sommaPesi : 1 / count($pesi);
                }
                $importiComponenti = MoneyHelper::distribuisciPesiNormalizzati($pesiNormalizzati, $importoChiave);
            }

            foreach ($comp as $i => $c) {
                unset($c['peso']);
                $this->righeDettaglio[] = $this->rigaDettaglio('riparto', $c + [
                    'anagrafica_id'     => $aid,
                    'immobile_id'       => $iid,
                    'conto_id'          => $conto->id,
                    'conto_nome'        => $conto->nome,
                    'conto_radice_id'   => $radice->id,
                    'conto_radice_nome' => $radice->nome,
                    'importo'           => (int) ($importiComponenti[$i] ?? 0),
                ]);
            }
        }

        // Sottrae quanto ciascuna unità ha GIÀ versato per questa voce: senza questo
        // passaggio una spesa già coperta in tutto o in parte verrebbe richiesta una
        // seconda volta (vedi docs/fondo_accantonato_e_quadratura_sp.md §4).
        /*
         * ⚠️ **Il fattore di copertura, e perché il netting non può ignorarlo.**
         *
         * `nettingGiaVersato()` applica la copertura storica in proporzione a quanto questa
         * chiamata rappresenta del budget del capitolo — serve per l'acconto e il saldo, che sono
         * due chiamate indipendenti sullo stesso conto. Ma il confronto lo faceva contro
         * `$conto->importo` **nominale**, mentre l'importo distribuito è già decurtato dagli
         * scoperti: la copertura veniva quindi scomputata solo per la frazione ripartita, e la
         * differenza **richiesta di nuovo a chi l'aveva già versata**.
         *
         * Uno scoperto non è una tranche che arriverà dopo: è una parte che non verrà chiesta a
         * nessuno, mai. Il denominatore giusto è quindi il budget *coperibile*, non quello
         * nominale. Senza scoperti il fattore vale 1 e non cambia niente.
         */
        $fattoreCopertura = abs($importoConto) > 0
            ? $importoDaDistribuirePennyPerfect / abs($importoConto)
            : 1.0;

        $importiDistributi = $this->nettingGiaVersato($conto, $importiDistributi, $fattoreCopertura);

        // La riga del già versato: negativa, per conto e soggetto — la terza chiave che
        // `getNettingApplicato()` (per immobile) non ha: il già versato è una riga negativa per
        // (conto, soggetto), non una colonna sulla riga di riparto (1.11.0-beta.29).
        // Due righe quando il versato ha due nature (decisione 17): quella **della persona** (la riga di
        // `contributi_versati` con la sua anagrafica: è un suo pagamento, in un conguaglio resta suo) e quella
        // **dell'unità** (senza persona: abbassa la spesa dell'unità, D8). Si distinguono dalla descrizione,
        // che è ciò che `ConguaglioPassaggio::scomponiPerConto` rilegge; i lettori che sommano le righe
        // `netting` (stampe, invarianti) non cambiano.
        foreach ($this->ultimoNettingPerChiave as $key => $assorbitoChiave) {
            if ($assorbitoChiave <= 0) continue;
            [$aid, $iid] = array_map('intval', explode('|', $key));
            $persona = min($assorbitoChiave, (int) ($this->ultimoNettingPersonaPerChiave[$key] ?? 0));
            foreach ([[$persona, self::NETTING_DELLA_PERSONA], [$assorbitoChiave - $persona, self::NETTING_DELL_UNITA]] as [$importo, $descrizione]) {
                if ($importo <= 0) continue;
                $this->righeDettaglio[] = $this->rigaDettaglio('netting', [
                    'anagrafica_id'     => $aid,
                    'immobile_id'       => $iid,
                    'conto_id'          => $conto->id,
                    'conto_nome'        => $conto->nome,
                    'conto_radice_id'   => $radice->id,
                    'conto_radice_nome' => $radice->nome,
                    'importo'           => -$importo,
                    'riga_descrizione'  => $descrizione,
                ]);
            }
        }

        foreach ($importiDistributi as $key => $importoCentesimi) {
            [$aid, $iid] = array_map('intval', explode('|', $key));

            if (!isset($totali[$aid])) $totali[$aid] = [];
            if (!isset($totali[$aid][$iid])) $totali[$aid][$iid] = 0;

            $totali[$aid][$iid] += $importoCentesimi;
        }
    }

    /**
     * Netting del già-versato: da ogni quota lorda sottrae la copertura già versata verso questa voce
     * di spesa.
     *
     * **Per persona dove si sa chi ha versato, per unità altrove** (decisione 17 del progetto sul
     * subentro, 1.11.0-beta.31). Fino alla beta.30 la copertura era sempre dell'unità: dopo una vendita
     * lo sconto passava all'acquirente e il venditore che aveva anticipato i lavori restava a mani
     * vuote. Ora una riga con `anagrafica_id` sconta **la quota di quella persona**, con il tetto del suo
     * lordo; quel che avanza — o l'intero versato, se quella persona non è più fra i destinatari — è
     * un'eccedenza a suo nome (`getEccedenzeCopertura()`), da restituire o conguagliare, non uno sconto
     * a chi le è subentrato. Le righe senza `anagrafica_id` (quelle storiche, scritte per unità) si
     * comportano come prima: ripartite fra i comproprietari in proporzione alle quote lorde,
     * penny-perfect.
     *
     * La quota netta non scende mai sotto zero: l'eventuale eccedenza — l'unità ha
     * versato più di quanto le spetta — non viene inghiottita in silenzio ma
     * accumulata e resa leggibile da `getEccedenzeCopertura()`, perché è denaro dei
     * condòmini che va restituito o conguagliato.
     *
     * @param  array<string,int>  $importiDistributi  mappa "anagraficaId|immobileId" => centesimi lordi
     * @return array<string,int>  la stessa mappa, al netto delle coperture
     */
    private function nettingGiaVersato(Conto $conto, array $importiDistributi, float $fattoreCopertura = 1.0): array
    {
        $this->ultimoNettingPerChiave = [];
        $this->ultimoNettingPersonaPerChiave = [];
        $copertureDettagliate = ContributoVersato::perImmobileESoggetto(Conto::class, $conto->id);
        // La lettura per unità (somma di tutte le righe, con e senza persona) resta la base dei log e
        // del caso storico; la parte per persona si applica prima, qui sotto.
        $coperture = collect($copertureDettagliate)->map(fn (array $parti) => array_sum($parti));

        if ($coperture->isEmpty()) {
            return $importiDistributi;
        }

        // D8 (docs/fondo_accantonato_e_quadratura_sp.md): la copertura SENZA persona è
        // dell'IMMOBILE. Su un conto con ripartizione mista (proprietario/inquilino)
        // viene sottratta dal lordo aggregato dell'unità PRIMA che questo venga spaccato
        // fra i soggetti — un versamento «dell'unità» sconta anche l'inquilino. Dalla
        // beta.31 (decisione 17) la riga con `anagrafica_id` sconta solo il lordo di quella
        // persona: l'avviso qui sotto riguarda le sole righe senza persona. Non bloccante:
        // la UI (ContributiEdit.vue, «Versato da») lo dice in fase di inserimento.
        $haRipartizioneMista = $conto->tabelleMillesimali->contains(function ($ctm) {
            $rip = $ctm->ripartizioni;
            return $rip->isNotEmpty() && !($rip->count() === 1
                && $rip->first()->soggetto === 'proprietario'
                && (float) $rip->first()->percentuale === 100.0);
        });
        if ($haRipartizioneMista) {
            Log::warning("nettingGiaVersato: conto con ripartizione per soggetto (proprietario/inquilino) e copertura già-versato registrata — la copertura è per immobile e viene sottratta dal lordo aggregato prima della spaccatura per soggetto (D8).", [
                'conto_id' => $conto->id,
            ]);
        }

        // QUOTA di questa chiamata sul budget nominale del conto.
        //
        // Un capitolo può essere finanziato da PIÙ chiamate indipendenti: due
        // piani rate sullo stesso conto (acconto + saldo), o più fatture
        // straordinarie collegate allo stesso conto imprevisto. La copertura
        // storica in `contributi_versati` è il totale versato per l'INTERA voce,
        // non per questa singola chiamata: applicarla per intero ad ogni chiamata
        // la sottrarrebbe più volte, lasciando un residuo mai richiesto a
        // nessuno — vedi BucoBGiaVersatoDoppioPianoRateTest. Qui se ne applica
        // solo la quota proporzionale a quanto QUESTA chiamata rappresenta del
        // budget totale (conto->importo). Con una sola chiamata (il caso comune,
        // nessuna rateizzazione in più tranche) la quota è 1 e il comportamento
        // resta identico a prima di questa correzione.
        //
        // floor(), mai round(): un pareggio fra chiamate indipendenti nel tempo
        // (l'acconto oggi, il saldo fra un mese) non può ridistribuire un resto
        // come fa distribuisciImporto() dentro la STESSA chiamata. floor() sbaglia
        // sempre per difetto sulla copertura applicata: nel peggiore dei casi si
        // richiede qualche centesimo IN PIÙ del dovuto, mai in meno.
        //
        // ⚠️ **Il nominale è scalato dal fattore di copertura (beta.63).** Quando una parte del
        // capitolo resta scoperta, l'importo distribuito è decurtato ma il budget del conto no:
        // il rapporto scendeva sotto 1 anche in assenza di altre tranche, e la copertura storica
        // veniva scomputata solo in parte. Vedi `GiaVersatoSottoDecurtazioneTest`.
        // B2: mai sotto il totale che il piano straordinario distribuisce su questo conto (più chiamate,
        // una per competenza): con `conti.importo` a zero la quota resta 1 solo se la chiamata è una.
        $totaleNominale = max((int) round(abs((int) $conto->importo) * $fattoreCopertura), $this->nominaleMinimoPerConto[$conto->id] ?? 0);
        $totaleQuestaChiamata = abs(array_sum($importiDistributi));
        $quota = ($totaleNominale > 0 && $totaleQuestaChiamata < $totaleNominale)
            ? $totaleQuestaChiamata / $totaleNominale
            : 1.0;

        // Raggruppa le righe per immobile: la copertura è dell'unità, non del soggetto.
        $righePerImmobile = [];
        foreach ($importiDistributi as $key => $importo) {
            [, $iid] = array_map('intval', explode('|', $key));
            $righePerImmobile[$iid][$key] = $importo;
        }

        // Copertura orfana: un'unità con un già-versato registrato ma che non
        // compare fra le righe distribuite (tipicamente perché la cascata di
        // risoluzione soggetto/ruolo non trova nessuna anagrafica attiva e
        // l'unità finisce nel bucket "scoperto"). La copertura non viene persa —
        // resta nel ledger per la prossima volta — ma qui non ha nulla da
        // scontare: se ne resta traccia solo nei log, senza bloccare nulla.
        foreach ($coperture as $immobileId => $importo) {
            if ($importo > 0 && !isset($righePerImmobile[$immobileId])) {
                Log::warning("nettingGiaVersato: copertura registrata su un'unità che non compare fra le righe distribuite (probabile unità scoperta, senza anagrafiche attive).", [
                    'conto_id'      => $conto->id,
                    'immobile_id'   => $immobileId,
                    'importo_cents' => $importo,
                ]);
            }
        }

        foreach ($righePerImmobile as $immobileId => $righe) {
            $parti = $copertureDettagliate[$immobileId] ?? [];
            if (array_sum($parti) <= 0) {
                continue;
            }

            // Su una quota negativa (nota di credito) il netting non si applica.
            if (array_sum($righe) <= 0) {
                continue;
            }

            // ── Decisione 17: prima la parte **di ciascuna persona**, sulla sua sola riga ──────────────
            foreach ($parti as $chiavePersona => $versatoPersona) {
                if ($chiavePersona === '' || $versatoPersona <= 0) {
                    continue;
                }
                $aid = (int) $chiavePersona;
                $key = "{$aid}|{$immobileId}";
                $coperturaPersona = $quota >= 1.0 ? (int) $versatoPersona : (int) floor($versatoPersona * $quota);
                $lordoPersona = (int) ($righe[$key] ?? 0);
                $applicataPersona = min($coperturaPersona, max(0, $lordoPersona));

                if ($applicataPersona > 0) {
                    $righe[$key] = $lordoPersona - $applicataPersona;
                    $importiDistributi[$key] = $righe[$key];
                    $this->ultimoNettingPerChiave[$key] = ($this->ultimoNettingPerChiave[$key] ?? 0) + $applicataPersona;
                    $this->ultimoNettingPersonaPerChiave[$key] = ($this->ultimoNettingPersonaPerChiave[$key] ?? 0) + $applicataPersona;
                    $this->nettingApplicato[$conto->id][$immobileId] = ($this->nettingApplicato[$conto->id][$immobileId] ?? 0) + $applicataPersona;
                }
                if ($coperturaPersona > $applicataPersona) {
                    // Ha versato più della sua quota, o non è più fra i destinatari (ha venduto prima
                    // della delibera): il resto è suo, non di chi gli è subentrato.
                    $this->eccedenzeCopertura[] = [
                        'immobile_id'   => $immobileId,
                        'anagrafica_id' => $aid,
                        'conto_id'      => $conto->id,
                        'versato'       => $coperturaPersona,
                        'dovuto'        => max(0, $lordoPersona),
                        'eccedenza'     => $coperturaPersona - $applicataPersona,
                    ];
                }
            }

            // ── Poi la parte **dell'unità** (righe senza persona), come prima: pro-lordo fra i comproprietari ──
            $coperturaStorica = (int) ($parti[''] ?? 0);
            if ($coperturaStorica <= 0) {
                continue;
            }

            $lordoImmobile = array_sum($righe);
            if ($lordoImmobile <= 0) {
                if ($quota >= 1.0 ? $coperturaStorica : (int) floor($coperturaStorica * $quota)) {
                    $this->eccedenzeCopertura[] = [
                        'immobile_id' => $immobileId,
                        'conto_id'    => $conto->id,
                        'versato'     => $quota >= 1.0 ? $coperturaStorica : (int) floor($coperturaStorica * $quota),
                        'dovuto'      => 0,
                        'eccedenza'   => $quota >= 1.0 ? $coperturaStorica : (int) floor($coperturaStorica * $quota),
                    ];
                }
                continue;
            }

            $copertura = $quota >= 1.0 ? $coperturaStorica : (int) floor($coperturaStorica * $quota);
            $applicata = min($copertura, $lordoImmobile);

            if ($copertura > $lordoImmobile) {
                $this->eccedenzeCopertura[] = [
                    'immobile_id' => $immobileId,
                    'conto_id'    => $conto->id,
                    'versato'     => $copertura,
                    'dovuto'      => $lordoImmobile,
                    'eccedenza'   => $copertura - $lordoImmobile,
                ];
            }

            // Ripartisce la copertura tra i comproprietari in proporzione al lordo.
            //
            // Scaricare il resto per intero sull'ULTIMA riga (come prima di questa
            // correzione) può assegnarle più copertura di quanta ne possa assorbire
            // il suo stesso lordo, se le quote sono molto sbilanciate: es. lordo
            // immobile 10.000, comproprietari 1%/1%/98% (100/100/9.800), copertura
            // applicata 9.999 — al comproprietario che finisce per ultimo in
            // iterazione, col vecchio schema, tocca 9.999 − 9.898 = 101, contro un
            // suo lordo di soli 100: 1 centesimo di copertura "evapora" nel clamp
            // max(0, ...) invece di finire su un altro comproprietario che ha
            // ancora capienza. Qui ogni riga riceve al più il proprio lordo, e il
            // resto (mai negativo: $applicata ≤ $lordoImmobile per costruzione) va
            // — un centesimo alla volta — a chi ha ancora capienza, iniziando da
            // chi ha il resto frazionario più alto (stesso principio di
            // distribuisciImporto(), qui vincolato dalla capienza di ogni riga).
            $quote = [];
            $assegnato = 0;
            foreach ($righe as $key => $lordoRiga) {
                $base = (int) floor($applicata * $lordoRiga / $lordoImmobile);
                $quote[$key] = min($base, $lordoRiga);
                $assegnato += $quote[$key];
            }

            $resto = $applicata - $assegnato;
            if ($resto > 0) {
                $chiaviOrdinate = array_keys($righe);
                usort($chiaviOrdinate, function ($a, $b) use ($righe, $applicata, $lordoImmobile) {
                    $fracA = fmod($applicata * $righe[$a] / $lordoImmobile, 1);
                    $fracB = fmod($applicata * $righe[$b] / $lordoImmobile, 1);
                    return $fracB <=> $fracA;
                });

                foreach ($chiaviOrdinate as $key) {
                    if ($resto <= 0) break;
                    $capienza = $righe[$key] - $quote[$key];
                    if ($capienza <= 0) continue;
                    $incremento = min($capienza, $resto);
                    $quote[$key] += $incremento;
                    $resto -= $incremento;
                }
            }

            // Il registro per immobile: quanto è stato assorbito da questa unità su questo
            // capitolo. Si somma invece di assegnare, perché un capitolo può essere finanziato
            // da più chiamate indipendenti (acconto e saldo) e ciascuna applica la sua quota.
            $assorbito = 0;
            foreach ($righe as $key => $lordoRiga) {
                $importiDistributi[$key] = max(0, $lordoRiga - $quote[$key]);
                $assorbito += min($quote[$key], $lordoRiga);
                // Per chiave, non solo per immobile: è ciò che il dettaglio del riparto registra.
                $this->ultimoNettingPerChiave[$key] = ($this->ultimoNettingPerChiave[$key] ?? 0) + min($quote[$key], $lordoRiga);
            }
            if ($assorbito > 0) {
                $this->nettingApplicato[$conto->id][$immobileId] =
                    ($this->nettingApplicato[$conto->id][$immobileId] ?? 0) + $assorbito;
            }
        }

        return $importiDistributi;
    }

    /**
     * Unità che hanno versato PIÙ di quanto la spesa richiedeva loro.
     *
     * @return array<int,array{immobile_id:int,conto_id:int,versato:int,dovuto:int,eccedenza:int}>
     */
    public function getEccedenzeCopertura(): array
    {
        return $this->eccedenzeCopertura;
    }

    /**
     * Il netting del già-versato davvero applicato: `conto_id => [immobile_id => centesimi]`.
     *
     * Serve a chi stampa. La regola che questo motore si è dato — scritta nel docblock di
     * `getImportiPerConto()` e rimasta senza codice per ventisette beta — è che **la colonna di un
     * capitolo continua a valere il budget deliberato**, mentre lo sconto a un'unità che aveva già
     * versato è una grandezza per immobile e vive in una colonna sua. Questo accessore è quella
     * colonna.
     *
     * Vuoto finché non si è chiamato `calcolaPerGestione()` o `calcolaDaFattureStraordinarie()`,
     * e vuoto anche dopo se nessuna unità aveva coperture registrate.
     *
     * @return array<int,array<int,int>>
     */
    public function getNettingApplicato(): array
    {
        return $this->nettingApplicato;
    }

}
