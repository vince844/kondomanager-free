<?php

/**
 * # Le anagrafiche del pannello chiedono i permessi delle anagrafiche (decisione 137)
 *
 * L'elenco, la scheda, il modulo di creazione e quello di modifica mostrano codici fiscali, telefoni e indirizzi
 * email; creare, modificare ed eliminare scrivono l'archivio delle persone. Entrare nel pannello non basta: ogni azione
 * chiede la sua abilità alla policy, con i quattro permessi delle anagrafiche: «Visualizza anagrafiche» per l'elenco e
 * la scheda, «Crea anagrafiche» per il modulo nuovo e il salvataggio, «Modifica anagrafiche» per il modulo di modifica
 * e il salvataggio, «Elimina anagrafiche» per l'eliminazione. Sono i permessi con cui il menù mostra la voce
 * «Anagrafiche» (`AppHeader.vue`) e che i ruoli di serie danno: l'amministratore li ha tutti; il collaboratore ha i
 * primi tre e non «Elimina anagrafiche», come non ha «Elimina condomini» né «Elimina utenti».
 *
 * Cosa NON copre: le altre pagine del pannello; il portale (`user.anagrafiche.*`); i pulsanti e i collegamenti delle
 * pagine (`resources/js/components/anagrafiche/*.test.ts`, `resources/js/pages/anagrafiche/*.test.ts`).
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
    $this->withoutVite();

    $this->aurora = Condominio::factory()->create(['nome' => 'Condominio Aurora']);
    $this->mario = Anagrafica::create([
        'nome' => 'Mario Rossi', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'RSSMRA80A01H501U',
        'telefono' => '0612345678', 'cellulare' => '3331234567', 'email' => 'mario.rossi@example.com',
    ]);
    $this->mario->condomini()->attach($this->aurora->id);

    RuoloSpatie::findOrCreate('Solo pannello', 'web')->syncPermissions([Permission::ACCESS_ADMIN_PANEL->value]);
});

function anagPannelloUtente(string $ruolo): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole($ruolo);

    return $user->fresh();
}

/** Un'anagrafica senza palazzi né passaggi: l'eliminazione non ha altri motivi per fermarsi. */
function anagPannelloEliminabile(): Anagrafica
{
    return Anagrafica::create(['nome' => 'Da eliminare', 'indirizzo' => 'Via Verdi 2']);
}

/** Il corpo del modulo di modifica come lo manda la pagina, dai dati che riceve, con le modifiche date. */
function anagPannelloModulo($test, User $chi, Anagrafica $anagrafica, array $modifiche = []): array
{
    $props = $test->actingAs($chi)->get(route('admin.anagrafiche.edit', $anagrafica))->assertOk()->viewData('page')['props'];
    $a = $props['anagrafica'];

    return array_merge([
        'nome' => $a['nome'], 'indirizzo' => $a['indirizzo'], 'codice_fiscale' => $a['codice_fiscale'],
        'email' => $a['email'], 'email_secondaria' => $a['email_secondaria'], 'pec' => $a['pec'],
        'telefono' => $a['telefono'], 'cellulare' => $a['cellulare'],
        'condomini' => collect($a['condomini'] ?? [])->pluck('id')->all(),
    ], $modifiche);
}

test('decisione 137 — chi ha il solo accesso al pannello non apre l\'elenco, la scheda né i moduli delle anagrafiche', function () {
    $solo = anagPannelloUtente('Solo pannello');

    foreach ([
        route('admin.anagrafiche.index'),
        route('admin.anagrafiche.show', $this->mario),
        route('admin.anagrafiche.create'),
        route('admin.anagrafiche.edit', $this->mario),
    ] as $url) {
        $risposta = $this->actingAs($solo)->get($url);
        $risposta->assertForbidden();
        expect($risposta->getContent())->not->toContain('RSSMRA80A01H501U')->not->toContain('3331234567');
    }
});

test('decisione 137 — chi ha il solo accesso al pannello non crea, non modifica e non elimina un\'anagrafica', function () {
    $solo = anagPannelloUtente('Solo pannello');
    $daEliminare = anagPannelloEliminabile();

    $this->actingAs($solo)->post(route('admin.anagrafiche.store'), [
        'nome' => 'Nuova persona', 'indirizzo' => 'Via Nuova 3', 'condomini' => [$this->aurora->id],
    ])->assertForbidden();
    $this->actingAs($solo)->put(route('admin.anagrafiche.update', $this->mario), [
        'nome' => 'Mario Cambiato', 'indirizzo' => 'Via Roma 1', 'condomini' => [$this->aurora->id],
    ])->assertForbidden();
    $this->actingAs($solo)->delete(route('admin.anagrafiche.destroy', $daEliminare))->assertForbidden();

    expect(Anagrafica::where('nome', 'Nuova persona')->exists())->toBeFalse()
        ->and($this->mario->fresh()->nome)->toBe('Mario Rossi')
        ->and(Anagrafica::whereKey($daEliminare->id)->exists())->toBeTrue();
});

test('decisione 137 — l\'amministratore apre, crea, modifica ed elimina le anagrafiche come prima', function () {
    $admin = anagPannelloUtente(Role::AMMINISTRATORE->value);
    $daEliminare = anagPannelloEliminabile();

    $elenco = $this->actingAs($admin)->get(route('admin.anagrafiche.index'))->assertOk()->viewData('page')['props']['anagrafiche'];
    expect(collect($elenco)->pluck('codice_fiscale')->all())->toContain('RSSMRA80A01H501U');
    $this->actingAs($admin)->get(route('admin.anagrafiche.show', $this->mario))->assertOk();
    $this->actingAs($admin)->get(route('admin.anagrafiche.create'))->assertOk();

    $this->actingAs($admin)->post(route('admin.anagrafiche.store'), [
        'nome' => 'Nuova persona', 'indirizzo' => 'Via Nuova 3', 'condomini' => [$this->aurora->id],
    ])->assertSessionHasNoErrors()->assertRedirect(route('admin.anagrafiche.index'));
    $this->actingAs($admin)->put(route('admin.anagrafiche.update', $this->mario), anagPannelloModulo($this, $admin, $this->mario, ['nome' => 'Mario Rossi Bianchi']))
        ->assertSessionHasNoErrors()->assertRedirect(route('admin.anagrafiche.index'));
    $this->actingAs($admin)->from(route('admin.anagrafiche.index'))->delete(route('admin.anagrafiche.destroy', $daEliminare))
        ->assertSessionHas('message', fn ($m) => $m['type'] === 'success');

    expect(Anagrafica::where('nome', 'Nuova persona')->exists())->toBeTrue()
        ->and($this->mario->fresh()->nome)->toBe('Mario Rossi Bianchi')
        ->and(Anagrafica::whereKey($daEliminare->id)->exists())->toBeFalse();
});

test('decisione 137 — il collaboratore apre, crea e modifica le anagrafiche come prima, e non le elimina', function () {
    $collaboratore = anagPannelloUtente(Role::COLLABORATORE->value);
    $daEliminare = anagPannelloEliminabile();

    $elenco = $this->actingAs($collaboratore)->get(route('admin.anagrafiche.index'))->assertOk()->viewData('page')['props']['anagrafiche'];
    expect(collect($elenco)->pluck('codice_fiscale')->all())->toContain('RSSMRA80A01H501U');
    $this->actingAs($collaboratore)->get(route('admin.anagrafiche.show', $this->mario))->assertOk();
    $this->actingAs($collaboratore)->get(route('admin.anagrafiche.create'))->assertOk();

    $this->actingAs($collaboratore)->post(route('admin.anagrafiche.store'), [
        'nome' => 'Nuova persona', 'indirizzo' => 'Via Nuova 3', 'condomini' => [$this->aurora->id],
    ])->assertSessionHasNoErrors()->assertRedirect(route('admin.anagrafiche.index'));
    $this->actingAs($collaboratore)->put(route('admin.anagrafiche.update', $this->mario), anagPannelloModulo($this, $collaboratore, $this->mario, ['nome' => 'Mario Rossi Bianchi']))
        ->assertSessionHasNoErrors()->assertRedirect(route('admin.anagrafiche.index'));
    // «Elimina anagrafiche» non è fra i permessi del collaboratore di serie.
    $this->actingAs($collaboratore)->delete(route('admin.anagrafiche.destroy', $daEliminare))->assertForbidden();

    expect(Anagrafica::where('nome', 'Nuova persona')->exists())->toBeTrue()
        ->and($this->mario->fresh()->nome)->toBe('Mario Rossi Bianchi')
        ->and(Anagrafica::whereKey($daEliminare->id)->exists())->toBeTrue();
});

test('decisione 137 — ogni azione chiede il suo permesso, e solo quello', function (array $permessi, array $atteso) {
    RuoloSpatie::findOrCreate('Su misura', 'web')->syncPermissions(array_merge([Permission::ACCESS_ADMIN_PANEL->value], $permessi));
    $chi = anagPannelloUtente('Su misura');
    $daEliminare = anagPannelloEliminabile();

    $esito = fn ($r) => $r->status() === 403 ? 'no' : 'sì';
    $ottenuto = [
        'elenco' => $esito($this->actingAs($chi)->get(route('admin.anagrafiche.index'))),
        'scheda' => $esito($this->actingAs($chi)->get(route('admin.anagrafiche.show', $this->mario))),
        'modulo nuovo' => $esito($this->actingAs($chi)->get(route('admin.anagrafiche.create'))),
        'salva nuova' => $esito($this->actingAs($chi)->post(route('admin.anagrafiche.store'), [
            'nome' => 'Nuova persona', 'indirizzo' => 'Via Nuova 3', 'condomini' => [$this->aurora->id],
        ])),
        'modulo di modifica' => $esito($this->actingAs($chi)->get(route('admin.anagrafiche.edit', $this->mario))),
        'salva modifica' => $esito($this->actingAs($chi)->put(route('admin.anagrafiche.update', $this->mario), [
            'nome' => 'Mario Cambiato', 'indirizzo' => 'Via Roma 1', 'condomini' => [$this->aurora->id],
        ])),
        'elimina' => $esito($this->actingAs($chi)->from(route('admin.anagrafiche.index'))->delete(route('admin.anagrafiche.destroy', $daEliminare))),
    ];

    expect($ottenuto)->toBe($atteso)
        ->and(Anagrafica::where('nome', 'Nuova persona')->exists())->toBe($atteso['salva nuova'] === 'sì')
        ->and($this->mario->fresh()->nome === 'Mario Cambiato')->toBe($atteso['salva modifica'] === 'sì')
        ->and(Anagrafica::whereKey($daEliminare->id)->exists())->toBe($atteso['elimina'] === 'no');
})->with([
    'solo «Visualizza anagrafiche»' => [[Permission::VIEW_ANAGRAFICHE->value],
        ['elenco' => 'sì', 'scheda' => 'sì', 'modulo nuovo' => 'no', 'salva nuova' => 'no', 'modulo di modifica' => 'no', 'salva modifica' => 'no', 'elimina' => 'no']],
    'solo «Crea anagrafiche»' => [[Permission::CREATE_ANAGRAFICHE->value],
        ['elenco' => 'no', 'scheda' => 'no', 'modulo nuovo' => 'sì', 'salva nuova' => 'sì', 'modulo di modifica' => 'no', 'salva modifica' => 'no', 'elimina' => 'no']],
    'solo «Modifica anagrafiche»' => [[Permission::EDIT_ANAGRAFICHE->value],
        ['elenco' => 'no', 'scheda' => 'no', 'modulo nuovo' => 'no', 'salva nuova' => 'no', 'modulo di modifica' => 'sì', 'salva modifica' => 'sì', 'elimina' => 'no']],
    'solo «Elimina anagrafiche»' => [[Permission::DELETE_ANAGRAFICHE->value],
        ['elenco' => 'no', 'scheda' => 'no', 'modulo nuovo' => 'no', 'salva nuova' => 'no', 'modulo di modifica' => 'no', 'salva modifica' => 'no', 'elimina' => 'sì']],
]);

/*
|--------------------------------------------------------------------------
| Il permesso prima della validazione
|--------------------------------------------------------------------------
| Il salvataggio di un'anagrafica nuova e quello di una modifica chiedono il permesso nella richiesta
| (`CreateAnagraficaRequest::authorize()`, `UpdateAnagraficaRequest::authorize()`), prima delle regole: chi
| non può salvare riceve il rifiuto della policy, con la sua frase, e nessun esito delle regole, nemmeno di
| quelle di unicità. Chi può salvare riceve gli errori come prima.
*/

test('decisione 137 — chi non può creare un\'anagrafica riceve il rifiuto prima della validazione, anche con un\'email e un codice fiscale già usati', function (string $ruolo) {
    RuoloSpatie::findOrCreate('Consultazione', 'web')->syncPermissions([Permission::ACCESS_ADMIN_PANEL->value, Permission::VIEW_ANAGRAFICHE->value]);
    $chi = anagPannelloUtente($ruolo);

    foreach ([
        'già usati' => ['email' => 'mario.rossi@example.com', 'codice_fiscale' => 'RSSMRA80A01H501U'],
        'nuovi' => ['email' => 'nuova@example.com', 'codice_fiscale' => 'NNNNNN80A01H501U'],
    ] as $valori) {
        $risposta = $this->actingAs($chi)->post(route('admin.anagrafiche.store'), ['nome' => 'X', 'indirizzo' => 'Y'] + $valori);
        $risposta->assertForbidden();
        expect(session('errors'))->toBeNull();
    }
    expect(Anagrafica::where('nome', 'X')->exists())->toBeFalse();

    // Il rifiuto è quello della policy, con la sua frase.
    $this->actingAs($chi)->postJson(route('admin.anagrafiche.store'), ['nome' => 'X', 'indirizzo' => 'Y', 'email' => 'mario.rossi@example.com'])
        ->assertForbidden()->assertJson(['message' => __('anagrafiche.permessi.create')])->assertJsonMissingPath('errors');
})->with(['Solo pannello', 'Consultazione']);

test('decisione 137 — chi non può modificare un\'anagrafica riceve il rifiuto prima della validazione, anche con l\'email e il codice fiscale di un\'altra persona', function () {
    RuoloSpatie::findOrCreate('Consultazione', 'web')->syncPermissions([Permission::ACCESS_ADMIN_PANEL->value, Permission::VIEW_ANAGRAFICHE->value]);
    $chi = anagPannelloUtente('Consultazione');
    $luigi = Anagrafica::create(['nome' => 'Luigi Verdi', 'indirizzo' => 'Via Po 2', 'email' => 'luigi.verdi@example.com', 'codice_fiscale' => 'VRDLGU70A01H501Z']);

    $this->actingAs($chi)->put(route('admin.anagrafiche.update', $this->mario), [
        'nome' => 'Mario Rossi', 'indirizzo' => 'Via Roma 1', 'email' => 'luigi.verdi@example.com', 'codice_fiscale' => 'VRDLGU70A01H501Z',
        'condomini' => [$this->aurora->id],
    ])->assertForbidden();
    expect(session('errors'))->toBeNull()
        ->and($this->mario->fresh()->email)->toBe('mario.rossi@example.com');

    $this->actingAs($chi)->putJson(route('admin.anagrafiche.update', $this->mario), ['nome' => 'Mario Rossi', 'indirizzo' => 'Via Roma 1', 'email' => 'luigi.verdi@example.com'])
        ->assertForbidden()->assertJson(['message' => __('anagrafiche.permessi.update')])->assertJsonMissingPath('errors');
    expect($luigi->fresh()->email)->toBe('luigi.verdi@example.com');
});

test('decisione 137 — chi può salvare riceve gli errori di unicità come prima, nella creazione e nella modifica', function (string $ruolo) {
    $chi = anagPannelloUtente($ruolo);
    $luigi = Anagrafica::create(['nome' => 'Luigi Verdi', 'indirizzo' => 'Via Po 2', 'email' => 'luigi.verdi@example.com', 'codice_fiscale' => 'VRDLGU70A01H501Z']);

    $this->actingAs($chi)->from(route('admin.anagrafiche.create'))->post(route('admin.anagrafiche.store'), [
        'nome' => 'X', 'indirizzo' => 'Y', 'email' => 'mario.rossi@example.com', 'codice_fiscale' => 'RSSMRA80A01H501U',
    ])->assertRedirect(route('admin.anagrafiche.create'))->assertSessionHasErrors(['email', 'codice_fiscale']);

    $this->actingAs($chi)->from(route('admin.anagrafiche.edit', $this->mario))->put(route('admin.anagrafiche.update', $this->mario),
        anagPannelloModulo($this, $chi, $this->mario, ['email' => 'luigi.verdi@example.com', 'codice_fiscale' => 'VRDLGU70A01H501Z']))
        ->assertRedirect(route('admin.anagrafiche.edit', $this->mario))->assertSessionHasErrors(['email', 'codice_fiscale']);

    expect(Anagrafica::where('nome', 'X')->exists())->toBeFalse()
        ->and($this->mario->fresh()->email)->toBe('mario.rossi@example.com')
        ->and($luigi->fresh()->codice_fiscale)->toBe('VRDLGU70A01H501Z');
})->with([Role::AMMINISTRATORE->value, Role::COLLABORATORE->value]);

test('decisione 137 — con «Modifica anagrafiche» e senza «Crea anagrafiche» il salvataggio di una nuova è rifiutato prima della validazione, e quello di una modifica no', function () {
    RuoloSpatie::findOrCreate('Solo modifica', 'web')->syncPermissions([Permission::ACCESS_ADMIN_PANEL->value, Permission::EDIT_ANAGRAFICHE->value]);
    $chi = anagPannelloUtente('Solo modifica');

    $this->actingAs($chi)->post(route('admin.anagrafiche.store'), ['nome' => 'X', 'indirizzo' => 'Y', 'email' => 'mario.rossi@example.com'])
        ->assertForbidden();
    $this->actingAs($chi)->from(route('admin.anagrafiche.edit', $this->mario))->put(route('admin.anagrafiche.update', $this->mario), [
        'nome' => 'Mario Rossi', 'indirizzo' => 'Via Roma 1', 'email' => 'non-una-email', 'condomini' => [$this->aurora->id],
    ])->assertSessionHasErrors(['email']);
});

/*
|--------------------------------------------------------------------------
| Dopo «Salva», una pagina che si apre
|--------------------------------------------------------------------------
| Il salvataggio torna all'elenco solo a chi lo può consultare («Visualizza anagrafiche»). Chi crea o modifica
| senza consultare torna al modulo della persona salvata: quello di modifica, se la può modificare; se no, un
| modulo nuovo vuoto. Il messaggio di riuscita arriva con lui, e nessun salvataggio riuscito finisce in un rifiuto.
| Lo stesso vale per il messaggio d'errore, quando il salvataggio non riesce.
*/

/** La pagina dove porta la risposta, aperta da chi ha salvato: la rotta, lo stato e il messaggio che riceve. */
function anagPannelloDoveTorna($test, User $chi, $risposta): array
{
    $dove = $risposta->headers->get('Location');
    $pagina = $test->actingAs($chi)->get($dove);

    return [
        'rotta' => app('router')->getRoutes()->match(\Illuminate\Http\Request::create($dove))->getName(),
        'stato' => $pagina->status(),
        'messaggio' => $pagina->status() === 200 ? ($pagina->viewData('page')['props']['flash']['message'] ?? null) : null,
    ];
}

test('decisione 137 — dopo «Salva» di una nuova anagrafica si torna all\'elenco solo con «Visualizza anagrafiche»; senza, al modulo della persona salvata, con il messaggio di riuscita', function (array $permessi, string $rottaAttesa) {
    RuoloSpatie::findOrCreate('Su misura', 'web')->syncPermissions(array_merge([Permission::ACCESS_ADMIN_PANEL->value], $permessi));
    $chi = anagPannelloUtente('Su misura');

    $risposta = $this->actingAs($chi)->from(route('admin.anagrafiche.create'))->post(route('admin.anagrafiche.store'), [
        'nome' => 'Nuova persona', 'indirizzo' => 'Via Nuova 3', 'condomini' => [$this->aurora->id],
    ]);
    $risposta->assertSessionHasNoErrors();
    $nuova = Anagrafica::where('nome', 'Nuova persona')->sole();

    expect(anagPannelloDoveTorna($this, $chi, $risposta))->toBe([
        'rotta' => $rottaAttesa,
        'stato' => 200,
        'messaggio' => ['type' => 'success', 'message' => __('anagrafiche.success_create_anagrafica')],
    ]);
    if ($rottaAttesa === 'admin.anagrafiche.edit') {
        $risposta->assertRedirect(route('admin.anagrafiche.edit', $nuova));
    }
})->with([
    'con «Visualizza» e «Crea anagrafiche», l\'elenco' => [[Permission::VIEW_ANAGRAFICHE->value, Permission::CREATE_ANAGRAFICHE->value], 'admin.anagrafiche.index'],
    'con «Crea» e «Modifica» senza «Visualizza», il modulo di modifica della persona creata' => [[Permission::CREATE_ANAGRAFICHE->value, Permission::EDIT_ANAGRAFICHE->value], 'admin.anagrafiche.edit'],
    'con il solo «Crea anagrafiche», un modulo nuovo' => [[Permission::CREATE_ANAGRAFICHE->value], 'admin.anagrafiche.create'],
]);

test('decisione 137 — dopo «Salva» di una modifica si torna all\'elenco solo con «Visualizza anagrafiche»; senza, al modulo di modifica della stessa persona, con il messaggio di riuscita', function (array $permessi, string $rottaAttesa) {
    RuoloSpatie::findOrCreate('Su misura', 'web')->syncPermissions(array_merge([Permission::ACCESS_ADMIN_PANEL->value], $permessi));
    $chi = anagPannelloUtente('Su misura');

    $risposta = $this->actingAs($chi)->from(route('admin.anagrafiche.edit', $this->mario))
        ->put(route('admin.anagrafiche.update', $this->mario), anagPannelloModulo($this, $chi, $this->mario, ['nome' => 'Mario R.']));
    $risposta->assertSessionHasNoErrors();

    expect($this->mario->fresh()->nome)->toBe('Mario R.')
        ->and(anagPannelloDoveTorna($this, $chi, $risposta))->toBe([
            'rotta' => $rottaAttesa,
            'stato' => 200,
            'messaggio' => ['type' => 'success', 'message' => __('anagrafiche.success_update_anagrafica')],
        ]);
    if ($rottaAttesa === 'admin.anagrafiche.edit') {
        $risposta->assertRedirect(route('admin.anagrafiche.edit', $this->mario));
    }
})->with([
    'con «Visualizza» e «Modifica anagrafiche», l\'elenco' => [[Permission::VIEW_ANAGRAFICHE->value, Permission::EDIT_ANAGRAFICHE->value], 'admin.anagrafiche.index'],
    'con il solo «Modifica anagrafiche», il modulo di modifica' => [[Permission::EDIT_ANAGRAFICHE->value], 'admin.anagrafiche.edit'],
    'con «Modifica» e «Crea» senza «Visualizza», il modulo di modifica' => [[Permission::EDIT_ANAGRAFICHE->value, Permission::CREATE_ANAGRAFICHE->value], 'admin.anagrafiche.edit'],
]);

test('decisione 137 — l\'amministratore e il collaboratore di serie, dopo «Salva», tornano all\'elenco come prima', function (string $ruolo) {
    $chi = anagPannelloUtente($ruolo);

    $nuova = $this->actingAs($chi)->post(route('admin.anagrafiche.store'), ['nome' => 'Nuova persona', 'indirizzo' => 'Via Nuova 3', 'condomini' => [$this->aurora->id]]);
    $modifica = $this->actingAs($chi)->put(route('admin.anagrafiche.update', $this->mario), anagPannelloModulo($this, $chi, $this->mario, ['nome' => 'Mario R.']));

    expect(anagPannelloDoveTorna($this, $chi, $nuova)['rotta'])->toBe('admin.anagrafiche.index')
        ->and(anagPannelloDoveTorna($this, $chi, $modifica)['rotta'])->toBe('admin.anagrafiche.index');
})->with([Role::AMMINISTRATORE->value, Role::COLLABORATORE->value]);

test('decisione 137 — quando il salvataggio non riesce, chi non consulta l\'elenco riceve il messaggio d\'errore su un modulo che può aprire', function () {
    RuoloSpatie::findOrCreate('Su misura', 'web')->syncPermissions([
        Permission::ACCESS_ADMIN_PANEL->value, Permission::CREATE_ANAGRAFICHE->value, Permission::EDIT_ANAGRAFICHE->value,
    ]);
    $chi = anagPannelloUtente('Su misura');
    $modulo = anagPannelloModulo($this, $chi, $this->mario, ['nome' => 'Mario R.']);
    Anagrafica::creating(fn () => throw new \RuntimeException('scrittura non riuscita'));
    Anagrafica::updating(fn () => throw new \RuntimeException('scrittura non riuscita'));

    $nuova = $this->actingAs($chi)->post(route('admin.anagrafiche.store'), ['nome' => 'Nuova persona', 'indirizzo' => 'Via Nuova 3', 'condomini' => [$this->aurora->id]]);
    expect(anagPannelloDoveTorna($this, $chi, $nuova))->toBe([
        'rotta' => 'admin.anagrafiche.create',
        'stato' => 200,
        'messaggio' => ['type' => 'error', 'message' => __('anagrafiche.error_create_anagrafica')],
    ]);

    $modifica = $this->actingAs($chi)->put(route('admin.anagrafiche.update', $this->mario), $modulo);
    $modifica->assertRedirect(route('admin.anagrafiche.edit', $this->mario));
    expect(anagPannelloDoveTorna($this, $chi, $modifica))->toBe([
        'rotta' => 'admin.anagrafiche.edit',
        'stato' => 200,
        'messaggio' => ['type' => 'error', 'message' => __('anagrafiche.error_update_anagrafica')],
    ])->and(Anagrafica::where('nome', 'Nuova persona')->exists())->toBeFalse()
        ->and($this->mario->fresh()->nome)->toBe('Mario Rossi');
});

test('decisione 137 — la dashboard del pannello, dove portano «Indietro» e «Annulla» dei moduli a chi non consulta l\'elenco, si apre con il solo accesso al pannello', function () {
    $this->actingAs(anagPannelloUtente('Solo pannello'))->get(route('admin.dashboard'))->assertOk();
});
