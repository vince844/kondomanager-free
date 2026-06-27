# 💾 Installazione su Synology NAS (Container Manager)

KondoManager può essere facilmente ospitato sul tuo NAS Synology sfruttando **Container Manager** (precedentemente noto come Docker). 
Questa guida utilizza lo stack **Standard** (Nginx + PHP-FPM + Supervisor per i processi in background), che è la soluzione più affidabile.

## Prerequisiti
1. Un NAS Synology compatibile con **Container Manager** (solitamente i modelli "Plus" come DS220+, DS923+, ecc.).
2. **Container Manager** installato tramite il "Centro pacchetti" (Package Center).
3. Accesso alle cartelle condivise (assicurati di avere la cartella `docker` creata nel tuo NAS).

---

## Passo 1 — Ottenere i file del progetto

Hai due opzioni: usare l'interfaccia web (File Station) o usare SSH.

### Opzione A: Tramite File Station (Più semplice, niente riga di comando)
1. Scarica il file ZIP di KondoManager da GitHub: [Scarica v1.9.1-beta](https://github.com/vince844/kondomanager-free/archive/refs/heads/v1.9.1-beta.zip).
2. Apri **File Station** nel tuo Synology.
3. Naviga nella cartella condivisa `docker`.
4. Crea una nuova sottocartella chiamata `kondomanager-free`.
5. Carica il file ZIP all'interno di questa cartella ed estrailo (tasto destro -> Estrai qui).
6. Assicurati che tutti i file (incluso `docker-compose.yml`) siano direttamente dentro `docker/kondomanager-free/` (e non in un'ulteriore sottocartella nidificata).

### Opzione B: Tramite SSH (Per utenti avanzati)
1. Abilita SSH dal Pannello di controllo Synology (Terminale e SNMP).
2. Accedi al NAS tramite terminale (`ssh tuoutente@ip-del-nas`).
3. Esegui:
   ```bash
   cd /volume1/docker
   git clone -b v1.9.1-beta https://github.com/vince844/kondomanager-free.git
   ```

---

## Passo 2 — Permessi di esecuzione (Fondamentale!)

Per permettere a Docker di avviare KondoManager, i file di avvio devono avere i permessi di esecuzione. Questo è il passaggio in cui molti utenti si bloccano con l'errore `permission denied`.

Se sei collegato in **SSH**, esegui semplicemente:
```bash
cd /volume1/docker/kondomanager-free
chmod +x docker/standard/entrypoint.sh
chmod +x docker/standard/worker-entrypoint.sh
```

**Se non vuoi usare SSH**, puoi usare l'Utilità di pianificazione (Task Scheduler) del Synology:
1. Vai su **Pannello di controllo** -> **Utilità di pianificazione**.
2. Crea -> **Attività pianificata** -> **Script definito dall'utente**.
3. Generale: Nome "Permessi KondoManager", Utente: `root`.
4. Impostazioni script: Inserisci questo codice:
   ```bash
   chmod +x /volume1/docker/kondomanager-free/docker/standard/entrypoint.sh
   chmod +x /volume1/docker/kondomanager-free/docker/standard/worker-entrypoint.sh
   ```
5. Clicca OK.
6. Seleziona l'attività appena creata e clicca **Esegui**. Una volta eseguita, puoi eliminarla.

---

## Passo 3 — Creare il Progetto in Container Manager

1. Apri **Container Manager** sul tuo Synology.
2. Vai nella scheda **Progetto** (Project) sulla sinistra.
3. Clicca su **Crea**.
4. Compila i campi:
   * **Nome progetto:** `kondomanager`
   * **Percorso:** Seleziona la cartella `docker/kondomanager-free`
   * **Sorgente:** Seleziona "Usa docker-compose.yml esistente"
5. Clicca su **Avanti**.
6. (Opzionale) Nella schermata successiva, se desideri modificare le porte per evitare conflitti con altri servizi sul tuo NAS, puoi modificare il file YAML direttamente dall'interfaccia. Di default KondoManager userà la porta `8889`.
7. Clicca **Avanti** e poi **Fatto** (assicurati che la spunta "Avvia progetto una volta creato" sia selezionata).

Container Manager inizierà a scaricare le immagini e a costruire il progetto. **L'operazione richiederà circa 3-5 minuti**.

---

## Passo 4 — Controllare lo stato e i processi in background

Nel Container Manager, clicca sul progetto `kondomanager` appena creato per visualizzare i 4 container che lo compongono:
- `kondo_app` (Il cuore di Laravel)
- `kondo_nginx` (Il server web)
- `kondo_db` (Il database MySQL)
- `kondo_worker` (Supervisor che gestisce i processi in background)

### Come accedere:
1. Apri il browser e vai a `http://IP-DEL-TUO-NAS:8889`
2. Effettua il login con le credenziali di default:
   - Email: `admin@km.com`
   - Password: `password`

### Interfaccia Worker (Supervisor):
Per assicurarti che i processi in background stiano funzionando correttamente (invio email in background, fatturazione automatica, ecc.):
1. Vai a `http://IP-DEL-TUO-NAS:9001`
2. Inserisci utente `admin` e password `password`.
3. Vedrai il processo `laravel-worker` in esecuzione (RUNNING).

---

## Troubleshooting su Synology

### Il container `kondo_app` si ferma in continuazione
Controlla i log dal Container Manager. Se vedi un errore relativo a `permission denied` su `entrypoint.sh`, significa che il Passo 2 non è andato a buon fine. Ripeti l'operazione con il Task Scheduler assicurandoti di usare l'utente `root`.

### Errore di connessione / CORS nel browser (Reindirizza a localhost o test)
Se avevi usato questa cartella in precedenza in altri ambienti, il file `.env` potrebbe contenere configurazioni errate. Il nostro script corregge in automatico questo problema impostando `APP_URL=http://localhost:8889`. 
Tuttavia, siccome sei su un NAS, potresti voler impostare l'IP reale del tuo NAS.
1. Da File Station, apri la cartella `kondomanager-free`
2. Modifica il file `.env` con l'editor di testo del NAS
3. Cambia `APP_URL=http://localhost:8889` in `APP_URL=http://192.168.x.x:8889` (usa l'IP del tuo NAS).
4. Riavvia il progetto da Container Manager.

### Errore di permessi di scrittura
Se ricevi errori del tipo `The stream or file "/var/www/storage/logs/laravel.log" could not be opened`, il container non ha i permessi di scrittura sulla cartella condivisa.
Dal terminale o tramite Task Scheduler esegui:
```bash
chmod -R 777 /volume1/docker/kondomanager-free/storage
chmod -R 777 /volume1/docker/kondomanager-free/bootstrap/cache
```

---

## Esporre KondoManager su Internet (Synology Reverse Proxy)

Se desideri accedere a KondoManager dall'esterno (es. `https://gestionale.miodominio.com`) usando certificati SSL validi, il metodo migliore è utilizzare il Reverse Proxy integrato in DSM.

1. Vai nel **Pannello di controllo** -> **Portale di accesso** -> **Avanzate** -> **Proxy inverso** (Reverse Proxy).
2. Clicca su **Crea**.
3. Configura le regole:
   - **Origine:**
     - Protocollo: `HTTPS`
     - Nome host: `gestionale.miodominio.com` (o il dominio che hai scelto)
     - Porta: `443`
   - **Destinazione:**
     - Protocollo: `HTTP`
     - Nome host: `localhost`
     - Porta: `8889` (o quella configurata in Container Manager)
4. (Opzionale) Nella scheda **Intestazioni personalizzate** (Custom Headers), clicca su *Crea* -> *WebSocket* per permettere al proxy di far passare correttamente le connessioni real-time di Laravel.
5. Clicca su **Salva**.

**ATTENZIONE: Aggiornare il file `.env`**
Dopo aver configurato il proxy inverso, devi dire a KondoManager di generare i link (CSS, JS, immagini) utilizzando il tuo nuovo dominio sicuro, altrimenti il frontend tenterà di caricare i file su `http://localhost` bloccando tutto.

1. Usa File Station o l'editor di testo di Synology per aprire il file `docker/kondomanager-free/.env`.
2. Trova la riga `APP_URL=`
3. Modificala inserendo il tuo dominio ESATTO (incluso https):
   ```env
   APP_URL=https://gestionale.miodominio.com
   ```
4. Se vuoi che i log di sicurezza registrino l'indirizzo IP reale degli utenti (invece dell'IP interno del NAS), cerca nel file `.env` la voce sui proxy e impostala così:
   ```env
   TRUSTED_PROXIES=*
   ```
5. Riavvia il progetto da Container Manager per applicare le modifiche. Il nostro script di avvio intelligente riconoscerà che hai impostato un dominio personalizzato e non lo sovrascriverà.
