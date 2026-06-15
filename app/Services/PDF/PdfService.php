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

        $finalConfig = array_merge($defaultConfig, $config);

        $mpdf = new Mpdf($finalConfig);
        
        // Add dynamic footer with legal text from settings and page numbering
        $footerText = trim($settings->nota_legale_stampe ?? '');
        
        $footerHtml = '
            <div style="border-top: 1px solid #ddd; padding-top: 5px; font-size: 8pt; font-weight: normal; color: #555;">
                <table width="100%" style="border-collapse: collapse;">
                    <tr>
                        <td style="text-align: left; width: 80%; white-space: nowrap; overflow: hidden;">' . htmlspecialchars($footerText) . '</td>
                        <td style="text-align: right; width: 20%;">Pagina {PAGENO} di {nbpg}</td>
                    </tr>
                </table>
            </div>
        ';
        $mpdf->SetHTMLFooter($footerHtml);

        // Prepare signature path for the view
        $data['firma_stampe_absolute_path'] = null;
        if ($settings->firma_stampe_path && Storage::disk('public')->exists($settings->firma_stampe_path)) {
            $data['firma_stampe_absolute_path'] = Storage::disk('public')->path($settings->firma_stampe_path);
        }

        $html = View::make($view, $data)->render();
        
        $mpdf->WriteHTML($html);

        return $mpdf;
    }
}
