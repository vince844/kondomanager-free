<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il rendiconto con gli interessi di mora, letto da un condòmino: il riepilogo non mette la voce degli interessi fra le entrate da terzi a zero e dice
 * perché i versamenti del registro superano le quote pagate; due versioni fermate lo stesso giorno si distinguono dal frontespizio; la nota dice l'articolo
 * dell'imputazione e il comma dell'art. 1129, tiene il «%» accanto alla cifra, e non parla del programma; il frontespizio non dice al condòmino dove si scrive
 * il saldo della banca; la dichiarazione con due «nessuna» si legge.
 *
 * Via Roma (`RendicontoViaRomaScenario.php`) con gli interessi di mora del 20/12/2026: Bice € 0,92 versati il 28/12, Ugo € 1,18 non versati, al 01/03/2027.
 */

use App\Models\Documento;
use App\Models\Gestionale\RendicontoVersione;
use App\Services\Gestionale\Addebiti\AddebitiIndividuali;
use App\Services\Gestionale\NotaSinteticaDelRendiconto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__ . '/../Gestionale/RendicontoViaRomaScenario.php';

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

function rgiTesto(string $pdf, string $modo = '-raw'): string
{
    $file = tempnam(sys_get_temp_dir(), 'rgi') . '.pdf';
    file_put_contents($file, $pdf);
    $t = (string) shell_exec('pdftotext ' . $modo . ' ' . escapeshellarg($file) . ' - 2>/dev/null');
    @unlink($file);

    return trim((string) preg_replace('/\s+/u', ' ', str_replace(["\u{00A0}", 'ﬁ', 'ﬂ'], [' ', 'fi', 'fl'], $t)));
}

/** Via Roma al 01/03/2027 con gli interessi di Bice versati e quelli di Ugo no. */
function rgiViaRoma($test): array
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

function rgiFascicolo($test, array $s, array $parti): string
{
    $r = $test->actingAs($test->user)->get(route('admin.gestionale.esercizi.rendiconto.fascicolo', [$s['c'], $s['e']]) . '?' . http_build_query(['parti' => $parti]));
    $r->assertOk();

    return (string) $r->getContent();
}

it('nel riepilogo la voce degli interessi non sta fra le entrate da terzi a € 0,00, sopra «Interessi e spese versati dai condòmini € 0,92»', function () {
    $s = rgiViaRoma($this);
    $t = rgiTesto(rgiFascicolo($this, $s, ['riepilogo']), '-layout');

    expect($t)->toContain('Interessi e spese versati dai condòmini € 0,92')->not->toMatch('/Interessi di mora € 0,00 € 0,00/u');
});

it('nel riepilogo la differenza fra le quote pagate e i versamenti del registro è detta: sono gli interessi versati', function () {
    $s = rgiViaRoma($this);
    $t = rgiTesto(rgiFascicolo($this, $s, ['riepilogo']));

    expect($t)->toContain('i versamenti dei condòmini nel registro di contabilità di questo esercizio sono € 3.700,92')
        ->toMatch('/sono € 3\.700,92[^.]*€ 0,92 sono interessi e spese/u');
});

it('due versioni fermate lo stesso giorno non hanno lo stesso frontespizio: dicono l\'ora', function () {
    $s = rgiViaRoma($this);
    $prime = [];
    foreach ([11, 12] as $ora) {
        $this->travelTo(\Carbon\Carbon::parse("2027-03-01 {$ora}:00", 'Europe/Rome'));
        $this->actingAs($this->user)->from(route('admin.gestionale.esercizi.rendiconto.sintesi', [$s['c'], $s['e']]))
            ->post(route('admin.gestionale.esercizi.rendiconto.versioni.store', [$s['c'], $s['e']]), ['parti' => ['riepilogo']])->assertSessionHasNoErrors();
        $v = RendicontoVersione::where('esercizio_id', $s['e']->id)->latest('numero')->firstOrFail();
        $pdf = (string) Storage::disk('local')->get(Documento::findOrFail(collect($v->pdf['documenti'])->firstWhere('parte', 'fascicolo')['documento_id'])->path);
        $file = tempnam(sys_get_temp_dir(), 'rgiv') . '.pdf';
        file_put_contents($file, $pdf);
        $prime[] = (string) preg_replace('/\s+/u', ' ', (string) shell_exec('pdftotext -f 1 -l 1 -raw ' . escapeshellarg($file) . ' - 2>/dev/null'));
        @unlink($file);
    }

    expect($prime[0])->not->toBe($prime[1])
        ->and($prime[0])->toContain('01/03/2027 alle 11:00')
        ->and($prime[1])->toContain('01/03/2027 alle 12:00');
});

it('la nota dice l\'articolo dell\'imputazione agli interessi e non parla del «programma»', function () {
    $s = rgiViaRoma($this);
    $this->actingAs($this->user)->postJson(route('admin.gestionale.esercizi.rendiconto.nota.genera', [$s['c'], $s['e']]), ['nota' => ''])->assertOk();
    $t = rgiTesto((string) $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.nota.stampa', [$s['c'], $s['e']]))->getContent());

    expect($t)->toContain('prima agli interessi e alle spese')->toContain('art. 1194 c.c.')->not->toContain('il programma');
});

it('la nota cita l\'art. 1129 con il comma, e il «%» non va a capo da solo', function () {
    $s = rgiViaRoma($this);
    $nota = app(NotaSinteticaDelRendiconto::class);
    $this->actingAs($this->user)->postJson(route('admin.gestionale.esercizi.rendiconto.nota.genera', [$s['c'], $s['e']]), ['nota' => ''])->assertOk();
    $frasi = collect($nota->perLaStampa($s['c'], $s['e'], $nota->letture($s['c'], $s['e']))['parti'])->flatMap(fn ($p) => array_column($p['fatti'], 'testo'))->implode(' ');

    expect($frasi)->toContain('art. 1129, comma 9, c.c.')->toMatch("/\d\u{00A0}%/u")->not->toMatch('/\d %/u');
});

it('il frontespizio non dice al condòmino che «il saldo che dice la banca non è scritto»', function () {
    $s = rgiViaRoma($this);
    $t = rgiTesto(rgiFascicolo($this, $s, ['riepilogo']));

    expect($t)->toContain('I controlli')->not->toContain('non è scritto')->toContain('l\'amministratore non ha indicato il saldo dell\'estratto conto della banca');
});

it('la dichiarazione con due «nessuna» si legge: «… e che nessuna delibera …»', function () {
    $righe = NotaSinteticaDelRendiconto::dichiarazione(['liti' => ['stato' => 'nessuna'], 'impugnazioni' => ['stato' => 'nessuna']]);

    expect(implode(' ', $righe))->toContain('né contro il condominio e che nessuna delibera');
});
