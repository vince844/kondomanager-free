<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Con «Numero massimo di allegati per elemento» a 1 le frasi del limite dicono «1 allegato», non «1 allegati»: il riquadro della scheda
 * (`allegati.upload.no_slots`, in ogni lingua) e il rifiuto del caricamento (`RegoleAllegati::errorePostiRimasti`).
 */

use App\Settings\GeneralSettings;
use App\Support\Allegati\RegoleAllegati;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

dataset('lingue del limite', [
    'italiano'   => ['it', '1 allegato ', '1 allegati', '3 allegati'],
    'inglese'    => ['en', '1 attachment ', '1 attachments', '3 attachments'],
    'spagnolo'   => ['es', '1 adjunto', '1 adjuntos', '3 adjuntos'],
    'portoghese' => ['pt', '1 anexo ', '1 anexos', '3 anexos'],
]);

it('il riquadro della scheda dice «1 allegato» al singolare e «3 allegati» al plurale', function (string $lingua, string $uno, string $sbagliato, string $tre) {
    $singolare = trans_choice('allegati.upload.no_slots', 1, ['massimo' => 1], $lingua);
    $plurale = trans_choice('allegati.upload.no_slots', 3, ['massimo' => 3], $lingua);

    expect($singolare)->toContain($uno)
        ->and($singolare)->not->toContain($sbagliato)
        ->and($plurale)->toContain($tre);
})->with('lingue del limite');

it('il rifiuto del caricamento dice «1 allegato» quando il massimo è uno', function () {
    $impostazioni = app(GeneralSettings::class);
    $impostazioni->allegati_massimo_per_elemento = 1;
    $impostazioni->save();

    expect(RegoleAllegati::massimoPerElemento())->toBe(1)
        ->and(RegoleAllegati::errorePostiRimasti(1, 1))->toBe('È stato raggiunto il limite di 1 allegato: non se ne possono aggiungere altri.');

    $impostazioni->allegati_massimo_per_elemento = 3;
    $impostazioni->save();

    expect(RegoleAllegati::errorePostiRimasti(3, 1))->toBe('È stato raggiunto il limite di 3 allegati: non se ne possono aggiungere altri.');
});
