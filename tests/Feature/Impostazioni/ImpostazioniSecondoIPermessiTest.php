<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La pagina «Impostazioni» si apre a chi può aprire almeno una delle sue schede, e mostra solo quelle.
 *
 * Ogni scheda chiede ciò che chiede la rotta che apre: l'elenco sta una volta sola nel controller
 * (`ImpostazioniController::SCHEDE`), e la pagina riceve le chiavi delle schede da mostrare. Le rotte delle schede non cambiano:
 * la pagina mostra, non apre. Qui si prova che ogni scheda mostrata si apre davvero (mai un 403 dietro una scheda) e che la voce
 * del menu chiede gli stessi permessi dell'elenco.
 */

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Http\Controllers\Impostazioni\ImpostazioniController;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function conRuolo(string $ruolo): User
{
    $utente = User::factory()->create(['email_verified_at' => now()]);
    $utente->assignRole($ruolo);

    return $utente->fresh();
}

/** Le schede che la pagina riceve. */
function schedeDi(User $utente): array
{
    return test()->actingAs($utente)->get(route('impostazioni'))->assertOk()->viewData('page')['props']['schede'];
}

/** L'indirizzo che apre ogni scheda, come nella pagina. */
const INDIRIZZI_DELLE_SCHEDE = [
    'generali'      => '/impostazioni/generali',
    'registro'      => '/impostazioni/registro-modifiche',
    'stampe'        => '/impostazioni/stampe',
    'assemblee'     => '/impostazioni/assemblee',
    'utenti'        => '/utenti',
    'posta'         => '/impostazioni/mail',
    'cron'          => '/impostazioni/cron',
    'errori'        => '/logs',
    'backup'        => '/impostazioni/backups',
    'installazione' => '/impostazioni/installazione',
    'aggiornamento' => '/system/upgrade',
];

it('il collaboratore apre la pagina e vede solo le schede che può aprire', function () {
    $collaboratore = conRuolo(RoleEnum::COLLABORATORE->value);

    expect(schedeDi($collaboratore))->toBe(['utenti']);
});

it('l\'amministratore vede tutte le schede', function () {
    expect(schedeDi(conRuolo(RoleEnum::AMMINISTRATORE->value)))->toBe(array_keys(ImpostazioniController::SCHEDE));
    expect(array_keys(ImpostazioniController::SCHEDE))->toBe(array_keys(INDIRIZZI_DELLE_SCHEDE));
});

it('un condòmino e un fornitore ricevono 403', function (string $ruolo) {
    $this->actingAs(conRuolo($ruolo))->get(route('impostazioni'))->assertForbidden();
})->with([RoleEnum::UTENTE->value, RoleEnum::FORNITORE->value]);

it('un ruolo su misura con il solo registro delle modifiche vede solo il registro', function () {
    $ruolo = Role::create(['name' => 'revisore', 'guard_name' => 'web', 'description' => 'Rilegge']);
    $ruolo->givePermissionTo([PermissionEnum::ACCESS_ADMIN_PANEL->value, PermissionEnum::VIEW_REGISTRO_MODIFICHE->value]);

    expect(schedeDi(conRuolo('revisore')))->toBe(['registro']);
});

it('un ruolo su misura con le impostazioni generali vede le schede delle impostazioni, non quelle dell\'amministratore', function () {
    $ruolo = Role::create(['name' => 'tecnico', 'guard_name' => 'web', 'description' => 'Tecnico']);
    $ruolo->givePermissionTo([PermissionEnum::ACCESS_ADMIN_PANEL->value, PermissionEnum::MANAGE_GENERAL_SETTINGS->value]);

    expect(schedeDi(conRuolo('tecnico')))->toBe(['generali', 'stampe', 'posta', 'cron', 'errori', 'backup', 'installazione']);
});

it('ogni scheda mostrata si apre: mai un 403 dietro una scheda', function (string $chi) {
    $ruoli = [
        'revisore' => [PermissionEnum::ACCESS_ADMIN_PANEL, PermissionEnum::VIEW_REGISTRO_MODIFICHE],
        'tecnico'  => [PermissionEnum::ACCESS_ADMIN_PANEL, PermissionEnum::MANAGE_GENERAL_SETTINGS],
    ];
    if (isset($ruoli[$chi])) {
        Role::create(['name' => $chi, 'guard_name' => 'web', 'description' => $chi])
            ->givePermissionTo(array_map(fn ($p) => $p->value, $ruoli[$chi]));
    }
    $utente = conRuolo($chi);

    foreach (schedeDi($utente) as $scheda) {
        $stato = $this->actingAs($utente)->get(INDIRIZZI_DELLE_SCHEDE[$scheda])->status();
        expect($stato)->not->toBe(403, "la scheda «{$scheda}» si mostra a {$chi} ma risponde 403");
    }
})->with(['collaboratore', 'revisore', 'tecnico', 'amministratore']);

it('la voce «Impostazioni» del menu chiede gli stessi permessi dell\'elenco delle schede', function () {
    $sorgente = file_get_contents(resource_path('js/components/AppHeader.vue'));
    expect(preg_match('/const permessiDelleImpostazioni = \[([^\]]*)\]/', $sorgente, $m))->toBe(1);
    preg_match_all('/Permission\.([A-Z_]+)/', $m[1], $nomi);

    $nelMenu = collect($nomi[1])->map(fn ($nome) => constant(PermissionEnum::class.'::'.$nome)->value)->sort()->values()->all();
    $nellElenco = collect(ImpostazioniController::SCHEDE)
        ->filter(fn ($requisito) => $requisito instanceof PermissionEnum)
        ->map(fn ($requisito) => $requisito->value)->unique()->sort()->values()->all();

    expect($nelMenu)->toBe($nellElenco);
    // Le schede che chiedono il ruolo: la voce lo chiede anche lei.
    expect($sorgente)->toMatch('/apreLeImpostazioni[\s\S]*hasRole\(\[\'amministratore\'\]\)/');
});
