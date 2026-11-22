<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\ContoCorrente;
use App\Models\Fornitore;
use App\Models\RegistroModifica;
use App\Models\User;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * 1.12.0-beta.13 (Coda 679, §13 (3) del progetto): l'area «persone», con il gesto vero dalla rotta, come la pagina. La scheda della persona salvata
 * scrive i dati cambiati con le parole della pagina e il legame con i condomini (gesto 7); il fornitore scrive i suoi dati, i rappresentanti e il conto
 * tolto con il modello (prima era una cancellazione su query, che il registro non vedeva).
 *
 * Che cosa non copre: le pagine del portale dei condòmini (scrivono con lo stesso aiutante dei legami); il fornitore creato dalla finestra della fattura.
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();
    $this->marco = User::factory()->create(['name' => 'Marco Galli', 'email_verified_at' => now()]);
    $this->marco->assignRole(Role::COLLABORATORE->value);
    $this->roma = Condominio::factory()->create(['nome' => 'Via Roma 12']);
    $this->italia = Condominio::factory()->create(['nome' => 'Corso Italia 3']);
    $this->ida = Anagrafica::query()->create(['nome' => 'Ida Romano', 'indirizzo' => 'Via Roma 12', 'email' => 'ida.romano@libero.it',
        'telefono' => '333 120 4455']);
    $this->ida->condomini()->attach($this->roma->id);
});

function apModulo(Anagrafica $a, array $modifiche): array
{
    return array_merge([
        'nome' => $a->nome, 'indirizzo' => $a->indirizzo, 'email' => $a->email, 'telefono' => $a->telefono, 'cellulare' => $a->cellulare,
        'pec' => $a->pec, 'email_secondaria' => $a->email_secondaria, 'codice_fiscale' => $a->codice_fiscale, 'note' => $a->note,
        'condomini' => $a->condomini()->pluck('condomini.id')->all(),
    ], $modifiche);
}

it('la scheda della persona salvata dalla pagina scrive l\'email cambiata e il condominio aggiunto, con chi l\'ha fatto', function () {
    $prima = RegistroModifica::query()->max('id') ?? 0;

    $this->actingAs($this->marco)->put(route('admin.anagrafiche.update', $this->ida), apModulo($this->ida, [
        'email' => 'ida.romano@gmail.com', 'condomini' => [$this->roma->id, $this->italia->id],
    ]))->assertSessionHasNoErrors();

    $righe = RegistroModifica::query()->where('id', '>', $prima)->orderBy('id')->get();
    expect($righe)->toHaveCount(2)
        ->and($righe[0]->autore_nome)->toBe('Marco Galli')
        ->and($righe[0]->autore_ruolo)->toBe('collaboratore')
        ->and($righe[0]->oggetto_nome)->toBe('Anagrafica di Ida Romano')
        ->and($righe[0]->campi)->toHaveCount(1)
        ->and($righe[0]->campi[0])->toMatchArray(['etichetta' => 'Indirizzo email primario', 'prima' => 'ida.romano@libero.it', 'dopo' => 'ida.romano@gmail.com'])
        ->and($righe[1]->campi[0])->toMatchArray(['etichetta' => 'Condomini', 'prima' => 'Via Roma 12', 'dopo' => 'Via Roma 12, Corso Italia 3'])
        ->and(array_keys($righe[1]->condomini()))->toBe([$this->roma->id, $this->italia->id])
        ->and(ContestoDelRegistro::scrittureFuoriGesto())->toBe([]);
});

it('la persona nuova dalla pagina: una riga sola, «Creata, con 2 dati», con il condominio a cui è collegata (decisione 554)', function () {
    $prima = RegistroModifica::query()->max('id') ?? 0;

    $this->actingAs($this->marco)->post(route('admin.anagrafiche.store'), [
        'nome' => 'Carlo Fontana', 'indirizzo' => 'Via Po 3', 'condomini' => [$this->roma->id],
    ])->assertSessionHasNoErrors();

    $righe = RegistroModifica::query()->where('id', '>', $prima)->orderBy('id')->get();
    expect($righe->pluck('azione')->all())->toBe(['creata'])
        ->and($righe[0]->oggetto_nome)->toBe('Anagrafica di Carlo Fontana')
        ->and($righe[0]->riepilogo)->toBe('Con 2 dati.')
        ->and($righe[0]->condomini())->toBe([$this->roma->id => 'Via Roma 12'])
        ->and($righe[0]->sequenza)->not->toBeNull()
        ->and(collect($righe[0]->campi)->pluck('chiave')->all())->toEqualCanonicalizing(['nome', 'indirizzo']);
});

it('il fornitore: i dati cambiati, il conto tolto con il modello, i rappresentanti collegati e scollegati', function () {
    $admin = User::factory()->create(['name' => 'Andrea Costa', 'email_verified_at' => now()]);
    $admin->assignRole(Role::AMMINISTRATORE->value);
    $fornitore = Fornitore::query()->create(['ragione_sociale' => 'Serramenti Marino', 'stato' => 'attivo', 'giorni_scadenza' => 30,
        'modalita_pagamento_default' => 'bonifico', 'iban_principale' => 'IT60X0542811101000000123456']);
    $conto = $fornitore->contiCorrenti()->create(['iban' => 'IT60X0542811101000000123456', 'tipo' => 'ordinario', 'predefinito' => true,
        'intestatario' => 'Serramenti Marino']);
    $prima = RegistroModifica::query()->max('id') ?? 0;

    $this->actingAs($admin)->put(route('admin.fornitori.update', $fornitore), [
        'ragione_sociale' => 'Serramenti Marino S.r.l.', 'stato' => 'sospeso', 'modalita_pagamento_default' => 'bonifico', 'giorni_scadenza' => 30,
        'soggetto_ritenuta' => false, 'certificazione_iso' => false, 'residente_fiscale' => true, 'regime_forfetario' => false,
        'provvigioni_base_ridotta' => false, 'iban_principale' => null,
    ])->assertSessionHasNoErrors();

    $righe = RegistroModifica::query()->where('id', '>', $prima)->orderBy('id')->get();
    $fornitoreRiga = $righe->firstWhere('oggetto_tipo', 'fornitore');
    expect(collect($fornitoreRiga->campi)->pluck('dopo', 'etichetta')->all())
        ->toMatchArray(['Ragione sociale' => 'Serramenti Marino S.r.l.', 'Stato operativo' => 'Sospeso', 'IBAN principale' => null])
        ->and($righe->firstWhere('oggetto_tipo', 'conto_corrente'))->not->toBeNull()
        ->and($righe->firstWhere('oggetto_tipo', 'conto_corrente')->azione)->toBe('eliminata')
        ->and($righe->firstWhere('oggetto_tipo', 'conto_corrente')->scheda_tipo)->toBe('fornitore')
        ->and(ContoCorrente::query()->whereKey($conto->id)->exists())->toBeFalse();

    $this->actingAs($admin)->post(route('admin.fornitori.anagrafiche.store', $fornitore), ['anagrafica_id' => $this->ida->id, 'ruolo' => 'titolare'])
        ->assertSessionHasNoErrors();
    $collegata = RegistroModifica::query()->orderByDesc('id')->first();
    expect($collegata->campi[0])->toMatchArray(['etichetta' => 'Rappresentanti', 'prima' => null, 'dopo' => 'Ida Romano'])
        ->and($collegata->soggetti())->toContain(['anagrafica', $this->ida->id]);

    $this->actingAs($admin)->delete(route('admin.fornitori.anagrafiche.destroy', [$fornitore, $this->ida]))->assertSessionHasNoErrors();
    expect(RegistroModifica::query()->orderByDesc('id')->first()->campi[0])->toMatchArray(['prima' => 'Ida Romano', 'dopo' => null]);
});

it('«Crea nuova anagrafica» dal dialogo di «Registra passaggio»: una riga sola «Creata», nel registro di Via Roma 12 (decisione 554)', function () {
    $andrea = User::factory()->create(['name' => 'Andrea Costa', 'email_verified_at' => now()]);
    $andrea->assignRole(Role::AMMINISTRATORE->value);
    $unita = \App\Models\Immobile::query()->create(['condominio_id' => $this->roma->id, 'tipo' => 'appartamento', 'codice_immobile' => 'AP-7',
        'nome' => 'Interno 7', 'interno' => '7']);
    $prima = RegistroModifica::query()->max('id') ?? 0;

    $this->actingAs($andrea)->postJson(route('admin.gestionale.immobili.passaggi.anagrafica', [$this->roma->id, $unita->id]),
        ['nome' => 'Carlo Fontana', 'indirizzo' => 'Via Roma 12', 'codice_fiscale' => 'FNTCRL80A01F205X'])->assertCreated();

    $righe = RegistroModifica::query()->where('id', '>', $prima)->where('oggetto_tipo', 'anagrafica')->orderBy('id')->get();
    expect($righe->pluck('azione')->all())->toBe(['creata'])
        ->and($righe[0]->oggetto_nome)->toBe('Anagrafica di Carlo Fontana')
        ->and($righe[0]->condomini())->toBe([$this->roma->id => 'Via Roma 12']);
});

it('il condòmino invitato che completa la sua anagrafica nel portale: una riga sola «Creata», con i condomini dell\'invito (decisione 554)', function () {
    $this->roma->update(['codice_identificativo' => 'ROMA12']);
    \App\Models\Invito::create(['email' => 'carlo.fontana@example.com', 'building_codes' => ['ROMA12'], 'accepted_at' => now(),
        'expires_at' => now()->addDays(\App\Models\Invito::GIORNI_DI_VALIDITA)]);
    $carlo = User::factory()->create(['name' => 'Carlo Fontana', 'email' => 'carlo.fontana@example.com', 'email_verified_at' => now()]);
    $carlo->assignRole(Role::UTENTE->value);
    $prima = RegistroModifica::query()->max('id') ?? 0;

    $this->actingAs($carlo)->post(route('user.anagrafiche.store'), ['nome' => 'Carlo Fontana', 'indirizzo' => 'Via Roma 12'])->assertRedirect();

    $righe = RegistroModifica::query()->where('id', '>', $prima)->where('oggetto_tipo', 'anagrafica')->orderBy('id')->get();
    expect($righe->pluck('azione')->all())->toBe(['creata'])
        ->and($righe[0]->autore_nome)->toBe('Carlo Fontana')
        ->and($righe[0]->condomini())->toBe([$this->roma->id => 'Via Roma 12']);
});
