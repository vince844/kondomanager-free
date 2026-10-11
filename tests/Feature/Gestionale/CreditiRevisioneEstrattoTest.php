<?php

/**
 * 1.11.0-beta.49, Fase 1-bis — i rilievi dell'estratto conto (`docs/piano_esecutivo_beta49.md`, «Fase 1-bis (11/10/2026)»):
 * K1, K3, K5 e S1, nelle forme riviste dopo le verifiche.
 *
 * - K1: un saldo dell'esercizio assorbito dal piano dell'esercizio dopo sparisce dall'estratto conto dell'esercizio di partenza.
 *   Non nella finestra normale (un solo esercizio aperto), ma dopo una riapertura dalle rotte e con i due esercizi aperti che crea
 *   l'importazione (`LivelloEsercizi`: l'esercizio nasce aperto, `createDefaultGestione` riusa l'ordinaria).
 * - K3: la quota di una voce di tipo «entrata» (proventi) è negativa senza essere un credito riportato; l'estratto scarta la sua
 *   riga in AVERE come se il credito fosse già nel saldo iniziale, e non torna con il giornale.
 * - K5: chiuso il 2026, la pagina mostra il 2027 ma elenca anche le righe del 2026, che pesano la sola spesa e perdono il riporto
 *   che il saldo iniziale del 2027 non contiene.
 * - S1: l'estratto riconosce le rettifiche del riallineamento cercando il marcatore dentro la causale, che per un incasso è la
 *   descrizione libera e per un rimborso contiene il nome dell'anagrafica: lo storno con quella stringa sparisce.
 *
 * Il criterio di ogni test è il giornale: il saldo dell'estratto, a video e nel PDF, è il saldo della persona su «Crediti verso
 * condomini» più il pregresso non ancora a giornale. Gli esercizi si chiudono, si aprono e si riaprono dalle rotte della pagina
 * degli esercizi; il saldo scritto dopo il piano viene dalla pagina dei saldi, la voce di entrata dal modale della nuova voce,
 * l'emissione, l'incasso, il rimborso e lo storno dalle loro rotte. I piani si generano con `GeneratePianoRateAction`, come negli
 * altri test dei crediti.
 *
 * Cosa NON copre: il conto del prelievo e del rimborso per una voce di entrata (K2, area dell'incasso); lo storno di un credito
 * usato tutto prima dell'emissione (K4); il riporto a mano della voce 1.17 di `chiusura_esercizio_vincoli.md`, che resta della
 * chiusura d'esercizio; le rettifiche vere del riallineamento (`RiallineaCreditiTest`, R13).
 *
 * Tutte le cifre sono in centesimi. Segno dei saldi: positivo = debito, negativo = credito.
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Helpers\MoneyHelper;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Models\Tabella;
use App\Models\User;
use App\Services\CondominioService;
use App\Services\Gestionale\RiallineaCreditiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

// ─── Aiuti ────────────────────────────────────────────────────────────────────────────────────────────────────────────────

/** Un condominio con i conti per emettere, una cassa e la tabella «Proprietà»; niente esercizi: li crea chi chiama. */
function rveCondominio(): array
{
    static $seq = 0;
    $seq++;
    $c = Condominio::factory()->create();
    $banca = ContoContabile::create(['condominio_id' => $c->id, 'codice' => '10.10', 'nome' => 'Banca', 'tipo' => 'attivo', 'ruolo' => 'banca', 'categoria' => 'liquidita']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '10.20', 'nome' => 'Crediti verso condomini', 'tipo' => 'attivo', 'ruolo' => 'crediti_condomini', 'categoria' => 'crediti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '20.10', 'nome' => 'Anticipi', 'tipo' => 'passivo', 'ruolo' => 'anticipi_condomini', 'categoria' => 'debiti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '20.20', 'nome' => 'Gestione rate', 'tipo' => 'passivo', 'ruolo' => 'gestione_rate', 'categoria' => 'debiti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '30.10', 'nome' => 'Passate gestioni', 'tipo' => 'passivo', 'ruolo' => 'passate_gestioni', 'categoria' => 'debiti']);
    $cassa = Cassa::create(['condominio_id' => $c->id, 'conto_contabile_id' => $banca->id, 'nome' => 'Banca principale', 'tipo' => 'banca', 'attiva' => true, 'saldo_iniziale' => 10000]);
    $tabella = Tabella::create(['condominio_id' => $c->id, 'nome' => 'Proprietà', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);

    return ['c' => $c, 'cassa' => $cassa, 'tabella' => $tabella, 'seq' => $seq, 'persone' => [], 'unita' => []];
}

/** Un'unità del condominio sulla tabella data, con il suo proprietario (creato la prima volta che compare). */
function rveUnita(array &$s, string $sigla, string $chi, Tabella $tabella, float $millesimi, ?string $nome = null): void
{
    $s['unita'][$sigla] = Immobile::create(['condominio_id' => $s['c']->id, 'tipo' => 'appartamento', 'codice_immobile' => "RVE-{$s['seq']}-{$sigla}", 'nome' => "Interno {$sigla}", 'interno' => $sigla]);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $s['unita'][$sigla]->id, 'valore' => $millesimi, 'created_at' => now(), 'updated_at' => now()]);
    $s['persone'][$chi] ??= tap(Anagrafica::forceCreate(['nome' => $nome ?? ucfirst($chi), 'email' => "rve-{$chi}{$s['seq']}@test.it", 'indirizzo' => 'Via Roma 1',
        'codice_fiscale' => strtoupper(str_pad($chi, 6, 'X')) . str_pad((string) $s['seq'], 10, '0', STR_PAD_LEFT)]), fn ($a) => $a->condomini()->syncWithoutDetaching([$s['c']->id]));
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['persone'][$chi]->id, 'immobile_id' => $s['unita'][$sigla]->id, 'tipologia' => 'proprietario', 'quota' => 100,
        'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
}

/** La voce di spesa da 120000 sulla tabella «Proprietà», nel piano dei conti della gestione. */
function rveVoceSpese(array $s, Gestione $g): void
{
    $pc = PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $g->id, 'nome' => 'Piano dei conti']);
    $conto = Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Spese generali', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 120000]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $s['tabella']->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
}

/** Un piano ordinario della gestione nell'esercizio, metodo «prima rata», generato con i saldi liberi della gestione. */
function rvePiano(array $s, Esercizio $e, Gestione $g, string $nome, int $numeroRate, string $primaScadenza, string $metodo = 'prima_rata'): PianoRate
{
    $piano = PianoRate::create([
        'gestione_id' => $g->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $e->id, 'nome' => $nome, 'stato' => 'approvato', 'tipo' => 'ordinario',
        'numero_rate' => $numeroRate, 'giorno_scadenza' => 5, 'data_prima_scadenza' => $primaScadenza, 'metodo_distribuzione' => $metodo, 'applica_saldi' => true,
    ]);
    app(GeneratePianoRateAction::class)->execute($piano, forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Prova', esercizio: $e);

    return $piano;
}

function rveRata(PianoRate $piano, int $numero): int
{
    return (int) DB::table('rate')->where('piano_rate_id', $piano->id)->where('numero_rata', $numero)->value('id');
}

function rveQuota(array $s, PianoRate $piano, string $chi, int $numero): int
{
    return (int) DB::table('rate_quote')->where('rata_id', rveRata($piano, $numero))->where('anagrafica_id', $s['persone'][$chi]->id)->value('id');
}

/** L'emissione vera, dalla rotta della pagina del piano. */
function rveEmetti($test, array $s, PianoRate $piano, array $numeri, string $data): void
{
    $ids = array_map(fn ($n) => rveRata($piano, $n), $numeri);
    $test->actingAs($test->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $piano]), [
        'rate_ids' => $ids, 'data_emissione' => $data, 'invia_notifiche' => false,
    ])->assertSessionHasNoErrors();
    expect(DB::table('rate')->whereIn('id', $ids)->pluck('stato')->unique()->values()->all())->toBe(['emessa']);
}

/** Un saldo scritto dalla pagina dei saldi: va sull'esercizio aperto (il primo, se ce ne sono due). */
function rveSaldoDallaPagina($test, array $s, string $chi, string $sigla, Gestione $g, int $centesimi): Saldo
{
    $test->actingAs($test->user)->post(route('admin.gestionale.saldi.store', [$s['c']]), [
        'anagrafica_id' => $s['persone'][$chi]->id, 'immobile_id' => $s['unita'][$sigla]->id, 'gestione_id' => $g->id, 'saldo_iniziale' => $centesimi,
    ])->assertSessionHasNoErrors();

    return Saldo::where('condominio_id', $s['c']->id)->where('anagrafica_id', $s['persone'][$chi]->id)->latest('id')->firstOrFail();
}

/** Chiude o riapre un esercizio dalla pagina degli esercizi. */
function rveStatoEsercizio($test, array $s, Esercizio $e, string $stato): void
{
    $test->actingAs($test->user)->put(route('admin.gestionale.esercizi.update', [$s['c'], $e]), [
        'nome' => $e->nome, 'descrizione' => $e->nome, 'data_inizio' => $e->data_inizio->format('Y-m-d'), 'data_fine' => $e->data_fine->format('Y-m-d'), 'stato' => $stato,
    ])->assertSessionHasNoErrors();
    expect($e->fresh()->stato)->toBe($stato);
}

/** Apre l'esercizio dell'anno dalla pagina degli esercizi (la rotta rifiuta un secondo esercizio aperto). */
function rveApriAnno($test, array $s, int $anno): Esercizio
{
    $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.store', [$s['c']]), [
        'nome' => "Esercizio {$anno}", 'descrizione' => "Anno {$anno}", 'data_inizio' => "{$anno}-01-01", 'data_fine' => "{$anno}-12-31", 'stato' => 'aperto',
    ])->assertSessionHasNoErrors();

    return Esercizio::where('condominio_id', $s['c']->id)->where('nome', "Esercizio {$anno}")->firstOrFail();
}

/** Ciò che la pagina dell'estratto conto mostra della persona. */
function rveEstratto($test, array $s, string $chi): array
{
    $r = $test->actingAs($test->user)->get(route('admin.gestionale.anagrafiche.estratto-conto', [$s['c'], $s['persone'][$chi]]));
    $r->assertOk();
    $p = $r->viewData('page')['props'];

    return [
        'esercizio' => $p['esercizio']['nome'], 'saldo_iniziale' => $p['stats']['saldo_iniziale_raw'], 'saldo' => $p['stats']['saldo_raw'],
        'addebiti' => $p['stats']['totale_addebiti'], 'versato' => $p['stats']['totale_versamenti'], 'righe' => count($p['timeline']), 'timeline' => $p['timeline'],
    ];
}

/** Lo stesso estratto in PDF: i dati passati alla vista della stampa e il suo testo, reso dalla stessa vista. */
function rvePdf($test, array $s, string $chi): array
{
    $dati = null;
    View::composer('pdf.gestionale.estratto_conto_anagrafica', function ($v) use (&$dati) {
        $dati ??= $v->getData();
    });
    $r = $test->actingAs($test->user)->get(route('admin.gestionale.anagrafiche.estratto-conto.print', [$s['c'], $s['persone'][$chi]]));
    $r->assertOk();
    expect($r->headers->get('Content-Type'))->toBe('application/pdf');
    $testo = preg_replace('/\s+/u', ' ', strip_tags(view('pdf.gestionale.estratto_conto_anagrafica', $dati)->render()));

    return [
        'esercizio' => $dati['esercizio']->nome, 'saldo_iniziale' => (int) $dati['saldoInizialeCents'], 'saldo' => $dati['stats']['saldo_raw'],
        'addebiti' => $dati['stats']['totale_addebiti'], 'versato' => $dati['stats']['totale_versamenti'], 'righe' => count($dati['timeline']), 'testo' => $testo,
    ];
}

/** Le cifre che pagina e PDF devono dire uguali: [esercizio, saldo iniziale, saldo, totale addebiti, totale versato, righe]. */
function rveCifre(array $e): array
{
    return [$e['esercizio'], $e['saldo_iniziale'], $e['saldo'], $e['addebiti'], $e['versato'], $e['righe']];
}

/** Il mastrino della persona su «Crediti verso condomini», sulle scritture vive: dare − avere. */
function rveMastrino(array $s, string $chi): int
{
    return (int) DB::table('righe_scritture as r')->join('scritture_contabili as sc', 'sc.id', '=', 'r.scrittura_id')->join('conti_contabili as cc', 'cc.id', '=', 'r.conto_contabile_id')
        ->where('sc.condominio_id', $s['c']->id)->whereNull('sc.deleted_at')->where('cc.ruolo', 'crediti_condomini')->where('r.anagrafica_id', $s['persone'][$chi]->id)
        ->sum(DB::raw("CASE WHEN r.tipo_riga = 'dare' THEN r.importo ELSE -r.importo END"));
}

// ─── K1: il saldo dell'esercizio assorbito dal piano dell'esercizio dopo ──────────────────────────────────────────────────

it('K1 — riaperto il 2026 dopo il piano 2027 che ne ha assorbito il saldo, il saldo iniziale resta € 50,00 e l\'estratto torna con il giornale, a video e nel PDF', function () {
    // Preventivo 120000 in 6 rate da 20000, «prima rata», Ugo solo sull'unità A. La gestione ordinaria è una sola, riusata ogni
    // anno (`createDefaultGestione`). Rata 1 del 2026 emessa: 20000. Poi, dalla pagina dei saldi, Ugo +5000 sull'esercizio 2026,
    // scritto dopo il piano: resta libero.
    $s = rveCondominio();
    rveUnita($s, 'A', 'ugo', $s['tabella'], 1000.0);
    $e26 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2026', 'descrizione' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = app(CondominioService::class)->createDefaultGestione($s['c'], $e26);
    rveVoceSpese($s, $g);
    $p26 = rvePiano($s, $e26, $g, 'Preventivo 2026', 6, '2026-01-05');
    rveEmetti($this, $s, $p26, [1], '2026-01-31');
    $saldo = rveSaldoDallaPagina($this, $s, 'ugo', 'A', $g, 5000);
    expect($saldo->esercizio_id)->toBe($e26->id);

    // Pagina 2026: saldo iniziale 5000 (il saldo libero), saldo 5000 + 20000 = 25000. Giusto oggi.
    expect(rveCifre(rveEstratto($this, $s, 'ugo')))->toBe(['Esercizio 2026', 5000, 25000, '€ 200,00', '€ 0,00', 1]);

    // Si chiude il 2026, si apre il 2027 e si aggancia la gestione dalla pagina della gestione del 2026 (dalla URL del 2027 la
    // gestione non si raggiunge); il preventivo 2027 assorbe il saldo del 2026: rata 1 del 2027 = 20000 + 5000 = 25000.
    rveStatoEsercizio($this, $s, $e26, 'chiuso');
    $e27 = rveApriAnno($this, $s, 2027);
    $this->actingAs($this->user)->put(route('admin.gestionale.esercizi.gestioni.update', [$s['c'], $e26, $g]), [
        'nome' => $g->nome, 'descrizione' => 'Gestione ordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2027-12-31', 'tipo' => 'ordinaria',
    ])->assertSessionHasNoErrors();
    expect(DB::table('esercizio_gestione')->where('gestione_id', $g->id)->orderBy('esercizio_id')->pluck('esercizio_id')->all())->toBe([$e26->id, $e27->id]);
    $p27 = rvePiano($s, $e27, $g, 'Preventivo 2027', 6, '2027-01-05');
    expect([(bool) $saldo->fresh()->is_applicato, (int) $saldo->fresh()->piano_rate_id, (int) $saldo->fresh()->esercizio_id])->toBe([true, $p27->id, $e26->id]);
    rveEmetti($this, $s, $p27, [1], '2027-01-31');

    // Il percorso normale, giusto oggi e da tenere: pagina 2027, saldo iniziale 5000 (`saldo_usato` della rata 1 del 2027), saldo
    // 5000 + 20000 (rata 1 del 2026) + 20000 (la spesa della rata 1 del 2027) = 45000. Mastrino «Crediti» 20000 + 25000 = 45000.
    expect(rveMastrino($s, 'ugo'))->toBe(45000);
    $normale = rveEstratto($this, $s, 'ugo');
    expect([$normale['esercizio'], $normale['saldo_iniziale'], $normale['saldo']])->toBe(['Esercizio 2027', 5000, 45000]);

    // Si chiude il 2027 e si riapre il 2026, dalle rotte, senza errori. Pagina 2026: il saldo dell'esercizio 2026 è di un piano di un
    // altro esercizio, e il saldo iniziale lo deve contare: 5000. Saldo 5000 + 20000 + 20000 = 45000, uguale al mastrino. Oggi il
    // saldo iniziale è 0 (il saldo è «applicato» e il piano 2027 non è della pagina) e il saldo 40000: i 5000 spariscono.
    rveStatoEsercizio($this, $s, $e27, 'chiuso');
    rveStatoEsercizio($this, $s, $e26, 'aperto');
    $pagina = rveEstratto($this, $s, 'ugo');
    $pdf = rvePdf($this, $s, 'ugo');
    expect(['pagina' => [$pagina['esercizio'], $pagina['saldo_iniziale'], $pagina['saldo']], 'pdf' => [$pdf['esercizio'], $pdf['saldo_iniziale'], $pdf['saldo']]])
        ->toBe(['pagina' => ['Esercizio 2026', 5000, 45000], 'pdf' => ['Esercizio 2026', 5000, 45000]])
        ->and($pdf['saldo'])->toBe(rveMastrino($s, 'ugo'))
        ->and(substr_count($pdf['testo'], 'Saldo finale ' . MoneyHelper::format(45000)))->toBe(2);
});

it('K1 — due esercizi aperti come li crea l\'importazione: il saldo 2026 assorbito dal piano 2027 resta nell\'estratto del 2026, prima e dopo l\'emissione, a video e nel PDF', function () {
    // L'importazione crea ogni esercizio aperto (`LivelloEsercizi`) e gli aggancia la gestione ordinaria con
    // `createDefaultGestione`, che riusa quella che c'è: il 2026 e il 2027 aperti, una gestione sola. La pagina mostra il primo, il 2026.
    $s = rveCondominio();
    rveUnita($s, 'A', 'ugo', $s['tabella'], 1000.0);
    $e26 = Esercizio::create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2026', 'descrizione' => null, 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = app(CondominioService::class)->createDefaultGestione($s['c'], $e26);
    $e27 = Esercizio::create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'descrizione' => null, 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    expect(app(CondominioService::class)->createDefaultGestione($s['c'], $e27)->id)->toBe($g->id);
    rveVoceSpese($s, $g);

    // 6 rate da 20000, «prima rata». Rata 1 del 2026 emessa: 20000; poi Ugo +5000 dalla pagina dei saldi, sul 2026 (il primo aperto).
    $p26 = rvePiano($s, $e26, $g, 'Preventivo 2026', 6, '2026-01-05');
    rveEmetti($this, $s, $p26, [1], '2026-01-31');
    $saldo = rveSaldoDallaPagina($this, $s, 'ugo', 'A', $g, 5000);
    expect($saldo->esercizio_id)->toBe($e26->id);
    // Giusto oggi: saldo iniziale 5000, saldo 25000.
    expect(rveCifre(rveEstratto($this, $s, 'ugo')))->toBe(['Esercizio 2026', 5000, 25000, '€ 200,00', '€ 0,00', 1]);

    // Il preventivo 2027 assorbe il saldo del 2026 (rata 1 del 2027 = 20000 + 5000, in bozza). La posizione di Ugo non cambia: 20000
    // a giornale più i 5000 di pregresso, che ora stanno nella rata 1 del 2027 e non ancora nel giornale. Pagina 2026: saldo
    // iniziale 5000, saldo 25000. Oggi 0 e 20000.
    $p27 = rvePiano($s, $e27, $g, 'Preventivo 2027', 6, '2027-01-05');
    expect([(bool) $saldo->fresh()->is_applicato, (int) $saldo->fresh()->piano_rate_id])->toBe([true, $p27->id]);
    $prima = rveEstratto($this, $s, 'ugo');
    $primaPdf = rvePdf($this, $s, 'ugo');

    // Emessa la rata 1 del 2027: mastrino 20000 + 25000 = 45000; pagina 2026 5000 + 20000 + 20000 = 45000. Oggi 0 e 40000.
    rveEmetti($this, $s, $p27, [1], '2027-01-31');
    $dopo = rveEstratto($this, $s, 'ugo');
    $dopoPdf = rvePdf($this, $s, 'ugo');

    expect([
        'prima' => [$prima['esercizio'], $prima['saldo_iniziale'], $prima['saldo']],
        'prima_pdf' => [$primaPdf['saldo_iniziale'], $primaPdf['saldo']],
        'dopo' => [$dopo['esercizio'], $dopo['saldo_iniziale'], $dopo['saldo']],
        'dopo_pdf' => [$dopoPdf['saldo_iniziale'], $dopoPdf['saldo']],
        'mastrino' => rveMastrino($s, 'ugo'),
    ])->toBe([
        'prima' => ['Esercizio 2026', 5000, 25000],
        'prima_pdf' => [5000, 25000],
        'dopo' => ['Esercizio 2026', 5000, 45000],
        'dopo_pdf' => [5000, 45000],
        'mastrino' => 45000,
    ]);
});

// ─── K3: la voce di tipo «entrata» ────────────────────────────────────────────────────────────────────────────────────────

/**
 * Spese 120000 sulla tabella «Proprietà» (A di Ugo e C di Bice, 500 millesimi ciascuno) e proventi 12000 su una voce di tipo
 * «entrata» sulla tabella «Box», dove c'è solo il box D di Bice; le due voci dal modale della nuova voce, il saldo del box dalla
 * pagina dei saldi. 12 rate «prima rata»: per rata 5000 a Ugo, 5000 a Bice su C, −1000 a Bice su D (più il saldo del box nella
 * rata 1). Emessa la rata 1.
 */
function rveScenarioEntrata($test, int $saldoBox): array
{
    $s = rveCondominio();
    $e = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = app(CondominioService::class)->createDefaultGestione($s['c'], $e);
    $box = Tabella::create(['condominio_id' => $s['c']->id, 'nome' => 'Box', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    rveUnita($s, 'A', 'ugo', $s['tabella'], 500.0);
    rveUnita($s, 'C', 'bice', $s['tabella'], 500.0);
    rveUnita($s, 'D', 'bice', $box, 1000.0);
    $pc = PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $g->id, 'nome' => 'Piano dei conti']);
    foreach ([['Spese generali', 'spesa', '1.200,00', $s['tabella']], ['Proventi antenna', 'entrata', '120,00', $box]] as [$nome, $tipo, $importo, $tabella]) {
        $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$s['c'], $e, $pc]), [
            'nome' => $nome, 'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => $tipo, 'importo' => $importo, 'descrizione' => '', 'note' => '',
            'parent_id' => null, 'tabella_millesimale_id' => $tabella->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
            'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
        ])->assertSessionHasNoErrors();
    }
    expect(Conto::where('piano_conto_id', $pc->id)->orderBy('id')->get(['tipo', 'importo'])->map(fn ($c) => [$c->tipo, (int) $c->importo])->all())
        ->toBe([['spesa', 120000], ['entrata', 12000]]);
    if ($saldoBox !== 0) {
        rveSaldoDallaPagina($test, $s, 'bice', 'D', $g, $saldoBox);
    }
    $piano = rvePiano($s, $e, $g, 'Preventivo 2026', 12, '2026-01-05');
    rveEmetti($test, $s, $piano, [1], '2026-01-31');

    return $s + ['piano' => $piano];
}

it('K3 — la quota di una voce di entrata non è un credito riportato: l\'estratto di Bice, a video e nel PDF, torna con il giornale con e senza un saldo del box', function () {
    // Bice, rata 1: su C 5000; su D la quota pura −1000 (i proventi) più il saldo del box. Il giornale (rifatto a mano):
    //   a) saldo 0     → D −1000: AVERE 1000.                «Crediti» di Bice 5000 − 1000        = 4000;
    //   b) saldo −2000 → D −3000: AVERE 1000 e AVERE 2000.   5000 − 1000 − 2000                   = 2000;
    //   c) saldo +500  → D −500: AVERE 1000 e DARE 500.      5000 + 500 − 1000                    = 4500;
    //   d) saldo +3000 → D +2000: DARE 2000.                 5000 + 2000                          = 7000.
    // L'estratto: saldo iniziale = il saldo del box (`saldo_usato`), più gli addebiti della rata 5000 − 1000 = 4000 («€ 40,00»),
    // in tutti e quattro i casi. Oggi a) e b) scartano l'AVERE della spesa negativa come se fosse credito già nel saldo iniziale:
    // a) 5000 e «€ 50,00», b) 3000 e «€ 50,00». c) e d) tornano già, e la correzione non li deve cambiare.
    $esiti = [];
    foreach (['a' => 0, 'b' => -2000, 'c' => 500, 'd' => 3000] as $caso => $saldoBox) {
        $s = rveScenarioEntrata($this, $saldoBox);
        $quotaBox = RataQuote::where('rata_id', rveRata($s['piano'], 1))->where('immobile_id', $s['unita']['D']->id)->firstOrFail();
        expect([(int) $quotaBox->importo, (int) $quotaBox->regole_calcolo['importi']['quota_pura_gestione']])->toBe([-1000 + $saldoBox, -1000]);
        $pagina = rveEstratto($this, $s, 'bice');
        $pdf = rvePdf($this, $s, 'bice');
        $esiti[$caso] = [
            'giornale' => rveMastrino($s, 'bice'),
            'pagina' => [$pagina['saldo_iniziale'], $pagina['saldo'], $pagina['addebiti'], $pagina['versato']],
            'pdf' => [$pdf['saldo_iniziale'], $pdf['saldo'], $pdf['addebiti'], $pdf['versato'], substr_count($pdf['testo'], 'Totale addebiti € 40,00')],
            // Nessun credito è stato riportato: nessuna riga dell'estratto può dirlo.
            'credito_riportato' => collect($pagina['timeline'])->flatMap(fn ($r) => $r['dettagli'])->filter(fn ($d) => str_contains($d['text'], 'Credito riportato'))->count(),
        ];
    }

    expect($esiti)->toBe([
        'a' => ['giornale' => 4000, 'pagina' => [0, 4000, '€ 40,00', '€ 0,00'], 'pdf' => [0, 4000, '€ 40,00', '€ 0,00', 2], 'credito_riportato' => 0],
        'b' => ['giornale' => 2000, 'pagina' => [-2000, 2000, '€ 40,00', '€ 0,00'], 'pdf' => [-2000, 2000, '€ 40,00', '€ 0,00', 2], 'credito_riportato' => 0],
        'c' => ['giornale' => 4500, 'pagina' => [500, 4500, '€ 40,00', '€ 0,00'], 'pdf' => [500, 4500, '€ 40,00', '€ 0,00', 2], 'credito_riportato' => 0],
        'd' => ['giornale' => 7000, 'pagina' => [3000, 7000, '€ 40,00', '€ 0,00'], 'pdf' => [3000, 7000, '€ 40,00', '€ 0,00', 2], 'credito_riportato' => 0],
    ]);
});

// ─── K5: l'estratto con i movimenti di più esercizi ───────────────────────────────────────────────────────────────────────

it('K5 — chiuso il 2026 e aperto il 2027 dalle rotte, l\'estratto del 2027 torna con il mastrino di Ugo, a video e nel PDF', function (int $saldoUgo, array $pagina2026, array $pagina2027) {
    // Preventivo 120000 in 12 rate da 10000, «prima rata», Ugo solo sull'unità A con il suo saldo 2026. Emesse le rate 1 e 2.
    $s = rveCondominio();
    rveUnita($s, 'A', 'ugo', $s['tabella'], 1000.0);
    $e26 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2026', 'descrizione' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = app(CondominioService::class)->createDefaultGestione($s['c'], $e26);
    rveVoceSpese($s, $g);
    Saldo::create(['esercizio_id' => $e26->id, 'condominio_id' => $s['c']->id, 'anagrafica_id' => $s['persone']['ugo']->id, 'immobile_id' => $s['unita']['A']->id,
        'gestione_id' => $g->id, 'saldo_iniziale' => $saldoUgo, 'origine' => 'manuale', 'is_applicato' => false]);
    $piano = rvePiano($s, $e26, $g, 'Preventivo 2026', 12, '2026-01-05');
    rveEmetti($this, $s, $piano, [1, 2], '2026-02-28');
    $mastrino = rveMastrino($s, 'ugo');

    // Pagina 2026, giusta oggi: uguale al mastrino.
    $p26 = rveEstratto($this, $s, 'ugo');
    expect([$p26['esercizio'], $p26['saldo_iniziale'], $p26['saldo'], $mastrino])->toBe($pagina2026);

    // Chiuso il 2026 e aperto il 2027 dalla pagina degli esercizi: la pagina mostra il 2027, senza piani e senza saldi, e le righe
    // del 2026 restano nell'elenco. Il saldo iniziale del 2027 è 0, quindi le righe del 2026 devono pesare l'importo contabile
    // intero, riporto compreso, e il saldo della pagina è il mastrino. I totali con lo stesso conto: 0 + addebiti − 0 = saldo.
    rveStatoEsercizio($this, $s, $e26, 'chiuso');
    rveApriAnno($this, $s, 2027);
    $p27 = rveEstratto($this, $s, 'ugo');
    $pdf = rvePdf($this, $s, 'ugo');
    expect([
        'pagina' => [$p27['esercizio'], $p27['saldo_iniziale'], $p27['saldo'], $p27['addebiti'], $p27['versato']],
        'pdf' => [$pdf['esercizio'], $pdf['saldo_iniziale'], $pdf['saldo'], $pdf['addebiti'], $pdf['versato'], substr_count($pdf['testo'], 'Saldo finale ' . MoneyHelper::format($mastrino))],
    ])->toBe([
        'pagina' => $pagina2027,
        'pdf' => [...$pagina2027, 2],
    ]);
})->with([
    // Ugo −20000: rata 1 = 10000 − 20000 = −10000 (DARE 10000 della spesa, AVERE 20000 del credito riportato), rata 2 = 10000.
    // Mastrino 10000 − 20000 + 10000 = 0. Pagina 2026: saldo iniziale −20000 + 10000 + 10000 = 0. Pagina 2027: 10000 − 20000 +
    // 10000 = 0, «Totale addebiti» € 0,00 (l'AVERE del credito riportato riduce gli addebiti). Oggi 20000: l'AVERE è scartato
    // e il saldo iniziale del 2027 non lo contiene (prima della .49 erano 10000, quanto il mastrino della .48).
    'A, Ugo a credito di € 200,00' => [-20000, ['Esercizio 2026', -20000, 0, 0], ['Esercizio 2027', 0, 0, '€ 0,00', '€ 0,00']],
    // Ugo +5000: rata 1 = 10000 + 5000 = 15000, rata 2 = 10000. Mastrino 25000. Pagina 2026: 5000 + 10000 + 10000 = 25000.
    // Pagina 2027: 15000 + 10000 = 25000, «Totale addebiti» € 250,00. Oggi 20000: la rata 1 pesa la sola spesa (come nella .48).
    'B, Ugo a debito di € 50,00' => [5000, ['Esercizio 2026', 5000, 25000, 25000], ['Esercizio 2027', 0, 25000, '€ 250,00', '€ 0,00']],
]);

// ─── S1: il marcatore del riallineamento nel testo libero ─────────────────────────────────────────────────────────────────

/** Ugo solo sull'unità A, 12 rate da 10000 «rata zero», il suo saldo 2026 nella rata 0. */
function rveScenarioMarcatore(int $saldoUgo, string $nome): array
{
    $s = rveCondominio();
    rveUnita($s, 'A', 'ugo', $s['tabella'], 1000.0, $nome);
    $e = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = app(CondominioService::class)->createDefaultGestione($s['c'], $e);
    rveVoceSpese($s, $g);
    if ($saldoUgo !== 0) {
        Saldo::create(['esercizio_id' => $e->id, 'condominio_id' => $s['c']->id, 'anagrafica_id' => $s['persone']['ugo']->id, 'immobile_id' => $s['unita']['A']->id,
            'gestione_id' => $g->id, 'saldo_iniziale' => $saldoUgo, 'origine' => 'manuale', 'is_applicato' => false]);
    }

    return $s + ['piano' => rvePiano($s, $e, $g, 'Preventivo 2026', 12, '2026-01-05', 'rata_zero')];
}

it('S1 — un incasso stornato con il marcatore del riallineamento nella descrizione, anche in minuscolo, resta nell\'estratto conto come quello senza, a video e nel PDF', function () {
    // Rata 1 di Ugo da 10000 emessa; incasso di € 100,00 con la descrizione data, poi stornato. La quota ha ancora 10000 da pagare.
    // Rifatto a mano: 0 + 10000 (emissione) − 10000 (incasso, stornato ma nel saldo progressivo) + 10000 (la rettifica dello storno)
    // = 10000; tre righe; «Totale addebiti» € 100,00 e «Totale versato» € 0,00 (la coppia dello storno è fuori dai totali). Oggi con
    // il marcatore la rettifica sparisce: saldo 0 e due righe (il LIKE non distingue le maiuscole, e la minuscola fa lo stesso).
    $esiti = [];
    foreach (['Bonifico', 'Bonifico — ' . RiallineaCreditiService::MARCATORE, 'bonifico ' . mb_strtolower(RiallineaCreditiService::MARCATORE)] as $descrizione) {
        $s = rveScenarioMarcatore(0, 'Ugo');
        rveEmetti($this, $s, $s['piano'], [1], '2026-02-28');
        $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
            'pagante_id' => $s['persone']['ugo']->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2026-03-05', 'importo_totale' => '100.00',
            'descrizione' => $descrizione, 'eccedenza' => '0.00',
            'dettaglio_pagamenti' => [['rata_id' => rveQuota($s, $s['piano'], 'ugo', 1), 'importo' => '100.00']],
        ])->assertSessionHasNoErrors();
        $incasso = DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'incasso_rata')->latest('id')->first();
        expect($incasso->causale)->toBe($descrizione);
        $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.storno', [$s['c'], $incasso->id]))->assertSessionHasNoErrors();
        $quota = RataQuote::findOrFail(rveQuota($s, $s['piano'], 'ugo', 1));
        $pagina = rveEstratto($this, $s, 'ugo');
        $pdf = rvePdf($this, $s, 'ugo');
        $esiti[$descrizione] = [
            'da_pagare' => (int) $quota->importo - (int) $quota->importo_pagato,
            'pagina' => [$pagina['saldo'], $pagina['addebiti'], $pagina['versato'], $pagina['righe']],
            'pdf' => [$pdf['saldo'], $pdf['addebiti'], $pdf['versato'], $pdf['righe'], substr_count($pdf['testo'], 'Saldo finale € 100,00')],
        ];
    }

    $atteso = ['da_pagare' => 10000, 'pagina' => [10000, '€ 100,00', '€ 0,00', 3], 'pdf' => [10000, '€ 100,00', '€ 0,00', 3, 2]];
    expect($esiti)->toBe(array_fill_keys(array_keys($esiti), $atteso));
});

it('S1 — un rimborso stornato a un\'anagrafica con il marcatore del riallineamento nel nome resta nell\'estratto conto come quello senza, a video e nel PDF', function () {
    // Il nome è testo libero (lo scrive anche il condòmino, `CreateUserAnagraficaRequest`: «required|string|max:255») e finisce
    // nella causale del rimborso («Rimborso credito a …»), che lo storno ricopia. Ugo ha −20000 nella rata 0; rimborso di € 50,00 e
    // storno. Rifatto a mano: −20000 + 5000 (rimborso) − 5000 (storno) = −20000; due righe; i totali a zero (la coppia è fuori).
    // Oggi con il marcatore nel nome lo storno sparisce: −15000 e una riga, mentre il credito della quota resta 20000.
    $esiti = [];
    foreach (['Ugo Venditore', 'Ugo ' . RiallineaCreditiService::MARCATORE] as $nome) {
        $s = rveScenarioMarcatore(-20000, $nome);
        $this->actingAs($this->user)->post(route('admin.gestionale.anagrafiche.rimborsi.store', [$s['c'], $s['persone']['ugo']]), [
            'rata_quote_id' => rveQuota($s, $s['piano'], 'ugo', 0), 'cassa_id' => $s['cassa']->id, 'data_rimborso' => '2026-03-01', 'importo' => '50,00',
        ])->assertSessionHasNoErrors();
        $rimborso = DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'rimborso_condomino')->latest('id')->first();
        expect($rimborso->causale)->toContain($nome);
        $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.storno', [$s['c'], $rimborso->id]))->assertSessionHasNoErrors();
        $pagina = rveEstratto($this, $s, 'ugo');
        $pdf = rvePdf($this, $s, 'ugo');
        $esiti[$nome] = [
            'credito_quota' => (int) RataQuote::findOrFail(rveQuota($s, $s['piano'], 'ugo', 0))->credito_disponibile,
            'pagina' => [$pagina['saldo'], $pagina['addebiti'], $pagina['versato'], $pagina['righe']],
            'pdf' => [$pdf['saldo'], $pdf['addebiti'], $pdf['versato'], $pdf['righe'], substr_count($pdf['testo'], 'Saldo finale ' . MoneyHelper::format(-20000))],
        ];
    }

    $atteso = ['credito_quota' => 20000, 'pagina' => [-20000, '€ 0,00', '€ 0,00', 2], 'pdf' => [-20000, '€ 0,00', '€ 0,00', 2, 2]];
    expect($esiti)->toBe(array_fill_keys(array_keys($esiti), $atteso));
});
