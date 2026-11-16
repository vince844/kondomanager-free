<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * L'accordo di rientro nella tesoreria e nel passaggio di proprietà, sul caso di Neri (`AccordoCalendarioScenario.php`).
 *
 * - La tesoreria: le rate coperte da un accordo in corso sono attese alle date del calendario, non tutte a oggi. Il 20/08, dopo il versamento del 31/07,
 *   la rata 1 ha € 150,61 nell'accordo: nella finestra di 30 giorni (fino al 19/09) è attesa la sola rata 2 del calendario, € 75,31 il 31/08; la rata 3
 *   (30/09) è fuori. Con una finestra di 10 giorni (fino al 30/08) nemmeno la rata 2. Nel riquadro delle rate scadute la riga di Neri dice a che punto è
 *   l'accordo con la frase della prossima azione (la regola dei sei mesi): una riga sola, dalla 1.12.0-beta.10 al posto di `accordoTesto`.
 * - Il passaggio di U1 con un accordo in corso: l'anteprima lo dice.
 */

use App\Services\Treasury\TreasuryGuardianService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/AccordoCalendarioScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

/** Il caso con il versamento dell'accordo del 31/07 e le rate 1 degli altri versate: nella tesoreria resta il solo Neri. */
function atpTesoreria($test): array
{
    [$s, $interessi, $sollecito, $a] = aclAccordo($test);
    foreach (['Bianchi', 'Verdi'] as $chi) {
        $q = lcdQuota($s, $chi, 1);
        $resto = (int) DB::table('rate_quote')->where('id', $q)->selectRaw('importo - importo_pagato as r')->value('r');
        if ($resto > 0) {
            lcdIncassa($test, $s, $chi, [$q => $resto], '2026-04-30');
        }
    }
    aclVersa($test, $s, [adbQuota($interessi)->id => 92, adbQuota($sollecito)->id => 2500, lcdQuota($s, 'Neri', 1) => 4939], '2026-07-31');

    return [$s, $a];
}

it('la tesoreria aspetta le rate coperte alle date del calendario: il 20/08 € 75,31 il 31/08, niente a oggi; con dieci giorni niente', function () {
    [$s] = atpTesoreria($this);
    $this->travelTo('2026-08-20 10:00:00');

    $trenta = app(TreasuryGuardianService::class)->perCondominio((int) $s['c']->id, null, 30);
    expect([$trenta->incassiScadutiCents, $trenta->incassiInScadenzaCents])->toBe([0, 7531]);
    $dieci = app(TreasuryGuardianService::class)->perCondominio((int) $s['c']->id, null, 10);
    expect([$dieci->incassiScadutiCents, $dieci->incassiInScadenzaCents])->toBe([0, 0]);

    // Neri è fra le persone con rate scadute (la rata 1 lo è per la legge), con l'accordo detto nella frase della prossima azione.
    $neri = collect($trenta->morositaImpattanti)->firstWhere('anagraficaId', (int) $s['persone']['Neri']->id);
    expect($neri['importoCents'])->toBe(15061)
        ->and($neri)->not->toHaveKey('accordoTesto')
        ->and($neri['passo']['caso'])->toBe('R3')
        ->and($neri['passo']['frase'])->toBe('Accordo di rientro del 15/07/2026, fino al 30/09/2026: le rate scadute sono versate.');

    // Il 05/09 la rata 2 del calendario (31/08) è scaduta e non versata: attesa a oggi, e la riga lo dice.
    $this->travelTo('2026-09-05 10:00:00');
    $dopo = app(TreasuryGuardianService::class)->perCondominio((int) $s['c']->id, null, 30);
    expect([$dopo->incassiScadutiCents, $dopo->incassiInScadenzaCents])->toBe([7531, 7530])
        ->and(collect($dopo->morositaImpattanti)->firstWhere('anagraficaId', (int) $s['persone']['Neri']->id)['passo']['frase'])->toBe('La rata dell\'accordo del 31/08/2026 non è versata.');
});

it('chiuso l\'accordo, le rate coperte tornano attese a oggi', function () {
    [$s, $a] = atpTesoreria($this);
    $this->travelTo('2026-08-20 10:00:00');
    $this->actingAs($this->user)->postJson(route('admin.gestionale.accordi-rientro.chiudi', ['condominio' => $s['c']->id, 'accordo' => $a->id]), ['chiuso_il' => '2026-08-20'])->assertOk();

    $t = app(TreasuryGuardianService::class)->perCondominio((int) $s['c']->id, null, 30);
    expect([$t->incassiScadutiCents, $t->incassiInScadenzaCents])->toBe([15061, 0])
        ->and(collect($t->morositaImpattanti)->firstWhere('anagraficaId', (int) $s['persone']['Neri']->id)['passo']['frase'])->not->toContain('ccordo');
});

it('la vendita di U1 con un accordo in corso: l\'anteprima dice che Neri ha l\'accordo e che le rate coperte restano a suo nome', function () {
    [$s] = aclAccordo($this);
    $gialli = \App\Models\Anagrafica::forceCreate(['nome' => 'Gialli', 'email' => 'gialli-acl-' . $s['c']->id . '@test.it', 'indirizzo' => 'Via Verdi 1',
        'codice_fiscale' => 'GLLACL' . str_pad((string) $s['c']->id, 10, '0', STR_PAD_LEFT)]);
    $gialli->condomini()->syncWithoutDetaching([$s['c']->id]);
    $this->travelTo('2026-09-05 10:00:00');
    $dati = ['tipo' => 'vendita', 'riga_uscente_id' => $s['righe']['Neri'], 'anagrafica_entrante_id' => $gialli->id, 'decorrenza' => '2026-09-01',
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-09-03',
        'estremi_titolo' => 'atto notaio Bruni, rep. 7', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Rogito del 01/09'];

    $anteprima = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']['U1']]), $dati)->assertOk()->json();
    expect($anteprima['rate']['frasi'])->toContain('Neri ha un accordo di rientro aperto su U1, del 15/07/2026: le rate che copre restano a suo nome.');

    // Chiuso l'accordo, la frase non c'è.
    $this->actingAs($this->user)->postJson(route('admin.gestionale.accordi-rientro.chiudi', ['condominio' => $s['c']->id, 'accordo' => \App\Models\Gestionale\AccordoRientro::sole()->id]),
        ['chiuso_il' => '2026-09-05'])->assertOk();
    $anteprima = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']['U1']]), $dati)->assertOk()->json();
    expect(collect($anteprima['rate']['frasi'])->filter(fn ($f) => str_contains($f, 'accordo di rientro'))->all())->toBe([]);
});
