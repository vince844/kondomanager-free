<?php

/**
 * 1.11.0-beta.42 — lo stato del piano dopo un passaggio: un criterio solo per dire che un piano non si riscrive più.
 *
 * Il ricalcolo si rifiuta per una quota a giornale o per un movimento su una quota (`importo_pagato ≠ 0`), cioè
 * `PianoRate::eImmutabile()`. Il conguaglio guardava invece lo stato «emessa» della rata, che l'emissione scriveva anche
 * su una rata senza scritture (fatta solo di quote a credito) e su qualunque rata arrivasse nella richiesta. La regola che
 * tiene insieme le due strade è la decisione 21: ogni quota la sistema uno solo dei due, il ricalcolo o il conguaglio.
 * Con due criteri diversi capitava che la sistemassero entrambi — chi compra pagava due volte i suoi giorni — o nessuno.
 *
 * Coperti: la rata di sole quote a credito che non diventa «emessa» e il messaggio che lo dice; la vendita dopo, con il
 * piano che si ricalcola per giorni (una rata e tutte le rate a credito); le rate già «emesse» senza scrittura dalle
 * versioni prima; il piano con un incasso su una rata in bozza, che il ricalcolo rifiuta e il conguaglio ora prende;
 * l'emissione che considera solo le rate del piano.
 *
 * Cosa NON copre: come va a giornale una quota a credito (beta della contabilità dei crediti); i promemoria del portale di
 * una rata che resta in bozza; il piano emesso senza ricalcolo dopo un passaggio (decisione 35, più sotto in questo file
 * quando c'è).
 */

use App\Actions\Gestionale\Movimenti\StoreIncassoRateAction;
use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Models\Tabella;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

/**
 * Un'unità di Ugo, preventivo ordinario da € 1.200,00 in rate mensili dal 5 gennaio, con il saldo pregresso di Ugo
 * distribuito col metodo dato; i conti per emettere e incassare. Il piano è generato a gennaio, approvato.
 *
 * @return array{c: Condominio, e: Esercizio, g: Gestione, unita: Immobile, v: Anagrafica, a: Anagrafica, rigaV: int, piano: PianoRate, cassa: Cassa}
 */
function spScenario(int $numeroRate, string $metodo, int $saldoUgo, array $ripartizione = ['proprietario' => 100]): array
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
    foreach ($ripartizione as $soggetto => $percentuale) {
        DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => $soggetto, 'percentuale' => $percentuale, 'created_at' => now(), 'updated_at' => now()]);
    }
    $unita = Immobile::create(['condominio_id' => $c->id, 'tipo' => 'appartamento', 'codice_immobile' => "SP-{$seq}", 'nome' => 'Interno 1', 'interno' => '1']);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $unita->id, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);

    $banca = ContoContabile::create(['condominio_id' => $c->id, 'codice' => '10.10', 'nome' => 'Banca', 'tipo' => 'attivo', 'ruolo' => 'banca', 'categoria' => 'liquidita']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '10.20', 'nome' => 'Crediti verso condomini', 'tipo' => 'attivo', 'ruolo' => 'crediti_condomini', 'categoria' => 'crediti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '20.10', 'nome' => 'Anticipi', 'tipo' => 'passivo', 'ruolo' => 'anticipi_condomini', 'categoria' => 'debiti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '20.20', 'nome' => 'Gestione rate', 'tipo' => 'passivo', 'ruolo' => 'gestione_rate', 'categoria' => 'debiti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '30.10', 'nome' => 'Passate gestioni', 'tipo' => 'passivo', 'ruolo' => 'passate_gestioni', 'categoria' => 'debiti']);
    $cassa = Cassa::create(['condominio_id' => $c->id, 'conto_contabile_id' => $banca->id, 'nome' => 'Banca principale', 'tipo' => 'banca', 'attiva' => true, 'saldo_iniziale' => 0]);

    $v = Anagrafica::forceCreate(['nome' => 'Ugo Venditore', 'email' => "sp-v{$seq}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'SPVENDITORE' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT)]);
    $a = Anagrafica::forceCreate(['nome' => 'Elsa Acquirente', 'email' => "sp-a{$seq}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'SPACQUIRENT' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT)]);
    $v->condomini()->syncWithoutDetaching([$c->id]);
    $a->condomini()->syncWithoutDetaching([$c->id]);
    $rigaV = DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $v->id, 'immobile_id' => $unita->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);

    if (isset($ripartizione['inquilino'])) {
        $i = Anagrafica::forceCreate(['nome' => 'Ines Inquilina', 'email' => "sp-i{$seq}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'SPINQUILINA' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT)]);
        $i->condomini()->syncWithoutDetaching([$c->id]);
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $i->id, 'immobile_id' => $unita->id, 'tipologia' => 'inquilino', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2025-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    }

    if ($saldoUgo !== 0) {
        Saldo::create(['esercizio_id' => $e->id, 'condominio_id' => $c->id, 'anagrafica_id' => $v->id, 'immobile_id' => $unita->id, 'gestione_id' => $g->id, 'saldo_iniziale' => $saldoUgo, 'origine' => 'manuale', 'is_applicato' => false]);
    }

    $piano = PianoRate::create([
        'gestione_id' => $g->id, 'condominio_id' => $c->id, 'esercizio_id' => $e->id, 'nome' => 'Preventivo 2026', 'stato' => 'approvato', 'tipo' => 'ordinario',
        'numero_rate' => $numeroRate, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'metodo_distribuzione' => $metodo, 'applica_saldi' => true,
    ]);
    app(GeneratePianoRateAction::class)->execute($piano, forzaApplicazioneSaldi: true, esercizio: $e);

    return compact('c', 'e', 'g', 'unita', 'v', 'a', 'rigaV', 'piano', 'cassa');
}

function spRata(array $s, int $numero): int
{
    return (int) DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('numero_rata', $numero)->value('id');
}

/** L'emissione vera, dalla rotta della pagina del piano. */
function spEmetti($test, array $s, array $rateIds, string $data = '2026-01-05')
{
    return $test->actingAs($test->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $s['piano']]), [
        'rate_ids' => $rateIds, 'data_emissione' => $data, 'invia_notifiche' => false,
    ]);
}

function spVendita(array $s, string $decorrenza): array
{
    return [
        'tipo' => 'vendita', 'riga_uscente_id' => $s['rigaV'], 'anagrafica_entrante_id' => $s['a']->id, 'decorrenza' => $decorrenza,
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => $decorrenza,
        'estremi_titolo' => 'atto notaio Verdi, rep. 12345', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Rogito letto, piano da ricalcolare',
    ];
}

function spRicalcola($test, array $s)
{
    return $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $s['piano']]), [
        'accetta_destinatari' => true, 'nota_destinatari' => 'Vendita registrata, quote da dividere per giorni',
    ]);
}

/** Per persona: [preventivo nelle quote, pregresso nelle quote, coppia del conguaglio in saldi]. */
function spConti(array $s): array
{
    $quote = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s['piano']->id)
        ->get(['rate_quote.anagrafica_id', 'rate_quote.regole_calcolo']);
    $importi = fn ($q) => json_decode((string) $q->regole_calcolo, true)['importi'] ?? [];
    $esito = [];
    foreach (['ugo' => $s['v'], 'elsa' => $s['a']] as $nome => $p) {
        $sue = $quote->where('anagrafica_id', $p->id);
        $esito[$nome] = [
            (int) $sue->sum(fn ($q) => (int) ($importi($q)['quota_pura_gestione'] ?? 0)),
            (int) $sue->sum(fn ($q) => (int) ($importi($q)['saldo_usato'] ?? 0)),
            (int) Saldo::whereNotNull('subentro_id')->where('anagrafica_id', $p->id)->sum('saldo_iniziale'),
        ];
    }

    return $esito;
}

it('DC5 — la rata 1 fatta solo di quote a credito non diventa «emessa» e il messaggio lo dice; dopo la vendita il piano si ricalcola, e chi compra paga i suoi giorni una volta sola', function () {
    $s = spScenario(6, 'prima_rata', -67100);
    $rata1 = spRata($s, 1);
    // € 200,00 di preventivo meno € 671,00 di pregresso a credito: −€ 471,00, nessuna quota da pagare.
    expect((int) DB::table('rate_quote')->where('rata_id', $rata1)->sum('importo'))->toBe(-47100);

    $r = spEmetti($this, $s, [$rata1], '2026-01-13');
    $r->assertSessionHasNoErrors();
    expect(DB::table('rate')->where('id', $rata1)->value('stato'))->toBe('bozza')
        ->and(DB::table('rate_quote')->where('rata_id', $rata1)->whereNotNull('scrittura_contabile_id')->exists())->toBeFalse()
        ->and(DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'emissione_rata')->exists())->toBeFalse()
        ->and($r->getSession()->get('esito_emissione'))->toBe([
            'titolo' => 'Nessuna rata emessa',
            'testo' => 'La rata 1 non è stata emessa: non ha quote da pagare, e a giornale non c\'è niente da portare. Resta in bozza.',
        ]);

    // L'anteprima della vendita non elenca più la rata come emessa: dice soltanto di ricalcolare il piano.
    $anteprima = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), spVendita($s, '2026-02-07'))->assertOk()->json();
    expect(json_encode($anteprima, JSON_UNESCAPED_UNICODE))->not->toContain('già emessa');

    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), spVendita($s, '2026-02-07'))->assertSessionHasNoErrors()->assertRedirect();
    // Niente a giornale e nessun movimento: il conguaglio non tocca il piano, lo sistema il ricalcolo.
    expect(Saldo::whereNotNull('subentro_id')->count())->toBe(0);

    spRicalcola($this, $s)->assertSessionHasNoErrors()->assertRedirect();
    // Ugo dal 1/1 al 6/2, 37 giorni: 120000 × 37/365 = 12164,38 → 12164; Elsa 328 giorni: 107836. Il pregresso resta a Ugo.
    // Prima: la coppia sui giorni di Elsa della rata 1 (20000 × 328/365 = 17973) restava e si sommava al riparto per giorni.
    expect(spConti($s))->toBe(['ugo' => [12164, -67100, 0], 'elsa' => [107836, 0, 0]]);
});

it('DC5, dati delle versioni prima — una rata «emessa» senza scrittura non conta come emessa: nessun conguaglio, e il ricalcolo divide per giorni', function () {
    $s = spScenario(6, 'prima_rata', -67100);
    // Come la lasciava l'emissione fino alla beta.41: «emessa», senza nessuna quota a giornale.
    DB::table('rate')->where('id', spRata($s, 1))->update(['stato' => 'emessa', 'data_emissione' => '2026-01-13']);

    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), spVendita($s, '2026-02-07'))->assertSessionHasNoErrors()->assertRedirect();
    expect(Saldo::whereNotNull('subentro_id')->count())->toBe(0);

    spRicalcola($this, $s)->assertSessionHasNoErrors()->assertRedirect();
    expect(spConti($s))->toBe(['ugo' => [12164, -67100, 0], 'elsa' => [107836, 0, 0]]);
});

it('DV2 — tutte le quote a credito: l\'emissione fino al 30/4 non marca nessuna rata, la vendita del 1/5 non scrive coppie, e il ricalcolo dà a Elsa i suoi 245 giorni', function () {
    // Dodici rate da € 100,00 di preventivo e −€ 125,00 di pregresso ciascuna: −€ 25,00, tutte a credito.
    $s = spScenario(12, 'tutte_rate', -150000);
    $finoAdAprile = DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('data_scadenza', '<=', '2026-04-30 23:59:59')->pluck('id')->all();
    expect($finoAdAprile)->toHaveCount(4);

    $r = spEmetti($this, $s, $finoAdAprile);
    $r->assertSessionHasNoErrors();
    expect(DB::table('rate')->whereIn('id', $finoAdAprile)->where('stato', 'emessa')->count())->toBe(0)
        ->and($r->getSession()->get('esito_emissione')['testo'])->toBe('Le rate 1, 2, 3 e 4 non sono state emesse: non hanno quote da pagare, e a giornale non c\'è niente da portare. Restano in bozza.');

    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), spVendita($s, '2026-05-01'))->assertSessionHasNoErrors()->assertRedirect();
    // Prima: una coppia di 26849 (40000 × 245/365) sulle quattro rate «emesse», poi sommata al riparto per giorni.
    expect(Saldo::whereNotNull('subentro_id')->count())->toBe(0);

    spRicalcola($this, $s)->assertSessionHasNoErrors()->assertRedirect();
    // Ugo 1/1–30/4, 120 giorni: 120000 × 120/365 = 39452,05 → 39452; Elsa 245 giorni: 80548. Il pregresso resta a Ugo.
    expect(spConti($s))->toBe(['ugo' => [39452, -150000, 0], 'elsa' => [80548, 0, 0]]);
});

it('decisione 34.1 — un incasso su una rata in bozza blocca il ricalcolo, e il conguaglio prende il piano: le bozze da maggio passano a Elsa e la coppia è € 5,48', function () {
    $s = spScenario(12, 'prima_rata', 0);
    $quota1 = (int) DB::table('rate_quote')->where('rata_id', spRata($s, 1))->value('id');
    // Ugo paga la rata 1 prima che il piano sia emesso: nessuna scrittura di emissione, ma un movimento sulla quota.
    app(StoreIncassoRateAction::class)->execute([
        'pagante_id' => $s['v']->id, 'cassa_id' => $s['cassa']->id, 'gestione_id' => $s['g']->id,
        'data_pagamento' => '2026-01-10', 'importo_totale' => 100.00, 'descrizione' => 'Rata 1',
        'dettaglio_pagamenti' => [['rata_id' => $quota1, 'importo' => 100.00]],
    ], $s['c'], $s['e']);
    expect($s['piano']->fresh()->haRateEmesse())->toBeFalse()->and($s['piano']->fresh()->eImmutabile())->toBeTrue();

    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), spVendita($s, '2026-05-01'))->assertSessionHasNoErrors()->assertRedirect();

    // Le otto bozze dal 5 maggio passano a Elsa (€ 800,00); a Ugo restano le quattro di prima. La coppia: la parte di Elsa
    // sull'intero piano (120000 × 245/365 = 80547,95 → 80548) meno le bozze che già paga (80000) = 548.
    // Prima: il piano non entrava nel conguaglio (niente a giornale), il cancello chiedeva un ricalcolo che si rifiuta per
    // l'incasso, e i giorni di Elsa restavano pagati da Ugo.
    expect(spConti($s))->toBe(['ugo' => [40000, 0, -548], 'elsa' => [80000, 0, 548]]);
});

it('emissione mista — la rata con quote da pagare va a giornale e diventa «emessa», quella di sole quote a credito resta in bozza, e il messaggio le dice tutte e due', function () {
    $s = spScenario(6, 'prima_rata', -67100);
    $r = spEmetti($this, $s, [spRata($s, 1), spRata($s, 2)]);
    $r->assertSessionHasNoErrors();
    expect(DB::table('rate')->where('piano_rate_id', $s['piano']->id)->whereIn('numero_rata', [1, 2])->orderBy('numero_rata')->pluck('stato')->all())->toBe(['bozza', 'emessa'])
        ->and($r->getSession()->get('esito_emissione'))->toBe([
            'titolo' => 'Emissione completata',
            'testo' => 'È stata emessa 1 rata. La rata 1 non è stata emessa: non ha quote da pagare, e a giornale non c\'è niente da portare. Resta in bozza.',
        ]);
});

it('emissione — tutte le rate con quote da pagare: il messaggio conta le rate emesse davvero', function () {
    $s = spScenario(6, 'prima_rata', 0);
    $r = spEmetti($this, $s, [spRata($s, 1), spRata($s, 2), spRata($s, 3)]);
    $r->assertSessionHasNoErrors();
    expect($r->getSession()->get('esito_emissione'))->toBe(['titolo' => 'Emissione completata', 'testo' => 'Sono state emesse 3 rate.']);
});

it('D-3 — l\'emissione considera solo le rate del piano: una rata di un altro condominio nella richiesta la fa rifiutare, e quella rata resta in bozza', function () {
    $s = spScenario(6, 'prima_rata', 0);
    $altro = spScenario(6, 'prima_rata', 0);
    $estranea = spRata($altro, 1);

    $r = spEmetti($this, $s, [spRata($s, 1), $estranea]);
    $r->assertSessionHasErrors('rate_ids.1');
    expect(DB::table('rate')->where('id', $estranea)->value('stato'))->toBe('bozza')
        ->and(DB::table('rate')->where('id', spRata($s, 1))->value('stato'))->toBe('bozza');
});

/*
|--------------------------------------------------------------------------
| U1, decisione 35 — un piano con quote rimaste a chi è uscito non si emette: prima si ricalcola
|--------------------------------------------------------------------------
*/

/** La riga di titolarità in vigore di una persona sull'unità: chi vende nel secondo passaggio. */
function spRigaDi(array $s, Anagrafica $persona): int
{
    return (int) DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('anagrafica_id', $persona->id)->whereNull('data_fine')->value('id');
}

it('U1 — dopo una vendita con il piano ancora da ricalcolare, l\'emissione si rifiuta e dice di ricalcolare; dopo il ricalcolo si emette', function () {
    $s = spScenario(12, 'prima_rata', 0);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), spVendita($s, '2026-02-01'))->assertSessionHasNoErrors()->assertRedirect();

    $r = spEmetti($this, $s, [spRata($s, 1), spRata($s, 2)], '2026-02-10');
    // Rilievo R7 della Fase 1-bis: la frase dice il fatto e cosa fare, senza promettere che il ricalcolo cambi le cifre. Verifica a
    // video: il passaggio in elenco, su una riga sua, e il resto in un capoverso.
    expect($r->getSession()->get('message'))->toBe(['type' => 'error', 'message' => "Le quote di questo piano sono state calcolate prima di questo passaggio:\n• Ugo Venditore → Elsa Acquirente, Interno 1, dal 1 febbraio 2026\n\nRicalcola il piano prima di emettere, perché tenga conto del passaggio. Se le voci o la data della delibera lasciano quelle quote a Ugo Venditore, il ricalcolo dà le stesse cifre."])
        ->and(DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('stato', 'emessa')->count())->toBe(0)
        ->and(DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->exists())->toBeFalse();

    spRicalcola($this, $s)->assertSessionHasNoErrors()->assertRedirect();
    spEmetti($this, $s, [spRata($s, 1), spRata($s, 2)], '2026-02-10')->assertSessionHasNoErrors();
    expect(DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('stato', 'emessa')->count())->toBe(2);
});

it('U1, dati delle versioni prima — piano emesso senza ricalcolo dopo una vendita, poi una seconda vendita: il conguaglio si ferma sulle quote di Ugo e lo dice, nessuna coppia', function () {
    $s = spScenario(12, 'prima_rata', 0);
    $zeta = Anagrafica::forceCreate(['nome' => 'Zeta Seconda', 'email' => 'sp-zeta@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'SPZETASECONDA001']);
    $zeta->condomini()->syncWithoutDetaching([$s['c']->id]);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), spVendita($s, '2026-02-01'))->assertSessionHasNoErrors()->assertRedirect();

    // Come poteva succedere fino alla beta.41: le rate di gennaio e febbraio emesse senza ricalcolare, un giorno dopo.
    $this->travel(1)->days();
    $rate = [spRata($s, 1), spRata($s, 2)];
    DB::table('rate')->whereIn('id', $rate)->update(['stato' => 'emessa', 'data_emissione' => '2026-02-10']);
    aGiornaleNeiTest((int) $s['piano']->id, $rate);
    $this->travel(1)->days();

    $seconda = ['riga_uscente_id' => spRigaDi($s, $s['a']), 'anagrafica_entrante_id' => $zeta->id, 'decorrenza' => '2026-03-01', 'copia_autentica_il' => '2026-03-01'] + spVendita($s, '2026-03-01');
    $anteprima = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), $seconda)->assertOk()->json();
    expect(collect($anteprima['rate']['conguaglio']['non_risolte'] ?? [])->pluck('motivo')->implode(' | '))
        ->toContain('le quote intestate a Ugo Venditore su Interno 1 non sono passate con il suo passaggio del 1 febbraio 2026');
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $seconda)->assertSessionHasNoErrors()->assertRedirect();

    // Le dodici quote sono di Ugo e lo restano: la prima vendita non le ha conguagliate (il piano si ricalcolava) e il piano
    // è stato emesso senza ricalcolarlo. Prima: Elsa riceveva un credito di 120000 × 306/365 = 100603 su quote mai sue, e
    // Zeta un debito uguale.
    expect(Saldo::whereNotNull('subentro_id')->count())->toBe(0)
        ->and((int) DB::table('rate_quote')->where('anagrafica_id', $s['v']->id)->sum('importo'))->toBe(120000);
});

it('U1, dati delle versioni prima, forma dell\'usufrutto — costituzione con il piano ancora da ricalcolare, due rate emesse senza ricalcolo, estinzione: nessun credito all\'usufruttuario su quote mai sue', function () {
    $s = spScenario(12, 'prima_rata', 0);
    // Ugo costituisce l'usufrutto a Elsa il 1/2, con la legge: il piano si ricalcola ancora, la costituzione non lo conguaglia.
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), [
        'tipo' => 'usufrutto', 'sottotipo' => 'costituzione', 'ordinaria_dopo_atto' => 'usufruttuario', 'riga_uscente_id' => $s['rigaV'], 'anagrafica_entrante_id' => $s['a']->id,
        'decorrenza' => '2026-02-01', 'quota' => 100, 'tipologia' => 'usufruttuario', 'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Atto di costituzione letto',
    ])->assertSessionHasNoErrors()->assertRedirect();

    $this->travel(1)->days();
    $rate = [spRata($s, 1), spRata($s, 2)];
    DB::table('rate')->whereIn('id', $rate)->update(['stato' => 'emessa', 'data_emissione' => '2026-02-10']);
    aGiornaleNeiTest((int) $s['piano']->id, $rate);
    $this->travel(1)->days();

    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), [
        'tipo' => 'usufrutto', 'sottotipo' => 'estinzione', 'riga_uscente_id' => spRigaDi($s, $s['a']), 'decorrenza' => '2026-03-01',
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Estinzione per rinuncia letta',
    ])->assertSessionHasNoErrors()->assertRedirect();

    // Le dodici quote sono di Ugo, ed Elsa non ne ha mai avuta una. Prima: Elsa −100603 (120000 × 306/365), Ugo +100603.
    expect(Saldo::whereNotNull('subentro_id')->count())->toBe(0)
        ->and((int) DB::table('rate_quote')->where('anagrafica_id', $s['v']->id)->sum('importo'))->toBe(120000);
});

it('D1 — fine locazione senza un nuovo inquilino, piano già a giornale: il cancello non promette un conguaglio che non c\'è, e dice che le bozze di Ines restano sue senza conguaglio', function () {
    $s = spScenario(12, 'prima_rata', 0, ['inquilino' => 30, 'proprietario' => 70]);
    $finoAGiugno = DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('data_scadenza', '<=', '2026-06-30 23:59:59')->pluck('id')->all();
    spEmetti($this, $s, $finoAGiugno)->assertSessionHasNoErrors();
    $ines = Anagrafica::where('nome', 'Ines Inquilina')->sole();
    $riga = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $ines->id)->value('id');

    $anteprima = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), [
        'tipo' => 'fine_locazione', 'tipologia' => 'inquilino', 'riga_uscente_id' => $riga, 'decorrenza' => '2026-07-01', 'quota' => 100, 'copia_autentica' => false, 'pertinenze' => [],
    ])->assertOk()->json();

    $motivi = implode(' | ', $anteprima['cancello']['motivi']);
    // Prima: «… non si può più ricalcolare, restano sue e sono comprese nel conguaglio», con un conguaglio che non si calcola.
    expect($anteprima['rate']['conguaglio']['stato'] ?? 'nessuno')->toBe('nessuno')
        ->and($motivi)->toContain('il piano «Preventivo 2026» ha 6 quote non ancora emesse intestate a Ines Inquilina: non si può più ricalcolare, restano sue, senza conguaglio')
        ->not->toContain('nel conguaglio');
});

/*
|--------------------------------------------------------------------------
| Fase 1-bis della .42: i rilievi confermati dagli scettici (decisioni 38–41)
|--------------------------------------------------------------------------
*/

/** Un incasso di Ugo sulla quota di una rata, come lo registra il modulo incassi. */
function spIncassa(array $s, int $numeroRata, float $euro, string $data = '2026-01-10'): void
{
    $quota = (int) DB::table('rate_quote')->where('rata_id', spRata($s, $numeroRata))->where('anagrafica_id', $s['v']->id)->value('id');
    app(StoreIncassoRateAction::class)->execute([
        'pagante_id' => $s['v']->id, 'cassa_id' => $s['cassa']->id, 'gestione_id' => $s['g']->id,
        'data_pagamento' => $data, 'importo_totale' => $euro, 'descrizione' => 'Rata ' . $numeroRata,
        'dettaglio_pagamenti' => [['rata_id' => $quota, 'importo' => $euro]],
    ], $s['c'], $s['e']);
}

function spStornaIncassi(array $s): void
{
    foreach (\App\Models\Gestionale\ScritturaContabile::where('condominio_id', $s['c']->id)->where('tipo_movimento', 'incasso_rata')->get() as $incasso) {
        app(\App\Actions\Gestionale\Movimenti\StornoIncassoRateAction::class)->execute($incasso, $s['c']);
    }
}

it('decisione 41 (R1) — dopo una vendita con il piano da ricalcolare, l\'incasso su una sua quota si rifiuta e dice di ricalcolare prima; dopo il ricalcolo passa', function () {
    $s = spScenario(12, 'prima_rata', 0);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), spVendita($s, '2026-02-01'))->assertSessionHasNoErrors()->assertRedirect();

    expect(fn () => spIncassa($s, 1, 100.00))->toThrow(\App\Exceptions\Gestionale\PianoDaRicalcolareException::class,
        'Ricalcola prima il piano «Preventivo 2026»: le sue quote sono state calcolate prima del passaggio di Ugo Venditore su Interno 1, dal 1 febbraio 2026, e un incasso su una sua quota lo fermerebbe prima che ne tenga conto. Dopo il ricalcolo registra l\'incasso.');
    expect(DB::table('quota_scrittura')->count())->toBe(0);

    spRicalcola($this, $s)->assertSessionHasNoErrors()->assertRedirect();
    // Ugo dal 1/1 al 31/1, 31 giorni: 120000 × 31/365 = 10191,78 → 10192; Elsa 334 giorni: 109808.
    expect(spConti($s))->toBe(['ugo' => [10192, 0, 0], 'elsa' => [109808, 0, 0]]);
    spIncassa($s, 1, 8.50);
    expect(DB::table('quota_scrittura')->count())->toBe(1);
});

it('decisione 41 (R1), dati di prima — un incasso arrivato dopo il passaggio, senza emissioni: l\'emissione si rifiuta e dice di annullarlo, ricalcolare e registrarlo di nuovo', function () {
    $s = spScenario(12, 'prima_rata', 0);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), spVendita($s, '2026-02-01'))->assertSessionHasNoErrors()->assertRedirect();
    // Come poteva succedere fino alla beta.41, un giorno dopo il passaggio: la quota della rata 1 pagata.
    $this->travel(1)->days();
    $quota = (int) DB::table('rate_quote')->where('rata_id', spRata($s, 1))->value('id');
    $incasso = DB::table('scritture_contabili')->insertGetId(['condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'gestione_id' => $s['g']->id, 'data_registrazione' => now(), 'data_competenza' => now(),
        'numero_protocollo' => 'TEST-INC-1', 'causale' => 'Incasso', 'tipo_movimento' => 'incasso_rata', 'stato' => 'registrata', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('quota_scrittura')->insert(['rate_quota_id' => $quota, 'scrittura_contabile_id' => $incasso, 'importo_pagato' => 10000, 'data_pagamento' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('rate_quote')->where('id', $quota)->update(['importo_pagato' => 10000, 'stato' => 'pagata']);
    $this->travel(1)->days();

    // Prima: il piano era fermo per l'incasso, la guardia guardava lo stato di adesso e l'emissione passava; i giorni di Elsa
    // (109808) restavano a Ugo.
    $r = spEmetti($this, $s, [spRata($s, 2), spRata($s, 3)], '2026-02-10');
    expect($r->getSession()->get('message')['type'])->toBe('error')
        ->and($r->getSession()->get('message')['message'])->toContain("Oggi il ricalcolo si rifiuta perché il piano ha un incasso su una sua quota. Per procedere:\n1. Annulla quel movimento.\n2. Ricalcola il piano.\n3. Registra di nuovo quel movimento.")
        ->and(DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('stato', 'emessa')->count())->toBe(0);
});

it('decisione 38 (R2) — incasso su una bozza, vendita con conguaglio, storno dell\'incasso: il piano resta fermo, il ricalcolo e l\'eliminazione si rifiutano, si emette così com\'è', function () {
    $s = spScenario(12, 'prima_rata', 0);
    spIncassa($s, 1, 100.00);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), spVendita($s, '2026-05-01'))->assertSessionHasNoErrors()->assertRedirect();
    expect(spConti($s))->toBe(['ugo' => [40000, 0, -548], 'elsa' => [80000, 0, 548]]);

    $this->travel(1)->days();
    spStornaIncassi($s);
    $piano = $s['piano']->fresh();
    expect($piano->haIncassiRegistrati())->toBeFalse()->and($piano->haRateEmesse())->toBeFalse()->and($piano->conguagliato())->toBeTrue()->and($piano->eImmutabile())->toBeTrue();

    // Prima: il ricalcolo riusciva, e Elsa pagava 80548 nel riparto più la coppia 548 (81096).
    $r = spRicalcola($this, $s);
    expect($r->getSession()->get('message')['message'])->toStartWith('Un passaggio di titolarità ha preso questo piano nel conguaglio: Ugo Venditore → Elsa Acquirente, Interno 1, dal 1 maggio 2026. Ricalcolarlo rifarebbe per giorni ciò che quel conguaglio ha già regolato.')
        ->toContain('annulla quel passaggio dallo storico della sua unità');
    $this->actingAs($this->user)->delete(route('admin.gestionale.esercizi.piani-rate.destroy', [$s['c'], $s['e'], $s['piano']]));
    expect(PianoRate::whereKey($s['piano']->id)->exists())->toBeTrue()
        ->and(spConti($s))->toBe(['ugo' => [40000, 0, -548], 'elsa' => [80000, 0, 548]]);

    // Le cifre sono già giuste (Ugo 40000 − 548 = 39452, Elsa 80000 + 548 = 80548): si emette così com'è.
    spEmetti($this, $s, [spRata($s, 1), spRata($s, 2)])->assertSessionHasNoErrors();
    expect(DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('stato', 'emessa')->count())->toBe(2);
});

it('decisione 39 (R3) — un incasso stornato prima del passaggio non ferma il piano: il passaggio lo lascia al ricalcolo, e al passaggio dopo il conguaglio si ferma', function () {
    $s = spScenario(12, 'prima_rata', 0);
    $zeta = Anagrafica::forceCreate(['nome' => 'Zeta Seconda', 'email' => 'sp-zeta3@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'SPZETASECONDA003']);
    $zeta->condomini()->syncWithoutDetaching([$s['c']->id]);
    spIncassa($s, 1, 100.00);
    spStornaIncassi($s);
    $this->travel(1)->days();
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), spVendita($s, '2026-02-01'))->assertSessionHasNoErrors()->assertRedirect();
    expect(Saldo::whereNotNull('subentro_id')->count())->toBe(0);

    // Come la .41: le rate 1–2 emesse senza ricalcolo, un giorno dopo.
    $this->travel(1)->days();
    $rate = [spRata($s, 1), spRata($s, 2)];
    DB::table('rate')->whereIn('id', $rate)->update(['stato' => 'emessa', 'data_emissione' => '2026-02-10']);
    aGiornaleNeiTest((int) $s['piano']->id, $rate);
    $this->travel(1)->days();

    $seconda = ['riga_uscente_id' => spRigaDi($s, $s['a']), 'anagrafica_entrante_id' => $zeta->id, 'decorrenza' => '2026-03-01', 'copia_autentica_il' => '2026-03-01'] + spVendita($s, '2026-03-01');
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $seconda)->assertSessionHasNoErrors()->assertRedirect();
    // Prima: la pivot letta riga per riga diceva «fermo» per l'incasso stornato, e Elsa riceveva 100603 su quote mai sue.
    expect(Saldo::whereNotNull('subentro_id')->count())->toBe(0);
});

it('decisione 39 (R3) — un passaggio registrato con la regola della .41 non prendeva un piano fermo solo per un incasso: al passaggio dopo il conguaglio si ferma', function () {
    $s = spScenario(12, 'prima_rata', 0);
    $zeta = Anagrafica::forceCreate(['nome' => 'Zeta Seconda', 'email' => 'sp-zeta4@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'SPZETASECONDA004']);
    $zeta->condomini()->syncWithoutDetaching([$s['c']->id]);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), spVendita($s, '2026-02-01'))->assertSessionHasNoErrors()->assertRedirect();
    $p1 = \App\Models\Gestionale\Subentro::latest('id')->firstOrFail();
    // La vendita come l'avrebbe registrata la .41 (versione 1, nessuna coppia), con un incasso di Ugo di prima: un credito della
    // rata 0 usato, per esempio. Allora il conguaglio non prendeva un piano fermo solo per un movimento.
    $p1->update(['registro' => array_replace($p1->registro, ['versione' => 1])]);
    $quota = (int) DB::table('rate_quote')->where('rata_id', spRata($s, 1))->value('id');
    $incasso = DB::table('scritture_contabili')->insertGetId(['condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'gestione_id' => $s['g']->id, 'data_registrazione' => now(), 'data_competenza' => now(),
        'numero_protocollo' => 'TEST-INC-X', 'causale' => 'Incasso', 'tipo_movimento' => 'incasso_rata', 'stato' => 'registrata', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
    DB::table('quota_scrittura')->insert(['rate_quota_id' => $quota, 'scrittura_contabile_id' => $incasso, 'importo_pagato' => 10000, 'data_pagamento' => now()->subDay()->toDateString(), 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
    DB::table('rate_quote')->where('id', $quota)->update(['importo_pagato' => 10000, 'stato' => 'pagata']);
    $this->travel(1)->days();
    $rate = [spRata($s, 1), spRata($s, 2)];
    DB::table('rate')->whereIn('id', $rate)->update(['stato' => 'emessa', 'data_emissione' => '2026-02-10']);
    aGiornaleNeiTest((int) $s['piano']->id, $rate);
    $this->travel(1)->days();

    $seconda = ['riga_uscente_id' => spRigaDi($s, $s['a']), 'anagrafica_entrante_id' => $zeta->id, 'decorrenza' => '2026-03-01', 'copia_autentica_il' => '2026-03-01'] + spVendita($s, '2026-03-01');
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $seconda)->assertSessionHasNoErrors()->assertRedirect();
    // Prima: la regola della .42 applicata anche a quel passaggio, coppia Elsa −100603 / Zeta +100603.
    expect(Saldo::whereNotNull('subentro_id')->count())->toBe(0);
});

it('decisione 40 (R4) — cambio d\'inquilino con il piano da ricalcolare, emissione senza ricalcolo, poi un altro cambio: nessun credito all\'inquilino di mezzo, e il pannello lo dice', function () {
    $s = spScenario(12, 'prima_rata', 0, ['inquilino' => 30, 'proprietario' => 70]);
    $ines = Anagrafica::where('nome', 'Ines Inquilina')->sole();
    $nuovi = [];
    foreach (['Ivo Secondo' => 'SPIVOSECONDO0001', 'Iris Terza' => 'SPIRISTERZA00001'] as $nome => $cf) {
        $nuovi[] = $p = Anagrafica::forceCreate(['nome' => $nome, 'email' => strtolower(strtok($nome, ' ')) . '@sp.test', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => $cf]);
        $p->condomini()->syncWithoutDetaching([$s['c']->id]);
    }
    [$ivo, $iris] = $nuovi;
    $cambio = fn (int $riga, Anagrafica $entra, string $dal) => ['tipo' => 'fine_locazione', 'tipologia' => 'inquilino', 'riga_uscente_id' => $riga, 'anagrafica_entrante_id' => $entra->id,
        'decorrenza' => $dal, 'quota' => 100, 'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Contratto letto, cambio d\'inquilino'];
    $rigaInes = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $ines->id)->value('id');
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $cambio($rigaInes, $ivo, '2026-02-01'))->assertSessionHasNoErrors()->assertRedirect();

    // La guardia resta spenta per la locazione (decisione 32): l'emissione senza ricalcolo vuol dire che paga Ines.
    $this->travel(1)->days();
    spEmetti($this, $s, [spRata($s, 1), spRata($s, 2)], '2026-02-10')->assertSessionHasNoErrors();
    expect(DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('stato', 'emessa')->count())->toBe(2);
    $this->travel(1)->days();

    $rigaIvo = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $ivo->id)->value('id');
    $anteprima = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), $cambio($rigaIvo, $iris, '2026-03-01'))->assertOk()->json();
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $cambio($rigaIvo, $iris, '2026-03-01'))->assertSessionHasNoErrors()->assertRedirect();

    // Prima: coppia Ivo −30181, Iris +30181 (36000 × 306/365) su quote di Ines, e il cancello diceva «passa ancora».
    expect(Saldo::whereNotNull('subentro_id')->count())->toBe(0)
        ->and((int) DB::table('rate_quote')->where('anagrafica_id', $ines->id)->sum('importo'))->toBe(36000)
        ->and(implode(' | ', $anteprima['cancello']['motivi']))->not->toContain('passa ancora')
        ->and(collect($anteprima['rate']['conguaglio']['non_risolte'] ?? [])->pluck('motivo')->implode(' | '))->toContain('le quote intestate a Ines Inquilina su Interno 1 non sono passate con il suo passaggio del 1 febbraio 2026')
        ->and($anteprima['rate']['frasi'][0] ?? '')->not->toContain('due righe di saldo');
});

it('R5 — piano fermo solo per un incasso: il pannello non dice «nessuna rata emessa, niente da conguagliare», dice perché il piano non si ricalcola, e mostra il conguaglio', function () {
    $s = spScenario(12, 'prima_rata', 0);
    spIncassa($s, 1, 100.00);
    $anteprima = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), spVendita($s, '2026-05-01'))->assertOk()->json();

    $frasi = implode(' ', $anteprima['rate']['frasi']);
    expect($frasi)->not->toContain('Non c\'è niente da conguagliare')
        ->toStartWith('Nessuna rata di questa unità è ancora a giornale, ma il piano «Preventivo 2026» ha un incasso su una sua quota: non si ricalcola più')
        ->and((int) $anteprima['rate']['conguaglio']['totale_entrante'])->toBe(548)
        ->and(implode(' | ', $anteprima['cancello']['motivi']))->toContain('non si può più ricalcolare (ha un incasso su una sua quota)');
});

it('R8 — sui dati di prima il cancello non dice «passa ancora» per le quote che il passaggio di prima non ha fatto passare, e il riepilogo le chiama col loro nome', function () {
    $s = spScenario(12, 'prima_rata', 0);
    $zeta = Anagrafica::forceCreate(['nome' => 'Zeta Seconda', 'email' => 'sp-zeta8@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'SPZETASECONDA008']);
    $zeta->condomini()->syncWithoutDetaching([$s['c']->id]);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), spVendita($s, '2026-02-01'))->assertSessionHasNoErrors()->assertRedirect();
    $this->travel(1)->days();
    $rate = [spRata($s, 1), spRata($s, 2)];
    DB::table('rate')->whereIn('id', $rate)->update(['stato' => 'emessa', 'data_emissione' => '2026-02-10']);
    aGiornaleNeiTest((int) $s['piano']->id, $rate);
    $this->travel(1)->days();

    $seconda = ['riga_uscente_id' => spRigaDi($s, $s['a']), 'anagrafica_entrante_id' => $zeta->id, 'decorrenza' => '2026-03-01', 'copia_autentica_il' => '2026-03-01'] + spVendita($s, '2026-03-01');
    $anteprima = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), $seconda)->assertOk()->json();
    $motivi = implode(' | ', $anteprima['cancello']['motivi']);
    expect($motivi)->toContain('2 quote di rate già emesse a Ugo Venditore: restano sue, senza conguaglio: il passaggio di prima non le ha fatte passare, e il piano non è stato ricalcolato')
        ->not->toContain('passa ancora')
        ->and(collect($anteprima['rate']['conguaglio']['per_gestione'])->sum('mai_passate'))->toBe(12);
});

it('riemissione (S2/D7) — rimandare all\'emissione una rata già a giornale non dà un messaggio vuoto', function () {
    $s = spScenario(6, 'prima_rata', 0);
    spEmetti($this, $s, [spRata($s, 1)])->assertSessionHasNoErrors();
    $r = spEmetti($this, $s, [spRata($s, 1)]);
    expect($r->getSession()->get('esito_emissione'))->toBe(['titolo' => 'Rate già emesse', 'testo' => 'La rata 1 era già emessa.']);
});

/*
|--------------------------------------------------------------------------
| Ripresa del giro di verifica (04/10/2026): le transizioni fra stati
|--------------------------------------------------------------------------
*/

/** Una persona nuova del condominio. */
function spPersona(array $s, string $nome, string $cf): Anagrafica
{
    $p = Anagrafica::forceCreate(['nome' => $nome, 'email' => strtolower(str_replace(' ', '.', $nome)) . '@sp.test', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => $cf]);
    $p->condomini()->syncWithoutDetaching([$s['c']->id]);

    return $p;
}

/** Il netto di una persona sul piano: le sue quote più le coppie dei conguagli in saldi. */
function spNetto(array $s, Anagrafica $p): int
{
    return (int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s['piano']->id)->where('rate_quote.anagrafica_id', $p->id)->sum('rate_quote.importo')
        + (int) Saldo::whereNotNull('subentro_id')->where('anagrafica_id', $p->id)->sum('saldo_iniziale');
}

/** La vendita da chi ha la riga in corso a chi entra. */
function spVenditaDa(array $s, Anagrafica $da, Anagrafica $a, string $dal): array
{
    // Senza copia autentica: la data di ricezione non può essere nel futuro, e le decorrenze della catena arrivano a novembre.
    return ['riga_uscente_id' => spRigaDi($s, $da), 'anagrafica_entrante_id' => $a->id, 'decorrenza' => $dal, 'copia_autentica' => false, 'copia_autentica_il' => null] + spVendita($s, $dal);
}

it('decisione 43, il ciclo completo — conguaglio, piano fermo; annullamento del conguaglio, piano ancora fermo; annullamento del passaggio, nuova registrazione e ricalcolo con le cifre giuste e senza coppia; il passaggio dopo vede il piano com\'è', function () {
    $s = spScenario(12, 'prima_rata', 0);
    spIncassa($s, 1, 100.00);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), spVendita($s, '2026-05-01'))->assertSessionHasNoErrors()->assertRedirect();
    $p1 = \App\Models\Gestionale\Subentro::latest('id')->firstOrFail();
    expect($p1->registro['piani_presi'])->toBe([$s['piano']->id]);
    $this->travel(1)->days();
    spStornaIncassi($s);

    // 1. Conguaglio: il piano è fermo solo per il conguaglio (nessuna scrittura, nessun movimento), e il ricalcolo si rifiuta.
    $piano = $s['piano']->fresh();
    expect($piano->haRateEmesse())->toBeFalse()->and($piano->haIncassiRegistrati())->toBeFalse()
        ->and(collect($piano->passaggiCheLoHannoConguagliato())->pluck('id')->all())->toBe([$p1->id])->and($piano->eImmutabile())->toBeTrue();
    spRicalcola($this, $s);
    expect(spConti($s))->toBe(['ugo' => [40000, 0, -548], 'elsa' => [80000, 0, 548]]);

    // 2. Annullamento del conguaglio: le parti hanno regolato fra loro. Le coppie spariscono, le quote no, e il piano resta fermo:
    // il passaggio lo ha preso, e ricalcolarlo rifarebbe per giorni ciò che le parti hanno già regolato.
    $this->travel(1)->days();
    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$s['c'], $s['unita'], $p1]), ['nota_annullamento_conguaglio' => 'Le parti hanno regolato fra loro'])->assertRedirect();
    $piano = $s['piano']->fresh();
    expect($p1->fresh()->conguaglioAnnullato())->toBeTrue()
        ->and(Saldo::whereNotNull('subentro_id')->count())->toBe(0)
        ->and(collect($piano->passaggiCheLoHannoConguagliato())->pluck('id')->all())->toBe([$p1->id])
        ->and($piano->eImmutabile())->toBeTrue();
    $r = spRicalcola($this, $s);
    expect($r->getSession()->get('message')['message'])->toContain('Per farlo, annulla quel passaggio dallo storico della sua unità');
    expect(spConti($s))->toBe(['ugo' => [40000, 0, 0], 'elsa' => [80000, 0, 0]]);

    // 3. La strada del messaggio: annullare il passaggio, registrarlo di nuovo (il piano non è più preso: niente a giornale, nessun
    // movimento), ricalcolare. Le cifre per giorni (Ugo 1/1–30/4, 120 giorni: 39452; Elsa 245: 80548), nessuna coppia e nessun
    // pregresso nelle quote.
    $this->travel(1)->days();
    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita'], $p1]), ['nota_annullamento' => 'Lo registro di nuovo per ricalcolare il piano'])->assertRedirect();
    expect($s['piano']->fresh()->eImmutabile())->toBeFalse();
    $this->travel(1)->days();
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), spVendita($s, '2026-05-01'))->assertSessionHasNoErrors()->assertRedirect();
    $p2 = \App\Models\Gestionale\Subentro::latest('id')->firstOrFail();
    expect($p2->id)->not->toBe($p1->id)->and($p2->registro['piani_presi'])->toBe([])
        ->and(collect($s['piano']->fresh()->passaggiDaSeguire())->pluck('id')->all())->toBe([$p2->id]);
    spRicalcola($this, $s)->assertSessionHasNoErrors()->assertRedirect();
    expect(spConti($s))->toBe(['ugo' => [39452, 0, 0], 'elsa' => [80548, 0, 0]])
        ->and($s['piano']->fresh()->passaggiDaSeguire())->toBe([])
        ->and($s['piano']->fresh()->eImmutabile())->toBeFalse();

    // 4. Il passaggio dopo vede il piano com'è: ricalcolabile, senza conguaglio e senza quote «mai passate».
    $zeta = spPersona($s, 'Zeta Terza', 'SPZETATERZA00001');
    $this->travel(1)->days();
    $anteprima = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), spVenditaDa($s, $s['a'], $zeta, '2026-09-01'))->assertOk()->json();
    expect($anteprima['rate']['conguaglio']['stato'] ?? 'nessuno')->toBe('nessuno')
        ->and(implode(' | ', $anteprima['cancello']['motivi']))->toContain('il destinatario cambierebbe')->not->toContain('non si può più ricalcolare')->not->toContain('non le ha fatte passare');
});

it('catena di quattro persone — il conguaglio di prima tiene fermo il piano lungo la catena: ognuno paga i suoi giorni, nessun fermo falso', function () {
    $s = spScenario(12, 'prima_rata', 0);
    [$zeta, $walter] = [spPersona($s, 'Zeta Terza', 'SPZETATERZA00002'), spPersona($s, 'Walter Quarto', 'SPWALTERQUARTO01')];
    spIncassa($s, 1, 100.00);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), spVendita($s, '2026-05-01'))->assertSessionHasNoErrors()->assertRedirect();
    // Lo storno toglie il movimento: da qui il piano è fermo solo per il conguaglio di Ugo → Elsa (decisione 38), e i passaggi dopo
    // lo devono riconoscere attraverso quel conguaglio (`eraImmutabileAl` guarda i passaggi di prima).
    $this->travel(1)->days();
    spStornaIncassi($s);
    $this->travel(1)->days();
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), spVenditaDa($s, $s['a'], $zeta, '2026-09-01'))->assertSessionHasNoErrors()->assertRedirect();
    $this->travel(1)->days();
    $anteprima = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), spVenditaDa($s, $zeta, $walter, '2026-11-01'))->assertOk()->json();
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), spVenditaDa($s, $zeta, $walter, '2026-11-01'))->assertSessionHasNoErrors()->assertRedirect();

    // A mano, 120000 per giorni: Ugo 1/1–30/4 (120) = 39452; Elsa 1/5–31/8 (123) = 40438; Zeta 1/9–31/10 (61) = 20055; Walter
    // 1/11–31/12 (61) = 20055. Totale 120000. Nessun conguaglio fermo lungo la catena.
    expect([spNetto($s, $s['v']), spNetto($s, $s['a']), spNetto($s, $zeta), spNetto($s, $walter)])->toBe([39452, 40438, 20055, 20055])
        ->and(collect($anteprima['rate']['conguaglio']['non_risolte'] ?? [])->pluck('motivo')->implode(' | '))->not->toContain('non sono passate');
});

it('decisione 41 — incasso, compensazione e rimborso con un passaggio da seguire: tre ingressi, tre rifiuti, nessun effetto parziale', function () {
    // Ugo ha un credito di € 150,00 nella rata 0; la rata 1 vale € 100,00.
    $s = spScenario(12, 'rata_zero', -15000);
    $quotaRata1 = (int) DB::table('rate_quote')->where('rata_id', spRata($s, 1))->value('id');
    $quotaCredito = (int) DB::table('rate_quote')->where('rata_id', spRata($s, 0))->value('id');
    expect((int) DB::table('rate_quote')->where('id', $quotaCredito)->value('importo'))->toBe(-15000);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), spVendita($s, '2026-05-01'))->assertSessionHasNoErrors()->assertRedirect();
    expect($s['piano']->fresh()->passaggiDaSeguire())->toHaveCount(1);

    $foto = fn () => [
        DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->count(), DB::table('righe_scritture')->count(), DB::table('quota_scrittura')->count(),
        DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s['piano']->id)->orderBy('rate_quote.id')->get(['rate_quote.id', 'rate_quote.anagrafica_id', 'rate_quote.importo', 'rate_quote.importo_pagato', 'rate_quote.stato'])->map(fn ($q) => (array) $q)->all(),
        Saldo::count(),
    ];
    $prima = $foto();
    $frase = fn (string $cosa) => 'Ricalcola prima il piano «Preventivo 2026»: le sue quote sono state calcolate prima del passaggio di Ugo Venditore su Interno 1, dal 1 maggio 2026, e ' . $cosa;

    // 1. Incasso.
    expect(fn () => spIncassa($s, 1, 100.00))->toThrow(\App\Exceptions\Gestionale\PianoDaRicalcolareException::class, $frase('un incasso'));
    // 2. Compensazione: la rata 1 pagata in parte con il credito della rata 0.
    expect(fn () => app(StoreIncassoRateAction::class)->execute([
        'pagante_id' => $s['v']->id, 'cassa_id' => $s['cassa']->id, 'gestione_id' => $s['g']->id, 'data_pagamento' => '2026-05-10', 'importo_totale' => 70.00, 'descrizione' => 'Rata 1 con il credito',
        'dettaglio_pagamenti' => [['rata_id' => $quotaRata1, 'importo' => 100.00], ['rata_id' => $quotaCredito, 'importo' => -30.00]],
    ], $s['c'], $s['e']))->toThrow(\App\Exceptions\Gestionale\PianoDaRicalcolareException::class, $frase('un incasso o un credito usato'));
    // 3. Rimborso del credito, dalla rotta vera.
    $r = $this->actingAs($this->user)->post(route('admin.gestionale.anagrafiche.rimborsi.store', [$s['c'], $s['v']]), ['rata_quote_id' => $quotaCredito, 'cassa_id' => $s['cassa']->id, 'data_rimborso' => '2026-05-10', 'importo' => '30,00', 'nota' => 'Bonifico di prova']);
    $r->assertSessionHasErrors('rata_quote_id');
    expect(session('errors')->first('rata_quote_id'))->toStartWith($frase('un rimborso'));

    // Nessun effetto parziale: scritture, righe, pagamenti, quote e saldi come prima.
    expect($foto())->toBe($prima);
});

it('punto 8 — il piano dice la ragione vera per cui è fermo: un incasso, un credito usato o rimborsato, un conguaglio, una scrittura', function () {
    // Un incasso su una quota da pagare.
    $a = spScenario(12, 'prima_rata', 0);
    spIncassa($a, 1, 100.00);
    expect($a['piano']->fresh()->ragioniDelFermo())->toBe(['incasso'])->and($a['piano']->fresh()->fraseDelFermo())->toBe('ha un incasso su una sua quota');

    // Un credito rimborsato (quota a credito della rata 0).
    $b = spScenario(12, 'rata_zero', -15000);
    $credito = \App\Models\Gestionale\RataQuote::where('rata_id', spRata($b, 0))->firstOrFail();
    $b['cassa']->update(['saldo_iniziale' => 10000]);
    app(\App\Actions\Gestionale\Rimborsi\RimborsaCreditoAction::class)->execute($b['c'], $b['e'], $credito, $b['cassa'], \Carbon\CarbonImmutable::parse('2026-01-20'), 3000, 'Bonifico', $this->user->id);
    expect($b['piano']->fresh()->ragioniDelFermo())->toBe(['credito'])->and($b['piano']->fresh()->fraseDelFermo())->toBe('ha un credito usato o rimborsato su una sua quota');

    // Un conguaglio, dopo lo storno dell'incasso che aveva fermato il piano.
    spIncassa($a = spScenario(12, 'prima_rata', 0), 1, 100.00);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$a['c'], $a['unita']]), spVendita($a, '2026-05-01'))->assertSessionHasNoErrors()->assertRedirect();
    $this->travel(1)->days();
    spStornaIncassi($a);
    expect($a['piano']->fresh()->ragioniDelFermo())->toBe(['conguaglio'])
        ->and($a['piano']->fresh()->fraseDelFermo())->toBe('è stato preso nel conguaglio del passaggio Ugo Venditore → Elsa Acquirente, Interno 1, dal 1 maggio 2026')
        ->and($a['piano']->fresh()->rimediDelFermo())->toBe(['annulla quel passaggio dallo storico della sua unità («Passaggi registrati», dall\'ultimo) e registralo di nuovo']);

    // Una scrittura.
    $d = spScenario(6, 'prima_rata', 0);
    spEmetti($this, $d, [spRata($d, 1)])->assertSessionHasNoErrors();
    expect($d['piano']->fresh()->ragioniDelFermo())->toBe(['scrittura'])->and($d['piano']->fresh()->fraseDelFermo())->toBe('ha già quote a giornale');

    // E un piano che si ricalcola ancora non ha ragioni.
    $e = spScenario(6, 'prima_rata', 0);
    expect($e['piano']->fresh()->ragioniDelFermo())->toBe([])->and($e['piano']->fresh()->fraseDelFermo())->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Giro di verifica della ripresa della .42: i rilievi V1–V9 (decisioni 42 e 43)
|--------------------------------------------------------------------------
*/

/** Un secondo piano sulla stessa gestione, con una voce sua; il primo piano prende «Spese generali» come sua voce. */
function spSecondoPiano(array $s, int $importo, int $numeroRate = 12): PianoRate
{
    $pc = PianoConto::where('gestione_id', $s['g']->id)->firstOrFail();
    $generali = Conto::where('piano_conto_id', $pc->id)->where('nome', 'Spese generali')->firstOrFail();
    $s['piano']->capitoli()->syncWithoutDetaching([$generali->id => ['importo' => null]]);
    $conto = Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Riscaldamento', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => $importo]);
    $tabella = Tabella::where('condominio_id', $s['c']->id)->firstOrFail();
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    $piano = PianoRate::create([
        'gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Riscaldamento 2026', 'stato' => 'approvato', 'tipo' => 'ordinario',
        'numero_rate' => $numeroRate, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => true,
    ]);
    $piano->capitoli()->attach($conto->id, ['importo' => null]);
    app(GeneratePianoRateAction::class)->execute($piano, forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Secondo piano della gestione', esercizio: $s['e']);

    return $piano->fresh();
}

/** Il preventivo nelle quote di un piano, per persona. */
function spQuoteDi(PianoRate $piano, Anagrafica $p): int
{
    return (int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)->where('rate_quote.anagrafica_id', $p->id)->sum('rate_quote.importo');
}

function spAnnullaConguaglio($test, array $s, \App\Models\Gestionale\Subentro $p)
{
    return $test->actingAs($test->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$s['c'], $s['unita'], $p]), ['nota_annullamento_conguaglio' => 'Le parti hanno regolato fra loro']);
}

function spAnnullaPassaggio($test, array $s, \App\Models\Gestionale\Subentro $p)
{
    return $test->actingAs($test->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita'], $p]), ['nota_annullamento' => 'Lo registro di nuovo']);
}

it('V1 — annullato il conguaglio del passaggio di prima, il passaggio dopo resta un passaggio che ha preso il piano: il piano resta fermo, nessun ricalcolo obbligato, e chi è entrato dopo paga la sua coppia una volta', function () {
    $s = spScenario(12, 'prima_rata', 0);
    spIncassa($s, 1, 100.00);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), spVendita($s, '2026-05-01'))->assertSessionHasNoErrors()->assertRedirect();
    $p1 = \App\Models\Gestionale\Subentro::latest('id')->firstOrFail();
    $this->travel(1)->days();
    spStornaIncassi($s);
    $zeta = spPersona($s, 'Zeta Terza', 'SPZETATERZAV1001');
    $this->travel(1)->days();
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), spVenditaDa($s, $s['a'], $zeta, '2026-09-01'))->assertSessionHasNoErrors()->assertRedirect();
    $p2 = \App\Models\Gestionale\Subentro::latest('id')->firstOrFail();
    // Il piano era fermo per il conguaglio di p1: p2 lo prende. Zeta, 122 giorni: 120000 × 122/365 = 40109,6 → 40110; le bozze
    // da settembre fanno 40000, la coppia è 110.
    expect($p2->registro['piani_presi'])->toBe([$s['piano']->id])
        ->and([spNetto($s, $s['v']), spNetto($s, $s['a']), spNetto($s, $zeta)])->toBe([39452, 40438, 40110]);

    // Prima: annullato il conguaglio di p1, p2 «dimenticava» il piano, il ricalcolo diventava obbligato e assorbiva la coppia di
    // p2 — Zeta 40220, € 1,10 due volte.
    $this->travel(1)->days();
    spAnnullaConguaglio($this, $s, $p1)->assertRedirect();
    $piano = $s['piano']->fresh();
    expect(collect($piano->passaggiCheLoHannoConguagliato())->pluck('id')->all())->toBe([$p1->id, $p2->id])
        ->and($piano->eImmutabile())->toBeTrue()->and($piano->passaggiDaSeguire())->toBe([])
        ->and(spRicalcola($this, $s)->getSession()->get('message')['type'])->toBe('error')
        ->and([spNetto($s, $s['v']), spNetto($s, $s['a']), spNetto($s, $zeta)])->toBe([40000, 39890, 40110]);
    spEmetti($this, $s, [spRata($s, 9)], '2026-09-05')->assertSessionHasNoErrors();
    expect(DB::table('rate')->where('id', spRata($s, 9))->value('stato'))->toBe('emessa');

    // E annullato il passaggio dopo (è l'ultimo): resta p1, che il piano l'aveva preso. Fermo, e Elsa riprende le bozze.
    $this->actingAs($this->user)->delete(route('admin.gestionale.piani-rate.annulla-emissione', ['condominio' => $s['c']->id, 'pianoRate' => $s['piano']->id, 'rata' => spRata($s, 9)]));
    $this->travel(1)->days();
    spAnnullaPassaggio($this, $s, $p2)->assertRedirect();
    $piano = $s['piano']->fresh();
    expect(collect($piano->passaggiCheLoHannoConguagliato())->pluck('id')->all())->toBe([$p1->id])->and($piano->eImmutabile())->toBeTrue()
        ->and($piano->passaggiDaSeguire())->toBe([])
        ->and([spNetto($s, $s['v']), spNetto($s, $s['a']), spNetto($s, $zeta)])->toBe([40000, 80000, 0]);
});

it('V2 — la rinuncia al conguaglio tiene fermo il piano come il conguaglio: le parti hanno regolato fra loro, il piano non si ricalcola, e il passaggio dopo lo conguaglia', function () {
    $s = spScenario(12, 'prima_rata', 0);
    spIncassa($s, 1, 100.00);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), spVendita($s, '2026-05-01') + [
        'rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Le parti hanno regolato fra loro il conguaglio al rogito',
    ])->assertSessionHasNoErrors()->assertRedirect();
    $p1 = \App\Models\Gestionale\Subentro::latest('id')->firstOrFail();
    $this->travel(1)->days();
    spStornaIncassi($s);

    // Prima: nessuna coppia, quindi nessun fermo; il passaggio dopo rendeva il ricalcolo obbligato, e il ricalcolo rifaceva per
    // giorni i € 5,48 già regolati fra Ugo e Elsa.
    $piano = $s['piano']->fresh();
    expect(Saldo::whereNotNull('subentro_id')->count())->toBe(0)
        ->and($p1->registro['piani_presi'])->toBe([$s['piano']->id])
        ->and($piano->ragioniDelFermo())->toBe(['conguaglio'])
        ->and(spRicalcola($this, $s)->getSession()->get('message')['message'])->toContain('ha preso questo piano nel conguaglio');

    $zeta = spPersona($s, 'Zeta Terza', 'SPZETATERZAV2001');
    $this->travel(1)->days();
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), spVenditaDa($s, $s['a'], $zeta, '2026-09-01'))->assertSessionHasNoErrors()->assertRedirect();
    expect($s['piano']->fresh()->passaggiDaSeguire())->toBe([])
        ->and([spNetto($s, $s['v']), spNetto($s, $s['a']), spNetto($s, $zeta)])->toBe([40000, 39890, 40110]);
});

it('V4 — due piani presi dallo stesso conguaglio: annullato il conguaglio restano fermi tutti e due, e il messaggio lo dice; nessuno dei due si emette per rata a metà fra ricalcolo e conguaglio', function () {
    $s = spScenario(12, 'prima_rata', 0);
    $b = spSecondoPiano($s, 60000);
    spIncassa($s, 1, 100.00);
    spIncassa(['piano' => $b] + $s, 1, 50.00);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), spVendita($s, '2026-05-01'))->assertSessionHasNoErrors()->assertRedirect();
    $p1 = \App\Models\Gestionale\Subentro::latest('id')->firstOrFail();
    // Una coppia sola per la gestione: 548 del primo piano (120000 × 245/365 − 80000) e 274 del secondo (60000 × 245/365 − 40000).
    expect($p1->registro['piani_presi'])->toEqualCanonicalizing([$s['piano']->id, $b->id])
        ->and((int) Saldo::whereNotNull('subentro_id')->where('anagrafica_id', $s['a']->id)->sum('saldo_iniziale'))->toBe(822);
    $this->travel(1)->days();
    spStornaIncassi($s);

    $this->travel(1)->days();
    $r = spAnnullaConguaglio($this, $s, $p1)->assertRedirect();
    expect(session('message')['message'] ?? $r->getSession()->get('message')['message'])->toContain('i piani che il passaggio ha preso restano com\'erano. Se uno va ricalcolato: si tolgono prima le sue quote a giornale e i movimenti, se ne ha; poi si annulla il passaggio dallo storico dell\'unità (dall\'ultimo), lo si registra di nuovo e si ricalcola il piano; i movimenti tolti si registrano di nuovo dopo.')
        // Decisione 46: la cifra che le parti hanno regolato fra loro, per la gestione (822 = 548 + 274).
        ->toContain('l\'accordo fra le parti (€ 8,22 sulla gestione Ordinaria 2026) va rifatto');
    // Prima: il secondo piano tornava ricalcolabile ma non «da seguire», e si emetteva con Ugo a 20000 per i giorni di Elsa.
    foreach ([$s['piano']->fresh(), $b->fresh()] as $piano) {
        expect($piano->eImmutabile())->toBeTrue()->and($piano->ragioniDelFermo())->toBe(['conguaglio'])->and($piano->passaggiDaSeguire())->toBe([])
            ->and(spRicalcola($this, ['piano' => $piano] + $s)->getSession()->get('message')['message'])->toContain('ha preso questo piano nel conguaglio');
    }
    expect([spQuoteDi($s['piano'], $s['v']), spQuoteDi($s['piano'], $s['a']), spQuoteDi($b, $s['v']), spQuoteDi($b, $s['a'])])->toBe([40000, 80000, 20000, 40000])
        ->and(Saldo::whereNotNull('subentro_id')->count())->toBe(0);
});

it('V5 — costituzione dell\'usufrutto con la legge su un piano fermo: annullato il conguaglio il piano resta fermo, e l\'ordinaria non passa in silenzio al nudo proprietario; la strada (annullare il passaggio, piano in bozza, registrarlo di nuovo, ricalcolare) dà a Elsa i suoi giorni', function () {
    $s = spScenario(12, 'prima_rata', 0);
    spIncassa($s, 1, 100.00);
    $costituzione = [
        'tipo' => 'usufrutto', 'sottotipo' => 'costituzione', 'ordinaria_dopo_atto' => 'usufruttuario', 'riga_uscente_id' => $s['rigaV'], 'anagrafica_entrante_id' => $s['a']->id,
        'decorrenza' => '2026-05-01', 'quota' => 100, 'tipologia' => 'usufruttuario', 'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Atto di costituzione letto',
    ];
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $costituzione)->assertSessionHasNoErrors()->assertRedirect();
    $p1 = \App\Models\Gestionale\Subentro::latest('id')->firstOrFail();
    // Il piano è fermo, la voce resta sul «Proprietario»: il conguaglio dà a Elsa l'ordinaria dei suoi 245 giorni.
    expect((int) Saldo::whereNotNull('subentro_id')->where('anagrafica_id', $s['a']->id)->sum('saldo_iniziale'))->toBe(80548);
    $this->travel(1)->days();
    spStornaIncassi($s);

    // Prima: annullato il conguaglio il piano tornava ricalcolabile, e il ricalcolo dava tutto a Ugo (la voce sul «Proprietario»).
    $this->travel(1)->days();
    spAnnullaConguaglio($this, $s, $p1)->assertRedirect();
    expect($s['piano']->fresh()->eImmutabile())->toBeTrue()
        ->and(spRicalcola($this, $s)->getSession()->get('message')['message'])->toContain('annulla quel passaggio dallo storico della sua unità');
    expect(spQuoteDi($s['piano'], $s['v']))->toBe(120000);

    $this->travel(1)->days();
    spAnnullaPassaggio($this, $s, $p1)->assertRedirect();
    $this->actingAs($this->user)->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $s['piano']]), ['approvato' => false])->assertRedirect();
    $this->travel(1)->days();
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $costituzione)->assertSessionHasNoErrors()->assertRedirect();
    $p2 = \App\Models\Gestionale\Subentro::latest('id')->firstOrFail();
    expect($p2->registro['piani_presi'])->toBe([])->and(collect($p2->registro['voci_spostate'] ?? [])->pluck('conto')->all())->toBe(['Spese generali']);
    $this->actingAs($this->user)->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $s['piano']]), ['approvato' => true])->assertRedirect();
    spRicalcola($this, $s)->assertSessionHasNoErrors()->assertRedirect();
    expect([spQuoteDi($s['piano'], $s['v']), spQuoteDi($s['piano'], $s['a'])])->toBe([39452, 80548])
        ->and(Saldo::whereNotNull('subentro_id')->count())->toBe(0);
});

it('V7 — la coppia assorbita da un altro piano della stessa gestione: l\'annullamento dell\'emissione di quel piano non si rifiuta più (il passaggio non l\'ha preso), e il piano preso indica una strada che si percorre fino in fondo', function () {
    $s = spScenario(12, 'prima_rata', 0);
    $b = spSecondoPiano($s, 60000);
    $sb = ['piano' => $b] + $s;
    spIncassa($s, 1, 100.00);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), spVendita($s, '2026-05-01'))->assertSessionHasNoErrors()->assertRedirect();
    $p1 = \App\Models\Gestionale\Subentro::latest('id')->firstOrFail();
    expect($p1->registro['piani_presi'])->toBe([$s['piano']->id])
        ->and(collect($b->fresh()->passaggiDaSeguire())->pluck('id')->all())->toBe([$p1->id]);

    // Il secondo piano segue il passaggio: ricalcolato, assorbe la coppia del primo (60000 × 120/365 = 19726, meno 548: 19178).
    spRicalcola($this, $sb)->assertSessionHasNoErrors()->assertRedirect();
    expect([spQuoteDi($b, $s['v']), spQuoteDi($b, $s['a'])])->toBe([19178, 40822]);
    $rateB = DB::table('rate')->where('piano_rate_id', $b->id)->orderBy('numero_rata')->pluck('id')->all();
    spEmetti($this, $sb, $rateB, '2026-05-10')->assertSessionHasNoErrors();
    $this->travel(1)->days();
    spStornaIncassi($s);

    // Rilievo W6 del giro sulle correzioni (decisione 50): si seguono i messaggi. Il ricalcolo di A manda ad annullare il passaggio;
    // l'annullamento del passaggio dà due strade — il passaggio può restare, oppure B va prima riaperto (annullate le emissioni),
    // riportato in bozza ed eliminato —, non più il saldo manuale, che non lo sblocca. «Annulla il conguaglio», nello stesso
    // stato, dice ancora il saldo manuale: lì è la strada giusta.
    expect(spRicalcola($this, $s)->getSession()->get('message')['message'])->toContain('annulla quel passaggio dallo storico della sua unità');
    expect(spAnnullaPassaggio($this, $s, $p1)->assertUnprocessable()->json('errors.passaggio.0'))
        ->toContain('Il passaggio può restare: i piani che ha preso si emettono così come sono')
        ->toContain('Per annullarlo comunque, il piano «Riscaldamento 2026», che ha già quote a giornale, e le quote sono in mano ai condòmini, va prima riaperto — annulla le emissioni dalla pagina del piano, se non hanno incassi —; poi riportalo in bozza, elimina il piano')
        ->not->toContain('saldo manuale');
    expect(spAnnullaConguaglio($this, $s, $p1)->assertUnprocessable()->json('errors.conguaglio.0'))->toContain('saldo manuale di segno opposto');

    // Prima: il confronto per gestione e per ora rifiutava l'annullamento delle emissioni di B, che il passaggio non ha preso.
    foreach ($rateB as $rata) {
        $r = $this->actingAs($this->user)->delete(route('admin.gestionale.piani-rate.annulla-emissione', ['condominio' => $s['c']->id, 'pianoRate' => $b->id, 'rata' => $rata]));
        expect($r->getSession()->get('message')['type'])->toBe('success');
    }
    // Il primo piano: fermo per il conguaglio, si riapre annullando il passaggio. La coppia l'ha assorbita B, e l'annullamento lo
    // dice: B (ora senza emissioni) torna in bozza e si elimina, poi si annulla il passaggio, lo si registra di nuovo e si
    // ricalcola; B si rifà dopo, e divide per giorni da sé.
    expect(spRicalcola($this, $s)->getSession()->get('message')['message'])->toContain('annulla quel passaggio dallo storico della sua unità');
    $this->travel(1)->days();
    expect(spAnnullaPassaggio($this, $s, $p1)->assertUnprocessable()->json('errors.passaggio.0'))->toContain('Il piano «Riscaldamento 2026» non ha ancora emesso nulla')->toContain('elimina il piano');
    $this->actingAs($this->user)->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $b]), ['approvato' => false])->assertRedirect();
    $this->actingAs($this->user)->delete(route('admin.gestionale.esercizi.piani-rate.destroy', [$s['c'], $s['e'], $b]))->assertSessionHasNoErrors();
    expect(PianoRate::whereKey($b->id)->exists())->toBeFalse();
    spAnnullaPassaggio($this, $s, $p1)->assertRedirect();
    $this->travel(1)->days();
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), spVendita($s, '2026-05-01'))->assertSessionHasNoErrors()->assertRedirect();
    spRicalcola($this, $s)->assertSessionHasNoErrors()->assertRedirect();
    $b2 = PianoRate::create([
        'gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Riscaldamento 2026, di nuovo', 'stato' => 'approvato', 'tipo' => 'ordinario',
        'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => true,
    ]);
    $b2->capitoli()->attach(Conto::where('nome', 'Riscaldamento')->where('piano_conto_id', PianoConto::where('gestione_id', $s['g']->id)->value('id'))->value('id'), ['importo' => null]);
    app(GeneratePianoRateAction::class)->execute($b2, forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Vendita del 1/5', esercizio: $s['e']);
    expect([spQuoteDi($s['piano'], $s['v']), spQuoteDi($s['piano'], $s['a']), spQuoteDi($b2, $s['v']), spQuoteDi($b2, $s['a'])])->toBe([39452, 80548, 19726, 40274])
        ->and(Saldo::whereNotNull('subentro_id')->count())->toBe(0);
});

it('V3 — dati della .41: una vendita che ha conguagliato una rata «emessa» senza scrittura (la forma di DC5) tiene fermo il piano e lo lascia da seguire; emissione, ricalcolo e incasso si rifiutano, e la strada del messaggio porta alle cifre per giorni senza la coppia', function () {
    $s = spScenario(6, 'prima_rata', -67100);
    // Come la lasciava l'emissione fino alla .41: la rata 1, di soli crediti, «emessa» senza scrittura.
    DB::table('rate')->where('id', spRata($s, 1))->update(['stato' => 'emessa', 'data_emissione' => '2026-01-13']);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), spVendita($s, '2026-02-07'))->assertSessionHasNoErrors()->assertRedirect();
    $p1 = \App\Models\Gestionale\Subentro::latest('id')->firstOrFail();
    // Il passaggio come lo scriveva la .41: registro versione 1, senza `piani_presi`, e la coppia sulla rata «emessa»
    // (20000 × 328/365 = 17972,6 → 17973).
    $registro = $p1->registro;
    unset($registro['piani_presi']);
    $p1->update(['registro' => array_replace($registro, ['versione' => 1])]);
    foreach ([[$s['v']->id, -17973], [$s['a']->id, 17973]] as [$chi, $importo]) {
        Saldo::create(['esercizio_id' => $s['e']->id, 'condominio_id' => $s['c']->id, 'gestione_id' => $s['g']->id, 'immobile_id' => $s['unita']->id, 'anagrafica_id' => $chi,
            'saldo_iniziale' => $importo, 'origine' => 'automatico', 'is_applicato' => false, 'subentro_id' => $p1->id, 'descrizione' => 'Conguaglio della .41']);
    }
    $this->travel(1)->days();

    // Prima: il piano risultava da seguire e non fermo; l'emissione obbligava al ricalcolo, che assorbiva la coppia — Elsa 125809,
    // cioè i suoi giorni (107836) più i 17973 già nella coppia.
    $piano = $s['piano']->fresh();
    expect($piano->presoSoloInParteDa($p1))->toBeTrue()->and($piano->eImmutabile())->toBeTrue()
        ->and(collect($piano->passaggiDaSeguire())->pluck('id')->all())->toBe([$p1->id]);
    expect(spEmetti($this, $s, [spRata($s, 2)], '2026-02-10')->getSession()->get('message')['message'])
        ->toContain("Oggi il ricalcolo si rifiuta perché il piano è stato preso nel conguaglio di questo passaggio:\n• Ugo Venditore → Elsa Acquirente, Interno 1, dal 7 febbraio 2026\nPer procedere:")
        ->toContain("\n1. Annulla quel passaggio dallo storico della sua unità («Passaggi registrati», dall'ultimo) e registralo di nuovo.\n2. Ricalcola il piano.");
    expect(spRicalcola($this, $s)->getSession()->get('message')['message'])->toContain('ha preso questo piano nel conguaglio');
    expect(fn () => spIncassa($s, 2, 100.00))->toThrow(\App\Exceptions\Gestionale\PianoDaRicalcolareException::class);
    expect(DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('stato', 'emessa')->count())->toBe(1);

    // La strada: annullare il passaggio (la coppia se ne va con lui), registrarlo di nuovo, ricalcolare.
    spAnnullaPassaggio($this, $s, $p1)->assertRedirect();
    $this->travel(1)->days();
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), spVendita($s, '2026-02-07'))->assertSessionHasNoErrors()->assertRedirect();
    spRicalcola($this, $s)->assertSessionHasNoErrors()->assertRedirect();
    expect(spConti($s))->toBe(['ugo' => [12164, -67100, 0], 'elsa' => [107836, 0, 0]])
        ->and($s['piano']->fresh()->passaggiDaSeguire())->toBe([]);
});

it('V6 — le query per sapere se un piano è fermo o da seguire crescono col numero dei passaggi, non raddoppiano: una catena di sette vendite su un piano mai fermo', function () {
    $s = spScenario(12, 'prima_rata', 0);
    $misure = [];
    $chi = $s['v'];
    $mesi = ['02', '03', '04', '05', '06', '07', '08'];
    foreach ($mesi as $i => $mese) {
        $dopo = spPersona($s, "Persona {$i}", 'SPCATENAV6' . str_pad((string) $i, 6, '0', STR_PAD_LEFT));
        $this->travel(1)->minutes();
        $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), spVenditaDa($s, $chi, $dopo, "2026-{$mese}-01"))->assertSessionHasNoErrors()->assertRedirect();
        $chi = $dopo;
        if (in_array($i + 1, [3, 5, 7], true)) {
            $piano = $s['piano']->fresh();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $piano->conguagliato();
            $piano->passaggiDaSeguire();
            $misure[$i + 1] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }
    }

    // Prima, con la storia ricostruita all'indietro, un piano mai fermo esplorava tutti i sottoinsiemi dei passaggi di prima:
    // con dieci passaggi `conguagliato()` faceva 5117 query, e l'aumento raddoppiava a ogni passaggio. Con il registro (decisione
    // 42) le due domande insieme fanno 10 query con tre, cinque o sette passaggi; qui si chiede solo che non crescano più che in
    // modo lineare.
    expect(count(\App\Models\Gestionale\Subentro::all()))->toBe(7)
        ->and($misure[7] - $misure[5])->toBeLessThanOrEqual($misure[5] - $misure[3] + 2)
        ->and($misure[7])->toBeLessThan(100);
});

it('V9 — nel Wallet un saldo assorbito da un piano fermo solo per il conguaglio dice la ragione vera e il suo rimedio, nella modifica, nell\'eliminazione e nel pannello; niente «annulla le emissioni», che non ci sono', function () {
    $s = spScenario(12, 'prima_rata', 5000);
    $saldo = Saldo::whereNull('subentro_id')->where('anagrafica_id', $s['v']->id)->sole();
    expect((int) $saldo->piano_rate_id)->toBe((int) $s['piano']->id);
    spIncassa($s, 1, 50.00);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), spVendita($s, '2026-05-01'))->assertSessionHasNoErrors()->assertRedirect();
    $this->travel(1)->days();
    spStornaIncassi($s);
    expect($s['piano']->fresh()->ragioniDelFermo())->toBe(['conguaglio']);

    $ragione = 'che è stato preso nel conguaglio del passaggio Ugo Venditore → Elsa Acquirente, Interno 1, dal 1 maggio 2026. Per correggerlo: annulla quel passaggio dallo storico della sua unità';
    $this->actingAs($this->user)->patch(route('admin.gestionale.saldi.update', [$s['c']->id, $saldo->id]), ['saldo_iniziale' => 6000, 'gestione_id' => $s['g']->id])->assertSessionHasErrors('saldo');
    expect(session('errors')->first('saldo'))->toContain($ragione)->not->toContain('già emesso');
    $elimina = $this->actingAs($this->user)->delete(route('admin.gestionale.saldi.destroy', [$s['c']->id, $saldo->id]));
    expect($elimina->status())->toBe(403)->and($elimina->exception?->getMessage())->toContain(str_replace('Per correggerlo', 'Per eliminarlo', $ragione));
    expect($saldo->fresh()->saldo_iniziale)->toBe(5000);

    $pagina = $this->actingAs($this->user)->get(route('admin.gestionale.saldi.index', [$s['c']->id]))->assertOk()->viewData('page')['props'];
    $nelPannello = collect($pagina['immobili'])->flatMap(fn ($i) => $i['saldi'])->firstWhere('id', $saldo->id);
    expect($nelPannello['e_bloccato'])->toBeTrue()
        ->and($nelPannello['fermo_del_piano'])->toBe([
            'perche' => 'è stato preso nel conguaglio del passaggio Ugo Venditore → Elsa Acquirente, Interno 1, dal 1 maggio 2026',
            'rimedi' => ['annulla quel passaggio dallo storico della sua unità («Passaggi registrati», dall\'ultimo) e registralo di nuovo'],
            'ragioni' => ['conguaglio'],
        ]);
});

/*
|--------------------------------------------------------------------------
| Giro solo sulle correzioni della .42: W5 (decisione 47)
|--------------------------------------------------------------------------
*/

function spInizioLocazione($test, array $s, Anagrafica $chi, string $dal)
{
    return $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), [
        'tipo' => 'inizio_locazione', 'anagrafica_entrante_id' => $chi->id, 'decorrenza' => $dal, 'quota' => 100, 'tipologia' => 'inquilino',
        'copia_autentica' => false, 'data_fine_locazione' => '2030-02-28', 'regime_contratto' => 'abitativo', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Contratto letto, piano controllato',
    ]);
}

it('W5, decisione 47 — un inizio locazione non ha conguaglio e non prende il piano: dopo, l\'annullamento di un\'emissione riesce come nella .41; lo stesso con un inizio locazione registrato prima della .42', function (bool $dellaPrima) {
    $s = spScenario(12, 'prima_rata', 0);
    spEmetti($this, $s, [spRata($s, 1)])->assertSessionHasNoErrors();
    $luca = spPersona($s, 'Luca Inquilino', 'SPLUCAINQUIL0001');
    $this->travel(1)->days();
    spInizioLocazione($this, $s, $luca, '2026-03-01')->assertSessionHasNoErrors()->assertRedirect();
    $p = \App\Models\Gestionale\Subentro::latest('id')->firstOrFail();
    expect($p->registro['piani_presi'])->toBe([]);
    if ($dellaPrima) {
        $registro = $p->registro;
        unset($registro['piani_presi']);
        $p->update(['registro' => array_replace($registro, ['versione' => 1])]);
    }

    // Prima: «…ed è stato preso nel conguaglio del passaggio ? → Luca Inquilino», e l'annullamento si rifiutava.
    $piano = $s['piano']->fresh();
    expect($piano->passaggiCheLoHannoConguagliato())->toBe([])->and($piano->ragioniDelFermo())->toBe(['scrittura']);
    $r = $this->actingAs($this->user)->delete(route('admin.gestionale.piani-rate.annulla-emissione', ['condominio' => $s['c']->id, 'pianoRate' => $s['piano']->id, 'rata' => spRata($s, 1)]));
    expect($r->getSession()->get('message')['type'])->toBe('success')
        ->and(DB::table('rate')->where('id', spRata($s, 1))->value('stato'))->toBe('bozza');
})->with(['registrato con la .42' => [false], 'registrato prima, senza `piani_presi`' => [true]]);

it('W5, decisione 47 — la fine di una locazione senza un nuovo inquilino non prende il piano: dopo lo storno dell\'incasso che lo fermava, il piano si ricalcola', function () {
    $s = spScenario(12, 'prima_rata', 0, ['proprietario' => 70, 'inquilino' => 30]);
    spIncassa($s, 1, 50.00);
    $ines = Anagrafica::where('nome', 'Ines Inquilina')->firstOrFail();
    $rigaInes = spRigaDi($s, $ines);
    $this->travel(1)->days();
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), [
        'tipo' => 'fine_locazione', 'tipologia' => 'inquilino', 'riga_uscente_id' => $rigaInes, 'decorrenza' => '2026-03-01', 'quota' => 100, 'copia_autentica' => false, 'pertinenze' => [],
        'ho_letto' => true, 'nota_cancello' => 'Disdetta letta, piano controllato',
    ])->assertSessionHasNoErrors()->assertRedirect();
    $p = \App\Models\Gestionale\Subentro::latest('id')->firstOrFail();
    expect($p->registro['piani_presi'])->toBe([]);
    $this->travel(1)->days();
    spStornaIncassi($s);

    // Prima: il piano restava fermo «per il conguaglio del passaggio Ines Inquilina → ?», che non c'è.
    $piano = $s['piano']->fresh();
    expect($piano->ragioniDelFermo())->toBe([])->and($piano->eImmutabile())->toBeFalse();
    spRicalcola($this, $s)->assertSessionHasNoErrors()->assertRedirect();
    // Ines paga il 30 % per i 59 giorni di gennaio e febbraio: 36000 × 59/365 = 5819,2 → 5819; il resto è di Ugo.
    expect([spQuoteDi($s['piano'], $ines), spQuoteDi($s['piano'], $s['v'])])->toBe([5819, 114181]);
});

it('W1 — dati della .41: la rata «emessa» senza scrittura che un passaggio di allora ha preso nel conguaglio non torna in bozza all\'emissione, e l\'esito lo dice; il piano resta preso, e la strada del messaggio porta alle cifre per giorni senza la coppia', function () {
    $s = spScenario(6, 'prima_rata', -67100);
    DB::table('rate')->where('id', spRata($s, 1))->update(['stato' => 'emessa', 'data_emissione' => '2026-01-13']);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), spVendita($s, '2026-02-07'))->assertSessionHasNoErrors()->assertRedirect();
    $p1 = \App\Models\Gestionale\Subentro::latest('id')->firstOrFail();
    $registro = $p1->registro;
    unset($registro['piani_presi']);
    $p1->update(['registro' => array_replace($registro, ['versione' => 1])]);
    foreach ([[$s['v']->id, -17973], [$s['a']->id, 17973]] as [$chi, $importo]) {
        Saldo::create(['esercizio_id' => $s['e']->id, 'condominio_id' => $s['c']->id, 'gestione_id' => $s['g']->id, 'immobile_id' => $s['unita']->id, 'anagrafica_id' => $chi,
            'saldo_iniziale' => $importo, 'origine' => 'automatico', 'is_applicato' => false, 'subentro_id' => $p1->id, 'descrizione' => 'Conguaglio della .41']);
    }
    // Il giorno dopo, sempre con la .41, la rata 2 va a giornale.
    $this->travel(1)->days();
    DB::table('rate')->where('id', spRata($s, 2))->update(['stato' => 'emessa', 'data_emissione' => '2026-02-08']);
    aGiornaleNeiTest((int) $s['piano']->id, [spRata($s, 2)]);
    $this->travel(1)->days();
    expect($s['piano']->fresh()->ragioniDelFermo())->toBe(['scrittura', 'conguaglio']);

    // Prima: la rata 1 tornava in bozza, il piano non risultava più preso, e il ricalcolo assorbiva la coppia (Elsa 125809).
    $esito = spEmetti($this, $s, [spRata($s, 1)], '2026-02-10')->getSession()->get('esito_emissione');
    expect(DB::table('rate')->where('id', spRata($s, 1))->value('stato'))->toBe('emessa')
        ->and($esito['testo'])->toBe('La rata 1 non ha quote da pagare e a giornale non c\'è niente da portare; resta segnata come emessa, perché il conguaglio del passaggio di Ugo Venditore su Interno 1, dal 7 febbraio 2026, registrato con una versione di prima, l\'ha presa così.')
        ->and($s['piano']->fresh()->conguagliato())->toBeTrue();

    // Annullata l'emissione della rata 2 (venuta dopo il passaggio), il piano resta preso, e il ricalcolo si rifiuta.
    $this->actingAs($this->user)->delete(route('admin.gestionale.piani-rate.annulla-emissione', ['condominio' => $s['c']->id, 'pianoRate' => $s['piano']->id, 'rata' => spRata($s, 2)]));
    expect($s['piano']->fresh()->ragioniDelFermo())->toBe(['conguaglio'])
        ->and(spRicalcola($this, $s)->getSession()->get('message')['message'])->toContain('ha preso questo piano nel conguaglio');

    // La strada: annullare il passaggio (la coppia se ne va con lui), registrarlo di nuovo, ricalcolare.
    spAnnullaPassaggio($this, $s, $p1)->assertRedirect();
    $this->travel(1)->days();
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), spVendita($s, '2026-02-07'))->assertSessionHasNoErrors()->assertRedirect();
    spRicalcola($this, $s)->assertSessionHasNoErrors()->assertRedirect();
    expect(spConti($s))->toBe(['ugo' => [12164, -67100, 0], 'elsa' => [107836, 0, 0]]);
});

it('W9 — due passaggi su due unità hanno preso il piano: la frase li nomina tutti, con l\'unità, e chiede di annullarli tutti prima di registrarne di nuovo uno; un passaggio alla volta il piano resta preso, tutti e due insieme si riapre e si ricalcola per giorni', function () {
    $s = spScenario(12, 'prima_rata', 0);
    // Una seconda unità di Carla, con gli stessi millesimi: il piano, ricalcolato, dà € 600,00 a ciascuna.
    $unita2 = Immobile::create(['condominio_id' => $s['c']->id, 'tipo' => 'appartamento', 'codice_immobile' => 'SP-2-' . $s['unita']->id, 'nome' => 'Interno 2', 'interno' => '2']);
    DB::table('quote_tabella')->insert(['tabella_id' => Tabella::where('condominio_id', $s['c']->id)->value('id'), 'immobile_id' => $unita2->id, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);
    $carla = spPersona($s, 'Carla Seconda', 'SPCARLASECOND001');
    $dino = spPersona($s, 'Dino Terzo', 'SPDINOTERZO00001');
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $carla->id, 'immobile_id' => $unita2->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    spRicalcola($this, $s)->assertSessionHasNoErrors();
    expect([spQuoteDi($s['piano'], $s['v']), spQuoteDi($s['piano'], $carla)])->toBe([60000, 60000]);

    spIncassa($s, 1, 50.00);
    $s2 = ['unita' => $unita2, 'rigaV' => (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $carla->id)->value('id'), 'a' => $dino] + $s;
    $vendi = fn (array $x, string $dal) => $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$x['c'], $x['unita']]), spVendita($x, $dal))->assertSessionHasNoErrors()->assertRedirect();
    $vendi($s, '2026-05-01');
    $pa = \App\Models\Gestionale\Subentro::latest('id')->firstOrFail();
    $this->travel(1)->minutes();
    $vendi($s2, '2026-06-01');
    $pb = \App\Models\Gestionale\Subentro::latest('id')->firstOrFail();
    $this->travel(1)->days();
    spStornaIncassi($s);
    expect($s['piano']->fresh()->ragioniDelFermo())->toBe(['conguaglio']);

    // Prima: la frase era al singolare, senza l'unità, e «dallo storico dell'unità» faceva pensare a quella del Wallet aperto.
    $frase = spRicalcola($this, $s)->getSession()->get('message')['message'];
    expect($frase)->toStartWith('Più passaggi di titolarità hanno preso questo piano nel conguaglio: Ugo Venditore → Elsa Acquirente, Interno 1, dal 1 maggio 2026; Carla Seconda → Dino Terzo, Interno 2, dal 1 giugno 2026.')
        ->toContain('annulla tutti quei passaggi, ognuno dallo storico della sua unità')->toContain('quando sono annullati tutti, registrali di nuovo e poi ricalcola il piano');

    // Un passaggio alla volta: annullato e registrato di nuovo quello di Interno 1, l'altro tiene il piano preso, e la nuova
    // registrazione lo riprende.
    $this->travel(1)->days();
    spAnnullaPassaggio($this, $s, $pa)->assertRedirect();
    $this->travel(1)->minutes();
    $vendi($s, '2026-05-01');
    $pa2 = \App\Models\Gestionale\Subentro::latest('id')->firstOrFail();
    expect($pa2->registro['piani_presi'])->toBe([$s['piano']->id])->and($s['piano']->fresh()->eImmutabile())->toBeTrue();

    // Tutti e due annullati, poi registrati di nuovo: il piano si riapre, e il ricalcolo divide per giorni. Interno 1: Ugo 120
    // giorni, 60000 × 120/365 = 19726; Interno 2: Carla 151 giorni, 60000 × 151/365 = 24822.
    $this->travel(1)->days();
    spAnnullaPassaggio($this, $s, $pa2)->assertRedirect();
    spAnnullaPassaggio($this, $s2, $pb)->assertRedirect();
    expect($s['piano']->fresh()->eImmutabile())->toBeFalse();
    $this->travel(1)->minutes();
    $vendi($s, '2026-05-01');
    $this->travel(1)->minutes();
    $vendi($s2, '2026-06-01');
    spRicalcola($this, $s)->assertSessionHasNoErrors()->assertRedirect();
    expect([spQuoteDi($s['piano'], $s['v']), spQuoteDi($s['piano'], $s['a']), spQuoteDi($s['piano'], $carla), spQuoteDi($s['piano'], $dino)])->toBe([19726, 40274, 24822, 35178])
        ->and(Saldo::whereNotNull('subentro_id')->count())->toBe(0);
});

it('W2, decisione 46 — con la rinuncia le parti hanno regolato fra loro € 5,48: il rifiuto, i rimedi e lo storico lo dicono con la cifra, e la strada della 43, seguita, rifà quei giorni nelle quote — è l\'accordo che va rifatto, e il programma lo ha detto', function () {
    $s = spScenario(12, 'prima_rata', 0);
    spIncassa($s, 1, 100.00);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), spVendita($s, '2026-05-01') + [
        'rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Le parti hanno regolato fra loro il conguaglio al rogito',
    ])->assertSessionHasNoErrors()->assertRedirect();
    $p1 = \App\Models\Gestionale\Subentro::latest('id')->firstOrFail();
    expect($p1->registro['regolato_fuori'])->toBe([['gestione_id' => (int) $s['g']->id, 'gestione' => 'Ordinaria 2026', 'importo' => 548]]);
    $this->travel(1)->days();
    spStornaIncassi($s);

    $avviso = 'Le parti hanno già regolato fra loro € 5,48 sulla gestione Ordinaria 2026: ricalcolando, il condominio addebita a chi entra i suoi giorni, e quell\'accordo va rifatto fra le parti.';
    $piano = $s['piano']->fresh();
    expect(spRicalcola($this, $s)->getSession()->get('message')['message'])->toEndWith(' ' . $avviso)
        ->and($piano->rimediDelFermo()[0])->toContain('— le parti hanno già regolato fra loro € 5,48 sulla gestione Ordinaria 2026');
    $storico = app(\App\Services\Subentro\StoricoTitolarita::class)->perImmobile($s['unita']->fresh());
    expect(implode(' | ', collect($storico['subentri'])->firstWhere('id', $p1->id)['annullabile']['avvisi']))
        ->toContain('Le parti hanno già regolato fra loro € 5,48 sulla gestione Ordinaria 2026: se il passaggio si registra di nuovo e il piano si ricalcola');

    // La strada, seguita: Elsa paga i suoi giorni nelle quote (80548), e i € 5,48 dati a Ugo al rogito vanno restituiti fra loro.
    spAnnullaPassaggio($this, $s, $p1)->assertRedirect();
    $this->travel(1)->days();
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), spVendita($s, '2026-05-01'))->assertSessionHasNoErrors()->assertRedirect();
    spRicalcola($this, $s)->assertSessionHasNoErrors()->assertRedirect();
    expect(spConti($s))->toBe(['ugo' => [39452, 0, 0], 'elsa' => [80548, 0, 0]]);
});

it('X3 — passaggio della .41 preso solo in parte, la sua coppia assorbita da un altro piano già emesso: l\'annullamento del passaggio non propone la strada breve, perché il piano preso deve ancora seguire il passaggio e non si emette', function () {
    $s = spScenario(6, 'prima_rata', -67100);
    DB::table('rate')->where('id', spRata($s, 1))->update(['stato' => 'emessa', 'data_emissione' => '2026-01-13']);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), spVendita($s, '2026-02-07'))->assertSessionHasNoErrors()->assertRedirect();
    $p1 = \App\Models\Gestionale\Subentro::latest('id')->firstOrFail();
    $registro = $p1->registro;
    unset($registro['piani_presi']);
    $p1->update(['registro' => array_replace($registro, ['versione' => 1])]);
    foreach ([[$s['v']->id, -17973], [$s['a']->id, 17973]] as [$chi, $importo]) {
        Saldo::create(['esercizio_id' => $s['e']->id, 'condominio_id' => $s['c']->id, 'gestione_id' => $s['g']->id, 'immobile_id' => $s['unita']->id, 'anagrafica_id' => $chi,
            'saldo_iniziale' => $importo, 'origine' => 'automatico', 'is_applicato' => false, 'subentro_id' => $p1->id, 'descrizione' => 'Conguaglio della .41']);
    }
    $this->travel(1)->days();
    $b = spSecondoPiano($s, 60000);
    expect(Saldo::where('subentro_id', $p1->id)->where('is_applicato', true)->where('piano_rate_id', $b->id)->count())->toBe(2);
    spEmetti($this, ['piano' => $b] + $s, [(int) DB::table('rate')->where('piano_rate_id', $b->id)->where('numero_rata', 1)->value('id')])->assertSessionHasNoErrors();
    expect(collect($s['piano']->fresh()->passaggiDaSeguire())->pluck('id')->all())->toBe([$p1->id]);

    // Prima: «Il passaggio può restare: i piani che ha preso si emettono così come sono…», e il Preventivo rifiutava l'emissione.
    expect(spAnnullaPassaggio($this, $s, $p1)->assertUnprocessable()->json('errors.passaggio.0'))
        ->not->toContain('Il passaggio può restare')
        ->toContain('Il passaggio non può restare così: il piano «Preventivo 2026», preso da questo passaggio, deve ancora seguire un passaggio e non si emette senza ricalcolo.')
        ->toContain('Per annullarlo, il piano «Riscaldamento 2026»');
});

it('rilievo T1 del quarto giro — un passaggio registrato prima della .42, con rate emesse prima e una dopo: i rimedi, seguiti nell\'ordine scritto, passano tutti e portano alle cifre per giorni', function () {
    $s = spScenario(12, 'prima_rata', 0);
    spEmetti($this, $s, [spRata($s, 1), spRata($s, 2), spRata($s, 3), spRata($s, 4)])->assertSessionHasNoErrors();
    $this->travel(1)->minutes();
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), spVendita($s, '2026-05-01'))->assertSessionHasNoErrors()->assertRedirect();
    $p1 = \App\Models\Gestionale\Subentro::latest('id')->firstOrFail();
    $registro = $p1->registro;
    unset($registro['piani_presi']);
    $p1->update(['registro' => array_replace($registro, ['versione' => 1])]);
    $this->travel(1)->minutes();
    spEmetti($this, $s, [spRata($s, 5)], '2026-05-05')->assertSessionHasNoErrors();
    $piano = $s['piano']->fresh();
    expect($piano->ragioniDelFermo())->toBe(['scrittura', 'conguaglio'])
        ->and($piano->rimediDelFermo())->toBe([
            'annulla le emissioni venute dopo quel passaggio, se ce ne sono',
            'poi annulla quel passaggio dallo storico della sua unità («Passaggi registrati», dall\'ultimo)',
            'poi annulla le altre emissioni dalla pagina del piano, se non hanno incassi',
            'registralo di nuovo',
        ]);

    // Seguiti nell'ordine: la rata 5, il passaggio, le rate 1–4, la nuova registrazione. Prima il primo rimedio era il passaggio,
    // che si rifiutava per la rata 5; e l'ordine opposto lo rifiutava la guardia della 49.
    $annullaEmissione = fn (int $rata) => $this->actingAs($this->user)->delete(route('admin.gestionale.piani-rate.annulla-emissione', ['condominio' => $s['c']->id, 'pianoRate' => $s['piano']->id, 'rata' => $rata]))->getSession()->get('message')['type'];
    expect($annullaEmissione(spRata($s, 5)))->toBe('success');
    $this->travel(1)->minutes();
    spAnnullaPassaggio($this, $s, $p1)->assertRedirect();
    foreach ([1, 2, 3, 4] as $n) {
        expect($annullaEmissione(spRata($s, $n)))->toBe('success');
    }
    $this->travel(1)->minutes();
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), spVendita($s, '2026-05-01'))->assertSessionHasNoErrors()->assertRedirect();
    spRicalcola($this, $s)->assertSessionHasNoErrors()->assertRedirect();
    expect(spConti($s))->toBe(['ugo' => [39452, 0, 0], 'elsa' => [80548, 0, 0]]);
});

it('verifica a video della .42 — la pagina del piano porta le ragioni del fermo e i rimedi una voce per riga, senza il «poi» che serve solo in fila', function () {
    // La prop `fermo_del_piano` di `PianoRateController::show` è fatta di questi due metodi; la pagina non si apre qui, perché la
    // risorsa del piano usa `JSON_UNQUOTE`, che SQLite non ha (lo stesso limite di `ScopingDelleRotteAnnidateTest`).
    $s = spScenario(12, 'prima_rata', 0);
    spEmetti($this, $s, [spRata($s, 1)], '2026-01-05')->assertSessionHasNoErrors();
    $this->travel(1)->minutes();
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), spVendita($s, '2026-05-01'))->assertSessionHasNoErrors()->assertRedirect();
    $passaggio = 'Ugo Venditore → Elsa Acquirente, Interno 1, dal 1 maggio 2026';
    $annulla = 'annulla quel passaggio dallo storico della sua unità («Passaggi registrati», dall\'ultimo)';

    // Un passaggio della .42: le emissioni e il passaggio, nell'ordine dei rilievi T6 e W9; la prop è `fermoPerLaPagina()`.
    $piano = $s['piano']->fresh();
    expect($piano->fermoPerLaPagina())->toBe([
        'ragioni' => ['ha già quote a giornale', 'è stato preso nel conguaglio di questo passaggio'],
        'passaggi' => [$passaggio],
        'rimedi' => ['annulla le emissioni dalla pagina del piano, se non hanno incassi', $annulla . ' e registralo di nuovo'],
    ]);
    expect($piano->ragioniInParole())->toBe(['ha già quote a giornale', 'è stato preso nel conguaglio del passaggio ' . $passaggio])
        ->and($piano->fraseDelFermo())->toBe('ha già quote a giornale ed è stato preso nel conguaglio del passaggio ' . $passaggio)
        // Per l'avviso del ricalcolo: il passaggio va in elenco sotto la ragione, non in fila.
        ->and($piano->ragioniInParole(passaggiAParte: true))->toBe(['ha già quote a giornale', 'è stato preso nel conguaglio di questo passaggio'])
        ->and(PianoRate::vociDeiPassaggi($piano->passaggiDaAnnullare()))->toBe([$passaggio])
        ->and($piano->rimediDelFermo(inElenco: true))->toBe(['annulla le emissioni dalla pagina del piano, se non hanno incassi', $annulla . ' e registralo di nuovo']);

    // Lo stesso passaggio registrato prima della .42 (senza `piani_presi`): l'ordine del rilievo T1 del quarto giro, in passi
    // numerati senza «poi»; in fila, per il Wallet e l'incasso, il «poi» resta.
    $p = \App\Models\Gestionale\Subentro::sole();
    $registro = $p->registro;
    unset($registro['piani_presi']);
    $p->update(['registro' => array_replace($registro, ['versione' => 1])]);
    $piano = $s['piano']->fresh();
    expect($piano->rimediDelFermo(inElenco: true))->toBe(['annulla le emissioni venute dopo quel passaggio, se ce ne sono', $annulla, 'annulla le altre emissioni dalla pagina del piano, se non hanno incassi', 'registralo di nuovo'])
        ->and($piano->rimediDelFermo()[1])->toBe('poi ' . $annulla);

    // Un piano che si riscrive non ha ragioni: la prop è null.
    $libero = spSecondoPiano($s, 60000);
    expect($libero->ragioniInParole())->toBe([])->and($libero->fermoPerLaPagina())->toBeNull();
});

it('revisione della verifica a video — il rifiuto dell\'annullamento con due passaggi di prima della .42 accorda tutto al plurale, e con uno regolato fra le parti dice tutte e due le cose', function () {
    $s = spScenario(12, 'prima_rata', 0);
    spEmetti($this, $s, [spRata($s, 1)], '2026-01-05')->assertSessionHasNoErrors();
    $this->travel(1)->minutes();
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), spVendita($s, '2026-05-01'))->assertSessionHasNoErrors()->assertRedirect();
    $this->travel(1)->minutes();
    $zeta = spPersona($s, 'Zeta Terza', 'SPZETA00R00000001');
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), spVenditaDa($s, $s['a'], $zeta, '2026-09-01'))->assertSessionHasNoErrors()->assertRedirect();
    // Tutti e due registrati come prima della .42: senza `piani_presi`, la guardia della decisione 49 li conta.
    foreach (\App\Models\Gestionale\Subentro::orderBy('id')->get() as $p) {
        $registro = $p->registro;
        unset($registro['piani_presi']);
        $p->update(['registro' => array_replace($registro, ['versione' => 1])]);
    }
    $annulla = fn () => $this->actingAs($this->user)->delete(route('admin.gestionale.piani-rate.annulla-emissione', ['condominio' => $s['c']->id, 'pianoRate' => $s['piano']->id, 'rata' => spRata($s, 1)]))->getSession()->get('message');

    $m = $annulla();
    expect($m['type'])->toBe('error')
        ->and($m['message'])->toStartWith("Più passaggi di titolarità registrati dopo l'emissione hanno preso questo piano nel conguaglio:\n• Ugo Venditore → Elsa Acquirente, Interno 1, dal 1 maggio 2026\n• Elsa Acquirente → Zeta Terza, Interno 1, dal 1 settembre 2026\n\n")
        ->toContain("Sono passaggi registrati prima della versione 1.11.0-beta.42: il piano risulta preso grazie alle quote già a giornale. Annullare l'emissione lo riaprirebbe al ricalcolo, che rifarebbe per giorni ciò che quei conguagli hanno già regolato.")
        ->toContain("\n1. Annulla tutti quei passaggi, ognuno dallo storico della sua unità")
        ->toContain("\n3. Solo a questo punto registrali di nuovo.")
        ->and(DB::table('rate')->where('id', spRata($s, 1))->value('stato'))->toBe('emessa');

    // Il primo con la rinuncia: una parte l'hanno regolata le parti, l'altra il conguaglio.
    \App\Models\Gestionale\Subentro::orderBy('id')->first()->update(['nota_conguaglio' => 'Le parti regolano fra loro']);
    expect(\App\Models\Gestionale\Subentro::orderBy('id')->first()->conguaglioRinunciato())->toBeTrue()
        ->and($annulla()['message'])->toContain('che rifarebbe per giorni ciò che quei conguagli, o le parti fra loro, hanno già regolato.');
});
