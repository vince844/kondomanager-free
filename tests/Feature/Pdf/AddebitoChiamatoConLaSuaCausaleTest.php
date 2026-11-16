<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Un addebito a una persona sola (interessi, spese di sollecito, un contributo) è una rata del piano degli addebiti, una per addebito: nelle stampe che
 * legge il condòmino si chiama con la sua causale e «— addebito», mai «Rata n.1» o «rata 2», che si confonderebbero con le rate del preventivo; e non si
 * conta fra le rate scadute. Le stampe: l'estratto conto, la ricevuta, la lettera di serie, i crediti per anzianità, il foglio del conguaglio, la nota.
 *
 * Neri (`AccordoCalendarioScenario.php`): rata 1 di € 300,00 scaduta il 31/03/2026, € 100,00 versati; interessi al 29/06 € 0,92 e sollecito € 25,00,
 * addebitati il 29/06; accordo del 15/07 e versamento del 31/07 (€ 0,92, € 25,00 e € 49,39 della rata 1). Via Roma (`RendicontoViaRomaScenario.php`):
 * interessi di mora del 20/12/2026, Bice € 0,92 versati il 28/12, Ugo € 1,18 non versati (Ugo ha versato tutte le sue rate), al 01/03/2027.
 */

use App\Services\Gestionale\Addebiti\AddebitiIndividuali;
use App\Services\Gestionale\Lettere\TestiLettere;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__ . '/../Gestionale/AccordoCalendarioScenario.php';
require_once __DIR__ . '/../Gestionale/RendicontoViaRomaScenario.php';

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

/** Il testo di un PDF con pdftotext, con gli spazi di fila uniti. */
function acscTesto(string $pdf): string
{
    $file = tempnam(sys_get_temp_dir(), 'acsc') . '.pdf';
    file_put_contents($file, $pdf);
    $t = (string) shell_exec('pdftotext -raw ' . escapeshellarg($file) . ' - 2>/dev/null');
    @unlink($file);

    return trim((string) preg_replace('/\s+/u', ' ', str_replace(["\u{00A0}", 'ﬁ', 'ﬂ'], [' ', 'fi', 'fl'], $t)));
}

/** Neri dopo il versamento del 31/07: l'id dell'incasso. */
function acscNeri($test): array
{
    [$s, $interessi, $sollecito] = aclAccordo($test);
    $incasso = aclVersa($test, $s, [adbQuota($interessi)->id => 92, adbQuota($sollecito)->id => 2500, lcdQuota($s, 'Neri', 1) => 4939], '2026-07-31');
    $test->travelTo('2026-08-10 10:00:00');

    return [$s, $incasso];
}

/** Via Roma al 01/03/2027 con gli interessi di Bice versati e quelli di Ugo no. */
function acscViaRoma($test): array
{
    $s = vrmScenario($test);
    vrmGiorno($test, '2026-12-20');
    $voce = be52Voce($test, $s, $s['pc'], 'Interessi di mora', 'entrata', '0,00');
    $registra = fn (string $chi, string $u, int $importo) => app(AddebitiIndividuali::class)->registra($s['c'], $s['g'], $s['e'], [[
        'anagrafica_id' => $s['persone'][$chi]->id, 'immobile_id' => $s['unita'][$u]->id, 'importo' => $importo, 'causale' => 'Interessi di mora al 20/12/2026',
        'scadenza' => '2026-12-20', 'natura' => 'accessorio', 'tipo_accessorio' => 'interessi', 'base' => 'legge', 'riferimento' => 'artt. 1224 e 1284 c.c.',
        'conto_id' => $voce->id,
    ]], $test->user->id, '2026-12-20')[0];
    $bice = $registra('bice', 'C', 92);
    $registra('ugo', 'A', 118);
    vrmGiorno($test, '2026-12-28');
    vrmIncassa($test, $s, 'bice', [(int) DB::table('rate_quote')->where('rata_id', $bice->id)->value('id') => 92], '2026-12-28');
    vrmGiorno($test, '2027-03-01');

    return $s;
}

it('l\'estratto conto chiama gli interessi e le spese addebitati con la loro causale, non «Rata n.1» e «Rata n.2» accanto alla rata 1 del preventivo', function () {
    [$s] = acscNeri($this);
    $t = acscTesto((string) $this->actingAs($this->user)->get(route('admin.gestionale.anagrafiche.estratto-conto.print', [$s['c'], $s['persone']['Neri']]))->getContent());

    expect($t)->toContain('Rata n.1 (Scad. 31/03/2026)')
        ->toContain('Interessi di mora al 29/06/2026 — addebito (Scad. 29/06/2026)')
        ->toContain('Spese di sollecito — addebito (Scad. 29/06/2026)')
        ->not->toContain('Rata n.1 (Scad. 29/06/2026)')
        ->not->toContain('Rata n.2 (Scad. 29/06/2026)')
        // Gli incassi degli addebiti, anche quelli già scritti, si leggono con la causale.
        ->not->toContain('Incasso rata n.2');
});

it('la ricevuta non ripete la gestione nel titolo del piano degli addebiti', function () {
    [$s, $incasso] = acscNeri($this);
    $t = acscTesto((string) $this->actingAs($this->user)->post(route('admin.gestionale.ricevute.pdf-anteprima', ['condominio' => $s['c']->id]),
        ['incassi' => [$incasso], 'bollo' => 'assolta'])->getContent());

    expect($t)->toContain('Addebiti individuali')->not->toContain('Ordinaria 2026 — Ordinaria 2026');
});

it('la lettera di serie non chiama «rate» gli interessi e le spese addebitati che elenca', function () {
    [$s] = aclNeri($this);
    $this->travelTo('2026-07-10 10:00:00');
    foreach (['sollecito', 'diffida', 'messa_in_mora'] as $tipo) {
        $t = acscTesto((string) $this->actingAs($this->user)->post(route('admin.gestionale.lettere.pdf-anteprima', ['condominio' => $s['c']->id]), ['tipo' => $tipo,
            'persone' => [$s['persone']['Neri']->id], 'testo' => TestiLettere::DI_SERIE[$tipo], 'termine' => 15, 'indirizzi' => ['email']])->getContent());

        expect($t)->toContain('Interessi di mora al 29/06/2026 — addebito')->not->toContain('le rate indicate qui sotto');
    }
});

it('nei crediti per anzianità l\'interesse addebitato a Ugo si chiama con la sua causale, non «Addebiti individuali …, rata 2»', function () {
    $s = acscViaRoma($this);
    $r = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.fascicolo', [$s['c'], $s['e']]) . '?' . http_build_query(['parti' => ['condomini']]));
    $t = acscTesto((string) $r->assertOk()->getContent());

    expect($t)->toContain('Crediti per anzianità')
        ->toContain('Interessi di mora al 20/12/2026 — addebito')
        ->not->toContain('Addebiti individuali 2026 – Gestione ordinaria, rata');
});

it('il foglio di Ugo, che ha versato tutte le rate, non dice «rate già chieste» per € 1,18 di interessi; la nota non conta l\'interesse fra le rate scadute', function () {
    $s = acscViaRoma($this);
    $foglio = acscTesto((string) $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.conguaglio.foglio', [$s['c'], $s['e'], $s['persone']['ugo']->id]))->getContent());
    $this->actingAs($this->user)->postJson(route('admin.gestionale.esercizi.rendiconto.nota.genera', [$s['c'], $s['e']]), ['nota' => ''])->assertOk();
    $nota = acscTesto((string) $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.nota.stampa', [$s['c'], $s['e']]))->getContent());

    expect($foglio)->toContain('€ 1,18')->toContain('rate e addebiti già chiesti e non pagati')->not->toContain('rate già chieste e non pagate € 1,18');
    // Bice ha € 410,00 di rate scadute; gli interessi di Ugo (€ 1,18) sono detti a parte.
    expect($nota)->not->toContain('2 persone avevano rate scadute e non versate per € 411,18')
        ->toContain('1 persona aveva rate scadute e non versate per € 410,00')
        ->toMatch('/addebit[^.]*€ 1,18/u');
});
