<?php

use App\Http\Controllers\Gestionale\Movimenti\StornoFatturaController;
use App\Models\Gestionale\ContoContabile;
use App\Services\Gestionale\FatturaPassivaService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/GestionaleTestHelpers.php';

/**
 * Regressione dei 5 difetti indipendenti elencati in
 * docs/design/f24_ritenute_design.md §8, corretti nella Fase 1 di beta.21.
 */

it('punto 1: applica_ritenuta=false via HTTP sopprime la ritenuta (StoreFatturaRequest la valida)', function () {
    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $user = \App\Models\User::factory()->create();
    $user->givePermissionTo('Accesso pannello amministratore');
    $this->actingAs($user);

    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = setupContabile();
    $fornitore->update(['soggetto_ritenuta' => true, 'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 4]);

    $payload = datiBase([$condominio, $esercizio, $gestione, $fornitore], [
        'applica_ritenuta' => false,
        'stato_approvazione' => 'approvata',
        'dati_extra' => ['fiscal' => ['motivo_esclusione_ritenuta' => 'bonifico_parlante'], 'competenza' => null, 'override_budget' => null],
        'righe' => [[
            'descrizione' => 'Pulizie con bonifico parlante',
            'importo_imponibile' => 1000,
            'aliquota_iva' => 22,
            'conto_id' => $capitolo->id,
            'is_sopravvenienza' => false,
        ]],
    ]);

    $this->post(route('admin.gestionale.fatture.store', ['condominio' => $condominio->id]), $payload)
        ->assertSessionHasNoErrors();

    $fattura = \App\Models\Gestionale\FatturaPassiva::where('condominio_id', $condominio->id)->latest('id')->first();

    expect((int) $fattura->importo_ritenuta)->toBe(0)
        ->and($fattura->dati_extra['fiscal']['motivo_esclusione_ritenuta'] ?? null)->toBe('bonifico_parlante');
});

it('punto 2: lo storno propaga applica_ritenuta dall\'originale — nessun residuo su 2202', function () {
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = setupContabile();
    $fornitore->update(['soggetto_ritenuta' => true, 'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 4]);

    $fattura = (new FatturaPassivaService())->registraFattura(
        datiBase([$condominio, $esercizio, $gestione, $fornitore], [
            'righe' => [[
                'descrizione' => 'Appalto', 'importo_imponibile' => 1000, 'aliquota_iva' => 22,
                'conto_id' => $capitolo->id, 'is_sopravvenienza' => false,
            ]],
        ]),
        $condominio->id
    );

    expect((int) $fattura->importo_ritenuta)->toBe(4000);

    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $user = \App\Models\User::factory()->create();
    $user->givePermissionTo('Accesso pannello amministratore');
    $this->actingAs($user);

    $this->post(route('admin.gestionale.fatture.storno', ['condominio' => $condominio->id, 'fattura' => $fattura->id]))
        ->assertSessionHasNoErrors();

    $contoErario = ContoContabile::where('condominio_id', $condominio->id)->where('ruolo', 'debiti_erario_ritenute')->first();

    $dareErario = DB::table('righe_scritture')
        ->where('conto_contabile_id', $contoErario->id)
        ->where('tipo_riga', 'dare')
        ->sum('importo');
    $avereErario = DB::table('righe_scritture')
        ->where('conto_contabile_id', $contoErario->id)
        ->where('tipo_riga', 'avere')
        ->sum('importo');

    // L'originale accredita 40€ (AVERE), lo storno deve stornarli esattamente:
    // 40€ di DARE sullo stesso conto. Nessun residuo, nessun fantasma.
    expect((int) $dareErario)->toBe((int) $avereErario)
        ->and((int) $avereErario)->toBe(4000);
});

it('punto 3: nota di credito genuina su fornitore soggetto a ritenuta NON calcola ritenuta di default', function () {
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = setupContabile();
    $fornitore->update(['soggetto_ritenuta' => true, 'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 4]);

    // Il frontend di una NC genuina non invia MAI la chiave applica_ritenuta.
    $nc = (new FatturaPassivaService())->registraFattura(
        datiBase([$condominio, $esercizio, $gestione, $fornitore], [
            'tipo_documento' => 'nota_credito',
            'righe' => [[
                'descrizione' => 'Storno parziale', 'importo_imponibile' => 1000, 'aliquota_iva' => 22,
                'conto_id' => $capitolo->id, 'is_sopravvenienza' => false,
            ]],
        ]),
        $condominio->id
    );

    expect((int) $nc->importo_ritenuta)->toBe(0);

    $scrittura = $nc->scritture->first();
    assertQuadraturaPerfetta($scrittura->id);

    $contoErario = ContoContabile::where('condominio_id', $condominio->id)->where('ruolo', 'debiti_erario_ritenute')->first();
    $righeErario = DB::table('righe_scritture')->where('conto_contabile_id', $contoErario->id)->count();

    expect($righeErario)->toBe(0, 'Nessuna riga fantasma sul conto Erario per una NC che non applica ritenuta');
});

it('punto 6: la nota della scrittura riporta l\'aliquota reale del fornitore, non un 4% fisso', function () {
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = setupContabile();
    $fornitore->update(['soggetto_ritenuta' => true, 'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 20]);

    $fattura = (new FatturaPassivaService())->registraFattura(
        datiBase([$condominio, $esercizio, $gestione, $fornitore], [
            'righe' => [[
                'descrizione' => 'Consulenza professionale', 'importo_imponibile' => 1000, 'aliquota_iva' => 22,
                'conto_id' => $capitolo->id, 'is_sopravvenienza' => false,
            ]],
        ]),
        $condominio->id
    );

    $contoErario = ContoContabile::where('condominio_id', $condominio->id)->where('ruolo', 'debiti_erario_ritenute')->first();
    $nota = DB::table('righe_scritture')
        ->where('scrittura_id', $fattura->scritture->first()->id)
        ->where('conto_contabile_id', $contoErario->id)
        ->value('note');

    expect($nota)->toContain('20%')->not->toContain('4%');
});

it('punto 9: la base ritenuta esclude le righe con concorre_base_ritenuta=false (contributo cassa)', function () {
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = setupContabile();
    $fornitore->update(['soggetto_ritenuta' => true, 'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 20]);

    $fattura = (new FatturaPassivaService())->registraFattura(
        datiBase([$condominio, $esercizio, $gestione, $fornitore], [
            'righe' => [
                [
                    'descrizione' => 'Prestazione professionale', 'importo_imponibile' => 800, 'aliquota_iva' => 22,
                    'conto_id' => $capitolo->id, 'is_sopravvenienza' => false, 'concorre_base_ritenuta' => true,
                ],
                [
                    'descrizione' => 'Contributo cassa professionale 4%', 'importo_imponibile' => 32, 'aliquota_iva' => 0,
                    'conto_id' => $capitolo->id, 'is_sopravvenienza' => false, 'concorre_base_ritenuta' => false,
                ],
            ],
        ]),
        $condominio->id
    );

    // Base = solo 800€ (la cassa professionale è esclusa) → ritenuta 20% = 160€
    expect((int) $fattura->importo_ritenuta)->toBe(16000);

    $scrittura = $fattura->scritture->first();
    assertQuadraturaPerfetta($scrittura->id);
});

it('regime nuovo attraverso un fornitore realmente persistito (round-trip DB completo, non solo in memoria)', function () {
    // I test di RitenutaCalcoloTest usano `new Fornitore([...])` senza salvare:
    // provano il calcolo, non che le colonne M2 sopravvivano a un giro reale su
    // tabella. Qui il fornitore passa da INSERT a Fornitore::find() a
    // FatturaPassivaService — l'intera pila, non solo il metodo puro.
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = setupContabile();

    $fornitore->update([
        'soggetto_ritenuta' => true,
        'tipo_ritenuta' => \App\Enums\Fiscale\TipoRitenuta::LAVORO_AUTONOMO_20->value,
        'natura_percipiente' => \App\Enums\Fiscale\NaturaPercipiente::SOGGETTO_IRES->value,
    ]);

    $fornitoreRicaricato = \App\Models\Fornitore::find($fornitore->id);
    expect($fornitoreRicaricato->tipo_ritenuta)->toBe(\App\Enums\Fiscale\TipoRitenuta::LAVORO_AUTONOMO_20)
        ->and($fornitoreRicaricato->natura_percipiente)->toBe(\App\Enums\Fiscale\NaturaPercipiente::SOGGETTO_IRES);

    $fattura = (new FatturaPassivaService())->registraFattura(
        datiBase([$condominio, $esercizio, $gestione, $fornitoreRicaricato], [
            'righe' => [[
                'descrizione' => 'Consulenza legale', 'importo_imponibile' => 1000, 'aliquota_iva' => 22,
                'conto_id' => $capitolo->id, 'is_sopravvenienza' => false,
            ]],
        ]),
        $condominio->id
    );

    // 20% su 1000€ = 200€, codice tributo 1040 (lavoro autonomo, sempre — non 1020)
    expect((int) $fattura->importo_ritenuta)->toBe(20000)
        ->and($fattura->dati_extra['fiscal']['ritenuta_details']['codice_tributo'] ?? null)->toBe('1040');

    $scrittura = $fattura->scritture->first();
    assertQuadraturaPerfetta($scrittura->id);
});

it('regressione: righe senza il flag concorre_base_ritenuta si comportano come prima (default true)', function () {
    // Fatture registrate prima di M3 (o payload che non manda il campo) non
    // devono cambiare comportamento: il default è "concorre".
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = setupContabile();
    $fornitore->update(['soggetto_ritenuta' => true, 'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 4]);

    $fattura = (new FatturaPassivaService())->registraFattura(
        datiBase([$condominio, $esercizio, $gestione, $fornitore], [
            'righe' => [[
                'descrizione' => 'Pulizie', 'importo_imponibile' => 1000, 'aliquota_iva' => 22,
                'conto_id' => $capitolo->id, 'is_sopravvenienza' => false,
                // niente concorre_base_ritenuta nel payload
            ]],
        ]),
        $condominio->id
    );

    expect((int) $fattura->importo_ritenuta)->toBe(4000);
});

/**
 * Regressione dei 2 difetti confermati dalla revisione avversariale pre-porting
 * (agente separato, diff completo, test HTTP di riproduzione poi cancellati).
 */

it('revisione avversariale — bug critico: modificare una fattura di un fornitore NON soggetto a ritenuta non deve fallire', function () {
    // Bug confermato: FatturaRegisterEdit.vue inizializzava applica_ritenuta a
    // un booleano esplicito SEMPRE (mai null). Per un fornitore non soggetto a
    // ritenuta questo valeva `false`, e required_if:applica_ritenuta,false in
    // UpdateFatturaRequest scattava per un campo (motivo_esclusione_ritenuta)
    // del tutto irrilevante per questa fattura. Qui simuliamo esattamente il
    // valore che il form invia dopo il round-trip Inertia FormData
    // (null → stringa vuota) per il caso comune: fornitore non soggetto.
    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $user = \App\Models\User::factory()->create();
    $user->givePermissionTo('Accesso pannello amministratore');
    $this->actingAs($user);

    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = setupContabile();
    // setupContabile() crea il fornitore con soggetto_ritenuta = false: il caso comune.

    $fattura = (new FatturaPassivaService())->registraFattura(
        datiBase([$condominio, $esercizio, $gestione, $fornitore], [
            'righe' => [[
                'descrizione' => 'Pulizie', 'importo_imponibile' => 1000, 'aliquota_iva' => 22,
                'conto_id' => $capitolo->id, 'is_sopravvenienza' => false,
            ]],
        ]),
        $condominio->id
    );

    $payload = [
        'gestione_id' => $gestione->id,
        'numero_documento' => $fattura->numero_documento,
        'data_documento' => now()->format('Y-m-d'),
        'data_scadenza' => now()->addDays(30)->format('Y-m-d'),
        'modalita_pagamento' => 'bonifico',
        'applica_ritenuta' => '', // esattamente ciò che il form invia quando form.applica_ritenuta === null
        'righe' => [[
            'descrizione' => 'Pulizie (modificata)',
            'importo_imponibile' => 1200,
            'aliquota_iva' => 22,
            'conto_id' => $capitolo->id,
        ]],
    ];

    $this->put(route('admin.gestionale.fatture.update', ['condominio' => $condominio->id, 'fattura' => $fattura->id]), $payload)
        ->assertSessionHasNoErrors();

    expect((int) $fattura->fresh()->importo_imponibile)->toBe(120000);
});

it('revisione avversariale — bug alto: lo storno usa l\'importo originale, non lo ricalcola sull\'anagrafica attuale del fornitore', function () {
    // Bug confermato: se il fornitore cambia regime fiscale fra la
    // registrazione e lo storno (es. diventa forfetario), RitenutaService
    // ricalcolerebbe 0€ di ritenuta sullo storno anche se l'originale ne
    // aveva registrati 40€ — lasciando lo stesso residuo fantasma su
    // 2201/2202 che il fix del punto 2 doveva chiudere.
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = setupContabile();
    $fornitore->update(['soggetto_ritenuta' => true, 'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 4]);

    $fattura = (new FatturaPassivaService())->registraFattura(
        datiBase([$condominio, $esercizio, $gestione, $fornitore], [
            'righe' => [[
                'descrizione' => 'Appalto', 'importo_imponibile' => 1000, 'aliquota_iva' => 22,
                'conto_id' => $capitolo->id, 'is_sopravvenienza' => false,
            ]],
        ]),
        $condominio->id
    );

    expect((int) $fattura->importo_ritenuta)->toBe(4000);

    // L'anagrafica cambia DOPO la registrazione: il fornitore diventa forfetario.
    // RitenutaService::calcola() forzerebbe 0€ se lo storno lo interrogasse di nuovo.
    $fornitore->update(['regime_forfetario' => true]);

    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $user = \App\Models\User::factory()->create();
    $user->givePermissionTo('Accesso pannello amministratore');
    $this->actingAs($user);

    $this->post(route('admin.gestionale.fatture.storno', ['condominio' => $condominio->id, 'fattura' => $fattura->id]))
        ->assertSessionHasNoErrors();

    $contoErario = ContoContabile::where('condominio_id', $condominio->id)->where('ruolo', 'debiti_erario_ritenute')->first();

    $dareErario = DB::table('righe_scritture')->where('conto_contabile_id', $contoErario->id)->where('tipo_riga', 'dare')->sum('importo');
    $avereErario = DB::table('righe_scritture')->where('conto_contabile_id', $contoErario->id)->where('tipo_riga', 'avere')->sum('importo');

    // L'originale ha accreditato 40€ (AVERE); lo storno deve stornarli
    // ESATTAMENTE, non ricalcolare 0€ perché il fornitore ora è forfetario.
    expect((int) $avereErario)->toBe(4000)
        ->and((int) $dareErario)->toBe(4000);
});

/**
 * Design §2.4 M2: natura_percipiente mancante — warning bloccante con
 * override in v1.10 (blocco duro rimandato a v1.11).
 */

it('natura_percipiente mancante: la registrazione viene rifiutata senza conferma esplicita', function () {
    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $user = \App\Models\User::factory()->create();
    $user->givePermissionTo('Accesso pannello amministratore');
    $this->actingAs($user);

    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = setupContabile();
    $fornitore->update([
        'soggetto_ritenuta' => true,
        'tipo_ritenuta' => \App\Enums\Fiscale\TipoRitenuta::APPALTO_4->value,
        // natura_percipiente NON impostata, codice_tributo legacy NON impostato:
        // il codice tributo (1019 vs 1020) è indeterminabile.
    ]);

    $payload = datiBase([$condominio, $esercizio, $gestione, $fornitore], [
        'stato_approvazione' => 'approvata',
        'righe' => [[
            'descrizione' => 'Appalto', 'importo_imponibile' => 1000, 'aliquota_iva' => 22,
            'conto_id' => $capitolo->id, 'is_sopravvenienza' => false,
        ]],
    ]);

    $this->post(route('admin.gestionale.fatture.store', ['condominio' => $condominio->id]), $payload)
        ->assertSessionHasErrors(['dati_extra.fiscal.conferma_codice_tributo_mancante']);

    expect(\App\Models\Gestionale\FatturaPassiva::where('condominio_id', $condominio->id)->count())->toBe(0);
});

it('natura_percipiente mancante: con conferma esplicita la registrazione procede (codice tributo resta vuoto)', function () {
    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $user = \App\Models\User::factory()->create();
    $user->givePermissionTo('Accesso pannello amministratore');
    $this->actingAs($user);

    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = setupContabile();
    $fornitore->update([
        'soggetto_ritenuta' => true,
        'tipo_ritenuta' => \App\Enums\Fiscale\TipoRitenuta::APPALTO_4->value,
    ]);

    $payload = datiBase([$condominio, $esercizio, $gestione, $fornitore], [
        'stato_approvazione' => 'approvata',
        'dati_extra' => ['fiscal' => ['conferma_codice_tributo_mancante' => true], 'competenza' => null, 'override_budget' => null],
        'righe' => [[
            'descrizione' => 'Appalto', 'importo_imponibile' => 1000, 'aliquota_iva' => 22,
            'conto_id' => $capitolo->id, 'is_sopravvenienza' => false,
        ]],
    ]);

    $this->post(route('admin.gestionale.fatture.store', ['condominio' => $condominio->id]), $payload)
        ->assertSessionHasNoErrors();

    $fattura = \App\Models\Gestionale\FatturaPassiva::where('condominio_id', $condominio->id)->latest('id')->first();
    expect((int) $fattura->importo_ritenuta)->toBe(4000) // 4% di 1000€ — il calcolo procede comunque
        ->and($fattura->dati_extra['fiscal']['ritenuta_details']['codice_tributo'] ?? null)->toBeNull();
});

it('natura_percipiente mancante: NON blocca se il fornitore ha un codice_tributo legacy come override', function () {
    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $user = \App\Models\User::factory()->create();
    $user->givePermissionTo('Accesso pannello amministratore');
    $this->actingAs($user);

    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = setupContabile();
    $fornitore->update([
        'soggetto_ritenuta' => true,
        'tipo_ritenuta' => \App\Enums\Fiscale\TipoRitenuta::APPALTO_4->value,
        'codice_tributo' => '1019', // override manuale: risolve l'ambiguità da solo
    ]);

    $payload = datiBase([$condominio, $esercizio, $gestione, $fornitore], [
        'stato_approvazione' => 'approvata',
        'righe' => [[
            'descrizione' => 'Appalto', 'importo_imponibile' => 1000, 'aliquota_iva' => 22,
            'conto_id' => $capitolo->id, 'is_sopravvenienza' => false,
        ]],
    ]);

    $this->post(route('admin.gestionale.fatture.store', ['condominio' => $condominio->id]), $payload)
        ->assertSessionHasNoErrors();
});
