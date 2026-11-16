<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La ricerca, i filtri e le risposte dell'Action inbox con valori scritti a mano: nessun errore, niente fuori dalle regole. La ricerca passa come parametro,
 * con «!», «%» e «_» cercati come sono; l'ordine, la scheda e il tipo vengono solo da elenchi fissi; le risposte sbagliate non scrivono niente.
 */

use App\Models\Gestionale\RispostaPromemoria;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/InboxCompitiScenario.php';

beforeEach(function () {
    $this->withoutVite();
    icsInizia($this);
});

it('la ricerca e i filtri dell\'Inbox con valori ostili: nessun errore; «_» e «!» si cercano come sono', function () {
    icsCompito($this, 'controllo_incassi', [], 'Verifica incassi - Rata 3');
    icsCompito($this, 'emissione_rata', [], 'Rata_7 da emettere!');
    $ostili = [
        ['cerca' => "' OR 1=1 --"], ['cerca' => '") OR ("1"="1'], ['cerca' => ['a', 'b']], ['cerca' => str_repeat('x', 5000)], ['cerca' => "\xC3\x28"],
        ['ordine' => 'eventi.id; DROP TABLE eventi'], ['ordine' => ['recenti']], ['quando' => ['scaduti']], ['tipo' => ['x']], ['tipo' => "segnalazione_guasto' OR '1'='1"],
        ['condominio_id' => 'abc'], ['condominio_id' => ['1']], ['condominio_id' => '-1'], ['condominio_id' => '1 OR 1=1'], ['page' => '-5'], ['page' => ['2']],
    ];
    foreach ($ostili as $q) {
        Cache::flush();
        $this->actingAs($this->admin)->get(route('admin.inbox', $q))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.gestionale.index', ['condominio' => $this->condominio] + $q))->assertOk();
    }
    $titoli = fn (array $q) => collect($this->actingAs($this->admin)->get(route('admin.inbox', $q))->viewData('page')['props']['tasks']['data'])->pluck('title')->all();
    // «Rata_3» non trova «Rata 3»: senza l'escape «_» sarebbe un carattere qualunque.
    expect($titoli(['cerca' => 'Rata_3']))->toBe([])
        ->and($titoli(['cerca' => 'Rata_7']))->toBe(['Rata_7 da emettere!'])
        ->and($titoli(['cerca' => 'emettere!']))->toBe(['Rata_7 da emettere!'])
        ->and($titoli(['cerca' => "' OR 1=1 --"]))->toBe([]);
});

it('le risposte con valori ostili non scrivono niente', function () {
    $e = icsCompito($this, 'controllo_incassi');
    foreach ([['tipo' => ['fatto']], ['tipo' => 'fatto', 'nota' => ['x']], ['tipo' => 'ricordamelo', 'giorno' => '2026-05-20'], ['tipo' => 'ricordamelo', 'giorno' => ['2026-06-01']],
        ['tipo' => 'non_serve', 'motivo' => ['abc']], ['tipo' => 'non_serve', 'motivo' => '  ']] as $dati) {
        icsRispondi($this, $e, $dati)->assertSessionHasErrors();
    }
    expect($e->fresh()->is_completed)->toBeFalse()->and(RispostaPromemoria::count())->toBe(0);
});
