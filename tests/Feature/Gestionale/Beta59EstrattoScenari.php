<?php

/**
 * Lo scenario condiviso dei test della 1.11.0-beta.59 sull'estratto conto per persona (Code 288 e 317).
 *
 * Un condominio con i mastri di sistema (`CondominioService::ensureDefaultConti`, come il condominio vero), l'esercizio 2026 aperto, la gestione
 * ordinaria, una cassa e un'unità di Mario Rossi da 1000 millesimi. La voce «Spese generali» (€ 6.000,00) e il piano ordinario in 12 rate (€ 500,00
 * ciascuna, approvato il 02/01/2026) nascono dalle rotte della pagina; l'emissione, l'incasso, la regolazione immediata, la fattura, il pagamento, gli storni e la nota di
 * credito passano dalle loro rotte, da amministratore (ruolo di serie). Mario Rossi è anche il rappresentante di un fornitore
 * (`anagrafica_fornitore`): è la persona che il ciclo passivo scrive sulle righe del fornitore (`$fornitore->referenti()->first()`).
 *
 * Non è un test: il nome non finisce in `Test.php` e Pest non lo raccoglie. Lo caricano con `require_once` i file `Beta59Estratto*Test.php`.
 *
 * Il denaro è in centesimi, positivo = debito. Le date sono stringhe `Y-m-d`.
 */

use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Fornitore;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PagamentoFornitore;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Immobile;
use App\Models\Tabella;
use App\Models\User;
use App\Services\CondominioService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;

/**
 * Il condominio della prova, con Mario Rossi condòmino dell'unità A e il piano 2026 generato. Oggi è il 30/10/2026. Con `$saldo` la pagina dei
 * saldi scrive prima del piano un saldo iniziale di Mario sull'unità (in centesimi, negativo = credito), che il piano assorbe con il metodo dato.
 */
function b59eScenario($test, ?int $saldo = null, string $metodo = 'tutte_rate'): array
{
    static $seq = 0;
    $seq++;
    $test->seed(RolesAndPermissionsSeeder::class);
    $test->travelTo('2026-10-30 09:00:00');

    $admin = User::factory()->create(['email_verified_at' => now()]);
    $admin->assignRole(Role::AMMINISTRATORE->value);
    $collaboratore = User::factory()->create(['email_verified_at' => now()]);
    $collaboratore->assignRole(Role::COLLABORATORE->value);

    $c = Condominio::factory()->create(['nome' => "Condominio Via dei Mille {$seq}"]);
    app(CondominioService::class)->ensureDefaultConti($c);
    $banca = ContoContabile::where('condominio_id', $c->id)->where('ruolo', 'cassa')->firstOrFail();
    $cassa = Cassa::create(['condominio_id' => $c->id, 'conto_contabile_id' => $banca->id, 'nome' => 'Banca principale', 'tipo' => 'banca', 'attiva' => true, 'saldo_iniziale' => 1000000]);
    $tabella = Tabella::create(['condominio_id' => $c->id, 'nome' => 'Proprietà', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    $e = Esercizio::factory()->create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = app(CondominioService::class)->createDefaultGestione($c, $e);

    $unita = Immobile::create(['condominio_id' => $c->id, 'tipo' => 'appartamento', 'codice_immobile' => "B59E-{$seq}-A", 'nome' => 'Interno A', 'interno' => 'A']);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $unita->id, 'valore' => 1000, 'created_at' => now(), 'updated_at' => now()]);
    $mario = Anagrafica::forceCreate(['nome' => 'Mario Rossi', 'email' => "b59e-mario{$seq}@test.it", 'indirizzo' => 'Via dei Mille 1',
        'codice_fiscale' => 'RSSMRA70A01H5' . str_pad((string) $seq, 3, '0', STR_PAD_LEFT)]);
    $mario->condomini()->syncWithoutDetaching([$c->id]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $mario->id, 'immobile_id' => $unita->id, 'tipologia' => 'proprietario', 'quota' => 100,
        'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);

    $s = ['t' => $test, 'admin' => $admin, 'collaboratore' => $collaboratore, 'c' => $c, 'e' => $e, 'g' => $g, 'cassa' => $cassa, 'tabella' => $tabella,
        'unita' => $unita, 'mario' => $mario, 'seq' => $seq];

    $s['pc'] = PianoConto::create(['condominio_id' => $c->id, 'gestione_id' => $g->id, 'nome' => 'Piano dei conti 2026']);
    $test->actingAs($admin)->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$c, $e, $s['pc']]), [
        'nome' => 'Spese generali', 'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => 'spesa', 'importo' => '6.000,00',
        'descrizione' => '', 'note' => '', 'parent_id' => null, 'tabella_millesimale_id' => $tabella->id, 'percentuale_proprietario' => 100,
        'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0, 'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
    ])->assertSessionHasNoErrors();
    $s['voce'] = Conto::where('piano_conto_id', $s['pc']->id)->where('nome', 'Spese generali')->firstOrFail();

    if ($saldo !== null) {
        $test->actingAs($admin)->post(route('admin.gestionale.saldi.store', [$c]), [
            'anagrafica_id' => $mario->id, 'immobile_id' => $unita->id, 'gestione_id' => $g->id, 'saldo_iniziale' => $saldo,
        ])->assertSessionHasNoErrors();
    }

    $test->actingAs($admin)->post(route('admin.gestionale.esercizi.piani-rate.store', [$c, $e]), [
        'tipo' => 'ordinario', 'gestione_id' => $g->id, 'nome' => 'Preventivo 2026', 'metodo_distribuzione' => $metodo, 'numero_rate' => 12,
        'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'genera_subito' => 1,
    ])->assertSessionHasNoErrors();
    $s['piano'] = PianoRate::where('nome', 'Preventivo 2026')->where('condominio_id', $c->id)->firstOrFail();
    $test->actingAs($admin)->put(route('admin.gestionale.piani-rate.update-stato', [$c, $e, $s['piano']]), ['approvato' => true, 'data_delibera_assemblea' => '2026-01-02'])
        ->assertSessionHasNoErrors();

    return $s;
}

/** Un fornitore con Mario Rossi (o la persona data) come suo rappresentante; con `$ritenuta` soggetto alla ritenuta di quella percentuale sull'imponibile intero. */
function b59eFornitore(array $s, string $ragioneSociale, int $ritenuta = 0, ?Anagrafica $rappresentante = null): Fornitore
{
    $rappresentante ??= $s['mario'];
    $id = DB::table('fornitori')->insertGetId([
        'ragione_sociale' => $ragioneSociale, 'soggetto_ritenuta' => $ritenuta > 0, 'ritenuta_decisa_il' => now(), 'perc_imponibile_ritenuta' => 100,
        'perc_ritenuta' => $ritenuta > 0 ? $ritenuta : 4, 'giorni_scadenza' => 30, 'modalita_pagamento_default' => 'bonifico', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('anagrafica_fornitore')->insert(['fornitore_id' => $id, 'anagrafica_id' => $rappresentante->id, 'ruolo' => 'titolare', 'created_at' => now(), 'updated_at' => now()]);
    $fornitore = Fornitore::findOrFail($id);
    expect($fornitore->referenti()->first()?->id)->toBe($rappresentante->id);

    return $fornitore;
}

function b59eRata(array $s, int $numero): int
{
    return (int) DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('numero_rata', $numero)->value('id');
}

function b59eQuota(array $s, int $numero): int
{
    return (int) DB::table('rate_quote')->where('rata_id', b59eRata($s, $numero))->where('anagrafica_id', $s['mario']->id)->value('id');
}

/** L'emissione dalla rotta della pagina del piano, con la data che l'amministratore sceglie. */
function b59eEmetti(array $s, array $numeri, string $data): void
{
    $ids = array_map(fn ($n) => b59eRata($s, $n), $numeri);
    $s['t']->actingAs($s['admin'])->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $s['piano']]), [
        'rate_ids' => $ids, 'data_emissione' => $data, 'invia_notifiche' => false,
    ])->assertSessionHasNoErrors();
    expect(DB::table('rate_quote')->whereIn('rata_id', $ids)->whereNull('scrittura_contabile_id')->count())->toBe(0);
}

/** L'incasso dalla rotta della pagina degli incassi: l'importo in euro come lo manda la pagina, sulla rata data. */
function b59eIncassa(array $s, int $numero, string $data, string $importo, string $eccedenza = '0.00', string $descrizione = 'Bonifico'): int
{
    $s['t']->actingAs($s['admin'])->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['mario']->id, 'cassa_id' => $s['cassa']->id, 'gestione_id' => $s['g']->id, 'data_pagamento' => $data, 'importo_totale' => $importo,
        'descrizione' => $descrizione, 'eccedenza' => $eccedenza,
        'dettaglio_pagamenti' => [['rata_id' => b59eQuota($s, $numero), 'importo' => number_format((float) $importo - (float) $eccedenza, 2, '.', '')]],
    ])->assertSessionHasNoErrors();

    return (int) DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'incasso_rata')->max('id');
}

/**
 * L'incasso che paga anche con il credito riportato, dalla rotta della pagina degli incassi: `$dettaglio` dà l'importo per rata (numero di rata
 * => euro), con la rata 0 per il credito che si usa (negativo). Restituisce la scrittura di compensazione («storno_credito»).
 */
function b59eCompensa(array $s, string $data, array $dettaglio, string $totale = '0.00', string $eccedenza = '0.00'): int
{
    $s['t']->actingAs($s['admin'])->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['mario']->id, 'cassa_id' => $s['cassa']->id, 'gestione_id' => $s['g']->id, 'data_pagamento' => $data, 'importo_totale' => $totale,
        'descrizione' => 'Compensazione', 'eccedenza' => $eccedenza,
        'dettaglio_pagamenti' => array_map(fn ($numero, $importo) => ['rata_id' => b59eQuota($s, $numero), 'importo' => $importo], array_keys($dettaglio), $dettaglio),
    ])->assertSessionHasNoErrors();

    return (int) DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'storno_credito')->max('id');
}

/** La regolazione immediata dalla sua rotta, con il fornitore indicato (o nessuno). */
function b59eRegolazione(array $s, ?Fornitore $fornitore, string $data, float $euro, string $causale = 'Imposta di bollo su estratto conto'): int
{
    $s['t']->actingAs($s['admin'])->post(route('admin.gestionale.regolazioni-immediate.store', [$s['c']]), [
        'gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id, 'conto_id' => $s['voce']->id, 'cassa_id' => $s['cassa']->id, 'fornitore_id' => $fornitore?->id,
        'data_operazione' => $data, 'causale' => $causale, 'importo' => $euro,
    ])->assertSessionHasNoErrors();

    return (int) DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'regolazione_immediata')->max('id');
}

/** Una fattura (o una nota di credito) del fornitore dalla rotta del modulo: una riga sulla voce «Spese generali» con l'IVA al 22 %. */
function b59eFattura(array $s, Fornitore $fornitore, string $numero, string $data, float $imponibile, array $extra = []): FatturaPassiva
{
    $s['t']->actingAs($s['admin'])->post(route('admin.gestionale.fatture.store', [$s['c']]), array_merge([
        'fornitore_id' => $fornitore->id, 'esercizio_id' => $s['e']->id, 'gestione_id' => $s['g']->id, 'tipo_documento' => 'fattura',
        'numero_documento' => $numero, 'data_documento' => $data, 'data_scadenza' => $data, 'modalita_pagamento' => 'bonifico', 'stato_approvazione' => 'approvata',
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => null],
        'righe' => [['descrizione' => 'Riparazione', 'importo_imponibile' => $imponibile, 'aliquota_iva' => 22, 'conto_id' => $s['voce']->id, 'is_sopravvenienza' => false]],
    ], $extra))->assertSessionHasNoErrors();

    return FatturaPassiva::where('condominio_id', $s['c']->id)->where('numero_documento', $numero)->firstOrFail();
}

/** Il pagamento dell'intero netto della fattura, dalla rotta del modulo dei pagamenti. */
function b59ePaga(array $s, Fornitore $fornitore, FatturaPassiva $fattura, string $data): PagamentoFornitore
{
    $s['t']->actingAs($s['admin'])->post(route('admin.gestionale.pagamenti-fornitori.store', [$s['c']]), [
        'fornitore_id' => $fornitore->id, 'esercizio_id' => $s['e']->id, 'conto_corrente_id' => $s['cassa']->conto_contabile_id, 'data_pagamento' => $data,
        'metodo_pagamento' => 'bonifico', 'iban_beneficiario' => 'IT60X0542811101000000123456', 'importo_commissioni_cents' => 0, 'bonifico_parlante' => false,
        'allow_overdraft' => true, 'iban_confermato_manualmente' => true, 'conferma_duplicato_verificato' => true,
        'allocazioni' => [['fattura_id' => $fattura->id, 'tipo' => 'pagamento', 'importo_allocato_cents' => (int) $fattura->fresh()->netto_a_pagare]],
    ])->assertSessionHasNoErrors();

    return PagamentoFornitore::where('condominio_id', $s['c']->id)->latest('id')->firstOrFail();
}

/** Ciò che la pagina dell'estratto conto di Mario Rossi (o della persona data) riceve, letto da chi la apre. */
function b59ePagina(array $s, ?User $chi = null, ?Anagrafica $di = null): array
{
    $r = $s['t']->actingAs($chi ?? $s['admin'])->get(route('admin.gestionale.anagrafiche.estratto-conto', [$s['c'], $di ?? $s['mario']]));
    $r->assertOk();
    $p = $r->viewData('page')['props'];
    $timeline = json_decode(json_encode($p['timeline']), true);

    return ['saldo' => $p['stats']['saldo_raw'], 'addebiti' => $p['stats']['totale_addebiti'], 'versato' => $p['stats']['totale_versamenti'],
        'saldo_finale' => $p['stats']['saldo_finale'], 'timeline' => $timeline];
}

/** Lo stesso estratto nel PDF: i dati che il controller passa alla vista della stampa e il testo che la vista ne stampa. */
function b59ePdf(array $s): array
{
    $dati = null;
    View::composer('pdf.gestionale.estratto_conto_anagrafica', function ($v) use (&$dati) {
        $dati ??= $v->getData();
    });
    $r = $s['t']->actingAs($s['admin'])->get(route('admin.gestionale.anagrafiche.estratto-conto.print', [$s['c'], $s['mario']]));
    $r->assertOk();
    expect($r->headers->get('Content-Type'))->toBe('application/pdf');
    $testo = preg_replace('/\s+/u', ' ', strip_tags(view('pdf.gestionale.estratto_conto_anagrafica', $dati)->render()));

    return ['saldo' => $dati['stats']['saldo_raw'], 'addebiti' => $dati['stats']['totale_addebiti'], 'versato' => $dati['stats']['totale_versamenti'],
        'saldo_finale' => $dati['stats']['saldo_finale'], 'timeline' => $dati['timeline']->all(), 'testo' => $testo];
}

/** Le tre cifre che pagina e PDF mostrano: saldo finale, «Totale addebiti», «Totale versato». */
function b59eCifre(array $estratto): array
{
    return [$estratto['saldo_finale'], $estratto['addebiti'], $estratto['versato']];
}
