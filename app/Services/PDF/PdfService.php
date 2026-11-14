<?php

namespace App\Services\PDF;

use App\Settings\PrintSettings;
use App\Services\Documenti\ArchivioDocumenti;
use Mpdf\Mpdf;
use Illuminate\Support\Facades\View;

/**
 * Service class for generating PDF documents using mPDF.
 *
 * This class provides a centralized way to configure and generate PDF files
 * across the application, ensuring a consistent layout and styling.
 */
class PdfService
{
    /** Il nome della testata comune di `pdf.base`, il condominio al centro: la stampa che la scrive ha il margine che si allunga sulla testata. */
    public const TESTATA_COMUNE = 'KondoHeader';

    /**
     * Generates a PDF instance from a Blade view.
     *
     * @param string $view   The name of the Blade view (e.g., 'pdf.gestionale.distinta').
     * @param array  $data   The data to pass to the Blade view.
     * @param array  $config Additional mPDF configuration to override defaults.
     * @return \Mpdf\Mpdf    The configured mPDF instance ready for output.
     */
    /**
     * Nome del file per i tre libri contabili: «prima-nota-condominio-rossi-2026.pdf», non «print.pdf»
     * (il nome che il browser ricava dall'indirizzo quando la risposta non ne dichiara uno — visto al
     * test reale della beta.25). Solo ASCII: `Str::slug` toglie accenti e spazi, e il nome resta
     * leggibile in un elenco di download.
     */
    public static function nomeFile(string $libro, string $condominio, ?string $esercizio = null, ?string $suffisso = null): string
    {
        $parti = array_filter([$libro, \Illuminate\Support\Str::slug($condominio), $esercizio, $suffisso]);

        return implode('-', $parti).'.pdf';
    }

    /**
     * La stampa di una vista. `$data['gruppo_carta']` dice il gruppo di documenti della stampa ({@see GruppoCarta}): senza, è un'eccezione, così
     * una stampa nuova che non lo dice non esce. La vista riceve la carta dello studio del gruppo come `$carta` (o quella di
     * `$data['carta_da_provare']`, la prova della carta); `comincia_con_la_testatina` (le parti del fascicolo dopo il frontespizio) la fa
     * cominciare con la riga dello studio invece che con il blocco intero.
     *
     * Con lo studio, il margine alto si allunga sulla testata (`setAutoTopMargin` a `stretch`, senza aggiunte): mPDF non sposta il corpo sotto una
     * testata più alta del margine, ci scrive sopra, e la testata dello studio cresce con un nome lungo o un indirizzo su due righe. Senza lo studio
     * il margine si allunga solo sulla testata comune di `pdf.base` (il condominio al centro): con un nome di oltre 55 caratteri circa va su due
     * righe e scende sotto il margine delle stampe verticali; con un nome più corto sta nel margine, e la stampa resta com'è. Le altre stampe senza
     * lo studio, e il modello F24, hanno la configurazione di sempre.
     *
     * Con la carta intestata (le stampe verticali, se la carta del gruppo ce l'ha e si legge) i margini sono quelli della carta: la testata (il
     * condominio) comincia al margine alto e il corpo sotto di lei, il piede finisce al margine basso e il corpo sopra di lui (tutti e due i margini si
     * allungano su quello che contengono), i lati sono quelli della carta. Il documento è un {@see MpdfConCarta}: quando si scrive, la carta va sotto
     * ogni pagina; se lì la carta non si disegna, esce la stessa stampa con la carta a campi.
     *
     * @throws \InvalidArgumentException se la stampa non dice il gruppo della carta
     */
    public function generate(string $view, array $data = [], array $config = []): Mpdf
    {
        return $this->stampa($view, $data, $config, true);
    }

    /** La stampa di una vista, come la dice `generate()`; con `$conLaCartaIntestata` falso, la stessa carta a campi anche sulle verticali. */
    private function stampa(string $view, array $data, array $config, bool $conLaCartaIntestata): Mpdf
    {
        $datiDelChiamante = $data;
        $gruppo = $data['gruppo_carta'] ?? null;
        if (! $gruppo instanceof GruppoCarta) {
            throw new \InvalidArgumentException("La stampa «{$view}» non dice il gruppo della carta: `gruppo_carta` deve essere un ".GruppoCarta::class.'.');
        }
        // La carta intestata è verticale: una stampa orizzontale ha la testata a campi della stessa carta, e così la verticale la cui carta intestata
        // non si è disegnata.
        $verticale = strtoupper((string) ($config['orientation'] ?? 'P')) !== 'L' && $conLaCartaIntestata;
        $primaPaginaIntera = empty($data['comincia_con_la_testatina']);
        // La prova della carta, dalle impostazioni, passa la carta da provare al posto di quella del gruppo.
        $carta = ($data['carta_da_provare'] ?? null) instanceof \App\Models\CartaStampa
            ? app(CartaDiStampa::class)->perCarta($data['carta_da_provare'], $gruppo, $primaPaginaIntera, $verticale)
            : app(CartaDiStampa::class)->perGruppo($gruppo, $primaPaginaIntera, $verticale);
        $data['carta'] = $carta;
        $intestata = $carta->cartaIntestata();

        $settings = app(PrintSettings::class);
        $defaultConfig = [
            'mode'          => 'utf-8',
            'format'        => 'A4',
            'orientation'   => 'P',
            'margin_left'   => 12,
            'margin_right'  => 12,
            'margin_top'    => 40,
            'margin_bottom' => 15,
            'margin_header' => 10,
            'margin_footer' => 5,
            'default_font'  => 'dejavusans',
        ];

        $finalConfig = array_merge($defaultConfig, $config, self::configurazioneFont());
        if ($carta->haStudio()) {
            $finalConfig['setAutoTopMargin'] = 'stretch';
            $finalConfig['autoMarginPadding'] = 0;
        }
        if ($intestata !== null) {
            $finalConfig = array_merge($finalConfig, [
                'margin_header' => $intestata->alto, 'margin_top' => $intestata->alto,
                'margin_footer' => $intestata->basso, 'margin_bottom' => $intestata->basso,
                'margin_left' => $intestata->sinistro, 'margin_right' => $intestata->destro,
                'setAutoTopMargin' => 'stretch', 'setAutoBottomMargin' => 'stretch', 'autoMarginPadding' => 0,
            ]);
        }

        $mpdf = $intestata === null ? new Mpdf($finalConfig)
            : new MpdfConCarta($finalConfig, $intestata, $primaPaginaIntera, fn (): Mpdf => $this->stampa($view, $datiDelChiamante, $config, false));
        
        // Add nota legale to data so views can use it in their HTML footers
        $data['nota_legale_stampe'] = trim($settings->nota_legale_stampe ?? '');

        // Prepare signature path for the view.
        //
        // ⚠️ **Non tutte le stampe sono documenti da sottoscrivere.** Un registro come il Libro
        // Giornale (§10.5 di docs/registri_contabili.md: "niente firma, niente riepilogo per
        // capitolo") non è un rendiconto — è una lettura dei dati, non un atto dell'amministratore.
        // `senza_firma` nel `$data` del chiamante è l'opt-out: assente per ogni stampa esistente,
        // quindi zero cambiamento di comportamento per chi già c'era.
        // Un percorso locale anche quando la firma sta su S3: mPDF vuole un file, non uno stream
        // (`ArchivioDocumenti::percorsoLocalePubblico()` ne fa una copia temporanea in quel caso).
        $data['firma_stampe_absolute_path'] = null;
        if (empty($data['senza_firma']) && $settings->firma_stampe_path) {
            $data['firma_stampe_absolute_path'] = app(ArchivioDocumenti::class)
                ->percorsoLocalePubblico($settings->firma_stampe_path);
        }

        $html = View::make($view, $data)->render();

        if ($gruppo !== GruppoCarta::NESSUNO && ! $carta->haStudio() && str_contains($html, '<htmlpageheader name="'.self::TESTATA_COMUNE.'">')) {
            $mpdf->setAutoTopMargin = 'stretch';
            $mpdf->autoMarginPadding = 0;
        }

        // Su un piano dei conti molto articolato (molti capitoli/sottoconti,
        // molte unità) l'HTML di una stampa può superare il default PHP di
        // pcre.backtrack_limit (1 MB) e mPDF rifiuta la generazione con un
        // 500 ("The HTML code size is larger than pcre.backtrack_limit").
        // Alzarlo è il fix ufficiale raccomandato da mPDF per questo errore
        // — non cambia nulla per l'HTML che già rientrava nel limite, apre
        // solo margine per i casi grandi che prima fallivano. Verificato
        // fino a ~6 MB di HTML (50 capitoli × 80 unità) con questo valore.
        ini_set('pcre.backtrack_limit', '20000000');

        // ⚠️ **Misurato sul Libro Giornale (beta.23)**: un esercizio molto attivo — 2.000
        // scritture, 6.000 righe, oltre il massimo osservato oggi a database — genera un PDF
        // di questa forma in **23,5 secondi**. Ben oltre i `max_execution_time` tipici (30-60s)
        // di un hosting condiviso, dove basterebbe un condominio poco più grande per farlo
        // scadere.
        //
        // ⚠️ **Si alza SOLO dove il limite è finito e più basso, mai in assoluto.** Un
        // `set_time_limit(120)` secco ABBASSA il tetto dove non ce n'era: da CLI
        // `max_execution_time` vale 0 (illimitato) e diventerebbe 120 — misurato — quindi un
        // comando artisan o una suite che genera più PDF morirebbe con un fatale che prima non
        // esisteva. È il difetto che la Fase 1-bis della beta.23 ha trovato nella prima
        // stesura di questa riga.
        self::alzaIlLimiteDiTempo();

        $mpdf->WriteHTML($html);

        return $mpdf;
    }

    /**
     * Porta il limite di tempo del processo a 120 s dove è finito e più basso (vedi il commento in `generate`). Dove l'hosting vieta `set_time_limit`
     * (`disable_functions`) in PHP 8 la funzione non esiste, e chiamarla è un errore che `@` non tace: lì il limite resta quello del processo.
     */
    public static function alzaIlLimiteDiTempo(): void
    {
        $limiteCorrente = (int) ini_get('max_execution_time');
        if ($limiteCorrente > 0 && $limiteCorrente < 120 && function_exists('set_time_limit')) {
            @set_time_limit(120);
        }
    }

    /**
     * I caratteri del progetto, registrati in mPDF — Inter per il testo, Fraunces per i titoli.
     *
     * ⚠️ **Perché auto-ospitati e non da CDN.** mPDF non è un browser: non esegue una pagina e non
     * scarica fogli di stile o font remoti al momento della generazione. Un `<link>` a Google
     * Fonts in una vista di stampa non fallisce con un errore — **non fa niente**, e il PDF esce
     * col font di ripiego senza dirlo. I file stanno quindi in `resources/fonts/`, con le
     * rispettive licenze OFL accanto (entrambi i caratteri sono SIL Open Font License 1.1, quindi
     * ridistribuibili anche in un repository pubblico).
     *
     * ⚠️ **Registrare un carattere non cambia nulla per chi non lo chiede.** Il `default_font`
     * resta `dejavusans`: le sette stampe che esistevano prima escono identiche, byte per byte.
     * Solo chi passa `'default_font' => 'inter'` nella configurazione — oggi la stampa del
     * registro di contabilità — vede il cambio. È la decisione di §7-quinquies di
     * docs/registri_contabili.md: sistema tipografico del fac-simile per le stampe **nuove**, non
     * un rifacimento delle esistenti in un colpo solo.
     *
     * ⚠️ **`tempDir` fuori da `vendor/`, e fuori dai backup.** mPDF, la prima volta che
     * incontra un carattere nuovo, ne scrive la cache metrica su disco; il percorso predefinito
     * sta dentro `vendor/mpdf/mpdf`, che su un'installazione vera può benissimo essere di sola
     * lettura. Sta in `storage/framework/cache` — cartella che il prodotto già pretende
     * scrivibile — e non in `storage/app`: `config/backup.php` archivia tutto `storage/app` ed
     * esclude `storage/framework` proprio perché volatile. Una cache rigenerabile dentro ogni
     * backup e ogni ripristino era un peso senza scopo (revisione della beta.24).
     *
     * ⚠️ **Niente `useOTL`, e non per pigrizia: misurato.** Il fac-simile allinea le cifre con
     * `font-variant-numeric: tabular-nums`, che mPDF sa tradurre nel tag OpenType `tnum` — ma solo
     * se il carattere è registrato con `useOTL`. Provato con quattro maschere diverse su entrambi:
     * Inter fa fallire la costruzione stessa di mPDF («GPOS Lookup Type 5, Format 3 not
     * supported»), Fraunces non si carica più affatto. Non è un difetto da aggirare: è il limite
     * del parser di font di mPDF davanti a caratteri moderni, ed è esattamente ciò di cui avvisa
     * §7-quinquies («mPDF non è un browser»). **Conseguenza accettata:** le cifre restano
     * proporzionali. Ciò che serve davvero a un registro — che gli importi incolonnino sul bordo
     * destro — lo dà l'allineamento a destra della colonna, non il carattere.
     *
     * @return array{fontDir: array<int,string>, fontdata: array<string,array<string,string>>, tempDir: string}
     */
    public static function configurazioneFont(): array
    {
        $tempDir = storage_path('framework/cache/mpdf');

        if (! is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }

        return [
            'fontDir' => array_merge(
                (new \Mpdf\Config\ConfigVariables())->getDefaults()['fontDir'],
                [resource_path('fonts')],
            ),
            'fontdata' => (new \Mpdf\Config\FontVariables())->getDefaults()['fontdata'] + [
                'inter' => [
                    'R' => 'Inter-Regular.ttf',
                    'B' => 'Inter-Bold.ttf',
                ],
                // Il maiuscoletto dei titoli e delle intestazioni del fac-simile è un semibold,
                // non un regular: qui il peso "normale" della famiglia è già il 600.
                'fraunces' => [
                    'R' => 'Fraunces-SemiBold.ttf',
                    'B' => 'Fraunces-Bold.ttf',
                ],
            ],
            'tempDir' => $tempDir,
        ];
    }
}
