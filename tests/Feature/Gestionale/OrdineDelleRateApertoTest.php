<?php

/**
 * L'ordine delle rate che il programma propone, in due punti che nessun'altra prova guardava:
 * - una rata è «aperta» per una persona solo se il suo netto sulla rata è positivo: il box a credito accanto all'appartamento a debito sulla
 *   rata 0 la chiude, anche se la quota dell'appartamento, da sola, è da pagare;
 * - la situazione debitoria del modulo dell'incasso manda le righe nell'ordine proposto, non in quello in cui le legge il database: a pari
 *   scadenza la rata emessa viene prima della bozza anche quando la bozza è stata creata prima.
 *
 * Centesimi; le cifre sono scritte a mano dalla regola.
 */

use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestione;
use App\Models\User;
use App\Services\Gestionale\Incassi\OrdineImputazione;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/LetturaDebitiProva.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** Ugo con l'appartamento A (600) e il box G (400); tre rate dal 05/01/2026 su € 300,00, emesse il 01/01; i saldi della rata 0 dati. */
function odrScenario($test, array $saldi): array
{
    $test->travelTo('2026-03-20 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 600], 'G' => ['ugo', 400]], 3, 'rata_zero', $saldi, 30000, '2026-01-05');
    lcdEmetti($test, $s, [1, 2, 3], '2026-01-01');

    return $s;
}

it('la rata 0 con il netto della persona negativo non è aperta: l\'appartamento a € 100,00 e il box a € 150,00 di credito fanno € 50,00 di credito', function () {
    $s = odrScenario($this, [['ugo', 'A', 10000], ['ugo', 'G', -15000]]);
    $quoteRataZero = DB::table('rate_quote')->where('rata_id', lcdRata($s, 0))->where('anagrafica_id', $s['persone']['ugo']->id)->pluck('importo')->sort()->values()->all();
    expect($quoteRataZero)->toBe([-15000, 10000]);

    $aperte = array_column(app(OrdineImputazione::class)->rateAperte((int) $s['c']->id, (int) $s['persone']['ugo']->id), 'rata_id');

    expect($aperte)->toBe([lcdRata($s, 1), lcdRata($s, 2), lcdRata($s, 3)]);
});

it('la rata 0 con il netto a zero non è aperta; con il netto positivo sì', function () {
    $zero = odrScenario($this, [['ugo', 'A', 3000], ['ugo', 'G', -3000]]);
    $positivo = odrScenario($this, [['ugo', 'A', 10000], ['ugo', 'G', -3000]]);
    $aperte = fn (array $s) => array_column(app(OrdineImputazione::class)->rateAperte((int) $s['c']->id, (int) $s['persone']['ugo']->id), 'rata_id');

    expect($aperte($zero))->not->toContain(lcdRata($zero, 0))
        ->and($aperte($positivo))->toBe([lcdRata($positivo, 0), lcdRata($positivo, 1), lcdRata($positivo, 2), lcdRata($positivo, 3)]);
});

it('la situazione debitoria manda le righe nell\'ordine proposto: a pari scadenza la rata emessa, creata dopo, prima della bozza creata prima', function () {
    // La rata 2 del piano (05/02) resta in bozza; un'altra gestione ha una rata emessa con la stessa scadenza, con l'id più alto.
    $this->travelTo('2026-03-20 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 3, 'prima_rata', [], 30000, '2026-01-05');
    lcdEmetti($this, $s, [1, 3], '2026-01-01');
    $altra = Gestione::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Riscaldamento', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($s['e'], $altra->id);
    $piano = PianoRate::create(['gestione_id' => $altra->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Riscaldamento', 'stato' => 'approvato',
        'tipo' => 'ordinario', 'numero_rate' => 1, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-02-05', 'metodo_distribuzione' => 'prima_rata']);
    $emessa = Rata::create(['piano_rate_id' => $piano->id, 'numero_rata' => 1, 'data_scadenza' => '2026-02-05', 'descrizione' => 'Rata n.1 - Riscaldamento', 'importo_totale' => 8000, 'stato' => 'emessa']);
    $emissione = (int) DB::table('rate_quote')->where('id', lcdQuota($s, 'ugo', 1))->value('scrittura_contabile_id');
    RataQuote::create(['rata_id' => $emessa->id, 'anagrafica_id' => $s['persone']['ugo']->id, 'immobile_id' => $s['immobili']['A']->id, 'importo' => 8000, 'importo_pagato' => 0,
        'stato' => 'da_pagare', 'tipo' => 'ordinaria', 'data_scadenza' => '2026-02-05', 'scrittura_contabile_id' => $emissione]);
    expect(lcdRata($s, 2))->toBeLessThan($emessa->id);

    $righe = $this->actingAs($this->user)->getJson(route('admin.gestionale.situazione-debitoria', [$s['c'], 'anagrafica_id' => $s['persone']['ugo']->id]))->assertOk()->json('rate');

    // Così come arrivano, non ordinate dalla prova: rata 1 (05/01), la rata emessa del 05/02, la bozza del 05/02, la rata 3 (05/03).
    expect(array_column($righe, 'rata_padre_id'))->toBe([lcdRata($s, 1), $emessa->id, lcdRata($s, 2), lcdRata($s, 3)])
        ->and(array_column($righe, 'ordine'))->toBe([1, 2, 3, 4]);
});
