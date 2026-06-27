FROM dunglas/frankenphp:php8.4-bookworm

RUN apt-get update && apt-get install -y \
    git curl unzip libpng-dev libonig-dev libxml2-dev \
    libzip-dev libicu-dev netcat-openbsd gnupg ca-certificates supervisor \
    && curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && install-php-extensions pdo_mysql mbstring exif pcntl bcmath gd zip intl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www
RUN usermod -u 1000 www-data
ENV SERVER_NAME=:80
# Copia supervisord.conf nella posizione standard di sistema
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
# NOTA: i permessi di esecuzione su entrypoint.sh e worker-entrypoint.sh
# devono essere impostati sull'host con chmod +x
ENTRYPOINT ["/var/www/docker/frankenphp/entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile", "--adapter", "caddyfile"]