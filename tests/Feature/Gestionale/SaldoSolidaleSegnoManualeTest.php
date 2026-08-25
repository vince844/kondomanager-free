<?php

/**
 * beta.43 — Un credito ripartito a mano resta un credito.
 *
 * Il riparto manuale di un saldo solidale (il ramo B1 di `GenerateSaldiAction`) è la porta
 * che l'amministratore usa quando il default di legge non è la sua situazione: un patto fra
 * usufruttuario e nudo proprietario, un accollo scritto nel rogito. La modale la offre anche
 * sui saldi **a credito** — ed è giusto, un credito dell'unità si divide come un debito.
 *
 * Ma il segno si perdeva per strada, lungo una catena in cui nessun anello sbagliava da solo:
 *
 * 1. `PianiRateNew.vue:122` monta la casella con `disableNegative: true`, e mostra l'importo
 *    da distribuire in **valore assoluto** (`:1287`). Corretto: il meno lo porta il saldo, non
 *    lo digita l'utente.
 * 2. `CreatePianoRateRequest:81` valida l'importo come `'string'`, senza vincoli di segno.
 * 3. `PianoRateController` converte con `MoneyHelper::toCents()`, che di una stringa positiva
 *    fa un intero positivo.
 * 4. `GenerateSaldiAction` lo usava così com'era.
 *
 * Risultato: un credito di 600,00 € diviso fra due comproprietari usciva come **due debiti da
 * 300,00 €**. Non un importo storto — il **verso opposto**: a chi il condominio doveva dei
 * soldi ne venivano chiesti altrettanti, con uno scarto di 1.200,00 € sulla sua posizione.
 *
 * Il segno lo riapplica ora il server, dalla fonte. È l'unico posto che lo sa con certezza,
 * e l'unico che non dipende da cosa l'utente ha digitato.
 */

use App\Actions\PianoRate\GenerateSaldiAction;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Saldo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** Un'unità con due comproprietari e un saldo solidale dell'importo indicato (segno incluso). */
function scenarioRipartoManuale(int $saldoCents): object
{
    static $seq = 0;
    $seq++;

    $condominio = Condominio::create([
        'nome' => "Condominio Manuale {$seq}", 'uuid' => (string) Str::uuid(),
        'indirizzo' => 'Via Roma 1', 'citta' => 'Milano', 'cap' => '20100', 'provincia' => 'MI',
    ]);

    $esercizio = Esercizio::create([
        'condominio_id' => $condominio->id, 'nome' => '2026',
        'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto',
    ]);

    $gestione = Gestione::create([
        'condominio_id' => $condominio->id, 'nome' => "Ordinaria {$seq}",
        'data_inizio' => '2026-01-01', 'tipo' => 'ordinaria', 'saldo_applicato' => false,
    ]);
    $gestione->esercizi()->attach($esercizio->id, ['attiva' => true]);

    $immobile = Immobile::forceCreate([
        'condominio_id' => $condominio->id, 'nome' => "Int {$seq}",
        'descrizione' => 'Appartamento', 'interno' => (string) $seq,
    ]);

    $persone = [];
    foreach (['primo', 'secondo'] as $ruolo) {
        $seq++;
        $persone[$ruolo] = Anagrafica::forceCreate([
            'nome' => "Comproprietario {$seq}",
            'email' => "manuale{$seq}@test.it",
            'indirizzo' => 'Via Verdi 1',
            'codice_fiscale' => 'MNLTST' . str_pad((string) $seq, 10, '0', STR_PAD_LEFT),
        ]);

        DB::table('anagrafica_immobile')->insert([
            'anagrafica_id' => $persone[$ruolo]->id,
            'immobile_id' => $immobile->id,
            'tipologia' => 'proprietario',
            'quota' => 50.0,
            'attivo' => true,
            'data_inizio' => '2026-01-01',
        ]);
    }

    $saldo = Saldo::forceCreate([
        'condominio_id' => $condominio->id, 'esercizio_id' => $esercizio->id,
        'gestione_id' => $gestione->id, 'immobile_id' => $immobile->id,
        'anagrafica_id' => null, 'saldo_iniziale' => $saldoCents,
        'descrizione' => 'Pregresso solidale', 'is_applicato' => false,
    ]);

    $piano = PianoRate::create([
        'condominio_id' => $condominio->id, 'gestione_id' => $gestione->id,
        'nome' => "Piano {$seq}", 'numero_rate' => 2, 'metodo_distribuzione' => 'rata_zero',
        'stato' => 'bozza', 'tipo' => 'ordinario',
    ]);

    return (object) compact('condominio', 'gestione', 'immobile', 'saldo', 'piano', 'persone');
}

/**
 * Il riparto come arriva dal controller: importi **positivi** in centesimi, perché la casella
 * del form vieta il segno meno e mostra il totale in valore assoluto.
 */
function ripartoManuale(object $s, int $primoCents, int $secondoCents): array
{
    return app(GenerateSaldiAction::class)->execute($s->piano, $s->gestione, [[
        'saldo_id' => $s->saldo->id,
        'ripartizioni' => [
            ['anagrafica_id' => $s->persone['primo']->id, 'importo' => $primoCents],
            ['anagrafica_id' => $s->persone['secondo']->id, 'importo' => $secondoCents],
        ],
    ]]);
}

test('un saldo a CREDITO ripartito a mano resta un credito per entrambi', function () {
    // 600,00 € di credito dell'unità, divisi a metà. Prima uscivano due DEBITI da 300,00 €:
    // il verso opposto, con 1.200,00 € di scarto sulla posizione dei due comproprietari.
    $s = scenarioRipartoManuale(-60000);

    $d = ripartoManuale($s, 30000, 30000);

    expect($d[$s->persone['primo']->id][$s->immobile->id]['importo'])->toBe(-30000)
        ->and($d[$s->persone['secondo']->id][$s->immobile->id]['importo'])->toBe(-30000);
});

test('la somma ripartita a mano torna al saldo di partenza, segno compreso', function () {
    $s = scenarioRipartoManuale(-60000);

    $d = ripartoManuale($s, 45000, 15000);

    $somma = $d[$s->persone['primo']->id][$s->immobile->id]['importo']
        + $d[$s->persone['secondo']->id][$s->immobile->id]['importo'];

    expect($somma)->toBe(-60000);
});

test('un saldo a DEBITO ripartito a mano non cambia comportamento', function () {
    // Controprova: è il caso che funzionava, e deve continuare a funzionare identico.
    // Senza questa, «riapplica il segno della fonte» potrebbe averlo ribaltato dall'altra parte.
    $s = scenarioRipartoManuale(60000);

    $d = ripartoManuale($s, 45000, 15000);

    expect($d[$s->persone['primo']->id][$s->immobile->id]['importo'])->toBe(45000)
        ->and($d[$s->persone['secondo']->id][$s->immobile->id]['importo'])->toBe(15000);
});

test('il segno lo decide la fonte, non quello che è stato digitato', function () {
    // Se un client scrivesse importi già negativi su un saldo a credito, il risultato non
    // deve diventare positivo per doppia negazione: comanda `saldi.saldo_iniziale`.
    $s = scenarioRipartoManuale(-40000);

    $d = ripartoManuale($s, -20000, -20000);

    expect($d[$s->persone['primo']->id][$s->immobile->id]['importo'])->toBe(-20000)
        ->and($d[$s->persone['secondo']->id][$s->immobile->id]['importo'])->toBe(-20000);
});

/**
 * La somma delle quote manuali deve fare il saldo. Non è pignoleria contabile: siccome
 * `piano_rate_id` viene scritto sull'**intero** saldo, la parte non distribuita resta bloccata
 * e non addebitata a nessuno — il quarto modo di far sparire un pregresso in silenzio, dopo i
 * tre che questa beta ha chiuso.
 *
 * L'avviso in giallo esisteva già in `PianiRateNew.vue:1303`, ma non legava: un avviso che si
 * può ignorare è la stessa forma dell'enum dichiarato e mai applicato della beta.41.
 *
 * Il controllo sta nella `CreatePianoRateRequest` perché il riparto manuale entra **solo** di
 * lì — alla creazione del piano — e perché è il punto in cui l'amministratore è ancora davanti
 * al modulo con i suoi numeri sotto gli occhi.
 */
function validaRiparto(object $s, array $importi): \Illuminate\Contracts\Validation\Validator
{
    $ripartizioni = [];
    foreach ([$s->persone['primo'], $s->persone['secondo']] as $i => $persona) {
        $ripartizioni[] = ['anagrafica_id' => $persona->id, 'importo' => $importi[$i]];
    }

    // Gli importi arrivano qui come **stringhe mascherate** dal form ("1.200,50"): la
    // conversione in centesimi è a valle, nel controller. La regola deve saperle leggere.
    return \Illuminate\Support\Facades\Validator::make(
        ['saldi_config' => [['saldo_id' => $s->saldo->id, 'ripartizioni' => $ripartizioni]]],
        ['saldi_config' => (new \App\Http\Requests\Gestionale\PianoRate\CreatePianoRateRequest())->rules()['saldi_config']]
    );
}

test('un riparto manuale che non somma al saldo viene rifiutato', function () {
    // Saldo 600,00 €, distribuiti 500,00: i 100,00 mancanti resterebbero bloccati sul saldo
    // e non addebitati a nessuno.
    $s = scenarioRipartoManuale(60000);

    $validator = validaRiparto($s, ['300,00', '200,00']);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('saldi_config'))
        ->toContain('100,00');
});

test('un riparto manuale che somma al saldo passa', function () {
    $s = scenarioRipartoManuale(60000);

    expect(validaRiparto($s, ['300,00', '300,00'])->fails())->toBeFalse();
});

test('su un saldo a credito il confronto è in valore assoluto', function () {
    // La casella vieta il meno e la modale mostra il totale in valore assoluto: l'utente
    // digita 300 e 300 su un credito di 600, e deve andare bene.
    $s = scenarioRipartoManuale(-60000);

    expect(validaRiparto($s, ['300,00', '300,00'])->fails())->toBeFalse();
});

test('nemmeno un centesimo di scarto passa', function () {
    // Il denaro è in centesimi interi: una tolleranza qui sarebbe un centesimo che sparisce
    // a ogni piano, ed è esattamente il genere di cosa che nessuno ritrova più.
    $s = scenarioRipartoManuale(60000);

    expect(validaRiparto($s, ['300,00', '299,99'])->fails())->toBeTrue();
});

test('il meta dichiara che il riparto è manuale', function () {
    // Serve a distinguerlo dall'automatico quando si guarda com'è nata una quota: il riparto
    // manuale è una decisione dell'amministratore, non un calcolo da rifare.
    $s = scenarioRipartoManuale(-40000);

    $meta = ripartoManuale($s, 20000, 20000)[$s->persone['primo']->id][$s->immobile->id]['meta_storico'][0];

    expect($meta['tipo_riparto'])->toBe('solidale_manuale')
        ->and($meta['importo_originale'])->toBe(-40000);
});
