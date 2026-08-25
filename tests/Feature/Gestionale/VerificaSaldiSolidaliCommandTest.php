<?php

/**
 * beta.43 — Il comando che dice a chi ha già emesso se è toccato.
 *
 * La correzione del riparto solidale vale per i piani futuri: le quote già generate sono
 * **snapshot a database** e nessun ricalcolo le tocca. Senza questo comando l'unica scelta
 * per un amministratore sarebbe ricontrollare tutto a mano o fidarsi.
 *
 * È in **sola lettura**, e non è una timidezza: correggere significherebbe rigenerare le
 * quote di un piano già emesso, cioè toccare scritture contabili. La strada esiste già nel
 * prodotto — annulla le emissioni, ricalcola, riemetti — ed è una decisione di chi firma il
 * bilancio, non di un comando.
 *
 * I test scrivono le quote **nella forma lasciata dal codice vecchio**, che è l'unico modo di
 * esercitare un difetto già corretto: generare un piano oggi produrrebbe il riparto giusto.
 */

use App\Models\Anagrafica;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

/**
 * Una quota come la scriveva il generatore prima della beta.43: riparto automatico di un
 * saldo solidale, senza `ruolo_risolto` nel meta perché quel campo non esisteva.
 */
function quotaLegacySolidale(int $rataId, int $anagraficaId, int $immobileId, int $importo, int $saldoId, int $originale): void
{
    DB::table('rate_quote')->insert([
        'rata_id' => $rataId,
        'anagrafica_id' => $anagraficaId,
        'immobile_id' => $immobileId,
        'importo' => $importo,
        'importo_pagato' => 0,
        'stato' => 'da_pagare',
        'tipo' => 'saldo_iniziale',
        'regole_calcolo' => json_encode([
            'dettagli_saldo' => [[
                'saldo_origine_id' => $saldoId,
                'tipo_riparto' => 'solidale_automatico',
                'importo_originale' => $originale,
                'quota_applicata' => 100.0,
            ]],
        ]),
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** Condominio con un piano emesso e un'unità abitata da un proprietario e da un inquilino. */
function scenarioDiagnosi(string $tipoGestione = 'ordinaria'): object
{
    [$condominio, $esercizio, $gestione, , , , $immobileId] = setupContabile();

    DB::table('gestioni')->where('id', $gestione->id)->update(['tipo' => $tipoGestione]);

    $persone = [];
    foreach (['proprietario', 'inquilino'] as $i => $ruolo) {
        $persone[$ruolo] = Anagrafica::forceCreate([
            'nome' => ucfirst($ruolo).' Test',
            'email' => "diagnosi{$i}@test.it",
            'indirizzo' => 'Via Roma 1',
            'codice_fiscale' => 'DGNTST'.str_pad((string) $i, 10, '0', STR_PAD_LEFT),
        ]);

        DB::table('anagrafica_immobile')->insert([
            'anagrafica_id' => $persone[$ruolo]->id,
            'immobile_id' => $immobileId,
            'tipologia' => $ruolo,
            'quota' => 100.0,
            'attivo' => true,
            'data_inizio' => '2026-01-01',
        ]);
    }

    $piano = PianoRate::create([
        'condominio_id' => $condominio->id, 'gestione_id' => $gestione->id,
        'nome' => 'Piano con riparto vecchio', 'numero_rate' => 1,
        'metodo_distribuzione' => 'rata_zero', 'stato' => 'bozza', 'tipo' => 'ordinario',
    ]);

    $rata = Rata::create([
        'piano_rate_id' => $piano->id, 'numero_rata' => 0,
        'data_scadenza' => '2026-01-31', 'descrizione' => 'Saldo Pregresso',
        'importo_totale' => 100000, 'stato' => 'bozza',
    ]);

    return (object) compact('condominio', 'esercizio', 'gestione', 'immobileId', 'persone', 'piano', 'rata');
}

/**
 * L'output completo del comando, come stringa unica.
 *
 * Non si usa il concatenamento di `expectsOutputToContain`: quelle asserzioni si consumano
 * **una riga per volta e in ordine**, quindi verificare due frasi che stanno sulla stessa
 * riga — l'etichetta nella tabella e la sua legenda — fallisce anche quando l'output è
 * giusto. Un solo `toContain` sul testo intero dice quello che si intende.
 */
function outputDiagnosi(object $s): string
{
    \Illuminate\Support\Facades\Artisan::call('kondomanager:verifica-saldi-solidali', [
        '--condominio' => $s->condominio->id,
    ]);

    return \Illuminate\Support\Facades\Artisan::output();
}

test('segnala l\'inquilino che si è visto addebitare il pregresso dell\'unità', function () {
    $s = scenarioDiagnosi();

    // Il riparto sbagliato: 1.000,00 € spaccati a metà fra proprietario e inquilino.
    quotaLegacySolidale($s->rata->id, $s->persone['proprietario']->id, $s->immobileId, 50000, 7, 100000);
    quotaLegacySolidale($s->rata->id, $s->persone['inquilino']->id, $s->immobileId, 50000, 7, 100000);

    // L'etichetta nella cella è corta perché una tabella a terminale non regge una frase;
    // la spiegazione per esteso è nella legenda, e qui si verificano entrambe.
    expect(outputDiagnosi($s))
        ->toContain('Inquilino Test')
        ->toContain('inquilino non debitore')
        ->toContain('rapporto col locatore');
});

test('non segnala un riparto finito tutto sul proprietario', function () {
    // Controprova: un comando che segnala anche i casi giusti è un comando che si impara a
    // ignorare. Qui il pregresso è tutto del proprietario, com'è corretto.
    $s = scenarioDiagnosi();

    quotaLegacySolidale($s->rata->id, $s->persone['proprietario']->id, $s->immobileId, 100000, 7, 100000);

    expect(outputDiagnosi($s))->toContain('nessun riparto solidale da ricontrollare');
});

test('segnala anche il riparto che non torna al saldo di partenza', function () {
    // Il secondo difetto della stessa funzione: ogni quota usciva da un `round()`
    // indipendente, senza redistribuzione del resto, quindi la somma poteva non coincidere.
    $s = scenarioDiagnosi();

    quotaLegacySolidale($s->rata->id, $s->persone['proprietario']->id, $s->immobileId, 33334, 9, 100001);
    quotaLegacySolidale($s->rata->id, $s->persone['proprietario']->id, $s->immobileId, 33334, 9, 100001);
    quotaLegacySolidale($s->rata->id, $s->persone['proprietario']->id, $s->immobileId, 33334, 9, 100001);

    expect(outputDiagnosi($s))->toContain('non torna al saldo di partenza');
});

test('su una gestione straordinaria segnala l\'usufruttuario', function () {
    // Art. 1005 c.c.: le riparazioni straordinarie restano del proprietario. Il riparto
    // vecchio non guardava la natura della gestione, quindi questo caso esiste nei dati.
    $s = scenarioDiagnosi('straordinaria');

    $usufruttuario = Anagrafica::forceCreate([
        'nome' => 'Usufruttuario Test', 'email' => 'usufrutto.diagnosi@test.it',
        'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'USFDGN0000000001',
    ]);
    DB::table('anagrafica_immobile')->insert([
        'anagrafica_id' => $usufruttuario->id, 'immobile_id' => $s->immobileId,
        'tipologia' => 'usufruttuario', 'quota' => 100.0, 'attivo' => true,
        'data_inizio' => '2026-01-01',
    ]);

    quotaLegacySolidale($s->rata->id, $usufruttuario->id, $s->immobileId, 100000, 11, 100000);

    expect(outputDiagnosi($s))->toContain('art. 1005');
});

test('non modifica nulla', function () {
    // È la promessa che il comando fa nel suo output, e va verificata: se un giorno qualcuno
    // ci aggiungesse una riparazione, questo test si accende.
    $s = scenarioDiagnosi();

    quotaLegacySolidale($s->rata->id, $s->persone['inquilino']->id, $s->immobileId, 50000, 7, 100000);

    $prima = DB::table('rate_quote')->orderBy('id')->get()->toJson();

    outputDiagnosi($s);

    expect(DB::table('rate_quote')->orderBy('id')->get()->toJson())->toBe($prima);
});
