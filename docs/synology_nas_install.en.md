# 💾 Synology NAS Installation (Container Manager)

<!-- verifica-documentazione -->
> **Status:** Matches the code — **rewritten on 04/11/2026 against 1.12.0-beta.1, and not yet tried on a Synology NAS.** It describes the `ghcr.io/vince844/kondomanager-core` image of 1.11.0, the same one as the site's [Docker (production) guide](https://kondomanager.com/docs/installation-docker-produzione.html) (written in Italian), which was tried on Docker Desktop (macOS, under emulation) and not on a NAS. The DSM interface steps are written from how Synology documents Container Manager (DSM 7.2) and have not been verified on a real device. The previous version of this guide had you build the code from source with the development stack (debug on, test passwords, ports open on the network): it has been replaced.
<!-- /verifica-documentazione -->

> ⚠️ **This guide has not yet been tried on a Synology NAS.** The files and commands come from the production image, which was tried elsewhere; the DSM interface steps were not. The first time, do it in a new folder, without touching the shared folders you already have: the «Removing the trial» section, at the end, says how to take everything away.

KondoManager runs on a Synology NAS as a ready-made Docker image (`ghcr.io/vince844/kondomanager-core`): you do not download the code and you compile nothing. In **Container Manager** you create a project from two files, a `docker-compose.yml` and a `.env`, and the container does the rest: on its first start it installs itself, and at every start it brings the database up to date.

## What you need

1. A Synology NAS with **DSM 7.2 or later** and **Container Manager**, installed from the Package Center. Before 7.2 the package is called «Docker»: this guide is not written for that.
2. A model with an **x86-64** processor (Intel or AMD). The image is built only for `linux/amd64` (checked on the registry on 04/11/2026): on models with an ARM processor Docker can emulate it, but we have not tried it and we do not recommend it. The processor of your model is in the specifications on Synology's site.
3. Free memory for MySQL and PHP together. We have not measured it, so there is no number: before you start, check how much is free on the NAS.
4. A computer, to prepare two text files and to create the key and the passwords with `openssl` (macOS and Linux; on Windows the Git terminal or WSL).
5. Only if you want to open KondoManager from outside your home, over https: a domain name, and DSM's reverse proxy (last section).

## Before you start: where the files go, and what is not touched

- The two files live in **a new folder, all for KondoManager**: the `kondomanager` subfolder of the `docker` shared folder, the one Container Manager uses for its projects (it usually creates it when you install it; if it is not there, create a new shared folder just for this). Do not use a folder that holds your documents. The `.env` file contains the passwords: check that the `docker` folder is not shared with other users.
- **Before you create the folder, check that the name is free.** In File Station there must not already be a `docker/kondomanager` folder, and in Container Manager, **Project** tab, there must not already be a `kondomanager` project: the previous version of this guide created one with this name, in the `docker/kondomanager-free` folder. Docker recognises a project by its name, and treats two projects with the same name as one: the commands in this guide would also act on the other one's containers. If the name is taken, use `kondomanager-prova` for the folder and for the project, and read it in place of `kondomanager` in the paths, the project name and the volume names throughout the guide.
- **No shared folder you already have is connected to the container.** The database and the uploaded documents live in two *volumes* that Docker creates on its own, usually under `@docker` (Docker's area on the NAS volume), and not in a shared folder visible from File Station.
- **No `chmod` and no permission change is needed**: the container prepares its own folders. If you read elsewhere about giving `777` permissions to a shared folder, it is not necessary for this guide: do not do it.
- When the trial is over you take everything away, leaving nothing behind: see «Removing the trial».

---

## Step 1 — The two files

On a computer, create a `kondomanager` folder with two files in it.

### `docker-compose.yml`

It is the same file as in the site's Docker (production) guide:

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

- `app` is KondoManager: it answers on port 8080 of the NAS. If 8080 is already used by another service, change the number on the left (for example `"8090:80"`) and use it in the addresses in this guide too. When you have https through DSM's reverse proxy, write `"127.0.0.1:8080:80"`: that way the port answers only from the NAS itself and not from the network.
- `platform: linux/amd64` tells Docker that the image exists only for x86-64.
- `db` is the database, MySQL 8.4 (the version we tried). **It has no `ports:`**: it cannot be reached from the network, only from KondoManager.
- `storage` and `database` are the two volumes: the documents, the backups and the data live there, and they survive when the containers are recreated.
- **There is no `volumes:` line pointing to a folder of the NAS**: this is intentional.

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

The empty lines are the key and the passwords: **you write them yourself**, and none of them is on this page. While they stay empty the container does not install. `APP_URL` is the address you use to open KondoManager: for the first trial, `http://` plus your NAS's IP address and `:8080` (an example here). Write your own name and email for the administrator.

The file is named exactly `.env`, with the dot in front and no other extension (some Windows editors add `.txt`).

## Step 2 — The key and the passwords

On the computer, in the terminal:

```bash
echo "base64:$(openssl rand -base64 32)"
openssl rand -hex 16
```

- The first command prints a line that starts with `base64:`: paste it whole into `APP_KEY`.
- The second prints a 32-character password: run it twice, once for `DB_PASSWORD` and once for `DB_ROOT_PASSWORD`. It is fine for `INSTALL_ADMIN_PASSWORD` too; if you choose your own, make it at least 10 characters and without the `$` character (Docker Compose reads it as the start of a variable).

**Keep the key and the `.env` file.** Without `APP_KEY` the container does not start and says so in the log; with a key different from the one used at the first start, encrypted data can no longer be read. Keep a copy of the `.env` file off the NAS.

If you have SSH access to the NAS and no `openssl` on your computer, the key can also be created with the image itself: `sudo docker run --rm --entrypoint php ghcr.io/vince844/kondomanager-core:1.11.0 artisan key:generate --show`.

## Step 3 — Put the files on the NAS and create the project

The button names are those of Container Manager on DSM 7.2 as Synology documents it: **they have not been verified on a NAS**.

1. Open **File Station**, go to the `docker` shared folder, create the `kondomanager` folder and upload the two files into it, `docker-compose.yml` and `.env`. If File Station does not show the `.env`, it is a hidden file (it starts with a dot): turn on the display of hidden files in File Station's settings.
2. Open **Container Manager** and go to the **Project** tab, then **Create**.
3. Fill in the fields:
   * **Project name:** `kondomanager`, the same name as the folder: the SSH commands in this guide find the project from the name of the folder that holds its `docker-compose.yml`
   * **Path:** the `docker/kondomanager` folder
   * **Source:** «Use existing docker-compose.yml»
4. **Next.** If Container Manager offers to set up a web portal (Web Station), do not turn it on: DSM's reverse proxy is the last section of this guide.
5. **Next** and then **Done**, with the «Start project once created» box ticked.

Container Manager downloads the two images (`kondomanager-core` and `mysql:8.4`) and starts the two containers. How long this takes on your NAS we do not know: we have not measured it.

If you prefer the terminal and have SSH access, `sudo docker compose -f /volume1/docker/kondomanager/docker-compose.yml up -d` is enough. The `docker` shared folder usually sits on `/volume1`; if you created it on another volume of the NAS, the path starts with that one (`/volume2/…`). The SSH commands in this guide carry the full path of the file: that way they act on this project from whatever folder you run them.

## Step 4 — Check that it has started

1. In Container Manager, in the `kondomanager` project, open the log of the `app` container. At every start the container checks the key, prepares the folders, waits for the database, and at the first start installs KondoManager with the administrator from your `.env`: in the log you find, among other lines, «KondoManager installato. Accedi con mario@esempio.it.» (in Italian, with your email).
2. From the browser of a computer on your network open `http://NAS-IP:8080/up`: it answers with `"stato":"ok"`, `"documenti":"su_volume"` and the version. The detail appears because you are calling directly, from a private network and without a proxy; through a proxy it answers only with `"stato"`.
3. Open `http://NAS-IP:8080` and log in with the email and password you wrote in `INSTALL_ADMIN_EMAIL` and `INSTALL_ADMIN_PASSWORD`. The container reads them only on an empty database, at the first start: after your first login you can remove the password from the `.env` and change it in the application.
4. **There is no Supervisor interface to open.** The production image keeps the web server, PHP, the queue worker and the scheduler in the same container, and opens no port to control them: the status is given by the log.

## If something goes wrong

**The container keeps restarting, and the log says «APP_KEY manca».**
The key did not reach the container: write it as in Step 2 in the `.env` file, in the same folder as the `docker-compose.yml`.

**«invalid reference format» appears and the project does not start.**
The `.env` was not read and the image's version number (`KONDOMANAGER_VERSIONE`) stayed empty: the image would be called `ghcr.io/vince844/kondomanager-core:`, with no version. Check that the file is named `.env` and sits next to the `docker-compose.yml`.

**The log of the project says «The "APP_KEY" variable is not set» (or the same for another variable).**
The `.env` was not read. Docker Compose writes these warnings in the log of the *project*, not in that of the `app` container. Check that it is named `.env` and sits next to the `docker-compose.yml`. If Container Manager still does not read it, write the values directly in the `docker-compose.yml` in place of the `${...}`.

**«Mancano i dati dell'amministratore: nessuna scrittura» (the administrator's details are missing).**
The database is empty and the administrator's details are missing or not valid: you need a valid email in `INSTALL_ADMIN_EMAIL`, a password of at least 10 characters in `INSTALL_ADMIN_PASSWORD` and, if you write it, a name of at least 2. The lines before say which. The container has written nothing: fix the `.env` and recreate the project.

**«Il database non risponde: nessuna scrittura» (the database does not answer).**
The database did not answer within 120 seconds. Check the log of the `db` container.

**`/up` answers 503 and the log says «storage/app NON è su un volume separato».**
The `storage` volume is missing in the `docker-compose.yml`: put it back as in Step 1. Without it, documents and backups would disappear the first time the container is recreated.

**Port 8080 is already in use.**
Change the number on the left in the `ports:` line (for example `"8090:80"`) and recreate the project.

**The warning «The requested image's platform (linux/amd64) does not match the detected host platform».**
Your NAS has an ARM processor: the image is only for x86-64. See «What you need».

## Exposing KondoManager on the Internet (DSM reverse proxy)

To open KondoManager from outside your home, for example `https://gestionale.mydomain.com`, with a valid certificate, the best method is the reverse proxy built into DSM. DSM manages the certificate.

1. Go to **Control Panel** → **Login Portal** → **Advanced** → **Reverse Proxy**, then **Create**.
2. Set up the rule:
   - **Source:** protocol `HTTPS`, host name `gestionale.mydomain.com` (or the domain you chose), port `443`.
   - **Destination:** protocol `HTTP`, host name `127.0.0.1` (not `localhost`: with `"127.0.0.1:8080:80"` the port answers only on that address), port `8080` (or the one you chose in the compose file).
3. Save.
4. In the `.env` file write in `APP_URL` your **exact** domain, https included (`APP_URL=https://gestionale.mydomain.com`): it is the address KondoManager puts in its links and loads its pages from. Then recreate the project, because a simple restart does not re-read the `.env`: with SSH, `sudo docker compose -f /volume1/docker/kondomanager/docker-compose.yml up -d`; in Container Manager it is the project action that rebuilds the containers (the name of the button is not verified).
5. **`TRUSTED_PROXIES`: do not write anything.** In the image it is already `PRIVATE_SUBNETS`, which trusts a proxy connecting from a private network, Docker's networks included: DSM's proxy connects from the NAS itself. **Never use `*`**: with `*` whoever comes from outside chooses their own address, and the limit on login attempts stops working. The other cases are in the site's [Proxy and HTTPS](https://kondomanager.com/docs/configurazione-proxy-https.html) page (in Italian).
6. When https works, write `"127.0.0.1:8080:80"` in the `docker-compose.yml`, so the http port no longer answers from your home network, and recreate the project.

## Updating

Inside the container the update from the panel is turned off: updating means using the image of a new version.

1. From the program (its interface is in Italian): **Impostazioni → Gestione backups**, «Crea backup», and download it.
2. In the `.env` file write the new version in `KONDOMANAGER_VERSIONE`.
3. Download the new image and recreate KondoManager's container. With SSH:
   ```bash
   sudo docker compose -f /volume1/docker/kondomanager/docker-compose.yml pull app
   sudo docker compose -f /volume1/docker/kondomanager/docker-compose.yml up -d
   ```
   `pull app` downloads only KondoManager's image: the database stays as it is (a `pull` with no name would also download a new MySQL). Which Container Manager button does the same thing is not verified.

At start the container applies the migrations and brings permissions, municipalities, ATECO and the version up to date, before serving a single page.

## The backup and the volumes

- The program's backup (**Impostazioni → Gestione backups**) contains the database and the documents and sits in `storage/app/backups`, that is, in the `storage` volume: you download it from the interface. How it works is in the site's [Backup and restore](https://kondomanager.com/docs/backup.html) guide (in Italian). Restoring inside the container we have not tried yet.
- The `.env` file is not in the archive: in the container the configuration lives outside, next to the compose file. Keep a copy of the `.env`, with its `APP_KEY`, together with the archives.
- An archive that stays on the NAS does not protect you if you lose the NAS: download it and keep it elsewhere.
- The `storage` and `database` volumes are not in a shared folder. We have not verified whether the NAS's copy programs (for example Hyper Backup) include them: do not count on it, and download the program's archives.

## If you already installed with the previous version of this guide

The previous version had you build the code from source, in a `kondomanager-free` folder, with the development stack. That stack leaves **on the NAS's network** the database port and the port of the background-process interface, with the test credentials the guide itself published, an administrator with the password written in the guide, and debug on. Anyone on your network can try them.

Until you move to this guide, at least these three steps, which **we have not tried on a NAS**:

1. From the program, change the password of the administrator the old guide had you create.
2. In the `docker-compose.yml` of that folder remove the `ports:` block of the `db` and `worker` services, that is the `ports:` line and the one below it (the program stays reachable from the `nginx` service's port), and recreate the project.
3. In the `.env` of the same folder write `APP_DEBUG=false` and recreate the project.

Then, as soon as you can, make a backup from the program, download it and move to this guide. Moving the data into the new container is not tried. The previous version's project is also called `kondomanager`: for this guide use the other name, `kondomanager-prova`, as «Before you start» says.

## Removing the trial

Everything this guide creates lives in one folder and two volumes, and comes off without touching anything else. It deletes none of the shared folders you had.

1. **The project and the trial's data.** With SSH, first `sudo docker compose ls -a`: next to the `kondomanager` project the file `/volume1/docker/kondomanager/docker-compose.yml` must appear. Then `sudo docker compose -f /volume1/docker/kondomanager/docker-compose.yml down -v` stops and removes the containers and **deletes the two volumes**, that is, the trial's database and documents; it does not touch the other projects' volumes, because the path of the file tells Docker which project you mean. A `down -v` without `-f`, run from another project's folder, would delete that project's volumes. Without SSH: in Container Manager, **Project** tab, `kondomanager`, **Stop** and then **Delete**; the two volumes (`kondomanager_database` and `kondomanager_storage`) however stay until you delete them, and they take up space on the NAS volume but touch no shared folder.
2. **The images.** In Container Manager, **Image**: delete `ghcr.io/vince844/kondomanager-core` and `mysql:8.4`, the latter only if no other project uses it.
3. **The files.** In File Station delete the `docker/kondomanager` folder: it contains only the two files from this guide.
4. **The proxy, if you created it.** **Control Panel** → **Login Portal** → **Advanced** → **Reverse Proxy**: delete KondoManager's rule.

## What this guide does not cover

We have not tried it, so we do not promise it: the Container Manager steps on a real NAS, DSM before 7.2, models with an ARM processor, how much memory it needs and how long the first start takes, the address KondoManager sees behind DSM's proxy, restoring a backup inside the container, and moving an installation you already have (documents included): in that case, a backup first.
