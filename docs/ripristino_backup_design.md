# Ripristino guidato dei backup — Linee guida di progetto (beta.13)

> Documento di design per la 1.10.0-beta.13, scritto il 15/07/2026 dopo ricognizione
> del codice esistente e discussione delle decisioni. È la fonte di verità durante
> l'implementazione: se durante il lavoro emerge un motivo per deviare, prima si
> aggiorna questo documento, poi si devia.
>
> NB: questo file va COMMITTATO subito — la prima versione (15/07 sera) è stata
> cancellata da un `git clean` durante la preparazione del pacchetto beta.12
> perché era ancora non tracciata.

---

## 1. Obiettivo

Chiudere il cerchio aperto in beta.11: gli archivi di backup (full e db_only,
cifrati e non) diventano **ripristinabili dall'interfaccia**, senza phpMyAdmin,
senza terminale, senza mysql client — sugli stessi hosting condivisi per cui è
stato progettato il motore di backup.

Due ingressi:

- **M1 — Pannello amministrazione**: ripristina un archivio presente sul server
  sopra l'installazione corrente (rollback dopo un errore, recupero, trasferimento
  su installazione fresca).
- **M2 — Wizard d'installazione**: alla prima installazione, in alternativa alla
  procedura normale, "Ripristina da backup" ricostruisce l'intera installazione
  da un archivio (trasferimento server-to-server).

**Decisione di rilascio (Vincenzo, 15/07/2026):** M1 + M2 escono insieme nella
beta.13. Il piano resta in due milestone: se M2 si allunga, M1 è rilasciabile da
solo come beta.13 e M2 slitta a beta.14 senza rework.

Il formato archivio NON cambia: manifest_format 1 (beta.11/12) deve essere
ripristinabile. Il manifest era stato progettato per questo (versione app,
elenco migrazioni, sha256 del dump, contents, encrypted).

---

## 2. Decisioni prese (15/07/2026, con Vincenzo)

| Tema | Decisione |
|---|---|
| Backup di sicurezza pre-ripristino | **Attivo di default, disattivabile** (checkbox pre-spuntata nella conferma). Tipo db_only (veloce; i file correnti sono comunque preservati da parte fino alla finalizzazione). Il preflight avvisa se lo spazio non basta. |
| APP_KEY diversa (trasferimento via admin) | **Adozione chiave + ripiego pulizia**: se l'archivio full contiene un .env con APP_KEY diversa, si propone (consigliato) di adottare la APP_KEY del backup; in alternativa si tiene la chiave attuale e si azzerano automaticamente 2FA e password SMTP con avviso. Dopo l'import, in OGNI caso, una verifica di decifratura fa da rete di sicurezza (vedi §7). |
| Rilascio | M1+M2 insieme in beta.13, split-ready. |
| Conferma forte | **Password del proprio account** ri-digitata nella finestra di conferma (sudo mode), oltre al permesso `MANAGE_GENERAL_SETTINGS`. |
| Password archivi cifrati | Va **sempre chiesta** (mai riusare silenziosamente la password salvata; al massimo proporla come precompilazione). Verificata SUBITO provando a leggere `manifest.json`. |
| app.url del manifest | **Mai validato/bloccante** (decisione storica di beta.11: il dominio può legittimamente cambiare). Mostrato solo a titolo informativo nel riepilogo. |
| Versione salvata nel DB | La tabella `settings` (general.version) contiene la versione dell'installazione di ORIGINE dopo l'import: la finalizzazione **riusa la logica di `SystemUpgradeController::run()`** (migrazioni con retry + riallineamento versione + optimize:clear + storage:link), estratta in servizio condiviso. NON ci si affida al middleware `CheckForPendingUpdates` (§7-bis). |
| Quale wizard gira davvero | **RISOLTO da Vincenzo (15/07/2026)**: `eii/laravel-installer` è stata completamente sostituita nelle versioni precedenti — gira SOLO il wizard custom (`InstallerWizard` Livewire). I commenti contraddittori residui nel codice andranno ripuliti. |
| Installer bootstrap (`installer_php84.php`) | **Nessuna modifica necessaria per la beta.13** (analisi §7-ter). Solo il bump di routine PACKAGE_URL/HASH/APP_VERSION a ogni release. |

---

## 3. Vincoli scoperti in ricognizione (perché l'architettura è questa)

Fatti verificati nel codice il 15/07/2026:

1. **Sessioni, cache e code vivono nel DATABASE** (`SESSION_DRIVER=database`,
   `CACHE_STORE=database`, `QUEUE_CONNECTION=database` nel .env reale; default
   config = database anche senza env). Conseguenze:
   - a metà import la tabella `sessions` viene sovrascritta → **la sessione
     dell'admin muore**: gli step di ripristino NON possono usare l'auth di sessione;
   - `Cache::lock()` è su DB → **i lock in cache sono inaffidabili durante l'import**:
     i lock del ripristino vanno su file;
   - lo stato del ripristino NON può stare in una tabella (verrebbe sovrascritto,
     e la tabella `backups` nel dump è solo-struttura).
2. **2FA cifrata senza gestione errori**: `users.two_factor_secret` e
   `two_factor_recovery_codes` sono cifrati con APP_KEY e decifrati SENZA
   try/catch (`TwoFactorAuthChallengeController.php:59,81`). Con APP_KEY diversa,
   ogni utente con 2FA attiva riceve **HTTP 500 al login** → lockout totale.
3. **Password SMTP** cifrata con APP_KEY in `settings.payload` (group=mail):
   degrada senza crash (fallback log) ma va azzerata/segnalata se indecifrabile.
4. **Pattern session-less già collaudati nel repo** da riusare:
   - token monouso su file con scadenza: `UpdateService` / `update_bridge.json`
     (`bin2hex(random_bytes(32))`, `expires_at`);
   - middleware "blocca tutto tranne una allowlist": `CheckForPendingUpdates`
     (ma il nostro NON deve toccare DB/sessione: solo file marker);
   - stato su file che sopravvive al wipe del DB: `storage/install-progress.json`
     dell'installer;
   - CSRF: le rotte step vanno aggiunte a `validateCsrfTokens(except:)`
     in `bootstrap/app.php` (come `system/run-scheduler`).
5. **Il dump non ha uno splitter**: i test round-trip delegano il parsing al server
   MySQL (query multi-statement + `nextRowset()`), estraendo il blocco trigger per
   offset. Per un import **riprendibile a step** serve un tokenizer nostro (vedi §6).
6. **Installer**: Livewire/Blade, step in `config/installer.php`, progress file
   `storage/install-progress.json`, lock `storage/installed.lock`, middleware
   `CheckInstaller` (accesso solo se `run_installer=true` E lock assente). Lo step
   `environment` esegue `migrate:fresh` (droppa tutto!) + seed: il ramo ripristino
   NON deve passarci. Il wizard non tocca mai APP_KEY (generata dall'installer
   bootstrap alla creazione del .env, o dall'entrypoint Docker, solo se vuota →
   una chiave scritta da noi viene preservata).
7. **Maintenance mode di Laravel**: non usata a runtime nel progetto, e col driver
   file bloccherebbe anche le rotte di ripristino → si usa un marker file proprio.

---

## 4. Architettura

### RestoreManager (speculare a BackupManager, ma stato su FILE)

- Step-runner riprendibile: ogni richiesta HTTP avanza per `step_time_budget`
  secondi (riuso `StepBudget`), poi salva il checkpoint e risponde. Il frontend
  (o il wizard Livewire) fa polling sequenziale.
- **Stato**: file JSON `storage/app/backups/restore-state.json` (cartella già
  fuori document root ed esclusa dagli archivi). Contiene: uuid del ripristino,
  fase, checkpoint di fase, archivio sorgente, opzioni scelte, token di step
  (hash), timestamp, esiti. Scrittura atomica (tmp + rename).
- **Lock**: file lock (`flock` su file dedicato), NON `Cache::lock`.
- **Auth degli step**: token monouso generato all'avvio, salvato **in chiaro solo
  nella risposta all'admin** (il frontend lo tiene in memoria) e **come hash nello
  state file**; ogni step lo presenta in header; middleware dedicato lo verifica
  senza toccare DB né sessione. Scadenza: `stale_after_hours` come i backup.
  Nel wizard (M2) stesso meccanismo, con token nel progress file dell'installer.
- **Password dell'archivio cifrato**: mai scritta in chiaro nello state file —
  cifrata con APP_KEY (come fa il checkpoint dei backup) e azzerata in ogni
  percorso terminale. NB: se l'utente sceglie "adotta APP_KEY dal backup", la
  riscrittura del .env avviene alla FINE (finalizzazione): fino ad allora la
  chiave corrente resta valida per decifrare la password nello state file.

### Modalità ripristino (middleware)

- Marker file `storage/framework/restore.lock` scritto all'avvio, rimosso alla fine.
- Middleware in testa al gruppo `web`: se il marker esiste → lascia passare SOLO
  le rotte di ripristino (con token valido) e `/up`; tutto il resto riceve una
  pagina statica 503 "Ripristino in corso" (Blade semplice, nessuna dipendenza
  DB/sessione/Inertia).
- Il marker blocca anche: creazione backup, webhook cron, updater. Viceversa, un
  backup in corso blocca l'avvio di un ripristino (mutua esclusione, vedi §8).
- Ripresa dopo interruzione: il token di ripresa È il token di step, mostrato
  UNA VOLTA all'avvio con l'avvertenza di conservarlo fino alla fine (come una
  password). La pagina 503 mostra il campo "riprendi con la chiave di ripresa".

### Fasi (macchina a stati)

```
pending → safety_backup → extracting → verifying → importing_database
        → restoring_files → finalizing → completed | failed
```

| Fase | Cosa fa | Checkpoint |
|---|---|---|
| `pending` | Stato creato, preflight superato, conferma ricevuta. | — |
| `safety_backup` | Se richiesto: backup db_only via BackupManager (PRIMA di entrare in modalità ripristino: qui sessione e DB sono ancora sani). | uuid del backup di sicurezza |
| `extracting` | Estrae l'archivio in `storage/app/backups/tmp/restore-{uuid}/` a batch (N entry o M byte per step). Protezione zip-slip (§8). Con password: `setPassword` + verifica preventiva sul manifest. | indice entry |
| `verifying` | sha256 del `db/database.sql` estratto == `manifest.database.dump_sha256`. Confronto migrazioni/versione già fatto nel preflight, qui ri-verificato. | — |
| `importing_database` | Tokenizer statement-per-statement (§6) con offset di byte. Ogni step: nuova sessione MySQL → ri-emette il preambolo (`SET FOREIGN_KEY_CHECKS=0` ecc.). | offset byte + n. statement |
| `restoring_files` | Solo full: per ogni entry top-level di `files/storage/app/` → rinomina la corrente in `tmp/restore-{uuid}/old/`, sposta la nuova al suo posto. **`storage/app/backups` non si tocca MAI** (contiene archivi, stato, password salvata). `files/.env` NON viene copiato qui (vedi §7). | indice entry |
| `finalizing` | In ordine: (a) eventuale adozione APP_KEY → riscrittura .env (solo chiave APP_KEY, mai DB_*/APP_URL); (b) **finalizzazione di sistema riusata da `SystemUpgradeController::run()`** (§7-bis): migrazioni con retry + riallineamento `settings.general.version` a `config('app.version')` + optimize/view/route:clear + storage:link; (c) sonda di decifratura + eventuale pulizia 2FA/SMTP (§7); (d) TRUNCATE `sessions` + svuota `cache`/`cache_locks` + `forgetCachedPermissions()`; (e) **ri-registrazione archivi orfani**: scansione `storage/app/backups/*.zip`, lettura manifest, INSERT dei record mancanti (incluso il backup di sicurezza e l'archivio appena ripristinato); (f) cleanup tmp + vecchi file messi da parte; (g) scrittura esito nello state file, rimozione marker. | sotto-passi a/g |
| `completed` | Pagina esito (statica, senza auth): riepilogo + link al login. Tutti devono ri-autenticarsi. | — |
| `failed` | Stato + errore nello state file. Pagina esito con: errore, presenza del backup di sicurezza, file originali ancora in `old/` se il fallimento è avvenuto prima del cleanup, istruzioni di ripresa/rollback. Il marker RESTA (l'app a metà import non deve tornare raggiungibile) finché l'admin non riprende o esegue il rollback guidato. | — |

**Rollback in caso di `failed`**: il percorso guidato propone di ripristinare il
backup di sicurezza (stesso motore, senza safety backup annidato) e/o rimettere a
posto i file da `old/`. Nessun rollback automatico non richiesto: l'admin decide.

### Ordine deliberato: file DOPO database

L'import DB è la fase più lunga e rischiosa: se fallisce, i file correnti sono
ancora intatti al loro posto (il DB si recupera dal safety backup). Se invece
fallisse lo swap dei file (fase breve, rename locali), il DB nuovo è già coerente
e i vecchi file sono in `old/`. In entrambi i casi c'è sempre una via di ritorno.

---

## 5. I due ingressi

### M1 — Pannello amministrazione

- Nella pagina Gestione backups, azione "Ripristina" su ogni backup `completed`
  della lista + su archivi ri-registrati dalla scansione disco.
- **Scansione disco** ("Importa archivi trovati"): bottone che registra nella
  lista gli zip presenti in `storage/app/backups/` ma assenti dalla tabella
  (letti via manifest). Serve: al flusso FTP (carichi lo zip via FTP e lo
  ripristini dall'admin), al post-restore, e come recupero generale. Per gli
  archivi cifrati il manifest non è leggibile senza password → la scansione li
  registra come "archivio cifrato, dettagli disponibili al ripristino" leggendo
  solo nome file/dimensione/sha256.
- Flusso: click Ripristina → dialog con riepilogo manifest (data, versione,
  contenuto, origine informativa) → password archivio se cifrato + password
  account (sudo) + checkbox safety backup (pre-spuntata) + eventuale scelta
  APP_KEY se rilevata diversa → avvio → pagina di avanzamento (il resto dell'app
  è in 503) → esito → login.
- Permesso: `MANAGE_GENERAL_SETTINGS` (coerente col backup). Kill-switch:
  `config('backup.enabled')` vale anche per il ripristino (demo/SaaS).

### M2 — Wizard d'installazione

- Il wizard è interamente nostro (`InstallerWizard` Livewire — `eii/laravel-installer`
  sostituita del tutto, conferma di Vincenzo 15/07/2026): pieno controllo sul branch.
  Da ripulire i commenti residui che citano ancora il vendor.
- Welcome: scelta "Nuova installazione" / "Ripristina da backup".
- Ramo ripristino: requisiti → credenziali DB (con test connessione, riuso del
  componente esistente) → scelta archivio: upload diretto (mostrando i limiti
  `upload_max_filesize`/`post_max_size` rilevati) OPPURE istruzioni FTP verso
  `storage/app/backups` (il ramo crea la cartella se assente) + riscansiona →
  password se cifrato → riepilogo manifest → esecuzione motore (NO `migrate:fresh`,
  NO seed, NO step admin: gli utenti arrivano dal backup) → finalizzazione di
  sistema (§7-bis: migrate + versione DB + cache) → finish (scrive
  `installed.lock`, cancella progress file).
- **.env nel wizard**: si parte dal `.env` dell'archivio (quindi APP_KEY
  originale) e si sovrascrivono SOLO: `DB_*` (dal form), `APP_URL` (dal form),
  `APP_ENV`/`APP_DEBUG` (da config). `APP_NAME`/locale restano quelli del backup.
  Riuso della meccanica regex-safe di `updateEnvSettings()` (attenzione alle
  password con `$`: usare `preg_replace_callback` come l'esistente).
- db_only nel wizard: consentito ma con doppio avviso — niente documenti
  (record allegati orfani) e niente .env → APP_KEY nuova → la finalizzazione
  azzererà 2FA/SMTP (sonda §7).

---

## 6. Import del database: il tokenizer

Il dump è NOSTRO e deterministico (MySqlDumper): il tokenizer riconosce solo il
sottoinsieme che generiamo, ed è validato dai test round-trip esistenti.

Regole del formato (verificate in ricognizione):

- Preambolo: `SET NAMES utf8mb4; SET FOREIGN_KEY_CHECKS=0; SET UNIQUE_CHECKS=0;
  SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'; SET time_zone='+00:00';`
- Statement terminati da `;\n`, MA i letterali stringa (via `PDO::quote`, escape
  backslash) possono contenere `;`, newline, apici → si scandisce carattere per
  carattere con stati: fuori/dentro stringa `'…'` (gestendo `\'` e `\\`), dentro
  identificatore `` `…` ``, dentro commento `-- …\n`. I binari sono `0x…` (nessun
  ambiguo). I `CREATE TABLE` sono multiriga.
- Blocco trigger delimitato da `DELIMITER ;;\n` … `DELIMITER ;\n`: dentro, il
  terminatore è `;;\n`.
- Ogni step di import: `SET` di preambolo ri-emessi (sessione nuova), poi
  statement singoli via `PDO::exec` fino a esaurimento budget; checkpoint =
  offset byte del prossimo statement. NIENTE multi-statement PDO (buffering
  imprevedibile su host condivisi, e impossibile riprendere a metà).
- A fine file: footer `SET FOREIGN_KEY_CHECKS=1; SET UNIQUE_CHECKS=1;`.
- Il tokenizer va testato contro: i dump dei test round-trip (già coprono emoji,
  `;`/newline/apici nelle stringhe, blob hex, viste+stub, trigger, PK composte)
  E un confronto "import col tokenizer == import multi-statement del server"
  sullo stesso dump.

Miglioria contestuale al dumper (beta.13): aggiungere `sessions`, `cache`,
`cache_locks` a `dump_exclude_data` (dati volatili: alleggeriscono gli archivi
ed evitano di ripristinare sessioni morte). Il ripristino deve comunque gestire
gli archivi VECCHI che li contengono (il TRUNCATE di finalizzazione copre tutto).

---

## 7. .env, APP_KEY e dati cifrati (la matrice dei casi)

Inventario dei dati cifrati con APP_KEY (da ricognizione):

| Dato | Dove | Se la chiave cambia |
|---|---|---|
| Segreti 2FA + recovery codes | `users.two_factor_*` | **500 al login (nessun try/catch)** → lockout |
| Password SMTP | `settings` (group mail) | degrado silenzioso a log/env |
| Password salvata dei backup | FILE `.default-password` (mai negli archivi né nel DB) | `get()` → null (gestito) |
| Checkpoint backup in corso | `backups.checkpoint` | irrilevante (transitorio) |

Comportamenti:

- **Admin, archivio full**: si legge `APP_KEY` dal `files/.env` dell'archivio
  (senza applicarlo) e si confronta con la corrente.
  - Uguale (rollback classico): nessun tema, `.env` MAI toccato.
  - Diversa (trasferimento): scelta esplicita nella conferma —
    **(consigliata)** adotta la APP_KEY del backup: alla finalizzazione si
    riscrive SOLO `APP_KEY` nel `.env` corrente (mai DB_*/APP_URL: il server è
    questo); il file `.default-password` corrente viene azzerato (era cifrato
    con la vecchia chiave; l'admin re-imposta la password backup);
    **oppure** mantieni la chiave attuale → pulizia automatica: `two_factor_*`
    azzerati per tutti + password SMTP svuotata, con elenco esplicito nell'esito.
- **Admin, archivio db_only**: nessun .env nell'archivio → nessun confronto
  possibile a priori. Decide la **sonda post-import**.
- **Sonda di decifratura (sempre, in finalizzazione)**: si prova a decifrare un
  campione dei valori cifrati presenti (un `two_factor_secret` non nullo, la
  `mail_password` se presente). Se la decifratura fallisce → pulizia automatica
  come sopra + avviso nell'esito. È la rete di sicurezza che copre ogni caso non
  previsto (incluso db_only e archivi manipolati).
- **Wizard**: APP_KEY arriva dal `.env` dell'archivio (full) → tutto coerente.
  db_only → chiave fresca → la sonda pulisce e avvisa.

---

## 7-bis. Versione dell'app nel DATABASE (domanda di Vincenzo, 15/07/2026)

La versione vive in DUE posti: nei file (`config/app.php` → `APP_VERSION`) e nel
DATABASE (`settings`, group=general, name=version — `GeneralSettings::$version`).
Dopo un ripristino il DB contiene la versione dell'installazione di ORIGINE
(es. backup fatto sulla beta.11 ripristinato su codice beta.13 → il DB "dice"
beta.11): esattamente lo scenario "DB 1.9.1 su codice 1.10".

**Il progetto ha GIÀ la macchina per questo caso** — è il flusso post-aggiornamento:
- `CheckForPendingUpdates` (middleware): se `config('app.version')` > versione nel
  DB → redirect dell'admin a `system.upgrade.confirm`;
- `SystemUpgradeController::run()`: migrazioni con retry → UPDATE della versione
  nel DB a `config('app.version')` → `optimize:clear`/`view:clear`/`route:clear` →
  invalidazione cache middleware → `storage:link` → cleanup.

**Decisione**: la finalizzazione del ripristino ESEGUE direttamente questa logica
(estratta da `SystemUpgradeController::run()` in un servizio riusabile, es.
`SystemFinalizer`, usato da entrambi). NON ci si affida al middleware perché:
1. è attivo solo con `run_installer=true` (distribuzione installer: falso in dev/git);
2. scatterebbe solo al primo accesso di un admin → finestra con schema/versione
   disallineati;
3. il nostro caso è sincrono: lo sappiamo nel momento esatto del ripristino.

Il caso inverso (backup con versione più NUOVA del codice) resta BLOCCATO al
preflight leggendo `manifest.app.version` (downgrade non supportato: messaggio
"aggiorna prima KondoManager alla versione X"). `version_compare` gestisce
correttamente i suffissi beta (`1.10.0-beta.12` < `1.10.0`).

**Ripristinare un backup PIÙ VECCHIO su codice più nuovo (es. backup 1.9.1 su
codice 1.10) — domanda di Vincenzo 16/07/2026:** funziona ed è di prima classe.
Catena: preflight marca `needs_migration` (non blocca); import azzera+ricostruisce
il DB a 1.9.1 (schema, dati, tabella `migrations`, `settings.version` tutti a
1.9.1); finalizzazione → `SystemFinalizer::finalize()` esegue `migrate --force`
che confronta la tabella `migrations` (1.9.1) con i file su disco (1.10) e applica
SOLO le migrazioni mancanti (1.9.1→1.10), poi riallinea la versione a 1.10. È
**identico** alla strada di un aggiornamento normale: stessi file di migrazione,
stessi dati, stesso stato finale — nessun percorso di codice nuovo/non testato.
Unica avvertenza: se le migrazioni 1.9.1→1.10 includono data-migration, girano
sui dati importati (corretto e voluto). ⚠ La catena completa "backup vecchio →
import → migrate → allineamento" NON è ancora esercitata E2E (nel RestoreManagerTest
il SystemFinalizer è mockato): va coperta dal collaudo E2E reale su throwaway
(punto chiusura beta.13).

## 7-ter. Cambio dominio e installer bootstrap (domande di Vincenzo, 15/07/2026)

**Trasferimento su altro dominio** — verificato nel codice il 15/07/2026: nel
database NON esistono URL assoluti. `GeneralSettings` non ha campi URL;
`PrintSettings.firma_stampe_path` e i documenti sono percorsi RELATIVI su disco;
nessuna colonna url/link nelle migrazioni. Quindi il cambio dominio si riduce a:

| Cosa | Dove vive | Chi lo gestisce |
|---|---|---|
| `APP_URL` | `.env` | Admin flow: MAI toccato (il .env corrente ha già il dominio giusto del server su cui giri). Wizard: scritto dal form col dominio nuovo. |
| `app.url` nel manifest | archivio | Solo informativo, mai bloccante (già deciso). |
| Link firmati/inviti già SPEDITI via email | nelle caselle dei destinatari | Puntano al vecchio dominio e muoiono naturalmente: nulla da fare (i token in DB restano validi: rigenerare l'invito produce un link nuovo sul dominio nuovo). |
| Symlink `public/storage` | filesystem | Ricreato dalla finalizzazione (`storage:link`, già dentro §7-bis). |
| Cookie di sessione del vecchio dominio | browser degli utenti | Irrilevanti: sessioni azzerate comunque. |

Conclusione: **nessun lavoro aggiuntivo** oltre a quanto già in piano. Il "ripristino
che in realtà è un trasferimento" è il percorso wizard (dominio nuovo nel form) o
il percorso admin su installazione fresca (dominio nuovo già nel .env corrente).

**Installer bootstrap (`installer_php84.php`)** — analizzato il 15/07/2026:
NON serve modificarlo per la beta.13. Motivi:
- in modalità install: scarica il pacchetto, crea `.env` da `.env.example` con
  APP_KEY fresca e reindirizza a `/install` → è il WIZARD (a valle) che nel ramo
  ripristino sostituirà APP_KEY con quella dell'archivio e scriverà le DB_*: la
  chiave temporanea del bootstrap è irrilevante;
- le regole `.htaccess` che installa negano già l'accesso web a `.zip`, `.sql` e
  all'intera `storage/` → gli archivi caricati via FTP non sono scaricabili dal web;
- lo scenario "trasferimento": l'utente carica installer bootstrap + (via FTP) il
  suo zip di backup → installa il pacchetto → al wizard sceglie "Ripristina da
  backup" → fine. Il bootstrap non deve sapere nulla del ripristino.
- Unico intervento per release (routine, non beta.13-specifico): bump di
  `PACKAGE_URL`, `PACKAGE_HASH`, `APP_VERSION`.

---

## 8. Sicurezza

- **Zip-slip**: in estrazione ogni nome entry è validato — rifiutati percorsi
  con `..`, percorsi assoluti, entry symlink; si estraggono SOLO `db/database.sql`,
  `manifest.json` e `files/**`. I file finiscono esclusivamente sotto
  `storage/app` (fuori document root: mai eseguibili via web) tramite path
  ricostruito da noi, mai il nome entry grezzo.
- **Fiducia nell'archivio**: ripristinare un backup = fidarsi del suo contenuto
  (il DB può contenere qualsiasi cosa, inclusi utenti admin). Mitigazioni: sudo
  mode all'avvio; riepilogo manifest mostrato PRIMA della conferma; per gli
  archivi cifrati l'integrità è garantita dall'AES (manomissione → errore di
  lettura); per i non cifrati mostriamo lo sha256 da confrontare con quello
  annotato. La guida in-page lo spiega.
- **Auth step senza sessione**: token random 32 byte, hash nello state file,
  header dedicato, scadenza, throttle sulle rotte step. CSRF-except per le rotte
  step (pattern `system/run-scheduler`).
- **Mutua esclusione** (lock su FILE, non cache): un solo ripristino alla volta;
  ripristino rifiutato se c'è un backup in corso; backup/updater/cron rifiutati
  se c'è un ripristino in corso (marker).
- **Permessi**: `MANAGE_GENERAL_SETTINGS` + `config('backup.enabled')` (demo/SaaS
  esclusi). Nel wizard: `CheckInstaller` già garantisce che giri solo su app non
  installata (`run_installer` + assenza lock file).
- **Log**: eventi `RestoreStarted/Completed/Failed` (senza listener nel free,
  coerente col backup) + esito persistito nello state file per la pagina finale.

---

## 9. Post-ripristino (cosa deve essere vero alla fine)

- Tutti gli utenti ri-fanno login (sessioni azzerate). Messaggio dedicato.
- La lista backup mostra: archivi ri-registrati da disco (manifest), incluso il
  backup di sicurezza e l'archivio sorgente.
- Migrazioni allineate al codice E versione nel DB allineata a `config('app.version')`
  (§7-bis) — il caso "backup più NUOVO del codice" è stato BLOCCATO al preflight.
- Cache svuotate, permessi Spatie ricaricati, `storage:link` verificato.
- Se pulizia 2FA/SMTP è avvenuta: elenco chiaro nell'esito di cosa va
  riconfigurato (2FA per N utenti, password SMTP, password backup salvata).
- Nessun residuo in `tmp/restore-*`, marker rimosso, state file conservato come
  storico dell'ultimo ripristino (utile per assistenza).

---

## 10. Fuori scope (beta.13)

- Ripristino selettivo (solo alcune tabelle / solo file).
- Ripristino da destinazioni cloud (arriverà col plugin, via DestinationManager).
- Downgrade (backup più nuovo del codice) — bloccato, non supportato.
- Cambio driver DB in ripristino (dump MySQL → target MySQL/MariaDB; SQLite solo
  per sviluppo: il "ripristino" SQLite = sostituzione file, gestita ma non
  prioritaria).
- Upload chunked di archivi enormi nel wizard (v1: upload semplice + percorso FTP).
- Prova di ripristino programmata ("restore drill") — idea per il plugin.

---

## 11. Piano di lavoro

**Fase 0 — Verifiche preliminari** (ridotta dopo le risposte di Vincenzo)
1. ~~Chiarire ambiguità vendor/custom installer~~ → RISOLTO: gira solo il wizard
   custom; ripulire i commenti residui che citano il vendor.
2. Confermare che `PDO::exec` statement-singolo funziona con tutti i costrutti
   del dump (trigger inclusi, senza DELIMITER che è direttiva client).
3. Decidere il nome delle chiavi i18n (`restore_*` sotto `impostazioni.` in
   coerenza con `backup_*`).

**Fase 1 — Motore (M1 backend)**
- `RestoreState` (file JSON atomico) + lock file + eccezioni dedicate.
- `SqlDumpTokenizer` con test contro i dump round-trip (PRIMO componente da
  scrivere, come fu MySqlDumper in beta.11: è il cuore rischioso).
- `ArchiveReader` (estrazione con zip-slip guard + password AES + batch).
- `SystemFinalizer` estratto da `SystemUpgradeController::run()` (riusato da
  upgrade E restore: migrazioni con retry, versione DB, cache, storage:link).
- `RestoreManager` (macchina a stati completa, safety backup, finalizzazione
  con sonda/pulizia/ri-registrazione orfani).
- `RestorePreflight` (spazio, versioni/migrazioni dal manifest, driver, password).
- Middleware modalità ripristino + middleware token step + rotte + CSRF-except.

**Fase 2 — UI admin (M1 frontend)**
- Azione Ripristina + dialog conferma (manifest, password archivio, sudo,
  checkbox safety, scelta APP_KEY quando rilevata).
- Pagina avanzamento (pattern polling del backup) + pagina 503 statica +
  pagina esito. Scansione disco ("Importa archivi trovati").
- i18n 4 lingue, guida in-page aggiornata (nuova scheda "Ripristino dall'interfaccia").

**Fase 3 — Wizard (M2)**
- Scelta al welcome + step ramo ripristino (Livewire, riuso componenti DB/upload).
- Merge .env (APP_KEY dall'archivio, DB/URL dal form).
- Esecuzione motore + finalizzazione + finish. Pulizia commenti vendor residui.

**Fase 3-bis — Backup automatico PRIMA dell'aggiornamento — ✅ FATTO (16/07/2026, in beta.13)**
Chiude il cerchio col ripristino: *backup-prima-di-aggiornare* + *ripristina-se-va-storto*.
Implementato:
- **Dove**: schermata di conferma upgrade (`system/upgrade/finalize` → `Confirm.vue`),
  PRIMA del POST a `system.upgrade.run` (migrazioni). Il DB è ancora pre-migrazione.
- **Endpoint dedicati** in `SystemUpgradeController` (NON riuso di quelli backup, per
  non accoppiarsi a permesso/kill-switch): `POST system/upgrade/backup` (backupStart,
  crea db_only via BackupManager) + `POST system/upgrade/backup/{uuid}/step` (backupStep).
  Protetti dal gruppo rotte upgrade (`role:amministratore`, no auto.update → funziona
  anche in manuale/FTP). Guidati a step dal frontend come il backup normale.
- **Gate**: `Schema::hasTable('backups')` — un upgrade da versione precedente alla
  feature backup non ha la tabella (le migrazioni la creeranno): in quel caso `canBackup`
  è false, la UI mostra un avviso "esegui backup manuale dal pannello hosting" e procede.
  Endpoint risponde 409 se la tabella manca. Riusa un backup già in corso (ripresa/reload).
- **Tipo**: `db_only` (il rischio upgrade è nelle migrazioni; i file arrivano dal pacchetto).
- **Forzato vs opt-out**: toggle default ON; disattivandolo compare una checkbox
  "Confermo di voler aggiornare SENZA backup" e il pulsante resta disabilitato finché
  non la si spunta (attrito per l'admin distratto).
- **Sinergia**: il backup pre-upgrade finisce nella lista normale → se l'aggiornamento
  va male si ripristina col pannello M1.
- **Test**: `tests/Feature/System/PreUpgradeBackupTest.php` (5 verdi): canBackup, gate
  ruolo, db_only→completed, riuso in-corso, 409 senza tabella. E2E browser: toggle default
  ON, attrito opt-out (pulsante disabilitato + checkbox conferma). SystemUpgradeController::run
  già delega a SystemFinalizer (Fase 1). NB collaudo browser fatto SENZA cliccare "Avvia"
  (avrebbe creato un backup reale sul DB condiviso → retention avrebbe cancellato i backup
  reali di Vincenzo, come il bug del 15/07): verificato solo rendering+attrito.

**Fase 4 — Rifiniture e rilascio**
- `dump_exclude_data` += sessions/cache/cache_locks (dumper).
- Collaudo E2E REALE su throwaway, inclusa la catena "backup vecchio → migrate" (§7-bis).
- Changelog (md + json it/en/pt), bump versione, docs sito (dopo, come sempre).
- Port all'ufficiale SOLO dopo collaudo di Vincenzo.

---

## 12. Piano di test

**Pest (Feature/Unit):**
- Tokenizer: ogni costrutto del dump (stringhe con `;`/newline/apici/backslash,
  hex, CREATE multiriga, viste+stub, blocco trigger, preambolo/footer) +
  equivalenza con l'import multi-statement dei test esistenti.
- Round-trip COMPLETO su MySQL reale di servizio: crea dati → backup → distruggi
  → ripristina → confronta (chiude il cerchio coi test round-trip del dumper).
- Ripresa a metà di OGNI fase (stato serializzato/deserializzato su file).
- Zip-slip (archivi ostili costruiti ad hoc), password errata/assente, archivio
  manomesso (sha256), versione più nuova → blocco, migrazioni mancanti → migrate,
  versione DB riallineata dopo il ripristino (§7-bis).
- Sonda/pulizia 2FA+SMTP con APP_KEY diversa. Ri-registrazione orfani.
- Mutua esclusione backup↔ripristino. Token step: senza/scaduto/errato → 403.

**⚠️ REGOLA ASSOLUTA PER I TEST E IL COLLAUDO MANUALE ⚠️**

Il ripristino è distruttivo per definizione e **le cartelle test/ufficiale
condividono lo stesso MySQL di sviluppo** (`kondomanager-free`) — è già costato
due incidenti coi soli backup (15/07/2026: pulizia con `latest()` + retention
scattata da un backup di prova). Quindi:

- I test Pest del ripristino girano ESCLUSIVAMENTE su un database MySQL usa-e-getta
  dedicato (es. `kondomanager_restore_test`), MAI sulla connessione di default.
  Ogni test che importa un dump crea/droppa il proprio schema.
- Il collaudo E2E nel browser si fa su un'istanza throwaway: copia del progetto o
  `.env` alternativo puntato a un DB clone (`kondomanager_restore_e2e`), MAI sul
  DB condiviso. Preparare uno script di setup del clone all'inizio della Fase 1.
- Vincenzo: quando collaudi il ripristino a mano, fallo SOLO sull'istanza
  throwaway finché non siamo entrambi convinti; il primo collaudo sul DB vero
  va fatto con un backup fresco appena scaricato ANCHE fuori dal server.

---

## 13. Registro decisioni in corso d'opera

| Data | Decisione | Motivo |
|---|---|---|
| 15/07/2026 | Versione nel DB riallineata dalla finalizzazione riusando la logica di SystemUpgradeController::run() (estratta in SystemFinalizer); niente affidamento al middleware CheckForPendingUpdates | Il middleware è attivo solo con run_installer=true e solo al login admin; il ripristino conosce il momento esatto in cui allineare (domanda di Vincenzo sul "DB 1.9.1 con codice 1.10") |
| 15/07/2026 | Cambio dominio: nessun lavoro aggiuntivo (verificato: nessun URL assoluto nel DB; APP_URL gestito per-flusso; storage:link nella finalizzazione) | Domanda di Vincenzo sul trasferimento verso altro dominio |
| 15/07/2026 | installer_php84.php: nessuna modifica per beta.13 | Il bootstrap crea .env con APP_KEY temporanea e delega al wizard, che nel ramo ripristino la sostituisce; .htaccess protegge già gli zip |
| 15/07/2026 | Ambiguità wizard risolta: gira SOLO il wizard custom (vendor eii/laravel-installer completamente sostituito) | Conferma diretta di Vincenzo |
