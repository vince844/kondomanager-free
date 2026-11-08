<?php

/**
 * Il registro di contabilità con il riporto: la cassa all'inizio dell'esercizio è la colonna `saldo_iniziale` (l'apertura non ancora a giornale) più
 * le righe di cassa delle scritture degli esercizi che cominciano prima; se l'esercizio ha la sua scrittura di apertura su quella cassa, quella è il
 * riporto e gli esercizi prima non si sommano. L'apertura non è un'entrata. La stessa regola dà la disponibilità iniziale del prospetto per cassa, in
 * due forme: per cassa reale con i fondi dentro (il registro), per cassa (il prospetto); il totale è lo stesso.
 *
 * Poi i numeri dei documenti pagati nella descrizione, i totali per tipo, il saldo a fine mese, e il nome del file. Le cifre sono del progetto
 * («Via Roma» e il caso dell'anno a cavallo), fatte a mano. Centesimi.
 */

use App\Models\Esercizio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\ContoContabile;
use App\Services\Gestionale\RegistroContabilitaService;
use App\Services\Gestionale\RiepilogoFinanziarioService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/RendicontoViaRomaScenario.php';
require_once __DIR__.'/LetturaDebitiProva.php';

beforeEach(function () {
    $this->user = be52Utente();
    // Le pagine si leggono dalla loro rotta: il test non dipende da una compilazione di Vite.
    $this->withoutVite();
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
});

function rcrRegistro($test, array $s, Esercizio $e): array
{
    return $test->actingAs($test->user)->get(route('admin.gestionale.esercizi.registro-contabilita.index', [$s['c'], $e]))->assertOk()->viewData('page')['props']['riepilogo'];
}

/** Una scrittura di cassa scritta a mano, con la riga di cassa che porta la cassa (come la scrivono le azioni). */
function rcrScrittura(array $s, Esercizio $e, string $data, string $tipo, array $righe): int
{
    $id = DB::table('scritture_contabili')->insertGetId(['condominio_id' => $s['c']->id, 'esercizio_id' => $e->id, 'gestione_id' => $s['g']->id, 'data_registrazione' => $data,
        'data_competenza' => $data, 'numero_protocollo' => 'PROVA-'.uniqid(), 'causale' => "{$tipo} {$data}", 'tipo_movimento' => $tipo, 'stato' => 'registrata', 'created_at' => now(), 'updated_at' => now()]);
    foreach ($righe as [$conto, $verso, $importo, $cassa]) {
        DB::table('righe_scritture')->insert(['scrittura_id' => $id, 'conto_contabile_id' => $conto, 'cassa_id' => $cassa, 'tipo_riga' => $verso, 'importo' => $importo, 'created_at' => now(), 'updated_at' => now()]);
    }

    return $id;
}

function rcrConto(array $s, string $ruolo): int
{
    return (int) ContoContabile::where('condominio_id', $s['c']->id)->where('ruolo', $ruolo)->value('id');
}

// ─── Il riporto ────────────────────────────────────────────────────────────────────────────────────────────────────────

it('Via Roma, primo anno: riporto € 5.000,00 (l\'apertura), entrate € 3.940,00, uscite € 3.721,00, saldo € 5.219,00; l\'apertura non è un movimento', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $r = rcrRegistro($this, $s, $s['e']);
    $righe = app(RegistroContabilitaService::class)->registro($s['e']);

    expect($r['riporto']['totale'])->toBe(500000)
        ->and($r['totale_entrate'])->toBe(394000)
        ->and($r['totale_uscite'])->toBe(372100)
        ->and($r['saldo_finale'])->toBe(521900)
        ->and(collect($righe)->where('tipo_movimento', 'apertura')->count())->toBe(0)
        // Il primo saldo progressivo parte dal riporto: l'antenna del 15/02 porta a € 5.240,00.
        ->and($righe[0]['saldo_progressivo'])->toBe(524000);
});

it('Via Roma, il 2027 senza movimenti a cavallo parte da € 5.219,00 (prima: da zero)', function () {
    $s = vrmScenario($this);
    $e2027 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    vrmGiorno($this, '2027-01-15');
    $r = rcrRegistro($this, $s, $e2027);

    expect($r['riporto']['totale'])->toBe(521900)->and($r['saldo_finale'])->toBe(521900)
        ->and($r['totale_entrate'])->toBe(0)
        ->and(collect($r['saldi_per_cassa'])->pluck('saldo', 'cassa')->all())->toBe(['Banca principale' => 521900]);
});

it('l\'anno a cavallo, per esercizio: il registro 2026 finisce a € 6.960,00, il 2027 a € 7.460,00; il prospetto per cassa del 2027 parte dal riporto e conta l\'incasso del 28/12 una volta', function () {
    vrmGiorno($this, '2027-02-01');
    $s = be52Condominio();
    $s['cassa']->update(['saldo_iniziale' => 0]);
    DB::table('esercizi')->where('id', $s['e']->id)->update(['stato' => 'chiuso']);
    $e2027 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    $banca = (int) $s['cassa']->conto_contabile_id;
    $cassa = (int) $s['cassa']->id;
    rcrScrittura($s, $s['e'], '2026-01-01', 'apertura', [[$banca, 'dare', 700000, $cassa], [rcrConto($s, 'passate_gestioni'), 'avere', 700000, null]]);
    // L'F24 del 16/01/2027 resta nell'esercizio della delega, il 2026; l'incasso del 28/12/2026 è scritto il 05/01/2027 nel 2027.
    rcrScrittura($s, $s['e'], '2027-01-16', 'pagamento_f24', [[rcrConto($s, 'debiti_erario_ritenute'), 'dare', 4000, null], [$banca, 'avere', 4000, $cassa]]);
    rcrScrittura($s, $e2027, '2026-12-28', 'incasso_rata', [[$banca, 'dare', 50000, $cassa], [rcrConto($s, 'crediti_condomini'), 'avere', 50000, null]]);

    $r2026 = app(RegistroContabilitaService::class)->registroConRiepilogo($s['e']);
    $r2027 = app(RegistroContabilitaService::class)->registroConRiepilogo($e2027);
    $p2027 = app(RiepilogoFinanziarioService::class)->perEsercizio($e2027, '2027-02-01');

    expect($r2026['saldo_finale'])->toBe(696000)
        ->and($r2027['riporto']['totale'])->toBe(696000)->and($r2027['saldo_finale'])->toBe(746000)
        ->and($p2027['totale']['iniziale'])->toBe($r2027['riporto']['totale'])
        ->and($p2027['totale']['entrate'])->toBe(50000)
        ->and($p2027['totale']['finale'])->toBe(746000);
});

it('con un fondo e un accantonamento di € 1.000,00 nel 2026: il riporto del registro dice la banca con il fondo dentro, il prospetto banca e fondo separati, e i totali sono uguali', function () {
    vrmGiorno($this, '2027-02-01');
    $s = be52Condominio();
    $s['cassa']->update(['saldo_iniziale' => 0]);
    $e2027 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    $fondo = be52Fondo($s, 'Fondo facciata');
    // Il fondo senza un saldo suo: si muove solo con l'accantonamento dalla banca.
    $cassaFondo = tap(Cassa::where('conto_contabile_id', $fondo->id)->firstOrFail())->update(['saldo_iniziale' => 0]);
    $banca = (int) $s['cassa']->conto_contabile_id;
    rcrScrittura($s, $s['e'], '2026-01-01', 'apertura', [[$banca, 'dare', 500000, $s['cassa']->id], [rcrConto($s, 'passate_gestioni'), 'avere', 500000, null]]);
    rcrScrittura($s, $s['e'], '2026-06-01', 'giroconto', [[$fondo->id, 'dare', 100000, $cassaFondo->id], [$banca, 'avere', 100000, $s['cassa']->id]]);

    $registro = app(RegistroContabilitaService::class)->registroConRiepilogo($e2027);
    $prospetto = app(RiepilogoFinanziarioService::class)->perEsercizio($e2027, '2027-02-01');
    $perCassa = collect($prospetto['righe'])->pluck('iniziale', 'cassa')->all();

    expect(collect($registro['riporto']['per_cassa'])->pluck('riporto', 'cassa')->all())->toBe(['Banca principale' => 500000])
        ->and($perCassa['Banca principale'])->toBe(400000)->and($perCassa[$cassaFondo->nome])->toBe(100000)
        ->and($registro['riporto']['totale'])->toBe($prospetto['totale']['iniziale']);
});

it('una cassa con il saldo iniziale non ancora a giornale: il riporto è la colonna, nel registro e nel prospetto', function () {
    vrmGiorno($this, '2026-05-01');
    $s = be52Condominio();
    $s['cassa']->update(['saldo_iniziale' => 123400]);

    $registro = app(RegistroContabilitaService::class)->registroConRiepilogo($s['e']);
    $prospetto = app(RiepilogoFinanziarioService::class)->perEsercizio($s['e'], '2026-05-01');

    expect($registro['riporto']['totale'])->toBe(123400)->and($registro['saldo_finale'])->toBe(123400)
        ->and($registro['riporto']['per_cassa'][0]['da_apertura'])->toBeFalse()
        ->and($prospetto['totale']['iniziale'])->toBe(123400);
});

it('un\'apertura scritta nel secondo esercizio è il riporto di quella cassa, e il primo anno non si somma', function () {
    vrmGiorno($this, '2027-02-01');
    $s = be52Condominio();
    $s['cassa']->update(['saldo_iniziale' => 0]);
    $e2027 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    $banca = (int) $s['cassa']->conto_contabile_id;
    rcrScrittura($s, $s['e'], '2026-01-01', 'apertura', [[$banca, 'dare', 700000, $s['cassa']->id], [rcrConto($s, 'passate_gestioni'), 'avere', 700000, null]]);
    rcrScrittura($s, $s['e'], '2026-05-01', 'incasso_rata', [[$banca, 'dare', 50000, $s['cassa']->id], [rcrConto($s, 'crediti_condomini'), 'avere', 50000, null]]);
    // La chiusura del 2026 scrive l'apertura del 2027: € 7.500,00.
    rcrScrittura($s, $e2027, '2027-01-01', 'apertura', [[$banca, 'dare', 750000, $s['cassa']->id], [rcrConto($s, 'passate_gestioni'), 'avere', 750000, null]]);

    $registro = app(RegistroContabilitaService::class)->registroConRiepilogo($e2027);
    $prospetto = app(RiepilogoFinanziarioService::class)->perEsercizio($e2027, '2027-02-01');

    expect($registro['riporto']['totale'])->toBe(750000)->and($registro['riporto']['per_cassa'][0]['da_apertura'])->toBeTrue()
        ->and($registro['righe'])->toBe([])
        ->and($prospetto['totale']['iniziale'])->toBe(750000);
});

it('nel primo esercizio la riga del riporto si chiama «Saldo di apertura»; dal secondo «Riporto dall\'esercizio precedente»', function () {
    $s = vrmScenario($this);
    $e2027 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    vrmGiorno($this, '2027-01-15');

    expect(rcrRegistro($this, $s, $s['e'])['riporto']['etichetta'])->toBe('Saldo di apertura')
        ->and(rcrRegistro($this, $s, $e2027)['riporto']['etichetta'])->toBe('Riporto dall\'esercizio precedente');
});

it('i versamenti dei condòmini si sommano sul perimetro del registro senza costruirne le righe: lo stesso numero dei totali per tipo', function () {
    $s = vrmScenario($this);
    $e2025 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'chiuso']);
    // Uno storno di un incasso e un accantonamento verso un fondo non sono versamenti; un versamento dell'anno prima non è di questo registro.
    vrmGiorno($this, '2026-12-20');
    lcdStorna($this, ['c' => $s['c']], $s['incasso_bice']);
    rcrScrittura($s, $e2025, '2025-06-01', 'incasso_rata', [[(int) $s['cassa']->conto_contabile_id, 'dare', 12300, $s['cassa']->id], [rcrConto($s, 'crediti_condomini'), 'avere', 12300, null]]);
    vrmGiorno($this, '2027-01-15');
    $registro = app(RegistroContabilitaService::class)->registroConRiepilogo($s['e']);
    $versamenti = collect($registro['totali_per_tipo'])->firstWhere('chiave', 'versamenti_condomini')['entrate'];

    expect(app(RegistroContabilitaService::class)->versamentiDeiCondomini($s['e']))->toBe($versamenti)->toBe(370000)
        ->and(app(\App\Services\Gestionale\RiepilogoPerCompetenza::class)->perEsercizio($s['c'], $s['e'])['quote']['totale']['versamenti_registro'])->toBe(370000);
});

// ─── La descrizione, i totali, il saldo a fine mese ─────────────────────────────────────────────────────────────────────

it('un pagamento su due fatture dice i numeri: «Pag. FT-C, FT-D — Fornitore Test Srl»', function () {
    $s = vrmBase($this);
    vrmGiorno($this, '2026-05-10');
    $c = vrmFattura($this, $s, $s['fornitore'], 'FT-C', [[$s['pulizie']->id, 200.00]], '2026-05-10');
    $d = vrmFattura($this, $s, $s['fornitore'], 'FT-D', [[$s['manutenzioni']->id, 300.00]], '2026-05-10');
    vrmPaga($this, $s, $s['fornitore'], [[$c, 'pagamento', 20000], [$d, 'pagamento', 30000]], '2026-05-10');
    $riga = collect(app(RegistroContabilitaService::class)->registro($s['e']))->firstWhere('tipo_movimento', 'pagamento_fornitore');

    expect($riga['descrizione'])->toBe('Pag. FT-C, FT-D — Fornitore Test Srl')->and($riga['uscita'])->toBe(50000);
});

it('la nota di credito compensata non conta come fattura: «Pag. FT-A (nota NC-1 compensata) — Fornitore Test Srl»', function () {
    $s = vrmBase($this);
    vrmGiorno($this, '2026-05-10');
    $ft = vrmFattura($this, $s, $s['fornitore'], 'FT-A', [[$s['pulizie']->id, 1000.00]], '2026-05-10');
    $nc = vrmFattura($this, $s, $s['fornitore'], 'NC-1', [[$s['pulizie']->id, 200.00]], '2026-05-10', ['tipo_documento' => 'nota_credito']);
    vrmPaga($this, $s, $s['fornitore'], [[$ft, 'pagamento', 80000], [$ft, 'compensazione', 20000], [$nc, 'compensazione', 20000]], '2026-05-10');
    $riga = collect(app(RegistroContabilitaService::class)->registro($s['e']))->firstWhere('tipo_movimento', 'pagamento_fornitore');

    expect($riga['descrizione'])->toBe('Pag. FT-A (nota NC-1 compensata) — Fornitore Test Srl');
});

it('i totali per tipo di Via Roma, che sommano ai totali del registro; i pagamenti di fatture di esercizi precedenti a parte', function () {
    $s = vrmScenario($this);
    // Una fattura del 2025 pagata il 20/12/2026: € 300,00 di pagamenti di fatture di esercizi precedenti.
    $e2025 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'chiuso']);
    vrmGiorno($this, '2026-12-20');
    $vecchia = vrmFattura($this, $s, $s['fornitore'], 'FT-2025', [[$s['pulizie']->id, 300.00]], '2026-12-20');
    DB::table('fatture_passive')->where('id', $vecchia->id)->update(['esercizio_id' => $e2025->id]);
    DB::table('scritture_contabili')->where('id', DB::table('fattura_scrittura')->where('fattura_passiva_id', $vecchia->id)->where('tipo', 'competenza')->value('scrittura_contabile_id'))
        ->update(['esercizio_id' => $e2025->id, 'data_competenza' => '2025-12-10']);
    vrmPaga($this, $s, $s['fornitore'], [[$vecchia, 'pagamento', 30000]], '2026-12-20');
    vrmGiorno($this, '2027-01-15');
    $r = rcrRegistro($this, $s, $s['e']);
    $tipi = collect($r['totali_per_tipo'])->keyBy('chiave');

    expect([$tipi['versamenti_condomini']['entrate'], $tipi['entrate_terzi']['entrate'], $tipi['regolazioni']['uscite'], $tipi['f24']['uscite']])->toBe([370000, 24000, 9600, 4000])
        ->and([$tipi['pagamenti_fornitori']['uscite'], $tipi['pagamenti_fornitori']['di_cui_esercizi_precedenti']])->toBe([388500, 30000])
        ->and($tipi->sum('entrate'))->toBe($r['totale_entrate'])
        ->and($tipi->sum('uscite'))->toBe($r['totale_uscite']);
});

it('il saldo a fine mese: una riga per ogni mese con movimenti, con le entrate, le uscite e il saldo vero a quella data', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $mesi = collect(rcrRegistro($this, $s, $s['e'])['saldi_fine_mese']);

    expect($mesi->pluck('saldo', 'mese')->all())->toBe(['2026-02' => 524000, '2026-03' => 729500, '2026-06' => 719900, '2026-11' => 766150, '2026-12' => 521900])
        ->and($mesi->firstWhere('mese', '2026-11'))->toMatchArray(['fine' => '2026-11-30', 'entrate' => 164500, 'uscite' => 118250]);
});

it('con un filtro il saldo a fine mese resta quello vero, e i movimenti contano solo quelli mostrati', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $r = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.registro-contabilita.index', [$s['c'], $s['e'], 'search' => 'Canone']))->assertOk()->viewData('page')['props']['riepilogo'];

    expect($r['saldi_fine_mese'])->toBe([['mese' => '2026-06', 'fine' => '2026-06-30', 'movimenti' => 1, 'entrate' => 0, 'uscite' => 9600, 'saldo' => 719900]]);

    // Fino al 15/11: di novembre si vede il pagamento del 10/11, e il saldo del mese è quello del 30/11, con l'incasso di Bice.
    $fino = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.registro-contabilita.index', [$s['c'], $s['e'], 'data_a' => '2026-11-15']))->assertOk()->viewData('page')['props']['riepilogo'];
    expect(collect($fino['saldi_fine_mese'])->firstWhere('mese', '2026-11'))->toBe(['mese' => '2026-11', 'fine' => '2026-11-30', 'movimenti' => 1, 'entrate' => 0, 'uscite' => 118250, 'saldo' => 766150]);
});

// ─── La stampa ─────────────────────────────────────────────────────────────────────────────────────────────────────────

it('la stampa si chiama «registro-contabilita-…», dice il riporto e non dice più che il saldo parte da zero', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $r = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.registro-contabilita.print', [$s['c'], $s['e']]));
    $r->assertOk();

    expect($r->headers->get('Content-Disposition'))->toContain('registro-contabilita-')->not->toContain('prima-nota')
        ->and(substr($r->getContent(), 0, 4))->toBe('%PDF');

    $html = view('pdf.gestionale.registro_contabilita', rcrDatiStampa($s))->render();
    expect($html)->toContain('<b>Saldo di apertura</b>')->toContain('<b>€ 5.000,00</b>')->toContain('Saldo al 30/11/2026')
        ->not->toContain('parte da zero')
        // Il registro nomina chi ha versato ogni incasso: la stampa lo dice in testa.
        ->toContain(e(\App\Services\Gestionale\PosizioniDeiCondomini::RISERVATO));
});

/** I dati della vista come li prepara il controller: la stessa lettura del servizio. */
function rcrDatiStampa(array $s): array
{
    $registro = app(RegistroContabilitaService::class)->registroConRiepilogo($s['e']);

    return [
        'condominio' => $s['c'], 'esercizio' => $s['e'], 'righe' => $registro['righe'],
        'totale_entrate' => (int) array_sum(array_column($registro['righe'], 'entrata')), 'totale_uscite' => (int) array_sum(array_column($registro['righe'], 'uscita')),
        'saldo_finale' => $registro['saldo_finale'], 'saldo_alla_data' => $registro['data_riferimento'], 'filtri' => ['data_da' => null, 'data_a' => null, 'search' => null],
        'senza_firma' => true, 'mostra_annotazione' => false, 'saldi_per_cassa' => $registro['saldi_per_cassa'], 'riporto' => $registro['riporto'],
        'totali_per_tipo' => $registro['totali_per_tipo'], 'saldi_fine_mese' => $registro['saldi_fine_mese'],
    ];
}
