# 🗺️ Kondomanager — Roadmap

> Piano di sviluppo versionato per Kondomanager.
> Le versioni sono sequenziate logicamente: alcune dipendono da altre.

---

## Stato corrente

**In sviluppo:** `v1.9.1` — Pagamento Fatture MVP

---

## v1.9.x — Pagamenti

### v1.9.1 — Pagamento Fatture MVP *(in lavorazione)*

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

### v1.9.2 — Pagamenti Avanzati

- Acconti ai fornitori (conto Crediti v/Fornitori dedicato, compensazione via giroconto)
- Anticipi dell'amministratore con workflow di rimborso
- Compensazione pura senza movimento di cassa (NC > Fattura)
- Distinzione `data_pagamento` vs `data_valuta`
- Annullamento pagamento con motivazione obbligatoria
- Assegni con gestione data emissione vs addebito
- Abbuoni passivi
- RID/SDD passivi (rimandati a v1.16 per integrazione con riconciliazione bancaria)

### v1.9.3 — Export SEPA

- Generazione XML Pain.001.001.03 (standard SEPA)
- Variante CBI italiana (Unicredit, Intesa, BPER)
- Validazione XSD prima dell'export
- Bonifico Parlante nella sezione RmtInf con gestione overflow caratteri (CF condòmini multipli)
- Multi-banca testing
- Workflow real-world: XML uploaded a home banking, autorizzazione SCA/OTP PSD2 (non automatizzata)
- Fallback v1.9.1: export CSV/lista

---

## v1.10 — Foundation Release

Release che porta a maturità l'infrastruttura contabile.

- **Voci di accantonamento** con `fondo_target_id`, bifurcation incasso, `delibera_id` nullable
- **Giroconti** tra conti correnti e fondi
- **Stato Patrimoniale operativo** con scritture di chiusura
- **Bilanciatore Fondi** — verifica copertura liquida, morosità per immobile, quota segue immobile
- **Dashboard Intelligence:**
  - Treasury Guardian Widget — predittore liquidità a 30 giorni; scan conti vs fatture in scadenza; propone emissione insoluti, sollecito rate, giroconto fondo riserva *(MVP anticipato a v1.9.x; vista distesa in v1.18)*
  - Radar Salute Contabile — validatore millesimi + detector duplicati intelligenti (nascosto se OK, semaforo emergenza se dati incoerenti)
  - Credit Enforcer Widget — pannello morosità con link diretto al Wizard Solleciti
- **Backup Management**
- **Iniziativa A — Tabelle Millesimali avanzate:**
  - Supporto Art. 1124 c.c. (scale e ascensori)
  - Tipo `manuale` aggiunto all'enum
  - Tabelle manuali con quote relative
  - Filtri scala/palazzina in `QuoteList.vue`
  - Contatore totale live + colonna percentuale effettiva
  - Warning giallo non-bloccante su deviazione somma millesimi da 1000
- **Iniziativa B Fase 1 — Anagrafica Immobiliare:**
  - Campi catastali completi, vani decimali
  - Flag `in_bilancio`, `quota_detrazione`, tabella `aliquote_detrazione`
  - Alert maggiore età sul registro anagrafico
  - Tabella `forniture_immobile` con POD (energia), PDR (gas), seriali contatori
  - `codice_cliente`, `intestatario_id`, `valid_from`/`valid_to` per storico subentri

---

## v1.11 — Recupero Crediti + Motore Riparto Unificato

- Normalizzazione `piani_rate_capitoli` con comando Artisan
- Popolamento `rate_quote.riga_fattura_id` e `voce_id`
- Attivazione `stato_legale_aggiornato_at` su modifica stato legale
- **Motore Riparto Unificato:**
  - Livello 1 — quota per immobile (millesimi o quote relative)
  - Livello 2 — distribuzione tra soggetti per ruolo (proprietario/inquilino/usufruttuario)
  - Supporto tabelle statiche (millesimi) e dinamiche (consumi acqua/calore per periodo)
  - Versioning con `periodo_id` + snapshot alla chiusura
- `quota_bilancio` + per-immobile override

**Cleanup tecnici:**
- Temporal check in `Conto::getHasRateEmesseAttribute`
- Rimozione filtro `created_at` in `BudgetCoverageService` Step 1
- Rimozione `isParentLocked` da `AlberoDeiConti.vue`

---

## v1.12 — DNA Fiscale Fornitore

Sviluppato in 4 fasi parallelizzabili.

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

## v1.13 — Modulo Manutenzioni

*Sequenziato dopo v1.12 per sfruttare i dati DNA Fiscale del fornitore.*

- Registro beni condominiali (caldaie, ascensori, antenne, ecc.)
- Pivot `asset_immobile` per interventi su singole unità
- Scadenzario manutenzioni collegato al sistema eventi esistente
- Documenti allegati (verbali, certificazioni, contratti)
- Storico interventi con costi linkati alla contabilità
- Fornitore/manutentore con anagrafica fiscale completa
- Dashboard scadenze + reminder via email

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

- Report personalizzabili
- Export avanzati (Excel, PDF formattati)
- Prospetti per assemblea
- Analytics di condominio
- **Pagina Treasury Guardian** — vista distesa e operativa del widget di dashboard, drill-down per singolo condominio. Il widget compatto risponde a *"devo preoccuparmi?"*, questa pagina a *"cosa faccio?"*.
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
- Suite stampa completa (riparti, rendiconti, estratti conto, prospetti)

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
I dati anagrafici al momento del pagamento sono congelati in JSON con `schema_version`. Modifiche future all'anagrafica non alterano lo snapshot storico.

### 6. Read model materializzati
Stati derivati (es. `stato_pagamento` su fattura) sono cache di calcoli, non dati autoritativi. Comando Artisan di riconciliazione disponibile per ricalcolarli.

### 7. Pivot-typed architecture
La pivot `fattura_scrittura` ha campo `tipo` enum (`competenza | pagamento | compensazione`). Il calcolo del residuo esclude SEMPRE `competenza`.

### 8. Idempotency su tutte le operazioni finanziarie
UUID `idempotency_key` con UNIQUE constraint + wrapper `try/catch` su `QueryException` per race condition production-grade.

### 9. Test prima del rilascio
Ogni modulo ha una suite Pest che deve passare al 100% prima del deploy in produzione. Property-based test per invarianti di sistema (quadratura, cash = pivot pagamento).

### 10. Backed Enums + VARCHAR(50)
Vocabolario di dominio in PHP Backed Enum, persistito come `VARCHAR(50)` con cast Eloquent. Niente `ENUM` MySQL su tabelle che evolveranno.

---

## Stack tecnologico

- **Backend:** Laravel 12, PHP 8.2+, Eloquent ORM, Pest (testing)
- **Frontend:** Vue 3, Inertia.js, Tailwind CSS, Chart.js
- **Database:** MySQL 8.0.33 (produzione), SQLite in-memory (test)
- **Standard contabili:** Partita Doppia, Art. 1124/1135 c.c., IVA/ritenute/F24/770
- **Standard pagamenti:** SEPA Pain.001.001.03, CBI italiana, PSD2 SCA/OTP

---

## Contribuire

Hai un'idea per una feature? Vuoi anticipare qualcosa nella roadmap? Apri una issue o discuti sul forum:

🔗 [forum.kondomanager.com](https://forum.kondomanager.com)

---

*Ultimo aggiornamento: in fase v1.9.1*