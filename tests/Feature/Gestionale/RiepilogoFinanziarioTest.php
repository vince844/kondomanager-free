<?php

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\RigaScrittura;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\RiepilogoFinanziarioService;
use App\Services\Gestionale\StatoPatrimonialeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * D19 — riepilogo finanziario: per cassa, iniziale + entrate − uscite = finale; nel piede i soli
 * flussi esterni. Numeri calcolati a mano, sotto ogni test.
 */
function rfConto(int $condominioId, string $ruolo, string $codice, string $tipo, string $categoria): int
{
    return DB::table('conti_contabili')->insertGetId([
        'condominio_id' => $condominioId, 'ruolo' => $ruolo, 'codice' => $codice, 'nome' => $codice.' '.$ruolo,
        'tipo' => $tipo, 'categoria' => $categoria, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function rfCassa(int $condominioId, string $tipo, string $nome, string $codice): Cassa
{
    $conto = rfConto($condominioId, 'conto_'.$tipo, $codice, 'attivo', 'liquidita');

    return Cassa::create(['condominio_id' => $condominioId, 'nome' => $nome, 'tipo' => $tipo, 'conto_contabile_id' => $conto, 'saldo_iniziale' => 0, 'attiva' => true]);
}

function rfScrittura(array $ctx, Esercizio $es, string $data, string $tipo, int $contoDare, int $contoAvere, int $importo): void
{
    $sc = ScritturaContabile::create([
        'condominio_id' => $ctx['condominio']->id, 'esercizio_id' => $es->id, 'gestione_id' => $ctx['gestione']->id,
        'data_registrazione' => $data, 'data_competenza' => $data, 'causale' => $tipo.' '.$data,
        'tipo_movimento' => $tipo, 'stato' => 'registrata',
    ]);
    RigaScrittura::create(['scrittura_id' => $sc->id, 'conto_contabile_id' => $contoDare, 'tipo_riga' => 'dare', 'importo' => $importo]);
    RigaScrittura::create(['scrittura_id' => $sc->id, 'conto_contabile_id' => $contoAvere, 'tipo_riga' => 'avere', 'importo' => $importo]);
}

/**
 *   2025-01-01  apertura banca 5.000      DARE banca / AVERE passate gestioni   (tipo apertura)
 *   2025-03-01  incasso 2.000             DARE banca / AVERE crediti
 *   2025-04-01  accantonamento 1.000      DARE fondo / AVERE banca              (giroconto)
 *   2025-05-01  prelievo 300              DARE contanti / AVERE banca           (giroconto)
 *   2025-06-01  pagamento 700 da banca    DARE debiti / AVERE banca
 *   2025-07-01  spesa 100 in contanti     DARE costi / AVERE contanti
 *   2026-02-01  incasso 500 su banca      DARE banca / AVERE crediti            (esercizio 2026)
 */
function rfScenario(): array
{
    $condominio = Condominio::factory()->create();
    $es2025 = Esercizio::factory()->create(['condominio_id' => $condominio->id, 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'chiuso']);
    $es2026 = Esercizio::factory()->create(['condominio_id' => $condominio->id, 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $gestione = Gestione::factory()->create(['condominio_id' => $condominio->id]);
    $ctx = ['condominio' => $condominio, 'gestione' => $gestione];

    $banca = rfCassa($condominio->id, 'banca', 'Conto corrente', '1010');
    $contanti = rfCassa($condominio->id, 'contanti', 'Cassa contanti', '1011');
    $fondo = rfCassa($condominio->id, 'fondo', 'Fondo lavori', '1012');
    $crediti = rfConto($condominio->id, 'crediti_condomini', '1101', 'attivo', 'crediti');
    $debiti = rfConto($condominio->id, 'debiti_fornitori', '2201', 'passivo', 'debiti');
    $passate = rfConto($condominio->id, 'passate_gestioni', '3002', 'passivo', 'fondi');
    $costi = rfConto($condominio->id, 'costi_servizi', '5001', 'costo', 'costi');

    $b = $banca->conto_contabile_id; $c = $contanti->conto_contabile_id; $f = $fondo->conto_contabile_id;
    rfScrittura($ctx, $es2025, '2025-01-01', 'apertura',             $b, $passate, 500000);
    rfScrittura($ctx, $es2025, '2025-03-01', 'incasso_rata',         $b, $crediti, 200000);
    rfScrittura($ctx, $es2025, '2025-04-01', 'giroconto',            $f, $b,       100000);
    rfScrittura($ctx, $es2025, '2025-05-01', 'giroconto',            $c, $b,        30000);
    rfScrittura($ctx, $es2025, '2025-06-01', 'pagamento_fornitore',  $debiti, $b,   70000);
    rfScrittura($ctx, $es2025, '2025-07-01', 'regolazione_immediata', $costi, $c,   10000);
    rfScrittura($ctx, $es2026, '2026-02-01', 'incasso_rata',         $b, $crediti,  50000);

    return [$condominio, $es2025, $es2026, compact('banca', 'contanti', 'fondo')];
}

test('esercizio chiuso: ogni cassa quadra con i giroconti dentro, il piede quadra senza', function () {
    [, $es2025] = rfScenario();

    $r = app(RiepilogoFinanziarioService::class)->perEsercizio($es2025, oggi: '2026-09-12');
    $righe = collect($r['righe'])->keyBy('cassa');

    expect($r['dal'])->toBe('2025-01-01')->and($r['al'])->toBe('2025-12-31')->and($r['stato_esercizio'])->toBe('chiuso');

    // banca: iniziale 5.000 (l'apertura, non un'entrata); entrate 2.000; uscite 1.000 + 300 + 700; finale 5.000
    expect($righe['Conto corrente']['iniziale'])->toBe(500000)
        ->and($righe['Conto corrente']['entrate'])->toBe(200000)
        ->and($righe['Conto corrente']['uscite'])->toBe(200000)
        ->and($righe['Conto corrente']['finale'])->toBe(500000)
        // contanti: 0; 300 dal prelievo; 100 di spesa; 200
        ->and($righe['Cassa contanti']['iniziale'])->toBe(0)
        ->and($righe['Cassa contanti']['entrate'])->toBe(30000)
        ->and($righe['Cassa contanti']['uscite'])->toBe(10000)
        ->and($righe['Cassa contanti']['finale'])->toBe(20000)
        // fondo: 0; 1.000 accantonati; 0; 1.000
        ->and($righe['Fondo lavori']['entrate'])->toBe(100000)
        ->and($righe['Fondo lavori']['finale'])->toBe(100000);

    // Piede: iniziale 5.000; entrate ESTERNE 2.000; uscite ESTERNE 700 + 100 = 800; finale 6.200 = Σ finali
    expect($r['totale'])->toBe(['iniziale' => 500000, 'entrate' => 200000, 'uscite' => 80000, 'finale' => 620000])
        ->and($r['giroconti'])->toBe(['entrate' => 130000, 'uscite' => 130000])
        ->and(collect($r['righe'])->sum('finale'))->toBe(620000)
        ->and($r['casse_reali_negative'])->toBe([]);

    // Ordine di presentazione: banca, contanti, fondo.
    expect(collect($r['righe'])->pluck('tipo')->all())->toBe(['banca', 'contanti', 'fondo']);
});

test('esercizio aperto: l\'iniziale è tutto il 2025, la finestra si ferma a oggi', function () {
    [, , $es2026] = rfScenario();

    $r = app(RiepilogoFinanziarioService::class)->perEsercizio($es2026, oggi: '2026-09-12');
    $righe = collect($r['righe'])->keyBy('cassa');

    expect($r['al'])->toBe('2026-09-12')->and($r['stato_esercizio'])->toBe('aperto')
        ->and($righe['Conto corrente']['iniziale'])->toBe(500000)
        ->and($righe['Conto corrente']['entrate'])->toBe(50000)
        ->and($righe['Conto corrente']['finale'])->toBe(550000)
        ->and($righe['Cassa contanti']['iniziale'])->toBe(20000)
        ->and($righe['Fondo lavori']['iniziale'])->toBe(100000)
        ->and($r['totale'])->toBe(['iniziale' => 620000, 'entrate' => 50000, 'uscite' => 0, 'finale' => 670000]);
});

/**
 * R2 — la disponibilità finale di ogni cassa DEVE essere il saldo di quel conto nella fotografia
 * alla stessa data (D16). Stessa aggregazione (per conto contabile), stesso taglio (per data):
 * qui si prova che le due strade arrivano allo stesso numero.
 */
test('R2: il finale di ogni cassa è il saldo del suo conto nella situazione patrimoniale alla stessa data', function () {
    [$condominio, $es2025, $es2026, $casse] = rfScenario();
    $servizio = app(RiepilogoFinanziarioService::class);
    $sp = app(StatoPatrimonialeService::class);

    foreach ([[$es2025, '2025-12-31'], [$es2026, '2026-09-12']] as [$es, $al]) {
        $r = $servizio->perEsercizio($es, oggi: '2026-09-12');
        $foto = collect($sp->calcola($condominio, allaData: $al)['attivo']['voci'])->keyBy('id');
        foreach ($r['righe'] as $riga) {
            expect($riga['finale'])->toBe($foto[$riga['conto_contabile_id']]['saldo'], $riga['cassa'].' al '.$al);
        }
    }
});

test('una cassa reale che finisce sotto zero viene segnalata, un fondo no', function () {
    [$condominio, $es2025, , $casse] = rfScenario();
    $costi = DB::table('conti_contabili')->where('condominio_id', $condominio->id)->where('ruolo', 'costi_servizi')->value('id');
    $gestione = Gestione::where('condominio_id', $condominio->id)->first();
    // Una spesa in contanti di 500 su una cassa che ne ha 200 → −300.
    rfScrittura(['condominio' => $condominio, 'gestione' => $gestione], $es2025, '2025-08-01', 'regolazione_immediata', $costi, $casse['contanti']->conto_contabile_id, 50000);

    $r = app(RiepilogoFinanziarioService::class)->perEsercizio($es2025, oggi: '2026-09-12');
    $righe = collect($r['righe'])->keyBy('cassa');

    expect($righe['Cassa contanti']['finale'])->toBe(-30000)
        ->and($righe['Cassa contanti']['negativa'])->toBeTrue()
        ->and($righe['Conto corrente']['negativa'])->toBeFalse()
        ->and(collect($r['casse_reali_negative'])->map(fn ($n) => [$n['cassa'], $n['minimo'], $n['data']])->all())->toBe([['Cassa contanti', -30000, '2025-08-01']]);
});

test('il saldo in colonna di una cassa mai aperta a giornale conta come iniziale, una volta sola', function () {
    [$condominio, $es2025] = rfScenario();
    $conto = rfConto($condominio->id, 'conto_banca', '1013', 'attivo', 'liquidita');
    Cassa::create(['condominio_id' => $condominio->id, 'nome' => 'Vecchia banca', 'tipo' => 'banca', 'conto_contabile_id' => $conto, 'saldo_iniziale' => 12345, 'attiva' => true]);

    $r = app(RiepilogoFinanziarioService::class)->perEsercizio($es2025, oggi: '2026-09-12');
    $riga = collect($r['righe'])->firstWhere('cassa', 'Vecchia banca');

    expect($riga['iniziale'])->toBe(12345)->and($riga['entrate'])->toBe(0)->and($riga['finale'])->toBe(12345)
        ->and($r['totale']['iniziale'])->toBe(512345);
});

/**
 * ⚠️ Tre casi che la revisione della beta.25 ha trovato scoperti, con la prova.
 */
test('un movimento datato il giorno stesso del taglio è dentro la fotografia, anche su SQLite', function () {
    [$condominio, $es2025, , $casse] = rfScenario();
    $crediti = DB::table('conti_contabili')->where('condominio_id', $condominio->id)->where('ruolo', 'crediti_condomini')->value('id');
    $gestione = Gestione::where('condominio_id', $condominio->id)->first();
    rfScrittura(['condominio' => $condominio, 'gestione' => $gestione], $es2025, '2025-12-31', 'incasso_rata', $casse['banca']->conto_contabile_id, $crediti, 1000);

    $foto = collect(app(StatoPatrimonialeService::class)->calcola($condominio, allaData: '2025-12-31')['attivo']['voci'])->keyBy('id');
    expect($foto[$casse['banca']->conto_contabile_id]['saldo'])->toBe(501000);
});

/**
 * Un incasso ripartito su due casse — due righe di cassa dello stesso verso, più il credito — non è
 * un trasferimento: prima faceva scattare un'eccezione nel piede (pagina bianca), ora conta per
 * intero come flusso esterno e il piede quadra per costruzione.
 */
test('un incasso ripartito su due casse non è un giroconto: il piede quadra e nessuna eccezione', function () {
    [$condominio, $es2025, , $casse] = rfScenario();
    $crediti = DB::table('conti_contabili')->where('condominio_id', $condominio->id)->where('ruolo', 'crediti_condomini')->value('id');
    $gestione = Gestione::where('condominio_id', $condominio->id)->first();
    $sc = ScritturaContabile::create([
        'condominio_id' => $condominio->id, 'esercizio_id' => $es2025->id, 'gestione_id' => $gestione->id,
        'data_registrazione' => '2025-09-01', 'data_competenza' => '2025-09-01', 'causale' => 'Incasso misto',
        'tipo_movimento' => 'incasso_rata', 'stato' => 'registrata',
    ]);
    RigaScrittura::create(['scrittura_id' => $sc->id, 'conto_contabile_id' => $casse['banca']->conto_contabile_id, 'tipo_riga' => 'dare', 'importo' => 40000]);
    RigaScrittura::create(['scrittura_id' => $sc->id, 'conto_contabile_id' => $casse['contanti']->conto_contabile_id, 'tipo_riga' => 'dare', 'importo' => 10000]);
    RigaScrittura::create(['scrittura_id' => $sc->id, 'conto_contabile_id' => $crediti, 'tipo_riga' => 'avere', 'importo' => 50000]);

    $r = app(RiepilogoFinanziarioService::class)->perEsercizio($es2025, oggi: '2026-09-12');

    // Prima: iniziale 5.000, esterne 2.000 / 800, finale 6.200. Ora +500 di entrate esterne, finale 6.700.
    expect($r['piede_quadra'])->toBeTrue()
        ->and($r['totale'])->toBe(['iniziale' => 500000, 'entrate' => 250000, 'uscite' => 80000, 'finale' => 670000])
        ->and($r['giroconti']['entrate'])->toBe(130000);
});

test('una cassa che scende sotto zero a metà anno e risale è segnalata lo stesso, con la data', function () {
    [$condominio, $es2025, , $casse] = rfScenario();
    $costi = DB::table('conti_contabili')->where('condominio_id', $condominio->id)->where('ruolo', 'costi_servizi')->value('id');
    $crediti = DB::table('conti_contabili')->where('condominio_id', $condominio->id)->where('ruolo', 'crediti_condomini')->value('id');
    $ctx = ['condominio' => $condominio, 'gestione' => Gestione::where('condominio_id', $condominio->id)->first()];
    // Contanti: 0 → +300 (05/05) → −100 (07/07 spesa) → −600 (08/01 spesa 500) → +400 (09/01 incasso 1.000): finale positivo, ma il 1° agosto era a −600.
    rfScrittura($ctx, $es2025, '2025-08-01', 'regolazione_immediata', $costi, $casse['contanti']->conto_contabile_id, 50000);
    rfScrittura($ctx, $es2025, '2025-09-01', 'incasso_rata', $casse['contanti']->conto_contabile_id, $crediti, 100000);

    $r = app(RiepilogoFinanziarioService::class)->perEsercizio($es2025, oggi: '2026-09-12');
    $contanti = collect($r['righe'])->firstWhere('cassa', 'Cassa contanti');

    expect($contanti['finale'])->toBe(70000)
        ->and($contanti['negativa'])->toBeTrue()
        ->and($contanti['minimo'])->toBe(-30000)
        ->and($contanti['minimo_il'])->toBe('2025-08-01')
        ->and($r['casse_reali_negative'][0]['data'])->toBe('2025-08-01');
});

test('una coppia originale + storno dentro l\'esercizio non conta fra i flussi e non manda la cassa «sotto zero»', function () {
    [$condominio, $es2025, $es2026, $casse] = rfScenario();
    $costi = DB::table('conti_contabili')->where('condominio_id', $condominio->id)->where('ruolo', 'costi_servizi')->value('id');
    $ctx = ['condominio' => $condominio, 'gestione' => Gestione::where('condominio_id', $condominio->id)->first()];
    // Contanti a +200 dal 01/07 (300 di giroconto, 100 di spesa). Il 10/10 una spesa da 800 (→ −600) e il suo storno.
    rfScrittura($ctx, $es2025, '2025-10-10', 'regolazione_immediata', $costi, $casse['contanti']->conto_contabile_id, 80000);
    rfScrittura($ctx, $es2025, '2025-10-10', 'storno_regolazione_immediata', $casse['contanti']->conto_contabile_id, $costi, 80000);
    // Per causale, non per data: su SQLite la data è testo con l'ora e `= '2025-10-10'` non trova nulla.
    $originale = DB::table('scritture_contabili')->where('causale', 'regolazione_immediata 2025-10-10')->value('id');
    $storno = DB::table('scritture_contabili')->where('tipo_movimento', 'storno_regolazione_immediata')->value('id');
    DB::table('scritture_contabili')->where('id', $storno)->update(['scrittura_padre_id' => $originale]);

    // «+100 −100 fa zero, si annullano» (Vincenzo): la coppia non conta né fra le uscite né fra le entrate,
    // e la cassa non risulta mai sotto zero — la spesa non è mai successa. Il finale è lo stesso.
    $r = app(RiepilogoFinanziarioService::class)->perEsercizio($es2025, oggi: '2026-09-12');
    $contanti = collect($r['righe'])->firstWhere('cassa', 'Cassa contanti');
    expect($contanti['finale'])->toBe(20000)
        ->and($contanti['entrate'])->toBe(30000)->and($contanti['uscite'])->toBe(10000)
        ->and($contanti['negativa'])->toBeFalse()
        ->and($r['stornate'])->toBe(['coppie' => 1, 'importo' => 80000])
        ->and($r['casse_reali_negative'])->toBe([]);

    // Anche con lo storno il giorno dopo: stesso esercizio, stessa coppia, stesso esito.
    DB::table('scritture_contabili')->where('id', $storno)->update(['data_competenza' => '2025-10-11']);
    $r = app(RiepilogoFinanziarioService::class)->perEsercizio($es2025, oggi: '2026-09-12');
    expect(collect($r['righe'])->firstWhere('cassa', 'Cassa contanti')['negativa'])->toBeFalse();

    // Se lo storno cade nell'esercizio DOPO, ogni riga conta nel suo anno — altrimenti nessuna delle due
    // righe quadrerebbe: nel 2025 la spesa c'è (uscita 800, cassa a −600 il 10/10), nel 2026 c'è lo storno (entrata 800).
    DB::table('scritture_contabili')->where('id', $storno)->update(['data_competenza' => '2026-01-10', 'esercizio_id' => $es2026->id]);
    $r25 = app(RiepilogoFinanziarioService::class)->perEsercizio($es2025, oggi: '2026-09-12');
    $c25 = collect($r25['righe'])->firstWhere('cassa', 'Cassa contanti');
    expect($c25['uscite'])->toBe(90000)->and($c25['finale'])->toBe(-60000)
        ->and($c25['negativa'])->toBeTrue()->and($c25['minimo'])->toBe(-60000)->and($c25['minimo_il'])->toBe('2025-10-10')
        ->and($r25['stornate']['coppie'])->toBe(0);
    $r26 = app(RiepilogoFinanziarioService::class)->perEsercizio($es2026, oggi: '2026-09-12');
    $c26 = collect($r26['righe'])->firstWhere('cassa', 'Cassa contanti');
    expect($c26['iniziale'])->toBe(-60000)->and($c26['entrate'])->toBe(80000)->and($c26['finale'])->toBe(20000);
});

test('una quota pagata a credito (figlia storno_credito) non è una coppia stornata: l\'incasso conta', function () {
    [$condominio, $es2025, , $casse] = rfScenario();
    $crediti = DB::table('conti_contabili')->where('condominio_id', $condominio->id)->where('ruolo', 'crediti_condomini')->value('id');
    $ctx = ['condominio' => $condominio, 'gestione' => Gestione::where('condominio_id', $condominio->id)->first()];
    // Incasso in banca da 24,57 con una figlia `storno_credito` (credito usato per altre quote): l'incasso è valido.
    rfScrittura($ctx, $es2025, '2025-10-10', 'incasso_rata', $casse['banca']->conto_contabile_id, $crediti, 2457);
    rfScrittura($ctx, $es2025, '2025-10-10', 'storno_credito', $crediti, $crediti, 10000);
    $incasso = DB::table('scritture_contabili')->where('causale', 'incasso_rata 2025-10-10')->value('id');
    DB::table('scritture_contabili')->where('tipo_movimento', 'storno_credito')->update(['scrittura_padre_id' => $incasso]);

    $r = app(RiepilogoFinanziarioService::class)->perEsercizio($es2025, oggi: '2026-09-12');
    $banca = collect($r['righe'])->firstWhere('cassa', 'Conto corrente');

    // Col criterio LIKE 'storno_%' l'incasso spariva dai flussi e il finale della banca perdeva 24,57
    // rispetto alla fotografia: R2 diventava rosso con la diagnosi sbagliata («scritture datate fuori»).
    expect($r['stornate']['coppie'])->toBe(0)
        ->and($banca['entrate'])->toBe(202457)
        ->and($r['piede_quadra'])->toBeTrue();
});

test('un esercizio non ancora iniziato si dichiara futuro, con i flussi a zero e l\'iniziale a oggi', function () {
    [$condominio] = rfScenario();
    $es2027 = Esercizio::factory()->create(['condominio_id' => $condominio->id, 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);

    $r = app(RiepilogoFinanziarioService::class)->perEsercizio($es2027, oggi: '2026-09-12');

    expect($r['stato_esercizio'])->toBe('futuro')
        ->and($r['totale']['entrate'])->toBe(0)->and($r['totale']['uscite'])->toBe(0)
        ->and($r['totale']['iniziale'])->toBe(670000)->and($r['totale']['finale'])->toBe(670000);
});
