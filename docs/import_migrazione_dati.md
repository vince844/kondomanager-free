# Import / Migrazione Dati — Guida Architetturale

> Documento di lavoro interno. Definisce l'architettura del modulo di importazione dati
> da altri gestionali condominiali verso Kondomanager. Le interfacce e gli schemi qui
> proposti sono **bozze da validare contro il codice reale** prima dell'implementazione.
>
> **Ultimo aggiornamento:** analisi export reali Danea (4 file) e Giada (4 file)

---

## 1. Obiettivo

Ridurre l'attrito principale all'adozione di Kondomanager: il reinserimento manuale dei
dati quando un amministratore migra da un altro gestionale. L'obiettivo non è replicare lo
storico contabile altrui, ma portare in Kondomanager le informazioni necessarie a **iniziare
un nuovo esercizio già popolato** di anagrafiche, unità, tabelle, fornitori e saldi di apertura.

---

## 2. Principio fondante

I dati **puramente contabili (le scritture/movimenti) non si importano.** Le differenze
strutturali tra gestionali (piani dei conti diversi, netting diverso, gestione della
competenza diversa) rendono impossibile e pericoloso ricostruire la partita doppia altrui
dentro `scritture_contabili`.

Il ponte tra vecchio e nuovo gestionale è il **saldo finale dell'esercizio precedente**, che
diventa il **saldo iniziale** del primo esercizio in Kondomanager, materializzato come
un'unica **scrittura di apertura in quadratura DARE/AVERE**.

Questo principio è confermato dall'analisi dei file reali: sia Danea che Giada espongono
nei loro export i saldi per soggetto/unità in modo diretto e affidabile, ma i movimenti
sottostanti hanno strutture incompatibili con il nostro ledger.

### Messaggio all'utente

> Chiudi l'esercizio col vecchio gestionale, parti pulito con Kondomanager. Ti porti dietro
> anagrafiche, unità, tabelle, fornitori e saldi di apertura — **non** la cronologia contabile.

---

## 3. Cosa NON importiamo (per scelta)

- Storico dei movimenti contabili / scritture.
- Storico pagamenti e incassi di esercizi chiusi.
- Riparti e rendiconti già emessi nel vecchio sistema.
- Ruoli storici (`ex Pr`, `ex Co`, `ex Us` in Giada) — solo stato corrente.
- Attività/ticket operativi (es. Elenco Attività di Danea) — storico operativo non contabile.

---

## 4. I tre livelli di importabilità

| Livello | Cosa | Compatibilità | Dipendenza |
|---------|------|----------------|------------|
| 1 — Master data puro | Condomìni, soggetti, unità immobiliari, fornitori | Mappatura ~1:1 | Anagrafica Immobiliare (v1.10), Anagrafica Fornitore (v1.12) |
| 2 — Strutture semi-standardizzate | Tabelle millesimali, piano dei conti | Buona, con normalizzazione | Tabelle: già possibile oggi |
| 3 — Saldi (mai movimenti) | Saldi iniziali esercizio, debiti v/fornitori, morosità per soggetto/immobile, fondi | Solo come scrittura di apertura | Stato Patrimoniale (v1.10) |

> **Nota tabelle millesimali**: importabili già oggi. Il tipo `manuale` accetta qualsiasi
> insieme di numeri relativi e calcola le proporzioni via `valore / sommaValori`. Si ingeriscono
> i millesimi altrui *as-is*, senza pretendere normalizzazione in ingresso. **Tabelle parziali**
> (NaN/assenza per alcune unità, confermato in Giada — es. tabella `idraulico`) = esclusione
> dall'allocazione per quella tabella, non errore di validazione.

---

## 5. Architettura: driver su formato canonico

Il cuore del modulo è un pattern **driver/adapter su formato canonico**.

- Si definisce una **rappresentazione interna unica** (canonica) di ogni entità.
- Ogni gestionale di origine ha un **driver** che traduce il suo export nel formato canonico.
- Il **template Excel** compilabile a mano è la serializzazione human-facing dello stesso
  formato canonico.

### Principio non negoziabile

**Il formato canonico si progetta a partire dal dominio di Kondomanager, mai dall'export di
Danea o di altri.** Le sorgenti si adattano al canonico, non viceversa.

### La pipeline converge

```
Export Danea  ──┬─ [DaneaDriver]  ──┐
Export Giada  ──┼─ [GiadaDriver]  ──┤
Export altro  ──┼─ [AltroDriver]  ──┤
Template man. ──┴─ [ManualDriver] ──┴──> DTO canonici ──> Validator ──> Preview/diff ──> Committer
```

### Interfaccia driver (bozza)

```php
interface ImportDriver
{
    public function supports(ImportFile $file): bool;
    public function extract(ImportFile $file): CanonicalDataset;
    public function key(): string;
}
```

---

## 6. La pipeline condivisa

1. **Estrazione** (driver) → `CanonicalDataset`
2. **Validazione** — source-agnostic, opera sui DTO canonici
3. **Preview / diff** — mostra cosa verrà creato, warning, richiede conferma
4. **Commit** — in transazione DB unica, ogni record taggato con `import_batch_id`
5. **Rollback** — eliminando per `import_batch_id`

### Requisiti trasversali
- Dry-run first: nessun commit senza preview confermata
- Idempotenza: ri-eseguire lo stesso batch non duplica
- `import_batch_id` su ogni entità creata
- Fail-fast con log diagnostici

---

## 7. Formato canonico — Fase 1: Soggetto / Immobile / Titolarità

### 7.1 `CanonicalSoggetto`

| Campo | Tipo | Note |
|-------|------|------|
| `tipo` | enum | `persona_fisica` \| `persona_giuridica` |
| `nome` | string\|null | obbligatorio per persona fisica |
| `cognome` | string\|null | obbligatorio per persona fisica |
| `ragione_sociale` | string\|null | obbligatorio per persona giuridica |
| `codice_fiscale` | string\|null | validato se presente; warning se mancante |
| `partita_iva` | string\|null | |
| `email` | string\|null | |
| `pec` | string\|null | |
| `telefono` | string\|null | |
| `indirizzo_residenza` | string\|null | |
| `data_nascita` | date\|null | abilita alert maggiore età |
| `ref_esterno` | string\|null | chiave originale nel gestionale di partenza |

> **Da analisi reali:** Danea non espone CF nell'export. Giada lo espone su `giada_unita.xls`
> campo `CodFisc`. In entrambi i casi i nomi sono in un'unica stringa — il driver deve
> tentare split `Cognome Nome` (formato comune nei gestionali italiani). De-dup per CF +
> `ref_esterno` per evitare soggetti doppi quando lo stesso proprietario ha più unità
> (confermato: `TACCALA MARAA RASA` appare per int. 3 e int. 4 in Danea; stessa logica in Giada).

### 7.2 `CanonicalImmobile`

| Campo | Tipo | Note |
|-------|------|------|
| `condominio_ref` | string | riferimento al condominio |
| `denominazione` | string | es. "Interno 3", "Box 12" |
| `scala` | string\|null | |
| `palazzina` | string\|null | confermato in entrambi i gestionali |
| `piano` | string\|null | presente in Giada; assente in Danea |
| `interno` | string\|null | presente in Giada; assente in Danea |
| `vani` | decimal\|null | |
| `in_bilancio` | bool | default true |
| `dati_catastali` | object\|null | |
| `ref_esterno` | string\|null | es. `1-3` (palazzina-progressivo) |

### 7.3 `CanonicalTitolarita`

| Campo | Tipo | Note |
|-------|------|------|
| `soggetto_ref` | string | |
| `immobile_ref` | string | |
| `ruolo` | enum | `proprietario` \| `inquilino` \| `usufruttuario` \| `nudo_proprietario` |
| `quota_bilancio` | decimal\|null | |
| `saldo_iniziale` | decimal\|null | **nuovo** — fonte diretta per Opzione A |
| `valid_from` | date\|null | |
| `valid_to` | date\|null | |

> **`saldo_iniziale` aggiunto** rispetto alla versione precedente: entrambi i gestionali
> espongono il saldo per soggetto/unità direttamente. In Danea: `rate_versate.xls` (saldo
> versato, da cui ricavare il residuo). In Giada: campo `Saldo prec` su `giada_unita.xls`
> (saldo diretto per soggetto) e `Saldo finale` su `giara_riparto_consuntivo_24_25.xls`
> (più preciso, include il riparto dell'esercizio appena chiuso).

---

## 8. Validazione

- **Soggetto**: CF formalmente valido se presente (warning se mancante, non blocco); de-dup su CF + `ref_esterno`.
- **Immobile**: `condominio_ref` risolvibile; `palazzina` + `progressivo` come chiave.
- **Titolarità**: somma `quota_bilancio` per immobile = 100% (warning se diverso).
- **Tabelle**: ogni immobile presente; NaN/assenza = esclusione (non errore); somme a 1000 solo se tipo non `manuale`.
- **Esercizio**: `data_inizio`/`data_fine` liberi — non assumere solare. Confermato da Giada (nov–ott).
- **Fornitori** (Fase 2): CF/PIVA valido per regime.
- **Saldi**: quadratura DARE/AVERE della scrittura di apertura.

---

## 9. Scrittura di apertura e saldi iniziali

### Opzione A — Rata di apertura sintetica (MVP consigliato)

Genera una `rata` fittizia "Saldo esercizio precedente" per soggetto/unità. Riusa il motore
morosità esistente senza modifiche.

- **Pro**: zero modifiche al motore morosità, riuso totale.
- **Contro**: riga non deliberata nel piano rate.

### Opzione B — Posizione ledger pura

Scrittura di apertura su *Crediti v/Condòmini* per immobile.

- **Pro**: contabilmente più pulito.
- **Contro**: richiede modifiche alla logica morosità.

**Raccomandazione**: Opzione A come MVP; Opzione B con Credit Enforcer maturo (v1.10+).

---

## 10. Versioning dei template

- Campo `template_schema_version` nei template Excel e nei `CanonicalDataset`.
- La pipeline rifiuta o migra versioni non compatibili esplicitamente.

---

## 11. Sequencing nella roadmap

| Fetta | Pronta dopo |
|-------|-------------|
| Tabelle millesimali | **Già possibile** |
| Soggetti / immobili / titolarità | v1.10 |
| Fornitori | v1.12 |
| Saldi iniziali | MVP con v1.10; completo con v1.17 |

---

## 12. Driver Danea — Analisi export reali

> File ricevuti: `anagrafica.xls`, `Elenco_Attivita_.xls`, `Movimenti.xls`, `rate_versate.xls`

### Struttura file

| File | Contenuto | Livello import |
|------|-----------|----------------|
| `anagrafica.xls` | Unità + millesimali fusi in un unico file. Header riga 0, dati da riga 1. | L1 + L2 |
| `Movimenti.xls` | Movimenti contabili con righe principali + righe ritenuta alternate | NON importare |
| `rate_versate.xls` | Incassi da condòmini per esercizio | L3 (saldi) |
| `Elenco_Attivita_.xls` | Ticket/CRM: pratiche, guasti, richieste | Fuori scope |

### Mappatura `anagrafica.xls` → canonico

| Colonna Danea | Campo canonico | Note |
|---------------|----------------|------|
| `Palazzina` | `CanonicalImmobile.palazzina` | |
| `Progressivo` | chiave `ref_esterno` (es. `1-3`) | |
| `Proprietario` | `CanonicalSoggetto.nome`+`cognome` | Unica stringa — split necessario |
| `Proprietà` | `CanonicalTabella.millesimi` (tabella Proprietà) | |
| `Scala A palazz. 1` | `CanonicalTabella.millesimi` (tabella Scala A P1) | |
| `Scala A palazz. 2` | `CanonicalTabella.millesimi` (tabella Scala A P2) | |
| `Riscaldamento` | `CanonicalTabella.millesimi` (tabella Riscaldamento) | |

### Osservazioni critiche

- **Ruolo non esplicito**: Danea non espone il ruolo (Pr/Co/Us) nell'export anagrafica — si assume `proprietario` per default. Casi inquilino/usufruttuario richiedono integrazione manuale post-import.
- **CF assente**: nessun codice fiscale nell'export; de-dup solo per nome+cognome (fragile).
- **Tabelle millesimali fuse con anagrafiche**: il driver deve estrarre le due entità dallo stesso foglio.
- **Soggetto con più unità**: `TACCALA MARAA RASA` appare per int. 3 e int. 4 — de-dup per nome cognome obbligatorio.
- **Movimenti**: struttura a righe alternate (G1 = fattura, G61 = ritenuta correlata). Piano dei conti `Categoria / Sottovoce` non mappabile; utile solo per Smart Ledger Suggester post-v1.12.
- **`rate_versate.xls`**: anagrafica troncata con `(...)` — non affidabile come chiave di match; meglio usare il protocollo `Rxx` come `ref_esterno`. L'importo è il versato cumulativo (può coprire più rate).
- **Esercizio solare** (gen–dic): data header in `Movimenti.xls` e `rate_versate.xls`.

### Checklist analisi (completata)

- [x] Struttura saldi per condòmino → `rate_versate.xls`: importo versato per anagrafica (Opzione A)
- [x] Piano dei conti → `Categoria / Sottovoce` testo libero, non mappabile al ledger
- [x] Separazione soggetti/unità → **non separati**: una riga = un'unità, proprietario come stringa
- [x] Formato millesimi → interi per colonna nello stesso foglio anagrafica
- [x] CF/PIVA → **assenti** nell'export; warning garantito in validazione
- [x] Esercizio → **solare** (jan–dic)

---

## 13. Driver Giada — Analisi export reali

> File ricevuti: `giada_unita.xls`, `giada_mill.xls`, `giada_consuntivo_24_25.xls`,
> `giara_riparto_consuntivo_24_25.xls`

### Struttura file

| File | Contenuto | Livello import |
|------|-----------|----------------|
| `giada_unita.xls` | Soggetti + unità + ruoli + saldi precedenti | L1 + L3 |
| `giada_mill.xls` | Tabelle millesimali separate | L2 |
| `giada_consuntivo_24_25.xls` | Consuntivo per conto | NON importare |
| `giara_riparto_consuntivo_24_25.xls` | Riparto per unità/soggetto con saldi finali | L3 (saldi più precisi) |

### Mappatura `giada_unita.xls` → canonico

| Colonna Giada | Campo canonico | Note |
|---------------|----------------|------|
| `Palazzina` | `CanonicalImmobile.palazzina` | |
| `Progressivo` | chiave `ref_esterno` | |
| `Piano` | `CanonicalImmobile.piano` | assente in Danea |
| `Interno` | `CanonicalImmobile.interno` | assente in Danea |
| `Tipo` | tipo unità | es. `Appartamento` |
| `Ruolo` | `CanonicalTitolarita.ruolo` | `Pr`→`proprietario`, `Co`→`inquilino`, `Us`→`usufruttuario` |
| `Saldo prec` | `CanonicalTitolarita.saldo_iniziale` | saldo precedente per soggetto |
| `Denominazione` | `CanonicalSoggetto.nome`+`cognome` | unica stringa, split necessario |
| `CodFisc` | `CanonicalSoggetto.codice_fiscale` | presente — migliore qualità rispetto a Danea |
| `Email` / `Tel1` | `CanonicalSoggetto.email` / `telefono` | |
| `Sub. cat.` | quota millesimale sintetica (informativa) | non tabella completa |

### Mappatura `giada_mill.xls` → canonico

Una riga per unità, una colonna per tabella. Valori come numeri decimali che sommano a 1000.
`NaN` = unità non partecipante a quella tabella (tabella parziale — non errore).

| Colonna Giada | Tabella canonico | Note |
|---------------|------------------|------|
| `AMMINISTRAZIONE` | Tabella "Amministrazione" | somma = 1000 |
| `ASSICURAZIONE` | Tabella "Assicurazione" | somma = 1000 |
| `MANUTENZIONE ORDINARIA` | Tabella "Manutenzione Ordinaria" | somma = 1000 |
| `ASCENSORE E SCALE` | Tabella "Ascensore e Scale" | somma = 1000 |
| `idraulico` | Tabella "Idraulico" | **parziale** — solo 3 unità su 15 |
| `Cassete postali` | Tabella tipo `manuale` a parti uguali | valore = 1 per ogni unità |

### Mappatura saldi da `giara_riparto_consuntivo_24_25.xls`

Il riparto consuntivo è la fonte più precisa per i saldi di fine esercizio:

| Colonna Giada | Uso import |
|---------------|-----------|
| `Saldo finale` | saldo da portare come apertura in Kondomanager |
| `Saldi di fine Es. prec.` | saldo dell'esercizio precedente (cross-check) |
| `Movimenti personali` | addebiti ad personam già avvenuti (non importare come movimenti) |
| `mill.` (accanto a ogni voce) | millesimi usati per il riparto (cross-check con `giada_mill.xls`) |

### Osservazioni critiche

- **Ruoli espliciti e multipli per unità**: `Pr`, `Co`, `Us` — più vicino al `CanonicalTitolarita`. Il driver deve gestire N righe per lo stesso `Progressivo`.
- **Ruoli storici `ex`**: `ex Pr`, `ex Co`, `ex Us` con giorni proporzionali (`29 gg`, `336 gg`) per subentri mid-anno. **Non importare** — solo stato corrente.
- **CF disponibile**: qualità superiore a Danea; de-dup affidabile.
- **Tabelle parziali confermate**: `idraulico` con NaN per la maggior parte delle unità.
- **Esercizio non solare**: `2024/2025` (nov–ott). Il canonico non deve assumere gen–dic.
- **`Cassete postali`** = parti uguali (valore 1): schema tipo `manuale` confermato in uso reale.
- **Consuntivo**: stesso pattern piano dei conti `Categoria / Sottovoce` di Danea — ulteriore conferma del pattern comune nei gestionali italiani (utile per Smart Ledger Suggester).

### Checklist analisi (completata)

- [x] Struttura saldi per soggetto → `Saldo prec` in `giada_unita.xls` (diretto); `Saldo finale` in riparto (più preciso)
- [x] Piano dei conti → `Categoria / Sottovoce` testo libero, stesso pattern di Danea
- [x] Separazione soggetti/unità → **parziale**: file unità ha più righe per progressivo con ruolo esplicito
- [x] Millesimi → file separato; tabelle parziali con NaN gestite come esclusione
- [x] CF/PIVA → **presente** in `giada_unita.xls` campo `CodFisc`
- [x] Esercizio → **non solare** (nov–ott); canonico deve accettare date libere

---

## 14. Confronto Danea vs Giada

| Aspetto | Danea | Giada |
|---------|-------|-------|
| Unità + millesimi | File unico fuso | File separati |
| Ruoli soggetti | Assente nell'export (solo proprietario) | `Pr`, `Co`, `Us` espliciti |
| Codice fiscale | Assente | Presente su `giada_unita.xls` |
| Saldo iniziale | Da `rate_versate` per differenza | `Saldo prec` diretto per soggetto |
| Saldo preciso | Non disponibile direttamente | `Saldo finale` nel riparto consuntivo |
| Tabelle parziali | Non osservato | Confermato (NaN = non partecipa) |
| Esercizio | Solare (gen–dic) | Non solare (nov–ott) |
| Piano dei conti | `Categoria / Sottovoce` testo | `Categoria / Sottovoce` testo (identico) |
| Attività/CRM | `Elenco_Attivita_.xls` | Non presente nell'export |

### Implicazioni architetturali cross-gestionale

1. **Due driver distinti necessari** — struttura dei file completamente diversa.
2. **Il canonico regge entrambi** senza modifiche — conferma che il design è corretto.
3. **`saldo_iniziale` su `CanonicalTitolarita`** — aggiunto dopo analisi reali; entrambi i gestionali lo espongono in forme diverse ma mappabili.
4. **Tabelle parziali** — NaN/assenza = esclusione, non errore; da gestire nel validator e nel Committer.
5. **Esercizio non solare** — `data_inizio`/`data_fine` liberi nel canonico, mai assumere gen–dic.
6. **Nome+cognome come unica stringa** — pattern comune; split `Cognome Nome` tentato dal driver, con fallback a `denominazione` unica.
7. **Piano dei conti `Categoria / Sottovoce`** — pattern identico in entrambi i gestionali; prezioso per Smart Ledger Suggester (post-v1.12) indipendentemente dall'import.

---

## 15. Prossimi passi

1. Validare il canonico Fase 1 (§7) contro lo schema reale Anagrafica Immobiliare v1.10.
2. Aggiungere `saldo_iniziale` a `CanonicalTitolarita` e gestirlo nella pipeline.
3. Implementare `ImportDriver` + `ManualTemplateDriver` + template Excel.
4. Implementare Validator (§8) con gestione tabelle parziali.
5. Implementare Committer transazionale con `import_batch_id`.
6. Test Pest end-to-end (SQLite in-memory).
7. `DaneaDriver` — prima iterazione su `anagrafica.xls` (L1+L2, senza saldi).
8. `GiadaDriver` — più completo di Danea: L1+L2+L3 da tre file distinti.