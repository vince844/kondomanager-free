<?php

use App\Enums\Permission;
use App\Models\Anagrafica;
use App\Models\Commento;
use App\Models\Condominio;
use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\NuovoCommentoNotification;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role;

// ---------------------------------------------------------------------------
// Helpers locali al file di test
// ---------------------------------------------------------------------------

/**
 * Crea un utente con permessi e lo collega al condominio tramite anagrafica.
 */
function creaUtenteCondomino(Condominio $condominio, array $permessi = []): User
{
    $user = User::factory()->create();

    $anagrafica = Anagrafica::factory()->create(['user_id' => $user->id]);
    $anagrafica->condomini()->attach($condominio->id);

    foreach ($permessi as $permesso) {
        SpatiePermission::firstOrCreate(['name' => $permesso, 'guard_name' => 'web']);
        $user->givePermissionTo($permesso);
    }

    return $user;
}

/**
 * Crea una segnalazione nel condominio con commenti abilitati.
 */
function creaSegnalazione(Condominio $condominio, User $creatore, array $attrs = []): Segnalazione
{
    return Segnalazione::factory()->create(array_merge([
        'condominio_id' => $condominio->id,
        'created_by'    => $creatore->id,
        'can_comment'   => true,
    ], $attrs));
}

// ---------------------------------------------------------------------------
// Setup permessi minimi (evita crash Spatie in tutti i test)
// ---------------------------------------------------------------------------
beforeEach(function () {
    $permessiCommenti = [
        Permission::COMMENT_SEGNALAZIONI->value,
        Permission::PUBLISH_COMMENTS_SEGNALAZIONI->value,
        Permission::APPROVE_COMMENTS_SEGNALAZIONI->value,
        Permission::EDIT_OWN_COMMENTS_SEGNALAZIONI->value,
        Permission::DELETE_OWN_COMMENTS_SEGNALAZIONI->value,
        Permission::ACCESS_ADMIN_PANEL->value,
        Permission::VIEW_SEGNALAZIONI->value,
        Permission::EDIT_SEGNALAZIONI->value,
    ];

    foreach ($permessiCommenti as $permesso) {
        SpatiePermission::firstOrCreate(['name' => $permesso, 'guard_name' => 'web']);
    }
});

// ---------------------------------------------------------------------------
// Test 1: condomino del condominio crea commento → in_attesa
// ---------------------------------------------------------------------------
test('condomino senza publish crea commento in_attesa', function () {
    Notification::fake();

    $condominio = Condominio::factory()->create();
    $utente     = creaUtenteCondomino($condominio, [
        Permission::COMMENT_SEGNALAZIONI->value,
        Permission::ACCESS_ADMIN_PANEL->value,
    ]);
    $creatore   = creaUtenteCondomino($condominio, [
        Permission::COMMENT_SEGNALAZIONI->value,
        Permission::ACCESS_ADMIN_PANEL->value,
    ]);
    $segnalazione = creaSegnalazione($condominio, $creatore);

    $this->actingAs($utente)
        ->post(route('admin.segnalazioni.commenti.store', $segnalazione), [
            'corpo' => 'Testo del commento di test.',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('commenti', [
        'commentable_type' => Segnalazione::class,
        'commentable_id'   => $segnalazione->id,
        'user_id'          => $utente->id,
        'stato'            => 'in_attesa',
    ]);
});

// ---------------------------------------------------------------------------
// Test 2: condomino con PUBLISH → commento subito pubblicato
// ---------------------------------------------------------------------------
test('condomino con PUBLISH_COMMENTS crea commento subito pubblicato', function () {
    Notification::fake();

    $condominio = Condominio::factory()->create();
    $creatore   = creaUtenteCondomino($condominio, [
        Permission::COMMENT_SEGNALAZIONI->value,
        Permission::ACCESS_ADMIN_PANEL->value,
    ]);
    $utente     = creaUtenteCondomino($condominio, [
        Permission::COMMENT_SEGNALAZIONI->value,
        Permission::PUBLISH_COMMENTS_SEGNALAZIONI->value,
        Permission::ACCESS_ADMIN_PANEL->value,
    ]);
    $segnalazione = creaSegnalazione($condominio, $creatore);

    $this->actingAs($utente)
        ->post(route('admin.segnalazioni.commenti.store', $segnalazione), [
            'corpo' => 'Testo pubblicato direttamente.',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('commenti', [
        'commentable_id' => $segnalazione->id,
        'user_id'        => $utente->id,
        'stato'          => 'pubblicato',
    ]);
});

// ---------------------------------------------------------------------------
// Test 3: utente di altro condominio riceve 403
// ---------------------------------------------------------------------------
test('utente di altro condominio non può commentare — 403', function () {
    $condominioA = Condominio::factory()->create();
    $condominioB = Condominio::factory()->create();

    $creatore   = creaUtenteCondomino($condominioA, [Permission::COMMENT_SEGNALAZIONI->value]);
    $estraneo   = creaUtenteCondomino($condominioB, [Permission::COMMENT_SEGNALAZIONI->value]);

    $segnalazione = creaSegnalazione($condominioA, $creatore);

    $this->actingAs($estraneo)
        ->post(route('admin.segnalazioni.commenti.store', $segnalazione), [
            'corpo' => 'Tentativo da condominio sbagliato.',
        ])
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Test 4: can_comment = false → creazione negata
// ---------------------------------------------------------------------------
test('con can_comment false la creazione è negata', function () {
    $condominio = Condominio::factory()->create();
    $creatore   = creaUtenteCondomino($condominio, [Permission::COMMENT_SEGNALAZIONI->value]);
    $utente     = creaUtenteCondomino($condominio, [Permission::COMMENT_SEGNALAZIONI->value]);

    $segnalazione = creaSegnalazione($condominio, $creatore, ['can_comment' => false]);

    $this->actingAs($utente)
        ->post(route('admin.segnalazioni.commenti.store', $segnalazione), [
            'corpo' => 'Non dovrebbe funzionare.',
        ])
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Test 5: con can_comment = false, i commenti esistenti restano visibili
// ---------------------------------------------------------------------------
test('con can_comment false i commenti pubblicati esistenti restano visibili', function () {
    $condominio = Condominio::factory()->create();
    $creatore   = creaUtenteCondomino($condominio, [Permission::COMMENT_SEGNALAZIONI->value]);
    $segnalazione = creaSegnalazione($condominio, $creatore, ['can_comment' => false]);

    // Commento pubblicato pre-esistente
    Commento::factory()->create([
        'commentable_type' => Segnalazione::class,
        'commentable_id'   => $segnalazione->id,
        'user_id'          => $creatore->id,
        'condominio_id'    => $condominio->id,
        'stato'            => 'pubblicato',
    ]);

    expect($segnalazione->commenti()->count())->toBe(1);
    expect($segnalazione->commentiAbilitati())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Test 6: solo autore può modificare il proprio commento
// ---------------------------------------------------------------------------
test('solo l\'autore può modificare il proprio commento', function () {
    $condominio = Condominio::factory()->create();
    $autore     = creaUtenteCondomino($condominio, [
        Permission::COMMENT_SEGNALAZIONI->value,
        Permission::EDIT_OWN_COMMENTS_SEGNALAZIONI->value,
        Permission::ACCESS_ADMIN_PANEL->value,
    ]);
    // $altro NON ha ACCESS_ADMIN_PANEL (altrimenti bypassa la policy via before())
    // Usiamo withoutMiddleware solo per questo utente passando direttamente al controller
    $altro      = creaUtenteCondomino($condominio, [
        Permission::COMMENT_SEGNALAZIONI->value,
        Permission::EDIT_OWN_COMMENTS_SEGNALAZIONI->value,
    ]);
    $segnalazione = creaSegnalazione($condominio, $autore);

    $commento = Commento::factory()->create([
        'commentable_type' => Segnalazione::class,
        'commentable_id'   => $segnalazione->id,
        'user_id'          => $autore->id,
        'condominio_id'    => $condominio->id,
        'stato'            => 'pubblicato',
    ]);

    // Autore con ACCESS_ADMIN_PANEL → policy bypassa via before() ma consente
    $this->actingAs($autore)
        ->patch(route('admin.commenti.update', $commento), ['corpo' => 'Testo modificato.'])
        ->assertRedirect();

    // Altro utente senza ACCESS_ADMIN_PANEL → deve passare il middleware admin ma
    // non ha il ruolo. Testiamo direttamente via withoutMiddleware per isolare la policy
    $this->actingAs($altro)
        ->withoutMiddleware()
        ->patch(route('admin.commenti.update', $commento), ['corpo' => 'Tentativo altrui.'])
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Test 7: admin può approvare commento in_attesa
// ---------------------------------------------------------------------------
test('admin approva commento in_attesa — diventa pubblicato', function () {
    $condominio = Condominio::factory()->create();
    $admin      = creaUtenteCondomino($condominio, [
        Permission::APPROVE_COMMENTS_SEGNALAZIONI->value,
        Permission::ACCESS_ADMIN_PANEL->value,
    ]);
    $utente     = creaUtenteCondomino($condominio, [
        Permission::COMMENT_SEGNALAZIONI->value,
        Permission::ACCESS_ADMIN_PANEL->value,
    ]);
    $segnalazione = creaSegnalazione($condominio, $utente);

    $commento = Commento::factory()->create([
        'commentable_type' => Segnalazione::class,
        'commentable_id'   => $segnalazione->id,
        'user_id'          => $utente->id,
        'condominio_id'    => $condominio->id,
        'stato'            => 'in_attesa',
    ]);

    $this->actingAs($admin)
        ->post(route('admin.commenti.approva', $commento))
        ->assertRedirect();

    $this->assertDatabaseHas('commenti', [
        'id'    => $commento->id,
        'stato' => 'pubblicato',
    ]);
});

// ---------------------------------------------------------------------------
// Test 8: commento nascosto / soft-deleted non compare in commenti()
// ---------------------------------------------------------------------------
test('commento nascosto non compare nella relazione commenti()', function () {
    $condominio = Condominio::factory()->create();
    $utente     = creaUtenteCondomino($condominio, [Permission::COMMENT_SEGNALAZIONI->value]);
    $segnalazione = creaSegnalazione($condominio, $utente);

    Commento::factory()->create([
        'commentable_type' => Segnalazione::class,
        'commentable_id'   => $segnalazione->id,
        'user_id'          => $utente->id,
        'stato'            => 'pubblicato',
    ]);

    Commento::factory()->create([
        'commentable_type' => Segnalazione::class,
        'commentable_id'   => $segnalazione->id,
        'user_id'          => $utente->id,
        'stato'            => 'nascosto',
    ]);

    Commento::factory()->create([
        'commentable_type' => Segnalazione::class,
        'commentable_id'   => $segnalazione->id,
        'user_id'          => $utente->id,
        'stato'            => 'in_attesa',
    ])->delete(); // soft delete

    expect($segnalazione->commenti()->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Test 9: notifiche inviate ai destinatari corretti, escluso l'autore
// ---------------------------------------------------------------------------
test('alla creazione notifica i destinatari escludendo l\'autore', function () {
    Notification::fake();

    $condominio = Condominio::factory()->create();
    $autore     = creaUtenteCondomino($condominio, [
        Permission::COMMENT_SEGNALAZIONI->value,
        Permission::PUBLISH_COMMENTS_SEGNALAZIONI->value,
    ]);
    $creatoreSegnalazione = creaUtenteCondomino($condominio, [Permission::COMMENT_SEGNALAZIONI->value]);
    $segnalazione = creaSegnalazione($condominio, $creatoreSegnalazione);

    $this->actingAs($autore)
        ->post(route('admin.segnalazioni.commenti.store', $segnalazione), [
            'corpo' => 'Commento da autore — non deve notificare sé stesso.',
        ]);

    // L'autore NON deve ricevere notifica
    Notification::assertNotSentTo($autore, NuovoCommentoNotification::class);
});
