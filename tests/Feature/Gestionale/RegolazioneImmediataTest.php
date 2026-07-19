<?php

use App\Actions\Gestionale\Movimenti\RegistraRegolazioneImmediataAction;
use App\Exceptions\Gestionale\RegolazioneImmediataNonAmmessaException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $permessoAdmin = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruoloAdmin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruoloAdmin->givePermissionTo($permessoAdmin);

    $this->user = User::factory()->create();
    $this->user->assignRole($ruoloAdmin);
});

/**
 * setupPagamentiService() estende setupContabile() con conto bancario + cassa,
 * che è esattamente ciò che serve alla regolazione immediata (AVERE banca).
 * Ritorna [$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo].
 */
function datiRegolazioneImmediata(array $ctx, array $extra = []): array
{
    [$condominio, $esercizio, $gestione, , , $capitolo] = $ctx;

    $cassaId = DB::table('casse')->where('condominio_id', $condominio->id)->value('id');

    return array_merge([
        'gestione_id' => $gestione->id,
        'esercizio_id' => $esercizio->id,
        'conto_id' => $capitolo->id,
        'cassa_id' => $cassaId,
        'fornitore_id' => null,
        'data_operazione' => now()->toDateString(),
        'causale' => 'Imposta di bollo su estratto conto',
        'importo' => 16.68,
    ], $extra);
}

// ─── §9.1 — una sola scrittura quadrata ──────────────────────────────────────

test('la regolazione immediata genera una sola scrittura quadrata (DARE costo / AVERE banca)', function () {
    $ctx = setupPagamentiService();
    [$condominio, $esercizio, , , , $capitolo] = $ctx;

    $scrittura = (new RegistraRegolazioneImmediataAction())
        ->execute(datiRegolazioneImmediata($ctx), $condominio, $esercizio);

    expect($scrittura->righe()->count())->toBe(2);
    assertQuadraturaPerfetta($scrittura->id);

    $dare = $scrittura->righe()->where('tipo_riga', 'dare')->first();
    $avere = $scrittura->righe()->where('tipo_riga', 'avere')->first();

    expect($dare->importo)->toBe(1668)
        ->and($avere->importo)->toBe(1668)
        // Il capitolo di spesa resta agganciato: budget e riparto continuano a funzionare.
        ->and($dare->voce_spesa_id)->toBe($capitolo->id)
        ->and($dare->conto_contabile_id)->toBe($capitolo->conto_contabile_id)
        // La riga di cassa porta cassa_id: indispensabile per la riconciliazione.
        ->and($avere->cassa_id)->not->toBeNull();
});

// ─── §9.2 — nessun pivot fattura_scrittura, nessuno stato_pagamento ──────────

test('la regolazione immediata non crea alcuna fattura né riga nel pivot fattura_scrittura', function () {
    $ctx = setupPagamentiService();
    [$condominio, $esercizio] = $ctx;

    $fattureIniziali = DB::table('fatture_passive')->count();

    $scrittura = (new RegistraRegolazioneImmediataAction())
        ->execute(datiRegolazioneImmediata($ctx), $condominio, $esercizio);

    expect(DB::table('fattura_scrittura')->where('scrittura_contabile_id', $scrittura->id)->count())->toBe(0)
        ->and(DB::table('fatture_passive')->count())->toBe($fattureIniziali);
});

// ─── §9.3 + §9.6 — tipo_movimento e protocollo RIM ──────────────────────────

test('la scrittura porta tipo_movimento regolazione_immediata e protocollo RIM', function () {
    $ctx = setupPagamentiService();
    [$condominio, $esercizio] = $ctx;

    $scrittura = (new RegistraRegolazioneImmediataAction())
        ->execute(datiRegolazioneImmediata($ctx), $condominio, $esercizio);

    expect($scrittura->tipo_movimento->value)->toBe('regolazione_immediata')
        ->and($scrittura->numero_protocollo)->toStartWith('RIM-'.now()->format('Y'))
        ->and($scrittura->stato)->toBe('registrata');
});

// ─── §9.4 — fornitore come tag analitico, mai sul mastro Debiti ─────────────

test('il fornitore indicato resta un tag analitico e non movimenta i debiti verso fornitori', function () {
    $ctx = setupPagamentiService();
    [$condominio, $esercizio, , $fornitore] = $ctx;

    $scrittura = (new RegistraRegolazioneImmediataAction())
        ->execute(datiRegolazioneImmediata($ctx, ['fornitore_id' => $fornitore->id]), $condominio, $esercizio);

    $contoDebitiId = DB::table('conti_contabili')
        ->where('condominio_id', $condominio->id)
        ->where('ruolo', 'debiti_fornitori')
        ->value('id');

    expect($scrittura->righe()->where('conto_contabile_id', $contoDebitiId)->count())->toBe(0);
    assertQuadraturaPerfetta($scrittura->id);
});

// ─── §9.5 — guard rail: ritenuta d'acconto ──────────────────────────────────

test('un fornitore soggetto a ritenuta è vietato: serve la partita per separare netto ed Erario', function () {
    $ctx = setupPagamentiService();
    [$condominio, $esercizio, , $fornitore] = $ctx;

    DB::table('fornitori')->where('id', $fornitore->id)->update(['soggetto_ritenuta' => true]);

    expect(fn () => (new RegistraRegolazioneImmediataAction())
        ->execute(datiRegolazioneImmediata($ctx, ['fornitore_id' => $fornitore->id]), $condominio, $esercizio)
    )->toThrow(RegolazioneImmediataNonAmmessaException::class);

    // Fail-fast: nessuna scrittura orfana lasciata a metà.
    expect(DB::table('scritture_contabili')->where('tipo_movimento', 'regolazione_immediata')->count())->toBe(0);
});

test('la richiesta di alimentare lo scadenziario è vietata: quel debito va tracciato con una fattura', function () {
    $ctx = setupPagamentiService();
    [$condominio, $esercizio] = $ctx;

    expect(fn () => (new RegistraRegolazioneImmediataAction())
        ->execute(datiRegolazioneImmediata($ctx, ['genera_scadenza' => true]), $condominio, $esercizio)
    )->toThrow(RegolazioneImmediataNonAmmessaException::class);
});

test('un capitolo senza ancoraggio in partita doppia viene rifiutato invece di produrre una scrittura monca', function () {
    $ctx = setupPagamentiService();
    [$condominio, $esercizio, , , , $capitolo] = $ctx;

    DB::table('conti')->where('id', $capitolo->id)->update(['conto_contabile_id' => null]);

    expect(fn () => (new RegistraRegolazioneImmediataAction())
        ->execute(datiRegolazioneImmediata($ctx), $condominio, $esercizio)
    )->toThrow(RegolazioneImmediataNonAmmessaException::class);
});

// ─── Tenancy: il varco che il resto del progetto lascia aperto ───────────────

test('la cassa di un altro condominio viene rifiutata dalla validazione', function () {
    $ctx = setupPagamentiService();
    [$condominio, $esercizio] = $ctx;

    // Secondo condominio costruito a mano: setupPagamentiService() non è
    // richiamabile due volte (immobili.codice_immobile ha un unique globale).
    $altroCondominio = \App\Models\Condominio::factory()->create();
    $contoAltrui = DB::table('conti_contabili')->insertGetId([
        'condominio_id' => $altroCondominio->id,
        'ruolo' => 'conto_bancario',
        'codice' => 'BANCA-ALTRUI',
        'nome' => 'Conto Corrente Altrui',
        'tipo' => 'attivo',
        'categoria' => 'crediti',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $cassaAltrui = DB::table('casse')->insertGetId([
        'condominio_id' => $altroCondominio->id,
        'conto_contabile_id' => $contoAltrui,
        'nome' => 'Cassa Altrui',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($this->user)->post(
        route('admin.gestionale.regolazioni-immediate.store', $condominio),
        datiRegolazioneImmediata($ctx, ['cassa_id' => $cassaAltrui])
    );

    $response->assertSessionHasErrors('cassa_id');
    expect(DB::table('scritture_contabili')->where('tipo_movimento', 'regolazione_immediata')->count())->toBe(0);
});

// ─── HTTP: happy path e caso reale del bollo ────────────────────────────────

test('via HTTP la registrazione del bollo produce una scrittura sola, senza fornitore fittizio', function () {
    $ctx = setupPagamentiService();
    [$condominio] = $ctx;

    $response = $this->actingAs($this->user)->post(
        route('admin.gestionale.regolazioni-immediate.store', $condominio),
        datiRegolazioneImmediata($ctx)
    );

    $response->assertSessionHasNoErrors();

    $scritture = DB::table('scritture_contabili')
        ->where('condominio_id', $condominio->id)
        ->where('tipo_movimento', 'regolazione_immediata')
        ->get();

    expect($scritture)->toHaveCount(1)
        ->and($scritture->first()->causale)->toBe('Imposta di bollo su estratto conto');

    assertQuadraturaPerfetta($scritture->first()->id);

    // L'utente atterra sul dettaglio della scrittura appena creata: `movimenti.index`
    // rimbalzerebbe su Incassi rate, dove il movimento non compare.
    $response->assertRedirect(route('admin.gestionale.scritture.show', [
        'condominio' => $condominio->id,
        'scrittura' => $scritture->first()->id,
    ]));
});

// ─── Form: espone solo capitoli realmente utilizzabili ──────────────────────

test('il form espone i capitoli agganciati alla partita doppia e nasconde quelli senza ancoraggio', function () {
    $ctx = setupPagamentiService();
    [$condominio, , , , , $capitolo] = $ctx;

    $response = $this->actingAs($this->user)
        ->get(route('admin.gestionale.regolazioni-immediate.create', $condominio));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('gestionale/movimenti/regolazioni/RegolazioneImmediataNew')
        ->has('capitoli', 1)
        ->where('capitoli.0.id', $capitolo->id)
        ->has('casse')
        ->has('esercizi')
        ->has('gestioni')
        ->has('fornitori')
    );

    // Senza conto contabile il capitolo sparisce dalla tendina: l'Action lo
    // rifiuterebbe comunque, e mostrarlo produrrebbe un errore dopo il click.
    DB::table('conti')->where('id', $capitolo->id)->update(['conto_contabile_id' => null]);

    $this->actingAs($this->user)
        ->get(route('admin.gestionale.regolazioni-immediate.create', $condominio))
        ->assertInertia(fn ($page) => $page->has('capitoli', 0));
});

test('i fondi non compaiono tra le casse: sono partizioni virtuali, non uscite di cassa reali', function () {
    $ctx = setupPagamentiService();
    [$condominio] = $ctx;

    $contoFondo = DB::table('conti_contabili')->insertGetId([
        'condominio_id' => $condominio->id,
        'ruolo' => 'fondo_riserva_cassa',
        'codice' => 'FND-CASSA',
        'nome' => 'Fondo riserva',
        'tipo' => 'attivo',
        'categoria' => 'crediti',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('casse')->insert([
        'condominio_id' => $condominio->id,
        'conto_contabile_id' => $contoFondo,
        'nome' => 'Fondo riserva',
        'tipo' => 'fondo',
        'attiva' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($this->user)
        ->get(route('admin.gestionale.regolazioni-immediate.create', $condominio))
        ->assertInertia(fn ($page) => $page
            ->has('casse', 1)
            ->where('casse.0.nome', 'Conto Corrente Test')
        );
});

// ─── Storno: la via d'uscita che la regola cardine impone ───────────────────

test('la regolazione immediata si storna con una scrittura inversa quadrata', function () {
    $ctx = setupPagamentiService();
    [$condominio, $esercizio, , , , $capitolo] = $ctx;

    $originale = (new RegistraRegolazioneImmediataAction())
        ->execute(datiRegolazioneImmediata($ctx), $condominio, $esercizio);

    $this->actingAs($this->user)
        ->post(route('admin.gestionale.regolazioni-immediate.storno', [$condominio, $originale]), [
            'motivo' => 'Capitolo di spesa sbagliato in fase di registrazione',
        ])
        ->assertSessionHasNoErrors();

    $storno = \App\Models\Gestionale\ScritturaContabile::where('scrittura_padre_id', $originale->id)->firstOrFail();

    expect($storno->tipo_movimento->value)->toBe('storno_regolazione_immediata')
        ->and($storno->numero_protocollo)->toStartWith('STO-');

    assertQuadraturaPerfetta($storno->id);

    // Speculare: il capitolo si scarica in AVERE, la cassa rientra in DARE.
    $dare = $storno->righe()->where('tipo_riga', 'dare')->first();
    $avere = $storno->righe()->where('tipo_riga', 'avere')->first();
    expect($dare->cassa_id)->not->toBeNull()
        ->and($avere->voce_spesa_id)->toBe($capitolo->id);

    // Effetto netto sul capitolo: zero. Il budget torna libero.
    $netto = DB::table('righe_scritture')
        ->whereIn('scrittura_id', [$originale->id, $storno->id])
        ->where('voce_spesa_id', $capitolo->id)
        ->selectRaw("SUM(CASE WHEN tipo_riga='dare' THEN importo ELSE -importo END) as netto")
        ->value('netto');
    expect((int) $netto)->toBe(0);

    // L'originale resta nel giornale: append-only, mai cancellazione.
    expect(\App\Models\Gestionale\ScritturaContabile::find($originale->id))->not->toBeNull();
});

test('una regolazione immediata già stornata non si storna due volte', function () {
    $ctx = setupPagamentiService();
    [$condominio, $esercizio] = $ctx;

    $originale = (new RegistraRegolazioneImmediataAction())
        ->execute(datiRegolazioneImmediata($ctx), $condominio, $esercizio);

    $payload = ['motivo' => 'Registrazione errata, da annullare'];

    $this->actingAs($this->user)
        ->post(route('admin.gestionale.regolazioni-immediate.storno', [$condominio, $originale]), $payload);

    $this->actingAs($this->user)
        ->post(route('admin.gestionale.regolazioni-immediate.storno', [$condominio, $originale]), $payload)
        ->assertSessionHas('message.type', 'error');

    expect(\App\Models\Gestionale\ScritturaContabile::where('scrittura_padre_id', $originale->id)->count())->toBe(1);
});

test('lo storno di una regolazione immediata resta possibile anche a esercizio chiuso', function () {
    $ctx = setupPagamentiService();
    [$condominio, $esercizio] = $ctx;

    $originale = (new RegistraRegolazioneImmediataAction())
        ->execute(datiRegolazioneImmediata($ctx), $condominio, $esercizio);

    // L'esercizio si chiude: la regola cardine dice che lo storno resta ammesso.
    $esercizio->update(['stato' => 'chiuso']);
    $esercizioNuovo = \App\Models\Esercizio::factory()->create([
        'condominio_id' => $condominio->id,
        'stato' => 'aperto',
    ]);

    $this->actingAs($this->user)
        ->post(route('admin.gestionale.regolazioni-immediate.storno', [$condominio, $originale]), [
            'motivo' => 'Errore rilevato dopo la chiusura dell esercizio',
        ])
        ->assertSessionHasNoErrors();

    $storno = \App\Models\Gestionale\ScritturaContabile::where('scrittura_padre_id', $originale->id)->firstOrFail();

    // Lo storno vive nell'esercizio aperto, l'originale resta nel suo.
    expect($storno->esercizio_id)->toBe($esercizioNuovo->id)
        ->and($storno->causale)->toContain('Originale in esercizio');
    assertQuadraturaPerfetta($storno->id);
});

test('l esercizio chiuso non è selezionabile per una regolazione immediata', function () {
    $ctx = setupPagamentiService();
    [$condominio, $esercizio] = $ctx;

    $esercizio->update(['stato' => 'chiuso']);

    $response = $this->actingAs($this->user)->post(
        route('admin.gestionale.regolazioni-immediate.store', $condominio),
        datiRegolazioneImmediata($ctx)
    );

    $response->assertSessionHasErrors('esercizio_id');
});
