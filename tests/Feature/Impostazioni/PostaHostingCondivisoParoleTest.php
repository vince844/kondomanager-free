<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 720 (decisione 578, 2) — le due frasi sulla posta degli hosting condivisi (Altervista: porte 465 e 587 chiuse in uscita, proc_open spenta).
 */

use App\Enums\Role;
use App\Models\MailLog;
use App\Models\User;
use App\Settings\MailSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $posta = app(MailSettings::class);
    $posta->mail_enabled = true;
    $posta->mail_driver = 'sendmail';
    $posta->mail_from_address = 'condominio@vero.example';
    $posta->mail_from_name = 'Studio';
    $posta->save();
    $this->amministratore = User::factory()->create(['email_verified_at' => now()]);
    $this->amministratore->assignRole(Role::AMMINISTRATORE->value);
});

function provaSendmailConErrore($test, string $errore)
{
    // Il trasporto finto: lancia l'eccezione che PHP dà quando l'hosting ha spento proc_open.
    Event::listen(MessageSending::class, function () use ($errore) {
        throw new RuntimeException($errore);
    });

    return $test->actingAs($test->amministratore)->withSession(['auth.password_confirmed_at' => time()])
        ->postJson(route('admin.settings.mail.test'), [
            'mail_driver' => 'sendmail',
            'mail_from_address' => 'condominio@vero.example',
            'mail_from_name' => 'Studio',
            'test_email' => 'prova@vero.example',
        ]);
}

it('Coda 720 — la scheda di Sendmail non consiglia più Gmail SMTP e dice che su un hosting condiviso i server esterni sono bloccati', function (string $lingua) {
    $frase = trans('impostazioni.dialogs.sendmail_guide_description', [], $lingua);

    expect($frase)->not->toContain('Gmail SMTP');
})->with(['it', 'en', 'pt', 'es']);

it('Coda 720 — in italiano la scheda di Sendmail dice la frase nuova', function () {
    expect(trans('impostazioni.dialogs.sendmail_guide_description', [], 'it'))
        ->toContain('Su un hosting condiviso come Altervista i server di posta esterni sono bloccati: scarica i PDF e mandali dal tuo programma di posta.');
});

it('Coda 720 — con proc_open spenta la prova di Sendmail dice in parole che l\'hosting non permette di lanciare sendmail', function () {
    $risposta = provaSendmailConErrore($this, 'proc_open() has been disabled for security reasons')->assertStatus(500);

    $risposta->assertJsonPath('motivo.titolo', 'Questo hosting non permette di lanciare il programma sendmail.');
    $risposta->assertJsonPath('message', 'proc_open() has been disabled for security reasons');
});

it('Coda 720 — gli altri errori di Sendmail restano come sono', function () {
    $risposta = provaSendmailConErrore($this, 'Qualcosa è andato storto')->assertStatus(500);

    $risposta->assertJsonPath('motivo.titolo', 'Il server ha rifiutato la prova.');
});
