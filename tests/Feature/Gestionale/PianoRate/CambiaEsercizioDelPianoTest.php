<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * «Esercizio del piano: 2026 · Cambia» nella pagina del piano: un piano nato nell'esercizio sbagliato si sposta in un altro esercizio aperto della sua
 * gestione, se non ha rate emesse né incassi. Lo scenario: il «Preventivo 2026» (Spese generali € 1.200,00 su A di Ugo e C di Bice, 500/500; Proventi
 * antenna € 240,00 sul box D di Bice), la stessa gestione agganciata all'esercizio 2027. La catena: il piano spostato e ricalcolato toglie il già versato
 * dell'esercizio nuovo.
 */

use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContributoVersato;
use App\Models\Gestionale\PianoRate;
use App\Services\CondominioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Beta52TotaliScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->user = b52tAdmin();
    b52tSqliteJson();
});

function b15ceScenario($test): array
{
    $s = b52tScenario($test);
    $s['piano'] = b52tPiano($test, $s);
    $s['e27'] = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    app(CondominioService::class)->createDefaultGestione($s['c'], $s['e27']);

    return $s;
}

function b15ceCambia($test, array $s, Esercizio $verso)
{
    session()->forget(['message', 'errors']);

    return $test->actingAs($test->user)->from('/pagina-del-piano')->put(route('admin.gestionale.esercizi.piani-rate.update-esercizio', [$s['c'], $s['e'], $s['piano']]),
        ['esercizio_id' => $verso->id]);
}

function b15ceRiga($test, array $s, Esercizio $e): array
{
    return $test->actingAs($test->user)->get(route('admin.gestionale.esercizi.piani-rate.show', [$s['c'], $e, $s['piano']]))->assertOk()
        ->viewData('page')['props']['cambio_esercizio'];
}

it('la pagina del piano dice l\'esercizio e offre gli altri esercizi aperti della gestione', function () {
    $s = b15ceScenario($this);

    expect(b15ceRiga($this, $s, $s['e']))->toBe(['attuale' => ['id' => $s['e']->id, 'nome' => 'Esercizio 2026'],
        'scelte' => [['id' => $s['e27']->id, 'nome' => 'Esercizio 2027', 'perche' => null]], 'fermo' => null]);
});

it('il piano si sposta nel 2027: esce dall\'elenco del 2026, entra in quello del 2027, e la frase dice di ricalcolarlo', function () {
    $s = b15ceScenario($this);

    b15ceCambia($this, $s, $s['e27'])->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.gestionale.esercizi.piani-rate.show', [$s['c'], $s['e27'], $s['piano']]));

    $elenco = fn (Esercizio $e) => collect($this->actingAs($this->user)->get(route('admin.gestionale.esercizi.piani-rate.index', [$s['c'], $e]))->assertOk()
        ->viewData('page')['props']['pianiRate'])->pluck('nome')->all();
    expect((int) $s['piano']->fresh()->esercizio_id)->toBe($s['e27']->id)
        ->and(session('message')['type'] ?? null)->toBe('success')
        ->and((string) (session('message')['message'] ?? ''))->toBe('Il piano «Preventivo 2026» ora è dell\'esercizio «Esercizio 2027». Le rate sono state calcolate con l\'esercizio di prima: ricalcola il piano, così toglie il già versato di questo esercizio. Le scadenze delle rate restano quelle del piano: se servono altre date, rifallo.')
        ->and($elenco($s['e']))->not->toContain('Preventivo 2026')
        ->and($elenco($s['e27']))->toContain('Preventivo 2026')
        ->and(\App\Models\RegistroModifica::query()->where('riepilogo', 'Piano rate «Preventivo 2026» spostato dall\'esercizio «Esercizio 2026» all\'esercizio «Esercizio 2027»')->exists())->toBeTrue();
});

it('la catena: il piano spostato e ricalcolato toglie il già versato del 2027, che nel 2026 non toglieva', function () {
    $s = b15ceScenario($this);
    ContributoVersato::create(['condominio_id' => $s['c']->id, 'target_type' => Conto::class, 'target_id' => $s['spesa']->id, 'immobile_id' => $s['unita']['A']->id,
        'anagrafica_id' => $s['persone']['ugo']->id, 'esercizio_id' => $s['e27']->id, 'importo_cents' => 10000, 'natura' => 'fondo_vincolato', 'origine' => 'migrazione']);
    $ricalcola = fn (Esercizio $e) => $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $e, $s['piano']]),
        ['accetta_destinatari' => true, 'nota_destinatari' => 'Letto: ricalcolo del piano nella prova'])->assertRedirect();

    // A mano, nel 2026: Ugo la metà delle spese, € 600,00; Bice € 600,00 meno € 240,00 dell'antenna, € 360,00. Il già versato del 2027 non conta.
    $ricalcola($s['e']);
    $prima = b52tSommeQuote($s['piano']->fresh());
    b15ceCambia($this, $s, $s['e27'])->assertSessionHasNoErrors();
    $ricalcola($s['e27']);

    // Nel 2027: Ugo € 600,00 meno € 100,00 del già versato del 2027 = € 500,00; Bice invariata.
    expect(array_values($prima))->toBe([36000, 60000])
        ->and(array_values(b52tSommeQuote($s['piano']->fresh())))->toBe([36000, 50000]);
});

it('il piano con una rata emessa non cambia esercizio, e la pagina dice perché', function () {
    $s = b15ceScenario($this);
    $rata = DB::table('rate')->where('piano_rate_id', $s['piano']->id)->orderBy('numero_rata')->value('id');
    $scrittura = DB::table('scritture_contabili')->insertGetId(['condominio_id' => $s['c']->id, 'gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id,
        'data_registrazione' => '2026-01-05', 'data_competenza' => '2026-01-05', 'numero_protocollo' => 'E1', 'causale' => 'emissione', 'tipo_movimento' => 'emissione_rata',
        'stato' => 'registrata', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('rate_quote')->where('rata_id', $rata)->update(['scrittura_contabile_id' => $scrittura]);

    $fermo = 'Il piano ha rate emesse, incassi o il conguaglio di un passaggio: non cambia esercizio, si rifà. Annulla prima gli incassi e le emissioni, poi eliminalo e crealo di nuovo dalla pagina dell\'esercizio giusto.';
    expect(b15ceRiga($this, $s, $s['e'])['fermo'])->toBe($fermo);
    b15ceCambia($this, $s, $s['e27'])->assertRedirect('/pagina-del-piano');
    expect((string) (session('message')['message'] ?? ''))->toBe($fermo)
        ->and((int) $s['piano']->fresh()->esercizio_id)->toBe($s['e']->id);
});

it('un esercizio chiuso non si offre e non si accetta; il piano di un altro condominio non si tocca: 404', function () {
    $s = b15ceScenario($this);
    $s['e27']->update(['stato' => 'chiuso']);

    expect(b15ceRiga($this, $s, $s['e'])['scelte'])->toBe([]);
    b15ceCambia($this, $s, $s['e27'])->assertSessionHasErrors('esercizio_id');
    expect((int) $s['piano']->fresh()->esercizio_id)->toBe($s['e']->id);

    $altro = \App\Models\Condominio::factory()->create();
    $this->actingAs($this->user)->put(route('admin.gestionale.esercizi.piani-rate.update-esercizio', [$altro, $s['e'], $s['piano']]), ['esercizio_id' => $s['e27']->id])
        ->assertNotFound();
});
