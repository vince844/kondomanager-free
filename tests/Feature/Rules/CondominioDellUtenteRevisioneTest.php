<?php

/**
 * Giro di sicurezza della 1.11.0-beta.39, revisione avversariale: due difetti che la prima stesura
 * della Coda 184 aveva lasciato in `CondominioDellUtente` e nelle richieste di comunicazioni ed
 * eventi.
 *
 * 1. **Elemento-array.** `condomini_ids.*` non aveva `integer`. `appartiene()` con un array faceva
 *    un `whereIn` (bastava un condominio dell'utente fra quelli indicati), e `attach()`/`sync()`
 *    leggono un elemento-array come «chiave = id da collegare, valore = colonne del pivot»: con
 *    `[altrui => ['id' => mio]]` la regola controllava il condominio proprio e Laravel agganciava
 *    quello altrui. Ora le quattro richieste hanno `bail` e `integer`, e la regola rifiuta da sola
 *    tutto ciò che non è un id singolo.
 * 2. **Autore staccato.** Chi aveva scritto una comunicazione o un evento su un palazzo da cui poi è
 *    stato staccato non poteva più salvarlo: la pagina di modifica rimanda i condomìni del record,
 *    e la regola li rifiutava. Ora nelle modifiche restano ammessi i condomìni in cui il record è
 *    già; un palazzo nuovo deve essere dell'utente. È lo stesso caso già risolto per le segnalazioni.
 * 3. **L'oracolo (secondo giro).** Ammettere i condomìni già collegati faceva della validazione un
 *    indovino: girando prima della policy, un utente qualunque che mandava una modifica su un record
 *    altrui riceveva 403 per il palazzo giusto ed errore di validazione per gli altri, e scopriva
 *    dove stava il record. Ora le tre richieste di modifica autorizzano in `authorize()`, prima delle
 *    regole: 403 sempre, senza errori in sessione.
 *
 * Ogni test che si aspetta «nessuna riga» ha accanto il caso buono con lo stesso payload corretto:
 * senza, un inserimento che fallisse per un altro motivo farebbe passare il test per la ragione
 * sbagliata.
 *
 * **Cosa resta scoperto**: le pagine Vue mostrano solo `form.errors.condomini_ids`, non
 * `condomini_ids.N`, quindi un rifiuto sul singolo elemento non si vede a video. Il flusso normale
 * non lo produce, perché il select offre solo i condomìni dell'utente. Documenti e segnalazioni
 * avevano già `integer` e non sono ripetuti qui.
 */

use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\VisibilityStatus;
use App\Events\Comunicazioni\NotifyAdminOfCreatedComunicazione;
use App\Models\Anagrafica;
use App\Models\CategoriaEvento;
use App\Models\Comunicazione;
use App\Models\Condominio;
use App\Models\Evento;
use App\Models\User;
use App\Rules\CondominioDellUtente;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    // Solo la notifica all'amministratore. Un `Event::fake()` totale spegnerebbe anche l'hook
    // `creating` che genera lo slug della comunicazione: ogni inserimento fallirebbe su `slug`, e un
    // «nessuna riga» passerebbe anche senza la correzione.
    Event::fake([NotifyAdminOfCreatedComunicazione::class]);
});

/**
 * @param  array<int, string>  $permessi
 * @return array{0: User, 1: Anagrafica}
 */
function condominoConPermessi(Condominio $condominio, array $permessi): array
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole(Role::UTENTE->value);
    if ($permessi !== []) {
        $user->givePermissionTo($permessi);
    }

    $anagrafica = Anagrafica::factory()->create(['user_id' => $user->id]);
    $anagrafica->condomini()->attach($condominio->id);

    return [$user, $anagrafica];
}

function datiComunicazione(array $condominiIds, string $subject = 'Prova'): array
{
    return [
        'subject'       => $subject,
        'description'   => 'Descrizione di prova',
        'priority'      => 'media',
        'is_featured'   => false,
        'is_private'    => false,
        'condomini_ids' => $condominiIds,
    ];
}

function datiEvento(array $condominiIds, int $categoriaId, string $title = 'Prova'): array
{
    return [
        'title'         => $title,
        'start_time'    => now()->addDay()->toDateTimeString(),
        'end_time'      => now()->addDay()->addHour()->toDateTimeString(),
        'category_id'   => $categoriaId,
        'condomini_ids' => $condominiIds,
        'mode'          => 'all',
    ];
}

function condominiDi(Comunicazione|Evento $record): array
{
    return $record->condomini()->pluck('condomini.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
}

// --- la regola, isolata ---------------------------------------------------------------------

test('appartiene dice no a tutto ciò che non è un id singolo', function () {
    $suo = Condominio::factory()->create();
    $altro = Condominio::factory()->create();
    [$user] = condominoConPermessi($suo, []);

    expect(CondominioDellUtente::appartiene($user, $suo->id))->toBeTrue()
        ->and(CondominioDellUtente::appartiene($user, (string) $suo->id))->toBeTrue()
        ->and(CondominioDellUtente::appartiene($user, [$suo->id, $altro->id]))->toBeFalse()
        ->and(CondominioDellUtente::appartiene($user, ['id' => $suo->id]))->toBeFalse()
        ->and(CondominioDellUtente::appartiene($user, $suo->id.' OR 1=1'))->toBeFalse()
        ->and(CondominioDellUtente::appartiene($user, null))->toBeFalse();
});

test('la regola da sola, con giaCollegati e senza integer, rifiuta un elemento-array', function () {
    $suo = Condominio::factory()->create();
    $vecchio = Condominio::factory()->create();
    $altro = Condominio::factory()->create();
    [$user] = condominoConPermessi($suo, []);
    $this->actingAs($user);

    // 1 è fra i già collegati di proposito: (int) di un array non vuoto vale 1.
    $regole = ['condomini_ids.*' => [new CondominioDellUtente([1, $vecchio->id])]];

    // Caso buono: un palazzo di cui non è membro, ma in cui il record è già, passa anche come stringa.
    expect(Validator::make(['condomini_ids' => [(string) $vecchio->id]], $regole)->passes())->toBeTrue();

    // Senza il controllo sull'id singolo nel ramo dei già collegati, questo passerebbe, e
    // attach()/sync() aggancerebbero $altro.
    $v = Validator::make(['condomini_ids' => [$altro->id => ['id' => $suo->id]]], $regole);

    expect($v->fails())->toBeTrue()
        ->and($v->errors()->has('condomini_ids.'.$altro->id))->toBeTrue();
});

// --- elemento-array: comunicazioni ----------------------------------------------------------

test('comunicazione nuova: un elemento-array non aggancia il condominio altrui', function () {
    $suo = Condominio::factory()->create();
    $altro = Condominio::factory()->create();
    [$user] = condominoConPermessi($suo, [Permission::CREATE_COMUNICAZIONI->value]);

    foreach ([[$altro->id => ['id' => $suo->id]], [[$suo->id, $altro->id]]] as $payload) {
        $this->actingAs($user)
            ->post(route('user.comunicazioni.store'), datiComunicazione($payload))
            ->assertSessionHasErrors('condomini_ids.'.array_key_first($payload));
    }

    expect(Comunicazione::count())->toBe(0)
        ->and(DB::table('comunicazione_condominio')->where('condominio_id', $altro->id)->exists())->toBeFalse();

    // Il caso buono, stesso payload con l'id corretto: l'inserimento funziona davvero.
    $this->actingAs($user)
        ->post(route('user.comunicazioni.store'), datiComunicazione([$suo->id]))
        ->assertSessionHasNoErrors();

    expect(condominiDi(Comunicazione::sole()))->toBe([$suo->id]);
});

test('comunicazione in modifica: un elemento-array non la sposta nel condominio altrui', function () {
    $suo = Condominio::factory()->create();
    $altro = Condominio::factory()->create();
    [$user] = condominoConPermessi($suo, [Permission::EDIT_OWN_COMUNICAZIONI->value]);
    $comunicazione = Comunicazione::factory()->create(['created_by' => $user->id, 'subject' => 'Originale']);
    $comunicazione->condomini()->attach($suo->id);

    $this->actingAs($user)
        ->put(route('user.comunicazioni.update', $comunicazione), datiComunicazione([$altro->id => ['id' => $suo->id]], 'Spostata'))
        ->assertSessionHasErrors('condomini_ids.'.$altro->id);

    expect($comunicazione->fresh()->subject)->toBe('Originale')
        ->and(condominiDi($comunicazione))->toBe([$suo->id]);

    $this->actingAs($user)
        ->put(route('user.comunicazioni.update', $comunicazione), datiComunicazione([$suo->id], 'Corretta'))
        ->assertSessionHasNoErrors();

    expect($comunicazione->fresh()->subject)->toBe('Corretta');
});

// --- elemento-array: eventi -----------------------------------------------------------------

test('evento nuovo: un elemento-array non aggancia il condominio altrui', function () {
    $suo = Condominio::factory()->create();
    $altro = Condominio::factory()->create();
    [$user] = condominoConPermessi($suo, [Permission::CREATE_EVENTS->value]);
    $categoria = CategoriaEvento::create(['name' => 'Prova', 'description' => 'Prova']);

    foreach ([[$altro->id => ['id' => $suo->id]], [[$suo->id, $altro->id]]] as $payload) {
        $this->actingAs($user)
            ->post(route('user.eventi.store'), datiEvento($payload, $categoria->id))
            ->assertSessionHasErrors('condomini_ids.'.array_key_first($payload));
    }

    expect(Evento::count())->toBe(0)
        ->and(DB::table('condominio_evento')->where('condominio_id', $altro->id)->exists())->toBeFalse();

    $this->actingAs($user)
        ->post(route('user.eventi.store'), datiEvento([$suo->id], $categoria->id))
        ->assertSessionHasNoErrors();

    expect(condominiDi(Evento::sole()))->toBe([$suo->id]);
});

test('evento in modifica: un elemento-array non lo sposta nel condominio altrui', function () {
    $suo = Condominio::factory()->create();
    $altro = Condominio::factory()->create();
    [$user] = condominoConPermessi($suo, [Permission::EDIT_OWN_EVENTS->value]);
    $categoria = CategoriaEvento::create(['name' => 'Prova', 'description' => 'Prova']);
    $evento = Evento::create([
        'title'       => 'Originale',
        'start_time'  => now()->addDay(),
        'end_time'    => now()->addDay()->addHour(),
        'created_by'  => $user->id,
        'category_id' => $categoria->id,
        'visibility'  => VisibilityStatus::HIDDEN->value,
        'is_approved' => false,
    ]);
    $evento->condomini()->attach($suo->id);

    $this->actingAs($user)
        ->put(route('user.eventi.update', $evento), datiEvento([$altro->id => ['id' => $suo->id]], $categoria->id, 'Spostato'))
        ->assertSessionHasErrors('condomini_ids.'.$altro->id);

    expect($evento->fresh()->title)->toBe('Originale')
        ->and(condominiDi($evento))->toBe([$suo->id]);

    $this->actingAs($user)
        ->put(route('user.eventi.update', $evento), datiEvento([$suo->id], $categoria->id, 'Corretto'))
        ->assertSessionHasNoErrors();

    expect($evento->fresh()->title)->toBe('Corretto');
});

// --- autore staccato dal palazzo ------------------------------------------------------------

test('comunicazione: l\'autore staccato dal palazzo la corregge, ma non la sposta in un palazzo altrui', function () {
    $vecchio = Condominio::factory()->create();
    $attuale = Condominio::factory()->create();
    $altrui = Condominio::factory()->create();
    [$user, $anagrafica] = condominoConPermessi($attuale, [Permission::EDIT_OWN_COMUNICAZIONI->value]);
    $comunicazione = Comunicazione::factory()->create(['created_by' => $user->id, 'subject' => 'Originale']);
    $comunicazione->condomini()->attach($vecchio->id);
    // Non è mai stato membro di `vecchio` in questo test, che è lo stesso caso di chi ne è stato
    // staccato: la regola guarda solo l'appartenenza di adesso.

    $this->actingAs($user)
        ->put(route('user.comunicazioni.update', $comunicazione), datiComunicazione([$vecchio->id], 'Titolo corretto'))
        ->assertSessionHasNoErrors();
    expect($comunicazione->fresh()->subject)->toBe('Titolo corretto')
        ->and(condominiDi($comunicazione))->toBe([$vecchio->id]);

    $this->actingAs($user)
        ->put(route('user.comunicazioni.update', $comunicazione), datiComunicazione([$altrui->id], 'Spostata'))
        ->assertSessionHasErrors('condomini_ids.0');
    $this->actingAs($user)
        ->put(route('user.comunicazioni.update', $comunicazione), datiComunicazione([$vecchio->id, $altrui->id], 'Allargata'))
        ->assertSessionHasErrors('condomini_ids.1');

    expect($comunicazione->fresh()->subject)->toBe('Titolo corretto')
        ->and(condominiDi($comunicazione))->toBe([$vecchio->id]);

    // Il palazzo in cui è membro adesso resta sempre ammesso.
    $this->actingAs($user)
        ->put(route('user.comunicazioni.update', $comunicazione), datiComunicazione([$vecchio->id, $attuale->id], 'Anche nel mio'))
        ->assertSessionHasNoErrors();
    expect(condominiDi($comunicazione))->toBe([$vecchio->id, $attuale->id]);
});

test('evento: l\'autore staccato dal palazzo lo corregge, ma non lo sposta in un palazzo altrui', function () {
    $vecchio = Condominio::factory()->create();
    $attuale = Condominio::factory()->create();
    $altrui = Condominio::factory()->create();
    [$user] = condominoConPermessi($attuale, [Permission::EDIT_OWN_EVENTS->value]);
    $categoria = CategoriaEvento::create(['name' => 'Prova', 'description' => 'Prova']);
    $evento = Evento::create([
        'title'       => 'Originale',
        'start_time'  => now()->addDay(),
        'end_time'    => now()->addDay()->addHour(),
        'created_by'  => $user->id,
        'category_id' => $categoria->id,
        'visibility'  => VisibilityStatus::HIDDEN->value,
        'is_approved' => false,
    ]);
    $evento->condomini()->attach($vecchio->id);

    $this->actingAs($user)
        ->put(route('user.eventi.update', $evento), datiEvento([$vecchio->id], $categoria->id, 'Titolo corretto'))
        ->assertSessionHasNoErrors();
    expect($evento->fresh()->title)->toBe('Titolo corretto')
        ->and(condominiDi($evento))->toBe([$vecchio->id]);

    $this->actingAs($user)
        ->put(route('user.eventi.update', $evento), datiEvento([$altrui->id], $categoria->id, 'Spostato'))
        ->assertSessionHasErrors('condomini_ids.0');
    $this->actingAs($user)
        ->put(route('user.eventi.update', $evento), datiEvento([$vecchio->id, $altrui->id], $categoria->id, 'Allargato'))
        ->assertSessionHasErrors('condomini_ids.1');

    expect($evento->fresh()->title)->toBe('Titolo corretto')
        ->and(condominiDi($evento))->toBe([$vecchio->id]);
});

// --- l'oracolo: chi non può modificare non scopre dove sta il record ------------------------

test('chi non può modificare riceve 403 senza errori, qualunque palazzo indichi', function () {
    $a = Condominio::factory()->create();
    $b = Condominio::factory()->create();
    $c = Condominio::factory()->create();
    [$autore] = condominoConPermessi($a, [Permission::EDIT_OWN_COMUNICAZIONI->value, Permission::EDIT_OWN_EVENTS->value]);
    [$estraneo] = condominoConPermessi($b, [Permission::EDIT_OWN_COMUNICAZIONI->value, Permission::EDIT_OWN_EVENTS->value]);
    $categoria = CategoriaEvento::create(['name' => 'Prova', 'description' => 'Prova']);

    $comunicazione = Comunicazione::factory()->create(['created_by' => $autore->id, 'subject' => 'Originale']);
    $comunicazione->condomini()->attach($a->id);
    $evento = Evento::create([
        'title'       => 'Originale',
        'start_time'  => now()->addDay(),
        'end_time'    => now()->addDay()->addHour(),
        'created_by'  => $autore->id,
        'category_id' => $categoria->id,
        'visibility'  => VisibilityStatus::HIDDEN->value,
        'is_approved' => false,
    ]);
    $evento->condomini()->attach($a->id);
    $segnalazione = \App\Models\Segnalazione::factory()->create(['condominio_id' => $a->id, 'created_by' => $autore->id, 'subject' => 'Originale']);

    foreach ([[$a->id], [$c->id], [$a->id, $c->id]] as $ids) {
        $this->actingAs($estraneo)
            ->put(route('user.comunicazioni.update', $comunicazione), datiComunicazione($ids, 'Tentativo'))
            ->assertForbidden()->assertSessionHasNoErrors();
        $this->actingAs($estraneo)
            ->put(route('user.eventi.update', $evento), datiEvento($ids, $categoria->id, 'Tentativo'))
            ->assertForbidden()->assertSessionHasNoErrors();
    }
    foreach ([$a->id, $c->id] as $id) {
        $this->actingAs($estraneo)->put(route('user.segnalazioni.update', $segnalazione), [
            'subject' => 'Tentativo', 'description' => 'Prova', 'priority' => 'media',
            'stato' => 'aperta', 'is_private' => false, 'condominio_id' => $id,
        ])->assertForbidden()->assertSessionHasNoErrors();
    }

    expect($comunicazione->fresh()->subject)->toBe('Originale')
        ->and($evento->fresh()->title)->toBe('Originale')
        ->and($segnalazione->fresh()->subject)->toBe('Originale');

    // Il caso buono accanto: l'autore salva, così il 403 qui sopra non passa per la ragione sbagliata.
    $this->actingAs($autore)
        ->put(route('user.comunicazioni.update', $comunicazione), datiComunicazione([$a->id], 'Corretta'))
        ->assertSessionHasNoErrors();
    expect($comunicazione->fresh()->subject)->toBe('Corretta');
});
