<?php

/**
 * Coda 438, decisione 192.1 — i messaggi degli altri moduli sui piani che non si ricalcolano più, e ciò che la strada perde.
 *
 * Il piano senza l'esercizio salvato sulla gestione usata in più esercizi (forma (A)) e il piano con la riga delle voci vuota (forma (B)) non si
 * ricalcolano: «Ricalcola» e il cestino si fermano con un messaggio che dice di eliminarli e crearli di nuovo dalla pagina del loro esercizio
 * (`PianoRate::fraseDaRifare`). Due cose che la forma non diceva:
 * - dopo una vendita, l'incasso, il rimborso e l'emissione su un piano così chiedevano di ricalcolarlo prima, cioè un'azione che la pagina
 *   rifiuta; ora dicono che il ricalcolo si rifiuta e la strada;
 * - la strada perde ciò che il piano tiene e la creazione non riprende da sola: il riparto a mano di un saldo solidale, i tratti di
 *   competenza delle voci, gli spostamenti di «Sposta spesa». Il messaggio li nomina, con le cifre del riparto.
 *
 * Scenario: Interno 1 di Ugo Venditore, «Spese generali» € 1.200,00 su «Proprietà» (1000 millesimi), dodici rate dal 5 gennaio 2026; la
 * gestione «Ordinaria 2026» agganciata al 2026 e al 2027; il piano «Preventivo 2026» generato dalla pagina del 2026 e poi reso come lo
 * lascia la 1.10 (senza esercizio né registro del riparto). Le cifre si rifanno dalla regola accanto a ogni prova.
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
    $this->travelTo(now()->setDate(2026, 1, 10)->setTime(10, 0));
});

/**
 * Il piano della 1.10 sulla gestione usata in due esercizi. `$conEva`: Eva comproprietaria al 50 % con Ugo, senza generare il piano (lo crea la prova).
 *
 * @return array{c: Condominio, e: Esercizio, e27: Esercizio, g: Gestione, voce: Conto, unita: Immobile, v: Anagrafica, a: Anagrafica, rigaV: int, piano: ?PianoRate, cassa: Cassa, tabella: Tabella}
 */
function pdrScenario(string $metodo = 'prima_rata', int $saldoUgo = 0, bool $generaIlPiano = true): array
{
    static $seq = 0;
    $seq++;
    $c = Condominio::factory()->create();
    $e = Esercizio::factory()->create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $e27 = Esercizio::factory()->create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    $g = Gestione::factory()->create(['condominio_id' => $c->id, 'nome' => 'Ordinaria 2026', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($e, $g->id);
    legaAEsercizio($e27, $g->id);
    $pc = PianoConto::create(['condominio_id' => $c->id, 'gestione_id' => $g->id, 'nome' => 'PC']);
    $voce = Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Spese generali', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 120000]);
    $tabella = Tabella::create(['condominio_id' => $c->id, 'nome' => 'Proprietà', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $voce->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    $unita = Immobile::create(['condominio_id' => $c->id, 'tipo' => 'appartamento', 'codice_immobile' => "PDR-{$seq}", 'nome' => 'Interno 1', 'interno' => '1']);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $unita->id, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);

    $banca = ContoContabile::create(['condominio_id' => $c->id, 'codice' => '10.10', 'nome' => 'Banca', 'tipo' => 'attivo', 'ruolo' => 'banca', 'categoria' => 'liquidita']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '10.20', 'nome' => 'Crediti verso condomini', 'tipo' => 'attivo', 'ruolo' => 'crediti_condomini', 'categoria' => 'crediti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '20.10', 'nome' => 'Anticipi', 'tipo' => 'passivo', 'ruolo' => 'anticipi_condomini', 'categoria' => 'debiti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '20.20', 'nome' => 'Gestione rate', 'tipo' => 'passivo', 'ruolo' => 'gestione_rate', 'categoria' => 'debiti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '30.10', 'nome' => 'Passate gestioni', 'tipo' => 'passivo', 'ruolo' => 'passate_gestioni', 'categoria' => 'debiti']);
    $cassa = Cassa::create(['condominio_id' => $c->id, 'conto_contabile_id' => $banca->id, 'nome' => 'Banca principale', 'tipo' => 'banca', 'attiva' => true, 'saldo_iniziale' => 100000]);

    $v = Anagrafica::forceCreate(['nome' => 'Ugo Venditore', 'email' => "pdr-v{$seq}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'PDRVENDITOR' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT)]);
    $a = Anagrafica::forceCreate(['nome' => 'Elsa Acquirente', 'email' => "pdr-a{$seq}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'PDRACQUIREN' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT)]);
    $v->condomini()->syncWithoutDetaching([$c->id]);
    $a->condomini()->syncWithoutDetaching([$c->id]);
    $rigaV = DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $v->id, 'immobile_id' => $unita->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    if ($saldoUgo !== 0) {
        Saldo::create(['esercizio_id' => $e->id, 'condominio_id' => $c->id, 'anagrafica_id' => $v->id, 'immobile_id' => $unita->id, 'gestione_id' => $g->id, 'saldo_iniziale' => $saldoUgo, 'origine' => 'manuale', 'is_applicato' => false]);
    }

    $piano = null;
    if ($generaIlPiano) {
        $piano = PianoRate::create([
            'gestione_id' => $g->id, 'condominio_id' => $c->id, 'esercizio_id' => $e->id, 'nome' => 'Preventivo 2026', 'stato' => 'approvato', 'tipo' => 'ordinario',
            'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'metodo_distribuzione' => $metodo, 'applica_saldi' => true,
        ]);
        DB::table('piano_rate_capitoli')->insert(['piano_rate_id' => $piano->id, 'conto_id' => $voce->id, 'importo' => 120000, 'created_at' => now(), 'updated_at' => now()]);
        app(GeneratePianoRateAction::class)->execute($piano, forzaApplicazioneSaldi: true, esercizio: $e);
        pdrDella110($piano);
    }

    return compact('c', 'e', 'e27', 'g', 'voce', 'unita', 'v', 'a', 'rigaV', 'piano', 'cassa', 'tabella');
}

/** Il piano com'era generato dalla 1.10: né l'esercizio salvato né il registro del riparto. */
function pdrDella110(PianoRate $piano): void
{
    DB::table('piani_rate')->where('id', $piano->id)->update(['esercizio_id' => null]);
    DB::table('righe_riparto')->where('piano_rate_id', $piano->id)->delete();
}

function pdrVendita($test, array $s, string $decorrenza = '2026-02-01'): void
{
    // Il rogito si registra dopo la decorrenza: la copia autentica non può essere ricevuta in un giorno futuro.
    $test->travelTo(\Carbon\CarbonImmutable::parse($decorrenza)->addDays(14)->setTime(10, 0));
    $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), [
        'tipo' => 'vendita', 'riga_uscente_id' => $s['rigaV'], 'anagrafica_entrante_id' => $s['a']->id, 'decorrenza' => $decorrenza,
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => $decorrenza,
        'estremi_titolo' => 'atto notaio Verdi, rep. 12345', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Rogito letto, piano da ricalcolare',
    ])->assertSessionHasNoErrors()->assertRedirect();
}

function pdrQuota(array $s, PianoRate $piano, int $numero, Anagrafica $chi): int
{
    $rata = (int) DB::table('rate')->where('piano_rate_id', $piano->id)->where('numero_rata', $numero)->value('id');

    return (int) DB::table('rate_quote')->where('rata_id', $rata)->where('anagrafica_id', $chi->id)->value('id');
}

function pdrChiesto(PianoRate $piano, Anagrafica ...$persone): array
{
    return array_map(fn (Anagrafica $p) => (int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')
        ->where('rate.piano_rate_id', $piano->id)->where('rate_quote.anagrafica_id', $p->id)->sum('rate_quote.importo'), $persone);
}

function pdrRicalcola($test, array $s, string $anno, PianoRate $piano): ?string
{
    $m = $test->actingAs($test->user)->from('/piano')->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $anno === '2027' ? $s['e27'] : $s['e'], $piano]), [
        'accetta_destinatari' => true, 'nota_destinatari' => 'Vendita registrata, quote da dividere per giorni',
    ])->getSession()->get('message');

    return ($m['type'] ?? null) === 'error' ? $m['message'] : null;
}

/** La strada del messaggio, dalle rotte: in bozza, eliminato dall'elenco, creato di nuovo dalla pagina del 2026 con la voce. */
function pdrStrada($test, array $s, PianoRate $piano, array $extra = []): PianoRate
{
    $test->actingAs($test->user)->from('/piano')->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $piano]), ['approvato' => false])->assertSessionHasNoErrors();
    $test->actingAs($test->user)->from('/elenco')->delete(route('admin.gestionale.esercizi.piani-rate.destroy', [$s['c'], $s['e'], $piano]))->assertSessionHasNoErrors();
    expect(PianoRate::find($piano->id))->toBeNull();
    $r = $test->actingAs($test->user)->from('/crea')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), array_merge([
        'tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'nome' => 'Preventivo 2026', 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 12,
        'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'genera_subito' => 1, 'capitoli_config' => [['id' => $s['voce']->id, 'importo' => '1.200,00']],
        'accetta_destinatari' => true, 'nota_destinatari' => 'Piano rifatto dopo il passaggio, quote per giorni',
    ], $extra));
    $r->assertSessionHasNoErrors();
    expect(($r->getSession()->get('message')['type'] ?? null))->not->toBe('error');

    return PianoRate::where('condominio_id', $s['c']->id)->where('nome', 'Preventivo 2026')->firstOrFail();
}

/** Il rifiuto della forma (A) per il «Preventivo 2026» approvato: il fatto, il perché e la strada, con `$apertura` davanti. */
function pdrRifiutoA(string $apertura, bool $approvato = true): string
{
    return $apertura . ': il piano rate «Preventivo 2026» non ha il suo esercizio salvato, e la gestione «Ordinaria 2026» è usata in più esercizi («Esercizio 2026» e '
        . '«Esercizio 2027»). Il programma non sa a quale di questi appartiene il piano: ricalcolarlo, anche togliendo una voce, lo legherebbe all\'esercizio della pagina da '
        . 'cui lo fai, e se non è il suo il piano perderebbe il già versato del suo esercizio o prenderebbe quello di un altro. Per rifarlo, '
        . ($approvato ? 'riportalo in bozza dalla sua pagina, ' : '') . 'eliminalo dall\'elenco dei piani rate e crealo di nuovo dalla pagina dei piani rate del suo esercizio, scegliendo le voci.';
}

// --- AD-4: i messaggi che chiedevano di ricalcolare prima ------------------------------------------------------------------------------

it('Coda 438 — dopo una vendita, l\'incasso su una quota del piano della 1.10 senza esercizio non chiede un ricalcolo che si rifiuta: dice di rifare il piano, con la strada, e la strada lo sblocca', function () {
    $s = pdrScenario();
    $piano = $s['piano'];
    // Il piano della 1.10: Ugo 12 × 10000 = 120000, nessun esercizio salvato.
    expect(pdrChiesto($piano, $s['v']))->toBe([120000])->and($piano->fresh()->esercizio_id)->toBeNull();
    pdrVendita($this, $s);

    $rifiuto = null;
    try {
        app(StoreIncassoRateAction::class)->execute([
            'pagante_id' => $s['v']->id, 'cassa_id' => $s['cassa']->id, 'gestione_id' => $s['g']->id, 'data_pagamento' => '2026-02-10', 'importo_totale' => 100.00,
            'descrizione' => 'Rata 1', 'dettaglio_pagamenti' => [['rata_id' => pdrQuota($s, $piano, 1, $s['v']), 'importo' => 100.00]],
        ], $s['c'], $s['e']);
    } catch (\App\Exceptions\Gestionale\PianoDaRicalcolareException $ex) {
        $rifiuto = $ex->getMessage();
    }

    expect($rifiuto)->toBe('Rifai prima il piano «Preventivo 2026»: le sue quote sono state calcolate prima del passaggio di Ugo Venditore su Interno 1, dal 1 febbraio 2026, '
        . 'e un incasso su una sua quota lo fermerebbe prima che ne tenga conto. ' . pdrRifiutoA('Oggi il ricalcolo si rifiuta') . ' Dopo registra l\'incasso sul piano nuovo.')
        ->and(DB::table('quota_scrittura')->count())->toBe(0);
    // Il ricalcolo, che la frase di prima chiedeva, si rifiuta davvero (decisione 192.1), dalla pagina del 2026 e da quella del 2027.
    expect(pdrRicalcola($this, $s, '2026', $piano))->toBe(pdrRifiutoA('Impossibile ricalcolare'))
        ->and(pdrRicalcola($this, $s, '2027', $piano))->toBe(pdrRifiutoA('Impossibile ricalcolare'));

    // La strada. A mano: Ugo dal 1/1 al 31/1, 31 giorni: 120000 × 31/365 = 10191,78 → 10192; Elsa 120000 − 10192 = 109808.
    $nuovo = pdrStrada($this, $s, $piano);
    expect($nuovo->esercizio_id)->toBe($s['e']->id)
        ->and(pdrChiesto($nuovo, $s['v'], $s['a']))->toBe([10192, 109808])
        ->and($nuovo->passaggiDaSeguire())->toBe([])
        ->and($nuovo->fraseRicalcolaPrima())->toBeNull();
});

it('Coda 438 — controprova: con l\'esercizio salvato il piano si ricalcola, e la frase dell\'incasso resta quella di sempre', function () {
    $s = pdrScenario();
    DB::table('piani_rate')->where('id', $s['piano']->id)->update(['esercizio_id' => $s['e']->id]);
    pdrVendita($this, $s);

    expect($s['piano']->fresh()->fraseRicalcolaPrima())->toBe('Ricalcola prima il piano «Preventivo 2026»: le sue quote sono state calcolate prima del passaggio di Ugo Venditore '
        . 'su Interno 1, dal 1 febbraio 2026, e un incasso su una sua quota lo fermerebbe prima che ne tenga conto. Dopo il ricalcolo registra l\'incasso.');
});

it('Coda 438 — dopo una vendita, il rimborso di un credito del piano della 1.10 senza esercizio dice di rifare il piano, con la strada, e non scrive niente', function () {
    // Ugo ha un credito pregresso di € 150,00, tutto nella rata 0.
    $s = pdrScenario('rata_zero', -15000);
    $piano = $s['piano'];
    $credito = (int) DB::table('rate_quote')->where('rata_id', DB::table('rate')->where('piano_rate_id', $piano->id)->where('numero_rata', 0)->value('id'))->value('id');
    expect((int) DB::table('rate_quote')->where('id', $credito)->value('importo'))->toBe(-15000);
    pdrVendita($this, $s, '2026-05-01');
    $scritture = DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->count();

    $r = $this->actingAs($this->user)->from('/rimborso')->post(route('admin.gestionale.anagrafiche.rimborsi.store', [$s['c'], $s['v']]),
        ['rata_quote_id' => $credito, 'cassa_id' => $s['cassa']->id, 'data_rimborso' => '2026-05-10', 'importo' => '30,00', 'nota' => 'Bonifico di prova']);
    $r->assertSessionHasErrors('rata_quote_id');

    expect(session('errors')->first('rata_quote_id'))->toBe('Rifai prima il piano «Preventivo 2026»: le sue quote sono state calcolate prima del passaggio di Ugo Venditore '
        . 'su Interno 1, dal 1 maggio 2026, e un rimborso su una sua quota lo fermerebbe prima che ne tenga conto. ' . pdrRifiutoA('Oggi il ricalcolo si rifiuta')
        . ' Dopo registra il rimborso sul piano nuovo.')
        ->and(DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->count())->toBe($scritture);
});

it('Coda 438 — dopo una vendita, l\'emissione del piano della 1.10 senza esercizio dice che il ricalcolo si rifiuta e la strada', function () {
    $s = pdrScenario();
    $piano = $s['piano'];
    pdrVendita($this, $s);
    $rate = DB::table('rate')->where('piano_rate_id', $piano->id)->whereIn('numero_rata', [1, 2])->pluck('id')->all();

    $r = $this->actingAs($this->user)->from('/piano')->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $piano]), ['rate_ids' => $rate, 'data_emissione' => '2026-02-10', 'invia_notifiche' => false]);

    expect($r->getSession()->get('message'))->toBe(['type' => 'error', 'message' => "Le quote di questo piano sono state calcolate prima di questo passaggio:\n• Ugo Venditore → Elsa Acquirente, Interno 1, dal 1 febbraio 2026\n\n"
        . 'Ricalcola il piano prima di emettere, perché tenga conto del passaggio. Se le voci o la data della delibera lasciano quelle quote a Ugo Venditore, il ricalcolo dà le stesse cifre.'
        . "\n\n" . pdrRifiutoA('Oggi il ricalcolo si rifiuta')])
        ->and(DB::table('rate')->where('piano_rate_id', $piano->id)->where('stato', 'emessa')->count())->toBe(0);
});

// --- L2: ciò che la strada perde -----------------------------------------------------------------------------------------------------------

it('Coda 438 — il piano della 1.10 senza esercizio con un saldo solidale ripartito a mano: il rifiuto nomina il riparto con le cifre, e rimesso nella creazione le cifre restano quelle', function () {
    $s = pdrScenario(generaIlPiano: false);
    // Eva comproprietaria al 50 % con Ugo, e un saldo pregresso solidale dell'unità di € 600,00 (Art. 63).
    $eva = Anagrafica::forceCreate(['nome' => 'Eva Comproprietaria', 'email' => 'pdr-eva@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'PDREVACOMPR00001']);
    $eva->condomini()->syncWithoutDetaching([$s['c']->id]);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $eva->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'proprietario', 'quota' => 50, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    $saldo = Saldo::forceCreate(['condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'gestione_id' => $s['g']->id, 'immobile_id' => $s['unita']->id,
        'anagrafica_id' => null, 'saldo_iniziale' => 60000, 'descrizione' => 'Pregresso solidale', 'is_applicato' => false]);
    $riparto = ['saldi_config' => [['saldo_id' => $saldo->id, 'ripartizioni' => [['anagrafica_id' => $s['v']->id, 'importo' => '600,00'], ['anagrafica_id' => $eva->id, 'importo' => '0,00']]]]];
    $crea = fn () => $this->actingAs($this->user)->from('/crea')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), [
        'tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'nome' => 'Preventivo 2026', 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 12,
        'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'genera_subito' => 1, 'capitoli_config' => [['id' => $s['voce']->id, 'importo' => '1.200,00']],
    ] + $riparto)->assertSessionHasNoErrors();
    $crea();
    $piano = PianoRate::where('condominio_id', $s['c']->id)->where('nome', 'Preventivo 2026')->firstOrFail();
    // A mano: € 1.200,00 sull'unità, a metà fra i due proprietari: 600 a testa; il saldo solidale tutto a Ugo con il riparto a mano: Ugo 1200, Eva 600.
    // Con il riparto automatico (pro-quota, quello che la creazione propone) sarebbero 300 a testa: Ugo 900, Eva 900.
    expect(pdrChiesto($piano, $s['v'], $eva))->toBe([120000, 60000]);
    pdrDella110($piano);
    DB::table('piani_rate')->where('id', $piano->id)->update(['stato' => 'approvato']);
    $piano = $piano->fresh();
    $this->travelTo(now()->setDate(2027, 2, 1)->setTime(10, 0));

    $perde = ' Eliminandolo si perde anche ciò che il piano tiene e la creazione non riprende da sola: il riparto a mano del saldo di Interno 1 (€ 600,00: '
        . 'Ugo Venditore € 600,00 ed Eva Comproprietaria € 0,00). Annotalo prima di eliminarlo e rimettilo nel piano nuovo.';
    expect(pdrRicalcola($this, $s, '2027', $piano))->toBe(pdrRifiutoA('Impossibile ricalcolare') . $perde)
        ->and(pdrChiesto($piano, $s['v'], $eva))->toBe([120000, 60000]);

    // La strada con il riparto rimesso nella creazione: le cifre di prima. A mano: Ugo 1200, Eva 600.
    $this->actingAs($this->user)->from('/piano')->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $piano]), ['approvato' => false])->assertSessionHasNoErrors();
    $this->actingAs($this->user)->from('/elenco')->delete(route('admin.gestionale.esercizi.piani-rate.destroy', [$s['c'], $s['e'], $piano]))->assertSessionHasNoErrors();
    expect(DB::table('saldi')->where('id', $saldo->id)->value('is_applicato'))->toBeFalsy();
    $crea();
    $nuovo = PianoRate::where('condominio_id', $s['c']->id)->where('nome', 'Preventivo 2026')->firstOrFail();
    expect($nuovo->id)->not->toBe($piano->id)
        ->and(pdrChiesto($nuovo, $s['v'], $eva))->toBe([120000, 60000]);
});

it('Coda 438 — il piano della 1.10 senza esercizio con i tratti di competenza e uno spostamento di «Sposta spesa»: il rifiuto li nomina', function () {
    $s = pdrScenario();
    $piano = $s['piano'];
    DB::table('piani_rate')->where('id', $piano->id)->update(['stato' => 'bozza']);
    $pulizie = Conto::create(['piano_conto_id' => $s['voce']->piano_conto_id, 'nome' => 'Pulizie', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 20000]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $pulizie->id, 'tabella_id' => $s['tabella']->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    // Nessun messaggio prima: il piano non tiene niente che la creazione non riprenda.
    expect(pdrRicalcola($this, $s, '2026', $piano->fresh()))->toBe(pdrRifiutoA('Impossibile ricalcolare', approvato: false));

    // Un tratto di competenza sulla voce, come lo scrive la creazione (`competenze_capitolo` sulla riga della voce).
    $riga = (int) DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->value('id');
    DB::table('competenze_capitolo')->insert(['piano_rate_capitolo_id' => $riga, 'dal' => '2026-01-01', 'al' => '2026-06-30', 'ordine' => 0, 'created_at' => now(), 'updated_at' => now()]);
    expect(pdrRicalcola($this, $s, '2026', $piano->fresh()))->toBe(pdrRifiutoA('Impossibile ricalcolare', approvato: false)
        . ' Eliminandolo si perde anche ciò che il piano tiene e la creazione non riprende da sola: i tratti di competenza delle voci. Annotalo prima di eliminarlo e rimettilo nel piano nuovo.');

    // «Sposta spesa» dalla sua rotta: € 100,00 da «Spese generali» a «Pulizie».
    $this->actingAs($this->user)->from('/piano')->post(route('admin.gestionale.piani-rate.move-budget', [$s['c'], $piano]), [
        'source_id' => $s['voce']->id, 'destination_id' => $pulizie->id, 'amount' => 100, 'reason' => 'Spostamento di prova',
    ])->assertSessionHasNoErrors();
    expect(DB::table('budget_movements')->where('piano_rate_id', $piano->id)->count())->toBe(1)
        ->and(pdrRicalcola($this, $s, '2026', $piano->fresh()))->toBe(pdrRifiutoA('Impossibile ricalcolare', approvato: false)
            . ' Eliminandolo si perde anche ciò che il piano tiene e la creazione non riprende da sola: i tratti di competenza delle voci e gli spostamenti fra le voci '
            . 'fatti con «Sposta spesa». Annotalo prima di eliminarlo e rimettilo nel piano nuovo.');
});
