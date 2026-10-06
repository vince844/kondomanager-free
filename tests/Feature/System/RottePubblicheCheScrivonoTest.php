<?php

/**
 * La guardia sulle rotte **senza login** che scrivono — 1.11.0-beta.45, decisa da Vincenzo il
 * 06/10/2026 (regola in `docs/flusso_di_lavoro_rilascio.md`, «La lente «sicurezza» è fissa»).
 *
 * `RotteSoloLoginTest` elenca le rotte con `auth`, quindi quelle senza non le vede per costruzione.
 * Ed è lì che stavano le Code 222 e 223: `POST /password/new` e `POST /invito/register` salvavano
 * senza chiedere la firma del link che apre il modulo, e bastava conoscere un'email. Questo test
 * obbliga a guardare: ogni rotta senza `auth` che non sia un `GET` deve stare nell'elenco qui sotto,
 * con la prova che chiede a chi la chiama, e la prova viene **verificata**:
 *
 * - `FIRMA`: la rotta ha il middleware `signed`, senza parametri da ignorare (`signed:relative` sì,
 *   `signed:id` no: una firma che ignora un parametro lascia cambiare proprio quello).
 * - `MIDDLEWARE`: la rotta ha il middleware indicato, che fa da prova (il token del ripristino, la
 *   sessione della sfida a due fattori, la registrazione pubblica accesa).
 * - `GESTORE`: la prova sta nel metodo che risponde, e il test cerca nel suo sorgente, commenti
 *   esclusi, la chiamata indicata (`$request->authenticate()`, `Password::reset(`…).
 * - `PACCHETTO`: la rotta la registra un pacchetto (Laravel, Livewire), non il nostro codice; il test
 *   verifica che il gestore stia davvero in `vendor/`.
 * - `APERTA`: aperta a chiunque per scelta, e il motivo dice perché non crea niente di riservato.
 *
 * **Cosa resta scoperto**: il test prova che una prova **c'è**, non che sia **giusta**: una firma
 * controllata sull'URL sbagliato, o un gestore che chiama `authenticate()` e poi scrive su un altro
 * utente, passano lo stesso. Quella domanda resta alla lente «sicurezza» della Fase 1-bis. Non guarda
 * i `GET`. Oggi l'unico `GET` senza `auth` che cambia stato è `/system/run-scheduler`, protetto dal
 * token di `CheckExternalCron`; i link firmati che aprono un modulo non scrivono. La lente li chiede
 * per nome.
 */

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Routing\Route;

const PUBBLICA_FIRMA = 'firma';
const PUBBLICA_MIDDLEWARE = 'middleware';
const PUBBLICA_GESTORE = 'gestore';
const PUBBLICA_PACCHETTO = 'pacchetto';
const PUBBLICA_APERTA = 'aperta';

/**
 * Chiave = nome della rotta, oppure «METODI uri» per le poche senza nome. Il terzo elemento è la
 * prova da verificare: la classe del middleware per `MIDDLEWARE`, il frammento di codice per
 * `GESTORE`.
 *
 * @return array<string, array{0: string, 1: string, 2?: string}>
 */
function elencoRottePubblicheCheScrivono(): array
{
    return [
        // --- moduli aperti da un link mandato per email ----------------------------------------
        'password.create'         => [PUBBLICA_FIRMA, 'password del primo accesso: lo stesso URL firmato del GET (Coda 222)'],
        'invito.register.store'   => [PUBBLICA_FIRMA, 'registrazione da invito: lo stesso URL firmato del GET (Coda 223)'],

        // --- autenticazione ------------------------------------------------------------------
        'POST login'              => [PUBBLICA_GESTORE, 'la password dell\'account, con il limite di tentativi di LoginRequest', '$request->authenticate()'],
        'password.store'          => [PUBBLICA_GESTORE, 'il token del reset salvato da Laravel e mandato alla casella dell\'utente', 'Password::reset('],
        'POST two-factor-challenge' => [PUBBLICA_MIDDLEWARE, 'la sessione aperta dalla password giusta', \App\Http\Middleware\EnsureTwoFactorChallengeSession::class],
        'POST register'           => [PUBBLICA_MIDDLEWARE, 'registrazione pubblica: aperta solo se l\'amministratore l\'ha accesa, e l\'account nasce col ruolo predefinito', \App\Http\Middleware\CheckUserRegistration::class],
        'password.email'          => [PUBBLICA_APERTA, 'manda il link del reset alla casella dell\'email indicata, e non dice se l\'account esiste; l\'host del link però viene dalla richiesta (Coda 239)'],

        // --- recupero di un ripristino fallito: il login non c'è più, l'import l'ha azzerato ----
        'ripristino.step'         => [PUBBLICA_MIDDLEWARE, 'il token del ripristino', \App\Http\Middleware\EnsureRestoreToken::class],
        'ripristino.resume'       => [PUBBLICA_GESTORE, 'token del ripristino o password di chi l\'ha avviato, con il limite di tentativi (1.11.0-beta.39)', '$this->authorizeRecovery('],
        'ripristino.abort'        => [PUBBLICA_GESTORE, 'token del ripristino o password di chi l\'ha avviato, con il limite di tentativi (1.11.0-beta.39)', '$this->authorizeRecovery('],

        // --- rotte dei pacchetti ---------------------------------------------------------------
        'default-livewire.update' => [PUBBLICA_PACCHETTO, 'Livewire: lo stato dei componenti viaggia con il suo checksum'],
        'livewire.upload-file'    => [PUBBLICA_PACCHETTO, 'Livewire: il caricamento verifica la firma dell\'URL nel gestore'],
        'storage.local.upload'    => [PUBBLICA_PACCHETTO, 'Laravel, disco locale servito: ReceiveFile verifica la firma dell\'URL'],
    ];
}

function chiaveDellaRottaPubblica(Route $route): string
{
    return $route->getName() ?? implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri();
}

/** @return array<string, Route> */
function rottePubblicheCheScrivono(): array
{
    $router = app('router');
    $trovate = [];

    foreach ($router->getRoutes() as $route) {
        if (array_diff($route->methods(), ['GET', 'HEAD']) === []) {
            continue;
        }

        foreach ($router->gatherRouteMiddleware($route) as $m) {
            if (str_starts_with($m, Authenticate::class)) {
                continue 2;
            }
        }

        $trovate[chiaveDellaRottaPubblica($route)] = $route;
    }

    return $trovate;
}

/** `signed` o `signed:relative`: una firma che non ignora nessun parametro della query. */
function haUnaFirmaIntera(Route $route): bool
{
    foreach (app('router')->gatherRouteMiddleware($route) as $m) {
        if ($m === ValidateSignature::class || $m === ValidateSignature::class.':relative') {
            return true;
        }
    }

    return false;
}

function haIlMiddleware(Route $route, string $classe): bool
{
    foreach (app('router')->gatherRouteMiddleware($route) as $m) {
        if (str_starts_with($m, $classe)) {
            return true;
        }
    }

    return false;
}

/** Il file del gestore della rotta: il metodo del controller, o la closure. */
function fileDelGestore(Route $route): string
{
    $uses = $route->getAction('uses');

    if ($uses instanceof Closure) {
        return (new ReflectionFunction($uses))->getFileName();
    }

    [$classe, $metodo] = str_contains($uses, '@') ? explode('@', $uses, 2) : [$uses, '__invoke'];

    return (new ReflectionMethod($classe, $metodo))->getFileName();
}

/** Il sorgente del metodo del controller, senza commenti: una prova commentata non è una prova. */
function sorgenteDelGestoreSenzaCommenti(Route $route): string
{
    [$classe, $metodo] = explode('@', $route->getAction('uses'), 2);
    $riflesso = new ReflectionMethod($classe, $metodo);
    $righe = array_slice(file($riflesso->getFileName()), $riflesso->getStartLine() - 1, $riflesso->getEndLine() - $riflesso->getStartLine() + 1);

    $pulito = '';
    foreach (token_get_all("<?php\n".implode('', $righe)) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $pulito .= is_array($token) ? $token[1] : $token;
    }

    return $pulito;
}

function provaVerificata(Route $route, array $voce): bool
{
    [$categoria] = $voce;

    return match ($categoria) {
        PUBBLICA_FIRMA      => haUnaFirmaIntera($route),
        PUBBLICA_MIDDLEWARE => haIlMiddleware($route, $voce[2]),
        PUBBLICA_GESTORE    => str_contains(sorgenteDelGestoreSenzaCommenti($route), $voce[2]),
        PUBBLICA_PACCHETTO  => str_contains(fileDelGestore($route), DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR),
        PUBBLICA_APERTA     => true,
    };
}

test('ogni rotta senza login che scrive è in elenco', function () {
    $nuove = array_diff_key(rottePubblicheCheScrivono(), elencoRottePubblicheCheScrivono());

    expect(array_keys($nuove))->toBe([], "Rotte senza `auth` che accettano POST, PUT, PATCH o DELETE, non in elenco.\n"
        .'Per ciascuna: quale prova chiede a chi la chiama? Firma dell\'URL (`signed`), un token salvato, la sessione di '
        .'un passaggio precedente. `throttle` non è una prova, e nemmeno «la password è ancora vuota». Poi la voce in '
        .'elencoRottePubblicheCheScrivono().');
});

test('l\'elenco non ha voci per rotte che non ci sono più o che hanno ormai il login', function () {
    $sparite = array_diff_key(elencoRottePubblicheCheScrivono(), rottePubblicheCheScrivono());

    expect(array_keys($sparite))->toBe([], 'Voci da togliere da elencoRottePubblicheCheScrivono().');
});

test('la prova che l\'elenco dichiara c\'è davvero', function () {
    $rotte = rottePubblicheCheScrivono();
    $senza = [];

    foreach (elencoRottePubblicheCheScrivono() as $chiave => $voce) {
        if (isset($rotte[$chiave]) && ! provaVerificata($rotte[$chiave], $voce)) {
            $senza[] = $chiave;
        }
    }

    expect($senza)->toBe([], 'Rotte in elenco la cui prova dichiarata non si trova (middleware assente, chiamata assente dal gestore, gestore fuori da vendor/).');
});

test('la guardia stessa: una rotta pubblica nuova che scrive la vede, una con il login no', function () {
    \Illuminate\Support\Facades\Route::post('/prova-della-guardia', fn () => 'ok')->name('prova.della.guardia');
    \Illuminate\Support\Facades\Route::post('/prova-della-guardia-con-login', fn () => 'ok')
        ->middleware('auth')->name('prova.della.guardia.login');
    app('router')->getRoutes()->refreshNameLookups();

    $rotte = rottePubblicheCheScrivono();

    expect($rotte)->toHaveKey('prova.della.guardia')
        ->and($rotte)->not->toHaveKey('prova.della.guardia.login')
        // E la 222 com'era fino alla 1.11.0-beta.44, senza `signed`, non passerebbe la verifica.
        ->and(provaVerificata($rotte['prova.della.guardia'], [PUBBLICA_FIRMA, '']))->toBeFalse();
});

test('la guardia stessa: una firma che ignora un parametro non conta come firma', function () {
    \Illuminate\Support\Facades\Route::post('/prova-firma-parziale', fn () => 'ok')
        ->middleware('signed:id')->name('prova.firma.parziale');
    \Illuminate\Support\Facades\Route::post('/prova-firma-relativa', fn () => 'ok')
        ->middleware('signed:relative')->name('prova.firma.relativa');
    app('router')->getRoutes()->refreshNameLookups();

    $rotte = rottePubblicheCheScrivono();

    expect(provaVerificata($rotte['prova.firma.parziale'], [PUBBLICA_FIRMA, '']))->toBeFalse()
        ->and(provaVerificata($rotte['prova.firma.relativa'], [PUBBLICA_FIRMA, '']))->toBeTrue();
});
