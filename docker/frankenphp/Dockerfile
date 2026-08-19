FROM dunglas/frankenphp:php8.4-bookworm

RUN apt-get update && apt-get install -y \
    git curl unzip libpng-dev libonig-dev libxml2-dev \
    libzip-dev libicu-dev netcat-openbsd gnupg ca-certificates supervisor \
    && curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && install-php-extensions pdo_mysql mbstring exif pcntl bcmath gd zip intl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
# ============================================================================
# LIMITI DI CARICAMENTO
# ============================================================================
# ⚠️ Questa immagine era rimasta scoperta: la revisione della beta.58 ha fatto notare che è proprio
# quella consigliata a chi vuole prestazioni migliori, quindi il sintomo della segnalazione — un PDF
# da 4 MB rifiutato — sarebbe rimasto lì mentre il changelog dichiarava il limite corretto.
#
# Caddy (dentro FrankenPHP) non ha il limite di corpo di nginx: qui basta allineare PHP.
#   upload_max_filesize  20M   ← il singolo file, ed è il numero che il programma mostra
#   post_max_size        25M   ← l'intera richiesta: deve stare sopra il precedente
# Gli stessi valori di `Dockerfile` e `docker/standard/Dockerfile`: se uno cambia, cambiano tutti.
RUN printf '%b' 'upload_max_filesize = 20M\npost_max_size = 25M\nmax_execution_time = 120\n' \
    > /usr/local/etc/php/conf.d/zz-kondomanager-upload.ini

WORKDIR /var/www
RUN usermod -u 1000 www-data
ENV SERVER_NAME=:80
# Copia supervisord.conf nella posizione standard di sistema
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
# NOTA: i permessi di esecuzione su entrypoint.sh e worker-entrypoint.sh
# devono essere impostati sull'host con chmod +x
ENTRYPOINT ["/var/www/docker/frankenphp/entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile", "--adapter", "caddyfile"]