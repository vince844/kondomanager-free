<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * «Modifica gestione» con due esercizi aperti lega la gestione all'esercizio della pagina, non a un altro (1.12.0-beta.14, tappa 5).
 *
 * Il caso. Dopo un'importazione restano aperti due esercizi, il 2025 e il 2026 (decisione 707 (4)). `GestioneController::update()`
 * cercava «l'esercizio aperto» per conto suo, il primo che il database restituisce, e ci legava la gestione modificata: dalla pagina del
 * 2026 la gestione del 2026 finiva legata anche al 2025, e con la regola del più recente quella del 2025 finirebbe legata anche al 2026.
 * Una gestione legata a un esercizio in più compare nelle sue pagine, nei suoi piani rate e nel suo consuntivo.
 *
 * Adesso: con la pagina di un esercizio aperto, la gestione resta in quell'esercizio, e si torna all'elenco delle gestioni di quella
 * pagina. Dalla pagina di un esercizio chiuso resta la strada di prima (Coda 438 e 439): «Modifica gestione» porta la gestione
 * anche nell'esercizio aperto (con due aperti, il più recente).
 */

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta52VoceEntrataScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = be52Utente();
});

/** Un esercizio del condominio, con l'anno e lo stato dati. */
function b14gmEsercizio(Condominio $c, int $anno, string $stato = 'aperto'): Esercizio
{
    return Esercizio::create(['condominio_id' => $c->id, 'nome' => 'Esercizio '.$anno, 'data_inizio' => $anno.'-01-01', 'data_fine' => $anno.'-12-31', 'stato' => $stato]);
}

/** Una gestione dell'anno, legata al solo esercizio dato. */
function b14gmGestione(Condominio $c, Esercizio $e, int $anno, string $nome): Gestione
{
    $g = $c->gestioni()->create(['nome' => $nome, 'tipo' => 'straordinaria', 'attiva' => true, 'data_inizio' => $anno.'-01-01', 'data_fine' => $anno.'-12-31']);
    $e->gestioni()->attach($g->id);

    return $g;
}

/** Gli esercizi a cui la gestione è legata, per id. */
function b14gmLegami(Gestione $g): array
{
    return DB::table('esercizio_gestione')->where('gestione_id', $g->id)->orderBy('esercizio_id')->pluck('esercizio_id')->map(fn ($id) => (int) $id)->all();
}

/** «Modifica gestione» dalla pagina dell'esercizio dato, con le date dell'anno. */
function b14gmModifica($test, Condominio $c, Esercizio $e, Gestione $g, int $anno)
{
    return $test->actingAs($test->user)->put(route('admin.gestionale.esercizi.gestioni.update', [$c, $e, $g]), [
        'nome' => $g->nome, 'descrizione' => 'Lavori', 'data_inizio' => $anno.'-01-01', 'data_fine' => $anno.'-12-31', 'tipo' => 'straordinaria',
    ]);
}

it('dalla pagina del 2026, con il 2025 aperto e scritto prima, la gestione del 2026 resta nel 2026 e si torna alle gestioni del 2026', function () {
    $c = Condominio::factory()->create();
    $e25 = b14gmEsercizio($c, 2025);
    $e26 = b14gmEsercizio($c, 2026);
    $g = b14gmGestione($c, $e26, 2026, 'Rifacimento facciata');

    b14gmModifica($this, $c, $e26, $g, 2026)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.gestionale.esercizi.gestioni.index', ['condominio' => $c->id, 'esercizio' => $e26->id]));

    expect(b14gmLegami($g))->toBe([$e26->id]);
});

it('dalla pagina del 2025, con il 2026 aperto e più recente, la gestione del 2025 resta nel 2025', function () {
    $c = Condominio::factory()->create();
    // Il 2026 scritto prima del 2025 (l'importazione dell'anno prima, arrivata dopo): né il primo trovato né il più recente sono la pagina.
    $e26 = b14gmEsercizio($c, 2026);
    $e25 = b14gmEsercizio($c, 2025);
    $g = b14gmGestione($c, $e25, 2025, 'Ascensore');

    b14gmModifica($this, $c, $e25, $g, 2025)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.gestionale.esercizi.gestioni.index', ['condominio' => $c->id, 'esercizio' => $e25->id]));

    expect(b14gmLegami($g))->toBe([$e25->id])
        ->and(DB::table('esercizio_gestione')->where('esercizio_id', $e26->id)->count())->toBe(0);
});

it('dalla pagina di un esercizio chiuso resta la strada di prima: la gestione va anche nell\'esercizio aperto', function () {
    $c = Condominio::factory()->create();
    $e26 = b14gmEsercizio($c, 2026);
    $g = b14gmGestione($c, $e26, 2026, 'Fondo lavori');
    $e26->update(['stato' => 'chiuso']);
    $e27 = b14gmEsercizio($c, 2027);

    b14gmModifica($this, $c, $e26, $g, 2026)->assertSessionHasNoErrors();

    expect(b14gmLegami($g))->toBe([$e26->id, $e27->id]);
});
