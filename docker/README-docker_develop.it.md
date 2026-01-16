# Kondomanager – Guida Completa all’Ambiente Docker (Dev & Deploy)

Questo documento descrive **passo dopo passo**, in modo **semplice e riproducibile**, come qualsiasi utente (anche con conoscenze di base) possa installare, configurare e lavorare con **Kondomanager** in un ambiente Docker, oltre a preparare integrazione continua e deployment.

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
2. Vai su:
   https://github.com/vince844/kondomanager-free
3. Clicca su **Fork** (in alto a destra)
4. Scegli il tuo account personale
5. (Opzionale) Rinomina il repository

Risultato: il progetto sarà sotto il tuo controllo.

----------

## 2.1 Clonare il fork localmente

```bash
git clone https://github.com/TUO_UTENTE/kondomanager-free.git
cd kondomanager-free
```

----------

## 2.2 Struttura Docker

```
kondomanager/
├── docker/
│   ├── Dockerfile
│   ├── docker-compose_develop.yml
│   ├── entrypoint.sh
│   └── nginx/
│       └── default.conf
```

----------

## 2.3 Servizi inclusi

| Servizio | Funzione |
|-------|---------|
| app | PHP 8.2 (runtime) + Composer (build-time) |
| nginx | Web server |
| db | MySQL 8.0 |
| phpmyadmin | Gestione database |

----------

## 3. Variabili d’ambiente (.env)

1. Copia il file di esempio (se non esiste):

```bash
cp .env.example .env
```

2. Verifica le variabili principali per lo sviluppo:

```env
APP_ENV=local
APP_DEBUG=true

AUTO_KEYGEN=true
AUTO_MIGRATE=true

DB_HOST=db
DB_DATABASE=kondomanager_dev
DB_USERNAME=kondomanager
DB_PASSWORD=kondomanager
```

⚠️ **Non versionare mai file `.env` con credenziali reali.**

----------

## 4. Avvio dell’ambiente di sviluppo

Dalla root del progetto, esegui:

```bash
docker compose -f docker/docker-compose_develop.yml up -d --build
```

Fatto.  
Non è necessario eseguire altri comandi.

Verifica container:

```bash
docker compose ps
```

----------

## 5. Punti di accesso

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

### Comandi Docker utili

Arrestare l’ambiente:

```bash
docker compose -f docker/docker-compose_develop.yml down
```

Reset completo (inclusi i volumi):

```bash
docker compose -f docker/docker-compose_develop.yml down -v
docker compose -f docker/docker-compose_develop.yml up -d --build
```

Visualizzare i log:

```bash
docker compose -f docker/docker-compose_develop.yml logs -f
```

----------

## 6. Cosa avviene automaticamente

All’avvio dei container:

- Le estensioni PHP vengono installate in build-time
- Le dipendenze Composer vengono installate in build-time
- Gli asset frontend vengono compilati in build-time
- La `APP_KEY` di Laravel viene generata automaticamente (se assente)
- Viene creato il collegamento simbolico a `storage`
- Le migration del database vengono eseguite automaticamente quando `AUTO_MIGRATE=true`

Questo garantisce un ambiente coerente per tutti gli sviluppatori e per le pipeline CI.

----------

## 7. Workflow di sviluppo

### Backend (Laravel)

- Controller, rotte, configurazioni e template Blade hanno hot reload
- Non è necessario riavviare i container per modifiche backend

### Frontend

- Gli asset frontend vengono compilati durante il build dell’immagine Docker
- Per applicare modifiche frontend è necessario ricostruire l’immagine:

```bash
docker compose -f docker/docker-compose_develop.yml up -d --build
```

Questo comportamento è intenzionale per mantenere l’ambiente prevedibile e riproducibile.

----------

## 8. Nota su CI/CD

Kondomanager è **Docker-first**.

Le pipeline CI devono solo eseguire:

```bash
docker build .
```

In ambienti CI simili allo sviluppo, anche `docker compose` può essere utilizzato.  
Non è richiesto alcun setup in runtime.

----------

## 9. Workflow Git consigliato (sviluppo)

### Branch

```text
main        → produzione
staging     → pre-produzione
develop     → sviluppo
feature/*   → nuove funzionalità
fix/*       → correzioni
```

----------

## 10. Note finali

- Non eseguire manualmente `composer install` o `npm install`
- Non montare l’intera directory del progetto come volume
- Tutti gli ambienti (dev, CI, produzione) devono utilizzare l’immagine Docker come fonte di verità
- Un file `.dockerignore` viene utilizzato per rendere i build rapidi e deterministici

Questo approccio garantisce:
- Onboarding rapido
- Riduzione degli errori umani
- Pipeline CI/CD semplici e manutenibili

----------

## 11. Best practice

- ❌ Non usare mai `root` in produzione
- ❌ Non versionare file `.env`
- ✅ Backup automatici del database
- ✅ Logging centralizzato
- ✅ Aggiornamenti di sicurezza regolari

----------

## 12. Supporto

Questa guida consente a **qualsiasi utente** di avviare un ambiente completamente funzionante in pochi minuti.
Per i deploy in produzione, verifica sempre con un **amministratore di sistema**.
