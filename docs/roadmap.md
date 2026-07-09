# Kondomanager — Roadmap

> Piano di sviluppo versionato per Kondomanager.
> Le versioni sono sequenziate logicamente: alcune dipendono da altre.

---

## Stato corrente

**Rilasciato:** `v1.9.1` — Smart Treasury & Passive Cycle
**In sviluppo:** `v1.10.0` — The Core Engine Release

---

## v1.9.x — Pagamenti

### v1.9.1 — Smart Treasury & Passive Cycle *(Rilasciato)*

Chiude il ciclo passivo iniziato con la v1.9 (registrazione fatture).

- Bonifico singolo, cumulativo e con netting nota di credito (Opzione B: 3 record pivot, invariante `Σ tipo='pagamento' = uscita_cassa`)
- Pagamenti parziali e multipli sulla stessa fattura
- Bonifico Parlante per detrazioni fiscali (art. 16-bis TUIR, ristrutturazione, ecobonus, sismabonus, superbonus)
- Storno per insoluti con scrittura inversa append-only
- Storno cross-esercizio (Variante B1): se l'esercizio originale è chiuso, lo storno viene registrato nell'esercizio corrente aperto
- Sentinella IBAN, blocco antiriciclaggio (€5.000 contanti — D.Lgs. 231/2007), detector duplicati
- Idempotency key UUID per prevenire doppi inserimenti su retry/concorrenza
- Lock pessimistico con `orderBy('id')` per deadlock prevention
- Refactor F24: la ritenuta matura al pagamento, non alla registrazione fattura
- 22 test automatici di copertura (registrazione, netting, storno, eccezioni, invarianti)

---

## v1.10.0 — The Core Engine Release

Release che porta a maturità l'infrastruttura contabile **e** il Livello 1 del motore di riparto, completa l'anagrafica immobiliare (Iniziativa B Fase 1) e introduce la **risoluzione a cascata del ruolo** con il relativo **warning di coerenza-ruoli**.
Include inoltre le funzionalità avanzate di pagamento originariamente previste per la v1.9.2.

**Pagamenti Avanzati:**
- Acconti ai fornitori (conto Crediti v/Fornitori dedicato, compensazione via giroconto)
- Anticipi dell'amministratore con workflow di rimborso
- Compensazione pura senza movimento di cassa (NC > Fattura)
- Distinzione `data_pagamento` vs `data_valuta`
- Annullamento pagamento con motivazione obbligatoria
- Assegni con gestione data emissione vs addebito
- Abbuoni passivi
- RID/SDD passivi (rimandati a v1.16 per integrazione con riconciliazione bancaria)

**Foundation & Motore:**

- **Voci di accantonamento** con `fondo_target_id`, bifurcation incasso, `delibera_id` nullable;
  fix coverage entry per ancorare la copertura a `voce_spesa_id` (debito tecnico da `FatturaPassivaService`)
- **Hardening DB — Invariante quote condominiali:** check constraint
  `chk_immobile_condominiale` su `rate_quote`
  (`origine_tipo = 'ad_personam' OR immobile_id IS NOT NULL`).
  Trasforma in invariante certificato dal DB ciò che oggi è garantito
  solo dall'application logic di `CalcoloQuoteService`.
  Da aggiungere nella migration delle Voci di accantonamento,
  contestualmente alla valorizzazione di `origine_tipo`.
  // TODO(trigger:v1.10-voci-accantonamento)
- **Giroconti** tra conti correnti e fondi
- **Registrazione a regolazione immediata** (vedi [`registrazione_e_regolazione_immediata.md`](registrazione_e_regolazione_immediata.md)) — scrittura ledger-native senza Fattura a monte (costo → banca/cassa in scrittura unica) per utenze, bolli, commissioni bancarie, F24, piccole spese; fornitore opzionale come tag analitico; guard rail su ritenuta/split payment. Stesso primitivo dei Giroconti: `Scrittura` classificata via `RegistrazioneType`, nessuna riga pivot. Fondamenta per la riconciliazione bancaria (v1.16).
- **Stato Patrimoniale operativo** con scritture di chiusura
- **Estratto conto / situazione di cassa** — vista dei movimenti per conto corrente e cassa, derivata dalle scritture (read-model, non primitivo di scrittura)
- **Bilanciatore Fondi** — verifica copertura liquida, morosità per immobile, quota segue immobile
- **Attestazione debiti/crediti per rogito (MVP):** documento generabile su richiesta
  per singolo condòmino/immobile; mostra credito fondo residuo e debiti aperti verso
  il condominio. Formula: `credito_X = versato_da_X − (speso_dal_fondo × millesimi_X / 1000)`.
  Richiesto per dichiarazione ex art. 1130 n. 9 c.c. in sede di compravendita.
  Alimentato dal Bilanciatore Fondi — dipendenza diretta.
  Suite stampa completa → v1.21.
  > *Origine: feedback beta-tester — confermato da code review su `rate_quote.immobile_id`
  > e `StoreIncassoRateAction`: la tracciabilità per immobile è già garantita a DB.*
- **Dashboard Intelligence:**
  - Treasury Guardian Widget — predittore liquidità a 30 giorni; scan conti vs fatture in scadenza; propone emissione insoluti, sollecito rate, giroconto fondo riserva *(MVP anticipato a v1.9.x; vista distesa in v1.18)*
  - **Radar Salute Contabile** — validatore millesimi + **detector coerenza-ruoli (quota scoperta)** + detector duplicati intelligenti (nascosto se OK, semaforo emergenza se dati incoerenti)
  - Credit Enforcer Widget — pannello morosità con link diretto al Wizard Solleciti
  - **Crediti da compensare Widget** (specchio del Credit Enforcer, ma per i condòmini a credito invece che morosi) — lista condòmini con credito disponibile, link diretto a Nuovo Incasso pre-compilato. Vedi [`credito_visibile_ovunque.md`](credito_visibile_ovunque.md).
- **Credito visibile ovunque** (vedi [`credito_visibile_ovunque.md`](credito_visibile_ovunque.md), seguito diretto del fix v1.10.0-beta.9):
  - Avviso non bloccante quando una compensazione a credito attraversa gestioni diverse (ordinaria/straordinaria), con nota esplicita registrata sulla scrittura
  - Spaccato del credito disponibile per gestione, mostrato nell'avviso e in Estratto Conto
  - Card "Credito disponibile" in Estratto Conto Anagrafica (non esiste ancora una scheda anagrafica dedicata — `AnagraficaController::show()` è uno stub)
  - *Differito:* visibilità del credito lato portale condòmino — richiede prima lavoro di ottimizzazione UI sull'area condòmino
- **Backup Management**
- **Gestione Code Fallite (System Health):**
  - Pannello UI per il monitoraggio della tabella `failed_jobs` con azioni dirette per riprovare (Retry) o eliminare definitivamente (Forget) email e processi di background bloccati.
- **Rateazione per origine e quadratura al centesimo** (vedi [`v1.10_rateazione_origine.md`](v1.10_rateazione_origine.md)): rateazione per origine di addebito (es. preventivo, saldo iniziale, fondo) su scadenze multiple, quadratura al centesimo bidimensionale (per-immobile-first) e predisposizione al calcolo esatto dei subentri.
- **Iniziativa A — Tabelle Millesimali avanzate + motore (Livello 1 + cascata)** (vedi [`tabelle_millesimali.md`](tabelle_millesimali.md)):
  - Supporto Art. 1124 c.c. (scale e ascensori)
  - Tipo `manuale` aggiunto all'enum
  - Tabelle manuali con quote relative
  - Filtri scala/palazzina in `QuoteList.vue`
  - Contatore totale live + colonna percentuale effettiva
  - Warning giallo non-bloccante su deviazione somma millesimi da 1000
  - **Risoluzione a cascata del ruolo** (sostituisce il fallback piatto in `CalcoloQuoteService::distribuisciSuTabelle`):
    godimento `inquilino → comodatario → usufruttuario → proprietario`;
    capitale `nuda proprietà → proprietario` (classe unica).
    Default per natura della spesa (art. 1004/1005), non per coefficiente fiscale.
    Spec: [`cascata_risoluzione_ruolo_coerenza_ruoli.md`](cascata_risoluzione_ruolo_coerenza_ruoli.md).
  - **Warning coerenza-ruoli (quota scoperta):** avviso non-bloccante + importo scoperto quando la cascata è esaurita; override con nota obbligatoria (pattern Validatore Coerenza Millesimi). Sorgente UI: Radar Salute Contabile.
  - **Anteprima riparto con risoluzione esplicita:** il soggetto risolto è mostrato prima della generazione e congelato nel riparto.
- **Iniziativa B Fase 1 — Anagrafica Immobiliare** (vedi [`evoluzione_anagrafica_e_motore_riparto.md`](evoluzione_anagrafica_e_motore_riparto.md)):
  - Campi catastali completi, vani decimali
  - **Estensione ruoli:** `nuda_proprietario`, `comodatario` (enum `anagrafica_immobile.tipologia` e `conto_tabella_ripartizioni.soggetto`)
  - Flag `in_bilancio`, `quota_detrazione`
  - **Tabella `aliquote_detrazione` a due assi** (`categoria_intervento` × `tipo_uso` nullable); seed 5 righe spese 2025 (50/36/36/65/75). ⚠️ **Aliquote 2026 da verificare prima del seed di produzione.**
  - Alert maggiore età sul registro anagrafico
  - Tabella `forniture_immobile` con POD (energia), PDR (gas), seriali contatori
  - `codice_cliente`, `intestatario_id`, `valid_from`/`valid_to` per storico subentri
  - Correzione copy in pagina associazione anagrafica (includere Usufruttuario e nuovi ruoli)
  - **Verifica:** esiste un meccanismo che pone `attivo = false` allo scadere di `data_fine`? In assenza → observer/job (slittabile a v1.11).

---

## v1.10.1 — Export SEPA

- Generazione XML Pain.001.001.03 (standard SEPA)
- Variante CBI italiana (Unicredit, Intesa, BPER)
- Validazione XSD prima dell'export
- Bonifico Parlante nella sezione RmtInf con gestione overflow caratteri (CF condòmini multipli)
- Multi-banca testing
- Workflow real-world: XML uploaded a home banking, autorizzazione SCA/OTP PSD2 (non automatizzata)
- Fallback v1.9.1: export CSV/lista

---

## v1.11 — Recupero Crediti + Motore Riparto Unificato

- Normalizzazione `piani_rate_capitoli` con comando Artisan
- Popolamento `rate_quote.riga_fattura_id` e `voce_id`
- Attivazione `stato_legale_aggiornato_at` su modifica stato legale
- **Recupero Scoperti Pregressi (Feature Automazione)** (vedi [`scoperto-quote-senza-destinatario.md`](scoperto-quote-senza-destinatario.md)):
  - Creazione tabella dedicata `scoperti_pregressi` per storicizzare gli importi orfani calcolati e scartati dal motore (oggi salvati solo testualmente in `nota_scoperti`).
  - Gestione Lifecycle dello scoperto: tracciabilità stato (aperto/recuperato), collegamento all'utente (chiudibile automaticamente all'emissione della rata riparatrice o manualmente).
  - Integrazione in `PianiRateNew.vue`: rilevamento automatico e check per inglobare/sanare gli scoperti in un nuovo piano rate appena l'anagrafica mancante viene censita sull'immobile.
  - **Chiusura automatica del task inbox:** oggi il task `SCOPERTO_DOCUMENTATO` rimane aperto finché l'admin non lo chiude manualmente. In v1.11, quando viene emesso un addebito manuale o si chiude l'esercizio conguagliando l'importo per l'immobile interessato, il sistema chiude automaticamente il task inbox corrispondente.
- **Motore Riparto Unificato (Livello 2 completo)** (vedi [`evoluzione_anagrafica_e_motore_riparto.md`](evoluzione_anagrafica_e_motore_riparto.md)):
  - Livello 1 — quota per immobile (millesimi o quote relative)
  - Livello 2 — distribuzione tra soggetti per ruolo (proprietario/inquilino/usufruttuario/nuda proprietà/comodatario)
  - **Override per-immobile** delle ripartizioni: nuova tabella `quote_tabella_ripartizioni`
  - **Cascata a generazione** (estesa dalla v1.10) + **distribuzione `quota_bilancio` rinormalizzata entro il ruolo**
  - **`quota_bilancio`** come peso entro il ruolo (migrazione: copia da `quota`; poi `NOT NULL`)
  - **Validazione anti-orfano completa** (versione piena del warning v1.10)
  - Supporto tabelle statiche (millesimi) e dinamiche (consumi acqua/calore per periodo)
  - Versioning con `periodo_id` + snapshot alla chiusura
  - Test di regressione: default identico al comportamento precedente
- UI gestione override per-immobile

**Cleanup tecnici:**
- Temporal check in `Conto::getHasRateEmesseAttribute`
- Rimozione filtro `created_at` in `BudgetCoverageService` Step 1
- Rimozione `isParentLocked` da `AlberoDeiConti.vue`

---

## v1.12 — DNA Fiscale Fornitore

Sviluppato in 4 fasi parallelizzabili (vedi specifica [`anagrafica-fornitore.md`](anagrafica-fornitore.md)).

### Fase A — Anagrafica base
- Campi obbligatori e validatori
- Multi-select categorie
- Migrazione dati legacy

### Fase B — Regime fiscale e IVA
- Regime fiscale (ordinario, forfettario, minimi)
- Natura IVA con Reverse Charge N6.x
- Split Payment per Pubbliche Amministrazioni
- Impatto automatico sul `FatturaPassivaService`

### Fase C — Ritenute e tributi
- Ritenute con causali Certificazione Unica
- Codici tributo F24
- Generazione automatica Modello 770

### Fase D — Documenti e controlli
- DURC con scadenza
- Visura camerale
- Frontalieri svizzeri (regime fiscale particolare)
- Audit log IBAN con double lock

---

## v1.13 — Modulo Manutenzioni (incluso Segnalazioni)

*Sequenziato dopo v1.12 per sfruttare i dati DNA Fiscale del fornitore. (Vedi anche [`modulo_commenti_sengalazioni.md`](modulo_commenti_sengalazioni.md)).*

- Registro beni condominiali (caldaie, ascensori, antenne, ecc.)
- Pivot `asset_immobile` per interventi su singole unità
- Scadenzario manutenzioni collegato al sistema eventi esistente
- Documenti allegati (verbali, certificazioni, contratti)
- Storico interventi con costi linkati alla contabilità
- Fornitore/manutentore con anagrafica fiscale completa
- Dashboard scadenze + reminder via email
- **Integrazione Ciclo Passivo (Workflow Segnalazioni → Lavori → Inbox):** Alla chiusura di un intervento o lavoro generato da una segnalazione, il sistema crea automaticamente un task nell'Inbox dell'amministratore ("Attesa Fattura"). Questo task guiderà l'amministratore a sollecitare la fattura e a registrarla in tempo, prevenendo lo sforamento dei 30 giorni di legge (Art. 1130 c.c.).

---

## v1.14 — Ricevute Attive

- Numerazione progressiva per condominio
- Header personalizzabili
- Generazione PDF
- Invio email con tracking
- Origini: rate ordinarie, conguagli, riparti straordinari, addebiti
- Note di credito passive con link alla fattura originale
- Stornature parziali e totali con tracciabilità

> Nota: sono ricevute, non fatture fiscali. La fatturazione elettronica SDI è valutata su richiesta in versioni future.

---

## v1.15 — Water Metering Module

*(Vedi specifica completa: [`water_metering_module.md`](water_metering_module.md))*

- Contatori come entità (supporto multi-contatore per unità)
- Letture con `origine` enum: manuale, CSV import, API, MQTT, telelettura
- Tabella gateway per dispositivi IoT
- Ingestion REST + CSV (standard); MQTT in fase 2
- `periodi_consumo` con date inizio/fine
- Calcolo sfrido pre-riparto
- Output: tabella dinamica per periodo consumata dal motore riparto unificato

---

## v1.16 — Treasury & Cash Flow

*Completa il quadro contabile dopo Water Metering.*

- Cash flow forecasting esteso
- **Riconciliazione bancaria** — import estratti conto, matching automatico con scritture
- Gestione tesoreria multi-conto
- RID/SDD passivi (qui per integrazione naturale)
- Scoring duplicati completo (CRO/TRN, finestra 7 giorni, IBAN+importo)

---

## v1.17 — Year End Master

*Chiude il ciclo dopo Treasury.*

- Procedura chiusura esercizio guidata
- Generazione rendiconto finale
- Transizione consuntivo → preventivo successivo
- Chiusura e apertura conti automatica
- Cambio gestione e passaggio di consegne

---

## v1.18 — Reporting Suite

*(Vedi specifica: [`layer-reporting-consuntivo.md`](layer-reporting-consuntivo.md))*

- Report personalizzabili
- Export avanzati (Excel, PDF formattati)
- Prospetti per assemblea
- Analytics di condominio
- **Pagina Treasury Guardian** (vedi [`treasury_guardian_report_page.md`](treasury_guardian_report_page.md) e [`treasury_guardian_widget.md`](treasury_guardian_widget.md)) — vista distesa e operativa del widget di dashboard, drill-down per singolo condominio. Il widget compatto risponde a *"devo preoccuparmi?"*, questa pagina a *"cosa faccio?"*.
  - Accesso da pulsante dedicato sul widget compatto in dashboard
  - Grafico proiezione di cassa a 30 giorni (scenari ottimistico, pessimistico, saldo attuale)
  - Pannello "Perché è a rischio" — fatture in scadenza, morosità impattanti, anomalie
  - Azioni suggerite con impatto stimato, ordinate per fattibilità, con link ai flussi operativi
  - Situazione dettagliata + esposizione debiti pregressi segregata dal semaforo
  - Vista per gestione (ordinaria/straordinaria) — risolve la cecità della vista aggregata
  - Riuso di `TreasuryGuardianService` e del DTO esistenti: nessuna nuova logica di dominio, in prevalenza lavoro di presentazione
  - Simulatore liquidità what-if interattivo *(richiede v1.10 — Bilanciatore Fondi)*

---

## v1.19 — Privacy & GDPR Suite

- Gestione consensi
- Data retention policy automatiche
- Diritto all'oblio
- Registro trattamenti
- Export dati personali su richiesta
- Audit log accesso dati sensibili

---

## v1.20 — Claims & Insurance

- Gestione polizze condominiali
- Tracking sinistri
- Scadenze premi
- Documentazione perizie
- Collegamento contabile per addebiti e rimborsi assicurativi

---

## v1.21 — Communication & Workflow

- Notifiche automatizzate ai condòmini (rate, solleciti, comunicazioni)
- Template comunicazioni (convocazioni assemblea, circolari)
- Distribuzione documenti via email/PEC
- Workflow interni (approvazione spese, delibere)
- **Suite stampa completa** (riparti, rendiconti, estratti conto, prospetti)

### Suite Stampe — Riparto per Tabella × Soggetto

*(Specifica completa: [`stampa_riparto_per_condomino.md`](stampa_riparto_per_condomino.md))*

La stampa **Prospetto Completo** (tutte le unità × tutte le tabelle) è già implementata da v1.9.x.
In v1.21 si aggiungono le due varianti per il condòmino:

- **Opzione A — Estratto Personale PDF multi-pagina**: un unico PDF A4 Portrait con una
  sezione per ogni soggetto separata da page-break. L'amministratore stampa e distribuisce
  fisicamente. Nessuna dipendenza aggiuntiva. Stimata: 2–3 sessioni.

- **Opzione B — ZIP con PDF individuali**: un archivio `.zip` contenente un PDF per ogni
  soggetto, nominato `riparto_{anno}_Int{N}_{Cognome}.pdf`, pronto per l'invio email
  individuale. Richiede `ext-zip` PHP. Per >50 soggetti: job asincrono con notifica
  Inbox. Stimata: 3–5 sessioni. Prepara il terreno per l'invio automatico in v1.22.

  > *Nessuna migration necessaria — tutti i dati sono già in `rate_quote`,
  > `quote_tabella`, `conto_tabella_millesimale`.*

- **Riparto per tabella × soggetto (documento trasparenza prioritario per migranti da Danea)**:
  il dato è già in DB — è solo presentazione. Priorità alta per amministratori che migrano
  da Danea/Gestionale.it (vedi note_tecniche).

---

## v1.22 — Fatture Avanzate

- **Fatture ricorrenti** — template + scheduler, pattern numerazione, sospensione temporanea
- **Approval Workflow** — blocco contabilizzazione, approvazione post-hoc
- **Multi-Gestione split** — distribuzione percentuale tra gestioni con scritture multiple
- **Import CSV/Excel** — wizard, mapping colonne, report errori
- **Riparto misto per riga** — criteri: `millesimi_generali`, `riscaldamento`, `scale`, `parti_uguali`, `personale`, `custom`

---

## Moduli Intelligenti (post-v1.12)

Una volta completato il DNA Fiscale, si abilita una serie di moduli "intelligenti" in priorità.

1. **Fiscal Sentinel** — validazione layer su `FatturaPassivaService`, soft warning con override
2. **Compliance Health Badge** — semaforo DURC/Visura/CF/PIVA per fornitore
3. **Smart Ledger Suggester** — suggerimento conto contabile da `categoria_fornitore` × `voce_deliberata`
4. **Treasury Guardian — Freeze DURC** — freeze automatico pagamenti su DURC scaduto *(richiede Fase D)*
5. **Reverse Charge Auto-entry** — doppia scrittura automatica su Natura IVA N6.x
6. **Fiscal Debt Predictor** — previsione mensile F24 *(richiede Fase C)*

---

## Documentazione per sviluppatori

In parallelo al codice, doppia documentazione:

- **Guida personalizzazione installer** — per utenti senza Node.js: traduzioni `lang/`, config, modifiche backend
- **Guida sviluppo source-mode** — setup, architettura, estensione modelli/voci/componenti, convenzioni di test
- **Bridge installer → source** — per migrare in caso di upgrade

> Nota: il frontend Vue/Tailwind non è modificabile senza ricompilazione nell'installer deploy.

---

## Principi architetturali

Regole non negoziabili che guidano tutte le scelte di sviluppo:

### 1. Ledger-centric immutabile
Il giornale contabile è la verità. Mai cancellazioni, solo scritture inverse. Gli storni creano nuove scritture, mai modificano le esistenti.

### 2. BigInteger cents
Tutti i valori monetari come `BIGINT` signed in centesimi. Nessun floating point, mai. Range max ~92 quadrilioni €.

### 3. Penny-perfect arithmetic
Distribuzione esplicita dei resti nei calcoli. Nessun arrotondamento silenzioso.

### 4. Partita Doppia pura
DARE = AVERE sempre. Validato programmaticamente con `DoubleEntryValidator::validateOrFail()` su ogni scrittura prima del commit.

### 5. Snapshot immutabili
I dati anagrafici al momento del pagamento sono congelati in JSON con `schema_version`. Modifiche future all'anagrafica non alterano lo snapshot storico. **Estensione riparto:** anche la risoluzione a cascata del ruolo è congelata nel riparto generato — l'attribuzione esplicita, non la regola, è la verità.

### 6. Read model materializzati
Stati derivati (es. `stato_pagamento` su fattura) sono cache di calcoli, non dati autoritativi. Comando Artisan di riconciliazione disponibile per ricalcolarli.

### 7. Pivot-typed architecture
La pivot `fattura_scrittura` ha campo `tipo` enum (`competenza | pagamento | compensazione`). Il calcolo del residuo esclude SEMPRE `competenza`.

### 8. Idempotency su tutte le operazioni finanziarie
UUID `idempotency_key` con UNIQUE constraint + wrapper `try/catch` su `QueryException` per race condition production-grade.

### 9. Test prima del rilascio
Ogni modulo ha una suite Pest che deve passare al 100% prima del deploy in produzione. Property-based test per invarianti di sistema (quadratura, cash = pivot pagamento).

### 10. Backed Enums + VARCHAR(50)
Vocabolario di dominio in PHP Backed Enum, persistito come `VARCHAR(50)` con cast Eloquent. Niente `ENUM` MySQL su tabelle che evolveranno. *(Vale anche per i ruoli `tipologia`/`soggetto`: l'aggiunta di `nuda_proprietario` e `comodatario` è un cambio di Backed Enum + VARCHAR, non un `ALTER` su `ENUM` MySQL.)*

---

## Stack tecnologico

- **Backend:** Laravel 12, PHP 8.2+, Eloquent ORM, Pest (testing)
- **Frontend:** Vue 3, Inertia.js, Tailwind CSS, Chart.js
- **Database:** MySQL 8.0.33 (produzione), SQLite in-memory (test)
- **Standard contabili:** Partita Doppia, Art. 1124/1135 c.c., riparto godimento/capitale art. 1004/1005 c.c., IVA/ritenute/F24/770
- **Standard pagamenti:** SEPA Pain.001.001.03, CBI italiana, PSD2 SCA/OTP

---

## Indice della Documentazione (Knowledge Base)

Nel progetto sono presenti numerosi documenti di design e specifiche tecniche all'interno della cartella `docs/`. Ecco un indice per orientarsi rapidamente sugli sviluppi passati e futuri:

### 🏗 Architettura e Decisioni Tecniche
- [`note_tecniche_e_decisioni.md`](note_tecniche_e_decisioni.md) — Il registro delle decisioni architetturali principali (ADR).
- [`architettura_ciclo_passivo.md`](architettura_ciclo_passivo.md) — Design system per la gestione del ciclo passivo.
- [`architettura_eventi_inbox.md`](architettura_eventi_inbox.md) — Funzionamento del sistema ad eventi e dell'inbox notifiche.
- [`architettura_mail_system.md`](architettura_mail_system.md) — Struttura per l'invio delle email e template.
- [`architettura_saldi_iniziali.md`](architettura_saldi_iniziali.md) — Regole per l'impostazione dei saldi all'avvio di un condominio.
- [`security_cron_scheduler.md`](security_cron_scheduler.md) — Gestione della sicurezza e dei job schedulati (cron).

### 💰 Contabilità, Fatture e Incassi
- [`pagamenti_fatture.md`](pagamenti_fatture.md) — Logica e stati per il pagamento ai fornitori (cuore della v1.9).
- [`modifica_fatture_e_incassi.md`](modifica_fatture_e_incassi.md) — Guida per il refactoring verso il ledger append-only e storni-auto.
- [`registrazione_fatture_passive.md`](registrazione_fatture_passive.md) — Specifiche di inserimento e partita doppia fatture.
- [`registrazione_e_regolazione_immediata.md`](registrazione_e_regolazione_immediata.md) — Spese senza fattura e giroconti (v1.10).
- [`registrazione_incasso_rata.md`](registrazione_incasso_rata.md) — Flusso e scritture per la registrazione degli incassi.
- [`gestione_debiti_pregressi.md`](gestione_debiti_pregressi.md) — Trattamento contabile delle pendenze di esercizi passati.
- [`layer-reporting-consuntivo.md`](layer-reporting-consuntivo.md) — Specifiche del motore di reporting e bilancio.
- [`treasury_guardian_widget.md`](treasury_guardian_widget.md) & [`treasury_guardian_report_page.md`](treasury_guardian_report_page.md) — Design dell'intelligenza finanziaria e widget di cassa.

### 🏢 Anagrafiche, Riparto e Tabelle
- [`evoluzione_anagrafica_e_motore_riparto.md`](evoluzione_anagrafica_e_motore_riparto.md) — Design per l'anagrafica avanzata (v1.10/v1.11).
- [`cascata_risoluzione_ruolo_coerenza_ruoli.md`](cascata_risoluzione_ruolo_coerenza_ruoli.md) — Algoritmo per addebitare la spesa al soggetto corretto (nuda proprietà, inquilino, ecc.).
- [`tabelle_millesimali.md`](tabelle_millesimali.md) — Gestione tecnica delle tabelle di ripartizione.
- [`stampa_riparto_per_condomino.md`](stampa_riparto_per_condomino.md) — Spec estensione stampa riparto: Opzione A (PDF multi-pagina) e Opzione B (ZIP individuale) per il condòmino (v1.21).
- [`guida_preventivi_rate_capitoli.md`](guida_preventivi_rate_capitoli.md) — Gestione del bilancio preventivo.
- [`logica_piani_rate.md`](logica_piani_rate.md) & [`creazione_piano_rate.md`](creazione_piano_rate.md) — Gestione scadenze e generazione rate.
- [`v1.10_rateazione_origine.md`](v1.10_rateazione_origine.md) — Rateazione per origine e quadratura al centesimo (v1.10).
- [`anagrafica-fornitore.md`](anagrafica-fornitore.md) — Specifiche del DNA Fiscale Fornitori (v1.12).

### 🛠 Strumenti, Utility e Moduli Speciali
- [`modulo_commenti_sengalazioni.md`](modulo_commenti_sengalazioni.md) — Specifiche per la gestione dei ticket e segnalazioni (v1.13).
- [`water_metering_module.md`](water_metering_module.md) — Specifica del modulo lettura contatori acqua (v1.15).
- [`aggiornamento_universale.md`](aggiornamento_universale.md) — Strategie per gli aggiornamenti over-the-air del software.
- [`import_migrazione_dati.md`](import_migrazione_dati.md) — Procedure per importare condomini da altri gestionali.

### ⚙️ Installazione e Deployment (Guide Utente)
- `docker_local_dev.*.md` — Setup ambiente di sviluppo tramite Docker (multi-lingua).
- `synology_nas_install.*.md` — Guida per installare Kondomanager sui server Synology (multi-lingua).

---

## Contribuire

Hai un'idea per una feature? Vuoi anticipare qualcosa nella roadmap? Apri una issue o discuti sul forum:

🔗 [forum.kondomanager.com](https://forum.kondomanager.com)

---

*Ultimo aggiornamento: in fase v1.9.1 — rev. con risoluzione a cascata e coerenza-ruoli (v1.10), motore unificato e override per-immobile (v1.11).*