<?php

/**
 * Il piano degli addebiti nei lettori che non sono la lettura comune: il prospetto degli oneri accessori dell'inquilino (non è un preventivo, e non
 * lo legge), le lettere ai condòmini (l'addebito si chiama con la sua causale, non «Rata 1»), la pagina di un piano nuovo (non fa «Integrativa» il
 * primo preventivo dell'anno), la pagina del piano degli addebiti (si apre), le persone a cui un addebito può andare (una riga spenta non conta,
 * come nel riparto).
 *
 * Il caso è Via Verdi (`ConsuntivoPerPersonaPosizioneScenario.php`): le quattro rate emesse il 15/01, Neri su U1.
 */

use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Services\Gestionale\Addebiti\AddebitiIndividuali;
use App\Services\Gestionale\Lettere\EmissioneLettere;
use App\Services\Riparto\ProspettoOneriAccessori;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/AddebitiScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

it('il prospetto degli oneri accessori dell\'inquilina di U1 non cambia con un contributo e una spesa fatturata addebitati a Neri: il piano degli addebiti non è un preventivo', function () {
    $s = cpcViaVerdi($this, ['inquilino' => ['U1', 'Ines', 30, '2026-01-01', null]]);
    cpcRateEIncassi($this, $s);
    cpcSpese($s);
    $riga = cpcSpesaPersonale($s, 'U1', 2500, '2026-10-01');
    $this->travelTo('2026-11-02 10:00:00');
    $prima = app(ProspettoOneriAccessori::class)->calcola($s['unita']['U1']->fresh(), $s['e']->fresh());
    adbRegistra($this, $s, [['chi' => 'Neri', 'unita' => 'U1', 'euro' => '50,00', 'causale' => 'Contributo cantina', 'scadenza' => '2026-11-20'],
        ['chi' => 'Neri', 'unita' => 'U1', 'euro' => '25,00', 'causale' => 'Spese di sollecito', 'natura' => 'accessorio', 'riga_fattura_id' => $riga, 'scadenza' => '2026-11-20']])
        ->assertCreated();
    $dopo = app(ProspettoOneriAccessori::class)->calcola($s['unita']['U1']->fresh(), $s['e']->fresh());

    expect(json_encode($dopo, JSON_UNESCAPED_UNICODE))->toBe(json_encode($prima, JSON_UNESCAPED_UNICODE))
        ->and(json_encode($dopo, JSON_UNESCAPED_UNICODE))->not->toContain('Addebiti individuali');
});

it('la lettera di sollecito a Neri chiama l\'addebito con la sua causale; le rate del preventivo restano «Rata N»', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    $this->travelTo('2026-05-01 10:00:00');
    $rataId = adbRegistra($this, $s, [['chi' => 'Neri', 'unita' => 'U1', 'euro' => '50,00', 'causale' => 'Contributo cantina', 'scadenza' => '2026-05-15']])->assertCreated()->json('rate.0');

    $this->travelTo('2026-07-01 10:00:00');
    $righe = collect(app(EmissioneLettere::class)->contenuti($s['c'], 'sollecito', ['persone' => [$s['persone']['Neri']->id]])['persone'][$s['persone']['Neri']->id]['righe']);
    expect($righe->firstWhere('rata_id', $rataId)['addebito'] ?? null)->toBe('Contributo cantina')
        ->and($righe->where('rata_id', '!=', $rataId)->isNotEmpty())->toBeTrue()
        ->and($righe->where('rata_id', '!=', $rataId)->every(fn (array $r) => ! array_key_exists('addebito', $r)))->toBeTrue();
    // Il PDF della lettera, nei tre casi della carta: `tests/Feature/Pdf/AddebitiNelRendicontoStampaTest.php`.
});

it('una gestione con il solo piano degli addebiti chiama il suo primo piano «Piano rate», non «Integrativa»; il piano degli addebiti nasce «libero»', function () {
    $s = cpcViaVerdi($this);
    $g2 = Gestione::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Riscaldamento 2026', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($s['e'], $g2->id);
    $this->travelTo('2026-01-20 10:00:00');
    adbRegistra($this, $s, [['chi' => 'Neri', 'unita' => 'U1', 'euro' => '5,00', 'causale' => 'Contributo', 'scadenza' => '2026-01-31']], $g2->id, $s['e']->id)->assertCreated();

    $props = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.piani-rate.create', [$s['c'], $s['e']]) . '?gestione_id=' . $g2->id)->assertOk()->viewData('page')['props'];
    $piano = PianoRate::degliAddebiti()->sole();
    expect($props['hasPianoEsistente'])->toBeFalse()
        ->and(DB::table('piani_rate')->where('id', $piano->id)->value('contesto_creazione'))->toBe('libero_manuale')
        // Il nome dice l'anno una volta: la gestione lo porta già.
        ->and($piano->nome)->toBe('Addebiti individuali – Riscaldamento 2026');
});

it('il nome del piano degli addebiti porta l\'anno quando il nome della gestione non lo dice', function () {
    $s = cpcViaVerdi($this);
    $g2 = Gestione::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Riscaldamento', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($s['e'], $g2->id);
    $this->travelTo('2026-01-20 10:00:00');
    adbRegistra($this, $s, [['chi' => 'Neri', 'unita' => 'U1', 'euro' => '5,00', 'causale' => 'Contributo', 'scadenza' => '2026-01-31']], $g2->id, $s['e']->id)->assertCreated();

    expect(PianoRate::degliAddebiti()->sole()->nome)->toBe('Addebiti individuali 2026 – Riscaldamento');
});

it('la pagina del piano degli addebiti si apre, con la frase sua e senza le quote non assegnate; la pagina del piano del riparto pure', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    $this->travelTo('2026-05-01 10:00:00');
    adbRegistra($this, $s, [['chi' => 'Neri', 'unita' => 'U1', 'euro' => '50,00', 'causale' => 'Contributo', 'scadenza' => '2026-05-01']])->assertCreated();
    $piano = PianoRate::degliAddebiti()->sole();

    $props = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.piani-rate.show', [$s['c'], $s['e'], $piano]))->assertOk()->viewData('page')['props'];
    expect($props['degli_addebiti'])->toBe(['frase' => '«Addebiti individuali – Ordinaria 2026» ' . PianoRate::FRASE_DEGLI_ADDEBITI])
        ->and($props['addebiti_delle_quote'])->toBe([]);
    $props = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.piani-rate.show', [$s['c'], $s['e'], $s['piano']]))->assertOk()->viewData('page')['props'];
    expect($props['degli_addebiti'])->toBeNull();
});

it('una riga di titolarità spenta non fa di nessuno un titolare a cui addebitare, come nel riparto', function () {
    $s = cpcViaVerdi($this);
    $this->travelTo('2026-05-01 10:00:00');
    expect(app(\App\Services\Gestionale\Addebiti\PersoneDellAddebito::class)->puoRicevere((int) $s['persone']['Neri']->id, (int) $s['unita']['U1']->id))->toBeTrue();
    DB::table('anagrafica_immobile')->where('id', $s['righe']['Neri'])->update(['attivo' => false]);

    adbRegistra($this, $s, [['chi' => 'Neri', 'unita' => 'U1', 'euro' => '10,00']])->assertStatus(422)
        ->assertJsonPath('errors.addebiti.0', AddebitiIndividuali::FRASE_INQUILINO);
    $p = $this->actingAs($this->user)->getJson(route('admin.gestionale.addebiti.proposta', [$s['c']]) . '?anagrafica_id=' . $s['persone']['Neri']->id)->assertOk()->json();
    expect($p['unita'])->toBe([]);
});
