# 💾 Synology NAS Installation (Container Manager)

KondoManager can be easily hosted on your Synology NAS using **Container Manager** (formerly known as Docker). 
This guide uses the **Standard** stack (Nginx + PHP-FPM + Supervisor for background processes), which is the most reliable solution.

## Prerequisites
1. A Synology NAS compatible with **Container Manager** (usually "Plus" models like DS220+, DS923+, etc.).
2. **Container Manager** installed via the Package Center.
3. Access to shared folders (make sure you have a `docker` folder created on your NAS).

---

## Step 1 — Get the project files

You have two options: using the web interface (File Station) or using SSH.

### Option A: Via File Station (Easiest, no command line)
1. Download the KondoManager ZIP file from GitHub: [Download v1.8.0beta](https://github.com/vince844/kondomanager-free/archive/refs/heads/v1.8.0beta.zip).
2. Open **File Station** on your Synology.
3. Navigate to the `docker` shared folder.
4. Create a new subfolder called `kondomanager-free`.
5. Upload the ZIP file into this folder and extract it (right-click -> Extract Here).
6. Ensure all files (including `docker-compose.yml`) are directly inside `docker/kondomanager-free/` (and not in a further nested subfolder).

### Option B: Via SSH (For advanced users)
1. Enable SSH from the Synology Control Panel (Terminal & SNMP).
2. Login to the NAS via terminal (`ssh youruser@nas-ip`).
3. Run:
   ```bash
   cd /volume1/docker
   git clone -b v1.8.0beta https://github.com/vince844/kondomanager-free.git
   ```

---

## Step 2 — Execution permissions (Crucial!)

To allow Docker to start KondoManager, the startup files must have execution permissions. This is where many users get stuck with a `permission denied` error.

If you are connected via **SSH**, simply run:
```bash
cd /volume1/docker/kondomanager-free
chmod +x docker/standard/entrypoint.sh
chmod +x docker/standard/worker-entrypoint.sh
```

**If you don't want to use SSH**, you can use the Synology Task Scheduler:
1. Go to **Control Panel** -> **Task Scheduler**.
2. Create -> **Scheduled Task** -> **User-defined script**.
3. General: Name "KondoManager Permissions", User: `root`.
4. Task Settings: Enter this code:
   ```bash
   chmod +x /volume1/docker/kondomanager-free/docker/standard/entrypoint.sh
   chmod +x /volume1/docker/kondomanager-free/docker/standard/worker-entrypoint.sh
   ```
5. Click OK.
6. Select the newly created task and click **Run**. Once executed, you can delete it.

---

## Step 3 — Create the Project in Container Manager

1. Open **Container Manager** on your Synology.
2. Go to the **Project** tab on the left.
3. Click **Create**.
4. Fill in the fields:
   * **Project name:** `kondomanager`
   * **Path:** Select the `docker/kondomanager-free` folder
   * **Source:** Select "Use existing docker-compose.yml"
5. Click **Next**.
6. (Optional) On the next screen, if you want to change ports to avoid conflicts with other services on your NAS, you can edit the YAML file directly from the interface. By default, KondoManager will use port `8889`.
7. Click **Next** and then **Done** (make sure the "Start project once created" checkbox is selected).

Container Manager will start downloading images and building the project. **This process will take about 3-5 minutes**.

---

## Step 4 — Check status and background processes

In Container Manager, click on the newly created `kondomanager` project to view its 4 containers:
- `kondo_app` (The core Laravel app)
- `kondo_nginx` (The web server)
- `kondo_db` (The MySQL database)
- `kondo_worker` (Supervisor handling background processes)

### How to access:
1. Open your browser and go to `http://YOUR-NAS-IP:8889`
2. Login with default credentials:
   - Email: `admin@km.com`
   - Password: `password`

### Worker Interface (Supervisor):
To ensure background processes are working correctly (background emails, automatic invoicing, etc.):
1. Go to `http://YOUR-NAS-IP:9001`
2. Enter user `admin` and password `password`.
3. You will see the `laravel-worker` process RUNNING.

---

## Synology Troubleshooting

### The `kondo_app` container keeps stopping
Check the logs from Container Manager. If you see an error related to `permission denied` on `entrypoint.sh`, it means Step 2 failed. Repeat the operation with the Task Scheduler making sure to use the `root` user.

### Connection / CORS error in browser (Redirects to localhost or test)
If you previously used this folder in other environments, the `.env` file might contain incorrect configurations. Our script automatically fixes this by setting `APP_URL=http://localhost:8889`. 
However, since you are on a NAS, you might want to set the actual IP of your NAS.
1. From File Station, open the `kondomanager-free` folder
2. Edit the `.env` file using the NAS text editor
3. Change `APP_URL=http://localhost:8889` to `APP_URL=http://192.168.x.x:8889` (use your NAS IP).
4. Restart the project from Container Manager.

### Write permission errors
If you get errors like `The stream or file "/var/www/storage/logs/laravel.log" could not be opened`, the container doesn't have write permissions to the shared folder.
From the terminal or via Task Scheduler run:
```bash
chmod -R 777 /volume1/docker/kondomanager-free/storage
chmod -R 777 /volume1/docker/kondomanager-free/bootstrap/cache
```

---

## Exposing KondoManager to the Internet (Synology Reverse Proxy)

If you wish to access KondoManager from the outside (e.g., `https://management.mydomain.com`) using valid SSL certificates, the best method is to use the built-in Reverse Proxy in DSM.

1. Go to **Control Panel** -> **Login Portal** -> **Advanced** -> **Reverse Proxy**.
2. Click **Create**.
3. Configure the rules:
   - **Source:**
     - Protocol: `HTTPS`
     - Hostname: `management.mydomain.com` (or your chosen domain)
     - Port: `443`
   - **Destination:**
     - Protocol: `HTTP`
     - Hostname: `localhost`
     - Port: `8889` (or the one configured in Container Manager)
4. (Optional) In the **Custom Headers** tab, click *Create* -> *WebSocket* to allow the proxy to correctly pass Laravel's real-time connections.
5. Click **Save**.

**WARNING: Update your `.env` file**
After configuring the reverse proxy, you must tell KondoManager to generate links (CSS, JS, images) using your new secure domain, otherwise the frontend will try to load files from `http://localhost` blocking everything.

1. Use File Station or the Synology text editor to open the `docker/kondomanager-free/.env` file.
2. Find the line `APP_URL=`
3. Change it by inserting your EXACT domain (including https):
   ```env
   APP_URL=https://management.mydomain.com
   ```
4. If you want security logs to record the real IP address of users (instead of the NAS internal IP), find the proxy setting in the `.env` file and set it like this:
   ```env
   TRUSTED_PROXIES=*
   ```
5. Restart the project from Container Manager to apply the changes. Our smart startup script will recognize that you have set a custom domain and will not overwrite it.
