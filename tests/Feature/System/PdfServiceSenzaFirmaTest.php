<?php

/**
 * `senza_firma` — l'opt-out aggiunto in beta.23 per la stampa del Libro Giornale (§10.5 di
 * docs/registri_contabili.md: "è un registro, non un atto da sottoscrivere"). PdfService::generate()
 * imposta `firma_stampe_absolute_path` per la vista, e ogni stampa esistente lo lascia implicito
 * (fallback: mostra la firma se configurata) — questo test verifica che il nuovo flag lo azzeri,
 * senza cambiare nulla per chi non lo passa.
 *
 * Non genera un vero PDF: mocka la View facade per intercettare i dati che PdfService le passa,
 * perché la domanda è "che valore riceve la vista?", non "che aspetto ha il rendering" — quella
 * seconda domanda l'ha già risposta una verifica a video sul PDF vero, non ripetibile in CI.
 */

use App\Services\PDF\PdfService;
use App\Settings\PrintSettings;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;

function configuraFirmaDiProva(): string
{
    Storage::fake('public');
    Storage::disk('public')->put('firme/prova.png', 'contenuto-immagine-di-prova');

    $settings = app(PrintSettings::class);
    $settings->firma_stampe_path = 'firme/prova.png';
    $settings->save();

    return Storage::disk('public')->path('firme/prova.png');
}

test('senza_firma: la vista riceve firma_stampe_absolute_path a null anche con una firma configurata', function () {
    $percorsoFirma = configuraFirmaDiProva();

    $catturati = [];
    View::shouldReceive('make')->once()->andReturnUsing(function ($view, $data) use (&$catturati) {
        $catturati = $data;

        return new class
        {
            public function render()
            {
                return '<html></html>';
            }
        };
    });

    app(PdfService::class)->generate('pdf.base', ['senza_firma' => true]);

    expect($catturati['firma_stampe_absolute_path'])->toBeNull();
});

test('senza il flag, una firma configurata continua ad arrivare alla vista come sempre', function () {
    $percorsoFirma = configuraFirmaDiProva();

    $catturati = [];
    View::shouldReceive('make')->once()->andReturnUsing(function ($view, $data) use (&$catturati) {
        $catturati = $data;

        return new class
        {
            public function render()
            {
                return '<html></html>';
            }
        };
    });

    app(PdfService::class)->generate('pdf.base', []);

    expect($catturati['firma_stampe_absolute_path'])->toBe($percorsoFirma);
});
