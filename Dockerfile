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
# ============================================================================
# LIMITI DI CARICAMENTO
# ============================================================================
# I tre valori della catena devono concordare, altrimenti vince il più basso e l'utente riceve un
# errore che non spiega niente. Aggiunti nella beta.58, dopo una segnalazione dal forum: chi usava
# questa immagine non riusciva a caricare nemmeno un PDF da 1,5 MB, perché nginx si fermava a 1 MB
# mentre la schermata dichiarava 20.
#
#   nginx  client_max_body_size 25M   ← il tetto dell'intera richiesta
#   php    post_max_size        25M   ← idem, lato PHP: deve stare sopra upload_max_filesize
#   php    upload_max_filesize  20M   ← il singolo file, ed è il numero che l'utente vede
#
# `LimiteCaricamento` legge i due valori PHP e mostra il più basso; quello di nginx non è
# leggibile da PHP, ed è la ragione per cui va tenuto **più alto** degli altri due invece che uguale.
RUN printf '%b' 'upload_max_filesize = 20M\npost_max_size = 25M\nmax_execution_time = 120\n' \
    > /usr/local/etc/php/conf.d/zz-kondomanager-upload.ini

RUN cat > /etc/nginx/conf.d/default.conf <<'EOF'
server {
    listen 80;
    root /var/www/public;
    index index.php;
    # Senza questa riga nginx si ferma a 1 MB (il suo default) e risponde 413 prima che PHP veda
    # la richiesta: nessuno dei messaggi dell'applicazione arriverebbe mai all'utente. Va tenuta
    # coerente con `post_max_size` qui sotto e con il tetto di App\Support\LimiteCaricamento.
    client_max_body_size 30M;   # ⚠️ sopra `post_max_size` (25M), non uguale: a parità di valore
                                # scarta sempre nginx per primo, con la sua 413 spoglia, e la
                                # pagina d'errore del programma non si vede mai. Ripasso .58.

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