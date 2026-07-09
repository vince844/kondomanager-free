# Credito visibile ovunque — Iniziativa post v1.10.0-beta.9

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
4. **Widget dashboard "Crediti da compensare".** Nuovo `DashboardWidget` (stesso
   contratto di `TreasuryGuardianWidget`, registrato in `WidgetManager`): lista
   dei condòmini con `credito_disponibile > 0` nel condominio corrente, con link
   diretto a Nuovo Incasso pre-compilato (riusa il parametro `prefill_anagrafica_id`
   già supportato da `IncassoRateNew.vue`). Chiude il loop su crediti dimenticati
   (specialmente quelli da saldo iniziale, che possono restare inutilizzati per
   mesi se nessuno emette nuove rate compatibili).

## Differito — visibilità lato condòmino

Il portale condòmino esiste ma richiede lavoro di ottimizzazione UI più ampio,
fuori scope da questa iniziativa. Quando verrà ripreso: il condòmino dovrebbe
vedere il proprio credito/anticipo disponibile (non solo l'amministratore),
idealmente con lo stesso spaccato per gestione del punto 2. Nessuna decisione
tecnica presa finora — da specificare quando si affronta l'area condòmino.
