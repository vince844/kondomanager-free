# 🐳 Local Development with Docker

> **Platforms supported:** Windows (WSL2), macOS, Linux, Synology NAS

---

## Which stack should I use?

| Stack | Compose file | Port | Recommended for |
|-------|-------------|------|-----------------|
| **Standard** — PHP-FPM + Nginx | `docker-compose.yml` | `8889` | ✅ Windows / macOS / Linux |
| **FrankenPHP** — Laravel Octane | `docker-compose-franken.yml` | `8889` | 🧪 Synology NAS *(to be tested)* |

> ℹ️ The `Dockerfile` in the repository root is used **only for Coolify (production)** — you don't need it for local development.

**Why Standard for Windows/macOS/Linux?**  
The PHP-FPM + Nginx stack is battle-tested, easy to debug, and widely documented. FrankenPHP runs on a single process (lower memory footprint), which makes it potentially interesting on a Synology NAS, but it hasn't been fully validated yet in that environment.

---

## Prerequisites

- **Docker Desktop** ≥ 4.x installed and running
  - On Windows: enable WSL2 backend → *Settings → General → "Use the WSL 2 based engine"*
- Git

### ⚠️ Windows / WSL2 users — important

Always clone the repository **inside WSL** (the Linux filesystem), not in your Windows drive. Working from `/mnt/c/Users/...` causes permission errors and is extremely slow.

```bash
# ✅ Correct — Linux filesystem (best performance)
cd ~/projects
git clone ...

# ❌ Avoid — Windows mounted filesystem
# /mnt/c/Users/yourname/...
```

---

## Step 1 — Clone the repository

Open a terminal (or WSL terminal on Windows) and run:

```bash
git clone -b v1.9.1-beta https://github.com/vince844/kondomanager-free.git
cd kondomanager-free
```

---

## Step 2 — Set permissions on the startup scripts

Before building, make the startup scripts executable. This is required on Linux/WSL — without this step you'll get a `permission denied` error.

**If using the Standard stack (Nginx):**
```bash
chmod +x docker/standard/entrypoint.sh
chmod +x docker/standard/worker-entrypoint.sh
```

**If using the FrankenPHP stack:**
```bash
chmod +x docker/frankenphp/entrypoint.sh
```

---

## Step 3 — Build and start

### Standard stack (recommended)

```bash
docker-compose up --build -d
```

### FrankenPHP stack

```bash
docker-compose -f docker-compose-franken.yml up --build -d
```

> The first build takes approximately **3–5 minutes** — Docker installs PHP extensions, Node.js, Composer dependencies, and compiles frontend assets.

---

## Step 4 — Check the logs

Wait for the initialization message in the app container log:

**Standard stack:**
```bash
docker logs kondo_app
```
Look for: `✅ KondoManager Standard Pronto!`

**FrankenPHP stack:**
```bash
docker logs kondo_app_franken
```
Look for: `✅ KondoManager FrankenPHP Pronto!`

---

## Step 5 — Open the application

Once the success message appears:

| Service | URL | Credentials |
|---------|-----|-------------|
| **Web App** | http://localhost:8889 | Email: `admin@km.com` / Password: `password` |
| **MySQL Database** | `127.0.0.1:3307` | User: `root` / Password: `root` / DB: `kondomanager_dev` |

You can connect to the database with any MySQL client (TablePlus, DBeaver, MySQL Workbench, etc.) using the credentials above.

---

## What happens automatically on first startup

The entrypoint script runs the following steps without any manual input:

1. Copies `.env.example` → `.env` (if not already present)
2. Configures DB connection to point to the `db` container
3. Installs PHP dependencies via Composer
4. Generates the `APP_KEY`
5. Waits for MySQL to be ready
6. Installs Node.js dependencies and compiles frontend assets *(only on first run)*
7. Runs database migrations
8. Runs seeders *(only if the database is empty — safe to restart)*

---

## Background processes — Supervisor

In the Standard stack, background processes (queue worker, scheduler) are managed by **Supervisor**, which keeps them alive and automatically restarts them on crash.

### Architecture

| Container | Process | Managed by |
|-----------|---------|-----------|
| `kondo_app` | PHP-FPM (web requests) | php-fpm directly |
| `kondo_worker` | Laravel queue worker | **Supervisor** |
| `kondo_nginx` | Web server | Nginx |
| `kondo_db` | Database | MySQL |

The `kondo_worker` container starts Supervisor on boot, which in turn starts and monitors `php artisan queue:work`.

### Supervisor configuration

The configuration file is located at [`docker/supervisord.conf`](../docker/supervisord.conf):

```ini
[supervisord]
nodaemon=true

[program:laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/artisan queue:work --sleep=3 --tries=3 --timeout=90
autostart=true
autorestart=true
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/storage/logs/worker.log
```

**Key parameters:**
- `--sleep=3` — waits 3 seconds between jobs when the queue is empty
- `--tries=3` — a failed job is retried up to 3 times
- `--timeout=90` — a job running longer than 90 seconds is terminated
- `numprocs=1` — one active worker process (increase for more parallelism)

### Monitoring the worker

```bash
# View worker logs in real time
docker compose logs -f worker

# View logs written by Supervisor to file
docker compose exec worker cat /var/www/storage/logs/worker.log

# Supervisor status inside the container
docker compose exec worker supervisorctl status

# Manually restart the worker
docker compose exec worker supervisorctl restart laravel-worker:*
```

### Increasing worker processes (for high load)

Edit `docker/supervisord.conf`:
```ini
numprocs=3   # starts 3 workers in parallel
```

Then rebuild the container:
```bash
docker compose up --build -d worker
```

---

## Switching between stacks

> ⚠️ **Both stacks use the same ports (8889 and 3307).** If you want to switch from one to the other, stop the currently active stack first to avoid port conflicts.

```bash
# Stop the Standard stack before switching to FrankenPHP
docker-compose down

# — or —

# Stop the FrankenPHP stack before switching to Standard
docker-compose -f docker-compose-franken.yml down
```

---

## Useful commands

```bash
# Run an Artisan command inside the app container
docker compose exec app php artisan <command>

# Open a shell inside the app container
docker compose exec app bash

# View worker logs (Standard stack)
docker compose logs -f worker

# View all container statuses
docker compose ps

# Restart the app container (e.g. after editing .env)
docker compose restart app

# Full reset — destroys all containers AND the database volume
docker compose down -v
docker compose up --build -d

# Force recompile frontend assets
docker compose exec app rm -rf public/build
docker compose exec app npm run build

# Force re-run seeders (useful during development)
docker compose exec app php artisan db:seed --force
```

---

## Troubleshooting

### `permission denied` when starting
The entrypoint script is not executable. Run:
```bash
chmod +x docker/standard/entrypoint.sh
chmod +x docker/standard/worker-entrypoint.sh
# or for FrankenPHP:
chmod +x docker/frankenphp/entrypoint.sh
```

### The `app` container keeps restarting
Check the logs for the specific error:
```bash
docker compose logs app
```

### MySQL is not responding / app can't connect to DB
MySQL takes ~10–15 seconds to initialize on first start. The entrypoint script waits automatically, but if you interrupted it, try:
```bash
docker compose restart app
docker compose logs db
```

### Frontend assets are not updating after code changes
The build is skipped if `public/build/` already exists. Force a rebuild:
```bash
docker compose exec app rm -rf public/build
docker compose exec app npm run build
```

### Port 8889 or 3307 already in use
Another process or Docker stack is using that port. Run `docker compose down` on any other active stack, or check with:
```bash
# macOS / Linux / WSL
lsof -i :8889
lsof -i :3307
```

### CORS error / redirect to `https://` instead of `http://`
If the browser shows a `Cross-Origin Request Blocked` error or the page tries to open `https://localhost:8889`, the problem is `APP_URL` in the `.env` file.

**Cause:** the `.env` in your project folder was previously created by Herd, Coolify, or another environment, and contains `APP_URL=https://...`. Docker mounts host files directly into the container (volume mount), so it uses that `.env` as-is.

**Automatic fix (recent versions):** the `entrypoint.sh` automatically sets `APP_URL=http://localhost:8889` on every startup — no manual intervention needed.

**Manual fix (if needed):**
```bash
docker compose exec app sed -i 's|^APP_URL=.*|APP_URL=http://localhost:8889|' /var/www/.env
docker compose exec app php artisan config:clear
docker compose restart app
```
