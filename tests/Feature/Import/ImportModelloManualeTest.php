<?php

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Immobile;
use App\Models\ImportBatch;
use App\Models\Saldo;
use App\Models\Tabella;
use App\Models\User;
use App\Services\Import\ModelloManualeWriter;
use App\Services\Import\Parser\ModelloManualeParser;
use App\Services\Import\ReportRecognizer;
use App\Services\Import\SpreadsheetReader;
use Database\Seeders\TipologieImmobiliSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;

/**
 * Il modello compilato a mano, dal file al database.
 *
 * ## Perché il file di prova si costruisce **sopra quello vero**
 *
 * Ogni caso di questo file parte da `ModelloManualeWriter`, ci sovrascrive le righe di esempio e
 * poi lo rilegge. Scrivere invece un `.xlsx` di comodo con le stesse colonne sarebbe stato più
 * corto e avrebbe testato la cosa sbagliata: il modello e il parser sono **due metà della stessa
 * promessa** — «scarica, compila, ricarica» — e la metà che si rompe per prima è la fedeltà fra
 * loro. Un test che non passa dal generatore continuerebbe a passare il giorno in cui il
 * generatore sposta l'intestazione di una riga, e nessuno lo saprebbe fino al primo
 * amministratore che ricarica quattro fogli compilati.
 */
function utenteModello(): User
{
    // Il secondo permesso non serve alla rotta: serve alla **pagina 403**, che lo interroga per
    // decidere cosa mostrare. Senza, un `assertForbidden` esplode dentro la vista dell'errore
    // invece di riportare l'errore.
    foreach (['Crea condomini', 'Accesso pannello amministratore'] as $nome) {
        Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']);
    }

    $u = User::factory()->create();
    // ⚠️ **Dal 30/08/2026 serve «Importa dati», non più «Crea condomini».** L'amministratore lo ha
    // per costruzione; qui si concede esplicitamente perché il caso non passa da un ruolo.
    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'Importa dati', 'guard_name' => 'web']);
    $u->givePermissionTo('Crea condomini');
    $u->givePermissionTo('Importa dati');
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    return $u;
}

/**
 * Un percorso temporaneo che **non lascia due file dietro di sé**.
 *
 * ⚠️ `tempnam()` non restituisce un nome libero: **crea** il file. Concatenargli «.xlsx» produce
 * quindi un secondo percorso, e lo stub da 0 byte resta lì per sempre. È lo stesso difetto che la
 * revisione avversariale ha trovato nella rotta di scaricamento — qui era negli aiutanti dei test,
 * dove costava 400 file per ogni giro della suite sulla macchina di chi la lancia.
 */
function percorsoTemporaneo(string $prefisso = 'modello_'): string
{
    $base = tempnam(sys_get_temp_dir(), $prefisso);
    $percorso = $base.'.xlsx';

    @unlink($base);
    temporaneiDelCaso($percorso);

    return $percorso;
}

/**
 * Il registro dei file temporanei di questo file di test, svuotato dopo ogni caso.
 *
 * ⚠️ Senza, una suite intera lasciava **duecento modelli compilati** nella cartella temporanea
 * della macchina di chi la lancia — file veri da 15 KB l'uno, non stub. Cancellarli dentro ogni
 * caso non basta: metà di essi vengono passati a `UploadedFile` e la loro vita finisce quando
 * finisce il caso, non quando l'ultima asserzione è passata.
 *
 * @return list<string> i percorsi accumulati, e li dimentica
 */
function temporaneiDelCaso(?string $aggiungi = null): array
{
    static $percorsi = [];

    if ($aggiungi !== null) {
        $percorsi[] = $aggiungi;

        return [];
    }

    $accumulati = $percorsi;
    $percorsi = [];

    return $accumulati;
}

/**
 * Il modello vuoto, compilato come lo compilerebbe un amministratore.
 *
 * Le righe di esempio del modello vengono **cancellate**, non aggirate: è ciò che il file stesso
 * chiede di fare («righe di esempio: cancellale e metti le tue»), quindi è la forma in cui il
 * file arriva davvero.
 *
 * @param  array<string, string>  $copertina
 * @param  list<list<mixed>>  $unita
 * @param  list<list<mixed>>  $persone
 * @param  array{nomi: list<string>, righe: list<list<mixed>>, controllo?: list<mixed>}|null  $tabelle
 * @param  list<list<mixed>>  $saldi
 */
function modelloCompilato(
    array $copertina = [],
    array $unita = [],
    array $persone = [],
    ?array $tabelle = null,
    array $saldi = [],
): string {
    $percorso = percorsoTemporaneo();
    (new ModelloManualeWriter)->scriviSu($percorso);

    $libro = IOFactory::load($percorso);

    // La prima riga di dati: subito sotto l'intestazione scura che il generatore mette in riga 5.
    $prima = 6;

    $svuota = function (string $foglio, int $da) use ($libro) {
        $f = $libro->getSheetByName($foglio);

        for ($r = $da; $r <= $da + 40; $r++) {
            foreach (range('A', 'J') as $c) {
                $f->setCellValue($c.$r, null);
            }
        }

        return $f;
    };

    $c = $svuota('0 copertina', $prima);
    $campi = ['condominio', 'codice_fiscale', 'indirizzo', 'esercizio', 'data_inizio', 'data_fine'];

    foreach ($campi as $i => $campo) {
        $c->setCellValue('A'.($prima + $i), $campo);
        $c->setCellValue('B'.($prima + $i), $copertina[$campo] ?? '');
    }

    $svuota('1 unita', $prima)->fromArray($unita, null, 'A'.$prima);
    $svuota('2 persone', $prima)->fromArray($persone, null, 'A'.$prima);
    $svuota('4 saldi', $prima)->fromArray($saldi, null, 'A'.$prima);

    // Le tabelle hanno una riga in più: `# tabella` in 5, l'intestazione in 6, i dati da 7.
    if ($tabelle !== null) {
        $t = $svuota('3 tabelle', 7);
        $t->fromArray(['# tabella', ...$tabelle['nomi']], null, 'A5');
        $t->fromArray(['unita', ...$tabelle['nomi']], null, 'A6');
        $t->fromArray($tabelle['righe'], null, 'A7');

        if (isset($tabelle['controllo'])) {
            $t->fromArray(['# TOTALE DI CONTROLLO', ...$tabelle['controllo']], null, 'A'.(7 + count($tabelle['righe']) + 1));
        }
    } else {
        $svuota('3 tabelle', 5);
    }

    (new Xlsx($libro))->save($percorso);
    $libro->disconnectWorksheets();

    return $percorso;
}

/** Un condominio piccolo ma completo: tre unità, quattro titolari, due tabelle, due saldi. */
function modelloDiProva(array $sovrascrivi = []): string
{
    return modelloCompilato(...array_merge([
        'copertina' => [
            'condomini' => 1,
            'condominio' => 'CONDOMINIO SCRITTO A MANO',
            'codice_fiscale' => '97555000188',
            'indirizzo' => 'Via del Modello 10, Roma',
            'esercizio' => '2026',
            'data_inizio' => '01/01/2026',
            'data_fine' => '31/12/2026',
        ],
        'unita' => [
            ['B1/1', '1', 'A', '1', 'T', 'appartamento'],
            ['B1/2', '1', 'A', '2', '1', 'appartamento'],
            ['NEG', '1', 'A', '3', 'T', 'negozio'],
        ],
        'persone' => [
            ['B1/1', 'ROSSI MARIO', 'RSSMRA80A01H501U', 'Via Roma 1, Roma', 'proprietario', 100, '', ''],
            ['B1/2', 'BIANCHI ANNA', '', 'Via Roma 2, Roma', 'proprietario', 60, '', ''],
            ['B1/2', 'VERDI LUCA', '', 'Via Roma 2, Roma', 'proprietario', 40, '', ''],
            ['NEG', 'GIALLI SPA', '', 'Via Roma 3, Roma', 'conduttore', 100, '', ''],
        ],
        'tabelle' => [
            'nomi' => ['PROPRIETA GENERALE', 'SCALE'],
            'righe' => [
                ['B1/1', 400, 500],
                ['B1/2', 400, 500],
                // ⚠️ La cella vuota della colonna «SCALE» è il caso che dà senso alla distinzione
                // fra vuoto e zero: il negozio ha l'ingresso sulla strada e alle scale non
                // partecipa. Scritto zero, comparirebbe fra i partecipanti con quota nulla.
                ['NEG', 200, ''],
            ],
            'controllo' => [1000, 1000],
        ],
        'saldi' => [
            ['B1/1', 'ROSSI MARIO', 120.50, 'conguaglio 2024/2025'],
            ['B1/2', '', -45, 'credito a favore dell\'unità'],
        ],
    ], $sovrascrivi));
}

/** @return array<string, mixed> ciò che il parser ricava dal file, senza passare dal database. */
function leggiModello(string $percorso): array
{
    return (new ModelloManualeParser)->estrai((new SpreadsheetReader)->leggi($percorso));
}

function caricaModello(User $come, string $percorso): ImportBatch
{
    $file = new UploadedFile($percorso, 'modello.xlsx', null, test: true);

    test()->actingAs($come)->post(route('import.store'), ['file' => [$file]]);

    // ⚠️ **`orderByDesc('id')` e non `latest()`, ed è costato un test che provava il nulla.**
    // `latest()` ordina per `created_at`: due caricamenti nello stesso secondo sono a **pari
    // merito**, e il database può restituire il primo. Finché ogni caso caricava un file solo la
    // differenza non esisteva; il primo test che ne carica **due** — il giro completo
    // annulla-e-reimporta, in fondo a questo file — riceveva due volte lo **stesso** lotto, e
    // passava confermando due volte quello di prima. Tre sabotaggi al prodotto lo lasciavano
    // verde, ed è così che se n'è accorto qualcuno. La chiave primaria non ha pari merito.
    return ImportBatch::orderByDesc('id')->first();
}

/** Tutti i codici dei rilievi, di qualunque foglio. */
function codiciModello(array $letto): array
{
    $codici = [];

    foreach ($letto['esiti'] as $esito) {
        foreach ($esito->rilievi as $r) {
            $codici[] = $r->codice;
        }
    }

    return $codici;
}

beforeEach(function () {
    test()->seed(TipologieImmobiliSeeder::class);
    Storage::fake('local');
});

afterEach(function () {
    foreach (temporaneiDelCaso() as $percorso) {
        @unlink($percorso);
    }
});

it('riconosce il proprio modello, e con la fiducia massima', function () {
    // ⚠️ Il segnale è il **titolo in riga 1 della copertina** più le sue tre colonne. Se il
    // generatore spostasse il titolo alla riga 4, il riconoscimento scenderebbe a 60 e — cosa
    // peggiore — un file un po' diverso potrebbe superarlo. Questo test è il guinzaglio fra
    // `ModelloManualeWriter::TITOLO` e `ReportType::ModelloManuale::titoloBanner()`.
    $fogli = (new SpreadsheetReader)->leggi(modelloDiProva());

    $esito = (new ReportRecognizer)->riconosci($fogli[0]);

    expect($esito->tipo?->value)->toBe('modello_manuale')
        ->and($esito->confidenza)->toBe(100);
});

it('non scambia i suoi altri quattro fogli per stampe di Danea', function () {
    // Il rischio è concreto: «unita, palazzina, interno, piano, tipo» somiglia all'elenco unità,
    // e un foglio riconosciuto come `elenco_unita` verrebbe letto dal parser sbagliato — che di
    // quelle colonne ne troverebbe tre su dieci e produrrebbe unità senza titolari.
    $fogli = (new SpreadsheetReader)->leggi(modelloDiProva());

    foreach (array_slice($fogli, 1) as $foglio) {
        expect((new ReportRecognizer)->riconosci($foglio)->tipo)->toBeNull();
    }
});

it('legge i cinque fogli di un modello compilato', function () {
    $letto = leggiModello(modelloDiProva());

    expect($letto['condominio']->nome)->toBe('CONDOMINIO SCRITTO A MANO')
        ->and($letto['condominio']->codiceFiscale)->toBe('97555000188')
        ->and($letto['esercizio']->etichetta)->toBe('2026')
        ->and($letto['esercizio']->dataInizio->toDateString())->toBe('2026-01-01')
        ->and($letto['immobili'])->toHaveCount(3)
        ->and($letto['soggetti'])->toHaveCount(4)
        ->and($letto['titolarita'])->toHaveCount(4)
        ->and($letto['tabelle'])->toHaveCount(2)
        ->and($letto['saldi']->righe)->toHaveCount(2)
        ->and(codiciModello($letto))->toBe([]);
});

it('tiene la cella vuota diversa dallo zero', function () {
    // Il negozio non partecipa alle scale. `null` significa «non partecipa», `0` significa
    // «partecipa con zero millesimi»: la seconda lo metterebbe fra i partecipanti, e da lì nei
    // riparti e nei quorum assembleari.
    $letto = leggiModello(modelloDiProva());

    $scale = $letto['tabelle']['SCALE'];

    expect($scale->partecipanti())->toBe(2)
        ->and($letto['tabelle']['PROPRIETA GENERALE']->partecipanti())->toBe(3);
});

it('legge i ruoli a parole, sinonimi compresi', function () {
    $letto = leggiModello(modelloDiProva());

    $ruoli = array_map(fn ($t) => $t->ruolo->value, $letto['titolarita']);

    // «conduttore» è la parola che un amministratore scrive per l'inquilino.
    expect($ruoli)->toBe(['proprietario', 'proprietario', 'proprietario', 'inquilino']);
});

it('non inverte il segno dei saldi, a differenza del riparto di Danea', function () {
    // ⚠️ Il modello dichiara «POSITIVO = deve al condominio», che è già la convenzione di
    // Kondomanager. Un'inversione «per simmetria» con `RipartoConsuntivoParser` trasformerebbe
    // ogni debito in credito senza che niente lo segnali: sul modello non c'è un totale di
    // controllo, quindi la quadratura non se ne accorgerebbe.
    $letto = leggiModello(modelloDiProva());

    $importi = array_map(fn ($s) => $s->importoCents, $letto['saldi']->righe);

    expect($importi)->toBe([12050, -4500])
        ->and($letto['saldi']->righe[1]->isSolidale())->toBeTrue()
        ->and($letto['saldi']->righe[0]->causale)->toBe('conguaglio 2024/2025');
});

it('porta un modello compilato fino in archivio', function () {
    $utente = utenteModello();
    $batch = caricaModello($utente, modelloDiProva());

    expect($batch->files()->first()->report_type)->toBe('modello_manuale');

    // Niente è ancora stato scritto: la verifica legge e basta.
    $this->actingAs($utente)->get(route('import.verifica', $batch->uuid))->assertOk();
    expect(Condominio::count())->toBe(0);

    $this->actingAs($utente)->post(route('import.conferma', $batch->uuid));

    $condominio = Condominio::first();

    expect($condominio->nome)->toBe('CONDOMINIO SCRITTO A MANO')
        ->and(Esercizio::where('condominio_id', $condominio->id)->count())->toBe(1)
        ->and(Immobile::where('condominio_id', $condominio->id)->count())->toBe(3)
        ->and(Anagrafica::count())->toBe(4)
        ->and(Tabella::where('condominio_id', $condominio->id)->count())->toBe(2)
        ->and(Saldo::count())->toBe(2);
});

it('scrive la causale nella descrizione del saldo', function () {
    // È l'unica informazione che nessuna stampa di Danea porta, e fra un anno sarà l'unica cosa
    // che spiega perché quella posizione era aperta.
    $utente = utenteModello();
    $batch = caricaModello($utente, modelloDiProva());

    $this->actingAs($utente)->post(route('import.conferma', $batch->uuid));

    expect(Saldo::where('saldo_iniziale', 12050)->first()->descrizione)
        ->toBe('conguaglio 2024/2025');
});

it('collega i saldi anche quando la sigla dell\'unità contiene un trattino', function () {
    // ⚠️ La ricerca storica di `LivelloSaldi` confronta la **coda** della chiave canonica, perché
    // il riparto di Danea stampa solo il progressivo. Con una sigla come «A-2» la chiave diventa
    // `1-0-A-2`, la cui coda è «2»: il saldo non si sarebbe collegato a niente, e il messaggio
    // avrebbe detto «unità non fra quelle importate» su un'unità che c'era.
    $utente = utenteModello();
    $batch = caricaModello($utente, modelloDiProva([
        'unita' => [['A-2', '', '', '2', '1', 'appartamento']],
        'persone' => [['A-2', 'ROSSI MARIO', '', 'Via Roma 1, Roma', 'proprietario', 100, '', '']],
        'tabelle' => null,
        'saldi' => [['A-2', 'ROSSI MARIO', 300, 'pregresso']],
    ]));

    $this->actingAs($utente)->post(route('import.conferma', $batch->uuid));

    expect(Saldo::count())->toBe(1)
        ->and(Saldo::first()->saldo_iniziale)->toBe(30000);
});

it('rifiuta una sigla che nel foglio delle unità non c\'è, e dice quali conosce', function () {
    $letto = leggiModello(modelloDiProva([
        'persone' => [['B9/9', 'ROSSI MARIO', '', 'Via Roma 1, Roma', 'proprietario', 100, '', '']],
    ]));

    $rilievo = $letto['esiti']['soggetti']->rilievi[0];

    expect($rilievo->codice)->toBe('modello.unita_sconosciuta')
        ->and($rilievo->messaggio)->toContain('B9/9')
        // Il rimedio elenca le sigle vere: senza, l'amministratore deve tornare al foglio 1 e
        // confrontare a occhio duecento righe per capire dove ha sbagliato a copiare.
        ->and($rilievo->rimedio)->toContain('«B1/1»')
        ->and($rilievo->foglio)->toBe('2 persone');
});

it('tollera uno spazio di troppo, ma lo dice', function () {
    // La regola dichiarata è l'uguaglianza esatta, e resta quella. Ma «B1/ 1» è l'errore che chi
    // copia a mano fa davvero, e fargli perdere la riga sarebbe sproporzionato — soprattutto su
    // un foglio dove la riga persa non si vede, perché il totale di controllo è facoltativo.
    $letto = leggiModello(modelloDiProva([
        'persone' => [['b1/ 1', 'ROSSI MARIO', '', 'Via Roma 1, Roma', 'proprietario', 100, '', '']],
    ]));

    $rilievi = $letto['esiti']['soggetti']->rilievi;

    expect($letto['titolarita'])->toHaveCount(1)
        ->and($letto['titolarita'][0]->immobileRef)->toBe('1-A-B1/1')
        ->and($rilievi[0]->codice)->toBe('modello.sigla_scritta_diversa')
        ->and($rilievi[0]->severita->value)->toBe('avviso')
        ->and($rilievi[0]->messaggio)->toContain('«b1/ 1» → «B1/1»');
});

it('segnala una sola volta la stessa sigla scritta male su venti righe', function () {
    // Fra venti avvisi identici non si legge più quello vero: è il modo in cui un pannello di
    // avvisi smette di essere letto.
    $persone = [];

    foreach (range(1, 20) as $i) {
        $persone[] = ['b1/ 1', 'PERSONA '.$i, '', 'Via Roma '.$i.', Roma', 'proprietario', '', '', ''];
    }

    $letto = leggiModello(modelloDiProva(['persone' => $persone]));

    $sigle = array_filter($letto['esiti']['soggetti']->rilievi, fn ($r) => $r->codice === 'modello.sigla_scritta_diversa');

    expect($sigle)->toHaveCount(1);
});

it('dice quando il totale di controllo non torna, senza però fermarsi', function () {
    // I millesimi non devono sommare a 1000 perché Kondomanager calcoli bene — è verificato più
    // volte — quindi lo scarto è un **avviso**: serve ad accorgersi di una riga persa mentre si
    // copiava, non a imporre un totale.
    $letto = leggiModello(modelloDiProva([
        'tabelle' => [
            'nomi' => ['PROPRIETA GENERALE'],
            'righe' => [['B1/1', 400], ['B1/2', 400]],
            'controllo' => [1000],
        ],
    ]));

    $rilievo = $letto['esiti']['tabelle']->rilievi[0];

    expect($rilievo->codice)->toBe('modello.totale_di_controllo_diverso')
        ->and($rilievo->severita->value)->toBe('avviso')
        ->and($rilievo->messaggio)->toContain('800')
        ->and($letto['tabelle']['PROPRIETA GENERALE']->partecipanti())->toBe(2);
});

it('non fa entrare la propria nota di esempio come se fosse un\'unità', function () {
    // Il modello lascia in coda una riga «↑ righe di esempio: cancellale e metti le tue». Chi
    // cancella gli esempi e dimentica quella riga non deve trovarsi un condòmino di nome «↑».
    $percorso = percorsoTemporaneo();
    (new ModelloManualeWriter)->scriviSu($percorso);

    $letto = leggiModello($percorso);

    expect(array_keys($letto['immobili']))->toBe(['1-A-B1/1', '1-A-B1/2']);
});

it('avverte quando manca il ruolo, una volta sola e dicendo cosa assume', function () {
    // Assumere «proprietario» è un'affermazione sul diritto di qualcuno: a un inquilino non
    // spettano le spese straordinarie. Si assume perché è il caso più frequente, e si dice.
    $letto = leggiModello(modelloDiProva([
        'persone' => [
            ['B1/1', 'ROSSI MARIO', '', 'Via Roma 1, Roma', '', 100, '', ''],
            ['B1/2', 'BIANCHI ANNA', '', 'Via Roma 2, Roma', '', 100, '', ''],
        ],
    ]));

    $avvisi = array_filter($letto['esiti']['soggetti']->rilievi, fn ($r) => $r->codice === 'modello.ruolo_assente');

    expect($avvisi)->toHaveCount(1)
        ->and(reset($avvisi)->messaggio)->toContain('2 righe')
        ->and(array_map(fn ($t) => $t->ruolo->value, $letto['titolarita']))->toBe(['proprietario', 'proprietario']);
});

it('si ferma sul ruolo che non capisce, invece di indovinarlo', function () {
    $letto = leggiModello(modelloDiProva([
        'persone' => [['B1/1', 'ROSSI MARIO', '', 'Via Roma 1, Roma', 'comproprietario al 50', 100, '', '']],
    ]));

    expect($letto['titolarita'])->toBe([])
        ->and($letto['esiti']['soggetti']->rilievi[0]->codice)->toBe('modello.ruolo_sconosciuto');
});

it('legge le date scritte a mano e quelle che Excel salva come numero', function () {
    // ⚠️ `SpreadsheetReader` legge con `formatData: false`: una cella formattata come data arriva
    // come **seriale** (46023 = 1º gennaio 2026), una scritta in una cella testuale arriva come
    // stringa. Sono i due modi in cui lo stesso file arriva a seconda di come è stata compilata
    // quella cella, e chi compila non sa nemmeno di aver scelto.
    $letto = leggiModello(modelloDiProva([
        'copertina' => [
            'condominio' => 'CONDOMINIO DATE',
            'indirizzo' => 'Via delle Date 1',
            'esercizio' => '',
            'data_inizio' => 46023,
            'data_fine' => '31/12/2026',
        ],
    ]));

    expect($letto['esercizio']->dataInizio->toDateString())->toBe('2026-01-01')
        ->and($letto['esercizio']->dataFine->toDateString())->toBe('2026-12-31')
        // Senza etichetta si usa l'anno, che è come la chiamerebbe chi compila.
        ->and($letto['esercizio']->etichetta)->toBe('2026');
});

it('chiama 2025/2026 un esercizio che scavalca l\'anno', function () {
    $letto = leggiModello(modelloDiProva([
        'copertina' => [
            'condominio' => 'CONDOMINIO A CAVALLO',
            'indirizzo' => 'Via del Cavallo 1',
            'esercizio' => '',
            'data_inizio' => '01/11/2025',
            'data_fine' => '31/10/2026',
        ],
    ]));

    expect($letto['esercizio']->etichetta)->toBe('2025/2026');
});

it('dice che mancano le date, invece di far fallire l\'importazione più avanti', function () {
    // ⚠️ Senza esercizio restano fuori titolarità e saldi — chi possiede cosa e con quale
    // posizione aperta — e il messaggio del livello manderebbe a cercare una stampa di Danea che
    // qui non c'entra niente. Chi dichiara il condominio nella copertina non passa mai dalla
    // scelta della destinazione, quindi nessuno risolverebbe l'esercizio al posto suo.
    $letto = leggiModello(modelloDiProva([
        'copertina' => [
            'condominio' => 'CONDOMINIO SENZA DATE',
            'indirizzo' => 'Via Vuota 1',
            'esercizio' => '2026',
            'data_inizio' => '',
            'data_fine' => '',
        ],
    ]));

    expect($letto['esercizio'])->toBeNull()
        ->and(codiciModello($letto))->toContain('modello.esercizio_senza_date');
});

it('si accorge di un periodo scritto al contrario', function () {
    $letto = leggiModello(modelloDiProva([
        'copertina' => [
            'condominio' => 'CONDOMINIO ROVESCIO',
            'indirizzo' => 'Via Rovescia 1',
            'esercizio' => '2026',
            'data_inizio' => '31/12/2026',
            'data_fine' => '01/01/2026',
        ],
    ]));

    expect(codiciModello($letto))->toContain('modello.periodo_rovesciato');
});

it('lascia lavorare gli altri fogli quando uno è in bianco', function () {
    // È la regola della beta.5 applicata al formato per cui è nata: qui un foglio vuoto non è un
    // export andato storto, è una scelta — «i saldi non li ho», «le tabelle le metto dopo».
    $utente = utenteModello();
    $batch = caricaModello($utente, modelloDiProva(['saldi' => [], 'tabelle' => null]));

    $this->actingAs($utente)->post(route('import.conferma', $batch->uuid));

    expect(Immobile::count())->toBe(3)
        ->and(Anagrafica::count())->toBe(4)
        ->and(Saldo::count())->toBe(0)
        ->and(Tabella::count())->toBe(0)
        // ⚠️ **Completata, non interrotta.** Chi non ha i saldi non ha sbagliato niente.
        ->and($batch->fresh()->stato)->toBe(ImportBatch::STATO_COMPLETATO);
});

it('riconosce i fogli anche se chi compila li rinomina', function () {
    // Il nome del foglio è il segnale primario, ma chi lavora a mano rinomina: le firme delle
    // colonne sono la seconda strada, e senza di esse un file compilato bene finirebbe letto
    // come se fosse mezzo vuoto.
    $percorso = modelloDiProva();

    $libro = IOFactory::load($percorso);
    $libro->getSheetByName('1 unita')->setTitle('elenco appartamenti');
    $libro->getSheetByName('2 persone')->setTitle('condomini');
    (new Xlsx($libro))->save($percorso);
    $libro->disconnectWorksheets();

    $letto = leggiModello($percorso);

    expect($letto['immobili'])->toHaveCount(3)
        ->and($letto['soggetti'])->toHaveCount(4);
});

it('non lascia il modello scaricabile a chi non può creare condomìni', function () {
    // La rotta sta dentro il gruppo dell'importazione, e il modello è la porta d'ingresso di
    // quel percorso: se fosse pubblica, sarebbe l'unica porta aperta di tutto il gruppo.
    $this->get(route('import.modello'))->assertRedirect(route('login'));

    utenteModello();

    $this->actingAs(User::factory()->create())
        ->get(route('import.modello'))
        ->assertForbidden();
});

it('serve un modello che si può ricaricare subito', function () {
    // ⚠️ **Il giro completo del pulsante.** Un modello che si scarica e non si può ricaricare è
    // esattamente il difetto per cui la card che lo ospita è rimasta «in arrivo» due beta: il
    // file che esce dalla rotta deve essere lo stesso che il riconoscitore sa leggere, e questo
    // test è l'unico posto in cui le due metà si toccano davvero.
    $risposta = $this->actingAs(utenteModello())->get(route('import.modello'));

    $risposta->assertOk()
        ->assertDownload('modello-import-kondomanager.xlsx');

    $percorso = percorsoTemporaneo('scaricato_');
    file_put_contents($percorso, $risposta->streamedContent());

    $fogli = (new SpreadsheetReader)->leggi($percorso);

    expect((new ReportRecognizer)->riconosci($fogli[0])->tipo?->value)->toBe('modello_manuale');

    @unlink($percorso);
});

/*
|--------------------------------------------------------------------------
| I sette difetti della revisione avversariale del 30/08/2026
|--------------------------------------------------------------------------
|
| Cinque lenti indipendenti hanno letto il codice appena scritto e proposto 25 difetti; otto
| sono passati per un revisore incaricato di **smentirli**, e sette hanno retto. Tutti e sette
| avevano in comune la stessa forma: **un dato sbagliato che entra senza un rilievo**, che è
| l'unico difetto che questo importatore non si può permettere.
|
| Ogni caso qui sotto è quello riprodotto dal revisore, non una sua riformulazione.
*/

it('non perde un foglio rinominato in un modo che ne richiama un altro', function () {
    // ⚠️ Bastava chiamare «4 saldi» → «saldi per unita» — un nome più esplicito, non uno
    // sbagliato — perché il ciclo sui ruoli trovasse «unita», lo trovasse già occupato dal foglio
    // 1, e lasciasse cadere il foglio **senza passarlo al riconoscimento per firma**. Misurato:
    // i saldi sparivano tutti, e nemmeno la riga «Saldi di apertura» compariva fra le letture.
    $percorso = modelloDiProva();

    $libro = IOFactory::load($percorso);
    $libro->getSheetByName('4 saldi')->setTitle('saldi per unita');
    $libro->getSheetByName('2 persone')->setTitle('persone per unita');
    (new Xlsx($libro))->save($percorso);
    $libro->disconnectWorksheets();

    $letto = leggiModello($percorso);

    expect($letto['saldi']?->righe)->toHaveCount(2)
        ->and($letto['soggetti'])->toHaveCount(4)
        ->and(codiciModello($letto))->not->toContain('modello.foglio_non_riconosciuto');
});

it('dice quando un foglio non l\'ha letto, invece di lasciarlo sparire', function () {
    // Un foglio di appunti si ignora giustamente; un elenco compilato che non sappiamo ricondurre
    // a niente va **detto**. La differenza non la sappiamo fare noi, e tacere sarebbe sceglierla.
    $percorso = modelloDiProva();

    $libro = IOFactory::load($percorso);
    $libro->createSheet()->setTitle('appunti')->setCellValue('A1', 'da chiedere al geometra');
    (new Xlsx($libro))->save($percorso);
    $libro->disconnectWorksheets();

    $letto = leggiModello($percorso);

    $rilievo = collect($letto['esiti']['condominio']->rilievi)
        ->firstWhere('codice', 'modello.foglio_non_riconosciuto');

    expect($rilievo)->not->toBeNull()
        ->and($rilievo->severita->value)->toBe('avviso')
        ->and($rilievo->messaggio)->toContain('appunti')
        // Le unità entrano lo stesso: un foglio in più non è un motivo per fermarsi.
        ->and($letto['immobili'])->toHaveCount(3);
});

it('non scambia l\'anno «2025» per una data del 1905', function () {
    // ⚠️ È l'errore che il modello stesso invita a fare, perché la nota accanto a «data_inizio»
    // parla di «2024/2025». `is_numeric(2025)` è vero, e 2025 come seriale Excel è il 17 luglio
    // **1905**: l'esercizio nasceva con due giorni del 1905, senza un rilievo — «senza date» non
    // scattava perché una data era stata letta, «periodo rovesciato» nemmeno perché il 1905 di
    // data_fine veniva comunque dopo. Titolarità e saldi ci finivano dentro.
    $letto = leggiModello(modelloDiProva([
        'copertina' => [
            'condominio' => 'CONDOMINIO ANNI',
            'indirizzo' => 'Via degli Anni 1',
            'esercizio' => '2025/2026',
            'data_inizio' => 2025,
            'data_fine' => 2026,
        ],
    ]));

    expect($letto['esercizio'])->toBeNull()
        ->and(codiciModello($letto))->toContain('modello.esercizio_senza_date');
});

it('legge ancora le date che Excel salva come numero', function () {
    // La controprova della soglia: alzarla troppo avrebbe rotto il caso normale, che è una cella
    // formattata come data e quindi letta come seriale.
    $letto = leggiModello(modelloDiProva([
        'copertina' => [
            'condominio' => 'CONDOMINIO SERIALE',
            'indirizzo' => 'Via del Seriale 1',
            'esercizio' => '',
            'data_inizio' => 46023,
            'data_fine' => 46387,
        ],
    ]));

    expect($letto['esercizio']->dataInizio->toDateString())->toBe('2026-01-01')
        ->and($letto['esercizio']->dataFine->toDateString())->toBe('2026-12-31');
});

it('si rifiuta di leggere un importo che non è un numero, invece di scrivere zero', function () {
    // ⚠️ Il difetto più grave dei sette. `MoneyHelper::toCents()` non solleva mai: su qualunque
    // cosa non capisca restituisce **0**. Una morosità di € 120,50 scritta «n.d.» entrava a zero,
    // senza errore e senza avviso — e la rete non c'è per costruzione, perché su questo foglio il
    // totale di controllo non esiste e la quadratura non gira.
    $letto = leggiModello(modelloDiProva([
        'saldi' => [
            ['B1/1', 'ROSSI MARIO', 'n.d.', 'conguaglio'],
            ['B1/2', '', '-', 'da verificare'],
        ],
    ]));

    $codici = array_map(fn ($r) => $r->codice, $letto['esiti']['saldi']->rilievi);

    expect($letto['saldi'])->toBeNull()
        ->and($codici)->toBe(['modello.saldo_non_numerico', 'modello.saldo_non_numerico']);
});

it('legge gli importi come li scrive una persona, simbolo e parentesi comprese', function (string $scritto, int $atteso) {
    // Quattro scritture che un amministratore produce davvero, e che prima finivano tutte a zero
    // tranne una — «120,50-», che entrava con il **segno rovesciato**: un credito diventava un
    // debito, uno scarto di € 241 su una riga sola.
    $percorso = modelloDiProva([
        'saldi' => [['B1/1', 'ROSSI MARIO', 0, 'pregresso']],
    ]);

    // ⚠️ **La cella si scrive come testo, e non è un dettaglio di comodo.** `fromArray()`
    // converte «1.234» nel float 1.234 con la convenzione inglese, cioè fa proprio la lettura
    // sbagliata che questo test deve escludere. Una cella formattata testo in Excel — o un
    // valore incollato da un PDF — arriva invece come stringa, ed è quello il caso vero.
    $libro = IOFactory::load($percorso);
    $libro->getSheetByName('4 saldi')->setCellValueExplicit('C6', $scritto, DataType::TYPE_STRING);
    (new Xlsx($libro))->save($percorso);
    $libro->disconnectWorksheets();

    $letto = leggiModello($percorso);

    expect($letto['saldi']->righe[0]->importoCents)->toBe($atteso)
        ->and($letto['esiti']['saldi']->rilievi)->toBe([]);
})->with([
    'simbolo davanti' => ['€ 120,50', 12050],
    'parentesi contabili' => ['(45,00)', -4500],
    'meno in coda' => ['120,50-', -12050],
    'migliaia con lo spazio' => ['1 234,50', 123450],
    // ⚠️ «1.234» senza virgola: `is_numeric()` lo accetta e lo legge all'inglese, cioè
    // millecinquecento volte più piccolo. Su un importo la lettura non è ambigua — in Italia un
    // prezzo con tre decimali e senza virgola non esiste.
    'migliaia col punto, senza decimali' => ['1.234', 123400],
    'e i decimali col punto restano decimali' => ['450.50', 45050],
]);

it('sui millesimi il punto resta un decimale, a differenza degli importi', function () {
    // ⚠️ La stessa scrittura vuol dire due cose diverse nelle due colonne: su un millesimo
    // «1.234» con tre decimali è normale, su un importo no. Per questo la normalizzazione delle
    // migliaia vive solo nel ramo del denaro: applicarla qui moltiplicherebbe per mille.
    $letto = leggiModello(modelloDiProva([
        'tabelle' => [
            'nomi' => ['PROPRIETA GENERALE'],
            'righe' => [['B1/1', '1.234'], ['B1/2', '2.5']],
        ],
    ]));

    expect($letto['tabelle']['PROPRIETA GENERALE']->quote)->toBe([
        '1-A-B1/1' => 1.234,
        '1-A-B1/2' => 2.5,
    ]);
});

it('somma due righe di saldo della stessa persona invece di perderne una', function () {
    // ⚠️ Il modello invitava a scrivere due righe distinte sulla stessa posizione; il livello ne
    // scriveva una e contava l'altra fra i «saltati» — un contatore che ovunque nel prodotto
    // significa «era già in archivio, non l'ho toccato». € 300 sparivano e il lotto si chiudeva
    // «completato».
    $utente = utenteModello();
    $batch = caricaModello($utente, modelloDiProva([
        'saldi' => [
            ['B1/1', 'ROSSI MARIO', 120.50, 'conguaglio 2024/2025'],
            ['B1/1', 'ROSSI MARIO', 300, 'rate non versate 2025'],
        ],
    ]));

    $this->actingAs($utente)->post(route('import.conferma', $batch->uuid));

    expect(Saldo::count())->toBe(1)
        ->and(Saldo::first()->saldo_iniziale)->toBe(42050)
        // Le causali si uniscono: il perché è l'unica cosa che nessuna stampa porta.
        ->and(Saldo::first()->descrizione)->toBe('conguaglio 2024/2025 · rate non versate 2025');
});

it('non cancella gli errori del modello quando nel lotto c\'è anche un file di Danea', function () {
    // ⚠️ Misurato dalla revisione: caricato il solo modello, l'esito dei saldi aveva un errore
    // bloccante e `confermabile = false`; aggiungendo una stampa di Danea lo stesso esito tornava
    // a zero errori e **confermabile = true**. I due errori scritti a mano non erano stati
    // risolti — erano stati cancellati da un `=` secco.
    $utente = utenteModello();

    // Una riga rotta **e** una buona: senza quella buona il modello non porterebbe nessun saldo,
    // e non ci sarebbe nessun conflitto da segnalare — solo l'errore di riga.
    $modello = modelloDiProva([
        'saldi' => [
            ['B1/1', 'ROSSI MARIO', 'n.d.', 'conguaglio'],
            ['B1/2', '', -45, 'credito'],
        ],
    ]);

    $files = [
        new UploadedFile($modello, 'modello.xlsx', null, test: true),
        new UploadedFile(base_path('tests/Fixtures/import/danea/riparto_consuntivo.xls'), 'riparto.xls', null, test: true),
    ];

    $this->actingAs($utente)->post(route('import.store'), ['file' => $files]);

    $esiti = app(App\Services\Import\ImportVerificaService::class)
        ->verifica(ImportBatch::latest()->first())['esiti'];

    $codici = array_map(fn ($r) => $r->codice, $esiti['saldi']->rilievi);

    expect($codici)->toContain('modello.saldo_non_numerico')
        // E il conflitto vero — due file che portano gli stessi saldi — viene detto invece di
        // essere risolto in silenzio prendendo l'ultimo.
        ->and($codici)->toContain('import.due_file_stesso_dato')
        ->and($esiti['saldi']->confermabile())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| La seconda revisione avversariale — i 13 candidati rimasti
|--------------------------------------------------------------------------
|
| I primi otto difetti erano stati verificati e corretti; 17 candidati erano rimasti non
| guardati. Tolti quattro doppioni di quelli già chiusi, i 13 restanti sono passati da un
| revisore incaricato di smentirli: **dodici hanno retto**, uno solo è caduto.
|
| Il fatto che pesa di più: erano stati classificati «media» e «bassa», e due di essi
| ribaltavano i millesimi in archivio. La gravità dichiarata da chi trova un difetto non è
| un'informazione affidabile.
*/

it('non lascia che due colonne con lo stesso nome ne cancellino una', function () {
    // ⚠️ `$quote` è indicizzato per colonna, `$tabelle` per **nome**: la seconda «SCALE»
    // sovrascriveva la prima e i millesimi entravano ribaltati, con zero rilievi. Nemmeno il
    // totale di controllo se ne accorgeva, perché veniva confrontato con la superstite.
    $letto = leggiModello(modelloDiProva([
        'tabelle' => [
            'nomi' => ['SCALE', 'SCALE'],
            'righe' => [['B1/1', 500, ''], ['B1/2', 500, ''], ['NEG', '', 1000]],
        ],
    ]));

    $scale = $letto['tabelle']['SCALE'];

    expect($letto['tabelle'])->toHaveCount(1)
        // Vince la prima, come per i fogli: le colonne si leggono da sinistra.
        ->and($scale->partecipanti())->toBe(2)
        ->and(array_map(fn ($r) => $r->codice, $letto['esiti']['tabelle']->rilievi))
        ->toContain('modello.tabelle_omonime');
});

it('rifiuta una data che non esiste, invece di traboccarla al mese dopo', function () {
    // ⚠️ `createFromFormat` è lasco e non lo dice: «31/02/2026» non fallisce, diventa il 3 marzo.
    // Un esercizio che comincia due giorni dopo sposta la data_inizio di ogni titolarità scritta.
    $letto = leggiModello(modelloDiProva([
        'copertina' => [
            'condominio' => 'CONDOMINIO DATA FINTA',
            'indirizzo' => 'Via Inesistente 1',
            'esercizio' => '2026',
            'data_inizio' => '31/02/2026',
            'data_fine' => '31/12/2026',
        ],
    ]));

    expect($letto['esercizio'])->toBeNull()
        ->and(codiciModello($letto))->toContain('modello.esercizio_senza_date');
});

it('accetta però le date scritte senza lo zero davanti', function () {
    // La controprova: il controllo sul trabocco non deve diventare un rigore su «1/1/2026», che
    // è come una persona scrive.
    $letto = leggiModello(modelloDiProva([
        'copertina' => [
            'condominio' => 'CONDOMINIO SENZA ZERI',
            'indirizzo' => 'Via Breve 1',
            'esercizio' => '',
            'data_inizio' => '1/1/2026',
            'data_fine' => '31/12/2026',
        ],
    ]));

    expect($letto['esercizio']->dataInizio->toDateString())->toBe('2026-01-01');
});

it('tiene la sigla dell\'unità testuale su tutti i fogli che la usano', function () {
    // ⚠️ Il formato testo stava solo sul foglio 1: «016» restava «016» lì, ma negli altri tre —
    // colonna in formato generale — Excel lo salvava come il **numero 16**, e il confronto
    // falliva. Per ogni unità con lo zero davanti sparivano titolari, millesimi e saldi, con un
    // messaggio che diceva «l'unità 16 non compare» su un foglio dove c'era scritto «016».
    $percorso = percorsoTemporaneo();
    (new ModelloManualeWriter)->scriviSu($percorso);

    $libro = IOFactory::load($percorso);

    foreach (['1 unita', '2 persone', '3 tabelle', '4 saldi'] as $nome) {
        expect($libro->getSheetByName($nome)->getStyle('A')->getNumberFormat()->getFormatCode())
            ->toBe('@', "la colonna A del foglio «{$nome}» non è in formato testo");
    }

    // ⚠️ E solo la colonna A: i millesimi devono restare numeri.
    expect($libro->getSheetByName('3 tabelle')->getStyle('B')->getNumberFormat()->getFormatCode())
        ->not->toBe('@');

    $libro->disconnectWorksheets();
    @unlink($percorso);
});

it('dice anche sul foglio delle tabelle di cancellare gli esempi', function () {
    // ⚠️ Era l'unico dei cinque senza quella riga — le sue due righe di esempio non passano da
    // `righe()`, che è il metodo che la scrive — ed è anche l'unico segnale che il parser
    // riconosce. Chi cancellava gli esempi degli altri tre fogli, qui non riceveva istruzioni: in
    // archivio entravano due tabelle millesimali inventate da noi.
    $percorso = percorsoTemporaneo();
    (new ModelloManualeWriter)->scriviSu($percorso);

    $foglio = collect((new SpreadsheetReader)->leggi($percorso))->firstWhere('nome', '3 tabelle');
    @unlink($percorso);

    $testo = collect(range(0, $foglio->numeroRighe() - 1))
        ->flatMap(fn (int $i) => $foglio->riga($i))
        ->implode(' ');

    expect($testo)->toContain('righe di esempio: cancellale');
});

it('non scambia per riga di totale un\'unità che si chiama «#1»', function () {
    // ⚠️ Il riconoscimento era «comincia per #», che è un carattere che l'amministratore può
    // legittimamente usare in una sigla: la riga finiva fra i totali di controllo e i suoi
    // millesimi sparivano dalla tabella.
    $letto = leggiModello(modelloDiProva([
        'unita' => [['#1', '1', 'A', '1', 'T', 'appartamento']],
        'persone' => [['#1', 'ROSSI MARIO', '', 'Via Roma 1, Roma', 'proprietario', 100, '', '']],
        'tabelle' => ['nomi' => ['PROPRIETA GENERALE'], 'righe' => [['#1', 1000]]],
        'saldi' => [],
    ]));

    expect($letto['tabelle']['PROPRIETA GENERALE']->partecipanti())->toBe(1)
        ->and($letto['tabelle']['PROPRIETA GENERALE']->somma())->toBe(1000.0);
});

it('non fa sparire un millesimo che non sa leggere, come se l\'unità non partecipasse', function () {
    // ⚠️ «45,5 mill.» tornava `null` da `numero()`, e `null` in questa colonna significa «non
    // partecipa»: un dato scritto veniva letto come una scelta di non partecipare, in silenzio,
    // su numeri che decidono quanto paga ciascuno.
    $letto = leggiModello(modelloDiProva([
        'tabelle' => [
            'nomi' => ['PROPRIETA GENERALE'],
            'righe' => [['B1/1', '450,5 mill.'], ['B1/2', 549.5]],
        ],
    ]));

    expect($letto['tabelle']['PROPRIETA GENERALE']->partecipanti())->toBe(1)
        ->and(array_map(fn ($r) => $r->codice, $letto['esiti']['tabelle']->rilievi))
        ->toContain('modello.millesimo_non_numerico');
});

it('tratta un foglio svuotato come non fornito, non come rotto', function () {
    // ⚠️ Chi cancella tutto il contenuto di un foglio — esempi e intestazione — sta dicendo
    // «questo dato non ce l'ho», che dalla beta.5 è un salto. Finiva invece in un **errore
    // bloccante** il cui rimedio era «riscarica il modello vuoto»: a chi ha deliberatamente
    // lasciato in bianco i saldi, si diceva di ricominciare da capo.
    $percorso = modelloDiProva();

    $libro = IOFactory::load($percorso);
    $f = $libro->getSheetByName('4 saldi');

    for ($r = 4; $r <= 40; $r++) {
        foreach (range('A', 'J') as $c) {
            $f->setCellValue($c.$r, null);
        }
    }

    (new Xlsx($libro))->save($percorso);
    $libro->disconnectWorksheets();

    $letto = leggiModello($percorso);

    expect($letto['saldi'])->toBeNull()
        ->and($letto['esiti'])->not->toHaveKey('saldi')
        ->and(codiciModello($letto))->not->toContain('modello.intestazione_illeggibile')
        // E il resto entra: è tutto il punto della regola.
        ->and($letto['immobili'])->toHaveCount(3);
});

it('non lascia file orfani nella cartella temporanea a ogni scaricamento', function () {
    // ⚠️ `tempnam()` **crea** il file; concatenargli «.xlsx» produceva un percorso diverso, e lo
    // stub da 0 byte non lo cancellava nessuno. Un file orfano per ogni download, per sempre.
    $prima = count(glob(sys_get_temp_dir().'/modello_*') ?: []);

    $risposta = $this->actingAs(utenteModello())->get(route('import.modello'));
    $risposta->assertOk();
    $risposta->streamedContent();

    expect(count(glob(sys_get_temp_dir().'/modello_*') ?: []))->toBe($prima);
});

it('non mostra il passo dei capitoli a chi compila il modello a mano', function () {
    // ⚠️ Il modello il preventivo **non lo chiede**, ed è una scelta dichiarata. Lo stepper però
    // mostrava «Capitoli di spesa» grigio, con la sua pallina numerata, in mezzo a sette passi
    // verdi: fa cercare un foglio che non esiste e lascia il dubbio di aver saltato qualcosa.
    $utente = utenteModello();
    $batch = caricaModello($utente, modelloDiProva());

    $this->actingAs($utente)->get(route('import.verifica', $batch->uuid))
        ->assertInertia(fn ($p) => $p
            ->has('passi', 7)
            ->where('passi', fn ($passi) => collect($passi)->pluck('chiave')->doesntContain('capitoli'))
        );
});

it('lo mostra invece a chi importa da Danea, dove quella stampa esiste', function () {
    // La differenza non è cosmetica: là il «Bilancio consuntivo per conto» si può esportare, e non
    // averlo è un fatto che vale la pena dire.
    $utente = utenteModello();

    $files = [new UploadedFile(
        base_path('tests/Fixtures/import/danea/riparto_consuntivo.xls'), 'riparto.xls', null, test: true,
    )];

    $this->actingAs($utente)->post(route('import.store'), ['file' => $files]);

    $this->actingAs($utente)->get(route('import.verifica', ImportBatch::latest()->first()->uuid))
        ->assertInertia(fn ($p) => $p
            ->has('passi', 8)
            ->where('passi', fn ($passi) => collect($passi)->pluck('chiave')->contains('capitoli'))
        );
});



/*
|--------------------------------------------------------------------------
| Il giro completo: genera → compila → importa → annulla → reimporta
|--------------------------------------------------------------------------
|
| Scritti il 30/08/2026 aprendo la 1.11.0-beta.6, su una preoccupazione di Vincenzo: «dobbiamo
| stare attenti a non rompere quello che avevamo fatto nella beta.5 perché funzionava bene».
|
| ## Perché stanno in QUESTO file e non in uno loro
|
| Perché è qui che vivono `modelloDiProva()`, `caricaModello()` e `modelloCompilato()`, e Pest
| carica i file di test **uno per uno**: un file nuovo che li chiamasse funzionerebbe lanciando la
| suite intera e morirebbe di errore fatale lanciando solo sé stesso — che è il modo in cui si
| lanciano quando si sta lavorando. L'alternativa era duplicare centoventi righe di costruzione
| del modello, cioè due copie divergenti di «come si compila», che è precisamente il difetto che
| questo progetto insegue da trenta beta.
|
| ## Cosa presidiano, ed è una cosa sola vista da due lati
|
| Il rischio dell'annullamento **non è rompere il parser**: è **lasciare residui**. Un
| annullamento che dimentica qualcosa non si vede subito — si vede alla *seconda* importazione,
| che trova righe «già presenti» e chiede decisioni che nessuno ha mai posto. È la lezione della
| beta.47: *«un'operazione fallita deve lasciare l'archivio come l'ha trovato, o il tentativo
| successivo non è un ritentativo: è un caso nuovo, più difficile del primo»*.
|
| Quindi il giro completo prova **tutte e due** le preoccupazioni con una prova sola: se
| l'annullamento lascia residui è rosso, e se il modello o il parser si rompono è rosso uguale.
|
| ## Cosa NON coprono
|
| - **Non provano il regime B** (importazione dentro un condominio che preesisteva): il modello
|   manuale porta la copertina, quindi crea sempre il condominio. Il caso «scelto e non creato» —
|   quello in cui `import_batches.condominio_id` non basta a decidere — vuole un corpus Danea
|   senza testata, e non passa da qui.
| - **Non provano i rami `unito` e `saltato`**: il giro non incontra duplicati da risolvere. Sui
|   quattro lotti veri a database, misurati il 30/08/2026, **l'unica azione registrata è `creato`**:
|   quei due rami non sono mai stati esercitati, né qui né sui dati reali.
| - **Non dicono niente sui saldi in centesimi oltre ai due valori del modello di prova.**
|
| ## Cosa la sabotatura ha provato, e cosa NON è riuscita a provare
|
| *Scritto il 30/08/2026, perché una prova che non si è vista fallire non è una prova.*
|
| - **Il primo caso morde**: cambiando il nome di un foglio dentro `ModelloManualeWriter` diventa
|   rosso, insieme a mezzo file. È la fedeltà fra le due metà della promessa della beta.5, ed è
|   esattamente ciò che deve presidiare.
| - **Il secondo caso non l'ho fatto diventare rosso sabotando il prodotto**, e la spiegazione non
|   è che non guardi: è che la proprietà è **difesa in profondità**. Tolta di mano la domanda sul
|   duplicato del condominio, si ferma il livello **esercizi**; tolto pure il riconoscimento dei
|   soggetti, si ferma comunque. Ogni livello rifiuta di duplicare per conto proprio, e un
|   sabotaggio a un punto solo sposta la fermata al punto dopo. Misurato: sotto sabotaggio il
|   rapporto passa da uno a due livelli e lo stato resta `parziale`.
| - **Ma l'asserzione sa fallire**, ed è stato verificato con un controllo positivo: facendo
|   importare al secondo giro un condominio **diverso**, il caso diventa rosso su
|   `'condomini' => 2`.
| - ⚠️ **E il tentativo di sabotarlo ha trovato due difetti veri, che rileggere non aveva
|   trovato.** Il primo era nella fotografia, che leggeva `Condominio::first()?->nome` e quindi
|   **non avrebbe visto un secondo condominio**. Il secondo era in `caricaModello()`, che usava
|   `latest()`: vedi il commento là sopra. Finché il sabotaggio non è stato tentato, questi due
|   test passavano **entrambi per il motivo sbagliato**.
*/

/**
 * La fotografia dell'archivio: ciò che deve tornare identico dopo annulla-e-reimporta.
 *
 * ⚠️ **Nessun id, e i valori ordinati.** Due importazioni successive scrivono gli stessi dati con
 * chiavi diverse: un confronto che includesse gli id sarebbe rosso per costruzione, e quello è il
 * modo più veloce per far disattivare una prova. Si confronta ciò che l'amministratore vedrebbe.
 *
 * @return array<string, mixed>
 */
function fotografiaArchivio(): array
{
    $saldi = Saldo::pluck('saldo_iniziale')->map(fn ($v) => (int) $v)->sort()->values()->all();
    $unita = Immobile::pluck('nome')->sort()->values()->all();
    $persone = Anagrafica::pluck('nome')->sort()->values()->all();
    $tabelle = Tabella::pluck('nome')->sort()->values()->all();

    return [
        // ⚠️ **Il conteggio, non solo il nome del primo.** La prima stesura leggeva
        // `Condominio::first()?->nome` e basta: un secondo condominio creato per sbaglio non
        // avrebbe cambiato la fotografia di una virgola. L'ha trovato il tentativo di sabotare
        // la prova, non la rilettura — ed è il motivo per cui il sabotaggio si fa.
        'condomini' => Condominio::count(),
        'condominio' => Condominio::first()?->nome,
        'esercizi' => Esercizio::count(),
        'unita' => $unita,
        'persone' => $persone,
        'tabelle' => $tabelle,
        'saldi' => $saldi,
        'titolarita' => \Illuminate\Support\Facades\DB::table('anagrafica_immobile')->count(),
    ];
}

it('il modello compilato entra in archivio, ed è questa la fotografia che l\'annullamento dovrà saper ripristinare', function () {
    $utente = utenteModello();
    $batch = caricaModello($utente, modelloDiProva());

    $this->actingAs($utente)->post(route('import.conferma', $batch->uuid));

    expect($batch->fresh()->stato)->toBe('completato')
        ->and(fotografiaArchivio())->toBe([
            'condomini' => 1,
            'condominio' => 'CONDOMINIO SCRITTO A MANO',
            'esercizi' => 1,
            // ⚠️ Il nome porta la tipologia davanti alla sigla: è l'importatore a comporlo così,
            // non il file. Misurato, non supposto — la sigla nuda («B1/1») era la mia ipotesi.
            'unita' => ['appartamento B1/1', 'appartamento B1/2', 'negozio NEG'],
            'persone' => ['BIANCHI ANNA', 'GIALLI SPA', 'ROSSI MARIO', 'VERDI LUCA'],
            'tabelle' => ['PROPRIETA GENERALE', 'SCALE'],
            // −45,00 € e 120,50 €, in centesimi interi come vuole la convenzione.
            'saldi' => [-4500, 12050],
            'titolarita' => 4,
        ]);

    // Il registro dice diciassette righe, e **nessun capitolo**: il modello non ha il foglio del
    // preventivo, tolto nella beta.4 perché il preventivo è ciò che l'assemblea sta per
    // deliberare. Se un giorno comparisse un capitolo qui, vorrebbe dire che quel foglio è
    // tornato senza che nessuno l'abbia deciso.
    expect(\Illuminate\Support\Facades\DB::table('import_batch_items')
        ->where('import_batch_id', $batch->id)->count())->toBe(17)
        ->and(\Illuminate\Support\Facades\DB::table('import_batch_items')
            ->where('import_batch_id', $batch->id)->where('livello', 'capitoli')->count())->toBe(0);
});

it('rifare la stessa importazione senza annullarla non duplica niente: si ferma in «parziale»', function () {
    // ⚠️ Questo caso **non riguarda l'annullamento**: fotografa la protezione che c'è **già**, ed
    // è quella che la beta.6 non deve rompere. Misurato il 30/08/2026: il secondo giro non scrive
    // una riga in più e il lotto resta `parziale` invece di dichiararsi completato.
    //
    // Serve anche da controprova al caso qui sotto: se dopo un annullamento il secondo giro si
    // fermasse **allo stesso modo**, vorrebbe dire che l'annullamento non ha tolto niente.
    $utente = utenteModello();

    $primo = caricaModello($utente, modelloDiProva());
    $this->actingAs($utente)->post(route('import.conferma', $primo->uuid));
    $dopoIlPrimo = fotografiaArchivio();

    $secondo = caricaModello($utente, modelloDiProva());
    $this->actingAs($utente)->get(route('import.verifica', $secondo->uuid));
    $this->actingAs($utente)->post(route('import.conferma', $secondo->uuid));

    expect($secondo->fresh()->stato)->toBe('parziale')
        ->and(fotografiaArchivio())->toBe($dopoIlPrimo);
});

it('dopo l\'annullamento, la stessa importazione rifatta ridà la fotografia identica', function () {
    // ⚠️ **L'annullamento chiede «Elimina condomini», non «Crea condomini».** In regime A disfare
    // vuol dire cancellare un condominio, e chi non può cancellarne uno dalla sua scheda non deve
    // poterlo fare da qui: è la regola della beta.71 — *due strade per la stessa cosa devono dare
    // lo stesso esito* — applicata al cancello invece che all'esito.
    Permission::firstOrCreate(['name' => 'Elimina condomini', 'guard_name' => 'web']);
    $utente = utenteModello();
    $utente->givePermissionTo('Elimina condomini');
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    $utente = $utente->fresh();

    $primo = caricaModello($utente, modelloDiProva());
    $this->actingAs($utente)->post(route('import.conferma', $primo->uuid));
    $riferimento = fotografiaArchivio();

    $this->actingAs($utente)->delete(route('import.annulla', $primo->uuid))->assertRedirect();

    // 1. L'archivio è tornato vuoto. Non «quasi»: le titolarità e le anagrafiche sono la parte
    //    che il registro non sa raggiungere da solo (§3.1 e §3.2 della scheda), quindi sono
    //    esattamente quelle che un annullamento incompleto lascia indietro.
    expect(Condominio::count())->toBe(0)
        ->and(Immobile::count())->toBe(0)
        ->and(Tabella::count())->toBe(0)
        ->and(Saldo::count())->toBe(0)
        ->and(\Illuminate\Support\Facades\DB::table('anagrafica_immobile')->count())->toBe(0)
        ->and($primo->fresh()->stato)->not->toBe('completato');

    // 2. E il secondo giro è un ritentativo vero, non un caso nuovo: arriva in fondo — non in
    //    `parziale` come nel caso qui sopra — e ricostruisce la stessa identica fotografia.
    $secondo = caricaModello($utente, modelloDiProva());
    $this->actingAs($utente)->get(route('import.verifica', $secondo->uuid));
    $this->actingAs($utente)->post(route('import.conferma', $secondo->uuid));

    expect($secondo->fresh()->stato)->toBe('completato')
        ->and(fotografiaArchivio())->toBe($riferimento);
})->skip(
    // ⚠️ **Uno `skip` con la condizione scritta dentro**, che si accende da solo il giorno in cui
    // la rotta esiste: è la forma che la beta.43 ha promosso, e l'opposto dello `skip` con
    // motivazione «si verifica a mano» che la beta.75 ha vietato.
    //
    // ⚠️ **Ma ha un difetto che va detto:** se l'annullamento prenderà un nome di rotta diverso da
    // `import.annulla`, questa condizione resterà vera per sempre e la prova non si accenderà —
    // cioè diventerebbe una guardia verde che non guarda niente, che è il difetto che questa
    // stessa giornata ha corretto in `FlussoDiLavoroNonRestaIndietroTest`. Chi costruisce
    // l'annullamento controlli **questa riga** prima di dichiararlo finito.
    fn () => ! \Illuminate\Support\Facades\Route::has('import.annulla'),
    'L\'annullamento non esiste ancora: scheda in docs/annullamento_importazione.md, Coda 96.'
);











it('un\'importazione appena fatta è sempre annullabile: nessun impedimento scatta da solo', function () {
    // ⚠️ **È la guardia dell'elenco degli impedimenti, e senza di lei quell'elenco è un'ipotesi.**
    //
    // `AnnullamentoImportazione::SEGNI_DI_LAVORO` è una lista scritta a mano: dice quali tabelle
    // significano «qualcuno ci ha lavorato sopra». Ha **due** modi di sbagliare, opposti:
    //
    // - **corta** — e allora l'annullamento cancella in silenzio roba che non ha importato. È il
    //   difetto che la revisione avversariale del 30/08/2026 ha trovato: ne sorvegliava sei su
    //   ventuno tabelle a cascata, e sparivano documenti, comunicazioni, segnalazioni e i
    //   contributi già versati;
    // - **lunga** — e allora nomina qualcosa che **l'importazione stessa crea**, e da quel momento
    //   nessuna importazione è più annullabile: la funzione muore senza che un test si accenda.
    //
    // Questo caso presidia il secondo verso, che è quello invisibile. Il primo lo presidia la
    // domanda «cosa scende a cascata», e va rifatta quando si aggiunge una tabella al condominio.
    $utente = utenteModello();
    $batch = caricaModello($utente, modelloDiProva());
    $this->actingAs($utente)->post(route('import.conferma', $batch->uuid));

    $verdetto = app(\App\Services\Import\AnnullamentoImportazione::class)->verdetto($batch->fresh());

    expect($verdetto->impedimenti)->toBe([],
        'Un\'importazione appena conclusa risulta già non annullabile: '.
        'una voce di SEGNI_DI_LAVORO nomina qualcosa che l\'importazione crea da sé.'
    )->and($verdetto->possibile)->toBeTrue();
});
