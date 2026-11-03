<?php

/**
 * # Chi riceve gli avvisi di segnalazioni, comunicazioni e documenti (Code 302 e 198)
 *
 * ## Le regole
 *
 * - **La platea è chi trova l'oggetto nell'elenco del portale** (decisione Q1 del 17/10/2026: la
 *   pivot dice a chi è indirizzato, `is_private` decide la privacy). Una segnalazione privata avvisa
 *   l'autore e le persone collegate; una non privata indirizzata a persone avvisa solo loro; una non
 *   indirizzata, il condominio. Lo stesso in creazione, in modifica (con «avvisa i destinatari» e
 *   nello spostamento in un altro palazzo), nell'approvazione e nell'avviso all'amministratore.
 *   La regola è la stessa per segnalazioni e comunicazioni, private comprese.
 * - **Una bozza o un oggetto in moderazione non avvisa nessuno** (Coda 198): alla creazione niente;
 *   **alla prima pubblicazione** (pubblicata e approvata, anche in modifica) parte da sola l'avviso
 *   «nuovo» a tutta la platea (decisione 126.3), come fa l'approvazione. Senza memoria: rimettere in
 *   bozza e ripubblicare rimanda l'avviso (scelta tecnica: nessuna colonna nuova). Lo stesso
 *   meccanismo vale per segnalazioni e documenti.
 * - Gli amministratori restano avvisati dai loro avvisi («da approvare», «nuova»), che non
 *   passano dalla platea del portale.
 *
 * Le notifiche sono a consenso: ogni persona qui ha acceso le preferenze, così un «non riceve» non
 * passa per la ragione sbagliata.
 */

use App\Enums\NotificationType;
use App\Enums\Permission;
use App\Enums\Role;
use App\Events\Segnalazioni\NotifyAdminOfCreatedSegnalazione;
use App\Listeners\Segnalazioni\SendNewSegnalazioneNotificationToAdmin;
use App\Models\Anagrafica;
use App\Models\CategoriaDocumento;
use App\Models\Comunicazione;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Evento;
use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\Comunicazioni\ApprovedComunicazioneNotification;
use App\Notifications\Comunicazioni\NewComunicazioneNotification;
use App\Notifications\Comunicazioni\UpdatedComunicazioneNotification;
use App\Notifications\Documenti\NewDocumentoNotification;
use App\Notifications\Segnalazioni\ApprovedSegnalazioneNotification;
use App\Notifications\Segnalazioni\NewSegnalazioneNotification;
use App\Notifications\Segnalazioni\UpdatedSegnalazioneNotification;
use App\Services\Notifiche\DestinatariNotifica;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role as SpatieRole;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    Storage::fake('local');
    $this->withoutVite();

    $this->condominio = Condominio::factory()->create();

    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    $this->admin->assignRole(Role::AMMINISTRATORE->value);
    plateaPreferenze($this->admin);

    $this->mario = plateaResidente($this->condominio);
    $this->luigi = plateaResidente($this->condominio);
    $this->anna  = plateaResidente($this->condominio);
});

function plateaPreferenze(User $user): void
{
    foreach (NotificationType::cases() as $tipo) {
        DB::table('notification_preferences')->insert([
            'user_id' => $user->id, 'type' => $tipo->value, 'enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

function plateaResidente(Condominio $condominio, array $permessiInPiu = []): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole(Role::UTENTE->value);
    if ($permessiInPiu !== []) {
        $user->givePermissionTo($permessiInPiu);
    }
    plateaPreferenze($user);
    $anagrafica = Anagrafica::factory()->create(['user_id' => $user->id]);
    $anagrafica->condomini()->attach($condominio->id);

    return $user->fresh();
}

function plateaSegnalazione(Condominio $condominio, User $autore, array $stato = [], array $collegate = []): Segnalazione
{
    $s = Segnalazione::create(array_merge([
        'subject' => 'Ascensore fermo', 'description' => 'Al terzo piano', 'priority' => 'alta', 'stato' => 'aperta',
        'condominio_id' => $condominio->id, 'created_by' => $autore->id,
        'is_private' => false, 'is_published' => true, 'is_approved' => true, 'is_featured' => false, 'can_comment' => false,
    ], $stato));
    if ($collegate !== []) {
        $s->anagrafiche()->attach(array_map(fn (User $u) => $u->anagrafica->id, $collegate));
    }

    return $s->fresh();
}

function plateaModuloSegnalazione(Segnalazione $s, array $cambi = []): array
{
    return array_merge([
        'subject' => $s->subject, 'description' => $s->description, 'priority' => $s->priority, 'stato' => $s->stato,
        'condominio_id' => $s->condominio_id, 'can_comment' => false, 'is_featured' => false,
        'is_published' => (bool) $s->is_published,
        'anagrafiche' => $s->anagrafiche()->pluck('anagrafiche.id')->all(),
        'avvisa_destinatari' => false,
    ], $cambi);
}

function plateaComunicazione(Condominio $condominio, User $autore, array $stato = [], array $collegate = []): Comunicazione
{
    $c = Comunicazione::create(array_merge([
        'subject' => 'Assemblea', 'description' => 'Il 12 alle 18', 'priority' => 'media', 'created_by' => $autore->id,
        'is_featured' => false, 'is_private' => false, 'is_published' => true, 'is_approved' => true, 'can_comment' => false,
    ], $stato));
    $c->condomini()->attach($condominio->id);
    if ($collegate !== []) {
        $c->anagrafiche()->attach(array_map(fn (User $u) => $u->anagrafica->id, $collegate));
    }

    return $c->fresh();
}

function plateaModuloComunicazione(Comunicazione $c, array $cambi = []): array
{
    return array_merge([
        'subject' => $c->subject, 'description' => $c->description, 'priority' => $c->priority,
        'can_comment' => false, 'is_featured' => false, 'is_published' => (bool) $c->is_published,
        'condomini_ids' => $c->condomini()->pluck('condomini.id')->all(),
        'anagrafiche' => $c->anagrafiche()->pluck('anagrafiche.id')->all(),
        'avvisa_destinatari' => false,
    ], $cambi);
}

// ─── Coda 302: segnalazioni, la platea segue la privacy e i destinatari ─────────────────────

test('Coda 302 — una segnalazione creata dall\'amministratore e indirizzata a una persona avvisa solo lei', function () {
    $this->actingAs($this->admin)->post(route('admin.segnalazioni.store'), [
        'subject' => 'Perdita nel box', 'description' => 'Riguarda il box di Anna', 'priority' => 'media', 'stato' => 'aperta',
        'condominio_id' => $this->condominio->id, 'can_comment' => false, 'is_featured' => false, 'is_published' => true,
        'anagrafiche' => [$this->anna->anagrafica->id],
    ])->assertRedirect()->assertSessionHasNoErrors();

    Notification::assertSentTo($this->anna->anagrafica, NewSegnalazioneNotification::class);
    Notification::assertNotSentTo($this->luigi->anagrafica, NewSegnalazioneNotification::class);
    Notification::assertNotSentTo($this->mario->anagrafica, NewSegnalazioneNotification::class);
});

test('Coda 302 — una segnalazione creata dall\'amministratore senza destinatari avvisa il condominio', function () {
    $this->actingAs($this->admin)->post(route('admin.segnalazioni.store'), [
        'subject' => 'Lavori in facciata', 'description' => 'Da lunedì', 'priority' => 'bassa', 'stato' => 'aperta',
        'condominio_id' => $this->condominio->id, 'can_comment' => false, 'is_featured' => false, 'is_published' => true,
        'anagrafiche' => [],
    ])->assertRedirect()->assertSessionHasNoErrors();

    foreach ([$this->mario, $this->luigi, $this->anna] as $persona) {
        Notification::assertSentTo($persona->anagrafica, NewSegnalazioneNotification::class);
    }
});

test('Coda 302 — l\'approvazione di una segnalazione indirizzata a una persona avvisa lei e il creatore, non il vicino', function () {
    $s = plateaSegnalazione($this->condominio, $this->mario, ['is_published' => false, 'is_approved' => false], [$this->anna]);

    $this->actingAs($this->admin)->put(route('admin.segnalazioni.toggle-approval', $s))->assertRedirect();

    Notification::assertSentTo($this->mario, ApprovedSegnalazioneNotification::class);
    Notification::assertSentTo($this->anna->anagrafica, NewSegnalazioneNotification::class);
    Notification::assertNotSentTo($this->luigi->anagrafica, NewSegnalazioneNotification::class);
});

test('Coda 302 — una privata creata da un condòmino che pubblica da sé avvisa gli amministratori, non il palazzo', function () {
    $autore = plateaResidente($this->condominio, [Permission::PUBLISH_SEGNALAZIONI->value]);

    $this->actingAs($autore)->post(route('user.segnalazioni.store'), [
        'subject' => 'Rumori notturni', 'description' => 'Dal piano di sopra', 'priority' => 'media', 'stato' => 'aperta',
        'condominio_id' => $this->condominio->id, 'is_private' => true,
    ])->assertRedirect();

    Notification::assertSentTo($this->admin, NewSegnalazioneNotification::class);
    Notification::assertNotSentTo($this->luigi->anagrafica, NewSegnalazioneNotification::class);
    Notification::assertNotSentTo($this->luigi, NewSegnalazioneNotification::class);
});

test('Coda 302 — la stessa, non privata, avvisa gli amministratori e il palazzo', function () {
    $autore = plateaResidente($this->condominio, [Permission::PUBLISH_SEGNALAZIONI->value]);

    $this->actingAs($autore)->post(route('user.segnalazioni.store'), [
        'subject' => 'Cancello rotto', 'description' => 'Non si chiude', 'priority' => 'alta', 'stato' => 'aperta',
        'condominio_id' => $this->condominio->id, 'is_private' => false,
    ])->assertRedirect();

    Notification::assertSentTo($this->admin, NewSegnalazioneNotification::class);
    Notification::assertSentTo($this->luigi->anagrafica, NewSegnalazioneNotification::class);
});

test('Coda 302 — l\'avviso della segnalazione creata dal portale, che parte in coda, segue la platea di quando parte: indirizzata a Luigi prima del lavoro in coda, non va ad Anna', function () {
    // Il listener dell'avviso all'amministratore è in coda (`ShouldQueue`, l'evento con
    // `SerializesModels`): legge la segnalazione quando il lavoro gira, non quando il condòmino la crea.
    // Se nel frattempo l'amministratore la indirizza a una persona, la platea è quella persona.
    Event::fake([NotifyAdminOfCreatedSegnalazione::class]);
    $autore = plateaResidente($this->condominio, [Permission::PUBLISH_SEGNALAZIONI->value]);

    $this->actingAs($autore)->post(route('user.segnalazioni.store'), [
        'subject' => 'Ascensore fermo', 'description' => "Al terzo piano\nda ieri", 'priority' => 'alta', 'stato' => 'aperta',
        'condominio_id' => $this->condominio->id, 'is_private' => false,
    ])->assertRedirect();
    $s = Segnalazione::query()->where('created_by', $autore->id)->latest('id')->firstOrFail();
    expect((bool) $s->is_approved && (bool) $s->is_published)->toBeTrue();
    Event::assertDispatched(NotifyAdminOfCreatedSegnalazione::class);

    $s->anagrafiche()->attach($this->luigi->anagrafica->id);

    // Il lavoro in coda: l'evento si ricostruisce dal database.
    app(SendNewSegnalazioneNotificationToAdmin::class)->handle(new NotifyAdminOfCreatedSegnalazione($s->fresh()));

    Notification::assertSentTo($this->admin, NewSegnalazioneNotification::class);
    Notification::assertSentTo($this->luigi->anagrafica, NewSegnalazioneNotification::class);
    Notification::assertNotSentTo($this->anna->anagrafica, NewSegnalazioneNotification::class);
    Notification::assertNotSentTo($autore->anagrafica, NewSegnalazioneNotification::class);
});

test('Coda 302 — «avvisa i destinatari» su una segnalazione indirizzata a una persona avvisa solo lei', function () {
    $s = plateaSegnalazione($this->condominio, $this->admin, [], [$this->anna]);

    $this->actingAs($this->admin)
        ->put(route('admin.segnalazioni.update', $s), plateaModuloSegnalazione($s, ['avvisa_destinatari' => true]))
        ->assertRedirect()->assertSessionHasNoErrors();

    Notification::assertSentTo($this->anna->anagrafica, UpdatedSegnalazioneNotification::class);
    Notification::assertNotSentTo($this->luigi->anagrafica, UpdatedSegnalazioneNotification::class);
});

test('Coda 302 — la platea calcolata segue la privacy e i destinatari, come l\'elenco del portale', function () {
    $risolutore = app(DestinatariNotifica::class);
    $ids = fn (array $persone) => collect($persone)->map(fn (User $u) => $u->anagrafica->id)->sort()->values()->all();
    $platea = fn (Segnalazione $s) => $risolutore->perModello($s->fresh())->sort()->values()->all();

    expect($platea(plateaSegnalazione($this->condominio, $this->admin)))->toBe($ids([$this->mario, $this->luigi, $this->anna]))
        ->and($platea(plateaSegnalazione($this->condominio, $this->admin, [], [$this->anna])))->toBe($ids([$this->anna]))
        ->and($platea(plateaSegnalazione($this->condominio, $this->mario, ['is_private' => true], [$this->mario])))->toBe($ids([$this->mario]))
        // la privata con i residenti già tolti: il solo autore
        ->and($platea(plateaSegnalazione($this->condominio, $this->mario, ['is_private' => true])))->toBe($ids([$this->mario]))
        ->and($platea(plateaSegnalazione($this->condominio, $this->mario, ['is_private' => true], [$this->anna])))->toBe($ids([$this->mario, $this->anna]))
        // in moderazione o in bozza: nessuno
        ->and($platea(plateaSegnalazione($this->condominio, $this->mario, ['is_published' => false, 'is_approved' => false])))->toBe([])
        ->and($platea(plateaSegnalazione($this->condominio, $this->admin, ['is_published' => false])))->toBe([]);
});

test('Coda 302 — la platea di ogni segnalazione è esattamente chi la trova nell\'elenco del portale', function () {
    $persone = [$this->mario, $this->luigi, $this->anna];
    $risolutore = app(DestinatariNotifica::class);

    $segnalazioni = [];
    foreach ([false, true] as $privata) {
        foreach ([[true, true], [false, true], [true, false]] as [$pubblicata, $approvata]) {
            foreach ([[], [$this->mario], [$this->anna], [$this->luigi, $this->anna]] as $collegate) {
                $segnalazioni[] = plateaSegnalazione($this->condominio, $this->mario, [
                    'is_private' => $privata, 'is_published' => $pubblicata, 'is_approved' => $approvata,
                ], $collegate);
            }
        }
    }

    foreach ($persone as $persona) {
        $elenco = collect($this->actingAs($persona)->get(route('user.segnalazioni.index', ['per_page' => 100]))
            ->viewData('page')['props']['segnalazioni']['data'])->pluck('id')->all();

        foreach ($segnalazioni as $s) {
            expect($risolutore->perModello($s)->contains($persona->anagrafica->id))
                ->toBe(in_array($s->id, $elenco, true), "segnalazione {$s->id}, persona {$persona->id}");
        }
    }
});

// ─── Coda 198: le bozze non avvisano; la prima pubblicazione sì ─────────────────────────────

test('Coda 198 — una comunicazione creata in bozza non avvisa nessuno', function () {
    $this->actingAs($this->admin)->post(route('admin.comunicazioni.store'), [
        'subject' => 'Bozza riservata', 'description' => 'Da rivedere', 'priority' => 'media',
        'can_comment' => false, 'is_featured' => false, 'is_published' => false,
        'condomini_ids' => [$this->condominio->id], 'anagrafiche' => [],
    ])->assertRedirect()->assertSessionHasNoErrors();

    Notification::assertNotSentTo($this->luigi->anagrafica, NewComunicazioneNotification::class);
    Notification::assertNotSentTo($this->mario->anagrafica, NewComunicazioneNotification::class);
});

test('Coda 198 — una comunicazione creata pubblicata avvisa il condominio', function () {
    $this->actingAs($this->admin)->post(route('admin.comunicazioni.store'), [
        'subject' => 'Assemblea', 'description' => 'Il 12 alle 18', 'priority' => 'media',
        'can_comment' => false, 'is_featured' => false, 'is_published' => true,
        'condomini_ids' => [$this->condominio->id], 'anagrafiche' => [],
    ])->assertRedirect()->assertSessionHasNoErrors();

    Notification::assertSentTo($this->luigi->anagrafica, NewComunicazioneNotification::class);
});

test('Coda 198 — pubblicare in modifica una bozza avvisa da sola tutta la platea, e ripubblicarla la riavvisa', function () {
    $c = plateaComunicazione($this->condominio, $this->admin, ['is_published' => false]);

    $this->actingAs($this->admin)
        ->put(route('admin.comunicazioni.update', $c), plateaModuloComunicazione($c, ['is_published' => true]))
        ->assertSessionHasNoErrors();

    Notification::assertSentToTimes($this->luigi->anagrafica, NewComunicazioneNotification::class, 1);
    Notification::assertSentToTimes($this->anna->anagrafica, NewComunicazioneNotification::class, 1);

    // Rimessa in bozza: nessun avviso, neanche con la casella.
    $this->actingAs($this->admin)
        ->put(route('admin.comunicazioni.update', $c), plateaModuloComunicazione($c->fresh(), ['is_published' => false, 'avvisa_destinatari' => true]))
        ->assertSessionHasNoErrors();
    Notification::assertNotSentTo($this->luigi->anagrafica, UpdatedComunicazioneNotification::class);

    // Ripubblicata: senza memoria, l'avviso riparte.
    $this->actingAs($this->admin)
        ->put(route('admin.comunicazioni.update', $c), plateaModuloComunicazione($c->fresh(), ['is_published' => true]))
        ->assertSessionHasNoErrors();
    Notification::assertSentToTimes($this->luigi->anagrafica, NewComunicazioneNotification::class, 2);
});

test('Coda 198 — la modifica di una bozza non avvisa nessuno, neanche i destinatari aggiunti o con la casella', function () {
    $c = plateaComunicazione($this->condominio, $this->admin, ['is_published' => false], [$this->mario]);

    $this->actingAs($this->admin)
        ->put(route('admin.comunicazioni.update', $c), plateaModuloComunicazione($c, [
            'anagrafiche' => [$this->mario->anagrafica->id, $this->anna->anagrafica->id],
            'avvisa_destinatari' => true,
        ]))->assertSessionHasNoErrors();

    Notification::assertNothingSentTo($this->anna->anagrafica);
    Notification::assertNothingSentTo($this->mario->anagrafica);
});

test('Coda 198 — l\'approvazione di una comunicazione indirizzata a una persona avvisa lei, non il vicino', function () {
    $autore = plateaResidente($this->condominio, [Permission::CREATE_COMUNICAZIONI->value]);
    $c = plateaComunicazione($this->condominio, $autore, ['is_published' => false, 'is_approved' => false], [$this->anna]);

    $this->actingAs($this->admin)->put(route('admin.comunicazioni.toggle-approval', $c))->assertRedirect();

    Notification::assertSentTo($autore, ApprovedComunicazioneNotification::class);
    Notification::assertSentTo($this->anna->anagrafica, NewComunicazioneNotification::class);
    Notification::assertNotSentTo($this->luigi->anagrafica, NewComunicazioneNotification::class);
});

test('Coda 198 — pubblicare in modifica una segnalazione in bozza avvisa da sola il condominio', function () {
    $s = plateaSegnalazione($this->condominio, $this->admin, ['is_published' => false]);

    $this->actingAs($this->admin)
        ->put(route('admin.segnalazioni.update', $s), plateaModuloSegnalazione($s, ['is_published' => true]))
        ->assertSessionHasNoErrors();

    Notification::assertSentTo($this->luigi->anagrafica, NewSegnalazioneNotification::class);
});

test('Coda 198 — una segnalazione creata in bozza non avvisa nessuno', function () {
    $this->actingAs($this->admin)->post(route('admin.segnalazioni.store'), [
        'subject' => 'Bozza', 'description' => 'Da completare', 'priority' => 'bassa', 'stato' => 'aperta',
        'condominio_id' => $this->condominio->id, 'can_comment' => false, 'is_featured' => false, 'is_published' => false,
        'anagrafiche' => [],
    ])->assertRedirect()->assertSessionHasNoErrors();

    Notification::assertNotSentTo($this->luigi->anagrafica, NewSegnalazioneNotification::class);
});

test('Coda 198 — un documento caricato in bozza non avvisa nessuno; pubblicato in modifica avvisa il condominio', function () {
    $categoria = CategoriaDocumento::create(['name' => 'Verbali', 'description' => 'Assemblee']);

    $this->actingAs($this->admin)->post(route('admin.documenti.store'), [
        'name' => 'Verbale in preparazione', 'description' => 'Bozza riservata', 'categorie' => [$categoria->id],
        'is_published' => false, 'condomini_ids' => [$this->condominio->id], 'anagrafiche' => [],
        'file' => UploadedFile::fake()->create('verbale.pdf', 12, 'application/pdf'),
    ])->assertRedirect()->assertSessionHasNoErrors();

    Notification::assertNotSentTo($this->luigi->anagrafica, NewDocumentoNotification::class);

    $d = Documento::latest('id')->firstOrFail();
    $this->actingAs($this->admin)
        ->put(route('admin.documenti.update', $d), ['is_published' => true])
        ->assertSessionHasNoErrors();

    Notification::assertSentTo($this->luigi->anagrafica, NewDocumentoNotification::class);
});

test('Coda 198 — l\'approvazione di un documento indirizzato a una persona avvisa lei, non il vicino', function () {
    $d = Documento::create([
        'name' => 'Contratto del box', 'description' => 'Riguarda solo Anna', 'created_by' => $this->admin->id,
        'is_published' => false, 'is_approved' => false,
        'path' => 'documenti/prova.pdf', 'mime_type' => 'application/pdf', 'file_size' => 10,
    ]);
    $d->condomini()->attach($this->condominio->id);
    $d->anagrafiche()->attach($this->anna->anagrafica->id);

    $this->actingAs($this->admin)->put(route('admin.documenti.toggle-approval', $d))->assertRedirect();

    Notification::assertSentTo($this->anna->anagrafica, NewDocumentoNotification::class);
    Notification::assertNotSentTo($this->luigi->anagrafica, NewDocumentoNotification::class);
});

// ─── Gli altri canali ───────────────────────────────────────────────────────────────────────

test('Coda 302 — gli avvisi di segnalazioni e comunicazioni viaggiano solo per mail, e la modifica non crea eventi nell\'Inbox', function () {
    foreach ([NewSegnalazioneNotification::class, UpdatedSegnalazioneNotification::class, ApprovedSegnalazioneNotification::class,
              NewComunicazioneNotification::class, UpdatedComunicazioneNotification::class] as $classe) {
        $riflessione = new ReflectionClass($classe);
        $istanza = $riflessione->newInstanceWithoutConstructor();
        expect($istanza->via($this->luigi))->toBe(['mail'], $classe);
    }

    $s = plateaSegnalazione($this->condominio, $this->mario, ['is_private' => true], [$this->mario]);
    $prima = Evento::count();
    $this->actingAs($this->admin)
        ->put(route('admin.segnalazioni.update', $s), plateaModuloSegnalazione($s, ['avvisa_destinatari' => true]))
        ->assertSessionHasNoErrors();

    expect(Evento::count())->toBe($prima);
});
