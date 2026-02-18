# Rapporto Tecnico Consolidato  
**Accounting Core & Intelligence** **Versione 1.9.4** **Stato:** 🟢 Stable  
**Data:** Febbraio 2026

---

## 1. Integrità Contabile e Protezione del Dato

### Double-Lock Strategy (Lucchetto sui Saldi)
- Implementata protezione automatica della colonna `saldo_applicato`
- Al momento della creazione di un **Piano Rate**, il sistema impegna in modo irreversibile il saldo dell’esercizio precedente
- Blocco a livello di **Controller** di qualsiasi tentativo di duplicazione addebito su altre gestioni / condomini
- Meccanismo di **soft-lock** + **hard-lock** su scritture contabili pregresse

### Anti-Pollution Logic
- **Risolto bug critico** di contaminazione debiti tra condomini diversi per lo stesso proprietario
- Isolamento ermetico tramite **filtri stringenti su `condominio_id`** in tutte le query di calcolo saldo e generazione quote
- Validazione contestuale obbligatoria su ogni write/update di movimenti

### Orphan Rate Prevention
- Il motore di calcolo ignora sistematicamente le rate “orfane” (derivate da piani rate cancellati, gestioni obsolete o esercizi chiusi)
- Garantito **saldo reale e pulito** in ogni momento
- Introduzione concetto di **rate tombstone** (record con flag `orphaned_at`)

---

## 2. Motore Contabile Avanzato  
**Trait: CalculatesFinancialWaterfall**

Evoluzione significativa del trait di gestione del flusso pagamenti:

### Rilevamento Rata 0 (Saldo Pregresso)
- Distinzione automatica tra:
  - incasso destinato a **Saldo Pregresso** (debiti esercizi precedenti)
  - incasso destinato a **Spesa Corrente** (quote esercizio in corso)
- Algoritmo waterfall evoluto con priorità esplicita:  
  **Rata 0 → Rate scadute → Rate in scadenza → Rate future**

### Predisposizione Reporting Futuro (v1.12)
- Classificazione pulita e tracciabile delle entrate:
  - Entrate da **recupero arretrati**
  - Entrate da **esercizio corrente**
- Base dati pronta per separation-of-concerns nel rendiconto analitico

---

## 3. UI/UX Piano Rate 2.0 – Professional Tooling

Ridisegno completo dell’interfaccia di gestione piani rate con focus su ergonomia e prevenzione errori

### Miglioramenti principali

- **Dashboard a larghezza piena** Layout ottimizzato per monitor 24–32″ utilizzati in studi di amministrazione

- **Logica condizionale intelligente** Selettore “Distribuzione Saldo Iniziale” (Prima rata vs Rate distribuite) appare **solo** se `SaldoEsercizioService` rileva effettivi arretrati recuperabili

- **Filtro Capitoli “No-Jump”** - Caricamento asincrono capitoli di spesa  
  - Disabilitazione input fino a selezione gestione  
  - Eliminato fastidioso “salto” visivo della pagina

- **Coerenza Design System** - Checkbox neri opachi  
  - Toggle moderni  
  - Pattern Shadcn/UI pienamente rispettati anche su ricorrenza avanzata

---

## 4. Validazione e Testing

### Test di Integrità Matematica
- Verifica end-to-end:  
  **Importo Iniziale – Σ Versamenti = Saldo Residuo atteso** Navigazione completa relazioni: Esercizio → Piano Rate → Quote → Versamenti

### Test di Regressione Critici
- **Double-Lock Strategy** protetta da **test di deploy-blocking** (se la logica di protezione viene rimossa, la pipeline fallisce)
- Coverage aumentata su scenari di: cancellazione piano rate, cambio esercizio, multi-condominio con unico proprietario, incasso parziale con Rata 0.

---

## 5. Budget Allocation & Visualization Engine
**Architettura "Polymorphic Budgeting"**

Abbiamo introdotto un modello ibrido per gestire la complessità dei preventivi condominiali, dove convivono importi fissi, saldi a finire e riallocazioni dinamiche.

### Pattern "Jolly" (Wildcard Allocation)
- **Implementazione DB:** Utilizzo del valore `NULL` nella colonna `importo` della tabella pivot `piano_rate_capitoli`.
- **Significato Logico:** `NULL` non significa "zero", ma **"Saldo a finire"**.
- **Comportamento:** Il sistema calcola dinamicamente: `Importo Conto - Σ(Importi Fissi) = Valore del Jolly`.
- **Vantaggio:** Permette di modificare il preventivo globale senza dover aggiornare manualmente le righe dei piani rate integrativi. Il database "respira" insieme al preventivo.

### Algoritmo "Smart Push-Down" (Waterfall Saturation)
Logica di distribuzione dei fondi stanziati su Capitoli Padre (Folder):
1.  **Analisi Deficit:** Il sistema scansiona tutti i sottoconti figli.
2.  **Filtraggio:** Identifica i figli con `Deficit > 0` (Preventivo non coperto da fondi diretti).
3.  **Equa Ripartizione:** Divide il fondo del padre per il numero di figli bisognosi (`N`).
4.  **Assegnazione Saturante:** Assegna a ciascun figlio `min(Deficit, Quota)`.
5.  **Risultato:** Garantisce che i fondi generici coprano i buchi specifici senza sovraccaricare chi è già coperto.

### Dashboard Reconciliation Logic (Macro vs Micro)
Per evitare falsi positivi nell'Audit Dashboard:
- **Check Primario (Macro):** Verifica quadratura `Totale Preventivo vs Totale Rate` (Delta < 5€).
- **Check Secondario (Micro):** Analisi voce per voce.
- **Regola di Soppressione:** Se il Macro è bilanciato, i deficit Micro vengono considerati "Virtualmente Coperti" (dalla logica Push-Down) e non vengono segnalati come anomalie, garantendo coerenza tra widget visivi e logica contabile.

---

## 🗺️ Roadmap Evolutiva  
**Verso lo “Year End Master”**

### Fase 1 – Treasury & Cash Flow  (v1.11)
- **UX Incasso “Rata 0”** Selettore dedicato nella maschera incassi per destinazione esplicita saldo pregresso.
- **Alert Liquidità** in tempo reale  
  Basato sulle informazioni già elaborate dal Trait Waterfall.

### Fase 2 – Reporting Suite  (v1.12)
- **Rendiconto Analitico** professionale  
  Separazione chiara tra: Recupero arretrati e Quote esercizio corrente.
- **Stato Patrimoniale** Integrazione saldi “blindati” nelle poste attive (crediti vs condomini).

### Fase 3 – Year End Master  (v1.13 – Chiusura Esercizio)
- **Wizard di Chiusura in 3 step**
  1. Verifica Contabile (Check fatture aperte, pagamenti orfani, rate sospese).
  2. Riconciliazione (Generazione prospetto saldi finali per approvazione).
  3. Approvazione & Lock (Chiusura formale + passaggio in **sola lettura**).
- **Snapshot Definitivo** Creazione punto di ripristino contabile → base immutable per il `SaldoEsercizioService` dell’anno successivo.

---

**Stato attuale (v1.9):** Fondamenta solide di integrità contabile e protezione dati.  
Motore finanziario evoluto e predisposto per reporting avanzato.  
Interfaccia professionale e sicura per l’operatività quotidiana.

**Prossimo Step Operativo:**
Inizio lavori su **Versione 1.11 - Treasury & Rata 0**.
Focus: Struttura dati per `tipo_rata` ('saldo_iniziale') e UX dedicata per l'incasso selettivo del pregresso.