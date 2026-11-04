<?php

/**
 * Coda 438, decisione 192.1 — i piani nati con le versioni più vecchie sulla gestione usata in più esercizi non si ricalcolano.
 *
 * Due forme di piano che le installazioni hanno già e che il programma non sa leggere sulla gestione usata in più esercizi:
 * (A) il piano senza l'esercizio salvato (generato prima che la generazione lo scrivesse, o creato senza generarlo prima che lo scrivesse la
 *     creazione): ricalcolato dalla pagina di un esercizio, prende quell'esercizio, e con lui il già versato che vale lì;
 * (B) il piano con la riga delle voci vuota mentre la gestione ha un piano in un altro esercizio: ricalcolato, prende le voci libere nel suo
 *     esercizio (decisione 183.2), anche quelle che chiede il piano dell'altro esercizio, senza impegnarle.
 * «Ricalcola» e «togli la voce» li rifiutano con un messaggio che dice il fatto e la strada: eliminarli e crearli di nuovo dalla pagina dei piani
 * rate del loro esercizio. Nessuna cifra si sposta. Ogni prova segue anche la strada dalle rotte, e le cifre di arrivo si rifanno dalla regola
 * (decisioni 183.1 e 183.2, la guardia della decisione 187) accanto alla prova.
 *
 * Scenario di base: Ugo (A) e Bice (C) a 500 millesimi su «Proprietà»; «Spese generali» € 1.200,00 e «Pulizie» € 200,00; la gestione ordinaria
 * agganciata al 2026 e al 2027, come la crea l'importatore. I piani della 1.10 si costruiscono generandoli dalla rotta e togliendo poi l'esercizio
 * (`piani_rate.esercizio_id`) e il registro del riparto (`righe_riparto`); i piani con la riga vuota togliendo le righe di `piano_rate_capitoli`.
 */

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\ContributoVersato;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Tabella;
use App\Services\CondominioService;
use App\Services\Riparto\QuotaDellaChiamata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta52VoceEntrataScenari.php';
require_once __DIR__.'/Beta52TotaliScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = be52Utente();
    b52tSqliteJson();
});

function g112pScenario($test, bool $conIl2027 = true): array
{
    static $seq = 950;
    $seq++;
    $c = Condominio::factory()->create();
    app(CondominioService::class)->ensureDefaultConti($c);
    $banca = ContoContabile::where('condominio_id', $c->id)->where('ruolo', 'cassa')->first()
        ?? ContoContabile::create(['condominio_id' => $c->id, 'codice' => '10.10', 'nome' => 'Banca', 'tipo' => 'attivo', 'ruolo' => 'banca', 'categoria' => 'liquidita']);
    $cassa = Cassa::create(['condominio_id' => $c->id, 'conto_contabile_id' => $banca->id, 'nome' => 'Banca principale', 'tipo' => 'banca', 'attiva' => true, 'saldo_iniziale' => 1000000]);
    $tabella = Tabella::create(['condominio_id' => $c->id, 'nome' => 'Proprietà', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    $es = [];
    $es['2026'] = Esercizio::create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = app(CondominioService::class)->createDefaultGestione($c, $es['2026']);
    if ($conIl2027) {
        $es['2027'] = Esercizio::create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
        app(CondominioService::class)->createDefaultGestione($c, $es['2027']);
    }
    $s = ['c' => $c, 'cassa' => $cassa, 'tabella' => $tabella, 'e' => $es['2026'], 'es' => $es, 'g' => $g, 'seq' => $seq, 'persone' => [], 'unita' => []];
    be52Unita($s, 'A', 'ugo', $tabella, 500.0);
    be52Unita($s, 'C', 'bice', $tabella, 500.0);
    $s['pc'] = PianoConto::create(['condominio_id' => $c->id, 'gestione_id' => $g->id, 'nome' => 'Piano dei conti']);
    $s['spesa'] = be52Voce($test, $s, $s['pc'], 'Spese generali', 'spesa', '1.200,00');
    $s['pulizie'] = be52Voce($test, $s, $s['pc'], 'Pulizie', 'spesa', '200,00');

    return $s;
}

function g112pGiaVersato($test, array $s, string $voce, string $valePer, int $cents)
{
    return $test->actingAs($test->user)->from('/gia-versato')->put(route('admin.gestionale.contributi.update', [$s['c'], $s[$voce]]), [
        'natura' => 'fondo_vincolato',
        'vale_per' => $valePer === 'tutti' ? 'tutti' : $s['es'][$valePer]->id,
        'righe' => [
            ['immobile_id' => $s['unita']['A']->id, 'anagrafica_id' => $s['persone']['ugo']->id, 'gia_versato' => $cents],
            ['immobile_id' => $s['unita']['C']->id, 'anagrafica_id' => $s['persone']['bice']->id, 'gia_versato' => 0],
        ],
        'descrizione' => 'Accantonamento di prima',
        'liquidita_stato' => ContributoVersato::LIQUIDITA_GIA_IN_APERTURA,
        'cassa_id' => null,
    ]);
}

/** Crea un piano dalla pagina dei piani rate dell'esercizio `$anno`, con «genera subito» salvo `$extra`; `$capitoli` null = senza scegliere le voci. */
function g112pCrea($test, array $s, string $anno, string $nome, ?array $capitoli, array $extra = [])
{
    return $test->actingAs($test->user)->from('/crea')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['es'][$anno]]), array_merge([
        'tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'nome' => $nome, 'metodo_distribuzione' => 'tutte_rate', 'numero_rate' => 12,
        'giorno_scadenza' => 5, 'data_prima_scadenza' => "{$anno}-01-05", 'genera_subito' => 1,
    ], $extra) + ($capitoli !== null ? ['capitoli_config' => collect($capitoli)->map(fn ($euro, $voce) => ['id' => $s[$voce]->id, 'importo' => $euro])->values()->all()] : []));
}

/** «Ricalcola» dalla pagina del piano aperta dall'elenco dell'esercizio `$anno` (la rotta che la pagina chiama, con gli stessi campi). */
function g112pRicalcola($test, array $s, string $anno, PianoRate $piano, array $corpo = [])
{
    return $test->actingAs($test->user)->from('/piano')->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['es'][$anno], $piano]), $corpo);
}

/** Il cestino accanto a una voce, dalla pagina del piano aperta dall'elenco dell'esercizio `$anno`. */
function g112pTogli($test, array $s, string $anno, PianoRate $piano, int $contoId)
{
    return $test->actingAs($test->user)->from('/piano')->delete(route('admin.gestionale.piani-rate.capitoli.detach', [$s['c'], $s['es'][$anno], $piano, $contoId]));
}

/** «Elimina» dall'elenco dei piani rate dell'esercizio `$anno`. */
function g112pElimina($test, array $s, string $anno, PianoRate $piano)
{
    return $test->actingAs($test->user)->from('/elenco')->delete(route('admin.gestionale.esercizi.piani-rate.destroy', [$s['c'], $s['es'][$anno], $piano]));
}

function g112pPiano(array $s, string $nome): ?PianoRate
{
    return PianoRate::where('condominio_id', $s['c']->id)->where('nome', $nome)->first();
}

function g112pChiesto(array $s, string $nome): array
{
    $piano = g112pPiano($s, $nome);
    if ($piano === null) {
        return ['piano' => 'non c\'è'];
    }

    return collect($s['persone'])->map(fn ($a) => (int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')
        ->where('rate.piano_rate_id', $piano->id)->where('rate_quote.anagrafica_id', $a->id)->sum('rate_quote.importo'))->all();
}

/** Tutto ciò che un ricalcolo riscriverebbe: l'esercizio e lo stato del piano, la riga delle voci, le rate e le quote (con i loro id), il registro del riparto. */
function g112pFoto(PianoRate $piano): array
{
    $riga = DB::table('piani_rate')->where('id', $piano->id)->first();

    return [
        'esercizio_id' => $riga->esercizio_id,
        'stato' => $riga->stato,
        'updated_at' => $riga->updated_at,
        'voci' => DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->orderBy('id')->get(['id', 'conto_id', 'importo'])->map(fn ($r) => (array) $r)->all(),
        'rate' => DB::table('rate')->where('piano_rate_id', $piano->id)->orderBy('id')->get(['id', 'numero_rata', 'importo_totale'])->map(fn ($r) => (array) $r)->all(),
        'quote' => DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)->orderBy('rate_quote.id')
            ->get(['rate_quote.id', 'rate_quote.anagrafica_id', 'rate_quote.importo'])->map(fn ($r) => (array) $r)->all(),
        'righe_riparto' => DB::table('righe_riparto')->where('piano_rate_id', $piano->id)->count(),
    ];
}

function g112pErrore($r): ?string
{
    $m = $r->getSession()->get('message');

    return ($m['type'] ?? null) === 'error' ? ($m['message'] ?? null) : null;
}

/** Il piano generato con la 1.10 (o con una 1.11 prima che la generazione scrivesse l'esercizio): né l'esercizio né il registro del riparto. */
function g112pDella110(PianoRate $piano): void
{
    DB::table('piani_rate')->where('id', $piano->id)->update(['esercizio_id' => null]);
    DB::table('righe_riparto')->where('piano_rate_id', $piano->id)->delete();
}

/** Il piano con la riga delle voci vuota: com'era la creazione di prima quando le voci erano tutte nei piani di un altro esercizio. */
function g112pRigaVuota(PianoRate $piano): void
{
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->delete();
}

/** Un capitolo dal modale (importo zero, nessuna tabella) e i suoi sottoconti su «Proprietà». */
function g112pCapitolo($test, array $s, string $nome, array $figli): array
{
    $voce = fn (array $campi) => $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$s['c'], $s['e'], $s['pc']]), array_merge([
        'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => 'spesa', 'descrizione' => '', 'note' => '',
        'parent_id' => null, 'tabella_millesimale_id' => $s['tabella']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
    ], $campi))->assertSessionHasNoErrors();
    $voce(['nome' => $nome, 'importo' => '0,00', 'isCapitolo' => true, 'tabella_millesimale_id' => null, 'percentuale_proprietario' => null, 'percentuale_inquilino' => null, 'percentuale_usufruttuario' => null]);
    $padre = \App\Models\Gestionale\Conto::where('piano_conto_id', $s['pc']->id)->where('nome', $nome)->firstOrFail();
    $esito = ['padre' => $padre];
    foreach ($figli as $figlio => $importo) {
        $voce(['nome' => $figlio, 'importo' => $importo, 'parent_id' => $padre->id, 'isSottoConto' => true]);
        $esito[$figlio] = \App\Models\Gestionale\Conto::where('piano_conto_id', $s['pc']->id)->where('nome', $figlio)->firstOrFail();
    }

    return $esito;
}

/** Il solo capitolo «Manutenzione» (Ascensore € 600,00, Caldaia € 400,00): le due voci senza figli dello scenario si tolgono dal piano dei conti. */
function g112pSoloManutenzione($test, array &$s): array
{
    DB::table('conto_tabella_millesimale')->whereIn('conto_id', [$s['spesa']->id, $s['pulizie']->id])->delete();
    DB::table('conti')->whereIn('id', [$s['spesa']->id, $s['pulizie']->id])->delete();
    $man = g112pCapitolo($test, $s, 'Manutenzione', ['Ascensore' => '600,00', 'Caldaia' => '400,00']);
    $s['manutenzione'] = $man['padre'];
    $s['ascensore'] = $man['Ascensore'];
    $s['caldaia'] = $man['Caldaia'];

    return $man;
}

/** La gestione straordinaria agganciata al 2026 e al 2027, la voce «Facciata» su «Proprietà» e una fattura del 2026 di € 1.000,00 sulla voce. */
function g112pStraordinaria($test, array &$s): void
{
    $s['gs'] = app(CondominioService::class)->createDefaultGestione($s['c'], $s['es']['2026'], 'straordinaria');
    app(CondominioService::class)->createDefaultGestione($s['c'], $s['es']['2027'], 'straordinaria');
    $pcs = PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $s['gs']->id, 'nome' => 'Piano dei conti straordinario']);
    $s['facciata'] = be52Voce($test, $s, $pcs, 'Facciata', 'spesa', '1.000,00');
    $fornitoreId = DB::table('fornitori')->insertGetId([
        'ragione_sociale' => 'Fornitore di prova Srl', 'soggetto_ritenuta' => false, 'ritenuta_decisa_il' => now(), 'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 4,
        'giorni_scadenza' => 30, 'modalita_pagamento_default' => 'bonifico', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $s['fattura'] = \App\Models\Gestionale\FatturaPassiva::create(['condominio_id' => $s['c']->id, 'fornitore_id' => $fornitoreId, 'esercizio_id' => $s['es']['2026']->id,
        'tipo_documento' => 'fattura', 'numero_documento' => 'FT-FACCIATA', 'data_documento' => '2026-03-10', 'data_scadenza' => '2026-04-10', 'is_pregresso' => false,
        'importo_imponibile' => 100000, 'importo_iva' => 0, 'importo_ritenuta' => 0, 'totale_documento' => 100000, 'netto_a_pagare' => 100000, 'stato_pagamento' => 'aperta',
        'stato_approvazione' => 'approvata', 'modalita_pagamento' => 'bonifico']);
    DB::table('righe_fattura')->insert(['fattura_passiva_id' => $s['fattura']->id, 'conto_id' => $s['facciata']->id, 'immobile_id' => null, 'descrizione' => 'Facciata',
        'aliquota_iva' => 0, 'importo_imponibile' => 100000, 'importo_iva' => 0, 'is_sopravvenienza' => true, 'is_rateizzata' => false, 'created_at' => now(), 'updated_at' => now()]);
}

/** Il piano straordinario dalla pagina dei piani rate dell'esercizio `$anno`, con la fattura della facciata e «genera subito» (nasce approvato). */
function g112pCreaStraordinario($test, array $s, string $anno, string $nome)
{
    return $test->actingAs($test->user)->from('/crea')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['es'][$anno]]), [
        'tipo' => 'straordinario', 'gestione_id' => $s['gs']->id, 'nome' => $nome, 'metodo_distribuzione' => 'tutte_rate', 'numero_rate' => 2,
        'giorno_scadenza' => 10, 'data_prima_scadenza' => $anno . '-03-10', 'genera_subito' => 1,
        'fatture_config' => [['id' => $s['fattura']->id, 'importo' => '1.000,00']],
        'tipo_autorizzazione' => 'delibera', 'motivazione_autorizzazione' => 'Delibera di prova', 'data_delibera_assemblea' => $anno . '-02-01',
    ]);
}

/** Il rifiuto del piano senza esercizio: il fatto con i nomi, il perché e la strada (decisione 192.1). */
function g112pRifiutoSenzaEsercizio(?string $msg, string $impossibile, string $piano, bool $approvato = false, string $gestione = 'Gestione ordinaria', string $cosa = 'le voci'): void
{
    expect($msg)->not->toBeNull()
        ->and($msg)->toStartWith($impossibile . ': il piano rate «' . $piano . '» non ha il suo esercizio salvato, e la gestione «' . $gestione . '» è usata in più esercizi («Esercizio 2026» e «Esercizio 2027»).')
        ->and($msg)->toContain('Il programma non sa a quale di questi appartiene il piano')
        ->and($msg)->toEndWith('Per rifarlo, ' . ($approvato ? 'riportalo in bozza dalla sua pagina, ' : '') . 'eliminalo dall\'elenco dei piani rate e crealo di nuovo dalla pagina dei piani rate del suo esercizio, scegliendo ' . $cosa . '.');
}

/** Il rifiuto del piano con la riga vuota: il fatto con i nomi (il piano dell'altro esercizio), il perché e la strada nell'esercizio del piano. */
function g112pRifiutoRigaVuota(?string $msg, string $impossibile, string $piano, string $altri, string $esercizio): void
{
    expect($msg)->not->toBeNull()
        ->and($msg)->toStartWith($impossibile . ': il piano rate «' . $piano . '» non ha voci associate, e la gestione «Gestione ordinaria» ' . $altri . '.')
        ->and($msg)->toContain('Il programma non sa quali voci spettano a questo piano')
        ->and($msg)->toEndWith('Per rifarlo, eliminalo dall\'elenco dei piani rate e crealo di nuovo dalla pagina dei piani rate dell\'esercizio «' . $esercizio . '», scegliendo le voci.');
}

// --- (A) Il piano senza l'esercizio salvato ---------------------------------------------------------------------------------------------

it('Coda 438 — un piano del 2026 senza esercizio, aperto dall\'elenco del 2027: «Ricalcola» è rifiutato, il piano resta com\'era (Ugo € 400,00), e la strada dalla pagina del 2026 dà le stesse cifre', function () {
    $this->travelTo(now()->setDate(2026, 3, 1)->setTime(10, 0));
    $s = g112pScenario($this);
    g112pGiaVersato($this, $s, 'spesa', '2026', 30000)->assertSessionHasNoErrors();
    expect(g112pErrore(g112pCrea($this, $s, '2026', 'P26', ['spesa' => '1.200,00', 'pulizie' => '200,00'])))->toBeNull();
    $p26 = g112pPiano($s, 'P26');
    // Il piano generato dalla pagina del 2026 a marzo 2026, con il registro del riparto ma senza l'esercizio (la migrazione non lo riempie sulla gestione di due esercizi).
    DB::table('piani_rate')->where('id', $p26->id)->update(['esercizio_id' => null]);
    // A mano (decisione 183.1): P26 è del 2026 e il già versato del 2026 vale lì: Ugo 600 − 300 + 100 = 400, Bice 600 + 100 = 700.
    expect(g112pChiesto($s, 'P26'))->toBe(['ugo' => 40000, 'bice' => 70000]);
    $prima = g112pFoto($p26);

    $this->travelTo(now()->setDate(2027, 4, 15)->setTime(10, 0));
    $elenco = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.piani-rate.index', [$s['c'], $s['es']['2027']]));
    expect(collect($elenco->viewData('page')['props']['pianiRate'])->pluck('nome')->all())->toContain('P26');
    $r = g112pRicalcola($this, $s, '2027', $p26);
    g112pRifiutoSenzaEsercizio(g112pErrore($r), 'Impossibile ricalcolare', 'P26');
    // Niente è cambiato: l'esercizio ancora nullo, le stesse rate e le stesse quote, Ugo € 400,00 e Bice € 700,00.
    expect(g112pFoto($p26))->toBe($prima)
        ->and(g112pChiesto($s, 'P26'))->toBe(['ugo' => 40000, 'bice' => 70000])
        ->and(QuotaDellaChiamata::esercizioDelPiano($p26->id))->toBe($s['es']['2026']->id);

    // La strada: eliminato dall'elenco, creato di nuovo dalla pagina del 2026 con le due voci. A mano: 400 e 700, come prima.
    expect(g112pErrore(g112pElimina($this, $s, '2027', $p26)))->toBeNull()
        ->and(PianoRate::find($p26->id))->toBeNull();
    expect(g112pErrore(g112pCrea($this, $s, '2026', 'P26', ['spesa' => '1.200,00', 'pulizie' => '200,00'])))->toBeNull()
        ->and(g112pPiano($s, 'P26')->esercizio_id)->toBe($s['es']['2026']->id)
        ->and(g112pChiesto($s, 'P26'))->toBe(['ugo' => 40000, 'bice' => 70000]);
});

it('Coda 438 — lo stesso piano senza esercizio è rifiutato anche dalla pagina del suo esercizio: il rifiuto non dipende dalla pagina', function () {
    $this->travelTo(now()->setDate(2026, 3, 1)->setTime(10, 0));
    $s = g112pScenario($this);
    g112pGiaVersato($this, $s, 'spesa', '2026', 30000)->assertSessionHasNoErrors();
    expect(g112pErrore(g112pCrea($this, $s, '2026', 'P26', ['spesa' => '1.200,00', 'pulizie' => '200,00'])))->toBeNull();
    $p26 = g112pPiano($s, 'P26');
    DB::table('piani_rate')->where('id', $p26->id)->update(['esercizio_id' => null]);
    $prima = g112pFoto($p26);
    $this->travelTo(now()->setDate(2027, 4, 15)->setTime(10, 0));

    g112pRifiutoSenzaEsercizio(g112pErrore(g112pRicalcola($this, $s, '2026', $p26)), 'Impossibile ricalcolare', 'P26');
    // A mano: com'era, 400 e 700.
    expect(g112pFoto($p26))->toBe($prima)->and(g112pChiesto($s, 'P26'))->toBe(['ugo' => 40000, 'bice' => 70000]);
});

it('Coda 438 — il piano del 2026 creato e generato a febbraio 2027, senza esercizio (dedotto del 2027), ricalcolato dalla pagina del 2026: rifiutato, e la strada dà Ugo € 400,00', function () {
    // Il caso che la Coda 75 nomina: la deduzione dalla data di creazione dà il 2027 al piano del 2026 creato nel 2027.
    $this->travelTo(now()->setDate(2027, 2, 10)->setTime(10, 0));
    $s = g112pScenario($this);
    g112pGiaVersato($this, $s, 'spesa', '2026', 30000)->assertSessionHasNoErrors();
    expect(g112pErrore(g112pCrea($this, $s, '2026', 'C26', ['spesa' => '1.200,00', 'pulizie' => '200,00'])))->toBeNull();
    $c26 = g112pPiano($s, 'C26');
    // Generato dalla pagina del 2026: 400 e 700.
    expect(g112pChiesto($s, 'C26'))->toBe(['ugo' => 40000, 'bice' => 70000]);
    DB::table('piani_rate')->where('id', $c26->id)->update(['esercizio_id' => null]);
    expect(QuotaDellaChiamata::esercizioDelPiano($c26->id))->toBe($s['es']['2027']->id);
    $prima = g112pFoto($c26);

    g112pRifiutoSenzaEsercizio(g112pErrore(g112pRicalcola($this, $s, '2026', $c26)), 'Impossibile ricalcolare', 'C26');
    expect(g112pFoto($c26))->toBe($prima)->and(g112pChiesto($s, 'C26'))->toBe(['ugo' => 40000, 'bice' => 70000]);

    // La strada: dalla pagina del 2026 il piano nuovo ricorda il 2026. A mano (183.1): 600 − 300 + 100 = 400, Bice 700.
    expect(g112pErrore(g112pElimina($this, $s, '2026', $c26)))->toBeNull();
    expect(g112pErrore(g112pCrea($this, $s, '2026', 'C26', ['spesa' => '1.200,00', 'pulizie' => '200,00'])))->toBeNull()
        ->and(g112pPiano($s, 'C26')->esercizio_id)->toBe($s['es']['2026']->id)
        ->and(g112pChiesto($s, 'C26'))->toBe(['ugo' => 40000, 'bice' => 70000]);
});

it('Coda 438 — un piano del 2026 creato senza generarlo e senza esercizio (creato prima che la creazione lo salvasse): la prima generazione è rifiutata, e la strada lo crea nel 2026', function () {
    $this->travelTo(now()->setDate(2027, 2, 10)->setTime(10, 0));
    $s = g112pScenario($this);
    g112pGiaVersato($this, $s, 'spesa', '2026', 30000)->assertSessionHasNoErrors();
    expect(g112pErrore(g112pCrea($this, $s, '2026', 'N26', ['spesa' => '1.200,00', 'pulizie' => '200,00'], ['genera_subito' => 0])))->toBeNull();
    $n26 = g112pPiano($s, 'N26');
    DB::table('piani_rate')->where('id', $n26->id)->update(['esercizio_id' => null]);
    $prima = g112pFoto($n26);

    g112pRifiutoSenzaEsercizio(g112pErrore(g112pRicalcola($this, $s, '2026', $n26)), 'Impossibile ricalcolare', 'N26');
    // Niente è cambiato: nessuna rata, la riga delle voci com'era.
    expect(g112pFoto($n26))->toBe($prima)->and($prima['rate'])->toBe([])->and(g112pChiesto($s, 'N26'))->toBe(['ugo' => 0, 'bice' => 0]);

    // A mano (183.1): dalla pagina del 2026, 600 − 300 + 100 = 400, Bice 700.
    expect(g112pErrore(g112pElimina($this, $s, '2026', $n26)))->toBeNull();
    expect(g112pErrore(g112pCrea($this, $s, '2026', 'N26', ['spesa' => '1.200,00', 'pulizie' => '200,00'])))->toBeNull()
        ->and(g112pChiesto($s, 'N26'))->toBe(['ugo' => 40000, 'bice' => 70000]);
});

it('Coda 438 — un piano del 2026 generato con la 1.10 (senza esercizio né registro del riparto), aperto dall\'elenco del 2027: «Ricalcola» è rifiutato e il piano resta com\'era', function () {
    $this->travelTo(now()->setDate(2026, 3, 1)->setTime(10, 0));
    $s = g112pScenario($this);
    g112pGiaVersato($this, $s, 'spesa', '2026', 30000)->assertSessionHasNoErrors();
    expect(g112pErrore(g112pCrea($this, $s, '2026', 'P26', ['spesa' => '1.200,00', 'pulizie' => '200,00'])))->toBeNull();
    $p26 = g112pPiano($s, 'P26');
    g112pDella110($p26);
    expect(DB::table('rate')->where('piano_rate_id', $p26->id)->exists())->toBeTrue();
    $prima = g112pFoto($p26);

    $this->travelTo(now()->setDate(2027, 4, 15)->setTime(10, 0));
    g112pRifiutoSenzaEsercizio(g112pErrore(g112pRicalcola($this, $s, '2027', $p26)), 'Impossibile ricalcolare', 'P26');
    // A mano (183.1): 400 e 700, com'era; l'esercizio resta nullo, il registro del riparto resta assente.
    expect(g112pFoto($p26))->toBe($prima)->and($prima['righe_riparto'])->toBe(0)
        ->and(g112pChiesto($s, 'P26'))->toBe(['ugo' => 40000, 'bice' => 70000]);

    expect(g112pErrore(g112pElimina($this, $s, '2027', $p26)))->toBeNull();
    expect(g112pErrore(g112pCrea($this, $s, '2026', 'P26', ['spesa' => '1.200,00', 'pulizie' => '200,00'])))->toBeNull()
        ->and(g112pChiesto($s, 'P26'))->toBe(['ugo' => 40000, 'bice' => 70000]);
});

it('Coda 438 — lo stesso piano della 1.10: «togli la voce» dall\'elenco del 2027 è rifiutato, la voce resta, e la strada senza «Pulizie» dà Ugo € 300,00', function () {
    $this->travelTo(now()->setDate(2026, 3, 1)->setTime(10, 0));
    $s = g112pScenario($this);
    g112pGiaVersato($this, $s, 'spesa', '2026', 30000)->assertSessionHasNoErrors();
    expect(g112pErrore(g112pCrea($this, $s, '2026', 'P26', ['spesa' => '1.200,00', 'pulizie' => '200,00'])))->toBeNull();
    $p26 = g112pPiano($s, 'P26');
    g112pDella110($p26);
    $prima = g112pFoto($p26);

    $this->travelTo(now()->setDate(2027, 4, 15)->setTime(10, 0));
    g112pRifiutoSenzaEsercizio(g112pErrore(g112pTogli($this, $s, '2027', $p26, $s['pulizie']->id)), 'Impossibile togliere la voce', 'P26');
    // Niente è cambiato: «Pulizie» ancora nella riga, 400 e 700.
    expect(g112pFoto($p26))->toBe($prima)
        ->and(collect($prima['voci'])->pluck('conto_id')->map(fn ($id) => (int) $id)->all())->toContain($s['pulizie']->id)
        ->and(g112pChiesto($s, 'P26'))->toBe(['ugo' => 40000, 'bice' => 70000]);

    // La strada: creato di nuovo dalla pagina del 2026 con la sola «Spese generali». A mano: Ugo 600 − 300 = 300, Bice 600.
    expect(g112pErrore(g112pElimina($this, $s, '2027', $p26)))->toBeNull();
    expect(g112pErrore(g112pCrea($this, $s, '2026', 'P26', ['spesa' => '1.200,00'])))->toBeNull()
        ->and(g112pChiesto($s, 'P26'))->toBe(['ugo' => 30000, 'bice' => 60000]);
});

it('Coda 438 — il piano del 2026 preparato a dicembre 2025 (dedotto del 2027), senza esercizio: rifiutato dalla pagina del 2026, e la strada toglie il già versato del 2026', function () {
    $this->travelTo(now()->setDate(2025, 12, 15)->setTime(10, 0));
    $s = g112pScenario($this);
    expect(g112pErrore(g112pCrea($this, $s, '2026', 'P26', ['spesa' => '1.200,00', 'pulizie' => '200,00'])))->toBeNull();
    $p26 = g112pPiano($s, 'P26');
    g112pDella110($p26);
    // La deduzione: la data di creazione non è in nessun esercizio della gestione, e vale il più recente.
    expect(QuotaDellaChiamata::esercizioDelPiano($p26->id))->toBe($s['es']['2027']->id);
    $this->travelTo(now()->setDate(2026, 4, 15)->setTime(10, 0));
    g112pGiaVersato($this, $s, 'spesa', '2026', 30000)->assertSessionHasNoErrors();
    $prima = g112pFoto($p26);

    g112pRifiutoSenzaEsercizio(g112pErrore(g112pRicalcola($this, $s, '2026', $p26)), 'Impossibile ricalcolare', 'P26');
    // A mano: generato prima del già versato, 600 + 100 = 700 a testa, com'era.
    expect(g112pFoto($p26))->toBe($prima)->and(g112pChiesto($s, 'P26'))->toBe(['ugo' => 70000, 'bice' => 70000]);

    // La strada: dalla pagina del 2026. A mano (183.1): 600 − 300 + 100 = 400, Bice 700.
    expect(g112pErrore(g112pElimina($this, $s, '2026', $p26)))->toBeNull();
    expect(g112pErrore(g112pCrea($this, $s, '2026', 'P26', ['spesa' => '1.200,00', 'pulizie' => '200,00'])))->toBeNull()
        ->and(g112pChiesto($s, 'P26'))->toBe(['ugo' => 40000, 'bice' => 70000]);
});

it('Coda 438 — il preventivo del 2027 preparato con la 1.10 a novembre 2026 (dedotto del 2026): rifiutato dalla pagina del 2027, e la strada toglie il già versato del 2027', function () {
    $this->travelTo(now()->setDate(2026, 11, 15)->setTime(10, 0));
    $s = g112pScenario($this);
    expect(g112pErrore(g112pCrea($this, $s, '2027', 'P27', ['spesa' => '1.200,00', 'pulizie' => '200,00'])))->toBeNull();
    $p27 = g112pPiano($s, 'P27');
    g112pDella110($p27);
    expect(QuotaDellaChiamata::esercizioDelPiano($p27->id))->toBe($s['es']['2026']->id);
    $this->travelTo(now()->setDate(2027, 1, 20)->setTime(10, 0));
    g112pGiaVersato($this, $s, 'spesa', '2027', 30000)->assertSessionHasNoErrors();
    $prima = g112pFoto($p27);

    g112pRifiutoSenzaEsercizio(g112pErrore(g112pRicalcola($this, $s, '2027', $p27)), 'Impossibile ricalcolare', 'P27');
    // A mano: generato prima del già versato, 700 a testa, com'era.
    expect(g112pFoto($p27))->toBe($prima)->and(g112pChiesto($s, 'P27'))->toBe(['ugo' => 70000, 'bice' => 70000]);

    // La strada: dalla pagina del 2027. A mano (183.1): 600 − 300 + 100 = 400, Bice 700.
    expect(g112pErrore(g112pElimina($this, $s, '2027', $p27)))->toBeNull();
    expect(g112pErrore(g112pCrea($this, $s, '2027', 'P27', ['spesa' => '1.200,00', 'pulizie' => '200,00'])))->toBeNull()
        ->and(g112pPiano($s, 'P27')->esercizio_id)->toBe($s['es']['2027']->id)
        ->and(g112pChiesto($s, 'P27'))->toBe(['ugo' => 40000, 'bice' => 70000]);
});

it('Coda 438 — il piano approvato senza esercizio: il messaggio dice di riportarlo in bozza, l\'eliminazione diretta è rifiutata, e la strada scritta si percorre dalle rotte', function () {
    $this->travelTo(now()->setDate(2026, 3, 1)->setTime(10, 0));
    $s = g112pScenario($this);
    g112pGiaVersato($this, $s, 'spesa', '2026', 30000)->assertSessionHasNoErrors();
    expect(g112pErrore(g112pCrea($this, $s, '2026', 'P26', ['spesa' => '1.200,00', 'pulizie' => '200,00'])))->toBeNull();
    $p26 = g112pPiano($s, 'P26');
    $this->actingAs($this->user)->from('/piano')->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['es']['2026'], $p26]), [
        'approvato' => true, 'data_delibera_assemblea' => '2026-02-20',
    ])->assertSessionHasNoErrors();
    g112pDella110($p26);
    $prima = g112pFoto($p26);
    expect($prima['stato'])->toBe('approvato');

    $this->travelTo(now()->setDate(2027, 4, 15)->setTime(10, 0));
    $r = g112pRicalcola($this, $s, '2027', $p26);
    g112pRifiutoSenzaEsercizio(g112pErrore($r), 'Impossibile ricalcolare', 'P26', approvato: true);
    // Il messaggio intero, con il perché.
    expect(g112pErrore($r))->toBe('Impossibile ricalcolare: il piano rate «P26» non ha il suo esercizio salvato, e la gestione «Gestione ordinaria» è usata in più esercizi '
        . '(«Esercizio 2026» e «Esercizio 2027»). Il programma non sa a quale di questi appartiene il piano: ricalcolarlo, anche togliendo una voce, lo legherebbe '
        . 'all\'esercizio della pagina da cui lo fai, e se non è il suo il piano perderebbe il già versato del suo esercizio o prenderebbe quello di un altro. '
        . 'Per rifarlo, riportalo in bozza dalla sua pagina, eliminalo dall\'elenco dei piani rate e crealo di nuovo dalla pagina dei piani rate del suo esercizio, scegliendo le voci.');
    expect(g112pFoto($p26))->toBe($prima);

    // Senza tornare in bozza l'eliminazione è rifiutata: per questo il messaggio lo dice.
    expect(g112pErrore(g112pElimina($this, $s, '2027', $p26)))->toContain('Impossibile eliminare un piano rate approvato');
    // La strada: in bozza dalla sua pagina, eliminato dall'elenco, creato di nuovo dal 2026. A mano: 400 e 700.
    $this->actingAs($this->user)->from('/piano')->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['es']['2027'], $p26]), ['approvato' => false])
        ->assertSessionHasNoErrors();
    expect(g112pErrore(g112pElimina($this, $s, '2027', $p26)))->toBeNull();
    expect(g112pErrore(g112pCrea($this, $s, '2026', 'P26', ['spesa' => '1.200,00', 'pulizie' => '200,00'])))->toBeNull()
        ->and(g112pChiesto($s, 'P26'))->toBe(['ugo' => 40000, 'bice' => 70000]);
});

it('Coda 438 — il rifiuto viene dopo quelli di prima: un piano senza esercizio con una rata emessa dice di annullare le emissioni, come prima', function () {
    $this->travelTo(now()->setDate(2026, 3, 1)->setTime(10, 0));
    $s = g112pScenario($this);
    expect(g112pErrore(g112pCrea($this, $s, '2026', 'P26', ['spesa' => '1.200,00', 'pulizie' => '200,00'])))->toBeNull();
    $p26 = g112pPiano($s, 'P26');
    // Approvato ed emesso (la prima rata) dalla pagina del 2026, poi l'esercizio tolto come nei piani della 1.10.
    $this->actingAs($this->user)->from('/piano')->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['es']['2026'], $p26]), [
        'approvato' => true, 'data_delibera_assemblea' => '2026-02-20',
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->user)->from('/piano')->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $p26]), [
        'rate_ids' => [b52tRata($p26, 1)], 'data_emissione' => '2026-03-01', 'invia_notifiche' => false,
    ])->assertSessionHasNoErrors();
    expect(PianoRate::find($p26->id)->haRateEmesse())->toBeTrue();
    g112pDella110($p26);

    $this->travelTo(now()->setDate(2027, 4, 15)->setTime(10, 0));
    expect(g112pErrore(g112pRicalcola($this, $s, '2027', $p26)))->toBe('Impossibile ricalcolare: ci sono rate già emesse in contabilità. Annulla prima le emissioni.')
        ->and(g112pErrore(g112pTogli($this, $s, '2027', $p26, $s['pulizie']->id)))->toBe('Annulla le emissioni prima di modificare le voci.');
});

// --- (B) Il piano con la riga delle voci vuota ------------------------------------------------------------------------------------------

it('Coda 438 — il piano del 2027 nato senza voci, con il già versato «per tutti gli esercizi» e il piano del 2026 che lo toglie: rifiutato; la strada si ferma alla guardia, e indicato il 2026 dà € 700,00 a testa', function () {
    $this->travelTo(now()->setDate(2027, 1, 20)->setTime(10, 0));
    $s = g112pScenario($this);
    g112pGiaVersato($this, $s, 'spesa', 'tutti', 30000)->assertSessionHasNoErrors();
    expect(g112pErrore(g112pCrea($this, $s, '2026', 'P26', ['spesa' => '1.200,00', 'pulizie' => '200,00'])))->toBeNull();
    // A mano: P26 chiede le due voci e il già versato vale lì per intero: Ugo 600 − 300 + 100 = 400, Bice 700.
    expect(g112pChiesto($s, 'P26'))->toBe(['ugo' => 40000, 'bice' => 70000]);
    expect(g112pErrore(g112pCrea($this, $s, '2027', 'P27', null, ['genera_subito' => 0])))->toBeNull();
    $p27 = g112pPiano($s, 'P27');
    g112pRigaVuota($p27);
    [$prima27, $prima26] = [g112pFoto($p27), g112pFoto(g112pPiano($s, 'P26'))];

    $r = g112pRicalcola($this, $s, '2027', $p27);
    g112pRifiutoRigaVuota(g112pErrore($r), 'Impossibile ricalcolare', 'P27', 'ha un piano in un altro esercizio: «P26» («Esercizio 2026»)', 'Esercizio 2027');
    expect(g112pErrore($r))->toBe('Impossibile ricalcolare: il piano rate «P27» non ha voci associate, e la gestione «Gestione ordinaria» ha un piano in un altro esercizio: '
        . '«P26» («Esercizio 2026»). Il programma non sa quali voci spettano a questo piano: ricalcolato senza voci prenderebbe tutte quelle che nessun piano del suo '
        . 'esercizio ha già, e potrebbe chiedere di nuovo quelle del piano dell\'altro esercizio o togliere due volte il già versato. '
        . 'Per rifarlo, eliminalo dall\'elenco dei piani rate e crealo di nuovo dalla pagina dei piani rate dell\'esercizio «Esercizio 2027», scegliendo le voci.');
    // Niente è cambiato: la riga ancora vuota, P27 senza quote, P26 400 e 700.
    expect(g112pFoto($p27))->toBe($prima27)->and($prima27['voci'])->toBe([])
        ->and(g112pFoto(g112pPiano($s, 'P26')))->toBe($prima26)
        ->and(g112pChiesto($s, 'P27'))->toBe(['ugo' => 0, 'bice' => 0]);

    // La strada. Eliminato, e creato di nuovo dalla pagina del 2027 senza scegliere le voci: dalla 183.2 prende le due voci, e la guardia della decisione 187
    // lo ferma (P26 e P27 chiedono € 1.200,00 ciascuno sulla voce da € 1.200,00, e i € 300,00 di Ugo «per tutti gli esercizi» non si tolgono in tutti e due).
    expect(g112pErrore(g112pElimina($this, $s, '2027', $p27)))->toBeNull();
    $r2 = g112pCrea($this, $s, '2027', 'P27', null);
    expect(g112pErrore($r2))->toContain('ha un già versato registrato che vale per tutti gli esercizi, ed è già nel piano «P26»')
        ->and(g112pErrore($r2))->toContain('indicalo nella pagina «Già versato» della voce')
        ->and(g112pPiano($s, 'P27'))->toBeNull();
    // Indicato che il già versato è del 2026, il piano nuovo del 2027 non lo toglie. A mano (183.1 e 183.2): 600 + 100 = 700 a testa; P26 com'era.
    g112pGiaVersato($this, $s, 'spesa', '2026', 30000)->assertSessionHasNoErrors();
    expect(g112pErrore(g112pCrea($this, $s, '2027', 'P27', null)))->toBeNull()
        ->and(g112pChiesto($s, 'P27'))->toBe(['ugo' => 70000, 'bice' => 70000])
        ->and(g112pChiesto($s, 'P26'))->toBe(['ugo' => 40000, 'bice' => 70000]);
});

it('Coda 438 — lo stesso piano nato senza voci con il già versato indicato per il 2026: rifiutato, e la strada senza scegliere le voci dà € 700,00 a testa', function () {
    $this->travelTo(now()->setDate(2027, 1, 20)->setTime(10, 0));
    $s = g112pScenario($this);
    g112pGiaVersato($this, $s, 'spesa', '2026', 30000)->assertSessionHasNoErrors();
    expect(g112pErrore(g112pCrea($this, $s, '2026', 'P26', ['spesa' => '1.200,00', 'pulizie' => '200,00'])))->toBeNull();
    expect(g112pErrore(g112pCrea($this, $s, '2027', 'P27', null, ['genera_subito' => 0])))->toBeNull();
    $p27 = g112pPiano($s, 'P27');
    g112pRigaVuota($p27);
    $prima = g112pFoto($p27);

    g112pRifiutoRigaVuota(g112pErrore(g112pRicalcola($this, $s, '2027', $p27)), 'Impossibile ricalcolare', 'P27', 'ha un piano in un altro esercizio: «P26» («Esercizio 2026»)', 'Esercizio 2027');
    expect(g112pFoto($p27))->toBe($prima);

    // A mano (183.1 e 183.2): il già versato del 2026 non vale nel 2027, le due voci non sono in un piano del 2027: 600 + 100 = 700 a testa.
    expect(g112pErrore(g112pElimina($this, $s, '2027', $p27)))->toBeNull();
    expect(g112pErrore(g112pCrea($this, $s, '2027', 'P27', null)))->toBeNull()
        ->and(g112pChiesto($s, 'P27'))->toBe(['ugo' => 70000, 'bice' => 70000])
        ->and(g112pChiesto($s, 'P26'))->toBe(['ugo' => 40000, 'bice' => 70000]);
});

it('Coda 438 — il piano del 2027 nato senza voci non chiede le voci senza impegnarle: rifiutato, e dopo la strada un integrativo senza voci scelte non ha niente da chiedere', function () {
    $this->travelTo(now()->setDate(2027, 1, 20)->setTime(10, 0));
    $s = g112pScenario($this);
    expect(g112pErrore(g112pCrea($this, $s, '2026', 'P26', ['spesa' => '1.200,00', 'pulizie' => '200,00'])))->toBeNull();
    expect(g112pErrore(g112pCrea($this, $s, '2027', 'P27', null, ['genera_subito' => 0])))->toBeNull();
    $p27 = g112pPiano($s, 'P27');
    g112pRigaVuota($p27);
    $prima = g112pFoto($p27);

    g112pRifiutoRigaVuota(g112pErrore(g112pRicalcola($this, $s, '2027', $p27)), 'Impossibile ricalcolare', 'P27', 'ha un piano in un altro esercizio: «P26» («Esercizio 2026»)', 'Esercizio 2027');
    // Con le voci da aggiungere dal modale del ricalcolo il rifiuto è lo stesso: la riga resta vuota.
    g112pRifiutoRigaVuota(g112pErrore(g112pRicalcola($this, $s, '2027', $p27, ['orphan_ids' => [$s['spesa']->id, $s['pulizie']->id]])), 'Impossibile ricalcolare', 'P27',
        'ha un piano in un altro esercizio: «P26» («Esercizio 2026»)', 'Esercizio 2027');
    expect(g112pFoto($p27))->toBe($prima)->and(g112pChiesto($s, 'P27'))->toBe(['ugo' => 0, 'bice' => 0]);

    // La strada: P27 di nuovo dalla pagina del 2027 senza scegliere le voci. A mano (183.2): le due voci, 700 a testa; la riga le ha.
    expect(g112pErrore(g112pElimina($this, $s, '2027', $p27)))->toBeNull();
    expect(g112pErrore(g112pCrea($this, $s, '2027', 'P27', null)))->toBeNull()
        ->and(g112pChiesto($s, 'P27'))->toBe(['ugo' => 70000, 'bice' => 70000])
        ->and(DB::table('piano_rate_capitoli')->where('piano_rate_id', g112pPiano($s, 'P27')->id)->count())->toBe(2);
    // Un integrativo del 2027 senza voci scelte: le voci sono nel P27, che le chiede (183.2): niente da chiedere, il rifiuto di oggi.
    $r = g112pCrea($this, $s, '2027', 'I27', null);
    expect(g112pErrore($r))->toContain('tutte le voci di questa gestione sono già nel piano «P27»')
        ->and(g112pPiano($s, 'I27'))->toBeNull();
});

it('Coda 438 — il piano del 2027 nato senza voci con una voce nuova da aggiungere dal modale del ricalcolo («Sincronizza»): rifiutato prima di aggiungerla, e la strada prende anche la voce nuova', function () {
    $this->travelTo(now()->setDate(2027, 1, 20)->setTime(10, 0));
    $s = g112pScenario($this);
    expect(g112pErrore(g112pCrea($this, $s, '2026', 'P26', ['spesa' => '1.200,00', 'pulizie' => '200,00'])))->toBeNull();
    expect(g112pErrore(g112pCrea($this, $s, '2027', 'P27', null, ['genera_subito' => 0])))->toBeNull();
    $p27 = g112pPiano($s, 'P27');
    g112pRigaVuota($p27);
    // «Varie», nuova e in nessun piano: è la voce che il modale del ricalcolo offre da aggiungere, e la sincronizzazione la scriverebbe nella riga.
    $s['varie'] = be52Voce($this, $s, $s['pc'], 'Varie', 'spesa', '300,00');
    $prima = g112pFoto($p27);

    g112pRifiutoRigaVuota(g112pErrore(g112pRicalcola($this, $s, '2027', $p27, ['orphan_ids' => [$s['varie']->id]])), 'Impossibile ricalcolare', 'P27',
        'ha un piano in un altro esercizio: «P26» («Esercizio 2026»)', 'Esercizio 2027');
    // Niente è cambiato: la riga ancora vuota, «Varie» non è entrata.
    expect(g112pFoto($p27))->toBe($prima)->and($prima['voci'])->toBe([]);

    // La strada. A mano (183.2, l'esempio della decisione): nel 2027 le tre voci sono libere, 1.200 + 200 + 300 = 1.700, € 850,00 a testa.
    expect(g112pErrore(g112pElimina($this, $s, '2027', $p27)))->toBeNull();
    expect(g112pErrore(g112pCrea($this, $s, '2027', 'P27', null)))->toBeNull()
        ->and(g112pChiesto($s, 'P27'))->toBe(['ugo' => 85000, 'bice' => 85000]);
});

it('Coda 438 — due piani del 2027 nati senza voci: rifiutati tutti e due, e la strada ne crea uno con le due voci', function () {
    $this->travelTo(now()->setDate(2027, 1, 20)->setTime(10, 0));
    $s = g112pScenario($this);
    expect(g112pErrore(g112pCrea($this, $s, '2026', 'P26', ['spesa' => '1.200,00', 'pulizie' => '200,00'])))->toBeNull();
    expect(g112pErrore(g112pCrea($this, $s, '2027', 'P27a', null, ['genera_subito' => 0])))->toBeNull();
    expect(g112pErrore(g112pCrea($this, $s, '2027', 'P27b', null, ['genera_subito' => 0])))->toBeNull();
    [$a, $b] = [g112pPiano($s, 'P27a'), g112pPiano($s, 'P27b')];
    g112pRigaVuota($a);
    g112pRigaVuota($b);
    [$primaA, $primaB] = [g112pFoto($a), g112pFoto($b)];

    foreach (['P27a' => $a, 'P27b' => $b] as $nome => $piano) {
        g112pRifiutoRigaVuota(g112pErrore(g112pRicalcola($this, $s, '2027', $piano)), 'Impossibile ricalcolare', $nome, 'ha un piano in un altro esercizio: «P26» («Esercizio 2026»)', 'Esercizio 2027');
    }
    expect(g112pFoto($a))->toBe($primaA)->and(g112pFoto($b))->toBe($primaB);

    // A mano (183.2): un piano solo del 2027 con le due voci, 700 a testa.
    expect(g112pErrore(g112pElimina($this, $s, '2027', $a)))->toBeNull()
        ->and(g112pErrore(g112pElimina($this, $s, '2027', $b)))->toBeNull();
    expect(g112pErrore(g112pCrea($this, $s, '2027', 'P27', null)))->toBeNull()
        ->and(g112pChiesto($s, 'P27'))->toBe(['ugo' => 70000, 'bice' => 70000]);
});

it('Coda 438 — il piano del 2026 nato senza voci, con un piano del 2027 sulle sole «Pulizie»: rifiutato dal 2026; la strada dà Ugo € 400,00, e l\'integrativo del 2027 dopo si ferma alla guardia', function () {
    $this->travelTo(now()->setDate(2027, 1, 20)->setTime(10, 0));
    $s = g112pScenario($this);
    expect(g112pErrore(g112pCrea($this, $s, '2026', 'P26', null, ['genera_subito' => 0])))->toBeNull();
    $p26 = g112pPiano($s, 'P26');
    g112pRigaVuota($p26);
    expect(g112pErrore(g112pCrea($this, $s, '2027', 'P27', ['pulizie' => '200,00'])))->toBeNull();
    // A mano: 200 / 2 = 100 a testa.
    expect(g112pChiesto($s, 'P27'))->toBe(['ugo' => 10000, 'bice' => 10000]);
    g112pGiaVersato($this, $s, 'spesa', 'tutti', 30000)->assertSessionHasNoErrors();
    $prima = g112pFoto($p26);

    g112pRifiutoRigaVuota(g112pErrore(g112pRicalcola($this, $s, '2026', $p26)), 'Impossibile ricalcolare', 'P26', 'ha un piano in un altro esercizio: «P27» («Esercizio 2027»)', 'Esercizio 2026');
    expect(g112pFoto($p26))->toBe($prima)->and(g112pChiesto($s, 'P27'))->toBe(['ugo' => 10000, 'bice' => 10000]);

    // La strada: P26 di nuovo dalla pagina del 2026 senza scegliere le voci. A mano (183.2 e 183.1): nel 2026 le due voci sono libere, e il già versato
    // «per tutti» vale lì per intero: Ugo 600 − 300 + 100 = 400, Bice 700. La riga ha le voci con il loro importo.
    expect(g112pErrore(g112pElimina($this, $s, '2026', $p26)))->toBeNull();
    expect(g112pErrore(g112pCrea($this, $s, '2026', 'P26', null)))->toBeNull()
        ->and(g112pChiesto($s, 'P26'))->toBe(['ugo' => 40000, 'bice' => 70000]);
    // Un integrativo del 2027 senza voci scelte prende «Spese generali» (non è in un piano del 2027): dalla regola (183.1 e 187) P26 e I27 chiederebbero
    // € 1.200,00 ciascuno sulla voce da € 1.200,00, e i € 300,00 di Ugo «per tutti» non si tolgono in tutti e due: la guardia lo ferma.
    $r = g112pCrea($this, $s, '2027', 'I27', null);
    expect(g112pErrore($r))->toContain('ha un già versato registrato che vale per tutti gli esercizi, ed è già nel piano «P26»')
        ->and(g112pPiano($s, 'I27'))->toBeNull();
});

// --- (B) con i capitoli ----------------------------------------------------------------------------------------------------------------

it('Coda 438 — voci con sottoconti: il piano del 2027 nato senza voci, con le foglie nel piano del 2026, è rifiutato; la strada prende le foglie e un integrativo dopo non le chiede di nuovo', function () {
    $this->travelTo(now()->setDate(2027, 1, 20)->setTime(10, 0));
    $s = g112pScenario($this);
    $man = g112pSoloManutenzione($this, $s);
    // P26 creato senza scegliere le voci: la riga ha le foglie del capitolo (la «fotografia»). A mano: (600 + 400) / 2 = 500 a testa.
    expect(g112pErrore(g112pCrea($this, $s, '2026', 'P26', null)))->toBeNull()
        ->and(g112pChiesto($s, 'P26'))->toBe(['ugo' => 50000, 'bice' => 50000]);
    expect(g112pErrore(g112pCrea($this, $s, '2027', 'P27', null, ['genera_subito' => 0])))->toBeNull();
    $p27 = g112pPiano($s, 'P27');
    g112pRigaVuota($p27);
    $prima = g112pFoto($p27);

    g112pRifiutoRigaVuota(g112pErrore(g112pRicalcola($this, $s, '2027', $p27)), 'Impossibile ricalcolare', 'P27', 'ha un piano in un altro esercizio: «P26» («Esercizio 2026»)', 'Esercizio 2027');
    expect(g112pFoto($p27))->toBe($prima);

    // La strada. A mano (183.2): nel 2027 le due foglie sono libere, 500 a testa; la pagina del piano dice € 1.000,00 di spese e il badge resta spento.
    expect(g112pErrore(g112pElimina($this, $s, '2027', $p27)))->toBeNull();
    expect(g112pErrore(g112pCrea($this, $s, '2027', 'P27', null)))->toBeNull()
        ->and(g112pChiesto($s, 'P27'))->toBe(['ugo' => 50000, 'bice' => 50000]);
    $quote = app(\App\Services\PianoRateQuoteService::class);
    expect($quote->totaliPerSegnoCents(g112pPiano($s, 'P27'))['spese'])->toBe(100000)
        ->and($quote->statoAllineamento(g112pPiano($s, 'P27')))->toBe(\App\Services\PianoRateQuoteService::ALLINEATO);
    // Un integrativo del 2027 senza voci scelte: le foglie sono nel P27 (183.2), niente da chiedere.
    $r = g112pCrea($this, $s, '2027', 'I27', null);
    expect(g112pErrore($r))->not->toBeNull()->and(g112pPiano($s, 'I27'))->toBeNull();
});

it('Coda 438 — voci con sottoconti: il piano del 2027 nato senza voci con il già versato «per tutti gli esercizi» su una foglia è rifiutato; la strada si ferma alla guardia e, indicato il 2026, dà € 500,00 a testa', function () {
    $this->travelTo(now()->setDate(2027, 1, 20)->setTime(10, 0));
    $s = g112pScenario($this);
    g112pSoloManutenzione($this, $s);
    g112pGiaVersato($this, $s, 'ascensore', 'tutti', 30000)->assertSessionHasNoErrors();
    expect(g112pErrore(g112pCrea($this, $s, '2026', 'P26', null)))->toBeNull();
    // A mano: Ascensore 600 (Ugo 300 − 300 = 0, Bice 300) e Caldaia 400 (200 e 200): Ugo 200, Bice 500.
    expect(g112pChiesto($s, 'P26'))->toBe(['ugo' => 20000, 'bice' => 50000]);
    expect(g112pErrore(g112pCrea($this, $s, '2027', 'P27', null, ['genera_subito' => 0])))->toBeNull();
    $p27 = g112pPiano($s, 'P27');
    g112pRigaVuota($p27);
    $prima = g112pFoto($p27);

    g112pRifiutoRigaVuota(g112pErrore(g112pRicalcola($this, $s, '2027', $p27)), 'Impossibile ricalcolare', 'P27', 'ha un piano in un altro esercizio: «P26» («Esercizio 2026»)', 'Esercizio 2027');
    expect(g112pFoto($p27))->toBe($prima)->and(g112pChiesto($s, 'P26'))->toBe(['ugo' => 20000, 'bice' => 50000]);

    // La strada: la creazione senza voci scelte prende di nuovo l'Ascensore intero; dalla regola (183.1 e 187) € 1.200,00 in due piani su una voce da € 600,00
    // con il già versato «per tutti»: la guardia ferma il piano.
    expect(g112pErrore(g112pElimina($this, $s, '2027', $p27)))->toBeNull();
    expect(g112pErrore(g112pCrea($this, $s, '2027', 'P27', null)))->toContain('ha un già versato registrato che vale per tutti gli esercizi')
        ->and(g112pPiano($s, 'P27'))->toBeNull();
    // Indicato il 2026: nel 2027 il già versato non vale. A mano: Ascensore 300 e Caldaia 200 a testa, 500 e 500; P26 com'era.
    g112pGiaVersato($this, $s, 'ascensore', '2026', 30000)->assertSessionHasNoErrors();
    expect(g112pErrore(g112pCrea($this, $s, '2027', 'P27', null)))->toBeNull()
        ->and(g112pChiesto($s, 'P27'))->toBe(['ugo' => 50000, 'bice' => 50000])
        ->and(g112pChiesto($s, 'P26'))->toBe(['ugo' => 20000, 'bice' => 50000]);
});

it('Coda 438 — voci con sottoconti: il piano del 2027 nato senza voci, con le foglie di un capitolo in un altro piano del 2027, è rifiutato; la strada prende la sola voce libera', function () {
    $this->travelTo(now()->setDate(2027, 1, 20)->setTime(10, 0));
    $s = g112pScenario($this);
    DB::table('conti')->where('id', $s['pulizie']->id)->update(['importo' => 0]);
    $man = g112pCapitolo($this, $s, 'Manutenzione', ['Ascensore' => '600,00', 'Caldaia' => '400,00']);
    // P26 senza voci scelte: «Spese generali», «Pulizie» a zero e le due foglie. A mano: 1.200 + 600 + 400 = 2.200, 1.100 a testa.
    expect(g112pErrore(g112pCrea($this, $s, '2026', 'P26', null)))->toBeNull()
        ->and(g112pChiesto($s, 'P26'))->toBe(['ugo' => 110000, 'bice' => 110000]);
    expect(g112pErrore(g112pCrea($this, $s, '2027', 'P27', null, ['genera_subito' => 0])))->toBeNull();
    $p27 = g112pPiano($s, 'P27');
    g112pRigaVuota($p27);
    // Un piano del 2027 per la sola manutenzione, con la selezione rapida del capitolo (la riga ha le due foglie). A mano: 500 a testa.
    expect(g112pErrore(g112pCrea($this, $s, '2027', 'M27', null, ['capitoli_ids' => [$man['padre']->id]])))->toBeNull()
        ->and(g112pChiesto($s, 'M27'))->toBe(['ugo' => 50000, 'bice' => 50000]);
    $prima = g112pFoto($p27);

    g112pRifiutoRigaVuota(g112pErrore(g112pRicalcola($this, $s, '2027', $p27)), 'Impossibile ricalcolare', 'P27', 'ha un piano in un altro esercizio: «P26» («Esercizio 2026»)', 'Esercizio 2027');
    expect(g112pFoto($p27))->toBe($prima)->and(g112pChiesto($s, 'M27'))->toBe(['ugo' => 50000, 'bice' => 50000]);

    // La strada. A mano (183.2): nel 2027 le foglie sono nel M27; il P27 prende «Spese generali» (e «Pulizie» a zero): 1.200 / 2 = 600 a testa.
    expect(g112pErrore(g112pElimina($this, $s, '2027', $p27)))->toBeNull();
    expect(g112pErrore(g112pCrea($this, $s, '2027', 'P27', null)))->toBeNull()
        ->and(g112pChiesto($s, 'P27'))->toBe(['ugo' => 60000, 'bice' => 60000])
        ->and(g112pChiesto($s, 'M27'))->toBe(['ugo' => 50000, 'bice' => 50000]);
});

it('Coda 438 — un capitolo nel piano del 2026 e una sua foglia in un piano del 2027: i due piani del 2027 nati senza voci sono rifiutati; la strada sceglie la «Caldaia»', function () {
    $this->travelTo(now()->setDate(2027, 1, 20)->setTime(10, 0));
    $s = g112pScenario($this);
    g112pSoloManutenzione($this, $s);
    $this->travel(1)->minutes();
    // P26 chiede il capitolo con il suo importo (la riga ha il capitolo); M27 la sola «Ascensore».
    expect(g112pErrore(g112pCrea($this, $s, '2026', 'P26', ['manutenzione' => '1.000,00'])))->toBeNull()
        ->and(g112pChiesto($s, 'P26'))->toBe(['ugo' => 50000, 'bice' => 50000]);
    expect(g112pErrore(g112pCrea($this, $s, '2027', 'P27a', null, ['genera_subito' => 0])))->toBeNull();
    expect(g112pErrore(g112pCrea($this, $s, '2027', 'P27b', null, ['genera_subito' => 0])))->toBeNull();
    [$a, $b] = [g112pPiano($s, 'P27a'), g112pPiano($s, 'P27b')];
    g112pRigaVuota($a);
    g112pRigaVuota($b);
    expect(g112pErrore(g112pCrea($this, $s, '2027', 'M27', ['ascensore' => '600,00'])))->toBeNull()
        ->and(g112pChiesto($s, 'M27'))->toBe(['ugo' => 30000, 'bice' => 30000]);
    [$primaA, $primaB] = [g112pFoto($a), g112pFoto($b)];

    foreach (['P27a' => $a, 'P27b' => $b] as $nome => $piano) {
        g112pRifiutoRigaVuota(g112pErrore(g112pRicalcola($this, $s, '2027', $piano)), 'Impossibile ricalcolare', $nome, 'ha un piano in un altro esercizio: «P26» («Esercizio 2026»)', 'Esercizio 2027');
    }
    expect(g112pFoto($a))->toBe($primaA)->and(g112pFoto($b))->toBe($primaB);

    // La strada: eliminati, un piano del 2027 scegliendo la «Caldaia». A mano: 400 / 2 = 200 a testa; nel 2027 l'Ascensore lo chiede il solo M27.
    expect(g112pErrore(g112pElimina($this, $s, '2027', $a)))->toBeNull()->and(g112pErrore(g112pElimina($this, $s, '2027', $b)))->toBeNull();
    expect(g112pErrore(g112pCrea($this, $s, '2027', 'C27', ['caldaia' => '400,00'])))->toBeNull()
        ->and(g112pChiesto($s, 'C27'))->toBe(['ugo' => 20000, 'bice' => 20000])
        ->and(g112pChiesto($s, 'M27'))->toBe(['ugo' => 30000, 'bice' => 30000]);
});

it('Coda 438 — un capitolo libero nel 2027: il piano nato senza voci è rifiutato, e la strada dà € 1.100,00 a testa con la pagina che dice € 2.200,00 e il badge spento', function () {
    $this->travelTo(now()->setDate(2027, 1, 20)->setTime(10, 0));
    $s = g112pScenario($this);
    DB::table('conti')->where('id', $s['pulizie']->id)->update(['importo' => 0]);
    g112pCapitolo($this, $s, 'Manutenzione', ['Ascensore' => '600,00', 'Caldaia' => '400,00']);
    expect(g112pErrore(g112pCrea($this, $s, '2026', 'P26', null)))->toBeNull();
    expect(g112pErrore(g112pCrea($this, $s, '2027', 'P27', null, ['genera_subito' => 0])))->toBeNull();
    $p27 = g112pPiano($s, 'P27');
    g112pRigaVuota($p27);
    $prima = g112pFoto($p27);

    g112pRifiutoRigaVuota(g112pErrore(g112pRicalcola($this, $s, '2027', $p27)), 'Impossibile ricalcolare', 'P27', 'ha un piano in un altro esercizio: «P26» («Esercizio 2026»)', 'Esercizio 2027');
    expect(g112pFoto($p27))->toBe($prima);

    // A mano (183.2): nel 2027 tutte le voci sono libere: 1.200 + 600 + 400 = 2.200, 1.100 a testa.
    expect(g112pErrore(g112pElimina($this, $s, '2027', $p27)))->toBeNull();
    expect(g112pErrore(g112pCrea($this, $s, '2027', 'P27', null)))->toBeNull()
        ->and(g112pChiesto($s, 'P27'))->toBe(['ugo' => 110000, 'bice' => 110000]);
    $quote = app(\App\Services\PianoRateQuoteService::class);
    expect($quote->totaliPerSegnoCents(g112pPiano($s, 'P27'))['spese'])->toBe(220000)
        ->and($quote->statoAllineamento(g112pPiano($s, 'P27')))->toBe(\App\Services\PianoRateQuoteService::ALLINEATO);
});

it('Coda 438 — «togli la voce» su un piano nato senza voci (dalla rotta: la pagina non mostra voci da togliere) è rifiutato come il ricalcolo', function () {
    $this->travelTo(now()->setDate(2027, 1, 20)->setTime(10, 0));
    $s = g112pScenario($this);
    expect(g112pErrore(g112pCrea($this, $s, '2026', 'P26', ['spesa' => '1.200,00', 'pulizie' => '200,00'])))->toBeNull();
    expect(g112pErrore(g112pCrea($this, $s, '2027', 'P27', null, ['genera_subito' => 0])))->toBeNull();
    $p27 = g112pPiano($s, 'P27');
    g112pRigaVuota($p27);
    $prima = g112pFoto($p27);

    g112pRifiutoRigaVuota(g112pErrore(g112pTogli($this, $s, '2027', $p27, $s['pulizie']->id)), 'Impossibile togliere la voce', 'P27',
        'ha un piano in un altro esercizio: «P26» («Esercizio 2026»)', 'Esercizio 2027');
    expect(g112pFoto($p27))->toBe($prima)->and(g112pChiesto($s, 'P27'))->toBe(['ugo' => 0, 'bice' => 0]);
});

it('Coda 438 — la gestione usata in tre esercizi: i messaggi nominano tutti gli esercizi e tutti i piani degli altri esercizi', function () {
    $this->travelTo(now()->setDate(2027, 1, 20)->setTime(10, 0));
    $s = g112pScenario($this);
    $s['es']['2028'] = Esercizio::create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2028', 'data_inizio' => '2028-01-01', 'data_fine' => '2028-12-31', 'stato' => 'aperto']);
    app(CondominioService::class)->createDefaultGestione($s['c'], $s['es']['2028']);
    expect(g112pErrore(g112pCrea($this, $s, '2026', 'P26', ['spesa' => '1.200,00', 'pulizie' => '200,00'])))->toBeNull();
    expect(g112pErrore(g112pCrea($this, $s, '2028', 'P28', ['spesa' => '1.200,00'])))->toBeNull();
    expect(g112pErrore(g112pCrea($this, $s, '2027', 'P27', null, ['genera_subito' => 0])))->toBeNull();
    $p27 = g112pPiano($s, 'P27');
    g112pRigaVuota($p27);
    $prima27 = g112pFoto($p27);

    $msg = g112pErrore(g112pRicalcola($this, $s, '2027', $p27));
    g112pRifiutoRigaVuota($msg, 'Impossibile ricalcolare', 'P27', 'ha piani in altri esercizi: «P26» («Esercizio 2026») e «P28» («Esercizio 2028»)', 'Esercizio 2027');
    expect($msg)->toContain('potrebbe chiedere di nuovo quelle dei piani degli altri esercizi o togliere due volte il già versato.')
        ->and(g112pFoto($p27))->toBe($prima27);

    $p26 = g112pPiano($s, 'P26');
    DB::table('piani_rate')->where('id', $p26->id)->update(['esercizio_id' => null]);
    $prima26 = g112pFoto($p26);
    expect(g112pErrore(g112pRicalcola($this, $s, '2027', $p26)))
        ->toStartWith('Impossibile ricalcolare: il piano rate «P26» non ha il suo esercizio salvato, e la gestione «Gestione ordinaria» è usata in più esercizi '
            . '(«Esercizio 2026», «Esercizio 2027» e «Esercizio 2028»).')
        ->and(g112pFoto($p26))->toBe($prima26);
    // A mano (183.2): P26 e P28 fermi; P26 600 + 100 = 700 a testa, P28 600 a testa.
    expect(g112pChiesto($s, 'P26'))->toBe(['ugo' => 70000, 'bice' => 70000])->and(g112pChiesto($s, 'P28'))->toBe(['ugo' => 60000, 'bice' => 60000]);
});

// --- Le controprove: i piani che si ricalcolano come prima ------------------------------------------------------------------------------

it('Coda 438, controprova — il piano del 2026 con l\'esercizio salvato, aperto dall\'elenco del 2027: si ricalcola e resta del 2026 (Ugo € 400,00), anche togliendo una voce', function () {
    $this->travelTo(now()->setDate(2026, 3, 1)->setTime(10, 0));
    $s = g112pScenario($this);
    g112pGiaVersato($this, $s, 'spesa', '2026', 30000)->assertSessionHasNoErrors();
    expect(g112pErrore(g112pCrea($this, $s, '2026', 'P26', ['spesa' => '1.200,00', 'pulizie' => '200,00'])))->toBeNull();
    $p26 = g112pPiano($s, 'P26');
    $this->travelTo(now()->setDate(2027, 4, 15)->setTime(10, 0));

    // A mano (183.1): 400 e 700; tolta «Pulizie», 300 e 600.
    expect(g112pErrore(g112pRicalcola($this, $s, '2027', $p26)))->toBeNull()
        ->and(PianoRate::find($p26->id)->esercizio_id)->toBe($s['es']['2026']->id)
        ->and(g112pChiesto($s, 'P26'))->toBe(['ugo' => 40000, 'bice' => 70000]);
    expect(g112pErrore(g112pTogli($this, $s, '2027', $p26, $s['pulizie']->id)))->toBeNull()
        ->and(g112pChiesto($s, 'P26'))->toBe(['ugo' => 30000, 'bice' => 60000]);
});

it('Coda 438, controprova — un piano senza esercizio su una gestione con un esercizio solo si ricalcola, e prende quell\'esercizio', function () {
    $this->travelTo(now()->setDate(2026, 3, 1)->setTime(10, 0));
    $s = g112pScenario($this, conIl2027: false);
    g112pGiaVersato($this, $s, 'spesa', '2026', 30000)->assertSessionHasNoErrors();
    expect(g112pErrore(g112pCrea($this, $s, '2026', 'P26', ['spesa' => '1.200,00', 'pulizie' => '200,00'])))->toBeNull();
    $p26 = g112pPiano($s, 'P26');
    g112pDella110($p26);

    // A mano (183.1): 400 e 700; tolta «Pulizie», 300 e 600.
    expect(g112pErrore(g112pRicalcola($this, $s, '2026', $p26)))->toBeNull()
        ->and(PianoRate::find($p26->id)->esercizio_id)->toBe($s['es']['2026']->id)
        ->and(g112pChiesto($s, 'P26'))->toBe(['ugo' => 40000, 'bice' => 70000]);
    DB::table('piani_rate')->where('id', $p26->id)->update(['esercizio_id' => null]);
    expect(g112pErrore(g112pTogli($this, $s, '2026', $p26, $s['pulizie']->id)))->toBeNull()
        ->and(g112pChiesto($s, 'P26'))->toBe(['ugo' => 30000, 'bice' => 60000]);
});

it('Coda 438, controprova — un piano con la riga vuota sulla gestione usata in due esercizi, senza piani nell\'altro esercizio, si ricalcola e prende le voci libere', function () {
    $this->travelTo(now()->setDate(2027, 1, 20)->setTime(10, 0));
    $s = g112pScenario($this);
    expect(g112pErrore(g112pCrea($this, $s, '2027', 'P27', null, ['genera_subito' => 0])))->toBeNull();
    $p27 = g112pPiano($s, 'P27');
    g112pRigaVuota($p27);

    // A mano (183.2): nessun altro piano, le due voci libere: 700 a testa.
    expect(g112pErrore(g112pRicalcola($this, $s, '2027', $p27)))->toBeNull()
        ->and(g112pChiesto($s, 'P27'))->toBe(['ugo' => 70000, 'bice' => 70000]);
});

it('Coda 438, controprova — un piano con la riga vuota e le voci in un altro piano dello stesso esercizio: il messaggio di oggi, non il rifiuto nuovo', function () {
    $this->travelTo(now()->setDate(2027, 1, 20)->setTime(10, 0));
    $s = g112pScenario($this, conIl2027: false);
    expect(g112pErrore(g112pCrea($this, $s, '2026', 'P26', ['spesa' => '1.200,00', 'pulizie' => '200,00'])))->toBeNull();
    expect(g112pErrore(g112pCrea($this, $s, '2026', 'V26', null, ['genera_subito' => 0])))->toBeNull();
    $v26 = g112pPiano($s, 'V26');
    g112pRigaVuota($v26);

    $msg = g112pErrore(g112pRicalcola($this, $s, '2026', $v26));
    expect($msg)->toContain('tutte le voci di questa gestione sono già nel piano «P26»')
        ->and($msg)->not->toContain('non ha voci associate')
        ->and(g112pChiesto($s, 'V26'))->toBe(['ugo' => 0, 'bice' => 0]);
});

it('Coda 438, controprova — un piano creato dalla pagina del suo esercizio, con «genera subito», non incontra il rifiuto: si ricalcola da tutte e due le pagine e perde una voce', function () {
    $this->travelTo(now()->setDate(2027, 1, 20)->setTime(10, 0));
    $s = g112pScenario($this);
    g112pGiaVersato($this, $s, 'spesa', '2026', 30000)->assertSessionHasNoErrors();
    expect(g112pErrore(g112pCrea($this, $s, '2026', 'P26', ['spesa' => '1.200,00', 'pulizie' => '200,00'])))->toBeNull();
    // Il piano del 2027 senza scegliere le voci: dalla 183.2 prende le due voci, e la riga le ha.
    expect(g112pErrore(g112pCrea($this, $s, '2027', 'P27', null)))->toBeNull();
    $p27 = g112pPiano($s, 'P27');
    expect(DB::table('piano_rate_capitoli')->where('piano_rate_id', $p27->id)->count())->toBe(2);

    // A mano: 700 a testa da tutte e due le pagine (il piano resta del 2027); tolta «Pulizie», 600 a testa.
    expect(g112pErrore(g112pRicalcola($this, $s, '2027', $p27)))->toBeNull()->and(g112pChiesto($s, 'P27'))->toBe(['ugo' => 70000, 'bice' => 70000]);
    expect(g112pErrore(g112pRicalcola($this, $s, '2026', $p27)))->toBeNull()->and(g112pChiesto($s, 'P27'))->toBe(['ugo' => 70000, 'bice' => 70000])
        ->and(PianoRate::find($p27->id)->esercizio_id)->toBe($s['es']['2027']->id);
    // Con le voci tutte nei piani del suo esercizio, la creazione con «genera subito» si ferma con il messaggio di oggi, e il piano non nasce.
    $r = g112pCrea($this, $s, '2027', 'I27', null);
    expect(g112pErrore($r))->toContain('tutte le voci di questa gestione sono già nel piano «P27»')
        ->and(g112pErrore($r))->not->toContain('non ha voci associate')
        ->and(g112pPiano($s, 'I27'))->toBeNull();
    expect(g112pErrore(g112pTogli($this, $s, '2027', $p27, $s['pulizie']->id)))->toBeNull()->and(g112pChiesto($s, 'P27'))->toBe(['ugo' => 60000, 'bice' => 60000]);
});

it('Coda 438 — un piano creato dalla pagina senza «genera subito» quando tutte le voci sono nel piano del suo esercizio nasce con la riga vuota: «Ricalcola» dice il rifiuto della riga vuota, non quello di prima', function () {
    $this->travelTo(now()->setDate(2027, 1, 20)->setTime(10, 0));
    $s = g112pScenario($this);
    expect(g112pErrore(g112pCrea($this, $s, '2026', 'P26', ['spesa' => '1.200,00', 'pulizie' => '200,00'])))->toBeNull();
    expect(g112pErrore(g112pCrea($this, $s, '2027', 'P27', null)))->toBeNull();
    // Nessuna voce libera nel 2027 (sono nel P27): l'integrativo creato senza generarlo nasce senza voci, con l'esercizio della pagina.
    expect(g112pErrore(g112pCrea($this, $s, '2027', 'I27', null, ['genera_subito' => 0])))->toBeNull();
    $i27 = g112pPiano($s, 'I27');
    expect(DB::table('piano_rate_capitoli')->where('piano_rate_id', $i27->id)->exists())->toBeFalse()
        ->and($i27->esercizio_id)->toBe($s['es']['2027']->id);
    $prima = g112pFoto($i27);

    // Prima diceva che le voci sono tutte nel piano «P27» (vero anche oggi); ora la riga vuota con il piano del 2026 parla per prima, con la strada.
    g112pRifiutoRigaVuota(g112pErrore(g112pRicalcola($this, $s, '2027', $i27)), 'Impossibile ricalcolare', 'I27', 'ha un piano in un altro esercizio: «P26» («Esercizio 2026»)', 'Esercizio 2027');
    // A mano: niente cambiato; P27 700 a testa, I27 niente.
    expect(g112pFoto($i27))->toBe($prima)
        ->and(g112pChiesto($s, 'P27'))->toBe(['ugo' => 70000, 'bice' => 70000])
        ->and(g112pChiesto($s, 'I27'))->toBe(['ugo' => 0, 'bice' => 0]);
});

it('Coda 438, controprova — un piano straordinario (la riga delle voci vuota per costruzione) con l\'esercizio salvato, sulla gestione straordinaria usata in due esercizi, si ricalcola', function () {
    $this->travelTo(now()->setDate(2027, 1, 20)->setTime(10, 0));
    $s = g112pScenario($this);
    g112pStraordinaria($this, $s);
    expect(g112pErrore(g112pCreaStraordinario($this, $s, '2026', 'S26')))->toBeNull();
    $s26 = g112pPiano($s, 'S26');
    // Un altro piano della stessa gestione, del 2027 (senza fatture: basta che ci sia).
    PianoRate::create(['gestione_id' => $s['gs']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['es']['2027']->id, 'nome' => 'S27', 'stato' => 'bozza',
        'tipo' => 'straordinario', 'numero_rate' => 2, 'giorno_scadenza' => 10, 'data_prima_scadenza' => '2027-03-10', 'metodo_distribuzione' => 'tutte_rate',
        'tipo_autorizzazione' => 'delibera', 'motivazione_autorizzazione' => 'Delibera di prova', 'data_delibera_assemblea' => '2027-02-01']);
    expect(DB::table('piano_rate_capitoli')->where('piano_rate_id', $s26->id)->exists())->toBeFalse()
        ->and($s26->esercizio_id)->toBe($s['es']['2026']->id);

    // A mano: la fattura di € 1.000,00 sulla «Facciata», ripartita su «Proprietà» a metà: 500 a testa, prima e dopo il ricalcolo.
    expect(g112pChiesto($s, 'S26'))->toBe(['ugo' => 50000, 'bice' => 50000]);
    expect(g112pErrore(g112pRicalcola($this, $s, '2026', $s26)))->toBeNull()
        ->and(g112pChiesto($s, 'S26'))->toBe(['ugo' => 50000, 'bice' => 50000]);
});

it('Coda 438 — un piano straordinario senza esercizio sulla gestione straordinaria usata in due esercizi: rifiutato, il messaggio dice di riportarlo in bozza e di scegliere le fatture, e la strada si percorre', function () {
    $this->travelTo(now()->setDate(2026, 3, 15)->setTime(10, 0));
    $s = g112pScenario($this);
    g112pStraordinaria($this, $s);
    expect(g112pErrore(g112pCreaStraordinario($this, $s, '2026', 'S26')))->toBeNull();
    $s26 = g112pPiano($s, 'S26');
    // A mano: € 1.000,00 a metà su «Proprietà», 500 a testa. Il piano straordinario nasce approvato.
    expect(g112pChiesto($s, 'S26'))->toBe(['ugo' => 50000, 'bice' => 50000])->and($s26->stato->value)->toBe('approvato');
    g112pDella110($s26);
    $prima = g112pFoto($s26);

    $this->travelTo(now()->setDate(2027, 4, 15)->setTime(10, 0));
    g112pRifiutoSenzaEsercizio(g112pErrore(g112pRicalcola($this, $s, '2027', $s26)), 'Impossibile ricalcolare', 'S26', approvato: true, gestione: 'Gestione straordinaria', cosa: 'le fatture');
    expect(g112pFoto($s26))->toBe($prima);

    // La strada: in bozza, eliminato, creato di nuovo dalla pagina del 2026 con la fattura. A mano: 500 a testa.
    $this->actingAs($this->user)->from('/piano')->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['es']['2027'], $s26]), ['approvato' => false])
        ->assertSessionHasNoErrors();
    expect(g112pErrore(g112pElimina($this, $s, '2027', $s26)))->toBeNull();
    expect(g112pErrore(g112pCreaStraordinario($this, $s, '2026', 'S26')))->toBeNull()
        ->and(g112pPiano($s, 'S26')->esercizio_id)->toBe($s['es']['2026']->id)
        ->and(g112pChiesto($s, 'S26'))->toBe(['ugo' => 50000, 'bice' => 50000]);
});

it('Coda 438 — il piano del 2026 che la 1.11 ha spostato nel 2027 ha l\'esercizio salvato: si ricalcola come prima e resta nel 2027 (decisione 191: la pagina per correggerlo è della Coda 461)', function () {
    $this->travelTo(now()->setDate(2026, 3, 1)->setTime(10, 0));
    $s = g112pScenario($this);
    expect(g112pErrore(g112pCrea($this, $s, '2026', 'P26', ['spesa' => '1.200,00', 'pulizie' => '200,00'])))->toBeNull();
    $p26 = g112pPiano($s, 'P26');
    // La porta della 1.11: «Ricalcola» dall'elenco del 2027 scriveva nel piano l'esercizio della pagina.
    DB::table('piani_rate')->where('id', $p26->id)->update(['esercizio_id' => $s['es']['2027']->id]);
    $this->travelTo(now()->setDate(2027, 4, 15)->setTime(10, 0));
    g112pGiaVersato($this, $s, 'spesa', '2026', 30000)->assertSessionHasNoErrors();
    $r = g112pRicalcola($this, $s, '2026', $p26);
    // Fissa l'uscita del programma: il piano resta nel 2027 e non toglie i € 300,00 del 2026 (600 + 100 = 700); dalla regola il P26 è del 2026 (400 e 700).
    expect(g112pErrore($r))->toBeNull()
        ->and(PianoRate::find($p26->id)->esercizio_id)->toBe($s['es']['2027']->id)
        ->and(g112pChiesto($s, 'P26'))->toBe(['ugo' => 70000, 'bice' => 70000]);
});

// Coda 438 (decisione 183.2): un piano senza voci, al ricalcolo, prende tutte le voci che nessun piano del suo esercizio ha già. Il cestino non toglie
// l'ultima voce di un piano: il ricalcolo dopo la rimozione chiederebbe anche la voce appena tolta, e sulla gestione usata in più esercizi le voci
// del piano dell'altro esercizio, senza impegnarle. Cifre dalla regola: «Pulizie» € 200,00 e «Spese generali» € 1.200,00 a 500 millesimi a testa.

it('Coda 438 — il cestino non toglie l\'ultima voce di un piano del 2027 nato dopo la beta: il piano resta com\'è (Ugo e Bice € 100,00) e la frase dice di eliminarlo se non serve più', function () {
    $this->travelTo(now()->setDate(2027, 1, 20)->setTime(10, 0));
    $s = g112pScenario($this);
    expect(g112pErrore(g112pCrea($this, $s, '2026', 'P26', ['spesa' => '1.200,00', 'pulizie' => '200,00'])))->toBeNull();
    expect(g112pErrore(g112pCrea($this, $s, '2027', 'P27', ['pulizie' => '200,00'])))->toBeNull();
    $p27 = g112pPiano($s, 'P27');
    $prima = g112pFoto($p27);

    $msg = g112pErrore(g112pTogli($this, $s, '2027', $p27, $s['pulizie']->id));

    expect($msg)->toBe('Impossibile togliere la voce: «Pulizie» è l\'ultima voce del piano rate «P27». Un piano senza voci, al ricalcolo, prende tutte '
            . 'le voci della gestione che nessun altro piano del suo esercizio ha già. Se deve chiedere un\'altra voce, aggiungila prima con «Sincronizza», '
            . 'se è libera, e poi togli questa; se il piano non serve più, eliminalo dall\'elenco dei piani rate.')
        ->and(g112pFoto(PianoRate::find($p27->id)))->toBe($prima)
        ->and(g112pChiesto($s, 'P27'))->toBe(['ugo' => 10000, 'bice' => 10000]);

    // La strada della frase: l'eliminazione dall'elenco riesce.
    expect(g112pErrore(g112pElimina($this, $s, '2027', PianoRate::find($p27->id))))->toBeNull()
        ->and(g112pPiano($s, 'P27'))->toBeNull();
});

it('Coda 438 — anche su una gestione con un esercizio solo il cestino non toglie l\'ultima voce (P26 con le sole «Pulizie», Q26 con «Spese generali»)', function () {
    $this->travelTo(now()->setDate(2026, 3, 1)->setTime(10, 0));
    $s = g112pScenario($this, conIl2027: false);
    expect(g112pErrore(g112pCrea($this, $s, '2026', 'Q26', ['spesa' => '1.200,00'])))->toBeNull();
    expect(g112pErrore(g112pCrea($this, $s, '2026', 'P26', ['pulizie' => '200,00'])))->toBeNull();
    $p26 = g112pPiano($s, 'P26');
    $prima = g112pFoto($p26);

    expect(g112pErrore(g112pTogli($this, $s, '2026', $p26, $s['pulizie']->id)))->toStartWith('Impossibile togliere la voce: «Pulizie» è l\'ultima voce del piano rate «P26».')
        ->and(g112pFoto(PianoRate::find($p26->id)))->toBe($prima)
        ->and(g112pChiesto($s, 'P26'))->toBe(['ugo' => 10000, 'bice' => 10000]);
});

it('Coda 438 — il cestino su un piano approvato con una voce sola: la frase dice anche di riportarlo in bozza', function () {
    $this->travelTo(now()->setDate(2027, 1, 20)->setTime(10, 0));
    $s = g112pScenario($this);
    expect(g112pErrore(g112pCrea($this, $s, '2027', 'P27', ['pulizie' => '200,00'])))->toBeNull();
    $p27 = g112pPiano($s, 'P27');
    DB::table('piani_rate')->where('id', $p27->id)->update(['stato' => \App\Enums\StatoPianoRate::APPROVATO->value]);

    expect(g112pErrore(g112pTogli($this, $s, '2027', PianoRate::find($p27->id), $s['pulizie']->id)))
        ->toEndWith('se il piano non serve più, riportalo in bozza dalla sua pagina ed eliminalo dall\'elenco dei piani rate.');
});

it('Coda 438 — controprova: con due voci il cestino toglie la voce e ricalcola (P27 con «Spese generali» e «Pulizie»: tolte le «Pulizie», Ugo e Bice € 600,00)', function () {
    $this->travelTo(now()->setDate(2027, 1, 20)->setTime(10, 0));
    $s = g112pScenario($this);
    expect(g112pErrore(g112pCrea($this, $s, '2027', 'P27', ['spesa' => '1.200,00', 'pulizie' => '200,00'])))->toBeNull();
    $p27 = g112pPiano($s, 'P27');

    expect(g112pErrore(g112pTogli($this, $s, '2027', $p27, $s['pulizie']->id)))->toBeNull()
        ->and(DB::table('piano_rate_capitoli')->where('piano_rate_id', $p27->id)->pluck('conto_id')->all())->toBe([$s['spesa']->id])
        ->and(g112pChiesto($s, 'P27'))->toBe(['ugo' => 60000, 'bice' => 60000]);
});
