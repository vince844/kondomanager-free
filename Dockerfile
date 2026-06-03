FROM php:8.4-fpm-bookworm

# Installazione dipendenze
RUN apt-get update && apt-get install -y \
    nginx git curl unzip libpng-dev libonig-dev libxml2-dev libzip-dev libicu-dev zip \
    && curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip intl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Configurazione Nginx per Laravel
RUN echo 'server { \
    listen 80; \
    root /var/www/public; \
    index index.php; \
    location / { try_files $uri $uri/ /index.php?$query_string; } \
    location ~ \.php$ { \
        fastcgi_pass 127.0.0.1:9000; \
        fastcgi_index index.php; \
        include fastcgi_params; \
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; \
    } \
}' > /etc/nginx/sites-available/default

WORKDIR /var/www
COPY . .

# Installazione e build
RUN composer install --no-dev --optimize-autoloader && npm install && npm run build

# CORREZIONE PERMESSI: Tutto di proprietà di www-data
RUN chown -R www-data:www-data /var/www /var/lib/nginx /var/log/nginx

# Script di avvio (PHP-FPM + Nginx)
RUN echo '#!/bin/sh\nphp-fpm -D\nnginx -g "daemon off;"' > /start.sh && chmod +x /start.sh

EXPOSE 80
CMD ["/start.sh"]