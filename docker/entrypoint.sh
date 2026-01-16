#!/bin/sh
set -e

echo "🚀 Kondomanager container starting..."

# --------------------------------------------------
# Generate APP_KEY if missing
# --------------------------------------------------
if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "base64:" ]; then
  if [ "$AUTO_KEYGEN" = "true" ]; then
    echo "🔑 Generating APP_KEY..."
    php artisan key:generate --force
  else
    echo "⚠️ APP_KEY missing and AUTO_KEYGEN=false"
  fi
fi

# --------------------------------------------------
# Storage link
# --------------------------------------------------
if [ ! -L "public/storage" ]; then
  echo "🔗 Creating storage symlink..."
  php artisan storage:link || true
fi

# --------------------------------------------------
# Database migrations (optional)
# --------------------------------------------------
if [ "$AUTO_MIGRATE" = "true" ]; then
  echo "🗄️ Running database migrations..."
  php artisan migrate --force
else
  echo "ℹ️ AUTO_MIGRATE=false, skipping migrations"
fi

echo "✅ Kondomanager ready."

# --------------------------------------------------
# Hand over to PHP-FPM
# --------------------------------------------------
exec "$@"
