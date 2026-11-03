<?php

/**
 * # Un documento modificato con un file nuovo: i destinatari (Coda 307)
 *
 * «Senza il campo `anagrafiche` la modifica non le tocca» (Coda 307): una richiesta che non porta il
 * campo tiene i destinatari. Ma la pagina di modifica del documento, quando c'è un file nuovo, parte
 * come FormData (`@inertiajs/core`, `objectToFormData`), e in FormData un elenco vuoto **non si scrive
 * affatto**: «tolti tutti i destinatari» arrivava al server uguale a «campo non mandato», e il file
 * cambiava mentre i destinatari restavano.
 *
 * La pagina manda ora anche `anagrafiche_nel_modulo`, il segno che il campo c'era: con il segno, la
 * chiave assente vuol dire «nessuno». Una richiesta senza il segno (non dalla pagina) resta com'era:
 * senza campo non si tocca.
 *
 * I corpi qui sotto sono quelli che arrivano al server: con il file, i booleani come «1»/«0» e
 * l'elenco vuoto assente (FormData); senza file, JSON.
 */

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

    $this->condominio = Condominio::factory()->create();
    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    $this->admin->assignRole(Role::AMMINISTRATORE->value);

    $this->anna = Anagrafica::factory()->create(['nome' => 'Anna Neri']);
    $this->anna->condomini()->attach($this->condominio->id);
    $this->bruno = Anagrafica::factory()->create(['nome' => 'Bruno Galli']);
    $this->bruno->condomini()->attach($this->condominio->id);

    $this->verbali = CategoriaDocumento::create(['name' => 'Verbali', 'description' => 'Assemblee']);
});

function fileDestinatariDocumento(Condominio $condominio, User $autore, CategoriaDocumento $categoria, array $anagrafiche): Documento
{
    $d = Documento::create([
        'name' => 'Lettera ai condòmini', 'description' => 'Prima versione', 'created_by' => $autore->id,
        'is_published' => true, 'is_approved' => true,
        'path' => 'documenti/vecchio.pdf', 'mime_type' => 'application/pdf', 'file_size' => 10,
    ]);
    $d->condomini()->attach($condominio->id);
    $d->categorie()->attach($categoria->id);
    if ($anagrafiche !== []) {
        $d->anagrafiche()->attach(array_map(fn (Anagrafica $a) => $a->id, $anagrafiche));
    }

    return $d->fresh();
}

/** Il corpo FormData della pagina, con un file nuovo: booleani come «1»/«0», un elenco vuoto non c'è. */
function fileDestinatariFormData(Documento $d, array $anagrafiche, bool $conSegno = true): array
{
    $corpo = [
        'name' => $d->name, 'description' => $d->description,
        'condomini_ids' => $d->condomini()->pluck('condomini.id')->all(),
        'is_published' => '1', 'categorie' => $d->categorie()->pluck('categorie_documento.id')->all(),
        'file' => UploadedFile::fake()->create('nuovo.pdf', 12, 'application/pdf'),
        'avvisa_destinatari' => '0',
    ];
    if ($anagrafiche !== []) {
        $corpo['anagrafiche'] = $anagrafiche;
    }
    if ($conSegno) {
        $corpo['anagrafiche_nel_modulo'] = '1';
    }

    return $corpo;
}

test('Coda 307 — sostituire il file e togliere tutti i destinatari nello stesso salvataggio li toglie', function () {
    $d = fileDestinatariDocumento($this->condominio, $this->admin, $this->verbali, [$this->anna]);

    $this->actingAs($this->admin)->from(route('admin.documenti.edit', $d))
        ->put(route('admin.documenti.update', $d), fileDestinatariFormData($d, []))
        ->assertRedirect(route('admin.documenti.index'))->assertSessionHasNoErrors();

    $d->refresh();
    expect($d->path)->not->toBe('documenti/vecchio.pdf')
        ->and($d->anagrafiche()->count())->toBe(0);
});

test('Coda 307 — sostituire il file e cambiare i destinatari li sostituisce', function () {
    $d = fileDestinatariDocumento($this->condominio, $this->admin, $this->verbali, [$this->anna]);

    $this->actingAs($this->admin)
        ->put(route('admin.documenti.update', $d), fileDestinatariFormData($d, [$this->bruno->id]))
        ->assertSessionHasNoErrors();

    expect($d->fresh()->anagrafiche()->pluck('anagrafiche.id')->all())->toBe([$this->bruno->id]);
});

test('Coda 307 — sostituire il file senza toccare i destinatari li tiene', function () {
    $d = fileDestinatariDocumento($this->condominio, $this->admin, $this->verbali, [$this->anna, $this->bruno]);

    $this->actingAs($this->admin)
        ->put(route('admin.documenti.update', $d), fileDestinatariFormData($d, [$this->anna->id, $this->bruno->id]))
        ->assertSessionHasNoErrors();

    expect($d->fresh()->path)->not->toBe('documenti/vecchio.pdf')
        ->and($d->fresh()->anagrafiche()->pluck('anagrafiche.id')->sort()->values()->all())
        ->toBe(collect([$this->anna->id, $this->bruno->id])->sort()->values()->all());
});

test('Coda 307 — una richiesta con il file ma senza il campo e senza il segno tiene i destinatari', function () {
    // Non viene dalla pagina: per lei vale ancora «senza campo non si tocca».
    $d = fileDestinatariDocumento($this->condominio, $this->admin, $this->verbali, [$this->anna]);

    $this->actingAs($this->admin)
        ->put(route('admin.documenti.update', $d), fileDestinatariFormData($d, [], conSegno: false))
        ->assertSessionHasNoErrors();

    expect($d->fresh()->path)->not->toBe('documenti/vecchio.pdf')
        ->and($d->fresh()->anagrafiche()->pluck('anagrafiche.id')->all())->toBe([$this->anna->id]);
});

test('Coda 307 — con il segno a falso, il file nuovo e il campo assente, i destinatari restano', function () {
    // Il segno dice che il campo c'era solo se è vero: «0» è come non averlo mandato.
    $d = fileDestinatariDocumento($this->condominio, $this->admin, $this->verbali, [$this->anna]);
    $corpo = fileDestinatariFormData($d, [], conSegno: false) + ['anagrafiche_nel_modulo' => '0'];

    $this->actingAs($this->admin)->put(route('admin.documenti.update', $d), $corpo)->assertSessionHasNoErrors();

    expect($d->fresh()->anagrafiche()->pluck('anagrafiche.id')->all())->toBe([$this->anna->id]);
});

test('Coda 307 — senza file, con il segno e l\'elenco vuoto (JSON), i destinatari si tolgono', function () {
    $d = fileDestinatariDocumento($this->condominio, $this->admin, $this->verbali, [$this->anna]);

    $this->actingAs($this->admin)->put(route('admin.documenti.update', $d), [
        'name' => $d->name, 'description' => $d->description, 'condomini_ids' => [$this->condominio->id],
        'is_published' => true, 'categorie' => [$this->verbali->id], 'anagrafiche' => [], 'file' => null,
        'avvisa_destinatari' => false, 'anagrafiche_nel_modulo' => true,
    ])->assertSessionHasNoErrors();

    expect($d->fresh()->anagrafiche()->count())->toBe(0);
});

test('Coda 307 — la modifica del documento, aperta e inviata senza toccare niente, non cambia niente', function () {
    // Dati scomodi: due palazzi, due destinatari, due categorie, in bozza, testo con apostrofi e
    // virgolette su più righe. Il corpo è quello di `documenti/DocumentiEdit.vue` (useForm) senza file:
    // la pagina lo manda in JSON.
    $altro = Condominio::factory()->create();
    $contratti = CategoriaDocumento::create(['name' => 'Contratti', 'description' => 'Fornitori']);
    $d = fileDestinatariDocumento($this->condominio, $this->admin, $this->verbali, [$this->anna, $this->bruno]);
    $d->condomini()->attach($altro->id);
    $d->categorie()->attach($contratti->id);
    $d->update(['name' => "Verbale dell'assemblea «straordinaria»", 'description' => "Prima riga\nseconda riga — con l'apostrofo", 'is_published' => false]);

    $prima = $d->fresh()->only(['name', 'description', 'created_by', 'is_published', 'is_approved', 'path', 'mime_type', 'file_size']);
    $ordina = fn ($c) => collect($c)->sort()->values()->all();
    $pivotPrima = [
        $ordina($d->condomini()->pluck('condomini.id')), $ordina($d->anagrafiche()->pluck('anagrafiche.id')),
        $ordina($d->categorie()->pluck('categorie_documento.id')),
    ];

    $p = $this->actingAs($this->admin)->get(route('admin.documenti.edit', $d))->assertOk()->viewData('page')['props']['documento'];
    $p = $p['data'] ?? $p;

    $this->actingAs($this->admin)->put(route('admin.documenti.update', $d), [
        'name'                   => $p['name'],
        'description'            => $p['description'],
        'condomini_ids'          => collect($p['condomini']['options'])->pluck('value')->all(),
        'is_published'           => (bool) $p['is_published'],
        'anagrafiche'            => collect($p['anagrafiche'])->pluck('id')->all(),
        'categorie'              => collect($p['categorie'])->pluck('id')->all(),
        'file'                   => null,
        'avvisa_destinatari'     => false,
        'anagrafiche_nel_modulo' => true,
    ])->assertRedirect(route('admin.documenti.index'))->assertSessionHasNoErrors();

    expect($d->fresh()->only(array_keys($prima)))->toEqual($prima)
        ->and([
            $ordina($d->condomini()->pluck('condomini.id')), $ordina($d->anagrafiche()->pluck('anagrafiche.id')),
            $ordina($d->categorie()->pluck('categorie_documento.id')),
        ])->toBe($pivotPrima);
    Notification::assertNothingSent();
});

// ─── Gli altri elenchi del modulo, con un file nuovo ────────────────────────────────────────────
//
// In FormData sparisce **ogni** elenco vuoto, non solo i destinatari: anche i palazzi e le categorie.
// Con il segno della pagina, un elenco assente vale vuoto, così le regole «almeno uno» scattano come
// con il corpo JSON (senza file): togliere il palazzo o le categorie si rifiuta allo stesso modo, con
// o senza un file nuovo.

/**
 * Il corpo FormData della pagina con un file nuovo, quando chi modifica ha svuotato gli elenchi
 * `$svuotati`: in FormData un elenco vuoto non si scrive, quindi la chiave non c'è.
 */
function fileElenchiFormData(Documento $d, array $svuotati, ?string $segno = '1'): array
{
    $corpo = [
        'name' => $d->name, 'description' => $d->description,
        'condomini_ids' => $d->condomini()->pluck('condomini.id')->all(),
        'is_published' => $d->is_published ? '1' : '0',
        'anagrafiche' => $d->anagrafiche()->pluck('anagrafiche.id')->all(),
        'categorie' => $d->categorie()->pluck('categorie_documento.id')->all(),
        'file' => UploadedFile::fake()->create('nuovo.pdf', 12, 'application/pdf'),
        'avvisa_destinatari' => '0',
    ];
    foreach ($svuotati as $chiave) {
        unset($corpo[$chiave]);
    }
    if (($corpo['anagrafiche'] ?? null) === []) {
        unset($corpo['anagrafiche']);
    }
    if ($segno !== null) {
        $corpo['anagrafiche_nel_modulo'] = $segno;
    }

    return $corpo;
}

/** Ciò che una modifica rifiutata non deve aver toccato: il file, i palazzi, le categorie, i destinatari. */
function fileElenchiStato(Documento $d): array
{
    $d = $d->fresh();
    $ordina = fn ($c) => collect($c)->sort()->values()->all();

    return [
        'path'        => $d->path,
        'condomini'   => $ordina($d->condomini()->pluck('condomini.id')),
        'categorie'   => $ordina($d->categorie()->pluck('categorie_documento.id')),
        'anagrafiche' => $ordina($d->anagrafiche()->pluck('anagrafiche.id')),
    ];
}

test('Coda 307 — con un file nuovo e il palazzo tolto, il documento indirizzato a una persona non diventa di tutto il palazzo: si rifiuta come senza file', function () {
    // Il gesto: si toglie il palazzo (la pagina svuota anche i destinatari) e si sceglie un file.
    // Atteso da `condomini_ids` «sometimes|required|array|min:1» (senza file lo stesso modulo si
    // rifiuta) e dalla Coda 307 (un documento indirizzato a una persona non diventa di tutto il
    // condominio senza che nessuno l'abbia scelto).
    $d = fileDestinatariDocumento($this->condominio, $this->admin, $this->verbali, [$this->anna]);
    $prima = fileElenchiStato($d);

    $senzaFile = $this->actingAs($this->admin)->from(route('admin.documenti.edit', $d))->put(route('admin.documenti.update', $d), [
        'name' => $d->name, 'description' => $d->description, 'condomini_ids' => [], 'is_published' => true,
        'anagrafiche' => [], 'categorie' => [$this->verbali->id], 'file' => null, 'avvisa_destinatari' => false,
        'anagrafiche_nel_modulo' => true,
    ])->assertRedirect(route('admin.documenti.edit', $d))->assertSessionHasErrors('condomini_ids');
    $erroreSenzaFile = session('errors')->first('condomini_ids');

    $this->actingAs($this->admin)->from(route('admin.documenti.edit', $d))
        ->put(route('admin.documenti.update', $d), fileElenchiFormData($d, ['condomini_ids', 'anagrafiche']))
        ->assertRedirect(route('admin.documenti.edit', $d))->assertSessionHasErrors('condomini_ids');

    expect(session('errors')->first('condomini_ids'))->toBe($erroreSenzaFile)
        ->and(fileElenchiStato($d))->toBe($prima);
    Notification::assertNothingSent();
});

test('Coda 307 — con un file nuovo e le categorie tolte, il salvataggio si rifiuta come senza file', function () {
    // Atteso da `categorie` «sometimes|required|array|min:1» («almeno una, dalla 1.11.0-beta.10»).
    $d = fileDestinatariDocumento($this->condominio, $this->admin, $this->verbali, [$this->anna]);
    $prima = fileElenchiStato($d);

    $this->actingAs($this->admin)->from(route('admin.documenti.edit', $d))->put(route('admin.documenti.update', $d), [
        'name' => $d->name, 'description' => $d->description, 'condomini_ids' => [$this->condominio->id], 'is_published' => true,
        'anagrafiche' => [$this->anna->id], 'categorie' => [], 'file' => null, 'avvisa_destinatari' => false,
        'anagrafiche_nel_modulo' => true,
    ])->assertSessionHasErrors('categorie');
    $erroreSenzaFile = session('errors')->first('categorie');

    $this->actingAs($this->admin)->from(route('admin.documenti.edit', $d))
        ->put(route('admin.documenti.update', $d), fileElenchiFormData($d, ['categorie']))
        ->assertRedirect(route('admin.documenti.edit', $d))->assertSessionHasErrors('categorie');

    expect(session('errors')->first('categorie'))->toBe($erroreSenzaFile)
        ->and(fileElenchiStato($d))->toBe($prima);
});

test('Coda 307 — aperta e inviata con il solo file nuovo, la modifica cambia il file e nient\'altro', function () {
    // Il caso «aperta e inviata senza toccare niente», con un file nuovo: il corpo è quello della pagina
    // (FormData), costruito dalle props della modifica. Dati scomodi: due palazzi, due categorie, in
    // bozza, apostrofi e virgolette su più righe; una volta con due destinatari, una volta con nessuno
    // (in FormData l'elenco vuoto non viaggia: con il segno vale «nessuno», che è com'era).
    $altro = Condominio::factory()->create();
    $contratti = CategoriaDocumento::create(['name' => 'Contratti', 'description' => 'Fornitori']);

    foreach ([[$this->anna, $this->bruno], []] as $destinatari) {
        $d = fileDestinatariDocumento($this->condominio, $this->admin, $this->verbali, $destinatari);
        $d->condomini()->attach($altro->id);
        $d->categorie()->attach($contratti->id);
        $d->update(['name' => "Verbale dell'assemblea «straordinaria»", 'description' => "Prima riga\nseconda riga — con l'apostrofo", 'is_published' => false]);

        $campi = ['name', 'description', 'created_by', 'is_published', 'is_approved'];
        $prima = $d->fresh()->only($campi);
        $statoPrima = fileElenchiStato($d);

        $p = $this->actingAs($this->admin)->get(route('admin.documenti.edit', $d))->assertOk()->viewData('page')['props']['documento'];
        $p = $p['data'] ?? $p;
        $corpo = [
            'name'                   => $p['name'],
            'description'            => $p['description'],
            'condomini_ids'          => collect($p['condomini']['options'])->pluck('value')->all(),
            'is_published'           => $p['is_published'] ? '1' : '0',
            'categorie'              => collect($p['categorie'])->pluck('id')->all(),
            'file'                   => UploadedFile::fake()->create('nuovo.pdf', 12, 'application/pdf'),
            'avvisa_destinatari'     => '0',
            'anagrafiche_nel_modulo' => '1',
        ];
        $anagrafiche = collect($p['anagrafiche'])->pluck('id')->all();
        if ($anagrafiche !== []) {
            $corpo['anagrafiche'] = $anagrafiche;
        }

        $this->actingAs($this->admin)->put(route('admin.documenti.update', $d), $corpo)
            ->assertRedirect(route('admin.documenti.index'))->assertSessionHasNoErrors();

        $dopo = fileElenchiStato($d);
        expect($d->fresh()->only($campi))->toEqual($prima)
            ->and($dopo['path'])->not->toBe($statoPrima['path'])
            ->and(array_diff_key($dopo, ['path' => 1]))->toBe(array_diff_key($statoPrima, ['path' => 1]));
    }
    Notification::assertNothingSent();
});

test('Coda 307 — una richiesta con il file e senza il segno, che non porta palazzi, categorie né destinatari, non li tocca', function () {
    // Non viene dalla pagina: per lei vale ancora «senza campo non si tocca», per tutti e tre gli elenchi.
    $d = fileDestinatariDocumento($this->condominio, $this->admin, $this->verbali, [$this->anna]);
    $prima = fileElenchiStato($d);

    $this->actingAs($this->admin)
        ->put(route('admin.documenti.update', $d), fileElenchiFormData($d, ['condomini_ids', 'categorie', 'anagrafiche'], segno: null))
        ->assertSessionHasNoErrors();

    expect(array_diff_key(fileElenchiStato($d), ['path' => 1]))->toBe(array_diff_key($prima, ['path' => 1]))
        ->and($d->fresh()->path)->not->toBe($prima['path']);
});

test('Coda 307 — con il segno a falso, il file nuovo e nessuno dei tre elenchi, niente si tocca', function () {
    // Il segno dice che i campi c'erano solo se è vero: «0» è come non averlo mandato.
    $d = fileDestinatariDocumento($this->condominio, $this->admin, $this->verbali, [$this->anna]);
    $prima = fileElenchiStato($d);

    $this->actingAs($this->admin)
        ->put(route('admin.documenti.update', $d), fileElenchiFormData($d, ['condomini_ids', 'categorie', 'anagrafiche'], segno: '0'))
        ->assertSessionHasNoErrors();

    expect(array_diff_key(fileElenchiStato($d), ['path' => 1]))->toBe(array_diff_key($prima, ['path' => 1]));
});
