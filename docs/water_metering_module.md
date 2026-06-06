# Water Metering Module — Documento di Design (v1.15)

> **Stato:** Bozza di design — da rifinire prima dell'implementazione
> **Versione target:** v1.15
> **Dipendenze:** Motore Riparto Unificato (v1.11), Anagrafica Immobiliare + `forniture_immobile` (v1.10.1)
> **Ultimo aggiornamento:** sessione di design iniziale

---

## 1. Obiettivo e perimetro

Il modulo Water Metering gestisce la **misurazione dei consumi idrici** e la loro trasformazione in una tabella di ripartizione consumabile dal motore di riparto. Il modulo si occupa esclusivamente di contatori, letture, calcolo consumi e sfrido. **Non** conosce voci di spesa, conti, fatture o logica di riparto: produce una tabella dinamica per periodo e la consegna al motore di riparto unificato, che la tratta come qualsiasi altra tabella.

Questo disaccoppiamento è la decisione architetturale centrale: Water Metering è testabile in isolamento e non ha dipendenze sul dominio contabile.

### Cosa fa
- Anagrafica contatori (generali e individuali, multi-contatore per immobile)
- Registrazione letture da origini multiple (manuale, CSV, API REST, telelettura)
- Gestione periodi di consumo con date inizio/fine
- Calcolo consumi per immobile (differenza tra letture consecutive)
- Calcolo e distribuzione dello sfrido
- Produzione di una tabella dinamica per periodo

### Cosa NON fa (delegato ad altri moduli)
- Composizione pesata sulle voci di spesa → motore di riparto (v1.11)
- Generazione quote, scritture contabili, conguagli → contabilità esistente
- Codici POD/PDR e identificativi fornitura → `forniture_immobile` (v1.10.1)

---

## 2. Principio architetturale: tabella dinamica

Il motore di riparto unificato (v1.11) accetta due specie della stessa primitiva:

- **Tabelle statiche** — millesimi, altezze, quote deliberate. Configurate una volta, riusate finché non modificate.
- **Tabelle dinamiche** — consumi. Calcolate per ogni periodo a partire dalle letture, versionate con `periodo_id`.

L'output del modulo Water Metering per un periodo è una mappa `immobile_id → valore_consumo` — esattamente la stessa forma di una tabella manuale a quote relative, solo calcolata dinamicamente invece che configurata.

**Conseguenza pratica sulla composizione delle voci:** la voce "Acqua" si configura come somma pesata, identica nel meccanismo all'art. 1124:

| Voce | Composizione |
|------|--------------|
| Acqua | 30% Quota Fissa (statica: parti uguali o millesimi) + 70% Consumo (dinamica) |
| Manutenzione ascensore (art. 1124) | 50% Proprietà (statica) + 50% Altezze (statica) |
| Riscaldamento (UNI 10200) | 30% Millesimi Calore (statica) + 70% Consumo Calore (dinamica) |

Stessa primitiva, stessi pesi, stessa logica di composizione. La differenza sta solo nella sorgente della tabella e nel suo ciclo di vita.

**Versioning obbligatorio:** la tabella dinamica DEVE essere ancorata a un `periodo_id`. Alla chiusura del periodo i valori vengono congelati in uno snapshot. Senza questo, ricalcolare un consuntivo vecchio dopo aver chiuso un periodo nuovo sovrascriverebbe i dati storici.

---

## 3. Modello dati

> **Convenzioni di progetto applicate:**
> - Valori numerici di misura come `BIGINT` in unità intera (no floating point), coerente col principio "BigInteger cents" della contabilità
> - Enum di dominio come PHP Backed Enum persistiti come `VARCHAR(50)` (no `ENUM` MySQL su tabelle che evolveranno)
> - Distribuzione esplicita dei resti (penny-perfect / liter-perfect)

### 3.1 `contatori`

Rappresenta un contatore fisico. Un immobile può avere più contatori; il condominio ha almeno un contatore generale per tipo.

| Campo | Tipo | Note |
|-------|------|------|
| `id` | BIGINT PK | |
| `condominio_id` | BIGINT FK | |
| `immobile_id` | BIGINT FK nullable | NULL per contatori generali/condominiali |
| `tipo` | VARCHAR(50) | Backed Enum: `acqua_fredda`, `acqua_calda`, `calore`, `gas` |
| `ruolo` | VARCHAR(50) | Backed Enum: `generale`, `individuale` |
| `matricola` | VARCHAR | Numero di serie fisico |
| `unita_misura` | VARCHAR(50) | Backed Enum: `litri`, `mc`, `wh`, `kwh` — vedi §3.6 unità |
| `fattore_conversione` | BIGINT | Fattore per convertire il valore grezzo all'unità base intera |
| `data_installazione` | DATE | |
| `data_rimozione` | DATE nullable | Valorizzato alla sostituzione |
| `contatore_sostituito_id` | BIGINT FK nullable | Catena di sostituzione (vedi §5.1) |
| `attivo` | BOOLEAN | |
| `note` | TEXT nullable | |

### 3.2 `letture`

Una lettura puntuale di un contatore. Il consumo è derivato dalla differenza tra letture consecutive, non memorizzato qui.

| Campo | Tipo | Note |
|-------|------|------|
| `id` | BIGINT PK | |
| `contatore_id` | BIGINT FK | |
| `periodo_id` | BIGINT FK nullable | Assegnato quando la lettura entra in un periodo |
| `data_lettura` | DATETIME | |
| `valore` | BIGINT | In unità base intera del contatore (no float) |
| `origine` | VARCHAR(50) | Backed Enum: `manuale`, `csv_import`, `api`, `mqtt`, `telelettura` |
| `tipo_lettura` | VARCHAR(50) | Backed Enum: `effettiva`, `stimata`, `autolettura` |
| `gateway_id` | BIGINT FK nullable | Valorizzato per letture da telelettura |
| `note` | TEXT nullable | |
| `created_at` / `updated_at` | TIMESTAMP | |

**Idempotency:** per le letture da origine automatica (API/MQTT/CSV) prevedere una chiave di deduplica (es. `contatore_id` + `data_lettura` + `origine` UNIQUE, oppure `external_id` dal gateway) per evitare doppi inserimenti su retry — coerente col principio di idempotency delle operazioni sensibili.

### 3.3 `gateway`

Dispositivi di telelettura installati in condominio. Tabella di sola anagrafica/monitoraggio; la traduzione protocollo→formato standard avviene nel gateway, non qui.

| Campo | Tipo | Note |
|-------|------|------|
| `id` | BIGINT PK | |
| `condominio_id` | BIGINT FK | |
| `device_id` | VARCHAR UNIQUE | Identificativo del dispositivo |
| `nome` | VARCHAR | Etichetta leggibile |
| `protocollo` | VARCHAR(50) | Backed Enum: `lorawan`, `mqtt`, `rest`, `altro` |
| `last_seen` | DATETIME nullable | Ultimo contatto |
| `attivo` | BOOLEAN | |
| `note` | TEXT nullable | |

### 3.4 `periodi_consumo`

Periodo di fatturazione/lettura. Quando viene chiuso, i consumi calcolati vengono congelati nello snapshot (§3.5).

| Campo | Tipo | Note |
|-------|------|------|
| `id` | BIGINT PK | |
| `condominio_id` | BIGINT FK | |
| `descrizione` | VARCHAR | Es. "Gennaio–Giugno 2026" |
| `data_inizio` | DATE | |
| `data_fine` | DATE | |
| `tipo_consumo` | VARCHAR(50) | Backed Enum: `acqua_fredda`, `acqua_calda`, `calore`, `gas` |
| `criterio_sfrido` | VARCHAR(50) | Backed Enum: `millesimi`, `parti_uguali`, `proporzionale_consumo` |
| `tabella_sfrido_id` | BIGINT FK nullable | Tabella millesimale usata se `criterio_sfrido = millesimi` |
| `stato` | VARCHAR(50) | Backed Enum: `aperto`, `in_calcolo`, `chiuso` |
| `chiuso_at` | TIMESTAMP nullable | |
| `note` | TEXT nullable | |

### 3.5 `consumi_periodo` (snapshot / tabella dinamica)

Risultato del calcolo per immobile in un periodo. È la **tabella dinamica** consumata dal motore di riparto. Popolata al calcolo, congelata alla chiusura.

| Campo | Tipo | Note |
|-------|------|------|
| `id` | BIGINT PK | |
| `periodo_id` | BIGINT FK | |
| `immobile_id` | BIGINT FK | |
| `consumo_individuale` | BIGINT | Somma consumi contatori individuali dell'immobile |
| `quota_sfrido` | BIGINT | Sfrido attribuito a questo immobile |
| `consumo_totale` | BIGINT | `consumo_individuale + quota_sfrido` — è il valore-tabella |
| `snapshot_at` | TIMESTAMP nullable | Valorizzato alla chiusura del periodo |

**Invariante:** `Σ consumo_totale (tutti gli immobili) = consumo contatore generale del periodo`. Da validare programmaticamente prima della chiusura (analogo a `DoubleEntryValidator`).

### 3.6 Nota sulle unità di misura

Per rispettare il principio "no floating point", i valori sono interi. I contatori idrici leggono tipicamente in mc con precisione al litro. **Decisione di partenza:** memorizzare `valore` e tutti i consumi in **litri** (1 mc = 1000 L) per l'acqua. Per il calore valutare Wh, per il gas litri/dl. Il campo `unita_misura` + `fattore_conversione` documentano la conversione per la visualizzazione. *(Punto da confermare in fase di rifinitura — vedi §7.)*

---

## 4. Contratto di ingestion

L'obiettivo è che chi sviluppa soluzioni di telelettura si adatti al formato di Kondomanager, senza che il core conosca protocolli specifici (LoRa/LoRaWAN/proprietari). Il gateway traduce, Kondomanager ingerisce.

### 4.1 Payload standard

```json
{
  "condominio_id": 12,
  "matricola": "WM-00482",
  "data_lettura": "2026-06-30T08:00:00+02:00",
  "valore": 1284500,
  "unita": "litri",
  "origine": "api",
  "note": "lettura automatica gateway A"
}
```

- `matricola` risolve il `contatore_id` lato server (più robusto dell'ID interno per integrazioni esterne)
- `data_lettura` in ISO 8601 con timezone
- `valore` nell'unità dichiarata; il server converte all'unità base interna
- `origine` tra i valori dell'enum

### 4.2 Canali

| Canale | Fase | Note |
|--------|------|------|
| Upload CSV | 1 (standard) | Wizard con mapping colonne, report errori, riga per riga |
| Endpoint REST `POST /api/v1/letture` | 3 | Autenticato (token per gateway/condominio), idempotente, batch supportato |
| MQTT subscriber | 4 (futuro) | Il gateway pubblica su topic, un worker consuma |

> Inserimento manuale (UI) è la Fase 1 base, non passa dal contratto di ingestion ma scrive direttamente sulla tabella `letture` con `origine = manuale`.

---

## 5. Algoritmi

### 5.1 Calcolo consumo per contatore

```
consumo = lettura_corrente.valore - lettura_precedente.valore
```

**Casi limite da gestire:**
- **Prima lettura** (nessuna precedente): consumo = 0 nel periodo, la lettura fa da baseline.
- **Sostituzione contatore mid-periodo:** il vecchio contatore ha `data_rimozione`, il nuovo ha `contatore_sostituito_id` che punta al vecchio. Consumo periodo = (ultima lettura vecchio − prima lettura periodo vecchio) + (lettura corrente nuovo − lettura installazione nuovo). La catena di sostituzione preserva la continuità.
- **Rollover contatore** (raggiunge il massimo e riparte da 0): se `corrente < precedente` oltre una soglia, applicare la logica di wrap-around sul fondo scala del contatore. *(Da definire se gestire automaticamente o segnalare anomalia.)*
- **Lettura stimata:** quando manca la lettura effettiva, `tipo_lettura = stimata`. Conguaglio alla lettura effettiva successiva. *(Logica di stima da definire — vedi §7.)*

### 5.2 Calcolo e distribuzione sfrido

```
1. consumo_generale  = consumo del contatore generale nel periodo
2. somma_individuali = Σ consumo_individuale (tutti gli immobili)
3. sfrido            = consumo_generale - somma_individuali
4. distribuisci sfrido tra immobili secondo criterio_sfrido:
     - parti_uguali        → sfrido / numero_immobili
     - millesimi           → sfrido × (millesimi_immobile / 1000)
     - proporzionale_consumo → sfrido × (consumo_individuale / somma_individuali)
5. consumo_totale[i] = consumo_individuale[i] + quota_sfrido[i]
```

**Penny-perfect / liter-perfect:** la divisione dello sfrido produce resti. Distribuire i resti esplicitamente (es. al primo immobile, o ai maggiori consumatori) così che `Σ quota_sfrido = sfrido` esatto. Nessun arrotondamento silenzioso.

**Sfrido negativo:** se `somma_individuali > consumo_generale` (possibile per imprecisioni di misura o letture sfasate), lo sfrido è negativo. Decidere se ammetterlo (riduce le quote individuali proporzionalmente) o segnalarlo come anomalia da verificare. *(Vedi §7.)*

### 5.3 Output verso il motore di riparto

Alla chiusura del periodo, `consumi_periodo.consumo_totale` per immobile costituisce la tabella dinamica. Il motore di riparto (v1.11) la consuma identicamente a una tabella statica: la voce "Acqua" composta al 70% su questa tabella + 30% su una statica produce le quote individuali al centesimo.

---

## 6. Fasi di sviluppo

| Fase | Contenuto | Output rilasciabile |
|------|-----------|---------------------|
| **1** | Modello dati (`contatori`, `letture`, `periodi_consumo`, `consumi_periodo`) + UI inserimento letture manuale + calcolo consumi + sfrido + tabella dinamica + integrazione motore riparto | Riparto acqua a consumo funzionante con letture manuali |
| **2** | Import CSV con wizard mapping colonne + report errori | Caricamento massivo letture |
| **3** | Endpoint REST `POST /api/v1/letture` + tabella `gateway` + autenticazione + idempotency | Integrazione telelettura di terze parti |
| **4** | MQTT subscriber | Telelettura real-time push |

La Fase 1 è il MVP completo dal punto di vista funzionale: un amministratore può gestire l'intero ciclo inserendo le letture a mano. Le fasi 2–4 sono ottimizzazioni di ingestion che non cambiano il modello di calcolo.

---

## 7. Punti aperti da rifinire

1. **Unità di misura base:** confermare litri per l'acqua. Definire le unità per calore e gas. Valutare se serve un'unità base unica o per-tipo.
2. **Letture stimate:** algoritmo di stima (media storica? media condominiale? consumo periodo precedente?) e logica di conguaglio alla lettura effettiva.
3. **Sfrido negativo:** ammetterlo con distribuzione proporzionale o bloccarlo come anomalia?
4. **Rollover contatore:** gestione automatica con fondo scala o segnalazione manuale?
5. **Quota fissa acqua:** la componente fissa (parte non a consumo) è una tabella statica separata configurata sulla voce, o va modellata dentro il modulo? Probabilmente è solo configurazione sulla voce → resta fuori dal modulo. Da confermare.
6. **Riscaldamento UNI 10200:** stesso modulo o modulo gemello? Il calore ha i repartitori (non contatori volumetrici) e i millesimi di calore (potenza dispersa, non consumo). L'architettura è la stessa (statica + dinamica) ma i dettagli di misura differiscono. Valutare se generalizzare `contatori` o creare un'entità dedicata.
7. **Permessi/ruoli:** chi può inserire/modificare letture, chiudere periodi? Allineare al sistema di permessi esistente.
8. **Modifica lettura dopo chiusura periodo:** se emerge un errore in un periodo chiuso, come si corregge? (Probabilmente riapertura periodo con ricalcolo, tracciata.)

---

## 8. Riferimenti incrociati

- **Motore Riparto Unificato (v1.11):** consuma la tabella dinamica prodotta qui. Vedi principio tabelle statiche vs dinamiche.
- **Anagrafica Immobiliare (v1.10.1):** `forniture_immobile` contiene POD/PDR/matricole — distinto dai `contatori` di misura, ma collegabile (la matricola del contatore può comparire in entrambi i contesti).
- **Tabelle Millesimali (v1.10 Iniziativa A):** il `criterio_sfrido = millesimi` riusa le tabelle millesimali esistenti.