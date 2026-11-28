<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Modificare un documento di un fornitore non cambia l'autore né l'approvazione (Coda 396)
 *
 * `created_by` e `is_approved` si scrivono alla creazione; l'approvazione cambia solo dall'interruttore (`FornitoreDocumentoController::approvazione`).
 * La modifica scrive il nome, la descrizione, la pubblicazione e il file.
 */

use App\Enums\Role;
use App\Models\Fornitore;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();
    Storage::fake('local');

    $this->giulia = User::factory()->create(['email_verified_at' => now()]);
    $this->giulia->assignRole(Role::COLLABORATORE->value);
    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    $this->admin->assignRole(Role::AMMINISTRATORE->value);

    $this->fornitore = Fornitore::create([
        'ragione_sociale' => 'Idraulica Prova S.r.l.', 'stato' => 'attivo', 'giorni_scadenza' => 30,
        'modalita_pagamento_default' => 'bonifico', 'soggetto_ritenuta' => false, 'perc_imponibile_ritenuta' => 100,
    ]);
    Storage::disk('local')->put('documenti/durc.pdf', '%PDF-1.4 durc');
    $this->documento = $this->fornitore->documenti()->create([
        'name' => 'DURC 2026', 'description' => 'Descrizione', 'path' => 'documenti/durc.pdf', 'mime_type' => 'application/pdf', 'file_size' => 20,
        'created_by' => $this->giulia->id, 'is_published' => false, 'is_approved' => false,
    ]);
});

it('l\'amministratore che corregge il nome non diventa l\'autore e non approva', function () {
    $this->actingAs($this->admin)->put(route('admin.fornitori.documenti.update', [$this->fornitore, $this->documento]), [
        'name' => 'DURC 2026 corretto', 'description' => 'Descrizione', 'is_published' => false, 'is_approved' => true, 'created_by' => $this->admin->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $ora = $this->documento->fresh();
    expect($ora->name)->toBe('DURC 2026 corretto')
        ->and((int) $ora->created_by)->toBe($this->giulia->id)
        ->and((bool) $ora->is_approved)->toBeFalse()
        ->and((bool) $ora->is_published)->toBeFalse();
});

it('chi non approva non toglie l\'approvazione correggendo il nome, e la pubblicazione che sceglie si scrive', function () {
    $this->documento->update(['is_approved' => true]);
    $sandra = User::factory()->create(['email_verified_at' => now()]);
    $sandra->assignRole(Role::UTENTE->value);
    $sandra->givePermissionTo(['Accesso pannello amministratore', 'Modifica anagrafiche', 'Visualizza anagrafiche']);

    $this->actingAs($sandra->fresh())->put(route('admin.fornitori.documenti.update', [$this->fornitore, $this->documento]), [
        'name' => 'DURC 2027', 'description' => 'Descrizione', 'is_published' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $ora = $this->documento->fresh();
    expect($ora->name)->toBe('DURC 2027')
        ->and((bool) $ora->is_approved)->toBeTrue()
        ->and((bool) $ora->is_published)->toBeTrue()
        ->and((int) $ora->created_by)->toBe($this->giulia->id);
});
