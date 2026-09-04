<?php

use App\Models\Gestionale\DelegaF24;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/GeneraDelegheF24Test.php';

/**
 * Il codice tributo non si inventa (Coda 119, regola del 03/09/2026).
 *
 * ⚠️ **`TipoRitenuta::codiceTributo()` rifiuta un `null`**: l'enum, per costruzione, non
 * accetta «non so». È `GeneraDelegheF24Action::naturaPercipiente()` a **fabbricare** un
 * valore per superarlo — se la natura manca nello snapshot e in anagrafica, ripiega su
 * `PERSONA_FISICA_IRPEF` e stampa **1019 anche su una società**, che vuole 1020. Il denaro
 * arriva all'Erario sotto un codice che non è il suo, e non c'è nessun avviso da nessuna
 * parte.
 *
 * Il design lo aveva già previsto e datato (§2.4 M2 e §7 punto 7): *«v1.10: warning
 * bloccante con override. v1.11: blocco duro»*. Siamo nella 1.11.
 */
test('senza la natura del percipiente la delega non si fa, e si dice per chi', function () {
    $ctx = ctxF24();
    $ctx[3]->update(['natura_percipiente' => null, 'codice_tributo' => null]);
    $ctx[3]->refresh();

    pagaConRitenuta($ctx, '2026-03-10', 1000);

    // ⚠️ Prima del 03/09/2026 questa chiamata **riusciva**, e produceva una riga con
    // codice tributo 1019 — inventato. Adesso si ferma.
    expect(fn () => azioneDeleghe()->esegui($ctx[0], $ctx[1]->id))
        ->toThrow(DomainException::class, 'manca la natura del percipiente');

    // ⚠️ E non lascia niente a metà: la transazione non scrive nessuna bozza parziale.
    expect(DelegaF24::where('condominio_id', $ctx[0]->id)->count())->toBe(0);
});

test('il messaggio nomina i fornitori, non dice genericamente di completare l\'anagrafica', function () {
    $ctx = ctxF24();
    $ctx[3]->update(['natura_percipiente' => null, 'codice_tributo' => null, 'ragione_sociale' => 'Impresa Senza Natura Srl']);
    $ctx[3]->refresh();

    pagaConRitenuta($ctx, '2026-03-10', 1000);

    $messaggio = '';
    try {
        azioneDeleghe()->esegui($ctx[0], $ctx[1]->id);
    } catch (DomainException $e) {
        $messaggio = $e->getMessage();
    }

    // Chi legge deve sapere QUALE anagrafica aprire: cercarli a mano fra tutti i
    // fornitori pagati nel periodo è il motivo per cui un avviso generico non basta.
    expect($messaggio)->toContain('Impresa Senza Natura Srl');
});

test('con la natura classificata la delega si fa, e il codice tributo e quello giusto', function () {
    // ⚠️ Il controesempio, e serve: una guardia che blocca sempre non e una guardia.
    // IRES → 1020, che e proprio il codice che il ripiego silenzioso sbagliava.
    $ctx = ctxF24();
    $ctx[3]->update(['natura_percipiente' => 'soggetto_ires', 'codice_tributo' => null]);
    $ctx[3]->refresh();

    pagaConRitenuta($ctx, '2026-03-10', 1000);
    azioneDeleghe()->esegui($ctx[0], $ctx[1]->id);

    $riga = DelegaF24::where('condominio_id', $ctx[0]->id)->first()?->righe()->first();

    expect($riga)->not->toBeNull()
        ->and($riga->codice_tributo)->toBe('1020');
});

// ════════════════════════════════════════════════════════════════════════════
// Il blocco deve essere VIVIBILE: si dice prima, non dopo
// ════════════════════════════════════════════════════════════════════════════

test('la pagina F24 elenca i fornitori da classificare prima che si prema il pulsante', function () {
    // ⚠️ Un blocco che si scopre solo sbattendoci contro è un blocco che si subisce.
    // L'elenco deve arrivare quando si apre la pagina, con l'id per costruire il
    // collegamento all'anagrafica: «completa l'anagrafica» senza dire quale costringe a
    // cercare fra tutti i fornitori pagati nel periodo.
    $ctx = ctxF24();
    $ctx[3]->update(['natura_percipiente' => null, 'codice_tributo' => null, 'ragione_sociale' => 'Impresa Da Classificare Srl']);
    $ctx[3]->refresh();

    pagaConRitenuta($ctx, '2026-03-10', 1000);

    $utente = App\Models\User::factory()->create();
    $permesso = Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $utente->assignRole($ruolo);

    $this->actingAs($utente)
        ->get(route('admin.gestionale.f24.index', $ctx[0]->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('fornitori_da_classificare', 1)
            ->where('fornitori_da_classificare.0.ragione_sociale', 'Impresa Da Classificare Srl')
            ->where('fornitori_da_classificare.0.id', $ctx[3]->id)
        );
});

test('con tutto classificato la pagina non elenca nessuno', function () {
    // ⚠️ Il controesempio: un pannello che c'è sempre smette di essere letto.
    $ctx = ctxF24();   // ctxF24 dichiara persona_fisica_irpef
    pagaConRitenuta($ctx, '2026-03-10', 1000);

    $utente = App\Models\User::factory()->create();
    $permesso = Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $utente->assignRole($ruolo);

    $this->actingAs($utente)
        ->get(route('admin.gestionale.f24.index', $ctx[0]->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('fornitori_da_classificare', 0));
});

// ════════════════════════════════════════════════════════════════════════════
// Il blocco non deve chiedere un dato che non decide niente
// ════════════════════════════════════════════════════════════════════════════

/**
 * ⚠️ **Difetto della stessa giornata, trovato riguardando il proprio lavoro.**
 *
 * La prima stesura del blocco non guardava il regime: si fermava ogni volta che la natura
 * mancava. Ma `TipoRitenuta::codiceTributo()` fa dipendere il codice dalla natura per un
 * regime solo — l'appalto, dove IRPEF dà 1019 e IRES 1020. Per il lavoro autonomo, le
 * provvigioni e i non residenti il codice è **sempre** 1040, e per il lavoro dipendente
 * sempre 1001.
 *
 * Un condominio che paga soltanto un professionista al 20% si vedeva quindi rifiutare la
 * delega per un campo che sul suo modello avrebbe scritto 1040 comunque — e il messaggio
 * gli diceva pure una cosa falsa, cioè che «il codice sarebbe 1019 o 1020 a caso».
 */
test('al 20% la delega si fa anche senza natura, perche li il codice e 1040 comunque', function () {
    $ctx = ctxF24(20);
    $ctx[3]->update(['natura_percipiente' => null, 'codice_tributo' => null]);
    $ctx[3]->refresh();

    pagaConRitenuta($ctx, '2026-03-10', 1000);

    azioneDeleghe()->esegui($ctx[0], $ctx[1]->id);

    $riga = DelegaF24::where('condominio_id', $ctx[0]->id)->first()?->righe()->first();

    expect($riga)->not->toBeNull()
        ->and($riga->codice_tributo)->toBe('1040');
});

test('e la pagina non chiede di classificare chi non ha niente da classificare', function () {
    // ⚠️ Il pannello rosa e il blocco devono dire la stessa cosa: se la delega si fa,
    // l'elenco dei fornitori da classificare dev'essere vuoto. Due schermate che
    // rispondono diversamente alla stessa domanda sono peggio di una schermata sola.
    $ctx = ctxF24(20);
    $ctx[3]->update(['natura_percipiente' => null, 'codice_tributo' => null]);
    $ctx[3]->refresh();

    pagaConRitenuta($ctx, '2026-03-10', 1000);

    $utente = App\Models\User::factory()->create();
    $permesso = Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $utente->assignRole($ruolo);

    $this->actingAs($utente)
        ->get(route('admin.gestionale.f24.index', $ctx[0]->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('fornitori_da_classificare', 0));
});

test('l\'appalto invece la natura la pretende, ed e l\'unico', function () {
    // ⚠️ Il controesempio dell'esclusione: senza questo, «non blocca mai» passerebbe.
    foreach (App\Enums\Fiscale\TipoRitenuta::cases() as $tipo) {
        expect($tipo->dipendeDallaNatura())->toBe(
            $tipo === App\Enums\Fiscale\TipoRitenuta::APPALTO_4,
            "il regime {$tipo->value} classificato male"
        );
    }
});
