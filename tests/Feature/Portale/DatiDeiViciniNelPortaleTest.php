<?php

/**
 * # Il portale non manda al browser i dati dei vicini (Coda 260)
 *
 * Le pagine del condòmino mostrano il nome dell'autore di segnalazioni, comunicazioni e
 * documenti, e il sorgente della pagina (`data-page`) porta solo quello: non l'anagrafica
 * completa (indirizzo, codice fiscale, email, PEC, telefono, cellulare) né l'utente con
 * email, ruoli e permessi; non le persone a cui un avviso è indirizzato; non le «note»
 * del condominio e dell'esercizio aperto; non il percorso del file dei documenti sul
 * disco.
 *
 * La scelta (tabella delle scelte tecniche, 260): al portale una risorsa minima — autore e
 * condominio con id e nome; il nome dell'autore dall'anagrafica, con il ripiego sull'utente, come
 * nei commenti; le persone a cui un avviso è indirizzato non si mostrano; le «note» del condominio e
 * dell'esercizio non arrivano ai condòmini. Il pannello dell'amministratore riceve le persone per nome, come le sue
 * pagine le mostrano; la scheda completa di una persona sta nella sua pagina, che chiede «Visualizza anagrafiche» (Coda 408).
 *
 * Il controllo legge le props della pagina Inertia, senza quelle condivise (`auth` porta l'utente
 * stesso, che i suoi dati li può vedere).
 */

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\CategoriaDocumento;
use App\Models\Comunicazione;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Esercizio;
use App\Models\Evento;
use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/** I dati del vicino e del condominio che non devono uscire dal pannello. */
const DATI_RISERVATI = [
    'VCNLGU80A01H501X',            // codice fiscale del vicino
    '0612345678',                  // telefono
    '3331234567',                  // cellulare
    'luigi.anagrafica@example.test',
    'luigi.pec@pec.example.test',
    'luigi.secondaria@example.test',
    'luigi.utente@example.test',   // email dell'account
    'Via dei Vicini 7',            // indirizzo
    'NOTA-RISERVATA-DEL-CONDOMINIO',
    'NOTA-RISERVATA-DELL-ESERCIZIO',
    'documenti/percorso-sul-disco.pdf',
];

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    $this->withoutVite();

    $this->condominio = Condominio::factory()->create([
        'nome' => 'Condominio Aurora',
        'note' => 'NOTA-RISERVATA-DEL-CONDOMINIO',
    ]);
    Esercizio::factory()->create([
        'condominio_id' => $this->condominio->id,
        'stato' => 'aperto',
        'note' => 'NOTA-RISERVATA-DELL-ESERCIZIO',
    ]);

    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    $this->admin->assignRole(Role::AMMINISTRATORE->value);

    $this->mario = viciniResidente($this->condominio, 'Mario Rossi', 'mario.utente@example.test', [
        'codice_fiscale' => 'RSSMRA70A01H501Z',
    ]);
    $this->luigi = viciniResidente($this->condominio, 'Luigi Verdi', 'luigi.utente@example.test', [
        'codice_fiscale'   => 'VCNLGU80A01H501X',
        'telefono'         => '0612345678',
        'cellulare'        => '3331234567',
        'email'            => 'luigi.anagrafica@example.test',
        'email_secondaria' => 'luigi.secondaria@example.test',
        'pec'              => 'luigi.pec@pec.example.test',
        'indirizzo'        => 'Via dei Vicini 7',
    ]);
});

function viciniResidente(Condominio $condominio, string $nome, string $email, array $anagrafica, array $permessi = []): User
{
    $user = User::factory()->create(['email_verified_at' => now(), 'name' => 'account '.$nome, 'email' => $email]);
    $user->assignRole(Role::UTENTE->value);
    if ($permessi !== []) {
        $user->givePermissionTo($permessi);
    }
    $a = Anagrafica::factory()->create(array_merge(['user_id' => $user->id, 'nome' => $nome], $anagrafica));
    $a->condomini()->attach($condominio->id);

    return $user->fresh();
}

/** Le props della pagina, senza quelle condivise, come testo. */
function viciniProps($test, User $user, string $url): string
{
    $props = $test->actingAs($user)->get($url)->assertOk()->viewData('page')['props'];
    unset($props['auth'], $props['flash'], $props['errors'], $props['ziggy'], $props['app']);

    return json_encode($props, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function viciniNessunDatoRiservato(string $json, string $dove): void
{
    foreach (DATI_RISERVATI as $dato) {
        expect(str_contains($json, $dato))->toBeFalse("{$dove}: c'è «{$dato}»");
    }
    expect(str_contains($json, '"permissions"'))->toBeFalse("{$dove}: ci sono i permessi di un altro");
    expect(str_contains($json, '"roles"'))->toBeFalse("{$dove}: ci sono i ruoli di un altro");
    expect(str_contains($json, '"patyh"'))->toBeFalse("{$dove}: c'è il percorso del file");
}

function viciniSegnalazione(Condominio $condominio, User $autore, array $collegate = []): Segnalazione
{
    $s = Segnalazione::create([
        'subject' => 'Rumori dal tetto', 'description' => 'Di notte', 'priority' => 'media', 'stato' => 'aperta',
        'condominio_id' => $condominio->id, 'created_by' => $autore->id,
        'is_private' => false, 'is_published' => true, 'is_approved' => true, 'is_featured' => false, 'can_comment' => true,
    ]);
    if ($collegate !== []) {
        $s->anagrafiche()->attach(array_map(fn (User $u) => $u->anagrafica->id, $collegate));
    }

    return $s;
}

function viciniComunicazione(Condominio $condominio, User $autore, array $collegate = []): Comunicazione
{
    $c = Comunicazione::create([
        'subject' => 'Assemblea', 'description' => 'Il 12 alle 18', 'priority' => 'media', 'created_by' => $autore->id,
        'is_featured' => false, 'is_private' => false, 'is_published' => true, 'is_approved' => true, 'can_comment' => false,
    ]);
    $c->condomini()->attach($condominio->id);
    if ($collegate !== []) {
        $c->anagrafiche()->attach(array_map(fn (User $u) => $u->anagrafica->id, $collegate));
    }

    return $c;
}

function viciniDocumento(Condominio $condominio, User $autore, CategoriaDocumento $categoria, array $collegate = []): Documento
{
    $d = Documento::create([
        'name' => 'Verbale', 'description' => 'Assemblea ordinaria', 'created_by' => $autore->id,
        'is_published' => true, 'is_approved' => true,
        'path' => 'documenti/percorso-sul-disco.pdf', 'mime_type' => 'application/pdf', 'file_size' => 1024,
    ]);
    $d->categorie()->attach($categoria->id);
    $d->condomini()->attach($condominio->id);
    if ($collegate !== []) {
        $d->anagrafiche()->attach(array_map(fn (User $u) => $u->anagrafica->id, $collegate));
    }

    return $d;
}

// ─── Le pagine del condòmino ────────────────────────────────────────────────────────────────

test('Coda 260 — elenco e scheda delle segnalazioni non portano i dati del vicino che le ha scritte', function () {
    $s = viciniSegnalazione($this->condominio, $this->luigi);
    viciniSegnalazione($this->condominio, $this->admin, [$this->mario, $this->luigi]);

    $elenco = viciniProps($this, $this->mario, route('user.segnalazioni.index'));
    viciniNessunDatoRiservato($elenco, 'elenco segnalazioni');
    // Il nome resta: è quello dell'anagrafica, come nei commenti, non quello dell'account.
    expect($elenco)->toContain('"name":"Luigi Verdi"')->not->toContain('account Luigi Verdi');

    $scheda = viciniProps($this, $this->mario, route('user.segnalazioni.show', $s));
    viciniNessunDatoRiservato($scheda, 'scheda segnalazione');
    expect($scheda)->toContain('Luigi Verdi')->toContain('Condominio Aurora');
});

test('Coda 260 — elenco e scheda delle comunicazioni non portano i dati dell\'autore né delle persone a cui sono indirizzate', function () {
    $indirizzata = viciniComunicazione($this->condominio, $this->admin, [$this->mario, $this->luigi]);
    viciniComunicazione($this->condominio, $this->luigi);

    $elenco = viciniProps($this, $this->mario, route('user.comunicazioni.index'));
    viciniNessunDatoRiservato($elenco, 'elenco comunicazioni');

    $scheda = viciniProps($this, $this->mario, route('user.comunicazioni.show', $indirizzata));
    viciniNessunDatoRiservato($scheda, 'scheda comunicazione');
    // Le persone a cui è indirizzata non si mostrano, neanche per nome.
    expect($scheda)->not->toContain('Luigi Verdi');
});

test('Coda 260 — l\'archivio dei documenti del condòmino non porta i dati dell\'autore, dei destinatari né il percorso del file', function () {
    $categoria = CategoriaDocumento::create(['name' => 'Verbali', 'description' => 'Assemblee']);
    viciniDocumento($this->condominio, $this->luigi, $categoria);
    viciniDocumento($this->condominio, $this->admin, $categoria, [$this->mario, $this->luigi]);

    viciniNessunDatoRiservato(viciniProps($this, $this->mario, route('user.categorie-documenti.index')), 'categorie documenti');
    $elenco = viciniProps($this, $this->mario, route('user.categorie-documenti.show', $categoria));
    viciniNessunDatoRiservato($elenco, 'documenti della categoria');
    expect($elenco)->toContain('Luigi Verdi');
});

test('Coda 260 — l\'agenda del condòmino non porta i dati delle altre persone dell\'evento né le note del condominio', function () {
    $evento = Evento::create([
        'title' => 'Sopralluogo caldaia', 'description' => 'Con i due interessati',
        'start_time' => now()->addDays(3), 'end_time' => now()->addDays(3)->addHour(),
        'created_by' => $this->admin->id, 'is_approved' => true, 'visibility' => 'public', 'timezone' => 'Europe/Rome',
    ]);
    $evento->anagrafiche()->attach([$this->mario->anagrafica->id, $this->luigi->anagrafica->id]);
    $evento->condomini()->attach($this->condominio->id);

    $agenda = viciniProps($this, $this->mario, route('user.eventi.index'));
    expect($agenda)->toContain('Sopralluogo caldaia');
    viciniNessunDatoRiservato($agenda, 'agenda');
    expect($agenda)->toContain('Condominio Aurora');
});

test('Coda 260 — la dashboard del condòmino non porta i dati dei vicini né le note del condominio', function () {
    $categoria = CategoriaDocumento::create(['name' => 'Verbali', 'description' => 'Assemblee']);
    viciniSegnalazione($this->condominio, $this->luigi);
    viciniComunicazione($this->condominio, $this->admin, [$this->mario, $this->luigi]);
    viciniDocumento($this->condominio, $this->luigi, $categoria);
    $evento = Evento::create([
        'title' => 'Pulizia cortile', 'description' => 'Tutti invitati',
        'start_time' => now()->addDays(2), 'end_time' => now()->addDays(2)->addHour(),
        'created_by' => $this->admin->id, 'is_approved' => true, 'visibility' => 'public', 'timezone' => 'Europe/Rome',
    ]);
    $evento->anagrafiche()->attach([$this->mario->anagrafica->id, $this->luigi->anagrafica->id]);
    $evento->condomini()->attach($this->condominio->id);

    $props = $this->actingAs($this->mario)->get(route('user.dashboard'))->assertOk()->viewData('page')['props'];
    unset($props['auth'], $props['flash'], $props['errors'], $props['ziggy'], $props['app']);
    // `eventi` arriva come scorrimento infinito: si serializza tutto, quello che c'è.
    $json = json_encode($props, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    viciniNessunDatoRiservato($json, 'dashboard');
    expect($json)->toContain('Rumori dal tetto')->toContain('Luigi Verdi');
});

test('Coda 260 — i moduli del condòmino ricevono i propri condomìni con id e nome, senza le note', function () {
    $redattore = viciniResidente($this->condominio, 'Paolo Neri', 'paolo@example.test', [], [
        Permission::CREATE_ARCHIVE_DOCUMENTS->value, Permission::CREATE_EVENTS->value,
    ]);

    $categoria = CategoriaDocumento::create(['name' => 'Verbali', 'description' => 'Assemblee']);
    $documento = viciniProps($this, $redattore, route('user.documenti.create', ['categoria' => $categoria->id]));
    viciniNessunDatoRiservato($documento, 'nuovo documento');
    expect($documento)->toContain('Condominio Aurora');

    $evento = viciniProps($this, $redattore, route('user.eventi.create'));
    viciniNessunDatoRiservato($evento, 'nuovo evento');
    expect($evento)->toContain('Condominio Aurora');
});

test('Coda 260 — il pannello dell\'amministratore riceve l\'autore con la sua persona per nome; la scheda completa sta nella pagina della persona (Coda 408)', function () {
    $s = viciniSegnalazione($this->condominio, $this->luigi);

    $props = $this->actingAs($this->admin)->get(route('admin.segnalazioni.show', $s))->assertOk()->viewData('page')['props'];
    $json = json_encode($props['segnalazione'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // La segnalazione mostra l'autore per nome: della sua persona arrivano id e nome, il resto sta nella sua scheda, che
    // chiede «Visualizza anagrafiche».
    expect($props['segnalazione']['created_by']['anagrafica'])->toBe(['id' => $this->luigi->anagrafica->id, 'nome' => $this->luigi->anagrafica->nome])
        ->and($json)->not->toContain('VCNLGU80A01H501X')->not->toContain('luigi.anagrafica@example.test');
    $this->actingAs($this->admin)->get(route('admin.anagrafiche.show', $this->luigi->anagrafica))->assertOk()
        ->assertSee('VCNLGU80A01H501X')->assertSee('luigi.anagrafica@example.test');
});

// ─── Le pagine di modifica del portale: aperte e salvate senza toccare niente ───────────────

test('Coda 260 — la modifica di una segnalazione dal portale, aperta e salvata senza toccare niente, non cambia niente', function () {
    $autore = viciniResidente($this->condominio, 'Nadia Conti', 'nadia@example.test', [], [Permission::EDIT_OWN_SEGNALAZIONI->value]);
    $s = Segnalazione::create([
        'subject' => "L'ascensore dell'ala «B» è fermo", 'description' => "Prima riga\nseconda riga — con l'apostrofo",
        'priority' => 'urgente', 'stato' => 'in lavorazione', 'condominio_id' => $this->condominio->id,
        'created_by' => $autore->id, 'is_private' => true, 'is_published' => true, 'is_approved' => true,
        'is_featured' => false, 'can_comment' => true,
    ]);
    $s->anagrafiche()->attach($autore->anagrafica->id);
    $prima = $s->fresh()->only(['subject', 'description', 'priority', 'stato', 'condominio_id', 'created_by', 'is_private', 'is_published', 'is_approved']);

    $p = $this->actingAs($autore)->get(route('user.segnalazioni.edit', $s))->assertOk()->viewData('page')['props']['segnalazione'];
    $p = $p['data'] ?? $p;
    // Il corpo che manda `segnalazioni/user/SegnalazioniEdit.vue`.
    $this->actingAs($autore)->put(route('user.segnalazioni.update', $s), [
        'subject' => $p['subject'], 'description' => $p['description'], 'priority' => $p['priority'], 'stato' => $p['stato'],
        'condominio_id' => $p['condominio']['option']['value'], 'is_private' => (bool) $p['is_private'],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($s->fresh()->only(array_keys($prima)))->toEqual($prima)
        ->and($s->fresh()->anagrafiche()->pluck('anagrafiche.id')->all())->toBe([$autore->anagrafica->id]);
});

test('Coda 260 — la modifica di una comunicazione dal portale, aperta e salvata senza toccare niente, non cambia niente', function () {
    $autore = viciniResidente($this->condominio, 'Nadia Conti', 'nadia@example.test', [], [Permission::EDIT_OWN_COMUNICAZIONI->value]);
    $c = Comunicazione::create([
        'subject' => "Lavori sull'impianto — «urgenti»", 'description' => "Prima riga\nseconda riga",
        'priority' => 'urgente', 'created_by' => $autore->id, 'is_featured' => true, 'is_private' => true,
        'is_published' => true, 'is_approved' => true, 'can_comment' => false,
    ]);
    $c->condomini()->attach($this->condominio->id);
    $c->anagrafiche()->attach($autore->anagrafica->id);
    $prima = $c->fresh()->only(['subject', 'description', 'priority', 'created_by', 'is_featured', 'is_private', 'is_published', 'is_approved']);

    $p = $this->actingAs($autore)->get(route('user.comunicazioni.edit', $c))->assertOk()->viewData('page')['props']['comunicazione'];
    $p = $p['data'] ?? $p;
    // Il corpo che manda `comunicazioni/user/ComunicazioniEdit.vue`.
    $this->actingAs($autore)->put(route('user.comunicazioni.update', $c), [
        'subject' => $p['subject'], 'description' => $p['description'], 'priority' => $p['priority'],
        'condomini_ids' => collect($p['condomini']['options'])->pluck('value')->all(),
        'is_featured' => (bool) $p['is_featured'], 'is_private' => (bool) $p['is_private'],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($c->fresh()->only(array_keys($prima)))->toEqual($prima)
        ->and($c->fresh()->condomini()->pluck('condomini.id')->all())->toBe([$this->condominio->id])
        ->and($c->fresh()->anagrafiche()->pluck('anagrafiche.id')->all())->toBe([$autore->anagrafica->id]);
});

test('Coda 260 — la modifica di un documento dal portale, aperta e salvata senza toccare niente, non cambia niente', function () {
    $autore = viciniResidente($this->condominio, 'Nadia Conti', 'nadia@example.test', [], [Permission::EDIT_OWN_ARCHIVE_DOCUMENTS->value]);
    $verbali = CategoriaDocumento::create(['name' => 'Verbali', 'description' => 'Assemblee']);
    $contratti = CategoriaDocumento::create(['name' => 'Contratti', 'description' => 'Fornitori']);
    $d = viciniDocumento($this->condominio, $autore, $verbali);
    $d->categorie()->attach($contratti->id);
    $d->update(['name' => "Verbale dell'assemblea «straordinaria»", 'description' => "Prima riga\nseconda riga"]);
    $prima = $d->fresh()->only(['name', 'description', 'created_by', 'is_published', 'is_approved', 'path']);

    $p = $this->actingAs($autore)->get(route('user.documenti.edit', $d))->assertOk()->viewData('page')['props']['documento'];
    $p = $p['data'] ?? $p;
    // Il corpo che manda `documenti/user/DocumentiEdit.vue` (senza file).
    $this->actingAs($autore)->post(route('user.documenti.update', $d), [
        'name' => $p['name'], 'description' => $p['description'], 'is_published' => (bool) $p['is_published'],
        'categorie' => collect($p['categorie'])->pluck('id')->all(),
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($d->fresh()->only(array_keys($prima)))->toEqual($prima)
        ->and($d->fresh()->categorie()->pluck('categorie_documento.id')->sort()->values()->all())
        ->toBe(collect([$verbali->id, $contratti->id])->sort()->values()->all());
});
