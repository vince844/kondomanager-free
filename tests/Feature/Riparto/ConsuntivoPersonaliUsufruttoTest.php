<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 216, il consuntivo per persona — la spesa di una sola unità in usufrutto, su una gestione ordinaria.
 *
 * Il piano da fatture la dà all'usufruttuario dal giorno dell'atto, quando al passaggio si è scelto «l'ordinaria all'usufruttuario»
 * (decisione 731 (3), `CalcoloQuoteService::addebitaDiretto`); il consuntivo per persona la dava ancora al nudo proprietario, e piano e
 * consuntivo dicevano due persone diverse. Via Verdi: U3 è di Verdi; dal 01/05/2026 Verdi costituisce l'usufrutto a Rossi e resta nudo
 * proprietario. Il citofono di U3, € 80,00 del 10/09/2026, senza competenza.
 *
 * Che cosa non copre: MySQL (la suite gira su SQLite).
 */

use App\Models\Gestionale\Subentro;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__ . '/ConsuntivoPerPersonaPosizioneScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    foreach (['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche', 'Modifica anagrafiche', 'Elimina anagrafiche'] as $nome) {
        $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']));
    }
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** Via Verdi con l'usufrutto di U3 costituito a Rossi dal 01/05, con la scelta data, e il citofono di U3 del 10/09. */
function cpuScenario($test, string $scelta): array
{
    $s = cpcViaVerdi($test);
    $test->travelTo('2026-05-05 10:00:00');
    $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['U3']]), [
        'tipo' => 'usufrutto', 'sottotipo' => 'costituzione', 'riga_uscente_id' => $s['righe']['Verdi'], 'anagrafica_entrante_id' => $s['persone']['Rossi']->id,
        'decorrenza' => '2026-05-01', 'quota' => 100, 'tipologia' => 'usufruttuario', 'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true,
        'nota_cancello' => 'Costituzione dell\'usufrutto, letta', 'ordinaria_dopo_atto' => $scelta,
    ])->assertSessionHasNoErrors()->assertRedirect();
    cpcSpesaPersonale($s, 'U3', 8000, '2026-09-10');

    return $s;
}

it('Coda 216 — con «l\'ordinaria all\'usufruttuario» la spesa di U3 del 10/09 è di Rossi, usufruttuario, come nel piano; prima andava a Verdi, nudo proprietario', function () {
    $s = cpuScenario($this, Subentro::ORDINARIA_ALL_USUFRUTTUARIO);
    $c = cpcLeggi($this, $s);

    expect(cpcDi($c, $s, 'Rossi', 'U3')['personali']['non_chieste'] ?? null)->toBe(8000)
        ->and(cpcDi($c, $s, 'Verdi', 'U3')['personali']['non_chieste'] ?? 0)->toBe(0);
});

it('Coda 216 — con «come dice ogni voce» la spesa resta al nudo proprietario, come prima', function () {
    $s = cpuScenario($this, Subentro::ORDINARIA_COME_LA_VOCE);
    $c = cpcLeggi($this, $s);

    expect(cpcDi($c, $s, 'Verdi', 'U3')['personali']['non_chieste'] ?? null)->toBe(8000)
        ->and(cpcDi($c, $s, 'Rossi', 'U3')['personali']['non_chieste'] ?? 0)->toBe(0);
});

it('Coda 216 — una spesa di U3 prima dell\'atto (10/03) resta a Verdi, proprietario quel giorno, con tutte e due le scelte', function (string $scelta) {
    $s = cpuScenario($this, $scelta);
    cpcSpesaPersonale($s, 'U3', 3000, '2026-03-10');
    $c = cpcLeggi($this, $s);

    $verdi = collect(cpcDi($c, $s, 'Verdi', 'U3')['personali']['righe'] ?? [])->sum('importo');
    expect($verdi)->toBe($scelta === Subentro::ORDINARIA_ALL_USUFRUTTUARIO ? 3000 : 11000);
})->with([Subentro::ORDINARIA_ALL_USUFRUTTUARIO, Subentro::ORDINARIA_COME_LA_VOCE]);
