# Piano lavori — Evoluzione anagrafica e motore di riparto

**Versioni Kondomanager interessate:** v1.10, v1.10.1, v1.11
**Stato:** Proposta operativa per validazione

---

## 1. Sintesi

Questo documento consolida due iniziative emerse dal feedback degli amministratori, che operano su due livelli complementari dello stesso motore di riparto:

1. **Iniziativa A — Tabelle millesimali ricche e flessibili**: supporto pieno per le ripartizioni ex art. 1124 c.c. (scale, ascensori), tabelle manuali con quote relative, scope filtering per scala/palazzina. Risponde alla domanda "quanto deve pagare ogni immobile per questa spesa?".
2. **Iniziativa B — Anagrafica immobiliare evoluta**: separazione tra ownership legale, gestione operativa e diritto fiscale tramite l'introduzione di %Bilancio e %Detrazione, completamento dei dati catastali, override delle ripartizioni per ruolo a livello di singolo immobile. Risponde alla domanda "una volta calcolata la quota dell'immobile, come si distribuisce tra i soggetti?".

I due strati sono ortogonali e additivi. L'evoluzione viene distribuita su tre release per gestire il rischio e mantenere il commitment già preso sulla v1.10.

---

## 2. Architettura a due livelli

Il motore di riparto, dopo l'evoluzione completa (v1.11), lavorerà secondo questa pipeline:

```
LIVELLO 1 — CALCOLO DELLA QUOTA DELL'IMMOBILE
  Per ogni tabella collegata al conto (con il suo coefficiente):
    Se tipo = 'manuale':
      valore_immobile = quote_relative / somma_quote_tabella
    Altrimenti:
      valore_immobile = millesimi / 1000
    contributo = importo × valore_immobile × coefficiente_tabella
  quota_immobile = somma di tutti i contributi

LIVELLO 2 — DISTRIBUZIONE TRA I SOGGETTI
  Determina lo split per ruolo (proprietario/inquilino/usufruttuario/nudo prop.):
    Se esiste un override in quote_tabella_ripartizioni → usa quello
    Altrimenti → usa il default in conto_tabella_ripartizioni
  Per ogni ruolo:
    quota_ruolo = quota_immobile × percentuale_ruolo
    Identifica i soggetti di quel ruolo con in_bilancio = true e periodo valido
    Distribuisce la quota_ruolo tra i soggetti usando le rispettive quota_bilancio
```

Il primo livello determina **quanto** paga l'unità immobiliare. Il secondo livello determina **chi** paga e **in che proporzione** all'interno dell'unità.

---

## 3. Stato attuale di Kondomanager

| Funzionalità | Stato | Dove |
|---|---|---|
| Tabelle millesimali multiple per condominio | Esistente | `tabelle` |
| Tipi di tabella | Esistente | `tabelle.tipo` |
| Unità di misura (millesimi, persone, kwatt, mtcubi, quote) | Esistente | `tabelle.quota` |
| Periodo di validità delle tabelle | Esistente | `tabelle.data_inizio` / `data_fine` |
| Scope per palazzina/scala | Esistente | `tabelle.palazzina_id` / `scala_id` |
| Quota per immobile per tabella + flag esclusione | Esistente | `quote_tabella` |
| **Pivot pesato voce→tabelle** (consente 50/50 multi-tabella sulla stessa voce) | **Esistente** | `conto_tabella_millesimale.coefficiente` |
| Ripartizione per ruolo (proprietario/inquilino/usufruttuario) | Esistente, a livello di tabella | `conto_tabella_ripartizioni` |
| Anagrafica del rapporto soggetto-immobile | Esistente | `anagrafica_immobile` |
| Storicizzazione del rapporto | Esistente | `anagrafica_immobile.data_inizio` / `data_fine` |
| Addebito ad personam (100% a un singolo immobile) | Esistente | flag su riga fattura |

**Nota importante:** il pivot pesato voce→tabelle (es. 50% Tab Proprietà + 50% Tab Altezze per le scale ex art. 1124) è già supportato dall'infrastruttura esistente. La v1.10 espone questa funzionalità in modo organico e completa le casistiche di tabella manuale.

---

## 4. Roadmap delle release

### v1.10 — Tabelle e calcolo

Release dedicata al perfezionamento del Livello 1 del motore di riparto. Mantiene il focus già pianificato sulle tabelle e sull'art. 1124.

**Iniziativa A — Tabelle millesimali:**

- Migration enum `tabelle.tipo`: aggiunta `manuale` per tabelle con quote relative
- Frontend `QuoteList` con branch dedicato alle tabelle manuali, feedback live di somma totale e percentuali per riga
- Scope filtering nei form di gestione tabelle (scale → solo immobili della scala; lastrico → solo immobili della palazzina) con toggle "mostra tutti" per gestire eccezioni
- Validazione somma coefficienti voce di spesa = 100%, avviso non bloccante in salvataggio e blocco in fase di generazione riparto
- Motore di riparto: branch dedicato per `tipo = 'manuale'` con normalizzazione automatica sul totale inserito

**Già pianificate per la stessa release:**

- Voci Accantonamento (modifica al motore contabile)
- Eventuali altre feature già nel backlog v1.10

### v1.10.1 — Anagrafica catastale e fiscale

Patch dedicata all'arricchimento dell'anagrafica immobiliare e alla predisposizione fiscale. Tutti i cambiamenti sono additivi e a basso rischio: nessuna modifica al motore di riparto, nessuna migrazione distruttiva di dati esistenti.

**Modifiche alla tabella `immobili`:**

- `categoria_catastale` varchar (es. "A/2", "C/6")
- `classe_catastale` varchar
- `rendita_catastale` decimal(10,2)
- `partita_catastale` varchar
- `estensione_particella` varchar (per condomini con più particelle)
- `vani` modifica da `integer` a `decimal(4,1)` per supportare valori come 13,5

**Modifiche alla tabella `anagrafica_immobile`:**

- Aggiunta `nuda_proprietario` all'enum `tipologia`
- Nuova colonna `in_bilancio` boolean default `true` — flag che decide se il soggetto compare nei riparti. Default `true` per retrocompatibilità
- Nuova colonna `quota_detrazione` decimal(5,2) nullable — quota di detrazione fiscale spettante. Dato puramente informativo per le certificazioni fiscali

**Modifiche all'enum di `conto_tabella_ripartizioni`:**

- Aggiunta `nuda_proprietario` all'enum `soggetto`

**Nuova tabella `aliquote_detrazione`** — parametrica, gestione delle aliquote fiscali senza modifiche al codice ad ogni cambio normativo:

```sql
CREATE TABLE aliquote_detrazione (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    codice varchar(50) NOT NULL UNIQUE,
    descrizione varchar(255) NOT NULL,
    aliquota decimal(5,2) NOT NULL,
    tipo enum('abitazione_principale','seconda_casa','altro') NOT NULL,
    data_inizio_validita date NOT NULL,
    data_fine_validita date NULL,
    note text NULL,
    created_at timestamp NULL,
    updated_at timestamp NULL,
    PRIMARY KEY (id)
);
```

Esempi di record iniziali per il 2025/2026:

- `RIST_AB_PRIN_2025` — Ristrutturazione abitazione principale — 50% — abitazione_principale — dal 01/01/2025
- `RIST_SEC_2025` — Ristrutturazione seconda casa — 36% — seconda_casa — dal 01/01/2025

**Funzionalità accessoria — Alert maggiore età:**

Job schedulato che identifica i soggetti la cui `data_nascita` ha appena superato la maggiore età e che hanno ancora `in_bilancio = false`. Notifica all'amministratore con suggerimento di rivedere la `quota_bilancio` dell'immobile coinvolto.

**Aggiornamenti UI:**

- Form di gestione anagrafica immobiliare con i nuovi campi e i nuovi tipi di rapporto
- Form di gestione tabelle millesimali con `nuda_proprietario` come opzione di ruolo

### v1.11 — Motore di riparto unificato e Recupero Crediti

Release maggiore che integra il refactor completo del motore di riparto con il modulo Recupero Crediti. La coordinazione è naturale: entrambe le funzionalità definiscono "chi è il debitore".

**Iniziativa B — Fase 2:**

- Migration `quota_bilancio` decimal(5,2) su `anagrafica_immobile`. Inizialmente nullable per consentire la migrazione: per ogni record esistente la `quota_bilancio` viene popolata con il valore di `quota` come default sicuro. Successivamente diventa `NOT NULL` con vincolo di validazione
- Nuova tabella `quote_tabella_ripartizioni` per gli override per-immobile delle ripartizioni per ruolo:

```sql
CREATE TABLE quote_tabella_ripartizioni (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    quote_tabella_id bigint unsigned NOT NULL,
    soggetto enum('proprietario','inquilino','usufruttuario','nuda_proprietario') NOT NULL,
    percentuale decimal(5,2) NOT NULL,
    created_at timestamp NULL,
    updated_at timestamp NULL,
    PRIMARY KEY (id),
    UNIQUE KEY (quote_tabella_id, soggetto),
    FOREIGN KEY (quote_tabella_id) REFERENCES quote_tabella(id) ON DELETE CASCADE
);
```

- UI per la gestione degli override per-immobile delle ripartizioni
- Refactor del motore di riparto con la pipeline a due livelli completa
- Validazione somma `quota_bilancio` = 100% per immobile
- Test di regressione completi sui riparti esistenti per garantire che il comportamento di default sia identico al precedente

**Modulo Recupero Crediti:**

Sviluppato in parallelo, beneficia direttamente delle modifiche all'anagrafica per identificare correttamente il debitore.

---

## 5. Validazioni e regole di business

| Regola | Quando | Release |
|---|---|---|
| Somma coefficienti tabelle collegate a una voce di spesa = 100% | Salvataggio voce, generazione riparto | v1.10 |
| Tabelle manuali: somma quote > 0 | Salvataggio quote tabella | v1.10 |
| Somma percentuali `conto_tabella_ripartizioni` per conto-tabella = 100% | Salvataggio (esiste, va estesa con `nuda_proprietario`) | v1.10.1 |
| Tipologia `nuda_proprietario` richiede usufruttuario sullo stesso immobile (warning) | Salvataggio anagrafica | v1.10.1 |
| `quota_detrazione` può essere nullable, non vincolata a sommare 100% | Sempre | v1.10.1 |
| Somma `quota_bilancio` di tutti i soggetti `in_bilancio = true` di un immobile = 100% | Salvataggio anagrafica, generazione riparto | v1.11 |
| Almeno un soggetto con `in_bilancio = true` per ogni immobile attivo | Salvataggio anagrafica | v1.11 |
| Somma percentuali `quote_tabella_ripartizioni` per quote_tabella = 100% | Salvataggio override | v1.11 |

---

## 6. Casistiche supportate dopo l'implementazione completa

| Scenario | Modellazione | Disponibile da |
|---|---|---|
| **Spese scale ex art. 1124** con tabelle già depositate | Voce "Manutenzione scale" → 50% Tabella Proprietà + 50% Tabella Altezze | v1.10 |
| **Spese scale ex art. 1124** senza Tabella Altezze | Crea Tabella manuale con altezze in metri (o numero piano), poi voce → 50% Tab A + 50% Tab Manuale | v1.10 |
| **Più scale con ascensori distinti** | Tabelle scopate per scala, voci separate per ogni scala | v1.10 |
| **Distribuzione per parti uguali** (es. compenso amministratore) | Tabella manuale con valore 1 per ogni unità | v1.10 |
| **Distribuzione per residenti** | Tabella manuale con numero residenti per unità | v1.10 |
| **Distribuzione per superficie** | Tabella manuale con metri quadri per unità | v1.10 |
| **Anagrafica catastale completa** (categoria, classe, rendita, partita) | Nuovi campi su `immobili` | v1.10.1 |
| **Vani decimali** (es. 13,5) | Modifica tipo colonna | v1.10.1 |
| **Eredi minorenni nei riparti** | `in_bilancio = false` per i minorenni; alert al raggiungimento maggiore età | v1.10.1 |
| **Detrazione fiscale 50% al residente, 36% al non residente** | Tabella `aliquote_detrazione` parametrica + `quota_detrazione` per soggetto | v1.10.1 |
| **Coppia comproprietari, in bilancio solo uno** | Marito: in_bilancio=false. Moglie: quota_bilancio=100, in_bilancio=true | v1.11 |
| **Immobile affittato, tutte le spese all'inquilino** | Override `quote_tabella_ripartizioni`: 100% inquilino su quell'immobile per tutte le tabelle | v1.11 |
| **Immobile affittato, ordinaria a inquilino, straordinaria a proprietario** | Tabelle ordinarie con override 100% inquilino, tabelle straordinarie con override 100% proprietario | v1.11 |
| **Usufrutto/nuda proprietà 70/30** | Override `quote_tabella_ripartizioni`: 70% usufruttuario, 30% nuda_proprietario | v1.11 |
| **Lavori straordinari su sotto-insieme di immobili** | Tabella dedicata con `quote_tabella` solo per gli immobili coinvolti | Già esistente |
| **Addebito ad personam** (riparazione causata da un singolo) | Riga di fattura collegata direttamente all'immobile, bypass del meccanismo millesimale | Già esistente |

---

## 7. Cosa NON cambia

- Tutta la logica contabile in partita doppia (scritture, FatturaPassivaService, scadenziario)
- I dati delle tabelle millesimali esistenti — nessuna migrazione di valori
- `piano_rate_capitoli` e la pianificazione dei budget — completamente ortogonale
- I riparti già generati prima dell'introduzione di queste modifiche restano validi e consultabili
- L'addebito ad personam su singolo immobile — già funzionante

---

## 8. Domande aperte

1. **Aliquote detrazione iniziali:** è disponibile un elenco completo delle aliquote attualmente in vigore (codice fiscale, descrizione, percentuale, periodo di validità) per popolare il seeder iniziale?
2. **Comodatario:** vale la pena aggiungere `comodatario` come tipologia separata oltre alle quattro proposte, o per il momento si modella come inquilino a titolo gratuito?
3. **Notifica alert maggiore età:** quale modalità di consegna preferire — avviso in dashboard, email, oppure entrambi?
4. **Tipo `manuale` vs riuso di tipi esistenti:** alcune funzionalità potrebbero in teoria essere coperte dai tipi `altro` o `speciale` già esistenti combinati con `quota = 'quote'`. Si conferma comunque l'introduzione del tipo esplicito `manuale` per chiarezza semantica?

---

## 9. Razionale dello split su tre release

La scelta di distribuire il lavoro su tre release invece di concentrare tutto in v1.10 risponde a tre criteri:

**Continuità con quanto pianificato:** la v1.10 era già caratterizzata come "release per le tabelle e l'art. 1124". Questo focus viene mantenuto.

**Gestione del rischio:** ogni release contiene modifiche coerenti e testabili indipendentemente. Un eventuale rollback impatta una sola area funzionale alla volta.

**Beneficio incrementale per l'amministratore:** ogni release porta valore concreto e immediatamente utilizzabile. Non si attende un singolo grande rilascio per vedere risultati.