<?php

/**
 * REGRESSIONE beta.32 — "Il lucchetto orfano".
 *
 * Segnalazione forum: dopo aver creato un piano rate con i saldi pregressi,
 * l'amministratore si accorge che il piano è sbagliato. Cancella il piano, ma
 * i saldi restano bloccati col lucchetto e non sono più modificabili; ricreando
 * il piano, i saldi pregressi non vengono più inclusi (riparte dal solo
 * preventivo). Il wallet resta congelato senza alcun modo di sbloccarlo dalla UI.
 *
 * La causa è che il lucchetto (gestioni.saldo_applicato + saldi.is_applicato)
 * non era legato al piano che aveva assorbito i saldi: veniva dedotto a
 * posteriori leggendo il contenuto delle quote. Ogni percorso che rigenera le
 * rate (Ricalcola, Rimuovi voce) cancellava quelle quote e, con esse, l'unica
 * traccia che permetteva a destroy() di sbloccare.
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
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Scenario minimo ma completo: un condominio con un immobile, un proprietario,
 * una tabella millesimale, un capitolo di spesa da 1.000 € e un saldo pregresso
 * a debito di 300 € sulla gestione ordinaria.
 */
function scenarioSaldiLock(int $saldoIniziale = 30000): object
{
    $condominio = Condominio::create([
        'nome' => 'Condominio Lucchetto',
        'uuid' => (string) Str::uuid(),
        'indirizzo' => 'Via Roma 1', 'citta' => 'Milano', 'cap' => '20100', 'provincia' => 'MI',
    ]);

    $esercizio = Esercizio::create([
        'condominio_id' => $condominio->id,
        'nome' => '2026',
        'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto',
    ]);

    $gestione = Gestione::create([
        'condominio_id' => $condominio->id,
        'nome' => 'Ordinaria 2026',
        'data_inizio' => '2026-01-01', 'tipo' => 'ordinaria',
        'saldo_applicato' => false,
    ]);
    $gestione->esercizi()->attach($esercizio->id, ['attiva' => true]);

    $anagrafica = Anagrafica::create([
        'condominio_id' => $condominio->id,
        'nome' => 'Mario', 'cognome' => 'Rossi', 'email' => 'mario.lucchetto@test.it',
        'indirizzo' => 'Via Verdi 10', 'cap' => '00100', 'citta' => 'Roma', 'provincia' => 'RM',
        'codice_fiscale' => 'RSSMRA80A01H501U',
    ]);

    $tabella = Tabella::create([
        'condominio_id' => $condominio->id,
        'nome' => 'Generale', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true,
    ]);

    $immobile = Immobile::create([
        'condominio_id' => $condominio->id,
        'nome' => 'Int 1', 'descrizione' => 'Appartamento', 'interno' => '1',
        'foglio' => '1', 'particella' => '100', 'subalterno' => '1',
    ]);
    $immobile->anagrafiche()->attach($anagrafica->id, [
        'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => now()->subYear(),
    ]);

    DB::table('quote_tabella')->insert([
        'tabella_id' => $tabella->id, 'immobile_id' => $immobile->id, 'valore' => 1000,
        'created_at' => now(), 'updated_at' => now(),
    ]);

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

    $saldo = Saldo::create([
        'esercizio_id' => $esercizio->id,
        'condominio_id' => $condominio->id,
        'anagrafica_id' => $anagrafica->id,
        'immobile_id' => $immobile->id,
        'gestione_id' => $gestione->id,
        'saldo_iniziale' => $saldoIniziale, // positivo = debito pregresso
        'origine' => 'manuale',
        'is_applicato' => false,
    ]);

    return (object) compact('condominio', 'esercizio', 'gestione', 'anagrafica', 'immobile', 'capitolo', 'saldo');
}

function adminUtente(): User
{
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);

    $user = User::factory()->create();
    $user->assignRole($ruolo);

    return $user;
}

/** Crea il piano rate + genera le rate con i saldi inclusi, come fa lo store(). */
function creaPianoConSaldi(object $s, string $metodo = 'rata_zero'): PianoRate
{
    $piano = PianoRate::create([
        'condominio_id' => $s->condominio->id,
        'gestione_id' => $s->gestione->id,
        'nome' => 'Piano 2026',
        'numero_rate' => 2,
        'metodo_distribuzione' => $metodo,
        'stato' => 'bozza',
        'tipo' => 'ordinario',
    ]);

    // È la generazione stessa a chiudere il lucchetto, intestandolo al piano.
    app(GeneratePianoRateAction::class)->execute(
        pianoRate: $piano,
        forzaApplicazioneSaldi: true
    );

    return $piano->refresh();
}

/** Somma dei saldi pregressi effettivamente presenti nelle quote del piano. */
function saldoNelPiano(PianoRate $piano): int
{
    return $piano->fresh()->rate()->with('rateQuote')->get()
        ->flatMap->rateQuote
        ->sum(fn ($q) => (int) data_get($q->regole_calcolo, 'importi.saldo_usato', 0));
}

// ---------------------------------------------------------------------------
// 1. IL CUORE DEL BUG: il ricalcolo perde i saldi
// ---------------------------------------------------------------------------

test('il ricalcolo di un piano rate non deve perdere i saldi già assorbiti', function () {
    $s = scenarioSaldiLock();
    $user = adminUtente();
    $piano = creaPianoConSaldi($s);

    // Il piano appena generato contiene i 300 € di pregresso.
    expect(saldoNelPiano($piano))->toBe(30000);

    // L'amministratore clicca "Ricalcola" (rotta regenerate).
    $this->actingAs($user)
        ->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [
            $s->condominio->id, $s->esercizio->id, $piano->id,
        ]))
        ->assertSessionHasNoErrors();

    // I saldi devono essere ancora dentro il piano: senza questo, il condòmino
    // smette silenziosamente di essere addebitato del suo pregresso.
    expect(saldoNelPiano($piano))->toBe(30000);
});

// ---------------------------------------------------------------------------
// 2. LA CONSEGUENZA SEGNALATA: il lucchetto resta chiuso per sempre
// ---------------------------------------------------------------------------

test('dopo un ricalcolo, eliminare il piano rate sblocca comunque i saldi', function () {
    $s = scenarioSaldiLock();
    $user = adminUtente();
    $piano = creaPianoConSaldi($s);

    expect($s->gestione->fresh()->saldo_applicato)->toBeTrue()
        ->and($s->saldo->fresh()->is_applicato)->toBeTrue();

    $this->actingAs($user)
        ->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [
            $s->condominio->id, $s->esercizio->id, $piano->id,
        ]));

    $this->actingAs($user)
        ->delete(route('admin.gestionale.esercizi.piani-rate.destroy', [
            $s->condominio->id, $s->esercizio->id, $piano->id,
        ]));

    expect(PianoRate::find($piano->id))->toBeNull()
        ->and($s->gestione->fresh()->saldo_applicato)->toBeFalse()
        ->and($s->saldo->fresh()->is_applicato)->toBeFalse();
});

test('eliminare un piano rate mai generato non lascia i saldi bloccati', function () {
    $s = scenarioSaldiLock();
    $user = adminUtente();

    // Creazione dalla UI SENZA generazione immediata delle rate.
    $this->actingAs($user)
        ->post(route('admin.gestionale.esercizi.piani-rate.store', [$s->condominio->id, $s->esercizio->id]), [
            'gestione_id' => $s->gestione->id,
            'nome' => 'Piano in bozza',
            'tipo' => 'ordinario',
            'metodo_distribuzione' => 'rata_zero',
            'numero_rate' => 2,
            'giorno_scadenza' => 10,
            'capitoli_ids' => [$s->capitolo->id],
            'genera_subito' => false,
            'recurrence_enabled' => false,
        ])->assertSessionHasNoErrors();

    $piano = PianoRate::where('gestione_id', $s->gestione->id)->firstOrFail();

    // Nessuna rata generata ⇒ nessun saldo assorbito ⇒ nessun lucchetto.
    expect($s->gestione->fresh()->saldo_applicato)->toBeFalse()
        ->and($s->saldo->fresh()->is_applicato)->toBeFalse();

    $this->actingAs($user)
        ->delete(route('admin.gestionale.esercizi.piani-rate.destroy', [
            $s->condominio->id, $s->esercizio->id, $piano->id,
        ]));

    expect($s->gestione->fresh()->saldo_applicato)->toBeFalse()
        ->and($s->saldo->fresh()->is_applicato)->toBeFalse();
});

// ---------------------------------------------------------------------------
// 3. DANNO COLLATERALE: lo sblocco a tappeto tocca anche i debiti fornitore
// ---------------------------------------------------------------------------

test('eliminare un piano rate non sblocca i debiti pregressi verso i fornitori', function () {
    $s = scenarioSaldiLock();
    $user = adminUtente();

    // Un debito verso fornitore vive nella stessa tabella saldi con
    // is_applicato=true proprio per restare fuori dai piani rate.
    $fornitore = \App\Models\Fornitore::create([
        'ragione_sociale' => 'Idraulica Bianchi SRL',
        'partita_iva' => '01234567890',
        'indirizzo' => 'Via Tubi 5', 'cap' => '20100', 'comune' => 'Milano', 'provincia' => 'MI',
    ]);
    $debitoFornitore = Saldo::create([
        'esercizio_id' => $s->esercizio->id,
        'condominio_id' => $s->condominio->id,
        'gestione_id' => $s->gestione->id,
        'fornitore_id' => $fornitore->id,
        'anagrafica_id' => null,
        'immobile_id' => null,
        'saldo_iniziale' => -50000,
        'descrizione' => 'Fattura 2025 non pagata',
        'origine' => 'manuale',
        'is_applicato' => true,
    ]);

    $piano = creaPianoConSaldi($s);

    $this->actingAs($user)
        ->delete(route('admin.gestionale.esercizi.piani-rate.destroy', [
            $s->condominio->id, $s->esercizio->id, $piano->id,
        ]));

    // Il saldo del condòmino torna libero...
    expect($s->saldo->fresh()->is_applicato)->toBeFalse()
        // ...ma il debito verso il fornitore no: non è mai stato in questo piano.
        ->and($debitoFornitore->fresh()->is_applicato)->toBeTrue();
});

// ---------------------------------------------------------------------------
// 4. RIPARAZIONE DEI DATI GIÀ ROTTI (migrazione beta.32)
// ---------------------------------------------------------------------------

/** Riesegue la sola migrazione di riparazione (le colonne esistono già). */
function eseguiRiparazioneLucchetti(): void
{
    $migration = require database_path('migrations/2026_07_31_120000_add_piano_rate_id_to_saldi_table.php');
    $migration->up();
}

test('la migrazione riapre il lucchetto rimasto orfano nelle installazioni già colpite', function () {
    $s = scenarioSaldiLock();

    // Stato "malato" com'era su disco prima della beta.32: lucchetti chiusi
    // senza nessun piano rate che li rivendichi.
    $s->gestione->update(['saldo_applicato' => true, 'nota_saldo' => 'Saldo Netto +300,00 processato']);
    $s->saldo->update(['is_applicato' => true, 'piano_rate_id' => null]);

    eseguiRiparazioneLucchetti();

    expect($s->gestione->fresh()->saldo_applicato)->toBeFalse()
        ->and($s->gestione->fresh()->nota_saldo)->toBeNull()
        ->and($s->saldo->fresh()->is_applicato)->toBeFalse();
});

test('la migrazione non tocca i lucchetti legittimi ma li intesta al piano che li contiene', function () {
    $s = scenarioSaldiLock();
    $piano = creaPianoConSaldi($s);

    // Simuliamo un DB pre-beta.32: il legame esplicito non esisteva.
    Saldo::where('id', $s->saldo->id)->update(['piano_rate_id' => null]);

    eseguiRiparazioneLucchetti();

    expect($s->saldo->fresh()->is_applicato)->toBeTrue()
        ->and($s->saldo->fresh()->piano_rate_id)->toBe($piano->id)
        ->and($s->gestione->fresh()->saldo_applicato)->toBeTrue();
});

// ---------------------------------------------------------------------------
// 5. IL PERCORSO COMPLETO DELLA SEGNALAZIONE
// ---------------------------------------------------------------------------

test('cancellato il piano sbagliato, il nuovo piano riporta di nuovo i saldi pregressi', function () {
    $s = scenarioSaldiLock();
    $user = adminUtente();
    $primoPiano = creaPianoConSaldi($s);

    $this->actingAs($user)
        ->delete(route('admin.gestionale.esercizi.piani-rate.destroy', [
            $s->condominio->id, $s->esercizio->id, $primoPiano->id,
        ]));

    // Secondo tentativo: i 300 € di pregresso devono tornare nel piano.
    $s->gestione->refresh();
    $secondoPiano = creaPianoConSaldi($s);

    expect(saldoNelPiano($secondoPiano))->toBe(30000);
});

// ---------------------------------------------------------------------------
// 6. GUARDIE AGGIUNTE DOPO LA REVISIONE AVVERSARIALE DEL FIX
// ---------------------------------------------------------------------------

test('il riparto manuale di un saldo solidale sopravvive al ricalcolo', function () {
    $s = scenarioSaldiLock();
    $user = adminUtente();

    // Saldo solidale: nessuna anagrafica assegnata, due comproprietari 50/50.
    $s->saldo->update(['anagrafica_id' => null]);
    $secondo = Anagrafica::create([
        'condominio_id' => $s->condominio->id,
        'nome' => 'Luisa', 'cognome' => 'Bianchi', 'email' => 'luisa.lucchetto@test.it',
        'indirizzo' => 'Via Verdi 12', 'cap' => '00100', 'citta' => 'Roma', 'provincia' => 'RM',
        'codice_fiscale' => 'BNCLSU85A41H501T',
    ]);
    DB::table('anagrafica_immobile')->where('immobile_id', $s->immobile->id)->update(['quota' => 50]);
    $s->immobile->anagrafiche()->attach($secondo->id, [
        'tipologia' => 'proprietario', 'quota' => 50, 'attivo' => true, 'data_inizio' => now()->subYear(),
    ]);

    $piano = PianoRate::create([
        'condominio_id' => $s->condominio->id,
        'gestione_id' => $s->gestione->id,
        'nome' => 'Piano con riparto manuale',
        'numero_rate' => 1,
        'metodo_distribuzione' => 'rata_zero',
        'stato' => 'bozza',
        'tipo' => 'ordinario',
        // L'amministratore forza 250/50 invece del pro-quota 150/150.
        'saldi_config' => [['saldo_id' => $s->saldo->id, 'ripartizioni' => [
            ['anagrafica_id' => $s->anagrafica->id, 'importo' => 25000],
            ['anagrafica_id' => $secondo->id, 'importo' => 5000],
        ]]],
    ]);

    app(GeneratePianoRateAction::class)->execute(
        pianoRate: $piano,
        forzaApplicazioneSaldi: true,
        saldiConfig: $piano->saldi_config
    );

    $quotaPrima = fn () => $piano->fresh()->rate()->with('rateQuote')->get()
        ->flatMap->rateQuote->firstWhere('anagrafica_id', $s->anagrafica->id)?->importo;

    // 250,00 € esatti: il riparto manuale arriva già in centesimi dal controller,
    // e non va riconvertito una seconda volta (addebitava 25.000,00 €).
    expect($quotaPrima())->toBe(25000);

    $this->actingAs($user)
        ->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [
            $s->condominio->id, $s->esercizio->id, $piano->id,
        ]))->assertSessionHasNoErrors();

    // Senza persistenza il ricalcolo ricadrebbe sul pro-quota: 150/150.
    expect($quotaPrima())->toBe(25000);
});

test('il riparto manuale di un saldo solidale non viene convertito due volte in centesimi', function () {
    $s = scenarioSaldiLock();
    $s->saldo->update(['anagrafica_id' => null]);

    $piano = PianoRate::create([
        'condominio_id' => $s->condominio->id,
        'gestione_id' => $s->gestione->id,
        'nome' => 'Piano riparto manuale',
        'numero_rate' => 1,
        'metodo_distribuzione' => 'rata_zero',
        'stato' => 'bozza',
        'tipo' => 'ordinario',
    ]);

    // Percorso reale completo: la stringa mascherata del form passa da
    // MoneyHelper::toCents nello store e arriva qui già in centesimi.
    $importoCents = \App\Helpers\MoneyHelper::toCents('250,00');
    expect($importoCents)->toBe(25000);

    app(GeneratePianoRateAction::class)->execute(
        pianoRate: $piano,
        forzaApplicazioneSaldi: true,
        saldiConfig: [['saldo_id' => $s->saldo->id, 'ripartizioni' => [
            ['anagrafica_id' => $s->anagrafica->id, 'importo' => $importoCents],
        ]]]
    );

    expect(saldoNelPiano($piano))->toBe(25000);
});

test('un piano straordinario non assorbe i pregressi della gestione quando viene generato in differita', function () {
    $s = scenarioSaldiLock();

    $fornitore = \App\Models\Fornitore::create([
        'ragione_sociale' => 'Facciate Rossi SRL',
        'partita_iva' => '11122233344',
        'indirizzo' => 'Via Ponteggi 1', 'cap' => '20100', 'comune' => 'Milano', 'provincia' => 'MI',
    ]);
    $fattura = \App\Models\Gestionale\FatturaPassiva::create([
        'condominio_id' => $s->condominio->id,
        'fornitore_id' => $fornitore->id,
        'esercizio_id' => $s->esercizio->id,
        'tipo_documento' => 'fattura',
        'numero_documento' => 'FT-STRAORD-1',
        'data_documento' => now()->format('Y-m-d'),
        'data_scadenza' => now()->addDays(30)->format('Y-m-d'),
        'is_pregresso' => false,
        'importo_imponibile' => 40000, 'importo_iva' => 0, 'importo_ritenuta' => 0,
        'totale_documento' => 40000, 'netto_a_pagare' => 40000,
        'stato_pagamento' => 'aperta', 'stato_approvazione' => 'approvata',
        'modalita_pagamento' => 'bonifico',
    ]);
    DB::table('righe_fattura')->insert([
        'fattura_passiva_id' => $fattura->id,
        'conto_id' => $s->capitolo->id,
        'immobile_id' => $s->immobile->id, // ad personam: quota assegnabile
        'descrizione' => 'Riparazione balcone',
        'aliquota_iva' => 0,
        'importo_imponibile' => 40000, 'importo_iva' => 0,
        'is_sopravvenienza' => false, 'is_rateizzata' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $straordinario = PianoRate::create([
        'condominio_id' => $s->condominio->id,
        'gestione_id' => $s->gestione->id,
        'nome' => 'Lavori facciata',
        'numero_rate' => 1,
        'metodo_distribuzione' => 'rata_zero',
        'stato' => 'bozza',
        'tipo' => 'straordinario',
        // Lo store persiste lo stato del wallet, che qui è "ci sono saldi liberi".
        'applica_saldi' => true,
    ]);
    $straordinario->fatture()->attach($fattura->id, ['importo_collegato' => 40000]);

    app(GeneratePianoRateAction::class)->execute(pianoRate: $straordinario);

    expect(saldoNelPiano($straordinario))->toBe(0)
        ->and($s->saldo->fresh()->is_applicato)->toBeFalse();
});

test('i debiti verso fornitori non vengono mai intestati a un piano rate', function () {
    $s = scenarioSaldiLock();

    $fornitore = \App\Models\Fornitore::create([
        'ragione_sociale' => 'Edilizia Verdi SRL',
        'partita_iva' => '09876543210',
        'indirizzo' => 'Via Calce 3', 'cap' => '20100', 'comune' => 'Milano', 'provincia' => 'MI',
    ]);

    // Riga anomala: debito fornitore rimasto LIBERO (stato non producibile oggi
    // dalla UI, ma nulla nello schema lo impedisce).
    $debitoLibero = Saldo::create([
        'esercizio_id' => $s->esercizio->id,
        'condominio_id' => $s->condominio->id,
        'gestione_id' => $s->gestione->id,
        'fornitore_id' => $fornitore->id,
        'anagrafica_id' => null,
        'immobile_id' => null,
        'saldo_iniziale' => -80000,
        'origine' => 'manuale',
        'is_applicato' => false,
    ]);

    creaPianoConSaldi($s);

    expect($debitoLibero->fresh()->piano_rate_id)->toBeNull()
        ->and($debitoLibero->fresh()->is_applicato)->toBeFalse();
});

test('la migrazione non indovina quando più piani della stessa gestione contengono saldi', function () {
    $s = scenarioSaldiLock();
    $primo = creaPianoConSaldi($s);

    // Secondo piano che contiene anch'esso quote di saldo: stato raggiungibile
    // solo su dati storici, ma è proprio lì che la riparazione deve essere prudente.
    $secondo = PianoRate::create([
        'condominio_id' => $s->condominio->id,
        'gestione_id' => $s->gestione->id,
        'nome' => 'Piano storico',
        'numero_rate' => 1,
        'metodo_distribuzione' => 'rata_zero',
        'stato' => 'bozza',
        'tipo' => 'ordinario',
    ]);
    $rata = \App\Models\Gestionale\Rata::create([
        'piano_rate_id' => $secondo->id,
        'numero_rata' => 0,
        'data_scadenza' => now(),
        'data_emissione' => now(),
        'descrizione' => 'Saldo Pregresso storico',
        'importo_totale' => 12000,
        'stato' => 'bozza',
    ]);
    // insert() e non create(): 'tipo' non è fillable su RataQuote, ed è così che
    // la generazione reale scrive le quote (GenerateRateQuotesAction usa insert).
    \App\Models\Gestionale\RataQuote::insert([[
        'rata_id' => $rata->id,
        'anagrafica_id' => $s->anagrafica->id,
        'immobile_id' => $s->immobile->id,
        'importo' => 12000,
        'importo_pagato' => 0,
        'stato' => 'da_pagare',
        'tipo' => 'saldo_iniziale',
        'data_scadenza' => now()->format('Y-m-d'),
        'created_at' => now(), 'updated_at' => now(),
    ]]);

    // Torniamo allo stato pre-beta.32: lucchetti senza intestazione.
    Saldo::where('gestione_id', $s->gestione->id)->update(['piano_rate_id' => null, 'is_applicato' => true]);

    eseguiRiparazioneLucchetti();

    // Ambiguo: la riparazione non intesta e non sblocca nulla.
    expect($s->saldo->fresh()->piano_rate_id)->toBeNull()
        ->and($s->saldo->fresh()->is_applicato)->toBeTrue()
        ->and($s->gestione->fresh()->saldo_applicato)->toBeTrue()
        ->and(PianoRate::find($primo->id))->not->toBeNull()
        ->and(PianoRate::find($secondo->id))->not->toBeNull();
});

test('rimuovere una voce di spesa dal piano non fa sparire i saldi pregressi', function () {
    $s = scenarioSaldiLock();
    $user = adminUtente();
    $piano = creaPianoConSaldi($s);
    $piano->capitoli()->attach($s->capitolo->id);

    $this->actingAs($user)
        ->delete(route('admin.gestionale.piani-rate.capitoli.detach', [
            $s->condominio->id, $s->esercizio->id, $piano->id, $s->capitolo->id,
        ]));

    expect(saldoNelPiano($piano))->toBe(30000)
        ->and($s->saldo->fresh()->piano_rate_id)->toBe($piano->id);
});
