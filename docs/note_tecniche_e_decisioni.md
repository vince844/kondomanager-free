# Note tecniche e decisioni — Kondomanager

Raccolta di vincoli di design, decisioni e note di conformità che **non** sono feature
con una versione assegnata (quelle stanno nella roadmap), ma promemoria da tenere
presenti quando si tocca l'area relativa.

> **Come leggere questo file.** Ogni sezione con un'implicazione implementativa riporta ora
> un tag `→ Roadmap:` che punta alla versione/sezione corrispondente in
> [`roadmap.md`](roadmap.md), e uno stato verificato sul codice reale (non solo dichiarato):
> **✅ implementato**, **🔧 [APERTO]** (non ancora fatto), **⚠️ parziale** (backend c'è,
> frontend/copy no o viceversa). Verifica di riallineamento doc↔codice fatta il 2026-07-26
> (audit multi-agente su 8 claim di questo file, incrociato anche con `docs/changelog.md` per
> le voci risultate implementate — dà la beta esatta di rilascio dove trovata). Dove non c'è
> tag, la nota è un principio/decisione senza una singola feature versionabile a cui agganciarsi.

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

**🔧 [APERTO] Evoluzione — comando "Genera tabella parziale dai millesimi generali".**
Selezionare i beneficiari → il sistema eredita i loro millesimi generali, invece di doverli
ribattere a mano. Implementa letteralmente il principio, toglie il calcolo manuale e
impedisce all'amministratore di creare per errore una tabella egualitaria (il caso che
l'ordinanza sanziona). Possibile argomento autentico per forum/changelog.

**→ Roadmap:** `v1.10.0` — Iniziativa A, "Tabelle Millesimali avanzate + motore" (vedi
[`tabelle_millesimali.md`](tabelle_millesimali.md)). *Nota storica:* questa sezione citava in
origine una "v1.9.10 (Tables Infrastructure)" che non esiste più come milestone a sé nella
roadmap attuale — è stata assorbita nell'Iniziativa A della v1.10.0.
**Verificato sul codice (2026-07-26): non implementato.** `TabellaController` espone solo
CRUD standard; nessun endpoint/comando "genera dai millesimi generali", nessuna colonna che
distingua una tabella ufficiale da una ripartizione derivata. Dettaglio in
*Architettura tabelle*, sotto.

---

### ✅ [AGGIUNTO — beta.9] Stampe — blocco dati amministratore configurabile (L. 4/2013 · art. 1129 · art. 71-bis disp. att.)

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

> Nota: questa sezione è sul **principio di editabilità**, non sulla UI di consultazione. La
> pagina di sfoglio del registro (elenco cronologico delle scritture per esercizio, con
> filtri e verifica di quadratura patrimoniale) esiste dalla **v1.10.0-beta.29** — prima era
> raggiungibile solo per drill-down dal documento collegato. Non versionata in questo file
> perché non tocca il principio di immutabilità qui sotto, solo la lettura.

**Quadro normativo.** Il registro di contabilità (art. 1130 n. 7) è documento obbligatorio:
annotazione cronologica dei movimenti **entro 30 giorni**. L'estratto conto bancario ne è
semmai il *supporto*, non il sostituto (punto AMACI — vedi sotto).

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

**Riscontro beta — motivazione pratica.** Un beta-tester ha esplicitato quello che molti
amministratori vivono: «l'immutabilità assoluta è troppo — capita spesso di fare errori di
digitazione e dover fare storno + riscrittura, è una pura perdita di tempo». Questo riscontro
*rafforza* la scelta a soglia: non è un compromesso, è la risposta corretta al flusso di
lavoro reale. Il blocco scatta solo dove protegge qualcosa di concreto (storico definitivo,
riconciliato, bilancio approvato), non su ogni singola digitazione.

**Chiarimento sul threshold per i pagamenti — i "30 giorni" del beta-tester.** L'amministratore
usa i 30 giorni come *proxy intuitivo di freschezza*: «sono ancora nel periodo di registrazione
lecita, perché devo stornare invece di correggere?». Non esiste una norma che attribuisca una
finestra di modifica di 30 giorni — l'art. 1130 n. 7 parla di obbligo di *annotazione entro
30 giorni dall'evento*, non di finestra di correzione. Il feedback punta però a un problema
reale di design: il punto di cristallizzazione corretto per un pagamento **non è il salvataggio**
ma la **riconciliazione con l'estratto conto**. Prima di quella, il dato è ancora "in volo".

**Threshold corretto per i pagamenti:**

| Stato del pagamento | Modificabile? | Motivazione |
|---|---|---|
| Registrato, non riconciliato, periodo aperto | **Sì** | Nessun dato definitivo dipende ancora da questa riga |
| Riconciliato con estratto conto | **No → storno** | Punto di cristallizzazione reale |
| Incluso in bilancio approvato | **No → storno** | Più forte: delibera assembleare |
| Stornato | **No** | È già storia |

**✅ [RISOLTO] Feature 4 — Modifica Pagamento pre-riconciliazione.** Questa feature è la
conseguenza diretta del threshold corretto. Quasi tutti i gestionali condominiali trattano la
registrazione di pagamento come immutabile appena salvata — per eredità della mentalità del
software contabile classico, non per obbligo di legge. Offrire la modifica fino alla riconcilia-
zione è una differenza concreta nel flusso quotidiano: l'amministratore la sente il primo giorno,
non la cerca in un feature list. **È un elemento di differenziazione reale rispetto ai competitor.**

Cosa è modificabile:

| Campo | Modificabile? | Note |
|---|---|---|
| Importo | ✅ Sì | Ricalcola scritture in transazione atomica |
| Data pagamento | ✅ Sì | Aggiorna scadenzario/F24 (listener `SyncF24WithPagamento`) |
| Metodo pagamento / conto | ✅ Sì | Cambia il conto di liquidità nelle scritture |
| Note / riferimento | ✅ Sì | Solo testo, nessun effetto contabile |
| Fornitore | ❌ No | Cambia la semantica del documento — storno |
| Fattura collegata | ❌ No | Storno + nuovo pagamento |

Impatto tecnico: `aggiornaPagamento()` transazionale in `PagamentoFornitoreService` (pattern
identico a `aggiornaFattura()` già previsto per Feature 2) — valida soglia → aggiorna testata →
elimina vecchie scritture → ricrea → Double-Entry Validator. Audit trail: log della modifica
con valori before/after (non una scrittura di storno, una riga di audit). Il listener
`SyncF24WithPagamento` si aggancia all'evento `PagamentoAggiornato` per ricalcolare la scadenza
F24 se cambia `data_pagamento`. Frontend: voce "Modifica" visibile nella DataTable solo se
il pagamento è modificabile (`riconciliato = false AND gestione_aperta = true`).

**Stato.** Sviluppata e implementata (precedendo Feature 1). Endpoint `update` su `PagamentoFornitoreController`, service `aggiornaPagamento` transazionale e UI dedicata `PagamentoEdit.vue`. Classificazione: feature media, alto valore percepito.

**Sul punto AMACI — "i 30 giorni li assolve l'estratto conto".** La tesi — diffusa nei corsi —
è che l'obbligo di registrazione entro 30 gg sia già assolto dall'estratto conto bancario,
essendo tutti i movimenti transitati per banca. È una posizione **difendibile nei condomini
interamente bancati**, ma non universale: basta un singolo movimento *per cassa* (es.
raccomandata pagata in contanti, rimborsata poi con un bonifico all'amministratore) per far
emergere un movimento che sull'estratto conto non compare come spesa condominiale diretta.
Il giornale lo registra esplicitamente; l'estratto conto, in quel caso, no.
**Conclusione tecnica:** il punto AMACI è un'interpretazione operativamente comoda ma non
pacifica; non cambia né il quadro normativo né la decisione di design. Se un legale volesse
convalidarla, il perimetro esatto da verificare è proprio il trattamento dei movimenti per
cassa e degli eventuali rimborsi spese. Per Kondomanager resta invariato: il giornale è
il documento primario, l'estratto conto è un supporto.

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

**→ Roadmap:** `v1.17` — "Year End Master" (procedura di chiusura esercizio guidata,
transizione consuntivo→preventivo, chiusura/apertura conti automatica, cambio gestione).
**Verificato sul codice (2026-07-26): non implementato.** `EsercizioController` oggi ha solo
un CRUD standard; lo stato esercizio è un semplice `aperto`/`chiuso` cambiabile via update
generico, senza nessuna delle capacità sopra — coerente con l'essere pianificato per v1.17,
non ancora iniziato.

---

## 🔧 [APERTO] Guardrail UX da implementare

**→ Roadmap:** nessuna versione la nomina esplicitamente — è condizionale ("se mai
introdotta" una modalità "parti uguali" nelle tabelle), da riprendere se/quando ci si lavora
sull'Iniziativa A (`v1.10.0`) o sul motore riparto unificato (`v1.11`).

- **Modalità "parti uguali" (se mai introdotta).** Segnalare in interfaccia che il riparto
  in parti uguali è legittimo *solo* con regolamento contrattuale o delibera all'unanimità
  (deroga ai criteri legali ex art. 1123 c.c.). Serve a non indurre l'amministratore
  nell'errore sanzionato da Cass. 1095/2026.
  **Verificato (2026-07-26): non implementato** — nessun componente tabelle menziona
  "parti uguali"/"unanimità"/"1123"; atteso, dato che il documento lo marca condizionale.

---

## 🔧 [APERTO] Architettura tabelle — ufficiali vs ripartizioni di calcolo

**→ Roadmap:** `v1.10.0` — Iniziativa A, "Tabelle Millesimali avanzate + motore".

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

**Stato attuale (v1.9.5) — verificato invariato al 2026-07-26.** La via odierna — creare una
tabella parziale con i millesimi dei beneficiari — dà i numeri giusti ma lascia un
oggetto-tabella permanente. Target: trattare la ripartizione parziale come *derivata* (comando
"genera dai millesimi generali sulle unità selezionate"), distinta dalle tabelle ufficiali
nell'UI e nel modello dati. Confermato via audit codice: `TabellaController` (CRUD puro),
migrazione `tabelle` (nessun campo ufficiale/derivata) — nessun cambiamento rispetto a v1.9.5.

---

## 🔧 [APERTO] Validatore Coerenza Millesimi — fail-fast multilivello (Tier 1)

**→ Roadmap:** `v1.10.0` — Iniziativa A, "Validatore Coerenza Millesimi (Tier 1)" (testo
identico a questa sezione in [`roadmap.md`](roadmap.md)). È l'automazione base, priorità
assoluta Tier 1. Qui sono fissate le decisioni di design e i guardrail; lo scheduling resta
in roadmap.

**Verificato sul codice (2026-07-26): nessuno dei 5 fronti è implementato.**
- Fronte 1 (blocco emissione rate) — `CalcoloQuoteService::distribuisciSuTabelle()` fa solo
  `Log::warning` + skip silenzioso se la somma è zero; nessun campo "totale di riferimento"
  sul modello `Tabella`, nessun blocco reale in emissione rate.
- Fronte 2 (widget dashboard) — nessun riferimento a millesimi/tabelle in `Dashboard.vue` o
  nei suoi widget.
- Fronte 3 (forzatura tracciata) — nessun meccanismo di override trovato.
- Fronte 4 (Griglia Valori + import Excel) — `QuoteList.vue` è un editing riga-per-riga
  semplice, senza totale live né import; nessuna libreria Excel nel progetto.
- Fronte 5 (log diagnostici) — esistono solo log generici sul caso "somma zero", non il log
  mirato sulla riga/quota che fa sballare il totale contro il riferimento dichiarato.

**✅ [RISOLTO — 2026-07-26] Effetto collaterale trovato durante la verifica — copy fuorviante
in `TabelleList.vue`.** Il testo di guida della pagina diceva che il sistema "controlla in
tempo reale che la somma dei millesimi di ogni tabella sia esattamente 1000" (card) e che
"il validatore di coerenza ti assisterà per evitare errori matematici" (sottotitolo pagina) —
nessuna delle due era vera (nessuna verifica reale esiste), e la prima **contraddiceva anche
la decisione di design qui sotto** (mai contro 1000 fisso, sempre contro il totale di
riferimento dichiarato). Corretto in entrambi i punti con un testo onesto su cosa fa davvero
il motore oggi (normalizza sul totale effettivo, non richiede 1000) — nessuna menzione di un
validatore che non esiste. Fix di solo copy, indipendente dal validatore vero (Tier 1, sopra),
che resta 🔧 [APERTO].

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
   (vedi *Architettura tabelle*, sopra).
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

**Causa.** Operatore JavaScript `||` usato come fallback — `0 || 100` produce `100`.

**Fix.** Sostituire `||` con `??` (nullish coalescing).

**Stato:** Risolto nella 1.9.1-beta.9

---

### ✅ [RISOLTO] Bug 2 — Modifica sottoconto: campo codice non visibile dopo salvataggio

**Causa.** Il campo codice non veniva persistito/recuperato in quanto mancante nel `$fillable` del modello.

**Fix.** Aggiornato il `$fillable` di `Conto.php` includendo la colonna `codice`.

**Stato:** Risolto nella 1.9.1-beta.9.

---

### ✅ [RISOLTO] Bug 3 — Mancato aggiornamento dropdown Capitoli Padre dopo creazione/modifica

**Causa.** `onDropdownCapitoliOpen()` saltava la chiamata API se `capitoli.value.length > 0`,
anche dopo un salvataggio che aggiungeva nuovi capitoli.

**Fix.** Estrarre `reset` dal composable `useCapitoliConti` e invocarla nell'`onSuccess`
del salvataggio.

**Stato:** Risolto nella 1.9.1-beta.9

---

### ✅ [RISOLTO] Bug 4 — Persistenza errore validazione sulle tendine v-select

**Causa.** `update:modelValue` inline non scatenava `form.clearErrors()` in modo affidabile.

**Fix.** Watcher Vue 3 nella `<script setup>` che ascolta il valore e chiama `clearErrors`.

**Stato:** Risolto nella 1.9.1-beta.9

---

## Segnalazioni v1.9.1-beta.10 — Fatture Passive: IVA manuale e Modifica

*(Segnalate dall'utente in data 2026-06-14.)*

---

### 🔧 [APERTO] Feature 1 — Inserimento manuale importi IVA (Modalità "Importi Liberi")

**→ Roadmap:** nessuna versione la nomina più — è sparita anche dalla roadmap attuale (era
"da schedulare" già in questo documento, riga *Post-v1.9.1* più sotto, e non è mai stata
ripresa in nessuna release da v1.9.2 in poi). Va o riportata esplicitamente in roadmap se è
ancora voluta, o tolta da qui se è stata scartata.
**Verificato sul codice (2026-07-26): non implementato.** Nessuna colonna `iva_manuale` /
`importo_iva_manuale` su `righe_fattura`, nessun toggle in `FatturaRegisterNew.vue`,
`FatturaPassivaService` calcola sempre `imponibile × aliquota%`.

**Segnalazione.** Le bollette di energia/utenze hanno IVA non calcolabile come semplice
aliquota sull'imponibile (include accise, contributi, arrotondamenti). Il form forza
`imponibile × aliquota%`.

**Decisione di design — modalità duale.**
- Default: imponibile + aliquota% → IVA calcolata (90% dei casi, invariato)
- Nuova: toggle per riga → due campi separati Imponibile (€) + IVA (€). Aliquota%
  diventa campo calcolato informativo, non editabile.

**Impatto tecnico.**
- Frontend: toggle + MoneyInput IVA manuale; computed `totali` gestisce entrambe le modalità
- Backend `FatturaPassivaService`: usa `importo_iva` ricevuto se `iva_manuale = true`
- Validazione: `importo_iva` required se `iva_manuale`, `aliquota_iva` nullable
- Migration: colonna `iva_manuale` (boolean, default false) + `importo_iva_manuale`
  (integer, nullable) su `righe_fattura`; `importo_iva_pregresso` su `fatture_passive`

**Prerequisito** per il corretto ciclo passivo delle utenze condominiali.
**Classificazione.** Feature media. Stimata: 1–2 sessioni. **Non architetturale.**

---

### ✅ [RISOLTO] Feature 2 — Modifica fattura passiva pre-pagamento

**Segnalazione.** Non esiste `update()` nel controller — eliminazione e ricreazione da zero.

**Decisione — modifica con soglia di cristallizzazione** (coerente con *Libro giornale a soglia*).

Fattura modificabile se e solo se:
- `stato_pagamento = aperta` (nessun pagamento, nemmeno parziale)
- Non stornata (`dati_extra.is_stornata` falso/assente)
- Esercizio contabile aperto

Cosa è modificabile: testata, righe, allegato. **NON modificabile:** fornitore.

**Impatto tecnico (Implementato).**
- Route: `PUT /fatture/{fattura}` → `FatturaPassivaController::update()`
- Service: `aggiornaFattura()` transazionale (valida → aggiorna testata → elimina vecchie
  scritture → ricrea → Double-Entry Validator)
- Frontend: `FatturaRegisterEdit.vue` creato e integrato
- DataTable: voce "Modifica" visibile solo se modificabile

**Stato.** Sviluppata e implementata (prima di Feature 1, scavalcando l'ordine consigliato). Classificazione: Feature importante.

---

## Segnalazioni v1.9.1-beta.10 — Pagamento Fatture

*(Analisi 2026-06-16, rivista 2026-06-17 dopo riscontro beta: approccio minimale — solo testo, niente nuovi campi DB.)*

---

### ✅ [NON È UN BUG] Bug 5 — `sforo_motivato` "blocca" il pagamento (art. 1135 c.c.)

**Segnalazione.** Le fatture con `stato_approvazione = 'sforo_motivato'` non sono pagabili
direttamente: `validaInput()` lancia `FatturaNonApprovataException`.

**Conclusione — non è un blocco da rimuovere, è il gate previsto.** Lo sforo si sblocca
passando dal modal "Approva Sforo" (POST `gestionale.fatture.approva-sforo`), che porta lo
stato ad `'approvata'` registrando la nota; poi il pagamento procede. `validaInput()` resta
sul check `== 'approvata'`.

**Contesto normativo.** Per le spese urgenti ex art. 1135, c. 2 c.c. l'amministratore paga
subito; la ratifica è successiva e non bloccante. Questo non impone di togliere il gate: impone
solo che il modal lo spieghi bene (Feature 3 rivista) e che la motivazione finisca nella nota.

**Fix backend `whereNotIn(['approvata', 'sforo_motivato'])` — scartato e non applicato.**
Bypasserebbe il modal in silenzio, perdendo la nota e il valore documentale.

**Stato:** Risolto come decisione di flusso. Resta solo il ritocco del testo (Feature 3 rivista).

---

### ✅ [RISOLTO] Bug 6 — `Call to undefined method TipoDetrazione::descrizione()`

**Segnalazione.** Pagamento con Bonifico Parlante → errore fatale.

**Causa.** Metodo `descrizione()` assente nell'enum implementato.
`PagamentoFornitoreService::generaCausaleBonifico()` lo chiama per costruire la causale.

**Fix.** Risolto dall'utente. Verificare che `TipoDetrazione` esponga entrambi:
`descrizione()` e `riferimentoNormativo()` come da guida v1.9.1 sez. 4.4.

**Stato:** Risolto dall'utente. ✅

---

### ✅ [RISOLTO — beta.9] Feature 3 (rivista) — Dicitura del modal "Approva Sforo"

**Decisione (riscontro beta, 2026-06-17).** La versione strutturata — `tipo_autorizzazione`,
`data_ratifica_prevista`, schema JSON in `dati_extra['ratifica_assembleare']`, toggle
delibera/urgenza, 5 test sul metadata — **è abbandonata**: over-engineering. Il tester chiede
solo di riscrivere la dicitura, e ha ragione: «il gestionale registra quello che accade, eviti
di inserire campi boolean nelle tabelle del db».

**Cosa si fa davvero — niente backend, niente DB.**
- Il modal Approva Sforo resta com'è, con il **solo campo nota a testo libero** già presente
  ("Riferimento verbale / Note"). Nessun campo nuovo, nessuno schema JSON, nessuna colonna.
- Si riscrive solo la **dicitura** del warning, in un testo unico che copre delibera preventiva
  e urgenza ex art. 1135. Alla conferma (nota valorizzata) lo stato va ad `'approvata'` come oggi
  e il pagamento procede → risolve anche Bug 5.
- L'Inbox operativa mantiene il warning "Ratifica Assemblea — Sforo budget" sulla fattura pagata.

**Dicitura del modal:**

> **Ratifica assembleare (Art. 1135 c.c.)**
> Questa fattura supera il budget approvato dall'assemblea.
> • Se la spesa è già stata deliberata, indica nel campo note il riferimento al verbale.
> • In caso di lavori urgenti, per evitare un pregiudizio al condominio l'amministratore può
>   procedere al pagamento, dandone comunicazione all'assemblea nella prima convocazione utile.
>   In tal caso annota qui la motivazione, es.: «Pagamento effettuato per urgenza
>   dall'amministratore — [breve descrizione]».
> La ratifica resterà segnalata nella Inbox operativa fino all'approvazione in assemblea.

**File toccato.** Solo il testo del `ConfirmDialog` in
`resources/js/pages/gestionale/movimenti/pagamenti/PagamentoNew.vue`.

**Test.** Niente test sul metadata (non esiste più). Resta utile un solo test di flusso:
fattura `sforo_motivato` → approva-sforo con nota → stato `'approvata'` → fattura pagabile
(nessuna `FatturaNonApprovataException`). Verificare che la nota del campo esistente venga
persistita.

**Stato: ✅ Fatto.** *(Corretto il 2026-07-26 — questa riga diceva ancora "Da fare — solo
testo", in contraddizione con l'header "✅ [RISOLTO — beta.9]" sopra: refuso di
aggiornamento, non doppio stato reale. Verificato sul codice: il testo del `ConfirmDialog` in
`PagamentoNew.vue` corrisponde parola per parola alla dicitura proposta qui sopra. Confermato
anche nel changelog — `docs/changelog.md:971`, beta.9: "Dicitura modal Approva Sforo riscritta
(Feature 3, Art. 1135 c.c.): Il testo del modal e del tooltip badge... è stato riscritto per
coprire esplicitamente i due scenari previsti dall'art. 1135 c.c....".)*

---

## Piano di Azione v1.9.1 — Stato al 2026-06-17

### ✅ Completato

**Fondamenta:**
- [x] 6 Backed Enums (`TipoMovimentoContabile`, `MetodoPagamento`, `StatoPagamentoFattura`,
  `StatoPagamentoFornitore`, `TipoAllocazioneFattura`, `TipoDetrazione`)
- [x] 10 Domain Exceptions in `app/Exceptions/Pagamenti/` — 1 file per classe (PSR-4)
- [x] `HasProtocolNumber` trait — fix Backed Enum cast: `tipo_movimento` restituisce Enum,
  non stringa. Match corretto con `->value`. Aggiunto case `storno_pagamento_fornitore → 'STO'`.

**Migration (4):**
- [x] `upgrade_scritture_contabili_v191` — ENUM→VARCHAR(50) + `idempotency_key` UUID UNIQUE
- [x] `upgrade_fatture_passive_v191` — 4 colonne audit read model (`versione_allocazioni` BIGINT)
- [x] `create_pagamenti_fornitori_table` — tabella documentale completa con indici performance
- [x] `seed_conti_mancanti_v191` — data migration conti `spese_bancarie`, `iva_acquisti`, `iva_vendite`

**Modelli (4):**
- [x] `FatturaPassiva` — cast `StatoPagamentoFattura`, accessor `residuo`/`totale_allocato`,
  scope (`pagabili`, `conResiduo`, `conInconsistenza`), `scritture()` con `->using(FatturaScrittura::class)`
- [x] `ScritturaContabile` — cast `TipoMovimentoContabile`, `idempotency_key` fillable,
  relazione `fatture()` con FK esplicite, relazione `pagamentoFornitore()`, nota SoftDeletes
- [x] `FatturaScrittura` (Pivot) — `$incrementing = true`, `$timestamps = true`,
  cast `TipoAllocazioneFattura`
- [x] `PagamentoFornitore` — relazioni complete, self-ref storno (`padre`/`storno`), scope

**Service + Events:**
- [x] `PagamentoFornitoreService` completo — `registraPagamento()`, `stornaPagamento()`
  (cross-esercizio Variante B1), `ricalcolaStatoFattura()`, `generaCausaleBonifico()`,
  `trovaNoteCreditoCompensabili()`, `verificaCapienza()`, `deriveGestioneId()`
- [x] `PagamentoRegistrato` e `PagamentoStornato` — namespace `App\Events\Gestionale`
- [x] `validaInput()` — mantenuto il check su `== 'approvata'` (nessun `whereNotIn`): lo
  `sforo_motivato` si paga solo passando dal modal Approva Sforo, che lo porta ad `approvata`.
  Il `whereNotIn(['approvata','sforo_motivato'])` valutato prima è stato scartato (toglierebbe
  il gate e la nota). Non applicato.
- [x] Fix overpayment check per NC — skip su `tipo_documento = 'nota_credito'`
- [x] `cassa_id` popolato sulle righe di liquidità (coerenza con `StoreIncassoRateAction`)
- [x] `gestione_id` derivato da `$fattura->scritture()->first()?->gestione_id`
  (pattern identico a `StornoFatturaController`)

**Test (22 test, 65+ assertions):**
- [x] `GestionaleTestHelpers.php` — `setupContabile()`, `assertQuadraturaPerfetta()`, `datiBase()`
- [x] `PagamentoFornitoreServiceTest.php` — 6 gruppi copertura completa

---

### 🔧 Da completare prima del rilascio v1.9.1

- [x] **Feature 3 (rivista)** — solo testo del modal Approva Sforo in `PagamentoNew.vue`
  (dicitura delibera + urgenza ex art. 1135). Niente campi nuovi, niente JSON, niente colonne.
  Risolve anche Bug 5. Un solo test di flusso (`sforo_motivato` → `approvata` → pagabile).
- [x] **Controller + Form Request** — Ricognizione completata (2026-06-18): il controller
  `PagamentoFornitoreController` era già completo al 90% (index, create, store, pendenze, distinta).
  `StornoPagamentoController` (invokable separato) e `StorePagamentoFornitoreRequest` erano già
  presenti e funzionanti. Aggiunto il metodo `show()` con guard condominio, eager load delle
  relazioni e `Inertia::render('PagamentoShow')`. Aggiunta route `GET /pagamenti-fornitori/{pagamento}`
  (`pagamenti-fornitori.show`) in `gestionale.php`, posizionata dopo `/distinta` per evitare
  conflitti di route matching. Verificata con `php -l` (zero errori) e `php artisan route:list`.
- [x] **F24 Refactor** — Completato (2026-06-18). Creato `SyncF24WithPagamento` listener
  (`$afterCommit = true`, auto-discovery via `subscribe()`). Il task "F24 Ritenuta" viene ora
  generato al momento del **pagamento** (evento `PagamentoRegistrato`), non della fattura.
  Scadenza: 16 del mese successivo a `data_pagamento`, spostata al lunedì se weekend.
  Eliminato il blocco commentato (42 righe) da `SyncScadenziarioWithFattura`.
  Guard: salta se `importo_ritenuta <= 0` o se il pagamento è uno storno (`pagamento_padre_id !== null`).
  `action_url` del task punta alla nuova `PagamentoShow`. Verificato con `php artisan event:list`.
- [x] **Comando Artisan** — SCARTATO. Il comando `kondomanager:fatture-ricalcola-stati` è stato declassato a diagnostico/manutenzione interna. L'hosting condiviso e i rischi di sovrascrittura automatica non lo rendono adatto alla release v1.9.1. L'inconsistenza sarà gestita dal "Radar Salute Contabile" in dashboard in futura release.
- [x] **Frontend Vue (Payment Sentinel UI)** — COMPLETATO. L'analisi ha rivelato che la logica (RadarNoteCredito, SentinellaIban, DetectorDuplicato, BonificoParlante) era già stata integrata nativamente all'interno del file monolitico `PagamentoNew.vue`. Il refactoring in componenti separati è rimandato alla v1.9.2 (debito tecnico dichiarato). Bug 2 (codice sottoconto sparito) risolto aggiornando il `$fillable` di `Conto.php`.

**Esito Validazione Finale:** Tutti i test del modulo Gestionale (`tests/Feature/Gestionale`) inclusi flussi HTTP, service, policy e sync Inbox F24, risultano **VERDI (160+ assertions passate in isolation e in suite)** (Data: 2026-06-18). La release v1.9.1 lato backend per l'area pagamenti può considerarsi solida e pronta per il merge.

---

### 📋 Post-v1.9.1

*(Stato verificato sul codice il 2026-07-26 — la numerazione v1.9.2/v1.9.3 di questa lista è
storica: nella roadmap attuale queste voci sono state rinumerate, vedi tag `→ Roadmap` su
ciascuna.)*

- **⚠️ Acconti, anticipi admin, compensazione pura, assegni** — *(era → v1.9.2)*
  **→ Roadmap:** `v1.10.0` — "Pagamenti Avanzati" (*"idea da valutare in corso d'opera —
  scoping non ancora definitivo"*, testualmente in roadmap.md). **Verificato: parziale.**
  Solo la **compensazione NC>Fattura** ha vero backend (`TipoAllocazioneFattura::COMPENSAZIONE`,
  `PagamentoFornitoreService::trovaNoteCreditoCompensabili()`, test di netting verdi) — ma il
  docblock dello stesso service la elenca ancora come "rimandata", e non è confermato che il
  frontend copra il caso di compensazione pura al 100% (nessun euro di cassa). Acconti fornitori,
  anticipi amministratore e assegni con doppia data: **assenti**, nessun case enum, nessuna
  colonna dedicata.
- **🔧 Export SEPA Pain.001.001.03** — *(era → v1.9.3)*
  **→ Roadmap:** `v1.10.1` — "Export SEPA" (sezione dedicata, rinumerata rispetto a questa
  lista). **Verificato: non implementato** — nessun generatore XML nel repo.
- **🔧 Feature 1 (IVA manuale bollette)** — *(era "da schedulare")* tuttora non ripresa in
  nessuna versione della roadmap attuale. Vedi sezione dedicata sopra (Segnalazioni
  beta.10) per lo stato verificato e la nota "va riportata o tolta".
- [x] Feature 2 (Modifica fattura pre-pagamento) → completata
- [x] Feature 4 (Modifica pagamento) → completata

---

## ✅ Decisione — Footer stampe configurabile (L. 4/2013 · art. 1129 · art. 71-bis disp. att.)

*(Decisione presa in data 2026-06-14. Duplica/precisa la sezione "✅ [AGGIUNTO]" in cima al
file — stesso campo `nota_legale_stampe`, qui il dettaglio implementativo completo.)*

**Decisione.** Campo testo libero `nota_legale_stampe` gestito tramite **Spatie Settings**.
Textarea in Impostazioni. Stampato as-is nel footer mPDF di tutte le stampe PDF.

**Scartato:** tabella `profili_stampa`, enum `tipo_soggetto`, campi strutturati — over-engineering.

**Verificato sul codice (2026-07-26): implementato per intero.** `PrintSettings` (Spatie
Settings, group `print`) → `ImpostazioniStampeController` → textarea in
`impostazioniStampe.vue` → `PdfService::generate()` inietta il valore in ogni stampa →
`pdf/base.blade.php` lo stampa nel footer mPDF su tutte le pagine (`sethtmlpagefooter
page="ALL"`). Tutte le 7 viste PDF del gestionale ereditano da `pdf.base`, quindi tutte
mostrano il footer configurato.

**Confermato anche nel changelog** — `docs/changelog.md:981`, rilasciato in **beta.9**:
*"Impostazioni Stampe PDF: Aggiunto un nuovo pannello di configurazione globale dedicato alle
stampe. È ora possibile definire una Nota Legale (es. professione esercitata ex l. 4/2013,
P.IVA, Polizza RC) che apparirà come piè di pagina in tutti i prospetti generati."* Un
miglioramento successivo (`docs/changelog.md:514`, beta successiva) ha aggiunto anche la data
di emissione della stampa sopra la nota legale nello stesso footer.

---

## Note varie

### 🔧 [APERTO] Helper di test `registraFatturaServiceTest()` — due forme incompatibili di `$ctx`

**File:** `tests/Feature/Gestionale/GestionaleTestHelpers.php` — funzione `registraFatturaServiceTest()` (riga ~199).

**Causa.** L'array `$ctx` passato alla funzione viene costruito in due forme posizionali diverse
a seconda di chi lo chiama: da `setupContabile()` direttamente (capitolo in posizione 4) oppure
da `setupPagamentiService()`/`setupPagamentiHttp()` (capitolo in posizione 5, perché in mezzo c'è
anche `contoCorrenteId`). La destrutturazione attuale
(`[$condominio, $esercizio, $gestione, $fornitore, , $capitolo] = $ctx;`) è corretta solo per la
seconda forma.

**Impatto.** Nullo sulla produzione — è un helper di test. Chi lo chiama con la prima forma senza
un override esplicito di `righe` ottiene un errore di vincolo FK rumoroso (il test fallisce, non
produce un falso verde con dati sbagliati). Decine di test esistenti
(`PagamentoFornitoreServiceTest.php`, `PagamentoFornitoreControllerTest.php`,
`HardeningFase0Test.php`, `TenancyScopingTest.php`, `StornoPagamentoControllerTest.php`,
`SyncF24WithPagamentoTest.php`, `GirocontoTest.php:392`) dipendono dalla forma attuale — non
toccare la destrutturazione senza aggiornarli tutti.

**Fix proposto.** Due helper distinti con nomi che rendono esplicita la forma attesa (es.
`registraFatturaDaContestoBase()` / `registraFatturaDaContestoPagamenti()`), oppure un array
associativo a chiavi nominate invece che posizionale.

**Stato:** Aperto, non urgente — non causa dati sbagliati, solo un errore rumoroso per chi scrive
un nuovo test senza saperlo. Rimandato a una sessione dedicata (2026-07-26).

---

### 🔧 [APERTO] Incassi rate — audit "chi ha stornato" mai stato funzionante

**File:** `app/Http/Controllers/Gestionale/Movimenti/IncassoRateController.php` — riga ~329.

**Causa.** Il codice legge `$scrittura->dati_extra['stornato_da_user_id']` per popolare il nome
di chi ha stornato un incasso, ma `dati_extra` non è mai esistita come colonna su
`scritture_contabili` (nessuna migrazione la crea) — e `StornoIncassoRateAction` non la scrive
comunque. In più il confronto di stato usava `'stornato'` invece del vero valore `'annullata'`
(bug gemello di quello corretto in beta.29 sul contatore "Stornati", vedi changelog).

**Impatto.** Nullo su calcoli, quadratura, riparto — riguarda solo l'informazione "chi ha
stornato" nel dettaglio di un incasso, che non si è mai popolata da quando questo codice esiste.
Non è una regressione introdotta da beta.29: non ha mai funzionato.

**Fix.** Richiede o una migrazione (colonna `dati_extra` JSON su `scritture_contabili`) con
`StornoIncassoRateAction` aggiornata per scriverci dentro, oppure recuperare l'informazione dal
`created_by` della scrittura di "rettifica" figlia (da verificare se già valorizzato
automaticamente da un observer).

**Stato:** Aperto, bassa priorità — puramente informativo/audit, nessun impatto contabile.
Rimandato (2026-07-26).