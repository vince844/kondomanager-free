<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * I testi delle pagine degli utenti dicono ciò che il programma fa.
 *
 * - Un invito dà il ruolo «utente» o «fornitore»: la scheda degli inviti parla di condòmini e fornitori, non di collaboratori.
 * - Il pulsante in fondo alla scheda dell'utente salva: si chiama «Salva».
 * - Eliminare un utente elimina anche le comunicazioni e le segnalazioni che ha scritto, per tutti: la finestra lo dice, e la
 *   prova qui sotto lo verifica sul database, così la frase non può restare vera per sbaglio.
 */

use App\Enums\Role as RoleEnum;
use App\Models\Comunicazione;
use App\Models\Condominio;
use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

dataset('lingue', ['it', 'en', 'es', 'pt']);

it('la scheda degli inviti non parla di collaboratori', function (string $lingua) {
    app()->setLocale($lingua);

    foreach (['users.header.list_invites_description', 'users.guides.invites_desc'] as $chiave) {
        expect(mb_strtolower(__($chiave)))->not->toContain('collabora')->not->toContain('colabora')->not->toBe(mb_strtolower($chiave));
    }
})->with('lingue');

it('il pulsante della scheda dell\'utente si chiama «Salva»', function () {
    app()->setLocale('it');

    expect(__('users.actions.save_user'))->toBe('Salva');
    // Il menu dell'elenco apre la scheda: lì resta «Modifica».
    expect(__('users.actions.edit_user'))->toBe('Modifica');
    expect(file_get_contents(resource_path('js/pages/utenti/ModificaUtente.vue')))
        ->toContain("trans('users.actions.save_user')")
        ->not->toContain("trans('users.actions.edit_user')");
});

it('la finestra di eliminazione dice che comunicazioni e segnalazioni scritte dall\'utente spariscono per tutti', function (string $lingua) {
    app()->setLocale($lingua);
    $frase = mb_strtolower(__('users.dialogs.delete_user_description'));

    $parole = [
        'it' => ['comunicazioni', 'segnalazioni', 'per tutti'],
        'en' => ['notices', 'reports', 'for everyone'],
        'es' => ['comunicaciones', 'incidencias', 'para todos'],
        'pt' => ['comunicações', 'ocorrências', 'para todos'],
    ][$lingua];

    foreach ($parole as $parola) {
        expect($frase)->toContain($parola);
    }
})->with('lingue');

it('eliminato l\'utente, le sue comunicazioni e segnalazioni non ci sono più', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $amministratore = User::factory()->create(['email_verified_at' => now()]);
    $amministratore->assignRole(RoleEnum::AMMINISTRATORE->value);

    $autore = User::factory()->create(['email_verified_at' => now()]);
    $autore->assignRole(RoleEnum::COLLABORATORE->value);

    $comunicazione = Comunicazione::factory()->create(['created_by' => $autore->id]);
    $segnalazione = Segnalazione::factory()->create(['created_by' => $autore->id, 'condominio_id' => Condominio::factory()->create()->id]);

    $this->actingAs($amministratore)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('utenti.destroy', ['utenti' => $autore->id]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(User::find($autore->id))->toBeNull();
    expect(Comunicazione::withoutGlobalScopes()->find($comunicazione->id))->toBeNull();
    expect(Segnalazione::withoutGlobalScopes()->find($segnalazione->id))->toBeNull();
});
