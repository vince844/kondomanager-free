<?php

/**
 * La situazione patrimoniale alla data, con le fatture aperte: per l'esercizio aperto (a oggi) e per quello finito (alla data di fine), a video e in
 * stampa. Le fatture aperte sono quelle con la scrittura di competenza datata entro la data, di qualunque esercizio, con il residuo verso il fornitore
 * (al netto della ritenuta) letto dalle allocazioni di pagamento e compensazione datate entro la data: la stessa funzione del pagato del riepilogo
 * (`PagatoAllaData`), con il perimetro di tutte le fatture. La riga «fatture aperte + differenza = debiti verso fornitori» dice i nomi della differenza.
 * Sotto l'Erario, le ritenute delle fatture non ancora pagate; dentro i crediti, le spese anticipate per singole unità. Un blocco per gestione.
 *
 * Le cifre sono quelle del progetto (§ 3, § 5, test 14–16 e 19), fatte a mano. Centesimi.
 */

use App\Models\Esercizio;
use App\Services\Gestionale\PagatoAllaData;
use App\Services\Gestionale\StatoPatrimonialePaginaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;

require_once __DIR__.'/GestionaleTestHelpers.php';
require_once __DIR__.'/RendicontoViaRomaScenario.php';
require_once __DIR__.'/Beta59313AdPersonamScenari.php';
require_once __DIR__.'/LetturaDebitiProva.php';

beforeEach(function () {
    $this->user = be52Utente();
    // Le pagine si leggono dalla loro rotta: il test non dipende da una compilazione di Vite.
    $this->withoutVite();
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
});

function sadPagina(array $s, ?Esercizio $e = null): array
{
    return app(StatoPatrimonialePaginaService::class)->costruisci($s['c'], $e ?? $s['e']);
}

/** Le fatture aperte della pagina: [numero => residuo]. */
function sadAperte(array $p): array
{
    return collect($p['debiti']['fatture'])->mapWithKeys(fn ($f) => [$f['numero'] => $f['nota_credito'] ? -$f['residuo'] : $f['residuo']])->all();
}

/**
 * Via Roma con la FT-2 pagata in parte (o per niente): le stesse operazioni di `vrmScenario`, con il pagamento della FT-2 di `$pagatoFt2` centesimi
 * il 10/11 (zero: nessun pagamento) e, con `$f24`, l'F24 delle ritenute dei pagamenti fino al 16/12.
 */
function sadViaRoma($test, int $pagatoFt2, bool $f24): array
{
    $s = vrmBase($test);
    vrmGiorno($test, '2026-02-15');
    $test->actingAs($test->user)->post(route('admin.gestionale.incassi-da-terzi.store', [$s['c']]), [
        'esercizio_id' => $s['e']->id, 'conto_id' => $s['antenna']->id, 'cassa_id' => $s['cassa']->id, 'data_operazione' => '2026-02-15',
        'importo' => 240.00, 'anagrafica_id' => null, 'causale' => 'Affitto antenna 2026',
    ])->assertSessionHasNoErrors();
    vrmGiorno($test, '2026-03-31');
    vrmIncassa($test, $s, 'ugo', array_fill_keys(vrmQuote($s, 'ugo'), 17125), '2026-03-31');
    vrmGiorno($test, '2026-04-10');
    $s['ft1'] = vrmFattura($test, $s, $s['fornitore'], 'FT-1', [[$s['pulizie']->id, 2900.00]], '2026-04-10');
    $s['ft2'] = vrmFattura($test, $s, $s['idraulico'], 'FT-2', [[$s['manutenzioni']->id, 1000.00, 22]], '2026-04-10');
    $s['ft3'] = vrmFattura($test, $s, $s['fornitore'], 'FT-3', [[$s['manutenzioni']->id, 150.00]], '2026-04-10', ['stato_approvazione' => 'contestata']);
    if ($pagatoFt2 > 0) {
        vrmGiorno($test, '2026-11-10');
        $s['pag_ft2'] = vrmPaga($test, $s, $s['idraulico'], [[$s['ft2'], 'pagamento', $pagatoFt2]], '2026-11-10', 250, $s['bancarie']->id);
    }
    vrmGiorno($test, '2026-12-15');
    vrmPaga($test, $s, $s['fornitore'], [[$s['ft1'], 'pagamento', 240000]], '2026-12-15', 250, $s['bancarie']->id);
    if ($f24) {
        vrmGiorno($test, '2026-12-16');
        vrmVersaF24($s, '2026-12-16');
    }

    return $s;
}

// ─── Le fatture aperte alla data (test 14) ─────────────────────────────────────────────────────────────────────────────

it('una fattura da € 1.220,00 del 10/03 pagata € 500,00 il 15/08 e € 720,00 il 01/10: aperta per € 1.220,00 al 30/06, per € 720,00 al 31/08, chiusa al 31/12', function () {
    $s = vrmBase($this);
    vrmGiorno($this, '2026-03-10');
    $ft = vrmFattura($this, $s, $s['fornitore'], 'FT-313', [[$s['pulizie']->id, 1000.00, 22]], '2026-03-10');
    vrmGiorno($this, '2026-08-15');
    vrmPaga($this, $s, $s['fornitore'], [[$ft, 'pagamento', 50000]], '2026-08-15');
    vrmGiorno($this, '2026-10-01');
    vrmPaga($this, $s, $s['fornitore'], [[$ft, 'pagamento', 72000]], '2026-10-01');

    // La stessa funzione del pagato del riepilogo, con il perimetro di tutte le fatture alla data.
    $alla = fn (string $d) => collect(app(PagatoAllaData::class)->perFatture((int) $s['c']->id, $d))->filter(fn ($f) => $f['residuo'] !== 0)->pluck('residuo', 'numero')->all();
    expect($alla('2026-03-09'))->toBe([])
        ->and($alla('2026-06-30'))->toBe(['FT-313' => 122000])
        ->and($alla('2026-08-31'))->toBe(['FT-313' => 72000])
        ->and($alla('2026-12-31'))->toBe([]);

    // La pagina, il 30/06 e il 31/08 con l'esercizio aperto, e a febbraio con l'esercizio finito.
    vrmGiorno($this, '2026-06-30');
    $giugno = sadPagina($s);
    vrmGiorno($this, '2026-08-31');
    $agosto = sadPagina($s);
    vrmGiorno($this, '2027-02-10');
    $fine = sadPagina($s);
    expect(sadAperte($giugno))->toBe(['FT-313' => 122000])->and($giugno['debiti']['saldo_fornitori'])->toBe(122000)->and($giugno['debiti']['scarto'])->toBe(0)
        ->and(sadAperte($agosto))->toBe(['FT-313' => 72000])->and($agosto['debiti']['scarto'])->toBe(0)
        ->and($fine['data'])->toBe('2026-12-31')->and(sadAperte($fine))->toBe([])->and($fine['debiti']['saldo_fornitori'])->toBe(0);
});

it('un esercizio finito: l\'elenco al 31/12 c\'è, a video e in stampa, anche dopo che la FT-1 è stata pagata il 20/01/2027', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-20');
    vrmPaga($this, $s, $s['fornitore'], [[$s['ft1'], 'pagamento', 50000]], '2027-01-20');
    vrmGiorno($this, '2027-02-10');
    $p = sadPagina($s);

    expect($p['data'])->toBe('2026-12-31')
        ->and(sadAperte($p))->toBe(['FT-1' => 50000, 'FT-3' => 15000])
        ->and($p['debiti']['totale_residui'])->toBe(65000)
        ->and($p['debiti']['saldo_fornitori'])->toBe(65000)
        ->and($p['debiti']['scarto'])->toBe(0)
        ->and(collect($p['debiti']['fatture'])->firstWhere('numero', 'FT-3')['contestata'])->toBeTrue();

    $props = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.stato-patrimoniale.index', [$s['c'], $s['e']]))->assertOk()->viewData('page')['props'];
    expect($props['pagina']['debiti'])->toEqual($p['debiti']);

    $dati = null;
    View::composer('pdf.gestionale.stato_patrimoniale', function ($v) use (&$dati) {
        $dati = $v->getData();
    });
    $r = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.stato-patrimoniale.print', [$s['c'], $s['e']]))->assertOk();
    $html = view('pdf.gestionale.stato_patrimoniale', $dati)->render();
    expect(substr((string) $r->getContent(), 0, 4))->toBe('%PDF')
        ->and($dati['pagina']['debiti'])->toEqual($p['debiti'])
        ->and($html)->toContain('Fatture aperte al 31/12/2026')
        ->and($html)->toContain('FT-1')->and($html)->toContain('FT-3')->and($html)->toContain('contestata')
        ->and($html)->toContain('€ 650,00');
});

it('con una fattura del 2025 da € 300,00 ancora aperta e una pregressa non pagata da € 200,00: fatture aperte € 1.150,00, e la riga con i debiti torna', function () {
    $s = vrmScenario($this);
    be52MastriPregresso($s);
    $e2025 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'chiuso']);
    $vecchia = vrmFattura($this, $s, $s['fornitore'], 'FT-2025', [[$s['pulizie']->id, 300.00]], '2025-12-10');
    $sc = DB::table('fattura_scrittura')->where('fattura_passiva_id', $vecchia->id)->where('tipo', 'competenza')->value('scrittura_contabile_id');
    DB::table('scritture_contabili')->where('id', $sc)->update(['esercizio_id' => $e2025->id, 'data_competenza' => '2025-12-10']);
    DB::table('fatture_passive')->where('id', $vecchia->id)->update(['esercizio_id' => $e2025->id]);
    $debito = be52DebitoFornitore($s, $s['fornitore'], -20000);
    vrmGiorno($this, '2026-12-20');
    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.store', [$s['c']]), be52PayloadPregressa($s, $s['fornitore'], [
        'imponibile_pregresso' => 200.00, 'aliquota_iva_pregressa' => 0, 'saldo_patrimoniale_id' => $debito,
        'coperture' => [['tipo_copertura' => 'rata_0', 'importo' => 200.00, 'fonte_id' => $debito]],
    ], 'PREG-1'))->assertSessionHasNoErrors();
    vrmGiorno($this, '2027-01-15');
    $p = sadPagina($s);

    // Per scadenza, come l'elenco di sempre: la pregressa (31/12/2024), la fattura del 2025, poi quelle del 2026.
    expect(sadAperte($p))->toBe(['PREG-1' => 20000, 'FT-2025' => 30000, 'FT-1' => 50000, 'FT-3' => 15000])
        ->and($p['debiti']['totale_residui'])->toBe(115000)
        ->and($p['debiti']['saldo_fornitori'])->toBe(115000)
        ->and($p['debiti']['scarto'])->toBe(0)
        ->and($p['debiti']['scarto_voci'])->toBe([])
        ->and(collect($p['debiti']['fatture'])->firstWhere('numero', 'PREG-1')['pregressa'])->toBeTrue();
});

it('la parte ad personam di una fattura non pagata è un debito verso il fornitore come il resto: sta nella fattura aperta, e la differenza è zero', function () {
    $s = b59apBase();
    $ft = b59apFattura($this, $s, [b59apRigaAdPersonam($s)]);
    $this->travelTo('2026-03-20 10:00:00');
    $p = app(StatoPatrimonialePaginaService::class)->costruisci($s['c'], $s['e']);

    expect(sadAperte($p))->toBe(['FT-313' => 122000])
        ->and($p['debiti']['saldo_fornitori'])->toBe(122000)
        ->and($p['debiti']['scarto'])->toBe(0);
});

it('la FT-2 pagata oltre il netto: non è fra le fatture aperte, e la differenza con i debiti ha il suo nome, «pagato in più»', function () {
    $s = vrmFinoANovembre($this);
    vrmGiorno($this, '2026-11-20');
    // € 120,00 oltre il netto di € 1.180,00, con il pagamento in più permesso dalla rotta.
    $this->actingAs($this->user)->post(route('admin.gestionale.pagamenti-fornitori.store', [$s['c']]), [
        'fornitore_id' => $s['idraulico']->id, 'esercizio_id' => $s['e']->id, 'conto_corrente_id' => $s['cassa']->conto_contabile_id, 'data_pagamento' => '2026-11-20',
        'metodo_pagamento' => 'bonifico', 'iban_beneficiario' => 'IT60X0542811101000000123456', 'importo_commissioni_cents' => 0, 'bonifico_parlante' => false,
        'allow_overdraft' => true, 'allow_overpayment' => true, 'iban_confermato_manualmente' => true, 'conferma_duplicato_verificato' => true,
        'allocazioni' => [['fattura_id' => $s['ft2']->id, 'tipo' => 'pagamento', 'importo_allocato_cents' => 12000]],
    ])->assertSessionHasNoErrors();
    vrmGiorno($this, '2026-11-25');
    $p = sadPagina($s);

    expect(sadAperte($p))->toBe(['FT-1' => 290000, 'FT-3' => 15000])
        ->and($p['debiti']['saldo_fornitori'])->toBe(305000 - 12000)
        ->and($p['debiti']['scarto'])->toBe(-12000)
        ->and($p['debiti']['scarto_voci'])->toBe([['voce' => 'pagato in più a un fornitore', 'importo' => -12000, 'fatture' => ['FT-2']]]);
});

it('una fattura stornata entro la data non è aperta, e nemmeno la sua nota; stornata dopo la data, al 31/12 era aperta', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2026-12-20');
    $ft = vrmFattura($this, $s, $s['fornitore'], 'FT-S', [[$s['pulizie']->id, 100.00]], '2026-12-20');
    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.storno', [$s['c'], $ft]))->assertSessionHasNoErrors();
    // Una fattura con la ritenuta stornata: la ritenuta non è più sull'Erario, e non è fra quelle delle fatture non pagate.
    $ftR = vrmFattura($this, $s, $s['idraulico'], 'FT-R', [[$s['manutenzioni']->id, 100.00]], '2026-12-20');
    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.storno', [$s['c'], $ftR]))->assertSessionHasNoErrors();
    vrmGiorno($this, '2026-12-21');
    expect(sadAperte(sadPagina($s)))->toBe(['FT-1' => 50000, 'FT-3' => 15000])->and(sadPagina($s)['debiti']['scarto'])->toBe(0)
        ->and(sadPagina($s)['sintesi']['debiti_erario'])->toBe(0)
        ->and(sadPagina($s)['debiti']['erario_su_fatture_non_pagate'])->toBe(0);

    vrmGiorno($this, '2026-12-22');
    $ft2 = vrmFattura($this, $s, $s['fornitore'], 'FT-T', [[$s['pulizie']->id, 80.00]], '2026-12-22');
    vrmGiorno($this, '2027-01-12');
    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.storno', [$s['c'], $ft2]))->assertSessionHasNoErrors();
    vrmGiorno($this, '2027-02-01');
    $p = sadPagina($s);
    expect(sadAperte($p))->toBe(['FT-1' => 50000, 'FT-3' => 15000, 'FT-T' => 8000])->and($p['debiti']['saldo_fornitori'])->toBe(73000)->and($p['debiti']['scarto'])->toBe(0);
});

it('una nota di credito del fornitore non ancora usata sta fra le aperte con il segno meno, e la riga torna', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2026-12-20');
    vrmFattura($this, $s, $s['fornitore'], 'NC-9', [[$s['pulizie']->id, 30.00]], '2026-12-20', ['tipo_documento' => 'nota_credito']);
    vrmGiorno($this, '2027-01-15');
    $p = sadPagina($s);

    expect(sadAperte($p))->toBe(['FT-1' => 50000, 'FT-3' => 15000, 'NC-9' => -3000])
        ->and($p['debiti']['totale_residui'])->toBe(62000)
        ->and($p['debiti']['saldo_fornitori'])->toBe(62000)
        ->and($p['debiti']['scarto'])->toBe(0);
});

// ─── L'Erario (test 15) ────────────────────────────────────────────────────────────────────────────────────────────────

it('Via Roma con la FT-2 non pagata: Erario € 40,00 di cui € 40,00 su fatture non ancora pagate; debiti verso fornitori e fatture aperte € 1.830,00', function () {
    $s = sadViaRoma($this, 0, false);
    vrmGiorno($this, '2027-01-15');
    $p = sadPagina($s);

    expect($p['sintesi']['debiti_erario'])->toBe(4000)
        ->and($p['debiti']['erario_su_fatture_non_pagate'])->toBe(4000)
        ->and($p['sintesi']['debiti_fornitori'])->toBe(183000)
        ->and(sadAperte($p))->toBe(['FT-1' => 50000, 'FT-2' => 118000, 'FT-3' => 15000])
        ->and($p['debiti']['totale_residui'])->toBe(183000)
        ->and($p['debiti']['scarto'])->toBe(0);
});

it('la FT-2 pagata a metà (€ 590,00): di cui € 20,00 su fatture non ancora pagate, con l\'F24 della metà pagata versato o no', function () {
    $senza = sadViaRoma($this, 59000, false);
    vrmGiorno($this, '2027-01-15');
    $p = sadPagina($senza);
    expect($p['sintesi']['debiti_erario'])->toBe(4000)
        ->and($p['debiti']['erario_su_fatture_non_pagate'])->toBe(2000)
        ->and(sadAperte($p))->toBe(['FT-1' => 50000, 'FT-2' => 59000, 'FT-3' => 15000])
        ->and($p['debiti']['scarto'])->toBe(0);

    $con = sadViaRoma($this, 59000, true);
    vrmGiorno($this, '2027-01-15');
    $q = sadPagina($con);
    expect($q['sintesi']['debiti_erario'])->toBe(2000)
        ->and($q['debiti']['erario_su_fatture_non_pagate'])->toBe(2000);
});

it('Via Roma come il progetto: Erario € 0,00, niente su fatture non pagate', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $p = sadPagina($s);

    expect($p['sintesi']['debiti_erario'])->toBe(0)->and($p['debiti']['erario_su_fatture_non_pagate'])->toBe(0)
        ->and(sadAperte($p))->toBe(['FT-1' => 50000, 'FT-3' => 15000]);
});

// ─── Le anticipazioni (test 16) ────────────────────────────────────────────────────────────────────────────────────────

it('dopo la fattura ad personam di € 1.220,00: crediti € 1.220,00, di cui spese anticipate € 1.220,00; dopo le emissioni anticipate € 0,00', function () {
    $s = b59apBase();
    $ft = b59apFattura($this, $s, [b59apRigaAdPersonam($s)]);
    $this->travelTo('2026-03-20 10:00:00');
    $prima = app(StatoPatrimonialePaginaService::class)->costruisci($s['c'], $s['e']);
    expect($prima['sintesi']['crediti_condomini'])->toBe(122000)
        ->and($prima['sintesi']['spese_anticipate'])->toBe(122000);

    $piano = b59apPiano($this, $s, $ft);
    b59apEmetti($this, $s, $piano, [1, 2]);
    $this->travelTo('2026-04-05 10:00:00');
    $dopo = app(StatoPatrimonialePaginaService::class)->costruisci($s['c'], $s['e']);
    expect($dopo['sintesi']['crediti_condomini'])->toBe(122000)
        ->and($dopo['sintesi']['spese_anticipate'])->toBe(0);
});

// ─── La divisione per gestione (test 19) ───────────────────────────────────────────────────────────────────────────────

it('due blocchi, ordinaria e straordinaria, che sommano al totale; la straordinaria su due esercizi con il cumulato dell\'opera; una fattura con la voce di un\'altra gestione', function () {
    $s = vrmScenario($this);
    $e2025 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'chiuso']);
    $facciata = \App\Models\Gestione::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Facciata', 'tipo' => 'straordinaria', 'data_inizio' => '2025-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($e2025, $facciata->id);
    legaAEsercizio($s['e'], $facciata->id);
    $pcF = \App\Models\Gestionale\PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $facciata->id, 'nome' => 'Piano facciata']);
    $ponteggio = be52Voce($this, $s, $pcF, 'Ponteggio', 'spesa', '5.000,00');
    vrmGiorno($this, '2026-03-10');
    $f2025 = vrmFattura($this, $s, $s['fornitore'], 'FF-2025', [[$ponteggio->id, 2750.00]], '2026-03-10', ['gestione_id' => $facciata->id]);
    $sc = DB::table('fattura_scrittura')->where('fattura_passiva_id', $f2025->id)->where('tipo', 'competenza')->value('scrittura_contabile_id');
    DB::table('scritture_contabili')->where('id', $sc)->update(['esercizio_id' => $e2025->id, 'data_competenza' => '2025-11-10']);
    vrmFattura($this, $s, $s['fornitore'], 'FF-2026', [[$ponteggio->id, 1000.00]], '2026-03-10', ['gestione_id' => $facciata->id]);
    // La voce della facciata con la testata dell'ordinaria: il costo segue la voce, il debito la testata.
    vrmFattura($this, $s, $s['fornitore'], 'FX', [[$ponteggio->id, 200.00]], '2026-03-10');
    vrmGiorno($this, '2027-01-15');
    $p = sadPagina($s);
    $g = $p['per_gestione'];
    $blocco = fn (int $id) => collect($g['righe'])->first(fn ($r) => $r['gestione']['id'] === $id);
    $ord = $blocco((int) $s['g']->id);
    $str = $blocco((int) $facciata->id);

    expect([$ord['costi'], $ord['quote_emesse'], $ord['crediti'], $ord['debiti']])->toBe([412850, 411000, 41000, 85000])
        ->and([$str['costi'], $str['quote_emesse'], $str['crediti'], $str['debiti']])->toBe([120000, 0, 0, 375000])
        ->and($str['cumulato_opera'])->toBe(['dal' => '2025-01-01', 'consuntivo' => 395000])
        ->and($ord['cumulato_opera'])->toBeNull()
        ->and($g['senza_voce'])->toBe(250)
        ->and($g['totale'])->toBe(['costi' => 533100, 'quote_emesse' => 411000, 'crediti' => 41000, 'debiti' => 460000])
        ->and($g['totale']['costi'])->toBe($p['risultato_gestione']['costi'])
        ->and($g['totale']['crediti'])->toBe($p['sintesi']['crediti_condomini'])
        ->and($g['totale']['debiti'])->toBe($p['sintesi']['debiti_fornitori'])
        ->and($g['fatture_altra_gestione'])->toBe(['FX'])
        ->and($g['nota_liquidita'])->toContain('del condominio');
});

it('i crediti seguono la gestione del piano della rata, non quella dell\'incasso: un versamento che paga una rata di due gestioni', function () {
    $this->travelTo('2026-01-02 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 600], 'B' => ['bice', 400]], 12, 'prima_rata', [], 120000);
    lcdEmetti($this, $s, [1], '2026-01-02');
    $g2 = \App\Models\Gestione::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Riscaldamento 2026', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($s['e'], $g2->id);
    $pc = \App\Models\Gestionale\PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $g2->id, 'nome' => 'PC2']);
    $conto = \App\Models\Gestionale\Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Gasolio', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 20000]);
    $tabella = DB::table('tabelle')->where('condominio_id', $s['c']->id)->value('id');
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $tabella, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    $piano = \App\Models\Gestionale\PianoRate::create(['gestione_id' => $g2->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Riscaldamento 2026',
        'stato' => 'approvato', 'tipo' => 'ordinario', 'numero_rate' => 2, 'giorno_scadenza' => 10, 'data_prima_scadenza' => '2026-02-10', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => false]);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($piano, forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Prova', esercizio: $s['e']);
    lcdEmetti($this, ['piano' => $piano] + $s, [1], '2026-02-01');
    $this->travelTo('2026-03-20 10:00:00');
    // Ugo paga in un versamento solo la sua rata 1 dell'ordinaria (€ 60,00) e quella del riscaldamento (€ 60,00): la scrittura ha una gestione sola.
    $riscaldamento = (int) DB::table('rate_quote')->where('rata_id', lcdRata(['piano' => $piano] + $s, 1))->where('anagrafica_id', $s['persone']['ugo']->id)->value('id');
    lcdIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 1) => 6000, $riscaldamento => 6000], '2026-03-20');
    $g = app(StatoPatrimonialePaginaService::class)->costruisci($s['c'], $s['e'])['per_gestione'];
    $crediti = collect($g['righe'])->mapWithKeys(fn ($r) => [$r['gestione']['nome'] => $r['crediti']])->all();

    // Resta Bice: € 40,00 dell'ordinaria e € 40,00 del riscaldamento.
    expect($crediti)->toBe(['Ordinaria 2026' => 4000, 'Riscaldamento 2026' => 4000])
        ->and($g['totale']['crediti'])->toBe(8000);
});

// ─── I casi di confine della situazione alla data ──────────────────────────────────────────────────────────────────────

it('un esercizio finito resta alla sua data: la parte ad personam emessa dopo la fine è ancora fra le spese anticipate, e un incasso dopo la fine non tocca i crediti per gestione', function () {
    $s = b59apBase();
    $ft = b59apFattura($this, $s, [b59apRigaAdPersonam($s)]);
    $piano = b59apPiano($this, $s, $ft);
    $this->travelTo('2027-01-20 10:00:00');
    b59apEmetti($this, $s, $piano, [1, 2], '2027-01-10');
    $this->travelTo('2027-02-01 10:00:00');
    $p = app(StatoPatrimonialePaginaService::class)->costruisci($s['c'], $s['e']);
    $pos = app(\App\Services\Gestionale\PosizioniDeiCondomini::class)->posizioni($s['c'], $s['e']);

    // Al 31/12/2026 la fattura c'è e l'emissione del 10/01/2027 no.
    expect($p['data'])->toBe('2026-12-31')
        ->and($p['sintesi']['spese_anticipate'])->toBe(122000)
        ->and($p['sintesi']['crediti_condomini'])->toBe(122000)
        ->and($pos['riga_crediti'])->toBe(['crediti' => 122000, 'posizioni' => 0, 'spese_anticipate' => 122000, 'differenza' => 0]);

    $v = vrmScenario($this);
    vrmGiorno($this, '2027-01-20');
    $bice = vrmQuote($v, 'bice');
    vrmIncassa($this, $v, 'bice', [$bice[10] => 6750, $bice[11] => 17125, $bice[12] => 6125], '2027-01-20');
    vrmGiorno($this, '2027-02-15');
    $g = sadPagina($v)['per_gestione'];
    expect($g['righe'][0]['crediti'])->toBe(41000)->and($g['totale']['crediti'])->toBe(41000);
});

it('le righe cancellate non contano: una fattura ad personam cancellata non è fra le spese anticipate; un pagamento cancellato lascia la fattura aperta', function () {
    $s = b59apBase();
    $ft = b59apFattura($this, $s, [b59apRigaAdPersonam($s)]);
    DB::table('scritture_contabili')->where('id', DB::table('fattura_scrittura')->where('fattura_passiva_id', $ft->id)->where('tipo', 'competenza')->value('scrittura_contabile_id'))
        ->update(['deleted_at' => now()]);
    $this->travelTo('2026-03-20 10:00:00');
    expect(app(StatoPatrimonialePaginaService::class)->costruisci($s['c'], $s['e'])['sintesi']['spese_anticipate'])->toBe(0);

    // La FT-1 pagata tutta in due volte (€ 2.400,00 il 15/12, € 500,00 il 20/12); il primo pagamento cancellato.
    $v = vrmScenario($this);
    vrmGiorno($this, '2026-12-20');
    vrmPaga($this, $v, $v['fornitore'], [[$v['ft1'], 'pagamento', 50000]], '2026-12-20');
    DB::table('scritture_contabili')->where('id', $v['pag_ft1']->scrittura_contabile_id)->update(['deleted_at' => now()]);
    vrmGiorno($this, '2027-01-15');
    $p = sadPagina($v);
    expect(sadAperte($p))->toBe(['FT-1' => 240000, 'FT-3' => 15000])
        ->and($p['debiti']['saldo_fornitori'])->toBe(255000)
        ->and($p['debiti']['scarto'])->toBe(0);
});

it('una riga senza persona sugli anticipi non è una spesa anticipata per una unità', function () {
    $s = b59apBase();
    b59apFattura($this, $s, [b59apRigaAdPersonam($s)]);
    $anticipi = \App\Models\Gestionale\ContoContabile::where('condominio_id', $s['c']->id)->where('ruolo', 'anticipi_condomini')->firstOrFail();
    $sc = \App\Models\Gestionale\ScritturaContabile::create(['condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'gestione_id' => $s['g']->id,
        'data_registrazione' => '2026-03-15', 'data_competenza' => '2026-03-15', 'causale' => 'Versamento senza nome', 'tipo_movimento' => 'incasso_rata', 'stato' => 'registrata']);
    $sc->righe()->create(['conto_contabile_id' => $s['cassa']->conto_contabile_id, 'cassa_id' => $s['cassa']->id, 'tipo_riga' => 'dare', 'importo' => 7000]);
    $sc->righe()->create(['conto_contabile_id' => $anticipi->id, 'tipo_riga' => 'avere', 'importo' => 7000]);
    $this->travelTo('2026-03-20 10:00:00');

    expect(app(StatoPatrimonialePaginaService::class)->costruisci($s['c'], $s['e'])['sintesi']['spese_anticipate'])->toBe(122000);
});

it('una nota di credito usata oltre il suo importo (con il pagamento in più permesso): la differenza con i debiti ha il suo nome', function () {
    $s = vrmBase($this);
    vrmGiorno($this, '2026-04-10');
    $ft = vrmFattura($this, $s, $s['fornitore'], 'FT-X', [[$s['pulizie']->id, 1000.00]], '2026-04-10');
    $nc = vrmFattura($this, $s, $s['fornitore'], 'NC-X', [[$s['pulizie']->id, 100.00]], '2026-04-11', ['tipo_documento' => 'nota_credito']);
    vrmGiorno($this, '2026-05-10');
    // € 800,00 in banca e € 150,00 della nota da € 100,00.
    $this->actingAs($this->user)->post(route('admin.gestionale.pagamenti-fornitori.store', [$s['c']]), [
        'fornitore_id' => $s['fornitore']->id, 'esercizio_id' => $s['e']->id, 'conto_corrente_id' => $s['cassa']->conto_contabile_id, 'data_pagamento' => '2026-05-10',
        'metodo_pagamento' => 'bonifico', 'iban_beneficiario' => 'IT60X0542811101000000123456', 'importo_commissioni_cents' => 0, 'bonifico_parlante' => false,
        'allow_overdraft' => true, 'allow_overpayment' => true, 'iban_confermato_manualmente' => true, 'conferma_duplicato_verificato' => true,
        'allocazioni' => [['fattura_id' => $ft->id, 'tipo' => 'pagamento', 'importo_allocato_cents' => 80000], ['fattura_id' => $ft->id, 'tipo' => 'compensazione', 'importo_allocato_cents' => 15000],
            ['fattura_id' => $nc->id, 'tipo' => 'compensazione', 'importo_allocato_cents' => 15000]],
    ])->assertSessionHasNoErrors();
    vrmGiorno($this, '2026-05-20');
    $p = sadPagina($s);

    // La FT-X resta aperta per € 50,00; la nota ha dato € 50,00 più del suo importo, e il conto dei debiti li porta: 50 + 50 = 100.
    expect(sadAperte($p))->toBe(['FT-X' => 5000])
        ->and($p['debiti']['saldo_fornitori'])->toBe(10000)
        ->and($p['debiti']['scarto_voci'])->toBe([['voce' => 'note di credito usate oltre il loro importo', 'importo' => 5000, 'fatture' => ['NC-X']]]);
});

// ─── Le ritenute delle fatture non ancora pagate, con una nota di credito che ha la sua ritenuta ───────────────────────

/** La FT-R dell'idraulico (€ 1.000,00 + IVA 22 %, ritenuta € 40,00, netto € 1.180,00) e la sua nota NC-R (€ 200,00 + IVA, ritenuta € 8,00, netto € 236,00). */
function sadFatturaENota($test, array $s): array
{
    vrmGiorno($test, '2026-04-10');
    $ft = vrmFattura($test, $s, $s['idraulico'], 'FT-R', [[$s['manutenzioni']->id, 1000.00, 22]], '2026-04-10');
    $nc = vrmFattura($test, $s, $s['idraulico'], 'NC-R', [[$s['manutenzioni']->id, 200.00, 22]], '2026-04-11', ['tipo_documento' => 'nota_credito']);

    return [$ft->fresh(), $nc->fresh()];
}

function sadPagaConLaNota($test, array $s, $ft, $nc, int $contanti): void
{
    vrmGiorno($test, '2026-05-10');
    $test->actingAs($test->user)->post(route('admin.gestionale.pagamenti-fornitori.store', [$s['c']]), [
        'fornitore_id' => $s['idraulico']->id, 'esercizio_id' => $s['e']->id, 'conto_corrente_id' => $s['cassa']->conto_contabile_id, 'data_pagamento' => '2026-05-10',
        'metodo_pagamento' => 'bonifico', 'iban_beneficiario' => 'IT60X0542811101000000123456', 'importo_commissioni_cents' => 0, 'bonifico_parlante' => false,
        'allow_overdraft' => true, 'iban_confermato_manualmente' => true, 'conferma_duplicato_verificato' => true,
        'allocazioni' => array_values(array_filter([
            $contanti > 0 ? ['fattura_id' => $ft->id, 'tipo' => 'pagamento', 'importo_allocato_cents' => $contanti] : null,
            ['fattura_id' => $ft->id, 'tipo' => 'compensazione', 'importo_allocato_cents' => 23600],
            ['fattura_id' => $nc->id, 'tipo' => 'compensazione', 'importo_allocato_cents' => 23600],
        ])),
    ])->assertSessionHasNoErrors();
}

it('una nota di credito con la sua ritenuta: le ritenute «su fatture non ancora pagate» sono quelle della parte ancora aperta, mai più del debito verso l\'Erario', function () {
    // La nota toglie dall'Erario la sua ritenuta: € 40,00 − € 8,00 = € 32,00. Quando la FT-R sarà pagata, il pagamento tratterrà la ritenuta della parte
    // che paga: con la regola della quota, € 40,00 × parte / € 1.180,00.
    $s = vrmBase($this);
    [$ft, $nc] = sadFatturaENota($this, $s);
    expect([$ft->importo_ritenuta, $nc->importo_ritenuta, $nc->netto_a_pagare])->toBe([4000, -800, -23600]);
    vrmGiorno($this, '2026-05-20');
    $aperta = sadPagina($s);
    // La nota non ancora usata: la FT-R per intero (€ 40,00) meno la ritenuta della nota (€ 8,00).
    expect($aperta['sintesi']['debiti_erario'])->toBe(3200)->and($aperta['debiti']['erario_su_fatture_non_pagate'])->toBe(3200);

    $soloNota = vrmBase($this);
    [$ft, $nc] = sadFatturaENota($this, $soloNota);
    sadPagaConLaNota($this, $soloNota, $ft, $nc, 0);
    vrmGiorno($this, '2026-05-20');
    $p = sadPagina($soloNota);
    // La nota chiude € 236,00: aperti € 944,00, la loro ritenuta 40 × 944 / 1.180 = € 32,00.
    expect(sadAperte($p))->toBe(['FT-R' => 94400])
        ->and($p['sintesi']['debiti_erario'])->toBe(3200)->and($p['debiti']['erario_su_fatture_non_pagate'])->toBe(3200);

    $conContanti = vrmBase($this);
    [$ft, $nc] = sadFatturaENota($this, $conContanti);
    sadPagaConLaNota($this, $conContanti, $ft, $nc, 50000);
    vrmGiorno($this, '2026-05-20');
    $q = sadPagina($conContanti);
    // € 500,00 in banca (trattenuti 40 × 500 / 1.180 = € 16,95) e € 236,00 della nota: aperti € 444,00, la loro ritenuta 40 × 444 / 1.180 = € 15,05;
    // l'Erario € 32,00 = € 16,95 trattenuti + € 15,05 della parte non pagata.
    expect(sadAperte($q))->toBe(['FT-R' => 44400])
        ->and($q['sintesi']['debiti_erario'])->toBe(3200)->and($q['debiti']['erario_su_fatture_non_pagate'])->toBe(1505);
});
