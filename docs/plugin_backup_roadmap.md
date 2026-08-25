# Plugin Backup Pro — Roadmap e punti di aggancio

<!-- verifica-documentazione -->
> **Stato:** Non implementato (intenzione) — verificato il 31/07/2026 su 1.10.0-beta.32
> La sezione "Architettura del core free" descrive fedelmente il codice ed è usabile come riferimento per gli agganci; i punti 1-4 e 8 sono funzioni del plugin mai costruite, e il punto 6 (restore guidato) è stato nel frattempo realizzato nel core free.
<!-- /verifica-documentazione -->

> Documento di lavoro interno (tenuto fuori dai repository pubblici di proposito).
> Il core backup free è stato progettato in beta.11 con punti di estensione precisi:
> questo file elenca cosa il plugin a pagamento dovrà aggiungere e COME agganciarsi,
> così non si dimentica nulla quando inizierà lo sviluppo.
>
> Decisioni di Vincenzo (11/07/2026): "solo database" e "password zip" vanno nel
> FREE in beta.12 (punti 5 e 7); notifiche email nel plugin (punto 3); il plugin
> monetizza scheduling + cloud, non la sicurezza di base.
>
> STATO 14/07/2026: punti 5 e 7 IMPLEMENTATI in beta.12 (cartella test), passati
> per review adversariale multi-agente (15 finding confermati, tutti risolti tranne
> 2 i18n pre-esistenti sotto). Portati all'ufficiale il 15/07/2026 con la release 1.10.0-beta.12 (port completo verificato, suite verde in entrambe le cartelle).
> Residui i18n pre-esistenti (fuori scope, non introdotti da beta.12): lang/es/
> validation.php in gran parte in inglese, e "(and :count more error)" non tradotto —
> mitigati dai guard client-side che mostrano messaggi custom tradotti prima del 422.

## Architettura del core free (riferimento)

Implementata in `kondomanager-free` (beta.11), cartella `app/Services/Backup/`:

- **Step-runner riprendibile**: `BackupManager::runStep()` fa avanzare il backup per
  max `config('backup.step_time_budget')` secondi (default 20s) e salva il checkpoint
  nella colonna json `checkpoint` della tabella `backups`. Chiunque può "pompare" gli
  step: oggi lo fa il polling del browser, domani lo scheduler. Lock anti-sovrapposizione:
  `Cache::lock("backup:step:{uuid}")`.
- **Dump MySQL puro PDO** (`MySqlDumper`): nessun binario, nessun proc_open. SQLite via
  `VACUUM INTO`. Driver scelto dal binding `DatabaseDumperInterface` in `BackupServiceProvider`.
- **Destinazioni**: contratto `App\Contracts\Backup\BackupDestination`, registro
  `DestinationManager` (singleton nel container). Il free registra solo `local`.
- **Eventi** (senza listener nel free): `App\Events\Backup\BackupStarted`,
  `BackupCompleted`, `BackupFailed`, `BackupDeleted`.
- **Manifest versionato** (`manifest_format: 1`) dentro ogni zip + copia nella colonna
  `manifest`: versione app, migrazioni, conteggi, sha256 del dump.

## Funzioni del plugin a pagamento

### 1. Backup programmati (scheduling)
- Comando/job che chiama `BackupManager::start()` + un loop di `runStep()`.
- Su VPS/cron nativo: comando artisan del plugin schedulato (es. `backup-pro:run`).
- Su shared hosting: agganciarsi allo scheduler esistente (webhook `/system/run-scheduler`,
  finestra ~55s) eseguendo 1-2 step per tick — il motore riprendibile lo consente già,
  NON serve toccare il core. Stato "in coda/programmato" gestito dal plugin.
- Decisione di prodotto già presa: il comando artisan di backup NON esiste nel free
  proprio per riservare l'automazione al plugin.

### 2. Destinazioni cloud (Google Drive, S3, FTP, ...)
- Dal service provider del plugin:
  `app(DestinationManager::class)->extend('gdrive', fn () => new GoogleDriveDestination(...));`
- Upload post-completamento: listener su `BackupCompleted` che copia l'archivio dalla
  destinazione locale alle destinazioni remote configurate (con retry). Le destinazioni
  remote restituiscono `localPath() === null`: il download dalla UI passa per stream.
- Adapter Flysystem: Google Drive richiede adapter di terze parti; S3 richiede
  `league/flysystem-aws-s3-v3` (dipendenze del plugin, non del core).

### 3. Notifiche email (esito backup)
- Listener del plugin su `BackupCompleted` / `BackupFailed` → mail all'amministratore
  usando l'infrastruttura mail già esistente (MailSettings + MailLog).
- Ha senso soprattutto insieme allo scheduling (i backup manuali si vedono a schermo).

### 4. Fast-path mysqldump su VPS
- Override del binding `DatabaseDumperInterface` con un dumper che usa il binario
  `mysqldump` quando disponibile (detection: `function_exists('proc_open')` + binario
  nel PATH) e ricade sul dumper PDO del core altrimenti.
- Vantaggio: dump molto più veloce su installazioni grandi. Solo plugin: il free
  mantiene UN solo code path testato.

### 5. Cifratura zip con password — FATTO in beta.12 (FREE)
- `ZipArchive::setEncryptionName($entry, ZipArchive::EM_AES_256)` per ogni entry
  nell'`Archiver` (che chiude/riapre a batch) + `setPassword()`. Verificato: TUTTE
  le entry AES-256 anche su backup ripreso da checkpoint.
- MODELLO SCELTO (14/07/2026): UNA password salvata, non una per backup — Vincenzo
  ha sollevato il problema reale "password diverse = impossibili da ricordare =
  backup irrecuperabili". `BackupPasswordStore` la salva cifrata con APP_KEY in
  `storage/app/backups/.default-password` (chmod 0600): NON è nel database (nessun
  dump la contiene) e la cartella è già esclusa dai backup → la password non entra
  MAI in un archivio (test dedicato). Un archivio rubato resta illeggibile.
  Esposizione accettata: chi viola il server live la ottiene, ma avrebbe già il DB.
- UI: la password sta nelle IMPOSTAZIONI (card sotto "Backup da conservare"), non
  nel riquadro Crea — è un'impostazione persistente. Toggle "Proteggi" nel create
  usa la salvata; disabilitato con hint se non impostata. Icona occhio + validazione
  inline (min 8, confirmed). Il valore in chiaro non arriva MAI al frontend: la
  pagina riceve solo `backup_has_password` booleano.
- Avvertenze UX mostrate: Windows Explorer non apre zip AES (serve 7-Zip / Keka su
  Mac); password dimenticata = backup irrecuperabile (nessun recovery, per design).
- NOTA: il futuro restore guidato dovrà chiedere la password (non usare quella
  salvata: su un server nuovo non esiste).

### 6. Restore guidato da UI (probabilmente FREE, non plugin — è promesso in homepage)
- Il formato è già pronto: leggere `manifest.json`, validare `manifest_format` e
  versione app (accettare solo backup di versione uguale o più vecchia + `artisan migrate`
  finale), verificare sha256 dei componenti, importare il dump via PDO (gli INSERT
  sono <1MB e senza DEFINER apposta), estrarre `files/` con ZipArchive.
- Valutare se integrarlo anche nel wizard installer ("Ripristina da backup" su
  installazione vergine = migrazione server completa).
- Il campo `app.url` del manifest è SOLO informativo (identifica l'installazione di
  origine): il restore NON deve validarlo né usarlo — in un trasferimento il dominio
  cambia legittimamente e l'APP_URL corretto vive nel .env, che l'utente aggiorna.

### 7. Opzione "solo database" — FATTO in beta.12 (FREE)
- Banale col motore attuale: saltare la fase file (FileSelector vuoto) e marcare il
  tipo nel manifest (`contents: db-only`). UI: scelta al momento della creazione.

### 8. Integrazione Audit/Activity log
- Quando il tab "Attività Sistema" della pagina Logs verrà attivato (oggi è commentato
  in LogsController), aggiungere listener che registrano `activity()` su
  BackupStarted/Completed/Failed/Deleted. Gli errori oggi sono già in: colonna `error`
  della tabella backups (visibile in UI) + `laravel.log` via `report()`.

### 9. Modalità SaaS/cloud gestito — flag GIÀ IMPLEMENTATO in beta.11
- FATTO (beta.11, nato per la demo pubblica): flag `config('backup.enabled')` /
  env `BACKUP_ENABLED=false` — la card in impostazioni appare col badge "Disabilitati"
  e tutte le rotte backup rispondono 404 anche agli amministratori (l'archivio
  conterrebbe il .env del server). Coperto da test.
- Sul server demo: aggiungere `BACKUP_ENABLED=false` al .env.
- Nel SaaS: stesso flag per i tenant; il motore resta utilizzabile internamente
  dalla piattaforma per i backup gestiti.

## Vincoli da NON violare (lezioni della ricerca beta.11)

- Mai assumere proc_open/exec/binari esterni nel code path di default.
- Mai esecuzione monolitica: tutto ciò che è lungo deve passare per lo step-runner.
- `storage/app/backups` è auto-esclusa dal backup e va tenuta fuori dall'exclude-list
  dell'updater OTA (l'updater già esclude `storage` in blocco).
- Il formato dell'archivio (db/ + files/ + manifest.json) è un contratto: le evoluzioni
  passano da `manifest_format` incrementale, mai da breaking change silenziosi.
- APP_KEY non è ricostruibile: ogni funzione di restore deve preservarla e ogni
  documentazione deve avvisare di non rigenerarla.
