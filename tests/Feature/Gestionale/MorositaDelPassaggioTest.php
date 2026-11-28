<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La morosità che il pannello del passaggio dice di chi esce è lo scaduto della lettura comune dei debiti (`LetturaDebiti::perPersona`) sulle
 * unità del passaggio, non la somma delle sole quote a debito.
 *
 * Il caso: Ugo ha un credito di € 600,00 dall'anno prima, ripartito su tutte le rate (− 5000 a rata), e il preventivo è € 1.200,00 (10000 a
 * rata): le sue quote sono 5000. Il 1/7 vende a Elsa, le bozze 7–12 passano a lei a 10000 e a lui resta la gemella del
 * credito, − 5000 per rata; il 1/9 Elsa rivende a Ugo (una ricompra), e le bozze 9–12 tornano a lui a 10000. Sulle rate 9–12 Ugo ha due quote
 * della stessa rata e unità: + 10000 e − 5000. Con le rate emesse fino al 5/11 e nessun incasso, oggi (24/11/2026): le rate 1–6 5000 × 6 =
 * 30000; le 7 e 8 solo il credito; le 9–11 10000 − 5000 = 5000 × 3 = 15000. Lo scaduto è 45000 (€ 450,00); la somma delle sole quote a debito
 * dava 30000 + 30000 = 60000 (€ 600,00), € 150,00 in più. È il seme 14 del laboratorio in piccolo.
 *
 * Cosa NON copre: la pagina Vue; MySQL; la nota di solidarietà (`NotaSolidarieta`), che conta l'emesso non pagato ed è un'altra misura.
 */

use App\Models\User;
use App\Services\Gestionale\Debiti\LetturaDebiti;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/Support/ScenariPassaggi.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware([\App\Http\Middleware\HandleInertiaRequests::class]);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

function mdpEmetti($test, array $s, string $fino): void
{
    $rate = DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('stato', '!=', 'emessa')->where('data_scadenza', '<=', $fino . ' 23:59:59')->pluck('id')->all();
    $test->actingAs($test->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $s['piano']]), [
        'rate_ids' => $rate, 'data_emissione' => $fino, 'invia_notifiche' => false,
    ])->assertSessionHasNoErrors();
}

function mdpVendita(int $riga, \App\Models\Anagrafica $entra, string $il): array
{
    return ruPassaggio('vendita', $riga, $entra, $il, 100) + ['ho_letto' => true, 'nota_cancello' => 'Rogito letto, passaggio registrato'];
}

/** La storia: la vendita del 1/7 a Elsa, la ricompra del 1/9, le rate emesse fino al 20/11; il modulo della vendita di Ugo a Carlo del 1/12. */
function mdpStoria($test): array
{
    $test->travelTo(\Carbon\CarbonImmutable::parse('2026-11-24 12:00:00'));
    $s = ruScenario('tutte_rate', -60000);
    mdpEmetti($test, $s, '2026-06-30');
    ruRegistra($test, $s, mdpVendita($s['rigaV'], $s['a'], '2026-07-01'));
    mdpEmetti($test, $s, '2026-08-31');
    $rigaA = (int) DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('anagrafica_id', $s['a']->id)->whereNull('data_fine')->value('id');
    ruRegistra($test, $s, mdpVendita($rigaA, $s['v'], '2026-09-01'));
    mdpEmetti($test, $s, '2026-11-20');
    $rigaV = (int) DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('anagrafica_id', $s['v']->id)->whereNull('data_fine')->value('id');
    $s['carlo'] = \App\Models\Anagrafica::forceCreate(['nome' => 'Carlo', 'email' => 'carlo-mdp@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'CRLMDP00000000001']);
    $s['carlo']->condomini()->syncWithoutDetaching([$s['c']->id]);
    $s['modulo'] = ruPassaggio('vendita', $rigaV, $s['carlo'], '2026-12-01', 100);

    return $s;
}

it('dopo una vendita e una ricompra sulla stessa rata, la morosità di Ugo nel pannello è lo scaduto della lettura comune, € 450,00, non € 600,00', function () {
    $s = mdpStoria($this);

    // Le due quote della stessa rata e unità: + 10000 e − 5000 sulle rate 9–12.
    $gemelle = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s['piano']->id)
        ->where('rate_quote.anagrafica_id', $s['v']->id)->where('rate.numero_rata', 9)->orderBy('rate_quote.importo')->pluck('rate_quote.importo')->map(fn ($c) => (int) $c)->all();
    expect($gemelle)->toBe([-5000, 10000]);

    $a = ruAnteprima($this, $s, $s['modulo']);
    $lettura = app(LetturaDebiti::class)->perPersona((int) $s['c']->id, (int) $s['v']->id);
    $scaduto = (int) collect($lettura['rate'])->filter(fn ($r) => (int) $r['immobile_id'] === (int) $s['unita']->id && $r['scaduta'])->sum('residuo');

    // La situazione debitoria della stessa persona (il modulo dell'incasso): le righe emesse e scadute con un residuo.
    $situazione = collect($this->actingAs($this->user)->getJson(route('admin.gestionale.situazione-debitoria', ['condominio' => $s['c']->id, 'anagrafica_id' => $s['v']->id]))->assertOk()->json('rate'))
        ->filter(fn ($r) => $r['is_emitted'] && $r['scaduta'] && $r['residuo'] > 0)->sum(fn ($r) => (int) round($r['residuo'] * 100));

    expect($scaduto)->toBe(45000)
        ->and($situazione)->toBe(45000)
        ->and($a['rate']['morosita']['importo'] ?? null)->toBe(45000)
        ->and($a['rate']['morosita']['importo_formattato'] ?? null)->toBe('€ 450,00');
});

it('senza gemelle la cifra non cambia: Elsa, prima della ricompra, ha le rate 7 e 8 scadute e non pagate, € 200,00', function () {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-24 12:00:00'));
    $s = ruScenario('tutte_rate', -60000);
    mdpEmetti($this, $s, '2026-06-30');
    ruRegistra($this, $s, mdpVendita($s['rigaV'], $s['a'], '2026-07-01'));
    mdpEmetti($this, $s, '2026-08-31');
    $rigaA = (int) DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('anagrafica_id', $s['a']->id)->whereNull('data_fine')->value('id');

    $a = ruAnteprima($this, $s, ruPassaggio('vendita', $rigaA, $s['v'], '2026-09-01', 100));

    expect($a['rate']['morosita']['importo'] ?? null)->toBe(20000);
});
