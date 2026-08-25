<?php

/**
 * beta.43 — Una guardia di dominio non deve arrivare all'utente come pagina 500.
 *
 * `StoreIncassoRateAction` verifica, prima di scrivere qualunque cosa, che
 * `importo_totale === somma algebrica delle righe + eccedenza`. È la rete che protegge la
 * partita doppia e va benissimo dov'è. Il problema era come si presentava: un
 * `RuntimeException('Totale non corrispondente.')` che `IncassoRateController::store()` non
 * catturava. Risultato per l'amministratore: schermata di errore del server, e la
 * distribuzione appena fatta a mano fra le rate persa.
 *
 * Chi la faceva scattare era il difetto dell'eccedenza — il credito contato due volte,
 * corretto in `usePaymentDistribution` e nel suo chiamante, con la sua suite vitest. Ma la
 * guardia può scattare per qualunque altra ragione futura, e il modo in cui si presenta
 * non deve dipendere da quale difetto l'ha svegliata.
 *
 * Questi test guardano i due lati: che il rifiuto sia un errore di validazione con il modulo
 * conservato, e che **niente** sia stato scritto.
 */

use App\Actions\Gestionale\Movimenti\StoreIncassoRateAction;
use App\Exceptions\Gestionale\TotaleIncassoNonCorrispondenteException;
use App\Models\Gestionale\ScritturaContabile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

function utenteIncasso(): \App\Models\User
{
    Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $user = \App\Models\User::factory()->create();
    $user->givePermissionTo('Accesso pannello amministratore');

    return $user;
}

/** Condominio con una cassa, un pagante e una quota di rata da 300,00 € aperta. */
function scenarioIncasso(): object
{
    [$condominio, $esercizio, $gestione, , $capitolo, , $immobileId] = setupContabile();

    // Ogni cassa ha il suo conto contabile dedicato: `casse.conto_contabile_id` è NOT NULL,
    // ed è la colonna su cui l'incasso scrive il DARE.
    $contoCassaId = DB::table('conti_contabili')->insertGetId([
        'condominio_id' => $condominio->id,
        'ruolo' => null,
        'codice' => '1010.01',
        'nome' => 'Banca Test',
        'tipo' => 'attivo',
        'categoria' => 'liquidita',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $cassaId = DB::table('casse')->insertGetId([
        'condominio_id' => $condominio->id,
        'conto_contabile_id' => $contoCassaId,
        'nome' => 'Banca Test',
        'tipo' => 'banca',
        'saldo_iniziale' => 0,
        'attiva' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $anagrafica = \App\Models\Anagrafica::forceCreate([
        'nome' => 'Pagante Test',
        'email' => 'pagante.quadra@test.it',
        'indirizzo' => 'Via Roma 1',
        'codice_fiscale' => 'PGNTST00A01H501Z',
    ]);

    // Il pagante deve risultare del condominio: è la `Rule::exists` della FormRequest.
    DB::table('anagrafica_condominio')->insert([
        'anagrafica_id' => $anagrafica->id,
        'condominio_id' => $condominio->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $pianoRateId = DB::table('piani_rate')->insertGetId([
        'condominio_id' => $condominio->id,
        'gestione_id' => $gestione->id,
        'nome' => 'Piano Test',
        'numero_rate' => 1,
        'metodo_distribuzione' => 'rata_zero',
        'stato' => 'bozza',
        'tipo' => 'ordinario',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $rataId = DB::table('rate')->insertGetId([
        'piano_rate_id' => $pianoRateId,
        'conto_id' => $capitolo->id,
        'numero_rata' => 1,
        'data_scadenza' => '2026-03-31',
        'descrizione' => 'Rata 1',
        'importo_totale' => 30000,
        'stato' => 'emessa',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $quotaId = DB::table('rate_quote')->insertGetId([
        'rata_id' => $rataId,
        'anagrafica_id' => $anagrafica->id,
        'immobile_id' => $immobileId,
        'importo' => 30000,
        'importo_pagato' => 0,
        'stato' => 'aperta',
        'data_scadenza' => '2026-03-31',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return (object) compact('condominio', 'esercizio', 'gestione', 'cassaId', 'anagrafica', 'quotaId');
}

/**
 * Il payload che il difetto dell'eccedenza produceva: 300,00 € di riga incassata, ma un
 * anticipo di 200,00 € dichiarato su 300,00 € entrati. 300 ≠ 300 + 200.
 */
function payloadNonQuadrante(object $s): array
{
    return [
        'pagante_id' => $s->anagrafica->id,
        'cassa_id' => $s->cassaId,
        'gestione_id' => $s->gestione->id,
        'data_pagamento' => '2026-03-01',
        'importo_totale' => 300,
        'descrizione' => 'Incasso con eccedenza gonfiata',
        'eccedenza' => 200,
        'dettaglio_pagamenti' => [
            ['rata_id' => $s->quotaId, 'importo' => 300],
        ],
    ];
}

test('l\'incasso che non quadra torna al modulo con l\'errore, non con un 500', function () {
    $s = scenarioIncasso();

    $risposta = $this->actingAs(utenteIncasso())
        ->from(route('admin.gestionale.movimenti-rate.create', $s->condominio->id))
        ->post(route('admin.gestionale.movimenti-rate.store', $s->condominio->id), payloadNonQuadrante($s));

    $risposta->assertRedirect()
        ->assertSessionHasErrors('importo_totale');

    expect($risposta->status())->not->toBe(500);
});

test('il messaggio dice di quanto è lo scarto, non solo che non torna', function () {
    // «Totale non corrispondente» non permetteva a nessuno di capire da che parte guardare —
    // ed è il motivo per cui il difetto che lo faceva scattare è vissuto a lungo indisturbato.
    $s = scenarioIncasso();

    $risposta = $this->actingAs(utenteIncasso())
        ->from(route('admin.gestionale.movimenti-rate.create', $s->condominio->id))
        ->post(route('admin.gestionale.movimenti-rate.store', $s->condominio->id), payloadNonQuadrante($s));

    $messaggio = $risposta->getSession()->get('errors')->first('importo_totale');

    expect($messaggio)
        ->toContain('300,00')   // l'importo incassato
        ->toContain('200,00');  // l'anticipo dichiarato, che è anche lo scarto
});

test('quando il totale non quadra non viene scritto niente', function () {
    $s = scenarioIncasso();

    $scrittureIniziali = ScritturaContabile::count();

    $this->actingAs(utenteIncasso())
        ->from(route('admin.gestionale.movimenti-rate.create', $s->condominio->id))
        ->post(route('admin.gestionale.movimenti-rate.store', $s->condominio->id), payloadNonQuadrante($s));

    expect(ScritturaContabile::count())->toBe($scrittureIniziali)
        ->and(DB::table('rate_quote')->where('id', $s->quotaId)->value('importo_pagato'))->toBe(0);
});

test('la guardia solleva un\'eccezione di dominio, non una generica', function () {
    // Il tipo conta: un `RuntimeException` nudo non è distinguibile da un guasto vero, e nel
    // catch generico del controller finirebbe a `Log::error` con lo stack trace — rumore in
    // mezzo agli errori che contano. È la stessa lezione delle due eccezioni distinte della
    // beta.37 sulle chiavi di idempotenza.
    $s = scenarioIncasso();

    expect(fn () => app(StoreIncassoRateAction::class)
        ->execute(payloadNonQuadrante($s), $s->condominio, $s->esercizio))
        ->toThrow(TotaleIncassoNonCorrispondenteException::class);
});

test('un incasso che quadra passa: la guardia non è diventata più stretta', function () {
    // Controprova obbligatoria. Sostituire un 500 con un rifiuto è un miglioramento solo se
    // le registrazioni corrette continuano a passare.
    $s = scenarioIncasso();

    $payload = payloadNonQuadrante($s);
    $payload['eccedenza'] = 0;

    $this->actingAs(utenteIncasso())
        ->post(route('admin.gestionale.movimenti-rate.store', $s->condominio->id), $payload)
        ->assertSessionHasNoErrors();

    expect(DB::table('rate_quote')->where('id', $s->quotaId)->value('importo_pagato'))->toBe(30000);
});
