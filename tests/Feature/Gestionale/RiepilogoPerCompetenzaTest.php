<?php

/**
 * Il riepilogo finanziario per competenza dell'esercizio: per capitolo il preventivo, il deliberato con le rate, il consuntivo, l'anno prima, lo
 * scostamento, il pagato e il da pagare alla data; fuori dai capitoli le fatture contestate, le commissioni senza voce e i costi non classificati, in
 * una riga ciascuna; le entrate da terzi; le quote deliberate, emesse, pagate e non pagate; il risultato con i suoi due termini, letto dal giornale.
 *
 * La data della lettura è la fine dell'esercizio se è finito, oggi se è aperto. Il caso è «Via Roma» (`RendicontoViaRomaScenario.php`); le cifre
 * sono fatte a mano dalla regola, prima del codice. Centesimi.
 */

use App\Models\Esercizio;
use App\Models\Gestionale\FatturaPassiva;
use App\Services\Gestionale\Debiti\LetturaDebiti;
use App\Services\Gestionale\RiepilogoPerCompetenza;
use App\Services\Gestionale\StatoPatrimonialePaginaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/GestionaleTestHelpers.php';
require_once __DIR__.'/RendicontoViaRomaScenario.php';
require_once __DIR__.'/LetturaDebitiProva.php';

beforeEach(function () {
    $this->user = be52Utente();
    // Le pagine si leggono dalla loro rotta: il test non dipende da una compilazione di Vite.
    $this->withoutVite();
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
});

function rpcLeggi(array $s, ?Esercizio $e = null): array
{
    return app(RiepilogoPerCompetenza::class)->perEsercizio($s['c'], $e ?? $s['e']);
}

/** La voce (foglia o capitolo) del riepilogo, per id, in qualunque blocco e sezione. */
function rpcVoce(array $r, int $voceId): ?array
{
    foreach ($r['blocchi'] as $b) {
        foreach ([...$b['spese'], ...$b['entrate']] as $v) {
            if ((int) $v['voce_id'] === $voceId) {
                return $v;
            }
            foreach ($v['voci'] ?? [] as $f) {
                if ((int) $f['voce_id'] === $voceId) {
                    return $f;
                }
            }
        }
    }

    return null;
}

function rpcColonne(array $v, array $chiavi = ['consuntivo', 'pagato', 'da_pagare']): array
{
    return array_map(fn ($k) => $v[$k], $chiavi);
}

// ─── Il caso intero ─────────────────────────────────────────────────────────────────────────────────────────────────────

it('il caso è quello del progetto: banca € 5.219,00, costi € 4.131,00, risultato € −21,00, debiti verso fornitori € 650,00, Erario € 0,00', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $p = app(StatoPatrimonialePaginaService::class)->costruisci($s['c'], $s['e']);

    expect($p['data'])->toBe('2026-12-31')
        ->and($p['liquidita']['liquidita_totale'])->toBe(521900)
        ->and($p['risultato_gestione'])->toMatchArray(['quote_emesse' => 411000, 'costi' => 413100, 'risultato' => -2100])
        ->and($p['sintesi']['debiti_fornitori'])->toBe(65000)
        ->and($p['sintesi']['debiti_erario'])->toBe(0);
});

it('il riepilogo al 31/12: ogni cifra della tabella del progetto, per voce', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $r = rpcLeggi($s);

    expect($r['alla'])->toBe('2026-12-31')->and($r['finito'])->toBeTrue()
        ->and(rpcColonne(rpcVoce($r, $s['pulizie']->id), ['preventivo', 'consuntivo', 'pagato', 'da_pagare']))->toBe([300000, 290000, 240000, 50000])
        // 1.180,00 al fornitore + 40,00 con l'F24 del 16/12.
        ->and(rpcColonne(rpcVoce($r, $s['manutenzioni']->id), ['preventivo', 'consuntivo', 'pagato', 'da_pagare', 'ritenute_da_versare']))->toBe([120000, 122000, 122000, 0, 0])
        // 96,00 di regolazione + 2,50 di commissione con la voce, pagati alla data della loro scrittura.
        ->and(rpcColonne(rpcVoce($r, $s['bancarie']->id), ['preventivo', 'consuntivo', 'pagato', 'da_pagare']))->toBe([15000, 9850, 9850, 0])
        ->and(rpcColonne(rpcVoce($r, $s['antenna']->id), ['preventivo', 'incassato']))->toBe([24000, 24000]);
});

it('fuori dai capitoli: le fatture contestate € 150,00 e le commissioni senza voce € 2,50, in una riga ciascuna; nessun costo non classificato', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $f = rpcLeggi($s)['fuori_capitoli'];

    expect(rpcColonne($f['contestate']))->toBe([15000, 0, 15000])->and($f['contestate']['numero'])->toBe(1)
        ->and(rpcColonne($f['commissioni_senza_voce']))->toBe([250, 250, 0])->and($f['commissioni_senza_voce']['numero'])->toBe(1)
        ->and(rpcColonne($f['non_classificati']))->toBe([0, 0, 0]);
});

it('una contestata non si paga dalla rotta; se il giornale ha un pagamento legato a una fattura ora contestata, la sua commissione sta con la contestata, nel consuntivo e nel pagato', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2026-12-20');
    $this->actingAs($this->user)->post(route('admin.gestionale.pagamenti-fornitori.store', [$s['c']]), [
        'fornitore_id' => $s['fornitore']->id, 'esercizio_id' => $s['e']->id, 'conto_corrente_id' => $s['cassa']->conto_contabile_id, 'data_pagamento' => '2026-12-20',
        'metodo_pagamento' => 'bonifico', 'iban_beneficiario' => 'IT60X0542811101000000123456', 'importo_commissioni_cents' => 0, 'bonifico_parlante' => false,
        'allow_overdraft' => true, 'iban_confermato_manualmente' => true, 'conferma_duplicato_verificato' => true,
        'allocazioni' => [['fattura_id' => $s['ft3']->id, 'tipo' => 'pagamento', 'importo_allocato_cents' => 5000]],
    ])->assertSessionHasErrors();

    // I dati di una fattura pagata e contestata dopo (il giornale lo permette, la pagina no): il pagamento della FT-1 legato anche alla FT-3.
    DB::table('fattura_scrittura')->insert(['fattura_passiva_id' => $s['ft3']->id, 'scrittura_contabile_id' => $s['pag_ft1']->scrittura_contabile_id, 'tipo' => 'pagamento',
        'importo_allocato' => 0, 'created_at' => now(), 'updated_at' => now()]);
    vrmGiorno($this, '2027-01-15');
    $r = rpcLeggi($s);

    // La commissione di € 2,50 di quel pagamento esce dalla voce «Spese bancarie» (come la esclude il consuntivo) ed entra nella riga delle contestate.
    expect(rpcColonne(rpcVoce($r, $s['bancarie']->id)))->toBe([9600, 9600, 0])
        ->and(rpcColonne($r['fuori_capitoli']['contestate']))->toBe([15250, 250, 15000])
        ->and($r['riconciliazione']['differenza'])->toBe(0);
});

it('la riga che riconcilia: costi a giornale € 4.131,00 = spese per capitolo meno entrate € 3.978,50 + commissioni senza voce € 2,50 + contestate € 150,00', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $q = rpcLeggi($s)['riconciliazione'];

    expect($q)->toMatchArray(['costi' => 413100, 'capitoli_netti' => 397850, 'commissioni_senza_voce' => 250, 'contestate' => 15000, 'non_classificati' => 0, 'differenza' => 0]);
});

it('il risultato € −21,00 con i suoi due termini, gli stessi della pagina dello stato patrimoniale: quote a giornale € 4.110,00, costi € 4.131,00', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $r = rpcLeggi($s);
    $p = app(StatoPatrimonialePaginaService::class)->costruisci($s['c'], $s['e']);

    expect($r['risultato'])->toMatchArray(['quote_emesse' => 411000, 'costi' => 413100, 'risultato' => -2100])
        ->and($r['risultato'])->toMatchArray(array_intersect_key($p['risultato_gestione'], array_flip(['quote_emesse', 'costi', 'risultato'])));
});

it('con una fattura del 2025 da € 300,00 ancora aperta il riepilogo dice «da pagare» € 650,00 e non dice che è uguale ai debiti, che sono € 950,00', function () {
    $s = vrmScenario($this);
    $e2025 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'chiuso']);
    $vecchia = vrmFattura($this, $s, $s['fornitore'], 'FT-2025', [[$s['pulizie']->id, 300.00]], '2025-12-10');
    // La fattura scritta nell'esercizio 2025: la sua scrittura di competenza e il documento.
    $sc = DB::table('fattura_scrittura')->where('fattura_passiva_id', $vecchia->id)->where('tipo', 'competenza')->value('scrittura_contabile_id');
    DB::table('scritture_contabili')->where('id', $sc)->update(['esercizio_id' => $e2025->id, 'data_competenza' => '2025-12-10']);
    DB::table('fatture_passive')->where('id', $vecchia->id)->update(['esercizio_id' => $e2025->id]);
    vrmGiorno($this, '2027-01-15');
    $r = rpcLeggi($s);
    $p = app(StatoPatrimonialePaginaService::class)->costruisci($s['c'], $s['e']);

    expect($r['totali']['da_pagare'])->toBe(65000)
        ->and($p['sintesi']['debiti_fornitori'])->toBe(95000)
        ->and($r)->not->toHaveKey('uguaglianza_debiti')
        ->and($r['avvertenze']['perimetro_da_pagare'])->toContain('fatture di questo esercizio');
});

// ─── Il pagato alla data ────────────────────────────────────────────────────────────────────────────────────────────────

it('una fattura da € 100,00 su tre voci (33,33 + 33,33 + 33,34) pagata € 50,00: il pagato si divide in 16,67, 16,66, 16,67', function () {
    $s = vrmBase($this);
    $v1 = be52Voce($this, $s, $s['pc'], 'Ascensore', 'spesa', '100,00');
    $v2 = be52Voce($this, $s, $s['pc'], 'Giardino', 'spesa', '100,00');
    $v3 = be52Voce($this, $s, $s['pc'], 'Luce scale', 'spesa', '100,00');
    vrmGiorno($this, '2026-05-10');
    $ft = vrmFattura($this, $s, $s['fornitore'], 'FT-TRE', [[$v1->id, 33.33], [$v2->id, 33.33], [$v3->id, 33.34]], '2026-05-10');
    vrmPaga($this, $s, $s['fornitore'], [[$ft, 'pagamento', 5000]], '2026-05-10');
    $r = rpcLeggi($s);

    expect([rpcVoce($r, $v1->id)['pagato'], rpcVoce($r, $v2->id)['pagato'], rpcVoce($r, $v3->id)['pagato']])->toBe([1667, 1666, 1667])
        ->and([rpcVoce($r, $v1->id)['da_pagare'], rpcVoce($r, $v2->id)['da_pagare'], rpcVoce($r, $v3->id)['da_pagare']])->toBe([1666, 1667, 1667])
        ->and($r['avvertenze']['pagato_diviso'])->toContain('in proporzione');
});

it('fattura € 1.000,00 e nota di credito € 200,00 sulla stessa voce, bonifico € 800,00: consuntivo 800,00, pagato 800,00, da pagare 0,00', function () {
    $s = vrmBase($this);
    vrmGiorno($this, '2026-05-10');
    $ft = vrmFattura($this, $s, $s['fornitore'], 'FT-A', [[$s['pulizie']->id, 1000.00]], '2026-05-10');
    $nc = vrmFattura($this, $s, $s['fornitore'], 'NC-1', [[$s['pulizie']->id, 200.00]], '2026-05-10', ['tipo_documento' => 'nota_credito']);
    vrmPaga($this, $s, $s['fornitore'], [[$ft, 'pagamento', 80000], [$ft, 'compensazione', 20000], [$nc, 'compensazione', 20000]], '2026-05-10');

    expect(rpcColonne(rpcVoce(rpcLeggi($s), $s['pulizie']->id)))->toBe([80000, 80000, 0]);
});

it('con la nota su un\'altra voce: 1.000,00 e −200,00 pagati, da pagare zero tutte e due', function () {
    $s = vrmBase($this);
    vrmGiorno($this, '2026-05-10');
    $ft = vrmFattura($this, $s, $s['fornitore'], 'FT-A', [[$s['pulizie']->id, 1000.00]], '2026-05-10');
    $nc = vrmFattura($this, $s, $s['fornitore'], 'NC-1', [[$s['manutenzioni']->id, 200.00]], '2026-05-10', ['tipo_documento' => 'nota_credito']);
    vrmPaga($this, $s, $s['fornitore'], [[$ft, 'pagamento', 80000], [$ft, 'compensazione', 20000], [$nc, 'compensazione', 20000]], '2026-05-10');
    $r = rpcLeggi($s);

    expect(rpcColonne(rpcVoce($r, $s['pulizie']->id)))->toBe([100000, 100000, 0])
        ->and(rpcColonne(rpcVoce($r, $s['manutenzioni']->id)))->toBe([-20000, -20000, 0]);
});

it('la FT-2 con la ritenuta prima dell\'F24: pagato 1.180,00, da pagare 40,00 «di cui ritenute da versare»; dopo l\'F24 1.220,00 e 0,00', function () {
    $s = vrmFinoANovembre($this);
    vrmGiorno($this, '2026-12-01');
    $prima = rpcVoce(rpcLeggi($s), $s['manutenzioni']->id);
    vrmGiorno($this, '2026-12-16');
    vrmVersaF24($s, '2026-12-16');
    $dopo = rpcVoce(rpcLeggi($s), $s['manutenzioni']->id);

    expect(rpcColonne($prima, ['consuntivo', 'pagato', 'da_pagare', 'ritenute_da_versare']))->toBe([122000, 118000, 4000, 4000])
        ->and(rpcColonne($dopo, ['consuntivo', 'pagato', 'da_pagare', 'ritenute_da_versare']))->toBe([122000, 122000, 0, 0]);
});

it('l\'F24 stornato prima della data: la ritenuta della FT-2 torna «da versare»', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2026-12-20');
    $delega = \App\Models\Gestionale\DelegaF24::where('condominio_id', $s['c']->id)->where('stato', 'versata')->firstOrFail();
    (new \App\Actions\Gestionale\Movimenti\StornaVersamentoF24Action)->esegui($delega, 'Delega respinta dalla banca', '2026-12-20');
    vrmGiorno($this, '2027-01-15');

    expect(rpcColonne(rpcVoce(rpcLeggi($s), $s['manutenzioni']->id), ['consuntivo', 'pagato', 'da_pagare', 'ritenute_da_versare']))->toBe([122000, 118000, 4000, 4000]);
});

it('il pagamento della FT-2 stornato prima della data, con l\'F24 già versato: la fattura torna da pagare per intero, ritenuta compresa', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2026-12-20');
    app(\App\Services\Gestionale\PagamentoFornitoreService::class)->stornaPagamento($s['pag_ft2']->fresh(), 'Bonifico respinto');
    vrmGiorno($this, '2027-01-15');

    expect(rpcColonne(rpcVoce(rpcLeggi($s), $s['manutenzioni']->id), ['consuntivo', 'pagato', 'da_pagare', 'ritenute_da_versare']))->toBe([122000, 0, 122000, 4000]);
});

it('una regolazione dell\'esercizio con la data dopo la fine: è nel consuntivo, e alla data non è pagata', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2026-12-20');
    $this->actingAs($this->user)->post(route('admin.gestionale.regolazioni-immediate.store', [$s['c']]), [
        'gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id, 'conto_id' => $s['bancarie']->id, 'cassa_id' => $s['cassa']->id, 'fornitore_id' => null,
        'data_operazione' => '2026-12-20', 'causale' => 'Competenze di dicembre', 'importo' => 12.00,
    ])->assertSessionHasNoErrors();
    // Addebitata a gennaio e scritta nel 2026 ancora aperto: la data del fatto è dopo la fine dell'esercizio.
    DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('causale', 'Competenze di dicembre')->update(['data_competenza' => '2027-01-10']);
    vrmGiorno($this, '2027-01-15');

    expect(rpcColonne(rpcVoce(rpcLeggi($s), $s['bancarie']->id)))->toBe([11050, 9850, 1200]);
});

it('una riga di costo senza voce che non è una commissione è un costo non classificato, e la riga che riconcilia la conta', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2026-12-20');
    $this->actingAs($this->user)->post(route('admin.gestionale.regolazioni-immediate.store', [$s['c']]), [
        'gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id, 'conto_id' => $s['pulizie']->id, 'cassa_id' => $s['cassa']->id, 'fornitore_id' => null,
        'data_operazione' => '2026-12-20', 'causale' => 'Senza voce', 'importo' => 12.00,
    ])->assertSessionHasNoErrors();
    $sc = DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('causale', 'Senza voce')->value('id');
    DB::table('righe_scritture')->where('scrittura_id', $sc)->whereNotNull('voce_spesa_id')->update(['voce_spesa_id' => null]);
    vrmGiorno($this, '2027-01-15');
    $r = rpcLeggi($s);

    expect(rpcColonne($r['fuori_capitoli']['non_classificati']))->toBe([1200, 1200, 0])
        ->and($r['riconciliazione'])->toMatchArray(['costi' => 414300, 'capitoli_netti' => 397850, 'non_classificati' => 1200, 'differenza' => 0]);
});

it('un pagamento su due fatture con la ritenuta: la ritenuta versata si divide fra le fatture in proporzione', function () {
    $s = vrmBase($this);
    vrmGiorno($this, '2026-05-10');
    // FT-X imponibile 1.000,00 (ritenuta 40,00, netto 1.180,00); FT-Y imponibile 500,00 (ritenuta 20,00, netto 590,00); un bonifico solo.
    $x = vrmFattura($this, $s, $s['idraulico'], 'FT-X', [[$s['pulizie']->id, 1000.00, 22]], '2026-05-10');
    $y = vrmFattura($this, $s, $s['idraulico'], 'FT-Y', [[$s['manutenzioni']->id, 500.00, 22]], '2026-05-10');
    vrmPaga($this, $s, $s['idraulico'], [[$x, 'pagamento', 118000], [$y, 'pagamento', 59000]], '2026-05-10');
    vrmGiorno($this, '2026-06-16');
    vrmVersaF24($s, '2026-06-16');
    $r = rpcLeggi($s);

    expect(rpcColonne(rpcVoce($r, $s['pulizie']->id), ['consuntivo', 'pagato', 'da_pagare']))->toBe([122000, 122000, 0])
        ->and(rpcColonne(rpcVoce($r, $s['manutenzioni']->id), ['consuntivo', 'pagato', 'da_pagare']))->toBe([61000, 61000, 0]);
});

it('un pagamento stornato dopo la data non cambia il pagato alla data', function () {
    $s = vrmBase($this);
    vrmGiorno($this, '2026-05-10');
    $ft = vrmFattura($this, $s, $s['fornitore'], 'FT-A', [[$s['pulizie']->id, 1000.00]], '2026-05-10');
    $p = vrmPaga($this, $s, $s['fornitore'], [[$ft, 'pagamento', 100000]], '2026-05-10');
    // Il 2026 è finito per data: la lettura è al 31/12. Lo storno del 10/01/2027 è dopo la data.
    $e2027 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    vrmGiorno($this, '2027-01-10');
    app(\App\Services\Gestionale\PagamentoFornitoreService::class)->stornaPagamento($p->fresh(), 'Bonifico respinto');
    $alla = rpcVoce(rpcLeggi($s), $s['pulizie']->id);

    expect(rpcColonne($alla))->toBe([100000, 100000, 0]);
});

it('una regolazione immediata e una commissione con la voce sono pagate alla data della loro scrittura', function () {
    $s = vrmBase($this);
    vrmGiorno($this, '2026-05-10');
    $ft = vrmFattura($this, $s, $s['fornitore'], 'FT-A', [[$s['pulizie']->id, 1000.00]], '2026-05-10');
    vrmPaga($this, $s, $s['fornitore'], [[$ft, 'pagamento', 50000]], '2026-05-10', 250, $s['bancarie']->id);
    $this->actingAs($this->user)->post(route('admin.gestionale.regolazioni-immediate.store', [$s['c']]), [
        'gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id, 'conto_id' => $s['bancarie']->id, 'cassa_id' => $s['cassa']->id, 'fornitore_id' => null,
        'data_operazione' => '2026-05-10', 'causale' => 'Bolli', 'importo' => 12.00,
    ])->assertSessionHasNoErrors();

    expect(rpcColonne(rpcVoce(rpcLeggi($s), $s['bancarie']->id)))->toBe([1450, 1450, 0])
        ->and(rpcColonne(rpcVoce(rpcLeggi($s), $s['pulizie']->id)))->toBe([100000, 50000, 50000]);
});

it('il dettaglio per spesa: una riga per fattura con fornitore, numero e descrizione; la somma è la voce', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $v = rpcVoce(rpcLeggi($s), $s['manutenzioni']->id);
    $righe = collect($v['dettaglio']);
    $ft2 = $righe->firstWhere('numero', 'FT-2');

    expect($ft2)->toMatchArray(['fornitore' => 'Idraulica Bianchi', 'descrizione' => 'Servizio FT-2', 'consuntivo' => 122000, 'pagato' => 122000, 'da_pagare' => 0])
        // La contestata sta nella sua riga, non nel dettaglio della voce.
        ->and($righe->firstWhere('numero', 'FT-3'))->toBeNull()
        ->and($righe->sum('consuntivo'))->toBe($v['consuntivo'])->and($righe->sum('pagato'))->toBe($v['pagato']);
});

// ─── Le quote ──────────────────────────────────────────────────────────────────────────────────────────────────────────

it('le quote: deliberate € 4.110,00; emesse € 4.110,00 = pagate € 3.700,00 + non pagate € 410,00 − crediti € 0,00; le non pagate sono lo scaduto di «Rate scadute» al 31/12', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $q = rpcLeggi($s)['quote']['totale'];
    $scaduto = array_sum(array_map(fn ($p) => $p['totali']['scaduto'], app(LetturaDebiti::class)->perCondominio((int) $s['c']->id, '2026-12-31')));

    expect($q)->toMatchArray(['deliberate' => 411000, 'emesse' => 411000, 'pagate' => 370000, 'non_pagate' => 41000, 'scadute' => 41000, 'crediti' => 0])
        ->and($q['scadute'])->toBe($scaduto)
        // La riga che le lega al registro: i versamenti dei condòmini del registro di contabilità, dal registro.
        ->and($q['versamenti_registro'])->toBe(370000);
});

it('Ugo paga € 30,00 in più sulla rata 3 (dalla rotta): crediti sulle rate € 30,00, pagate € 3.730,00, e la differenza con il netto di «Rate scadute» la dice la riga', function () {
    $s = vrmScenario($this, 3000);
    vrmGiorno($this, '2027-01-15');
    $q = rpcLeggi($s)['quote']['totale'];

    $lettura = app(LetturaDebiti::class)->perCondominio((int) $s['c']->id, '2026-12-31');
    $credito = array_sum(array_map(fn ($p) => (int) array_sum(array_column($p['rate'], 'credito')), $lettura));
    $residuo = array_sum(array_map(fn ($p) => (int) array_sum(array_column($p['rate'], 'residuo')), $lettura));

    expect($q)->toMatchArray(['emesse' => 411000, 'pagate' => 373000, 'non_pagate' => 41000, 'crediti' => 3000, 'netto' => 38000])
        ->and($q['emesse'])->toBe($q['pagate'] + $q['non_pagate'] - $q['crediti'])
        // La stessa lettura per persona, alla stessa data: una fonte sola per il non pagato e per il credito sulla rata.
        ->and([$q['non_pagate'], $q['crediti']])->toBe([$residuo, $credito]);
});

it('una rata del piano 2026 emessa dalla rotta con la data 31/12/2026 nel 2027 aperto, e una con la data 05/01/2027: la riga che lega le quote le dice, dalle due pagine', function () {
    // Un piano 2026 di dodici rate da € 100,00; le rate 1–10 emesse il 31/01/2026. Il 2026 si chiude, il 2027 si apre; il 05/01/2027 si emettono la rata 11
    // con la data del 31/12/2026 e la rata 12 con quella del giorno.
    $this->travelTo('2026-01-10 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 12, 'prima_rata', [], 120000);
    lcdEmetti($this, $s, range(1, 10), '2026-01-31');
    $e2027 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    legaAEsercizio($e2027, $s['g']->id);
    DB::table('esercizi')->where('id', $s['e']->id)->update(['stato' => 'chiuso']);
    $this->travelTo(\Illuminate\Support\Carbon::parse('2027-01-05 10:00', 'Europe/Rome'));
    lcdEmetti($this, $s, [11], '2026-12-31');
    lcdEmetti($this, $s, [12], '2027-01-05');
    expect(DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'emissione_rata')->where('esercizio_id', $e2027->id)->count())->toBe(2);
    $this->travelTo(\Illuminate\Support\Carbon::parse('2027-01-15 10:00', 'Europe/Rome'));
    $r2026 = rpcLeggi($s);
    $r2027 = rpcLeggi($s, $e2027);

    expect($r2026['raccordo_quote'])->toMatchArray(['quote_piani' => 110000, 'quote_giornale' => 100000, 'piani_emesse_altrove' => 10000, 'altri_emesse_qui' => 0, 'altre_differenze' => 0])
        ->and($r2027['raccordo_quote'])->toMatchArray(['quote_piani' => 0, 'quote_giornale' => 20000, 'piani_emesse_altrove' => 0, 'altri_emesse_qui' => 20000, 'altre_differenze' => 0]);
});

it('il credito riportato di Ugo (rata 0) sta nella riga degli esercizi precedenti, non fra le quote dell\'anno: le une e l\'altra sommano', function () {
    // Il caso della lettura comune: credito riportato 20000 (rata 0), rate da 10000; emesse le rate 1 e 2 il 28/02; il 10/03 Ugo usa 5000 del
    // credito sulla rata 1; il 15/03 si emette la rata 0, che porta in avere 15000.
    $this->travelTo('2026-03-20 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 12, 'rata_zero', [['ugo', 'A', -20000]]);
    lcdEmetti($this, $s, [1, 2], '2026-02-28');
    lcdIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 0) => -5000, lcdQuota($s, 'ugo', 1) => 5000], '2026-03-10');
    lcdEmetti($this, $s, [0], '2026-03-15');
    $r = rpcLeggi($s);
    $righe = collect($r['blocchi'][0]['quote'])->keyBy('riga');

    // Il credito riportato non ha chiuso niente: non è «pagate», è un termine suo. Pagate è solo la compensazione di € 50,00 sulla rata 1.
    expect($righe['ordinarie'])->toMatchArray(['emesse' => 20000, 'pagate' => 5000, 'non_pagate' => 15000, 'crediti' => 0, 'credito_riportato' => 0])
        ->and($righe['esercizi_precedenti'])->toMatchArray(['emesse' => 0, 'pagate' => 0, 'non_pagate' => 0, 'crediti' => 15000, 'credito_riportato' => 15000, 'deliberate' => null])
        ->and($r['quote']['totale'])->toMatchArray(['emesse' => 20000, 'pagate' => 5000, 'non_pagate' => 15000, 'crediti' => 15000, 'credito_riportato' => 15000, 'netto' => 0])
        ->and($r['quote']['totale']['emesse'])->toBe($r['quote']['totale']['pagate'] + $r['quote']['totale']['non_pagate'] - $r['quote']['totale']['crediti'] + $r['quote']['totale']['credito_riportato']);
});

it('il credito riportato emesso con la rata 0 e usato dopo, in parte e tutto: le pagate contano la compensazione, il credito riportato è quello non ancora usato', function (int $usato, int $riportato) {
    // Credito riportato di Ugo € 200,00 (rata 0), rate da € 100,00. La rata 0 si emette per prima il 20/02 (porta in avere il credito), le rate 1 e 2 il
    // 28/02; il 10/03 Ugo usa il credito sulle rate: € 50,00 sulla rata 1, o € 200,00 sulle rate 1 e 2.
    $this->travelTo('2026-02-20 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 12, 'rata_zero', [['ugo', 'A', -20000]]);
    lcdEmetti($this, $s, [0], '2026-02-20');
    lcdEmetti($this, $s, [1, 2], '2026-02-28');
    $this->travelTo('2026-03-10 10:00:00');
    $righe = [lcdQuota($s, 'ugo', 0) => -$usato, lcdQuota($s, 'ugo', 1) => min($usato, 10000)];
    if ($usato > 10000) {
        $righe[lcdQuota($s, 'ugo', 2)] = $usato - 10000;
    }
    lcdIncassa($this, $s, 'ugo', $righe, '2026-03-10');
    $this->travelTo('2026-03-20 10:00:00');
    $r = rpcLeggi($s);
    $t = $r['quote']['totale'];
    $precedenti = collect($r['blocchi'][0]['quote'])->firstWhere('riga', 'esercizi_precedenti');

    // Pagate = versate o compensate: la compensazione con il credito chiude le quote. Il credito riportato è quello non ancora usato: la frase della pagina
    // («non ha ancora chiuso nessuna quota») vale per lui, e con il credito usato tutto non c'è.
    expect($t['pagate'])->toBe($usato)
        ->and($t['credito_riportato'])->toBe($riportato)
        ->and($precedenti['pagate'] ?? 0)->toBe(0)
        ->and($t['emesse'])->toBe($t['pagate'] + $t['non_pagate'] - $t['crediti'] + $t['credito_riportato']);
})->with(['in parte' => [5000, 15000], 'tutto' => [20000, 0]]);

// ─── Il preventivo e il deliberato ─────────────────────────────────────────────────────────────────────────────────────

it('prima di tutto: la somma delle righe del riparto per voce è l\'importo chiesto dal piano, per una voce di spesa e per una di entrata', function () {
    $s = vrmBase($this);
    $somma = fn (int $voce) => (int) DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $voce)->sum('importo');

    expect($somma($s['pulizie']->id))->toBe(300000)->and($somma($s['antenna']->id))->toBe(-24000)
        ->and((int) DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->sum('importo'))->toBe(411000);
});

it('«Pulizie» preventivo € 3.000,00, chiesta dal piano per € 2.700,00: prima del 2027 le colonne dicono 3.000,00 e 2.700,00; dopo, «non conservato» e 2.700,00', function () {
    $s = vrmBase($this);
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $s['pulizie']->id)->update(['importo' => 270000]);
    // Il piano è emesso: per rigenerarlo con la voce più bassa lo si fa su un piano nuovo, approvato, come chiede il programma.
    $piano = be52PianoConVoci($this, $s, [$s['pulizie']->id], 'Pulizie ridotte');
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->where('conto_id', $s['pulizie']->id)->update(['importo' => 270000]);
    be52Ricalcola($this, $s, $piano)->assertSessionHasNoErrors();
    DB::table('piani_rate')->where('id', $s['piano']->id)->delete();
    $this->actingAs($this->user)->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $piano]), ['approvato' => true, 'data_delibera_assemblea' => '2026-01-02'])->assertSessionHasNoErrors();
    $prima = rpcVoce(rpcLeggi($s), $s['pulizie']->id);

    $e2027 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    legaAEsercizio($e2027, $s['g']->id);
    DB::table('conti')->where('id', $s['pulizie']->id)->update(['importo' => 330000]);
    $dopo = rpcVoce(rpcLeggi($s), $s['pulizie']->id);

    expect([$prima['preventivo'], $prima['preventivo_stato'], $prima['deliberato'], $prima['deliberato_stato']])->toBe([300000, 'conservato', 270000, 'conservato'])
        ->and([$dopo['preventivo'], $dopo['preventivo_stato'], $dopo['deliberato'], $dopo['deliberato_stato']])->toBe([null, 'non_conservato', 270000, 'conservato']);
});

it('due piani dello stesso esercizio che chiedono € 1.500,00 ciascuno sulla stessa voce: deliberato € 3.000,00', function () {
    $s = vrmBase($this);
    DB::table('piani_rate')->where('id', $s['piano']->id)->delete();
    foreach (['Primo semestre', 'Secondo semestre'] as $nome) {
        $piano = be52PianoConVoci($this, $s, [$s['pulizie']->id], $nome);
        DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->where('conto_id', $s['pulizie']->id)->update(['importo' => 150000]);
        be52Ricalcola($this, $s, $piano)->assertSessionHasNoErrors();
        $this->actingAs($this->user)->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $piano]), ['approvato' => true, 'data_delibera_assemblea' => '2026-01-02'])->assertSessionHasNoErrors();
    }

    expect(rpcVoce(rpcLeggi($s), $s['pulizie']->id)['deliberato'])->toBe(300000);
});

it('un piano generato prima del 14/09/2026, senza le righe del riparto, che con i dati di oggi non torna più: il deliberato è nello stato «non_conservato»', function () {
    $s = vrmBase($this);
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    // Dalla 1.12.0-beta.6 un piano senza righe si ricostruisce, se torna per unità con le quote (Coda 557, `DeliberatoRicostruitoTest`): con i millesimi
    // cambiati dopo l'emissione non torna, e resta «non_conservato».
    DB::table('quote_tabella')->where('immobile_id', $s['unita']['A']->id)->update(['valore' => 600]);
    $v = rpcVoce(rpcLeggi($s), $s['pulizie']->id);

    expect([$v['deliberato'], $v['deliberato_stato'], $v['scostamento']])->toBe([null, 'non_conservato', null])
        ->and(rpcLeggi($s)['quote']['totale']['deliberate'])->toBeNull();
});

it('la frase che dice perché il deliberato non c\'è esce quando almeno una gestione ha un piano senza le righe del riparto, e non esce quando tutte le hanno', function () {
    $s = vrmBase($this);
    $frase = 'Il deliberato con le rate non è disponibile per i piani generati prima che il programma ne conservasse il dettaglio: è nel verbale dell\'assemblea che li ha approvati.';
    // Una seconda gestione, con il suo piano approvato.
    $altra = \App\Models\Gestione::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Riscaldamento', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($s['e'], $altra->id);
    $piano2 = \App\Models\Gestionale\PianoRate::create(['gestione_id' => $altra->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Riscaldamento', 'stato' => 'approvato',
        'tipo' => 'ordinario', 'numero_rate' => 1, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-02-05', 'metodo_distribuzione' => 'prima_rata']);
    $copiaLeRighe = fn () => DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->get()
        ->each(fn ($r) => DB::table('righe_riparto')->insert(collect((array) $r)->except('id')->merge(['piano_rate_id' => $piano2->id])->all()));
    $righe1 = DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->get();

    // Il piano della seconda gestione non ha le righe: basta lei.
    expect($righe1->count())->toBeGreaterThan(0)
        ->and(rpcLeggi($s)['avvertenze']['deliberato'])->toBe($frase);
    // Tutte e due le hanno: nessuna frase.
    $copiaLeRighe();
    expect(rpcLeggi($s)['avvertenze']['deliberato'])->toBeNull();
    // Ora manca la prima, e la seconda c'è. Dalla 1.12.0-beta.6 la prima si ricostruisce (Coda 557): la frase del «non disponibile» non esce, esce quella
    // della ricostruzione; con i millesimi cambiati dopo l'emissione non torna più, e la frase esce.
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    expect(rpcLeggi($s)['avvertenze']['deliberato'])->toBeNull()
        ->and(rpcLeggi($s)['avvertenze']['deliberato_ricostruito'])->not->toBeNull();
    DB::table('quote_tabella')->where('immobile_id', $s['unita']['A']->id)->update(['valore' => 600]);
    expect(rpcLeggi($s)['avvertenze']['deliberato'])->toBe($frase);
    // Nessuna delle due.
    DB::table('righe_riparto')->where('piano_rate_id', $piano2->id)->delete();
    expect(rpcLeggi($s)['avvertenze']['deliberato'])->toBe($frase);
    // Una gestione senza nessun piano approvato non è «prima che il programma conservasse il dettaglio»: la frase non c'è.
    DB::table('piani_rate')->whereIn('id', [$s['piano']->id, $piano2->id])->update(['stato' => 'bozza']);
    expect(rpcLeggi($s)['avvertenze']['deliberato'])->toBeNull();
});

it('una gestione straordinaria: solo il deliberato, nessuno scostamento, e la colonna lo dice', function () {
    $s = vrmBase($this);
    DB::table('gestioni')->where('id', $s['g']->id)->update(['tipo' => 'straordinaria']);
    $v = rpcVoce(rpcLeggi($s), $s['pulizie']->id);

    expect([$v['preventivo'], $v['preventivo_stato'], $v['scostamento']])->toBe([null, 'solo_deliberato', null])
        ->and($v['deliberato'])->toBe(300000);
});

// ─── L'anno prima ──────────────────────────────────────────────────────────────────────────────────────────────────────

it('l\'anno prima: lo speso 2025 delle stesse voci (la gestione è riusata); un primo esercizio dice «primo esercizio nel programma»', function () {
    $s = vrmBase($this);
    $primo = rpcLeggi($s);
    $e2025 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'chiuso']);
    legaAEsercizio($e2025, $s['g']->id);
    vrmGiorno($this, '2026-03-10');
    $vecchia = vrmFattura($this, $s, $s['fornitore'], 'FT-2025', [[$s['pulizie']->id, 2750.00]], '2026-03-10');
    $sc = DB::table('fattura_scrittura')->where('fattura_passiva_id', $vecchia->id)->where('tipo', 'competenza')->value('scrittura_contabile_id');
    DB::table('scritture_contabili')->where('id', $sc)->update(['esercizio_id' => $e2025->id, 'data_competenza' => '2025-11-10']);
    $r = rpcLeggi($s);

    expect($primo['anno_prima'])->toBeNull()->and($primo['avvertenze']['anno_prima'])->toBe('primo esercizio nel programma')
        ->and($r['anno_prima'])->toMatchArray(['id' => $e2025->id, 'nome' => 'Esercizio 2025'])
        ->and(rpcVoce($r, $s['pulizie']->id)['anno_prima'])->toBe(275000)
        ->and(rpcVoce($r, $s['manutenzioni']->id)['anno_prima'])->toBe(0);
});

// ─── Le spese che stanno su un capitolo o su una voce di entrata, e la riga che riconcilia ───────────────────────────────────

/** Una voce o un capitolo dal modulo (rotta store), come fa l'amministratore. */
function rpcConto($test, array $s, string $nome, ?int $padre, bool $capitolo, string $importo = '0,00'): \App\Models\Gestionale\Conto
{
    $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$s['c'], $s['e'], $s['pc']]), [
        'nome' => $nome, 'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => 'spesa', 'importo' => $importo, 'descrizione' => '', 'note' => '',
        'parent_id' => $padre, 'tabella_millesimale_id' => $capitolo ? null : $s['tabella']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => $capitolo, 'isSottoConto' => $padre !== null, 'richiedeGiaVersato' => false,
    ])->assertSessionHasNoErrors();

    return \App\Models\Gestionale\Conto::where('piano_conto_id', $s['pc']->id)->where('nome', $nome)->firstOrFail();
}

function rpcCondominio($test): array
{
    vrmGiorno($test, '2026-01-02');
    $s = be52Condominio();
    be52Unita($s, 'A', 'ugo', $s['tabella'], 1000.0);
    $s['pc'] = \App\Models\Gestionale\PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $s['g']->id, 'nome' => 'Piano dei conti']);
    $s['fornitore'] = be52Fornitore();

    return $s;
}

it('una voce con una fattura diventata capitolo, con una sottovoce: la spesa scritta sul capitolo sta nella sua riga, nel consuntivo e nel da pagare', function () {
    $s = rpcCondominio($this);
    $pulizie = rpcConto($this, $s, 'Pulizie', null, false, '1.000,00');
    vrmGiorno($this, '2026-03-10');
    vrmFattura($this, $s, $s['fornitore'], 'FT-P', [[$pulizie->id, 600.00]], '2026-03-10');
    $this->actingAs($this->user)->put(route('admin.gestionale.esercizi.piani-conti.conti.update', [$s['c'], $s['e'], $s['pc'], $pulizie]), [
        'nome' => 'Pulizie', 'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => 'spesa', 'importo' => null, 'descrizione' => '', 'note' => '',
        'parent_id' => null, 'tabella_millesimale_id' => null, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => true, 'isSottoConto' => false, 'richiedeGiaVersato' => false, 'confermaConversioneCapitolo' => true,
    ])->assertSessionHasNoErrors();
    expect((bool) $pulizie->fresh()->is_capitolo)->toBeTrue();
    $scale = rpcConto($this, $s, 'Scale', $pulizie->id, false, '400,00');
    vrmGiorno($this, '2026-05-10');
    vrmFattura($this, $s, $s['fornitore'], 'FT-S', [[$scale->id, 200.00]], '2026-05-10');
    vrmGiorno($this, '2027-01-15');
    $r = rpcLeggi($s);
    $capitolo = collect($r['blocchi'][0]['spese'])->firstWhere('voce_id', $pulizie->id);
    $proprio = collect($capitolo['voci'])->firstWhere('proprio', true);

    expect(rpcColonne($capitolo))->toBe([80000, 0, 80000])
        ->and($proprio)->toMatchArray(['nome' => 'Scritto sul capitolo', 'consuntivo' => 60000, 'da_pagare' => 60000])
        ->and(collect($proprio['dettaglio'])->pluck('numero')->all())->toBe(['FT-P'])
        ->and($r['totali']['da_pagare'])->toBe(80000)
        ->and($r['riconciliazione'])->toMatchArray(['costi' => 80000, 'capitoli_netti' => 80000, 'differenza' => 0]);
});

it('un capitolo con due sottovoci: le cifre del capitolo sono le somme delle voci, il pagato diviso in proporzione', function () {
    $s = rpcCondominio($this);
    $impianti = rpcConto($this, $s, 'Impianti', null, true);
    $ascensore = rpcConto($this, $s, 'Ascensore', $impianti->id, false, '500,00');
    $citofono = rpcConto($this, $s, 'Citofono', $impianti->id, false, '300,00');
    vrmGiorno($this, '2026-04-10');
    $ft = vrmFattura($this, $s, $s['fornitore'], 'FT-X', [[$ascensore->id, 400.00], [$citofono->id, 100.00]], '2026-04-10');
    vrmGiorno($this, '2026-04-11');
    vrmPaga($this, $s, $s['fornitore'], [[$ft, 'pagamento', 25000]], '2026-04-11');
    vrmGiorno($this, '2027-01-15');
    $capitolo = collect(rpcLeggi($s)['blocchi'][0]['spese'])->firstWhere('voce_id', $impianti->id);

    expect(rpcColonne($capitolo, ['preventivo', 'consuntivo', 'pagato', 'da_pagare']))->toBe([80000, 50000, 25000, 25000])
        ->and(array_map(fn ($v) => [$v['nome'], $v['preventivo'], $v['consuntivo'], $v['pagato'], $v['da_pagare']], $capitolo['voci']))
        ->toBe([['Ascensore', 50000, 40000, 20000, 20000], ['Citofono', 30000, 10000, 5000, 5000]]);
});

it('una voce di entrata con una fattura di prima (€ 500,00, non pagata) e l\'incasso dell\'antenna: le spese sulla voce si vedono, e il da pagare le conta', function () {
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-07 10:00', 'Europe/Rome'));
    $s = be52Scenario($this);
    app(\App\Actions\Cassa\RegistraAperturaCassaAction::class)->execute($s['cassa']->fresh());
    $this->actingAs($this->user)->post(route('admin.gestionale.incassi-da-terzi.store', [$s['c']]), [
        'esercizio_id' => $s['e']->id, 'conto_id' => $s['entrata']->id, 'cassa_id' => $s['cassa']->id, 'data_operazione' => '2026-02-15',
        'importo' => 240.00, 'anagrafica_id' => null, 'causale' => 'Affitto antenna 2026',
    ])->assertSessionHasNoErrors();
    // La fattura scritta su una voce di entrata, come la registrazione la accettava prima.
    be52FatturaDalServizio($s, be52Fornitore(), $s['entrata']->id, 500.00, 'FT-VECCHIA-ENTRATA');
    $r = rpcLeggi($s);
    $antenna = rpcVoce($r, $s['entrata']->id);

    expect(rpcColonne($antenna, ['incassato', 'consuntivo', 'pagato', 'da_pagare']))->toBe([24000, 50000, 0, 50000])
        ->and(collect($antenna['dettaglio'])->pluck('numero')->all())->toBe(['FT-VECCHIA-ENTRATA'])
        ->and($r['totali']['da_pagare'])->toBe(50000)
        ->and($r['riconciliazione'])->toMatchArray(['costi' => 26000, 'capitoli_netti' => 26000, 'differenza' => 0]);
});

it('una voce di una gestione non agganciata all\'esercizio, con una riga nell\'esercizio: non sta in nessuna riga, e la riga che riconcilia la dice come differenza', function () {
    $s = vrmScenario($this);
    $altra = \App\Models\Gestione::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Gestione staccata', 'tipo' => 'ordinaria']);
    $pc = \App\Models\Gestionale\PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $altra->id, 'nome' => 'Piano staccato']);
    $voce = \App\Models\Gestionale\Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Bolli', 'tipo' => 'spesa', 'importo' => 0, 'conto_contabile_id' => $s['bancarie']->conto_contabile_id]);
    // La regolazione di € 96,00 portata su quella voce.
    $sc = DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('causale', 'Canone e bolli')->value('id');
    DB::table('righe_scritture')->where('scrittura_id', $sc)->where('voce_spesa_id', $s['bancarie']->id)->update(['voce_spesa_id' => $voce->id]);
    vrmGiorno($this, '2027-01-15');
    $r = rpcLeggi($s);

    expect(rpcVoce($r, $voce->id))->toBeNull()
        ->and($r['riconciliazione'])->toMatchArray(['costi' => 413100, 'capitoli_netti' => 388250, 'differenza' => 9600]);
});

// ─── Il pagato in più, il perimetro, le date ────────────────────────────────────────────────────────────────────────────

it('la FT-2 pagata oltre il netto (dalla rotta, con il pagamento in più permesso): la ritenuta resta € 40,00, il da pagare non scende sotto zero e il pagato in più sta a parte', function () {
    $s = vrmBase($this);
    vrmGiorno($this, '2026-04-10');
    $ft2 = vrmFattura($this, $s, $s['idraulico'], 'FT-2', [[$s['manutenzioni']->id, 1000.00, 22]], '2026-04-10');
    vrmGiorno($this, '2026-11-10');
    $this->actingAs($this->user)->post(route('admin.gestionale.pagamenti-fornitori.store', [$s['c']]), [
        'fornitore_id' => $s['idraulico']->id, 'esercizio_id' => $s['e']->id, 'conto_corrente_id' => $s['cassa']->conto_contabile_id, 'data_pagamento' => '2026-11-10',
        'metodo_pagamento' => 'bonifico', 'iban_beneficiario' => 'IT60X0542811101000000123456', 'importo_commissioni_cents' => 0, 'bonifico_parlante' => false,
        'allow_overpayment' => true, 'nota_override' => 'pagato di più', 'allow_overdraft' => true, 'iban_confermato_manualmente' => true, 'conferma_duplicato_verificato' => true,
        'allocazioni' => [['fattura_id' => $ft2->id, 'tipo' => 'pagamento', 'importo_allocato_cents' => 130000]],
    ])->assertSessionHasNoErrors();
    $p = \App\Models\Gestionale\PagamentoFornitore::where('condominio_id', $s['c']->id)->latest('id')->firstOrFail();
    vrmGiorno($this, '2026-12-16');
    vrmVersaF24($s, '2026-12-16');
    // Un'altra fattura aperta, su un'altra voce: il pagato in più della FT-2 non la riduce.
    vrmFattura($this, $s, $s['fornitore'], 'FT-A', [[$s['pulizie']->id, 200.00]], '2026-12-16');
    vrmGiorno($this, '2027-01-15');
    $r = rpcLeggi($s);

    expect((int) $p->importo_ritenuta)->toBe(4000)
        ->and(rpcColonne(rpcVoce($r, $s['manutenzioni']->id), ['consuntivo', 'pagato', 'da_pagare', 'ritenute_da_versare', 'pagato_in_piu']))->toBe([122000, 134000, 0, 0, 12000])
        ->and($r['totali'])->toMatchArray(['da_pagare' => 20000, 'pagato_in_piu' => 12000])
        // La stessa regola per la fattura, nella funzione del pagato alla data (che la situazione patrimoniale leggerà con tutte le fatture).
        ->and(array_intersect_key(app(\App\Services\Gestionale\PagatoAllaData::class)->perFatture((int) $s['c']->id, '2026-12-31', (int) $s['e']->id)[$ft2->id], array_flip(['totale', 'pagato', 'da_pagare', 'pagato_in_piu'])))
        ->toBe(['totale' => 122000, 'pagato' => 134000, 'da_pagare' => 0, 'pagato_in_piu' => 12000]);
});

it('la fattura del 2025 pagata in parte nel 2026: il riepilogo del 2026 non la vede (il perimetro è l\'esercizio della fattura)', function () {
    $s = vrmScenario($this);
    $e2025 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'chiuso']);
    vrmGiorno($this, '2026-12-20');
    $vecchia = vrmFattura($this, $s, $s['fornitore'], 'FT-2025', [[$s['pulizie']->id, 300.00]], '2026-12-20');
    $sc = DB::table('fattura_scrittura')->where('fattura_passiva_id', $vecchia->id)->where('tipo', 'competenza')->value('scrittura_contabile_id');
    DB::table('scritture_contabili')->where('id', $sc)->update(['esercizio_id' => $e2025->id, 'data_competenza' => '2025-12-10']);
    DB::table('fatture_passive')->where('id', $vecchia->id)->update(['esercizio_id' => $e2025->id]);
    vrmPaga($this, $s, $s['fornitore'], [[$vecchia, 'pagamento', 10000]], '2026-12-20');
    vrmGiorno($this, '2027-01-15');
    $r = rpcLeggi($s);

    expect(rpcColonne(rpcVoce($r, $s['pulizie']->id)))->toBe([290000, 240000, 50000])
        ->and(collect(rpcVoce($r, $s['pulizie']->id)['dettaglio'])->pluck('numero')->all())->toBe(['FT-1'])
        ->and($r['totali']['da_pagare'])->toBe(65000);
});

it('le quote alla data: Bice paga i suoi € 410,00 il 20/01/2027, e il riepilogo del 2026 letto il 15/02 dice ancora non pagate € 410,00', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-20');
    $bice = vrmQuote($s, 'bice');
    vrmIncassa($this, $s, 'bice', [$bice[10] => 6750, $bice[11] => 17125, $bice[12] => 17125], '2027-01-20');
    vrmGiorno($this, '2027-02-15');

    expect(rpcLeggi($s)['quote']['totale'])->toMatchArray(['emesse' => 411000, 'pagate' => 370000, 'non_pagate' => 41000, 'scadute' => 41000]);
});

it('un\'emissione cancellata: le sue quote non sono emesse, né le loro pagate', function () {
    $s = vrmScenario($this);
    // La rata 12 (€ 171,25 a testa): Ugo l'ha pagata il 31/03, Bice no.
    $rata = DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('numero_rata', 12)->value('id');
    $em = DB::table('rate_quote')->where('rata_id', $rata)->value('scrittura_contabile_id');
    DB::table('scritture_contabili')->where('id', $em)->update(['deleted_at' => now()]);
    vrmGiorno($this, '2027-01-15');

    expect(rpcLeggi($s)['quote']['totale'])->toMatchArray(['emesse' => 376750, 'pagate' => 352875, 'non_pagate' => 23875]);
});

it('una scrittura cancellata non conta: l\'incasso di Bice cancellato toglie le sue pagate e torna fra le non pagate', function () {
    $s = vrmScenario($this);
    DB::table('scritture_contabili')->where('id', $s['incasso_bice'])->update(['deleted_at' => now()]);
    vrmGiorno($this, '2027-01-15');

    expect(rpcLeggi($s)['quote']['totale'])->toMatchArray(['emesse' => 411000, 'pagate' => 205500, 'non_pagate' => 205500]);
});

it('il cumulato dell\'opera di una gestione straordinaria su due esercizi: le spese dal suo inizio', function () {
    $s = vrmBase($this);
    DB::table('gestioni')->where('id', $s['g']->id)->update(['tipo' => 'straordinaria']);
    $e2025 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'chiuso']);
    legaAEsercizio($e2025, $s['g']->id);
    vrmGiorno($this, '2026-03-10');
    $vecchia = vrmFattura($this, $s, $s['fornitore'], 'FT-2025', [[$s['pulizie']->id, 2750.00]], '2026-03-10');
    $sc = DB::table('fattura_scrittura')->where('fattura_passiva_id', $vecchia->id)->where('tipo', 'competenza')->value('scrittura_contabile_id');
    DB::table('scritture_contabili')->where('id', $sc)->update(['esercizio_id' => $e2025->id, 'data_competenza' => '2025-11-10']);
    vrmFattura($this, $s, $s['fornitore'], 'FT-2026', [[$s['pulizie']->id, 1000.00]], '2026-03-10');

    expect(rpcLeggi($s)['blocchi'][0]['cumulato_opera'])->toBe(['dal' => '2025-01-01', 'consuntivo' => 375000]);
});

it('l\'anno prima con due esercizi prima è il più vicino', function () {
    $s = vrmBase($this);
    foreach ([['Esercizio 2024', '2024', 99900], ['Esercizio 2025', '2025', 275000]] as [$nome, $anno, $cent]) {
        $e = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => $nome, 'data_inizio' => "{$anno}-01-01", 'data_fine' => "{$anno}-12-31", 'stato' => 'chiuso']);
        legaAEsercizio($e, $s['g']->id);
        vrmGiorno($this, '2026-03-10');
        $f = vrmFattura($this, $s, $s['fornitore'], "FT-{$anno}", [[$s['pulizie']->id, $cent / 100]], '2026-03-10');
        $sc = DB::table('fattura_scrittura')->where('fattura_passiva_id', $f->id)->where('tipo', 'competenza')->value('scrittura_contabile_id');
        DB::table('scritture_contabili')->where('id', $sc)->update(['esercizio_id' => $e->id, 'data_competenza' => "{$anno}-11-10"]);
    }
    $r = rpcLeggi($s);

    expect($r['anno_prima']['nome'])->toBe('Esercizio 2025')->and(rpcVoce($r, $s['pulizie']->id)['anno_prima'])->toBe(275000);
});

// ─── La pagina ─────────────────────────────────────────────────────────────────────────────────────────────────────────

it('la pagina del riepilogo dalla rotta, dietro il pannello e nel condominio giusto: i dati sono quelli del servizio', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $props = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.riepilogo', [$s['c'], $s['e']]))->assertOk()->viewData('page')['props'];

    expect($props['riepilogo'])->toEqual(rpcLeggi($s));

    $altro = be52Condominio();
    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.riepilogo', [$altro['c'], $s['e']]))->assertNotFound();
    $this->actingAs(\App\Models\User::factory()->create())->get(route('admin.gestionale.esercizi.rendiconto.riepilogo', [$s['c'], $s['e']]))->assertForbidden();
});
