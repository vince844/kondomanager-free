#!/bin/bash
set -e

# Il worker non esegue setup (già fatto dall'app container)
# Attende solo che il DB sia disponibile prima di avviare Supervisor

until nc -z -w30 db 3306; do
    echo "⏳ Worker in attesa del DB..."
    sleep 2
done

echo "✅ Worker Pronto — avvio Supervisor"
exec "$@"
