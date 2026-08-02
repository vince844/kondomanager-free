<?php

/**
 * beta.34 — Il gate dei saldi guarda i saldi liberi, non il flag di gestione.
 *
 * Ultimo posto in cui sopravviveva il «troppo largo» corretto nella beta.33.
 *
 * `SaldoEsercizioService::calcolaSaldoApplicabile()` apriva con un'uscita
 * anticipata su `gestioni.saldo_applicato`. Dalla beta.32 quel flag è un
 * DERIVATO: `allineaFlagGestione()` lo accende con un `exists()`, cioè appena
 * UN saldo della gestione è bloccato. Usarlo come gate significava che il primo
 * piano emesso rendeva inassorbibili tutti i saldi rimasti liberi — compresi
 * quelli inseriti DOPO, che la beta.33 ha reso legittimi proprio per permettere
 * la correzione in corso di gestione.
 *
 * L'effetto pratico: l'amministratore trova un pregresso dimenticato a marzo,
 * lo registra (gli riesce, dalla .33), poi crea il piano integrativo per
 * chiederlo — e la pagina gli risponde che «i saldi pregressi sono già stati
 * integrati», senza offrirgli nulla. Quel saldo non è più assorbibile da
 * nessun piano.
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Models\Tabella;
use App\Services\Gestionale\SaldoEsercizioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function scenarioGate(): object
{
    $condominio = Condominio::create([
        'nome' => 'Condominio Gate', 'uuid' => (string) Str::uuid(),
        'indirizzo' => 'Via Roma 1', 'citta' => 'Milano', 'cap' => '20100', 'provincia' => 'MI',
    ]);
    $esercizio = Esercizio::create([
        'condominio_id' => $condominio->id, 'nome' => '2026',
        'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto',
    ]);
    $gestione = Gestione::create([
        'condominio_id' => $condominio->id, 'nome' => 'Ordinaria 2026',
        'data_inizio' => '2026-01-01', 'tipo' => 'ordinaria', 'saldo_applicato' => false,
    ]);
    $gestione->esercizi()->attach($esercizio->id, ['attiva' => true]);

    $primo = Anagrafica::create([
        'condominio_id' => $condominio->id, 'nome' => 'Anna', 'cognome' => 'Conti',
        'email' => 'anna.gate@test.it', 'indirizzo' => 'Via Verdi 10',
        'cap' => '00100', 'citta' => 'Roma', 'provincia' => 'RM', 'codice_fiscale' => 'CNTNNA80A41H501U',
    ]);
    $secondo = Anagrafica::create([
        'condominio_id' => $condominio->id, 'nome' => 'Bruno', 'cognome' => 'Dolci',
        'email' => 'bruno.gate@test.it', 'indirizzo' => 'Via Verdi 12',
        'cap' => '00100', 'citta' => 'Roma', 'provincia' => 'RM', 'codice_fiscale' => 'DLCBRN78B02H501V',
    ]);

    $tabella = Tabella::create([
        'condominio_id' => $condominio->id, 'nome' => 'Generale',
        'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true,
    ]);

    $immobili = [];
    foreach ([['Int 1', $primo], ['Int 2', $secondo]] as $i => [$nome, $proprietario]) {
        $imm = Immobile::create([
            'condominio_id' => $condominio->id, 'nome' => $nome, 'descrizione' => 'Appartamento',
            'interno' => (string) ($i + 1), 'foglio' => '1', 'particella' => '100', 'subalterno' => (string) ($i + 1),
        ]);
        $imm->anagrafiche()->attach($proprietario->id, [
            'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => now()->subYear(),
        ]);
        DB::table('quote_tabella')->insert([
            'tabella_id' => $tabella->id, 'immobile_id' => $imm->id, 'valore' => 500,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $immobili[] = $imm;
    }

    $pianoConti = PianoConto::create([
        'condominio_id' => $condominio->id, 'gestione_id' => $gestione->id, 'nome' => 'Preventivo 2026',
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

    // Il pregresso noto al momento del preventivo.
    $saldoIniziale = Saldo::create([
        'esercizio_id' => $esercizio->id, 'condominio_id' => $condominio->id,
        'anagrafica_id' => $primo->id, 'immobile_id' => $immobili[0]->id,
        'gestione_id' => $gestione->id, 'saldo_iniziale' => 30000,
        'origine' => 'manuale', 'is_applicato' => false,
    ]);

    return (object) [
        'condominio' => $condominio, 'esercizio' => $esercizio, 'gestione' => $gestione,
        'primo' => $primo, 'secondo' => $secondo,
        'int1' => $immobili[0], 'int2' => $immobili[1],
        'saldoIniziale' => $saldoIniziale,
    ];
}

/** Genera il piano e ne emette una quota, così i saldi assorbiti si bloccano. */
function generaEdEmetti(object $s): PianoRate
{
    $piano = PianoRate::create([
        'condominio_id' => $s->condominio->id, 'gestione_id' => $s->gestione->id,
        'nome' => 'Preventivo 2026', 'numero_rate' => 2, 'metodo_distribuzione' => 'rata_zero',
        'stato' => 'bozza', 'tipo' => 'ordinario',
    ]);
    app(GeneratePianoRateAction::class)->execute(pianoRate: $piano, forzaApplicazioneSaldi: true);

    $quota = $piano->rate()->with('rateQuote')->get()->flatMap->rateQuote->first();
    $scrittura = ScritturaContabile::create([
        'condominio_id' => $s->condominio->id, 'esercizio_id' => $s->esercizio->id,
        'gestione_id' => $s->gestione->id,
        'data_registrazione' => '2026-03-10', 'data_competenza' => '2026-03-10',
        'causale' => 'Emissione rata di test', 'tipo_movimento' => 'emissione_rata',
        'stato' => 'registrata',
    ]);
    $quota->update(['scrittura_contabile_id' => $scrittura->id]);

    return $piano->refresh();
}

test('un saldo aggiunto dopo l\'emissione resta assorbibile da un piano integrativo', function () {
    $s = scenarioGate();
    generaEdEmetti($s);

    // Il flag di gestione è ora acceso: un saldo bloccato esiste davvero.
    expect($s->gestione->fresh()->saldo_applicato)->toBeTrue();

    // Marzo: emerge un pregresso dimenticato di un'altra unità. Registrarlo è
    // lecito dalla beta.33 — il lucchetto vale per riga, e questa riga è nuova.
    Saldo::create([
        'esercizio_id' => $s->esercizio->id, 'condominio_id' => $s->condominio->id,
        'anagrafica_id' => $s->secondo->id, 'immobile_id' => $s->int2->id,
        'gestione_id' => $s->gestione->id, 'saldo_iniziale' => 45000,
        'origine' => 'manuale', 'is_applicato' => false,
    ]);

    $info = app(SaldoEsercizioService::class)->calcolaSaldoApplicabile($s->gestione->fresh());

    // Prima della correzione: applicabile=false, saldo=0, e il messaggio
    // «i saldi pregressi sono già stati integrati» — cioè quel pregresso non
    // era più chiedibile da nessun piano.
    expect($info['applicabile'])->toBeTrue()
        ->and($info['has_movimenti'])->toBeTrue()
        ->and($info['saldo'])->toBe(45000);
});

test('il saldo proposto conta solo le righe libere, non quelle gia assorbite', function () {
    $s = scenarioGate();
    generaEdEmetti($s);

    Saldo::create([
        'esercizio_id' => $s->esercizio->id, 'condominio_id' => $s->condominio->id,
        'anagrafica_id' => $s->secondo->id, 'immobile_id' => $s->int2->id,
        'gestione_id' => $s->gestione->id, 'saldo_iniziale' => 45000,
        'origine' => 'manuale', 'is_applicato' => false,
    ]);

    $info = app(SaldoEsercizioService::class)->calcolaSaldoApplicabile($s->gestione->fresh());

    // 45000, non 75000: i 30000 del primo saldo sono già dentro il piano emesso
    // e chiederli di nuovo sarebbe un doppio addebito.
    expect($info['saldo'])->toBe(45000);
});

test('quando tutti i saldi sono stati assorbiti il gate resta chiuso, e lo dice', function () {
    $s = scenarioGate();
    generaEdEmetti($s);

    $info = app(SaldoEsercizioService::class)->calcolaSaldoApplicabile($s->gestione->fresh());

    // Nessuna riga libera: qui il divieto è corretto, e il messaggio pure.
    expect($info['applicabile'])->toBeFalse()
        ->and($info['saldo'])->toBe(0)
        ->and($info['motivo'])->toContain('già');
});

test('una gestione senza alcun saldo resta al primo anno', function () {
    $s = scenarioGate();
    $s->saldoIniziale->delete();

    $info = app(SaldoEsercizioService::class)->calcolaSaldoApplicabile($s->gestione->fresh());

    expect($info['applicabile'])->toBeTrue()
        ->and($info['has_movimenti'])->toBeFalse()
        ->and($info['is_primo_anno'])->toBeTrue();
});

test('un debito verso fornitore non viene proposto come saldo assorbibile', function () {
    $s = scenarioGate();
    $s->saldoIniziale->delete();

    $fornitore = \App\Models\Fornitore::create([
        'condominio_id' => $s->condominio->id, 'ragione_sociale' => 'Impresa Test',
        'partita_iva' => '01234567890', 'indirizzo' => 'Via Fornitori 1',
        'citta' => 'Milano', 'cap' => '20100', 'provincia' => 'MI',
    ]);

    Saldo::create([
        'esercizio_id' => $s->esercizio->id, 'condominio_id' => $s->condominio->id,
        'fornitore_id' => $fornitore->id, 'gestione_id' => $s->gestione->id,
        'saldo_iniziale' => 90000, 'origine' => 'manuale', 'is_applicato' => false,
    ]);

    $info = app(SaldoEsercizioService::class)->calcolaSaldoApplicabile($s->gestione->fresh());

    // I debiti verso fornitore vivono nella stessa tabella ma non entrano mai
    // in un piano rate: GenerateSaldiAction li esclude, e il numero proposto
    // deve coincidere con quello che il piano assorbirebbe davvero.
    expect($info['saldo'])->toBe(0)
        ->and($info['has_movimenti'])->toBeFalse();
});
