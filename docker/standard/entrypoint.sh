#!/bin/bash
set -e

# Configurazione .env
if [ ! -f ".env" ]; then cp .env.example .env; fi
sed -i 's/^#\?\s*DB_HOST=.*/DB_HOST=db/' .env
sed -i 's/^#\?\s*DB_PORT=.*/DB_PORT=3306/' .env
sed -i 's/^#\?\s*DB_DATABASE=.*/DB_DATABASE=kondomanager_dev/' .env
sed -i 's/^#\?\s*DB_USERNAME=.*/DB_USERNAME=root/' .env
sed -i 's/^#\?\s*DB_PASSWORD=.*/DB_PASSWORD=root/' .env
# APP_URL: sovrascrive solo se è il default di Laravel o Herd, per evitare problemi locali.
# Se l'utente imposta un dominio reale (es. per Reverse Proxy sul NAS), lo preserva.
CURRENT_APP_URL=$(grep "^APP_URL=" .env | cut -d '=' -f2 || true)
if [[ "$CURRENT_APP_URL" == *"kondomanager-free.test"* ]] || [[ "$CURRENT_APP_URL" == "http://localhost" ]] || [[ -z "$CURRENT_APP_URL" ]]; then
    sed -i 's|^#\?\s*APP_URL=.*|APP_URL=http://localhost:8889|' .env
fi

# Dipendenze PHP
if [ ! -d "vendor" ]; then composer install --no-interaction --optimize-autoloader; fi
if ! grep -q "APP_KEY=base64" .env || [ -z "$(grep APP_KEY .env | cut -d '=' -f2)" ]; then
    php artisan key:generate --force
fi

# Attesa DB
until nc -z -v -w30 db 3306; do echo "⏳ Attesa DB..."; sleep 2; done

# Build asset solo se non già compilati (evita rebuild ad ogni riavvio)
if [ ! -d "node_modules" ]; then npm install; fi
if [ ! -d "public/build" ]; then
    echo "🔨 Build asset frontend..."
    npm run build
fi

php artisan migrate --force

# Seed solo se il DB è vuoto (evita duplicati ad ogni riavvio)
USER_COUNT=$(php artisan tinker --execute="echo \App\Models\User::count();" 2>/dev/null | tail -1 || echo "0")
if [ "$USER_COUNT" = "0" ]; then
    echo "🌱 Esecuzione seeder iniziale..."
    php artisan db:seed --force
fi

echo "✅ KondoManager Standard Pronto!"
exec "$@"