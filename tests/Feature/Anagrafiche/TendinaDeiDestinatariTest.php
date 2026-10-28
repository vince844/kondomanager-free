<?php

/**
 * # La tendina dei destinatari risponde il nome, e solo a chi scrive per i destinatari (Coda 402)
 *
 * `GET /admin/fetch-anagrafiche` riempie la tendina «Destinatari» di sei pagine del pannello: nuova e modifica di una
 * comunicazione (`ComunicazioniNew.vue`, `ComunicazioniEdit.vue`), di una scadenza in agenda (`EventiNew.vue`,
 * `EventiEdit.vue`) e di un documento dell'archivio (`DocumentiNew.vue`, `DocumentiEdit.vue`). Le sei pagine leggono
 * della risposta soltanto `id` e `nome`: la rotta risponde quei due campi, e solo a chi ha uno dei permessi con cui
 * quelle pagine si aprono (creare o modificare comunicazioni, scadenze in agenda, documenti dell'archivio, anche i
 * propri). Entrare nel pannello non basta; «Visualizza anagrafiche» non c'entra, perché la tendina non è la rubrica.
 *
 * I palazzi chiesti non si restringono: nel pannello le sei pagine offrono tutti i condomìni a chi le apre
 * (`Condominio::all()` nei controller), e la tendina segue lo stesso perimetro.
 *
 * Cosa NON copre: le sei pagine, che la simulano (`resources/js/pages/comunicazioni/ComunicazioniEdit.test.ts`).
 */

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Role as RuoloSpatie;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->aurora = Condominio::factory()->create(['nome' => 'Condominio Aurora']);
    $this->borea = Condominio::factory()->create(['nome' => 'Condominio Borea']);

    $this->mario = Anagrafica::create([
        'nome' => "Mario D'Àngelo", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'DNGMRA80A01H501U',
        'telefono' => '0612345678', 'cellulare' => '3331234567', 'email' => 'mario.dangelo@example.com',
        'tipologia_documento' => 'id_card', 'numero_documento' => 'CA12345XY', 'scadenza_documento' => '2030-01-31',
        'luogo_nascita' => 'Roma', 'data_nascita' => '1980-01-01', 'note' => 'Nota riservata sul condòmino',
    ]);
    $this->mario->condomini()->attach($this->aurora->id);

    $this->luca = Anagrafica::create(['nome' => 'Luca Verdi', 'indirizzo' => 'Via Po 2', 'codice_fiscale' => 'VRDLCU70B02F205X']);
    $this->luca->condomini()->attach($this->borea->id);
});

function tendinaUtente(string $ruolo): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole($ruolo);

    return $user->fresh();
}

function tendinaSuMisura(array $permessi): User
{
    RuoloSpatie::findOrCreate('Su misura', 'web')->syncPermissions(array_merge([Permission::ACCESS_ADMIN_PANEL->value], $permessi));

    return tendinaUtente('Su misura');
}

/** La chiamata come la manda la pagina con axios: `condomini_ids[]` per ogni palazzo scelto. */
function tendinaChiedi($test, User $chi, array $palazzi)
{
    return $test->actingAs($chi)->getJson('/admin/fetch-anagrafiche?'.http_build_query(['condomini_ids' => array_map(fn ($c) => $c->id, $palazzi)]));
}

/** I dati della persona che la tendina non mostra. */
const TENDINA_DATI_CHE_NON_SERVONO = ['DNGMRA80A01H501U', '0612345678', '3331234567', 'mario.dangelo@example.com', 'CA12345XY', '2030-01-31', '1980-01-01', 'Nota riservata sul condòmino'];

test('Coda 402 — la tendina dei destinatari risponde soltanto l\'id e il nome delle persone dei palazzi scelti', function (string $ruolo) {
    $risposta = tendinaChiedi($this, tendinaUtente($ruolo), [$this->aurora])->assertOk();

    expect($risposta->json())->toBe([['id' => $this->mario->id, 'nome' => "Mario D'Àngelo"]]);
    foreach (TENDINA_DATI_CHE_NON_SERVONO as $dato) {
        expect($risposta->getContent())->not->toContain($dato);
    }

    expect(collect(tendinaChiedi($this, tendinaUtente($ruolo), [$this->aurora, $this->borea])->assertOk()->json())->pluck('nome')->sort()->values()->all())
        ->toBe(["Luca Verdi", "Mario D'Àngelo"]);
})->with([Role::AMMINISTRATORE->value, Role::COLLABORATORE->value]);

test('Coda 402 — con il solo accesso al pannello la tendina dei destinatari non risponde', function () {
    $risposta = tendinaChiedi($this, tendinaSuMisura([]), [$this->aurora, $this->borea]);

    $risposta->assertForbidden()->assertJson(['message' => __('anagrafiche.permessi.destinatari')]);
    expect($risposta->getContent())->not->toContain("Mario D")->not->toContain('Luca Verdi')->not->toContain('DNGMRA80A01H501U');
});

test('Coda 402 — la tendina risponde a chi apre una delle sei pagine che la usano', function (string $permesso) {
    $risposta = tendinaChiedi($this, tendinaSuMisura([$permesso]), [$this->aurora])->assertOk();

    expect($risposta->json())->toBe([['id' => $this->mario->id, 'nome' => "Mario D'Àngelo"]]);
})->with([
    Permission::CREATE_COMUNICAZIONI->value,
    Permission::EDIT_COMUNICAZIONI->value,
    Permission::EDIT_OWN_COMUNICAZIONI->value,
    Permission::CREATE_EVENTS->value,
    Permission::EDIT_EVENTS->value,
    Permission::EDIT_OWN_EVENTS->value,
    Permission::CREATE_ARCHIVE_DOCUMENTS->value,
    Permission::EDIT_ARCHIVE_DOCUMENTS->value,
    Permission::EDIT_OWN_ARCHIVE_DOCUMENTS->value,
]);

test('Coda 402 — chi consulta soltanto (le anagrafiche, le comunicazioni, l\'agenda, l\'archivio) non riceve la tendina dei destinatari', function (string $permesso) {
    tendinaChiedi($this, tendinaSuMisura([$permesso]), [$this->aurora])->assertForbidden();
})->with([
    Permission::VIEW_ANAGRAFICHE->value,
    Permission::VIEW_COMUNICAZIONI->value,
    Permission::VIEW_EVENTS->value,
    Permission::VIEW_ARCHIVE_DOCUMENTS->value,
]);
