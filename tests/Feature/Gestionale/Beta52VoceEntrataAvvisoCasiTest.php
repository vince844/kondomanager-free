<?php

/**
 * L'avviso della pagina del piano dice che cosa annullare per riscriverlo: un credito usato, il passaggio che ha conguagliato il piano.
 *
 * `Beta52VoceEntrataAvvisoTest` prova l'avviso con le emissioni e con gli incassi. Qui stanno le altre due ragioni per cui «Ricalcola», «Rimuovi voce e ricalcola»
 * ed «Elimina» rifiutano (`PianoRate::ragioniDelFermo()`): una quota con un credito già usato o rimborsato, e un passaggio di titolarità che ha conguagliato il
 * piano. In ogni caso la prima strada dell'avviso dice che cosa annullare e in che ordine, con le parole che il pulsante «Ricalcola» già usa.
 *
 * Fonte dell'atteso: i conti a mano di ogni test (spesa € 1.200,00 e voce −€ 240,00) e il testo che `PianoRate::ragioniDelFermo()` e il pulsante «Ricalcola» già
 * mostrano.
 *
 * Cosa resta scoperto: la schermata (`PianiRateShowAvvisoSpesaNegativa.test.ts`).
 */

use App\Services\Riparto\AvvisoSpeseNegative;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta52VoceEntrataScenari.php';
require_once __DIR__.'/Beta51PianoRateScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = be52Utente();
});

it('con un credito usato su una quota l\'avviso dice di annullare i crediti usati o rimborsati', function () {
    [$s, $piano] = be52PianoVecchio($this);
    $quota = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)->orderBy('rate_quote.id')->value('rate_quote.id');
    DB::table('rate_quote')->where('id', $quota)->update(['importo' => -1000, 'importo_pagato' => -1000]);

    $avviso = app(AvvisoSpeseNegative::class)->perLaPagina($piano->fresh());

    expect($avviso['situazione'])->toBe('Il piano ha un credito usato o rimborsato su una sua quota: «Ricalcola», «Rimuovi voce e ricalcola» ed «Elimina» lo rifiutano, quindi nessuno dei tre arriva a togliere la voce negativa dalle quote.')
        ->and($avviso['strade'][0])->toBe('Per riscrivere il piano annulla i crediti usati o rimborsati, poi correggi come segue.');
});

it('con un passaggio che ha conguagliato il piano l\'avviso dice di annullare il passaggio e di registrarlo di nuovo', function () {
    $b = Beta51Scenario::nuovo($this)->tabella('Proprietà', ['A' => 1000])->gestione('Ordinaria 2026')
        ->voce('Ordinaria 2026', 'Spese generali', 120000, ['Proprietà' => 100])
        ->voce('Ordinaria 2026', 'Rimborso', 24000, ['Proprietà' => 100])
        ->pianoOrdinario('Preventivo 2026', 'Ordinaria 2026', capitoli: true)->titolare('A', 'ugo');
    $b->persona('elsa');
    $b->genera('Preventivo 2026');
    $b->emetti('Preventivo 2026', '2026-06-30')->assertSessionHasNoErrors();
    $b->passaggio('vendita', 'A', 'ugo', 'elsa', '2026-05-01');
    $piano = $b->piano('Preventivo 2026');
    $voce = $b->voci['Rimborso']['conto'];
    DB::table('conti')->where('id', $voce->id)->update(['importo' => -24000]);
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->where('conto_id', $voce->id)->update(['importo' => -24000]);
    expect($piano->fresh()->conguagliato())->toBeTrue();

    $avviso = app(AvvisoSpeseNegative::class)->perLaPagina($piano->fresh());

    expect($avviso['strade'][0])->toContain('annulla il passaggio che ha conguagliato il piano e registralo di nuovo (i passi sono sul pulsante «Ricalcola»)');
});
