# Import / Migrazione Dati — Guida Architetturale

> Documento di lavoro interno. Definisce l'architettura del modulo di importazione dati
> da altri gestionali condominiali verso Kondomanager. Le interfacce e gli schemi qui
> proposti sono **bozze da validare contro il codice reale** prima dell'implementazione.
>
> **Modello driver:** un driver per sorgente. Per uno stesso gestionale, un singolo driver può
> dover gestire più *tipi di report* con layout differenti (vedi §12 per Danea). Le sorgenti si
> adattano sempre al formato canonico, mai viceversa. Danea è la prima sorgente concreta, non
> un caso speciale.

---

## 1. Obiettivo

Ridurre l'attrito principale all'adozione di Kondomanager: il reinserimento manuale dei dati
quando un amministratore migra da un altro gestionale. L'obiettivo non è replicare lo storico
contabile altrui, ma portare in Kondomanager le informazioni necessarie a **iniziare un nuovo
esercizio già popolato** di anagrafiche, unità, tabelle, fornitori e saldi di apertura.

### Messaggio all'utente

> Chiudi l'esercizio col vecchio gestionale, parti pulito con Kondomanager. Ti porti dietro
> anagrafiche, unità, tabelle, fornitori e saldi di apertura — **non** la cronologia contabile.

---

## 2. Principio fondante

I dati **puramente contabili (scritture/movimenti) non si importano.** Le differenze
strutturali tra gestionali (piani dei conti diversi, netting diverso, competenza diversa)
rendono impossibile e pericoloso ricostruire la partita doppia altrui dentro
`scritture_contabili`.

Il ponte tra vecchio e nuovo gestionale è il **saldo finale dell'esercizio precedente**, che
diventa il **saldo iniziale** del primo esercizio in Kondomanager, materializzato come un'unica
**scrittura di apertura in quadratura DARE/AVERE**.

I gestionali esistenti espongono i saldi per soggetto/unità in modo diretto e affidabile (es. un
saldo finale nel riparto consuntivo), mentre i movimenti sottostanti hanno strutture incompatibili
col nostro ledger.

---

## 3. Cosa NON importiamo (per scelta)

- Storico dei movimenti contabili / scritture.
- Storico pagamenti e incassi di esercizi chiusi (se non come fonte saldo aggregata).
- Riparti e rendiconti già emessi nel vecchio sistema (li usiamo solo per *leggere* il saldo).
- Bilanci consuntivi per conto.
- Ruoli storici (ex proprietario / ex inquilino / ex usufruttuario) — solo stato corrente.
- Attività/ticket operativi — storico operativo non contabile.

---

## 4. I tre livelli di importabilità

| Livello | Cosa | Compatibilità | Dipendenza |
|---------|------|----------------|------------|
| 1 — Master data puro | Condomìni, soggetti, unità immobiliari, fornitori | Mappatura ~1:1 | Anagrafica Immobiliare (v1.10), Anagrafica Fornitore (v1.12) |
| 2 — Strutture semi-standardizzate | Tabelle millesimali, piano dei conti | Buona, con normalizzazione | Tabelle: già possibile oggi |
| 3 — Saldi (mai movimenti) | Saldi iniziali esercizio, debiti v/fornitori, morosità per soggetto/immobile, fondi | Solo come scrittura di apertura | Stato Patrimoniale (v1.10) |

> **Nota tabelle millesimali**: importabili già oggi. Il tipo `manuale` accetta qualsiasi
> insieme di numeri relativi e calcola le proporzioni via `valore / sommaValori`. Si
> ingeriscono i millesimi altrui *as-is*, senza pretendere normalizzazione in ingresso.
> **Tabelle parziali** (NaN/assenza per alcune unità — es. una tabella che riguarda solo poche
> unità) = esclusione dall'allocazione per quella tabella, non errore di validazione.

---

## 5. Architettura: driver su formato canonico

Il cuore del modulo è un pattern **driver/adapter su formato canonico**. Questo è ciò che
permette di "considerare tutto" senza riscrivere il nucleo a ogni nuovo gestionale.

- Si definisce una **rappresentazione interna unica** (canonica) di ogni entità.
- Ogni *sorgente* ha un **driver** che traduce il suo export nel formato canonico.
- Il **template Excel** compilabile a mano è la serializzazione human-facing dello stesso
  formato canonico (driver manuale).

### Principio non negoziabile

**Il formato canonico si progetta a partire dal dominio di Kondomanager, mai dall'export di
Danea o di altri.** Le sorgenti si adattano al canonico, non viceversa.

### La pipeline converge

```
Bundle Danea     ──┬─ [DaneaDriver]    ──┐
Bundle Millesimo ──┼─ [MillesimoDriver] ──┤   (futuro)
Bundle Domustudio──┼─ [DomustudioDriver]──┤   (futuro)
Export generico  ──┼─ [GenericCsvDriver]──┤   (futuro)
Template manuale ──┴─ [ManualDriver]    ──┴──> DTO canonici ──> Validator ──> Preview/diff ──> Committer
```

### Due lezioni dal formato reale, riflesse nell'interfaccia

1. **Un singolo gestionale può richiedere più parser interni.** Danea espone più report con
   layout diversi (vedi §12). Quindi il driver non lavora su *un* file, ma su un **bundle** di
   file, e classifica internamente ciascun file per report-type prima di fondere tutto in un
   unico `CanonicalDataset`. Lo stesso pattern vale per i gestionali futuri.
2. **L'header non è sempre a riga 0.** Alcuni report hanno righe di intestazione/banner prima
   dei dati. Il driver deve **localizzare la riga header dinamicamente** cercando un quorum di
   etichette note, mai assumere riga 0.

### Interfaccia driver (bozza)

```php
interface ImportDriver
{
    public function key(): string;                          // 'danea', 'millesimo', 'manual', ...
    public function supports(ImportBundle $bundle): bool;    // riconosce almeno un file gestibile
    public function extract(ImportBundle $bundle): CanonicalDataset; // classifica report-type e fonde
}

// Un bundle è semplicemente l'insieme dei file caricati in una sessione di import.
final class ImportBundle
{
    /** @var ImportFile[] */
    public array $files;
}
```

All'interno di un driver, ogni report-type ha un sub-parser:

```php
interface ReportParser
{
    public function detect(ImportFile $file): bool;             // banner title o firma header
    public function parse(ImportFile $file): CanonicalFragment; // contributo parziale al dataset
}
```

Il driver esegue i `ReportParser` sui file del bundle, raccoglie i `CanonicalFragment` e li
**fonde** per chiave (`ref_esterno` / CF) in un unico `CanonicalDataset`.

---

## 6. La pipeline condivisa

1. **Estrazione** (driver) → `CanonicalDataset`
2. **Validazione** — source-agnostic, opera sui DTO canonici
3. **Preview / diff** — mostra cosa verrà creato, warning, richiede conferma esplicita (§15)
4. **Commit** — in transazione DB unica, ogni record taggato con `import_batch_id`
5. **Rollback** — eliminando per `import_batch_id`

### Requisiti trasversali
- **Dry-run first**: nessun commit senza preview confermata. È il paracadute che assorbe
  l'incertezza dei formati e l'imperfezione dei dati — vale per *ogni* driver.
- Idempotenza: ri-eseguire lo stesso batch non duplica.
- `import_batch_id` su ogni entità creata.
- Fail-fast con log diagnostici.

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

> **CF — dipende dal report, non dal gestionale.** Alcuni report compatti non espongono il CF;
> altri report dello stesso gestionale lo espongono. Quindi: de-dup affidabile per CF quando il
> report lo contiene, fallback su `Cognome Nome` + `ref_esterno` quando manca. In entrambi i casi
> i nomi spesso arrivano in un'unica stringa — il driver tenta lo split `Cognome Nome`, con
> fallback a `denominazione` unica. De-dup tipico: quando lo stesso proprietario possiede più
> unità, compare su più righe con lo stesso CF → un solo soggetto, più titolarità.

### 7.2 `CanonicalImmobile`

| Campo | Tipo | Note |
|-------|------|------|
| `condominio_ref` | string | riferimento al condominio |
| `denominazione` | string | es. "Interno 3", "Box 12" |
| `palazzina` | string\|null | tipicamente presente |
| `gruppo` | string\|null | presente (lettera o numero) — entra nella chiave |
| `scala` | string\|null | |
| `piano` | string\|null | presente solo in alcuni report |
| `interno` | string\|null | presente solo in alcuni report |
| `tipo_unita` | string\|null | es. `Appartamento` |
| `vani` | decimal\|null | |
| `in_bilancio` | bool | default true |
| `dati_catastali` | object\|null | |
| `ref_esterno` | string\|null | chiave composita `palazzina-gruppo-progressivo` (es. `1-A-3`) |

### 7.3 `CanonicalTitolarita`

| Campo | Tipo | Note |
|-------|------|------|
| `soggetto_ref` | string | |
| `immobile_ref` | string | |
| `ruolo` | enum | `proprietario` \| `inquilino` \| `usufruttuario` \| `nudo_proprietario` |
| `quota_bilancio` | decimal\|null | |
| `saldo_iniziale` | decimal\|null | fonte diretta per Opzione A (vedi §9) |
| `valid_from` | date\|null | |
| `valid_to` | date\|null | |

> **Ruolo — dipende dal report.** I report compatti spesso non lo espongono (si assume
> `proprietario`). I report di dettaglio espongono il ruolo (proprietario / inquilino /
> usufruttuario) con **più righe per la stessa unità** (es. proprietario + inquilino). Il driver
> deve gestire N titolarità per `ref_esterno`. I ruoli storici (subentri infra-annuali) **non**
> si importano: solo stato corrente.

---

## 8. Validazione (source-agnostic)

- **Soggetto**: CF formalmente valido se presente (warning se mancante, non blocco); de-dup su CF + `ref_esterno`.
- **Immobile**: `condominio_ref` risolvibile; chiave `palazzina-gruppo-progressivo`.
- **Titolarità**: somma `quota_bilancio` per immobile = 100% (warning se diverso).
- **Tabelle**: ogni immobile presente; NaN/assenza = esclusione (non errore); somme a 1000 solo se tipo non `manuale`.
- **Esercizio**: `data_inizio`/`data_fine` liberi — **mai** assumere anno solare (esistono esercizi sfalsati, es. nov–ott).
- **Fornitori** (Fase 2): CF/PIVA valido per regime.
- **Saldi**: quadratura DARE/AVERE della scrittura di apertura.

---

## 9. Scrittura di apertura e saldi iniziali

### Convenzione di segno

Nel riparto consuntivo tipico vale la relazione:

```
Saldo finale = Saldi di fine Es. prec. + Totale gestione + Rate versate
Totale gestione = somma voci di riparto + Movimenti personali
```

Esempio (valori illustrativi): `-800 (Totale gestione) + 0 (Saldi prec) + 950 (Rate versate) = 150 (Saldo finale)`.

**`Saldo finale` è il valore da importare come saldo di apertura.** Segno: **negativo = a
debito** (il condòmino deve), **positivo = a credito** (ha versato in più).

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

## 12. Driver Danea — un vendor, più report-type

Danea espone i dati attraverso **più report con layout differenti**. La variazione rilevante per
l'import non è tra gestionali, ma tra *tipi di report dello stesso gestionale*: alcuni report sono
compatti (poche colonne, niente CF né ruolo), altri sono di dettaglio (CF, ruoli, saldi). Un
singolo `DaneaDriver` li riconosce e li parsa tutti.

### 12.1 I report-type rilevanti

| Report-type (key) | Esempio nome file | Banner | Header riga | Livello | Import |
|---|---|---|---|---|---|
| `anagrafica_millesimi` | `anagrafica.xls`, `millesimi.xls` | No | 0 | L1 + L2 | Sì (fallback) |
| `elenco_unita` | `elenco_unita.xls` | No | 0 | L1 + L3 | **Sì (preferito)** per soggetti/unità/titolarità |
| `riparto_consuntivo` | `riparto_consuntivo.xls` | Sì | dinamica | L3 | **Sì** — saldi più precisi |
| `rate_versate` | `rate_versate.xls` | Sì | dinamica | L3 (opz.) | Opzionale / fallback saldo |
| `movimenti` | `movimenti.xls` | Sì | dinamica | — | **No** (solo Smart Ledger Suggester, post-v1.12) |
| `bilancio_consuntivo` | `bilancio_consuntivo.xls` | Sì | dinamica | — | **No** |
| `elenco_attivita` | `elenco_attivita.xls` | Sì | dinamica | — | **No** (fuori scope) |

Punto chiave: il report compatto `anagrafica_millesimi` ha **layout stabile** tra condomìni
diversi — è la prova che si tratta di un formato fisso del gestionale e non di versioni diverse
del software. Ciò che cambia è *quale report* l'amministratore sceglie di esportare.

### 12.2 Detection del report-type

1. **Report con banner**: le prime righe contengono il titolo del report e una riga di
   intestazione del tipo `Condominio <Nome> - C. Fisc. <CF>` con periodo/esercizio. Il titolo
   identifica il report; la riga intestazione fornisce condominio e CF.
2. **Report senza banner**: si ispeziona l'header a riga 0.
   - presenza di `Ruolo` + un campo saldo + `CodFisc` → `elenco_unita`
   - presenza di un campo proprietario + colonne-tabella e **assenza** di `Ruolo` → `anagrafica_millesimi`
3. **Sempre**: localizzare la riga header cercando un quorum di etichette note (mai assumere riga 0).

### 12.3 Mappatura `anagrafica_millesimi` → canonico

| Colonna sorgente | Campo canonico | Note |
|------------------|----------------|------|
| `Palazzina` | `CanonicalImmobile.palazzina` | |
| `Gruppo` | `CanonicalImmobile.gruppo` | lettera o numero |
| `Progressivo` | parte di `ref_esterno` (`1-A-3`) | |
| `Proprietario` | `CanonicalSoggetto.nome`+`cognome` | unica stringa — split necessario |
| *(colonne tabella)* | `CanonicalTabella.millesimi` | una colonna per tabella (es. Amministrazione, Assicurazione, Scale e ascensore, Riscaldamento). Possibili tabelle parziali (valori solo su alcune unità) e tabelle a parti uguali (valore costante per ogni unità) |

> Nessun CF, nessun ruolo, nessun saldo in questo report. È il livello base.

### 12.4 Mappatura `elenco_unita` → canonico (report preferito)

| Colonna sorgente | Campo canonico | Note |
|------------------|----------------|------|
| `Palazzina` / `Gruppo unità` / `Progressivo` | chiave immobile | `ref_esterno` composito |
| `Piano` / `Interno` | `CanonicalImmobile.piano` / `interno` | assenti nel compatto |
| `Tipo` | `CanonicalImmobile.tipo_unita` | es. `Appartamento` |
| `Ruolo` | `CanonicalTitolarita.ruolo` | proprietario/inquilino/usufruttuario; N righe per progressivo |
| `Saldo prec` | `CanonicalTitolarita.saldo_iniziale` | saldo a inizio esercizio esportato |
| `Denominazione` | `CanonicalSoggetto.nome`+`cognome` | unica stringa, split |
| `CodFisc` | `CanonicalSoggetto.codice_fiscale` | **presente** — abilita de-dup affidabile |
| `Email` / `Tel` / `Indirizzo`/`CAP`/`Città`/`Prov` | campi soggetto | |
| `Note` | (ignorare o esporre in preview) | testo libero, talvolta info su ruoli/subentri |

### 12.5 Mappatura saldi da `riparto_consuntivo` (fonte saldi più precisa)

| Colonna sorgente | Uso import |
|------------------|-----------|
| `Saldo finale` | **saldo di apertura** da portare in Kondomanager |
| `Saldi di fine Es. prec.` | cross-check |
| `Rate versate` | componente del calcolo (non importare come movimenti) |
| `Movimenti personali` | addebiti ad personam già avvenuti (non importare come movimenti) |
| `mill.` (accanto a ogni voce) | millesimi usati nel riparto (cross-check con le tabelle) |

### 12.6 Report esclusi — note

- `movimenti`: righe alternate (riga fattura + riga ritenuta verso l'Erario). Conto in formato
  `Categoria / Sottovoce` testo libero, **non** mappabile al ledger. Utile solo per uno Smart
  Ledger Suggester futuro (post-v1.12), non per l'import.
- `rate_versate`: anagrafica spesso troncata, non affidabile come chiave di match; usare il
  protocollo del pagamento come `ref_esterno`. L'importo è il versato cumulativo. Resta come
  fallback saldo se l'amministratore non ha il riparto.
- `bilancio_consuntivo`, `elenco_attivita`: fuori scope.

---

## 13. Confronto tra i report-type Danea

| Aspetto | `anagrafica_millesimi` (compatto) | `elenco_unita` (dettaglio) | `riparto_consuntivo` |
|---------|-----------------------------------|----------------------------|----------------------|
| Anagrafica + millesimi | fusi | unità separate dai millesimi | n/a |
| Ruoli soggetti | assenti (solo proprietario) | espliciti | con storici |
| Codice fiscale | assente | **presente** | n/a |
| Saldo | assente | saldo precedente (apertura) | saldo finale (più preciso) |
| Piano/Interno | assenti | presenti | n/a |
| Banner di testata | no | no | sì |
| Header a riga | 0 | 0 | dinamica |

> Esercizio solare/non-solare e nomi in unica stringa sono trasversali a tutti i report (sono
> proprietà del condominio o del formato, non distinzioni tra report).

### Implicazioni architetturali

1. **Un solo driver, più sub-parser** — non un driver per formato.
2. **Il canonico regge tutti i report** senza modifiche — conferma che il design è corretto.
3. **Preferire i report ricchi**: `elenco_unita` (soggetti/unità/ruoli/CF/saldo prec) +
   `riparto_consuntivo` (saldo finale preciso). Il compatto è il fallback.
4. **Header dinamico** obbligatorio.
5. **Tabelle parziali** (NaN = non partecipa) e **parti uguali** gestite nel Validator e nel
   Committer.

---

## 14. Procedura di export consigliata per l'amministratore

Riduciamo l'ambiguità alla fonte: invece di indovinare, **diciamo all'amministratore quale
export produrre**. Trasforma il punto debole (formati diversi) in una procedura documentata.

**Per la migliore qualità (Danea):**

1. Esporta l'**Elenco unità** → soggetti, unità, ruoli, CF, saldo precedente.
2. Esporta il **Consuntivo ripartizioni per unità / anagrafica** dell'ultimo esercizio chiuso
   → saldi finali precisi + millesimi.
3. (Opzionale) Esporta le **tabelle millesimali** se non già nei report sopra.

**Fallback (se l'amministratore ha solo questo):** l'export **anagrafica + millesimi** compatto.
Import possibile ma di qualità inferiore (niente CF → de-dup per nome, niente ruolo →
si assume proprietario). La preview (§15) consente di correggere a mano.

**Quando non c'è un export usabile** (qualsiasi gestionale): usare il **template Excel manuale**
(§16), che è la serializzazione del canonico.

---

## 15. Schermata di preview / conferma (source-agnostic)

La preview è **obbligatoria** e identica per tutti i driver. È il punto in cui l'amministratore
vede *cosa* sta per importare e conferma manualmente prima del commit.

Deve mostrare, raggruppato per entità:

- **Condomìni** rilevati (nome, CF, indirizzo dall'intestazione).
- **Soggetti**: nuovi vs già esistenti (match per CF/nome), con CF mancanti evidenziati.
- **Unità**: con `palazzina-gruppo-progressivo`, piano/interno dove presenti.
- **Titolarità**: ruolo assegnato (e i casi in cui il ruolo è stato *assunto* `proprietario`
  perché assente nel report — da confermare).
- **Tabelle millesimali**: totale per tabella, tabelle parziali segnalate (non come errore),
  tabelle a parti uguali segnalate.
- **Saldi di apertura**: per soggetto/unità, con segno (debito/credito) e fonte (saldo finale
  vs saldo precedente vs rate versate).
- **Warning non bloccanti**: CF mancante, quota_bilancio ≠ 100%, nome non splittabile, possibile
  duplicato.

Azioni richieste prima del commit:

- Conferma/modifica dei campi assunti (ruolo, split nome, accorpamento duplicati).
- Conferma esplicita del numero di record che verranno creati.
- Solo a conferma avvenuta → commit in transazione unica con `import_batch_id`.

---

## 16. Estensibilità ad altri gestionali e al template manuale

Danea è il **primo** driver, non un caso speciale. Tutto ciò che precede è costruito perché
altri gestionali entrino **senza toccare canonico, pipeline e preview**.

- **Nuovo gestionale**: si implementa un nuovo `ImportDriver` con i suoi `ReportParser`. Se anche
  quel gestionale ha più formati di export, il pattern bundle + report-type (§5, §12) si applica
  identico.
- **Export generico CSV**: un `GenericCsvDriver` con mappatura colonne guidata dall'utente
  (l'utente associa le colonne del suo CSV ai campi canonici) — utile quando non vale la pena
  scrivere un driver dedicato.
- **Template Excel manuale** (`ManualDriver`): è la **serializzazione human-facing del canonico**
  ed è il fallback universale quando non esiste un export usabile, o per casi piccoli/limite.
  Doppio ruolo: file editabile a mano *e* formato canonico. Va versionato (`template_schema_version`).
- **File Excel "custom"** dell'amministratore: si ricconducono al template manuale (l'utente li
  rimappa) oppure, se ricorrenti, diventano un driver dedicato. La preview resta la rete di
  sicurezza in entrambi i casi.

Regola pratica: si scrive un driver dedicato solo quando si hanno **export reali in mano**
(Rule of Three: si astrae al terzo caso, non al primo). Finché non li hai, il template manuale
copre tutto.

---

## 17. Prossimi passi

1. Validare il canonico Fase 1 (§7) contro lo schema reale dell'Anagrafica Immobiliare v1.10.
2. Implementare `ImportBundle`, `ImportDriver`, `ReportParser` e il merge per chiave.
3. Implementare il **routine di header-detection dinamico** (cerca quorum di etichette note).
4. Implementare il Validator (§8) con gestione tabelle parziali e parti uguali.
5. Implementare il Committer transazionale con `import_batch_id` e la scrittura di apertura (§9).
6. Implementare la **schermata di preview/conferma** (§15) — prima ancora dei driver, perché è
   il contratto comune.
7. `DaneaDriver` — prima iterazione: `elenco_unita` + `riparto_consuntivo` (la combinazione a
   qualità più alta: L1 + L3 con CF, ruoli e saldi precisi).
8. `DaneaDriver` — seconda iterazione: `anagrafica_millesimi` come fallback (L1 + L2).
9. `ManualDriver` + template Excel versionato (§16).
10. Test Pest end-to-end (SQLite in-memory) su tutti i report-type e sul template manuale.
11. Documentare la "Procedura di export consigliata" (§14) nella guida utente.