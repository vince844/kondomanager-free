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
 *   dell'hosting e non è riproducibile qui. Si prova che il valore scritto è quello che la nostra
 *   configurazione espande nelle sole reti private, e che il middleware lo riceve.
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

/**
 * Il valore di `trustedproxy.proxies` come lo carica Laravel quando nel `.env` c'è `TRUSTED_PROXIES=$valore`:
 * il file di configurazione vero, letto con la variabile impostata, e la variabile rimessa com'era.
 * Attraversa il tratto `.env` → configurazione, che `config()->set()` scavalcherebbe.
 */
function proxiesDaConfigurazione(string $valore): mixed
{
    $prima = [$_SERVER['TRUSTED_PROXIES'] ?? null, $_ENV['TRUSTED_PROXIES'] ?? null];
    $_SERVER['TRUSTED_PROXIES'] = $_ENV['TRUSTED_PROXIES'] = $valore;

    try {
        return (require dirname(__DIR__, 3).'/config/trustedproxy.php')['proxies'];
    } finally {
        foreach (['_SERVER' => $prima[0], '_ENV' => $prima[1]] as $dove => $era) {
            if ($era === null) {
                unset($GLOBALS[$dove]['TRUSTED_PROXIES']);
            } else {
                $GLOBALS[$dove]['TRUSTED_PROXIES'] = $era;
            }
        }
    }
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

it('il valore scritto è quello che la nostra configurazione sa espandere', function () {
    // ⚠️ **È l'anello che lega la nostra stringa al comportamento reale.** Il segno `PRIVATE_SUBNETS` lo espande
    // `config/trustedproxy.php` nell'elenco delle sole reti da cui non si arriva da internet, e allo stesso modo
    // per chi ha già quel valore nel `.env`; non arriva così com'è a Symfony, che con lo stesso nome ha un
    // elenco diverso (Code 262 e 259: i limiti per indirizzo contano l'indirizzo di chi si connette,
    // salvo un proxy di una rete privata).
    $proxies = proxiesDaConfigurazione(UpgradePatchServiceProvider::VALORE);
    expect($proxies)->toBeArray()->not->toContain(UpgradePatchServiceProvider::VALORE);

    Request::setTrustedProxies($proxies, Request::HEADER_X_FORWARDED_FOR);

    // Il proxy sta su rete privata: gli si crede, e l'indirizzo del visitatore è quello inoltrato.
    $richiesta = Request::create('/');
    $richiesta->server->set('REMOTE_ADDR', '10.1.2.3');
    $richiesta->headers->set('X-Forwarded-For', '203.0.113.9');
    expect($richiesta->getClientIp())->toBe('203.0.113.9');

    // Controprova: da un indirizzo pubblico l'intestazione non è creduta.
    $daInternet = Request::create('/');
    $daInternet->server->set('REMOTE_ADDR', '8.8.8.8');
    $daInternet->headers->set('X-Forwarded-For', '203.0.113.9');
    expect($daInternet->getClientIp())->toBe('8.8.8.8');

    Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
});

it('e le reti in cui il valore si espande sono le private davvero, senza le reti speciali di Symfony', function () {
    // Se un giorno l'elenco perdesse una rete — per esempio la CGNAT `100.64/10`, che molti hosting condivisi
    // usano — il valore scritto continuerebbe a sembrare giusto e coprirebbe meno. E non deve ritrovarsi reti
    // da cui si arriva da internet.
    $proxies = proxiesDaConfigurazione(UpgradePatchServiceProvider::VALORE);

    foreach (['127.0.0.0/8', '::1/128', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10',
        '169.254.0.0/16', 'fe80::/10', 'fc00::/7'] as $rete) {
        expect($proxies)->toContain($rete);
    }

    foreach (['2002::/16', '2001::/32', '::ffff:0:0/96', '::/96', '64:ff9b::/96', '2001:db8::/32', '192.0.2.0/24',
        '198.51.100.0/24', '203.0.113.0/24', '0.0.0.0/8', '240.0.0.0/4'] as $rete) {
        expect($proxies)->not->toContain($rete);
    }
});

it('gli altri valori di TRUSTED_PROXIES restano com\'erano', function () {
    // Solo il segno delle reti private cambia significato; il jolly e le liste esplicite arrivano al
    // middleware come prima, e il segno accanto a un indirizzo esplicito tiene l'indirizzo.
    expect(proxiesDaConfigurazione('*'))->toBe('*')
        ->and(proxiesDaConfigurazione('10.0.0.1,10.0.0.2'))->toBe('10.0.0.1,10.0.0.2');

    $insieme = proxiesDaConfigurazione('PRIVATE_SUBNETS, 203.0.113.5');
    expect($insieme)->toContain('203.0.113.5')->toContain('10.0.0.0/8')->not->toContain('PRIVATE_SUBNETS')->not->toContain('2002::/16');

    // Il sinonimo che Symfony accetta per lo stesso segno vale lo stesso elenco, il nostro: non arriva così com'è
    // a Symfony, che ha un elenco più largo.
    expect(proxiesDaConfigurazione('private_ranges'))->toBe(proxiesDaConfigurazione(UpgradePatchServiceProvider::VALORE));
});

// ─────────────────────────────────────────────────────────────────────────────
// 4. La catena vera: dal .env al middleware, passando dalla nostra config
// ─────────────────────────────────────────────────────────────────────────────

it("⚠️ il valore arriva davvero al middleware, non solo a Symfony", function () {
    // ⚠️ **Il buco che avevo lasciato**, e che Vincenzo ha chiesto di chiudere il 22/08/2026.
    // Le prove qui sopra chiamano `Request::setTrustedProxies()` direttamente: dimostrano che
    // l'elenco si sa usare, **non** che la nostra catena lo consegni al middleware.
    //
    // Il segno grezzo messo con `config()->set()` scavalcherebbe il
    // file di configurazione e proverebbe solo il tratto *chiave di config → middleware*. Qui il valore è quello
    // che esce dal file di configurazione letto con la variabile del `.env` (`proxiesDaConfigurazione()`),
    // perché è il file a espandere il segno delle reti private: la catena è attraversata dal `.env` in poi.
    // Resta fuori solo la lettura del `.env` stesso da parte di Dotenv, che non è nostra.
    //
    // Qui si fa una richiesta HTTP vera attraverso lo stack, con il valore che il provider scrive.
    config()->set('trustedproxy.proxies', proxiesDaConfigurazione(\App\Providers\UpgradePatchServiceProvider::VALORE));

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

it('e una richiesta da internet non riesce a falsificare il proprio indirizzo', function (string $remoto) {
    // ⚠️ **La controprova, ed è il senso di tutta la correzione della coda ㉞.** Con `*` questa
    // asserzione fallirebbe: l'indirizzo sarebbe quello inoltrato, cioè scelto dal client, e con
    // esso le chiavi di throttle di accesso e doppia autenticazione.
    //
    // Anche da IPv6, e da indirizzi pubblici scritti in forme particolari (6to4, Teredo, un IPv4 scritto come
    // IPv6, come lo riporta un server a doppio stack): nessuno è in una rete privata (Code 262 e 259). Gli altri
    // stanno appena fuori da una rete privata scritta come IPv6, uno appena prima e uno appena dopo: una maschera
    // più larga anche di un bit solo si allarga verso il basso o verso l'alto, secondo la rete, e ne prende uno.
    config()->set('trustedproxy.proxies', proxiesDaConfigurazione(\App\Providers\UpgradePatchServiceProvider::VALORE));

    Route::get('/_prova-ip-pubblico', fn () => request()->ip())->middleware('web');

    $daInternet = $this->withServerVariables(['REMOTE_ADDR' => $remoto])
        ->get('/_prova-ip-pubblico', ['X-Forwarded-For' => '203.0.113.9']);

    expect($daInternet->getContent())->toBe($remoto,
        "Una richiesta arrivata da un indirizzo pubblico è riuscita a farsi credere un altro IP.\n".
        'È esattamente ciò che `*` permetteva e che questa beta ha chiuso.'
    );
})->with([
    'IPv4 pubblico'                    => ['8.8.8.8'],
    'IPv6 pubblico'                    => ['2a01:4f8:1:2::1'],
    '6to4'                             => ['2002:c000:204::1'],
    'Teredo'                           => ['2001:0:4136:e378:8000:63bf:3fff:fdd2'],
    'IPv4 pubblico scritto come IPv6'  => ['::ffff:8.8.8.8'],
    'appena prima di 127/8, scritto come IPv6'     => ['::ffff:126.255.255.255'],
    'appena dopo 127/8, scritto come IPv6'         => ['::ffff:128.0.0.0'],
    'appena prima di 10/8, scritto come IPv6'      => ['::ffff:9.255.255.255'],
    'appena dopo 10/8, scritto come IPv6'          => ['::ffff:11.0.0.0'],
    'appena prima di 172.16/12, scritto come IPv6' => ['::ffff:172.15.255.255'],
    'appena dopo 172.16/12, scritto come IPv6'     => ['::ffff:172.32.0.1'],
    'appena prima di 192.168/16, scritto come IPv6' => ['::ffff:192.167.255.255'],
    'appena dopo 192.168/16, scritto come IPv6'    => ['::ffff:192.169.0.0'],
    'appena prima della CGNAT, scritto come IPv6'  => ['::ffff:100.63.255.255'],
    'appena dopo la CGNAT, scritto come IPv6'      => ['::ffff:100.128.0.1'],
    'appena prima di 169.254/16, scritto come IPv6' => ['::ffff:169.253.255.255'],
    'appena dopo 169.254/16, scritto come IPv6'    => ['::ffff:169.255.0.0'],
]);

it('e un proxy su una rete privata vera è creduto, in IPv4, in IPv6 e scritto come IPv6', function (string $proxy) {
    // Per chi ha il proxy su una rete privata non cambia niente: è creduto come prima.
    config()->set('trustedproxy.proxies', proxiesDaConfigurazione(\App\Providers\UpgradePatchServiceProvider::VALORE));

    Route::get('/_prova-ip-proxy', fn () => request()->ip())->middleware('web');

    $daProxy = $this->withServerVariables(['REMOTE_ADDR' => $proxy])
        ->get('/_prova-ip-proxy', ['X-Forwarded-For' => '9.9.9.9']);

    expect($daProxy->getContent())->toBe('9.9.9.9');
})->with([
    'loopback'                       => ['127.0.0.1'],
    'loopback IPv6'                  => ['::1'],
    'RFC 1918 (10/8)'                => ['10.1.2.3'],
    'RFC 1918 (172.16/12, Docker)'   => ['172.18.0.2'],
    'RFC 1918 (192.168/16)'          => ['192.168.1.10'],
    'CGNAT'                          => ['100.64.0.1'],
    'link-local'                     => ['169.254.10.20'],
    'ULA'                            => ['fd00::1'],
    'link-local IPv6'                => ['fe80::1'],
    'Docker scritto come IPv6'       => ['::ffff:172.18.0.2'],
    // Ogni rete privata IPv4 anche nella forma che riporta un server a doppio stack: l'elenco le ha una per
    // una (`::ffff:0:0/96` comprenderebbe internet), e una che mancasse toglierebbe la fiducia a quel proxy.
    'loopback scritto come IPv6'     => ['::ffff:127.0.0.1'],
    'loopback scritto come IPv6, ultimo indirizzo' => ['::ffff:127.255.255.254'],
    '10/8 scritto come IPv6'         => ['::ffff:10.1.2.3'],
    '10/8 scritto come IPv6, ultimo indirizzo' => ['::ffff:10.255.255.254'],
    '172.16/12 scritto come IPv6, ultimo indirizzo' => ['::ffff:172.31.255.254'],
    '192.168/16 scritto come IPv6'   => ['::ffff:192.168.1.10'],
    '192.168/16 scritto come IPv6, ultimo indirizzo' => ['::ffff:192.168.255.254'],
    'CGNAT scritto come IPv6'        => ['::ffff:100.64.0.1'],
    'CGNAT scritto come IPv6, ultimo indirizzo' => ['::ffff:100.127.255.254'],
    'link-local scritto come IPv6'   => ['::ffff:169.254.10.20'],
    'link-local scritto come IPv6, ultimo indirizzo' => ['::ffff:169.254.255.254'],
]);

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
    // ⚠️ **L'altra metà della catena**: che il file di configurazione legga la variabile giusta.
    //
    // Il tratto esiste per una ragione precisa, raccontata nel blocco in testa a
    // `config/trustedproxy.php`: leggere `env('TRUSTED_PROXIES')` dentro `bootstrap/app.php`
    // restituiva **sempre `null`**, perché quel punto del ciclo di vita precede il caricamento del
    // `.env`. I proxy non venivano configurati **mai**, qualunque cosa ci fosse scritto — e nessun
    // errore lo diceva. Se qualcuno riportasse la lettura là, tornerebbe lo stesso silenzio.
    //
    // Non basta cercare nel sorgente la riga `'proxies' => env('TRUSTED_PROXIES')`: il
    // file non copia il valore così com'è (espande il segno delle reti private): lo si carica con la
    // variabile impostata e si guarda che cosa ne esce. Un valore che il file non trasforma deve uscire
    // uguale, cioè letto dalla variabile giusta.
    expect(proxiesDaConfigurazione('192.0.2.10,192.0.2.11'))->toBe('192.0.2.10,192.0.2.11',
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
