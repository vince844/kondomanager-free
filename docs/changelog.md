# Changelog

Tutte le modifiche notevoli a questo progetto saranno documentate in questo file.

## [1.9.9] - Tenant Wallet UX & Smart Intent Sync

Questa release chiude il cerchio del sistema "Smart Wallet", estendendo le potenzialità della gestione crediti direttamente all'area riservata dei condòmini. È stato introdotto un sistema di "Comunicazione di Intenti" che permette al condomino di decidere attivamente come usare i propri fondi, mantenendo l'amministratore in totale controllo della contabilità.

### 🤝 Smart Intent Sync (Ponte Condòmino-Admin)
* **Frontend Condòmino (Il Salvadanaio):** Se un condòmino vanta un credito pregresso (su Rata 0), la modale di pagamento della rata mostra ora un widget interattivo ("Il tuo Salvadanaio"). Il sistema calcola matematicamente se il credito è sufficiente a coprire l'intera rata o se è necessaria un'integrazione tramite bonifico, mostrando i totali parziali con estrema chiarezza.
* **Dichiarazione di Compensazione:** Il condòmino può ora cliccare su pulsanti specifici per notificare all'amministratore la volontà di usare il credito (es. "Salda con il credito" o "Ho pagato la differenza"). 
* **Inbox Admin Contestuale:** L'evento generato nella Inbox dell'amministratore non mostra più il totale nominale della rata, ma esplicita testualmente l'intento (es. *"Il condòmino ha richiesto di usare 100€ del suo salvadanaio, aspetta un bonifico di 12,48€"*).
* **Guida Operativa Visiva:** Cliccando sulla notifica, la pagina di registrazione incasso (`IncassoRateNew`) rileva automaticamente l'intento di compensazione tramite parametro URL (`intent_usa_credito`) e mostra un Alert Giallo strategico, guidando l'amministratore a cliccare sul tasto "Usa Credito" per completare la quadratura.

### 🧠 Ottimizzazioni Architetturali & Bug Fixes
* **Lazy Loading dei Saldi Pregressi:** Ottimizzato il Listener `SyncScadenziarioWithPianoRate`. Il backend ora interroga il database alla ricerca di crediti su Rata 0 *solo ed esclusivamente* se il piano rate corrente è configurato con la strategia `metodo_distribuzione === 'rata_zero'`, azzerando query inutili per i piani a spalmatura.
* **Bugfix "Paradosso Arretrati" (`EventModal`):** Risolto un falso positivo visivo nell'area condòmini. Aprendo il dettaglio di una Rata 0 a credito, il sistema non mostra più l'alert arancione ingannevole relativo ad altre rate ordinarie non ancora saldate (ramanzina inappropriata su un documento di credito). L'alert arretrati compare ora solo sulle vere rate a debito.
* **Data Scadenza Notifiche Admin:** Il Task "Verifica Incasso" generato dal click del condòmino imposta ora il suo `start_time` al momento esatto del click (`now()`), garantendo la sua apparizione immediata e istantanea in cima alla Inbox dell'amministratore.

## [1.9.8] - Smart Wallet & Payment Intelligence

Questa release rivoluziona l'esperienza di incasso rate e la gestione dei crediti pregressi. Abbandonando vecchie logiche di alterazione visiva dei residui, la piattaforma adotta ora un approccio "Single Source of Truth" supportato da un vero e proprio "Portafoglio Virtuale" (Wallet) a disposizione dell'amministratore, garantendo quadratura contabile assoluta e massima flessibilità operativa.

### 💰 Smart Wallet (Gestione Compensazioni Reali)
* **Single Source of Truth:** La pagina di registrazione incassi mostra ora gli importi nominali esatti delle rate (allineati perfettamente ai PDF e all'App dei condòmini), eliminando la logica del finto "Waterfall" sul frontend che generava confusione visiva.
* **Pulsante "Compensa Credito":** L'amministratore può ora attingere a crediti precedenti (Rata 0 negativa) tramite un pulsante dedicato. I fondi virtuali si sommano ai contanti versati in tempo reale, aumentando la "potenza di fuoco" disponibile per saldare i debiti aperti.
* **Prelievo Intelligente (Smart Withdrawal):** Se un credito pregresso (es. 200€) è superiore alla rata da pagare (es. 112€), il sistema non "brucia" l'intero salvadanaio, ma preleva in automatico solo l'esatto importo necessario (112€), mantenendo il resto a disposizione per incassi futuri.
* **Anteprima Scrittura Dinamica:** Il widget di riepilogo riconosce l'utilizzo del credito e mostra diciture specifiche (es. *"Credito rimanente nel salvadanaio: € 88,00"*), garantendo che l'amministratore sappia esattamente cosa verrà registrato in contabilità prima ancora di salvare.

### 🧠 Logiche di Base e Architettura
* **Refactoring Modulo Incassi (`IncassoRateNew`):** Il componente Vue è stato completamente riprogettato con un'architettura chirurgica. Separazione netta tra stato, calcoli matematici isolati (`math.parse`) e azioni di sistema, eliminando oltre 100 righe di codice ridondante e garantendo una reattività immediata dell'interfaccia.
* **Ottimizzazione Tasto "Scadute":** La logica di autoselezione delle rate in ritardo è stata potenziata. Ora include correttamente le rate che scadono nella giornata odierna ("Oggi") e forza l'aggiornamento visivo immediato della UI senza perdere il focus.
* **Tipizzazione Rigorosa (TypeScript):** Estese e aggiornate le interfacce contabili (`Rata`, `DettaglioQuotaRata`) per supportare nativamente le nuove stringhe compresse dei nomi e i ruoli dei proprietari, garantendo una build a zero errori.

### 🎨 UI/UX Enhancements (Maschera Incassi)
* **Feedback Cromatico Istantaneo:** I residui in tabella utilizzano ora classi semantiche Tailwind avanzate: **Verde sgargiante** per i crediti a favore del condomino, **Rosso acceso** per i debiti pregressi urgenti, e grigio standard per l'amministrazione ordinaria.
* **Lettura Tabella Pivot Avanzata:** Il tooltip nero ("scontrino") ora interroga direttamente la tabella pivot per estrapolare dinamicamente il ruolo del pagante e mostrare badge dedicati (es. `[P]` per Proprietario, `[I]` per Inquilino), senza appesantire la query principale.
* **Smart Truncation & Hover Text:** In presenza di unità immobiliari con molteplici eredi (es. "Rossi, Bianchi + altri 18"), la UI comprime automaticamente la stringa per non rompere il layout della tabella. Passando il mouse sul nome (con cursore "help"), viene svelato il tooltip nativo con l'elenco completo di tutti i comproprietari.
* **Filtro "Mostra solo scadute":** Aggiunto un interruttore dinamico sopra la tabella per nascondere le rate future, mantenendo sempre "appuntata" in cima l'eventuale Rata 0 per non perdere mai di vista i saldi storici.
* **Input Protections:** I campi degli importi (MoneyInput) si disabilitano e vanno in "fade-out" quando la rata è esattamente a zero o è un credito puro, evitando errori di digitazione e registrazioni di "incassi su incassi". 

---
## [1.9.7] - Visual Harmony & Smart Filters

Continua il processo di modernizzazione e pulizia dell'interfaccia utente. Questa release uniforma il design system dei moduli operativi (Comunicazioni, Segnalazioni, Documenti e Agenda) e introduce filtri di ricerca avanzati e persistenti per una gestione multi-condominio più fluida.

### 🎨 UI/UX Redesign & Visual Harmony
* **Widget Guide Contestuali:** Aggiunto il nuovo componente `PageHeaderGuide` in tutte le pagine indice dei moduli operativi (Bacheca, Guasti, Archivio, Scadenze). L'header ora accoglie l'utente con breadcrumbs puliti e card informative dinamiche che spiegano "a colpo d'occhio" le funzionalità chiave del modulo, migliorando l'onboarding.
* **Statistiche Semantiche (Pastel Design):** Refactoring completo dei moduli statistici (`ComunicazioniStats`, `SegnalazioniStats`, `DocumentiStats`, `EventiStats`). Abbandonate le "Card" generiche in favore di contenitori flat con fondini color pastello a opacità ridotta. I colori (Rosso, Ambra, Smeraldo, Blu, Violetto, Rosa) sono ora assegnati semanticamente per comunicare istantaneamente il livello di urgenza (es. scadenze e guasti) o la classificazione del dato (es. spazio cloud).
* **Interattività Visiva:** I widget cliccabili (come le statistiche dell'Agenda che fungono da filtro) ora presentano un micro-feedback visivo al passaggio del mouse (sollevamento e ombra) per indicare chiaramente la loro interattività.
* **Tabelle "Card Style":** Tutte le DataTables (liste dati) sono state incapsulate in moderni contenitori smussati (`rounded-2xl`) con ombre leggere (`shadow-sm`), allineando il design a quello della nuova Dashboard Contabile.

### 🧠 Smart Filters & Backend Logic
* **Filtro "Condominio" Persistente:** Introdotto un nuovo selettore a tendina all'interno delle Toolbar delle tabelle (Segnalazioni e Documenti) che permette di filtrare rapidamente i record appartenenti a uno specifico fabbricato. Il sistema ora salva e mantiene lo stato del filtro anche dopo il ricaricamento dei dati.
* **Dynamic Clear Button:** Il pulsante "Svuota filtri" nelle DataTables ora è intelligente: compare a schermo solo se l'utente ha effettivamente applicato almeno un criterio di ricerca (testo, priorità, stato, o condominio).
* **Backend Query Fix:** Corretta un'anomalia nei Service Layer (`SegnalazioneService` e `DocumentoService`). Aggiunte le istruzioni SQL mancanti per processare correttamente l'array `condominio_id` proveniente dal frontend, implementando query ottimizzate (`whereIn` per le relazioni 1:N e `whereHas` per le relazioni N:N) che garantiscono risultati istantanei e precisi.

## [1.9.6] - Active Budget Guardian & UI Refinements

Questa release potenzia ulteriormente la "torre di controllo" dell'amministratore, introducendo un guardiano attivo sulla coerenza dei dati e migliorando la User Experience durante la compilazione dei preventivi.

### 🛡️ Active Budget Guardian (Validatore Disallineamenti)
* **Prevenzione Errori Strutturali:** La Dashboard ora monitora attivamente le modifiche retroattive al piano dei conti. Se l'amministratore modifica l'importo di una spesa (o di un suo sottoconto) *dopo* aver già generato il piano rate, il sistema intercetta immediatamente l'incoerenza.
* **Allarmi Gerarchici:** Introdotta una "cascata di priorità" negli avvisi della Dashboard:
    1. 🔴 **Priorità Massima (Disallineamento):** Segnala i piani rate che necessitano di un ricalcolo urgente per evitare di richiedere quote errate ai condòmini.
    2. 🟠 **Priorità Secondaria (Voci Orfane):** Segnala la presenza di nuove spese a preventivo non ancora assegnate a nessun piano di ripartizione.
* **Azione Diretta:** I banner di allarme includono pulsanti operativi (es. "Apri", "Analizza Voci") che portano l'utente esattamente dove serve per risolvere l'anomalia.

### 🎨 UI/UX Refinements (Piano dei Conti)
* **Total Budget Badge:** Aggiunto un badge dinamico nell'intestazione della pagina "Gestione Spese" che mostra in tempo reale la somma totale matematica del preventivo, offrendo un colpo d'occhio immediato sul "peso" del bilancio.
* **Smart Edit Modal:** La modale di modifica delle singole voci di spesa è stata riprogettata:
    * Integra ora i selettori per "Fornitore Suggerito" e "Natura Spesa (Fiscale)" per allinearla alla creazione.
    * Disabilita in automatico gli alert di sistema focus-stealing che causavano fastidiosi highlight neri sui testi all'apertura.
    * Include box informativi contestuali che spiegano esattamente *perché* un importo è bloccato (Hard Lock vs Soft Lock) e *come* l'utente può sbloccarlo.
* **Protezione Soft Lock Avanzata:** L'algoritmo che impedisce di abbassare l'importo di una voce al di sotto di quanto già impegnato ora utilizza un pattern di fallback sicuro, risolvendo i conflitti con i valori di "inclusione totale" (NULL) nella tabella pivot.

## [1.9.5] - Smart Waterfall & Transparent Ledger

Questa release perfeziona il cuore dell'Accounting Core introducendo il calcolo a cascata per i saldi pregressi e una riconciliazione automatica per gli incassi cumulativi. L'interfaccia dell'Estratto Conto è stata riprogettata per garantire il 100% del rigore matematico senza sacrificare la chiarezza per l'utente finale.

### 🧠 Smart Waterfall Logic (Distribuzione a Cascata)
* **Pianificazione Intelligente Saldi:** Quando un'anagrafica possiede più immobili (es. Appartamento + Box) e vanta un credito o un debito dall'esercizio precedente, il motore di generazione Piani Rate ora lo distribuisce a cascata. Il credito viene usato per azzerare la quota del primo immobile, e il residuo scivola automaticamente a copertura del secondo, evitando la generazione di "rate negative" o anomalie contabili.
* **Incassi Cumulativi Automatici:** Il motore di registrazione incassi (`StoreIncassoRateAction`) è stato riscritto. Ora l'amministratore può inserire un singolo bonifico globale: sarà il sistema a scomporlo automaticamente, saldando le quote dei vari immobili in ordine progressivo fino all'esaurimento dei fondi.

### 📊 Estratto Conto: Transparent Ledger
* **Matematica Inviolabile:** La tabella dei movimenti contabili ora mostra esclusivamente gli importi puri in Dare/Avere (escludendo figurativamente i debiti pregressi dall'importo visivo). Questo garantisce che la colonna "Saldo Progressivo" sia matematicamente ineccepibile in ogni istante.
* **UI/UX Esplicativa:** Per giustificare le discrepanze tra la spesa nominale e l'incasso reale (dovute a crediti/debiti degli anni precedenti), l'Estratto Conto utilizza lo snapshot salvato nel database per generare scritte esplicative dinamiche (es. *"👉 Include recupero debito pregresso: € 100"*).
* **Tooltip Contabili:** Il dettaglio a comparsa nell'estratto conto scompone visivamente la quota pura dal saldo usato, mostrando il calcolo esatto che ha generato il totale da saldare per la specifica rata.

### 🛡️ Retrocompatibilità (Legacy Support)
* **Graceful Fallback:** Le logiche di estrazione visiva riconoscono automaticamente le rate generate con la versione 1.8 (prive di snapshot JSON) elaborandole in modalità standard, garantendo totale retrocompatibilità senza errori a schermo.

## [1.9.4] - Visual Intelligence & Dashboard Audit

Questa release completa il ciclo "Accounting Core" introducendo un livello di **Intelligenza Visiva** senza precedenti. Il sistema non si limita a calcolare i numeri, ma ora "spiega" all'amministratore la provenienza dei fondi tramite badge semantici, icone intuitive e colori contestuali. Inoltre, la Dashboard è stata calibrata per eliminare falsi positivi contabili.

### 🎨 Visual Intelligence (Smart Radar)

* **Semantic Fund Tracking:** Il dettaglio della voce di spesa (`ContoResource`) ora riconosce e classifica visivamente la fonte del budget:
    * 🎯 **Diretta (Target):** Fondi assegnati specificamente alla voce.
    * ↳ **Da Capitolo (Downstream):** Fondi ereditati dal capitolo padre tramite logica *Smart Push-Down*.
    * 📈 **Spostamento (Trending Up):** Fondi provenienti da una riallocazione manuale (badge Viola).
    * 🔀 **Mista (Merge):** Combinazione di più fonti.
* **Gestione "Overbudget Sano":**
    * Introdotta la distinzione tra **Eccedenza Critica** (Rosso) ed **Extra Budget Gestito** (Viola).
    * Se una voce supera il preventivo a causa di uno spostamento volontario (es. "Rottura Cancello"), la barra diventa viola e non genera allarme, segnalando un'operazione contabile consapevole.
* **Badge "Squircle" Moderni:** Refactoring completo della UI dei badge (`AlberoDeiConti` e `DettaglioConto`). Abbandonato lo stile "pillola" per un design squadrato (`rounded-md`) coerente con il design system Shadcn/Linear, arricchito da icone `Lucide` parlanti.

### 🧠 Core Logic & Dashboard

* **Smart Dashboard Reconciliation:**
    * Risolto il "Paradosso del Bilancio": il Controller della dashboard ora applica una validazione gerarchica.
    * Se il **Delta Globale** del bilancio è a pareggio (tolleranza < 5€), il sistema ignora i micro-deficit delle singole voci (spesso coperti dai fondi padre), sopprimendo la modale "Audit spese scoperte" e mostrando il widget verde "Bilancio Allineato".
* **Logic "Equal Deficit Distribution":**
    * Perfezionato l'algoritmo di *Push-Down* nella Resource. I fondi del padre vengono distribuiti equamente tra i figli che presentano un *deficit matematico* (Preventivo > Fissi), ignorando correttamente i flag "Jolly" (`NULL`) che avrebbero altrimenti escluso voci legittime dalla ripartizione.

### 🛠️ Refactoring Tecnico

* **Currency Composable:** Introdotto `useCurrencyFormatter.ts` per centralizzare la logica di formattazione monetaria (spazi non divisibili, gestione centesimi), eliminando codice duplicato nei componenti Vue.
* **CSS Cleanup:** Rimossa interamente la sezione `<style>` legacy (300+ righe) dai componenti di dettaglio conto, migrando tutto a classi utility Tailwind native per una manutenibilità superiore.

---

## [1.9.3] - Penny Perfect & Smart Push-Down

Questa release rifinisce il motore "Accounting Intelligence Core" (v1.9) introducendo la precisione assoluta nei calcoli e una gestione intelligente dei capitoli raggruppati.

### 🧠 Accounting Core Intelligence: Evolution

* **Frazionamento Voci di Spesa (Partial Budgeting):**
    * Introdotta la possibilità di includere nei Piani Rate solo una quota parte dell'importo totale di una voce di spesa (es. richiedere un acconto di 400€ su una spesa da 1.000€).
    * Il sistema traccia automaticamente il "Residuo Disponibile" per i piani successivi e impedisce il superamento del budget preventivato.
* **Algoritmo "Penny Perfect" (Quadratura Centesimale):**
    * Il motore di calcolo delle quote (`CalcoloQuoteService`) è stato riscritto per eliminare gli errori di arrotondamento.
    * Implementata logica di quadratura: l'ultimo beneficiario di una ripartizione assorbe matematicamente l'eventuale resto, garantendo che la somma delle quote corrisponda al 100% dell'importo speso, al centesimo.
* **Logica "Smart Folder Push-Down":**
    * Supporto avanzato per i "Capitoli Contenitore" (Folder) nei piani rate parziali.
    * Se si assegna un importo forzato a un Capitolo Padre (es. "Spese Generali: 200€") che non ha tabella millesimale propria, il sistema calcola automaticamente il rapporto proporzionale rispetto al preventivo originale e "spinge" l'override sui sottoconti figli, distribuendo l'importo corretto in base alle loro tabelle specifiche.
* **Gestione Piani Integrativi (No-Duplicate Balance):**
    * Introdotta logica decisionale ibrida (Controller + DB) nell'Action di generazione.
    * Il sistema ora distingue tra "Primo Piano" (che applica i saldi pregressi) e "Piani Integrativi" (che contengono solo le nuove spese), prevenendo la duplicazione dei debiti/crediti iniziali.
* **Sposta Spesa (Budget Reallocation):**
    * Introdotta la possibilità di spostare quote di budget tra diverse voci di spesa all'interno della stessa gestione (es. spostare 100€ dal "Compenso Amministratore" alla "Manutenzione Giardino").
    * **Audit Trail:** Ogni spostamento viene tracciato nella nuova tabella `budget_movements`, registrando l'autore dell'operazione, la data, l'importo e la causale.
    * **History Popover:** Implementata un'icona "Storico" (🕒) dinamica nel Piano Rate che permette di visualizzare la "genesi" dell'importo attuale (es. Originale 300€ - 100€ spostati = Attuale 200€).
    * **Protezione Bidirezionale:** Il sistema impedisce la rimozione di voci di spesa dal piano rate se sono coinvolte in uno spostamento pendente (sia come sorgente che come destinazione), garantendo l'integrità del bilancio.

### 🐛 Bug Fixes & Refactoring

* **Correzione Ricorsione Override:** Risolto un bug critico nel calcolo dei piani parziali dove l'importo del padre si sommava erroneamente a quello dei figli. Ora l'override sul nodo padre interrompe correttamente la ricorsione.
* **Tooltip Saldi Intelligenti:** Il frontend ora mostra i dettagli dei saldi (pallini rossi/blu) solo se il piano rate specifico li include effettivamente (basandosi sullo snapshot delle regole di calcolo), evitando confusione nei piani integrativi.
* **Fix Totali Widget Copertura:** La risorsa API ora calcola il totale del piano rate leggendo correttamente gli importi parziali dalla tabella pivot, allineando il widget di copertura con il reale valore delle rate generate.
* **Mass Assignment Protection:** Aggiunti `saldo_applicato` e `nota_saldo` al modello `Gestione` per permettere il corretto salvataggio dello stato dei saldi.

---

## [1.9.0] - Accounting Intelligence Core

Questa release rappresenta il più grande aggiornamento strutturale al motore contabile di Kondomanager.
Con la v1.9.0 introduciamo l'**Audit Intelligence**: un sistema di controllo attivo che garantisce l'integrità matematica tra Preventivo e Piani Rate.
Abbiamo eliminato l'astrazione dei piani rate: ora ogni voce di spesa è "ancorata" atomicamente, prevenendo duplicazioni, ammanchi e errori di ripartizione.

Inoltre, questa versione abbatte le barriere tecniche, introducendo la piena compatibilità con hosting condivisi (come Altervista), gestione avanzata dei Cron Job e configurazioni email semplificate.

### 🧠 New Feature: Accounting Core Intelligence

Il nuovo motore contabile introduce livelli di sicurezza avanzati per "blindare" il bilancio condominiale:

* **Ancoraggio Atomico & Gerarchico:** I piani rate vengono collegati a specifici capitoli di spesa tramite una tabella pivot.
    * *Auto-Popolamento:* I piani globali vengono ancorati automaticamente a tutte le spese correnti.
    * *Gerarchia:* Il selettore capitoli supporta la logica Padre/Figlio con indicatori visivi di stato.
* **Collision Detection (Anti-Double Billing):** Il sistema impedisce matematicamente di inserire la stessa voce di spesa in due piani rate attivi contemporaneamente.
* **Double-Lock Strategy (Lucchetto sui Saldi):**
    * *Protezione Saldo Applicato:* Al momento della creazione di un Piano Rate, il sistema impegna in modo irreversibile il saldo dell’esercizio precedente.
    * *Hard-Lock:* Blocco a livello di Controller per impedire tentativi di duplicazione addebito su altre gestioni.
* **Dashboard Audit & Copertura:** Nuova widget "Semaforo Contabile" nella dashboard. Confronta in tempo reale il Preventivo vs Pianificato e segnala le voci "Orfane".
* **Sincronizzazione Intelligente (Smart Sync):** Workflow guidato per integrare le voci orfane nei piani rate esistenti con selezione granulare.
* **Blocco Cancellazione Preventivo:** Protezione a livello di `ContoController` per impedire l'eliminazione di voci ancorate a piani attivi.

### 🛠️ System & Hosting Compatibility

Abbiamo reso Kondomanager installabile ovunque, dai server dedicati agli hosting gratuiti.

* **Database Flexibility:** Configurazione `.env` per supportare charset diversi da `utf8mb4` (compatibilità legacy MySQL/Altervista).
* **Hosting Condiviso & HTTPS:** Logica avanzata per forzare HTTPS e gestire i reverse proxies (`TRUSTED_PROXIES`), risolvendo loop di redirect.
* **Gestione Cron Job Remoti:** Attivazione dei processi pianificati (Queue Work) tramite chiamata HTTP esterna sicura con token cifrato.
* **Configurazione SMTP via UI:** Configurazione server di posta direttamente da pannello, senza editare file `.env`.

### 🚀 Improvements

* **UX Potenziata (No-Jump & Design System):**
    * *Filtro Capitoli:* Caricamento asincrono e disabilitazione input per evitare "salti" visivi della pagina.
    * *Coerenza Design:* Adozione completa dei pattern Shadcn/UI (checkbox opachi, toggle moderni) su tutta l'interfaccia.
* **Logica Condizionale Saldi:** Il selettore "Distribuzione Saldo Iniziale" appare solo se il sistema rileva effettivi arretrati recuperabili.
* **Admin Inbox Notifiche:** Badge visivo sul pulsante Admin Inbox per le notifiche di "Pagamento Effettuato".
* **Log Email:** Sistema di logging per tracciare lo stato di invio delle email.
* **Logica "Financial Waterfall":** Aggiornamento del Trait per rilevare con precisione quando un saldo pregresso è incorporato nella rata corrente.

### 🐛 Bug Fixes

* **CRITICO - Cross-Condominium Pollution:** Risolto un bug grave nel calcolo degli arretrati che aggregava erroneamente i debiti dello stesso proprietario su condomini diversi.
* **Duplicazione Saldi:** Risolto problema che impegnava irreversibilmente il saldo dell'esercizio precedente alla creazione del piano rate (ora gestito dinamicamente).
* **Pulizia Rate Orfane:** Implementata logica automatica per ignorare rate collegate a piani cancellati o gestioni obsolete.
* **Validazione Obbligatoria Tabelle:** Introdotta logica rigorosa per le voci di spesa (singole o sottoconti). Il campo "Tabella Millesimale" è ora obbligatorio per garantire che ogni spesa abbia sempre un criterio di ripartizione certo.

---

## [1.8.0] - The "Smart Assistant" Update

Questa release segna un cambio di paradigma per Kondomanager. Abbiamo lavorato intensamente per trasformare il gestionale da un semplice archivio dati a un **Assistente Proattivo**.

Con la nuova **Smart Activity Inbox**, il sistema lavora per te: il calendario non è più statico, ma suggerisce in modo intelligente le scadenze imminenti e le azioni richieste.
Inoltre, introduciamo gli **Aggiornamenti Frontend**, permettendo anche agli utenti su hosting condivisi (o con poca esperienza di terminale) di mantenere il software aggiornato con un semplice click.

### ✨ New Features

#### Core & Automazione
* **Smart Activity Inbox:** Il nuovo motore eventi trasforma il calendario in un assistente virtuale. Il sistema ora genera e suggerisce eventi collegati alla generazione e ai pagamenti delle rate, permettendo una gestione proattiva delle scadenze.
* **Aggiornamenti Automatici da Frontend:** Nuova funzione dedicata agli utenti che hanno usato l'installazione guidata. È ora possibile aggiornare Kondomanager direttamente dal pannello di amministrazione senza accedere alla console del server.
* **Condominio di Default al Login:** Nelle impostazioni generali, gli amministratori possono ora impostare un condominio specifico da aprire automaticamente al login. Ogni utente (admin o collaboratore) può personalizzare questa scelta, ottimizzando il flusso di lavoro.

#### Contabilità & Gestione
* **Gestione Fornitori:** Aggiunto modulo completo per la creazione e gestione delle anagrafiche fornitori.
* **Casse del Condominio:** Nuova funzionalità per creare e gestire le risorse finanziarie e le casse condominiali.
* **Emissione Rate Evoluta (Capitoli di Spesa):** Introdotta la possibilità di emettere rate parziali o mirate selezionando specifici capitoli di spesa (es. generare rate solo per "Scala A").
* **Piani Rate Multipli:** Evoluzione della logica contabile. Ogni gestione mantiene un singolo piano dei conti, ma ora può supportare **più piani rate**, offrendo massima flessibilità.
* **Registrazione Pagamento Rate:** Nuova interfaccia dedicata per la registrazione rapida dei pagamenti.
* **Ottimizzazione Incassi Multi-gestione:** Supporto avanzato per pagamenti che coprono più gestioni, con riconciliazione virtuale visibile nei report.
* **Estratto Conto:** Aggiunta la visualizzazione dell'estratto conto direttamente nell'anagrafica del condòmino.
* **Statistiche Dashboard:** Nuovi moduli statistici sulla home page amministratore per un controllo immediato dell'andamento gestionale.

#### Internazionalizzazione
Kondomanager diventa globale. Abbiamo aggiunto il supporto completo per le lingue **Inglese** e **Portoghese** in tutto l'ecosistema:
* Traduzione completa delle **Impostazioni Generali** e dell'interfaccia **Frontend**.
* Traduzione modulo **Comunicazioni in Bacheca**.
* Traduzione modulo **Autenticazione e Registrazione**.
* Traduzione delle **Notifiche Email** transazionali.
* Traduzione modulo **Documenti/Archivio** del condominio.
* Traduzione modulo **Segnalazioni Guasti**.

#### DevOps
* **Supporto Docker:** Aggiunta guida ufficiale e file di configurazione per il deploy di Kondomanager tramite Docker (Special thanks to @k3ntinhu).

### 🚀 Improvements

* **Nuovo Menu "Rubrica":** Riorganizzazione della Topbar. La voce "Anagrafiche" diventa "Rubrica" e integra un menu a tendina per l'accesso rapido sia ai Condòmini che ai Fornitori.
* **Visualizzazione Permessi Rapida:** Le tabelle *Utenti* e *Ruoli* ora mostrano direttamente i permessi associati nelle colonne, evitando di dover entrare in modifica per verificarli.
* **Gestione Intelligente Permessi:** Migliorata la logica di assegnazione e revoca permessi durante la modifica di un Utente o di un Ruolo.
* **Smart Associazione Immobili:** Nel menu a tendina per associare un'anagrafica a un immobile, il sistema ora mostra *solo* le anagrafiche già presenti nel condominio ma *non ancora associate* a quell'immobile specifico, prevenendo duplicazioni errate.
* **Filtro Preventivi nel Piano dei Conti:** Durante la creazione di un nuovo piano dei conti, il controller ora filtra e mostra solo le gestioni che non hanno ancora un preventivo associato.
* **Integrazione Widget Eventi:** Il widget eventi nella dashboard utente è stato collegato alla nuova *Smart Activity Inbox* per mostrare le notifiche intelligenti.
* **UX Piani Rate:** Migliorata la visualizzazione e le funzioni operative all'interno della gestione piani rate.

### 🐛 Bug Fixes

* **Valori negativi:** Risolto un bug che impediva l'inserimento di valori negativi nelle maschere di input delle anagrafiche associate all'immobile (utile per conguagli o crediti pregressi).
* **Registrazione utenti invitati:** Risolto un problema che impediva agli utenti invitati via email di completare la registrazione se l'opzione "Registrazione pubblica" era disabilitata nelle impostazioni generali.
* **Sicurezza password:** Implementato controllo per impedire il riutilizzo della password corrente durante la procedura di cambio password (Special thanks to @borghiste - Issue #30).