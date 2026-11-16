<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * I suggerimenti dell'elenco «Rate scadute» che chiedono le regole del recupero: «N persone oltre i N giorni di tolleranza senza una lettera» e
 * «Interessi di mora da proporre per N persone». Sono suggerimenti, mai azioni: dicono quante persone e quali, e niente si scrive.
 *
 * Via Verdi (`AccessoriScenario.php`), al 29/06/2026: la rata 1 scaduta il 31/03 è da versare per Neri (€ 300,00), Bianchi (€ 180,00) e Verdi; Verdi
 * la versa il 15/04; a Bianchi è partita per email una lettera il 20/04, dopo la scadenza. La regola del condominio dal 01/01/2026: 15 giorni di
 * tolleranza.
 */

use App\Models\Gestionale\RegistroInvio;
use App\Services\Gestionale\Accessori\SuggerimentiDelRecupero;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/AccessoriScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

function sgrProps($test, array $s): array
{
    return $test->actingAs($test->user)->get(route('admin.gestionale.rate-scadute.index', ['condominio' => $s['c']->id]))->assertOk()->viewData('page')['props'];
}

it('senza la tolleranza nelle regole c\'è solo il suggerimento degli interessi; con 15 giorni, chi è oltre senza una lettera', function () {
    $s = accViaVerdi($this);
    $this->travelTo('2026-04-15 10:00:00');
    lcdIncassa($this, $s, 'Verdi', [lcdQuota($s, 'Verdi', 1) => (int) DB::table('rate_quote')->where('id', lcdQuota($s, 'Verdi', 1))->value('importo')], '2026-04-15');
    RegistroInvio::create([
        'condominio_id' => $s['c']->id, 'anagrafica_id' => $s['persone']['Bianchi']->id, 'tipo' => 'sollecito', 'canale' => 'email',
        'documentabile_type' => \App\Models\Anagrafica::class, 'documentabile_id' => $s['persone']['Bianchi']->id,
        'dati' => ['lettera' => 'lettera-bianchi', 'persona' => ['id' => $s['persone']['Bianchi']->id, 'nome' => 'Bianchi'], 'righe' => [], 'totale' => 18000],
        'stato' => RegistroInvio::STATO_INVIATA, 'emesso_il' => '2026-04-20 08:00:00',
    ]);

    $this->travelTo('2026-06-29 10:00:00');
    $prima = DB::table('scritture_contabili')->count();
    $props = sgrProps($this, $s);
    // Neri: 300,00 per 90 giorni, € 1,18; Bianchi: 180,00 per 90 giorni, € 0,71: tutti e due hanno interessi da proporre.
    expect($props['suggerimenti'])->toBe([[
        'chiave' => 'interessi_da_proporre', 'frase' => 'Interessi di mora da proporre per 2 persone.',
        'persone' => [$s['persone']['Neri']->id, $s['persone']['Bianchi']->id], 'porta' => 'proponi_interessi',
    ]])->and($props['regole_del_recupero'])->toBe('1,60 % annuo, tasso legale');

    $this->actingAs($this->user)->post(route('admin.gestionale.recupero-crediti.store', [$s['c']]), ['dal' => '2026-01-01', 'tasso_tipo' => 'legale', 'giorni_tolleranza' => 15])
        ->assertSessionHasNoErrors();
    $props = sgrProps($this, $s);
    expect($props['suggerimenti'][0])->toBe(['chiave' => 'oltre_tolleranza', 'frase' => '1 persona oltre i 15 giorni di tolleranza senza una lettera.',
        'persone' => [$s['persone']['Neri']->id], 'porta' => 'seleziona'])
        ->and($props['suggerimenti'][1]['chiave'])->toBe('interessi_da_proporre')
        ->and(DB::table('scritture_contabili')->count())->toBe($prima);

    // Gli interessi registrati al 29/06: per oggi non c'è più niente da proporre a Neri.
    accRegistra($this, $s, accProposta($this, $s, ['Neri'])->json())->assertCreated();
    expect(collect(sgrProps($this, $s)['suggerimenti'])->firstWhere('chiave', 'interessi_da_proporre')['persone'])->toBe([$s['persone']['Bianchi']->id]);
});

it('le frasi della tolleranza', function () {
    expect(SuggerimentiDelRecupero::fraseOltreTolleranza(3, 15))->toBe('3 persone oltre i 15 giorni di tolleranza senza una lettera.')
        ->and(SuggerimentiDelRecupero::fraseOltreTolleranza(1, 1))->toBe('1 persona oltre il giorno di tolleranza senza una lettera.')
        ->and(SuggerimentiDelRecupero::fraseOltreTolleranza(2, 0))->toBe('2 persone con rate scadute senza una lettera.');
});

it('nel 2027 senza il tasso legale gli interessi si fermano: il suggerimento lo dice, con le persone, invece di tacere', function () {
    $s = accViaVerdi($this);
    adbAnnoDopo($s);
    $this->travelTo('2027-02-15 10:00:00');
    $fermi = collect(sgrProps($this, $s)['suggerimenti'])->firstWhere('chiave', 'interessi_fermi');
    expect($fermi['frase'])->toBe('Per 3 persone gli interessi di mora si fermano: il tasso legale del 2027 non è ancora nel programma. Puoi proporli fino al 31/12/2026.')
        ->and($fermi['porta'])->toBe('proponi_interessi')->and(count($fermi['persone']))->toBe(3)
        ->and(collect(sgrProps($this, $s)['suggerimenti'])->firstWhere('chiave', 'interessi_da_proporre'))->toBeNull();
});
