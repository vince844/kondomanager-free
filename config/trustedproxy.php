<?php

// Le reti private davvero: quelle da cui un proxy si connette all'applicazione e da cui nessuno arriva da
// internet. Il segno PRIVATE_SUBNETS (e il suo sinonimo private_ranges) nel .env vale questo elenco.
$retiPrivate = [
    '127.0.0.0/8', '::1/128',                           // loopback
    '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16',    // RFC 1918
    '100.64.0.0/10',                                    // CGNAT (RFC 6598), molti hosting condivisi
    '169.254.0.0/16', 'fe80::/10',                      // link-local
    'fc00::/7',                                         // ULA, le reti private IPv6
    // Le stesse reti IPv4 come le riporta un server a doppio stack (::ffff:a.b.c.d): solo loro, non
    // ::ffff:0:0/96, che comprende ogni IPv4 di internet.
    '::ffff:127.0.0.0/104', '::ffff:10.0.0.0/104', '::ffff:172.16.0.0/108', '::ffff:192.168.0.0/112',
    '::ffff:100.64.0.0/106', '::ffff:169.254.0.0/112',
];

// Il valore del .env, con il segno sostituito dall'elenco; ogni altro valore ('*', una lista di
// indirizzi, nessun valore) arriva al middleware com'era.
$proxiesFidati = env('TRUSTED_PROXIES');

if (is_string($proxiesFidati)) {
    $vociDelValore = array_map('trim', explode(',', $proxiesFidati));

    if (array_intersect($vociDelValore, ['PRIVATE_SUBNETS', 'private_ranges']) !== []) {
        $proxiesFidati = array_values(array_unique(array_merge(
            array_diff($vociDelValore, ['PRIVATE_SUBNETS', 'private_ranges', '']),
            $retiPrivate
        )));
    }
}

return [

    /*
    |--------------------------------------------------------------------------
    | Proxy Fidati (Trusted Proxies)
    |--------------------------------------------------------------------------
    |
    | Elenco dei reverse proxy di cui Laravel si può fidare per leggere gli
    | header "X-Forwarded-*" (schema http/https, IP client reale, host, porta).
    | Il middleware globale Illuminate\Http\Middleware\TrustProxies legge questo
    | valore da config('trustedproxy.proxies') A OGNI RICHIESTA, nel momento
    | giusto del ciclo di vita — quando la richiesta è già disponibile.
    |
    | PERCHÉ È QUI E NON IN bootstrap/app.php:
    | La configurazione dei trusted proxy NON può essere fatta leggendo
    | env('TRUSTED_PROXIES') dentro il closure ->withMiddleware() di
    | bootstrap/app.php. Quel closure gira quando lo HttpKernel viene RISOLTO
    | (Application::handleRequest() -> make(Kernel)), cioè PRIMA che i
    | bootstrapper LoadEnvironmentVariables e LoadConfiguration abbiano
    | caricato il .env e la config. In quel punto NÉ env() NÉ config() vedono
    | ancora i valori del .env: env('TRUSTED_PROXIES') torna sempre null, la
    | condizione non scatta mai e i proxy non venivano MAI configurati,
    | qualunque cosa fosse scritta nel .env. (Verificato empiricamente su
    | hosting reale: env letto in quel punto = null, letto a request-time = '*'.)
    | Questo file di config viene invece valutato dal bootstrapper
    | LoadConfiguration, DOPO che il .env è stato caricato: qui env() funziona.
    | Il valore risolto sopravvive anche a `php artisan config:cache`.
    |
    | VALORE CONSIGLIATO — dalla beta.65: PRIVATE_SUBNETS
    | È un segno che questo file sostituisce con l'elenco delle reti private ($retiPrivate, qui
    | sopra): loopback, RFC1918, CGNAT 100.64/10, link-local, e le reti IPv6 locali (ULA fc00::/7,
    | fe80::/10). Si fida solo di un proxy che si connette da una di quelle reti — cioè il caso
    | dell'hosting condiviso e di nginx/apache davanti a php-fpm — e da internet NON è
    | falsificabile, perché chi arriva da fuori ha un REMOTE_ADDR pubblico.
    |
    | L'elenco è nostro e comprende solo reti da cui non si arriva da internet: il segno non arriva
    | così com'è a Symfony, che con lo stesso nome (IpUtils::PRIVATE_SUBNETS) ha un elenco più
    | largo. È ciò che tiene veri i limiti per indirizzo (login, sfida 2FA): ci si fida solo del
    | proxy, e l'indirizzo di chi arriva da fuori resta il suo. Per chi ha già
    | PRIVATE_SUBNETS nel .env vale ogni proxy su una rete privata vera,
    | anche quando il server lo riporta come IPv6 (::ffff:172.18.0.2): quelle forme sono
    | nell'elenco. Anche il nome che Symfony accetta come sinonimo, private_ranges, vale così.
    |
    | È il valore che l'installatore scrive da solo (UpgradePatchServiceProvider), e ha sostituito
    | il precedente '*'. Fino alla beta.64 quel '*' veniva scritto in base a HTTP_HOST, cioè a
    | un'intestazione mandata dal client: vedi la coda ㉞ in docs/roadmap.md.
    |
    | Se l'hosting mette il proprio proxy su un indirizzo PUBBLICO, PRIVATE_SUBNETS non lo copre e
    | serve la lista esplicita degli IP di quel proxy. Non '*'.
    |
    | SICUREZZA — valori possibili di TRUSTED_PROXIES nel .env:
    |   - PRIVATE_SUBNETS       => CONSIGLIATO: vedi sopra. Sicuro ovunque, efficace dove il proxy
    |                              è su rete privata o loopback.
    |   - non impostato / null  => DEFAULT SICURO: non ci si fida di nessun
    |                              proxy, gli header X-Forwarded-* vengono
    |                              ignorati. Un attaccante NON può falsificare
    |                              il proprio IP o lo schema. È la scelta
    |                              corretta per VPS "nude" dove PHP è esposto
    |                              direttamente.
    |   - '*'                   => SCONSIGLIATO dalla beta.65, preferire
    |                              PRIVATE_SUBNETS. Si fida del proxy che si connette
    |                              direttamente (REMOTE_ADDR). Usare SOLO su
    |                              hosting condiviso / dietro Cloudflare dove
    |                              l'unico modo per raggiungere PHP è passare
    |                              dal proxy dell'host. Se l'origine è
    |                              raggiungibile direttamente (bypass del
    |                              proxy), '*' consente lo spoofing di
    |                              X-Forwarded-For: preferire allora la lista
    |                              esplicita di IP.
    |   - '10.0.0.1,10.0.0.2'   => Lista esplicita: si fida solo di quegli IP.
    |
    | Gli header fidati restano quelli di default del middleware Laravel
    | (X-Forwarded-For/Host/Port/Proto/Prefix), che includono già Proto: è
    | quello che serve per far riconoscere correttamente https dietro proxy.
    |
    | ATTENZIONE (superficie throttle/brute-force): request()->ip() alimenta
    | non solo l'allowlist del webhook cron (protetta comunque da token
    | segreto) ma anche le throttle key di login (email|ip) e 2FA
    | (userid|2fa|ip). Se si usa '*' su un'origine raggiungibile anche fuori
    | dal proxy, un attaccante può ruotare X-Forwarded-For falsificati per
    | generare bucket di throttle sempre nuovi e aggirare il blocco a 5
    | tentativi — cioè brute-force non limitato su password/2FA. Su origini
    | direttamente raggiungibili preferire SEMPRE la lista IP esplicita del
    | proxy (e/o firewallare l'origine così che solo il proxy la raggiunga),
    | non '*'.
    |
    */

    'proxies' => $proxiesFidati,

];
