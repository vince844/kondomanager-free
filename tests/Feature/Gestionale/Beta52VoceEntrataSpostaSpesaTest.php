<?php

/**
 * «Sposta spesa» solo fra voci dello stesso tipo.
 *
 * Il caso del collaudo: spese € 1.200,00 ed entrata di € 240,00 (affitto dell'antenna) fanno un piano di € 960,00. Spostare € 50,00 dalla spesa
 * all'entrata porta il piano a € 860,00, dall'entrata alla spesa a € 1.060,00, con il messaggio di successo: l'entrata è una quota a credito, e
 * il motore la legge con il segno opposto. Il server rifiuta lo spostamento fra tipi diversi, sul campo della destinazione, e lo storno di un
 * movimento già scritto resta sempre possibile (passa dalla stessa `moveBudget()` con `reversesMovementId`).
 *
 * Fonte dell'atteso: i conti a mano di ogni test (spese 120000 − entrata 24000 = 96000, 48000 a testa) e la riga 248 di `roadmap.md`.
 *
 * Cosa resta scoperto: il piano straordinario (le sorgenti sono fatture e non hanno il tipo; `moveBudget` non lo calcola); il cambio di tipo di una
 * voce che ha già un movimento (1.11.1, decisione 85.6); la schermata (`ModalSpostaSpesaTipo.test.ts`).
 *
 * I test che cominciano con «controprova» proteggono ciò che la correzione non deve rompere: sono verdi prima e dopo.
 */

use App\Models\Gestionale\BudgetMovement;
use App\Services\Gestionale\BudgetMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/Beta52VoceEntrataScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = be52Utente();
});

function be52Sposta($test, array $s, $piano, int $da, int $a, string $euro = '50.00')
{
    return $test->actingAs($test->user)->post(route('admin.gestionale.piani-rate.move-budget', [$s['c'], $piano]), ['source_id' => $da, 'destination_id' => $a, 'amount' => $euro, 'reason' => 'Prova']);
}

it('sposta spesa da una spesa a un\'entrata si rifiuta e il piano resta di € 960,00', function () {
    $s = be52Scenario($this);
    $piano = be52PianoDallaRotta($this, $s);
    expect(be52Totale($piano))->toBe(96000);

    be52Sposta($this, $s, $piano, $s['spesa']->id, $s['entrata']->id);
    $errori = session('errors')?->toArray() ?? [];
    be52Ricalcola($this, $s, $piano->fresh());

    expect(be52Pivot($piano->fresh()))->toBe([$s['spesa']->id => 120000, $s['entrata']->id => 24000])
        ->and(be52Totale($piano->fresh()))->toBe(96000)
        ->and(BudgetMovement::count())->toBe(0)
        ->and($errori['destination_id'][0] ?? null)->toBe('«Spese generali» è una spesa e «Affitto antenna» è un\'entrata: il budget si sposta solo fra voci dello stesso tipo, altrimenti il totale del piano cambierebbe. Scegli una voce dello stesso tipo.');
});

it('sposta spesa da un\'entrata a una spesa si rifiuta e il piano resta di € 960,00', function () {
    $s = be52Scenario($this);
    $piano = be52PianoDallaRotta($this, $s);

    be52Sposta($this, $s, $piano, $s['entrata']->id, $s['spesa']->id);
    $errori = session('errors')?->toArray() ?? [];
    be52Ricalcola($this, $s, $piano->fresh());

    expect(be52Pivot($piano->fresh()))->toBe([$s['spesa']->id => 120000, $s['entrata']->id => 24000])
        ->and(be52Totale($piano->fresh()))->toBe(96000)
        ->and(BudgetMovement::count())->toBe(0)
        ->and($errori['destination_id'][0] ?? null)->toBe('«Affitto antenna» è un\'entrata e «Spese generali» è una spesa: il budget si sposta solo fra voci dello stesso tipo, altrimenti il totale del piano cambierebbe. Scegli una voce dello stesso tipo.');
});

it('controprova — fra due spese e fra due entrate lo spostamento passa e il piano non cambia', function () {
    $s = be52Scenario($this);
    $pulizie = be52Voce($this, $s, $s['pc'], 'Pulizie', 'spesa', '600,00');
    $proventi = be52Voce($this, $s, $s['pc'], 'Proventi bacheca', 'entrata', '100,00');
    $piano = be52PianoDallaRotta($this, $s);
    $prima = be52Totale($piano);
    expect($prima)->toBe(146000); // 1.200 + 600 − 240 − 100

    be52Sposta($this, $s, $piano, $s['spesa']->id, $pulizie->id);
    expect(session('errors')?->toArray() ?? [])->toBe([]);
    be52Sposta($this, $s, $piano, $s['entrata']->id, $proventi->id, '40.00');
    expect(session('errors')?->toArray() ?? [])->toBe([]);
    be52Ricalcola($this, $s, $piano->fresh());

    expect(BudgetMovement::count())->toBe(2)
        ->and(be52Totale($piano->fresh()))->toBe(146000)
        ->and(be52Pivot($piano->fresh()))->toBe([$s['spesa']->id => 115000, $s['entrata']->id => 20000, $pulizie->id => 65000, $proventi->id => 14000]);
});

it('controprova — un movimento fra voci di tipo diverso già scritto si storna ancora', function () {
    $s = be52Scenario($this);
    $altra = be52Voce($this, $s, $s['pc'], 'Pulizie', 'spesa', '600,00');
    $piano = be52PianoDallaRotta($this, $s);
    // Il movimento nasce fra due spese (ammesso); poi la destinazione cambia tipo: è il dato che le versioni precedenti lasciavano.
    be52Sposta($this, $s, $piano, $s['spesa']->id, $altra->id);
    $movimento = BudgetMovement::firstOrFail();
    DB::table('conti')->where('id', $altra->id)->update(['tipo' => 'entrata']);
    $pivotMisto = be52Pivot($piano->fresh());
    expect($pivotMisto[$s['spesa']->id])->toBe(115000);

    $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.budget-movements.reverse', [$s['c'], $piano, $movimento]));

    expect(session('errors')?->toArray() ?? [])->toBe([])
        ->and(BudgetMovement::where('reverses_movement_id', $movimento->id)->count())->toBe(1)
        ->and(be52Pivot($piano->fresh())[$s['spesa']->id])->toBe(120000);
});

it('il servizio rifiuta il tipo diverso su destination_id e lascia passare lo storno', function () {
    $s = be52Scenario($this);
    $altra = be52Voce($this, $s, $s['pc'], 'Pulizie', 'spesa', '600,00');
    $piano = be52PianoDallaRotta($this, $s);
    $servizio = app(BudgetMovementService::class);

    $rifiutato = null;
    try {
        $servizio->moveBudget($piano, $s['spesa']->id, $s['entrata']->id, 5000, 'Prova', $this->user->id);
    } catch (ValidationException $e) {
        $rifiutato = array_keys($e->errors());
    }
    expect($rifiutato)->toBe(['destination_id']);

    $movimento = $servizio->moveBudget($piano, $s['spesa']->id, $altra->id, 5000, 'Prova', $this->user->id);
    DB::table('conti')->where('id', $altra->id)->update(['tipo' => 'entrata']);
    $storno = $servizio->reverseMovement($movimento, $this->user->id);
    expect($storno->type)->toBe('reversal');
});

it('la pagina del piano manda il tipo di ogni voce, sorgenti e destinazioni', function () {
    DB::connection()->getPdo()->sqliteCreateFunction('JSON_UNQUOTE', fn ($x) => $x);
    $s = be52Scenario($this);
    $piano = be52PianoDallaRotta($this, $s);

    $r = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.piani-rate.show', [$s['c'], $s['e'], $piano]));
    $r->assertOk();
    $props = $r->viewData('page')['props'];

    $tipi = fn (array $voci) => collect($voci)->mapWithKeys(fn ($v) => [$v['nome'] => $v['tipo'] ?? '(manca)'])->sortKeys()->all();
    $atteso = ['Affitto antenna' => 'entrata', 'Spese generali' => 'spesa'];
    expect($tipi($props['sources']))->toBe($atteso)
        ->and($tipi($props['destinations']))->toBe($atteso);
});

it('controprova — da una spesa a una voce che non è ancora nella pivot lo spostamento passa e crea la riga della destinazione', function () {
    $s = be52Scenario($this);
    $piano = be52PianoDallaRotta($this, $s);
    $pulizie = be52Voce($this, $s, $s['pc'], 'Pulizie', 'spesa', '600,00'); // nata dopo il piano: nella pivot non c'è
    expect(be52Pivot($piano))->toBe([$s['spesa']->id => 120000, $s['entrata']->id => 24000]);

    be52Sposta($this, $s, $piano, $s['spesa']->id, $pulizie->id);

    expect(be52Errori())->toBe([])
        ->and(be52Pivot($piano->fresh()))->toBe([$s['spesa']->id => 115000, $s['entrata']->id => 24000, $pulizie->id => 5000])
        ->and(BudgetMovement::count())->toBe(1);
});

it('controprova — una destinazione di un altro condominio si rifiuta per il perimetro, senza il testo del tipo e senza il nome della voce altrui', function () {
    $s = be52Scenario($this);
    $altro = be52Scenario($this);
    $voceAltrui = be52Voce($this, $altro, $altro['pc'], 'Proventi del vicino', 'entrata', '100,00');
    $piano = be52PianoDallaRotta($this, $s);

    be52Sposta($this, $s, $piano, $s['spesa']->id, $voceAltrui->id);
    $errori = be52Errori();

    expect(array_keys($errori))->toBe(['destination_id'])
        ->and(count($errori['destination_id']))->toBe(1)
        ->and(str_contains($errori['destination_id'][0], 'stesso tipo'))->toBeFalse('il rifiuto del perimetro non parla del tipo')
        ->and(str_contains(json_encode($errori, JSON_UNESCAPED_UNICODE), 'Proventi del vicino'))->toBeFalse('il rifiuto non nomina la voce di un altro condominio')
        ->and(BudgetMovement::count())->toBe(0)
        ->and(be52Pivot($piano->fresh()))->toBe([$s['spesa']->id => 120000, $s['entrata']->id => 24000]);
});
