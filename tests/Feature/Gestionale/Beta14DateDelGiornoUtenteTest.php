<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * 1.12.0-beta.14 (Code 494 e 547) — il giorno e il mese sono quelli di Roma, non quelli dell'orologio del server (UTC).
 *
 * Fra le 00:00 e le 01:00 di Roma d'inverno (le 02:00 d'estate) `now()` dice ancora il giorno prima: all'1 del mese o dell'anno una card contava il
 * mese passato, l'esercizio «dell'anno in corso» nasceva dell'anno scorso, e una scrittura contabile prendeva la data di ieri.
 *
 * Le card dei due elenchi (incassi, pagamenti) sono provate nei loro file (`IncassoRateControllerTest`, `PagamentoFornitoreControllerTest`); qui
 * l'archivio dei documenti, l'esercizio creato per l'anno, e la guardia che nessun `data_registrazione` si scriva più con `now()` in `app/`.
 *
 * Cosa NON copre: i due punti F24 (`StornaVersamentoF24Action`, `DelegaF24Controller`) e `AccessoriAgliEredi`, che sono di altri pacchetti e per ora
 * sono nell'elenco delle eccezioni della guardia.
 */

use App\Models\Condominio;
use App\Models\Documento;
use App\Models\User;
use App\Services\CondominioService;
use App\Services\DocumentoService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

it('l\'esercizio dell\'anno in corso, creato alle 00:30 di Roma del 01/01/2027, è il 2027', function () {
    $condominio = Condominio::factory()->create();
    $this->travelTo(Carbon::parse('2027-01-01 00:30', 'Europe/Rome'));

    $esercizio = app(CondominioService::class)->createEsercizioForCondominio($condominio);

    expect($esercizio->data_inizio->toDateString())->toBe('2027-01-01')
        ->and($esercizio->data_fine->toDateString())->toBe('2027-12-31')
        ->and($esercizio->nome)->toBe('Esercizio anno 2027');
});

it('la card «caricati nel mese» dell\'archivio parte dal primo del mese di Roma: un documento di fine novembre non è di dicembre', function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $user = User::factory()->create();
    $user->assignRole($ruolo);

    // 22:30 UTC del 30/11 = 23:30 di Roma del 30/11: novembre.
    $this->travelTo(Carbon::parse('2026-11-30 22:30', 'UTC'));
    Documento::create(['name' => 'Di novembre', 'path' => 'documenti/n.pdf', 'mime_type' => 'application/pdf', 'file_size' => 10, 'is_published' => true, 'is_approved' => true, 'created_by' => $user->id]);
    // 23:30 UTC del 30/11 = 00:30 di Roma del 01/12: dicembre.
    $this->travelTo(Carbon::parse('2026-11-30 23:30', 'UTC'));
    Documento::create(['name' => 'Di dicembre', 'path' => 'documenti/d.pdf', 'mime_type' => 'application/pdf', 'file_size' => 10, 'is_published' => true, 'is_approved' => true, 'created_by' => $user->id]);

    $this->actingAs($user);
    $stats = app(DocumentoService::class)->getDocumentiStats();

    expect($stats->total_documents)->toBe(2)
        ->and($stats->uploaded_this_month)->toBe(1);
});
