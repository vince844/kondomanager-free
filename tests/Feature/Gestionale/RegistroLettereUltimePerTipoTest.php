<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * L'ultima lettera di ogni tipo di una persona (`per_tipo` di `RegistroLettere::ultimePerPersone`): la diffida o messa in mora più recente, il sollecito più
 * recente, il promemoria più recente, ognuno con lo stato, il giorno, il canale, la spedizione, il termine e le rate che chiedeva. Una diffida seguita da un
 * sollecito resta nella sua chiave: `ultima` è il sollecito, come prima. Le rate si leggono con una seconda lettura, solo per quelle lettere.
 */

use App\Services\Gestionale\Lettere\RegistroLettere;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/SeiMesiPerAgireScenario.php';

beforeEach(function () {
    $this->user = smaUtente();
    $this->withoutVite();
});

function rlpUltime(array $s, string $chi): array
{
    return app(RegistroLettere::class)->ultimePerPersone((int) $s['c']->id, [(int) $s['persone'][$chi]->id], '2026-05-20')[(int) $s['persone'][$chi]->id];
}

it('per_tipo: la diffida spedita resta dopo un sollecito per email; `ultima`, `in_sospeso` e `conta` sono quelli di prima', function () {
    $s = smaViaVerdi($this);
    $promemoria = smaLettera($s, 'rossi', 'promemoria', 'inviata', '2026-02-01', [3]);
    $diffida = smaLettera($s, 'rossi', 'diffida', 'spedita', '2026-04-20', [1, 2, 3, 4]);
    $sollecito = smaLettera($s, 'rossi', 'sollecito', 'inviata', '2026-05-15', [5]);
    $u = rlpUltime($s, 'rossi');

    expect($u['ultima']['tipo'])->toBe('sollecito')
        ->and($u['conta']['tipo'])->toBe('sollecito')
        ->and($u['in_sospeso'])->toBeNull()
        ->and($u['per_tipo']['diffida']['id'])->toBe($diffida->id)
        ->and($u['per_tipo']['diffida']['tipo'])->toBe('diffida')
        ->and($u['per_tipo']['diffida']['stato'])->toBe('spedita')
        ->and($u['per_tipo']['diffida']['data'])->toBe('2026-04-20')
        ->and($u['per_tipo']['diffida']['spedizione'])->toMatchArray(['canale' => 'raccomandata', 'data' => '2026-04-20'])
        ->and($u['per_tipo']['diffida']['termine'])->toBe(15)
        ->and($u['per_tipo']['diffida']['termine_forma'])->toBe('giorni')
        ->and($u['per_tipo']['diffida']['righe'])->toBe([smaChiave($s, 'rossi', 1), smaChiave($s, 'rossi', 2), smaChiave($s, 'rossi', 3), smaChiave($s, 'rossi', 4)])
        ->and($u['per_tipo']['sollecito']['id'])->toBe($sollecito->id)
        ->and($u['per_tipo']['sollecito']['canale'])->toBe('email')
        ->and($u['per_tipo']['sollecito']['righe'])->toBe([smaChiave($s, 'rossi', 5)])
        ->and($u['per_tipo']['promemoria']['id'])->toBe($promemoria->id);
});

it('per_tipo: la messa in mora più recente della diffida sta nella chiave della diffida; un sollecito non partito è l\'ultimo sollecito', function () {
    $s = smaViaVerdi($this);
    smaLettera($s, 'rossi', 'diffida', 'spedita', '2026-03-01', [1, 2]);
    $mora = smaLettera($s, 'rossi', 'messa_in_mora', 'stampata', '2026-04-01', [1, 2, 3]);
    smaLettera($s, 'rossi', 'sollecito', 'spedita', '2026-02-01', [1]);
    $nonPartito = smaLettera($s, 'rossi', 'sollecito', 'non_partita', '2026-05-01', [1, 2, 3, 4]);
    $u = rlpUltime($s, 'rossi');

    expect($u['per_tipo']['diffida']['id'])->toBe($mora->id)
        ->and($u['per_tipo']['diffida']['tipo'])->toBe('messa_in_mora')
        ->and($u['per_tipo']['diffida']['stato'])->toBe('stampata')
        ->and($u['per_tipo']['diffida']['spedizione'])->toBeNull()
        ->and($u['per_tipo']['sollecito']['id'])->toBe($nonPartito->id)
        ->and($u['per_tipo']['sollecito']['stato'])->toBe('non_partita')
        ->and($u['per_tipo']['promemoria'])->toBeNull();
});

it('le rate si leggono con una seconda lettura, solo per le lettere di per_tipo', function () {
    $s = smaViaVerdi($this);
    foreach (range(1, 4) as $i) {
        smaLettera($s, 'bianchi', 'sollecito', 'inviata', '2026-0' . $i . '-10', [1]);
    }
    $ultimo = smaLettera($s, 'bianchi', 'sollecito', 'inviata', '2026-05-10', [1, 2]);
    $letture = [];
    DB::listen(function ($q) use (&$letture) {
        if (str_contains($q->sql, 'registro_invii') && str_contains($q->sql, 'righe')) {
            $letture[] = $q->bindings;
        }
    });
    $u = rlpUltime($s, 'bianchi');

    expect($letture)->toHaveCount(1)
        ->and(array_values(array_filter($letture[0], 'is_int')))->toBe([$ultimo->id])
        ->and($u['per_tipo']['sollecito']['righe'])->toBe([smaChiave($s, 'bianchi', 1), smaChiave($s, 'bianchi', 2)]);
});

it('senza lettere la persona non c\'è, come prima', function () {
    $s = smaViaVerdi($this);

    expect(app(RegistroLettere::class)->ultimePerPersone((int) $s['c']->id, [(int) $s['persone']['verdi']->id], '2026-05-20'))->toBe([]);
});
