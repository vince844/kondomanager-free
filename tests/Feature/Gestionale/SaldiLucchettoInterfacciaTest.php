<?php

/**
 * beta.44 — Il lucchetto dei saldi visto da fuori.
 *
 * La beta.33 ha spostato la soglia del blocco da «esiste un piano» a «il piano è **emesso** o
 * incassato», e l'ha fatto **solo lato server**. `Saldo::eBloccato()` è l'autorità: se il saldo
 * ha un piano risponde `PianoRate::eImmutabile()`, cioè rate emesse oppure incassi registrati.
 *
 * Quel metodo però non arriva mai a Vue. `SaldoInizialeController::index()` serializza il model
 * grezzo e `Saldo` non ha `$appends`, quindi l'interfaccia decide sul booleano nudo
 * `is_applicato` — che `SaldoEsercizioService:114` accende alla **generazione** del piano,
 * insieme a `piano_rate_id`. Risultato: fra generazione ed emissione il server accetta la
 * correzione di un saldo e il pannello mostra il lucchetto.
 *
 * ## Perché è la stessa segnalazione della beta.32, ancora aperta
 *
 * La modale del lucchetto consiglia: *«annulla le emissioni: il saldo torna modificabile senza
 * bisogno di eliminare il piano»*. `EmissioneRateController::destroy` azzera davvero
 * `scrittura_contabile_id`, quindi da quel momento `eImmutabile()` è falso — ma **nessuno
 * rimette `is_applicato` a false**, e l'interfaccia guarda quello. L'amministratore segue
 * un'istruzione stampata dentro l'applicazione, non ottiene niente, e l'unica strada che gli
 * resta è cancellare il piano: il percorso esatto della segnalazione per cui la beta.32 è nata.
 *
 * Questi test guardano il **payload**, che è il confine dove il difetto vive: il motore era già
 * giusto e coperto da 25 test, l'interfaccia non lo sapeva.
 */

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Saldo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function utenteLucchetto(): \App\Models\User
{
    Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $user = \App\Models\User::factory()->create();
    $user->givePermissionTo('Accesso pannello amministratore');

    return $user;
}

/**
 * Un saldo assorbito da un piano, nello stato indicato.
 *
 * `$stato` vale 'generato' (il piano esiste, nessuna quota emessa), 'emesso' (una quota ha la
 * sua scrittura contabile) o 'incassato' (una quota ha `importo_pagato > 0`).
 */
function scenarioLucchetto(string $stato = 'generato', bool $conPiano = true): object
{
    static $seq = 0;
    $seq++;

    $condominio = Condominio::create([
        'nome' => "Condominio Lucchetto {$seq}", 'uuid' => (string) Str::uuid(),
        'indirizzo' => 'Via Roma 1', 'citta' => 'Milano', 'cap' => '20100', 'provincia' => 'MI',
    ]);

    $esercizio = Esercizio::create([
        'condominio_id' => $condominio->id, 'nome' => '2026',
        'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto',
    ]);

    $gestione = Gestione::create([
        'condominio_id' => $condominio->id, 'nome' => "Ordinaria {$seq}",
        'data_inizio' => '2026-01-01', 'tipo' => 'ordinaria', 'saldo_applicato' => true,
    ]);
    $gestione->esercizi()->attach($esercizio->id, ['attiva' => true]);

    $immobile = Immobile::forceCreate([
        'condominio_id' => $condominio->id, 'nome' => "Int {$seq}",
        'descrizione' => 'Appartamento', 'interno' => (string) $seq,
    ]);

    $anagrafica = Anagrafica::forceCreate([
        'nome' => "Titolare {$seq}", 'email' => "lucchetto{$seq}@test.it",
        'indirizzo' => 'Via Verdi 1',
        'codice_fiscale' => 'LCCTST'.str_pad((string) $seq, 10, '0', STR_PAD_LEFT),
    ]);

    DB::table('anagrafica_immobile')->insert([
        'anagrafica_id' => $anagrafica->id, 'immobile_id' => $immobile->id,
        'tipologia' => 'proprietario', 'quota' => 100.0, 'attivo' => true,
        'data_inizio' => '2026-01-01',
    ]);

    $piano = null;
    if ($conPiano) {
        $piano = PianoRate::create([
            'condominio_id' => $condominio->id, 'gestione_id' => $gestione->id,
            'nome' => "Piano {$seq}", 'numero_rate' => 1,
            'metodo_distribuzione' => 'rata_zero', 'stato' => 'bozza', 'tipo' => 'ordinario',
        ]);

        $rata = Rata::create([
            'piano_rate_id' => $piano->id, 'numero_rata' => 0,
            'data_scadenza' => '2026-01-31', 'descrizione' => 'Saldo Pregresso',
            'importo_totale' => 50000, 'stato' => 'bozza',
        ]);

        $scritturaId = null;
        if ($stato === 'emesso') {
            $scritturaId = DB::table('scritture_contabili')->insertGetId([
                'condominio_id' => $condominio->id, 'esercizio_id' => $esercizio->id,
                'tipo_movimento' => 'emissione_rata', 'stato' => 'registrata',
                'data_registrazione' => '2026-01-31', 'data_competenza' => '2026-01-31',
                'numero_protocollo' => "EMI-{$seq}", 'causale' => 'Emissione rate',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('rate_quote')->insert([
            'rata_id' => $rata->id, 'anagrafica_id' => $anagrafica->id,
            'immobile_id' => $immobile->id, 'importo' => 50000,
            'importo_pagato' => $stato === 'incassato' ? 50000 : 0,
            'scrittura_contabile_id' => $scritturaId,
            'stato' => 'da_pagare', 'tipo' => 'saldo_iniziale',
            'data_scadenza' => '2026-01-31',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // Lo stato che `SaldoEsercizioService:114` lascia quando il piano assorbe i pregressi:
    // `is_applicato` acceso alla GENERAZIONE, non all'emissione.
    $saldo = Saldo::forceCreate([
        'condominio_id' => $condominio->id, 'esercizio_id' => $esercizio->id,
        'gestione_id' => $gestione->id, 'immobile_id' => $immobile->id,
        'anagrafica_id' => $anagrafica->id, 'saldo_iniziale' => 50000,
        'descrizione' => 'Pregresso', 'is_applicato' => true,
        'piano_rate_id' => $piano?->id,
    ]);

    return (object) compact('condominio', 'esercizio', 'gestione', 'immobile', 'saldo', 'piano');
}

/** Il saldo come arriva al pannello Vue. */
function saldoNelPayload(object $s): array
{
    $risposta = test()->actingAs(utenteLucchetto())
        ->get(route('admin.gestionale.saldi.index', $s->condominio->id));

    $risposta->assertOk();

    $immobili = $risposta->viewData('page')['props']['immobili'];

    return collect($immobili)->pluck('saldi')->flatten(1)
        ->firstWhere('id', $s->saldo->id);
}

test('un piano generato ma non emesso lascia il saldo libero anche nel payload', function () {
    // È il caso al centro della voce: il server accetta già la correzione, l'interfaccia no.
    $s = scenarioLucchetto('generato');

    $saldo = saldoNelPayload($s);

    expect($saldo['e_bloccato'])->toBeFalse()
        // Il flag grezzo resta acceso: è quello che `SaldoEsercizioService` scrive alla
        // generazione, e non è lui l'autorità. Il test lo fissa per evitare che qualcuno
        // "aggiusti" il sintomo spegnendolo, rompendo il gate dei saldi liberi.
        ->and($saldo['is_applicato'])->toBeTruthy();
});

test('un piano con rate emesse blocca il saldo', function () {
    $s = scenarioLucchetto('emesso');

    expect(saldoNelPayload($s)['e_bloccato'])->toBeTrue();
});

test('un piano con incassi registrati blocca il saldo', function () {
    $s = scenarioLucchetto('incassato');

    expect(saldoNelPayload($s)['e_bloccato'])->toBeTrue();
});

test('un saldo bloccato senza piano resta bloccato: è il lucchetto orfano', function () {
    // Debiti verso fornitori e dati anteriori alla beta.32: `is_applicato` acceso e nessun
    // piano a cui risalire. Per quelli il ripiego sul flag grezzo è corretto, e si sbloccano
    // a mano — non vanno liberati per errore da questa correzione.
    $s = scenarioLucchetto(conPiano: false);

    expect(saldoNelPayload($s)['e_bloccato'])->toBeTrue();
});

test('il payload dice quale piano tiene il lucchetto', function () {
    // Serviva già alla UI per non mostrare un'icona muta, ed è la metà informativa della
    // beta.32 che senza il flag calcolato non poteva essere usata fino in fondo.
    $s = scenarioLucchetto('emesso');

    $saldo = saldoNelPayload($s);

    expect($saldo['piano_rate']['nome'])->toBe($s->piano->nome);
});
