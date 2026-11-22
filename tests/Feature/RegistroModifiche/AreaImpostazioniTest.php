<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Role;
use App\Models\RegistroModifica;
use App\Models\User;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use App\Settings\MailSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;

/**
 * 1.12.0-beta.13 (Coda 679, gesto 8 e P20 del progetto): le impostazioni dello studio non hanno modelli e il pacchetto le salva con un `upsert`. Una riga
 * per salvataggio, un campo per impostazione cambiata con la parola della pagina; la password SMTP dice «cambiata» e i suoi valori non si scrivono;
 * salvare senza cambiare niente non scrive.
 *
 * Che cosa non copre: le impostazioni generali, delle stampe, dei backup e del tasso legale una per una (stesso ascoltatore, altre rotte); le impostazioni
 * scritte dall'installazione.
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
    $posta = app(MailSettings::class);
    $posta->mail_enabled = true;
    $posta->mail_driver = 'smtp';
    $posta->mail_host = 'smtp.vero.example';
    $posta->mail_port = 587;
    $posta->mail_username = 'utente@vero.example';
    $posta->mail_password = Crypt::encryptString('segreto-smtp-vero');
    $posta->mail_encryption = 'tls';
    $posta->mail_from_address = 'condominio@vero.example';
    $posta->mail_from_name = 'Studio';
    $posta->save();
    $this->andrea = User::factory()->create(['name' => 'Andrea Costa', 'email_verified_at' => now()]);
    $this->andrea->assignRole(Role::AMMINISTRATORE->value);
});

function aiCorpo(array $cambi = []): array
{
    return array_merge(['mail_enabled' => true, 'mail_driver' => 'smtp', 'mail_host' => 'smtp.vero.example', 'mail_port' => 587,
        'mail_username' => 'utente@vero.example', 'mail_password' => '', 'mail_encryption' => 'tls', 'mail_from_address' => 'condominio@vero.example',
        'mail_from_name' => 'Studio'], $cambi);
}

it('la posta salvata dalla pagina: una riga, il server cambiato con le sue parole, la password «cambiata» senza valori', function () {
    $prima = RegistroModifica::query()->max('id') ?? 0;

    $this->actingAs($this->andrea)->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('admin.settings.mail.update'), aiCorpo(['mail_host' => 'smtp.altrove.example', 'mail_password' => 'password-nuova']))
        ->assertSessionHasNoErrors();

    $righe = RegistroModifica::query()->where('id', '>', $prima)->get();
    $campi = collect($righe[0]->campi)->keyBy('chiave');
    expect($righe)->toHaveCount(1)
        ->and($righe[0]->area)->toBe('impostazioni')
        ->and($righe[0]->oggetto_nome)->toBe('Configurazione email')
        ->and($righe[0]->autore_nome)->toBe('Andrea Costa')
        ->and($righe[0]->condomini())->toBe([])
        // Le parole della pagina delle caselle dello studio (decisione 572, proposta 14).
        ->and($campi['mail_host'])->toMatchArray(['etichetta' => 'Server di posta in uscita', 'prima' => 'smtp.vero.example', 'dopo' => 'smtp.altrove.example'])
        ->and($campi['mail_password'])->toMatchArray(['etichetta' => 'Password', 'prima' => 'cambiata', 'dopo' => 'cambiata',
            'valore_prima' => null, 'valore_dopo' => null, 'segreto' => true])
        ->and(json_encode($righe[0]->campi))->not->toContain('password-nuova')
        ->and(json_encode($righe[0]->campi))->not->toContain('segreto-smtp-vero');
});

it('salvare la posta senza cambiare niente non scrive righe', function () {
    $prima = RegistroModifica::query()->max('id') ?? 0;

    $this->actingAs($this->andrea)->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('admin.settings.mail.update'), aiCorpo())->assertSessionHasNoErrors();

    expect(RegistroModifica::query()->where('id', '>', $prima)->count())->toBe(0);
});
