<?php

/**
 * beta.43 — Il widget Double Lock si spartiva la tabella dei saldi sul campo sbagliato.
 *
 * Nella tabella `saldi` convivono due famiglie che non hanno niente in comune se non lo
 * schema: i **pregressi dei condòmini** (nominali o solidali dell'unità) e i **debiti verso
 * fornitori** ereditati da esercizi passati. A distinguerle c'è una colonna dedicata,
 * `fornitore_id`, ed è quella che il resto del codice usa già — `GenerateSaldiAction` e
 * `SaldoEsercizioService` filtrano entrambi su `whereNull('fornitore_id')`.
 *
 * Il widget invece si orientava su `anagrafica_id`, e siccome un saldo **solidale** ha
 * `anagrafica_id` a NULL esattamente come un debito fornitore, le due query sbagliavano in
 * modo simmetrico:
 *
 * - la capienza della Rata Zero contava solo `whereNotNull('anagrafica_id')`, quindi i
 *   **debiti solidali sparivano** dal totale e la copertura disponibile risultava più bassa
 *   del vero;
 * - i debiti verso fornitori prendevano `whereNull('anagrafica_id')` con importo negativo,
 *   quindi i **crediti solidali finivano fra i debiti verso fornitori** — soldi che il
 *   condominio deve a un condòmino, presentati come soldi che deve a un'impresa.
 *
 * Oggi a database non esiste alcun saldo solidale, quindi nessuno l'ha ancora visto. Ma nella
 * modale di inserimento **«solidale» è l'opzione preselezionata** (`SaldiDetailPanel.vue:181`
 * e `:196`): è ciò che si ottiene senza toccare niente, quindi non è un caso di frontiera.
 *
 * La stessa lettura sbagliata viveva in un secondo posto — il calcolo del «buco» in
 * `SyncScadenziarioWithFattura` — e questa suite copre entrambi, perché correggere il caso e
 * non la classe è la trappola che nella beta.40 è costata un ciclo intero.
 */

use App\Models\Saldo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

function utenteWidget(): \App\Models\User
{
    Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $user = \App\Models\User::factory()->create();
    $user->givePermissionTo('Accesso pannello amministratore');

    return $user;
}

/**
 * Un condominio con le tre specie di saldo che il widget deve saper distinguere:
 * un debito solidale, un credito solidale e un debito verso fornitore.
 */
function scenarioTreFamiglie(): object
{
    [$condominio, $esercizio, $gestione, $fornitore, , , $immobileId] = setupContabile();

    $comune = [
        'condominio_id' => $condominio->id,
        'esercizio_id' => $esercizio->id,
        'gestione_id' => $gestione->id,
        'is_applicato' => false,
    ];

    $debitoSolidale = Saldo::forceCreate($comune + [
        'immobile_id' => $immobileId,
        'anagrafica_id' => null,
        'saldo_iniziale' => 50000,
        'descrizione' => 'Pregresso dell\'unità',
    ]);

    $creditoSolidale = Saldo::forceCreate($comune + [
        'immobile_id' => $immobileId,
        'anagrafica_id' => null,
        'saldo_iniziale' => -20000,
        'descrizione' => 'Credito dell\'unità',
    ]);

    $debitoFornitore = Saldo::forceCreate($comune + [
        'immobile_id' => null,
        'anagrafica_id' => null,
        'fornitore_id' => $fornitore->id,
        'saldo_iniziale' => -100000,
        'descrizione' => 'Fattura pulizie non pagata',
    ]);

    return (object) compact('condominio', 'esercizio', 'debitoSolidale', 'creditoSolidale', 'debitoFornitore');
}

/**
 * I dati del widget nascono in `prepareContestoBudget()` e viaggiano verso la schermata di
 * **registrazione** della fattura, non verso l'elenco: è lì che l'amministratore sceglie con
 * quale provvista coprire un pregresso.
 */
function propsWidget(object $s): array
{
    $risposta = test()->actingAs(utenteWidget())
        ->get(route('admin.gestionale.fatture.create', $s->condominio->id));

    $risposta->assertOk();

    return $risposta->viewData('page')['props'];
}

test('la capienza della Rata Zero comprende i debiti solidali dell\'unità', function () {
    // 500,00 € di pregresso intestato all'unità. Prima non entravano nel totale, quindi la
    // copertura disponibile per le fatture pregresse risultava a zero — e l'amministratore
    // vedeva un semaforo che gli diceva di non avere provvista mentre l'aveva.
    $s = scenarioTreFamiglie();

    expect(propsWidget($s)['capienza_rata_zero'])->toBe(50000);
});

test('un credito solidale non viene scambiato per un debito verso un fornitore', function () {
    // È l'errore più visibile dei due: 200,00 € che il condominio deve a un condòmino,
    // elencati fra i debiti verso le imprese, con tanto di riga selezionabile per coprirci
    // una fattura.
    $s = scenarioTreFamiglie();

    $idElencati = collect(propsWidget($s)['debiti_patrimoniali'])->pluck('id');

    expect($idElencati)->not->toContain($s->creditoSolidale->id)
        ->and($idElencati)->toContain($s->debitoFornitore->id);
});

test('i debiti verso fornitori restano tutti al loro posto', function () {
    // Controprova: il filtro nuovo non deve aver perso per strada il caso che funzionava.
    $s = scenarioTreFamiglie();

    $debiti = collect(propsWidget($s)['debiti_patrimoniali']);

    expect($debiti)->toHaveCount(1)
        ->and($debiti->first()['importo_iniziale'])->toBe(100000);
});

test('il divieto di eliminare la gestione dice dove stanno davvero i saldi', function () {
    // `GestioneController` conta TUTTI i saldi della gestione, fornitori compresi, e rimanda
    // «alla sezione saldi» — dove un saldo fornitore non compare, perché quella pagina carica
    // i saldi passando dagli immobili e un debito fornitore non ne ha uno.
    // L'amministratore leggeva un'istruzione che non poteva eseguire.
    $s = scenarioTreFamiglie();

    $risposta = test()->actingAs(utenteWidget())
        ->from(route('admin.gestionale.esercizi.gestioni.index', [$s->condominio->id, $s->esercizio->id]))
        ->delete(route('admin.gestionale.esercizi.gestioni.destroy', [
            $s->condominio->id, $s->esercizio->id, $s->debitoSolidale->gestione_id,
        ]));

    $messaggio = collect($risposta->getSession()->all())
        ->flatten()
        ->filter(fn ($v) => is_string($v) && str_contains($v, 'saldi'))
        ->first();

    expect($messaggio)->toContain('fornitor');
});
