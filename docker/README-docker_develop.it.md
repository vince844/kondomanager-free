# Kondomanager – Guida Completa all’Ambiente Docker (Dev & Deploy)
 
Questo documento descrive **passo dopo passo**, in modo **semplice e riproducibile**, come qualsiasi utente (anche con conoscenze di base) può installare, configurare e lavorare con **Kondomanager** in un ambiente Docker, oltre a preparare l’integrazione continua e il deploy.

----------

## 1. Prerequisiti

Prima di iniziare, assicurati di avere installato:

- **Git** (>= 2.30)
- **Docker Desktop** (include Docker + Docker Compose)
  - Windows / macOS: https://www.docker.com/products/docker-desktop
  - Linux: docker + docker-compose-plugin

Verifica rapida:
```bash
git --version
docker --version
docker compose version
```

----------

## 2. Fork del repository

1. Accedi a GitHub  
2. Vai a:  
   https://github.com/vince844/kondomanager-free  
3. Clicca su **Fork** (in alto a destra)  
4. Seleziona il tuo account personale  
5. (Opzionale) Rinomina il repository  

Risultato: il progetto sarà sotto il tuo controllo.

----------

## 3. Clonare il fork localmente

```bash
git clone https://github.com/TUO_USERNAME/kondomanager-free.git
cd kondomanager-free
```

----------

## 4. Struttura Docker

```
kondomanager/
└── docker/
    └── Dockerfile
    └── nginx/
        └── default.conf
```

### Servizi inclusi

| Servizio | Funzione |
|--------|---------|
| app | PHP 8.2 + Composer + Node.js |
| nginx | Web server |
| db | MySQL 8.0 |
| phpmyadmin | Gestione database |

----------

## 5. Variabili di ambiente (.env)

1. Copia il file di esempio (se non esiste):

```bash
cp .env.example .env
```

2. Verifica i valori principali:

```env
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8889

DB_HOST=db
DB_DATABASE=kondomanager_dev
DB_USERNAME=kondomanager
DB_PASSWORD=kondomanager
```

⚠️ **Non versionare mai il file `.env` con credenziali reali di produzione.**

----------

## 6. Build e avvio dell’ambiente Docker

Dalla root del progetto:

```bash
docker compose build
docker compose up -d
```

Verifica dei container:

```bash
docker compose ps
```

----------

## 7. Setup dell’applicazione

### Accesso al container dell’applicazione

```bash
docker compose exec app bash
```

### Installazione dipendenze PHP

```bash
composer install
```

### Installazione dipendenze frontend

```bash
npm install
npm run dev
```

### Generazione chiave applicazione

```bash
php artisan key:generate
```

### Migrazione database

```bash
php artisan migrate --seed
```

----------

## 8. Accessi

**Applicazione**  
http://localhost:8889

**phpMyAdmin**  
http://localhost:8990

**MySQL**  
Host: localhost  
Porta: 3307  

Credenziali MySQL:
- Utente: `kondomanager`  
- Password: `kondomanager`

----------

## 9. Comandi Docker utili

```bash
# Arrestare i container
docker compose down

# Rebuild completo
docker compose down -v
docker compose build --no-cache
docker compose up -d

# Log
docker compose logs -f app
```

----------

## 10. Flusso Git consigliato (sviluppo)

### Branch

```text
main        → produzione
staging     → pre-produzione
develop     → sviluppo
feature/*   → nuove funzionalità
fix/*       → correzioni
```

### Creare una nuova funzionalità

```bash
git checkout develop
git pull
git checkout -b feature/mia-feature
```

Commit:

```bash
git add .
git commit -m "feat: descrizione chiara"
git push origin feature/mia-feature
```

Apri una Pull Request verso `develop`.

----------

## 11. Best practice

- ❌ Non usare mai `root` in produzione  
- ❌ Non versionare il file `.env`  
- ✅ Backup automatici del database  
- ✅ Log centralizzati  
- ✅ Aggiornamenti di sicurezza regolari  

----------

## 12. Supporto

Questa guida consente a **qualsiasi utente** di avviare un ambiente funzionante in pochi minuti.  
Per ambienti di produzione, valida sempre con un **amministratore di sistema**.
