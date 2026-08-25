FROM php:8.4-fpm-bookworm

# Installazione dipendenze e Node.js 20
RUN apt-get update && apt-get install -y \
    git curl unzip libpng-dev libonig-dev libxml2-dev \
    libzip-dev libicu-dev netcat-openbsd gnupg ca-certificates supervisor \
    && curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip intl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
# I limiti di caricamento devono concordare con `client_max_body_size` di nginx (25M in
# docker/standard/nginx/default.conf): vince sempre il più basso della catena, e l'utente riceve un
# errore che non spiega niente. `upload_max_filesize` è il numero che il programma mostra, letto da
# App\Support\LimiteCaricamento. Aggiunti nella beta.58 dopo una segnalazione dal forum.
RUN printf '%b' 'upload_max_filesize = 20M\npost_max_size = 25M\nmax_execution_time = 120\n' \
    > /usr/local/etc/php/conf.d/zz-kondomanager-upload.ini

WORKDIR /var/www
RUN usermod -u 1000 www-data
# Copia supervisord.conf nella posizione standard di sistema
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
# NOTA: i permessi di esecuzione su entrypoint.sh e worker-entrypoint.sh
# devono essere impostati sull'host con chmod +x (vedi docs/docker_local_dev.it.md)
# Il volume mount in docker-compose sovrascrive i file dell'immagine.
ENTRYPOINT ["/var/www/docker/standard/entrypoint.sh"]
CMD ["php-fpm"]