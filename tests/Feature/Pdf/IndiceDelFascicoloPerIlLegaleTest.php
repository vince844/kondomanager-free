<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * L'indice del fascicolo per il legale va al legale: dice i fatti dell'accordo di rientro (la decadenza registrata, con la sua nota) e mette nell'archivio la
 * stampa dell'accordo anche senza la scansione firmata; non parla del programma e non dà istruzioni all'amministratore, che restano nella finestra.
 *
 * Neri (`AccordoCalendarioScenario.php`): accordo del 15/07/2026 con il calendario, versamento del 31/07, decadenza registrata il 02/10/2026.
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
    foreach (['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Modifica scadenza in agenda'] as $n) {
        $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => $n, 'guard_name' => 'web']));
    }
    $this->user = \App\Models\User::factory()->create();
    $this->user->assignRole($ruolo);
    $this->withoutVite();
    Storage::fake('local');
    Storage::fake('public');
});

function idflTesto(string $pdf): string
{
    $file = tempnam(sys_get_temp_dir(), 'idfl') . '.pdf';
    file_put_contents($file, $pdf);
    $t = (string) shell_exec('pdftotext -raw ' . escapeshellarg($file) . ' - 2>/dev/null');
    @unlink($file);

    return trim((string) preg_replace('/\s+/u', ' ', str_replace(["\u{00A0}", 'ﬁ', 'ﬂ'], [' ', 'fi', 'fl'], $t)));
}

it('l\'indice dice la decadenza registrata dell\'accordo, l\'archivio ha la stampa dell\'accordo, e il PDF non parla del programma né dà istruzioni', function () {
    [$s, $interessi, $sollecito, $a] = aclAccordo($this);
    aclVersa($this, $s, [adbQuota($interessi)->id => 92, adbQuota($sollecito)->id => 2500, lcdQuota($s, 'Neri', 1) => 4939], '2026-07-31');
    $this->travelTo('2026-10-02 10:00:00');
    $this->actingAs($this->user)->postJson(route('admin.gestionale.accordi-rientro.decadenza', ['condominio' => $s['c']->id, 'accordo' => $a->id]),
        ['data' => '2026-10-02', 'nota' => 'Comunicata con la raccomandata del 02/10'])->assertOk();

    $rotta = fn (string $n) => route('admin.gestionale.' . $n, ['condominio' => $s['c']->id, 'anagrafica' => $s['persone']['Neri']->id]);
    $ant = $this->actingAs($this->user)->getJson($rotta('anagrafiche.fascicolo-legale'))->assertOk()->json();
    $rate = collect($ant['esercizi'])->flatMap(fn ($e) => array_column($e['rate'], 'chiave'))->all();
    $r = $this->actingAs($this->user)->post($rotta('anagrafiche.fascicolo-legale.prepara'), ['rate' => $rate, 'parti' => $ant['parti']]);
    $zipFile = tempnam(sys_get_temp_dir(), 'idfl');
    file_put_contents($zipFile, $r->streamedContent());
    $zip = new ZipArchive;
    $zip->open($zipFile);
    $nomi = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $nomi[] = (string) $zip->getNameIndex($i);
    }
    $indice = idflTesto((string) $zip->getFromName('00 Indice.pdf'));
    $zip->close();
    @unlink($zipFile);

    expect($indice)->toContain('Accordo di rientro del 15/07/2026')
        ->toContain('decadenza registrata il 02/10/2026')
        ->toContain('Comunicata con la raccomandata del 02/10')
        ->not->toContain('programma')
        ->not->toContain('scrivila');
    expect(collect($nomi)->contains(fn ($n) => str_contains(mb_strtolower($n), 'accordo')))->toBeTrue();
});
