<?php

namespace App\Services\PDF;

use App\Settings\PrintSettings;
use Illuminate\Support\Facades\Storage;
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
    /**
     * Generates a PDF instance from a Blade view.
     *
     * @param string $view   The name of the Blade view (e.g., 'pdf.gestionale.distinta').
     * @param array  $data   The data to pass to the Blade view.
     * @param array  $config Additional mPDF configuration to override defaults.
     * @return \Mpdf\Mpdf    The configured mPDF instance ready for output.
     */
    public function generate(string $view, array $data = [], array $config = []): Mpdf
    {
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

        $mpdf = new Mpdf($finalConfig);
        
        // Add nota legale to data so views can use it in their HTML footers
        $data['nota_legale_stampe'] = trim($settings->nota_legale_stampe ?? '');

        // Prepare signature path for the view.
        //
        // ⚠️ **Non tutte le stampe sono documenti da sottoscrivere.** Un registro come il Libro
        // Giornale (§10.5 di docs/registri_contabili.md: "niente firma, niente riepilogo per
        // capitolo") non è un rendiconto — è una lettura dei dati, non un atto dell'amministratore.
        // `senza_firma` nel `$data` del chiamante è l'opt-out: assente per ogni stampa esistente,
        // quindi zero cambiamento di comportamento per chi già c'era.
        $data['firma_stampe_absolute_path'] = null;
        if (
            empty($data['senza_firma'])
            && $settings->firma_stampe_path
            && Storage::disk('public')->exists($settings->firma_stampe_path)
        ) {
            $data['firma_stampe_absolute_path'] = Storage::disk('public')->path($settings->firma_stampe_path);
        }

        $html = View::make($view, $data)->render();

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
        $limiteCorrente = (int) ini_get('max_execution_time');
        if ($limiteCorrente > 0 && $limiteCorrente < 120) {
            @set_time_limit(120);
        }

        $mpdf->WriteHTML($html);

        return $mpdf;
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
    private static function configurazioneFont(): array
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
