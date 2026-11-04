# 🐳 Local Development with Docker

<!-- verifica-documentazione -->
> **Status:** Matches the code — re-read on 04/11/2026 against 1.12.0-beta.1, checked against `docker-compose.yml`, `docker-compose-franken.yml` and the scripts in `docker/standard/` and `docker/frankenphp/`. **Rewritten on 04/11/2026:** the clone no longer points to the branch of an old version but to the default branch (`main`); the stack ports are open on `127.0.0.1` only; at the top there is a notice that this stack is for developing KondoManager, not for using it; the references to Synology NAS are gone (for a NAS see the [Synology guide](synology_nas_install.en.md)); and the statements the startup scripts no longer supported are corrected (what is installed at startup and when the frontend is rebuilt).
> Not run on a clean computer in this rewrite: the port lines are validated with `docker compose config`, not started.
<!-- /verifica-documentazione -->

> **Platforms supported:** Windows (WSL2), macOS, Linux

> ⚠️ **This guide is for developing KondoManager on your own computer. It is not a way to install it for use.** In this stack the environment is `local`, debug is on and the passwords are the ones written below: anyone who reads the guide knows them. That is why the ports (application, MySQL, Supervisor) are open **on `127.0.0.1` only**, that is, only from the computer it runs on. To use KondoManager in an office, on a server or on a NAS there is the production image: the [Docker (production) guide](https://kondomanager.com/docs/installation-docker-produzione.html), written in Italian, and for Synology the [Synology NAS guide](synology_nas_install.en.md).

---

## Which stack should I use?

| Stack | Compose file | Port | Recommended for |
|-------|-------------|------|-----------------|
| **Standard** — PHP-FPM + Nginx | `docker-compose.yml` | `8889` | ✅ Windows / macOS / Linux |
| **FrankenPHP** — Laravel Octane | `docker-compose-franken.yml` | `8889` | 🧪 Experimental |

> ℹ️ The `Dockerfile` in the repository root builds the **production image** (`ghcr.io/vince844/kondomanager-core`, published with every version): you don't need it for development. The two development stacks use `docker/standard/Dockerfile` and `docker/frankenphp/Dockerfile`.

**Why Standard?**
The PHP-FPM + Nginx stack is battle-tested, easy to debug, and widely documented. FrankenPHP serves web requests with a single process, but the stack is experimental: if you have no reason to try it, use Standard.

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

Open your terminal (on macOS/Linux) or WSL terminal (on Windows) and run:

```bash
git clone https://github.com/vince844/kondomanager-free.git
cd kondomanager-free
```

With no other options git downloads the default branch, `main`: there is no version number in this guide to keep up to date. To start from a specific version (for example to reproduce a problem) use the tag, written in full because `v1.11.0` is also the name of a branch:

```bash
git checkout refs/tags/v1.11.0
```

---

## Step 2 — Check the permissions of the startup scripts

The startup scripts come from the repository with the execute permission already set: git keeps it. It can be lost if you downloaded the ZIP or cloned onto the Windows filesystem, and then startup stops with a `permission denied` error. Making the scripts executable is harmless even when they already are, so you can do it anyway before the build.

**If using the Standard stack (Nginx):**
```bash
chmod +x docker/standard/entrypoint.sh
chmod +x docker/standard/worker-entrypoint.sh
```

**If using the FrankenPHP stack:**
```bash
chmod +x docker/frankenphp/entrypoint.sh
chmod +x docker/frankenphp/worker-entrypoint.sh
```

---

## Step 3 — Build and start

### Standard stack (recommended)

```bash
docker compose up --build -d
```

### FrankenPHP stack

```bash
docker compose -f docker-compose-franken.yml up --build -d
```

> The first start takes a few minutes: Docker builds the image (PHP extensions and Node.js), and then, on its first start, the app container installs the Composer and npm dependencies and compiles the frontend. Compiling the frontend peaks at about **1.1 GB of memory**: if the memory given to Docker is lower, that step is interrupted. In that case the database is already up to date, but the startup script stops with an error and the app container does not start: the application does not answer until you give Docker more memory and start the stack again (`docker compose up -d`; for FrankenPHP `docker compose -f docker-compose-franken.yml up -d`).

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

| Service | URL | Development credentials |
|---------|-----|-------------|
| **Web App** | http://localhost:8889 | Email: `admin@km.com` / Password: `password` |
| **MySQL Database** | `127.0.0.1:3307` | User: `root` / Password: `root` / DB: `kondomanager_dev` |

You can connect to the database with any MySQL client (TablePlus, DBeaver, MySQL Workbench, etc.) using the credentials above.

> ⚠️ **These are development credentials, and they work only because the stack runs in the `local` environment.** The `admin@km.com` administrator is created by the seeder only with `APP_ENV=local`; in any other environment the seeder generates a random password. Do not use these passwords, or this stack, for an office's data.
>
> The ports answer **only from this computer** (`127.0.0.1`): from a phone or another computer on the network they do not open. If you need them from another device, remove `127.0.0.1:` in front of the port in `docker-compose.yml` (or in `docker-compose-franken.yml`) and recreate the containers: from then on anyone on your network can reach it, with those passwords.

---

## What happens at every startup

The entrypoint script runs the following steps without any manual input. Some happen only on the first start, others repeat at every start of the container:

1. Copies `.env.example` → `.env` if not already present, and puts it in the development environment: `APP_ENV=local` and `APP_DEBUG=true` *(only when it creates the file: an existing `.env` is left alone, except for the database lines and `APP_URL`, below)*
2. Configures DB connection to point to the `db` container *(at every start)*
3. Sets `APP_URL=http://localhost:8889` if it is empty, exactly `http://localhost`, or contains `kondomanager-free.test`; any other value stays as it is
4. Installs PHP dependencies via Composer *(at every start: it costs a few seconds when there is nothing to do)*
5. Generates the `APP_KEY` if it is missing
6. Waits for MySQL to be ready
7. Installs Node.js dependencies with `npm install` *(at every start)*
8. Clears the caches (`php artisan optimize:clear`) and runs database migrations
9. Runs seeders *(only if there is no user yet — safe to restart)*
10. Aligns roles and permissions, the list of municipalities, the ATECO classification and the recorded version (`php artisan kondomanager:aggiorna`)
11. Compiles the frontend assets *(only if `public/build/manifest.json` is missing, or a file in `resources`, `lang`, `vite.config.ts` or `package-lock.json` is newer than the manifest)*

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

The configuration file is located at [`docker/supervisord.conf`](../docker/supervisord.conf). Below is an excerpt (the `[rpcinterface:supervisor]` and `[supervisorctl]` sections are left out; they serve the `supervisorctl` command). **The user and password of the web interface are the development ones, written in the file:**

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

> ℹ️ The Supervisor web interface (port 9001) exists only in this development stack, and answers only from this computer because the port is published on `127.0.0.1`. The production image does not open it: its Supervisor has only a local socket and no HTTP port.

**Key parameters:**
- `--sleep=3` — waits 3 seconds between jobs when the queue is empty
- `--tries=3` — a failed job is retried up to 3 times
- `--timeout=90` — a job running longer than 90 seconds is terminated
- `numprocs=1` — one active worker process (increase for more parallelism)

### Monitoring the worker

**1. Web Interface (Recommended)**
You can easily check the process status and read logs from your browser:
- Go to: `http://localhost:9001`
- User: `admin` / Password: `password` *(development credentials)*

**2. Command Line**
```bash
# View real-time worker logs in real time
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
docker compose down

# — or —

# Stop the FrankenPHP stack before switching to Standard
docker compose -f docker-compose-franken.yml down
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

## File uploads and document persistence

*Section added on 18/08/2026 with 1.10.0-beta.58.*

### Upload limits are declared in the images

Up to beta.57 none of the three images declared a limit, so nginx's default applied — **1 MB** —
and a 1.5 MB PDF was refused while the application promised 20. The values are now declared in the configuration of each image — `docker/standard/Dockerfile` and `docker/standard/nginx/default.conf`, `docker/frankenphp/Dockerfile`, `docker/production/php.ini` and `docker/production/nginx.conf` — and are consistent with each other:

| | Value |
| :--- | :--- |
| `upload_max_filesize` (PHP) | 20M |
| `post_max_size` (PHP) | 25M |
| `client_max_body_size` (nginx) | 30M |

The FrankenPHP stack has no nginx: the two PHP values apply.

The order is deliberate: nginx is the highest, so the one that refuses is **PHP**, which can say so
with an understandable message instead of a web-server error. The application has no limit of its
own: it reads PHP's and writes that on screen.

### What does not survive recreating a container

`storage/app` holds uploaded documents, backups and attachments. In this compose the folder comes
from the `./:/var/www` bind mount, so it lives on the host and is safe. **In a real deployment that
is not a given**: without a declared volume that folder sits in the container's writable layer and
disappears the first time it is recreated, with nothing saying so beforehand.

To find out before rather than after:

```bash
docker compose exec app php artisan kondomanager:verifica-persistenza
```

It answers with how many files and how many megabytes are at stake, and if the folder is inside the
container it explains where to declare the volume. Outside the development environment, with `--rigoroso` it exits with an error code when the volume is missing, so
it can go into a deployment pipeline; in the development stack (`local` environment) it answers that no volume is needed and does not fail.

---

## Troubleshooting

### `permission denied` when starting
The entrypoint script is not executable. Run:
```bash
chmod +x docker/standard/entrypoint.sh
chmod +x docker/standard/worker-entrypoint.sh
# or for FrankenPHP:
chmod +x docker/frankenphp/entrypoint.sh
chmod +x docker/frankenphp/worker-entrypoint.sh
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
The frontend is recompiled at every start of the container, but only if `public/build/manifest.json` is missing or a file in `resources`, `lang`, `vite.config.ts` or `package-lock.json` is newer than the manifest: restart the container (`docker compose restart app`). To force a rebuild:
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

### The application does not open from a phone or another computer
The stack ports are published on `127.0.0.1` only, so they answer only from the computer where Docker runs. This is deliberate: see Step 5 for what happens if you remove `127.0.0.1:` in front of the port.

### CORS error / redirect to `https://` instead of `http://`
If the browser shows a `Cross-Origin Request Blocked` error or the page tries to open `https://localhost:8889`, the problem is `APP_URL` in the `.env` file.

**Cause:** the `.env` in your project folder was previously created by Herd, Coolify, or another environment, and contains `APP_URL=https://...`. Docker mounts host files directly into the container (volume mount), so it uses that `.env` as-is.

**Automatic fix, but conditional:** the `entrypoint.sh` sets `APP_URL=http://localhost:8889` **only if** the current value is empty, is exactly `http://localhost`, or contains `kondomanager-free.test`. Any other value (for example an `https://...` left by Herd or Coolify) is **preserved**, so as not to break installations behind a reverse proxy: in that case use the manual fix below.

**Manual fix (if needed):**
```bash
docker compose exec app sed -i 's|^APP_URL=.*|APP_URL=http://localhost:8889|' /var/www/.env
docker compose exec app php artisan config:clear
docker compose restart app
```
