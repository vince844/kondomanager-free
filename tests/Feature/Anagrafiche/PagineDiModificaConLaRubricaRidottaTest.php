<?php

/**
 * # Le pagine di modifica di una comunicazione, di una scadenza in agenda e di un documento portano della rubrica solo il nome (Coda 408)
 *
 * Le tre pagine (`ComunicazioniEdit.vue`, `EventiEdit.vue`, `DocumentiEdit.vue`) hanno una tendina «Destinatari» che
 * mostra le persone per nome. Di ogni persona leggono soltanto `id` e `nome`, nell'elenco delle persone che il controller
 * manda e nei destinatari già scelti dentro la comunicazione, la scadenza o il documento: il server manda quei due campi,
 * come la tendina `fetch-anagrafiche` (`TendinaDeiDestinatariTest.php`). Codice fiscale, telefoni, email, indirizzo e
 * documento di una persona restano nella sua scheda, che chiede «Visualizza anagrafiche». La pagina di una comunicazione
 * non porta nemmeno la scheda di chi l'ha scritta, che non legge.
 *
 * Le pagine «nuovo» mandano un elenco vuoto: la tendina si riempie dopo la scelta dei condomìni.
 *
 * Cosa NON copre: la tendina `fetch-anagrafiche` (`TendinaDeiDestinatariTest.php`); le pagine, che leggono i due campi
 * (`resources/js/pages/comunicazioni/ComunicazioniEdit.test.ts`, `eventi/EventiEdit.test.ts`, `documenti/DocumentiEdit.test.ts`).
 */

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\Comunicazione;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Evento;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role as RuoloSpatie;

/** Ciò che di una persona resta nella sua scheda. */
const RUBRICA_RISERVATA = ['DNGMRA80A01H501U', '0612345678', '3331234567', 'mario.dangelo@example.com', 'Nota riservata', 'CA12345XY',
    'VRDNNA85C41H501Z', 'anna.verdi@example.com', 'BNCLRA70B01H501W', 'Via dei Platani 9'];

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();
    Storage::fake('local');

    $this->aurora = Condominio::factory()->create(['nome' => 'Condominio Aurora']);

    $this->mario = Anagrafica::create([
        'nome' => "Mario D'Àngelo", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'DNGMRA80A01H501U', 'telefono' => '0612345678',
        'cellulare' => '3331234567', 'email' => 'mario.dangelo@example.com', 'numero_documento' => 'CA12345XY', 'note' => 'Nota riservata',
    ]);
    $this->anna = Anagrafica::create([
        'nome' => 'Anna Verdi', 'indirizzo' => 'Via Po 2', 'codice_fiscale' => 'VRDNNA85C41H501Z', 'email' => 'anna.verdi@example.com',
    ]);

    // Chi ha scritto la comunicazione ha una persona in rubrica, con i suoi dati.
    $this->autore = User::factory()->create();
    $this->laura = Anagrafica::create([
        'nome' => 'Laura Bianchi', 'indirizzo' => 'Via dei Platani 9', 'codice_fiscale' => 'BNCLRA70B01H501W', 'user_id' => $this->autore->id,
    ]);

    $this->comunicazione = Comunicazione::create([
        'subject' => 'Avviso di prova', 'description' => 'Testo', 'priority' => 'media', 'created_by' => $this->autore->id, 'is_private' => false,
        'is_published' => true, 'is_approved' => true, 'is_featured' => false, 'can_comment' => false,
    ]);
    $this->comunicazione->condomini()->attach($this->aurora->id);
    $this->comunicazione->anagrafiche()->attach($this->mario->id);

    $this->evento = Evento::create([
        'title' => 'Assemblea di prova', 'start_time' => now()->addDay(), 'end_time' => now()->addDay()->addHour(),
        'created_by' => $this->autore->id, 'visibility' => 'public', 'is_approved' => true,
    ]);
    $this->evento->condomini()->attach($this->aurora->id);
    $this->evento->anagrafiche()->attach($this->mario->id);

    Storage::disk('local')->put('documenti/verbale.pdf', '%PDF-1.4 verbale');
    $this->documento = Documento::create([
        'name' => 'Verbale di prova', 'description' => 'Testo', 'path' => 'documenti/verbale.pdf', 'mime_type' => 'application/pdf',
        'file_size' => 16, 'created_by' => $this->autore->id, 'is_published' => true, 'is_approved' => true,
    ]);
    $this->documento->condomini()->attach($this->aurora->id);
    $this->documento->anagrafiche()->attach($this->mario->id);
});

function ridottaUtente(string $ruolo): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole($ruolo);

    return $user->fresh();
}

/** Un ruolo su misura che modifica comunicazioni, scadenze e documenti e non consulta le anagrafiche. */
function ridottaRedazione(): User
{
    RuoloSpatie::findOrCreate('Redazione', 'web')->syncPermissions([
        Permission::ACCESS_ADMIN_PANEL->value, Permission::EDIT_COMUNICAZIONI->value, Permission::EDIT_EVENTS->value, Permission::EDIT_ARCHIVE_DOCUMENTS->value,
    ]);

    return ridottaUtente('Redazione');
}

/** Le tre pagine di modifica, con il nome dell'oggetto che modificano nelle props e la rotta che le apre. */
function ridottaPagine($test): array
{
    return [
        'comunicazione' => [route('admin.comunicazioni.edit', $test->comunicazione), 'comunicazione'],
        'scadenza' => [route('admin.eventi.edit', $test->evento), 'evento'],
        'documento' => [route('admin.documenti.edit', $test->documento), 'documento'],
    ];
}

test('Coda 408 — le pagine di modifica non portano dati delle persone a chi non consulta le anagrafiche', function () {
    $redazione = ridottaRedazione();
    expect($redazione->hasPermissionTo(Permission::VIEW_ANAGRAFICHE->value))->toBeFalse();
    $this->actingAs($redazione)->get(route('admin.anagrafiche.index'))->assertForbidden();

    foreach (ridottaPagine($this) as $pagina => [$url]) {
        $risposta = $this->actingAs($redazione)->get($url);

        $risposta->assertOk();
        foreach (RUBRICA_RISERVATA as $dato) {
            expect($risposta->getContent(), "$pagina: $dato")->not->toContain($dato);
        }
    }
});

test('Coda 408 — l\'elenco delle persone è fatto di id e nome, lo stesso per chi consulta le anagrafiche e per chi no', function (string $chi) {
    $utente = $chi === 'Redazione' ? ridottaRedazione() : ridottaUtente($chi);
    $attese = [
        ['id' => $this->mario->id, 'nome' => "Mario D'Àngelo"],
        ['id' => $this->anna->id, 'nome' => 'Anna Verdi'],
        ['id' => $this->laura->id, 'nome' => 'Laura Bianchi'],
    ];

    foreach (ridottaPagine($this) as $pagina => [$url]) {
        $props = $this->actingAs($utente)->get($url)->assertOk()->viewData('page')['props'];

        expect($props['anagrafiche'], $pagina)->toBe($attese);
    }
})->with([
    'un ruolo su misura senza «Visualizza anagrafiche»' => ['Redazione'],
    'il collaboratore di serie' => [Role::COLLABORATORE->value],
    'l\'amministratore' => [Role::AMMINISTRATORE->value],
]);

test('Coda 408 — i destinatari già scelti restano nella pagina, con id e nome', function () {
    $amministratore = ridottaUtente(Role::AMMINISTRATORE->value);

    foreach (ridottaPagine($this) as $pagina => [$url, $oggetto]) {
        $props = $this->actingAs($amministratore)->get($url)->assertOk()->viewData('page')['props'];
        $scelti = $props[$oggetto]['anagrafiche'];

        expect(array_column($scelti, 'id'), $pagina)->toBe([$this->mario->id])
            ->and($scelti[0]['nome'], $pagina)->toBe("Mario D'Àngelo");
        foreach (['codice_fiscale', 'email', 'telefono', 'cellulare', 'indirizzo'] as $campo) {
            expect($scelti[0][$campo] ?? null, "$pagina: $campo")->toBeNull();
        }
    }
});

test('Coda 408 — la pagina di una comunicazione non porta la scheda di chi l\'ha scritta', function () {
    $props = $this->actingAs(ridottaRedazione())->get(route('admin.comunicazioni.edit', $this->comunicazione))->assertOk()->viewData('page')['props'];

    expect($props['comunicazione'])->not->toHaveKey('created_by')
        ->and(json_encode($props, JSON_UNESCAPED_UNICODE))->not->toContain('BNCLRA70B01H501W')->not->toContain('Via dei Platani 9');
});

test('Coda 408 — le pagine «nuovo» mandano un elenco di persone vuoto, a chi le apre', function () {
    $redazione = ridottaUtente(Role::AMMINISTRATORE->value);

    foreach ([route('admin.comunicazioni.create'), route('admin.eventi.create'), route('admin.documenti.create')] as $url) {
        $props = $this->actingAs($redazione)->get($url)->assertOk()->viewData('page')['props'];

        expect($props['anagrafiche'])->toBe([]);
    }
});
