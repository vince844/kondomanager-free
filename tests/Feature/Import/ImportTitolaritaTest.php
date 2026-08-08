<?php

use App\Enums\RuoloAnagraficaImmobile;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Immobile;
use App\Models\ImportBatch;
use App\Models\Palazzina;
use App\Services\Import\ImportContext;
use App\Services\Import\ImportRunner;
use App\Services\Import\Livelli\LivelloCondominio;
use App\Services\Import\Livelli\LivelloEsercizi;
use App\Services\Import\Livelli\LivelloSoggetti;
use App\Services\Import\Livelli\LivelloTabelle;
use App\Services\Import\Livelli\LivelloTitolarita;
use App\Services\Import\Livelli\LivelloUnita;
use App\Services\Import\Parser\BannerParser;
use App\Services\Import\Parser\AnagraficaMillesimiParser;
use App\Services\Import\Parser\ElencoUnitaParser;
use App\Services\Import\ReportRecognizer;
use App\Services\Import\SpreadsheetReader;
use Database\Seeders\TipologieImmobiliSeeder;
use Illuminate\Support\Facades\DB;

// `tipologie_immobili` è una tabella di lookup che l'installer popola con `db:seed`. Senza,
// il test girerebbe su un'installazione che in produzione non esiste — e il primo effetto
// sarebbe far passare per difetto una mappatura che invece funziona.
beforeEach(fn () => test()->seed(TipologieImmobiliSeeder::class));

/**
 * Questo file copre i livelli fino alle tabelle: i **saldi di apertura** hanno il loro
 * (`ImportSaldiAperturaTest`), che è l'end-to-end completo. Elencare i livelli invece di
 * lasciar girare tutto è la stessa scelta del §5 — si importa quel che si sta importando.
 */
const LIVELLI_FINO_ALLE_TABELLE = ['condominio', 'esercizi', 'soggetti', 'unita', 'titolarita', 'tabelle'];

/**
 * L'importazione completa: due file di Danea → condominio, esercizio, persone, unità e
 * **titolarità**.
 *
 * Il bundle di tre file non è un artificio del test: è la procedura di export consigliata
 * (§19). Il riparto consuntivo porta la testata — condominio ed esercizio — e l'elenco unità
 * porta persone, unità e ruoli. Nessuno dei due da solo basta, ed è il motivo per cui il driver
 * lavora su un **insieme** di file e non su uno.
 *
 * ## Cosa questi test NON coprono
 *
 * - **I saldi di apertura**: il parser li legge e li porta nel canonico, ma il livello che li
 *   scrive nel wallet non esiste ancora. Finché non c'è, il saldo importato non ha effetti.
 * - **Le decisioni «dividi in due»** sui nomi doppi: si producono, non si eseguono.
 * - **L'annullamento** del lotto.
 * - **Il report compatto** `anagrafica_millesimi` come sorgente alternativa.
 */
function bundleDanea(): ImportContext
{
    $reader = new SpreadsheetReader;
    $riconoscitore = new ReportRecognizer;

    $riparto = $reader->leggi(base_path('tests/Fixtures/import/danea/riparto_consuntivo.xls'))[0];
    $banner = (new BannerParser)->estrai($riparto);

    $elenco = $reader->leggi(base_path('tests/Fixtures/import/danea/elenco_unita.xls'))[0];
    $letto = (new ElencoUnitaParser)->estrai($elenco, $riconoscitore->riconosci($elenco)->rigaIntestazione);

    $mill = $reader->leggi(base_path('tests/Fixtures/import/danea/millesimi_tabelle_miste.xls'))[0];
    $millesimi = (new AnagraficaMillesimiParser)->estrai($mill, $riconoscitore->riconosci($mill)->rigaIntestazione);

    // La **fusione per chiave** dei frammenti (§6): i due file parlano di unità in parte
    // diverse, e l'insieme che entra in archivio è l'unione. L'elenco unità vince sui doppioni
    // perché è il report più ricco — porta piano, tipo e dati catastali che il compatto non ha.
    $immobili = [...$millesimi['immobili'], ...$letto['immobili']];

    return (new ImportContext(ImportBatch::create(['sorgente' => 'danea'])))
        ->conCanonico(LivelloCondominio::CHIAVE, $banner['condominio'])
        ->conCanonico(LivelloEsercizi::CHIAVE, $banner['esercizio'])
        ->conCanonico(LivelloSoggetti::CHIAVE, $letto['soggetti'])
        ->conCanonico(LivelloUnita::CHIAVE, $immobili)
        ->conCanonico(LivelloTitolarita::CHIAVE, $letto['titolarita'])
        ->conCanonico(LivelloTabelle::CHIAVE, $millesimi['tabelle']);
}

it('porta due file di Danea fino a chi possiede cosa', function () {
    $ctx = bundleDanea();

    $esiti = (new ImportRunner)->esegui($ctx, [], LIVELLI_FINO_ALLE_TABELLE);

    foreach (['condominio', 'esercizi', 'soggetti', 'unita', 'titolarita', 'tabelle'] as $livello) {
        expect($esiti[$livello]->riuscito())->toBeTrue("il livello «{$livello}» non è passato");
    }

    expect(Condominio::count())->toBe(1)
        ->and(Anagrafica::count())->toBeGreaterThan(0)
        ->and(Immobile::count())->toBeGreaterThan(0)
        ->and(DB::table('anagrafica_immobile')->count())->toBeGreaterThan(0);

    expect($ctx->batch->fresh()->stato)->toBe(ImportBatch::STATO_COMPLETATO);
});

it('collega ogni unità a una persona vera, non a un\'etichetta di ruolo', function () {
    // È la differenza dal concorrente, misurata: nel suo video le unità hanno proprietario e
    // inquilino vuoti e le rate sono intestate alla parola «Proprietario». Qui ogni titolarità
    // punta a una riga di `anagrafiche` con un nome dentro.
    (new ImportRunner)->esegui(bundleDanea(), [], LIVELLI_FINO_ALLE_TABELLE);

    $righe = DB::table('anagrafica_immobile')
        ->join('anagrafiche', 'anagrafiche.id', '=', 'anagrafica_immobile.anagrafica_id')
        ->join('immobili', 'immobili.id', '=', 'anagrafica_immobile.immobile_id')
        ->select('anagrafiche.nome', 'immobili.interno', 'anagrafica_immobile.tipologia')
        ->get();

    expect($righe)->not->toBeEmpty();

    foreach ($righe as $r) {
        expect(trim($r->nome))->not->toBe('')
            ->and(trim((string) $r->interno))->not->toBe('')
            ->and(RuoloAnagraficaImmobile::tryFrom($r->tipologia))->not->toBeNull();
    }
});

it('registra proprietario, inquilino e usufruttuario con i loro ruoli veri', function () {
    (new ImportRunner)->esegui(bundleDanea(), [], LIVELLI_FINO_ALLE_TABELLE);

    $ruoli = DB::table('anagrafica_immobile')->pluck('tipologia')->unique()->values()->all();

    expect($ruoli)->toContain(RuoloAnagraficaImmobile::PROPRIETARIO->value)
        ->and($ruoli)->toContain(RuoloAnagraficaImmobile::INQUILINO->value)
        ->and($ruoli)->toContain(RuoloAnagraficaImmobile::USUFRUTTUARIO->value);
});

it('fa decorrere le titolarità dall\'inizio dell\'esercizio, non da oggi', function () {
    // `anagrafica_immobile.data_inizio` è NOT NULL e l'export non porta la decorrenza: quella
    // sta solo nel database nativo. Metterci «oggi» farebbe risultare tutti i titolari entrati
    // il giorno della migrazione, e ogni calcolo pro-rata a ritroso ne uscirebbe falsato.
    (new ImportRunner)->esegui(bundleDanea(), [], LIVELLI_FINO_ALLE_TABELLE);

    $date = DB::table('anagrafica_immobile')->pluck('data_inizio')->unique()->values()->all();

    expect($date)->toHaveCount(1)
        ->and(substr((string) $date[0], 0, 10))->toBe('2024-11-01');
});

it('importa le palazzine invece di buttarle in una nota', function () {
    (new ImportRunner)->esegui(bundleDanea(), [], LIVELLI_FINO_ALLE_TABELLE);

    expect(Palazzina::count())->toBeGreaterThan(0);

    $immobiliConPalazzina = Immobile::whereNotNull('palazzina_id')->count();
    expect($immobiliConPalazzina)->toBe(Immobile::count());
});

it('lascia senza tipologia le unità che Danea chiama in un modo che non conosciamo', function () {
    // Danea scrive «Appartamento», il nostro elenco ha «Abitazione». La corrispondenza è ovvia
    // e si fa; se un giorno arriva un tipo che non sappiamo tradurre, l'unità entra **senza**
    // tipologia e con un avviso — perché una tipologia inventata sparisce dalla vista e resta
    // sbagliata.
    $esiti = (new ImportRunner)->esegui(bundleDanea(), [], LIVELLI_FINO_ALLE_TABELLE);

    expect($esiti['unita']->riuscito())->toBeTrue();

    // «Appartamento» è mappato su «Abitazione»: nessun avviso di tipologia non riconosciuta.
    $codici = array_map(fn ($r) => $r->codice, $esiti['unita']->avvisi);
    expect($codici)->not->toContain('unita.tipologia_non_mappata');

    // Ma non tutte le unità ne hanno una, ed è corretto: quelle che arrivano dal report
    // **compatto** non hanno la colonna `Tipo`, che il compatto non porta. La tipologia c'è
    // dove la sorgente la dava, e manca dove la sorgente non ce l'aveva — invece di essere
    // inventata uguale per tutti.
    $conTipologia = Immobile::whereNotNull('tipologia_id')->count();
    expect($conTipologia)->toBeGreaterThan(0)
        ->and($conTipologia)->toBeLessThan(Immobile::count());

    // E quelle che ce l'hanno, ce l'hanno giusta.
    expect(
        Immobile::whereNotNull('tipologia_id')
            ->join('tipologie_immobili', 'tipologie_immobili.id', '=', 'immobili.tipologia_id')
            ->pluck('tipologie_immobili.nome')->unique()->all()
    )->toBe(['Abitazione']);
});

it('non crea due persone per lo stesso proprietario che possiede due unità', function () {
    (new ImportRunner)->esegui(bundleDanea(), [], LIVELLI_FINO_ALLE_TABELLE);

    $duplicati = DB::table('anagrafiche')
        ->select('nome', DB::raw('COUNT(*) as n'))
        ->groupBy('nome')
        ->having('n', '>', 1)
        ->get();

    expect($duplicati)->toBeEmpty();
});

it('lascia l\'email al primo e importa il secondo senza, quando due persone la condividono', function () {
    // Il caso della coppia: `anagrafiche.email` è UNIQUE. Rifiutare l'intera riga perderebbe
    // molto di più che rinunciare al recapito duplicato — ma va detto, e va detto **a chi è
    // rimasto**, o l'amministratore scriverà alla persona sbagliata credendo di scrivere a
    // entrambe.
    Anagrafica::create([
        'nome' => 'GIA PRESENTE',
        'indirizzo' => 'Via Antica 1',
        'email' => 'condivisa@example.test',
    ]);

    $ctx = (new ImportContext(ImportBatch::create(['sorgente' => 'manual'])))
        ->conCanonico(LivelloSoggetti::CHIAVE, [
            'cf:NUOVO' => new App\Services\Import\Canonical\CanonicalSoggetto(
                nome: 'NUOVO ARRIVATO',
                codiceFiscale: 'RSSMRA50A41L100X',
                indirizzo: 'Via Nuova 2',
                email: 'condivisa@example.test',
            ),
        ]);

    $esito = (new LivelloSoggetti)->commit($ctx);

    expect($esito->riuscito())->toBeTrue()
        ->and($esito->creati)->toBe(1);

    $nuovo = Anagrafica::where('nome', 'NUOVO ARRIVATO')->first();
    expect($nuovo)->not->toBeNull()
        ->and($nuovo->email)->toBeNull();

    $codici = array_map(fn ($r) => $r->codice, $esito->avvisi);
    expect($codici)->toContain('soggetto.recapito_gia_usato');
    expect($esito->avvisi[0]->messaggio)->toContain('GIA PRESENTE');
});

it('rifiuta la persona senza indirizzo invece di far fallire il database', function () {
    $ctx = (new ImportContext(ImportBatch::create(['sorgente' => 'manual'])))
        ->conCanonico(LivelloSoggetti::CHIAVE, [
            'cf:X' => new App\Services\Import\Canonical\CanonicalSoggetto(
                nome: 'SENZA CASA',
                codiceFiscale: 'RSSMRA50A41L100X',
                indirizzo: null,
            ),
        ]);

    $esito = (new LivelloSoggetti)->commit($ctx);

    expect($esito->riuscito())->toBeFalse()
        ->and($esito->rilievi[0]->codice)->toBe('soggetto.indirizzo_assente')
        ->and(Anagrafica::count())->toBe(0);
});

it('avvisa quando restano unità senza nessun titolare', function () {
    // È l'avviso che la schermata di conferma mostra per primo, in rosso: un'unità senza
    // titolare non riceve rate, non compare in morosità e non entra nei riparti. Senza questo
    // controllo l'importazione risulta riuscita e il prodotto inutile — che è esattamente lo
    // stato in cui finisce il concorrente.
    $ctx = bundleDanea();
    (new ImportRunner)->esegui($ctx, [], LIVELLI_FINO_ALLE_TABELLE);

    // Un'unità in più, senza titolari: è il caso della riga che un livello precedente ha
    // saltato per una decisione non presa.
    $condominio = Condominio::first();
    $orfana = Immobile::create([
        'condominio_id' => $condominio->id,
        'nome' => 'Orfana',
        'descrizione' => 'Unità senza titolari, per il test',
        'interno' => '999',
        'attivo' => true,
    ]);

    // Si richiama il livello direttamente, con l'orfana nel gruppo delle unità risolte e
    // nessuna titolarità da scrivere: è il modo più diretto di verificare il conteggio.
    $ctx->risolviMolti(LivelloUnita::CHIAVE, ['1-0-999' => $orfana])
        ->conCanonico(LivelloTitolarita::CHIAVE, []);

    $esito = (new LivelloTitolarita)->commit($ctx);

    expect($esito->riuscito())->toBeTrue()          // un avviso non blocca
        ->and($esito->creati)->toBe(0);

    $codici = array_map(fn ($r) => $r->codice, $esito->avvisi);
    expect($codici)->toContain('titolarita.unita_senza_titolare');
    expect($esito->avvisi[0]->messaggio)->toContain('1 unità');
    expect($esito->avvisi[0]->rimedio)->toContain('non riceve rate');
});

it('non duplica le titolarità quando lo stesso file viene caricato due volte', function () {
    (new ImportRunner)->esegui(bundleDanea(), [], LIVELLI_FINO_ALLE_TABELLE);
    $primo = DB::table('anagrafica_immobile')->count();

    (new ImportRunner)->esegui(bundleDanea(), [
        'condominio:00000000001' => 'salta',
        'esercizi:2024/2025' => 'salta',
        ...array_reduce(
            Anagrafica::pluck('codice_fiscale')->filter()->all(),
            function (array $carry, string $cf) {
                $carry['soggetti:'.$cf] = 'salta';

                return $carry;
            },
            [],
        ),
    ]);

    expect(DB::table('anagrafica_immobile')->count())->toBe($primo);
});

it('lascia il condominio importato nello stesso stato in cui lo lascia l\'applicazione', function () {
    // L'applicazione non crea mai un condominio nudo: `CondominioService` gli costruisce anche
    // il piano dei conti standard, e all'esercizio aggancia la gestione ordinaria. Un
    // condominio importato senza sarebbe in uno stato che il prodotto non produce mai — e ogni
    // cosa a valle darebbe per scontati conti e gestioni che non ci sono.
    //
    // Si riusano i servizi dell'applicazione, non se ne scrivono di paralleli.
    (new ImportRunner)->esegui(bundleDanea(), [], LIVELLI_FINO_ALLE_TABELLE);

    $condominio = Condominio::first();

    expect(DB::table('conti_contabili')->where('condominio_id', $condominio->id)->count())
        ->toBeGreaterThan(0);

    // Il conto che regge i saldi dei condòmini: senza, il livello dei saldi non avrebbe dove
    // appoggiarsi.
    expect(DB::table('conti_contabili')
        ->where('condominio_id', $condominio->id)
        ->where('ruolo', 'crediti_condomini')
        ->exists())->toBeTrue();

    $gestione = DB::table('gestioni')->where('condominio_id', $condominio->id)->first();
    expect($gestione)->not->toBeNull()
        ->and($gestione->tipo)->toBe('ordinaria');

    // E la gestione è agganciata all'esercizio importato, non a uno qualsiasi.
    $esercizio = DB::table('esercizi')->where('condominio_id', $condominio->id)->first();
    expect(DB::table('esercizio_gestione')
        ->where('esercizio_id', $esercizio->id)
        ->where('gestione_id', $gestione->id)
        ->exists())->toBeTrue();
});
