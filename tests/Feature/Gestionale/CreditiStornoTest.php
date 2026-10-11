<?php

/**
 * 1.11.0-beta.49, la contabilità dei crediti — l'incasso, il rimborso e lo storno (voci P2, P4 e P8 del verbale,
 * `docs/piano_esecutivo_beta49.md`; decisioni 71, 75 e 76 del registro).
 *
 * - P2, DC1: il credito riportato da un esercizio precedente (quota a importo negativo) finché la sua quota non ha scrittura
 *   non è nei conti dell'anno: usato in compensazione, riaccreditato o rimborsato, esce da «Passate gestioni». Dopo
 *   l'emissione della sua rata (decisione 71) sta in «Crediti verso condomini». Senza il conto «Passate gestioni» la
 *   compensazione si rifiuta, come già il rimborso (76.3).
 * - P4, lo storno: lo specchio dell'originale più una scrittura figlia dell'emissione, che riporta fra i crediti verso
 *   condomini il credito restituito quando la sua rata è ormai a giornale (76.3); l'annullamento dell'emissione cancella
 *   anche le figlie; la famiglia dello storno comprende le rettifiche del riallineamento delle sue figlie (contrasto 3).
 * - P8, Coda 209: la conferma dello storno avvisa quando la parte in più dell'incasso è già stata usata (75.5), con la
 *   frase giusta anche quando l'incasso stornato una parte in più non l'aveva (sonda SC1 dello scettico).
 *
 * Il doppio storno concorrente (due richieste nello stesso istante, il blocco sulla scrittura) non si prova qui per intero:
 * SQLite in memoria non ha transazioni concorrenti. Si provano il doppio invio in sequenza e la metà deterministica della
 * corsa: una seconda chiamata con la scrittura letta prima della prima, che deve rileggere lo stato e non scrivere niente.
 *
 * Scenario comune (`cstScenario`): un'unità di Ugo, preventivo da € 1.200,00 in 12 rate da € 100,00 dal 5 gennaio, metodo
 * «rata zero», saldo pregresso di Ugo −€ 200,00 (un credito). La quota di Ugo nella rata 0 vale −20000 (spesa 0, saldo
 * usato −20000); le rate 1–12 valgono 10000 ciascuna. Cassa con € 100,00 di apertura.
 *
 * `cstConti()` restituisce, in centesimi interi e con il segno dei saldi (positivo = debito):
 * [mastrino «Crediti verso condomini» di Ugo, «Crediti verso condomini» nello stato patrimoniale, «Passate gestioni» nello
 *  stato patrimoniale (DARE positivo → saldo negativo), credito disponibile della quota della rata 0, saldo dell'estratto
 *  conto di Ugo].
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
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Models\Tabella;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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

function cstScenario(int $saldoUgo = -20000, int $cassaIniziale = 10000): array
{
    static $seq = 0;
    $seq++;
    $c = Condominio::factory()->create();
    $e = Esercizio::factory()->create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = Gestione::factory()->create(['condominio_id' => $c->id, 'nome' => 'Ordinaria 2026', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($e, $g->id);
    $pc = PianoConto::create(['condominio_id' => $c->id, 'gestione_id' => $g->id, 'nome' => 'PC']);
    $conto = Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Spese generali', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 120000]);
    $tabella = Tabella::create(['condominio_id' => $c->id, 'nome' => 'Proprietà', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    $unita = Immobile::create(['condominio_id' => $c->id, 'tipo' => 'appartamento', 'codice_immobile' => "CST-{$seq}", 'nome' => 'Interno 1', 'interno' => '1']);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $unita->id, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);

    $banca = ContoContabile::create(['condominio_id' => $c->id, 'codice' => '10.10', 'nome' => 'Banca', 'tipo' => 'attivo', 'ruolo' => 'banca', 'categoria' => 'liquidita']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '10.20', 'nome' => 'Crediti verso condomini', 'tipo' => 'attivo', 'ruolo' => 'crediti_condomini', 'categoria' => 'crediti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '20.10', 'nome' => 'Anticipi', 'tipo' => 'passivo', 'ruolo' => 'anticipi_condomini', 'categoria' => 'debiti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '20.20', 'nome' => 'Gestione rate', 'tipo' => 'passivo', 'ruolo' => 'gestione_rate', 'categoria' => 'debiti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '30.10', 'nome' => 'Passate gestioni', 'tipo' => 'passivo', 'ruolo' => 'passate_gestioni', 'categoria' => 'debiti']);
    $cassa = Cassa::create(['condominio_id' => $c->id, 'conto_contabile_id' => $banca->id, 'nome' => 'Banca principale', 'tipo' => 'banca', 'attiva' => true, 'saldo_iniziale' => $cassaIniziale]);

    $v = Anagrafica::forceCreate(['nome' => 'Ugo Venditore', 'email' => "cst-v{$seq}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'CSTVENDITOR' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT)]);
    $v->condomini()->syncWithoutDetaching([$c->id]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $v->id, 'immobile_id' => $unita->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    Saldo::create(['esercizio_id' => $e->id, 'condominio_id' => $c->id, 'anagrafica_id' => $v->id, 'immobile_id' => $unita->id, 'gestione_id' => $g->id, 'saldo_iniziale' => $saldoUgo, 'origine' => 'manuale', 'is_applicato' => false]);

    $piano = PianoRate::create([
        'gestione_id' => $g->id, 'condominio_id' => $c->id, 'esercizio_id' => $e->id, 'nome' => 'Preventivo 2026', 'stato' => 'approvato', 'tipo' => 'ordinario',
        'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'metodo_distribuzione' => 'rata_zero', 'applica_saldi' => true,
    ]);
    app(GeneratePianoRateAction::class)->execute($piano, forzaApplicazioneSaldi: true, esercizio: $e);

    return compact('c', 'e', 'g', 'unita', 'v', 'piano', 'cassa');
}

function cstQuota(array $s, int $numero): int
{
    return (int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')
        ->where('rate.piano_rate_id', $s['piano']->id)->where('rate.numero_rata', $numero)->where('rate_quote.anagrafica_id', $s['v']->id)->value('rate_quote.id');
}

function cstRata(array $s, int $numero): int
{
    return (int) DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('numero_rata', $numero)->value('id');
}

function cstContoId(array $s, string $ruolo): int
{
    return (int) DB::table('conti_contabili')->where('condominio_id', $s['c']->id)->where('ruolo', $ruolo)->value('id');
}

function cstEuro(int $c): string
{
    return sprintf('%s%d.%02d', $c < 0 ? '-' : '', intdiv(abs($c), 100), abs($c) % 100);
}

function cstEmetti($test, array $s, array $numeri, string $il)
{
    return $test->actingAs($test->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $s['piano']]), [
        'rate_ids' => array_map(fn ($n) => cstRata($s, $n), $numeri), 'data_emissione' => $il, 'invia_notifiche' => false,
    ]);
}

/** «Usa credito»: `$credito` centesimi dalla quota della rata `$daRata` di Ugo pagano la sua rata `$suRata`. Restituisce l'incasso padre. */
function cstCompensa($test, array $s, int $credito, int $daRata, int $suRata, string $il): int
{
    $test->actingAs($test->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['v']->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => $il, 'importo_totale' => '0.00',
        'descrizione' => 'Compensazione', 'eccedenza' => '0.00',
        'dettaglio_pagamenti' => [['rata_id' => cstQuota($s, $suRata), 'importo' => cstEuro($credito)], ['rata_id' => cstQuota($s, $daRata), 'importo' => cstEuro(-$credito)]],
    ])->assertSessionHasNoErrors();

    return (int) DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'incasso_rata')->max('id');
}

/** Un incasso in denaro dalla rotta vera: `$righe` = [numero della rata di Ugo => centesimi], più la parte in più. */
function cstIncassa($test, array $s, array $righe, int $parteInPiu, string $il): int
{
    $test->actingAs($test->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['v']->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => $il, 'importo_totale' => cstEuro(array_sum($righe) + $parteInPiu),
        'descrizione' => 'Bonifico', 'eccedenza' => cstEuro($parteInPiu),
        'dettaglio_pagamenti' => array_map(fn ($n, $c) => ['rata_id' => cstQuota($s, $n), 'importo' => cstEuro($c)], array_keys($righe), array_values($righe)),
    ])->assertSessionHasNoErrors();

    return (int) DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'incasso_rata')->max('id');
}

function cstRimborsa($test, array $s, int $importo, int $daRata, string $il): int
{
    $test->actingAs($test->user)->post(route('admin.gestionale.anagrafiche.rimborsi.store', [$s['c'], $s['v']]), [
        'rata_quote_id' => cstQuota($s, $daRata), 'cassa_id' => $s['cassa']->id, 'data_rimborso' => $il, 'importo' => number_format($importo / 100, 2, ',', ''),
    ])->assertSessionHasNoErrors();

    return (int) DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'rimborso_condomino')->max('id');
}

function cstStorna($test, array $s, int $id)
{
    return $test->actingAs($test->user)->post(route('admin.gestionale.movimenti-rate.storno', [$s['c'], $id]));
}

function cstAnnullaEmissione($test, array $s, int $numero)
{
    return $test->actingAs($test->user)->delete(route('admin.gestionale.piani-rate.annulla-emissione', ['condominio' => $s['c']->id, 'pianoRate' => $s['piano']->id, 'rata' => cstRata($s, $numero)]));
}

function cstAnteprima($test, array $s, int $incasso)
{
    return $test->actingAs($test->user)->getJson(route('admin.gestionale.movimenti-rate.storno.anteprima', [$s['c'], $incasso]));
}

function cstConti(array $s): array
{
    $sp = app(\App\Services\Gestionale\StatoPatrimonialeService::class)->calcola($s['c']);
    $voci = collect([...$sp['attivo']['voci'], ...$sp['passivo']['voci']]);
    $mastrino = (int) DB::table('righe_scritture')->join('scritture_contabili', 'scritture_contabili.id', '=', 'righe_scritture.scrittura_id')
        ->join('conti_contabili', 'conti_contabili.id', '=', 'righe_scritture.conto_contabile_id')
        ->where('scritture_contabili.condominio_id', $s['c']->id)->whereNull('scritture_contabili.deleted_at')
        ->where('conti_contabili.ruolo', 'crediti_condomini')->where('righe_scritture.anagrafica_id', $s['v']->id)
        ->selectRaw("SUM(CASE WHEN righe_scritture.tipo_riga = 'dare' THEN righe_scritture.importo ELSE -righe_scritture.importo END) as s")->value('s');
    $q0 = RataQuote::find(cstQuota($s, 0));
    $estratto = (int) test()->actingAs(test()->user)->get(route('admin.gestionale.anagrafiche.estratto-conto', [$s['c'], $s['v']]))->viewData('page')['props']['stats']['saldo_raw'];

    return [$mastrino, (int) $voci->where('ruolo', 'crediti_condomini')->sum('saldo'), (int) $voci->where('ruolo', 'passate_gestioni')->sum('saldo'), (int) $q0->credito_disponibile, $estratto];
}

/** Le righe di una scrittura come [ruolo del conto, verso, importo], in ordine di ruolo, verso e importo (l'ordine di scrittura non conta). */
function cstRighe(int $scritturaId): array
{
    return DB::table('righe_scritture')->join('conti_contabili', 'conti_contabili.id', '=', 'righe_scritture.conto_contabile_id')
        ->where('righe_scritture.scrittura_id', $scritturaId)
        ->get(['conti_contabili.ruolo', 'righe_scritture.tipo_riga', 'righe_scritture.importo'])
        ->map(fn ($r) => [$r->ruolo, $r->tipo_riga, (int) $r->importo])
        ->sortBy(fn ($r) => sprintf('%s|%s|%012d', $r[0], $r[1], $r[2]))->values()->all();
}

/**
 * La compensazione di prima com'era scritta dalla 1.9 e dalla 1.10 (DC1: il prelievo del credito da saldi della rata 0 in
 * bozza su «Crediti verso condomini»), poi riallineata con la rettifica di DC1 (decisione 76.1): DARE «Passate gestioni» /
 * AVERE «Crediti verso condomini», 5000, a nome di Ugo sulla rata 0. La rettifica è figlia della compensazione (contrasto 3
 * del critico). Se il comando `kondomanager:riallinea-crediti` esiste, la scrive lui; altrimenti (il codice di oggi) la si
 * scrive a mano nella forma del comando del progetto, con il suo marcatore nella causale.
 */
function cstCompensazioneDella110Riallineata($test, array $s): int
{
    cstEmetti($test, $s, [1, 2], '2026-02-28')->assertSessionHasNoErrors();
    $incasso = cstCompensa($test, $s, 5000, 0, 1, '2026-03-10');
    $comp = (int) DB::table('scritture_contabili')->where('scrittura_padre_id', $incasso)->where('tipo_movimento', 'storno_credito')->value('id');
    DB::table('righe_scritture')->where('scrittura_id', $comp)->where('tipo_riga', 'dare')->update(['conto_contabile_id' => cstContoId($s, 'crediti_condomini')]);

    if (array_key_exists('kondomanager:riallinea-crediti', Artisan::all())) {
        $test->artisan('kondomanager:riallinea-crediti', ['--condominio' => $s['c']->id])->assertExitCode(0);
    } else {
        $r = ScritturaContabile::create(['condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'gestione_id' => $s['g']->id,
            'scrittura_padre_id' => $comp, 'data_registrazione' => now(), 'data_competenza' => '2026-03-10',
            'causale' => 'Riallineamento crediti (1.11) — rettifica', 'tipo_movimento' => 'rettifica', 'stato' => 'registrata']);
        $chiavi = ['anagrafica_id' => $s['v']->id, 'rata_id' => cstRata($s, 0), 'immobile_id' => $s['unita']->id];
        $r->righe()->create(['conto_contabile_id' => cstContoId($s, 'crediti_condomini'), 'tipo_riga' => 'avere', 'importo' => 5000] + $chiavi);
        $r->righe()->create(['conto_contabile_id' => cstContoId($s, 'passate_gestioni'), 'tipo_riga' => 'dare', 'importo' => 5000] + $chiavi);
    }
    // La rettifica c'è, una sola, figlia della compensazione.
    expect(DB::table('scritture_contabili')->where('scrittura_padre_id', $comp)->where('tipo_movimento', 'rettifica')->count())->toBe(1);

    return $incasso;
}

// ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// P2 — DC1: il conto del credito nel prelievo, nel riaccredito e nel rimborso
// ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

it('P2/DC1 — il credito da saldi della rata 0 in bozza usato in compensazione esce da «Passate gestioni», non resta fra i crediti verso condomini', function () {
    $s = cstScenario();
    cstEmetti($this, $s, [1, 2], '2026-02-28')->assertSessionHasNoErrors();
    // Il credito di € 200,00 paga le rate 1 e 2 (€ 100,00 ciascuna), con la rata 0 in bozza.
    $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['v']->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2026-03-10', 'importo_totale' => '0.00', 'descrizione' => 'Compensazione', 'eccedenza' => '0.00',
        'dettaglio_pagamenti' => [['rata_id' => cstQuota($s, 1), 'importo' => '100.00'], ['rata_id' => cstQuota($s, 2), 'importo' => '100.00'], ['rata_id' => cstQuota($s, 0), 'importo' => '-200.00']],
    ])->assertSessionHasNoErrors();
    $comp = (int) DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'storno_credito')->value('id');

    // A mano. «Crediti» di Ugo: DARE 10000 + 10000 (emissione delle rate 1 e 2) − AVERE 10000 − 10000 (la compensazione le
    // chiude) = 0. Il prelievo è DARE «Passate gestioni» 20000 → saldo −20000. Credito della quota 0: −20000 − (−20000) = 0.
    // Estratto: saldo iniziale −20000 + 20000 di rate + 20000 di prelievo − 20000 di compensazione = 0.
    expect(cstConti($s))->toBe([0, 0, -20000, 0, 0])
        ->and(cstRighe($comp))->toBe([
            ['crediti_condomini', 'avere', 10000],
            ['crediti_condomini', 'avere', 10000],
            ['passate_gestioni', 'dare', 20000],
        ]);
});

it('P2/DC1 — il riaccredito del credito non usato torna sullo stesso conto del prelievo, «Passate gestioni»', function () {
    $s = cstScenario();
    cstEmetti($this, $s, [1, 2], '2026-02-28')->assertSessionHasNoErrors();
    // Rata 1 da € 100,00: € 90,00 in contanti più € 40,00 di credito della rata 0 (in bozza). Servono € 10,00 di credito; gli
    // altri € 30,00 tornano alla quota 0 (StoreIncassoRateAction, il riaccredito), e la parte in più dichiarata è quella.
    $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['v']->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2026-03-10', 'importo_totale' => '90.00', 'descrizione' => 'Contanti più credito', 'eccedenza' => '30.00',
        'dettaglio_pagamenti' => [['rata_id' => cstQuota($s, 0), 'importo' => '-40.00'], ['rata_id' => cstQuota($s, 1), 'importo' => '100.00']],
    ])->assertSessionHasNoErrors();
    $comp = (int) DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'storno_credito')->value('id');

    // La compensazione: prelievo DARE «Passate» 4000, chiusura della rata 1 AVERE «Crediti» 1000, riaccredito AVERE «Passate» 3000.
    // «Crediti» di Ugo: 20000 − 9000 (contanti) − 1000 (credito) = 10000. «Passate»: DARE 4000 − AVERE 3000 = DARE 1000 → −1000.
    // Credito della quota 0: 20000 − 4000 + 3000 = 19000. Estratto: −20000 + 20000 − 9000 + 4000 − 1000 − 3000 = −9000 (Ugo
    // deve 10000 della rata 2 e ha 19000 di credito).
    // Se si corregge il solo prelievo e non il riaccredito: «Crediti» 20000 − 9000 − 1000 − 3000 = 7000, «Passate» −4000.
    expect(cstConti($s))->toBe([10000, 10000, -1000, 19000, -9000])
        ->and(cstRighe($comp))->toBe([
            ['crediti_condomini', 'avere', 1000],
            ['passate_gestioni', 'avere', 3000],
            ['passate_gestioni', 'dare', 4000],
        ]);
});

it('P2/DC1 — con la rata 0 già a giornale il prelievo e il riaccredito del credito restano su «Crediti verso condomini»', function () {
    $s = cstScenario();
    cstEmetti($this, $s, [0, 1, 2], '2026-02-28')->assertSessionHasNoErrors();
    // Lo stesso pagamento del riaccredito qui sopra, ma con la rata 0 già a giornale (decisione 71: AVERE «Crediti» 20000 a nome
    // di Ugo sulla rata 0, DARE «Passate» 20000). Il credito sta ora fra i crediti verso condomini: prelievo e riaccredito escono e
    // tornano lì, e «Passate gestioni» non si muove più.
    $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['v']->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2026-03-10', 'importo_totale' => '90.00', 'descrizione' => 'Contanti più credito', 'eccedenza' => '30.00',
        'dettaglio_pagamenti' => [['rata_id' => cstQuota($s, 0), 'importo' => '-40.00'], ['rata_id' => cstQuota($s, 1), 'importo' => '100.00']],
    ])->assertSessionHasNoErrors();
    $comp = (int) DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'storno_credito')->value('id');

    // A mano. La compensazione: prelievo DARE «Crediti» 4000 (rata 0), chiusura della rata 1 AVERE «Crediti» 1000, riaccredito
    // AVERE «Crediti» 3000 (rata 0). «Crediti» di Ugo: 20000 (rate 1 e 2) − 20000 (credito della rata 0) − 9000 (contanti)
    // + 4000 − 1000 − 3000 = −9000. «Passate»: DARE 20000 dell'emissione → −20000. Credito della quota 0: 20000 − 4000 + 3000 =
    // 19000. Estratto −9000: Ugo deve 10000 della rata 2 e ha 19000 di credito.
    // Con il criterio «importo < 0» (senza guardare se la quota è a giornale) prelievo e riaccredito andrebbero su «Passate»:
    // «Crediti» −10000, «Passate» −21000. Oggi la rata 0 non va a giornale (DC2): [11000, 11000, 0, 19000, −9000].
    expect(cstConti($s))->toBe([-9000, -9000, -20000, 19000, -9000])
        ->and(cstRighe($comp))->toBe([
            ['crediti_condomini', 'avere', 1000],
            ['crediti_condomini', 'avere', 3000],
            ['crediti_condomini', 'dare', 4000],
        ]);
});

it('P2/DC1 — senza il conto «Passate gestioni» la compensazione di un credito da saldi si rifiuta con la frase del rimborso, e non scrive niente', function () {
    $s = cstScenario();
    cstEmetti($this, $s, [1, 2], '2026-02-28')->assertSessionHasNoErrors();
    DB::table('conti_contabili')->where('condominio_id', $s['c']->id)->where('ruolo', 'passate_gestioni')->delete();

    // Il rimborso dello stesso credito si rifiuta già oggi (RimborsaCreditoAction): la sua frase è il riferimento (76.3, «come già il rimborso»).
    $this->actingAs($this->user)->post(route('admin.gestionale.anagrafiche.rimborsi.store', [$s['c'], $s['v']]), [
        'rata_quote_id' => cstQuota($s, 0), 'cassa_id' => $s['cassa']->id, 'data_rimborso' => '2026-03-09', 'importo' => '50,00',
    ])->assertSessionHasErrors('rata_quote_id');
    $fraseDelRimborso = session('errors')->first('rata_quote_id');
    // Il nome del conto è quello che gli dà il programma, uno solo (giro stretto sulle correzioni della Fase 1-bis, LT-2).
    expect($fraseDelRimborso)->toStartWith('Manca il conto di sistema «Fondo Passate Gestioni» (2301)');

    $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['v']->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2026-03-10', 'importo_totale' => '0.00', 'descrizione' => 'Compensazione', 'eccedenza' => '0.00',
        'dettaglio_pagamenti' => [['rata_id' => cstQuota($s, 1), 'importo' => '100.00'], ['rata_id' => cstQuota($s, 0), 'importo' => '-100.00']],
    ])->assertSessionHasErrors('dettaglio_pagamenti');

    expect(session('errors')->first('dettaglio_pagamenti'))->toBe($fraseDelRimborso)
        ->and(DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->whereIn('tipo_movimento', ['incasso_rata', 'storno_credito', 'rimborso_condomino'])->count())->toBe(0)
        ->and((int) RataQuote::find(cstQuota($s, 0))->importo_pagato)->toBe(0)
        ->and((int) RataQuote::find(cstQuota($s, 1))->importo_pagato)->toBe(0);
});

it('P2/DC1 (guardia) — senza il conto «Passate gestioni» la compensazione di una parte in più si registra: quel credito sta già fra i crediti verso condomini', function () {
    $s = cstScenario(saldoUgo: 0);
    cstEmetti($this, $s, [1, 2], '2026-02-28')->assertSessionHasNoErrors();
    DB::table('conti_contabili')->where('condominio_id', $s['c']->id)->where('ruolo', 'passate_gestioni')->delete();
    // € 150,00 sulla rata 1 da € 100,00: la parte in più (5000) resta sulla quota della rata 1, ed è a giornale (AVERE «Crediti»).
    cstIncassa($this, $s, [1 => 10000], 5000, '2026-03-05');

    $comp = cstCompensa($this, $s, 5000, 1, 2, '2026-03-10');

    // «Crediti» di Ugo: 20000 − 15000 (l'incasso, parte in più compresa) + 5000 (prelievo) − 5000 (rata 2) = 5000.
    $figlia = (int) DB::table('scritture_contabili')->where('scrittura_padre_id', $comp)->where('tipo_movimento', 'storno_credito')->value('id');
    expect(cstRighe($figlia))->toBe([['crediti_condomini', 'avere', 5000], ['crediti_condomini', 'dare', 5000]])
        ->and((int) RataQuote::find(cstQuota($s, 2))->importo_pagato)->toBe(5000);
});

it('P2/DC1 — il rimborso del credito della rata 0 già a giornale rovescia «Crediti verso condomini», e la nota non lo chiama eccedenza', function () {
    $s = cstScenario();
    cstEmetti($this, $s, [0, 1, 2], '2026-02-28')->assertSessionHasNoErrors();
    $rimborso = cstRimborsa($this, $s, 5000, 0, '2026-03-05');

    // Con la 71 l'emissione della rata 0 ha scritto AVERE «Crediti» 20000 / DARE «Passate» 20000 a nome di Ugo: il credito è
    // fra i crediti verso condomini, e il rimborso lo rovescia lì. «Crediti» di Ugo: 20000 (rate 1 e 2) − 20000 + 5000 = 5000.
    // «Passate»: −20000. Credito della quota 0: 20000 − 5000 = 15000. Estratto: 5000.
    expect(cstConti($s))->toBe([5000, 5000, -20000, 15000, 5000])
        ->and(collect(cstRighe($rimborso))->firstWhere(1, 'dare'))->toBe(['crediti_condomini', 'dare', 5000]);
    // La nota dice da dove viene il credito (la nota del rimborso a tre casi; il testo è di P11): è un credito riportato già a
    // giornale, non una parte in più versata.
    $nota = (string) DB::table('scritture_contabili')->where('id', $rimborso)->value('note');
    expect($nota)->not->toContain('eccedenza di versamento')
        ->and($nota)->toContain('Crediti verso condomini');
});

// ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// P4 — lo storno: lo specchio dell'originale più la figlia dell'emissione
// ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

it('P4/S1 — lo storno di una compensazione presa con la rata 0 in bozza, dopo che la rata 0 è andata a giornale, rimette il credito fra i crediti verso condomini con una figlia dell\'emissione', function () {
    $s = cstScenario();
    cstEmetti($this, $s, [1, 2], '2026-02-28')->assertSessionHasNoErrors();
    // € 50,00 del credito pagano metà della rata 1, con la rata 0 ancora in bozza: DARE «Passate» 5000, AVERE «Crediti» 5000.
    $incasso = cstCompensa($this, $s, 5000, 0, 1, '2026-03-10');
    $comp = (int) DB::table('scritture_contabili')->where('scrittura_padre_id', $incasso)->where('tipo_movimento', 'storno_credito')->value('id');
    cstEmetti($this, $s, [0], '2026-03-15')->assertSessionHasNoErrors();
    // L'emissione della rata 0 porta a giornale il credito che resta: AVERE «Crediti» 15000, DARE «Passate» 15000.
    // «Crediti»: 20000 − 5000 − 15000 = 0. «Passate»: DARE 5000 + 15000 → −20000. Credito: 15000. Estratto 0.
    expect(cstConti($s))->toBe([0, 0, -20000, 15000, 0]);
    $emissione0 = (int) DB::table('rate_quote')->where('id', cstQuota($s, 0))->value('scrittura_contabile_id');

    cstStorna($this, $s, $incasso)->assertSessionHasNoErrors();

    // Lo specchio della compensazione rovescia i suoi conti, senza cambiarli: AVERE «Passate» 5000, DARE «Crediti» 5000.
    $rettifica = (int) DB::table('scritture_contabili')->where('scrittura_padre_id', $comp)->where('tipo_movimento', 'rettifica')->value('id');
    expect(cstRighe($rettifica))->toBe([['crediti_condomini', 'dare', 5000], ['passate_gestioni', 'avere', 5000]]);
    // La figlia dell'emissione della rata 0 porta i 5000 restituiti fra i crediti: AVERE «Crediti» 5000 a nome di Ugo sulla rata 0,
    // DARE «Passate» 5000, come avrebbe fatto l'emissione senza l'uso.
    $figlie = DB::table('scritture_contabili')->where('scrittura_padre_id', $emissione0)->get();
    expect($figlie)->toHaveCount(1)
        ->and($figlie[0]->tipo_movimento)->toBe('emissione_rata');
    expect(cstRighe((int) $figlie[0]->id))->toBe([['crediti_condomini', 'avere', 5000], ['passate_gestioni', 'dare', 5000]])
        ->and(DB::table('righe_scritture')->where('scrittura_id', $figlie[0]->id)->where('tipo_riga', 'avere')->first(['anagrafica_id', 'rata_id', 'immobile_id']))
        ->toEqual((object) ['anagrafica_id' => $s['v']->id, 'rata_id' => cstRata($s, 0), 'immobile_id' => $s['unita']->id]);

    // A mano. «Crediti»: 20000 − 5000 − 15000 + 5000 (specchio) − 5000 (figlia) = 0. «Passate»: DARE 5000 + 15000 − 5000 + 5000 =
    // 20000 → −20000. Il credito torna intero, 20000. Estratto 0: Ugo deve 20000 di rate 1 e 2 e ha 20000 di credito.
    // Con lo specchio da solo (la patch del laboratorio): [5000, 5000, −15000, 20000, 0].
    expect(cstConti($s))->toBe([0, 0, -20000, 20000, 0]);

    // Il doppio invio in sequenza non scrive una seconda figlia né una seconda rettifica.
    cstStorna($this, $s, $incasso);
    expect(DB::table('scritture_contabili')->where('scrittura_padre_id', $emissione0)->count())->toBe(1)
        ->and(DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'rettifica')->count())->toBe(2)
        ->and(cstConti($s))->toBe([0, 0, -20000, 20000, 0]);
});

it('P4/S2 — lo storno di un rimborso fatto con la rata 0 in bozza, dopo che la rata 0 è andata a giornale, rimette il credito fra i crediti verso condomini', function () {
    $s = cstScenario();
    $rimborso = cstRimborsa($this, $s, 5000, 0, '2026-02-20');
    cstEmetti($this, $s, [0, 1, 2], '2026-02-28')->assertSessionHasNoErrors();
    // Il rimborso è uscito da «Passate gestioni» (DARE 5000), l'emissione ha portato a giornale il credito che restava (15000).
    // «Crediti»: 20000 − 15000 = 5000. «Passate»: DARE 5000 + 15000 → −20000. Credito: 15000. Estratto 5000.
    expect(cstConti($s))->toBe([5000, 5000, -20000, 15000, 5000]);

    cstStorna($this, $s, $rimborso)->assertSessionHasNoErrors();

    // Specchio: AVERE «Passate» 5000 e DARE banca 5000; figlia: AVERE «Crediti» 5000 e DARE «Passate» 5000.
    // «Crediti»: 5000 − 5000 = 0. «Passate»: 20000 − 5000 + 5000 → −20000. Credito 20000. Cassa: 10000 − 5000 + 5000 = 10000.
    expect(cstConti($s))->toBe([0, 0, -20000, 20000, 0])
        ->and(app(\App\Services\Gestionale\SaldoCassaService::class)->saldoDisponibile($s['cassa']))->toBe(10000);
});

it('P4/S3 — dopo quello storno l\'annullamento dell\'emissione della rata 0 cancella anche la figlia, e l\'emissione rifatta riporta il credito intero', function () {
    $s = cstScenario();
    cstEmetti($this, $s, [1, 2], '2026-02-28')->assertSessionHasNoErrors();
    $emissioniDelleRate1e2 = DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'emissione_rata')->orderBy('id')->pluck('id')->all();
    $incasso = cstCompensa($this, $s, 5000, 0, 1, '2026-03-10');
    cstEmetti($this, $s, [0], '2026-03-15')->assertSessionHasNoErrors();
    cstStorna($this, $s, $incasso)->assertSessionHasNoErrors();

    $r = cstAnnullaEmissione($this, $s, 0);
    expect($r->getSession()->get('message')['type'])->toBe('success');
    // Si cancellano l'emissione della rata 0 (AVERE 15000 / DARE 15000) e la sua figlia (AVERE 5000 / DARE 5000): restano le
    // sole emissioni delle rate 1 e 2. «Crediti»: 20000 − 5000 + 5000 = 20000. «Passate»: 5000 − 5000 = 0. Credito 20000.
    // Se la figlia restasse (la FK `scrittura_padre_id` è nullOnDelete): «Crediti» 15000, «Passate» −5000.
    // La forma «la rettifica sul conto dove il credito sta ora» qui dava [15000, 15000, −5000] (misurato dal progettista).
    expect(cstConti($s))->toBe([20000, 20000, 0, 20000, 0])
        ->and(DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'emissione_rata')->orderBy('id')->pluck('id')->all())->toBe($emissioniDelleRate1e2);

    cstEmetti($this, $s, [0], '2026-03-20')->assertSessionHasNoErrors();
    // Emissione rifatta: AVERE «Crediti» 20000 / DARE «Passate» 20000 (da «Passate» non è uscito niente).
    expect(cstConti($s))->toBe([0, 0, -20000, 20000, 0]);
});

it('P4/S4 (guardia) — una compensazione della 1.10 scritta su «Crediti» con la rata 0 in bozza, stornata con la rata 0 ancora in bozza: lo storno rovescia quello che c\'è scritto', function () {
    $s = cstScenario();
    cstEmetti($this, $s, [1, 2], '2026-02-28')->assertSessionHasNoErrors();
    $incasso = cstCompensa($this, $s, 5000, 0, 1, '2026-03-10');
    // Com'era scritta dalla 1.9 e dalla 1.10 (DC1): il prelievo del credito su «Crediti verso condomini».
    $comp = (int) DB::table('scritture_contabili')->where('scrittura_padre_id', $incasso)->where('tipo_movimento', 'storno_credito')->value('id');
    DB::table('righe_scritture')->where('scrittura_id', $comp)->where('tipo_riga', 'dare')->update(['conto_contabile_id' => cstContoId($s, 'crediti_condomini')]);
    // «Crediti»: 20000 + 5000 − 5000 = 20000; «Passate» 0; credito 15000.
    expect(cstConti($s))->toBe([20000, 20000, 0, 15000, 0]);

    cstStorna($this, $s, $incasso)->assertSessionHasNoErrors();

    // Rata 0 in bozza: Ugo deve 20000, il credito (20000) è fuori dal giornale, «Passate gestioni» non si è mai mossa.
    // La forma «sul conto di adesso» qui dava [25000, 25000, +5000] (misurato dal progettista).
    expect(cstConti($s))->toBe([20000, 20000, 0, 20000, 0]);
});

it('P4/S5 — una compensazione della 1.10 riallineata, stornata con la rata 0 in bozza: lo storno rovescia anche la rettifica del riallineamento, figlia della compensazione', function () {
    $s = cstScenario();
    $incasso = cstCompensazioneDella110Riallineata($this, $s);
    // Dopo il riallineamento: «Crediti» 20000 − 5000 + 5000 − 5000 = 15000; «Passate» DARE 5000 → −5000; credito 15000.
    expect(cstConti($s))->toBe([15000, 15000, -5000, 15000, 0]);

    cstStorna($this, $s, $incasso)->assertSessionHasNoErrors();

    // Tutto come prima della compensazione: «Crediti» 20000, «Passate» 0, credito 20000. Oggi la rettifica, nipote dell'incasso,
    // resta fuori dallo storno: [15000, 15000, −5000, 20000, 0].
    expect(cstConti($s))->toBe([20000, 20000, 0, 20000, 0]);
    // E la rettifica del riallineamento ha il suo specchio, come l'incasso e la compensazione.
    $rettificaDelRiallineamento = (int) DB::table('scritture_contabili')->where('tipo_movimento', 'rettifica')
        ->whereIn('scrittura_padre_id', DB::table('scritture_contabili')->where('scrittura_padre_id', $incasso)->where('tipo_movimento', 'storno_credito')->pluck('id'))->value('id');
    expect(DB::table('scritture_contabili')->where('scrittura_padre_id', $rettificaDelRiallineamento)->where('tipo_movimento', 'rettifica')->count())->toBe(1);
});

it('P4/S5 — la stessa compensazione riallineata, stornata dopo l\'emissione della rata 0: il credito torna intero fra i crediti verso condomini', function () {
    $s = cstScenario();
    $incasso = cstCompensazioneDella110Riallineata($this, $s);
    cstEmetti($this, $s, [0], '2026-03-15')->assertSessionHasNoErrors();
    // L'emissione legge il credito già uscito da «Passate» (la rettifica, 5000) e porta a giornale il resto: AVERE 15000 / DARE 15000.
    // «Crediti»: 15000 − 15000 = 0; «Passate»: 5000 + 15000 → −20000; credito 15000. (Oggi, senza la 71: [15000, 15000, −5000, 15000, 0].)
    expect(cstConti($s))->toBe([0, 0, -20000, 15000, 0]);

    cstStorna($this, $s, $incasso)->assertSessionHasNoErrors();

    // Specchio della compensazione (AVERE «Crediti» 5000 sulla rata 0, DARE «Crediti» 5000 sulla rata 1) e della rettifica (DARE
    // «Crediti» 5000, AVERE «Passate» 5000), più la figlia dell'emissione (AVERE «Crediti» 5000, DARE «Passate» 5000).
    // «Crediti»: 0 + 5000 − 5000 + 5000 − 5000 = 0. «Passate»: 20000 − 5000 + 5000 → −20000. Credito 20000.
    expect(cstConti($s))->toBe([0, 0, -20000, 20000, 0]);
});

it('P4/S6 (guardia) — lo storno di una compensazione presa con la rata 0 già a giornale è solo lo specchio: nessuna figlia dell\'emissione', function () {
    $s = cstScenario();
    cstEmetti($this, $s, [0, 1, 2], '2026-02-28')->assertSessionHasNoErrors();
    // Il credito è già fra i crediti verso condomini: la compensazione preleva da lì (DARE «Crediti» 5000 sulla rata 0, AVERE
    // «Crediti» 5000 sulla rata 1), e da «Passate gestioni» non esce niente.
    $incasso = cstCompensa($this, $s, 5000, 0, 1, '2026-03-10');
    $emissione0 = (int) DB::table('rate_quote')->where('id', cstQuota($s, 0))->value('scrittura_contabile_id');
    // «Crediti»: 20000 (rate 1 e 2) − 20000 (credito della rata 0) + 5000 − 5000 = 0. «Passate» −20000. Credito 15000. Estratto 0.
    expect(cstConti($s))->toBe([0, 0, -20000, 15000, 0]);

    cstStorna($this, $s, $incasso)->assertSessionHasNoErrors();

    // Lo specchio rovescia due righe sullo stesso conto: «Crediti» resta 0, «Passate» −20000, il credito torna 20000. Una figlia
    // dell'emissione qui sarebbe un secondo accredito (la regola presa da «la quota è a giornale» invece che dal netto uscito da
    // «Passate gestioni» nella famiglia): «Crediti» −5000, «Passate» −25000.
    expect(DB::table('scritture_contabili')->where('scrittura_padre_id', $emissione0)->count())->toBe(0)
        ->and(cstConti($s))->toBe([0, 0, -20000, 20000, 0]);
});

it('P4 — lo storno rilegge lo stato della scrittura dentro la transazione: una seconda richiesta partita con la scrittura ancora valida non storna due volte', function () {
    $s = cstScenario(saldoUgo: 0);
    cstEmetti($this, $s, [1], '2026-02-28')->assertSessionHasNoErrors();
    $incasso = cstIncassa($this, $s, [1 => 10000], 0, '2026-03-05');
    // La seconda richiesta ha letto la scrittura (e le sue figlie, come fa StornoIncassoController) prima che la prima finisse:
    // in memoria è ancora «registrata». Il doppio storno concorrente vero non si prova su SQLite; questa è la sua metà
    // deterministica: lo stato va riletto sotto blocco, non preso dal modello che arriva.
    $lettaPrima = ScritturaContabile::with(['figlie.righe', 'figlie.quotePagate', 'righe', 'quotePagate'])->findOrFail($incasso);
    cstStorna($this, $s, $incasso)->assertSessionHasNoErrors();

    try {
        app(\App\Actions\Gestionale\Movimenti\StornoIncassoRateAction::class)->execute($lettaPrima, $s['c']);
    } catch (\Throwable) {
        // Rifiutare va bene quanto non fare niente: conta che niente sia scritto due volte.
    }

    // A mano. Cassa: 10000 + 10000 (incasso) − 10000 (storno) = 10000; quota della rata 1: 10000 − 10000 = 0 pagati; una sola
    // rettifica. Oggi la seconda chiamata scrive un'altra rettifica: cassa 0, quota a −10000 pagati.
    expect(DB::table('scritture_contabili')->where('scrittura_padre_id', $incasso)->where('tipo_movimento', 'rettifica')->count())->toBe(1)
        ->and((int) RataQuote::find(cstQuota($s, 1))->importo_pagato)->toBe(0)
        ->and(app(\App\Services\Gestionale\SaldoCassaService::class)->saldoDisponibile($s['cassa']))->toBe(10000);
});

it('P4 — la stessa seconda richiesta, sullo storno di S1, non scrive una seconda figlia dell\'emissione', function () {
    $s = cstScenario();
    cstEmetti($this, $s, [1, 2], '2026-02-28')->assertSessionHasNoErrors();
    $incasso = cstCompensa($this, $s, 5000, 0, 1, '2026-03-10');
    cstEmetti($this, $s, [0], '2026-03-15')->assertSessionHasNoErrors();
    $emissione0 = (int) DB::table('rate_quote')->where('id', cstQuota($s, 0))->value('scrittura_contabile_id');
    $lettaPrima = ScritturaContabile::with(['figlie.righe', 'figlie.quotePagate', 'righe', 'quotePagate'])->findOrFail($incasso);
    cstStorna($this, $s, $incasso)->assertSessionHasNoErrors();

    try {
        app(\App\Actions\Gestionale\Movimenti\StornoIncassoRateAction::class)->execute($lettaPrima, $s['c']);
    } catch (\Throwable) {
    }

    // Come dopo un solo storno (S1): una figlia dell'emissione della rata 0, due rettifiche (l'incasso e la compensazione), conti
    // [0, 0, −20000, 20000, 0]. Saltare le scritture già annullate una per una non basta: la figlia nasce dopo il ciclo, dalla
    // famiglia, e con una seconda figlia «Crediti» farebbe −5000 e «Passate» −25000.
    expect(DB::table('scritture_contabili')->where('scrittura_padre_id', $emissione0)->count())->toBe(1)
        ->and(DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'rettifica')->count())->toBe(2)
        ->and(cstConti($s))->toBe([0, 0, -20000, 20000, 0]);
});

it('P4 (capolinea) — dopo lo storno di S1 lo stato patrimoniale, l\'estratto conto di Ugo (a video e nel PDF) e il registro dicono gli stessi numeri', function () {
    $s = cstScenario();
    cstEmetti($this, $s, [1, 2], '2026-02-28')->assertSessionHasNoErrors();
    $incasso = cstCompensa($this, $s, 5000, 0, 1, '2026-03-10');
    cstEmetti($this, $s, [0], '2026-03-15')->assertSessionHasNoErrors();
    cstStorna($this, $s, $incasso)->assertSessionHasNoErrors();

    $passateDi = fn (array $pagina) => (int) collect($pagina['situazione']['passivo']['gruppi'])->flatMap(fn ($g) => $g['voci'])->where('ruolo', 'passate_gestioni')->sum('saldo');

    // Lo stato patrimoniale, a video: «Crediti verso condomini» 0 (20000 di rate 1 e 2 contro 20000 di credito a giornale),
    // «Passate gestioni» −20000, e il controllo di partita doppia torna (la quadratura R6 qui non dice niente: la cassa di prova
    // ha un saldo d'apertura non registrato a giornale).
    $pagina = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.stato-patrimoniale.index', [$s['c'], $s['e']]))
        ->assertOk()->viewData('page')['props']['pagina'];
    expect($pagina['sintesi']['crediti_condomini'])->toBe(0)
        ->and($passateDi($pagina))->toBe(-20000)
        ->and(collect($pagina['controlli'])->firstWhere('id', 'PD')['esito'])->toBe('quadra');

    // Lo stesso nel PDF.
    $nelPdf = [];
    View::composer('pdf.gestionale.stato_patrimoniale', function ($view) use (&$nelPdf) {
        $nelPdf = $view->getData();
    });
    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.stato-patrimoniale.print', [$s['c'], $s['e']]))->assertOk();
    expect($nelPdf['pagina']['sintesi']['crediti_condomini'])->toBe(0)
        ->and($passateDi($nelPdf['pagina']))->toBe(-20000);

    // L'estratto conto di Ugo, a video e nel PDF: saldo 0.
    $stats = $this->actingAs($this->user)->get(route('admin.gestionale.anagrafiche.estratto-conto', [$s['c'], $s['v']]))->viewData('page')['props']['stats'];
    $estrattoPdf = [];
    View::composer('pdf.gestionale.estratto_conto_anagrafica', function ($view) use (&$estrattoPdf) {
        $estrattoPdf = $view->getData();
    });
    $this->actingAs($this->user)->get(route('admin.gestionale.anagrafiche.estratto-conto.print', [$s['c'], $s['v']]))->assertOk();
    expect($stats['saldo_raw'])->toBe(0)
        ->and($estrattoPdf['stats']['saldo_raw'])->toBe(0);

    // Il registro, mastrino di «Crediti verso condomini»: chiude a 0; l'incasso e la compensazione stornati sono marcati, le
    // emissioni (la rata 0 e la sua figlia) no: la figlia non è uno storno dell'emissione.
    $crediti = ContoContabile::find(cstContoId($s, 'crediti_condomini'));
    $righe = collect(app(\App\Services\Gestionale\RegistroContabilitaService::class)->mastrino($s['e'], $crediti)['righe']);
    expect($righe->last()['saldo_progressivo'])->toBe(0)
        ->and($righe->where('tipo_movimento', 'emissione_rata')->pluck('stornata')->unique()->values()->all())->toBe([false])
        ->and($righe->whereIn('tipo_movimento', ['incasso_rata', 'storno_credito'])->pluck('stornata')->unique()->values()->all())->toBe([true]);
});

it('P4 — la pagina della scrittura riceve il tipo delle figlie e lo stato del padre, per dire «annullato» solo per uno storno', function () {
    $s = cstScenario(saldoUgo: 0);
    cstEmetti($this, $s, [1, 2], '2026-02-28')->assertSessionHasNoErrors();
    cstIncassa($this, $s, [1 => 10000], 5000, '2026-03-05');
    // Una compensazione valida: l'incasso padre ha una figlia «storno_credito», e non è annullato.
    $incasso = cstCompensa($this, $s, 5000, 1, 2, '2026-03-10');
    $comp = (int) DB::table('scritture_contabili')->where('scrittura_padre_id', $incasso)->value('id');

    $padre = $this->actingAs($this->user)->get(route('admin.gestionale.scritture.show', [$s['c'], $incasso]))->viewData('page')['props']['scrittura'];
    $figlia = $this->actingAs($this->user)->get(route('admin.gestionale.scritture.show', [$s['c'], $comp]))->viewData('page')['props']['scrittura'];

    // I dati che servono a `resources/js/lib/gestionale/scritture/bannerStorno.ts` (vitest): oggi le figlie portano solo l'etichetta
    // e il padre non dice il suo stato, e il riquadro «movimento annullato» compare per ogni figlia (Show.vue:299).
    expect($padre['stato'])->toBe('registrata')
        ->and(collect($padre['figlie'])->pluck('tipo_movimento')->all())->toBe(['storno_credito'])
        ->and($figlia['tipo_movimento'])->toBe('storno_credito')
        ->and($figlia['padre']['stato'])->toBe('registrata')
        ->and($figlia['padre']['tipo_movimento'])->toBe('incasso_rata');
});

// ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// P8 — Coda 209: l'avviso nella conferma dello storno
// ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

it('P8/C209 — la conferma dello storno di un incasso la cui parte in più è già stata usata dice quanto, dove e quanto tornerà da pagare', function () {
    $s = cstScenario(saldoUgo: 0);
    cstEmetti($this, $s, [1, 2], '2026-02-28')->assertSessionHasNoErrors();
    // € 150,00 sulla rata 1 da € 100,00: la parte in più (5000) resta sulla quota della rata 1 come credito.
    $incasso = cstIncassa($this, $s, [1 => 10000], 5000, '2026-03-05');
    // Quel credito paga metà della rata 2.
    $compensazione = cstCompensa($this, $s, 5000, 1, 2, '2026-03-10');
    $prot = (string) DB::table('scritture_contabili')->where('id', $compensazione)->value('numero_protocollo');

    $r = cstAnteprima($this, $s, $incasso)->assertOk();

    // A mano, sulla quota della rata 1: pagato 15000 − 5000 (usati) = 10000; lo storno toglie 15000, restano −5000 pagati: da
    // pagare 10000 + 5000 = 15000. Senza l'uso sarebbero 10000. Già usato: 15000 − 10000 = 5000.
    expect($r->json('voci'))->toBe([['rata' => 1, 'persona' => 'Ugo Venditore', 'importo_rata' => 10000, 'da_pagare_dopo' => 15000, 'gia_usato' => 5000,
        'usi' => [['tipo' => 'compensazione', 'protocollo' => $prot, 'data' => '10/03/2026']]]])
        ->and($r->json('frase'))->toBe("Sulla rata n.1 di Ugo Venditore € 50,00 pagati in più sono già stati usati nell'incasso prot. {$prot} del 10/03/2026: lo storno non annulla quell'uso, e la rata n.1, da € 100,00, tornerà da pagare per € 150,00.");

    // Lo storno fa esattamente quello che la conferma ha detto.
    cstStorna($this, $s, $incasso)->assertSessionHasNoErrors();
    $q1 = RataQuote::find(cstQuota($s, 1));
    expect((int) $q1->importo - (int) $q1->importo_pagato)->toBe(15000);
});

it('P8/SC1 — la parte in più usata viene da un altro incasso: l\'avviso dello storno di un incasso senza parte in più non gliela attribuisce', function () {
    $s = cstScenario(saldoUgo: 0);
    cstEmetti($this, $s, [1, 2], '2026-02-28')->assertSessionHasNoErrors();
    // A paga € 50,00 della rata 1; B paga gli altri € 50,00 più € 50,00 di parte in più, che resta sulla rata 1.
    $a = cstIncassa($this, $s, [1 => 5000], 0, '2026-03-05');
    cstIncassa($this, $s, [1 => 5000], 5000, '2026-03-06');
    $compensazione = cstCompensa($this, $s, 5000, 1, 2, '2026-03-10');
    $prot = (string) DB::table('scritture_contabili')->where('id', $compensazione)->value('numero_protocollo');

    $r = cstAnteprima($this, $s, $a)->assertOk();

    // A mano, sulla quota della rata 1: pagato 5000 + 10000 − 5000 = 10000; lo storno di A toglie 5000: restano 5000, da pagare
    // 5000. Senza l'uso sarebbero pagati 10000, da pagare 0. Già usato 5000 — ma la parte in più era di B, non di A.
    expect($r->json('voci'))->toBe([['rata' => 1, 'persona' => 'Ugo Venditore', 'importo_rata' => 10000, 'da_pagare_dopo' => 5000, 'gia_usato' => 5000,
        'usi' => [['tipo' => 'compensazione', 'protocollo' => $prot, 'data' => '10/03/2026']]]])
        ->and($r->json('frase'))->toBe("Sulla rata n.1 di Ugo Venditore € 50,00 pagati in più sono già stati usati nell'incasso prot. {$prot} del 10/03/2026: lo storno non annulla quell'uso, e la rata n.1, da € 100,00, tornerà da pagare per € 50,00.");

    cstStorna($this, $s, $a)->assertSessionHasNoErrors();
    $q1 = RataQuote::find(cstQuota($s, 1));
    expect((int) $q1->importo - (int) $q1->importo_pagato)->toBe(5000);
});

it('P8/C209 — la parte in più rimborsata: l\'avviso nomina il rimborso, con la data e il protocollo', function () {
    $s = cstScenario(saldoUgo: 0);
    cstEmetti($this, $s, [1, 2], '2026-02-28')->assertSessionHasNoErrors();
    $incasso = cstIncassa($this, $s, [1 => 10000], 5000, '2026-03-05');
    // I € 50,00 in più della rata 1 tornano a Ugo con un bonifico.
    $rimborso = cstRimborsa($this, $s, 5000, 1, '2026-03-12');
    $prot = (string) DB::table('scritture_contabili')->where('id', $rimborso)->value('numero_protocollo');

    $r = cstAnteprima($this, $s, $incasso)->assertOk();

    // Pagato 15000 − 5000 (rimborsati) = 10000; lo storno toglie 15000: da pagare 15000, senza il rimborso 10000. Già usato 5000.
    expect($r->json('voci'))->toBe([['rata' => 1, 'persona' => 'Ugo Venditore', 'importo_rata' => 10000, 'da_pagare_dopo' => 15000, 'gia_usato' => 5000,
        'usi' => [['tipo' => 'rimborso', 'protocollo' => $prot, 'data' => '12/03/2026']]]])
        ->and($r->json('frase'))->toContain('€ 50,00')->toContain('rimborsati il 12/03/2026')->toContain($prot)->toContain('€ 150,00')
        ->and($r->json('frase'))->not->toContain('di questo incasso');
});

it('P8/C209 — senza usi della parte in più la conferma non avvisa', function () {
    $s = cstScenario(saldoUgo: 0);
    cstEmetti($this, $s, [1, 2], '2026-02-28')->assertSessionHasNoErrors();
    $incasso = cstIncassa($this, $s, [1 => 10000], 5000, '2026-03-05');

    $r = cstAnteprima($this, $s, $incasso)->assertOk();

    expect($r->json('voci'))->toBe([])->and($r->json('frase'))->toBeNull();
});

it('P8/C209 — un uso della parte in più poi stornato non è più un uso: la conferma non avvisa', function () {
    $s = cstScenario(saldoUgo: 0);
    cstEmetti($this, $s, [1, 2], '2026-02-28')->assertSessionHasNoErrors();
    $incasso = cstIncassa($this, $s, [1 => 10000], 5000, '2026-03-05');
    // La parte in più paga metà della rata 2; poi quella compensazione si storna, e i € 50,00 tornano sulla rata 1.
    $compensazione = cstCompensa($this, $s, 5000, 1, 2, '2026-03-10');
    cstStorna($this, $s, $compensazione)->assertSessionHasNoErrors();

    $r = cstAnteprima($this, $s, $incasso)->assertOk();

    // A mano, sulla quota della rata 1: pagato 15000 − 5000 (compensazione) + 5000 (il suo storno) = 15000; lo storno
    // dell'incasso toglie 15000: pagato 0, da pagare 10000, quanto la rata. Nessun uso vivo, nessun avviso. Se la
    // compensazione annullata contasse ancora come uso: già usato 5000 e un «tornerà da pagare per € 100,00» che non dice niente.
    expect($r->json('voci'))->toBe([])->and($r->json('frase'))->toBeNull();

    cstStorna($this, $s, $incasso)->assertSessionHasNoErrors();
    $q1 = RataQuote::find(cstQuota($s, 1));
    expect((int) $q1->importo - (int) $q1->importo_pagato)->toBe(10000);
});

it('P8/C209 (lente sicurezza) — l\'anteprima dello storno non risponde per la scrittura di un altro condominio né per una che non è un incasso', function () {
    $s = cstScenario(saldoUgo: 0);
    $altro = cstScenario(saldoUgo: 0);
    cstEmetti($this, $s, [1], '2026-02-28')->assertSessionHasNoErrors();
    $incasso = cstIncassa($this, $s, [1 => 10000], 0, '2026-03-05');
    $emissione = (int) DB::table('rate_quote')->where('id', cstQuota($s, 1))->value('scrittura_contabile_id');

    $diUnAltro = $this->actingAs($this->user)->getJson(route('admin.gestionale.movimenti-rate.storno.anteprima', [$altro['c'], $incasso]));
    $nonIncasso = cstAnteprima($this, $s, $emissione);

    expect($diUnAltro->status())->toBeIn([403, 404])
        ->and($nonIncasso->status())->toBeIn([403, 404])
        ->and($diUnAltro->json('voci'))->toBeNull()
        ->and($nonIncasso->json('voci'))->toBeNull();

    // E senza un utente collegato la rotta non risponde affatto (la lente: le rotte senza auth).
    $this->app['auth']->forgetGuards();
    $ospite = $this->getJson(route('admin.gestionale.movimenti-rate.storno.anteprima', [$s['c'], $incasso]));
    expect($ospite->status())->toBeIn([401, 403])
        ->and($ospite->json('voci'))->toBeNull();
});

it('P8 — l\'elenco degli incassi passa l\'importo in centesimi interi, che la conferma dello storno mostra senza moltiplicare', function () {
    $s = cstScenario(saldoUgo: 0);
    cstEmetti($this, $s, [1], '2026-02-28')->assertSessionHasNoErrors();
    // € 19,99: in JavaScript 19.99 * 100 = 1998.9999999999998 (DataTableRowActions.vue:30).
    $incasso = cstIncassa($this, $s, [1 => 1999], 0, '2026-03-05');

    $servizio = app(\App\Services\Gestionale\IncassoRateService::class);
    $riga = $servizio->getIncassiQuery($s['c'])->get()->map(fn ($m) => $servizio->formatMovimentoForFrontend($m))->firstWhere('id', $incasso);

    expect($riga['importo_totale_cents'] ?? null)->toBe(1999);
});

// ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Mutazioni sopravvissute (fase finale della 1.11.0-beta.49): i test che le prendono
// ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

it('P8/C209 — lo storno di un incasso precedente sulla stessa rata non è un uso della parte in più: la conferma non avvisa', function () {
    $s = cstScenario(saldoUgo: 0);
    cstEmetti($this, $s, [1], '2026-02-28')->assertSessionHasNoErrors();
    // Un primo incasso di € 100,00 sulla rata 1, poi stornato: la sua rettifica (viva) porta −10000 nella pivot della quota.
    $primo = cstIncassa($this, $s, [1 => 10000], 0, '2026-03-01');
    cstStorna($this, $s, $primo)->assertSessionHasNoErrors();
    // Il secondo incasso: € 150,00 sulla rata 1, con € 50,00 di parte in più che nessuno ha usato.
    $secondo = cstIncassa($this, $s, [1 => 10000], 5000, '2026-03-05');

    $r = cstAnteprima($this, $s, $secondo)->assertOk();

    // A mano, sulla quota della rata 1: pagato 10000 − 10000 + 15000 = 15000; lo storno del secondo toglie 15000 → pagato 0, da
    // pagare 10000, quanto la rata. Nessun uso: niente avviso. Se ogni riga negativa della pivot contasse come uso, la rettifica
    // del primo storno direbbe «già usati € 100,00» di una parte in più che non è mai stata spesa.
    expect($r->json('voci'))->toBe([])->and($r->json('frase'))->toBeNull();
});

it('P8/C209 — un incasso che lascia la rata pagata anche dopo il suo storno non avvisa, anche se un\'altra parte in più di quella rata è già stata usata', function () {
    $s = cstScenario(saldoUgo: 0);
    cstEmetti($this, $s, [1, 2], '2026-02-28')->assertSessionHasNoErrors();
    // Un primo incasso di € 20,00 sulla rata 1 da € 100,00; un secondo di € 180,00, che paga gli altri € 80,00 e lascia € 100,00 di
    // parte in più sulla stessa quota (pagato 20000); la compensazione usa € 30,00 di quella parte in più sulla rata 2 (pagato 17000).
    $primo = cstIncassa($this, $s, [1 => 2000], 0, '2026-03-01');
    cstIncassa($this, $s, [1 => 8000], 10000, '2026-03-05');
    cstCompensa($this, $s, 3000, 1, 2, '2026-03-10');
    expect((int) RataQuote::find(cstQuota($s, 1))->importo_pagato)->toBe(17000);

    $r = cstAnteprima($this, $s, $primo)->assertOk();

    // A mano: pagato 17000, lo storno del primo toglie 2000 → 15000, la rata resta pagata; con o senza l'uso dei 3000 da pagare non
    // torna niente. Nessun avviso. Se bastasse «c'è un uso», la conferma direbbe «già usati € 0,00 … tornerà da pagare per € 0,00».
    expect($r->json('voci'))->toBe([])->and($r->json('frase'))->toBeNull();
});

it('P8/C209 — l\'uso che lo storno stesso annulla non si avvisa: stornato prima l\'incasso con la parte in più, lo storno della compensazione che l\'aveva usata non avvisa', function () {
    $s = cstScenario(saldoUgo: 0);
    cstEmetti($this, $s, [1, 2], '2026-02-28')->assertSessionHasNoErrors();
    // € 200,00 sulla rata 1 da € 100,00 (parte in più 10000); la compensazione usa 5000 di quella parte in più sulla rata 2; poi
    // l'incasso di € 200,00 si storna (la quota della rata 1: 20000 − 5000 − 20000 = −5000 pagati).
    $primo = cstIncassa($this, $s, [1 => 10000], 10000, '2026-03-05');
    $compensazione = cstCompensa($this, $s, 5000, 1, 2, '2026-03-10');
    cstStorna($this, $s, $primo)->assertSessionHasNoErrors();
    expect((int) RataQuote::find(cstQuota($s, 1))->importo_pagato)->toBe(-5000);

    $r = cstAnteprima($this, $s, $compensazione)->assertOk();

    // A mano. Lo storno della compensazione rimette i 5000 sulla quota della rata 1 (−5000 + 5000 = 0, da pagare 10000) e li toglie
    // dalla rata 2 (5000 − 5000 = 0, da pagare 10000): annulla il suo stesso uso, e nessun altro ha usato niente. Nessun avviso.
    // Contando come uso anche la compensazione che si sta stornando: «già usati € 50,00» sulla rata 1, falso.
    expect($r->json('voci'))->toBe([])->and($r->json('frase'))->toBeNull();

    cstStorna($this, $s, $compensazione)->assertSessionHasNoErrors();
    expect([(int) RataQuote::find(cstQuota($s, 1))->importo_pagato, (int) RataQuote::find(cstQuota($s, 2))->importo_pagato])->toBe([0, 0]);
});
