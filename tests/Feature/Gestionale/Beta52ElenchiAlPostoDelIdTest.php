<?php

/**
 * Un elenco al posto dell'id, in «Sposta spesa» e nella regolazione immediata, è un errore sul campo e non il testo di un'eccezione.
 *
 * `Rule::exists` accetta un elenco di id e `findOrFail` lo trasforma in una collezione: in «Sposta spesa» la pagina riceveva, sotto l'importo,
 * «Errore di sistema: Property [piano_conto_id] does not exist on this collection instance.»; nella regolazione immediata la richiesta passava e l'azione
 * rispondeva 500 (con il debug acceso, la traccia a video). Nessuna scrittura in nessuno dei due casi (la transazione, il perimetro). Ora `integer`
 * fa rifiutare l'elenco sul campo che lo porta, con una frase che dice quale scelta rifare, e nessun testo di sistema arriva a video.
 *
 * La voce di entrata scritta «+id» o «id.0» nella regolazione prende il rifiuto sul campo `conto_id`, accanto al menu da cui si sceglie una voce di spesa, e
 * non il riquadro «Operazione non ammessa» con il pulsante «Registra una fattura passiva»: l'id si legge come lo legge la regola `integer`, come fa
 * `PerimetroFattura` per le fatture.
 *
 * Fonte dell'atteso: i conti a mano di ogni test (una regolazione registrata scrive una sola scrittura contabile; un rifiuto non ne scrive nessuna).
 *
 * Cosa resta scoperto: la frase `Errore di sistema: …` con il testo dell'eccezione, che altri controller scrivono nello stesso modo (voce 274 della
 * roadmap); `fornitore_id` senza il condominio, perché i fornitori valgono per tutta l'installazione; la schermata.
 *
 * I test che cominciano con «controprova» proteggono ciò che la correzione non deve rompere: sono verdi prima e dopo.
 */

use App\Models\Gestionale\BudgetMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta52VoceEntrataScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = be52Utente();
});

function be52ElenchiSposta($test, array $s, $piano, array $corpo)
{
    return $test->actingAs($test->user)->post(route('admin.gestionale.piani-rate.move-budget', [$s['c'], $piano]), $corpo + ['amount' => '50.00', 'reason' => 'Prova']);
}

/** Il corpo di una regolazione immediata valida sulla voce di spesa dello scenario. */
function be52ElenchiRegolazione(array $s, array $over = []): array
{
    return array_merge(['gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id, 'conto_id' => $s['spesa']->id, 'cassa_id' => $s['cassa']->id,
        'data_operazione' => '2026-03-10', 'causale' => 'Prova', 'importo' => 30.00], $over);
}

function be52ElenchiScritture(array $s): int
{
    return DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->count();
}

// ─── «Sposta spesa» ─────────────────────────────────────────────────────────────────────────────────────────────

it('«Sposta spesa»: la sorgente scritta come elenco si rifiuta sul campo, senza il testo di sistema e senza scrivere', function () {
    $s = be52Scenario($this);
    $pulizie = be52Voce($this, $s, $s['pc'], 'Pulizie', 'spesa', '600,00');
    $piano = be52PianoDallaRotta($this, $s);
    $pivot = be52Pivot($piano->fresh());

    be52ElenchiSposta($this, $s, $piano, ['source_id' => [$s['spesa']->id], 'destination_id' => $pulizie->id]);
    $errori = be52Errori();

    expect(array_keys($errori))->toBe(['source_id'])
        ->and($errori['source_id'][0])->toBe('La voce da cui spostare il budget non è valida: scegli una voce dal menu «Preleva da».')
        ->and(json_encode($errori, JSON_UNESCAPED_UNICODE))->not->toContain('Errore di sistema')->not->toContain('collection')
        ->and(BudgetMovement::count())->toBe(0)
        ->and(be52Pivot($piano->fresh()))->toBe($pivot);
});

it('«Sposta spesa»: la destinazione scritta come elenco si rifiuta sul campo, senza il testo di sistema e senza scrivere', function () {
    $s = be52Scenario($this);
    $pulizie = be52Voce($this, $s, $s['pc'], 'Pulizie', 'spesa', '600,00');
    $piano = be52PianoDallaRotta($this, $s);
    $pivot = be52Pivot($piano->fresh());

    be52ElenchiSposta($this, $s, $piano, ['source_id' => $s['spesa']->id, 'destination_id' => [$pulizie->id]]);
    $errori = be52Errori();

    expect(array_keys($errori))->toBe(['destination_id'])
        ->and($errori['destination_id'][0])->toBe('La voce su cui spostare il budget non è valida: scegli una voce dal menu «Sposta su».')
        ->and(json_encode($errori, JSON_UNESCAPED_UNICODE))->not->toContain('Errore di sistema')->not->toContain('collection')
        ->and(BudgetMovement::count())->toBe(0)
        ->and(be52Pivot($piano->fresh()))->toBe($pivot);
});

it('«Sposta spesa»: i due elenchi, o un elenco di due id, prendono l\'errore sul campo e non scrivono', function () {
    $s = be52Scenario($this);
    $pulizie = be52Voce($this, $s, $s['pc'], 'Pulizie', 'spesa', '600,00');
    $piano = be52PianoDallaRotta($this, $s);
    $pivot = be52Pivot($piano->fresh());

    be52ElenchiSposta($this, $s, $piano, ['source_id' => [$s['spesa']->id], 'destination_id' => [$pulizie->id]]);
    expect(array_keys(be52Errori()))->toBe(['source_id', 'destination_id']);
    session()->forget('errors');

    be52ElenchiSposta($this, $s, $piano, ['source_id' => [$s['spesa']->id, $pulizie->id], 'destination_id' => $pulizie->id]);
    expect(array_keys(be52Errori()))->toBe(['source_id'])
        ->and(json_encode(be52Errori(), JSON_UNESCAPED_UNICODE))->not->toContain('Errore di sistema')
        ->and(BudgetMovement::count())->toBe(0)
        ->and(be52Pivot($piano->fresh()))->toBe($pivot);
});

it('«Sposta spesa»: un elenco con una voce di un altro condominio resta rifiutato e non nomina nessuno', function () {
    $s = be52Scenario($this);
    $altro = be52Scenario($this);
    $pulizie = be52Voce($this, $s, $s['pc'], 'Pulizie', 'spesa', '600,00');
    $piano = be52PianoDallaRotta($this, $s);

    be52ElenchiSposta($this, $s, $piano, ['source_id' => [$s['spesa']->id, $altro['spesa']->id], 'destination_id' => $pulizie->id]);

    expect(array_keys(be52Errori()))->toBe(['source_id'])
        ->and(BudgetMovement::count())->toBe(0);
});

it('controprova — «Sposta spesa» fra due spese scritte come numeri passa ancora', function () {
    $s = be52Scenario($this);
    $pulizie = be52Voce($this, $s, $s['pc'], 'Pulizie', 'spesa', '600,00');
    $piano = be52PianoDallaRotta($this, $s);

    be52ElenchiSposta($this, $s, $piano, ['source_id' => $s['spesa']->id, 'destination_id' => $pulizie->id])->assertSessionHasNoErrors();
    be52ElenchiSposta($this, $s, $piano, ['source_id' => (string) $s['spesa']->id, 'destination_id' => (string) $pulizie->id])->assertSessionHasNoErrors();

    expect(BudgetMovement::count())->toBe(2);
});

// ─── Regolazione immediata ──────────────────────────────────────────────────────────────────────────────────────

it('regolazione immediata: un elenco al posto di ciascun id si rifiuta sul campo e non scrive nessuna scrittura', function (string $campo, string $frase) {
    $s = be52Scenario($this);
    $f = be52Fornitore();
    $valori = ['gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id, 'conto_id' => $s['spesa']->id, 'cassa_id' => $s['cassa']->id, 'fornitore_id' => $f->id];

    $r = $this->actingAs($this->user)->post(route('admin.gestionale.regolazioni-immediate.store', [$s['c']]), be52ElenchiRegolazione($s, [$campo => [$valori[$campo]]]));
    $errori = be52Errori();

    expect($r->status())->toBe(302)
        ->and(array_keys($errori))->toBe([$campo])
        ->and($errori[$campo][0])->toBe($frase)
        ->and(be52ElenchiScritture($s))->toBe(0);
})->with([
    'gestione_id' => ['gestione_id', 'La gestione scelta non è valida: scegli una gestione dal menu.'],
    'esercizio_id' => ['esercizio_id', 'L\'esercizio scelto non è valido: scegli un esercizio dal menu.'],
    'conto_id' => ['conto_id', 'Il capitolo di spesa scelto non è valido: scegli una voce di spesa dal menu.'],
    'cassa_id' => ['cassa_id', 'La cassa scelta non è valida: scegli una cassa dal menu.'],
    'fornitore_id' => ['fornitore_id', 'Il fornitore scelto non è valido: scegli un fornitore dall\'elenco.'],
]);

it('controprova — la regolazione immediata su una voce di spesa, con la cassa e il fornitore scelti, si registra ancora', function () {
    $s = be52Scenario($this);
    $f = be52Fornitore();

    $this->actingAs($this->user)->post(route('admin.gestionale.regolazioni-immediate.store', [$s['c']]), be52ElenchiRegolazione($s, ['fornitore_id' => $f->id]))->assertSessionHasNoErrors();

    expect(be52ElenchiScritture($s))->toBe(1);
});

// ─── La voce di entrata scritta in un'altra forma ───────────────────────────────────────────────────────────────

it('regolazione immediata: la voce di entrata scritta «+id», con un decimale o con il punto zero prende il rifiuto sul campo della voce e non scrive', function (string $forma) {
    $s = be52Scenario($this);
    $id = $s['entrata']->id;
    $valore = match ($forma) {
        'più' => '+'.$id,
        'decimale' => (float) $id,
        'punto zero' => $id.'.0',
    };

    $r = $this->actingAs($this->user)->post(route('admin.gestionale.regolazioni-immediate.store', [$s['c']]), be52ElenchiRegolazione($s, ['conto_id' => $valore]));
    $errori = be52Errori();

    expect($r->status())->toBe(302)
        ->and(array_keys($errori))->toBe(['conto_id'])
        ->and(DB::table('righe_scritture')->where('voce_spesa_id', $id)->count())->toBe(0)
        ->and(be52ElenchiScritture($s))->toBe(0);
})->with(['più', 'decimale', 'punto zero']);

it('regolazione immediata: la voce di entrata scritta «+id» o con un decimale dice che è un\'entrata e di sceglierne una di spesa', function () {
    $s = be52Scenario($this);

    foreach (['+'.$s['entrata']->id, (float) $s['entrata']->id] as $valore) {
        $this->actingAs($this->user)->post(route('admin.gestionale.regolazioni-immediate.store', [$s['c']]), be52ElenchiRegolazione($s, ['conto_id' => $valore]));
        $testo = be52Errori()['conto_id'][0] ?? '';
        session()->forget('errors');

        expect($testo)->toContain('«Affitto antenna» è una voce di entrata')->toContain('Scegli una voce di spesa');
    }
});

it('regolazione immediata: la forma con il punto zero dice che l\'id non è un numero intero, sul campo della voce', function () {
    $s = be52Scenario($this);

    $this->actingAs($this->user)->post(route('admin.gestionale.regolazioni-immediate.store', [$s['c']]), be52ElenchiRegolazione($s, ['conto_id' => $s['entrata']->id.'.0']));

    expect(be52Errori()['conto_id'][0] ?? null)->toBe('Il capitolo di spesa scelto non è valido: scegli una voce di spesa dal menu.');
});

it('controprova — una voce di spesa scritta «+id» o come stringa si registra ancora', function () {
    $s = be52Scenario($this);

    $this->actingAs($this->user)->post(route('admin.gestionale.regolazioni-immediate.store', [$s['c']]), be52ElenchiRegolazione($s, ['conto_id' => '+'.$s['spesa']->id]))->assertSessionHasNoErrors();
    $this->actingAs($this->user)->post(route('admin.gestionale.regolazioni-immediate.store', [$s['c']]), be52ElenchiRegolazione($s, ['conto_id' => (string) $s['spesa']->id]))->assertSessionHasNoErrors();

    expect(be52ElenchiScritture($s))->toBe(2);
});

it('controprova — la voce di entrata scritta come numero resta rifiutata sul campo della voce, nel testo di sempre', function () {
    $s = be52Scenario($this);

    $this->actingAs($this->user)->post(route('admin.gestionale.regolazioni-immediate.store', [$s['c']]), be52ElenchiRegolazione($s, ['conto_id' => $s['entrata']->id]));
    $errori = be52Errori();

    expect(array_keys($errori))->toBe(['conto_id'])
        ->and($errori['conto_id'][0])->toContain('«Affitto antenna» è una voce di entrata')
        ->and(be52ElenchiScritture($s))->toBe(0);
});
