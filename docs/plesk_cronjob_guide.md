# Configurazione Cron Job su Plesk — KondoManager

> **Versione applicabile:** KondoManager v1.9.1+  
> **Versione raccomandata:** v1.10.0-beta.1 (include il monitor heartbeat in Impostazioni → Automazioni)  
> **Ambiente:** Hosting condiviso o VPS con pannello Plesk (PHP 8.x via PHP-FPM)

---

## Introduzione

KondoManager utilizza due meccanismi separati per le operazioni pianificate:

- **Lo Scheduler Laravel** (`schedule:run`) — gestisce i task di manutenzione: pulizia log, controllo aggiornamenti, aggiornamento IP cron, e facoltativamente il worker delle code.
- **Il Queue Worker** (`queue:work`) — processa i job asincroni: invio email, generazione PDF, rate programmazione.

Su hosting **senza accesso terminale** (es. Altervista, Netsons), KondoManager usa un unico punto di ingresso via webhook esterno (cron-job.org) e processa le code in modo sincrono dentro lo stesso scheduler. Questo approccio funziona perché quei server hanno timeout HTTP molto permissivi.

**Plesk è diverso.** Offre cron job nativi da riga di comando CLI, timeout web ben più severi (30–60 secondi), e gestione dei processi separata. Mischiare i due approcci genera l'`Error 500` che probabilmente stai vedendo. Questa guida ti porta alla configurazione corretta in pochi passi.

---

## Sintomi di una configurazione errata

- cron-job.org restituisce **Error 500** o timeout
- Il pannello Plesk mostra il cron come "eseguito" ma le email non arrivano e i PDF non vengono generati
- Il widget heartbeat nella pagina **Impostazioni → Automazioni** rimane rosso (v1.10.0+)

---

## Come funziona il flag `SCHEDULE_QUEUE_WORKER`

KondoManager ha una variabile d'ambiente che controlla se il worker delle code deve girare dentro lo scheduler:

```
# .env
SCHEDULE_QUEUE_WORKER=true   # default nella distro → worker sincrono dentro schedule:run
SCHEDULE_QUEUE_WORKER=false  # corretto per Plesk → worker separato come cron autonomo
```

Con il flag a `true` (default), lo scheduler tenta di processare le code durante la stessa chiamata HTTP di cron-job.org. Su Plesk, questa chiamata dura fino a 55 secondi e viene terminata dal server web → **Error 500**.

La soluzione non richiede modifiche al codice: basta impostare il flag a `false` nel file `.env` e usare due cron nativi separati.

> **⚠️ Importante — non modificare `config/app.php`**  
> Il default del flag si trova in `config/app.php`, ma **non va toccato**. Quel file fa parte del codice distribuito a tutti gli utenti: cambiarlo romperebbe silenziosamente le installazioni su hosting condivisi (Altervista, Netsons) che dipendono dal valore `true`. La variabile va impostata **solo nel `.env`** della propria installazione.

---

## Passo 1 — Aggiungere il flag nel `.env`

Accedi alla gestione file di Plesk (o via FTP/SSH) e apri il file `.env` nella root del progetto.

Aggiungi o modifica la riga:

```dotenv
SCHEDULE_QUEUE_WORKER=false
```

Salva il file. Questo passo va fatto **prima** di qualsiasi altra operazione.

---

## Passo 2 — Svuotare la cache di configurazione

Dopo aver modificato il `.env`, Laravel deve ricaricare la configurazione e liberare eventuali mutex bloccati. Ci sono due percorsi equivalenti.

### Percorso A — Aggiornamento a v1.10.0-beta.1 (consigliato)

Dal pannello KondoManager vai in **Impostazioni → Aggiornamenti** e installa la versione 1.10.0-beta.1.

Il processo di aggiornamento esegue automaticamente `optimize:clear`, che:
- rilegge il nuovo valore del flag dal `.env`
- libera il mutex di `withoutOverlapping()` eventualmente bloccato da sessioni precedenti interrotte da cron-job.org

Non serve fare nulla a mano.

### Percorso B — Comando manuale (se non si vuole aggiornare)

Esegui dal terminale Plesk (SSH o terminale integrato):

```bash
cd /var/www/vhosts/tuodominio.it/httpdocs && \
/opt/plesk/php/8.4/bin/php artisan optimize:clear
```

> **Perché `optimize:clear` e non solo `config:clear`?**  
> `optimize:clear` esegue in sequenza: `config:clear` + `cache:clear` + `route:clear` + `view:clear`.  
> Il `cache:clear` è fondamentale perché svuota anche il **mutex** di `withoutOverlapping()` che potrebbe essere rimasto bloccato da una precedente chiamata interrotta. Senza questo passaggio il worker sembrerebbe non fare nulla per le successive 24 ore.

---

## Passo 3 — Disattivare cron-job.org esterno

Se stai usando cron-job.org come trigger esterno, **disattivalo**. Su Plesk non serve e genera solo Error 500.

Puoi farlo in due modi:

**A) Dal pannello KondoManager:**  
`Impostazioni → Automazioni → Webhook Esterno → disattiva il toggle`

**B) Direttamente su cron-job.org:**  
Accedi al tuo account su [cron-job.org](https://cron-job.org) e metti in pausa o elimina il job configurato per il tuo dominio.

---

## Passo 4 — Configurare i due cron nativi in Plesk

Accedi a **Plesk → Domini → [tuo dominio] → Attività pianificate** (o cerca "Cron Jobs" nel menu) e crea **due attività separate**, entrambe con frequenza **ogni minuto** (`* * * * *`).

> I due cron restano separati per design — non vanno uniti in un unico task che esegue prima l'uno e poi l'altro. Il perché è spiegato in **Note architetturali** in fondo alla guida.

Usa il tipo di attività **"Esegui uno script PHP"** invece di "Esegui un comando". Plesk invoca l'interprete PHP direttamente e risolve il percorso dello script rispetto alla webspace del dominio, quindi funziona anche se l'utente di sistema ha una shell chrooted — **non serve modificare l'accesso SSH del dominio**, che può restare nella sua configurazione più sicura. Questo è l'unico metodo verificato empiricamente in questa guida.

**Cron 1 — Laravel Scheduler**

| Campo | Valore |
|---|---|
| Tipo attività | Esegui uno script PHP |
| Percorso dello script | `httpdocs/artisan` |
| con argomenti | `schedule:run` |
| Usa versione PHP | la tua versione (es. 8.4.x) |
| Esegui | `* * * * *` |
| Notificare | Solo errori |

**Cron 2 — Queue Worker**

| Campo | Valore |
|---|---|
| Tipo attività | Esegui uno script PHP |
| Percorso dello script | `httpdocs/artisan` |
| con argomenti | `queue:work --stop-when-empty --max-time=55 --tries=3` |
| Usa versione PHP | la tua versione (es. 8.4.x) |
| Esegui | `* * * * *` |
| Notificare | Solo errori |

> **Il percorso è relativo, non assoluto:** `httpdocs/artisan`, non `/var/www/vhosts/tuodominio.it/httpdocs/artisan`. Plesk lo risolve da solo rispetto alla webspace del dominio.
>
> **Non serve alcun `cd` iniziale.** Il file `artisan` di Laravel risolve i propri percorsi (autoloader, bootstrap dell'applicazione) tramite la posizione del file stesso (`__DIR__`), non tramite la cartella di lavoro della shell. Funziona correttamente indipendentemente da dove e come Plesk lo esegue.
>
> **"Solo errori" invece di silenziare tutto:** a differenza di un `>> /dev/null 2>&1`, questa opzione ti avvisa via email solo se in futuro qualcosa smette di funzionare (es. un bug che fa fallire un job), restando silenziosa in condizioni normali. Consigliata anche a regime, non solo in fase di verifica.

**Limite di questo metodo:** non produce un file di log dedicato (niente `storage/logs/worker.log`), perché non passa da una shell con redirect. Per la verifica vedi il Passo 5. Se in futuro serve un log persistente del worker, la via corretta è aggiungere un listener sugli eventi `JobProcessing`/`JobProcessed` di Laravel che scriva su un canale di log dedicato — non un comando shell alternativo non verificato.

---

## Passo 5 — Verificare che tutto funzioni

### Verifica tramite heartbeat (v1.10.0+)

Dalla versione 1.10.0 è disponibile un monitor visivo dello scheduler. Dopo 2–3 minuti dalla configurazione dei cron, vai su:

`Impostazioni → Automazioni → Cron`

Dovresti vedere:

- **Pallino verde + "Attivo"** → lo scheduler gira correttamente
- **Sorgente "system"** → il cron parte da CLI nativo Plesk ✅
- **Sorgente "webhook"** → sta ancora girando da cron-job.org, torna al Passo 3

Se il pallino è rosso dopo 3 minuti, controlla `storage/logs/laravel.log` e l'output dell'ultima esecuzione nelle Attività pianificate di Plesk (vedi sotto).

### Verifica via log ed email

Questo metodo non produce un file di log dedicato. Per verificare che funzioni:

- L'**heartbeat** sopra resta il controllo più affidabile in assoluto.
- Con "Notificare" su **Solo errori**, ricevi un'email automaticamente se un job fallisce.
- Le eccezioni nei job restano comunque registrate da Laravel in `storage/logs/laravel.log`, consultabile via File Manager di Plesk o FTP.

---

## Risoluzione problemi

### L'errore `chrootsh: ... No such file or directory`

Se hai usato il tipo di attività "Esegui un comando" invece di "Esegui uno script PHP" indicato al Passo 4, potresti vedere un output come questo:

```
/usr/local/psa/bin/chrootsh: line 0: cd: /var/www/vhosts/tuodominio.it/httpdocs: No such file or directory
```

La causa è la shell chrooted (sandboxata) del tuo utente di sistema — impostazione predefinita e più sicura su Plesk, che blocca i comandi shell che assumono percorsi assoluti dal filesystem reale.

**Soluzione:** passa al metodo del Passo 4 ("Esegui uno script PHP"). Funziona indipendentemente da questa impostazione, perché Plesk invoca l'interprete PHP direttamente invece di passare dalla shell di login. Non serve modificare l'accesso SSH del dominio.

---

### Il worker non fa nulla nonostante il cron giri

**Causa più probabile:** mutex di `withoutOverlapping()` bloccato da una sessione precedente interrotta da cron-job.org.

**Soluzione:** riesegui `optimize:clear` (Passo 2, Percorso B). Il mutex scade autonomamente dopo 24 ore, ma `cache:clear` lo libera immediatamente.

---

### Error 500 continua a comparire da cron-job.org

Hai dimenticato di disattivare cron-job.org (Passo 3), oppure il flag nel `.env` non è stato salvato correttamente. Verifica:

```bash
grep "SCHEDULE_QUEUE_WORKER" /var/www/vhosts/tuodominio.it/httpdocs/.env
```

Il risultato deve essere:

```
SCHEDULE_QUEUE_WORKER=false
```

Poi riesegui `optimize:clear`.

---

### Il pallino heartbeat rimane rosso dopo la configurazione

Verifica che entrambi i cron siano attivi in Plesk e che il cron dello scheduler (`schedule:run`) abbia già girato almeno una volta. Il pallino diventa verde entro 2–3 minuti dal primo passaggio del cron.

Se rimane rosso, controlla che non ci siano errori PHP nel cron stesso: in Plesk puoi vedere l'output dell'ultima esecuzione nelle Attività pianificate.

---

### `storage/logs/laravel.log` mostra errori di connessione al DB

Un errore comune su Plesk è usare `DB_HOST=localhost` invece di `127.0.0.1`. Prova a sostituirlo nel `.env` e riesegui `optimize:clear`.

---

### Plesk mostra il cron come "eseguito" ma i job non partono mai

Controlla che `QUEUE_CONNECTION` nel `.env` non sia impostato a `sync`:

```bash
grep "QUEUE_CONNECTION" /var/www/vhosts/tuodominio.it/httpdocs/.env
```

Se il risultato è `QUEUE_CONNECTION=sync`, i job vengono eseguiti inline al momento della dispatch e `queue:work` non ha nulla da processare. Il valore corretto è:

```dotenv
QUEUE_CONNECTION=database
```

Dopo la modifica, riesegui `optimize:clear`.

---

### Vuoi ri-abilitare la config cache per le performance

Una volta verificato che tutto funziona, puoi riportare la config in cache:

```bash
cd /var/www/vhosts/tuodominio.it/httpdocs && \
/opt/plesk/php/8.4/bin/php artisan config:cache
```

> A questo punto `SCHEDULE_QUEUE_WORKER=false` è già nel `.env`, quindi viene cachato correttamente. Non farlo prima di aver verificato che il flag sia sul valore giusto.

---

## Riepilogo rapido

| Passo | Azione |
|---|---|
| 1 | `.env` → `SCHEDULE_QUEUE_WORKER=false` |
| 2A | Aggiornamento a v1.10.0-beta.1 (gestisce `optimize:clear` in automatico) |
| 2B | oppure: `php artisan optimize:clear` da terminale |
| 3 | Disattiva cron-job.org |
| 4a | Plesk: "Esegui uno script PHP" → `httpdocs/artisan`, argomenti `schedule:run`, ogni minuto |
| 4b | Plesk: "Esegui uno script PHP" → `httpdocs/artisan`, argomenti `queue:work --stop-when-empty --max-time=55 --tries=3`, ogni minuto |
| 5 | Verifica: pallino verde + sorgente "system" in Impostazioni → Automazioni |

---

## Note architetturali

Questa configurazione è quella **nativa di Laravel su server con accesso CLI**: due processi indipendenti, nessun blocco incrociato, nessun timeout web. Gli altri utenti su hosting senza CLI (Altervista, Netsons) continuano a usare il flag a `true` con cron-job.org senza alcuna modifica — l'architettura di KondoManager supporta entrambi gli scenari tramite la stessa variabile d'ambiente.

### Perché due cron separati e non uno solo

`schedule:run` e `queue:work` hanno profili di esecuzione opposti per design:

- `schedule:run` deve essere quasi istantaneo — controlla quali task sono dovuti in quel minuto e li lancia. È lui che scrive anche l'heartbeat.
- `queue:work --stop-when-empty --max-time=55` è pensato per girare fino a 55 secondi, processando la coda finché non la svuota o scade il tempo.

Se venissero uniti in un'unica invocazione (uno script che fa prima l'uno e poi l'altro, o la vecchia closure `Schedule::call()` che l'app usava di default), lo scheduler resterebbe bloccato ad aspettare il worker per fino a 55 secondi — esattamente il problema che ha reso necessaria questa guida in origine: mutex di `withoutOverlapping()` che rimane incastrato, heartbeat che salta un giro, ed Error 500 se fosse ancora agganciato a cron-job.org.

Tenerli separati dà anche isolamento dei guasti: se il worker va in crash per un job che fallisce, non si porta dietro lo scheduler — l'heartbeat continua a battere, il pruning e il controllo aggiornamenti continuano a girare normalmente.

Non è una particolarità di Plesk: su VPS con Supervisor lo stesso risultato si ottiene con due **processi persistenti** (`laravel-scheduler` e `laravel-worker` in `supervisord.conf`) che girano per sempre. Su Plesk, senza un process manager che tenga vivo un demone tra un minuto e l'altro, la stessa separazione logica si ottiene con due **cron che si ri-lanciano ogni minuto** invece di due processi always-on. Stessa architettura, forma diversa perché manca Supervisor.

Sul costo delle risorse: `schedule:run` impegna il server per una frazione di secondo quando non ci sono task dovuti in quel minuto; `queue:work --stop-when-empty` si chiude da solo appena la coda è vuota. Due cron al minuto qui sono leggeri, non ridondanti.

---

*Documentazione KondoManager — [forum.kondomanager.com](https://forum.kondomanager.com)*
