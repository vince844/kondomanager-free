<?php

/**
 * 1.11.0-beta.37 — l'annullamento di un passaggio registrato (decisione 27 del progetto sul subentro).
 *
 * Fino alla beta.36 un passaggio non si annullava (decisione 24): si correggeva chiudendo e riassociando a mano. Dalla
 * beta.37 si annulla **l'ultimo passaggio dell'unità**, **a rate intatte**, e l'annullamento rimette le righe di
 * titolarità, le bozze passate a chi è entrato e il conguaglio come erano prima. Decisioni di Vincenzo del 28/09/2026:
 *
 * 1. solo l'ultimo passaggio dell'unità (e di ciascuna pertinenza), e solo se dopo il passaggio chi è entrato non ha
 *    rate emesse né pagamenti sulle rate passate a lui; altrimenti il messaggio dice la via, come la scala dello storno;
 * 2. bloccano anche un pagamento segnalato dal portale e non ancora verificato, e una riga corretta a mano dopo il
 *    passaggio; blocca per costruzione il saldo del conguaglio già assorbito da un piano;
 * 3. il passaggio annullato **resta nello storico**, annullato, con data, autore e nota, e nessun conto lo legge più;
 * 4. dalla beta.37 ogni passaggio scrive un **registro** di ciò che ha fatto, con i valori di prima e di dopo, e
 *    l'annullamento lo rilegge al contrario; i passaggi registrati prima non si annullano.
 *
 * Il flusso è quello vero: il piano generato a gennaio con il solo venditore, le prime quattro rate emesse a giornale,
 * la vendita registrata dalla rotta, l'annullamento dalla rotta.
 *
 * **Cosa resta scoperto** (regola «ogni test dichiara cosa NON copre»): l'emissione è simulata a giornale come in
 * `RiassegnazioneBozzeTest`, non passa da `EmissioneRateController`; il documento allegato al passaggio (resta in
 * archivio: nessun test lo tocca). La vendita con riserva d'usufrutto (beta.38) non è qui: l'annullamento semplice, le
 * seconde riserve e l'annullamento dopo un piano generato ed emesso sono in `RiservaUsufruttoTest`, le catene annullate
 * all'indietro nella griglia B di `InvariantiPassaggiTest`.
 *
 * **Le corse fra scritture** (Fase 1-bis, A2, e giro di verifica, C-R1–C-R6) non si provano qui: SQLite serializza gli
 * scrittori, e i lock non cambiano niente. Sono state provate a mano il 28/09/2026 su un MySQL 8.4 privato, con due
 * processi e il codice vero: registrazione in volo e annullamento (l'annullamento aspetta e nomina il passaggio nuovo),
 * anche con la registrazione ferma fra il lock dell'unità e la scrittura (prima delle correzioni: stallo, errore 1213);
 * annullamento in volo e registrazione («il periodo di chi esce non esiste più»); emissione e annullamento nei due ordini
 * (la quota emessa è di chi la possiede quando si emette; l'annullamento aspetta e rifiuta); «Associa» in volo e
 * annullamento (rifiuta con la riga nuova); annullamento in volo e «Associa» validato prima (le guardie ripassate sotto
 * lock rifiutano). Le letture bloccanti usano un indice (EXPLAIN con 3000 promemoria). Non provate su MySQL: la
 * generazione del piano (il lock sulle unità del condominio, e `sincronizzaLucchetti` che si ferma) e la segnalazione dal
 * portale in volo (riletta sotto lock): sono provate solo per la strada normale, qui sotto.
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Evento;
use App\Models\Gestione;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Subentro;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Models\Tabella;
use App\Models\User;
use App\Services\Subentro\NotaSolidarieta;
use App\Services\Subentro\StoricoTitolarita;
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

/*
|--------------------------------------------------------------------------
| Helper (prefisso `ap`: Pest carica tutti i file nello stesso processo)
|--------------------------------------------------------------------------
*/

/**
 * Il condominio del forum (lo stesso di `RiassegnazioneBozzeTest`): un'unità, preventivo ordinario da € 1.200,00 in
 * dodici rate mensili il 5 di ogni mese, generato a gennaio con il solo venditore e il suo saldo pregresso.
 *
 * @return array{c: Condominio, e: Esercizio, g: Gestione, unita: Immobile, v: Anagrafica, a: Anagrafica, b: Anagrafica, rigaV: int, piano: PianoRate, r: ?Anagrafica, rigaR: ?int, unita2: ?Immobile, d: ?Anagrafica}
 */
function apScenario(string $metodo = 'prima_rata', int $saldoVenditore = 0, bool $comproprietario = false, ?string $interno2 = null, array $ripartizione = ['proprietario' => 100]): array
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
    $unita = Immobile::create(['condominio_id' => $c->id, 'tipo' => 'appartamento', 'codice_immobile' => "AP-{$seq}", 'nome' => 'Interno 1', 'interno' => '1']);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $unita->id, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);

    $persona = function (string $nome, string $sigla) use ($c, $seq) {
        $p = Anagrafica::forceCreate(['nome' => $nome, 'email' => "ap-{$sigla}{$seq}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'AP' . strtoupper($sigla) . 'PERSONA' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT)]);
        $p->condomini()->syncWithoutDetaching([$c->id]);

        return $p;
    };
    $v = $persona('Venditore Ugo', 'v');
    $a = $persona('Acquirente Elsa', 'a');
    $b = $persona('Compratrice Bice', 'b');
    $rigaV = DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $v->id, 'immobile_id' => $unita->id, 'tipologia' => 'proprietario', 'quota' => $comproprietario ? 50 : 100, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    // Con un comproprietario al 50 %, il saldo pregresso è suo: le sue bozze, passando, lasciano una gemella a lui.
    $r = null;
    $rigaR = null;
    if ($comproprietario) {
        $r = $persona('Terzo Rino', 'r');
        $rigaR = DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $r->id, 'immobile_id' => $unita->id, 'tipologia' => 'proprietario', 'quota' => 50, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    }

    // Un secondo appartamento nello stesso piano: di chi vende (`'v'`) o di un altro condòmino (`'d'`, Condòmino Dino).
    $unita2 = null;
    $d = null;
    if ($interno2 !== null) {
        $unita2 = Immobile::create(['condominio_id' => $c->id, 'tipo' => 'appartamento', 'codice_immobile' => "AP2-{$seq}", 'nome' => 'Interno 2', 'interno' => '2']);
        DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $unita2->id, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);
        $d = $interno2 === 'd' ? $persona('Condòmino Dino', 'd') : $v;
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $d->id, 'immobile_id' => $unita2->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    }

    if ($saldoVenditore !== 0) {
        Saldo::create(['esercizio_id' => $e->id, 'condominio_id' => $c->id, 'anagrafica_id' => ($r ?? $v)->id, 'immobile_id' => $unita->id, 'gestione_id' => $g->id, 'saldo_iniziale' => $saldoVenditore, 'origine' => 'manuale', 'is_applicato' => false]);
    }

    $piano = PianoRate::create([
        'gestione_id' => $g->id, 'condominio_id' => $c->id, 'esercizio_id' => $e->id, 'nome' => 'Preventivo 2026', 'stato' => 'approvato',
        'tipo' => 'ordinario', 'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05',
        'metodo_distribuzione' => $metodo, 'applica_saldi' => true,
    ]);
    app(GeneratePianoRateAction::class)->execute($piano, forzaApplicazioneSaldi: true, esercizio: $e);

    return compact('c', 'e', 'g', 'unita', 'v', 'a', 'b', 'rigaV', 'piano', 'r', 'rigaR', 'unita2', 'd');
}

/** Emette a giornale le rate del piano con scadenza fino al giorno dato (di norma le prime quattro). */
function apEmetti(array $s, string $fino = '2026-04-30', ?int $soloRata = null): void
{
    $scritturaId = DB::table('scritture_contabili')->insertGetId([
        'condominio_id' => $s['c']->id, 'gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id, 'data_registrazione' => '2026-01-05', 'data_competenza' => '2026-01-05',
        'numero_protocollo' => 'EMI-AP-' . $s['piano']->id . '-' . $fino . '-' . ($soloRata ?? 'x'), 'causale' => 'emissione rate', 'descrizione' => 'emissione', 'tipo_movimento' => 'emissione_rate', 'stato' => 'registrata',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $rate = DB::table('rate')->where('piano_rate_id', $s['piano']->id)
        ->when($soloRata !== null, fn ($q) => $q->where('numero_rata', $soloRata), fn ($q) => $q->where('data_scadenza', '<=', $fino . ' 23:59:59'))
        ->pluck('id');
    DB::table('rate')->whereIn('id', $rate)->update(['stato' => 'emessa', 'data_emissione' => '2026-01-05']);
    DB::table('rate_quote')->whereIn('rata_id', $rate)->update(['scrittura_contabile_id' => $scritturaId]);
}

function apVendita(int $rigaUscente, Anagrafica $entrante, string $decorrenza = '2026-05-01', array $extra = []): array
{
    return array_merge([
        'tipo' => 'vendita', 'riga_uscente_id' => $rigaUscente, 'anagrafica_entrante_id' => $entrante->id, 'decorrenza' => $decorrenza,
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => $decorrenza,
        'estremi_titolo' => 'atto notaio Verdi, rep. 12345', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Rogito letto, bozze a chi compra',
    ], $extra);
}

function apRegistra($test, array $s, array $dati, ?Immobile $unita = null): Subentro
{
    $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $unita ?? $s['unita']]), $dati)
        ->assertSessionHasNoErrors()->assertRedirect();

    return Subentro::whereNull('subentro_padre_id')->latest('id')->firstOrFail();
}

function apAnnulla($test, array $s, Subentro $subentro, string $nota = 'Registrato con la data sbagliata, lo rifaccio', ?Immobile $unita = null)
{
    return $test->actingAs($test->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $unita ?? $s['unita'], $subentro]), ['nota_annullamento' => $nota]);
}

/** Le righe di titolarità delle unità, senza id né timestamp: ciò che deve tornare identico. */
function apFotoRighe(array $immobileIds): array
{
    return DB::table('anagrafica_immobile')->whereIn('immobile_id', $immobileIds)->get()
        ->map(fn ($r) => [(int) $r->immobile_id, (int) $r->anagrafica_id, $r->tipologia, round((float) $r->quota, 2), (string) $r->data_inizio, $r->data_fine === null ? null : (string) $r->data_fine, (bool) $r->attivo])
        ->sort()->values()->all();
}

/** Le quote del piano, con le regole congelate: id compreso, perché devono tornare le stesse righe. */
function apFotoQuote(PianoRate $piano): array
{
    return DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)
        ->orderBy('rate_quote.id')
        ->get(['rate_quote.id', 'rate_quote.rata_id', 'rate_quote.anagrafica_id', 'rate_quote.immobile_id', 'rate_quote.importo', 'rate_quote.importo_pagato', 'rate_quote.stato', 'rate_quote.tipo', 'rate_quote.regole_calcolo', 'rate_quote.scrittura_contabile_id'])
        ->map(fn ($q) => (array) $q)->all();
}

function apQuoteDi(PianoRate $piano, Anagrafica $persona): \Illuminate\Support\Collection
{
    return DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)
        ->where('rate_quote.anagrafica_id', $persona->id)->get(['rate_quote.*', 'rate.numero_rata']);
}

/*
|--------------------------------------------------------------------------
| Il caso di base: tutto torna com'era
|--------------------------------------------------------------------------
*/

it('vendita il 1° maggio con quattro rate emesse: l\'annullamento rimette righe, bozze e conguaglio esattamente come prima del passaggio, qualunque sia il metodo dei saldi', function (string $metodo, int $saldo) {
    $s = apScenario($metodo, $saldo);
    apEmetti($s);
    $righePrima = apFotoRighe([$s['unita']->id]);
    $quotePrima = apFotoQuote($s['piano']);
    $saldiPrima = Saldo::orderBy('id')->get(['id', 'anagrafica_id', 'saldo_iniziale', 'subentro_id'])->toArray();

    $subentro = apRegistra($this, $s, apVendita($s['rigaV'], $s['a']));
    expect(apQuoteDi($s['piano'], $s['a']))->toHaveCount(8)
        ->and(Saldo::where('subentro_id', $subentro->id)->count())->toBe(2);

    apAnnulla($this, $s, $subentro)->assertSessionHasNoErrors()->assertRedirect();

    expect(apFotoRighe([$s['unita']->id]))->toBe($righePrima)
        ->and(apFotoQuote($s['piano']))->toBe($quotePrima)
        ->and(Saldo::orderBy('id')->get(['id', 'anagrafica_id', 'saldo_iniziale', 'subentro_id'])->toArray())->toBe($saldiPrima);

    $annullato = Subentro::withoutGlobalScopes()->findOrFail($subentro->id);
    expect($annullato->annullato_il)->not->toBeNull()
        ->and((int) $annullato->annullato_da)->toBe($this->user->id)
        ->and($annullato->nota_annullamento)->toBe('Registrato con la data sbagliata, lo rifaccio');
})->with([
    'nessun saldo' => ['prima_rata', 0],
    'spalmati, venditore a debito € 180,00: la quota divisa torna una' => ['tutte_rate', 18000],
    'spalmati, venditore a credito € 60,00' => ['tutte_rate', -6000],
    'rata zero, venditore a debito' => ['rata_zero', 18000],
]);

it('il passaggio registrato dalla beta.37 porta il suo registro: righe chiuse e aperte, quote riassegnate, con i valori di prima', function () {
    $s = apScenario('tutte_rate', 18000);
    apEmetti($s);
    $subentro = apRegistra($this, $s, apVendita($s['rigaV'], $s['a']));

    $registro = Subentro::withoutGlobalScopes()->findOrFail($subentro->id)->registro;
    expect($registro)->toBeArray()
        ->and(collect($registro['righe'] ?? [])->pluck('operazione')->all())->toContain('chiusa', 'aperta')
        ->and(collect($registro['quote'] ?? []))->toHaveCount(8)
        ->and(collect($registro['quote'])->first())->toHaveKeys(['id', 'prima'])
        ->and(collect($registro['quote'])->whereNotNull('gemella_id'))->toHaveCount(8);
});

/*
|--------------------------------------------------------------------------
| Dopo l'annullamento nessun lettore vede il passaggio, e lo storico lo mostra annullato
|--------------------------------------------------------------------------
*/

it('il passaggio annullato resta nello storico, annullato; la nota di solidarietà sparisce; la riga di chi vendeva si può passare di nuovo', function () {
    $s = apScenario();
    apEmetti($s);
    $subentro = apRegistra($this, $s, apVendita($s['rigaV'], $s['a']));
    expect(app(NotaSolidarieta::class)->per($s['c'], null, $s['unita']))->not->toBeEmpty();

    apAnnulla($this, $s, $subentro)->assertRedirect();

    $storico = app(StoricoTitolarita::class)->perImmobile($s['unita']->fresh());
    $voce = collect($storico['subentri'])->firstWhere('id', $subentro->id);
    expect($voce)->not->toBeNull()
        ->and($voce['annullato'])->toBeTrue()
        ->and($voce['nota_annullamento'])->toBe('Registrato con la data sbagliata, lo rifaccio')
        ->and($voce['annullato_da'])->toBe($this->user->name) // decisione 27.4: chi l'ha annullato (Fase 1-bis A6)
        ->and($voce['annullabile']['si'])->toBeFalse()
        ->and($storico['passaggi'])->toBe(0) // visto a video: il pulsante contava anche l'annullato
        ->and(app(NotaSolidarieta::class)->per($s['c'], null, $s['unita']->fresh()))->toBeEmpty()
        ->and(Subentro::where('immobile_id', $s['unita']->id)->count())->toBe(0);

    // Lo si rifà con la data giusta: la riga di chi vendeva non risulta più «chiusa da un passaggio registrato».
    $rifatto = apRegistra($this, $s, apVendita($s['rigaV'], $s['a'], '2026-06-01'));
    expect($rifatto->id)->not->toBe($subentro->id)
        ->and(DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->value('data_fine'))->toBe('2026-05-31');
});

/*
|--------------------------------------------------------------------------
| Solo l'ultimo passaggio
|--------------------------------------------------------------------------
*/

it('si annulla solo l\'ultimo passaggio dell\'unità: il primo di una catena si rifiuta e nomina quello dopo; annullato il secondo, il primo torna annullabile', function () {
    $s = apScenario();
    apEmetti($s);
    $primo = apRegistra($this, $s, apVendita($s['rigaV'], $s['a']));
    $rigaA = (int) $primo->riga_entrante_id;
    $secondo = apRegistra($this, $s, apVendita($rigaA, $s['b'], '2026-09-01'));

    apAnnulla($this, $s, $primo)->assertUnprocessable()->assertJsonValidationErrors('passaggio');
    expect(apAnnulla($this, $s, $primo)->json('errors.passaggio.0'))->toContain('Compratrice Bice')->toContain('1 settembre 2026');

    apAnnulla($this, $s, $secondo)->assertRedirect();
    $storico = app(StoricoTitolarita::class)->perImmobile($s['unita']->fresh());
    expect(collect($storico['subentri'])->firstWhere('id', $primo->id)['annullabile']['si'])->toBeTrue();
    apAnnulla($this, $s, $primo)->assertRedirect();
    expect(DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->value('data_fine'))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| A rate intatte
|--------------------------------------------------------------------------
*/

it('una rata passata a chi è entrato ed emessa dopo il passaggio ferma l\'annullamento, dice quale e la via, e non scrive niente', function () {
    $s = apScenario();
    apEmetti($s);
    $subentro = apRegistra($this, $s, apVendita($s['rigaV'], $s['a']));
    apEmetti($s, soloRata: 5);
    $righe = apFotoRighe([$s['unita']->id]);
    $quote = apFotoQuote($s['piano']);

    $messaggio = apAnnulla($this, $s, $subentro)->assertUnprocessable()->json('errors.passaggio.0');
    expect($messaggio)->toContain('la rata 5 del piano «Preventivo 2026»')->toContain('Acquirente Elsa')->toContain('Annulla l\'emissione di quella rata')
        // Rilievo W8 del giro sulle correzioni della .42: il consiglio «annulla prima il conguaglio» non c'è più — la rata 5,
        // emessa dopo il passaggio, non la blocca il passaggio stesso, e annullare il conguaglio non riapre niente (decisione 43).
        ->not->toContain('annulla prima il conguaglio')
        ->and(apFotoRighe([$s['unita']->id]))->toBe($righe)
        ->and(apFotoQuote($s['piano']))->toBe($quote)
        ->and(Subentro::withoutGlobalScopes()->findOrFail($subentro->id)->annullato_il)->toBeNull();

    // La strada del messaggio, fino in fondo: annullata l'emissione della rata 5 (la guardia non la rifiuta), il passaggio si annulla.
    $rata5 = (int) DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('numero_rata', 5)->value('id');
    $r = $this->actingAs($this->user)->delete(route('admin.gestionale.piani-rate.annulla-emissione', ['condominio' => $s['c']->id, 'pianoRate' => $s['piano']->id, 'rata' => $rata5]));
    expect($r->getSession()->get('message')['type'])->toBe('success')->and(DB::table('rate')->where('id', $rata5)->value('stato'))->toBe('bozza');
    apAnnulla($this, $s, $subentro)->assertRedirect();
    expect(Subentro::withoutGlobalScopes()->findOrFail($subentro->id)->annullato_il)->not->toBeNull();
});

it('un pagamento su una rata passata a chi è entrato ferma l\'annullamento', function () {
    $s = apScenario();
    apEmetti($s);
    $subentro = apRegistra($this, $s, apVendita($s['rigaV'], $s['a']));
    $rata6 = (int) DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('numero_rata', 6)->value('id');
    DB::table('rate_quote')->where('rata_id', $rata6)->where('anagrafica_id', $s['a']->id)->update(['importo_pagato' => 5000, 'stato' => 'parzialmente_pagata']);

    expect(apAnnulla($this, $s, $subentro)->assertUnprocessable()->json('errors.passaggio.0'))->toContain('pagamento');
});

it('un pagamento segnalato dal portale e non ancora verificato ferma l\'annullamento come un pagamento', function () {
    $s = apScenario();
    (new \App\Listeners\Gestionale\SyncScadenziarioWithPianoRate())->handle(new \App\Events\Gestionale\PianoRateStatusUpdated($s['c'], $s['e'], $s['piano'], $this->user, \App\Enums\StatoPianoRate::BOZZA, \App\Enums\StatoPianoRate::APPROVATO));
    apEmetti($s);
    $subentro = apRegistra($this, $s, apVendita($s['rigaV'], $s['a']));

    $rata7 = (int) DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('numero_rata', 7)->value('id');
    $evento = Evento::whereJsonContains('meta->context->rata_id', $rata7)->where('tipo', \App\Enums\EventoTipo::SCADENZA_RATA_CONDOMINO->value)->firstOrFail();
    $meta = $evento->meta;
    $meta['status'] = 'reported';
    $evento->meta = $meta;
    $evento->save();

    expect(apAnnulla($this, $s, $subentro)->assertUnprocessable()->json('errors.passaggio.0'))->toContain('segnalato dal portale');
});

it('una rata emessa a chi è entrato da un piano ricalcolato dopo il passaggio ferma l\'annullamento (lettura stretta di «rate intatte»)', function () {
    $s = apScenario();
    apEmetti($s);
    $subentro = apRegistra($this, $s, apVendita($s['rigaV'], $s['a']));

    // Un secondo piano, generato dopo il passaggio sull'unità di Elsa, con una rata emessa.
    $piano2 = DB::table('piani_rate')->insertGetId([
        'gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'nome' => 'Conguaglio luglio', 'numero_rate' => 1, 'giorno_scadenza' => 5,
        'metodo_distribuzione' => 'tutte_rate', 'attivo' => true, 'stato' => 'approvato', 'tipo' => 'ordinario', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $scrittura = DB::table('scritture_contabili')->insertGetId([
        'condominio_id' => $s['c']->id, 'gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id, 'data_registrazione' => '2026-07-01', 'data_competenza' => '2026-07-01',
        'numero_protocollo' => 'EMI-AP-LUGLIO', 'causale' => 'emissione rate', 'descrizione' => 'emissione', 'tipo_movimento' => 'emissione_rate', 'stato' => 'registrata', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $rata = DB::table('rate')->insertGetId(['piano_rate_id' => $piano2, 'numero_rata' => 1, 'data_scadenza' => '2026-07-05', 'data_emissione' => '2026-07-01', 'importo_totale' => 3000, 'stato' => 'emessa', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('rate_quote')->insert([
        'rata_id' => $rata, 'anagrafica_id' => $s['a']->id, 'immobile_id' => $s['unita']->id, 'importo' => 3000, 'importo_pagato' => 0, 'stato' => 'da_pagare', 'tipo' => 'ordinaria',
        'data_scadenza' => '2026-07-05', 'scrittura_contabile_id' => $scrittura, 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(apAnnulla($this, $s, $subentro)->assertUnprocessable()->json('errors.passaggio.0'))->toContain('Conguaglio luglio');
});

it('una riga corretta a mano dopo il passaggio ferma l\'annullamento, invece di rimettere valori che non sono più quelli giusti', function () {
    $s = apScenario();
    apEmetti($s);
    $subentro = apRegistra($this, $s, apVendita($s['rigaV'], $s['a']));
    DB::table('anagrafica_immobile')->where('id', $subentro->riga_entrante_id)->update(['quota' => 50, 'updated_at' => now()->addMinute()]);

    expect(apAnnulla($this, $s, $subentro)->assertUnprocessable()->json('errors.passaggio.0'))->toContain('modificat')->toContain('Acquirente Elsa');
});

it('il conguaglio già assorbito da un piano ferma l\'annullamento con la via, come per l\'annullamento del solo conguaglio', function () {
    $s = apScenario();
    apEmetti($s);
    $subentro = apRegistra($this, $s, apVendita($s['rigaV'], $s['a']));
    Saldo::where('subentro_id', $subentro->id)->update(['is_applicato' => true, 'piano_rate_id' => $s['piano']->id]);

    // Lo storico lo dice prima (motivoBlocco); l'azione lo ricontrolla sulle righe bloccate.
    expect(collect(app(StoricoTitolarita::class)->perImmobile($s['unita']->fresh())['subentri'])->firstWhere('id', $subentro->id)['annullabile']['motivo'])->toContain('assorbito');
    expect(apAnnulla($this, $s, $subentro)->assertUnprocessable()->json('errors.passaggio.0'))->toContain('assorbito')->toContain('Preventivo 2026');
});

/*
|--------------------------------------------------------------------------
| Passaggi di prima, nota, doppio annullamento
|--------------------------------------------------------------------------
*/

it('un passaggio registrato prima della beta.37, senza registro, non si annulla: il messaggio dice di correggerlo a mano', function () {
    $s = apScenario();
    apEmetti($s);
    $subentro = apRegistra($this, $s, apVendita($s['rigaV'], $s['a']));
    DB::table('subentri')->where('id', $subentro->id)->update(['registro' => null]);

    expect(apAnnulla($this, $s, $subentro)->assertUnprocessable()->json('errors.passaggio.0'))->toContain('prima della 1.11.0-beta.37')->toContain('a mano');
});

it('la nota dell\'annullamento è obbligatoria, di almeno dieci caratteri, e un passaggio annullato non si annulla una seconda volta', function () {
    $s = apScenario();
    apEmetti($s);
    $subentro = apRegistra($this, $s, apVendita($s['rigaV'], $s['a']));

    apAnnulla($this, $s, $subentro, 'corto')->assertUnprocessable()->assertJsonValidationErrors('nota_annullamento');
    apAnnulla($this, $s, $subentro)->assertRedirect();

    // Fase 1-bis A8: una seconda scheda rimasta aperta riceve un rifiuto leggibile, non una pagina 404; e la copia
    // autentica o l'annullamento del conguaglio su un passaggio annullato si rifiutano senza scrivere.
    apAnnulla($this, $s, $subentro)->assertRedirect()->assertSessionHas('message', fn ($m) => $m['type'] === 'error' && str_contains($m['message'], 'già stato annullato'));
    $this->actingAs($this->user)->patch(route('admin.gestionale.immobili.passaggi.copia-autentica', [$s['c'], $s['unita'], $subentro]), ['copia_autentica_il' => '2026-06-01'])
        ->assertRedirect()->assertSessionHas('message', fn ($m) => $m['type'] === 'error' && str_contains($m['message'], 'annullato'));
    $this->actingAs($this->user)->delete(route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$s['c'], $s['unita'], $subentro]), ['nota_annullamento_conguaglio' => 'Le parti si sono accordate diversamente'])
        ->assertRedirect()->assertSessionHas('message', fn ($m) => $m['type'] === 'error' && str_contains($m['message'], 'annullato'));
    // L-R3: anche con una data futura il rifiuto è quello dell'annullato, nel messaggio della pagina, non sotto il campo.
    $this->actingAs($this->user)->patch(route('admin.gestionale.immobili.passaggi.copia-autentica', [$s['c'], $s['unita'], $subentro]), ['copia_autentica_il' => now()->addYear()->toDateString()])
        ->assertSessionHasNoErrors()->assertSessionHas('message', fn ($m) => $m['type'] === 'error' && str_contains($m['message'], 'annullato'));
    $annullato = Subentro::withoutGlobalScopes()->findOrFail($subentro->id);
    expect($annullato->copia_autentica_il->toDateString())->toBe('2026-05-01')
        ->and($annullato->conguaglio_annullato_il)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Gli altri tipi di passaggio, le pertinenze, i promemoria
|--------------------------------------------------------------------------
*/

it('vendita a chi è già comproprietario: la quota sommata torna com\'era, e la sua riga di prima si riapre', function () {
    $s = apScenario();
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 60]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['a']->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'proprietario', 'quota' => 40, 'attivo' => true, 'data_inizio' => '2020-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    $righePrima = apFotoRighe([$s['unita']->id]);

    $subentro = apRegistra($this, $s, apVendita($s['rigaV'], $s['a'], '2026-05-01', ['quota' => 60]));
    expect((float) DB::table('anagrafica_immobile')->where('id', $subentro->riga_entrante_id)->value('quota'))->toBe(100.0);

    apAnnulla($this, $s, $subentro)->assertRedirect();
    expect(apFotoRighe([$s['unita']->id]))->toBe($righePrima);
});

it('inizio locazione: l\'annullamento toglie l\'inquilino e il promemoria in agenda del contratto', function () {
    $s = apScenario();
    $verdi = Anagrafica::forceCreate(['nome' => 'Verdi Luca', 'email' => 'ap-verdi@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'APVERDILUCA00001']);
    $verdi->condomini()->syncWithoutDetaching([$s['c']->id]);
    $righePrima = apFotoRighe([$s['unita']->id]);

    $subentro = apRegistra($this, $s, [
        'tipo' => 'inizio_locazione', 'anagrafica_entrante_id' => $verdi->id, 'decorrenza' => '2026-06-01', 'quota' => 100, 'tipologia' => 'inquilino',
        'copia_autentica' => false, 'data_fine_locazione' => '2030-05-31', 'regime_contratto' => 'abitativo', 'pertinenze' => [],
        'promemoria_scadenza' => true, 'promemoria_giorni' => 90,
    ]);
    expect(Evento::where('eventable_type', Subentro::class)->where('eventable_id', $subentro->id)->count())->toBe(1);

    apAnnulla($this, $s, $subentro)->assertRedirect();
    expect(apFotoRighe([$s['unita']->id]))->toBe($righePrima)
        ->and(Evento::where('eventable_type', Subentro::class)->where('eventable_id', $subentro->id)->count())->toBe(0);
});

it('usufrutto, costituzione: torna il proprietario pieno, e spariscono la riga di nudo proprietario e quella dell\'usufruttuario', function () {
    $s = apScenario();
    $righePrima = apFotoRighe([$s['unita']->id]);
    $subentro = apRegistra($this, $s, [
        'tipo' => 'usufrutto', 'sottotipo' => 'costituzione', 'ordinaria_dopo_atto' => 'usufruttuario', 'riga_uscente_id' => $s['rigaV'], 'anagrafica_entrante_id' => $s['a']->id,
        'decorrenza' => '2026-05-01', 'quota' => 100, 'tipologia' => 'usufruttuario', 'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Atto di costituzione letto'
    ]);
    expect(DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->count())->toBe(3);

    apAnnulla($this, $s, $subentro)->assertRedirect();
    expect(apFotoRighe([$s['unita']->id]))->toBe($righePrima);
});

it('usufrutto, estinzione: torna l\'usufruttuario e i nudi proprietari tornano nudi, ciascuno alla sua quota', function () {
    $s = apScenario();
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'nuda_proprietario', 'quota' => 60]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['b']->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'nuda_proprietario', 'quota' => 40, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    $rigaUsu = DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $s['a']->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'usufruttuario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    $righePrima = apFotoRighe([$s['unita']->id]);

    $subentro = apRegistra($this, $s, [
        'tipo' => 'usufrutto', 'sottotipo' => 'estinzione', 'riga_uscente_id' => $rigaUsu, 'decorrenza' => '2026-05-01',
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Estinzione per rinuncia letta'
    ]);

    apAnnulla($this, $s, $subentro)->assertRedirect();
    expect(apFotoRighe([$s['unita']->id]))->toBe($righePrima);
});

it('le pertinenze passate con l\'unità tornano anche loro, nella stessa operazione, e il passaggio figlio resta annullato con il padre', function () {
    $s = apScenario();
    $box = Immobile::forceCreate(['condominio_id' => $s['c']->id, 'nome' => 'Box 12', 'descrizione' => 'Box', 'interno' => 'B12', 'pertinenza_di_immobile_id' => $s['unita']->id]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['v']->id, 'immobile_id' => $box->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    $righePrima = apFotoRighe([$s['unita']->id, $box->id]);

    $subentro = apRegistra($this, $s, apVendita($s['rigaV'], $s['a'], '2026-05-01', ['pertinenze' => [$box->id]]));
    $figlio = Subentro::where('subentro_padre_id', $subentro->id)->sole();

    apAnnulla($this, $s, $subentro)->assertRedirect();
    expect(apFotoRighe([$s['unita']->id, $box->id]))->toBe($righePrima)
        ->and(Subentro::withoutGlobalScopes()->findOrFail($figlio->id)->annullato_il)->not->toBeNull();
});

it('una pertinenza che ha avuto un passaggio suo dopo quello dell\'unità ferma l\'annullamento dell\'unità', function () {
    $s = apScenario();
    $box = Immobile::forceCreate(['condominio_id' => $s['c']->id, 'nome' => 'Box 12', 'descrizione' => 'Box', 'interno' => 'B12', 'pertinenza_di_immobile_id' => $s['unita']->id]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['v']->id, 'immobile_id' => $box->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    $subentro = apRegistra($this, $s, apVendita($s['rigaV'], $s['a'], '2026-05-01', ['pertinenze' => [$box->id]]));

    $rigaBoxA = (int) DB::table('anagrafica_immobile')->where('immobile_id', $box->id)->where('anagrafica_id', $s['a']->id)->value('id');
    apRegistra($this, $s, apVendita($rigaBoxA, $s['b'], '2026-08-01'), $box);

    expect(apAnnulla($this, $s, $subentro)->assertUnprocessable()->json('errors.passaggio.0'))
        ->toBe('Dopo questo, su Box 12, c\'è un altro passaggio: la vendita da Acquirente Elsa a Compratrice Bice, dal 1 agosto 2026. Si annulla prima quello, dallo storico di Box 12, poi questo.');
});

it('i promemoria delle rate nel portale tornano a chi è uscito', function () {
    $s = apScenario();
    (new \App\Listeners\Gestionale\SyncScadenziarioWithPianoRate())->handle(new \App\Events\Gestionale\PianoRateStatusUpdated($s['c'], $s['e'], $s['piano'], $this->user, \App\Enums\StatoPianoRate::BOZZA, \App\Enums\StatoPianoRate::APPROVATO));
    apEmetti($s);
    $rata9 = (int) DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('numero_rata', 9)->value('id');
    $destinatari = fn () => Evento::whereJsonContains('meta->context->rata_id', $rata9)->where('tipo', \App\Enums\EventoTipo::SCADENZA_RATA_CONDOMINO->value)
        ->get()->flatMap(fn (Evento $e) => $e->anagrafiche()->pluck('anagrafiche.id'))->map(fn ($id) => (int) $id)->unique()->values()->all();
    $prima = $destinatari();

    $subentro = apRegistra($this, $s, apVendita($s['rigaV'], $s['a']));
    expect($destinatari())->not->toBe($prima);

    apAnnulla($this, $s, $subentro)->assertRedirect();
    expect($destinatari())->toBe($prima);
});

/*
|--------------------------------------------------------------------------
| I lettori che lo scope del modello non raggiunge
|--------------------------------------------------------------------------
*/

it('un usufrutto annullato non fa più «entrare con un passaggio» la riga messa a mano con la stessa data: vale da D7 stretto, cioè tutto l\'anno', function () {
    $s = apScenario();
    $subentro = apRegistra($this, $s, [
        'tipo' => 'usufrutto', 'sottotipo' => 'costituzione', 'ordinaria_dopo_atto' => 'usufruttuario', 'riga_uscente_id' => $s['rigaV'], 'anagrafica_entrante_id' => $s['a']->id,
        'decorrenza' => '2026-05-01', 'quota' => 100, 'tipologia' => 'usufruttuario', 'copia_autentica' => false, 'pertinenze' => [],
        'ho_letto' => true, 'nota_cancello' => 'Atto di costituzione letto',
    ]);
    apAnnulla($this, $s, $subentro)->assertRedirect();

    // L'amministratore associa a mano un usufruttuario con la data dell'atto annullato: nessun predecessore, nessun passaggio.
    $riga = DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $s['b']->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'usufruttuario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2026-05-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    $risolutore = new \App\Services\Riparto\RisolutoreTitolari();

    // Sottoquery di `vincolaQuery`: in gennaio-aprile la riga c'è, perché la sua data d'inizio non filtra.
    $gennaioAprile = new \App\Support\PeriodoCompetenza('2026-01-01', '2026-04-30');
    expect($risolutore->vincolaQuery(DB::table('anagrafica_immobile')->where('id', $riga), $gennaioAprile)->exists())->toBeTrue();

    // Lettura diretta di `entrataConPassaggio`: i giorni sono quelli dell'anno intero.
    $righe = DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('tipologia', 'usufruttuario')->get();
    expect($risolutore->giorniDiTitolarita($righe->firstWhere('id', $riga), new \App\Support\PeriodoCompetenza('2026-01-01', '2026-12-31'), $righe))->toBe(365);
});

it('dopo l\'annullamento la persona entrata per sbaglio si può cancellare, e lo storico la nomina ancora dal registro', function () {
    $s = apScenario();
    apEmetti($s);
    $subentro = apRegistra($this, $s, apVendita($s['rigaV'], $s['a']));
    apAnnulla($this, $s, $subentro)->assertRedirect();

    // Il distacco dal condominio è quello di «Modifica» (sync dei condomini); la cancellazione passa dalla rotta vera,
    // con la sua guardia sui passaggi registrati, che l'annullato non deve più far scattare (Fase 1-bis, A7).
    DB::table('anagrafica_condominio')->where('anagrafica_id', $s['a']->id)->delete();
    $this->actingAs($this->user)->delete(route('admin.anagrafiche.destroy', $s['a']->id))->assertSessionHas('message', fn ($m) => $m['type'] === 'success');
    expect(DB::table('anagrafiche')->where('id', $s['a']->id)->exists())->toBeFalse();
    // Chi esce ha ancora le sue righe e non si cancella qui: si simula l'effetto della chiave esterna `nullOnDelete`.
    DB::table('subentri')->where('id', $subentro->id)->update(['anagrafica_uscente_id' => null]);

    $voce = collect(app(StoricoTitolarita::class)->perImmobile($s['unita']->fresh())['subentri'])->firstWhere('id', $subentro->id);
    expect($voce['entrante'])->toBe('Acquirente Elsa')
        ->and($voce['uscente'])->toBe('Venditore Ugo');
});

/*
|--------------------------------------------------------------------------
| Fase 1-bis della beta.37: i reperti confermati, uno per test
|--------------------------------------------------------------------------
*/

/** «Associa soggetto» dalla rotta vera, con le sue guardie sullo stato di quel momento. */
function apAssocia($test, array $s, Anagrafica $persona, string $tipologia, float $quota, string $dal): void
{
    $test->actingAs($test->user)->post(route('admin.gestionale.immobili.anagrafiche.store', [$s['c'], $s['unita']]), [
        'anagrafica_id' => $persona->id, 'tipologia' => $tipologia, 'quota' => $quota, 'data_inizio' => $dal,
    ])->assertSessionHasNoErrors();
}

it('A1 — vendita di metà, con chi vende riassociato a mano per il resto: l\'annullamento si ferma e nomina la riga, invece di lasciare Ugo proprietario due volte', function () {
    $s = apScenario();
    apEmetti($s);
    // Dalla 1.11.0-beta.42 la richiesta rifiuta una quota diversa da quella di chi vende (decisione 37): la vendita di metà
    // si registra come la lasciavano le versioni prima, dall'azione, perché i dati di allora restano da annullare.
    app(\App\Actions\Subentro\RegistraSubentroAction::class)->execute($s['c'], $s['unita'], [
        'tipo' => 'vendita', 'sottotipo' => null, 'riga_uscente' => \App\Models\TitolaritaImmobile::with('anagrafica')->findOrFail($s['rigaV']),
        'entrante' => $s['a'], 'decorrenza' => \Carbon\CarbonImmutable::parse('2026-05-01'), 'quota' => 50.0, 'tipologia' => 'proprietario',
        'copia_autentica' => true, 'copia_autentica_il' => \Carbon\CarbonImmutable::parse('2026-05-01'), 'estremi_titolo' => 'atto notaio Verdi, rep. 12345',
        'nota' => null, 'data_fine_locazione' => null, 'regime_contratto' => null, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Rogito letto, bozze a chi compra',
        'allegato_titolo' => null, 'promemoria_scadenza' => false, 'promemoria_giorni' => null, 'rinuncia_conguaglio' => false, 'nota_conguaglio' => null,
        'ordinaria_dopo_atto' => null, 'voci_da_tenere' => [], 'ordinaria_impronta' => null,
    ], $this->user);
    $subentro = Subentro::whereNull('subentro_padre_id')->latest('id')->firstOrFail();
    apAssocia($this, $s, $s['v'], 'proprietario', 50, '2026-05-01');
    $righe = apFotoRighe([$s['unita']->id]);

    $messaggio = apAnnulla($this, $s, $subentro)->assertUnprocessable()->json('errors.passaggio.0');
    expect($messaggio)->toContain('Venditore Ugo tornerebbe proprietario dal 1 gennaio 2019, in corso')
        ->toContain('c\'è anche la riga di Venditore Ugo come proprietario dal 1 maggio 2026')->toContain('si sovrapporrebbero')
        // G-3 del giro di verifica: «Dissocia» la rifiuterebbe (è il seguito della riga chiusa dal passaggio), e il testo
        // dice la via manuale che funziona (Vincenzo, 29/09/2026: solo testo).
        ->toContain('Da «Modifica associazione» spostane l\'inizio al 2 maggio 2026, poi toglila con «Dissocia» e annulla.')
        ->and(apFotoRighe([$s['unita']->id]))->toBe($righe);
    expect(collect(app(StoricoTitolarita::class)->perImmobile($s['unita']->fresh())['subentri'])->firstWhere('id', $subentro->id)['annullabile']['motivo'])->toBe($messaggio);

    // La via, seguita dalle rotte vere: si sposta l'inizio, si dissocia, si annulla, e tutto torna com'era.
    $rigaMano = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['v']->id)->where('data_inizio', '2026-05-01')->value('id');
    $this->actingAs($this->user)->put(route('admin.gestionale.immobili.anagrafiche.update', [$s['c'], $s['unita'], $rigaMano]), [
        'anagrafica_id' => $s['v']->id, 'tipologia' => 'proprietario', 'quota' => 50, 'data_inizio' => '2026-05-02', 'data_fine' => null, 'note' => null,
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->user)->delete(route('admin.gestionale.immobili.anagrafiche.destroy', [$s['c'], $s['unita'], $rigaMano]))->assertSessionHasNoErrors();
    apAnnulla($this, $s, $subentro)->assertRedirect()->assertSessionHasNoErrors();
    expect(DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->count())->toBe(1)
        ->and(DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->value('data_fine'))->toBeNull();
});

it('A1 — fine locazione senza nuovo inquilino, poi il nuovo associato a mano: le quote dell\'inquilino farebbero 200, e l\'annullamento si ferma', function () {
    $s = apScenario();
    $vecchio = Anagrafica::forceCreate(['nome' => 'Inquilino Oreste', 'email' => 'ap-oreste@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'APORESTEINQ00001']);
    $nuovo = Anagrafica::forceCreate(['nome' => 'Inquilina Nora', 'email' => 'ap-nora@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'APNORAINQUI00001']);
    $vecchio->condomini()->syncWithoutDetaching([$s['c']->id]);
    $nuovo->condomini()->syncWithoutDetaching([$s['c']->id]);
    $rigaO = DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $vecchio->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'inquilino', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2024-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    $subentro = apRegistra($this, $s, [
        'tipo' => 'fine_locazione', 'riga_uscente_id' => $rigaO, 'anagrafica_entrante_id' => null, 'decorrenza' => '2026-06-01',
        'quota' => 100, 'tipologia' => 'inquilino', 'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Recesso letto, unità sfitta',
    ]);
    apAssocia($this, $s, $nuovo, 'inquilino', 100, '2026-06-15');

    expect(apAnnulla($this, $s, $subentro)->assertUnprocessable()->json('errors.passaggio.0'))
        ->toContain('Inquilino Oreste tornerebbe inquilino dal 1 gennaio 2024, in corso')->toContain('Inquilina Nora')->toContain('le quote farebbero 200')
        ->toContain('Quella riga è stata associata dopo il passaggio: toglila con «Dissocia», poi annulla.');
});

it('A1 — una riga vecchia, fuori dal registro, riaperta da «Modifica» dopo il passaggio ferma l\'annullamento come una riga nuova', function () {
    $s = apScenario();
    $vecchio = Anagrafica::forceCreate(['nome' => 'Inquilino Oreste', 'email' => 'ap-oreste2@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'APORESTEINQ00002']);
    $antico = Anagrafica::forceCreate(['nome' => 'Inquilino Remo', 'email' => 'ap-remo@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'APREMOINQUI00002']);
    $vecchio->condomini()->syncWithoutDetaching([$s['c']->id]);
    $antico->condomini()->syncWithoutDetaching([$s['c']->id]);
    $rigaRemo = DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $antico->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'inquilino', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2020-01-01', 'data_fine' => '2023-12-31', 'created_at' => now(), 'updated_at' => now()]);
    $rigaO = DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $vecchio->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'inquilino', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2024-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    $subentro = apRegistra($this, $s, [
        'tipo' => 'fine_locazione', 'riga_uscente_id' => $rigaO, 'anagrafica_entrante_id' => null, 'decorrenza' => '2026-06-01',
        'quota' => 100, 'tipologia' => 'inquilino', 'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Recesso letto, unità sfitta',
    ]);
    $this->actingAs($this->user)->put(route('admin.gestionale.immobili.anagrafiche.update', [$s['c'], $s['unita'], $rigaRemo]), [
        'anagrafica_id' => $antico->id, 'tipologia' => 'inquilino', 'quota' => 100, 'data_inizio' => '2026-06-01', 'data_fine' => null, 'note' => null,
    ])->assertSessionHasNoErrors();

    expect(apAnnulla($this, $s, $subentro)->assertUnprocessable()->json('errors.passaggio.0'))->toContain('Inquilino Remo')->toContain('le quote farebbero 200')
        ->toContain('Quella riga c\'era già prima del passaggio: se l\'hai cambiata dopo, riportala com\'era da «Modifica associazione», poi annulla. Non toglierla con «Dissocia»');
});

it('A1 — un inquilino associato a mano dopo una vendita non si scontra con niente: l\'annullamento passa, e l\'inquilino resta', function () {
    $s = apScenario();
    apEmetti($s);
    $subentro = apRegistra($this, $s, apVendita($s['rigaV'], $s['a']));
    $inquilino = Anagrafica::forceCreate(['nome' => 'Inquilino Oreste', 'email' => 'ap-oreste3@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'APORESTEINQ00003']);
    $inquilino->condomini()->syncWithoutDetaching([$s['c']->id]);
    apAssocia($this, $s, $inquilino, 'inquilino', 100, '2026-07-01');

    apAnnulla($this, $s, $subentro)->assertRedirect()->assertSessionHasNoErrors();
    expect(DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->value('data_fine'))->toBeNull()
        ->and(DB::table('anagrafica_immobile')->where('anagrafica_id', $inquilino->id)->exists())->toBeTrue();
});

it('A3 — il piano ricalcolato dopo il passaggio si è portato via le bozze del registro: l\'annullamento passa, avvisa di ricalcolare, e il ricalcolo rimette tutto a chi vendeva', function () {
    // Il percorso del reperto: rate emesse, vendita con rinuncia al conguaglio (le bozze passano comunque), emissioni
    // annullate, piano ricalcolato con la titolarità nuova.
    $s = apScenario();
    $statiPrima = DB::table('rate')->where('piano_rate_id', $s['piano']->id)->pluck('stato', 'id');
    apEmetti($s);
    $subentro = apRegistra($this, $s, apVendita($s['rigaV'], $s['a'], '2026-05-01', ['rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Regolato fra le parti davanti al notaio']));
    expect(Subentro::withoutGlobalScopes()->findOrFail($subentro->id)->registro['quote'])->toHaveCount(8);
    foreach ($statiPrima as $id => $stato) {
        DB::table('rate')->where('id', $id)->update(['stato' => $stato, 'data_emissione' => null]);
    }
    DB::table('rate_quote')->whereIn('rata_id', $statiPrima->keys())->update(['scrittura_contabile_id' => null]);
    $rigenera = function () use ($s) {
        DB::table('rate')->where('piano_rate_id', $s['piano']->id)->delete();
        DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
        app(GeneratePianoRateAction::class)->execute($s['piano']->fresh(), accettaDestinatari: true, notaDestinatari: 'Ricalcolato dopo il passaggio', esercizio: $s['e']);
    };
    $rigenera();
    expect(apQuoteDi($s['piano'], $s['a']))->not->toBeEmpty();

    $voce = collect(app(StoricoTitolarita::class)->perImmobile($s['unita']->fresh())['subentri'])->firstWhere('id', $subentro->id);
    expect($voce['annullabile']['si'])->toBeTrue()
        ->and(implode(' ', $voce['annullabile']['avvisi']))->toContain('«Preventivo 2026» è stato generato o ricalcolato dopo il passaggio')
        // Nessuna bozza del registro esiste più: il modulo non promette rate che tornano.
        ->and(implode(' ', $voce['annullabile']['effetti']))->not->toContain('rate passate');

    // L-R1 del giro di verifica: l'avviso va in testa, in un messaggio giallo che non si chiude da solo.
    apAnnulla($this, $s, $subentro)->assertRedirect()->assertSessionHas('message', fn ($m) => $m['type'] === 'warning'
        && str_starts_with($m['message'], 'Passaggio annullato. Il piano «Preventivo 2026» è stato generato o ricalcolato dopo il passaggio') && ! str_contains($m['message'], 'rate passate'));
    expect(DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->value('data_fine'))->toBeNull();
    $rigenera();
    expect(apQuoteDi($s['piano'], $s['a']))->toBeEmpty()->and(apQuoteDi($s['piano'], $s['v']))->toHaveCount(12);
});

it('A3 — una bozza del registro che esiste ancora ma ha un importo cambiato continua a fermare l\'annullamento, e dice quale rata', function () {
    $s = apScenario();
    apEmetti($s);
    $subentro = apRegistra($this, $s, apVendita($s['rigaV'], $s['a']));
    $voce = collect(Subentro::withoutGlobalScopes()->findOrFail($subentro->id)->registro['quote'])->first();
    DB::table('rate_quote')->where('id', $voce['id'])->update(['importo' => 1]);

    expect(apAnnulla($this, $s, $subentro)->assertUnprocessable()->json('errors.passaggio.0'))->toContain('è stata modificata dopo')->toContain('del piano «Preventivo 2026»')->not->toContain('rata ?');
});

it('A4 — due comproprietari che vendono registrati al contrario delle date: le gemelle del secondo non sono «un piano ricalcolato», e una sua rata emessa non ferma il primo', function () {
    $s = apScenario('tutte_rate', 18000, comproprietario: true);
    apEmetti($s);
    $primo = apRegistra($this, $s, apVendita($s['rigaV'], $s['a'], '2026-09-01', ['quota' => 50]));
    apRegistra($this, $s, apVendita($s['rigaR'], $s['b'], '2026-06-01', ['quota' => 50]));

    $storico = fn () => collect(app(StoricoTitolarita::class)->perImmobile($s['unita']->fresh())['subentri'])->firstWhere('id', $primo->id);
    expect($storico()['annullabile']['si'])->toBeTrue()->and($storico()['annullabile']['avvisi'])->toBe([]);

    apEmetti($s, soloRata: 6);
    expect($storico()['annullabile']['motivo'])->toBeNull();
    apAnnulla($this, $s, $primo)->assertRedirect()->assertSessionHasNoErrors();
    expect(DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->value('data_fine'))->toBeNull()
        ->and(apQuoteDi($s['piano'], $s['a']))->toBeEmpty()
        ->and(DB::table('anagrafica_immobile')->where('anagrafica_id', $s['b']->id)->exists())->toBeTrue();
});

it('A4 — un piano nuovo emesso dopo il passaggio solo a chi vendeva (il consuntivo dell\'anno prima) non ferma l\'annullamento', function () {
    $s = apScenario();
    apEmetti($s);
    $subentro = apRegistra($this, $s, apVendita($s['rigaV'], $s['a']));
    $piano2 = DB::table('piani_rate')->insertGetId([
        'gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'nome' => 'Consuntivo 2025', 'numero_rate' => 1, 'giorno_scadenza' => 5,
        'metodo_distribuzione' => 'tutte_rate', 'attivo' => true, 'stato' => 'approvato', 'tipo' => 'ordinario', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $scrittura = DB::table('scritture_contabili')->insertGetId([
        'condominio_id' => $s['c']->id, 'gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id, 'data_registrazione' => '2026-07-01', 'data_competenza' => '2026-07-01',
        'numero_protocollo' => 'EMI-AP-CONS25', 'causale' => 'emissione rate', 'descrizione' => 'emissione', 'tipo_movimento' => 'emissione_rate', 'stato' => 'registrata', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $rata = DB::table('rate')->insertGetId(['piano_rate_id' => $piano2, 'numero_rata' => 1, 'data_scadenza' => '2026-07-05', 'data_emissione' => '2026-07-01', 'importo_totale' => 3000, 'stato' => 'emessa', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('rate_quote')->insert([
        'rata_id' => $rata, 'anagrafica_id' => $s['v']->id, 'immobile_id' => $s['unita']->id, 'importo' => 3000, 'importo_pagato' => 0, 'stato' => 'da_pagare', 'tipo' => 'ordinaria',
        'data_scadenza' => '2026-07-05', 'scrittura_contabile_id' => $scrittura, 'created_at' => now(), 'updated_at' => now(),
    ]);

    apAnnulla($this, $s, $subentro)->assertRedirect()->assertSessionHasNoErrors();
});

it('A5 — la segnalazione dal portale di un altro condòmino, sulla stessa rata ma su un\'altra unità, non ferma l\'annullamento', function () {
    $s = apScenario(interno2: 'd');
    (new \App\Listeners\Gestionale\SyncScadenziarioWithPianoRate())->handle(new \App\Events\Gestionale\PianoRateStatusUpdated($s['c'], $s['e'], $s['piano'], $this->user, \App\Enums\StatoPianoRate::BOZZA, \App\Enums\StatoPianoRate::APPROVATO));
    apEmetti($s);
    $subentro = apRegistra($this, $s, apVendita($s['rigaV'], $s['a']));
    apSegnala($s, 7, $s['d']);

    apAnnulla($this, $s, $subentro)->assertRedirect()->assertSessionHasNoErrors();
});

it('A5 — chi vendeva ha anche Interno 2 e dopo il passaggio segnala la rata 7: l\'annullamento si ferma e lo nomina, e la sua segnalazione resta', function () {
    $s = apScenario(interno2: 'v');
    (new \App\Listeners\Gestionale\SyncScadenziarioWithPianoRate())->handle(new \App\Events\Gestionale\PianoRateStatusUpdated($s['c'], $s['e'], $s['piano'], $this->user, \App\Enums\StatoPianoRate::BOZZA, \App\Enums\StatoPianoRate::APPROVATO));
    apEmetti($s);
    $subentro = apRegistra($this, $s, apVendita($s['rigaV'], $s['a']));
    $evento = apSegnala($s, 7, $s['v']);

    expect(apAnnulla($this, $s, $subentro)->assertUnprocessable()->json('errors.passaggio.0'))->toContain('rata 7')->toContain('segnalato dal portale da Venditore Ugo')
        ->and($evento->fresh()->meta['status'])->toBe('reported');
});

/** Mette a «segnalato dal portale» il promemoria della rata per quella persona. */
function apSegnala(array $s, int $numeroRata, Anagrafica $persona): Evento
{
    $rata = (int) DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('numero_rata', $numeroRata)->value('id');
    $evento = Evento::whereJsonContains('meta->context->rata_id', $rata)->where('tipo', \App\Enums\EventoTipo::SCADENZA_RATA_CONDOMINO->value)
        ->whereHas('anagrafiche', fn ($q) => $q->where('anagrafica_id', $persona->id))->firstOrFail();
    $meta = $evento->meta;
    $meta['status'] = 'reported';
    $evento->meta = $meta;
    $evento->save();

    return $evento;
}

it('A6 — chi ha annullato resta nello storico anche quando il suo utente viene cancellato: il nome è nel registro', function () {
    $s = apScenario();
    $carla = User::factory()->create(['name' => 'Revisora Carla']);
    $carla->assignRole(Role::findByName('admin', 'web'));
    $subentro = apRegistra($this, $s, apVendita($s['rigaV'], $s['a']));
    $this->actingAs($carla)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita'], $subentro]), ['nota_annullamento' => 'Rogito registrato due volte'])->assertRedirect();
    DB::table('users')->where('id', $carla->id)->delete();

    $voce = collect(app(StoricoTitolarita::class)->perImmobile($s['unita']->fresh())['subentri'])->firstWhere('id', $subentro->id);
    expect($voce['annullato_da'])->toBe('Revisora Carla');
});

it('A10 — l\'annullamento toglie il promemoria in agenda e svuota il contatore dello staff, invece di lasciarlo contare per dieci minuti', function () {
    $s = apScenario();
    Role::firstOrCreate(['name' => \App\Enums\Role::AMMINISTRATORE->value, 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => \App\Enums\Role::COLLABORATORE->value, 'guard_name' => 'web']); // `clearAdminCache` li cerca tutti e due
    $this->user->assignRole(\App\Enums\Role::AMMINISTRATORE->value);
    $verdi = Anagrafica::forceCreate(['nome' => 'Verdi Luca', 'email' => 'ap-verdi2@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'APVERDILUCA00002']);
    $verdi->condomini()->syncWithoutDetaching([$s['c']->id]);
    $subentro = apRegistra($this, $s, [
        'tipo' => 'inizio_locazione', 'anagrafica_entrante_id' => $verdi->id, 'decorrenza' => '2026-06-01', 'quota' => 100, 'tipologia' => 'inquilino',
        'copia_autentica' => false, 'data_fine_locazione' => '2030-05-31', 'regime_contratto' => 'abitativo', 'pertinenze' => [],
        'promemoria_scadenza' => true, 'promemoria_giorni' => 90,
    ]);
    \Illuminate\Support\Facades\Cache::put("inbox_count_{$this->user->id}", 1, 600);

    apAnnulla($this, $s, $subentro)->assertRedirect();
    expect(\Illuminate\Support\Facades\Cache::has("inbox_count_{$this->user->id}"))->toBeFalse();
});

it('A11 — le date dell\'annullamento e della registrazione sono nel giorno dell\'utente: alle 00:30 di Roma è già il giorno dopo', function () {
    $s = apScenario();
    \Illuminate\Support\Carbon::setTestNow('2026-09-28 22:30:00'); // 29 settembre, 00:30 a Roma
    $subentro = apRegistra($this, $s, apVendita($s['rigaV'], $s['a']));
    apAnnulla($this, $s, $subentro)->assertRedirect();
    $voce = collect(app(StoricoTitolarita::class)->perImmobile($s['unita']->fresh())['subentri'])->firstWhere('id', $subentro->id);
    \Illuminate\Support\Carbon::setTestNow();

    expect($voce['annullato_il'])->toBe('29 settembre 2026')->and($voce['registrato_il'])->toBe('29 settembre 2026');
});

it('A13 — il messaggio dopo l\'annullamento dice solo ciò che è tornato: una locazione non sposta rate, e senza conguaglio non lo nomina', function () {
    $s = apScenario();
    $verdi = Anagrafica::forceCreate(['nome' => 'Verdi Luca', 'email' => 'ap-verdi3@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'APVERDILUCA00003']);
    $verdi->condomini()->syncWithoutDetaching([$s['c']->id]);
    $subentro = apRegistra($this, $s, [
        'tipo' => 'inizio_locazione', 'anagrafica_entrante_id' => $verdi->id, 'decorrenza' => '2026-06-01', 'quota' => 100, 'tipologia' => 'inquilino',
        'copia_autentica' => false, 'data_fine_locazione' => '2030-05-31', 'regime_contratto' => 'abitativo', 'pertinenze' => [],
    ]);

    apAnnulla($this, $s, $subentro)->assertRedirect()->assertSessionHas('message', fn ($m) => $m['message'] === 'Passaggio annullato. Le righe di titolarità scritte dal passaggio sono tornate come prima. Resta nello storico, con la tua nota.');
});

it('A13 — dopo una vendita il messaggio conta le rate tornate e nomina le parti', function () {
    $s = apScenario();
    apEmetti($s);
    $subentro = apRegistra($this, $s, apVendita($s['rigaV'], $s['a']));

    apAnnulla($this, $s, $subentro)->assertRedirect()->assertSessionHas('message', fn ($m) => str_contains($m['message'], 'Le quote di 8 rate passate a Acquirente Elsa sono tornate a Venditore Ugo')
        && str_contains($m['message'], 'Il conguaglio è stato tolto dai saldi della gestione.'));
});

it('A15 e A16 — il blocco nomina il tipo del passaggio che viene dopo, e se è di una pertinenza passata con un\'altra unità dice da quale storico si annulla', function () {
    $s = apScenario();
    $verdi = Anagrafica::forceCreate(['nome' => 'Verdi Luca', 'email' => 'ap-verdi4@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'APVERDILUCA00004']);
    $verdi->condomini()->syncWithoutDetaching([$s['c']->id]);
    $inizio = apRegistra($this, $s, [
        'tipo' => 'inizio_locazione', 'anagrafica_entrante_id' => $verdi->id, 'decorrenza' => '2026-03-01', 'quota' => 100, 'tipologia' => 'inquilino',
        'copia_autentica' => false, 'data_fine_locazione' => '2030-02-28', 'regime_contratto' => 'abitativo', 'pertinenze' => [],
    ]);
    apRegistra($this, $s, [
        'tipo' => 'fine_locazione', 'riga_uscente_id' => (int) $inizio->riga_entrante_id, 'anagrafica_entrante_id' => null, 'decorrenza' => '2026-07-01',
        'quota' => 100, 'tipologia' => 'inquilino', 'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Recesso letto, unità sfitta',
    ]);
    expect(apAnnulla($this, $s, $inizio)->assertUnprocessable()->json('errors.passaggio.0'))
        ->toBe('Dopo questo, su Interno 1, c\'è un altro passaggio: la fine locazione di Verdi Luca, dal 1 luglio 2026. Si annulla prima quello, poi questo.');

    // Il box ha un passaggio suo (S0), poi passa con l'unità: nella scheda del box il passaggio figlio non compare.
    $box = Immobile::forceCreate(['condominio_id' => $s['c']->id, 'nome' => 'Box 12', 'descrizione' => 'Box', 'interno' => 'B12', 'pertinenza_di_immobile_id' => $s['unita']->id]);
    $rigaBoxB = DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $s['b']->id, 'immobile_id' => $box->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    $s0 = apRegistra($this, $s, apVendita($rigaBoxB, $s['v'], '2026-02-01'), $box);
    apRegistra($this, $s, apVendita($s['rigaV'], $s['a'], '2026-05-01', ['pertinenze' => [$box->id]]));

    expect(apAnnulla($this, $s, $s0, unita: $box)->assertUnprocessable()->json('errors.passaggio.0'))
        ->toBe('Dopo questo, su Box 12, c\'è un altro passaggio, registrato insieme a Interno 1: la vendita da Venditore Ugo a Acquirente Elsa, dal 1 maggio 2026. Si annulla dallo storico di Interno 1, poi questo.');
});

it('C-R3 — un saldo che il piano stava usando e che è sparito durante la generazione ferma il piano, invece di lasciarlo dentro le quote', function () {
    $s = apScenario('tutte_rate', 18000);
    $usato = (int) Saldo::where('anagrafica_id', $s['v']->id)->value('id');
    Saldo::whereKey($usato)->delete();

    expect(fn () => app(\App\Services\Gestionale\SaldoEsercizioService::class)->sincronizzaLucchetti($s['piano'], $s['g'], [$usato]))
        ->toThrow(\RuntimeException::class, 'è stato tolto mentre il piano si generava');
});

it('C-R5 — la segnalazione dal portale, riletta sotto lock, parte dallo stato di adesso: la prima passa, la seconda dice «già segnalato»', function () {
    $s = apScenario();
    (new \App\Listeners\Gestionale\SyncScadenziarioWithPianoRate())->handle(new \App\Events\Gestionale\PianoRateStatusUpdated($s['c'], $s['e'], $s['piano'], $this->user, \App\Enums\StatoPianoRate::BOZZA, \App\Enums\StatoPianoRate::APPROVATO));

    // Riscritto nel giro di sicurezza della 1.11.0-beta.39: prima si agiva come $this->user, l'
    // "admin" di questo file — senza anagrafica — e passava perché gli si dava VIEW_EVENTS: la
    // vecchia view() guardava solo il permesso, ed era proprio la falla della PR #48. La nuova
    // ability `reportPayment` non ha scorciatoie: serve la persona titolare della rata, cioè il
    // venditore Ugo ('v'), a cui viene agganciato un utente apposta. La generazione del piano
    // (riga sopra) non lo tocca: l'unico punto in cui `user_id` conta è la risoluzione di
    // Auth::user()->anagrafica qui sotto.
    $titolare = User::factory()->create(['email_verified_at' => now()]);
    $s['v']->update(['user_id' => $titolare->id]);
    $titolare->givePermissionTo(Permission::firstOrCreate(['name' => \App\Enums\Permission::VIEW_EVENTS->value, 'guard_name' => 'web']));
    $rata9 = (int) DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('numero_rata', 9)->value('id');
    $evento = Evento::whereJsonContains('meta->context->rata_id', $rata9)->where('tipo', \App\Enums\EventoTipo::SCADENZA_RATA_CONDOMINO->value)->firstOrFail();

    $this->actingAs($titolare)->post(route('user.eventi.report_payment', $evento))->assertSessionHas('success');
    expect($evento->fresh()->meta['status'])->toBe('reported');
    $this->actingAs($titolare)->post(route('user.eventi.report_payment', $evento))->assertSessionHas('info', 'Già segnalato.');
});

/*
|--------------------------------------------------------------------------
| Giro di verifica delle correzioni della Fase 1-bis
|--------------------------------------------------------------------------
*/

/** Un inquilino al 100 % dal 2024, prima del passaggio. @return array{0: Anagrafica, 1: int} */
function apInquilino(array $s, string $nome, string $cf): array
{
    $p = Anagrafica::forceCreate(['nome' => $nome, 'email' => strtolower(str_replace(' ', '', $nome)) . '-' . $s['c']->id . '@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => $cf]);
    $p->condomini()->syncWithoutDetaching([$s['c']->id]);
    $riga = DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $p->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'inquilino', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2024-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);

    return [$p, $riga];
}

/** Ricalcola il piano dello scenario con la titolarità di adesso (come la rotta `regenerate`). */
function apRigenera(array $s): void
{
    DB::table('rate')->where('piano_rate_id', $s['piano']->id)->delete();
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    app(GeneratePianoRateAction::class)->execute($s['piano']->fresh(), accettaDestinatari: true, notaDestinatari: 'Ricalcolato per la prova', esercizio: $s['e']);
}

function apFineLocazione($test, array $s, int $riga): Subentro
{
    return apRegistra($test, $s, [
        'tipo' => 'fine_locazione', 'riga_uscente_id' => $riga, 'anagrafica_entrante_id' => null, 'decorrenza' => '2026-06-01',
        'quota' => 100, 'tipologia' => 'inquilino', 'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Recesso letto, unità sfitta',
    ]);
}

it('G-1 — fine locazione senza nuovo inquilino e piano ricalcolato: la parte dell\'inquilino passata al proprietario conta; avvisa, e con la rata emessa ferma l\'annullamento', function () {
    $s = apScenario(ripartizione: ['proprietario' => 30, 'inquilino' => 70]);
    [, $rigaO] = apInquilino($s, 'Inquilino Oreste', 'APORESTEINQ00010');
    apRigenera($s);
    $subentro = apFineLocazione($this, $s, $rigaO);
    apRigenera($s);

    $voce = collect(app(StoricoTitolarita::class)->perImmobile($s['unita']->fresh())['subentri'])->firstWhere('id', $subentro->id);
    expect(implode(' ', $voce['annullabile']['avvisi']))->toContain('Il piano «Preventivo 2026» è stato generato o ricalcolato dopo il passaggio');

    apEmetti($s, soloRata: 7);
    expect(apAnnulla($this, $s, $subentro)->assertUnprocessable()->json('errors.passaggio.0'))->toContain('la rata 7 del piano «Preventivo 2026»');
});

it('G-1 — una spesa del solo proprietario dopo una fine locazione non cambia con l\'annullamento: una sua rata emessa non ferma niente', function () {
    $s = apScenario();
    [, $rigaO] = apInquilino($s, 'Inquilino Oreste', 'APORESTEINQ00011');
    apRigenera($s);
    $subentro = apFineLocazione($this, $s, $rigaO);
    apRigenera($s);
    apEmetti($s, soloRata: 7);

    apAnnulla($this, $s, $subentro)->assertRedirect()->assertSessionHasNoErrors();
});

it('G-1 — un riparto la cui competenza finisce prima della decorrenza non cambia con l\'annullamento, e non ferma niente', function () {
    $s = apScenario(ripartizione: ['proprietario' => 30, 'inquilino' => 70]);
    [, $rigaO] = apInquilino($s, 'Inquilino Oreste', 'APORESTEINQ00012');
    apRigenera($s);
    $subentro = apFineLocazione($this, $s, $rigaO);
    apRigenera($s);
    // Come un consuntivo dell'anno prima generato dopo: la competenza del riparto si chiude prima del 1° giugno.
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->update(['competenza_al' => '2026-05-31']);
    apEmetti($s, soloRata: 7);

    apAnnulla($this, $s, $subentro)->assertRedirect()->assertSessionHasNoErrors();
});

it('C-R7 — una quota nuova che riprende l\'id di una bozza sparita (MySQL 5.7 dopo un riavvio) non è la bozza del passaggio: non si rimette, e conta come nata dopo', function () {
    $s = apScenario();
    $statiPrima = DB::table('rate')->where('piano_rate_id', $s['piano']->id)->pluck('stato', 'id');
    apEmetti($s);
    $subentro = apRegistra($this, $s, apVendita($s['rigaV'], $s['a'], '2026-05-01', ['rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Regolato fra le parti davanti al notaio']));
    foreach ($statiPrima as $id => $stato) {
        DB::table('rate')->where('id', $id)->update(['stato' => $stato, 'data_emissione' => null]);
    }
    DB::table('rate_quote')->whereIn('rata_id', $statiPrima->keys())->update(['scrittura_contabile_id' => null]);
    $voce = collect(Subentro::withoutGlobalScopes()->findOrFail($subentro->id)->registro['quote'])->first();
    apRigenera($s);

    // Un piano nuovo, dopo il riavvio: la sua rata e la sua quota riprendono gli id della rata e della bozza sparite, con
    // lo stesso titolare e lo stesso importo. Solo la traccia del passaggio dentro la quota le distingue.
    $piano2 = DB::table('piani_rate')->insertGetId([
        'gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'nome' => 'Facciata 2026', 'numero_rate' => 1, 'giorno_scadenza' => 5,
        'metodo_distribuzione' => 'tutte_rate', 'attivo' => true, 'stato' => 'approvato', 'tipo' => 'ordinario', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $rata = DB::table('rate')->insertGetId(['id' => (int) $voce['rata_id'], 'piano_rate_id' => $piano2, 'numero_rata' => 1, 'data_scadenza' => '2026-10-05', 'importo_totale' => (int) $voce['dopo']['importo'], 'stato' => 'bozza', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('rate_quote')->insert([
        'id' => (int) $voce['id'], 'rata_id' => $rata, 'anagrafica_id' => $s['a']->id, 'immobile_id' => $s['unita']->id, 'importo' => (int) $voce['dopo']['importo'],
        'importo_pagato' => 0, 'stato' => 'da_pagare', 'tipo' => 'ordinaria', 'data_scadenza' => '2026-10-05', 'created_at' => now()->addMinute(), 'updated_at' => now()->addMinute(),
    ]);

    apAnnulla($this, $s, $subentro)->assertRedirect()->assertSessionHasNoErrors()
        ->assertSessionHas('message', fn ($m) => str_contains($m['message'], 'Facciata 2026') && ! str_contains($m['message'], 'rate passate'));
    expect((int) DB::table('rate_quote')->where('id', $voce['id'])->value('anagrafica_id'))->toBe($s['a']->id)
        ->and((int) DB::table('rate_quote')->where('id', $voce['id'])->value('rata_id'))->toBe($rata);
});

it('G-5 — la rata emessa dopo il passaggio ha già un pagamento, anche di un altro condòmino: il motivo dice la via intera e i suoi costi', function () {
    $s = apScenario(interno2: 'd');
    apEmetti($s);
    $subentro = apRegistra($this, $s, apVendita($s['rigaV'], $s['a']));
    apEmetti($s, soloRata: 5);
    $rata5 = (int) DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('numero_rata', 5)->value('id');
    DB::table('rate_quote')->where('rata_id', $rata5)->where('anagrafica_id', $s['d']->id)->update(['importo_pagato' => 5000]);

    expect(apAnnulla($this, $s, $subentro)->assertUnprocessable()->json('errors.passaggio.0'))
        ->toContain('è stata emessa a Acquirente Elsa la rata 5 del piano «Preventivo 2026», e sulla rata 5 ci sono già dei pagamenti')
        ->toContain('andrebbero stornati prima tutti gli incassi di quella rata, anche quelli degli altri condòmini')
        ->toContain('Ogni storno resta nel giornale');
});

it('G-6 — un altro passaggio con la stessa data, registrato dopo: il messaggio lo dice accanto a «un altro passaggio», dove concorda', function () {
    $s = apScenario(comproprietario: true);
    $primo = apRegistra($this, $s, apVendita($s['rigaV'], $s['a'], '2026-05-01', ['quota' => 50]));
    apRegistra($this, $s, apVendita($s['rigaR'], $s['b'], '2026-05-01', ['quota' => 50]));

    expect(apAnnulla($this, $s, $primo)->assertUnprocessable()->json('errors.passaggio.0'))
        ->toBe('Dopo questo, su Interno 1, c\'è un altro passaggio con la stessa data, registrato dopo: la vendita da Terzo Rino a Compratrice Bice, dal 1 maggio 2026. Si annulla prima quello, poi questo.');
});

it('C-R8 — due conferme quasi insieme: la seconda, che trova il passaggio già annullato, riceve il messaggio della pagina e non un errore nascosto', function () {
    $s = apScenario();
    apEmetti($s);
    $subentro = apRegistra($this, $s, apVendita($s['rigaV'], $s['a']));
    $utente = $this->user;
    // L'altra scheda annulla nell'istante fra il controllo del controller e l'azione.
    $this->app->resolving(\App\Actions\Subentro\AnnullaPassaggioAction::class, function ($azione) use ($subentro, $utente) {
        static $fatto = false;
        if (! $fatto) {
            $fatto = true;
            $azione->execute(Subentro::findOrFail($subentro->id), 'Annullato dall\'altra scheda', $utente);
        }
    });

    apAnnulla($this, $s, $subentro)->assertRedirect()->assertSessionHasNoErrors()
        ->assertSessionHas('message', fn ($m) => $m['type'] === 'error' && str_contains($m['message'], 'già stato annullato'));
});
