<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La scelta fra «Il programma suggerisce la prossima azione» e «Scelgo io la prossima azione» non sta più nelle impostazioni generali dello studio: è una scelta
 * del condominio, nella sua scheda «Impostazioni». Le impostazioni generali non la hanno più (né la pagina né il modulo), e la migrazione delle impostazioni
 * toglie la riga scritta prima; rieseguita non fa niente.
 */

use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => \App\Enums\Permission::MANAGE_GENERAL_SETTINGS->value, 'guard_name' => 'web']));
    Permission::firstOrCreate(['name' => \App\Enums\Permission::ACCESS_ADMIN_PANEL->value, 'guard_name' => 'web']);
    $this->admin = User::factory()->create();
    $this->admin->assignRole($ruolo);
    Role::firstOrCreate(['name' => 'utente', 'guard_name' => 'web']);
    $this->withoutVite();
});

function ippModulo(array $modifiche = []): array
{
    return array_merge(['user_frontend_registration' => false, 'force_comment_moderation' => false, 'language' => 'it', 'app_name' => 'Kondomanager',
        'open_condominio_on_login' => false, 'default_condominio_id' => null, 'default_user_role' => 'utente', 'default_per_page' => 10], $modifiche);
}

const IPP_RIGA = ['group' => 'general', 'name' => 'passo_proposto'];

it('le impostazioni generali non hanno più la scelta: né la classe, né la pagina, né il modulo', function () {
    expect(property_exists(GeneralSettings::class, 'passo_proposto'))->toBeFalse()
        ->and(DB::table('settings')->where(IPP_RIGA)->count())->toBe(0);

    $this->actingAs($this->admin)->get(route('impostazioni.generali'))->assertOk()
        ->assertInertia(fn ($p) => $p->missing('passo_proposto'));

    // Un modulo vecchio che la manda ancora non scrive niente.
    $this->actingAs($this->admin)->post(route('impostazioni.generali.store'), ippModulo(['passo_proposto' => false]))->assertSessionHasNoErrors();
    expect(DB::table('settings')->where(IPP_RIGA)->count())->toBe(0);
});

it('la migrazione toglie la riga scritta prima, e rieseguita non fa niente', function () {
    $path = database_path('settings/2026_11_15_200100_remove_passo_proposto_from_general_settings.php');
    expect(file_exists($path))->toBeTrue('Migrazione non trovata: 2026_11_15_200100_remove_passo_proposto_from_general_settings');
    $migration = require $path;

    // Come sul database dove la riga è già stata scritta.
    DB::table('settings')->insert(IPP_RIGA + ['locked' => false, 'payload' => json_encode(false), 'created_at' => now(), 'updated_at' => now()]);
    $migration->up();
    $migration->up();
    expect(DB::table('settings')->where(IPP_RIGA)->count())->toBe(0);

    $migration->down();
    expect(DB::table('settings')->where(IPP_RIGA)->count())->toBe(0)
        ->and(file_exists(database_path('settings/2026_11_15_140100_add_passo_proposto_to_general_settings.php')))->toBeFalse();
});
