<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La risposta resta quando l'evento non c'è più: gli eventi finiti da più di un anno si potano ogni giorno (`Evento::prunable()`, con una query), e il compito
 * «Emettere rata» si cancella quando la rata è emessa. La risposta tiene il titolo e il tipo del compito copiati al primo gesto, e il suo `evento_id` diventa
 * vuoto (la chiave esterna lascia vuoto, anche per le cancellazioni fatte con una query).
 */

use App\Enums\EventoTipo;
use App\Models\Evento;
use App\Models\Gestionale\RispostaPromemoria;
use Illuminate\Support\Facades\Artisan;

require_once __DIR__.'/InboxCompitiScenario.php';

beforeEach(function () {
    $this->withoutVite();
    icsInizia($this);
});

it('la potatura degli eventi finiti da più di un anno toglie l\'evento; la risposta resta, con il titolo e il tipo', function () {
    $e = icsCompito($this, 'controllo_incassi', [], 'Verifica incassi - Rata 3');
    icsRispondi($this, $e, ['tipo' => 'fatto', 'nota' => 'tutto incassato'])->assertSessionHasNoErrors();

    $this->travel(400)->days();
    Artisan::call('model:prune', ['--model' => [Evento::class]]);
    $r = RispostaPromemoria::first();

    expect(Evento::find($e->id))->toBeNull()
        ->and($r->evento_id)->toBeNull()
        ->and($r->titolo)->toBe('Verifica incassi - Rata 3')
        ->and($r->tipo_compito)->toBe('controllo_incassi')
        ->and($r->stato)->toBe('fatto')
        ->and($r->nota)->toBe('tutto incassato');
});

it('la cancellazione del compito «Emettere rata» con una query lascia la risposta, con il titolo e il tipo', function () {
    $e = icsCompito($this, 'emissione_rata', ['context' => ['rata_id' => 77]], 'Emettere rata 3');
    icsRispondi($this, $e, ['tipo' => 'ricordamelo', 'giorno' => '2026-06-01'])->assertSessionHasNoErrors();

    // Come `EmissioneRateController` all'emissione della rata.
    Evento::where('tipo', EventoTipo::EMISSIONE_RATA->value)->where('meta->context->rata_id', 77)->delete();
    $r = RispostaPromemoria::first();

    expect(Evento::count())->toBe(0)
        ->and($r->evento_id)->toBeNull()
        ->and($r->promemoria_evento_id)->toBeNull()
        ->and($r->titolo)->toBe('Emettere rata 3')
        ->and($r->tipo_compito)->toBe('emissione_rata')
        ->and(collect($r->registro)->pluck('tipo')->all())->toBe(['ricordamelo']);
});
