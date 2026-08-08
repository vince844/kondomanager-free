<?php

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Immobile;
use App\Models\ImportBatch;
use App\Models\QuotaTabella;
use App\Models\Tabella;
use App\Services\Import\Canonical\CanonicalSoggetto;
use App\Services\Import\Canonical\CanonicalTabella;
use App\Services\Import\Canonical\CanonicalTitolarita;
use App\Enums\RuoloAnagraficaImmobile as ImportRuolo;
use App\Services\Import\ImportContext;
use App\Services\Import\Livelli\LivelloCondominio;
use App\Services\Import\Livelli\LivelloEsercizi;
use App\Services\Import\Livelli\LivelloSoggetti;
use App\Services\Import\Livelli\LivelloTabelle;
use App\Services\Import\Livelli\LivelloTitolarita;
use App\Services\Import\Livelli\LivelloUnita;
use Database\Seeders\TipologieImmobiliSeeder;
use Illuminate\Support\Facades\DB;

/**
 * «Ogni livello è una transazione» (`ImportRunner`, §2 del suo docblock): se un livello si
 * blocca, non deve restare scritto **niente** di quel livello — non «quasi niente», non «tutto
 * tranne l'ultima riga».
 *
 * Trovato dalla revisione avversariale: tre livelli (Soggetti, Titolarità, Tabelle) scrivevano
 * dentro lo stesso ciclo in cui rilevavano la condizione che blocca, e restituivano l'esito
 * bloccato come **valore di ritorno normale** — non un'eccezione. La transazione che
 * `ImportRunner` mette attorno a `commit()` fa rollback solo su un'eccezione lanciata: un
 * `EsitoCommit::bloccato(...)` restituito normalmente la lascia fare regolarmente commit. Le
 * righe già scritte restavano in archivio con il livello segnato «non riuscito» — e al
 * tentativo successivo, quelle stesse righe risultavano «già presenti», producendo decisioni
 * che nessuno aveva mai chiesto.
 *
 * ## Cosa questi test NON coprono
 *
 * - Il comportamento «giusto» quando il livello **passa**: quello lo coprono i test end-to-end
 *   di ciascun livello (ImportTitolaritaTest, ImportTabelleMillesimaliTest, ecc.).
 * - LivelloSaldi: già scriveva con lo schema corretto (due giri, scrive solo dopo aver
 *   verificato che non ci sono rilievi bloccanti) prima di questa revisione.
 */
beforeEach(fn () => test()->seed(TipologieImmobiliSeeder::class));

function contestoAtomicita(): ImportContext
{
    return new ImportContext(ImportBatch::create(['sorgente' => 'danea']));
}

it('LivelloSoggetti non scrive nessuna persona se una riga successiva richiede una decisione', function () {
    // Un'anagrafica già in archivio: farà da "duplicato" per l'ultima delle venti righe.
    Anagrafica::create([
        'nome' => 'GIA PRESENTE',
        'codice_fiscale' => 'RSSMRA50A41L100X',
        'indirizzo' => 'Via Antica 1',
    ]);

    $soggetti = [];
    for ($i = 1; $i <= 19; $i++) {
        $soggetti['nuovo:'.$i] = new CanonicalSoggetto(nome: 'NUOVO '.$i, indirizzo: 'Via Nuova '.$i);
    }
    // Nessuna decisione fornita per questo: il livello deve fermarsi qui.
    $soggetti['dup'] = new CanonicalSoggetto(
        nome: 'GIA PRESENTE OMONIMO',
        codiceFiscale: 'RSSMRA50A41L100X',
        indirizzo: 'Via Qualsiasi 9',
    );

    $ctx = contestoAtomicita()->conCanonico(LivelloSoggetti::CHIAVE, $soggetti);

    $esito = (new LivelloSoggetti)->commit($ctx);

    expect($esito->riuscito())->toBeFalse()
        // Il punto del test: nessuna delle diciannove persone senza problemi è stata scritta.
        ->and(Anagrafica::count())->toBe(1); // solo quella creata prima del test, nessuna nuova
});

it('LivelloTitolarita non scrive nessuna titolarità se una riga successiva ha un riferimento irrisolto', function () {
    $condominio = Condominio::create(['nome' => 'Prova Atomicità', 'indirizzo' => 'Via Prova 1']);
    $immobile = Immobile::create(['condominio_id' => $condominio->id, 'nome' => 'Unità 1', 'descrizione' => 'Unità 1', 'interno' => '1']);
    $esercizio = Esercizio::create([
        'condominio_id' => $condominio->id,
        'nome' => '2024/2025',
        'data_inizio' => '2024-11-01',
        'data_fine' => '2025-10-31',
    ]);
    $anagrafica = Anagrafica::create(['nome' => 'PROVA', 'indirizzo' => 'Via Prova 1']);

    $ctx = contestoAtomicita();
    $ctx->risolvi(LivelloCondominio::CHIAVE, $condominio);
    $ctx->risolvi(LivelloEsercizi::CHIAVE, $esercizio);

    // Nove righe risolvibili verso la stessa unità (ruoli diversi per non collassare come
    // duplicati) più una decima con un riferimento a un soggetto che non esiste.
    $titolarita = [];
    foreach (range(1, 9) as $i) {
        $titolarita[] = new CanonicalTitolarita(
            immobileRef: 'unita:'.$i,
            soggettoRef: 'sog',
            ruolo: ImportRuolo::PROPRIETARIO,
        );
    }
    $titolarita[] = new CanonicalTitolarita(
        immobileRef: 'unita:1',
        soggettoRef: 'soggetto-inesistente',
        ruolo: ImportRuolo::PROPRIETARIO,
    );

    $ctx->risolviMolti(LivelloUnita::CHIAVE, array_fill_keys(
        array_map(fn ($i) => 'unita:'.$i, range(1, 9)),
        $immobile,
    ));
    $ctx->risolviMolti(LivelloSoggetti::CHIAVE, ['sog' => $anagrafica]);
    $ctx->conCanonico(LivelloTitolarita::CHIAVE, $titolarita);

    $esito = (new LivelloTitolarita)->commit($ctx);

    expect($esito->riuscito())->toBeFalse()
        ->and(DB::table('anagrafica_immobile')->count())->toBe(0);
});

it('LivelloTabelle non scrive nessuna tabella se una tabella successiva ha unità orfane', function () {
    $condominio = Condominio::create(['nome' => 'Prova Atomicità Tabelle', 'indirizzo' => 'Via Prova 1']);
    $unita = Immobile::create(['condominio_id' => $condominio->id, 'nome' => 'Unità 1', 'descrizione' => 'Unità 1', 'interno' => '1']);

    $ctx = contestoAtomicita();
    $ctx->risolvi(LivelloCondominio::CHIAVE, $condominio);
    $ctx->risolviMolti(LivelloUnita::CHIAVE, ['u1' => $unita]);

    $ctx->conCanonico(LivelloTabelle::CHIAVE, [
        // Pulita: nessun problema.
        'AMMINISTRAZIONE' => new CanonicalTabella('AMMINISTRAZIONE', ['u1' => 1000.0]),
        // Referenzia un'unità che non esiste nel contesto risolto: blocca il livello.
        'ASCENSORE' => new CanonicalTabella('ASCENSORE', ['u1' => 500.0, 'u-fantasma' => 500.0]),
    ]);

    $esito = (new LivelloTabelle)->commit($ctx);

    expect($esito->riuscito())->toBeFalse()
        // Il punto del test: neanche AMMINISTRAZIONE, che di suo era pulita, è entrata.
        ->and(Tabella::count())->toBe(0)
        ->and(QuotaTabella::count())->toBe(0);
});
