<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * L'elenco dei compiti dell'Action inbox: le schede «Tutti / Scaduti / In arrivo» con il loro numero, la ricerca («Cerca un compito, un condominio, una
 * persona»), l'ordine, l'anteprima dei gruppi con «Tutti» (la regola delle 8) e le schede 15 alla volta («Mostra altri 15»). I filtri sono nell'indirizzo e
 * si sommano; i numeri arrivano dal server con gli stessi filtri, mai dalla pagina caricata. Oggi è il 20/05/2026, alle 10:00 di Roma.
 */

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Evento;
use Carbon\CarbonImmutable;

require_once __DIR__.'/InboxCompitiScenario.php';

beforeEach(function () {
    $this->withoutVite();
    icsInizia($this);
});

/** Un compito del tipo dato a `$d` giorni da oggi, con il titolo dato, nel condominio dato (di serie quello dello scenario). */
function aieCompito($test, string $tipo, int $d, string $titolo, ?Condominio $condominio = null, array $meta = []): Evento
{
    $e = icsCompito($test, $tipo, $meta, $titolo);
    $giorno = CarbonImmutable::parse('2026-05-20')->addDays($d)->format('Y-m-d') . ' 09:00:00';
    Evento::whereKey($e->id)->update(['start_time' => $giorno, 'end_time' => $giorno]);
    if ($condominio !== null) {
        $e->condomini()->sync([$condominio->id]);
    }

    return $e->fresh();
}

function aiePagina($test, array $filtri = []): array
{
    return $test->actingAs($test->admin)->get(route('admin.inbox', $filtri))->assertOk()->viewData('page')['props'];
}

function aieTitoli($test, array $filtri = []): array
{
    return collect(aiePagina($test, $filtri)['tasks']['data'])->pluck('title')->all();
}

it('le schede «Tutti / Scaduti / In arrivo» con il loro numero; la scheda segue il filtro per data, e il numero della scheda non cambia con la barra scelta', function () {
    foreach ([-100, -40, -2] as $d) {
        aieCompito($this, 'emissione_rata', $d, "Scaduto {$d}");
    }
    foreach ([0, 9, 25, 40] as $d) {
        aieCompito($this, 'controllo_incassi', $d, "In arrivo {$d}");
    }
    icsBonifico($this);
    // La segnalazione e il bonifico sono in «Tutti», non fra gli scaduti né fra quelli in arrivo.
    aieCompito($this, 'segnalazione_guasto', -200, 'Ascensore fermo');

    $tutti = aiePagina($this);
    expect($tutti['schede'])->toBe(['tutti' => 9, 'scaduti' => 3, 'in_arrivo' => 4])
        ->and($tutti['filtri']['scheda'])->toBe('tutti');

    // «In arrivo» ha anche i compiti fra più di 30 giorni, che non sono nel grafico.
    expect(aieTitoli($this, ['quando' => 'in_arrivo']))->toBe(['In arrivo 0', 'In arrivo 9', 'In arrivo 25', 'In arrivo 40']);
    expect(aiePagina($this, ['quando' => 'in_arrivo'])['filtri']['scheda'])->toBe('in_arrivo')
        ->and(aiePagina($this, ['quando' => 'da_31_a_90'])['filtri']['scheda'])->toBe('scaduti')
        ->and(aiePagina($this, ['quando' => 'prossimi_7'])['filtri']['scheda'])->toBe('in_arrivo');
    // La barra «da 31 a 90 giorni» restringe l'elenco e il gruppo; il numero delle schede resta quello di tutta la scheda.
    $barra = aiePagina($this, ['quando' => 'da_31_a_90']);
    expect($barra['schede'])->toBe(['tutti' => 9, 'scaduti' => 3, 'in_arrivo' => 4])
        ->and($barra['gruppi'])->toBe(['scaduti' => 1, 'segnalazioni' => 0, 'bonifici' => 0, 'avanti' => 0])
        ->and(collect($barra['tasks']['data'])->pluck('title')->all())->toBe(['Scaduto -40']);
    // Il tipo cambia i numeri delle schede.
    expect(aiePagina($this, ['tipo' => 'emissione_rata'])['schede'])->toBe(['tutti' => 3, 'scaduti' => 3, 'in_arrivo' => 0]);
});

it('«Tutti» con più di un gruppo mostra l\'anteprima di ogni gruppo con la regola delle 8; con un gruppo solo, o in una scheda, l\'elenco 15 alla volta', function () {
    foreach (range(1, 12) as $n) {
        aieCompito($this, 'emissione_rata', -$n, sprintf('Scaduto %02d', $n));
    }
    foreach ([3, 5, 7] as $d) {
        aieCompito($this, 'controllo_incassi', $d, "In arrivo {$d}");
    }
    icsBonifico($this);
    aieCompito($this, 'segnalazione_guasto', -30, 'Ascensore fermo');

    $p = aiePagina($this);
    expect($p['modo'])->toBe('anteprima')
        ->and(collect($p['anteprima'])->map(fn ($g) => [$g['gruppo'], $g['numero'], count($g['righe'])])->all())->toBe([
            ['scaduti', 12, 5], ['segnalazioni', 1, 1], ['bonifici', 1, 1], ['avanti', 3, 3],
        ])
        // Le prime 5 dei 12 scaduti sono le più vecchie.
        ->and(collect($p['anteprima'][0]['righe'])->pluck('title')->all())->toBe(['Scaduto 12', 'Scaduto 11', 'Scaduto 10', 'Scaduto 09', 'Scaduto 08'])
        ->and($p['anteprima'][0]['righe'][0])->toHaveKeys(['titolo', 'sotto', 'azione', 'giorni', 'risposta']);

    // Con il bonifico solo, o nella scheda «Scaduti», l'elenco.
    expect(aiePagina($this, ['tipo' => 'verifica_pagamento'])['modo'])->toBe('elenco')
        ->and(aiePagina($this, ['tipo' => 'verifica_pagamento'])['anteprima'])->toBe([])
        ->and(aiePagina($this, ['quando' => 'scaduti'])['modo'])->toBe('elenco');
    // Fino a 8 righe un gruppo si mostra tutto.
    Evento::where('title', 'like', 'Scaduto 1%')->get()->each(fn ($e) => icsRispondi($this, $e, ['tipo' => 'fatto']));
    $otto = aiePagina($this);
    expect(collect($otto['anteprima'])->firstWhere('gruppo', 'scaduti'))->toMatchArray(['numero' => 9])
        ->and(count(collect($otto['anteprima'])->firstWhere('gruppo', 'scaduti')['righe']))->toBe(5);
    Evento::where('title', 'Scaduto 09')->get()->each(fn ($e) => icsRispondi($this, $e, ['tipo' => 'fatto']));
    expect(count(collect(aiePagina($this)['anteprima'])->firstWhere('gruppo', 'scaduti')['righe']))->toBe(8);
});

it('le schede 15 alla volta: «Mostra altri 15» chiede solo l\'elenco, la pagina dopo, e il numero di tutto l\'elenco', function () {
    foreach (range(1, 20) as $n) {
        aieCompito($this, 'emissione_rata', -$n, sprintf('Scaduto %02d', $n));
    }

    $prima = aiePagina($this, ['quando' => 'scaduti']);
    expect($prima['tasks']['data'])->toHaveCount(15)
        ->and($prima['tasks']['total'])->toBe(20)
        ->and($prima['tasks']['data'][0]['title'])->toBe('Scaduto 20');

    $versione = app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request());
    $dopo = $this->actingAs($this->admin)->get(route('admin.inbox', ['quando' => 'scaduti', 'page' => 2]), [
        'X-Inertia' => 'true', 'X-Inertia-Version' => (string) $versione, 'X-Inertia-Partial-Component' => 'dashboard/ActionInbox', 'X-Inertia-Partial-Data' => 'tasks',
    ])->assertOk();
    expect(collect($dopo->json('props.tasks.data'))->pluck('title')->all())->toBe(['Scaduto 05', 'Scaduto 04', 'Scaduto 03', 'Scaduto 02', 'Scaduto 01'])
        ->and(array_keys($dopo->json('props')))->not->toContain('schede')->not->toContain('counts')->not->toContain('anteprima');
});

it('la ricerca: il titolo, la descrizione, il condominio, la persona; senza maiuscole; «%» si cerca com\'è; le schede la seguono, le card no', function () {
    $altro = Condominio::factory()->create(['nome' => 'Corso Italia 3']);
    aieCompito($this, 'emissione_rata', -5, 'Emettere rata 4');
    aieCompito($this, 'pagamento_fornitore', -3, 'Pagare Idraulica Rossi');
    $descritto = aieCompito($this, 'scadenza', 2, 'Scade la locazione');
    Evento::whereKey($descritto->id)->update(['description' => 'Il contratto di Bianchi scade il 30 giugno.']);
    aieCompito($this, 'controllo_incassi', 4, 'Verifica incassi', $altro);
    aieCompito($this, 'scadenza', 6, 'Sconto del 10% sul canone');
    [$bonifico] = icsBonifico($this);
    $giulia = Anagrafica::create(['nome' => 'Giulia Verdi', 'indirizzo' => 'Via Po 1']);
    $conPersona = aieCompito($this, 'scadenza', 8, 'Rinnovo');
    $conPersona->anagrafiche()->attach($giulia->id);

    expect(aieTitoli($this, ['cerca' => 'idraulica']))->toBe(['Pagare Idraulica Rossi'])
        ->and(aieTitoli($this, ['cerca' => 'BIANCHI']))->toBe(['Scade la locazione'])
        ->and(aieTitoli($this, ['cerca' => 'corso italia']))->toBe(['Verifica incassi'])
        // La persona del bonifico, dal contesto del compito; e quella collegata al compito.
        ->and(aieTitoli($this, ['cerca' => 'mario rossi']))->toBe([$bonifico->title])
        ->and(aieTitoli($this, ['cerca' => 'giulia']))->toBe(['Rinnovo'])
        ->and(aieTitoli($this, ['cerca' => '10%']))->toBe(['Sconto del 10% sul canone'])
        ->and(aieTitoli($this, ['cerca' => '%']))->toBe(['Sconto del 10% sul canone'])
        ->and(aieTitoli($this, ['cerca' => 'nessuno così']))->toBe([])
        ->and(aieTitoli($this, ['cerca' => '   ']))->toHaveCount(7);

    $p = aiePagina($this, ['cerca' => 'rossi']);
    expect($p['filtri']['cerca'])->toBe('rossi')
        ->and($p['schede'])->toBe(['tutti' => 2, 'scaduti' => 1, 'in_arrivo' => 0])
        ->and($p['gruppi'])->toBe(['scaduti' => 1, 'segnalazioni' => 0, 'bonifici' => 1, 'avanti' => 0])
        ->and($p['counts']['all'])->toBe(7);
    // La ricerca si somma agli altri filtri.
    expect(aieTitoli($this, ['cerca' => 'rossi', 'quando' => 'scaduti']))->toBe(['Pagare Idraulica Rossi']);
});

it('l\'ordine: i più vecchi prima (di serie), i più recenti prima, per condominio; i gruppi restano nel loro ordine (scaduti, bonifici, più avanti)', function () {
    $alfa = Condominio::factory()->create(['nome' => 'Via Alfieri 2']);
    $zeta = Condominio::factory()->create(['nome' => 'Via Zanella 9']);
    aieCompito($this, 'emissione_rata', -30, 'Scaduto Zanella', $zeta);
    aieCompito($this, 'emissione_rata', -10, 'Scaduto Alfieri', $alfa);
    aieCompito($this, 'emissione_rata', -20, 'Scaduto Aurora');
    aieCompito($this, 'controllo_incassi', 3, 'Avanti Zanella', $zeta);
    aieCompito($this, 'controllo_incassi', 9, 'Avanti Alfieri', $alfa);
    icsBonifico($this);

    $bonifico = 'Verifica incasso della rata 3 di Mario';
    expect(aieTitoli($this))->toBe(['Scaduto Zanella', 'Scaduto Aurora', 'Scaduto Alfieri', $bonifico, 'Avanti Zanella', 'Avanti Alfieri'])
        ->and(aieTitoli($this, ['ordine' => 'recenti']))->toBe(['Scaduto Alfieri', 'Scaduto Aurora', 'Scaduto Zanella', $bonifico, 'Avanti Alfieri', 'Avanti Zanella'])
        ->and(aieTitoli($this, ['ordine' => 'condominio']))->toBe(['Scaduto Aurora', 'Scaduto Alfieri', 'Scaduto Zanella', $bonifico, 'Avanti Alfieri', 'Avanti Zanella'])
        ->and(aieTitoli($this, ['ordine' => 'a caso']))->toBe(aieTitoli($this))
        ->and(aiePagina($this, ['ordine' => 'recenti'])['filtri']['ordine'])->toBe('recenti');
    // Nell'anteprima, lo stesso ordine.
    expect(collect(aiePagina($this, ['ordine' => 'recenti'])['anteprima'])->firstWhere('gruppo', 'scaduti')['righe'][0]['title'])->toBe('Scaduto Alfieri');
});
