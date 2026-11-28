<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 610 — la spesa di una sola unità con la data di un giorno, nel piano da fatture.
 *
 * Via Verdi (lo scenario del consuntivo per persona): U2 è di Bianchi fino al 30/06/2026, di Rossi dal 01/07 (il passaggio registrato). La fattura del
 * fabbro di € 120,00 del 10/03/2026, sulla sola U2, senza competenza scritta. Il consuntivo per persona la dà a chi era titolare il giorno della fattura
 * (Bianchi, decisione 329 (2)); il piano da fatture la divideva sui giorni dell'esercizio: € 59,51 a Bianchi (181 giorni) e € 60,49 a Rossi (184), e il
 * conguaglio spostava € 60,49 fra i due. Ora il piano la chiede tutta a Bianchi. Con la competenza scritta dal 01/01 al 31/12 si divide per giorni come
 * prima (decisione 329 (1)). La spesa divisa su una voce (non sull'unità) non cambia.
 *
 * Che cosa non copre: MySQL (la suite gira su SQLite).
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Gestionale\PianoRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__ . '/../Riparto/ConsuntivoPerPersonaPosizioneScenario.php';

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

/** Via Verdi con il passaggio di U2 dal 01/07, la fattura del fabbro su U2 e il piano da fatture che la chiede, generato. */
function b15sgScenario($test, ?string $competenzaDal = null, ?string $competenzaAl = null, string $data = '2026-03-10', int $importo = 12000): array
{
    $s = cpcViaVerdi($test);
    cpcPassaggio($test, $s);
    $s['riga'] = cpcSpesaPersonale($s, 'U2', $importo, $data);
    $fatturaId = (int) DB::table('righe_fattura')->where('id', $s['riga'])->value('fattura_passiva_id');
    DB::table('fatture_passive')->where('id', $fatturaId)->update(['competenza_dal' => $competenzaDal, 'competenza_al' => $competenzaAl, 'is_pregresso' => false]);
    $s['fabbro'] = PianoRate::create([
        'gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Spese da finanziare', 'stato' => 'approvato', 'tipo' => 'straordinario',
        'numero_rate' => 2, 'giorno_scadenza' => 15, 'data_prima_scadenza' => '2026-08-15', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => false,
    ]);
    $s['fabbro']->fatture()->attach($fatturaId, ['importo_collegato' => $importo]);
    app(GeneratePianoRateAction::class)->execute($s['fabbro'], forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Il fabbro di U2', esercizio: $s['e']);

    return $s;
}

/** Quanto il piano del fabbro chiede a ognuno: [nome => centesimi], dalle righe del riparto. */
function b15sgChiesto(array $s): array
{
    $nomi = collect($s['persone'])->mapWithKeys(fn ($p, $nome) => [$p->id => $nome])->all();
    $esito = [];
    foreach (DB::table('righe_riparto')->where('piano_rate_id', $s['fabbro']->id)->where('tipo', 'ad_personam')->get(['anagrafica_id', 'importo']) as $r) {
        $esito[$nomi[(int) $r->anagrafica_id]] = ($esito[$nomi[(int) $r->anagrafica_id]] ?? 0) + (int) $r->importo;
    }
    ksort($esito);

    return $esito;
}

it('Coda 610 — la fattura del fabbro di un giorno, senza competenza: il piano la chiede tutta a chi era titolare quel giorno, come il consuntivo; prima € 59,51 e € 60,49', function () {
    $s = b15sgScenario($this);

    expect(b15sgChiesto($s))->toBe(['Bianchi' => 12000]);

    // La catena: le rate del piano chiedono a Bianchi € 120,00, a Rossi niente.
    $rate = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s['fabbro']->id)
        ->groupBy('rate_quote.anagrafica_id')->selectRaw('rate_quote.anagrafica_id, SUM(rate_quote.importo) as totale')->pluck('totale', 'anagrafica_id')->map(fn ($v) => (int) $v)->all();
    expect($rate)->toBe([$s['persone']['Bianchi']->id => 12000]);

    // Il consuntivo per persona: la spesa è chiesta nel piano a chi la deve, e nessuno dei due ha una spesa personale da chiedere ancora.
    $c = cpcLeggi($this, $s);
    expect(cpcDi($c, $s, 'Bianchi', 'U2')['personali']['non_chieste'])->toBe(0)
        ->and(cpcDi($c, $s, 'Rossi', 'U2')['personali']['non_chieste'] ?? 0)->toBe(0);
});

it('Coda 610, decisione 329 (1) — con la competenza scritta dal 01/01 al 31/12 la stessa fattura si divide per giorni, come prima', function () {
    $s = b15sgScenario($this, '2026-01-01', '2026-12-31');

    // € 120,00 × 181 / 365 = € 59,51 a Bianchi; il resto, € 60,49, a Rossi.
    expect(b15sgChiesto($s))->toBe(['Bianchi' => 5951, 'Rossi' => 6049]);
});

it('Coda 610 — con la competenza scritta di un giorno (dal 10/03 al 10/03) vale quel giorno, come senza', function () {
    $s = b15sgScenario($this, '2026-03-10', '2026-03-10');

    expect(b15sgChiesto($s))->toBe(['Bianchi' => 12000]);
});

it('la spesa vecchia senza periodo (fattura del 15/11/2025 su U2, nel piano del 2026) non si indovina: si divide sui giorni di quest\'anno, come prima', function () {
    $s = b15sgScenario($this, null, null, '2025-11-15', 30000);

    // A mano: € 300,00 × 181 / 365 = € 148,767 a Bianchi (dal 01/01 al 30/06), × 184 / 365 = € 151,233 a Rossi; ai resti maggiori € 148,77 e € 151,23.
    expect(b15sgChiesto($s))->toBe(['Bianchi' => 14877, 'Rossi' => 15123]);
});

it('il carrello segna la fattura di prima dell\'esercizio senza competenza («spesa_vecchia»), non quella dell\'esercizio né quella con il periodo', function () {
    $s = cpcViaVerdi($this);
    $vecchia = cpcSpesaPersonale($s, 'U2', 30000, '2025-11-15');
    $nuova = cpcSpesaPersonale($s, 'U2', 12000, '2026-03-10');
    $conPeriodo = cpcSpesaPersonale($s, 'U2', 5000, '2025-12-01', null, '2025-12-01');
    $id = fn (int $riga) => (int) DB::table('righe_fattura')->where('id', $riga)->value('fattura_passiva_id');
    DB::table('fatture_passive')->whereIn('id', [$id($vecchia), $id($nuova), $id($conPeriodo)])->update(['is_pregresso' => false]);

    $carrello = collect($this->actingAs($this->user)->getJson(route('admin.gestionale.fetch-fatture-straordinarie', $s['c']->id)
        . '?esercizio_id=' . $s['e']->id . '&gestione_id=' . $s['g']->id)->assertOk()->json())->keyBy('id');

    expect($carrello[$id($vecchia)]['spesa_vecchia'] ?? null)->toBeTrue()
        ->and($carrello[$id($nuova)]['spesa_vecchia'] ?? null)->toBeFalse()
        ->and($carrello[$id($conPeriodo)]['spesa_vecchia'] ?? null)->toBeFalse();
});
