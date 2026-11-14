<?php

/**
 * Le spese per una sola unità chieste con le rate, nel deliberato del riepilogo: il deliberato per voce legge le righe del riparto che non sono
 * personali, e una riga sua, «Spese personali chieste con le rate», somma quelle personali; le quote deliberate del blocco sono voci più spese
 * personali, e la Sintesi e il frontespizio leggono quel totale. Lo stesso con il deliberato ricostruito, e «non disponibile» quando non torna.
 *
 * Il caso, a mano: Via Roma (il piano ordinario chiede € 4.110,00 = 3.000 + 1.200 + 150 − 240) e un piano straordinario con la fattura
 * FT-FACCIATA da € 1.200,00: € 1.000,00 sulla voce «Facciata» (Ugo e Bice, 500 e 500 millesimi: 500,00 e 500,00) e € 200,00 sull'unità A, senza
 * voce (a Ugo). I piani chiedono € 5.310,00; il deliberato per voce della Facciata è € 1.000,00, le spese personali € 200,00, le quote deliberate
 * del blocco straordinario € 1.200,00, il totale € 5.310,00.
 */

use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Services\Gestionale\ParoleDellaStampa;
use App\Services\Gestionale\RiepilogoPerCompetenza;
use App\Services\Gestionale\SintesiDelRendiconto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/GestionaleTestHelpers.php';
require_once __DIR__.'/RendicontoViaRomaScenario.php';
require_once __DIR__.'/LetturaDebitiProva.php';

beforeEach(function () {
    $this->user = be52Utente();
    $this->withoutVite();
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
});

/** Via Roma con il piano straordinario della facciata: € 1.000,00 sulla voce e € 200,00 sull'unità A, senza voce. */
function dspScenario($test): array
{
    $s = vrmBase($test);
    $s['gs'] = app(\App\Services\CondominioService::class)->createDefaultGestione($s['c'], $s['e'], 'straordinaria');
    $pcs = PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $s['gs']->id, 'nome' => 'Piano dei conti straordinario']);
    $s['facciata'] = be52Voce($test, $s, $pcs, 'Facciata', 'spesa', '1.000,00');
    $fattura = FatturaPassiva::create(['condominio_id' => $s['c']->id, 'fornitore_id' => $s['fornitore']->id, 'esercizio_id' => $s['e']->id,
        'tipo_documento' => 'fattura', 'numero_documento' => 'FT-FACCIATA', 'data_documento' => '2026-03-10', 'data_scadenza' => '2026-04-10', 'is_pregresso' => false,
        'importo_imponibile' => 120000, 'importo_iva' => 0, 'importo_ritenuta' => 0, 'totale_documento' => 120000, 'netto_a_pagare' => 120000, 'stato_pagamento' => 'aperta',
        'stato_approvazione' => 'approvata', 'modalita_pagamento' => 'bonifico']);
    DB::table('righe_fattura')->insert(['fattura_passiva_id' => $fattura->id, 'conto_id' => $s['facciata']->id, 'immobile_id' => null, 'descrizione' => 'Facciata',
        'aliquota_iva' => 0, 'importo_imponibile' => 100000, 'importo_iva' => 0, 'is_sopravvenienza' => true, 'is_rateizzata' => false, 'created_at' => now(), 'updated_at' => now()]);
    $s['riga_balcone'] = DB::table('righe_fattura')->insertGetId(['fattura_passiva_id' => $fattura->id, 'conto_id' => null, 'immobile_id' => $s['unita']['A']->id, 'descrizione' => 'Riparazione del balcone A',
        'aliquota_iva' => 0, 'importo_imponibile' => 20000, 'importo_iva' => 0, 'is_sopravvenienza' => true, 'is_rateizzata' => false, 'created_at' => now(), 'updated_at' => now()]);
    $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), [
        'tipo' => 'straordinario', 'gestione_id' => $s['gs']->id, 'nome' => 'Facciata 2026', 'metodo_distribuzione' => 'tutte_rate', 'numero_rate' => 2,
        'giorno_scadenza' => 10, 'data_prima_scadenza' => '2026-03-10', 'genera_subito' => 1,
        'fatture_config' => [['id' => $fattura->id, 'importo' => '1.200,00']],
        'tipo_autorizzazione' => 'delibera', 'motivazione_autorizzazione' => 'Delibera di prova', 'data_delibera_assemblea' => '2026-02-01',
    ])->assertSessionHasNoErrors();
    $s['straordinario'] = PianoRate::where('nome', 'Facciata 2026')->where('condominio_id', $s['c']->id)->firstOrFail();
    if ((string) ($s['straordinario']->stato instanceof \BackedEnum ? $s['straordinario']->stato->value : $s['straordinario']->stato) !== 'approvato') {
        $test->actingAs($test->user)->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $s['straordinario']]), ['approvato' => true, 'data_delibera_assemblea' => '2026-02-01'])
            ->assertSessionHasNoErrors();
    }

    return $s;
}

function dspBlocco(array $r, int $gestioneId): array
{
    foreach ($r['blocchi'] as $b) {
        if ((int) $b['gestione']['id'] === $gestioneId) {
            return $b;
        }
    }
    throw new RuntimeException('nessun blocco');
}

function dspVoce(array $blocco, int $voceId): array
{
    foreach ($blocco['spese'] as $v) {
        if ((int) $v['voce_id'] === $voceId) {
            return $v;
        }
    }
    throw new RuntimeException('nessuna voce');
}

it('le spese personali chieste con le rate hanno una riga loro nel deliberato, e le quote deliberate del blocco le comprendono: € 1.200,00, non € 1.000,00', function () {
    $s = dspScenario($this);
    // La riga del riparto della spesa personale c'è, senza voce, a Ugo: è la riga che il deliberato per voce non legge.
    expect(DB::table('righe_riparto')->where('piano_rate_id', $s['straordinario']->id)->where('tipo', 'ad_personam')->sum('importo'))->toEqual(20000);

    $r = app(RiepilogoPerCompetenza::class)->perEsercizio($s['c'], $s['e']);
    $b = dspBlocco($r, $s['gs']->id);
    $sintesi = app(SintesiDelRendiconto::class)->perEsercizio($s['c'], $s['e']);

    expect(dspVoce($b, $s['facciata']->id)['deliberato'])->toBe(100000)
        ->and($b['deliberato_stato'])->toBe('conservato')
        ->and($b['spese_personali'])->toBe(20000)
        ->and($b['quote'][0]['deliberate'])->toBe(120000)
        ->and($r['quote']['totale']['deliberate'])->toBe(531000)
        ->and($sintesi['prospetto']['deliberato'])->toBe(531000)
        ->and($sintesi['riquadri']['deliberato']['deliberato'])->toBe(531000);
});

it('con il deliberato ricostruito (il piano senza le righe del riparto) le stesse cifre; se non torna, le spese personali sono «non disponibili» come le voci', function () {
    $s = dspScenario($this);
    DB::table('righe_riparto')->where('piano_rate_id', $s['straordinario']->id)->delete();

    $b = dspBlocco(app(RiepilogoPerCompetenza::class)->perEsercizio($s['c'], $s['e']), $s['gs']->id);
    expect($b['deliberato_stato'])->toBe('ricostruito')
        ->and(dspVoce($b, $s['facciata']->id)['deliberato'])->toBe(100000)
        ->and($b['spese_personali'])->toBe(20000)
        ->and($b['quote'][0]['deliberate'])->toBe(120000);

    // La riga del balcone spostata sull'unità C dopo il piano: rifatto, il riparto dà i € 200,00 a Bice e le quote li chiedono a Ugo. Non torna.
    DB::table('righe_fattura')->where('id', $s['riga_balcone'])->update(['immobile_id' => $s['unita']['C']->id]);
    $r = app(RiepilogoPerCompetenza::class)->perEsercizio($s['c'], $s['e']);
    $b = dspBlocco($r, $s['gs']->id);
    expect($b['deliberato_stato'])->toBe('non_conservato')
        ->and($b['spese_personali'])->toBeNull()
        // Le rate del piano non sono emesse: senza un deliberato la riga delle quote del blocco non c'è.
        ->and($b['quote'])->toBe([]);
});

it('la pagina del riepilogo e la sua stampa hanno la riga delle spese personali chieste con le rate, che apre le fatture con l\'unità', function () {
    $s = dspScenario($this);

    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.riepilogo', [$s['c'], $s['e']]))->assertOk()
        ->assertInertia(fn ($p) => $p->where('riepilogo.blocchi.1.spese_personali', 20000));
    $html = view('pdf.gestionale.rendiconto_riepilogo', ['condominio' => $s['c'], 'esercizio' => $s['e'],
        'riepilogo' => app(RiepilogoPerCompetenza::class)->perEsercizio($s['c'], $s['e']), 'dettaglio' => false])->render();
    expect(vrmTesto($html))->toContain('Spese personali chieste con le rate')->toContain('€ 200,00');

    // Le fatture con una riga su un'unità: la FT-FACCIATA c'è, le fatture di sole voci no.
    $altra = FatturaPassiva::create(['condominio_id' => $s['c']->id, 'fornitore_id' => $s['fornitore']->id, 'esercizio_id' => $s['e']->id,
        'tipo_documento' => 'fattura', 'numero_documento' => 'FT-SOLO-VOCI', 'data_documento' => '2026-03-11', 'data_scadenza' => '2026-04-11', 'is_pregresso' => false,
        'importo_imponibile' => 1000, 'importo_iva' => 0, 'importo_ritenuta' => 0, 'totale_documento' => 1000, 'netto_a_pagare' => 1000, 'stato_pagamento' => 'aperta',
        'stato_approvazione' => 'approvata', 'modalita_pagamento' => 'bonifico']);
    DB::table('righe_fattura')->insert(['fattura_passiva_id' => $altra->id, 'conto_id' => $s['pulizie']->id, 'immobile_id' => null, 'descrizione' => 'Pulizie',
        'aliquota_iva' => 0, 'importo_imponibile' => 1000, 'importo_iva' => 0, 'is_sopravvenienza' => true, 'is_rateizzata' => false, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($this->user)->get(route('admin.gestionale.fatture.index', [$s['c']]).'?con_unita=1')->assertOk()
        ->assertInertia(fn ($p) => $p->where('fatture.data', fn ($righe) => collect($righe)->pluck('numero_documento')->all() === ['FT-FACCIATA'])->where('filters.con_unita', '1'));
});

it('la stampa dice il deliberato ricostruito e il piano che non si rifà con le parole della pagina', function () {
    $ricostruito = ParoleDellaStampa::frase(RiepilogoPerCompetenza::FRASE_DELIBERATO_RICOSTRUITO);
    $spostato = ParoleDellaStampa::frase(RiepilogoPerCompetenza::FRASE_DELIBERATO_SPOSTATO);

    expect($ricostruito)->toContain('ricostruito')->not->toContain('ricalcolat')
        ->and($spostato)->toContain('non si rifà')->not->toContain('ricalcola');
});
