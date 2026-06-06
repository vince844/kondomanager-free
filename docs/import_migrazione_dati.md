# Import / Migrazione Dati — Guida Architetturale

> Documento di lavoro interno. Definisce l'architettura del modulo di importazione dati
> da altri gestionali condominiali verso Kondomanager. Le interfacce e gli schemi qui
> proposti sono **bozze da validare contro il codice reale** prima dell'implementazione.

---

## 1. Obiettivo

Ridurre l'attrito principale all'adozione di Kondomanager: il reinserimento manuale dei
dati quando un amministratore migra da un altro gestionale. L'obiettivo non è replicare lo
storico contabile altrui, ma portare in Kondomanager le informazioni necessarie a **iniziare
un nuovo esercizio già popolato** di anagrafiche, unità, tabelle, fornitori e saldi di apertura.

## 2. Principio fondante

I dati **puramente contabili (le scritture/movimenti) non si importano.** Le differenze
strutturali tra gestionali (piani dei conti diversi, netting diverso, gestione della
competenza diversa) rendono impossibile e pericoloso ricostruire la partita doppia altrui
dentro `scritture_contabili`.

Il ponte tra vecchio e nuovo gestionale è il **saldo finale dell'esercizio precedente**, che
diventa il **saldo iniziale** del primo esercizio in Kondomanager, materializzato come
un'unica **scrittura di apertura in quadratura DARE/AVERE**.

Questo principio è perfettamente coerente con l'architettura ledger-centric: il giornale
resta verità immutabile, e l'import non lo viola — vi inserisce solo un punto di partenza pulito.

### Messaggio all'utente

> Chiudi l'esercizio col vecchio gestionale, parti pulito con Kondomanager. Ti porti dietro
> anagrafiche, unità, tabelle, fornitori e saldi di apertura — **non** la cronologia contabile.

## 3. Cosa NON importiamo (per scelta)

- Storico dei movimenti contabili / scritture.
- Storico pagamenti e incassi di esercizi chiusi.
- Riparti e rendiconti già emessi nel vecchio sistema.

Tentare la ricostruzione di questi dati comprometterebbe l'integrità del ledger. Si accetta
il saldo di apertura e si va avanti.

## 4. I tre livelli di importabilità

Il problema si decompone in tre livelli con grado di compatibilità strutturale decrescente.
Ognuno dipende da moduli diversi di Kondomanager e diventa disponibile man mano che quei
moduli maturano.

| Livello | Cosa | Compatibilità | Dipendenza |
|--------|------|----------------|------------|
| 1 — Master data puro | Condomìni, soggetti (anagrafiche), unità immobiliari, fornitori | Mappatura ~1:1 | Anagrafica Immobiliare (v1.10), Anagrafica Fornitore (v1.12) |
| 2 — Strutture semi-standardizzate | Tabelle millesimali, piano dei conti | Buona, con normalizzazione | Tabelle: già possibile oggi. Piano dei conti: punto più scomodo |
| 3 — Saldi (mai movimenti) | Saldi iniziali esercizio, debiti v/fornitori, morosità per immobile, fondi | Solo come scrittura di apertura | Stato Patrimoniale (v1.10), idealmente macchina apertura conti (v1.17) |

> **Nota tabelle millesimali**: importabili già oggi. Il tipo `manuale` accetta qualsiasi
> insieme di numeri relativi e calcola le proporzioni via `valore / sommaValori`. Si ingeriscono
> i millesimi altrui *as-is*, senza pretendere normalizzazione in ingresso.

## 5. Architettura: driver su formato canonico

Il cuore del modulo è un pattern **driver/adapter su formato canonico**.

- Si definisce una **rappresentazione interna unica** (canonica) di ogni entità.
- Ogni gestionale di origine ha un **driver** che traduce il suo export nel formato canonico.
- Il **template Excel** compilabile a mano è la serializzazione human-facing dello stesso
  formato canonico (l'approccio "incolla nei modelli" alla Danea).

### Principio non negoziabile

**Il formato canonico si progetta a partire dal dominio di Kondomanager, mai dall'export di
Danea o di altri.** Se si modella il canonico attorno a una sorgente, si ereditano i suoi
vincoli e ogni driver successivo diventa scomodo. Le sorgenti si adattano al canonico, non
viceversa.

> Conseguenza pratica: **non avere ancora il file di Danea è un vantaggio.** Costringe a
> disegnare il canonico dal dominio, incontaminato da una singola sorgente.

### La pipeline converge

Il percorso manuale e il percorso driver **convergono sulla stessa pipeline**. Il driver
sostituisce solo il primo passo. Tutto a valle è condiviso.

```
Export Danea  ──┬─ [DaneaDriver]  ──┐
Export altro  ──┼─ [AltroDriver]  ──┤
Template man. ──┴─ [ManualDriver] ──┴──> DTO canonici ──> Validator ──> Preview/diff ──> Committer (tx + import_batch_id)
```

Non devono esistere due codepath di import. Costruendo prima la pipeline generica +
`ManualTemplateDriver` end-to-end, ogni driver di sorgente diventa un'aggiunta piccola e
isolata: pura mappatura colonne → canonico, **zero accesso al DB**, zero rischio per il core.

### Interfaccia driver (bozza)

```php
interface ImportDriver
{
    /** Riconosce se può gestire il file fornito. */
    public function supports(ImportFile $file): bool;

    /** Estrae DTO canonici dall'export di origine. Nessun accesso al DB. */
    public function extract(ImportFile $file): CanonicalDataset;

    /** Identificativo del driver per logging/preview (es. 'danea', 'manual'). */
    public function key(): string;
}
```

- `ManualTemplateDriver` legge il template canonico 1:1.
- `DaneaDriver` (futuro) legge l'export Danea e mappa. Granularità per entità (sub-handler per
  Unità, Tabelle, Fornitori), dato che Danea esporta modelli separati per tipo.
- I driver sono intercambiabili dal punto di vista della pipeline.

## 6. La pipeline condivisa

1. **Estrazione** (driver) → `CanonicalDataset` (collezioni di DTO per entità).
2. **Validazione** — source-agnostic, opera sui DTO canonici. È qui che sta il lavoro vero.
3. **Preview / diff** — mostra cosa verrà creato, evidenzia warning (CF mancante, somme non
   quadrate, ecc.), richiede conferma esplicita.
4. **Commit** — in transazione DB unica, ogni record taggato con `import_batch_id`.
5. **Rollback** — possibile finché l'esercizio non è operativo, eliminando per `import_batch_id`.

### Requisiti trasversali

- **Dry-run first**: nessun commit senza preview confermata.
- **Idempotenza**: ri-eseguire l'import dello stesso batch non duplica.
- **`import_batch_id`** su ogni entità creata, per rollback pulito.
- **Fail-fast** con log diagnostici sui punti di uscita e reconciliation log dei delta.

## 7. Formato canonico — Fase 1: Soggetto / Immobile / Titolarità

Danea esporta "anagrafiche e unità immobiliari" insieme, ma il canonico le tiene **separate
e pulite** in tre entità. Questo è il livello più semplice e quello costruibile già con v1.10.

### 7.1 `CanonicalSoggetto` (anagrafica)

Master data della persona/ente. Nessun ruolo, nessuna quota qui (vivono nella titolarità).

| Campo | Tipo | Note |
|------|------|------|
| `tipo` | enum | `persona_fisica` \| `persona_giuridica` |
| `nome` | string\|null | obbligatorio per persona fisica |
| `cognome` | string\|null | obbligatorio per persona fisica |
| `ragione_sociale` | string\|null | obbligatorio per persona giuridica |
| `codice_fiscale` | string\|null | validato se presente; warning se mancante |
| `partita_iva` | string\|null | |
| `email` | string\|null | |
| `pec` | string\|null | |
| `telefono` | string\|null | |
| `indirizzo_residenza` | string\|null | residenza / sede legale |
| `data_nascita` | date\|null | abilita alert maggiore età |
| `ref_esterno` | string\|null | chiave originale nel gestionale di partenza, per de-dup |

### 7.2 `CanonicalImmobile` (unità immobiliare)

Master data della proprietà. Allineato ai campi Anagrafica Immobiliare (v1.10.1).

| Campo | Tipo | Note |
|------|------|------|
| `condominio_ref` | string | riferimento al condominio (codice o nome) |
| `denominazione` | string | es. "Interno 3", "Box 12" |
| `scala` | string\|null | per filtro scope / art. 1124 |
| `palazzina` | string\|null | |
| `vani` | decimal\|null | decimale |
| `in_bilancio` | bool | default true; false per minori/non titolari |
| `dati_catastali` | object\|null | foglio, particella, subalterno, categoria, ecc. |
| `ref_esterno` | string\|null | chiave originale, per de-dup |

> Estensione futura (Iniziativa B Fase 1): `forniture_immobile` con POD/PDR, matricole
> contatori, `codice_cliente`, `intestatario_id`, `valid_from`/`valid_to`. Fuori scope Fase 1
> dell'import.

### 7.3 `CanonicalTitolarita` (link soggetto ↔ immobile)

Il legame che porta ruolo e quota. Coerente col principio: **`quota_bilancio` segue
l'immobile, non la persona**; gli split per ruolo vivono qui / sulle tabelle di riparto, non
sull'anagrafica.

| Campo | Tipo | Note |
|------|------|------|
| `soggetto_ref` | string | riferimento al `CanonicalSoggetto` |
| `immobile_ref` | string | riferimento al `CanonicalImmobile` |
| `ruolo` | enum | `proprietario` \| `inquilino` \| `usufruttuario` \| `nudo_proprietario` |
| `quota_bilancio` | decimal\|null | % di bilancio per quel soggetto su quell'immobile |
| `valid_from` | date\|null | per storico subentri |
| `valid_to` | date\|null | |

> Vincolo di coerenza: la somma di `%Bilancio` per immobile deve fare **100%**, coprendo
> tutti i titolari di godimento. I minori compaiono con `in_bilancio = false`.

## 8. Validazione (regole per entità, source-agnostic)

- **Soggetto**: CF formalmente valido se presente (altrimenti warning, non blocco);
  coerenza nome/cognome vs ragione sociale rispetto a `tipo`; de-dup su `codice_fiscale` +
  `ref_esterno`.
- **Immobile**: `condominio_ref` risolvibile; coerenza catastale di base; `vani` numerico.
- **Titolarità**: `soggetto_ref` e `immobile_ref` risolvibili; somma `%Bilancio` per immobile
  = 100% (warning se diverso, con possibilità di ribilanciamento guidato).
- **Tabelle**: ogni immobile presente; somme coerenti — o auto-normalizzate via tipo `manuale`.
- **Fornitori** (Fase 2): CF/PIVA valido per regime (aggancio a DNA Fiscale v1.12).
- **Saldi**: quadratura DARE/AVERE della scrittura di apertura; i saldi per immobile
  riconciliano col totale.

## 9. Scrittura di apertura e saldi iniziali (decisione aperta)

La scrittura di apertura è semplice per banca, cassa, debiti v/fornitori e fondi: una
`scrittura_contabile` datata all'inizio del primo esercizio, con `esercizio_id` e
`gestione_id` del nuovo esercizio, in quadratura DARE/AVERE.

Il nodo è la **morosità per condòmino**: in Kondomanager non è un saldo memorizzato, è
*calcolata* da `rate_quote`/piano rate vs pagamenti. Importare un saldo iniziale per immobile
richiede un meccanismo che il motore morosità riconosca **senza** un piano rate alle spalle.

### Opzione A — Rata di apertura sintetica (MVP consigliato)

Si genera una `rata` fittizia "Saldo esercizio precedente" per immobile. La morosità rientra
naturalmente nel motore esistente e nel Credit Enforcer / Wizard Solleciti.

- **Pro**: riusa tutto l'esistente, zero modifiche al motore morosità.
- **Contro**: introduce una riga non deliberata nel piano rate.

### Opzione B — Posizione ledger pura

Scrittura di apertura su *Crediti v/Condòmini* per immobile; il motore morosità impara a
leggere anche questa fonte.

- **Pro**: contabilmente più pulito.
- **Contro**: richiede di toccare la logica di calcolo morosità.

**Raccomandazione**: Opzione A come MVP; Opzione B quando il Credit Enforcer (v1.10) sarà
maturo. Decisione finale da validare contro il codice del motore morosità.

## 10. Versioning dei template

Lo schema canonico evolverà. Si versiona:

- Un campo `template_schema_version` nei template Excel e nei `CanonicalDataset`.
- Il percorso manuale e tutti i driver devono dichiarare/tracciare la versione che producono.
- La pipeline rifiuta (o migra) versioni non compatibili in modo esplicito.

## 11. Sequencing nella roadmap

Non costruire un modulo Import monolitico. Decomporre per livello e rilasciare ogni fetta
quando l'entità sottostante matura.

| Fetta | Pronta dopo |
|------|-------------|
| Tabelle millesimali | **Già possibile** (CalcoloQuoteService + tipo `manuale`) |
| Soggetti / immobili / titolarità | v1.10 (Anagrafica Immobiliare + Stato Patrimoniale) |
| Fornitori | v1.12 (DNA Fiscale — per validare regime/CF/PIVA in ingresso) |
| Saldi iniziali / scrittura di apertura | MVP con v1.10; versione completa con v1.17 |

> La pipeline generica nasce attorno al caso più semplice (Soggetto/Immobile/Titolarità)
> prima di affrontare i saldi.

## 12. Driver Danea (rinviato)

Da costruire quando sarà disponibile un export Excel reale. Checklist di analisi, quando arriva:

- [ ] Come Danea struttura l'export dei **saldi per condòmino** (è il punto critico di mappatura).
- [ ] Come struttura il **piano dei conti** (secondo punto critico).
- [ ] Separazione/aggregazione di soggetti e unità nei suoi modelli.
- [ ] Formato dei millesimi e dei tipi di tabella.
- [ ] Codifica CF/PIVA e gestione campi mancanti.

Il `DaneaDriver` mapperà *dentro* il canonico esistente; non si modifica il canonico per Danea.

## 13. Prossimi passi

1. **Validare il canonico Fase 1** (Soggetto / Immobile / Titolarità) contro lo schema reale
   delle migrazioni Anagrafica Immobiliare v1.10.
2. Definire i **DTO canonici** come value object immutabili.
3. Implementare `ImportDriver` + `ManualTemplateDriver` + generazione dei **template Excel**.
4. Implementare **Validator** (regole §8) e **Preview/diff**.
5. Implementare **Committer** transazionale con `import_batch_id` + rollback.
6. Test Pest end-to-end sul percorso manuale (helper condivisi, SQLite in-memory).
7. Solo dopo: prima fetta saldi (Opzione A) e, in futuro, `DaneaDriver`.