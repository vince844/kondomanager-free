<?php

/**
 * Coda 439 — «Modifica gestione» e la creazione di una gestione scrivono nel legame con l'esercizio (`esercizio_gestione`) il periodo della gestione
 * dentro l'esercizio: l'intersezione fra le date della gestione e quelle dell'esercizio, vuota se non si toccano (decisione 183.4: «solo le date del
 * pivot (l'intersezione, vuota se non c'è)»). L'aggancio non cambia: «Modifica gestione» aggancia ancora l'esercizio aperto.
 *
 * Fonte dell'atteso: la decisione; le date si rifanno a mano accanto a ogni test.
 */

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Services\CondominioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta52VoceEntrataScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = be52Utente();
});

/** Il 2026 chiuso con la gestione ordinaria come la lascia l'importatore (date del 2026, riga del pivot senza date), e il 2027 aperto. */
function g112pdScenario(): array
{
    $c = Condominio::factory()->create();
    $e26 = Esercizio::create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = app(CondominioService::class)->createDefaultGestione($c, $e26);
    $e26->update(['stato' => 'chiuso']);
    $e27 = Esercizio::create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);

    return ['c' => $c, 'e26' => $e26, 'e27' => $e27, 'g' => $g];
}

/** Le date della riga del pivot fra la gestione e l'esercizio, come stanno a database (giorno, senza l'ora). */
function g112pRiga(Gestione $g, Esercizio $e): ?array
{
    $r = DB::table('esercizio_gestione')->where('gestione_id', $g->id)->where('esercizio_id', $e->id)->first();

    return $r === null ? null : [$r->data_inizio === null ? null : substr((string) $r->data_inizio, 0, 10), $r->data_fine === null ? null : substr((string) $r->data_fine, 0, 10)];
}

/** «Modifica gestione» dalla pagina del 2026, con le date date. */
function g112pModifica($test, array $s, string $dal, string $al, string $tipo = 'ordinaria')
{
    return $test->actingAs($test->user)->put(route('admin.gestionale.esercizi.gestioni.update', [$s['c'], $s['e26'], $s['g']]), [
        'nome' => 'Gestione ordinaria', 'descrizione' => 'Gestione ordinaria', 'data_inizio' => $dal, 'data_fine' => $al, 'tipo' => $tipo,
    ]);
}

it('Coda 439 — «Modifica gestione» dalla pagina del 2026 con il 2027 aperto e la gestione dentro il 2026: la riga del 2027 resta senza date, non «dal 01/01/2027 al 31/12/2026»', function () {
    $s = g112pdScenario();

    g112pModifica($this, $s, '2026-01-01', '2026-12-31')->assertSessionHasNoErrors();

    // Dalla decisione: gestione 01/01/2026–31/12/2026, esercizio 01/01/2027–31/12/2027, nessun giorno in comune.
    expect(g112pRiga($s['g'], $s['e27']))->toBe([null, null])
        ->and(g112pRiga($s['g'], $s['e26']))->toBe([null, null]);
});

it('Coda 439 — la gestione portata a tutto il 2027: la riga del 2027 dice dal 01/01/2027 al 31/12/2027', function () {
    $s = g112pdScenario();

    g112pModifica($this, $s, '2026-01-01', '2027-12-31')->assertSessionHasNoErrors();

    expect(g112pRiga($s['g'], $s['e27']))->toBe(['2027-01-01', '2027-12-31']);
});

it('Coda 439 — una gestione che entra nel 2027 per un pezzo: la riga del 2027 ha il pezzo in comune, anche di un giorno solo', function () {
    $s = g112pdScenario();

    g112pModifica($this, $s, '2026-06-01', '2027-05-31')->assertSessionHasNoErrors();
    expect(g112pRiga($s['g'], $s['e27']))->toBe(['2027-01-01', '2027-05-31']);

    // Un giorno solo in comune: il 01/01/2027.
    g112pModifica($this, $s, '2026-01-01', '2027-01-01')->assertSessionHasNoErrors();
    expect(g112pRiga($s['g'], $s['e27']))->toBe(['2027-01-01', '2027-01-01']);
});

it('Coda 439 — una gestione straordinaria creata dalla pagina del 2027 con le date nel 2028: la riga del 2027 resta senza date', function () {
    $s = g112pdScenario();

    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.gestioni.store', [$s['c'], $s['e27']]), [
        'nome' => 'Rifacimento facciata', 'descrizione' => 'Lavori straordinari', 'data_inizio' => '2028-03-01', 'data_fine' => '2028-10-31', 'tipo' => 'straordinaria',
    ])->assertSessionHasNoErrors();
    $straordinaria = Gestione::where('condominio_id', $s['c']->id)->where('nome', 'Rifacimento facciata')->firstOrFail();

    // Dalla decisione: 01/03/2028–31/10/2028 contro 01/01/2027–31/12/2027, nessun giorno in comune.
    expect(g112pRiga($straordinaria, $s['e27']))->toBe([null, null]);
});

it('Coda 439 — una gestione creata dentro l\'esercizio tiene le sue date, e una che lo supera ha quelle dell\'esercizio', function () {
    $s = g112pdScenario();

    foreach ([['Dentro', '2027-03-01', '2027-06-30'], ['Oltre', '2026-10-01', '2028-02-28']] as [$nome, $dal, $al]) {
        $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.gestioni.store', [$s['c'], $s['e27']]), [
            'nome' => $nome, 'descrizione' => 'Lavori', 'data_inizio' => $dal, 'data_fine' => $al, 'tipo' => 'straordinaria',
        ])->assertSessionHasNoErrors();
    }

    expect(g112pRiga(Gestione::where('condominio_id', $s['c']->id)->where('nome', 'Dentro')->firstOrFail(), $s['e27']))->toBe(['2027-03-01', '2027-06-30'])
        ->and(g112pRiga(Gestione::where('condominio_id', $s['c']->id)->where('nome', 'Oltre')->firstOrFail(), $s['e27']))->toBe(['2027-01-01', '2027-12-31']);
});

it('Coda 439 — la gestione riportata dentro il 2026 dopo essere arrivata nel 2027: la riga del 2027 già scritta torna senza date, e resta una sola', function () {
    $s = g112pdScenario();

    g112pModifica($this, $s, '2026-01-01', '2027-12-31')->assertSessionHasNoErrors();
    expect(g112pRiga($s['g'], $s['e27']))->toBe(['2027-01-01', '2027-12-31']);

    // La stessa riga, riscritta (`syncWithoutDetaching` su un esercizio già agganciato): le date vuote si scrivono.
    g112pModifica($this, $s, '2026-01-01', '2026-12-31')->assertSessionHasNoErrors();
    expect(g112pRiga($s['g'], $s['e27']))->toBe([null, null])
        ->and(DB::table('esercizio_gestione')->where('gestione_id', $s['g']->id)->where('esercizio_id', $s['e27']->id)->count())->toBe(1);
});
