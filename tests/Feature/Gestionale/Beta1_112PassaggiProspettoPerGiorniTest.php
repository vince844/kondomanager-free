<?php

/**
 * Coda 298, decisione 183.4 — la frase dell'ingresso nella testa del prospetto si decide per giorni, non per persona.
 *
 * Chi ha voci nelle rate di un piano le ha per un tratto: la frase vale per i giorni insieme che quel tratto copre, e chi entra conta per i giorni in cui
 * non ne ha. Due strade dalla rotta:
 * - (A) Ines esce il 1/4 prima della generazione (ha nelle sue rate gennaio–marzo, 90 giorni: 36000 × 90/365 = 8876,71 → 8877; il ripiego di Ugo
 *   27123 per 275 giorni) e poi è di nuovo inquilina dal 1/7 (rientra, o la fine è annullata), insieme a Luca dal 1/7: i giorni di luglio–dicembre sono
 *   nel ripiego, che il prospetto divide fra Ines e Luca (rientra: 27123 × 184/275 = 18147,7, metà a testa 9074; annullata: pesi 50 × 275 e 50 × 184,
 *   Ines 16250 e Luca 10873). La frase diceva «con la parte dell'inquilino di quei giorni nelle rate di Ines, e Luca, per quei giorni, non ha voci»: falso.
 * - (B) Luca, inquilino con Ines alla generazione, esce il 1/4 e rientra il 1/7: nelle rate ha gennaio–marzo (pesi 50 × 365 e 50 × 90: Ines 28879, Luca
 *   7121), e da luglio la parte è tutta nelle rate di Ines. La frase non c'era, perché Luca ha voci nelle rate del piano (di un altro tratto).
 */

use App\Models\User;
use App\Services\Riparto\ProspettoOneriAccessori;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/Support/ScenariFineLocazione.php';

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

function s298Genera($test, array $s): void
{
    $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $s['piano']]), [
        'accetta_destinatari' => true, 'nota_destinatari' => 'Letto: generazione nella prova',
    ])->assertSessionHasNoErrors();
    expect(DB::table('rate')->where('piano_rate_id', $s['piano']->id)->count())->toBe(12);
}

function s298Inizio($test, array $s, string $chi, string $il): void
{
    fclRegistra($test, $s, ['tipo' => 'inizio_locazione', 'tipologia' => 'inquilino', 'anagrafica_entrante_id' => $s['persone'][$chi]->id, 'decorrenza' => $il, 'quota' => 50,
        'copia_autentica' => false, 'data_fine_locazione' => '2030-06-30', 'regime_contratto' => 'abitativo', 'pertinenze' => []]);
}

/** Le frasi di testa, dalla rotta del pulsante (il PDF) e dalla vista resa. @return list<string> */
function s298Testa($test, array $s): array
{
    $r = $test->actingAs($test->user)->get(route('admin.gestionale.immobili.prospetto-oneri', [$s['c'], $s['unita'], 'esercizio' => $s['e']->id]));
    expect($r->status())->toBe(200);
    $testa = app(ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e'])['intestazione'];
    $stampa = fclStampa($s);
    foreach ($testa as $frase) {
        expect($stampa)->toContain($frase);
    }

    return $testa;
}

it('Coda 298 (A) — chi c\'era ha voci nelle rate di un altro tratto: i giorni insieme sono nel ripiego, e la testa non dice che sono nelle sue rate', function (bool $annulla, array $attesi) {
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50]]]);
    fclPersona($s, 'Luca');
    $fine = fclRegistra($this, $s, fclModulo($s, null, '2026-04-01') + ['conguaglio' => 'non_scrivere']);
    s298Genera($this, $s);
    if ($annulla) {
        fclAnnulla($this, $s, $fine)->assertStatus(302);
    } else {
        s298Inizio($this, $s, 'Ines', '2026-07-01');
    }
    s298Inizio($this, $s, 'Luca', '2026-07-01');

    $testa = s298Testa($this, $s);
    expect(implode("\n", $testa))->not->toContain('è stato generato prima')
        ->and(fclProspetto($s)['conduttori'])->toBe($attesi);
})->with([
    'Ines rientra il 1/7' => [false, ['ines' => [17951, 8877, 9074, 274], 'luca' => [9074, 0, 9074, 184]]],
    'la fine di Ines annullata' => [true, ['ines' => [25127, 8877, 16250, 365], 'luca' => [10873, 0, 10873, 184]]],
]);

it('Coda 298 (B) — chi entra aveva voci nelle rate di un altro tratto (è uscito ed è rientrato): per i giorni insieme a chi c\'era la testa dice la frase dell\'ingresso', function () {
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50], ['Luca', 50]]]);
    fclRegistra($this, $s, fclModulo($s, null, '2026-04-01', esce: 'Luca') + ['conguaglio' => 'non_scrivere']);
    s298Genera($this, $s);
    s298Inizio($this, $s, 'Luca', '2026-07-01');

    $testa = s298Testa($this, $s);
    expect($testa)->toContain('Dal 01/07/2026 Luca è inquilino insieme a Ines, ma il piano «Preventivo 2026» è stato generato prima: le cifre sono quelle del piano com\'era, con la parte dell\'inquilino di quei giorni nelle rate di Ines, e Luca, per quei giorni, non ha voci. Un piano generato dopo dividerà quelle voci fra Ines e Luca, per quota e per giorni.')
        ->and(fclProspetto($s)['conduttori'])->toBe(['ines' => [28879, 28879, 0, 365], 'luca' => [7121, 7121, 0, 90]]);
});
