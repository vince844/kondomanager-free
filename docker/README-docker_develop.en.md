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

## 3. Clone the fork locally

```bash
git clone https://github.com/YOUR_USERNAME/kondomanager-free.git
cd kondomanager-free
```

----------

## 4. Docker Structure

```
kondomanager/
└── docker/
    └── Dockerfile
    └── nginx/
        └── default.conf
```

### Included services

| Service | Purpose |
|-------|--------|
| app | PHP 8.2 + Composer + Node.js |
| nginx | Web server |
| db | MySQL 8.0 |
| phpmyadmin | Database management |

----------

## 5. Environment Variables (.env)

1. Copy the example file (if it does not exist yet):

```bash
cp .env.example .env
```

2. Confirm the main values:

```env
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8889

DB_HOST=db
DB_DATABASE=kondomanager_dev
DB_USERNAME=kondomanager
DB_PASSWORD=kondomanager
```

⚠️ **Never commit `.env` files with real production credentials.**

----------

## 6. Build and start the Docker environment

From the project root:

```bash
docker compose build
docker compose up -d
```

Check containers:

```bash
docker compose ps
```

----------

## 7. Application setup

### Access the application container

```bash
docker compose exec app bash
```

### Install PHP dependencies

```bash
composer install
```

### Install frontend dependencies

```bash
npm install
npm run dev
```

### Generate application key

```bash
php artisan key:generate
```

### Run database migrations

```bash
php artisan migrate --seed
```

----------

## 8. Access points

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

## 9. Useful Docker commands

```bash
# Stop containers
docker compose down

# Full rebuild
docker compose down -v
docker compose build --no-cache
docker compose up -d

# Logs
docker compose logs -f app
```

----------

## 10. Recommended Git workflow (development)

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
