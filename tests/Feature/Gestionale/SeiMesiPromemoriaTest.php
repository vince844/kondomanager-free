<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * «Ricordamelo» sul compito dei sei mesi va nell'agenda del condominio: un evento nuovo (`InboxService::creaPromemoria()`), di tipo «promemoria», che non è un
 * compito (`meta.requires_action` falso), il giorno scelto alle 09:00, scritte come le scrive l'agenda (l'ora così come si legge), un'ora; il suo id nella risposta. Un secondo «Ricordamelo» sposta
 * lo stesso evento. Il giorno lo dice l'agenda: spostato nell'agenda, il compito torna il giorno nuovo; cancellato, il compito torna subito e lo dice.
 */

use App\Enums\EventoTipo;
use App\Models\Evento;
use App\Models\Gestionale\RispostaPromemoria;

require_once __DIR__.'/SeiMesiPerAgireScenario.php';

beforeEach(function () {
    $this->user = smaUtente();
    $this->withoutVite();
});

function sprRicordamelo($test, array $s, string $giorno, string $nota = '')
{
    return smaRispondi($test, $s, ['tipo' => 'ricordamelo', 'argomento' => 'sei_mesi', 'esercizio_id' => $s['e2025']->id, 'giorno' => $giorno, 'nota' => $nota]);
}

function sprCompito(array $s): ?array
{
    return collect(smaRiquadro($s)['compiti'])->firstWhere('argomento', 'sei_mesi');
}

it('scrive un evento «promemoria» che non è un compito, il giorno scelto alle 09:00, nel condominio, con il ritorno al compito', function () {
    $s = smaViaVerdi($this);
    sprRicordamelo($this, $s, '2026-06-15', 'sentire l\'avvocato')->assertSessionHasNoErrors();
    $r = RispostaPromemoria::first();
    $e = Evento::find($r->promemoria_evento_id);

    expect(Evento::count())->toBe(1)
        ->and($e->tipo)->toBe(EventoTipo::PROMEMORIA)
        ->and($e->meta['requires_action'])->toBeFalse()
        ->and($e->meta['context']['risposta_id'])->toBe($r->id)
        ->and($e->meta['action_url'])->toContain('#sei-mesi-' . $s['e2025']->id)
        ->and($e->start_time->format('Y-m-d H:i'))->toBe('2026-06-15 09:00')
        ->and($e->end_time->format('Y-m-d H:i'))->toBe('2026-06-15 10:00')
        ->and($e->title)->toBe('Ricordamelo: sei mesi per agire, esercizio 2025 — Via Verdi 12')
        ->and($e->description)->toContain('sentire l\'avvocato')
        ->and($e->condomini()->pluck('condomini.id')->all())->toBe([$s['c']->id])
        ->and($e->is_completed)->toBeFalse();
});

it('un secondo «Ricordamelo» sposta lo stesso evento, non ne scrive due', function () {
    $s = smaViaVerdi($this);
    sprRicordamelo($this, $s, '2026-06-15')->assertSessionHasNoErrors();
    sprRicordamelo($this, $s, '2026-06-20')->assertSessionHasNoErrors();

    expect(Evento::count())->toBe(1)
        ->and(Evento::first()->start_time->format('Y-m-d H:i'))->toBe('2026-06-20 09:00')
        ->and(RispostaPromemoria::first()->ricordami_il->format('Y-m-d'))->toBe('2026-06-20');
});

it('il promemoria spostato nell\'agenda sposta il giorno del compito', function () {
    $s = smaViaVerdi($this);
    sprRicordamelo($this, $s, '2026-06-15')->assertSessionHasNoErrors();
    Evento::first()->update(['start_time' => '2026-05-22 07:00:00', 'end_time' => '2026-05-22 08:00:00']);

    expect(sprCompito($s))->toBeNull()
        ->and(smaRiga(smaRiquadro($s), 'Esercizio 2025')['risposta']['frase'])->toBe('Ti ricordo il 22/05/2026.');
    $this->travelTo('2026-05-22 10:00:00');
    expect(sprCompito($s))->not->toBeNull();
});

it('il promemoria cancellato dall\'agenda fa tornare il compito subito, e lo dice', function () {
    $s = smaViaVerdi($this);
    sprRicordamelo($this, $s, '2026-06-15')->assertSessionHasNoErrors();
    expect(sprCompito($s))->toBeNull();
    Evento::query()->delete();

    expect(sprCompito($s)['tornato'])->toBe('Il promemoria nell\'agenda è stato tolto: il compito è tornato.')
        ->and(RispostaPromemoria::first()->fresh()->promemoria_evento_id)->toBeNull();

    // Un «Ricordamelo» nuovo scrive un evento nuovo.
    sprRicordamelo($this, $s, '2026-06-18')->assertSessionHasNoErrors();
    expect(Evento::count())->toBe(1);
});

it('il giorno è nel calendario dell\'amministratore: alle 00:30 di Roma «domani» è il giorno di Roma', function () {
    $s = smaViaVerdi($this);
    // Le 22:30 del 20/05 in UTC sono le 00:30 del 21/05 a Roma: oggi è il 21, e il 21 non è più «da domani».
    $this->travelTo('2026-05-20 22:30:00');
    sprRicordamelo($this, $s, '2026-05-21')->assertSessionHasErrors(['giorno' => 'Scegli un giorno da domani in poi.']);
    sprRicordamelo($this, $s, '2026-05-22')->assertSessionHasNoErrors();

    expect(Evento::first()->start_time->format('Y-m-d H:i'))->toBe('2026-05-22 09:00');
});

it('«Non serve», «Fatto» o «Riapri» dopo «Ricordamelo» tolgono il promemoria dall\'agenda, e il registro lo dice', function (string $tipo, array $dati) {
    $s = smaViaVerdi($this);
    sprRicordamelo($this, $s, '2026-06-15')->assertSessionHasNoErrors();
    expect(Evento::count())->toBe(1);

    smaRispondi($this, $s, ['tipo' => $tipo, 'argomento' => 'sei_mesi', 'esercizio_id' => $s['e2025']->id] + $dati)->assertSessionHasNoErrors();
    $r = RispostaPromemoria::where('chiave', \App\Services\Gestionale\RispostePromemoria::chiave('sei_mesi', (int) $s['c']->id, (int) $s['e2025']->id, null))->first();

    expect(Evento::count())->toBe(0)
        ->and($r->promemoria_evento_id)->toBeNull()
        ->and(collect($r->registro)->last()['dati']['promemoria_tolto'])->toBe('2026-06-15');
})->with([
    'non serve' => ['non_serve', ['motivo' => 'assemblea informata']],
    'riapri' => ['riapri', []],
]);

it('dal giorno del promemoria la riga non dice più «Ti ricordo»: dice che te l\'ha ricordato', function () {
    $s = smaViaVerdi($this);
    sprRicordamelo($this, $s, '2026-05-25')->assertSessionHasNoErrors();
    expect(smaRiga(smaRiquadro($s), 'Esercizio 2025')['risposta']['frase'])->toBe('Ti ricordo il 25/05/2026.');

    $this->travelTo('2026-06-02 10:00:00');
    expect(smaRiga(smaRiquadro($s), 'Esercizio 2025')['risposta']['frase'])->toBe('Te l\'ho ricordato il 25/05/2026.');
});
