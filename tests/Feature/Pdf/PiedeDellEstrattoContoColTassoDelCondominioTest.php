<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il piede dell'estratto conto non promette «gli interessi legali» quando il condominio ha scritto un altro tasso (il regolamento), e non cita l'art. 1135
 * c.c., che sono le attribuzioni dell'assemblea. Neri di Via Verdi (`AccessoriScenario.php`), con una regola del 5,00 % dal regolamento.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__ . '/../Gestionale/AccordoCalendarioScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    foreach (\App\Enums\Permission::cases() as $p) {
        $ruolo->givePermissionTo(Permission::findOrCreate($p->value, 'web'));
    }
    foreach (['Accesso pannello amministratore', 'Visualizza anagrafiche'] as $n) {
        $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => $n, 'guard_name' => 'web']));
    }
    $this->user = \App\Models\User::factory()->create();
    $this->user->assignRole($ruolo);
    $this->withoutVite();
    Storage::fake('local');
    Storage::fake('public');
});

function pdecTesto(string $pdf): string
{
    $file = tempnam(sys_get_temp_dir(), 'pdec') . '.pdf';
    file_put_contents($file, $pdf);
    $t = (string) shell_exec('pdftotext -raw ' . escapeshellarg($file) . ' - 2>/dev/null');
    @unlink($file);

    return trim((string) preg_replace('/\s+/u', ' ', str_replace(["\u{00A0}", 'ﬁ', 'ﬂ'], [' ', 'fi', 'fl'], $t)));
}

it('con il tasso del regolamento il piede non dice «interessi legali ai sensi dell\'art. 1284» né «art. 1135»', function () {
    $s = accViaVerdi($this);
    $this->actingAs($this->user)->post(route('admin.gestionale.recupero-crediti.store', [$s['c']]), ['dal' => '2026-01-01', 'tasso_tipo' => 'scritto', 'tasso_annuo' => '5',
        'fonte' => 'regolamento', 'riferimento' => 'art. 12 del regolamento contrattuale'])->assertSessionHasNoErrors();
    $this->travelTo('2026-06-29 10:00:00');
    accRegistra($this, $s, accProposta($this, $s, ['Neri'])->json())->assertCreated();
    $this->travelTo('2026-07-10 10:00:00');
    $t = pdecTesto((string) $this->actingAs($this->user)->get(route('admin.gestionale.anagrafiche.estratto-conto.print', [$s['c'], $s['persone']['Neri']]))->getContent());

    expect($t)->toContain('5,00 % annuo')
        ->not->toContain('interessi legali ai sensi dell\'art. 1284 c.c.')
        ->not->toContain('art. 1135');
});
