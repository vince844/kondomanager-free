<?php

/**
 * # Il `.env` non si riscrive più in base a un'intestazione mandata dal client
 *
 * ## Il difetto (coda ㉞, accertata il 17/08/2026, corretta nella beta.65)
 *
 * `UpgradePatchServiceProvider` girava a **ogni richiesta** e decideva se scrivere
 * `TRUSTED_PROXIES=*` nel `.env` guardando `$_SERVER['HTTP_HOST']`, cioè un valore che **manda chi
 * fa la richiesta**. Dove il web server accetta qualunque `Host` — l'nginx del `Dockerfile` di root
 * ascolta senza `server_name`, quindi è il *default server* — bastava una GET non autenticata con
 * `Host: x.av`.
 *
 * E il confronto era su **sottostringhe**: `strpos($host, '.av')` prendeva anche `studio.aversa.it`,
 * `condominio.avellino.it`, `mio.avvocato.it`. Falso positivo con la stessa conseguenza, senza
 * nessun attaccante.
 *
 * **Cosa costa quando scatta.** Con `proxies='*'` Laravel crede a `X-Forwarded-For` da chiunque,
 * quindi `request()->ip()` lo sceglie il client — e con esso le chiavi di throttle del login
 * (`email|ip`) e della doppia autenticazione (`userid|2fa|ip`). Ruotando indirizzi falsi il blocco a
 * cinque tentativi non arriva mai: forza bruta senza limite su password e codice 2FA.
 *
 * ## La correzione, e perché rende il difetto impossibile invece che improbabile
 *
 * Si scrive `PRIVATE_SUBNETS`, che è sicuro su qualunque host — chi arriva da internet ha un
 * `REMOTE_ADDR` pubblico e non rientra in quelle reti. **Siccome il valore va bene ovunque, non c'è
 * più niente da rilevare**: il ramo che leggeva l'host non è stato reso più stretto, è stato tolto.
 *
 * ## Cosa presidiano questi test
 *
 * Due cose diverse, e vale la pena distinguerle:
 *
 * 1. **Il comportamento**: cosa scrive, cosa non tocca, cosa fa due volte di fila.
 * 2. **Che la vulnerabilità non torni**: che in quel file non ricompaia una lettura dell'host, e che
 *    non ci si scriva più `*`. Sono asserzioni sul *sorgente*, non sul comportamento, e servono
 *    perché la strada per rimettere il difetto è breve e sembra un miglioramento («rileviamo meglio
 *    l'hosting»).
 *
 * ## Cosa NON copre
 *
 * - **Non prova che dietro un proxy vero gli indirizzi arrivino giusti**: quello dipende dalla rete
 *   dell'hosting e non è riproducibile qui. Si prova che il valore scritto è quello che il framework
 *   sa espandere.
 * - **Non tocca mai il `.env` del progetto**: ogni prova lavora su un file temporaneo. È il motivo
 *   per cui `sistemaIlFileEnv()` prende il percorso come argomento.
 */

use App\Providers\UpgradePatchServiceProvider;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;

/** Il provider, istanziato a mano: qui non serve il container. */
function patchDelEnv(): UpgradePatchServiceProvider
{
    return new UpgradePatchServiceProvider(app());
}

/** Un `.env` finto con il contenuto indicato. Vive nella cartella temporanea, non nel progetto. */
function envFinto(string $contenuto): string
{
    $percorso = tempnam(sys_get_temp_dir(), 'env');
    file_put_contents($percorso, $contenuto);

    return $percorso;
}

const ENV_BASE = "APP_NAME=Kondomanager\nAPP_ENV=production\nDB_HOST=localhost\n";

/**
 * Le righe di **assegnazione** di `TRUSTED_PROXIES`, ignorando commenti e prosa.
 *
 * ⚠️ Serve perché il blocco che scriviamo **nomina** `TRUSTED_PROXIES=*` nella spiegazione —
 * «qui c'era …» — e una ricerca sul testo intero lo scambierebbe per un'assegnazione. La prima
 * stesura di questi test sbagliava esattamente così, e falliva su codice giusto.
 *
 * @return list<string>
 */
function assegnazioniTrustedProxies(string $contenuto): array
{
    return array_values(array_filter(
        array_map('trim', explode("\n", $contenuto)),
        fn ($riga) => str_starts_with($riga, 'TRUSTED_PROXIES=')
    ));
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. Il comportamento
// ─────────────────────────────────────────────────────────────────────────────

it('scrive PRIVATE_SUBNETS quando non c\'è nessuna configurazione', function () {
    $env = envFinto(ENV_BASE);

    expect(patchDelEnv()->sistemaIlFileEnv($env))->toBe('aggiunto');

    $dopo = file_get_contents($env);

    // ⚠️ Si guarda l'**assegnazione**, non il testo: una riga sola, e con il valore giusto.
    expect(assegnazioniTrustedProxies($dopo))->toBe(['TRUSTED_PROXIES=PRIVATE_SUBNETS'])
        // Le righe che c'erano restano dov'erano: non si riscrive il file, si aggiunge in coda.
        ->and($dopo)->toStartWith(ENV_BASE);

    @unlink($env);
});

it('migra il blocco che avevamo scritto noi, spiegando cosa è cambiato', function () {
    $env = envFinto(ENV_BASE."\n\n# --- AUTO-PATCH v1.9 (Proxy Fix) ---\nTRUSTED_PROXIES=*\n");

    expect(patchDelEnv()->sistemaIlFileEnv($env))->toBe('migrato');

    $dopo = file_get_contents($env);

    // Resta **una sola** assegnazione, ed è quella nuova: il `*` sopravvive solo nella frase che
    // spiega cosa c'era prima, che è esattamente il punto.
    expect(assegnazioniTrustedProxies($dopo))->toBe(['TRUSTED_PROXIES=PRIVATE_SUBNETS'])
        // ⚠️ La spiegazione non è cortesia: è un'impostazione di sicurezza cambiata sul server di
        // qualcun altro, e l'amministratore deve poter capire cosa e come tornare indietro.
        ->and($dopo)->toContain('Qui c\'era TRUSTED_PROXIES=*')
        ->and($dopo)->toContain('lista esplicita')
        ->and($dopo)->toStartWith(ENV_BASE);

    @unlink($env);
});

it('⚠️ non tocca MAI una riga scritta a mano, qualunque valore abbia', function () {
    // È il confine di tutta la correzione: si ripulisce solo ciò che abbiamo sporcato noi.
    foreach (['TRUSTED_PROXIES=*', 'TRUSTED_PROXIES=10.0.0.1,10.0.0.2', 'TRUSTED_PROXIES=null'] as $riga) {
        $prima = ENV_BASE.$riga."\n";
        $env = envFinto($prima);

        expect(patchDelEnv()->sistemaIlFileEnv($env))->toBe('gia-configurato')
            ->and(file_get_contents($env))->toBe($prima, "La riga «{$riga}» è stata toccata.");

        @unlink($env);
    }
});

it('non tocca il blocco nostro se qualcuno ci ha messo mano dentro', function () {
    // Il commento è nostro ma il valore no: da quel momento è una scelta dell'amministratore, e
    // riscriverla vorrebbe dire sovrascrivere una decisione presa da lui.
    $prima = ENV_BASE."\n# --- AUTO-PATCH v1.9 (Proxy Fix) ---\nTRUSTED_PROXIES=172.20.0.5\n";
    $env = envFinto($prima);

    expect(patchDelEnv()->sistemaIlFileEnv($env))->toBe('gia-configurato')
        ->and(file_get_contents($env))->toBe($prima);

    @unlink($env);
});

it('lascia stare anche la riga commentata di `.env.example`', function () {
    // ⚠️ Voluto: quell'installazione ha già una riga da scommentare, e scriverne una seconda più
    // sotto darebbe due righe che dicono cose diverse. È anche il motivo per cui la stragrande
    // maggioranza delle installazioni non è mai stata patchata, nemmeno prima.
    $prima = ENV_BASE."# TRUSTED_PROXIES=null\n";
    $env = envFinto($prima);

    expect(patchDelEnv()->sistemaIlFileEnv($env))->toBe('gia-configurato')
        ->and(file_get_contents($env))->toBe($prima);

    @unlink($env);
});

it('due giri di fila non raddoppiano niente', function () {
    // Un aggiornamento interrotto e ripreso passa di qui due volte. Vale la stessa regola delle
    // migrazioni: la seconda esecuzione dev'essere un non-evento.
    $env = envFinto(ENV_BASE);

    expect(patchDelEnv()->sistemaIlFileEnv($env))->toBe('aggiunto');
    $dopoUno = file_get_contents($env);

    expect(patchDelEnv()->sistemaIlFileEnv($env))->toBe('gia-configurato')
        ->and(file_get_contents($env))->toBe($dopoUno)
        ->and(substr_count($dopoUno, 'TRUSTED_PROXIES'))->toBe(1);

    @unlink($env);
});

it('un `.env` che non esiste non fa esplodere niente', function () {
    expect(patchDelEnv()->sistemaIlFileEnv('/percorso/che/non/esiste/.env'))->toBe('assente');
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. Quando gira
// ─────────────────────────────────────────────────────────────────────────────

it('gira solo durante installazione e aggiornamento', function () {
    // ⚠️ **La metà della correzione di sicurezza.** Fino alla beta.64 girava a ogni richiesta, ed è
    // per questo che il difetto era raggiungibile da chiunque senza autenticarsi.
    //
    // Si prova la condizione e non `boot()`: se la condizione fosse rotta, chiamare `boot()`
    // scriverebbe sul `.env` **vero** del progetto. Una prova che fa danno quando il codice è
    // sbagliato non è una prova.
    config()->set('installer.run_installer', false);
    config()->set('app.env', 'production');
    expect(patchDelEnv()->deveGirare())->toBeFalse();

    config()->set('installer.run_installer', true);
    expect(patchDelEnv()->deveGirare())->toBeTrue();

    // E mai in sviluppo: là il `.env` è di chi sviluppa.
    config()->set('app.env', 'local');
    expect(patchDelEnv()->deveGirare())->toBeFalse();
});

// ─────────────────────────────────────────────────────────────────────────────
// 3. Che la vulnerabilità non torni
// ─────────────────────────────────────────────────────────────────────────────

it('⚠️ il provider non legge più niente che arrivi dal client', function () {
    // Asserzione sul **sorgente**, non sul comportamento, e ci vuole: la strada per rimettere il
    // difetto è breve e sembra un miglioramento («rileviamo meglio l'hosting»). Il codice che
    // decide cosa scrivere nel `.env` non deve dipendere da niente che scelga chi fa la richiesta.
    $sorgente = file_get_contents(dirname(__DIR__, 3).'/app/Providers/UpgradePatchServiceProvider.php');

    // Si guarda il codice, non i commenti: il blocco in testa **racconta** il difetto e nomina
    // HTTP_HOST apposta, e sarebbe assurdo che questo test costringesse a non spiegarlo.
    $codice = implode("\n", array_filter(
        explode("\n", $sorgente),
        fn ($riga) => ! preg_match('#^\s*(\*|//|/\*)#', $riga)
    ));

    // ⚠️ `X-Forwarded` **non** è nell'elenco, ed è una precisazione che la prima stesura di questo
    // test non aveva: quella stringa compare legittimamente nel testo che scriviamo dentro il
    // `.env` per spiegare all'amministratore cosa cambia. Vietarla avrebbe costretto a spiegare
    // peggio. Qui si vieta di **leggere** dalla richiesta, non di nominarla.
    foreach (['HTTP_HOST', 'HTTP_X_FORWARDED', '$_SERVER', '$request', 'request()'] as $vietato) {
        expect(str_contains($codice, $vietato))->toBeFalse(
            "`{$vietato}` è tornato nel codice di UpgradePatchServiceProvider.\n\n".
            "Quel file decide cosa scrivere in un file di configurazione di sicurezza: non deve\n".
            "dipendere da niente che scelga chi manda la richiesta. È esattamente il difetto che la\n".
            "beta.65 ha chiuso — vedi la coda ㉞ in docs/roadmap.md."
        );
    }
});

it('e non scrive più «*» da nessuna parte', function () {
    $sorgente = file_get_contents(dirname(__DIR__, 3).'/app/Providers/UpgradePatchServiceProvider.php');

    expect(UpgradePatchServiceProvider::VALORE)->toBe('PRIVATE_SUBNETS');

    // ⚠️ Il conteggio grezzo non funziona — e la prima stesura di questo test lo faceva, fallendo
    // su codice giusto: `TRUSTED_PROXIES=*` compare tre volte nel file, ma **due sono prosa** (il
    // racconto del difetto in testa e la frase «qui c'era …» che finisce nel `.env`). L'unica che
    // conta è la costante che riconosce il blocco da migrare.
    $righeCheAssegnano = array_filter(
        explode("\n", $sorgente),
        fn ($riga) => str_contains($riga, "'TRUSTED_PROXIES=") && ! preg_match('#^\s*(\*|//)#', $riga)
    );

    // La regola vera, che le mie prime due stesure avevano sbagliato: ogni riga che assegna
    // `TRUSTED_PROXIES` deve o **riconoscere** il blocco vecchio (`BLOCCO_VECCHIO`) o scrivere la
    // **costante** (`self::VALORE`). Un valore letterale è ciò che non deve esistere — è così che
    // il `*` era finito nel codice.
    expect($righeCheAssegnano)->not->toBeEmpty('Nessuna riga assegna TRUSTED_PROXIES: il file ha cambiato forma.');

    foreach ($righeCheAssegnano as $riga) {
        $accettabile = str_contains($riga, 'BLOCCO_VECCHIO') || str_contains($riga, 'self::VALORE');

        expect($accettabile)->toBeTrue(
            "Questa riga scrive un valore letterale di TRUSTED_PROXIES:\n\n  ".trim($riga).
            "\n\nIl valore si scrive solo attraverso la costante VALORE, che oggi vale ".
            UpgradePatchServiceProvider::VALORE.". Un letterale qui e' come e' entrato il carattere jolly."
        );
    }
});

it('il valore scritto è quello che il framework sa espandere davvero', function () {
    // ⚠️ **Non è pignoleria: è l'unico anello che lega la nostra stringa al comportamento reale.**
    // `PRIVATE_SUBNETS` non è una convenzione nostra, è un token di Symfony. Se un aggiornamento del
    // framework lo togliesse, scriveremmo nel `.env` una parola che nessuno interpreta e i proxy non
    // sarebbero fidati **in silenzio**: nessun errore, solo indirizzi sbagliati.
    $richiesta = Request::create('/');
    $richiesta->server->set('REMOTE_ADDR', '10.1.2.3');

    Request::setTrustedProxies([UpgradePatchServiceProvider::VALORE], Request::HEADER_X_FORWARDED_FOR);

    $richiesta->headers->set('X-Forwarded-For', '203.0.113.9');

    // Il proxy sta su rete privata: gli si crede, e l'indirizzo del visitatore è quello inoltrato.
    expect($richiesta->getClientIp())->toBe('203.0.113.9');

    // Controprova, che è il senso di tutta la correzione: se la stessa richiesta arriva da un
    // indirizzo **pubblico**, l'intestazione non viene creduta e non si può falsificare il proprio IP.
    $daInternet = Request::create('/');
    $daInternet->server->set('REMOTE_ADDR', '198.51.100.7');
    $daInternet->headers->set('X-Forwarded-For', '203.0.113.9');

    expect($daInternet->getClientIp())->toBe('198.51.100.7');

    Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
});

it('e le reti private che il token espande sono quelle che ci aspettiamo', function () {
    // Se un giorno l'elenco cambiasse — per esempio perdendo la CGNAT `100.64/10`, che molti
    // hosting condivisi usano — il valore scritto continuerebbe a sembrare giusto e coprirebbe meno.
    expect(IpUtils::PRIVATE_SUBNETS)->toContain('127.0.0.0/8')
        ->and(IpUtils::PRIVATE_SUBNETS)->toContain('10.0.0.0/8')
        ->and(IpUtils::PRIVATE_SUBNETS)->toContain('172.16.0.0/12')
        ->and(IpUtils::PRIVATE_SUBNETS)->toContain('192.168.0.0/16')
        ->and(IpUtils::PRIVATE_SUBNETS)->toContain('100.64.0.0/10')
        ->and(IpUtils::PRIVATE_SUBNETS)->toContain('::1/128');
});

// ─────────────────────────────────────────────────────────────────────────────
// 4. La catena vera: dal .env al middleware, passando dalla nostra config
// ─────────────────────────────────────────────────────────────────────────────

it("⚠️ il valore arriva davvero al middleware, non solo a Symfony", function () {
    // ⚠️ **Il buco che avevo lasciato**, e che Vincenzo ha chiesto di chiudere il 22/08/2026.
    // Le prove qui sopra chiamano `Request::setTrustedProxies()` direttamente: dimostrano che
    // Symfony sa espandere il token, **non** che la nostra catena glielo consegni.
    //
    // ⚠️ **Questo test copre METÀ catena, e va detto.** `config()->set()` scavalca il file di
    // configurazione, quindi qui si prova il tratto *chiave di config → middleware*: che
    // `TrustProxies` legga `trustedproxy.proxies` e sappia farci qualcosa. Provato rompendo di
    // proposito il file di config: **questo test resta verde**, perché non lo attraversa.
    //
    // L'altro tratto — *`.env` → chiave di config* — non si può esercitare senza rifare il boot
    // dell'applicazione, ed è coperto dall'asserzione strutturale qui sotto. Sono due prove
    // diverse per due tratti diversi, e tenerle separate è più onesto di una che sembra coprire
    // tutto e non lo fa.
    //
    // Qui si fa una richiesta HTTP vera attraverso lo stack, con il valore che il provider scrive.
    config()->set('trustedproxy.proxies', \App\Providers\UpgradePatchServiceProvider::VALORE);

    Route::get('/_prova-ip', fn () => request()->ip())->middleware('web');

    // Il proxy sta su rete privata — è il caso dell'hosting condiviso e di nginx davanti a php-fpm.
    $daProxyPrivato = $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])
        ->get('/_prova-ip', ['X-Forwarded-For' => '203.0.113.9']);

    expect($daProxyPrivato->getContent())->toBe('203.0.113.9',
        "Il valore scritto nel `.env` non arriva al middleware: il proxy privato non viene creduto\n".
        "e l'indirizzo del visitatore resta quello del proxy. Guardare `config/trustedproxy.php`\n".
        'e la catena descritta nel suo blocco in testa.'
    );
});

it('e una richiesta da internet non riesce a falsificare il proprio indirizzo', function () {
    // ⚠️ **La controprova, ed è il senso di tutta la correzione della coda ㉞.** Con `*` questa
    // asserzione fallirebbe: l'indirizzo sarebbe quello inoltrato, cioè scelto dal client, e con
    // esso le chiavi di throttle di accesso e doppia autenticazione.
    config()->set('trustedproxy.proxies', \App\Providers\UpgradePatchServiceProvider::VALORE);

    Route::get('/_prova-ip-pubblico', fn () => request()->ip())->middleware('web');

    $daInternet = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->get('/_prova-ip-pubblico', ['X-Forwarded-For' => '203.0.113.9']);

    expect($daInternet->getContent())->toBe('198.51.100.7',
        "Una richiesta arrivata da un indirizzo pubblico è riuscita a farsi credere un altro IP.\n".
        'È esattamente ciò che `*` permetteva e che questa beta ha chiuso.'
    );
});

it('con «*» invece la falsificazione riesce — la prova che il valore conta', function () {
    // Non è un test del prodotto: è la **misura del difetto**, tenuta accanto alla sua correzione.
    // Se un giorno qualcuno rimettesse `*` pensando sia equivalente, questo blocco dice cosa cambia.
    config()->set('trustedproxy.proxies', '*');

    Route::get('/_prova-ip-jolly', fn () => request()->ip())->middleware('web');

    $falsificata = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->get('/_prova-ip-jolly', ['X-Forwarded-For' => '203.0.113.9']);

    expect($falsificata->getContent())->toBe('203.0.113.9');
});

it("⚠️ e la chiave di config è legata alla variabile giusta del .env", function () {
    // ⚠️ **L'altra metà della catena**, che il test qui sopra non può attraversare: `config()->set()`
    // scavalca il file, e rifare il boot dell'applicazione in un test è più fragile di quello che
    // proverebbe. Si guarda quindi il sorgente della configurazione.
    //
    // Il tratto esiste per una ragione precisa, raccontata nel blocco in testa a
    // `config/trustedproxy.php`: leggere `env('TRUSTED_PROXIES')` dentro `bootstrap/app.php`
    // restituiva **sempre `null`**, perché quel punto del ciclo di vita precede il caricamento del
    // `.env`. I proxy non venivano configurati **mai**, qualunque cosa ci fosse scritto — e nessun
    // errore lo diceva. Se qualcuno riportasse la lettura là, tornerebbe lo stesso silenzio.
    $sorgente = file_get_contents(dirname(__DIR__, 3).'/config/trustedproxy.php');

    // ⚠️ `str_contains(...)->toBeTrue()` e non `toContain(...)`: in Pest `toContain` è **variadico**
    // e il secondo argomento non è un messaggio, è un secondo termine da cercare. Ci sono già
    // inciampato nella beta.63 — vale la pena scriverlo dove ricapita.
    expect(str_contains($sorgente, "'proxies' => env('TRUSTED_PROXIES')"))->toBeTrue(
        "`config/trustedproxy.php` non lega più `proxies` alla variabile `TRUSTED_PROXIES` del\n".
        "`.env`. Il provider continuerebbe a scriverla e nessuno la leggerebbe: i proxy non\n".
        'sarebbero fidati, senza nessun errore da nessuna parte.'
    );

    // E che il middleware la legga da lì e non da un valore cablato nel codice.
    $middleware = file_get_contents(
        dirname(__DIR__, 3).'/vendor/laravel/framework/src/Illuminate/Http/Middleware/TrustProxies.php'
    );

    expect(str_contains($middleware, "config('trustedproxy.proxies')"))->toBeTrue(
        'Il middleware di Laravel non legge più `config(\'trustedproxy.proxies\')`: la nostra '.
        'configurazione è scollegata da quello che il framework guarda davvero.'
    );
});

it("⚠️ `.env.example` porta la riga che lo script di build si aspetta di trovare", function () {
    // ⚠️ **Un patto fra due file che vivono in posti diversi**, e per questo va presidiato qui.
    //
    // Lo script di build (l'automator `build_kondomanager_php84_v2`) non riscrive più
    // `.env.example` — prima lo rigenerava da zero, ed erano due copie della stessa cosa che
    // potevano divergere in silenzio: la versione generata prometteva *«l'installer lo attiverà
    // (*) se necessario»* mentre l'installer non ha mai scritto `TRUSTED_PROXIES`, quindi
    // un'installazione fatta col wizard **non configurava mai** i proxy fidati.
    //
    // Adesso il build **scommenta questa riga**, esattamente come già fa con `run_installer` in
    // `config/installer.php`. Se qui la riga cambia forma, il build si ferma con un avviso — ma
    // se ne accorge solo chi sta preparando un pacchetto, magari a release iniziata. Meglio che
    // lo dica la suite.
    $esempio = file_get_contents(dirname(__DIR__, 3).'/.env.example');

    expect(str_contains($esempio, "\n# TRUSTED_PROXIES=".UpgradePatchServiceProvider::VALORE))->toBeTrue(
        "In `.env.example` non c'è più la riga commentata\n\n".
        '  # TRUSTED_PROXIES='.UpgradePatchServiceProvider::VALORE."\n\n".
        "che lo script di build cerca per scommentarla nel pacchetto con l'installazione guidata.\n".
        "Se la si cambia di forma, il pacchetto esce senza proxy fidati configurati — cioè con\n".
        'https non riconosciuto e indirizzi IP sbagliati su ogni hosting condiviso.'
    );

    // E deve restare **commentata** nel repository: questo file è per chi sviluppa, e in locale
    // non serve. È il build a decidere per il pacchetto.
    expect(assegnazioniTrustedProxies($esempio))->toBe([],
        "In `.env.example` la riga di TRUSTED_PROXIES risulta attiva. Nel repository va tenuta\n".
        "commentata — chi sviluppa non ha un proxy davanti — e la attiva lo script di build solo\n".
        'nel pacchetto distribuito.'
    );
});
