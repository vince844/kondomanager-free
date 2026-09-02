<?php

/**
 * La guardia server-side aggiunta a `UpdateFatturaRequest::guardiaNumeroDocumentoCollidente()`
 * (decisione D4, 1.11.0-beta.13): fornitore e numero documento sono read-only a video in
 * Modifica, ma non erano protetti lato server. Questo file prova il contratto server, non
 * l'interfaccia — la stessa distinzione già fatta per `RicercaFattureSimiliTest.php`
 * (query pura) e `FetchFattureSimiliHttpTest.php` (endpoint dell'avviso non bloccante).
 *
 * Qui l'endpoint è un altro: `PUT admin.gestionale.fatture.update`, e il blocco è vero
 * blocco (errore in sessione), non un avviso.
 */

use App\Models\Esercizio;
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

/**
 * Il payload minimo valido per `admin.gestionale.fatture.update`, letto da
 * `FatturaPassivaControllerTest.php` (il salvataggio bloccato non deve restituire successo
 * silenzioso). Il numero documento è l'unica cosa che ogni test qui sotto fa variare.
 */
function payloadModifica($fattura, $gestione, $capitolo, string $numeroDocumento): array
{
    return [
        'gestione_id'         => $gestione->id,
        'numero_documento'    => $numeroDocumento,
        'data_documento'      => $fattura->data_documento->format('Y-m-d'),
        'data_scadenza'       => $fattura->data_scadenza->format('Y-m-d'),
        'modalita_pagamento'  => 'bonifico',
        'righe' => [[
            'descrizione'        => 'Servizio Test',
            'importo_imponibile' => 1000,
            'aliquota_iva'       => 22,
            'conto_id'           => $capitolo->id,
        ]],
    ];
}

/**
 * ⚠️ **`registraFatturaServiceTest()` ha un difetto di destrutturazione noto** (già
 * aggirato in `RicercaFattureSimiliTest.php`, beta.13): internamente legge
 * `[$condominio, $esercizio, $gestione, $fornitore, , $capitolo] = $ctx`, che salta
 * l'indice 4 (il vero capitolo, restituito da `setupContabile()`) e assegna `$capitolo`
 * all'indice 5 (in realtà il conto contabile del fondo). Senza `righe` esplicite, il
 * `conto_id` di default è quindi sbagliato e la insert fallisce per vincolo di chiave
 * esterna. Il giro è passare sempre `righe` con il capitolo vero, preso dalla
 * destrutturazione esterna (indice 4).
 */
function registraFattura($ctx, $capitolo, string $numeroDocumento, ?string $dataDocumento = null): \App\Models\Gestionale\FatturaPassiva
{
    return registraFatturaServiceTest($ctx, array_filter([
        'numero_documento' => $numeroDocumento,
        'data_documento'   => $dataDocumento,
        'righe' => [[
            'descrizione'        => 'Servizio Test',
            'importo_imponibile' => 1000,
            'aliquota_iva'       => 22,
            'conto_id'           => $capitolo->id,
            'is_sopravvenienza'  => false,
        ]],
    ], fn ($v) => $v !== null));
}

test('un numero documento che collide con un\'altra fattura dello stesso fornitore ed esercizio viene bloccato', function () {
    $ctx = setupContabile();
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = $ctx;

    // ⚠️ **Le due fatture devono avere date DIVERSE, o il test è verde per il motivo sbagliato.**
    // Con la stessa data (`datiBase()` mette `now()` a entrambe) lo scenario viola anche
    // `unique_ft_condominio`, e il catch D2 di `FatturaPassivaController` scrive sotto la
    // **stessa** chiave `numero_documento`: `assertSessionHasErrors` non distingue le due
    // sorgenti, e la guardia D4 poteva essere cancellata per intero lasciando tutto verde.
    // Provato per mutazione dalla revisione avversariale della beta.13. Con date diverse
    // l'indice unico non c'entra piu' e a bloccare puo' essere solo la guardia.
    $fatturaA = registraFattura($ctx, $capitolo, 'FT-2026-COLLIDE-A', '2026-05-10');
    $fatturaB = registraFattura($ctx, $capitolo, 'FT-2026-COLLIDE-B', '2026-06-20');

    $response = $this->actingAs($this->user)
        ->put(
            route('admin.gestionale.fatture.update', [$condominio, $fatturaB]),
            payloadModifica($fatturaB, $gestione, $capitolo, $fatturaA->numero_documento)
        );

    $response->assertSessionHasErrors(['numero_documento']);
});

test('modificare una fattura che ha una gemella con lo stesso numero e data diversa NON viene bloccata', function () {
    $ctx = setupContabile();
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = $ctx;

    // Lo stato che D4 permette DELIBERATAMENTE: l'avviso duplicati non blocca mai, quindi due
    // fatture dello stesso fornitore e dello stesso esercizio con lo stesso numero e date
    // diverse si registrano entrambe (`unique_ft_condominio` include `data_documento`).
    $gemellaA = registraFattura($ctx, $capitolo, 'FT-GEMELLA', '2026-05-10');
    $gemellaB = registraFattura($ctx, $capitolo, 'FT-GEMELLA', '2026-06-20');

    // Si modifica SOLO la scadenza. Il numero non si tocca: a video è un <span> read-only,
    // quindi il modulo lo rispedisce sempre identico.
    $payload = payloadModifica($gemellaB, $gestione, $capitolo, $gemellaB->numero_documento);
    $payload['data_scadenza'] = '2026-07-31';

    $response = $this->actingAs($this->user)
        ->put(route('admin.gestionale.fatture.update', [$condominio, $gemellaB]), $payload);

    $response->assertSessionDoesntHaveErrors(['numero_documento']);
    $response->assertSessionDoesntHaveErrors(['error']);

    // E il salvataggio è andato davvero a buon fine, non è solo "senza errori".
    expect($gemellaB->fresh()->data_scadenza->format('Y-m-d'))->toBe('2026-07-31');

    // Simmetrico: anche la gemella A resta modificabile.
    $payloadA = payloadModifica($gemellaA, $gestione, $capitolo, $gemellaA->numero_documento);
    $payloadA['data_scadenza'] = '2026-06-30';

    $this->actingAs($this->user)
        ->put(route('admin.gestionale.fatture.update', [$condominio, $gemellaA]), $payloadA)
        ->assertSessionDoesntHaveErrors(['numero_documento']);
});

test('salvare il numero documento invariato non scatta la guardia (autoesclusione)', function () {
    $ctx = setupContabile();
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = $ctx;

    $fattura = registraFattura($ctx, $capitolo, 'FT-2026-INVARIATO');

    $response = $this->actingAs($this->user)
        ->put(
            route('admin.gestionale.fatture.update', [$condominio, $fattura]),
            payloadModifica($fattura, $gestione, $capitolo, $fattura->numero_documento)
        );

    $response->assertSessionDoesntHaveErrors(['numero_documento']);
});

test('un numero documento uguale ma in un esercizio diverso non viene bloccato', function () {
    $ctx = setupContabile();
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = $ctx;

    $esercizioAltro = Esercizio::factory()->create([
        'condominio_id' => $condominio->id,
        'stato'         => 'aperto',
    ]);

    $ctxAltro = $ctx;
    $ctxAltro[1] = $esercizioAltro;

    // ⚠️ **La data dev'essere chiaramente diversa, non solo l'esercizio.** Con la stessa data
    // (entrambe create con `now()` nello stesso test) questo scenario collide comunque contro
    // `unique_ft_condominio`, che è scoped al condominio e non all'esercizio (decisione D1) — e
    // quel secondo blocco, reale, mascherava quello che il test vuole isolare: se la sola
    // guardia D4-forte (che invece guarda l'esercizio) lascia passare o no. Trovato perché
    // questo stesso test, prima di questa riga, era verde per il motivo sbagliato: la
    // collisione sull'indice c'era, ma finiva sotto la chiave `error`, che l'asserzione non
    // controllava — la decisione D2, appena costruita, l'ha spostata sotto `numero_documento`
    // e il test è diventato rosso per davvero.
    $fatturaEsercizioAltro = registraFattura($ctxAltro, $capitolo, 'FT-2026-ALTRO-ESERCIZIO', '2025-01-15');
    $fattura = registraFattura($ctx, $capitolo, 'FT-2026-QUESTO-ESERCIZIO');

    $response = $this->actingAs($this->user)
        ->put(
            route('admin.gestionale.fatture.update', [$condominio, $fattura]),
            payloadModifica($fattura, $gestione, $capitolo, $fatturaEsercizioAltro->numero_documento)
        );

    $response->assertSessionDoesntHaveErrors(['numero_documento']);
    $response->assertSessionDoesntHaveErrors(['error']);
});
