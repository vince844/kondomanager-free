# 🐳 Sviluppo Locale con Docker

<!-- verifica-documentazione -->
> **Stato:** Descrive il codice — verificato e corretto il 31/07/2026 su 1.10.0-beta.32, ampliato il **18/08/2026 su 1.10.0-beta.58** con la sezione «Caricamento file e persistenza dei documenti»
> Le quattro affermazioni sbagliate trovate nell'audit sono state corrette: branch di clone (era `v1.9.1-beta`, che non esiste), `chmod` mancante su `docker/frankenphp/worker-entrypoint.sh`, la riscrittura di APP_URL (è condizionata) e l'estratto di supervisord.conf, ora completo di `[inet_http_server]` e dello scheduler.
<!-- /verifica-documentazione -->

> **Piattaforme supportate:** Windows (WSL2), macOS, Linux, Synology NAS

---

## Quale stack usare?

| Stack | File Compose | Porta | Consigliato per |
|-------|-------------|-------|-----------------|
| **Standard** — PHP-FPM + Nginx | `docker-compose.yml` | `8889` | ✅ Windows / macOS / Linux |
| **FrankenPHP** — Laravel Octane | `docker-compose-franken.yml` | `8889` | 🧪 Synology NAS *(da testare)* |

> ℹ️ Il `Dockerfile` nella root del repository è usato **solo per Coolify (produzione)** — non serve per lo sviluppo locale.

**Perché lo Standard su Windows/macOS/Linux?**  
La stack PHP-FPM + Nginx è solida, facile da debuggare e ampiamente documentata. FrankenPHP gira su un singolo processo (footprint di memoria ridotto), il che lo rende potenzialmente interessante su un Synology NAS, ma non è ancora stato validato completamente in quell'ambiente.

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
git clone -b v1.9.1 https://github.com/vince844/kondomanager-free.git
cd kondomanager-free
```

---

## Passo 2 — Impostare i permessi sugli script di avvio

Prima di fare il build, rendi gli script di avvio eseguibili. È obbligatorio su Linux/WSL — senza questo passaggio otterrai un errore `permission denied`.

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
docker-compose up --build -d
```

### Stack FrankenPHP

```bash
docker-compose -f docker-compose-franken.yml up --build -d
```

> Il primo build richiede circa **3–5 minuti** — Docker installa le estensioni PHP, Node.js, le dipendenze Composer e compila gli asset del frontend.

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

| Servizio | URL | Credenziali |
|----------|-----|-------------|
| **Applicazione Web** | http://localhost:8889 | Email: `admin@km.com` / Password: `password` |
| **Database MySQL** | `127.0.0.1:3307` | Utente: `root` / Password: `root` / DB: `kondomanager_dev` |

Puoi connetterti al database con qualsiasi client MySQL (TablePlus, DBeaver, MySQL Workbench, ecc.) usando le credenziali sopra.

---

## Cosa succede automaticamente al primo avvio

Lo script entrypoint esegue i seguenti passaggi senza alcun input manuale:

1. Copia `.env.example` → `.env` (se non esiste già)
2. Configura la connessione al DB per puntare al container `db`
3. Installa le dipendenze PHP tramite Composer
4. Genera la `APP_KEY`
5. Attende che MySQL sia pronto
6. Installa le dipendenze Node.js e compila gli asset del frontend *(solo al primo avvio)*
7. Esegue le migration del database
8. Esegue i seeder *(solo se il DB è vuoto — sicuro riavviare)*

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

Il file di configurazione si trova in [`docker/supervisord.conf`](../docker/supervisord.conf):

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

**Parametri principali:**
- `--sleep=3` — attende 3 secondi tra un job e l'altro quando la coda è vuota
- `--tries=3` — un job fallito viene ritentato al massimo 3 volte
- `--timeout=90` — un job che dura più di 90 secondi viene interrotto
- `numprocs=1` — un solo processo worker attivo (aumentabile per più parallelismo)

### Monitorare il worker

**1. Interfaccia Web (Consigliata)**
Puoi controllare lo stato dei processi e leggere i log comodamente dal browser:
- Vai su: `http://localhost:9001`
- Utente: `admin` / Password: `password`

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
docker-compose down

# — oppure —

# Fermare la stack FrankenPHP prima di passare alla Standard
docker-compose -f docker-compose-franken.yml down
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
Adesso i valori sono scritti nei `Dockerfile` e sono coerenti fra loro:

| | Valore |
| :--- | :--- |
| `upload_max_filesize` (PHP) | 20M |
| `post_max_size` (PHP) | 25M |
| `client_max_body_size` (nginx) | 30M |

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
spiega dove dichiarare il volume. Con `--rigoroso` esce con codice di errore, così si può mettere
in una pipeline di deploy.

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
Il build viene saltato se la cartella `public/build/` esiste già. Forza un rebuild:
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
