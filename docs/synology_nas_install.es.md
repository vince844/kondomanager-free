# 💾 Instalación en Synology NAS (Container Manager)

KondoManager puede alojarse fácilmente en tu NAS Synology utilizando **Container Manager** (anteriormente conocido como Docker). 
Esta guía utiliza la stack **Standard** (Nginx + PHP-FPM + Supervisor para procesos en background), que es la solución más fiable.

## Requisitos previos
1. Un NAS Synology compatible con **Container Manager** (generalmente los modelos "Plus" como DS220+, DS923+, etc.).
2. **Container Manager** instalado a través del Centro de Paquetes.
3. Acceso a las carpetas compartidas (asegúrate de tener una carpeta `docker` creada en tu NAS).

---

## Paso 1 — Obtener los archivos del proyecto

Tienes dos opciones: usar la interfaz web (File Station) o usar SSH.

### Opción A: Vía File Station (Más fácil, sin línea de comandos)
1. Descarga el archivo ZIP de KondoManager desde GitHub: [Descargar v1.8.0beta](https://github.com/vince844/kondomanager-free/archive/refs/heads/v1.8.0beta.zip).
2. Abre **File Station** en tu Synology.
3. Navega a la carpeta compartida `docker`.
4. Crea una nueva subcarpeta llamada `kondomanager-free`.
5. Sube el archivo ZIP dentro de esta carpeta y extráelo (clic derecho -> Extraer aquí).
6. Asegúrate de que todos los archivos (incluido `docker-compose.yml`) estén directamente dentro de `docker/kondomanager-free/` (y no en una subcarpeta adicional).

### Opción B: Vía SSH (Para usuarios avanzados)
1. Habilita SSH desde el Panel de control de Synology (Terminal y SNMP).
2. Inicia sesión en el NAS vía terminal (`ssh tuusuario@ip-del-nas`).
3. Ejecuta:
   ```bash
   cd /volume1/docker
   git clone -b v1.8.0beta https://github.com/vince844/kondomanager-free.git
   ```

---

## Paso 2 — Permisos de ejecución (¡Crucial!)

Para permitir que Docker inicie KondoManager, los archivos de inicio deben tener permisos de ejecución. Aquí es donde muchos usuarios se atascan con un error `permission denied`.

Si estás conectado vía **SSH**, simplemente ejecuta:
```bash
cd /volume1/docker/kondomanager-free
chmod +x docker/standard/entrypoint.sh
chmod +x docker/standard/worker-entrypoint.sh
```

**Si no quieres usar SSH**, puedes usar el Programador de tareas de Synology:
1. Ve al **Panel de control** -> **Programador de tareas**.
2. Crear -> **Tarea programada** -> **Script definido por el usuario**.
3. General: Nombre "Permisos KondoManager", Usuario: `root`.
4. Configuración de la tarea: Introduce este código:
   ```bash
   chmod +x /volume1/docker/kondomanager-free/docker/standard/entrypoint.sh
   chmod +x /volume1/docker/kondomanager-free/docker/standard/worker-entrypoint.sh
   ```
5. Haz clic en OK.
6. Selecciona la tarea recién creada y haz clic en **Ejecutar**. Una vez ejecutada, puedes eliminarla.

---

## Paso 3 — Crear el Proyecto en Container Manager

1. Abre **Container Manager** en tu Synology.
2. Ve a la pestaña **Proyecto** a la izquierda.
3. Haz clic en **Crear**.
4. Rellena los campos:
   * **Nombre del proyecto:** `kondomanager`
   * **Ruta:** Selecciona la carpeta `docker/kondomanager-free`
   * **Origen:** Selecciona "Usar docker-compose.yml existente"
5. Haz clic en **Siguiente**.
6. (Opcional) En la siguiente pantalla, si deseas cambiar los puertos para evitar conflictos con otros servicios en tu NAS, puedes editar el archivo YAML directamente desde la interfaz. Por defecto, KondoManager utilizará el puerto `8889`.
7. Haz clic en **Siguiente** y luego en **Hecho** (asegúrate de que la casilla "Iniciar proyecto una vez creado" esté seleccionada).

Container Manager comenzará a descargar imágenes y a construir el proyecto. **Este proceso tardará unos 3-5 minutos**.

---

## Paso 4 — Comprobar estado y procesos en background

En Container Manager, haz clic en el proyecto `kondomanager` recién creado para ver sus 4 contenedores:
- `kondo_app` (El núcleo de Laravel)
- `kondo_nginx` (El servidor web)
- `kondo_db` (La base de datos MySQL)
- `kondo_worker` (Supervisor que gestiona los procesos en background)

### Cómo acceder:
1. Abre tu navegador y ve a `http://IP-DE-TU-NAS:8889`
2. Inicia sesión con las credenciales por defecto:
   - Email: `admin@km.com`
   - Contraseña: `password`

### Interfaz del Worker (Supervisor):
Para asegurarte de que los procesos en background funcionan correctamente (emails en background, facturación automática, etc.):
1. Ve a `http://IP-DE-TU-NAS:9001`
2. Introduce usuario `admin` y contraseña `password`.
3. Verás el proceso `laravel-worker` en ejecución (RUNNING).

---

## Resolución de problemas en Synology

### El contenedor `kondo_app` se detiene continuamente
Revisa los logs desde Container Manager. Si ves un error relacionado con `permission denied` en `entrypoint.sh`, significa que el Paso 2 falló. Repite la operación con el Programador de tareas asegurándote de usar el usuario `root`.

### Error de conexión / CORS en el navegador (Redirige a localhost o test)
Si usaste previamente esta carpeta en otros entornos, el archivo `.env` podría contener configuraciones incorrectas. Nuestro script soluciona esto automáticamente estableciendo `APP_URL=http://localhost:8889`. 
Sin embargo, dado que estás en un NAS, es posible que desees establecer la IP real de tu NAS.
1. Desde File Station, abre la carpeta `kondomanager-free`
2. Edita el archivo `.env` usando el editor de texto del NAS
3. Cambia `APP_URL=http://localhost:8889` a `APP_URL=http://192.168.x.x:8889` (usa la IP de tu NAS).
4. Reinicia el proyecto desde Container Manager.

### Errores de permisos de escritura
Si recibes errores como `The stream or file "/var/www/storage/logs/laravel.log" could not be opened`, el contenedor no tiene permisos de escritura en la carpeta compartida.
Desde el terminal o vía Programador de tareas ejecuta:
```bash
chmod -R 777 /volume1/docker/kondomanager-free/storage
chmod -R 777 /volume1/docker/kondomanager-free/bootstrap/cache
```

---

## Exponer KondoManager a Internet (Reverse Proxy de Synology)

Si deseas acceder a KondoManager desde el exterior (ej. `https://gestion.midominio.com`) usando certificados SSL válidos, el mejor método es utilizar el Reverse Proxy integrado en DSM.

1. Ve a **Panel de control** -> **Portal de inicio de sesión** -> **Avanzado** -> **Proxy inverso** (Reverse Proxy).
2. Haz clic en **Crear**.
3. Configura las reglas:
   - **Origen:**
     - Protocolo: `HTTPS`
     - Nombre de host: `gestion.midominio.com` (o el dominio que hayas elegido)
     - Puerto: `443`
   - **Destino:**
     - Protocolo: `HTTP`
     - Nombre de host: `localhost`
     - Puerto: `8889` (o el configurado en Container Manager)
4. (Opcional) En la pestaña **Encabezados personalizados** (Custom Headers), haz clic en *Crear* -> *WebSocket* para permitir que el proxy pase correctamente las conexiones en tiempo real de Laravel.
5. Haz clic en **Guardar**.

**ADVERTENCIA: Actualiza tu archivo `.env`**
Después de configurar el proxy inverso, debes indicarle a KondoManager que genere enlaces (CSS, JS, imágenes) usando tu nuevo dominio seguro, de lo contrario el frontend intentará cargar archivos desde `http://localhost` bloqueando todo.

1. Usa File Station o el editor de texto de Synology para abrir el archivo `docker/kondomanager-free/.env`.
2. Busca la línea `APP_URL=`
3. Cámbiala insertando tu dominio EXACTO (incluyendo https):
   ```env
   APP_URL=https://gestion.midominio.com
   ```
4. Si deseas que los logs de seguridad registren la dirección IP real de los usuarios (en lugar de la IP interna del NAS), busca la configuración del proxy en el archivo `.env` y establécela así:
   ```env
   TRUSTED_PROXIES=*
   ```
5. Reinicia el proyecto desde Container Manager para aplicar los cambios. Nuestro script de inicio inteligente reconocerá que has establecido un dominio personalizado y no lo sobrescribirá.
