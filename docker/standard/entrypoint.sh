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
# Le dipendenze si installano SEMPRE, non solo quando la cartella manca.
# «vendor esiste» non vuol dire «vendor è quello giusto»: dopo un git pull da una
# versione precedente la cartella c'è, con dentro le librerie vecchie, quindi
# composer veniva saltato e si arrivava a `migrate` con codice nuovo e dipendenze
# vecchie. Nel salto 1.9.x → 1.10 questo significa Livewire 3 sotto codice che
# chiede la 4, e ogni comando artisan muore su «Attribute [livewire] does not
# exist» — migrate compreso. Quando non c'è niente da fare composer costa pochi
# secondi; saltarlo costa un database a metà.
composer install --no-interaction --optimize-autoloader
if ! grep -q "APP_KEY=base64" .env || [ -z "$(grep APP_KEY .env | cut -d '=' -f2)" ]; then
    php artisan key:generate --force
fi

# Attesa DB
until nc -z -v -w30 db 3306; do echo "⏳ Attesa DB..."; sleep 2; done

# Stessa ragione di composer: npm install e' idempotente e legge package-lock.json,
# quindi si lancia sempre invece di fidarsi dell'esistenza della cartella.
npm install

# La cache di configurazione va svuotata PRIMA di migrare, non dopo.
# Il bind mount porta l'albero dell'host dentro il contenitore, quindi un
# bootstrap/cache/config.php scritto da una versione precedente sopravvive alla
# ricreazione del contenitore. Migrare con quella cache significa far girare le
# migrazioni contro la configurazione vecchia: e' esattamente lo scenario in cui la
# migrazione delle righe per pagina moriva e lasciava il database a meta'. La cura
# deve arrivare prima del guasto, non dopo: costa qualche decimo di secondo.
php artisan optimize:clear

php artisan migrate --force

# Seed solo se il DB è vuoto (evita duplicati ad ogni riavvio)
USER_COUNT=$(php artisan tinker --execute="echo \App\Models\User::count();" 2>/dev/null | tail -1 || echo "0")
if [ "$USER_COUNT" = "0" ]; then
    echo "🌱 Esecuzione seeder iniziale..."
    php artisan db:seed --force
fi

# Gli asset si ricostruiscono quando il manifest manca oppure quando un ingresso del
# bundle e' piu' recente del manifest. Il controllo «la cartella public/build esiste»
# serviva a non ricompilare a ogni riavvio, ma serviva anche asset della versione
# precedente, in silenzio e senza che nessuno potesse accorgersene.
#
# Sta in fondo di proposito, dopo migrate E dopo il seed: la compilazione puo' fallire per memoria
# — misurato 1,13 GB di picco — e con `set -e` un build ucciso dall'OOM su un NAS
# fermerebbe l'avvio prima delle migrazioni e prima del seed, lasciando il database
# indietro o un'installazione nuova senza il primo utente. Cosi' invece il database
# resta allineato e si perde soltanto l'aggiornamento degli asset.
#
# Gli ingressi non sono solo `resources`: il plugin i18n compila `lang/*.php` dentro il
# bundle, e `vite.config.ts` e `package-lock.json` cambiano cosa viene prodotto. Una
# beta che corregge una sola traduzione cambia il bundle e non toccherebbe `resources`.
#
# Il `command -v find` non e' pignoleria: senza, un `find` assente farebbe fallire la
# sostituzione, il risultato sarebbe vuoto, la condizione falsa e la ricostruzione
# verrebbe saltata **in silenzio** — cioe' esattamente il difetto che questa correzione
# sta togliendo, riscritto in un altro punto. Quando il controllo non si puo' fare, si
# ricostruisce: sbagliare costa qualche secondo, l'altro verso costa asset vecchi.
if [ ! -f "public/build/manifest.json" ] \
   || ! command -v find >/dev/null 2>&1 \
   || [ -n "$(find resources lang vite.config.ts package-lock.json -newer public/build/manifest.json -print -quit 2>/dev/null)" ]; then
    echo "🔨 Build asset frontend..."
    npm run build
fi

echo "✅ KondoManager Standard Pronto!"
exec "$@"