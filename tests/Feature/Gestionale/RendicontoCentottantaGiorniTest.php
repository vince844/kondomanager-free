<?php

/**
 * I 180 giorni del rendiconto nel riquadro dei sei mesi: la data entro cui la legge chiede di convocare l'assemblea che approva il rendiconto (art. 1130
 * n. 10 c.c.), contata dal giorno dopo la fine dell'esercizio (`addDays(180)`: il 31/12/2025 dà il 29/06/2026). Ci sono tutti gli esercizi finiti con il
 * rendiconto non approvato, tranne i chiusi, e l'ultimo finito anche approvato, fino alla data. Lo stato ha le frasi di `statiInTesta` così come sono. Il
 * compito c'è solo per l'ultimo esercizio finito, da 60 giorni prima, finché il rendiconto è una bozza.
 */

use App\Models\Esercizio;
use App\Models\Gestionale\RendicontoVersione;
use App\Services\Gestionale\Debiti\SeiMesiPerAgire;

require_once __DIR__.'/SeiMesiPerAgireScenario.php';

beforeEach(function () {
    $this->user = smaUtente();
    $this->withoutVite();
});

function rcgVersione(array $s, Esercizio $e, string $stato, string $calcolato, ?string $delibera = null, ?string $verbale = null): RendicontoVersione
{
    return RendicontoVersione::create(['condominio_id' => $s['c']->id, 'esercizio_id' => $e->id, 'numero' => RendicontoVersione::where('esercizio_id', $e->id)->count() + 1,
        'stato' => $stato, 'forma' => 1, 'cifre' => [], 'calcolato_il' => $calcolato, 'data_delibera' => $delibera, 'numero_verbale' => $verbale]);
}

function rcgRendiconti(array $s): array
{
    return collect(smaRiquadro($s)['rendiconti'])->keyBy('esercizio.nome')->all();
}

it('la data: la fine dell\'esercizio più 180 giorni', function () {
    expect(SeiMesiPerAgire::dataDelRendiconto('2025-12-31'))->toBe('2026-06-29')
        ->and(SeiMesiPerAgire::dataDelRendiconto('2026-06-30'))->toBe('2026-12-27');
});

it('tutti gli esercizi finiti e non approvati, tranne i chiusi, in ordine di data; le frasi di statiInTesta; il compito solo per l\'ultimo', function () {
    $s = smaViaVerdi($this);
    Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2024', 'data_inizio' => '2024-01-01', 'data_fine' => '2024-12-31', 'stato' => 'aperto']);
    Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2023', 'data_inizio' => '2023-01-01', 'data_fine' => '2023-12-31', 'stato' => 'chiuso']);
    $r = smaRiquadro($s)['rendiconti'];

    expect(collect($r)->map(fn ($x) => [$x['esercizio']['nome'], $x['data'], $x['giorni'], $x['stato'], $x['colore'], $x['compito'], $x['ultimo']])->all())->toBe([
        ['Esercizio 2024', '2025-06-29', -325, 'bozza', 'passata', false, false],
        ['Esercizio 2025', '2026-06-29', 40, 'bozza', 'vicina', true, true],
    ])
        ->and($r[1]['frase'])->toBe('Bozza: le cifre cambiano a ogni registrazione.')
        ->and($r[1]['passo'])->toBe('apri')
        ->and(collect(smaRiquadro($s)['compiti'])->where('argomento', 'rendiconto')->pluck('esercizio.nome')->all())->toBe(['Esercizio 2025']);
});

it('con la versione fermata: grigio qualunque sia la data, «Registra l\'approvazione», il compito si spegne', function () {
    $s = smaViaVerdi($this);
    rcgVersione($s, $s['e2025'], RendicontoVersione::PRESENTATA, '2026-05-12 09:00:00');
    $r = rcgRendiconti($s)['Esercizio 2025'];

    expect($r['stato'])->toBe('presentata')
        ->and($r['frase'])->toBe('Fermato per l\'assemblea il 12/05/2026.')
        ->and($r['colore'])->toBe('fermato')
        ->and($r['passo'])->toBe('approvazione')
        ->and($r['compito'])->toBeFalse();

    $this->travelTo('2026-07-10 10:00:00');
    expect(rcgRendiconti($s)['Esercizio 2025']['colore'])->toBe('fermato');
});

it('l\'ultimo esercizio finito approvato resta, in verde e senza passo, fino alla data; dopo non c\'è più', function () {
    $s = smaViaVerdi($this);
    rcgVersione($s, $s['e2025'], RendicontoVersione::APPROVATA, '2026-04-10 09:00:00', '2026-04-20', '3');
    $r = rcgRendiconti($s)['Esercizio 2025'];

    expect($r['stato'])->toBe('approvata')
        ->and($r['frase'])->toBe('Approvato il 20/04/2026 (verbale n. 3).')
        ->and($r['colore'])->toBe('approvato')
        ->and($r['passo'])->toBeNull()
        ->and($r['compito'])->toBeFalse();

    $this->travelTo('2026-06-30 10:00:00');
    expect(rcgRendiconti($s))->not->toHaveKey('Esercizio 2025');
});

it('il compito del rendiconto da 60 giorni prima: a 61 no, a 60 sì', function () {
    $s = smaViaVerdi($this);
    $this->travelTo('2026-04-29 10:00:00');
    expect(rcgRendiconti($s)['Esercizio 2025']['compito'])->toBeFalse()->and(rcgRendiconti($s)['Esercizio 2025']['colore'])->toBe('lontana');

    $this->travelTo('2026-04-30 10:00:00');
    expect(rcgRendiconti($s)['Esercizio 2025']['compito'])->toBeTrue();
});

it('«Non serve» chiude il compito del rendiconto; la riga resta con la risposta', function () {
    $s = smaViaVerdi($this);
    smaRispondi($this, $s, ['tipo' => 'non_serve', 'argomento' => 'rendiconto', 'esercizio_id' => $s['e2025']->id, 'motivo' => 'assemblea già convocata'])->assertSessionHasNoErrors();
    $r = rcgRendiconti($s)['Esercizio 2025'];

    expect($r['compito'])->toBeFalse()->and($r['risposta']['stato'])->toBe('non_serve');
});

it('«Fatto» del rendiconto dopo «Ricordamelo» toglie il promemoria; l\'evento di «Mettilo in agenda» resta', function () {
    $s = smaViaVerdi($this);
    smaRispondi($this, $s, ['tipo' => 'ricordamelo', 'argomento' => 'rendiconto', 'esercizio_id' => $s['e2025']->id, 'giorno' => '2026-06-10'])->assertSessionHasNoErrors();
    smaRispondi($this, $s, ['tipo' => 'in_agenda', 'argomento' => 'rendiconto', 'esercizio_id' => $s['e2025']->id])->assertSessionHasNoErrors();
    expect(\App\Models\Evento::count())->toBe(2);

    smaRispondi($this, $s, ['tipo' => 'fatto', 'argomento' => 'rendiconto', 'esercizio_id' => $s['e2025']->id, 'nota' => 'convocata'])->assertSessionHasNoErrors();
    $r = \App\Models\Gestionale\RispostaPromemoria::first();

    expect(\App\Models\Evento::count())->toBe(1)
        ->and(\App\Models\Evento::first()->id)->toBe($r->agenda_evento_id)
        ->and($r->promemoria_evento_id)->toBeNull();
});
