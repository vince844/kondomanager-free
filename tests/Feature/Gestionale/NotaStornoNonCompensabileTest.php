<?php

/**
 * La nota di credito generata da uno storno non è un documento vero (Coda 124).
 *
 * ## Il difetto, misurato con una sonda end-to-end il 04/09/2026
 *
 * `StornoFatturaController` marca la nota che genera con `dati_extra['nota_storno']`, perché
 * `PagamentoFornitoreService::trovaNoteCreditoCompensabili()` la esclude filtrando su quella
 * chiave. Ma `FatturaPassivaService::registraFattura()` **ricostruisce `dati_extra` da zero**
 * tenendo solo tre chiavi — `fiscal`, `competenza`, `override_budget` — e `nota_storno` andava
 * perso. Il filtro `whereNull('dati_extra->nota_storno')` era quindi **sempre vero**: la nota
 * da storno rientrava fra quelle offerte in compensazione.
 *
 * Eseguito lo scenario per intero: fattura A stornata, fattura B vera registrata, B compensata
 * con la nota nata dallo storno di A. Risultato **misurato**, non dedotto: B risultava «pagata»,
 * residuo zero, **zero euro usciti dalla cassa**. La partita doppia non se ne accorgeva — la
 * scrittura era DARE 122.000 / AVERE 122.000 sullo **stesso mastro** (debiti_fornitori), un giro
 * a vuoto che il validatore lascia passare — ma il mastro restava a debito mentre l'elenco delle
 * fatture aperte diventava vuoto: giornale e partitario **divergevano**.
 *
 * ## Perché la correzione è in due punti, non uno
 *
 * ⚠️ **Sistemare solo il filtro di `trovaNoteCreditoCompensabili()` non basta.**
 * `PagamentoFornitoreController::pendenze()` costruisce l'elenco «tutti i documenti aperti del
 * fornitore» con una query separata che non guardava affatto `nota_storno`, e il merge finale
 * scarta dall'elenco generale solo le note che sono **anche** in quello compensabile: chiudere
 * la prima porta senza chiudere la seconda avrebbe lasciato la nota comunque selezionabile,
 * solo passando per l'altra lista.
 *
 * E una terza linea, indipendente dalla schermata: `PagamentoFornitoreService::validaInput()`
 * rifiuta esplicitamente qualunque allocazione che punti a una nota da storno, per una richiesta
 * costruita a mano o per un elenco che in futuro dimenticasse il filtro.
 */

use App\Enums\TipoAllocazioneFattura;
use App\Exceptions\Pagamenti\NotaStornoNonCompensabileException;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $permessoAdmin = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruoloAdmin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruoloAdmin->givePermissionTo($permessoAdmin);

    $this->user = User::factory()->create();
    $this->user->assignRole($ruoloAdmin);
});

/** Storna la fattura via HTTP, come fa l'amministratore, e restituisce la NC generata. */
function stornaEOttieniNota($condominio, FatturaPassiva $fattura, $user): FatturaPassiva
{
    test()->actingAs($user)
        ->post(route('admin.gestionale.fatture.storno', [$condominio, $fattura]))
        ->assertSessionHasNoErrors();

    return FatturaPassiva::where('numero_documento', 'STORNO-'.$fattura->numero_documento)->firstOrFail();
}

test('la nota generata da uno storno porta davvero la marcatura', function () {
    // ⚠️ Prima della correzione questo test falliva: dati_extra->nota_storno era null.
    $ctx = setupPagamentiService();
    [$condominio] = $ctx;

    $fattura = registraFatturaServiceTest($ctx, ['numero_documento' => 'FT-124-A']);
    $nota = stornaEOttieniNota($condominio, $fattura, $this->user);

    expect($nota->dati_extra['nota_storno'] ?? null)->not->toBeNull()
        ->and($nota->dati_extra['nota_storno'])->toContain((string) $fattura->id);
});

test('trovaNoteCreditoCompensabili non la restituisce più', function () {
    $ctx = setupPagamentiService();
    [$condominio, , , $fornitore] = $ctx;

    $fattura = registraFatturaServiceTest($ctx, ['numero_documento' => 'FT-124-B']);
    stornaEOttieniNota($condominio, $fattura, $this->user);

    $compensabili = app(App\Services\Gestionale\PagamentoFornitoreService::class)
        ->trovaNoteCreditoCompensabili($fornitore->id, $condominio->id);

    expect($compensabili)->toBeEmpty();
});

test('l\'endpoint delle pendenze non la elenca affatto, né come nota né come documento aperto', function () {
    // ⚠️ È il rilievo che «solo il filtro sul compensabile non basta»: qui si controlla
    // l'elenco che l'amministratore vede davvero, non il metodo di supporto.
    $ctx = setupPagamentiService();
    [$condominio, , , $fornitore] = $ctx;

    $fattura = registraFatturaServiceTest($ctx, ['numero_documento' => 'FT-124-C']);
    $nota = stornaEOttieniNota($condominio, $fattura, $this->user);

    $risposta = $this->actingAs($this->user)
        ->getJson(route('admin.gestionale.pagamenti-fornitori.pendenze', $condominio).'?fornitore_id='.$fornitore->id)
        ->assertOk()
        ->json();

    $ids = collect($risposta['pendenze'])->pluck('id');

    expect($ids)->not->toContain($nota->id)
        ->and($risposta['totale_nc'])->toBe(0)
        ->and($risposta['has_netting'])->toBeFalse();
});

test('provando a compensarla direttamente il pagamento viene rifiutato, non silenziosamente accettato', function () {
    // ⚠️ **Il caso pericoloso**: nessuna schermata la offre più, ma se una richiesta la
    // referenzia comunque — costruita a mano, o da un elenco futuro con lo stesso difetto —
    // il denaro si ferma qui, prima di scrivere qualunque cosa a database.
    $ctx = setupPagamentiService();
    [$condominio, $esercizio, , $fornitore] = $ctx;

    $fatturaStornata = registraFatturaServiceTest($ctx, ['numero_documento' => 'FT-124-D']);
    $notaStorno = stornaEOttieniNota($condominio, $fatturaStornata, $this->user);

    $fatturaVera = registraFatturaServiceTest($ctx, ['numero_documento' => 'FT-124-E']);

    $service = app(App\Services\Gestionale\PagamentoFornitoreService::class);

    $dati = datiPagamento($ctx, $fatturaVera, [
        'allocazioni' => [[
            'fattura_id'             => $fatturaVera->id,
            'tipo'                   => TipoAllocazioneFattura::COMPENSAZIONE->value,
            'importo_allocato_cents' => $fatturaVera->netto_a_pagare,
        ], [
            'fattura_id'             => $notaStorno->id,
            'tipo'                   => TipoAllocazioneFattura::COMPENSAZIONE->value,
            'importo_allocato_cents' => $fatturaVera->netto_a_pagare,
        ]],
    ]);

    expect(fn () => $service->registraPagamento($dati))
        ->toThrow(NotaStornoNonCompensabileException::class);

    // ⚠️ Il punto che la sonda del 04/09/2026 aveva misurato come rotto: dopo il rifiuto
    // niente deve essere cambiato. La transazione del service deve aver fatto rollback.
    expect($fatturaVera->fresh()->stato_pagamento->value)->toBe('aperta')
        ->and($fatturaVera->fresh()->residuo)->toBe($fatturaVera->netto_a_pagare)
        ->and($notaStorno->fresh()->residuo)->toBe(abs($notaStorno->netto_a_pagare));
});

test('lo stesso rifiuto, passando dall\'HTTP: un errore di dominio, non un guasto tecnico nel log', function () {
    // ⚠️ **Trovato dalla revisione avversariale del 05/09/2026.** `NotaStornoNonCompensabileException`
    // non aveva un catch suo in `PagamentoFornitoreController::store()`, quindi cadeva nel
    // catch generico — che scrive `Log::error()` con lo stack trace, come rumore in mezzo ai
    // guasti veri. Una regola di dominio rispettata (l'unico modo in cui questa eccezione può
    // arrivare qui, dato che nessuna schermata offre più la nota) non è un errore tecnico.
    $ctx = setupPagamentiService();
    [$condominio, $esercizio, , $fornitore] = $ctx;

    $fatturaStornata = registraFatturaServiceTest($ctx, ['numero_documento' => 'FT-124-G']);
    $notaStorno = stornaEOttieniNota($condominio, $fatturaStornata, $this->user);
    $fatturaVera = registraFatturaServiceTest($ctx, ['numero_documento' => 'FT-124-H']);

    $dati = datiPagamento($ctx, $fatturaVera, [
        'allocazioni' => [[
            'fattura_id'             => $fatturaVera->id,
            'tipo'                   => TipoAllocazioneFattura::COMPENSAZIONE->value,
            'importo_allocato_cents' => $fatturaVera->netto_a_pagare,
        ], [
            'fattura_id'             => $notaStorno->id,
            'tipo'                   => TipoAllocazioneFattura::COMPENSAZIONE->value,
            'importo_allocato_cents' => $fatturaVera->netto_a_pagare,
        ]],
    ]);

    $risposta = $this->actingAs($this->user)
        ->post(route('admin.gestionale.pagamenti-fornitori.store', $condominio), $dati);

    $risposta->assertSessionHasErrors('nota_storno_non_compensabile');
    // La chiave dedicata prova che è passato dal catch di dominio: il catch generico
    // scrive sempre in 'error', mai in questa chiave.
    expect(session('errors')->has('error'))->toBeFalse();
});

test('una nota di credito VERA, non nata da storno, resta compensabile come sempre', function () {
    // ⚠️ Il controesempio che tiene stretta la correzione: se il filtro escludesse troppo,
    // il netting vero — quello che il fornitore emette davvero — smetterebbe di funzionare.
    $ctx = setupPagamentiService();
    [$condominio, $esercizio, , $fornitore, $contoCorrenteId, $capitolo] = $ctx;

    $fattura = registraFatturaServiceTest($ctx, [
        'numero_documento' => 'FT-124-F',
        'righe' => [[
            'descrizione' => 'Servizio Test', 'importo_imponibile' => 1000, 'aliquota_iva' => 22,
            'conto_id' => $capitolo->id, 'is_sopravvenienza' => false,
        ]],
    ]);

    $notaVera = registraFatturaServiceTest($ctx, [
        'tipo_documento'   => 'nota_credito',
        'numero_documento' => 'NC-124-VERA',
        'righe' => [[
            'descrizione' => 'Sconto Test', 'importo_imponibile' => 1000, 'aliquota_iva' => 22,
            'conto_id' => $capitolo->id, 'is_sopravvenienza' => false,
        ]],
    ]);

    $compensabili = app(App\Services\Gestionale\PagamentoFornitoreService::class)
        ->trovaNoteCreditoCompensabili($fornitore->id, $condominio->id);

    expect($compensabili->pluck('id'))->toContain($notaVera->id);

    $risposta = $this->actingAs($this->user)
        ->getJson(route('admin.gestionale.pagamenti-fornitori.pendenze', $condominio).'?fornitore_id='.$fornitore->id)
        ->assertOk()
        ->json();

    expect(collect($risposta['pendenze'])->pluck('id'))->toContain($notaVera->id)
        ->and($risposta['has_netting'])->toBeTrue();

    $service = app(App\Services\Gestionale\PagamentoFornitoreService::class);
    $dati = datiPagamento($ctx, $fattura, [
        'allocazioni' => [[
            'fattura_id'             => $fattura->id,
            'tipo'                   => TipoAllocazioneFattura::COMPENSAZIONE->value,
            'importo_allocato_cents' => $fattura->netto_a_pagare,
        ], [
            'fattura_id'             => $notaVera->id,
            'tipo'                   => TipoAllocazioneFattura::COMPENSAZIONE->value,
            'importo_allocato_cents' => $fattura->netto_a_pagare,
        ]],
    ]);

    $service->registraPagamento($dati);

    expect($fattura->fresh()->stato_pagamento->value)->toBe('pagata')
        ->and($notaVera->fresh()->stato_pagamento->value)->toBe('pagata');
});
