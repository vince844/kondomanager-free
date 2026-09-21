# syntax=docker/dockerfile:1.7
#
# Immagine di produzione di KondoManager: un container con nginx, php-fpm, worker delle code e
# scheduler sotto supervisor. È l'immagine canonica; docker/standard/ è lo stack di sviluppo con
# bind mount (non produce un'immagine pubblicabile), docker/frankenphp/ è sperimentale.
#
# Principi:
# - L'immagine è immutabile: dipendenze e asset si costruiscono qui dentro, mai copiati dall'host.
# - La configurazione arriva SOLO dalle variabili d'ambiente del container: nessun .env
#   nell'immagine (vedi .dockerignore) e nessun `config:cache` in fase di build, che
#   congelerebbe i valori vuoti di questo stadio dentro bootstrap/cache/config.php e ignorerebbe
#   per sempre le variabili passate a runtime. Eventuali cache si fanno all'avvio.
# - Il codice appartiene a root ed è in sola lettura per il processo web; www-data scrive solo
#   in storage/ e bootstrap/cache. Un aggiornamento è un'immagine nuova, non una scrittura
#   sull'albero.
# - Ogni stadio contiene solo ciò che gli serve: Node non entra nell'immagine finale, Composer
#   nemmeno.
#
# Costruzione:   docker build -t kondomanager-core:dev .
# Avvio (prova): docker run --rm -p 8080:80 -e APP_KEY=base64:... -e DB_HOST=... \
#                  -e INSTALL_ADMIN_EMAIL=... -e INSTALL_ADMIN_PASSWORD=... kondomanager-core:dev
#
# Il primo avvio non è interattivo: docker/production/entrypoint.sh attende il database, installa
# con le variabili INSTALL_* se il database è vuoto (`php artisan kondomanager:installa`), allinea
# un database esistente all'immagine nuova (`php artisan kondomanager:aggiorna`: migrazioni e
# seeder mirati), costruisce le cache e solo allora avvia i processi. `/up`
# risponde 200 solo a installazione fatta e migrazioni applicate (App\Http\Controllers\System\
# SaluteController): è ciò che HEALTHCHECK, e chi orchestra i container, interrogano.
# Le variabili che l'immagine legge: .env.example le documenta tutte.

# ---------------------------------------------------------------------------------------------
# base: PHP con le estensioni, nginx e supervisor. Comune a vendor e runtime così le estensioni
# si installano una volta sola e composer verifica la piattaforma giusta.
# ---------------------------------------------------------------------------------------------
FROM php:8.4-fpm-bookworm AS base

# I pacchetti -dev servono solo a compilare le estensioni: dopo restano le librerie di runtime
# (trovate con ldd sui .so compilati, com'è d'uso nell'immagine ufficiale di PHP) e i -dev se ne
# vanno con `--auto-remove`: questo strato pesa 60 MB invece di 200. La catena di compilazione
# dell'immagine ufficiale (gcc, make: $PHPIZE_DEPS, 314 MB) invece resta: sta in uno strato
# dell'immagine di base, e cancellare lì dentro da uno strato successivo non toglie un byte.
RUN savedAptMark="$(apt-mark showmanual)" \
    && apt-get update && apt-get install -y --no-install-recommends \
        nginx supervisor curl unzip zip \
        libpng-dev libonig-dev libxml2-dev libzip-dev libicu-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring exif pcntl bcmath gd zip intl opcache \
    && apt-mark auto '.*' > /dev/null \
    && apt-mark manual $savedAptMark nginx supervisor curl unzip zip > /dev/null \
    && ldd "$(php -r 'echo ini_get("extension_dir");')"/*.so \
        | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) { next }; gsub("^/(usr/)?", "", so); print so }' \
        | sort -u | xargs -r dpkg-query --search | cut -d: -f1 | sort -u | xargs -rt apt-mark manual \
    && apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false \
    && apt-get clean && rm -rf /var/lib/apt/lists/* \
    # I log di nginx sullo stdout/stderr del container, come nell'immagine ufficiale di nginx:
    # il file di default resterebbe dentro il container e crescerebbe fino alla ricreazione.
    && ln -sf /dev/stdout /var/log/nginx/access.log \
    && ln -sf /dev/stderr /var/log/nginx/error.log

COPY docker/production/php.ini /usr/local/etc/php/conf.d/zz-kondomanager.ini

WORKDIR /var/www

# ---------------------------------------------------------------------------------------------
# vendor: dipendenze PHP di produzione. Prima il solo lock (così lo strato è riusabile finché non
# cambiano le dipendenze), poi il sorgente per l'autoloader ottimizzato. Niente script di
# Composer: `package:discover` gira nello stadio finale, con l'applicazione completa.
# ---------------------------------------------------------------------------------------------
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress
COPY . .
RUN composer dump-autoload --no-dev --optimize --no-interaction

# ---------------------------------------------------------------------------------------------
# assets: il bundle Vite. Ha bisogno di vendor/tightenco/ziggy (alias `ziggy-js` in vite.config.ts)
# e di lang/ (il plugin i18n compila le traduzioni PHP dentro il bundle), quindi copia il sorgente
# intero più quel pacchetto dallo stadio vendor. Picco di memoria misurato: ~1,1 GB.
# ---------------------------------------------------------------------------------------------
FROM node:20-bookworm-slim AS assets

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
COPY --from=vendor /var/www/vendor/tightenco/ziggy ./vendor/tightenco/ziggy
RUN npm run build

# ---------------------------------------------------------------------------------------------
# runtime: l'immagine finale.
# ---------------------------------------------------------------------------------------------
FROM base AS runtime

# Valori di default sicuri: un'immagine di produzione non va mai in debug per dimenticanza.
# Chi la avvia li può sovrascrivere; APP_KEY invece non ha default di proposito — senza,
# l'entrypoint si ferma con un messaggio, ed è la cosa giusta.
# DB_CONNECTION=mysql: senza, il default di Laravel è sqlite, che in un container senza volume
# significa un database che sparisce alla ricreazione — in silenzio.
# Cache, sessioni e code sul database: un container solo, nessun servizio in più da tenere su;
# la tabella `cache` la pulisce lo scheduler una volta a settimana (routes/console.php).
# INSTALLER_ENABLED=false: niente wizard (l'installazione la fa l'entrypoint), niente controllo
# né aggiornamento automatico (l'aggiornamento è un'immagine nuova).
# TRUSTED_PROXIES=PRIVATE_SUBNETS: davanti c'è sempre un proxy (Traefik, Coolify, il router di
# casa) che si connette da rete privata; da internet non è falsificabile.
# KM_CONTAINER=1: dice al programma che è nell'immagine. L'unica conseguenza: i documenti su disco
# locale senza un volume sono effimeri, e /up risponde 503 finché non c'è un volume su
# /var/www/storage o DOCUMENTI_DISK=s3 (config/kondomanager.php).
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=mysql \
    CACHE_STORE=database \
    SESSION_DRIVER=database \
    QUEUE_CONNECTION=database \
    INSTALLER_ENABLED=false \
    TRUSTED_PROXIES=PRIVATE_SUBNETS \
    KM_CONTAINER=1

# L'ancora al repository: GitHub collega il package a kondomanager-free da questa etichetta, e
# chi fa `docker pull` sa da dove viene l'immagine.
LABEL org.opencontainers.image.source="https://github.com/vince844/kondomanager-free" \
      org.opencontainers.image.description="KondoManager, gestionale open source per il condominio" \
      org.opencontainers.image.licenses="AGPL-3.0"

RUN rm -f /etc/nginx/sites-enabled/default
COPY docker/production/nginx.conf /etc/nginx/conf.d/default.conf
# Al posto del file di Debian, così anche `supervisorctl status` (senza opzioni) lo trova.
COPY docker/production/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/production/entrypoint.sh /usr/local/bin/kondomanager-entrypoint
RUN chmod 755 /usr/local/bin/kondomanager-entrypoint

COPY . .
COPY --from=vendor /var/www/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

# La struttura di storage/ va ricreata: .dockerignore ne esclude il contenuto, non vogliamo i
# file di chi costruisce. `package:discover` scrive bootstrap/cache/{packages,services}.php:
# dipende solo da vendor, non dall'ambiente, quindi può stare qui. `storage:link` crea il
# collegamento public/storage che a runtime www-data non potrebbe più creare (public/ è di root).
RUN mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
             storage/framework/sessions storage/framework/testing storage/framework/views \
             storage/logs bootstrap/cache \
    && php artisan package:discover --ansi \
    && php artisan storage:link \
    && chown -R www-data:www-data storage bootstrap/cache \
    && find . -path ./storage -prune -o -path ./bootstrap/cache -prune -o -type d -exec chmod 755 {} + \
    && find . -path ./storage -prune -o -path ./bootstrap/cache -prune -o -type f -exec chmod 644 {} + \
    && chmod 755 artisan

# /up (App\Http\Controllers\System\SaluteController): 200 solo se l'installazione è chiusa, il
# database risponde e non ci sono migrazioni da applicare; 503 altrimenti. start-period copre il
# primo avvio: attesa del database, installazione e cache — durante quel tempo i fallimenti non
# contano.
HEALTHCHECK --interval=30s --timeout=5s --start-period=90s --retries=3 \
    CMD curl -fsS http://127.0.0.1/up > /dev/null || exit 1

EXPOSE 80
ENTRYPOINT ["kondomanager-entrypoint"]
