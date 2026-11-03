<?php

/**
 * # L'autore che non è più condòmino del palazzo (Code 263, 198 e 302)
 *
 * Fuori dal pannello l'autore vede ciò che ha scritto finché è **nel palazzo** dell'oggetto, o finché
 * l'oggetto è indirizzato a lui. Quando l'amministratore stacca la sua anagrafica dal condominio (ha
 * venduto, si è trasferito, ma resta condòmino altrove e l'account resta attivo), le sue segnalazioni
 * e comunicazioni **non private** di quel palazzo non le apre più, non le ritrova in elenco, non ne
 * riceve gli avvisi: la discussione che continua è dei condòmini che ci sono. Staccare l'anagrafica è
 * il gesto con cui l'amministratore dice «non è più dei nostri»; il programma non sceglie in silenzio
 * il contrario.
 *
 * Una **privata** resta sempre del suo autore: è indirizzata a lui per costruzione (Coda 304), e
 * nessun altro la vedrebbe.
 *
 * La regola sta in `scopeVisibiliNelPortale()` (scheda, elenco, conteggi, commento e avvisi dei
 * commenti) e, dal lato delle persone, in `anagraficheCheLaVedono()` (gli avvisi di creazione,
 * modifica e approvazione): qui si provano tutte e due dalle rotte.
 */

use App\Enums\NotificationType;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\Comunicazione;
use App\Models\Condominio;
use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\Commenti\NuovoCommentoNotification;
use App\Notifications\Comunicazioni\UpdatedComunicazioneNotification;
use App\Notifications\Segnalazioni\UpdatedSegnalazioneNotification;
use App\Services\Notifiche\DestinatariNotifica;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    $this->withoutVite();

    $this->vecchio = Condominio::factory()->create(['nome' => 'Condominio Aurora']);
    $this->nuovo   = Condominio::factory()->create(['nome' => 'Condominio Borea']);

    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    $this->admin->assignRole(Role::AMMINISTRATORE->value);

    // Mario è condòmino dei due palazzi; poi vende nel primo e l'amministratore lo stacca.
    $this->mario = uscitoResidente([$this->vecchio->id, $this->nuovo->id], 'Mario Rossi');
    $this->luigi = uscitoResidente([$this->vecchio->id], 'Luigi Verdi');
});

function uscitoResidente(array $condominiIds, string $nome, array $permessiInPiu = []): User
{
    $user = User::factory()->create(['email_verified_at' => now(), 'name' => $nome]);
    $user->assignRole(Role::UTENTE->value);
    if ($permessiInPiu !== []) {
        $user->givePermissionTo($permessiInPiu);
    }
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

function uscitoSegnalazione(Condominio $condominio, User $autore, array $stato = [], array $collegate = []): Segnalazione
{
    $s = Segnalazione::create(array_merge([
        'subject' => 'Infiltrazione dal tetto', 'description' => 'Macchia sul soffitto del quinto piano',
        'priority' => 'media', 'stato' => 'aperta', 'condominio_id' => $condominio->id, 'created_by' => $autore->id,
        'is_private' => false, 'is_published' => true, 'is_approved' => true, 'is_featured' => false, 'can_comment' => true,
    ], $stato));
    if ($collegate !== []) {
        $s->anagrafiche()->attach(array_map(fn (User $u) => $u->anagrafica->id, $collegate));
    }

    return $s->fresh();
}

function uscitoComunicazione(Condominio $condominio, User $autore, array $stato = [], array $collegate = []): Comunicazione
{
    $c = Comunicazione::create(array_merge([
        'subject' => 'Turni delle pulizie', 'description' => 'Il nuovo calendario', 'priority' => 'media',
        'created_by' => $autore->id, 'is_featured' => false, 'is_private' => false, 'is_published' => true,
        'is_approved' => true, 'can_comment' => false,
    ], $stato));
    $c->condomini()->attach($condominio->id);
    if ($collegate !== []) {
        $c->anagrafiche()->attach(array_map(fn (User $u) => $u->anagrafica->id, $collegate));
    }

    return $c->fresh();
}

function uscitoIdInElenco($test, User $user, string $rotta, string $chiave): array
{
    $props = $test->actingAs($user)->get(route($rotta, ['per_page' => 100]))->assertOk()->viewData('page')['props'];

    return collect($props[$chiave]['data'])->pluck('id')->all();
}

function uscitoStacca(User $user, Condominio $condominio): User
{
    $user->anagrafica->condomini()->detach($condominio->id);

    return $user->fresh();
}

// ─── Segnalazioni: scheda, elenco, conteggi ─────────────────────────────────────────────────

test('Coda 263 — l\'autore che non è più condòmino del palazzo non apre più la sua segnalazione non privata, non la trova in elenco e non la conta', function () {
    $s = uscitoSegnalazione($this->vecchio, $this->mario, ['priority' => 'urgente']);
    $mario = uscitoStacca($this->mario, $this->vecchio);

    $this->actingAs($mario)->get(route('user.segnalazioni.show', $s))->assertForbidden();
    expect(uscitoIdInElenco($this, $mario, 'user.segnalazioni.index', 'segnalazioni'))->not->toContain($s->id);
    expect((int) $this->actingAs($mario)->getJson(route('segnalazioni.stats'))->json('urgente'))->toBe(0);

    // Il vicino che nel palazzo c'è la vede ancora.
    $this->actingAs($this->luigi)->get(route('user.segnalazioni.show', $s))->assertOk();
});

test('Coda 263 — l\'autore ancora nel palazzo apre la sua segnalazione non privata, anche indirizzata ad altri', function () {
    // Il controesempio: la regola guarda il palazzo dell'autore, non lo toglie a chi c'è.
    $s = uscitoSegnalazione($this->vecchio, $this->mario, [], [$this->luigi]);

    $this->actingAs($this->mario)->get(route('user.segnalazioni.show', $s))->assertOk();
});

test('Coda 263 — l\'autore uscito dal palazzo apre ancora la segnalazione se è indirizzata a lui', function () {
    $s = uscitoSegnalazione($this->vecchio, $this->mario, [], [$this->mario]);
    $mario = uscitoStacca($this->mario, $this->vecchio);

    $this->actingAs($mario)->get(route('user.segnalazioni.show', $s))->assertOk();
});

test('Coda 304 — la privata resta del suo autore anche quando lui non è più nel palazzo', function () {
    $collegata = uscitoSegnalazione($this->vecchio, $this->mario, ['is_private' => true], [$this->mario]);
    $giaSvuotata = uscitoSegnalazione($this->vecchio, $this->mario, ['is_private' => true]);
    $mario = uscitoStacca($this->mario, $this->vecchio);
    // L'amministratore stacca anche il collegamento: resta l'autore.
    $collegata->anagrafiche()->detach();

    $this->actingAs($mario)->get(route('user.segnalazioni.show', $collegata))->assertOk();
    $this->actingAs($mario)->get(route('user.segnalazioni.show', $giaSvuotata))->assertOk();
    $this->actingAs($this->luigi)->get(route('user.segnalazioni.show', $giaSvuotata))->assertForbidden();
});

test('Coda 263 — la segnalazione spostata in un palazzo dove l\'autore non sta non la apre più', function () {
    $terzo = Condominio::factory()->create();
    $s = uscitoSegnalazione($this->vecchio, $this->mario);
    $s->update(['condominio_id' => $terzo->id]);

    $this->actingAs($this->mario)->get(route('user.segnalazioni.show', $s))->assertForbidden();
});

// ─── Segnalazioni: commenti e avvisi ────────────────────────────────────────────────────────

test('Coda 305 — l\'autore uscito dal palazzo non riceve l\'avviso dei commenti scritti dopo, e non li legge', function () {
    $s = uscitoSegnalazione($this->vecchio, $this->mario);
    $mario = uscitoStacca($this->mario, $this->vecchio);

    $this->actingAs($this->luigi)
        ->post(route('user.segnalazioni.commenti.store', $s), ['corpo' => 'Il tecnico passa giovedì'])
        ->assertSessionHasNoErrors();

    Notification::assertNotSentTo($mario, NuovoCommentoNotification::class);
    expect($s->fresh()->utenteHaAccessoAiCommenti($mario))->toBeFalse();
});

test('Coda 305 — l\'autore della privata riceve ancora l\'avviso dei commenti anche fuori dal palazzo', function () {
    // Controesempio sullo stesso percorso: un'altra persona collegata commenta la privata di Mario.
    $anna = uscitoResidente([$this->vecchio->id], 'Anna Neri');
    $s = uscitoSegnalazione($this->vecchio, $this->mario, ['is_private' => true], [$this->mario, $anna]);
    $mario = uscitoStacca($this->mario, $this->vecchio);

    $this->actingAs($anna)
        ->post(route('user.segnalazioni.commenti.store', $s), ['corpo' => 'Confermo, anche da me'])
        ->assertSessionHasNoErrors();

    Notification::assertSentTo($mario, NuovoCommentoNotification::class);
});

test('Coda 302 — l\'avviso di modifica di una segnalazione non privata non va all\'autore che non è più nel palazzo', function () {
    $s = uscitoSegnalazione($this->vecchio, $this->mario);
    uscitoStacca($this->mario, $this->vecchio);

    $this->actingAs($this->admin)->put(route('admin.segnalazioni.update', $s), [
        'subject' => $s->subject, 'description' => $s->description, 'priority' => $s->priority, 'stato' => 'in lavorazione',
        'condominio_id' => $s->condominio_id, 'can_comment' => true, 'is_featured' => false, 'is_published' => true,
        'anagrafiche' => [], 'avvisa_destinatari' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    Notification::assertSentTo($this->luigi->anagrafica, UpdatedSegnalazioneNotification::class);
    Notification::assertNotSentTo($this->mario->anagrafica, UpdatedSegnalazioneNotification::class);
});

test('Coda 302 — la platea di ogni segnalazione è chi la trova in elenco, anche con l\'autore uscito dal palazzo', function () {
    $risolutore = app(DestinatariNotifica::class);
    $anna = uscitoResidente([$this->vecchio->id], 'Anna Neri');

    $segnalazioni = [];
    foreach ([false, true] as $privata) {
        foreach ([[], [$this->mario], [$anna]] as $collegate) {
            $segnalazioni[] = uscitoSegnalazione($this->vecchio, $this->mario, ['is_private' => $privata], $collegate);
        }
    }
    $mario = uscitoStacca($this->mario, $this->vecchio);

    foreach ([$mario, $this->luigi, $anna] as $persona) {
        $elenco = uscitoIdInElenco($this, $persona, 'user.segnalazioni.index', 'segnalazioni');

        foreach ($segnalazioni as $s) {
            expect($risolutore->perModello($s->fresh())->contains($persona->anagrafica->id))
                ->toBe(in_array($s->id, $elenco, true), "segnalazione {$s->id} (privata=".(int) $s->is_private."), persona {$persona->name}");
        }
    }

    // E l'autore uscito sta nella platea solo delle private e di quella indirizzata a lui.
    $diMario = collect($segnalazioni)->filter(fn (Segnalazione $s) => $risolutore->perModello($s->fresh())->contains($mario->anagrafica->id));
    expect($diMario->count())->toBe(4);
});

// ─── Comunicazioni: la gemella ──────────────────────────────────────────────────────────────

test('Coda 198 — l\'autore che non è più condòmino del palazzo non apre più la sua comunicazione non privata; la privata sì', function () {
    $autore = uscitoResidente([$this->vecchio->id, $this->nuovo->id], 'Carla Bianchi', [Permission::CREATE_COMUNICAZIONI->value]);
    $pubblica = uscitoComunicazione($this->vecchio, $autore);
    $privata = uscitoComunicazione($this->vecchio, $autore, ['is_private' => true], [$autore]);
    $autore = uscitoStacca($autore, $this->vecchio);

    $this->actingAs($autore)->get(route('user.comunicazioni.show', $pubblica))->assertForbidden();
    expect(uscitoIdInElenco($this, $autore, 'user.comunicazioni.index', 'comunicazioni'))->not->toContain($pubblica->id);
    $this->actingAs($autore)->get(route('user.comunicazioni.show', $privata))->assertOk();

    $this->actingAs($this->luigi)->get(route('user.comunicazioni.show', $pubblica))->assertOk();
});

test('Coda 304 — la comunicazione privata resta del suo autore fuori dal palazzo, anche quando è collegata solo a un\'altra persona', function () {
    $autore = uscitoResidente([$this->vecchio->id, $this->nuovo->id], 'Carla Bianchi', [Permission::CREATE_COMUNICAZIONI->value]);
    $anna = uscitoResidente([$this->vecchio->id], 'Anna Neri');
    $privata = uscitoComunicazione($this->vecchio, $autore, ['is_private' => true], [$anna]);
    $autore = uscitoStacca($autore, $this->vecchio);

    $this->actingAs($autore)->get(route('user.comunicazioni.show', $privata))->assertOk();
    expect(uscitoIdInElenco($this, $autore, 'user.comunicazioni.index', 'comunicazioni'))->toContain($privata->id);
    // E resta nella platea dei suoi avvisi, come la trova in elenco.
    expect(app(DestinatariNotifica::class)->perModello($privata->fresh())->contains($autore->anagrafica->id))->toBeTrue();
    $this->actingAs($this->luigi)->get(route('user.comunicazioni.show', $privata))->assertForbidden();
});

test('Coda 198 — l\'autore ancora in uno dei palazzi della comunicazione la apre', function () {
    $autore = uscitoResidente([$this->nuovo->id], 'Carla Bianchi', [Permission::CREATE_COMUNICAZIONI->value]);
    $c = uscitoComunicazione($this->vecchio, $autore);
    $c->condomini()->attach($this->nuovo->id);

    $this->actingAs($autore)->get(route('user.comunicazioni.show', $c))->assertOk();
});

test('Coda 302 — l\'avviso di modifica di una comunicazione non privata non va all\'autore che non è più nel palazzo', function () {
    $autore = uscitoResidente([$this->vecchio->id, $this->nuovo->id], 'Carla Bianchi', [Permission::CREATE_COMUNICAZIONI->value]);
    $c = uscitoComunicazione($this->vecchio, $autore);
    $autore = uscitoStacca($autore, $this->vecchio);

    $this->actingAs($this->admin)->put(route('admin.comunicazioni.update', $c), [
        'subject' => $c->subject, 'description' => 'Il calendario corretto', 'priority' => $c->priority,
        'can_comment' => false, 'is_featured' => false, 'is_published' => true,
        'condomini_ids' => [$this->vecchio->id], 'anagrafiche' => [], 'avvisa_destinatari' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    Notification::assertSentTo($this->luigi->anagrafica, UpdatedComunicazioneNotification::class);
    Notification::assertNotSentTo($autore->anagrafica, UpdatedComunicazioneNotification::class);
    expect(app(DestinatariNotifica::class)->perModello($c->fresh())->contains($autore->anagrafica->id))->toBeFalse();
});
