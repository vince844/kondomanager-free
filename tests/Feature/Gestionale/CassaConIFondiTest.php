<?php

/**
 * La scheda «Cassa» del rendiconto: il conto corrente chiude con il saldo della banca, con dentro i fondi, che sono partizioni dello stesso conto; sotto
 * il conto le righe «di cui» del fondo (iniziale, accantonati, usati, finale) e della parte libera. Ogni riga quadra da sé; le righe «di cui» fanno il
 * conto nell'iniziale e nel finale, e nelle entrate e nelle uscite meno gli spostamenti fra la parte libera e i fondi, che il conto non vede; le casse
 * fanno il piede nell'iniziale e nel finale, e nei flussi meno i trasferimenti fra le casse. Il conto è la stessa cifra del controllo della banca.
 *
 * Le cifre, a mano, sotto ogni caso. Centesimi.
 */

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Models\Gestionale\Cassa;
use App\Services\Gestionale\RiepilogoFinanziarioService;
use App\Services\Gestionale\SaldiDelleBanche;
use App\Services\Gestionale\StatoPatrimonialePaginaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function ccfConto(int $condominioId, string $ruolo, string $codice, string $tipo, string $categoria): int
{
    return DB::table('conti_contabili')->insertGetId([
        'condominio_id' => $condominioId, 'ruolo' => $ruolo, 'codice' => $codice, 'nome' => $codice.' '.$ruolo,
        'tipo' => $tipo, 'categoria' => $categoria, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function ccfCassa(int $condominioId, string $tipo, string $nome, string $codice, bool $attiva = true): Cassa
{
    $conto = ccfConto($condominioId, 'conto_'.$tipo.'_'.$codice, $codice, 'attivo', 'liquidita');

    return Cassa::create(['condominio_id' => $condominioId, 'nome' => $nome, 'tipo' => $tipo, 'conto_contabile_id' => $conto, 'saldo_iniziale' => 0, 'attiva' => $attiva]);
}

/** Una registrazione a due righe, dare e avere, con la cassa sulle righe di cassa (come le scrivono le azioni). */
function ccfScrittura(array $ctx, Esercizio $es, string $data, string $tipo, int $contoDare, int $contoAvere, int $importo): int
{
    $cassaDi = fn (int $conto) => DB::table('casse')->where('conto_contabile_id', $conto)->value('id');
    $id = DB::table('scritture_contabili')->insertGetId(['condominio_id' => $ctx['condominio']->id, 'esercizio_id' => $es->id, 'gestione_id' => $ctx['gestione']->id,
        'data_registrazione' => $data, 'data_competenza' => $data, 'numero_protocollo' => 'CCF-'.uniqid(), 'causale' => $tipo.' '.$data, 'tipo_movimento' => $tipo,
        'stato' => 'registrata', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('righe_scritture')->insert([
        ['scrittura_id' => $id, 'conto_contabile_id' => $contoDare, 'cassa_id' => $cassaDi($contoDare), 'tipo_riga' => 'dare', 'importo' => $importo, 'created_at' => now(), 'updated_at' => now()],
        ['scrittura_id' => $id, 'conto_contabile_id' => $contoAvere, 'cassa_id' => $cassaDi($contoAvere), 'tipo_riga' => 'avere', 'importo' => $importo, 'created_at' => now(), 'updated_at' => now()],
    ]);

    return $id;
}

function ccfBase(): array
{
    $condominio = Condominio::factory()->create();
    $gestione = Gestione::factory()->create(['condominio_id' => $condominio->id]);

    return ['condominio' => $condominio, 'gestione' => $gestione,
        'crediti' => ccfConto($condominio->id, 'crediti_condomini', '1101', 'attivo', 'crediti'),
        'debiti' => ccfConto($condominio->id, 'debiti_fornitori', '2201', 'passivo', 'debiti'),
        'passate' => ccfConto($condominio->id, 'passate_gestioni', '3002', 'passivo', 'fondi'),
        'costi' => ccfConto($condominio->id, 'costi_servizi', '5001', 'costo', 'costi')];
}

/** Il conto di una cassa nella scheda, per nome; e le righe «di cui» per nome (la parte libera è «libero»). */
function ccfConti(array $r): array
{
    return collect($r['conti'])->keyBy('cassa')->map(fn ($c) => $c + ['per_nome' => collect($c['di_cui'])->keyBy(fn ($d) => $d['tipo'] === 'libero' ? 'libero' : $d['cassa'])->all()])->all();
}

function ccfCifre(array $r): array
{
    return [$r['iniziale'], $r['entrate'], $r['uscite'], $r['finale']];
}

/** Ogni riga quadra da sé; le righe «di cui» fanno il conto (i flussi meno gli spostamenti interni); le casse fanno il piede (i flussi meno i trasferimenti). */
function ccfSiSommano(array $r): void
{
    foreach ($r['conti'] as $c) {
        expect($c['iniziale'] + $c['entrate'] - $c['uscite'])->toBe($c['finale'], 'la riga di «'.$c['cassa'].'»');
        if ($c['di_cui'] !== []) {
            foreach ($c['di_cui'] as $d) {
                expect($d['iniziale'] + $d['entrate'] - $d['uscite'])->toBe($d['finale'], 'la riga «di cui» '.($d['cassa'] ?? 'libero'));
            }
            expect(array_sum(array_column($c['di_cui'], 'iniziale')))->toBe($c['iniziale'])
                ->and(array_sum(array_column($c['di_cui'], 'finale')))->toBe($c['finale'])
                ->and(array_sum(array_column($c['di_cui'], 'entrate')) - $c['interni'])->toBe($c['entrate'])
                ->and(array_sum(array_column($c['di_cui'], 'uscite')) - $c['interni'])->toBe($c['uscite']);
        }
    }
    expect(array_sum(array_column($r['conti'], 'iniziale')))->toBe($r['totale']['iniziale'])
        ->and(array_sum(array_column($r['conti'], 'finale')))->toBe($r['totale']['finale'])
        ->and(array_sum(array_column($r['conti'], 'entrate')) - $r['fra_le_casse'])->toBe($r['totale']['entrate'])
        ->and(array_sum(array_column($r['conti'], 'uscite')) - $r['fra_le_casse'])->toBe($r['totale']['uscite']);
}

/**
 *   2025-01-01  apertura banca 5.000      DARE banca / AVERE passate gestioni
 *   2025-03-01  incasso 2.000             DARE banca / AVERE crediti
 *   2025-04-01  accantonamento 1.000      DARE fondo / AVERE banca              (giroconto)
 *   2025-05-01  prelievo 300              DARE contanti / AVERE banca           (giroconto)
 *   2025-06-01  pagamento 700 da banca    DARE debiti / AVERE banca
 *   2025-07-01  spesa 100 in contanti     DARE costi / AVERE contanti
 *   2026-02-01  incasso 500 su banca      DARE banca / AVERE crediti
 */
function ccfUnaBanca(): array
{
    $ctx = ccfBase();
    $c = $ctx['condominio'];
    $es2025 = Esercizio::factory()->create(['condominio_id' => $c->id, 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'chiuso']);
    $es2026 = Esercizio::factory()->create(['condominio_id' => $c->id, 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $banca = ccfCassa($c->id, 'banca', 'Conto corrente', '1010');
    $contanti = ccfCassa($c->id, 'contanti', 'Cassa contanti', '1011');
    $fondo = ccfCassa($c->id, 'fondo', 'Fondo lavori', '1012');
    [$b, $k, $f] = [$banca->conto_contabile_id, $contanti->conto_contabile_id, $fondo->conto_contabile_id];
    ccfScrittura($ctx, $es2025, '2025-01-01', 'apertura', $b, $ctx['passate'], 500000);
    ccfScrittura($ctx, $es2025, '2025-03-01', 'incasso_rata', $b, $ctx['crediti'], 200000);
    ccfScrittura($ctx, $es2025, '2025-04-01', 'giroconto', $f, $b, 100000);
    ccfScrittura($ctx, $es2025, '2025-05-01', 'giroconto', $k, $b, 30000);
    ccfScrittura($ctx, $es2025, '2025-06-01', 'pagamento_fornitore', $ctx['debiti'], $b, 70000);
    ccfScrittura($ctx, $es2025, '2025-07-01', 'regolazione_immediata', $ctx['costi'], $k, 10000);
    ccfScrittura($ctx, $es2026, '2026-02-01', 'incasso_rata', $b, $ctx['crediti'], 50000);

    return $ctx + compact('es2025', 'es2026', 'banca', 'contanti', 'fondo');
}

it('una banca con il fondo e i contanti: il conto chiude con il fondo dentro, € 6.000,00; sotto, il fondo € 1.000,00 e la parte libera € 5.000,00', function () {
    $s = ccfUnaBanca();
    $r = app(RiepilogoFinanziarioService::class)->perEsercizio($s['es2025'], '2026-09-12');
    $conti = ccfConti($r);

    // Il conto: iniziale 5.000 (l'apertura) + il fondo 0; entrate con l'esterno 2.000; uscite 300 (ai contanti) + 700 = 1.000; finale 6.000 = 5.000 + 1.000.
    expect(array_keys($conti))->toBe(['Conto corrente', 'Cassa contanti'])
        ->and(ccfCifre($conti['Conto corrente']))->toBe([500000, 200000, 100000, 600000])
        ->and($conti['Conto corrente']['interni'])->toBe(100000)
        // Il fondo: 0, accantonati 1.000, usati 0, finale 1.000; la parte libera: 5.000, 2.000, 2.000 (con l'accantonamento), 5.000.
        ->and(array_keys($conti['Conto corrente']['per_nome']))->toBe(['Fondo lavori', 'libero'])
        ->and(ccfCifre($conti['Conto corrente']['per_nome']['Fondo lavori']))->toBe([0, 100000, 0, 100000])
        ->and(ccfCifre($conti['Conto corrente']['per_nome']['libero']))->toBe([500000, 200000, 200000, 500000])
        // I contanti: 0, 300, 100, 200, senza righe «di cui».
        ->and(ccfCifre($conti['Cassa contanti']))->toBe([0, 30000, 10000, 20000])
        ->and($conti['Cassa contanti']['di_cui'])->toBe([])
        // Il prelievo di 300 è nelle due righe e non nel piede.
        ->and($r['fra_le_casse'])->toBe(30000);
    ccfSiSommano($r);
});

it('l\'anno dopo il conto parte da € 6.000,00, con il fondo già aperto: il riporto del fondo è nel conto', function () {
    $s = ccfUnaBanca();
    $r = app(RiepilogoFinanziarioService::class)->perEsercizio($s['es2026'], '2026-09-12');
    $conti = ccfConti($r);

    // Conto: 5.000 + 1.000 di riporto; entrate 500; finale 6.500. Fondo 1.000 fermo; libero 5.000 + 500 = 5.500. Contanti 200 fermi.
    expect(ccfCifre($conti['Conto corrente']))->toBe([600000, 50000, 0, 650000])
        ->and(ccfCifre($conti['Conto corrente']['per_nome']['Fondo lavori']))->toBe([100000, 0, 0, 100000])
        ->and(ccfCifre($conti['Conto corrente']['per_nome']['libero']))->toBe([500000, 50000, 0, 550000])
        ->and($conti['Conto corrente']['interni'])->toBe(0)
        ->and(ccfCifre($conti['Cassa contanti']))->toBe([20000, 0, 0, 20000]);
    ccfSiSommano($r);
});

it('con un fondo aperto con un saldo suo e un conto in banca solo: il fondo è nel conto, come nel controllo della banca', function () {
    $ctx = ccfBase();
    $c = $ctx['condominio'];
    $es = Esercizio::factory()->create(['condominio_id' => $c->id, 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $banca = ccfCassa($c->id, 'banca', 'Conto corrente', '1010');
    $fondo = ccfCassa($c->id, 'fondo', 'Fondo riserva', '1012');
    ccfScrittura($ctx, $es, '2026-01-01', 'apertura', $banca->conto_contabile_id, $ctx['passate'], 500000);
    ccfScrittura($ctx, $es, '2026-01-01', 'apertura', $fondo->conto_contabile_id, $ctx['passate'], 30000);
    ccfScrittura($ctx, $es, '2026-03-01', 'giroconto', $fondo->conto_contabile_id, $banca->conto_contabile_id, 100000);
    $r = app(RiepilogoFinanziarioService::class)->perEsercizio($es, '2027-01-15');
    $conti = ccfConti($r);

    // Conto: 5.000 + 300 di apertura del fondo; nessun flusso con l'esterno; finale 5.300 = libero 4.000 + fondo 1.300.
    expect(ccfCifre($conti['Conto corrente']))->toBe([530000, 0, 0, 530000])
        ->and(ccfCifre($conti['Conto corrente']['per_nome']['Fondo riserva']))->toBe([30000, 100000, 0, 130000])
        ->and(ccfCifre($conti['Conto corrente']['per_nome']['libero']))->toBe([500000, 0, 100000, 400000])
        ->and($conti['Conto corrente']['interni'])->toBe(100000);
    ccfSiSommano($r);

    $pagina = app(StatoPatrimonialePaginaService::class)->costruisci($c, $es, '2027-01-15');
    $banche = app(SaldiDelleBanche::class)->confronto($c, [], $pagina);
    expect($banche[0]['contabilita'])->toBe($conti['Conto corrente']['finale']);
});

it('due conti in banca, ognuno con il suo fondo: ogni conto ha il suo; un fondo aperto con un saldo suo non è nel conto di una banca, e ha la sua riga', function () {
    $ctx = ccfBase();
    $c = $ctx['condominio'];
    $es = Esercizio::factory()->create(['condominio_id' => $c->id, 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $a = ccfCassa($c->id, 'banca', 'Banca A', '1010');
    $b = ccfCassa($c->id, 'banca', 'Banca B', '1011');
    $fa = ccfCassa($c->id, 'fondo', 'Fondo A', '1012');
    $fb = ccfCassa($c->id, 'fondo', 'Fondo B', '1013');
    $fx = ccfCassa($c->id, 'fondo', 'Fondo X', '1014');
    ccfScrittura($ctx, $es, '2026-01-01', 'apertura', $a->conto_contabile_id, $ctx['passate'], 500000);
    ccfScrittura($ctx, $es, '2026-01-01', 'apertura', $b->conto_contabile_id, $ctx['passate'], 200000);
    ccfScrittura($ctx, $es, '2026-01-01', 'apertura', $fx->conto_contabile_id, $ctx['passate'], 30000);
    ccfScrittura($ctx, $es, '2026-02-01', 'giroconto', $fa->conto_contabile_id, $a->conto_contabile_id, 100000);
    ccfScrittura($ctx, $es, '2026-02-02', 'giroconto', $fb->conto_contabile_id, $b->conto_contabile_id, 40000);
    ccfScrittura($ctx, $es, '2026-03-01', 'incasso_rata', $a->conto_contabile_id, $ctx['crediti'], 60000);
    ccfScrittura($ctx, $es, '2026-04-01', 'pagamento_fornitore', $ctx['debiti'], $b->conto_contabile_id, 10000);
    $r = app(RiepilogoFinanziarioService::class)->perEsercizio($es, '2027-01-15');
    $conti = ccfConti($r);

    // A: 5.000; +600; finale 5.600 = A 4.600 + Fondo A 1.000. B: 2.000; −100; finale 1.900 = B 1.500 + Fondo B 400. Fondo X 300, senza una banca.
    expect(array_keys($conti))->toBe(['Banca A', 'Banca B', 'Fondo X'])
        ->and(ccfCifre($conti['Banca A']))->toBe([500000, 60000, 0, 560000])
        ->and(array_keys($conti['Banca A']['per_nome']))->toBe(['Fondo A', 'libero'])
        ->and(ccfCifre($conti['Banca A']['per_nome']['Fondo A']))->toBe([0, 100000, 0, 100000])
        ->and(ccfCifre($conti['Banca A']['per_nome']['libero']))->toBe([500000, 60000, 100000, 460000])
        ->and(ccfCifre($conti['Banca B']))->toBe([200000, 0, 10000, 190000])
        ->and(ccfCifre($conti['Banca B']['per_nome']['Fondo B']))->toBe([0, 40000, 0, 40000])
        ->and(ccfCifre($conti['Fondo X']))->toBe([30000, 0, 0, 30000])
        ->and($conti['Fondo X']['senza_banca'])->toBeTrue()
        ->and($conti['Banca A']['senza_banca'])->toBeFalse();
    ccfSiSommano($r);

    $pagina = app(StatoPatrimonialePaginaService::class)->costruisci($c, $es, '2027-01-15');
    $banche = collect(app(SaldiDelleBanche::class)->confronto($c, [], $pagina))->keyBy('cassa');
    expect($banche['Banca A']['contabilita'])->toBe(560000)->and($banche['Banca B']['contabilita'])->toBe(190000);
});

it('una banca chiusa e svuotata non conta: con l\'altra banca sola, il fondo accantonato dalla banca chiusa è nel conto di quella che resta', function () {
    $ctx = ccfBase();
    $c = $ctx['condominio'];
    $es2025 = Esercizio::factory()->create(['condominio_id' => $c->id, 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'chiuso']);
    $es2026 = Esercizio::factory()->create(['condominio_id' => $c->id, 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $vecchia = ccfCassa($c->id, 'banca', 'Banca chiusa', '1010', attiva: false);
    $nuova = ccfCassa($c->id, 'banca', 'Banca nuova', '1011');
    $fondo = ccfCassa($c->id, 'fondo', 'Fondo lavori', '1012');
    ccfScrittura($ctx, $es2025, '2025-01-01', 'apertura', $vecchia->conto_contabile_id, $ctx['passate'], 500000);
    ccfScrittura($ctx, $es2025, '2025-06-01', 'giroconto', $fondo->conto_contabile_id, $vecchia->conto_contabile_id, 100000);
    ccfScrittura($ctx, $es2026, '2026-02-01', 'giroconto', $nuova->conto_contabile_id, $vecchia->conto_contabile_id, 400000);
    $r = app(RiepilogoFinanziarioService::class)->perEsercizio($es2026, '2027-01-15');
    $conti = ccfConti($r);

    // La banca chiusa: 4.000 di riporto, 4.000 alla nuova, finale 0. La nuova: 0 + il fondo 1.000; +4.000; finale 5.000 = 4.000 + 1.000.
    expect(ccfCifre($conti['Banca chiusa']))->toBe([400000, 0, 400000, 0])
        ->and($conti['Banca chiusa']['di_cui'])->toBe([])
        ->and(ccfCifre($conti['Banca nuova']))->toBe([100000, 400000, 0, 500000])
        ->and(ccfCifre($conti['Banca nuova']['per_nome']['Fondo lavori']))->toBe([100000, 0, 0, 100000])
        ->and($r['fra_le_casse'])->toBe(400000);
    ccfSiSommano($r);

    $pagina = app(StatoPatrimonialePaginaService::class)->costruisci($c, $es2026, '2027-01-15');
    $banche = collect(app(SaldiDelleBanche::class)->confronto($c, [], $pagina))->keyBy('cassa');
    expect($banche->keys()->all())->toBe(['Banca nuova'])->and($banche['Banca nuova']['contabilita'])->toBe(500000);
});

it('la regola di quale banca conta è una sola: attiva, o con un saldo, o con l\'estratto scritto', function () {
    expect(SaldiDelleBanche::contaIlConto(true, 0, false))->toBeTrue()
        ->and(SaldiDelleBanche::contaIlConto(false, 0, false))->toBeFalse()
        ->and(SaldiDelleBanche::contaIlConto(false, 100, false))->toBeTrue()
        ->and(SaldiDelleBanche::contaIlConto(false, 0, true))->toBeTrue();
});

it('un giroconto fra due fondi di banche diverse: il conto di ogni banca non cambia, e i due fondi dicono lo spostamento nelle loro righe', function () {
    $ctx = ccfBase();
    $c = $ctx['condominio'];
    $es = Esercizio::factory()->create(['condominio_id' => $c->id, 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $a = ccfCassa($c->id, 'banca', 'Banca A', '1010');
    $b = ccfCassa($c->id, 'banca', 'Banca B', '1011');
    $fa = ccfCassa($c->id, 'fondo', 'Fondo A', '1012');
    $fb = ccfCassa($c->id, 'fondo', 'Fondo B', '1013');
    ccfScrittura($ctx, $es, '2026-01-01', 'apertura', $a->conto_contabile_id, $ctx['passate'], 500000);
    ccfScrittura($ctx, $es, '2026-01-01', 'apertura', $b->conto_contabile_id, $ctx['passate'], 200000);
    ccfScrittura($ctx, $es, '2026-02-01', 'giroconto', $fa->conto_contabile_id, $a->conto_contabile_id, 100000);
    ccfScrittura($ctx, $es, '2026-02-02', 'giroconto', $fb->conto_contabile_id, $b->conto_contabile_id, 40000);
    ccfScrittura($ctx, $es, '2026-05-01', 'giroconto', $fb->conto_contabile_id, $fa->conto_contabile_id, 20000);
    // Un giroconto fra due fondi della stessa banca, dopo: resta nel conto di quella banca.
    $fa2 = ccfCassa($c->id, 'fondo', 'Fondo A2', '1015');
    ccfScrittura($ctx, $es, '2026-06-01', 'giroconto', $fa2->conto_contabile_id, $a->conto_contabile_id, 10000);
    ccfScrittura($ctx, $es, '2026-06-02', 'giroconto', $fa2->conto_contabile_id, $fa->conto_contabile_id, 5000);
    $r = app(RiepilogoFinanziarioService::class)->perEsercizio($es, '2027-01-15');
    $conti = ccfConti($r);

    // Banca A: 5.000 = 3.900 sul conto + 950 nel Fondo A + 150 nel Fondo A2 (il passaggio di 50 fra i due fondi di A resta in A).
    // Banca B: 2.000 = 1.600 + 400. Il passaggio di 200 dal Fondo A al Fondo B non dice in quale banca stia: due righe a parte, −200 e +200.
    expect(ccfCifre($conti['Banca A']))->toBe([500000, 0, 0, 500000])
        ->and(ccfCifre($conti['Banca A']['per_nome']['Fondo A']))->toBe([0, 100000, 5000, 95000])
        ->and(ccfCifre($conti['Banca A']['per_nome']['Fondo A2']))->toBe([0, 15000, 0, 15000])
        ->and(ccfCifre($conti['Banca B']))->toBe([200000, 0, 0, 200000])
        ->and(ccfCifre($conti['Fondo A']))->toBe([0, 0, 20000, -20000])
        ->and(ccfCifre($conti['Fondo B']))->toBe([0, 20000, 0, 20000]);
    ccfSiSommano($r);
    $pagina = app(StatoPatrimonialePaginaService::class)->costruisci($c, $es, '2027-01-15');
    $banche = collect(app(SaldiDelleBanche::class)->confronto($c, [], $pagina))->keyBy('cassa');
    expect($banche['Banca A']['contabilita'])->toBe(500000)->and($banche['Banca B']['contabilita'])->toBe(200000);
});

it('il registro conta come la scheda: la banca che resta ha nel riporto il fondo accantonato dalla banca chiusa, e «Come è composta» lo dice', function () {
    $ctx = ccfBase();
    $c = $ctx['condominio'];
    $es2025 = Esercizio::factory()->create(['condominio_id' => $c->id, 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'chiuso']);
    $es2026 = Esercizio::factory()->create(['condominio_id' => $c->id, 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $vecchia = ccfCassa($c->id, 'banca', 'Banca chiusa', '1010', attiva: false);
    $nuova = ccfCassa($c->id, 'banca', 'Banca nuova', '1011');
    $fondo = ccfCassa($c->id, 'fondo', 'Fondo lavori', '1012');
    ccfScrittura($ctx, $es2025, '2025-01-01', 'apertura', $vecchia->conto_contabile_id, $ctx['passate'], 500000);
    ccfScrittura($ctx, $es2025, '2025-06-01', 'giroconto', $fondo->conto_contabile_id, $vecchia->conto_contabile_id, 100000);
    ccfScrittura($ctx, $es2026, '2026-02-01', 'giroconto', $nuova->conto_contabile_id, $vecchia->conto_contabile_id, 400000);
    \Illuminate\Support\Carbon::setTestNow('2027-01-15 10:00:00');
    $registro = app(\App\Services\Gestionale\RegistroContabilitaService::class);

    // La banca nuova: il riporto è il fondo accantonato dalla banca chiusa nel 2025, € 1.000,00; più i € 4.000,00 arrivati: € 5.000,00, come la scheda.
    $r = $registro->registroConRiepilogo($es2026, ['cassa' => $nuova->id]);
    expect($r['riporto']['totale'])->toBe(100000)->and($r['saldo_finale'])->toBe(500000)
        ->and($r['composizione'])->toBe(['movimenti' => 400000, 'voce_movimenti' => 'I movimenti di «Banca nuova» nel registro',
            'parti' => [['voce' => '«Fondo lavori», accantonato da «Banca chiusa»', 'importo' => 100000, 'quando' => 'inizio']], 'saldo' => 500000]);
    // La banca chiusa: i suoi movimenti fanno € 1.000,00 (il riporto 5.000 senza l'accantonamento, meno 4.000), meno il fondo che è nel conto della nuova: zero.
    $v = $registro->registroConRiepilogo($es2026, ['cassa' => $vecchia->id]);
    expect($v['riporto']['totale'])->toBe(400000)->and($v['saldo_finale'])->toBe(0)
        ->and($v['composizione']['movimenti'])->toBe(100000)
        ->and($v['composizione']['parti'])->toBe([['voce' => '«Fondo lavori», accantonato da qui: è nel conto «Banca nuova»', 'importo' => -100000, 'quando' => 'inizio']]);
    // Senza filtro il totale è lo stesso del prospetto: € 5.000,00.
    expect($registro->registroConRiepilogo($es2026)['saldo_finale'])->toBe(500000)
        ->and(app(RiepilogoFinanziarioService::class)->perEsercizio($es2026, '2027-01-15')['totale']['finale'])->toBe(500000);
    \Illuminate\Support\Carbon::setTestNow();
});

// ─── Le casse sotto zero guardano il conto con i fondi dentro ───────────────────────────────────────────────────────────

/** Una banca aperta a € 1.000,00 che accantona € 800,00 nel fondo il 01/03 e paga `$paga` il 01/06. */
function ccfSottoZero(int $paga): array
{
    $ctx = ccfBase();
    $c = $ctx['condominio'];
    $es = Esercizio::factory()->create(['condominio_id' => $c->id, 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $banca = ccfCassa($c->id, 'banca', 'Conto corrente', '1010');
    $fondo = ccfCassa($c->id, 'fondo', 'Fondo lavori', '1012');
    ccfScrittura($ctx, $es, '2026-01-01', 'apertura', $banca->conto_contabile_id, $ctx['passate'], 100000);
    ccfScrittura($ctx, $es, '2026-03-01', 'giroconto', $fondo->conto_contabile_id, $banca->conto_contabile_id, 80000);
    ccfScrittura($ctx, $es, '2026-06-01', 'pagamento_fornitore', $ctx['debiti'], $banca->conto_contabile_id, $paga);
    $r = app(RiepilogoFinanziarioService::class)->perEsercizio($es, '2027-01-15');
    $cn = collect(app(StatoPatrimonialePaginaService::class)->costruisci($c, $es, '2027-01-15')['controlli'])->firstWhere('id', 'CN');

    return [$r, $cn, collect($r['conti'])->firstWhere('cassa', 'Conto corrente')];
}

it('tutto sopra zero: la banca con il fondo e la sua parte libera; il controllo torna', function () {
    // Paga € 150,00: la parte libera 1.000 − 800 − 150 = 50, il conto 850.
    [$r, $cn, $conto] = ccfSottoZero(15000);
    expect($r['casse_reali_negative'])->toBe([])->and($r['liberi_negativi'])->toBe([])
        ->and($conto)->toMatchArray(['negativa' => false, 'libero_negativo' => false, 'finale' => 85000])
        ->and($cn)->toMatchArray(['esito' => 'quadra', 'sinistra' => 0, 'liberi' => 0, 'rimedio' => null]);
});

it('la parte libera sotto zero con il conto sopra zero: non è una cassa sotto zero, è un avviso a parte con la sua frase', function () {
    // Paga € 500,00: la parte libera 1.000 − 800 − 500 = −300 il 01/06; il conto con il fondo 1.000 − 500 = 500, mai sotto zero.
    [$r, $cn, $conto] = ccfSottoZero(50000);
    expect($r['casse_reali_negative'])->toBe([])
        ->and($r['liberi_negativi'])->toHaveCount(1)
        ->and($r['liberi_negativi'][0])->toMatchArray(['cassa' => 'Conto corrente', 'minimo' => -30000, 'data' => '2026-06-01'])
        ->and($conto)->toMatchArray(['negativa' => false, 'minimo' => 50000, 'libero_negativo' => true, 'libero_minimo' => -30000, 'libero_minimo_il' => '2026-06-01'])
        ->and($cn)->toMatchArray(['esito' => 'segnalazione', 'sinistra' => 0, 'liberi' => 1, 'azione' => 'giroconti',
            'rimedio' => StatoPatrimonialePaginaService::RIMEDIO_LIBERO_SOTTO_ZERO])
        ->and($cn['nota'])->toBe('Il conto «Conto corrente» con i suoi fondi non è mai sceso sotto zero, ma la sua parte libera sì: € -300,00 il 01/06/2026. '
            .'Vuol dire che un pagamento ha usato il denaro di un fondo, che sta sullo stesso conto.')
        ->and(StatoPatrimonialePaginaService::RIMEDIO_LIBERO_SOTTO_ZERO)->toStartWith('Di solito è un pagamento fatto con il denaro di un fondo senza il giroconto prima.')
        ->and($cn['nota'].' '.$cn['rimedio'])->not->toMatch('/errore|sbagliat|programma/i');
});

it('il conto sotto zero con il fondo dentro: è la cassa sotto zero, con il minimo della banca vera', function () {
    // Paga € 1.500,00: la parte libera 1.000 − 800 − 1.500 = −1.300; il conto con il fondo 1.000 − 1.500 = −500 il 01/06.
    [$r, $cn, $conto] = ccfSottoZero(150000);
    expect($r['casse_reali_negative'])->toHaveCount(1)
        ->and($r['casse_reali_negative'][0])->toMatchArray(['cassa' => 'Conto corrente', 'minimo' => -50000, 'data' => '2026-06-01'])
        ->and($r['liberi_negativi'])->toBe([])
        ->and($conto)->toMatchArray(['negativa' => true, 'minimo' => -50000, 'minimo_il' => '2026-06-01', 'libero_negativo' => false])
        ->and($cn)->toMatchArray(['esito' => 'non_quadra', 'sinistra' => 1, 'liberi' => 0])
        ->and($cn['nota'])->toStartWith('Conto corrente a € -500,00 il 01/06/2026.');
});

// ─── Il tipo di ogni fondo e quanto si può usare ───────────────────────────────────────────────────────────────────────

/** Un fondo con il suo tipo scritto com'è nella tabella (anche senza tipo, come un fondo di prima che il tipo esistesse). */
function ccfTipo(Cassa $fondo, ?string $sottotipo, ?string $vincolo = null, bool $sbloccato = false, ?string $motivazione = null): void
{
    DB::table('casse')->where('id', $fondo->id)->update(['sottotipo_fondo' => $sottotipo, 'vincolo_descrizione' => $vincolo, 'is_override_assemblea' => $sbloccato,
        'motivazione_override' => $motivazione]);
}

it('ogni tipo di fondo ha la sua etichetta e il suo posto in «Si può usare»: la parte libera, i generici e gli sbloccati sì; i vincolati, il TFR, la morosità e i fondi senza tipo no', function () {
    $ctx = ccfBase();
    $c = $ctx['condominio'];
    $es = Esercizio::factory()->create(['condominio_id' => $c->id, 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $banca = ccfCassa($c->id, 'banca', 'Conto corrente', '1010');
    ccfScrittura($ctx, $es, '2026-01-01', 'apertura', $banca->conto_contabile_id, $ctx['passate'], 1000000);
    $fondi = [];
    foreach ([['Generico', 10000, 'generico'], ['Lavori', 20000, 'vincolato_lavori', 'rifacimento della facciata'], ['Senza descrizione', 25000, 'vincolato_lavori'],
        ['TFR portiere', 30000, 'tfr'], ['Morosi', 40000, 'morosita'], ['Sbloccato', 50000, 'vincolato_lavori', 'tetto', true, 'urgenza approvata il 10/03'],
        ['Vecchio', 60000, null]] as $i => $f) {
        $fondi[$f[0]] = ccfCassa($c->id, 'fondo', $f[0], (string) (1300 + $i));
        ccfScrittura($ctx, $es, '2026-02-01', 'giroconto', $fondi[$f[0]]->conto_contabile_id, $banca->conto_contabile_id, $f[1]);
        ccfTipo($fondi[$f[0]], $f[2], $f[3] ?? null, $f[4] ?? false, $f[5] ?? null);
    }
    $r = app(RiepilogoFinanziarioService::class)->perEsercizio($es, '2027-01-15');
    $conto = collect($r['conti'])->firstWhere('cassa', 'Conto corrente');
    $per = collect($conto['di_cui'])->keyBy(fn ($d) => $d['cassa'] ?? 'libero');

    // Le etichette, una per tipo, e il loro tono.
    expect(collect($per)->except('libero')->map(fn ($d) => [$d['tipo_fondo']['testo'], $d['tipo_fondo']['tono'], $d['usabile']])->all())->toBe([
        'Generico' => ['generico, si può usare', 'libero', true],
        'Lavori' => ['vincolato ai lavori: rifacimento della facciata', 'vincolato', false],
        'Morosi' => ['morosità', 'vincolato', false],
        'Sbloccato' => ['vincolato, sbloccato dall\'assemblea: urgenza approvata il 10/03', 'sbloccato', true],
        'Senza descrizione' => ['vincolato ai lavori', 'vincolato', false],
        'TFR portiere' => ['TFR', 'vincolato', false],
        'Vecchio' => ['tipo non indicato: conta come vincolato', 'non_indicato', false],
    ]);
    // La parte libera: 10.000 − 2.350 = 7.650. Si può usare: 7.650 + 100 (generico) + 500 (sbloccato) = 8.250; il conto resta 10.000.
    expect($per['libero']['finale'])->toBe(765000)
        ->and($conto['finale'])->toBe(1000000)
        ->and($conto['si_puo_usare'])->toBe(825000)
        ->and(\App\Services\Gestionale\TipoDelFondo::quantoSiPuoUsare(765000, collect($conto['di_cui'])->filter(fn ($d) => $d['tipo'] === 'fondo')
            ->map(fn ($d) => ['sottotipo' => DB::table('casse')->where('id', $d['cassa_id'])->value('sottotipo_fondo'),
                'sbloccato' => (bool) DB::table('casse')->where('id', $d['cassa_id'])->value('is_override_assemblea'), 'saldo' => $d['finale']])->all()))->toBe(825000);
});

it('la regola di «si può usare» è una sola: quella della spesa imprevista, con il fondo senza tipo che vale vincolato', function () {
    $tipo = \App\Services\Gestionale\TipoDelFondo::class;
    expect($tipo::siPuoUsare('generico', false))->toBeTrue()
        ->and($tipo::siPuoUsare('vincolato_lavori', false))->toBeFalse()
        ->and($tipo::siPuoUsare('vincolato_lavori', true))->toBeTrue()
        ->and($tipo::siPuoUsare('tfr', false))->toBeFalse()
        ->and($tipo::siPuoUsare('morosita', false))->toBeFalse()
        ->and($tipo::siPuoUsare(null, false))->toBeFalse()
        ->and(\App\Services\Gestionale\LiquiditaVincolataService::SOTTOTIPO_NON_INDICATO)->toBe('tipo non indicato');
    // La spesa imprevista legge la stessa regola dal modello.
    $c = Condominio::factory()->create();
    $f = ccfCassa($c->id, 'fondo', 'Fondo', '1100');
    foreach ([['generico', false, true], ['vincolato_lavori', false, false], ['tfr', true, true], [null, false, false]] as [$s, $o, $atteso]) {
        ccfTipo($f, $s, null, $o);
        expect(Cassa::find($f->id)->is_utilizzabile_per_imprevisti)->toBe($atteso);
    }
});

// ─── Gli spostamenti fra la parte libera e i fondi, detti nella riga ──────────────────────────────────────────────────

it('la parte libera dice quanto è andato nei fondi e quanto ne è tornato; il passaggio fra due fondi e quello dalla cassa virtuale non sono né l\'uno né l\'altro', function () {
    $ctx = ccfBase();
    $c = $ctx['condominio'];
    $es = Esercizio::factory()->create(['condominio_id' => $c->id, 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $banca = ccfCassa($c->id, 'banca', 'Conto corrente', '1010');
    $lavori = ccfCassa($c->id, 'fondo', 'Fondo lavori', '1301');
    $riserva = ccfCassa($c->id, 'fondo', 'Fondo riserva', '1302');
    $online = ccfCassa($c->id, 'virtuale', 'Pagamenti online', '1303');
    ccfScrittura($ctx, $es, '2026-01-01', 'apertura', $banca->conto_contabile_id, $ctx['passate'], 1000000);
    ccfScrittura($ctx, $es, '2026-02-01', 'giroconto', $lavori->conto_contabile_id, $banca->conto_contabile_id, 100000);
    ccfScrittura($ctx, $es, '2026-02-02', 'giroconto', $riserva->conto_contabile_id, $banca->conto_contabile_id, 50000);
    ccfScrittura($ctx, $es, '2026-03-01', 'giroconto', $banca->conto_contabile_id, $lavori->conto_contabile_id, 20000);
    ccfScrittura($ctx, $es, '2026-03-02', 'giroconto', $riserva->conto_contabile_id, $lavori->conto_contabile_id, 10000);
    ccfScrittura($ctx, $es, '2026-04-01', 'incasso_rata', $online->conto_contabile_id, $ctx['crediti'], 5000);
    ccfScrittura($ctx, $es, '2026-04-02', 'giroconto', $banca->conto_contabile_id, $online->conto_contabile_id, 5000);
    $r = app(RiepilogoFinanziarioService::class)->perEsercizio($es, '2027-01-15');
    $conto = collect($r['conti'])->firstWhere('cassa', 'Conto corrente');
    $libero = collect($conto['di_cui'])->firstWhere('tipo', 'libero');

    // Messi nei fondi: 1.000 + 500 = 1.500. Tornati dai fondi: 200 dal fondo lavori; i 50 della cassa virtuale tornano in banca ma non da un fondo,
    // e i 100 dal fondo lavori alla riserva restano fra i fondi. Gli spostamenti dentro il conto, in tutto: 1.500 + 200 + 100 + 50 = 1.850.
    // La parte libera: 10.000 + 200 + 50 − 1.500 = 8.750, con le uscite 1.500 tutte verso i fondi e le entrate 250.
    expect([$libero['messi_nei_fondi'], $libero['tornati_dai_fondi'], $conto['interni']])->toBe([150000, 20000, 185000])
        ->and(ccfCifre($libero))->toBe([1000000, 25000, 150000, 875000])
        ->and($libero['uscite'])->toBeGreaterThanOrEqual($libero['messi_nei_fondi'])
        ->and($libero['entrate'])->toBeGreaterThanOrEqual($libero['tornati_dai_fondi']);
    ccfSiSommano($r);
    // Senza fondi nel conto, niente da dire: la parte libera non c'è.
    $solo = ccfBase();
    $es2 = Esercizio::factory()->create(['condominio_id' => $solo['condominio']->id, 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $b2 = ccfCassa($solo['condominio']->id, 'banca', 'Banca sola', '1010');
    ccfScrittura($solo, $es2, '2026-01-01', 'apertura', $b2->conto_contabile_id, $solo['passate'], 100000);
    expect(collect(app(RiepilogoFinanziarioService::class)->perEsercizio($es2, '2027-01-15')['conti'])->firstWhere('cassa', 'Banca sola')['di_cui'])->toBe([]);
});
