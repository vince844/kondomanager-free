<?php

use App\Models\Condominio;
use App\Models\ImportBatch;
use App\Models\QuotaTabella;
use App\Models\Tabella;
use App\Services\Import\Canonical\CanonicalCondominio;
use App\Services\Import\ImportContext;
use App\Services\Import\ImportRunner;
use App\Services\Import\Livelli\LivelloCondominio;
use App\Services\Import\Livelli\LivelloTabelle;
use App\Services\Import\Livelli\LivelloUnita;
use App\Services\Import\Parser\AnagraficaMillesimiParser;
use App\Services\Import\ReportRecognizer;
use App\Services\Import\Severita;
use App\Services\Import\SpreadsheetReader;
use Database\Seeders\TipologieImmobiliSeeder;

beforeEach(fn () => test()->seed(TipologieImmobiliSeeder::class));

/**
 * L'importazione «un livello alla volta» del §5: chi ha solo il file dei millesimi non sta
 * importando le persone, e non deve vedersi rifiutare l'import perché mancano.
 */
const LIVELLI_MILLESIMI = ['condominio', 'unita', 'tabelle'];

/**
 * Le tabelle millesimali dal report compatto — livello 7.
 *
 * La fixture porta i tre casi che sui file veri convivono nello stesso foglio: una tabella
 * **parziale** (l'impianto idraulico, quattro unità su sei), una **a parti uguali** (le
 * cassette postali, tutte a 1) e valori con **quattro decimali** (`228.5002`).
 *
 * ## Cosa questi test NON coprono
 *
 * - **Il collegamento tabella → conto** (`conto_tabella_millesimale`): senza, le tabelle
 *   importate esistono ma nessuna spesa ci si appoggia ancora.
 * - **La guardia del Validatore Coerenza Millesimi**, che la 1.10 deve ancora e che
 *   introdurrà `totale_riferimento` su `tabelle`: quando arriverà, l'import dovrà rispettarla.
 * - **Le tabelle per palazzina o per scala**: `tabelle` ha `palazzina_id` e `scala_id`, e
 *   l'import le crea sempre a livello di condominio. Sui file reali esistono nomi come
 *   «Scala A palazz. 1», che sono tabelle parziali con un nome parlante — e il parser le
 *   tratta come tali, senza tentare di dedurne l'ambito.
 */
function millesimiDaFixture(): array
{
    $fogli = (new SpreadsheetReader)->leggi(base_path('tests/Fixtures/import/danea/millesimi_tabelle_miste.xls'));
    $riga = (new ReportRecognizer)->riconosci($fogli[0])->rigaIntestazione;

    return (new AnagraficaMillesimiParser)->estrai($fogli[0], $riga);
}

function contestoMillesimi(): ImportContext
{
    $letto = millesimiDaFixture();

    return (new ImportContext(ImportBatch::create(['sorgente' => 'danea'])))
        ->conCanonico(LivelloCondominio::CHIAVE, new CanonicalCondominio(
            nome: 'Millesimi Prova',
            codiceFiscale: '00321760282',
            indirizzo: 'Via Roma 15',
        ))
        ->conCanonico(LivelloUnita::CHIAVE, $letto['immobili'])
        ->conCanonico(LivelloTabelle::CHIAVE, $letto['tabelle']);
}

it('ricava tutte le tabelle del file, quante che siano', function () {
    // Il numero e i nomi delle tabelle li decide l'amministratore, non il gestionale: un parser
    // che si aspettasse un elenco noto non leggerebbe il secondo file che gli capita.
    $out = millesimiDaFixture();

    expect($out['tabelle'])->toHaveCount(6)
        ->and(array_keys($out['tabelle']))->toContain('AMMINISTRAZIONE')
        ->and(array_keys($out['tabelle']))->toContain('idraulico')
        ->and(array_keys($out['tabelle']))->toContain('Cassete postali');
});

it('non trasforma la cella vuota in uno zero', function () {
    // È il cuore della tabella parziale. Con gli zeri la somma resterebbe la stessa ma la
    // platea si allargherebbe, e il riparto uscirebbe sbagliato senza che niente lo segnali.
    $out = millesimiDaFixture();

    $idraulico = $out['tabelle']['idraulico'];
    $amministrazione = $out['tabelle']['AMMINISTRAZIONE'];

    expect($idraulico->partecipanti())->toBeLessThan($amministrazione->partecipanti())
        ->and($idraulico->quote)->not->toContain(0.0);
});

it('riconosce la tabella a parti uguali invece di farla sembrare un errore', function () {
    $out = millesimiDaFixture();

    expect($out['tabelle']['Cassete postali']->isPartiUguali())->toBeTrue()
        ->and($out['tabelle']['AMMINISTRAZIONE']->isPartiUguali())->toBeFalse();

    $codici = array_map(fn ($r) => $r->codice, $out['esito']->perSeverita(Severita::Avviso));
    expect($codici)->toContain('tabella.parti_uguali')
        ->and($codici)->toContain('tabella.parziale');
});

it('conta i decimali che servono davvero, invece di arrotondare in ingresso', function () {
    // I millesimi reali hanno quattro decimali (`228.5002`) e la colonna `numero_decimali`
    // nasce a due. Arrotondare all'ingresso è una perdita silenziosa.
    $out = millesimiDaFixture();

    expect($out['tabelle']['idraulico']->decimaliNecessari())->toBe(4)
        ->and($out['tabelle']['AMMINISTRAZIONE']->decimaliNecessari())->toBe(2);
});

it('dice che da questo report le persone non si possono ricavare', function () {
    // Niente CF, niente ruoli, niente indirizzi — e `anagrafiche.indirizzo` è NOT NULL.
    // Meglio dirlo che lasciarlo scoprire a importazione finita.
    $out = millesimiDaFixture();

    $codici = array_map(fn ($r) => $r->codice, $out['esito']->rilievi);

    expect($codici)->toContain('soggetti.non_ricavabili_da_questo_report')
        // Ma non blocca: unità e tabelle entrano lo stesso.
        ->and($out['esito']->confermabile())->toBeTrue();
});

it('scrive le tabelle in archivio con i valori a quattro decimali intatti', function () {
    $ctx = contestoMillesimi();
    $esiti = (new ImportRunner)->esegui($ctx, [], LIVELLI_MILLESIMI);

    expect($esiti['tabelle']->riuscito())->toBeTrue()
        ->and($esiti['tabelle']->creati)->toBe(6);

    $idraulico = Tabella::where('nome', 'idraulico')->first();
    expect($idraulico)->not->toBeNull()
        ->and($idraulico->numero_decimali)->toBe(4);

    $valori = QuotaTabella::where('tabella_id', $idraulico->id)->pluck('valore')->map(fn ($v) => (float) $v);
    expect($valori)->toContain(228.5002);
});

it('non crea la riga per l\'unità che non partecipa a una tabella parziale', function () {
    // `quote_tabella` ha una colonna `escluso`, ma **non la legge nessuno**: il motore cicla su
    // tutte le righe e somma `valore`. Una riga marcata esclusa con un valore verrebbe
    // conteggiata lo stesso. L'unità che non partecipa semplicemente non ha una riga.
    $ctx = contestoMillesimi();
    (new ImportRunner)->esegui($ctx, [], LIVELLI_MILLESIMI);

    $idraulico = Tabella::where('nome', 'idraulico')->first();
    $amministrazione = Tabella::where('nome', 'AMMINISTRAZIONE')->first();

    $righeIdraulico = QuotaTabella::where('tabella_id', $idraulico->id)->count();
    $righeAmministrazione = QuotaTabella::where('tabella_id', $amministrazione->id)->count();

    expect($righeIdraulico)->toBeLessThan($righeAmministrazione)
        ->and(QuotaTabella::where('tabella_id', $idraulico->id)->where('valore', 0)->count())->toBe(0);
});

it('deduce il tipo dal nome solo quando è ovvio, e per il resto dice «altro»', function () {
    $ctx = contestoMillesimi();
    (new ImportRunner)->esegui($ctx, [], LIVELLI_MILLESIMI);

    // «ASCENSORE E SCALE» contiene entrambe le parole: vince ascensore, che è la più specifica.
    expect(Tabella::where('nome', 'ASCENSORE E SCALE')->value('tipo'))->toBe('ascensore');

    // «AMMINISTRAZIONE» e «ASSICURAZIONE» non corrispondono a nessun tipo del nostro enum.
    // Assegnare loro `standard` sarebbe più ordinato e falso: `standard` è la tabella generale
    // di proprietà, non un contenitore per ciò che non si sa classificare.
    expect(Tabella::where('nome', 'AMMINISTRAZIONE')->value('tipo'))->toBe('altro')
        ->and(Tabella::where('nome', 'ASSICURAZIONE')->value('tipo'))->toBe('altro');
});

it('si rifiuta di scrivere i millesimi senza le unità a cui attaccarli', function () {
    $letto = millesimiDaFixture();

    $ctx = (new ImportContext(ImportBatch::create(['sorgente' => 'danea'])))
        ->conCanonico(LivelloCondominio::CHIAVE, new CanonicalCondominio(
            nome: 'Solo Millesimi',
            codiceFiscale: '00321760282',
            indirizzo: 'Via Roma 15',
        ))
        ->conCanonico(LivelloTabelle::CHIAVE, $letto['tabelle']);

    $esiti = (new ImportRunner)->esegui($ctx, [], LIVELLI_MILLESIMI);

    expect($esiti['unita']->riuscito())->toBeFalse()
        ->and($esiti)->not->toHaveKey('tabelle')
        ->and(Tabella::count())->toBe(0);
});

it('non duplica le tabelle quando lo stesso file viene caricato due volte', function () {
    (new ImportRunner)->esegui(contestoMillesimi(), [], LIVELLI_MILLESIMI);
    $primo = Tabella::count();
    $quotePrimo = QuotaTabella::count();

    $ctx2 = contestoMillesimi();
    $esiti = (new ImportRunner)->esegui($ctx2, ['condominio:00321760282' => 'salta'], LIVELLI_MILLESIMI);

    expect($esiti['tabelle']->saltati)->toBe($primo)
        ->and(Tabella::count())->toBe($primo)
        ->and(QuotaTabella::count())->toBe($quotePrimo)
        ->and(Condominio::count())->toBe(1);
});

it('avvisa che le tabelle entrano senza capitolo di spesa collegato', function () {
    // Trovato guardando l'elenco tabelle del condominio importato: erano tutte marcate
    // «Orfana». Una tabella scollegata esiste e non ripartisce niente — il riparto non usa
    // quei millesimi finché qualcuno non la aggancia a un capitolo. Danea non dice quale
    // spesa vada su quale tabella, quindi il collegamento resta all'amministratore: ma
    // scoprirlo a settembre, davanti a un consuntivo che non torna, è un'altra cosa.
    $esiti = (new ImportRunner)->esegui(contestoMillesimi(), [], LIVELLI_MILLESIMI);

    $codici = array_map(fn ($a) => $a->codice, $esiti['tabelle']->avvisi);

    expect($codici)->toContain('tabelle.non_collegate_ai_capitoli')
        ->and(Tabella::query()->has('conti')->count())->toBe(0);
});
