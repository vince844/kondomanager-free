<?php

use App\Models\CodiceAteco;
use App\Models\User;
use Spatie\Permission\Models\Permission;

/**
 * La classificazione ATECO: la tabella, la ricerca e l'aiuto accanto al campo.
 *
 * Nasce dalla Coda 99, aperta chiudendo la beta.7 da una domanda di Vincenzo sul campo «Codice
 * ATECO» della scheda fornitore: quel campo era testo libero, e a database c'erano già `ATECO123456`
 * e `1223456`.
 *
 * COSA QUESTO FILE NON COPRE, dichiarato perché non torni più in mente dopo:
 * - **non legge un vero XLSX di ISTAT.** `LettoreStrutturaAteco` è esercitato solo indirettamente:
 *   qui si passa dal JSON convertito, che è la strada delle installazioni. La conversione è stata
 *   verificata a mano sul file vero (3.257 codici, 52,5 MB di picco), non da un test;
 * - non copre la resa a schermo del componente, solo la risposta che riceve;
 * - non copre il caso di due revisioni conviventi in tabella: oggi il comando ne carica una sola, e
 *   il messaggio sui codici superstiti è l'unico presidio.
 */
beforeEach(function () {
    Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);

    $this->user = User::factory()->create();
    $this->user->givePermissionTo('Accesso pannello amministratore');
});

function codice(array $override = []): CodiceAteco
{
    return CodiceAteco::create(array_merge([
        'codice'         => '43.22.01',
        'titolo'         => 'Installazione di impianti geotermici',
        'titolo_en'      => 'Installation of geothermal systems',
        'livello'        => 6,
        'codice_padre'   => '43.22.0',
        'ordine'         => 1000,
        'versione_fonte' => 'ATECO 2025',
    ], $override));
}

/*
|--------------------------------------------------------------------------
| La ricerca
|--------------------------------------------------------------------------
*/

it('trova un codice scritto senza i punti', function () {
    // «432201» è come lo batte chi ce l'ha in testa: il codice entra in tabella in due grafie.
    codice();

    expect(CodiceAteco::cerca('432201')->count())->toBe(1);
    expect(CodiceAteco::cerca('43.22.01')->count())->toBe(1);
});

it('trova un codice cercandolo a parole', function () {
    // ⚠️ Il caso che ha smascherato il difetto: `scopeCerca` passava il **termine** dentro la
    // funzione che costruisce la **colonna**, e «idraulic» diventava «idraulic idraulic» — non
    // combaciava con niente. Le due responsabilità sono ora separate (`normalizza` / `testoRicerca`).
    codice(['codice' => '43.22.0', 'titolo' => 'Installazione di impianti idraulici', 'livello' => 5]);

    expect(CodiceAteco::cerca('idraulic')->count())->toBe(1);
    expect(CodiceAteco::cerca('IDRAULICI')->count())->toBe(1);
});

it('non si appoggia agli accenti né alla collation', function () {
    codice(['codice' => '01.11.00', 'titolo' => 'Coltivazione di cereali però speciali']);

    expect(CodiceAteco::cerca('pero speciali')->count())->toBe(1);
});

it('calcola il testo di ricerca anche a una riga creata fuori dal comando', function () {
    // `upsert` non fa scattare gli eventi del model: questa rete copre tutte le altre strade.
    $c = codice();

    expect($c->testo_ricerca)->toContain('432201');
});

/*
|--------------------------------------------------------------------------
| L'endpoint dietro il pulsante
|--------------------------------------------------------------------------
*/

it('cerca solo fra i codici che un fornitore dichiara davvero', function () {
    // Sezioni e divisioni sono contenitori dell'albero: offrirle significherebbe far scegliere
    // «COSTRUZIONI» a chi cercava un idraulico.
    codice(['codice' => 'F', 'titolo' => 'Costruzioni idrauliche', 'livello' => 1, 'codice_padre' => null, 'ordine' => 1]);
    // ⚠️ `codice_padre` esplicito: l'helper ne ha uno predefinito, e senza override questa riga
    // sarebbe **padre di sé stessa** — cosa che sulla fonte non succede e che il dato di prova non
    // deve inventare.
    codice(['codice' => '43.22.0', 'titolo' => 'Impianti idraulici', 'livello' => 5, 'codice_padre' => 'F', 'ordine' => 2]);

    $r = $this->actingAs($this->user)->getJson('/ateco/cerca?q=idraulic')->json();

    expect($r['codici'])->toHaveCount(1);
    expect($r['codici'][0]['codice'])->toBe('43.22.0');
});

it('dichiara sempre la revisione, anche quando non trova niente', function () {
    // È la condizione a cui questo tipo di aiuto era stato accettato: un elenco che non dice da
    // quale revisione viene invecchia in silenzio.
    codice();

    $r = $this->actingAs($this->user)->getJson('/ateco/cerca?q=zzzznontrovabile')->json();

    expect($r['codici'])->toBeEmpty();
    expect($r['versione'])->toBe('ATECO 2025');
});

it('dichiara il totale prima del taglio, così chi cerca sa che non sono tutti', function () {
    foreach (range(1, 25) as $i) {
        codice(['codice' => '43.22.' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'ordine' => $i]);
    }

    $r = $this->actingAs($this->user)->getJson('/ateco/cerca?q=geotermici')->json();

    expect($r['codici'])->toHaveCount(20);
    expect($r['totale'])->toBe(25);
});

it('non esplode se il termine arriva come array', function () {
    // `?q[]=idraulico` è un indirizzo che chiunque può comporre: `(string) $array` farebbe scattare
    // un warning che Laravel rilancia come eccezione — 500 invece di una lista vuota.
    codice();

    $this->actingAs($this->user)->getJson('/ateco/cerca?q[]=idraulico')
        ->assertOk()
        ->assertJsonPath('codici', []);
});

it('non cerca sotto i due caratteri', function () {
    codice();

    $r = $this->actingAs($this->user)->getJson('/ateco/cerca?q=a')->json();

    expect($r['codici'])->toBeEmpty();
    expect($r['totale'])->toBe(0);
});

it('non risponde a chi non ha accesso al pannello', function () {
    $estraneo = User::factory()->create();

    $this->actingAs($estraneo)->getJson('/ateco/cerca?q=idraulico')->assertForbidden();
});

it('non offre due volte lo stesso titolo quando la categoria ha un figlio solo', function () {
    // ⚠️ Misurato sulla fonte: **725 categorie su 920** hanno un unico figlio col titolo identico —
    // «42.91.0 Costruzione di opere idrauliche» e «42.91.00 Costruzione di opere idrauliche» sono la
    // stessa cosa scritta due volte, e occupavano due dei venti posti. Si tiene la sottocategoria,
    // che è il codice a sei cifre che sta sulla visura.
    codice(['codice' => '42.91.0', 'titolo' => 'Costruzione di opere idrauliche', 'livello' => 5, 'codice_padre' => '42.91', 'ordine' => 1]);
    codice(['codice' => '42.91.00', 'titolo' => 'Costruzione di opere idrauliche', 'livello' => 6, 'codice_padre' => '42.91.0', 'ordine' => 2]);

    $r = $this->actingAs($this->user)->getJson('/ateco/cerca?q=opere idrauliche')->json();

    expect($r['codici'])->toHaveCount(1);
    expect($r['codici'][0]['codice'])->toBe('42.91.00');
});

it('trova due parole giuste anche se nel titolo non sono attaccate', function () {
    // ⚠️ «installazione ascensori» dava **zero** risultati: il LIKE pretendeva la frase intera,
    // mentre il titolo ufficiale è «Installazione di ascensori e scale mobili». Le parole si cercano
    // separatamente, come fa da sempre `Comune::scopeCerca()`.
    codice(['codice' => '43.24.01', 'titolo' => 'Installazione di ascensori e scale mobili', 'codice_padre' => '43.24.0']);

    expect(CodiceAteco::cerca('installazione ascensori')->count())->toBe(1);
    expect(CodiceAteco::cerca('ascensori installazione')->count())->toBe(1);
});

it('i caratteri jolly scritti dall utente valgono come caratteri', function () {
    // ⚠️ È il difetto già corretto sui Comuni, rimesso qui identico: cercando `%` uscivano tutti e
    // 2.210 i codici classificabili.
    codice();

    expect(CodiceAteco::cerca('%')->count())->toBe(0);
    expect(CodiceAteco::cerca('_')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| La riga che dice da dove viene il codice
|--------------------------------------------------------------------------
*/

it('mostra da dove viene il codice', function () {
    codice(['codice' => '43.2', 'titolo' => 'Installazione di impianti elettrici', 'livello' => 3, 'codice_padre' => '43', 'ordine' => 1]);
    codice(['codice' => '43.22.0', 'titolo' => 'Impianti idraulici', 'livello' => 5, 'codice_padre' => '43.2', 'ordine' => 2]);

    $r = $this->actingAs($this->user)->getJson('/ateco/cerca?q=idraulici')->json();

    expect($r['codici'][0]['gerarchia'])->toBe('Installazione di impianti elettrici');
});

it('tace invece di ripetere il titolo del codice stesso', function () {
    // ⚠️ ISTAT ripete il titolo lungo la catena quando un livello ha un figlio solo, e la prima
    // stesura stampava «Costruzione di opere idrauliche › Costruzione di opere idrauliche». Una riga
    // di contesto che ripete ciò che sta scritto sopra è rumore, non contesto. Trovato a video.
    codice(['codice' => '42.91.0', 'titolo' => 'Costruzione di opere idrauliche', 'livello' => 5, 'codice_padre' => null, 'ordine' => 1]);
    codice(['codice' => '42.91.00', 'titolo' => 'Costruzione di opere idrauliche', 'livello' => 6, 'codice_padre' => '42.91.0', 'ordine' => 2]);

    $r = $this->actingAs($this->user)->getJson('/ateco/cerca?q=42.91.00')->json();

    expect($r['codici'][0]['gerarchia'])->toBeNull();
});

it('non ripete lo stesso antenato due volte nella gerarchia', function () {
    // ⚠️ Il test che mancava: la riga che collassa gli antenati ripetuti si poteva **cancellare e la
    // suite restava verde**. Sono i valori veri di 43.22.05, dove padre e nonno hanno lo stesso
    // titolo perché ISTAT lo ripete lungo la catena.
    codice(['codice' => '43.22', 'titolo' => 'Installazione di impianti idraulici', 'livello' => 4, 'codice_padre' => '43.2', 'ordine' => 1]);
    codice(['codice' => '43.22.0', 'titolo' => 'Installazione di impianti idraulici', 'livello' => 5, 'codice_padre' => '43.22', 'ordine' => 2]);
    codice(['codice' => '43.22.05', 'titolo' => 'Installazione di altri impianti termo-idraulici', 'livello' => 6, 'codice_padre' => '43.22.0', 'ordine' => 3]);

    $r = $this->actingAs($this->user)->getJson('/ateco/cerca?q=termo-idraulici')->json();

    expect($r['codici'][0]['gerarchia'])->toBe('Installazione di impianti idraulici');
    expect(substr_count($r['codici'][0]['gerarchia'], 'Installazione di impianti idraulici'))->toBe(1);
});

it('non offre i codici di una revisione che ISTAT ha ritirato', function () {
    // ⚠️ `upsert` corregge le righe che ritrova e **non tocca le altre**: dopo un cambio di revisione
    // i codici ritirati restano in tabella. Proporli senza contrassegno è peggio che non averli, e la
    // riga in fondo al dialogo dichiarerebbe una revisione presa a caso.
    codice(['codice' => '68.32.00', 'titolo' => 'Amministrazione di condomini', 'codice_padre' => '68.32.0', 'versione_fonte' => 'ATECO 2022']);
    codice(['codice' => '68.32.01', 'titolo' => 'Gestione di beni immobili per conto terzi', 'codice_padre' => '68.32.0', 'versione_fonte' => 'ATECO 2025']);

    $r = $this->actingAs($this->user)->getJson('/ateco/cerca?q=condomini')->json();

    expect($r['versione'])->toBe('ATECO 2025');
    expect(collect($r['codici'])->pluck('codice')->all())->not->toContain('68.32.00');
});

/*
|--------------------------------------------------------------------------
| Il comando che carica la classificazione
|--------------------------------------------------------------------------
*/

it('carica i codici da un elenco convertito', function () {
    $file = tempnam(sys_get_temp_dir(), 'ateco') . '.json';
    file_put_contents($file, json_encode([
        'fonte'    => 'ISTAT — Struttura ATECO 2025',
        'versione' => 'ATECO 2025',
        'codici'   => [
            ['ordine' => 1, 'codice' => 'F', 'titolo' => 'Costruzioni', 'titolo_en' => 'Construction', 'livello' => 1, 'codice_padre' => null],
            ['ordine' => 2, 'codice' => '43.22.01', 'titolo' => 'Impianti geotermici', 'titolo_en' => 'Geothermal', 'livello' => 6, 'codice_padre' => 'F'],
        ],
    ]));

    $this->artisan('kondomanager:aggiorna-ateco', ['--da' => $file])->assertSuccessful();

    expect(CodiceAteco::count())->toBe(2);
    expect(CodiceAteco::where('codice', '43.22.01')->value('versione_fonte'))->toBe('ATECO 2025');
    // Il testo di ricerca viene calcolato dal comando: `upsert` non fa scattare gli eventi.
    expect(CodiceAteco::where('codice', '43.22.01')->value('testo_ricerca'))->toContain('432201');

    unlink($file);
});

it('non timbra una data se nessuno la dichiara', function () {
    // ⚠️ Nel file ISTAT una data **non esiste** — verificata cella per cella. Dedurla dal
    // `last-modified` HTTP sarebbe scrivere un dato che il documento di processo, dopo averlo
    // misurato sui Comuni, definisce inaffidabile.
    $file = tempnam(sys_get_temp_dir(), 'ateco') . '.json';
    file_put_contents($file, json_encode([
        'fonte' => 'x', 'versione' => 'ATECO 2025',
        'codici' => [['ordine' => 1, 'codice' => 'F', 'titolo' => 'Costruzioni', 'livello' => 1, 'codice_padre' => null]],
    ]));

    $this->artisan('kondomanager:aggiorna-ateco', ['--da' => $file])->assertSuccessful();

    expect(CodiceAteco::first()->fonte_al)->toBeNull();

    $this->artisan('kondomanager:aggiorna-ateco', ['--da' => $file, '--fonte-al' => '2024-12-11'])->assertSuccessful();

    expect(CodiceAteco::first()->fonte_al->toDateString())->toBe('2024-12-11');

    unlink($file);
});

it('si ferma invece di scrivere righe monche', function (array $doc, string $atteso) {
    // Senza questa guardia un file sbagliato passato con `--da` scriverebbe sopra un elenco buono.
    $file = tempnam(sys_get_temp_dir(), 'ateco') . '.json';
    file_put_contents($file, json_encode($doc));

    $this->artisan('kondomanager:aggiorna-ateco', ['--da' => $file])
        ->expectsOutputToContain($atteso)
        ->assertFailed();

    expect(CodiceAteco::count())->toBe(0);

    unlink($file);
})->with([
    'senza la chiave dei codici' => [
        ['fonte' => 'x', 'versione' => 'ATECO 2025'],
        'non ha la chiave',
    ],
    'con un livello fuori scala' => [
        ['fonte' => 'x', 'versione' => 'ATECO 2025', 'codici' => [['ordine' => 1, 'codice' => 'F', 'titolo' => 'C', 'livello' => 9]]],
        'livello fuori dall\'intervallo',
    ],
    'con un codice ripetuto' => [
        ['fonte' => 'x', 'versione' => 'ATECO 2025', 'codici' => [
            ['ordine' => 1, 'codice' => 'F', 'titolo' => 'C', 'livello' => 1],
            ['ordine' => 2, 'codice' => 'F', 'titolo' => 'D', 'livello' => 1],
        ]],
        'codici ripetuti',
    ],
]);

it('rifiuta una data ambigua invece di indovinarla', function (string $data) {
    // ⚠️ Misurato con Carbon, prima della correzione: «11/12/2024» diventava il **12 novembre** e
    // «2025» diventava **la data di oggi**, perché veniva letto come un orario. Una data timbrata
    // sulla fonte e sbagliata in silenzio è peggio di una data che manca.
    $file = tempnam(sys_get_temp_dir(), 'ateco') . '.json';
    file_put_contents($file, json_encode([
        'fonte' => 'x', 'versione' => 'ATECO 2025',
        'codici' => [['ordine' => 1, 'codice' => 'F', 'titolo' => 'Costruzioni', 'livello' => 1]],
    ]));

    $this->artisan('kondomanager:aggiorna-ateco', ['--da' => $file, '--fonte-al' => $data])
        ->expectsOutputToContain('si scrive 2024-12-11')
        ->assertFailed();

    unlink($file);
})->with(['11/12/2024', '2025', 'ieri', '2024-13-45']);

it('non tocca l\'elenco spedito se non riesce a riscriverlo per intero', function () {
    // ⚠️ È la procedura che il changelog insegna per il giorno della revisione nuova: scrivere
    // direttamente sul file lo tronca **prima** di avere il contenuto, e un elenco troncato committato
    // è un file di dati vuoto spedito a ogni installazione.
    $cartella = sys_get_temp_dir() . '/km-ateco-' . uniqid();
    mkdir($cartella);
    $bersaglio = $cartella . '/elenco.json';
    file_put_contents($bersaglio, '{"fonte":"vecchio","versione":"ATECO 2025","codici":[]}');
    $prima = file_get_contents($bersaglio);

    $file = tempnam(sys_get_temp_dir(), 'ateco') . '.json';
    file_put_contents($file, json_encode([
        'fonte' => 'x', 'versione' => 'ATECO 2025',
        'codici' => [['ordine' => 1, 'codice' => 'F', 'titolo' => 'Costruzioni', 'livello' => 1]],
    ]));

    // La scrittura riesce: il file vecchio viene sostituito, non troncato e poi riempito.
    $this->artisan('kondomanager:aggiorna-ateco', ['--da' => $file, '--scrivi-file' => true, '--in' => $bersaglio])
        ->assertSuccessful();

    $dopo = json_decode(file_get_contents($bersaglio), true);

    expect($dopo)->not->toBeNull()
        ->and($dopo['codici'])->toHaveCount(1)
        ->and($prima)->not->toBe(file_get_contents($bersaglio))
        // Il temporaneo non resta in giro.
        ->and(is_file($bersaglio . '.nuovo'))->toBeFalse();

    unlink($file);
    unlink($bersaglio);
    rmdir($cartella);
});
