<?php

/**
 * Cosa succede quando la chiave di idempotenza di un pagamento è già in uso.
 *
 * La protezione contro il doppio invio funziona: `registraPagamento()` cerca la chiave e,
 * se la trova, restituisce il pagamento già registrato invece di crearne un secondo. È la
 * risposta ai casi che nessuno prevede a essere rotta.
 *
 * `idempotency_key` è **unica su tutte le `scritture_contabili`**, non solo su quelle dei
 * pagamenti — la colonna è dichiarata `->unique()` in
 * `2026_05_22_...upgrade_scritture_contabili` — e ci scrivono anche i giroconti
 * (`RegistraGirocontoAction:203`). Quindi la ricerca può benissimo trovare una scrittura
 * che **non è un pagamento**: lì `$esistente->pagamentoFornitore` vale `null`, e il metodo
 * dichiara `: PagamentoFornitore`. Risultato: un `TypeError` di PHP al posto di un errore
 * di dominio che il controller sa raccontare.
 *
 * Stessa forma il secondo caso: la ricerca non filtra per condominio, quindi la chiave di
 * un pagamento del condominio A restituisce **quel** pagamento anche a chi sta registrando
 * nel condominio B. Non è un replay: è una collisione, e restituire l'oggetto di un altro
 * condominio fa credere al chiamante che il salvataggio sia andato a buon fine mentre nel
 * condominio B non è stato registrato niente.
 *
 * Trovato il 02/08/2026 costruendo i dati della guida del sito, riusando una chiave fissa.
 */

use App\Enums\TipoMovimentoContabile;
use App\Exceptions\Pagamenti\IdempotencyKeyConflittoException;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\PagamentoFornitoreService;
use Illuminate\Support\Str;

require_once __DIR__.'/GestionaleTestHelpers.php';

// ════════════════════════════════════════════════════════════════════════════
// Il caso che deve continuare a funzionare: il replay vero
// ════════════════════════════════════════════════════════════════════════════

test('rimandare due volte lo stesso pagamento con la stessa chiave non ne crea due', function () {
    $ctx = setupPagamentiService();
    $fattura = registraFatturaServiceTest($ctx);
    $service = new PagamentoFornitoreService;

    $chiave = (string) Str::uuid();
    $dati = datiPagamento($ctx, $fattura, ['idempotency_key' => $chiave]);

    $primo = $service->registraPagamento($dati);
    $secondo = $service->registraPagamento($dati);

    expect($secondo->id)->toEqual($primo->id);
    expect(\App\Models\Gestionale\PagamentoFornitore::count())->toEqual(1);
});

// ════════════════════════════════════════════════════════════════════════════
// La chiave appartiene a una scrittura che non è un pagamento
// ════════════════════════════════════════════════════════════════════════════

test('una chiave già usata da un giroconto non fa esplodere il servizio', function () {
    $ctx = setupPagamentiService();
    [$condominio, $esercizio, $gestione] = $ctx;
    $fattura = registraFatturaServiceTest($ctx);

    $chiave = (string) Str::uuid();

    // Ciò che un giroconto lascia dietro di sé: una scrittura con la chiave e nessun
    // pagamento collegato.
    ScritturaContabile::create([
        'condominio_id' => $condominio->id,
        'gestione_id' => $gestione->id,
        'esercizio_id' => $esercizio->id,
        'data_registrazione' => now()->format('Y-m-d'),
        'data_competenza' => now()->format('Y-m-d'),
        'causale' => 'Giroconto fondo → banca',
        'tipo_movimento' => TipoMovimentoContabile::GIROCONTO,
        'stato' => 'registrata',
        'idempotency_key' => $chiave,
    ]);

    $service = new PagamentoFornitoreService;

    // Deve essere un errore di dominio, catturabile e raccontabile: non un TypeError.
    expect(fn () => $service->registraPagamento(
        datiPagamento($ctx, $fattura, ['idempotency_key' => $chiave])
    ))->toThrow(IdempotencyKeyConflittoException::class);
});

test('il messaggio del conflitto dice che la chiave è di un altro movimento', function () {
    $ctx = setupPagamentiService();
    [$condominio, $esercizio, $gestione] = $ctx;
    $fattura = registraFatturaServiceTest($ctx);

    $chiave = (string) Str::uuid();

    ScritturaContabile::create([
        'condominio_id' => $condominio->id,
        'gestione_id' => $gestione->id,
        'esercizio_id' => $esercizio->id,
        'data_registrazione' => now()->format('Y-m-d'),
        'data_competenza' => now()->format('Y-m-d'),
        'causale' => 'Giroconto fondo → banca',
        'tipo_movimento' => TipoMovimentoContabile::GIROCONTO,
        'stato' => 'registrata',
        'idempotency_key' => $chiave,
    ]);

    try {
        (new PagamentoFornitoreService)->registraPagamento(
            datiPagamento($ctx, $fattura, ['idempotency_key' => $chiave])
        );
        $this->fail('Doveva sollevare IdempotencyKeyConflittoException.');
    } catch (IdempotencyKeyConflittoException $e) {
        expect($e->getMessage())->toContain('già utilizzata');
    }
});

// ════════════════════════════════════════════════════════════════════════════
// La chiave appartiene al pagamento di un ALTRO condominio
// ════════════════════════════════════════════════════════════════════════════

test('la chiave di un pagamento di un altro condominio non viene scambiata per un replay', function () {
    // Primo condominio: un pagamento vero con la sua chiave.
    $ctxA = setupPagamentiService();
    $fatturaA = registraFatturaServiceTest($ctxA);
    $service = new PagamentoFornitoreService;

    $chiave = (string) Str::uuid();
    $pagamentoA = $service->registraPagamento(datiPagamento($ctxA, $fatturaA, ['idempotency_key' => $chiave]));

    // Secondo condominio, stessa chiave: non è lo stesso pagamento rimandato indietro,
    // è una collisione. Restituire il pagamento di A farebbe credere a B di aver
    // registrato qualcosa che nel suo condominio non esiste.
    $ctxB = setupPagamentiService();
    $fatturaB = registraFatturaServiceTest($ctxB);

    expect(fn () => $service->registraPagamento(
        datiPagamento($ctxB, $fatturaB, ['idempotency_key' => $chiave])
    ))->toThrow(IdempotencyKeyConflittoException::class);

    // E soprattutto: nel condominio B non deve essere comparso nulla.
    expect(
        \App\Models\Gestionale\PagamentoFornitore::where('condominio_id', $ctxB[0]->id)->count()
    )->toEqual(0);

    expect($pagamentoA->fresh())->not->toBeNull();
});
