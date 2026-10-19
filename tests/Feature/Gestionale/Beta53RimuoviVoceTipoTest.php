<?php

/**
 * Controprova lato server della conferma di «Rimuovi voce» nella pagina del piano rate: la pagina riceve il tipo di ogni voce e la copia com'è scritta.
 *
 * La conferma dice «voce di entrata» e «il totale da ripartire aumenterà» per una voce di entrata, e mostra l'importo indicato senza il segno
 * (`PianiRateShowRimuoviVoceEntrata.test.ts`): la pagina lo decide dal `tipo` della voce, che il server manda per ogni voce del piano
 * (`Conto::segno` alla data del piano: un capitolo di sole entrate è «entrata»), e dall'`importo`, la copia del piano, che per un dato di prima
 * della 1.11.0-beta.52 può avere il meno. Questo file prova che il server manda proprio questi campi, così il vitest della pagina li può scrivere a mano.
 *
 * I test sono tutti controprove: verdi prima e dopo la correzione, che tocca solo la pagina.
 *
 * Cosa resta scoperto: la conferma a video (il vitest); il capitolo con sottoconti di tipo diverso (il tipo è quello del capitolo); il piano straordinario
 * (le sue voci sono fatture, senza tipo); i database diversi da sqlite.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta53SpesaNegativaScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->user = b52tAdmin();
    b52tSqliteJson();
});

/** Le voci della pagina del piano: nome => [tipo, importo, is_parent]. */
function b53rvVoci($test, array $s, $piano): array
{
    return collect(b53snPaginaPiano($test, $s, $piano)['pianoRate']['capitoli'])->mapWithKeys(fn ($c) => [$c['nome'] => [$c['tipo'] ?? null, (int) $c['importo'], (bool) $c['is_parent']]])->all();
}

it('controprova — la pagina del piano manda il tipo di ogni voce e la copia com\'è: spesa, entrata, e la spesa scritta col meno', function () {
    $s = b53snV2($this);

    expect(b53rvVoci($this, $s, $s['piano']))->toBe([
        'Spese generali' => ['spesa', 120000, false],
        'Affitto antenna' => ['entrata', 24000, false],
        'Rimborso vecchio' => ['spesa', -24000, false],
    ]);
});

it('controprova — un\'entrata con la copia scritta col meno arriva alla pagina come «entrata» con l\'importo −24000', function () {
    $s = b53snBase($this);
    $piano = b52tPiano($this, $s, [], 'Ordinario 2026');
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->where('conto_id', $s['antenna']->id)->update(['importo' => -24000]);

    expect(b53rvVoci($this, $s, $piano)['Affitto antenna'])->toBe(['entrata', -24000, false]);
});

it('controprova — un capitolo di soli sottoconti di entrata arriva alla pagina come un gruppo di tipo «entrata»', function () {
    $s = b52tScenarioConPadre($this, 'entrata');

    expect(b53rvVoci($this, $s, $s['piano'])['Manutenzione'])->toBe(['entrata', 100000, true]);
});
