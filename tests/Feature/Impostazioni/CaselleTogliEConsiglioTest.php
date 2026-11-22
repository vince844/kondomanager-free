<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Role;
use App\Models\RegistroModifica;
use App\Models\User;
use App\Services\Posta\PecDelloStudio;
use App\Services\Posta\CasellaPec;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use App\Settings\MailSettings;
use App\Settings\PecSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;

/**
 * 1.12.0-beta.13 (decisione 577 (2) e (3), e le domande della pagina delle caselle):
 *
 * - «Togli la casella PEC»: con la conferma della password, la casella torna «Da impostare», le PEC non partono e «Manda le PEC» lo dice; una riga del registro
 *   delle modifiche, con la password «cambiata».
 * - Dopo l'aggiornamento la posta mai provata mostra un consiglio, non un allarme: «Fai una prova quando puoi: gli invii continuano.».
 * - Con `posta_dell_installazione` accesa il programma non usa la posta salvata dallo studio: le email partono con quella dell'installazione.
 * - «Questa installazione» ha la riga della posta dell'installazione.
 *
 * Che cosa non copre: la pagina (vitest e la prova a video).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
    $this->andrea = User::factory()->create(['name' => 'Andrea Costa', 'email' => 'andrea.costa@studiocosta.it', 'email_verified_at' => now()]);
    $this->andrea->assignRole(Role::AMMINISTRATORE->value);
});

function ctcAndrea(object $test)
{
    return $test->actingAs($test->andrea)->withSession(['auth.password_confirmed_at' => time()]);
}

function ctcCasellaSalvata(): void
{
    $pec = app(PecSettings::class);
    $pec->indirizzo = 'andrea.costa@pec.studiocosta.it';
    $pec->server = 'smtps.pec.ilgestore.it';
    $pec->porta = 465;
    $pec->cifratura = 'ssl';
    $pec->utente = 'andrea.costa@pec.studiocosta.it';
    $pec->password = Crypt::encryptString('segreto-pec');
    $pec->impostata_il = now()->toIso8601String();
    $pec->save();
}

it('«Togli la casella PEC»: la casella torna «Da impostare», «Manda le PEC» lo dice, e c\'è una riga del registro', function () {
    ctcCasellaSalvata();
    expect(app(CasellaPec::class)->impostata())->toBeTrue();
    $prima = (int) (RegistroModifica::query()->max('id') ?? 0);

    ctcAndrea($this)->delete(route('admin.settings.pec.destroy'))->assertRedirect()->assertSessionHasNoErrors();

    expect(app(CasellaPec::class)->impostata())->toBeFalse()
        ->and(app(PecDelloStudio::class)->perche())->toBe(PecDelloStudio::NON_IMPOSTATA);
    $caselle = ctcAndrea($this)->get(route('impostazioni.mail'))->viewData('page')['props']['caselle'];
    expect($caselle['pec']['stato'])->toBe('da_impostare')->and($caselle['pec']['password_impostata'])->toBeFalse();
    $riga = RegistroModifica::query()->where('id', '>', $prima)->orderByDesc('id')->first();
    expect($riga)->not->toBeNull()
        ->and(collect($riga->campi)->firstWhere('chiave', 'password')['dopo'] ?? null)->toBe('cambiata')
        ->and(collect($riga->campi)->firstWhere('chiave', 'indirizzo')['prima'] ?? null)->toBe('andrea.costa@pec.studiocosta.it');
});

it('«Togli la casella PEC» chiede la password ridigitata da poco, e il permesso delle impostazioni', function () {
    ctcCasellaSalvata();
    $this->actingAs($this->andrea)->delete(route('admin.settings.pec.destroy'))->assertStatus(302);
    expect(app(CasellaPec::class)->impostata())->toBeTrue();

    $collaboratore = User::factory()->create(['email_verified_at' => now()]);
    $collaboratore->assignRole(Role::COLLABORATORE->value);
    $this->actingAs($collaboratore)->withSession(['auth.password_confirmed_at' => time()])->delete(route('admin.settings.pec.destroy'))->assertForbidden();
    expect(app(CasellaPec::class)->impostata())->toBeTrue();
});

it('la posta mai provata dopo l\'aggiornamento ha un consiglio, non un allarme: gli invii continuano', function () {
    $posta = app(MailSettings::class);
    $posta->mail_enabled = true;
    $posta->mail_driver = 'smtp';
    $posta->mail_host = 'smtp.studiocosta.it';
    $posta->mail_port = 587;
    $posta->mail_username = 'segreteria@studiocosta.it';
    $posta->mail_password = Crypt::encryptString('segreta');
    $posta->mail_encryption = 'tls';
    $posta->mail_from_address = 'segreteria@studiocosta.it';
    $posta->save();

    $caselle = ctcAndrea($this)->get(route('impostazioni.mail'))->viewData('page')['props']['caselle'];
    expect($caselle['posta']['stato'])->toBe('da_provare')
        ->and($caselle['posta']['consiglio'])->toBe('Fai una prova quando puoi: gli invii continuano.')
        ->and($caselle['pec']['consiglio'] ?? null)->toBeNull();
});

it('con la posta dell\'installazione accesa il programma non usa la posta salvata dallo studio', function () {
    $posta = app(MailSettings::class);
    $posta->mail_enabled = true;
    $posta->mail_driver = 'smtp';
    $posta->mail_host = 'smtp.vecchio-studio.it';
    $posta->mail_port = 587;
    $posta->save();
    Config::set('mail.default', 'smtp');
    Config::set('mail.mailers.smtp.host', 'smtp.installazione.example');
    $riflesso = new ReflectionClass(\App\Providers\MailConfigServiceProvider::class);
    $riflesso->setStaticPropertyValue('defaultConfig', null);
    config(['kondomanager.posta_dell_installazione' => true]);

    app(\App\Providers\MailConfigServiceProvider::class, ['app' => app()])->boot();

    expect(config('mail.mailers.smtp.host'))->toBe('smtp.installazione.example')->and(config('mail.default'))->toBe('smtp');
    $riflesso->setStaticPropertyValue('defaultConfig', null);
});

it('«Questa installazione» dice se la posta la prepara chi gestisce l\'installazione, nelle quattro lingue', function () {
    foreach (['it', 'en', 'es', 'pt'] as $lingua) {
        $voci = require base_path('lang/' . $lingua . '/impostazioni.php');
        expect($voci['installazione']['funzione_posta_dell_installazione'] ?? null)->toBeString()->not->toBe('');
    }
    expect((require base_path('lang/it/impostazioni.php'))['installazione']['funzione_posta_dell_installazione'])
        ->toBe('La posta la prepara chi gestisce l\'installazione');
    expect((string) file_get_contents(resource_path('js/pages/impostazioni/impostazioniInstallazione.vue')))->toContain('\'posta_dell_installazione\'');
});
