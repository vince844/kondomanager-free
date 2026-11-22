<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Permission;
use App\Enums\Role;
use App\Http\Controllers\RegistroModifiche\RegistroController;
use App\Http\Controllers\RegistroModifiche\RipristinoController;
use App\Http\Controllers\RegistroModifiche\StoriaController;
use App\Http\Controllers\RegistroModifiche\VerificaController;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\RegistroModifica;
use App\Models\User;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role as RuoloSpatie;

/**
 * 1.12.0-beta.13 (§15 e P18 del progetto della tappa 1; decisione 518 (3)): i due permessi del registro delle modifiche e chi apre le sue rotte.
 *
 * - «Vede il registro delle modifiche» (l'amministratore) e «Vede la storia delle schede» (l'amministratore e il collaboratore), con la descrizione, nei
 *   ruoli di serie e nella copia del frontend (`PermessiNelFrontendTest` controlla che le due liste siano uguali).
 * - **Ogni rotta nuova risponde 403** a chi non ha il permesso (un ruolo su misura con il solo accesso al pannello), al ruolo `utente` e al ruolo
 *   `fornitore` **anche con i due permessi dati a mano** (P18); il collaboratore apre la «Storia» e non il registro, la stampa, la verifica.
 * - **L'ordine delle rotte**: ogni indirizzo nuovo arriva al suo controller, non allo `show()` di una `Route::resource` sotto lo stesso prefisso (il
 *   difetto della Coda ㉗).
 *
 * Che cosa non copre: la limitazione per condominio dei collaboratori, che oggi non esiste (§1 del progetto); le rotte dei casi speciali (oscuramento,
 * conservazione, sospensione), che hanno i loro test (`OscuramentoTest`, `ConservazioneESospensioneTest`).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();
    $this->c = Condominio::factory()->create(['nome' => 'Via Roma 12']);
    $this->ida = Anagrafica::query()->create(['nome' => 'Ida Romano', 'indirizzo' => 'Via Roma 12', 'email' => 'ida@libero.it']);
    $this->ida->condomini()->attach($this->c->id);
    $this->ida->update(['email' => 'ida@gmail.com']);
    $this->riga = RegistroModifica::query()->where('oggetto_tipo', 'anagrafica')->where('azione', 'modificata')->firstOrFail();
});

function rmpUtente(string $ruolo, array $permessiInPiu = []): User
{
    $u = User::factory()->create(['email_verified_at' => now()]);
    $u->assignRole($ruolo);
    if ($permessiInPiu !== []) {
        $u->givePermissionTo($permessiInPiu);
    }

    return $u->fresh();
}

/** Le rotte nuove, come le chiamano le pagine: metodo, indirizzo, controller e metodo atteso. @return list<array{0: string, 1: string, 2: string}> */
function rmpRotte($test): array
{
    return [
        ['GET', route('admin.registro-modifiche.storia', ['tipo' => 'anagrafica', 'id' => $test->ida->id]), StoriaController::class],
        ['GET', route('admin.registro-modifiche.ripristina.anteprima', ['riga' => $test->riga->id, 'campo' => 'email']), RipristinoController::class . '@anteprima'],
        ['POST', route('admin.registro-modifiche.ripristina', ['riga' => $test->riga->id]), RipristinoController::class . '@ripristina'],
        ['POST', route('admin.registro-modifiche.verifica'), VerificaController::class . '@verifica'],
        ['GET', route('admin.registro-modifiche.verifica.rapporto'), VerificaController::class . '@rapporto'],
        ['GET', route('admin.gestionale.registro-modifiche.index', $test->c), RegistroController::class . '@delCondominio'],
        ['GET', route('admin.gestionale.registro-modifiche.stampa', $test->c), RegistroController::class . '@stampaDelCondominio'],
        ['GET', route('impostazioni.registro-modifiche'), RegistroController::class . '@delloStudio'],
        ['GET', route('impostazioni.registro-modifiche.stampa'), RegistroController::class . '@stampaDelloStudio'],
        // Il conto delle righe della finestra «Stampa il registro delle modifiche»: gli stessi permessi della stampa.
        ['GET', route('admin.gestionale.registro-modifiche.stampa.conto', $test->c), RegistroController::class . '@contaDelCondominio'],
        ['GET', route('impostazioni.registro-modifiche.stampa.conto'), RegistroController::class . '@contaDelloStudio'],
    ];
}

it('i due permessi esistono con la descrizione; l\'amministratore li ha tutti e due, il collaboratore solo la storia, condòmini e fornitori nessuno', function () {
    expect(Permission::VIEW_REGISTRO_MODIFICHE->value)->toBe('Vede il registro delle modifiche')
        ->and(Permission::VIEW_STORIA_SCHEDE->value)->toBe('Vede la storia delle schede')
        ->and(Permission::VIEW_REGISTRO_MODIFICHE->description())->toContain('registro delle modifiche')
        ->and(Permission::VIEW_STORIA_SCHEDE->description())->toContain('«Storia»')
        ->and(Role::AMMINISTRATORE->permissions())->toContain(Permission::VIEW_REGISTRO_MODIFICHE, Permission::VIEW_STORIA_SCHEDE)
        ->and(Role::COLLABORATORE->permissions())->toContain(Permission::VIEW_STORIA_SCHEDE)
        ->and(Role::COLLABORATORE->permissions())->not->toContain(Permission::VIEW_REGISTRO_MODIFICHE)
        ->and(Role::UTENTE->permissions())->not->toContain(Permission::VIEW_STORIA_SCHEDE, Permission::VIEW_REGISTRO_MODIFICHE)
        ->and(Role::FORNITORE->permissions())->not->toContain(Permission::VIEW_STORIA_SCHEDE, Permission::VIEW_REGISTRO_MODIFICHE)
        ->and(rmpUtente('collaboratore')->hasPermissionTo(Permission::VIEW_STORIA_SCHEDE->value))->toBeTrue();
});

it('ogni rotta nuova arriva al suo controller: nessuna finisce nello show() di una risorsa sotto lo stesso prefisso', function () {
    foreach (rmpRotte($this) as [$metodo, $url, $atteso]) {
        $rotta = app('router')->getRoutes()->match(Request::create($url, $metodo));
        expect($rotta->getActionName())->toBe($atteso, "{$metodo} {$url}");
    }
});

it('chi entra nel pannello senza i permessi riceve 403 da ogni rotta nuova', function () {
    RuoloSpatie::findOrCreate('Solo pannello', 'web')->syncPermissions([Permission::ACCESS_ADMIN_PANEL->value, Permission::VIEW_CONDOMINI->value,
        Permission::VIEW_ANAGRAFICHE->value, Permission::EDIT_ANAGRAFICHE->value]);
    $chi = rmpUtente('Solo pannello');

    foreach (rmpRotte($this) as [$metodo, $url]) {
        $this->actingAs($chi)->json($metodo, $url, ['campo' => 'email'])->assertForbidden();
    }
    expect($this->ida->fresh()->email)->toBe('ida@gmail.com');
});

it('il ruolo utente e il ruolo fornitore ricevono 403 da ogni rotta nuova, anche con i due permessi e l\'accesso al pannello dati a mano', function () {
    $tutti = [Permission::VIEW_REGISTRO_MODIFICHE->value, Permission::VIEW_STORIA_SCHEDE->value, Permission::ACCESS_ADMIN_PANEL->value,
        Permission::VIEW_CONDOMINI->value, Permission::VIEW_ANAGRAFICHE->value, Permission::EDIT_ANAGRAFICHE->value, Permission::MANAGE_GENERAL_SETTINGS->value];

    foreach ([Role::UTENTE->value, Role::FORNITORE->value] as $ruolo) {
        $chi = rmpUtente($ruolo, $tutti);
        foreach (rmpRotte($this) as [$metodo, $url]) {
            $this->actingAs($chi)->json($metodo, $url, ['campo' => 'email'])->assertForbidden();
        }
    }
    expect($this->ida->fresh()->email)->toBe('ida@gmail.com');
});

it('il collaboratore apre la «Storia», non il registro del condominio, quello dello studio, la stampa né la verifica', function () {
    $marco = rmpUtente('collaboratore');

    $this->actingAs($marco)->getJson(route('admin.registro-modifiche.storia', ['tipo' => 'anagrafica', 'id' => $this->ida->id]))->assertOk();
    foreach (array_slice(rmpRotte($this), 3) as [$metodo, $url]) {
        $this->actingAs($marco)->json($metodo, $url)->assertForbidden();
    }
});

it('l\'amministratore apre tutte le rotte di lettura', function () {
    $andrea = rmpUtente('amministratore');

    $this->actingAs($andrea)->getJson(route('admin.registro-modifiche.storia', ['tipo' => 'anagrafica', 'id' => $this->ida->id]))->assertOk();
    $this->actingAs($andrea)->getJson(route('admin.registro-modifiche.ripristina.anteprima', ['riga' => $this->riga->id, 'campo' => 'email']))->assertOk();
    $this->actingAs($andrea)->postJson(route('admin.registro-modifiche.verifica'))->assertOk();
    $this->actingAs($andrea)->get(route('admin.gestionale.registro-modifiche.index', $this->c))->assertOk();
    $this->actingAs($andrea)->get(route('impostazioni.registro-modifiche'))->assertOk();
    $this->actingAs($andrea)->getJson(route('admin.gestionale.registro-modifiche.stampa.conto', $this->c))->assertOk();
    $this->actingAs($andrea)->getJson(route('impostazioni.registro-modifiche.stampa.conto'))->assertOk();
});
