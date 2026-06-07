# Water Metering Module — Documento di Design (v1.15)

> **Stato:** Bozza di design — da rifinire prima dell'implementazione
> **Versione target:** v1.15
> **Dipendenze:** Motore Riparto Unificato (v1.11), Anagrafica Immobiliare + `forniture_immobile` (v1.10.1)
> **Ultimo aggiornamento:** merge — architettura di ingestion + ecosistema hardware + contratto canonico + `tracciato_import`

---

## 1. Obiettivo e perimetro

Il modulo Water Metering gestisce la **misurazione dei consumi idrici** e la loro trasformazione in una tabella di ripartizione consumabile dal motore di riparto. Il modulo si occupa esclusivamente di contatori, letture, calcolo consumi e sfrido. **Non** conosce voci di spesa, conti, fatture o logica di riparto: produce una tabella dinamica per periodo e la consegna al motore di riparto unificato, che la tratta come qualsiasi altra tabella.

Questo disaccoppiamento è la prima decisione architetturale: Water Metering è testabile in isolamento e non ha dipendenze sul dominio contabile.

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
- Parlare coi contatori / conoscere i protocolli radio → lo fanno i dispositivi di raccolta del produttore (vedi §3.3)

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

## 3. Architettura di ingestion (il problema centrale)

Questa è la parte più delicata del modulo. Ogni produttore di contatori ha il suo formato CSV, il suo software, le sue API. Inseguire ogni produttore integrandolo singolarmente nel core sarebbe insostenibile: ogni nuovo marchio o aggiornamento del loro software richiederebbe una modifica al gestionale. La soluzione è un'altra.

### 3.1 Il principio: un solo formato canonico

Kondomanager definisce **un solo formato interno di lettura** — il payload canonico (matricola, data, valore, unità, origine — specifica concreta in §4). Internamente esiste solo questo. È il contratto, ed è stabile. Tutto il resto si traduce in esso, e **la traduzione avviene ai bordi, non nel core**.

### 3.2 Il modello a due livelli

La complessità specifica del produttore non vive tutta nello stesso posto:

**CSV → traduzione dentro Kondomanager.** Il CSV è strutturalmente semplice e l'utente ha già il file in mano. Qui un wizard di mappatura ha senso. La complessità si incapsula in un **profilo di import** (`tracciato_import`) salvabile e riusabile — vedi §5.

**API / protocolli radio → traduzione fuori da Kondomanager, sul dispositivo di raccolta.** Per la telelettura, l'adapter è il concentratore/gateway. Parla M-BUS/LoRaWAN/protocollo proprietario da un lato, e dall'altro invia i dati a Kondomanager nel formato canonico. Kondomanager espone un solo punto d'ingresso e non conosce i dettagli di ogni marca.

> **Decisione chiave:** non si integrano le API dei produttori nel core. Il core definisce 1 formato; tutto il resto vi si adatta. La varietà sta negli adapter (profili CSV in-core, dispositivi di raccolta sul bordo).

### 3.3 L'ecosistema hardware reale

L'hardware di raccolta **esiste già ed è maturo**: lo costruiscono i produttori di contatori. Kondomanager non entra nel mondo dei dispositivi — riceve il file/dato che questi già producono. La catena è:

```
Contatore (modulo radio wM-BUS/LoRa)
   → Dispositivo di raccolta (concentratore fisso o ricevitore walk-by)
      → File CSV/XML (o push API)
         → Kondomanager
```

**Due scenari concreti di raccolta:**

1. **Walk-by / drive-by** — un tecnico passa periodicamente con un ricevitore portatile, raccoglie le letture via radio, il software (es. BMetering) salva ed esporta un CSV. Nessun dispositivo permanente in condominio. **È il caso più comune nei condomìni piccoli/medi.** Per Kondomanager si traduce nel percorso **import CSV**.

2. **Concentratore fisso** — dispositivo installato stabilmente che ascolta in continuazione i contatori e accumula/inoltra le letture. Per Kondomanager si traduce nel percorso **API/file schedulato**.

**Esempi reali (bMeters, il produttore citato dalla community):**

| Dispositivo | Funzione | Output rilevante per noi |
|-------------|----------|--------------------------|
| RFM-C4 | Concentratore wM-BUS, CAT1/4G, MQTT verso B Metering Cloud | **Invio schedulato (orario/giornaliero/settimanale/mensile) via email o FTP di file CSV/XML/TXT** |
| RFM-C3 | Gateway/concentratore wM-BUS | Trasmissione via GPRS o Ethernet/LAN/Wi-Fi |
| MB MASTER | Concentratore M-BUS cablato (60/250 dispositivi) | Software con **export verso software di fatturazione** |
| LoRaWAN Gateway | Acquisizione end-point LoRaWAN | Ethernet/4G/Wi-Fi |
| RFM-RBT / RBT2 | Ricevitore wM-BUS portatile | Raccolta walk-by |

**Implicazione pratica fortissima:** l'RFM-C4 già fa quasi esattamente ciò che serve — raccoglie e manda un CSV schedulato via email/FTP. Il percorso CSV (Fase 2) si innesta su un dispositivo che esiste e già produce il file. **Zero sviluppo hardware lato Kondomanager.**

**Standard aperto:** i contatori bMeters usano wM-BUS EN13757 (OMS), che permette comunicazione con qualsiasi sistema di lettura che adotti lo stesso protocollo e con accessori di terze parti. Quindi non c'è lock-in sull'hardware del singolo produttore: gateway LoRaWAN/M-BUS generici funzionano sullo stesso principio.

**Chi costruisce "la traduzione":**
- Per il CSV: nessuno. Il concentratore/software produce già il CSV; la traduzione è il profilo di mappatura colonne (configurazione, non hardware).
- Per il real-time via API: un piccolo ponte che prende i dati dal cloud del produttore (o dall'output MQTT/FTP del concentratore) e li spinge sull'API canonica. Lavoro opzionale, sul bordo, nelle fasi successive. Non è core e non blocca nulla.

---

## 4. Contratto di ingestion canonico

Specifica concreta del formato unico verso cui tutto si traduce. È il contratto che gateway, ponti e import CSV devono produrre.

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
- `origine` tra i valori dell'enum (vedi §6.2)

### 4.2 Canali

| Canale | Fase | Note |
|--------|------|------|
| Inserimento manuale (UI) | 1 | Non passa dal contratto; scrive direttamente su `letture` con `origine = manuale` |
| Upload CSV | 2 | Passa per un `tracciato_import` (§5); wizard mapping, report errori riga per riga |
| Endpoint REST `POST /api/v1/letture` | 3 | Autenticato (token per gateway/condominio), idempotente, batch supportato |
| MQTT subscriber | 4 (futuro) | Il gateway pubblica su topic, un worker consuma |

> L'inserimento manuale e l'import CSV producono internamente lo stesso payload canonico degli altri canali: la differenza è solo *come* il dato arriva, non *come* viene rappresentato una volta dentro.

---

## 5. Profilo di import (`tracciato_import`)

Il pezzo che tocca direttamente l'utente nel percorso CSV. Incapsula tutto ciò che serve a interpretare un export di un produttore specifico, in modo riutilizzabile.

### 5.1 Perché non basta "mappare le colonne"

In Italia il parsing CSV deve gestire diverse insidie oltre all'ordine delle colonne:

- **Separatore di campo**: virgola vs **punto e virgola** (Excel italiano usa il `;`)
- **Separatore decimale**: il `;` italiano implica spesso la virgola decimale
- **Formato data**: `GG/MM/AAAA` vs ISO `AAAA-MM-GG` vs altri
- **Unità di misura**: litri vs m³ (con conversione)
- **Righe di intestazione/metadati** da saltare prima dei dati
- **Encoding**: UTF-8 vs Latin-1/Windows-1252

Tutto questo va catturato nel profilo, non gestito ad hoc a ogni import.

### 5.2 Struttura `tracciato_import`

| Campo | Tipo | Note |
|-------|------|------|
| `id` | BIGINT PK | |
| `nome` | VARCHAR | Es. "Export BMetering" |
| `descrizione_sorgente` | VARCHAR nullable | Produttore/software di origine |
| `mappatura_colonne` | JSON | `{matricola: 0, data: 2, valore: 3, ...}` (indice o nome colonna) |
| `delimitatore` | VARCHAR(5) | `,` `;` `\t` |
| `separatore_decimale` | VARCHAR(1) | `.` o `,` |
| `formato_data` | VARCHAR(50) | Pattern di parsing (es. `d/m/Y`) |
| `unita_misura` | VARCHAR(50) | Backed Enum: unità del file sorgente |
| `righe_intestazione` | INT | Quante righe saltare in testa |
| `encoding` | VARCHAR(20) | `utf-8`, `windows-1252` |
| `condiviso` | BOOLEAN | Preset community vs profilo privato |
| `created_at` / `updated_at` | TIMESTAMP | |

**UX risultante:** primo caricamento → configuri e salvi il profilo. Caricamenti successivi → "scegli profilo, carica file, conferma". I profili `condiviso = true` per i produttori comuni (bMeters, Maddalena, Sensus, Istmeca) diventano preset distribuiti: uno li configura, la community ne beneficia. Stesso spirito dei DTO canonici + adapter del modulo import dati.

---

## 6. Modello dati

> **Convenzioni di progetto applicate:**
> - Valori numerici di misura come `BIGINT` in unità intera (no floating point), coerente col principio "BigInteger cents" della contabilità
> - Enum di dominio come PHP Backed Enum persistiti come `VARCHAR(50)` (no `ENUM` MySQL su tabelle che evolveranno)
> - Distribuzione esplicita dei resti (penny-perfect / liter-perfect)

### 6.1 `contatori`

Rappresenta un contatore fisico. Un immobile può avere più contatori; il condominio ha almeno un contatore generale per tipo.

| Campo | Tipo | Note |
|-------|------|------|
| `id` | BIGINT PK | |
| `condominio_id` | BIGINT FK | |
| `immobile_id` | BIGINT FK nullable | NULL per contatori generali/condominiali |
| `tipo` | VARCHAR(50) | Backed Enum: `acqua_fredda`, `acqua_calda`, `calore`, `gas` |
| `ruolo` | VARCHAR(50) | Backed Enum: `generale`, `individuale` |
| `matricola` | VARCHAR | Numero di serie fisico — chiave di risoluzione negli import |
| `unita_misura` | VARCHAR(50) | Backed Enum: `litri`, `mc`, `wh`, `kwh` — vedi §6.7 |
| `fattore_conversione` | BIGINT | Fattore per convertire il valore grezzo all'unità base intera |
| `data_installazione` | DATE | |
| `data_rimozione` | DATE nullable | Valorizzato alla sostituzione |
| `contatore_sostituito_id` | BIGINT FK nullable | Catena di sostituzione (vedi §7.1) |
| `attivo` | BOOLEAN | |
| `note` | TEXT nullable | |

### 6.2 `letture`

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
| `tracciato_import_id` | BIGINT FK nullable | Profilo usato, per letture da CSV |
| `external_id` | VARCHAR nullable | ID dal sistema sorgente, per deduplica |
| `note` | TEXT nullable | |
| `created_at` / `updated_at` | TIMESTAMP | |

**Idempotency:** per le origini automatiche (API/MQTT/CSV) prevedere deduplica via UNIQUE su `(contatore_id, data_lettura, origine)` oppure `external_id`, per evitare doppi inserimenti su retry — coerente col principio di idempotency delle operazioni sensibili.

### 6.3 `gateway`

Dispositivi di telelettura installati. Tabella di anagrafica/monitoraggio; la traduzione protocollo→formato canonico avviene nel dispositivo, non qui.

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

### 6.4 `periodi_consumo`

Periodo di fatturazione/lettura. Alla chiusura i consumi vengono congelati nello snapshot (§6.6).

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

### 6.5 `tracciato_import`

Vedi §5.2 per la struttura completa.

### 6.6 `consumi_periodo` (snapshot / tabella dinamica)

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

### 6.7 Nota sulle unità di misura

Per rispettare il principio "no floating point", i valori sono interi. **Decisione confermata per l'acqua: memorizzare in litri** (1 mc = 1000 L). Conferma esterna: i dispositivi bMeters lavorano in litri (un contatore che mostra 1,231 m³ corrisponde a 1231). Per calore (Wh/kWh) e gas (litri/dl) l'unità base resta da confermare. Il campo `unita_misura` + `fattore_conversione` documentano la conversione per la visualizzazione.

---

## 7. Algoritmi

### 7.1 Calcolo consumo per contatore

```
consumo = lettura_corrente.valore - lettura_precedente.valore
```

**Casi limite da gestire:**
- **Prima lettura** (nessuna precedente): consumo = 0 nel periodo, la lettura fa da baseline.
- **Sostituzione contatore mid-periodo:** il vecchio contatore ha `data_rimozione`, il nuovo ha `contatore_sostituito_id` che punta al vecchio. Consumo periodo = (ultima lettura vecchio − prima lettura periodo vecchio) + (lettura corrente nuovo − lettura installazione nuovo). La catena di sostituzione preserva la continuità.
- **Rollover contatore** (raggiunge il massimo e riparte da 0): se `corrente < precedente` oltre una soglia, applicare la logica di wrap-around sul fondo scala. *(Da definire se automatico o segnalazione.)*
- **Lettura stimata:** quando manca quella effettiva, `tipo_lettura = stimata`. Conguaglio alla lettura effettiva successiva. *(Logica di stima da definire — vedi §9.)*

### 7.2 Calcolo e distribuzione sfrido

```
1. consumo_generale  = consumo del contatore generale nel periodo
2. somma_individuali = Σ consumo_individuale (tutti gli immobili)
3. sfrido            = consumo_generale - somma_individuali
4. distribuisci sfrido tra immobili secondo criterio_sfrido:
     - parti_uguali          → sfrido / numero_immobili
     - millesimi             → sfrido × (millesimi_immobile / 1000)
     - proporzionale_consumo → sfrido × (consumo_individuale / somma_individuali)
5. consumo_totale[i] = consumo_individuale[i] + quota_sfrido[i]
```

**Liter-perfect:** la divisione dello sfrido produce resti. Distribuirli esplicitamente (es. al primo immobile o ai maggiori consumatori) così che `Σ quota_sfrido = sfrido` esatto. Nessun arrotondamento silenzioso.

**Sfrido negativo:** se `somma_individuali > consumo_generale` (imprecisioni di misura o letture sfasate), lo sfrido è negativo. Decidere se ammetterlo (riduce le quote proporzionalmente) o segnalarlo come anomalia. *(Vedi §9.)*

### 7.3 Output verso il motore di riparto

Alla chiusura del periodo, `consumi_periodo.consumo_totale` per immobile costituisce la tabella dinamica. Il motore di riparto (v1.11) la consuma identicamente a una tabella statica: la voce "Acqua" composta al 70% su questa tabella + 30% su una statica produce le quote individuali al centesimo.

---

## 8. Fasi di sviluppo

| Fase | Contenuto | Output rilasciabile |
|------|-----------|---------------------|
| **1** | Modello dati (`contatori`, `letture`, `periodi_consumo`, `consumi_periodo`) + UI inserimento letture manuale + calcolo consumi + sfrido + tabella dinamica + integrazione motore riparto | Riparto acqua a consumo funzionante con letture manuali |
| **2** | Import CSV con `tracciato_import` (wizard mappatura + parsing IT) + report errori | Caricamento del CSV che i concentratori/software già producono (es. export BMetering, RFM-C4 via email/FTP) |
| **3** | Endpoint REST `POST /api/v1/letture` + tabella `gateway` + autenticazione + idempotency | Push da concentratore fisso / ponte cloud del produttore |
| **4** | MQTT subscriber | Telelettura real-time push |

La Fase 1 è il MVP funzionale completo: l'amministratore gestisce l'intero ciclo inserendo le letture a mano. La Fase 2 è quella che sblocca il valore reale per la maggior parte degli utenti, perché si innesta su hardware/export già esistenti. Le fasi 3–4 sono ottimizzazioni di ingestion che non cambiano il modello di calcolo.

---

## 9. Punti aperti da rifinire

1. **Unità di misura base calore/gas:** litri confermati per l'acqua. Definire Wh/kWh per il calore e litri/dl per il gas.
2. **Letture stimate:** algoritmo di stima (media storica? media condominiale? periodo precedente?) e logica di conguaglio alla lettura effettiva.
3. **Sfrido negativo:** ammetterlo con distribuzione proporzionale o bloccarlo come anomalia?
4. **Rollover contatore:** gestione automatica con fondo scala o segnalazione manuale?
5. **Quota fissa acqua:** la componente fissa è una tabella statica separata configurata sulla voce (probabile) o va modellata nel modulo? Da confermare — verosimilmente resta fuori dal modulo.
6. **Riscaldamento UNI 10200:** stesso modulo o gemello? Il calore usa repartitori (non contatori volumetrici) e millesimi di calore (potenza dispersa, non consumo). Architettura identica (statica + dinamica) ma misura diversa. Valutare se generalizzare `contatori` o creare entità dedicata.
7. **Permessi/ruoli:** chi può inserire/modificare letture e chiudere periodi? Allineare al sistema di permessi esistente.
8. **Modifica lettura dopo chiusura periodo:** correzione errori in periodo chiuso (probabile riapertura con ricalcolo, tracciata).
9. **Profili condivisi:** meccanismo di distribuzione dei preset `tracciato_import` (bundle nel repo? import/export profilo? contributo via forum?).

---

## 10. Riferimenti incrociati

- **Motore Riparto Unificato (v1.11):** consuma la tabella dinamica prodotta qui. Vedi principio tabelle statiche vs dinamiche.
- **Anagrafica Immobiliare (v1.10.1):** `forniture_immobile` contiene POD/PDR/matricole — distinto dai `contatori` di misura, ma collegabile (la matricola può comparire in entrambi i contesti).
- **Tabelle Millesimali (v1.10 Iniziativa A):** il `criterio_sfrido = millesimi` riusa le tabelle millesimali esistenti.
- **Modulo Import Dati (design esistente):** stesso pattern driver/adapter + DTO canonici. Il `tracciato_import` è l'applicazione di quella filosofia alle letture.