<?php

/**
 * 1.11.0-beta.53 (Coda 244) — la nota di solidarietà (art. 63, co. 4 disp. att. c.c.) con le righe legate alla vendita: i casi che
 * `Beta53NotaSolidarietaLegataTest` non costruisce.
 *
 * La nota resta lorda per le righe legate e le dice a parte, con la loro somma sull'unità della nota: più righe di chi compra (su gestioni
 * diverse) si dicono insieme, al plurale («con saldi scritti a mano»); righe che sommano zero non si dicono; la riga legata sul box passato
 * con la vendita sta nella nota del box, non in quella dell'appartamento. Nella vendita con riserva d'usufrutto la frase è la stessa.
 *
 * Le cifre: la fermata della vendita del 1/11 è € 60,16 (6016) verso Elsa (`Beta53SaldoLegatoScenari.php`); la nota dell'appartamento dice
 * € 629,62 non pagati (63000 − 38 della coppia della vendita), e le righe legate non la cambiano.
 *
 * Che cosa NON copre: le pagine che mostrano la nota (estratto conto, situazione debitoria), che leggono `testo`; la nota del box con le sue
 * cifre (qui se ne guarda solo la frase delle righe legate); MySQL.
 */

use App\Models\Gestionale\Subentro;
use App\Models\Gestione;
use App\Services\Subentro\NotaSolidarieta;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/Beta53SaldoLegatoScenari.php';
require_once __DIR__ . '/Support/ScenariPassaggi.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    sl53Amministratore($this);
});

const SL53Z_ELSA = 'Elsa ha inoltre a suo debito € 60,16 con un saldo scritto a mano per lo stesso passaggio.';

/** Le note di solidarietà di chi compra, per unità (il nome dell'unità). */
function sl53zNote(array $s, string $chiCompra = 'Elsa'): array
{
    return collect(app(NotaSolidarieta::class)->per($s['c'], $s['persone'][$chiCompra]))->filter(fn ($n) => (int) ($n['subentro_id'] ?? 0) > 0)->keyBy('immobile')->all();
}

/** La nota dell'appartamento della vendita. */
function sl53zNota(array $s): array
{
    $nota = collect(app(NotaSolidarieta::class)->per($s['c'], $s['persone']['Elsa']))->firstWhere('subentro_id', (int) $s['vendita']->id);
    expect($nota)->not->toBeNull();

    return $nota;
}

/** Un'altra gestione attiva del condominio, legata all'esercizio aperto. */
function sl53zAltraGestione(array $s): Gestione
{
    $g = Gestione::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Straordinaria facciata', 'tipo' => 'straordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'attiva' => true]);
    legaAEsercizio($s['e'], $g->id);

    return $g;
}

it('due righe legate di chi compra, su due gestioni: la nota le dice insieme, al plurale, e la cifra non cambia', function () {
    $s = sl53Scenario($this);
    $altra = sl53zAltraGestione($s);
    expect(sl53Errori(sl53Posta($this, $s, 'Elsa', 6016, (int) $s['vendita']->id)))->toBe([]);
    expect(sl53Errori(sl53Posta($this, $s, 'Elsa', 1000, (int) $s['vendita']->id, ['gestione_id' => $altra->id])))->toBe([]);

    $nota = sl53zNota($s);

    expect($nota['residuo_uscente_cents'])->toBe(62962)
        ->and(str_contains($nota['testo'], 'Elsa ha inoltre a suo debito € 70,16 con saldi scritti a mano per lo stesso passaggio.'))->toBeTrue($nota['testo']);
});

it('righe legate di chi compra che sommano zero: la nota non le dice', function () {
    $s = sl53Scenario($this);
    $altra = sl53zAltraGestione($s);
    expect(sl53Errori(sl53Posta($this, $s, 'Elsa', 6016, (int) $s['vendita']->id)))->toBe([]);
    expect(sl53Errori(sl53Posta($this, $s, 'Elsa', -6016, (int) $s['vendita']->id, ['gestione_id' => $altra->id])))->toBe([]);

    $nota = sl53zNota($s);

    expect($nota['residuo_uscente_cents'])->toBe(62962)
        ->and(str_contains($nota['testo'], 'scritti a mano'))->toBeFalse($nota['testo'])
        ->and(str_contains($nota['testo'], 'scritto a mano'))->toBeFalse($nota['testo']);
});

it('la riga legata sul box passato con la vendita sta nella nota del box, non in quella dell\'appartamento', function () {
    $s = sl53Scenario($this, box: true);
    expect(sl53Errori(sl53Posta($this, $s, 'Elsa', 6016, (int) $s['vendita']->id)))->toBe([]);
    expect(sl53Errori(sl53Posta($this, $s, 'Elsa', 1000, (int) $s['vendita']->id, ['immobile_id' => $s['box']->id])))->toBe([]);

    $nota = sl53zNota($s);

    expect(str_contains($nota['testo'], SL53Z_ELSA))->toBeTrue($nota['testo'])
        ->and(str_contains($nota['testo'], '€ 10,00'))->toBeFalse($nota['testo']);
    $note = sl53zNote($s);
    $delBox = collect($note)->first(fn ($n, $unita) => str_starts_with((string) $unita, 'Box'));
    expect($delBox)->not->toBeNull('Nessuna nota per il box: ' . implode(' | ', array_keys($note)));
    expect(str_contains($delBox['testo'], 'Elsa ha inoltre a suo debito € 10,00 con un saldo scritto a mano per lo stesso passaggio.'))->toBeTrue($delBox['testo']);
});

it('nella vendita con riserva d\'usufrutto la riga legata di chi compra la nuda proprietà si dice allo stesso modo', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    $sub = ruRegistra($this, $s, ruRiserva($s));
    expect($sub->riservaUsufrutto())->toBeTrue();
    $this->travel(1)->minutes();
    $r = $this->actingAs($this->user)->from('/saldi')->post(route('admin.gestionale.saldi.store', [$s['c']]), [
        'anagrafica_id' => $s['a']->id, 'immobile_id' => $s['unita']->id, 'gestione_id' => $s['g']->id, 'saldo_iniziale' => 1000, 'passaggio_id' => $sub->id,
    ]);
    expect(sl53Errori($r))->toBe([]);

    $nota = collect(app(NotaSolidarieta::class)->per($s['c'], $s['a']))->firstWhere('subentro_id', (int) $sub->id);

    expect($nota)->not->toBeNull();
    expect(str_contains($nota['testo'], 'rispondono in solido'))->toBeTrue($nota['testo'])
        ->and(str_contains($nota['testo'], 'Acquirente Elsa ha inoltre a suo debito € 10,00 con un saldo scritto a mano per lo stesso passaggio.'))->toBeTrue($nota['testo']);
});
