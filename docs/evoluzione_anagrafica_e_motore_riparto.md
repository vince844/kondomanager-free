# Piano lavori — Evoluzione anagrafica e motore di riparto

**Versioni Kondomanager interessate:** v1.10, v1.11
**Stato:** Proposta operativa per validazione — **rev. 2** (allineata alla roadmap; integrata risoluzione a cascata, coerenza-ruoli, %Bilancio entro il ruolo, schema detrazioni a due assi)

> **Note di revisione (rev. 2)**
> - **Versioning allineato alla roadmap.** La ex-`v1.10.1` (anagrafica catastale/fiscale) è confluita in **v1.10 — Iniziativa B Fase 1**, come da roadmap canonica. Resta un solo punto da confermare: se preferisci ri-splittarla in una patch dedicata, si reintroduce `v1.10.1`.
> - **Livello 2 completato** con la risoluzione a cascata (prima mancava il comportamento a ruolo assente).
> - **%Bilancio = peso entro il ruolo**, non sull'intero immobile (vedi §2 e §5). *Decisione da confermare con l'amministratore proponente.*
> - **Schema `aliquote_detrazione` a due assi** (uso × intervento) e seed a 5 righe (spese 2025; **aliquote 2026 da verificare**).
> - **Ruoli estesi:** aggiunti `nuda_proprietario` e `comodatario`.

---

## 1. Sintesi

Questo documento consolida due iniziative emerse dal feedback degli amministratori, che operano su due livelli complementari dello stesso motore di riparto:

1. **Iniziativa A — Tabelle millesimali ricche e flessibili**: supporto pieno per le ripartizioni ex art. 1124 c.c. (scale, ascensori), tabelle manuali con quote relative, scope filtering per scala/palazzina. Risponde alla domanda *"quanto deve pagare ogni immobile per questa spesa?"*.
2. **Iniziativa B — Anagrafica immobiliare evoluta**: separazione tra ownership legale, gestione operativa e diritto fiscale tramite l'introduzione di `%Bilancio` e `%Detrazione`, completamento dei dati catastali, override delle ripartizioni per ruolo a livello di singolo immobile. Risponde alla domanda *"una volta calcolata la quota dell'immobile, come si distribuisce tra i soggetti?"*.

I due strati sono ortogonali e additivi. L'evoluzione viene distribuita su due release (v1.10 e v1.11) per gestire il rischio e mantenere il commitment già preso sulla v1.10.

**Principio di trasparenza (vincolante).** Qualsiasi risoluzione automatica (cascata di ruolo, default per natura della spesa) deve essere **mostrata nell'anteprima** del riparto prima della generazione e **congelata nel riparto generato**. La verità è l'output esplicito e immutabile, non una regola applicata a runtime in modo invisibile. Questo allinea il motore di riparto alla filosofia ledger del progetto ("le proiezioni sono fissate, il giornale è la verità").

---

## 2. Architettura a due livelli

Il motore di riparto, dopo l'evoluzione completa (v1.11), lavorerà secondo questa pipeline:

```
LIVELLO 1 — CALCOLO DELLA QUOTA DELL'IMMOBILE
  Per ogni tabella collegata al conto (con il suo coefficiente):
    Se tipo = 'manuale':
      valore_immobile = quote_relative / somma_quote_tabella
    Altrimenti:
      valore_immobile = millesimi / somma_millesimi_tabella   (mai /1000 cablato)
    contributo = importo × valore_immobile × coefficiente_tabella
  quota_immobile = somma di tutti i contributi

LIVELLO 2 — DISTRIBUZIONE TRA I SOGGETTI
  Percentuali per ruolo (proprietario / inquilino / usufruttuario /
                         nuda proprietà / comodatario):
    Se esiste override per-immobile in quote_tabella_ripartizioni → usa quello
    Altrimenti → default per-tabella in conto_tabella_ripartizioni
  Per ogni ruolo con percentuale > 0:
    quota_ruolo = quota_immobile × percentuale_ruolo
    Soggetti del ruolo con in_bilancio = true e periodo valido
    SE il ruolo non ha soggetti su questo immobile → risoluzione a cascata:
       asse GODIMENTO:  inquilino → comodatario → usufruttuario → proprietario
       asse CAPITALE:   nuda proprietà → proprietario   [trattati come UNA classe]
       se anche la cascata è vuota → quota scoperta + warning (vedi §5)
    Distribuisce quota_ruolo tra i soggetti del ruolo risolto usando
       quota_bilancio RINORMALIZZATA dentro il ruolo (quota / Σ quota nel ruolo)
  La risoluzione a cascata è mostrata in anteprima e congelata nel riparto generato
    (output esplicito e immutabile: nessuna attribuzione "magica" a runtime).
```

Il primo livello determina **quanto** paga l'unità immobiliare. Il secondo livello determina **chi** paga e **in che proporzione** all'interno dell'unità.

### 2.1 La risoluzione a cascata (il pezzo che mancava)

Lo pseudocodice originario non specificava cosa accade quando, su un determinato immobile, il ruolo indicato dal coefficiente **non è presente**. È il caso che ha sollevato il beta-tester sull'usufrutto: tabella ordinaria con coefficiente sull'inquilino, unità in usufrutto **senza** inquilino. Senza una regola, la `quota_ruolo` resta orfana o ricade in modo arbitrario.

La cascata risolve `ruolo → soggetto reale` seguendo la natura economica della spesa, fedele al Codice Civile:

- **Asse del godimento** (spese ordinarie, di gestione corrente, consumi — art. 1004 c.c.): `inquilino → comodatario → usufruttuario → proprietario`. Chi gode del bene paga la gestione corrente; in assenza dell'inquilino/comodatario è l'usufruttuario, in assenza di usufrutto è il proprietario pieno.
- **Asse del capitale** (spese straordinarie, interventi sul capitale — art. 1005 c.c.): `nuda proprietà → proprietario`, trattati come **una sola classe**. Una straordinaria atterra su chi detiene il capitale: il nudo proprietario se c'è usufrutto, il proprietario pieno altrimenti. Trattarli come classe unica è ciò che impedisce di ricreare la quota orfana sull'asse capitale (una colonna su "nuda proprietà" non resta scoperta sulle unità in piena proprietà, e viceversa).

**Conseguenza pratica per l'amministratore.** Impostando il coefficiente sul ruolo "naturale" (inquilino per il godimento, proprietario per il capitale), una **singola** configurazione di tabella diventa corretta su tutte le composizioni del condominio — affittate, in usufrutto, in piena proprietà — senza override manuali. L'override per-immobile (§2.2) resta per le eccezioni pattuite.

**Default per natura, non per coefficiente fiscale.** I default della cascata seguono la regola del Codice (ordinaria → godimento, straordinaria → capitale), **non** il coefficiente fiscale dell'usufrutto (es. 30/70 legato all'età, DPR 131/86). Il 30/70 è legittimo solo se pattuito nell'atto e va espresso come **override per-immobile**, non come default.

### 2.2 Override per-immobile vs default per-tabella

Due meccanismi complementari, non alternativi:

- **Default per-tabella** (`conto_tabella_ripartizioni`, già esistente): un set di percentuali per ruolo valido per tutti gli immobili della tabella. Copre il caso comune insieme alla cascata.
- **Override per-immobile** (`quote_tabella_ripartizioni`, nuovo in v1.11): percentuali per ruolo specifiche per la singola unità. Serve **solo** per le eccezioni: usufrutto 70/30 pattuito, villetta con contratto che addossa *tutte* le spese all'inquilino (ordinaria + straordinaria), ecc.

Quando esiste un override per-immobile, prevale; in sua assenza vale il default per-tabella; in entrambi i casi la cascata risolve i ruoli assenti.

### 2.3 La `%Bilancio` è un peso *entro il ruolo*

> **Decisione da confermare con l'amministratore proponente** — devìa dalla sua formulazione letterale ("somma 100% sull'immobile"), ma copre tutti i suoi casi e risolve un conflitto.

La `quota_bilancio` è il peso del soggetto **dentro il suo ruolo**, rinormalizzato in distribuzione (`quota / Σ quota nel ruolo`). Non somma 100% sull'intero immobile, ma **100% dentro ciascun ruolo presente**.

Perché non sull'intero immobile: le percentuali per ruolo della tabella fanno **già** la *selezione del ruolo*; se la `%Bilancio` provasse a fare anche quella, le due dimensioni si sovrapporrebbero. La distinzione è invisibile nel caso comune e necessaria in quello generale:

- **Spesa mono-ruolo** (es. straordinaria → 100% proprietario): "100% dentro il ruolo" coincide con "100% sull'immobile" — l'intuizione dell'amministratore regge.
- **Spesa multi-ruolo** (es. "spese generali 50% proprietario + 50% inquilino"): servono pesi separati dentro ciascun lato; un'unica chiave sull'immobile non basta.

I tre casi sollevati dall'amministratore restano tutti coperti:

- **Comproprietari** (due coniugi che pagano entrambi): `quota_bilancio` 50/50 dentro il ruolo proprietario.
- **Eredi minorenni** che non possono figurare nei bilanci: `in_bilancio = false`; il 100% si redistribuisce tra gli adulti dello stesso ruolo.
- **Villetta, tutte le spese all'inquilino**: **non** è `%Bilancio`, è un *override per-immobile* delle percentuali per ruolo (inquilino 100% su tutte le tabelle di quell'unità).

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
| **Fallback ruolo assente → proprietario (piatto)** | **Esistente, da sostituire con cascata** | `CalcoloQuoteService::distribuisciSuTabelle` |
| Rinormalizzazione quota entro il ruolo (`quota / Σ quota`) | Esistente | `CalcoloQuoteService::distribuisciSuTabelle` |
| Anagrafica del rapporto soggetto-immobile | Esistente | `anagrafica_immobile` |
| Storicizzazione del rapporto (`data_inizio`/`data_fine`) | Esistente *(ma non usata in riparto — vedi nota)* | `anagrafica_immobile` |
| Addebito ad personam (100% a un singolo immobile) | Esistente | flag su riga fattura |

**Nota importante.** Il pivot pesato voce→tabelle (es. 50% Tab Proprietà + 50% Tab Altezze per le scale ex art. 1124) è già supportato dall'infrastruttura esistente. La v1.10 espone questa funzionalità in modo organico e completa le casistiche di tabella manuale.

**Nota su `data_inizio`/`data_fine`.** Il rapporto è storicizzato a DB, ma il motore (`distribuisciSuTabelle`, `addebitaDiretto`) filtra **solo** su `attivo`/`pivot.attivo`, senza confronto con le date di competenza. **Verifica richiesta:** esiste un meccanismo (observer / job schedulato / attributo calcolato) che pone `attivo = false` allo scadere di `data_fine`? In assenza, i subentri a metà esercizio non vengono gestiti correttamente — bug latente, indipendente dal tema usufrutto. Da chiarire prima della v1.11.

---

## 4. Roadmap delle release

### v1.10 — Tabelle, calcolo e anagrafica

Release che porta a maturità il Livello 1 del motore e completa l'anagrafica immobiliare (Iniziativa B Fase 1). La risoluzione a cascata e il warning di coerenza-ruoli vengono introdotti qui, sul meccanismo per-tabella esistente.

#### Iniziativa A — Tabelle millesimali e motore (Livello 1 + cascata)

- Migration enum `tabelle.tipo`: aggiunta `manuale` per tabelle con quote relative.
- Frontend `QuoteList` con branch dedicato alle tabelle manuali, feedback live di somma totale e percentuali per riga.
- Scope filtering nei form di gestione tabelle (scale → solo immobili della scala; lastrico → solo immobili della palazzina) con toggle "mostra tutti" per le eccezioni.
- Validazione somma coefficienti voce di spesa = 100%, avviso non bloccante in salvataggio e blocco in fase di generazione riparto.
- Motore di riparto: branch dedicato per `tipo = 'manuale'` con normalizzazione automatica sul totale inserito.
- **Risoluzione a cascata del ruolo** (sostituisce il fallback piatto in `distribuisciSuTabelle`): godimento `inquilino → comodatario → usufruttuario → proprietario`; capitale `nuda proprietà → proprietario` come classe unica. *(Dettaglio implementativo: vedi spec `spec-cascata-godimento-coerenza-ruoli.md`.)*
- **Warning coerenza-ruoli (quota scoperta):** quando un coefficiente punta a un ruolo e nessun soggetto è risolvibile su un immobile (cascata esaurita), avviso non-bloccante con importo scoperto; override con nota obbligatoria (stesso pattern del Validatore Coerenza Millesimi). Sorgente UI: widget Radar Salute Contabile.
- **Anteprima riparto con risoluzione esplicita:** ogni quota mostra il soggetto risolto prima della generazione; l'esito è congelato nel riparto generato.

#### Iniziativa B Fase 1 — Anagrafica catastale e fiscale

Tutti i cambiamenti sono additivi e a basso rischio: nessuna modifica distruttiva ai dati esistenti.

**Modifiche alla tabella `immobili`:**

- `categoria_catastale` varchar (es. "A/2", "C/6")
- `classe_catastale` varchar
- `rendita_catastale` decimal(10,2)
- `partita_catastale` varchar
- `estensione_particella` varchar (per condomini con più particelle)
- `vani` da `integer` a `decimal(4,1)` (per valori come 13,5)

**Modifiche alla tabella `anagrafica_immobile`:**

- Estensione enum `tipologia` con `nuda_proprietario` e `comodatario`.
- `in_bilancio` boolean default `true` — flag che decide se il soggetto compare nei riparti. Default `true` per retrocompatibilità.
- `quota_detrazione` decimal(5,2) nullable — quota di detrazione fiscale spettante. Dato informativo per le certificazioni.

**Modifiche all'enum `soggetto` di `conto_tabella_ripartizioni`:**

- Aggiunta `nuda_proprietario` e `comodatario`.

**Nuova tabella `aliquote_detrazione`** — parametrica, **a due assi** (uso × tipo di intervento), per gestire le aliquote senza toccare il codice a ogni cambio normativo:

```sql
CREATE TABLE aliquote_detrazione (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    codice varchar(50) NOT NULL UNIQUE,
    descrizione varchar(255) NOT NULL,
    aliquota decimal(5,2) NOT NULL,
    categoria_intervento enum('ristrutturazione','ecobonus','sismabonus',
                              'barriere','altro') NOT NULL,
    tipo_uso enum('abitazione_principale','seconda_casa',
                  'godimento_personale') NULL,   -- NULL = indipendente dall'uso
    data_inizio_validita date NOT NULL,
    data_fine_validita date NULL,
    note text NULL,
    created_at timestamp NULL,
    updated_at timestamp NULL,
    PRIMARY KEY (id)
);
```

> **Perché due assi.** `categoria_intervento` (ristrutturazione, ecobonus, …) e `tipo_uso` (abitazione principale, seconda casa, …) sono dimensioni distinte: l'aliquota dipende dall'intervento e, *solo per alcuni*, anche dall'uso. `tipo_uso = NULL` significa "vale a prescindere dall'uso" (ecobonus parti comuni, barriere). Schiacciare i due assi in uno solo (`tipo` come nel disegno originale) impedisce di modellare ecobonus e barriere.

**Seed iniziale — spese sostenute nel 2025** *(detraibili nel 730/2026)*:

| codice | categoria_intervento | tipo_uso | aliquota |
|---|---|---|---|
| `RIST_AB_PRIN_2025` | ristrutturazione | abitazione_principale | 50% |
| `RIST_SEC_2025` | ristrutturazione | seconda_casa | 36% |
| `RIST_GOD_PERS_2025` | ristrutturazione | godimento_personale | 36% |
| `ECOBONUS_PARTI_COMUNI_2025` | ecobonus | *(NULL)* | 65% |
| `BARRIERE_2025` | barriere | *(NULL)* | 75% |

> ⚠️ **Aliquote 2026 da verificare prima del seed di produzione.** Le percentuali sopra valgono sulle **spese 2025**. Le aliquote sulle spese **2026** cambiano con la Legge di Bilancio e non sono confermate in questo documento. Verificare la normativa vigente prima di seedare record con validità 2026.

**Funzionalità accessoria — Alert maggiore età.** Job schedulato che identifica i soggetti la cui `data_nascita` ha appena superato la maggiore età e che hanno ancora `in_bilancio = false`. Notifica all'amministratore con suggerimento di rivedere la `quota_bilancio` dell'immobile coinvolto.

**Tabella `forniture_immobile`** (predisposizione utenze): POD (energia), PDR (gas), seriali contatori, `intestatario_id`, `valid_from`/`valid_to` per lo storico subentri. *(Campi presenti ma non ancora usati dal motore riparto — l'aggancio ai consumi arriva con v1.15 Water Metering.)*

**Aggiornamenti UI Fase 1:**

- Form anagrafica immobiliare con i nuovi campi e i nuovi tipi di rapporto (`nuda_proprietario`, `comodatario`).
- Form tabelle millesimali con i nuovi ruoli come opzione.
- **Correzione copy** della guida in pagina di associazione anagrafica: includere "Usufruttuario" (e i nuovi ruoli) nell'elenco — oggi recita solo "(Proprietario o Inquilino)".

#### Già pianificate per la stessa release (dalla roadmap)

- Voci di accantonamento (modifica al motore contabile).
- Hardening DB: check constraint `chk_immobile_condominiale` su `rate_quote`.
- Bilanciatore Fondi, Attestazione rogito MVP, Dashboard Intelligence, Backup, Gestione Code Fallite. *(Vedi roadmap completa.)*

### v1.11 — Motore di riparto unificato e Recupero Crediti

Release maggiore che integra il refactor completo del Livello 2 con il modulo Recupero Crediti. La coordinazione è naturale: entrambe definiscono "chi è il debitore".

**Iniziativa B — Fase 2:**

- Migration `quota_bilancio` decimal(5,2) su `anagrafica_immobile`. Inizialmente nullable per consentire la migrazione: per ogni record esistente la `quota_bilancio` viene popolata con il valore di `quota` come default sicuro. Successivamente `NOT NULL` con vincolo di validazione.
- Nuova tabella `quote_tabella_ripartizioni` per gli override per-immobile delle ripartizioni per ruolo:

```sql
CREATE TABLE quote_tabella_ripartizioni (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    quote_tabella_id bigint unsigned NOT NULL,
    soggetto enum('proprietario','inquilino','usufruttuario',
                  'nuda_proprietario','comodatario') NOT NULL,
    percentuale decimal(5,2) NOT NULL,
    created_at timestamp NULL,
    updated_at timestamp NULL,
    PRIMARY KEY (id),
    UNIQUE KEY (quote_tabella_id, soggetto),
    FOREIGN KEY (quote_tabella_id) REFERENCES quote_tabella(id) ON DELETE CASCADE
);
```

- UI per la gestione degli override per-immobile delle ripartizioni.
- **Refactor del motore di riparto con la pipeline a due livelli completa**: override per-immobile + cascata a generazione + distribuzione `quota_bilancio` rinormalizzata entro il ruolo.
- **Validazione anti-orfano completa** (versione piena del warning v1.10) e validazione `%Bilancio` entro il ruolo.
- Supporto tabelle dinamiche (consumi acqua/calore per periodo) con `periodo_id` + snapshot alla chiusura *(aggancio a v1.15)*.
- Test di regressione completi: il comportamento di default deve restare identico al precedente per i riparti esistenti.

**Modulo Recupero Crediti:** sviluppato in parallelo, beneficia direttamente delle modifiche all'anagrafica per identificare correttamente il debitore.

**Cleanup tecnici previsti** (dalla roadmap): rimozione del temporal check in `Conto::getHasRateEmesseAttribute`; rimozione filtro `created_at` in `BudgetCoverageService` Step 1; rimozione `isParentLocked` da `AlberoDeiConti.vue`.

---

## 5. Validazioni e regole di business

| Regola | Quando | Release |
|---|---|---|
| Somma coefficienti tabelle collegate a una voce di spesa = 100% | Salvataggio voce, generazione riparto | v1.10 |
| Tabelle manuali: somma quote > 0 | Salvataggio quote tabella | v1.10 |
| Somma percentuali `conto_tabella_ripartizioni` per conto-tabella = 100% | Salvataggio (esiste, da estendere con i nuovi ruoli) | v1.10 |
| **Coerenza-ruoli: ogni ruolo con percentuale > 0 su una tabella ha un destinatario su ogni immobile della tabella, dopo cascata/override** | **Generazione riparto** | **v1.10 (warning) / v1.11 (completa)** |
| Coerenza millesimi (integrità trascrizione = totale dichiarato) — warning forte / gate a emissione rate | Emissione rate | v1.10 |
| Scostamento da 1000 (informativo, mai bloccante) | Sempre | v1.10 |
| Tipologia `nuda_proprietario` richiede usufruttuario sullo stesso immobile (warning) | Salvataggio anagrafica | v1.10 |
| Tipologia `comodatario` non coesiste con `inquilino` sullo stesso immobile (warning) | Salvataggio anagrafica | v1.10 |
| `quota_detrazione` nullable, non vincolata a sommare 100% | Sempre | v1.10 |
| **Somma `quota_bilancio` dei soggetti `in_bilancio = true` dello stesso ruolo = 100%** (peso entro il ruolo, rinormalizzato in distribuzione) | Salvataggio anagrafica, generazione riparto | v1.11 |
| Almeno un soggetto con `in_bilancio = true` per ogni immobile attivo | Salvataggio anagrafica | v1.11 |
| Somma percentuali `quote_tabella_ripartizioni` per quote_tabella = 100% | Salvataggio override | v1.11 |

> **Due controlli di coerenza distinti, da non fondere.** (1) **Coerenza millesimi** — livello *tabella*, "la tabella è trascritta giusta?" (somma valori = totale dichiarato), si corregge sui millesimi. (2) **Coerenza-ruoli** — livello *split per ruolo*, "questo coefficiente ha un destinatario reale su ogni immobile?", si corregge sulle anagrafiche o accettando la cascata. Stessa filosofia (fail-fast + override + nota), trigger diversi. Vivono come controlli fratelli nello stesso modulo di coerenza.

---

## 6. Casistiche supportate dopo l'implementazione completa

| Scenario | Modellazione | Da |
|---|---|---|
| **Spese scale ex art. 1124** con tabelle già depositate | Voce "Manutenzione scale" → 50% Tab Proprietà + 50% Tab Altezze | v1.10 |
| **Spese scale ex art. 1124** senza Tab Altezze | Tab manuale con altezze (o n. piano), poi voce → 50% Tab A + 50% Tab Manuale | v1.10 |
| **Più scale/ascensori distinti** | Tabelle scopate per scala, voci separate | v1.10 |
| **Distribuzione per parti uguali** (compenso amministratore, c/c) | Tab manuale con valore 1 per unità (= 1000/N) | v1.10 |
| **Distribuzione per residenti / superficie** | Tab manuale con n. residenti / mq per unità | v1.10 |
| **Ordinaria → godente, straordinaria → capitale** (caso comune, *qualsiasi composizione*) | Coefficiente per-tabella: ordinaria su `inquilino`, straordinaria su `proprietario`; **la cascata risolve le unità senza quel ruolo** (inquilino assente → usufruttuario/proprietario; ecc.). **Nessun override necessario.** | v1.10 |
| **Anagrafica catastale completa** | Nuovi campi su `immobili` | v1.10 |
| **Vani decimali** (13,5) | Modifica tipo colonna | v1.10 |
| **Eredi minorenni nei riparti** | `in_bilancio = false`; il 100% del ruolo si redistribuisce tra gli adulti; alert alla maggiore età | v1.10 (flag) / v1.11 (riparto) |
| **Detrazione 50% residente / 36% non residente / 36% inquilino-comodatario / 65% ecobonus / 75% barriere** | `aliquote_detrazione` a due assi + `quota_detrazione` per soggetto | v1.10 |
| **Coppia comproprietari, in bilancio solo uno** | Uno `in_bilancio=false`; l'altro `quota_bilancio=100` dentro il ruolo proprietario | v1.11 |
| **Immobile affittato, *tutte* le spese all'inquilino** (contratto derogatorio) | *Eccezione* → override `quote_tabella_ripartizioni`: 100% inquilino su tutte le tabelle di quell'immobile | v1.11 |
| **Usufrutto/nuda proprietà 70/30 pattuito** | *Eccezione* → override `quote_tabella_ripartizioni`: 70% usufruttuario, 30% nuda_proprietario | v1.11 |
| **Comodato gratuito** | Ruolo `comodatario`; nella cascata di godimento accanto all'inquilino | v1.10 (ruolo) / v1.11 (override) |
| **Lavori straordinari su sotto-insieme di immobili** | Tabella dedicata con `quote_tabella` solo per gli immobili coinvolti | Già esistente |
| **Addebito ad personam** (riparazione causata da un singolo) | Riga di fattura collegata direttamente all'immobile, bypass del meccanismo millesimale | Già esistente |

> **Norma vs eccezione.** I casi *comuni* (ordinaria/straordinaria per natura) si gestiscono con il **coefficiente per-tabella + cascata**, senza toccare il singolo immobile. L'**override per-immobile** è riservato alle *eccezioni pattuite* (villetta derogatoria, 70/30, ecc.).

---

## 7. Cosa NON cambia

- Tutta la logica contabile in partita doppia (scritture, `FatturaPassivaService`, scadenziario).
- I dati delle tabelle millesimali esistenti — nessuna migrazione di valori.
- `piano_rate_capitoli` e la pianificazione dei budget — completamente ortogonale.
- I riparti già generati prima di queste modifiche restano validi e consultabili.
- L'addebito ad personam su singolo immobile — già funzionante.
- Il comportamento di default per i riparti esistenti — garantito da test di regressione in v1.11.

---

## 8. Domande aperte / decisioni

1. **`%Bilancio` entro il ruolo** *(da confermare con l'amministratore)*: si adotta il modello "peso entro il ruolo" del §2.3? Copre tutti i suoi casi e risolve il conflitto con le percentuali per ruolo. In caso contrario, va ridiscusso perché "100% sull'intero immobile" si sovrappone alla selezione del ruolo.
2. **Aliquote detrazione 2026** *(risolto per il 2025, aperto per il 2026)*: il seed 2025 è confermato dalla normativa nota; le aliquote 2026 vanno verificate prima del seed di produzione.
3. **`comodatario`** *(proposto: aggiungere)*: confermata l'aggiunta come ruolo distinto (utile per detrazione 36% "diritto personale di godimento" e per la generalità richiesta dall'amministratore). In cascata sta sull'asse godimento.
4. **Storicizzazione del rapporto in riparto** *(verifica tecnica)*: confermare l'esistenza del meccanismo che pone `attivo = false` allo scadere di `data_fine`; in assenza, prevedere observer/job in v1.11.
5. **Versioning** *(decisione)*: confermata la confluenza della ex-`v1.10.1` in `v1.10` (allineamento alla roadmap), oppure ripristino della patch dedicata?
6. **Notifica alert maggiore età**: dashboard, email, o entrambi?
7. **Tipo `manuale` vs riuso di `altro`/`speciale`**: si conferma il tipo esplicito `manuale` per chiarezza semantica?

---

## 9. Razionale dello split su release

- **Continuità con quanto pianificato:** la v1.10 resta "release per tabelle, art. 1124 e anagrafica".
- **Gestione del rischio:** ogni release contiene modifiche coerenti e testabili indipendentemente; un eventuale rollback impatta una sola area.
- **Beneficio incrementale:** la cascata e il warning coerenza-ruoli (v1.10) sbloccano *subito* il caso usufrutto sul meccanismo per-tabella esistente, senza attendere il refactor per-immobile (v1.11).