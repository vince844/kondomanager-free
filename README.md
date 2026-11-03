[![Read in English](https://img.shields.io/badge/Read_in-English-red.svg)](README.en.md)
[![Leggi in Italiano](https://img.shields.io/badge/Leggi_in-Italiano-green.svg)](README.md)
[![Leia em Português](https://img.shields.io/badge/Leia_em-Português-yellow.svg)](README.pt-br.md)
[![Generic badge](https://img.shields.io/badge/Version-1.11.0-blue.svg)](https://github.com/vince844/kondomanager-free/releases)
[![License](https://img.shields.io/badge/License-AGPL_3.0-blue.svg)](https://opensource.org/licenses/AGPL-3.0)
[![Forum](https://img.shields.io/badge/Forum-Comunità-orange.svg)](https://kondomanager.short.gy/km-forum)
[![GitHub stars](https://img.shields.io/github/stars/vince844/kondomanager-free?style=social)](https://github.com/vince844/kondomanager-free/stargazers)
[![GitHub forks](https://img.shields.io/github/forks/vince844/kondomanager-free?style=social)](https://github.com/vince844/kondomanager-free/network/members)
[![GitHub issues](https://img.shields.io/github/issues/vince844/kondomanager-free)](https://github.com/vince844/kondomanager-free/issues)
[![GitHub last commit](https://img.shields.io/github/last-commit/vince844/kondomanager-free)](https://github.com/vince844/kondomanager-free/commits/main)

# KondoManager - Software gratuito e open source per la gestione condominiale

**KondoManager** è il primo software open source e self-hosted per la gestione condominiale, **sviluppato in Laravel, Vue 3, Inertia.js e MySQL**. Progettato per amministratori di condominio che vogliono uno strumento serio: contabilità in partita doppia, piani rate, portale digitale per i condòmini e aggiornamenti automatici — senza abbonamenti, senza vendor lock-in.

---

## Screenshots

<table>
  <tr>
    <td><img src="https://dev.karibusana.org/github/Screenshot-3-new.png" alt="Dashboard" width="100%"></td>
    <td><img src="https://dev.karibusana.org/github/Screenshot-2-new.png" alt="Segnalazioni guasto" width="100%"></td>
  </tr>
  <tr>
    <td><img src="https://dev.karibusana.org/github/Screenshot-1-new.png" alt="Bacheca condominio" width="100%"></td>
    <td><img src="https://dev.karibusana.org/github/Screenshot-6-new.png" alt="Archivio documenti" width="100%"></td>
  </tr>
  <tr>
    <td><img src="https://dev.karibusana.org/github/Screenshot-4-new.png" alt="Agenda del condominio" width="100%"></td>
    <td><img src="https://dev.karibusana.org/github/Screenshot-5-new.png" alt="Gestione utenti e permessi" width="100%"></td>
  </tr>
</table>

---

## Prova la demo

Puoi visualizzare una demo del progetto andando al seguente indirizzo:

👉 **[KondoManager demo](https://kondomanager.short.gy/kondo)**

**Attenzione:** Per questioni di sicurezza alcune funzionalità quali l'invio delle email e notifiche sono state disattivate.

**Credenziali di accesso:**

| Ruolo | Email | Password |
| :--- | :--- | :--- |
| **Amministratore** | `admin@kondomanager.it` | `Pa$$w0rd!` |
| **Utente** | `user@kondomanager.it` | `Pa$$w0rd!` |

---

## Funzionalità del gestionale

### Funzioni core

- Sistema di aggiornamento automatico da pannello amministratore
- Gestione anagrafiche condomini e fornitori del condominio
- Gestione segnalazioni guasti con modulo chat/commenti integrato
- Bacheca condominiale digitale per le comunicazioni
- Archivio documenti e categorie del condominio
- Agenda scadenze con gestione ricorrenze
- Gestione avanzata utenti, ruoli e permessi
- Notifiche email automatiche
- Login con protezione a due fattori
- Sistema di inviti per la registrazione utenti
- Lingua dell'installazione a scelta fra italiano, inglese, portoghese e spagnolo; le pagine del gestionale sono in gran parte solo in italiano
- Importazione di un condominio intero da un altro gestionale (prima sorgente: Danea Domustudio) o da un modello Excel da compilare a mano
- Backup completo e ripristino dal pannello, riprendibili e cifrabili in AES-256
- Documenti dell'archivio in una o più categorie, anche quelli caricati dal condòmino nel portale
- Ricerca del codice ATECO 2025 dell'ISTAT nella scheda fornitore, e categorie dei fornitori gestite dall'elenco dei fornitori
- Immagine Docker di produzione (`ghcr.io/vince844/kondomanager-core`), che a ogni avvio installa su un database vuoto e allinea quello che c'è già

### Passaggi di proprietà, locazioni e successioni

- «Registra passaggio» dalla pagina dei titolari dell'unità, con cinque tipi: vendita o donazione, inizio locazione, fine locazione, usufrutto (costituzione o estinzione) e successione
- Sulla gestione ordinaria la quota dell'unità si divide sui giorni di titolarità; sulla straordinaria conta la delibera
- Il pannello «Cosa cambierà» mostra che cosa succede prima di registrare; dallo storico dell'unità si annulla l'ultimo passaggio
- Nella vendita le rate in bozza che scadono dal giorno del passaggio passano a chi compra; per le rate già emesse il conguaglio si scrive, oppure si lascia alle parti
- Successione con la parte del defunto divisa fra gli eredi per quota, legato compreso; vendita con l'usufrutto riservato a chi vende
- Prospetto degli oneri accessori in PDF per ogni conduttore, con chi rimborsa chi

### Modulo contabilità gestionale e struttura

- Gestione palazzine, scale e immobili
- Conti correnti del condominio
- Tabelle millesimali illimitate con ripartizione multi-tabella e coefficienti configurabili
- Gestione esercizi contabili
- Gestioni ordinarie e straordinarie
- Creazione piano dei conti con separazione preventivo deliberato e sopravvenienze
- Generazione piano rateale anche con ricorrenze avanzate e snapshot temporale
- Piani rate straordinari autorizzati da una delibera assembleare o da un intervento d'urgenza (Art. 1135 c.c.)
- Registrazione fatture passive multi-riga con addebito diretto su singola unità immobiliare (Art. 63 c.c.)
- Gestione sopravvenienze passive e spese fuori preventivo (Art. 1130-bis c.c.)
- Gestione debiti pregressi e fatture di esercizi precedenti con allarme prescrizione (Art. 2948 c.c.)
- Gestione sforamenti di budget con tre strategie: conguaglio, rata integrativa o Fondo di Riserva
- Motore note di credito e storni con validatore di quadratura centesimale (Write-Then-Reverse)
- Fondi di riserva vincolati con audit trail e sblocco in deroga documentato
- Registrazione incassi con ripartizione automatica o manuale
- Smart Wallet condòmino con compensazione crediti pregressi
- Emissione rate silenziosa con pubblicazione differita
- Partita doppia con motore Penny-Perfect in centesimi interi
- Estratto conto dell'anagrafica con ordinamento a cascata
- Financial X-Ray: spaccato finanziario per condòmino con dettaglio per unità immobiliare
- Dashboard Audit con semaforo contabile e rilevamento voci orfane
- Smart inbox intelligente per scadenze in agenda interattive
- Modulo pagamento fatture fornitori con bonifici cumulativi, storno e Ledger immutabile
- Widget Treasury Guardian per l'analisi predittiva della liquidità a 30 giorni
- Motore a cascata dinamico (Inquilino → Usufruttuario → Proprietario)
- Gestione intelligente scoperti documentati per unità immobiliari vuote
- Libro giornale sfogliabile con ricerca, filtri per periodo e stato, e verifica del pareggio
- Stato patrimoniale con diagnosi in linguaggio corrente degli sbilanci e correzione sul posto
- Giroconti fra conti correnti e fondi, con protocollo, anteprima e storno
- Ritenute d'acconto e modello F24: scadenzario per soglia, delega e chiusura in partita doppia
- Contributi già versati dai condòmini scomputati dal riparto, con distribuzione per millesimi
- Registrazione rapida delle piccole spese senza aprire una partita fornitore
- Consuntivo accanto al preventivo su ogni voce, con il dettaglio dei movimenti che lo compongono
- Data di prima scadenza configurabile sul piano rate
- Registrazione delle fatture passive dal file XML o dalla busta firmata .p7m dello SdI, anche molte insieme, con il fornitore creato dal file se non c'è
- Note di credito collegate alla fattura che rettificano: da lì la fattura vale al netto della nota
- Incassi con «Versato da», quando il bonifico l'ha fatto un'altra persona del condominio, e rimborso del credito dall'estratto conto
- Voci di entrata, come l'affitto dell'antenna, che riducono la spesa da ripartire
- Prima nota con il saldo progressivo, e mastrino di ogni conto con il riporto e la contropartita di ogni riga
- Dettaglio del riparto registrato alla generazione del piano rate, voce per voce e persona per persona

### Suite documentale e Stampe PDF

- Motore di rendering 100% nativo in formato PDF senza dipendenze esterne
- Personalizzazione dei documenti con l'inserimento della firma dell'amministratore e note legali a piè di pagina
- **Distinta Preventivo / Piano dei Conti:** stampa completa di capitoli, sottoconti e importi preventivati
- **Ripartizione Spese:** documento che illustra la ripartizione del piano dei conti sulle tabelle millesimali
- **Riparto Bilancio per Tabella (Piano Rate):** documento dettagliato (A4 orizzontale) che incrocia unità immobiliari, soggetti (proprietario, inquilino, ecc.) e tabelle millesimali
- **Prospetto Rate e Scadenziari:** con aggregazione dinamica per Condòmino o per Unità Immobiliare
- **Distinta Pagamento Fornitore:** riepilogo importi con causali e dettagli per bonifici parlanti
- **Riparto per Capitolo di Spesa:** la lettura per voce di bilancio, affiancata al riparto per tabella
- **Estratto Conto del Condòmino:** saldi e sequenza completa dei movimenti dell'esercizio
- **Modello F24 e prospetto per l'home banking:** con l'elenco dei pagamenti che ogni delega versa
- **Prospetto degli oneri accessori:** per ogni conduttore, le voci a suo carico divise sui giorni di conduzione, e chi rimborsa chi
- **Prima nota, libro giornale e libro mastro:** i registri contabili, con il mastrino di ogni conto

---

## Requisiti minimi

Per installare KondoManager, il tuo ambiente server deve soddisfare i seguenti requisiti:

- **PHP** >= 8.4.1 — *dalla versione 1.10.0; le precedenti giravano su PHP 8.2*
- **Database:** MySQL 5.7+ o MariaDB 10.3+
- **Estensioni PHP:** `zip`, `curl`, `bcmath`, `fileinfo`, `gd`, `intl`, `mbstring`, `openssl`, `dom`, `xml` — `gd` serve a generare i PDF: senza, il programma si installa ma non produce nessuna stampa
- **Per installazione manuale:** Node.js & NPM, Composer

---

## Documentazione ufficiale

La documentazione completa di KondoManager è disponibile all'indirizzo:

**[www.kondomanager.com/docs](https://kondomanager.com/docs)**

Alcune guide per cominciare:

- **Installazione:** [le quattro strade](https://kondomanager.com/docs/installation.html), [installazione guidata](https://kondomanager.com/docs/installation-wizard.html), [installazione manuale](https://kondomanager.com/docs/installation-manual.html), [Docker per lo sviluppo](https://kondomanager.com/docs/installation-docker.html), [proxy e HTTPS](https://kondomanager.com/docs/configurazione-proxy-https.html), [email SMTP](https://kondomanager.com/docs/configurazione-email-smtp.html), [backup e ripristino](https://kondomanager.com/docs/backup.html)
- **Primi passi:** [esercizi e gestioni](https://kondomanager.com/docs/esercizi-e-gestioni-condominio.html), [importare i dati da un altro gestionale](https://kondomanager.com/docs/importare-dati-altro-gestionale.html), [tabelle millesimali](https://kondomanager.com/docs/tabelle-millesimali-condominio.html), [piano dei conti](https://kondomanager.com/docs/piano-dei-conti-condominio.html), [ruoli e permessi](https://kondomanager.com/docs/roles.html)
- **Rate e incassi:** [creare un piano rate](https://kondomanager.com/docs/creare-piano-rate-condominio.html), [scadenze delle rate](https://kondomanager.com/docs/quando-scadono-rate-condominio.html), [registrare un incasso](https://kondomanager.com/docs/registrare-incasso-rate-condominio.html), [subentro: vendita e cambio inquilino](https://kondomanager.com/docs/subentro-condominio-vendita-cambio-inquilino.html)
- **Fatture e ritenute:** [registrare una fattura passiva](https://kondomanager.com/docs/registrare-fattura-passiva-condominio.html), [importare una fattura dal file XML](https://kondomanager.com/docs/importare-fattura-xml-condominio.html), [pagare il fornitore](https://kondomanager.com/docs/pagare-fornitore-condominio.html), [ritenuta d'acconto](https://kondomanager.com/docs/ritenuta-acconto-condominio.html), [versare le ritenute con l'F24](https://kondomanager.com/docs/versare-ritenute-f24-condominio.html)
- **Registri:** [prima nota](https://kondomanager.com/docs/prima-nota-condominio.html), [libro giornale](https://kondomanager.com/docs/libro-giornale-condominio.html), [mastrino di un conto](https://kondomanager.com/docs/mastrino-conto-condominio.html), [stato patrimoniale](https://kondomanager.com/docs/stato-patrimoniale-non-quadra.html), [tutte le stampe](https://kondomanager.com/docs/stampe-condominio.html)

---

## Installazione guidata (Consigliata per utenti meno esperti)

Per gli utenti meno esperti o per installazioni veloci su hosting condivisi (cPanel, Plesk, ecc.), abbiamo creato un wizard automatizzato.

### 1. Nuova installazione guidata

1. Scarica il [file di installazione](https://kondomanager.short.gy/km-installer) dal sito ufficiale di Kondomanager
2. Estrai e carica il file `index.php` nella **root** del tuo server (via FTP o File Manager su cPanel).
3. Apri il browser all'indirizzo: `https://tuosito.com/index.php`.
4. Segui la procedura guidata a schermo.

Per maggiori dettagli, visita la [guida ufficiale all'installazione](https://kondomanager.com/docs/installation.html) oppure il nostro [canale YouTube](https://www.youtube.com/@Kondomanager).

### 2. Aggiornamento automatico da pannello amministratore

Il sistema di aggiornamento automatico porta l'installazione alla versione nuova dal pannello di amministrazione, in due passaggi: «Scarica e Installa» mette i file nuovi, poi «Avvia aggiornamento» aggiorna il database, con la copia di sicurezza del database proposta prima delle migrazioni, se si parte dalla 1.10.0 o da una versione successiva.

**Attenzione:** Se non configuri i processi `CronJob`, l'aggiornamento automatico non funzionerà.

### Configurazione CronJob

**Opzione A — Da pannello di controllo (consigliata per hosting condivisi)**

A partire dalla v1.9.0 puoi configurare i processi pianificati direttamente dal pannello di amministrazione di KondoManager, senza accedere al server. Il sistema è compatibile con servizi esterni come [cron-job.org](https://cron-job.org), utile su hosting condivisi privi di cron nativo. Trovi la configurazione in **Impostazioni → Automazione cloud**.

**Opzione B — Da pannello hosting (cPanel / Plesk)**

Accedi al tuo pannello hosting nella sezione "Cron Jobs" o "Pianificazione Attività" e imposta l'esecuzione ogni minuto (`* * * * *`).

**Esempio per ambiente locale ServBay** (da KondoManager 1.10.0 il riferimento è PHP 8.4, non
supportato dalla versione gratuita di MAMP — se stai migrando da MAMP, segui la
[guida di migrazione a ServBay](https://kondomanager.com/docs/migrazione-mamp-a-servbay.html)):

*macOS — crontab (`crontab -e`):*
```bash
/Applications/ServBay/script/alias/php tuacartella/artisan schedule:run >> /dev/null 2>&1
```

*Windows — Utilità di pianificazione (Windows non ha crontab):* apri il Prompt dei comandi,
verifica il percorso PHP effettivo con `where php`, poi registra l'attività ogni minuto:
```bash
schtasks /create /tn "KondoManager Scheduler" /tr "php C:\tuacartella\artisan schedule:run" /sc minute /mo 1
```

**Esempio per server condiviso (cPanel / Linux):**
```bash
/usr/local/bin/php /home/tuosito/public_html/artisan schedule:run >> /dev/null 2>&1
```

Assicurati di usare il percorso assoluto all'eseguibile PHP 8.4+, ad esempio `/usr/local/bin/ea-php84`. Cerca in MultiPHP Manager la versione PHP effettivamente assegnata al dominio.

---

### Configurazione email (SMTP / Sendmail)

A partire dalla v1.9.0 le credenziali email si configurano direttamente dal pannello di amministrazione, senza modificare il file `.env`.

Trovi la configurazione in **Impostazioni → Configurazione email**.

| Campo | Descrizione |
| :--- | :--- |
| **Driver** | `SMTP` per provider esterni (Gmail, Mailgun, ecc.) oppure `Sendmail` per il mailer PHP locale dell'hosting |
| **Host** | Indirizzo del server SMTP (es. `smtp.gmail.com`) |
| **Porta** | Tipicamente `587` (TLS) o `465` (SSL) |
| **Utente** | Indirizzo email o username del provider |
| **Password** | Password o App Password del provider |
| **Cifratura** | `tls` oppure `ssl` |
| **Mittente** | Nome e indirizzo che appariranno come mittente nelle email ai condòmini |

La configurazione salvata dal pannello ha **precedenza** sul file `.env` e può essere modificata in qualsiasi momento senza riavviare il server.

**Nota sul metodo di invio:** il gestionale consiglia `SMTP` con un server esterno (Gmail, Brevo, ecc.); `Sendmail` è solo per VPS o server dedicati con Postfix e i record SPF e DKIM configurati.

---

### 3. Aggiornamento manuale (con il file di installazione)

I passaggi non dipendono dalla versione di partenza: sono gli stessi per qualunque aggiornamento manuale dalla 1.8.0 in avanti.

1. Assicurati di avere un backup del `database` e dei file della cartella `storage`
2. Scarica il [file di aggiornamento](https://kondomanager.short.gy/km-installer) dal sito ufficiale di Kondomanager
3. Estrai e carica il file `index.php` nella root del tuo server
4. Apri il browser all'indirizzo: `https://tuosito.com/index.php`
5. Il sistema rileverà automaticamente la versione precedente installata.
6. Clicca su **"Aggiorna ora"** e segui i passaggi guidati.

**Cosa fa il sistema automaticamente:**

- Backup automatico del file `.env`
- Scaricamento e installazione dei nuovi file core
- Ripristino dei dati e delle configurazioni
- Esecuzione delle migrazioni del database
- Pulizia e ottimizzazione cache

**Importante:** Non chiudere la pagina del browser durante il processo di aggiornamento. Al termine il file `index.php` che hai caricato si cancella da solo, per sicurezza; se non ci riesce, resta un file che risponde «410 Gone» e puoi eliminarlo a mano. Se l'aggiornamento si interrompe con un errore, il file `index.php` può restare sul server: eliminalo a mano.

**Se in passato hai lanciato `php artisan optimize` sul tuo server**, dopo l'aggiornamento lancia anche:

```bash
php artisan optimize:clear
```

L'aggiornatore svuota la cache della configurazione ma non quella delle rotte: senza questo passaggio il programma continua a servire la tabella delle rotte della versione precedente, e le voci nuove rispondono «pagina non trovata». Se non hai mai lanciato `optimize`, non devi fare nulla.


---

## Installazione manuale (Per sviluppatori e utenti esperti)

Se desideri contribuire al codice o hai accesso SSH completo al server.

### Prima installazione su un server

`.env.example` porta già i valori per un server: `APP_ENV=production` e `APP_DEBUG=false`. Non accendere il debug su un'installazione raggiungibile da altri: le pagine d'errore mostrerebbero il codice, le query e il corpo delle richieste.

1. **Clona la repository**
```bash
git clone https://github.com/vince844/kondomanager-free.git
cd kondomanager-free
```

2. **Installa le dipendenze e compila l'interfaccia**
```bash
composer install --no-dev --optimize-autoloader
npm install
npm run build
```

3. **Configura l'ambiente**
```bash
cp .env.example .env
php artisan key:generate --force
```

Modifica il file `.env` inserendo l'indirizzo dell'installazione (`APP_URL`) e i parametri del tuo database (`DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`).

4. **Installa, con il tuo amministratore**
```bash
php artisan kondomanager:installa --admin-nome="Mario Rossi" --admin-email=mario@esempio.it --admin-password="una-password-di-almeno-10-caratteri"
```

Il comando crea le tabelle, i ruoli, l'amministratore con i dati che gli passi, i dati iniziali e le impostazioni; su un'installazione già fatta non fa nulla. I dati dell'amministratore si possono passare anche con le variabili `INSTALL_ADMIN_NAME`, `INSTALL_ADMIN_EMAIL` e `INSTALL_ADMIN_PASSWORD` (vedi `.env.example`), così la password non resta nella cronologia del terminale.

5. **Pubblica e avvia i processi in background**

Il server web deve servire la cartella `public/`. Configura il CronJob (vedi [Configurazione CronJob](#configurazione-cronjob)) e il worker delle code (vedi il punto 3 dell'aggiornamento manuale qui sotto).

### Prima installazione per sviluppare in locale

1. **Clona la repository e installa le dipendenze**
```bash
git clone https://github.com/vince844/kondomanager-free.git
cd kondomanager-free
composer install
npm install
```

2. **Configura l'ambiente di sviluppo**
```bash
cp .env.example .env
php artisan key:generate
```

Nel file `.env` metti `APP_ENV=local` e `APP_DEBUG=true`, e i parametri del tuo database (`DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`).

3. **Setup Database**
```bash
php artisan migrate
php artisan db:seed
```

4. **Avvia**
```bash
npm run dev
php artisan serve
```

Visita http://localhost:8000.

**Credenziali di sviluppo:** `admin@km.com` / `password`, solo con `APP_ENV=local`. Con un altro ambiente `db:seed` crea l'amministratore con una password casuale e la stampa una volta sola. Per lo sviluppo con Docker vedi [docs/docker_local_dev.it.md](docs/docker_local_dev.it.md): dopo un `git pull`, `docker compose up -d --build` ricompila l'immagine, e a ogni avvio il contenitore fa le migrazioni e allinea ruoli e permessi, l'elenco dei Comuni, la classificazione ATECO e la versione registrata.

---

### Aggiornamento manuale (via SSH / Terminale)

Se preferisci aggiornare manualmente, segui rigorosamente questi passaggi per garantire la compatibilità con il sistema di versioning:

> ⚠️ **Se stai salendo dalla 1.9.x alla 1.10.0, il `composer install` non è facoltativo.** La 1.10 porta Livewire dalla versione 3 alla 4: finché le dipendenze restano quelle vecchie, ogni comando `php artisan` si ferma con `Attribute [livewire] does not exist`. Il database non viene toccato — l'errore arriva prima delle migrazioni — e si esce completando l'aggiornamento delle dipendenze. Per questo i comandi qui sotto sono **una catena sola**.

> ⚠️ **Se stai salendo dalla 1.10.0 alla 1.11.0**, la catena usa `php artisan kondomanager:aggiorna` al posto di `php artisan migrate --force`: con le sole migrazioni resterebbero indietro il permesso nuovo «Importa dati» e i codici della classificazione ATECO. Dopo l'aggiornamento controlla nel `.env` che `APP_URL` sia l'indirizzo con cui apri il gestionale, con `https://` se il gestionale è in https: lo usano i link delle mail del reset della password e della verifica dell'email. Se il gestionale sta dietro un proxy, per `TRUSTED_PROXIES` il valore consigliato è `PRIVATE_SUBNETS`, che però non copre un proxy con un indirizzo pubblico: per quello si scrive l'elenco dei suoi indirizzi; `.env.example` sconsiglia `*`.

1. **Backup database (Raccomandato)**
```bash
mysqldump -u username -p database_name > backup_$(date +%Y%m%d).sql
```

2. **Aggiornamento, in un comando solo**

I passaggi sono legati da `&&`: se composer o npm falliscono — capita, per un download interrotto o per memoria insufficiente sul server — la catena si ferma lì e **le migrazioni non partono**. La `config:clear` prima di `kondomanager:aggiorna` è necessaria perché le migrazioni delle impostazioni leggono la configurazione. `kondomanager:aggiorna` fa le migrazioni e allinea ruoli e permessi, l'elenco dei Comuni, la classificazione ATECO e la versione registrata, come l'aggiornamento dal pannello.

```bash
git pull origin main \
 && composer install --no-dev --optimize-autoloader \
 && npm install \
 && npm run build \
 && php artisan config:clear \
 && php artisan kondomanager:aggiorna \
 && php artisan optimize:clear \
 && php artisan storage:link \
 && php artisan queue:restart \
 && echo "OK - aggiornamento completato"
```

Se non compare `OK - aggiornamento completato`, l'aggiornamento non è finito: leggi l'ultimo messaggio, risolvi il problema e rilancia la stessa catena — è rieseguibile. `queue:restart` fa ripartire con il codice nuovo il worker delle code tenuto attivo da Supervisor (punto 3); senza un worker non fa niente. Ruoli e permessi, elenco dei Comuni e classificazione ATECO, se non riescono, lo scrivono nel registro (`storage/logs`) senza fermare la catena: se dopo l'aggiornamento manca un permesso o un codice ATECO, guarda lì e rilancia `php artisan kondomanager:aggiorna`.

3. **Configurazione e avvio delle code (Queues)**

Il sistema utilizza di default il driver database (puoi anche utilizzare Redis se preferisci) per gestire i processi in background. È necessario avviare il worker per processare le attività in coda.
```bash
php artisan queue:work
```
**Nota:** In ambiente di produzione, si consiglia di configurare Supervisor per mantenere il processo attivo.

### Verifica versione installata

Puoi verificare la versione corrente e il funzionamento delle configurazioni tramite Tinker:
```bash
php artisan tinker
>>> config('app.version')
```

---

## Documenti utili

- [Laravel Documentation](https://laravel.com/docs)
- [Vue.js Documentation](https://vuejs.org/guide/introduction.html)
- [Tailwind CSS Documentation](https://tailwindcss.com/docs)
- [Inertia.js Documentation](https://inertiajs.com/)
- [Spatie Laravel Settings](https://spatie.be/docs/laravel-settings)

---

## Community e supporto

Hai domande sull'installazione, vuoi condividere la tua esperienza o cercare aiuto da altri utenti? Unisciti alla community ufficiale di KondoManager:

**[Forum ufficiale KondoManager](https://kondomanager.short.gy/km-forum)**

Il forum è il posto giusto per:
- chiedere supporto tecnico e consigli di configurazione
- segnalare problemi e confrontarsi con altri amministratori di condominio
- proporre nuove funzionalità e discutere la roadmap
- condividere configurazioni, integrazioni e best practice

---

## Come contribuire

Chi volesse contribuire a far crescere il progetto è sempre il benvenuto!

Per poter contribuire, si consiglia di seguire le indicazioni descritte nella [guida per chi contribuisce](https://github.com/vince844/kondomanager-free/blob/main/CONTRIBUTING.md). Alla tua prima pull request ti verrà chiesto di firmare il [Contributor License Agreement](https://cla-assistant.io/vince844/kondomanager-free): lo propone in automatico CLA assistant, e si firma una volta sola.
Se volete contribuire attivamente con semplici migliorie o correzioni potete [cercare tra le issues](https://github.com/vince844/kondomanager-free/issues) aperte oppure apri un nuovo argomento sul [Forum ufficiale KondoManager](https://kondomanager.short.gy/km-forum)

---

## Sostieni il progetto

Sviluppare un software open source richiede molto impegno e dedizione. Ti sarò grato se deciderai di sostenere il progetto.

[Sostieni KondoManager su Patreon](https://www.patreon.com/KondoManager)

---

## Feedback & Supporto

- **Community:** Unisciti al [forum ufficiale](https://kondomanager.short.gy/km-forum) per confrontarti con altri utenti e ricevere supporto dalla community.
- **Bug e richieste:** Usa la sezione ["Issues" o "Discussions"](https://github.com/vince844/kondomanager-free/issues) di questa repository.
- **Vulnerabilità di sicurezza:** Non aprire una issue pubblica; segnalale in privato come spiegato in [SECURITY.md](https://github.com/vince844/kondomanager-free/blob/main/SECURITY.md).
- **Supporto dedicato:** Per richieste di personalizzazione o supporto professionale, usa il [modulo contatti](https://dev.karibusana.org/gestionale-condominio-contatti.html) sul sito ufficiale.

---

## Licenza

Questo progetto è rilasciato sotto licenza [AGPL-3.0](https://github.com/vince844/kondomanager-free?tab=AGPL-3.0-1-ov-file#readme).

---

## Crediti

### Lead Developer:
- [Vincenzo Vecchio](https://github.com/vince844) - Project founder and main developer

### Contributors:

[![Contributori di KondoManager](https://contrib.rocks/image?repo=vince844/kondomanager-free)](https://github.com/vince844/kondomanager-free/graphs/contributors)

### Stelle su GitHub:
Grazie a tutte le persone che hanno messo una stella al progetto!

[![Chi ha messo una stella a KondoManager](https://kondomanager.com/assets/img/stargazers.svg)](https://github.com/vince844/kondomanager-free/stargazers)

### Sostenitori Patreon:
- **[Mittelcom](https://www.amministrazionitedaldimorea.it/)** — grazie per il tuo supporto e per credere nel progetto! 

---
