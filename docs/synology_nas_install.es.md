# 💾 Instalación en Synology NAS (Container Manager)

<!-- verifica-documentazione -->
> **Estado:** Coincide con el código — **reescrita el 04/11/2026 en 1.12.0-beta.1, y todavía no probada en un NAS Synology.** Describe la imagen `ghcr.io/vince844/kondomanager-core` de la 1.11.0, la misma de la [guía de Docker (producción)](https://kondomanager.com/docs/installation-docker-produzione.html) del sitio (escrita en italiano), que se probó en Docker Desktop (macOS, en emulación) y no en un NAS. Los pasos de la interfaz de DSM están escritos a partir de cómo Synology documenta Container Manager (DSM 7.2) y no se han verificado en un aparato real. La versión anterior de esta guía hacía construir el código desde las fuentes con la stack de desarrollo (depuración activada, contraseñas de prueba, puertos abiertos en la red): se ha sustituido.
<!-- /verifica-documentazione -->

> ⚠️ **Esta guía todavía no se ha probado en un NAS Synology.** Los archivos y los comandos vienen de la imagen de producción, probada en otro sitio; los pasos de la interfaz de DSM no. La primera vez hazla en una carpeta nueva, sin tocar las carpetas compartidas que ya tienes: la sección «Para quitar la prueba», al final, dice cómo quitarlo todo.

KondoManager funciona en un NAS Synology como imagen Docker ya preparada (`ghcr.io/vince844/kondomanager-core`): no se descarga el código y no se compila nada. En **Container Manager** se crea un proyecto a partir de dos archivos, un `docker-compose.yml` y un `.env`, y el contenedor hace el resto: en el primer arranque se instala solo, y en cada arranque pone al día la base de datos.

## Qué necesitas

1. Un NAS Synology con **DSM 7.2 o posterior** y **Container Manager**, que se instala desde el Centro de Paquetes. Antes de la 7.2 el paquete se llama «Docker»: esta guía no está escrita para eso.
2. Un modelo con procesador **x86-64** (Intel o AMD). La imagen está construida solo para `linux/amd64` (comprobado en el registro el 04/11/2026): en los modelos con procesador ARM Docker puede emularla, pero no lo hemos probado y no lo recomendamos. El procesador de tu modelo está en las especificaciones, en el sitio de Synology.
3. Memoria libre para MySQL y PHP juntos. No la hemos medido, así que no hay una cifra: antes de empezar, comprueba cuánta queda libre en el NAS.
4. Un ordenador, para preparar dos archivos de texto y para crear la clave y las contraseñas con `openssl` (macOS y Linux; en Windows el terminal de Git o WSL).
5. Solo si quieres abrir KondoManager desde fuera de casa, en https: un nombre de dominio, y el proxy inverso de DSM (última sección).

## Antes de empezar: dónde van los archivos, y qué no se toca

- Los dos archivos están en **una carpeta nueva, solo para KondoManager**: la subcarpeta `kondomanager` de la carpeta compartida `docker`, la que Container Manager usa para sus proyectos (normalmente la crea él al instalarlo; si no existe, crea una carpeta compartida nueva solo para esto). No uses una carpeta que contenga tus documentos. El archivo `.env` contiene las contraseñas: comprueba que la carpeta `docker` no esté compartida con otros usuarios.
- **Antes de crear la carpeta, comprueba que el nombre esté libre.** En File Station no debe existir ya una carpeta `docker/kondomanager`, y en Container Manager, pestaña **Proyecto**, no debe existir ya un proyecto `kondomanager`: la versión anterior de esta guía lo creaba con este nombre, en la carpeta `docker/kondomanager-free`. Docker reconoce un proyecto por su nombre, y trata dos proyectos con el mismo nombre como uno solo: los comandos de esta guía actuarían también sobre los contenedores del otro. Si el nombre ya está ocupado, usa `kondomanager-prova` para la carpeta y para el proyecto, y léelo en lugar de `kondomanager` en las rutas, en el nombre del proyecto y en los nombres de los volúmenes de toda la guía.
- **Ninguna carpeta compartida que ya tengas se conecta al contenedor.** La base de datos y los documentos cargados están en dos *volúmenes* que Docker crea por su cuenta, normalmente bajo `@docker` (el área de Docker en el volumen del NAS), y no en una carpeta compartida visible desde File Station.
- **No hace falta ningún `chmod` ni cambiar ningún permiso**: el contenedor prepara solo sus carpetas. Si en otro sitio lees que hay que dar permisos `777` a una carpeta compartida, para esta guía no es necesario: no lo hagas.
- Cuando acabe la prueba se quita todo, sin dejar nada: mira «Para quitar la prueba».

---

## Paso 1 — Los dos archivos

En un ordenador crea una carpeta `kondomanager` y dentro dos archivos.

### `docker-compose.yml`

Es el mismo archivo de la guía de Docker (producción) del sitio:

```yaml
services:
  app:
    image: ghcr.io/vince844/kondomanager-core:${KONDOMANAGER_VERSIONE}
    platform: linux/amd64
    restart: unless-stopped
    depends_on:
      db:
        condition: service_healthy
    ports:
      - "8080:80"
    environment:
      APP_KEY: ${APP_KEY}
      APP_URL: ${APP_URL}
      DB_HOST: db
      DB_DATABASE: kondomanager
      DB_USERNAME: kondomanager
      DB_PASSWORD: ${DB_PASSWORD}
      INSTALL_ADMIN_NAME: ${INSTALL_ADMIN_NAME}
      INSTALL_ADMIN_EMAIL: ${INSTALL_ADMIN_EMAIL}
      INSTALL_ADMIN_PASSWORD: ${INSTALL_ADMIN_PASSWORD}
    volumes:
      - storage:/var/www/storage

  db:
    image: mysql:8.4
    restart: unless-stopped
    environment:
      MYSQL_DATABASE: kondomanager
      MYSQL_USER: kondomanager
      MYSQL_PASSWORD: ${DB_PASSWORD}
      MYSQL_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}
    volumes:
      - database:/var/lib/mysql
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "127.0.0.1"]
      interval: 5s
      timeout: 5s
      retries: 30

volumes:
  storage:
  database:
```

- `app` es KondoManager: responde en el puerto 8080 del NAS. Si el 8080 ya lo usa otro servicio, cambia el número de la izquierda (por ejemplo `"8090:80"`) y úsalo también en las direcciones de esta guía. Cuando tengas el https con el proxy inverso de DSM, escribe `"127.0.0.1:8080:80"`: así el puerto responde solo desde el propio NAS y no desde la red.
- `platform: linux/amd64` le dice a Docker que la imagen existe solo para x86-64.
- `db` es la base de datos, MySQL 8.4 (la versión que hemos probado). **No tiene `ports:`**: no se alcanza desde la red, solo desde KondoManager.
- `storage` y `database` son los dos volúmenes: ahí están los documentos, las copias de seguridad y los datos, y sobreviven cuando los contenedores se vuelven a crear.
- **No hay ninguna línea `volumes:` que apunte a una carpeta del NAS**: es a propósito.

### `.env`

```
KONDOMANAGER_VERSIONE=1.11.0
APP_KEY=
APP_URL=http://192.168.1.50:8080
DB_PASSWORD=
DB_ROOT_PASSWORD=
INSTALL_ADMIN_NAME=Mario Rossi
INSTALL_ADMIN_EMAIL=mario@esempio.it
INSTALL_ADMIN_PASSWORD=
```

Las líneas vacías son la clave y las contraseñas: **las escribes tú**, y ninguna está en esta página. Mientras sigan vacías el contenedor no se instala. `APP_URL` es la dirección con la que abres KondoManager: para la primera prueba, `http://` más la dirección IP de tu NAS y `:8080` (aquí un ejemplo). Escribe tu nombre y tu email como administrador.

El archivo se llama exactamente `.env`, con el punto delante y ninguna otra extensión (algunos editores de Windows añaden `.txt`).

## Paso 2 — La clave y las contraseñas

En el ordenador, en el terminal:

```bash
echo "base64:$(openssl rand -base64 32)"
openssl rand -hex 16
```

- El primer comando imprime una línea que empieza por `base64:`: pégala entera en `APP_KEY`.
- El segundo imprime una contraseña de 32 caracteres: ejecútalo dos veces, una para `DB_PASSWORD` y otra para `DB_ROOT_PASSWORD`. Sirve también para `INSTALL_ADMIN_PASSWORD`; si eliges una tú, que tenga al menos 10 caracteres y no lleve el carácter `$` (Docker Compose lo lee como el comienzo de una variable).

**Conserva la clave y el archivo `.env`.** Sin `APP_KEY` el contenedor no arranca y lo dice en el registro; con una clave distinta de la del primer arranque los datos cifrados ya no se pueden leer. Guarda una copia del archivo `.env` fuera del NAS.

Si tienes acceso SSH al NAS y no tienes `openssl` en el ordenador, la clave también se crea con la propia imagen: `sudo docker run --rm --entrypoint php ghcr.io/vince844/kondomanager-core:1.11.0 artisan key:generate --show`.

## Paso 3 — Poner los archivos en el NAS y crear el proyecto

Los nombres de los botones son los de Container Manager en DSM 7.2 tal como los documenta Synology: **no se han verificado en un NAS**.

1. Abre **File Station**, ve a la carpeta compartida `docker`, crea la carpeta `kondomanager` y sube dentro los dos archivos, `docker-compose.yml` y `.env`. Si File Station no muestra el `.env`, es un archivo oculto (empieza por un punto): activa la visualización de los archivos ocultos en los ajustes de File Station.
2. Abre **Container Manager** y ve a la pestaña **Proyecto**, luego **Crear**.
3. Rellena los campos:
   * **Nombre del proyecto:** `kondomanager`, el mismo nombre de la carpeta: los comandos por SSH de esta guía encuentran el proyecto por el nombre de la carpeta de su `docker-compose.yml`
   * **Ruta:** la carpeta `docker/kondomanager`
   * **Origen:** «Usar docker-compose.yml existente»
4. **Siguiente.** Si Container Manager propone configurar un portal web (Web Station), no lo actives: el proxy inverso de DSM es la última sección de esta guía.
5. **Siguiente** y luego **Hecho**, con la casilla «Iniciar proyecto una vez creado» seleccionada.

Container Manager descarga las dos imágenes (`kondomanager-core` y `mysql:8.4`) y arranca los dos contenedores. Cuánto tarda en tu NAS no lo sabemos: no lo hemos medido.

Si prefieres el terminal y tienes acceso SSH, basta `sudo docker compose -f /volume1/docker/kondomanager/docker-compose.yml up -d`. La carpeta compartida `docker` suele estar en `/volume1`; si la creaste en otro volumen del NAS, la ruta empieza por ese (`/volume2/…`). Los comandos por SSH de esta guía llevan la ruta completa del archivo: así actúan sobre este proyecto desde cualquier carpeta en la que los lances.

## Paso 4 — Comprobar que ha arrancado

1. En Container Manager, en el proyecto `kondomanager`, abre el registro del contenedor `app`. En cada arranque el contenedor comprueba la clave, prepara las carpetas, espera a la base de datos y, en el primer arranque, instala KondoManager con el administrador de tu `.env`: en el registro encuentras, entre otras líneas, «KondoManager installato. Accedi con mario@esempio.it.» (en italiano, con tu email).
2. Desde el navegador de un ordenador de tu red abre `http://IP-DEL-NAS:8080/up`: responde con `"stato":"ok"`, `"documenti":"su_volume"` y la versión. El detalle aparece porque llamas directamente, desde una red privada y sin proxy; a través de un proxy responde solo con `"stato"`.
3. Abre `http://IP-DEL-NAS:8080` e inicia sesión con el email y la contraseña que escribiste en `INSTALL_ADMIN_EMAIL` e `INSTALL_ADMIN_PASSWORD`. El contenedor los lee solo con una base de datos vacía, en el primer arranque: después del primer acceso puedes quitar la contraseña del `.env` y la cambias desde la aplicación.
4. **No hay ninguna interfaz de Supervisor que abrir.** La imagen de producción mantiene en el mismo contenedor el servidor web, PHP, el trabajador de colas y el programador de tareas, y no abre ningún puerto para controlarlos: el estado lo da el registro.

## Si algo no funciona

**El contenedor se reinicia constantemente y el registro dice «APP_KEY manca».**
La clave no ha llegado al contenedor: escríbela como en el Paso 2 en el archivo `.env`, en la misma carpeta que el `docker-compose.yml`.

**Aparece «invalid reference format» y el proyecto no arranca.**
No se ha leído el `.env` y el número de versión de la imagen (`KONDOMANAGER_VERSIONE`) se ha quedado vacío: la imagen se llamaría `ghcr.io/vince844/kondomanager-core:`, sin versión. Comprueba que el archivo se llame `.env` y esté junto al `docker-compose.yml`.

**El registro del proyecto dice «The "APP_KEY" variable is not set» (o lo mismo para otra variable).**
No se ha leído el `.env`. Docker Compose escribe estos avisos en el registro del *proyecto*, no en el del contenedor `app`. Comprueba que se llame `.env` y esté junto al `docker-compose.yml`. Si Container Manager sigue sin leerlo, escribe los valores directamente en el `docker-compose.yml` en lugar de los `${...}`.

**«Mancano i dati dell'amministratore: nessuna scrittura» (faltan los datos del administrador).**
La base de datos está vacía y los datos del administrador faltan o no son válidos: hace falta un email válido en `INSTALL_ADMIN_EMAIL`, una contraseña de al menos 10 caracteres en `INSTALL_ADMIN_PASSWORD` y, si lo escribes, un nombre de al menos 2. Las líneas anteriores dicen cuál. El contenedor no ha escrito nada: corrige el `.env` y vuelve a crear el proyecto.

**«Il database non risponde: nessuna scrittura» (la base de datos no responde).**
La base de datos no respondió en 120 segundos. Revisa el registro del contenedor `db`.

**`/up` responde 503 y el registro dice «storage/app NON è su un volume separato».**
Falta el volumen `storage` en el `docker-compose.yml`: vuelve a ponerlo como en el Paso 1. Sin él, documentos y copias de seguridad desaparecerían la primera vez que se vuelva a crear el contenedor.

**El puerto 8080 ya está en uso.**
Cambia el número de la izquierda en la línea `ports:` (por ejemplo `"8090:80"`) y vuelve a crear el proyecto.

**El aviso «The requested image's platform (linux/amd64) does not match the detected host platform».**
Tu NAS tiene un procesador ARM: la imagen es solo para x86-64. Mira «Qué necesitas».

## Exponer KondoManager a Internet (proxy inverso de DSM)

Para abrir KondoManager desde fuera de casa, por ejemplo `https://gestion.midominio.com`, con un certificado válido, el mejor método es el proxy inverso integrado en DSM. El certificado lo gestiona DSM.

1. Ve a **Panel de control** → **Portal de inicio de sesión** → **Avanzado** → **Proxy inverso**, luego **Crear**.
2. Configura la regla:
   - **Origen:** protocolo `HTTPS`, nombre de host `gestion.midominio.com` (o el dominio que hayas elegido), puerto `443`.
   - **Destino:** protocolo `HTTP`, nombre de host `127.0.0.1` (no `localhost`: con `"127.0.0.1:8080:80"` el puerto responde solo en esa dirección), puerto `8080` (o el que hayas elegido en el compose).
3. Guarda.
4. En el archivo `.env` escribe en `APP_URL` tu dominio **exacto**, https incluido (`APP_URL=https://gestion.midominio.com`): es la dirección que KondoManager pone en sus enlaces y desde la que carga las páginas. Después vuelve a crear el proyecto, porque un simple reinicio no vuelve a leer el `.env`: con SSH, `sudo docker compose -f /volume1/docker/kondomanager/docker-compose.yml up -d`; en Container Manager es la acción del proyecto que reconstruye los contenedores (el nombre del botón no está verificado).
5. **`TRUSTED_PROXIES`: no escribas nada.** En la imagen ya vale `PRIVATE_SUBNETS`, que se fía de un proxy conectado desde una red privada, incluidas las redes de Docker: el proxy de DSM se conecta desde el propio NAS. **No uses nunca `*`**: con `*` quien llega desde fuera elige su propia dirección, y el bloqueo de los intentos de acceso deja de funcionar. Los demás casos están en la página [Proxy y HTTPS](https://kondomanager.com/docs/configurazione-proxy-https.html) del sitio (en italiano).
6. Cuando el https funcione, escribe en el `docker-compose.yml` `"127.0.0.1:8080:80"`, así el puerto en http ya no responde desde la red de casa, y vuelve a crear el proyecto.

## Actualizar

Dentro del contenedor la actualización desde el panel está desactivada: actualizar significa usar la imagen de una versión nueva.

1. Desde el programa (su interfaz está en italiano): **Impostazioni → Gestione backups**, «Crea backup», y descárgalo.
2. En el archivo `.env` escribe la versión nueva en `KONDOMANAGER_VERSIONE`.
3. Descarga la imagen nueva y vuelve a crear el contenedor de KondoManager. Con SSH:
   ```bash
   sudo docker compose -f /volume1/docker/kondomanager/docker-compose.yml pull app
   sudo docker compose -f /volume1/docker/kondomanager/docker-compose.yml up -d
   ```
   `pull app` descarga solo la imagen de KondoManager: la base de datos se queda como está (un `pull` sin nombre descargaría también un MySQL nuevo). Qué botón de Container Manager hace lo mismo no está verificado.

Al arrancar, el contenedor aplica las migraciones y pone al día permisos, municipios, ATECO y versión, antes de servir una sola página.

## La copia de seguridad y los volúmenes

- La copia de seguridad del programa (**Impostazioni → Gestione backups**) contiene la base de datos y los documentos y está en `storage/app/backups`, es decir, en el volumen `storage`: se descarga desde la interfaz. Cómo funciona está en la guía [Copia de seguridad y restauración](https://kondomanager.com/docs/backup.html) del sitio (en italiano). La restauración dentro del contenedor todavía no la hemos probado.
- El archivo `.env` no está en el archivo comprimido: en el contenedor la configuración está fuera, junto al compose. Guarda una copia del `.env`, con su `APP_KEY`, junto con las copias de seguridad.
- Una copia que se queda en el NAS no te protege si pierdes el NAS: descárgala y guárdala en otro sitio.
- Los volúmenes `storage` y `database` no están en una carpeta compartida. No hemos verificado si los programas de copia del NAS (por ejemplo Hyper Backup) los incluyen: no cuentes con ello, y descarga las copias del programa.

## Si ya instalaste con la versión anterior de esta guía

La versión anterior hacía construir el código desde las fuentes, en una carpeta `kondomanager-free`, con la stack de desarrollo. Esa stack deja **en la red del NAS** el puerto de la base de datos y el de la interfaz de los procesos en segundo plano, con las credenciales de prueba que la propia guía publicaba, un administrador con la contraseña escrita en la guía y la depuración activada. Cualquiera que esté en tu red puede probarlas.

Mientras no pases a esta guía, al menos estos tres pasos, que **no hemos probado en un NAS**:

1. Desde el programa, cambia la contraseña del administrador que la guía antigua hacía crear.
2. En el `docker-compose.yml` de esa carpeta quita el bloque `ports:` de los servicios `db` y `worker`, es decir, la línea `ports:` y la de debajo (el programa sigue accesible desde el puerto del servicio `nginx`), y vuelve a crear el proyecto.
3. En el `.env` de la misma carpeta escribe `APP_DEBUG=false` y vuelve a crear el proyecto.

Después, en cuanto puedas, haz una copia de seguridad desde el programa, descárgala y pasa a esta guía. El traslado de los datos al contenedor nuevo no está probado. El proyecto de la versión anterior también se llama `kondomanager`: para esta guía usa el otro nombre, `kondomanager-prova`, como dice «Antes de empezar».

## Para quitar la prueba

Todo lo que crea esta guía está en una carpeta y dos volúmenes, y se quita sin tocar nada más. No borra ninguna de las carpetas compartidas que tenías.

1. **El proyecto y los datos de la prueba.** Con SSH, primero `sudo docker compose ls -a`: junto al proyecto `kondomanager` debe aparecer el archivo `/volume1/docker/kondomanager/docker-compose.yml`. Después `sudo docker compose -f /volume1/docker/kondomanager/docker-compose.yml down -v` detiene y elimina los contenedores y **borra los dos volúmenes**, es decir, la base de datos y los documentos de la prueba; los volúmenes de los demás proyectos no, porque la ruta del archivo le dice a Docker de qué proyecto se trata. Un `down -v` sin `-f`, lanzado desde la carpeta de otro proyecto, borraría los volúmenes de ese. Sin SSH: en Container Manager, pestaña **Proyecto**, `kondomanager`, **Detener** y luego **Eliminar**; los dos volúmenes (`kondomanager_database` y `kondomanager_storage`) sin embargo se quedan hasta que los borres, y ocupan espacio en el volumen del NAS pero no tocan ninguna carpeta compartida.
2. **Las imágenes.** En Container Manager, **Imagen**: elimina `ghcr.io/vince844/kondomanager-core` y `mysql:8.4`, esta última solo si ningún otro proyecto la usa.
3. **Los archivos.** En File Station elimina la carpeta `docker/kondomanager`: contiene solo los dos archivos de esta guía.
4. **El proxy, si lo creaste.** **Panel de control** → **Portal de inicio de sesión** → **Avanzado** → **Proxy inverso**: elimina la regla de KondoManager.

## Qué no cubre esta guía

No lo hemos probado, así que no lo prometemos: los pasos de Container Manager en un NAS real, DSM anterior a la 7.2, los modelos con procesador ARM, cuánta memoria hace falta y cuánto tarda el primer arranque, la dirección que KondoManager ve detrás del proxy de DSM, la restauración de una copia de seguridad dentro del contenedor, y el traslado de una instalación que ya tengas (documentos incluidos): en ese caso, primero una copia de seguridad.
