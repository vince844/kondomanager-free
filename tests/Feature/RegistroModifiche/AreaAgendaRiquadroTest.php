<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\RegistroModifica;
use App\Services\RegistroModifiche\ContestoDelRegistro;

require_once __DIR__ . '/../Gestionale/SeiMesiPerAgireScenario.php';

/**
 * 1.12.0-beta.13 (Coda 679, decisione 547): i compiti del riquadro «Sei mesi per agire» e del rendiconto, che non sono eventi
 * dell'agenda ma la risposta di un argomento del condominio. Chiusi («Fatto», «Non serve», «Registra che hai agito»), rimandati, messi in agenda e
 * riaperti da una persona sono righe del registro, simmetriche a quelle dei compiti dell'Inbox, con il nome del compito.
 *
 * Che cosa non copre: le parole a video del registro (le scrive la pagina del registro); i compiti dell'Inbox, che sono in `AreaAgendaTest`.
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    $this->user = smaUtente();
    $this->withoutVite();
});

function aarDopo(int $prima)
{
    return RegistroModifica::query()->where('id', '>', $prima)->where('area', 'agenda')->orderBy('id')->get();
}

it('il compito del riquadro chiuso con «Non serve» e riaperto: «Compito chiuso: non serve», poi «Compito riaperto», con il nome del compito', function () {
    $s = smaViaVerdi($this);
    $prima = RegistroModifica::query()->max('id') ?? 0;
    $base = ['argomento' => 'sei_mesi', 'esercizio_id' => $s['e2025']->id];

    smaRispondi($this, $s, $base + ['tipo' => 'non_serve', 'motivo' => 'pratica già dal legale'])->assertSessionHasNoErrors();
    smaRispondi($this, $s, $base + ['tipo' => 'riapri'])->assertSessionHasNoErrors();

    $righe = aarDopo($prima);
    expect($righe->pluck('riepilogo')->all())->toBe(['Compito chiuso: non serve', 'Compito riaperto'])
        ->and($righe->pluck('oggetto_nome')->unique()->all())->toBe(['Compito «Sei mesi per agire, esercizio 2025»'])
        ->and($righe->pluck('oggetto_tipo')->unique()->all())->toBe(['risposte_promemoria'])
        ->and($righe[0]->perche)->toBe('pratica già dal legale')
        ->and($righe[1]->oggetto_id)->toBe($righe[0]->oggetto_id)
        ->and($righe[1]->autore_nome)->toBe('Vincenzo')
        ->and($righe[1]->condomini())->toBe([$s['c']->id => 'Via Verdi 12']);
});

it('«Fatto» con le persone spuntate e la riapertura: le persone fra i soggetti, e quante tornano nel riquadro', function () {
    $s = smaViaVerdi($this);
    $prima = RegistroModifica::query()->max('id') ?? 0;
    $base = ['argomento' => 'sei_mesi', 'esercizio_id' => $s['e2025']->id];

    smaRispondi($this, $s, $base + ['tipo' => 'fatto', 'persone' => [$s['persone']['rossi']->id, $s['persone']['bianchi']->id], 'giorno' => '2026-05-19',
        'nota' => 'diffide mandate a mano'])->assertSessionHasNoErrors();
    smaRispondi($this, $s, $base + ['tipo' => 'riapri'])->assertSessionHasNoErrors();

    $righe = aarDopo($prima);
    $persone = [['anagrafica', $s['persone']['bianchi']->id], ['anagrafica', $s['persone']['rossi']->id]];
    usort($persone, fn ($a, $b) => $a[1] <=> $b[1]);
    expect($righe->pluck('riepilogo')->all())->toBe(['Compito chiuso: fatto per 2 persone', 'Compito riaperto: 2 persone tornano nel riquadro'])
        ->and($righe[0]->perche)->toBe('diffide mandate a mano')
        ->and($righe[0]->soggetti())->toEqualCanonicalizing($persone)
        ->and($righe[1]->soggetti())->toEqualCanonicalizing($persone);
});

it('«Registra che hai agito» e la sua riapertura sono righe del compito della persona; «Ricordamelo» e «Mettilo in agenda» del rendiconto pure', function () {
    $s = smaViaVerdi($this);
    $prima = RegistroModifica::query()->max('id') ?? 0;
    $rossi = $s['persone']['rossi'];

    smaRispondi($this, $s, ['tipo' => 'agito', 'argomento' => 'sei_mesi', 'esercizio_id' => $s['e2025']->id, 'anagrafica_id' => $rossi->id,
        'giorno' => '2026-05-12', 'nota' => 'pratica data all\'avv. Gallo'])->assertSessionHasNoErrors();
    smaRispondi($this, $s, ['tipo' => 'riapri', 'argomento' => 'sei_mesi', 'esercizio_id' => $s['e2025']->id, 'anagrafica_id' => $rossi->id])
        ->assertSessionHasNoErrors();
    smaRispondi($this, $s, ['tipo' => 'ricordamelo', 'argomento' => 'rendiconto', 'esercizio_id' => $s['e2025']->id, 'giorno' => '2026-06-10'])
        ->assertSessionHasNoErrors();
    smaRispondi($this, $s, ['tipo' => 'in_agenda', 'argomento' => 'rendiconto', 'esercizio_id' => $s['e2025']->id, 'giorno' => '2026-06-20'])
        ->assertSessionHasNoErrors();

    $righe = aarDopo($prima);
    expect($righe->map(fn ($r) => $r->oggetto_nome . ' · ' . $r->riepilogo)->all())->toBe([
        'Compito «Sei mesi per agire, esercizio 2025» per ' . $rossi->nome . ' · Compito chiuso: azione registrata il 12/05/2026',
        'Compito «Sei mesi per agire, esercizio 2025» per ' . $rossi->nome . ' · Compito riaperto',
        'Compito «Rendiconto 2025 da portare in assemblea» · Compito rimandato al 10/06/2026',
        'Compito «Rendiconto 2025 da portare in assemblea» · Messo in agenda per il 20/06/2026',
    ])
        ->and($righe[0]->perche)->toBe('pratica data all\'avv. Gallo')
        ->and($righe[0]->soggetti())->toBe([['anagrafica', $rossi->id]]);
});
