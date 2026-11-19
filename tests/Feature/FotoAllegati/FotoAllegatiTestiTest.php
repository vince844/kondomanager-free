<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # La foto dal telefono negli allegati: i testi (Coda 590, decisione 293)
 *
 * Il pulsante «Scatta una foto» e la preparazione delle immagini sono nel browser, e li provano due vitest
 * (`resources/js/components/allegati/AllegatiCampoModuliFoto.test.ts` e
 * `resources/js/lib/allegati/preparaImmagine.test.ts`). Dalla decisione 531 anche il server toglie i dati della foto, in ogni caso
 * (`FotoAllegatiSenzaDatiTest.php`): la frase «ripulite dei dati di posizione» è vera anche quando il browser non riesce a
 * ricodificare la foto. Qui si prova che i tre testi nuovi stanno nei file di lingua, nelle quattro lingue, e che il server
 * accetta gli stessi formati di prima.
 */

use App\Support\Allegati\RegoleAllegati;
use Illuminate\Support\Facades\Lang;

test('i tre testi della foto stanno nei file di lingua, nelle quattro lingue', function () {
    $chiavi = ['allegati.upload.take_photo', 'allegati.upload.preparing', 'allegati.upload.photo_hint'];
    foreach ($chiavi as $chiave) {
        foreach (['it', 'en', 'es', 'pt'] as $lingua) {
            expect(Lang::hasForLocale($chiave, $lingua))->toBeTrue("Manca {$chiave} in {$lingua}");
        }
    }

    expect(__('allegati.upload.take_photo', [], 'it'))->toBe('Scatta una foto')
        ->and(__('allegati.upload.preparing', [], 'it'))->toBe('Preparo le foto…')
        // Senza «prima dell'invio» (decisione di chi coordina il giro B, dopo la 531): quando il browser non riesce a ricodificarla la foto parte com'è e
        // la ripulisce il server prima di salvarla; così la frase è vera sempre.
        ->and(__('allegati.upload.photo_hint', [], 'it'))->toBe('Le foto vengono rimpicciolite e ripulite dei dati di posizione.');
    foreach (['en' => 'before sending', 'es' => 'antes del envío', 'pt' => 'antes do envio'] as $lingua => $prima) {
        expect(__('allegati.upload.photo_hint', [], $lingua))->not->toContain($prima);
    }

    foreach (['en', 'es', 'pt'] as $lingua) {
        foreach ($chiavi as $chiave) {
            expect(__($chiave, [], $lingua))->not->toBe(__($chiave, [], 'it'), "{$chiave} in {$lingua} è rimasta in italiano");
        }
    }
});

test('il server accetta gli stessi formati di prima', function () {
    expect(RegoleAllegati::FORMATI)->toBe(['jpg', 'jpeg', 'png', 'webp', 'pdf']);
});
