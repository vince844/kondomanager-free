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
 * si tocca mai; le righe che il ponte aggiunge sotto il blocco (le impostazioni del database) restano.
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

it('migra il blocco scritto dal ponte di una versione installata, come il blocco del provider', function () {
    [$esito, $dopo] = proxyPonteApplica(PROXY_PONTE_ENV."\n\n".PROXY_PONTE_BLOCCO."\n");

    expect($esito)->toBe('migrato')
        ->and($dopo)->toBe(PROXY_PONTE_ENV."\n\n".proxyPonteBloccoMigrato()."\n")
        ->and(proxyPonteAssegnazioni($dopo))->toBe(['TRUSTED_PROXIES='.UpgradePatchServiceProvider::VALORE]);
});

it('lascia al loro posto le righe del database che il ponte scrive sotto il blocco', function () {
    [$esito, $dopo] = proxyPonteApplica(PROXY_PONTE_ENV."\n\n".PROXY_PONTE_BLOCCO."\n".PROXY_PONTE_RIGHE_DB);

    expect($esito)->toBe('migrato')
        ->and($dopo)->toBe(PROXY_PONTE_ENV."\n\n".proxyPonteBloccoMigrato()."\n".PROXY_PONTE_RIGHE_DB);
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
