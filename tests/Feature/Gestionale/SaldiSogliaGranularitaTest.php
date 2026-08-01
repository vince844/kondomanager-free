<?php

/**
 * beta.33 — "Il lucchetto si apre quando serve".
 *
 * Due difetti distinti, con la stessa origine: il lucchetto sui saldi scatta
 * troppo presto (alla generazione del piano, non alla sua emissione) e agisce
 * troppo largo (sull'intera gestione, non sulla riga assorbita).
 *
 * Il documento `docs/rettifiche_saldo_in_corso_gestione.md` avverte che il
 * lucchetto del saldo di apertura NON va allentato, e ha ragione: protegge il
 * rendiconto. Ma dalla beta.32 quel compito lo assolve il lucchetto per riga
 * (`saldi.piano_rate_id`), che impedisce il doppio assorbimento a monte. Il flag
 * `gestioni.saldo_applicato` è diventato un derivato, e continuare a usarlo come
 * autorità blocca proprio la "rettifica in itinere" che quel documento indica
 * come bisogno scoperto: una correzione trovata dopo, su una gestione che ha già
 * un piano.
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

function scenarioSoglia(): object
{
    $condominio = Condominio::create([
        'nome' => 'Condominio Soglia', 'uuid' => (string) Str::uuid(),
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

    $anagrafica = Anagrafica::create([
        'condominio_id' => $condominio->id, 'nome' => 'Mario', 'cognome' => 'Rossi',
        'email' => 'mario.soglia@test.it', 'indirizzo' => 'Via Verdi 10',
        'cap' => '00100', 'citta' => 'Roma', 'provincia' => 'RM', 'codice_fiscale' => 'RSSMRA80A01H501U',
    ]);
    $tabella = Tabella::create([
        'condominio_id' => $condominio->id, 'nome' => 'Generale',
        'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true,
    ]);
    $immobile = Immobile::create([
        'condominio_id' => $condominio->id, 'nome' => 'Int 1', 'descrizione' => 'Appartamento',
        'interno' => '1', 'foglio' => '1', 'particella' => '100', 'subalterno' => '1',
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
    $pivot = DB::table('conto_tabella_millesimale')->insertGetId([
        'conto_id' => $capitolo->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('conto_tabella_ripartizioni')->insert([
        'conto_tabella_millesimale_id' => $pivot, 'soggetto' => 'proprietario', 'percentuale' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $saldo = Saldo::create([
        'esercizio_id' => $esercizio->id, 'condominio_id' => $condominio->id,
        'anagrafica_id' => $anagrafica->id, 'immobile_id' => $immobile->id,
        'gestione_id' => $gestione->id, 'saldo_iniziale' => 30000,
        'origine' => 'manuale', 'is_applicato' => false,
    ]);

    return (object) compact('condominio', 'esercizio', 'gestione', 'anagrafica', 'immobile', 'capitolo', 'tabella', 'saldo', 'pianoConti');
}

function utenteAdmin(): User
{
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $user = User::factory()->create();
    $user->assignRole($ruolo);
    return $user;
}

function generaPiano(object $s): PianoRate
{
    $piano = PianoRate::create([
        'condominio_id' => $s->condominio->id, 'gestione_id' => $s->gestione->id,
        'nome' => 'Piano 2026', 'numero_rate' => 2, 'metodo_distribuzione' => 'rata_zero',
        'stato' => 'bozza', 'tipo' => 'ordinario',
    ]);
    app(GeneratePianoRateAction::class)->execute(pianoRate: $piano, forzaApplicazioneSaldi: true);
    return $piano->refresh();
}

// ---------------------------------------------------------------------------
// 1. LA SOGLIA — il piano generato ma non emesso non deve bloccare niente
// ---------------------------------------------------------------------------

test('un piano solo generato non blocca la modifica dei saldi che ha assorbito', function () {
    $s = scenarioSoglia();
    $user = utenteAdmin();
    generaPiano($s);

    // Nessuna rata emessa, nessun incasso: il piano è ancora interamente riscrivibile
    // (lo dimostra il fatto che «Ricalcola» lo cancella e rigenera senza vincoli).
    $this->actingAs($user)
        ->patch(route('admin.gestionale.saldi.update', [$s->condominio->id, $s->saldo->id]), [
            'saldo_iniziale' => 25000,
            'gestione_id'    => $s->gestione->id,
        ])
        ->assertSessionHasNoErrors();

    expect($s->saldo->fresh()->saldo_iniziale)->toBe(25000);
});

test('un piano con rate emesse blocca la modifica dei saldi che ha assorbito', function () {
    $s = scenarioSoglia();
    $user = utenteAdmin();
    $piano = generaPiano($s);

    // Simula l'emissione: una quota con scrittura contabile collegata.
    $quota = $piano->rate()->with('rateQuote')->get()->flatMap->rateQuote->first();
    $scrittura = \App\Models\Gestionale\ScritturaContabile::create([
        'condominio_id'      => $s->condominio->id,
        'esercizio_id'       => $s->esercizio->id,
        'gestione_id'        => $s->gestione->id,
        'data_registrazione' => '2026-03-10',
        'data_competenza'    => '2026-03-10',
        'causale'            => 'Emissione rata di test',
        'tipo_movimento'     => 'emissione_rata',
        'stato'              => 'registrata',
    ]);
    $quota->update(['scrittura_contabile_id' => $scrittura->id]);

    $this->actingAs($user)
        ->patch(route('admin.gestionale.saldi.update', [$s->condominio->id, $s->saldo->id]), [
            'saldo_iniziale' => 25000,
            'gestione_id'    => $s->gestione->id,
        ])
        ->assertSessionHasErrors();

    expect($s->saldo->fresh()->saldo_iniziale)->toBe(30000);
});

// ---------------------------------------------------------------------------
// 2. LA GRANULARITÀ — un saldo libero non deve subire il lucchetto di un altro
// ---------------------------------------------------------------------------

test('un saldo libero resta eliminabile anche se un altro saldo della stessa gestione è bloccato', function () {
    $s = scenarioSoglia();
    $user = utenteAdmin();
    generaPiano($s);

    // Rettifica in itinere: un subentro scoperto dopo, sulla stessa gestione.
    $altra = Anagrafica::create([
        'condominio_id' => $s->condominio->id, 'nome' => 'Luisa', 'cognome' => 'Bianchi',
        'email' => 'luisa.soglia@test.it', 'indirizzo' => 'Via Verdi 12',
        'cap' => '00100', 'citta' => 'Roma', 'provincia' => 'RM', 'codice_fiscale' => 'BNCLSU85A41H501T',
    ]);
    $libero = Saldo::create([
        'esercizio_id' => $s->esercizio->id, 'condominio_id' => $s->condominio->id,
        'anagrafica_id' => $altra->id, 'immobile_id' => $s->immobile->id,
        'gestione_id' => $s->gestione->id, 'saldo_iniziale' => 12000,
        'origine' => 'manuale', 'is_applicato' => false,
    ]);

    expect($s->saldo->fresh()->is_applicato)->toBeTrue()
        ->and($libero->fresh()->is_applicato)->toBeFalse();

    $this->actingAs($user)
        ->delete(route('admin.gestionale.saldi.destroy', [$s->condominio->id, $libero->id]))
        ->assertSessionHasNoErrors();

    expect(Saldo::find($libero->id))->toBeNull()
        // il saldo assorbito dal piano resta protetto
        ->and(Saldo::find($s->saldo->id))->not->toBeNull();
});

// ---------------------------------------------------------------------------
// 3. LO SBLOCCO MANUALE — per i lucchetti che nessun piano rivendica
// ---------------------------------------------------------------------------

test('un lucchetto senza titolare si può riaprire a mano', function () {
    $s = scenarioSoglia();
    $user = utenteAdmin();

    // Stato tipico dei dati anteriori alla beta.32, e del caso ambiguo che la
    // migrazione di riparazione lascia chiuso di proposito.
    $s->saldo->update(['is_applicato' => true, 'piano_rate_id' => null]);
    $s->gestione->update(['saldo_applicato' => true]);

    $this->actingAs($user)
        ->post(route('admin.gestionale.saldi.sblocca', [$s->condominio->id, $s->saldo->id]))
        ->assertSessionHasNoErrors();

    expect($s->saldo->fresh()->is_applicato)->toBeFalse()
        // il flag di gestione è un derivato: deve seguire
        ->and($s->gestione->fresh()->saldo_applicato)->toBeFalse();
});

test('lo sblocco manuale non tocca i saldi intestati a un piano', function () {
    $s = scenarioSoglia();
    $user = utenteAdmin();
    $piano = generaPiano($s);

    $this->actingAs($user)
        ->post(route('admin.gestionale.saldi.sblocca', [$s->condominio->id, $s->saldo->id]))
        ->assertForbidden();

    expect($s->saldo->fresh()->is_applicato)->toBeTrue()
        ->and($s->saldo->fresh()->piano_rate_id)->toBe($piano->id);
});

test('lo sblocco manuale non tocca i debiti verso fornitori', function () {
    $s = scenarioSoglia();
    $user = utenteAdmin();

    $fornitore = \App\Models\Fornitore::create([
        'ragione_sociale' => 'Idraulica Neri SRL', 'partita_iva' => '55566677788',
        'indirizzo' => 'Via Tubi 9', 'cap' => '20100', 'comune' => 'Milano', 'provincia' => 'MI',
    ]);
    $debito = Saldo::create([
        'esercizio_id' => $s->esercizio->id, 'condominio_id' => $s->condominio->id,
        'gestione_id' => $s->gestione->id, 'fornitore_id' => $fornitore->id,
        'anagrafica_id' => null, 'immobile_id' => null,
        'saldo_iniziale' => -70000, 'origine' => 'manuale', 'is_applicato' => true,
    ]);

    $this->actingAs($user)
        ->post(route('admin.gestionale.saldi.sblocca', [$s->condominio->id, $debito->id]))
        ->assertForbidden();

    expect($debito->fresh()->is_applicato)->toBeTrue();
});

// ---------------------------------------------------------------------------
// 4. IL SILENZIO — un capitolo senza tabella millesimale non deve sparire
// ---------------------------------------------------------------------------

test('un capitolo senza tabella millesimale diventa uno scoperto, non sparisce', function () {
    $s = scenarioSoglia();

    // Secondo capitolo, con un importo ma senza alcuna tabella collegata:
    // oggi il motore logga un warning e non distribuisce, in silenzio.
    Conto::forceCreate([
        'piano_conto_id' => $s->pianoConti->id, 'nome' => 'Spese Scala B',
        'importo' => 50000, 'tipo' => 'spesa', 'attivo' => true,
    ]);

    $piano = PianoRate::create([
        'condominio_id' => $s->condominio->id, 'gestione_id' => $s->gestione->id,
        'nome' => 'Piano con capitolo orfano', 'numero_rate' => 1,
        'metodo_distribuzione' => 'rata_zero', 'stato' => 'bozza', 'tipo' => 'ordinario',
    ]);

    // La generazione deve fermarsi e dichiarare l'importo non assegnato,
    // esattamente come già fa per le unità senza anagrafica attiva.
    expect(fn () => app(GeneratePianoRateAction::class)->execute(
        pianoRate: $piano,
        forzaApplicazioneSaldi: false
    ))->toThrow(\App\Exceptions\Gestionale\ScopertiNonAccettatiException::class);
});
