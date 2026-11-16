<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * «Mettilo in agenda» sulla riga del rendiconto e sul suo compito: un evento nell'agenda del condominio, di serie nel giorno dei 180 giorni, che si cambia (da
 * oggi in poi), alle 09:00 come si leggono nell'agenda; il suo id nella risposta della riga. Dopo, «In agenda il …» con il giorno letto dall'evento, e nessun secondo evento; tolto
 * l'evento dall'agenda torna il pulsante. Non chiude il compito.
 */

use App\Enums\EventoTipo;
use App\Models\Evento;
use App\Models\Gestionale\RispostaPromemoria;

require_once __DIR__.'/SeiMesiPerAgireScenario.php';

beforeEach(function () {
    $this->user = smaUtente();
    $this->withoutVite();
});

function riaAgenda($test, array $s, ?string $giorno = null)
{
    return smaRispondi($test, $s, array_filter(['tipo' => 'in_agenda', 'argomento' => 'rendiconto', 'esercizio_id' => $s['e2025']->id, 'giorno' => $giorno, 'nota' => 'sala condominiale'],
        fn ($v) => $v !== null));
}

function riaRendiconto(array $s): array
{
    return collect(smaRiquadro($s)['rendiconti'])->firstWhere('esercizio.nome', 'Esercizio 2025');
}

it('scrive l\'evento nel giorno dei 180 giorni, alle 09:00, con il titolo del rendiconto; la riga dice «In agenda il …»; il compito resta', function () {
    $s = smaViaVerdi($this);
    riaAgenda($this, $s)->assertSessionHasNoErrors();
    $e = Evento::first();

    expect(Evento::count())->toBe(1)
        ->and($e->tipo)->toBe(EventoTipo::PROMEMORIA)
        ->and($e->meta['requires_action'])->toBeFalse()
        ->and($e->title)->toBe('Convocare l\'assemblea per il rendiconto 2025 — Via Verdi 12')
        ->and($e->start_time->format('Y-m-d H:i'))->toBe('2026-06-29 09:00')
        ->and(RispostaPromemoria::first()->agenda_evento_id)->toBe($e->id)
        ->and(collect(RispostaPromemoria::first()->registro)->last()['tipo'])->toBe('in_agenda')
        ->and(riaRendiconto($s)['agenda'])->toBe(['giorno' => '2026-06-29'])
        ->and(riaRendiconto($s)['compito'])->toBeTrue();
});

it('nel giorno scelto; un giorno prima di oggi no', function () {
    $s = smaViaVerdi($this);
    riaAgenda($this, $s, '2026-05-19')->assertSessionHasErrors(['giorno' => 'Scegli un giorno da oggi in poi.']);
    riaAgenda($this, $s, '2026-06-10')->assertSessionHasNoErrors();

    expect(Evento::first()->start_time->format('Y-m-d H:i'))->toBe('2026-06-10 09:00');
});

it('«In agenda il …» legge il giorno dall\'evento spostato; un secondo «Mettilo in agenda» non scrive un altro evento', function () {
    $s = smaViaVerdi($this);
    riaAgenda($this, $s)->assertSessionHasNoErrors();
    Evento::first()->update(['start_time' => '2026-06-20 07:00:00', 'end_time' => '2026-06-20 08:00:00']);
    expect(riaRendiconto($s)['agenda'])->toBe(['giorno' => '2026-06-20']);

    riaAgenda($this, $s, '2026-06-25')->assertSessionHasNoErrors();
    expect(Evento::count())->toBe(1)->and(riaRendiconto($s)['agenda'])->toBe(['giorno' => '2026-06-25']);
});

it('l\'evento cancellato dall\'agenda fa tornare il pulsante', function () {
    $s = smaViaVerdi($this);
    riaAgenda($this, $s)->assertSessionHasNoErrors();
    Evento::query()->delete();

    expect(riaRendiconto($s)['agenda'])->toBeNull();
    riaAgenda($this, $s)->assertSessionHasNoErrors();
    expect(Evento::count())->toBe(1);
});
