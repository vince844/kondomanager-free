<?php

use App\Providers\UpgradePatchServiceProvider;
use Symfony\Component\Process\Process;

/**
 * Il ponte dell'aggiornamento eseguito davvero, in un processo PHP separato.
 *
 * Il ponte (`resources/installer/index.php`, copiato in `public/km-update.php` quando si avvia
 * l'aggiornamento) è un file PHP fuori da Laravel: definisce costanti e chiude la risposta con
 * `exit`, quindi non si include nel processo dei test. Ogni prova lo copia in una cartella
 * temporanea fuori dal progetto, con il suo file di scambio `storage/app/update_bridge.json` e un
 * `artisan` vuoto (la radice del progetto è la cartella che lo contiene), e lo esegue con uno
 * script di appoggio che prepara la richiesta: metodo, campi, sessione, intestazioni.
 *
 * Nessuna rete e nessun server: il pacchetto punta a un indirizzo locale che non risponde, oppure
 * è un archivio costruito qui e letto con `file://`.
 *
 * Le regole che queste prove fissano:
 *
 * - il file di scambio vale solo in `storage/app/update_bridge.json` della radice del progetto, la
 *   cartella che contiene `artisan`: quella del ponte, dove la cartella pubblica coincide con la
 *   radice, altrimenti quella sopra; un file con lo stesso nome in un'altra posizione non vale;
 * - il ponte avvia l'aggiornamento solo con il token che Laravel ha scritto nel file di scambio,
 *   finché non è scaduto, e una richiesta rifiutata non tocca né il file di scambio, né il ponte, né i
 *   file di lavoro di un aggiornamento in corso;
 * - senza `artisan` nella cartella del ponte né in quella sopra il ponte non conosce la radice del
 *   progetto: lo dice (503) e nomina `artisan`, invece di dire che manca il file di scambio;
 * - la pagina aperta senza invio non avvia niente: risponde 200, dice la versione di destinazione (o,
 *   se il PHP è più vecchio del richiesto, quale serve) e rimanda al gestionale e all'installatore a
 *   file singolo;
 * - un errore durante l'aggiornamento chiude il ponte: il file di scambio si cancella, il file si
 *   riduce alla firma che la finalizzazione riconosce (`410 Gone`), e la schermata indica
 *   l'installatore a file singolo;
 * - a buon fine, un `.env` senza `TRUSTED_PROXIES` riceve `PRIVATE_SUBNETS`, con lo stesso blocco
 *   che scrive `UpgradePatchServiceProvider`.
 */

/** Un indirizzo locale su cui non risponde nessuno: lo scarico fallisce subito, senza rete. */
const PONTE_ESEC_INDIRIZZO_MUTO = 'http://127.0.0.1:9/km_v9.9.9.zip';

/** Dove Laravel scrive il file di scambio, dentro la radice del progetto. */
const PONTE_ESEC_SCAMBIO = 'storage/app/update_bridge.json';

/** Lo script che prepara la richiesta e poi esegue il ponte, come farebbe il server web. */
const PONTE_ESEC_APPOGGIO = <<<'PHP'
<?php
$base = $argv[1];
$r = json_decode(file_get_contents($base.'/richiesta.json'), true);

ini_set('session.save_path', $base.'/sessioni');
session_id('kmponteprova');

if ($r['sessione'] !== []) {
    session_start();
    foreach ($r['sessione'] as $chiave => $valore) {
        $_SESSION[$chiave] = $valore;
    }
}

$_SERVER['REQUEST_METHOD'] = $r['metodo'];
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'it-IT,it;q=0.9';
foreach ($r['server'] as $chiave => $valore) {
    $_SERVER[$chiave] = $valore;
}
$_POST = $r['campi'];

$ponte = $base.'/'.$r['ponte'];

register_shutdown_function(function () use ($ponte) {
    echo '<!--km-codice:'.var_export(http_response_code(), true).'-->';

    if (function_exists('opcache_is_script_cached')) {
        echo '<!--km-opcache:'.var_export(opcache_is_script_cached($ponte), true).'-->';
    }
});

require $ponte;
PHP;

/** La cartella temporanea della prova, con il ponte copiato e un `artisan` vuoto. */
function ponteEsecCartella(): string
{
    $base = sys_get_temp_dir().'/km-ponte-'.bin2hex(random_bytes(6));
    mkdir($base.'/radice/public', 0777, true);
    mkdir($base.'/sessioni', 0777, true);

    copy(base_path('resources/installer/index.php'), $base.'/radice/public/km-update.php');
    file_put_contents($base.'/radice/artisan', '');
    file_put_contents($base.'/appoggio.php', PONTE_ESEC_APPOGGIO);

    return $base;
}

/** Il file di scambio della prova, nella posizione in cui lo scrive Laravel. */
function ponteEsecPercorsoScambio(string $base): string
{
    return $base.'/radice/'.PONTE_ESEC_SCAMBIO;
}

/** Toglie la cartella della prova; rifiuta qualunque percorso che non sia una di quelle. */
function ponteEsecRimuovi(?string $base): void
{
    if ($base === null || ! str_starts_with($base, sys_get_temp_dir().'/km-ponte-') || ! is_dir($base)) {
        return;
    }

    $voci = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($voci as $voce) {
        $voce->isDir() && ! $voce->isLink() ? rmdir($voce->getPathname()) : unlink($voce->getPathname());
    }

    rmdir($base);
}

/**
 * Il file di scambio come lo scrive `UpdateService::prepareForUpgrade()`: il token, la scadenza,
 * il pacchetto, i requisiti e la radice del progetto. Di serie nella sua posizione; `$percorso` lo
 * scrive altrove, `$sostituisci` cambia le voci di primo livello.
 */
function ponteEsecScambio(string $base, array $sicurezza, array $pacchetto = [], ?string $percorso = null, array $sostituisci = []): string
{
    $scambio = [
        'security' => $sicurezza,
        'package' => $pacchetto + [
            'version' => '9.9.9',
            'url' => PONTE_ESEC_INDIRIZZO_MUTO,
            'hash' => str_repeat('a', 64),
            'exclude' => ['.env', 'storage', 'public/uploads', 'public/storage', 'install.log', 'bootstrap/cache'],
        ],
        'requirements' => ['php' => '8.0.0'],
        'paths' => ['base' => $base.'/radice', 'public' => $base.'/radice/public'],
    ];

    $contenuto = json_encode(array_replace($scambio, $sostituisci), JSON_PRETTY_PRINT);
    $percorso ??= ponteEsecPercorsoScambio($base);

    if (! is_dir(dirname($percorso))) {
        mkdir(dirname($percorso), 0777, true);
    }

    file_put_contents($percorso, $contenuto);

    return $contenuto;
}

/** Un token della forma che scrive `prepareForUpgrade()`: 64 caratteri esadecimali. */
function ponteEsecToken(): string
{
    return bin2hex(random_bytes(32));
}

/**
 * Le opzioni che accendono OPcache nel processo della prova, con i file che non si ricontrollano
 * (`validate_timestamps=0`), come su un server che li tiene in memoria; `null` se questo PHP non
 * ha OPcache.
 */
function ponteEsecOpzioniOpcache(): ?array
{
    $opzioni = ['-d', 'opcache.enable_cli=1', '-d', 'opcache.validate_timestamps=0', '-d', 'opcache.file_update_protection=0'];

    if (! extension_loaded('Zend OPcache')) {
        array_unshift($opzioni, '-d', 'zend_extension=opcache');
    }

    $prova = new Process([PHP_BINARY, ...$opzioni, '-r', 'echo function_exists("opcache_get_status") && is_array(opcache_get_status(false)) ? "si" : "no";']);
    $prova->run();

    return trim($prova->getOutput()) === 'si' ? $opzioni : null;
}

/**
 * Esegue il ponte con la richiesta indicata e restituisce la pagina, il codice di risposta e
 * l'uscita degli errori del processo. `$ponte` è il percorso del ponte nella cartella della prova,
 * `$opzioniPhp` le opzioni `-d` del processo.
 *
 * @return array{pagina: string, codice: string, errori: string}
 */
function ponteEsegui(string $base, string $metodo, array $campi = [], array $sessione = [], array $server = [], string $ponte = 'radice/public/km-update.php', array $opzioniPhp = []): array
{
    file_put_contents($base.'/richiesta.json', json_encode(compact('metodo', 'campi', 'sessione', 'server', 'ponte')));

    $processo = new Process([PHP_BINARY, ...$opzioniPhp, $base.'/appoggio.php', $base], $base, null, null, 60);
    $processo->run();

    $pagina = $processo->getOutput();
    preg_match('/<!--km-codice:([^-]+)-->/', $pagina, $codice);

    return ['pagina' => $pagina, 'codice' => $codice[1] ?? '', 'errori' => $processo->getErrorOutput()];
}

/** I file rimasti nella radice della prova, in percorsi relativi e in ordine. */
function ponteEsecFileNellaRadice(string $base): array
{
    $radice = $base.'/radice';
    $elenco = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($radice, RecursiveDirectoryIterator::SKIP_DOTS)) as $voce) {
        $elenco[] = substr($voce->getPathname(), strlen($radice) + 1);
    }

    sort($elenco);

    return $elenco;
}

/** Le righe che assegnano `TRUSTED_PROXIES`, senza i commenti che la nominano. */
function ponteEsecAssegnazioniProxy(string $env): array
{
    return array_values(array_filter(
        array_map('trim', explode("\n", $env)),
        fn ($riga) => str_starts_with($riga, 'TRUSTED_PROXIES=')
    ));
}

/**
 * La richiesta è stata rifiutata senza toccare niente: la pagina lo dice con 403, nessuno scarico
 * è partito, il file di scambio e il ponte sono byte per byte quelli di prima.
 */
function ponteEsecRifiutataSenzaToccare(array $esito, string $base, string $scambioPrima): void
{
    expect($esito['pagina'])->toContain('Token di sicurezza non valido.')
        ->and($esito['pagina'])->not->toContain('Download pacchetto')
        ->and($esito['codice'])->toBe('403')
        ->and(@file_get_contents(ponteEsecPercorsoScambio($base)))->toBe($scambioPrima)
        ->and(md5_file($base.'/radice/public/km-update.php'))->toBe(md5_file(base_path('resources/installer/index.php')))
        ->and((string) @file_get_contents($base.'/radice/install.log'))->not->toContain('Download failed');
}

/** Il ponte si è chiuso: niente file di scambio, al suo posto la sola firma `410 Gone`. */
function ponteEsecChiuso(string $base): void
{
    $ponte = (string) @file_get_contents($base.'/radice/public/km-update.php');

    expect(file_exists(ponteEsecPercorsoScambio($base)))->toBeFalse('Il file di scambio è ancora lì.')
        // Le due firme che `SystemUpgradeController::cleanupInstallerJunk()` riconosce.
        ->and($ponte)->toContain('410 Gone')
        ->and($ponte)->toContain('Auto-Update Engine')
        // E nient'altro: il motore non c'è più.
        ->and($ponte)->not->toContain('PACKAGE_URL');
}

beforeEach(function () {
    $this->ponteBase = ponteEsecCartella();
});

afterEach(function () {
    ponteEsecRimuovi($this->ponteBase ?? null);
});

// ─────────────────────────────────────────────────────────────────────────────
// 1. Chi avvia l'aggiornamento
// ─────────────────────────────────────────────────────────────────────────────

it('la pagina aperta senza invio non offre un modulo di avvio e rimanda al gestionale e all installatore', function () {
    $scambio = ponteEsecScambio($this->ponteBase, ['token' => ponteEsecToken(), 'expires_at' => time() + 600]);

    $esito = ponteEsegui($this->ponteBase, 'GET');

    // Con il file di scambio al suo posto la pagina risponde 200 con l'avviso della versione, non con il 503 del file mancante.
    expect($esito['codice'])->toBe('200')
        ->and($esito['pagina'])->toContain('<div class="update-alert">Versione target: v9.9.9</div>')
        ->and($esito['pagina'])->not->toContain('File mancante')
        ->and($esito['pagina'])->not->toContain('<form')
        ->and($esito['pagina'])->not->toContain('name="token"')
        ->and($esito['pagina'])->toContain('href="/system/upgrade"')
        ->and($esito['pagina'])->toContain('https://kondomanager.short.gy/km-installer')
        ->and(file_get_contents(ponteEsecPercorsoScambio($this->ponteBase)))->toBe($scambio);
});

it('la pagina aperta senza invio dice quale PHP serve quando quello del server è più vecchio, e non avvia niente', function () {
    $scambio = ponteEsecScambio($this->ponteBase, ['token' => ponteEsecToken(), 'expires_at' => time() + 600], sostituisci: ['requirements' => ['php' => '99.0.0']]);

    $esito = ponteEsegui($this->ponteBase, 'GET');

    expect($esito['codice'])->toBe('200')
        ->and($esito['pagina'])->toContain('<div class="error-alert">Richiesto PHP 99.0.0 o superiore (rilevato '.PHP_VERSION.'). Aggiorna PHP dal pannello hosting.</div>')
        ->and($esito['pagina'])->not->toContain('<div class="update-alert">')
        ->and($esito['pagina'])->not->toContain('<form')
        ->and(file_get_contents(ponteEsecPercorsoScambio($this->ponteBase)))->toBe($scambio);
});

it('senza file di scambio risponde 503 con lo stesso rimando', function (string $metodo) {
    $esito = ponteEsegui($this->ponteBase, $metodo, ['token' => ponteEsecToken()]);

    expect($esito['codice'])->toBe('503')
        ->and($esito['pagina'])->toContain('File mancante')
        ->and($esito['pagina'])->toContain('href="/system/upgrade"')
        ->and($esito['pagina'])->toContain('https://kondomanager.short.gy/km-installer')
        ->and($esito['pagina'])->not->toContain('Download pacchetto');
})->with(['GET', 'POST']);

it('senza artisan nella radice risponde 503 e dice che manca artisan, anche se il file di scambio c\'è', function (string $metodo) {
    $token = ponteEsecToken();
    $scambio = ponteEsecScambio($this->ponteBase, ['token' => $token, 'expires_at' => time() + 600]);
    unlink($this->ponteBase.'/radice/artisan');

    $esito = ponteEsegui($this->ponteBase, $metodo, ['token' => $token, 'mode' => 'update']);

    // Il file di scambio c'è e il token è giusto: il ponte non trova la radice del progetto (la cartella con `artisan`), e lo dice.
    expect($esito['codice'])->toBe('503')
        ->and($esito['pagina'])->toContain('<span class="code">artisan</span>')
        ->and($esito['pagina'])->not->toContain('File mancante')
        ->and($esito['pagina'])->not->toContain('Download pacchetto')
        ->and($esito['pagina'])->toContain('href="/system/upgrade"')
        ->and($esito['pagina'])->toContain('https://kondomanager.short.gy/km-installer')
        ->and(file_get_contents(ponteEsecPercorsoScambio($this->ponteBase)))->toBe($scambio)
        // Finché la radice non è nota il registro sta accanto al ponte.
        ->and((string) file_get_contents($this->ponteBase.'/radice/public/install.log'))->toContain('Project root not found');
})->with(['GET', 'POST']);

it('la pagina aperta senza invio non contiene niente che avvii lo scarico', function () {
    $token = ponteEsecToken();
    $scambio = ponteEsecScambio($this->ponteBase, ['token' => $token, 'expires_at' => time() + 600]);

    // Il token del file di scambio non compare nella pagina, e un invio senza valori è rifiutato.
    expect(ponteEsegui($this->ponteBase, 'GET')['pagina'])->not->toContain($token);

    $esito = ponteEsegui($this->ponteBase, 'POST', ['token' => '', 'mode' => 'update']);

    ponteEsecRifiutataSenzaToccare($esito, $this->ponteBase, $scambio);
});

it('rifiuta il token tenuto nella sessione del visitatore', function () {
    $tokenDiSessione = ponteEsecToken();
    $scambio = ponteEsecScambio($this->ponteBase, ['token' => ponteEsecToken(), 'expires_at' => time() + 600]);

    $esito = ponteEsegui($this->ponteBase, 'POST', ['token' => $tokenDiSessione], ['token' => $tokenDiSessione]);

    ponteEsecRifiutataSenzaToccare($esito, $this->ponteBase, $scambio);
});

it('rifiuta un invio senza token', function () {
    $scambio = ponteEsecScambio($this->ponteBase, ['token' => ponteEsecToken(), 'expires_at' => time() + 600]);

    $esito = ponteEsegui($this->ponteBase, 'POST');

    ponteEsecRifiutataSenzaToccare($esito, $this->ponteBase, $scambio);
});

it('rifiuta un token che non è quello del file di scambio, anche se ne ha la forma', function () {
    $scambio = ponteEsecScambio($this->ponteBase, ['token' => ponteEsecToken(), 'expires_at' => time() + 600]);

    $esito = ponteEsegui($this->ponteBase, 'POST', ['token' => ponteEsecToken()]);

    ponteEsecRifiutataSenzaToccare($esito, $this->ponteBase, $scambio);
});

it('un invio rifiutato non tocca i file di lavoro di un aggiornamento in corso', function () {
    $scambio = ponteEsecScambio($this->ponteBase, ['token' => ponteEsecToken(), 'expires_at' => time() + 600]);

    // L'archivio che lo scarico dell'amministratore sta scrivendo ha un nome fisso nella radice.
    file_put_contents($this->ponteBase.'/radice/update_temp.zip', 'scarico in corso');

    $esito = ponteEsegui($this->ponteBase, 'POST', ['token' => ponteEsecToken()]);

    ponteEsecRifiutataSenzaToccare($esito, $this->ponteBase, $scambio);
    expect(@file_get_contents($this->ponteBase.'/radice/update_temp.zip'))->toBe('scarico in corso');
});

it('rifiuta un token che non è una stringa, con la sua pagina e senza un errore PHP', function () {
    $token = ponteEsecToken();
    $scambio = ponteEsecScambio($this->ponteBase, ['token' => $token, 'expires_at' => time() + 600]);

    $esito = ponteEsegui($this->ponteBase, 'POST', ['token' => [$token]]);

    ponteEsecRifiutataSenzaToccare($esito, $this->ponteBase, $scambio);
    expect($esito['errori'])->not->toContain('TypeError');
});

it('con un file di scambio senza token non accetta nessun invio', function (array $sicurezza) {
    $scambio = ponteEsecScambio($this->ponteBase, $sicurezza);

    $esito = ponteEsegui($this->ponteBase, 'POST', ['token' => '']);

    ponteEsecRifiutataSenzaToccare($esito, $this->ponteBase, $scambio);
})->with([
    'token assente' => [['expires_at' => time() + 600]],
    'token vuoto' => [['token' => '', 'expires_at' => time() + 600]],
]);

// ─────────────────────────────────────────────────────────────────────────────
// 2. La scadenza
// ─────────────────────────────────────────────────────────────────────────────

it('rifiuta un file di scambio scaduto, anche con il token giusto, e si chiude', function () {
    $token = ponteEsecToken();
    ponteEsecScambio($this->ponteBase, ['token' => $token, 'expires_at' => time() - 60]);

    $esito = ponteEsegui($this->ponteBase, 'POST', ['token' => $token, 'mode' => 'update']);

    expect($esito['pagina'])->toContain('è scaduto')
        ->and($esito['pagina'])->not->toContain('Download pacchetto')
        ->and($esito['pagina'])->toContain('https://kondomanager.short.gy/km-installer');
    ponteEsecChiuso($this->ponteBase);
});

it('un file di scambio senza scadenza vale come scaduto', function () {
    $token = ponteEsecToken();
    ponteEsecScambio($this->ponteBase, ['token' => $token]);

    $esito = ponteEsegui($this->ponteBase, 'POST', ['token' => $token]);

    expect($esito['pagina'])->toContain('è scaduto')
        ->and($esito['pagina'])->not->toContain('Download pacchetto');
    ponteEsecChiuso($this->ponteBase);
});

it('la scadenza vale per l avvio: un aggiornamento partito in tempo finisce anche dopo', function () {
    // Il pacchetto arriva da una FIFO che si riempie dopo 4 secondi: lo scarico finisce due
    // secondi dopo la scadenza del file di scambio, come un aggiornamento che dura più dei suoi
    // 10 minuti di validità.
    $archivio = $this->ponteBase.'/pacchetto.zip';
    $zip = new ZipArchive;
    $zip->open($archivio, ZipArchive::CREATE);
    $zip->addFromString('artisan', "#!/usr/bin/env php\n");
    $zip->addFromString('public/index.php', "<?php // front controller del pacchetto\n");
    $zip->addFromString('config/app.php', "<?php return [];\n");
    $zip->close();

    // La FIFO è del sistema operativo: dove l'estensione posix manca, o il disco non le regge, la prova non si può fare.
    $lento = $this->ponteBase.'/lento.zip';
    if (! function_exists('posix_mkfifo') || ! posix_mkfifo($lento, 0600)) {
        $this->markTestSkipped('Questo PHP non crea file FIFO.');
    }

    $token = ponteEsecToken();
    ponteEsecScambio(
        $this->ponteBase,
        ['token' => $token, 'expires_at' => time() + 2],
        ['url' => 'file://'.$lento, 'hash' => hash_file('sha256', $archivio)]
    );

    $scrittore = new Process([PHP_BINARY, '-r', 'sleep(4); file_put_contents($argv[1], file_get_contents($argv[2]));', $lento, $archivio], null, null, null, 30);
    $scrittore->start();

    $esito = ponteEsegui($this->ponteBase, 'POST', ['token' => $token, 'mode' => 'update']);
    $scrittore->wait();

    expect($esito['pagina'])->toContain('Aggiornamento completato!')
        ->and($esito['pagina'])->not->toContain('è scaduto')
        ->and(file_exists(ponteEsecPercorsoScambio($this->ponteBase)))->toBeFalse();
});

// ─────────────────────────────────────────────────────────────────────────────
// 3. L'errore chiude il ponte
// ─────────────────────────────────────────────────────────────────────────────

it('a un errore di scarico toglie il file di scambio, si riduce alla firma 410 e indica l installatore', function () {
    $token = ponteEsecToken();
    ponteEsecScambio($this->ponteBase, ['token' => $token, 'expires_at' => time() + 600]);

    $esito = ponteEsegui($this->ponteBase, 'POST', ['token' => $token, 'mode' => 'update']);

    // Il token giusto e non scaduto avvia davvero lo scarico, che fallisce sull'indirizzo muto.
    expect($esito['pagina'])->toContain('Download pacchetto v9.9.9')
        ->and($esito['pagina'])->toContain('Download failed')
        ->and($esito['pagina'])->toContain('https://kondomanager.short.gy/km-installer');
    ponteEsecChiuso($this->ponteBase);

    // Della prova resta solo il registro: niente archivio a metà, niente cartelle di lavoro.
    expect(ponteEsecFileNellaRadice($this->ponteBase))->toBe(['artisan', 'install.log', 'public/km-update.php']);
});

it('dopo un errore lo stesso token non avvia più niente', function () {
    $token = ponteEsecToken();
    ponteEsecScambio($this->ponteBase, ['token' => $token, 'expires_at' => time() + 600]);

    ponteEsegui($this->ponteBase, 'POST', ['token' => $token]);
    $secondo = ponteEsegui($this->ponteBase, 'POST', ['token' => $token]);

    expect($secondo['pagina'])->not->toContain('Download pacchetto');
    ponteEsecChiuso($this->ponteBase);
});

it('chiudendosi, il ponte toglie la propria versione compilata dalla cache degli script', function () {
    $opcache = ponteEsecOpzioniOpcache();

    if ($opcache === null) {
        $this->markTestSkipped('Questo PHP non ha OPcache.');
    }

    $token = ponteEsecToken();
    ponteEsecScambio($this->ponteBase, ['token' => $token, 'expires_at' => time() + 600]);

    $esito = ponteEsegui($this->ponteBase, 'POST', ['token' => $token], [], [], 'radice/public/km-update.php', $opcache);

    // Lo script appoggio guarda la cache alla fine della richiesta, dopo la chiusura.
    expect($esito['pagina'])->toContain('Download failed')
        ->and($esito['pagina'])->toContain('<!--km-opcache:false-->');
    ponteEsecChiuso($this->ponteBase);
});

// ─────────────────────────────────────────────────────────────────────────────
// 4. A buon fine
// ─────────────────────────────────────────────────────────────────────────────

it('a buon fine aggiorna i file, toglie sé stesso e il file di scambio, e scrive PRIVATE_SUBNETS nel .env', function () {
    // Un pacchetto minimo con i tre file che il controllo di salute cerca.
    $archivio = $this->ponteBase.'/pacchetto.zip';
    $zip = new ZipArchive;
    $zip->open($archivio, ZipArchive::CREATE);
    $zip->addFromString('artisan', "#!/usr/bin/env php\n");
    $zip->addFromString('public/index.php', "<?php // front controller del pacchetto\n");
    $zip->addFromString('config/app.php', "<?php return [];\n");
    $zip->close();

    $envPrima = "APP_NAME=Kondomanager\nAPP_ENV=production\nDB_ENGINE=InnoDB\n";
    file_put_contents($this->ponteBase.'/radice/.env', $envPrima);

    $token = ponteEsecToken();
    ponteEsecScambio(
        $this->ponteBase,
        ['token' => $token, 'expires_at' => time() + 600],
        ['url' => 'file://'.$archivio, 'hash' => hash_file('sha256', $archivio)]
    );

    // Dietro un proxy: è il caso in cui il ponte scrive la configurazione dei proxy fidati.
    $esito = ponteEsegui($this->ponteBase, 'POST', ['token' => $token, 'mode' => 'update'], [], [
        'HTTP_HOST' => 'condominio.example.it',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ]);

    expect($esito['pagina'])->toContain('Aggiornamento completato!')
        ->and(file_get_contents($this->ponteBase.'/radice/public/index.php'))->toContain('front controller del pacchetto')
        ->and(file_exists(ponteEsecPercorsoScambio($this->ponteBase)))->toBeFalse()
        ->and(file_exists($this->ponteBase.'/radice/public/km-update.php'))->toBeFalse();

    $envDopo = file_get_contents($this->ponteBase.'/radice/.env');

    expect(ponteEsecAssegnazioniProxy($envDopo))->toBe(['TRUSTED_PROXIES='.UpgradePatchServiceProvider::VALORE]);

    // Lo stesso blocco, byte per byte, che il provider aggiunge a un `.env` uguale.
    $confronto = tempnam(sys_get_temp_dir(), 'env');
    file_put_contents($confronto, $envPrima);

    try {
        $provider = new UpgradePatchServiceProvider(app());

        expect($provider->sistemaIlFileEnv($confronto))->toBe('aggiunto')
            ->and($envDopo)->toBe(file_get_contents($confronto))
            // E il provider, alla richiesta dopo, non ha più niente da fare.
            ->and($provider->sistemaIlFileEnv($this->ponteBase.'/radice/.env'))->toBe('gia-configurato');
    } finally {
        @unlink($confronto);
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// 5. Dove sta il file di scambio
// ─────────────────────────────────────────────────────────────────────────────

it('un file di scambio fuori da storage/app della radice del progetto non vale', function (string $posizione) {
    $token = ponteEsecToken();
    $percorso = $this->ponteBase.'/'.$posizione;
    $scambio = ponteEsecScambio($this->ponteBase, ['token' => $token, 'expires_at' => time() + 600], [], $percorso);

    $esito = ponteEsegui($this->ponteBase, 'POST', ['token' => $token, 'mode' => 'update']);

    expect($esito['codice'])->toBe('503')
        ->and($esito['pagina'])->toContain('File mancante')
        ->and($esito['pagina'])->not->toContain('Download pacchetto')
        ->and(file_get_contents($percorso))->toBe($scambio)
        ->and(md5_file($this->ponteBase.'/radice/public/km-update.php'))->toBe(md5_file(base_path('resources/installer/index.php')));
})->with([
    'nella radice del progetto' => ['radice/update_bridge.json'],
    'accanto al ponte' => ['radice/public/update_bridge.json'],
    // public/storage porta dentro storage/app/public, che è pubblica: lì sotto non vale.
    'sotto la cartella pubblica' => ['radice/public/storage/app/update_bridge.json'],
]);

it('dove la cartella pubblica coincide con la radice del progetto, il file di scambio si trova sotto la stessa radice', function () {
    rename($this->ponteBase.'/radice/public/km-update.php', $this->ponteBase.'/radice/km-update.php');

    $token = ponteEsecToken();
    ponteEsecScambio($this->ponteBase, ['token' => $token, 'expires_at' => time() + 600]);

    $esito = ponteEsegui($this->ponteBase, 'POST', ['token' => $token, 'mode' => 'update'], [], [], 'radice/km-update.php');

    expect($esito['pagina'])->toContain('Download pacchetto v9.9.9')
        ->and($esito['pagina'])->toContain('Download failed')
        ->and(file_exists(ponteEsecPercorsoScambio($this->ponteBase)))->toBeFalse()
        ->and((string) file_get_contents($this->ponteBase.'/radice/km-update.php'))->toContain('410 Gone');
});

it('dove la cartella pubblica coincide con la radice, vale solo il file di scambio di quella radice, non di una cartella sopra', function () {
    rename($this->ponteBase.'/radice/public/km-update.php', $this->ponteBase.'/radice/km-update.php');

    // La cartella sopra la radice è un'altra installazione, con il suo file di scambio.
    file_put_contents($this->ponteBase.'/artisan', '');
    $tokenSopra = ponteEsecToken();
    $sopra = $this->ponteBase.'/'.PONTE_ESEC_SCAMBIO;
    $scambioSopra = ponteEsecScambio($this->ponteBase, ['token' => $tokenSopra, 'expires_at' => time() + 600], [], $sopra);

    // Senza un file di scambio nella radice non parte niente, nemmeno con il token di quello sopra.
    $senza = ponteEsegui($this->ponteBase, 'POST', ['token' => $tokenSopra, 'mode' => 'update'], [], [], 'radice/km-update.php');

    expect($senza['codice'])->toBe('503')
        ->and($senza['pagina'])->not->toContain('Download pacchetto');

    // Con il suo file di scambio, il token di quello sopra è rifiutato e il suo avvia lo scarico.
    $token = ponteEsecToken();
    ponteEsecScambio($this->ponteBase, ['token' => $token, 'expires_at' => time() + 600]);

    $rifiutato = ponteEsegui($this->ponteBase, 'POST', ['token' => $tokenSopra, 'mode' => 'update'], [], [], 'radice/km-update.php');
    $accettato = ponteEsegui($this->ponteBase, 'POST', ['token' => $token, 'mode' => 'update'], [], [], 'radice/km-update.php');

    expect($rifiutato['codice'])->toBe('403')
        ->and($rifiutato['pagina'])->not->toContain('Download pacchetto')
        ->and($accettato['pagina'])->toContain('Download pacchetto v9.9.9')
        ->and(file_get_contents($sopra))->toBe($scambioSopra);
});

it('se la radice scritta nel file di scambio non è un installazione, vale la radice in cui il file di scambio si trova', function () {
    $token = ponteEsecToken();
    ponteEsecScambio($this->ponteBase, ['token' => $token, 'expires_at' => time() + 600], [], null, [
        'paths' => ['base' => $this->ponteBase.'/spostata', 'public' => $this->ponteBase.'/spostata/public'],
    ]);

    $esito = ponteEsegui($this->ponteBase, 'POST', ['token' => $token, 'mode' => 'update']);

    // Il ponte lavora nella cartella con `artisan` sopra di sé, dove scrive anche il registro.
    $radice = realpath($this->ponteBase.'/radice');

    expect($esito['pagina'])->toContain('Download failed')
        ->and((string) @file_get_contents($this->ponteBase.'/radice/install.log'))->toContain('Project root: '.$radice.PHP_EOL);
    ponteEsecChiuso($this->ponteBase);
});
