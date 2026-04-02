# 📘 MANUALE DEFINITIVO
## Kondomanager — Gestione Spese e Piani di Rateazione
### Versione 1.9.4

---

## 1. Premessa: Il Motore a Regole

Il sistema si basa su un principio fondamentale:

> **La natura della spesa determina automaticamente il comportamento del software.**

Non è l'amministratore che deve decidere *come trattare* la spesa:
è il sistema che guida il flusso corretto.

Questo approccio elimina errori operativi, garantisce coerenza contabile e introduce un controllo finanziario reale del condominio.

### La Regola d'Oro del Motore

> **Le rate non vengono calcolate sull'importo della fattura,
> ma sull'importo finanziato dal piano rate.**

Questa distinzione è il cuore dell'architettura finanziaria.

---

## 2. I 5 Scenari Operativi

---

### 🟢 SCENARIO 1: Spesa Preventivata (Regolare o con Sforo)

**Situazione:**
La fattura appartiene a una voce già prevista e approvata dall'assemblea a inizio anno.

**Esempi:** Manutenzione ascensore, pulizia scale, assicurazione fabbricato

| Fase | Descrizione |
|------|-------------|
| **Registrazione** | Selezione del `conto_id` dal piano dei conti |
| **Tabelle Millesimali** | Automatiche dal Piano dei Conti — nessuna scelta richiesta |
| **Sforo Budget** | Widget diventa rosso + richiesta motivazione testuale obbligatoria |
| **Riscossione** | Inclusa nelle rate ordinarie già emesse — nessuna azione richiesta |

📌 **Nota operativa:**
Se serve liquidità immediata per coprire uno sforo → creare un **Piano Ordinario Integrativo** selezionando la voce sforata e impostando manualmente l'importo da recuperare.

**Impatto contabile:** Conto Economico corrente
**Action Inbox:** NO
**Tipo piano rate:** Ordinario (già esistente)

---

### 🔴 SCENARIO 2: Spesa Fuori Preventivo (Imprevisto Condominiale)

**Situazione:**
Arriva una fattura per una spesa non prevista che riguarda tutto il condominio.
Non esiste una voce di bilancio dedicata.

**Esempi:** Crollo albero, guasto straordinario citofono, multa al condominio, tubo comune esploso

| Fase | Descrizione |
|------|-------------|
| **Registrazione** | Flag `is_sopravvenienza = true` sulla riga |
| **Conto** | Dropdown disabilitato — il sistema usa automaticamente il Mastro Sopravvenienze (`ruolo = sopravvenienze_passive`) |
| **Tabelle Millesimali** | La tabella viene letta dal conto associato alla riga |
| **Autorizzazione Legale** | Obbligatoria al momento della creazione del piano straordinario |
| **Riscossione** | Piano Rate Straordinario |
| **Trigger UX** | Action Inbox attiva |

🧠 **Logica chiave:**
Il sistema NON assume nulla → obbliga l'amministratore a una decisione consapevole prima di generare le rate.

**Impatto contabile:** Conto Economico corrente (Mastro Sopravvenienze Passive)
**Action Inbox:** SÌ — "Emettere rate per spesa imprevista"
**Tipo piano rate:** Straordinario

---

### 🟣 SCENARIO 3: Spesa Ad Personam (Ripartizione Mista)

**Situazione:**
Una spesa in cui una parte è a carico di tutti (millesimi) e una parte è a carico esclusivo di un singolo proprietario.
Può coesistere nello Scenario 2 nella stessa fattura.

**Esempio:** Fattura muratore 1.000€ — 200€ per cornicione comune, 800€ per balcone privato di Rossi

| Riga | Importo | `conto_id` | `immobile_id` | Comportamento motore |
|------|---------|-----------|--------------|---------------------|
| 1 | 200€ | Manutenzione | NULL | Spalma per millesimi su tutti |
| 2 | 800€ | Manutenzione | 18 (Rossi) | **OVERRIDE**: 100% a Rossi |

**La Regola dell'Override:**
Un millisecondo prima di applicare i millesimi, il motore controlla `immobile_id`.
Se valorizzato → ignora completamente la tabella millesimale → addebita il 100% al proprietario di quell'immobile.

**Risultato sul bollettino di Rossi:** 822€ (22€ quota millesimale + 800€ addebito diretto)
**Risultato sul bollettino di Bianchi:** 18€ (solo quota millesimale)

**Impatto contabile:** Conto Economico — il conto rimane "Manutenzione" per entrambe le righe. Il bilancio mostra l'importo corretto per natura di spesa.
**Action Inbox:** SÌ se presente riga con `is_sopravvenienza = true`
**Tipo piano rate:** Straordinario

---

### 🟡 SCENARIO 4: Debito Pregresso (Passate Gestioni)

**Situazione:**
Salta fuori una fattura di esercizi precedenti non contabilizzata.

**Esempio:** Bolletta acqua 2024 registrata nel 2026

| Fase | Descrizione |
|------|-------------|
| **Registrazione** | Flag `is_pregresso = true` + Widget Double Lock |
| **Contabilizzazione** | Stato Patrimoniale — NON tocca il Conto Economico corrente |
| **Copertura** | Tre opzioni: `rata_0`, `sopravvenienza`, `fondo_riserva` |
| **Tabelle Millesimali** | Modale obbligatoria (scelta manuale della tabella) |
| **Riscossione** | Solo se copertura = `sopravvenienza` → Piano Straordinario |

**Le tre opzioni di copertura del Double Lock:**

| Tipo copertura | Significato | Genera piano rate? |
|---------------|-------------|-------------------|
| `rata_0` | Coperto dai saldi iniziali condòmini | NO — già incassato |
| `sopravvenienza` | Convertito in spesa corrente da riscuotere | SÌ — piano straordinario |
| `fondo_riserva` | Coperto attingendo al fondo | NO — fondo si riduce |

⚠️ **Principio fondamentale:**
Il passato NON altera il bilancio corrente dell'esercizio in corso.

**Impatto contabile:** Stato Patrimoniale (Mastro Passate Gestioni o Sopravvenienze)
**Action Inbox:** SÌ se copertura = `sopravvenienza`
**Tipo piano rate:** Straordinario (solo per sopravvenienza)

---

### 🟠 SCENARIO 5: Spesa Preventivata senza Copertura Finanziaria

**Situazione:**
La spesa è prevista a bilancio, l'assemblea l'aveva approvata, ma le rate ordinarie emesse non sono sufficienti a coprire l'importo a cassa.

**Esempio:** Manutenzione preventivata 5.000€ ma i condòmini morosi hanno lasciato solo 3.000€ in cassa

| Fase | Descrizione |
|------|-------------|
| **Registrazione** | Normale spesa preventivata — nessun flag speciale |
| **Controllo** | Il sistema verifica copertura finanziaria reale |
| **Segnalazione** | Widget "Spesa non coperta finanziariamente" |
| **Riscossione** | Suggerito Piano Ordinario Integrativo |

**Impatto contabile:** Conto Economico corrente
**Action Inbox:** Segnalazione (non bloccante)
**Tipo piano rate:** Ordinario Integrativo

---

## 3. Il Ciclo Finanziario Completo

Il software gestisce separatamente le 5 fasi finanziarie di ogni spesa:

| Fase | Oggetto | Domanda chiave |
|------|---------|----------------|
| 1 | **Fattura** | Quanto dobbiamo? |
| 2 | **Tabella Millesimale** | Chi deve pagare? |
| 3 | **Piano Rate** | Quando deve pagare? |
| 4 | **Rate** | Quanto deve pagare per rata? |
| 5 | **Incassi** | Chi ha già pagato? |

```
FATTURA (Debito)
      ↓
TABELLA MILLESIMALE (Ripartizione)
      ↓
PIANO RATE (Finanziamento)
      ↓
RATE (Emissione bollettini)
      ↓
INCASSI (Riscossione)
```

Ogni oggetto risponde a **una sola domanda** — questa separazione garantisce controllo finanziario completo e tracciabilità.

---

## 4. Lo Stato Finanziario della Fattura

Ogni fattura non è solo un costo — è un **debito da finanziare**.

### Stati possibili

| Stato | Condizione | Significato |
|------|-----------|-------------|
| **Da finanziare** | `totale_collegato = 0` | Nessun piano rate collegato |
| **Parzialmente finanziata** | `0 < totale_collegato < importo` | Solo una parte coperta |
| **Finanziata** | `totale_collegato >= importo` | 100% coperta |

### Calcolo automatico

```
totale_finanziato =
    SUM(importo_collegato dalla tabella piano_rate_fatture)
  + importo coperto da fondo_riserva (fattura_coperture)
  + importo coperto da rata_0 (fattura_coperture)
```

Questo stato alimenta la Dashboard, il Widget Finanziario, l'Action Inbox e i Report.

---

## 5. La Pivot Finanziaria — `piano_rate_fatture`

Questa non è una semplice tabella pivot many-to-many.
È una **tabella finanziaria** che risponde alla domanda:

> "Questo piano rate finanzia questa fattura per quale importo e usando quale tabella millesimale?"

### Struttura

| Campo | Tipo | Descrizione |
|-------|------|-------------|
| `piano_rate_id` | FK | Piano rate |
| `fattura_passiva_id` | FK | Fattura |
| `tabella_millesimale_id` | FK nullable | Override tabella (usato per pregresse) |
| `importo_collegato` | bigInteger (centesimi) | Quota finanziata da questo piano |

### Regole sul campo `tabella_millesimale_id`

| Valore | Significato |
|--------|-------------|
| `NULL` | Usa la tabella associata al conto della riga fattura (caso standard) |
| `NOT NULL` | Override esplicito — usato per fatture pregresse dove la tabella viene scelta nella modale |

### Casi abilitati da `importo_collegato`

**A. Rateizzazione parziale su più anni**
```
Fattura tetto: 50.000€

Pivot 2026 → Piano A → importo_collegato: 2.500.000 cent (25.000€)
Pivot 2027 → Piano B → importo_collegato: 2.500.000 cent (25.000€)
```

**B. Copertura mista fondo + rate**
```
Fattura straordinaria: 30.000€

fattura_coperture → fondo_riserva → 10.000€
piano_rate_fatture → Piano Straordinario → importo_collegato: 20.000€
```

**C. Un piano che copre più fatture con importi diversi**
```
Piano Straordinario Gennaio 2026: 15.000€ totali

Fattura A (idraulico) → importo_collegato: 10.000€
Fattura B (elettricista) → importo_collegato: 5.000€
```

---

## 6. Il Motore di Calcolo — Regole di Routing

Il `CalcoloQuoteService` biforca automaticamente in base al tipo di piano:

### Piano Ordinario → `calcolaPerGestione()`
- Legge il budget dal Piano dei Conti
- Usa le tabelle millesimali associate ai conti
- Applica gli override da `piano_rate_capitoli.importo`
- Distribuisce penny-perfect con quadratura

### Piano Straordinario → `calcolaDaFattureStraordinarie()`
- Legge `importo_collegato` dalla pivot `piano_rate_fatture`
- Per ogni riga fattura applica la **Regola dell'Override**:

```
SE riga.immobile_id IS NOT NULL
    → OVERRIDE AD PERSONAM: 100% al proprietario dell'immobile
    → ignora completamente la tabella millesimale

SE riga.immobile_id IS NULL
    → controlla tabella_millesimale_id sulla pivot
    → SE pivot ha tabella → usa quella (override pregresso)
    → SE pivot non ha tabella → usa tabella dal conto della riga
    → SE nessuna tabella → FALLBACK LEGALE
```

### Fallback Legale — Art. 1123 c.c.

Se il motore non trova nessuna tabella millesimale configurata, NON procede automaticamente.
Mostra un avviso esplicito all'amministratore:

> "Nessuna tabella millesimale trovata per questa voce di spesa.
> Per legge la spesa verrebbe ripartita secondo i millesimi generali (Art. 1123 c.c.).
> Vuoi procedere utilizzando la Tabella Generale?"

L'amministratore deve confermare esplicitamente. La scelta viene registrata nel log.

---

## 7. Lo Scudo Legale — Autorizzazione Piani Straordinari

Nessun piano straordinario può essere generato senza documentazione legale.
Il sistema blocca la generazione e richiede obbligatoriamente una delle due opzioni:

### Percorso A — Delibera Assembleare

| Campo | Obbligatorio |
|-------|-------------|
| Data delibera | SÌ |
| Numero verbale | SÌ |
| Note | NO |

### Percorso B — Urgenza Art. 1135 c.c.

| Campo | Obbligatorio |
|-------|-------------|
| Descrizione urgenza | SÌ (min. 20 caratteri) |
| Data intervento | SÌ |
| Data ratifica assemblea prevista | NO |

Questi dati vengono salvati su `piani_rate`:

| Campo DB | Descrizione |
|----------|-------------|
| `tipo_autorizzazione` | `delibera` o `urgenza` |
| `motivazione_autorizzazione` | Testo libero — audit trail legale |
| `data_delibera_assemblea` | Data verbale (già esistente) |
| `numero_verbale` | Riferimento verbale (già esistente) |
| `approvato_da_user_id` | Chi ha cliccato Approva (già esistente) |
| `approvato_il` | Timestamp approvazione (già esistente) |

---

## 8. UX — Le 3 Porte di Accesso al Piano Straordinario

Il sistema è multi-entry — tutti i percorsi convergono nello stesso motore:

| Punto di accesso | Azione | Trigger |
|-----------------|--------|---------|
| **Dalla fattura** | Pulsante "Finanzia con piano rate" | Manuale |
| **Dalla Dashboard** | Action Inbox — card con pulsante "Risolvi" | Automatico |
| **Dai Piani Rate** | Creazione manuale nuovo piano | Manuale |

### Il Carrello delle Fatture (Piano Straordinario)

Quando si crea un piano straordinario, il sistema mostra:
- Fatture correnti con `is_sopravvenienza = true` non ancora finanziate
- Fatture pregresse con copertura `sopravvenienza` non ancora finanziate

L'amministratore seleziona le fatture, imposta l'`importo_collegato` per ciascuna, e il motore calcola automaticamente le quote.

---

## 9. Il Direttore Finanziario Virtuale

### Dashboard Widget

| Metrica | Fonte |
|---------|-------|
| Totale debiti aperti | `SUM(netto_a_pagare)` su fatture aperte |
| Importo finanziato | `SUM(importo_collegato)` da pivot |
| Residuo da finanziare | Differenza |
| Rate da incassare | Rate emesse non pagate |
| Rate scadute | Rate con `data_scadenza < oggi` non pagate |

### Action Inbox — Tabella Trigger

| Evento | Messaggio | Azione suggerita |
|--------|-----------|-----------------|
| Fattura imprevista registrata | "Spesa imprevista da finanziare" | Crea piano straordinario |
| Fattura pregressa con sopravvenienza | "Debito pregresso da riscuotere" | Crea piano straordinario |
| Rate scadute non pagate | "X condòmini morosi" | Invia solleciti |
| Spesa non coperta finanziariamente | "Budget insufficiente" | Piano integrativo |
| Sforamento budget | "Motivazione richiesta" | Approva con scudo legale |

---

## 10. Matrice Riassuntiva Completa

| Tipo Spesa | Flag | Impatto Bilancio | Modale Registrazione | Action Inbox | Tipo Piano Rate | Regola Calcolo |
|-----------|------|-----------------|---------------------|--------------|----------------|----------------|
| Preventivata in budget | — | Conto Economico | Nessuna | NO | Ordinario esistente | Tabelle del conto |
| Preventivata con sforo | `override_budget` | Conto Economico | Motivazione testuale | NO | Ordinario Integrativo | Tabelle del conto |
| Fuori preventivo (imprevisto) | `is_sopravvenienza` | Conto Economico (Sopravvenienze) | Tabella millesimale | SÌ | Straordinario | Pivot + tabella conto |
| Ad personam (singolo) | `immobile_id` | Conto Economico | Nessuna | SÌ se misto | Straordinario | Override 100% |
| Pregressa con rata_0 | `is_pregresso` | Stato Patrimoniale | Double Lock | NO | Nessuno | — |
| Pregressa con sopravvenienza | `is_pregresso` | Stato Patrimoniale | Double Lock + Tabella | SÌ | Straordinario | Pivot override tabella |
| Pregressa con fondo riserva | `is_pregresso` | Stato Patrimoniale | Double Lock | NO | Nessuno | — |
| Preventivata senza liquidità | — | Conto Economico | Nessuna | Segnalazione | Ordinario Integrativo | Tabelle del conto |

---

## 11. Architettura Database — Tabelle Coinvolte

### Tabelle principali

| Tabella | Ruolo |
|---------|-------|
| `fatture_passive` | Documento fiscale — il debito |
| `righe_fattura` | Dettaglio spesa per riga con `conto_id` e `immobile_id` |
| `fattura_coperture` | Double Lock — copertura debiti pregressi |
| `fattura_scrittura` | Collegamento fattura ↔ scritture contabili |
| `piani_rate` | Piano di rateazione (ordinario o straordinario) |
| `piano_rate_capitoli` | Conti associati al piano ordinario |
| `piano_rate_fatture` | **Pivot finanziaria** — fatture associate al piano straordinario |
| `rate` | Singola rata con data scadenza e importo totale |
| `rate_quote` | Quota individuale per condòmino/immobile |

### Campi chiave per il routing del motore

| Campo | Tabella | Significato |
|-------|---------|-------------|
| `tipo` | `piani_rate` | `ordinario` o `straordinario` |
| `tipo_autorizzazione` | `piani_rate` | `delibera` o `urgenza` |
| `is_sopravvenienza` | `righe_fattura` | Riga fuori preventivo |
| `is_pregresso` | `fatture_passive` | Fattura di anni precedenti |
| `immobile_id` | `righe_fattura` | Override ad personam |
| `importo_collegato` | `piano_rate_fatture` | Quota finanziata da questo piano |
| `tabella_millesimale_id` | `piano_rate_fatture` | Override tabella per pregresse |

---

## 12. Note Implementative per Sviluppatori

### Ordine di esecuzione delle migration

```
1. add_is_sopravvenienza_to_righe_fattura
2. add_tipo_straordinario_and_autorizzazione_to_piani_rate  ← include piano_rate_fatture
3. alter_conti_contabili_tipo_categoria_enum  ← aggiunge 'costo' e 'costi'
```

### Servizi coinvolti

| Servizio | Responsabilità |
|---------|----------------|
| `FatturaPassivaService` | Registrazione fattura + scritture contabili + coperture |
| `CalcoloQuoteService` | Calcolo quote per piano ordinario e straordinario |
| `PianoRateCreatorService` | Creazione piano + validazione capitoli unici |
| `GeneratePianoRateAction` | Orchestrazione: biforca tra ordinario e straordinario |
| `GenerateRateQuotesAction` | Creazione fisica rate e quote — penny-perfect |
| `GenerateSaldiAction` | Applicazione saldi iniziali condòmini |

### Invarianti del motore (non violare mai)

1. `SUM(rate_quote.importo)` per piano deve eguagliare `SUM(importo_collegato)` sulla pivot
2. `immobile_id NOT NULL` su `righe_fattura` bypassa sempre la tabella millesimale
3. Le fatture pregresse con `rata_0` o `fondo_riserva` non generano mai un piano rate
4. Nessun piano straordinario può essere approvato senza `tipo_autorizzazione` valorizzato
5. Il fallback Art. 1123 c.c. richiede sempre conferma esplicita dell'amministratore

---

## 13. Cosa NON è ancora implementato (Roadmap)

| Feature | Versione target | Note |
|---------|----------------|------|
| Legal Shield (`debitore_legale_id` / `intestatario_rate_id`) | v1.10 | Separazione soggetto legale da intestatario bollettino |
| Stato finanziario fattura (calcolato) | v1.10 | Widget su lista fatture |
| Conguaglio / Consuntivo | v1.12 | Calcolo differenza preventivo/consuntivo per condòmino |
| Stampa PDF bollettini | v1.11 | Export rate_quote formattato |
| Area condòmini portal | v1.12 | Art. 1130-bis c.c. — accesso condòmini |

---

## 🚀 Conclusione

Kondomanager non è un gestionale condominiale.

È un **motore decisionale finanziario** che gestisce l'intero ciclo di vita di ogni spesa:

```
Nascita del debito (fattura)
        ↓
Ripartizione (tabelle millesimali / override)
        ↓
Finanziamento (piano rate + pivot)
        ↓
Riscossione (rate + bollettini)
        ↓
Chiusura (incasso + riconciliazione)
```

Il sistema non registra solo il passato.
**Il sistema aiuta l'amministratore a gestire il futuro finanziario del condominio.**