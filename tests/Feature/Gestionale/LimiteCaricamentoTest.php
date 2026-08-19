<?php

/**
 * Il limite di caricamento dei file: tre numeri diversi, e vinceva quello che nessuno dichiarava.
 *
 * ## Origine: segnalazione dal forum, 18/08/2026
 *
 * *«Cercando di fare l'upload di un file grande 4376KB si ottiene il generico errore "L'upload di
 * file è fallito", nonostante è riportato un limite massimo di 10MB (provando con un file grande
 * 1926KB l'upload va a buon fine).»*
 *
 * I numeri in gioco erano tre:
 *
 * | Dove | Limite |
 * | :--- | :--- |
 * | la schermata | 10 MB, scritto a mano nel testo |
 * | la validazione | `max:20480`, cioè 20 MB |
 * | il server di chi segnala | ~2 MB (`upload_max_filesize` di PHP) |
 *
 * A vincere è sempre il terzo, ed è l'unico che non dichiaravamo. Quando il file lo supera, **non
 * arriva mai al programma**: lo scarta PHP, e a Laravel resta solo la regola `uploaded`, che produce
 * il messaggio generico che l'amministratore ha visto.
 *
 * ## Cosa questo file prova
 *
 * Che il limite mostrato è quello **effettivo**, cioè il più basso dei tre, e che quando il file
 * viene scartato il messaggio dice il numero vero invece di «l'upload è fallito».
 */

use App\Support\LimiteCaricamento;
use Illuminate\Support\Facades\Validator;

it('il limite effettivo è il più basso dei tre, non quello scritto nel testo', function () {
    $effettivo = LimiteCaricamento::megabyte();

    expect($effettivo)->toBeLessThanOrEqual(LimiteCaricamento::daIni('upload_max_filesize') / 1048576)
        ->and($effettivo)->toBeLessThanOrEqual(LimiteCaricamento::daIni('post_max_size') / 1048576)
        ->and($effettivo)->toBeLessThanOrEqual(20.0); // il tetto della nostra regola di validazione
});

it('sa leggere le sigle di php.ini, che non sono numeri', function () {
    // `upload_max_filesize = 2M` non è «2»: è la ragione per cui un confronto ingenuo con un numero
    // di byte darebbe sempre lo stesso esito sbagliato.
    expect(LimiteCaricamento::interpreta('2M'))->toBe(2 * 1048576)
        ->and(LimiteCaricamento::interpreta('128M'))->toBe(128 * 1048576)
        ->and(LimiteCaricamento::interpreta('1G'))->toBe(1073741824)
        ->and(LimiteCaricamento::interpreta('512K'))->toBe(512 * 1024)
        ->and(LimiteCaricamento::interpreta('-1'))->toBe(PHP_INT_MAX);
});

it('in php.ini «0» significa «nessun limite», non «zero byte»', function () {
    // ⚠️ Reperto della Fase 1-bis. `post_max_size = 0` è la configurazione **documentata da PHP**
    // per togliere il limite — la scelta di chi vuole caricamenti grandi. La prima stesura la
    // leggeva come zero byte: la schermata annunciava «max 0 MB» e respingeva qualunque file,
    // anche da 1 KB. Cioè la correzione del limite si sarebbe trasformata in un blocco totale
    // proprio sull'installazione di chi il limite lo aveva tolto apposta.
    expect(LimiteCaricamento::interpreta('0'))->toBe(PHP_INT_MAX)
        ->and(LimiteCaricamento::interpreta('0M'))->toBe(PHP_INT_MAX)
        ->and(LimiteCaricamento::interpreta('-1'))->toBe(PHP_INT_MAX);
});

it('legge le sigle minuscole e con spazi, che php.ini ammette', function () {
    expect(LimiteCaricamento::interpreta('2m'))->toBe(2 * 1048576)
        ->and(LimiteCaricamento::interpreta(' 8M '))->toBe(8 * 1048576)
        ->and(LimiteCaricamento::interpreta('1g'))->toBe(1073741824);
});

it('il limite del server è cosa diversa dal tetto che ci diamo noi', function () {
    // ⚠️ Reperto della Fase 1-bis: il messaggio della regola `uploaded` scatta quando è **PHP** ad
    // aver scartato il file, e in quel momento il nostro tetto di 20 MB non è mai entrato in gioco.
    // Dichiararlo lì portava il limite dei documenti su schermate che ne hanno altri — l'importatore
    // ne dichiara 25 — cioè sostituiva una bugia generica con una bugia informata.
    $server = min(LimiteCaricamento::daIni('upload_max_filesize'), LimiteCaricamento::daIni('post_max_size'));

    expect(LimiteCaricamento::etichettaServer())->toContain('MB')
        ->and(LimiteCaricamento::byteServer())->toBe($server);
});

it('la regola di validazione non promette più di quanto il server accetti', function () {
    // Prima la regola diceva 20 MB su qualunque installazione. Su un server da 2 MB era una
    // promessa che il server smentiva prima ancora che la regola venisse valutata.
    expect(LimiteCaricamento::regolaMax())->toBeLessThanOrEqual(20480)
        ->and(LimiteCaricamento::regolaMax())->toBe((int) floor(LimiteCaricamento::megabyte() * 1024));
});

it('quando il file viene scartato dal server, il messaggio dice il limite vero', function () {
    // Si riproduce il caso reale: un file che PHP ha già respinto arriva con il codice d'errore
    // `UPLOAD_ERR_INI_SIZE`, `isValid()` è falso e scatta la regola `uploaded` — la stessa che
    // produceva «L'upload di file è fallito» sul server di chi ha segnalato.
    // NB: `trans()` da solo non basterebbe, perché il sostitutore di `:limite` agisce durante la
    // validazione e non nella semplice traduzione. Era l'errore della prima stesura di questo test.
    $scartato = new \Illuminate\Http\UploadedFile(
        __FILE__, 'grande.pdf', 'application/pdf', UPLOAD_ERR_INI_SIZE, false
    );

    $validatore = \Illuminate\Support\Facades\Validator::make(
        ['documento' => $scartato],
        ['documento' => 'file'],
    );

    expect($validatore->fails())->toBeTrue();

    $messaggio = $validatore->errors()->first('documento');

    // `etichettaServer()` e non `etichetta()`: quando questa regola scatta il file l'ha scartato
    // **PHP**, e il nostro tetto di 20 MB non è mai entrato in gioco. La prima stesura del test
    // fissava il comportamento sbagliato, ed è caduta quando è stato corretto — come doveva.
    expect($messaggio)->toContain(LimiteCaricamento::etichettaServer())
        ->and($messaggio)->toContain('upload_max_filesize')
        ->and($messaggio)->not->toContain(':limite');
});

/**
 * ## Le due schermate della stessa funzione, e la seconda che era rimasta indietro
 *
 * La segnalazione parlava del **caricamento**, e la correzione è partita da lì. Ma il documento di
 * un'unità si carica da una schermata e si sostituisce da un'altra, e la seconda continuava a
 * scrivere «Max 20MB» a mano mentre `UpdateImmobileDocumentoRequest` validava già con il limite del
 * server: la stessa promessa non mantenuta che il forum aveva segnalato, una schermata più in là.
 *
 * Questi due test tengono le due schermate insieme. Non provano un numero — il numero dipende dal
 * `php.ini` della macchina che li esegue — ma che entrambe **ricevano quello del server**.
 */
describe('il limite arriva a tutte e due le schermate del documento', function () {
    beforeEach(function () {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $ruolo = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        foreach (['Accesso pannello amministratore', \App\Enums\Permission::PUBLISH_ARCHIVE_DOCUMENTS->value] as $nome) {
            $ruolo->givePermissionTo(
                \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web'])
            );
        }

        $this->user = \App\Models\User::factory()->create();
        $this->user->assignRole($ruolo);

        $this->condominio = \App\Models\Condominio::factory()->create();
        \App\Models\Esercizio::factory()->create(['condominio_id' => $this->condominio->id]);
        \App\Models\Gestionale\PianoConto::factory()->create(['condominio_id' => $this->condominio->id]);

        $this->immobile = \App\Models\Immobile::create([
            'condominio_id' => $this->condominio->id,
            'nome'          => 'Appartamento 1',
            'interno'       => '1',
        ]);
    });

    it('la schermata di caricamento riceve il limite del server', function () {
        $this->actingAs($this->user)
            ->get(route('admin.gestionale.immobili.documenti.create', [
                'condominio' => $this->condominio->id,
                'immobile'   => $this->immobile->id,
            ]))
            ->assertInertia(fn ($page) => $page->where('limiteFile', LimiteCaricamento::etichetta()));
    });

    it('anche la schermata di modifica lo riceve, e non lo scrive più a mano', function () {
        $documento = $this->immobile->documenti()->create([
            'name'         => 'Visura catastale',
            'path'         => 'documenti/visura.pdf',
            'mime_type'    => 'application/pdf',
            'file_size'    => 122880,
            'is_published' => true,
            'is_approved'  => true,
            'created_by'   => $this->user->id,
        ]);

        $this->actingAs($this->user)
            ->get(route('admin.gestionale.immobili.documenti.edit', [
                'condominio' => $this->condominio->id,
                'immobile'   => $this->immobile->id,
                'documento'  => $documento->id,
            ]))
            ->assertInertia(fn ($page) => $page->where('limiteFile', LimiteCaricamento::etichetta()));
    });
});

/**
 * ## Il tetto non è uno solo: ogni porta ha il suo, e nessuna deve promettere più del server
 *
 * Aggiunto nella beta.60, chiudendo la coda ㊺. Fino alla .59 la classe aveva **un tetto solo**,
 * 20 MB, scritto in una costante privata — perfetto per i documenti, sbagliato per tutti gli altri:
 *
 * | Porta | Tetto suo | Applicando il tetto unico |
 * | :--- | :--- | :--- |
 * | documenti (PDF) | 20 MB | — |
 * | allegato fattura (PDF, XML, P7M da scanner) | 10 MB | lo **alzerebbe** a 20 |
 * | importatore (fogli di calcolo) | 25 MB | lo **abbasserebbe** a 20, −20% |
 * | firma di stampa (immagine 180×80 pt) | 2 MB | lo **alzerebbe** a 20 |
 *
 * Il difetto da chiudere non era «tutti a 20»: era **«nessuno promette più di quanto il server
 * accetti»**. Sono due cose diverse, e confonderle avrebbe rotto l'importatore — la voce di punta
 * della 1.10 — per correggere un difetto di forma.
 */
describe('ogni porta porta il suo tetto', function () {
    it('senza argomenti resta il tetto dei documenti, cioè quello di prima', function () {
        expect(LimiteCaricamento::regolaMax())->toBe(LimiteCaricamento::regolaMax(20.0));
    });

    it('un tetto più basso del server vince: la porta non promette più di quanto vuole', function () {
        // Il server di sviluppo è generoso; una porta che si dà 2 MB deve restare a 2.
        expect(LimiteCaricamento::regolaMax(2.0))->toBeLessThanOrEqual(2 * 1024)
            ->and(LimiteCaricamento::regolaMax(10.0))->toBeLessThanOrEqual(10 * 1024);
    });

    it('un tetto più alto NON scavalca il server, che è tutto il senso della classe', function () {
        // È l'invariante che non deve rompersi allargando: l'importatore chiede 25 MB, ma su un
        // server che ne accetta 2 il limite resta 2.
        $server = min(
            LimiteCaricamento::daIni('upload_max_filesize'),
            LimiteCaricamento::daIni('post_max_size'),
        );

        expect(LimiteCaricamento::regolaMax(25.0) * 1024)->toBeLessThanOrEqual($server);
    });

    it('l\'etichetta e la regola dicono lo stesso numero, su ogni tetto', function () {
        // ⚠️ Questo test esisteva già e **nascondeva il difetto**: usava una tolleranza di 0,1 MB,
        // che è esattamente la misura dello scarto che doveva prendere. La revisione della .60 ha
        // misurato che l'etichetta dissentiva dalla regola su **209 valori su 299**, e in uno
        // scenario reale l'utente vedeva tre numeri per lo stesso limite.
        //
        // Adesso il confronto è esatto e gira su tutta la scala, non su un valore comodo.
        $diversi = [];

        for ($mb = 0.1; $mb <= 30.0; $mb += 0.1) {
            $tetto = round($mb, 1);
            $etichetta = (float) str_replace(',', '.', str_replace(' MB', '', LimiteCaricamento::etichetta($tetto)));
            $regola = LimiteCaricamento::regolaMax($tetto) / 1024;

            if (abs($etichetta - $regola) > 0.05) {
                $diversi[] = sprintf('tetto %.1f → schermata %.1f, regola %.3f', $tetto, $etichetta, $regola);
            }
        }

        expect($diversi)->toBe([],
            'la schermata e la regola direbbero all\'utente due numeri diversi per lo stesso limite');
    });

    it('il tetto dell\'importatore non viene abbassato dalla nostra costante', function () {
        // Su un server generoso l'importatore deve restare a 25 MB: applicargli il tetto dei
        // documenti gli toglierebbe un quinto della capienza senza che nessuno l'abbia deciso.
        $server = min(
            LimiteCaricamento::daIni('upload_max_filesize'),
            LimiteCaricamento::daIni('post_max_size'),
        );

        if ($server < 25 * 1024 * 1024) {
            expect(true)->toBeTrue(); // su un server stretto vince il server, ed è giusto così

            return;
        }

        expect(LimiteCaricamento::regolaMax(25.0))->toBe(25 * 1024);
    });
});

/**
 * ## Il messaggio della NOSTRA regola, che era l'altra metà della segnalazione
 *
 * La segnalazione del forum aveva due facce: la schermata prometteva più del server (chiusa nella
 * .58 e allargata alle altre nove porte nella .60) e il messaggio d'errore era incomprensibile.
 * La seconda faccia era rimasta aperta: quando scatta la nostra `max:`, Laravel usa il testo
 * predefinito e dice **«non può essere più grande di 20480 kilobytes»** — il numero giusto, detto
 * in un'unità che nessuno usa e diversa da quella che la schermata accanto ha appena scritto.
 *
 * ⚠️ Il sostitutore vive in `AppServiceProvider` e non in nove `messages()`, per la ragione di
 * sempre: una porta nuova nasce già corretta.
 */
describe('il messaggio della nostra regola parla come una persona', function () {
    it('su un file dice i megabyte, non i kilobyte', function () {
        $v = Validator::make(
            ['file' => \Illuminate\Http\UploadedFile::fake()->create('x.pdf', 999999, 'application/pdf')],
            ['file' => 'file|mimes:pdf|max:'.LimiteCaricamento::regolaMax()]
        );

        $messaggio = $v->errors()->first('file');

        expect($messaggio)->toContain('MB')
            ->and($messaggio)->not->toContain('kilobyte')
            ->and($messaggio)->not->toContain((string) LimiteCaricamento::regolaMax());
    });

    it('dice lo stesso numero che la schermata ha promesso', function () {
        // ⚠️ È la coppia che conta: se la schermata dice «2 MB» e l'errore dice «1,9 MB», l'utente
        // pensa di aver trovato un difetto — e ha ragione.
        $v = Validator::make(
            ['file' => \Illuminate\Http\UploadedFile::fake()->create('x.pdf', 999999, 'application/pdf')],
            ['file' => 'file|max:'.LimiteCaricamento::regolaMax(2.0)]
        );

        expect($v->errors()->first('file'))->toContain(LimiteCaricamento::etichetta(2.0));
    });

    it('non tocca i max: che non riguardano file', function () {
        // ⚠️ Questo test esiste per una regressione vera, introdotta e corretta durante la revisione
        // della .60: un `replacer` **sostituisce** la sostituzione predefinita di Laravel invece di
        // affiancarla, e restituire il messaggio intatto lasciava «:max» scritto in pagina su ogni
        // stringa e ogni numero del programma.
        $stringa = Validator::make(['nome' => str_repeat('a', 300)], ['nome' => 'string|max:255']);
        $numero = Validator::make(['n' => 99], ['n' => 'integer|max:10']);
        $lista = Validator::make(['a' => [1, 2, 3]], ['a' => 'array|max:2']);

        expect($stringa->errors()->first('nome'))->toContain('255')->not->toContain(':max')
            ->and($numero->errors()->first('n'))->toContain('10')->not->toContain(':max')
            ->and($lista->errors()->first('a'))->toContain('2')->not->toContain(':max');
    });
});
