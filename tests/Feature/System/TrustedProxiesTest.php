<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Trusted Proxies — regressione di un bug subdolo.
 *
 * Storia: la configurazione dei proxy fidati veniva fatta leggendo
 * env('TRUSTED_PROXIES') dentro il closure ->withMiddleware() di
 * bootstrap/app.php. Quel closure gira quando lo HttpKernel viene RISOLTO,
 * PRIMA che i bootstrapper LoadEnvironmentVariables/LoadConfiguration
 * carichino il .env: lì env() torna sempre null, quindi trustProxies() non
 * veniva MAI chiamato e gli header X-Forwarded-* restavano ignorati SU OGNI
 * installazione, qualunque cosa fosse scritta nel .env. Conseguenze:
 *   - request()->isSecure() falso dietro proxy => mixed content HTTPS;
 *   - request()->ip() = IP del proxy, non del client reale => allowlist IP
 *     del webhook cron (CheckExternalCron) e throttle key di login/2FA
 *     silenziosamente basate sull'IP sbagliato.
 *
 * Fix: la config è stata spostata in config/trustedproxy.php, letto dal
 * middleware globale TrustProxies a request-time (quando il .env è caricato).
 *
 * Questi test si dividono in due famiglie:
 *   1) STRUTTURALI — bloccano la reintroduzione dell'anti-pattern (il vero
 *      guard della regressione: non dipendono da override runtime).
 *   2) COMPORTAMENTALI — pilotano config('trustedproxy.proxies') e simulano
 *      REMOTE_ADDR + header X-Forwarded-* per verificare end-to-end il
 *      middleware globale TrustProxies.
 *
 * NB sull'ambiente di test: phpunit.xml PINna TRUSTED_PROXIES a null, così la
 * suite è ermetica e il default reale (non fidarsi di nessuno) è testabile
 * senza forzare la config a mano — vedi il test "default".
 */

/**
 * Rotta sonda: espone lo schema/IP così come Laravel li risolve DOPO che il
 * middleware globale TrustProxies ha processato la richiesta. Non è nel gruppo
 * 'web' (niente sessione/CSRF): riceve solo il middleware globale, isolando
 * TrustProxies.
 */
beforeEach(function () {
    Route::get('/__proxy-probe', fn (Request $request) => response()->json([
        'ip' => $request->ip(),
        'secure' => $request->isSecure(),
        'scheme' => $request->getScheme(),
    ]));
});

/**
 * Helper: richiesta alla sonda simulando un proxy davanti a PHP.
 * $remoteAddr = IP che si connette direttamente a PHP (il proxy).
 * Gli header X-Forwarded-* sono ciò che il proxy dichiara.
 */
function probeBehindProxy(string $remoteAddr, string $forwardedFor, string $forwardedProto = 'https')
{
    // URL ASSOLUTO http:// di proposito: se usassimo un path relativo, il test
    // client di Laravel genererebbe l'URL via url(), che deriva lo schema dalla
    // richiesta PRECEDENTE ancora in contesto. Dopo una prima richiesta https
    // (proxy fidato) la seconda erediterebbe HTTPS='on' nel server var,
    // falsando isSecure() a prescindere dai trusted proxy. È un artefatto del
    // solo test client (in produzione ogni richiesta è un processo isolato):
    // fissando lo schema qui, isSecure() dipende ESCLUSIVAMENTE dalla logica
    // dei trusted proxy, che è ciò che vogliamo verificare.
    return test()->call('GET', 'http://localhost/__proxy-probe', [], [], [], [
        'REMOTE_ADDR' => $remoteAddr,
        'HTTP_X_FORWARDED_FOR' => $forwardedFor,
        'HTTP_X_FORWARDED_PROTO' => $forwardedProto,
    ]);
}

// =============================================================================
// 1) TEST STRUTTURALI — guard anti-reintroduzione (il vero test della regressione)
// =============================================================================

it('bootstrap/app.php NON legge più env(TRUSTED_PROXIES) né chiama trustProxies() nel CODICE', function () {
    // È QUI che viveva il bug: leggere env() in quel closure (troppo presto).
    // Questo guard fallisce se qualcuno reintroduce l'anti-pattern. Usiamo
    // php_strip_whitespace() per rimuovere i commenti: il file spiega il bug a
    // parole (nominando env('TRUSTED_PROXIES')), quindi controlliamo solo il
    // codice reale, non la documentazione inline.
    $bootstrapCode = php_strip_whitespace(base_path('bootstrap/app.php'));

    expect($bootstrapCode)->not->toContain('trustProxies');
    expect($bootstrapCode)->not->toContain("env('TRUSTED_PROXIES')");
});

it('config/trustedproxy.php alimenta i proxy da env(TRUSTED_PROXIES) sulla chiave letta dal middleware', function () {
    // Il middleware Laravel legge esattamente config('trustedproxy.proxies').
    // Questo guard è deterministico (non dipende dal valore ambientale) e
    // fallirebbe se il file venisse hardcodato o cambiasse chiave/env.
    $file = base_path('config/trustedproxy.php');
    expect(file_exists($file))->toBeTrue();

    // Controllo sul CODICE (commenti rimossi): il file DEVE leggere la env.
    $code = php_strip_whitespace($file);
    expect($code)->toContain("env('TRUSTED_PROXIES')");

    $config = require $file;
    expect($config)->toHaveKey('proxies');
    // A boot la chiave riflette la env (con il pin di phpunit: entrambe null).
    expect(config('trustedproxy.proxies'))->toBe(env('TRUSTED_PROXIES'));
});

// =============================================================================
// 2) TEST COMPORTAMENTALI — end-to-end sul middleware globale TrustProxies
// =============================================================================

it('DEFAULT (nessun override): con la suite ermetica gli header X-Forwarded-* sono ignorati', function () {
    // NON tocchiamo la config: usiamo il default reale risolto dal file
    // (phpunit pinna TRUSTED_PROXIES=null). Se questo passa, il default
    // "sicuro" è davvero attivo — non solo perché un test lo forza.
    expect(config('trustedproxy.proxies'))->toBeNull();

    $response = probeBehindProxy(
        remoteAddr: '203.0.113.9',
        forwardedFor: '198.51.100.7',
        forwardedProto: 'https',
    );

    $response->assertOk();
    expect($response->json('ip'))->toBe('203.0.113.9');   // IP reale, non spoofato
    expect($response->json('secure'))->toBeFalse();
});

it('con TRUSTED_PROXIES esplicitamente null IGNORA gli header X-Forwarded-*', function () {
    // Default sicuro forzato: nessun proxy fidato. Un attaccante non può
    // falsificare IP o schema. Scenario corretto per VPS con PHP esposto.
    config()->set('trustedproxy.proxies', null);

    $response = probeBehindProxy(
        remoteAddr: '203.0.113.9',      // chi si connette (finto proxy / attaccante)
        forwardedFor: '198.51.100.7',   // IP che prova a spacciarsi come client
        forwardedProto: 'https',
    );

    $response->assertOk();
    expect($response->json('ip'))->toBe('203.0.113.9');
    expect($response->json('secure'))->toBeFalse();
    expect($response->json('scheme'))->toBe('http');
});

it('con TRUSTED_PROXIES=* ONORA gli header X-Forwarded-* del proxy', function () {
    // Scenario shared hosting / Cloudflare: ci si fida del proxy che si
    // connette direttamente. Questo è il caso che il bug di timing rompeva:
    // se questo test passa, config('trustedproxy.proxies') arriva davvero al
    // middleware a request-time.
    config()->set('trustedproxy.proxies', '*');

    $response = probeBehindProxy(
        remoteAddr: '203.0.113.9',
        forwardedFor: '198.51.100.7',
        forwardedProto: 'https',
    );

    $response->assertOk();
    expect($response->json('ip'))->toBe('198.51.100.7'); // IP client reale dichiarato dal proxy
    expect($response->json('secure'))->toBeTrue();        // https riconosciuto: niente mixed content
    expect($response->json('scheme'))->toBe('https');
});

it('con proxy fidato ma X-Forwarded-Proto=http NON forza https (proto letto per valore)', function () {
    // Guard contro un fix ingenuo "qualunque header forwarded => https":
    // il proxy è fidato, l'IP viene onorato, ma lo schema dichiarato è http.
    config()->set('trustedproxy.proxies', '*');

    $response = probeBehindProxy(
        remoteAddr: '203.0.113.9',
        forwardedFor: '198.51.100.7',
        forwardedProto: 'http',
    );

    $response->assertOk();
    expect($response->json('ip'))->toBe('198.51.100.7'); // IP onorato (proxy fidato)
    expect($response->json('secure'))->toBeFalse();       // ma schema http rispettato
    expect($response->json('scheme'))->toBe('http');
});

it('con lista IP esplicita si fida SOLO del proxy in lista', function () {
    config()->set('trustedproxy.proxies', '10.0.0.5');

    // Richiesta che arriva DAVVERO da 10.0.0.5 => header onorati.
    $trusted = probeBehindProxy(
        remoteAddr: '10.0.0.5',
        forwardedFor: '198.51.100.7',
        forwardedProto: 'https',
    );
    $trusted->assertOk();
    expect($trusted->json('ip'))->toBe('198.51.100.7');
    expect($trusted->json('secure'))->toBeTrue();
});

it('con lista IP esplicita IGNORA un peer non in lista (anti-spoofing)', function () {
    config()->set('trustedproxy.proxies', '10.0.0.5');

    // Stesso set di header, ma la connessione arriva da un IP diverso: un
    // attaccante che invia X-Forwarded-* fingendosi il proxy NON è creduto.
    $untrusted = probeBehindProxy(
        remoteAddr: '203.0.113.66',
        forwardedFor: '198.51.100.7',
        forwardedProto: 'https',
    );
    $untrusted->assertOk();
    expect($untrusted->json('ip'))->toBe('203.0.113.66'); // IP reale, non spoofato
    expect($untrusted->json('secure'))->toBeFalse();
});

it('la lista IP separata da virgole tollera gli spazi', function () {
    // config/trustedproxy.php documenta il formato "10.0.0.1,10.0.0.2":
    // verifichiamo che uno degli IP con spazi attorno sia comunque fidato.
    config()->set('trustedproxy.proxies', '10.0.0.5, 10.0.0.6');

    $response = probeBehindProxy(
        remoteAddr: '10.0.0.6',
        forwardedFor: '198.51.100.7',
        forwardedProto: 'https',
    );
    $response->assertOk();
    expect($response->json('ip'))->toBe('198.51.100.7');
    expect($response->json('secure'))->toBeTrue();
});

it('la fiducia è ri-derivata a OGNI richiesta (nessun leak dello stato statico)', function () {
    // Symfony tiene i trusted proxies in uno stato statico globale. Il
    // middleware lo resetta a ogni richiesta: verifichiamo che una richiesta
    // "fidata" non lasci fiducia residua a quella successiva.
    config()->set('trustedproxy.proxies', '*');
    $first = probeBehindProxy('203.0.113.9', '198.51.100.7', 'https');
    $first->assertOk();
    expect($first->json('ip'))->toBe('198.51.100.7'); // fidato

    // Ora togliamo la fiducia: la richiesta seguente NON deve credere agli header.
    config()->set('trustedproxy.proxies', null);
    $second = probeBehindProxy('203.0.113.9', '198.51.100.7', 'https');
    $second->assertOk();
    expect($second->json('ip'))->toBe('203.0.113.9'); // niente residuo: IP reale
    expect($second->json('secure'))->toBeFalse();
});
