# Fondo Accantonato e quadratura dello Stato Patrimoniale

<!-- rettifica -->
> ⚠️ **Non è più vero — verificato il 31/07/2026 su 1.10.0-beta.32.** Il servizio esiste, calcola attivo/passivo/risultato, espone `sbilancio` e `quadra`, ed è già consumato da due chiamanti.
> *Prova:* app/Services/Gestionale/StatoPatrimonialeService.php:35-94 (`calcola()` restituisce `sbilancio`/`quadra`; riga 70 `$sbilancio = $totaleAttivo - $totalePassivo - $risultato`); usato in app/Http/Controllers/Gestionale/Movimenti/ScritturaContabileController.php e app/Services/Gestionale/SpesaPerVoceService.php. Nessuna rotta/pagina dedicata (cercato `stato-patrimoniale` su routes/ = 0), ma il servizio non è "solo roadmap".
<!-- /rettifica -->

<!-- verifica-documentazione -->
> **Stato:** Contiene affermazioni false — verificato il 31/07/2026 su 1.10.0-beta.32, riverificato il 01/08/2026 su 1.10.0-beta.33
> Le decisioni (§2, §4bis, §4ter, §10) e il modello contabile (§3) restano validi e sono la fonte del design; la diagnosi al presente di §1, §7 e §8 è invece superata — enum `apertura`/`accantonamento`, netting del già-versato e `StatoPatrimonialeService` sono stati implementati fra beta.25 e beta.27, quindi non leggere quelle sezioni come stato del codice.
<!-- /verifica-documentazione -->

**Stato:** Proposta di design — non approvata, non pianificata su una versione specifica.

> ✅ **Il «buco A» del §1 è chiuso dalla beta.45 (05/08/2026), su entrambi i lati.** Il documento
> lo descriveva come stato ma dava per chiuso il percorso che lo genera: non lo era. Un saldo di
> apertura scritto **in modifica** non finiva a giornale — `UpdateCassaAction` salvava la colonna
> e non chiamava mai `RegistraAperturaCassaAction` — quindi il buco continuava a nascere.
> Ora: la modifica registra l'apertura come già faceva la creazione; la diagnosi del Libro
> Giornale espone un pulsante che la registra sulle casse rimaste indietro (con esito tipizzato:
> sei casi, due dei quali non sono errori); la diagnosi guarda anche i saldi **negativi**, che
> prima ignorava pur sbilanciando; e l'eliminazione della cassa — che faceva sparire il buco
> insieme alla liquidità — è impedita con il motivo e il rimedio.
> *Prova:* `app/Actions/Cassa/UpdateCassaAction.php`, `app/Enums/EsitoAperturaCassa.php`,
> `Cassa::motivoBloccoEliminazione()`, `tests/Feature/Gestionale/AperturaCassaInModificaTest.php`.
**Verificato su:** codice reale, branch `v1.10.0-beta.20/24` (indagine multi-agente su riparto,
emissione, incasso, creazione cassa/fondo, coverage engine).
**Origine:** caso di supporto forum (migrazione con fondo lavori già raccolto + fattura di variante),
luglio 2026. Seguito diretto di [`rettifiche_saldo_in_corso_gestione.md`](rettifiche_saldo_in_corso_gestione.md).
**Documenti collegati:** [`architettura_saldi_iniziali.md`](architettura_saldi_iniziali.md) (ADR-001),
[`v1.10_rateazione_origine.md`](v1.10_rateazione_origine.md), [`roadmap.md`](roadmap.md)
(§v1.10 "Stato Patrimoniale operativo", "Voci di accantonamento"; §v1.17 Year End Master).

> **Perché questo documento esiste.** Kondomanager oggi *non* squadra visibilmente, ma solo
> perché lo Stato Patrimoniale non è ancora stato costruito. Nel momento in cui lo si costruisce
> (deliverable v1.10), **non quadrerà** per qualunque condominio che porti dentro un fondo
> accantonato — via migrazione oggi, o via chiusura esercizio domani. È un problema di
> **modello contabile**, non di UI, e va risolto prima di rilasciare lo SP: uno SP che non
> chiude è il modo più rapido per perdere la fiducia degli amministratori.

---

## 1. Il caso e i due buchi

**Scenario.** Un condominio ha un fondo lavori straordinari di **€1.000** raccolto dai condòmini
per millesimi in un periodo precedente (o migrato da altro gestionale). I soldi sono fisicamente
in banca. Poi arriva la fattura dei lavori di **€1.100** (variante). Il risultato corretto atteso:

- Sullo Stato Patrimoniale: €1.000 in banca con contropartita di **fondo vincolato sul passivo**.
- Nel riparto della fattura: a ciascun condòmino si chiede **solo il residuo scoperto**
  (€100 × millesimi), perché €1.000 × millesimi è **già stato versato**.
- Nessuna squadratura, nessun doppio addebito.

Oggi si generano invece **due buchi indipendenti da €1.000 che non si compensano**.

### Buco A — apertura senza contropartita (esiste già alla migrazione)

Il `saldo_iniziale` di una cassa è **una colonna intera in centesimi sulla tabella `casse`**, non
una scrittura in partita doppia (`CreateCassaModelAction.php:19`,
`create_casse_table` migration). Il saldo cassa è **ibrido**:
`saldo = saldo_iniziale (colonna) + Σ DARE − Σ AVERE (ledger)` (`SaldoCassaService.php:45`). La
quota `saldo_iniziale` non è backed da alcuna riga di partita doppia.

Un fondo (`Cassa` `tipo = fondo`, `sottotipo_fondo = vincolato_lavori`) ha il suo conto contabile
creato **sempre come `tipo = attivo`, `categoria = liquidita`, figlio del mastro
`1010 Disponibilità Liquide`** (`CreateCassaAccountAction.php:60`), dichiarato esplicitamente come
**partizione dell'unico c/c reale, non una passività** (`SaldoCassaService.php:13`).

→ I €1.000 entrano come **pura attività, senza AVERE di contropartita**: un **patrimonio netto
implicito, mai registrato**. Non intercettato perché:
- l'enum `apertura` (`TipoMovimentoContabile.php:61`) che scriverebbe `DARE banca / AVERE fondo` a
  t0 **esiste ma è morto** (nessuna scrittura lo istanzia);
- il `DoubleEntryValidator` controlla solo `dare = avere` della **singola scrittura**, mai
  `Attività = Passività + Netto`;
- il trial-balance diagnostico legge solo `righe_scritture` (`TestContabilitaController.php:113`),
  quindi vede quella banca a **0** mentre `SaldoCassaService` la vede a **1.000** — l'incoerenza
  non emerge da nessuna parte.

### Buco B — doppio addebito nel riparto

`CalcoloQuoteService` ripartisce l'importo **lordo** del conto sui millesimi (`spesa × millesimi`)
**senza sottrarre nulla di già versato** (`CalcoloQuoteService.php:450`). Non esiste il concetto di

<!-- rettifica -->
> ⚠️ **Non è più vero — verificato il 31/07/2026 su 1.10.0-beta.32.** La riga 450 oggi è il docblock di `addebitaDiretto()`. Tutti i riferimenti file:riga del documento sono ancorati a beta.20/24 e sono derivati.
> *Prova:* app/Services/CalcoloQuoteService.php:445-455.
<!-- /rettifica -->
"già versato per capitolo/spesa": l'unico `importo_pagato` è per singola quota-rata in fase di
incasso (`StoreIncassoRateAction.php:137`), **mai reimmesso nel riparto** di una nuova fattura.

→ La fattura da €1.100 viene ripartita **€1.100 × millesimi**, non €100. All'emissione del piano
integrativo si scrive `DARE crediti_condòmini` per l'intero importo
(`EmissioneRateController.php:121`): il doppio addebito diventa **credito reale a mastro**. Se i
condòmini ripagano, la banca sale a ~€2.100 per una spesa di €1.100; se non ripagano, restano
€1.100 di crediti scoperti.

La **copertura** (`fattura_coperture`, strategia `fondo_riserva`) **non chiude il buco B**: è un
registro informativo, "coperture ≠ contabilità" (`FatturaPassivaService.php:199`). Evita lo *sforo
di budget*, non il *doppio addebito*. Nel pregresso la riga `DARE` va su `passate_gestioni`, non
sul fondo (fix beta.19, `FatturaPassivaService.php:316`), proprio perché il fondo è un conto
*attivo* e non può fare da contropartita passiva.

**I due buchi non si compensano** e nessuno dei due validator esistenti li segnala.

---

## 2. La radice: manca il primitivo — ma NON è (solo) il "Fondo"

**Revisione rev.2 (post-discussione).** Il primo taglio di questo documento chiamava il primitivo
mancante "Fondo Accantonato". È una lente troppo stretta e, per certi condomìni, **giuridicamente
scorretta**: non si può attribuire un vincolo di fondo che l'assemblea non ha mai deliberato.

Il primitivo base è più neutro:

> **Contributi riscossi dai condòmini e non ancora spesi su una spesa/gestione deliberata** — sullo
> Stato Patrimoniale una posta del **passivo/netto** (avanzo di gestione, di competenza dei
> condòmini, conguagliabile), con un **ledger per-condòmino del già-versato**.

Il **Fondo** (speciale ex art. 1135 c.c. n.4, o di riserva) è il **caso speciale deliberato**: lo
stesso fatto contabile, con in più un **vincolo di destinazione** e la sua governance. Tre situazioni
reali, **stessa meccanica contabile**, etichetta e vincolo diversi:

| Situazione | Natura della posta | Vincolo | Granularità naturale |
|---|---|---|---|
| Fondo speciale ex art. 1135 (deliberato, per l'opera) | Fondo lavori (riserva vincolata) | sì, per opera | **per voce di spesa** |
| Fondo di riserva (deliberato, generico) | Fondo di riserva | sì, generico | **per gestione/condominio** |
| Rate riscosse su lavori/gestione, **senza fondo esplicito** | Avanzo di gestione (debito v/condòmini) | no | **per voce di spesa** |

Nota legale: per la **straordinaria** il fondo speciale è obbligatorio (art. 1135 c.c. n.4,
costituibile progressivamente per SAL), quindi il riscosso su lavori straordinari *è*
funzionalmente il fondo speciale anche se il vecchio gestionale non l'ha etichettato —
rappresentarlo come fondo vincolato è corretto. Per l'**ordinaria**, o dove nessun fondo è stato
deliberato, è un **avanzo di gestione**, e inventare un fondo sarebbe scorretto.

Quello che manca è lo stesso nei tre casi: (1) la **contropartita sul passivo**, (2) il **ledger
per-condòmino del già-versato**, (3) il **draw-down sul riparto**. Quello che esiste oggi — il
**fondo come partizione attiva del c/c** via giroconto (beta.19, `RegistraGirocontoAction.php:206`)
— è solo la *faccia liquidità*, non la faccia contabile.

Nel resto del documento "Fondo Accantonato" va letto come **il caso vincolato del primitivo generale
"riscosso-non-speso / avanzo"**: la partita doppia (§3), il fix del riparto (§4), le invarianti (§7)
e i mattoni (§8) valgono identici per l'avanzo non vincolato — cambia solo il conto di contropartita
(riserva vincolata vs debito v/condòmini) e l'applicabilità delle regole di governance del vincolo.

### Le due facce di un fondo/avanzo (il cuore del design)

Un fondo accantonato ha **due nature che vanno rappresentate separatamente**:

| Faccia | Domanda a cui risponde | Oggi | Corretto |
|---|---|---|---|
| **Liquidità (attivo)** | *Dove* sono fisicamente i soldi? | c/c unico, opz. earmark via cassa-fondo (giroconto) | invariato — resta sull'attivo |
| **Vincolo (passivo/netto)** | *A cosa* sono impegnati e *chi* ha contribuito? | **assente** | **posta di passivo vincolato + ledger per-condòmino** |

Il buco A è esattamente **l'assenza della faccia-vincolo**: i soldi hanno un "dove" (banca) ma
nessun "a cosa" registrato in partita doppia. Il buco B è **l'assenza del ledger per-condòmino
del già-versato** letto dal riparto.

> **Nota sul non-doppio-conteggio della liquidità.** La money vive in **un solo posto** (il c/c,
> attivo). Il Fondo Accantonato **non** è un secondo asset: è la posta di **passivo** che dice
> "€1.000 di quel c/c sono vincolati a lavori". La cassa-fondo attiva della beta.19 (partizione via
> giroconto) resta valida come **vista di tesoreria** ("quanta liquidità è earmarkata"), ma **non è
> la contropartita contabile del vincolo** — quella è la posta di passivo. Vedi §6 per la
> riconciliazione con beta.19.

---

## 3. Ciclo di vita in partita doppia

Convenzione: importi in centesimi, penny-perfect (principi roadmap §2-3).

### 3a. Raccolta del fondo *dentro* Kondomanager (rate di accantonamento)

- **Emissione quota di accantonamento:** `DARE Crediti v/condòmini / AVERE Fondo lavori (passivo)`.
  Diversa dall'emissione ordinaria (`AVERE gestione_rate 3001`): qui la contropartita è il **fondo
  vincolato**, non il ricavo generico di gestione.
- **Incasso:** `DARE Banca / AVERE Crediti v/condòmini`.
- **Effetto netto:** Banca ↑, Fondo-passivo ↑, e il ledger per-condòmino registra il **già-versato**
  di ciascuno (cella `condòmino × fondo`).

### 3b. Fondo *migrato* (già raccolto altrove) — il caso del forum

Non si replica la raccolta rata-per-rata. Si inietta **una scrittura di apertura** (finalmente si
istanzia l'enum `apertura` oggi morto):

```
DARE  Banca (1010.xx)            €1.000
AVERE Fondo lavori (passivo)     €1.000        tipo_movimento = apertura
```

Questa **singola scrittura bilanciata** fa due cose insieme:
- dà alla banca il suo saldo come **valore backed da ledger** (non più solo colonna) → **chiude il
  buco A**;
- crea la **contropartita passiva** del vincolo.

In parallelo si popola il **ledger per-condòmino di apertura del fondo** (Σ = €1.000), che è
**diverso dai Saldi Iniziali**: non è "residuo da riscuotere", è "già versato e vincolato" (vedi §5).

### 3c. Draw-down contro la fattura (la variante €1.100)

- **Registrazione fattura:** `DARE Costo lavori / AVERE Debiti v/fornitore` €1.100.
- **Pagamento:** `DARE Debiti v/fornitore / AVERE Banca` €1.100. (La banca va a −€100 finché non si
  incassa il residuo: è la reale tensione di cassa, corretta e visibile.)
- **Rilascio del fondo a copertura:** `DARE Fondo lavori (passivo) / AVERE Costo lavori` €1.000.
  Il fondo si **consuma** discaricando €1.000 del costo. Fondo-passivo → 0.
- **Nuovo riparto del residuo scoperto:** `DARE Crediti v/condòmini / AVERE Costo lavori` €100.
  Costo → 0 (interamente coperto: €1.000 fondo + €100 nuovo riparto).
- **Incasso del residuo:** `DARE Banca / AVERE Crediti` €100. Banca: −€100 + €100 = 0.

**SP finale:** Banca 0, Fondo 0, tutto chiuso. Ogni condòmino ha pagato €1.000 (in passato, nel
fondo) + €100 (ora) = €1.100 = esattamente la sua quota del costo di €1.100. **Nessun doppio
addebito. Quadra al centesimo.**

### 3d. Chiusura / residui

Se il fondo non è interamente consumato (spesa < fondo raccolto), il residuo del Fondo-passivo
resta come posta vincolata sullo SP fino a nuova delibera (restituzione ai condòmini o
ridestinazione) — coerente con il vincolo di destinazione (vedi
[`uso improprio del fondo cassa`] sul sito e la governance `sottotipo_fondo`/override già esistente).

---

## 4. Il fix del riparto (Buco B)

Il salto architetturale mancante — confermato dalla verifica — è **collegare il coverage engine
(che già conosce `fondo_id`) al motore di riparto (che oggi ignora tutto)**.

`CalcoloQuoteService`, per una spesa con copertura da fondo, deve calcolare per ciascun condòmino:

```
residuo_dovuto[condòmino] = (spesa_totale × millesimi[condòmino])
                          − copertura_fondo_attribuita[condòmino]
```

dove `copertura_fondo_attribuita[condòmino]` **non** è ricalcolata pro-quota al volo, ma **letta dal
ledger per-condòmino del fondo** (il già-versato di §3). Questo è essenziale, non un dettaglio:
se il fondo è stato raccolto su **millesimi diversi** da quelli della spesa corrente (caso raro ma
reale — es. tabella lavori ≠ tabella generale, o subentri in mezzo), sottrarre il contributo
**effettivo** di ciascuno è l'unico modo per non generare micro-squadrature per-condòmino.

Nel caso lineare (fondo raccolto sugli stessi millesimi della spesa):
`residuo = (spesa − copertura) × millesimi = €100 × millesimi`. Esatto.

Questo è precisamente ciò che lo spec `v1.10_rateazione_origine.md` chiama origine **`fondo`**
(contabilizzante) e le celle `rate_quota_origine`: la cella `(condòmino × target × natura)` **è** il
ledger del già-versato. Non è infrastruttura nuova rispetto a quello spec — è la sua ragion d'essere
contabile, resa esplicita.

---

## 4bis. Granularità: per-voce di spesa, con target flessibile

**Decisione (post-discussione): il riscosso-non-speso si aggancia alla voce di spesa (capitolo),
non solo alla gestione — con il target flessibile.**

Ragione contabile/legale: il riparto è per capitolo (ogni spesa ha la sua tabella e la sua
copertura) e il contributo di un condòmino è verso **opere/capitoli specifici deliberati**, non un
pentolone di gestione. Il fondo speciale ex art. 1135 è costituito *per l'opera*. Aggregare a
livello di gestione produrrebbe **cross-subsidy**: chi ha versato per la facciata non ha versato per
l'ascensore.

Ragione pratica (il payoff): con la chiave per-voce si ha **una sola gestione straordinaria, N voci,
ciascuna col suo riscosso-non-speso e la sua copertura**. Una variante su una voce pesca dal
già-versato *di quella voce*. È il "Carrello della Spesa" esteso al layer fondo/avanzo, e **risolve
alla radice la proliferazione di gestioni** che il lock `saldo_applicato` costringeva a creare
(vedi [`rettifiche_saldo_in_corso_gestione.md`](rettifiche_saldo_in_corso_gestione.md)).

<!-- rettifica -->
> ⚠️ **La premessa è cambiata — verificato il 01/08/2026 su 1.10.0-beta.33.** Il vincolo citato qui («il lock `saldo_applicato` costringeva a creare gestioni parallele») si è in buona parte dissolto: dalla beta.32 il lucchetto ha un titolare (`saldi.piano_rate_id`) e si riapre da sé alla cancellazione del piano; dalla beta.33 scatta all'**emissione**, non alla generazione, ed è **per singola riga**. Aggiungere una correzione a gestione avviata non richiede più una gestione parallela.
> **Cosa cambia per questo documento:** nulla nel design — la chiave per-voce resta giusta per le sue ragioni proprie (riscosso-non-speso per opera, varianti, art. 1130-bis). Cambia il *peso dell'argomento*: la proliferazione di gestioni non è più un problema aperto da risolvere, quindi non va usata come giustificazione principale del primitivo. **D6 resta confermata a maggior ragione.**
> *Prova:* app/Models/Saldo.php:82-89; app/Models/Gestionale/PianoRate.php:103-106; docs/rettifiche_saldo_in_corso_gestione.md (rettifica sotto la tabella dei due bisogni).
<!-- /rettifica -->

**Ma non è *sempre* per-voce.** Un fondo di riserva generico non è legato a un'opera → sta a livello
di **gestione/condominio**. Quindi il **target è flessibile** (`voce_di_spesa | gestione`), con:
- **default per-voce** per fondi lavori (art. 1135) e avanzi di straordinaria;
- **gestione-level** per la riserva generica.

La cella del ledger è `(condòmino × target × natura[fondo_vincolato | avanzo])`, dove `target`
mappa sul `fondo_target_id` della roadmap (una voce di spesa oppure una gestione). L'aggregazione a
gestione/condominio serve solo per la presentazione nello SP.

---

## 4ter. Collocazione UI

**Decisione: NON dentro Gestione Casse.** Metterlo tra le tipologie di cassa ripeterebbe l'errore
diagnosticato in §1-2 — confondere la *faccia liquidità* (attivo, cassa) con la *faccia vincolo*
(passivo, fondo/avanzo). La cassa-fondo beta.19 resta in Casse com'è (tesoreria/earmarking).

I tre punti di contatto UI:

1. **Onboarding/migrazione — "Posizioni di apertura" per voce**, come **estensione della famiglia
   Saldi Iniziali** (stesso concetto: "come parte questo condominio"), non delle Casse. Raccoglie:
   quanto è già stato riscosso e da chi, per voce, con la **qualificazione obbligatoria**
   *"fondo deliberato (vincolato) o rate già riscosse (avanzo)?"* (è una qualificazione giuridica: la
   dà la delibera, non il software — la si chiede all'amministratore). Schermata **distinta** dai
   Saldi Iniziali propriamente detti, che restano per morosi/residui da riscuotere.
2. **Copertura fattura** (`fattura_coperture` esiste già): estesa perché "copri con quanto già
   versato" **alimenti il netting del riparto** (§4), con l'admin che vede in chiaro *"di €1.100:
   €1.000 coperti dal già versato, €100 da ripartire"*.
3. **Stato Patrimoniale** (read): fondi vincolati e avanzi come poste del passivo, con drill-down per
   voce.

Il dettaglio schermo si disegna a modello dati fermo, non prima.

---

## 5. Riconciliazione con i Saldi Iniziali (scioglie il "cane che si morde la coda")

La circolarità emersa nella discussione ("per chiudere il buco devo passare dai Saldi Iniziali, ma
i Saldi Iniziali sono il primitivo sbagliato e per giunta lucchettati per-gestione") si scioglie
**separando due significati oggi sovrapposti**. Nella migrazione, la posizione di un condòmino
rispetto all'accantonamento 2025 si scompone in **due fatti distinti**:

| Fatto | Primitivo corretto | Lato SP |
|---|---|---|
| "Ha **già versato** €X nel fondo" | **Fondo Accantonato** — ledger per-condòmino (nuovo) | passivo (vincolo) + attivo (banca) |
| "Deve **ancora versare** €Y del fondo" (moroso sull'accantonamento) | **Saldo Iniziale** (esistente, corretto) | credito verso il condòmino |

Oggi, in assenza del Fondo Accantonato, il "già versato" è **invisibile** — o viene forzato dentro
il `saldo_iniziale` della cassa come liquidità indifferenziata (buco A), o non entra affatto. È
questa sovrapposizione la radice del problema, non il lock dei saldi.

Il `Saldo` resta ciò per cui è nato (ADR-001): **residuo da riscuotere**, per-condòmino o solidale
(Art. 63). Il Fondo Accantonato è un **primitivo distinto**, con il suo ciclo di vita, **decoupled
dal lock `saldo_applicato` della gestione**: iniettare un fondo di apertura non richiede di
riaprire la gestione, perché non è un saldo. Questo chiude anche la circolarità di
[`rettifiche_saldo_in_corso_gestione.md`](rettifiche_saldo_in_corso_gestione.md): quel documento
notava che "Saldi Iniziali è sovraccarico"; qui identifichiamo il sovraccarico principale
(fondo accantonato) e lo estraiamo in un primitivo proprio.

---

## 6. Riconciliazione con la cassa-fondo beta.19

La beta.19 ha introdotto il fondo come **partizione attiva del c/c**, con giroconti. **Non va
buttata** — va reinquadrata:

- La **cassa-fondo (attivo)** resta come **vista di tesoreria/earmarking**: "di questi €X in banca,
  €1.000 sono fisicamente destinati ai lavori". Utile per il Treasury Guardian e per non pagare
  altre spese con soldi vincolati.
- Il **Fondo Accantonato (passivo)** è la **contropartita contabile del vincolo** e la fonte del
  ledger per-condòmino.

Le due devono restare **coerenti** ma non doppie: la liquidità totale è **una sola** (il c/c). Va
deciso (decisione aperta §9) se:
- **(a)** il giroconto banca→fondo-attivo resta una riclassificazione *memo* dentro l'attivo, e il
  vincolo vero vive sul passivo; oppure
- **(b)** si abbandona la partizione-attiva e il fondo diventa **solo** posta di passivo, con
  l'earmarking derivato dal saldo del passivo. Più pulito contabilmente, ma tocca codice beta.19
  già rilasciato.

Raccomandazione preliminare: **(a)** in transizione (non rompe beta.19), con l'invariante che il
saldo della cassa-fondo attiva **non deve mai essere sommato** al saldo del c/c nel calcolo
dell'attivo totale (è già una sua partizione), e che il Fondo-passivo sia la sola posta letta dallo
SP. Da validare con un test di quadratura `A = P + N`.

---

## 7. Invarianti e validazione (oggi mancanti)

Il buco A non è intercettato perché manca il controllo patrimoniale. Serve introdurre:

- **INV-SP (quadratura patrimoniale):** `Σ Attività = Σ Passività + Σ Netto` sul perimetro di un
  esercizio/condominio. Oggi il `DoubleEntryValidator` verifica solo `dare = avere` della singola
  scrittura — necessario ma **non sufficiente**: un'apertura mancante lascia l'attivo sbilanciato
  senza violare alcuna singola scrittura.
- **INV-Fondo (copertura ≤ raccolta):** per ogni fondo, `Σ draw-down ≤ Σ accantonato`; il saldo del
  Fondo-passivo non va mai sotto zero.
- **INV-Ledger (per-condòmino):** `Σ_condòmini già-versato = saldo Fondo-passivo`.
- **INV-Riparto:** per una spesa coperta da fondo, `Σ_condòmini residuo_dovuto = spesa − copertura`.
- **Migrazione dei saldi cassa esistenti:** le casse migrate con `saldo_iniziale` colonna ma senza
  scrittura di apertura vanno **backfillate** con la scrittura `apertura` (§3b) contro una posta di
  netto/fondo appropriata, altrimenti lo SP nascerà già sbilanciato al primo rilascio. Backfill
  additivo, con query di verifica `A = P + N` obbligatoria a valle (pattern
  `v1.10_rateazione_origine.md` §10).

---

## 8. Mattoni riusabili vs da costruire

| Mattone | Stato | Ruolo nel Fondo Accantonato |
|---|---|---|
| Giroconto + scrittura (`RegistraGirocontoAction`) | **esiste** | tesoreria banca↔fondo (faccia liquidità) |
| Cassa `tipo=fondo` + `sottotipo_fondo` + governance/override | **esiste** | contenitore ed etichetta del vincolo; earmark attivo |
| `SaldoCassaService` come fonte unica | **esiste** | saldo attivo del fondo, affidabile |
| Coverage engine `fattura_coperture` (`fondo_id`, `pianificata`/`confermata`) | **esiste** | gancio naturale del **draw-down**; oggi non tocca il riparto |

<!-- rettifica -->
> ⚠️ **Non è più vero — verificato il 31/07/2026 su 1.10.0-beta.32.** Il netting del già-versato è implementato da beta.26/27: il riparto sottrae per immobile i `contributi_versati` della voce.
> *Prova:* app/Services/CalcoloQuoteService.php:794 (`$importiDistributi = $this->nettingGiaVersato($conto, $importiDistributi);`) e :822-830 (`private function nettingGiaVersato(...)` che legge `ContributoVersato::perImmobile(Conto::class, $conto->id)`). Tabella `contributi_versati` in database/migrations/2026_07_24_120000_create_contributi_versati_table.php.
<!-- /rettifica -->
| Piano dei conti radice `PASSIVO 2000` + conti-fondi `2301/3001` | **esiste (struttura)** | destinazione della posta di passivo; oggi nessuno la scrive per l'apertura |
| `Saldo`/Wallet + Rata 0 | **esiste, semantica diversa** | resta per i **residui**; NON per il già-versato |
| Enum `apertura`/`accantonamento`/`chiusura` | **dichiarati, morti** | i `tipo_movimento` per apertura/accantonamento/chiusura — basta istanziarli |

<!-- rettifica -->
> ⚠️ **Non è più vero — verificato il 31/07/2026 su 1.10.0-beta.32.** APERTURA e ACCANTONAMENTO sono istanziati da beta.25/beta.27. Solo CHIUSURA è ancora morto.
> *Prova:* app/Actions/Cassa/RegistraAperturaCassaAction.php:86 (`'tipo_movimento' => TipoMovimentoContabile::APERTURA`); app/Actions/Cassa/RegistraContributoInCassaAction.php:75 (`::ACCANTONAMENTO`); app/Models/Gestionale/Cassa.php:112,141 leggono le scritture di apertura. Cercato `TipoMovimentoContabile::CHIUSURA` su app/ = 0 risultati (quello sì ancora morto).
<!-- /rettifica -->
| `OrigineQuota` economico + `generaScritturaEmissione` | **solo spec** | discriminante emissione: la quota-fondo genera credito verso il fondo, non verso gestione_rate |
| `rate_quota_origine` / `piano_origine_scadenza` / `fondo_target_id` / `delibera_id` | **solo spec** | **il ledger per-condòmino del fondo** e la bifurcation incasso — da migrare da zero |
| Stato Patrimoniale (sezioni A/P/N, chiusura, `A=P+N`) | **solo roadmap v1.10** | dove il buco A diventerebbe visibile — e dove va intercettato |

Nessun mattone singolo manca in modo insormontabile: mancano **due connessioni** —
(1) una **scrittura di apertura/accantonamento** che dia al fondo una contropartita passiva reale, e
(2) il **link coverage↔riparto** via ledger per-condòmino.

---

## 9. Collocazione roadmap e dipendenze

Questo **non è un item nuovo**: è un **vincolo di correttezza su item v1.10 già previsti**. Va
trattato come prerequisito/constraint di:

- **"Stato Patrimoniale operativo con scritture di chiusura" (v1.10):** deve nascere con l'invariante
  `A = P + N` e con le scritture di **apertura** dei saldi cassa, altrimenti non chiude per i
  condomini migrati. **Questo è il gate: non rilasciare lo SP senza aver chiuso il buco A.**
- **"Voci di accantonamento con `fondo_target_id`, bifurcation incasso" (v1.10):** è il primitivo di
  §3-4. Deve includere il **ledger per-condòmino** (celle `rate_quota_origine` origine `fondo`) e il
  **link al riparto**, non solo la raccolta.
- **`v1.10_rateazione_origine.md`:** l'origine `fondo` contabilizzante è la stessa cosa; questo doc
  ne fissa la **semantica patrimoniale** (contropartita passiva + draw-down).

Se v1.10 è troppo carico, il **minimo indispensabile per non regredire** è: (i) scrittura di
apertura dei saldi cassa + INV-SP prima dello SP; (ii) il resto (ledger per-condòmino + netting
riparto) può seguire in v1.11 (Recupero Crediti / Motore Riparto Unificato), che già tocca
`rate_quota.origine` e i subentri.

---

## 10. Decisioni — stato

**Decise (rev.2, discussione luglio 2026):**
- **Gerarchia:** primitivo base = riscosso-non-speso/avanzo; il Fondo (art. 1135 / riserva) è il caso
  vincolato speciale (§2).
- **Granularità:** per voce di spesa, con target flessibile `voce | gestione` (§4bis).
- **UI:** non dentro Casse; estensione della famiglia Saldi Iniziali per le posizioni di apertura
  (§4ter).
- **D1 — Fondo su passivo puro vs partizione-attiva + passivo:** deciso **(a)** in transizione — la
  cassa-fondo beta.19 resta come vista di tesoreria, la posta di passivo è la verità contabile;
  invariante: il saldo cassa-fondo non va mai sommato al c/c nell'attivo totale. Pulizia a passivo
  puro rimandata (tocca codice beta.19 già rilasciato).
- **D3 — Ledger per-condòmino:** riuso di `rate_quota_origine` (celle origine `fondo`/`avanzo`), zero
  nuova infrastruttura.
- **D5 — Netting su contributo effettivo:** il riparto legge il **già-versato effettivo** per
  condòmino, non un pro-quota ricalcolato (necessario per subentri art. 63 e tabelle miste).
- **D6 — Indipendenza dal lock `saldo_applicato`:** confermata; il primitivo non passa dai Saldi e
  non riapre la gestione.

- **D7 — Ancoraggio della scrittura di apertura:** deciso **esercizio**, con `gestione_id` **nullable
  e lasciato vuoto** per le aperture di cassa. Motivo: la tabella `casse` non ha `gestione_id` — una
  cassa è di *condominio* — e il saldo di apertura di un c/c non appartiene né all'ordinaria né alla
  straordinaria; agganciarlo d'ufficio alla gestione ordinaria (solo perché la FK è NOT NULL)
  inquinerebbe i report per-gestione. L'esercizio è l'ancora giusta: auto-creato con il condominio
  (`CondominioService.php:79`), uno per anno, non eliminabile, con invariante "al più uno aperto".
  Regola generale: `gestione_id` valorizzato quando il fatto appartiene a una gestione (aperture di
  fondo/avanzo), nullo quando è di condominio (aperture di cassa). **Richiede migration** su una FK
  NOT NULL → da segnalare nel changelog.
  - ⚠️ *Conseguenza da gestire al flip:* `TreasuryGuardianService::calcolaLiquidita` filtra i

<!-- rettifica -->
> ⚠️ **Non è più vero — verificato il 31/07/2026 su 1.10.0-beta.32.** Già deciso e già scritto nel codice: le scritture con `gestione_id` nullo sono incluse, con commento esplicito che cita la beta.25.
> *Prova:* app/Services/Treasury/TreasuryGuardianService.php:163-172 (`$q->where('sc.gestione_id', $gestioneId)->orWhereNull('sc.gestione_id')`).
<!-- /rettifica -->
    movimenti per `sc.gestione_id` quando riceve una gestione. Con `gestione_id` nullo, l'apertura
    verrebbe esclusa da quella vista e la liquidità risulterebbe più bassa del reale. Va deciso se
    quel filtro debba lasciar passare le scritture senza gestione (probabile: la liquidità è del
    condominio, non della gestione).

**Ancora aperte (richiedono validazione esterna):**
- **D2 — Classificazione di bilancio della posta:** riserva vincolata (fondo) vs debito v/condòmini
  (avanzo) vs voce di netto. La *presenza* sul passivo è solida; l'esatta classificazione è **prassi
  contabile** — **validare con un commercialista** prima di cementarla nello schema DB.
- **D4 — Migrazione fondi/avanzi esistenti:** backfill automatico delle casse-fondo già in
  produzione vs procedura guidata di onboarding; e come qualificare (fondo vs avanzo) i dati storici
  — la qualificazione la dà la delibera, quindi va **chiesta all'amministratore**, non dedotta. Query
  `A = P + N` a valle obbligatoria in ogni caso.
- **D8 — Copertura su conti con ripartizione mista proprietario/inquilino** *(aperta in beta.27,
  revisione avversariale)*: la chiave di netting è per IMMOBILE (D5/design §4), ma un conto può avere
  `conto_tabella_ripartizioni` con più soggetti (es. 70% proprietario / 30% inquilino sulla stessa
  unità — grosse manutenzioni art. 1576 c.c.). Oggi il netting sottrae la copertura dal lordo
  aggregato dell'immobile PRIMA di spaccarlo fra i soggetti: un versamento fatto dal solo
  proprietario per la sua quota finisce per scontare anche l'inquilino, che non ha versato nulla —
  un errore potenzialmente legale, non solo contabile. Soluzione scelta per ora: **bloccare con
  avviso**, non correggere il calcolo. `ContributoVersatoController::edit()` rileva la ripartizione
  non-standard e la UI (`ContributiEdit.vue`) mostra un avviso esplicito invitando l'amministratore a
  verificare a mano; il motore (`CalcoloQuoteService::nettingGiaVersato`) logga lo stesso caso.
  Nessun cambio di schema. **Da riprendere**: se emergono casi reali, la soluzione corretta è
  scopare la copertura al soggetto (nuova colonna nullable `soggetto` su `contributi_versati`, con
  un campo in più nel form solo quando il conto ha ripartizione mista) — è un pezzo di lavoro a sé,
  non una correzione minore.

- **D8-bis — Il "già versato" (beta.26) non crea liquidità reale** *(implementata in beta.27)*:
  scoperto verificando lo scenario del forum con un test reale — dichiarare un già-versato non
  tocca nessuna cassa: `StatoPatrimonialeService` continua a riportare `quadra=true` a prescindere
  (garanzia strutturale `dare=avere` per singola scrittura, non un controllo economico "questi
  soldi esistono davvero da qualche parte"). È lo stesso **Buco A** di §1, riscoperto un livello più
  in alto: il già-versato descrive correttamente la storia contributiva dei condòmini nel riparto,
  ma non dice nulla su *dove* siano finiti quei soldi oggi. Due scenari reali, comportamento
  economico opposto:
  - **Scenario A — sono ancora fermi, mai spesi:** manca la stessa scrittura di apertura di §3b/beta.25
    (`RegistraAperturaCassaAction`), solo innescata da un evento diverso. Nuova azione dedicata
    `RegistraContributoInCassaAction`: stessa forma contabile (`DARE cassa selezionata / AVERE Fondo
    Passate Gestioni`), ma `tipo_movimento = accantonamento` (non `apertura`, per non toccare né
    essere toccata dalla guardia "una volta sola per cassa" di beta.25) e importo letto da parametro
    (il totale del già-versato), non da `casse.saldo_iniziale`.
  - **Scenario B — sono già stati spesi come acconto al fornitore, prima di Kondomanager:** nessuna
    liquidità da registrare — il già-versato resta corretto per il riparto, ma il debito v/fornitore
    che comparirà con la fattura reale non è ancora scontato di quell'acconto. Nessuna scrittura:
    solo un task Inbox (`EventoTipo::GIA_VERSATO_ACCONTO_DICHIARATO`) con la nota dell'amministratore,
    sullo stesso pattern di `SCOPERTO_DOCUMENTATO` — un promemoria d'audit, non un blocco.
  - **La domanda si pone una volta sola.** Alla prima dichiarazione di un già-versato > 0 per una
    voce, il salvataggio apre una modale che copre i due scenari (`ModalLiquiditaGiaVersato.vue`,
    stile radio-card come `ModalOverrideBudget.vue`) invece di limitarsi ad avvisare. La risposta
    (`liquidita_stato`/`cassa_id` su `contributi_versati`) va **rimandata ad ogni resave** — il
    controller sostituisce integralmente le righe della voce, quindi ometterla la cancellerebbe
    silenziosamente e la domanda ripartirebbe da capo. Un resave non deve mai né riaccreditare la
    cassa una seconda volta né riaprire un secondo task: il controller lo garantisce leggendo
    `whereNotNull('liquidita_stato')` **prima** della transazione di sostituzione.
  - **Non ancora chiuso — rimandato apposta:** far quadrare lo Scenario B quando arriva la fattura
    reale (netto del debito v/fornitore dell'acconto già versato) richiederebbe estendere l'enum
    `tipo_copertura` di `fattura_coperture` con un nuovo caso (es. `acconto_pregresso`). È il gancio
    naturale — stesso ruolo di `fondo_riserva`/`sopravvenienza` — ma tocca il sottosistema fatture in
    modo più ampio del solo già-versato: decisione a sé, da portare all'utente separatamente. Oggi
    lo Scenario B resta un promemoria di audit, verificato a mano.
  - **Bug trovato in verifica dal vivo (non nei test automatici):** il selettore cassa dello Scenario
    A (`vue-select` con `append-to-body`) calcolava la sua posizione assumendo il toggle nel flusso
    normale del documento, sommando `window.scrollY`. Questa modale è `position: fixed` — le
    coordinate del toggle sono già relative al viewport e non cambiano con lo scroll dello sfondo —
    quindi il menu finiva spinto fuori schermo di tutto l'offset di scroll: nel caso reale (form
    scrollato fino al pulsante Salva, l'unico modo per aprire questa modale), il dropdown si apriva a
    `top: 823px` su un viewport di `767px`, invisibile ma tecnicamente presente e selezionabile alla
    cieca. Fix con una `:calculate-position` custom, `position: fixed` sulle coordinate pure del
    toggle. Stesso pattern (`Teleport` + `v-select append-to-body`) è già usato altrove
    (`ModalOverrideBudget.vue`, `ModalSpesaImprevista.vue`) senza questo fix: bug latente pre-esistente,
    non introdotto qui, non ancora corretto in quei componenti — verificare se riproducibile lì prima
    di prioritizzarlo.

---

*Documento di design. Le strutture qui descritte sono proposte e vanno validate contro lo schema di
produzione prima dell'implementazione. Non modifica codice. Grounding: verifica multi-agente su
branch v1.10.0-beta.20/24 — riferimenti file:riga nel corpo.*

### Changelog del documento
- **rev.3 (2026-07-25):** aggiunta D8-bis — il già-versato (beta.26) non creava liquidità reale
  (stesso Buco A di §1, un livello più in alto); implementata la modale a due scenari
  (`ModalLiquiditaGiaVersato.vue`) con `RegistraContributoInCassaAction` per lo Scenario A e un task
  Inbox di audit per lo Scenario B. Quadratura dello Scenario B sulla fattura futura rimandata
  (richiede estendere `fattura_coperture.tipo_copertura`, decisione a sé).
- **rev.2 (2026-07-23):** riframing del primitivo — non più "Fondo Accantonato" come concetto
  universale, ma **riscosso-non-speso / avanzo di gestione** come base, con il Fondo (art. 1135 /
  riserva) come caso vincolato speciale (§2). Aggiunte: §4bis granularità per-voce con target
  flessibile; §4ter collocazione UI (fuori da Casse, famiglia Saldi Iniziali). Decisioni D1/D3/D5/D6
  chiuse; D2 (classificazione di bilancio) e D4 (backfill/qualificazione) restano aperte con
  validazione esterna (commercialista / delibera).
- **rev.1 (2026-07-23):** prima stesura — diagnosi dei due buchi (apertura senza contropartita +
  doppio addebito nel riparto), ciclo di vita in partita doppia, riconciliazione con beta.19 e
  Saldi Iniziali, invarianti, mattoni riusabili, collocazione roadmap.

<!-- rettifiche-non-ancorate -->

## ⚠️ Rettifiche non ancorate (31/07/2026)

Correzioni verificate sul codice che non è stato possibile agganciare a una riga precisa di questo documento. Valgono per l'intero testo.

- **Il documento afferma:** "Invarianti e validazione (**oggi mancanti**)... INV-SP (quadratura patrimoniale) ... Oggi il `DoubleEntryValidator` verifica solo `dare = avere`".
  **Realtà:** INV-SP è implementata: lo stesso ragionamento (necessario ma non sufficiente) è ora nel docblock del servizio che la calcola, e il servizio espone anche la `liquidita_non_contabilizzata` che genera lo sbilancio.
  *Prova:* app/Services/Gestionale/StatoPatrimonialeService.php:17-25 (docblock che cita esattamente il limite del DoubleEntryValidator) e :64-86.

<!-- /rettifiche-non-ancorate -->
