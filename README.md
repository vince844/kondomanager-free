[![Read in English](https://img.shields.io/badge/Read_in-English-red.svg)](README.en.md)
[![Leggi in Italiano](https://img.shields.io/badge/Leggi_in-Italiano-green.svg)](README.md)
[![Leia em Português](https://img.shields.io/badge/Leia_em-Português-yellow.svg)](README.pt-br.md)
[![Generic badge](https://img.shields.io/badge/Version-1.9.0-blue.svg)](https://github.com/vince844/kondomanager-free/releases)
[![License](https://img.shields.io/badge/License-AGPL_3.0-blue.svg)](https://opensource.org/licenses/AGPL-3.0)
[![Forum](https://img.shields.io/badge/Forum-Comunità-orange.svg)](https://kondomanager.short.gy/km-forum)
[![GitHub stars](https://img.shields.io/github/stars/vince844/kondomanager-free?style=social)](https://github.com/vince844/kondomanager-free/stargazers)
[![GitHub forks](https://img.shields.io/github/forks/vince844/kondomanager-free?style=social)](https://github.com/vince844/kondomanager-free/network/members)
[![GitHub issues](https://img.shields.io/github/issues/vince844/kondomanager-free)](https://github.com/vince844/kondomanager-free/issues)
[![GitHub last commit](https://img.shields.io/github/last-commit/vince844/kondomanager-free)](https://github.com/vince844/kondomanager-free/commits/main)

# KondoManager - Software gratuito e open source per la gestione condominiale

**KondoManager** è il primo software open source e self-hosted per la gestione condominiale, **sviluppato in Laravel, Vue 3, Inertia.js e MySQL**. Progettato per amministratori di condominio che vogliono uno strumento serio: contabilità in partita doppia, piani rate con validazione legale, portale digitale per i condòmini e aggiornamenti automatici — senza abbonamenti, senza vendor lock-in.

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

👉 **[KondoManager demo](https://rebrand.ly/kondomanager)**

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
- Gestione segnalazioni guasti del condominio
- Bacheca condominiale digitale per le comunicazioni
- Archivio documenti e categorie del condominio
- Agenda scadenze con gestione ricorrenze
- Gestione avanzata utenti, ruoli e permessi
- Notifiche email automatiche
- Login con protezione a due fattori
- Sistema di inviti per la registrazione utenti
- Localizzazione: Italiano, Inglese, Portoghese

### Modulo contabilità gestionale e struttura

- Gestione palazzine, scale e immobili
- Conti correnti del condominio
- Tabelle millesimali illimitate con ripartizione multi-tabella e coefficienti configurabili
- Gestione esercizi contabili
- Gestioni ordinarie e straordinarie
- Creazione piano dei conti con separazione preventivo deliberato e sopravvenienze
- Generazione piano rateale anche con ricorrenze avanzate e snapshot temporale
- Piani rate straordinari con scudo legale delibera assembleare (Art. 1135 c.c.)
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

---

## Requisiti minimi

Per installare KondoManager, il tuo ambiente server deve soddisfare i seguenti requisiti:

- **PHP** >= 8.2
- **Database:** MySQL 5.7+ o MariaDB 10.3+
- **Estensioni PHP:** `zip`, `curl`, `openssl`, `mbstring`, `fileinfo`, `dom`, `xml` — consulta la guida di [Laravel](https://laravel.com/docs/12.x/deployment) per ulteriori informazioni
- **Per installazione manuale:** Node.js & NPM, Composer

---

## Documentazione ufficiale

La documentazione completa di KondoManager è disponibile all'indirizzo:

**[www.kondomanager.com/docs](https://www.kondomanager.com/docs)**

Trovi guide dettagliate su:

- [Installazione e configurazione iniziale](https://www.kondomanager.com/docs/installation.html)
- Configurazione email e cron job
- Modulo contabilità e piano dei conti
- Gestione piani rate e incassi
- Aggiornamenti e manutenzione

---

## Installazione guidata (Consigliata per utenti meno esperti)

Per gli utenti meno esperti o per installazioni veloci su hosting condivisi (cPanel, Plesk, ecc.), abbiamo creato un wizard automatizzato.

### 1. Nuova installazione guidata

1. Scarica il [file di installazione](https://kondomanager.short.gy/km-installer) dal sito ufficiale di Kondomanager
2. Estrai e carica il file `index.php` nella **root** del tuo server (via FTP o File Manager su cPanel).
3. Apri il browser all'indirizzo: `https://tuosito.com/index.php`.
4. Segui la procedura guidata a schermo.

Per maggiori dettagli, visita la [guida ufficiale all'installazione](https://www.kondomanager.com/docs/installation.html) oppure il nostro [canale YouTube](https://www.youtube.com/@Kondomanager).

### 2. Aggiornamento automatico da pannello amministratore

Il sistema di aggiornamento automatico gestisce automaticamente il ciclo di vita degli aggiornamenti, garantendo la sicurezza dei dati e tutto con pochi click direttamente dal pannello di amministrazione.

**Attenzione:** Se non configuri i processi `CronJob`, l'aggiornamento automatico non funzionerà.

### Configurazione CronJob

**Opzione A — Da pannello di controllo (consigliata per hosting condivisi)**

A partire dalla v1.9.0 puoi configurare i processi pianificati direttamente dal pannello di amministrazione di KondoManager, senza accedere al server. Il sistema è compatibile con servizi esterni come [cron-job.org](https://cron-job.org), utile su hosting condivisi privi di cron nativo. Trovi la configurazione in **Impostazioni → Processi pianificati**.

**Opzione B — Da pannello hosting (cPanel / Plesk)**

Accedi al tuo pannello hosting nella sezione "Cron Jobs" o "Pianificazione Attività" e imposta l'esecuzione ogni minuto (`* * * * *`).

**Esempio per ambiente locale MAMP (Mac):**
```bash
/Applications/MAMP/bin/php/php8.2.0/bin/php tuacartella/artisan schedule:run >> /dev/null 2>&1
```

**Esempio per server condiviso (cPanel / Linux):**
```bash
/usr/local/bin/php /home/tuosito/public_html/artisan schedule:run >> /dev/null 2>&1
```

Assicurati di usare il percorso assoluto all'eseguibile PHP v8.2+, ad esempio `/usr/local/bin/ea-php82`. Cerca in MultiPHP Manager la versione PHP effettivamente assegnata al dominio.

---

### Configurazione email (SMTP / Sendmail)

A partire dalla v1.9.0 le credenziali email si configurano direttamente dal pannello di amministrazione, senza modificare il file `.env`.

Trovi la configurazione in **Impostazioni → Configurazione Email**.

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

**Nota per hosting condivisi (es. Altervista):** se il provider non consente connessioni SMTP esterne, seleziona il driver `Sendmail` per utilizzare il mailer PHP locale, che nella maggior parte dei casi non richiede credenziali aggiuntive.

---

### 3. Aggiornamento manuale dalla versione 1.8.0 alla 1.9.0

1. Assicurati di avere un backup del `database` e dei file della cartella `storage`
2. Scarica il [file di aggiornamento](https://kondomanager.short.gy/km-installer) dal sito ufficiale di Kondomanager
3. Carica il file `index.php` nella root del tuo server
4. Apri il browser all'indirizzo: `https://tuosito.com/index.php`
5. Il sistema rileverà automaticamente la versione precedente installata.
6. Clicca su **"Aggiorna adesso"** e segui i passaggi guidati.

**Cosa fa il sistema automaticamente:**

- Backup automatico del file `.env`
- Scaricamento e installazione dei nuovi file core
- Ripristino dei dati e delle configurazioni
- Esecuzione delle migrazioni del database
- Pulizia e ottimizzazione cache

**Importante:** Non chiudere la pagina del browser durante il processo di aggiornamento. Il file `index.php` si auto-eliminerà al termine dell'operazione per sicurezza.

---

## Installazione manuale (Per sviluppatori e utenti esperti)

Se desideri contribuire al codice o hai accesso SSH completo al server.

### Prima installazione

1. **Clona la repository**
```bash
git clone https://github.com/vince844/kondomanager-free.git
cd kondomanager-free
```

2. **Installa le dipendenze**
```bash
composer install
npm install
```

3. **Configura l'ambiente**
```bash
cp .env.example .env
php artisan key:generate
```

Modifica il file `.env` inserendo i parametri del tuo database (`DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`).

4. **Setup Database**
```bash
php artisan migrate
php artisan db:seed
```

5. **Avvia**
```bash
npm run dev
php artisan serve
```

Visita http://localhost:8000.

**Credenziali default:** `admin@km.com` / `password` (Ricorda di cambiarle subito andando sul tuo profilo `/settings/profile`).

---

### Aggiornamento manuale (via SSH / Terminale)

Se preferisci aggiornare manualmente, segui rigorosamente questi passaggi per garantire la compatibilità con il sistema di versioning:

1. **Backup database (Raccomandato)**
```bash
mysqldump -u username -p database_name > backup_$(date +%Y%m%d).sql
```

2. **Aggiorna codice e dipendenze**
```bash
git pull origin main
composer install --no-dev --optimize-autoloader
npm install && npm run build
```

3. **Passaggio critico — pulizia cache**

È fondamentale pulire la cache delle configurazioni prima di migrare, specialmente per il nuovo sistema di versioning settings:
```bash
php artisan config:clear
```

4. **Migrazione e ottimizzazione**
```bash
php artisan migrate --force
php artisan optimize:clear
php artisan storage:link
```

5. **Configurazione e avvio delle code (Queues)**

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

Per poter contribuire, si consiglia di seguire le indicazioni descritte all'interno della [documentazione ufficiale](https://github.com/vince844/kondomanager-free/blob/main/CONTRIBUTING). 
Se volete contribuire attivamente con semplici migliorie o correzioni potete [cercare tra le issues](https://github.com/vince844/kondomanager-free/issues) aperte oppure apri un nuovo argomento sul [Forum ufficiale KondoManager](https://kondomanager.short.gy/km-forum)

---

## Sostieni il progetto

Sviluppare un software open source richiede molto impegno e dedizione. Ti sarò grato se deciderai di sostenere il progetto.

[Sostieni KondoManager su Patreon](https://www.patreon.com/KondoManager)

---

## Feedback & Supporto

- **Community:** Unisciti al [forum ufficiale](https://kondomanager.short.gy/km-forum) per confrontarti con altri utenti e ricevere supporto dalla community.
- **Bug e richieste:** Usa la sezione ["Issues" o "Discussions"](https://github.com/vince844/kondomanager-free/issues) di questa repository.
- **Supporto dedicato:** Per richieste di personalizzazione o supporto professionale, usa il [modulo contatti](https://dev.karibusana.org/gestionale-condominio-contatti.html) sul sito ufficiale.

---

## Licenza

Questo progetto è rilasciato sotto licenza [AGPL-3.0](https://github.com/vince844/kondomanager-free?tab=AGPL-3.0-1-ov-file#readme).

---

## Crediti

### Lead Developer:
- [Vincenzo Vecchio](https://github.com/vince844) - Project founder and main developer

### Contributors:
- [Amnit Haldar](https://github.com/amit-eiitech) - Per il suo prezioso contributo sulla creazione dell'installazione guidata
- [k3ntinhu](https://github.com/k3ntinhu) - Per il suo prezioso contributo sulla configurazione di Docker container e la comunità portoghese
- [Stefano B](https://github.com/borghiste) - Per aver segnalato e risolto un bug di sicurezza
- Tutti i contributori e sviluppatori della community open source.

### Sostenitori Patreon:
- **[Fabio Lembo Luscari]** — grazie per il tuo supporto e per credere nel progetto! 

---
