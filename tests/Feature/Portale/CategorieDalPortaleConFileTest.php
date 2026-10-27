<?php

/**
 * # Un documento modificato dal portale con un file nuovo: le categorie (Coda 307)
 *
 * La stessa forma del modulo del pannello (`DocumentoFileEDestinatariTest`), sulla pagina del portale
 * (`documenti/user/DocumentiEdit.vue`): con un file nuovo il modulo parte come FormData, e in FormData
 * un elenco vuoto **non si scrive affatto**. «Tolte tutte le categorie» arrivava al server uguale a
 * «campo non mandato»: la regola «almeno una» (dalla 1.11.0-beta.10) non scattava, il file cambiava e
 * le categorie restavano, senza un messaggio. Senza file lo stesso gesto si rifiuta.
 *
 * La pagina manda ora `categorie_nel_modulo`, il segno che il campo c'era: con il segno, la chiave
 * assente vuol dire «nessuna», e la regola scatta come con il corpo JSON. Una richiesta senza il segno
 * (non dalla pagina) resta com'era: senza campo non si tocca.
 *
 * I corpi qui sotto sono quelli che arrivano al server dalla pagina, che salva con `form.post`: con il
 * file, i booleani come «1»/«0» e l'elenco vuoto assente (FormData); senza file, JSON.
 */

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\CategoriaDocumento;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    config(['kondomanager.disco_documenti' => 'local']);
    Storage::fake('local');
    $this->withoutVite();

    $this->condominio = Condominio::factory()->create(['nome' => "Residenza l'Ulivo"]);

    // Mario, condòmino di serie che carica e modifica i propri documenti d'archivio dal portale.
    $this->mario = User::factory()->create(['email_verified_at' => now(), 'name' => 'Mario Rossi']);
    $this->mario->assignRole(Role::UTENTE->value);
    $this->mario->givePermissionTo([Permission::CREATE_ARCHIVE_DOCUMENTS->value, Permission::EDIT_OWN_ARCHIVE_DOCUMENTS->value]);
    Anagrafica::factory()->create(['user_id' => $this->mario->id, 'nome' => 'Mario Rossi'])->condomini()->attach($this->condominio->id);

    $this->verbali = CategoriaDocumento::create(['name' => 'Verbali', 'description' => 'Assemblee']);
    $this->contratti = CategoriaDocumento::create(['name' => "Contratti d'appalto", 'description' => 'Fornitori']);
});

function portaleDocumento(Condominio $condominio, User $autore, array $categorie): Documento
{
    $d = Documento::create([
        'name' => "Verbale dell'assemblea «straordinaria»", 'description' => "Prima riga\nseconda riga — con l'apostrofo",
        'created_by' => $autore->id, 'is_published' => true, 'is_approved' => true,
        'path' => 'documenti/vecchio.pdf', 'mime_type' => 'application/pdf', 'file_size' => 10,
    ]);
    $d->condomini()->attach($condominio->id);
    $d->categorie()->attach(array_map(fn (CategoriaDocumento $c) => $c->id, $categorie));

    return $d->fresh();
}

/**
 * Il corpo FormData della pagina del portale con un file nuovo, dalle props della modifica: i booleani
 * come «1»/«0», un elenco vuoto non c'è. `$segno` a null: la richiesta non porta il segno.
 */
function portaleFormData(array $props, array $categorie, ?string $segno = '1'): array
{
    $corpo = [
        'name' => $props['name'], 'description' => $props['description'],
        'is_published' => $props['is_published'] ? '1' : '0',
        'file' => UploadedFile::fake()->create('nuovo.pdf', 12, 'application/pdf'),
    ];
    if ($categorie !== []) {
        $corpo['categorie'] = $categorie;
    }
    if ($segno !== null) {
        $corpo['categorie_nel_modulo'] = $segno;
    }

    return $corpo;
}

/**
 * Un amministratore che apre le pagine del portale: con un'anagrafica collegata all'account, perché senza
 * il portale lo rimanda alla dashboard del pannello (decisione 134.4, `CheckHasAnagrafica`).
 */
function portaleAmministratoreConAnagrafica(Condominio $condominio): User
{
    $admin = User::factory()->create(['email_verified_at' => now()]);
    $admin->assignRole(Role::AMMINISTRATORE->value);
    Anagrafica::factory()->create(['user_id' => $admin->id, 'nome' => 'Studio Bianchi'])->condomini()->attach($condominio->id);

    return $admin->fresh();
}

/** Le props del documento che la pagina di modifica del portale riceve. */
function portaleProps($test, User $chi, Documento $d): array
{
    $p = $test->actingAs($chi)->get(route('user.documenti.edit', $d))->assertOk()->viewData('page')['props']['documento'];

    return $p['data'] ?? $p;
}

/** Ciò che una modifica rifiutata non deve aver toccato. */
function portaleStato(Documento $d): array
{
    $d = $d->fresh();
    $ordina = fn ($c) => collect($c)->sort()->values()->all();

    return [
        'path'        => $d->path,
        'name'        => $d->name,
        'description' => $d->description,
        'condomini'   => $ordina($d->condomini()->pluck('condomini.id')),
        'categorie'   => $ordina($d->categorie()->pluck('categorie_documento.id')),
        'anagrafiche' => $ordina($d->anagrafiche()->pluck('anagrafiche.id')),
    ];
}

test('Coda 307 — dal portale, con un file nuovo e le categorie tolte, il salvataggio si rifiuta come senza file', function () {
    // Atteso da `categorie` «sometimes|required|array|min:1» di `Documento\Utenti\UpdateDocumentoRequest`
    // («almeno una, dalla 1.11.0-beta.10»): senza file lo stesso gesto si rifiuta, e con il file deve
    // rifiutarsi allo stesso modo, con lo stesso messaggio, senza toccare niente.
    $d = portaleDocumento($this->condominio, $this->mario, [$this->verbali]);
    $prima = portaleStato($d);
    $props = portaleProps($this, $this->mario, $d);

    $this->actingAs($this->mario)->from(route('user.documenti.edit', $d))->post(route('user.documenti.update', $d), [
        'name' => $props['name'], 'description' => $props['description'], 'is_published' => (bool) $props['is_published'],
        'categorie' => [], 'file' => null, 'categorie_nel_modulo' => true,
    ])->assertRedirect(route('user.documenti.edit', $d))->assertSessionHasErrors('categorie');
    $erroreSenzaFile = session('errors')->first('categorie');

    $this->actingAs($this->mario)->from(route('user.documenti.edit', $d))
        ->post(route('user.documenti.update', $d), portaleFormData($props, []))
        ->assertRedirect(route('user.documenti.edit', $d))->assertSessionHasErrors('categorie');

    expect(session('errors')->first('categorie'))->toBe($erroreSenzaFile)
        ->and(portaleStato($d))->toBe($prima);
});

test('Coda 307 — dal portale, aperto e inviato con il solo file nuovo, la modifica cambia il file e nient\'altro', function () {
    // Il caso «aperta e inviata senza toccare niente», con un file nuovo: il corpo della pagina costruito
    // dalle props della modifica. Dati scomodi: due categorie (una con l'apostrofo), nome e descrizione
    // con apostrofi, virgolette e due righe.
    $d = portaleDocumento($this->condominio, $this->mario, [$this->verbali, $this->contratti]);
    $prima = portaleStato($d);
    $props = portaleProps($this, $this->mario, $d);

    $this->actingAs($this->mario)->post(route('user.documenti.update', $d), portaleFormData($props, collect($props['categorie'])->pluck('id')->all()))
        ->assertRedirect(route('user.categorie-documenti.index'))->assertSessionHasNoErrors();

    $dopo = portaleStato($d);
    expect($dopo['path'])->not->toBe($prima['path'])
        ->and(array_diff_key($dopo, ['path' => 1]))->toBe(array_diff_key($prima, ['path' => 1]));
});

test('Coda 307 — dal portale, con un file nuovo e le categorie cambiate, le categorie si sostituiscono', function () {
    // Il segno riempie solo l'elenco che manca: un elenco mandato vale com'è.
    $d = portaleDocumento($this->condominio, $this->mario, [$this->verbali]);
    $props = portaleProps($this, $this->mario, $d);

    $this->actingAs($this->mario)->post(route('user.documenti.update', $d), portaleFormData($props, [$this->contratti->id]))
        ->assertSessionHasNoErrors();

    expect(portaleStato($d)['categorie'])->toBe([$this->contratti->id]);
});

test('Coda 307 — dal portale, una richiesta con il file e senza il segno, che non porta le categorie, non le tocca', function () {
    // Non viene dalla pagina: per lei vale ancora «senza campo non si tocca».
    $d = portaleDocumento($this->condominio, $this->mario, [$this->verbali]);
    $prima = portaleStato($d);
    $props = portaleProps($this, $this->mario, $d);

    $this->actingAs($this->mario)->post(route('user.documenti.update', $d), portaleFormData($props, [], segno: null))
        ->assertSessionHasNoErrors();

    expect(portaleStato($d)['categorie'])->toBe($prima['categorie'])
        ->and(portaleStato($d)['path'])->not->toBe($prima['path']);
});

test('Coda 307 — dal portale, con il segno a falso, il file nuovo e le categorie assenti, non le tocca', function () {
    // Il segno dice che il campo c'era solo se è vero: «0» è come non averlo mandato.
    $d = portaleDocumento($this->condominio, $this->mario, [$this->verbali]);
    $prima = portaleStato($d);
    $props = portaleProps($this, $this->mario, $d);

    $this->actingAs($this->mario)->post(route('user.documenti.update', $d), portaleFormData($props, [], segno: '0'))
        ->assertSessionHasNoErrors();

    expect(portaleStato($d)['categorie'])->toBe($prima['categorie']);
});

test('Coda 307 — dal portale, un segno che non è né vero né falso si rifiuta e non tocca niente', function () {
    // Il segno è un sì o un no, come quello del pannello (`anagrafiche_nel_modulo` «sometimes|boolean»).
    $d = portaleDocumento($this->condominio, $this->mario, [$this->verbali]);
    $prima = portaleStato($d);
    $props = portaleProps($this, $this->mario, $d);

    $this->actingAs($this->mario)->from(route('user.documenti.edit', $d))
        ->post(route('user.documenti.update', $d), portaleFormData($props, [], segno: 'forse'))
        ->assertRedirect(route('user.documenti.edit', $d))->assertSessionHasErrors('categorie_nel_modulo');

    expect(portaleStato($d))->toBe($prima);
});

test('Coda 307 — la pagina del portale aperta da chi ha il pannello salva verso il pannello: il segno del portale non toglie palazzi né destinatari', function () {
    // Chi ha l'accesso al pannello e apre la pagina del portale salva su `admin.documenti.update` (la pagina
    // sceglie il prefisso dal ruolo, `generateRoute`), con il corpo del portale, che non ha palazzi né
    // destinatari. Il segno del pannello (`anagrafiche_nel_modulo`) li varrebbe vuoti; quello del portale
    // riguarda solo le categorie, e la richiesta del pannello lo legge solo per loro: niente si toglie.
    $admin = portaleAmministratoreConAnagrafica($this->condominio);
    $anna = Anagrafica::factory()->create(['nome' => 'Anna Neri']);
    $anna->condomini()->attach($this->condominio->id);
    $d = portaleDocumento($this->condominio, $this->mario, [$this->verbali, $this->contratti]);
    $d->anagrafiche()->attach($anna->id);
    $prima = portaleStato($d);
    $props = portaleProps($this, $admin, $d);

    $this->actingAs($admin)->post(route('admin.documenti.update', $d), portaleFormData($props, collect($props['categorie'])->pluck('id')->all()))
        ->assertRedirect(route('admin.documenti.index'))->assertSessionHasNoErrors();

    $dopo = portaleStato($d);
    expect($dopo['path'])->not->toBe($prima['path'])
        ->and(array_diff_key($dopo, ['path' => 1]))->toBe(array_diff_key($prima, ['path' => 1]));
});

test('Coda 307 — la pagina del portale aperta da chi ha il pannello: con un file nuovo e le categorie tolte, il salvataggio si rifiuta come dal portale', function () {
    // La stessa regola sulla rotta del pannello: con il segno del portale e le categorie assenti, la
    // richiesta del pannello le vale vuote, e «almeno una» scatta con lo stesso messaggio. Palazzi,
    // destinatari, file e categorie restano.
    $admin = portaleAmministratoreConAnagrafica($this->condominio);
    $anna = Anagrafica::factory()->create(['nome' => "Anna D'Amico"]);
    $anna->condomini()->attach($this->condominio->id);
    $d = portaleDocumento($this->condominio, $this->mario, [$this->verbali, $this->contratti]);
    $d->anagrafiche()->attach($anna->id);
    $prima = portaleStato($d);
    $props = portaleProps($this, $admin, $d);

    $this->actingAs($admin)->from(route('user.documenti.edit', $d))
        ->post(route('admin.documenti.update', $d), [
            'name' => $props['name'], 'description' => $props['description'], 'is_published' => (bool) $props['is_published'],
            'categorie' => [], 'file' => null, 'categorie_nel_modulo' => true,
        ])->assertRedirect(route('user.documenti.edit', $d))->assertSessionHasErrors('categorie');
    $erroreSenzaFile = session('errors')->first('categorie');

    $this->actingAs($admin)->from(route('user.documenti.edit', $d))
        ->post(route('admin.documenti.update', $d), portaleFormData($props, []))
        ->assertRedirect(route('user.documenti.edit', $d))->assertSessionHasErrors('categorie');

    expect(session('errors')->first('categorie'))->toBe($erroreSenzaFile)
        ->and(portaleStato($d))->toBe($prima);
});

test('Coda 307 — sulla rotta del pannello, senza il segno del portale o con il segno a falso, le categorie assenti non si toccano', function () {
    // Una richiesta che non viene da una delle due pagine e non porta le categorie non le tocca.
    $admin = portaleAmministratoreConAnagrafica($this->condominio);
    $d = portaleDocumento($this->condominio, $this->mario, [$this->verbali]);
    $props = portaleProps($this, $admin, $d);

    foreach ([null, '0'] as $segno) {
        $this->actingAs($admin)->post(route('admin.documenti.update', $d), portaleFormData($props, [], $segno))
            ->assertRedirect(route('admin.documenti.index'))->assertSessionHasNoErrors();

        expect($d->fresh()->categorie()->pluck('categorie_documento.id')->all())->toBe([$this->verbali->id]);
    }
});

test('Coda 307 — sulla rotta del pannello, un segno del portale che non è né vero né falso si rifiuta e non tocca niente', function () {
    $admin = portaleAmministratoreConAnagrafica($this->condominio);
    $d = portaleDocumento($this->condominio, $this->mario, [$this->verbali]);
    $prima = portaleStato($d);
    $props = portaleProps($this, $admin, $d);

    $this->actingAs($admin)->from(route('user.documenti.edit', $d))
        ->post(route('admin.documenti.update', $d), portaleFormData($props, [], segno: 'forse'))
        ->assertRedirect(route('user.documenti.edit', $d))->assertSessionHasErrors('categorie_nel_modulo');

    expect(portaleStato($d))->toBe($prima);
});
