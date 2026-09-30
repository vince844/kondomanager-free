<?php

/**
 * Giro di sicurezza della 1.11.0-beta.39 (PR #48 di kta1kri, revisione in
 * docs/sicurezza_pr48_kta1kri.md): due file, tre difetti, una sola causa.
 *
 * `Utenti/EventoController::index()` (l'agenda del condòmino) e
 * `PaymentReportingController` (la segnalazione di un pagamento) chiamavano entrambi
 * `Gate::authorize('view', $evento)`, e la correzione della PR ha stretto quella
 * ability perché richiedesse un condominio in comune. Il problema è che le due rotte
 * chiedono due cose diverse a quella stessa ability:
 *
 * - `index()` non ha un Evento vero — la rotta resource non ne inietta uno, e il
 *   container ne costruisce uno vuoto — quindi qualunque regola su `view()` che
 *   guardi il condominio nega **sempre**: l'agenda si romperebbe per ogni condòmino.
 * - `PaymentReportingController` invece un Evento vero ce l'ha, ma il condominio da
 *   solo non basta: le rate per condòmino sono agganciate sia alla persona sia al
 *   condominio (`EventiRataCondomino::crea()`), quindi un vicino dello stesso palazzo
 *   passerebbe comunque, e con lui i compiti nascosti dell'amministratore agganciati
 *   allo stesso condominio.
 *
 * Il difetto vero, nel codice fino alla 1.11.0-beta.38, era più largo di quello che la PR
 * descriveva: `view()` guardava il solo permesso, quindi chiunque avesse `VIEW_EVENTS` (`UTENTE`
 * e `FORNITORE` di default) segnalava il pagamento su **qualunque** evento, di qualunque palazzo,
 * compresi i compiti nascosti dell'amministratore. Bastava l'id.
 *
 * La correzione di questa beta separa le due domande: `index()` chiede solo il
 * permesso (`viewAny`), e la segnalazione del pagamento chiede una ability nuova,
 * `reportPayment`, che guarda la persona e il tipo dell'evento, non il condominio.
 * `view()` è rimasta com'era e oggi non ha chiamanti.
 *
 * **Cosa resta scoperto** (regola «ogni test dichiara cosa NON copre»): la modifica di un evento
 * da parte del condòmino (`user.eventi.update`) qui non è esercitata: la copre
 * `tests/Feature/Rules/CondominioDellUtenteRevisioneTest.php`. La creazione (`CREATE_EVENTS`, che
 * `UTENTE` non ha di default) è coperta dal test «dopo aver creato un evento…», con il permesso
 * concesso ad hoc; l'evento creato dal condòmino nasce nascosto e non approvato, quindi quel test
 * prova che l'agenda si apre, non che l'evento vi compaia. Restano scoperti anche il calcolo
 * dell'importo in banca quando si usa il credito (`intent_usa_credito`), già esistente e non
 * toccato da questa beta, e la ricorrenza degli eventi, estranea a questa ability.
 */

use App\Enums\EventoTipo;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\VisibilityStatus;
use App\Models\Anagrafica;
use App\Models\CategoriaEvento;
use App\Models\Condominio;
use App\Models\Evento;
use App\Models\User;
use App\Services\Gestionale\InboxService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    // Un utente qualunque per fare da "amministratore" (created_by) sui promemoria di rata: le
    // sue proprietà non contano per questi test, serve solo un id utente valido a foreign key.
    $this->staff = User::factory()->create(['email_verified_at' => now()]);
});

/**
 * Il promemoria di rata di `$anagrafica`, sul condominio `$condominio`: stessa forma esatta di
 * `EventiRataCondomino::crea()` (app/Services/Gestionale/EventiRataCondomino.php), così i test
 * esercitano il codice vero e non un'approssimazione che potrebbe divergere da esso.
 */
function creaRataCondomino(Anagrafica $anagrafica, Condominio $condominio, User $staff, int $importoRestante = 10000): Evento
{
    $evento = Evento::create([
        'title'       => 'Scadenza rata 1 - Piano di prova',
        'start_time'  => now()->addDays(10),
        'end_time'    => now()->addDays(10)->endOfDay(),
        'created_by'  => $staff->id,
        'description' => 'Promemoria di prova',
        'visibility'  => VisibilityStatus::PRIVATE->value,
        'is_approved' => true,
        'tipo'        => EventoTipo::SCADENZA_RATA_CONDOMINO,
        'meta'        => [
            'type'             => EventoTipo::SCADENZA_RATA_CONDOMINO->value,
            'status'           => 'pending',
            'importo_originale' => $importoRestante,
            'importo_pagato'   => 0,
            'importo_restante' => $importoRestante,
            'condominio_nome'  => $condominio->nome,
        ],
    ]);

    $evento->anagrafiche()->attach($anagrafica->id);
    $evento->condomini()->attach($condominio->id);

    return $evento;
}

function utenteCondomino(Condominio $condominio, bool $conVisualizzaEventi = true): array
{
    $user = User::factory()->create(['email_verified_at' => now()]);

    // `revokePermissionTo` toglierebbe solo un permesso diretto: VIEW_EVENTS arriva dal ruolo
    // UTENTE, quindi il modo per costruire un utente che non ce l'ha è non assegnargli il ruolo,
    // non provare a togliergliela dopo.
    if ($conVisualizzaEventi) {
        $user->assignRole(Role::UTENTE->value);
    }

    $anagrafica = Anagrafica::factory()->create(['user_id' => $user->id]);
    $anagrafica->condomini()->attach($condominio->id);

    return [$user, $anagrafica];
}

// --- E1: l'agenda del condòmino (index) --------------------------------------------------

test('il condòmino con VIEW_EVENTS apre la sua agenda', function () {
    $condominio = Condominio::factory()->create();
    [$user, $anagrafica] = utenteCondomino($condominio);
    creaRataCondomino($anagrafica, $condominio, $this->staff);

    $response = $this->actingAs($user)->get(route('user.eventi.index'));

    // Con la view() proposta dalla PR #48 (condominio in comune) questa rotta avrebbe dato 403 a
    // ogni condòmino, perché index() la chiamava su un Evento vuoto. index() ora chiede solo il
    // permesso (viewAny), che UTENTE ha di default.
    $response->assertOk();
});

test('il condòmino senza VIEW_EVENTS non apre l\'agenda', function () {
    $condominio = Condominio::factory()->create();
    [$user] = utenteCondomino($condominio, conVisualizzaEventi: false);

    $response = $this->actingAs($user)->get(route('user.eventi.index'));

    $response->assertForbidden();
});

// --- E2/E3: la segnalazione del pagamento (reportPayment) ---------------------------------

test('il titolare segnala la propria rata', function () {
    $condominio = Condominio::factory()->create();
    [$user, $anagrafica] = utenteCondomino($condominio);
    $rata = creaRataCondomino($anagrafica, $condominio, $this->staff);

    $response = $this->actingAs($user)->post(route('user.eventi.report_payment', $rata));

    $response->assertRedirect()->assertSessionHas('success');
    expect($rata->fresh()->meta['status'])->toBe('reported');
    expect(Evento::where('tipo', EventoTipo::VERIFICA_PAGAMENTO->value)
        ->whereJsonContains('meta->context->related_event_id', $rata->id)
        ->count())->toBe(1);
});

test('un vicino dello stesso stabile non può segnalare la rata di un altro', function () {
    $condominio = Condominio::factory()->create();
    [, $titolare] = utenteCondomino($condominio);
    [$vicino] = utenteCondomino($condominio);
    $rata = creaRataCondomino($titolare, $condominio, $this->staff);

    // Anche tentando di dichiarare un uso di credito, come nello scenario descritto nella
    // revisione: il controllo deve fermarsi prima di leggere quei valori.
    $response = $this->actingAs($vicino)->post(route('user.eventi.report_payment', $rata), [
        'intent_usa_credito' => true,
        'credito_richiesto'  => 50000,
    ]);

    $response->assertForbidden();
    $metaFresca = $rata->fresh()->meta;
    expect($metaFresca['status'])->toBe('pending');
    expect($metaFresca)->not->toHaveKey('reported_at');
    expect($metaFresca)->not->toHaveKey('intent_usa_credito');
    expect($metaFresca)->not->toHaveKey('credito_richiesto');
    expect(Evento::where('tipo', EventoTipo::VERIFICA_PAGAMENTO->value)->count())->toBe(0);
});

test('un condòmino di un altro stabile non può segnalare', function () {
    $condominioA = Condominio::factory()->create();
    $condominioB = Condominio::factory()->create();
    [, $titolare] = utenteCondomino($condominioA);
    [$estraneo] = utenteCondomino($condominioB);
    $rata = creaRataCondomino($titolare, $condominioA, $this->staff);

    $response = $this->actingAs($estraneo)->post(route('user.eventi.report_payment', $rata));

    $response->assertForbidden();
    expect($rata->fresh()->meta['status'])->toBe('pending');
});

test('un condòmino non può segnalare un compito amministrativo del suo stabile', function () {
    $condominio = Condominio::factory()->create();
    [$user] = utenteCondomino($condominio);

    // Un compito nascosto, agganciato allo stesso condominio dell'utente — esattamente come fa
    // InboxService::createTask() per EMISSIONE_RATA e CONTROLLO_INCASSI.
    $compito = InboxService::createTask(
        tipo: EventoTipo::CONTROLLO_INCASSI,
        title: 'Verifica incassi - Rata 1',
        description: 'Compito amministrativo di prova',
        scadenza: now(),
        createdByUserId: $this->staff->id,
        condominioId: $condominio->id,
    );

    $response = $this->actingAs($user)->post(route('user.eventi.report_payment', $compito));

    $response->assertForbidden();
});

test('un condòmino non può segnalare un evento pubblico del suo condominio', function () {
    $condominio = Condominio::factory()->create();
    [$user] = utenteCondomino($condominio);

    $evento = Evento::create([
        'title'       => 'Assemblea condominiale',
        'start_time'  => now()->addDays(5),
        'end_time'    => now()->addDays(5)->addHours(2),
        'created_by'  => $this->staff->id,
        'visibility'  => VisibilityStatus::PUBLIC->value,
        'is_approved' => true,
        'meta'        => ['status' => 'pending'],
    ]);
    $evento->condomini()->attach($condominio->id);

    $response = $this->actingAs($user)->post(route('user.eventi.report_payment', $evento));

    $response->assertForbidden();
});

test('un condòmino non può segnalare su un evento che ha creato lui stesso', function () {
    $condominio = Condominio::factory()->create();
    [$user, $anagrafica] = utenteCondomino($condominio);

    // Un evento creato dal condòmino stesso (created_by = lui): la scorciatoia «sei il creatore»
    // che la PR #48 proponeva per view() non deve valere per reportPayment.
    $evento = Evento::create([
        'title'       => 'Evento creato dal condòmino',
        'start_time'  => now()->addDays(3),
        'end_time'    => now()->addDays(3)->addHour(),
        'created_by'  => $user->id,
        'visibility'  => VisibilityStatus::HIDDEN->value,
        'is_approved' => true,
        'tipo'        => EventoTipo::SCADENZA_RATA_CONDOMINO,
        'meta'        => ['status' => 'pending', 'importo_restante' => 10000],
    ]);
    $evento->condomini()->attach($condominio->id);

    $response = $this->actingAs($user)->post(route('user.eventi.report_payment', $evento));

    $response->assertForbidden();
});

test('il condòmino non può segnalare il compito VERIFICA_PAGAMENTO che la sua segnalazione ha creato', function () {
    $condominio = Condominio::factory()->create();
    [$user, $anagrafica] = utenteCondomino($condominio);
    $rata = creaRataCondomino($anagrafica, $condominio, $this->staff);
    $this->actingAs($user)->post(route('user.eventi.report_payment', $rata))->assertSessionHas('success');

    $compito = Evento::where('tipo', EventoTipo::VERIFICA_PAGAMENTO->value)->sole();
    // PaymentReportingController aggancia il compito alla sua stessa anagrafica: il controllo sulla
    // persona passa, lo ferma solo il controllo sul tipo. È l'unico test del file che mette alla
    // prova il tipo: gli altri casi negati (compito CONTROLLO_INCASSI, evento pubblico) non hanno
    // la sua anagrafica e si fermano già sulla persona.
    expect($compito->anagrafiche()->whereKey($anagrafica->id)->exists())->toBeTrue();
    $metaPrima = $compito->meta;

    $this->actingAs($user)->post(route('user.eventi.report_payment', $compito))->assertForbidden();

    expect(Evento::where('tipo', EventoTipo::VERIFICA_PAGAMENTO->value)->count())->toBe(1)
        ->and($compito->fresh()->meta)->toBe($metaPrima);
});

test('un utente senza VIEW_EVENTS non può segnalare, anche se è il titolare', function () {
    $condominio = Condominio::factory()->create();
    [$user, $anagrafica] = utenteCondomino($condominio, conVisualizzaEventi: false);
    $rata = creaRataCondomino($anagrafica, $condominio, $this->staff);

    $response = $this->actingAs($user)->post(route('user.eventi.report_payment', $rata));

    $response->assertForbidden();
});

test('un utente senza anagrafica riceve un rifiuto, non un errore del server', function () {
    $condominio = Condominio::factory()->create();
    $altro = Anagrafica::factory()->create();
    $rata = creaRataCondomino($altro, $condominio, $this->staff);

    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole(Role::UTENTE->value);

    $response = $this->actingAs($user)->post(route('user.eventi.report_payment', $rata));

    $response->assertForbidden();
});

test('il titolare scollegato dal condominio segnala ancora la sua rata', function () {
    $condominio = Condominio::factory()->create();
    [$user, $anagrafica] = utenteCondomino($condominio);
    $rata = creaRataCondomino($anagrafica, $condominio, $this->staff);

    // L'amministratore lo rimuove dal condominio (per esempio un aggiustamento manuale
    // dell'anagrafica): la riga anagrafica_condominio sparisce, ma la rata resta agganciata alla
    // persona tramite anagrafica_evento. reportPayment guarda quest'ultima, non la prima.
    $anagrafica->condomini()->detach($condominio->id);

    $response = $this->actingAs($user)->post(route('user.eventi.report_payment', $rata));

    $response->assertRedirect()->assertSessionHas('success');
    expect($rata->fresh()->meta['status'])->toBe('reported');
});

test('dopo aver creato un evento il condòmino torna alla sua agenda, e non può segnalarlo', function () {
    $condominio = Condominio::factory()->create();
    [$user, $anagrafica] = utenteCondomino($condominio);
    $user->givePermissionTo(Permission::CREATE_EVENTS->value);
    $categoria = CategoriaEvento::create(['name' => 'Prova', 'description' => 'Prova']);

    // tipo, anagrafiche e meta non sono fra le regole di CreateEventoRequest: validated() li scarta
    // e store() costruisce l'Evento da un elenco esplicito di campi. Qui si prova che restano fuori.
    $this->actingAs($user)->post(route('user.eventi.store'), [
        'title'         => 'Evento del condòmino',
        'start_time'    => now()->addDay()->toDateTimeString(),
        'end_time'      => now()->addDay()->addHour()->toDateTimeString(),
        'category_id'   => $categoria->id,
        'condomini_ids' => [$condominio->id],
        'tipo'          => EventoTipo::SCADENZA_RATA_CONDOMINO->value,
        'anagrafiche'   => [$anagrafica->id],
        'meta'          => ['status' => 'pending'],
    ])->assertSessionHasNoErrors()->assertRedirect(route('user.eventi.index'));

    $this->actingAs($user)->get(route('user.eventi.index'))->assertOk();

    // Anche il ramo d'errore di store() rimanda all'agenda: la prova che la creazione è riuscita è
    // la riga, non il redirect.
    $creato = Evento::where('title', 'Evento del condòmino')->sole();
    expect($creato->tipo)->toBeNull()
        ->and($creato->meta)->toBeNull()
        ->and($creato->anagrafiche()->count())->toBe(0)
        ->and($creato->condomini()->pluck('condomini.id')->map(fn ($id) => (int) $id)->all())->toBe([$condominio->id]);

    $this->actingAs($user)->post(route('user.eventi.report_payment', $creato))->assertForbidden();
});
