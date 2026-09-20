#!/bin/sh
# Primo avvio e ogni avvio dell'immagine di produzione. Gira come root (supervisor deve poter
# lanciare nginx e php-fpm), ma tutto ciò che è artisan gira come www-data: è l'utente che
# possiede storage/ e bootstrap/cache, e un file scritto da root lì dentro sarebbe illeggibile
# per il processo web.
#
# Ordine, e ogni passo è ripetibile:
#   1. la struttura di storage/ (un volume montato vuoto la nasconde) e il collegamento pubblico;
#   2. `kondomanager:installa`: attende il database; su un database vuoto installa con le
#      variabili INSTALL_*; su uno già installato non fa nulla;
#   3. `kondomanager:aggiorna`: le migrazioni di un'immagine nuova più i seeder mirati che un
#      aggiornamento richiede (permessi e ruoli, comuni, ATECO, versione registrata), prima di
#      servire una sola richiesta. Senza lock: un'installazione ha un container solo, e un lock
#      rimasto da un processo ucciso terrebbe fermo l'aggiornamento per un'ora senza dirlo;
#   4. le cache di configurazione, rotte, viste ed eventi — qui, a runtime, e non nel build:
#      in fase di build le variabili non ci sono, e una cache fatta lì congelerebbe i valori
#      vuoti per sempre;
#   5. supervisor, che tiene su nginx, php-fpm, il worker delle code e lo scheduler.
#
# Se un passo fallisce il container esce con errore e non serve niente: `/up` non risponde e
# chi orchestra lo vede. È meglio di un'istanza che risponde 500 al primo clic.
set -eu

cd /var/www

artisan() {
    runuser -u www-data -- php artisan "$@"
}

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY manca: senza, sessioni e dati cifrati non sono leggibili. Passala come variabile d'ambiente (php artisan key:generate --show per crearne una)." >&2
    exit 1
fi

# 1. Struttura di storage/. `mkdir -p` non tocca ciò che esiste; il chown è sulle cartelle di
#    struttura, non ricorsivo sui documenti: su un archivio grande costerebbe minuti a ogni avvio.
for cartella in storage/app/private storage/app/public storage/framework/cache/data \
                storage/framework/sessions storage/framework/testing storage/framework/views \
                storage/logs bootstrap/cache; do
    mkdir -p "$cartella"
done
chown www-data:www-data storage storage/app storage/app/private storage/app/public \
    storage/framework storage/framework/cache storage/framework/cache/data \
    storage/framework/sessions storage/framework/testing storage/framework/views \
    storage/logs bootstrap/cache

# Il collegamento public/storage lo crea il Dockerfile; se public/ è un volume, lo ricrea qui.
if [ ! -e public/storage ]; then
    artisan storage:link --force
fi

# Una cache lasciata da un avvio precedente non deve guidare l'installazione o le migrazioni
# di questo: si riparte da zero e si ricostruisce in fondo. Solo le cache su file: `cache:clear`
# passa dal database, che a questo punto può non rispondere ancora.
artisan config:clear --quiet
artisan route:clear --quiet
artisan view:clear --quiet
artisan event:clear --quiet

# 2. Installazione (idempotente) — attende il database fino a 120 secondi.
artisan kondomanager:installa --attendi="${KM_ATTESA_DATABASE:-120}"

# 3. Allineamento al codice dell'immagine (migrazioni e seeder mirati).
artisan kondomanager:aggiorna

# 4. Cache di runtime.
artisan optimize

# 5. Processi.
exec supervisord -c /etc/supervisor/supervisord.conf
