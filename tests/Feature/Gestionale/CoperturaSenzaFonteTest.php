<?php

/**
 * Una copertura di fattura pregressa registrata **senza `fonte_id`**.
 *
 * `StoreFatturaRequest:102` dichiara `coperture.*.fonte_id` come `nullable|integer`: il
 * campo può legittimamente non arrivare. `FatturaPassivaService` però lo leggeva senza
 * protezione in due dei tre rami — `rata_0` (`:318`) e `fondo_riserva` (`:320`) — mentre
 * il terzo, `sopravvenienza` (`:319`), aveva già il suo `?? null`. Registrando un
 * pregresso con copertura «Rata 0» senza fonte esplicita usciva quindi un
 * *Undefined array key «fonte_id»*.
 *
 * Non blocca la registrazione: la fattura si salva, e in PHP 8 l'accesso a una chiave
 * assente vale `null`, che è poi il valore giusto. Sporca però i log a ogni registrazione,
 * e su un'installazione con `display_errors` acceso l'avviso finisce **a schermo**,
 * davanti all'amministratore, in mezzo a un'operazione riuscita.
 *
 * Trovato il 02/08/2026 popolando i dati della guida del sito.
 *
 * I test convertono i warning in eccezioni, così un ritorno del difetto li fa fallire
 * invece di lasciare una riga in più nei log che nessuno legge.
 */

use App\Services\Gestionale\FatturaPassivaService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/GestionaleTestHelpers.php';

/**
 * Trasforma gli avvisi di PHP in eccezioni per la durata di una chiamata.
 *
 * Serve perché un `Undefined array key` non fa fallire niente da solo: senza questo,
 * il test passerebbe anche col difetto presente.
 */
function senzaWarningPhp(callable $fn): mixed
{
    set_error_handler(function (int $severity, string $message, string $file, int $line) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }, E_WARNING | E_NOTICE);

    try {
        return $fn();
    } finally {
        restore_error_handler();
    }
}

/** Dati di una fattura pregressa con una copertura del tipo richiesto, senza `fonte_id`. */
function pregressoConCopertura(array $ctx, string $tipoCopertura): array
{
    [$condominio, $esercizio, $gestione, $fornitore] = $ctx;

    return datiBase([$condominio, $esercizio, $gestione, $fornitore], [
        'data_documento' => '2024-12-01',
        'data_scadenza' => '2024-12-31',
        'is_pregresso' => true,
        'imponibile_pregresso' => 819.67,
        'aliquota_iva_pregressa' => 22,
        'righe' => [],
        'coperture' => [[
            'tipo_copertura' => $tipoCopertura,
            'importo' => 1000.00,
            // `fonte_id` volutamente assente: la Request lo ammette nullable.
        ]],
    ]);
}

test('copertura «rata_0» senza fonte_id: nessun avviso PHP', function () {
    $ctx = setupContabile();
    [$condominio] = $ctx;

    $fattura = senzaWarningPhp(fn () => (new FatturaPassivaService)->registraFattura(
        pregressoConCopertura($ctx, 'rata_0'),
        $condominio->id
    ));

    expect($fattura->coperture()->where('tipo_copertura', 'rata_0')->count())->toEqual(1);
    expect($fattura->coperture()->first()->saldo_id)->toBeNull();
});

test('copertura «fondo_riserva» senza fonte_id: nessun avviso PHP', function () {
    $ctx = setupContabile();
    [$condominio] = $ctx;

    $fattura = senzaWarningPhp(fn () => (new FatturaPassivaService)->registraFattura(
        pregressoConCopertura($ctx, 'fondo_riserva'),
        $condominio->id
    ));

    expect($fattura->coperture()->where('tipo_copertura', 'fondo_riserva')->count())->toEqual(1);
    expect($fattura->coperture()->first()->fondo_id)->toBeNull();
});

test('copertura «sopravvenienza» senza fonte_id: era già protetta e resta tale', function () {
    $ctx = setupContabile();
    [$condominio] = $ctx;

    $fattura = senzaWarningPhp(fn () => (new FatturaPassivaService)->registraFattura(
        pregressoConCopertura($ctx, 'sopravvenienza'),
        $condominio->id
    ));

    expect($fattura->coperture()->where('tipo_copertura', 'sopravvenienza')->count())->toEqual(1);
    expect($fattura->coperture()->first()->conto_id)->toBeNull();
});

test('con fonte_id valorizzato il legame viene comunque registrato', function () {
    $ctx = setupContabile();
    [$condominio, $esercizio, $gestione, $fornitore, , $contoFondo] = $ctx;

    $dati = datiBase([$condominio, $esercizio, $gestione, $fornitore], [
        'data_documento' => '2024-12-01',
        'data_scadenza' => '2024-12-31',
        'is_pregresso' => true,
        'imponibile_pregresso' => 819.67,
        'aliquota_iva_pregressa' => 22,
        'righe' => [],
        'coperture' => [[
            'tipo_copertura' => 'fondo_riserva',
            'importo' => 1000.00,
            'fonte_id' => $contoFondo->id,
        ]],
    ]);

    $fattura = senzaWarningPhp(fn () => (new FatturaPassivaService)->registraFattura($dati, $condominio->id));

    // La correzione non deve trasformare in `null` un legame che c'era.
    expect((int) $fattura->coperture()->first()->fondo_id)->toEqual($contoFondo->id);

    assertQuadraturaPerfetta($fattura->scritture->first()->id);
});

test('la registrazione riesce comunque: la fattura e la sua scrittura esistono', function () {
    $ctx = setupContabile();
    [$condominio] = $ctx;

    $fattura = senzaWarningPhp(fn () => (new FatturaPassivaService)->registraFattura(
        pregressoConCopertura($ctx, 'rata_0'),
        $condominio->id
    ));

    expect($fattura->id)->not->toBeNull();

    $scritturaId = $fattura->scritture->first()->id;
    assertQuadraturaPerfetta($scritturaId);

    expect(DB::table('righe_scritture')->where('scrittura_id', $scritturaId)->count())
        ->toBeGreaterThan(0);
});
