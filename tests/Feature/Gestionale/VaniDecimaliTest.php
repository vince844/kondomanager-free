<?php

/**
 * Il numero di vani accetta i valori non interi, e il rifiuto ha dove comparire.
 *
 * ## Origine: segnalazione dal forum, agosto 2026
 *
 * > *«nell'anagrafica di un immobile è possibile riportare il "Numero vani" […] Il sistema però
 * > non permette di inserire un numero di vani non intero (e in alcune visure catastali tale
 * > valore non è intero), **non dà errori ma non salva il dato**. Nello specifico ho provato sia
 * > ad inserire "6.5" che "6,5".»*
 *
 * ## La segnalazione descrive un difetto più piccolo di quello vero, in due punti
 *
 * **Primo: non è «non salva il dato», è «non salva niente».** La colonna era `integer` e la regola
 * `integer`: `6.5` faceva fallire la validazione, e con lei l'**intera** richiesta. In creazione
 * l'unità non nasceva affatto; in modifica non veniva aggiornato nessun campo. Chi avesse corretto
 * insieme il piano, la superficie e i vani si sarebbe perso anche le altre due correzioni.
 *
 * **Secondo: «non dà errori» non è un modo di dire.** L'errore c'era — *«numero vani deve essere
 * un intero»* — e non aveva **nessun posto dove comparire**: la scheda dell'unità valida sedici
 * campi e ne rende quattro con un `InputError`. Il rifiuto veniva pronunciato dal server, tornava
 * in sessione, e moriva lì. È la lezione della beta.49 — *un rifiuto che il server pronuncia e la
 * schermata non mostra vale quanto un rifiuto non pronunciato* — su una schermata che quella
 * lezione non aveva mai attraversato.
 *
 * Correggere solo il primo punto avrebbe chiuso il caso e lasciato in piedi il meccanismo che lo
 * ha reso invisibile: la stessa buca era già aperta sulla **superficie**, dove `90,5` all'italiana
 * falliva esattamente allo stesso modo, e nessuno l'aveva ancora segnalata.
 *
 * ## La virgola non è un capriccio
 *
 * Davanti a una visura catastale un amministratore italiano batte `6,5`. La convenzione del
 * progetto è quella scritta nella beta.61 per i millesimi: *«chi la batte per abitudine non deve
 * essere corretto da un messaggio d'errore, gli si normalizza il valore e basta»*. Qui la
 * normalizzazione sta in `prepareForValidation()`, cioè prima della regola, per tutti e due i
 * campi decimali della scheda.
 *
 * ## Perché `decimal:0,2` e non `numeric`
 *
 * `numeric` accetterebbe `6.555`, che MySQL scriverebbe in colonna come `6.56` — un valore
 * cambiato in silenzio. È il difetto che la beta.61 ha appena pagato sui millesimi. Con
 * `decimal:0,2` il terzo decimale viene **rifiutato con un messaggio**, che ora ha anche dove
 * comparire.
 *
 * ## Cosa questo file NON copre
 *
 * Non copre gli altri dieci campi della scheda che restavano muti e che questa beta ha dotato di
 * un `InputError` (piano, note, palazzina, scala e i cinque catastali): lì non c'è una regola che
 * si possa violare in modo interessante, e la loro prova è la lettura del template. Non copre la
 * resa a video del valore — «6,5 vani» invece di «6.50 vani» — che si guarda a schermo. Non copre
 * l'importatore, che oggi il campo non lo scrive.
 */

use App\Models\Condominio;
use App\Models\Immobile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(
        Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web'])
    );

    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);

    $this->condominio = Condominio::factory()->create();
    \App\Models\Esercizio::factory()->create(['condominio_id' => $this->condominio->id]);
    \App\Models\Gestionale\PianoConto::factory()->create(['condominio_id' => $this->condominio->id]);

    $this->tipologiaId = DB::table('tipologie_immobili')->value('id')
        ?? DB::table('tipologie_immobili')->insertGetId([
            'nome'       => 'Appartamento',
            'categoria'  => 'A/2',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
});

function unitaConVani(Condominio $condominio, User $autore, int $tipologiaId, array $modifiche = []): array
{
    return array_merge([
        'nome'          => 'Appartamento 4',
        'interno'       => '4',
        'condominio_id' => $condominio->id,
        'tipologia_id'  => $tipologiaId,
        'created_by'    => $autore->id,
    ], $modifiche);
}

it('un\'unità si crea con 6.5 vani, che è il valore della visura', function () {
    $this->actingAs($this->user)
        ->post(route('admin.gestionale.immobili.store', ['condominio' => $this->condominio->id]),
            unitaConVani($this->condominio, $this->user, $this->tipologiaId, ['numero_vani' => '6.5']))
        ->assertSessionHasNoErrors();

    $immobile = Immobile::where('condominio_id', $this->condominio->id)->first();

    expect($immobile)->not->toBeNull()
        ->and((float) $immobile->numero_vani)->toBe(6.5);
});

it('e si crea anche con 6,5, perché è così che si scrive in italiano', function () {
    // Prima della correzione questo caso non falliva «peggio» dell'altro: falliva **uguale**, e
    // senza dirlo. La normalizzazione della virgola sta in `prepareForValidation()`, quindi la
    // regola vede sempre e solo il punto.
    $this->actingAs($this->user)
        ->post(route('admin.gestionale.immobili.store', ['condominio' => $this->condominio->id]),
            unitaConVani($this->condominio, $this->user, $this->tipologiaId, ['numero_vani' => '6,5']))
        ->assertSessionHasNoErrors();

    expect((float) Immobile::where('condominio_id', $this->condominio->id)->first()->numero_vani)->toBe(6.5);
});

it('la stessa cosa vale in modifica, che è dove la segnalazione se ne è accorta', function () {
    $immobile = Immobile::create(unitaConVani($this->condominio, $this->user, $this->tipologiaId, [
        'numero_vani'  => 6,
        'descrizione'  => '',
        'codice_immobile' => 'TEST-0001',
    ]));

    $this->actingAs($this->user)
        ->put(route('admin.gestionale.immobili.update', [
            'condominio' => $this->condominio->id,
            'immobile'   => $immobile->id,
        ]), unitaConVani($this->condominio, $this->user, $this->tipologiaId, ['numero_vani' => '6,5']))
        ->assertSessionHasNoErrors();

    expect((float) $immobile->fresh()->numero_vani)->toBe(6.5);
});

it('il quarto di vano ci sta: la colonna ne conserva due, di decimali', function () {
    // È la ragione per cui la colonna è `decimal(5,2)` e non `decimal(4,1)` come prevedeva il
    // piano lavori: un `ALTER` in più su una release senza backup automatico costa più dei due
    // caratteri risparmiati nella dichiarazione.
    $this->actingAs($this->user)
        ->post(route('admin.gestionale.immobili.store', ['condominio' => $this->condominio->id]),
            unitaConVani($this->condominio, $this->user, $this->tipologiaId, ['numero_vani' => '6,25']))
        ->assertSessionHasNoErrors();

    expect((float) Immobile::where('condominio_id', $this->condominio->id)->first()->numero_vani)->toBe(6.25);
});

it('il terzo decimale viene rifiutato con un messaggio, non arrotondato in silenzio', function () {
    // ⚠️ **È questo test a tenere in piedi `decimal:0,2`.** Con `numeric` al suo posto un `6,555`
    // passerebbe la regola, l'unità verrebbe creata e MySQL scriverebbe `6.56` in colonna — un
    // valore cambiato in silenzio, il difetto che la beta.61 ha pagato sui millesimi. Diventerebbero
    // rosse tutt'e due le asserzioni qui sotto, ed è la ragione per cui ci sono tutt'e due: la
    // prima dice che il rifiuto c'è, la seconda che non è stato creato niente.
    //
    // *(La prima stesura di questo commento diceva l'opposto — «con `numeric` questo test
    // passerebbe lo stesso» — cioè annunciava a chi legge che la regola non era presidiata mentre
    // lo era. Corretto dalla revisione avversariale, misurando col validatore vero.)*
    $this->actingAs($this->user)
        ->post(route('admin.gestionale.immobili.store', ['condominio' => $this->condominio->id]),
            unitaConVani($this->condominio, $this->user, $this->tipologiaId, ['numero_vani' => '6,555']))
        ->assertSessionHasErrors('numero_vani');

    expect(Immobile::where('condominio_id', $this->condominio->id)->count())->toBe(0);
});

it('un numero di vani negativo non entra', function () {
    $this->actingAs($this->user)
        ->post(route('admin.gestionale.immobili.store', ['condominio' => $this->condominio->id]),
            unitaConVani($this->condominio, $this->user, $this->tipologiaId, ['numero_vani' => '-2']))
        ->assertSessionHasErrors('numero_vani');
});

it('il campo resta facoltativo: chi non lo compila crea l\'unità lo stesso', function () {
    // Controprova del gruppo qui sopra: allargare la regola non deve aver reso obbligatorio un
    // campo che non lo era. È il caso più frequente in assoluto e quello che nessuno prova.
    $this->actingAs($this->user)
        ->post(route('admin.gestionale.immobili.store', ['condominio' => $this->condominio->id]),
            unitaConVani($this->condominio, $this->user, $this->tipologiaId))
        ->assertSessionHasNoErrors();

    expect(Immobile::where('condominio_id', $this->condominio->id)->first()->numero_vani)->toBeNull();
});

it('anche la superficie accetta la virgola: era la stessa buca, mai segnalata', function () {
    // La superficie era già `decimal(8,2)` e validata `numeric`, quindi `90.5` passava — ma
    // `90,5` no, e il rifiuto era invisibile esattamente come quello dei vani. Il gemello si
    // corregge insieme, o resta aperto finché non lo segnala qualcun altro.
    $this->actingAs($this->user)
        ->post(route('admin.gestionale.immobili.store', ['condominio' => $this->condominio->id]),
            unitaConVani($this->condominio, $this->user, $this->tipologiaId, ['superficie' => '90,5']))
        ->assertSessionHasNoErrors();

    expect((float) Immobile::where('condominio_id', $this->condominio->id)->first()->superficie)->toBe(90.5);
});

it('la colonna a database è decimale, non intera', function () {
    // Il test sul comportamento HTTP passerebbe anche con la colonna ancora `integer` su SQLite,
    // che è permissivo sui tipi. Questo lo chiede allo schema, che è dove il dato vive davvero.
    expect(Schema::getColumnType('immobili', 'numero_vani'))->toBeIn(['decimal', 'numeric']);
});
