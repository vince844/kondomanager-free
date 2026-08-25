<?php

/**
 * beta.34 — Il saldo stampato sull'avviso appartiene a UNA gestione.
 *
 * `PianoRateQuoteService` alimenta l'avviso di pagamento. Per scrivere la riga
 * «Saldo iniziale» sommava i saldi filtrando per esercizio + condominio, senza
 * dire quale gestione: con un'ordinaria e una straordinaria aperte nello stesso
 * esercizio, l'avviso dell'ordinaria stampava anche il pregresso della
 * straordinaria.
 *
 * Il generatore del piano (`GenerateSaldiAction`) ha sempre filtrato per
 * `gestione_id`. Erano la stampa e il generatore a guardare assi diversi, e a
 * rimetterci era il foglio che arriva al condòmino — il posto peggiore dove
 * sbagliare un numero, perché è l'unico che nessuno riconcilia.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * NOTA SULLA FORMA DI QUESTI TEST — non è una scelta di stile.
 *
 * Lo scenario più diretto sarebbe: stessa persona, stessa unità, un saldo per
 * gestione. Su SQLite è impossibile: sopravvive un indice UNIQUE
 * (esercizio_id, anagrafica_id, immobile_id) che su MySQL è stato rimosso a
 * marzo 2026 e su SQLite no. I test girano su SQLite, quindi quello scenario
 * qui non si può costruire.
 *
 * Per restare fedeli al difetto senza dipendere dallo schema, il pregresso
 * delle due gestioni è distribuito su chiavi diverse: due unità della stessa
 * persona nel test per anagrafica, due persone della stessa unità nel test per
 * immobile. La somma sbagliata è identica, il vincolo non viene toccato.
 * ────────────────────────────────────────────────────────────────────────────
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Models\Tabella;
use App\Services\PianoRateQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** Condominio con DUE gestioni aperte sullo stesso esercizio, due unità e due persone. */
function scenarioDueGestioni(): object
{
    $condominio = Condominio::create([
        'nome' => 'Condominio Due Gestioni', 'uuid' => (string) Str::uuid(),
        'indirizzo' => 'Via Roma 1', 'citta' => 'Milano', 'cap' => '20100', 'provincia' => 'MI',
    ]);
    $esercizio = Esercizio::create([
        'condominio_id' => $condominio->id, 'nome' => '2026',
        'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto',
    ]);

    $ordinaria = Gestione::create([
        'condominio_id' => $condominio->id, 'nome' => 'Ordinaria 2026',
        'data_inizio' => '2026-01-01', 'tipo' => 'ordinaria', 'saldo_applicato' => false,
    ]);
    $ordinaria->esercizi()->attach($esercizio->id, ['attiva' => true]);

    $straordinaria = Gestione::create([
        'condominio_id' => $condominio->id, 'nome' => 'Rifacimento facciata',
        'data_inizio' => '2026-01-01', 'tipo' => 'straordinaria', 'saldo_applicato' => false,
    ]);
    $straordinaria->esercizi()->attach($esercizio->id, ['attiva' => true]);

    $laura = Anagrafica::create([
        'condominio_id' => $condominio->id, 'nome' => 'Laura', 'cognome' => 'Bianchi',
        'email' => 'laura.duegestioni@test.it', 'indirizzo' => 'Via Verdi 10',
        'cap' => '00100', 'citta' => 'Roma', 'provincia' => 'RM', 'codice_fiscale' => 'BNCLRA80A41H501U',
    ]);
    $marco = Anagrafica::create([
        'condominio_id' => $condominio->id, 'nome' => 'Marco', 'cognome' => 'Neri',
        'email' => 'marco.duegestioni@test.it', 'indirizzo' => 'Via Verdi 12',
        'cap' => '00100', 'citta' => 'Roma', 'provincia' => 'RM', 'codice_fiscale' => 'NREMRC78B02H501V',
    ]);

    $tabella = Tabella::create([
        'condominio_id' => $condominio->id, 'nome' => 'Generale',
        'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true,
    ]);

    // Int 1 è di Laura e Marco a metà; Int 2 è solo di Laura.
    $int1 = Immobile::create([
        'condominio_id' => $condominio->id, 'nome' => 'Int 1', 'descrizione' => 'Appartamento',
        'interno' => '1', 'foglio' => '1', 'particella' => '100', 'subalterno' => '1',
    ]);
    $int1->anagrafiche()->attach($laura->id, [
        'tipologia' => 'proprietario', 'quota' => 50, 'attivo' => true, 'data_inizio' => now()->subYear(),
    ]);
    $int1->anagrafiche()->attach($marco->id, [
        'tipologia' => 'proprietario', 'quota' => 50, 'attivo' => true, 'data_inizio' => now()->subYear(),
    ]);

    $int2 = Immobile::create([
        'condominio_id' => $condominio->id, 'nome' => 'Int 2', 'descrizione' => 'Appartamento',
        'interno' => '2', 'foglio' => '1', 'particella' => '100', 'subalterno' => '2',
    ]);
    $int2->anagrafiche()->attach($laura->id, [
        'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => now()->subYear(),
    ]);

    foreach ([$int1, $int2] as $imm) {
        DB::table('quote_tabella')->insert([
            'tabella_id' => $tabella->id, 'immobile_id' => $imm->id, 'valore' => 500,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $pianoConti = PianoConto::create([
        'condominio_id' => $condominio->id, 'gestione_id' => $ordinaria->id, 'nome' => 'Preventivo 2026',
    ]);
    $capitolo = Conto::forceCreate([
        'piano_conto_id' => $pianoConti->id, 'nome' => 'Spese Generali',
        'importo' => 100000, 'tipo' => 'spesa', 'attivo' => true,
    ]);
    $pivot = DB::table('conto_tabella_millesimale')->insertGetId([
        'conto_id' => $capitolo->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('conto_tabella_ripartizioni')->insert([
        'conto_tabella_millesimale_id' => $pivot, 'soggetto' => 'proprietario', 'percentuale' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return (object) compact(
        'condominio', 'esercizio', 'ordinaria', 'straordinaria',
        'laura', 'marco', 'int1', 'int2', 'tabella', 'pianoConti'
    );
}

function saldoDi(object $s, Gestione $gestione, Anagrafica $chi, Immobile $dove, int $centesimi): Saldo
{
    return Saldo::create([
        'esercizio_id' => $s->esercizio->id, 'condominio_id' => $s->condominio->id,
        'anagrafica_id' => $chi->id, 'immobile_id' => $dove->id,
        'gestione_id' => $gestione->id, 'saldo_iniziale' => $centesimi,
        'origine' => 'manuale', 'is_applicato' => false,
    ]);
}

function generaPianoOrdinaria(object $s): PianoRate
{
    $piano = PianoRate::create([
        'condominio_id' => $s->condominio->id, 'gestione_id' => $s->ordinaria->id,
        'nome' => 'Piano ordinario 2026', 'numero_rate' => 2, 'metodo_distribuzione' => 'rata_zero',
        'stato' => 'bozza', 'tipo' => 'ordinario',
    ]);
    app(GeneratePianoRateAction::class)->execute(pianoRate: $piano, forzaApplicazioneSaldi: true);

    return $piano->refresh();
}

test('l\'avviso per anagrafica somma solo i saldi della gestione del piano', function () {
    $s = scenarioDueGestioni();

    // Laura: 300,00 € di pregresso sull'ordinaria (Int 1) e 500,00 € sulla
    // straordinaria (Int 2). L'avviso del piano ordinario deve dire 300,00 €.
    saldoDi($s, $s->ordinaria, $s->laura, $s->int1, 30000);
    saldoDi($s, $s->straordinaria, $s->laura, $s->int2, 50000);

    $piano = generaPianoOrdinaria($s);

    $riga = app(PianoRateQuoteService::class)
        ->quotePerAnagrafica($piano)
        ->firstWhere('anagrafica.id', $s->laura->id);

    // Prima della correzione qui arrivava 80000: la query filtrava esercizio +
    // condominio + anagrafica, e la gestione non compariva da nessuna parte.
    expect($riga['saldo_iniziale'])->toBe(30000);
});

test('l\'avviso per immobile somma solo i saldi della gestione del piano', function () {
    $s = scenarioDueGestioni();

    // Stessa unità, due comproprietari, due gestioni diverse.
    saldoDi($s, $s->ordinaria, $s->laura, $s->int1, 30000);
    saldoDi($s, $s->straordinaria, $s->marco, $s->int1, 50000);

    $piano = generaPianoOrdinaria($s);

    $riga = app(PianoRateQuoteService::class)
        ->quotePerImmobile($piano)
        ->firstWhere('immobile.id', $s->int1->id);

    expect($riga['totale_debiti'])->toBe(30000);
});

test('un credito su un\'altra gestione non abbassa il saldo stampato', function () {
    $s = scenarioDueGestioni();

    // Il verso opposto, e il più insidioso: senza il filtro l'avviso avrebbe
    // mostrato 300,00 − 500,00 = −200,00 €, cioè un credito che non esiste.
    // Un numero troppo BASSO non fa reclamare nessuno, quindi non emerge mai.
    saldoDi($s, $s->ordinaria, $s->laura, $s->int1, 30000);
    saldoDi($s, $s->straordinaria, $s->laura, $s->int2, -50000);

    $piano = generaPianoOrdinaria($s);

    $riga = app(PianoRateQuoteService::class)
        ->quotePerAnagrafica($piano)
        ->firstWhere('anagrafica.id', $s->laura->id);

    expect($riga['saldo_iniziale'])->toBe(30000);
});
