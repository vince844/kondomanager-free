<?php

namespace App\Services\Gestionale;

use App\Http\Controllers\Gestionale\Movimenti\RegistroContabilitaController;
use App\Http\Controllers\Gestionale\Movimenti\RendicontoCondominiController;
use App\Http\Controllers\Gestionale\Movimenti\RendicontoConguaglioController;
use App\Http\Controllers\Gestionale\Movimenti\RiepilogoPerCompetenzaController;
use App\Http\Controllers\Gestionale\Movimenti\StatoPatrimonialeController;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Services\PDF\MpdfConCarta;
use App\Services\PDF\PdfService;
use App\Settings\PrintSettings;
use App\Traits\PdfRigheStampabili;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use App\Services\PDF\GruppoCarta;

/**
 * Il fascicolo del rendiconto per l'assemblea: un PDF solo con il frontespizio (il condominio, l'esercizio, la data della stampa, i numeri del prospetto
 * della gestione, le quote non pagate, i fondi, l'esito dei controlli, l'indice e lo spazio per la firma) e le parti scelte, **sempre nell'ordine fisso**
 * qualunque sia l'ordine delle spunte: riepilogo, situazione, cassa, condòmini, conguaglio, registro, controlli. È l'ordine che il programma propone (un fascicolo che
 * cambia ordine a ogni stampa non si confronta con quello dell'anno prima): chi vuole un altro ordine stampa le parti una per una.
 *
 * IL TEMPO E LA MEMORIA DEL TOTALE. Prima di cominciare, il fascicolo conta le righe delle parti scelte e stima il tempo e la memoria della stampa intera
 * con le misure delle stampe: il tempo è una base più un costo per riga di ogni parte, la memoria la base più grande più il costo per riga di ogni parte
 * (le parti si scrivono una alla volta, ma la memoria di PHP non torna tutta indietro fra una parte e l'altra: misurate da sole il registro al tetto e
 * i crediti per anzianità a 12.000 righe stavano a 188 e 189,5 MB, insieme a 218,5 MB; con 14.484 righe a 238,5 MB, il 93 % di 256M). Oltre il tetto
 * di tempo o di memoria il fascicolo non parte, e dice quale parte togliere: ogni parte si scarica da sola dalla sua scheda.
 *
 * IL NUMERO DEL FASCICOLO. Ogni pagina, il frontespizio compreso, ha nel piede il nome della parte con il suo numero di pagina e sotto «Pagina 5 di 13 del
 * fascicolo»: la seconda riga la scrive l'unione, nel posto che il piede delle parti lascia libero quando la stampa è nel fascicolo (Coda 573).
 *
 * Le parti si scrivono in file temporanei e si uniscono con FPDI (la libreria che mPDF porta con sé). I file temporanei stanno nella cache del framework,
 * mai nell'archivio dei documenti; si cancellano alla fine, anche quando qualcosa si rompe, e anche quando PHP si ferma con un errore fatale (la funzione
 * di chiusura gira lo stesso). Se il processo viene ucciso da fuori, la cartella resta: all'inizio di ogni fascicolo si tolgono quelle più vecchie di un'ora.
 */
final class FascicoloDelRendiconto
{
    use PdfRigheStampabili;

    /** Le parti, nell'ordine fisso. */
    public const PARTI = ['riepilogo', 'situazione', 'cassa', 'condomini', 'conguaglio', 'registro', 'controlli'];

    /** I nomi delle parti, come li dice la frase quando una va tolta. */
    public const NOMI = ['riepilogo' => 'Riepilogo finanziario', 'situazione' => 'Situazione patrimoniale', 'cassa' => 'Entrate e uscite per cassa',
        'condomini' => 'Condòmini: posizioni e crediti per anzianità', 'conguaglio' => 'Riparto del consuntivo e conguaglio', 'registro' => 'Registro di contabilità',
        'controlli' => 'Controlli'];

    /**
     * Il tempo, misurato l'08/11/2026 su un Apple M1 Pro con richieste vere alle rotte delle stampe e del fascicolo: il registro 13.295
     * righe 35,8 s; i crediti per anzianità 1.000 righe 5,0 s, 6.000 26,9 s, 12.000 53,6 s; le posizioni 1.000 righe 2,1 s, 5.000 10,1 s; il riepilogo con
     * il dettaglio 1.001 righe 2,5 s, 12.001 25,7 s; la situazione 1.000 fatture aperte 2,0 s, 6.000 10,1 s. Il fascicolo intero: 94,2 s con il registro a
     * 13.295 righe e i crediti a 12.000; 110,9 s con i crediti a 14.484. Da cui 5 s di base (il frontespizio, l'unione, le parti piccole) e i costi per
     * riga qui sotto, presi dal lato alto: la stima dà 99,5 s e 111,4 s nei due casi misurati.
     */
    public const SECONDI_BASE = 5.0;
    public const SECONDI_PER_RIGA = ['riepilogo' => 0.0022, 'situazione' => 0.0017, 'posizioni' => 0.0021, 'crediti' => 0.0046, 'registro' => 0.0028,
        'conguaglio_persona' => RendicontoConguaglioController::SECONDI_PER_RIGA_PER_PERSONA,
        // Il consuntivo per tabella si conta in celle (le righe per le tabelle della loro gestione, {@see RendicontoConguaglioController::celleDellaRiga()}).
        'conguaglio_tabella' => RendicontoConguaglioController::SECONDI_PER_CELLA_PER_TABELLA,
        // La lettura del conguaglio, per unità di peso (unità × gruppi di pesi): 14,9 s a 90.000, la misura della scheda con una tabella per voce.
        'conguaglio_lettura' => 0.00017];

    /**
     * Il margine del tempo: la stima deve stare nella metà del limite di PHP. Le misure sono su un M1 Pro; un processore di un server condiviso può
     * essere più lento anche della metà, e il limite di PHP conta il tempo del processore. Il limite è quello del processo (`limiteTempoDelProcesso`):
     * 120 s dove è più basso e PHP concede di alzarlo, come fa `PdfService` alla prima stampa, o dove è assente; in produzione nginx aspetta 130 s.
     */
    public const MARGINE_TEMPO = 0.5;

    /**
     * La base della memoria del totale: la più grande fra le basi delle stampe delle parti, quella del registro (78 MB). Misurato l'08/11/2026 con richieste
     * vere: il fascicolo con il registro al tetto della sua stampa (13.295 righe) e la situazione, la cassa e i controlli, picco 190 MB (37 s); il registro
     * da solo nel fascicolo 188 MB; la stima, 78 + 139,6 = 217,6 MB.
     */
    public const MB_BASE = RegistroContabilitaController::BASE_MB_STAMPA;

    /** Il limite di tempo che vale per il fascicolo, in secondi; nullo, quello del processo. Una prova lo fissa. */
    private static ?int $limiteTempo = null;

    /** Il `memory_limit` che vale per il tetto; nullo, quello del processo. Una prova lo fissa: il processo non si abbassa sotto quello che usa. */
    private static ?string $limiteMemoria = null;

    public function __construct(
        private readonly ControlliDelRendiconto $controlli,
        private readonly SintesiDelRendiconto $sintesi,
        private readonly PosizioniDeiCondomini $posizioni,
        private readonly RegistroContabilitaService $registro,
        private readonly PdfService $pdf,
        private readonly ConguaglioDelRendiconto $conguaglio,
    ) {}

    /** La cartella dei file temporanei: nella cache del framework, che non è nei backup e non è l'archivio. */
    public static function cartellaTemporanea(): string
    {
        return storage_path('framework/cache/fascicoli');
    }

    /**
     * La stima del tempo (secondi) e della memoria (MB oltre la base) del fascicolo, dalle righe delle sue parti.
     *
     * @param  array<string,int>  $righe  per pezzo: riepilogo, situazione, posizioni, crediti, registro
     * @return array{secondi: float, mb: float}
     */
    public static function stima(array $righe): array
    {
        $mbPerRiga = ['riepilogo' => RiepilogoPerCompetenzaController::MB_PER_RIGA, 'situazione' => StatoPatrimonialeController::MB_PER_FATTURA,
            'posizioni' => RendicontoCondominiController::MB_PER_POSIZIONE, 'crediti' => RendicontoCondominiController::MB_PER_CREDITO,
            'registro' => RegistroContabilitaController::MB_PER_RIGA, 'conguaglio_persona' => RendicontoConguaglioController::MB_PER_RIGA_PER_PERSONA,
            'conguaglio_tabella' => RendicontoConguaglioController::MB_PER_CELLA_PER_TABELLA, 'conguaglio_lettura' => 0.0];
        $secondi = self::SECONDI_BASE;
        $mb = 0.0;
        foreach ($righe as $pezzo => $n) {
            $secondi += $n * self::SECONDI_PER_RIGA[$pezzo];
            $mb += $n * $mbPerRiga[$pezzo];
        }

        return ['secondi' => $secondi, 'mb' => $mb];
    }

    /**
     * Il limite di tempo del processo per il fascicolo. `PdfService` lo porta a 120 s alla prima stampa, quando è fra 1 e 119 s: qui si fa lo stesso passo
     * (`PdfService::alzaIlLimiteDiTempo`) e si rilegge il limite, così la stima conta quello che il processo ha davvero. Dove l'hosting vieta di cambiarlo
     * (`disable_functions`) resta il limite del processo; senza limite (da riga di comando) la stima conta 120 s.
     */
    public static function limiteTempoDelProcesso(): int
    {
        \App\Services\PDF\PdfService::alzaIlLimiteDiTempo();
        $limite = (int) ini_get('max_execution_time');

        return $limite > 0 ? $limite : 120;
    }

    /**
     * Il tetto del fascicolo con i limiti dati: i secondi della stima (la metà del limite di tempo) e i MB oltre la base (la formula delle stampe, con il
     * margine dell'85 %; `-1`, nessun limite).
     *
     * @return array{secondi: float, mb: float}
     */
    public static function tetti($limiteMemoria, int $limiteTempo): array
    {
        return ['secondi' => $limiteTempo * self::MARGINE_TEMPO, 'mb' => round(self::megabyteOltreLaBase($limiteMemoria, self::MB_BASE) ?? INF, 4)];
    }

    /**
     * Il fascicolo, o il perché non parte.
     *
     * @param  list<string>  $scelte  le parti spuntate, in qualunque ordine
     * @param  string|null  $limite  il `memory_limit` che vale per il tetto; nullo, quello del processo
     * @param  bool  $lettere  la colonna dell'ultima lettera di sollecito nei crediti per anzianità: il fascicolo va a tutti, e di serie non c'è
     * @return array{pdf:string, nome:string, indice: list<array{parte:string, titolo:string, pagina:?int, pagine:int, nota:?string}>}|array{troppo:string, togliere:list<string>}
     */
    public function genera(Condominio $condominio, Esercizio $esercizio, array $scelte, bool $dettaglio = false, ?string $limite = null, bool $lettere = false): array
    {
        $limite = $limite ?? self::$limiteMemoria ?? (string) ini_get('memory_limit');
        self::togliCartelleVecchie();
        $parti = array_values(array_intersect(self::PARTI, $scelte));
        if ($parti === []) {
            $parti = self::PARTI;
        }
        $c = $this->controlli->perEsercizio($condominio, $esercizio);
        // I costi fuori dai capitoli del riepilogo servono alla stampa del conguaglio, anche dopo che il riepilogo esce dalla memoria.
        $c['fuori_capitoli'] = $c['riepilogo']['fuori_capitoli'];

        // La lettura del conguaglio è nella stima con il suo peso, prima di farla: oltre il tetto il fascicolo non la fa.
        $troppo = self::troppo($parti, $this->righeDelleParti($parti, $esercizio, $c, $dettaglio), self::tetti($limite, self::$limiteTempo ?? self::limiteTempoDelProcesso()), $limite);
        if ($troppo !== null) {
            return $troppo;
        }
        // La parte del conguaglio legge il consuntivo per persona una volta, con la spesa per tabella. La stessa lettura serve ai quattro controlli del
        // conguaglio (la parte «Controlli»; senza la parte del conguaglio restano da leggere, con il rimando alla scheda), al riquadro della Sintesi e
        // alle due stampe della parte: tutti e tre si fanno qui, e la lettura esce dalla memoria prima delle parti, che non la tengono.
        $letto = in_array('conguaglio', $parti, true) ? $this->conguaglio->leggi($esercizio, [], true) : null;
        $c['conguaglio'] = null;
        if ($letto !== null) {
            $c = $this->controlli->conIlConguaglio($c, $letto);
        }

        $sintesi = $this->sintesi->perEsercizio($condominio, $esercizio, null, $c);
        if ($letto !== null) {
            $c['conguaglio_stampe'] = $this->stampeDelConguaglio($condominio, $esercizio, $letto, $c['fuori_capitoli'] ?? null);
            unset($letto);
            $c['conguaglio'] = null;
            gc_collect_cycles();
        }
        $cartella = self::cartellaTemporanea().'/'.Str::uuid();
        $file = new Filesystem;
        $file->ensureDirectoryExists($cartella);
        // Un errore fatale (il tempo o la memoria finiti) non esegue il `finally`: la funzione di chiusura sì.
        register_shutdown_function(static fn () => $file->deleteDirectory($cartella));

        try {
            $voci = [];
            $stampati = [];
            foreach ($parti as $parte) {
                // Il riepilogo letto serve solo alla sua parte (la sintesi ha già i suoi numeri): non resta in memoria mentre si stampano le parti grandi.
                if ($parte !== 'riepilogo') {
                    unset($c['riepilogo']);
                }
                $pezzi = $this->parte($parte, $condominio, $esercizio, $c, $dettaglio, $lettere);
                if ($parte === 'conguaglio') {
                    // Le due tabelle del conguaglio sono nei loro pezzi: non restano in memoria per le parti dopo.
                    unset($c['conguaglio_stampe']);
                    gc_collect_cycles();
                }
                foreach ($pezzi as $pezzo) {
                    if (isset($pezzo['vista'])) {
                        $percorso = $cartella.'/'.count($stampati).'.pdf';
                        // Nel piede di ogni pagina il nome della parte accanto al numero, «Situazione patrimoniale, pagina 1 di 2», e sotto la riga libera dove
                        // l'unione scrive il numero del fascicolo, quello dell'indice («Pagina 5 di 13 del fascicolo»).
                        // Ogni parte ha la carta del rendiconto, e comincia con la riga dello studio: il blocco intero è nel frontespizio.
                        $mpdf = $this->pdf->generate($pezzo['vista'], array_merge($pezzo['dati'], ['gruppo_carta' => GruppoCarta::RENDICONTO_E_ASSEMBLEA, 'comincia_con_la_testatina' => true])
                            + ['senza_firma' => true, 'parte_del_fascicolo' => $pezzo['piede'] ?? $pezzo['titolo']], $pezzo['opzioni']);
                        $pagine = count($mpdf->pages);
                        $piede = self::piedeDi($mpdf);
                        $mpdf->Output($percorso, Destination::FILE);
                        [$pagine, $piede] = self::comeUscita($mpdf, $pagine, $piede);
                        unset($mpdf);
                        gc_collect_cycles();
                        $stampati[] = ['percorso' => $percorso, 'piede' => $piede];
                        $voci[] = ['parte' => $parte, 'titolo' => $pezzo['titolo'], 'pagina' => null, 'pagine' => $pagine, 'nota' => null];
                    } else {
                        $voci[] = ['parte' => $parte, 'titolo' => $pezzo['titolo'], 'pagina' => null, 'pagine' => 0, 'nota' => $pezzo['nota']];
                    }
                }
            }

            // Il frontespizio, con le pagine dell'indice: si scrive una volta per sapere quante pagine occupa, e di nuovo se non è una. Se non sta in una
            // pagina si riscrive prima con le righe strette (gli spazi fra i blocchi più piccoli, le stesse parole): una carta intestata con il margine
            // alto lascia meno spazio, e la firma andava da sola sulla seconda pagina.
            $frontespizio = $cartella.'/frontespizio.pdf';
            $pagineFrontespizio = 1;
            $compatto = false;
            $giri = 0;
            do {
                $indice = $this->numera($voci, $pagineFrontespizio);
                $mpdf = $this->pdf->generate('pdf.gestionale.rendiconto_frontespizio', [
                    'condominio' => $condominio, 'esercizio' => $esercizio, 'sintesi' => $sintesi, 'indice' => $indice, 'studio' => app(PrintSettings::class),
                    'anno_a_cavallo' => $c['anno_a_cavallo'], 'senza_firma' => true, 'gruppo_carta' => GruppoCarta::RENDICONTO_E_ASSEMBLEA, 'compatto' => $compatto,
                ], StatoPatrimonialeController::OPZIONI_STAMPA);
                $scritte = count($mpdf->pages);
                $piedeDelFrontespizio = self::piedeDi($mpdf);
                $mpdf->Output($frontespizio, Destination::FILE);
                [$scritte, $piedeDelFrontespizio] = self::comeUscita($mpdf, $scritte, $piedeDelFrontespizio);
                unset($mpdf);
                $stringere = $scritte > 1 && ! $compatto;
                $compatto = $compatto || $stringere;
                $ancora = $stringere || $scritte !== $pagineFrontespizio;
                $pagineFrontespizio = $stringere ? $pagineFrontespizio : $scritte;
            } while ($ancora && ++$giri < 4);

            // Il numero del fascicolo su ogni pagina, sotto il nome della parte: il totale è il frontespizio più le pagine delle parti.
            $totale = $pagineFrontespizio + array_sum(array_column($voci, 'pagine'));
            $numero = 0;
            $unito = new Mpdf(['mode' => 'utf-8', 'format' => 'A4'] + PdfService::configurazioneFont());
            foreach ([['percorso' => $frontespizio, 'piede' => $piedeDelFrontespizio], ...$stampati] as $stampato) {
                $n = $unito->setSourceFile($stampato['percorso']);
                for ($i = 1; $i <= $n; $i++) {
                    $pagina = $unito->importPage($i);
                    $misura = $unito->getTemplateSize($pagina);
                    // mPDF vuole il foglio in verticale e l'orientamento a parte: con «L» scambia larghezza e altezza del formato che riceve, e un
                    // foglio già orizzontale tornerebbe verticale, tagliato a destra.
                    $unito->AddPageByArray(['orientation' => $misura['orientation'],
                        'sheet-size' => [min($misura['width'], $misura['height']), max($misura['width'], $misura['height'])]]);
                    $unito->useTemplate($pagina);
                    self::scriviIlNumero($unito, ++$numero, $totale, $stampato['piede']);
                }
            }
            $pdf = $unito->Output('', Destination::STRING_RETURN);
        } finally {
            $file->deleteDirectory($cartella);
        }

        return [
            'pdf' => $pdf,
            'nome' => PdfService::nomeFile('fascicolo-rendiconto', $condominio->nome, $esercizio->data_inizio->format('Y'), 'al-'.$sintesi['stato']['alla']),
            'indice' => $indice,
        ];
    }

    /**
     * Come si scrive il numero del fascicolo, per il piede della parte. Le parti nel fascicolo lasciano libera, nella colonna destra del piede, la riga
     * sotto il nome della parte, l'ultima del piede (allineata in basso): finisce dove finisce il piede, al margine del piede della stampa. Il piede col
     * carattere Inter (`pdf._carta.piede_inter`) non ha spazi sotto né a destra; quello di `pdf.base` (le parti che non hanno la grafica del rendiconto; dalla 1.12.0-beta.9 anche le posizioni e i crediti per anzianità hanno quella col carattere Inter) ha lo spazio
     * fra le celle della sua tabella, e il testo della tabella a 9 punti (mPDF non le porta la misura del `div`). `sopra` e `rientro`: dalla fine del piede
     * alla riga di base del testo, e dal margine destro alla fine del testo, in mm; misurati il 10/11/2026 con una riga di prova scritta dal piede stesso
     * nel posto libero (il numero cade sul suo riquadro a meno di 0,005 punti). Se il piede cambia, le misure si rifanno: `NumeroDelFascicoloTest` lo dice.
     */
    private const PIEDI = [
        'inter' => ['carattere' => 'inter', 'misura' => 7, 'colore' => [0x5A, 0x6B, 0x7B], 'sopra' => 0.584, 'rientro' => 0.0],
        'dejavusans' => ['carattere' => 'dejavusans', 'misura' => 9, 'colore' => [0x33, 0x33, 0x33], 'sopra' => 1.653, 'rientro' => 0.847],
    ];

    /**
     * Dove finisce il piede di una stampa, letto dalla stampa appena scritta: il margine del piede (mPDF lo scrive con il fondo lì e lo fa crescere verso
     * l'alto; con la carta intestata è quello della carta), il margine destro, il carattere del piede.
     *
     * @return array{basso: float, destro: float, carattere: string}
     */
    private static function piedeDi(Mpdf $mpdf): array
    {
        return ['basso' => (float) $mpdf->margin_footer, 'destro' => (float) $mpdf->rMargin, 'carattere' => $mpdf->default_font === 'inter' ? 'inter' : 'dejavusans'];
    }

    /**
     * Le pagine e il piede della stampa com'è uscita davvero. Le pagine e il piede si leggono prima di `Output`, che con la carta intestata rifà il PDF; se
     * lì la carta non si disegna e la stampa esce a campi ({@see MpdfConCarta::$ripiego}), i margini sono altri, le pagine possono essere altre e il piede è
     * un altro: quelli letti prima sono di una stampa che non è uscita, e l'indice, il totale e il posto del numero devono essere quelli del PDF che c'è.
     *
     * @param  array{basso: float, destro: float, carattere: string}  $piede  quelli letti prima di `Output`
     * @return array{0: int, 1: array{basso: float, destro: float, carattere: string}}
     */
    private static function comeUscita(Mpdf $mpdf, int $pagine, array $piede): array
    {
        $ripiego = $mpdf instanceof MpdfConCarta ? $mpdf->ripiego : null;

        return $ripiego === null ? [$pagine, $piede] : [$ripiego['pagine'], array_diff_key($ripiego, ['pagine' => 0])];
    }

    /** «Pagina 5 di 13 del fascicolo», nella riga che il piede della parte lascia libera sotto il suo nome. */
    private static function scriviIlNumero(Mpdf $unito, int $numero, int $totale, array $piede): void
    {
        $stile = self::PIEDI[$piede['carattere']];
        $testo = "Pagina {$numero} di {$totale} del fascicolo";
        $unito->SetFont($stile['carattere'], '', $stile['misura']);
        $unito->SetTextColor(...$stile['colore']);
        $fine = $unito->w - $piede['destro'] - $stile['rientro'];
        $unito->WriteText($fine - $unito->GetStringWidth($testo), $unito->h - $piede['basso'] - $stile['sopra'], $testo);
    }

    /** Toglie le cartelle temporanee più vecchie di un'ora: quelle di un fascicolo il cui processo è stato ucciso da fuori. Le altre sono di fascicoli in corso. */
    public static function togliCartelleVecchie(): void
    {
        $file = new Filesystem;
        if (! $file->isDirectory(self::cartellaTemporanea())) {
            return;
        }
        foreach ($file->directories(self::cartellaTemporanea()) as $cartella) {
            if ($file->lastModified($cartella) < time() - 3600) {
                $file->deleteDirectory($cartella);
            }
        }
    }

    /**
     * Le righe dei pezzi delle parti scelte, dalle letture che il fascicolo ha già fatto (i controlli leggono il riepilogo, la situazione e le posizioni)
     * e dal conteggio del registro: le stesse righe che le stampe avranno.
     *
     * @return array<string,int>
     */
    public function righeDelleParti(array $parti, Esercizio $esercizio, array $c, bool $dettaglio): array
    {
        $righe = [];
        foreach ($parti as $parte) {
            match ($parte) {
                'riepilogo' => $righe['riepilogo'] = RiepilogoPerCompetenzaController::righeDellaStampa($c['riepilogo'], $dettaglio),
                'situazione' => $righe['situazione'] = count($c['pagina']['debiti']['fatture']),
                'condomini' => $righe += ['posizioni' => $c['conteggi']['posizioni'], 'crediti' => $c['conteggi']['rate_non_pagate']],
                'conguaglio' => $righe += self::righeDelConguaglio($esercizio),
                'registro' => $righe['registro'] = $this->registro->numeroRighe($esercizio),
                default => null,
            };
        }

        return $righe;
    }

    /**
     * I dati delle due stampe del conguaglio dalla lettura: il consuntivo per tabella e il conguaglio per persona (le righe per la stampa, con le uscite
     * fuori dai capitoli). Si fanno prima delle parti, così la lettura, più grande, non resta in memoria mentre si stampano le altre.
     *
     * @return array{tabelle: array<string,mixed>, persone: array<string,mixed>}
     */
    private function stampeDelConguaglio(Condominio $condominio, Esercizio $esercizio, array $letto, ?array $fuori): array
    {
        return ['tabelle' => $this->conguaglio->perTabella($condominio, $esercizio, $letto), 'persone' => $this->conguaglio->righe($condominio, $esercizio, $letto, $fuori, true)];
    }

    /**
     * Le righe delle due stampe del conguaglio e il peso della sua lettura, prima di farla. Le stampe hanno una riga per persona e unità **per
     * gestione**: per ogni gestione dell'esercizio con voci, le titolarità attive nell'esercizio sulle unità delle tabelle delle sue voci; per il
     * conguaglio per persona in più la riga da altre gestioni, le persone e unità con una rata ancora aperta di una gestione che l'esercizio non ha
     * (i subtotali sono pochi, e stanno nel margine). Il consuntivo per tabella si conta in celle: le righe della gestione per le celle di una riga con
     * le sue tabelle. Il peso è quello del riquadro della Sintesi ({@see ConguaglioDelRendiconto::peso()}).
     *
     * @return array{conguaglio_lettura:int, conguaglio_persona:int, conguaglio_tabella:int}
     */
    public static function righeDelConguaglio(Esercizio $esercizio): array
    {
        $inizio = substr((string) $esercizio->data_inizio, 0, 10);
        $fine = substr((string) $esercizio->data_fine, 0, 10);
        $gestioni = DB::table('esercizio_gestione')->where('esercizio_id', $esercizio->id)->pluck('gestione_id')->map(fn ($id) => (int) $id)->all();
        $persona = 0;
        $celle = 0;
        foreach ($gestioni as $gid) {
            $voci = DB::table('conti')->join('piani_conti as pc', 'pc.id', '=', 'conti.piano_conto_id')->where('pc.gestione_id', $gid)->pluck('conti.id')->all();
            $tabelle = $voci === [] ? [] : DB::table('conto_tabella_millesimale')->whereIn('conto_id', $voci)->distinct()->pluck('tabella_id')->all();
            if ($tabelle === []) {
                continue;
            }
            $righe = (int) DB::table('anagrafica_immobile as ai')->where('ai.attivo', true)
                ->whereIn('ai.immobile_id', DB::table('quote_tabella')->whereIn('tabella_id', $tabelle)->select('immobile_id'))
                ->where(fn ($q) => $q->whereNull('ai.data_inizio')->orWhere('ai.data_inizio', '<=', $fine.' 23:59:59'))
                ->where(fn ($q) => $q->whereNull('ai.data_fine')->orWhere('ai.data_fine', '>=', $inizio))
                ->count();
            $persona += $righe;
            $celle += $righe * RendicontoConguaglioController::celleDellaRiga(count($tabelle));
        }
        $altre = DB::query()->fromSub(DB::table('rate_quote as rq')->join('rate as r', 'r.id', '=', 'rq.rata_id')->join('piani_rate as p', 'p.id', '=', 'r.piano_rate_id')
            ->where('p.condominio_id', $esercizio->condominio_id)->whereNotIn('p.gestione_id', $gestioni)->whereIn('rq.stato', ['da_pagare', 'parzialmente_pagata'])
            ->select('rq.anagrafica_id', 'rq.immobile_id')->distinct(), 'aperte')->count();

        return ['conguaglio_lettura' => app(ConguaglioDelRendiconto::class)->peso($esercizio), 'conguaglio_persona' => $persona + $altre, 'conguaglio_tabella' => $celle];
    }

    /**
     * Oltre il tetto di tempo o di memoria: le parti da togliere, dalla più grande, finché la stima del resto ci sta; la frase dice quali, e che ognuna si
     * scarica da sola dalla sua scheda; di una parte più grande anche della sua stampa dice solo che la scheda dice come fare, mai che si scarica da sola.
     * Quando una sola parte tolta basta e più
     * parti, da sole, basterebbero, la frase le nomina tutte («togli una di queste»); `togliere` ha la più leggera. Se nemmeno senza le parti con righe il
     * fascicolo ci sta, la frase dice di stampare le parti una per una.
     *
     * @param  list<string>  $parti
     * @param  array<string,int>  $righe
     * @param  array{secondi: float, mb: float}  $tetti
     * @return array{troppo:string, togliere:list<string>}|null
     */
    public static function troppo(array $parti, array $righe, array $tetti, $limiteMemoria): ?array
    {
        $dellaParte = fn (string $pezzo) => match ($pezzo) {
            'posizioni', 'crediti' => 'condomini',
            'conguaglio_persona', 'conguaglio_tabella', 'conguaglio_lettura' => 'conguaglio',
            default => $pezzo,
        };
        $sta = fn (array $r) => ($s = self::stima($r))['secondi'] <= $tetti['secondi'] && $s['mb'] <= $tetti['mb'];
        if ($sta($righe)) {
            return null;
        }
        $stima = self::stima($righe);
        $cosa = match (true) {
            $stima['secondi'] > $tetti['secondi'] && $stima['mb'] > $tetti['mb'] => 'il tempo e la memoria',
            $stima['secondi'] > $tetti['secondi'] => 'il tempo',
            default => 'la memoria',
        };

        $togliere = [];
        $alternative = [];
        $resto = $righe;
        $senza = fn (array $r, string $parte) => array_filter($r, fn ($n, $pezzo) => $dellaParte($pezzo) !== $parte, ARRAY_FILTER_USE_BOTH);
        while (! $sta($resto) && $resto !== []) {
            // Il peso di ogni parte: la sua quota del tetto, nella misura che pesa di più.
            $peso = [];
            foreach ($resto as $pezzo => $n) {
                $s = self::stima([$pezzo => $n]);
                $peso[$dellaParte($pezzo)] = ($peso[$dellaParte($pezzo)] ?? 0) + max(($s['secondi'] - self::SECONDI_BASE) / max(1.0, $tetti['secondi']), $s['mb'] / max(1.0, $tetti['mb']));
            }
            // Si toglie il meno possibile: la più leggera fra quelle che da sole bastano; se nessuna basta, la più pesante, e si guarda di nuovo.
            $bastano = array_filter($peso, fn ($p, $parte) => $sta($senza($resto, $parte)), ARRAY_FILTER_USE_BOTH);
            if ($bastano !== []) {
                asort($bastano);
                $via = array_key_first($bastano);
                // Al primo passo, tutte le parti che da sole bastano: la frase le nomina, perché chi legge sceglie quale tenere.
                $alternative = $togliere === [] ? array_keys($bastano) : [];
            } else {
                arsort($peso);
                $via = array_key_first($peso);
            }
            $togliere[] = $via;
            $resto = $senza($resto, $via);
        }

        // Più parti che da sole bastano: si nominano tutte, nell'ordine del fascicolo; altrimenti quelle che si tolgono.
        $nominate = count($alternative) > 1 ? array_values(array_intersect(self::PARTI, $alternative)) : $togliere;
        $nomiDi = fn (array $pezzi) => array_map(fn ($p) => '«'.self::NOMI[$p].'»', $pezzi);
        $nomi = $nomiDi($nominate);
        $grandi = array_values(array_filter($nominate, fn ($p) => ! self::stampabileDaSola($p, $righe, $limiteMemoria)));
        $daSole = array_values(array_diff($nominate, $grandi));
        // Più parti da scegliere: «una di queste»; se si tolgono insieme, «A e B». «Che si scarica da sola» solo se nessuna è più grande della sua stampa.
        $quali = count($nominate) > 1 && count($togliere) === 1
            ? 'una di queste: '.implode(', ', array_slice($nomi, 0, -1)).' o '.end($nomi)
            : implode(' e ', $nomi);
        if ($grandi === []) {
            $quali .= count($nominate) === 1 ? ', che si scarica da sola dalla sua scheda' : ', che si scaricano da sole, ognuna dalla sua scheda';
        }
        $dellaSuaStampa = count($grandi) === 1
            ? ' '.$nomiDi($grandi)[0].' è più grande anche della sua stampa: la sua scheda dice come fare.'
            : ' '.implode(' e ', $nomiDi($grandi)).' sono più grandi anche della loro stampa: la scheda di ognuna dice come fare.';
        $delleDaSole = match (true) {
            $grandi === [] || $daSole === [] => '',
            count($daSole) === 1 => ' '.$nomiDi($daSole)[0].' si scarica da sola dalla sua scheda.',
            default => ' '.implode(' e ', $nomiDi($daSole)).' si scaricano da sole, ognuna dalla sua scheda.',
        };
        $frase = $sta($resto) && count($togliere) < count($parti)
            ? 'Il fascicolo con queste parti supererebbe '.$cosa.' che il server concede a una stampa: togli '.$quali.($grandi === [] || count($nominate) > 1 ? ', e' : ' e').' scarica di nuovo il fascicolo.'
                .($grandi === [] ? '' : $dellaSuaStampa.$delleDaSole)
            : 'Con '.$cosa.' che il server concede a una stampa il fascicolo non si può fare: stampa le parti una per una, ognuna dalla sua scheda.';

        return ['troppo' => $frase, 'togliere' => $togliere];
    }

    /** Se la parte sta nel tetto della sua stampa, da sola. */
    private static function stampabileDaSola(string $parte, array $righe, $limiteMemoria): bool
    {
        return match ($parte) {
            'riepilogo' => ($righe['riepilogo'] ?? 0) <= RiepilogoPerCompetenzaController::righeStampabiliDelRiepilogo($limiteMemoria),
            'situazione' => ($righe['situazione'] ?? 0) <= StatoPatrimonialeController::fattureStampabiliCon($limiteMemoria),
            'condomini' => ($righe['posizioni'] ?? 0) <= RendicontoCondominiController::posizioniStampabiliCon($limiteMemoria)
                && ($righe['crediti'] ?? 0) <= RendicontoCondominiController::creditiStampabiliCon($limiteMemoria),
            'registro' => ($righe['registro'] ?? 0) <= RegistroContabilitaController::righeStampabiliDelRegistro($limiteMemoria),
            'conguaglio' => ($righe['conguaglio_persona'] ?? 0) <= RendicontoConguaglioController::righePerPersonaStampabiliCon($limiteMemoria)
                && ($righe['conguaglio_tabella'] ?? 0) <= RendicontoConguaglioController::celleStampabiliCon($limiteMemoria),
            default => true,
        };
    }

    /** Le pagine dell'indice: ogni parte stampata comincia dopo il frontespizio e le parti prima. */
    private function numera(array $voci, int $pagineFrontespizio): array
    {
        $pagina = $pagineFrontespizio + 1;
        foreach ($voci as $i => $v) {
            if ($v['pagine'] > 0) {
                $voci[$i]['pagina'] = $pagina;
                $pagina += $v['pagine'];
            }
        }

        return $voci;
    }

    /**
     * I pezzi di una parte: da stampare (vista, dati, opzioni) o da dire nell'indice (la nota). La parte «condòmini» ha due pezzi, le posizioni e i
     * crediti per anzianità. Il tetto di memoria e di tempo è già stato guardato sul totale, prima di cominciare.
     *
     * @return list<array<string,mixed>>
     */
    private function parte(string $parte, Condominio $condominio, Esercizio $esercizio, array $c, bool $dettaglio, bool $lettere = false): array
    {
        $base = ['condominio' => $condominio, 'esercizio' => $esercizio];
        $situazione = fn (string $p, string $titolo) => ['titolo' => $titolo, 'vista' => 'pdf.gestionale.stato_patrimoniale', 'opzioni' => StatoPatrimonialeController::OPZIONI_STAMPA,
            'dati' => $base + ['pagina' => $c['pagina'], 'parte' => $p, 'controlli' => $c['controlli'], 'esito' => $c['esito'], 'anno_a_cavallo' => $c['anno_a_cavallo']]];

        switch ($parte) {
            case 'riepilogo':
                return [['titolo' => 'Riepilogo finanziario'.($dettaglio ? ', con il dettaglio per spesa' : ''), 'piede' => 'Riepilogo finanziario', 'vista' => 'pdf.gestionale.rendiconto_riepilogo',
                    'dati' => $base + ['riepilogo' => $c['riepilogo'], 'dettaglio' => $dettaglio], 'opzioni' => RiepilogoPerCompetenzaController::OPZIONI_STAMPA]];
            case 'situazione':
                return [$situazione('situazione', 'Situazione patrimoniale')];
            case 'cassa':
                return [$situazione('cassa', 'Entrate e uscite per cassa')];
            case 'controlli':
                return [$situazione('controlli', 'Controlli del rendiconto')];
            case 'condomini':
                $pezzi = [];
                $posizioni = $this->posizioni->posizioni($condominio, $esercizio);
                $pezzi[] = $posizioni['righe'] === []
                    ? ['titolo' => 'Posizioni dei condòmini', 'nota' => 'vuota: nessuna posizione diversa da zero alla data']
                    : ['titolo' => 'Posizioni dei condòmini', 'vista' => 'pdf.gestionale.rendiconto_posizioni', 'opzioni' => RendicontoCondominiController::OPZIONI_STAMPA,
                        'dati' => $base + ['posizioni' => $posizioni, 'titolo' => 'Posizioni dei condòmini']];
                unset($posizioni);
                $crediti = $this->posizioni->crediti($condominio, $esercizio);
                $pezzi[] = $crediti['righe'] === []
                    ? ['titolo' => 'Crediti per anzianità', 'nota' => 'vuota: nessuna rata non pagata alla data']
                    : ['titolo' => 'Crediti per anzianità', 'vista' => 'pdf.gestionale.rendiconto_crediti', 'opzioni' => RendicontoCondominiController::OPZIONI_STAMPA,
                        'dati' => $base + ['crediti' => $crediti, 'titolo' => 'Crediti per anzianità', 'senza_lettere' => ! $lettere]];

                return $pezzi;
            case 'conguaglio':
                // Le due tabelle, dalla stessa lettura, fatte prima delle parti ({@see stampeDelConguaglio()}): il consuntivo per tabella e il conguaglio per
                // persona. Il foglio della persona non è nel fascicolo.
                ['tabelle' => $tabelle, 'persone' => $persone] = $c['conguaglio_stampe'];
                $vuota = array_sum(array_map(fn ($g) => count($g['righe']), $tabelle['gestioni'])) === 0;
                $pezzi = [$vuota
                    ? ['titolo' => 'Consuntivo per tabella', 'nota' => 'vuota: nessuna spesa divisa fra le persone']
                    : ['titolo' => 'Consuntivo per tabella', 'vista' => 'pdf.gestionale.consuntivo_per_tabella', 'opzioni' => RendicontoConguaglioController::OPZIONI_STAMPA,
                        'dati' => $base + ['consuntivo' => $tabelle, 'titolo' => 'Consuntivo per tabella']]];
                unset($tabelle);
                $pezzi[] = RendicontoConguaglioController::contaRighe($persone) === 0
                    ? ['titolo' => 'Conguaglio per persona', 'nota' => 'vuota: nessuna persona con una cifra']
                    : ['titolo' => 'Conguaglio per persona', 'vista' => 'pdf.gestionale.conguaglio_per_persona', 'opzioni' => RendicontoConguaglioController::OPZIONI_STAMPA,
                        'dati' => $base + ['conguaglio' => $persone, 'titolo' => 'Conguaglio per persona']];

                return $pezzi;
            case 'registro':
                $registro = $this->registro->registroConRiepilogo($esercizio);
                if ($registro['righe'] === [] && ($registro['riporto']['totale'] ?? 0) === 0) {
                    return [['titolo' => 'Registro di contabilità', 'nota' => 'vuota: nessun movimento di cassa nell\'esercizio']];
                }

                return [['titolo' => 'Registro di contabilità', 'vista' => 'pdf.gestionale.registro_contabilita', 'opzioni' => RegistroContabilitaController::OPZIONI_STAMPA,
                    'dati' => RegistroContabilitaController::datiDellaStampa($condominio, $esercizio, $registro, ['data_da' => null, 'data_a' => null, 'search' => null], false)]];
        }

        return [];
    }
}
