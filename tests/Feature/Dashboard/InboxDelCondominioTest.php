<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * L'Inbox operativa della dashboard del condominio legge i compiti con le regole dell'Action inbox, una sola per le due: la regola di «scaduto» e i gruppi
 * (`FasceDeiCompiti`: un compito di oggi non è scaduto, le segnalazioni e i bonifici segnalati non scadono), l'ordine (gli scaduti, le segnalazioni aperte, i
 * bonifici da verificare, gli altri), la prossima azione del tipo (`TipiDeiCompiti`), la priorità della segnalazione, il titolo senza il condominio ripetuto.
 * Le schede «Tutte», «Scadute», «In arrivo» (`?inbox=`) filtrano l'elenco con la regola di `FasceDeiCompiti`; i loro numeri valgono per tutte le schede. La riga
 * dice quando la riga sotto il titolo è la prima riga della descrizione, perché la pagina non la scriva due volte. Oggi è il 20/05/2026, alle 10:00 di Roma.
 */

use App\Models\Evento;

require_once __DIR__.'/InboxCompitiScenario.php';

beforeEach(function () {
    $this->withoutVite();
    icsInizia($this);
});

/** Le righe dell'Inbox operativa del condominio, nell'ordine della pagina. */
function idcRighe($test): array
{
    return $test->actingAs($test->admin)->get(route('admin.gestionale.index', $test->condominio))->assertOk()->viewData('page')['props']['inboxTasks']['data'];
}

it('un compito di ieri è scaduto; uno di oggi no; una segnalazione aperta da 267 giorni e un bonifico segnalato non sono scaduti', function () {
    icsCompito($this, 'emissione_rata', [], 'Emettere rata 1');
    $oggi = icsCompito($this, 'controllo_incassi', [], 'Verifica incassi di oggi');
    Evento::whereKey($oggi->id)->update(['start_time' => now(), 'end_time' => now()->addHour()]);
    $segnalazione = icsCompito($this, 'segnalazione_guasto', ['priority' => 'urgente', 'is_approved' => true], 'Ascensore fermo');
    Evento::whereKey($segnalazione->id)->update(['start_time' => now()->subDays(267), 'end_time' => now()->subDays(267)->addHour()]);
    [$bonifico] = icsBonifico($this);
    $righe = collect(idcRighe($this))->keyBy('title');

    expect($righe['Emettere rata 1'])->toMatchArray(['status' => 'expired', 'gruppo' => 'scaduti', 'giorni' => -1])
        ->and($righe['Verifica incassi di oggi'])->toMatchArray(['status' => 'scheduled', 'gruppo' => 'avanti', 'giorni' => 0])
        ->and($righe['Ascensore fermo'])->toMatchArray(['status' => 'scheduled', 'gruppo' => 'segnalazioni', 'giorni' => -267, 'priorita' => 'urgente'])
        ->and($righe[$bonifico->title])->toMatchArray(['status' => 'scheduled', 'gruppo' => 'bonifici']);
});

it('l\'ordine è quello dell\'Action inbox: gli scaduti, le segnalazioni aperte, i bonifici da verificare, gli altri', function () {
    $avanti = icsCompito($this, 'scadenza', [], 'Più avanti');
    Evento::whereKey($avanti->id)->update(['start_time' => now()->addDays(3), 'end_time' => now()->addDays(3)->addHour()]);
    icsBonifico($this);
    icsCompito($this, 'segnalazione_guasto', [], 'Infiltrazione');
    icsCompito($this, 'emissione_rata', [], 'Emettere rata 2');

    expect(array_column(idcRighe($this), 'gruppo'))->toBe(['scaduti', 'segnalazioni', 'bonifici', 'avanti']);
});

it('ogni riga ha la prossima azione della mappa unica, al posto di «Risolvi», e il titolo senza il condominio ripetuto', function () {
    icsCompito($this, 'emissione_rata', [], 'Emettere rata 1 - Condominio Aurora');
    icsCompito($this, 'scadenza', [], 'Rinnovo assicurazione');
    $righe = collect(idcRighe($this))->keyBy('title');

    expect($righe['Emettere rata 1 - Condominio Aurora']['azione'])->toBe(['testo' => 'Emetti la rata', 'url' => '/da/risolvere'])
        ->and($righe['Emettere rata 1 - Condominio Aurora']['titolo'])->toBe('Emettere rata 1')
        ->and($righe['Rinnovo assicurazione']['azione'])->toBe(['testo' => 'Segna come fatto', 'url' => null]);
});

/** Le props dell'Inbox operativa con la scheda data. */
function idcProps($test, ?string $scheda = null): array
{
    return $test->actingAs($test->admin)->get(route('admin.gestionale.index', array_filter([$test->condominio, 'inbox' => $scheda])))->assertOk()->viewData('page')['props'];
}

/** Uno scaduto, uno di oggi, uno fra tre giorni, una segnalazione aperta, un bonifico segnalato. */
function idcCinque($test): void
{
    icsCompito($test, 'emissione_rata', [], 'Emettere rata 1');
    foreach (['Verifica incassi di oggi' => 0, 'Più avanti' => 3] as $titolo => $giorni) {
        $c = icsCompito($test, 'controllo_incassi', [], $titolo);
        Evento::whereKey($c->id)->update(['start_time' => now()->addDays($giorni), 'end_time' => now()->addDays($giorni)->addHour()]);
    }
    icsCompito($test, 'segnalazione_guasto', ['is_approved' => true], 'Ascensore fermo');
    icsBonifico($test);
}

it('le schede: «Scadute» mostra solo gli scaduti, «In arrivo» solo i compiti da oggi in poi; le segnalazioni e i bonifici solo in «Tutte»', function () {
    idcCinque($this);

    $tutte = idcProps($this);
    expect($tutte['inboxScheda'])->toBe('tutti')
        ->and($tutte['inboxTasks']['data'])->toHaveCount(5)
        ->and($tutte['inboxSchede'])->toBe(['tutti' => 5, 'scaduti' => 1, 'in_arrivo' => 2])
        ->and($tutte['inboxScaduti'])->toBe(1);

    $scadute = idcProps($this, 'scaduti');
    expect($scadute['inboxScheda'])->toBe('scaduti')
        ->and(array_column($scadute['inboxTasks']['data'], 'title'))->toBe(['Emettere rata 1'])
        // I numeri delle schede e il cartellino non seguono la scheda scelta.
        ->and($scadute['inboxSchede'])->toBe(['tutti' => 5, 'scaduti' => 1, 'in_arrivo' => 2])
        ->and($scadute['inboxScaduti'])->toBe(1);

    $inArrivo = idcProps($this, 'in_arrivo');
    expect($inArrivo['inboxScheda'])->toBe('in_arrivo')
        ->and(array_column($inArrivo['inboxTasks']['data'], 'title'))->toBe(['Verifica incassi di oggi', 'Più avanti']);
});

it('una scheda che non c\'è vale «Tutte»; le barre del grafico dell\'Action inbox non sono schede della dashboard', function () {
    idcCinque($this);

    foreach (['qualcosa', 'oltre_90', 'prossimi_7'] as $scheda) {
        $props = idcProps($this, $scheda);
        expect($props['inboxScheda'], $scheda)->toBe('tutti')
            ->and($props['inboxTasks']['data'], $scheda)->toHaveCount(5);
    }
});

it('la riga dice quando la riga sotto il titolo è la prima riga della descrizione: la pagina non la scrive due volte', function () {
    $senzaDati = icsCompito($this, 'scadenza', [], 'Decidere quando avviare il rifacimento del lastrico');
    Evento::whereKey($senzaDati->id)->update(['description' => 'Il fondo lavori ha € 1.500,00 accantonati a fronte di una spesa preventivata di € 9.000,00.']);
    icsCompito($this, 'emissione_rata', ['numero_rata' => 2, 'totale_rata' => 45000], 'Emettere rata 2');
    $righe = collect(idcRighe($this))->keyBy('title');

    expect($righe['Decidere quando avviare il rifacimento del lastrico'])->toMatchArray([
        'sotto' => 'Il fondo lavori ha € 1.500,00 accantonati a fronte di una spesa preventivata di € 9.000,00.', 'sotto_dalla_descrizione' => true])
        ->and($righe['Emettere rata 2'])->toMatchArray(['sotto' => 'Rata 2 · € 450,00', 'sotto_dalla_descrizione' => false]);
});
