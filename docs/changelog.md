# Changelog

Tutte le modifiche rilevanti a Kondomanager sono documentate in questo file.

Il formato segue [Keep a Changelog](https://keepachangelog.com/it/1.0.0/)
e il progetto adotta il [Versionamento Semantico](https://semver.org/lang/it/).

---

## [Unreleased] — 1.9.1 Cash Statements

> Pagamento delle fatture passive, riconciliazione bancaria e rendiconto di cassa.
---

## [1.9.1-beta.9] - Hotfix UI Piano dei Conti & Action Inbox Upgrade

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

## [1.9.1-beta.8] - Modulo Commenti per Segnalazioni Guasto

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

## [1.9.1-beta.7] - Filtri Interattivi, Chiarezza Visiva e Tracciabilità UI

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

## [1.9.1-beta.6] - Storno Pagamenti e Ledger Immutabile
### Aggiunto
- **Storno Pagamenti (Ledger Immutabile)**: Modulo completo (backend e UI) per l'annullamento di pagamenti errati o respinti (es. insoluti bancari). Il sistema garantisce l'integrità contabile registrando una scrittura inversa append-only, riaprendo automaticamente le fatture coinvolte e ripristinando la cassa, senza cancellare record storici.
- **Storni Cross-Esercizio**: Gestione intelligente degli storni su bilanci chiusi. Se l'esercizio del pagamento originale è chiuso, il sistema non permette la modifica retroattiva ma registra l'operazione di storno nell'esercizio corrente aperto, salvaguardando i saldi storici consolidati.
- **Sincronizzazione Action Inbox e Pagamenti**: Implementato un nuovo listener (`SyncScadenziarioWithPagamento`) che collega la registrazione e lo storno dei pagamenti ai task amministrativi dell'Inbox. La registrazione di un pagamento ora segna automaticamente come completato il task "Pagare fornitore", rimuovendolo dalle urgenze dell'amministratore, mentre uno storno lo riapre immediatamente, ripristinandone la priorità. 
- **UX Ottimizzata Action Inbox**: Risolvendo il task di pagamento dall'Inbox, l'utente viene reindirizzato automaticamente al modulo di registrazione pagamento pre-compilato, azzerando i tempi di ricerca e garantendo un'esperienza fluida.
- **Test Automatici Contabili**: Aggiunta un'ampia suite di test automatici per le logiche di storno avanzato, inclusi storni di pagamenti cumulativi multi-fattura e storni complessi compensati con Note di Credito (netting), garantendo quadratura perfetta DARE/AVERE, oltre a test automatizzati per il ciclo di vita dei task nell'Admin Inbox.

### Corretto
- **Compatibilità SQL Strict Mode (`ONLY_FULL_GROUP_BY`)**: Risolto l'errore 1055 nel modulo Treasury Guardian che bloccava la dashboard su server MySQL 8.0 o configurazioni strict. Il refactoring elimina i `GROUP BY` manuali, delegando il calcolo delle allocazioni a Laravel tramite aggregazioni Eloquent (`withSum`).

---

## [1.9.1-beta.5] - Treasury Guardian Widget MVP
### Aggiunto
- **Treasury Guardian Widget MVP**: Implementato il nuovo widget predittivo di tesoreria nella dashboard. Il sistema calcola automaticamente la proiezione dello scoperto di liquidità a 30 giorni, fornendo una classificazione del rischio (Verde, Giallo, Rosso) basata sulle fatture in scadenza e le rate emesse.
- **Call-to-Action Dinamiche (Smart UX)**: Le azioni suggerite si adattano ora al contesto di cassa. Il widget suggerisce di "Emettere Nuove Rate" in caso di esposizione al rischio senza incassi attesi, e di "Verificare o Sollecitare Incassi" se ci sono versamenti potenzialmente non registrati, con descrizioni leggibili (multi-line).

### Corretto
- **Quadratura Liquidità e Saldi Iniziali**: Risolto un disallineamento tra il calcolo del widget e il bilancio di verifica. Il motore ora somma correttamente il `saldo_iniziale` di cassa ai movimenti contabili di liquidità.
- **Calcolo Esatto degli Incassi Attesi**: Corretto l'algoritmo di stima degli incassi (rate in arrivo). Il sistema ora estrae esclusivamente i movimenti in AVERE (pagamenti ricevuti) evitando sovrastime derivanti dall'emissione in partita doppia, garantendo una stima predittiva perfetta al centesimo.
- **Statistiche Ritenute d'Acconto**: Corretto un problema statistico nella dashboard dei pagamenti che manteneva a zero il conteggio delle ritenute d'acconto. Il sistema calcola ora la ritenuta proporzionalmente al momento della registrazione del pagamento.
- **Action Inbox per Piani Straordinari & Sync**: Esteso il supporto della Action Inbox ai Piani Rate Straordinari (generazione immediata task di emissione rate e verifica incassi) e risolto un bug che non eliminava i vecchi eventi in caso di rigenerazione totale di un piano rate approvato.

---

## [1.9.1-beta.4] - Smart Error Handling Pagamenti
### Aggiunto
- **Smart Error Handling Pagamenti**: Nuovi modali intelligenti e contestuali per la gestione delle eccezioni di dominio durante il pagamento fornitori.
- **Audit Trail Responsabilità**: Tracciamento obbligatorio delle note di override per decisioni critiche (es. scoperto di conto, overpayment) ai sensi dell'art. 1129 c.c.
- **Sentinelle di Partita Doppia**: Controlli rigorosi e informativi su allocazioni inconsistenti e violazione del tetto contanti (D.Lgs. 231/2007).

### Corretto
- **Fix calcolo capienza Cassa**: Risolto un bug critico nel backend che ignorava il `saldo_iniziale` della Cassa nel calcolo del saldo corrente per il controllo fondi.

---

## [1.9.1-beta.3] — Dettaglio Fattura & Flusso Pagamento Rapido

> Aggiunta pagina di dettaglio fattura con visualizzazione completa di voci, importi, scadenze, documenti allegati, audit trail per l'Art. 1135 c.c., e possibilità di procedere immediatamente al pagamento.

### Funzionalità — Dettaglio Fattura

- **Pagina Dettaglio Fattura Passiva:** Aggiunta la vista dedicata per ispezionare tutti gli estremi della fattura. È presente il riepilogo documenti, importi (imponibile/iva), scadenza, badge stato approvazione e stato pagamento. Mostra il dettaglio delle righe contabilizzate, incrociando i capitoli di spesa del piano dei conti.
- **Audit Trail Ratifica Assembleare:** Se la fattura è stata approvata in seguito a uno "sforo motivato" (Art. 1135 c.c.), la pagina di dettaglio espone ora una sezione di Audit Trail (banner in evidenza) con autore, orario di approvazione e nota verbale.
- **Flusso "Paga Ora":** Un pulsante verde in corrispondenza dei badge ("Paga Fattura") consente di saltare immediatamente alla pagina di registrazione pagamento, auto-selezionando il fornitore e marcando l'intera fattura per il saldo in un solo click, con caricamento istantaneo delle pendenze residue.
- **Ritenute d'Acconto:** Aggiunta una nota riepilogativa nel dettaglio fattura se il compenso è soggetto a ritenuta d'acconto, incluse le specifiche dell'aliquota (%) e del tributo assegnato.
- **Approvazione base:** Possibilità di passare lo stato da "Da Approvare" ad "Approvata" direttamente dal menu azioni riga (per fatture interne che non costituiscono sforo motivato), permettendone il rapido sblocco per il saldo.

---

## [1.9.1-beta.2] — Ratifica Assembleare Sforo Motivato & Legal Compliance

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

## [1.9.1-beta.1] — Registro Pagamenti Fornitori, Statistiche Incassi & Hardening UI/UX

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