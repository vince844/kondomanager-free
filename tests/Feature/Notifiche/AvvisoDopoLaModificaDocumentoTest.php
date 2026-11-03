<?php

/**
 * # La terza porta: i documenti d'archivio
 *
 * Stessa forma delle comunicazioni — platea *scelta*, o elenco di anagrafiche o condomìni
 * collegati — e stessa correzione. Il file esiste lo stesso, e non è ridondanza: è la lezione
 * della beta.62, dove una correzione applicata a un controller e non al suo gemello è tornata dal
 * forum tre mesi e mezzo dopo. *Il test si scrive per rotta*, perché è la simmetria fra le porte a
 * essere l'invariante, e una simmetria che nessun test guarda dura fino alla prima modifica.
 *
 * Le asserzioni sul comportamento condiviso — chi c'era già non riceve un secondo «nuovo», il
 * nuovo arrivato non riceve «aggiornato» — stanno per esteso in `AvvisoDopoLaModificaTest.php`.
 * Qui si prova che **questa porta** fa la stessa cosa.
 */

use App\Enums\NotificationType;
use App\Enums\Permission;
use App\Models\Anagrafica;
use App\Models\CategoriaDocumento;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\User;
use App\Notifications\Documenti\NewDocumentoNotification;
use App\Notifications\Documenti\UpdatedDocumentoNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission as SpatiePermission;

uses(RefreshDatabase::class);

function destinatarioDiDocumenti(Condominio $condominio, string $nome): Anagrafica
{
    SpatiePermission::findOrCreate(Permission::VIEW_ARCHIVE_DOCUMENTS->value, 'web');

    $user = User::factory()->create(['name' => $nome]);
    $user->givePermissionTo(Permission::VIEW_ARCHIVE_DOCUMENTS->value);

    // ⚠️ **Due righe, non una.** Dalla beta.64 l'avviso di *modifica* ha una preferenza sua,
    // separata da quella delle cose *nuove*: una fixture che creasse solo la prima produrrebbe
    // zero destinatari sull'avviso di modifica, cioè un test rosso per la ragione sbagliata.
    foreach ([NotificationType::NEW_ARCHIVE_DOCUMENT, NotificationType::UPDATED_ARCHIVE_DOCUMENT] as $tipo) {
        DB::table('notification_preferences')->insert([
            'user_id'    => $user->id,
            'type'       => $tipo->value,
            'enabled'    => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $anagrafica = Anagrafica::factory()->create(['nome' => $nome, 'user_id' => $user->id]);
    $anagrafica->condomini()->attach($condominio->id);

    return $anagrafica;
}

function amministratoreDiDocumenti(): User
{
    foreach ([
        Permission::VIEW_ARCHIVE_DOCUMENTS,
        Permission::EDIT_ARCHIVE_DOCUMENTS,
        Permission::PUBLISH_ARCHIVE_DOCUMENTS,
        Permission::ACCESS_ADMIN_PANEL,
    ] as $p) {
        SpatiePermission::findOrCreate($p->value, 'web');
    }

    $user = User::factory()->create(['name' => 'Amministratore']);
    $user->givePermissionTo([
        Permission::VIEW_ARCHIVE_DOCUMENTS->value,
        Permission::EDIT_ARCHIVE_DOCUMENTS->value,
        Permission::ACCESS_ADMIN_PANEL->value,
    ]);

    return $user;
}

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    Notification::fake();
    Storage::fake('local');
    Storage::disk('local')->put('documenti/prova.pdf', '%PDF-1.4');

    $this->condominio = Condominio::factory()->create();
    $this->admin = amministratoreDiDocumenti();
    $this->categoria = CategoriaDocumento::create(['name' => 'Verbali', 'description' => 'Assemblee']);

    $this->giaDestinatario = destinatarioDiDocumenti($this->condominio, 'Rossi Mario');
    $this->nuovo = destinatarioDiDocumenti($this->condominio, 'Bianchi Anna');

    $this->documento = Documento::create([
        'name'         => 'Regolamento condominiale',
        'description'  => 'Testo approvato in assemblea.',
        'path'         => 'documenti/prova.pdf',
        'mime_type'    => 'application/pdf',
        'file_size'    => 9,
        'created_by'   => $this->admin->id,
        'is_published' => true,
        'is_approved'  => true,
    ]);

    // Con il legame, non con la colonna: vedi 1.11.0-beta.10.
    $this->documento->categorie()->attach($this->categoria->id);

    $this->documento->condomini()->attach($this->condominio->id);
    $this->documento->anagrafiche()->attach([$this->giaDestinatario->id]);
});

/** I dati del modulo di modifica, con la platea e la casella indicate. */
function datiDocumento(Documento $d, Condominio $condominio, array $anagrafiche, bool $avvisa = false): array
{
    return [
        'name'            => $d->name,
        'description'     => $d->description,
        'created_by'      => $d->created_by,
        'is_approved'     => $d->is_approved,
        'is_published'    => $d->is_published,
        // ⚠️ Il modulo di modifica manda **sempre** le categorie, ed è quello che questo fixture
        // deve rappresentare: prima diceva `'category_id' => $d->category_id`, che dalla
        // 1.11.0-beta.10 vale `null` — un campo che nel modulo vero non esiste più.
        'categorie'       => $d->categorie->pluck('id')->all(),
        'condomini_ids'   => [$condominio->id],
        'anagrafiche'     => collect($anagrafiche)->pluck('id')->all(),
        'avvisa_destinatari' => $avvisa,
    ];
}

it("chi viene aggiunto in modifica riceve il documento, che per lui è nuovo", function () {
    $this->actingAs($this->admin)->put(
        route('admin.documenti.update', ['documento' => $this->documento->id]),
        datiDocumento($this->documento, $this->condominio, [$this->giaDestinatario, $this->nuovo])
    );

    Notification::assertSentTo($this->nuovo, NewDocumentoNotification::class);
    Notification::assertNotSentTo($this->giaDestinatario, NewDocumentoNotification::class);
});

it('con la casella spuntata, chi c\'era già riceve l\'avviso di MODIFICA', function () {
    $this->actingAs($this->admin)->put(
        route('admin.documenti.update', ['documento' => $this->documento->id]),
        datiDocumento($this->documento, $this->condominio, [$this->giaDestinatario], avvisa: true)
    );

    Notification::assertSentTo($this->giaDestinatario, UpdatedDocumentoNotification::class);
});

it('senza la casella non parte niente verso chi c\'era già', function () {
    $this->actingAs($this->admin)->put(
        route('admin.documenti.update', ['documento' => $this->documento->id]),
        datiDocumento($this->documento, $this->condominio, [$this->giaDestinatario], avvisa: false)
    );

    Notification::assertNothingSentTo($this->giaDestinatario);
});
