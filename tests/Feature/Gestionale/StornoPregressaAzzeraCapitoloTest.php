<?php

use App\Models\Gestionale\FatturaPassiva;
use App\Services\Gestionale\FatturaPassivaService;
use App\Services\Gestionale\SpesaPerVoceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/FatturaLifecycleTest.php';

/**
 * **Coda 145 — lo storno di una pregressa deve azzerare il capitolo che la pregressa ha inventato.**
 *
 * ⚠️ **Prima di questi test nessuna prova, in tutta la suite, esercitava lo storno di una fattura
 * pregressa con copertura `sopravvenienza`.** Il difetto è stato trovato dalla Fase 1-bis della
 * beta.21 e riprodotto due volte con sonde indipendenti; questo file è la rete che mancava.
 *
 * ## Cosa succede, e perché nessuno se ne accorgeva
 *
 * Una fattura pregressa **scoperta** — l'importo supera il debito storico dichiarato — fa creare un
 * **capitolo di spesa dinamico** e scrive DARE sul suo mastro **con `voce_spesa_id` valorizzato**:
 * è quell'etichetta che porta il costo dentro `SpesaPerVoceService`, e da lì al piano dei conti, al
 * dettaglio voce, al drill-down, al PDF della distinta e al cruscotto — cinque posti, mappati in
 * `docs/catene_fra_moduli.md` riga 181.
 *
 * Stornandola, `StornoFatturaController` copia **tutte** le coperture, sopravvenienza compresa, e il
 * ramo pregresso le somma in `$totaleRata0`: la contropartita viene scritta, ma **su
 * `passate_gestioni` e senza `voce_spesa_id`**. Risultato: il costo sul capitolo non si azzera mai,
 * e `passate_gestioni` riceve un movimento che nessuno ha mai messo dall'altra parte.
 *
 * ⚠️ **`DoubleEntryValidator` non lo vede**: le due scritture quadrano ciascuna per sé e lo
 * sbilancio sta **fra** i due documenti. È la firma «quadra lo stesso» che la beta.20 ha imparato a
 * riconoscere — e infatti il quinto controllo del deep scan è lo strumento che la vede.
 *
 * 📌 Il docblock di `SpesaPerVoceService` **afferma già** che «uno storno azzera». Questi test non
 * introducono una regola: pretendono che il codice mantenga quella che aveva scritto.
 */
beforeEach(function () {
    app()[Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    $permesso = Spatie\Permission\Models\Permission::firstOrCreate(
        ['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']
    );
    $ruolo = Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);

    $this->user = App\Models\User::factory()->create();
    $this->user->assignRole($ruolo);
});

function ecosistemaPregresso(): array
{
    $ctx = setupEcosistemaLifecycle();

    DB::table('conti_contabili')->insert([
        'condominio_id' => $ctx[0]->id, 'ruolo' => 'passate_gestioni', 'codice' => 'PASS-GEST',
        'nome' => 'Passate gestioni', 'tipo' => 'passivo', 'categoria' => 'debiti',
        'attivo' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $ctx;
}

/** Registra una fattura pregressa SCOPERTA, cioè quella che fa nascere il capitolo dinamico. */
function pregressaScoperta(array $ctx, string $numero = 'FT-PREG-145'): FatturaPassiva
{
    [$condominio, $esercizio, $gestione, $fornitore] = $ctx;

    return app(FatturaPassivaService::class)->registraFattura([
        'fornitore_id' => $fornitore->id,
        'esercizio_id' => $esercizio->id,
        'gestione_id' => $gestione->id,
        'tipo_documento' => 'fattura',
        'numero_documento' => $numero,
        'data_documento' => '2024-11-30',
        'data_scadenza' => '2024-12-31',
        'modalita_pagamento' => 'bonifico',
        'applica_ritenuta' => false,
        'is_pregresso' => true,
        'imponibile_pregresso' => 500.00,
        'aliquota_iva_pregressa' => 22,
        'dati_extra' => [
            'fiscal' => [], 'competenza' => null, 'override_budget' => null,
            'log_legale_sopravvenienza' => ['motivazione' => 'Fattura emersa dopo la chiusura'],
        ],
        'righe' => [],
    ], $condominio->id);
}

/** Il costo che il capitolo porta, letto dove lo legge il piano dei conti. */
function costoDelCapitolo(array $ctx, int $capitoloId): int
{
    $per = app(SpesaPerVoceService::class)->perEsercizio($ctx[1]);

    return (int) ($per[$capitoloId] ?? 0);
}

it('la pregressa scoperta crea il capitolo e ci mette sopra il costo — la premessa', function () {
    // ⚠️ Questo test non prova la correzione: prova che lo SCENARIO esiste. Senza, i due test
    // sotto potrebbero passare perché non succede niente, invece che perché succede la cosa giusta.
    $ctx = ecosistemaPregresso();
    $contiPrima = DB::table('conti')->count();

    $fattura = pregressaScoperta($ctx);

    expect(DB::table('conti')->count())->toBeGreaterThan($contiPrima, 'nessun capitolo dinamico creato');

    $copertura = $fattura->coperture()->where('tipo_copertura', 'sopravvenienza')->first();
    expect($copertura)->not->toBeNull('nessuna copertura sopravvenienza');

    $capitolo = (int) ($copertura->conto_id ?? $copertura->fonte_id);
    expect(costoDelCapitolo($ctx, $capitolo))->toBe(61_000, 'il costo non è sul capitolo inventato');
});

it('stornandola, il costo sul capitolo torna a zero', function () {
    // ⚠️ **È il difetto della Coda 145.** Prima della correzione questo test è ROSSO: il costo
    // resta 61.000 e continua a entrare nel rendiconto e nel riparto per un documento annullato.
    $ctx = ecosistemaPregresso();
    $fattura = pregressaScoperta($ctx, 'FT-PREG-STORNO');
    $copertura = $fattura->coperture()->where('tipo_copertura', 'sopravvenienza')->firstOrFail();
    $capitolo = (int) ($copertura->conto_id ?? $copertura->fonte_id);

    expect(costoDelCapitolo($ctx, $capitolo))->toBe(61_000);

    $this->actingAs($this->user)
        ->post(route('admin.gestionale.fatture.storno', [$ctx[0], $fattura]))
        ->assertSessionHasNoErrors();

    expect(costoDelCapitolo($ctx, $capitolo))
        ->toBe(0, 'il costo del capitolo inventato sopravvive allo storno: entra nel rendiconto e si ripartisce ai condòmini per un documento che non esiste più');
});

it('e passate_gestioni non riceve un movimento senza contropartita', function () {
    // La seconda metà del difetto, nel verso corretto: `passate_gestioni` è un mastro PASSIVO
    // (saldo = avere − dare), quindi un AVERE senza contropartita ne SOVRASTIMA il debito.
    $ctx = ecosistemaPregresso();
    $fattura = pregressaScoperta($ctx, 'FT-PREG-SALDO');

    $this->actingAs($this->user)
        ->post(route('admin.gestionale.fatture.storno', [$ctx[0], $fattura]))
        ->assertSessionHasNoErrors();

    // ⚠️ **Senza queste due righe il test era verde anche a storno mai eseguito.** Su una
    // pregressa scoperta `passate_gestioni` non si muove nemmeno in registrazione: lo zero
    // qui sotto è vero già prima del POST. Il difetto che il test nomina — un AVERE senza
    // contropartita — lo vedrebbe eccome (il saldo diventerebbe +20.000); ciò che non
    // vedeva era la **premessa**, cioè che lo storno fosse davvero avvenuto. Misurato
    // mutando il controller a `return back()` immediato: quattro test su cinque rossi,
    // questo verde. Il rilievo veniva dalla Fase 1-bis della beta.22, dove il panel di
    // refutazione lo aveva scartato: aveva torto il panel.
    $flash = session('message');
    expect($flash['type'] ?? 'success')->toBe('success', 'lo storno è stato respinto: '.($flash['message'] ?? ''));
    expect($fattura->fresh()->dati_extra['is_stornata'] ?? false)->toBeTrue('lo storno non è avvenuto');

    $saldo = (int) DB::table('righe_scritture')
        ->join('scritture_contabili', 'righe_scritture.scrittura_id', '=', 'scritture_contabili.id')
        ->join('conti_contabili', 'righe_scritture.conto_contabile_id', '=', 'conti_contabili.id')
        ->where('scritture_contabili.condominio_id', $ctx[0]->id)
        ->where('conti_contabili.ruolo', 'passate_gestioni')
        ->selectRaw("COALESCE(SUM(CASE WHEN righe_scritture.tipo_riga = 'avere' THEN righe_scritture.importo ELSE -righe_scritture.importo END), 0) as saldo")
        ->value('saldo');

    expect($saldo)->toBe(0, 'passate_gestioni ha un movimento che nessuno ha messo dall’altra parte');
});

/**
 * ⚠️ **Lo scenario di punta della Coda 145 ha DUE coperture, e i tre test sopra ne hanno una sola.**
 *
 * «L'importo supera il debito storico dichiarato» significa che un debito storico c'è: il modulo
 * inietta una copertura `rata_0` fino a capienza, e il servizio aggiunge la `sopravvenienza`
 * dell'eccedenza. Due coperture, con la sopravvenienza sempre ultima.
 *
 * ⚠️ **E lì mordeva un difetto preesistente di PHP, non del dominio.** Il primo ciclo delle
 * coperture itera **per riferimento** e non scioglieva il legame: il ciclo successivo, per valore,
 * riscriveva l'ultimo elemento a ogni giro, quindi la sopravvenienza veniva letta come duplicato
 * della `rata_0`. Il ramo che azzera il capitolo non veniva **mai raggiunto**, e lo storno moriva
 * con «Sbilancio rilevato tra DARE (€ 610,00) e AVERE (€ 400,00)» — cioè 2 × 200.
 *
 * Trovato dalla Fase 1-bis della beta.22 da **quattro lenti indipendenti**, ognuna con la propria
 * sonda. I tre test sopra non potevano vederlo: registrano la pregressa senza `coperture`.
 */
it('una pregressa PARZIALMENTE coperta si storna, e il capitolo torna a zero', function () {
    $ctx = ecosistemaPregresso();
    [$condominio, $esercizio, $gestione, $fornitore] = $ctx;

    $fattura = app(FatturaPassivaService::class)->registraFattura([
        'fornitore_id' => $fornitore->id,
        'esercizio_id' => $esercizio->id,
        'gestione_id' => $gestione->id,
        'tipo_documento' => 'fattura',
        'numero_documento' => 'FT-PREG-DUE-COPERTURE',
        'data_documento' => '2024-11-30',
        'data_scadenza' => '2024-12-31',
        'modalita_pagamento' => 'bonifico',
        'applica_ritenuta' => false,
        'is_pregresso' => true,
        'imponibile_pregresso' => 500.00,
        'aliquota_iva_pregressa' => 22,
        // ⚠️ La copertura che il modulo inietta quando esiste un debito storico: è LEI a creare
        // il secondo elemento, e quindi a far mordere il riferimento penzolante.
        'coperture' => [
            ['tipo_copertura' => 'rata_0', 'importo' => 200.00, 'fonte_id' => null],
        ],
        'dati_extra' => [
            'fiscal' => [], 'competenza' => null, 'override_budget' => null,
            'log_legale_sopravvenienza' => ['motivazione' => 'Fattura emersa dopo la chiusura'],
        ],
        'righe' => [],
    ], $condominio->id);

    $copertura = $fattura->coperture()->where('tipo_copertura', 'sopravvenienza')->firstOrFail();
    $capitolo = (int) $copertura->conto_id;

    // Premessa: il capitolo porta l'eccedenza, cioè 610 − 200 = 410.
    expect(costoDelCapitolo($ctx, $capitolo))->toBe(41_000);

    // ⚠️ **Prima della correzione lo storno veniva RESPINTO**, non solo lasciava il costo.
    // Ma NON su `withErrors`: lo sbilancio è un'eccezione, e il controller la riporta con
    // `flashError`, cioè in sessione sotto `message`. `assertSessionHasNoErrors()` passava
    // anche col difetto in piedi — misurato mutando il servizio. È il flash che va guardato,
    // altrimenti il test dichiara una difesa che non esercita.
    $this->actingAs($this->user)
        ->post(route('admin.gestionale.fatture.storno', [$condominio, $fattura]))
        ->assertSessionHasNoErrors();

    $flash = session('message');
    expect($flash['type'] ?? 'success')->toBe('success', 'lo storno è stato respinto: '.($flash['message'] ?? ''));
    expect($fattura->fresh()->dati_extra['is_stornata'] ?? false)->toBeTrue('lo storno non è avvenuto');
    expect(costoDelCapitolo($ctx, $capitolo))->toBe(0, 'il costo sopravvive allo storno');
});

/**
 * ⚠️ **La Coda 145 nominava DUE misure, e il giornale ne chiude una sola.**
 *
 * `SpesaPerVoceService` legge il costo dal giornale, e la correzione principale lo azzera. Ma
 * `conti.importo` — il **fabbisogno** del capitolo, scritto da `creaContoDinamicoSopravvenienza()`
 * alla registrazione — restava al suo valore: a video il consuntivo della voce tornava a «—» e il
 * totale calava di € 610,00, mentre l'intestazione del piano dei conti continuava a dire
 * «Sopravvenienze: € 610,00». Trovato **nella verifica a video della beta.22**, non dai test.
 */
it('e il capitolo inventato non chiede più il suo fabbisogno', function () {
    $ctx = ecosistemaPregresso();
    $fattura = pregressaScoperta($ctx, 'FT-PREG-FABBISOGNO');

    $copertura = $fattura->coperture()->where('tipo_copertura', 'sopravvenienza')->firstOrFail();
    $capitolo = (int) $copertura->conto_id;

    // Premessa: alla registrazione il capitolo nasce col fabbisogno della fattura.
    expect((int) \App\Models\Gestionale\Conto::findOrFail($capitolo)->importo)->toBe(61_000);

    $this->actingAs($this->user)
        ->post(route('admin.gestionale.fatture.storno', [$ctx[0], $fattura]))
        ->assertSessionHasNoErrors();

    $flash = session('message');
    expect($flash['type'] ?? 'success')->toBe('success', 'lo storno è stato respinto: '.($flash['message'] ?? ''));

    expect((int) \App\Models\Gestionale\Conto::findOrFail($capitolo)->importo)
        ->toBe(0, 'il capitolo inventato chiede ancora il suo fabbisogno per un documento annullato');
});
