# 💾 Installazione su Synology NAS (Container Manager)

<!-- verifica-documentazione -->
> **Stato:** Descrive il codice — **riscritta il 04/11/2026 su 1.12.0-beta.1, e non ancora provata su un NAS Synology.** Descrive l'immagine `ghcr.io/vince844/kondomanager-core` della 1.11.0, la stessa della [guida Docker (produzione)](https://kondomanager.com/docs/installation-docker-produzione.html) del sito, che è stata provata su Docker Desktop (macOS, in emulazione) e non su un NAS. I passi dell'interfaccia di DSM sono scritti da come Synology documenta Container Manager (DSM 7.2) e non sono stati verificati su un apparecchio vero. La versione precedente di questa guida faceva costruire il codice dai sorgenti con la stack di sviluppo (debug acceso, password di prova, porte aperte sulla rete): è stata sostituita.
<!-- /verifica-documentazione -->

> ⚠️ **Questa guida non è ancora stata provata su un NAS Synology.** I file e i comandi vengono dall'immagine di produzione, provata altrove; i passi dell'interfaccia di DSM no. La prima volta falla in una cartella nuova, senza toccare le cartelle condivise che hai già: la sezione «Per togliere la prova», in fondo, dice come levare tutto.

KondoManager gira su un NAS Synology come immagine Docker già pronta (`ghcr.io/vince844/kondomanager-core`): non si scarica il codice e non si compila niente. In **Container Manager** si crea un progetto da due file, un `docker-compose.yml` e un `.env`, e il contenitore fa il resto: al primo avvio si installa da solo, a ogni avvio allinea il database.

## Che cosa serve

1. Un NAS Synology con **DSM 7.2 o successivo** e **Container Manager**, che si installa dal Centro pacchetti. Prima della 7.2 il pacchetto si chiama «Docker»: questa guida non è scritta per quello.
2. Un modello con processore **x86-64** (Intel o AMD). L'immagine è costruita solo per `linux/amd64` (verificato sul registro il 04/11/2026): sui modelli con processore ARM Docker può emularla, ma non l'abbiamo provato e non lo consigliamo. Il processore del tuo modello è nelle specifiche, sul sito di Synology.
3. Memoria libera per MySQL e PHP insieme. Non l'abbiamo misurata, quindi non c'è un numero: prima di cominciare controlla quanta ne resta libera sul NAS.
4. Un computer, per preparare due file di testo e per creare la chiave e le password con `openssl` (macOS e Linux; su Windows il terminale di Git o WSL).
5. Solo se vuoi aprire KondoManager da fuori casa, in https: un nome di dominio, e il reverse proxy di DSM (ultima sezione).

## Prima di cominciare: dove vanno i file, e che cosa non viene toccato

- I due file stanno in **una cartella nuova, tutta per KondoManager**: la sottocartella `kondomanager` della cartella condivisa `docker`, quella che Container Manager usa per i suoi progetti (di solito la crea lui quando lo installi; se non c'è, crea una nuova cartella condivisa solo per questo). Non usare una cartella che contiene i tuoi documenti. Il file `.env` contiene le password: controlla che la cartella `docker` non sia condivisa con altri utenti.
- **Prima di creare la cartella, controlla che il nome sia libero.** In File Station non deve esserci già una cartella `docker/kondomanager`, e in Container Manager, scheda **Progetto**, non deve esserci già un progetto `kondomanager`: la versione precedente di questa guida lo creava con questo nome, nella cartella `docker/kondomanager-free`. Docker riconosce un progetto dal suo nome, e due progetti con lo stesso nome li tratta come uno solo: i comandi di questa guida agirebbero anche sui contenitori dell'altro. Se il nome è già preso, usa `kondomanager-prova` per la cartella e per il progetto, e leggilo al posto di `kondomanager` nei percorsi, nel nome del progetto e nei nomi dei volumi di tutta la guida.
- **Nessuna cartella condivisa che hai già viene collegata al contenitore.** Il database e i documenti caricati stanno in due *volumi* che Docker crea per conto suo, di solito sotto `@docker` (l'area di Docker sul volume del NAS), e non in una cartella condivisa visibile da File Station.
- **Non serve nessun `chmod` e nessun permesso da cambiare**: il contenitore prepara da solo le sue cartelle. Se altrove leggi di dare i permessi `777` a una cartella condivisa, per questa guida non è necessario: non farlo.
- A prova finita si toglie tutto, senza lasciare niente: vedi «Per togliere la prova».

---

## Passo 1 — I due file

Su un computer crea una cartella `kondomanager` e dentro due file.

### `docker-compose.yml`

È lo stesso file della guida Docker (produzione) del sito:

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

- `app` è KondoManager: risponde sulla porta 8080 del NAS. Se la 8080 è già usata da un altro servizio, cambia il numero a sinistra (per esempio `"8090:80"`) e usalo anche negli indirizzi di questa guida. Quando avrai l'https con il reverse proxy di DSM, scrivi `"127.0.0.1:8080:80"`: così la porta risponde solo dal NAS stesso e non dalla rete.
- `platform: linux/amd64` dice a Docker che l'immagine esiste solo per x86-64.
- `db` è il database, MySQL 8.4 (la versione provata). **Non ha `ports:`**: non si raggiunge dalla rete, solo da KondoManager.
- `storage` e `database` sono i due volumi: lì stanno i documenti, i backup e i dati, e sopravvivono quando i contenitori vengono ricreati.
- **Non c'è nessuna riga `volumes:` che punti a una cartella del NAS**: è voluto.

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

Le righe vuote sono la chiave e le password: **le scrivi tu**, e nessuna sta in questa pagina. Finché restano vuote il contenitore non si installa. `APP_URL` è l'indirizzo con cui apri KondoManager: per la prima prova, `http://` più l'indirizzo IP del tuo NAS e `:8080` (qui un esempio). Il nome e l'email dell'amministratore, scrivi i tuoi.

Il nome del file è esattamente `.env`, con il punto davanti e nessuna altra estensione (alcuni editor di Windows aggiungono `.txt`).

## Passo 2 — La chiave e le password

Sul computer, nel terminale:

```bash
echo "base64:$(openssl rand -base64 32)"
openssl rand -hex 16
```

- Il primo comando stampa una riga che comincia con `base64:`: incollala intera in `APP_KEY`.
- Il secondo stampa una password di 32 caratteri: lancialo due volte, una per `DB_PASSWORD` e una per `DB_ROOT_PASSWORD`. Va bene anche per `INSTALL_ADMIN_PASSWORD`; se ne scegli una tu, che sia di almeno 10 caratteri e senza il carattere `$` (Docker Compose lo legge come l'inizio di una variabile).

**Conserva la chiave e il file `.env`.** Senza `APP_KEY` il contenitore non parte e lo dice nel registro; con una chiave diversa da quella del primo avvio i dati cifrati non si leggono più. Tieni una copia del file `.env` fuori dal NAS.

Se hai l'accesso SSH al NAS e non hai `openssl` sul computer, la chiave si crea anche con l'immagine stessa: `sudo docker run --rm --entrypoint php ghcr.io/vince844/kondomanager-core:1.11.0 artisan key:generate --show`.

## Passo 3 — Mettere i file sul NAS e creare il progetto

I nomi dei pulsanti sono quelli di Container Manager su DSM 7.2 come li documenta Synology: **non sono stati verificati su un NAS**.

1. Apri **File Station**, vai nella cartella condivisa `docker`, crea la cartella `kondomanager` e caricaci dentro i due file, `docker-compose.yml` e `.env`. Se File Station non mostra il `.env`, è un file nascosto (comincia con il punto): attiva la visualizzazione dei file nascosti nelle impostazioni di File Station.
2. Apri **Container Manager** e vai nella scheda **Progetto**, poi **Crea**.
3. Compila i campi:
   * **Nome progetto:** `kondomanager`, lo stesso nome della cartella: i comandi da SSH di questa guida trovano il progetto dal nome della cartella del suo `docker-compose.yml`
   * **Percorso:** la cartella `docker/kondomanager`
   * **Sorgente:** «Usa docker-compose.yml esistente»
4. **Avanti.** Se Container Manager propone di impostare un portale web (Web Station), non attivarlo: il reverse proxy di DSM è l'ultima sezione di questa guida.
5. **Avanti** e poi **Fatto**, con la spunta «Avvia il progetto una volta creato» selezionata.

Container Manager scarica le due immagini (`kondomanager-core` e `mysql:8.4`) e avvia i due contenitori. Quanto ci mette sul tuo NAS non lo sappiamo: non l'abbiamo misurato.

Se preferisci il terminale e hai l'accesso SSH, basta `sudo docker compose -f /volume1/docker/kondomanager/docker-compose.yml up -d`. La cartella condivisa `docker` sta di solito su `/volume1`; se l'hai creata su un altro volume del NAS, il percorso comincia con quello (`/volume2/…`). I comandi da SSH di questa guida portano il percorso del file per intero: così agiscono su questo progetto da qualunque cartella li lanci.

## Passo 4 — Controllare che sia partito

1. In Container Manager, nel progetto `kondomanager`, apri il registro del contenitore `app`. A ogni avvio il contenitore controlla la chiave, prepara le cartelle, aspetta il database, e al primo avvio installa KondoManager con l'amministratore del tuo `.env`: nel registro trovi, fra le altre righe, «KondoManager installato. Accedi con mario@esempio.it.» (con la tua email).
2. Dal browser di un computer della tua rete apri `http://IP-DEL-NAS:8080/up`: risponde con `"stato":"ok"`, `"documenti":"su_volume"` e la versione. Il dettaglio compare perché chiami direttamente, da una rete privata e senza proxy; attraverso un proxy risponde solo con `"stato"`.
3. Apri `http://IP-DEL-NAS:8080` ed entra con l'email e la password che hai scritto in `INSTALL_ADMIN_EMAIL` e `INSTALL_ADMIN_PASSWORD`. Il contenitore le legge solo su un database vuoto, al primo avvio: dopo il primo accesso puoi togliere la password dal `.env` e la cambi dall'applicazione.
4. **Non c'è nessuna interfaccia di Supervisor da aprire.** L'immagine di produzione tiene nello stesso contenitore il server web, PHP, il lavoratore delle code e lo scheduler, e non apre nessuna porta per controllarli: lo stato lo dà il registro.

## Se qualcosa non va

**Il contenitore si riavvia di continuo, e il registro dice «APP_KEY manca».**
La chiave non è arrivata al contenitore: scrivila come nel Passo 2 nel file `.env`, nella stessa cartella del `docker-compose.yml`.

**Compare «invalid reference format» e il progetto non parte.**
Il `.env` non è stato letto e il numero della versione dell'immagine (`KONDOMANAGER_VERSIONE`) è rimasto vuoto: l'immagine si chiamerebbe `ghcr.io/vince844/kondomanager-core:`, senza versione. Controlla che il file si chiami `.env` e stia accanto al `docker-compose.yml`.

**Il registro del progetto dice «The "APP_KEY" variable is not set» (o lo stesso per un'altra variabile).**
Il `.env` non è stato letto. Questi avvisi li scrive Docker Compose nel registro del *progetto*, non in quello del contenitore `app`. Controlla che si chiami `.env` e che stia accanto al `docker-compose.yml`. Se Container Manager non lo legge lo stesso, scrivi i valori direttamente nel `docker-compose.yml` al posto dei `${...}`.

**«Mancano i dati dell'amministratore: nessuna scrittura».**
Il database è vuoto e i dati dell'amministratore mancano o non vanno bene: serve un'email valida in `INSTALL_ADMIN_EMAIL`, una password di almeno 10 caratteri in `INSTALL_ADMIN_PASSWORD` e, se lo scrivi, un nome di almeno 2. Le righe prima dicono quale. Il contenitore non ha scritto niente: correggi il `.env` e ricrea il progetto.

**«Il database non risponde: nessuna scrittura».**
Il database non ha risposto entro 120 secondi. Controlla il registro del contenitore `db`.

**`/up` risponde 503 e il registro dice «storage/app NON è su un volume separato».**
Manca il volume `storage` nel `docker-compose.yml`: rimettilo com'è nel Passo 1. Senza, documenti e backup sparirebbero alla prima ricreazione del contenitore.

**La porta 8080 è già in uso.**
Cambia il numero a sinistra nella riga `ports:` (per esempio `"8090:80"`) e ricrea il progetto.

**L'avviso «The requested image's platform (linux/amd64) does not match the detected host platform».**
Il tuo NAS ha un processore ARM: l'immagine è solo per x86-64. Vedi «Che cosa serve».

## Esporre KondoManager su Internet (reverse proxy di DSM)

Per aprire KondoManager da fuori casa, per esempio `https://gestionale.miodominio.com`, con un certificato valido, il metodo migliore è il reverse proxy integrato in DSM. Il certificato lo gestisce DSM.

1. Vai in **Pannello di controllo** → **Portale di accesso** → **Avanzate** → **Proxy inverso**, poi **Crea**.
2. Configura la regola:
   - **Origine:** protocollo `HTTPS`, nome host `gestionale.miodominio.com` (o il dominio che hai scelto), porta `443`.
   - **Destinazione:** protocollo `HTTP`, nome host `127.0.0.1` (non `localhost`: con `"127.0.0.1:8080:80"` la porta risponde solo su quell'indirizzo), porta `8080` (o quella che hai scelto nel compose).
3. Salva.
4. Nel file `.env` scrivi in `APP_URL` il tuo dominio **esatto**, https compreso (`APP_URL=https://gestionale.miodominio.com`): è l'indirizzo che KondoManager mette nei link e da cui carica le pagine. Poi ricrea il progetto, perché un semplice riavvio non rilegge il `.env`: con SSH, `sudo docker compose -f /volume1/docker/kondomanager/docker-compose.yml up -d`; in Container Manager è l'azione del progetto che ricostruisce i contenitori (il nome del pulsante non è verificato).
5. **`TRUSTED_PROXIES`: non scrivere niente.** Nell'immagine vale già `PRIVATE_SUBNETS`, che si fida di un proxy collegato da una rete privata, comprese le reti di Docker: il proxy di DSM si collega dal NAS stesso. **Non usare mai `*`**: con `*` chi arriva da fuori sceglie il proprio indirizzo, e il blocco dei tentativi di accesso non lavora più. Gli altri casi sono nella pagina [Proxy e HTTPS](https://kondomanager.com/docs/configurazione-proxy-https.html) del sito.
6. Quando l'https funziona, nel `docker-compose.yml` scrivi `"127.0.0.1:8080:80"`, così la porta in http non risponde più dalla rete di casa, e ricrea il progetto.

## Aggiornare

Nel contenitore l'aggiornamento dal pannello è spento: aggiornare vuol dire usare l'immagine di una versione nuova.

1. Dal programma: **Impostazioni → Gestione backups**, «Crea backup», e scaricalo.
2. Nel file `.env` scrivi la versione nuova in `KONDOMANAGER_VERSIONE`.
3. Scarica l'immagine nuova e ricrea il contenitore di KondoManager. Con SSH:
   ```bash
   sudo docker compose -f /volume1/docker/kondomanager/docker-compose.yml pull app
   sudo docker compose -f /volume1/docker/kondomanager/docker-compose.yml up -d
   ```
   `pull app` scarica solo l'immagine di KondoManager: il database resta quello che è (un `pull` senza nome scaricherebbe anche un MySQL nuovo). Quale pulsante di Container Manager faccia la stessa cosa non è verificato.

All'avvio il contenitore applica le migrazioni e allinea permessi, Comuni, ATECO e versione, prima di servire una sola pagina.

## Il backup e i volumi

- Il backup del programma (**Impostazioni → Gestione backups**) contiene il database e i documenti e sta in `storage/app/backups`, cioè nel volume `storage`: si scarica dall'interfaccia. Come funziona è nella guida [Backup e ripristino](https://kondomanager.com/docs/backup.html) del sito. Il ripristino dentro il contenitore non l'abbiamo ancora provato.
- Il file `.env` non è nell'archivio: nel contenitore la configurazione sta fuori, accanto al compose. Tieni una copia del `.env`, con la sua `APP_KEY`, insieme agli archivi.
- Un archivio che resta sul NAS non ti protegge se perdi il NAS: scaricalo e conservalo altrove.
- I volumi `storage` e `database` non stanno in una cartella condivisa. Non abbiamo verificato se i programmi di copia del NAS (per esempio Hyper Backup) li includono: non contarci, e scarica gli archivi del programma.

## Se hai già installato con la versione precedente di questa guida

La versione precedente faceva costruire il codice dai sorgenti, in una cartella `kondomanager-free`, con la stack di sviluppo. Quella stack lascia **sulla rete del NAS** la porta del database e quella dell'interfaccia dei processi in background, con le credenziali di prova che la guida stessa pubblicava, un amministratore con la password scritta nella guida e il debug acceso. Chiunque sia sulla tua rete può provarle.

Finché non passi a questa guida, almeno questi tre passi, che **non abbiamo provato su un NAS**:

1. Dal programma, cambia la password dell'amministratore che la vecchia guida faceva creare.
2. Nel `docker-compose.yml` di quella cartella togli il blocco `ports:` dei servizi `db` e `worker`, cioè la riga `ports:` e quella sotto (il programma resta raggiungibile dalla porta del servizio `nginx`), e ricrea il progetto.
3. Nel `.env` della stessa cartella scrivi `APP_DEBUG=false` e ricrea il progetto.

Poi, appena puoi, fai un backup dal programma, scaricalo e passa a questa guida. Il passaggio dei dati nel contenitore nuovo non è provato. Il progetto della versione precedente si chiama anch'esso `kondomanager`: per questa guida usa l'altro nome, `kondomanager-prova`, come dice «Prima di cominciare».

## Per togliere la prova

Tutto ciò che questa guida crea sta in una cartella e in due volumi, e si toglie senza toccare altro. Non cancella nessuna cartella condivisa che avevi.

1. **Il progetto e i dati della prova.** Con SSH, prima `sudo docker compose ls -a`: accanto al progetto `kondomanager` deve comparire il file `/volume1/docker/kondomanager/docker-compose.yml`. Poi `sudo docker compose -f /volume1/docker/kondomanager/docker-compose.yml down -v` ferma e rimuove i contenitori e **cancella i due volumi**, cioè il database e i documenti della prova; i volumi degli altri progetti no, perché il percorso del file dice a Docker di quale progetto si parla. Un `down -v` senza `-f`, lanciato dalla cartella di un altro progetto, cancellerebbe i volumi di quello. Senza SSH: in Container Manager, scheda **Progetto**, `kondomanager`, **Arresta** e poi **Elimina**; i due volumi (`kondomanager_database` e `kondomanager_storage`) però restano finché non li cancelli, e occupano spazio sul volume del NAS ma non toccano nessuna cartella condivisa.
2. **Le immagini.** In Container Manager, **Immagine**: elimina `ghcr.io/vince844/kondomanager-core` e `mysql:8.4`, quest'ultima solo se nessun altro progetto la usa.
3. **I file.** In File Station elimina la cartella `docker/kondomanager`: contiene soltanto i due file di questa guida.
4. **Il proxy, se l'hai creato.** **Pannello di controllo** → **Portale di accesso** → **Avanzate** → **Proxy inverso**: elimina la regola di KondoManager.

## Che cosa questa guida non copre

Non l'abbiamo provato, quindi non lo promettiamo: i passi di Container Manager su un NAS vero, DSM prima della 7.2, i modelli con processore ARM, quanta memoria serve e quanto tempo richiede il primo avvio, l'indirizzo che KondoManager vede dietro il proxy di DSM, il ripristino di un backup nel contenitore, e il trasloco di un'installazione che hai già (documenti compresi): in quel caso, prima un backup.
