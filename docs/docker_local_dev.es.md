# 🐳 Desarrollo Local con Docker

<!-- verifica-documentazione -->
> **Estado:** Coincide con el código — releída el 04/11/2026 en 1.12.0-beta.1, contrastada con `docker-compose.yml`, `docker-compose-franken.yml` y los scripts de `docker/standard/` y `docker/frankenphp/`. **Reescrita el 04/11/2026:** el clon ya no apunta a la rama de una versión antigua sino a la rama predeterminada (`main`); los puertos de la stack están abiertos solo en `127.0.0.1`; al principio hay un aviso de que esta stack sirve para desarrollar KondoManager, no para usarlo; se han quitado las referencias a Synology NAS (para un NAS está la [guía de Synology](synology_nas_install.es.md)) y se han corregido las frases que los scripts de inicio ya no confirmaban (qué se instala al arrancar y cuándo se recompila el frontend).
> No probada en esta reescritura en un ordenador limpio: las líneas de los puertos se han validado con `docker compose config`, no se han arrancado.
<!-- /verifica-documentazione -->

> **Plataformas compatibles:** Windows (WSL2), macOS, Linux

> ⚠️ **Esta guía sirve para desarrollar KondoManager en tu ordenador. No es la forma de instalarlo para usarlo.** En esta stack el entorno es `local`, la depuración está activada y las contraseñas son las escritas más abajo: las conoce cualquiera que lea la guía. Por eso los puertos (aplicación, MySQL, Supervisor) están abiertos **solo en `127.0.0.1`**, es decir, solo desde el ordenador en el que funciona. Para usar KondoManager en un despacho, en un servidor o en un NAS existe la imagen de producción: la [guía de Docker (producción)](https://kondomanager.com/docs/installation-docker-produzione.html), escrita en italiano, y para Synology la [guía para Synology NAS](synology_nas_install.es.md).

---

## ¿Qué stack debo usar?

| Stack | Archivo Compose | Puerto | Recomendado para |
|-------|----------------|--------|------------------|
| **Standard** — PHP-FPM + Nginx | `docker-compose.yml` | `8889` | ✅ Windows / macOS / Linux |
| **FrankenPHP** — Laravel Octane | `docker-compose-franken.yml` | `8889` | 🧪 Experimental |

> ℹ️ El `Dockerfile` en la raíz del repositorio construye la **imagen de producción** (`ghcr.io/vince844/kondomanager-core`, publicada con cada versión): para desarrollar no hace falta. Las dos stacks de desarrollo usan `docker/standard/Dockerfile` y `docker/frankenphp/Dockerfile`.

**¿Por qué Standard?**
La stack PHP-FPM + Nginx es robusta, fácil de depurar y ampliamente documentada. FrankenPHP atiende las peticiones web con un único proceso, pero la stack es experimental: si no tienes un motivo para probarla, usa Standard.

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
git clone https://github.com/vince844/kondomanager-free.git
cd kondomanager-free
```

Sin más opciones, git descarga la rama predeterminada, `main`: en la guía no hay un número de versión que mantener al día. Para partir de una versión concreta (por ejemplo para reproducir un problema) usa la etiqueta, escrita completa porque `v1.11.0` es también el nombre de una rama:

```bash
git checkout refs/tags/v1.11.0
```

---

## Paso 2 — Comprobar los permisos de los scripts de inicio

Los scripts de inicio llegan del repositorio con el permiso de ejecución ya establecido: git lo conserva. Puede perderse si has descargado el ZIP o has clonado desde el sistema de archivos de Windows, y entonces el arranque se detiene con el error `permission denied`. Hacer ejecutables los scripts es inofensivo aunque ya lo sean, así que puedes hacerlo igualmente antes del build.

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
docker compose up --build -d
```

### Stack FrankenPHP

```bash
docker compose -f docker-compose-franken.yml up --build -d
```

> El primer arranque tarda unos minutos: Docker construye la imagen (extensiones de PHP y Node.js) y después el container de la aplicación, en su primer arranque, instala las dependencias de Composer y npm y compila el frontend. La compilación del frontend llega a unos **1,1 GB de memoria**: si la memoria concedida a Docker es menor, ese paso se interrumpe. En ese caso la base de datos ya está al día, pero el script de arranque se detiene con un error y el container de la aplicación no arranca: la aplicación no responde hasta que le des más memoria a Docker y vuelvas a arrancar la stack (`docker compose up -d`; para FrankenPHP `docker compose -f docker-compose-franken.yml up -d`).

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

| Servicio | URL | Credenciales de desarrollo |
|----------|-----|--------------|
| **Aplicación Web** | http://localhost:8889 | Email: `admin@km.com` / Contraseña: `password` |
| **Base de Datos MySQL** | `127.0.0.1:3307` | Usuario: `root` / Contraseña: `root` / DB: `kondomanager_dev` |

Puedes conectarte a la base de datos con cualquier cliente MySQL (TablePlus, DBeaver, MySQL Workbench, etc.) usando las credenciales anteriores.

> ⚠️ **Son credenciales de desarrollo, y funcionan solo porque la stack está en el entorno `local`.** El administrador `admin@km.com` lo crea el seeder únicamente con `APP_ENV=local`; con otro entorno el seeder genera una contraseña aleatoria. No uses estas contraseñas, ni esta stack, para los datos de un despacho.
>
> Los puertos responden **solo desde este ordenador** (`127.0.0.1`): desde el móvil o desde otro ordenador de la red no se abren. Si necesitas usarlos desde otro dispositivo, quita `127.0.0.1:` delante del puerto en `docker-compose.yml` (o en `docker-compose-franken.yml`) y vuelve a crear los containers: desde ese momento cualquiera que esté en tu red puede alcanzarlo, con esas contraseñas.

---

## Qué ocurre en cada inicio

El script de entrypoint ejecuta los siguientes pasos sin ninguna intervención manual. Algunos son solo del primer inicio, otros se repiten en cada arranque del container:

1. Copia `.env.example` → `.env` si no existe aún, y lo pone en el entorno de desarrollo: `APP_ENV=local` y `APP_DEBUG=true` *(solo cuando crea el archivo: un `.env` que ya existe no se toca, salvo las líneas de la base de datos y `APP_URL`, más abajo)*
2. Configura la conexión a la base de datos para apuntar al container `db` *(en cada inicio)*
3. Establece `APP_URL=http://localhost:8889` si está vacío, es exactamente `http://localhost` o contiene `kondomanager-free.test`; cualquier otro valor se queda como está
4. Instala las dependencias PHP mediante Composer *(en cada inicio: cuesta pocos segundos cuando no hay nada que hacer)*
5. Genera la `APP_KEY` si falta
6. Espera a que MySQL esté disponible
7. Instala las dependencias de Node.js con `npm install` *(en cada inicio)*
8. Vacía las cachés (`php artisan optimize:clear`) y ejecuta las migraciones de la base de datos
9. Ejecuta los seeders *(solo si todavía no hay ningún usuario — seguro reiniciar)*
10. Alinea roles y permisos, la lista de municipios, la clasificación ATECO y la versión registrada (`php artisan kondomanager:aggiorna`)
11. Compila los assets del frontend *(solo si `public/build/manifest.json` falta, o si un archivo de `resources`, `lang`, `vite.config.ts` o `package-lock.json` es más reciente que el manifest)*

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

El archivo de configuración se encuentra en [`docker/supervisord.conf`](../docker/supervisord.conf). A continuación, un extracto (faltan las secciones `[rpcinterface:supervisor]` y `[supervisorctl]`, que sirven al comando `supervisorctl`). **El usuario y la contraseña de la interfaz web son los de desarrollo, escritos en el archivo:**

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

> ℹ️ La interfaz web de Supervisor (puerto 9001) existe solo en esta stack de desarrollo, y responde solo desde este ordenador porque el puerto está publicado en `127.0.0.1`. La imagen de producción no la abre: su Supervisor tiene solo un socket local y ningún puerto HTTP.

**Parámetros principales:**
- `--sleep=3` — espera 3 segundos entre jobs cuando la cola está vacía
- `--tries=3` — un job fallido se reintenta hasta 3 veces
- `--timeout=90` — un job que dure más de 90 segundos es interrumpido
- `numprocs=1` — un solo proceso worker activo (aumentable para más paralelismo)

### Monitorear el worker

**1. Interfaz Web (Recomendado)**
Puedes comprobar cómodamente el estado de los procesos y leer los logs desde tu navegador:
- Ve a: `http://localhost:9001`
- Usuario: `admin` / Contraseña: `password` *(credenciales de desarrollo)*

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
docker compose down

# — o —

# Detener la stack FrankenPHP antes de cambiar a Standard
docker compose -f docker-compose-franken.yml down
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
prometía 20. Ahora los valores están declarados en la configuración de cada imagen — `docker/standard/Dockerfile` y `docker/standard/nginx/default.conf`, `docker/frankenphp/Dockerfile`, `docker/production/php.ini` y `docker/production/nginx.conf` — y son coherentes entre sí:

| | Valor |
| :--- | :--- |
| `upload_max_filesize` (PHP) | 20M |
| `post_max_size` (PHP) | 25M |
| `client_max_body_size` (nginx) | 30M |

En la stack FrankenPHP no hay nginx: valen los dos valores de PHP.

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
del contenedor explica dónde declarar el volumen. Fuera del entorno de desarrollo, con `--rigoroso` sale con código de error cuando falta el volumen, de modo
que puede ponerse en una tubería de despliegue; en la stack de desarrollo (entorno `local`) responde que no hace falta un volumen y no falla.

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
El frontend se recompila en cada arranque del container, pero solo si `public/build/manifest.json` falta o si un archivo de `resources`, `lang`, `vite.config.ts` o `package-lock.json` es más reciente que el manifest: reinicia el container (`docker compose restart app`). Para forzar la recompilación:
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

### La aplicación no se abre desde el móvil ni desde otro ordenador
Los puertos de la stack están publicados solo en `127.0.0.1`, así que responden únicamente desde el ordenador en el que funciona Docker. Es a propósito: mira el Paso 5 para saber qué ocurre si quitas `127.0.0.1:` delante del puerto.

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
