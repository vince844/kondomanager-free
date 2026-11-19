<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Decisione 538: il dettaglio dell'incasso dice chi ha pagato davvero. Il versamento di un erede sul debito ereditato si scrive come nella decisione 529 (la
 * riga sulla rata del defunto con il legame all'erede, la coppia nel Wallet); la pagina lo leggeva come un versamento del defunto «versato da» l'erede: a
 * video «Soggetto pagante: Massimo Bagnara — Versato da Cecilia Angeli». Sul caso di Ettore Fabbri (`InteressiSulDebitoEreditatoScenario.php`): la rata di
 * aprile da € 300,00, il decesso il 17/11/2026, Elena e Marco al 50 %.
 *
 * - Elena paga € 150,00 a nome suo: «Ha pagato» Elena, sotto «erede, sul debito di Ettore Fabbri», lo spaccato € 150,00 / € 0,00; lo stesso dopo lo storno.
 * - Bianchi, erede e condòmino, versa la sua rata e il debito ereditato nello stesso incasso: lo spaccato con le due parti.
 * - Il conto di Ettore «per il debito degli eredi», per quota: ha pagato Ettore, sotto di chi è il debito coperto, e nessun «Versato da» (il legame di ogni
 *   riga a un erede non è chi ha versato).
 * - «Resta a Ettore» con «Versato da» Marco (nessuna coppia): come prima, Ettore con «Versato da» Marco, senza spaccato.
 *
 * Che cosa NON copre: la pagina a video (vitest `IncassoRateShowHaPagato.test.ts` per il disegno); la catena (decisione 530), letta con la stessa regola ma
 * non provata qui; l'erede unico al 100 % con il versamento dal conto del defunto dopo il decesso, che a giornale è uguale al versamento dell'erede e si legge
 * come suo (un limite scritto nel rapporto); l'elenco degli incassi, che continua a dire la posizione; la ricevuta (ancora intestata al defunto); MySQL.
 */

use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\ScritturaContabile;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/InteressiSulDebitoEreditatoScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

/** L'erede pagante, sul debito ereditato (`[subentro => centesimi]`) e sulle sue rate (`[id quota => centesimi]`). */
function dceVersaErede($test, array $s, string $chi, array $ereditato, string $data, array $righe = [])
{
    $test->travelTo($data . ' 11:00:00');
    $totale = array_sum($ereditato) + array_sum($righe);

    return $test->actingAs($test->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['persone'][$chi]->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => $data, 'importo_totale' => number_format($totale / 100, 2, '.', ''),
        'descrizione' => 'Bonifico', 'eccedenza' => '0.00', 'gestione_id' => $s['g']->id,
        'dettaglio_pagamenti' => array_map(fn ($id, $c) => ['rata_id' => (int) $id, 'importo' => number_format($c / 100, 2, '.', '')], array_keys($righe), array_values($righe)),
        'debito_ereditato' => array_map(fn ($id, $c) => ['subentro_id' => (int) $id, 'importo' => number_format($c / 100, 2, '.', '')], array_keys($ereditato), array_values($ereditato)),
    ]);
}

/** Un versamento sulla rata di aprile di Ettore, pagante Ettore, con «Versato da» e la scelta «per il debito degli eredi». */
function dceVersaSuEttore($test, array $s, int $cent, ?bool $agliEredi, ?string $versatoDa = null)
{
    $test->travelTo('2026-11-25 11:00:00');
    $quota = RataQuote::where('rata_id', lcdRata($s, 1))->where('anagrafica_id', $s['persone']['Neri']->id)->where('immobile_id', $s['unita']['U1']->id)->firstOrFail();

    return $test->actingAs($test->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), array_filter([
        'pagante_id' => $s['persone']['Neri']->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2026-11-25', 'importo_totale' => number_format($cent / 100, 2, '.', ''),
        'descrizione' => 'Bonifico', 'eccedenza' => '0.00', 'gestione_id' => $s['g']->id,
        'dettaglio_pagamenti' => [['rata_id' => $quota->id, 'importo' => number_format($cent / 100, 2, '.', '')]],
        'versato_da_id' => $versatoDa === null ? null : $s['persone'][$versatoDa]->id, 'agli_eredi' => $agliEredi === null ? null : ($agliEredi ? '1' : '0'),
    ], fn ($v) => $v !== null));
}

/** Le proprietà della pagina del dettaglio dell'ultimo incasso. */
function dceDettaglio($test, array $s): array
{
    $sc = ScritturaContabile::where('tipo_movimento', 'incasso_rata')->latest('id')->firstOrFail();

    return $test->actingAs($test->user)->get(route('admin.gestionale.movimenti-rate.show', [$s['c'], $sc->id]))->assertOk()->viewData('page')['props'];
}

it('decisione 538: Elena paga € 150,00 sul debito ereditato; «Ha pagato» è Elena, «erede, sul debito di Ettore Fabbri», e il totale si divide € 150,00 / € 0,00; lo stesso dopo lo storno', function () {
    $s = ideCaso($this);
    $p = ideRegistra($this, $s, ideSuccessione($s));
    dceVersaErede($this, $s, 'Elena', [$p->id => 15000], '2026-11-25')->assertSessionHasNoErrors();

    $props = dceDettaglio($this, $s);
    // Con il codice di prima la pagina non aveva `ha_pagato`: leggeva la posizione, Ettore Fabbri, con «Versato da» Elena.
    expect($props['ha_pagato'] ?? null)->toBe(['nome' => 'Elena', 'sotto' => 'erede, sul debito di Ettore Fabbri', 'versato_da' => null])
        ->and($props['spaccato'] ?? null)->toBe(['sul_debito_ereditato' => 15000, 'sulle_sue_rate' => 0])
        // Controllo: la scrittura non cambia, la posizione resta quella del giornale.
        ->and($props['incassoFormatted']['pagante']['lista_completa'])->toBe('Ettore Fabbri');

    $this->travelTo('2026-11-28 10:00:00');
    app(\App\Actions\Gestionale\Movimenti\StornoIncassoRateAction::class)->execute(ScritturaContabile::where('tipo_movimento', 'incasso_rata')->latest('id')->firstOrFail(), $s['c'], \App\Enums\NaturaStorno::VERSAMENTO_TORNATO);
    $dopo = dceDettaglio($this, $s);
    expect($dopo['incasso']['stato'])->toBe('annullata')
        ->and($dopo['ha_pagato']['nome'] ?? null)->toBe('Elena')
        ->and($dopo['spaccato'] ?? null)->toBe(['sul_debito_ereditato' => 15000, 'sulle_sue_rate' => 0]);
});

it('decisione 538: Bianchi, erede e condòmino, versa la sua rata e € 150,00 sul debito ereditato nello stesso incasso; lo spaccato dice le due parti', function () {
    $s = ideCaso($this);
    $dati = ideSuccessione($s);
    $dati['eredi'] = [['anagrafica_id' => $s['persone']['Bianchi']->id, 'quota' => 50], ['anagrafica_id' => $s['persone']['Marco']->id, 'quota' => 50]];
    $dati['erede_di_riferimento'] = $s['persone']['Marco']->id;
    $p = ideRegistra($this, $s, $dati);
    $quota = RataQuote::where('rata_id', lcdRata($s, 1))->where('anagrafica_id', $s['persone']['Bianchi']->id)->firstOrFail();
    $aperto = (int) $quota->importo - (int) $quota->importo_pagato;
    dceVersaErede($this, $s, 'Bianchi', [$p->id => 15000], '2026-11-20', [$quota->id => $aperto])->assertSessionHasNoErrors();

    $props = dceDettaglio($this, $s);
    expect($aperto)->toBeGreaterThan(0)
        ->and($props['ha_pagato']['nome'] ?? null)->toBe('Bianchi')
        ->and($props['ha_pagato']['sotto'] ?? null)->toBe('erede, sul debito di Ettore Fabbri')
        ->and($props['spaccato'] ?? null)->toBe(['sul_debito_ereditato' => 15000, 'sulle_sue_rate' => $aperto]);
});

it('decisione 538: il conto di Ettore «per il debito degli eredi», per quota: ha pagato Ettore, sotto di chi è il debito, e nessun «Versato da» Elena', function () {
    $s = ideCaso($this);
    ideRegistra($this, $s, ideSuccessione($s));
    dceVersaSuEttore($this, $s, 10000, true)->assertSessionHasNoErrors();

    $props = dceDettaglio($this, $s);
    // Con il codice di prima «Versato da» era il legame della prima riga, Elena, che non ha versato niente.
    expect($props['incassoFormatted']['pagante']['versato_da'])->toBe('Elena')
        ->and($props['ha_pagato'] ?? null)->toBe(['nome' => 'Ettore Fabbri', 'sotto' => 'per il debito ereditato di Elena e Marco', 'versato_da' => null])
        ->and(array_key_exists('spaccato', $props))->toBeTrue()->and($props['spaccato'])->toBeNull();
});

it('decisione 538, controllo: «Resta a Ettore» con «Versato da» Marco non ha coppie e si legge come prima, Ettore con «Versato da» Marco, senza spaccato', function () {
    $s = ideCaso($this);
    ideRegistra($this, $s, ideSuccessione($s));
    dceVersaSuEttore($this, $s, 5000, false, 'Marco')->assertSessionHasNoErrors();

    $props = dceDettaglio($this, $s);
    expect($props['ha_pagato'] ?? null)->toBe(['nome' => 'Ettore Fabbri', 'sotto' => $props['incassoFormatted']['pagante']['ruolo'], 'versato_da' => 'Marco'])
        ->and(array_key_exists('spaccato', $props))->toBeTrue()->and($props['spaccato'])->toBeNull();
});
