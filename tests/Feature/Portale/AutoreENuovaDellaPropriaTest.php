<?php

/**
 * # L'autore non riceve «nuova segnalazione» (o «nuova comunicazione», o «nuovo documento») del proprio (Code 302 e 198)
 *
 * Dopo una modifica i nuovi arrivati nella platea ricevono l'avviso di **creazione**, perché per loro
 * l'oggetto è nuovo davvero (`avvisaDopoLaModifica`). Per l'autore non lo è mai: l'ha scritto lui. Ci
 * entra quando una modifica lo porta nella sua platea — l'amministratore la sposta nel suo palazzo,
 * oppure pubblica una bozza già approvata (la platea passa da vuota a piena) — e fino a qui riceveva
 * l'annuncio della propria segnalazione come se fosse di un altro. L'approvazione dall'interruttore
 * lo esclude già (`SendApprovedSegnalazioneNotificationToUser`): l'autore riceve «approvata», non
 * «nuova». La modifica fa ora lo stesso.
 *
 * Chi c'era già, autore compreso, riceve l'avviso di **modifica** quando chi salva lo chiede: quello
 * non cambia.
 *
 * I documenti hanno la stessa regola (`Documenti\DocumentoController::avvisaDopoLaModifica`, gemella di
 * quella delle comunicazioni; l'approvazione lo esclude già in `SendApprovedDocumentoNotificationToUser`).
 *
 * Rimesso in bozza e ripubblicato, l'oggetto torna «nuovo» per la platea (Coda 198, senza memoria): per
 * l'autore no. E la creazione dal pannello: chi crea un oggetto dal pannello e ha un'anagrafica nel
 * palazzo non riceve l'annuncio del proprio (`SendNew*NotificationToUser`), come chi lo crea dal portale.
 */

use App\Enums\NotificationType;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\CategoriaDocumento;
use App\Models\Comunicazione;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\Comunicazioni\NewComunicazioneNotification;
use App\Notifications\Comunicazioni\UpdatedComunicazioneNotification;
use App\Notifications\Documenti\NewDocumentoNotification;
use App\Notifications\Documenti\UpdatedDocumentoNotification;
use App\Notifications\Segnalazioni\NewSegnalazioneNotification;
use App\Notifications\Segnalazioni\UpdatedSegnalazioneNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    $this->withoutVite();

    $this->aurora = Condominio::factory()->create(['nome' => 'Condominio Aurora']);
    $this->borea  = Condominio::factory()->create(['nome' => 'Condominio Borea']);

    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    $this->admin->assignRole(Role::AMMINISTRATORE->value);

    // Mario è l'autore e sta nell'Aurora; Luigi è un vicino dell'Aurora.
    $this->mario = propriaResidente([$this->aurora->id], 'Mario Rossi', [Permission::CREATE_COMUNICAZIONI->value]);
    $this->luigi = propriaResidente([$this->aurora->id], 'Luigi Verdi');
});

function propriaResidente(array $condominiIds, string $nome, array $permessiInPiu = []): User
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

function propriaSegnalazione(Condominio $condominio, User $autore, array $stato = []): Segnalazione
{
    return Segnalazione::create(array_merge([
        'subject' => "Il cancello dell'ingresso «B»", 'description' => "Non chiude\nda due giorni",
        'priority' => 'media', 'stato' => 'aperta', 'condominio_id' => $condominio->id, 'created_by' => $autore->id,
        'is_private' => false, 'is_published' => true, 'is_approved' => true, 'is_featured' => false, 'can_comment' => true,
    ], $stato))->fresh();
}

function propriaComunicazione(Condominio $condominio, User $autore, array $stato = []): Comunicazione
{
    $c = Comunicazione::create(array_merge([
        'subject' => 'Turni delle pulizie', 'description' => 'Il nuovo calendario', 'priority' => 'media',
        'created_by' => $autore->id, 'is_featured' => false, 'is_private' => false, 'is_published' => true,
        'is_approved' => true, 'can_comment' => false,
    ], $stato));
    $c->condomini()->attach($condominio->id);

    return $c->fresh();
}

function proprioDocumento(Condominio $condominio, User $autore, array $stato = []): Documento
{
    $d = Documento::create(array_merge([
        'name' => "Verbale dell'assemblea «straordinaria»", 'description' => "Prima riga\nseconda riga", 'created_by' => $autore->id,
        'is_published' => true, 'is_approved' => true, 'path' => 'documenti/verbale.pdf', 'mime_type' => 'application/pdf', 'file_size' => 10,
    ], $stato));
    $d->condomini()->attach($condominio->id);
    $d->categorie()->attach(CategoriaDocumento::create(['name' => 'Verbali', 'description' => 'Le assemblee'])->id);

    return $d->fresh();
}

/** Il corpo della modifica dal pannello, come lo manda `documenti/DocumentiEdit.vue` senza un file nuovo, con i cambi del caso. */
function proprioCorpoDocumento(Documento $d, array $cambi = []): array
{
    return array_merge([
        'name' => $d->name, 'description' => $d->description, 'is_published' => (bool) $d->is_published,
        'condomini_ids' => $d->condomini()->pluck('condomini.id')->all(), 'anagrafiche' => $d->anagrafiche()->pluck('anagrafiche.id')->all(),
        'categorie' => $d->categorie()->pluck('categorie_documento.id')->all(), 'file' => null, 'avvisa_destinatari' => false,
        'anagrafiche_nel_modulo' => true,
    ], $cambi);
}

/** Il corpo della modifica dal pannello, come lo manda `SegnalazioniEdit.vue`, con i cambi del caso. */
function propriaCorpoSegnalazione(Segnalazione $s, array $cambi = []): array
{
    return array_merge([
        'subject' => $s->subject, 'description' => $s->description, 'priority' => $s->priority, 'stato' => $s->stato,
        'condominio_id' => $s->condominio_id, 'can_comment' => (bool) $s->can_comment, 'is_featured' => (bool) $s->is_featured,
        'is_published' => (bool) $s->is_published, 'anagrafiche' => [], 'avvisa_destinatari' => false,
    ], $cambi);
}

/** Il corpo della modifica dal pannello, come lo manda `ComunicazioniEdit.vue`, con i cambi del caso. */
function propriaCorpoComunicazione(Comunicazione $c, array $cambi = []): array
{
    return array_merge([
        'subject' => $c->subject, 'description' => $c->description, 'priority' => $c->priority, 'stato' => '',
        'can_comment' => (bool) $c->can_comment, 'is_featured' => (bool) $c->is_featured, 'is_published' => (bool) $c->is_published,
        'condomini_ids' => $c->condomini()->pluck('condomini.id')->all(), 'anagrafiche' => [], 'avvisa_destinatari' => false,
    ], $cambi);
}

// ─── Segnalazioni ───────────────────────────────────────────────────────────────────────────

test('Coda 302 — la segnalazione spostata nel palazzo del suo autore non gli manda «nuova segnalazione» della propria', function () {
    // In `avvisaDopoLaModifica` fra i «nuovi» non c'è l'anagrafica del creatore, come in
    // `SendApprovedSegnalazioneNotificationToUser`. Luigi, che sta nell'Aurora, la riceve: per lui è
    // nuova davvero.
    $s = propriaSegnalazione($this->borea, $this->mario);

    $this->actingAs($this->admin)->put(route('admin.segnalazioni.update', $s), propriaCorpoSegnalazione($s, ['condominio_id' => $this->aurora->id]))
        ->assertRedirect(route('admin.segnalazioni.index'))->assertSessionHasNoErrors();

    Notification::assertSentTo($this->luigi->anagrafica, NewSegnalazioneNotification::class);
    Notification::assertNotSentTo($this->mario->anagrafica, NewSegnalazioneNotification::class);
});

test('Coda 198 — la bozza approvata pubblicata in modifica non manda «nuova segnalazione» al suo autore', function () {
    // La platea passa da vuota a piena e parte l'avviso «nuova» a tutti; l'autore no.
    $s = propriaSegnalazione($this->aurora, $this->mario, ['is_published' => false, 'is_approved' => true]);

    $this->actingAs($this->admin)->put(route('admin.segnalazioni.update', $s), propriaCorpoSegnalazione($s, ['is_published' => true]))
        ->assertRedirect(route('admin.segnalazioni.index'))->assertSessionHasNoErrors();

    Notification::assertSentTo($this->luigi->anagrafica, NewSegnalazioneNotification::class);
    Notification::assertNotSentTo($this->mario->anagrafica, NewSegnalazioneNotification::class);
});

test('Coda 302 — chi c\'era già, autore compreso, riceve ancora l\'avviso di modifica quando chi salva lo chiede', function () {
    // La correzione tocca solo l'avviso «nuova»: l'avviso «modifica» resta com'era.
    $s = propriaSegnalazione($this->aurora, $this->mario);

    $this->actingAs($this->admin)->put(route('admin.segnalazioni.update', $s), propriaCorpoSegnalazione($s, [
        'description' => 'Il fabbro passa domani', 'avvisa_destinatari' => true,
    ]))->assertSessionHasNoErrors();

    Notification::assertSentTo($this->mario->anagrafica, UpdatedSegnalazioneNotification::class);
    Notification::assertSentTo($this->luigi->anagrafica, UpdatedSegnalazioneNotification::class);
    Notification::assertNotSentTo($this->mario->anagrafica, NewSegnalazioneNotification::class);
});

test('Coda 302 — la segnalazione di un altro spostata nel palazzo di Mario gli manda «nuova segnalazione»', function () {
    // Il caso vicino: l'esclusione è per l'autore, non per chi cambia palazzo con lei.
    $s = propriaSegnalazione($this->borea, $this->luigi);

    $this->actingAs($this->admin)->put(route('admin.segnalazioni.update', $s), propriaCorpoSegnalazione($s, ['condominio_id' => $this->aurora->id]))
        ->assertSessionHasNoErrors();

    Notification::assertSentTo($this->mario->anagrafica, NewSegnalazioneNotification::class);
});

// ─── Comunicazioni ──────────────────────────────────────────────────────────────────────────

test('Coda 302 — la comunicazione spostata nel palazzo del suo autore non gli manda «nuova comunicazione» della propria', function () {
    $c = propriaComunicazione($this->borea, $this->mario);

    $this->actingAs($this->admin)->put(route('admin.comunicazioni.update', $c), propriaCorpoComunicazione($c, ['condomini_ids' => [$this->aurora->id]]))
        ->assertRedirect(route('admin.comunicazioni.index'))->assertSessionHasNoErrors();

    Notification::assertSentTo($this->luigi->anagrafica, NewComunicazioneNotification::class);
    Notification::assertNotSentTo($this->mario->anagrafica, NewComunicazioneNotification::class);
});

test('Coda 198 — la bozza approvata pubblicata in modifica non manda «nuova comunicazione» al suo autore', function () {
    $c = propriaComunicazione($this->aurora, $this->mario, ['is_published' => false, 'is_approved' => true]);

    $this->actingAs($this->admin)->put(route('admin.comunicazioni.update', $c), propriaCorpoComunicazione($c, ['is_published' => true]))
        ->assertRedirect(route('admin.comunicazioni.index'))->assertSessionHasNoErrors();

    Notification::assertSentTo($this->luigi->anagrafica, NewComunicazioneNotification::class);
    Notification::assertNotSentTo($this->mario->anagrafica, NewComunicazioneNotification::class);
});

test('Coda 302 — l\'autore di una comunicazione riceve ancora l\'avviso di modifica quando chi salva lo chiede', function () {
    $c = propriaComunicazione($this->aurora, $this->mario);

    $this->actingAs($this->admin)->put(route('admin.comunicazioni.update', $c), propriaCorpoComunicazione($c, [
        'description' => 'Il calendario corretto', 'avvisa_destinatari' => true,
    ]))->assertSessionHasNoErrors();

    Notification::assertSentTo($this->mario->anagrafica, UpdatedComunicazioneNotification::class);
    Notification::assertNotSentTo($this->mario->anagrafica, NewComunicazioneNotification::class);
});

test('Coda 302 — la comunicazione di un altro spostata nel palazzo di Mario gli manda «nuova comunicazione»', function () {
    $carla = propriaResidente([$this->borea->id], 'Carla Bianchi', [Permission::CREATE_COMUNICAZIONI->value]);
    $c = propriaComunicazione($this->borea, $carla);

    $this->actingAs($this->admin)->put(route('admin.comunicazioni.update', $c), propriaCorpoComunicazione($c, ['condomini_ids' => [$this->aurora->id]]))
        ->assertSessionHasNoErrors();

    Notification::assertSentTo($this->mario->anagrafica, NewComunicazioneNotification::class);
});

// ─── Documenti ──────────────────────────────────────────────────────────────────────────────

/** L'archivio su un disco finto, e l'autore con il permesso di caricare documenti dal portale. */
function prontiIDocumenti(User $autore): void
{
    config(['kondomanager.disco_documenti' => 'local']);
    Storage::fake('local');
    $autore->givePermissionTo(Permission::CREATE_ARCHIVE_DOCUMENTS->value);
}

test('Coda 302 — il documento spostato nel palazzo del suo autore non gli manda «nuovo documento» del proprio', function () {
    // La stessa regola delle segnalazioni e delle comunicazioni: fra i «nuovi» non c'è l'anagrafica del
    // creatore, come in `SendApprovedDocumentoNotificationToUser`. Luigi, che sta nell'Aurora, lo riceve.
    prontiIDocumenti($this->mario);
    $d = proprioDocumento($this->borea, $this->mario);

    $this->actingAs($this->admin)->put(route('admin.documenti.update', $d), proprioCorpoDocumento($d, ['condomini_ids' => [$this->aurora->id]]))
        ->assertRedirect(route('admin.documenti.index'))->assertSessionHasNoErrors();

    Notification::assertSentTo($this->luigi->anagrafica, NewDocumentoNotification::class);
    Notification::assertNotSentTo($this->mario->anagrafica, NewDocumentoNotification::class);
});

test('Coda 198 — il documento approvato in bozza pubblicato in modifica non manda «nuovo documento» al suo autore', function () {
    // La platea passa da vuota a piena e parte l'avviso «nuovo» a tutti; l'autore no.
    prontiIDocumenti($this->mario);
    $d = proprioDocumento($this->aurora, $this->mario, ['is_published' => false, 'is_approved' => true]);

    $this->actingAs($this->admin)->put(route('admin.documenti.update', $d), proprioCorpoDocumento($d, ['is_published' => true]))
        ->assertRedirect(route('admin.documenti.index'))->assertSessionHasNoErrors();

    Notification::assertSentTo($this->luigi->anagrafica, NewDocumentoNotification::class);
    Notification::assertNotSentTo($this->mario->anagrafica, NewDocumentoNotification::class);
});

test('Coda 302 — il documento indirizzato a persone che aggiunge l\'autore fra i destinatari non gli manda «nuovo documento» del proprio', function () {
    // Il documento è indirizzato a Carla (Borea); la modifica aggiunge Luigi e Mario, che stanno nell'Aurora:
    // Luigi lo riceve, Mario no.
    prontiIDocumenti($this->mario);
    $carla = propriaResidente([$this->borea->id], 'Carla Bianchi');
    $d = proprioDocumento($this->borea, $this->mario);
    $d->anagrafiche()->attach($carla->anagrafica->id);

    $this->actingAs($this->admin)->put(route('admin.documenti.update', $d), proprioCorpoDocumento($d, [
        'anagrafiche' => [$carla->anagrafica->id, $this->luigi->anagrafica->id, $this->mario->anagrafica->id],
    ]))->assertSessionHasNoErrors();

    expect($d->fresh()->anagrafiche()->pluck('anagrafiche.id')->sort()->values()->all())
        ->toBe(collect([$carla->anagrafica->id, $this->luigi->anagrafica->id, $this->mario->anagrafica->id])->sort()->values()->all());
    Notification::assertSentTo($this->luigi->anagrafica, NewDocumentoNotification::class);
    Notification::assertNotSentTo($this->mario->anagrafica, NewDocumentoNotification::class);
    Notification::assertNotSentTo($carla->anagrafica, NewDocumentoNotification::class);
});

test('Coda 302 — l\'autore di un documento riceve ancora l\'avviso di modifica quando chi salva lo chiede', function () {
    // La correzione tocca solo l'avviso «nuovo»: l'avviso «modifica» resta com'era, anche all'autore.
    prontiIDocumenti($this->mario);
    $d = proprioDocumento($this->aurora, $this->mario);

    $this->actingAs($this->admin)->put(route('admin.documenti.update', $d), proprioCorpoDocumento($d, [
        'description' => 'Il verbale corretto', 'avvisa_destinatari' => true,
    ]))->assertSessionHasNoErrors();

    Notification::assertSentTo($this->mario->anagrafica, UpdatedDocumentoNotification::class);
    Notification::assertSentTo($this->luigi->anagrafica, UpdatedDocumentoNotification::class);
    Notification::assertNotSentTo($this->mario->anagrafica, NewDocumentoNotification::class);
});

test('Coda 302 — il documento di un altro spostato nel palazzo di Mario gli manda «nuovo documento»', function () {
    // Il caso vicino: l'esclusione è per l'autore, non per chi cambia palazzo con il documento, né per chi lo modifica.
    $carla = propriaResidente([$this->borea->id], 'Carla Bianchi');
    prontiIDocumenti($carla);
    $d = proprioDocumento($this->borea, $carla);

    $this->actingAs($this->admin)->put(route('admin.documenti.update', $d), proprioCorpoDocumento($d, ['condomini_ids' => [$this->aurora->id]]))
        ->assertSessionHasNoErrors();

    Notification::assertSentTo($this->mario->anagrafica, NewDocumentoNotification::class);
    Notification::assertSentTo($this->luigi->anagrafica, NewDocumentoNotification::class);
});

// ─── Rimessa in bozza e ripubblicata ────────────────────────────────────────────────────────

test('Coda 198 — la segnalazione rimessa in bozza e ripubblicata dal pannello non manda «nuova segnalazione» al suo autore; al vicino sì', function () {
    $s = propriaSegnalazione($this->aurora, $this->mario);

    $this->actingAs($this->admin)->put(route('admin.segnalazioni.update', $s), propriaCorpoSegnalazione($s, ['is_published' => false]))
        ->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->put(route('admin.segnalazioni.update', $s), propriaCorpoSegnalazione($s->fresh(), ['is_published' => true]))
        ->assertSessionHasNoErrors();

    Notification::assertNotSentTo($this->mario->anagrafica, NewSegnalazioneNotification::class);
    Notification::assertSentTo($this->luigi->anagrafica, NewSegnalazioneNotification::class);
});

test('Coda 198 — la comunicazione rimessa in bozza e ripubblicata dal pannello non manda «nuova comunicazione» al suo autore; al vicino sì', function () {
    $c = propriaComunicazione($this->aurora, $this->mario);

    $this->actingAs($this->admin)->put(route('admin.comunicazioni.update', $c), propriaCorpoComunicazione($c, ['is_published' => false]))
        ->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->put(route('admin.comunicazioni.update', $c), propriaCorpoComunicazione($c->fresh(), ['is_published' => true]))
        ->assertSessionHasNoErrors();

    Notification::assertNotSentTo($this->mario->anagrafica, NewComunicazioneNotification::class);
    Notification::assertSentTo($this->luigi->anagrafica, NewComunicazioneNotification::class);
});

// ─── Creazione dal pannello ─────────────────────────────────────────────────────────────────

/** Un amministratore che abita nell'Aurora: ha un'anagrafica lì, con gli avvisi accesi. */
function propriaAmministratoreNelPalazzo(Condominio $condominio): User
{
    $admin = propriaResidente([$condominio->id], "Paola D'Angelo");
    $admin->syncRoles([Role::AMMINISTRATORE->value]);

    return $admin->fresh();
}

test('Coda 302 — chi crea un documento dal pannello e abita nel palazzo non riceve «nuovo documento» del proprio; i vicini sì', function () {
    $paola = propriaAmministratoreNelPalazzo($this->aurora);
    prontiIDocumenti($paola);
    $categoria = CategoriaDocumento::create(['name' => 'Verbali', 'description' => 'Le assemblee']);

    $this->actingAs($paola)->post(route('admin.documenti.store'), [
        'name' => "Verbale dell'assemblea «ordinaria»", 'description' => "Prima riga\nseconda riga", 'condomini_ids' => [$this->aurora->id],
        'is_published' => '1', 'categorie' => [$categoria->id], 'file' => UploadedFile::fake()->create('verbale.pdf', 12, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    Notification::assertNotSentTo($paola->anagrafica, NewDocumentoNotification::class);
    Notification::assertSentTo($this->luigi->anagrafica, NewDocumentoNotification::class);
    Notification::assertSentTo($this->mario->anagrafica, NewDocumentoNotification::class);
});

test('Coda 302 — chi crea una comunicazione dal pannello e abita nel palazzo non riceve «nuova comunicazione» della propria; i vicini sì', function () {
    $paola = propriaAmministratoreNelPalazzo($this->aurora);

    $this->actingAs($paola)->post(route('admin.comunicazioni.store'), [
        'subject' => "L'assemblea di giugno", 'description' => "Ordine del giorno\nin allegato", 'priority' => 'media',
        'can_comment' => false, 'is_featured' => false, 'is_published' => true, 'condomini_ids' => [$this->aurora->id], 'anagrafiche' => [],
    ])->assertSessionHasNoErrors();

    Notification::assertNotSentTo($paola->anagrafica, NewComunicazioneNotification::class);
    Notification::assertSentTo($this->luigi->anagrafica, NewComunicazioneNotification::class);
});

test('Coda 302 — chi crea una segnalazione dal pannello e abita nel palazzo non riceve «nuova segnalazione» della propria; i vicini sì', function () {
    $paola = propriaAmministratoreNelPalazzo($this->aurora);

    $this->actingAs($paola)->post(route('admin.segnalazioni.store'), [
        'subject' => "L'ascensore «B» è fermo", 'description' => "Dal terzo piano\nnon scende", 'priority' => 'alta', 'stato' => 'aperta',
        'condominio_id' => $this->aurora->id, 'can_comment' => true, 'is_featured' => false, 'is_published' => true, 'anagrafiche' => [],
    ])->assertSessionHasNoErrors();

    Notification::assertNotSentTo($paola->anagrafica, NewSegnalazioneNotification::class);
    Notification::assertSentTo($this->luigi->anagrafica, NewSegnalazioneNotification::class);
});

test('Coda 302 — creato dal pannello e indirizzato anche a chi lo crea, l\'avviso «nuovo» va solo agli altri destinatari', function () {
    // Il caso vicino: l'esclusione è per chi lo crea, anche quando è fra i destinatari scelti.
    $paola = propriaAmministratoreNelPalazzo($this->aurora);

    $this->actingAs($paola)->post(route('admin.comunicazioni.store'), [
        'subject' => 'Turni delle pulizie', 'description' => 'Il nuovo calendario', 'priority' => 'media',
        'can_comment' => false, 'is_featured' => false, 'is_published' => true, 'condomini_ids' => [$this->aurora->id],
        'anagrafiche' => [$paola->anagrafica->id, $this->luigi->anagrafica->id],
    ])->assertSessionHasNoErrors();

    Notification::assertNotSentTo($paola->anagrafica, NewComunicazioneNotification::class);
    Notification::assertSentTo($this->luigi->anagrafica, NewComunicazioneNotification::class);
    Notification::assertNotSentTo($this->mario->anagrafica, NewComunicazioneNotification::class);
});
