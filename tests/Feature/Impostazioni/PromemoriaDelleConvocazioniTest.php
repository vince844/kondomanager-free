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
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * 1.12.0-beta.13 (Coda 679, la convocazione): «Promemoria per email delle convocazioni da spedire» nelle impostazioni della posta.
 *
 * - Acceso di serie; la pagina lo legge con la riga «Adesso», che dice anche quando senza un server di posta il promemoria non parte.
 * - Si cambia da solo, senza salvare la casella: è dello studio, anche dove la posta la prepara chi gestisce l'installazione; non chiede la password
 *   ridigitata (non manda la posta altrove). Il cambio è una riga della «Storia» della pagina, con le sue parole.
 * - Senza il permesso delle impostazioni, 403.
 * - La migrazione delle impostazioni rieseguita non riaccende un interruttore spento.
 *
 * Che cosa non copre: il promemoria che parte (`PromemoriaDellaConvocazioneTest`); la pagina a video (vitest).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->andrea = User::factory()->create(['name' => 'Andrea Costa', 'email' => 'andrea.costa@studiocosta.it', 'email_verified_at' => now()]);
    $this->andrea->assignRole(Role::AMMINISTRATORE->value);
});

function pdcPromemoriaDellaPagina(object $test): array
{
    return $test->actingAs($test->andrea)->get(route('impostazioni.mail'))->assertOk()->viewData('page')['props']['promemoria'];
}

it('acceso di serie: la pagina lo dice con la riga «Adesso»; senza un server di posta dice che non parte', function () {
    expect(app(MailSettings::class)->promemoria_convocazioni)->toBeTrue()
        ->and(pdcPromemoriaDellaPagina($this))->toBe(['acceso' => true, 'adesso' => 'Adesso: acceso. A chi ha preparato l\'assemblea, due giorni lavorativi '
            . 'prima del giorno limite per spedire la convocazione e la mattina di quel giorno, se restano avvisi da spedire.']);

    // Senza un server di posta (il trasporto `log`): l'interruttore resta acceso, e la riga dice che il promemoria non parte.
    Config::set('mail.default', 'log');
    expect(pdcPromemoriaDellaPagina($this))->toBe(['acceso' => true, 'adesso' => 'Adesso: acceso, ma senza un server di posta il promemoria non parte.']);
});

it('spento dalla pagina: l\'impostazione cambia, la «Storia» lo dice con le sue parole, senza la password ridigitata', function () {
    $prima = RegistroModifica::query()->max('id') ?? 0;

    $this->actingAs($this->andrea)->withSession(['auth.password_confirmed_at' => 0])
        ->post(route('admin.settings.mail.promemoria'), ['acceso' => false])->assertRedirect()->assertSessionHasNoErrors();

    $righe = RegistroModifica::query()->where('id', '>', $prima)->get();
    expect(app(MailSettings::class)->refresh()->promemoria_convocazioni)->toBeFalse()
        ->and(pdcPromemoriaDellaPagina($this))->toBe(['acceso' => false, 'adesso' => 'Adesso: spento. Il compito resta nell\'Action inbox, senza email.'])
        ->and($righe)->toHaveCount(1)
        ->and($righe[0]->oggetto_tipo)->toBe('impostazioni_posta')
        ->and(collect($righe[0]->campi)->keyBy('chiave')['promemoria_convocazioni'])
        ->toMatchArray(['etichetta' => 'Promemoria per email delle convocazioni da spedire', 'prima' => 'sì', 'dopo' => 'no']);

    $this->actingAs($this->andrea)->post(route('admin.settings.mail.promemoria'), ['acceso' => true])->assertSessionHasNoErrors();
    expect(app(MailSettings::class)->refresh()->promemoria_convocazioni)->toBeTrue();
});

it('si cambia anche dove la posta la prepara chi gestisce l\'installazione', function () {
    config(['kondomanager.posta_dell_installazione' => true]);

    $this->actingAs($this->andrea)->post(route('admin.settings.mail.promemoria'), ['acceso' => false])->assertSessionHasNoErrors();

    expect(app(MailSettings::class)->refresh()->promemoria_convocazioni)->toBeFalse();
});

it('senza il permesso delle impostazioni, 403; un valore che non è sì o no, 422', function () {
    $collaboratore = User::factory()->create(['email_verified_at' => now()]);
    $collaboratore->assignRole(Role::COLLABORATORE->value);

    $this->actingAs($collaboratore)->post(route('admin.settings.mail.promemoria'), ['acceso' => false])->assertForbidden();
    $this->actingAs($this->andrea)->postJson(route('admin.settings.mail.promemoria'), ['acceso' => 'forse'])->assertStatus(422);
    expect(app(MailSettings::class)->refresh()->promemoria_convocazioni)->toBeTrue();
});

it('la migrazione rieseguita non riaccende un interruttore spento, e lo rimette se manca', function () {
    $migrazione = require database_path('settings/2026_11_21_090000_add_promemoria_convocazioni_to_mail_settings.php');
    $valore = fn () => DB::table('settings')->where('group', 'mail')->where('name', 'promemoria_convocazioni')->value('payload');

    expect($valore())->toBe(json_encode(true));
    DB::table('settings')->where('group', 'mail')->where('name', 'promemoria_convocazioni')->update(['payload' => json_encode(false)]);
    $migrazione->up();
    expect($valore())->toBe(json_encode(false));

    DB::table('settings')->where('group', 'mail')->where('name', 'promemoria_convocazioni')->delete();
    $migrazione->up();
    expect($valore())->toBe(json_encode(true));
});
