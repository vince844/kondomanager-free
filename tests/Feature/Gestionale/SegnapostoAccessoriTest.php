<?php

/**
 * I segnaposto delle lettere che le regole del recupero portano: `[interessi]` (gli interessi di mora addebitati fra le righe della lettera), `[spese]`
 * (le spese per il ritardo addebitate fra le righe), `[tasso]` (il tasso del condominio con la sua fonte). Si riempiono solo con ciò che è registrato e
 * che la lettera chiede, quindi stanno nel suo `[totale]`: mai una cifra calcolata e non registrata; senza niente, «€ 0,00», e l'anteprima lo dice.
 *
 * Neri (`AccessoriScenario.php`): il 29/06 interessi di mora al 29/06, € 1,18 (300,00 × 1,60 % × 90/365), e spese di sollecito, € 25,00. Bianchi niente.
 */

use App\Models\Gestionale\RegistroInvio;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/AccessoriScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

const SAA_TESTO = "Gentile [nome],\nrisultano da versare [totale], con interessi [interessi] e spese [spese] (tasso [tasso]). Entro [termine].";

it('l\'anteprima e la lettera: [interessi] € 1,18, [spese] € 25,00, [tasso] «1,60 % annuo, tasso legale»; chi non ne ha «€ 0,00», e l\'anteprima lo conta', function () {
    $s = accViaVerdi($this);
    $this->travelTo('2026-06-29 10:00:00');
    accRegistra($this, $s, accProposta($this, $s, ['Neri'])->json())->assertCreated();
    adbRegistra($this, $s, [['chi' => 'Neri', 'unita' => 'U1', 'euro' => '25,00', 'causale' => 'Spese di sollecito', 'scadenza' => '2026-06-29', 'natura' => 'accessorio',
        'tipo_accessorio' => 'sollecito', 'base' => 'delibera', 'conto_id' => $s['postali']->id]])->assertCreated();

    $this->travelTo('2026-07-10 10:00:00');
    $scelte = ['tipo' => 'sollecito', 'persone' => [$s['persone']['Neri']->id, $s['persone']['Bianchi']->id], 'testo' => SAA_TESTO, 'termine' => 15, 'indirizzi' => ['email']];
    $a = $this->actingAs($this->user)->postJson(route('admin.gestionale.lettere.anteprima', ['condominio' => $s['c']->id]), $scelte)->assertOk()->json();
    $per = collect($a['persone'])->keyBy('nome');
    expect([$per['Neri']['interessi'], $per['Neri']['spese']])->toBe([118, 2500])->and([$per['Bianchi']['interessi'], $per['Bianchi']['spese']])->toBe([0, 0])
        ->and([$a['interessi_a_zero'], $a['spese_a_zero'], $a['tasso']])->toBe([1, 1, '1,60 % annuo, tasso legale']);

    \Illuminate\Support\Facades\Queue::fake();
    config(['mail.default' => 'array']);
    $this->actingAs($this->user)->postJson(route('admin.gestionale.lettere.invia', ['condominio' => $s['c']->id]), $scelte)->assertOk();
    $neri = RegistroInvio::where('anagrafica_id', $s['persone']['Neri']->id)->where('tipo', 'sollecito')->sole();
    $bianchi = RegistroInvio::where('anagrafica_id', $s['persone']['Bianchi']->id)->where('tipo', 'sollecito')->sole();
    expect($neri->dati['testo'])->toContain('con interessi € 1,18 e spese € 25,00 (tasso 1,60 % annuo, tasso legale)')
        ->and($neri->dati['segnaposti'])->toBe(['interessi' => 118, 'spese' => 2500, 'tasso' => '1,60 % annuo, tasso legale'])
        ->and($bianchi->dati['testo'])->toContain('con interessi € 0,00 e spese € 0,00');

    // Neri versa gli interessi: non sono più da versare, e la lettera dopo dice € 0,00. Mai una cifra calcolata: gli interessi maturati dopo il 29/06
    // non entrano finché non si registrano.
    $quota = \App\Models\Gestionale\RataQuote::where('anagrafica_id', $s['persone']['Neri']->id)->where('tipo', 'accessorio')->orderBy('id')->firstOrFail();
    lcdIncassa($this, $s, 'Neri', [$quota->id => 118], '2026-07-10');
    $dopo = $this->actingAs($this->user)->postJson(route('admin.gestionale.lettere.anteprima', ['condominio' => $s['c']->id]), $scelte)->json();
    expect(collect($dopo['persone'])->firstWhere('nome', 'Neri')['interessi'])->toBe(0);
});

it('[tasso] con una regola scritta: «3,00 % annuo, art. 12 del regolamento»; il termine di serie delle lettere viene dalle regole', function () {
    $s = accViaVerdi($this);
    $this->travelTo('2026-07-10 10:00:00');
    $this->actingAs($this->user)->post(route('admin.gestionale.recupero-crediti.store', [$s['c']]), [
        'dal' => '2026-07-01', 'tasso_tipo' => 'scritto', 'tasso_annuo' => '3', 'fonte' => 'regolamento', 'riferimento' => 'art. 12 del regolamento', 'termine_lettere_giorni' => 20,
    ])->assertSessionHasNoErrors();

    $a = $this->actingAs($this->user)->postJson(route('admin.gestionale.lettere.anteprima', ['condominio' => $s['c']->id]),
        ['tipo' => 'sollecito', 'persone' => [$s['persone']['Neri']->id], 'testo' => SAA_TESTO, 'termine' => 15, 'indirizzi' => ['email']])->json();
    expect($a['tasso'])->toBe('3,00 % annuo, art. 12 del regolamento');
    expect($this->actingAs($this->user)->getJson(route('admin.gestionale.lettere.opzioni', ['condominio' => $s['c']->id]))->json('termine_di_serie'))->toBe(20);
});

it('[interessi] e [spese] stanno nel [totale] della stessa lettera: il giorno della registrazione non ci sono ancora fra le righe, e dicono € 0,00', function () {
    $s = accViaVerdi($this);
    $this->travelTo('2026-06-29 10:00:00');
    accRegistra($this, $s, accProposta($this, $s, ['Neri'])->json())->assertCreated();
    adbRegistra($this, $s, [['chi' => 'Neri', 'unita' => 'U1', 'euro' => '25,00', 'causale' => 'Spese di sollecito', 'scadenza' => '2026-07-31', 'natura' => 'accessorio',
        'tipo_accessorio' => 'sollecito', 'base' => 'delibera', 'conto_id' => $s['postali']->id]])->assertCreated();
    $testo = "Gentile [nome],\nrisultano da versare [totale], di cui interessi di mora [interessi] e spese [spese]. Entro [termine].";
    $scelte = ['tipo' => 'sollecito', 'persone' => [$s['persone']['Neri']->id], 'testo' => $testo, 'termine' => 15, 'indirizzi' => ['email']];

    // Il 29/06: la lettera ha la rata 1, € 300,00; gli interessi scadono oggi e il sollecito il 31/07, e non sono fra le righe.
    $neri = collect($this->actingAs($this->user)->postJson(route('admin.gestionale.lettere.anteprima', ['condominio' => $s['c']->id]), $scelte)->assertOk()->json('persone'))->firstWhere('nome', 'Neri');
    expect([$neri['totale'], $neri['interessi'], $neri['spese'], $neri['righe']])->toBe([30000, 0, 0, 1]);

    // Il 10/07: gli interessi sono scaduti e fra le righe; il sollecito no. € 301,18, di cui interessi € 1,18 e spese € 0,00.
    $this->travelTo('2026-07-10 10:00:00');
    $neri = collect($this->actingAs($this->user)->postJson(route('admin.gestionale.lettere.anteprima', ['condominio' => $s['c']->id]), $scelte)->assertOk()->json('persone'))->firstWhere('nome', 'Neri');
    expect([$neri['totale'], $neri['interessi'], $neri['spese'], $neri['righe']])->toBe([30118, 118, 0, 2]);
    \Illuminate\Support\Facades\Queue::fake();
    config(['mail.default' => 'array']);
    $this->actingAs($this->user)->postJson(route('admin.gestionale.lettere.invia', ['condominio' => $s['c']->id]), $scelte)->assertOk();
    expect(RegistroInvio::where('anagrafica_id', $s['persone']['Neri']->id)->latest('id')->firstOrFail()->dati['testo'])
        ->toContain('risultano da versare € 301,18, di cui interessi di mora € 1,18 e spese € 0,00.');
});
