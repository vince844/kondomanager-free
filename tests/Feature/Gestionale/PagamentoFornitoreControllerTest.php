<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

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

    // Coda 718 (decisione 675): un promemoria per condominio, «Prepara le deleghe F24», non uno per pagamento.
    $task = Evento::where('meta->type', 'versamento_ritenuta')
        ->where('meta->context->prepara_deleghe', true)
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
            // Una data diversa da quella di registrazione, ma nel passato: la data
            // di pagamento descrive un movimento già avvenuto e non può essere futura.
            'data_pagamento' => now()->subDay()->toDateString(),
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
    expect($pagamento->data_pagamento->toDateString())->toBe(now()->subDay()->toDateString());
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

test('filtro stato isola il pagamento stornato dagli altri', function () {
    [$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo] = setupPagamentiHttp();
    $fatturaConfermata = registraFatturaServiceTest([$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo]);
    $fatturaStornata = registraFatturaServiceTest([$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo]);

    $this->actingAs($this->user)
        ->post(route('admin.gestionale.pagamenti-fornitori.store', [$condominio]), [
            'fornitore_id' => $fornitore->id,
            'esercizio_id' => $esercizio->id,
            'conto_corrente_id' => $contoCorrenteId,
            'data_pagamento' => now()->toDateString(),
            'metodo_pagamento' => MetodoPagamento::BONIFICO->value,
            'allocazioni' => [
                [
                    'fattura_id' => $fatturaConfermata->id,
                    'tipo' => TipoAllocazioneFattura::PAGAMENTO->value,
                    'importo_allocato_cents' => 122000,
                ],
            ],
            'bonifico_parlante' => false,
            'allow_overdraft' => true,
        ]);

    $pagamentoConfermato = PagamentoFornitore::first();

    $this->actingAs($this->user)
        ->post(route('admin.gestionale.pagamenti-fornitori.store', [$condominio]), [
            'fornitore_id' => $fornitore->id,
            'esercizio_id' => $esercizio->id,
            'conto_corrente_id' => $contoCorrenteId,
            'data_pagamento' => now()->toDateString(),
            'metodo_pagamento' => MetodoPagamento::BONIFICO->value,
            'allocazioni' => [
                [
                    'fattura_id' => $fatturaStornata->id,
                    'tipo' => TipoAllocazioneFattura::PAGAMENTO->value,
                    'importo_allocato_cents' => 122000,
                ],
            ],
            'bonifico_parlante' => false,
            'allow_overdraft' => true,
        ]);

    $pagamentoDaStornare = PagamentoFornitore::where('id', '!=', $pagamentoConfermato->id)->firstOrFail();

    $this->actingAs($this->user)
        ->post(route('admin.gestionale.pagamenti-fornitori.storno', [$condominio, $pagamentoDaStornare]), [
            'motivo' => 'Storno di test per isolare il filtro stato.',
        ]);

    // Lo storno genera un terzo record (la scrittura di storno stessa nasce
    // "confermato"): a questo punto ci sono 2 pagamenti confermati e 1 stornato,
    // quindi il filtro stato=stornato è quello che isola in modo inequivocabile
    // il pagamento originale appena stornato.
    expect(PagamentoFornitore::count())->toBe(3);

    $response = $this->actingAs($this->user)
        ->get(route('admin.gestionale.pagamenti-fornitori.index', [$condominio]).'?stato=stornato');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('gestionale/movimenti/pagamenti/PagamentiList')
        ->has('pagamenti.data', 1)
        ->where('pagamenti.data.0.id', $pagamentoDaStornare->id)
        ->where('pagamenti.data.0.stato', 'stornato')
    );
});

test('filtro intervallo date isola i pagamenti per data_pagamento', function () {
    [$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo] = setupPagamentiHttp();
    $fatturaFuoriRange = registraFatturaServiceTest([$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo]);
    $fatturaDentroRange = registraFatturaServiceTest([$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo]);

    $this->actingAs($this->user)
        ->post(route('admin.gestionale.pagamenti-fornitori.store', [$condominio]), [
            'fornitore_id' => $fornitore->id,
            'esercizio_id' => $esercizio->id,
            'conto_corrente_id' => $contoCorrenteId,
            'data_pagamento' => '2026-01-10',
            'metodo_pagamento' => MetodoPagamento::BONIFICO->value,
            'allocazioni' => [
                [
                    'fattura_id' => $fatturaFuoriRange->id,
                    'tipo' => TipoAllocazioneFattura::PAGAMENTO->value,
                    'importo_allocato_cents' => 122000,
                ],
            ],
            'bonifico_parlante' => false,
            'allow_overdraft' => true,
        ]);

    $this->actingAs($this->user)
        ->post(route('admin.gestionale.pagamenti-fornitori.store', [$condominio]), [
            'fornitore_id' => $fornitore->id,
            'esercizio_id' => $esercizio->id,
            'conto_corrente_id' => $contoCorrenteId,
            'data_pagamento' => '2026-03-15',
            'metodo_pagamento' => MetodoPagamento::BONIFICO->value,
            'allocazioni' => [
                [
                    'fattura_id' => $fatturaDentroRange->id,
                    'tipo' => TipoAllocazioneFattura::PAGAMENTO->value,
                    'importo_allocato_cents' => 122000,
                ],
            ],
            'bonifico_parlante' => false,
            'allow_overdraft' => true,
        ]);

    $pagamentoDentroRange = PagamentoFornitore::whereDate('data_pagamento', '2026-03-15')->firstOrFail();

    $response = $this->actingAs($this->user)
        ->get(route('admin.gestionale.pagamenti-fornitori.index', [$condominio]).'?data_da=2026-03-01&data_a=2026-03-31');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('gestionale/movimenti/pagamenti/PagamentiList')
        ->has('pagamenti.data', 1)
        ->where('pagamenti.data.0.id', $pagamentoDentroRange->id)
    );
});

test('un IBAN diverso da quello del fornitore, senza conferma, torna alla pagina con la frase per l\'amministratore e non registra niente', function () {
    [$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo] = setupPagamentiHttp();
    $fornitore->update(['iban_principale' => 'IT60X0542811101000000123456']);
    $fattura = registraFatturaServiceTest([$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo]);

    $dati = [
        'fornitore_id' => $fornitore->id,
        'esercizio_id' => $esercizio->id,
        'conto_corrente_id' => $contoCorrenteId,
        'data_pagamento' => now()->toDateString(),
        'metodo_pagamento' => MetodoPagamento::BONIFICO->value,
        'iban_beneficiario' => 'IT28W8000000292100645211208',
        'allocazioni' => [[
            'fattura_id' => $fattura->id,
            'tipo' => TipoAllocazioneFattura::PAGAMENTO->value,
            'importo_allocato_cents' => 122000,
        ]],
        'bonifico_parlante' => false,
        'allow_overdraft' => true,
    ];
    $rotta = route('admin.gestionale.pagamenti-fornitori.store', [$condominio]);

    $risposta = $this->actingAs($this->user)->post($rotta, $dati);

    $risposta->assertSessionHasErrors('iban_discrepanza');
    expect(session('errors')->first('iban_discrepanza'))
        ->toContain('IT60X0542811101000000123456')
        ->not->toContain('iban_confermato_manualmente');
    expect(PagamentoFornitore::count())->toBe(0);

    // Con la conferma dell'amministratore lo stesso pagamento passa.
    $this->actingAs($this->user)->post($rotta, $dati + ['iban_confermato_manualmente' => true])
        ->assertSessionHasNoErrors();
    expect(PagamentoFornitore::count())->toBe(1);
});

test('la modifica con un IBAN diverso da quello del fornitore, senza conferma, torna alla pagina con la frase per l\'amministratore e non cambia il pagamento', function () {
    [$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo] = setupPagamentiHttp();
    $fornitore->update(['iban_principale' => 'IT60X0542811101000000123456']);
    $fattura = registraFatturaServiceTest([$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo]);

    $this->actingAs($this->user)->post(route('admin.gestionale.pagamenti-fornitori.store', [$condominio]), [
        'fornitore_id' => $fornitore->id,
        'esercizio_id' => $esercizio->id,
        'conto_corrente_id' => $contoCorrenteId,
        'data_pagamento' => now()->toDateString(),
        'metodo_pagamento' => MetodoPagamento::BONIFICO->value,
        'iban_beneficiario' => 'IT60X0542811101000000123456',
        'allocazioni' => [['fattura_id' => $fattura->id, 'tipo' => TipoAllocazioneFattura::PAGAMENTO->value, 'importo_allocato_cents' => 122000]],
        'bonifico_parlante' => false,
        'allow_overdraft' => true,
    ])->assertSessionHasNoErrors();
    $pagamento = PagamentoFornitore::firstOrFail();

    $dati = [
        'conto_corrente_id' => $contoCorrenteId,
        'data_pagamento' => $pagamento->data_pagamento->toDateString(),
        'metodo_pagamento' => MetodoPagamento::BONIFICO->value,
        'importo_lordo_cents' => $pagamento->importo_lordo,
        'importo_netto_cents' => $pagamento->importo_netto,
        'importo_ritenuta_cents' => $pagamento->importo_ritenuta,
        'iban_beneficiario' => 'IT28W8000000292100645211208',
        'causale_bonifico' => 'Causale nuova',
    ];
    $rotta = route('admin.gestionale.pagamenti-fornitori.update', [$condominio, $pagamento]);

    $this->actingAs($this->user)->put($rotta, $dati)->assertSessionHasErrors('iban_discrepanza');
    expect(session('errors')->first('iban_discrepanza'))->toContain('IT60X0542811101000000123456')->not->toContain('iban_confermato_manualmente');
    expect($pagamento->fresh()->iban_beneficiario)->toBe('IT60X0542811101000000123456')
        ->and($pagamento->fresh()->causale_bonifico)->not->toBe('Causale nuova');

    // Con la conferma dell'amministratore la stessa modifica passa.
    $this->actingAs($this->user)->put($rotta, $dati + ['iban_confermato_manualmente' => true])->assertSessionHasNoErrors();
    expect($pagamento->fresh()->iban_beneficiario)->toBe('IT28W8000000292100645211208');
});

test('la card «pagato nel mese» conta il mese di Roma: alle 00:30 del 01/12 un pagamento del 01/12 è di dicembre (Coda 547)', function () {
    // Alle 00:30 di Roma del 01/12/2026 `now()` (UTC) dice ancora le 23:30 del 30/11: il mese era novembre.
    $ctx = setupPagamentiHttp();
    [$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo] = $ctx;
    $fattura = registraFatturaServiceTest($ctx);
    $this->travelTo(\Carbon\Carbon::parse('2026-12-01 00:30', 'Europe/Rome'));

    $this->actingAs($this->user)
        ->post(route('admin.gestionale.pagamenti-fornitori.store', [$condominio]), [
            'fornitore_id' => $fornitore->id,
            'esercizio_id' => $esercizio->id,
            'conto_corrente_id' => $contoCorrenteId,
            'data_pagamento' => '2026-12-01',
            'metodo_pagamento' => MetodoPagamento::BONIFICO->value,
            'allocazioni' => [['fattura_id' => $fattura->id, 'tipo' => TipoAllocazioneFattura::PAGAMENTO->value, 'importo_allocato_cents' => 122000]],
            'bonifico_parlante' => false,
            'allow_overdraft' => true,
        ])->assertSessionHasNoErrors();

    $this->actingAs($this->user)
        ->get(route('admin.gestionale.pagamenti-fornitori.index', [$condominio]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('stats.totale_pagato_mese', 122000));
});

/** Un pagamento a bonifico di € 1.220,00 con l'IBAN dato (o senza), scritto dalla rotta; restituisce il contesto e il pagamento. */
function b14PagamentoConIban(?string $iban): array
{
    $ctx = setupPagamentiHttp();
    [$condominio, $esercizio, , $fornitore, $contoCorrenteId] = $ctx;
    $fattura = registraFatturaServiceTest($ctx);
    $dati = [
        'fornitore_id' => $fornitore->id, 'esercizio_id' => $esercizio->id, 'conto_corrente_id' => $contoCorrenteId,
        'data_pagamento' => now()->toDateString(), 'metodo_pagamento' => MetodoPagamento::BONIFICO->value,
        'allocazioni' => [['fattura_id' => $fattura->id, 'tipo' => TipoAllocazioneFattura::PAGAMENTO->value, 'importo_allocato_cents' => 122000]],
        'bonifico_parlante' => false, 'allow_overdraft' => true,
    ] + ($iban === null ? [] : ['iban_beneficiario' => $iban]);
    test()->actingAs(test()->user)->post(route('admin.gestionale.pagamenti-fornitori.store', [$condominio]), $dati)->assertSessionHasNoErrors();

    return [$ctx, PagamentoFornitore::firstOrFail()];
}

/** I campi del modulo di modifica come li manda la pagina: quelli del pagamento com'è, più quelli dati. */
function b14DatiModifica(PagamentoFornitore $p, int $contoId, array $in): array
{
    return $in + [
        'conto_corrente_id' => $contoId, 'data_pagamento' => $p->data_pagamento->toDateString(), 'metodo_pagamento' => MetodoPagamento::BONIFICO->value,
        'importo_lordo_cents' => $p->importo_lordo, 'importo_netto_cents' => $p->importo_netto, 'importo_ritenuta_cents' => $p->importo_ritenuta,
    ];
}

test('la pagina di modifica del pagamento porta l\'IBAN registrato (Coda 626)', function () {
    [$ctx, $p] = b14PagamentoConIban('IT60X0542811101000000123456');

    $this->actingAs($this->user)->get(route('admin.gestionale.pagamenti-fornitori.edit', [$ctx[0], $p]))->assertOk()
        ->assertInertia(fn ($page) => $page->where('pagamento.iban_beneficiario', 'IT60X0542811101000000123456'));
});

test('un IBAN scritto a gruppi di quattro passa nella registrazione e nella modifica e si salva senza spazi (Coda 626)', function () {
    [$ctx, $p] = b14PagamentoConIban('IT60 X054 2811 1010 0000 0123 456');
    expect($p->iban_beneficiario)->toBe('IT60X0542811101000000123456');

    $trentuno = 'IT28 W800 0000 2921 0064 5211 208';
    $this->actingAs($this->user)->put(route('admin.gestionale.pagamenti-fornitori.update', [$ctx[0], $p]), b14DatiModifica($p, $ctx[4], ['iban_beneficiario' => $trentuno, 'iban_confermato_manualmente' => true]))
        ->assertSessionHasNoErrors();

    expect($p->fresh()->iban_beneficiario)->toBe('IT28W8000000292100645211208');
});

test('un IBAN lungo più di 34 caratteri, già normalizzato, si rifiuta nella registrazione e nella modifica (Coda 626)', function () {
    [$ctx, $p] = b14PagamentoConIban('IT60X0542811101000000123456');
    $lungo = 'IT60 X054 2811 1010 0000 0123 4560 0000 0123 4560';

    $this->actingAs($this->user)->put(route('admin.gestionale.pagamenti-fornitori.update', [$ctx[0], $p]), b14DatiModifica($p, $ctx[4], ['iban_beneficiario' => $lungo]))
        ->assertSessionHasErrors('iban_beneficiario');
    expect($p->fresh()->iban_beneficiario)->toBe('IT60X0542811101000000123456');

    $fattura2 = registraFatturaServiceTest($ctx);
    $this->actingAs($this->user)->post(route('admin.gestionale.pagamenti-fornitori.store', [$ctx[0]]), [
        'fornitore_id' => $ctx[3]->id, 'esercizio_id' => $ctx[1]->id, 'conto_corrente_id' => $ctx[4], 'data_pagamento' => now()->toDateString(),
        'metodo_pagamento' => MetodoPagamento::BONIFICO->value, 'iban_beneficiario' => $lungo,
        'allocazioni' => [['fattura_id' => $fattura2->id, 'tipo' => TipoAllocazioneFattura::PAGAMENTO->value, 'importo_allocato_cents' => 122000]],
        'bonifico_parlante' => false, 'allow_overdraft' => true,
    ])->assertSessionHasErrors('iban_beneficiario');
    expect(PagamentoFornitore::count())->toBe(1);
});

test('in modifica un IBAN svuotato si cancella: non torna quello di prima (Coda 626)', function () {
    [$ctx, $p] = b14PagamentoConIban('IT60X0542811101000000123456');

    $this->actingAs($this->user)->put(route('admin.gestionale.pagamenti-fornitori.update', [$ctx[0], $p]), b14DatiModifica($p, $ctx[4], ['iban_beneficiario' => '']))
        ->assertSessionHasNoErrors();

    expect($p->fresh()->iban_beneficiario)->toBeNull();
});

test('in modifica, senza il campo IBAN nella richiesta, quello registrato resta (Coda 626)', function () {
    [$ctx, $p] = b14PagamentoConIban('IT60X0542811101000000123456');

    $this->actingAs($this->user)->put(route('admin.gestionale.pagamenti-fornitori.update', [$ctx[0], $p]), b14DatiModifica($p, $ctx[4], ['causale_bonifico' => 'Altra causale']))
        ->assertSessionHasNoErrors();

    expect($p->fresh()->iban_beneficiario)->toBe('IT60X0542811101000000123456');
});
