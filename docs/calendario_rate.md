# Calendario delle rate — data di partenza, override manuale e modifica post-generazione

**Spec di implementazione — target v1.10 (Fase 1) / v1.10.1 (Fasi 2–3)**
rev 1 · 2026-07-29

> Documento gemello di [`v1.10_rateazione_origine.md`](v1.10_rateazione_origine.md).
> Quello governa **quanto** cade su ogni rata (percentuali per origine); questo governa
> **quando** cade. I due assi sono ortogonali e non si toccano: nessuna delle decisioni
> qui prese cambia un importo.
>
> Le strutture dati sono proposte e vanno confermate contro lo schema di produzione
> (sezione 10). I punti già verificati contro il codice reale sono annotati con il
> riferimento `file:riga`.

---

## 0. Punto di ripartenza — leggi solo questo

Sintesi operativa per riprendere il lavoro a freddo. Tutto il resto del documento è
motivazione e dettaglio: serve quando si implementa, non quando si decide *da dove*
ricominciare.

### Stato al 2026-07-29
Nessuna riga di codice scritta. Il documento è completo, le sei decisioni sono chiuse
(§10), la roadmap è aggiornata. Il lavoro è **pianificato, non iniziato**.

### I due fatti che reggono tutto lo spec
1. **La scadenza non entra nel giornale** — la scrittura di emissione usa
   `data_registrazione`/`data_competenza`, mai `data_scadenza` (§2). Ri-datare è
   ri-schedulare: **nessuno storno**, nemmeno su rate emesse.
2. **Il consumatore delle date è già un vettore** — `GenerateRateQuotesAction` riceve un
   array e ci itera (§3.2). Il calendario manuale non tocca il generatore di quote.

### Ordine di esecuzione

**① Bonifiche — 2 righe, nessuna dipendenza, farle per prime**

| Cosa | Dove | Perché ora |
|---|---|---|
| Togliere `start_time` dal check duplicati eventi | [`SyncScadenziarioWithPianoRate.php:148`](../app/Listeners/Gestionale/SyncScadenziarioWithPianoRate.php:148) | §7.3 — latente **solo** finché le date non cambiano. Diventa un bug attivo il giorno in cui si rilascia la Fase 3 |
| `?? 1` → rimuovere la chiave (il DB dichiara `default(5)`) | [`PianoRateCreatorService.php:38`](../app/Services/PianoRateCreatorService.php:38) | D1 — oggi il piano è persistito al giorno 1 e generato al giorno 5 |

**② Fase 1 — data prima scadenza → v1.10** (§5)
Colonna nullable `data_prima_scadenza`; helper `PianoRate::dataPartenzaCalendario()`;
sostituzione nei **tre** punti di §3.1; date picker in `PianiRateNew.vue` con il default
visibile nel placeholder.
*Trappole già identificate:* `giorno_scadenza` non deve sovrascrivere il giorno scelto
(§5.1.1), e la `rrule` va rigenerata, non solo la data di start (§5.1.2).

**③ Chiudere D2 — bloccante per la Fase 3**
`rate_quote.data_scadenza` è scritta e mai letta (§4.3). Decisione presa: sincronizzarla.
Va implementata **prima** di rendere le date modificabili, non dopo.

**④ Fasi 2 e 3 → v1.10.1** (§6, §7)
Calendario manuale (le date arrivano nel payload, D3) e riprogrammazione post-generazione.
Il pattern di propagazione **non va inventato**: `FatturaPassivaService` risolve già lo
stesso problema per le fatture — [riga 866](../app/Services/Gestionale/FatturaPassivaService.php:866),
filtro `is_completed = false` e log before/after inclusi.

### Se si ha tempo per una cosa sola
Le **due bonifiche di ①**. Sono indipendenti da tutto, valgono a prescindere dal fatto che
questo spec venga mai implementato, e una delle due è un bug che oggi non si vede solo per
fortuna.

---

## 1. Obiettivo e scope

Tre richieste indipendenti arrivate da tre amministratori diversi si rivelano, alla
lettura del codice, **tre facce dello stesso vincolo**: il calendario delle rate è
*derivato* e *immutabile*. Derivato, perché nasce da `gestione.data_inizio` senza che
l'amministratore possa dire da dove partire; immutabile, perché dopo la generazione non
esiste alcun percorso per cambiare una data.

| # | Richiesta utente | Cosa manca oggi |
|---|---|---|
| **R1** | *"Come imposto che la prima rata scade il 15 marzo? Di default mi prende l'inizio della gestione"* | Nessun campo "data prima scadenza". Il punto di partenza è hardcoded |
| **R2** | *"Vorrei creare una rateizzazione manuale, impostando la data di ogni rata"* | Il generatore è puramente ricorrenziale (RRULE): non accetta un vettore di date |
| **R3** | *"Vorrei modificare le date delle rate"* | Nessuna rotta di update tocca `rate.data_scadenza` dopo la generazione |

### In scope
- Campo `data_prima_scadenza` esplicito sul piano, con fallback all'attuale comportamento.
- Modalità **calendario manuale**: vettore di date arbitrarie al posto della ricorrenza.
- **Modifica post-generazione** delle date, con propagazione a scadenzario e read-model.
- Invarianti sul calendario, validazioni, suite Pest.
- Retrocompatibilità totale dei piani esistenti.

### Fuori scope — deferred
- **Scadenze differenziate per condòmino** (stessa rata, date diverse per soggetto).
  Il substrato esiste già a DB — `rate_quote.data_scadenza` è una colonna per-quota
  (§4.3) — ma la semantica "una rata, una scadenza" attraversa scadenzario, solleciti e
  stampe. → non prima della v1.11.
- **Piani di rientro per morosi** (calendario individuale su credito già emesso) → v1.11
  (Recupero Crediti), dove il tema nasce naturalmente.
- **Ricalcolo automatico delle date allo slittamento di una gestione**: se cambia
  `gestione.data_inizio`, oggi i piani già generati non si toccano e questo spec non lo
  cambia (vedi D5).

---

## 2. Principio architetturale — la scadenza non è un fatto contabile

È il perno di tutto il documento, ed è **verificato sul codice**, non assunto.

La scrittura di emissione di una rata
([`EmissioneRateController.php:94-104`](../app/Http/Controllers/Gestionale/PianiRate/EmissioneRateController.php:94))
è costruita così:

```php
'data_registrazione' => now(),
'data_competenza'    => $request->data_emissione,
```

`data_scadenza` **non compare nella scrittura**, né in testata né sulle righe. Il
giornale conosce la data in cui la rata è stata emessa e quella di competenza; la data
di scadenza vive esclusivamente su `rate.data_scadenza`.

Conseguenza diretta, e risposta alla domanda di design centrale:

> **Modificare una data di scadenza non richiede storno, nemmeno su una rata già emessa.**
> Non c'è nulla da stornare: il credito verso il condòmino è nato con l'emissione e non
> cambia di un centesimo se la scadenza si sposta di un mese.

È esattamente la distinzione che [`v1.10_rateazione_origine.md`](v1.10_rateazione_origine.md) §2
traccia tra origini *contabilizzanti* e origini *di sola pianificazione*: la data è
**pura pianificazione**. Ri-datare una rata è *ri-schedulare*, non invertire il giornale.

Questo **non** significa che l'operazione sia priva di effetti. Ne ha, e sono tutti
extra-contabili: promemoria nello scadenzario, eventi visibili al condòmino, previsione
di liquidità, calcolo della morosità. Sono questi — non il ledger — a dettare le regole
di §7.

---

## 3. Stato attuale — cosa fa il codice oggi

### 3.1 La data di partenza

`gestione.data_inizio` è il punto di partenza, cablato in **tre punti**:

| File | Riga | Uso |
|---|---|---|
| [`PianoRateCreatorService.php`](../app/Services/PianoRateCreatorService.php:47) | 47 | `$start` della RRULE salvata in `ricorrenze_rate` |
| [`GenerateDateRateAction.php`](../app/Actions/PianoRate/GenerateDateRateAction.php:25) | 25 | start date passata a `Recurr\Rule` in fase di generazione |
| [`GenerateDateRateAction.php`](../app/Actions/PianoRate/GenerateDateRateAction.php:57) | 57 | `Carbon::parse()` nel fallback `defaultMonthly()` (piano senza ricorrenza) |

L'unica leva dell'amministratore è `giorno_scadenza` (giorno del mese, default `5` nel
form, `1` nel service — vedi D1), più frequenza e intervallo della ricorrenza. Può dire
*"il 15 di ogni mese"*; **non** può dire *"si parte da marzo"*.

### 3.2 La generazione

`GenerateDateRateAction::execute()` restituisce un `array` di date, che
`GenerateRateQuotesAction::execute()` riceve come `$dateRate` e itera
([`GenerateRateQuotesAction.php:148`](../app/Actions/PianoRate/GenerateRateQuotesAction.php:148)):
`$numeroRate = count($dateRate)`, e ogni indice diventa `numero_rata = $index + 1`.

> **Nota architetturale che rende tutto lo spec economico:** il consumatore delle date
> è **già un vettore**. `GenerateRateQuotesAction` non sa nulla di RRULE, ricorrenze o
> `giorno_scadenza` — riceve un array di date e ci itera sopra. Il calendario manuale
> (R2) non richiede quindi di toccare il generatore di quote: richiede solo una
> **seconda sorgente** per quell'array. L'astrazione giusta esiste già.

### 3.3 La modifica

Non esiste. L'unica rotta di update sul piano è
[`piani-rate.update-stato`](../routes/gestionale.php:174). `rate.data_scadenza` è
scritta una sola volta, alla generazione.

### 3.4 Schema attuale

`piani_rate` ([migration](../database/migrations/2025_11_05_093141_create_piani_rate_table.php)):
`numero_rate`, `giorno_scadenza`, `metodo_distribuzione`, nessun campo data.
`ricorrenze_rate` ([migration](../database/migrations/2025_11_05_221451_create_ricorrenze_rate_table.php)):
`rrule` + campi denormalizzati, `unique(piano_rate_id)` — al più una ricorrenza per piano.

---

## 4. Modello dati

Tre interventi additivi. Nessuna tabella nuova: il vettore di date manuali non merita
una tabella propria, perché è già materializzato in `rate` subito dopo la generazione
(vedi D3).

### 4.1 `piani_rate.data_prima_scadenza` (R1)

```php
$table->date('data_prima_scadenza')->nullable()->after('giorno_scadenza');
// NULL = comportamento storico (parte da gestione.data_inizio) — retrocompatibilità
```

Nullable **per scelta, non per pigrizia**: `NULL` è il valore che dice "usa il default
storico", e permette a tutti i piani esistenti di continuare a generare esattamente le
stesse date senza backfill.

### 4.2 `piani_rate.modalita_calendario` (R2)

```php
$table->string('modalita_calendario', 20)->default('ricorrenza');
// 'ricorrenza' (default, comportamento attuale) | 'manuale'
```

Backed Enum `ModalitaCalendario` persistito `VARCHAR(20)`, secondo la convenzione di
progetto (roadmap §10: *Backed Enums + VARCHAR(50)*).

In modalità `manuale` non si crea alcun record in `ricorrenze_rate`: le date arrivano dal
form e vanno dritte in `rate`. Le due modalità sono mutuamente esclusive e la modalità è
**una proprietà del piano**, non della singola rata.

### 4.3 Nessuna colonna nuova sulle rate — ma un debito da chiudere

`rate.data_scadenza` esiste già ed è il campo da rendere modificabile.

`rate_quote.data_scadenza` esiste anch'essa
([migration](../database/migrations/2025_11_05_093418_create_rate_quote_table.php:22),
nullable, con **due indici**: uno semplice e `idx_quote_scadenza` composito con `stato`).
È scritta alla generazione
([`GenerateRateQuotesAction.php:108`](../app/Actions/PianoRate/GenerateRateQuotesAction.php:108)
e [`:270`](../app/Actions/PianoRate/GenerateRateQuotesAction.php:270)) e **non è letta da
nessuno**: ogni consumatore passa dalla relazione (`$quota->rata->data_scadenza`, es.
[`IncassoRateService.php:130`](../app/Services/Gestionale/IncassoRateService.php:130)).

È una copia denormalizzata senza lettori. Diventa un problema **nel momento esatto in cui
le date diventano modificabili**: una copia non sincronizzata è una bomba a orologeria per
il primo che, un domani, scriverà una query su quell'indice. Vedi D2 per la scelta
(sincronizzare vs dismettere); qualunque sia, va decisa *prima* di rilasciare R3, non dopo.

---

## 5. Fase 1 — Data prima scadenza esplicita (R1)

L'intervento più piccolo dei tre, e quello che intercetta la lamentela più frequente.

### 5.1 Backend

Un helper unico, sorgente di verità del punto di partenza:

```php
// PianoRate.php
public function dataPartenzaCalendario(): Carbon
{
    return $this->data_prima_scadenza
        ? Carbon::parse($this->data_prima_scadenza)
        : Carbon::parse($this->gestione->data_inizio);
}
```

Poi si sostituisce nei tre punti di §3.1. Cambio meccanico, ma con **due trappole**:

1. **`giorno_scadenza` non deve sovrascrivere il giorno scelto.**
   `GenerateDateRateAction` forza `$date->day = $giornoTarget` su *ogni* occorrenza
   ([riga 40-48](../app/Actions/PianoRate/GenerateDateRateAction.php:40)). Se
   l'amministratore indica il **15 marzo** come prima scadenza e `giorno_scadenza` vale
   `5`, il risultato sarebbe *5 marzo* — il campo appena introdotto verrebbe ignorato in
   silenzio. Regola: **se `data_prima_scadenza` è valorizzata, il giorno del mese si
   deriva da essa** e `giorno_scadenza` viene ignorato per quel piano (e nascosto in UI).
   Un solo campo deve governare il giorno.

2. **La RRULE va rigenerata, non solo la data di start.**
   `PianoRateCreatorService::creaRicorrenza()` scrive `setByMonthDay($giorno)` nella
   regola persistita ([riga 79](../app/Services/PianoRateCreatorService.php:79)). Se
   cambia il giorno di partenza senza rigenerare la `rrule`, ricorrenza salvata e date
   generate divergono — e la divergenza è invisibile finché qualcuno non rigenera.

### 5.2 UI

In [`PianiRateNew.vue`](../resources/js/pages/gestionale/pianiRate/PianiRateNew.vue:1025)
il campo *"Giorno del mese"* è affiancato (e, se valorizzato, sostituito) da un date
picker **"Data prima scadenza"**, con placeholder esplicito sul default:
*"Se vuoto: inizio della gestione (01/01/2026)"*. Rendere visibile il default è metà
della soluzione — l'utente che ha scritto non sapeva da dove uscisse quella data.

### 5.3 Validazioni

- `data_prima_scadenza` deve cadere **entro l'esercizio** del piano. `[VERIFICA]` contro
  `esercizi.data_inizio` / `data_fine`.
- Se `data_prima_scadenza < gestione.data_inizio` → **warning non bloccante**
  (rate esigibili prima dell'apertura della gestione: legittimo per un piano
  straordinario deliberato in anticipo, sospetto per un ordinario).
- L'ultima rata generata oltre la fine della gestione → warning non bloccante, con la
  data mostrata. Il pattern è quello del Validatore Coerenza Millesimi: avvisare, non
  impedire.

---

## 6. Fase 2 — Calendario manuale (R2)

Grazie a §3.2, l'intervento è chirurgico: **`GenerateDateRateAction` acquisisce un
secondo ramo**, e nient'altro a valle cambia.

```php
public function execute(PianoRate $pianoRate, $gestione): array
{
    if ($pianoRate->modalita_calendario === ModalitaCalendario::Manuale) {
        return $pianoRate->rate()
            ->orderBy('numero_rata')
            ->pluck('data_scadenza')
            ->all();
    }
    // ... ramo ricorrenza esistente, invariato
}
```

`[VERIFICA]` Il pluck presuppone che in modalità manuale le `rate` esistano già come
scheletro (date senza quote) al momento della generazione delle quote. È la sequenza
naturale — l'amministratore compila il calendario, poi genera — ma va confermata contro
l'ordine effettivo delle chiamate in
[`PianoRateController::store()`](../app/Http/Controllers/Gestionale/PianiRate/PianoRateController.php:183).
In alternativa, le date viaggiano nel payload di `store` e si passano direttamente
all'action senza round-trip su DB (più semplice, vedi D3).

### 6.1 UI

Toggle **Ricorrenza / Manuale** in cima al blocco scadenze. In manuale: tabella di
`numero_rate` righe con un date picker per riga, più due comodità che fanno la
differenza fra "usabile" e "compilo 12 date a mano":

- **"Precompila da ricorrenza"** — genera le date con la regola corrente e le rende
  editabili. Il caso reale non è *"12 date arbitrarie"*, è *"mensile, ma la rata di
  agosto slitta a settembre"*.
- **Ordinamento automatico** con avviso se l'utente inserisce date non crescenti.

### 6.2 Validazioni

- Tutte le date valorizzate: nessun buco.
- **Strettamente crescenti** (INV-2). Due rate con la stessa scadenza sono un errore di
  compilazione, non una configurazione.
- `count(date) === numero_rate`: cambiando `numero_rate` la tabella si adegua troncando o
  aggiungendo in coda, mai riordinando.

---

## 7. Fase 3 — Modifica post-generazione (R3)

Qui vive la vera complessità, e non è contabile (§2): è di **propagazione**.

### 7.1 Cosa può essere modificato, e quando

| Stato della rata | Data modificabile? | Perché |
|---|---|---|
| `bozza` (non emessa) | **Sì**, libera | Nessuna scrittura, nessun evento pubblicato |
| `emessa` | **Sì**, con conferma esplicita | Nessuna scrittura da stornare (§2), ma il condòmino ha già visto la scadenza |
| `chiusa` / integralmente incassata | **No** | Ri-datare una rata già pagata non ha significato operativo e falsa gli storici di morosità |

Sul piano `approvato` la modifica resta possibile: l'approvazione riguarda il riparto
deliberato, non il calendario. `[VERIFICA]` da confermare contro il significato che
`StatoPianoRate::APPROVATO` ha assunto in produzione — se l'approvazione è intesa come
"delibera assembleare congelata", allora anche il calendario ne fa parte e serve una
nota di motivazione obbligatoria (pattern override del Validatore Coerenza Millesimi).

### 7.2 Propagazione — la checklist che rende sicura la modifica

Ogni consumatore di `data_scadenza`, verificato con grep sul codice:

| Consumatore | Riferimento | Effetto della modifica |
|---|---|---|
| **`rate_quote.data_scadenza`** | copia denormalizzata (§4.3) | Da sincronizzare o dismettere — **decidere prima** (D2) |
| **Evento condòmino** (`SCADENZA_RATA_CONDOMINO`) | [`SyncScadenziarioWithPianoRate.php:197`](../app/Listeners/Gestionale/SyncScadenziarioWithPianoRate.php:197) | `start_time`/`end_time` da riallineare |
| **Task admin "Emettere rata"** | [`:63`](../app/Listeners/Gestionale/SyncScadenziarioWithPianoRate.php:63) | Promemoria a `scadenza − 7gg` |
| **Task admin "Verifica incassi"** | [`:96`](../app/Listeners/Gestionale/SyncScadenziarioWithPianoRate.php:96) | Controllo a `scadenza + 4gg` |
| **Task riemissione** | [`EmissioneRateController.php:331`](../app/Http/Controllers/Gestionale/PianiRate/EmissioneRateController.php:331) | Stesso `−7gg`, ricreato dopo annullamento |
| **Treasury Guardian** | [`TreasuryGuardianService.php:106`](../app/Services/Treasury/TreasuryGuardianService.php:106) | Legge live: **nessuna azione**, si riallinea da solo |
| **Stampe e read-model** | `PianoRateQuoteService`, `IncassoRateService`, `PianoRatePrintController` | Leggono via relazione: **nessuna azione** |

### 7.3 La trappola dei duplicati nello scadenzario

`SyncScadenziarioWithPianoRate` previene i duplicati degli eventi condòmino con questo
check ([riga 148](../app/Listeners/Gestionale/SyncScadenziarioWithPianoRate.php:148)):

```php
$esiste = Evento::where('start_time', $rata->data_scadenza->copy()->setTime(0, 0))
    ->whereJsonContains('meta->context->rata_id', $rata->id)
    // ...
```

Il predicato include **`start_time`**. Oggi è innocuo, perché la data non cambia mai.
Il giorno in cui cambia, il check smette di trovare l'evento esistente — che è ancora
alla vecchia data — e **ne crea un secondo**: il condòmino si ritrova due scadenze per
la stessa rata, una fantasma alla data vecchia.

I task admin (righe 71 e 98) usano già il solo `rata_id` e sono immuni. È il solo
evento condòmino a portare `start_time` nel predicato.

**Fix, da applicare comunque e a prescindere da R3:** togliere `start_time` dal check di
esistenza. L'identità di quell'evento è `(rata_id, anagrafica_id)` — la data è un suo
attributo, non parte della sua chiave.

### 7.4 Il pattern da riusare — c'è già in casa

`FatturaPassivaService` risolve **esattamente lo stesso problema** per le fatture:
cambia `data_scadenza` → riallinea il task Inbox
([riga 866-873](../app/Services/Gestionale/FatturaPassivaService.php:866)):

```php
$nuovaScadenza = $fattura->fresh()->data_scadenza;
if ($nuovaScadenza && $dataScadenzaBefore !== $nuovaScadenza->format('Y-m-d')) {
    Evento::where('meta->context->fattura_id', $fattura->id)
        ->where('meta->type', 'pagamento_fornitore')
        ->where('is_completed', false)
        ->update(['start_time' => $nuovaScadenza->setTime(9, 0)]);
}
```

Da imitare punto per punto, incluso il filtro `is_completed = false` (un promemoria già
evaso non si risuscita) e il log di audit con `before`/`after` (riga 876). Nasce così un
`RiprogrammaScadenzaRataAction` simmetrico, che aggiorna la rata e riallinea i tre eventi
in una transazione.

### 7.5 Modifica singola vs a cascata

Spostando la rata *n*, l'amministratore intende quasi sempre spostare **anche le
successive**. L'azione espone entrambe le semantiche, con la seconda come default:

- **Solo questa rata** — le altre restano dove sono (il caso "agosto slitta a settembre").
- **Questa e le successive** — traslazione rigida del delta sulle rate `> n` non ancora
  chiuse (il caso "il piano parte con un mese di ritardo").

La traslazione a cascata salta le rate chiuse e riapplica INV-2 (monotonia) alla fine,
segnalando le collisioni invece di risolverle in silenzio.

### 7.6 Notifica al condòmino

Modificare la scadenza di una rata **già emessa** cambia un'informazione che il condòmino
ha già ricevuto. La conferma in UI dichiara quanti condòmini sono impattati e offre —
non impone — l'invio di una comunicazione di variazione. La scelta va tracciata sulla
rata (`dati_extra`, pattern `override_budget`), perché *"perché nessuno mi ha avvisato"*
è una contestazione assembleare reale.

---

## 8. Invarianti e validazioni

- **INV-1** (integrità): ogni rata di un piano generato ha `data_scadenza` non nulla.
- **INV-2** (monotonia): per ogni piano, `numero_rata` crescente ⇒ `data_scadenza`
  strettamente crescente. Vale per la Rata Zero, che precede sempre la rata 1.
- **INV-3** (coerenza copia): `rate_quote.data_scadenza == rata.data_scadenza` per ogni
  quota — *oppure* la colonna è dismessa (D2). Un'invariante o l'altra, mai una terza via.
- **INV-4** (nessun effetto contabile): riprogrammare una data **non** crea, modifica o
  cancella righe di scrittura. Verificabile con un diff del giornale prima/dopo.
- **INV-5** (scadenzario allineato): dopo una riprogrammazione non esistono eventi con
  `meta.context.rata_id = X` e `start_time` diverso dalla `data_scadenza` corrente.
- **INV-6** (nessun duplicato): per ogni `(rata_id, anagrafica_id)` esiste **al più un**
  evento `SCADENZA_RATA_CONDOMINO` — l'invariante che §7.3 oggi non garantisce.

---

## 9. Piano di test (Pest)

Suite completa e non filtrata prima dell'RC. Un `Event::fake()` non prova la
propagazione: i listener reali devono girare.

**Fase 1 — data prima scadenza**
1. `data_prima_scadenza = 15/03` su piano mensile a 12 rate → prima rata 15/03, ultima
   15/02 dell'anno dopo; **il giorno resta 15** anche con `giorno_scadenza = 5` (§5.1.1).
2. `data_prima_scadenza = NULL` → date identiche byte per byte a quelle prodotte prima
   della migration (regressione: il default storico non si muove).
3. Prima scadenza il 31/01 su piano mensile → febbraio cade il 28 (o 29), non trabocca a
   marzo — `addMonthsNoOverflow` + clamp `daysInMonth` già presenti.
4. RRULE persistita coerente con le date generate dopo il cambio di giorno (§5.1.2).

**Fase 2 — calendario manuale**
5. 4 date arbitrarie non equidistanti → 4 rate con quelle esatte scadenze; gli **importi
   sono identici** a quelli del piano ricorrenziale equivalente (l'asse date non tocca
   l'asse importi).
6. Date non crescenti → errore di validazione, nessuna rata creata.
7. `count(date) ≠ numero_rate` → errore di validazione.
8. Piano manuale + Rata Zero (`metodo_distribuzione = 'rata_zero'`): la Rata Zero prende
   `$dateRate[0]` ([riga 53](../app/Actions/PianoRate/GenerateRateQuotesAction.php:53))
   — verificare che non collida con la rata 1 e che INV-2 regga.

**Fase 3 — modifica**
9. Rata in bozza ri-datata → `rate.data_scadenza` aggiornata, INV-3, INV-5 verificati.
10. Rata **emessa** ri-datata → INV-4: il giornale è identico prima e dopo (conteggio
    righe e somma importi invariati).
11. **Nessun duplicato** (INV-6): ri-datare una rata approvata e ri-scatenare il listener
    → un solo evento per `(rata_id, anagrafica_id)`. *Questo test fallisce sul codice
    attuale* — è la prova del bug di §7.3.
12. Task admin riallineati: `−7gg` e `+4gg` ricalcolati sulla nuova data; un task già
    completato **non** viene toccato.
13. Cascata: spostare la rata 3 di +30gg con "e successive" → 4..12 traslate, la rata
    chiusa saltata, INV-2 rispettato.
14. Rata chiusa → tentativo di modifica respinto con eccezione.
15. Treasury Guardian: dopo lo spostamento di una rata dentro/fuori la finestra a 30
    giorni, la previsione di liquidità cambia di conseguenza (nessuna cache stantia).

---

## 10. Decisioni

Quattro delle sei sollevate in prima stesura sono state chiuse verificandole contro il
codice: non erano scelte di prodotto, avevano già una risposta nel repository.

### D1 — Default di `giorno_scadenza` — **RISOLTA: non è una decisione, è un bug**

Sembravano tre default in disaccordo. Guardando la migration, i default sono **due**, e
uno solo è fuori posto:

| Sorgente | Valore |
|---|---|
| [migration `piani_rate`](../database/migrations/2025_11_05_093141_create_piani_rate_table.php) | `default(5)` |
| [`PianiRateNew.vue:170`](../resources/js/pages/gestionale/pianiRate/PianiRateNew.vue:170) | `5` |
| [`GenerateDateRateAction`](../app/Actions/PianoRate/GenerateDateRateAction.php:40) (righe 40, 58) | `?? 5` |
| [`PianoRateCreatorService:38`](../app/Services/PianoRateCreatorService.php:38) | **`?? 1`** |

Il DB dichiara `5`. Il service, scrivendo `$data['giorno_scadenza'] ?? 1`, **passa un
valore esplicito e impedisce al default del DB di applicarsi**: un piano creato senza
quel campo viene persistito a `1` e poi generato al `5`. Non c'è una scelta di prodotto
da fare — c'è un `?? 1` da portare a `?? 5`. Meglio ancora: rimuovere del tutto la chiave
dall'array quando è assente, lasciando decidere al DB.

### D2 — `rate_quote.data_scadenza` — **DECISA: sincronizzare (opzione a)**

Colonna scritta, mai letta, con due indici (§4.3). Alternative: (a) sincronizzarla
nell'action di riprogrammazione; (b) smettere di scriverla e droppare gli indici.

**Sincronizzare.** Costa una riga nell'action (`$rata->rateQuote()->update([...])`), e la
colonna è **l'unico substrato già a DB** per le scadenze per-condòmino e per i piani di
rientro v1.11 — dismetterla oggi significa ricrearla fra sei mesi con un backfill sopra
dati vivi. È l'unica delle sei che è **bloccante**: va chiusa prima di rilasciare la
Fase 3, perché una copia che diverge silenziosamente è peggio di una copia inutile.

### D3 — Dove vivono le date manuali prima della generazione — **DECISA: payload (opzione a)**

Le date viaggiano nel payload di `store` e vanno dritte all'action: zero schema, zero
stato intermedio, nessuna rata-fantasma senza quote da ripulire se l'utente abbandona il
form. Lo scheletro di `rate` (opzione b) si adotta solo se emerge la richiesta esplicita
di salvare un calendario incompleto e tornarci sopra — oggi nessuno l'ha chiesta.

Conseguenza su §6: il ramo manuale di `GenerateDateRateAction` riceve le date come
argomento, non le rilegge da `rate`. Il `[VERIFICA]` sull'ordine delle chiamate in
`PianoRateController::store()` decade.

### D4 — L'approvazione congela il calendario? — **RISOLTA: sì, è una delibera**

`updateStato()` non è un flag tecnico:
[richiede `data_delibera_assemblea`](../app/Http/Controllers/Gestionale/PianiRate/PianoRateController.php:620)
(`required_if:approvato,true`) e registra `numero_verbale`, `nota_approvazione`,
`approvato_da_user_id`, `approvato_il` — i campi legali aggiunti dalla
[migration delibera](../database/migrations/2026_03_22_075117_add_delibera_fields_to_piani_rate_table.php).
`APPROVATO` **è** l'approvazione assembleare, con numero di verbale.

Il calendario fa parte di ciò che l'assemblea ha deliberato: *"12 rate mensili al 5 del
mese"* sta nel verbale quanto gli importi. Quindi: modifica ammessa (non è un fatto
contabile, §2), ma con **nota di motivazione obbligatoria** su piano approvato — stesso
pattern dell'override del Validatore Coerenza Millesimi. La nota si affianca a
`nota_approvazione`, non la sostituisce.

### D5 — Slittamento della gestione — **RISOLTA in gran parte dalla Fase 1**

`gestione.data_inizio` **è liberamente modificabile** dopo la generazione di un piano
([`UpdateGestioneRequest:27`](../app/Http/Requests/Gestionale/Gestione/UpdateGestioneRequest.php:27),
nessuna guardia sui piani esistenti). Lo scenario non è ipotetico.

Il comportamento attuale è peggiore del previsto: le date già generate restano ferme —
corretto — ma la `rrule` persistita **non contiene la data di partenza** (è
`GenerateDateRateAction:25` a iniettarla, leggendo `$gestione->data_inizio` *al momento
della generazione*). Quindi il piano è stabile finché nessuno rigenera, e **slitta in
silenzio alla prima rigenerazione**, mesi dopo, senza che nulla lo colleghi alla modifica
della gestione.

La Fase 1 lo chiude alla radice: un piano con `data_prima_scadenza` valorizzata **àncora
la propria partenza** e diventa immune. Resta il caso `NULL` (piani storici), per cui
basta un avviso non bloccante nel Radar Salute Contabile quando `gestione.data_inizio`
cambia con piani già generati, con azione "fissa le date correnti" — che valorizza
`data_prima_scadenza` con la data della rata 1 già emessa. Nessun ricalcolo automatico:
quelle date possono essere già state comunicate ai condòmini.

### D6 — Interazione con la rateazione per origine — **DECISA dallo split di roadmap**

Con [`v1.10_rateazione_origine.md`](v1.10_rateazione_origine.md) in v1.10 e il calendario
manuale in v1.10.1, l'ordine di atterraggio è noto: **le origini arrivano prima**. Quindi
è il calendario a doversi adattare, e la regola è una sola:

> `piano_origine_scadenza` indicizza le percentuali per `rata_numero`. Cambiare
> `numero_rate` — o rimuovere una rata dal calendario manuale — **invalida la
> configurazione per origine**, che va ricompilata.

Da implementare come blocco esplicito con messaggio, non come cancellazione silenziosa
delle righe. Spostare una *data* lasciando invariato `numero_rate` è invece innocuo: le
percentuali sono agganciate al numero della rata, non alla sua scadenza — i due assi
restano ortogonali, come da premessa del documento.

---

## 11. Collocazione roadmap

Le tre richieste hanno costo e rischio molto diversi, e **non conviene trattarle come un
blocco unico**.

### v1.10 — Fase 1 (R1: data prima scadenza)

Una colonna nullable, un helper, tre sostituzioni, un date picker. Nessun impatto su
scadenzario, ledger o read-model: le date nascono già corrette, non si *muovono*. La
retrocompatibilità è garantita dal `NULL`.

È anche la richiesta che genera più attrito nel supporto — la formulazione
*"di default mi prende l'inizio della contabilità"* dice che l'utente non capisce da dove
esca quella data, e metà del valore lo consegna il solo placeholder di §5.2.

In più, chiude un difetto di documentazione: [`logica_piani_rate.md:93`](logica_piani_rate.md:93)
e [`guida_preventivi_rate_capitoli.md:95`](guida_preventivi_rate_capitoli.md:95) recitano
già *"Definisci numero di rate, **data prima scadenza** e eventuali arrotondamenti"*.
Il campo è documentato da tempo e non esiste: parte della confusione degli amministratori
nasce da lì. La Fase 1 allinea il software alla sua stessa documentazione.

### v1.10.1 — Fasi 2 e 3 (R2, R3)

La 1.10.1 è oggi **Export SEPA**, ed è la collocazione naturale: un export di incassi
ricorrenti ha bisogno di date di addebito corrette e correggibili, quindi le due
funzionalità si rinforzano invece di competere. La 1.10.0 è a `beta.29` e già molto
carica — infilarci una feature che tocca scadenzario ed eventi condòmino significa
rimettere in gioco superfici già stabilizzate.

### Da fare comunque, subito

Il **fix duplicati di §7.3** (togliere `start_time` dal check di esistenza) non dipende da
nessuna delle tre fasi ed è una riga. Va in 1.10 insieme alla Fase 1, come bonifica: oggi
è latente solo perché le date non cambiano mai — cioè è latente solo finché non facciamo
esattamente ciò che questo documento propone.

Il **D1** (tre default per `giorno_scadenza`) è anch'esso preesistente e va chiuso con la
Fase 1, visto che è lo stesso codice.

---

### Changelog del documento
- **rev 2 (2026-07-29)**: §10 riscritta — quattro decisioni chiuse verificandole contro il
  codice. D1 declassata a bug (`?? 1` nel service scavalca il `default(5)` del DB); D4
  risolta (`APPROVATO` richiede `data_delibera_assemblea`: è delibera, serve nota
  obbligatoria); D5 risolta (`gestione.data_inizio` è editabile senza guardie, e la
  `rrule` non contiene la partenza → slittamento silenzioso alla prima rigenerazione, che
  la Fase 1 chiude alla radice); D6 risolta dallo split di roadmap. D2 e D3 confermate
  sulle opzioni raccomandate; D2 marcata **bloccante** per la Fase 3.
- **rev 1 (2026-07-29)**: prima stesura. Origine: tre richieste indipendenti da
  amministratori beta. Verificato su codice v1.10.0-beta.29 che la scadenza non entra nel
  giornale (§2) e che il consumatore delle date è già un vettore (§3.2) — i due fatti che
  rendono l'intervento additivo. Individuati due difetti preesistenti: check duplicati
  eventi sensibile a `start_time` (§7.3) e triplo default di `giorno_scadenza` (D1).
  Proposta di split roadmap: Fase 1 in v1.10, Fasi 2–3 in v1.10.1.
