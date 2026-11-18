<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Commenti: i permessi e i ruoli di serie (Code 559 e 558, decisioni 261 e 268.2)
 *
 * Due permessi nuovi per le comunicazioni, «Modifica propri» ed «Elimina propri», come le segnalazioni. Il collaboratore
 * di serie tocca solo i suoi commenti, come per gli allegati; utente e fornitore commentano le comunicazioni come le
 * segnalazioni. Il seeder riallinea i ruoli di serie a ogni aggiornamento, anche togliendo.
 */

use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission as PermessoSpatie;
use Spatie\Permission\Models\Role as RuoloSpatie;

require_once __DIR__ . '/CommentiScenario.php';

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function ccmPermessiCommentiDelRuolo(string $ruolo): array
{
    $tutti = array_merge(array_values(CCM_PERMESSI), array_values(CCM_PERMESSI_SEGNALAZIONI));

    return RuoloSpatie::findByName($ruolo, 'web')->permissions->pluck('name')
        ->filter(fn ($p) => in_array($p, $tutti, true))->sort()->values()->all();
}

function ccmAttesi(array $azioni): array
{
    $nomi = [];
    foreach ($azioni as $azione) {
        $nomi[] = CCM_PERMESSI[$azione];
        $nomi[] = CCM_PERMESSI_SEGNALAZIONI[$azione];
    }
    sort($nomi);

    return $nomi;
}

test('i sedici permessi dei commenti esistono, con i due nuovi delle comunicazioni', function () {
    foreach (array_merge(array_values(CCM_PERMESSI), array_values(CCM_PERMESSI_SEGNALAZIONI)) as $nome) {
        expect(PermessoSpatie::where('name', $nome)->where('guard_name', 'web')->exists())->toBeTrue("Manca il permesso «{$nome}»");
    }
    expect(\App\Enums\Permission::tryFrom('Modifica propri commenti comunicazioni'))->not->toBeNull()
        ->and(\App\Enums\Permission::tryFrom('Elimina propri commenti comunicazioni'))->not->toBeNull();
});

test('l\'amministratore ha tutti i permessi dei commenti', function () {
    expect(ccmPermessiCommentiDelRuolo(Role::AMMINISTRATORE->value))
        ->toBe(ccmAttesi(['commenta', 'pubblica', 'approva', 'modifica', 'modifica_propri', 'elimina', 'elimina_propri', 'visualizza']));
});

test('il collaboratore commenta, pubblica, modifica ed elimina i suoi e vede; non approva e non tocca i commenti degli altri', function () {
    expect(ccmPermessiCommentiDelRuolo(Role::COLLABORATORE->value))
        ->toBe(ccmAttesi(['commenta', 'pubblica', 'modifica_propri', 'elimina_propri', 'visualizza']));
});

test('il condòmino e il fornitore commentano le comunicazioni come le segnalazioni', function (string $ruolo) {
    expect(ccmPermessiCommentiDelRuolo($ruolo))
        ->toBe(ccmAttesi(['commenta', 'pubblica', 'modifica_propri', 'elimina_propri', 'visualizza']));
})->with([Role::UTENTE->value, Role::FORNITORE->value]);

test('un aggiornamento toglie al collaboratore la modifica dei commenti degli altri che aveva prima', function () {
    $collaboratore = RuoloSpatie::findByName(Role::COLLABORATORE->value, 'web');
    $collaboratore->givePermissionTo(['Modifica commenti segnalazioni', 'Modifica commenti comunicazioni']);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    $this->seed(RolesAndPermissionsSeeder::class);

    $nomi = $collaboratore->fresh()->permissions->pluck('name')->all();
    expect($nomi)->not->toContain('Modifica commenti segnalazioni')
        ->and($nomi)->not->toContain('Modifica commenti comunicazioni')
        ->and($nomi)->toContain('Elimina propri commenti segnalazioni');
});
