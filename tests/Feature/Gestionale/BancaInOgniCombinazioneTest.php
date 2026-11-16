<?php

/**
 * Il saldo della banca in ogni combinazione: la scheda «Cassa» del rendiconto, il registro di contabilità filtrato su quella cassa, la situazione
 * patrimoniale e il controllo della banca dicono la stessa cifra; le righe e le colonne della scheda si sommano; i quindici controlli tornano; il
 * controllo della banca senza il saldo della banca («non calcolato»), con il saldo uguale (a fine esercizio e a metà anno: torna) e con un saldo diverso
 * di una cifra nota («da leggere», con la causa giusta e mai una causa nata da un giroconto verso un fondo, da un fondo o da uno storno).
 *
 * La base è Via Roma (`RendicontoViaRomaScenario.php`, fatta dalle rotte delle pagine): una banca aperta a € 5.000,00, il pagamento con la ritenuta e l'F24,
 * le commissioni (una sulla voce, una senza voce), l'incasso da terzi dell'antenna, la regolazione immediata delle spese della banca, l'incasso parziale di
 * Bice, le rate emesse. La banca al 31/12/2026 = 5.000 + 3.700 + 240 − (2.402,50 + 1.182,50 + 40 + 96) = € 5.219,00. Ogni caso aggiunge i suoi movimenti
 * dalle rotte e dice a mano di quanto cambia la banca. Centesimi.
 */

use App\Models\Esercizio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\ContoContabile;
use App\Services\Gestionale\NotaSinteticaDelRendiconto;
use App\Services\Gestionale\SaldiDelleBanche;
use App\Services\Gestionale\StatoPatrimonialePaginaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/RendicontoViaRomaScenario.php';
require_once __DIR__.'/LetturaDebitiProva.php';

beforeEach(function () {
    $this->user = be52Utente();
    $this->withoutVite();
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
});

/** Una cassa nuova con il suo conto, come la scrive la pagina delle casse: un conto di liquidità tutto suo. */
function bocCassa(array $s, string $tipo, string $nome, int $saldoIniziale = 0, bool $apri = true): Cassa
{
    $conto = ContoContabile::create(['condominio_id' => $s['c']->id, 'codice' => 'B-'.substr(md5($nome.$s['c']->id), 0, 6), 'nome' => $nome, 'tipo' => 'attivo',
        'ruolo' => $tipo === 'fondo' ? 'fondo_riserva' : ($tipo === 'contanti' ? 'cassa_contanti' : 'conto_bancario'), 'categoria' => 'liquidita']);
    $cassa = Cassa::create(['condominio_id' => $s['c']->id, 'conto_contabile_id' => $conto->id, 'nome' => $nome, 'tipo' => $tipo, 'attiva' => true,
        'saldo_iniziale' => $saldoIniziale] + ($tipo === 'fondo' ? ['sottotipo_fondo' => 'generico'] : []));
    if ($apri && $saldoIniziale !== 0) {
        app(\App\Actions\Cassa\RegistraAperturaCassaAction::class)->execute($cassa->fresh());
    }

    return $cassa->fresh();
}

/** Un giroconto dalla rotta della pagina dei giroconti. Restituisce la registrazione. */
function bocGiroconto($test, array $s, Cassa $da, Cassa $a, int $importo, string $data, ?Esercizio $e = null): int
{
    vrmGiorno($test, $data);
    $test->actingAs($test->user)->post(route('admin.gestionale.giroconti.store', [$s['c']]), [
        'gestione_id' => $s['g']->id, 'esercizio_id' => ($e ?? $s['e'])->id, 'cassa_origine_id' => $da->id, 'cassa_destinazione_id' => $a->id,
        'data_operazione' => $data, 'causale' => 'Giroconto '.$da->nome.' → '.$a->nome, 'importo' => $importo / 100,
    ])->assertSessionHasNoErrors();

    return (int) DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'giroconto')->max('id');
}

/** Il giorno in cui si leggono le viste di un esercizio finito: il 15 gennaio dopo. */
function bocDopo($test, Esercizio $e): void
{
    vrmGiorno($test, $e->data_fine->copy()->addDays(15)->format('Y-m-d'));
}

/**
 * Le quattro viste del saldo di una cassa reale: la scheda «Cassa» (dalla sua rotta), il registro filtrato su quella cassa (dalla sua rotta), la
 * situazione patrimoniale (il conto della cassa più le partizioni che le appartengono, dalla fotografia della pagina) e il controllo della banca (dalla
 * rotta dei controlli). Con le righe della scheda, il confronto del registro con la scheda e i controlli.
 */
function bocViste($test, array $s, Esercizio $e, Cassa $cassa): array
{
    $u = $test->actingAs($test->user);
    $scheda = $u->get(route('admin.gestionale.esercizi.rendiconto.cassa', [$s['c'], $e]))->assertOk()->viewData('page')['props']['cassa']['riepilogo'];
    $conto = collect($scheda['conti'])->first(fn ($r) => ! $r['senza_banca'] && $r['cassa_id'] === $cassa->id);
    $registro = $u->get(route('admin.gestionale.esercizi.registro-contabilita.index', [$s['c'], $e]).'?cassa='.$cassa->id.'&da=cassa')->assertOk()->viewData('page')['props'];
    $controlli = $u->get(route('admin.gestionale.esercizi.rendiconto.controlli', [$s['c'], $e]))->assertOk()->viewData('page')['props'];
    $bc = collect($controlli['controlli'])->firstWhere('id', 'BC');
    $pagina = app(StatoPatrimonialePaginaService::class)->costruisci($s['c'], $e);
    $voci = collect($pagina['situazione']['attivo']['gruppi'])->flatMap(fn ($g) => $g['voci'])->keyBy('id');
    $parti = $conto === null ? [] : array_filter($conto['di_cui'], fn ($d) => $d['tipo'] !== 'libero');
    // Il conto nella fotografia, più il saldo di apertura non ancora a giornale (la situazione lo mostra come «Saldi di apertura non ancora registrati»).
    $nelGiornale = fn (int $conto) => (int) ($voci[$conto]['saldo'] ?? 0) + (int) DB::table('casse')->where('conto_contabile_id', $conto)->value('saldo_iniziale');
    $situazione = $nelGiornale((int) $cassa->conto_contabile_id) + (int) array_sum(array_map(fn ($d) => $nelGiornale((int) $d['conto_contabile_id']), $parti));

    return ['scheda' => $conto === null ? null : $conto['finale'], 'registro' => (int) $registro['riepilogo']['saldo_finale'], 'situazione' => $situazione,
        'controllo' => collect($bc['banche'] ?? [])->firstWhere('cassa_id', $cassa->id)['contabilita'] ?? null, 'conto' => $conto, 'riepilogo' => $scheda,
        'confronto' => $registro['confronto_scheda'], 'card' => $registro['riepilogo'], 'composizione' => $registro['riepilogo']['composizione'] ?? null,
        'controlli' => $controlli['controlli'], 'bc' => $bc, 'pagina' => $pagina];
}

/** Quanto si può usare del conto di una cassa reale, dalla scheda «Cassa» (dalla sua rotta), dopo aver dato a un fondo il suo tipo. */
function bocSiPuoUsare($test, array $s, Esercizio $e, Cassa $cassa, ?Cassa $fondo = null, ?string $sottotipo = null): ?int
{
    if ($fondo !== null) {
        DB::table('casse')->where('id', $fondo->id)->update(['sottotipo_fondo' => $sottotipo]);
    }
    $scheda = $test->actingAs($test->user)->get(route('admin.gestionale.esercizi.rendiconto.cassa', [$s['c'], $e]))->assertOk()->viewData('page')['props']['cassa']['riepilogo'];

    return collect($scheda['conti'])->first(fn ($r) => ! $r['senza_banca'] && $r['cassa_id'] === $cassa->id)['si_puo_usare'];
}

/**
 * Il totale delle casse contro la somma delle sole righe delle casse (le righe in grassetto; le righe del gruppo di un conto non contano): la differenza
 * per colonna. Zero nell'iniziale e nel finale; nelle entrate e nelle uscite il passaggio da una cassa all'altra, che il totale non conta.
 */
function bocTotaleDelleCasse(array $r): array
{
    $somma = fn (string $k) => (int) array_sum(array_column($r['conti'], $k));

    return ['iniziale' => $somma('iniziale') - $r['totale']['iniziale'], 'entrate' => $somma('entrate') - $r['totale']['entrate'],
        'uscite' => $somma('uscite') - $r['totale']['uscite'], 'finale' => $somma('finale') - $r['totale']['finale']];
}

/** Ogni riga quadra da sé; le righe «di cui» fanno il conto; le casse fanno il piede (i flussi meno i trasferimenti fra le casse). */
function bocSiSommano(array $r): void
{
    foreach ($r['conti'] as $c) {
        expect($c['iniziale'] + $c['entrate'] - $c['uscite'])->toBe($c['finale']);
        if ($c['di_cui'] !== []) {
            foreach ($c['di_cui'] as $d) {
                expect($d['iniziale'] + $d['entrate'] - $d['uscite'])->toBe($d['finale']);
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
 * Le quattro viste dicono la stessa cifra, quella fatta a mano; le righe e le colonne si sommano; i quindici controlli tornano, salvo quelli detti. Per un
 * esercizio finito il controllo dei vincoli dei fondi (R8) è da leggere per costruzione: i vincoli dichiarati non hanno una data.
 */
function bocUnaCifra($test, array $s, Esercizio $e, Cassa $cassa, int $atteso, array $daLeggere = []): array
{
    $v = bocViste($test, $s, $e, $cassa);
    $daLeggere += $v['pagina']['stato_esercizio'] === 'chiuso' ? ['R8' => 'segnalazione'] : [];
    // Il controllo della banca è delle casse di tipo banca: i contanti non hanno un estratto.
    expect(['scheda' => $v['scheda'], 'registro' => $v['registro'], 'situazione' => $v['situazione'], 'controllo' => $v['controllo']])
        ->toBe(['scheda' => $atteso, 'registro' => $atteso, 'situazione' => $atteso, 'controllo' => $cassa->tipo === 'banca' ? $atteso : null]);
    bocSiSommano($v['riepilogo']);
    bocComposta($v);
    expect($v['controlli'])->toHaveCount(15);
    $esiti = collect($v['controlli'])->reject(fn ($c) => $c['id'] === 'BC')->mapWithKeys(fn ($c) => [$c['id'] => $c['esito']])->all();
    expect(array_filter($esiti, fn ($x, $id) => $x !== ($daLeggere[$id] ?? 'quadra'), ARRAY_FILTER_USE_BOTH))->toBe([]);

    return $v;
}

/** «Come è composta», nel registro filtrato: i movimenti della cassa e le parti dei fondi si sommano al saldo, che è la cifra del registro. */
function bocComposta(array $v): void
{
    expect($v['composizione'])->not->toBeNull()
        ->and($v['composizione']['movimenti'] + (int) array_sum(array_column($v['composizione']['parti'], 'importo')))->toBe($v['composizione']['saldo'])
        ->and($v['composizione']['saldo'])->toBe($v['registro']);
}

/**
 * Il saldo della banca scritto nella nota (o tolto, con `null`), e il controllo della banca e le possibili cause di quel conto. `$altri`: il saldo degli altri
 * conti in banca, per cassa ([data, saldo]), scritto uguale a quello registrato perché il controllo dica di questo conto.
 */
function bocConSaldoDellaBanca($test, array $s, Esercizio $e, Cassa $cassa, ?string $data, ?int $saldo, array $altri = []): array
{
    $c = NotaSinteticaDelRendiconto::contenuto($e);
    $c['banca'] = array_map(fn ($a) => ['data' => $a[0], 'saldo' => $a[1], 'spiegazione' => ''], $altri);
    if ($saldo !== null) {
        $c['banca'][(string) $cassa->id] = ['data' => $data, 'saldo' => $saldo, 'spiegazione' => ''];
    }
    NotaSinteticaDelRendiconto::salva($e, $c, null);
    $props = $test->actingAs($test->user)->get(route('admin.gestionale.esercizi.rendiconto.controlli', [$s['c'], $e]))->assertOk()->viewData('page')['props'];
    $bc = collect($props['controlli'])->firstWhere('id', 'BC');
    $conto = collect($bc['banche'] ?? [])->firstWhere('cassa_id', $cassa->id);

    return ['bc' => $bc, 'conto' => $conto, 'piste' => $conto['piste'] ?? []];
}

/**
 * Il controllo della banca tre volte: senza il saldo della banca; con il saldo uguale alla fine dell'esercizio e a una data a metà anno; con un saldo
 * diverso di `$differenza` alla fine, che deve avere fra le cause `$causa` e nessuna causa nata da un giroconto verso una partizione, da una partizione o
 * da uno storno.
 */
function bocTreVolte($test, array $s, Esercizio $e, Cassa $cassa, int $allaFine, string $meta, int $aMeta, int $differenza = -1250, string $causa = 'spese_banca', array $altri = []): void
{
    $senza = bocConSaldoDellaBanca($test, $s, $e, $cassa, null, null, $altri);
    expect($senza['bc']['esito'])->toBe('non_calcolato')
        ->and($senza['bc']['nota'])->toContain('Il saldo che dice la banca non è scritto nella nota sintetica per «'.$cassa->nome.'»')
        ->and($senza['bc']['rimedio'])->toBe(SaldiDelleBanche::RIMEDIO_SCRIVI)
        ->and(SaldiDelleBanche::RIMEDIO_SCRIVI)->toBe('Scrivi nella nota sintetica il saldo che leggi sull\'estratto conto che ti manda la banca.')
        ->and($senza['bc']['azione'])->toBe('nota');

    $fine = $e->data_fine->format('Y-m-d');
    foreach ([[$fine, $allaFine], [$meta, $aMeta]] as [$data, $saldo]) {
        $uguale = bocConSaldoDellaBanca($test, $s, $e, $cassa, $data, $saldo, $altri);
        expect($uguale['bc']['esito'])->toBe('quadra', "il saldo della banca al {$data}")
            ->and($uguale['conto']['contabilita'])->toBe($saldo)->and($uguale['conto']['differenza'])->toBe(0);
    }

    $diverso = bocConSaldoDellaBanca($test, $s, $e, $cassa, $fine, $allaFine + $differenza, $altri);
    expect($diverso['bc']['esito'])->toBe('segnalazione')
        ->and($diverso['conto']['differenza'])->toBe($differenza)
        ->and(array_column($diverso['piste'], 'tipo'))->toContain($causa);
    bocNessunaCausaFalsa($s, $diverso['piste']);
}

/** Nessuna causa nata da un giroconto verso una partizione, da una partizione o da uno storno: i movimenti di ogni causa sono della banca e veri. */
function bocNessunaCausaFalsa(array $s, array $piste): void
{
    foreach ($piste as $p) {
        foreach ($p['movimenti'] as $m) {
            $tipo = DB::table('scritture_contabili')->where('id', $m['scrittura_id'])->value('tipo_movimento');
            $conPartizione = DB::table('righe_scritture as rs')->join('casse as ca', 'ca.id', '=', 'rs.cassa_id')->where('rs.scrittura_id', $m['scrittura_id'])
                ->whereIn('ca.tipo', ['fondo', 'virtuale'])->exists();
            $stornata = DB::table('scritture_contabili')->where('scrittura_padre_id', $m['scrittura_id'])->whereNull('deleted_at')->exists()
                || DB::table('scritture_contabili')->where('id', $m['scrittura_id'])->value('stato') === 'annullata';
            expect($conPartizione)->toBeFalse('la causa «'.$p['tipo'].'» nasce da un giroconto verso un fondo: '.$p['frase'])
                ->and($stornata)->toBeFalse('la causa «'.$p['tipo'].'» nasce da un movimento stornato')
                ->and(in_array($tipo, [...\App\Enums\TipoMovimentoContabile::storniDiScrittura(), 'rettifica'], true))->toBeFalse('la causa nasce da uno storno');
        }
    }
}

// ─── La banca sola ─────────────────────────────────────────────────────────────────────────────────────────────────────

it('la banca sola (Via Roma, con la ritenuta e l\'F24, le commissioni, l\'antenna, la regolazione, l\'incasso parziale): € 5.219,00 in ogni vista', function () {
    $s = vrmScenario($this);
    bocDopo($this, $s['e']);
    $v = bocUnaCifra($this, $s, $s['e'], $s['cassa'], 521900);

    // Senza fondi la scheda ha la riga della banca e basta: iniziale 5.000 (l'apertura), entrate 3.700 + 240, uscite 2.402,50 + 1.182,50 + 40 + 96.
    expect($v['conto'])->toMatchArray(['iniziale' => 500000, 'entrate' => 394000, 'uscite' => 372100, 'finale' => 521900, 'di_cui' => [], 'interni' => 0])
        ->and($v['riepilogo']['fra_le_casse'])->toBe(0)
        // Il registro di quella cassa ha le sue card: il riporto, le entrate, le uscite, il saldo; e la riga del confronto dice lo stesso saldo.
        ->and($v['card']['riporto']['totale'])->toBe(500000)->and($v['card']['totale_entrate'])->toBe(394000)->and($v['card']['totale_uscite'])->toBe(372100)
        ->and($v['confronto']['differenza'])->toBe(0)
        ->and($v['confronto']['frase'])->toBe('Per tutto l\'esercizio, la scheda Cassa del rendiconto chiude «Banca principale» allo stesso saldo, €'."\u{a0}".'5.219,00.');

    // Al 30/06: 5.000 + 240 (15/02) + 2.055 (Ugo, 31/03) − 96 (30/06) = € 7.199,00.
    bocTreVolte($this, $s, $s['e'], $s['cassa'], 521900, '2026-06-30', 719900);
});

it('un movimento con la data dopo il saldo della banca: l\'F24 del 16/12 con il saldo al 15/12 è la causa giusta, e nessuna è falsa', function () {
    $s = vrmScenario($this);
    bocDopo($this, $s['e']);
    // Al 15/12 in contabilità 5.219 + 40 = 5.259 (l'F24 è del 16/12); la banca dice 5.219 perché l'aveva già: d = −40.
    $r = bocConSaldoDellaBanca($this, $s, $s['e'], $s['cassa'], '2026-12-15', 521900);

    expect($r['conto']['contabilita'])->toBe(525900)->and($r['conto']['differenza'])->toBe(-4000)
        ->and(array_column($r['piste'], 'tipo'))->toBe(['spese_banca', 'dopo_estratto'])
        ->and($r['piste'][1]['movimenti'][0]['importo'])->toBe(-4000)
        ->and($r['piste'][1]['frase'])->toContain('con una data dopo quella del saldo della banca');
    bocNessunaCausaFalsa($s, $r['piste']);
});

// ─── Banca e contanti ──────────────────────────────────────────────────────────────────────────────────────────────────

it('banca e contanti con i giroconti nei due versi: le righe delle due casse si muovono, il totale con l\'esterno no; la stessa cifra in ogni vista', function () {
    $s = vrmScenario($this);
    $contanti = bocCassa($s, 'contanti', 'Cassa contanti');
    bocGiroconto($this, $s, $s['cassa'], $contanti, 30000, '2026-12-17');
    bocGiroconto($this, $s, $contanti, $s['cassa'], 10000, '2026-12-18');
    bocDopo($this, $s['e']);
    // Banca 5.219 − 300 + 100 = € 5.019,00; contanti 300 − 100 = € 200,00.
    $v = bocUnaCifra($this, $s, $s['e'], $s['cassa'], 501900);
    $vc = bocUnaCifra($this, $s, $s['e'], $contanti, 20000);
    // Le card del registro filtrato sono di quella cassa: la banca con i due giroconti, i contanti con i loro.
    expect([$v['card']['totale_entrate'], $v['card']['totale_uscite']])->toBe([394000 + 10000, 372100 + 30000])
        ->and([$vc['card']['riporto']['totale'], $vc['card']['totale_entrate'], $vc['card']['totale_uscite']])->toBe([0, 30000, 10000]);

    $contantiNellaScheda = collect($v['riepilogo']['conti'])->firstWhere('cassa_id', $contanti->id);
    expect($v['conto'])->toMatchArray(['entrate' => 394000 + 10000, 'uscite' => 372100 + 30000, 'di_cui' => []])
        ->and($contantiNellaScheda)->toMatchArray(['iniziale' => 0, 'entrate' => 30000, 'uscite' => 10000, 'finale' => 20000])
        ->and($v['riepilogo']['fra_le_casse'])->toBe(40000)
        ->and($v['riepilogo']['totale'])->toBe(['iniziale' => 500000, 'entrate' => 394000, 'uscite' => 372100, 'finale' => 521900])
        // Senza fondi nessun conto ha il suo gruppo; il totale delle casse è la somma delle due righe, meno i € 400,00 passati da una cassa all'altra.
        ->and($contantiNellaScheda['di_cui'])->toBe([])
        ->and(bocTotaleDelleCasse($v['riepilogo']))->toBe(['iniziale' => 0, 'entrate' => 40000, 'uscite' => 40000, 'finale' => 0]);
    bocTreVolte($this, $s, $s['e'], $s['cassa'], 501900, '2026-06-30', 719900);
});

// ─── Banca e fondo: ogni giroconto ─────────────────────────────────────────────────────────────────────────────────────

it('banca e fondi: l\'accantonamento, il ritorno in banca, il passaggio fra due fondi e un giroconto annullato non muovono la banca: € 5.219,00 dappertutto', function () {
    $s = vrmScenario($this);
    $lavori = bocCassa($s, 'fondo', 'Fondo lavori');
    $riserva = bocCassa($s, 'fondo', 'Fondo riserva');
    bocGiroconto($this, $s, $s['cassa'], $lavori, 150000, '2026-12-17');
    bocGiroconto($this, $s, $lavori, $s['cassa'], 20000, '2026-12-18');
    bocGiroconto($this, $s, $lavori, $riserva, 30000, '2026-12-19');
    $annullato = bocGiroconto($this, $s, $s['cassa'], $riserva, 5000, '2026-12-20');
    $this->actingAs($this->user)->post(route('admin.gestionale.giroconti.storno', [$s['c'], $annullato]), ['motivo' => 'Registrato per sbaglio due volte'])->assertSessionHasNoErrors();
    bocDopo($this, $s['e']);
    $v = bocUnaCifra($this, $s, $s['e'], $s['cassa'], 521900);

    // Il conto: come Via Roma (i giroconti con i fondi si compensano dentro il conto). Sotto: lavori 1.500 − 200 − 300 = 1.000; riserva 300;
    // libero 5.219 − 1.300 = 3.919. Il giroconto annullato e il suo storno, nello stesso esercizio, non contano. Spostamenti interni 1.500 + 200 + 300.
    $per = collect($v['conto']['di_cui'])->keyBy(fn ($d) => $d['cassa'] ?? 'libero');
    expect($v['conto'])->toMatchArray(['iniziale' => 500000, 'entrate' => 394000, 'uscite' => 372100, 'finale' => 521900, 'interni' => 200000])
        ->and(array_values(array_intersect_key($per['Fondo lavori'], array_flip(['iniziale', 'entrate', 'uscite', 'finale']))))->toBe([0, 150000, 50000, 100000])
        ->and(array_values(array_intersect_key($per['Fondo riserva'], array_flip(['iniziale', 'entrate', 'uscite', 'finale']))))->toBe([0, 30000, 0, 30000])
        ->and($per['libero']['finale'])->toBe(391900)
        // Detti nella riga della parte libera: € 1.500,00 messi nei fondi, € 200,00 tornati dal fondo lavori; il passaggio fra i fondi (€ 300,00) e il
        // giroconto annullato no.
        ->and([$per['libero']['messi_nei_fondi'], $per['libero']['tornati_dai_fondi']])->toBe([150000, 20000])
        ->and($v['riepilogo']['fra_le_casse'])->toBe(0)
        // Il registro non ha gli accantonamenti, la scheda sì nelle righe «di cui»: la riga del confronto lo dice.
        ->and($v['confronto']['frase'])->toContain('Gli spostamenti fra il conto e i suoi fondi (€'."\u{a0}".'2.000,00) qui non compaiono');

    bocTreVolte($this, $s, $s['e'], $s['cassa'], 521900, '2026-06-30', 719900);
    // La banca dice € 1.500,00 in più: l'importo dell'accantonamento del 17/12, vicino alla data. L'accantonamento non è una causa: il denaro è sullo
    // stesso conto, e la contabilità del conto lo ha già.
    $piu = bocConSaldoDellaBanca($this, $s, $s['e'], $s['cassa'], '2026-12-31', 521900 + 150000);
    expect($piu['conto']['differenza'])->toBe(150000);
    bocNessunaCausaFalsa($s, $piu['piste']);
    // Lo stesso per il ritorno di € 200,00 dal fondo (la banca ha di meno di quanto è tornato) e per il passaggio fra i fondi.
    foreach ([-20000, 30000, 5000, -5000] as $d) {
        bocNessunaCausaFalsa($s, bocConSaldoDellaBanca($this, $s, $s['e'], $s['cassa'], '2026-12-31', 521900 + $d)['piste']);
    }
    // Si può usare: con i due fondi generici, tutto il conto (3.919 + 1.000 + 300 = € 5.219,00); con il fondo dei lavori vincolato ai lavori, la parte
    // libera e la riserva (3.919 + 300 = € 4.219,00); con il fondo dei lavori senza tipo, lo stesso: un fondo senza tipo vale vincolato. Il conto resta € 5.219,00.
    expect($v['conto']['si_puo_usare'])->toBe(521900)
        ->and(bocSiPuoUsare($this, $s, $s['e'], $s['cassa'], $lavori, 'vincolato_lavori'))->toBe(421900)
        ->and(bocSiPuoUsare($this, $s, $s['e'], $s['cassa'], $lavori, null))->toBe(421900)
        ->and(bocViste($this, $s, $s['e'], $s['cassa'])['scheda'])->toBe(521900);
});

// ─── Più conti ─────────────────────────────────────────────────────────────────────────────────────────────────────────

it('due conti in banca, ognuno con il suo fondo, e un giroconto fra i due conti: ogni conto ha il suo saldo in ogni vista', function () {
    $s = vrmScenario($this);
    $b = bocCassa($s, 'banca', 'Banca seconda', 200000);
    $fa = bocCassa($s, 'fondo', 'Fondo della prima');
    $fb = bocCassa($s, 'fondo', 'Fondo della seconda');
    bocGiroconto($this, $s, $s['cassa'], $fa, 150000, '2026-12-17');
    bocGiroconto($this, $s, $b, $fb, 40000, '2026-12-17');
    bocGiroconto($this, $s, $s['cassa'], $b, 100000, '2026-12-18');
    bocDopo($this, $s['e']);
    // Prima: 5.219 − 1.000 = € 4.219,00, di cui 1.500 nel suo fondo. Seconda: 2.000 + 1.000 = € 3.000,00, di cui 400 nel suo fondo.
    $va = bocUnaCifra($this, $s, $s['e'], $s['cassa'], 421900);
    $vb = bocUnaCifra($this, $s, $s['e'], $b, 300000);

    expect(collect($va['conto']['di_cui'])->pluck('finale', 'cassa')->all())->toBe(['Fondo della prima' => 150000, '' => 271900])
        ->and(collect($vb['conto']['di_cui'])->pluck('finale', 'cassa')->all())->toBe(['Fondo della seconda' => 40000, '' => 260000])
        ->and($va['riepilogo']['fra_le_casse'])->toBe(100000)
        ->and($va['bc']['banche'][0]['fondi'])->toBe('accantonati')
        // Si può usare, per ogni conto: la sua parte libera e il suo fondo generico (2.719 + 1.500 = € 4.219,00; 2.600 + 400 = € 3.000,00); con il fondo
        // della seconda per il TFR, la seconda ha la sola parte libera (€ 2.600,00) e la prima non cambia.
        ->and([$va['conto']['si_puo_usare'], $vb['conto']['si_puo_usare']])->toBe([421900, 300000])
        // Ogni conto ha il suo gruppo, con quanto la sua parte libera ha messo nel suo fondo; il giroconto fra i due conti non è fra questi.
        ->and(collect($va['conto']['di_cui'])->firstWhere('tipo', 'libero')['messi_nei_fondi'])->toBe(150000)
        ->and(collect($vb['conto']['di_cui'])->firstWhere('tipo', 'libero')['messi_nei_fondi'])->toBe(40000)
        // Il totale delle casse: la somma delle sole righe delle casse; nelle entrate e nelle uscite meno il passaggio fra i due conti, € 1.000,00.
        ->and(bocTotaleDelleCasse($va['riepilogo']))->toBe(['iniziale' => 0, 'entrate' => 100000, 'uscite' => 100000, 'finale' => 0])
        ->and(bocSiPuoUsare($this, $s, $s['e'], $b, $fb, 'tfr'))->toBe(260000)
        ->and(bocSiPuoUsare($this, $s, $s['e'], $s['cassa']))->toBe(421900);
    bocTreVolte($this, $s, $s['e'], $b, 300000, '2026-06-30', 200000, altri: [(string) $s['cassa']->id => ['2026-12-31', 421900]]);
    bocTreVolte($this, $s, $s['e'], $s['cassa'], 421900, '2026-06-30', 719900, altri: [(string) $b->id => ['2026-12-31', 300000]]);
});

// ─── Gli storni ────────────────────────────────────────────────────────────────────────────────────────────────────────

it('lo storno di un incasso e quello di un pagamento nello stesso esercizio: nella scheda si annullano, nel registro ci sono, e il saldo è lo stesso', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2026-12-20');
    $bice = vrmQuote($s, 'bice');
    $incasso = vrmIncassa($this, $s, 'bice', [$bice[10] => 6750], '2026-12-20');
    lcdStorna($this, $s, $incasso);
    $ft = vrmFattura($this, $s, $s['fornitore'], 'FT-STORNO', [[$s['manutenzioni']->id, 80.00]], '2026-12-20');
    $pag = vrmPaga($this, $s, $s['fornitore'], [[$ft, 'pagamento', 8000]], '2026-12-20');
    $this->actingAs($this->user)->post(route('admin.gestionale.pagamenti-fornitori.storno', [$s['c'], $pag]), ['motivo' => 'Bonifico partito per errore'])->assertSessionHasNoErrors();
    bocDopo($this, $s['e']);
    $v = bocUnaCifra($this, $s, $s['e'], $s['cassa'], 521900);

    // Nella scheda le due coppie non contano; il registro mostra 67,50 + 80 fra le entrate e fra le uscite.
    expect($v['conto'])->toMatchArray(['entrate' => 394000, 'uscite' => 372100])
        ->and($v['riepilogo']['stornate']['coppie'])->toBe(2)
        ->and($v['card']['totale_entrate'])->toBe(394000 + 6750 + 8000)->and($v['card']['totale_uscite'])->toBe(372100 + 6750 + 8000)
        ->and($v['confronto']['frase'])->toContain('Qui compaiono i movimenti stornati e i loro storni (€'."\u{a0}".'147,50 fra le entrate e €'."\u{a0}".'147,50 fra le uscite)');
    bocTreVolte($this, $s, $s['e'], $s['cassa'], 521900, '2026-06-30', 719900);
});

it('lo storno di un pagamento nell\'esercizio dopo: il 2026 ha il pagamento, il 2027 lo storno; ogni esercizio ha la sua cifra in ogni vista', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2026-12-20');
    $ft = vrmFattura($this, $s, $s['fornitore'], 'FT-DOPO', [[$s['manutenzioni']->id, 80.00]], '2026-12-20');
    $pag = vrmPaga($this, $s, $s['fornitore'], [[$ft, 'pagamento', 8000]], '2026-12-20');
    DB::table('esercizi')->where('id', $s['e']->id)->update(['stato' => 'chiuso']);
    $e2027 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    DB::table('esercizio_gestione')->insertOrIgnore(['esercizio_id' => $e2027->id, 'gestione_id' => $s['g']->id, 'created_at' => now(), 'updated_at' => now()]);
    vrmGiorno($this, '2027-01-10');
    $this->actingAs($this->user)->post(route('admin.gestionale.pagamenti-fornitori.storno', [$s['c'], $pag]), ['motivo' => 'Bonifico tornato indietro dalla banca'])->assertSessionHasNoErrors();
    bocDopo($this, $s['e']);
    // Il 2026: 5.219 − 80 = € 5.139,00. Il 2027: 5.139 + 80 dello storno = € 5.219,00.
    bocUnaCifra($this, $s, $s['e'], $s['cassa'], 513900);
    bocDopo($this, $e2027);
    $v = bocUnaCifra($this, $s, $e2027, $s['cassa'], 521900);
    expect($v['conto'])->toMatchArray(['iniziale' => 513900, 'entrate' => 8000, 'uscite' => 0]);
    bocTreVolte($this, $s, $e2027, $s['cassa'], 521900, '2027-06-30', 521900);
});

// ─── L'eccedenza e il credito ──────────────────────────────────────────────────────────────────────────────────────────

it('l\'incasso con l\'eccedenza entra in banca; l\'incasso pagato con quel credito non sposta denaro e non è fra le entrate', function () {
    $s = vrmScenario($this);
    $bice = vrmQuote($s, 'bice');
    vrmGiorno($this, '2026-12-20');
    // Bice versa € 117,50: € 67,50 chiudono la rata 10, € 50,00 restano a credito sulla rata.
    vrmIncassa($this, $s, 'bice', [$bice[10] => 6750], '2026-12-20', 5000);
    vrmGiorno($this, '2026-12-21');
    // Il credito di € 50,00 paga una parte della rata 11: in cassa entrano € 0,00.
    $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['persone']['bice']->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2026-12-21', 'importo_totale' => '0.00', 'descrizione' => 'Compensazione',
        'eccedenza' => '0.00', 'gestione_id' => $s['g']->id,
        'dettaglio_pagamenti' => [['rata_id' => $bice[11], 'importo' => '50.00'], ['rata_id' => $bice[10], 'importo' => '-50.00']],
    ])->assertSessionHasNoErrors();
    expect(DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'storno_credito')->count())->toBe(1);
    bocDopo($this, $s['e']);
    // 5.219 + 117,50 = € 5.336,50; le entrate 3.940 + 117,50.
    $v = bocUnaCifra($this, $s, $s['e'], $s['cassa'], 533650);
    expect($v['conto'])->toMatchArray(['entrate' => 394000 + 11750, 'uscite' => 372100])->and($v['card']['totale_entrate'])->toBe(394000 + 11750);
    bocTreVolte($this, $s, $s['e'], $s['cassa'], 533650, '2026-06-30', 719900);
});

// ─── L'accordo, gli interessi, gli addebiti, il passaggio e la successione ─────────────────────────────────────────────

require_once __DIR__.'/AccordoCalendarioScenario.php';

it('gli interessi e le spese emessi, l\'accordo e la successione non muovono la banca; il versamento dell\'accordo sì, di quanto è versato', function () {
    // Via Verdi: la banca aperta a € 1.000,00; Neri versa € 100,00 il 30/04; il 29/06 interessi di € 0,92 e il sollecito di € 25,00 emessi; il
    // 15/07 l'accordo. La banca: 1.000 + 100 = € 1.100,00.
    [$s, $interessi, $sollecito] = aclAccordo($this);
    // Il saldo di apertura a giornale, come chiede la quadratura patrimoniale (Via Verdi lo tiene nella colonna della cassa): la banca non cambia.
    app(\App\Actions\Cassa\RegistraAperturaCassaAction::class)->execute($s['cassa']->fresh());
    $this->travelTo('2027-01-15 10:00:00');
    bocUnaCifra($this, $s, $s['e'], $s['cassa'], 110000);

    // Il 31/07 il versamento dell'accordo di € 75,31: interessi 0,92, sollecito 25,00, rata 1 49,39. La banca: € 1.175,31.
    $this->travelTo('2026-07-31 10:00:00');
    aclVersa($this, $s, [(int) adbQuota($interessi)->id => 92, (int) adbQuota($sollecito)->id => 2500, lcdQuota($s, 'Neri', 1) => 4939], '2026-07-31');
    // Il 01/09 la successione di U1 agli eredi, con l'arretrato: nessun movimento di denaro.
    $eredi = [];
    foreach (['Anna', 'Bruno'] as $nome) {
        $p = \App\Models\Anagrafica::forceCreate(['nome' => $nome, 'email' => strtolower($nome).'-boc-'.$s['c']->id.'@test.it', 'indirizzo' => 'Via Verdi 1',
            'codice_fiscale' => 'BOC'.strtoupper(substr($nome, 0, 3)).str_pad((string) $s['c']->id, 10, '0', STR_PAD_LEFT)]);
        $p->condomini()->syncWithoutDetaching([$s['c']->id]);
        $eredi[] = $p;
    }
    $this->travelTo('2026-09-10 10:00:00');
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['U1']]), ['tipo' => 'successione',
        'riga_uscente_id' => $s['righe']['Neri'], 'decorrenza' => '2026-09-01', 'quota' => 100, 'tipologia' => 'proprietario',
        'eredi' => [['anagrafica_id' => $eredi[0]->id, 'quota' => 50], ['anagrafica_id' => $eredi[1]->id, 'quota' => 50]], 'arretrato' => 'eredi',
        'erede_di_riferimento' => $eredi[0]->id, 'copia_autentica' => false, 'estremi_titolo' => 'dichiarazione di successione n. 1', 'pertinenze' => [],
        'ho_letto' => true, 'nota_cancello' => 'Dichiarazione di successione letta'])->assertSessionHasNoErrors();
    $this->travelTo('2027-01-15 10:00:00');
    $v = bocUnaCifra($this, $s, $s['e'], $s['cassa'], 117531);
    expect($v['conto']['entrate'])->toBe(10000 + 7531);
    bocTreVolte($this, $s, $s['e'], $s['cassa'], 117531, '2026-06-30', 110000);
});

it('il passaggio di un\'unità non muove la banca: Via Verdi con le rate, gli incassi e la vendita di U2, € 4.650,00 in ogni vista', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    cpcPassaggio($this, $s);
    app(\App\Actions\Cassa\RegistraAperturaCassaAction::class)->execute($s['cassa']->fresh());
    $this->travelTo('2027-01-15 10:00:00');
    // 1.000 di apertura + 1.700 + 1.200 + 750 degli incassi del 01/03.
    bocUnaCifra($this, $s, $s['e'], $s['cassa'], 465000);
    bocTreVolte($this, $s, $s['e'], $s['cassa'], 465000, '2026-02-28', 100000);
});

// ─── La struttura ──────────────────────────────────────────────────────────────────────────────────────────────────────

it('una cassa disattivata: la banca chiusa e svuotata non conta più, e i fondi stanno nella banca che resta, nel registro come nella scheda', function () {
    $s = vrmScenario($this);
    $vecchia = bocCassa($s, 'banca', 'Banca chiusa', 200000);
    $fondo = bocCassa($s, 'fondo', 'Fondo lavori');
    bocGiroconto($this, $s, $vecchia, $fondo, 50000, '2026-12-17');
    bocGiroconto($this, $s, $vecchia, $s['cassa'], 150000, '2026-12-18');
    $vecchia->update(['attiva' => false]);
    bocDopo($this, $s['e']);
    // La banca che resta: 5.219 + 1.500 = 6.719, con il fondo di € 500,00 accantonato dalla banca chiusa: € 7.219,00 in tutte le viste. Nel registro i
    // movimenti della banca sono € 6.719,00, e «Come è composta» dice il fondo accantonato dalla banca chiusa, € 500,00.
    $v = bocUnaCifra($this, $s, $s['e'], $s['cassa'], 721900);
    expect($v['composizione'])->toMatchArray(['movimenti' => 671900, 'saldo' => 721900, 'voce_movimenti' => 'I movimenti di «Banca principale» nel registro'])
        ->and($v['composizione']['parti'])->toBe([['voce' => '«Fondo lavori», accantonato da «Banca chiusa» nell\'esercizio', 'importo' => 50000, 'quando' => 'esercizio']])
        ->and($v['confronto']['differenza'])->toBe(0)
        ->and($v['confronto']['frase'])->toContain('Il saldo conta anche il denaro dei fondi che non è passato da questa cassa')
        ->and(collect($v['bc']['banche'])->pluck('cassa')->all())->toBe(['Banca principale']);
    // La banca chiusa: zero nella scheda e nel registro; nel registro i suoi movimenti fanno € 500,00, meno il fondo accantonato da lei, che è nel conto
    // della banca che resta.
    $chiusa = bocViste($this, $s, $s['e'], $vecchia);
    expect($chiusa['scheda'])->toBe(0)->and($chiusa['registro'])->toBe(0)->and($chiusa['controllo'])->toBeNull()
        ->and($chiusa['composizione']['movimenti'])->toBe(50000)
        ->and($chiusa['composizione']['parti'])->toBe([['voce' => '«Fondo lavori», accantonato da qui nell\'esercizio: è nel conto «Banca principale»', 'importo' => -50000, 'quando' => 'esercizio']]);
    bocComposta($chiusa);
    bocTreVolte($this, $s, $s['e'], $s['cassa'], 721900, '2026-06-30', 719900);
});

it('un movimento con la data di un altro esercizio: la scheda e il registro lo contano nell\'esercizio in cui è scritto, la situazione e il controllo alla data; la differenza è il movimento, e la dice il controllo delle registrazioni oltre il confine', function () {
    $s = vrmScenario($this);
    // Un F24 di € 40,00 del 10/01/2027 scritto nell'esercizio 2026 (come nella prova dell'anno a cavallo).
    $id = DB::table('scritture_contabili')->insertGetId(['condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'gestione_id' => $s['g']->id,
        'data_registrazione' => '2027-01-10', 'data_competenza' => '2027-01-10', 'numero_protocollo' => 'BOC-F24', 'causale' => 'F24 di gennaio', 'tipo_movimento' => 'pagamento_f24',
        'stato' => 'registrata', 'created_at' => now(), 'updated_at' => now()]);
    $erario = (int) ContoContabile::where('condominio_id', $s['c']->id)->where('ruolo', 'debiti_erario_ritenute')->value('id');
    DB::table('righe_scritture')->insert([
        ['scrittura_id' => $id, 'conto_contabile_id' => $erario, 'cassa_id' => null, 'tipo_riga' => 'dare', 'importo' => 4000, 'created_at' => now(), 'updated_at' => now()],
        ['scrittura_id' => $id, 'conto_contabile_id' => $s['cassa']->conto_contabile_id, 'cassa_id' => $s['cassa']->id, 'tipo_riga' => 'avere', 'importo' => 4000, 'created_at' => now(), 'updated_at' => now()],
    ]);
    vrmGiorno($this, '2027-02-01');
    $v = bocViste($this, $s, $s['e'], $s['cassa']);
    // Per esercizio: 5.219 − 40 = € 5.179,00. Per data, al 31/12/2026: € 5.219,00. La differenza, € 40,00, è il movimento.
    expect(['scheda' => $v['scheda'], 'registro' => $v['registro'], 'situazione' => $v['situazione'], 'controllo' => $v['controllo']])
        ->toBe(['scheda' => 517900, 'registro' => 517900, 'situazione' => 521900, 'controllo' => 521900])
        ->and(collect($v['controlli'])->firstWhere('id', 'AC')['esito'])->not->toBe('quadra')
        ->and(collect($v['controlli'])->firstWhere('id', 'AC')['sinistra'])->toBe(1);
    bocSiSommano($v['riepilogo']);
    // Il controllo della banca è per data: con il saldo uguale a quello al 31/12, torna; la causa di una differenza di € 40,00 è il movimento dopo la data,
    // dieci giorni dopo.
    expect(bocConSaldoDellaBanca($this, $s, $s['e'], $s['cassa'], '2026-12-31', 521900)['bc']['esito'])->toBe('quadra');
    $r = bocConSaldoDellaBanca($this, $s, $s['e'], $s['cassa'], '2026-12-31', 517900);
    expect(array_column($r['piste'], 'tipo'))->toContain('dopo_estratto');
    bocNessunaCausaFalsa($s, $r['piste']);
});

it('una registrazione eliminata non conta in nessuna vista; i saldi delle persone da un\'importazione non sono denaro; un fondo non paga una fattura', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2026-12-20');
    $this->actingAs($this->user)->post(route('admin.gestionale.regolazioni-immediate.store', [$s['c']]), [
        'gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id, 'conto_id' => $s['bancarie']->id, 'cassa_id' => $s['cassa']->id, 'fornitore_id' => null,
        'data_operazione' => '2026-12-20', 'causale' => 'Registrata per sbaglio', 'importo' => 33.00,
    ])->assertSessionHasNoErrors();
    DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('causale', 'Registrata per sbaglio')->update(['deleted_at' => now()]);
    // Un saldo di una persona, come lo scrive l'importazione: un debito, non denaro.
    DB::table('saldi')->insert(['esercizio_id' => $s['e']->id, 'condominio_id' => $s['c']->id, 'gestione_id' => $s['g']->id, 'immobile_id' => $s['unita']['A']->id,
        'anagrafica_id' => $s['persone']['ugo']->id, 'saldo_iniziale' => 12000, 'origine' => 'importato', 'created_at' => now(), 'updated_at' => now()]);
    // Un fondo che prova a pagare una fattura: rifiutato, e la banca non si muove.
    $fondo = bocCassa($s, 'fondo', 'Fondo lavori');
    $ft = vrmFattura($this, $s, $s['fornitore'], 'FT-FONDO', [[$s['manutenzioni']->id, 50.00]], '2026-12-20');
    $this->actingAs($this->user)->post(route('admin.gestionale.pagamenti-fornitori.store', [$s['c']]), [
        'fornitore_id' => $s['fornitore']->id, 'esercizio_id' => $s['e']->id, 'conto_corrente_id' => $fondo->conto_contabile_id, 'data_pagamento' => '2026-12-20',
        'metodo_pagamento' => 'bonifico', 'iban_beneficiario' => 'IT60X0542811101000000123456', 'importo_commissioni_cents' => 0, 'bonifico_parlante' => false,
        'allow_overdraft' => true, 'iban_confermato_manualmente' => true, 'conferma_duplicato_verificato' => true,
        'allocazioni' => [['fattura_id' => $ft->id, 'tipo' => 'pagamento', 'importo_allocato_cents' => 5000]],
    ])->assertSessionHasErrors('conto_corrente_id');
    bocDopo($this, $s['e']);
    bocUnaCifra($this, $s, $s['e'], $s['cassa'], 521900, ['CR' => collect(bocViste($this, $s, $s['e'], $s['cassa'])['controlli'])->firstWhere('id', 'CR')['esito']]);
});

it('più gestioni sullo stesso conto: una spesa della gestione straordinaria esce dalla stessa banca, e la cifra è una sola', function () {
    $s = vrmScenario($this);
    $straordinaria = \App\Models\Gestione::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Facciata', 'tipo' => 'straordinaria']);
    $straordinaria->esercizi()->attach($s['e']->id);
    $pc = \App\Models\Gestionale\PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $straordinaria->id, 'nome' => 'Piano della facciata']);
    $voce = be52Voce($this, $s, $pc, 'Ponteggi', 'spesa', '2.000,00');
    vrmGiorno($this, '2026-12-20');
    $this->actingAs($this->user)->post(route('admin.gestionale.regolazioni-immediate.store', [$s['c']]), [
        'gestione_id' => $straordinaria->id, 'esercizio_id' => $s['e']->id, 'conto_id' => $voce->id, 'cassa_id' => $s['cassa']->id, 'fornitore_id' => null,
        'data_operazione' => '2026-12-20', 'causale' => 'Acconto ponteggi', 'importo' => 150.00,
    ])->assertSessionHasNoErrors();
    bocDopo($this, $s['e']);
    // 5.219 − 150 = € 5.069,00.
    $v = bocUnaCifra($this, $s, $s['e'], $s['cassa'], 506900);
    expect($v['conto']['uscite'])->toBe(372100 + 15000);
    // La banca dice € 150,00 in più: l'acconto del 20/12 non ancora uscito. Le spese della banca no: c'è una spesa registrata subito nell'ultimo mese.
    bocTreVolte($this, $s, $s['e'], $s['cassa'], 506900, '2026-06-30', 719900, 15000, 'transito');
});

it('la cassa dei pagamenti online (una cassa virtuale): è una parte del conto per la scheda e il controllo; il registro, che legge banca e contanti, resta senza, e la riga del confronto lo dice', function () {
    $s = vrmScenario($this);
    $online = bocCassa($s, 'virtuale', 'Pagamenti online');
    $bice = vrmQuote($s, 'bice');
    vrmGiorno($this, '2026-12-20');
    $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['persone']['bice']->id, 'cassa_id' => $online->id, 'data_pagamento' => '2026-12-20', 'importo_totale' => '67.50', 'descrizione' => 'Pagamento online',
        'eccedenza' => '0.00', 'gestione_id' => $s['g']->id, 'dettaglio_pagamenti' => [['rata_id' => $bice[10], 'importo' => '67.50']],
    ])->assertSessionHasNoErrors();
    bocDopo($this, $s['e']);
    $v = bocViste($this, $s, $s['e'], $s['cassa']);
    // Scheda, situazione e controllo: 5.219 + 67,50 = € 5.286,50, di cui € 67,50 nella cassa virtuale. Il registro: € 5.219,00.
    expect(['scheda' => $v['scheda'], 'situazione' => $v['situazione'], 'controllo' => $v['controllo'], 'registro' => $v['registro']])
        ->toBe(['scheda' => 528650, 'situazione' => 528650, 'controllo' => 528650, 'registro' => 521900])
        ->and(collect($v['conto']['di_cui'])->firstWhere('cassa', 'Pagamenti online')['finale'])->toBe(6750)
        ->and($v['confronto']['differenza'])->toBe(-6750)
        ->and($v['confronto']['frase'])->toContain('La differenza, €'."\u{a0}".'67,50, è il denaro della cassa «Pagamenti online»: è una cassa virtuale (per esempio i pagamenti online), '
            .'che la scheda conta nel conto corrente; il registro di contabilità legge solo i movimenti di banca e contanti, e quel denaro qui non c\'è.')
        ->and($v['composizione']['parti'])->toBe([])
        // Senza un fondo non c'è la riga «Si può usare»: una cassa virtuale non è un fondo.
        ->and($v['conto']['si_puo_usare'])->toBeNull();
    bocSiSommano($v['riepilogo']);
});

it('un fondo aperto con un saldo suo, con un conto in banca solo: è nel conto in ogni vista, registro compreso, e «Come è composta» dice il saldo iniziale del fondo', function () {
    $s = vrmScenario($this);
    bocCassa($s, 'fondo', 'Fondo riserva', 30000);
    bocDopo($this, $s['e']);
    // 5.219 + 300 di apertura del fondo = € 5.519,00. Nel registro il riporto è 5.000 + 300 (il fondo c'era il primo giorno), i movimenti € 5.219,00.
    $v = bocUnaCifra($this, $s, $s['e'], $s['cassa'], 551900);
    expect($v['conto'])->toMatchArray(['iniziale' => 530000, 'entrate' => 394000, 'uscite' => 372100])
        ->and($v['card']['riporto']['totale'])->toBe(530000)
        ->and($v['composizione'])->toMatchArray(['movimenti' => 521900, 'saldo' => 551900])
        ->and($v['composizione']['parti'])->toBe([['voce' => 'Il saldo iniziale di «Fondo riserva»', 'importo' => 30000, 'quando' => 'inizio']])
        ->and($v['confronto']['differenza'])->toBe(0);
    // La stampa del registro filtrato dice la stessa composizione sotto le cifre.
    if (trim((string) shell_exec('command -v pdftotext')) !== '') {
        $pdf = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.registro-contabilita.print', [$s['c'], $s['e']]).'?cassa='.$s['cassa']->id)->assertOk()->getContent();
        $file = tempnam(sys_get_temp_dir(), 'boc').'.pdf';
        file_put_contents($file, $pdf);
        $testo = preg_replace('/[\s\x{a0}]+/u', ' ', (string) shell_exec('pdftotext '.escapeshellarg($file).' - 2>/dev/null'));
        @unlink($file);
        expect($testo)->toContain('Il saldo di cassa è composto così: I movimenti di «Banca principale» nel registro € 5.219,00; Il saldo iniziale di «Fondo riserva» € 300,00; insieme € 5.519,00.')
            ->toContain('cassa: «Banca principale»');
    }
    bocTreVolte($this, $s, $s['e'], $s['cassa'], 551900, '2026-06-30', 749900);
});

it('il rimborso di un fornitore, come incasso da terzi, entra in banca con il suo segno', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2026-12-18');
    $this->actingAs($this->user)->post(route('admin.gestionale.incassi-da-terzi.store', [$s['c']]), [
        'esercizio_id' => $s['e']->id, 'conto_id' => $s['antenna']->id, 'cassa_id' => $s['cassa']->id, 'data_operazione' => '2026-12-18',
        'importo' => 60.00, 'anagrafica_id' => null, 'causale' => 'Rimborso della ditta delle pulizie per un intervento non fatto',
    ])->assertSessionHasNoErrors();
    bocDopo($this, $s['e']);
    // 5.219 + 60 = € 5.279,00.
    $v = bocUnaCifra($this, $s, $s['e'], $s['cassa'], 527900);
    expect($v['conto']['entrate'])->toBe(394000 + 6000);
    bocTreVolte($this, $s, $s['e'], $s['cassa'], 527900, '2026-06-30', 719900);
});

it('lo storno di un incasso fatto l\'anno dopo: la scheda e il registro del 2026 hanno la coppia (si annulla), la situazione e il controllo al 31/12 hanno l\'incasso; la differenza è lo storno, oltre il confine', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2026-12-20');
    $bice = vrmQuote($s, 'bice');
    $incasso = vrmIncassa($this, $s, 'bice', [$bice[10] => 6750], '2026-12-20');
    vrmGiorno($this, '2027-01-10');
    lcdStorna($this, $s, $incasso);
    $storno = DB::table('scritture_contabili')->where('scrittura_padre_id', $incasso)->where('tipo_movimento', 'rettifica')->first();
    vrmGiorno($this, '2027-02-01');
    $v = bocViste($this, $s, $s['e'], $s['cassa']);
    // Lo storno resta nell'esercizio dell'incasso, con la data in cui il denaro è tornato: per esercizio la coppia si annulla (€ 5.219,00), al
    // 31/12/2026 la banca aveva ancora l'incasso (€ 5.286,50).
    expect((int) $storno->esercizio_id)->toBe((int) $s['e']->id)->and(substr((string) $storno->data_competenza, 0, 10))->toBe('2027-01-10')
        ->and(['scheda' => $v['scheda'], 'registro' => $v['registro'], 'situazione' => $v['situazione'], 'controllo' => $v['controllo']])
        ->toBe(['scheda' => 521900, 'registro' => 521900, 'situazione' => 528650, 'controllo' => 528650])
        ->and(collect($v['controlli'])->firstWhere('id', 'AC')['esito'])->not->toBe('quadra');
    bocSiSommano($v['riepilogo']);
    expect(bocConSaldoDellaBanca($this, $s, $s['e'], $s['cassa'], '2026-12-31', 528650)['bc']['esito'])->toBe('quadra');
    bocNessunaCausaFalsa($s, bocConSaldoDellaBanca($this, $s, $s['e'], $s['cassa'], '2026-12-31', 521900)['piste']);
});
