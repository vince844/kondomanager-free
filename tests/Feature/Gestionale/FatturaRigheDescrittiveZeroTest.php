<?php

use App\Models\Conto;
use App\Services\Gestionale\FatturaPassivaService;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

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

require_once __DIR__.'/FatturaLifecycleTest.php';

/**
 * Le righe puramente descrittive da € 0,00 che gli XML veri portano (Fase 1-bis, reperto 3).
 *
 * ⚠️ **Cinque degli undici file di collaudo ne hanno almeno una**: «PROTOCOLLO 10000-2025»,
 * «Riga ausiliaria contenente informazioni tecniche e aggiuntive del documento», e le righe di
 * intestazione che introducono le voci successive. Sono **contenuto del documento**, non spese:
 * buttarle perderebbe informazione, ma pretendere che l'amministratore assegni un capitolo di
 * spesa a un importo nullo è un attrito senza motivo — e la richiesta arriva con un messaggio
 * che parla di capitolo obbligatorio senza dire perché lo chieda per € 0,00.
 */
function setupPerRigheZero(): array
{
    return setupEcosistemaLifecycle();
}

it('registra una fattura con una riga descrittiva a zero senza capitolo di spesa', function () {
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = setupPerRigheZero();

    $dati = [
        'fornitore_id'       => $fornitore->id,
        'esercizio_id'       => $esercizio->id,
        'gestione_id'        => $gestione->id,
        'tipo_documento'     => 'fattura',
        'numero_documento'   => 'FT-RIGA-ZERO',
        'data_documento'     => now()->format('Y-m-d'),
        'data_scadenza'      => now()->addDays(15)->format('Y-m-d'),
        'modalita_pagamento' => 'bonifico',
        'applica_ritenuta'   => false,
        'dati_extra'         => ['fiscal' => [], 'competenza' => null, 'override_budget' => null],
        'righe' => [
            // La riga descrittiva: nessun capitolo, nessun immobile, importo nullo.
            ['descrizione' => 'PROTOCOLLO 10000-2025', 'importo_imponibile' => 0, 'aliquota_iva' => 0,
             'conto_id' => null, 'is_sopravvenienza' => false],
            ['descrizione' => 'Manutenzione impianto', 'importo_imponibile' => 1000, 'aliquota_iva' => 22,
             'conto_id' => $capitolo->id, 'is_sopravvenienza' => false],
        ],
    ];

    $fattura = (new FatturaPassivaService())->registraFattura($dati, $condominio->id);

    // ⚠️ La riga descrittiva **resta**: è contenuto del documento e non va persa.
    expect($fattura->righe()->count())->toBe(2);
    expect($fattura->righe()->where('descrizione', 'PROTOCOLLO 10000-2025')->exists())->toBeTrue();

    // ⚠️ Ma non produce nessuna riga di giornale: un DARE di € 0,00 non è una scrittura,
    // è rumore. Senza questa guardia il servizio finiva nel ramo «Integrità compromessa:
    // Impossibile allocare la riga» e rispondeva 500 con rollback.
    $righeGiornale = $fattura->scritture()->first()->righe()->where('tipo_riga', 'dare')->get();
    expect($righeGiornale)->toHaveCount(1)
        ->and((int) $righeGiornale->first()->importo)->toBe(122000);
});

it('una fattura con una riga descrittiva a zero resta modificabile', function () {
    // ⚠️ È l'avvertenza del critico avversariale, e senza la guardia in `aggiornaFattura`
    // sarebbe stata la conseguenza peggiore: correggere solo la validazione avrebbe reso
    // **non modificabile** ogni fattura importata con una riga descrittiva, perché ogni
    // salvataggio esplodeva su «impossibile allocare la riga».
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = setupPerRigheZero();

    $servizio = new FatturaPassivaService();
    $base = [
        'fornitore_id' => $fornitore->id,
        'esercizio_id' => $esercizio->id,
        'gestione_id' => $gestione->id,
        'tipo_documento' => 'fattura',
        'numero_documento' => 'FT-MODIFICABILE',
        'data_documento' => now()->format('Y-m-d'),
        'data_scadenza' => now()->addDays(15)->format('Y-m-d'),
        'modalita_pagamento' => 'bonifico',
        'applica_ritenuta' => false,
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => null],
        'righe' => [
            ['descrizione' => 'PROTOCOLLO 10000-2025', 'importo_imponibile' => 0, 'aliquota_iva' => 0,
             'conto_id' => null, 'is_sopravvenienza' => false],
            ['descrizione' => 'Manutenzione impianto', 'importo_imponibile' => 1000, 'aliquota_iva' => 22,
             'conto_id' => $capitolo->id, 'is_sopravvenienza' => false],
        ],
    ];

    $fattura = $servizio->registraFattura($base, $condominio->id);

    // Si corregge l'importo della riga vera, tenendo la descrittiva dov'è.
    $modificata = $base;
    $modificata['righe'][1]['importo_imponibile'] = 1200;

    $servizio->aggiornaFattura($fattura, $modificata);

    expect($fattura->fresh()->righe()->count())->toBe(2)
        ->and((int) $fattura->fresh()->righe()->where('descrizione', 'Manutenzione impianto')->first()->importo_imponibile)
        ->toBe(120000);
});

/**
 * ⚠️ **I due test qui sotto esistono perché la loro guardia NON era protetta.**
 * Verificato il 03/09/2026 togliendo `!$importoNullo` da entrambe le FormRequest:
 * **2.230 test restavano verdi**. Una guardia che nessun test fa fallire è una guardia che
 * fra sei mesi qualcuno «semplifica» in buona fede.
 *
 * Passano dalla ROTTA e non dal servizio: il rifiuto arrivava dalla validazione, ed è quello
 * che l'amministratore vedeva — «Il capitolo di spesa è obbligatorio» sotto una riga da € 0,00.
 */
function corpoFatturaConRigaZero(array $ctx, string $numero): array
{
    [, $esercizio, $gestione, $fornitore, $capitolo] = $ctx;

    return [
        'fornitore_id' => $fornitore->id,
        'esercizio_id' => $esercizio->id,
        'gestione_id' => $gestione->id,
        'tipo_documento' => 'fattura',
        'numero_documento' => $numero,
        'data_documento' => now()->format('Y-m-d'),
        'data_scadenza' => now()->addDays(15)->format('Y-m-d'),
        'modalita_pagamento' => 'bonifico',
        'stato_approvazione' => 'approvata',
        'righe' => [
            ['descrizione' => 'Riga ausiliaria contenente informazioni tecniche',
             'importo_imponibile' => 0, 'aliquota_iva' => 0, 'conto_id' => null, 'is_sopravvenienza' => false],
            ['descrizione' => 'Manutenzione impianto',
             'importo_imponibile' => 1000, 'aliquota_iva' => 22, 'conto_id' => $capitolo->id, 'is_sopravvenienza' => false],
        ],
    ];
}

it('registrando, la validazione non chiede un capitolo per una riga da zero euro', function () {
    $ctx = setupPerRigheZero();
    [$condominio] = $ctx;

    $risposta = $this->actingAs($this->user)->post(
        route('admin.gestionale.fatture.store', $condominio->id),
        corpoFatturaConRigaZero($ctx, 'FT-VALIDA-ZERO')
    );

    // ⚠️ L'asserto è **preciso**: nessun errore sulla riga a zero. Non «nessun errore»,
    // che dipenderebbe da tutti gli altri campi del payload e diventerebbe rosso ogni volta
    // che la FormRequest ne chiede uno nuovo — misurando una cosa diversa da quella in prova.
    $risposta->assertSessionDoesntHaveErrors(['righe.0.conto_id']);
});

it('modificando, la validazione non chiede un capitolo per una riga da zero euro', function () {
    // ⚠️ La guardia gemella chiede una cosa DIVERSA (esenta le righe con `immobile_id`, non
    // le sopravvenienze): sono due domande, e vanno protette da due test.
    $ctx = setupPerRigheZero();
    [$condominio] = $ctx;

    // La fattura di partenza si crea dal servizio: il gesto in prova è la MODIFICA, e
    // passare anche la creazione dalla rotta legherebbe questo test a ogni campo che la
    // FormRequest di registrazione chiederà in futuro.
    $servizio = new FatturaPassivaService();
    $fattura = $servizio->registraFattura(
        corpoFatturaConRigaZero($ctx, 'FT-MOD-ZERO') + [
            'applica_ritenuta' => false,
            'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => null],
        ],
        $condominio->id,
    );

    $corpo = corpoFatturaConRigaZero($ctx, 'FT-MOD-ZERO');
    $corpo['righe'][1]['importo_imponibile'] = 1200;

    $risposta = $this->actingAs($this->user)->put(
        route('admin.gestionale.fatture.update', [$condominio->id, $fattura->id]),
        $corpo
    );

    $risposta->assertSessionDoesntHaveErrors(['righe.0.conto_id']);
});
