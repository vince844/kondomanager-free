# Note tecniche e decisioni — Kondomanager

Raccolta di vincoli di design, decisioni e note di conformità che **non** sono feature
con una versione assegnata (quelle stanno nella roadmap), ma promemoria da tenere
presenti quando si tocca l'area relativa.

---

## Conformità normativa

### Ripartizione spese in condominio parziale — Cass. civ. Sez. II, ord. n. 1095/2026

**Principio.** Le spese per parti comuni che servono solo alcuni condòmini (es. colonna
di scarico di una verticale) vanno ripartite *in proporzione ai millesimi* tra i soli
beneficiari (art. 1123, comma 1, c.c.), **mai in parti uguali**. L'assenza di tabelle
millesimali parziali non legittima il riparto "per teste": si usano i millesimi generali
ricalcolati sul gruppo (semplice operazione aritmetica — per ciascuno: i suoi millesimi
diviso la somma dei millesimi del gruppo). Il riparto in parti uguali è lecito solo se
previsto da regolamento contrattuale o deliberato all'unanimità (deroga ai criteri legali).

**Come lo gestisce Kondomanager — già conforme da v1.9.5.** Si crea una tabella
millesimale dedicata con le sole unità beneficiarie e, come valore, i loro millesimi
generali *grezzi*. Non serve riproporzionare a 1000 manualmente:
`CalcoloQuoteService::distribuisciSuTabelle()` normalizza in automatico —
`valore / sommaValori` (somma effettiva dei valori in tabella) e, a valle,
`peso / pesoTotale` — poi `distribuisciImporto()` ripartisce l'intero importo.
Il motore esegue quindi da sé l'operazione aritmetica richiesta dalla Cassazione.
Vale sia per il motore ordinario sia per quello straordinario (stesso core di distribuzione).

**Evoluzione — v1.9.10 (Tables Infrastructure).** Comando "Genera tabella parziale dai
millesimi generali per le unità selezionate": seleziona i beneficiari → il sistema eredita
i loro millesimi generali. Implementa letteralmente il principio, toglie il calcolo manuale
e impedisce all'amministratore di creare per errore una tabella egualitaria (il caso che
l'ordinanza sanziona). Possibile argomento autentico per forum/changelog.

---

### Stampe — blocco dati amministratore configurabile (L. 4/2013 · art. 1129 · art. 71-bis disp. att.)

**Principio.** Non hardcodare nelle stampe diciture come "professione esercitata ai sensi
della L. 4/2013": valgono solo per chi opera come professionista sotto quel regime. Una
dicitura fissa taglia fuori l'**amministratore-condòmino** (che ex art. 71-bis disp. att. non
ha nemmeno l'obbligo di formazione quando è nominato tra i condòmini dello stabile) e le
**società di servizi**.

**Decisione di design.** Rendere il blocco con i dati dell'amministratore in fondo alle
stampe **completamente configurabile** — testo libero o campi strutturati impostati una volta.
Così il professionista ci mette riferimento L. 4/2013, P.IVA e polizza; il
condòmino-amministratore solo i suoi dati; la società la propria ragione sociale. Nessuna
formula imposta dal sistema.

**Conformità.** Coerente con l'art. 1129 c.c. (dati anagrafici/professionali e luogo di
conservazione dei registri da indicare). Si aggancia naturalmente alle **Stampe Essenziali**.

---

### Libro giornale — immutabilità *a soglia*, non assoluta (art. 1130 n. 7 c.c.)

**Quadro normativo.** Il registro di contabilità (art. 1130 n. 7) è documento obbligatorio:
annotazione cronologica dei movimenti **entro 30 giorni**. L'estratto conto bancario ne è
semmai il *supporto*, non il sostituto (l'idea "i 30 giorni li assolve l'estratto conto",
diffusa nei corsi, è interpretazione difendibile ma non pacifica — unica parte eventualmente
da verificare con un legale, ma non cambia la conclusione tecnica).

**Punto chiave.** Nessuna norma impone che il giornale *del software* sia tecnicamente
immodificabile. L'obbligo è **tenere** il registro e **annotare in tempo**, non il divieto di
correggere. L'immutabilità è quindi una **scelta di integrità**, non un vincolo di legge.

**Decisione di design — blocco a soglia.** Le scritture sono liberamente modificabili finché
sono **"aperte"** (bozza / non registrate, gestione aperta, riga non riconciliata né inclusa
in un bilancio approvato). Una volta **registrate/bloccate** o a **gestione chiusa**, diventano
immutabili e si corregge **via storno**. Si protegge l'integrità dove serve davvero — storico,
riconciliato, approvato — togliendo l'attrito sui refusi di giornata (oggi un errore appena
digitato richiede storno + riscrittura, pura perdita di tempo).

**Coerenza interna.** Il Treasury Guardian deriva la liquidità *dal giornale* ("il libro è la
verità"): motivo in più per blindare i dati **finalizzati**, non per bloccare la correzione di
un errore fresco.

---

### Bilancio approvato — niente riapertura, correzione via sopravvenienza (art. 1137 · art. 2948 c.c.)

**Principio.** Un bilancio **approvato dall'assemblea non si riapre per riscriverlo**: la
delibera ha effetti, e riscrivere a ritroso farebbe saltare la catena degli esercizi
successivi e i riparti già addebitati.

**Meccanismo corretto.** Ciò che emerge dopo si sistema **nell'esercizio in corso** con una
**sopravvenienza** (attiva o passiva). L'errore "salta fuori" e si corregge nell'anno corrente,
non rifacendo la storia; le scritture degli esercizi nel frattempo restano valide — ed è
proprio per questo che non si riapre.

**Impugnazione.** I 30 giorni dell'art. 1137 riguardano l'**annullabilità**; un vizio che
emerge molto dopo (es. due anni) reggerebbe solo come **nullità** (senza termine). Anche se il
giudice annulla e l'assemblea ridelibera, la gestione resta **"in avanti"** (conguagli,
sopravvenienze), **non** con la riapertura letterale del periodo a DB.

**Stato.** L'architettura sopravvenienze (attiva/passiva in doppia partita) è la base corretta.
Gli edge case — in particolare gli effetti di un annullamento giudiziale — vanno disegnati con
cura nel **Year End Master**.

---

## Guardrail UX da implementare

- **Modalità "parti uguali" (se mai introdotta).** Segnalare in interfaccia che il riparto
  in parti uguali è legittimo *solo* con regolamento contrattuale o delibera all'unanimità
  (deroga ai criteri legali ex art. 1123 c.c.). Serve a non indurre l'amministratore
  nell'errore sanzionato da Cass. 1095/2026.

---

## Architettura tabelle — ufficiali vs ripartizioni di calcolo

*(rilevante quando si sviluppa la Tables Infrastructure — vedi nota versioni in fondo alla sezione)*

**Distinzione da introdurre.** Separare due concetti che oggi nel modello dati coincidono:

- **Tabelle ufficiali del condominio** — la generale e quelle allegate al regolamento
  (scale, ascensore, riscaldamento). Stabili e governate: si toccano solo nei casi
  dell'art. 69 disp. att. c.c. (errore, o mutamenti che alterano oltre 1/5 il valore
  proporzionale anche di una sola unità). Approvazione/revisione a maggioranza qualificata
  (art. 1136, c. 2) quando applicano i criteri legali — Cass. SS.UU. 18477/2010; unanimità
  solo per le tabelle convenzionali che derogano ai criteri.
- **Ripartizioni di calcolo** — derivate, per spese a uso parziale e una tantum. Non sono
  tabelle ufficiali e non vanno trattate (né governate) come tali.

**Approccio corretto per le spese a uso parziale** (es. colonna di scarico — Cass. 1095/2026):
non creare una nuova tabella ufficiale, ma derivare il riparto di *quella* spesa dai
millesimi generali già esistenti, ristretti alle unità interessate, a livello di spesa/piano.
Perché:

- non introduce alcun criterio nuovo → pura applicazione aritmetica dell'art. 1123, c. 1.
  L'approvazione delle tabelle è una presa d'atto, non la fonte dell'obbligazione
  (SS.UU. 18477/2010): quindi **niente nuova tabella e niente delibera ad hoc** — l'assemblea
  approva spesa e rendiconto, il riparto proporzionale tra i beneficiari si applica per legge;
- tiene pulite le tabelle ufficiali, che non vanno popolate di un oggetto per ogni spesa
  una tantum.

**Stato attuale (v1.9.5).** La via odierna — creare una tabella parziale con i millesimi dei
beneficiari — dà i numeri giusti ma lascia un oggetto-tabella permanente. Target: trattare la
ripartizione parziale come *derivata* (comando "genera dai millesimi generali sulle unità
selezionate"), distinta dalle tabelle ufficiali nell'UI e nel modello dati.

> **Nota versioni.** Questo è lavoro sull'area *tabelle* → sulla roadmap è la
> **v1.9.10 (Tables Infrastructure)**, non la v1.10.0 (che è la migrazione dell'installer in
> `Kondomanager\Installer`). Confermare dove agganciarlo.

---

## Validatore Coerenza Millesimi — fail-fast multilivello (Tier 1)

*(È l'automazione base, priorità assoluta Tier 1; sulla roadmap sta nell'area v1.9.x. Qui sono
fissate le decisioni di design e i guardrail; lo scheduling resta in roadmap. Il precursore
leggero — totale in fase di inserimento — è anticipabile senza attendere la feature completa.)*

**Filosofia.** Approccio **fail-fast** per prevenire gli errori silenziosi, lasciando sempre
un'uscita di sicurezza **tracciata**. Cinque fronti:

1. **Fronte Operativo (Emissione Rate).** Blocco di sistema in fase di emissione rate **solo
   per incoerenza reale** della tabella: somma dei valori diversa dal **totale di riferimento
   dichiarato** della tabella, oppure totale a zero. **Non** si blocca perché il totale è
   diverso da 1000 (vedi *Totale di riferimento* sotto): la matematica del riparto si normalizza
   già sul totale effettivo, quindi un 997 o un 1001 ripartiscono comunque il 100% della spesa.
   Il gate duro presidia i refusi che spezzano la coerenza interna, non i totali "non tondi"
   legittimi.
2. **Fronte Interfaccia (Dashboard).** Widget visivo in dashboard che segnala subito le
   incongruenze, prima di arrivare alle operazioni critiche. *Precursore leggero anticipabile:*
   totale corrente + scarto rispetto al **totale di riferimento** della tabella ("998/1000 —
   mancano 2", "1003/1000 — eccesso di 3"; con riferimento parametrizzato, non 1000 fisso).
3. **Fronte Eccezioni (Forzatura Tracciata).** Override manuale per bypassare il blocco nei
   casi eccezionali (emergenze, tabelle storiche consolidate che sfuggono alla perfezione
   matematica). Ogni forzatura **registrata nei log**. Coerente con la linea "advisory con
   override" del progetto; la forzatura **richiede una nota/motivazione** (stesso pattern della
   nota obbligatoria già adottato altrove, es. Piano Rate «Annullato»), non solo un click. La
   nota raccoglie il *perché* (arrotondamenti del tecnico, unità rimossa…), così lo scostamento
   resta documentato.
4. **Fronte Gestione Dati.** "Griglia Valori" per editing massivo e veloce dei millesimi +
   import da **Excel**. Si lega al comando "Genera tabella parziale dai millesimi generali"
   della Tables Infrastructure (v1.9.10).
5. **Fronte Diagnostico.** Log diagnostici precisi per individuare la **riga/quota esatta** che
   fa sballare il calcolo.

> **Cosa significa "quadrare" — totale di riferimento, non 1000 fisso.** Il riferimento dipende
> dal **tipo di tabella** e, soprattutto, **non è necessariamente 1000 nemmeno per la tabella
> generale**. Molte tabelle approvate non totalizzano 1000 *ab origine*, in modo del tutto
> legittimo (vedi sotto). La verifica va quindi parametrizzata su un **totale di riferimento
> dichiarato per tabella**, altrimenti genera **falsi positivi** proprio dove serve di più.

**Totale di riferimento configurabile — le tabelle non fanno sempre 1000 (riscontro beta).**
Casi reali in cui il totale è legittimamente diverso da 1000:

- **Unità rimossa.** Tolta un'unità (una "PM"), la tabella resta p.es. a **997** millesimi senza
  riproporzionare il resto.
- **Arrotondamenti del tecnico.** Tabelle cartacee approvate con millesimi arrotondati
  all'unità (spesso per eccesso). Esempio: tre appartamenti calcolati 333,6 / 333,6 / 332,8
  (= 1000) e **approvati 334 / 334 / 333 (= 1001)**. L'amministratore ha solo i valori interi
  deliberati, non i decimali, e **non può "correggere"** la tabella per riportarla a 1000: vale
  il documento approvato dall'assemblea — stessa logica del bilancio approvato che non si
  riscrive.

Conseguenze di design:

- Ogni tabella ha un **totale di riferimento** dichiarato (default 1000 per la generale, ma
  impostabile/confermabile su 997, 1001, 1002…). La coerenza si verifica **contro quel totale
  dichiarato**, non contro 1000.
- **Due controlli distinti, da non fondere in uno:**
  - *Integrità di trascrizione* — somma dei valori = totale dichiarato. Becca i refusi (es. 343
    battuto al posto di 334). È il controllo che ha senso rendere fermo (warning forte / gate a
    emissione rate).
  - *Scostamento da 1000* — puramente **informativo**: «Totale 1001 — diverso dai 1000 teorici.
    Arrotondamenti? Unità rimossa? Conferma e annota.» **Mai bloccante.**
- **Denominatore del riparto = totale effettivo della tabella, mai 1000 cablato.** Quota unità =
  `millesimi_unità / totale_tabella × spesa`. Il motore lo fa **già**: `CalcoloQuoteService`
  normalizza via `valore / sommaValori` (cfr. *Conformità — Cass. 1095/2026* e *Architettura
  tabelle*). Quindi 997 e 1001 ripartiscono il 100% al centesimo senza alcun intervento. Se mai
  comparisse un `/1000` cablato da qualche parte, sarebbe un bug latente proprio per questi casi
  — da cercare e rimuovere.

**Connessione normativa.** Blindare la coerenza dei millesimi presidia anche l'art. 1123 c.c. e
Cass. 1095/2026 (riparto proporzionale corretto tra i beneficiari) — vedi *Conformità
normativa*.

**Obiettivo.** Integrità dei dati contabili blindata, senza paralizzare il lavoro
dell'amministratore — e senza falsi allarmi sulle tabelle legittimamente diverse da 1000.

---

## Conservazione dati e portabilità

- **Tutti gli esercizi restano in database, per scelta.** Continuità storica, confronti anno
  su anno, consultazione a distanza di anni e copertura della conservazione documentale
  (cfr. prescrizione quinquennale ex art. 2948 c.c. sui contributi, su cui poggia la
  *Sentinella della Prescrizione*). L'amministratore **non è costretto a cancellare nulla**.
- **Esportazione/backup sempre possibili** a fine esercizio, in parallelo. Backup di base
  sempre gratuito; automazione, schedulazione e storage remoto col **Backup Pro**.
- **Peso del database — non è un collo di bottiglia.** I dati puramente contabili (registrazioni
  in partita doppia, movimenti, rate, riparti) sono righe di testo e numeri: nell'ordine di
  decine — al più un centinaio — di KB per gestione. **Gli allegati binari non stanno nel
  database**: i documenti sono salvati su filesystem e a DB si conserva **solo il percorso del
  file** (niente BLOB). Quindi anche 20 condomìni × 20 anni di sola contabilità restano
  nell'ordine di poche decine di MB; e uno scenario "800 MB" sarebbe comunque gestito senza
  problemi da MySQL con gli indici giusti. I vincoli reali non sono le prestazioni delle query
  ma la **quota disco del DB sull'hosting condiviso** (verificare il limite del piano, es.
  Netsons) e il **tempo di dump/restore**, entrambi già mitigati dall'avere i binari fuori dal
  DB. Misurazione rapida del peso per tabella: query su `information_schema.tables` ordinata per
  `data_length + index_length`. Se un domani servisse: prima gli indici, poi semmai
  partizionamento o uno schema d'archivio per le gestioni più vecchie (sempre consultabili) —
  ottimizzazione prematura oggi.
- **Filosofia.** Niente lock-in, formati aperti: i dati sono dell'amministratore e li porta via
  quando vuole.

---

## Segnalazioni v1.9.1-beta.9

*(Bug confermati dall'analisi del codice in data 2026-06-14. Da fixare prima del rilascio.)*

---

### ✅ [RISOLTO] Bug 1 — Modifica sottoconto: percentuale proprietario forza 100% anche quando è 0

**File:** `resources/js/components/gestionale/pianiDeiConti/conti/ModalModificaConto.vue` — riga 117

**Descrizione.** Quando si apre il modal di modifica di un sottoconto che ha
`percentuale_proprietario = 0` (es. spesa interamente a carico dell'inquilino:
proprietario 0%, inquilino 100%), il form mostra 100% al proprietario invece di 0%.
L'utente è costretto a correggerlo manualmente.

**Causa.** Operatore JavaScript `||` usato come fallback per il default 100:

```js
// RIGA 117 — SBAGLIATO
form.percentuale_proprietario = getPercentualeBySoggetto(newConto, 'proprietario') || 100
```

`getPercentualeBySoggetto` ritorna `Number(...)`: se la percentuale salvata è `0`,
il valore è **falsy** in JS e `0 || 100` produce `100`, sovrascrivendo il dato reale.

**Fix.** Sostituire `||` con `??` (nullish coalescing), che scatta solo su `null`/`undefined`
e non su `0`:

```js
// RIGA 117 — CORRETTO
form.percentuale_proprietario = getPercentualeBySoggetto(newConto, 'proprietario') ?? 100
```

**Impatto.** Solo la UI in apertura del modal di *modifica*. Il salvataggio e il DB sono
corretti (il dato zero è già scritto bene). Il bug si manifesta solo in lettura.

**Stato:** Risolto nella 1.9.1-beta.9

---

### Bug 2 — Modifica sottoconto: campo codice non visibile dopo salvataggio (da verificare)

**Segnalazione.** L'utente riferisce che il codice del sottoconto "non appare più da
nessuna parte" dopo la modifica.

**Analisi preliminare.** Il campo `codice` è correttamente:
- inizializzato nel `watch` del conto (`form.codice = newConto.codice || ''` — riga 108);
- esposto da `ContoResource` (`'codice' => $this->codice` — riga 198);
- caricato dal controller `show` tramite eager load dei sottoconti con
  `ContoResource::collection`.

**Ipotesi da verificare.**
Il controller `PianoContiController::show` carica i conti con `whereNull('parent_id')` e
i sottoconti tramite eager load nella relazione. I sottoconti passano attraverso
`ContoResource` ricorsivamente — verificare che il campo `codice` venga effettivamente
trasmesso nel JSON per i sottoconti e che il campo input nel modal venga pre-popolato
correttamente (potrebbe essere un problema di timing del `watch` con `immediate: true`
se `props.conto` arriva già popolato prima del mount).

**Stato.** Da riprodurre sul campo e confermare.

---

### ✅ [RISOLTO] Bug 3 — Mancato aggiornamento dropdown Capitoli Padre dopo creazione/modifica

**Segnalazione.** Quando si inserisce un conto padre non appare subito fra le opzioni ma si deve uscire (ricaricare la pagina) per poter associare un sottoconto.

**Riproduzione:**
1. Cliccare su "Nuovo Conto", creare un "Capitolo padre" e salvare.
2. Riaprire "Nuovo Conto" per creare un "Sotto-conto" e aprire la tendina "Capitolo padre".
3. Il conto appena creato non è presente nella lista.
4. Ricaricando la pagina, il conto appare.

**Causa:**
Il composable `useCapitoliConti` gestisce lo stato dei capitoli. Nel file `ModalNuovoConto.vue` (e `ModalModificaConto.vue`), all'apertura della tendina scatta la funzione `onDropdownCapitoliOpen()`. Questa funzione effettua la chiamata API **solo se** la lista è vuota (`if (capitoli.value.length === 0)`). 
Al salvataggio di un nuovo conto, il form viene resettato ma lo stato `capitoli` del composable non viene svuotato. Di conseguenza, alla successiva apertura della tendina, la lunghezza è `> 0`, la chiamata API viene saltata e la lista mostra i vecchi dati.

**Fix:**
Estrarre la funzione `reset` dal composable `useCapitoliConti` e invocarla all'interno del blocco `onSuccess` del salvataggio del form (sia nel modale di creazione che di modifica), forzando così un nuovo fetch alla successiva apertura della tendina.

**Stato:** Risolto nella 1.9.1-beta.9

---

### ✅ [RISOLTO] Bug 4 — Persistenza dell'errore di validazione sulle tendine v-select

**Segnalazione.** Creando o modificando una voce di spesa senza selezionare la "Tabella Millesimale" o il "Capitolo Padre", appare il messaggio di errore rosso. Tuttavia, andando successivamente a selezionare un valore valido dalla tendina, l'errore non scompariva dinamicamente.

**Causa:**
Gli eventi `update:modelValue` inline non riuscivano a far scattare la funzione `form.clearErrors()` di InertiaJS in modo affidabile, soprattutto nel modale di modifica dati.

**Fix:**
Implementato un sistema più robusto basato sui watcher di Vue3 all'interno della sezione `<script setup>` di `ModalNuovoConto.vue` e `ModalModificaConto.vue`, che restano in ascolto perenne del valore e cancellano automaticamente l'errore al variare del model:
```javascript
watch(() => form.tabella_millesimale_id, () => {
  form.clearErrors('tabella_millesimale_id')
})
```

**Stato:** Risolto nella 1.9.1-beta.9

---

## Segnalazioni v1.9.1-beta.10 — Fatture Passive: IVA manuale e Modifica

*(Segnalate dall'utente in data 2026-06-14. Analisi e decisioni di design sotto.)*

---

### Feature 1 — Inserimento manuale importi IVA (Modalità "Importi Liberi")

**Segnalazione.** Le bollette di energia (e in generale le utenze: gas, acqua, rifiuti) hanno
una struttura in cui l'IVA non è calcolabile come semplice aliquota sull'imponibile. Nella
bolletta allegata: imponibile lordo ~€44,31, "Accise e IVA" €11,02, totale €55,33. L'IVA
include accise, contributi e arrotondamenti → nessuna aliquota % produce il valore corretto.
L'utente non riesce a registrare il documento perché il pannello destra forza il calcolo
`imponibile × aliquota%`. Mettendo aliquota 0%, il backend ricalcola comunque l'IVA
(confermato dal codice: `$ivaRiga = round($impRiga * $aliq / 100)`).

**Connessione con il modulo riscaldamento.** Le bollette di riscaldamento centralizzato sono
il caso d'uso principale: la gestione "Riscaldamento" ha già tabelle millesimali dedicate
(quota fissa + quota variabile + coefficiente dispersione) e gestioni separate nel piano dei
conti, ma al momento della *registrazione della fattura del fornitore di energia* il flusso
è identico alle fatture ordinarie — e quindi soffre dello stesso problema IVA. Risolvere
questo punto è **prerequisito** per un corretto ciclo passivo delle utenze condominiali.

**Decisione di design — modalità duale.**

1. **Default (attuale):** Importo imponibile + aliquota % → IVA calcolata. Resta il
   comportamento principale, adatto al 90% delle fatture artigiani/professionisti.
2. **Modalità "Importi Liberi" (nuova):** Toggle per riga che disattiva il calcolo
   automatico e mostra due campi: Imponibile (€) + IVA (€). L'utente li inserisce
   manualmente. Il totale riga è la somma dei due. L'aliquota % diventa un campo
   calcolato e puramente informativo (IVA/imponibile×100), non editabile.

**Impatto tecnico — localizzato, non architetturale.**

- **Frontend** (`FatturaRegisterNew.vue`): Aggiunta toggle per riga + campo MoneyInput per
  IVA manuale. Il computed `totali` deve gestire entrambe le modalità. Anche per la
  modalità "pregresso" (pannello sinistro) servono due campi separati.
- **Backend** (`FatturaPassivaService.php`): La riga arriva con `importo_iva` esplicito
  quando in modalità manuale. Il service deve usare il valore ricevuto invece di ricalcolare.
  Aggiungere un campo `iva_manuale: boolean` nella riga per distinguere le due modalità.
- **Validazione** (`StoreFatturaRequest.php`): Regola condizionale: se `iva_manuale` è true,
  `importo_iva` è required e `aliquota_iva` diventa nullable.
- **Migration**: Colonna `iva_manuale` (boolean, default false) su `righe_fattura` +
  colonna `importo_iva_manuale` (integer, nullable) per il valore inserito dall'utente.
  Per le fatture pregresse: aggiungere `importo_iva_pregresso` su `fatture_passive` per
  l'override manuale.

**Classificazione.** Feature media — non tocca l'architettura contabile (la partita doppia
non cambia: dare/avere restano calcolati sugli importi finali, indipendentemente da come
sono stati determinati). Stimata: 1–2 sessioni di lavoro.

---

### Feature 2 — Modifica fattura passiva pre-pagamento

**Segnalazione.** Dall'elenco Fatture Passive non è possibile modificare una fattura
registrata. L'unica strada oggi è eliminarla e ricrearla da zero, con perdita del protocollo
e dell'allegato.

**Stato attuale del codice.** Confermato: non esiste alcun metodo `update()` nel controller,
nessuna route `PUT /fatture/{fattura}`, nessuna voce "Modifica" nel menu azioni della
DataTable. La show page è di sola lettura.

**Decisione di design — modifica con soglia di cristallizzazione.**

Coerente con la linea già stabilita in *"Libro giornale — immutabilità a soglia"* (vedi
sopra): le scritture sono modificabili finché aperte, poi si corregge via storno.

Regola: la fattura è **modificabile** se e solo se:
- `stato_pagamento = aperta` (nessun pagamento, nemmeno parziale)
- La fattura **non** è stata stornata (`dati_extra.is_stornata` è falso/assente)
- L'esercizio contabile è ancora **aperto**

Una volta che esiste un movimento di pagamento (anche parziale), la fattura si cristallizza.
La correzione passa obbligatoriamente dallo **storno contabile**.

**Cosa è modificabile (pre-pagamento):**
- Dati testata: numero documento, data documento, data scadenza, IBAN, modalità pagamento,
  conto corrente di addebito
- Righe: descrizione, capitolo di spesa, importi, aliquote IVA, assegnazione immobile
- Allegato: sostituzione/aggiunta PDF
- **NON** modificabile: fornitore (cambierebbe la chiave del debito nel libro giornale)

**Impatto tecnico — significativo.**

- **Route**: `PUT /fatture/{fattura}` → `FatturaPassivaController::update()`
- **Controller**: Metodo `update()` con i guard contabili (stato pagamento, esercizio aperto,
  non stornata). Deve rigenerare le scritture contabili collegate.
- **Service**: Metodo `aggiornaFattura()` in `FatturaPassivaService` — transazionale, deve:
  1. Validare le precondizioni
  2. Aggiornare la testata
  3. Eliminare vecchie righe e scritture contabili (stessa logica del `destroy`)
  4. Ricreare righe e scrittura contabile con i nuovi dati
  5. Rieseguire il Double-Entry Validator
- **Frontend**: Pagina `FatturaRegisterEdit.vue` (o riuso di `FatturaRegisterNew.vue` con
  prop `editMode`). Il form deve arrivare pre-compilato con i dati della fattura esistente.
- **DataTableRowActions.vue**: Aggiungere voce "Modifica" visibile solo se la fattura è
  modificabile (aperta + non stornata + esercizio aperto).
- **Migration**: Nessuna — i dati esistono già, cambia solo la logica applicativa.

**Connessione con il modulo riscaldamento.** Le fatture di utenze (riscaldamento, energia)
sono quelle più soggette a correzioni post-registrazione: l'amministratore spesso registra
il documento alla ricezione e poi corregge l'allocazione sui capitoli dopo aver verificato
i consumi. La modifica pre-pagamento è particolarmente utile per questo flusso.

**Classificazione.** Feature importante — tocca il flusso contabile (riscrittura partita
doppia) ma non cambia l'architettura. Richiede attenzione ai guard per non corrompere lo
stato. La logica "elimina vecchie scritture + ricrea" è già collaudata nel `destroy()`.
Stimata: 2–3 sessioni di lavoro.

**Ordine di implementazione consigliato:** Feature 1 (IVA manuale) prima di Feature 2
(Modifica), perché l'IVA manuale è più semplice, risolve il blocco immediato dell'utente,
e il form di modifica dovrà comunque supportare la modalità IVA manuale.

---

## Note varie

_(da popolare)_