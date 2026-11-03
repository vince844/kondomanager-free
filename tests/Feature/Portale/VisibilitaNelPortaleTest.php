<?php

/**
 * # Chi vede una segnalazione o una comunicazione fuori dal pannello (Code 198, 263, 304, 305)
 *
 * ## La regola, una sola
 *
 * Fuori dal pannello una segnalazione o una comunicazione si apre se è **pubblicata e approvata,
 * oppure se chi la apre ne è l'autore** (decisione 125; la stessa forma di `DocumentoPolicy`); e poi
 * solo se è indirizzata a lui: l'autore; le anagrafiche collegate; tutto il
 * condominio se non ha anagrafiche collegate **e non è privata**. Una privata la vedono solo l'autore
 * e le anagrafiche collegate, anche con i collegamenti vuoti (Coda 304, decisione Q1 del 17/10/2026:
 * la privacy la decide `is_private`).
 *
 * La regola la scrive lo scope del modello, in un posto solo, e la usano tutti: la
 * scheda (policy), l'elenco (servizio), i conteggi (`segnalazioni.stats`) e gli
 * avvisi dei commenti. Guarda la pubblicazione e la privacy: questi test la provano
 * da ogni porta, e la griglia in fondo verifica che elenco e scheda dicano la stessa
 * cosa.
 *
 * L'oggetto non visibile dà **403**, come le righe della roadmap.
 *
 * Il commento (Coda 305) chiede l'accesso all'oggetto: chi non può aprire la segnalazione non la
 * commenta.
 */

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\Commento;
use App\Models\Comunicazione;
use App\Models\Condominio;
use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role as SpatieRole;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    $this->withoutVite();

    $this->condominio = Condominio::factory()->create();

    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    $this->admin->assignRole(Role::AMMINISTRATORE->value);

    $this->mario = visibilitaResidente($this->condominio);   // l'autore
    $this->luigi = visibilitaResidente($this->condominio);   // il vicino
    $this->anna  = visibilitaResidente($this->condominio);   // una terza persona, a volte destinataria
});

function visibilitaResidente(Condominio $condominio, string $ruolo = 'utente'): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole($ruolo === 'utente' ? Role::UTENTE->value : Role::FORNITORE->value);
    $anagrafica = Anagrafica::factory()->create(['user_id' => $user->id]);
    $anagrafica->condomini()->attach($condominio->id);

    return $user->fresh();
}

function visibilitaSegnalazione(Condominio $condominio, User $autore, array $stato = [], array $collegate = []): Segnalazione
{
    $s = Segnalazione::create(array_merge([
        'subject'       => 'Infiltrazione dal tetto',
        'description'   => 'Macchia sul soffitto',
        'priority'      => 'alta',
        'stato'         => 'aperta',
        'condominio_id' => $condominio->id,
        'created_by'    => $autore->id,
        'is_private'    => false,
        'is_published'  => true,
        'is_approved'   => true,
        'is_featured'   => false,
        'can_comment'   => true,
        'assigned_to'   => null,
    ], $stato));

    if ($collegate !== []) {
        $s->anagrafiche()->attach(array_map(fn (User $u) => $u->anagrafica->id, $collegate));
    }

    return $s->fresh();
}

function visibilitaComunicazione(Condominio $condominio, User $autore, array $stato = [], array $collegate = []): Comunicazione
{
    $c = Comunicazione::create(array_merge([
        'subject'      => 'Assemblea straordinaria',
        'description'  => 'Ordine del giorno riservato',
        'priority'     => 'media',
        'created_by'   => $autore->id,
        'is_featured'  => false,
        'is_private'   => false,
        'is_published' => true,
        'is_approved'  => true,
        'can_comment'  => false,
    ], $stato));
    $c->condomini()->attach($condominio->id);

    if ($collegate !== []) {
        $c->anagrafiche()->attach(array_map(fn (User $u) => $u->anagrafica->id, $collegate));
    }

    return $c->fresh();
}

function visibilitaIdSegnalazioniInElenco($test, User $user): array
{
    $props = $test->actingAs($user)->get(route('user.segnalazioni.index', ['per_page' => 100]))->assertOk()->viewData('page')['props'];

    return collect($props['segnalazioni']['data'])->pluck('id')->all();
}

function visibilitaIdComunicazioniInElenco($test, User $user): array
{
    $props = $test->actingAs($user)->get(route('user.comunicazioni.index', ['per_page' => 100]))->assertOk()->viewData('page')['props'];

    return collect($props['comunicazioni']['data'])->pluck('id')->all();
}

// ─── Coda 263: le segnalazioni non pubblicate o non approvate ───────────────────────────────

test('Coda 263 — il vicino non apre una segnalazione pubblicata ma non approvata', function () {
    $s = visibilitaSegnalazione($this->condominio, $this->mario, ['is_published' => true, 'is_approved' => false]);

    $this->actingAs($this->luigi)->get(route('user.segnalazioni.show', $s))->assertForbidden();
});

test('Coda 263 — il condòmino non apre la bozza dell\'amministratore (approvata, non pubblicata)', function () {
    $s = visibilitaSegnalazione($this->condominio, $this->admin, ['is_published' => false, 'is_approved' => true]);

    $this->actingAs($this->luigi)->get(route('user.segnalazioni.show', $s))->assertForbidden();
});

test('Coda 263 — l\'autore apre la propria segnalazione ancora in moderazione', function () {
    $s = visibilitaSegnalazione($this->condominio, $this->mario, ['is_published' => false, 'is_approved' => false]);

    $this->actingAs($this->mario)->get(route('user.segnalazioni.show', $s))->assertOk();
});

test('Coda 263 — il vicino apre ancora la segnalazione pubblicata e approvata del suo palazzo', function () {
    $s = visibilitaSegnalazione($this->condominio, $this->mario);

    $this->actingAs($this->luigi)->get(route('user.segnalazioni.show', $s))->assertOk();
    expect(visibilitaIdSegnalazioniInElenco($this, $this->luigi))->toContain($s->id);
});

test('Coda 263 — un utente con il permesso di vedere le segnalazioni ma senza anagrafica non apre la scheda e non riceve un errore', function () {
    // Decisione 134.4: il portale manda chi non ha un'anagrafica a completare i suoi dati, con la
    // frase che dice perché (`CheckHasAnagrafica`); prima la scheda rispondeva 403. La regola della
    // scheda non cambia: la policy gli nega la segnalazione.
    $senza = User::factory()->create(['email_verified_at' => now()]);
    $senza->assignRole(Role::UTENTE->value);
    $s = visibilitaSegnalazione($this->condominio, $this->mario);

    $this->actingAs($senza)->get(route('user.segnalazioni.show', $s))->assertRedirect(route('user.anagrafiche.create'));
    expect(\Illuminate\Support\Facades\Gate::forUser($senza)->denies('show', $s))->toBeTrue();
});

test('Coda 263 — i conteggi del portale non vanno in errore per un utente senza anagrafica', function () {
    $senza = User::factory()->create(['email_verified_at' => now()]);
    $senza->assignRole(Role::UTENTE->value);
    visibilitaSegnalazione($this->condominio, $this->mario);

    $this->actingAs($senza)->getJson(route('segnalazioni.stats'))->assertOk();
});

// ─── Coda 304: la privata la decide is_private ───────────────────────────────────────────────

test('Coda 304 — una privata con i residenti già tolti resta dell\'autore: il vicino non la trova, non la apre, non la conta', function () {
    // Una privata senza residenti può esistere già nel database: il modulo oggi non la svuota.
    $s = visibilitaSegnalazione($this->condominio, $this->mario, ['is_private' => true, 'priority' => 'urgente']);
    expect($s->anagrafiche()->count())->toBe(0);

    expect(visibilitaIdSegnalazioniInElenco($this, $this->luigi))->not->toContain($s->id);
    $this->actingAs($this->luigi)->get(route('user.segnalazioni.show', $s))->assertForbidden();
    expect((int) $this->actingAs($this->luigi)->getJson(route('segnalazioni.stats'))->json('urgente'))->toBe(0);

    $dashboard = $this->actingAs($this->luigi)->get(route('user.dashboard'))->assertOk()->viewData('page')['props'];
    expect(collect($dashboard['segnalazioni'])->pluck('id')->all())->not->toContain($s->id);

    // L'autore sì.
    expect(visibilitaIdSegnalazioniInElenco($this, $this->mario))->toContain($s->id);
    $this->actingAs($this->mario)->get(route('user.segnalazioni.show', $s))->assertOk();
    expect((int) $this->actingAs($this->mario)->getJson(route('segnalazioni.stats'))->json('urgente'))->toBe(1);
});

test('Coda 304 — una privata collegata a un\'altra persona la vedono l\'autore e quella persona, non il vicino', function () {
    $s = visibilitaSegnalazione($this->condominio, $this->mario, ['is_private' => true], [$this->anna]);

    $this->actingAs($this->anna)->get(route('user.segnalazioni.show', $s))->assertOk();
    $this->actingAs($this->mario)->get(route('user.segnalazioni.show', $s))->assertOk();
    $this->actingAs($this->luigi)->get(route('user.segnalazioni.show', $s))->assertForbidden();
});

test('Coda 304 — una segnalazione non privata indirizzata a una persona la vede lei, non il vicino', function () {
    $s = visibilitaSegnalazione($this->condominio, $this->admin, [], [$this->anna]);

    expect(visibilitaIdSegnalazioniInElenco($this, $this->anna))->toContain($s->id);
    expect(visibilitaIdSegnalazioniInElenco($this, $this->luigi))->not->toContain($s->id);
    $this->actingAs($this->luigi)->get(route('user.segnalazioni.show', $s))->assertForbidden();
});

// ─── Coda 305: il commento chiede l'accesso alla segnalazione ───────────────────────────────

test('Coda 305 — il vicino non commenta una segnalazione in moderazione', function () {
    $s = visibilitaSegnalazione($this->condominio, $this->mario, ['is_published' => false, 'is_approved' => false]);

    $this->actingAs($this->luigi)
        ->post(route('user.segnalazioni.commenti.store', $s), ['corpo' => 'Anch\'io'])
        ->assertForbidden();

    expect(Commento::count())->toBe(0);
});

test('Coda 305 — il fornitore del palazzo non commenta la privata di un condòmino', function () {
    $fornitore = visibilitaResidente($this->condominio, 'fornitore');
    $s = visibilitaSegnalazione($this->condominio, $this->mario, ['is_private' => true], [$this->mario]);

    $this->actingAs($fornitore)
        ->post(route('user.segnalazioni.commenti.store', $s), ['corpo' => 'Passo domani'])
        ->assertForbidden();

    expect(Commento::count())->toBe(0);
});

test('Coda 305 — l\'autore e la persona collegata commentano la privata', function () {
    $s = visibilitaSegnalazione($this->condominio, $this->mario, ['is_private' => true], [$this->mario, $this->anna]);

    $this->actingAs($this->mario)
        ->post(route('user.segnalazioni.commenti.store', $s), ['corpo' => 'Aggiungo una foto'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($this->anna)
        ->post(route('user.segnalazioni.commenti.store', $s), ['corpo' => 'Confermo'])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(Commento::count())->toBe(2);
});

test('Coda 305 — chi non può aprire la segnalazione non riceve l\'avviso dei commenti', function () {
    // L'avviso delle risposte usa la stessa regola della scheda.
    $s = visibilitaSegnalazione($this->condominio, $this->mario, ['is_private' => true], [$this->mario]);

    expect($s->utenteHaAccessoAiCommenti($this->mario))->toBeTrue()
        ->and($s->utenteHaAccessoAiCommenti($this->luigi))->toBeFalse()
        ->and($s->utenteHaAccessoAiCommenti($this->admin))->toBeTrue();

    $inModerazione = visibilitaSegnalazione($this->condominio, $this->mario, ['is_published' => false, 'is_approved' => false]);
    expect($inModerazione->utenteHaAccessoAiCommenti($this->luigi))->toBeFalse()
        ->and($inModerazione->utenteHaAccessoAiCommenti($this->mario))->toBeTrue();
});

test('Coda 305 — nessuna pagina assegna una segnalazione: il campo assigned_to non entra né in creazione né in modifica', function () {
    // È la prova, dalla rotta, che il vecchio ramo «assegnata» degli avvisi dei commenti non era
    // raggiungibile: la regola sola non lo ripete.
    $this->actingAs($this->admin)->post(route('admin.segnalazioni.store'), [
        'subject'       => 'Ascensore',
        'description'   => 'Fermo',
        'priority'      => 'alta',
        'stato'         => 'aperta',
        'condominio_id' => $this->condominio->id,
        'can_comment'   => true,
        'is_featured'   => false,
        'is_published'  => true,
        'assigned_to'   => $this->luigi->id,
    ])->assertRedirect();
    $s = Segnalazione::latest('id')->firstOrFail();
    expect($s->assigned_to)->toBeNull();

    $this->actingAs($this->admin)->put(route('admin.segnalazioni.update', $s), [
        'subject'       => 'Ascensore',
        'description'   => 'Fermo',
        'priority'      => 'alta',
        'stato'         => 'aperta',
        'condominio_id' => $this->condominio->id,
        'can_comment'   => true,
        'is_featured'   => false,
        'is_published'  => true,
        'anagrafiche'   => [],
        'assigned_to'   => $this->luigi->id,
    ])->assertRedirect();
    expect($s->fresh()->assigned_to)->toBeNull();

    $this->actingAs($this->mario)->post(route('user.segnalazioni.store'), [
        'subject'       => 'Portone',
        'description'   => 'Non chiude',
        'priority'      => 'media',
        'stato'         => 'aperta',
        'condominio_id' => $this->condominio->id,
        'is_private'    => false,
        'assigned_to'   => $this->luigi->id,
    ])->assertRedirect();
    expect(Segnalazione::latest('id')->firstOrFail()->assigned_to)->toBeNull();
});

// ─── Coda 198: le comunicazioni non pubblicate o non approvate ──────────────────────────────

test('Coda 198 — il condòmino non apre per id la bozza dell\'amministratore', function () {
    $c = visibilitaComunicazione($this->condominio, $this->admin, ['is_published' => false, 'is_approved' => true]);

    $this->actingAs($this->luigi)->get(route('user.comunicazioni.show', $c))->assertForbidden();
});

test('Coda 198 — il fornitore del palazzo non apre per id una comunicazione in bozza', function () {
    $fornitore = visibilitaResidente($this->condominio, 'fornitore');
    $c = visibilitaComunicazione($this->condominio, $this->admin, ['is_published' => false, 'is_approved' => true]);

    $this->actingAs($fornitore)->get(route('user.comunicazioni.show', $c))->assertForbidden();
});

test('Coda 198 — il vicino non apre la comunicazione in moderazione di un altro; l\'autore sì', function () {
    $autore = visibilitaResidente($this->condominio);
    $autore->givePermissionTo(Permission::CREATE_COMUNICAZIONI->value);
    $c = visibilitaComunicazione($this->condominio, $autore, ['is_published' => false, 'is_approved' => false]);

    $this->actingAs($this->luigi)->get(route('user.comunicazioni.show', $c))->assertForbidden();
    $this->actingAs($autore)->get(route('user.comunicazioni.show', $c))->assertOk();
});

test('Coda 198 — la comunicazione pubblicata e approvata del palazzo si apre e si trova in elenco', function () {
    $c = visibilitaComunicazione($this->condominio, $this->admin);

    $this->actingAs($this->luigi)->get(route('user.comunicazioni.show', $c))->assertOk();
    expect(visibilitaIdComunicazioniInElenco($this, $this->luigi))->toContain($c->id);
});

test('Coda 304 — una comunicazione privata con i destinatari già tolti non la vede il vicino', function () {
    $autore = visibilitaResidente($this->condominio);
    $c = visibilitaComunicazione($this->condominio, $autore, ['is_private' => true]);

    expect(visibilitaIdComunicazioniInElenco($this, $this->luigi))->not->toContain($c->id);
    $this->actingAs($this->luigi)->get(route('user.comunicazioni.show', $c))->assertForbidden();
    $this->actingAs($autore)->get(route('user.comunicazioni.show', $c))->assertOk();

    $dashboard = $this->actingAs($this->luigi)->get(route('user.dashboard'))->assertOk()->viewData('page')['props'];
    expect(collect($dashboard['comunicazioni'])->pluck('id')->all())->not->toContain($c->id);
});

test('Coda 198 — un utente con il permesso di vedere le comunicazioni ma senza anagrafica non apre la scheda e non riceve un errore', function () {
    // Decisione 134.4, come per le segnalazioni qui sopra: prima 403, ora la pagina dei propri dati.
    $senza = User::factory()->create(['email_verified_at' => now()]);
    $senza->assignRole(Role::UTENTE->value);
    $c = visibilitaComunicazione($this->condominio, $this->admin);

    $this->actingAs($senza)->get(route('user.comunicazioni.show', $c))->assertRedirect(route('user.anagrafiche.create'));
    expect(\Illuminate\Support\Facades\Gate::forUser($senza)->denies('show', $c))->toBeTrue();
});

// ─── La griglia: elenco e scheda dicono la stessa cosa ──────────────────────────────────────

test('Coda 304 — per ogni stato, la segnalazione è nell\'elenco del condòmino se e solo se lui la apre (e non è in moderazione)', function () {
    $persone = ['mario' => $this->mario, 'luigi' => $this->luigi, 'anna' => $this->anna];
    $altroPalazzo = Condominio::factory()->create();
    $persone['estraneo'] = visibilitaResidente($altroPalazzo);

    $casi = [];
    foreach ([false, true] as $privata) {
        foreach ([[true, true], [true, false], [false, true], [false, false]] as [$pubblicata, $approvata]) {
            foreach (['nessuno' => [], 'autore' => [$this->mario], 'anna' => [$this->anna]] as $etichetta => $collegate) {
                $s = visibilitaSegnalazione($this->condominio, $this->mario, [
                    'is_private' => $privata, 'is_published' => $pubblicata, 'is_approved' => $approvata,
                ], $collegate);
                $casi[] = [$s, $privata, $pubblicata && $approvata, $etichetta];
            }
        }
    }

    foreach ($persone as $nome => $persona) {
        $inElenco = visibilitaIdSegnalazioniInElenco($this, $persona);

        foreach ($casi as [$s, $privata, $visibileATutti, $etichetta]) {
            $apre = $this->actingAs($persona)->get(route('user.segnalazioni.show', $s))->status() === 200;

            // Quello che la regola vuole, scritto per esteso.
            $indirizzata = $nome === 'mario'
                || ($etichetta === 'anna' && $nome === 'anna')
                || (! $privata && $etichetta === 'nessuno' && $nome !== 'estraneo');
            $atteso = ($visibileATutti || $nome === 'mario') && $indirizzata;

            expect($apre)->toBe($atteso, "scheda: {$nome}, privata=".(int) $privata.", visibile=".(int) $visibileATutti.", collegate={$etichetta}");
            expect(in_array($s->id, $inElenco, true))->toBe($atteso && $visibileATutti, "elenco: {$nome}, privata=".(int) $privata.", visibile=".(int) $visibileATutti.", collegate={$etichetta}");
        }
    }
});

test('Coda 198 — per ogni stato, la comunicazione è nell\'elenco del condòmino se e solo se lui la apre (e non è in moderazione)', function () {
    $autore = visibilitaResidente($this->condominio);
    $persone = ['autore' => $autore, 'luigi' => $this->luigi, 'anna' => $this->anna];

    $casi = [];
    foreach ([false, true] as $privata) {
        foreach ([[true, true], [true, false], [false, true], [false, false]] as [$pubblicata, $approvata]) {
            foreach (['nessuno' => [], 'anna' => [$this->anna]] as $etichetta => $collegate) {
                $c = visibilitaComunicazione($this->condominio, $autore, [
                    'is_private' => $privata, 'is_published' => $pubblicata, 'is_approved' => $approvata,
                ], $collegate);
                $casi[] = [$c, $privata, $pubblicata && $approvata, $etichetta];
            }
        }
    }

    foreach ($persone as $nome => $persona) {
        $inElenco = visibilitaIdComunicazioniInElenco($this, $persona);

        foreach ($casi as [$c, $privata, $visibileATutti, $etichetta]) {
            $apre = $this->actingAs($persona)->get(route('user.comunicazioni.show', $c))->status() === 200;

            $indirizzata = $nome === 'autore'
                || ($etichetta === 'anna' && $nome === 'anna')
                || (! $privata && $etichetta === 'nessuno');
            $atteso = ($visibileATutti || $nome === 'autore') && $indirizzata;

            expect($apre)->toBe($atteso, "scheda: {$nome}, privata=".(int) $privata.", visibile=".(int) $visibileATutti.", collegate={$etichetta}");
            expect(in_array($c->id, $inElenco, true))->toBe($atteso && $visibileATutti, "elenco: {$nome}, privata=".(int) $privata.", visibile=".(int) $visibileATutti.", collegate={$etichetta}");
        }
    }
});
