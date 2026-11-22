<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il testo del messaggio PEC nella riga del registro degli invii. La riga di una lettera ha già `testo` (il testo della lettera con i campi riempiti, che la
 * lettera rifatta e il PDF leggono): il testo del messaggio sta in `testo_del_messaggio`, e unire i dati non lo perde. L'oggetto resta in `oggetto`, perché il
 * controllo della ricevuta lo legge.
 */

use App\Models\Anagrafica;
use App\Models\Gestionale\RegistroInvio;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\Posta\DocumentoPerPec;
use App\Services\Posta\InvioPerPec;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/LetterePecScenario.php';

beforeEach(function () {
    Storage::fake(app(ArchivioDocumenti::class)->nomeDisco());
    LetterePec::conLaCasella();
});

it('una lettera mandata per PEC tiene nella riga il testo della lettera e, a parte, il testo del messaggio', function () {
    $persona = Anagrafica::factory()->create(['nome' => 'Ugo', 'pec' => 'ugo@pec.it']);
    $condominio = \App\Models\Condominio::factory()->create();
    $documento = new DocumentoPerPec((int) $condominio->id, (int) $persona->id, 'ugo@pec.it', 'diffida', $persona, '%PDF-1.4 prova', 'diffida-ugo',
        'Condominio di prova — Diffida', "Gentile Ugo,\n\nIn allegato la diffida.", ['lettera' => 'l-1', 'testo' => 'Il testo della diffida, con i campi riempiti.']);

    $riga = app(InvioPerPec::class)->invia($documento, null);

    expect($riga->stato)->toBe(RegistroInvio::STATO_INVIATA)
        ->and($riga->dati['testo'])->toBe('Il testo della diffida, con i campi riempiti.')
        ->and($riga->dati['testo_del_messaggio'])->toBe("Gentile Ugo,\n\nIn allegato la diffida.")
        ->and($riga->dati['oggetto'])->toBe('Condominio di prova — Diffida')
        ->and($riga->dati['dalla_casella'])->toBe('studio.costa@pec.it')
        ->and(LetterePec::$mandate[0]['messaggio']->getTextBody())->toBe("Gentile Ugo,\n\nIn allegato la diffida.");
});
