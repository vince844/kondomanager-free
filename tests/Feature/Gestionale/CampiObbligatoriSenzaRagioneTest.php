<?php

/**
 * I campi che il programma pretendeva senza averne bisogno.
 *
 * ## Origine: quattro segnalazioni di un amministratore sul forum, 18/08/2026
 *
 * Le prime tre di questo file sono la stessa storia raccontata su due schermate vicine — *il
 * programma chiede una cosa che non gli serve, o dice una cosa e ne fa un'altra*:
 *
 * 1. Sul caricamento di un documento di un'unità l'etichetta dice **«Descrizione (Opzionale)»** e la
 *    regola pretende il campo. Due testi scritti in momenti diversi che nessuno ha messo a confronto.
 * 2. Creando un'unità la **descrizione** è obbligatoria senza una ragione: la colonna a database
 *    accetta il vuoto e nessun calcolo la legge.
 * 3. Creando un'unità è obbligatorio anche l'**interno**, e la segnalazione porta il caso che smonta
 *    la regola: *«nel caso di un posto auto esterno non collegato a un immobile non credo abbia senso
 *    riportare questo dato»*.
 *
 * ## Perché l'interno è diverso dagli altri due, e perché comunque si può togliere
 *
 * Gli altri due sono regole rimaste lì. L'interno invece **serve davvero**: è il dato con cui le
 * stampe di riparto distinguono due unità, e la colonna a database è `NOT NULL`. Toglierlo sembrava
 * richiedere una migrazione. Non la richiede, per una ragione che vale la pena scrivere: entrambe le
 * request passano da `prepareForValidation()` con `strtoupper((string) $this->interno)`, e su un
 * valore assente `(string) null` dà **stringa vuota** — che il vincolo `NOT NULL` accetta.
 *
 * E l'unità non resta senza identità: `nome` è obbligatorio e le stampe lo mettono in testa, con
 * l'interno come riga sotto («Int. —» quando manca). Un posto auto si chiama «Posto auto 3» e si
 * riconosce; oggi è costretto a fingere un interno che finisce stampato nei riparti.
 *
 * ## Cosa questo file NON copre
 *
 * Non copre il limite di caricamento dei file (è l'altra segnalazione della stessa serie e ha il suo
 * file), né la resa delle stampe, che si guarda a video.
 */

use App\Models\Condominio;
use App\Models\Immobile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);

    // Il modulo dei documenti chiede `hasPermissionTo()` su questo permesso dentro
    // `prepareForValidation()`, e Spatie **solleva** se il permesso non esiste a database invece di
    // rispondere «no»: senza questa riga la richiesta muore con un 500 prima della validazione.
    $pubblica = Permission::firstOrCreate([
        'name'       => \App\Enums\Permission::PUBLISH_ARCHIVE_DOCUMENTS->value,
        'guard_name' => 'web',
    ]);

    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $ruolo->givePermissionTo($pubblica);

    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);

    $this->condominio = Condominio::factory()->create();

    // Le rotte del gestionale passano da `EnsureCondominioHasEsercizio` e
    // `EnsureCondominioHasPianoConti`: senza questi due la richiesta viene deviata prima di
    // arrivare al controller, e il test fallirebbe per un motivo che non c'entra con ciò che prova.
    \App\Models\Esercizio::factory()->create(['condominio_id' => $this->condominio->id]);
    \App\Models\Gestionale\PianoConto::factory()->create(['condominio_id' => $this->condominio->id]);
});

/**
 * I campi minimi di un'unità, senza descrizione e senza interno.
 *
 * La tipologia si crea qui: `RefreshDatabase` svuota anche le tabelle di appoggio, e senza una riga
 * il modulo verrebbe respinto per un motivo che non c'entra con quello che questi test provano.
 */
function immobileMinimo(Condominio $condominio, User $autore, array $modifiche = []): array
{
    $tipologiaId = DB::table('tipologie_immobili')->value('id')
        ?? DB::table('tipologie_immobili')->insertGetId([
            'nome'       => 'Posto auto',
            'categoria'  => 'C/6',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

    return array_merge([
        'nome'          => 'Posto auto 3',
        'condominio_id' => $condominio->id,
        'tipologia_id'  => $tipologiaId,
        'created_by'    => $autore->id,
    ], $modifiche);
}

it('un\'unità si crea senza descrizione: la colonna accetta il vuoto e nessun calcolo la legge', function () {
    $this->actingAs($this->user)
        ->post(route('admin.gestionale.immobili.store', ['condominio' => $this->condominio->id]),
            immobileMinimo($this->condominio, $this->user, ['interno' => 'P3']))
        ->assertSessionHasNoErrors();

    expect(Immobile::where('condominio_id', $this->condominio->id)->count())->toBe(1);
});

it('un posto auto esterno si crea senza interno, ed è il caso che ha aperto la segnalazione', function () {
    $this->actingAs($this->user)
        ->post(route('admin.gestionale.immobili.store', ['condominio' => $this->condominio->id]),
            immobileMinimo($this->condominio, $this->user))
        ->assertSessionHasNoErrors();

    $immobile = Immobile::where('condominio_id', $this->condominio->id)->first();

    expect($immobile)->not->toBeNull()
        ->and($immobile->nome)->toBe('Posto auto 3');
});

it('senza interno il vincolo del database regge lo stesso: si scrive stringa vuota, non null', function () {
    // È la ragione per cui questa correzione **non porta una migrazione**: `prepareForValidation()`
    // fa `strtoupper((string) $this->interno)`, e su un valore assente `(string) null` dà ''.
    // Se un domani quella conversione sparisse, l'inserimento violerebbe `NOT NULL` e questo test
    // se ne accorgerebbe prima dell'utente.
    $this->actingAs($this->user)
        ->post(route('admin.gestionale.immobili.store', ['condominio' => $this->condominio->id]),
            immobileMinimo($this->condominio, $this->user));

    $interno = DB::table('immobili')->where('condominio_id', $this->condominio->id)->value('interno');

    expect($interno)->not->toBeNull()->toBe('');
});

it('il nome resta obbligatorio: è ciò che tiene identificabile l\'unità nelle stampe', function () {
    // Controprova: togliere l'interno è sicuro **perché** il nome non si può togliere. Se un giorno
    // diventasse facoltativo anche quello, un'unità potrebbe finire nei riparti senza niente che la
    // distingua, e questo test lo direbbe prima.
    $senzaNome = immobileMinimo($this->condominio, $this->user);
    unset($senzaNome['nome']);

    $this->actingAs($this->user)
        ->post(route('admin.gestionale.immobili.store', ['condominio' => $this->condominio->id]), $senzaNome)
        ->assertSessionHasErrors('nome');
});

it('un documento di un\'unità si carica senza descrizione, come promette la sua etichetta', function () {
    Storage::fake('local');

    $immobile = Immobile::create([
        'condominio_id' => $this->condominio->id,
        'nome'          => 'Appartamento 1',
        'descrizione'   => 'Prova',
        'interno'       => '1',
    ]);

    $this->actingAs($this->user)
        ->post(route('admin.gestionale.immobili.documenti.store', [
            'condominio' => $this->condominio->id,
            'immobile'   => $immobile->id,
        ]), [
            'name'         => 'Visura catastale',
            'file'         => UploadedFile::fake()->create('visura.pdf', 120, 'application/pdf'),
            'is_published' => true,
            'is_approved'  => true,
            'created_by'   => $this->user->id,
        ])
        ->assertSessionHasNoErrors();

    // ⚠️ Senza questa riga il test è un falso verde: `assertSessionHasNoErrors()` passa anche se la
    // richiesta è stata respinta prima della validazione (permesso mancante, redirect, 403). Il
    // documento va cercato in archivio, che è l'unica prova che il caricamento è avvenuto.
    expect(\App\Models\Documento::where('name', 'Visura catastale')->exists())->toBeTrue();
});
