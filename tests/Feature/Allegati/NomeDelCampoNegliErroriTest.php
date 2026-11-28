<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * I messaggi d'errore degli allegati nominano l'allegato con una parola, non con il nome tecnico del campo («allegati.0»).
 * In ogni lingua del programma, con le regole vere degli allegati (`RegoleAllegati::regoleValidazioneFile`).
 */

use App\Support\Allegati\RegoleAllegati;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

dataset('lingue e parole', [
    'italiano'   => ['it', 'allegato'],
    'inglese'    => ['en', 'attachment'],
    'spagnolo'   => ['es', 'adjunto'],
    'portoghese' => ['pt', 'anexo'],
]);

it('un file di un formato non ammesso: il messaggio nomina l\'allegato, non «allegati.0»', function (string $lingua, string $parola) {
    app()->setLocale($lingua);

    $errori = Validator::make(
        ['allegati' => [UploadedFile::fake()->create('lettera.docx', 10)]],
        RegoleAllegati::regoleValidazioneFile(),
    )->errors()->all();

    expect($errori)->not->toBeEmpty();
    foreach ($errori as $messaggio) {
        expect($messaggio)->not->toContain('allegati.')->and(mb_strtolower($messaggio))->toContain($parola);
    }
})->with('lingue e parole');

it('un file troppo grande: il messaggio nomina l\'allegato, non «allegati.0»', function (string $lingua, string $parola) {
    app()->setLocale($lingua);

    $errori = Validator::make(
        ['allegati' => [UploadedFile::fake()->create('foto.jpg', 1024 * 1024)]],
        RegoleAllegati::regoleValidazioneFile(),
    )->errors()->all();

    expect($errori)->not->toBeEmpty();
    foreach ($errori as $messaggio) {
        expect($messaggio)->not->toContain('allegati.')->and(mb_strtolower($messaggio))->toContain($parola);
    }
})->with('lingue e parole');
