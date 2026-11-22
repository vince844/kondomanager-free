<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Invito;
use App\Models\RegistroModifica;
use App\Models\User;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * 1.12.0-beta.13 (Coda 679 e Coda ㉗, decisione 518 (4), §5 del progetto): gli atti sulle utenze, ognuno dalla sua rotta. Righe `atto_utenza` dell'area
 * «utenti», con i nomi di chi fa e di chi subisce copiati: creato, ruolo cambiato, permessi cambiati, nome e email cambiati (nessuna riga: non è un atto di
 * governo), sospeso, riattivato, email verificata a mano, invito mandato e revocato, invito rimandato, eliminato con il nome che resta, permesso diretto
 * revocato, ruolo come definizione con i permessi tolti e aggiunti. La persona dell'utenza è fra i soggetti e i suoi condomini sono quelli della riga.
 *
 * Che cosa non copre: l'utenza creata dall'installazione e quella nata dall'accettazione di un invito (lo stesso atto «registrato» del servizio); gli
 * accessi (non sono atti, Coda ㉗ (2)).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();
    $this->andrea = User::factory()->create(['name' => 'Andrea Costa', 'email_verified_at' => now()]);
    $this->andrea->assignRole(RoleEnum::AMMINISTRATORE->value);
    $this->roma = Condominio::factory()->create(['nome' => 'Via Roma 12']);
    $this->giorgio = User::factory()->create(['name' => 'Giorgio Bianchi', 'email' => 'giorgio@example.it', 'email_verified_at' => now()]);
    $this->giorgio->assignRole(RoleEnum::UTENTE->value);
    $this->persona = Anagrafica::query()->create(['nome' => 'Giorgio Bianchi', 'indirizzo' => 'Via Roma 12', 'user_id' => $this->giorgio->id]);
    $this->persona->condomini()->attach($this->roma->id);
});

function auAtti(int $prima)
{
    return RegistroModifica::query()->where('id', '>', $prima)->whereIn('azione', ['atto_utenza', 'creata', 'modificata', 'eliminata'])
        ->whereIn('area', ['utenti'])->orderBy('id')->get();
}

function auConferma($test)
{
    return $test->actingAs($test->andrea)->withSession(['auth.password_confirmed_at' => time()]);
}

it('creato: l\'atto con il ruolo dato, firmato da chi lo fa', function () {
    $prima = RegistroModifica::query()->max('id') ?? 0;
    auConferma($this)->post(route('utenti.store'), ['name' => 'Ombra Neri', 'email' => 'ombra@example.it',
        'roles' => Role::where('name', RoleEnum::UTENTE->value)->value('id'), 'permissions' => [], 'anagrafica' => null])->assertSessionHasNoErrors();

    $atti = auAtti($prima);
    expect($atti)->toHaveCount(1)
        ->and($atti[0]->azione)->toBe('atto_utenza')
        ->and($atti[0]->oggetto_nome)->toBe('Utente di Ombra Neri')
        ->and($atti[0]->autore_nome)->toBe('Andrea Costa')
        ->and($atti[0]->riepilogo)->toBe('Utente creato: utente')
        ->and($atti[0]->campi[0])->toMatchArray(['etichetta' => 'Ruolo', 'prima' => null, 'dopo' => 'utente']);
});

it('il ruolo cambiato scrive l\'atto; nome ed email cambiati da soli no (non è un atto di governo)', function () {
    $scheda = fn (array $modifiche) => array_merge(['name' => 'Giorgio Bianchi', 'email' => 'giorgio@example.it',
        'roles' => Role::where('name', RoleEnum::UTENTE->value)->value('id'), 'permissions' => [], 'anagrafica' => $this->persona->id], $modifiche);

    $prima = RegistroModifica::query()->max('id') ?? 0;
    auConferma($this)->put(route('utenti.update', ['utenti' => $this->giorgio->id]), $scheda(['name' => 'Giorgio Bianchi Rossi',
        'email' => 'giorgio.rossi@example.it']))->assertSessionHasNoErrors();
    expect(auAtti($prima))->toHaveCount(0);

    $prima = RegistroModifica::query()->max('id');
    auConferma($this)->put(route('utenti.update', ['utenti' => $this->giorgio->id]), $scheda(['email' => 'giorgio.rossi@example.it',
        'roles' => Role::where('name', RoleEnum::FORNITORE->value)->value('id')]))->assertSessionHasNoErrors();
    $atti = auAtti($prima);
    expect($atti)->toHaveCount(1)
        ->and($atti[0]->riepilogo)->toBe('Ruolo o permessi cambiati')
        ->and($atti[0]->campi[0])->toMatchArray(['etichetta' => 'Ruolo', 'prima' => 'utente', 'dopo' => 'fornitore'])
        ->and($atti[0]->condomini())->toBe([$this->roma->id => 'Via Roma 12'])
        ->and($atti[0]->soggetti())->toBe([['anagrafica', $this->persona->id], ['utente', $this->giorgio->id]]);
});

it('sospeso e riattivato; email verificata a mano', function () {
    $prima = RegistroModifica::query()->max('id') ?? 0;
    $this->actingAs($this->andrea)->put(route('utenti.suspend', $this->giorgio))->assertSessionHasNoErrors();
    $this->actingAs($this->andrea)->put(route('utenti.unsuspend', $this->giorgio))->assertSessionHasNoErrors();
    $this->giorgio->forceFill(['email_verified_at' => null])->save();
    $this->actingAs($this->andrea)->put(route('utenti.toggle-verification', $this->giorgio))->assertSessionHasNoErrors();

    expect(auAtti($prima)->pluck('riepilogo')->all())->toBe(['Utente sospeso', 'Utente riattivato', 'Email verificata a mano']);
});

it('eliminato: l\'atto resta con il nome copiato, anche quando l\'utenza non c\'è più', function () {
    $prima = RegistroModifica::query()->max('id') ?? 0;
    $id = $this->giorgio->id;
    $this->actingAs($this->andrea)->delete(route('utenti.destroy', ['utenti' => $id]))->assertSessionHasNoErrors();

    expect(User::query()->whereKey($id)->exists())->toBeFalse();
    $atto = auAtti($prima)->firstWhere('riepilogo', 'Utente eliminato');
    expect($atto)->not->toBeNull()
        ->and($atto->oggetto_nome)->toBe('Utente di Giorgio Bianchi')
        ->and($atto->oggetto_id)->toBe($id)
        ->and($atto->campi[0])->toMatchArray(['etichetta' => 'Ruolo', 'prima' => 'utente', 'dopo' => null]);
});

it('invito mandato e revocato; invito rimandato', function () {
    $prima = RegistroModifica::query()->max('id') ?? 0;
    $this->actingAs($this->andrea)->post(route('inviti.store'), ['emails' => ['nuovo@example.it'], 'buildings' => [$this->roma->codice_identificativo]])
        ->assertSessionHasNoErrors();
    $invito = Invito::query()->where('email', 'nuovo@example.it')->firstOrFail();
    $this->actingAs($this->andrea)->delete(route('inviti.destroy', $invito))->assertSessionHasNoErrors();
    $this->actingAs($this->andrea)->post(route('utenti.reinvite', ['email' => $this->giorgio->email]))->assertSessionHasNoErrors();

    $atti = auAtti($prima);
    expect($atti->pluck('riepilogo')->all())->toBe(['Invito mandato', 'Invito revocato', 'Invito rimandato'])
        ->and($atti[0]->oggetto_nome)->toBe('Invito a nuovo@example.it')
        ->and($atti[0]->condomini())->toBe([$this->roma->id => 'Via Roma 12']);
});

it('il permesso diretto revocato, e il ruolo come definizione con i permessi tolti e aggiunti', function () {
    $permesso = Permission::where('name', PermissionEnum::VIEW_USERS->value)->firstOrFail();
    $this->giorgio->givePermissionTo($permesso);
    $prima = RegistroModifica::query()->max('id') ?? 0;
    auConferma($this)->delete(route('users.permissions.destroy', [$this->giorgio, $permesso]));
    expect(auAtti($prima)->pluck('riepilogo')->all())->toBe(['Ruolo o permessi cambiati'])
        ->and(auAtti($prima)[0]->campi[0])->toMatchArray(['etichetta' => 'Permessi tolti', 'prima' => PermissionEnum::VIEW_USERS->value]);

    $ruolo = Role::create(['name' => 'contabile', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(PermissionEnum::VIEW_USERS->value);
    $prima = RegistroModifica::query()->max('id');
    auConferma($this)->delete(route('ruoli.permissions.destroy', [$ruolo, $permesso]));
    $atto = auAtti($prima)->first();
    expect($atto->riepilogo)->toBe('Ruolo «contabile»: permessi cambiati')
        ->and($atto->campi[0])->toMatchArray(['etichetta' => 'Permessi tolti', 'prima' => PermissionEnum::VIEW_USERS->value, 'dopo' => null]);
});
