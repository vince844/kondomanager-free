# Guida «Tabelle millesimali» per il sito ufficiale — progetto

<!-- verifica-documentazione -->
> ⚠️ **DA AGGIORNARE — la beta.61 ha cambiato la pagina che questa guida descrive.** La pagina del
> sito non nomina nessuna delle funzioni nuove: associazione in blocco con anteprima, ricerca,
> ordinamento, il «+» sull'ultima riga, e soprattutto il **millesimo che si può lasciare vuoto** con
> l'avviso alla generazione del piano rate. Verificato il 20/08/2026 con `grep`: zero occorrenze di
> «associa in blocco», «ricerca», «ordinamento», «senza millesimo». Le sezioni dove entrano esistono
> già («Inserire i millesimi», «Quando qualcosa manca»), quindi è un aggiornamento, non una
> riscrittura. **Va fatto in Fase 5, dopo il port**, con gli screenshot presi dall'ufficiale.
>
> Le due righe di questo documento diventate false con la beta.61 sono corrette qui sotto e marcate.
>
> **Stato al 19/08/2026: SCRITTA.** La pagina esiste: `docs/tabelle-millesimali-condominio.html` sul sito
> ufficiale (capitolo «Immobili», dopo Pertinenze), con tre screenshot reali (condominio «Le Terrazze», id 52 —
> elenco tabelle, creazione, e la pagina quote della tabella «PROPRIETA GENERALE» che somma 1.100,20), copertina
> OG dedicata, sidebar aggiornata su tutte e 34 le pagine di `docs/`, catena prev/next e `sitemap.xml` a posto,
> `src/verifica-pagina.sh` verde. Link reciproci con due articoli di blog; il terzo (`gestire-scale-...`) è
> linkato dalla guida ma non ancora nel verso opposto. Questo documento resta come traccia della ricognizione
> che ha preceduto la scrittura — non va più consultato per «cosa manca», ma per «perché la pagina dice quello
> che dice».
>
> **Provenienza.** L'analisi è del 18/08/2026: otto agenti su quattro aree, ognuna verificata due volte in
> contraddittorio, per 185 fatti con la prova nel codice, più dodici sonde Pest eseguite davvero (elencate in
> appendice). La ricognizione è stata condotta **a metà della beta.57**, con modifiche non committate proprio
> sulle tabelle.
>
> **Ri-verificato il 19/08/2026 su 1.10.0-beta.59, albero pulito**, per i punti portanti: gli otto tipi di
> tabella sono committati in `CreateTabellaRequest.php:32` e `UpdateTabellaRequest.php:32`; le regole delle
> quote sono ancora `required|numeric` e nient'altro; `grep -rn "tabella->tipo" app/` è vuoto; la
> normalizzazione è dove era (`CalcoloQuoteService.php:770` e `:808`); `attiva`, `data_inizio` e `data_fine`
> non compaiono in nessuno dei tre servizi di riparto; la frase falsa di `TabelleGuide.vue:125` è ancora lì.
> La beta.58 e la beta.59 non toccano le tabelle. I conteggi del sito sono stati ricontati oggi e coincidono.
<!-- /verifica-documentazione -->

---

## Cosa esiste già, e cosa manca

| | |
| :--- | :--- |
| Guide in `docs/` sulle tabelle millesimali | **nessuna** (33 pagine, zero) |
| Articoli in `blog/` sull'argomento | **cinque**: `tabelle-millesimali-come-verificarle`, `verificare-riparto-prima-assemblea`, `gestire-scale-riscaldamento-unico-bilancio`, `tabella-cancellata-invece-che-usata-pertinenze`, `ordinamento-tabelle-solo-dieci-righe-visibili` |
| Guida dentro il gestionale | `resources/js/components/guides/TabelleGuide.vue`, **e contiene due affermazioni false** (vedi «Difetti emersi») |

Il capitolo di destinazione nella sidebar è **«Immobili»**, oggi con una voce sola (Pertinenze). Il nome del
capitolo è il contenitore, non il tema della prima pagina che ci è finita dentro: aprire un capitolo
«Millesimi» per una guida sola va contro la regola di `flusso_di_lavoro_rilascio.md:1377-1379`.

---

## Semantica verificata nel codice — non riderivarla

Questa è la parte costosa. Ogni riga qui sotto ha la sua prova nel sorgente; le voci marcate **[sonda]** sono
state eseguite, non lette.

### 1 · Creare la tabella

- Il modulo ha **nove campi in tre riquadri**: configurazione principale (nome, tipologia, unità di misura,
  numero decimali, descrizione), assegnazione strutturale (palazzina, scala), impostazioni avanzate (note,
  spunta «associa tutti gli immobili esistenti»). `TabelleNew.vue:146,160,197,233,247,269,283,307,319-330`.
- **Obbligatori sono quattro**: nome, tipologia, unità di misura, numero decimali (`CreateTabellaRequest.php:31-41`).
  A schermo nessuno è contrassegnato: niente asterischi, e i tre menù si svuotano con la «x». L'errore compare
  solo premendo «salva tabella». **[sonda]**
- **Le tipologie sono otto**: standard, ascensore, scale, riscaldamento, acqua, lastrico, speciale, altro.
  «Scale» è comparsa nelle tendine **solo con la beta.57**: prima esisteva nell'enum del database e
  l'importatore la assegnava, ma la tendina ne offriva sette e la validazione rifiutava l'ottava — una tabella
  importata «SCALE A» non si poteva più salvare, nemmeno cambiandole solo la descrizione
  (`tests/Feature/Gestionale/TipoTabellaScaleTest.php`).
- **La tipologia non entra in nessun calcolo.** `grep -rn "tabella->tipo" app/` è vuoto. Decide l'icona,
  l'etichetta nell'elenco, e una sola comodità: scegliendo «Ascensore» in creazione, se l'unità di misura è
  ancora «millesimi» passa a «quote» (`TabelleNew.vue:109-113`). Con «Scale» non scatta niente.
- **Le unità di misura sono cinque**: millesimi, persone, quote, kilowatt, metri cubi. Non cambiano
  l'aritmetica — il motore fa sempre valore diviso somma dei valori — solo etichette e decimali.
- **I decimali vanno da 0 a 5**, predefinito 2. Il tetto è quello della colonna, `decimal(12,5)`: restano
  sette cifre davanti alla virgola, e un sesto decimale viene arrotondato dal database, non troncato. **[sonda]**
- **Palazzina e scala non delimitano niente.** `tabella->palazzina_id` e `tabella->scala_id` non sono letti da
  nessuna parte in `app/` fuori dal salvataggio e dalla colonna «Palazzina / Scala» dell'elenco. **[sonda]**
- **Il nome non deve essere univoco**: due invii identici creano due tabelle, senza avviso. **[sonda]** E
  l'iniziale viene forzata maiuscola in visualizzazione (`TabellaResource.php:22`): riaprendo la tabella in
  modifica quella maiuscola **entra nel database** e non si torna indietro dalla pagina. **[sonda]**
- **Nell'elenco compaiono le note, non la descrizione** (`columns.ts:22`). Il segnaposto del campo note dice
  «riservate agli amministratori»: sono invece il testo mostrato sotto il nome della tabella.
- Alla creazione il programma imposta `attiva = vero` e `data_inizio = oggi`, campi che **nessuna pagina
  mostra e nessuna pagina cambia**. L'importatore non scrive `data_inizio` affatto.
- **Non serve nessun permesso dedicato**: basta l'accesso al pannello amministratore, non esiste una policy
  sul modello Tabella, e le richieste di modifica non verificano che la tabella appartenga al condominio
  dell'indirizzo. **[sonda]** Dalle pagine non ci si arriva, ma è una ragione per non scrivere che i condomini
  sono separati fra loro.

### 2 · Associare le unità e inserire i millesimi

- Si arriva alla schermata da «Tabelle» → elenco, in tre modi (nome cliccabile, menù «⋮ → Millesimi», oppure
  in automatico subito dopo aver salvato una tabella nuova). **Non esiste una voce di menù «Millesimi».**
- **La tendina propone tutte le unità del condominio**, meno quelle già in elenco. Il filtro per palazzina o
  scala non viene mai applicato: si può associare a una tabella «Scala A» un'unità della scala B. **[sonda]**
- **La spunta «associa tutti gli immobili esistenti» esiste solo in creazione.** Crea una riga per ogni unità
  **con il valore vuoto**, ignora palazzina e scala, ed è una fotografia del momento: le unità inserite dopo
  vanno aggiunte a mano. In modifica la spunta non c'è. **[sonda]**
- **Il valore si scrive con il punto**, unica pagina del gestionale in cui il separatore mostrato e salvato è
  il punto. La virgola è tollerata e raddrizzata all'uscita dalla casella.
- **Il limite dei decimali è solo della casella**: il server accetta qualunque numero. Un `333.33333` su una
  tabella a due decimali si salva così com'è. **[sonda]** E accetta anche i **negativi**: non sono digitabili,
  ma nulla li ferma da un'importazione. Il totale a piè di pagina li esclude, il motore salta la riga ma
  calcola il denominatore con `sum('valore')`, che il negativo lo include. **[sonda]**
- **Abbassare i decimali riscrive i valori.** In archivio restano com'erano, ma la pagina li ridisegna
  arrotondati alla prima riapertura e il primo «salva quote» — anche senza toccare nulla — fissa
  l'arrotondamento. La digitazione tronca, il caricamento arrotonda: due regole diverse sullo stesso campo.
- **Il totale in fondo non giudica.** Nessun confronto con 1.000 né con un totale atteso; nessun blocco,
  nessun colore. È scritto nel codice che è deliberato (`QuoteList.vue:292-312`): sui dati veri nove tabelle
  su quindici non sommano a 1.000 e sono tutte corrette. **[sonda]** Il confronto con un totale dichiarato è
  stato progettato e messo da parte l'11/08/2026.
- **Il salvataggio è tutto o niente.** Ogni riga vuole immobile e valore: con una sola casella vuota su due
  righe il database resta a zero righe, si perde anche quella già completa. **[sonda]** Lo zero invece passa,
  e per il motore un'unità a zero è un'unità che non paga.
- **Il cestino non chiede conferma**, e al salvataggio le righe tolte vengono cancellate davvero. Salvando a
  modulo vuoto si svuota l'intera tabella, anche se è «in uso». **[sonda]**
- **Uscendo si perde tutto in silenzio.** L'avviso «uscire senza salvare?» esiste su un solo percorso, il
  collegamento dentro il dialogo delle unità. «Annulla» e «torna alle tabelle» sono link normali e non
  chiedono niente; `grep beforeunload resources/js/` è vuoto.
- **Il tetto alle righe è il numero di unità in anagrafica**, non un limite del programma — è costato una
  segnalazione sul forum il 15/08/2026, e il messaggio è stato riscritto nella beta.55. Su un condominio senza
  unità esce comunque, nella forma «hai già associato tutte le 0 unità immobiliari».
- Nell'elenco, **«unità associate» conta le righe**, comprese quelle senza valore: una tabella creata con la
  spunta e mai compilata mostra il numero pieno. E **«orfana» non parla dei millesimi**: dice che nessun
  capitolo di spesa la sta usando.

### 3 · Come la usa il motore

- **Il legame nasce sulla voce di spesa, non sulla tabella**: si apre la voce del piano dei conti e si aggiunge
  la tabella (`routes/gestionale.php:194-201`). La tabella non sa a quali spese è collegata, e in una gestione
  nuova il piano non si duplica: i collegamenti vanno rifatti uno per uno.
- Una voce di spesa nuova **nasce con una tabella obbligatoria a coefficiente 100**, non mostrato e non
  modificabile in quella finestra. Per aggiungerne una seconda bisogna **prima abbassare** il coefficiente
  della prima: finché il residuo è zero il pulsante «aggiungi» è spento.
- **Il coefficiente è un peso relativo, non una fetta di spesa.** Con una tabella sola, 50 riparte comunque il
  100% (**[sonda]**: € 1.000,00 distribuiti per intero). Con due tabelle al 30 e al 20 il risultato è 60/40,
  non «50 fuori». **[sonda]** L'etichetta sotto il campo dice il contrario: «percentuale della spesa attribuita
  a questa tabella» (`ModalAssociaTabella.vue:236-238`).
- **La normalizzazione è sulla somma reale**, mai su 1.000 fisso. Una tabella che somma 997 riparte tutto:
  500 e 497 su € 1.000,00 danno **€ 501,50 e € 498,50**. **[sonda]** Il tre per mille mancante non resta
  scoperto e non genera nessun avviso.

**Tre casi diventano «scoperto», e si comportano in modo diverso:**

| caso | motivo | effetto |
| :--- | :--- | :--- |
| voce senza nessuna tabella | `conto_senza_tabella` | blocca la generazione del piano rate |
| tabella collegata ma vuota o tutta a zero | `tabella_senza_millesimi` | la sua fetta è tolta dall'importo, gli altri **non** la pagano **[sonda]** |
| unità associata **senza millesimo** *(beta.61)* | `millesimo_non_compilato` | blocca la generazione; accettando, quell'unità **sparisce dal piano** e la sua quota la pagano le altre |
| unità senza titolari utilizzabili | *(nessuno: è il caso storico della 1.9.1)* | la sua quota è tolta dal totale: il condominio incassa meno **[sonda]** |

Per procedere serve accettare gli scoperti e scrivere una motivazione di almeno dieci caratteri, che resta sul
piano e genera un promemoria in bacheca. **Il blocco arriva solo alla generazione del piano**, che può essere
settimane dopo: la scheda della voce nel frattempo dice soltanto «nessuna tabella associata», senza colore né
allarme.

- **La ripartizione fra soggetti viaggia con il collegamento**: proprietario, inquilino, usufruttuario, somma
  obbligata a 100. Senza ripartizioni registrate vale proprietario al 100%. **Il nudo proprietario non è
  selezionabile**: la colonna è un enum a tre valori, verificato sul MySQL reale. **[sonda]** Esiste come
  titolare sull'unità dalla beta.43, ma nella ripartizione solo come esito della cascata.
- **La cascata dei ruoli è silenziosa**: inquilino → usufruttuario → proprietario → nudo proprietario, e così
  via. **[sonda]** Nessun messaggio a schermo, solo una riga di debug nel log. In assemblea si legge il nome di
  chi ha pagato, non il ruolo configurato: un addebito «sbagliato» spesso è la cascata, non un errore.
- **Fra più titolari dello stesso ruolo la quota è un peso relativo**, non una frazione: due comproprietari
  70/30 su € 500,00 pagano € 350,00 e € 150,00, ma un proprietario unico a cui si scrive 50 paga tutto. **[sonda]**
- **`attiva` e le date non filtrano niente**: una tabella con `attiva = false`, inizio 1999 e fine 2001 addebita
  comunque. **[sonda]** L'unico modo di escluderla è scollegarla dalla voce di spesa; nemmeno la tendina della
  finestra di associazione la filtra.
- **La spunta «escluso» sulle righe non fa niente**: l'unità marcata esclusa paga come le altre. **[sonda]**
  Per escludere si toglie la riga o si mette zero — e lo zero non produce nessuno scoperto e nessun avviso: la
  sua parte la pagano gli altri, in silenzio.
- **Un'unità nuova non entra da sola nelle tabelle esistenti.** Nessun observer, nessun avviso: quell'unità
  semplicemente non paga, e non compare fra gli scoperti perché per quella tabella non esiste. **È l'unico
  ammanco che il programma non segnala in nessun modo.**
- Esiste un secondo modo, automatico, in cui una tabella finisce collegata: la spesa imprevista deliberata
  crea la propria voce di sopravvenienza e la collega da sé, con coefficiente 100
  (`FatturaPassivaService.php:969-998`).

### 4 · Ciclo di vita, e il rapporto con i piani rate

- **Una tabella collegata a una voce non si cancella**: il server rifiuta. Una tabella scollegata sì, anche se
  ha ripartito i piani degli anni passati, e con lei spariscono i millesimi (cascata). Le rate restano, il
  documento che spiegava come erano state calcolate no.
- **Cancellare un'unità immobiliare porta via le sue righe da tutte le tabelle**, senza chiedere niente.
- **Cambiare i millesimi non tocca un piano rate già generato.** E, correzione a una lettura affrettata dello
  snapshot: `rate_quote.regole_calcolo` **non conserva il millesimo usato** — contiene origine, importi,
  parametri di distribuzione e audit, e la tabella non ha nemmeno una colonna `tabella_id`. Dopo una modifica
  non esiste nessun posto dove leggere con quale millesimo quelle rate erano state calcolate.
- **Nessuna schermata segnala il disallineamento**: niente badge «da ricalcolare», niente avviso al
  salvataggio, niente voce in cruscotto.
- **Rigenerare è vietato con incassi registrati o rate già emesse.** Superata l'emissione, il piano è congelato
  sui millesimi vecchi finché non si annullano incassi ed emissioni. Il blocco alla modifica delle tabelle di
  una voce «in piano» vive **solo nella schermata**: i tre controller non lo controllano. La formulazione
  corretta per una guida è «passa dall'annullamento del piano», non «il sistema impedisce ogni modifica».

---

## Le frasi da non scrivere

Ognuna è stata smentita sul codice o con una sonda. Sono ordinate da quelle che un amministratore darebbe per
scontate.

**Sul totale e sui controlli**
1. «Il totale deve fare 1.000 e il programma controlla che torni.» — Nessun confronto, con niente.
2. «Se la somma non è 1.000 il gestionale avvisa o non ti fa salvare.» — Salva, e riparte comunque il 100%.
3. «Se una tabella somma 997, lo 0,3 per mille resta scoperto.» — Viene ridistribuito fra chi c'è.
4. «Il programma controlla che i millesimi siano valori validi.» — L'unica regola è che siano numeri: passano i negativi e i decimali oltre il limite dichiarato.
5. «Non puoi scrivere più decimali di quelli della tabella.» — La casella lo impedisce, il server no.
6. «Abbassare i decimali è una scelta di sola visualizzazione.» — Il primo salvataggio successivo li riscrive arrotondati.

**Sulla tipologia e sull'unità di misura**
7. «La tipologia Ascensore applica il 50/50 dell'art. 1124.» — Nessun codice legge il tipo. Vale identico per «Scale».
8. «Riscaldamento e Acqua attivano un calcolo dedicato.» — Etichette come le altre.
9. «Con metri cubi il riparto usa le letture dei contatori.» — I contatori non esistono: i valori si battono a mano. Il modulo è previsto per la v1.15.
10. «Metri cubi, kW o persone attivano un calcolo diverso.» — Cambia l'etichetta, non l'aritmetica.
11. «Cambiando unità di misura il programma converte i valori.» — Non converte niente.
12. «I tipi sono otto e lo sono sempre stati.» — «Scale» è selezionabile dalla beta.57: se la guida esce prima della stabile va detto da quale versione.

**Sul perimetro**
13. «Assegnando la tabella a una palazzina o a una scala il riparto si limita a quelle unità.» — È solo un'etichetta. **Lo afferma la guida dentro il programma**, ed è falso.
14. «Una tabella di scala propone solo le unità di quella scala.» — Propone tutte quelle del condominio, e la spunta «associa tutti» le associa tutte.
15. «Puoi disattivare una tabella o darle una data di fine validità.» — I campi esistono, nessuna pagina li cambia, nessun calcolo li legge.
16. «Puoi escludere un'unità con la spunta escluso.» — La colonna c'è e non la legge nessuno.
17. «Le tabelle di un condominio sono isolate da quelle degli altri.» — Le pagine sì, le richieste no.

**Sul coefficiente e sui soggetti**
18. «Il coefficiente attribuisce quella percentuale di spesa alla tabella.» — È un peso relativo: con una tabella sola, 50 riparte il 100%.
19. «Se i coefficienti non arrivano a 100 la parte che manca resta fuori.» — 30 e 20 ripartono tutto, in rapporto 60/40.
20. «Puoi affiancare subito una seconda tabella.» — Prima si abbassa il coefficiente della prima.
21. «Puoi indicare il nudo proprietario fra i soggetti.» — Le caselle sono tre.
22. «Le percentuali fra i soggetti accettano i decimali.» — Solo alla creazione della voce: in modifica devono essere interi.
23. «Il programma avvisa quando addebita a un ruolo diverso da quello impostato.» — La cascata è muta.
24. «La percentuale sul legame persona-unità riduce quanto quella persona paga.» — È un peso fra titolari dello stesso ruolo.

**Su cosa succede quando manca qualcosa**
25. «Se una voce non ha tabelle il programma la segnala subito, oppure la ignora.» — Falso in entrambi i versi: blocca, ma solo alla generazione del piano rate.
26. «Se manca il titolare di un'unità la sua quota viene ripartita fra gli altri.» — Viene tolta dal totale: il condominio incassa meno.
27. «Un'unità con millesimi a zero genera uno scoperto, o almeno un avviso.» — Viene saltata in silenzio.
28. «Le unità nuove entrano da sole nelle tabelle esistenti.» — Nessun automatismo e nessun avviso.
29. «Se una tabella non è compilata te ne accorgi dall'elenco.» — «Unità associate» conta le righe, non i valori.
30. «Lo stato Orfana segnala una tabella senza millesimi.» — Segnala una tabella che nessuna spesa sta usando.

**Sul lavoro di tutti i giorni**
31. «Spunta “associa tutti” e la compili con calma.» — Le righe nascono vuote e non si salva finché non sono tutte piene.
32. «Puoi rilanciare “associa tutti” quando aggiungi un'unità.» — La spunta esiste solo alla creazione.
33. «Se non spunti la casella torni all'elenco.» — Alla pagina dei millesimi si arriva sempre; la spunta decide solo se è già popolata.
34. «Cancellare una riga chiede conferma.» — Agisce subito.
35. «Uscendo dalla pagina il programma ti avvisa che perderai i dati.» — Solo da un percorso su tre.
36. «Il messaggio “numero massimo di righe” è un limite del programma.» — È il numero di unità in anagrafica.
36-bis. *(beta.61)* «Una casella dei millesimi lasciata vuota è come scriverci zero.» — **No, ed è la
    distinzione che la pagina ora costruisce**: vuoto significa «non ancora compilato» e alla
    generazione del piano il programma si ferma per avvisarti; zero significa «questa unità non
    partecipa», è legittimo e non avvisa. Prima della beta.61 il vuoto non si poteva nemmeno salvare.
36-ter. *(beta.61)* «I decimali dichiarati dalla tabella arrotondano i valori salvati.» — Non più:
    governano come il valore si mostra, non cosa viene conservato.
37. «Il nome che scrivi è il nome che resta.» — L'iniziale diventa maiuscola e resta.
38. «La descrizione compare nell'elenco.» — Nell'elenco compaiono le note.
39. «Puoi correggere i millesimi anche dopo aver emesso le rate e ricalcolare.» — Con incassi o emissioni la rigenerazione è bloccata.
40. «Nel piano rate resta traccia del millesimo usato.» — Non resta.
41. «Serve un permesso specifico per gestire le tabelle.» — Basta l'accesso al pannello.
42. «I collegamenti voce-tabella si portano dietro la gestione nuova.» — Vanno rifatti uno per uno.

---

## Difetti emersi, da trattare fuori dalla guida

Non sono materiale per il sito: sono correzioni al prodotto. Elencati perché la ricognizione li ha trovati e
perché **una guida onesta li rende visibili**.

1. **`TabelleGuide.vue:125` dice il falso**: «puoi delimitare l'uso di una tabella… solo agli immobili che
   fanno fisicamente parte di quella scala». Non succede. È il caso più grave: è la guida dentro al programma.
2. **`TabelleGuide.vue:74-75`** lascia intendere il riparto «basandosi sulle letture individuali» e i
   contabilizzatori di calore: quelle letture non hanno un posto dove stare.
3. **`TabelleNew.vue:343` e `TabelleGuide.vue:127`** dicono che è la spunta ad aprire la schermata dei
   millesimi: si apre comunque.
4. **`TabelleNew.vue:343`** promette «tutte le quote e i parametri»: i parametri sono stati rimossi nella beta.50.
5. **`ModalAssociaTabella.vue:236-238`** chiama il coefficiente «percentuale della spesa attribuita a questa
   tabella»: con una tabella sola non è vero.
6. **Colonna «Stato»**: mostra «in uso / orfana» ma l'ordinamento agisce su `attiva`, che è vero per tutte —
   il clic sembra non fare niente.
7. **`escluso` e `regole_calcolo`** sono colonne morte; `escluso` sembra fatta apposta per una funzione che non c'è.
8. **Nessun controllo di appartenenza al condominio** in `CreateTabellaRequest`/`UpdateTabellaRequest` e in
   `TabellaController::update()`.
9. **`.env.shot` non è in `.dockerignore`** mentre il Dockerfile fa `COPY . /var/www/html/` (repo del sito).
   Oggi protetto solo dal fatto che il contesto di build arriva dal repository. Si nota scrivendo una guida con
   screenshot, perché è lì che il file va creato.

---

## Documenti correlati

- **`tabelle_millesimali.md`** — la specifica del *tipo manuale* e dello *scope filtering* per palazzina e
  scala. È dichiarata «non implementato». È il nesso da tenere presente: la frase falsa di
  `TabelleGuide.vue:125` promette esattamente lo scope filtering di quel documento, progettato e mai
  costruito. La guida pubblica deve descrivere il presente, non quella specifica.
- **`validatore_coerenza_millesimi.md`** — perché il controllo sul totale non esiste. Ridimensionato l'11/08/2026
  a «solo il totale a video»: il motore normalizza sulla somma reale, quindi il difetto è un riparto diverso da
  quello approvato, non un buco di cassa. È il documento da citare se qualcuno chiede perché la guida non può
  promettere una verifica.
- **`flusso_di_lavoro_rilascio.md`** — le regole di pubblicazione (riquadro beta, badge di versione, coppia
  guida + articolo, screenshot, verifica del wiring). Dove questo documento e quello divergono sui numeri
  (marcatori beta, larghezze degli screenshot), la fonte è il `grep`, non la frase scritta.

---

## Come si aggancia al sito — meccanica verificata

- **Modello da clonare: una delle 14 guide «complete»** (quelle con `og:title`; le altre 19 sono sul modello
  magro). Le keywords non sono un marcatore affidabile: sono 15, non 14.
- **La sidebar è HTML duplicato**: 33 pagine × 2 varianti (desktop `doc-link`, mobile `block px-3 py-1.5`) =
  **66 blocchi da toccare**. Una sostituzione agganciata a `doc-link` prende solo il desktop: è già successo il
  05/08/2026.
- **`genera_sidebar_docs.py` NON va lanciato così com'è.** Il suo albero è fermo a 4 capitoli e 16 voci mentre
  le pagine ne hanno 5 e 18: cancella «Immobili → Pertinenze» e «Registri → Stato Patrimoniale» da tutte e 33
  le pagine, e stampa «senza perdite» perché confronta il risultato con sé stesso. Prima va riallineato
  l'albero. *(Ricontrollato il 19/08/2026: ancora fermo.)*
- **Solo il desktop marca la pagina corrente con `active`**: clonando una pagina resta l'`active` della pagina
  d'origine in tutti e 66 i blocchi. Non è mai successo, quindi va controllato di proposito.
- **Catena prev/next lineare su tutta la sidebar**, non per capitolo. Una seconda voce in «Immobili» si inserisce
  fra `pertinenze-condominio-box-cantina.html` (Successiva) e `piano-dei-conti-condominio.html` (Precedente):
  tre file da toccare, non uno.
- **`sitemap.xml` a mano**: una `<url>` con `<loc>`, `<lastmod>` in AAAA-MM-GG e `<priority>` 0.7, **senza**
  `changefreq`. Lo script della sidebar non la tocca.
- **`docs/index.html` non ha un indice delle guide**: l'unico elenco è la sidebar. La regola della card in testa
  a `#postGrid` riguarda `blog.html`, non le guide.
- **Verifica del wiring**: contare `href="<slug>.html"` pagina per pagina — deve dare esattamente **2**, e **3**
  solo sui due vicini di prev/next. Contare lo slug nudo non serve: nella pagina nuova compare 5 volte da solo
  (canonical, og:url e tre volte nel JSON-LD).
- **`src/verifica-pagina.sh` non guarda la sidebar**: una pagina irraggiungibile dal menù passa con cinque ✓ e
  uscita 0. È stato dimostrato su una copia usa-e-getta.
- **Badge di versione**: unico e identico su tutte e 33 le pagine (oggi v1.9.1), è quello che il visitatore
  scarica. Non si tocca fino alla Fase 7.
- **Riquadro ambra `<!-- BETA-1.10: … -->`** obbligatorio, perché gli otto tipi esistono dalla beta.57, e la
  regola impone **la coppia**: guida in `docs/` **e** articolo in `blog/`.
- **Screenshot**: solo con `src/scatta-app.sh` (autenticato come `shot@kondomanager.local`, `.env.shot`,
  app in ascolto su `http://127.0.0.1:8001`). Il browser degli strumenti naviga ma non salva su disco.
  Nome `<sigla>-<sezione>.png` in `assets/img/docs/` — la sigla corta è la pratica, lo slug intero è
  l'eccezione. **L'invariante è la larghezza**, non l'intera coppia di dimensioni: le figure sono `w-full`.
- **`width` sull'`<img>` è il gate del lightbox**: sotto 700 l'immagine non si ingrandisce, e il ripiego
  `naturalWidth` non salva. Il blocco lightbox è CSS in testa + IIFE in fondo: `docs/users.html` lo ha
  dimenticato e ha uno screenshot non ingrandibile.
- **L'indice «in questa pagina» è scritto a mano** e il suo scrollspy non funziona sul modello moderno (cerca
  `section[id]`, le 14 guide recenti usano `<h2 id>`). Si eredita il difetto: non è un errore di clonazione.
- **Il CSS non è un CDN**: `assets/css/kondo.css` è compilato da `npm run build:css`. Una classe Tailwind mai
  usata prima nel sito **non esiste nel file** e non produce nessun errore: semplicemente non si vede.
- **Copertina OG propria**, generata con `src/genera-og.sh`. Non riusare
  `kondomanager-tabelle-millesimali-og.png`: è la copertina dell'articolo di blog.
- **Rimandi reciproci guida ↔ articolo** in entrambe le direzioni. Sui millesimi ci sono già cinque articoli e
  nessuna guida.
- **Gli strumenti del sito sono fuori da git** (`*.py` e `*.sh` esclusi): esistono solo nella cartella locale.
- **Il repo del sito ha lavoro non committato** (dieci file modificati, tre non tracciati, fra cui
  `docs/users.html`, `docs/settings.html` e `sitemap.xml`): «sul sito c'è già X» va sempre qualificato.
  *(Ancora vero il 19/08/2026.)*

---

## Struttura proposta — **da decidere, non verificata**

Il workflow non è arrivato a proporre una scaletta: questa è una proposta, marcata come tale.

**Una pagina sola**, `docs/tabelle-millesimali-condominio.html`, capitolo «Immobili», fra Pertinenze e Piano
dei Conti. Le guide in `docs/` sono monopagina, e spezzare in due significherebbe raddoppiare i 66 blocchi.

Sezioni (`<h2 id>` + voce nell'indice laterale, scritta a mano):

1. **A cosa serve una tabella** — il legame nasce sulla voce di spesa, non sulla tabella.
2. **Crearla** — i quattro campi obbligatori, e che tipologia e unità di misura sono etichette.
3. **Inserire i millesimi** — la schermata, il punto come separatore, il salvataggio tutto-o-niente.
4. **Il totale non deve fare 1.000** — la sezione che vale la guida: il motore normalizza sulla somma reale,
   e nove tabelle su quindici, sui dati veri, non fanno 1.000 e sono corrette.
5. **Collegarla a una spesa** — il coefficiente come peso relativo, le due tabelle sulla stessa voce.
6. **Chi paga davvero** — proprietario/inquilino/usufruttuario, la cascata silenziosa, i comproprietari.
7. **Quando qualcosa manca** — i tre scoperti e la motivazione obbligatoria al piano rate.
8. **Cambiare i millesimi dopo** — il piano non si aggiorna, la rigenerazione e i suoi divieti.

Titolo, H1 e voce di menù sono tre stringhe distinte: `<title>` lungo con la parola chiave, H1 corto, menù
cortissimo (colonna da 224 px) — per esempio «Tabelle millesimali». Maiuscole solo a inizio frase, anche dopo
i due punti del title; le uniche eccezioni ammesse sono i nomi propri delle schermate («Piano dei Conti»,
«Libro Giornale», «Stato Patrimoniale»).

---

## Appendice — le dodici sonde

Eseguite come test Pest il 18/08/2026 e poi rimosse. Sono elencate perché il risultato è la cosa che costa
rifare, e perché ricostruirle da qui è mezz'ora.

| | cosa provava | esito |
| :--- | :--- | :--- |
| **A** | tabella che somma 997 | riparte comunque il 100%: € 501,50 e € 498,50 su € 1.000,00 |
| **B** | coefficiente 50 su tabella unica | distribuisce il 100% della spesa |
| **C** | due tabelle a 30 e 20 | si comportano come 60/40 |
| **D** | quota 50 su titolare unico | addebita comunque tutta la quota dell'unità |
| **E** | voce senza nessuna tabella | scoperto `conto_senza_tabella`, zero quote generate |
| **F** | tabella non attiva e fuori data | usata comunque dal motore |
| **G** | coefficiente su inquilino, unità senza inquilino ma con usufruttuario | paga l'usufruttuario (cascata) |
| **H** | unità senza anagrafica attiva | scoperto solo per la sua fetta, gli altri non pagano |
| **H2** | anagrafica presente ma `attivo = false` | identico ad H, centesimo per centesimo |
| **I** | due comproprietari 50/50 e 70/30 | divisione proporzionale sul campo quota |
| **J** | tabella collegata ma senza millesimi | scoperto `tabella_senza_millesimi` |
| **K** | ripartizione proprietario 70 / inquilino 30 | € 700,00 e € 300,00 |
| **L** | soggetto `nuda_proprietario` nella pivot | rifiutato dal vincolo di integrità |

Altre sonde, eseguite sulle schermate e non sul motore, sono citate nel testo come **[sonda]**: validazione dei
campi obbligatori, decimali oltre il limite, valori negativi, salvataggio parziale, svuotamento di una tabella
«in uso», doppio nome, maiuscola forzata, palazzina di un altro condominio.
