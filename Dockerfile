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
RUN rm -f /etc/nginx/sites-enabled/default
RUN cat > /etc/nginx/conf.d/default.conf <<'EOF'
server {
    listen 80;
    root /var/www/public;
    index index.php;
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
EOF

WORKDIR /var/www
COPY . .

# Installazione e build
RUN composer install --no-dev --optimize-autoloader && npm install && npm run build

# CORREZIONE PERMESSI: Tutto di proprietà di www-data
RUN chown -R www-data:www-data /var/www /var/lib/nginx /var/log/nginx

# SCRIPT DEL WORKER (Loop infinito per la resilienza)
RUN cat > /worker.sh <<'EOF'
#!/bin/sh
while true; do
    php /var/www/artisan queue:work
    sleep 3
done
EOF
RUN chmod +x /worker.sh

# SCRIPT DI AVVIO PRINCIPALE
RUN cat > /start.sh <<'EOF'
#!/bin/sh
php-fpm -D
su -s /bin/sh www-data -c "/worker.sh" > /var/log/nginx/worker.log 2>&1 &
su -s /bin/sh www-data -c "php /var/www/artisan schedule:work" > /var/log/nginx/scheduler.log 2>&1 &
nginx -g "daemon off;"
EOF
RUN chmod +x /start.sh

EXPOSE 80
CMD ["/start.sh"]