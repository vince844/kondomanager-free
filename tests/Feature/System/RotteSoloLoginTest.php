<?php

/**
 * La guardia sulle rotte protette dal **solo login** — giro di sicurezza della 1.11.0-beta.39,
 * decisa da Vincenzo il 30/09/2026 (regola in `docs/flusso_di_lavoro_rilascio.md`, «La lente
 * «sicurezza» è fissa»).
 *
 * `/logs`, `/impostazioni/mail` e `/impostazioni/cron` avevano solo `auth` dalla 1.9.0, e i
 * controller nessun controllo: qualunque condòmino col login si faceva consegnare la password SMTP.
 * Nessuno se n'era accorto per tre release, perché niente obbligava a guardare. Questo test obbliga:
 * ogni rotta con `auth` e **senza** un middleware di ruolo o permesso (`role`, `permission`,
 * `role_or_permission`) deve stare nell'elenco qui sotto, con una categoria e un motivo.
 *
 * - `CONTROLLO`: l'autorizzazione sta nel metodo del controller. Il test lo **verifica** leggendo il
 *   sorgente del metodo, commenti esclusi: deve contenere `Gate::authorize`, `Gate::allowIf`/`denyIf`
 *   o `->authorize(`. Non contano `hasPermissionTo`, `hasRole` e `->can(` (possono servire a
 *   scegliere cosa mostrare, come in `segnalazioni.stats`), né `abort_if`/`abort_unless`/`abort(403`
 *   (un 404 su un file, un 403 per un caso solo), né l'`authorize()` della FormRequest (può essere
 *   condizionale e servire più rotte). Togliere il controllo, anche solo commentandolo, fa fallire
 *   il test.
 * - `ACCOUNT`: la rotta agisce solo sull'utente collegato (profilo, password, due fattori,
 *   preferenze, verifica dell'email): non c'è nessun record altrui da proteggere.
 * - `VUOTO`: il metodo è uno stub vuoto. Il test verifica che resti vuoto: chi lo riempie deve
 *   decidere il controllo e spostare la voce in `CONTROLLO`.
 * - `DA_VERIFICARE`: debito noto, da chiudere nel giro completo sulle rotte (roadmap).
 *
 * **Cosa resta scoperto**: il test prova che un controllo **c'è**, non che sia **giusto**. Un
 * `Gate::authorize('view', $x)` su una policy che risponde sì a tutti passa lo stesso: è il caso
 * della PR #48. Quella domanda resta alla lente «sicurezza» della Fase 1-bis. Non guarda nemmeno le
 * rotte già protette da un middleware di ruolo o permesso, né quelle senza `auth`.
 */

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Route;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

const ROTTA_CONTROLLO = 'controllo';
const ROTTA_ACCOUNT = 'account';
const ROTTA_VUOTO = 'vuoto';
const ROTTA_DA_VERIFICARE = 'da verificare';

/**
 * Chiave = nome della rotta, oppure «METODI uri» per le poche senza nome.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function elencoRotteSoloLogin(): array
{
    $account = [ROTTA_ACCOUNT, 'agisce solo sull\'utente collegato'];
    $policy = [ROTTA_CONTROLLO, 'policy o Gate nel controller'];
    $gestione = [ROTTA_CONTROLLO, 'gestione dell\'installazione: Gate sul permesso nel controller'];

    return [
        // --- account personale ------------------------------------------------------------
        'appearance'                           => $account,
        'GET|POST|PUT|PATCH|DELETE|OPTIONS settings' => [ROTTA_ACCOUNT, 'redirect a settings/profile'],
        'POST confirm-password'                => $account,
        'password.confirm'                     => $account,
        'logout'                               => $account,
        'password.edit'                        => $account,
        'password.update'                      => $account,
        'profile.edit'                         => $account,
        'profile.update'                       => $account,
        'profile.destroy'                      => $account,
        'two-factor.show'                      => $account,
        'two-factor.enable'                    => $account,
        'two-factor.confirm'                   => $account,
        'two-factor.disable'                   => $account,
        'two-factor.regenerate-recovery-codes' => $account,
        'user.settings.notifications.index'    => $account,
        'user.settings.notifications.update'   => $account,
        'verification.notice'                  => $account,
        'verification.send'                    => $account,
        'verification.verify'                  => [ROTTA_ACCOUNT, 'link firmato di verifica della propria email'],

        // --- stub vuoti -------------------------------------------------------------------
        'ruoli.show'               => [ROTTA_VUOTO, 'resource non implementata'],
        'utenti.show'              => [ROTTA_VUOTO, 'resource non implementata'],
        'user.eventi.show'         => [ROTTA_VUOTO, 'resource non implementata'],
        'user.anagrafiche.index'   => [ROTTA_VUOTO, 'resource non implementata'],
        'user.anagrafiche.show'    => [ROTTA_VUOTO, 'resource non implementata'],
        'user.anagrafiche.edit'    => [ROTTA_VUOTO, 'resource non implementata'],
        'user.anagrafiche.update'  => [ROTTA_VUOTO, 'resource non implementata'],
        'user.anagrafiche.destroy' => [ROTTA_VUOTO, 'resource non implementata'],

        // --- debito noto: giro completo sulle rotte ------------------------------------------
        'user.anagrafiche.create' => [ROTTA_DA_VERIFICARE, 'primo accesso di chi non ha un\'anagrafica (CheckHasAnagrafica)'],
        'user.anagrafiche.store'  => [ROTTA_DA_VERIFICARE, 'CreateUserAnagraficaRequest::authorize() dà sempre true: chi ha già un\'anagrafica ne crea un\'altra'],
        'user.dashboard'          => [ROTTA_DA_VERIFICARE, 'manda segnalazioni, comunicazioni, documenti ed eventi del palazzo senza guardare i VIEW_*: la pagina nasconde le schede, ma i dati arrivano nelle props'],
        'segnalazioni.stats'      => [ROTTA_DA_VERIFICARE, 'hasRole/hasPermissionTo scelgono la query, non autorizzano: niente VIEW_SEGNALAZIONI, e 500 senza anagrafica'],

        // --- impostazioni, utenti, ruoli, inviti: gestione dell'installazione ------------------
        'impostazioni'                         => $gestione,
        'impostazioni.generali'                => $gestione,
        'impostazioni.generali.store'          => $gestione,
        'impostazioni.installazione'           => $gestione,
        'impostazioni.stampe'                  => $gestione,
        'impostazioni.stampe.store'            => $gestione,
        'impostazioni.backups'                 => $gestione,
        'impostazioni.backups.store'           => $gestione,
        'impostazioni.backups.settings'        => $gestione,
        'impostazioni.backups.password'        => $gestione,
        'impostazioni.backups.step'            => $gestione,
        'impostazioni.backups.download'        => $gestione,
        'impostazioni.backups.destroy'         => $gestione,
        'impostazioni.backups.restore.inspect' => $gestione,
        'impostazioni.backups.restore.start'   => $gestione,
        'GET permessi'                         => $gestione,
        'users.permissions.destroy'            => $gestione,
        'ruoli.index'                          => $gestione,
        'ruoli.create'                         => $gestione,
        'ruoli.store'                          => $gestione,
        'ruoli.edit'                           => $gestione,
        'ruoli.update'                         => $gestione,
        'ruoli.destroy'                        => $gestione,
        'ruoli.permissions.destroy'            => $gestione,
        'utenti.index'                         => $gestione,
        'utenti.create'                        => $gestione,
        'utenti.store'                         => $gestione,
        'utenti.edit'                          => $gestione,
        'utenti.update'                        => $gestione,
        'utenti.destroy'                       => $gestione,
        'utenti.reinvite'                      => $gestione,
        'utenti.suspend'                       => $gestione,
        'utenti.unsuspend'                     => $gestione,
        'utenti.toggle-verification'           => $gestione,
        'inviti.index'                         => $gestione,
        'inviti.create'                        => $gestione,
        'inviti.store'                         => $gestione,
        'inviti.destroy'                       => $gestione,

        // --- portale del condòmino --------------------------------------------------------
        'user.categorie-documenti.index'   => $policy,
        'user.categorie-documenti.show'    => $policy,
        'user.commenti.update'             => $policy,
        'user.commenti.destroy'            => $policy,
        'user.comunicazioni.index'         => $policy,
        'user.comunicazioni.create'        => $policy,
        'user.comunicazioni.store'         => $policy,
        'user.comunicazioni.show'          => $policy,
        'user.comunicazioni.edit'          => $policy,
        'user.comunicazioni.update'        => $policy,
        'user.comunicazioni.destroy'       => $policy,
        'user.documenti.create'            => $policy,
        'user.documenti.store'             => $policy,
        'user.documenti.edit'              => $policy,
        'user.documenti.update'            => $policy,
        'user.documenti.destroy'           => $policy,
        'user.documenti.download'          => $policy,
        'user.eventi.index'                => $policy,
        'user.eventi.create'               => $policy,
        'user.eventi.store'                => $policy,
        'user.eventi.edit'                 => $policy,
        'user.eventi.update'               => $policy,
        'user.eventi.destroy'              => $policy,
        'user.eventi.report_payment'       => $policy,
        'user.segnalazioni.index'          => $policy,
        'user.segnalazioni.create'         => $policy,
        'user.segnalazioni.store'          => $policy,
        'user.segnalazioni.show'           => $policy,
        'user.segnalazioni.edit'           => $policy,
        'user.segnalazioni.update'         => $policy,
        'user.segnalazioni.destroy'        => $policy,
        'user.segnalazioni.commenti.store' => $policy,
    ];
}

function chiaveDellaRotta(Route $route): string
{
    return $route->getName() ?? implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri();
}

/**
 * @return array<string, Route>
 */
function rotteProtetteDalSoloLogin(): array
{
    $router = app('router');
    $protezioni = [RoleOrPermissionMiddleware::class, PermissionMiddleware::class, RoleMiddleware::class];
    $trovate = [];

    foreach ($router->getRoutes() as $route) {
        $middleware = $router->gatherRouteMiddleware($route);

        if (! in_array(Authenticate::class, $middleware, true)) {
            continue;
        }

        foreach ($middleware as $m) {
            foreach ($protezioni as $protezione) {
                if (str_starts_with($m, $protezione)) {
                    continue 3;
                }
            }
        }

        $trovate[chiaveDellaRotta($route)] = $route;
    }

    return $trovate;
}

function sorgenteDelMetodo(ReflectionMethod $metodo): string
{
    $righe = file($metodo->getFileName());

    return implode('', array_slice($righe, $metodo->getStartLine() - 1, $metodo->getEndLine() - $metodo->getStartLine() + 1));
}

/** Il metodo del controller dietro la rotta, o null per una closure. */
function metodoDellaRotta(Route $route): ?ReflectionMethod
{
    $azione = $route->getActionName();

    if ($azione === 'Closure') {
        return null;
    }

    [$classe, $metodo] = str_contains($azione, '@') ? explode('@', $azione, 2) : [$azione, '__invoke'];

    return new ReflectionMethod($classe, $metodo);
}

/** Il sorgente senza commenti: un controllo commentato non è un controllo. */
function senzaCommenti(string $sorgente): string
{
    $pulito = '';

    foreach (token_get_all("<?php\n".$sorgente) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $pulito .= is_array($token) ? $token[1] : $token;
    }

    return $pulito;
}

function contieneUnControllo(ReflectionMethod $metodo): bool
{
    // Contano solo le forme che bloccano, e solo nel metodo del controller. Non l'authorize() della
    // FormRequest: può essere condizionale e servire più rotte (UserCreateSegnalazioneRequest
    // autorizza in modifica, non in creazione). Non abort_if/abort_unless né abort(403): possono
    // essere un 404 su un file mancante, o un 403 dentro un if che copre un caso solo, e nessuno dei
    // due prova che il controllo principale c'è. Un `if (...) abort(403)` si scrive Gate::denyIf.
    $cheBlocca = '/Gate::(authorize|allowIf|denyIf)\b|->authorize\(/';

    return preg_match($cheBlocca, senzaCommenti(sorgenteDelMetodo($metodo))) === 1;
}

function eUnMetodoVuoto(ReflectionMethod $metodo): bool
{
    $corpo = senzaCommenti(sorgenteDelMetodo($metodo));
    $corpo = substr($corpo, strpos($corpo, '{') + 1);

    return trim($corpo) === '}';
}

test('ogni rotta protetta dal solo login è in elenco, con il suo motivo', function () {
    $nuove = array_diff_key(rotteProtetteDalSoloLogin(), elencoRotteSoloLogin());

    expect(array_keys($nuove))->toBe([], "Rotte con solo `auth` e nessun middleware di ruolo o permesso, non in elenco.\n"
        .'Per ciascuna: aggiungere un middleware (`permission:`, `role_or_permission:`), oppure un controllo nel '
        .'controller e una voce in elencoRotteSoloLogin() con il motivo.');
});

test('l\'elenco non ha voci per rotte che non ci sono più o che hanno ormai un middleware', function () {
    $sparite = array_diff_key(elencoRotteSoloLogin(), rotteProtetteDalSoloLogin());

    expect(array_keys($sparite))->toBe([], 'Voci da togliere da elencoRotteSoloLogin(): la rotta non esiste più o è protetta da un middleware.');
});

test('dove l\'elenco dice «controllo nel controller», il controllo c\'è davvero', function () {
    $rotte = rotteProtetteDalSoloLogin();
    $senza = [];

    foreach (elencoRotteSoloLogin() as $chiave => [$categoria]) {
        if ($categoria !== ROTTA_CONTROLLO || ! isset($rotte[$chiave])) {
            continue;
        }

        $metodo = metodoDellaRotta($rotte[$chiave]);
        if ($metodo === null || ! contieneUnControllo($metodo)) {
            $senza[] = $chiave;
        }
    }

    expect($senza)->toBe([], 'Rotte in elenco come «controllo nel controller» il cui metodo non contiene nessun controllo di autorizzazione.');
});

test('dove l\'elenco dice «metodo vuoto», il metodo è ancora vuoto', function () {
    $rotte = rotteProtetteDalSoloLogin();
    $riempiti = [];

    foreach (elencoRotteSoloLogin() as $chiave => [$categoria]) {
        if ($categoria !== ROTTA_VUOTO || ! isset($rotte[$chiave])) {
            continue;
        }

        $metodo = metodoDellaRotta($rotte[$chiave]);
        if ($metodo === null || ! eUnMetodoVuoto($metodo)) {
            $riempiti[] = $chiave;
        }
    }

    expect($riempiti)->toBe([], 'Metodi non più vuoti: decidere il controllo e spostare la voce in «controllo nel controller».');
});

test('la guardia stessa: un controllo commentato non conta, uno vero sì', function () {
    $commentato = new class
    {
        public function azione(): void
        {
            // Gate::authorize('update', $x);
            /* abort_unless(false, 403); */
        }
    };
    $vero = new class
    {
        public function azione(): void
        {
            \Illuminate\Support\Facades\Gate::authorize('update', null);
        }
    };
    $soloPerMostrare = new class
    {
        public function azione(): void
        {
            $tutto = auth()->user()?->hasRole('amministratore');
        }
    };

    $soloUn404 = new class
    {
        public function azione(): void
        {
            abort_unless(false, 404);
            if (false) {
                abort(403);
            }
        }
    };
    // La request autorizza solo in modifica: in creazione il controllo deve stare nel controller.
    $soloLaRequest = new class
    {
        public function store(\App\Http\Requests\Segnalazione\UserCreateSegnalazioneRequest $request): void
        {
        }
    };

    expect(contieneUnControllo(new ReflectionMethod($commentato, 'azione')))->toBeFalse()
        ->and(contieneUnControllo(new ReflectionMethod($vero, 'azione')))->toBeTrue()
        ->and(contieneUnControllo(new ReflectionMethod($soloPerMostrare, 'azione')))->toBeFalse()
        ->and(contieneUnControllo(new ReflectionMethod($soloUn404, 'azione')))->toBeFalse()
        ->and(contieneUnControllo(new ReflectionMethod($soloLaRequest, 'store')))->toBeFalse();
});
