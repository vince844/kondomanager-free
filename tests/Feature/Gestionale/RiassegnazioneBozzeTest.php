<?php

/**
 * B3a (1.11.0-beta.34) — le rate in bozza di un piano già a giornale passano a chi entra.
 *
 * La prassi degli amministratori (forum p=552, Fresco 21/09 e Gabriele 22/09/2026): alla vendita «le rate non
 * ancora emesse si intestano all'acquirente cambiando il nome su quelle che restano, senza rifare il riparto; il
 * passato si regola con il conguaglio». Supera la decisione 21 nel solo punto delle bozze. Tre decisioni di Vincenzo
 * del 25/09/2026, dopo il conto eseguito sul motore (`docs/piano_esecutivo_beta34_b3a.md`):
 *
 * 1. il conguaglio si calcola sull'**intero piano** e toglie ciò che chi entra paga già con le bozze — calcolato
 *    sulle sole emesse farebbe pagare all'acquirente più dei suoi giorni su € 1.200 (€ 263,01 con il rogito il 1/5,
 *    € 193,70 il 15/5 con la regola adottata);
 * 2. passano le bozze con scadenza **dalla decorrenza in poi**; sull'ordinario sempre, sullo straordinario solo se la
 *    spesa è di chi entra (delibera dalla decorrenza in poi);
 * 3. una bozza che porta **saldi pregressi** del venditore si divide: il preventivo a chi entra, il pregresso resta a
 *    chi esce in una quota sua sulla stessa rata — e deve valere con **tutti e tre** i metodi di distribuzione dei
 *    saldi (rata zero, prima rata, spalmati), a debito e a credito (Vincenzo: «è importante che la logica dei
 *    subentri funzioni anche in questi casi»).
 *
 * Il flusso è quello vero: generazione del piano a gennaio con il solo venditore, emissione delle prime quattro rate
 * a giornale, vendita registrata dalla rotta come fa l'amministratore.
 *
 * **Cosa resta scoperto** (regola «ogni test dichiara cosa NON copre»): l'emissione è simulata a giornale (una
 * scrittura e `scrittura_contabile_id`), non passa da `EmissioneRateController` — la divisione del pregresso a credito
 * (quota ≤ 0) all'emissione vera non è esercitata qui; le pertinenze nel passaggio; la corsa fra anteprima e
 * registrazione; la catena V → A → B **non** prova da sola la rilettura del riparto da `righe_di`: con una voce sola e
 * la competenza su tutto l'anno il ramo base dà gli stessi centesimi (lo servirebbe una voce a tratti — Fase 1-bis, R26).
 * Coperti dopo la Fase 1-bis (i test «R…» in fondo): le stampe del riparto, il compratore già comproprietario con
 * l'emissione vera, la nuda proprietà, il ripiego in due tratti, la straordinaria con il già versato, i promemoria che
 * cambiano verso, la catena con il pregresso, il pagamento segnalato dal portale.
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
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
 * Il condominio del forum: un'unità, preventivo ordinario da € 1.200,00 in dodici rate mensili (il 5 di ogni mese),
 * generato a gennaio quando l'unità era tutta del venditore, con il suo saldo pregresso distribuito col metodo dato.
 *
 * Con `$natura = 'straordinaria'` la stessa unità ha una spesa straordinaria da € 1.200,00 deliberata il giorno dato:
 * una fattura vera con la riga imprevista, collegata al piano come fa «Collega fatture», e il piano generato dalle
 * fatture (`calcolaDaFattureStraordinarie`), non dal preventivo.
 *
 * @return array{c: Condominio, e: Esercizio, g: Gestione, unita: Immobile, v: Anagrafica, a: Anagrafica, rigaV: int, piano: PianoRate}
 */
function rbScenario(string $metodo, int $saldoVenditore, string $natura = 'ordinaria', ?string $delibera = null, string $primaScadenza = '2026-01-05', int $numeroRate = 12, ?array $competenzaFattura = null, int $versatoVenditore = 0): array
{
    static $seq = 0;
    $seq++;
    $c = Condominio::factory()->create();
    $e = Esercizio::factory()->create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = Gestione::factory()->create(['condominio_id' => $c->id, 'nome' => $natura === 'ordinaria' ? 'Ordinaria 2026' : 'Facciata', 'tipo' => $natura, 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($e, $g->id);
    $pc = PianoConto::create(['condominio_id' => $c->id, 'gestione_id' => $g->id, 'nome' => 'PC']);
    $conto = Conto::create(['piano_conto_id' => $pc->id, 'nome' => $natura === 'ordinaria' ? 'Spese generali' : 'Rifacimento facciata', 'tipo' => 'spesa', 'natura_spesa' => $natura, 'importo' => 120000]);
    $tabella = Tabella::create(['condominio_id' => $c->id, 'nome' => 'Proprietà', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    $unita = Immobile::create(['condominio_id' => $c->id, 'tipo' => 'appartamento', 'codice_immobile' => "RB-{$seq}", 'nome' => 'Interno 1', 'interno' => '1']);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $unita->id, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);

    $v = Anagrafica::forceCreate(['nome' => 'Venditore Ugo', 'email' => "rb-v{$seq}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'RBVENDITORE' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT)]);
    $a = Anagrafica::forceCreate(['nome' => 'Acquirente Elsa', 'email' => "rb-a{$seq}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'RBACQUIRENT' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT)]);
    $v->condomini()->syncWithoutDetaching([$c->id]);
    $a->condomini()->syncWithoutDetaching([$c->id]);
    $rigaV = DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $v->id, 'immobile_id' => $unita->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);

    if ($saldoVenditore !== 0) {
        Saldo::create(['esercizio_id' => $e->id, 'condominio_id' => $c->id, 'anagrafica_id' => $v->id, 'immobile_id' => $unita->id, 'gestione_id' => $g->id, 'saldo_iniziale' => $saldoVenditore, 'origine' => 'manuale', 'is_applicato' => false]);
    }

    $piano = PianoRate::create([
        'gestione_id' => $g->id, 'condominio_id' => $c->id, 'esercizio_id' => $e->id, 'nome' => $natura === 'ordinaria' ? 'Preventivo 2026' : 'Rifacimento facciata', 'stato' => 'approvato',
        'tipo' => $natura === 'ordinaria' ? 'ordinario' : 'straordinario', 'numero_rate' => $numeroRate, 'giorno_scadenza' => 5, 'data_prima_scadenza' => $primaScadenza,
        'metodo_distribuzione' => $metodo, 'applica_saldi' => true, 'data_delibera_assemblea' => $delibera,
    ]);
    if ($natura !== 'ordinaria') {
        $fornitoreId = DB::table('fornitori')->insertGetId([
            'ragione_sociale' => 'Impresa Facciate Srl', 'soggetto_ritenuta' => false, 'ritenuta_decisa_il' => now(), 'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 4,
            'giorni_scadenza' => 30, 'modalita_pagamento_default' => 'bonifico', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $fattura = FatturaPassiva::create([
            'condominio_id' => $c->id, 'fornitore_id' => $fornitoreId, 'esercizio_id' => $e->id, 'tipo_documento' => 'fattura', 'numero_documento' => "FT-RB{$seq}",
            'data_documento' => $delibera, 'data_scadenza' => $delibera, 'is_pregresso' => false, 'importo_imponibile' => 120000, 'importo_iva' => 0, 'importo_ritenuta' => 0,
            'totale_documento' => 120000, 'netto_a_pagare' => 120000, 'stato_pagamento' => 'aperta', 'stato_approvazione' => 'approvata', 'modalita_pagamento' => 'bonifico',
            'competenza_dal' => $competenzaFattura[0] ?? null, 'competenza_al' => $competenzaFattura[1] ?? null,
        ]);
        DB::table('righe_fattura')->insert([
            'fattura_passiva_id' => $fattura->id, 'conto_id' => $conto->id, 'immobile_id' => null, 'descrizione' => 'Rifacimento facciata', 'aliquota_iva' => 0,
            'importo_imponibile' => 120000, 'importo_iva' => 0, 'is_sopravvenienza' => true, 'is_rateizzata' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $piano->fatture()->attach($fattura->id, ['importo_collegato' => 120000]);
    }
    if ($versatoVenditore !== 0) {
        // Il già versato della persona (decisione 17): scontato dalla sua quota, non divisibile.
        DB::table('contributi_versati')->insert(['condominio_id' => $c->id, 'target_type' => Conto::class, 'target_id' => $conto->id, 'immobile_id' => $unita->id, 'anagrafica_id' => $v->id,
            'importo_cents' => $versatoVenditore, 'natura' => 'avanzo', 'origine' => 'migrazione', 'created_at' => now(), 'updated_at' => now()]);
    }
    app(GeneratePianoRateAction::class)->execute($piano, forzaApplicazioneSaldi: true, esercizio: $e);

    return compact('c', 'e', 'g', 'unita', 'v', 'a', 'rigaV', 'piano');
}

/** Emette a giornale le rate con scadenza fino al giorno dato: di norma il 30/4, le prime quattro e la rata 0 che scade con la prima. */
function rbEmettiFinoAdAprile(array $s, string $fino = '2026-04-30'): void
{
    $scritturaId = DB::table('scritture_contabili')->insertGetId([
        'condominio_id' => $s['c']->id, 'gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id, 'data_registrazione' => '2026-01-05', 'data_competenza' => '2026-01-05',
        'numero_protocollo' => 'EMI-' . $s['piano']->id . '-' . $fino, 'causale' => 'emissione rate', 'descrizione' => 'emissione', 'tipo_movimento' => 'emissione_rate', 'stato' => 'registrata',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $rate = DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('data_scadenza', '<=', $fino . ' 23:59:59')->pluck('id');
    DB::table('rate')->whereIn('id', $rate)->update(['stato' => 'emessa', 'data_emissione' => '2026-01-05']);
    DB::table('rate_quote')->whereIn('rata_id', $rate)->update(['scrittura_contabile_id' => $scritturaId]);
}

function rbVendita(array $s, string $decorrenza = '2026-05-01'): array
{
    return [
        'tipo' => 'vendita', 'riga_uscente_id' => $s['rigaV'], 'anagrafica_entrante_id' => $s['a']->id, 'decorrenza' => $decorrenza,
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-05-06',
        'estremi_titolo' => 'atto notaio Verdi, rep. 12345', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Rogito letto, bozze a chi compra',
    ];
}

/** Le quote del piano per persona: preventivo e pregresso, dalle regole congelate di ogni quota. */
function rbQuote(array $s): array
{
    $righe = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s['piano']->id)
        ->get(['rate_quote.anagrafica_id', 'rate_quote.importo', 'rate_quote.regole_calcolo', 'rate.numero_rata', 'rate.stato', 'rate.data_scadenza']);
    $perPersona = fn (int $id) => $righe->where('anagrafica_id', $id);
    $importi = fn ($r) => json_decode((string) $r->regole_calcolo, true)['importi'] ?? [];

    return [
        'righe' => $righe,
        'v_preventivo' => (int) $perPersona($s['v']->id)->sum(fn ($r) => (int) ($importi($r)['quota_pura_gestione'] ?? 0)),
        'v_pregresso' => (int) $perPersona($s['v']->id)->sum(fn ($r) => (int) ($importi($r)['saldo_usato'] ?? 0)),
        'a_preventivo' => (int) $perPersona($s['a']->id)->sum(fn ($r) => (int) ($importi($r)['quota_pura_gestione'] ?? 0)),
        'a_pregresso' => (int) $perPersona($s['a']->id)->sum(fn ($r) => (int) ($importi($r)['saldo_usato'] ?? 0)),
        'v_importo' => (int) $perPersona($s['v']->id)->sum('importo'),
        'a_importo' => (int) $perPersona($s['a']->id)->sum('importo'),
    ];
}

/** La coppia scritta dal passaggio: [debito di chi entra, credito di chi esce]. */
function rbCoppia(array $s): array
{
    $coppia = Saldo::whereNotNull('subentro_id')->where('immobile_id', $s['unita']->id)->get();

    return [(int) $coppia->where('anagrafica_id', $s['a']->id)->sum('saldo_iniziale'), (int) $coppia->where('anagrafica_id', $s['v']->id)->sum('saldo_iniziale')];
}

dataset('metodi e saldi', [
    'rata zero, venditore a debito € 180,00' => ['rata_zero', 18000],
    'rata zero, venditore a credito € 60,00' => ['rata_zero', -6000],
    'prima rata, venditore a debito € 180,00' => ['prima_rata', 18000],
    'prima rata, venditore a credito € 60,00' => ['prima_rata', -6000],
    'spalmati, venditore a debito € 180,00' => ['tutte_rate', 18000],
    'spalmati, venditore a credito € 60,00' => ['tutte_rate', -6000],
    'nessun saldo' => ['prima_rata', 0],
]);

it('vendita il 1° maggio, quattro rate emesse: le otto bozze passano a chi entra per il preventivo, il pregresso resta a chi esce qualunque sia il metodo, la coppia è € 5,48 e i totali per persona tornano ai giorni (120/245)', function (string $metodo, int $saldo) {
    $s = rbScenario($metodo, $saldo);
    rbEmettiFinoAdAprile($s);
    $emessePrima = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s['piano']->id)->where('rate.stato', 'emessa')
        ->orderBy('rate_quote.id')->get(['rate_quote.*'])->map(fn ($q) => (array) $q)->all();
    $totaliRatePrima = DB::table('rate')->where('piano_rate_id', $s['piano']->id)->orderBy('numero_rata')->pluck('importo_totale', 'numero_rata')->all();

    // L'anteprima dice cosa succederà alle bozze, e propone la coppia che poi si scrive.
    $anteprima = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), rbVendita($s))->assertOk()->json();
    $frasi = implode("\n", $anteprima['rate']['conguaglio']['frasi'] ?? []);
    expect($frasi)->toContain('passano a Acquirente Elsa')->toContain('cambia l\'intestatario, non l\'importo')
        ->and((int) $anteprima['rate']['conguaglio']['totale_entrante'])->toBe(548);

    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), rbVendita($s))->assertSessionHasNoErrors();

    // Le rate emesse non si toccano (invariante 21, nella forma della B3a: «nessuna quota emessa cambia»).
    $emesseDopo = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s['piano']->id)->where('rate.stato', 'emessa')
        ->orderBy('rate_quote.id')->get(['rate_quote.*'])->map(fn ($q) => (array) $q)->all();
    expect($emesseDopo)->toBe($emessePrima);
    // Nessuna rata cambia importo: cambia l'intestatario, non l'importo.
    expect(DB::table('rate')->where('piano_rate_id', $s['piano']->id)->orderBy('numero_rata')->pluck('importo_totale', 'numero_rata')->all())->toBe($totaliRatePrima);

    $q = rbQuote($s);
    // Le otto bozze da maggio a dicembre: il preventivo (8 × € 100,00) è di chi entra; nessun pregresso gli passa.
    expect($q['a_preventivo'])->toBe(80000)->and($q['a_pregresso'])->toBe(0)
        // Al venditore restano le quattro emesse e tutto il suo pregresso, con qualunque metodo.
        ->and($q['v_preventivo'])->toBe(40000)->and($q['v_pregresso'])->toBe($saldo);
    foreach ($q['righe']->where('stato', 'bozza') as $bozza) {
        expect($bozza->data_scadenza >= '2026-05-01')->toBeTrue();
    }

    // La coppia: la parte di chi entra sull'intero piano (120.000 × 245/365 = 80.548) meno le bozze che già paga (80.000).
    [$debitoA, $creditoV] = rbCoppia($s);
    expect($debitoA)->toBe(548)->and($creditoV)->toBe(-548);
    // I totali per persona, sul preventivo: i giorni (120/245), al centesimo.
    expect($q['v_preventivo'] + $creditoV)->toBe(39452)->and($q['a_preventivo'] + $debitoA)->toBe(80548);
})->with('metodi e saldi');

it('vendita il 15 maggio: la rata del 5 maggio è ancora del venditore (scade prima), passano le sette da giugno, e la coppia è € 59,45 (75.945 − 70.000)', function () {
    $s = rbScenario('tutte_rate', 18000);
    rbEmettiFinoAdAprile($s);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), rbVendita($s, '2026-05-15'))->assertSessionHasNoErrors();

    $q = rbQuote($s);
    expect($q['a_preventivo'])->toBe(70000)->and($q['v_preventivo'])->toBe(50000)->and($q['v_pregresso'])->toBe(18000)->and($q['a_pregresso'])->toBe(0);
    [$debitoA, $creditoV] = rbCoppia($s);
    // 120.000 × 231/365 = 75.945,2 → 75.945; meno le sette bozze passate (70.000).
    expect($debitoA)->toBe(5945)->and($creditoV)->toBe(-5945)
        ->and($q['v_preventivo'] + $creditoV)->toBe(44055)->and($q['a_preventivo'] + $debitoA)->toBe(75945);
});

it('straordinario, delibera PRIMA del rogito: la spesa è di chi esce, le bozze restano sue e non c\'è coppia (art. 63 disp. att. c.c.; Cass. 24654/2010)', function () {
    // Delibera del 15 marzo, sei rate da aprile, la prima emessa; rogito il 1° maggio.
    $s = rbScenario('prima_rata', 0, 'straordinaria', '2026-03-15', '2026-04-05', 6);
    rbEmettiFinoAdAprile($s);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), rbVendita($s))->assertSessionHasNoErrors();

    $q = rbQuote($s);
    expect($q['v_preventivo'])->toBe(120000)->and($q['a_preventivo'])->toBe(0)->and(rbCoppia($s))->toBe([0, 0]);
});

it('straordinario, delibera DOPO il rogito registrato in ritardo: la spesa è tutta di chi entra, le bozze passano e la coppia gli fa pagare le due emesse', function () {
    // Rogito il 1° maggio, ma l'amministratore lo scopre ad agosto: intanto ha deliberato il 20 maggio, generato sei rate
    // da giugno col solo venditore ed emesso giugno e luglio. La spesa è di chi entra per intero.
    $s = rbScenario('prima_rata', 0, 'straordinaria', '2026-05-20', '2026-06-05', 6);
    rbEmettiFinoAdAprile($s, '2026-07-31');
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), rbVendita($s))->assertSessionHasNoErrors();

    $q = rbQuote($s);
    // Le quattro bozze da agosto a novembre (4 × € 200,00) sono di chi entra; al venditore restano le due emesse.
    expect($q['a_preventivo'])->toBe(80000)->and($q['v_preventivo'])->toBe(40000);
    // Tutto il piano è di chi entra: le bozze le paga già, le due emesse gliele porta la coppia.
    expect(rbCoppia($s))->toBe([40000, -40000]);
});

it('i promemoria del portale seguono le quote: a chi esce restano le rate emesse (e con i saldi spalmati il suo pregresso sulle bozze), a chi entra arrivano le otto bozze', function (string $metodo, int $saldo, int $pregressoPerRata) {
    $s = rbScenario($metodo, $saldo);
    // L'approvazione del piano crea un promemoria per (rata, persona) nel portale, come nel prodotto.
    (new \App\Listeners\Gestionale\SyncScadenziarioWithPianoRate())->handle(new \App\Events\Gestionale\PianoRateStatusUpdated($s['c'], $s['e'], $s['piano'], $this->user, \App\Enums\StatoPianoRate::BOZZA, \App\Enums\StatoPianoRate::APPROVATO));
    rbEmettiFinoAdAprile($s);
    $promemoria = fn (int $anagraficaId) => \App\Models\Evento::where('tipo', \App\Enums\EventoTipo::SCADENZA_RATA_CONDOMINO->value)
        ->whereHas('anagrafiche', fn ($q) => $q->where('anagrafica_id', $anagraficaId))->get()
        ->mapWithKeys(fn ($e) => [(int) $e->meta['numero_rata'] => (int) $e->meta['importo_originale']])->sortKeys()->all();
    expect($promemoria($s['a']->id))->toBe([]);

    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), rbVendita($s))->assertSessionHasNoErrors();

    // Chi entra: un promemoria per ciascuna delle otto rate da maggio, col solo preventivo.
    expect($promemoria($s['a']->id))->toBe(array_fill_keys(range(5, 12), 10000));
    // Chi esce: le rate emesse come prima; sulle bozze solo il suo pregresso, se ce n'è.
    $diChiEsce = $promemoria($s['v']->id);
    expect(array_keys($diChiEsce))->toBe($pregressoPerRata === 0 ? range(1, 4) : range(1, 12));
    if ($pregressoPerRata !== 0) {
        expect(array_slice($diChiEsce, 4, null, true))->toBe(array_fill_keys(range(5, 12), $pregressoPerRata));
    }
})->with([
    'prima rata, nessun saldo' => ['prima_rata', 0, 0],
    'spalmati, venditore a debito € 180,00' => ['tutte_rate', 18000, 1500],
]);

it('con la rinuncia al conguaglio le bozze passano lo stesso: le parti hanno regolato fra loro le due righe in saldi, non chi paga le rate che devono ancora scadere', function () {
    $s = rbScenario('prima_rata', 0);
    rbEmettiFinoAdAprile($s);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), rbVendita($s) + ['rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Regolato nel rogito fra le parti'])->assertSessionHasNoErrors();

    expect(rbQuote($s)['a_preventivo'])->toBe(80000)->and(rbCoppia($s))->toBe([0, 0]);
});

it('una bozza già pagata in parte non passa: l\'anticipo è di chi esce, la quota resta sua e il conguaglio la comprende (80.548 − 70.000 = 10.548)', function () {
    $s = rbScenario('prima_rata', 0);
    rbEmettiFinoAdAprile($s);
    $rata7 = (int) DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('numero_rata', 7)->value('id');
    DB::table('rate_quote')->where('rata_id', $rata7)->update(['importo_pagato' => 5000, 'stato' => 'parzialmente_pagata']);

    $anteprima = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), rbVendita($s))->assertOk()->json();
    expect($anteprima['rate']['conguaglio']['quote_in_bozza'])->toBe([['piano' => 'Preventivo 2026', 'intestatario' => 'Venditore Ugo', 'n' => 1, 'motivo' => 'pagata']])
        ->and(implode("\n", $anteprima['rate']['conguaglio']['frasi']))->toContain('Compresa la quota del piano «Preventivo 2026» non ancora emessa che ha già un pagamento: resterà intestata a Venditore Ugo e si conguaglia qui.')
        ->and(implode(' | ', $anteprima['cancello']['motivi']))->toContain('il piano «Preventivo 2026» ha 8 quote non ancora emesse intestate a Venditore Ugo: non si può più ricalcolare, 7 passano a Acquirente Elsa (cambia l\'intestatario, non l\'importo); 1 resta sua ed è compresa nel conguaglio');

    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), rbVendita($s))->assertSessionHasNoErrors();
    expect(DB::table('rate_quote')->where('rata_id', $rata7)->value('anagrafica_id'))->toBe($s['v']->id)
        ->and(rbQuote($s)['a_preventivo'])->toBe(70000)->and(rbCoppia($s))->toBe([10548, -10548]);
});

it('nell\'usufrutto le bozze non passano: l\'usufruttuario paga solo una parte delle voci, e cambiare il nome sulla quota intera sposterebbe anche il resto (decisione 21 invariata)', function () {
    $s = rbScenario('prima_rata', 0);
    rbEmettiFinoAdAprile($s);
    $usufrutto = ['tipo' => 'usufrutto', 'sottotipo' => 'costituzione', 'riga_uscente_id' => $s['rigaV'], 'anagrafica_entrante_id' => $s['a']->id, 'decorrenza' => '2026-05-01',
        'quota' => 100, 'tipologia' => 'usufruttuario', 'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Costituzione di usufrutto, letto'];

    $anteprima = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), $usufrutto)->assertOk()->json();
    expect($anteprima['rate']['conguaglio']['bozze_riassegnate'])->toBe([])
        ->and($anteprima['rate']['conguaglio']['quote_in_bozza'][0]['motivo'])->toBe('passaggio');

    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $usufrutto)->assertSessionHasNoErrors();
    expect(rbQuote($s)['a_preventivo'])->toBe(0)->and(rbCoppia($s))->toBe([80548, -80548]);
});

it('catena di due vendite: le bozze passate all\'acquirente di maggio passano di nuovo a chi compra da lui a settembre, e ognuno paga i suoi giorni (V 120, A 123, B 122)', function () {
    $s = rbScenario('prima_rata', 0);
    rbEmettiFinoAdAprile($s);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), rbVendita($s))->assertSessionHasNoErrors();
    // Da maggio ad agosto A riceve ed emette le sue rate; il 1° settembre vende a B.
    rbEmettiFinoAdAprile($s, '2026-08-31');
    $b = Anagrafica::forceCreate(['nome' => 'Compratrice Bice', 'email' => 'rb-b@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'RBCOMPRATRICE01']);
    $b->condomini()->syncWithoutDetaching([$s['c']->id]);
    $rigaA = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['a']->id)->whereNull('data_fine')->value('id');
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), [
        'tipo' => 'vendita', 'riga_uscente_id' => $rigaA, 'anagrafica_entrante_id' => $b->id, 'decorrenza' => '2026-09-01',
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-09-06',
        'estremi_titolo' => 'atto notaio Verdi, rep. 12400', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Secondo rogito, bozze a chi compra',
    ])->assertSessionHasNoErrors();

    $preventivo = fn (int $id) => (int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s['piano']->id)->where('rate_quote.anagrafica_id', $id)->sum('rate_quote.importo');
    $saldo = fn (int $id) => (int) Saldo::whereNotNull('subentro_id')->where('anagrafica_id', $id)->sum('saldo_iniziale');
    expect($preventivo($s['v']->id))->toBe(40000)->and($preventivo($s['a']->id))->toBe(40000)->and($preventivo($b->id))->toBe(40000);
    // B: la sua parte dell'intero piano (120.000 × 122/365 = 40.110) meno le quattro bozze (40.000). (Le bozze passate ad A
    // si rileggono dal riparto del venditore, `righe_di`; con una voce sola il ramo base darebbe gli stessi centesimi.)
    expect($saldo($b->id))->toBe(110)
        // Ognuno, preventivo più conguagli, paga i suoi giorni: 39.452 (120), 40.438 (123), 40.110 (122).
        ->and($preventivo($s['v']->id) + $saldo($s['v']->id))->toBe(39452)
        ->and($preventivo($s['a']->id) + $saldo($s['a']->id))->toBe(40438)
        ->and($preventivo($b->id) + $saldo($b->id))->toBe(40110);
});

it('rogito il 5 maggio, lo stesso giorno della rata 5: la rata passa, le bozze coprono più dei suoi giorni e la coppia si rovescia — credito € 7,67 a chi entra, e pannello e avviso lo dicono nel verso giusto', function () {
    $s = rbScenario('prima_rata', 0);
    rbEmettiFinoAdAprile($s);

    $anteprima = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), rbVendita($s, '2026-05-05'))->assertOk()->json();
    // 120.000 × 241/365 = 79.232,9 → 79.233; le otto bozze da maggio valgono 80.000.
    expect((int) $anteprima['rate']['conguaglio']['totale_entrante'])->toBe(-767)
        ->and($anteprima['rate']['conguaglio']['totale_entrante_assoluto_formattato'])->toBe('€ 7,67')
        ->and(implode("\n", $anteprima['rate']['conguaglio']['frasi']))->toContain('credito € 7,67 a Acquirente Elsa, debito € 7,67 a Venditore Ugo (la parte di Acquirente Elsa sull\'intero piano è € 792,33, e le rate in bozza che passano a suo nome valgono € 800,00: coprono più dei suoi giorni)');

    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), rbVendita($s, '2026-05-05'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('passaggio_registrato', fn ($p) => $p['conguaglio'] === '€ 7,67' && $p['conguaglio_rovesciato'] === true && $p['riassegnate'] === 8
            && str_contains((string) $p['riassegnate_frase'], 'Le 8 rate in bozza del piano «Preventivo 2026» sono passate a Acquirente Elsa'));
    // Il debito è di chi esce: somma zero, e i totali per persona tornano ai giorni (124 e 241).
    expect(rbCoppia($s))->toBe([-767, 767])
        ->and(rbQuote($s)['v_preventivo'] + 767)->toBe(40767)
        ->and(rbQuote($s)['a_preventivo'] - 767)->toBe(79233);
});

/*
|--------------------------------------------------------------------------
| Fase 1-bis della beta.34 — i reperti confermati, uno per test
|--------------------------------------------------------------------------
*/

it('R1 — dopo la vendita le stampe del riparto non hanno «Fuori riparto»: le bozze passate stanno in «Passate con un passaggio», tolte a chi vende e date a chi compra, e nessun errore nel log', function () {
    \Illuminate\Support\Facades\Log::spy();
    $s = rbScenario('tutte_rate', 18000);
    rbEmettiFinoAdAprile($s);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), rbVendita($s))->assertSessionHasNoErrors();

    foreach ([app(\App\Services\RipartoTabelleService::class)->buildMatrice($s['piano']), app(\App\Services\RipartoCapitoliService::class)->buildMatrice($s['piano'])] as $m) {
        $colonne = $m['tabelle'] ?? $m['capitoli'];
        $chiave = isset($m['tabelle']) ? 'per_tabella' : 'per_capitolo';
        expect($colonne)->not->toHaveKey('fuori_riparto')->and($colonne)->toHaveKey('passate');
        $soggetti = collect($m['righe'][$s['unita']->id]['soggetti']);
        $ugo = $soggetti[$s['v']->id];
        $elsa = $soggetti[$s['a']->id];
        // Ugo: € 1.200 di riparto, −€ 800 passate, € 180 di pregresso = € 580, quanto ha in rate.
        expect($ugo[$chiave]['passate']['importo'])->toBe(-80000)->and($ugo['totale'])->toBe(58000)
            ->and($elsa[$chiave]['passate']['importo'])->toBe(80000)->and($elsa['totale'])->toBe(80000);
    }
    \Illuminate\Support\Facades\Log::shouldNotHaveReceived('error');
});

it('R3 — il compratore già comproprietario ha due quote sulla stessa rata: l\'estratto conto le conta tutte e due (€ 220,00, non € 180,00)', function () {
    $s = rbScenario('prima_rata', 0);
    // Elsa è già comproprietaria al 30 %, Ugo al 70 %.
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 70]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['a']->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'proprietario', 'quota' => 30, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('rate')->where('piano_rate_id', $s['piano']->id)->delete();
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    app(GeneratePianoRateAction::class)->execute($s['piano']->fresh(), esercizio: $s['e']);
    $emetti = fn (string $fino) => $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $s['piano']]), [
        'rate_ids' => DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('stato', 'bozza')->where('data_scadenza', '<=', $fino . ' 23:59:59')->pluck('id')->all(),
        'data_emissione' => '2026-01-05', 'invia_notifiche' => false,
    ]);
    $emetti('2026-04-30');
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), array_replace(rbVendita($s), ['quota' => 70]))->assertSessionHasNoErrors();
    $emetti('2026-05-31');
    $rata5 = (int) DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('numero_rata', 5)->value('id');
    expect(DB::table('rate_quote')->where('rata_id', $rata5)->where('anagrafica_id', $s['a']->id)->pluck('importo')->sort()->values()->all())->toBe([3000, 7000]);

    $controller = app(\App\Http\Controllers\Gestionale\PianiRate\EstrattoContoAnagraficaController::class);
    $metodo = new ReflectionMethod($controller, 'buildLedger');
    [$timeline, $stats] = $metodo->invoke($controller, $s['c'], $s['e'], $s['a']);
    // Rate 1–4 al 30 % (4 × 3.000) più la rata 5 intera (7.000 + 3.000).
    expect(collect($timeline)->where('dare', '>', 0)->sum('dare'))->toBe(22000);
});

it('R4 — vendita della nuda proprietà dopo l\'usufrutto: le ordinarie del piano generato prima sono dell\'usufruttuario (art. 1004 c.c.), nessuna bozza passa a chi compra e nessuna coppia; netti 394,52 / 805,48 / 0', function () {
    $s = rbScenario('prima_rata', 0);
    rbEmettiFinoAdAprile($s);
    $ursula = Anagrafica::forceCreate(['nome' => 'Usufruttuaria Ursula', 'email' => 'rb-u@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'RBUSUFRUTTU00001']);
    $carlo = Anagrafica::forceCreate(['nome' => 'Compratore Carlo', 'email' => 'rb-cc@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'RBCOMPRATOR00001']);
    $ursula->condomini()->syncWithoutDetaching([$s['c']->id]);
    $carlo->condomini()->syncWithoutDetaching([$s['c']->id]);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), [
        'tipo' => 'usufrutto', 'sottotipo' => 'costituzione', 'riga_uscente_id' => $s['rigaV'], 'anagrafica_entrante_id' => $ursula->id, 'decorrenza' => '2026-05-01',
        'quota' => 100, 'tipologia' => 'usufruttuario', 'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Costituzione usufrutto, letto',
    ])->assertSessionHasNoErrors();
    rbEmettiFinoAdAprile($s, '2026-08-31');
    $rigaNuda = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['v']->id)->where('tipologia', 'nuda_proprietario')->value('id');
    $vendita = ['tipo' => 'vendita', 'riga_uscente_id' => $rigaNuda, 'anagrafica_entrante_id' => $carlo->id, 'decorrenza' => '2026-09-01', 'quota' => 100, 'tipologia' => 'nuda_proprietario',
        'copia_autentica' => true, 'copia_autentica_il' => '2026-09-05', 'estremi_titolo' => 'rep. 3', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Vendita della nuda, letto'];

    $anteprima = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), $vendita)->assertOk()->json();
    expect($anteprima['rate']['conguaglio']['bozze_riassegnate'])->toBe([])
        ->and(collect($anteprima['rate']['conguaglio']['quote_in_bozza'])->pluck('motivo')->unique()->all())->toBe(['ordinaria_dell_usufruttuario'])
        ->and(implode("\n", $anteprima['rate']['conguaglio']['frasi']))->toContain('dell\'usufruttuario (art. 1004 c.c.)')
        // Decisione 28.4 (beta.38): verso il condominio chi compra la nuda proprietà ne risponde in solido con
        // l'usufruttuario (art. 67 ult. co.); fra le parti le paga l'usufruttuario. La frase non dice più «non ne risponde».
        ->and(implode("\n", $anteprima['rate']['conguaglio']['frasi']))->not->toContain('non ne risponde')->toContain('non passano a');
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $vendita)->assertSessionHasNoErrors();

    $preventivo = fn (int $id) => (int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s['piano']->id)->where('rate_quote.anagrafica_id', $id)->sum('rate_quote.importo');
    $saldo = fn (int $id) => (int) Saldo::whereNotNull('subentro_id')->where('anagrafica_id', $id)->sum('saldo_iniziale');
    expect($preventivo($carlo->id))->toBe(0)->and($saldo($carlo->id))->toBe(0)
        ->and($preventivo($s['v']->id) + $saldo($s['v']->id))->toBe(39452)
        ->and($saldo($ursula->id))->toBe(80548);
});

it('R4, controllo — con l\'usufrutto già in essere alla generazione il motore dà l\'ordinaria al nudo proprietario, e nella vendita della nuda le bozze passano a chi compra come sempre', function () {
    $s = rbScenario('prima_rata', 0);
    $ursula = Anagrafica::forceCreate(['nome' => 'Usufruttuaria Ursula', 'email' => 'rb-u2@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'RBUSUFRUTTU00002']);
    $carlo = Anagrafica::forceCreate(['nome' => 'Compratore Carlo', 'email' => 'rb-cc2@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'RBCOMPRATOR00002']);
    $ursula->condomini()->syncWithoutDetaching([$s['c']->id]);
    $carlo->condomini()->syncWithoutDetaching([$s['c']->id]);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'nuda_proprietario']);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $ursula->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'usufruttuario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('rate')->where('piano_rate_id', $s['piano']->id)->delete();
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    app(GeneratePianoRateAction::class)->execute($s['piano']->fresh(), esercizio: $s['e']);
    expect(DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->pluck('ruolo_risolto')->unique()->all())->toBe(['nuda_proprietario']);
    rbEmettiFinoAdAprile($s);

    $anteprima = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), [
        'tipo' => 'vendita', 'riga_uscente_id' => $s['rigaV'], 'anagrafica_entrante_id' => $carlo->id, 'decorrenza' => '2026-05-01', 'quota' => 100, 'tipologia' => 'nuda_proprietario',
        'copia_autentica' => true, 'copia_autentica_il' => '2026-05-05', 'estremi_titolo' => 'rep. 4', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Vendita della nuda, letto',
    ])->assertOk()->json();
    expect($anteprima['rate']['conguaglio']['bozze_riassegnate'])->toHaveCount(8)
        ->and((int) $anteprima['rate']['conguaglio']['totale_entrante'])->toBe(548);
});

it('R5 nel conguaglio (difetto della beta.31) — la proprietaria paga per ripiego i 120 giorni senza inquilino, in due buchi: chi compra il 1/5 ne ha 61, non 245/365, e la coppia si rovescia (€ 19,00 a suo credito)', function () {
    $s = rbScenario('prima_rata', 0);
    DB::table('conto_tabella_ripartizioni')->update(['soggetto' => 'inquilino']);
    $ivo = Anagrafica::forceCreate(['nome' => 'Inquilino Ivo', 'email' => 'rb-ivo@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'RBINQUILINO00001']);
    $ivo->condomini()->syncWithoutDetaching([$s['c']->id]);
    $locazione = fn (array $extra) => $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $extra + [
        'quota' => 100, 'tipologia' => 'inquilino', 'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Locazione registrata, letto'])->assertSessionHasNoErrors();
    $locazione(['tipo' => 'inizio_locazione', 'anagrafica_entrante_id' => $ivo->id, 'decorrenza' => '2026-03-01']);
    $locazione(['tipo' => 'fine_locazione', 'riga_uscente_id' => (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $ivo->id)->value('id'), 'decorrenza' => '2026-11-01']);
    DB::table('rate')->where('piano_rate_id', $s['piano']->id)->delete();
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    app(GeneratePianoRateAction::class)->execute($s['piano']->fresh(), accettaDestinatari: true, notaDestinatari: 'Inquilino da marzo a ottobre', esercizio: $s['e']);
    rbEmettiFinoAdAprile($s);

    $anteprima = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), rbVendita($s))->assertOk()->json();
    $g = $anteprima['rate']['conguaglio']['per_gestione'][0];
    // La riga di ripiego di Ugo: 120.000 × 120/365 = 39.452 per 120 giorni (gen–feb e nov–dic); a Elsa i 61 di novembre e
    // dicembre, 39.452 × 61/120 = 20.055 — prima della correzione 39.452 × 245/365 = 26.481.
    expect($g['importo_lordo'])->toBe(20055)->and($g['importo'])->toBe(20055 - $g['bozze_passate_importo'])
        ->and(implode("\n", $anteprima['rate']['conguaglio']['frasi']))->toContain('59 a Venditore Ugo, 61 a Acquirente Elsa');
});

it('R8 — straordinaria con competenza dichiarata su tutto l\'anno e un già versato della persona: la spesa è divisa, le bozze restano a chi esce anche se il versato rende negativa la sua parte', function () {
    $s = rbScenario('prima_rata', 0, 'straordinaria', '2026-01-02', '2026-01-05', 12, ['2026-01-01', '2026-12-31'], 60000);
    rbEmettiFinoAdAprile($s);
    $anteprima = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), rbVendita($s))->assertOk()->json();
    expect($anteprima['rate']['conguaglio']['bozze_riassegnate'])->toBe([])
        ->and(collect($anteprima['rate']['conguaglio']['quote_in_bozza'])->pluck('motivo')->unique()->all())->toBe(['straordinaria_divisa']);
});

it('R9 — chi vende a credito con i saldi spalmati: il promemoria delle bozze che gli restano diventa un credito, e la descrizione lo dice (non «ti ricordiamo la scadenza»)', function () {
    $s = rbScenario('tutte_rate', -6000);
    (new \App\Listeners\Gestionale\SyncScadenziarioWithPianoRate())->handle(new \App\Events\Gestionale\PianoRateStatusUpdated($s['c'], $s['e'], $s['piano'], $this->user, \App\Enums\StatoPianoRate::BOZZA, \App\Enums\StatoPianoRate::APPROVATO));
    rbEmettiFinoAdAprile($s);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), rbVendita($s))->assertSessionHasNoErrors();

    $rata7 = (int) DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('numero_rata', 7)->value('id');
    $evento = \App\Models\Evento::where('tipo', \App\Enums\EventoTipo::SCADENZA_RATA_CONDOMINO->value)->whereJsonContains('meta->context->rata_id', $rata7)
        ->whereHas('anagrafiche', fn ($q) => $q->where('anagrafica_id', $s['v']->id))->firstOrFail();
    expect((int) $evento->meta['importo_originale'])->toBe(-500)
        ->and($evento->description)->toContain('credito a tuo favore')->not->toContain('ti ricordiamo la scadenza');
});

it('R11 e R12 — catena con pregresso spalmato: alla rivendita il pannello dà a chi rivende i suoi giorni (123, non 243), i pregressi a chi li ha (Ugo), e le quote di solo pregresso di Ugo non «si conguagliano»', function () {
    $s = rbScenario('tutte_rate', 18000);
    rbEmettiFinoAdAprile($s);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), rbVendita($s))->assertSessionHasNoErrors();
    rbEmettiFinoAdAprile($s, '2026-08-31');
    $b = Anagrafica::forceCreate(['nome' => 'Compratrice Bice', 'email' => 'rb-b2@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'RBCOMPRATRICE02']);
    $b->condomini()->syncWithoutDetaching([$s['c']->id]);
    $rigaA = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['a']->id)->whereNull('data_fine')->value('id');
    $anteprima = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), [
        'tipo' => 'vendita', 'riga_uscente_id' => $rigaA, 'anagrafica_entrante_id' => $b->id, 'decorrenza' => '2026-09-01', 'quota' => 100, 'tipologia' => 'proprietario',
        'copia_autentica' => true, 'copia_autentica_il' => '2026-09-06', 'estremi_titolo' => 'rep. 5', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Secondo rogito, letto',
    ])->assertOk()->json();
    $frasi = implode("\n", $anteprima['rate']['conguaglio']['frasi']);

    expect($frasi)->toContain('123 a Acquirente Elsa, 122 a Compratrice Bice')->not->toContain('243 a Acquirente Elsa')
        ->toContain('delle quote intestate a Venditore Ugo sono saldi pregressi')->not->toContain('delle quote intestate a Acquirente Elsa sono saldi pregressi')
        ->and(collect($anteprima['rate']['conguaglio']['quote_in_bozza'])->firstWhere('intestatario', 'Venditore Ugo')['motivo'])->toBe('solo_pregresso');
});

it('R17 — una bozza con un pagamento segnalato dal portale e non ancora verificato non passa a chi entra', function () {
    $s = rbScenario('prima_rata', 0);
    (new \App\Listeners\Gestionale\SyncScadenziarioWithPianoRate())->handle(new \App\Events\Gestionale\PianoRateStatusUpdated($s['c'], $s['e'], $s['piano'], $this->user, \App\Enums\StatoPianoRate::BOZZA, \App\Enums\StatoPianoRate::APPROVATO));
    rbEmettiFinoAdAprile($s);
    $rata6 = (int) DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('numero_rata', 6)->value('id');
    $evento = \App\Models\Evento::whereJsonContains('meta->context->rata_id', $rata6)->where('tipo', \App\Enums\EventoTipo::SCADENZA_RATA_CONDOMINO->value)->firstOrFail();
    $meta = $evento->meta;
    $meta['status'] = 'reported';
    $evento->meta = $meta;
    $evento->save();

    $anteprima = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), rbVendita($s))->assertOk()->json();
    expect($anteprima['rate']['conguaglio']['bozze_riassegnate'])->toHaveCount(7)
        ->and(collect($anteprima['rate']['conguaglio']['quote_in_bozza'])->pluck('motivo')->all())->toBe(['segnalata']);
});


/*
|--------------------------------------------------------------------------
| Decisione 26 (1.11.0-beta.35) — il piano da fatture su gestione ordinaria segue la competenza dichiarata
|--------------------------------------------------------------------------
|
| Lo stesso condominio del forum, ma il piano è «da fatture» (`tipo = straordinario`) su una gestione ORDINARIA, come
| quello che il pulsante «Finanzia spesa» apre dalla dashboard. Fino alla beta.34 la competenza dichiarata sulla fattura
| si ignorava sull'ordinaria: la pregressa del 2025 si divideva sul 2026 e dopo la vendita del 1/5 chi entrava ne pagava
| 245/365. Con la decisione 26 motore e conguaglio leggono la competenza della fattura, e le bozze di una voce che è tutta
| di chi esce restano sue (niente andata e ritorno, come per la straordinaria nella decisione 25, punto 2).
*/

/**
 * @param list<array{importo:int, pregressa?:bool, competenza?:?array{0:string,1:string}, ad_personam?:string}> $fatture tutte sullo stesso conto;
 *   con `ad_personam` (la descrizione) la riga è addebitata direttamente all'unità, senza conto
 */
function rbScenarioDaFatture(array $fatture, string $metodo = 'prima_rata', int $saldoVenditore = 0): array
{
    static $seq = 0;
    $seq++;
    $c = Condominio::factory()->create();
    $e = Esercizio::factory()->create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = Gestione::factory()->create(['condominio_id' => $c->id, 'nome' => 'Ordinaria 2026', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($e, $g->id);
    $pc = PianoConto::create(['condominio_id' => $c->id, 'gestione_id' => $g->id, 'nome' => 'PC']);
    $conto = Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Manutenzioni', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 0]);
    $tabella = Tabella::create(['condominio_id' => $c->id, 'nome' => 'Proprietà', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    $unita = Immobile::create(['condominio_id' => $c->id, 'tipo' => 'appartamento', 'codice_immobile' => "RBF-{$seq}", 'nome' => 'Interno 1', 'interno' => '1']);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $unita->id, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);

    $v = Anagrafica::forceCreate(['nome' => 'Venditore Ugo', 'email' => "rbf-v{$seq}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'RBFVENDITOR' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT)]);
    $a = Anagrafica::forceCreate(['nome' => 'Acquirente Elsa', 'email' => "rbf-a{$seq}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'RBFACQUIREN' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT)]);
    $v->condomini()->syncWithoutDetaching([$c->id]);
    $a->condomini()->syncWithoutDetaching([$c->id]);
    $rigaV = DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $v->id, 'immobile_id' => $unita->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    if ($saldoVenditore !== 0) {
        Saldo::create(['esercizio_id' => $e->id, 'condominio_id' => $c->id, 'anagrafica_id' => $v->id, 'immobile_id' => $unita->id, 'gestione_id' => $g->id, 'saldo_iniziale' => $saldoVenditore, 'origine' => 'manuale', 'is_applicato' => false]);
    }

    $piano = PianoRate::create([
        'gestione_id' => $g->id, 'condominio_id' => $c->id, 'esercizio_id' => $e->id, 'nome' => 'Spese da finanziare', 'stato' => 'approvato',
        'tipo' => 'straordinario', 'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05',
        'metodo_distribuzione' => $metodo, 'applica_saldi' => true, 'data_delibera_assemblea' => null,
    ]);
    $fornitoreId = DB::table('fornitori')->insertGetId([
        'ragione_sociale' => 'Manutenzioni Srl', 'soggetto_ritenuta' => false, 'ritenuta_decisa_il' => now(), 'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 4,
        'giorni_scadenza' => 30, 'modalita_pagamento_default' => 'bonifico', 'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ($fatture as $i => $f) {
        $pregressa = (bool) ($f['pregressa'] ?? false);
        $fattura = FatturaPassiva::create([
            'condominio_id' => $c->id, 'fornitore_id' => $fornitoreId, 'esercizio_id' => $e->id, 'tipo_documento' => 'fattura', 'numero_documento' => "FT-RBF{$seq}-{$i}",
            'data_documento' => $pregressa ? '2025-11-20' : '2026-01-02', 'data_scadenza' => '2026-02-01', 'is_pregresso' => $pregressa,
            'importo_imponibile' => $f['importo'], 'importo_iva' => 0, 'importo_ritenuta' => 0, 'totale_documento' => $f['importo'], 'netto_a_pagare' => $f['importo'],
            'stato_pagamento' => 'aperta', 'stato_approvazione' => 'approvata', 'modalita_pagamento' => 'bonifico',
            'competenza_dal' => $f['competenza'][0] ?? null, 'competenza_al' => $f['competenza'][1] ?? null,
        ]);
        if ($pregressa) {
            // La parte non coperta dai saldi iniziali: la copertura «sopravvenienza» sul conto, come la registra il servizio.
            DB::table('fattura_coperture')->insert([
                'fattura_passiva_id' => $fattura->id, 'tipo_copertura' => 'sopravvenienza', 'importo' => $f['importo'], 'stato' => 'pianificata',
                'conto_id' => $conto->id, 'nota_amministratore' => 'Eccedenza fattura pregressa non coperta dai saldi iniziali', 'created_at' => now(), 'updated_at' => now(),
            ]);
        } elseif (isset($f['ad_personam'])) {
            DB::table('righe_fattura')->insert([
                'fattura_passiva_id' => $fattura->id, 'conto_id' => null, 'immobile_id' => $unita->id, 'descrizione' => $f['ad_personam'], 'aliquota_iva' => 0,
                'importo_imponibile' => $f['importo'], 'importo_iva' => 0, 'is_sopravvenienza' => false, 'is_rateizzata' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
        } else {
            DB::table('righe_fattura')->insert([
                'fattura_passiva_id' => $fattura->id, 'conto_id' => $conto->id, 'immobile_id' => null, 'descrizione' => 'Riparazione', 'aliquota_iva' => 0,
                'importo_imponibile' => $f['importo'], 'importo_iva' => 0, 'is_sopravvenienza' => true, 'is_rateizzata' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $piano->fatture()->attach($fattura->id, ['importo_collegato' => $f['importo']]);
    }
    // A gennaio c'è solo il venditore: nessun cambio di titolare. La presa d'atto serve solo alle pregresse senza periodo.
    app(GeneratePianoRateAction::class)->execute($piano, forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Piano di prova da fatture', esercizio: $e);

    return compact('c', 'e', 'g', 'unita', 'v', 'a', 'rigaV', 'piano', 'conto');
}

it('decisione 26 [beta.35] — pregressa 2025 da € 1.200,00 in un piano da fatture su gestione ordinaria, quattro rate emesse, vendita il 1/5: la voce è tutta di chi vende, nessun conguaglio, le otto bozze restano sue — e motore e conguaglio danno lo stesso numero', function () {
    $s = rbScenarioDaFatture([['importo' => 120000, 'pregressa' => true, 'competenza' => ['2025-01-01', '2025-12-31']]]);
    // La riga congelata porta la competenza della fattura: senza, il conguaglio non ha niente da leggere.
    expect(DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->where('tipo', 'riparto')->pluck('gradino_competenza')->unique()->values()->all())->toBe(['dichiarata']);
    rbEmettiFinoAdAprile($s);

    $anteprima = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), rbVendita($s))->assertOk()->json();
    $cg = $anteprima['rate']['conguaglio'];
    // Prima della beta.35: 120000 × 245/365 = 80548 sull'intero piano, meno € 800,00 di bozze passate → coppia di € 5,48.
    expect((int) $cg['totale_entrante'])->toBe(0)
        ->and($cg['bozze_riassegnate'])->toBe([])
        ->and(collect($cg['quote_in_bozza'])->pluck('motivo')->unique()->values()->all())->toBe(['fattura_di_chi_esce'])
        ->and(collect($cg['quote_in_bozza'])->sum('n'))->toBe(8)
        // Una voce sola, dichiarata: la testa mostra il suo periodo (R5 della Fase 1-bis, il controesempio).
        ->and($cg['per_gestione'][0]['voce_per_voce'])->toBeFalse()
        // Decisione 28.8 c (beta.38): nessuna bozza cambia persona — la frase va fra le informazioni, non fra i motivi. Lo
        // stesso per le quattro emesse: il conguaglio dà zero a chi compra per costruzione, e la spunta non serve.
        ->and($anteprima['cancello']['informazioni'])->toContain('il piano «Spese da finanziare» ha 8 quote non ancora emesse intestate a Venditore Ugo: non si può più ricalcolare, restano sue: il conguaglio non le tocca')
        ->and($anteprima['cancello']['informazioni'])->toContain('4 quote di rate già emesse a Venditore Ugo su questa unità: restano sue, questo passaggio non le tocca')
        ->and($anteprima['cancello']['motivi'])->toBe([])
        ->and($anteprima['cancello']['richiesto'])->toBeFalse();

    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), rbVendita($s))->assertSessionHasNoErrors();
    $q = rbQuote($s);
    expect($q['v_preventivo'])->toBe(120000)->and($q['a_preventivo'])->toBe(0)->and(rbCoppia($s))->toBe([0, 0]);

    // Il motore sui titolari di oggi (venditore fino al 30/4, acquirente dal 1/5) dice lo stesso: il 2025 è del venditore.
    $motore = new \App\Services\CalcoloQuoteService();
    $totali = $motore->calcolaDaFattureStraordinarie($s['piano']->fresh(), app(\App\Services\Riparto\CompetenzaDelPiano::class)->perPiano($s['piano']->fresh(), $s['e']), soloLettura: true);
    expect((int) array_sum($totali[$s['v']->id] ?? []))->toBe(120000)->and($totali[$s['a']->id] ?? null)->toBeNull();
});

it('decisione 26 [beta.35] — caso misto sullo stesso conto: pregressa 2025 da € 600,00 e imprevisto 2026 da € 600,00 senza periodo; a chi entra va solo la parte dell\'imprevisto (60000 × 245/365 = 40274) e le bozze restano a chi vende perché la voce è divisa', function () {
    $s = rbScenarioDaFatture([
        ['importo' => 60000, 'pregressa' => true, 'competenza' => ['2025-01-01', '2025-12-31']],
        ['importo' => 60000],
    ]);
    rbEmettiFinoAdAprile($s);

    $anteprima = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), rbVendita($s))->assertOk()->json();
    $cg = $anteprima['rate']['conguaglio'];
    expect((int) $cg['totale_entrante'])->toBe(40274)
        ->and($cg['bozze_riassegnate'])->toBe([])
        ->and(collect($cg['quote_in_bozza'])->pluck('motivo')->unique()->values()->all())->toBe(['fattura_divisa'])
        // R5 della Fase 1-bis: una voce dichiarata e una no sulla stessa gestione — la testa non ha un periodo da mostrare.
        ->and($cg['per_gestione'][0]['voce_per_voce'])->toBeTrue();

    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), rbVendita($s))->assertSessionHasNoErrors();
    expect(rbCoppia($s))->toBe([40274, -40274])->and(rbQuote($s)['v_preventivo'])->toBe(120000);
});

it('decisione 26 [beta.35] — controllo: lo stesso piano da fatture su gestione ordinaria SENZA competenza dichiarata non cambia (bozze a chi entra, coppia € 5,48 come nella decisione 25)', function () {
    $s = rbScenarioDaFatture([['importo' => 120000]]);
    rbEmettiFinoAdAprile($s);
    $anteprima = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), rbVendita($s))->assertOk()->json();
    expect((int) $anteprima['rate']['conguaglio']['totale_entrante'])->toBe(548)
        ->and($anteprima['rate']['conguaglio']['bozze_riassegnate'])->toHaveCount(8);
});

it('R6 [beta.35] — nelle frasi voce per voce l\'addebito diretto all\'unità ha un nome, anche sull\'ordinaria: prima usciva «· : € 500,00»', function () {
    $s = rbScenarioDaFatture([
        ['importo' => 50000, 'ad_personam' => 'Riparazione citofono interno 1', 'competenza' => ['2026-03-01', '2026-08-31']],
        ['importo' => 40000],
    ]);
    rbEmettiFinoAdAprile($s);

    $anteprima = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), rbVendita($s))->assertOk()->json();
    $frasi = implode("\n", $anteprima['rate']['conguaglio']['frasi']);

    expect($frasi)->toContain('Riparazione citofono interno 1')->not->toContain('  · :');
});

it('verifica delle correzioni [beta.35] — R6: due addebiti diretti sulla stessa unità, con la stessa competenza, restano due voci con il loro nome e il loro importo', function () {
    $s = rbScenarioDaFatture([
        ['importo' => 50000, 'ad_personam' => 'Riparazione citofono interno 1', 'competenza' => ['2026-03-01', '2026-08-31']],
        ['importo' => 30000, 'ad_personam' => 'Sostituzione vetro interno 1', 'competenza' => ['2026-03-01', '2026-08-31']],
        ['importo' => 40000],
    ]);
    rbEmettiFinoAdAprile($s);

    $anteprima = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), rbVendita($s))->assertOk()->json();
    $frasi = implode("\n", $anteprima['rate']['conguaglio']['frasi']);

    // Prima: «· Riparazione citofono interno 1: € 800,00 …», il nome della prima e la somma di tutte e due.
    expect($frasi)->toContain('Riparazione citofono interno 1: € 500,00')->toContain('Sostituzione vetro interno 1: € 300,00');
});

it('verifica delle correzioni [beta.35] — R5: due piani da fatture sulla stessa gestione ordinaria, uno dichiarato e uno sulla base — la colonna dice «voce per voce»', function () {
    // Piano 0: la pregressa 2025 dichiarata.
    $s = rbScenarioDaFatture([['importo' => 60000, 'pregressa' => true, 'competenza' => ['2025-01-01', '2025-12-31']]]);
    // Piano 1, sulla stessa gestione: una fattura corrente senza periodo, che va sui giorni della base.
    $fornitoreId = DB::table('fornitori')->insertGetId(['ragione_sociale' => 'Secondo fornitore Srl', 'soggetto_ritenuta' => false, 'ritenuta_decisa_il' => now(), 'giorni_scadenza' => 30, 'modalita_pagamento_default' => 'bonifico', 'created_at' => now(), 'updated_at' => now()]);
    $fattura = FatturaPassiva::create([
        'condominio_id' => $s['c']->id, 'fornitore_id' => $fornitoreId, 'esercizio_id' => $s['e']->id, 'tipo_documento' => 'fattura', 'numero_documento' => 'FT-SECONDO',
        'data_documento' => '2026-01-02', 'data_scadenza' => '2026-02-01', 'is_pregresso' => false, 'importo_imponibile' => 60000, 'importo_iva' => 0, 'importo_ritenuta' => 0,
        'totale_documento' => 60000, 'netto_a_pagare' => 60000, 'stato_pagamento' => 'aperta', 'stato_approvazione' => 'approvata', 'modalita_pagamento' => 'bonifico',
    ]);
    DB::table('righe_fattura')->insert(['fattura_passiva_id' => $fattura->id, 'conto_id' => $s['conto']->id, 'immobile_id' => null, 'descrizione' => 'Riparazione', 'aliquota_iva' => 0, 'importo_imponibile' => 60000, 'importo_iva' => 0, 'is_sopravvenienza' => true, 'is_rateizzata' => false, 'created_at' => now(), 'updated_at' => now()]);
    $secondo = PianoRate::create([
        'gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Secondo piano', 'stato' => 'approvato',
        'tipo' => 'straordinario', 'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => false,
    ]);
    $secondo->fatture()->attach($fattura->id, ['importo_collegato' => 60000]);
    app(GeneratePianoRateAction::class)->execute($secondo, accettaDestinatari: true, notaDestinatari: 'Secondo piano di prova', esercizio: $s['e']);
    // Tutti e due emessi fino ad aprile: un piano che si può ancora ricalcolare non entra nel conguaglio.
    rbEmettiFinoAdAprile($s);
    rbEmettiFinoAdAprile(array_merge($s, ['piano' => $secondo]));

    $anteprima = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), rbVendita($s))->assertOk()->json();
    $gestione = collect($anteprima['rate']['conguaglio']['per_gestione'])->firstWhere('gestione_id', $s['g']->id);

    expect($gestione['gradino'])->toContain('dichiarata')
        ->and($gestione['voce_per_voce'])->toBeTrue();
});
