# Treasury Guardian Widget — Guida di Implementazione (MVP)

> **Versione target:** anticipato in v1.9.x · **Modulo:** Dashboard Intelligence
> **Scope:** sentinella finanziaria del **singolo condominio** (non multi-condominio)
> **Stato documento:** guida pre-implementazione — da confermare le "Domande aperte" prima di scrivere codice.

---

## 1. Obiettivo

Trasformare la dashboard del condominio da insieme di tile statiche a **assistente finanziario operativo**. Il widget deve prevedere uno scoperto di liquidità nei prossimi 30 giorni, spiegarne la causa e suggerire azioni concrete — restando sempre **advisory**, mai autonomo.

Una tile mostra un numero. Un assistente fa quattro cose:

1. **Prevede** — saldo proiettato, giorno dello scoperto, banda di incertezza.
2. **Spiega** — quali fatture e quali morosità causano il problema.
3. **Suggerisce** — leve concrete, quantificate, ordinate per fattibilità.
4. *(post-MVP)* **Simula** e **agisce proattivamente**.

---

## 2. Decisioni architetturali (recap)

| Decisione | Motivazione |
|---|---|
| Widget **atomico sul singolo `condominio_id`** | La vista multi-condominio futura sarà semplice composizione sopra questo servizio. Non si perde nulla, non si paga adesso. |
| Logica in un **`TreasuryGuardianService`**, non nel controller | Coerente con il principio "controller magri, business logic nei service". |
| Timeline di cassa in un **`TreasuryTimelineBuilder`** condiviso | In MVP il Treasury Guardian **non tocca** `LiquidityForecastService` (forecast 90gg): zero rischio di regressione. La convergenza dei due motori su un unico builder è rinviata — vedi §3 e §11. |
| Folder `app/Services/Treasury/` (service-layer classico) | Coerenza con `FatturaPassivaService`, `CalcoloQuoteService`, ecc. **Niente `App\Domain\`**: introdurrebbe un secondo stile architetturale per una sola feature. |
| Interfaccia `DashboardWidget` **leggera e opzionale** | Utile perché Radar Salute Contabile, Credit Enforcer, Liquidity Forecast widget sono in roadmap. Ma niente registry pesante in MVP. |
| **Nessuna nuova migration** in MVP | Il widget è read-only su dati esistenti (`scrittura_contabile`, `fatture_passive`, `conti_contabili`, pivot `fattura_scrittura`). |

### Cosa NON fare

- ❌ Query builder o aggregazioni dentro `DashboardController`.
- ❌ Componente Vue "smart" che conosce le regole di calcolo del rischio.
- ❌ Logica di forecast duplicata: la timeline di cassa vive una volta sola, in `TreasuryTimelineBuilder`.
- ❌ Pretendere che il widget muova soldi o tocchi il fondo riserva da solo.
- ❌ Saldo materializzato che rischia di andare fuori sync: la liquidità si **deriva dal giornale** (il libro è la verità).

---

## 3. Domande aperte — risoluzione MVP

Alcune di queste non sono "funzioni future": sono fatti già presenti nel codebase, da confermare con un rapido controllo. Le altre sono deferral genuini o decisioni di design, marcati con un `// TODO(trigger)` (vedi §11).

| # | Questione | Risoluzione MVP | Nota futura |
|---|---|---|---|
| 1 | **Condominio corrente** — come lo risolve la dashboard? | Non blocca: il service riceve già `condominio_id` esplicito ed è disaccoppiato. Nel controller riusare il meccanismo già adottato dagli altri controller in `app/Http/Controllers/Dashboard/` (route binding / sessione / `currentCondominio()`). | Nessuna — è una riga da copiare, non una funzione da aggiungere. |
| 2 | **Liquidità** — saldo materializzato o calcolo dal giornale? | **Calcolo dal giornale**, sempre. È sempre corretto e rispetta "il libro è la verità". | `// TODO(perf)`: se la pagina globale multi-condominio risulta lenta, valutare cache del saldo o materialized view. Trigger = performance misurata, non adesso. |
| 3 | **`LiquidityForecastService`** — espone una finestra parametrica? | In MVP **non si tocca**. La timeline di cassa vive in `TreasuryTimelineBuilder`, primitivo unico e parametrico per finestra; il Treasury Guardian lo usa per i 30gg. Il forecast 90gg esistente resta intatto. | `// TODO(v1.x)`: rifattorizzare `LiquidityForecastService` perché consumi anch'esso `TreasuryTimelineBuilder`, così 30gg e 90gg condividono un solo motore. |
| 4 | **Fondi vincolati** — separazione disponibile/vincolata | `liquiditaTotaleCents` calcolata; `vincolata = 0`, `disponibile = totale`; avviso non bloccante se esiste un fondo riserva. Vedi §6.4. | `// TODO(v1.10)`: popolare `liquiditaVincolataCents` quando Voci Accantonamento è disponibile. |
| 5 | **Fattura annullata** — quale campo segnala una fattura stornata? | ✅ **Risolto.** Lo storno forza `stato_pagamento = 'stornata'` (più flag `is_stornata` in `dati_extra`). Il filtro di inclusione `stato_pagamento ∈ (aperta, parziale)` di §6.2 **esclude già** le stornate: nessun filtro extra. Il widget usa la colonna, non il JSON `dati_extra` (vedi §6.7). | Nessuna. |
| 6 | **Debiti pregressi** — come sono modellati? | ✅ **Risolto.** Esiste il flag `is_pregresso`. Regola: pregresso con scadenza *futura* → uscita normale; pregresso *scaduto* → **segregato** in una riga propria, fuori dal semaforo a 30gg (vedi §6.7). | Nessuna. |

> **Convenzione TODO.** Le note future vivono **nel codice**, non solo in questo documento: marker `// TODO(trigger): ...` con la condizione di attivazione esplicita (`v1.10`, `perf`, `v1.x`). Coerente con la disciplina di deferral-tracking del progetto.

---

## 4. Struttura file

```txt
app/
 ├── Http/Controllers/Dashboard/
 │    └── DashboardController.php          [MODIFY] — solo orchestrazione
 ├── Services/Treasury/
 │    ├── TreasuryGuardianService.php      [NEW]   — logica principale
 │    └── TreasuryTimelineBuilder.php      [NEW]   — costruzione timeline cassa
 └── Support/Treasury/
      ├── TreasuryStatus.php               [NEW]   — DTO readonly di output
      └── AzioneSuggerita.php              [NEW]   — DTO singola azione

resources/js/Pages/dashboard/
 ├── Dashboard.vue                          [MODIFY]
 └── components/
      ├── TreasuryGuardianWidget.vue        [NEW]
      └── TreasuryActionRow.vue             [NEW]

tests/Feature/Treasury/
 └── TreasuryGuardianServiceTest.php        [NEW]   — Pest

# Opzionale (consigliato, abilita i widget futuri):
app/Contracts/DashboardWidget.php           [NEW]
```

---

## 5. Backend

### 5.1 DTO di output — `TreasuryStatus`

DTO `readonly`, immutabile, **tutti gli importi in BigInteger cents**.

```php
namespace App\Support\Treasury;

final readonly class TreasuryStatus
{
    public function __construct(
        public int     $condominioId,
        public ?int    $gestioneId,
        public int     $liquiditaTotaleCents,
        public int     $liquiditaVincolataCents,   // 0 in MVP se non calcolabile — vedi §6.4
        public int     $liquiditaDisponibileCents,
        public int     $uscitePredittiveCents,     // residuo, non importo originale
        public int     $incassiAttesiCents,
        public int     $debitiPregressiScadutiCents, // esposizione pregressa segregata — NON guida il semaforo (§6.7)
        public int     $scenarioPessimisticoCents, // liquidità − uscite (nessun incasso)
        public int     $scenarioOttimisticoCents,  // liquidità + incassi − uscite
        public ?string $giornoScopertoPrevisto,    // ISO date, null se nessuno scoperto
        public int     $scopertoMaxCents,          // ≤ 0; entità peggiore dello scoperto
        public string  $livello,                   // 'verde' | 'giallo' | 'rosso'
        public array   $fattureInScadenza,         // dettaglio causale
        public array   $morositaImpattanti,        // dettaglio causale
        public array   $azioniSuggerite,           // AzioneSuggerita[]
    ) {}

    public function toArray(): array { /* serializzazione per Inertia */ }
}
```

### 5.2 `TreasuryGuardianService`

```php
namespace App\Services\Treasury;

use App\Support\Treasury\TreasuryStatus;

final class TreasuryGuardianService
{
    public function __construct(
        private readonly TreasuryTimelineBuilder $timeline,
    ) {}

    /**
     * Stato di tesoreria di UN condominio. La vista multi-condominio
     * futura sarà un loop su questo metodo.
     */
    public function perCondominio(
        int $condominioId,
        ?int $gestioneId = null,
        int $giorni = 30,
    ): TreasuryStatus {
        // 1. Liquidità attuale — derivata dal giornale (vedi §6.1)
        // 2. Uscite predittive — fatture passive aperte, importo RESIDUO (§6.2)
        // 3. Incassi attesi — rate emesse non incassate in finestra (§6.3)
        // 4. Timeline cassa giorno-per-giorno → giorno dello scoperto (§6.5)
        // 5. Banda ottimistico/pessimistico
        // 6. Livello semaforo + azioni suggerite (§6.6)
    }
}
```

> ⚠️ **Niente duplicazione, ma in MVP niente refactor.** La logica di timeline vive una volta sola, in `TreasuryTimelineBuilder`. In MVP il Treasury Guardian **non chiama** `LiquidityForecastService`: il forecast 90gg resta intatto. La convergenza dei due motori sul builder condiviso è un `// TODO(v1.x)` (vedi §3 e §11) — così si evita la duplicazione senza rischiare una regressione sul 90gg adesso.

### 5.3 `DashboardController` — solo orchestrazione

```php
public function index(Request $request, TreasuryGuardianService $treasury): Response
{
    $condominio = $request->user()->currentCondominio(); // adattare alla risoluzione reale

    return Inertia::render('dashboard/Dashboard', [
        'treasury' => $treasury->perCondominio($condominio->id)->toArray(),
        // ...altri dati dashboard...
    ]);
}
```

Nessun calcolo nel controller. Nessuna query aggregata. Solo: risolvi il condominio → chiama il service → passa il DTO serializzato a Inertia.

### 5.4 (Opzionale) Contratto `DashboardWidget`

Conviene introdurlo ora perché Radar Salute Contabile, Credit Enforcer e Liquidity Forecast widget sono in roadmap.

```php
namespace App\Contracts;

interface DashboardWidget
{
    public function key(): string;
    public function isVisible(int $condominioId): bool;  // es. Radar: hidden if OK
    public function payload(int $condominioId): array;
}
```

`isVisible()` serve fin da subito: in roadmap il Radar è "nascosto se va tutto bene" — quel comportamento vive qui, non sparso nel Vue. Per il Treasury Guardian `isVisible()` ritorna sempre `true` (mostra anche lo stato verde, è rassicurante).

---

## 6. Regole di dominio (i calcoli)

### 6.1 Liquidità attuale

Somma del saldo dei `conti_contabili` con `categoria = liquidita`, **derivata dal giornale**: somma delle righe di `scrittura_contabile` per quei conti, filtrata per `condominio_id` e — se passato — `gestione_id`.

### 6.2 Uscite predittive — ⚠️ finestra, residuo, anomalie

**Finestra: scadute + in scadenza.** NON solo le fatture con scadenza *futura*. La finestra corretta è `data_scadenza ≤ oggi + N`, fatture ancora aperte/parziali. Le fatture **già scadute e non pagate** sono le più urgenti — sono quelle che portano a un decreto ingiuntivo — e vanno in cima alla timeline come uscite "a giorno 0" (data passata clampata a oggi, vedi §6.5).

**L'importo NON è `netto_a_pagare`.** È il residuo dopo i pagamenti parziali:

```txt
residuo = netto_a_pagare − Σ(pivot fattura_scrittura WHERE tipo = 'pagamento')
```

Una query naïf su `fatture_passive` ignora i pagamenti parziali e sovrastima lo scoperto. Usare il pivot `fattura_scrittura`, filtrando per `stato_pagamento` ∈ (`aperta`, `parziale`) — questo filtro **esclude già le fatture stornate** (stato terminale `'stornata'`). Per il trattamento dei debiti pregressi vedi §6.7.

**Anomalia: scadenza mancante.** Una fattura aperta con `data_scadenza` NULL è invisibile alla previsione di cassa: un'uscita non conteggiata = falso semaforo verde. Il widget **non deve scartarla in silenzio** — deve elencarla a parte come anomalia (*"N fatture senza data di scadenza"*), perché è esattamente il buco che genera un'ingiunzione quando nessuno se ne accorge.

**Parametro fornitore "calcolo automatico scadenza".** Il widget **non lo consuma**: quel parametro è un input di `FatturaPassivaService` al momento della registrazione (scadenza = data documento + giorni). Quando il widget legge la fattura, `data_scadenza` è già persistita. Il parametro *popola*, il widget *legge*. Diventerà rilevante in v1.22 con le fatture ricorrenti — vedi §11.

### 6.3 Incassi attesi

Rate emesse ai condòmini, non ancora incassate, con scadenza nella finestra. Sono **incerti** (dipendono dalla puntualità dei condòmini): per questo alimentano solo lo scenario ottimistico.

### 6.4 Liquidità disponibile vs vincolata

Il fondo riserva è denaro **legalmente vincolato**: non va conteggiato come copertura libera.

- **Limite noto dell'MVP:** la separazione precisa dipende da Voci Accantonamento + `fondo_target_id`, in roadmap a **v1.10**.
- **Strategia MVP:** calcolare `liquiditaTotaleCents`; impostare `liquiditaVincolataCents = 0` e `liquiditaDisponibileCents = liquiditaTotaleCents`; mostrare nel widget un avviso non bloccante *"il calcolo non distingue ancora i fondi vincolati"* se al condominio risulta associato un fondo riserva.
- **Trigger di rifinitura:** alla chiusura di v1.10 Voci Accantonamento, popolare `liquiditaVincolataCents` realmente.

### 6.5 Timeline e giorno dello scoperto

Costruire una lista di eventi di cassa datati (+incassi attesi, −fatture in scadenza), ordinarli per data, cumulare partendo dalla liquidità disponibile, e individuare il **primo giorno in cui il cumulato scende sotto zero**.

- `scenarioPessimisticoCents`: timeline con **solo** le uscite (nessun incasso).
- `scenarioOttimisticoCents`: timeline con uscite + tutti gli incassi attesi.
- `giornoScopertoPrevisto`: primo giorno negativo nello scenario pessimistico (è il "tra 18 giorni" del messaggio).

### 6.6 Livello semaforo

| Livello | Condizione |
|---|---|
| 🟢 `verde` | Anche lo scenario pessimistico resta ≥ 0 per tutta la finestra. |
| 🟡 `giallo` | Pessimistico va sotto zero, ma l'ottimistico (incassi inclusi) resta positivo. |
| 🔴 `rosso` | Anche lo scenario ottimistico va sotto zero. |

Il giallo è importante: dice "se i condòmini pagano sei a posto, altrimenti no" → suggerisce naturalmente l'azione "sollecita incassi".

### 6.7 Esclusioni e segregazioni

**Fatture stornate.** Lo storno di una fattura registrata per errore forza `stato_pagamento = 'stornata'` (stato terminale; esiste anche il flag `is_stornata` in `dati_extra`). Quindi il filtro di inclusione `stato_pagamento ∈ (aperta, parziale)` di §6.2 le **esclude già**: nessun filtro aggiuntivo necessario. Il widget filtra sulla **colonna** `stato_pagamento` (indicizzata) e **non** sul JSON `dati_extra` — più veloce in una query aggregata di dashboard.

Distinguere comunque due "storni" che concettualmente **non** sono la stessa cosa:

| Tipo di storno | Effetto sul widget |
|---|---|
| **Storno di fattura** (registrata per errore) | `stato_pagamento = 'stornata'` → esclusa dal filtro di §6.2. |
| **Storno di pagamento** (`storno_pagamento_fornitore`) | La fattura resta `aperta`/`parziale` e torna scoperta → **resta** come uscita; gestita dal residuo (§6.8). |

**Debiti pregressi — segregazione, non esclusione.** Il DB ha il flag `is_pregresso`. Un debito pregresso è quasi sempre *vecchio*: la sua `data_scadenza` è nel passato, quindi la regola "scadute + in scadenza" (§6.2) lo trascinerebbe tutto nel bucket "giorno 0". Ma per un pregresso scaduto la scadenza è un **fatto storico**, non un segnale di quando uscirà davvero la cassa: lasciarlo guidare il semaforo lo manderebbe in rosso fisso (alert fatigue). Regola:

- `is_pregresso` **+ scadenza futura** → uscita normale nella timeline (impegno di cassa reale, es. piano di rientro concordato).
- `is_pregresso` **+ scaduto** → estratto dalla timeline operativa e mostrato a parte (`debitiPregressiScadutiCents`, vedi §5.1). Visibile, ma **non guida** il semaforo a 30gg.

Il widget risponde così a due domande distinte: *"la gestione corrente è sotto controllo?"* e *"quanto pesa l'eredità pregressa?"*.


### 6.8 Interazione con il modulo Pagamenti Fornitori

Il Treasury Guardian è **consumatore in sola lettura**: non scrive mai pagamenti, pivot o scritture. Due punti di correttezza:

1. **Il residuo netta anche gli storni di pagamento**, non solo i pagamenti:
   ```txt
   residuo = netto_a_pagare − Σ(pagamenti) + Σ(storni di pagamento)
   ```
   Se gli storni sono pivot `fattura_scrittura` a importo negativo, la somma algebrica li gestisce già; verificare il segno nello schema.
2. **Timing = `data_valuta`, non `data_pagamento`.** Per una previsione di cassa conta quando il denaro lascia il conto. Un bonifico già preparato in una Distinta Pagamento ma non ancora eseguito è un'uscita *futura*, non una liquidità già ridotta.

### 6.9 Sforamenti, rate integrative e conguagli

Una fattura in sforamento di preventivo genera un classico **disallineamento di tempi**: l'uscita verso il fornitore è a breve, il recupero dai condòmini arriva dopo. È esattamente il rischio che il widget deve intercettare.

- **Uscita** — una fattura in sforamento è comunque una fattura da pagare alla sua scadenza, a prescindere da ratifica assembleare o piano rate. Si conteggia normalmente.
- **Copertura via rate integrative** — se il piano rate integrativo è stato emesso, quelle rate sono `incassi attesi` come ogni altra rata (§6.3) e alimentano lo scenario ottimistico.
- **Copertura via conguaglio** — il recupero arriva al consuntivo, quasi sempre **fuori** dalla finestra 30gg: il widget mostrerà l'uscita scoperta nel breve. Corretto, non un bug — entro 30 giorni quella cassa non rientra.
- **Limite MVP** — il widget lavora in **aggregato** (uscite totali vs incassi attesi totali), non sa collegare "questa fattura in sforamento ↔ queste rate". Il link `rate_quote.riga_fattura_id` è NULL fino a v1.11 (Recupero Crediti). La copertura per-fattura è un `// TODO(v1.11)`.

---

## 7. Frontend

### 7.1 `TreasuryGuardianWidget.vue`

Card per la dashboard del condominio. **Componente di sola presentazione**: riceve il DTO già calcolato, non conosce le regole di rischio.

Prop `treasury` (shape):

```ts
{
  livello: 'verde' | 'giallo' | 'rosso',
  liquiditaDisponibileCents: number,
  uscitePredittiveCents: number,
  giornoScopertoPrevisto: string | null,
  scopertoMaxCents: number,
  fattureInScadenza: Array<{ fornitore, numero, dataScadenza, importoCents }>,
  morositaImpattanti: Array<{ immobile, condomino, importoCents }>,
  azioniSuggerite: Array<{ tipo, label, descrizione, impattoCents, route }>,
}
```

Layout:

- Riga di intestazione semaforica:
  - 🟢 *"Situazione stabile"*
  - 🟡 *"Copertura a rischio se gli incassi tardano"*
  - 🔴 *"Possibile scoperto entro {N} giorni — {importo}"*
- Quattro indicatori sintetici: Liquidità disponibile · Fatture 30gg · Rischio liquidità · Morosità critiche.
- Sezione **"Perché"** (causa): fatture in scadenza + morosità impattanti.
- Azioni suggerite come righe (`TreasuryActionRow.vue`), ognuna con il proprio impatto numerico.

> Tutti gli importi arrivano in **cents**: formattare in euro solo nel layer di presentazione.

### 7.2 `Dashboard.vue`

- Rimuovere `<PlaceholderPattern>`.
- Inserire `<TreasuryGuardianWidget :treasury="treasury" />` nella griglia superiore.

---

## 8. Le funzioni "intelligenti" — MVP vs rinviato

### Nell'MVP

| Funzione | Cosa fa |
|---|---|
| **Previsione con *quando*** | Giorno dello scoperto, non solo sì/no. |
| **Banda di incertezza** | Scenario ottimistico vs pessimistico — onestà sull'incertezza delle morosità. |
| **Spiegazione causale** | Quali fatture e quali morosità generano il rischio. |
| **Suggerimenti come link** | CTA verso Wizard Solleciti / dettaglio fatture **pre-contestualizzate** (no motori automatici). |

### Rinviato a v1.10

| Funzione | Note |
|---|---|
| **Dynamic Liquidity Simulator** | What-if interattivo ("se pago queste 3 ora..."). Già in roadmap v1.9.1 Payment Sentinel. |
| **Proattività via Admin Inbox** | Su `FatturaRegistrata`, se emerge uno scoperto previsto, push dell'alert nell'Admin Inbox tramite il sistema di eventi esistente. |
| **Giroconto Fondo Riserva 1-click** | Dipende da Bilanciatore Fondi (v1.10). Tocca soldi vincolati: serve copertura deliberativa. |
| **Anomaly detection** | Fattura sopra la media storica del fornitore, concentrazione anomala di scadenze. |
| **Separazione fondi vincolati reale** | Dipende da Voci Accantonamento (v1.10). |

### Il guardrail (vale sempre)

Tutte le funzioni intelligenti restano **advisory con override**, mai autonome — coerente con la linea Fiscal Sentinel (soft warning + override). L'assistente **propone, spiega, pre-compila**; l'amministratore decide. Un assistente che muove soldi da solo è un rischio legale, non una feature.

Ordine delle azioni suggerite, per fattibilità: prima recuperare incassi propri (solleciti) → poi spostare fondi (giroconto, con vincolo segnalato) → mai per primo ritardare un fornitore (le utenze hanno conseguenze).

---

## 9. Test (Pest)

`tests/Feature/Treasury/TreasuryGuardianServiceTest.php`, con `RefreshDatabase`.

Casi minimi da coprire:

1. **Scenario verde** — liquidità ampia, nessuna fattura imminente → `livello = 'verde'`, `giornoScopertoPrevisto = null`.
2. **Scenario rosso** — liquidità insufficiente anche con incassi → `livello = 'rosso'`, scoperto e giorno calcolati.
3. **Scenario giallo** — pessimistico negativo, ottimistico positivo.
4. **Residuo corretto** — fattura con pagamento parziale: l'uscita predittiva usa il residuo, non `netto_a_pagare`. *(Test chiave: previene il bug più probabile.)*
5. **Filtro `gestione_id`** — uscite/liquidità di una sola gestione non si mescolano con le altre.
6. **Finestra temporale** — fattura oltre i 30 giorni esclusa dalle uscite predittive.
7. **Fattura già scaduta** — fattura aperta con `data_scadenza` nel passato → inclusa nelle uscite, posizionata a "giorno 0" della timeline. *(Test chiave: è lo scenario "ingiunzione".)*
8. **Scadenza mancante** — fattura aperta con `data_scadenza` NULL → non conteggiata nelle uscite ma riportata come anomalia, non scartata in silenzio.
9. **Fattura stornata** — fattura registrata per errore, `stato_pagamento = 'stornata'` → esclusa dalle uscite dal filtro di stato (§6.2).
10. **Storno di pagamento** — fattura pagata e poi con pagamento stornato → torna a comparire come uscita; il residuo netta il pagamento e ri-somma lo storno.
11. **Debiti pregressi** — `is_pregresso` scaduto → finisce in `debitiPregressiScadutiCents`, non in `uscitePredittiveCents`, e non cambia `livello`; `is_pregresso` con scadenza futura nella finestra → conteggiato come uscita normale.

Promemoria ambiente di test: SQLite in-memory; guardare con `DB::getDriverName() === 'mysql'` eventuale sintassi MySQL-specifica; `Event::fake()` su `FatturaRegistrata` se i factory innescano listener.

---

## 10. Checklist di implementazione

- [ ] Domande aperte (§3): tutte e 6 risolte — nessun blocco residuo.
- [ ] Verificare/estendere `LiquidityForecastService` con metodo a finestra parametrica.
- [ ] `TreasuryStatus` + `AzioneSuggerita` (DTO readonly).
- [ ] `TreasuryTimelineBuilder`.
- [ ] `TreasuryGuardianService::perCondominio()` — con esclusioni §6.7 e residuo §6.8.
- [ ] *(Opzionale)* Contratto `DashboardWidget`.
- [ ] `DashboardController` — sostituire eventuale logica con la sola chiamata al service.
- [ ] `TreasuryGuardianWidget.vue` + `TreasuryActionRow.vue`.
- [ ] `Dashboard.vue` — rimuovere placeholder, integrare il widget.
- [ ] Test Pest (11 casi sopra).
- [ ] Avviso non bloccante "fondi vincolati non ancora separati" se presente fondo riserva.

---

## 11. Note di roadmap

- La **pagina globale multi-condominio** (control room / centrale operativa) sarà composizione sopra `perCondominio()`. Naming da decidere: **evitare "Sentinel" e "Radar"** — già usati in roadmap (Anti-Fraud IBAN Sentinel, Radar Salute Contabile).
- Quando arriva v1.10 (Voci Accantonamento, Bilanciatore Fondi): popolare `liquiditaVincolataCents`, attivare il giroconto fondo riserva, abilitare il Dynamic Liquidity Simulator.
- `// TODO(v1.x)` — **convergenza dei motori**: rifattorizzare `LiquidityForecastService` perché consumi `TreasuryTimelineBuilder`, così forecast 30gg e 90gg condividono un solo primitivo.
- `// TODO(perf)` — se la pagina globale multi-condominio risulta lenta in produzione, valutare cache del saldo o materialized view; finché non misurato, calcolo dal giornale.
- `// TODO(v1.22)` — con le fatture ricorrenti, usare il parametro fornitore "calcolo automatico scadenza" (scadenza = data documento + giorni) per **proiettare** nella timeline anche le fatture attese ma non ancora registrate.
- `// TODO(v1.11)` — **copertura per-fattura**: quando `rate_quote.riga_fattura_id` sarà popolato (Recupero Crediti), collegare le rate integrative alla fattura in sforamento che coprono, distinguendo il deficit *strutturale* da quello solo di *timing*.
- La proattività via Admin Inbox riusa il sistema di eventi `FatturaRegistrata → listener` già esistente: nessuna infrastruttura nuova, solo un nuovo listener.