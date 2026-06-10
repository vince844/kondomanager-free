# 💻 Sviluppo Locale su Windows — Herd + DBngin + DBeaver

> Questa guida ti permette di avviare KondoManager in locale su **Windows** in pochi passi,
> senza configurare PHP manualmente e senza usare Docker.

---

## Strumenti necessari

| Strumento | Funzione | Download |
|-----------|---------|---------|
| **Laravel Herd** | PHP 8.x + Nginx — zero configurazione | [herd.laravel.com](https://herd.laravel.com/) |
| **DBngin** | Avvia MySQL in un click | [dbngin.com](https://dbngin.com/) |
| **DBeaver** | Client gratuito per creare e gestire il database | [dbeaver.io/download](https://dbeaver.io/download/) |

> ℹ️ Tutti e tre sono **gratuiti**. Herd Pro (a pagamento) aggiunge strumenti extra non necessari per KondoManager.

---

## Passo 1 — Installare Laravel Herd

1. Vai su [herd.laravel.com](https://herd.laravel.com/) e scarica la versione per **Windows**
2. Esegui il file `.exe` e segui l'installazione guidata
3. Al termine, Herd si avvia in background — troverai la sua icona nella barra delle applicazioni (system tray)

**Cosa installa automaticamente:**
- PHP 8.x con tutte le estensioni necessarie per Laravel (inclusa `fileinfo`)
- Nginx integrato
- Composer

> ✅ **Nessuna configurazione PHP richiesta** — Herd gestisce tutto in autonomia.

---

## Passo 2 — Installare DBngin e avviare MySQL

1. Vai su [dbngin.com](https://dbngin.com/) e scarica la versione per **Windows**
2. Installa ed avvia l'applicazione
3. Clicca su **"+"** per creare un nuovo servizio
4. Seleziona **MySQL** → versione **8.0**
5. Clicca **"Create"** — DBngin scarica e avvia MySQL automaticamente

Prendi nota della porta (di default: **3306**).

### 🔄 Alternativa se DBngin non funziona su Windows

Installa MySQL dal sito ufficiale:

1. Vai su [dev.mysql.com/downloads/installer](https://dev.mysql.com/downloads/installer/)
2. Scarica **"MySQL Installer for Windows"** (file `.msi`)
3. Scegli il tipo di installazione **"Server only"**
4. Durante la configurazione:
   - Password root: `root`
   - Porta: `3306`
   - Spunta **"avvia come servizio Windows"** ✅

---

## Passo 3 — Installare DBeaver

1. Vai su [dbeaver.io/download](https://dbeaver.io/download/) e scarica **DBeaver Community** (gratuito)
2. Installa ed avvia l'applicazione
3. Crea una nuova connessione MySQL:
   - Clicca **"New Database Connection"** (icona in alto a sinistra)
   - Seleziona **MySQL**
   - Compila i campi:
     - Host: `localhost`
     - Port: `3306`
     - Username: `root`
     - Password: `root`
   - Clicca **"Test Connection"** — se richiede il driver MySQL, clicca **"Download"**
   - Clicca **"Finish"**

---

## Passo 4 — Creare il database

Con la connessione MySQL aperta in DBeaver:

1. Clicca con il tasto destro sulla connessione → **"Create New Database"**
2. Compila i campi:
   - **Database name**: `kondomanager_dev`
   - **Charset**: `utf8mb4`
   - **Collation**: `utf8mb4_unicode_ci`
3. Clicca **OK**

---

## Passo 5 — Scaricare KondoManager e posizionarlo in Herd

Herd serve automaticamente tutti i progetti nella cartella `Herd` della tua cartella utente (`C:\Users\tuonome\Herd`).

1. Scarica il pacchetto di installazione da:
   👉 **[kondomanager.com/docs/installation-wizard.html](https://kondomanager.com/docs/installation-wizard.html)**
2. Estrai il contenuto ZIP in una cartella dentro `C:\Users\tuonome\Herd\`

> ⚠️ **Il nome della cartella diventa il tuo dominio locale.**
> Herd usa automaticamente il nome della cartella come sottodominio `.test`:
>
> | Nome cartella | URL locale |
> |--------------|------------|
> | `kondomanager` | `http://kondomanager.test` |
> | `kondomanager-free` | `http://kondomanager-free.test` |
> | `kondo` | `http://kondo.test` |
>
> Scegli il nome che preferisce — l'importante è ricordarlo per il passo successivo.

---

## Passo 6 — Avviare l'installazione guidata

Apri il browser e vai su:

```
http://[nome-cartella].test/index.php
```

Esempio: se hai chiamato la cartella `kondomanager`, l'URL sarà `http://kondomanager.test/index.php`

L'installazione guidata ti accompagnerà attraverso:

1. **Verifica requisiti** — controlla che tutte le estensioni PHP siano presenti
2. **Configurazione database** — inserisci i dati della connessione:
   - Host: `127.0.0.1`
   - Porta: `3306`
   - Database: `kondomanager_dev`
   - Utente: `root`
   - Password: `root`
3. **Installazione** — l'installer esegue automaticamente migration e seeder
4. **Completamento** — accedi all'applicazione

---

## Accedere all'applicazione

Una volta completata l'installazione, accedi con:

| Servizio | URL | Credenziali |
|----------|-----|-------------|
| **Applicazione Web** | `http://[nome-cartella].test` | Email: `admin@km.com` / Password: `password` |
| **Database** (DBeaver) | `localhost:3306` | User: `root` / Password: `root` / DB: `kondomanager_dev` |

---

## Risoluzione dei problemi

### La pagina `kondomanager-free.test` non si apre
- Verifica che Herd sia in esecuzione (icona nella barra delle applicazioni)
- Clicca sull'icona Herd → **"Restart"**
- Controlla che il progetto sia nella cartella `C:\Users\tuonome\Herd\`

### L'installer segnala estensioni PHP mancanti
Con Herd questo non dovrebbe accadere. Se compare comunque:
1. Clicca sull'icona Herd nella barra delle applicazioni
2. Vai in **PHP** → verifica che sia attiva la versione **8.2 o superiore**
3. Se necessario, cambia versione da **PHP → Install PHP...**

### Errore di connessione al database nell'installer
- Verifica che DBngin (o MySQL) sia avviato
- Prova a connetterti da DBeaver con gli stessi dati per isolare il problema
- Assicurati di aver creato il database `kondomanager_dev` nel Passo 4

### `Pacchetto non raggiungibile (HTTP 0)` durante l'installazione
Questo errore ha due cause possibili:

**Causa più comune — SSL non configurato (tipico di MAMP e Laragon)**
Con Herd questo problema **non si presenta**: Herd configura automaticamente i certificati SSL per PHP, a differenza di MAMP e Laragon che richiedono una configurazione manuale. Se stavi usando MAMP o Laragon e riscontravi questo errore, passare a Herd lo risolve.

**Causa alternativa — Firewall o rete**
Se il problema persiste anche con Herd, significa che il firewall o l'antivirus sta bloccando le connessioni di PHP verso internet. Prova a disabilitarlo temporaneamente durante l'installazione. Se sei su una rete aziendale o con VPN attiva, disattivala durante il setup.
