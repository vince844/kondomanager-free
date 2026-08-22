<?php

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
    | È un token che Symfony sostituisce con l'elenco delle reti private (loopback, RFC1918,
    | CGNAT 100.64/10 e gli equivalenti IPv6). Si fida solo di un proxy che si connette da una di
    | quelle reti — cioè il caso dell'hosting condiviso e di nginx/apache davanti a php-fpm — e da
    | internet NON è falsificabile, perché chi arriva da fuori ha un REMOTE_ADDR pubblico.
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

    'proxies' => env('TRUSTED_PROXIES'),

];
