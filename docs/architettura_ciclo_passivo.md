# Architettura Tecnico-Funzionale: Modulo Ciclo Passivo

**Versione:** 1.0.0  
**Data:** 01 Gennaio 2026  
**Contesto:** Gestionale Condominiale Enterprise  

---

## 1. Principi Fondamentali

### 1.1 Separazione Documento / Contabilità

- Il **Documento Fiscale** (`fatture_passive`) rappresenta la verità storica ricevuta dal fornitore.
- La **Scrittura Contabile** (`scritture_contabili`) rappresenta l’impatto economico-finanziario sul bilancio (Partita Doppia).
- Le due entità **non coincidono** e **non devono mai sovrapporsi**.

Il collegamento avviene **esclusivamente** tramite la tabella pivot `fattura_scrittura`.

La pivot specifica il **ruolo contabile** della scrittura rispetto alla fattura:
- `competenza`
- `pagamento`
- `ritenuta`

Questa tabella rappresenta il **fatto contabile reale**.

---

### 1.2 Architettura Event-Driven

Lo stato finanziario **non è mai modificato direttamente** sulla fattura.

- Ogni evento economico è espresso come **ScritturaContabile**.
- Lo stato della fattura (**aperta**, **parziale**, **pagata**) è **sempre derivato**.
- Non esistono flag manuali o aggiornamenti imperativi.

Questo garantisce:
- coerenza temporale
- ricostruibilità storica
- assenza di disallineamenti

---

### 1.3 Strict Typing Finanziario

- Tutti gli importi monetari sono salvati come **INTERI (centesimi)**.
- È vietato l’uso di `float` o `double`.
- Tutti gli importi sono **signed** (`bigint`).

Questa scelta elimina errori di arrotondamento e rende il sistema stabile nel lungo periodo.

---

### 1.4 Logica del Segno (Somma Algebrica)

Il sistema utilizza una **regola unica e immutabile**:

- **Fatture** → importi **POSITIVI**
- **Note di Credito** → importi **NEGATIVI**

Il segno viene determinato **una sola volta**, in fase di registrazione del documento.

Conseguenze:
- nessuna logica condizionale nei report
- saldi calcolati sempre con `SUM(importo)`
- comportamento matematico deterministico

---

## 2. Schema Database (ERD)

### 2.1 Anagrafica e Intelligence (`fornitori`)

Il fornitore è un’entità **fiscale**, non contabile.

| Campo                        | Tipo     | Note                                           |
|------------------------------|----------|------------------------------------------------|
| `soggetto_ritenuta`          | bool     | Attiva il calcolo automatico                   |
| `perc_ritenuta`              | decimal  | Aliquota (es. 20%, 4%)                         |
| `perc_imponibile_ritenuta`   | decimal  | Base imponibile (100%, 50%)                    |
| `codice_tributo`             | string   | Codice F24 (es. 1040)                          |

### 2.2 Testata Documento (`fatture_passive`)

Rappresenta la **verità fiscale**.

| Campo                  | Tipo     | Note                                           |
|------------------------|----------|------------------------------------------------|
| `tipo_documento`       | enum     | `fattura`, `nota_credito`                      |
| `importo_imponibile`   | bigint   | Signed                                         |
| `importo_ritenuta`     | bigint   | Signed – debito potenziale v/Erario            |
| `netto_a_pagare`       | bigint   | Signed                                         |
| `stato_pagamento`      | enum     | Derivato: `aperta`, `parziale`, `pagata`       |

### 2.3 Dettaglio Analitico (`righe_fattura`)

Collega il documento fiscale alle **Voci di Bilancio Condominiali**.

| Campo                  | Tipo     | Note                                           |
|------------------------|----------|------------------------------------------------|
| `conto_id`             | FK       | Voce di preventivo/consuntivo                  |
| `importo_imponibile`   | bigint   | Signed                                         |
| `aliquota_iva`         | decimal  | Multi-aliquota                                 |

### 2.4 Ponte Contabile (`fattura_scrittura`)

È l’unico punto di collegamento tra documento e contabilità.

| Campo              | Tipo     | Note                                           |
|--------------------|----------|------------------------------------------------|
| `tipo`             | enum     | `competenza`, `pagamento`, `ritenuta`          |
| `importo_allocato` | bigint   | Quota di scrittura applicata                   |

**Regola di Dominio:**  
Non può esistere un pagamento senza una scrittura di competenza preesistente.

---

## 3. Flusso Operativo: Registrazione Fattura

Metodo: `FatturaPassivaService::registraFattura()`

L’intero flusso è una **transazione atomica**.

### Step 1 – Determinazione Segno
- `nota_credito` → moltiplicatore `-1`
- `fattura` → moltiplicatore `+1`

### Step 2 – Calcoli Fiscali
- Calcolo imponibile e IVA riga per riga (applicando il segno)
- Se il fornitore è soggetto a ritenuta:  
  `Ritenuta = (Imponibile × %Base) × %Aliquota`
- `Totale Documento = Imponibile + IVA`
- `Netto a Pagare = Totale Documento − Ritenuta`

### Step 3 – Persistenza Documento
- Inserimento record in `fatture_passive`
- Inserimento righe in `righe_fattura`

### Step 4 – Generazione Scrittura di Competenza
Scrittura in partita doppia:
- **DARE** → Conti di costo / voci di bilancio  
- **AVERE** → Debiti v/Fornitori  

Per le note di credito, gli importi negativi **riducono costi e debiti**.

### Step 5 – Collegamento
- Inserimento record in `fattura_scrittura` con `tipo = competenza`

---

## 4. Calcolo Saldo e Stato Fattura

La formula ufficiale del saldo residuo è:
