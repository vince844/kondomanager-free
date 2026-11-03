<?php

/**
 * La lingua scelta nelle impostazioni generali vale anche per le risposte che nascono nei controlli della richiesta,
 * prima della pagina: il 429 dei limiti, il rimando di chi è sospeso, la scheda che non c'è.
 *
 * ## Perché questo file esiste
 *
 * La lingua dell'installazione la mette `SetLocaleMiddleware`, in coda al gruppo `web`. Il router riordina i
 * middleware con la sua lista delle priorità, e quelli che la lista porta in testa (i limiti di rotta, la ricerca
 * dei modelli nella rotta, la sessione) giravano prima di lei: le loro risposte uscivano nella lingua del `.env`
 * (`APP_LOCALE`), non in quella scelta. `bootstrap/app.php` mette la lingua nella lista, prima dei limiti.
 *
 * Il 429 è provato in `PaginaTroppiTentativiTest.php`; qui le altre risposte che lo spostamento porta nella lingua
 * giusta, e le rotte del ripristino a token, dove la lingua non deve fermare niente.
 */

use App\Enums\Role as RoleEnum;
use App\Models\User;
use App\Settings\GeneralSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $impostazioni = app(GeneralSettings::class);
    $impostazioni->language = 'en';
    $impostazioni->save();
});

/**
 * Una richiesta nuova parte dalla lingua del `.env`, come all'avvio di un processo: nei test l'applicazione è la stessa
 * per tutte le richieste (e `setLocale` riscrive anche `config('app.locale')`).
 */
function partendoDallaLinguaDelEnv(): void
{
    app()->setLocale('it');
}

it('chi è sospeso legge il perché, sulla pagina di accesso, nella lingua scelta nelle impostazioni generali', function () {
    $sospeso = User::factory()->create(['email_verified_at' => now(), 'suspended_at' => now()]);
    $sospeso->assignRole(RoleEnum::UTENTE->value);

    partendoDallaLinguaDelEnv();

    $this->actingAs($sospeso)->get('/settings/profile')
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => __('errors.403.account_suspended', [], 'en')]);
});

it('una scheda che non c\'è risponde 404 nella lingua scelta nelle impostazioni generali', function () {
    $amministratore = User::factory()->create(['email_verified_at' => now()]);
    $amministratore->assignRole(RoleEnum::AMMINISTRATORE->value);

    partendoDallaLinguaDelEnv();

    $this->actingAs($amministratore)->get('/impostazioni/backups/'.Str::uuid().'/download')
        ->assertNotFound()
        ->assertSee('<html lang="en">', false)
        ->assertSee(__('errors.404_heading', [], 'en'));
});
