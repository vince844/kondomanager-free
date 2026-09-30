<?php

/**
 * Giro di sicurezza della 1.11.0-beta.39 (PR #48 di kta1kri, commit aaff2bfc, portato con
 * `git cherry-pick`): `UserCreateSegnalazioneRequest` validava `condominio_id` solo con
 * `exists:condomini,id`, quindi un condòmino poteva aprire una segnalazione in un palazzo che
 * non è il suo. La correzione aggiunge una regola che pretende il condominio fra quelli
 * dell'anagrafica dell'utente.
 *
 * La stessa richiesta serve anche per `update()`: senza un'eccezione, un condòmino che
 * l'amministratore ha scollegato dal condominio della propria segnalazione non riuscirebbe più a
 * salvarne una modifica, nemmeno lasciando il condominio invariato — il che romperebbe una
 * modifica legittima invece di una manomissione. La correzione fa passare la regola quando il
 * valore coincide con il condominio attuale della segnalazione in modifica.
 *
 * **Cosa resta scoperto**: `SegnalazionePolicy::create()` (il permesso `CREATE_SEGNALAZIONI` in
 * sé, non il condominio scelto); il resto dei campi del modulo (oggetto, descrizione...), già
 * validati prima di questa beta e non toccati qui.
 */

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    // Gli eventi di notifica non servono a questi test e possono avere effetti collaterali
    // (invii, code): li si mette in pausa e si osserva solo se scattano o no.
    Event::fake();
});

/** Un condòmino con CREATE_SEGNALAZIONI (di default in UTENTE) e un'anagrafica nei condomìni dati. */
function segnalatoreCon(Condominio ...$condomini): array
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole(Role::UTENTE->value);

    $anagrafica = Anagrafica::factory()->create(['user_id' => $user->id]);
    foreach ($condomini as $condominio) {
        $anagrafica->condomini()->attach($condominio->id);
    }

    return [$user, $anagrafica];
}

function payloadSegnalazione(int $condominioId, array $overrides = []): array
{
    return array_merge([
        'subject'      => 'Perdita nel garage',
        'description'  => 'Si nota una perdita vicino al posto auto 3.',
        'priority'     => 'media',
        'stato'        => 'aperta',
        'is_private'   => false,
        'condominio_id' => $condominioId,
    ], $overrides);
}

// --- store() -------------------------------------------------------------------------------

test('il condòmino non apre una segnalazione in un condominio che non è il suo', function () {
    $suo = Condominio::factory()->create();
    $altro = Condominio::factory()->create();
    [$user] = segnalatoreCon($suo);

    $response = $this->actingAs($user)->post(
        route('user.segnalazioni.store'),
        payloadSegnalazione($altro->id)
    );

    $response->assertSessionHasErrors('condominio_id');
    expect(Segnalazione::where('condominio_id', $altro->id)->count())->toBe(0);
});

test('il condòmino apre una segnalazione nel proprio condominio', function () {
    $condominio = Condominio::factory()->create();
    [$user] = segnalatoreCon($condominio);

    $response = $this->actingAs($user)->post(
        route('user.segnalazioni.store'),
        payloadSegnalazione($condominio->id)
    );

    $response->assertSessionDoesntHaveErrors()
        ->assertRedirect(route('user.segnalazioni.index'));
    expect(Segnalazione::where('condominio_id', $condominio->id)->where('created_by', $user->id)->count())->toBe(1);
});

test('il condòmino di due condomìni può usare entrambi', function () {
    $condominioA = Condominio::factory()->create();
    $condominioB = Condominio::factory()->create();
    [$user] = segnalatoreCon($condominioA, $condominioB);

    $response = $this->actingAs($user)->post(
        route('user.segnalazioni.store'),
        payloadSegnalazione($condominioB->id)
    );

    $response->assertSessionDoesntHaveErrors();
    expect(Segnalazione::where('condominio_id', $condominioB->id)->count())->toBe(1);
});

test('un utente senza anagrafica riceve un errore di validazione, non un 500', function () {
    $condominio = Condominio::factory()->create();
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole(Role::UTENTE->value);

    $response = $this->actingAs($user)->post(
        route('user.segnalazioni.store'),
        payloadSegnalazione($condominio->id)
    );

    $response->assertSessionHasErrors('condominio_id');
    expect(Segnalazione::count())->toBe(0);
});

test('un id di condominio inesistente, non intero o in forma di array è respinto', function () {
    $condominio = Condominio::factory()->create();
    [$user] = segnalatoreCon($condominio);

    foreach ([999999, 'abc', [$condominio->id]] as $valore) {
        $response = $this->actingAs($user)->post(
            route('user.segnalazioni.store'),
            payloadSegnalazione(0, ['condominio_id' => $valore])
        );

        $response->assertSessionHasErrors('condominio_id');
    }

    expect(Segnalazione::count())->toBe(0);
});

// --- update() --------------------------------------------------------------------------

test('l\'update non sposta la segnalazione in un condominio altrui', function () {
    $proprio = Condominio::factory()->create();
    $altro = Condominio::factory()->create();
    [$user] = segnalatoreCon($proprio);
    $user->givePermissionTo(Permission::EDIT_OWN_SEGNALAZIONI->value);

    $segnalazione = Segnalazione::factory()->create([
        'condominio_id' => $proprio->id,
        'created_by'    => $user->id,
    ]);

    $response = $this->actingAs($user)->put(
        route('user.segnalazioni.update', $segnalazione),
        payloadSegnalazione($altro->id, ['subject' => 'Tentativo di spostamento'])
    );

    $response->assertSessionHasErrors('condominio_id');
    expect($segnalazione->fresh()->condominio_id)->toBe($proprio->id);
});

test('l\'update nel proprio condominio riesce', function () {
    $condominio = Condominio::factory()->create();
    [$user] = segnalatoreCon($condominio);
    $user->givePermissionTo(Permission::EDIT_OWN_SEGNALAZIONI->value);

    $segnalazione = Segnalazione::factory()->create([
        'condominio_id' => $condominio->id,
        'created_by'    => $user->id,
    ]);

    $response = $this->actingAs($user)->put(
        route('user.segnalazioni.update', $segnalazione),
        payloadSegnalazione($condominio->id, ['subject' => 'Oggetto corretto'])
    );

    $response->assertSessionDoesntHaveErrors();
    expect($segnalazione->fresh()->subject)->toBe('Oggetto corretto');
});

test('chi non è più membro del condominio può comunque salvare la propria segnalazione lasciandolo invariato', function () {
    $condominio = Condominio::factory()->create();
    [$user, $anagrafica] = segnalatoreCon($condominio);
    $user->givePermissionTo(Permission::EDIT_OWN_SEGNALAZIONI->value);

    $segnalazione = Segnalazione::factory()->create([
        'condominio_id' => $condominio->id,
        'created_by'    => $user->id,
    ]);

    // L'amministratore lo scollega dal condominio dopo che la segnalazione è stata aperta.
    $anagrafica->condomini()->detach($condominio->id);

    $response = $this->actingAs($user)->put(
        route('user.segnalazioni.update', $segnalazione),
        payloadSegnalazione($condominio->id, ['subject' => 'Piccola correzione'])
    );

    $response->assertSessionDoesntHaveErrors();
    expect($segnalazione->fresh()->subject)->toBe('Piccola correzione');
});
