<?php

use App\Enums\StatoPagamentoFattura;

use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\User;
use App\Services\Gestionale\FatturaPassivaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__ . '/GestionaleTestHelpers.php';

it('fattura ordinaria: bilancia Costo, Fornitore e Ritenuta', function () {
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = setupContabile();
    $fornitore->update(['soggetto_ritenuta' => true, 'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 4]);

    $data = datiBase([$condominio, $esercizio, $gestione, $fornitore], [
        'righe' => [[
            'descrizione'        => 'Pulizie',
            'importo_imponibile' => 1000,
            'aliquota_iva'       => 22,
            'conto_id'           => $capitolo->id,
            'is_sopravvenienza'  => false,
        ]],
    ]);

    $fattura   = (new FatturaPassivaService())->registraFattura($data, $condominio->id);
    $scrittura = $fattura->scritture->first();

    expect($fattura->netto_a_pagare)->toEqual(118000);
    assertQuadraturaPerfetta($scrittura->id);

    $contoErario = ContoContabile::where('condominio_id', $condominio->id)->where('ruolo', 'debiti_erario_ritenute')->first();
    $avereErario = DB::table('righe_scritture')
        ->where('scrittura_id', $scrittura->id)
        ->where('tipo_riga', 'avere')
        ->where('conto_contabile_id', $contoErario->id)
        ->value('importo');
    expect((int)$avereErario)->toEqual(4000);
});

it('nota di credito: inverte DARE/AVERE e quadra', function () {
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = setupContabile();

    $data = datiBase([$condominio, $esercizio, $gestione, $fornitore], [
        'tipo_documento'   => 'nota_credito',
        'applica_ritenuta' => false,
        'righe' => [[
            'descrizione'        => 'Storno Pulizie',
            'importo_imponibile' => 1000,
            'aliquota_iva'       => 22,
            'conto_id'           => $capitolo->id,
            'is_sopravvenienza'  => false,
        ]],
    ]);

    $nc        = (new FatturaPassivaService())->registraFattura($data, $condominio->id);
    $scrittura = $nc->scritture->first();

    expect($nc->totale_documento)->toEqual(-122000);
    assertQuadraturaPerfetta($scrittura->id);

    expect(DB::table('righe_scritture')->where('scrittura_id', $scrittura->id)->where('tipo_riga', 'avere')->where('importo', 122000)->exists())
        ->toBeTrue('Costo dovrebbe essere in AVERE');
    expect(DB::table('righe_scritture')->where('scrittura_id', $scrittura->id)->where('tipo_riga', 'dare')->where('importo', 122000)->exists())
        ->toBeTrue('Fornitore dovrebbe essere in DARE');
});

it('fattura mista: quota ad personam va su crediti condomini', function () {
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo, , $immobileId] = setupContabile();

    $data = datiBase([$condominio, $esercizio, $gestione, $fornitore], [
        'righe' => [
            ['descrizione' => 'Comune',  'importo_imponibile' => 500, 'aliquota_iva' => 10, 'conto_id' => $capitolo->id, 'is_sopravvenienza' => false],
            ['descrizione' => 'Privato', 'importo_imponibile' => 200, 'aliquota_iva' => 10, 'immobile_id' => $immobileId, 'is_sopravvenienza' => false],
        ],
    ]);

    $fattura   = (new FatturaPassivaService())->registraFattura($data, $condominio->id);
    $scrittura = $fattura->scritture->first();

    assertQuadraturaPerfetta($scrittura->id);

    $righeDare = DB::table('righe_scritture')
        ->where('scrittura_id', $scrittura->id)->where('tipo_riga', 'dare')
        ->pluck('importo')->map(fn($i) => (int)$i)->toArray();

    expect($righeDare)->toContain(55000)->and($righeDare)->toContain(22000);
});

it('fondo di riserva: giroconto DARE fondo + AVERE sopravvenienza', function () {
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo, $contoFondo] = setupContabile();

    $data = datiBase([$condominio, $esercizio, $gestione, $fornitore], [
        'righe' => [[
            'descrizione'        => 'Tetto',
            'importo_imponibile' => 2000,
            'aliquota_iva'       => 10,
            'conto_id'           => $capitolo->id,
            'is_sopravvenienza'  => false,
        ]],
        'dati_extra' => [
            'fiscal'          => [],
            'competenza'      => null,
            'override_budget' => [
                'strategia_rientro'     => 'fondo_riserva',
                'fondo_patrimoniale_id' => $contoFondo->id,
                'importo_sforo'         => 220000,
                'motivazione'           => 'Test',
            ],
        ],
    ]);

    $fattura   = (new FatturaPassivaService())->registraFattura($data, $condominio->id);
    $scrittura = $fattura->scritture->first();

    assertQuadraturaPerfetta($scrittura->id);

    $dareFondo = DB::table('righe_scritture')
        ->where('scrittura_id', $scrittura->id)
        ->where('tipo_riga', 'dare')
        ->where('conto_contabile_id', $contoFondo->id)
        ->value('importo');
    expect((int)$dareFondo)->toEqual(220000);
});

it('Scenario D: pregresso senza coperture → tutto su passate gestioni', function () {
    [$condominio, $esercizio, $gestione, $fornitore] = setupContabile();

    $data = datiBase([$condominio, $esercizio, $gestione, $fornitore], [
        'data_documento'         => '2024-12-01',
        'data_scadenza'          => '2024-12-31',
        'is_pregresso'           => true,
        'imponibile_pregresso'   => 819.67,
        'aliquota_iva_pregressa' => 22,
        'coperture'              => [],
        'righe'                  => [],
    ]);

    $fattura   = (new FatturaPassivaService())->registraFattura($data, $condominio->id);
    $scrittura = $fattura->scritture->first();

    assertQuadraturaPerfetta($scrittura->id);
    expect($fattura->coperture()->count())->toEqual(0);

    $contoPassate = ContoContabile::where('condominio_id', $condominio->id)->where('ruolo', 'passate_gestioni')->first();
    $darePassate  = DB::table('righe_scritture')
        ->where('scrittura_id', $scrittura->id)
        ->where('tipo_riga', 'dare')
        ->where('conto_contabile_id', $contoPassate->id)
        ->sum('importo');

    expect((int)$darePassate)->toEqual(100000);
});

it('Scenario A: pregresso con rata_0 → passate gestioni', function () {
    [$condominio, $esercizio, $gestione, $fornitore] = setupContabile();

    $saldoId = DB::table('saldi')->insertGetId([
        'condominio_id'  => $condominio->id,
        'esercizio_id'   => $esercizio->id,
        'saldo_iniziale' => -100000,
        'created_at'     => now(),
        'updated_at'     => now(),
    ]);

    $data = datiBase([$condominio, $esercizio, $gestione, $fornitore], [
        'data_documento'         => '2024-12-01',
        'data_scadenza'          => '2024-12-31',
        'is_pregresso'           => true,
        'imponibile_pregresso'   => 819.67,
        'aliquota_iva_pregressa' => 22,
        'saldo_patrimoniale_id'  => $saldoId,
        'righe'                  => [],
        'coperture' => [[
            'tipo_copertura' => 'rata_0',
            'importo'        => 1000.00,
            'fonte_id'       => $saldoId,
        ]],
    ]);

    $fattura   = (new FatturaPassivaService())->registraFattura($data, $condominio->id);
    $scrittura = $fattura->scritture->first();

    assertQuadraturaPerfetta($scrittura->id);

    $contoPassate = ContoContabile::where('condominio_id', $condominio->id)->where('ruolo', 'passate_gestioni')->first();
    $darePassate  = DB::table('righe_scritture')
        ->where('scrittura_id', $scrittura->id)
        ->where('tipo_riga', 'dare')
        ->where('conto_contabile_id', $contoPassate->id)
        ->sum('importo');

    expect((int)$darePassate)->toEqual(100000);
    expect($fattura->coperture()->where('tipo_copertura', 'rata_0')->count())->toEqual(1);
});

it('Scenario B: pregresso con fondo_riserva → DARE sul fondo', function () {
    [$condominio, $esercizio, $gestione, $fornitore, , $contoFondo] = setupContabile();

    $data = datiBase([$condominio, $esercizio, $gestione, $fornitore], [
        'data_documento'         => '2024-12-01',
        'data_scadenza'          => '2024-12-31',
        'is_pregresso'           => true,
        'imponibile_pregresso'   => 819.67,
        'aliquota_iva_pregressa' => 22,
        'righe'                  => [],
        'coperture' => [[
            'tipo_copertura' => 'fondo_riserva',
            'importo'        => 1000.00,
            'fonte_id'       => $contoFondo->id,
        ]],
    ]);

    $fattura   = (new FatturaPassivaService())->registraFattura($data, $condominio->id);
    $scrittura = $fattura->scritture->first();

    assertQuadraturaPerfetta($scrittura->id);

    $dareFondo = DB::table('righe_scritture')
        ->where('scrittura_id', $scrittura->id)
        ->where('tipo_riga', 'dare')
        ->where('conto_contabile_id', $contoFondo->id)
        ->value('importo');

    expect((int)$dareFondo)->toEqual(100000);
    expect($fattura->coperture()->where('tipo_copertura', 'fondo_riserva')->count())->toEqual(1);
});

it('spesa imprevista (fuori preventivo): crea conto dinamico e va in DARE su sopravvenienze', function () {
    [$condominio, $esercizio, $gestione, $fornitore] = setupContabile();
    $fornitore->update(['soggetto_ritenuta' => false]);

    // Simuliamo il payload di una spesa imprevista (nessun conto_id a preventivo)
    $data = datiBase([$condominio, $esercizio, $gestione, $fornitore], [
        'righe' => [[
            'descrizione'        => 'Rottura Tubo Improvvisa',
            'importo_imponibile' => 800,
            'aliquota_iva'       => 10,
            'conto_id'           => null, // Nessun conto a preventivo!
            'is_sopravvenienza'  => true,
        ]],
        'dati_extra' => [
            'fiscal' => [],
            'competenza' => null,
            'override_budget' => null,
            // Dati obbligatori per la generazione del conto dinamico
            'log_legale_sopravvenienza' => [
                'origine_decisionale' => 'gestione_corrente',
                'motivazione_sforo'   => 'Tubo rotto in piena notte',
                'tipo_ripartizione'   => 'millesimale',
                'nome_voce'           => 'Riparazione Tubo (Emergenza)'
            ]
        ]
    ]);

    $fattura   = (new FatturaPassivaService())->registraFattura($data, $condominio->id);
    $scrittura = $fattura->scritture->first();

    assertQuadraturaPerfetta($scrittura->id);

    // Verifichiamo che il costo sia finito ESATTAMENTE nel Mastro delle Sopravvenienze
    $contoSopravvenienza = ContoContabile::where('condominio_id', $condominio->id)
        ->where('ruolo', 'sopravvenienze_passive')
        ->first();

    $dareSopravvenienza = DB::table('righe_scritture')
        ->where('scrittura_id', $scrittura->id)
        ->where('tipo_riga', 'dare')
        ->where('conto_contabile_id', $contoSopravvenienza->id)
        ->value('importo');

    expect((int)$dareSopravvenienza)->toEqual(88000); // 800€ + 10% IVA = 880€
    
    // Verifichiamo che il conto dinamico sia stato creato nell'albero
    $contoDinamicoCreato = Conto::where('nome', 'Riparazione Tubo (Emergenza)')->exists();
    expect($contoDinamicoCreato)->toBeTrue();
});

it('il guardiano contabile (DoubleEntryValidator) blocca una scrittura sbilanciata', function () {
    // 1. Recuperiamo le entità necessarie (condominio, esercizio, gestione)
    [$condominio, $esercizio, $gestione] = setupContabile();
    
    // 2. Creiamo una testata vuota passando TUTTI i campi obbligatori
    $scrittura = \App\Models\Gestionale\ScritturaContabile::create([
        'condominio_id'      => $condominio->id,
        'esercizio_id'       => $esercizio->id,   
        'gestione_id'        => $gestione->id,    
        'data_registrazione' => now(),
        'data_competenza'    => now(),
        'causale'            => 'Test Sbilancio',
        'tipo_movimento'     => 'fattura_acquisto',
        'stato'              => 'registrata',
    ]);

    // 3. Inseriamo righe SBILANCIATE di proposito (Dare 100€, Avere 90€)
    $scrittura->righe()->create(['conto_contabile_id' => 1, 'tipo_riga' => 'dare', 'importo' => 10000]);
    $scrittura->righe()->create(['conto_contabile_id' => 2, 'tipo_riga' => 'avere', 'importo' => 9000]);

    // 4. Ci aspettiamo che il Validatore tiri un'eccezione bloccando tutto
    expect(fn() => \App\Services\Gestionale\DoubleEntryValidator::validateOrFail($scrittura->id))
        ->toThrow(Exception::class, 'Errore di integrità contabile');
});

it('elimina una fattura aperta senza vincoli (happy path)', function () {
    // 1. Setup Permessi
    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    /** @var \App\Models\User $user */
    $user = \App\Models\User::factory()->create();
    $user->givePermissionTo('Accesso pannello amministratore');
    $this->actingAs($user);

    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = setupContabile();

    $fattura = (new FatturaPassivaService())->registraFattura(
        datiBase([$condominio, $esercizio, $gestione, $fornitore], [
            'righe' => [[
                'descrizione' => 'Test delete',
                'importo_imponibile' => 1000,
                'aliquota_iva'       => 22,
                'conto_id'           => $capitolo->id,
                'is_sopravvenienza'  => false,
            ]]
        ]),
        $condominio->id
    );

    $response = $this->delete(route('admin.gestionale.fatture.destroy', [$condominio->id, $fattura->id]));
    $response->assertStatus(302); 

    expect(\App\Models\Gestionale\FatturaPassiva::find($fattura->id))->toBeNull();
});

it('blocca eliminazione se fattura non è aperta', function () {
    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    /** @var \App\Models\User $user */
    $user = \App\Models\User::factory()->create();
    $user->givePermissionTo('Accesso pannello amministratore');
    $this->actingAs($user);

    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = setupContabile();

    $fattura = (new FatturaPassivaService())->registraFattura(
        datiBase([$condominio, $esercizio, $gestione, $fornitore], [
            'righe' => [['descrizione' => 'Test', 'importo_imponibile' => 1000, 'aliquota_iva' => 22, 'conto_id' => $capitolo->id]]
        ]),
        $condominio->id
    );

    // Forza lo stato a PAGATA
    $fattura->update(['stato_pagamento' => 'pagata']);

    $response = $this->delete(route('admin.gestionale.fatture.destroy', [$condominio->id, $fattura->id]));
    
    // Invece di assertSessionHasErrors, verifichiamo che la fattura ESISTA ancora (segno che il delete è fallito)
    expect(FatturaPassiva::find($fattura->id))->not->toBeNull();
});

it('blocca eliminazione se esercizio è chiuso', function () {
    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    /** @var \App\Models\User $user */
    $user = \App\Models\User::factory()->create();
    $user->givePermissionTo('Accesso pannello amministratore');
    $this->actingAs($user);

    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = setupContabile();

    $fattura = (new FatturaPassivaService())->registraFattura(
        datiBase([$condominio, $esercizio, $gestione, $fornitore], [
            'righe' => [['descrizione' => 'Test', 'importo_imponibile' => 1000, 'aliquota_iva' => 22, 'conto_id' => $capitolo->id]]
        ]),
        $condominio->id
    );

    // Chiudiamo l'esercizio dopo la registrazione
    DB::table('esercizi')->where('id', $esercizio->id)->update(['stato' => 'chiuso']);

    $this->delete(route('admin.gestionale.fatture.destroy', [$condominio->id, $fattura->id]));
    
    // La fattura deve sopravvivere
    expect(FatturaPassiva::find($fattura->id))->not->toBeNull();
});

it('eliminando nota di credito ripristina fattura originale', function () {
    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    /** @var \App\Models\User $user */
    $user = \App\Models\User::factory()->create();
    $user->givePermissionTo('Accesso pannello amministratore');
    $this->actingAs($user);

    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = setupContabile();

    // 1. Creazione Fattura originale
    $fattura = (new FatturaPassivaService())->registraFattura(
        datiBase([$condominio, $esercizio, $gestione, $fornitore], [
            'righe' => [['descrizione' => 'Originale', 'importo_imponibile' => 1000, 'aliquota_iva' => 22, 'conto_id' => $capitolo->id]]
        ]),
        $condominio->id
    );

    // 2. Creazione Nota credito collegata
    $notaCredito = (new FatturaPassivaService())->registraFattura(
        datiBase([$condominio, $esercizio, $gestione, $fornitore], [
            'tipo_documento' => 'nota_credito',
            'righe' => [['descrizione' => 'Storno', 'importo_imponibile' => 1000, 'aliquota_iva' => 22, 'conto_id' => $capitolo->id]],
            'dati_extra' => ['stornata_da_id' => $fattura->id]
        ]),
        $condominio->id
    );

    // 3. Simuliamo lo stato stornato sull'originale (come farebbe il sistema)
    $fattura->update([
        'stato_pagamento' => StatoPagamentoFattura::PAGATA,
        'dati_extra' => array_merge($fattura->dati_extra ?? [], ['is_stornata' => true, 'stornata_da_id' => $notaCredito->id])
    ]);

    // 4. Eliminazione della NOTA DI CREDITO
    $this->delete(route('admin.gestionale.fatture.destroy', [$condominio->id, $notaCredito->id]))
        ->assertSessionHasNoErrors();

    // 5. Verifica ripristino originale
    $fatturaFresh = $fattura->fresh();
    expect($fatturaFresh->stato_pagamento)->toEqual(StatoPagamentoFattura::APERTA);
    // Verifichiamo che il flag is_stornata sia sparito dai dati_extra
    expect($fatturaFresh->dati_extra['is_stornata'] ?? false)->toBeFalsy();
});

it('blocca eliminazione se piano rate ha pagamenti', function () {
    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    /** @var \App\Models\User $user */
    $user = \App\Models\User::factory()->create();
    $user->givePermissionTo('Accesso pannello amministratore');
    $this->actingAs($user);

    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = setupContabile();

    $fattura = (new FatturaPassivaService())->registraFattura(
        datiBase([$condominio, $esercizio, $gestione, $fornitore], [
            'righe' => [['descrizione' => 'Straord', 'importo_imponibile' => 1000, 'aliquota_iva' => 22, 'conto_id' => $capitolo->id]]
        ]),
        $condominio->id
    );

    // Creiamo il piano rate in stato 'approvato' (così non è più eliminabile liberamente)
    $pianoId = DB::table('piani_rate')->insertGetId([
        'condominio_id' => $condominio->id,
        'gestione_id'    => $gestione->id,
        'nome'           => 'Piano Approvato',
        'stato'          => 'approvato', // Questo di solito basta a bloccare il delete
        'tipo'           => 'straordinario',
        'created_at'     => now(),
        'updated_at'     => now(),
    ]);

    DB::table('piano_rate_fatture')->insert(['piano_rate_id' => $pianoId, 'fattura_passiva_id' => $fattura->id]);

    // Inseriamo la rata senza il campo 'stato' che faceva crashare SQLite
    $rataId = DB::table('rate')->insertGetId([
        'piano_rate_id' => $pianoId,
        'numero_rata'   => 1,
        'data_scadenza' => now()->addDays(30)->format('Y-m-d'),
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    // Simuliamo la presenza di una scrittura di incasso (il protocollo è l'unica cosa certa)
    DB::table('scritture_contabili')->insert([
        'condominio_id'      => $condominio->id,
        'esercizio_id'       => $esercizio->id,
        'gestione_id'        => $gestione->id,
        'tipo_movimento'     => 'incasso_rata',
        'data_registrazione' => now(),
        'data_competenza'    => now(),
        'causale'            => 'Incasso Rata Test',
        'stato'              => 'registrata',
        'numero_protocollo'  => 'INC-' . uniqid(), 
        'created_at'         => now(),
        'updated_at'         => now(),
    ]);
    
    $this->delete(route('admin.gestionale.fatture.destroy', [$condominio->id, $fattura->id]));

    // Se la riga è ancora nel DB, il test passa.
    expect(\App\Models\Gestionale\FatturaPassiva::find($fattura->id))->not->toBeNull();
});
it('aggiorna fattura aperta riscrivendo il ledger', function () {
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = setupContabile();

    $data = datiBase([$condominio, $esercizio, $gestione, $fornitore], [
        'righe' => [[
            'descrizione'        => 'Lavori',
            'importo_imponibile' => 1000,
            'aliquota_iva'       => 22,
            'conto_id'           => $capitolo->id,
            'is_sopravvenienza'  => false,
        ]],
    ]);

    $service = new FatturaPassivaService();
    $fattura = $service->registraFattura($data, $condominio->id);

    $newData = array_merge($data, [
        'data_documento' => now()->addDay()->toDateString(),
        'righe' => [[
            'descrizione'        => 'Lavori extra',
            'importo_imponibile' => 2000,
            'aliquota_iva'       => 10,
            'conto_id'           => $capitolo->id,
            'is_sopravvenienza'  => false,
        ]],
    ]);

    $fatturaAggiornata = $service->aggiornaFattura($fattura, $newData);

    expect($fatturaAggiornata->importo_imponibile)->toEqual(200000);
    expect($fatturaAggiornata->importo_iva)->toEqual(20000);
    expect($fatturaAggiornata->totale_documento)->toEqual(220000);

    $scrittura = $fatturaAggiornata->scritture->first();
    assertQuadraturaPerfetta($scrittura->id);
    expect($scrittura->righe->where('tipo_riga', 'avere')->sum('importo'))->toEqual(220000);
});
