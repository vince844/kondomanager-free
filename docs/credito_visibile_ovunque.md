# Credito visibile ovunque — Iniziativa post v1.10.0-beta.9

<!-- verifica-documentazione -->
> **Stato:** Superato — verificato il 31/07/2026 su 1.10.0-beta.32, riverificato il 01/08/2026 su 1.10.0-beta.33, riverificato il 05/08/2026 su 1.10.0-beta.46
> Il piano in 4 punti è stato interamente realizzato e rilasciato: è sostituito dalla voce [1.10.0-beta.10] di docs/changelog.md e dal codice che ne è uscito (CreditoService, CreditiDaCompensareWidget, card credito in Estratto Conto, avviso cross-gestione).
> La beta.46 ha esteso i punti 3 e 4: `CreditoService` non dice più solo *quanto* credito c'è ma *quale rata copre* (chiave `compensabile`), e quella frase compare sia nel widget sia nella card di Estratto Conto — dove prende il posto del nome della gestione previsto dal punto 2 lettera (b). Vedi le rettifiche in linea sui punti interessati.
> Si può ancora leggere come motivazione delle scelte fatte, ma non come descrizione dello stato attuale. **Potato il 16/08/2026 su 1.10.0-beta.55:** le tre sezioni superate — il riquadro di stato in apertura, «Problema di dominio scoperto» e «Piano beta.10» — portano ora il loro avviso **in testa alla sezione**, perché chi arriva da una ricerca o da un link atterra lì e l'intestazione non la legge. L'unica parte ancora aperta resta «Differito — visibilità lato condòmino».
<!-- /verifica-documentazione -->

<!-- rettifica -->
> ⛔ **Questo riquadro è la fotografia di luglio 2026 e non vale più: potatura del 16/08/2026.** Il
> piano è stato realizzato per intero — vedi la voce `[1.10.0-beta.10]` del changelog e il codice
> che ne è uscito (`CreditoService`, `CreditiDaCompensareWidget`, la card del credito in Estratto
> Conto, l'avviso cross-gestione). Da qui in avanti il documento si legge come **motivazione delle
> scelte fatte**, non come stato del prodotto. L'unica parte ancora aperta è «Differito —
> visibilità lato condòmino», in fondo.
<!-- /rettifica -->

> Stato: in pianificazione (beta.10). Backend del credito già corretto in beta.9
> ([`changelog.md`](changelog.md#1100-beta9---crediti-visibili--castelletto-spendibile)).
> Qui si tratta solo di *visibilità* e di una regola di dominio ancora mancante
> (separazione gestioni), non di correttezza contabile.

## Origine

Caso segnalato sul forum (luglio 2026): un condòmino risultava "a credito" ma le
rate nuove comparivano come impagate. La beta.9 ha risolto il bug di fondo (quote
strapagate invisibili, eccedenza non spendibile, compensazioni a credito prive di
tracciabilità in lista/dettaglio). Discutendo del caso è emerso un problema più
ampio: il credito, anche quando disponibile, **vive solo dentro "Nuovo Incasso"**
quando l'amministratore cerca esplicitamente quell'anagrafica — non è visibile
altrove, quindi resta facile da dimenticare.

## Problema di dominio scoperto: credito cross-gestione

<!-- rettifica -->
> ✅ **Buco chiuso.** L'avviso cross-gestione esiste dalla beta.10; la sezione resta perché spiega
> **perché** il credito di una gestione non si spende in un'altra senza dirlo, che è la ragione per
> cui l'avviso ha quella forma.
<!-- /rettifica -->

<!-- rettifica -->
> ⚠️ **Il «solo» non descrive più il codice — verificato il 01/08/2026 su 1.10.0-beta.33.** Il «mai per `gestione_id`» resta vero, ma il filtro non è più soltanto `rata_id` + `anagrafica_id`: la beta.33 ha aggiunto un ripiego sul credito intestato a **un'altra** anagrafica, ammesso solo se le due condividono lo stesso immobile, e con annotazione obbligatoria sulla riga DARE. Prima quel ripiego era silenzioso — era il caso del coniuge che pagava per l'altro e del credito che spariva senza traccia.
> *Prova:* app/Actions/Gestionale/Movimenti/StoreIncassoRateAction.php:279-296 (guardia sull'immobile condiviso), :331 (annotazione della riga DARE) — riverificato il 05/08/2026 su 1.10.0-beta.46.
> ⚠️ **Numeri di riga:** le citazioni di questo documento verso `StoreIncassoRateAction.php` sono slittate di ~25 righe (la beta.33 ne ha aggiunte a monte). Cercare per **testo della nota** o nome del metodo, non per riga: la nota cross-gestione sta ora a :380-382 (verificato il 05/08/2026 su 1.10.0-beta.46), non a :348.
<!-- /rettifica -->

Verificato nel codice: `StoreIncassoRateAction` seleziona le quote-credito
filtrando solo per `rata_id` + `anagrafica_id`, mai per `gestione_id`. Nella UI,
col filtro "Tutte (automatica)" (default), righe di gestioni diverse convivono
nella stessa lista di Nuovo Incasso, e la distribuzione automatica non distingue
tra gestioni. Risultato: oggi è possibile (e già successo nei dati reali di un
cliente) compensare un debito della gestione **straordinaria** con credito
maturato sulla gestione **ordinaria**, silenziosamente.

Ordinaria e straordinaria hanno budget/conti spesso separati per norma — un
credito nato da un pagamento anticipato su lavori straordinari appartiene
concettualmente a quella gestione, non alla cassa generale. Va quindi introdotto
un controllo, ma **non bloccante**: la decisione finale resta all'amministratore
(caso reale: potrebbe esserci un accordo condominiale che lo autorizza).

## Piano beta.10

<!-- rettifica -->
> ✅ **Eseguito tutto, e in più.** I quattro punti sono usciti nella beta.10; la beta.46 ha esteso i
> punti 3 e 4 — `CreditoService` dice ora anche **quale rata** il credito copre. Si legge per
> capire cosa si voleva ottenere, non per sapere cosa manca: non manca niente.
<!-- /rettifica -->

1. **Avviso cross-gestione (non bloccante).** Quando la quota-credito e la
   quota-target appartengono a gestioni diverse, un banner giallo prima della
   conferma ("Stai usando credito ORDINARIA per una rata STRAORDINARIA,
   continuare?"). Alla conferma, la nota sulle righe `storno_credito` diventa
   esplicita ("Compensazione cross-gestione confermata dall'amministratore:
   [gestione credito] → [gestione rata]") — riusa il campo `note` già esistente
   e già mostrato in Estratto Conto (`riga.note`), senza bisogno di una tabella
   di log dedicata.
2. **Spaccato crediti per gestione.** Aggregazione di `RataQuote::credito_disponibile`
   raggruppata per `gestione_id`, mostrata (a) dentro il banner di avviso del
   punto 1 (es. "hai € 100 su ORDINARIA, € 50 su STRAORDINARIA") e (b) come card
   riassuntiva in Estratto Conto.
3. **Credito visibile in Estratto Conto.** Non esiste ancora una "scheda
   anagrafica" dedicata (`AnagraficaController::show()` è uno stub vuoto) — il
   credito va posizionato nella pagina già esistente e già linkata ovunque
   (`EstrattoContoAnagraficaController`), come nuova card accanto a Saldo
   Iniziale / Totale Addebiti / Totale Versamenti / Saldo Finale.

<!-- rettifica -->
> ⚠️ **La card c'è, ma non mostra più lo spaccato per gestione — verificato il 05/08/2026 su 1.10.0-beta.46.** Resta vero tutto il resto del punto 3 (`AnagraficaController::show()` è ancora uno stub vuoto, la card sta in Estratto Conto accanto alle altre quattro). Cambia però ciò che il punto 2 lettera (b) prometteva: fino alla beta.45 sotto l'importo compariva il nome della gestione («ORDINARIA e altre»), la beta.46 lo ha sostituito con la frase che dice **quale rata quel credito copre** e con un link «Compensa» che apre Nuovo Incasso già puntato su quella rata. Lo spaccato per gestione non è sparito: vive nel tooltip dell'icona accanto all'importo, che però compare solo quando le gestioni a credito sono più di una.
> Il link porta `prefill_anagrafica_id` e `prefill_rata_id`, e **non** `intent_usa_credito`: quel parametro dichiara una richiesta arrivata dal condòmino, mentre qui a muoversi è l'amministratore.
> *Prova:* app/Http/Controllers/Gestionale/PianiRate/EstrattoContoAnagraficaController.php:357-361 (`compensabile`, `compensabile_frase`, `compensabile_rata_id` nelle stats); resources/js/pages/gestionale/pianiRate/EstrattoContoAnagrafica.vue:87-94 (`urlCompensazione`) e :295-303 (frase + link, con il ramo «Nessun credito attivo»); app/Http/Controllers/Anagrafiche/AnagraficaController.php:141-144 (`show()` ancora vuoto).
<!-- /rettifica -->

4. **Widget dashboard "Crediti da compensare".** Nuovo `DashboardWidget` (stesso
   contratto di `TreasuryGuardianWidget`, registrato in `WidgetManager`): lista
   dei condòmini con `credito_disponibile > 0` nel condominio corrente, con link
   diretto a Nuovo Incasso pre-compilato (riusa il parametro `prefill_anagrafica_id`
   già supportato da `IncassoRateNew.vue`). Chiude il loop su crediti dimenticati
   (specialmente quelli da saldo iniziale, che possono restare inutilizzati per
   mesi se nessuno emette nuove rate compatibili).

<!-- rettifica -->
> ⚠️ **Il link non porta più solo l'anagrafica — verificato il 05/08/2026 su 1.10.0-beta.46.** Il widget esiste e si nasconde quando non c'è nulla da segnalare, ma dalla beta.46 ogni riga porta anche `prefill_rata_id`, cioè la rata bersaglio che quel credito copre, e sotto il nome del condòmino compare la frase che la nomina (o dice che non c'è nessuna rata aperta da coprire, in grigio corsivo: informazione, non invito). Non porta `intent_usa_credito`, per la stessa ragione detta al punto 3.
> Il bersaglio non è un'euristica del widget: viene dalla chiave `compensabile` di `CreditoService`, che spezza il credito in `origini` (da dove si preleva) e `rate_coperte` (cosa si paga), copre le rate in ordine di scadenza e — per scelta dichiarata — **non** attraversa le gestioni e **non** propone rate ancora in bozza.
> *Prova:* app/Services/Dashboard/Widgets/CreditiDaCompensareWidget.php:29-61 (costruzione del link e dei campi `compensabile_*`, `copre`, `rata_bersaglio_id`); app/Services/Gestionale/CreditoService.php:84-180 (`compensabile()`), :194-218 (`frase()`), :232-265 (`debitiAperti()`, con `stato != 'bozza'`); resources/js/pages/gestionale/dashboard/components/CreditiDaCompensareWidget.vue:48-55 (la riga «copre») e :59-61 (la nota in fondo).
<!-- /rettifica -->

## Differito — visibilità lato condòmino

<!-- rettifica -->
> ⚠️ **Differito sì, ma non da zero — verificato il 05/08/2026 su 1.10.0-beta.46.** Il condòmino un credito lo vede già, e lo può già chiedere: nel dettaglio dell'evento di scadenza c'è il riquadro «Il tuo Salvadanaio», che mostra l'importo, dice se copre tutta la rata e offre il pulsante «Sì, salda la rata con il credito» — è quello a spedire `intent_usa_credito` all'amministratore. Resta vero il resto del paragrafo: quella vista è limitata al credito della rata zero e **non** ha lo spaccato per gestione del punto 2.
> Una decisione tecnica, contro quanto dice l'ultima riga, ora c'è: la misura del credito lato condòmino è `RataQuote::credito_disponibile`, la stessa su cui decide il motore. Fino alla beta.45 lo snapshot sommava la colonna `importo` ignorando `importo_pagato`, e il Salvadanaio mostrava per intero un credito già speso.
> *Prova:* app/Listeners/Gestionale/SyncScadenziarioWithPianoRate.php:141-143 (la somma su `credito_disponibile`) e :230 (`credito_rata_zero` in `meta`); resources/js/components/eventi/EventDetailsDialog.vue:111-119 e :388-431 (riquadro, capienza e pulsante), :121-132 (`intent_usa_credito`).
<!-- /rettifica -->

Il portale condòmino esiste ma richiede lavoro di ottimizzazione UI più ampio,
fuori scope da questa iniziativa. Quando verrà ripreso: il condòmino dovrebbe
vedere il proprio credito/anticipo disponibile (non solo l'amministratore),
idealmente con lo stesso spaccato per gestione del punto 2. Nessuna decisione
tecnica presa finora — da specificare quando si affronta l'area condòmino.

<!-- rettifiche-non-ancorate -->

## ⚠️ Rettifiche non ancorate (31/07/2026)

Correzioni verificate sul codice che non è stato possibile agganciare a una riga precisa di questo documento. Valgono per l'intero testo.

- **Il documento afferma:** La compensazione cross-gestione avviene oggi in silenzio, senza alcuna rilevazione.
  **Realtà:** Era vero alla stesura, non lo è più: la rilevazione esiste su entrambi i lati. Il frontend mostra un banner ambra con checkbox obbligatoria che disabilita il pulsante di conferma, e il backend, indipendentemente dal frontend, scrive la provenienza esplicita nella nota della riga contabile. Resta vero — ma per scelta dichiarata — che il controllo non è bloccante.
  *Prova (numeri di riga aggiornati il 05/08/2026 su 1.10.0-beta.46: la beta.46 ha aggiunto codice a monte in `IncassoRateNew.vue`):* resources/js/pages/gestionale/movimenti/incassi/IncassoRateNew.vue:237-242 (isCrossGestione, crossGestioneConfermato), :708 (:disabled="… || (isCrossGestione && !crossGestioneConfermato)"), :796-810 (banner + checkbox); app/Actions/Gestionale/Movimenti/StoreIncassoRateAction.php:380-382 («Compensazione cross-gestione confermata dall'amministratore: {gestione} → {gestione} — rata n.X»).

<!-- /rettifiche-non-ancorate -->
