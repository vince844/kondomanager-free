<?php

use App\Providers\UpgradePatchServiceProvider;

/**
 * Il blocco che il ponte dell'aggiornamento scrive nel `.env` si migra a `PRIVATE_SUBNETS`, come il
 * blocco del provider della v1.9.
 *
 * Il ponte dell'aggiornamento, quando il `.env` non nomina `TRUSTED_PROXIES` e l'ambiente sembra
 * dietro un proxy, aggiunge in coda un blocco suo. Il ponte che esegue un aggiornamento è quello
 * della versione installata, non di quella che arriva: questi test riproducono, riga per riga, il
 * blocco che un ponte di una versione già installata può aver scritto, e `UpgradePatchServiceProvider`
 * lo riconosce e lo migra con il codice nuovo, alla prima richiesta dopo l'aggiornamento. Il ponte di
 * questa versione scrive già `PRIVATE_SUBNETS` (lo prova `UpdateBridgeEsecuzioneTest`).
 *
 * Le regole sono quelle del provider (vedi il suo docblock): si migra solo un blocco nostro ancora
 * esattamente com'era, riconosciuto come righe intere; una riga `TRUSTED_PROXIES` scritta a mano non
 * si tocca mai; le tre righe del database che il ponte di una versione già installata aggiungeva sotto
 * il suo blocco (`DB_CHARSET`, `DB_COLLATION`, `DB_ENGINE`, Coda 447) si tolgono, e solo se sono
 * quelle tre, intere e consecutive, subito sotto la riga `TRUSTED_PROXIES` di un blocco nostro.
 *
 * Ogni prova lavora su un file temporaneo, mai sul `.env` del progetto: per questo si chiama
 * `sistemaIlFileEnv()` con il percorso, come in `ProxyFidatiSenzaIndovinareTest`.
 */

/** Le tre righe che il ponte di una versione già installata può aver scritto, byte per byte: intestazione, commento, valore. */
const PROXY_PONTE_BLOCCO = "# --- AUTO-CONFIGURED BY V1.9 UPGRADER ---\n"
    ."# Fix per HTTPS e Cron Job su Hosting Condivisi/Proxy\n"
    .'TRUSTED_PROXIES=*';

/** Le righe del database che il ponte aggiunge sotto il blocco quando manca `DB_ENGINE`. */
const PROXY_PONTE_RIGHE_DB = "DB_CHARSET=utf8\nDB_COLLATION=utf8_unicode_ci\nDB_ENGINE=\"InnoDB ROW_FORMAT=DYNAMIC\"\n";

/** Un `.env` di partenza senza nessuna configurazione dei proxy. */
const PROXY_PONTE_ENV = "APP_NAME=Kondomanager\nAPP_ENV=production\nDB_HOST=localhost\n";

/** Il blocco che il ponte della 1.11.0 scrive, byte per byte: intestazione, quattro commenti, valore. */
const PROXY_PONTE_BLOCCO_NUOVO = "# --- KondoManager: proxy fidati (scritto automaticamente) ---\n"
    ."# Serve dietro un reverse proxy (hosting condiviso, nginx/apache davanti a php-fpm)\n"
    ."# perché Laravel riconosca https e l'indirizzo IP reale di chi visita.\n"
    ."# PRIVATE_SUBNETS si fida solo di proxy su reti private o loopback: da internet non è\n"
    ."# falsificabile. Se il tuo proxy ha un IP pubblico, scrivi qui la sua lista esplicita.\n"
    .'TRUSTED_PROXIES=PRIVATE_SUBNETS';

/** Un `.env` temporaneo con il contenuto indicato. */
function proxyPonteEnv(string $contenuto): string
{
    $percorso = tempnam(sys_get_temp_dir(), 'env');
    file_put_contents($percorso, $contenuto);

    return $percorso;
}

/** Applica il provider al contenuto indicato e restituisce l'esito e il file dopo. */
function proxyPonteApplica(string $contenuto): array
{
    $percorso = proxyPonteEnv($contenuto);

    try {
        $esito = (new UpgradePatchServiceProvider(app()))->sistemaIlFileEnv($percorso);

        return [$esito, file_get_contents($percorso)];
    } finally {
        @unlink($percorso);
    }
}

/**
 * Il blocco con cui il provider sostituisce un `*` scritto da noi, preso dal provider stesso: è
 * quello che esce migrando il blocco della v1.9, la regola «si migra come il blocco vecchio».
 */
function proxyPonteBloccoMigrato(): string
{
    [$esito, $dopo] = proxyPonteApplica(PROXY_PONTE_ENV."\n\n# --- AUTO-PATCH v1.9 (Proxy Fix) ---\nTRUSTED_PROXIES=*\n");

    expect($esito)->toBe('migrato')
        ->and($dopo)->toStartWith(PROXY_PONTE_ENV."\n\n")
        ->and($dopo)->toEndWith("\n");

    return substr($dopo, strlen(PROXY_PONTE_ENV."\n\n"), -1);
}

/** Le righe che assegnano `TRUSTED_PROXIES`, senza i commenti che la nominano. */
function proxyPonteAssegnazioni(string $env): array
{
    return array_values(array_filter(
        array_map('trim', explode("\n", $env)),
        fn ($riga) => str_starts_with($riga, 'TRUSTED_PROXIES=')
    ));
}

/** Le righe che assegnano una variabile del database, senza i commenti che le nominano. */
function proxyPonteAssegnazioniDb(string $env): array
{
    return array_values(array_filter(
        array_map('trim', explode("\n", $env)),
        fn ($riga) => preg_match('/^DB_(CHARSET|COLLATION|ENGINE)=/', $riga) === 1
    ));
}

/**
 * Dopo `$prefisso` (il `.env` fino alla riga `TRUSTED_PROXIES` compresa, e il suo a capo) non c'è altro che
 * la nota che dice cosa è successo: righe di commento, che nominano le tre variabili e dicono quale valore usa il programma.
 */
function proxyPonteNotaDelleRigheTolte(string $dopo, string $prefisso): bool
{
    if (! str_starts_with($dopo, $prefisso)) {
        return false;
    }

    $nota = substr($dopo, strlen($prefisso));

    return preg_match('/\A(# [^\n]*\n)+\z/', $nota) === 1
        && str_contains($nota, 'DB_CHARSET')
        && str_contains($nota, 'DB_COLLATION')
        && str_contains($nota, 'DB_ENGINE')
        && str_contains($nota, 'utf8mb4')
        // E dice che cosa ne segue per le tabelle già create: le righe tolte non le converte, e lì un testo con un'emoji non si salva: con
        // `strict` acceso (`config/database.php`) MySQL rifiuta tutta la scrittura, non la sola emoji.
        && str_contains($nota, "L'aggiornamento non ha convertito le tabelle già create: in quelle tabelle un testo con un'emoji non si salva, e il salvataggio dà un errore.");
}

/**
 * Quello che sta nel file dopo la nota che prende il posto delle tre righe, a partire dal fine riga che chiude la sua ultima riga
 * (se c'è). La nota sono le righe di commento subito sotto la riga `TRUSTED_PROXIES`.
 */
function proxyPonteCodaDopoLaNota(string $dopo): string
{
    $trovato = preg_match('/TRUSTED_PROXIES=[^\n]*\n(?:# [^\n]*\n)*# [^\n]*(.*)\z/s', $dopo, $parti);

    expect($trovato)->toBe(1);

    return $parti[1];
}

it('migra il blocco scritto dal ponte di una versione installata, come il blocco del provider', function () {
    [$esito, $dopo] = proxyPonteApplica(PROXY_PONTE_ENV."\n\n".PROXY_PONTE_BLOCCO."\n");

    expect($esito)->toBe('migrato')
        ->and($dopo)->toBe(PROXY_PONTE_ENV."\n\n".proxyPonteBloccoMigrato()."\n")
        ->and(proxyPonteAssegnazioni($dopo))->toBe(['TRUSTED_PROXIES='.UpgradePatchServiceProvider::VALORE]);
});

it('Coda 447 — migrando il blocco del ponte toglie anche le tre righe del database che ha scritto sotto', function () {
    [$esito, $dopo] = proxyPonteApplica(PROXY_PONTE_ENV."\n\n".PROXY_PONTE_BLOCCO."\n".PROXY_PONTE_RIGHE_DB);

    expect($esito)->toBe('migrato')
        ->and($dopo)->toStartWith(PROXY_PONTE_ENV."\n\n".proxyPonteBloccoMigrato()."\n")
        ->and(proxyPonteAssegnazioniDb($dopo))->toBe([])
        ->and(proxyPonteNotaDelleRigheTolte($dopo, PROXY_PONTE_ENV."\n\n".proxyPonteBloccoMigrato()."\n"))->toBeTrue();
});

it('riconosce il blocco del ponte anche in fondo al file, senza l ultimo a capo', function () {
    [$esito, $dopo] = proxyPonteApplica(PROXY_PONTE_ENV."\n\n".PROXY_PONTE_BLOCCO);

    expect($esito)->toBe('migrato')
        ->and($dopo)->toBe(PROXY_PONTE_ENV."\n\n".proxyPonteBloccoMigrato());
});

it('migra in un passaggio solo il blocco del ponte e quello del provider, se ci sono tutti e due', function () {
    $prima = PROXY_PONTE_ENV
        ."\n\n# --- AUTO-PATCH v1.9 (Proxy Fix) ---\nTRUSTED_PROXIES=*\n"
        ."\n\n".PROXY_PONTE_BLOCCO."\n";

    [$esito, $dopo] = proxyPonteApplica($prima);

    expect($esito)->toBe('migrato')
        ->and(proxyPonteAssegnazioni($dopo))->toBe([
            'TRUSTED_PROXIES='.UpgradePatchServiceProvider::VALORE,
            'TRUSTED_PROXIES='.UpgradePatchServiceProvider::VALORE,
        ]);
});

it('il blocco del provider della v1.9 si migra come sempre', function () {
    [$esito, $dopo] = proxyPonteApplica(PROXY_PONTE_ENV."\n\n# --- AUTO-PATCH v1.9 (Proxy Fix) ---\nTRUSTED_PROXIES=*\n");

    expect($esito)->toBe('migrato')
        ->and(proxyPonteAssegnazioni($dopo))->toBe(['TRUSTED_PROXIES='.UpgradePatchServiceProvider::VALORE])
        ->and($dopo)->toContain("Qui c'era TRUSTED_PROXIES=*");
});

it('dopo la migrazione del blocco del ponte, il giro dopo non cambia niente', function () {
    $percorso = proxyPonteEnv(PROXY_PONTE_ENV."\n\n".PROXY_PONTE_BLOCCO."\n".PROXY_PONTE_RIGHE_DB);
    $provider = new UpgradePatchServiceProvider(app());

    try {
        expect($provider->sistemaIlFileEnv($percorso))->toBe('migrato');
        $dopoUno = file_get_contents($percorso);

        expect($provider->sistemaIlFileEnv($percorso))->toBe('gia-configurato')
            ->and(file_get_contents($percorso))->toBe($dopoUno);
    } finally {
        @unlink($percorso);
    }
});

it('non tocca una riga TRUSTED_PROXIES scritta a mano, né un blocco del ponte cambiato a mano', function (string $prima) {
    [$esito, $dopo] = proxyPonteApplica($prima);

    expect($esito)->toBe('gia-configurato')
        ->and($dopo)->toBe($prima);
})->with([
    'riga a mano con «*»' => [PROXY_PONTE_ENV."TRUSTED_PROXIES=*\n"],
    'riga a mano con una lista' => [PROXY_PONTE_ENV."TRUSTED_PROXIES=10.0.0.1,10.0.0.2\n"],
    'riga a mano con «*» sotto un commento qualsiasi' => [PROXY_PONTE_ENV."# Fix per HTTPS e Cron Job su Hosting Condivisi/Proxy\nTRUSTED_PROXIES=*\n"],
    'blocco del ponte con il valore cambiato' => [PROXY_PONTE_ENV."\n\n# --- AUTO-CONFIGURED BY V1.9 UPGRADER ---\n# Fix per HTTPS e Cron Job su Hosting Condivisi/Proxy\nTRUSTED_PROXIES=172.20.0.5\n"],
    'blocco del ponte con il valore allungato' => [PROXY_PONTE_ENV."\n\n".PROXY_PONTE_BLOCCO.",10.0.0.1\n"],
    'blocco del ponte senza la riga di commento' => [PROXY_PONTE_ENV."\n\n# --- AUTO-CONFIGURED BY V1.9 UPGRADER ---\nTRUSTED_PROXIES=*\n"],
    'blocco del ponte con l intestazione ritoccata' => [PROXY_PONTE_ENV."\n\n#".PROXY_PONTE_BLOCCO."\n"],
    'blocco del ponte con una riga in mezzo' => [PROXY_PONTE_ENV."\n\n# --- AUTO-CONFIGURED BY V1.9 UPGRADER ---\n# Fix per HTTPS e Cron Job su Hosting Condivisi/Proxy\n# mio\nTRUSTED_PROXIES=*\n"],
]);

it('se l espressione regolare non si può valutare, il .env resta intero', function () {
    // In un processo a parte, con la compilazione JIT spenta e il limite di backtracking a zero:
    // `preg_replace_callback` restituisce null. Nel processo dei test non si riproduce, perché
    // l'espressione è già compilata in memoria dalle prove di sopra.
    $prima = PROXY_PONTE_ENV."\n\n".PROXY_PONTE_BLOCCO."\n";
    $percorso = proxyPonteEnv($prima);

    $script = 'require $argv[1]."/vendor/autoload.php";'
        .'$p = new App\Providers\UpgradePatchServiceProvider(new Illuminate\Foundation\Application(sys_get_temp_dir()));'
        .'echo $p->sistemaIlFileEnv($argv[2]), "|", preg_last_error_msg();';

    try {
        $processo = new Symfony\Component\Process\Process(
            [PHP_BINARY, '-d', 'pcre.jit=0', '-d', 'pcre.backtrack_limit=0', '-r', $script, base_path(), $percorso],
            null, null, null, 60
        );
        $processo->run();

        expect($processo->getOutput())->toBe('gia-configurato|Backtrack limit exhausted')
            ->and(file_get_contents($percorso))->toBe($prima);
    } finally {
        @unlink($percorso);
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// Coda 447 — le tre righe del database che il ponte scriveva sotto il suo blocco
// ─────────────────────────────────────────────────────────────────────────────

it('Coda 447 — toglie le tre righe del database sotto il blocco del ponte, nelle due forme in cui il ponte lo scriveva', function (string $blocco, string $esitoAtteso) {
    [$esito, $dopo] = proxyPonteApplica(PROXY_PONTE_ENV."\n\n".$blocco."\n".PROXY_PONTE_RIGHE_DB);

    $bloccoRimasto = $esitoAtteso === 'migrato' ? proxyPonteBloccoMigrato() : $blocco;

    expect($esito)->toBe($esitoAtteso)
        ->and(proxyPonteAssegnazioniDb($dopo))->toBe([])
        ->and(proxyPonteAssegnazioni($dopo))->toBe(['TRUSTED_PROXIES='.UpgradePatchServiceProvider::VALORE])
        ->and(proxyPonteNotaDelleRigheTolte($dopo, PROXY_PONTE_ENV."\n\n".$bloccoRimasto."\n"))->toBeTrue();
})->with([
    'il blocco dei ponti fino alla 1.11.0-beta.59, che il provider migra nello stesso passaggio' => [PROXY_PONTE_BLOCCO, 'migrato'],
    'il blocco del ponte della 1.11.0' => [PROXY_PONTE_BLOCCO_NUOVO, 'ripulito'],
]);

it('Coda 447 — il blocco già migrato dal provider della 1.11.0, con le righe ancora sotto, si ripulisce al giro dopo l aggiornamento', function () {
    // Com'è oggi nel `.env` di chi ha aggiornato dalla 1.10.0 dietro un proxy: il provider della 1.11.0 ha migrato il blocco e ha lasciato le righe.
    $bloccoMigrato = proxyPonteBloccoMigrato();

    [$esito, $dopo] = proxyPonteApplica(PROXY_PONTE_ENV."\n\n".$bloccoMigrato."\n".PROXY_PONTE_RIGHE_DB);

    expect($esito)->toBe('ripulito')
        ->and(proxyPonteAssegnazioniDb($dopo))->toBe([])
        ->and(proxyPonteNotaDelleRigheTolte($dopo, PROXY_PONTE_ENV."\n\n".$bloccoMigrato."\n"))->toBeTrue();
});

it('Coda 447 — le righe che seguono le tre del database restano, e la pulizia vale anche in fondo al file senza l ultimo a capo', function (string $dopoLeRighe) {
    $prima = PROXY_PONTE_ENV."\n\n".PROXY_PONTE_BLOCCO_NUOVO."\n".rtrim(PROXY_PONTE_RIGHE_DB, "\n").$dopoLeRighe;

    [$esito, $dopo] = proxyPonteApplica($prima);

    expect($esito)->toBe('ripulito')
        ->and(proxyPonteAssegnazioniDb($dopo))->toBe([])
        ->and($dopo)->toStartWith(PROXY_PONTE_ENV."\n\n".PROXY_PONTE_BLOCCO_NUOVO."\n");

    // Quello che veniva dopo le tre righe è rimasto uguale, compresa l'assenza dell'ultimo a capo.
    $coda = proxyPonteCodaDopoLaNota($dopo);
    expect($coda)->toBe($dopoLeRighe);
})->with([
    'in fondo al file, senza a capo' => [''],
    'in fondo al file, con l ultimo a capo' => ["\n"],
    'con altre variabili sotto' => ["\nMAIL_HOST=smtp.example.it\nMAIL_PORT=587\n"],
]);

it('Coda 447 — una riga TRUSTED_PROXIES allungata dall amministratore dentro il blocco nostro non impedisce di togliere le righe del database', function () {
    // Il blocco invita a scrivere la lista esplicita dei proxy: chi lo fa non ha scelto le righe del database che il ponte aveva scritto sotto.
    $bloccoScelto = str_replace('TRUSTED_PROXIES=PRIVATE_SUBNETS', 'TRUSTED_PROXIES=203.0.113.10,203.0.113.11', PROXY_PONTE_BLOCCO_NUOVO);

    [$esito, $dopo] = proxyPonteApplica(PROXY_PONTE_ENV."\n\n".$bloccoScelto."\n".PROXY_PONTE_RIGHE_DB);

    expect($esito)->toBe('ripulito')
        ->and(proxyPonteAssegnazioni($dopo))->toBe(['TRUSTED_PROXIES=203.0.113.10,203.0.113.11'])
        ->and(proxyPonteAssegnazioniDb($dopo))->toBe([]);
});

it('Coda 447 — dopo la pulizia il giro dopo non cambia niente', function () {
    $percorso = proxyPonteEnv(PROXY_PONTE_ENV."\n\n".PROXY_PONTE_BLOCCO_NUOVO."\n".PROXY_PONTE_RIGHE_DB);
    $provider = new UpgradePatchServiceProvider(app());

    try {
        expect($provider->sistemaIlFileEnv($percorso))->toBe('ripulito');
        $dopoUno = file_get_contents($percorso);

        expect($provider->sistemaIlFileEnv($percorso))->toBe('gia-configurato')
            ->and(file_get_contents($percorso))->toBe($dopoUno);
    } finally {
        @unlink($percorso);
    }
});

it('Coda 447 — il .env dopo la pulizia si legge con l analizzatore di Laravel, con le sue variabili e senza quelle del database', function () {
    $prima = PROXY_PONTE_ENV."DB_DATABASE=kondo\n\n".PROXY_PONTE_BLOCCO_NUOVO."\n".PROXY_PONTE_RIGHE_DB."MAIL_HOST=smtp.example.it\n";

    [, $dopo] = proxyPonteApplica($prima);

    // Lo stesso analizzatore che Laravel usa per il `.env` (vlucas/phpdotenv): la nota non deve far leggere male le righe che la seguono.
    $variabili = Dotenv\Dotenv::parse($dopo);

    expect($variabili)->toBe([
        'APP_NAME' => 'Kondomanager',
        'APP_ENV' => 'production',
        'DB_HOST' => 'localhost',
        'DB_DATABASE' => 'kondo',
        'TRUSTED_PROXIES' => 'PRIVATE_SUBNETS',
        'MAIL_HOST' => 'smtp.example.it',
    ]);
});

it('Coda 447 — non tocca le righe del database che non sono quelle che il ponte scriveva, o che non stanno sotto il suo blocco', function (string $prima) {
    [$esito, $dopo] = proxyPonteApplica($prima);

    expect($esito)->toBe('gia-configurato')
        ->and($dopo)->toBe($prima);
})->with([
    'solo le due del set di caratteri' => [PROXY_PONTE_ENV."\n\n".PROXY_PONTE_BLOCCO_NUOVO."\nDB_CHARSET=utf8\nDB_COLLATION=utf8_unicode_ci\n"],
    'con il motore scelto a mano' => [PROXY_PONTE_ENV."\n\n".PROXY_PONTE_BLOCCO_NUOVO."\nDB_CHARSET=utf8\nDB_COLLATION=utf8_unicode_ci\nDB_ENGINE=InnoDB\n"],
    'con un altro set di caratteri' => [PROXY_PONTE_ENV."\n\n".PROXY_PONTE_BLOCCO_NUOVO."\nDB_CHARSET=utf8mb4\nDB_COLLATION=utf8_unicode_ci\nDB_ENGINE=\"InnoDB ROW_FORMAT=DYNAMIC\"\n"],
    'con un altro confronto' => [PROXY_PONTE_ENV."\n\n".PROXY_PONTE_BLOCCO_NUOVO."\nDB_CHARSET=utf8\nDB_COLLATION=utf8mb4_unicode_ci\nDB_ENGINE=\"InnoDB ROW_FORMAT=DYNAMIC\"\n"],
    'con il motore seguito da un commento' => [PROXY_PONTE_ENV."\n\n".PROXY_PONTE_BLOCCO_NUOVO."\nDB_CHARSET=utf8\nDB_COLLATION=utf8_unicode_ci\nDB_ENGINE=\"InnoDB ROW_FORMAT=DYNAMIC\" # mio\n"],
    'con l intestazione del blocco preceduta da un carattere' => [PROXY_PONTE_ENV."\n\nx".PROXY_PONTE_BLOCCO_NUOVO."\n".PROXY_PONTE_RIGHE_DB],
    'in un altro ordine' => [PROXY_PONTE_ENV."\n\n".PROXY_PONTE_BLOCCO_NUOVO."\nDB_COLLATION=utf8_unicode_ci\nDB_CHARSET=utf8\nDB_ENGINE=\"InnoDB ROW_FORMAT=DYNAMIC\"\n"],
    'staccate dal blocco da una riga vuota' => [PROXY_PONTE_ENV."\n\n".PROXY_PONTE_BLOCCO_NUOVO."\n\n".PROXY_PONTE_RIGHE_DB],
    'staccate dal blocco da un commento' => [PROXY_PONTE_ENV."\n\n".PROXY_PONTE_BLOCCO_NUOVO."\n# mie\n".PROXY_PONTE_RIGHE_DB],
    'staccate dal blocco da un\'altra variabile' => [PROXY_PONTE_ENV."\n\n".PROXY_PONTE_BLOCCO_NUOVO."\nAPP_URL=https://esempio.it\n".PROXY_PONTE_RIGHE_DB],
    'con una riga in mezzo' => [PROXY_PONTE_ENV."\n\n".PROXY_PONTE_BLOCCO_NUOVO."\nDB_CHARSET=utf8\nAPP_URL=https://esempio.it\nDB_COLLATION=utf8_unicode_ci\nDB_ENGINE=\"InnoDB ROW_FORMAT=DYNAMIC\"\n"],
    'sopra il blocco e non sotto' => [PROXY_PONTE_ENV.PROXY_PONTE_RIGHE_DB."\n".PROXY_PONTE_BLOCCO_NUOVO."\n"],
    'sotto una riga TRUSTED_PROXIES scritta a mano' => [PROXY_PONTE_ENV."TRUSTED_PROXIES=*\n".PROXY_PONTE_RIGHE_DB],
    'sotto un blocco scritto a mano, con un suo commento' => [PROXY_PONTE_ENV."# Proxy del mio hosting\nTRUSTED_PROXIES=10.0.0.1\n".PROXY_PONTE_RIGHE_DB],
    'con una riga in mezzo al blocco' => [PROXY_PONTE_ENV."\n\n# --- KondoManager: proxy fidati (scritto automaticamente) ---\nAPP_URL=https://esempio.it\nTRUSTED_PROXIES=PRIVATE_SUBNETS\n".PROXY_PONTE_RIGHE_DB],
    'con i fine riga di Windows' => [str_replace("\n", "\r\n", PROXY_PONTE_ENV."\n\n".PROXY_PONTE_BLOCCO_NUOVO."\n".PROXY_PONTE_RIGHE_DB)],
    'con l intestazione del blocco del ponte seguita da uno spazio' => [PROXY_PONTE_ENV."\n\n# --- AUTO-CONFIGURED BY V1.9 UPGRADER --- \n# Fix per HTTPS e Cron Job su Hosting Condivisi/Proxy\nTRUSTED_PROXIES=*\n".PROXY_PONTE_RIGHE_DB],
    'con l intestazione del blocco del provider seguita da uno spazio' => [PROXY_PONTE_ENV."\n\n".str_replace("automaticamente) ---\n", "automaticamente) --- \n", PROXY_PONTE_BLOCCO_NUOVO)."\n".PROXY_PONTE_RIGHE_DB],
]);

it('Coda 447 — le tre righe del database senza nessun blocco nostro restano, e il provider aggiunge il suo blocco senza toccarle', function () {
    $prima = PROXY_PONTE_ENV.PROXY_PONTE_RIGHE_DB;

    [$esito, $dopo] = proxyPonteApplica($prima);

    expect($esito)->toBe('aggiunto')
        ->and($dopo)->toStartWith($prima)
        ->and(proxyPonteAssegnazioniDb($dopo))->toBe(['DB_CHARSET=utf8', 'DB_COLLATION=utf8_unicode_ci', 'DB_ENGINE="InnoDB ROW_FORMAT=DYNAMIC"']);
});

it('Coda 447 — se l espressione per le righe del database non si può valutare, il .env resta intero', function () {
    // Come la prova di sopra, con un blocco già migrato e le tre righe sotto: in un processo a parte, con la compilazione JIT spenta e il
    // limite di backtracking a zero, `preg_replace_callback` restituisce null e le righe non si toccano.
    $prima = PROXY_PONTE_ENV."\n\n".PROXY_PONTE_BLOCCO_NUOVO."\n".PROXY_PONTE_RIGHE_DB;
    $percorso = proxyPonteEnv($prima);

    $script = 'require $argv[1]."/vendor/autoload.php";'
        .'$p = new App\Providers\UpgradePatchServiceProvider(new Illuminate\Foundation\Application(sys_get_temp_dir()));'
        .'echo $p->sistemaIlFileEnv($argv[2]);';

    try {
        $processo = new Symfony\Component\Process\Process(
            [PHP_BINARY, '-d', 'pcre.jit=0', '-d', 'pcre.backtrack_limit=0', '-r', $script, base_path(), $percorso],
            null, null, null, 60
        );
        $processo->run();

        expect($processo->getOutput())->toBe('gia-configurato')
            ->and(file_get_contents($percorso))->toBe($prima);
    } finally {
        @unlink($percorso);
    }
});
