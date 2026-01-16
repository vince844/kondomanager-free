# Kondomanager – Complete Docker Environment Guide (Dev & Deploy)
 
This document describes **step by step**, in a **simple and reproducible** way, how any user (even with basic knowledge) can install, configure, and work with **Kondomanager** in a Docker environment, as well as prepare continuous integration and deployment.

----------

## 1. Prerequisites

Before you start, make sure you have installed:

- **Git** (>= 2.30)
- **Docker Desktop** (includes Docker + Docker Compose)
  - Windows / macOS: https://www.docker.com/products/docker-desktop
  - Linux: docker + docker-compose-plugin

Quick check:
```bash
git --version
docker --version
docker compose version
```

----------

## 2. Repository Fork

1. Log in to GitHub
2. Go to:
   https://github.com/vince844/kondomanager-free
3. Click **Fork** (top right corner)
4. Choose your personal account
5. (Optional) Rename the repository

Result: the project will be under your control.

----------

# 2.1. Clone the fork locally

```bash
git clone https://github.com/YOUR_USERNAME/kondomanager-free.git
cd kondomanager-free
```

----------

# 2.2. Docker Structure

```
kondomanager/
├── docker/
│   ├── Dockerfile
│   ├── docker-compose_develop.yml
│   ├── entrypoint.sh
│   └── nginx/
│       └── default.conf

```

# 2.3. Included services

| Service | Purpose |
|-------|--------|
| app | PHP 8.2 (runtime) + Composer (build-time) |
| nginx | Web server |
| db | MySQL 8.0 |
| phpmyadmin | Database management |

----------

## 3. Environment Variables (.env)

1. Copy the example file (if it does not exist yet):

```bash
cp .env.example .env
```

2. Confirm the Key variables for development:

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

⚠️ **Never commit `.env` files with real production credentials.**

----------

## 4. Start the development environment

From the project root, run:

```bash
docker compose -f docker/docker-compose_develop.yml up -d --build
```

That’s it.  
No additional commands are required.

Check containers:

```bash
docker compose ps
```

---

## 5. Access points

**Application**
http://localhost:8889

**phpMyAdmin**
http://localhost:8990

**MySQL**
Host: localhost
Port: 3307

MySQL credentials:
- User: `kondomanager`
- Password: `kondomanager`

----------

### Useful Docker commands

Stop the environment:

```bash
docker compose -f docker/docker-compose_develop.yml down
```

Full reset (including volumes):

```bash
docker compose -f docker/docker-compose_develop.yml down -v
docker compose -f docker/docker-compose_develop.yml up -d --build
```

View logs:

```bash
docker compose -f docker/docker-compose_develop.yml logs -f
```

---

## 6. What happens automatically

When the containers start:

- PHP extensions are installed at build time
- Composer dependencies are installed at build time
- Frontend assets are built at build time
- Laravel `APP_KEY` is generated automatically (if missing)
- `storage` symlink is created
- Database migrations are executed automatically when `AUTO_MIGRATE=true`

This guarantees a consistent environment for every developer and for CI pipelines.

---

## 7. Development workflow

### Backend (Laravel)

- Controllers, routes, configuration and Blade templates are hot-reloaded
- No container restart is required for backend changes

### Frontend

- Frontend assets are built during the Docker image build
- To reflect frontend changes, rebuild the image:

```bash
docker compose -f docker/docker-compose_develop.yml up -d --build
```

This behavior is intentional to keep the environment predictable and reproducible.

---


## 8. CI/CD note

Kondomanager is **Docker-first**.

CI pipelines only need to run:

```bash
docker build .
```

For development-like CI environments, `docker compose` can also be used.
No runtime setup or manual provisioning is required.

---


## 9. Recommended Git workflow (development)

### Branches

```text
main        → production
staging     → pre-production
develop     → development
feature/*   → new features
fix/*       → bug fixes
```

### Create a new feature

```bash
git checkout develop
git pull
git checkout -b feature/my-feature
```

Commit:

```bash
git add .
git commit -m "feat: clear description"
git push origin feature/my-feature
```

Open a Pull Request targeting `develop`.

----------

## 10. Final notes

- Do not run `composer install` or `npm install` manually
- Do not mount the entire project directory as a volume
- All environments (dev, CI, prod) should rely on the Docker image as the source of truth
- A `.dockerignore` file is used to keep Docker builds fast and deterministic

This approach ensures:
- Fast onboarding
- Minimal human error
- Clean and maintainable CI/CD pipelines

## 11. Best practices

- ❌ Never use `root` in production
- ❌ Never version `.env` files
- ✅ Automatic database backups
- ✅ Centralized logging
- ✅ Regular security updates

----------

## 12. Support

This guide allows **any user** to bring up a fully functional environment in minutes.
For production deployments, always validate with a **system administrator**.
