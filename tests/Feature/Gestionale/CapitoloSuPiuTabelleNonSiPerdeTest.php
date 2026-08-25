<?php

/**
 * # Una voce ripartita su più tabelle non le perde risalvandola
 *
 * ## Il difetto, riprodotto il 22/08/2026
 *
 * L'art. 1126 c.c. divide la spesa del lastrico solare in due: **un terzo** a chi ne ha l'uso
 * esclusivo, **due terzi** a tutti gli altri. In KondoManager si fa così: due tabelle millesimali
 * collegate alla stessa voce di spesa, con coefficiente 33,33 e 66,67. Lo stesso vale per l'art.
 * 1124 sulle scale e per l'ascensore: **non è un caso esotico, è il caso di scuola.**
 *
 * La scheda di modifica di una voce, però, conosce **una tabella sola**:
 * `ModalModificaConto.vue` legge `tabelle_millesimali?.[0]` e manda quella. E il controller, alla
 * riga marcata «FIX: pulizia delle vecchie tabelle orfane», cancella **tutte** le associazioni
 * diverse da quella ricevuta, insieme alle loro ripartizioni.
 *
 * Risultato misurato: si apre la scheda per cambiare l'importo, si salva, e la seconda tabella
 * sparisce. Senza chiedere niente, senza dire niente.
 *
 *     tabelle collegate prima : 2   (33,33 + 66,67)
 *     tabelle collegate dopo  : 1   (33,33)
 *
 * ## ⚠️ Perché **non** è della famiglia della beta.61 e della beta.63
 *
 * Lì il denaro finiva sulla persona sbagliata **con i totali che tornavano**, che è il difetto
 * invisibile. Qui no, e il merito è della beta.63: con i coefficienti che dichiarano solo il
 * 33,33%, `CalcoloQuoteService` registra il 66,67% mancante come **scoperto** — una riga visibile
 * nel riparto, con la sua causale. Il danno è la perdita del dato, non un addebito silenzioso.
 *
 * È il motivo per cui questa correzione è arrivata alla beta.68 e non prima: la rete c'era già.
 *
 * ## Cosa correggono questi test, e cosa no
 *
 * **Correggono la distruzione**: una scheda che non sa esprimere due tabelle non deve poterle
 * cancellare. È una rimozione, non una funzione nuova.
 *
 * **Non insegnano alla scheda a gestirne più d'una**: quella è una schermata nuova, ed è una
 * funzione — va nella 1.11, per il criterio del 22/08/2026. Fino ad allora una voce su più tabelle
 * si modifica dalla sezione delle tabelle collegate, che sa farlo da sempre.
 */

require_once __DIR__.'/GestionaleTestHelpers.php';

use App\Enums\Permission;
use App\Models\{Condominio, Esercizio, Gestione, User};
use App\Models\Gestionale\{Conto, PianoConto};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Il contesto minimo, con una voce di spesa e le tabelle del lastrico.
 *
 * ⚠️ Il nome è lungo di proposito: `scenarioLastrico()` esiste già in
 * `tests/Feature/Riparto/CoefficientiSottoIlCentoTest.php`, la guardia della beta.63 sullo stesso
 * identico caso — ed è quella che qui fa da rete, registrando come scoperto il pezzo di spesa che
 * la tabella superstite non copre. Pest carica tutti i file nello stesso spazio dei nomi, quindi
 * due funzioni omonime si scontrano al caricamento.
 */
function scenarioLastricoSuDueTabelle(): array
{
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    foreach (Permission::cases() as $p) {
        \Spatie\Permission\Models\Permission::findOrCreate($p->value, 'web');
    }
    $ruolo->syncPermissions(Permission::cases());
    $utente = User::factory()->create();
    $utente->assignRole($ruolo);

    $condominio = Condominio::factory()->create();
    $esercizio  = Esercizio::factory()->create(['condominio_id' => $condominio->id, 'stato' => 'aperto']);
    $gestione   = Gestione::factory()->create(['condominio_id' => $condominio->id]);

    DB::table('esercizio_gestione')->insert([
        'esercizio_id' => $esercizio->id, 'gestione_id' => $gestione->id,
        'attiva' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $pianoConti = PianoConto::factory()->create([
        'condominio_id' => $condominio->id, 'gestione_id' => $gestione->id,
    ]);

    $conto = Conto::create([
        'piano_conto_id' => $pianoConti->id, 'parent_id' => null, 'is_capitolo' => false,
        'importo' => 900000, 'nome' => 'Rifacimento lastrico', 'tipo' => 'spesa',
    ]);

    return compact('utente', 'condominio', 'esercizio', 'gestione', 'pianoConti', 'conto');
}

/** Collega una tabella alla voce, con il suo peso e la ripartizione al proprietario. */
function collegaTabellaAllaVoce(array $s, string $nome, float $coefficiente): int
{
    $tabellaId = DB::table('tabelle')->insertGetId([
        'condominio_id' => $s['condominio']->id, 'nome' => $nome,
    ]);

    $associazioneId = DB::table('conto_tabella_millesimale')->insertGetId([
        'conto_id' => $s['conto']->id, 'tabella_id' => $tabellaId,
        'coefficiente' => $coefficiente, 'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('conto_tabella_ripartizioni')->insert([
        'conto_tabella_millesimale_id' => $associazioneId, 'soggetto' => 'proprietario',
        'percentuale' => 100, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $tabellaId;
}

/** Risalva la voce dalla scheda, come fa `ModalModificaConto`: una tabella sola. */
function risalvaDallaScheda(array $s, int $tabellaId, array $extra = [])
{
    return test()->actingAs($s['utente'])->put(
        route('admin.gestionale.esercizi.piani-conti.conti.update', [
            $s['condominio'], $s['esercizio'], $s['pianoConti'], $s['conto'],
        ]),
        array_merge([
            'nome' => 'Rifacimento lastrico', 'tipo' => 'spesa', 'importo' => '9500',
            'parent_id' => null, 'isCapitolo' => false, 'isSottoConto' => false,
            'tabella_millesimale_id' => $tabellaId,
            'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        ], $extra)
    );
}

function tabelleDellaVoce(array $s)
{
    return DB::table('conto_tabella_millesimale')->where('conto_id', $s['conto']->id)->get();
}

it('lo scenario è quello vero: due tabelle, e la scheda ne conosce una', function () {
    // ⚠️ Senza questo, il giorno che il pivot cambia nome o forma questi test proverebbero uno
    // scenario che non esiste — verdi per sempre, e per la peggiore delle ragioni.
    $s = scenarioLastricoSuDueTabelle();
    collegaTabellaAllaVoce($s, 'Uso esclusivo lastrico', 33.33);
    collegaTabellaAllaVoce($s, 'Proprietà generale', 66.67);

    expect(tabelleDellaVoce($s))->toHaveCount(2);
    expect((float) tabelleDellaVoce($s)->sum('coefficiente'))->toBe(100.0);
});

it('⚠️ risalvando dalla scheda la seconda tabella non sparisce', function () {
    $s = scenarioLastricoSuDueTabelle();
    $uso = collegaTabellaAllaVoce($s, 'Uso esclusivo lastrico', 33.33);
    collegaTabellaAllaVoce($s, 'Proprietà generale', 66.67);

    // L'amministratore apre la scheda e cambia **solo l'importo**. La scheda, che conosce una
    // tabella sola, rimanda la prima.
    risalvaDallaScheda($s, $uso);

    expect(tabelleDellaVoce($s))->toHaveCount(2,
        "Risalvando la voce dalla sua scheda, la seconda tabella millesimale è stata cancellata.\n\n".
        "È il caso dell'art. 1126 c.c. — lastrico solare, un terzo a chi ne ha l'uso esclusivo e due\n".
        "terzi agli altri — e non è un caso di scuola: è **il** caso. Chi apre la scheda per\n".
        "correggere un importo si ritrova la ripartizione dimezzata, senza che niente glielo dica.\n\n".
        "Una scheda che non sa esprimere due tabelle non deve poterle cancellare. Il posto della\n".
        "correzione è la «pulizia delle vecchie tabelle orfane» in `ContoController@update`."
    );

    $coefficienti = tabelleDellaVoce($s)->pluck('coefficiente')->map(fn ($c) => (float) $c)->sort()->values();
    expect($coefficienti->all())->toBe([33.33, 66.67], 'I pesi delle due tabelle sono stati alterati.');
});

it('e nemmeno le ripartizioni della seconda tabella', function () {
    // La controprova che serve: salvare le associazioni e perderne le ripartizioni lascerebbe due
    // tabelle collegate a nessun soggetto — un riparto che non addebita niente a nessuno.
    $s = scenarioLastricoSuDueTabelle();
    $uso = collegaTabellaAllaVoce($s, 'Uso esclusivo lastrico', 33.33);
    collegaTabellaAllaVoce($s, 'Proprietà generale', 66.67);

    risalvaDallaScheda($s, $uso);

    $ripartizioni = DB::table('conto_tabella_ripartizioni')
        ->whereIn('conto_tabella_millesimale_id', tabelleDellaVoce($s)->pluck('id'))
        ->count();

    expect($ripartizioni)->toBe(2, 'Le ripartizioni delle tabelle sono state cancellate.');
});

it('l\'importo però si salva davvero: la voce resta modificabile', function () {
    // ⚠️ **Senza questa, la guardia sopra si soddisfa rifiutando il salvataggio.** Il difetto è che
    // la scheda distrugge, non che la scheda esista: chi ha una voce su due tabelle deve poterne
    // ancora correggere nome, importo e note.
    $s = scenarioLastricoSuDueTabelle();
    $uso = collegaTabellaAllaVoce($s, 'Uso esclusivo lastrico', 33.33);
    collegaTabellaAllaVoce($s, 'Proprietà generale', 66.67);

    risalvaDallaScheda($s, $uso, ['importo' => '9500', 'nome' => 'Rifacimento lastrico (perizia 2026)']);

    $conto = Conto::find($s['conto']->id);
    expect((int) $conto->importo)->toBe(950000, "L'importo non è stato salvato.");
    expect($conto->nome)->toBe('Rifacimento lastrico (perizia 2026)', 'Il nome non è stato salvato.');
});

it('su una voce con UNA tabella sola il comportamento non cambia', function () {
    // La seconda controprova: la correzione riguarda le voci su più tabelle, e non deve spostare di
    // un millesimo il caso normale — cambiare la tabella di una voce che ne ha una sola deve
    // continuare a funzionare, sostituendola.
    $s = scenarioLastricoSuDueTabelle();
    collegaTabellaAllaVoce($s, 'Proprietà generale', 100);
    $nuova = DB::table('tabelle')->insertGetId(['condominio_id' => $s['condominio']->id, 'nome' => 'Riscaldamento']);

    risalvaDallaScheda($s, $nuova);

    $tabelle = tabelleDellaVoce($s);
    expect($tabelle)->toHaveCount(1, 'La vecchia tabella non è stata sostituita.');
    expect((int) $tabelle->first()->tabella_id)->toBe($nuova, 'La tabella non è quella scelta nella scheda.');
});
