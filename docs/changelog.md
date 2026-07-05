# Changelog

Tutte le modifiche rilevanti a Kondomanager sono documentate in questo file.

Il formato segue [Keep a Changelog](https://keepachangelog.com/it/1.0.0/)
e il progetto adotta il [Versionamento Semantico](https://semver.org/lang/it/).

---

## [1.10.0-beta.7] - Hardening Fatture Passive

### Corretto

- **Crash immediato in Modifica Fattura Passiva:** `FatturaPassivaController::edit()` non passava sette prop calcolate invece da `create()` (`fornitori`, `esercizi`, `debiti_patrimoniali`, `fatture_pregresse_registrate`, `fondi_riserva`, `capienza_rata_zero`, `incassato_rata_zero`), e passava una versione ridotta di `conti`/`banche` priva dei dati di budget/saldo. Il componente Vue le dichiara tutte come prop obbligatorie e un `watch(..., { immediate: true })` vi accede senza controllo — più grave del caso analogo già corretto per i pagamenti fornitori (beta.6), perché qui la condizione di guardia è sempre vera per una fattura esistente (ha già `fornitore_id` e `data_documento` popolati), quindi il crash si verificava **incondizionatamente** al solo apertura della pagina, non al primo carattere digitato. Corretto estraendo il calcolo del contesto budget (già duplicato tra `create()` ed `edit()`) in un unico metodo condiviso `prepareContestoBudget()`, usato da entrambi.
- **`stato_pagamento = 'stornata'` mandava in crash qualunque lettura successiva della fattura:** lo storno (`StornoFatturaController`) scrive `'stornata'` sulla colonna `stato_pagamento`, ma l'enum PHP `StatoPagamentoFattura` (a cui il campo è castato) prevedeva solo `APERTA`/`PARZIALE`/`PAGATA` — un valore non valido per l'enum. La colonna è una semplice `VARCHAR`, quindi la scrittura su database riusciva silenziosamente; l'errore (`ValueError: "stornata" is not a valid backing value`) scattava solo alla lettura successiva, cioè su **qualunque** accesso a una fattura già stornata (elenco, dettaglio, modifica). Il frontend (badge, filtri in `DataTableRowActions.vue`) si aspettava già questo valore da tempo — mancava solo il case nell'enum. Aggiunto `STORNATA` con le relative label/colore badge.
- **Modifica di una fattura non modificabile (stornata, esercizio chiuso, pregressa, sopravvenienza, sforo motivato, piano rate straordinario) "riusciva" in apparenza:** `aggiornaFattura()` blocca correttamente questi casi lanciando `FatturaModificaVietataException`, ma il controller la traduceva con `back()->with(flashError(...))` invece di `back()->withErrors([...])` — stesso bug già corretto per i pagamenti fornitori in beta.6. Per Inertia un redirect senza errori di validazione è sempre "successo": il salvataggio falliva in silenzio senza che l'amministratore se ne accorgesse. Corretto su due livelli: la guardia di blocco è ora un unico metodo `motivoBloccoModifica()` condiviso tra `edit()` e `aggiornaFattura()` (invece di essere duplicata), `edit()` reindirizza subito al dettaglio con un messaggio esplicito prima di mostrare il form se la fattura non è modificabile, e l'eccezione usa `withErrors(['modifica_vietata' => ...])` per tutti i casi bloccati durante il salvataggio. Aggiunta anche la visualizzazione dei messaggi flash nella pagina di dettaglio fattura, che finora non ne mostrava nessuno.
- **Invio email dalla configurazione SMTP del pannello (Impostazioni > Mail) falliva silenziosamente con alcuni provider quando si selezionava la cifratura TLS:** `MailConfigServiceProvider::applySmtpConfig()` ricostruiva l'array `mail.mailers.smtp` da zero omettendo la chiave `local_domain` (hostname usato nel comando `EHLO`/`HELO`), presente invece nella configurazione letta da `.env`. Senza di essa Symfony Mailer usa il fallback letterale `[127.0.0.1]`, che molti server SMTP di hosting condivisi rifiutano come pattern tipico di relay malconfigurato — da qui l'invio fallito, apparentemente legato alla sola scelta della cifratura ma in realtà dovuto al passaggio dalla configurazione `.env` a quella da database. Il campo "crittografia" del pannello, inoltre, non aveva mai avuto alcun effetto reale: Laravel 13 decide TLS/SSL tramite la chiave `scheme`, non `encryption` (che nessun mailer di Laravel legge più). Corretto ripristinando `local_domain` in `applySmtpConfig()` e mappando la selezione SSL su `scheme => 'smtps'`, sia nella configurazione a runtime sia nel test di connessione da pannello (`MailSettingsController::testConnection()`).

---

## [1.10.0-beta.6] - Hardening Pagamenti Fornitori & Mobile UX

### Corretto

- **Crash immediato in Modifica Pagamento Fornitore digitando l'IBAN Beneficiario:** `PagamentoFornitoreController::edit()` non passava la prop `fornitori` al componente Vue (a differenza di `create()`), mentre il computed `selectedFornitore` vi accedeva senza controllo. Finché il campo IBAN restava vuoto il computed `ibanDiscrepanza` andava in corto circuito ed evitava il crash; al primo carattere digitato, il computed tentava di leggere `fornitori.find(...)` su `undefined` e mandava in errore l'intero render — senza alcuna riga in `laravel.log`, trattandosi di un `TypeError` client-side che non arriva mai al server.
- **Bonifico Parlante, Tipo Detrazione e Commissioni Bancarie non venivano salvati in modifica:** `UpdatePagamentoFornitoreRequest` non validava questi tre campi, che Laravel scartava silenziosamente prima che raggiungessero il service. Per le commissioni il problema era più profondo: anche a validazione corretta, `PagamentoFornitoreService::aggiornaPagamento()` non ricreava mai la riga contabile "Spese Bancarie" né aggiornava l'uscita di cassa di conseguenza (a differenza di `registraPagamento()`), quindi la funzionalità in modifica non era mai stata implementata, non solo dimenticata in validazione.
- **Pagamenti stornati raggiungibili — e apparentemente "salvabili con successo" — dalla pagina di modifica:** `aggiornaPagamento()` blocca correttamente (per design) la modifica di un pagamento stornato, ma lo faceva con un `back()->with(flashError(...))` invece di `back()->withErrors([...])` come le altre eccezioni dello stesso metodo. Per Inertia un redirect senza errori di validazione è sempre "successo": il salvataggio falliva in silenzio mentre l'amministratore vedeva comunque la modale "Pagamento registrato". Corretto su tre livelli: `edit()` ora reindirizza subito al dettaglio con un messaggio esplicito se il pagamento è stornato (senza mai mostrare il form), l'eccezione usa `withErrors(['modifica_vietata' => ...])` per tutti gli altri casi (esercizio chiuso, ecc.), e la pagina di dettaglio pagamento mostrava comunque il messaggio flash.
- **Pagamenti cumulativi (più fatture) potenzialmente corrotti se modificati:** un commento nel codice dichiarava "multi-fattura non modificabile", ma nulla lo impediva realmente — `aggiornaPagamento()` riassegnava l'intero importo netto a *ciascuna* fattura collegata invece di distribuirlo proporzionalmente. Aggiunto un guard esplicito che blocca la modifica di un pagamento su più fatture, invitando a usare lo storno.
- **Nessun controllo di capienza conto in modifica pagamento:** la modale "Saldo Conto Insufficiente" (con nota obbligatoria per bypassare) era collegata solo in creazione — `aggiornaPagamento()` non chiamava mai la validazione corrispondente, quindi una modifica poteva silenziosamente portare un conto in scoperto senza richiedere alcuna giustificazione. Il controllo ora scatta solo quando la modifica *peggiora* l'esposizione (aumento importo o cambio conto): un edit che lascia invariato o riduce l'importo passa comunque, anche se il conto è già in uno scoperto approvato in precedenza.
- **Layout di Modifica Pagamento Fornitore non responsive su mobile:** le righe a doppia colonna (Fornitore/Conto di Addebito, Data/Commissioni) usavano una griglia fissa che a larghezze da smartphone comprimeva i campi fino a sovrapporli. Ora impilano su una sola colonna sotto il breakpoint `sm`.

### Migliorato

- **Bonifico Parlante visibile solo per pagamenti in Bonifico:** la detrazione fiscale (art. 16-bis TUIR e normative collegate per ecobonus/sismabonus/superbonus) richiede per legge un bonifico bancario tracciabile — contanti e assegni non sono ammessi al beneficio. La sezione "Bonifico Parlante" ora compare solo quando il metodo di pagamento è Bonifico, sia in Nuovo Pagamento sia in Modifica, con reset automatico di detrazione/flag cambiando metodo.
- **Layout di Modifica Pagamento Fornitore ridisegnato a piena larghezza:** rimossa la guida introduttiva ridondante in testa alla pagina e il vincolo di larghezza che lasciava vuoti i due terzi dello schermo (retaggio della colonna destra rimossa in una beta precedente); i campi correlati sono ora affiancati a coppie, coerentemente con le altre pagine del gestionale (es. Risorse e Fondi).
- **Pulsante "Salva Modifiche" ridimensionato:** sostituita la CTA verde a piena larghezza con un pulsante compatto allineato a destra (+ link "Annulla"), come nelle altre pagine di modifica dell'app.

---

## [1.10.0-beta.5] - Installer Nativo

### Aggiunto

- **Installer Nativo KondoManager:** Sostituito interamente il pacchetto `eii/laravel-installer` con un wizard di installazione proprio, sotto `App\Livewire\Installer\*` — nessuna dipendenza esterna, nessun alias Livewire. Il vincolo di checksum che costringeva a usare la classe originale del vendor durante la primissima installazione (documentato nelle beta precedenti) non esiste più: un solo `InstallerWizard`, referenziato per nome-classe diretto nelle routes, gira identico sia alla prima installazione sia rientrando nel wizard.
- **Nuova grafica:** Tema scuro con card bianca, badge "Km" come unico marchio in header (coerente con `AppLogo.vue`, dove la scritta testuale accanto al badge è anch'essa assente), tooltip esplicativo su ogni campo del form, select personalizzati con freccia custom, loader più visibile e descrittivo tra uno step e l'altro (in particolare durante la configurazione del database). Interfaccia del wizard interamente in italiano/inglese, rilevata automaticamente dall'header `Accept-Language` del browser — concetto distinto dalla lingua scelta per l'app installata (`available_locales`), che resta configurabile come già introdotto in beta.4.
- **Layout compatto senza scroll:** Card e spaziature ridotte, campi "Nome applicazione/URL/Lingua" riorganizzati in griglia (prima impilati a piena larghezza) e intestazioni di sezione ridondanti rimosse, così l'intera pagina di ogni step (compreso Ambiente, il più denso di campi) è visibile senza scorrimento verticale sulle risoluzioni desktop comuni.
- **Testo di benvenuto rivisto:** Unificati i tre paragrafi introduttivi dello step Benvenuto (promemoria credenziali, scopo del wizard, requisiti minimi) in un messaggio di benvenuto coeso e più caloroso.
- **Mini-guida per ogni step:** Sotto il titolo di ogni pagina del wizard è stata aggiunta una breve descrizione di cosa fare in quello step. Nella pagina finale la guida ricorda esplicitamente di configurare il cronjob sul server, senza il quale i processi in background (emissione rate, promemoria, notifiche email) non funzionano.
- **Logo nella sidebar:** Il badge "Km" è stato spostato dall'header esterno alla sidebar dello stepper (sopra il primo step) con nome "Kondomanager" e sottotitolo, racchiusi in un riquadro con sfondo tenue per separarlo visivamente dagli step senza usare una linea divisoria.
- **Traduzione "Applicazione e database":** Rinominato lo step "Impostazioni ambientali" (traduzione letterale poco naturale di "environment settings") in "Applicazione e database", più chiaro e coerente col contenuto reale dello step (nome app, lingua, credenziali database).
- **Requisiti server in griglia:** Estensioni PHP e permessi cartelle mostrati su due colonne invece che in un unico elenco verticale, eliminando lo scroll residuo su questa pagina.
- **Feedback di caricamento sui pulsanti:** I pulsanti Avanti/Salta/Fine mostrano uno spinner e si disabilitano durante l'elaborazione dello step, evitando doppi invii accidentali (utile in particolare nello step Ambiente, che esegue `migrate:fresh`).
- **Pulsante "Indietro":** Aggiunta la navigazione allo step precedente (assente finora — il wizard procedeva solo in avanti), nascosta nella pagina finale dato che a quel punto l'installazione è già stata bloccata (lock file scritto).
- **Test connessione database:** Nello step Applicazione e database, un pulsante "Testa connessione" verifica host/porta/credenziali PRIMA di premere "Avanti" (che esegue `migrate:fresh`), usando una connessione DB dedicata e isolata (`installer_test`) così un test fallito non lascia stato sporco sulla connessione `mysql` reale.
- **Mostra/nascondi password:** Nuovo componente `<x-installer.password-input>` con icona a forma di occhio, applicato ai campi password di database, posta e amministratore — riduce gli errori di battitura su credenziali che altrimenti restano invisibili.
- **Ricontrolla requisiti server:** Nella pagina Requisiti server, un pulsante "Recheck" (con orario dell'ultimo controllo) permette di rieseguire il controllo PHP/estensioni/permessi senza ricaricare la pagina — utile se si risolve un requisito mancante a metà installazione.
- **Guida cronjob dettagliata su Finish:** Nella pagina finale, tab per cron-job.org/cPanel/Plesk-VPS (adattate dalla guida già presente nel pannello Impostazioni) con comandi pronti da copiare — invece di rifare l'intero step dedicato con test/skip, valutato eccessivo dato che il controllo esiste già post-login in Impostazioni > Cron.

### Corretto (grafica)

- **Pulsanti di test che si restringevano:** I pulsanti "Testa connessione"/"Invia email di test" non avevano `shrink-0`, quindi si comprimevano nel container flex quando il messaggio di esito accanto era lungo. Aggiunto `shrink-0` al pulsante e `min-w-0` al messaggio.
- **Test configurazione SMTP:** Nello step Posta è stato aggiunto un pulsante "Invia email di test" che tenta un invio reale con le credenziali appena inserite (senza scriverle su `.env`), mostrando subito se la configurazione funziona o l'errore di connessione/autenticazione restituito dal server SMTP.

### Sicurezza

- **Password amministratore mai su disco:** Lo step di creazione amministratore non include più la password in chiaro nel payload salvato nel progress file (nemmeno temporaneamente) — la redazione introdotta in beta.4 per lo step Finish resta come seconda barriera di sicurezza.

### Corretto

Bug reali emersi durante il porting e il test end-to-end dal vivo (non presenti nei singoli step testati isolatamente in precedenza):

- **Loop di redirect infinito tra gli step Posta e Amministratore:** Il wizard riusava per errore la stessa chiave di progress (`raw_env_data`) sia per lo step Ambiente sia per lo step Posta. Al termine dello step Posta (anche solo saltandolo), il wizard interpretava erroneamente quel marker come dati dello step Ambiente, li sovrascriveva e rimandava sempre indietro allo step Posta — bloccando per sempre il proseguimento verso Crea Amministratore.
- **Eccezione quando lo step Posta veniva saltato:** `MailSettings::completeStep()` chiamava `$this->validate()` incondizionatamente anche quando lo step non prevede regole (mail non richiesta), causando un errore Livewire (`MissingRulesException`) invece di procedere.
- **Lingua di default del campo Ambiente contaminata dalla lingua del wizard:** Il campo "Lingua" nello step Ambiente leggeva `config('app.locale')`, che nel frattempo la nuova rilevazione automatica della lingua del wizard (`App::setLocale()`) aveva già sovrascritto — mostrando come preselezionata la lingua del browser invece del valore reale in `.env`. Corretto leggendo `env('APP_LOCALE')` direttamente.
- **Checkmark permessi sempre verde:** Il display dei permessi server nello step Requisiti mostrava sempre l'icona di successo indipendentemente dall'esito reale del controllo (confrontava un array con un booleano). Ora riflette correttamente `exists` e `writable`.
- **Messaggi di validazione illeggibili:** Errori come "The db database field is required" sono stati sostituiti con le etichette tradotte dei campi ("The Database field is required") su tutti gli step con validazione (Ambiente, Posta, Amministratore). Anche il messaggio generico "formato non valido" sui campi database (host/porta/nome/utente) è stato sostituito con un messaggio esplicito ("non può contenere spazi").

### Corretto (test su hosting reale — Altervista, vhosting-it)

Bug emersi testando l'installer per davvero su hosting condiviso, non riproducibili in locale:

- **Errori di validazione bloccati in modo permanente + doppio click:** correggere un campo dopo un errore di validazione non aggiornava mai il server — l'errore restava visualizzato anche con un valore corretto finché non si premeva di nuovo un pulsante (es. "Avanti"), da cui il "serve un doppio click". Causa reale (trovata leggendo il sorgente JS di Livewire 4, non un bug di "morph" come ipotizzato inizialmente): in Livewire 4 il modificatore `.blur` **da solo non invia mai la richiesta al server** (`shouldSendNetwork` risulta sempre `false` senza `.live` — cambio di comportamento rispetto a Livewire 3, dove `wire:model.blur` era già sufficiente). Serve la combinazione `wire:model.live.blur`. Corretto su tutti i campi del wizard (Ambiente, Posta, Amministratore) — ripristinata la pulizia immediata dell'errore alla correzione del campo, senza bisogno di un secondo click.
- **Step Posta: mancava il campo Cifratura (TLS/SSL):** il form del wizard non aveva un campo per l'encryption SMTP, mentre l'app reale (Impostazioni > Mail) sì — causava "errore di connessione" nel test dell'installer anche con credenziali corrette, perché la richiesta partiva senza cifratura esplicita. Aggiunto il campo "Cifratura" (TLS/SSL/Nessuna), applicato sia al test email sia al salvataggio.
- **Campo Cifratura non aveva alcun effetto:** leggendo `Illuminate\Mail\MailManager::createSmtpTransport()` si scopre che Laravel legge solo la chiave di configurazione `scheme`, mai `encryption` — quindi né il valore scritto dall'installer né quello scritto da Impostazioni > Mail (`App\Settings\MailSettings::$mail_encryption`) avevano mai avuto effetto sulla cifratura reale. Corretto scrivendo `MAIL_SCHEME` (`smtps` per SSL, vuoto per TLS/auto-detect in base alla porta) sia nel test email sia in `.env`. *(Nota: non scriviamo più la configurazione mail anche su database — verificato che la pagina Impostazioni > Mail mostra già un badge "Configurazione .env" quando la mail è attiva solo via `.env`, quindi l'amministratore è comunque informato correttamente senza bisogno di duplicare la scrittura.)*
- **Test email: nessun destinatario dedicato:** il test inviava sempre all'indirizzo mittente configurato, impedendo di verificare la consegna reale su un indirizzo esterno. Aggiunto un campo "Invia email di prova a" separato, come già presente nel pannello Impostazioni dell'app.
- **`touch(): Utime failed: Operation not permitted` su Altervista:** hosting molto restrittivi negano la modifica dell'orario di modifica su file già esistenti. Laravel chiama internamente `touch()` come pura ottimizzazione della cache delle view compilate (Blade/Livewire) — se fallisce, il warning PHP viene convertito in eccezione fatale da Laravel, anche se la cache compilata resta valida. Aggiunto un gestore errori dedicato in `AppServiceProvider` che sopprime solo questo avviso specifico, lasciando invariata la gestione di tutti gli altri errori.
- **Grafica assente dopo il redirect su Altervista:** causa più probabile il bug `touch()` sopra — l'eccezione fatale durante la compilazione delle view mostra la pagina di errore generica di Laravel invece della pagina installer, dando l'impressione di "nessuna grafica". Individuato anche un meccanismo correlato da tenere presente per il futuro: `AppServiceProvider` ha un fix preesistente (`URL::forceScheme('https')`, per reverse proxy come Cloudflare) che forza tutti gli asset in HTTPS se `APP_URL` inizia per `https://` — innocuo se l'HTTPS sul dominio è realmente attivo (verificabile dal pannello di controllo dell'hosting), ma causerebbe lo stesso sintomo se configurato prima che il certificato sia pronto.
- **Spinner di caricamento invisibile sui pulsanti Avanti/Salta/Fine:** lo spinner e il testo "Attendere..." erano vincolati con `wire:target` alla sola azione del pulsante stesso (es. `completeStep`), quindi durante un salvataggio "on blur" di un campo (frequente da quando si usa `.live.blur`) il pulsante si disabilitava correttamente ma restava visivamente invariato — su connessioni lente dava l'impressione di un pulsante "bloccato" invece che in caricamento. Rimosso il vincolo `wire:target`: ora spinner e testo reagiscono a qualsiasi richiesta Livewire in corso. Confermato su hosting reale (vhosting-it) che questo risolve anche la segnalazione "il pulsante Salta non risponde".
- **Scroll residuo nello step Posta:** rimossa l'intestazione di sezione "Mail" (ridondante col titolo della pagina) e il relativo margine superiore, guadagnando spazio verticale sufficiente a eliminare lo scroll interno anche su schermi più bassi.
- **Richieste Livewire bloccate da CORS su Altervista ("i pulsanti non rispondono"):** causa reale, individuata dal log della console del browser (`Cross-Origin Request Blocked... reading the remote resource at https://.../livewire-xxx/update`, status 200 ma lettura bloccata): il fix `URL::forceScheme('https')` in `AppServiceProvider` forzava lo schema di **tutti** gli URL generati da Laravel (inclusi gli endpoint Livewire) a `https` solo perché `APP_URL` nel `.env` conteneva `https://`, indipendentemente dallo schema con cui la richiesta corrente veniva effettivamente servita. Quando per un qualsiasi motivo (proxy che inoltra `X-Forwarded-Proto: http`, redirect intermedio, hosting che non termina ancora davvero in HTTPS) la pagina risultava servita in `http`, gli endpoint Livewire restavano comunque forzati in `https` — uno schema diverso da quello della pagina che li chiama è, per il browser, un'origine diversa, quindi la richiesta viene bloccata come cross-origin pur rispondendo 200. Corretto condizionando il fix a `request()->isSecure()` (o al contesto console, per email/job in coda dove non esiste una request da cui rilevare lo schema reale): non si forza più uno schema in contraddizione con quello della richiesta corrente in atto. Confermato su hosting reale (Altervista) dopo il fix: redirect e caricamento CSS/grafica corretti end-to-end.
- **Rimosso il pulsante "Indietro":** tornare a uno step precedente permetteva di rientrare nello step Ambiente e ripremere "Avanti", che riesegue incondizionatamente `migrate:fresh` — un comportamento a sorpresa non ovvio dall'interfaccia. Rimossi il pulsante, il metodo `InstallerWizard::previousStep()` e la voce di traduzione `actions.back`, ormai inutilizzata.
- **Pagina Fine più compatta e titolo più caloroso:** ridotti margini e padding per eliminare lo scroll verticale residuo; il titolo passa da "Installazione completata!" a "Complimenti, installazione completata!" (e equivalente inglese).
- **Footer "Powered by Kondomanager":** aggiunto sia nel wizard Livewire (in fondo alla sidebar, in un riquadro grigio arrotondato che fa da specchio a quello del badge in alto) sia in `index.php`/`installer_php84.php` (in fondo alla card, stile coerente col resto del file) — nome prodotto e licenza AGPL-3.0 sempre visibili, con link al sito e alla repo GitHub.
- **Testo "Ready. Please select an action." rimosso** da `index.php`/`installer_php84.php`: non aggiungeva informazione, si passa direttamente dall'elenco dei requisiti al pulsante di avvio.

### Corretto (individuati rileggendo un `.env` reale generato su Altervista)

- **Duplicazione (solo cosmetica, verificata) di `DB_CHARSET`/`DB_COLLATION`/`DB_ENGINE`/`TRUSTED_PROXIES` in `.env`:** l'"Adaptive Env Injection" di `index.php`/`installer_php84.php` faceva un append cieco di queste righe in fondo al file, lasciando intatto il placeholder commentato già presente in `.env.example` — non causa un bug funzionale (in un `.env` vince sempre l'ultima occorrenza di una chiave, verificato con un test diretto), ma rende il file confuso da leggere ed è un pattern fragile. Aggiunta una funzione `setEnvValue()` che scommenta/sostituisce la riga esistente sul posto (stesso principio già usato in `InstallerWizard::updateEnvSettings()`), eliminando la duplicazione.
- **`MAIL_MAILER` forzato a `smtp` anche saltando lo step Posta:** saltando lo step, `InstallerWizard::saveStep()` chiamava comunque `runMailSetup([])` con dati vuoti; il fallback `$data['mail_mailer'] ?? 'smtp'` cambiava il mailer da `log` (il default sicuro di `.env.example`, che si limita a loggare le email) a `smtp` senza però impostare host/porta/credenziali — un mailer "attivo" ma non funzionante, peggio del semplice logging. Corretto: `runMailSetup()` viene chiamato solo se lo step è stato davvero compilato, non saltato.
- **Corruzione silenziosa di credenziali contenenti `$` seguito da una cifra:** `updateEnvSettings()`/`updateMailSettings()` scrivevano i valori su `.env` con `preg_replace($pattern, "{$key}={$value}", $env)` — in PHP, nella stringa di sostituzione di `preg_replace()`, `$` + cifra ha significato speciale di backreference. Riprodotto: una password come `Pass$1word` veniva scritta su `.env` come `Password` (il `$1` interpretato come backreference e rimosso). La connessione dal vivo durante l'installazione non risente del problema (usa il valore originale via `config()`), ma richieste successive che rileggono il `.env` userebbero la versione corrotta. Corretto sostituendo `preg_replace` con `preg_replace_callback` in entrambe le funzioni, che non interpreta `$` nella stringa restituita dalla callback.

### Rimosso

- **Dipendenza `eii/laravel-installer`:** Rimossa da `composer.json`. `livewire/livewire` (che era installato solo transitivamente tramite il pacchetto rimosso) è ora dichiarato come dipendenza diretta. Rimossi anche i file pubblicati orfani (`resources/views/vendor/installer/`, `public/vendor/installer/`).

### Test

- Suite completa (239 test) verificata a ogni fase.
- Test end-to-end reale contro un database MySQL dedicato e temporaneo (creato ed eliminato per l'occasione, senza toccare il database di sviluppo): `migrate:fresh`, seeding, sincronizzazione lingua/nome applicazione/versione, creazione amministratore con ruolo Spatie, redazione password nel riepilogo finale — verificato tutto funzionante end-to-end.

---

## [1.10.0-beta.4] - Aggiornamento Piattaforma (Laravel 13) & Identità Personalizzabile

### Aggiornamento Piattaforma

- **Laravel 13, Inertia 3 & Livewire 4:** Aggiornate le dipendenze core del framework (`laravel/framework` ^13.0, `inertiajs/inertia-laravel` / `@inertiajs/vue3` ^3.0, `livewire/livewire` 4.3, `vite` ^8.0, `pestphp/pest` ^4.0). Verificato l'intero frontend Inertia/Vue con test manuali estensivi su tutte le pagine.

### Aggiunto

- **Selezione Lingua in Installazione:** Il wizard di installazione permette ora di scegliere la lingua principale dell'applicazione (Italiano, Inglese, Spagnolo, Portoghese) direttamente dallo step "Impostazioni ambientali", invece di dover passare successivamente da Impostazioni Generali.
- **Nome Applicazione Personalizzabile da Pannello:** Il nome dell'applicazione (mostrato nella scheda del browser e come mittente nelle email transazionali) è ora modificabile in qualsiasi momento da Impostazioni Generali, non solo in fase di installazione. Il valore è salvato in `GeneralSettings->app_name` e applicato a runtime tramite `SetAppNameMiddleware` (richieste web) e un hook `Queue::before()` (job in coda / notifiche email), lo stesso meccanismo già usato per la lingua.

### Sicurezza

- **Redazione Credenziali nello step Finish dell'Installer:** Il componente vendor `eii/laravel-installer` esponeva le password in chiaro (admin, database, SMTP) sia nello snapshot Livewire incluso nell'HTML della pagina di riepilogo finale sia nel file `.txt` scaricabile. Introdotto `App\Livewire\Installer\Finish` che redige questi campi subito dopo il caricamento, prima che vengano serializzati.

### Corretto

- **Nome App / Lingua Ignorati in Prima Installazione Pulita:** I campi "Nome applicazione" e "Lingua" raccolti dal wizard non avevano alcun effetto in una prima installazione pulita (funzionavano solo negli aggiornamenti), perché il wizard che li scrive su `.env` viene sostituito dalla versione originale del vendor per un vincolo di checksum Livewire. Il valore scelto viene ora salvato in un marker nel progress file e applicato a `GeneralSettings` (lingua, nome app) subito dopo `migrate:fresh`, tramite il listener `MigrationsEnded` già usato per sincronizzare la versione — funziona quindi indipendentemente da quale wizard esegue l'installazione.
- **Cache Vista Blade Obsoleta / Attributo `<title>` Deprecato:** Risolti due problemi emersi dall'aggiornamento a Inertia 3: una cache di vista compilata precedente all'upgrade causava pagine bianche su tutte le rotte tranne "/"; l'attributo `<title inertia>` in `app.blade.php`, deprecato dalla nuova major, è stato aggiornato a `<title data-inertia>`.

### Rimosso

- **Campi Email Morti nello step Ambiente dell'Installer:** Rimossa da `FixedEnvironmentSettings` la raccolta di campi SMTP mai effettivamente scritti su `.env` né mai esposti nella vista pubblicata — la configurazione email resta affidata al suo step dedicato ("Impostazioni di posta").

---

## [1.10.0-beta.3] - Riparto Penny-Perfect, Hardening Installer & Requisito Minimo PHP 8.4

### Corretto
- **Discrepanza Centesimale nel Riparto per Tabella (Fase 1):** Risolto lo spostamento di centesimi tra le colonne del documento "Riparto Bilancio per Tabella × Soggetto" (`RipartoTabelleService::buildMatrice`). Le tabelle vengono ora ordinate per peso crescente prima della distribuzione penny-perfect, così il resto dell'arrotondamento va sempre alla tabella con il peso maggiore (dove lo scostamento è proporzionalmente irrilevante) invece che a una tabella arbitraria determinata dall'ordine di inserimento nel piano dei conti. Analisi completa, causa meccanica e roadmap della Fase 2 (approccio column-first, non ancora avviata) documentate in `docs/ripartotabelle_discrepanza_centesimale.md`.
- **Duplicazione Variabili Database nel Wizard di Installazione:** Corretta la regex in `InstallerWizard::updateEnvSettings()` / `updateMailSettings()` che non riconosceva le righe placeholder commentate (`# DB_HOST=...`) ereditate da `.env.example`, causando l'aggiunta di una riga duplicata invece della sostituzione in-place quando l'amministratore inseriva le credenziali reali del database durante l'installazione.

### Hardening
- **Requisito Minimo elevato a PHP 8.4:** `composer.json` e `config/installer.php` dichiarano ora `^8.4` / `8.4.0` come versione minima richiesta, allineati al nuovo target della piattaforma. Aggiornati anche `.env.example` (worker di coda per hosting condivisi, credenziali database non più commentate per evitare la duplicazione sopra) e i riferimenti PHP nella documentazione (README, roadmap).
- **Worker Coda su Hosting Condivisi:** Aggiunta la variabile `SCHEDULE_QUEUE_WORKER` a `.env.example`, documentando esplicitamente quando lo scheduler deve processare la coda in modalità sincrona (hosting condivisi senza Supervisor) rispetto a un worker dedicato (VPS/Plesk).

> **Nota di rilascio:** l'aggiornamento delle dipendenze Composer (`composer update` per allineare `composer.lock` alla nuova piattaforma PHP 8.4) è pianificato subito prima della pubblicazione di questa beta.

---

## [1.10.0-beta.2] - Estratto Conto Anagrafica PDF, Mobile UX & Breadcrumbs Dinamiche

### Aggiunto
- **Stampa Estratto Conto Anagrafica (PDF):** Aggiunta la generazione del nuovo documento ufficiale di "Estratto Conto" per singolo condòmino, richiamabile dalla vista Piani Rate. Il PDF include un'intestazione premium, la lista delle unità immobiliari associate, un cruscotto riepilogativo dei saldi (iniziale, addebiti, versamenti, saldo finale) e una tabella cronologica (timeline) dettagliata e formattata con tutti i movimenti e operazioni registrati per l'anagrafica nell'esercizio di riferimento, applicando il motore a partita doppia "penny-perfect" per il calcolo del saldo progressivo.
- **Burger Menu Intelligente su PageHeaderGuide:** Migliorata drasticamente la UX mobile del gestionale introducendo la modalità "Burger Menu" (Menu a tendina) nell'header di pagina standard. Su smartphone, i bottoni d'azione in esubero (es. Indietro, Pulsanti Guida) vengono automaticamente collassati sotto un unico pulsante espandibile "Opzioni", prevenendo overflow orizzontali e ammassamenti indesiderati di pulsanti.

### Corretto
- **Fix Ritorno Dinamico Breadcrumb:** Sostituita la precedente logica di generazione statica del link "Torna al Piano Rate" all'interno della vista *Estratto Conto Anagrafica*. Il sistema intercetta la cronologia d'accesso (tramite Inertia `window.history.state.back`) restituendo sempre il condòmino al piano corretto (generale o straordinario) da cui era partito, estirpando definitivamente falsi errori 404 in scenari multi-contesto.
- **Risoluzione Tipografica PDF:** Uniformata la formattazione dei campi contabili nel layout PDF del documento di Estratto Conto. Il simbolo della valuta (€) viene ora coerentemente stampato davanti all'importo in tutte le colonne (Dare, Avere, Saldi intermedi) anziché appeso alla fine.
- **Formattazione Piè di Pagina nei PDF:** Risolto un problema di rendering per cui gli "a capo" (invio) e gli spazi multipli inseriti nella "Nota Legale / Footer" non venivano rispettati nei PDF generati. La formattazione ora ricalca fedelmente l'input utente.

---

## [1.10.0-beta.1] - Piani Straordinari Misti, Paginazione Stampe PDF e Logica "Catch-all"

### Aggiunto
- **Pannello Impostazioni Cron & Heartbeat:** Aggiunta una nuova vista dedicata (`ImpostazioniCron.vue`) sotto `Impostazioni > Sistema > Automazioni`. La pagina espone un widget visivo "Heartbeat / Cron System" che indica in tempo reale lo stato di salute dello Scheduler di sistema. Se il pallino è verde, il server sta processando regolarmente i job in background; include inoltre un timestamp "Ultimo battito" per un controllo millimetrico.
- **Stampa Riparto Bilancio per Capitolo e Soggetto:** Introdotto il nuovo documento di riparto PDF accessibile dal piano rate. La stampa raggruppa analiticamente le spese ripartite per **Capitoli di spesa**, mostrando le quote e gli importi precisi distribuiti per ogni unità e soggetto coinvolto.
- **Paginazione Automatica Stampe PDF (Chunking):** Implementata la paginazione orizzontale dinamica per i prospetti in formato PDF (Riparto per Tabelle e Riparto per Capitoli). Se il piano dei conti o le tabelle millesimali superano la larghezza massima (oltre 5-6 colonne), il sistema suddivide automaticamente le voci su più pagine (`<pagebreak />`). Questo garantisce la massima leggibilità dei font evitando documenti illeggibili per condomini molto grandi.
- **Supporto Piani Rate Straordinari Misti:** Estesa la logica di calcolo (`RipartoCapitoliService` e `RipartoTabelleService`) per i Piani Straordinari. È ora possibile aggregare in un unico documento sia i costi derivanti dalle **fatture collegate** sia quelli provenienti da **capitoli di spesa aggiunti manualmente**.
- **Docker Supervisord per Scheduler Locale:** Aggiunto il demone `laravel-scheduler` in `docker/supervisord.conf` per l'avvio automatico di `schedule:work` all'interno dell'ambiente Docker, essenziale per lo sviluppo e il test locale dell'heartbeat.
- **Test Automatizzati (Riparto Preventivo):** Implementata una suite di test (Pest/PHPUnit) per certificare e bloccare eventuali regressioni del motore di riparto, testando rigorosamente la quadratura dei calcoli e il corretto funzionamento della logica Raccoglitore (catch-all) nei Piani Rate Generali.
- **Guida Plesk per Cron Job:** Redatta la documentazione ufficiale `plesk_cronjob_setup.it.md` per la corretta configurazione di Scheduler e Queue Worker in ambienti Plesk, con indicazioni per disabilitare il flag `SCHEDULE_QUEUE_WORKER` ed evitare Error 500 causati dai rigidi timeout web.

### Corretto
- **Logica "Catch-all" (Raccoglitore):** Ottimizzato il comportamento dei Piani Rate Generali. Ora il sistema esclude in automatico tutte le voci di spesa (`conti_id`) che sono già state intercettate da altri Piani Rate Parziali attivi. Il Piano Generale si comporta quindi come un contenitore per tutte le "voci orfane" rimaste da coprire, prevenendo la doppia riscossione.

---

## [1.9.1] — Smart Treasury & Passive Cycle

> **Stable release.**
> Ciclo passivo completo, pagamento delle fatture, storno pagamenti (Ledger immutabile), widget Treasury Guardian, motore a cascata per usufruttuari, gestione unità vuote (scoperti documentati) e suite completa di stampe PDF ufficiali (Riparti e Scadenziari).
>
> 12 beta release (beta.1 → beta.12) prima della stable.

---

### [1.9.1-beta.12] - Restyling Tabelle & Sicurezza Dati

### Miglioramenti UI/UX
- **Restyling Lista Tabelle Millesimali:** L'elenco delle tabelle è stato completamente riprogettato per migliorare leggibilità e densità delle informazioni.
  - Sostituite le icone generiche con icone colorate specifiche per ogni tipologia (Ascensore, Scale, Lastrico, Riscaldamento, ecc.).
  - Fusa la colonna Palazzina/Scala in un'unica colonna "Palazzina / Scala". Per le tabelle generali, compare un chiaro badge verde "INTERO CONDOMINIO".
  - Nuova colonna **Unità Associate**: mostra il conteggio esatto delle proprietà associate alla tabella.
  - Nuova colonna **Stato**: mostra con un indicatore visivo se una tabella è "In uso" (associata a voci di spesa) oppure "Orfana".

### Hardening
- **Prevenzione Cancellazione Tabelle in Uso:** Inserito un blocco sia frontend (alert giallo informativo immediato) che backend (`destroy`) che impedisce l'eliminazione accidentale di tabelle millesimali qualora siano già state associate a voci di spesa, proteggendo l'integrità dei dati storici.

### Corretto
- **Falla Matematica nel Riparto per Tabella:** Risolto un grave bug logico in `RipartoTabelleService` che alterava il calcolo delle percentuali e quote di ripartizione. La funzione divideva erroneamente il peso dell'anagrafica per l'importo monetario (`$importo`), causando quote sballate e totali incoerenti sul documento di stampa. Il calcolo ora accumula correttamente i pesi puri prima della distribuzione proporzionale "penny-perfect".
- **Bug Creazione Tabella (`undefined variable $tabella`):** Risolto un crash nel controller che si verificava qualora il salvataggio a database di una nuova tabella andasse in errore. Il `catch` ora esegue un fallback pulito tramite `back()` ripristinando il modulo e mostrando l'errore, invece di tentare un redirect su risorsa inesistente.
- **Dinamismo Intestazioni PDF:** Sistemate le intestazioni dinamiche dei PDF dei riparti, che ora riportano l'esatta unità di misura della tabella considerata (`mill. ‰`, `quote`, `pers.`, `kW`, `mc.`).

---

### [1.9.1-beta.11] - Allineamento Layout Tabelle & Hotfix Modale Sottoconti & Riparto per Tabella

### Aggiunto
- **Stampa Riparto Bilancio Preventivo per Tabella × Soggetto:** Introdotto il documento di riparto più dettagliato della suite stampe. Accessibile dal piano rate con il pulsante "Riparto per Tabella", genera un PDF landscape con formato adattivo (A4 fino a 5 tabelle, A3 oltre) che mostra per ogni unità immobiliare e per ogni soggetto (Proprietario, Inquilino, Usufruttuario, Nuda Proprietà, Comodatario) la quota millesimale e l'importo ripartito su ciascuna tabella millesimale configurata nel piano dei conti. Il documento include: intestazione premium con pillole riepilogative (n° unità, soggetti, totale €), barra sommario per tipo soggetto con percentuali, accent color per ruolo su ogni riga, colonna percentuale sul totale condominio, indicazione del piano per ogni appartamento, riferimento delibera e verbale assembleare (se compilati), riga totali con sfondo navy, legenda ruoli e note legali (art. 1123 c.c.). Il calcolo distribuisce gli importi reali delle rate emesse in proporzione ai pesi delle tabelle millesimali configurate, rispettando la cascata di risoluzione del ruolo del soggetto (identica al motore `CalcoloQuoteService`). Nessuna migration necessaria.

### Miglioramenti UI/UX
- **Allineamento Layout Pagine Tabelle:** Le pagine `TabelleNew`, `TabelleEdit` e `QuoteList` sono state completamente ridisegnate per aderire al design system uniforme del gestionale. Ogni pagina presenta ora il componente `PageHeaderGuide` con guide contestuali, form organizzati in sezioni Card con bordo tratteggiato (`border-dashed`) e bottoni di azione in fondo al form con tipografia `uppercase tracking-widest`.
- **Lista Tabelle — Colonna Denominazione Arricchita:** La colonna "Denominazione" nella lista delle tabelle millesimali mostra ora un layout "ricco" con icona colorata, titolo, nota e Call-To-Action "Gestione Quote →". Cliccando il nome della tabella si accede direttamente alla pagina di gestione dei millesimi (`QuoteList`), rendendo superfluo il passaggio per il menu a tendina.

### Corretto
- **Regressione Bug Modale Sottoconti — Quota Proprietario sempre al 100%:** Risolto un bug di race condition nel `ModalModificaConto`. Dopo un salvataggio, `resetForm()` impostava `percentuale_proprietario = 100`. Se l'utente riapriva il **medesimo** sottoconto (stessa reference Vue), il `watch(props.conto)` non si riattivava lasciando il campo bloccato a 100%. Corretto estraendo la logica di idratazione in `populateFormFromConto()` e richiamandola esplicitamente anche nel `watch(props.show)` a ogni apertura del modale.
- **TS Error TabelleEdit — `options` su v-select:** Corretti due errori TypeScript (TS2740) per cui `condominio.palazzine` e `condominio.scale` venivano passati come `options` alla `v-select` anziché le props dirette `palazzine` e `scale`.
- **TS Errors QuoteList — Indicizzazione `form.errors` e tipo indice:** Corretti due errori TS7053 sull'accesso dinamico a `form.errors` con chiavi template-literal (es. `` `quote.${idx}.valore` ``) tramite cast a `Record<string, string>`. Corretto inoltre un errore TS2345 sul tipo dell'argomento `index` in `removeImmobile` (ora accetta `number | string` con conversione esplicita).

### Hardening
- **Compatibilità PHP 8.4 e 8.5 (Configurazione Database):** Sostituito il controllo statico della versione PHP (`PHP_VERSION_ID`) nel file `config/database.php` con un controllo dinamico e retro-compatibile tramite `defined('\Pdo\Mysql::ATTR_SSL_CA')`. Questo elimina definitivamente il *deprecation warning* (`Constant PDO::MYSQL_ATTR_SSL_CA is deprecated`) che compariva su console durante il `composer install` sui server che utilizzano già PHP 8.4, garantendo aggiornamenti silenziosi e senza interruzioni.

---



### [1.9.1-beta.10] - ScopertoWarning & Coerenza Ruoli

### Aggiunto
- **Componente UI ScopertoWarning:** Inserita interfaccia che rileva se in alcune unità mancano i soggetti attivi per il riparto (es. inquilini) e calcola in tempo reale gli scoperti. 
- **Salvataggio Motivazione Scoperti:** Per poter forzare e generare il riparto addossando gli scoperti, l'amministratore deve inserire una nota obbligatoria (> 10 caratteri) che verrà persistita e mostrata storicamente come banner sul piano rate generato.
- **Risoluzione a Cascata:** Migliorata la tracciabilità della cascata di calcolo. Invece di segnalare un generico "cascata esaurita", il sistema espone esattamente il `ruolo_richiesto` mancante (es. usufruttuario o inquilino), arricchito dai nomi effettivi di Immobile e Conto.
- **Eccezione Silenziosa e Gatekeeper:** Introdotta `ScopertiNonAccettatiException` che blocca in sicurezza la logica del controller avvisando il frontend, ignorata volutamente da Sentry e loghi di sistema.
- **Task Inbox Automatico per Scoperti Documentati:** Quando l'amministratore accetta forzatamente uno scoperto e inserisce la motivazione, il sistema crea automaticamente un task prioritario nell'Admin Inbox con titolo "Quote non assegnate — [nome piano]", importo scoperto e istruzioni operative. Il task rimane aperto nel widget inbox della dashboard finché non viene chiuso manualmente, rendendo impossibile dimenticarsi dell'unità orfana.
- **Widget Copertura Bilancio — Nuovo stato "SCOPERTO DOCUMENTATO":** Introdotto un quarto stato `documented` nel Validatore Budget della dashboard. Se il buco di copertura è stato documentato consapevolmente (nota_scoperti presente), il widget mostra ora il badge neutro slate **SCOPERTO DOCUMENTATO** al posto del generico allarme ambra **FABBISOGNO SCOPERTO**. La logica garantisce che eventuali nuove spese inserite a posteriori abbiano la priorità, facendo riaccendere l'allarme ambra finché non vengono rateizzate.

### Risolto
- **DashboardController QueryException:** Risolto bug (Internal Server Error) nel `DashboardController` che tentava di filtrare i `piani_rate` tramite la colonna inesistente `esercizio_id` invece di passare per le `gestioni` collegate all'esercizio.
- **Terminologia widget rinominata:** "SOTTO COPERTURA" → **"FABBISOGNO SCOPERTO"** per aderenza al lessico contabile, eliminando l'ambiguità del termine precedente che evocava contesti investigativi piuttosto che amministrativi.

### Hardening
- **Compatibilità PHP 8.4 (Auto-Update Engine):** Inserito un fix preventivo nel bridge di installazione (`index.php`) per gestire l'impostazione errata `session.gc_divisor = 0` riscontrata su alcuni hosting condivisi. A differenza di PHP 8.3 che emetteva solo un warning, PHP 8.4 lancia una `ValueError` irreversibile. Il sistema intercetta ora questa configurazione e la neutralizza a runtime forzando `session.gc_divisor = 1000`, evitando crash silenti durante gli aggiornamenti over-the-air.

---

### [1.9.1-beta.9] - Hotfix UI Piano dei Conti & Action Inbox Upgrade + Completamento Controller Pagamenti

### Aggiunto
- **Compliance Alert (Art. 1130 c.c.):** Aggiunto un banner giallo di avvertimento non bloccante in `FatturaRegisterNew`, `FatturaRegisterEdit`, `PagamentoNew` e `PagamentoEdit` se l'amministratore tenta di registrare un movimento con una data antecedente a 30 giorni rispetto a oggi. L'avviso educa e responsabilizza, senza impedire l'operatività.
- **Admin Inbox — Conteggio Giorni Sospeso:** La inbox globale dell'amministratore e il widget nella dashboard condominio mostrano ora i giorni esatti di ritardo (es: "SCADUTO DA X GIORNI") calcolati in tempo reale rispetto alla data di scadenza delle operazioni, migliorando drasticamente la percezione dell'urgenza e del tempo trascorso.
- **Endpoint dettaglio pagamento fornitore:** Aggiunto il metodo `show()` in `PagamentoFornitoreController` con guard di appartenenza al condominio, eager loading completo delle relazioni (fornitore, conto, scrittura con righe e fatture allocate) e rendering Inertia verso la pagina `PagamentoShow`. La route `GET /pagamenti-fornitori/{pagamento}` è ora registrata in `gestionale.php` (`pagamenti-fornitori.show`), completando la mappa CRUD del modulo pagamenti.
- **Prop `size` su `ConfirmDialog`:** Il componente condiviso `ConfirmDialog.vue` supporta ora una prop `size` con quattro taglie (`sm`, `md`, `lg`, `xl`) che sovrascrive la larghezza di default `max-w-lg` tramite `cn()`. Il valore di default rimane `md` — nessun impatto sugli oltre 20 utilizzi esistenti.
- **F24 Refactor — `SyncF24WithPagamento` listener:** Creato il listener `SyncF24WithPagamento` (auto-discovery via `subscribe()`, `$afterCommit = true`) che crea il task "F24 Ritenuta" nell'Admin Inbox al momento del **pagamento** effettivo, non della registrazione fattura. La scadenza è calcolata al 16 del mese successivo a `data_pagamento`, spostata al lunedì se cade di weekend. Il listener è idempotente (`updateOrCreate`) con guard su `importo_ritenuta <= 0` e su record di storno (`pagamento_padre_id !== null`).
- **Pagina dettaglio pagamento fornitore (`PagamentoShow`):** Creata la pagina Vue `PagamentoShow.vue` con layout `GestionaleLayout`, `PageHeaderGuide`, riepilogo importi (lordo, netto, ritenuta, commissioni), partita doppia della scrittura collegata, fatture saldate con link click-through e pulsante "Distinta PDF".
- **Link dettaglio nel dropdown lista pagamenti:** Aggiunta voce "Vedi dettaglio" nel `DataTableRowActions.vue` che naviga a `PagamentoShow`. La precedente voce "Dettaglio scrittura" è rinominata "Dettaglio scrittura contabile" e spostata in posizione secondaria.

### Miglioramenti UI/UX
- **Modal "Ratifica assembleare — Sforo motivato" allargato:** Il `ConfirmDialog` di approvazione sforo usa ora `size="lg"` (`max-w-2xl`) per dare respiro al testo legale.
- **Dicitura modal Approva Sforo riscritta (Feature 3, Art. 1135 c.c.):** Il testo del modal e del tooltip badge "⚠ Ratifica richiesta" è stato riscritto per coprire esplicitamente i due scenari previsti dall'art. 1135 c.c.: spesa già deliberata in assemblea (con rif. verbale) e pagamento d'urgenza dell'amministratore (con motivazione obbligatoria). Nessun campo nuovo, nessuna colonna, nessun JSON aggiunto.
- **Case uniformato:** Titoli e testi dei modali seguono ora sentence case invece di title case.

### Corretto
- **Bug Creazione Password:** Risolto un bug critico che impediva ai nuovi utenti invitati di impostare la propria password. I nuovi utenti ricevono ora un campo password `null` anziché una stringa casuale, permettendo al sistema di distinguere il primo accesso e reindirizzando al login con messaggio specifico solo a password effettivamente impostata.
- **Link Inviti Scaduti:** Aggiunta gestione UX esplicita (messaggio flash) e ripristinato il controllo di sicurezza (`hasValidSignature()`) sui link scaduti e/o alterati per la creazione password e reinvito.
- **`SyncScadenziarioWithFattura` — codice morto rimosso:** Eliminato il blocco commentato (42 righe) che creava il task F24 al momento della fattura. La logica corretta vive ora in `SyncF24WithPagamento`.
- **Voce menu "Dettaglio scrittura contabile" appariva disabilitata:** Il `class="text-slate-500"` sul `DropdownMenuItem` causava un aspetto grigio identico allo stato `:disabled`. Rimosso.

### Aggiunto
- **Impostazioni Stampe PDF:** Aggiunto un nuovo pannello di configurazione globale dedicato alle stampe. È ora possibile definire una Nota Legale (es. professione esercitata ex l. 4/2013, P.IVA, Polizza RC) che apparirà come piè di pagina in tutti i prospetti generati.
- **Firma Amministratore:** Implementata la possibilità di caricare l'immagine della firma dell'amministratore (in formato PNG o JPG), che verrà apposta automaticamente in calce ai documenti ufficiali come rendiconti e prospetti rate.
- **Filtro Condominio su Action Inbox Admin:** Aggiunto un dropdown nella barra di navigazione superiore per filtrare la lista dei task per singolo condominio. Le KPI card (Scaduti, Verifiche incassi, Ticket, Totale) si aggiornano dinamicamente in base al condominio selezionato.
- **InfiniteScroll su Action Inbox Admin:** La paginazione statica è stata sostituita con il caricamento infinito (`Inertia::scroll`), coerentemente con il widget nella dashboard del gestionale.

### Corretto
- **Bug critico — Pulsante "Risolvi" inerte su Action Inbox Admin:** Il tasto "Risolvi" non eseguiva alcuna azione su task privi di `action_url` perché la funzione `completeTask()` era assente dalla pagina admin. La pagina gestionale (widget dashboard) era già corretta; la pagina admin `/admin/inbox` non era mai stata allineata. Ora il pulsante ✅ (completa task) è sempre visibile per tutti i task; il link "Risolvi →" appare in aggiunta solo se è presente un `action_url`.
- **Conteggi KPI non filtrati:** Le card dei conteggi nella Action Inbox admin mostravano sempre i totali globali anche selezionando un condominio specifico. Il controller ora ricalcola i conteggi filtrati per condominio direttamente nel backend quando `condominio_id` è specificato.
- **Dropdown Capitoli Padre:** Risolto un bug nell'interfaccia di inserimento e modifica dei conti per cui il menu a tendina "Capitolo padre" non si aggiornava istantaneamente dopo la creazione di un nuovo capitolo, costringendo l'utente a ricaricare la pagina. Ora la cache del componente si invalida e si sincronizza automaticamente al salvataggio.
- **Modifica Ripartizione Proprietario a 0%:** Risolto un bug nel modale di modifica dei sottoconti che forzava visivamente la quota del proprietario al 100% in apertura, ignorando il salvataggio legittimo di una quota pari allo 0% (es. per spese totalmente a carico dell'inquilino).
- **Rimozione Errori Validazione Dinamici:** Risolto un problema di usabilità nel modale di creazione e modifica dei conti in cui gli errori rossi di validazione per i campi "Tabella Millesimale" e "Capitolo Padre" rimanevano visibili anche dopo che l'utente aveva selezionato un valore valido dalla tendina. Gli errori ora scompaiono in tempo reale al variare della selezione.
- **Causale Bonifico Parlante:** Risolto un fatal error durante la registrazione di un pagamento con bonifico parlante causato da una chiamata a un metodo inesistente nell'Enum delle detrazioni. La causale bancaria fiscale viene ora generata correttamente e troncata entro i limiti SEPA.
- **Formattazione Data Modale Approvazione:** Risolto un bug nella modale di approvazione del piano rate che forzava la selezione della data in formato americano (yyyy-mm-dd) anziché italiano.
- **Falso 403 su Pagamenti in Hosting Condivisi:** Risolto un bug critico (Accesso negato / La scrittura non appartiene a questo condominio) che impediva il download della distinta e lo storno dei pagamenti su server di produzione che non utilizzano `mysqlnd`. Introdotto il cast esplicito a `integer` nei modelli Eloquent per garantire la corretta validazione dei permessi.

### Miglioramenti UI/UX
- **Redesign Action Inbox Admin:** Refactoring completo del layout della pagina `/admin/inbox` per allinearlo al design system del gestionale. Rimosso l'header hero scuro; adottato il pattern standard `px-6 py-8` con label + `h1 font-black`. Le 4 card filtro seguono ora lo stile delle KPI card della dashboard admin (icona decorativa in background, footer con freccia, bordo dinamico colorato). Il filtro condominio è integrato nel top-bar a destra accanto al pulsante "Dashboard".
- **Ordinamento Task per Urgenza:** I task scaduti appaiono sempre in cima alla lista, seguiti dai futuri in ordine cronologico crescente, indipendentemente dal filtro attivo.
- **Uniformità Pulsanti Azioni:** Tutti i pulsanti icona (✅ Completa, ✗ Rifiuta) seguono lo stesso pattern visivo (`w-8 h-8 rounded-md border shadow-sm`). I pulsanti testuali (Registra →, Risolvi →) usano lo stesso container `h-8 px-3 border bg-white`.
- **Dettaglio Piano Rate:** Ottimizzata l'interfaccia della pagina. I pulsanti della barra delle azioni diventano a scomparsa testuale (solo icona) sugli schermi dei portatili per evitare scorrimenti orizzontali. Integrato il nuovo header guida con breadcrumb unificate e spostato il badge della data di delibera in un comodo tooltip interattivo per risparmiare spazio verticale.

---

### [1.9.1-beta.8] - Modulo Commenti per Segnalazioni Guasto

### Aggiunto
- **Nuovo Modulo Commenti per le Segnalazioni Guasto**: Aggiunta la possibilità per amministratori, condòmini e fornitori di comunicare direttamente all'interno della singola segnalazione guasto.
- **Forza Moderazione Commenti**: Nuova impostazione globale per obbligare tutti i commenti degli utenti standard e fornitori all'approvazione obbligatoria dell'amministratore, ignorando i permessi di auto-pubblicazione.
- **Sicurezza e Permessi per i Commenti**: Isolamento completo dei ruoli con controlli severi. Gli amministratori possono moderare o nascondere commenti, mentre gli utenti standard e fornitori possono aggiungere, modificare o cancellare esclusivamente i propri commenti relativi ai propri condomini.
- **Notifiche in Tempo Reale**: Integrazione di notifiche automatiche in app e via email ogni volta che viene aggiunto o aggiornato un commento sulla segnalazione in carico.

### Miglioramenti Tecnici
- **Inbox Centralizzata e Polimorfismo**: Ristrutturazione di `ActionInboxController` e `InboxService` per la gestione dinamica delle azioni utente. Introdotto un costruttore universale (`createTask()`) basato sull'enum `EventoTipo`.
- **Resilienza delle Migrazioni (Windows/Shared Hosting)**: Aggiunto `set_time_limit(0)` e pattern di cleanup idempotente (`cleanupPartialMigration()`) alle migrazioni pesanti per prevenire blocchi dovuti a timeout PHP.
- **Sicurezza Infrastruttura di Testing**: Introdotto un fail-safe globale in `TestCase.php` che blocca l'esecuzione accidentale dei test sul database reale, imponendo l'uso di SQLite in-memory.
- **UI/UX Inbox**: Integrazione delle icone dinamiche (basate sul tipo di evento) nel widget e nella Action Inbox del gestionale.

### Importante
- **Nota per gli sviluppatori**: Dopo aver aggiornato il codice all'ultima versione e aver eseguito le migrazioni, è necessario lanciare il comando `php artisan db:seed --class=RolesAndPermissionsSeeder` per generare a database i nuovi permessi inseriti a sistema.

### Corretto
- **Falsi positivi Pendenze Utente (Rata Zero & Rimanenze)**: Risolto un bug critico che manteneva visibili le rate nella dashboard del condòmino anche dopo il saldo. Il sistema ora contrassegna automaticamente come pagate (`status = 'paid'`) le quote inziali a zero o in credito puro (es. Rata Zero azzerata) fin dal momento della loro emissione, evitando che restino perennemente nello scadenziario.
- **Prevenzione Segnalazioni a Zero**: Inserito un blocco frontend e backend che impedisce ai condòmini di segnalare un pagamento per rate con importo rimanente pari a zero, sostituendo il pulsante con un messaggio informativo di "Nessun pagamento richiesto".

---

### [1.9.1-beta.7] - Filtri Interattivi, Chiarezza Visiva e Tracciabilità UI

### Aggiunto
- **Filtri Interattivi sulle Card (Smart Stats)**: Le card riepilogative nella lista pagamenti ("Con Ritenuta d'Acconto" e "Operazioni Stornate") sono diventate interattive. Cliccandole, applicano o rimuovono istantaneamente il filtro corrispondente sulla tabella dati sottostante (`has_ritenuta` o `stato=stornato`), velocizzando la ricerca in elenchi molto corposi.
- **Allineamento Globale UI Dashboard**: L'interfaccia interattiva delle card statistiche (Smart Stats) è stata estesa alle viste "Fatture Passive" e "Incassi Rate", garantendo totale coerenza visiva e logica. Le card per filtrare "Fatture Aperte", "Da Ratificare" (Fatture) e "Stornati" (Incassi) adottano ora lo stesso design system con highlight ring attivi (`ring-2`) e gestiscono dinamicamente lo state di disabilitazione.
- **Integrazione Audit Ratifica in Dettaglio Scrittura**: Inclusa la visibilità delle note di audit (Art. 1135 c.c.) all'interno della vista di dettaglio della Scrittura Contabile, permettendo ai revisori di vedere l'intero ciclo di vita e la giustificazione legale dell'approvazione spesa.
- **Infrastruttura Documentale PDF Nativa**: Installata e integrata la libreria `mpdf/mpdf` per la generazione lato server di documenti PDF complessi (senza dipendenze esterne come Node o Chrome).
- **Distinta di Pagamento Fornitore (PDF)**: Creazione di layout e stili master per PDF (su formato A4) e implementazione del download della Distinta di Pagamento, completa di causale, totali e informazioni sul bonifico parlante.
- **Visualizzazione Dettaglio Incasso Rate**: Aggiunta la vista in sola lettura per esplorare analiticamente la composizione di un incasso rata, evidenziando se pagato tramite versamento contanti/bonifico o compensazione del credito.
- **Ottimizzazione Tooltip Tabelle**: Sostituiti i tooltip testuali nativi con HoverCard interattivi (Shadcn) per una lettura immediata e ricca del dettaglio importi nelle tabelle Fatture Passive e Pagamenti Fornitori.
- **Stampe Scadenziario e Riparti (PDF)**: Rilasciata la suite di stampe ufficiali per i piani rate (Scadenziario / Prospetto Rate) e per il piano dei conti (Distinta Preventivo e Ripartizione Spese). I layout includono intestazioni con riferimenti legali e design "printer-friendly" (su formati A4 Portrait/Landscape).
- **Scelta Multipla Aggregazione PDF**: L'amministratore ora può decidere dinamicamente dal menu a discesa se esportare il Prospetto Rate raggruppandolo "Per Condòmino", "Per Unità Immobiliare", o combinando entrambe le viste in un unico documento PDF multi-pagina. I totali del documento "Per Condòmino" riflettono esattamente la logica della UI aggregando automaticamente la somma degli immobili appartenenti allo stesso proprietario.
- **Test di Integrità e Quadratura PDF**: Integrata una test suite completa (Pest) dedicata ai controller di stampa che garantisce incroci perfetti: ogni importo mostrato sui PDF (es. calcolo totale da preventivo, esclusione di conti tecnici, raggruppamento anagrafiche) deve combaciare rigorosamente con i totali presenti a database, bloccando preventivamente eventuali disallineamenti di stampa.
- **Fix Sicurezza URL Firmati**: Sostituito l'indirizzo email con l'ID utente nei link crittografati per inviti e reset password, risolvendo definitivamente un errore 403 causato dalla decodifica automatica del carattere `@` da parte di browser e client email in Laravel 11.
- **Miglioramento UX Scadenza Link**: Estesa la validità dei link per l'accettazione degli inviti e per la creazione della password da 60 minuti a 3 giorni, offrendo più tempo agli utenti per completare la registrazione.
- **Ottimizzazione UI Piano dei Conti**: Raggruppati i pulsanti di stampa (Distinta Base e Ripartizione) all'interno di un unico menu a tendina "Stampe" per preservare lo spazio e mantenere il layout pulito su schermi piccoli.

### Refactoring & Ottimizzazioni
- **PSR-4 Compliance Exceptions**: Eseguito un refactoring architetturale delle eccezioni di dominio dei pagamenti. Diviso il macro-file `Exceptions.php` in 10 file di eccezione singoli e rimosso l'autoload manuale da `composer.json`, risolvendo in modo definitivo i warning dell'autoloader e rispettando gli standard PSR-4.

### Corretto
- **Ambiguità Visiva tra Originali e Storni**: Risolta la confusione causata dalla sovrapposizione visiva degli stati. I pagamenti che sono stati annullati mostrano ora il badge "Originale Stornato" barrato, mentre i nuovi movimenti di compensazione (le operazioni di storno vere e proprie) mostrano il badge "Storno Confermato". Solo sui normali pagamenti confermati appare l'opzione "Storna pagamento" nel dropdown.
- **Workflow Modale di Successo**: Dopo la registrazione di un pagamento (PagamentoNew), il pulsante "Torna all'elenco" reindirizza ora correttamente alla lista dei pagamenti (`gestionale.pagamenti-fornitori.index`) invece di rimandare all'elenco fatture, garantendo continuità operativa.
- **Hardening UI su Testi Estesi**: Risolto un bug di rendering nella vista Scrittura Contabile (`Show.vue`) che causava l'overflow e la sovrapposizione del layout in presenza di stringhe senza spazi lunghe (es. IBAN) o di testi descrittivi prolissi (note di audit). Aggiunti container con troncamento, interruzione parola e scrollbar verticale limitato in altezza (`max-h-32 overflow-y-auto`).
- **Allineamento UI Documenti**: Uniformato il design delle pagine di caricamento e modifica documenti fornitori (`DocumentiNew.vue`, `DocumentiEdit.vue`) allo standard del gestionale, sostituendo i layout sparsi con componenti coerenti (`AppLayout`, `PageHeaderGuide`).
- **Ottimizzazione Tabelle Documenti**: Risolto un `RangeError` sul formato date in ambiente di produzione, aggiunta la traduzione mancante dei badge di visibilità (Pubblico/Privato) e rinominata la colonna 'Data' in 'Caricato il' per maggiore chiarezza per l'utente finale (`columns.ts`).
- **Visualizzazione Allegati Fatture Esistenti**: Corretto un problema nella vista Dettaglio Fattura (`FatturaShow.vue`) che impediva la corretta visualizzazione del nome e della dimensione dei documenti allegati a causa di proprietà disallineate col modello dati.
- **Upload Allegati Nuove Fatture**: Risolto un bug critico in fase di registrazione nuova fattura (`FatturaRegisterNew.vue`) che causava la perdita del documento allegato durante l'invio del modulo a causa di una conversione JSON che distruggeva l'oggetto File prima del passaggio al backend.
- **Autocompilazione Pagamenti da Inbox Operativa**: Risolto un problema per cui cliccando 'Risolvi' su un task di pagamento dalla Inbox Operativa il modulo di pagamento non veniva precompilato. Il sistema ora deduce automaticamente il fornitore a partire dalla fattura passata nell'URL (`PagamentoFornitoreController`).

---

### [1.9.1-beta.6] - Storno Pagamenti e Ledger Immutabile
### Aggiunto
- **Storno Pagamenti (Ledger Immutabile)**: Modulo completo (backend e UI) per l'annullamento di pagamenti errati o respinti (es. insoluti bancari). Il sistema garantisce l'integrità contabile registrando una scrittura inversa append-only, riaprendo automaticamente le fatture coinvolte e ripristinando la cassa, senza cancellare record storici.
- **Storni Cross-Esercizio**: Gestione intelligente degli storni su bilanci chiusi. Se l'esercizio del pagamento originale è chiuso, il sistema non permette la modifica retroattiva ma registra l'operazione di storno nell'esercizio corrente aperto, salvaguardando i saldi storici consolidati.
- **Sincronizzazione Action Inbox e Pagamenti**: Implementato un nuovo listener (`SyncScadenziarioWithPagamento`) che collega la registrazione e lo storno dei pagamenti ai task amministrativi dell'Inbox. La registrazione di un pagamento ora segna automaticamente come completato il task "Pagare fornitore", rimuovendolo dalle urgenze dell'amministratore, mentre uno storno lo riapre immediatamente, ripristinandone la priorità. 
- **UX Ottimizzata Action Inbox**: Risolvendo il task di pagamento dall'Inbox, l'utente viene reindirizzato automaticamente al modulo di registrazione pagamento pre-compilato, azzerando i tempi di ricerca e garantendo un'esperienza fluida.
- **Test Automatici Contabili**: Aggiunta un'ampia suite di test automatici per le logiche di storno avanzato, inclusi storni di pagamenti cumulativi multi-fattura e storni complessi compensati con Note di Credito (netting), garantendo quadratura perfetta DARE/AVERE, oltre a test automatizzati per il ciclo di vita dei task nell'Admin Inbox.

### Corretto
- **Compatibilità SQL Strict Mode (`ONLY_FULL_GROUP_BY`)**: Risolto l'errore 1055 nel modulo Treasury Guardian che bloccava la dashboard su server MySQL 8.0 o configurazioni strict. Il refactoring elimina i `GROUP BY` manuali, delegando il calcolo delle allocazioni a Laravel tramite aggregazioni Eloquent (`withSum`).

---

### [1.9.1-beta.5] - Treasury Guardian Widget MVP
### Aggiunto
- **Treasury Guardian Widget MVP**: Implementato il nuovo widget predittivo di tesoreria nella dashboard. Il sistema calcola automaticamente la proiezione dello scoperto di liquidità a 30 giorni, fornendo una classificazione del rischio (Verde, Giallo, Rosso) basata sulle fatture in scadenza e le rate emesse.
- **Call-to-Action Dinamiche (Smart UX)**: Le azioni suggerite si adattano ora al contesto di cassa. Il widget suggerisce di "Emettere Nuove Rate" in caso di esposizione al rischio senza incassi attesi, e di "Verificare o Sollecitare Incassi" se ci sono versamenti potenzialmente non registrati, con descrizioni leggibili (multi-line).

### Corretto
- **Quadratura Liquidità e Saldi Iniziali**: Risolto un disallineamento tra il calcolo del widget e il bilancio di verifica. Il motore ora somma correttamente il `saldo_iniziale` di cassa ai movimenti contabili di liquidità.
- **Calcolo Esatto degli Incassi Attesi**: Corretto l'algoritmo di stima degli incassi (rate in arrivo). Il sistema ora estrae esclusivamente i movimenti in AVERE (pagamenti ricevuti) evitando sovrastime derivanti dall'emissione in partita doppia, garantendo una stima predittiva perfetta al centesimo.
- **Statistiche Ritenute d'Acconto**: Corretto un problema statistico nella dashboard dei pagamenti che manteneva a zero il conteggio delle ritenute d'acconto. Il sistema calcola ora la ritenuta proporzionalmente al momento della registrazione del pagamento.
- **Action Inbox per Piani Straordinari & Sync**: Esteso il supporto della Action Inbox ai Piani Rate Straordinari (generazione immediata task di emissione rate e verifica incassi) e risolto un bug che non eliminava i vecchi eventi in caso di rigenerazione totale di un piano rate approvato.

---

### [1.9.1-beta.4] - Smart Error Handling Pagamenti
### Aggiunto
- **Smart Error Handling Pagamenti**: Nuovi modali intelligenti e contestuali per la gestione delle eccezioni di dominio durante il pagamento fornitori.
- **Audit Trail Responsabilità**: Tracciamento obbligatorio delle note di override per decisioni critiche (es. scoperto di conto, overpayment) ai sensi dell'art. 1129 c.c.
- **Sentinelle di Partita Doppia**: Controlli rigorosi e informativi su allocazioni inconsistenti e violazione del tetto contanti (D.Lgs. 231/2007).

### Corretto
- **Fix calcolo capienza Cassa**: Risolto un bug critico nel backend che ignorava il `saldo_iniziale` della Cassa nel calcolo del saldo corrente per il controllo fondi.

---

### [1.9.1-beta.3] — Dettaglio Fattura & Flusso Pagamento Rapido

> Aggiunta pagina di dettaglio fattura con visualizzazione completa di voci, importi, scadenze, documenti allegati, audit trail per l'Art. 1135 c.c., e possibilità di procedere immediatamente al pagamento.

### Funzionalità — Dettaglio Fattura

- **Pagina Dettaglio Fattura Passiva:** Aggiunta la vista dedicata per ispezionare tutti gli estremi della fattura. È presente il riepilogo documenti, importi (imponibile/iva), scadenza, badge stato approvazione e stato pagamento. Mostra il dettaglio delle righe contabilizzate, incrociando i capitoli di spesa del piano dei conti.
- **Audit Trail Ratifica Assembleare:** Se la fattura è stata approvata in seguito a uno "sforo motivato" (Art. 1135 c.c.), la pagina di dettaglio espone ora una sezione di Audit Trail (banner in evidenza) con autore, orario di approvazione e nota verbale.
- **Flusso "Paga Ora":** Un pulsante verde in corrispondenza dei badge ("Paga Fattura") consente di saltare immediatamente alla pagina di registrazione pagamento, auto-selezionando il fornitore e marcando l'intera fattura per il saldo in un solo click, con caricamento istantaneo delle pendenze residue.
- **Ritenute d'Acconto:** Aggiunta una nota riepilogativa nel dettaglio fattura se il compenso è soggetto a ritenuta d'acconto, incluse le specifiche dell'aliquota (%) e del tributo assegnato.
- **Approvazione base:** Possibilità di passare lo stato da "Da Approvare" ad "Approvata" direttamente dal menu azioni riga (per fatture interne che non costituiscono sforo motivato), permettendone il rapido sblocco per il saldo.

---

### [1.9.1-beta.2] — Ratifica Assembleare Sforo Motivato & Legal Compliance

> Implementa il flusso di approvazione legale per le fatture registrate con sforo motivato (Art. 1135 c.c.),
> rendendo il ciclo passivo completamente operativo e conforme per gli studi di amministrazione professionale.

### Funzionalità — Ratifica Assembleare Sforo Motivato

- **Nuovo endpoint `POST /fatture/{fattura}/approva-sforo`:** Aggiunto metodo `approvaSforo()` in `FatturaPassivaController` che gestisce la transizione legale `sforo_motivato → approvata`. Il metodo include guard di stato, validazione note (max 1000 caratteri) e salvataggio automatico dell'audit trail in `dati_extra.ratifica_assembleare` (note, timestamp ISO8601, ID autore).

- **Audit trail permanente:** Ogni ratifica salva in `dati_extra`: data e ora dell'approvazione, ID dell'utente che ha confermato, e note libere con riferimento alla delibera assembleare. Il log server (`laravel.log`) registra ogni transizione per tracciabilità completa.

- **Bottone "Approva sforo" inline in Pagina Pagamento Fornitori:** Le fatture in sforo motivato mostrano nella riga pendenze un bottone arancione "Approva sforo". Al click si apre una modale di ratifica (`ConfirmDialog`) con contesto legale Art. 1135, riepilogo della fattura in oggetto, e campo note facoltativo. Alla conferma, lo stato cambia istantaneamente e le pendenze vengono ricaricate senza cambiare pagina — la fattura diventa selezionabile per il pagamento.

- **Voce "Ratifica Assembleare" nel menu azioni Lista Fatture:** Aggiunta al componente `DataTableRowActions.vue` una voce arancione nel dropdown `⋯`, visibile esclusivamente per fatture con `stato_approvazione === 'sforo_motivato'`. Apre lo stesso modale di ratifica con identico audit trail.

- **Tooltip professionale sfondo nero (reka-ui/shadcn):** Il badge "⚠ Ratifica richiesta" nella pagina Pagamento Fornitori è ora avvolto dal componente `Tooltip` di reka-ui con sfondo nero e freccia, che spiega all'amministratore il motivo legale del blocco (`Art. 1135 c.c.`) e le istruzioni per procedere. Sostituisce il tooltip nativo OS-level che era grezzo e non in linea con lo stile del gestionale.

### Motivazione Legale

> Le fatture con sforo motivato rappresentano spese urgenti sostenute oltre il budget deliberato dall'assemblea. L'Art. 1135 c.c. obbliga l'amministratore a convocare l'assemblea per ratificare formalmente la spesa prima del pagamento. Il blocco precedente era corretto ma silenzioso — senza comunicazione e senza via d'uscita dall'interfaccia, generando confusione operativa e richieste di supporto. Questa release documenta il blocco, spiega il perché e fornisce lo strumento per risolverlo, proteggendo legalmente l'amministratore.

---

### [1.9.1-beta.1] — Registro Pagamenti Fornitori, Statistiche Incassi & Hardening UI/UX

### Funzionalità — Registro Pagamenti Fornitori (Nuovo Modulo)

- **Nuovo Controller & Risorsa Backend:** Creato `PagamentoFornitoreController` e implementata `PagamentoFornitoreResource` per esporre e formattare i dati dei pagamenti verso i fornitori, completi di impaginazione e statistiche.
- **Nuova Interfaccia Registro Uscite:** Sviluppata la vista `PagamentoRegisterList.vue` con il componente dedicato `PagamentiDataTable.vue`. Colonne: Fornitore, Conto Addebito, Data Pagamento, Metodo & Importo, Stato.
- **Statistiche Finanziarie in Tempo Reale:** Tre card analitiche nell'header del registro:
  1. **Uscite Totali** — Somma totale delle uscite registrate nell'esercizio corrente.
  2. **Ritenute d'Acconto** — Conteggio dei pagamenti soggetti a ritenuta per il monitoraggio degli F24.
  3. **Operazioni Stornate** — Conteggio delle transazioni annullate o stornate.
- **Filtri Avanzati:** Pannello di controllo `DataTableToolbar.vue` con ricerca testuale debouncata dei fornitori e menu di selezione per metodo di pagamento (Bonifico, Assegno, Contanti, MAV, ecc.).

### Funzionalità — Statistiche in Lista Incassi Rate

- **Widget di Riepilogo Incassi:** Estese le card statistiche alla schermata `IncassoRateList.vue`:
  1. **Incassi Totali** — Conteggio complessivo degli incassi registrati sul condominio.
  2. **Incassato Mese** — Totale delle operazioni andate a buon fine nel mese solare corrente.
  3. **Incassi Stornati** — Numero di operazioni stornate o annullate.
- **Backend Integration:** Aggiornato `IncassoRateController@index` per calcolare queste statistiche in tempo reale tramite query ottimizzate.

### Hardening — Compatibilità Database PHP 8.5

- **Adattamento `PDO::MYSQL_ATTR_SSL_CA` per PHP 8.5:** In PHP 8.5 la costante `PDO::MYSQL_ATTR_SSL_CA` è stata spostata nel nuovo namespace dedicato `\Pdo\Mysql::ATTR_SSL_CA`. Aggiornato `config/database.php` per entrambe le connessioni `mysql` e `mariadb` con un controllo adattivo a runtime (`PHP_VERSION_ID >= 80500`) che seleziona automaticamente la costante corretta, mantenendo la retrocompatibilità con PHP 8.4 e precedenti.

### Bugfix & Hardening TypeScript

- **Risoluzione Casing Conflict macOS:** Risolto bug bloccante del compilatore TypeScript per discrepanze maiuscole/minuscole tra `DataTable.vue` e `Datatable.vue` dovute alla cache del file system macOS. La cartella dei componenti è stata rinominata in `pagamenti_fornitori`.
- **Fix SelectItem Empty Value (Shadcn UI):** Risolto crash a runtime di `SelectItem` (Shadcn/Radix-vue) che vietava stringhe vuote `""` come valore. Introdotto il valore speciale `"all"` per l'opzione "Tutti i metodi", mappato correttamente a stringa vuota nella chiamata API.
- **Fix Firma Evento `onMetodoChange`:** Risolto errore di digitazione `Type '(val: string) => void' is not assignable to type '(value: AcceptableValue) => any'` in `DataTableToolbar.vue`.
- **Fix GuideItem `colorVariant`:** Sostituito il colore non supportato `"rose"` con `"slate"` in `PagamentoRegisterList.vue` per conformarsi ai vincoli del tipo unione accettato da `PageHeaderGuide`.

---

## [1.9.0] — Accounting Intelligence Core

> **Stable release.**
> Introduce il motore contabile avanzato: ancoraggio atomico dei piani rate,
> dashboard di audit in tempo reale, gestione sopravvenienze passive,
> ripartizione mista ad personam, ciclo passivo completo e conformità Art. 1130-bis c.c.
>
> 27 beta release (beta.3 → beta.29) prima della stable.

---

### [1.9.0-beta.29] — Piano Rate Engine Fixes & Snapshot Architecture

#### Bugfix — Calcolo Totale Piano Rate (Filtro Snapshot)

**Problema:** Se la struttura del piano dei conti veniva popolata in un momento successivo (es. tramite la migrazione automatica della v1.9), il filtro snapshot escludeva completamente interi capitoli di spesa perché tutti i suoi sottoconti risultavano creati dopo il piano rate. Totale rate inferiore al preventivo (es. 4.610 € anziché 9.600 €).

**Soluzione:** Aggiunto un fallback in `CalcoloQuoteService`: se il filtro snapshot esclude tutti i figli ma esiste un importo già congelato (override) nella pivot `piano_rate_capitoli`, il sistema usa tutti i figli correnti per distribuire l'importo corretto, preservando la quadratura senza gonfiare il preventivo.

#### Ottimizzazione — Deep Eager Loading Motore di Calcolo

**Problema:** `CalcoloQuoteService` caricava le relazioni in modo superficiale. Durante la discesa ricorsiva nei sottoconti, Laravel eseguiva il lazy loading delle tabelle millesimali per ogni singola voce. Con `preventLazyLoading(true)` generava un Fatal Error; in alternativa causava un elevato numero di query (N+1 problem).

**Soluzione:** Implementato il Deep Eager Loading (`sottoconti.tabelleMillesimali...`) direttamente all'avvio del calcolo, riducendo drasticamente le query e prevenendo crash.

#### Hardening — Fallback Divisione Equa (Penny-Perfect)

**Problema:** Se un capitolo padre aveva sottoconti con budget totale pari a 0 (struttura creata manualmente o non ancora valorizzata), l'importo congelato del padre veniva ignorato silenziosamente.

**Soluzione:** Se il budget totale dei figli è zero, l'importo del padre viene distribuito in parti uguali tra i figli, garantendo che l'intero budget allocato venga sempre ripartito.

#### Architettura — Snapshot Puro per i Capitoli Orfani

**Problema (debito tecnico):** `SyncOrphanChaptersAction` inseriva i nuovi capitoli orfani nella pivot con importo `NULL`, forzando il motore a leggere il valore "live" dal preventivo e rompendo il principio di immutabilità necessario per la corretta chiusura dell'esercizio.

**Soluzione:** Durante la sincronizzazione, il sistema esegue una somma ricorsiva del preventivo effettivo (filtrando i conti tecnici) e salva il valore esatto nella pivot, congelandolo definitivamente.

---

### [1.9.0-beta.28] — Migration Resilience & Collation Fix (Hosting Condivisi)

#### Hardening — Pattern Idempotente `cleanupPartialMigration`

**Problema:** Su hosting condivisi (Netsons, SiteGround, Aruba) con PHP-FPM o su ambiente Windows, il `max_execution_time` può interrompere una migration `ALTER TABLE` a metà. La successiva esecuzione trovava colonne parzialmente create e crashava con `Duplicate column name` o `Can't DROP ... check it exists`.

**Soluzione:** Il pattern `cleanupPartialMigration` rende ogni migration auto-riparante: prima di aggiungere colonne, verifica e rimuove quelle orfane lasciate dall'esecuzione precedente.

**File modificati (3):**

- **`2026_03_16_223813_add_fornitore_and_description_to_saldi_table`** — Guard `information_schema.STATISTICS` prima di ogni `dropIndex('idx_saldi_condominio_fornitore')`, sia nel `cleanupPartialMigration()` che nel `down()`.
- **`2026_03_27_160203_add_mastri_costo_e_ripara_voci_orfane`** — Refactoring da N+1 a `DB::join()`. Aggiunto `set_time_limit(0)`. Loop `Condominio` convertito da `all()` a `lazy()` per eliminare il rischio OOM.
- **`2026_04_19_072947_hardening_legale_e_tracciabilita_fatture`** — Già conforme; nessuna modifica. È la migration di riferimento che ha ispirato il fix della saldi.

#### Bugfix Critico — Collation Mismatch MySQL (Error 1267)

**Problema:** Il dashboard di produzione su Netsons (MySQL 5.7/8.0 su `utf8mb3_general_ci`) crashava con `SQLSTATE[HY000]: General error: 1267 Illegal mix of collations` ad ogni caricamento.

**Causa radice:** `JSON_UNQUOTE(JSON_EXTRACT(meta, '$.type'))` restituisce una stringa con la collation della connessione (`utf8mb3_general_ci`), mentre i letterali stringa PHP confrontati ereditavano la collation della colonna `meta` (`utf8mb3_unicode_ci`). Entrambe le sorgenti avevano lo stesso livello di coercizione (`COERCIBLE`), quindi MySQL non poteva risolvere il conflitto autonomamente.

**Soluzione:** Wrapping sistematico di tutti i risultati `JSON_UNQUOTE` con `CONVERT(... USING utf8mb4)`.

**File modificati (3):**

- **`RecurrenceService`** — Tutti i confronti `where('meta->type', ...)` convertiti in `whereRaw("CONVERT(JSON_UNQUOTE(...) USING utf8mb4) = ?", [...])`. Sostituito `where('meta->requires_action', true)` con `whereJsonContains`.
- **`InboxService`** — Aggiunto `CONVERT(... USING utf8mb4)` ai confronti JSON in `getCounts()`.
- **`PianoRateResource`** — Refactoring del `whereRaw` nella clausola `has_saldi`: separati in due `whereRaw` distinti dentro un `orWhere(closure)`.

**File non modificati (già sicuri):** Tutti i Listener, i Controller (`DashboardController`, `SituazioneDebitoriaController`, ecc.), `Evento` e `GeneratePianoRateAction` usano `whereJsonContains` o `where('meta->...')` — immuni al problema di collation.

---

### [1.9.0-beta.27] — Tabelle Millesimali Multi-Coefficiente & Copertura Straordinaria Granulare

#### Funzionalità — Gestione Multi-Tabella con Coefficienti Controllati

**Problema:** Era possibile associare più tabelle millesimali a una voce di spesa, ma il coefficiente restava bloccato al 100% senza possibilità di modifica. Impossibile gestire scenari reali come "50% Tabella Generale + 50% Tabella Scale".

**File modificati (7):**

- **`AssociaTabellaController`** — Blocco hard: `somma_coefficienti_esistenti + nuovo_coefficiente ≤ 100`. In caso di violazione la richiesta viene rigettata con il residuo disponibile.
- **`AggiornaTabellaController`** *(nuovo)* — Controller `PUT` per modificare `coefficiente` di un'associazione esistente. Applica lo stesso blocco hard escludendo la riga corrente.
- **`routes/gestionale.php`** — Aggiunta route `PUT esercizi/{esercizio}/.../aggiorna-tabella/{tabella}`.
- **`DettaglioConto.vue`** — Barra visiva della somma coefficienti (arancione se parziale, verde se 100%). Bottone "Aggiungi" disabilitato con tooltip quando la somma raggiunge il 100%.
- **`ModalAssociaTabella.vue`** — Supporto dual-mode (crea / modifica). Badge "max X% disponibile" sul campo coefficiente.
- **`Index.vue`** — Istanza `ModalAssociaTabella` condivisa. Callback `gestisciTabella` smista su `router.post` o `router.put` in base al flag `_isEdit`.

#### Bugfix — Copertura Piani Straordinari Tracciabile e Collegabile

**Problema:** La riga "Analisi Copertura" relativa ai piani rate straordinari veniva generata come fallback generico senza `piano_rate_id`, rendendo impossibile il collegamento diretto al piano.

**Causa radice:** `BudgetCoverageService` Step 3 calcola la copertura straordinaria attraverso `piano_rate_fatture → righe_fattura → conto_id`, ma questa copertura non lascia traccia in `piano_rate_capitoli`. Il gap rimaneva inesplicato e veniva tappato dal fallback.

**File modificati (3):**

- **`PianoContiController::show()`** — Costruisce `$pianiStraordinariMap`: mappa `conto_id → [{id, nome, stato, importo}]` granulare per piano.
- **`ContoResource`** — Produce una riga per ogni piano straordinario con `piano_rate_id` reale; cade nel fallback solo per dati storici privi di `importo_collegato`.
- **`DettaglioConto.vue`** — Il nome del piano è ora un `<InertiaLink>` cliccabile quando `item.piano_rate_id` è valorizzato.

**Invarianti garantiti:** Nessuna migration necessaria.

---

### [1.9.0-beta.26] — Piano Rate Snapshot Engine

#### Bugfix Critico — Isolamento Temporale dei Piani Rate

**Problema:** L'aggiunta di una nuova voce di spesa come sottoconto di un capitolo già incluso in un piano rate attivo causava l'inclusione automatica e silenziosa della nuova voce nel piano esistente.

**Causa radice:** Il sistema non aveva il concetto di "snapshot temporale". I sottoconti venivano sempre letti dinamicamente dalla relazione Eloquent al momento del calcolo.

**File modificati (4):**

- **`CalcoloQuoteService`** — Sottoconti filtrati per `created_at <= piano_rate.created_at` prima di distribuire proporzionalmente il budget.
- **`BudgetCoverageService`** — STEP 1 di `calcolaCoperturaReale()`: il push-down del budget applica lo stesso filtro temporale.
- **`PianoRateController::store`** — I nuovi piani rate salvano in `piano_rate_capitoli` gli ID delle foglie esistenti al momento della creazione.
- **`PianoRateResource`** — La serializzazione di `figli_names` e il calcolo di `importo_originale` applicano lo stesso snapshot temporale.

**Invarianti garantiti:** Nessuna migration necessaria.

---

### [1.9.0-beta.25] — ERP Accounting Engine & Reverse Ledger

#### Architettura — Il Filtro Invertitore (Note di Credito)

- **Paradigma "Write-Then-Reverse":** Il core di `FatturaPassivaService` per le Note di Credito abbandona la logica ibrida basata su moltiplicatori matematici. La Partita Doppia viene generata sempre come per una fattura passiva standard (valori assoluti positivi). Un "Filtro Invertitore" finale capovolge chirurgicamente i segni (DARE↔AVERE).

#### Sicurezza — Il Guardiano Contabile

- **Double-Entry Validator:** Un istante prima di finalizzare il `DB::transaction`, il sistema calcola la somma esatta di DARE e AVERE. Qualsiasi sbilancio blocca fisicamente la transazione (Rollback totale) e scrive un log `CRITICAL` con User ID, importi e differenza.

#### Bugfix — Compliance Fiscale e Fondi

- **Storno Ritenute d'Acconto:** Risolto bug fiscale critico che escludeva il calcolo della ritenuta d'acconto durante la generazione delle Note di Credito.
- **Integrità Reportistica Fondi:** Le Note di Credito registrano l'utilizzo dei Fondi con segno negativo: `1.000 € (Fattura) + (−1.000 €) (Storno) = 0 €`.
- **Garbage Collection Conti Fantasma:** `FatturaPassivaController@destroy` distrugge automaticamente i "Conti Imprevisto" orfani creati dalle sopravvenienze.

#### Testing

- **Test Suite Alignment (100% Pass Rate):** Aggiornati `DashboardFinancialTest` e `BudgetCoverageServiceTest`.
- **Agnostic Migrations per SQLite:** Le migration storiche tollerano le esecuzioni su SQLite in RAM per i test Pest, eseguendo le query raw esclusivamente in ambiente MySQL/MariaDB.

```bash
php artisan test --filter="Scenario|fattura|nota di credito|fondo|mista"
```

---

### [1.9.0-beta.24] — Historical Debt Management & Financial UI

#### Funzionalità — UI Finanziaria Avanzata (Widget Double Lock)

Pannello di registrazione delle fatture pregresse con tre Card analitiche indipendenti:

1. **Quadratura (Scarto Economico):** Differenza tra totale fattura e debito storico a bilancio. Blocca il salvataggio se non quadra (rosso).
2. **Liquidità Arretrati (Deficit Finanziario):** Confronto tra debito storico e capienza della Rata 0. Avviso informativo non bloccante (ambra).
3. **Impatto Cassa (Netto Bancario):** Proiezione esatta del saldo di conto corrente post-operazione.

#### Funzionalità — Precisione Operativa & Legale

- **Calcolo Bonifico Netto:** La card "Impatto Cassa" scorporo le Ritenute d'Acconto, mostrando l'esatto importo del bonifico netto da disporre in banca.
- **Filtro Conti Liquidi:** Il menu "Conto Addebito" mostra esclusivamente Banche, Poste, Cassa Contanti.
- **Prescrizione Quinquennale:** Se la "Data di origine del debito" supera i 5 anni, scatta un alert rosso "Rischio Prescrizione" (Art. 2948 c.c.).

---

### [1.9.0-beta.23] — Dashboard Intelligence & Clean Ledger

#### Funzionalità — Dashboard & Deficit Operativo

- **Scoperto Operativo Reale:** Il box allerta ("Mancano € X") somma solo le spese scoperte che richiedono l'emissione di rate, ignorando i fondi avanzati in altri capitoli stagni.
- **Pulizia Cognitiva:** Rimosso il widget ridondante "Fatture in sospeso" dalla vista principale.

#### Funzionalità — Audit Spese Scoperte (Modale "Financial X-Ray")

- **Separazione Semantica:** "Fatture in sospeso" (Imprevisti e Art. 63) separate dagli "Sforamenti Budget Preventivo".
- **Esploso Fattura (Line-Level Breakdown):** Le fatture miste fuori budget mostrano il dettaglio riga per riga (Parte comune vs Art. 63 con indicazione dell'unità).
- **Smart Routing:** Deep-Link al wizard Piano Rate con auto-popolamento (`?tipo=straordinario&origine=dashboard&gestione_id=...&fatture[]=...`).

#### Funzionalità — Piano dei Conti (Clean Ledger UI)

- **Separazione Visiva Albero dei Conti:** "Preventivo deliberato" (modificabile) vs "Sopravvenienze e imprevisti" (sola lettura).
- **Sdoppiamento Totali:** Header con due badge distinti (es. *Preventivo: € 5.000* | *Sopravv: € 134*).
- **Badge Legale Art. 1130-bis c.c.:** Banner ambra nel dettaglio delle voci tecniche.

#### Bugfix — Core Logic & Type Hardening

- **Inertia.js FormData Sanitization:** Risolto perdita dati di `immobile_id`. Introdotto `form.transform` con casting rigoroso in `Number` o `null`.
- **Race Condition Inertia/URL:** Gli ID fatture vengono ora salvati in una ref dedicata durante `onMounted`, sopravvivendo alla riscrittura URL del router.
- **Prevenzione Falsi Positivi Booleani:** La query SQL intercetta correttamente i fallback numerici (`0`) delle colonne booleane (`is_rateizzata`) su MySQL/SQLite.

#### Backend — Hardening Legale & Tracciabilità

- **Migrazione Unificata:** `is_tecnico` su `conti`; `origine_tipo`, `stato_legale`, `stato_legale_aggiornato_at`, `riga_fattura_id`, `voce_id` su `rate_quote`; `is_rateizzata` su `righe_fattura`; `contesto_creazione` su `piani_rate`. Include data migration retroattiva (D1–D5).
- **Scope `visibili()` su Model Conto:** Filtra `is_tecnico=false`. Applicato su `FetchCapitoliContiController`, `FetchCapitoliPerGestioneController` e `PianoRateController::store()`.
- **Euristica `origine_tipo` / `stato_legale`:** `GenerateRateQuotesAction` popola automaticamente `condominiale` vs `ad_personam` e `certo` vs `contestabile`.
- **Semaforo Dashboard (`is_rateizzata`):** `true` alla creazione del piano straordinario, `false` alla cancellazione.
- **Contesto Creazione Piano Rate:** Enum `contesto_creazione` — `preventivo_iniziale` / `integrazione_dashboard` / `libero_manuale`.

---

### [1.9.0-beta.22] — Fund Governance & Audit-Ready Resources

#### Funzionalità — Governance Patrimoniale (Legal Compliance)

- **Motore a Regole Giuridiche:** Il sistema mappa la natura giuridica del fondo (`sottotipo_fondo`: Generico, Vincolato per Lavori, Accantonamento TFR, Morosità).
- **Audit Trail e Sblocco in Deroga:** I fondi vincolati nascono bloccati di default. `is_override_assemblea` richiede obbligatoriamente gli estremi della delibera o della giustificazione legale.
- **Single Source of Truth:** `is_utilizzabile_per_imprevisti` è calcolato dinamicamente dal Modello Eloquent.

#### Funzionalità — Enterprise Data Table (Risorse e Fondi)

- **Allineamento Matematico:** Colonna saldi con `tabular-nums` e allineamento a destra.
- **Semantica degli Stati:** "Libero" (Verde), "Vincolato" (Rosso), "Sbloccato in deroga" (Viola).
- **Smart Truncation & Type Hardening:** Troncamento a 40 caratteri. Sostituiti i cast `any` con interfacce TypeScript rigorose (`TipoCassa`, `SottotipoFondo`).

---

### [1.9.0-beta.21] — The Financial X-Ray & Single Source of Truth

#### Funzionalità — Spaccato Finanziario Trasparente (Tenant Wallet UX)

- **Pannello "X-Ray" (Sheet):** Interfaccia a scorrimento laterale con lo "scontrino matematico" di ogni condòmino.
- **Scomposizione Dinamica dei Debiti:** Quote millesimali ordinarie, spese private dirette (Art. 63) e saldi pregressi.
- **Raggruppamento per Immobile:** Divide i calcoli per ogni singola unità immobiliare.

#### Funzionalità — UI/UX

- **Azioni Contestuali (Dropdown Menu):** Bottone spaccato integrato in un menu a tre puntini nella colonna "Saldo".
- **Correzione Penny-Perfect (Fallback Zero-Quota):** Se i comproprietari hanno quote millesimali a zero, il sistema applica automaticamente una divisione equa (es. 50%/50%).

---

### [1.9.0-beta.20] — The Extraordinary Engine & Polymorphic UI

#### Funzionalità — Piani Rate Straordinari (Il Bivio & Art. 1135 c.c.)

- **Architettura a Doppio Binario:**
  1. **Ordinario** — Basato sul bilancio preventivo.
  2. **Straordinario** — Slegato dal preventivo, si alimenta dal "Carrello Fatture".
- **Scudo Legale Obbligatorio:** `CreatePianoRateRequest` richiede "Delibera Assembleare" o "Urgenza" con gli estremi.

#### Funzionalità — Polimorfismo Contabile & Dashboard Intelligence

- **Polimorfismo delle Risorse:** `PianoRateResource` maschera le fatture straordinarie come capitoli di spesa. Tutto l'ecosistema frontend continua a funzionare senza duplicazioni.
- **Widget "Sforo Recuperato":** Badge blu **"INTEGRATO — Sforo Recuperato"** quando una sopravvenienza viene finanziata.
- **Smart Push-Down Straordinario:** `BudgetCoverageService` (Step 3) inietta la copertura direttamente nel nodo dell'Albero dei Conti, colorando la barra al 100% (Smeraldo).

#### Funzionalità — Motore Penny-Perfect & Ripartizione Mista

- **Supporto Fatture Miste:** Spese comuni (millesimi) + addebiti personali diretti (`immobile_id`) nella stessa fattura.
- **Quadratura Frazionale Assoluta:** I resti decimali vengono assorbiti sulle prime rate (es. 3 rate da 6,62 € e 9 da 6,60 €). Corrispondenza al centesimo con il documento fiscale garantita.

---

### [1.9.0-beta.19] — The Triple Recovery Strategy & Reserve Fund Engine

#### Funzionalità — Gestione Intelligente Sforamenti (Il Tridente)

Tre strategie distinte e mutualmente esclusive per gli sforamenti di budget (Art. 1135 c.c.):

1. **Attesa Conguaglio** — Debito "silenzioso" da richiedere a chiusura esercizio.
2. **Rata Integrativa** — Allarme attivo verso l'emissione di un piano rate straordinario.
3. **Fondo di Riserva** — Assorbe istantaneamente lo sforo attingendo a un fondo patrimoniale preesistente.

#### Funzionalità — Integrazione Contabile Fondi

- **Automazione Partita Doppia:** L'utilizzo del Fondo Riserva genera scritture contabili reali nel Libro Giornale: **AVERE** sul mastro Cassa/Fondo, **DARE** sul mastro Sopravvenienze.

#### Funzionalità — Dashboard & Visual Intelligence 2.0

- 🟢 **Verde Smeraldo [Coperto da Fondo]** — Spesa già neutralizzata finanziariamente.
- 🟣 **Indaco [Sforo Autorizzato]** — Sforo destinato al conguaglio di fine anno.
- 🟠 **Ambra [Emetti Rate]** — Spesa che richiede azione immediata.
- **Stato "Bilancio Integrato":** Se tutte le spese scoperte hanno una strategia assegnata, il widget mostra **INTEGRATO**.

#### Funzionalità — Risorse e Cassa (Real-Time Balancing)

- **Calcolo Saldo Dinamico:** `Iniziale + DARE − AVERE` ricalcolato in tempo reale dal Libro Giornale, eliminando discrepanze tra gestione e contabilità pura.

---

### [1.9.0-beta.18] — Mixed Allocation & Dynamic Ledger

#### Funzionalità — Ripartizione Mista e Addebiti Personali

- **Spaccatura Fattura (Line-Level Splitting):** Un singolo documento fiscale può essere suddiviso in infinite righe di dettaglio, ciascuna con logica di ripartizione indipendente.
- **Addebito Diretto su Unità (`immobile_id`):** Una riga di spesa può essere assegnata a una singola unità. Il motore ignora i millesimi e addebita il 100% al proprietario interessato.
- **Fine delle Tabelle Fittizie:** Eliminata la necessità di creare tabelle millesimali finte (es. 1000/1000 su un singolo condomino) per le spese ad personam.

#### Funzionalità — Sopravvenienze Passive

- **Gestione Imprevisti "On-the-Fly":** Interruttore "⚡ Spesa imprevista" su ogni riga della fattura. Il sistema dirotta l'importo su "Sopravvenienze Passive" nel Libro Giornale.
- **Bilanci Trasparenti (Art. 1130-bis c.c.):** Le spese d'emergenza non inquinano i capitoli ordinari. Nel consuntivo l'assemblea vede una voce separata per tutti gli imprevisti.

#### Hardening — Database Fortification & UI Safety

- **Filtro "Fortezza" sul Piano dei Conti:** Blocco bidirezionale (Frontend + Backend) che impedisce la registrazione su Macro-Capitoli (nodi padre) o su voci orfane.
- **Backend Hard-Lock:** `FatturaPassivaService` lancia un'eccezione bloccante se rileva un tentativo su un conto privo di Mastro in Partita Doppia.

---

### [1.9.0-beta.17] — Legal Guardian & UI Precision

#### Funzionalità — Conformità Legale (Gate Legale Art. 1135 c.c.)

- **Workflow di Approvazione Blindato:** Blocco normativo che impedisce di rendere esecutivo un Piano Rate senza delibera formale.
- **Modale Delibera Assembleare:** La transizione "Bozza" → "Approvato" richiede Data Delibera, Numero Verbale e Note.
- **Audit Trail:** Tracciamento automatico di `approvato_il` e `approvato_da_user_id`.
- **Badge Legale Visivo:** Indicatore semantico nell'intestazione del Piano Rate (icona a martelletto).
- **Ripristino Sicuro:** Il ritorno allo stato "Bozza" cancella automaticamente i dati della delibera e l'audit trail.

#### Ottimizzazione — Smart Sync & Backend

- **Filtro Zero-Importo:** Il sistema esclude automaticamente i capitoli a 0,00 €, prevenendo falsi allarmi di sincronizzazione.
- **Pulsante Azione Dinamico:** Arancione "Sincronizza" se ci sono nuove voci scoperte, standard "Ricalcola" altrimenti.

#### Bugfix — UX & Radix UI

- **Fix Posizionamento HoverCard:** Risolto bug di "salto" a coordinate `0,0`. Forzato l'ancoraggio con `side="bottom"`.
- **Sincronizzazione Stato UI:** Se l'utente annulla l'inserimento della delibera, lo switch Vue torna istantaneamente allo stato reale del database.

---

### [1.9.0-beta.16] — Accounting Intelligence & Precision

#### Funzionalità — Motore Finanziario in Centesimi (MoneyHelper)

- Rimosso l'uso dei float nativi PHP per i calcoli finanziari. Integrata la classe `MoneyHelper` in tutto il ciclo di incasso.

#### Funzionalità — Gestione Debiti Pregressi e Double Lock

- **Meccanismo Double Lock:** Quadratura perfetta tra competenza economica, situazione patrimoniale e liquidità reale.
- **5 scenari gestiti:** Copertura Totale · Crisi di Liquidità · Proiettile Vagante (Sopravvenienza) · Copertura Mista (Split) · Fondo di Riserva.

#### Funzionalità — Ordinamento Visivo a Cascata (Waterfall)

- I movimenti **DARE** (Addebiti) precedono visivamente quelli in **AVERE** (Incassi), garantendo una curva del saldo priva di "falsi rossi".

#### Bugfix

- **Race Condition "NON PAGATA":** `ricalcolaStato()` spostata per eseguire *dopo* l'effettivo `attach()` dei pagamenti.
- **Sbilanciamento Incassi Misti:** Introdotto il controllo `$budgetCashCents` in `StoreIncassoRateAction` per impedire alla scrittura di cassa di consumare debito virtuale.

---

### [1.9.0-beta.15] — Tenant Experience & UI

- **Smart Wallet (Salvadanaio Condòmino):** Design "Digital Wallet" con breakdown matematico trasparente (costo rata, credito applicato, nuovo totale).
- **Credito Puro (Zero-Payment):** Card blu per le rate che generano credito netto. Istruzioni per il bonifico e pulsanti di pagamento nascosti automaticamente.
- **Sincronizzazione Dinamica UI:** L'area condòmini reagisce istantaneamente a qualsiasi azione dell'amministratore (incasso, storno, annullamento emissione).

---

### [1.9.0-beta.14] — Accounting Engine & Sync (Ciclo Attivo)

- **Motore di Storno "Self-Healing":** Fotografia preventiva dei soggetti coinvolti, inversione della partita doppia e ripristino chirurgico del debito sulle quote originali.
- **Onboarding Silenzioso:** Risolto bug critico nel rilascio delle "Rate Silenziose": il sistema filtra i pagamenti esclusivamente per la singola anagrafica.
- **Prevenzione Falsi Positivi JSON:** Il flag `is_emitted` viene interpretato correttamente a prescindere dal tipo di dato (booleano, intero o stringa).

---

### [1.9.0-beta.13] — Bugfixes & Ottimizzazioni

- **Fix Popup di Storno:** Corretto importo "€ 0,00" nel dialog di conferma. Il frontend riconverte correttamente i decimali in centesimi.
- **Fix Vue Warnings:** Aggiunta la prop `esercizi` mancante nel controller lista incassi.
- **Protezione Query Relazionali:** Sostituiti i fragili `whereIn` su campi JSON nidificati con costrutti logici multi-tipo più robusti.

---

### [1.9.0-beta.12] — Tenant Experience & Payment Loops

- **Debito Pregresso non "Scaduto":** La Rata 0 usa un design ambra dedicato con la dicitura "Debito Pregresso" invece del badge rosso "Scaduta".
- **Positive Feedback Loop (Rata Saldata):** Badge verde "Pagamento Ricevuto" e box trionfale "Rata Saldata" dopo la registrazione dell'incasso.
- **Self-Healing Loop (Pagamenti Rifiutati):** Se l'amministratore rifiuta una segnalazione, la modale riattiva i controlli con il bottone "Ho ri-effettuato il pagamento (Segnala di nuovo)".
- **Smart Visibility Bypass:** Risolto falso positivo che manteneva bloccati i pulsanti di pagamento con "Pagamento non ancora attivo".

---

### [1.9.0-beta.11] — Time-Travel Accounting (Debito Esercizio Precedente)

- **Caricamento Fatture Pregresse:** Registrazione di fatture datate in anni passati senza inquinare il bilancio dell'anno in corso.
- **Smart Date Check:** L'interfaccia Vue riconosce automaticamente le fatture di esercizi chiusi e mostra lo "Scudo Giallo" (Debito Esercizio Precedente).
- **Esenzione Budget Attiva:** Attivando "Debito Pregresso", il sistema disinnesca l'allarme "Sforamento Budget".
- **Partita Doppia Invisibile:** `FatturaPassivaService` devia gli importi sul conto **"Fondo Passate Gestioni"** invece dei capitoli ordinari.
- **Badge `[Archive Pregresso]`** nella Data Table principale delle fatture passive.

---

### [1.9.0-beta.10] — Silent Emission & Inbox Zero

#### Funzionalità — Emissione Silenziosa

- **Toggle "Rendi visibile e invia notifiche":** Emissione "in incognito" — scritture contabili generate, condòmini non notificati. Essenziale per caricare massivamente pagamenti pregressi senza generare allarmi.
- **Pulsante "Pubblica Nascoste":** Compare automaticamente solo se ci sono rate congelate. Sblocca la visibilità globale e invia tutte le notifiche in un colpo solo.
- **HoverCard Context-Aware:** Spiega dinamicamente lo stato del piano (Bozza, Approvato, Bloccato) e le azioni possibili.

#### Funzionalità — Inbox Zero

- **Uccisione Mirata (`verifica_pagamento`):** Quando un incasso porta a zero il debito di un condòmino, il promemoria di verifica viene cancellato all'istante.
- **Uccisione Globale (`controllo_incassi`):** Se il pagamento completa il 100% dell'incasso dell'intera rata, il task generico di controllo viene rimosso automaticamente.
- **Cache Buster Integrato:** Il badge numerico rosso sulla campanella sparisce all'istante.

---

### [1.9.0-beta.9] — Tenant Wallet UX & Smart Intent Sync

#### Funzionalità — Smart Intent Sync (Ponte Condòmino-Admin)

- **Il Salvadanaio:** Widget interattivo che calcola se il credito è sufficiente a coprire l'intera rata o se è necessaria un'integrazione tramite bonifico.
- **Dichiarazione di Compensazione:** Il condòmino notifica la volontà di usare il credito (es. "Salda con il credito" o "Ho pagato la differenza").
- **Inbox Admin Contestuale:** L'evento generato esplicita testualmente l'intento (es. *"Il condòmino ha richiesto di usare 100 € del suo salvadanaio, aspetta un bonifico di 12,48 €"*).
- **Guida Operativa Visiva:** `IncassoRateNew` rileva l'intento di compensazione tramite `intent_usa_credito` nell'URL e mostra un Alert Giallo strategico.

#### Bugfix

- **Lazy Loading Saldi Pregressi:** `SyncScadenziarioWithPianoRate` interroga il database per i crediti su Rata 0 solo se `metodo_distribuzione === 'rata_zero'`.
- **Bugfix "Paradosso Arretrati":** L'alert arretrati compare ora solo sulle vere rate a debito, non sulle Rata 0 a credito.

---

### [1.9.0-beta.8] — Smart Wallet & Payment Intelligence

#### Funzionalità — Smart Wallet

- **Single Source of Truth:** Importi nominali esatti allineati a PDF e App condòmini.
- **Pulsante "Compensa Credito":** Se il credito supera la rata, il sistema preleva solo l'esatto importo necessario (Smart Withdrawal), mantenendo il resto nel salvadanaio.
- **Anteprima Scrittura Dinamica:** Mostra diciture specifiche (es. *"Credito rimanente nel salvadanaio: € 88,00"*) prima di salvare.

#### Funzionalità — UI/UX Maschera Incassi

- **Feedback Cromatico:** Verde sgargiante per crediti, rosso acceso per debiti pregressi urgenti.
- **Smart Truncation & Hover Text:** Nomi compressi automaticamente con tooltip per l'elenco completo dei comproprietari.
- **Filtro "Mostra solo scadute":** Include le rate che scadono nella giornata odierna. Mantiene sempre "appuntata" la Rata 0.
- **Input Protections:** I campi importo si disabilitano quando la rata è a zero o è un credito puro.

---

### [1.9.0-beta.7] — Visual Harmony & Smart Filters

#### UI/UX — Design System

- **Widget Guide Contestuali (`PageHeaderGuide`):** Header con breadcrumbs e card informative dinamiche in tutti i moduli operativi.
- **Statistiche Semantiche (Pastel Design):** Colori semantici per urgenza (Rosso, Ambra, Smeraldo, Blu, Violetto, Rosa).
- **Tabelle "Card Style":** DataTables in contenitori `rounded-2xl` con `shadow-sm`.

#### Funzionalità — Smart Filters & Backend

- **Filtro "Condominio" Persistente:** Selettore a tendina con stato salvato dopo il ricaricamento.
- **Dynamic Clear Button:** Compare solo se è applicato almeno un criterio di ricerca.
- **Backend Query Fix:** Aggiunte istruzioni SQL mancanti in `SegnalazioneService` e `DocumentoService` (`whereIn` per 1:N, `whereHas` per N:N).

---

### [1.9.0-beta.6] — Active Budget Guardian & UI Refinements

#### Funzionalità — Active Budget Guardian

- **Allarmi Gerarchici:**
  1. 🔴 **Disallineamento** — Piano rate da ricalcolare urgentemente.
  2. 🟠 **Voci Orfane** — Nuove spese a preventivo non assegnate a nessun piano.
- **Azione Diretta:** I banner di allarme includono pulsanti operativi (es. "Apri", "Analizza Voci").

#### UI/UX — Piano dei Conti

- **Total Budget Badge:** Badge dinamico nell'header con la somma totale del preventivo.
- **Smart Edit Modal:** Integra selettori "Fornitore Suggerito" e "Natura Spesa (Fiscale)". Box informativi contestuali per Hard Lock vs Soft Lock.

---

### [1.9.0-beta.5] — Smart Waterfall & Transparent Ledger

#### Funzionalità — Smart Waterfall Logic

- **Pianificazione Intelligente Saldi:** I crediti/debiti pregressi su più immobili vengono distribuiti a cascata, evitando "rate negative".
- **Incassi Cumulativi Automatici:** `StoreIncassoRateAction` scompone automaticamente un singolo bonifico globale, saldando le quote in ordine progressivo.

#### Funzionalità — Estratto Conto: Transparent Ledger

- **Matematica Inviolabile:** La tabella dei movimenti mostra esclusivamente gli importi puri in Dare/Avere.
- **UI/UX Esplicativa:** Scritte dinamiche (es. *"👉 Include recupero debito pregresso: € 100"*).
- **Graceful Fallback:** Le rate generate con la v1.8 (prive di snapshot JSON) vengono elaborate in modalità standard per totale retrocompatibilità.

---

### [1.9.0-beta.4] — Visual Intelligence & Dashboard Audit

#### Funzionalità — Visual Intelligence (Smart Radar)

- **Semantic Fund Tracking:** 🎯 Diretta · ↳ Da Capitolo · 📈 Spostamento (Viola) · 🔀 Mista.
- **Gestione "Overbudget Sano":** Distinzione tra Eccedenza Critica (Rosso) ed Extra Budget Gestito (Viola).
- **Badge "Squircle":** Design `rounded-md` con icone Lucide.

#### Funzionalità — Core Logic & Dashboard

- **Smart Dashboard Reconciliation:** Se il Delta Globale è a pareggio (tolleranza < 5 €), la modale "Audit spese scoperte" viene soppressa e compare il widget verde "Bilancio Allineato".
- **Equal Deficit Distribution:** I fondi del padre vengono distribuiti equamente tra i figli in deficit matematico.

#### Refactoring

- **Currency Composable:** `useCurrencyFormatter.ts` centralizza la logica di formattazione monetaria.
- **CSS Cleanup:** Rimossa la sezione `<style>` legacy (300+ righe) dai componenti di dettaglio conto.

---

### [1.9.0-beta.3] — Penny Perfect & Smart Push-Down

#### Funzionalità — Accounting Core Intelligence: Evolution

- **Frazionamento Voci di Spesa (Partial Budgeting):** Inclusione nei Piani Rate di solo una quota parte dell'importo totale (es. acconto di 400 € su 1.000 €). Il sistema traccia il "Residuo Disponibile" per i piani successivi.
- **Algoritmo "Penny Perfect":** Il motore `CalcoloQuoteService` garantisce che la somma delle quote corrisponda al 100% dell'importo al centesimo. Il resto decimale viene assorbito dall'ultimo beneficiario.
- **Logica "Smart Folder Push-Down":** Se si assegna un importo forzato a un Capitolo Padre, il sistema calcola automaticamente il rapporto proporzionale e "spinge" l'override sui sottoconti figli.
- **Piani Integrativi (No-Duplicate Balance):** Distinzione tra "Primo Piano" (applica i saldi pregressi) e "Piani Integrativi" (solo nuove spese).
- **Sposta Spesa (Budget Reallocation):** Spostamento quote di budget tra voci della stessa gestione. Audit Trail nella tabella `budget_movements`. History Popover con la genesi dell'importo (es. *Originale 300 € − 100 € spostati = Attuale 200 €*). Protezione bidirezionale contro la rimozione di voci coinvolte in uno spostamento pendente.

#### Bugfix

- **Correzione Ricorsione Override:** L'importo del padre non si somma più erroneamente a quello dei figli nel calcolo dei piani parziali.
- **Fix Totali Widget Copertura:** La risorsa API legge correttamente gli importi parziali dalla tabella pivot.
- **Mass Assignment Protection:** Aggiunti `saldo_applicato` e `nota_saldo` al modello `Gestione`.

---

### [1.9.0] — Base Release *(Accounting Intelligence Core)*

#### Funzionalità — Accounting Core Intelligence

- **Ancoraggio Atomico & Gerarchico:** I piani rate vengono collegati a specifici capitoli di spesa tramite tabella pivot. Auto-popolamento per i piani globali. Selettore capitoli con logica Padre/Figlio.
- **Collision Detection (Anti-Double Billing):** Il sistema impedisce matematicamente di inserire la stessa voce di spesa in due piani rate attivi contemporaneamente.
- **Double-Lock Strategy:** Protezione saldo applicato + Hard-Lock a livello Controller per impedire duplicazioni su altre gestioni.
- **Dashboard Audit & Copertura:** Widget "Semaforo Contabile" — Preventivo vs Pianificato in tempo reale con segnalazione delle voci "Orfane".
- **Sincronizzazione Intelligente (Smart Sync):** Workflow guidato per integrare le voci orfane nei piani rate esistenti.
- **Blocco Cancellazione Preventivo:** Protezione in `ContoController` per impedire l'eliminazione di voci ancorate a piani attivi.

#### Funzionalità — System & Hosting Compatibility

- **Database Flexibility:** Supporto charset diversi da `utf8mb4` (compatibilità legacy MySQL/Altervista).
- **Hosting Condiviso & HTTPS:** Logica per forzare HTTPS e gestire i reverse proxies (`TRUSTED_PROXIES`).
- **Gestione Cron Job Remoti:** Attivazione processi pianificati tramite chiamata HTTP esterna sicura con token cifrato.
- **Configurazione SMTP via UI:** Configurazione server di posta direttamente da pannello, senza editare `.env`.

#### Bugfix

- **CRITICO — Cross-Condominium Pollution:** Risolto bug grave nel calcolo degli arretrati che aggregava erroneamente i debiti dello stesso proprietario su condomini diversi.
- **Duplicazione Saldi:** Risolto problema che impegnava irreversibilmente il saldo alla creazione del piano rate.
- **Pulizia Rate Orfane:** Logica automatica per ignorare rate collegate a piani cancellati o gestioni obsolete.
- **Validazione Obbligatoria Tabelle:** Il campo "Tabella Millesimale" è ora obbligatorio per ogni voce di spesa.

---

## [1.8.0] — The Smart Assistant Update

> Cambio di paradigma: Kondomanager passa da semplice archivio dati ad **Assistente Proattivo**.
> La nuova Smart Activity Inbox genera e suggerisce scadenze in modo intelligente.
> Introdotti gli aggiornamenti frontend per hosting condivisi senza accesso alla console.

### Funzionalità — Core & Automazione

- **Smart Activity Inbox:** Il nuovo motore eventi trasforma il calendario in un assistente virtuale. Il sistema genera e suggerisce eventi collegati alla generazione e ai pagamenti delle rate per una gestione proattiva delle scadenze.
- **Aggiornamenti Automatici da Frontend:** Aggiornamento di Kondomanager direttamente dal pannello di amministrazione, senza accedere alla console del server. Dedicato agli utenti dell'installazione guidata.
- **Condominio di Default al Login:** Nelle impostazioni generali è possibile impostare un condominio da aprire automaticamente al login. Personalizzabile per ogni utente (admin o collaboratore).

### Funzionalità — Contabilità & Gestione

- **Gestione Fornitori:** Modulo completo per la creazione e gestione delle anagrafiche fornitori.
- **Casse del Condominio:** Creazione e gestione delle risorse finanziarie e delle casse condominiali.
- **Emissione Rate Evoluta (Capitoli di Spesa):** Possibilità di emettere rate parziali o mirate selezionando specifici capitoli di spesa (es. generare rate solo per "Scala A").
- **Piani Rate Multipli:** Ogni gestione mantiene un singolo piano dei conti ma può supportare più piani rate.
- **Registrazione Pagamento Rate:** Nuova interfaccia dedicata per la registrazione rapida dei pagamenti.
- **Ottimizzazione Incassi Multi-gestione:** Supporto per pagamenti che coprono più gestioni, con riconciliazione virtuale visibile nei report.
- **Estratto Conto:** Visualizzazione dell'estratto conto direttamente nell'anagrafica del condòmino.
- **Statistiche Dashboard:** Nuovi moduli statistici nella home page amministratore.

### Funzionalità — Internazionalizzazione

Aggiunto il supporto completo per **Inglese** e **Portoghese**:

- Impostazioni Generali e interfaccia Frontend.
- Modulo Comunicazioni in Bacheca.
- Modulo Autenticazione e Registrazione.
- Notifiche Email transazionali.
- Modulo Documenti/Archivio.
- Modulo Segnalazioni Guasti.

### Funzionalità — DevOps

- **Supporto Docker:** Guida ufficiale e file di configurazione per il deploy tramite Docker. *(Thanks @k3ntinhu)*

### Miglioramenti

- **Nuovo Menu "Rubrica":** La voce "Anagrafiche" diventa "Rubrica" con menu a tendina per accesso rapido a Condòmini e Fornitori.
- **Visualizzazione Permessi Rapida:** Le tabelle Utenti e Ruoli mostrano direttamente i permessi associati nelle colonne.
- **Gestione Intelligente Permessi:** Migliorata la logica di assegnazione e revoca permessi durante la modifica di Utente o Ruolo.
- **Smart Associazione Immobili:** Il menu a tendina mostra solo le anagrafiche presenti nel condominio ma non ancora associate a quell'immobile specifico, prevenendo duplicazioni.
- **Filtro Preventivi nel Piano dei Conti:** Il controller mostra solo le gestioni che non hanno ancora un preventivo associato.
- **Integrazione Widget Eventi:** Il widget eventi nella dashboard è collegato alla Smart Activity Inbox.
- **UX Piani Rate:** Migliorata la visualizzazione e le funzioni operative nella gestione piani rate.

### Bugfix

- **Valori Negativi:** Risolto bug che impediva l'inserimento di valori negativi nelle maschere di input delle anagrafiche associate all'immobile (utile per conguagli o crediti pregressi).
- **Registrazione Utenti Invitati:** Risolto problema che impediva agli utenti invitati via email di completare la registrazione con la registrazione pubblica disabilitata.
- **Sicurezza Password:** Implementato controllo per impedire il riutilizzo della password corrente durante il cambio password. *(Thanks @borghiste — Issue #30)*

---