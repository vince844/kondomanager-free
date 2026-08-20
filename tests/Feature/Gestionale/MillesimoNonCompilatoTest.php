<?php

/**
 * beta.61 — Un'unità senza millesimo non sparisce più in silenzio dal piano rate.
 *
 * ## Il difetto, in euro
 *
 * Il motore normalizza sulla somma **reale** della tabella, mai su 1000: una tabella che somma a
 * 900 ripartisce comunque il 100% della spesa. Quindi un'unità senza millesimo non paga zero —
 * **sparisce**, senza nemmeno una riga da € 0,00 da spiegare — e la sua quota la pagano le altre.
 * Il totale del piano resta identico al preventivo, quindi nessun controllo contabile ha niente da
 * segnalare. Misurato: dieci unità, nove compilate e una dimenticata, e ciascuna delle nove paga
 * **€ 1.111,11 invece di € 1.000,00**, col centesimo di resto su uno solo.
 *
 * ## Perché fino alla .60 non si vedeva, e perché il `required` non proteggeva
 *
 * `quote.*.valore` era `required`, quindi lo stato non era raggiungibile lasciando una casella
 * vuota — a database ci sono **zero** righe NULL su 98. Ma non era una difesa: chi spuntava
 * «associa tutti gli immobili esistenti» otteneva una tabella **non più salvabile** finché non
 * l'aveva compilata tutta, e la via d'uscita rapida era scrivere `0`. Il motore legge lo zero come
 * «non partecipa», ed ecco lo stesso danno per un'altra strada. Il `required` non impediva
 * l'errore: lo convogliava in una forma che il programma non sa più distinguere da una scelta.
 *
 * ## La distinzione che questa beta introduce
 *
 * - **riga assente** → l'unità non partecipa (convenzione di casa, quella dell'importatore);
 * - **valore zero** → l'unità non partecipa, detto esplicitamente. Legittimo, e muto;
 * - **valore vuoto (NULL)** → *non ancora compilato*. È l'unico caso che avvisa.
 *
 * Avvisare anche sullo zero significherebbe urlare su nove tabelle su sedici che sono corrette —
 * l'ascensore senza i piani terra, le scale senza i negozi con ingresso su strada.
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Exceptions\Gestionale\ScopertiNonAccettatiException;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Tabella;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Uno scenario minimo: N unità in una tabella, un capitolo da € 1.000,00 ripartito su quella.
 *
 * `$millesimi` accetta un numero, la stringa vuota per «riga assente», o `null` per «riga presente
 * senza valore» — che sono i tre casi che questo file deve saper distinguere.
 *
 * @param  list<int|float|null|string>  $millesimi
 */
function scenarioMillesimi(array $millesimi): object
{
    $condominio = Condominio::create([
        'nome' => 'Condominio Millesimi', 'uuid' => (string) Str::uuid(),
        'indirizzo' => 'Via Roma 1', 'citta' => 'Milano', 'cap' => '20100', 'provincia' => 'MI',
    ]);

    $esercizio = Esercizio::create([
        'condominio_id' => $condominio->id, 'nome' => '2026',
        'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto',
    ]);

    $gestione = Gestione::create([
        'condominio_id' => $condominio->id, 'nome' => 'Ordinaria 2026',
        'data_inizio' => '2026-01-01', 'tipo' => 'ordinaria',
    ]);
    $gestione->esercizi()->attach($esercizio->id, ['attiva' => true]);

    $tabella = Tabella::create([
        'condominio_id' => $condominio->id, 'nome' => 'Generale',
        'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true,
    ]);

    $immobili = [];

    foreach ($millesimi as $i => $valore) {
        $anagrafica = Anagrafica::create([
            'condominio_id' => $condominio->id,
            'nome' => 'Proprietario', 'cognome' => 'N'.($i + 1),
            'email' => 'p'.($i + 1).'@test.it',
            'indirizzo' => 'Via Verdi 10', 'cap' => '00100', 'citta' => 'Roma', 'provincia' => 'RM',
            'codice_fiscale' => 'RSSMRA80A01H50'.$i.'U',
        ]);

        $immobile = Immobile::create([
            'condominio_id' => $condominio->id,
            'nome' => 'Int '.($i + 1), 'descrizione' => 'Appartamento', 'interno' => (string) ($i + 1),
            'foglio' => '1', 'particella' => '100', 'subalterno' => (string) ($i + 1),
        ]);

        $immobile->anagrafiche()->attach($anagrafica->id, [
            'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true,
            'data_inizio' => now()->subYear(),
        ]);

        $immobili[] = $immobile;

        // La stringa vuota significa «nessuna riga in tabella»: non è la stessa cosa di una riga
        // con valore nullo, ed è esattamente la differenza che questo file misura.
        if ($valore === '') {
            continue;
        }

        DB::table('quote_tabella')->insert([
            'tabella_id' => $tabella->id,
            'immobile_id' => $immobile->id,
            'valore' => $valore,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $pianoConti = PianoConto::create([
        'condominio_id' => $condominio->id, 'gestione_id' => $gestione->id, 'nome' => 'Preventivo 2026',
    ]);

    $capitolo = Conto::forceCreate([
        'piano_conto_id' => $pianoConti->id, 'nome' => 'Spese Generali',
        'importo' => 100000, 'tipo' => 'spesa', 'attivo' => true,
    ]);

    $pivotId = DB::table('conto_tabella_millesimale')->insertGetId([
        'conto_id' => $capitolo->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('conto_tabella_ripartizioni')->insert([
        'conto_tabella_millesimale_id' => $pivotId, 'soggetto' => 'proprietario', 'percentuale' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $pianoRate = PianoRate::create([
        'condominio_id' => $condominio->id, 'gestione_id' => $gestione->id,
        'nome' => 'Piano 2026', 'numero_rate' => 1,
        'metodo_distribuzione' => 'tutte_rate', 'stato' => 'bozza',
    ]);

    return (object) compact('condominio', 'gestione', 'tabella', 'immobili', 'capitolo', 'pianoRate');
}

/** Genera, e restituisce l'eccezione degli scoperti se il cancello si chiude. */
function generaScenario(object $s, bool $accetta = false, ?string $nota = null): ?ScopertiNonAccettatiException
{
    try {
        app(GeneratePianoRateAction::class)->execute($s->pianoRate, null, [], $accetta, $nota);

        return null;
    } catch (ScopertiNonAccettatiException $e) {
        return $e;
    }
}

test('una riga senza valore ferma la generazione e dice chi è', function () {
    $s = scenarioMillesimi([500, null]);

    $eccezione = generaScenario($s);

    expect($eccezione)->not->toBeNull();

    $motivi = array_column($eccezione->getScoperti(), 'motivo');
    expect($motivi)->toContain('millesimo_non_compilato');

    $riga = collect($eccezione->getScoperti())->firstWhere('motivo', 'millesimo_non_compilato');

    expect($riga['immobile_id'])->toBe($s->immobili[1]->id)
        ->and($riga['immobile_nome'])->toBe('Int 2')
        ->and($riga['tabella_id'])->toBe($s->tabella->id)
        // ⚠️ L'importo è **0 dichiarato**: quanto avrebbe dovuto pagare quell'unità non è
        // calcolabile, perché il numero che serve è proprio quello che manca. La schermata
        // rende un trattino, non «€ 0,00».
        ->and($riga['importo'])->toBe(0);
});

test('lo zero non avvisa: significa «non partecipa», ed è legittimo', function () {
    // È come sono fatte le tabelle parziali vere. Avvisare qui vorrebbe dire chiedere di
    // compilare righe già decise, su nove tabelle su sedici.
    $s = scenarioMillesimi([500, 0]);

    expect(generaScenario($s))->toBeNull();
});

test('la riga assente non avvisa: è la convenzione di casa per «non partecipa»', function () {
    $s = scenarioMillesimi([500, '']);

    expect(generaScenario($s))->toBeNull();
});

test('una tabella completa non avvisa', function () {
    // Il controllo sul ramo innocente: una guardia che si accende sull'uso normale è un guasto,
    // non una difesa. È la lezione della beta.60 sul `replacer`.
    $s = scenarioMillesimi([500, 500]);

    expect(generaScenario($s))->toBeNull();
    expect(DB::table('rate')->where('piano_rate_id', $s->pianoRate->id)->count())->toBeGreaterThan(0);
});

test('accettando si procede, e la motivazione resta agli atti', function () {
    $s = scenarioMillesimi([500, null]);

    expect(generaScenario($s, accetta: true, nota: 'Millesimo in attesa dal tecnico, procedo.'))->toBeNull();

    expect($s->pianoRate->fresh()->nota_scoperti)->toBe('Millesimo in attesa dal tecnico, procedo.');
});

test("procedendo, l'aritmetica resta quella di sempre: l'avviso non corregge, avverte", function () {
    // ⚠️ Va detto e presidiato, perché è la parte che l'avviso **non** risolve: chi accetta si
    // prende il riparto di prima, cioè l'unità senza millesimo fuori dal piano e la sua quota
    // sulle altre. L'avviso serve a saperlo prima e a metterlo agli atti, non a rimediare.
    $s = scenarioMillesimi([500, null]);

    generaScenario($s, accetta: true, nota: 'Procedo consapevolmente, il millesimo arriva poi.');

    $quote = DB::table('rate_quote')
        ->join('rate', 'rate.id', '=', 'rate_quote.rata_id')
        ->where('rate.piano_rate_id', $s->pianoRate->id)
        ->get();

    $perImmobile = $quote->groupBy('immobile_id')->map(fn ($g) => (int) $g->sum('importo'));

    // L'unità con il millesimo paga tutto; quella senza non compare proprio — nemmeno a zero.
    expect($perImmobile->get($s->immobili[0]->id))->toBe(100000)
        ->and($perImmobile->has($s->immobili[1]->id))->toBeFalse();
});

test('accettando nasce davvero il promemoria in Inbox, e dice la causa giusta', function () {
    // ⚠️ Il difetto trovato dalla revisione avversariale: il task non nasceva mai. La riga era
    // `loadMissing('gestione.esercizio')`, ma `Gestione` ha solo `esercizi()` al plurale —
    // `RelationNotFoundException`, inghiottita dal `catch (\Throwable)` in un `Log::warning`.
    // Il promemoria che doveva riportare l'amministratore a compilare il millesimo non compariva.
    // Lo stesso file lo sapeva: ottanta righe più su c'è il commento che lo dichiara.
    // Il promemoria porta la firma di chi ha accettato: senza un utente autenticato
    // `created_by` ricadrebbe su un id inventato e l'inserimento sbatterebbe nella chiave esterna.
    $this->actingAs(\App\Models\User::factory()->create());

    $s = scenarioMillesimi([500, null]);

    generaScenario($s, accetta: true, nota: 'Millesimo in attesa dal tecnico, procedo.');

    // Il contesto del task sta sotto `meta->context`, non alla radice di `meta`.
    $evento = DB::table('eventi')->where('meta->context->piano_rate_id', $s->pianoRate->id)->first();

    expect($evento)->not->toBeNull('il promemoria deve esistere');
    expect($evento->description)->toContain('millesimo');
    // ⚠️ E non deve dire la causa vecchia: per una riga senza millesimo l'importo è zero per
    // costruzione, e «€ 0,00 in quote non assegnabili per unità senza anagrafiche attive» era
    // una riga che si leggeva come un non-problema, con la causa sbagliata.
    expect($evento->description)->not->toContain('anagrafiche attive');
    expect($evento->description)->not->toContain('€ 0,00');
});
