<?php

use App\Enums\MetodoPagamento;
use App\Enums\StatoPagamentoFattura;
use App\Enums\StatoPagamentoFornitore;
use App\Enums\TipoAllocazioneFattura;
use App\Enums\TipoDetrazione;
use App\Events\Gestionale\PagamentoRegistrato;
use App\Listeners\Gestionale\SyncF24WithPagamento;
use App\Models\CategoriaEvento;
use App\Models\Evento;
use App\Models\Gestionale\PagamentoFornitore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

test('registrazione pagamento happy path: scrittura quadra, stato evolve, cassa valorizzata', function () {
    [$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo] = setupPagamentiHttp();
    $fattura = registraFatturaServiceTest([$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo]);

    expect($fattura->stato_pagamento)->toBe(StatoPagamentoFattura::APERTA);

    $response = $this->actingAs($this->user)
        ->post(route('admin.gestionale.pagamenti-fornitori.store', [$condominio]), [
            'fornitore_id' => $fornitore->id,
            'esercizio_id' => $esercizio->id,
            'conto_corrente_id' => $contoCorrenteId,
            'data_pagamento' => now()->toDateString(),
            'metodo_pagamento' => MetodoPagamento::BONIFICO->value,
            'allocazioni' => [
                [
                    'fattura_id' => $fattura->id,
                    'tipo' => TipoAllocazioneFattura::PAGAMENTO->value,
                    'importo_allocato_cents' => 122000,
                ],
            ],
            'bonifico_parlante' => false,
            'allow_overdraft' => true,
        ]);

    $response->assertStatus(302);
    $response->assertSessionHasNoErrors();

    $fattura->refresh();
    expect($fattura->stato_pagamento)->toBe(StatoPagamentoFattura::PAGATA);

    $pagamento = PagamentoFornitore::first();
    expect($pagamento)->not->toBeNull();

    $scrittura = $pagamento->scrittura;
    expect($scrittura)->not->toBeNull();
    expect($scrittura->isQuadrata())->toBeTrue();

    $rigaBanca = $scrittura->righe()->where('tipo_riga', 'avere')->first();
    expect($rigaBanca->cassa_id)->not->toBeNull();
});

test('pagamento fattura con ritenuta: il listener F24 viene triggerato', function () {
    [$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo] = setupPagamentiHttp();
    $fornitore->update([
        'soggetto_ritenuta' => true,
        'perc_ritenuta' => 20,
        'perc_imponibile_ritenuta' => 100,
        'codice_tributo' => '1040',
    ]);

    $fattura = registraFatturaServiceTest([$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo], [
        'applica_ritenuta' => true,
        'importo_ritenuta' => 20000,
        'importo_netto_a_pagare' => 102000,
    ]);

    $response = $this->actingAs($this->user)
        ->post(route('admin.gestionale.pagamenti-fornitori.store', [$condominio]), [
            'fornitore_id' => $fornitore->id,
            'esercizio_id' => $esercizio->id,
            'conto_corrente_id' => $contoCorrenteId,
            'data_pagamento' => now()->toDateString(),
            'metodo_pagamento' => MetodoPagamento::BONIFICO->value,
            'allocazioni' => [
                [
                    'fattura_id' => $fattura->id,
                    'tipo' => TipoAllocazioneFattura::PAGAMENTO->value,
                    'importo_allocato_cents' => 102000,
                ],
            ],
            'importo_ritenuta_cents' => 20000,
            'allow_overdraft' => true,
        ]);

    $response->assertStatus(302);
    $response->assertSessionHasNoErrors();

    $pagamento = PagamentoFornitore::first();

    CategoriaEvento::firstOrCreate(
        ['name' => 'Scadenze amministrative'],
        ['description' => 'Test', 'color' => '#000000', 'icon' => 'test']
    );

    (new SyncF24WithPagamento)->handle(new PagamentoRegistrato($pagamento));

    $task = Evento::where('meta->type', 'versamento_ritenuta')
        ->where('meta->context->pagamento_id', $pagamento->id)
        ->first();

    if (! $task) {
        dump('PAGAMENTO_ID: '.$pagamento->id);
        dump('IMPORTO_RITENUTA: '.$pagamento->importo_ritenuta);
        dump('FATTURA_ID: '.$fattura->id);
        dump('EVENTI_COUNT: '.Evento::count());
        dump('EVENTI: '.Evento::all()->toJson());
    }

    expect($task)->not->toBeNull();
    expect($task->meta['importo'])->toEqual(20000);
});

test('bonifico parlante: causale ben formata con riferimento normativo', function () {
    [$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo] = setupPagamentiHttp();
    $fattura = registraFatturaServiceTest([$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo]);

    $response = $this->actingAs($this->user)
        ->post(route('admin.gestionale.pagamenti-fornitori.store', [$condominio]), [
            'fornitore_id' => $fornitore->id,
            'esercizio_id' => $esercizio->id,
            'conto_corrente_id' => $contoCorrenteId,
            'data_pagamento' => now()->toDateString(),
            'metodo_pagamento' => MetodoPagamento::BONIFICO->value,
            'allocazioni' => [
                [
                    'fattura_id' => $fattura->id,
                    'tipo' => TipoAllocazioneFattura::PAGAMENTO->value,
                    'importo_allocato_cents' => 122000,
                ],
            ],
            'bonifico_parlante' => true,
            'allow_overdraft' => true,
            'tipo_detrazione' => TipoDetrazione::RISTRUTTURAZIONE->value,
            'beneficiari_detrazione' => [
                ['codice_fiscale' => 'RSSMRA80A01H501U'],
            ],
        ]);

    $response->assertStatus(302);
    $response->assertSessionHasNoErrors();

    $pagamento = PagamentoFornitore::first();
    expect($pagamento->causale_bonifico)->toContain(TipoDetrazione::RISTRUTTURAZIONE->riferimentoNormativo());
    expect($pagamento->causale_bonifico)->toContain('RSSMRA80A01H501U');
});

test('compensazione nota di credito: netting a 3 record pivot e invariante di cassa', function () {
    [$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo] = setupPagamentiHttp();

    $fattura = registraFatturaServiceTest([$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo]);
    $notaCredito = registraFatturaServiceTest([$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo], [
        'tipo_documento' => 'nota_credito',
        'applica_ritenuta' => false,
        'righe' => [[
            'descrizione' => 'Reso',
            'importo_imponibile' => 200,
            'aliquota_iva' => 22,
            'conto_id' => $capitolo->id,
            'is_sopravvenienza' => false,
        ]],
    ]);

    $response = $this->actingAs($this->user)
        ->post(route('admin.gestionale.pagamenti-fornitori.store', [$condominio]), [
            'fornitore_id' => $fornitore->id,
            'esercizio_id' => $esercizio->id,
            'conto_corrente_id' => $contoCorrenteId,
            'data_pagamento' => now()->toDateString(),
            'metodo_pagamento' => MetodoPagamento::BONIFICO->value,
            'allocazioni' => [
                [
                    'fattura_id' => $fattura->id,
                    'tipo' => TipoAllocazioneFattura::PAGAMENTO->value,
                    'importo_allocato_cents' => 97600, // 1220 - 244
                ],
                [
                    'fattura_id' => $notaCredito->id,
                    'tipo' => TipoAllocazioneFattura::COMPENSAZIONE->value,
                    'importo_allocato_cents' => 24400,
                ],
                [
                    'fattura_id' => $fattura->id,
                    'tipo' => TipoAllocazioneFattura::COMPENSAZIONE->value,
                    'importo_allocato_cents' => 24400,
                ],
            ],
            'bonifico_parlante' => false,
            'allow_overdraft' => true,
        ]);

    $response->assertStatus(302);
    $response->assertSessionHasNoErrors();

    $pagamento = PagamentoFornitore::first();
    // La relazione è $pagamento->scrittura->fatture
    expect($pagamento->scrittura->fatture()->count())->toBe(3);

    $cassaUscita = $pagamento->scrittura->righe()->where('tipo_riga', 'avere')->whereNotNull('cassa_id')->sum('importo');
    $sommaPagamenti = $pagamento->scrittura->fatture()->wherePivot('tipo', 'pagamento')->sum('importo_allocato');

    expect((int) $cassaUscita)->toEqual((int) $sommaPagamenti);
});

test('update pagamento confermato aggiorna i campi mutabili', function () {
    [$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo] = setupPagamentiHttp();
    $fattura = registraFatturaServiceTest([$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo]);

    $this->actingAs($this->user)
        ->post(route('admin.gestionale.pagamenti-fornitori.store', [$condominio]), [
            'fornitore_id' => $fornitore->id,
            'esercizio_id' => $esercizio->id,
            'conto_corrente_id' => $contoCorrenteId,
            'data_pagamento' => now()->toDateString(),
            'metodo_pagamento' => MetodoPagamento::BONIFICO->value,
            'allocazioni' => [
                [
                    'fattura_id' => $fattura->id,
                    'tipo' => TipoAllocazioneFattura::PAGAMENTO->value,
                    'importo_allocato_cents' => 122000,
                ],
            ],
            'bonifico_parlante' => false,
            'allow_overdraft' => true,
        ]);

    $pagamento = PagamentoFornitore::first();

    $response = $this->actingAs($this->user)
        ->put(route('admin.gestionale.pagamenti-fornitori.update', [$condominio, $pagamento]), [
            'conto_corrente_id' => $contoCorrenteId,
            'data_pagamento' => now()->addDay()->toDateString(),
            'metodo_pagamento' => MetodoPagamento::BONIFICO->value,
            'importo_lordo_cents' => $pagamento->importo_lordo,
            'importo_netto_cents' => $pagamento->importo_netto,
            'importo_ritenuta_cents' => $pagamento->importo_ritenuta,
            'causale_bonifico' => 'Modifica test causale',
            'note_override' => 'Nota aggiunta post',
        ]);

    $response->assertStatus(302);
    $response->assertSessionHasNoErrors();

    $pagamento->refresh();
    expect($pagamento->causale_bonifico)->toBe('Modifica test causale');
    expect($pagamento->note_override)->toBe('Nota aggiunta post');
    expect($pagamento->data_pagamento->toDateString())->toBe(now()->addDay()->toDateString());
});

test('pagina di modifica pagamento espone la lista fornitori richiesta dalla sentinella anti-frode IBAN', function () {
    [$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo] = setupPagamentiHttp();
    $fattura = registraFatturaServiceTest([$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo]);

    $this->actingAs($this->user)
        ->post(route('admin.gestionale.pagamenti-fornitori.store', [$condominio]), [
            'fornitore_id' => $fornitore->id,
            'esercizio_id' => $esercizio->id,
            'conto_corrente_id' => $contoCorrenteId,
            'data_pagamento' => now()->toDateString(),
            'metodo_pagamento' => MetodoPagamento::BONIFICO->value,
            'allocazioni' => [
                [
                    'fattura_id' => $fattura->id,
                    'tipo' => TipoAllocazioneFattura::PAGAMENTO->value,
                    'importo_allocato_cents' => 122000,
                ],
            ],
            'bonifico_parlante' => false,
            'allow_overdraft' => true,
        ]);

    $pagamento = PagamentoFornitore::first();

    $response = $this->actingAs($this->user)
        ->get(route('admin.gestionale.pagamenti-fornitori.edit', [$condominio, $pagamento]));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('gestionale/movimenti/pagamenti/PagamentoEdit')
        ->has('fornitori')
        ->where('fornitori.0.id', $fornitore->id)
    );
});

test('un pagamento stornato non è raggiungibile in modifica: edit() reindirizza con un errore chiaro', function () {
    [$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo] = setupPagamentiHttp();
    $fattura = registraFatturaServiceTest([$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo]);

    $this->actingAs($this->user)
        ->post(route('admin.gestionale.pagamenti-fornitori.store', [$condominio]), [
            'fornitore_id' => $fornitore->id,
            'esercizio_id' => $esercizio->id,
            'conto_corrente_id' => $contoCorrenteId,
            'data_pagamento' => now()->toDateString(),
            'metodo_pagamento' => MetodoPagamento::BONIFICO->value,
            'allocazioni' => [
                [
                    'fattura_id' => $fattura->id,
                    'tipo' => TipoAllocazioneFattura::PAGAMENTO->value,
                    'importo_allocato_cents' => 122000,
                ],
            ],
            'bonifico_parlante' => false,
            'allow_overdraft' => true,
        ]);

    $pagamento = PagamentoFornitore::first();

    $this->actingAs($this->user)
        ->post(route('admin.gestionale.pagamenti-fornitori.storno', [$condominio, $pagamento]), [
            'motivo' => 'Storno di test: IBAN errato del fornitore.',
        ]);

    $pagamento->refresh();
    expect($pagamento->stato)->toBe(StatoPagamentoFornitore::STORNATO);

    // La pagina di modifica non deve MAI essere raggiungibile per un pagamento stornato:
    // redirect immediato invece di renderizzare un form che fallirebbe silenziosamente al salvataggio.
    $response = $this->actingAs($this->user)
        ->get(route('admin.gestionale.pagamenti-fornitori.edit', [$condominio, $pagamento]));

    $response->assertRedirect(route('admin.gestionale.pagamenti-fornitori.show', [$condominio, $pagamento]));
    $response->assertSessionHas('message.type', 'error');

    // Difesa in profondità: anche un PUT diretto (bypassando la UI) deve fallire con un
    // errore di validazione esplicito (errors.modifica_vietata), non un redirect silenzioso
    // che il frontend Inertia interpreterebbe come successo.
    $updateResponse = $this->actingAs($this->user)
        ->put(route('admin.gestionale.pagamenti-fornitori.update', [$condominio, $pagamento]), [
            'conto_corrente_id' => $contoCorrenteId,
            'data_pagamento' => now()->toDateString(),
            'metodo_pagamento' => MetodoPagamento::BONIFICO->value,
            'importo_lordo_cents' => $pagamento->importo_lordo,
            'importo_netto_cents' => $pagamento->importo_netto,
        ]);

    $updateResponse->assertSessionHasErrors('modifica_vietata');
});
