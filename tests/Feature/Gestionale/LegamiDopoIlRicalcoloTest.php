<?php

/**
 * 1.11.0-beta.49, DC4 con la strada (b) — voce P7 del verbale (`docs/piano_esecutivo_beta49.md`), decisioni 75.3 e 76.2.
 *
 * Il ricalcolo e «togli una voce» cancellano le rate e le rigenerano; l'annullamento di una vendita che aveva diviso le bozze
 * (decisione 25) cancella la gemella del pregresso. Oggi la pivot `quota_scrittura` va via a cascata e le righe del giornale
 * perdono la rata (`righe_scritture.rata_id`, nullOnDelete). Un incasso, una compensazione o un rimborso stornati (pivot a
 * somma zero, il ricalcolo è ammesso) devono restare legati alla quota equivalente — stessa rata per numero, stessa persona,
 * stessa unità — e le righe del giornale riprendere la loro rata (76.2: si riscrive solo `rata_id`, mai un importo, un conto,
 * una data o una persona). Nessun importo cambia; `importo_pagato` e lo stato della quota restano quelli del generatore.
 *
 * Scenario comune (`lgmScenario`): Ugo, € 1.200,00 in 12 rate dal 5 gennaio su due voci da € 600,00 nominate dal piano
 * («Spese generali» e «Pulizie»); il saldo e il metodo li sceglie il test. Con `$conBox` Ugo ha anche un box, creato prima
 * dell'appartamento: la tabella vale 750 per l'appartamento e 250 per il box, quindi 7500 e 2500 a rata.
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Models\Tabella;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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

function lgmScenario(string $metodo = 'prima_rata', int $saldoUgo = 0, bool $conBox = false): array
{
    static $seq = 0;
    $seq++;
    $c = Condominio::factory()->create();
    $e = Esercizio::factory()->create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = Gestione::factory()->create(['condominio_id' => $c->id, 'nome' => 'Ordinaria 2026', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($e, $g->id);
    $pc = PianoConto::create(['condominio_id' => $c->id, 'gestione_id' => $g->id, 'nome' => 'PC']);
    $tabella = Tabella::create(['condominio_id' => $c->id, 'nome' => 'Proprietà', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    $voci = [];
    foreach (['Spese generali', 'Pulizie'] as $nome) {
        $voci[$nome] = Conto::create(['piano_conto_id' => $pc->id, 'nome' => $nome, 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 60000]);
        $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $voci[$nome]->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    }
    $box = $conBox ? Immobile::create(['condominio_id' => $c->id, 'tipo' => 'box', 'codice_immobile' => "LGM-B{$seq}", 'nome' => 'Box 1', 'interno' => 'B1']) : null;
    $unita = Immobile::create(['condominio_id' => $c->id, 'tipo' => 'appartamento', 'codice_immobile' => "LGM-{$seq}", 'nome' => 'Interno 1', 'interno' => '1']);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $unita->id, 'valore' => $conBox ? 750.0 : 1000.0, 'created_at' => now(), 'updated_at' => now()]);
    if ($box) {
        DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $box->id, 'valore' => 250.0, 'created_at' => now(), 'updated_at' => now()]);
    }
    $banca = ContoContabile::create(['condominio_id' => $c->id, 'codice' => '10.10', 'nome' => 'Banca', 'tipo' => 'attivo', 'ruolo' => 'banca', 'categoria' => 'liquidita']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '10.20', 'nome' => 'Crediti verso condomini', 'tipo' => 'attivo', 'ruolo' => 'crediti_condomini', 'categoria' => 'crediti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '20.10', 'nome' => 'Anticipi', 'tipo' => 'passivo', 'ruolo' => 'anticipi_condomini', 'categoria' => 'debiti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '20.20', 'nome' => 'Gestione rate', 'tipo' => 'passivo', 'ruolo' => 'gestione_rate', 'categoria' => 'debiti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '30.10', 'nome' => 'Passate gestioni', 'tipo' => 'passivo', 'ruolo' => 'passate_gestioni', 'categoria' => 'debiti']);
    $cassa = Cassa::create(['condominio_id' => $c->id, 'conto_contabile_id' => $banca->id, 'nome' => 'Banca principale', 'tipo' => 'banca', 'attiva' => true, 'saldo_iniziale' => 10000]);
    $v = Anagrafica::forceCreate(['nome' => 'Ugo Venditore', 'email' => "lgm-v{$seq}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'LGMVENDITOR' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT)]);
    $a = Anagrafica::forceCreate(['nome' => 'Elsa Acquirente', 'email' => "lgm-a{$seq}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'LGMACQUIREN' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT)]);
    $v->condomini()->syncWithoutDetaching([$c->id]);
    $a->condomini()->syncWithoutDetaching([$c->id]);
    if ($box) {
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $v->id, 'immobile_id' => $box->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
    $rigaV = DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $v->id, 'immobile_id' => $unita->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    if ($saldoUgo !== 0) {
        Saldo::create(['esercizio_id' => $e->id, 'condominio_id' => $c->id, 'anagrafica_id' => $v->id, 'immobile_id' => $unita->id, 'gestione_id' => $g->id, 'saldo_iniziale' => $saldoUgo, 'origine' => 'manuale', 'is_applicato' => false]);
    }
    $piano = PianoRate::create([
        'gestione_id' => $g->id, 'condominio_id' => $c->id, 'esercizio_id' => $e->id, 'nome' => 'Preventivo 2026', 'stato' => 'approvato', 'tipo' => 'ordinario',
        'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'metodo_distribuzione' => $metodo, 'applica_saldi' => true,
    ]);
    foreach ($voci as $conto) {
        $piano->capitoli()->attach($conto->id, ['importo' => 60000, 'note' => 'Inclusione automatica orfani']);
    }
    app(GeneratePianoRateAction::class)->execute($piano, forzaApplicazioneSaldi: true, esercizio: $e);

    return compact('c', 'e', 'g', 'unita', 'box', 'v', 'a', 'rigaV', 'piano', 'cassa', 'voci');
}

function lgmRata(array $s, int $numero): int
{
    return (int) DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('numero_rata', $numero)->value('id');
}

/** La quota di `$chi` (Ugo se nullo) nella rata, sull'appartamento salvo `$unita`; `$segno` sceglie fra la quota e la sua gemella. */
function lgmQuota(array $s, int $numero, ?int $chi = null, ?string $segno = null, ?int $unita = null): int
{
    return (int) DB::table('rate_quote')->where('rata_id', lgmRata($s, $numero))->where('anagrafica_id', $chi ?? $s['v']->id)
        ->where('immobile_id', $unita ?? $s['unita']->id)
        ->when($segno === '-', fn ($q) => $q->where('importo', '<', 0))->when($segno === '+', fn ($q) => $q->where('importo', '>', 0))->value('id');
}

function lgmEuro(int $c): string
{
    return sprintf('%s%d.%02d', $c < 0 ? '-' : '', intdiv(abs($c), 100), abs($c) % 100);
}

function lgmEmetti($test, array $s, array $numeri, string $il)
{
    return $test->actingAs($test->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $s['piano']]), [
        'rate_ids' => array_map(fn ($n) => lgmRata($s, $n), $numeri), 'data_emissione' => $il, 'invia_notifiche' => false,
    ]);
}

/** Un incasso dalla rotta vera: `$righe` = [id della quota => centesimi], negativo per usare un credito. */
function lgmIncassa($test, array $s, array $righe, string $il): int
{
    $test->actingAs($test->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['v']->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => $il, 'importo_totale' => lgmEuro(array_sum($righe)),
        'descrizione' => 'Incasso', 'eccedenza' => '0.00',
        'dettaglio_pagamenti' => array_map(fn ($id, $c) => ['rata_id' => $id, 'importo' => lgmEuro($c)], array_keys($righe), array_values($righe)),
    ])->assertSessionHasNoErrors();

    return (int) DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'incasso_rata')->max('id');
}

function lgmRimborsa($test, array $s, int $quotaId, int $importo, string $il): int
{
    $test->actingAs($test->user)->post(route('admin.gestionale.anagrafiche.rimborsi.store', [$s['c'], $s['v']]), [
        'rata_quote_id' => $quotaId, 'cassa_id' => $s['cassa']->id, 'data_rimborso' => $il, 'importo' => number_format($importo / 100, 2, ',', ''),
    ])->assertSessionHasNoErrors();

    return (int) DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'rimborso_condomino')->max('id');
}

function lgmStorna($test, array $s, int $id): void
{
    $test->actingAs($test->user)->post(route('admin.gestionale.movimenti-rate.storno', [$s['c'], $id]))->assertSessionHasNoErrors();
}

function lgmAnnullaEmissione($test, array $s, int $numero): void
{
    $r = $test->actingAs($test->user)->delete(route('admin.gestionale.piani-rate.annulla-emissione', ['condominio' => $s['c']->id, 'pianoRate' => $s['piano']->id, 'rata' => lgmRata($s, $numero)]));
    expect($r->getSession()->get('message')['type'])->toBe('success');
}

function lgmRicalcola($test, array $s): void
{
    $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $s['piano']]))->assertSessionHasNoErrors();
}

/** La pivot delle scritture date: [numero della rata, unità («I» appartamento, «B» box), importo della quota, importo nella pivot] per riga. */
function lgmPivot(array $s, array $ids): array
{
    return DB::table('quota_scrittura')->join('rate_quote', 'rate_quote.id', '=', 'quota_scrittura.rate_quota_id')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')
        ->whereIn('quota_scrittura.scrittura_contabile_id', $ids)->orderBy('quota_scrittura.scrittura_contabile_id')->orderBy('rate.numero_rata')->orderBy('quota_scrittura.importo_pagato')
        ->get(['rate.numero_rata', 'rate_quote.immobile_id', 'rate_quote.importo', 'quota_scrittura.importo_pagato'])
        ->map(fn ($r) => [(int) $r->numero_rata, (int) $r->immobile_id === (int) $s['unita']->id ? 'I' : 'B', (int) $r->importo, (int) $r->importo_pagato])->all();
}

/** Le righe con una persona (non di cassa) delle scritture date: il numero della rata, o null se la riga l'ha persa. */
function lgmRateDelleRighe(array $ids): array
{
    return DB::table('righe_scritture')->leftJoin('rate', 'rate.id', '=', 'righe_scritture.rata_id')->whereIn('righe_scritture.scrittura_id', $ids)
        ->whereNotNull('righe_scritture.anagrafica_id')->orderBy('righe_scritture.scrittura_id')->orderBy('righe_scritture.id')
        ->pluck('rate.numero_rata')->map(fn ($n) => $n === null ? null : (int) $n)->all();
}

/** Tutto ciò che di una riga del giornale non è il legame con la rata: deve restare identico (76.2). */
function lgmRigheSenzaRata(array $ids): array
{
    return DB::table('righe_scritture')->whereIn('scrittura_id', $ids)->orderBy('id')
        ->get(['id', 'scrittura_id', 'conto_contabile_id', 'cassa_id', 'anagrafica_id', 'immobile_id', 'tipo_riga', 'importo'])
        ->map(fn ($r) => (array) $r)->all();
}

/** Una scrittura, le sue figlie e le figlie delle figlie (la compensazione, le rettifiche dello storno, il riallineamento). */
function lgmFamiglia(int $id): array
{
    $ids = [$id];
    do {
        $prima = count($ids);
        $ids = DB::table('scritture_contabili')->whereIn('id', $ids)->orWhereIn('scrittura_padre_id', $ids)->pluck('id')->map(fn ($x) => (int) $x)->unique()->values()->all();
    } while (count($ids) > $prima);
    sort($ids);

    return $ids;
}

it('P7/D1 — il ricalcolo dopo un incasso stornato e un\'emissione annullata tiene il legame fra l\'incasso, la rettifica e la quota della rata 1', function () {
    $s = lgmScenario();
    lgmEmetti($this, $s, [1], '2026-01-31')->assertSessionHasNoErrors();
    $incasso = lgmIncassa($this, $s, [lgmQuota($s, 1) => 10000], '2026-01-20');
    lgmStorna($this, $s, $incasso);
    lgmAnnullaEmissione($this, $s, 1);
    $famiglia = lgmFamiglia($incasso);
    $righePrima = lgmRigheSenzaRata($famiglia);
    // L'incasso (+10000) e la sua rettifica (−10000) sulla quota della rata 1 (10000): la pivot somma a zero, il ricalcolo è ammesso.
    expect(lgmPivot($s, $famiglia))->toBe([[1, 'I', 10000, 10000], [1, 'I', 10000, -10000]]);

    lgmRicalcola($this, $s);

    // Le quote sono nuove (stessi importi: 120000 / 12 = 10000), la pivot torna sulla quota della rata 1 di Ugo, le due righe con la
    // persona riprendono la rata 1; nient'altro delle righe cambia (stessi id, conti, importi, persone). La quota nuova resta
    // com'è nata: importo_pagato 0. Oggi la pivot è vuota e le righe hanno rata_id nullo.
    expect(lgmPivot($s, $famiglia))->toBe([[1, 'I', 10000, 10000], [1, 'I', 10000, -10000]])
        ->and(lgmRateDelleRighe($famiglia))->toBe([1, 1])
        ->and(lgmRigheSenzaRata($famiglia))->toBe($righePrima)
        ->and((int) DB::table('rate_quote')->where('id', lgmQuota($s, 1))->value('importo_pagato'))->toBe(0);

    // La pagina dell'incasso stornato torna a dire la rata che pagava (IncassoRateService::getDettagliRate legge la pivot).
    $servizio = app(\App\Services\Gestionale\IncassoRateService::class);
    $riga = $servizio->getIncassiQuery($s['c'])->get()->map(fn ($m) => $servizio->formatMovimentoForFrontend($m))->firstWhere('id', $incasso);
    expect(collect($riga['dettagli_rate'])->pluck('numero')->all())->toBe([1]);
});

it('P7/D2 — «togli una voce» dopo un incasso stornato: le quote scendono a € 50,00 e il legame resta sulla rata 1', function () {
    $s = lgmScenario();
    lgmEmetti($this, $s, [1], '2026-01-31')->assertSessionHasNoErrors();
    $incasso = lgmIncassa($this, $s, [lgmQuota($s, 1) => 10000], '2026-01-20');
    lgmStorna($this, $s, $incasso);
    lgmAnnullaEmissione($this, $s, 1);
    $famiglia = lgmFamiglia($incasso);
    $righePrima = lgmRigheSenzaRata($famiglia);

    $this->actingAs($this->user)->delete(route('admin.gestionale.piani-rate.capitoli.detach', [$s['c'], $s['e'], $s['piano'], $s['voci']['Pulizie']->id]), ['accetta_destinatari' => true, 'nota_destinatari' => 'Voce tolta dalla delibera'])
        ->assertSessionHasNoErrors();

    // Resta «Spese generali», € 600,00 in 12 rate: 60000 / 12 = 5000 a rata. L'incasso stornato era di 10000, sulla quota di allora:
    // la pivot non si riscrive, si sposta.
    expect(lgmPivot($s, $famiglia))->toBe([[1, 'I', 5000, 10000], [1, 'I', 5000, -10000]])
        ->and(lgmRateDelleRighe($famiglia))->toBe([1, 1])
        ->and(lgmRigheSenzaRata($famiglia))->toBe($righePrima)
        ->and((int) DB::table('rate_quote')->where('id', lgmQuota($s, 1))->value('importo_pagato'))->toBe(0);
});

it('P7/D3 — il ricalcolo tiene anche i legami di una compensazione e di un rimborso stornati, con le loro rettifiche, e la quota a credito resta «credito»', function () {
    $s = lgmScenario('rata_zero', -20000);
    lgmEmetti($this, $s, [1], '2026-01-31')->assertSessionHasNoErrors();
    // € 50,00 del credito della rata 0 (in bozza) pagano metà della rata 1; poi lo storno.
    $compensazione = lgmIncassa($this, $s, [lgmQuota($s, 1) => 5000, lgmQuota($s, 0) => -5000], '2026-02-10');
    lgmStorna($this, $s, $compensazione);
    // € 50,00 dello stesso credito rimborsati, poi lo storno.
    $rimborso = lgmRimborsa($this, $s, lgmQuota($s, 0), 5000, '2026-02-12');
    lgmStorna($this, $s, $rimborso);
    lgmAnnullaEmissione($this, $s, 1);
    $famiglia = [...lgmFamiglia($compensazione), ...lgmFamiglia($rimborso)];
    $prima = lgmPivot($s, $famiglia);
    $righePrima = lgmRigheSenzaRata($famiglia);
    expect(count($prima))->toBe(6)->and(lgmRateDelleRighe($famiglia))->not->toContain(null);

    lgmRicalcola($this, $s);

    // Rata 0 (−20000): la compensazione −5000 e la sua rettifica +5000, il rimborso −5000 e la sua rettifica +5000; rata 1 (10000):
    // la compensazione +5000 e la rettifica −5000. Sei righe di pivot identiche a prima, nessuna riga del giornale senza rata.
    expect(lgmPivot($s, $famiglia))->toBe($prima)
        ->and(lgmRateDelleRighe($famiglia))->not->toContain(null)
        ->and(lgmRigheSenzaRata($famiglia))->toBe($righePrima)
        ->and((int) DB::table('rate_quote')->where('id', lgmQuota($s, 0))->value('importo_pagato'))->toBe(0)
        // La quota a credito resta come la scrive il generatore. Con `ricalcolaStato()` diventerebbe «pagata» (0 ≥ −20000), e il
        // conguaglio di una vendita dopo la leggerebbe come una bozza pagata (ConguaglioPassaggio, motivo «pagata»).
        ->and(DB::table('rate_quote')->where('id', lgmQuota($s, 0))->value('stato'))->toBe('credito');
});

it('P7/D4 — annullata una vendita che aveva diviso le bozze, la gemella del pregresso si riunisce alla sua quota e i legami di una compensazione stornata vanno con lei', function () {
    // «tutte le rate» con −€ 120,00: −1000 a rata, ogni quota di Ugo 10000 − 1000 = 9000.
    $s = lgmScenario('tutte_rate', -12000);
    lgmEmetti($this, $s, [1], '2026-01-31')->assertSessionHasNoErrors();
    $this->travel(1)->days();
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), [
        'tipo' => 'vendita', 'riga_uscente_id' => $s['rigaV'], 'anagrafica_entrante_id' => $s['a']->id, 'decorrenza' => '2026-05-01',
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-05-01',
        'estremi_titolo' => 'atto notaio Verdi, rep. 12345', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Rogito letto, piano preso',
    ])->assertSessionHasNoErrors()->assertRedirect();
    $passaggio = \App\Models\Gestionale\Subentro::latest('id')->firstOrFail();
    // La rata 5 si è divisa (decisione 25): 10000 a Elsa, la gemella del pregresso −1000 resta a Ugo.
    $gemella = lgmQuota($s, 5, $s['v']->id, '-');
    expect((int) DB::table('rate_quote')->where('id', $gemella)->value('importo'))->toBe(-1000)
        ->and((int) DB::table('rate_quote')->where('id', lgmQuota($s, 5, $s['a']->id))->value('importo'))->toBe(10000);

    // € 10,00 della gemella pagano parte della rata 1 di Ugo; poi lo storno.
    $this->travel(1)->days();
    $compensazione = lgmIncassa($this, $s, [lgmQuota($s, 1) => 1000, $gemella => -1000], '2026-05-10');
    lgmStorna($this, $s, $compensazione);
    $famiglia = lgmFamiglia($compensazione);
    expect(lgmPivot($s, $famiglia))->toBe([[1, 'I', 9000, 1000], [5, 'I', -1000, -1000], [1, 'I', 9000, -1000], [5, 'I', -1000, 1000]]);

    $this->travel(1)->days();
    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita'], $passaggio]), ['nota_annullamento' => 'Lo registro di nuovo'])->assertRedirect()->assertSessionHasNoErrors();

    // La rata 5 torna una quota sola di Ugo, 9000, e porta i legami della gemella: la compensazione −1000 e la rettifica +1000.
    // Le righe del giornale non cambiano (la rata è la stessa). Oggi le due righe di pivot della gemella vanno via a cascata.
    expect((int) DB::table('rate_quote')->where('rata_id', lgmRata($s, 5))->count())->toBe(1)
        ->and(lgmPivot($s, $famiglia))->toBe([[1, 'I', 9000, 1000], [5, 'I', 9000, -1000], [1, 'I', 9000, -1000], [5, 'I', 9000, 1000]])
        ->and(collect(lgmRateDelleRighe($famiglia))->sort()->values()->all())->toBe([1, 1, 5, 5])
        ->and((int) DB::table('rate_quote')->where('id', lgmQuota($s, 5))->value('importo_pagato'))->toBe(0);
});

it('P7/D4-bis — con un box di Ugo nella stessa rata, la gemella riporta i legami sulla quota con cui si riunisce, non sulla prima quota di Ugo', function () {
    // Appartamento 750/1000: 7500 a rata, meno 1000 di saldo («tutte le rate», −12000) = 6500. Box 250/1000: 2500 a rata.
    $s = lgmScenario('tutte_rate', -12000, conBox: true);
    lgmEmetti($this, $s, [1], '2026-01-31')->assertSessionHasNoErrors();
    $this->travel(1)->days();
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), [
        'tipo' => 'vendita', 'riga_uscente_id' => $s['rigaV'], 'anagrafica_entrante_id' => $s['a']->id, 'decorrenza' => '2026-05-01',
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-05-01',
        'estremi_titolo' => 'atto notaio Verdi, rep. 12345', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Rogito letto, piano preso, il box resta a Ugo',
    ])->assertSessionHasNoErrors()->assertRedirect();
    $passaggio = \App\Models\Gestionale\Subentro::latest('id')->firstOrFail();
    $gemella = lgmQuota($s, 5, $s['v']->id, '-');
    $boxDellaRata5 = lgmQuota($s, 5, $s['v']->id, null, $s['box']->id);
    // La rata 5 dell'appartamento: 7500 a Elsa, la gemella −1000 a Ugo; il box resta a Ugo con 2500, ed è la quota di Ugo con l'id
    // più basso nella rata (il box è stato creato per primo): chi cercasse «la quota di Ugo nella rata 5» troverebbe lui.
    expect((int) DB::table('rate_quote')->where('id', $gemella)->value('importo'))->toBe(-1000)
        ->and((int) DB::table('rate_quote')->where('id', lgmQuota($s, 5, $s['a']->id))->value('importo'))->toBe(7500)
        ->and((int) DB::table('rate_quote')->where('id', $boxDellaRata5)->value('importo'))->toBe(2500)
        ->and($boxDellaRata5)->toBeLessThan((int) DB::table('rate_quote')->where('rata_id', lgmRata($s, 5))->where('immobile_id', $s['unita']->id)->max('id'));

    $this->travel(1)->days();
    $compensazione = lgmIncassa($this, $s, [lgmQuota($s, 1) => 1000, $gemella => -1000], '2026-05-10');
    lgmStorna($this, $s, $compensazione);
    $famiglia = lgmFamiglia($compensazione);

    $this->travel(1)->days();
    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita'], $passaggio]), ['nota_annullamento' => 'Lo registro di nuovo'])->assertRedirect()->assertSessionHasNoErrors();

    // La compensazione ha pagato la rata 1 del box (la prima quota di Ugo nella rata 1: l'incasso col credito le prende per id), e
    // i legami della gemella vanno sulla quota dell'appartamento della rata 5, di nuovo di Ugo con 6500. Il box della rata 5 (2500)
    // non c'entra: niente pivot su di lui.
    expect(lgmPivot($s, $famiglia))->toBe([[1, 'B', 2500, 1000], [5, 'I', 6500, -1000], [1, 'B', 2500, -1000], [5, 'I', 6500, 1000]])
        ->and(DB::table('quota_scrittura')->where('rate_quota_id', $boxDellaRata5)->count())->toBe(0);
});

it('P7/D5 — la rettifica del riallineamento, che non ha pivot, riprende la sua rata dopo il ricalcolo, e così il suo storno', function () {
    $s = lgmScenario('rata_zero', -20000);
    lgmEmetti($this, $s, [1], '2026-01-31')->assertSessionHasNoErrors();
    // La compensazione com'era scritta dalla 1.10 (DC1: il prelievo su «Crediti»), poi la rettifica di DC1 del riallineamento,
    // figlia della compensazione: DARE «Passate gestioni» / AVERE «Crediti», 5000, a nome di Ugo sulla rata 0, senza pivot.
    $incasso = lgmIncassa($this, $s, [lgmQuota($s, 1) => 5000, lgmQuota($s, 0) => -5000], '2026-02-10');
    $comp = (int) DB::table('scritture_contabili')->where('scrittura_padre_id', $incasso)->where('tipo_movimento', 'storno_credito')->value('id');
    $conti = DB::table('conti_contabili')->where('condominio_id', $s['c']->id)->pluck('id', 'ruolo');
    DB::table('righe_scritture')->where('scrittura_id', $comp)->where('tipo_riga', 'dare')->update(['conto_contabile_id' => $conti['crediti_condomini']]);
    if (array_key_exists('kondomanager:riallinea-crediti', Artisan::all())) {
        $this->artisan('kondomanager:riallinea-crediti', ['--condominio' => $s['c']->id])->assertExitCode(0);
    } else {
        $r = ScritturaContabile::create(['condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'gestione_id' => $s['g']->id,
            'scrittura_padre_id' => $comp, 'data_registrazione' => now(), 'data_competenza' => '2026-02-10',
            'causale' => 'Riallineamento crediti (1.11) — rettifica', 'tipo_movimento' => 'rettifica', 'stato' => 'registrata']);
        $chiavi = ['anagrafica_id' => $s['v']->id, 'rata_id' => lgmRata($s, 0), 'immobile_id' => $s['unita']->id];
        $r->righe()->create(['conto_contabile_id' => $conti['crediti_condomini'], 'tipo_riga' => 'avere', 'importo' => 5000] + $chiavi);
        $r->righe()->create(['conto_contabile_id' => $conti['passate_gestioni'], 'tipo_riga' => 'dare', 'importo' => 5000] + $chiavi);
    }
    $riallineamento = (int) DB::table('scritture_contabili')->where('scrittura_padre_id', $comp)->where('tipo_movimento', 'rettifica')->value('id');
    expect($riallineamento)->toBeGreaterThan(0)
        ->and(DB::table('quota_scrittura')->where('scrittura_contabile_id', $riallineamento)->count())->toBe(0);
    lgmStorna($this, $s, $incasso);
    lgmAnnullaEmissione($this, $s, 1);
    // Le scritture senza pivot: il riallineamento e, se lo storno lo prende nella sua famiglia, il suo specchio.
    $senzaPivot = lgmFamiglia($riallineamento);
    $righePrima = lgmRigheSenzaRata($senzaPivot);
    expect(lgmRateDelleRighe($senzaPivot))->each->toBe(0);

    lgmRicalcola($this, $s);

    // Ogni riga con una persona del riallineamento (e del suo storno) riprende la rata 0. Oggi rata_id diventa nullo; la sola correzione
    // del laboratorio rimette solo le righe delle scritture che hanno una pivot.
    expect(lgmRateDelleRighe($senzaPivot))->not->toBeEmpty()
        ->and(lgmRateDelleRighe($senzaPivot))->each->toBe(0)
        ->and(lgmRigheSenzaRata($senzaPivot))->toBe($righePrima);
});

it('P7/D6 — con l\'appartamento e il box nella stessa rata il ricalcolo rimette ogni legame sulla quota della sua unità', function () {
    // Appartamento 750/1000: 7500 a rata; box 250/1000: 2500 a rata.
    $s = lgmScenario(conBox: true);
    lgmEmetti($this, $s, [1], '2026-01-31')->assertSessionHasNoErrors();
    // € 100,00 sulla rata 1 di Ugo: l'incasso paga le sue due quote, 7500 l'appartamento e 2500 il box. Poi lo storno.
    $incasso = lgmIncassa($this, $s, [lgmQuota($s, 1) => 10000], '2026-01-20');
    lgmStorna($this, $s, $incasso);
    lgmAnnullaEmissione($this, $s, 1);
    $famiglia = lgmFamiglia($incasso);
    $prima = lgmPivot($s, $famiglia);
    // Incasso: box +2500, appartamento +7500; rettifica: appartamento −7500, box −2500 (ordinate per importo nella pivot).
    expect($prima)->toBe([[1, 'B', 2500, 2500], [1, 'I', 7500, 7500], [1, 'I', 7500, -7500], [1, 'B', 2500, -2500]]);

    lgmRicalcola($this, $s);

    // Ogni legame torna sulla quota della sua unità: 7500 sull'appartamento, 2500 sul box. Una ricerca per sola persona e rata li
    // metterebbe tutti e quattro sulla stessa quota.
    expect(lgmPivot($s, $famiglia))->toBe($prima)
        ->and(lgmRateDelleRighe($famiglia))->not->toContain(null);
});
