<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\Comunicazione;
use App\Models\Condominio;
use App\Models\RegistroModifica;
use App\Models\Segnalazione;
use App\Models\User;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * 1.12.0-beta.13 (Coda 679, gesto 7 e §13 (3) del progetto): comunicazioni e segnalazioni salvate dalla pagina. I dati cambiati con le parole della
 * pagina (il tratto) e i legami fatti con `sync`/`attach` (condomini e destinatari) come un campo della scheda madre, con le persone fra i soggetti (P9).
 *
 * Che cosa non copre: le pagine del portale dei condòmini e i documenti (stesso aiutante dei legami, altre rotte); le notifiche, che restano dei loro test.
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();
    $this->andrea = User::factory()->create(['name' => 'Andrea Costa', 'email_verified_at' => now()]);
    $this->andrea->assignRole(Role::AMMINISTRATORE->value);
    $this->roma = Condominio::factory()->create(['nome' => 'Via Roma 12']);
    $this->italia = Condominio::factory()->create(['nome' => 'Corso Italia 3']);
    $this->ida = Anagrafica::query()->create(['nome' => 'Ida Romano', 'indirizzo' => 'Via Roma 12']);
    $this->ida->condomini()->attach($this->roma->id);
});

function acsdDopo(int $prima)
{
    return RegistroModifica::query()->where('id', '>', $prima)->orderBy('id')->get();
}

it('la comunicazione nuova, programmata, con i condomini e i destinatari: una riga sola «Creata», nel registro di Via Roma 12 (decisione 554)', function () {
    $this->travelTo('2026-11-19 10:00:00');
    $prima = RegistroModifica::query()->max('id') ?? 0;

    $this->actingAs($this->andrea)->post(route('admin.comunicazioni.store'), [
        'subject' => 'Lavori al portone', 'description' => 'Il portone resta aperto martedì.', 'priority' => 'media', 'can_comment' => true,
        'is_featured' => false, 'is_published' => false, 'is_private' => true, 'condomini_ids' => [$this->roma->id], 'anagrafiche' => [$this->ida->id],
        'data_programmata' => '2026-12-09T08:00',
    ])->assertSessionHasNoErrors();

    $righe = acsdDopo($prima)->where('oggetto_tipo', 'comunicazione')->values();
    $campi = collect($righe[0]->campi ?? [])->keyBy('chiave');
    expect($righe->pluck('azione')->all())->toBe(['creata'])
        ->and($righe[0]->oggetto_nome)->toBe('Comunicazione «Lavori al portone»')
        ->and($righe[0]->condomini())->toBe([$this->roma->id => 'Via Roma 12'])
        ->and($righe[0]->soggetti())->toContain(['anagrafica', $this->ida->id])
        ->and($campi['anagrafiche'])->toMatchArray(['etichetta' => 'Destinatari', 'prima' => null, 'dopo' => 'Ida Romano'])
        ->and($campi['programmata_per'])->toMatchArray(['prima' => null, 'dopo' => '09/12/2026 08:00'])
        ->and($campi->has('condomini'))->toBeFalse()
        ->and($righe[0]->riepilogo)->toBe('Con ' . $campi->count() . ' dati.');
});

it('il documento nuovo con le categorie e il condominio: una riga sola «Creata», con le categorie fra i dati (decisione 554)', function () {
    \Illuminate\Support\Facades\Storage::fake('local');
    $categoria = \App\Models\CategoriaDocumento::query()->create(['name' => 'Verbali', 'description' => 'Assemblee']);
    $prima = RegistroModifica::query()->max('id') ?? 0;

    $this->actingAs($this->andrea)->post(route('admin.documenti.store'), [
        'name' => 'Verbale del 3 dicembre', 'description' => 'Assemblea ordinaria', 'categorie' => [$categoria->id], 'is_published' => false,
        'condomini_ids' => [$this->roma->id], 'anagrafiche' => [],
        'file' => \Illuminate\Http\UploadedFile::fake()->create('verbale.pdf', 12, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    $righe = acsdDopo($prima)->where('oggetto_tipo', 'documento')->values();
    expect($righe->pluck('azione')->all())->toBe(['creata'])
        ->and($righe[0]->condomini())->toBe([$this->roma->id => 'Via Roma 12'])
        ->and(collect($righe[0]->campi)->firstWhere('chiave', 'categorie'))->toMatchArray(['etichetta' => 'Categorie', 'prima' => null, 'dopo' => 'Verbali']);
});

it('la comunicazione salvata dalla pagina: l\'oggetto cambiato e i condomini, con le parole della pagina', function () {
    $this->actingAs($this->andrea)->post(route('admin.comunicazioni.store'), [
        'subject' => 'Chiusura dell\'acqua', 'description' => 'Martedì dalle 9 alle 12 l\'acqua resta chiusa.', 'priority' => 'media',
        'can_comment' => true, 'is_featured' => false, 'is_published' => false, 'condomini_ids' => [$this->roma->id],
    ])->assertSessionHasNoErrors();
    $comunicazione = Comunicazione::query()->latest('id')->firstOrFail();
    $prima = RegistroModifica::query()->max('id');

    $this->actingAs($this->andrea)->put(route('admin.comunicazioni.update', $comunicazione), [
        'subject' => 'Lavori al portone', 'description' => 'Martedì dalle 9 alle 12 l\'acqua resta chiusa.', 'priority' => 'alta',
        'can_comment' => true, 'is_featured' => false, 'is_published' => false, 'condomini_ids' => [$this->roma->id, $this->italia->id],
    ])->assertSessionHasNoErrors();

    $righe = acsdDopo($prima);
    $dati = $righe->firstWhere(fn ($r) => collect($r->campi)->contains('chiave', 'subject'));
    expect(collect($dati->campi)->map(fn ($c) => $c['etichetta'] . ': ' . $c['prima'] . ' → ' . $c['dopo'])->sort()->values()->all())
        ->toBe(['Oggetto comunicazione: Chiusura dell\'acqua → Lavori al portone', 'Priorità comunicazione: Media → Alta'])
        ->and($dati->oggetto_nome)->toBe('Comunicazione «Lavori al portone»')
        ->and($righe->firstWhere(fn ($r) => ($r->campi[0]['chiave'] ?? null) === 'condomini')->campi[0])
        ->toMatchArray(['etichetta' => 'Condomini', 'prima' => 'Via Roma 12', 'dopo' => 'Via Roma 12, Corso Italia 3']);
});

it('la segnalazione: lo stato cambiato e le persone collegate, che entrano fra i soggetti', function () {
    $segnalazione = Segnalazione::query()->create(['subject' => 'Ascensore fermo', 'description' => 'Bloccato al secondo piano.', 'priority' => 'alta',
        'stato' => 'aperta', 'can_comment' => true, 'is_featured' => false, 'is_published' => true, 'is_approved' => true,
        'created_by' => $this->andrea->id, 'condominio_id' => $this->roma->id]);
    $prima = RegistroModifica::query()->max('id');

    $this->actingAs($this->andrea)->put(route('admin.segnalazioni.update', ['segnalazione' => $segnalazione->id]), [
        'subject' => 'Ascensore fermo', 'description' => 'Bloccato al secondo piano.', 'priority' => 'alta', 'stato' => 'in lavorazione',
        'can_comment' => true, 'is_featured' => false, 'is_published' => true, 'created_by' => $this->andrea->id, 'is_approved' => true,
        'condominio_id' => $this->roma->id, 'anagrafiche' => [$this->ida->id], 'avvisa_destinatari' => false,
    ])->assertSessionHasNoErrors();

    $righe = acsdDopo($prima);
    $stato = $righe->firstWhere(fn ($r) => collect($r->campi)->contains('chiave', 'stato'));
    $persone = $righe->firstWhere(fn ($r) => ($r->campi[0]['chiave'] ?? null) === 'anagrafiche');
    expect(collect($stato->campi)->firstWhere('chiave', 'stato'))->toMatchArray(['etichetta' => 'Stato segnalazione', 'prima' => 'Aperta',
        'dopo' => 'In lavorazione'])
        ->and($persone->campi[0])->toMatchArray(['etichetta' => 'Persone', 'prima' => null, 'dopo' => 'Ida Romano'])
        ->and($persone->soggetti())->toContain(['anagrafica', $this->ida->id]);
});
