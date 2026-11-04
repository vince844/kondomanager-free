# 🐳 Sviluppo Locale con Docker

<!-- verifica-documentazione -->
> **Stato:** Descrive il codice — riletta il 04/11/2026 su 1.12.0-beta.1 contro `docker-compose.yml`, `docker-compose-franken.yml` e gli script di `docker/standard/` e `docker/frankenphp/`. **Riscritta il 04/11/2026:** il clone non punta più al ramo di una versione vecchia ma al ramo predefinito (`main`); le porte della stack sono aperte solo su `127.0.0.1`; in testa c'è l'avviso che questa stack serve a sviluppare KondoManager, non a usarlo; sono tolti i riferimenti a Synology NAS (per un NAS c'è la [guida Synology](synology_nas_install.it.md)) e corrette le frasi che gli script di avvio non confermavano più (che cosa si installa all'avvio e quando si ricompila il frontend).
> Non provata in questa riscrittura su un computer pulito: le righe delle porte sono validate con `docker compose config`, non avviate.
<!-- /verifica-documentazione -->

> **Piattaforme supportate:** Windows (WSL2), macOS, Linux

> ⚠️ **Questa guida serve a sviluppare KondoManager sul tuo computer. Non è il modo di installarlo per usarlo.** In questa stack l'ambiente è `local`, il debug è acceso e le password sono quelle scritte qui sotto: le conosce chiunque legga la guida. Per questo le porte (applicazione, MySQL, Supervisor) sono aperte **solo su `127.0.0.1`**, cioè solo dal computer su cui gira. Per usare KondoManager in uno studio, su un server o su un NAS c'è l'immagine di produzione: la [guida Docker (produzione)](https://kondomanager.com/docs/installation-docker-produzione.html), scritta in italiano, e per Synology la [guida per Synology NAS](synology_nas_install.it.md).

---

## Quale stack usare?

| Stack | File Compose | Porta | Consigliato per |
|-------|-------------|------|-----------------|
| **Standard** — PHP-FPM + Nginx | `docker-compose.yml` | `8889` | ✅ Windows / macOS / Linux |
| **FrankenPHP** — Laravel Octane | `docker-compose-franken.yml` | `8889` | 🧪 Sperimentale |

> ℹ️ Il `Dockerfile` nella root del repository costruisce l'**immagine di produzione** (`ghcr.io/vince844/kondomanager-core`, pubblicata a ogni versione): per sviluppare non serve. Le due stack di sviluppo usano `docker/standard/Dockerfile` e `docker/frankenphp/Dockerfile`.

**Perché lo Standard?**
La stack PHP-FPM + Nginx è solida, facile da debuggare e ampiamente documentata. FrankenPHP serve le richieste web con un solo processo, ma la stack è sperimentale: se non hai un motivo per provarla, usa la Standard.

---

## Prerequisiti

- **Docker Desktop** ≥ 4.x installato e in esecuzione
  - Su Windows: abilita il backend WSL2 → *Settings → General → "Use the WSL 2 based engine"*
- Git

### ⚠️ Utenti Windows / WSL2 — importante

Clona sempre il repository **dentro WSL** (il filesystem Linux), non nell'unità Windows. Lavorare da `/mnt/c/Users/...` causa errori di permessi ed è estremamente lento.

```bash
# ✅ Corretto — filesystem Linux (prestazioni ottimali)
cd ~/projects
git clone ...

# ❌ Evitare — filesystem Windows montato
# /mnt/c/Users/tuonome/...
```

---

## Passo 1 — Clonare il repository

Apri il terminale (su macOS/Linux) o il terminale WSL (su Windows) ed esegui:

```bash
git clone https://github.com/vince844/kondomanager-free.git
cd kondomanager-free
```

Senza altre opzioni git scarica il ramo predefinito, `main`: nella guida non c'è un numero di versione da tenere aggiornato. Per partire da una versione precisa (per esempio per riprodurre un problema) usa il tag, scritto per esteso perché `v1.11.0` è anche il nome di un ramo:

```bash
git checkout refs/tags/v1.11.0
```

---

## Passo 2 — Controllare i permessi degli script di avvio

Gli script di avvio arrivano dal repository con il permesso di esecuzione già impostato: git lo conserva. Può andare perso se hai scaricato lo ZIP o se hai clonato dal filesystem di Windows, e allora l'avvio si ferma con l'errore `permission denied`. Rendere eseguibili gli script è innocuo anche quando lo sono già, quindi puoi farlo comunque prima del build.

**Se usi la stack Standard (Nginx):**
```bash
chmod +x docker/standard/entrypoint.sh
chmod +x docker/standard/worker-entrypoint.sh
```

**Se usi la stack FrankenPHP:**
```bash
chmod +x docker/frankenphp/entrypoint.sh
chmod +x docker/frankenphp/worker-entrypoint.sh
```

---

## Passo 3 — Build e avvio

### Stack Standard (consigliata)

```bash
docker compose up --build -d
```

### Stack FrankenPHP

```bash
docker compose -f docker-compose-franken.yml up --build -d
```

> Il primo avvio richiede alcuni minuti: Docker costruisce l'immagine (estensioni PHP e Node.js) e poi il container dell'app, al primo avvio, installa le dipendenze Composer e npm e compila il frontend. La compilazione del frontend arriva a circa **1,1 GB di memoria**: se la memoria concessa a Docker è inferiore, il passo viene interrotto. In quel caso il database è già allineato, ma lo script di avvio si ferma con un errore e il container dell'app non parte: l'applicazione non risponde finché non dai a Docker più memoria e non riavvii la stack (`docker compose up -d`; per FrankenPHP `docker compose -f docker-compose-franken.yml up -d`).

---

## Passo 4 — Controllare i log

Attendi il messaggio di inizializzazione nel log del container dell'app:

**Stack Standard:**
```bash
docker logs kondo_app
```
Cerca: `✅ KondoManager Standard Pronto!`

**Stack FrankenPHP:**
```bash
docker logs kondo_app_franken
```
Cerca: `✅ KondoManager FrankenPHP Pronto!`

---

## Passo 5 — Aprire l'applicazione

Quando compare il messaggio di successo:

| Servizio | URL | Credenziali di sviluppo |
|----------|-----|-------------|
| **Applicazione Web** | http://localhost:8889 | Email: `admin@km.com` / Password: `password` |
| **Database MySQL** | `127.0.0.1:3307` | Utente: `root` / Password: `root` / DB: `kondomanager_dev` |

Puoi connetterti al database con qualsiasi client MySQL (TablePlus, DBeaver, MySQL Workbench, ecc.) usando le credenziali sopra.

> ⚠️ **Sono credenziali di sviluppo, e funzionano solo perché la stack è in ambiente `local`.** L'amministratore `admin@km.com` lo crea il seeder soltanto con `APP_ENV=local`; con un altro ambiente il seeder genera una password casuale. Non usare queste password, né questa stack, per i dati di uno studio.
>
> Le porte rispondono **solo da questo computer** (`127.0.0.1`): dal telefono o da un altro computer della rete non si aprono. Se ti serve usarle da un altro dispositivo, togli `127.0.0.1:` davanti alla porta in `docker-compose.yml` (o in `docker-compose-franken.yml`) e ricrea i container: da quel momento chiunque sia sulla tua rete può raggiungerla, con quelle password.

---

## Cosa succede a ogni avvio

Lo script entrypoint esegue i seguenti passaggi senza alcun input manuale. Alcuni sono solo del primo avvio, altri si ripetono a ogni avvio del container:

1. Copia `.env.example` → `.env` se non esiste già, e lo mette in ambiente di sviluppo: `APP_ENV=local` e `APP_DEBUG=true` *(solo quando crea il file: un `.env` che c'è già non si tocca, salvo le righe del database e `APP_URL`, qui sotto)*
2. Configura la connessione al DB per puntare al container `db` *(a ogni avvio)*
3. Imposta `APP_URL=http://localhost:8889` se è vuoto, è esattamente `http://localhost` o contiene `kondomanager-free.test`; ogni altro valore resta com'è
4. Installa le dipendenze PHP tramite Composer *(a ogni avvio: costa pochi secondi quando non c'è niente da fare)*
5. Genera la `APP_KEY` se manca
6. Attende che MySQL sia pronto
7. Installa le dipendenze Node.js con `npm install` *(a ogni avvio)*
8. Svuota le cache (`php artisan optimize:clear`) ed esegue le migration del database
9. Esegue i seeder *(solo se non c'è nessun utente: sicuro riavviare)*
10. Allinea permessi e ruoli, l'elenco dei Comuni, la classificazione ATECO e la versione registrata (`php artisan kondomanager:aggiorna`)
11. Compila gli asset del frontend *(solo se `public/build/manifest.json` manca, o se un file di `resources`, `lang`, `vite.config.ts` o `package-lock.json` è più recente del manifest)*

---

## Processi in background — Supervisor

Nello stack Standard, i processi in background (queue worker, scheduler) sono gestiti da **Supervisor**, che li mantiene attivi e li riavvia automaticamente in caso di crash.

### Architettura

| Container | Processo | Gestito da |
|-----------|---------|-----------|
| `kondo_app` | PHP-FPM (richieste web) | php-fpm diretto |
| `kondo_worker` | Queue worker Laravel | **Supervisor** |
| `kondo_nginx` | Web server | Nginx |
| `kondo_db` | Database | MySQL |

Il container `kondo_worker` avvia Supervisor all'avvio, che a sua volta avvia e monitora `php artisan queue:work`.

### Configurazione Supervisor

Il file di configurazione si trova in [`docker/supervisord.conf`](../docker/supervisord.conf). Qui sotto un estratto (mancano le sezioni `[rpcinterface:supervisor]` e `[supervisorctl]`, che servono al comando `supervisorctl`). **Utente e password dell'interfaccia web sono quelli di sviluppo, scritti nel file:**

```ini
[supervisord]
nodaemon=true
logfile=/var/www/storage/logs/supervisord.log
pidfile=/var/run/supervisord.pid

[inet_http_server]
port = *:9001
username = admin
password = password

[program:laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/artisan queue:work --sleep=3 --tries=3 --timeout=90
autostart=true
autorestart=true
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/storage/logs/worker.log

[program:laravel-scheduler]
command=php /var/www/artisan schedule:work
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/www/storage/logs/scheduler.log
```

> ℹ️ L'interfaccia web di Supervisor (porta 9001) esiste solo in questa stack di sviluppo, e risponde solo da questo computer perché la porta è pubblicata su `127.0.0.1`. L'immagine di produzione non la apre: il suo Supervisor ha solo un socket locale e nessuna porta HTTP.

**Parametri principali:**
- `--sleep=3` — attende 3 secondi tra un job e l'altro quando la coda è vuota
- `--tries=3` — un job fallito viene ritentato al massimo 3 volte
- `--timeout=90` — un job che dura più di 90 secondi viene interrotto
- `numprocs=1` — un solo processo worker attivo (aumentabile per più parallelismo)

### Monitorare il worker

**1. Interfaccia Web (Consigliata)**
Puoi controllare lo stato dei processi e leggere i log comodamente dal browser:
- Vai su: `http://localhost:9001`
- Utente: `admin` / Password: `password` *(credenziali di sviluppo)*

**2. Da riga di comando**
```bash
# Vedere i log del worker in tempo reale
docker compose logs -f worker

# Vedere i log scritti da Supervisor nel file
docker compose exec worker cat /var/www/storage/logs/worker.log

# Stato di Supervisor dentro il container
docker compose exec worker supervisorctl status

# Riavviare manualmente il worker
docker compose exec worker supervisorctl restart laravel-worker:*
```

### Aumentare i processi worker (per carichi elevati)

Modifica `docker/supervisord.conf`:
```ini
numprocs=3   # avvia 3 worker in parallelo
```

Poi ricostruisci il container:
```bash
docker compose up --build -d worker
```

---

## Cambiare stack

> ⚠️ **Entrambe le stack usano le stesse porte (8889 e 3307).** Per passare da una all'altra, ferma prima la stack attiva per evitare conflitti di porta.

```bash
# Fermare la stack Standard prima di passare a FrankenPHP
docker compose down

# — oppure —

# Fermare la stack FrankenPHP prima di passare alla Standard
docker compose -f docker-compose-franken.yml down
```

---

## Comandi utili

```bash
# Eseguire un comando Artisan dentro il container dell'app
docker compose exec app php artisan <comando>

# Aprire una shell dentro il container dell'app
docker compose exec app bash

# Vedere i log del worker (stack Standard)
docker compose logs -f worker

# Vedere lo stato di tutti i container
docker compose ps

# Riavviare il container dell'app (es.: dopo aver modificato il .env)
docker compose restart app

# Reset completo — distrugge tutti i container E il volume del DB
docker compose down -v
docker compose up --build -d

# Forzare la ricompilazione degli asset del frontend
docker compose exec app rm -rf public/build
docker compose exec app npm run build

# Forzare la ri-esecuzione dei seeder (utile durante lo sviluppo)
docker compose exec app php artisan db:seed --force
```

---

## Caricamento file e persistenza dei documenti

*Sezione aggiunta il 18/08/2026 con la 1.10.0-beta.58.*

### I limiti di caricamento sono dichiarati nelle immagini

Fino alla beta.57 nessuna delle tre immagini dichiarava un limite, quindi valeva il default di
nginx — **1 MB** — e un PDF da 1,5 MB veniva rifiutato mentre l'applicazione ne prometteva 20.
Adesso i valori sono dichiarati nella configurazione di ogni immagine — `docker/standard/Dockerfile` e `docker/standard/nginx/default.conf`, `docker/frankenphp/Dockerfile`, `docker/production/php.ini` e `docker/production/nginx.conf` — e sono coerenti fra loro:

| | Valore |
| :--- | :--- |
| `upload_max_filesize` (PHP) | 20M |
| `post_max_size` (PHP) | 25M |
| `client_max_body_size` (nginx) | 30M |

Nella stack FrankenPHP non c'è nginx: valgono i due valori di PHP.

L'ordine non è casuale: nginx è il più alto, così a rifiutare è **PHP**, che sa dirlo con un
messaggio comprensibile invece che con un errore del server web. L'applicazione non ha un limite
proprio: legge quello di PHP e scrive quello sulla schermata.

### Cosa non sopravvive alla ricreazione di un contenitore

`storage/app` tiene i documenti caricati, i backup e gli allegati. In questo compose la cartella
arriva dal bind mount `./:/var/www`, quindi vive sull'host ed è al sicuro. **In un deploy vero
non è detto**: senza un volume dichiarato quella cartella sta nel livello scrivibile del
contenitore e sparisce alla prima ricreazione, senza che niente lo dica prima.

Per saperlo prima invece che dopo:

```bash
docker compose exec app php artisan kondomanager:verifica-persistenza
```

Risponde con quanti file e quanti megabyte sono in gioco, e se la cartella è dentro il contenitore
spiega dove dichiarare il volume. Fuori dall'ambiente di sviluppo, con `--rigoroso` esce con codice di errore quando manca il volume, così si può mettere
in una pipeline di deploy; nella stack di sviluppo (ambiente `local`) risponde che il volume non serve e non fallisce.

---

## Risoluzione dei problemi

### `permission denied` all'avvio
Lo script entrypoint non ha i permessi di esecuzione. Esegui:
```bash
chmod +x docker/standard/entrypoint.sh
chmod +x docker/standard/worker-entrypoint.sh
# o per FrankenPHP:
chmod +x docker/frankenphp/entrypoint.sh
chmod +x docker/frankenphp/worker-entrypoint.sh
```

### Il container `app` continua a riavviarsi
Controlla i log per il messaggio di errore specifico:
```bash
docker compose logs app
```

### MySQL non risponde / l'app non riesce a connettersi al DB
MySQL impiega ~10–15 secondi per inizializzarsi al primo avvio. Lo script entrypoint attende automaticamente, ma se è stato interrotto, prova:
```bash
docker compose restart app
docker compose logs db
```

### Gli asset del frontend non si aggiornano dopo modifiche al codice
Il frontend si ricompila a ogni avvio del container, ma solo se `public/build/manifest.json` manca o se un file di `resources`, `lang`, `vite.config.ts` o `package-lock.json` è più recente del manifest: riavvia il container (`docker compose restart app`). Per forzare la ricompilazione:
```bash
docker compose exec app rm -rf public/build
docker compose exec app npm run build
```

### La porta 8889 o 3307 è già in uso
Un altro processo o stack Docker sta usando quella porta. Esegui `docker compose down` su qualsiasi altra stack attiva, oppure controlla con:
```bash
# macOS / Linux / WSL
lsof -i :8889
lsof -i :3307
```

### L'applicazione non si apre dal telefono o da un altro computer
Le porte della stack sono pubblicate solo su `127.0.0.1`, quindi rispondono soltanto dal computer su cui gira Docker. È voluto: vedi il Passo 5 per sapere che cosa succede se togli `127.0.0.1:` davanti alla porta.

### Errore CORS / redirect a `https://` invece di `http://`
Se nel browser compare un errore come `Cross-Origin Request Blocked` o la pagina tenta di aprire `https://localhost:8889`, il problema è `APP_URL` nel file `.env`.

**Causa:** il `.env` nella cartella del progetto è stato creato in precedenza da Herd, Coolify o un altro ambiente, e contiene `APP_URL=https://...`. Docker monta i file dell'host direttamente nel container (volume mount), quindi usa quel `.env` così com'è.

**Correzione automatica, ma condizionata:** l'`entrypoint.sh` imposta `APP_URL=http://localhost:8889` **solo se** il valore attuale è vuoto, è esattamente `http://localhost` oppure contiene `kondomanager-free.test`. Qualsiasi altro valore (per esempio un `https://...` lasciato da Herd o da Coolify) viene **preservato**, per non rompere le installazioni dietro reverse proxy: in quel caso serve la correzione manuale qui sotto.

**Fix manuale (se necessario):**
```bash
docker compose exec app sed -i 's|^APP_URL=.*|APP_URL=http://localhost:8889|' /var/www/.env
docker compose exec app php artisan config:clear
docker compose restart app
```
