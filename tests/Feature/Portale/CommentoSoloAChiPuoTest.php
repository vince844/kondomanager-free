<?php

/**
 * # Il campo del commento solo a chi può commentare (Coda 305)
 *
 * Chi commenta lo decide `CommentoPolicy::create`: il permesso, la discussione aperta, il palazzo, la
 * scheda. La scheda però offriva il campo guardando solo il permesso (`commenti_config.can_create`):
 * l'autore di una privata uscito dal palazzo, o una persona collegata a una segnalazione di un palazzo
 * dove non sta, la apriva, scriveva il commento e se lo vedeva rifiutare (403). Ora il campo segue la
 * stessa domanda della policy (`Gate`), e la policy non cambia: chi può commentare resta com'era.
 */

use App\Enums\NotificationType;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role as RuoloSpatie;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    $this->withoutVite();

    $this->aurora = Condominio::factory()->create(['nome' => 'Condominio Aurora']);
    $this->borea  = Condominio::factory()->create(['nome' => 'Condominio Borea']);

    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    $this->admin->assignRole(Role::AMMINISTRATORE->value);
});

function commentoUtente(array $condominiIds, string $nome, string $ruolo = 'utente'): User
{
    $user = User::factory()->create(['email_verified_at' => now(), 'name' => $nome]);
    $user->assignRole($ruolo);
    foreach (NotificationType::cases() as $tipo) {
        DB::table('notification_preferences')->insert([
            'user_id' => $user->id, 'type' => $tipo->value, 'enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $anagrafica = Anagrafica::factory()->create(['user_id' => $user->id, 'nome' => $nome]);
    $anagrafica->condomini()->attach($condominiIds);

    return $user->fresh();
}

function commentoSegnalazione(Condominio $condominio, User $autore, array $stato = [], array $collegate = []): Segnalazione
{
    $s = Segnalazione::create(array_merge([
        'subject' => "Il cancello dell'ingresso «B»", 'description' => "Non chiude\nda due giorni",
        'priority' => 'media', 'stato' => 'aperta', 'condominio_id' => $condominio->id, 'created_by' => $autore->id,
        'is_private' => false, 'is_published' => true, 'is_approved' => true, 'is_featured' => false, 'can_comment' => true,
    ], $stato));
    if ($collegate !== []) {
        $s->anagrafiche()->attach(array_map(fn (User $u) => $u->anagrafica->id, $collegate));
    }

    return $s->fresh();
}

/** `can_create` della scheda del portale, o `null` se la scheda non si apre. */
function commentoOfferto($test, User $user, Segnalazione $s): ?bool
{
    $risposta = $test->actingAs($user)->get(route('user.segnalazioni.show', $s));
    if ($risposta->status() === 403) {
        return null;
    }

    return $risposta->assertOk()->viewData('page')['props']['commenti_config']['can_create'];
}

test('Coda 305 — l\'autore di una privata uscito dal palazzo la apre, ma il campo del commento non gli è offerto: il commento gli sarebbe rifiutato', function () {
    // La regola: `can_create` segue la policy (lo stesso `Gate`), così il campo non compare a chi non
    // può commentare; la policy non cambia.
    $mario = commentoUtente([$this->aurora->id, $this->borea->id], 'Mario Rossi');
    $s = commentoSegnalazione($this->aurora, $mario, ['is_private' => true], [$mario]);
    $mario->anagrafica->condomini()->detach($this->aurora->id);
    $mario = $mario->fresh();

    expect(commentoOfferto($this, $mario, $s))->toBeFalse();
    $this->actingAs($mario)->post(route('user.segnalazioni.commenti.store', $s), ['corpo' => 'Ci sono ancora io'])->assertForbidden();
});

test('Coda 305 — la persona collegata a una segnalazione di un palazzo dove non sta la legge, ma il campo del commento non le è offerto', function () {
    $luigi = commentoUtente([$this->aurora->id], 'Luigi Verdi');
    $carla = commentoUtente([$this->borea->id], "Carla D'Amico");
    $s = commentoSegnalazione($this->aurora, $luigi, [], [$carla]);

    expect(commentoOfferto($this, $carla, $s))->toBeFalse();
    $this->actingAs($carla)->post(route('user.segnalazioni.commenti.store', $s), ['corpo' => 'Mi riguarda'])->assertForbidden();
});

test('Coda 305 — per ogni persona e ogni segnalazione che apre, il campo del commento c\'è se e solo se il commento è accettato', function () {
    // La griglia: chi apre la scheda trova il campo esattamente quando la rotta del commento lo accetta.
    RuoloSpatie::create(['name' => 'Solo lettura', 'guard_name' => 'web'])->givePermissionTo([Permission::VIEW_SEGNALAZIONI->value]);

    $mario = commentoUtente([$this->aurora->id, $this->borea->id], 'Mario Rossi');
    $luigi = commentoUtente([$this->aurora->id], 'Luigi Verdi');
    $carla = commentoUtente([$this->borea->id], "Carla D'Amico");
    $soloLettura = commentoUtente([$this->aurora->id], 'Paolo Galli', 'Solo lettura');

    $segnalazioni = [
        commentoSegnalazione($this->aurora, $mario, ['is_private' => true], [$mario]),
        commentoSegnalazione($this->aurora, $luigi),
        commentoSegnalazione($this->aurora, $luigi, ['can_comment' => false]),
        commentoSegnalazione($this->aurora, $luigi, [], [$carla]),
        commentoSegnalazione($this->aurora, $luigi, [], [$mario]),
    ];
    $mario->anagrafica->condomini()->detach($this->aurora->id);

    $casi = 0;
    foreach ([$mario, $luigi, $carla, $soloLettura] as $persona) {
        $persona = $persona->fresh();
        foreach ($segnalazioni as $s) {
            $offerto = commentoOfferto($this, $persona, $s);
            if ($offerto === null) {
                continue;
            }
            $casi++;

            $stato = $this->actingAs($persona)->post(route('user.segnalazioni.commenti.store', $s), ['corpo' => 'Un commento'])->status();
            expect($offerto)->toBe($stato !== 403, "{$persona->name}, segnalazione {$s->id}: campo ".var_export($offerto, true).", commento {$stato}");
        }
    }

    // Le schede aperte, dalla regola: Mario la sua privata e quella collegata a lui (2); Luigi le quattro
    // non private, tutte sue (4); Carla quella collegata a lei (1); chi ha la sola lettura le due non private senza
    // collegamenti (2). Nove casi.
    expect($casi)->toBe(9);
});

test('Coda 305 — il condòmino del palazzo, con la discussione aperta, trova il campo; con la discussione chiusa no', function () {
    // Le guardie: dove il commento si accetta il campo resta.
    $luigi = commentoUtente([$this->aurora->id], 'Luigi Verdi');
    $aperta = commentoSegnalazione($this->aurora, $luigi);
    $chiusa = commentoSegnalazione($this->aurora, $luigi, ['can_comment' => false]);

    expect(commentoOfferto($this, $luigi, $aperta))->toBeTrue()
        ->and(commentoOfferto($this, $luigi, $chiusa))->toBeFalse();
});

test('Coda 305 — nella scheda del pannello l\'amministratore trova il campo, come prima', function () {
    $luigi = commentoUtente([$this->aurora->id], 'Luigi Verdi');
    $s = commentoSegnalazione($this->aurora, $luigi);

    $props = $this->actingAs($this->admin)->get(route('admin.segnalazioni.show', $s))->assertOk()->viewData('page')['props'];
    expect($props['commenti_config']['can_create'])->toBeTrue();
    $this->actingAs($this->admin)->post(route('admin.segnalazioni.commenti.store', $s), ['corpo' => 'Il fabbro passa domani'])->assertRedirect();
});

test('Coda 305 — nella scheda del pannello il campo segue la stessa domanda della rotta: chi ha il pannello commenta, anche senza il permesso dei commenti', function () {
    // La policy fa passare chi ha l'accesso al pannello (`CommentoPolicy::before`), e la policy non
    // cambia: un ruolo su misura del pannello senza «Commenta segnalazioni» commenta, e il campo c'è.
    RuoloSpatie::create(['name' => 'Segreteria del pannello', 'guard_name' => 'web'])->givePermissionTo([
        Permission::ACCESS_ADMIN_PANEL->value, Permission::VIEW_SEGNALAZIONI->value,
    ]);
    $segreteria = User::factory()->create(['email_verified_at' => now()]);
    $segreteria->assignRole('Segreteria del pannello');
    $luigi = commentoUtente([$this->aurora->id], 'Luigi Verdi');
    $s = commentoSegnalazione($this->aurora, $luigi);

    $props = $this->actingAs($segreteria)->get(route('admin.segnalazioni.show', $s))->assertOk()->viewData('page')['props'];
    expect($props['commenti_config']['can_create'])->toBeTrue();
    $this->actingAs($segreteria)->post(route('admin.segnalazioni.commenti.store', $s), ['corpo' => 'Avvisato il fabbro'])
        ->assertRedirect()->assertSessionHasNoErrors();
});
