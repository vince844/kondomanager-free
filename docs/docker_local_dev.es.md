# 🐳 Desarrollo Local con Docker

<!-- verifica-documentazione -->
> **Estado:** Coincide con el código — verificado y corregido el 31/07/2026 en 1.10.0-beta.32, ampliado el **18/08/2026 en 1.10.0-beta.58** con la sección «Carga de archivos y persistencia de los documentos»
> Se han corregido las cuatro afirmaciones erróneas detectadas en la auditoría: la rama de clonación (era `v1.9.1-beta`, que no existe), el `chmod` que faltaba en `docker/frankenphp/worker-entrypoint.sh`, la reescritura de APP_URL (es condicional) y el extracto de supervisord.conf, ahora con `[inet_http_server]` y el scheduler.
<!-- /verifica-documentazione -->

> **Plataformas compatibles:** Windows (WSL2), macOS, Linux, Synology NAS

---

## ¿Qué stack debo usar?

| Stack | Archivo Compose | Puerto | Recomendado para |
|-------|----------------|--------|------------------|
| **Standard** — PHP-FPM + Nginx | `docker-compose.yml` | `8889` | ✅ Windows / macOS / Linux |
| **FrankenPHP** — Laravel Octane | `docker-compose-franken.yml` | `8889` | 🧪 Synology NAS *(pendiente de pruebas)* |

> ℹ️ El `Dockerfile` en la raíz del repositorio se usa **únicamente para Coolify (producción)** — no es necesario para el desarrollo local.

**¿Por qué usar Standard en Windows/macOS/Linux?**  
La stack PHP-FPM + Nginx es robusta, fácil de depurar y ampliamente documentada. FrankenPHP funciona con un único proceso (menor uso de memoria), lo que puede ser interesante en un Synology NAS, pero aún no ha sido completamente validado en ese entorno.

---

## Requisitos previos

- **Docker Desktop** ≥ 4.x instalado y en ejecución
  - En Windows: activa el backend WSL2 → *Settings → General → "Use the WSL 2 based engine"*
- Git

### ⚠️ Usuarios de Windows / WSL2 — importante

Clona siempre el repositorio **dentro de WSL** (el sistema de archivos de Linux), no en tu unidad de Windows. Trabajar desde `/mnt/c/Users/...` provoca errores de permisos y es extremadamente lento.

```bash
# ✅ Correcto — sistema de archivos Linux (mejor rendimiento)
cd ~/projects
git clone ...

# ❌ Evitar — sistema de archivos de Windows montado
# /mnt/c/Users/tunombre/...
```

---

## Paso 1 — Clonar el repositorio

Abre tu terminal (en macOS/Linux) o el terminal WSL (en Windows) y ejecuta:

```bash
git clone -b v1.9.1 https://github.com/vince844/kondomanager-free.git
cd kondomanager-free
```

---

## Paso 2 — Establecer permisos en los scripts de inicio

Antes de hacer el build, haz que los scripts de inicio sean ejecutables. Esto es obligatorio en Linux/WSL — sin este paso obtendrás un error `permission denied`.

**Si usas la stack Standard (Nginx):**
```bash
chmod +x docker/standard/entrypoint.sh
chmod +x docker/standard/worker-entrypoint.sh
```

**Si usas la stack FrankenPHP:**
```bash
chmod +x docker/frankenphp/entrypoint.sh
chmod +x docker/frankenphp/worker-entrypoint.sh
```

---

## Paso 3 — Build e inicio

### Stack Standard (recomendado)

```bash
docker-compose up --build -d
```

### Stack FrankenPHP

```bash
docker-compose -f docker-compose-franken.yml up --build -d
```

> El primer build tarda aproximadamente **3–5 minutos** — Docker instala las extensiones de PHP, Node.js, las dependencias de Composer y compila los assets del frontend.

---

## Paso 4 — Revisar los logs

Espera el mensaje de inicialización en el log del container de la aplicación:

**Stack Standard:**
```bash
docker logs kondo_app
```
Busca: `✅ KondoManager Standard Pronto!`

**Stack FrankenPHP:**
```bash
docker logs kondo_app_franken
```
Busca: `✅ KondoManager FrankenPHP Pronto!`

---

## Paso 5 — Abrir la aplicación

Una vez que aparezca el mensaje de éxito:

| Servicio | URL | Credenciales |
|----------|-----|--------------|
| **Aplicación Web** | http://localhost:8889 | Email: `admin@km.com` / Contraseña: `password` |
| **Base de Datos MySQL** | `127.0.0.1:3307` | Usuario: `root` / Contraseña: `root` / DB: `kondomanager_dev` |

Puedes conectarte a la base de datos con cualquier cliente MySQL (TablePlus, DBeaver, MySQL Workbench, etc.) usando las credenciales anteriores.

---

## Qué ocurre automáticamente en el primer inicio

El script de entrypoint ejecuta los siguientes pasos sin ninguna intervención manual:

1. Copia `.env.example` → `.env` (si no existe aún)
2. Configura la conexión a la base de datos para apuntar al container `db`
3. Instala las dependencias PHP mediante Composer
4. Genera la `APP_KEY`
5. Espera a que MySQL esté disponible
6. Instala las dependencias de Node.js y compila los assets del frontend *(solo en el primer inicio)*
7. Ejecuta las migraciones de la base de datos
8. Ejecuta los seeders *(solo si la base de datos está vacía — seguro reiniciar)*

---

## Procesos en background — Supervisor

En la stack Standard, los procesos en background (queue worker, scheduler) son gestionados por **Supervisor**, que los mantiene activos y los reinicia automáticamente en caso de fallo.

### Arquitectura

| Container | Proceso | Gestionado por |
|-----------|---------|---------------|
| `kondo_app` | PHP-FPM (peticiones web) | php-fpm directamente |
| `kondo_worker` | Laravel queue worker | **Supervisor** |
| `kondo_nginx` | Servidor web | Nginx |
| `kondo_db` | Base de datos | MySQL |

El container `kondo_worker` inicia Supervisor al arrancar, que a su vez inicia y monitorea `php artisan queue:work`.

### Configuración de Supervisor

El archivo de configuración se encuentra en [`docker/supervisord.conf`](../docker/supervisord.conf):

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

**Parámetros principales:**
- `--sleep=3` — espera 3 segundos entre jobs cuando la cola está vacía
- `--tries=3` — un job fallido se reintenta hasta 3 veces
- `--timeout=90` — un job que dure más de 90 segundos es interrumpido
- `numprocs=1` — un solo proceso worker activo (aumentable para más paralelismo)

### Monitorear el worker

**1. Interfaz Web (Recomendado)**
Puedes comprobar cómodamente el estado de los procesos y leer los logs desde tu navegador:
- Ve a: `http://localhost:9001`
- Usuario: `admin` / Contraseña: `password`

**2. Línea de comandos**
```bash
# Ver los logs del worker en tiempo real
docker compose logs -f worker

# Ver los logs escritos por Supervisor en el archivo
docker compose exec worker cat /var/www/storage/logs/worker.log

# Estado de Supervisor dentro del container
docker compose exec worker supervisorctl status

# Reiniciar manualmente el worker
docker compose exec worker supervisorctl restart laravel-worker:*
```

### Aumentar los procesos worker (para alta carga)

Edita `docker/supervisord.conf`:
```ini
numprocs=3   # inicia 3 workers en paralelo
```

Luego reconstruye el container:
```bash
docker compose up --build -d worker
```

---

## Cambiar entre stacks

> ⚠️ **Ambas stacks usan los mismos puertos (8889 y 3307).** Si quieres cambiar de una a otra, detén primero la stack activa para evitar conflictos de puertos.

```bash
# Detener la stack Standard antes de cambiar a FrankenPHP
docker-compose down

# — o —

# Detener la stack FrankenPHP antes de cambiar a Standard
docker-compose -f docker-compose-franken.yml down
```

---

## Comandos útiles

```bash
# Ejecutar un comando Artisan dentro del container de la aplicación
docker compose exec app php artisan <comando>

# Abrir una shell dentro del container de la aplicación
docker compose exec app bash

# Ver los logs del worker (stack Standard)
docker compose logs -f worker

# Ver el estado de todos los containers
docker compose ps

# Reiniciar el container de la aplicación (ej.: tras editar el .env)
docker compose restart app

# Reset completo — destruye todos los containers Y el volumen de la base de datos
docker compose down -v
docker compose up --build -d

# Forzar la recompilación de los assets del frontend
docker compose exec app rm -rf public/build
docker compose exec app npm run build

# Forzar la re-ejecución de los seeders (útil durante el desarrollo)
docker compose exec app php artisan db:seed --force
```

---

## Carga de archivos y persistencia de los documentos

*Sección añadida el 18/08/2026 con la 1.10.0-beta.58.*

### Los límites de carga están declarados en las imágenes

Hasta la beta.57 ninguna de las tres imágenes declaraba un límite, por lo que se aplicaba el valor
predeterminado de nginx — **1 MB** — y un PDF de 1,5 MB era rechazado mientras la aplicación
prometía 20. Ahora los valores están escritos en los `Dockerfile` y son coherentes entre sí:

| | Valor |
| :--- | :--- |
| `upload_max_filesize` (PHP) | 20M |
| `post_max_size` (PHP) | 25M |
| `client_max_body_size` (nginx) | 30M |

El orden no es casual: nginx es el más alto, así quien rechaza es **PHP**, que sabe decirlo con un
mensaje comprensible en lugar de un error del servidor web. La aplicación no tiene un límite propio:
lee el de PHP y escribe ese en la pantalla.

### Qué no sobrevive a la recreación de un contenedor

`storage/app` guarda los documentos cargados, las copias de seguridad y los adjuntos. En este
compose la carpeta llega del bind mount `./:/var/www`, así que vive en el host y está a salvo. **En
un despliegue real no es seguro**: sin un volumen declarado esa carpeta está en la capa escribible
del contenedor y desaparece en la primera recreación, sin que nada lo avise antes.

Para saberlo antes y no después:

```bash
docker compose exec app php artisan kondomanager:verifica-persistenza
```

Responde diciendo cuántos archivos y cuántos megabytes están en juego, y si la carpeta está dentro
del contenedor explica dónde declarar el volumen. Con `--rigoroso` sale con código de error, de modo
que puede ponerse en una tubería de despliegue.

---

## Resolución de problemas

### `permission denied` al iniciar
El script de entrypoint no tiene permisos de ejecución. Ejecuta:
```bash
chmod +x docker/standard/entrypoint.sh
chmod +x docker/standard/worker-entrypoint.sh
# o para FrankenPHP:
chmod +x docker/frankenphp/entrypoint.sh
chmod +x docker/frankenphp/worker-entrypoint.sh
```

### El container `app` se reinicia constantemente
Revisa los logs para identificar el error específico:
```bash
docker compose logs app
```

### MySQL no responde / la aplicación no puede conectarse a la BD
MySQL tarda ~10–15 segundos en inicializarse la primera vez. El script de entrypoint espera automáticamente, pero si lo interrumpiste, prueba:
```bash
docker compose restart app
docker compose logs db
```

### Los assets del frontend no se actualizan tras cambios en el código
El build se omite si la carpeta `public/build/` ya existe. Fuerza un rebuild:
```bash
docker compose exec app rm -rf public/build
docker compose exec app npm run build
```

### El puerto 8889 o 3307 ya está en uso
Otro proceso o stack de Docker está usando ese puerto. Ejecuta `docker compose down` en cualquier otra stack activa, o comprueba con:
```bash
# macOS / Linux / WSL
lsof -i :8889
lsof -i :3307
```

### Error CORS / redirección a `https://` en lugar de `http://`
Si el navegador muestra un error `Cross-Origin Request Blocked` o la página intenta abrir `https://localhost:8889`, el problema está en `APP_URL` dentro del archivo `.env`.

**Causa:** el `.env` de tu carpeta de proyecto fue creado anteriormente por Herd, Coolify u otro entorno, y contiene `APP_URL=https://...`. Docker monta los archivos del host directamente en el container (volume mount), por lo que utiliza ese `.env` tal cual.

**Corrección automática, pero condicionada:** el `entrypoint.sh` establece `APP_URL=http://localhost:8889` **solo si** el valor actual está vacío, es exactamente `http://localhost` o contiene `kondomanager-free.test`. Cualquier otro valor (por ejemplo un `https://...` dejado por Herd o Coolify) se **conserva**, para no romper las instalaciones detrás de un proxy inverso: en ese caso usa la corrección manual de abajo.

**Fix manual (si es necesario):**
```bash
docker compose exec app sed -i 's|^APP_URL=.*|APP_URL=http://localhost:8889|' /var/www/.env
docker compose exec app php artisan config:clear
docker compose restart app
```
