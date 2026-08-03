# Changelog

Tutte le modifiche rilevanti a Kondomanager sono documentate in questo file.

Il formato segue [Keep a Changelog](https://keepachangelog.com/it/1.0.0/)
e il progetto adotta il [Versionamento Semantico](https://semver.org/lang/it/).

---

## [1.10.0-beta.37] - La Nota di Credito Tornava un Costo

Una nota di credito da 1.000,00 € + IVA. La si riapre in modifica — per correggere una data, una causale, qualunque cosa — e la si risalva senza toccare gli importi. A database la nota **cambia segno**: da −1.220,00 € a +1.220,00 €. Cioè smette di essere un accredito e diventa una spesa, e il debito verso il fornitore invece di calare cresce del doppio.

**Nessuna modifica al database.** Nessuna migrazione e nessun dato corretto all'indietro. Una nota di credito ribaltata si riconosce con certezza — è una nota di credito con il totale positivo, cosa che non può esistere — e si sistema stornandola e registrandola di nuovo.

### Corretto

- **Riaprendo in modifica una nota di credito, gli importi cambiavano segno al salvataggio.** Il denaro di una nota di credito è salvato **già negativo**: è la registrazione ad applicare il segno meno, una volta sola, a righe e testata. Il form di modifica però ripresentava al server esattamente quei negativi, e il salvataggio riapplicava il segno per conto suo: meno per meno fa più.

  Non serviva toccare gli importi: bastava aprire e salvare. E nulla lo impediva — una nota di credito aperta è modificabile come qualunque altra fattura, e la validazione degli importi accetta i numeri negativi senza obiezioni.

  La registrazione di una nota di credito **nuova** non è mai stata interessata: lì le cifre si digitano positive e il segno lo mette il sistema, che è esattamente il comportamento a cui la modifica è stata riallineata.

- **Perché nessun controllo se n'era accorto.** La scrittura contabile continuava a **quadrare perfettamente**: il filtro che inverte DARE e AVERE sulle note di credito si applicava comunque, quindi le due colonne restavano uguali e il validatore non aveva niente da segnalare. A cambiare era da che parte stavano i conti — il costo tornava fra le spese invece che in rettifica — non l'equilibrio fra dare e avere.

  È la stessa forma del difetto della versione precedente: **un errore simmetrico è invisibile alla partita doppia**, perché sposta i due piatti della bilancia insieme.

### Due bonifiche, trovate mentre si costruivano i dati della guida

Nessuna delle due è arrivata da una segnalazione: sono emerse il 2 agosto usando il prodotto per popolare gli esempi. Piccole, ma entrambe di quelle che si manifestano nel momento peggiore.

- **Registrare un pagamento poteva far comparire una pagina di errore invece di un messaggio.** Ogni pagamento può portare con sé una chiave che protegge dal doppio invio: se la stessa richiesta arriva due volte — un doppio clic, un timeout di rete che fa ritentare il browser — il sistema riconosce la chiave e restituisce il pagamento già registrato invece di crearne un secondo. La protezione funziona; a essere rotta era la risposta ai casi che non sono un doppio invio.

  Quella chiave è unica su **tutte** le scritture contabili, non solo sui pagamenti — la usano anche i giroconti. Se la chiave apparteneva a un movimento che non era un pagamento, il sistema non aveva niente da restituire e si fermava con un errore tecnico, di quelli che nessuna schermata sa raccontare. E se apparteneva al pagamento di **un altro condominio**, era peggio: lo restituiva in silenzio, facendo credere che il salvataggio fosse riuscito mentre nel condominio corrente non era stato registrato nulla.

  Ora sono due casi distinti con un messaggio proprio — «la chiave è già utilizzata da un altro movimento contabile», «è di un pagamento di un altro condominio» — e in tutti e due si esce rigenerandola. Il vero doppio invio continua a comportarsi come prima: nessun pagamento duplicato.

- **Un avviso di PHP compariva registrando una fattura pregressa.** Sulle fatture pregresse la fonte della copertura è facoltativa — si può registrare un debito storico senza dire da dove sarà coperto — ma due dei tre tipi di copertura, «Rata 0» e «Fondo di riserva», la leggevano come se fosse sempre presente. Il terzo era già a posto. La registrazione andava comunque a buon fine, ma lasciava un avviso nei log a ogni fattura; e su un'installazione configurata per mostrare gli errori a schermo l'avviso finiva **davanti all'amministratore**, in mezzo a un'operazione perfettamente riuscita.

### Da sapere

Ora che gli importi di una nota di credito arrivano al form positivi, il controllo di sforo del budget li conta come farebbe con una spesa, e su una nota di credito può comparire l'avviso «salvataggio oltre budget». **Non è una novità di questa versione**: succede già oggi registrando una nota di credito nuova, perché lì gli importi sono positivi da sempre. Questa correzione allinea la modifica alla registrazione invece di lasciarle divergere; che il budget debba trattare a parte le note di credito è una questione aperta per entrambe le pagine, ed è annotata in roadmap.

### Sotto il cofano

- **Il difetto è fissato da tutte e due le parti**, come si fa dalla beta.35. `FatturaRegisterEdit.test.ts` monta la schermata di modifica e controlla il numero che finisce nella casella dell'importo, perché l'errore viveva nell'inizializzazione del form. `NotaCreditoModificaSegnoTest.php` fissa il contratto del server: che una nota di credito nasca negativa, che sia davvero modificabile — se una guardia la fermasse, il difetto sarebbe irraggiungibile e il test lo direbbe — e che due giri di apri-e-salva non facciano oscillare il segno. Un settimo test controlla che una fattura ordinaria non abbia cambiato comportamento.

## [1.10.0-beta.36] - Due e Cinquanta Diventavano Venticinquemila

Un pagamento a fornitore con 2,50 € di commissioni bancarie. Lo si riapre in modifica — per correggere una data, una causale, qualunque cosa — e nella casella delle commissioni non c'è più 2,50 €: c'è **25.000,00 €**. Salvando senza toccare quel campo, a database ne finivano 25.000,00 €: la cifra di partenza moltiplicata per diecimila.

**Nessuna modifica al database.** Nessuna migrazione e nessun dato riparato all'indietro. Chi trovasse una commissione gonfiata su un pagamento già modificato può correggerla riaprendo il pagamento in modifica: ora la casella mostra la cifra giusta, e basta risalvare.

### Corretto

- **Riaprendo in modifica un pagamento fornitore, le commissioni bancarie venivano moltiplicate per cento.** Il denaro nel gestionale viaggia in centesimi interi: 2,50 € sono `250`. La schermata di modifica riceveva quel `250` e — invece di dividerlo per cento per riportarlo in euro, come vuole una casella in cui si digitano euro — lo moltiplicava di nuovo, mostrando `25000`. Al salvataggio quel numero veniva riconvertito in centesimi una seconda volta, e a database arrivavano 2.500.000 centesimi.

  Il difetto colpiva **anche senza toccare il campo**: bastava aprire e salvare. Gli altri importi della stessa schermata — lordo, ritenuta, netto — erano trattati correttamente: erano le sole commissioni ad avere una conversione di troppo, dentro lo stesso oggetto.

  La registrazione di un pagamento nuovo non è mai stata interessata: lì la casella parte da zero e la conversione avviene una volta sola, al momento giusto.

- **Perché nessun controllo se n'era accorto.** La cifra gonfiata entrava in partita doppia da tutte e due le parti — a debito di «spese bancarie» e a credito della banca — quindi la scrittura **quadrava perfettamente**. Il validatore di quadratura, che è la rete di sicurezza del modulo contabile, non aveva niente da segnalare. L'unico segnale possibile era il saldo del conto corrente che scendeva di 25.000 € invece che di 2,50 €, e un eventuale blocco per «saldo insufficiente» su un pagamento che l'amministratore non aveva modificato nell'importo.

### Sotto il cofano

Il modulo del denaro lato client sapeva convertire gli euro digitati in centesimi, ma **non sapeva fare il contrario**: la conversione inversa — quella che serve ogni volta che un form di modifica si precompila con un importo già salvato — non esisteva, e chi ne aveva avuto bisogno se l'era scritta a mano. Al contrario. Ora `centsToEuro` sta accanto a `euroToCents` in `resources/js/lib/gestionale/money.ts`, con scritto in chiaro qual è il confine di ingresso e quale quello di uscita.

- **Il difetto è fissato da tutte e due le parti**, come si è stabilito nella beta precedente. Da un lato `PagamentoEdit.test.ts` monta davvero la schermata e controlla il numero che finisce nella casella — perché l'errore viveva nell'inizializzazione del form, dove una prova sulla sola logica non sarebbe arrivata. Dall'altro `PagamentoCommissioniRoundTripTest.php` fissa il riferimento del server: che la schermata riceva centesimi interi, e che aprire e risalvare due volte di fila lasci la cifra dov'era.

## [1.10.0-beta.35] - Due Aritmetiche per lo Stesso Numero

Un amministratore segnala dal forum: registrando una fattura con ritenuta d'acconto, il riquadro **«Netto da pagare»** del form dice 373,12 €, ma la fattura salvata — quella che compare in Gestionale › Movimenti › Fatture Passive — ne vale 373,11. Un centesimo, su un numero che nessuno ricontrolla due volte perché è il totale.

**Nessuna modifica al database.** Nessuna migrazione e nessun dato riparato. Per il difetto segnalato non serve: le fatture già registrate erano e restano corrette, a sbagliare era solo il numero mostrato *prima* di salvare. Un secondo difetto trovato strada facendo — vedi in fondo — poteva invece lasciare una riga da 0,01 € su una fattura pregressa: da questa versione non succede più, ma le fatture pregresse registrate prima non vengono corrette all'indietro.

### Corretto

- **Il netto da pagare mostrato nel form non coincideva con quello salvato.** I numeri della segnalazione: imponibile 316,20 €, IVA al 22%, ritenuta d'appalto al 4%. L'IVA vera è 69,564 € e la ritenuta vera 12,648 €; il form le mostrava arrotondate — 69,56 e 12,65 — ma calcolava il netto sui grezzi: 316,20 + 69,564 − 12,648 = 373,116, che arrotondato dà 373,12. Il server, che lavora in centesimi interi, sommava i numeri arrotondati — gli stessi che l'amministratore leggeva a schermo — e otteneva 373,11.

  Nessuno dei due sbagliava i conti: erano **due aritmetiche diverse per lo stesso numero**. Quella giusta è la seconda, perché il netto deve quadrare con i numeri scritti sulla fattura, non con i decimi di centesimo che non compaiono da nessuna parte.

- **Lo stesso difetto colpiva altri tre valori, per la stessa ragione.** Andati con lui:
  - **IVA su fattura con più righe.** Il server arrotonda l'IVA *riga per riga*, il form la arrotondava sul totale. Due righe da 1,15 € al 22% fanno 25 centesimi ciascuna, cioè 50; sommando i grezzi il form ne mostrava 51.
  - **Ritenuta a base ridotta.** Sulle provvigioni (23% su base 50% o 20%) il server arrotonda *due volte* — prima la base ridotta, poi la trattenuta — mentre il form faceva un solo passaggio. Su 1.000,13 € il primo dà 115,02 €, il secondo 115,01 €.
  - **Note di credito.** `Math.round` di JavaScript arrotonda −5,5 a −5, `round()` di PHP a −6. Sugli importi negativi i due lati divergevano di un centesimo in direzioni opposte.

- **Una base imponibile della ritenuta configurata a zero veniva letta come 100%.** In anagrafica fornitore, `perc_imponibile_ritenuta = 0` significa «nessuna base, nessuna trattenuta»; il form trattava lo zero come un campo vuoto e ci sostituiva il 100%, annunciando una ritenuta che il salvataggio poi non operava. Difetto trovato mentre si riscriveva il calcolo, non segnalato da nessuno.

- **Su una fattura pregressa la copertura dal debito storico partiva sottostimata di un centesimo, e questo finiva a database.** È l'unico difetto della beta che non riguardava solo ciò che si vede. All'invio, il form calcolava da sé il lordo del documento — imponibile × (1 + aliquota/100), di nuovo in euro — invece di usare il totale che stava già mostrando nel riquadro. Quando il prodotto cadeva esattamente su mezzo centesimo il float scendeva sotto la soglia e la copertura nasceva di 0,01 € più piccola del dovuto: il resto veniva registrato come eccedenza scoperta, cioè **una riga da un centesimo su «passate gestioni»**, con la nota «caricamento debito pregresso senza copertura esplicita», su una fattura che a schermo quadrava alla perfezione. Ora la copertura parte dallo stesso totale mostrato nel riquadro.

### Sotto il cofano

Il calcolo dell'anteprima era **copiato identico** nelle due pagine di registrazione e di modifica: due copie che potevano divergere l'una dall'altra oltre che dal server. Ora vive in un modulo solo, `resources/js/lib/gestionale/fatture/totali.ts`, scritto per ricalcare operazione per operazione `FatturaPassivaService` e `RitenutaService` — e lo dichiara nei commenti, riga per riga, perché la prossima persona che lo tocca sappia che non è codice libero di essere elegante: deve dire quello che dice il PHP.

Con lui se ne va anche la tabella delle aliquote di ritenuta, duplicata nelle stesse due pagine.

- **Il progetto ha ora dei test JavaScript.** Non ne aveva nessuno: il calcolo del denaro lato client era l'unica parte del sistema senza rete di protezione, ed è quella che l'amministratore legge prima di decidere. Sedici casi in `totali.test.ts` (`npm test`), fra cui i numeri esatti della segnalazione.

- I test PHP di riferimento in `TotaliFatturaArrotondamentoTest.php` fissano gli stessi numeri dal lato del server. Sono la metà mancante del patto: se un domani cambia l'arrotondamento del backend, si accendono e ricordano che c'è un secondo calcolo, in un altro linguaggio, che deve seguirlo.

## [1.10.0-beta.34] - Quello Che il Sistema Non Diceva

Tre difetti diversi con la stessa forma: il sistema sapeva qualcosa e non lo diceva. Sapeva **perché** una fattura non si poteva eliminare, e faceva sparire la voce dal menu. Sapeva che un saldo era ancora libero, e rispondeva che erano già stati integrati tutti. Sapeva che una fattura aveva la ritenuta, e lo scriveva in un punto ciano di sei pixel.

**Nessuna modifica al database.** Nemmeno una migrazione: le tre voci della beta che ne avrebbero richiesta una sono state rimandate — vedi in fondo, con il perché.

### Corretto — numeri e divieti

- **L'avviso di pagamento sommava il pregresso di tutte le gestioni.** Per scrivere la riga «Saldo iniziale» il servizio che alimenta la stampa filtrava per esercizio e condominio, senza dire quale gestione: con un'ordinaria e una straordinaria aperte nello stesso esercizio, l'avviso dell'una riportava anche il pregresso dell'altra. Nel caso misurato dal test, 800,00 € stampati al posto di 300,00 €. Nel verso opposto è peggio: un credito su un'altra gestione **abbassava** il dovuto — 300,00 € che diventano un credito di 200,00 € — e un numero troppo basso non fa reclamare nessuno, quindi non emerge mai. Il generatore del piano ha sempre filtrato per gestione: erano la stampa e il generatore a guardare assi diversi. La contabilità non era intaccata; il foglio che arriva al condòmino sì, ed è l'unico che nessuno riconcilia.

- **Il divieto sull'«Elimina» della fattura passiva smette di essere muto.** Il server rifiutava l'eliminazione per **sette** motivi distinti — copertura da fondo confermata, piano rate con rate emesse o incassate, piano approvato, fattura pagata o parziale, più scritture collegate, esercizio chiuso, fattura già stornata — ognuno con il suo messaggio. Il menu della riga ne controllava **due**, e quando la condizione era falsa la voce spariva e basta. Due difetti opposti, entrambi reali: chi non poteva eliminare non sapeva quale motivo lo riguardasse né come uscirne; e chi rientrava nei due controlli del menu poteva vedersi comparire la voce e ottenere comunque un rifiuto.

  Ora la voce è **sempre presente**: attiva quando si può, altrimenti disabilitata, con il motivo e **il rimedio** nel tooltip — storna il giroconto, riporta il piano in bozza, storna il pagamento. Il motivo è calcolato dal server e viaggia col dato: il menu e la guardia dell'eliminazione sono ormai la stessa riga di codice, quindi non possono più divergere.

### Aggiunto

- **La pagina dei Saldi Iniziali ha la sua guida.** Pulsante «Guida» nell'header, come le impostazioni e le tabelle millesimali. Non è un riassunto dell'interfaccia: racconta il segno e perché il meno non si digita, l'intestazione all'intero immobile o al singolo soggetto (art. 63), i tre modi in cui i saldi entrano nel piano rate, e il lucchetto per intero — chi lo chiude, che la soglia è l'emissione e non la generazione, che vale per la riga e non per la gestione, e le tre strade per correggere un saldo già bloccato. È la pagina da cui è nata la segnalazione delle beta.32 e .33: era anche l'unica senza una guida.

- **Anche la pagina delle Fatture passive ha la sua guida.** Cosa entra nell'elenco e cosa no, come si legge una riga, sforo e ratifica assembleare, i sette motivi del divieto sull'Elimina con il rimedio di ciascuno, e le coperture da fondo. Contiene una cosa che non era scritta da nessuna parte pur essendo coperta da test: **lo storno è reversibile** — eliminando la nota di credito che genera, la fattura originale torna allo stato calcolato dai pagamenti reali.

- **Stato vuoto con icona e spiegazione** negli elenchi di unità immobiliari, tabelle millesimali, gestioni e piani dei conti, sul modello già usato dai piani rate. Nuovo componente condiviso `TableEmptyState.vue`.

- **La ritenuta d'acconto si vede.** Nell'elenco fatture era un punto ciano di 6 px accanto al numero, con la spiegazione solo nel tooltip — cioè visibile solo a chi già sapeva di doverci passare sopra. Ora è il badge «Ritenuta», identico a quello dei Pagamenti fornitori: la stessa informazione con lo stesso aspetto nelle due pagine.

- Il menu di riga delle fatture era largo 210 px e mandava a capo le voci che spiegano un divieto («Elimina — non consentito», «Storna — prima i pagamenti»). Allargato: l'etichetta è la parte che fa il lavoro, accorciarla avrebbe risolto il sintomo togliendo la spiegazione.

- **«Non c'è ancora niente» e «la ricerca non ha trovato nulla» sono due stati diversi**, e fino a oggi nessun elenco li distingueva — nemmeno quello dei piani rate preso a modello, che a ricerca vuota invitava comunque a creare il primo piano. Con la paginazione lato server il totale è zero in entrambi i casi, quindi la distinzione si legge dai filtri nella query string. Senza questa separazione l'invito all'azione sarebbe comparso proprio a chi stava solo cercando: un peggioramento travestito da miglioramento.

### Corretto

- **Dalla pagina dei Saldi non si poteva cambiare condominio.** Unica pagina della sezione Struttura in cui il nome restava testo fisso. Non era un limite del componente d'intestazione, che accetta già la lista dei condomìni: era il controller a non passarla. Coperto da test.

- **Un saldo registrato dopo l'emissione tornava inchiedibile.** Ultimo posto in cui sopravviveva il difetto della beta.33. La schermata di creazione del piano rate decideva se offrire i saldi guardando il flag di gestione, che dalla beta.32 è un valore derivato: si accende appena **una** riga risulta bloccata. Bastava quindi il primo piano emesso perché il sistema rispondesse *«i saldi pregressi sono già stati integrati»* e non proponesse più nulla — comprese le righe inserite dopo, che la beta.33 aveva reso registrabili proprio per permettere la correzione in corso d'anno. Si poteva scrivere quel pregresso, ma non chiederlo più a nessuno. Ora la domanda è «esistono saldi **liberi**?», e il divieto compare solo quando non ce ne sono davvero — con un messaggio che dice cosa fare.

- **Un debito verso un fornitore veniva proposto fra i saldi da assorbire.** Vive nella stessa tabella dei saldi dei condòmini ma non entra mai in un piano rate: il numero mostrato all'amministratore non era quello che il piano avrebbe davvero addebitato. Emerso scrivendo i test del punto sopra.

### Note per chi sviluppa

- `SaldoInizialeController::index()` ora usa il trait `HasCondomini`, come gli altri controller della sezione. Restano non passati `esercizio`/`esercizi`, che il componente supporterebbe.
- **Tutti** gli elenchi del gestionale hanno ora uno stato vuoto: ai quattro della prima tornata (unità, tabelle, gestioni, piani dei conti) si aggiungono casse, esercizi, palazzine, scale, anagrafiche e documenti dell'immobile. `gestionale/saldi/DataTable.vue` e il suo `columns.ts` risultano **codice morto**: nessuno li importa, la pagina Saldi usa il pannello a due colonne. Non sono stati toccati — vanno rimossi, non aggiornati.
- `SaldoEsercizioService::calcolaSaldoApplicabile()` non legge più `gestioni.saldo_applicato`. Regola generale: **un valore derivato non è mai un gate**, perché risponde a «esiste almeno un…» e nessuna decisione utile ha quella forma.
- La guardia `tryDropIndex()` della migrazione del 01/03/2026 ora funziona anche su SQLite. Su MySQL/MariaDB — gli unici database su cui Kondomanager gira — **il comportamento è identico a prima**: cambia solo il database dei test, dove sopravviveva un indice `UNIQUE` rimosso in produzione da marzo. Lo schema di prova era più severo di quello reale, e rendeva impossibile coprire un caso legittimo.

### Rimandato, e perché

Tre voci previste per questa beta sono state spostate. Il criterio è quello fissato il 31/07: il percorso 1.9.1 → 1.10 è l'**unico aggiornamento senza backup automatico**, quindi ogni migrazione evitabile lì è un rischio evitato.

- **`esercizio_id` su `piani_rate` + backfill → 1.11**, insieme alla correzione che genera il problema (`GestioneController::update()` riaggancia la gestione all'esercizio aperto, e nessuno disattiva il precedente). Nei dati non c'è **nessuna** occorrenza, e la prima possibile è gennaio 2027, al primo cambio d'anno con una gestione straordinaria aperta.
- **Filtro esercizio in `GenerateSaldiAction` → 1.11**, con la voce sopra: oggi è un no-op verificato, perché ogni gestione appartiene a un solo esercizio.
- **Sdoppiamento di `metodo_distribuzione` in debiti/crediti → fuori dalla 1.10.** Cercandone l'origine non è emerso nessun richiedente: nessuna segnalazione, nessuna specifica. Si riapre quando qualcuno la chiede, con il suo caso d'uso.

---

## [1.10.0-beta.33] - Il Lucchetto Si Apre Quando Serve

La beta.32 ha dato un titolare al lucchetto sui saldi. Restava la domanda successiva, che un amministratore ha posto senza girarci intorno: *«dovevo modificare i saldi, erano bloccati»*. Non era un difetto tecnico — era il lucchetto che scattava troppo presto e agiva troppo largo.

**Troppo presto.** Il lucchetto si chiudeva alla *generazione* del piano rate. Ma un piano generato e non ancora emesso è interamente riscrivibile: «Ricalcola» lo cancella e lo rigenera senza chiedere permesso. Il sistema era disposto a distruggere tutte le quote, e contemporaneamente vietava di correggere di un centesimo il saldo che le aveva prodotte. La soglia giusta era già scritta nel messaggio d'errore — *«un saldo già incluso in un piano rate emesso»* — solo che il codice non la rispettava.

**Troppo largo.** Il lucchetto era per gestione: un solo saldo assorbito da un piano congelava l'intero pannello, comprese le righe libere, nate dopo, mai entrate in nessun piano. Non si potevano modificare, non si potevano eliminare, non se ne potevano aggiungere di nuove. È il *«troppi blocchi»* della segnalazione, ed è anche il motivo per cui una correzione trovata in corso d'anno non aveva strada.

**Nessuna migrazione: nessuna modifica al database.**

### Corretto

- **I saldi si correggono finché il piano non è emesso.** La soglia passa da «esiste un piano» a «il piano è emesso in contabilità o ha incassi registrati». Le due condizioni non sono nuove: sono esattamente quelle che il sistema già usa per decidere se un piano è ricalcolabile. Ora vivono in un posto solo, `PianoRate::eImmutabile()`, invece di essere ricopiate in sei punti del codice.
- **Il lucchetto guarda la riga, non la gestione.** Un saldo mai assorbito da un piano resta modificabile ed eliminabile anche se un altro saldo della stessa gestione è dentro un piano emesso. E i pulsanti per aggiungere credito o debito non spariscono più: una correzione in corso di gestione è un'esigenza normale, non un'eccezione da vietare.
- **I messaggi dicono quale piano.** Prima l'unica informazione era un'icona muta e una spiegazione generica; ora il blocco nomina il piano rate che tiene il saldo e dice cosa fare per liberarlo — annullare le emissioni di quel piano, oppure eliminarlo.
- **Una voce di spesa senza tabella millesimale non sparisce più in silenzio.** Era il difetto più costoso di questa versione: un capitolo con un importo ma senza tabella collegata veniva semplicemente saltato dal calcolo, con una riga di log come unica traccia. Il piano rate si generava senza quell'importo, i condòmini venivano addebitati di meno e nessuno se ne accorgeva. Ora quell'importo finisce dove è sempre appartenuto — fra le **quote non assegnabili**, che bloccano la generazione e chiedono all'amministratore una motivazione scritta per procedere.
- **Il segno non si digita, e ora è vero ovunque.** Nel pannello Saldi il segno lo decide il pulsante che premi, «Aggiungi credito» o «Aggiungi debito», e il campo importo rifiuta il meno. Tranne nella modifica rapida, dove il meno si poteva scrivere ma veniva scartato in silenzio: chi correggeva un debito di 300 digitando `-100` per trasformarlo in credito otteneva un debito di 100. Ora il campo si comporta come tutti gli altri.
- **Il credito di un condòmino non paga più il debito di un estraneo.** Quando si usa un credito intestato a un'altra persona — caso legittimo fra comproprietari della stessa unità — il sistema ora verifica che i due condividano davvero quell'unità, e scrive sulla riga contabile di chi era quel denaro. Prima il controllo non c'era.

### Aggiunto

- **Sblocco manuale per i lucchetti senza titolare.** I saldi bloccati prima della 1.10 — quando il lucchetto non registrava da chi era stato chiuso — e i rari casi che la riparazione automatica lascia chiusi di proposito (due piani della stessa gestione che contengono entrambi quote di saldo: lì il sistema preferisce non indovinare) ora si possono riaprire dall'interfaccia, con l'avvertenza di controllare prima che nessun piano stia già addebitando quell'importo. I debiti verso i fornitori non sono sbloccabili per questa via: non sono lucchetti, vivono in quell'archivio per un altro motivo.

### Sotto il cofano

- `PianoRate::eImmutabile()`, `haRateEmesse()` e `haIncassiRegistrati()` raccolgono in un punto solo la soglia che era duplicata in sei file.
- `Saldo::eBloccato()` distingue i saldi con un titolare — che seguono la sorte del loro piano — da quelli senza, che restano bloccati fino allo sblocco manuale.
- Sette nuovi test coprono la soglia nei due versi (piano generato: si modifica; piano emesso: no), la granularità, lo sblocco manuale con le sue due guardie, e il capitolo senza tabella che ora diventa una quota non assegnabile.

---

## [1.10.0-beta.32] - Il Lucchetto Senza Padrone

Un amministratore segnala dal forum: ha creato un piano rate, si è accorto che era sbagliato, e ha provato a rimediare. I saldi pregressi erano bloccati col lucchetto, così — come indicato dal messaggio a video — ha cancellato il piano. Il lucchetto è rimasto chiuso. Ha ricreato il piano: i vecchi saldi non c'erano più, il piano ripartiva dal solo preventivo.

Il lucchetto era un interruttore senza soggetto: due booleani (`gestioni.saldo_applicato`, `saldi.is_applicato`) che dicevano *«questi saldi sono stati assorbiti da un piano rate»* senza dire **da quale**. Per riaprirlo, la cancellazione del piano doveva dedurlo a posteriori, leggendo le quote generate a caccia di tracce di saldo. Ma «Ricalcola» cancella e rigenera le rate — e con esse quelle tracce. Dopo un ricalcolo nessun piano rivendicava più i saldi: il lucchetto restava chiuso e la chiave non esisteva più. Nessun percorso dell'interfaccia poteva riaprirlo.

Lo stesso difetto ha una faccia contabile, e non è meno grave: siccome la rigenerazione cercava i saldi «liberi» e li trovava già bloccati, il piano ricalcolato ripartiva dal solo preventivo. Il pregresso spariva dalle rate **in silenzio**. Chi non avesse riaperto il dettaglio delle quote avrebbe emesso rate incomplete senza saperlo.

**Questa versione modifica il database (una migrazione).** Il lucchetto ha ora un titolare: `saldi.piano_rate_id` registra quale piano ha assorbito ogni saldo, e la scelta sui saldi viene persistita sul piano (`piani_rate.applica_saldi`) invece di essere dedotta. La stessa migrazione ripara le installazioni già colpite.

### Corretto

- **I saldi pregressi restavano bloccati col lucchetto per sempre.** Dopo un «Ricalcola» — o dopo la rimozione di una voce di spesa dal piano, che passa dallo stesso codice — cancellare il piano non riapriva più il lucchetto. I saldi restavano non modificabili e non eliminabili, e nessun piano successivo li includeva. La regola che ne esce, e che vale oltre questa pagina: **uno stato che protegge qualcosa deve registrare per conto di chi lo fa**, altrimenti nessuno sa quando può essere rimosso.
- **Il pregresso spariva dalle rate senza alcun avviso.** `GenerateSaldiAction` selezionava i saldi con `is_applicato = false`: dopo la prima generazione erano tutti a `true`, quindi il ricalcolo dello stesso piano ne trovava zero. Ora un saldo è disponibile per un piano se è libero **oppure se è già intestato a quel piano**, e un ricalcolo restituisce quote identiche a quelle di prima.
- **La cancellazione di un piano sbloccava anche i debiti pregressi verso i fornitori.** Vivono nella tabella `saldi` con `is_applicato = true` proprio per restare fuori dai piani rate, e lo sblocco a tappeto per gestione li travolgeva. Ora si riaprono solo i saldi intestati al piano eliminato.
- **Un piano creato senza «Genera calcolo scadenze subito» bloccava comunque i saldi**, pur non contenendone nemmeno uno: il `store()` chiudeva il lucchetto a prescindere dalla generazione. Era il modo più rapido di ottenere un lucchetto orfano — bastava creare il piano in bozza e cancellarlo. Ora il lucchetto lo chiude soltanto chi genera le quote, e solo sulle righe finite davvero nelle rate.
- **I saldi a zero non vengono più bloccati.** Non entrano mai nella generazione (che scarta gli importi nulli): erano bloccati solo perché il vecchio lock agiva su tutta la gestione. Spariscono anche dalla finestra di ripartizione solidale in fase di creazione, dove proponevano di ripartire un importo che non sarebbe mai stato applicato.
- **Un riparto manuale di 250,00 € ne addebitava 25.000,00.** Difetto indipendente, presente da quando esiste la ripartizione manuale dei saldi solidali (Art. 63) e trovato scrivendo i test di questa versione: l'importo digitato veniva convertito in centesimi due volte, dal controller e di nuovo dalla generazione. Cento volte l'importo dovuto, su una quota che l'amministratore ha deciso a mano proprio perché il riparto automatico non andava bene. Il riparto automatico pro-quota non è mai stato toccato dal difetto.
- **Il riparto manuale spariva al primo ricalcolo.** La ripartizione decisa dall'amministratore arrivava dal form solo alla creazione e non veniva conservata da nessuna parte: al primo «Ricalcola» tornava il pro-quota automatico, con importi diversi e nessun avviso. Ora è persistita sul piano e sopravvive a ricalcoli e rimozioni di voci.
- **Un piano straordinario generato in differita si portava dentro i pregressi della gestione.** Il codice dichiarava a log «saldi ignorati di default» ma non lo faceva: valeva solo per la generazione immediata. Ora un piano straordinario assorbe i pregressi soltanto se qualcuno lo chiede esplicitamente.

### Riparazione dei dati esistenti

- La migrazione ricuce le installazioni già colpite: per ogni gestione bloccata cerca il piano che contiene davvero le quote di saldo e glielo intesta; se nessun piano le contiene, il lucchetto è orfano e **viene aperto**, restituendo all'amministratore saldi che erano diventati inaccessibili. I debiti verso fornitori non vengono mai toccati. Verificata su MySQL con dati reali, oltre che nei test.
- **Quando la situazione è ambigua la riparazione non indovina.** Se due piani della stessa gestione contengono entrambi quote di saldo — stato possibile solo su dati storici — non è dato sapere quale riga appartenga a quale piano: intestarle a caso rischierebbe di sbloccare saldi che un piano continua ad addebitare, cioè un doppio addebito al piano successivo. In quel caso non tocca nulla e lo scrive nel log. Meglio un lucchetto da aprire a mano che un addebito doppio.
- **Ogni decisione della riparazione è tracciata nel log** (gestione, esito, piano scelto, elenco dei saldi toccati, nota precedente): una migrazione che modifica dati contabili deve poter essere ispezionata e ricostruita a posteriori.

### Sotto il cofano

- Il flag `gestioni.saldo_applicato` non è più una decisione ma un derivato: è acceso finché esiste almeno un saldo di condòmino bloccato su quella gestione. Non può più divergere dalle singole righe.
- Rimosso `SaldoEsercizioService::marcaSaldoApplicato()`, che chiudeva il lucchetto a tappeto su tutta la gestione: era l'origine del difetto ed è ora sostituito da `sincronizzaLucchetti()` / `rilasciaLucchetti()`, che agiscono per piano.
- Tredici nuovi test (`SaldiLockOrfanoTest`) coprono il percorso segnalato dall'amministratore, la migrazione di riparazione e le guardie emerse dalla revisione avversariale del fix; la migrazione è aggiunta anche alla batteria di rieseguibilità post-interruzione introdotta nella beta.31.
- La colonna `saldi.piano_rate_id` e il suo vincolo hanno guardie separate nella migrazione: su MySQL sono due statement distinti, e un'interruzione fra i due avrebbe lasciato la colonna senza `ON DELETE SET NULL` per sempre — cioè un saldo intestabile a un piano inesistente, di nuovo bloccato senza chiave.
- I debiti verso fornitori sono ora esclusi dalla selezione dei saldi con un filtro esplicito. Ne restavano fuori perché nascono già bloccati: un'invariante non scritta nello schema non è una difesa.

---

## [1.10.0-beta.31] - La Porta Chiusa dal di Dentro

Un amministratore segnala che l'aggiornamento dalla 1.9.1 non parte: la schermata di conferma mostra le due versioni, l'avviso, e un pulsante «Avvia aggiornamento» grigio che non risponde al click. Non è un problema della sua installazione. Partendo da una 1.9.1 pulita succede sempre.

Dalla beta.13 quella schermata propone un backup di sicurezza prima delle migrazioni, con un attrito voluto per chi sceglie di saltarlo: una conferma esplicita da spuntare. Ma il backup esiste solo dalla beta.11 in poi, e la conferma esplicita era renderizzata **dentro** il riquadro del backup. Quando il backup non c'era non compariva nemmeno la casella che avrebbe permesso di proseguire senza: la via di fuga viveva dentro la stanza chiusa a chiave.

Nessun dato è mai stato a rischio — il blocco avviene prima che il database venga toccato — ma l'amministratore restava murato fuori dal proprio gestionale, perché il sistema lo riporta su quella schermata a ogni accesso finché l'aggiornamento non è completato. E poiché le beta non escono ufficialmente, `1.9.1 → 1.10.0` non è il percorso di qualche installazione vecchia: è il percorso di **tutte**.

**Nessuna migrazione nuova e nessuna modifica di schema.** Tre migrazioni esistenti vengono rese rieseguibili, ma il risultato che producono è identico a prima — chi le ha già eseguite non se ne accorge. Cambia invece il comportamento dell'aggiornamento, ed è la prima voce qui sotto.

### ⚠ Cambia il comportamento dell'aggiornamento

- **Il backup automatico di sicurezza non viene più proposto aggiornando da una versione anteriore alla 1.10.** Quel backup gira *prima* delle migrazioni, quindi può contare solo sull'infrastruttura già presente nel database che sta per essere aggiornato — e chi arriva da una 1.9.x non ce l'ha. Invece di crearla al volo nel momento più delicato della vita dell'installazione, il primo aggiornamento alla 1.10 resta senza rete automatica, esattamente come ogni aggiornamento fatto fino ad oggi, e la pagina chiede una copia del database dal pannello dell'hosting. Il controllo **si riapre da solo**: dalla 1.11 in poi si parte sempre da una 1.10.0 o successiva, la tabella c'è, e il backup automatico torna disponibile senza che nessuno debba ricordarsi di togliere un interruttore.

### Corretto

- **L'aggiornamento dalla 1.9.1 era impossibile da avviare**: il pulsante «Avvia aggiornamento» restava disabilitato per sempre, perché la condizione che lo sblocca dipendeva da una casella di conferma renderizzata solo nel ramo in cui il backup *era* disponibile. La regola che ne esce, e che vale oltre questa pagina: l'azione primaria di una schermata su cui il sistema ti costringe non può mai dipendere da un valore il cui unico modo di cambiare vive dentro una condizione diversa.
- **Tre delle dodici migrazioni non erano rieseguibili**, e sono le tre di `contributi_versati` — le ultime aggiunte, mai passate per il trattamento `cleanupPartialMigration` che hanno le altre nove. Rilanciate dopo un'interruzione fallivano con *Table already exists*, *Duplicate key name* e *Duplicate column name*; `runMigrationsWithRetry()` riprova tre volte e si arrende. Un amministratore che seguiva l'indicazione appena aggiunta — «ricarica e premi di nuovo» — avrebbe ripremuto all'infinito sullo stesso errore, con il database a metà e senza backup automatico. La frase in pagina e queste guardie sono la stessa correzione: senza le seconde, la prima è una bugia.
- **La pagina di errore del bridge di aggiornamento presentava il setup standalone come strumento per le sole installazioni nuove**, lasciando senza risposta proprio chi ci arriva: qualcuno con un'installazione esistente il cui aggiornamento non è ripartito. Quel file invece riconosce da sé l'installazione esistente dalla presenza di `.env` e lavora in modalità aggiornamento — sostituisce i soli file di sistema, preserva database, storage e configurazione, svuota la cache compilata e rimanda a `/system/upgrade/finalize`. È lo strumento giusto per sbloccarsi, e ora la pagina lo dice.
- **Il motore di aggiornamento automatico mostrava il numero di versione al posto dell'errore.** `t()` iniettava `APP_VERSION` in ogni stringa con segnaposto ogni volta che il chiamante non passava argomenti propri: `sprintf(t('err_generic'), $e->getMessage())` produceva «Errore: 1.10.0-beta.x» e buttava via il guasto reale, leggibile solo in `install.log`. Un aggiornamento fallito non diceva a nessuno perché.
- **Con PHP più vecchio del richiesto, la stessa pagina restava bianca.** Il messaggio `err_php` ha due segnaposto; con un solo argomento iniettato `vsprintf` solleva `ValueError` — che discende da `\Error`, non da `\Exception`, e quindi **non veniva nemmeno intercettato** dal `catch` del bridge. Con `display_errors=0` il risultato era una schermata vuota, senza rollback, proprio nel caso in cui quel messaggio («Richiesto PHP 8.4…») è l'unica cosa che serve leggere. Colpiva entrambi i punti in cui il blocco PHP scatta: la schermata di avvio e la guardia lato server che intercetta un POST diretto. `t()` ora pareggia gli argomenti e non può più sollevare: in un aggiornatore, la schermata d'errore non può essere essa stessa una fonte di errori fatali.
- **Il bridge intercettava solo `Exception`, non `Throwable`.** Qualunque `Error`/`TypeError`/`ValueError` sollevato durante l'aggiornamento usciva dal `try` senza essere gestito: script morto in silenzio a metà, nessun rollback, e per l'amministratore la sensazione che «i file si scarichino ma non reindirizzi mai». Il setup standalone era già stato irrobustito su questo punto; il bridge no.
- **La disponibilità del backup era decisa in due punti diversi** — la pagina guardava l'esistenza della tabella, l'endpoint la ricontrollava per conto suo. Ora entrambe passano dalla stessa condizione e non possono più discordare.

### Modificato

- **La schermata di conferma dichiara che l'aggiornamento è riprendibile.** Su hosting condiviso le migrazioni possono superare il tempo massimo di esecuzione imposto dal server, che PHP non può alzare: la pagina va in errore o resta bianca. Non è un danno — ogni migrazione viene registrata singolarmente e il popolamento delle aperture di cassa è idempotente — ma finora nessuno lo diceva, e un errore in quel momento fa pensare di aver rotto tutto. Ora la pagina istruisce a ricaricare e premere di nuovo.
- **Il motore di aggiornamento automatico ha ora lo stesso aspetto del setup guidato**: fondo scuro, card bianca, logo Km, barra di avanzamento con percentuale e finestra di log, al posto del gradiente viola che si portava dietro da versioni. Erano tre schermate — bridge mancante, avvio, avanzamento — ciascuna con un proprio foglio di stile, ora unificate. Per l'amministratore che aggiorna sono lo stesso strumento; finché avevano due identità visive sembravano due prodotti diversi. Nessun cambiamento di logica: solo presentazione.
- **Riscritto l'avviso mostrato quando il backup automatico non è disponibile.** Diceva «Backup consigliato»; è il messaggio che leggerà ogni amministratore che aggiorna alla 1.10.0, nel momento in cui è l'unica protezione che ha. Ora chiede la copia del database in modo esplicito e spiega che dagli aggiornamenti successivi sarà Kondomanager a farla.

### Test

- Suite completa: **702 test verdi** (prima di questa beta: 685).
- `PreUpgradeBackupTest.php` passa da 6 a **11 test**. I nuovi coprono il percorso di aggiornamento da una 1.9.x: che il backup non venga offerto, che l'endpoint lo rifiuti, che una versione illeggibile nel database disattivi il backup senza bloccare nulla, e che torni disponibile da solo partendo da una 1.10, 1.10.3 o 1.11.
- Aggiunta la copertura dell'**invariante che conta più di tutte**: la finalizzazione deve restare raggiungibile anche quando il backup non è disponibile. È la prima volta che `POST /system/upgrade/run` viene testato via HTTP.
- Nuovo `UpgradeMigrationsRerunTest.php`, **guidato da dataset sulle dodici migrazioni** del salto 1.9.1 → 1.10: ognuna viene rieseguita a database già migrato e deve completare senza errore, senza aggiungere né perdere tabelle. È un presidio, non un test una tantum — **ogni migrazione nuova che tocca quel percorso va aggiunta all'elenco**.
- Entrambi i gruppi sono stati verificati per contrasto, ripristinando il codice pre-fix: 3 test rossi su 11 nel primo, 3 su 12 nel secondo, con esattamente gli errori attesi. Un test che passa anche senza la correzione non sta presidiando niente — e la prima stesura di questo, che usava `expect(closure)->not->toThrow()`, era proprio in quel caso.
- **Limite dichiarato**: il difetto del pulsante vive in una proprietà calcolata di Vue, e il progetto non ha un runner di test JavaScript. I test qui sopra bloccano il contratto lato server — che l'uscita esista sempre, e che le migrazioni siano riprendibili — ma non avrebbero intercettato *quel* bug. A coprirlo resta la prova manuale del percorso completo su un dump 1.9.1 reale, da rifare prima della 1.10.0 finale. L'adozione di Vitest è rimandata a dopo il rilascio: non si fa debuttare infrastruttura di test nuova sulla release più critica.

### Nota per chi è su una beta precedente

Un'installazione già bloccata sulla schermata di conferma non può essere sbloccata dall'interfaccia. La via d'uscita verificata è passare prima dalla **1.10.0-beta.11** — che precede l'introduzione del difetto e crea la tabella dei backup — completare l'aggiornamento del database, e da lì tornare alla versione corrente. Lo strumento è il file standalone di setup puntato a quel pacchetto: su un'installazione esistente riconosce il `.env`, lavora in modalità aggiornamento e non tocca il database.

Da evitare: modificare a mano la versione nella tabella `settings` per far sparire l'avviso. Marca il database come aggiornato senza averlo aggiornato, e produce un guasto molto più difficile da recuperare di quello di partenza.

---

## [1.10.0-beta.30] - Il Consuntivo Esce allo Scoperto & Tre Numeri Smettono di Mentire

Un amministratore scrive: «Ho fatto 2 registrazioni ma non si trovano da nessuna parte». Un altro, guardando la barra colorata del Piano dei Conti, dice: «pensavo che i 2 colori si riferissero a preventivato e già consumato». Sembrano due lamentele scollegate. Sono lo stesso bug.

Lo "speso" di una voce veniva ricostruito da una query su `righe_fattura`, duplicata identica in **sei** punti del codice. Quella fonte è incompleta per costruzione: solo le fatture passive scrivono lì. Una Regolazione Immediata scrive direttamente a giornale, quindi i soldi uscivano davvero dalla cassa — il saldo scendeva, l'amministratore lo vedeva — ma il capitolo restava apparentemente libero, il cruscotto sfori taceva, e la registrazione era introvabile partendo da Fatture Passive. Questa beta sposta il calcolo sul libro giornale, dove i movimenti stanno tutti, e lo espone finalmente a video accanto al preventivo.

**Nessuna migrazione del database.** Ma tre numeri già visibili cambiano valore: sono elencati per primi qui sotto, perché un amministratore che confronta prima e dopo li noterà.

### ⚠ Numeri che cambiano

- **Lo speso di una voce ora comprende le regolazioni immediate.** Capitoli che sembravano liberi possono risultare parzialmente impegnati o in sforo. Non è un errore nuovo: è una spesa che c'era già e non veniva contata. Il caso segnalato dall'amministratore — 6,72 € di regolazione su una voce — era invisibile al budget.
- **Il totale "Preventivo" non si gonfia più con gli sfori.** Sommava `$conto->importo`, che il controller porta allo speso quando il costo reale supera il budget: cresceva quindi insieme alle spese e smetteva di essere un preventivo. Accanto al nuovo totale Consuntivo l'incoerenza sarebbe stata evidente. Ora usa il budget deliberato.
- **Le spese ad personam non si sommano più allo speso comune** nel Piano dei Conti. Delle sei copie della query, quella era l'unica a non escluderle: la stessa voce mostrava un numero diverso a seconda della pagina da cui la si guardava.

### Aggiunto

- **Colonna "Consuntivo" accanto al Preventivo** nell'elenco conti e sottoconti, con intestazioni di colonna esplicite. Le voci in sforo sono in rosso con icona di allarme (il colore da solo non basta per chi ha una deficienza cromatica) e il dettaglio dell'eccedenza compare al passaggio del mouse. Le voci senza spesa mostrano `—` invece di `€ 0,00`: distingue "non ancora speso" da uno zero calcolato.
- **Totale "Consuntivo" in testata**, accanto a Preventivo e Sopravvenienze — la spesa complessiva del piano, che prima non era leggibile da nessuna parte in quella pagina.
- **Drill-down sul consuntivo**: cliccando l'importo si apre l'elenco dei movimenti che lo compongono, con data, causale, tipo e importo, e da lì si raggiunge la scrittura nel Libro Giornale. Il totale mostrato è quello vero della voce, ricalcolato a parte: con più di 200 movimenti l'elenco viene tagliato, e il taglio è dichiarato in pagina invece di far sembrare completo un elenco che non lo è. Gli storni compaiono con importo negativo, perché fanno parte della storia che spiega il totale.
- **Ordinamento della distinta per codice** *(richiesta di un'amministratrice)*, con selettore Nome/Codice sopra l'elenco. Il default resta "nome": `conti.codice` è nullable e senza formato imposto, quindi renderlo criterio unico avrebbe rotto l'elenco a chi non lo compila. L'ordinamento è **naturale** — `A.2` prima di `A.10`, `999` prima di `1020` — e le voci senza codice finiscono in fondo raggruppate invece di sparpagliarsi. Il selettore compare solo se almeno una voce ha un codice. La stampa "Distinta base" eredita la scelta: stampare per nome ciò che a schermo è ordinato per codice avrebbe reso la funzione mezza inutile.
- **Avviso ponte in Fatture Passive**: quante regolazioni immediate esistono nell'esercizio, con link al Libro Giornale già filtrato per tipo movimento. Si chiude con una × e **riappare dopo sei mesi** — chi le usa quotidianamente non ha bisogno del promemoria, ma passata una stagione contabile è più probabile che torni utile che fastidioso.
- **Azione "Registra pagamento"** nel menu di ogni fattura, che apre il modulo con fornitore, IBAN e fattura già selezionati con l'importo. Compare **solo sulle fatture pagabili**: il motore accetta il pagamento unicamente in stato `approvata`, quindi una fattura in attesa di ratifica, da approvare o contestata portava a un vicolo cieco. Il menu offre già l'azione corretta per lo sforo ("Ratifica assembleare"), quindi nascondere quella sbagliata non lascia l'utente senza strada.
- **Scorciatoia dallo sforo alla creazione del piano rate**, con gestione e tipo già impostati. Compare solo sulla strategia "rata integrativa": con la copertura da fondo o a consuntivo una rata non è la risposta, e il link inviterebbe a fare la cosa sbagliata.

### Modificato

- **"Copertura" si chiama ora "Coperto da piano rate"**, in tutte e quattro le occorrenze della pagina, con una frase sempre visibile sotto la barra che chiarisce cosa misura: la quota di preventivo già inserita in un piano rate emesso ai condòmini, non la spesa sostenuta. È il malinteso da cui è partita tutta la beta. Testo, non tooltip: la beta.28 aveva già insegnato che un suggerimento al passaggio del mouse non arriva a chi naviga da tastiera.
- **"Richiede rate" → "Da coprire con rata integrativa"**, e ogni strategia di rientro ha ora il suo ramo esplicito. Prima l'ultima era un `v-else` generico: qualunque strategia nuova sarebbe finita etichettata come rata integrativa.
- **Una voce senza preventivo non è più marcata come "in sforo"**: le sopravvenienze nascono fuori preventivo per definizione, e segnarle tutte in rosso toglieva forza al rosso dove indica uno sforo vero. Il loro preventivo mostra `—`, non `€ 0,00`.

### Corretto

- **Il pulsante "Indietro" non ha mai funzionato** nel dettaglio scrittura e nell'estratto conto condòmino: leggeva `window.history.state.back`, una convenzione di Vue Router che Inertia non popola. Il ramo era sempre falso e si tornava sistematicamente al registro di fallback, da qualunque pagina si arrivasse. Ora la provenienza reale è tracciata sull'evento di navigazione.
- **L'azione "Registra pagamento" esisteva già nel codice, commentata**, e puntava a una rotta inesistente: riattivarla così com'era avrebbe prodotto un errore. Il resto della catena — inclusa la preselezione della fattura — era completo e funzionante da tempo.

### Test

- Suite completa: **685 test verdi** (prima di questa beta: 644).
- Nuovi file `SpesaPerVoceServiceTest.php`, `PianoContiSpesoTest.php`, `OrdinamentoContiTest.php`; test aggiuntivi su `FatturaPassivaControllerTest.php` e `StampePDFTest.php`.
- Coperti i casi contabili che toccano il segno: nota di credito che scomputa, storno che azzera, fattura contestata che non pesa, spese ad personam fuori per costruzione, e l'invariante fra lettura analitica (`voce_spesa_id`) e sintetica (conto contabile).
- **Tre difetti della suite corretti**: due test fallivano a intermittenza per collisione di dati faker su colonne con vincolo di unicità (`PianoRateFactory.nome`, `AnagraficaFactory.email_secondaria`/`pec`); un `codice_immobile` hardcodato negli helper impediva di creare due condomini nello stesso test, rendendo impossibile scrivere le verifiche di isolamento multi-condominio — proprio quelle che in un gestionale multi-fabbricato servono di più.
- **La pagina Piano dei Conti era impossibile da testare via HTTP**: una query usava `GROUP_CONCAT ... SEPARATOR`, sintassi MySQL che SQLite rifiuta, e ogni richiesta finiva in errore 500 nella suite. Resa portabile per driver, la pagina è ora coperta.

---

## [1.10.0-beta.29] - Nasce il Libro Giornale, e lo Stato Patrimoniale Impara a Spiegarsi

Fino a ieri il Libro Giornale esisteva solo come dettaglio di una singola scrittura, raggiungibile solo per drill-down da altre pagine (fatture, pagamenti, giroconti...). Mancava un vero registro sfogliabile. Questa beta lo costruisce: elenco cronologico per esercizio, con filtri estesi (ricerca, tipo movimento, stato, intervallo date), switch reale fra esercizi per consultare gli anni precedenti, e un widget che espone per la prima volta a video `StatoPatrimonialeService` — il motore di verifica quadratura scritto in beta.25 ma mai collegato a nessuna pagina.

Durante la verifica dal vivo (non nella revisione automatica) è emerso un problema più sottile del previsto: un amministratore che non mastica contabilità vedeva un badge verde "quadra" accanto a due numeri (Attivo e Passivo) visibilmente diversi fra loro, e giustamente non si fidava — perché "quadra" verifica un'equazione a tre termini (Attivo = Passivo + Risultato d'esercizio), non il pareggio fra le due masse. Il widget ora traduce l'equazione in una frase in linguaggio corrente, e quando lo sbilancio è reale prova a indicarne la causa più probabile — con link diretto a dove intervenire — invece di lasciare l'amministratore a caccia di un errore invisibile.

### Aggiunto

- **Pagina Libro Giornale**: elenco paginato delle scritture contabili di un esercizio, annidato sotto `/esercizi/{esercizio}/scritture` così il selettore esercizio esistente funziona da subito, senza logica nuova. Filtri: ricerca testuale (causale/descrizione/protocollo), tipo movimento, stato, intervallo date di registrazione.
- **Widget verifica quadratura**: badge quadra/non quadra con una spiegazione in linguaggio corrente che cambia a seconda della situazione — un disavanzo a esercizio in corso è descritto come normale, uno sbilancio reale no. Sotto, l'equazione compatta (Attivo = Passivo + Risultato d'esercizio) per chi vuole il riscontro numerico immediato, e un link "Dettagli calcolo" che apre una tabella completa (Attivo, Passivo, Costi, Ricavi, Risultato d'esercizio, Liquidità non contabilizzata, Sbilancio) — vista provvisoria in attesa della pagina Stato Patrimoniale dedicata.
- **Diagnosi automatica dello sbilancio**: quando lo Stato Patrimoniale non quadra, il widget cerca le due cause riconoscibili con certezza — scritture non bilanciate al loro interno (Dare ≠ Avere sulla singola scrittura) e casse con un saldo di apertura non ancora portato a giornale — e le elenca con link diretto alla scrittura o alla cassa da correggere. Oltre questi due casi la diagnosi si ferma onestamente: un importo digitato male ma comunque bilanciato non lascia traccia distinguibile, e il messaggio lo dice esplicitamente invece di indovinare.
- **Widget riepilogo Dare/Avere** del periodo filtrato, con un bollino di conferma pareggio — un secondo segnale diagnostico gratuito, visto che in un registro corretto Dare e Avere aggregati tornano sempre.
- **Voce di menu "Libro Giornale"** nella barra Movimenti, accanto a "Prima nota" (non toccata: resta il placeholder di una feature diversa, già progettata a parte).
- **Filtri stato e intervallo date** estesi dal Libro Giornale a Incassi rate, Fatture passive, Pagamenti fornitori e Giroconti — stessa UI, stesso comportamento: selezione stato, due campi data, pulsante "Azzera filtri" quando c'è qualcosa da azzerare.
- *(Feedback dal forum)*: **anteprima del numero di protocollo in Regolazione immediata**, mostrata nella card "Anteprima scrittura" prima ancora di salvare — utile a chi registra una pila di scontrini e vuole annotare subito il numero sul documento cartaceo. Il numero è generato dal sistema (mai digitabile) e resta indicativo: quello definitivo è sempre assegnato al salvataggio, e può scostarsi di una unità se nel frattempo viene registrato un altro movimento con lo stesso prefisso.

### Corretto (prima del rilascio, revisione avversariale)

- **Link "Libro Giornale" rotto (404)** quando il condominio non ha nessun esercizio in stato aperto (es. l'amministratore lo ha appena chiuso): ora si disabilita come già fa "Prima nota", invece di generare un indirizzo con id 0.
- **Il selettore "righe per pagina" della tabella non veniva rispettato dal server**: il controller ignorava il parametro e restituiva sempre 20 righe, contraddicendo la selezione fatta in tabella.
- **Cambiare esercizio da una pagina successiva alla prima poteva mostrare una lista vuota senza alcun modo di uscirne**: il numero di pagina residuo dal cambio esercizio non veniva mai riportato entro il range disponibile per il nuovo esercizio. Ora la pagina richiesta viene sempre ricondotta all'ultima disponibile.
- **Le colonne Data e Importo sembravano ordinabili ma non lo erano davvero**: il riordino avveniva solo sulla pagina corrente già in memoria, non sull'intero registro filtrato — tolto l'ordinamento su queste colonne, mai stato previsto lato server.
- *(Trovato in verifica dal vivo con l'utente, non dalla revisione automatica)*: la frase in linguaggio corrente del widget quadratura non distingueva "non quadra" da "in disavanzo ma quadrato" — un condominio con uno sbilancio reale vedeva lo stesso messaggio rassicurante di uno semplicemente a metà esercizio. Ora la frase ramifica esplicitamente sullo stato di quadratura prima di guardare il segno del risultato d'esercizio.
- *(Trovato lavorando sui filtri di Incassi rate)*: **lo stat "Stornati" e il relativo filtro rapido in Incassi rate non hanno mai contato nulla**, da sempre a zero indipendentemente da quanti incassi fossero stati davvero stornati: confrontavano lo stato con `'stornato'`, un valore che non è mai esistito nella colonna (quello vero, scritto da `StornoIncassoRateAction`, è `'annullata'`) — probabile refuso di chi ha scritto quel controllo riusando il vocabolario di un'altra tabella (`pagamenti_fornitori.stato` usa davvero `'stornato'`, ma è un dominio diverso). Corretto il confronto.
- **Testo fuorviante nella pagina Tabelle millesimali** *(non legato al resto di questa beta, corretto nello stesso periodo di lavoro)*: la guida in pagina descriveva un "validatore di coerenza" che avrebbe controllato in tempo reale che la somma dei millesimi di ogni tabella fosse esattamente 1000 — funzionalità mai esistita nel motore. Il riparto in realtà distribuisce sempre il 100% della spesa sul totale reale dei millesimi della tabella, anche quando non è esattamente 1000 (unità rimosse, arrotondamenti approvati in assemblea): nessun euro perso o duplicato. Testo corretto per descrivere il comportamento reale, in card e sottotitolo della pagina.
- *(Trovato durante un controllo mirato richiesto dall'utente, non raggiungibile dall'interfaccia in uso normale)*: **lo storno di un incasso non verificava che la scrittura passata fosse davvero un incasso rata**. La rotta prendeva l'id direttamente dall'indirizzo e stornava qualunque scrittura dello stesso condominio le venisse passata — anche, per esempio, un giroconto — marcandola "annullata" e generando una rettifica priva di senso per quel tipo di movimento. Aggiunto un controllo esplicito sul tipo movimento prima di procedere.
- **Il riquadro con i dettagli dello storno (data, operatore) nel dettaglio di un incasso non compariva mai**, nemmeno per un incasso davvero stornato: confrontava lo stato con `'stornato'`, un valore mai esistito nel database (quello vero è `'annullata'`, stesso refuso già corretto altrove in questa beta). Corretti anche il colore del badge di stato e la barratura del totale versato, che per lo stesso motivo non riflettevano mai lo stato reale. L'operatore che ha effettuato lo storno resta "Sconosciuto": quel dato non è mai stato tracciato, correzione rimandata.
- **Etichetta di stato "Annullata" rinominata in "Stornata"/"Stornato"** nei filtri e nei badge della pagina Incassi rate, coerente con l'azione "Storna" che l'amministratore ha effettivamente compiuto (il valore salvato nel database resta `annullata`, invariato).
- *(Trovato scrivendo la documentazione del Libro Giornale)*: il sottotitolo della modale "Dettagli calcolo" mostrava "Esercizio Esercizio anno 2026" — il nome dell'esercizio include già la parola "Esercizio", il template ne anteponeva un secondo.
- *(Trovato dall'utente in verifica dal vivo, dopo il rilascio interno di questa beta)*: la stessa etichetta era rimasta "Annullata" nel Libro Giornale — pagina nuova di questa beta, non toccata dal rename fatto sopra per Incassi rate. Applicata la stessa etichetta "Stornata" nel badge di stato e nel filtro. Tolta anche la barratura sul testo del badge: aveva senso su un importo ("questo valore non è più valido"), non su un'etichetta di stato, dove taglia semplicemente la parola.
- L'intestazione della colonna "Importo" nel Libro Giornale era allineata a sinistra mentre gli importi sotto sono allineati a destra, risultando visivamente disallineata: allineata a destra come nelle altre tabelle movimenti (Casse).

### Test

- Nuovo file `ScritturaContabileControllerTest.php`: scoping condominio/esercizio, ogni filtro isolato, riflesso fedele di `StatoPatrimonialeService::calcola`, whitelist e clamp di `per_page`/`page`, diagnosi automatica per entrambe le cause riconoscibili (12 test in totale).
- Nuovo file `IncassoRateControllerTest.php`, più test aggiuntivi su `FatturaPassivaControllerTest.php`, `PagamentoFornitoreControllerTest.php` e `GirocontoTest.php`: filtro stato, filtro intervallo date, i due combinati insieme, per ciascuna delle 4 pagine.
- Nuovo file `StornoIncassoControllerTest.php`: rifiuto di una scrittura non-incasso_rata (es. giroconto) con stato/rettifiche invariati, storno regolare di un vero incasso_rata ancora funzionante.
- `RegolazioneImmediataTest.php`: anteprima del protocollo esposta dal form, non avanza se richiesta ripetutamente senza salvare, coincide col numero davvero assegnato al salvataggio.
- Suite completa: 644 test verdi (prima di questa beta: 616).

---

## [1.10.0-beta.28] - Il Piano Rate Impara Da Dove Arriva

La Dashboard, quando propone di creare un piano rate per uno sforo o una sopravvenienza, sa già perfettamente quale dei due tipi serve — lo scrive nell'indirizzo che genera (`?tipo=ordinario` o `?tipo=straordinario`). Ma la pagina di creazione, fino a ieri, si limitava a preselezionare quella scelta lasciando comunque cliccabile l'altra: un amministratore che toccava la card sbagliata arrivava fino in fondo al form e si scontrava con un errore di validazione solo al salvataggio. Questa beta chiude il cerchio: se il contesto è certo, l'altra opzione si disattiva subito, con la spiegazione sempre visibile — non solo al passaggio del mouse.

Insieme al lock, la card "Piano rate ordinario" ora si rietichetta da sola in "Piano Rata Integrativa" quando non è la prima emissione dell'anno per quella gestione — che sia perché la Dashboard sta chiedendo di coprire uno sforo, o perché la gestione selezionata ha già un piano rate "preventivo iniziale" a bilancio.

### Aggiunto

- **Le due card "Piano rate ordinario" / "Piano rate straordinario" si bloccano a vicenda** quando si arriva da un link della Dashboard che ha già determinato il tipo corretto (sforo su capitolo esistente vs sopravvenienza/ad personam): l'opzione non pertinente diventa non selezionabile, con una spiegazione sempre visibile sotto la card. Se l'utente cambia gestione manualmente dal select, il blocco si scioglie: il contesto della Dashboard vale solo per la gestione a cui si riferiva.
- **La card "Piano rate ordinario" diventa "Piano Rata Integrativa"** quando è chiaro che non si tratta della prima emissione dell'anno: arrivo dalla Dashboard per uno sforo, oppure — per chi crea il piano manualmente — la gestione selezionata ha già un piano rate "preventivo iniziale" ordinario a bilancio.

### Corretto (prima del rilascio, revisione avversariale)

- **Il segnale "la gestione ha già un piano rate" contava anche un piano straordinario preesistente**, facendo apparire "Piano Rata Integrativa" sul primissimo piano ordinario dell'anno per quella gestione. La query è ora vincolata a `tipo=ordinario` e a `contesto_creazione=preventivo_iniziale` (un piano "madre", non un'integrazione).
- **Il blocco delle card e l'etichetta "Integrativa" restavano ancorati alla gestione con cui la pagina si era aperta**, anche dopo che l'utente ne selezionava manualmente un'altra dal menu "Gestione di riferimento" — bloccando un tipo che per la nuova gestione poteva invece essere corretto, e mostrando un'etichetta falsa. Ora il contesto della Dashboard si applica solo finché la gestione selezionata resta quella originaria.
- **Il tooltip che spiegava il blocco era un `title` HTML, visibile solo al passaggio del mouse**: chi naviga da tastiera o con uno screen reader non lo riceveva mai, e vedeva semplicemente sparire un'opzione senza spiegazione. Sostituito con un testo sempre visibile.
- *(Limite noto, non un bug — documentato nel codice)*: il segnale "prima emissione dell'anno" non distingue esercizi diversi per una gestione riutilizzata su più anni (caso raro ma raggiungibile). L'impatto è puramente cosmetico sull'etichetta mostrata, non tocca calcoli, validazioni o dati salvati.

### Test

- 2 nuovi test di regressione per lo scoping tipo+contesto_creazione del segnale "piano già esistente".
- Suite completa: 616 test verdi (prima di questa beta: 614).

---

## [1.10.0-beta.27] - Il Già Versato Impara a Chiedere: "Questi Soldi, Dove Sono Oggi?"

Seguito diretto della beta.26. Il "già versato" per voce di spesa aggiusta correttamente il riparto — ma un test con numeri reali ha fatto emergere un buco più sottile: dichiarare un già versato non sposta un solo euro reale. È solo un dato per il riparto, non tocca nessuna cassa, non compare nello Stato Patrimoniale come liquidità — a meno che quei soldi non siano già stati spesi come acconto al fornitore prima di Kondomanager, nel qual caso è il debito verso il fornitore a non essere ancora scontato. Questa beta chiude entrambi i casi, e in più mette in comunicazione il già versato con il resto del gestionale: la registrazione di una fattura in sforo, la generazione del piano rate, e — quando serve — il debito pregresso verso il fornitore.

**⚠ MIGRAZIONE DATABASE**: una migrazione, eseguita automaticamente dall'aggiornamento guidato. Aggiunge due colonne nullable a `contributi_versati` (`liquidita_stato`, `cassa_id`): nessun dato esistente viene toccato, nessuna riga già presente cambia comportamento finché l'amministratore non dichiara esplicitamente dove si trovano quei soldi.

### Aggiunto

- **La domanda "dove sono questi soldi, oggi?"**: alla prima dichiarazione di un già versato per una voce, una modale chiede all'amministratore di scegliere fra due scenari reali — **sono ancora fermi, mai spesi** (li registriamo subito come liquidità reale su una cassa o un fondo, esattamente come un saldo di apertura) oppure **sono già stati spesi come acconto al fornitore** prima di Kondomanager (nessuna liquidità da registrare, ma apriamo un promemoria in Inbox che guida a registrare il debito residuo come fattura pregressa). La domanda si pone una volta sola per voce.
- **Un fondo deliberato non può finire su una cassa libera per imprevisti**: se il già versato ha un vincolo di destinazione (art. 1135 c.c.), il selettore della cassa esclude i fondi liberamente prelevabili per altri imprevisti — altrimenti quei soldi vincolati diventerebbero disponibili per qualunque sforo futuro su una voce diversa. Con un avanzo (nessun vincolo deliberato) questa restrizione non si applica.
- **La rata integrativa ora tiene conto del già versato**: quando si registra una fattura che sfora il budget di una voce con già versato attivo, la modale "Sforamento budget rilevato" mostra il residuo netto stimato — non l'intero sforo lordo — prima ancora di confermare. Scegliendo "Rata Integrativa" per una voce del genere, l'importo della voce nel piano dei conti si aggiorna da solo al costo reale: non serve più il passaggio manuale separato.
- **Eccedenza già versato ora visibile**: quando un'unità ha versato più del dovuto per una voce, la differenza non va mai sotto zero — ma prima restava visibile solo nei log del server. Ora apre un task in Inbox, con il dettaglio per unità di quanto va restituito o conguagliato.
- **Voce di spesa "da esercizio precedente"**: in creazione di una nuova voce nel piano dei conti, un checkbox dedicato (con tooltip esplicativo) marca le voci che richiedono il già versato — l'elenco della pagina "Già versato" mostra solo quelle, non l'intero piano dei conti. La stessa voce, nell'albero del piano dei conti, mostra ora una piccola icona a orologio per riconoscerla a colpo d'occhio.
- **Tre card guida** nella pagina "Già versato", sullo stesso modello delle altre pagine del gestionale.

### Corretto

- **Doppio conteggio del già versato quando il budget della voce non viene aggiornato**: generare un piano rate integrativo senza prima portare l'importo della voce al costo reale poteva far applicare il già versato contro un budget sbagliato, azzerando uno sforo vero senza mai chiederlo ai condòmini. Ora il sistema blocca la generazione con un messaggio esplicito che dice cosa correggere, invece di lasciare passare un calcolo silenziosamente sbagliato.
- **La stampa "Riparto per Capitolo di Spesa"** mostrava l'importo lordo della voce invece del netto (già versato scontato), disallineata dalle rate realmente emesse — corretta con lo stesso riallineamento già applicato alla stampa "Riparto per Tabella".
- **Ripartizione mista proprietario/inquilino con già versato**: la copertura è per immobile, non per soggetto — un versamento fatto solo dal proprietario finiva per scontare anche l'inquilino. Non ancora risolto nel calcolo (caso raro): la pagina ora lo segnala esplicitamente, invitando a verificare a mano.
- **Bug della beta.26**: nella pagina "Già versato", i link del menu a Gestioni, Piani dei Conti e Piano Rate portavano a un errore 404. La pagina non passava l'esercizio corrente al menu — unico punto del gestionale a non farlo, perché è l'unica pagina raggiungibile senza passare da un esercizio specifico nell'indirizzo.

---

## [1.10.0-beta.26] - Il Già Versato Chiude il Buco B & la Revisione Ferma un Secondo Buco Prima di Aprirlo

Caso reale segnalato sul forum: un condominio arriva da un altro gestionale con un accantonamento già raccolto (delibera 2025, €500 a testa per una ristrutturazione). Nel 2026 la fattura finale supera l'accantonato e il piano rate integrativo chiede l'INTERA spesa una seconda volta, perché nel motore di riparto non esiste il concetto di "quanto questa unità ha già versato per questa voce" — l'unica traccia di denaro incassato è legata alla rata che lo ha generato, mai alla voce di spesa. Questa beta introduce quella struttura dati e la pagina per compilarla: **Già versato per voce di spesa**, raggiungibile da Struttura.

**⚠ MIGRAZIONE DATABASE**: una migrazione, eseguita automaticamente dall'aggiornamento guidato. Crea la tabella `contributi_versati` (nuova, nessun dato esistente toccato): una riga per unità immobiliare, per voce di spesa, con l'importo già raccolto. Nessuna installazione esistente ha righe qui finché un amministratore non le inserisce esplicitamente — retrocompatibilità totale, il riparto si comporta esattamente come prima ovunque questa tabella sia vuota.

### Aggiunto

- **Pagina "Già versato per voce di spesa"** (`ContributiEdit.vue`): per ogni voce, elenca le unità con i rispettivi millesimi e la quota lorda dovuta. La compilazione rapida distribuisce un totale raccolto per millesimi, penny-perfect; ogni riga resta comunque correggibile a mano. Un pannello mostra in tempo reale l'effetto sul prossimo piano rate — "senza questo dato" vs "con il già versato" — prima ancora di salvare.
- **Elenco "Già versato"** (`ContributiList.vue`): tutte le voci di spesa del condominio con lo stato di copertura di ciascuna (barra di avanzamento, importo coperto, unità coperte). Nuova voce nel menu Struttura.
- **Netting nel motore di riparto** (`CalcoloQuoteService::nettingGiaVersato`): sottrae dalla quota lorda di ogni unità quanto quell'unità ha già versato per quella specifica voce. La copertura segue l'IMMOBILE (art. 63 disp. att. c.c.): se l'unità ha più comproprietari, si ripartisce tra loro in proporzione alle rispettive quote lorde, senza perdere un centesimo. Chi ha versato più del dovuto non va mai sotto zero — l'eccedenza si accumula e resta leggibile (`getEccedenzeCopertura()`), non sparisce in silenzio.
- **Qualificazione legale del versamento**: "Fondo deliberato" (art. 1135 c.c., vincolo di destinazione) o "Rate già riscosse" (senza vincolo, conguagliabili a fine gestione). Il software non decide da solo: un testo esplicito ricorda che la distinzione la stabilisce la delibera, non l'interfaccia.

### Corretto

- **Il netting non veniva mai "consumato"**: rileggeva l'INTERA copertura storica a ogni chiamata sullo stesso conto. Con un capitolo finanziato da due piani rate separati (acconto + saldo), ciascuno sottraeva la copertura per intero dal proprio lordo invece che la propria quota — nell'esempio verificato, un residuo vero di €400 veniva richiesto per €0, senza alcun avviso bloccante. Corretto applicando la copertura in proporzione al peso di ciascuna chiamata sul budget nominale del conto: con un solo piano rate (il caso comune, nessuna rateizzazione in tranche) il comportamento resta identico a prima. Lo stesso principio corregge il caso gemello nel motore straordinario, quando due fatture diverse puntano allo stesso conto imprevisto nella stessa esecuzione.
- **Il saldo di apertura di una cassa poteva essere contato due volte** *(bug in produzione dalla beta.25)*: dopo che l'apertura viene portata a giornale, il campo "Saldo iniziale" nel form di modifica risultava vuoto e modificabile — con un testo guida che invitava esplicitamente a "correggerlo". Un amministratore che riapre il form per qualunque motivo (anche solo rinominare la cassa) e ridigita l'importo che vede mancante fa tornare in vita la colonna mentre la scrittura di apertura resta comunque a giornale: lo Stato Patrimoniale si sbilancia esattamente di quella cifra. Il campo ora si blocca non appena l'apertura è registrata (non solo quando esistono altri movimenti) e mostra il saldo reale corrente a scopo informativo, non una colonna azzerata che sembra da compilare.
- **`immobile_id` non era vincolato al condominio** nella validazione del salvataggio: un payload con l'id di un immobile di UN ALTRO condominio veniva accettato dal server. Ora la validazione lo respinge esplicitamente.
- **I capitoli (voci contenitore, beta.22) comparivano fra le voci su cui registrare un versamento**, con importo €0: aprirli portava a una schermata senza senso. Esclusi dall'elenco e bloccati anche via URL diretto.
- **Formattazione degli importi in inglese**: i due campi monetari della pagina non ricevevano le opzioni di formato del resto dell'app (decimale `,`, migliaia `.`) e digitare "1500" produceva "15.00" — stesso bug della famiglia già corretta in beta.24, qui intercettato prima del rilascio.

### Test

- 20 nuovi test: il ciclo end-to-end della pagina (mostra, salva, sostituisce, elenca), il caso del forum riprodotto esattamente, copertura parziale/totale, eccedenza, comproprietari, retrocompatibilità con tabella vuota; la riproduzione esatta del bug acconto+saldo e della sua correzione, il caso gemello nello straordinario con due fatture sullo stesso conto, lo scoping multi-condominio, l'esclusione dei capitoli; la riproduzione dal vivo del doppio conteggio sul saldo di apertura e la sua correzione.
- Suite completa: 578 test verdi (prima di questa beta: 558).
- Nessuna delle correzioni di questa sezione è stata scoperta da un utente: la revisione avversariale è girata PRIMA del rilascio, sullo stesso codice che sarebbe altrimenti uscito così com'era.

---

## [1.10.0-beta.25] - Il Saldo di Apertura Entra a Giornale & lo Stato Patrimoniale Impara a Quadrare

Fino a ieri il saldo di apertura di una cassa viveva in un campo della tabella `casse` e **non generava alcuna scrittura contabile**: entrava nel sistema come pura attività, senza contropartita. Nessun controllo poteva accorgersene — il validatore di partita doppia verifica che ogni *singola* scrittura abbia DARE = AVERE, ma un valore che non ha proprio una scrittura non viola nulla. Il risultato è che ogni condominio con una cassa avviata con un saldo (praticamente ogni migrazione da un altro gestionale) portava con sé uno scarto patrimoniale invisibile, destinato a saltar fuori il giorno in cui si costruisce lo Stato Patrimoniale.

Questa beta chiude quel buco e, soprattutto, introduce lo strumento che impedisce di riaprirne di simili in futuro.

**⚠ MIGRAZIONE DATABASE**: due migrazioni, entrambe eseguite automaticamente dall'aggiornamento guidato. La prima rende `gestione_id` opzionale sulle scritture (permissiva, non tocca alcun dato esistente); la seconda **sposta il saldo di apertura delle casse dal campo al giornale**, creando la scrittura di contropartita e azzerando il campo nella stessa transazione. Il saldo mostrato non cambia mai: né prima, né durante, né dopo.

### Aggiunto

- **Stato Patrimoniale con verifica di quadratura** (`StatoPatrimonialeService`): calcola attivo, passivo e risultato d'esercizio e verifica l'equazione fondamentale **Attività = Passività + Patrimonio Netto**. È insieme un report e un sistema d'allarme: qualunque valore che entri in contabilità senza contropartita produce qui uno sbilancio, invece di restare invisibile fino alla segnalazione di un amministratore. Espone anche l'eventuale liquidità non ancora contabilizzata, così un'installazione a metà aggiornamento lo dichiara invece di nasconderlo. *(Il motore di calcolo; la pagina dedicata arriverà in una prossima beta.)*
- **Il saldo di apertura di una cassa è ora una scrittura contabile vera** (`DARE cassa / AVERE Fondo Passate Gestioni`), sia per le casse già esistenti (migrazione di backfill) sia per quelle create da qui in avanti. Un saldo di apertura negativo (conto scoperto) resta bilanciato invertendo i versi, senza mai scrivere importi negativi a giornale.

### Corretto

- **Saldi che contavano movimenti annullati**: il saldo di una cassa poteva risultare diverso a seconda di *chi* lo chiedeva — alcune schermate escludevano le scritture annullate, altre no. Con una scrittura stornata da 500 € la stessa cassa poteva mostrare due valori diversi a distanza di un clic. Ora il saldo ha un'unica fonte di calcolo, che esclude sempre le scritture annullate.
- **Creazione o modifica di una cassa di tipo Banca senza compilare "intestatario"**: il campo è facoltativo, ma lasciarlo vuoto generava un errore invece di ricadere sul nome del condominio come previsto (relazione mancante nel modello, usata in due punti del codice).
- **Liquidità per gestione nel Treasury Guardian**: il saldo di apertura di una cassa non appartiene ad alcuna gestione (i soldi in banca sono del condominio). Filtrando il predittore di liquidità per una singola gestione, quel saldo sarebbe stato escluso e la liquidità disponibile sarebbe crollata a zero pur essendoci denaro reale in banca, con falsi allarmi di cassa. Ora le scritture senza gestione restano sempre incluse.

### Modificato

- **Un solo calcolo del saldo cassa**: la formula era replicata in otto punti diversi tra servizi, controller e risorse, con divergenze silenziose tra l'uno e l'altro. Ora passano tutti dallo stesso servizio.
- **La scrittura di apertura non conta come "movimento" ai fini delle restrizioni di modifica**: la genera il sistema, non l'amministratore. Una cassa appena creata resta quindi pienamente modificabile (tipo e saldo di apertura), mentre resta bloccata — come prima — non appena ha un movimento reale.

### Test

- 26 nuovi test: contratto di retrocompatibilità 1.9.x → 1.10 (il saldo non cambia in nessuno stato), backfill reale con idempotenza, saldo negativo, salto sicuro in caso di dati insufficienti, rollback simmetrico, guardie di modifica, liquidità per gestione e invariante di quadratura.
- Suite completa: 558 test verdi (prima di questa beta: 532).
- **Nota di sicurezza sulla migrazione**: ogni caso dubbio viene *saltato*, mai forzato. Una cassa saltata resta nello stato precedente, che è comunque corretto — non esiste percorso in cui questa migrazione possa falsare un saldo.

---

## [1.10.0-beta.24] - Il Piano Rate che Spariva nel Nulla & la Rata Integrativa Ritrova il Nome

Segnalato da un amministratore in un caso di supporto reale: creando un piano rate integrativo su una voce di spesa in sforo, il click su "Salva piano rate" non produceva alcun effetto — nessuna navigazione, nessun errore a video, nessuna riga nei log del server. La causa era annidata su due livelli. Il campo importo, se lasciato al valore suggerito senza essere toccato manualmente, restava un numero grezzo invece che una stringa, e falliva silenziosamente la validazione lato server (che richiede esplicitamente una stringa); il tentativo di risolverlo passando dalla libreria di formattazione monetaria (`v-money3`) introduceva a sua volta un secondo bug — un valore "1.234,56" scritto a mano viene reinterpretato dal componente al primo render come sequenza di cifre grezze da tagliare, producendo importi sbagliati di un fattore 100 negli importi mostrati a schermo (es. "Totale richiesto" e "Residuo").

Nessuna migrazione database.

### Corretto

- **Il piano rate si salva sempre, che l'importo suggerito venga toccato o no**: i tre campi che alimentavano un valore monetario iniziale a un `MoneyInput` (`importo_da_usare` nella selezione capitoli, `importo_suggerito` nelle fatture straordinarie, `importo` nelle righe di ripartizione manuale saldi) ora partono da una stringa in notazione JS pura (`"1234.56"`), mai da un numero grezzo né dalla notazione mascherata italiana — l'unica forma che il componente `Money3` non ri-formatta mai da solo al mount, e che il backend (`MoneyHelper::toCents`) ha sempre saputo interpretare correttamente in entrambi i casi.
- **`parseMoney()` non assume più un solo formato**: la funzione usata per i calcoli live in pagina (totale selezionato, avviso di sforo sul residuo) ora riconosce la notazione in base alla presenza della virgola — esattamente la stessa logica già usata da `MoneyHelper::toCents()` lato server — invece di trattare ogni punto come separatore delle migliaia a prescindere.
- **Etichetta "Rata Integrativa" riallineata**: la modale di sforamento sulla fattura (`ModalOverrideBudget.vue`) e la guida in-app alla registrazione fatture mostravano "Genera nuovo piano" per la stessa identica strategia (`rata_integrativa`) che altrove nell'app — inclusa la card della Dashboard — è sempre stata etichettata "Rata Integrativa". Nessun cambio di comportamento, solo coerenza terminologica: la guida di supporto che rimanda l'amministratore a "scegliere Rata Integrativa" ora corrisponde di nuovo a quello che vede a schermo.
- **Tre errori di sintassi TypeScript corretti** (`EventiEdit.vue`, `EventiNew.vue`, `eventi/user/EventiEdit.vue`): un tipo oggetto anonimo scritto inline dentro un'espressione di template (`:reduce="(opt: { label: string; value: string }) => ..."`) mandava in errore il parser di `vue-tsc`. Nessun impatto a runtime — il template compilava ed eseguiva correttamente anche prima — ma bloccava un type-check pulito del progetto. Risolto estraendo un tipo con nome (`OpzioneSelect`), coerente con come gli altri `:reduce` dello stesso file già referenziano tipi con nome.

### Test

- Nessuna suite di test automatici copre questa parte del frontend: il progetto non ha un ambiente di test JS/Vue (solo Pest lato backend). La correzione è stata verificata dal vivo — riproduzione end-to-end del bug tramite tab Network del browser, e conferma indipendente che il backend (`GeneratePianoRateAction`, `CalcoloQuoteService`) era sempre stato corretto tramite una chiamata diretta al servizio in una transazione poi annullata, sugli stessi dati reali del caso di supporto. `vue-tsc --noEmit` pulito su tutti i file toccati.
- Segnalato come lacuna nota: questa classe di bug (formato numero/stringa su un campo `MoneyInput` non toccato dall'utente) potrebbe ripetersi altrove nell'app dove esiste lo stesso pattern — nessuno sweep sistematico è stato fatto in questa beta, per restare nello scope della segnalazione originale.

---

## [1.10.0-beta.23] - Riparto per Capitolo: una Colonna per Capitolo, non per Sottoconto

Segnalato da un amministratore: la stampa "Riparto Bilancio Preventivo per Capitolo e Soggetto" mostrava una colonna per ogni **sottoconto foglia** (es. "AM.BK Bancarie", "AM.CF Compensi", "AM.DF Dichiarazioni"...) invece che una per il capitolo padre reale ("Amministrative"). Su un condominio con molti sottoconti la tabella HTML generata poteva diventare così grande da far scattare il limite di sicurezza di mPDF (errore 500, `pcre.backtrack_limit`); su un condominio più piccolo il chunking a 6 colonne per pagina della stampa mostrava solo una parte delle colonne sulla prima pagina, con un totale di riga (calcolato correttamente su tutti i capitoli) che non coincideva con la somma dei valori visibili — confuso, anche se nessun dato era davvero sbagliato.

Nessuna migrazione database.

### Corretto

- **`RipartoCapitoliService` aggrega ora sul capitolo radice**, non sul sottoconto foglia: un capitolo con più sottoconti produce una sola colonna nella stampa, con l'importo che è la somma di tutti i suoi sottoconti. La matematica di ripartizione (tabelle millesimali, ripartizioni per soggetto, arrotondamento Hare-Niemeyer) resta calcolata sui sottoconti foglia, dove vivono davvero tabella e percentuali — solo l'aggregazione finale cambia.
- Se i sottoconti di uno stesso capitolo usano tabelle millesimali diverse tra loro, la colonna dei millesimi per quel capitolo mostra un trattino (nessun valore ambiguo mostrato): l'importo in euro resta sempre esatto, indipendentemente da questo caso.
- **L'aggregazione da sola non bastava a scala reale**: un test di stress dedicato (25 capitoli × 4 sottoconti, 40 unità) produce comunque ~1,55 MB di HTML — sopra il default PHP di `pcre.backtrack_limit` (1 MB) — e mPDF rifiutava comunque la generazione. `PdfService` alza ora quel limite (20.000.000, il fix ufficiale raccomandato da mPDF per questo esatto errore) prima di ogni stampa: verificato fino a ~6 MB di HTML (50 capitoli × 80 unità) senza errori. Non cambia nulla per le stampe che già rientravano nel limite, apre solo margine per i condomini grandi che prima fallivano — compresa `riparto_tabelle`, mai stata il problema ma protetta dallo stesso margine.
- **Non toccato nella logica**: la stampa "Riparto per Tabella × Soggetto" (`RipartoTabelleService`, `riparto_tabelle.blade.php`) — funzionava correttamente ed era esplicitamente fuori scope da questa correzione.

### Test

- `RipartoCapitoliAggregazionePadreTest`: 6 casi — un capitolo con più sottoconti produce una sola colonna, il totale di riga coincide sempre con la somma delle celle, sottoconti su tabelle diverse restano esatti in euro pur segnalando la quota come mista, un conto di primo livello senza figli resta invariato (retrocompatibilità), una verifica end-to-end che la stampa PDF risponde 200 con un capitolo a 8 sottoconti, e un caso a scala reale (25 capitoli, 40 unità) che riproduce esattamente il crash originale e verifica che il fix su `PdfService` lo risolva — confermato fallire senza quel fix, prima di essere aggiunto alla suite.
- Suite completa: 532 test verdi (prima di questa beta: 526).

---

## [1.10.0-beta.22] - Il Capitolo Diventa un Fatto, non un Indovinello

Un amministratore aveva segnalato: una voce di spesa non ancora budgettizzata quest'anno (importo a zero, ma con tabella millesimale e ripartizioni reali già collegate) perdeva silenziosamente tabella e ripartizioni al primo salvataggio della modale di modifica, senza toccare nulla. La causa: se un conto fosse un "capitolo" (contenitore puro, senza tabella propria) non è mai stato un fatto salvato — era **indovinato** in tre punti diversi del codice dalla stessa euristica fragile (`parent_id` nullo + importo a zero), anche se l'amministratore lo sceglie esplicitamente con un interruttore al momento della creazione. Una voce reale a zero ci finiva dentro per coincidenza, e il controller — corretto a marzo per ripulire i capitoli davvero orfani (commit `e25eefa2`) — cancellava tabella e ripartizioni convinto in buona fede di star pulendo un contenitore vuoto.

**⚠ MIGRAZIONE DATABASE**: una migrazione aggiunge `is_capitolo` a `conti`, con un backfill in tre passi pensato per non essere mai a rischio sui piani dei conti già esistenti: (1) parte dal criterio che il sistema usa già oggi per proporre i capitoli padre (primo livello, importo zero) — nessun cambio di comportamento osservabile; (2) protegge chi ha già una tabella millesimale reale collegata, che non è mai un capitolo qualunque sia il suo importo — il passo che chiude il difetto; (3) chi ha sottoconti resta sempre capitolo, coerente con la guardia strutturale già in vigore. Eseguire `php artisan migrate` dopo l'aggiornamento.

### Corretto

- **Voce a zero con tabella millesimale reale non perde più i dati al resave**: `is_capitolo` è ora un campo persistito, scelto esplicitamente in creazione e mai più dedotto da importo/parent_id. Corretti tutti e tre i punti che usavano l'euristica fragile: `FetchCapitoliContiController` (elenco dei capitoli padre proponibili), `ModalModificaConto.vue` (classificazione all'apertura della modale di modifica) e `AlberoDeiConti.vue` (icona cartella/file e ordinamento nell'albero).
- **Doppia guardia lato server contro la cancellazione**, indipendente da eventuali regressioni future del frontend: il ramo che elimina tabella/ripartizioni scatta solo su una transizione vera da non-capitolo a capitolo rispetto allo stato persistito (non più su un semplice resave), e se quella transizione cancellerebbe dati reali richiede una conferma esplicita — altrimenti il salvataggio viene rifiutato con un errore di validazione, mai una cancellazione silenziosa. La conversione deliberata (capitolo genuinamente nuovo) resta possibile con un dialogo di conferma esplicito in modale.
- **Regressione sfiorata sulla lista "capitolo padre" (bug distinto, stessa famiglia)**: sostituire il vecchio filtro `parent_id nullo + importo 0` con il solo `is_capitolo=true` aveva fatto perdere la guardia strutturale che la beta.16 (commit `073a6885`) aveva introdotto contro un sotto-conto già annidato riproposto come capitolo padre selezionabile — un record con `parent_id` valorizzato può avere `is_capitolo=true` (es. dal passo 3 del backfill della migrazione, che non guarda il *proprio* `parent_id` del record) e ricomparire nel menu a tendina. Le due condizioni ora si sommano (`is_capitolo=true` **e** `parent_id` nullo), non si sostituiscono. Aggiunta anche una guardia gemella lato richiesta: `isCapitolo` e `isSottoConto` insieme vengono ora sempre rifiutati alla creazione/modifica, per impedire che si ricreino stati incoerenti in futuro.

### Test

- `IsCapitoloBackfillTest`: 6 casi — le 4 combinazioni del backfill sulla migrazione (capitolo vuoto, voce con tabella reale, voce con importo diverso da zero, capitolo con sottoconti anche in presenza di un'inconsistenza pregressa, sottoconto), più uno scenario end-to-end che riproduce il difetto originale da zero su dati "stile legacy" e verifica che il resave dopo il backfill non cancelli nulla.
- `ContoControllerTest`: 3 nuovi casi sulla guardia anti-cancellazione (rifiuto senza conferma, conversione deliberata con conferma, resave normale che sopravvive) + 2 fixture pre-esistenti aggiornate con `is_capitolo` esplicito + 3 nuovi casi sulla guardia "capitolo padre" (sotto-conto con `is_capitolo=true` escluso dalla lista, `isCapitolo`+`isSottoConto` rifiutati in creazione e in modifica).
- Suite completa: 526 test verdi (prima di questa beta: 517).

---

## [1.10.0-beta.21] - Ritenuta d'Acconto: Fase 1 (Anagrafica e Calcolo)

Nasce da un fastidio piccolo (l'aliquota IVA proposta in fattura, fissa al 22%, spesso sbagliata) che ha portato a scoprire un problema più grande: la ritenuta d'acconto è oggi un interruttore fisso sul fornitore (`soggetto_ritenuta` + una sola percentuale), ma un fornitore reale può emettere sia fatture soggette sia fatture che non lo sono — l'esempio guida è un fornitore di estintori: la manutenzione è soggetta a ritenuta, la vendita dell'estintore no. Questa beta implementa la **Fase 1** di `docs/design/f24_ritenute_design.md`: anagrafica e calcolo, **zero impatto sul libro giornale** — il momento in cui la ritenuta entra in contabilità resta quello di oggi (alla registrazione). Le Fasi 2 (spostamento del fatto generatore al pagamento — "fase critica" nel design) e 3 (deleghe F24) restano deliberatamente fuori da questo rilascio.

Il documento di design è stato scritto scandagliando normativa e prassi condominiale, ma senza revisione di un commercialista: gli amministratori beta tester sono il collaudo che manca.

**⚠ MIGRAZIONE DATABASE**: due migrazioni, additive e non distruttive. `fornitori` guadagna il regime fiscale (`tipo_ritenuta`, `natura_percipiente`, `residente_fiscale`, `regime_forfetario` con data e riferimento dichiarazione, `provvigioni_base_ridotta`) con un backfill euristico dai campi legacy (`perc_ritenuta = 4` → appalto, `= 20` → lavoro autonomo, `codice_tributo` 1019/1020 → natura del percipiente); dove l'euristica non riconosce un valore noto resta `NULL`, mai un regime inventato. `righe_fattura` guadagna `concorre_base_ritenuta` (default `true`, nessuna riga esistente cambia comportamento) e `natura_riga_ritenuta`. Eseguire `php artisan migrate` dopo l'aggiornamento.

### Aggiunto

- **Namespace `App\Enums\Fiscale`** (`TipoRitenuta`, `NaturaPercipiente`, `PlafondRitenuta`, `TitoloRitenuta`, `MotivoEsclusioneRitenuta`, `NaturaRigaRitenuta`) + `config/fiscale.php`: codici tributo, aliquote storicizzate per regime, riferimenti normativi (incluso lo switch 2027 art. 25-ter → D.Lgs. 33/2025 per gli appalti). Nessun default silenzioso: un'aliquota mancante per la data richiesta lancia un'eccezione, mai uno zero muto.
- **`RitenutaService`**: motore di calcolo puro (nessuna scrittura contabile). Doppio regime supportato in parallelo — il nuovo (`tipo_ritenuta` + `natura_percipiente`, che pilota da solo la scelta fra codice tributo 1019/1020) e il legacy (i vecchi `perc_ritenuta`/`perc_imponibile_ritenuta`/`codice_tributo`, per i fornitori non ancora migrati) — così nessun fornitore esistente cambia comportamento finché non viene aggiornato. Il regime forfetario esclude la ritenuta sempre, anche se qualcuno la richiede esplicitamente: è un fatto di legge sul fornitore, non una scelta per documento.
- **Anagrafica fornitore**: selettori "Regime di ritenuta" e "Natura del percipiente", checkbox "Regime forfetario" (con data e riferimento della dichiarazione conservata) e "Residente fiscale in Italia", dichiarazione di provvigioni a base ridotta. I campi storici (percentuale, codice tributo) restano come override manuale facoltativo, non più testo libero obbligatorio.
- **Fattura**: toggle "Applica ritenuta d'acconto" per singolo documento (motivo obbligatorio quando disattivato su un fornitore soggetto: bonifico parlante, fuori campo, posa accessoria, altro), checkbox per riga "Concorre alla base ritenuta" (il contributo integrativo cassa professionale, ad esempio, non entra mai in base — la rivalsa INPS gestione separata sì).
- **Prefill intelligente dell'aliquota IVA**: una nuova riga riprende l'ultima aliquota usata per il fornitore selezionato (calcolata server-side dallo storico fatture), non più un 22% fisso — le bancarie restano a 0%, la manodopera edile a 10%, i professionisti a 22%, senza doverla correggere ogni volta.
- **Warning bloccante su `natura_percipiente` mancante** (design §2.4 M2): se il fornitore ha un regime di ritenuta ma non la natura del percipiente né un codice tributo legacy come override, il salvataggio è bloccato con un avviso e un link diretto all'anagrafica fornitore — a meno di conferma esplicita ("procedo comunque, correggo il codice tributo prima dell'F24"). Il blocco duro, senza possibilità di conferma, resta rimandato a v1.11 come deciso dal design doc: i dati reali hanno oggi codici tributo misti e incoerenti, un blocco immediato avrebbe paralizzato il flusso il giorno dopo l'aggiornamento.

### Corretto — 5 difetti indipendenti individuati durante il design (§8)

- **`applica_ritenuta` non era validato in `StoreFatturaRequest`**: `$request->validated()` scartava la chiave e vinceva sempre il default "applica". Dalla UI non si poteva registrare una fattura senza ritenuta per un fornitore soggetto, nemmeno spuntando l'opzione.
- **Lo storno fattura forzava `applica_ritenuta = false` incondizionatamente**: se l'originale aveva ritenuta, restava un residuo aperto sul conto Erario invece di essere stornato insieme al resto. Ora lo storno propaga dall'originale se applicare la ritenuta e con quali righe escluse dalla base, in modo che l'importo stornato coincida esattamente con quello versato in origine.
- **Una nota di credito genuina su un fornitore soggetto a ritenuta calcolava comunque una trattenuta**, producendo una riga contabile che l'anteprima in fattura non mostrava. Ora una nota di credito non applica la ritenuta salvo scelta esplicita.
- **La nota della scrittura in Erario riportava sempre "Ritenuta d'acconto 4%"**, anche quando l'aliquota reale era 20% o un'altra.
- **La base della ritenuta includeva indiscriminatamente ogni riga della fattura**, contributo cassa professionale compreso — che per legge va escluso anche dal punto 4 della Certificazione Unica.

### Corretto — dalla revisione avversariale pre-porting (agente indipendente sul diff completo)

- **Critico**: modificare una fattura di un fornitore *non* soggetto a ritenuta veniva rifiutata dal salvataggio. `FatturaRegisterEdit.vue` inizializzava `applica_ritenuta` a un booleano esplicito sempre; per un fornitore non soggetto valeva `false`, e `required_if:applica_ritenuta,false` in `UpdateFatturaRequest` scattava per un campo (`motivo_esclusione_ritenuta`) del tutto irrilevante per quella fattura. Rotto per la maggioranza dei fornitori — quelli non soggetti a ritenuta. Ora il booleano esplicito viene impostato solo quando la ritenuta è davvero rilevante per il fornitore; altrimenti `null`, come in creazione.
- **Alto**: lo storno ricalcolava la ritenuta sull'anagrafica *attuale* del fornitore invece di annullare l'importo *realmente registrato*. Se l'anagrafica cambiava fra la registrazione e lo storno (es. il fornitore diventava forfetario, o cambiava regime), l'importo stornato divergeva da quello originale, riaprendo lo stesso residuo fantasma su 2201/2202 che il fix del punto 2 doveva chiudere. Lo storno ora **fissa** l'importo, l'aliquota e il codice tributo dell'originale (`RitenutaCalcolo::override()`), senza mai reinterrogare lo stato corrente del fornitore.
- **Minore**: il regime legacy (`perc_ritenuta`/`perc_imponibile_ritenuta`) calcolava uno zero silenzioso se un fornitore soggetto a ritenuta non aveva la percentuale configurata, in contrasto con la disciplina "mai default silenzioso" già applicata al regime nuovo. Ora lancia un'eccezione esplicita, come `TipoRitenuta::aliquota()`.
- **Documentato e bloccato con un test**: l'arrotondamento a due passaggi (base ritenuta arrotondata, poi aliquota applicata) diverge di 1 centesimo da un calcolo diretto su circa il 6% degli importi possibili per i regimi provvigioni. Scelta deliberata — la base è di per sé una cifra rilevante per la Certificazione Unica in Fase 2 — ora esplicita e testata invece che implicita.

### Deciso e NON fatto

- **Fase 2** (il fatto generatore della ritenuta si sposta da registrazione a pagamento, tabella `ritenute_operate` come esplosione analitica della riga 2202) resta fuori: è la fase che il design doc definisce critica, perché tocca il libro giornale. Programmata per una beta successiva.
- **Fase 3** (deleghe F24, generazione del modello, quadro ST/AC) resta fuori: nessun consumatore in questa beta, nessuna colonna aggiunta per essa.
- **`NaturaRigaRitenuta` non collegato alla UI fattura**: l'enum esiste ed è testato (guida il default di `concorre_base_ritenuta` — cassa professionale esclusa, rivalsa INPS inclusa), ma in fattura resta esposto solo il checkbox grezzo "Concorre alla base ritenuta", senza il menu che ne spiegherebbe il motivo. Comportamento corretto e già coperto da test, manca solo la categorizzazione in UI. Roadmap: prossima release che tocca questa pagina.

### Test

- `RitenutaCalcoloTest`: 23 casi puri (TipoRitenuta, enum di supporto, `RitenutaService`, DTO, arrotondamento provvigioni) — nessuna scrittura contabile, nessuna tabella richiesta.
- `RitenutaSezione8Test`: 12 casi — 6 sui difetti originali del §8, 2 di riproduzione dei difetti trovati dalla revisione avversariale, 3 sul warning `natura_percipiente`, 1 di non-regressione sul default `concorre_base_ritenuta`.
- Suite completa: 514 test verdi (prima di questa beta: 479).

---

## [1.10.0-beta.20] - La Modifica Oltre Budget Prende Atto

La beta.18 aveva tolto la finzione (l'override in modifica che non registrava nulla), la beta.19 ha messo in sicurezza la contabilità (guardia sulle fatture con copertura fondo, giroconti reali). Questo blocco chiude i due varchi rimasti sul fronte modifica: il salvataggio oltre budget che passava con un click, e la fattura ratificata che tornava modificabile.

Nessuna migrazione database.

### Modificato — comportamenti che cambiano

- **Presa d'atto sul salvataggio oltre budget in modifica** (`FatturaRegisterEdit.vue`): con un capitolo oltre il residuo, il submit apre un dialogo che dichiara l'importo dello sforo e cosa NON accadrà salvando (nessuna motivazione registrata, nessuna copertura, fattura fuori dal cruscotto sforamenti); si annulla o si salva consapevolmente. Nessun flag persistente: il dialogo si ripresenta a ogni submit oltre budget, così una modifica ulteriore delle righe non eredita una conferma vecchia. Decisione di design (strada "presa d'atto onesta", non "override reale"): motivare uno sforo in modifica resta impossibile — la via è storno + nuova registrazione, come dichiara il banner già presente dalla beta.18.
- **Guardia sulla fattura ratificata senza copertura** (`FatturaPassivaService::motivoBloccoModifica`): `dati_extra.override_budget` presente → modifica vietata. Le strategie conguaglio/rata integrativa non creano coperture, quindi la guardia beta.19 sul fondo non le vedeva: dopo la ratifica (`sforo_motivato` → `approvata`) la fattura tornava modificabile, con motivazione e importo dello sforo in `dati_extra` riferiti a cifre che l'assemblea non ha mai visto. Ordine dei controlli: lo stato `sforo_motivato` mantiene il suo messaggio specifico ("in attesa di ratifica"); la nuova guardia scatta sulle ratificate.

### Deciso e NON fatto

- **La fattura modificata oltre budget non entra nel cruscotto sforamenti**: il cruscotto conta lo stato `sforo_motivato`, non una condizione calcolata. Dopo una modifica lo sforo è una proprietà del capitolo, non attribuibile a una singola fattura; transitare la fattura a `sforo_motivato` senza motivazione romperebbe l'invariante su cui poggiano ratifica e widget. Nota aperta per il futuro: l'albero del piano dei conti oggi maschera gli sfori non motivati (`PianoContiController` porta `importo` a `speso` quando lo speso supera il budget) — se si vorrà dare visibilità agli sfori "silenziosi", la sede è lì, come delta rispetto a `budgetOriginaliMap`.

### Test

- `FatturaPassivaServiceTest`: nuovo caso — sforo motivato con strategia conguaglio (nessuna copertura), bloccato prima della ratifica dalla guardia sullo stato e dopo la ratifica dalla nuova guardia su `dati_extra`.

---

## [1.10.0-beta.19] - Giroconti: i Fondi Diventano Movimenti Veri

La beta.18 aveva corretto *quanto* vale uno sforo; questa corregge *quando e come* il fondo lo copre. Il giroconto era predisposto fin dal primo giorno — il caso `giroconto` nell'enum, il prefisso `GIR`, la voce di menu col badge — e almeno quattro pezzi del gestionale erano scritti a metà in sua attesa: le coperture eternamente `pianificata`, lo storno che azzerava i report con un moltiplicatore, il conto 2202 che non si chiude, il Treasury Guardian col TODO sulla liquidità vincolata. Questa beta paga il debito.

**⚠ MIGRAZIONE DATABASE**: `fattura_coperture` guadagna `scrittura_giroconto_id` (FK → `scritture_contabili`, nullOnDelete) e `confermata_at`. Eseguire `php artisan migrate` dopo l'aggiornamento. Nessuna migrazione sull'enum `tipo_movimento`: `giroconto` era già previsto, `storno_giroconto` entra come case PHP su colonna VARCHAR.

### La scoperta che ha dettato il design

I fondi avevano il **segno in contraddizione**: tre punti (`CassaResource`, il contesto budget delle fatture, le scritture di sforo) li trattavano da passivo (`avere − dare`), mentre il loro conto contabile nasce **attivo/liquidità** (figlio del mastro 1010, `CreateCassaAccountAction`) e sia `Cassa::saldo_reale` sia il Treasury li leggevano da attivo. Sulla stessa riga di giornale, letture opposte. La convenzione attiva è l'unica in cui l'accantonamento banca→fondo è scrivibile (AVERE banca / DARE fondo, liquidità totale invariata), coerente col vincolo di dominio già dichiarato nel codice: *"i fondi sono partizioni virtuali dell'unico conto corrente reale"* (`RegolazioneImmediataController`). Beta.19 unifica: **fondo = partizione attiva, ovunque**.

### Aggiunto

- **Giroconti** (`RegistraGirocontoAction`, `GirocontoController`, pagine `GirocontiList`/`GirocontoNew`): scrittura a 2 righe — DARE cassa destinazione / AVERE cassa origine, `cassa_id` su entrambe — protocollo `GIR`, causale, anteprima della scrittura nel form, saldi disponibili per cassa (`SaldoCassaService`, fonte unica in convenzione attiva). Voce di menu attivata, badge "In sviluppo" rimosso.
- **Storno giroconto** (`StornaGirocontoAction`, tipo `storno_giroconto`, protocollo `STO`): righe specchiate, `scrittura_padre_id`, cross-esercizio Variante B1. Sempre ammesso, senza verifica di capienza (dogma: lo storno corregge, non si blocca).
- **Conferma delle coperture**: un giroconto fondo→banca con `fattura_copertura_id` porta la copertura a `confermata` nella stessa transazione, agganciandola alla scrittura. Guardie: solo coperture `fondo_riserva` `pianificata` con importo positivo, origine = fondo della copertura, destinazione banca, conferma solo integrale. Lo storno riporta a `pianificata`. Entry point doppio: select nella pagina Giroconti + CTA "Conferma con giroconto" su `FatturaShow` (deep-link `?copertura_id=`); a conferma avvenuta la fattura mostra protocollo GIR, link alla scrittura e "Procedi al pagamento".
- **Riallineamento scritture storiche** (`RiallineaFondiService` + card in `GirocontiList` + comando `kondomanager:riallinea-fondi --dry-run`): le righe pre-beta.19 sul conto del fondo (coppia sforo DARE fondo/AVERE sopravvenienze; DARE strutturale del pregresso) vengono neutralizzate con scritture di **rettifica** append-only collegate all'originale (`RET`, `scrittura_padre_id`). La card compare SOLO se il rilevamento trova qualcosa e sparisce a rettifiche fatte; l'esecuzione richiede conferma esplicita con anteprima. Decisione di design: mai migrazione dati automatica sul libro giornale.
- **Guardie giroconto**: capienza origine bloccante senza override; fondi vincolati (`sottotipo_fondo` ≠ generico) in uscita solo con `is_override_assemblea`; mai fondo↔contanti/virtuale (la liquidità del fondo vive sul c/c); esercizio aperto; data non futura; `idempotency_key` contro il doppio click.

### Modificato — comportamenti che cambiano

- **Lo sforo con strategia fondo non scrive più la coppia DARE fondo / AVERE sopravvenienze** alla registrazione (`FatturaPassivaService`): il costo resta sul capitolo, il fondo si muove solo alla conferma. La copertura `pianificata` resta il gancio.
- **Il pregresso con copertura fondo** scrive il DARE strutturale su `passate_gestioni` (come le coperture rata_0), non più sul conto del fondo.
- **Saldo fondi in convenzione unica** in `CassaResource` e `prepareContestoBudget` (il `max(0,…)` della UI resta).
- **Treasury Guardian**: `liquiditaVincolataCents` calcolata dalle casse tipo fondo (il TODO v1.10 è saldato); `calcolaFondiVincolati` riscritta (la vecchia cercava `categoria='fondi'`, dove i fondi-cassa non stanno); `condominioHaFondoRiserva` ora guarda le casse (prima era sempre true per la radice PASSIVO 2000). **Il semaforo può cambiare in produzione**: la liquidità disponibile scende del valore dei fondi — è la verità che prima mancava.
- **Varchi chiusi**: `StoreIncassoRateRequest` e `StorePagamentoFornitoreRequest` ora validano il tipo cassa lato server (via API si poteva incassare/pagare su un fondo).

### Dalla revisione avversariale pre-rilascio (3 revisori + verificatore sul diff completo)

- **Il ciclo di vita `pianificata→confermata` è presidiato nei punti di uscita della fattura**: lo storno fattura è bloccato se la copertura è `confermata` (prima si storna il giroconto), `destroy()` idem (avrebbe cancellato la copertura lasciando il GIR orfano), le coperture di fatture stornate sono escluse da form, guardia dell'Action e banner, e `motivoBloccoModifica` blocca la modifica di fatture con copertura fondo (la copertura fotografa lo sforo; `aggiornaFattura` non la ricalcola).
- **Race chiuse coi lock in transazione**: doppio storno del giroconto (due tab → doppia inversione) e doppia esecuzione del riallineamento (card web + artisan simultanei → fondo sovra-corretto).
- **`UpdatePagamentoFornitoreRequest`**: `conto_corrente_id` ora scopato sul condominio e vincolato alle casse reali — era la porta di servizio del varco chiuso sullo Store.
- **Idempotency key scopata** su condominio+tipo nel giroconto (la colonna è UNIQUE globale e ospita anche le key dei pagamenti).
- **`fondi_riserva[].saldo_attuale` al netto delle coperture pianificate**: due sfori consecutivi non possono più promettere oltre la capienza dello stesso fondo.
- Vicolo cieco muto in `GirocontoNew` (fondo senza cassa attiva) ora segnalato; la paginazione di GirocontiList conserva la ricerca attiva.

### Corretto — dal collaudo a video

- **La spunta "Debito esercizio precedente" non sopravviveva alla scelta del fornitore** (`FatturaRegisterNew.vue`): il watch su `[fornitore_id, data_documento]` ricalcolava `is_pregresso` dalla data a ogni scatto — anche quando a cambiare era solo il fornitore — cancellando la scelta manuale e riportando la vista alla fattura normale. Ora il ricalcolo avviene solo quando cambia la data. Correlato: cambiando fornitore, il `saldo_patrimoniale_id` selezionato restava nel form ma non era più fra le opzioni filtrate del WidgetDoubleLock, che mostrava l'id grezzo ("47") al posto della descrizione — la selezione ora si azzera se il debito non appartiene al nuovo fornitore, e comunque uscendo dalla vista pregressa. In `FatturaRegisterEdit.vue` il ricalcolo è stato rimosso del tutto: in modifica `is_pregresso` è immutabile (il server lo ignora), e retrodatare la data poteva accendere una vista pregressa con i totali a zero.

### Test

- `GirocontoTest`: 32 casi — quadratura e saldi per accantonamento/conferma/storno, regressione dell'armonizzazione (CassaResource ≡ SaldoCassaService), capienza, coppie vietate, fondo vincolato con/senza deroga, conferma con tutte le guardie, doppio storno, cross-esercizio B1, riallineamento (coppia sforo e pregresso), idempotenza, coperture di fatture stornate non confermabili, destroy bloccato con copertura confermata, modifica bloccata con copertura fondo, HTTP (redirect, 422 su esercizio chiuso/data futura/motivo corto, props Inertia).
- `FatturaPassivaServiceTest` riscritto sui due scenari cambiati (nessuna riga sul fondo alla registrazione; pregresso su passate gestioni).
- Suite completa: 433 test verdi.

---

## [1.10.0-beta.18] - Sforo al Lordo & la Modifica che Smette di Fingere

Rilascio nato da una segnalazione sul forum: un utente aveva registrato tre fatture 2026 sullo stesso capitolo di una gestione straordinaria e l'"Eccesso complessivo" mostrato dalla modale di sforamento non tornava con i suoi conti. Aveva ragione. Il difetto è del tutto lato form, ed era presente da beta.11.

Il tema è quello della beta.17 portato dove era rimasto scoperto: **un numero mostrato all'utente deve essere lo stesso che finisce in contabilità, e un'interfaccia che chiede qualcosa deve poi salvarla.**

### Corretto — il calcolo dello sforo

- **`budgetImpacts` confrontava basi disomogenee.** In `FatturaRegisterNew.vue` e `FatturaRegisterEdit.vue` la spesa della fattura in corso era calcolata sul solo `importo_imponibile`, e confrontata con `conti[].residuo_budget` che `FatturaPassivaController::prepareContestoBudget()` calcola invece come budget approvato meno la **somma lorda** (`SUM(importo_imponibile + importo_iva)`) delle fatture già registrate su quel conto. Netto contro lordo-già-decurtato: sottostima sistematica pari all'IVA della fattura corrente, che si amplifica con più fatture consecutive sullo stesso capitolo.
- **Non era solo visualizzazione.** `handleOverrideConfirm` invia `importo_sforo: sforoBudgetTotaleCents` e `FatturaPassivaService` lo usa **verbatim**, senza ricalcolo lato server, sia per il record `fattura_coperture` sia per le due righe di giroconto dal fondo di riserva. Un eccesso sottostimato per IVA si propagava fino al movimento contabile.
- **Falso negativo.** Con residuo compreso fra l'imponibile e il lordo, `isOk` risultava vero: la modale non si apriva affatto e la fattura veniva registrata oltre budget senza override né copertura.
- **Prova che era un'omissione e non una scelta.** Nello stesso file, il percorso "spesa imprevista" (`handleSpesaImprevistaConfirm`) calcolava già lo sforo includendo l'IVA (`imponibile_sopravvenienza + iva_sopravvenienza`).
- Introdotto **`resources/js/lib/gestionale/fatture/budget.ts`** con `lordoRigaCents()`, che ricalca l'arrotondamento **per riga** del service PHP (`round($impRiga * $aliq / 100)`) invece di quello in euro accumulato di `totali` — così il confronto col residuo usa la stessa base del database. L'arrotondamento dei negativi è allineato a PHP (`round()` arrotonda .5 lontano da zero, `Math.round` verso +∞): rilevante sulle note di credito.
- L'IIFE dentro il `v-if` del badge "Sforo budget" è diventato un metodo **`rigaInSforo(riga)`**. Documentata nel codice la divergenza deliberata rispetto a `budgetImpacts`, che aggrega per capitolo: due righe sullo stesso conto possono sforare insieme senza che nessuna sfori da sola.

### Modificato — comportamenti che cambiano

- **Rimosso da `FatturaRegisterEdit.vue` l'intero flusso di override budget** (`ModalOverrideBudget`, `showOverrideModal`, `handleOverrideConfirm`). Motivo: `UpdateFatturaRequest::rules()` non accetta `dati_extra` — è deliberato e documentato nel suo docblock — quindi `$request->validated()` scartava l'override, e `FatturaPassivaService::aggiornaFattura()` ricostruisce `dati_extra` dal record esistente. L'utente compilava motivazione (minimo 10 caratteri), strategia di rientro e fondo, e **non veniva persistito nulla**: né copertura, né giroconto, né passaggio a `sforo_motivato`. Al suo posto un banner persistente che dichiara il divieto prima del clic e indica la strada corretta (storno + nuova registrazione). Il salvataggio non cambia. Coerentemente, il pulsante non dice più "Autorizza e Registra" ma "Salva Modifiche", e la guida "Audit Trail" in testa alla pagina è stata riscritta: diceva l'esatto opposto del banner, a venticinque righe di distanza.
- **Rimosso da `FatturaRegisterEdit.vue` il toggle "Fuori Preventivo".** Impostava `is_sopravvenienza = true` azzerando `conto_id`, ma `UpdateFatturaRequest` respinge le sopravvenienze in modifica con un 422 su `righe.*.is_sopravvenienza` che quel template non renderizza (mostra solo gli errori su `descrizione`) e che `onError` non intercetta (gestisce solo `modifica_vietata`). Il salvataggio falliva in silenzio. Il campo era già forzato a `false` all'inizializzazione del form, con tanto di commento: la UI contraddiceva il proprio modello.
- Rimosso il flusso spesa imprevista residuo in Edit (`ModalSpesaImprevista` — che era usato **senza import**, terzo caso dopo i due chiusi in beta.17 — più `handleSpesaImprevistaConfirm`, `showSpesaImprevistaModal`, `spesaImprevistaMode`, `totaleCopertoPregressoEuro`, `eccedenzaPregressaEuro`), oltre a `hasSpesePrivate`, `totaleDocLordoEuro` e alle interface morte `DebitoPatrimoniale` e `FondoRiserva`. Erano irraggiungibili: `showSpesaImprevistaModal` non veniva mai messo a `true`. In tutto **−152 righe**, senza toccare un solo comportamento vivo.

### Test

- Nuovo test in `tests/Feature/Gestionale/FatturaPassivaControllerTest.php`: tre fatture consecutive sullo stesso capitolo con aliquote 22/10/4 %, verifica che `residuo_budget` lato server valga 239.800 e non 270.000. Isola il difetto come esclusivamente frontend e ancora l'invariante su cui la correzione si appoggia. Verificato con mutation test: riportando la query a sommare il solo imponibile, il test fallisce.
- Suite `tests/Feature/Gestionale/`: 254 test passati.

### Note

- **Nessuna migrazione.** La struttura del database non cambia.
- Le coperture da fondo di riserva già registrate con l'importo sottostimato **non vengono corrette automaticamente**: vanno verificate a mano. Lo scarto è pari all'IVA della fattura che ha generato lo sforamento.

---

## [1.10.0-beta.17] - Avvisi che Restano & Divieti Dichiarati Prima del Clic

Rilascio nato dal collaudo a video di beta.16. Verificando una per una le guardie contabili appena introdotte, è emerso che **funzionavano ma non si vedevano**: ogni rifiuto veniva comunicato per tre secondi e poi svaniva. Il difetto non era nelle guardie ma nel componente che mostra gli avvisi, ed era presente da prima — riguardava tutti i circa 150 punti dell'applicazione che usano il canale dei messaggi flash.

Il tema del rilascio è lo stesso della beta.16, portato un passo più in là: **un divieto va dichiarato prima del clic, e la sua spiegazione deve restare a schermo finché non è stata letta.**

### Modificato — comportamenti che cambiano

- **Gli avvisi di errore e di attenzione non si autochiudono più** (`Alert.vue`). Il componente impostava un `setTimeout` di 3 secondi indistintamente per ogni tipo di messaggio. Ora l'autochiusura vale solo per `success` e `info` (a 6 secondi) e tutti gli avvisi hanno un pulsante di chiusura esplicito. Rimossa inoltre la mutazione `usePage().props.flash = { message: "" }` eseguita alla chiusura: scriveva sulle props globali condivise da tutte le pagine, lasciando il canale sporco per i messaggi successivi.
- **La voce "Storna" scompare dalle fatture con pagamenti registrati** e viene sostituita da una voce disattivata con il motivo nel tooltip, distinto fra fattura *pagata* e *parziale*. Prima la voce era attiva su qualunque fattura non ancora stornata: il rifiuto arrivava dopo aver aperto la modale di conferma, che nel frattempo aveva descritto un'operazione che non sarebbe mai avvenuta.
- **`StornoFatturaController` risponde con `withErrors` anziché con il flash** per tutte e tre le guardie (già stornata, nota di credito, pagamenti registrati). Il canale degli errori di validazione apre una modale non ignorabile, trattamento appropriato per un'operazione contabile bloccata; il flash resta per gli avvisi che non richiedono una decisione.
- **Rimossa l'azione "Elimina" dal menu degli esercizi contabili.** Il muro contabile lato server introdotto in beta.16 resta come difesa in profondità, ma `scritture_contabili.esercizio_id` è `cascadeOnDelete`: il comando offriva un'operazione irreversibile sul contenitore dell'intero giornale di un periodo. La voce "Modifica" resta ed è deliberato — il campo `stato` vive lì, quindi è da lì che si chiude un esercizio; toglierla bloccherebbe il passaggio di anno contabile. Un'azione dedicata "Chiudi esercizio" è rimandata alla progettazione del ciclo di chiusura/apertura.

### Corretto

- **IVA a 0 salvata come 22%** (segnalazione dal forum su beta.14, difetto presente fino a beta.16 inclusa). In `FatturaRegisterNew` e `FatturaRegisterEdit` il payload inviato al server costruiva l'aliquota con `Number(r.aliquota_iva) || 22`: in JavaScript `0 || 22` vale `22`, quindi lo zero — un'aliquota perfettamente legittima — veniva scambiato per "campo non compilato" e sostituito con il valore ordinario. L'anteprima a schermo usa `|| 0` (righe 195 e 1002) ed era corretta: da qui il sintomo riferito dall'utente, *"l'importo giusto durante l'inserimento, con l'IVA dopo il salvataggio"*. Sostituito con `Number.isFinite(...) ? ... : 22`. Il servizio `FatturaPassivaService` è sempre stato corretto (`(float) $rigaInput['aliquota_iva']`, nessun default): il difetto era interamente nel form. Impatta ogni spesa senza IVA — commissioni bancarie e postali, professionisti in regime forfetario, scontrini — che nel ciclo passivo condominiale sono ordinarie.
- **La pagina Regolazione immediata restava bianca.** `RegolazioneImmediataController::create` non passava la prop `esercizio` (singolare): `GestionaleHeader` la usa per costruire i link a Gestioni, Piani conti e Piani rate, quindi il suo `setup()` sollevava `TypeError: esercizio.value is undefined` e l'intera barra di navigazione spariva. Con Vite in modalità sviluppo l'errore si propagava fino a impedire il rendering della pagina. Difetto introdotto insieme alla feature in beta.16 e mascherato in produzione, dove si manifestava solo come barra mancante. `GestionaleHeader` è stato reso tollerante (`esercizio.value?.id`): una prop dimenticata costerà al più qualche collegamento, non l'usabilità della pagina.
- La pagina **Regolazione immediata** non includeva `MovimentiLayout`: era l'unica della sezione priva della barra dei Movimenti.
- **Componenti usati e non importati**: `PageHeaderGuide` usava `DropdownMenuLabel` e `DropdownMenuSeparator` nel template senza dichiararli fra gli import, e `buildings/DataTableColumnHeader` faceva lo stesso con `DropdownMenuSeparator`. Vue emetteva un `Failed to resolve component` e non renderizzava quegli elementi. Nessun malfunzionamento visibile, ma il rumore in console maschera gli errori veri — è esattamente ciò che ha reso più lenta la diagnosi della pagina bianca qui sopra. Una scansione dell'intero `resources/js` conferma che erano gli unici due casi.
- Nel registro voci di spesa (`FatturaRegisterNew`, `FatturaRegisterEdit`) l'etichetta **"Totale riga"** andava a capo su due righe nella colonna stretta. Riequilibrata la griglia: causale `lg:col-span-5` → `4`, totale `2` → `3`, con `whitespace-nowrap tabular-nums` sul valore.

### Interfaccia — menu a tendina del registro voci di spesa

Segnalazione dell'utente sulla pagina di registrazione fattura: i menu della colonna di destra erano visibilmente diversi da quelli della colonna di sinistra, e un capitolo lungo sfondava il bordo del campo.

- **Allineamento fra le due colonne.** Alla baseline solo i `v-select` delle righe portavano la classe `style-chooser`, che li stilava con `border-radius: 0.75rem; min-height: 40px`; quelli della colonna di sinistra non avevano alcuna classe e ricevevano quindi il `vue-select` di default (~35 px, raggio 4 px). La correzione è la **rimozione di quella regola** in `FatturaRegisterNew` e `FatturaRegisterEdit`: le due colonne coincidono senza aggiungere nulla.
- **Troncamento delle etichette lunghe.** `.vs__selected-options` è un figlio flex che cresce con il contenuto: un capitolo del tipo *"Imprevisto - Mario Rossi Impianti s.r.l – Integrazioni Straordinarie (Scudo Legale)"* (91 caratteri) finiva sotto le icone `×` e `⌄`. La causa tecnica è l'assenza di `min-width: 0`, senza cui un figlio flex non può rimpicciolirsi sotto la propria larghezza intrinseca e quindi `text-overflow: ellipsis` non ha alcun effetto. Aggiunti `min-width: 0` + `overflow: hidden` sul contenitore e `flex: 0 0 auto` su `.vs__actions`. Nessuna metrica toccata: altezza, raggio e spaziature restano quelle di default.
- **Blocco `<style scoped>`, non globale.** `.style-chooser` è usata anche da altre pagine e un blocco `<style>` non incapsulato viene iniettato globalmente non appena il componente è importato: le regole sono quindi in un blocco `scoped` con `:deep()`, e non escono da queste due pagine.

#### Nota di processo — un'unificazione globale tentata e ritirata

Una prima stesura aveva centralizzato la resa di `vue-select` in `resources/css/custom.css` con selettore `.v-select`, per sanare la divergenza fra i **67** file che usano il componente. È stata **ritirata** dopo che l'utente ha notato un peggioramento delle pillole nei campi a selezione multipla. Misure in Chrome headless sui due CSS reali presi da git:

| | prima | dopo |
|---|---|---|
| altezza riquadro | 34,8 px | 40 px |
| altezza pillola | **24,8 px** | **34 px** *(+37%)* |
| cursore rispetto alla pillola | allineato | −4 px |

La causa: `.vs__selected-options` è un contenitore flex con `align-items` al valore di default, e la pillola non ha altezza propria — quindi **si stira** per riempire il contenitore. Imponendo `min-height: 2.5rem` e togliendo il `padding-bottom: 4px` del riquadro, lo spazio interno è passato da 28,8 a 38 px e la pillola è cresciuta con lui; separatamente, `margin: 0` su `.vs__search` ha cancellato il `margin-top: 4px` di default, che era esattamente quello della pillola.

Le **21 pagine a selezione multipla** colpite (utenti, ruoli, inviti, comunicazioni, eventi, documenti, anagrafiche, segnalazioni, piani rate) non avevano alcun override locale e nessun problema da risolvere: erano fuori dal perimetro della richiesta. La regola tenuta è quella minima e circoscritta descritta sopra. La divergenza di stile fra le pagine resta un debito noto, da affrontare semmai come lavoro a sé, con un collaudo dedicato sui campi a selezione multipla.

### Test

- Due test di regressione sull'IVA a zero (`aliquota 0 → nessuna IVA`, `aliquota 10 → IVA calcolata`) verificati con mutation test: reintroducendo un default nel service il primo fallisce.
- `HardeningFase0Test` asseriva il rifiuto dello storno con `assertSessionHas('message.type','error')`, cioè su un canale che l'utente non vedeva mai. Ora asserisce `assertSessionHasErrors('storno_vietato')`, lo stesso che il frontend legge. Suite a **443 test verdi**, 4 saltati.

### Note

- **Nessuna migrazione**: nessuna alterazione di schema o dati.
- Collaudo a video sulla pagina fattura con dati reali: capitolo di 91 caratteri troncato con ellissi, testo che resta 19 px dentro il bordo, icone `×` e `⌄` mai coperte, riquadro a 35 px identico a quello della colonna di sinistra.
- Collaudo a video eseguito su dati reali per: storno di fattura pagata, secondo esercizio aperto, eliminazione ultimo esercizio, sforo differenziale in modifica (residuo esatto e sforo reale ancora rilevato), registrazione e storno di una regolazione immediata con effetto netto zero sul capitolo.

---

## [1.10.0-beta.16] - Regolazione Immediata, Guardie del Libro Giornale & Tenancy

Rilascio nato da un caso reale segnalato sul forum: la registrazione di un'**imposta di bollo da 16,68 €** aveva prodotto sei documenti contabili, un fornitore fittizio "Banca", un falso sforamento di budget e infine un errore 500. Analizzando quel percorso sono emersi difetti latenti nel ciclo passivo e nel presidio del libro giornale, alcuni presenti da diverse versioni e mai visibili all'utente.

Il rilascio introduce la **registrazione a regolazione immediata** (prima nota diretta costo → banca, senza partita fornitore), chiude le falle che permettevano di aggirare o corrompere il giornale, e corregge uno sbilancio contabile silenzioso sugli incassi con utilizzo del credito.

### Aggiunto

- **Registrazione a regolazione immediata** (`Movimenti → Regolazione immediata`): una sola `ScritturaContabile` con due righe (DARE capitolo di spesa / AVERE cassa), nessuna `FatturaPassiva`, nessuna riga nel pivot `fattura_scrittura`, nessuno stato di pagamento da tracciare. Destinata ai fatti che nascono e si estinguono nello stesso momento: bolli, commissioni bancarie, addebiti automatici, piccole spese. La riga DARE porta `voce_spesa_id`, quindi il movimento **entra regolarmente in budget e riparto** come una riga di fattura. Nuovo case `TipoMovimentoContabile::REGOLAZIONE_IMMEDIATA` e prefisso protocollo `RIM`. Implementa `docs/registrazione_e_regolazione_immediata.md` (§4.3, §5, §6); nota: la spec citava un enum `RegistrazioneType` che non esiste — il vocabolario reale è `TipoMovimentoContabile`, già persistito.
- **Guard rail per costruzione** (spec §6): fornitore soggetto a ritenuta d'acconto e richiesta di alimentare lo scadenziario sono **vietati** con `RegolazioneImmediataNonAmmessaException`, perché richiedono la struttura del debito. Il blocco è anticipato in UI prima del submit, con il link al percorso corretto. Il fornitore, se indicato, resta un **tag analitico** su `anagrafica_id`: non movimenta Debiti v/Fornitori e non genera scadenze.
- **Storno della regolazione immediata**: scrittura inversa con `scrittura_padre_id`, protocollo `STO`, `voce_spesa_id` propagato (l'effetto netto sul capitolo torna a zero). Resta possibile anche a esercizio chiuso, appoggiando lo storno all'esercizio aperto con la provenienza in causale — stesso paradigma della Variante B1 già adottata per lo storno dei pagamenti. Era l'unico produttore di scritture privo di una via d'uscita, in violazione della regola cardine "storno sempre ammesso".
- **UI**: form compilabile interamente da tastiera (autofocus sull'importo, `Tab` fra i campi, `Invio` registra, `Esc` esce con conferma se il form è sporco), anteprima in tempo reale della scrittura in partita doppia, esclusione delle casse di tipo `fondo` (sono partizioni virtuali del conto corrente reale, non sorgenti di pagamento) e dei capitoli privi di ancoraggio in partita doppia.

### Modificato — comportamenti che cambiano

Queste modifiche **negano operazioni prima consentite**. Sono intenzionali: ciascuna impediva una corruzione del libro giornale.

- **Eliminazione esercizio**: bloccata se l'esercizio contiene scritture. `scritture_contabili.esercizio_id` è `cascadeOnDelete`, quindi l'eliminazione distruggeva in cascata righe, pivot `quota_scrittura` e `fattura_scrittura`, azzerando per `nullOnDelete` anche `rate_quote.scrittura_contabile_id`: restavano quote con `importo_pagato` materializzato e nessun movimento a giustificarlo. Era il modo di aggirare il sigillo più forte del sistema.
- **Un solo esercizio aperto per condominio**, imposto in `CreateEsercizioRequest`, `UpdateEsercizioRequest` **e** `CondominioService::createEsercizioForCondominio` (percorso non-HTTP: installer, seeder, creazione condominio). Con due esercizi aperti `HasEsercizio::getEsercizioCorrente()` fa `->first()` senza ordinamento e l'esercizio corrente diventa nondeterministico, con ricadute sullo storno cross-esercizio. Il vincolo scatta **solo sulla transizione** chiuso → aperto: un esercizio già aperto resta rinominabile anche su archivi che ne contengono due (ripristino di backup anteriori).
- **Storno di fattura con pagamenti registrati**: negato. Prima la nota di credito veniva creata anche su fattura pagata, lasciando un'uscita di cassa senza debito corrispondente e — dopo l'eliminazione della NC — una fattura `aperta` con pagamenti ancora allocati.
- **Note di credito da storno non compensabili**: `trovaNoteCreditoCompensabili()` esclude ora le NC con `dati_extra->nota_storno`. Non sono documenti emessi dal fornitore ed esistono solo per azzerare la fattura che stornano; consumarne una parte altrove lasciava lo storno incompleto e registrava l'estinzione di un debito senza contropartita di cassa. Le NC autentiche restano compensabili.
- **`data_pagamento` non successiva a oggi** in `Store`/`UpdatePagamentoFornitoreRequest` e negli incassi: il service la copia in `data_registrazione`, quindi una data futura produce una scrittura di giornale datata nel futuro. Il confronto usa il **fuso dell'utente** (`config('app.user_timezone')`, default `Europe/Rome`) via `DateHelper::oggiUtente()`, perché con il solo UTC la data odierna veniva respinta nelle ore notturne. Deroga: se la data inviata coincide con quella già salvata il vincolo non scatta, così i record storici con data futura restano modificabili.

### Corretto

- **Sbilancio silenzioso negli incassi con utilizzo del credito** (`StoreIncassoRateAction`): quando contanti e credito superavano il debito della rata, l'eccedenza — finanziata dal credito — veniva registrata in AVERE sulla scrittura di **cassa**, che in DARE porta i soli contanti. Il giornale usciva sbilanciato di `min(eccedenza, credito)` per **qualunque** combinazione di credito e eccedenza non nulli, senza che nulla lo segnalasse. Ora l'eccedenza è ripartita fra le due scritture e il credito prelevato ma non utilizzato viene **riaccreditato** sulle quote di provenienza: il condòmino consuma solo la parte realmente servita.
- **Resurrezione della fattura stornata** (`FatturaPassivaController::destroy`): eliminando la nota di credito di uno storno lo stato dell'originale veniva forzato ad `APERTA` senza consultare i pivot, producendo fatture "aperte" con pagamenti vivi. Ora il congelamento viene sciolto su **entrambe** le fonti di verità (`stato_pagamento` e `dati_extra.is_stornata`) e lo stato è **ricalcolato** dai pagamenti reali.
- **`ricalcolaStatoFattura` non sovrascrive più `STORNATA`**: il read model derivava solo `APERTA`/`PARZIALE`/`PAGATA` e poteva riportare ad `APERTA` una fattura stornata lasciando `dati_extra.is_stornata` a `true`, facendo divergere le due fonti di verità.
- **Falso sforamento di budget in modifica fattura**: `prepareContestoBudget()` sommava tutte le righe dell'esercizio **inclusa la fattura in corso di modifica**, che veniva quindi contata due volte. Il residuo esclude ora la fattura tramite il parametro `$escludiFattura`.
- **Emissione rate con quote tutte a zero o negative**: la testata veniva creata comunque, restando una scrittura **senza righe** (che il `DoubleEntryValidator` approva, 0 = 0) e bruciando un numero di protocollo. Nessuna quota riceveva `scrittura_contabile_id`, quindi il guard anti-doppia-emissione non scattava e ogni ri-emissione ne accumulava un'altra.
- **Prefisso protocollo `pagamento_f24`**: cadeva nel `default => 'SCR'` di `HasProtocolNumber`, assegnando a un versamento F24 un protocollo di scrittura generica.

### Sicurezza

- **Controllo di appartenenza al condominio** esteso a tutti gli identificativi accettati dai movimenti, prima validati con un `exists` privo di vincolo di tenancy mentre il codice a valle li risolve con `find()`/`findOrFail()` senza verifiche: `esercizio_id` (fatture e pagamenti, con controllo aggiuntivo dello stato `aperto`), `righe.*.conto_id` e `righe.*.immobile_id`, `conto_corrente_id` (che non aveva **nemmeno** un `exists`), `allocazioni.*.fattura_id`, `cassa_id`, `gestione_id`, `pagante_id`, `dettaglio_pagamenti.*.rata_id` e `parent_id` nel piano dei conti. Le rotte annidate non usano `scopeBindings`: aggiunta verifica esplicita di appartenenza in `EsercizioController::destroy` e lettura del condominio dall'esercizio stesso in `UpdateEsercizioRequest`.
- **`DoubleEntryValidator` esteso a tutti i produttori di scritture**: era invocato solo dal ciclo passivo, mentre `StoreIncassoRateAction`, `StornoIncassoRateAction` ed `EmissioneRateController` scrivevano nel giornale senza alcuna verifica di quadratura.

### Test

- **+32 test**, suite a 398 verdi. Nuovi file: `RegolazioneImmediataTest` (15), `HardeningFase0Test` (14), `TenancyScopingTest` (5); più un test di riproduzione dello sbilancio credito+eccedenza in `IncassoRateTest`.
- Ogni guardia è stata verificata con **mutation test**: rimosso il fix di produzione, il test corrispondente deve fallire. La procedura ha smascherato tre test inizialmente tautologici (l'eliminazione esercizio passava grazie alla FK `restrictOnDelete` di `fatture_passive`, non alla nuova guardia; la guardia `STORNATA` era testata impostando entrambi i rami dell'OR insieme; il controllo su `CondominioService` non veniva mai raggiunto perché il metodo usciva prima), poi riscritti in forma discriminante.

### Note

- **Nessuna migrazione**: nessuna alterazione di schema o dati. Il nuovo case dell'enum `TipoMovimentoContabile` non richiede DDL perché `scritture_contabili.tipo_movimento` è `VARCHAR(50)` dalla migrazione `2026_05_20_062512`.
- Nuova chiave di configurazione `app.user_timezone` (default `Europe/Rome`, sovrascrivibile con `APP_USER_TIMEZONE`): riguarda **solo** la validazione delle date digitate a mano. I timestamp restano in UTC.
- Non incluso e ancora aperto: il case `STORNATA` di `StatoPagamentoFattura` è assente sul branch `main`, dove `StornoFatturaController` scrive comunque `'stornata'` — la 1.9.1 in produzione risponde 500 alla lettura successiva di una fattura stornata.

### Piano dei conti — capitolo padre e auto-riferimento

Correzione di un bug nel piano dei conti segnalato da un utente: un **sotto-conto lasciato a importo 0** veniva proposto come **capitolo padre** nell'elenco delle voci selezionabili, e una voce poteva essere impostata come **figlia di sé stessa**. Il difetto si vedeva sia nel modale "Nuova voce di spesa o capitolo" sia in "Modifica voce di spesa".

#### Corretto

- **Un sotto-conto a €0 veniva scambiato per un capitolo:** `FetchCapitoliContiController` selezionava i possibili capitoli padre con il solo criterio `where('importo', 0)`. Poiché anche un sotto-conto lasciato a zero soddisfa quella condizione, finiva nell'elenco dei padri. L'appartenenza a un livello dell'albero è però una proprietà **strutturale**, non di importo: la query filtra ora su `whereNull('parent_id')` (voce di primo livello), mantenendo `importo = 0` per continuare a proporre solo capitoli vuoti. Nota: il difetto si manifestava unicamente con sotto-conti **reali** lasciati a zero — quelli tecnici (`is_tecnico`, sopravvenienze generate on-the-fly) erano già esclusi dallo scope `visibili()`.
- **Una voce poteva diventare figlia di sé stessa:** l'elenco dei capitoli padre non escludeva la voce in corso di modifica, che compariva quindi tra i propri possibili padri. L'endpoint accetta ora un parametro opzionale `conto_id` e scarta quel conto **e tutti i suoi discendenti** (`getAllChildrenIds()`), prevenendo di conseguenza anche i cicli nell'albero; `ModalModificaConto` lo trasmette tramite il composable `useCapitoliConti`.
- **Validazione lato server (difesa in profondità):** `CreateContoRequest` e `UpdateContoRequest` rifiutano ora un `parent_id` che punti a un sotto-conto (il padre deve essere una voce di primo livello); `UpdateContoRequest` blocca inoltre il padre che coincida con un discendente del conto in modifica (ciclo). Serviva un presidio esplicito perché la regola `Rule::notIn` che impediva l'auto-riferimento veniva **sovrascritta** dal ramo `isSottoConto` in `rules()`, restando di fatto inattiva.

#### Test

- 4 nuovi test in `tests/Feature/Gestionale/ContoControllerTest.php`: il sotto-conto a zero non compare tra i capitoli padre; la voce in modifica è esclusa dalla propria lista; l'update rifiuta un sotto-conto come padre; l'update rifiuta l'auto-riferimento. I primi tre **falliscono contro il codice pre-fix**, a conferma che il difetto era reale e ora è bloccato.

#### Note

- Modifica di **sola logica applicativa e di interfaccia** (controller di lettura, form request, composable e modale): **nessuna migrazione**, nessuna alterazione di schema o dati del database.

---

## [1.10.0-beta.15] - Cruscotto Sforamenti, Ciclo Straordinario (Piani Rate) & Stampe PDF

Correzione di un bug di **sola visualizzazione** nel cruscotto (Dashboard): l'indicatore di *sforamento di budget* di un capitolo (`orfano.is_sforo`) e l'allarme globale `has_sforo` risultavano **sempre spenti**, anche quando la spesa reale su un capitolo superava il preventivo e il deficit non era ancora coperto da alcun piano rate. Nessun dato contabile era errato — l'importo del deficit, la percentuale di copertura e la strategia di rientro erano già corretti — ma l'amministratore non riceveva l'avviso visivo che quella spesa era fuori budget. Emerso durante una review a partire dal caso della ratifica di uno sforo motivato (Art. 1135 c.c.): l'indicatore sembrava "spegnersi" dopo la ratifica, mentre in realtà non si era **mai** acceso.

### Corretto

- **Indicatore di sforamento sempre falso nel cruscotto:** `BudgetCoverageService::analyze()` espone in `item['budget']` il *fabbisogno reale* del capitolo, cioè `max(budget preventivato, speso reale)`. La Dashboard calcolava lo sforamento come `speso > item['budget']`, che equivale a `speso > max(preventivo, speso)`: una condizione **sempre falsa** per costruzione. Di conseguenza `orfano.is_sforo` era sempre `false`. Lo stesso difetto azzerava l'allarme globale `has_sforo`: il totale `$totBudgetPuro` veniva accumulato usando lo stesso `item['budget']` (fabbisogno reale) impiegato per `$totPrev`, rendendo il confronto `$totPrev > $totBudgetPuro` sempre falso. Il servizio ora espone anche `budget_teorico` (il preventivo puro, senza il `max`) e il cruscotto confronta la spesa reale contro quel valore, sia per il flag per-capitolo `is_sforo` sia per il totale `has_sforo`. Il fix tocca **solo** questi due indicatori: importo del deficit, percentuale di copertura, `delta`, `scoperto` e la strategia di rientro erano già calcolati correttamente e restano invariati.

### Test

- Nuova suite `tests/Feature/Gestionale/DashboardSforoIndicatorTest.php` (2 test): guardia di regressione anti-reintroduzione. Registra una spesa comune reale di 1.500€ su un capitolo con budget di 1.000€ non coperta da piani rate e verifica che il cruscotto accenda `is_sforo` e `has_sforo` **prima** della ratifica, e che `is_sforo` resti acceso **dopo** la ratifica assembleare (con `strategia` che decade a `nessuna`). Entrambi i test falliscono contro il codice pre-fix, a conferma che il difetto era reale e ora è bloccato.

### Note

- Modifica di **solo codice applicativo** (`BudgetCoverageService`, `DashboardController`): **nessuna migrazione**, nessuna alterazione di schema o dati del database.

### Corretto — Ciclo Straordinario (Piani Rate)

- **Piano rate straordinario con fatture pregresse — nessuna quota / errore fuorviante:** Un piano straordinario composto (anche solo in parte) da **fatture pregresse** non produceva quote. Le fatture pregresse non hanno `righe_fattura`: il loro importo straordinario è registrato come copertura di tipo `sopravvenienza` in `fattura_coperture`, agganciata a un capitolo dinamico con tabella millesimale. Il motore `CalcoloQuoteService::calcolaDaFattureStraordinarie` leggeva però **solo** `righe_fattura`, restituendo zero quote e facendo scattare in `GeneratePianoRateAction` una `RuntimeException` fuorviante ("nessuna quota calcolata — verificare millesimi/anagrafiche"), pur avendo il carrello (`FetchFattureStraordinarieController`, Query 1b) offerto volutamente quelle fatture. Il calcolo ora ripartisce anche le coperture `sopravvenienza` delle pregresse sul capitolo collegato.
- **Allineamento Calcolo/Carrello per le Fatture Correnti (righe ordinarie):** Per le fatture correnti il motore iterava **tutte** le `righe_fattura`, mentre il carrello e la marcatura `is_rateizzata` del `PianoRateController` considerano straordinarie solo le righe `is_sopravvenienza` o ad personam (`immobile_id`). Una fattura mista (righe ordinarie + straordinarie) finita in un piano straordinario rischiava quindi una **sovra-ripartizione**. Il calcolo ora filtra le sole righe imprevisto/ad personam, coerentemente con il carrello.
- **Rispetto della Quota Finanziata (`importo_collegato`):** Il motore distribuiva sempre l'intero importo della fattura ignorando `importo_collegato` (la quota della fattura effettivamente finanziata da quel piano, es. finanziamento parziale o split su più piani). Ora la ripartizione rispetta `importo_collegato` con scaling "penny-perfect"; il finanziamento intero resta **byte-identico** al comportamento precedente e un `importo_collegato` mancante/0 (dati storici) ricade in sicurezza sul totale naturale.
- **Messaggio Guardia Generazione più chiaro:** Il messaggio della guardia in `GeneratePianoRateAction` è ora contestuale al tipo di piano: per i piani straordinari indica le cause reali (importo finanziato, righe imprevisto/ad personam, copertura sopravvenienza per le pregresse) invece del generico riferimento a millesimi/anagrafiche.

### Test — Ciclo Straordinario

- Nuova suite `tests/Feature/Gestionale/PianoRateStraordinarioPregressoTest.php` (8 test): fatture pregresse (ripartizione + generazione senza `RuntimeException`), finanziamento parziale, fallback difensivo su `importo_collegato`=0, riga corrente pura, filtro delle righe ordinarie in fattura mista, addebito ad personam, ed E2E che verifica come le rate effettivamente generate sommino esattamente `importo_collegato`.

### Note — Ciclo Straordinario

- Modifica di **sola logica di calcolo lato lettura** (`CalcoloQuoteService`, `GeneratePianoRateAction`): **nessuna migrazione**, nessuna alterazione di schema o dati del database.

### Migliorato — Stampe PDF

- **Identità dell'unità allineata tra schermo e stampa:** nel prospetto scadenziario "per unità immobiliare" e nel riparto per tabella, la riga dell'unità era identificata dal `codice_immobile` + intestatario anagrafico, mentre la vista a schermo ("Per immobile") guida con `immobile.nome`. Le due viste potevano quindi mostrare identità diverse per la stessa unità (es. nome unità "Bianco Rossi" a schermo, intestatario "Proprietario Sconosciuto" in stampa), generando confusione. Ora anche la stampa guida con `immobile.nome` (fallback al codice), con interno/piano/codice come dettaglio e l'intestatario anagrafico come **riga secondaria** — che resta perché identifica il debitore (valenza legale dello scadenziario, art. 1135 / 63 disp. att. c.c.). File: `PianoRatePrintController::buildMatriceImmobile`, `RipartoTabelleService`, partial `_tabella_scadenziario`, `riparto_tabelle`.
- **Data di emissione nel piè di pagina:** tutte le stampe (`pdf.base`) riportano ora "Documento emesso il …" nel footer, sopra la nota legale, con default alla data di stampa (`now()`) e possibilità di passare una data specifica via la variabile `$data_emissione_stampe`.

### Note — Stampe PDF

- Modifica di **sola presentazione** (controller/servizio di stampa e template Blade): **nessuna migrazione**, nessuna alterazione di schema o dati del database. Il nome dell'intestatario è letto **live** dalla relazione `RataQuote → Anagrafica`: rinominare l'anagrafica aggiorna la stampa senza rigenerare il piano rate.

---

## [1.10.0-beta.14] - Trusted Proxies & Ripristino Backup Robusto

Correzione di un bug **strutturale e subdolo**: la configurazione dei proxy fidati (`TRUSTED_PROXIES`) non ha **mai** avuto effetto quando impostata via `.env`, su nessuna installazione. La causa è un problema di *timing* nel ciclo di vita di Laravel, non una svista di configurazione — individuato partendo da un caso reale di *mixed content* su Altervista e confermato con prove empiriche sul server.

Nella stessa versione, dopo un collaudo del ripristino su hosting reale partendo da un backup **1.10.0-beta.11**, sono stati corretti due bug che ne impedivano il completamento, aggiunto un **recupero guidato** quando un ripristino fallisce, e — grazie a un audit dedicato ai backup più vecchi — chiusi altri casi limite (perdita di file, adozione della chiave di cifratura, coda non ripulita).

### Aggiunto

- **Recupero guidato da un ripristino fallito:** se un ripristino si interrompe (o resta in stallo oltre il timeout), la pagina di attesa non mostra più uno spinner infinito ma una schermata di recupero con un messaggio chiaro, i **dettagli tecnici in un log copiabile** da inviare all'assistenza, e due azioni — *Riprendi il ripristino* (completa dal punto in cui si era interrotto, utile per un errore transitorio o già corretto) e *Sblocca l'applicazione* (esce dalla modalità ripristino). Le azioni sono autenticate **senza sessione** (l'import azzera la tabella `sessions`): con il token del ripristino se la pagina di amministrazione è ancora aperta, oppure con la password del proprio account dalla pagina di blocco. Quest'ultima è ora mostrata in **una sola lingua** (quella dell'amministratore) invece di impilare italiano e inglese.

### Corretto

- **`TRUSTED_PROXIES` letto troppo presto (i proxy fidati non venivano MAI configurati):** il blocco in `bootstrap/app.php` leggeva `env('TRUSTED_PROXIES')` dentro il closure `->withMiddleware()`. Quel closure gira quando lo `HttpKernel` viene *risolto* (`Application::handleRequest → make(Kernel)`), **prima** che il bootstrapper `LoadEnvironmentVariables` carichi il `.env`: in quel punto `env('TRUSTED_PROXIES')` è sempre `null`, quindi `trustProxies()` non veniva mai chiamato e gli header `X-Forwarded-*` restavano ignorati a prescindere dal `.env`. Conseguenze silenziose: `request()->isSecure()` falso dietro proxy (→ **mixed content**, CSS/JS bloccati come contenuto misto su HTTPS) e `request()->ip()` uguale all'IP del proxy anziché del client reale (→ allowlist IP del webhook cron e throttle key di login/2FA basate sull'IP sbagliato). La configurazione è stata spostata in **`config/trustedproxy.php`**, letto dal middleware globale `TrustProxies` a *request-time* (quando il `.env` è caricato) e resistente a `config:cache`. Il blocco rotto in `bootstrap/app.php` è stato rimosso.
- **Forzatura HTTPS degli asset resa affidabile dietro proxy:** il fix `URL::forceScheme('https')` in `AppServiceProvider` gira in `boot()`, anch'esso prima del middleware `TrustProxies`, quindi non può affidarsi a `isSecure()`; ora legge direttamente gli header `X-Forwarded-Proto`/`X-Forwarded-Ssl` per decidere se forzare lo schema. Innocuo su installazioni realmente in HTTP (nessun header → nessuna forzatura → nessun mismatch).
- **Chiave di config morta rimossa:** `config/app.php` non definisce più `trusted_proxies` (non era letta da nessuno; il middleware legge `config('trustedproxy.proxies')`).
- **Saldo di apertura delle casse azzerato se assente dal payload di modifica:** `UpdateCassaAction` riscriveva `saldo_iniziale` con `isset(...) ? MoneyHelper::toCents(...) : 0`, forzandolo a **zero** ogni volta che il campo non era presente nella richiesta di update (es. un salvataggio che non tocca il saldo). Ora, se `saldo_iniziale` è assente dal payload, viene mantenuto il valore corrente della cassa; l'azzeramento avviene solo se il campo è inviato esplicitamente vuoto.
- **Saldo di apertura non più modificabile con movimenti contabili registrati:** cambiare `saldo_iniziale` dopo che la cassa ha righe in `righe_scritture` alterava **retroattivamente** il saldo reale (`Cassa::getSaldoRealeAttribute`) e la verifica di capienza dei pagamenti ai fornitori (`PagamentoFornitoreService::saldoCorrente`, che somma `saldo_iniziale` + delta movimenti). Ora un guard in `UpdateCassaAction` blocca con `ValidationException` la modifica del saldo di apertura quando esistono movimenti, e lato interfaccia (`CasseEdit.vue`) il campo si disabilita con avviso. Contestualmente è stato ripristinato il guard gemello sul cambio `tipo`, che era uno **stub morto** (`$hasMovimenti = false` con TODO) e non bloccava mai nulla nonostante il campo fosse disabilitato solo lato UI (aggirabile via API): entrambi i controlli ora usano `Cassa::movimenti()->exists()`.
- **Ripristino di un backup creato con la 1.10.0-beta.11 che falliva con "Duplicate entry ... `backups_uuid_unique`":** i backup di quelle versioni includevano nel dump anche i DATI della tabella `backups`; al ripristino l'import ricreava quella riga e la ri-registrazione degli archivi tentava un `create()` con lo stesso uuid già presente, violando il vincolo UNIQUE. La ri-registrazione ora usa `updateOrCreate` per uuid, riconciliando la riga invece di duplicarla — i backup beta.11 si ripristinano correttamente.
- **Backup di sicurezza pre-aggiornamento che dava "server error" aggiornando da versioni ≤ beta.11:** il backup gira PRIMA delle migrazioni e il motore scriveva le colonne `type`/`encrypted` della tabella `backups` (introdotte in beta.12) che sullo schema più vecchio non esistono ancora. Ora quelle colonne vengono allineate al volo prima del backup (sola infrastruttura, nessun dato utente toccato).
- **Schermata di ripristino bloccata all'infinito dopo un errore:** la pagina di attesa non leggeva la fase e mostrava "ripristino in corso" ricaricandosi ogni 30 secondi anche a ripristino fallito. Ora è consapevole della fase e mostra il recupero guidato (vedi *Aggiunto*).
- **Voci di backup "fantasma" dopo un ripristino:** ripristinando un backup ≤ beta.11 restavano nell'elenco righe che puntavano ad archivi non più presenti su disco (404 al download/ripristino). La finalizzazione ora rimuove le righe completate prive di file corrispondente.
- **Coda e stato transitorio non ripuliti dopo un ripristino:** il dump di un backup include anche i dati di `jobs`/`failed_jobs`/`password_reset_tokens`; reimportarli avrebbe rieseguito job stantii e lasciato token e lavori falliti vecchi. La finalizzazione ora azzera queste tabelle insieme a sessioni e cache.

### Sicurezza

- **Il default resta "non fidarsi di nessuno":** su VPS con PHP esposto direttamente, senza `TRUSTED_PROXIES` gli header `X-Forwarded-*` sono ignorati e non è possibile falsificare IP o schema. `config/trustedproxy.php` documenta quando usare `*` (hosting condiviso/Cloudflare, dove l'origine è raggiungibile solo tramite il proxy) e quando preferire una **lista IP esplicita** (origine raggiungibile direttamente), avvertendo anche del rischio di aggiramento dei limiti anti-brute-force su login/2FA se si usa `*` in modo improprio.
- **Auto-patch di aggiornamento reso più prudente:** `UpgradePatchServiceProvider` non attiva più l'auto-configurazione `TRUSTED_PROXIES=*` sulla semplice presenza di header `X-Forwarded-*`/`CF-Connecting-IP` (falsificabili dal client): scrive `*` solo su hosting condivisi riconosciuti per nome (dove l'origine non è raggiungibile fuori dal proxy). Prima del fix il ramo era innocuo perché la lettura dei proxy era comunque inerte; rendendola effettiva, sarebbe diventato un possibile innesco di "trust-all" su origini raggiungibili direttamente.
- **Confronto token cron a tempo costante:** la verifica del token del webhook scheduler (`CheckExternalCron`) usa ora `hash_equals()` invece di `!==`, eliminando un possibile timing attack (trascurabile per un UUID a 128 bit, ma è la prassi corretta).
- **Nessuna perdita dei file utente se un ripristino completo si interrompe:** durante la fase di ripristino dei documenti i file correnti vengono spostati (non copiati) in un'area temporanea. Ora il backup di sicurezza pre-ripristino è **completo quando l'archivio è completo** (prima era sempre solo-database e non copriva `storage/app`), e l'azione *Sblocca l'applicazione* rimette al loro posto i file originali dalla temporanea prima di ripulirla, invece di cancellarli.
- **Adozione della chiave di cifratura dal backup resa effettiva:** trasferendo un backup su un'installazione con `APP_KEY` diversa e scegliendo di adottare la chiave dell'archivio, l'encrypter (già istanziato con la vecchia chiave a inizio richiesta) non veniva ricostruito: la sonda di decifratura girava con la chiave sbagliata e azzerava proprio i dati dell'autenticazione a due fattori e la password SMTP che l'adozione doveva preservare. Ora la chiave adottata viene applicata subito anche all'encrypter. (Percorso del futuro wizard di installazione; dal pannello l'adozione resta disattivata.)

### Test

- Nuova suite `tests/Feature/System/TrustedProxiesTest.php` (10 test): guard **strutturali** anti-reintroduzione dell'anti-pattern (il codice non deve tornare a leggere `env('TRUSTED_PROXIES')` in `bootstrap/app.php`) e verifiche **comportamentali** end-to-end del middleware `TrustProxies` (default sicuro, `*`, lista IP, anti-spoofing, schema per-valore, ri-derivazione per richiesta). La suite di test è stata resa **ermetica** fissando `TRUSTED_PROXIES=null` in `phpunit.xml`: senza, il `.env` reale filtrava nei test facendo girare l'intera suite in modalità "fidati di tutti", mascherando il default sicuro.
- **Test flaky reso deterministico:** `CascataRuoloRipartoTest` generava email e codici fiscali con `rand()` su colonne UNIQUE, con collisioni sporadiche nella suite completa; ora usa un contatore progressivo.
- Nuova suite `tests/Feature/Gestionale/UpdateCassaActionTest.php` (6 test): copre il non-azzeramento del saldo di apertura quando il campo è assente dal payload, l'azzeramento esplicito su valore vuoto, l'applicazione del nuovo saldo in assenza di movimenti e i due guard (blocco modifica saldo e blocco cambio `tipo` con movimenti esistenti).
- Copertura del ripristino di un backup ≤ beta.11 (dump con la propria riga `backups` e una riga "fantasma"), del backup pre-aggiornamento su schema privo di `type`/`encrypted`, dell'azzeramento della coda, e del flusso di recupero HTTP (pagina 503 in-corso/di-recupero in una sola lingua, autenticazione di riprendi/annulla con token o password account).

### Interno

- **File di sviluppo esclusi dal pacchetto di distribuzione:** `tests/`, `phpunit.xml` e altri file dev sono marcati `export-ignore` in `.gitattributes` — restano versionati in git ma non finiscono nel pacchetto scaricato dalle installazioni (`git archive`).
- **Documentazione allineata:** `docs/security_cron_scheduler.md` non fa più riferimento alla chiave rimossa `config('app.trusted_proxies')` (ora `config('trustedproxy.proxies')`) e avverte del rischio di usare `*` su origini raggiungibili direttamente.

---

## [1.10.0-beta.13] - Ripristino Guidato dall'Interfaccia

Terzo capitolo del sistema di backup: gli archivi creati in beta.11/12 diventano **ripristinabili con un clic dal pannello**, senza phpMyAdmin, terminale o client MySQL — sugli stessi hosting condivisi per cui è stato pensato il motore di backup. Si chiude il cerchio: *backup* → *ripristino*. In più, una rete di sicurezza per gli aggiornamenti: un **backup automatico del database prima di ogni upgrade**, così un amministratore distratto non resta senza punto di ripristino.

### Aggiunto

- **Ripristino di un backup dall'elenco (pannello amministrazione):** accanto a ogni backup completato, il pulsante *Ripristina* riporta l'installazione allo stato di quell'archivio. Una finestra di conferma mostra data, tipo e dimensione, chiede la password dell'archivio se cifrato, lascia scegliere se creare un **backup di sicurezza** prima di procedere (attivo di default) e richiede la **password del proprio account** (l'operazione è la più distruttiva dell'app). Durante il lavoro l'applicazione entra in *modalità ripristino* con una schermata di avanzamento; al termine tutti gli utenti effettuano di nuovo l'accesso e una pagina di esito riepiloga cosa eventualmente riconfigurare.
- **Motore di ripristino riprendibile (step-runner), speculare al backup:** ogni richiesta HTTP avanza per ~20 secondi e salva un checkpoint, così un ripristino interrotto (pagina chiusa, timeout) riprende da dove era rimasto. Lo stato vive su FILE, non nel database — che l'import sta sovrascrivendo (sessioni, cache e lock inclusi, che in questa applicazione stanno nel DB): per questo gli step si autenticano con un **token monouso**, non con la sessione. Fasi: backup di sicurezza → estrazione → verifica di integrità (SHA-256) → import del database → ripristino dei documenti → finalizzazione.
- **Import SQL in puro PHP con tokenizer dedicato (`SqlDumpTokenizer`):** ri-legge il dump prodotto dal backup statement per statement — gestendo correttamente stringhe con `;`/apici/newline, valori binari esadecimali, viste e trigger — con ripresa all'offset di byte. Nessun `mysql` da riga di comando.
- **Ripristino cross-versione:** un backup di una versione più vecchia viene ripristinato e poi allineato al codice attuale eseguendo automaticamente le migrazioni mancanti del database e riportando la versione a quella corrente (stessa logica di un aggiornamento). Un backup di una versione più **nuova** del codice viene invece rifiutato, invitando ad aggiornare prima.
- **Backup automatico prima dell'aggiornamento:** nella schermata di conferma dell'upgrade, un backup di sicurezza (solo database) del momento pre-migrazione, attivo di default; per disattivarlo serve una conferma esplicita. Il backup finisce nell'elenco normale: se l'aggiornamento va male, si ripristina con lo stesso pannello.
- **Guida in-page aggiornata** con la nuova procedura di ripristino con un clic e la nota che, ripristinando un backup più vecchio, l'allineamento di versione è automatico. Traduzioni complete it/en/es/pt.
- **Percorsi di backup configurabili da ambiente** (`BACKUP_ROOT`, `BACKUP_TMP_PATH`, ecc.): consente di spostare gli archivi su un disco dedicato o isolare istanze di collaudo, con default invariati.

### Sicurezza

- **Modalità ripristino:** finché un ripristino è in corso l'intera applicazione risponde con una pagina statica 503, lasciando passare solo le rotte di ripristino autenticate dal token e il controllo di salute. In caso di fallimento la modalità resta attiva: un'app col database a metà import non torna raggiungibile finché l'amministratore non riprende o esegue il rollback.
- **Archivi non fidati:** l'estrazione valida ogni voce dell'archivio (rifiuta percorsi con `..`, assoluti, symlink e voci fuori dai contenuti attesi di un backup KondoManager) prima di toccare il filesystem, e verifica l'integrità del dump con lo SHA-256 del manifest. La password di un archivio cifrato viene validata subito, prima di avviare qualsiasi operazione.
- **Cambio di chiave di cifratura gestito:** se un backup proviene da un'installazione con `APP_KEY` diversa, una sonda di decifratura in finalizzazione rileva i dati illeggibili (segreti dell'autenticazione a due fattori, password del server email) e li azzera, avvisando nell'esito cosa riconfigurare — evitando il blocco al login degli utenti con 2FA.

### Corretto

- **Bug critico nell'azzeramento dello schema durante l'import:** la cancellazione delle tabelle non disattivava i vincoli di chiave esterna, quindi falliva su qualsiasi database reale con relazioni — problema individuato dal test end-to-end della catena di ripristino cross-versione e risolto disabilitando i vincoli per la durata dell'operazione.
- **Impaginazione della pagina Gestione backups:** la colonna delle impostazioni non viene più stirata all'altezza della colonna con l'elenco (niente più spazio vuoto con il pulsante di salvataggio staccato), e l'intestazione della tabella è ora allineata alle colonne a tutte le larghezze dello schermo.

---

## [1.10.0-beta.12] - Cifratura Backup & Backup Solo Database

Secondo capitolo del sistema di backup introdotto nella beta.11. Due funzioni valutate inizialmente per il futuro plugin a pagamento e deliberatamente rilasciate nel free: la **cifratura AES-256 degli archivi** — un backup contiene tutti i dati del condominio e il file `.env` con le chiavi dell'applicazione, e la protezione di dati che lasciano il server non è un lusso — e il tipo **"solo database"** per copie rapide prima delle operazioni delicate.

### Aggiunto

- **Cifratura AES-256 degli archivi con password salvata unica:** la password di protezione si imposta **una volta sola** nelle impostazioni della pagina backup (con conferma per proteggersi dai refusi, icona occhio per mostrarla, validazione immediata sui campi) e viene riusata da tutti i backup protetti: niente password diverse per ogni archivio, facili da dimenticare. Nel riquadro di creazione resta un solo interruttore, "Proteggi con la password salvata (AES-256)", attivo di default quando una password è impostata e disabilitato con istruzioni quando non lo è. Ogni voce dello zip è cifrata singolarmente (WinZip AES-256, lo standard degli archivi cifrati); l'interfaccia avverte nel momento giusto che una password dimenticata rende il backup irrecuperabile e che gli zip AES si aprono con 7-Zip (Windows) o Keka (Mac), non con Esplora Risorse.
- **Nuovo tipo di backup "solo database":** include il dump completo del database ma non i documenti caricati — molto più rapido e leggero, pensato come rete di sicurezza prima di un'operazione delicata (aggiornamento, import massivo, chiusura d'esercizio). Selezione con due card affiancate ("Completo" / "Solo database") nel riquadro di creazione; il preflight calcola una stima di spazio dedicata, così un disco troppo pieno per un backup completo non blocca più un salvataggio solo-dati; il `manifest.json` dichiara il contenuto (`contents: db_only`) per il futuro ripristino guidato. I backup "solo database" e quelli cifrati sono riconoscibili nell'elenco (etichetta dedicata e icona lucchetto).
- **Impostazioni backup riorganizzate in un'unica scheda:** "Backup da conservare" e "Password di protezione dei backup" vivono ora nella stessa card con un unico pulsante "Salva impostazioni" in fondo — la password è un'impostazione persistente come la retention, e l'interfaccia la tratta come tale. Cambio password inline, rimozione con finestra di conferma.
- **12 nuovi test automatici sul backup (28 totali),** incluso il test-garanzia centrale della cifratura: la password salvata non compare MAI dentro un archivio — né tra le voci dello zip né nei byte grezzi del file — e un backup cifrato interrotto e ripreso resta cifrato AES-256 per ogni singola voce.

### Sicurezza

- **La password di protezione non lascia mai il server:** è custodita cifrata con la `APP_KEY` in un file dentro la cartella backups (auto-esclusa dagli archivi: non può finire dentro un backup), non è mai in chiaro nel database e non viaggia mai verso il browser — il frontend riceve solo il booleano "impostata / non impostata". L'unico modo di ottenerla è l'accesso diretto al file system del server.
- **La tabella `backups` entra nei dump MySQL/MariaDB solo come struttura, senza dati:** i checkpoint dei backup in corso contengono la password di cifratura della sessione (a sua volta cifrata con `APP_KEY`) e righe che dopo un ripristino punterebbero ad archivi inesistenti. Il checkpoint viene inoltre azzerato in tutti i percorsi terminali (completato, fallito, stantio, eliminato). Su SQLite (usato in sviluppo) il database viene invece copiato integralmente: l'eventuale checkpoint del backup in corso vi resta, ma la copia di sessione è cifrata con `APP_KEY` e l'archivio che la contiene è a sua volta cifrato con la password stessa.
- **Flag di protezione esplicito lato server:** la richiesta di creazione dichiara separatamente "voglio la cifratura" e il server rifiuta di procedere se manca la password salvata — impossibile ottenere per errore un backup non cifrato quando si è chiesta la protezione. Verifica preventiva che libzip supporti la cifratura, con messaggio chiaro in caso contrario.

### Corretto

- **Test intermittenti con l'esecuzione parallela della suite:** i processi paralleli dei test condividevano il file reale della password e la cartella reale dei temporanei, cancellandoseli a vicenda — e le pulizie di fine test potevano cancellare perfino la password di backup reale dell'installazione su cui giravano. I percorsi sono ora configurabili (`config/backup.php`) e ogni processo di test usa percorsi propri e isolati sotto `storage/framework/testing/`.
- **Irrobustimenti da revisione del codice:** eliminazione di un backup bloccata finché lo step in corso non rilascia il lock (niente file orfani), avvio protetto da lock contro il doppio click, file temporanei del dump rimossi solo dopo la persistenza del checkpoint, colonne generate e viste gestite correttamente anche negli archivi cifrati.
- **Fallimento Silenzioso nella Creazione di Commenti su Segnalazioni:** Risolto un bug per cui la pubblicazione di un commento su una segnalazione pubblica e non assegnata falliva sempre senza errori visibili all'utente. La causa era una chiamata a un metodo inesistente (`$user->condomini()`) nel calcolo dei destinatari delle notifiche, che generava un'eccezione fatale intercettata dal blocco `catch` generico del controller: la transazione veniva annullata (nessun commento salvato) ma l'utente vedeva comunque un redirect apparentemente riuscito. Corretta la risoluzione della relazione tramite `$user->anagrafica->condomini()`.

---

## [1.10.0-beta.11] - Backup Manuali & Trasferimento Installazione

Prima versione del sistema di backup integrato, la funzione "In arrivo" della pagina impostazioni. Obiettivo: un archivio **completo e autosufficiente** (database + documenti + configurazione) creato dall'interfaccia, sufficiente da solo per trasferire o ripristinare un'installazione — e che funzioni sugli hosting condivisi che sono il pubblico primario di KondoManager, dove `mysqldump`, `proc_open` e i cron affidabili spesso non esistono.

### Aggiunto

- **Nuova pagina Impostazioni → "Gestione backups":** creazione del backup con barra di avanzamento in tempo reale, elenco dei backup con stato, data, dimensione e impronta SHA-256 (con copia rapida per verificare l'integrità delle copie trasferite), download, eliminazione con conferma e annullamento di un backup in corso. Card "Requisiti di sistema" (preflight) che verifica estensione ZIP, driver database supportato, cartella scrivibile e spazio su disco con stima della dimensione prima di consentire l'avvio. Retention automatica configurabile (mantieni gli ultimi N, default 5). Traduzioni complete it/en/es/pt.
- **Motore a passi riprendibili (step-runner), scelta architetturale deliberata:** scartato `spatie/laravel-backup` dopo ricerca documentata — richiede sempre il binario `mysqldump` + `proc_open` (assenti o disabilitati sugli hosting condivisi tipici), non include alcuna funzione di restore ed esegue in un'unica richiesta monolitica non riprendibile. Il motore interno (zero nuove dipendenze Composer) lavora invece come Duplicator/UpdraftPlus: ogni richiesta HTTP avanza per ~20 secondi (configurabile in `config/backup.php`), salva un checkpoint nella tabella `backups` e il frontend richiede lo step successivo. Un backup interrotto (pagina chiusa, timeout del server) riprende esattamente da dove era rimasto; un lock in cache impedisce esecuzioni sovrapposte; i backup fermi da oltre 2 ore vengono marcati falliti e i temporanei ripuliti.
- **Dump MySQL in puro PHP/PDO (`MySqlDumper`):** nessun binario esterno. SQL prodotto per la massima portabilità: statement `INSERT` sotto 1 MB reimportabili da phpMyAdmin, nessun `DEFINER` (l'import non richiede privilegi SUPER), valori binari in esadecimale, viste ricreabili in qualsiasi ordine (tecnica degli stub di mysqldump), trigger inclusi, colonne generate escluse dall'INSERT e ricalcolate all'import, sessione in UTC per i TIMESTAMP. Paginazione keyset sulla chiave primaria con fallback a OFFSET per tabelle senza PK singola. SQLite gestito con `VACUUM INTO`. Postgres/SQL Server rifiutati esplicitamente dal preflight.
- **`manifest.json` versionato in ogni archivio** (formato 1): versione dell'applicazione, elenco delle migrazioni eseguite, conteggi tabelle/righe/file, checksum SHA-256 del dump, eventuali warning. È la "carta d'identità" del backup su cui si baserà il futuro **ripristino guidato dall'interfaccia** (validazione di compatibilità e integrità prima di toccare i dati) senza cambiare formato. Copiato anche nella colonna `manifest` per mostrare i dettagli in UI senza aprire lo zip.
- **Guida in-page "Backup e ripristino"** (pattern delle guide Cron/Stampe): tre schede — contenuto dell'archivio (con spiegazione del manifest e avviso di non modificarlo), procedura di ripristino/trasferimento passo-passo con l'avvertenza critica di **non rigenerare mai la APP_KEY**, buone pratiche di custodia (copia fuori dal server, verifica SHA-256, momento giusto per il backup a caldo).
- **Punti di estensione per il futuro plugin backup** (scheduling e destinazioni cloud): contratti `DatabaseDumperInterface` e `BackupDestination`, registro `DestinationManager` estendibile dal container, eventi `BackupStarted/Completed/Failed/Deleted` emessi dal core senza listener nel free. Il comando artisan di backup è deliberatamente assente dal free. Lo step-runner riprendibile renderà possibile il backup programmato anche nella finestra ~55s del webhook cron sugli hosting condivisi.
- **16 nuovi test automatici**, incluso il gate di rilascio: round-trip dump → import su MySQL reale con confronto dato-per-dato (stringhe con apici/emoji/newline, blob binari, PK composte, tabelle senza PK, colonne generate, viste, trigger, 2500 righe con checkpoint serializzati in JSON tra gli step) e round-trip dell'intero database di sviluppo con conteggi identici; più step-runner (ripresa, retention, lock, stantii, eliminazione), selettore file (esclusioni, symlink non seguiti, dotfile inclusi) e flusso HTTP completo (permessi, store→step→download, 409 su doppio avvio, validazione retention).
- **File di sistema esclusi automaticamente** dagli archivi: `.DS_Store`, `Thumbs.db`, `desktop.ini`.

### Sicurezza

- Gli archivi vivono in `storage/app/backups`, **fuori dalla document root** e su un disco dedicato con `serve => false`: non sono raggiungibili via web in alcun modo. Il download passa solo dalla rotta autenticata protetta dal permesso "Gestisci impostazioni generali", con URL basati su uuid non enumerabili. La cartella dei backup è auto-esclusa dagli archivi stessi (nessuna ricorsione). L'archivio contiene il file `.env` (necessario al ripristino completo): la guida in-page istruisce esplicitamente sulla custodia sicura.

### Corretto

- **Card "Gestione backups" disallineata nella hub impostazioni:** la descrizione su una sola riga faceva scendere il titolo rispetto all'icona, diversamente dalle altre card; descrizione riscritta (e più informativa) nelle 4 lingue.
- **Breadcrumb con chiavi di traduzione grezze al primo caricamento** nella nuova pagina backup: i breadcrumb calcolati come costante nel setup leggevano le traduzioni prima che fossero caricate; resi reattivi con `computed()`. Lo stesso difetto è presente nelle altre pagine impostazioni (Stampe, Cron, ...) e verrà corretto separatamente.

---

## [1.10.0-beta.10] - Credito Visibile Ovunque & Separazione Gestioni

Seguito diretto della beta.9: il credito ora è correttamente visibile e spendibile in "Nuovo incasso", ma restava isolato lì — non compariva in Estratto Conto né in Dashboard, e nulla impediva di compensare credito di una gestione (es. ordinaria) su una rata di un'altra (es. straordinaria), come già accaduto nei dati reali di un cliente.

### Aggiunto

- **Avviso non bloccante per compensazioni cross-gestione:** quando in "Nuovo incasso" il credito usato per saldare una rata proviene da una gestione diversa da quella della rata stessa (es. credito su Ordinaria 2026 usato su una rata di Straordinaria Atrio), l'interfaccia mostra un banner giallo con checkbox obbligatoria ("Confermo l'utilizzo di credito da una gestione diversa") che blocca il pulsante "Conferma incasso" finché non viene spuntata. La rilevazione non è mai bloccante per scelta esplicita: potrebbe esistere un accordo condominiale che autorizza il trasferimento tra gestioni, quindi la decisione resta sempre all'amministratore. La stessa rilevazione avviene anche lato backend, indipendentemente dal frontend (difesa in profondità): quando `StoreIncassoRateAction` rileva che la gestione della quota-credito prelevata differisce da quella della quota-target pagata, la nota sulla riga contabile in avere diventa esplicita ("Compensazione cross-gestione confermata dall'amministratore: [gestione credito] → [gestione rata] — rata n.X"), riusando il campo `note` già esistente e già mostrato in Estratto Conto — nessuna nuova tabella di audit log.
- **Spaccato del credito disponibile per gestione:** in "Nuovo incasso", un box blu sempre visibile quando il condomino ha credito mostra l'importo disponibile suddiviso per gestione (es. "€ 100 su Ordinaria 2026, € 50 su Straordinaria Atrio"), non solo il totale.
- **Credito disponibile in Estratto Conto:** nuova card nella scheda anagrafica dell'Estratto Conto con il totale del credito disponibile del condomino e, se proviene da più di una gestione, il relativo dettaglio al passaggio del mouse. Prima il credito era visibile solo cercando esplicitamente l'anagrafica dentro "Nuovo incasso" — non esistendo ancora una scheda anagrafica dedicata (`AnagraficaController::show()` è tuttora uno stub vuoto), l'Estratto Conto è il posto naturale, già esistente e già linkato ovunque (nome cliccabile nella lista incassi).
- **Nuovo widget Dashboard "Crediti da compensare":** elenco dei condomini con credito disponibile nel condominio corrente, ciascuno con link diretto a "Nuovo incasso" con anagrafica già precompilata. Segue lo stesso pattern del Treasury Guardian Widget esistente (contratto `DashboardWidget` + `WidgetManager`), nascosto automaticamente quando nessun condomino ha credito.
- **Nuovo `App\Services\Gestionale\CreditoService`** (`perAnagrafica`, `perCondominio`): l'aggregazione "credito disponibile raggruppato per gestione" era già duplicata in due punti (situazione debitoria, suggerimento all'emissione); con Estratto Conto e widget Dashboard sarebbe diventata una quarta copia. Estratta in un servizio condiviso; `EmissioneRateController::buildSuggerimentoCrediti()` è stato refactorizzato per usarlo al posto della query duplicata.
- **5 nuovi test automatici:** scenario di compensazione cross-gestione (verifica che la nota sulla riga avere contenga il testo esplicito) su `IncassoRateTest`, più 4 su un nuovo `CreditoServiceTest` (breakdown corretto per gestione, somma totale corretta, filtro per condominio). 190 test verdi su `tests/Feature/Gestionale` (1 skip preesistente, invariato).

### Corretto

- **"Inbox Operativa" in Dashboard cresceva senza limiti con molte attività reali, spingendo in basso il resto della pagina:** il tentativo iniziale di allineare la sua altezza a quella (variabile) della card "Copertura bilancio" accanto usava solo CSS Grid (`items-stretch`), che però può soltanto far crescere il fratello più *corto* fino a raggiungere quello più *alto* — mai il contrario. Con poche attività il bug restava invisibile (l'Inbox era sempre la più corta); con un'Inbox popolata da attività reali (es. 14 task scaduti) sfondava qualunque limite. Corretto misurando l'altezza reale di "Copertura bilancio" con un `ResizeObserver` e applicandola esplicitamente all'Inbox, che ora resta sempre della stessa altezza con scorrimento interno per il contenuto in eccesso.
- **Testo duplicato "Conto corrente" nella lista incassi:** quando l'amministratore chiama la propria cassa con lo stesso nome del suo tipo (es. cassa "Conto Corrente" di tipo "Conto Corrente"), la colonna Importo mostrava la stessa dicitura due volte, una volta come nome e una come tipo. La seconda riga ora compare solo quando aggiunge un'informazione diversa dal nome.
- **Badge impilate in modo incoerente nella lista incassi:** nella colonna Descrizione, il badge "1 RATA" (pagamento normale, testo corto) finiva affiancato al badge della gestione, mentre "1 RATA · CREDITO USATO" (testo più lungo) andava a capo sotto di esso — stesso layout, resa diversa solo in base alla lunghezza del testo, per via di `flex-wrap`. Ora sempre impilate verticalmente, indipendentemente dal contenuto.

### Migliorato

- **Linguaggio del banner cross-gestione:** sostituito il colloquiale "Occhio" con "Attenzione".
- **Posizione del widget "Crediti da compensare" in Dashboard:** riga a piena larghezza sotto "Copertura bilancio"/"Inbox Operativa", affiancato al Treasury Guardian con lo stesso rapporto 1/3 : 2/3 della riga sopra (Crediti più stretto, Treasury più denso); se uno dei due widget non è visibile, l'altro occupa l'intera riga.

**Deciso esplicitamente di non fare in questo giro:** visibilità del credito lato portale condòmino — richiede prima un lavoro più ampio di ottimizzazione UI sull'area condòmino, documentato come differito in `docs/credito_visibile_ovunque.md`.

---

## [1.10.0-beta.9] - Crediti Visibili & Castelletto Spendibile

### Corretto

- **Quote strapagate invisibili in "Nuovo incasso" (caso reale dal forum: condomino a credito ma nuove rate impagate):** `SituazioneDebitoriaController` selezionava solo le quote con `importo > importo_pagato` o i crediti a importo negativo. Una quota con più incassato del dovuto — possibile con incassi registrati da versioni precedenti la 1.9.1, che scaricavano l'intero importo della rata su un'unica quota "faro" senza tetto — spariva quindi dalla lista, con due effetti gravi: il residuo della rata mostrato in UI risultava gonfiato della parte strapagata (nel caso segnalato l'amministratore ha ri-incassato 173,80 € già versati, perché il form glieli riproponeva come dovuti) e il credito restava intrappolato, invisibile e inutilizzabile. Le quote strapagate ora entrano nel filtro (`importo_pagato > importo`), il residuo per rata si netta correttamente e la rata compare come riga di credito (residuo negativo, badge CREDITO) pronta per la compensazione.
- **L'eccedenza di un incasso ("Anticipo / Eccedenza") era un vicolo cieco contabile:** `StoreIncassoRateAction` la registrava come sola riga in avere sul conto `anticipi_condomini`, senza creare alcuna quota; il flusso "Usa credito" accettava però solo quote `saldo_iniziale` a importo negativo, quindi quell'anticipo non era mai più spendibile dall'interfaccia — il "castelletto" del condomino che paga in anticipo esisteva in contabilità ma non raggiungeva mai le rate emesse in seguito. L'eccedenza viene ora agganciata come strapagamento all'ultima quota del pagante toccata dall'incasso: resta in partita doppia sui crediti (con rata e immobile di riferimento), compare in "Nuovo incasso" come credito e si spende con "Usa credito". Il vecchio comportamento sopravvive come solo fallback nel caso limite in cui non esista alcuna quota di appoggio.
- **Compensazioni a credito invisibili in lista e dettaglio incasso (bug preesistente, non introdotto da questa release, ma scoperto verificando dal vivo i fix sopra):** `IncassoRateService::getDettagliRate()` confrontava `$figlia->tipo_movimento !== 'storno_credito'` — ma `tipo_movimento` è castato all'enum `TipoMovimentoContabile`, quindi il confronto con la stringa raw era **sempre vero** (tipi diversi) e il ramo che legge le compensazioni a credito non scattava mai: qualunque incasso saldato anche solo in parte con "Usa credito" — inclusi quelli storici col vecchio salvadanaio, non solo quelli col nuovo strapagamento — mostrava uno "Spaccato Copertura Rate" vuoto nel dettaglio. Nei casi limite di compensazione a **cassa zero** (importo versato € 0, rata coperta interamente dal credito) il problema si aggravava: la scrittura padre non aveva alcuna riga propria (tutto il movimento vive sulla scrittura figlia `storno_credito`), quindi anche il "Soggetto pagante" risultava "Sconosciuto" sia in lista che nel dettaglio, perché `formatMovimentoForFrontend()` cercava l'intestatario solo sulle righe della scrittura padre. Corretto il confronto enum-vs-enum e aggiunto un fallback che recupera pagante e dettaglio rate dalle scritture figlie quando la scrittura padre non basta.
- **La protezione anti "storno dello storno" non scattava mai:** stesso bug di comparazione enum-vs-stringa in `StornoIncassoRateAction::execute()` (`$scrittura->tipo_movimento === 'rettifica'`), quindi era tecnicamente possibile stornare una scrittura di rettifica già esistente, producendo un doppio storno. Corretto confrontando con `TipoMovimentoContabile::RETTIFICA`.
- **"€ 0,00" senza contesto sul dettaglio di una compensazione a cassa zero:** la card "Totale Versamento" mostrava solo la cifra, corretta ma isolata — l'unico modo per capire che la rata era stata saldata col credito era scorrere fino alla tabella "Spaccato Copertura Rate" e leggere i badge riga per riga. Aggiunta un'etichetta immediata sotto l'importo ("Saldato con credito pregresso" / "Contanti + credito pregresso" nei casi misti) appena la scrittura contiene almeno una riga di tipo credito.
- **"Conto di Accredito: N/D" e colonna Importo scollegate dal badge di credito:** stesso problema di contesto mancante in altri due punti. Nel dettaglio, "Conto di Accredito" mostrava il letterale "N/D" (corretto: nessuna cassa fisica è coinvolta in una compensazione a credito, ma sembra un dato mancante) — ora mostra "Nessuno — saldato interamente con credito" con icona monete. Nella lista incassi, la colonna Importo mostrava "€ 0,00 / 🏢 N/D / N/D": il badge "credito usato" esiste già ma vive nella colonna Descrizione accanto, fuori dal campo visivo naturale di chi legge l'importo — ora la colonna Importo stessa mostra "Credito pregresso" al posto del blocco N/D quando non c'è cassa coinvolta.

### Migliorato

- **"Usa credito" generalizzato:** la compensazione accetta ora qualsiasi forma di credito — saldo iniziale a importo negativo (comportamento storico, coperto da test di regressione) *oppure* quota strapagata — può attingere da più quote della stessa rata e da più righe di credito nella stessa registrazione, e funziona a cassa zero (importo versato € 0, rate pagate interamente col credito). Corretto anche un bug latente del riparto automatico: `distributeGreedy` azzerava la selezione delle righe di credito ad ogni ricalcolo, per cui cambiando l'importo versato dopo aver premuto "Usa credito" il credito veniva scollegato silenziosamente.
- **Suggerimento di compensazione all'emissione:** dopo l'emissione delle rate, se qualche intestatario ha un credito disponibile (saldo a credito o strapagamento) il messaggio di conferma lo segnala con nome e importo, invitando a compensare da "Nuovo incasso". Nessuna scrittura automatica: la decisione resta all'amministratore.

### Aggiunto

- **Nuovo accessor `RataQuote::credito_disponibile`:** formula unica del credito di una quota (parte non consumata per gli importi negativi, eccedenza incassata per le strapagate), usata da compensazione, situazione debitoria e suggerimento all'emissione.
- **Sei nuovi test** su `IncassoRateTest`: eccedenza che diventa credito spendibile, compensazione a cassa zero da strapagamento legacy (replica esatta del caso segnalato sul forum), regressione del salvadanaio da saldo iniziale, visibilità del credito nella situazione debitoria, pagante e dettaglio rate corretti su una compensazione a cassa zero. Scoperto inoltre che le annotazioni `/** @test */` non sono più supportate da PHPUnit 12: tre test esistenti della suite erano silenziosamente saltati — convertiti all'attributo `#[Test]`, ora girano e passano.
- **Verifica end-to-end su dati reali:** oltre ai test automatici, l'intero flusso (strapagamento → visibilità in Nuovo Incasso → "Usa credito" a cassa zero → dettaglio incasso → estratto conto) è stato riprodotto e verificato manualmente nel browser sul database di sviluppo, non solo in sandbox — è così che sono emersi i due bug di comparazione enum sopra.

---

## [1.10.0-beta.8] - Riparto Penny-Perfect & Offline

### Corretto

- **Totali di colonna errati nella stampa "Riparto per Tabella e Soggetto" (segnalazione beta-tester su condominio reale, 44 unità):** i totali delle colonne tabella non quadravano col budget della voce di spesa (es. ACQUA FISSO €2.199,96 invece di €2.200,00) e i centesimi mancanti ricomparivano su colonne estranee — in v1.9.1 fino al caso limite di €0,01 stampato nella colonna TUNNEL per condòmini che al tunnel non partecipano affatto. Causa: `RipartoTabelleService` ricostruiva le celle ridistribuendo il totale di riga (da `rate_quote`) sui pesi float e scaricava l'intero resto di arrotondamento su una sola tabella per soggetto (in v1.9.1 l'ultima registrata, anche a peso zero; dopo il fix "Fase 1" quella a peso maggiore) — mentre `CalcoloQuoteService`, il motore che genera le rate, calcola già ogni conto in modo penny-perfect tra i suoi soli partecipanti. La stampa ora ricostruisce le celle CONTO PER CONTO con lo stesso identico algoritmo del motore (pesi identici, cascata ruolo identica inclusa `nuda_proprietario`, decurtazione scoperti identica, copia 1:1 di `distribuisciImporto()` con vincolo di sincronizzazione documentato): ogni colonna somma esattamente al budget dei suoi conti, i centesimi di resto restano dentro i partecipanti della voce, e ogni riga coincide con `rate_quote` perché le allocazioni sono le stesse che hanno generato le rate. Un riallineamento di sicurezza copre i casi di dati modificati dopo la generazione (o quote extra come saldi/conguagli): la garanzia legale riga = `rate_quote` vale incondizionatamente, il che elimina anche la dipendenza da `piano_rate.snapshot_at` che bloccava la "Fase 2" originaria. `CalcoloQuoteService` non è stato toccato. Dettagli in `docs/ripartotabelle_discrepanza_centesimale.md` (appendice "Risoluzione definitiva").
- **Interfaccia dipendente da CDN esterno per i font:** `app.blade.php` caricava Instrument Sans da `fonts.bunny.net` con un `<link rel="stylesheet">` bloccante: senza connessione internet (installazioni locali MAMP, reti con firewall) il rendering di ogni pagina restava appeso fino al timeout, facendo sembrare l'applicazione bloccata. I font sono ora self-hosted via `@fontsource/instrument-sans` (400/500/600) e bundlati da Vite: nessuna richiesta esterna, avvio più rapido anche online.

### Migliorato

- **Impaginazione stampa riparto (feedback beta-tester):** colonne di servizio ristrette (App. 5→3,5%, Ruolo 5→3%, % TOT. 5→3,5%, TOTALE SOGG. e TOT. IMMOB. 8-10→7%) a favore delle colonne tabella; font alzato di mezzo punto nei casi oltre 5 tabelle (base 6→6,5pt); blocchi di chunking portati da 6 a 8 tabelle, così un condominio con 8 tabelle torna su blocco unico A3 come nelle stampe storiche. Criteri e valori documentati nella nuova guida `docs/stile_stampa_riparto_tabelle.md`.
- **`.gitignore`:** esclusi i file `lang/php_*.json` generati temporaneamente dal plugin Vite `laravel-vue-i18n` durante build/dev server.

### Aggiunto

- **Test di regressione su dati reali:** `RipartoCondominioParRealeTest` ricostruisce un condominio reale di 44 unità, 8 tabelle e 23 conti (dati forniti dall'amministratore beta-tester) e verifica al centesimo tutti i 44 totali `rate_quote` E tutti i totali di colonna della stampa; `RipartoCasiLimiteTest` copre conti multi-tabella con coefficienti 60/40, comproprietari 50/50, ripartizioni percentuali proprietario/inquilino, capitoli con importo override e piani a 12 rate. I test unit del precedente approccio row-first (superato) sono stati rimossi.

---

## [1.10.0-beta.7] - Hardening Fatture Passive

### Corretto

- **Crash immediato in Modifica Fattura Passiva:** `FatturaPassivaController::edit()` non passava sette prop calcolate invece da `create()` (`fornitori`, `esercizi`, `debiti_patrimoniali`, `fatture_pregresse_registrate`, `fondi_riserva`, `capienza_rata_zero`, `incassato_rata_zero`), e passava una versione ridotta di `conti`/`banche` priva dei dati di budget/saldo. Il componente Vue le dichiara tutte come prop obbligatorie e un `watch(..., { immediate: true })` vi accede senza controllo — più grave del caso analogo già corretto per i pagamenti fornitori (beta.6), perché qui la condizione di guardia è sempre vera per una fattura esistente (ha già `fornitore_id` e `data_documento` popolati), quindi il crash si verificava **incondizionatamente** al solo apertura della pagina, non al primo carattere digitato. Corretto estraendo il calcolo del contesto budget (già duplicato tra `create()` ed `edit()`) in un unico metodo condiviso `prepareContestoBudget()`, usato da entrambi.
- **`stato_pagamento = 'stornata'` mandava in crash qualunque lettura successiva della fattura:** lo storno (`StornoFatturaController`) scrive `'stornata'` sulla colonna `stato_pagamento`, ma l'enum PHP `StatoPagamentoFattura` (a cui il campo è castato) prevedeva solo `APERTA`/`PARZIALE`/`PAGATA` — un valore non valido per l'enum. La colonna è una semplice `VARCHAR`, quindi la scrittura su database riusciva silenziosamente; l'errore (`ValueError: "stornata" is not a valid backing value`) scattava solo alla lettura successiva, cioè su **qualunque** accesso a una fattura già stornata (elenco, dettaglio, modifica). Il frontend (badge, filtri in `DataTableRowActions.vue`) si aspettava già questo valore da tempo — mancava solo il case nell'enum. Aggiunto `STORNATA` con le relative label/colore badge.
- **Modifica di una fattura non modificabile (stornata, esercizio chiuso, pregressa, sopravvenienza, sforo motivato, piano rate straordinario) "riusciva" in apparenza:** `aggiornaFattura()` blocca correttamente questi casi lanciando `FatturaModificaVietataException`, ma il controller la traduceva con `back()->with(flashError(...))` invece di `back()->withErrors([...])` — stesso bug già corretto per i pagamenti fornitori in beta.6. Per Inertia un redirect senza errori di validazione è sempre "successo": il salvataggio falliva in silenzio senza che l'amministratore se ne accorgesse. Corretto su due livelli: la guardia di blocco è ora un unico metodo `motivoBloccoModifica()` condiviso tra `edit()` e `aggiornaFattura()` (invece di essere duplicata), `edit()` reindirizza subito al dettaglio con un messaggio esplicito prima di mostrare il form se la fattura non è modificabile, e l'eccezione usa `withErrors(['modifica_vietata' => ...])` per tutti i casi bloccati durante il salvataggio. Aggiunta anche la visualizzazione dei messaggi flash nella pagina di dettaglio fattura, che finora non ne mostrava nessuno.
- **Invio email dalla configurazione SMTP del pannello (Impostazioni > Mail) falliva silenziosamente con alcuni provider quando si selezionava la cifratura TLS:** `MailConfigServiceProvider::applySmtpConfig()` ricostruiva l'array `mail.mailers.smtp` da zero omettendo la chiave `local_domain` (hostname usato nel comando `EHLO`/`HELO`), presente invece nella configurazione letta da `.env`. Senza di essa Symfony Mailer usa il fallback letterale `[127.0.0.1]`, che molti server SMTP di hosting condivisi rifiutano come pattern tipico di relay malconfigurato — da qui l'invio fallito, apparentemente legato alla sola scelta della cifratura ma in realtà dovuto al passaggio dalla configurazione `.env` a quella da database. Il campo "crittografia" del pannello, inoltre, non aveva mai avuto alcun effetto reale: Laravel 13 decide TLS/SSL tramite la chiave `scheme`, non `encryption` (che nessun mailer di Laravel legge più). Corretto ripristinando `local_domain` in `applySmtpConfig()` e mappando la selezione SSL su `scheme => 'smtps'`, sia nella configurazione a runtime sia nel test di connessione da pannello (`MailSettingsController::testConnection()`).

---

## [1.10.0-beta.6] - Hardening Pagamenti Fornitori & Mobile UX

### Corretto

- **Crash immediato in Modifica Pagamento Fornitore digitando l'IBAN Beneficiario:** `PagamentoFornitoreController::edit()` non passava la prop `fornitori` al componente Vue (a differenza di `create()`), mentre il computed `selectedFornitore` vi accedeva senza controllo. Finché il campo IBAN restava vuoto il computed `ibanDiscrepanza` andava in corto circuito ed evitava il crash; al primo carattere digitato, il computed tentava di leggere `fornitori.find(...)` su `undefined` e mandava in errore l'intero render — senza alcuna riga in `laravel.log`, trattandosi di un `TypeError` client-side che non arriva mai al server.
- **Bonifico Parlante, Tipo Detrazione e Commissioni Bancarie non venivano salvati in modifica:** `UpdatePagamentoFornitoreRequest` non validava questi tre campi, che Laravel scartava silenziosamente prima che raggiungessero il service. Per le commissioni il problema era più profondo: anche a validazione corretta, `PagamentoFornitoreService::aggiornaPagamento()` non ricreava mai la riga contabile "Spese Bancarie" né aggiornava l'uscita di cassa di conseguenza (a differenza di `registraPagamento()`), quindi la funzionalità in modifica non era mai stata implementata, non solo dimenticata in validazione.
- **Pagamenti stornati raggiungibili — e apparentemente "salvabili con successo" — dalla pagina di modifica:** `aggiornaPagamento()` blocca correttamente (per design) la modifica di un pagamento stornato, ma lo faceva con un `back()->with(flashError(...))` invece di `back()->withErrors([...])` come le altre eccezioni dello stesso metodo. Per Inertia un redirect senza errori di validazione è sempre "successo": il salvataggio falliva in silenzio mentre l'amministratore vedeva comunque la modale "Pagamento registrato". Corretto su tre livelli: `edit()` ora reindirizza subito al dettaglio con un messaggio esplicito se il pagamento è stornato (senza mai mostrare il form), l'eccezione usa `withErrors(['modifica_vietata' => ...])` per tutti gli altri casi (esercizio chiuso, ecc.), e la pagina di dettaglio pagamento mostrava comunque il messaggio flash.
- **Pagamenti cumulativi (più fatture) potenzialmente corrotti se modificati:** un commento nel codice dichiarava "multi-fattura non modificabile", ma nulla lo impediva realmente — `aggiornaPagamento()` riassegnava l'intero importo netto a *ciascuna* fattura collegata invece di distribuirlo proporzionalmente. Aggiunto un guard esplicito che blocca la modifica di un pagamento su più fatture, invitando a usare lo storno.
- **Nessun controllo di capienza conto in modifica pagamento:** la modale "Saldo Conto Insufficiente" (con nota obbligatoria per bypassare) era collegata solo in creazione — `aggiornaPagamento()` non chiamava mai la validazione corrispondente, quindi una modifica poteva silenziosamente portare un conto in scoperto senza richiedere alcuna giustificazione. Il controllo ora scatta solo quando la modifica *peggiora* l'esposizione (aumento importo o cambio conto): un edit che lascia invariato o riduce l'importo passa comunque, anche se il conto è già in uno scoperto approvato in precedenza.
- **Layout di Modifica Pagamento Fornitore non responsive su mobile:** le righe a doppia colonna (Fornitore/Conto di Addebito, Data/Commissioni) usavano una griglia fissa che a larghezze da smartphone comprimeva i campi fino a sovrapporli. Ora impilano su una sola colonna sotto il breakpoint `sm`.

### Migliorato

- **Bonifico Parlante visibile solo per pagamenti in Bonifico:** la detrazione fiscale (art. 16-bis TUIR e normative collegate per ecobonus/sismabonus/superbonus) richiede per legge un bonifico bancario tracciabile — contanti e assegni non sono ammessi al beneficio. La sezione "Bonifico Parlante" ora compare solo quando il metodo di pagamento è Bonifico, sia in Nuovo Pagamento sia in Modifica, con reset automatico di detrazione/flag cambiando metodo.
- **Layout di Modifica Pagamento Fornitore ridisegnato a piena larghezza:** rimossa la guida introduttiva ridondante in testa alla pagina e il vincolo di larghezza che lasciava vuoti i due terzi dello schermo (retaggio della colonna destra rimossa in una beta precedente); i campi correlati sono ora affiancati a coppie, coerentemente con le altre pagine del gestionale (es. Risorse e Fondi).
- **Pulsante "Salva Modifiche" ridimensionato:** sostituita la CTA verde a piena larghezza con un pulsante compatto allineato a destra (+ link "Annulla"), come nelle altre pagine di modifica dell'app.

---

## [1.10.0-beta.5] - Installer Nativo

### Aggiunto

- **Installer Nativo KondoManager:** Sostituito interamente il pacchetto `eii/laravel-installer` con un wizard di installazione proprio, sotto `App\Livewire\Installer\*` — nessuna dipendenza esterna, nessun alias Livewire. Il vincolo di checksum che costringeva a usare la classe originale del vendor durante la primissima installazione (documentato nelle beta precedenti) non esiste più: un solo `InstallerWizard`, referenziato per nome-classe diretto nelle routes, gira identico sia alla prima installazione sia rientrando nel wizard.
- **Nuova grafica:** Tema scuro con card bianca, badge "Km" come unico marchio in header (coerente con `AppLogo.vue`, dove la scritta testuale accanto al badge è anch'essa assente), tooltip esplicativo su ogni campo del form, select personalizzati con freccia custom, loader più visibile e descrittivo tra uno step e l'altro (in particolare durante la configurazione del database). Interfaccia del wizard interamente in italiano/inglese, rilevata automaticamente dall'header `Accept-Language` del browser — concetto distinto dalla lingua scelta per l'app installata (`available_locales`), che resta configurabile come già introdotto in beta.4.
- **Layout compatto senza scroll:** Card e spaziature ridotte, campi "Nome applicazione/URL/Lingua" riorganizzati in griglia (prima impilati a piena larghezza) e intestazioni di sezione ridondanti rimosse, così l'intera pagina di ogni step (compreso Ambiente, il più denso di campi) è visibile senza scorrimento verticale sulle risoluzioni desktop comuni.
- **Testo di benvenuto rivisto:** Unificati i tre paragrafi introduttivi dello step Benvenuto (promemoria credenziali, scopo del wizard, requisiti minimi) in un messaggio di benvenuto coeso e più caloroso.
- **Mini-guida per ogni step:** Sotto il titolo di ogni pagina del wizard è stata aggiunta una breve descrizione di cosa fare in quello step. Nella pagina finale la guida ricorda esplicitamente di configurare il cronjob sul server, senza il quale i processi in background (emissione rate, promemoria, notifiche email) non funzionano.
- **Logo nella sidebar:** Il badge "Km" è stato spostato dall'header esterno alla sidebar dello stepper (sopra il primo step) con nome "Kondomanager" e sottotitolo, racchiusi in un riquadro con sfondo tenue per separarlo visivamente dagli step senza usare una linea divisoria.
- **Traduzione "Applicazione e database":** Rinominato lo step "Impostazioni ambientali" (traduzione letterale poco naturale di "environment settings") in "Applicazione e database", più chiaro e coerente col contenuto reale dello step (nome app, lingua, credenziali database).
- **Requisiti server in griglia:** Estensioni PHP e permessi cartelle mostrati su due colonne invece che in un unico elenco verticale, eliminando lo scroll residuo su questa pagina.
- **Feedback di caricamento sui pulsanti:** I pulsanti Avanti/Salta/Fine mostrano uno spinner e si disabilitano durante l'elaborazione dello step, evitando doppi invii accidentali (utile in particolare nello step Ambiente, che esegue `migrate:fresh`).
- **Pulsante "Indietro":** Aggiunta la navigazione allo step precedente (assente finora — il wizard procedeva solo in avanti), nascosta nella pagina finale dato che a quel punto l'installazione è già stata bloccata (lock file scritto).
- **Test connessione database:** Nello step Applicazione e database, un pulsante "Testa connessione" verifica host/porta/credenziali PRIMA di premere "Avanti" (che esegue `migrate:fresh`), usando una connessione DB dedicata e isolata (`installer_test`) così un test fallito non lascia stato sporco sulla connessione `mysql` reale.
- **Mostra/nascondi password:** Nuovo componente `<x-installer.password-input>` con icona a forma di occhio, applicato ai campi password di database, posta e amministratore — riduce gli errori di battitura su credenziali che altrimenti restano invisibili.
- **Ricontrolla requisiti server:** Nella pagina Requisiti server, un pulsante "Recheck" (con orario dell'ultimo controllo) permette di rieseguire il controllo PHP/estensioni/permessi senza ricaricare la pagina — utile se si risolve un requisito mancante a metà installazione.
- **Guida cronjob dettagliata su Finish:** Nella pagina finale, tab per cron-job.org/cPanel/Plesk-VPS (adattate dalla guida già presente nel pannello Impostazioni) con comandi pronti da copiare — invece di rifare l'intero step dedicato con test/skip, valutato eccessivo dato che il controllo esiste già post-login in Impostazioni > Cron.

### Corretto (grafica)

- **Pulsanti di test che si restringevano:** I pulsanti "Testa connessione"/"Invia email di test" non avevano `shrink-0`, quindi si comprimevano nel container flex quando il messaggio di esito accanto era lungo. Aggiunto `shrink-0` al pulsante e `min-w-0` al messaggio.
- **Test configurazione SMTP:** Nello step Posta è stato aggiunto un pulsante "Invia email di test" che tenta un invio reale con le credenziali appena inserite (senza scriverle su `.env`), mostrando subito se la configurazione funziona o l'errore di connessione/autenticazione restituito dal server SMTP.

### Sicurezza

- **Password amministratore mai su disco:** Lo step di creazione amministratore non include più la password in chiaro nel payload salvato nel progress file (nemmeno temporaneamente) — la redazione introdotta in beta.4 per lo step Finish resta come seconda barriera di sicurezza.

### Corretto

Bug reali emersi durante il porting e il test end-to-end dal vivo (non presenti nei singoli step testati isolatamente in precedenza):

- **Loop di redirect infinito tra gli step Posta e Amministratore:** Il wizard riusava per errore la stessa chiave di progress (`raw_env_data`) sia per lo step Ambiente sia per lo step Posta. Al termine dello step Posta (anche solo saltandolo), il wizard interpretava erroneamente quel marker come dati dello step Ambiente, li sovrascriveva e rimandava sempre indietro allo step Posta — bloccando per sempre il proseguimento verso Crea Amministratore.
- **Eccezione quando lo step Posta veniva saltato:** `MailSettings::completeStep()` chiamava `$this->validate()` incondizionatamente anche quando lo step non prevede regole (mail non richiesta), causando un errore Livewire (`MissingRulesException`) invece di procedere.
- **Lingua di default del campo Ambiente contaminata dalla lingua del wizard:** Il campo "Lingua" nello step Ambiente leggeva `config('app.locale')`, che nel frattempo la nuova rilevazione automatica della lingua del wizard (`App::setLocale()`) aveva già sovrascritto — mostrando come preselezionata la lingua del browser invece del valore reale in `.env`. Corretto leggendo `env('APP_LOCALE')` direttamente.
- **Checkmark permessi sempre verde:** Il display dei permessi server nello step Requisiti mostrava sempre l'icona di successo indipendentemente dall'esito reale del controllo (confrontava un array con un booleano). Ora riflette correttamente `exists` e `writable`.
- **Messaggi di validazione illeggibili:** Errori come "The db database field is required" sono stati sostituiti con le etichette tradotte dei campi ("The Database field is required") su tutti gli step con validazione (Ambiente, Posta, Amministratore). Anche il messaggio generico "formato non valido" sui campi database (host/porta/nome/utente) è stato sostituito con un messaggio esplicito ("non può contenere spazi").

### Corretto (test su hosting reale — Altervista, vhosting-it)

Bug emersi testando l'installer per davvero su hosting condiviso, non riproducibili in locale:

- **Errori di validazione bloccati in modo permanente + doppio click:** correggere un campo dopo un errore di validazione non aggiornava mai il server — l'errore restava visualizzato anche con un valore corretto finché non si premeva di nuovo un pulsante (es. "Avanti"), da cui il "serve un doppio click". Causa reale (trovata leggendo il sorgente JS di Livewire 4, non un bug di "morph" come ipotizzato inizialmente): in Livewire 4 il modificatore `.blur` **da solo non invia mai la richiesta al server** (`shouldSendNetwork` risulta sempre `false` senza `.live` — cambio di comportamento rispetto a Livewire 3, dove `wire:model.blur` era già sufficiente). Serve la combinazione `wire:model.live.blur`. Corretto su tutti i campi del wizard (Ambiente, Posta, Amministratore) — ripristinata la pulizia immediata dell'errore alla correzione del campo, senza bisogno di un secondo click.
- **Step Posta: mancava il campo Cifratura (TLS/SSL):** il form del wizard non aveva un campo per l'encryption SMTP, mentre l'app reale (Impostazioni > Mail) sì — causava "errore di connessione" nel test dell'installer anche con credenziali corrette, perché la richiesta partiva senza cifratura esplicita. Aggiunto il campo "Cifratura" (TLS/SSL/Nessuna), applicato sia al test email sia al salvataggio.
- **Campo Cifratura non aveva alcun effetto:** leggendo `Illuminate\Mail\MailManager::createSmtpTransport()` si scopre che Laravel legge solo la chiave di configurazione `scheme`, mai `encryption` — quindi né il valore scritto dall'installer né quello scritto da Impostazioni > Mail (`App\Settings\MailSettings::$mail_encryption`) avevano mai avuto effetto sulla cifratura reale. Corretto scrivendo `MAIL_SCHEME` (`smtps` per SSL, vuoto per TLS/auto-detect in base alla porta) sia nel test email sia in `.env`. *(Nota: non scriviamo più la configurazione mail anche su database — verificato che la pagina Impostazioni > Mail mostra già un badge "Configurazione .env" quando la mail è attiva solo via `.env`, quindi l'amministratore è comunque informato correttamente senza bisogno di duplicare la scrittura.)*
- **Test email: nessun destinatario dedicato:** il test inviava sempre all'indirizzo mittente configurato, impedendo di verificare la consegna reale su un indirizzo esterno. Aggiunto un campo "Invia email di prova a" separato, come già presente nel pannello Impostazioni dell'app.
- **`touch(): Utime failed: Operation not permitted` su Altervista:** hosting molto restrittivi negano la modifica dell'orario di modifica su file già esistenti. Laravel chiama internamente `touch()` come pura ottimizzazione della cache delle view compilate (Blade/Livewire) — se fallisce, il warning PHP viene convertito in eccezione fatale da Laravel, anche se la cache compilata resta valida. Aggiunto un gestore errori dedicato in `AppServiceProvider` che sopprime solo questo avviso specifico, lasciando invariata la gestione di tutti gli altri errori.
- **Grafica assente dopo il redirect su Altervista:** causa più probabile il bug `touch()` sopra — l'eccezione fatale durante la compilazione delle view mostra la pagina di errore generica di Laravel invece della pagina installer, dando l'impressione di "nessuna grafica". Individuato anche un meccanismo correlato da tenere presente per il futuro: `AppServiceProvider` ha un fix preesistente (`URL::forceScheme('https')`, per reverse proxy come Cloudflare) che forza tutti gli asset in HTTPS se `APP_URL` inizia per `https://` — innocuo se l'HTTPS sul dominio è realmente attivo (verificabile dal pannello di controllo dell'hosting), ma causerebbe lo stesso sintomo se configurato prima che il certificato sia pronto.
- **Spinner di caricamento invisibile sui pulsanti Avanti/Salta/Fine:** lo spinner e il testo "Attendere..." erano vincolati con `wire:target` alla sola azione del pulsante stesso (es. `completeStep`), quindi durante un salvataggio "on blur" di un campo (frequente da quando si usa `.live.blur`) il pulsante si disabilitava correttamente ma restava visivamente invariato — su connessioni lente dava l'impressione di un pulsante "bloccato" invece che in caricamento. Rimosso il vincolo `wire:target`: ora spinner e testo reagiscono a qualsiasi richiesta Livewire in corso. Confermato su hosting reale (vhosting-it) che questo risolve anche la segnalazione "il pulsante Salta non risponde".
- **Scroll residuo nello step Posta:** rimossa l'intestazione di sezione "Mail" (ridondante col titolo della pagina) e il relativo margine superiore, guadagnando spazio verticale sufficiente a eliminare lo scroll interno anche su schermi più bassi.
- **Richieste Livewire bloccate da CORS su Altervista ("i pulsanti non rispondono"):** causa reale, individuata dal log della console del browser (`Cross-Origin Request Blocked... reading the remote resource at https://.../livewire-xxx/update`, status 200 ma lettura bloccata): il fix `URL::forceScheme('https')` in `AppServiceProvider` forzava lo schema di **tutti** gli URL generati da Laravel (inclusi gli endpoint Livewire) a `https` solo perché `APP_URL` nel `.env` conteneva `https://`, indipendentemente dallo schema con cui la richiesta corrente veniva effettivamente servita. Quando per un qualsiasi motivo (proxy che inoltra `X-Forwarded-Proto: http`, redirect intermedio, hosting che non termina ancora davvero in HTTPS) la pagina risultava servita in `http`, gli endpoint Livewire restavano comunque forzati in `https` — uno schema diverso da quello della pagina che li chiama è, per il browser, un'origine diversa, quindi la richiesta viene bloccata come cross-origin pur rispondendo 200. Corretto condizionando il fix a `request()->isSecure()` (o al contesto console, per email/job in coda dove non esiste una request da cui rilevare lo schema reale): non si forza più uno schema in contraddizione con quello della richiesta corrente in atto. Confermato su hosting reale (Altervista) dopo il fix: redirect e caricamento CSS/grafica corretti end-to-end.
- **Rimosso il pulsante "Indietro":** tornare a uno step precedente permetteva di rientrare nello step Ambiente e ripremere "Avanti", che riesegue incondizionatamente `migrate:fresh` — un comportamento a sorpresa non ovvio dall'interfaccia. Rimossi il pulsante, il metodo `InstallerWizard::previousStep()` e la voce di traduzione `actions.back`, ormai inutilizzata.
- **Pagina Fine più compatta e titolo più caloroso:** ridotti margini e padding per eliminare lo scroll verticale residuo; il titolo passa da "Installazione completata!" a "Complimenti, installazione completata!" (e equivalente inglese).
- **Footer "Powered by Kondomanager":** aggiunto sia nel wizard Livewire (in fondo alla sidebar, in un riquadro grigio arrotondato che fa da specchio a quello del badge in alto) sia in `index.php`/`installer_php84.php` (in fondo alla card, stile coerente col resto del file) — nome prodotto e licenza AGPL-3.0 sempre visibili, con link al sito e alla repo GitHub.
- **Testo "Ready. Please select an action." rimosso** da `index.php`/`installer_php84.php`: non aggiungeva informazione, si passa direttamente dall'elenco dei requisiti al pulsante di avvio.

### Corretto (individuati rileggendo un `.env` reale generato su Altervista)

- **Duplicazione (solo cosmetica, verificata) di `DB_CHARSET`/`DB_COLLATION`/`DB_ENGINE`/`TRUSTED_PROXIES` in `.env`:** l'"Adaptive Env Injection" di `index.php`/`installer_php84.php` faceva un append cieco di queste righe in fondo al file, lasciando intatto il placeholder commentato già presente in `.env.example` — non causa un bug funzionale (in un `.env` vince sempre l'ultima occorrenza di una chiave, verificato con un test diretto), ma rende il file confuso da leggere ed è un pattern fragile. Aggiunta una funzione `setEnvValue()` che scommenta/sostituisce la riga esistente sul posto (stesso principio già usato in `InstallerWizard::updateEnvSettings()`), eliminando la duplicazione.
- **`MAIL_MAILER` forzato a `smtp` anche saltando lo step Posta:** saltando lo step, `InstallerWizard::saveStep()` chiamava comunque `runMailSetup([])` con dati vuoti; il fallback `$data['mail_mailer'] ?? 'smtp'` cambiava il mailer da `log` (il default sicuro di `.env.example`, che si limita a loggare le email) a `smtp` senza però impostare host/porta/credenziali — un mailer "attivo" ma non funzionante, peggio del semplice logging. Corretto: `runMailSetup()` viene chiamato solo se lo step è stato davvero compilato, non saltato.
- **Corruzione silenziosa di credenziali contenenti `$` seguito da una cifra:** `updateEnvSettings()`/`updateMailSettings()` scrivevano i valori su `.env` con `preg_replace($pattern, "{$key}={$value}", $env)` — in PHP, nella stringa di sostituzione di `preg_replace()`, `$` + cifra ha significato speciale di backreference. Riprodotto: una password come `Pass$1word` veniva scritta su `.env` come `Password` (il `$1` interpretato come backreference e rimosso). La connessione dal vivo durante l'installazione non risente del problema (usa il valore originale via `config()`), ma richieste successive che rileggono il `.env` userebbero la versione corrotta. Corretto sostituendo `preg_replace` con `preg_replace_callback` in entrambe le funzioni, che non interpreta `$` nella stringa restituita dalla callback.
- **Autocomplete mancante sui campi password dello step Amministratore:** indagata una segnalazione ("con password non coincidenti l'utente viene creato comunque") con 4 test diretti sul componente `CreateAdmin` (mismatch, match, campo conferma mai sincronizzato, verifica del messaggio d'errore) — la validazione server-side blocca correttamente la creazione in ogni scenario testato, nessun bug riprodotto lì. Aggiunto comunque `autocomplete="new-password"` a entrambi i campi password come misura preventiva: senza quell'attributo, il browser/password manager può suggerire o completare automaticamente il secondo campo con una password salvata diversa da quella digitata, dando l'impressione di un mismatch ignorato quando in realtà è il browser a uniformare silenziosamente il valore inviato.
- **Errore "password non coincidenti" bloccato dopo la correzione (step Amministratore):** verificato anche dal vivo nel browser (con lo stato Livewire sincronizzato via `$wire.set`, click reale su "Avanti", controllo del database prima/dopo) che con password diverse l'utente NON viene mai creato e il wizard non avanza. Trovato però un bug UX confinante: la regola `confirmed` vive sul campo password, ma l'utente corregge il mismatch tipicamente editando la CONFERMA — e il blur sulla conferma validava un campo senza regole proprie, quindi l'errore "non coincide" restava visualizzato anche a correzione avvenuta (stessa famiglia del bug `.live.blur` già corretto sugli altri step). Corretto `CreateAdmin::updated()`: quando cambia uno dei due campi password ed entrambi sono compilati, viene rivalidata la coppia — l'errore ora compare appena la conferma sbagliata viene digitata e scompare appena viene corretta, senza click su "Avanti". Il confronto scatta solo con entrambi i campi compilati (niente errore prematuro mentre si sta ancora digitando la conferma), e la sola password mantiene la validazione live di presenza/lunghezza. Aggiunti 6 test di regressione permanenti (`tests/Feature/Installer/CreateAdminValidationTest.php`).
- **Il vero bug dietro la segnalazione originale, trovato dopo — componente Amministratore rimontato da zero al click su "Avanti":** la verifica precedente (via `$wire.set` diretto) aveva escluso un bug perché non esercitava il percorso reale del click. Riguardando la registrazione originale della segnalazione e ripetendo la stessa identica sequenza in un browser vero, ispezionando le richieste di rete: il campo si sincronizzava correttamente sul server, ma al click su "Avanti" — che sul componente wizard dispatcha l'evento `completeStep` verso il figlio `CreateAdmin` — Livewire perdeva l'identità del componente figlio e lo rimontava da zero (nuovo `wire:id`, tutte le proprietà tornate a `null`), invece di riutilizzare l'istanza esistente con i dati digitati. Causa: `@livewire($step['component'], ['wizard' => $this])` in `installer-wizard.blade.php` non passava una key esplicita — ad ogni ri-render del wizard (incluso quello che processa il click su "Avanti") Livewire può perdere il collegamento col figlio già montato. Riproducibile in modo affidabile solo in un browser reale (il morphing DOM lato client dove si manifesta non è esercitato da `Livewire::test()`). Corretto aggiungendo `key($step['key'])` alla direttiva `@livewire()` — chiave stabile per tutta la permanenza sullo stesso step, indipendente dal numero di ri-render del wizard. Riguarda **tutti gli step del wizard**, non solo Amministratore, essendo la stessa direttiva condivisa. Riverificato con la stessa identica sequenza dal vivo dopo il fix: campi mantenuti, errore corretto, utente creato con i dati giusti, avanzamento regolare allo step Fine. *(Valutata e scartata la disabilitazione del pulsante "Avanti" in caso di mismatch: su hosting lenti lo stato disabilitato dipenderebbe dal round-trip di sincronizzazione del blur, ricreando l'effetto "pulsante morto senza spiegazione" appena risolto — il messaggio d'errore live più il blocco garantito lato server comunicano meglio la stessa protezione.)*

### Rimosso

- **Dipendenza `eii/laravel-installer`:** Rimossa da `composer.json`. `livewire/livewire` (che era installato solo transitivamente tramite il pacchetto rimosso) è ora dichiarato come dipendenza diretta. Rimossi anche i file pubblicati orfani (`resources/views/vendor/installer/`, `public/vendor/installer/`).

### Test

- Suite completa (239 test) verificata a ogni fase.
- Test end-to-end reale contro un database MySQL dedicato e temporaneo (creato ed eliminato per l'occasione, senza toccare il database di sviluppo): `migrate:fresh`, seeding, sincronizzazione lingua/nome applicazione/versione, creazione amministratore con ruolo Spatie, redazione password nel riepilogo finale — verificato tutto funzionante end-to-end.

---

## [1.10.0-beta.4] - Aggiornamento Piattaforma (Laravel 13) & Identità Personalizzabile

### Aggiornamento Piattaforma

- **Laravel 13, Inertia 3 & Livewire 4:** Aggiornate le dipendenze core del framework (`laravel/framework` ^13.0, `inertiajs/inertia-laravel` / `@inertiajs/vue3` ^3.0, `livewire/livewire` 4.3, `vite` ^8.0, `pestphp/pest` ^4.0). Verificato l'intero frontend Inertia/Vue con test manuali estensivi su tutte le pagine.

### Aggiunto

- **Selezione Lingua in Installazione:** Il wizard di installazione permette ora di scegliere la lingua principale dell'applicazione (Italiano, Inglese, Spagnolo, Portoghese) direttamente dallo step "Impostazioni ambientali", invece di dover passare successivamente da Impostazioni Generali.
- **Nome Applicazione Personalizzabile da Pannello:** Il nome dell'applicazione (mostrato nella scheda del browser e come mittente nelle email transazionali) è ora modificabile in qualsiasi momento da Impostazioni Generali, non solo in fase di installazione. Il valore è salvato in `GeneralSettings->app_name` e applicato a runtime tramite `SetAppNameMiddleware` (richieste web) e un hook `Queue::before()` (job in coda / notifiche email), lo stesso meccanismo già usato per la lingua.

### Sicurezza

- **Redazione Credenziali nello step Finish dell'Installer:** Il componente vendor `eii/laravel-installer` esponeva le password in chiaro (admin, database, SMTP) sia nello snapshot Livewire incluso nell'HTML della pagina di riepilogo finale sia nel file `.txt` scaricabile. Introdotto `App\Livewire\Installer\Finish` che redige questi campi subito dopo il caricamento, prima che vengano serializzati.

### Corretto

- **Nome App / Lingua Ignorati in Prima Installazione Pulita:** I campi "Nome applicazione" e "Lingua" raccolti dal wizard non avevano alcun effetto in una prima installazione pulita (funzionavano solo negli aggiornamenti), perché il wizard che li scrive su `.env` viene sostituito dalla versione originale del vendor per un vincolo di checksum Livewire. Il valore scelto viene ora salvato in un marker nel progress file e applicato a `GeneralSettings` (lingua, nome app) subito dopo `migrate:fresh`, tramite il listener `MigrationsEnded` già usato per sincronizzare la versione — funziona quindi indipendentemente da quale wizard esegue l'installazione.
- **Cache Vista Blade Obsoleta / Attributo `<title>` Deprecato:** Risolti due problemi emersi dall'aggiornamento a Inertia 3: una cache di vista compilata precedente all'upgrade causava pagine bianche su tutte le rotte tranne "/"; l'attributo `<title inertia>` in `app.blade.php`, deprecato dalla nuova major, è stato aggiornato a `<title data-inertia>`.

### Rimosso

- **Campi Email Morti nello step Ambiente dell'Installer:** Rimossa da `FixedEnvironmentSettings` la raccolta di campi SMTP mai effettivamente scritti su `.env` né mai esposti nella vista pubblicata — la configurazione email resta affidata al suo step dedicato ("Impostazioni di posta").

---

## [1.10.0-beta.3] - Riparto Penny-Perfect, Hardening Installer & Requisito Minimo PHP 8.4

### Corretto
- **Discrepanza Centesimale nel Riparto per Tabella (Fase 1):** Risolto lo spostamento di centesimi tra le colonne del documento "Riparto Bilancio per Tabella × Soggetto" (`RipartoTabelleService::buildMatrice`). Le tabelle vengono ora ordinate per peso crescente prima della distribuzione penny-perfect, così il resto dell'arrotondamento va sempre alla tabella con il peso maggiore (dove lo scostamento è proporzionalmente irrilevante) invece che a una tabella arbitraria determinata dall'ordine di inserimento nel piano dei conti. Analisi completa, causa meccanica e roadmap della Fase 2 (approccio column-first, non ancora avviata) documentate in `docs/ripartotabelle_discrepanza_centesimale.md`.
- **Duplicazione Variabili Database nel Wizard di Installazione:** Corretta la regex in `InstallerWizard::updateEnvSettings()` / `updateMailSettings()` che non riconosceva le righe placeholder commentate (`# DB_HOST=...`) ereditate da `.env.example`, causando l'aggiunta di una riga duplicata invece della sostituzione in-place quando l'amministratore inseriva le credenziali reali del database durante l'installazione.

### Hardening
- **Requisito Minimo elevato a PHP 8.4:** `composer.json` e `config/installer.php` dichiarano ora `^8.4` / `8.4.0` come versione minima richiesta, allineati al nuovo target della piattaforma. Aggiornati anche `.env.example` (worker di coda per hosting condivisi, credenziali database non più commentate per evitare la duplicazione sopra) e i riferimenti PHP nella documentazione (README, roadmap).
- **Worker Coda su Hosting Condivisi:** Aggiunta la variabile `SCHEDULE_QUEUE_WORKER` a `.env.example`, documentando esplicitamente quando lo scheduler deve processare la coda in modalità sincrona (hosting condivisi senza Supervisor) rispetto a un worker dedicato (VPS/Plesk).

> **Nota di rilascio:** l'aggiornamento delle dipendenze Composer (`composer update` per allineare `composer.lock` alla nuova piattaforma PHP 8.4) è pianificato subito prima della pubblicazione di questa beta.

---

## [1.10.0-beta.2] - Estratto Conto Anagrafica PDF, Mobile UX & Breadcrumbs Dinamiche

### Aggiunto
- **Stampa Estratto Conto Anagrafica (PDF):** Aggiunta la generazione del nuovo documento ufficiale di "Estratto Conto" per singolo condòmino, richiamabile dalla vista Piani Rate. Il PDF include un'intestazione premium, la lista delle unità immobiliari associate, un cruscotto riepilogativo dei saldi (iniziale, addebiti, versamenti, saldo finale) e una tabella cronologica (timeline) dettagliata e formattata con tutti i movimenti e operazioni registrati per l'anagrafica nell'esercizio di riferimento, applicando il motore a partita doppia "penny-perfect" per il calcolo del saldo progressivo.
- **Burger Menu Intelligente su PageHeaderGuide:** Migliorata drasticamente la UX mobile del gestionale introducendo la modalità "Burger Menu" (Menu a tendina) nell'header di pagina standard. Su smartphone, i bottoni d'azione in esubero (es. Indietro, Pulsanti Guida) vengono automaticamente collassati sotto un unico pulsante espandibile "Opzioni", prevenendo overflow orizzontali e ammassamenti indesiderati di pulsanti.

### Corretto
- **Fix Ritorno Dinamico Breadcrumb:** Sostituita la precedente logica di generazione statica del link "Torna al Piano Rate" all'interno della vista *Estratto Conto Anagrafica*. Il sistema intercetta la cronologia d'accesso (tramite Inertia `window.history.state.back`) restituendo sempre il condòmino al piano corretto (generale o straordinario) da cui era partito, estirpando definitivamente falsi errori 404 in scenari multi-contesto.
- **Risoluzione Tipografica PDF:** Uniformata la formattazione dei campi contabili nel layout PDF del documento di Estratto Conto. Il simbolo della valuta (€) viene ora coerentemente stampato davanti all'importo in tutte le colonne (Dare, Avere, Saldi intermedi) anziché appeso alla fine.
- **Formattazione Piè di Pagina nei PDF:** Risolto un problema di rendering per cui gli "a capo" (invio) e gli spazi multipli inseriti nella "Nota Legale / Footer" non venivano rispettati nei PDF generati. La formattazione ora ricalca fedelmente l'input utente.

---

## [1.10.0-beta.1] - Piani Straordinari Misti, Paginazione Stampe PDF e Logica "Catch-all"

### Aggiunto
- **Pannello Impostazioni Cron & Heartbeat:** Aggiunta una nuova vista dedicata (`ImpostazioniCron.vue`) sotto `Impostazioni > Sistema > Automazioni`. La pagina espone un widget visivo "Heartbeat / Cron System" che indica in tempo reale lo stato di salute dello Scheduler di sistema. Se il pallino è verde, il server sta processando regolarmente i job in background; include inoltre un timestamp "Ultimo battito" per un controllo millimetrico.
- **Stampa Riparto Bilancio per Capitolo e Soggetto:** Introdotto il nuovo documento di riparto PDF accessibile dal piano rate. La stampa raggruppa analiticamente le spese ripartite per **Capitoli di spesa**, mostrando le quote e gli importi precisi distribuiti per ogni unità e soggetto coinvolto.
- **Paginazione Automatica Stampe PDF (Chunking):** Implementata la paginazione orizzontale dinamica per i prospetti in formato PDF (Riparto per Tabelle e Riparto per Capitoli). Se il piano dei conti o le tabelle millesimali superano la larghezza massima (oltre 5-6 colonne), il sistema suddivide automaticamente le voci su più pagine (`<pagebreak />`). Questo garantisce la massima leggibilità dei font evitando documenti illeggibili per condomini molto grandi.
- **Supporto Piani Rate Straordinari Misti:** Estesa la logica di calcolo (`RipartoCapitoliService` e `RipartoTabelleService`) per i Piani Straordinari. È ora possibile aggregare in un unico documento sia i costi derivanti dalle **fatture collegate** sia quelli provenienti da **capitoli di spesa aggiunti manualmente**.
- **Docker Supervisord per Scheduler Locale:** Aggiunto il demone `laravel-scheduler` in `docker/supervisord.conf` per l'avvio automatico di `schedule:work` all'interno dell'ambiente Docker, essenziale per lo sviluppo e il test locale dell'heartbeat.
- **Test Automatizzati (Riparto Preventivo):** Implementata una suite di test (Pest/PHPUnit) per certificare e bloccare eventuali regressioni del motore di riparto, testando rigorosamente la quadratura dei calcoli e il corretto funzionamento della logica Raccoglitore (catch-all) nei Piani Rate Generali.
- **Guida Plesk per Cron Job:** Redatta la documentazione ufficiale `plesk_cronjob_setup.it.md` per la corretta configurazione di Scheduler e Queue Worker in ambienti Plesk, con indicazioni per disabilitare il flag `SCHEDULE_QUEUE_WORKER` ed evitare Error 500 causati dai rigidi timeout web.

### Corretto
- **Logica "Catch-all" (Raccoglitore):** Ottimizzato il comportamento dei Piani Rate Generali. Ora il sistema esclude in automatico tutte le voci di spesa (`conti_id`) che sono già state intercettate da altri Piani Rate Parziali attivi. Il Piano Generale si comporta quindi come un contenitore per tutte le "voci orfane" rimaste da coprire, prevenendo la doppia riscossione.

---

## [1.9.1] — Smart Treasury & Passive Cycle

> **Stable release.**
> Ciclo passivo completo, pagamento delle fatture, storno pagamenti (Ledger immutabile), widget Treasury Guardian, motore a cascata per usufruttuari, gestione unità vuote (scoperti documentati) e suite completa di stampe PDF ufficiali (Riparti e Scadenziari).
>
> 12 beta release (beta.1 → beta.12) prima della stable.

---

### [1.9.1-beta.12] - Restyling Tabelle & Sicurezza Dati

### Miglioramenti UI/UX
- **Restyling Lista Tabelle Millesimali:** L'elenco delle tabelle è stato completamente riprogettato per migliorare leggibilità e densità delle informazioni.
  - Sostituite le icone generiche con icone colorate specifiche per ogni tipologia (Ascensore, Scale, Lastrico, Riscaldamento, ecc.).
  - Fusa la colonna Palazzina/Scala in un'unica colonna "Palazzina / Scala". Per le tabelle generali, compare un chiaro badge verde "INTERO CONDOMINIO".
  - Nuova colonna **Unità Associate**: mostra il conteggio esatto delle proprietà associate alla tabella.
  - Nuova colonna **Stato**: mostra con un indicatore visivo se una tabella è "In uso" (associata a voci di spesa) oppure "Orfana".

### Hardening
- **Prevenzione Cancellazione Tabelle in Uso:** Inserito un blocco sia frontend (alert giallo informativo immediato) che backend (`destroy`) che impedisce l'eliminazione accidentale di tabelle millesimali qualora siano già state associate a voci di spesa, proteggendo l'integrità dei dati storici.

### Corretto
- **Falla Matematica nel Riparto per Tabella:** Risolto un grave bug logico in `RipartoTabelleService` che alterava il calcolo delle percentuali e quote di ripartizione. La funzione divideva erroneamente il peso dell'anagrafica per l'importo monetario (`$importo`), causando quote sballate e totali incoerenti sul documento di stampa. Il calcolo ora accumula correttamente i pesi puri prima della distribuzione proporzionale "penny-perfect".
- **Bug Creazione Tabella (`undefined variable $tabella`):** Risolto un crash nel controller che si verificava qualora il salvataggio a database di una nuova tabella andasse in errore. Il `catch` ora esegue un fallback pulito tramite `back()` ripristinando il modulo e mostrando l'errore, invece di tentare un redirect su risorsa inesistente.
- **Dinamismo Intestazioni PDF:** Sistemate le intestazioni dinamiche dei PDF dei riparti, che ora riportano l'esatta unità di misura della tabella considerata (`mill. ‰`, `quote`, `pers.`, `kW`, `mc.`).

---

### [1.9.1-beta.11] - Allineamento Layout Tabelle & Hotfix Modale Sottoconti & Riparto per Tabella

### Aggiunto
- **Stampa Riparto Bilancio Preventivo per Tabella × Soggetto:** Introdotto il documento di riparto più dettagliato della suite stampe. Accessibile dal piano rate con il pulsante "Riparto per Tabella", genera un PDF landscape con formato adattivo (A4 fino a 5 tabelle, A3 oltre) che mostra per ogni unità immobiliare e per ogni soggetto (Proprietario, Inquilino, Usufruttuario, Nuda Proprietà, Comodatario) la quota millesimale e l'importo ripartito su ciascuna tabella millesimale configurata nel piano dei conti. Il documento include: intestazione premium con pillole riepilogative (n° unità, soggetti, totale €), barra sommario per tipo soggetto con percentuali, accent color per ruolo su ogni riga, colonna percentuale sul totale condominio, indicazione del piano per ogni appartamento, riferimento delibera e verbale assembleare (se compilati), riga totali con sfondo navy, legenda ruoli e note legali (art. 1123 c.c.). Il calcolo distribuisce gli importi reali delle rate emesse in proporzione ai pesi delle tabelle millesimali configurate, rispettando la cascata di risoluzione del ruolo del soggetto (identica al motore `CalcoloQuoteService`). Nessuna migration necessaria.

### Miglioramenti UI/UX
- **Allineamento Layout Pagine Tabelle:** Le pagine `TabelleNew`, `TabelleEdit` e `QuoteList` sono state completamente ridisegnate per aderire al design system uniforme del gestionale. Ogni pagina presenta ora il componente `PageHeaderGuide` con guide contestuali, form organizzati in sezioni Card con bordo tratteggiato (`border-dashed`) e bottoni di azione in fondo al form con tipografia `uppercase tracking-widest`.
- **Lista Tabelle — Colonna Denominazione Arricchita:** La colonna "Denominazione" nella lista delle tabelle millesimali mostra ora un layout "ricco" con icona colorata, titolo, nota e Call-To-Action "Gestione Quote →". Cliccando il nome della tabella si accede direttamente alla pagina di gestione dei millesimi (`QuoteList`), rendendo superfluo il passaggio per il menu a tendina.

### Corretto
- **Regressione Bug Modale Sottoconti — Quota Proprietario sempre al 100%:** Risolto un bug di race condition nel `ModalModificaConto`. Dopo un salvataggio, `resetForm()` impostava `percentuale_proprietario = 100`. Se l'utente riapriva il **medesimo** sottoconto (stessa reference Vue), il `watch(props.conto)` non si riattivava lasciando il campo bloccato a 100%. Corretto estraendo la logica di idratazione in `populateFormFromConto()` e richiamandola esplicitamente anche nel `watch(props.show)` a ogni apertura del modale.
- **TS Error TabelleEdit — `options` su v-select:** Corretti due errori TypeScript (TS2740) per cui `condominio.palazzine` e `condominio.scale` venivano passati come `options` alla `v-select` anziché le props dirette `palazzine` e `scale`.
- **TS Errors QuoteList — Indicizzazione `form.errors` e tipo indice:** Corretti due errori TS7053 sull'accesso dinamico a `form.errors` con chiavi template-literal (es. `` `quote.${idx}.valore` ``) tramite cast a `Record<string, string>`. Corretto inoltre un errore TS2345 sul tipo dell'argomento `index` in `removeImmobile` (ora accetta `number | string` con conversione esplicita).

### Hardening
- **Compatibilità PHP 8.4 e 8.5 (Configurazione Database):** Sostituito il controllo statico della versione PHP (`PHP_VERSION_ID`) nel file `config/database.php` con un controllo dinamico e retro-compatibile tramite `defined('\Pdo\Mysql::ATTR_SSL_CA')`. Questo elimina definitivamente il *deprecation warning* (`Constant PDO::MYSQL_ATTR_SSL_CA is deprecated`) che compariva su console durante il `composer install` sui server che utilizzano già PHP 8.4, garantendo aggiornamenti silenziosi e senza interruzioni.

---



### [1.9.1-beta.10] - ScopertoWarning & Coerenza Ruoli

### Aggiunto
- **Componente UI ScopertoWarning:** Inserita interfaccia che rileva se in alcune unità mancano i soggetti attivi per il riparto (es. inquilini) e calcola in tempo reale gli scoperti. 
- **Salvataggio Motivazione Scoperti:** Per poter forzare e generare il riparto addossando gli scoperti, l'amministratore deve inserire una nota obbligatoria (> 10 caratteri) che verrà persistita e mostrata storicamente come banner sul piano rate generato.
- **Risoluzione a Cascata:** Migliorata la tracciabilità della cascata di calcolo. Invece di segnalare un generico "cascata esaurita", il sistema espone esattamente il `ruolo_richiesto` mancante (es. usufruttuario o inquilino), arricchito dai nomi effettivi di Immobile e Conto.
- **Eccezione Silenziosa e Gatekeeper:** Introdotta `ScopertiNonAccettatiException` che blocca in sicurezza la logica del controller avvisando il frontend, ignorata volutamente da Sentry e loghi di sistema.
- **Task Inbox Automatico per Scoperti Documentati:** Quando l'amministratore accetta forzatamente uno scoperto e inserisce la motivazione, il sistema crea automaticamente un task prioritario nell'Admin Inbox con titolo "Quote non assegnate — [nome piano]", importo scoperto e istruzioni operative. Il task rimane aperto nel widget inbox della dashboard finché non viene chiuso manualmente, rendendo impossibile dimenticarsi dell'unità orfana.
- **Widget Copertura Bilancio — Nuovo stato "SCOPERTO DOCUMENTATO":** Introdotto un quarto stato `documented` nel Validatore Budget della dashboard. Se il buco di copertura è stato documentato consapevolmente (nota_scoperti presente), il widget mostra ora il badge neutro slate **SCOPERTO DOCUMENTATO** al posto del generico allarme ambra **FABBISOGNO SCOPERTO**. La logica garantisce che eventuali nuove spese inserite a posteriori abbiano la priorità, facendo riaccendere l'allarme ambra finché non vengono rateizzate.

### Risolto
- **DashboardController QueryException:** Risolto bug (Internal Server Error) nel `DashboardController` che tentava di filtrare i `piani_rate` tramite la colonna inesistente `esercizio_id` invece di passare per le `gestioni` collegate all'esercizio.
- **Terminologia widget rinominata:** "SOTTO COPERTURA" → **"FABBISOGNO SCOPERTO"** per aderenza al lessico contabile, eliminando l'ambiguità del termine precedente che evocava contesti investigativi piuttosto che amministrativi.

### Hardening
- **Compatibilità PHP 8.4 (Auto-Update Engine):** Inserito un fix preventivo nel bridge di installazione (`index.php`) per gestire l'impostazione errata `session.gc_divisor = 0` riscontrata su alcuni hosting condivisi. A differenza di PHP 8.3 che emetteva solo un warning, PHP 8.4 lancia una `ValueError` irreversibile. Il sistema intercetta ora questa configurazione e la neutralizza a runtime forzando `session.gc_divisor = 1000`, evitando crash silenti durante gli aggiornamenti over-the-air.

---

### [1.9.1-beta.9] - Hotfix UI Piano dei Conti & Action Inbox Upgrade + Completamento Controller Pagamenti

### Aggiunto
- **Compliance Alert (Art. 1130 c.c.):** Aggiunto un banner giallo di avvertimento non bloccante in `FatturaRegisterNew`, `FatturaRegisterEdit`, `PagamentoNew` e `PagamentoEdit` se l'amministratore tenta di registrare un movimento con una data antecedente a 30 giorni rispetto a oggi. L'avviso educa e responsabilizza, senza impedire l'operatività.
- **Admin Inbox — Conteggio Giorni Sospeso:** La inbox globale dell'amministratore e il widget nella dashboard condominio mostrano ora i giorni esatti di ritardo (es: "SCADUTO DA X GIORNI") calcolati in tempo reale rispetto alla data di scadenza delle operazioni, migliorando drasticamente la percezione dell'urgenza e del tempo trascorso.
- **Endpoint dettaglio pagamento fornitore:** Aggiunto il metodo `show()` in `PagamentoFornitoreController` con guard di appartenenza al condominio, eager loading completo delle relazioni (fornitore, conto, scrittura con righe e fatture allocate) e rendering Inertia verso la pagina `PagamentoShow`. La route `GET /pagamenti-fornitori/{pagamento}` è ora registrata in `gestionale.php` (`pagamenti-fornitori.show`), completando la mappa CRUD del modulo pagamenti.
- **Prop `size` su `ConfirmDialog`:** Il componente condiviso `ConfirmDialog.vue` supporta ora una prop `size` con quattro taglie (`sm`, `md`, `lg`, `xl`) che sovrascrive la larghezza di default `max-w-lg` tramite `cn()`. Il valore di default rimane `md` — nessun impatto sugli oltre 20 utilizzi esistenti.
- **F24 Refactor — `SyncF24WithPagamento` listener:** Creato il listener `SyncF24WithPagamento` (auto-discovery via `subscribe()`, `$afterCommit = true`) che crea il task "F24 Ritenuta" nell'Admin Inbox al momento del **pagamento** effettivo, non della registrazione fattura. La scadenza è calcolata al 16 del mese successivo a `data_pagamento`, spostata al lunedì se cade di weekend. Il listener è idempotente (`updateOrCreate`) con guard su `importo_ritenuta <= 0` e su record di storno (`pagamento_padre_id !== null`).
- **Pagina dettaglio pagamento fornitore (`PagamentoShow`):** Creata la pagina Vue `PagamentoShow.vue` con layout `GestionaleLayout`, `PageHeaderGuide`, riepilogo importi (lordo, netto, ritenuta, commissioni), partita doppia della scrittura collegata, fatture saldate con link click-through e pulsante "Distinta PDF".
- **Link dettaglio nel dropdown lista pagamenti:** Aggiunta voce "Vedi dettaglio" nel `DataTableRowActions.vue` che naviga a `PagamentoShow`. La precedente voce "Dettaglio scrittura" è rinominata "Dettaglio scrittura contabile" e spostata in posizione secondaria.

### Miglioramenti UI/UX
- **Modal "Ratifica assembleare — Sforo motivato" allargato:** Il `ConfirmDialog` di approvazione sforo usa ora `size="lg"` (`max-w-2xl`) per dare respiro al testo legale.
- **Dicitura modal Approva Sforo riscritta (Feature 3, Art. 1135 c.c.):** Il testo del modal e del tooltip badge "⚠ Ratifica richiesta" è stato riscritto per coprire esplicitamente i due scenari previsti dall'art. 1135 c.c.: spesa già deliberata in assemblea (con rif. verbale) e pagamento d'urgenza dell'amministratore (con motivazione obbligatoria). Nessun campo nuovo, nessuna colonna, nessun JSON aggiunto.
- **Case uniformato:** Titoli e testi dei modali seguono ora sentence case invece di title case.

### Corretto
- **Bug Creazione Password:** Risolto un bug critico che impediva ai nuovi utenti invitati di impostare la propria password. I nuovi utenti ricevono ora un campo password `null` anziché una stringa casuale, permettendo al sistema di distinguere il primo accesso e reindirizzando al login con messaggio specifico solo a password effettivamente impostata.
- **Link Inviti Scaduti:** Aggiunta gestione UX esplicita (messaggio flash) e ripristinato il controllo di sicurezza (`hasValidSignature()`) sui link scaduti e/o alterati per la creazione password e reinvito.
- **`SyncScadenziarioWithFattura` — codice morto rimosso:** Eliminato il blocco commentato (42 righe) che creava il task F24 al momento della fattura. La logica corretta vive ora in `SyncF24WithPagamento`.
- **Voce menu "Dettaglio scrittura contabile" appariva disabilitata:** Il `class="text-slate-500"` sul `DropdownMenuItem` causava un aspetto grigio identico allo stato `:disabled`. Rimosso.

### Aggiunto
- **Impostazioni Stampe PDF:** Aggiunto un nuovo pannello di configurazione globale dedicato alle stampe. È ora possibile definire una Nota Legale (es. professione esercitata ex l. 4/2013, P.IVA, Polizza RC) che apparirà come piè di pagina in tutti i prospetti generati.
- **Firma Amministratore:** Implementata la possibilità di caricare l'immagine della firma dell'amministratore (in formato PNG o JPG), che verrà apposta automaticamente in calce ai documenti ufficiali come rendiconti e prospetti rate.
- **Filtro Condominio su Action Inbox Admin:** Aggiunto un dropdown nella barra di navigazione superiore per filtrare la lista dei task per singolo condominio. Le KPI card (Scaduti, Verifiche incassi, Ticket, Totale) si aggiornano dinamicamente in base al condominio selezionato.
- **InfiniteScroll su Action Inbox Admin:** La paginazione statica è stata sostituita con il caricamento infinito (`Inertia::scroll`), coerentemente con il widget nella dashboard del gestionale.

### Corretto
- **Bug critico — Pulsante "Risolvi" inerte su Action Inbox Admin:** Il tasto "Risolvi" non eseguiva alcuna azione su task privi di `action_url` perché la funzione `completeTask()` era assente dalla pagina admin. La pagina gestionale (widget dashboard) era già corretta; la pagina admin `/admin/inbox` non era mai stata allineata. Ora il pulsante ✅ (completa task) è sempre visibile per tutti i task; il link "Risolvi →" appare in aggiunta solo se è presente un `action_url`.
- **Conteggi KPI non filtrati:** Le card dei conteggi nella Action Inbox admin mostravano sempre i totali globali anche selezionando un condominio specifico. Il controller ora ricalcola i conteggi filtrati per condominio direttamente nel backend quando `condominio_id` è specificato.
- **Dropdown Capitoli Padre:** Risolto un bug nell'interfaccia di inserimento e modifica dei conti per cui il menu a tendina "Capitolo padre" non si aggiornava istantaneamente dopo la creazione di un nuovo capitolo, costringendo l'utente a ricaricare la pagina. Ora la cache del componente si invalida e si sincronizza automaticamente al salvataggio.
- **Modifica Ripartizione Proprietario a 0%:** Risolto un bug nel modale di modifica dei sottoconti che forzava visivamente la quota del proprietario al 100% in apertura, ignorando il salvataggio legittimo di una quota pari allo 0% (es. per spese totalmente a carico dell'inquilino).
- **Rimozione Errori Validazione Dinamici:** Risolto un problema di usabilità nel modale di creazione e modifica dei conti in cui gli errori rossi di validazione per i campi "Tabella Millesimale" e "Capitolo Padre" rimanevano visibili anche dopo che l'utente aveva selezionato un valore valido dalla tendina. Gli errori ora scompaiono in tempo reale al variare della selezione.
- **Causale Bonifico Parlante:** Risolto un fatal error durante la registrazione di un pagamento con bonifico parlante causato da una chiamata a un metodo inesistente nell'Enum delle detrazioni. La causale bancaria fiscale viene ora generata correttamente e troncata entro i limiti SEPA.
- **Formattazione Data Modale Approvazione:** Risolto un bug nella modale di approvazione del piano rate che forzava la selezione della data in formato americano (yyyy-mm-dd) anziché italiano.
- **Falso 403 su Pagamenti in Hosting Condivisi:** Risolto un bug critico (Accesso negato / La scrittura non appartiene a questo condominio) che impediva il download della distinta e lo storno dei pagamenti su server di produzione che non utilizzano `mysqlnd`. Introdotto il cast esplicito a `integer` nei modelli Eloquent per garantire la corretta validazione dei permessi.

### Miglioramenti UI/UX
- **Redesign Action Inbox Admin:** Refactoring completo del layout della pagina `/admin/inbox` per allinearlo al design system del gestionale. Rimosso l'header hero scuro; adottato il pattern standard `px-6 py-8` con label + `h1 font-black`. Le 4 card filtro seguono ora lo stile delle KPI card della dashboard admin (icona decorativa in background, footer con freccia, bordo dinamico colorato). Il filtro condominio è integrato nel top-bar a destra accanto al pulsante "Dashboard".
- **Ordinamento Task per Urgenza:** I task scaduti appaiono sempre in cima alla lista, seguiti dai futuri in ordine cronologico crescente, indipendentemente dal filtro attivo.
- **Uniformità Pulsanti Azioni:** Tutti i pulsanti icona (✅ Completa, ✗ Rifiuta) seguono lo stesso pattern visivo (`w-8 h-8 rounded-md border shadow-sm`). I pulsanti testuali (Registra →, Risolvi →) usano lo stesso container `h-8 px-3 border bg-white`.
- **Dettaglio Piano Rate:** Ottimizzata l'interfaccia della pagina. I pulsanti della barra delle azioni diventano a scomparsa testuale (solo icona) sugli schermi dei portatili per evitare scorrimenti orizzontali. Integrato il nuovo header guida con breadcrumb unificate e spostato il badge della data di delibera in un comodo tooltip interattivo per risparmiare spazio verticale.

---

### [1.9.1-beta.8] - Modulo Commenti per Segnalazioni Guasto

### Aggiunto
- **Nuovo Modulo Commenti per le Segnalazioni Guasto**: Aggiunta la possibilità per amministratori, condòmini e fornitori di comunicare direttamente all'interno della singola segnalazione guasto.
- **Forza Moderazione Commenti**: Nuova impostazione globale per obbligare tutti i commenti degli utenti standard e fornitori all'approvazione obbligatoria dell'amministratore, ignorando i permessi di auto-pubblicazione.
- **Sicurezza e Permessi per i Commenti**: Isolamento completo dei ruoli con controlli severi. Gli amministratori possono moderare o nascondere commenti, mentre gli utenti standard e fornitori possono aggiungere, modificare o cancellare esclusivamente i propri commenti relativi ai propri condomini.
- **Notifiche in Tempo Reale**: Integrazione di notifiche automatiche in app e via email ogni volta che viene aggiunto o aggiornato un commento sulla segnalazione in carico.

### Miglioramenti Tecnici
- **Inbox Centralizzata e Polimorfismo**: Ristrutturazione di `ActionInboxController` e `InboxService` per la gestione dinamica delle azioni utente. Introdotto un costruttore universale (`createTask()`) basato sull'enum `EventoTipo`.
- **Resilienza delle Migrazioni (Windows/Shared Hosting)**: Aggiunto `set_time_limit(0)` e pattern di cleanup idempotente (`cleanupPartialMigration()`) alle migrazioni pesanti per prevenire blocchi dovuti a timeout PHP.
- **Sicurezza Infrastruttura di Testing**: Introdotto un fail-safe globale in `TestCase.php` che blocca l'esecuzione accidentale dei test sul database reale, imponendo l'uso di SQLite in-memory.
- **UI/UX Inbox**: Integrazione delle icone dinamiche (basate sul tipo di evento) nel widget e nella Action Inbox del gestionale.

### Importante
- **Nota per gli sviluppatori**: Dopo aver aggiornato il codice all'ultima versione e aver eseguito le migrazioni, è necessario lanciare il comando `php artisan db:seed --class=RolesAndPermissionsSeeder` per generare a database i nuovi permessi inseriti a sistema.

### Corretto
- **Falsi positivi Pendenze Utente (Rata Zero & Rimanenze)**: Risolto un bug critico che manteneva visibili le rate nella dashboard del condòmino anche dopo il saldo. Il sistema ora contrassegna automaticamente come pagate (`status = 'paid'`) le quote inziali a zero o in credito puro (es. Rata Zero azzerata) fin dal momento della loro emissione, evitando che restino perennemente nello scadenziario.
- **Prevenzione Segnalazioni a Zero**: Inserito un blocco frontend e backend che impedisce ai condòmini di segnalare un pagamento per rate con importo rimanente pari a zero, sostituendo il pulsante con un messaggio informativo di "Nessun pagamento richiesto".

---

### [1.9.1-beta.7] - Filtri Interattivi, Chiarezza Visiva e Tracciabilità UI

### Aggiunto
- **Filtri Interattivi sulle Card (Smart Stats)**: Le card riepilogative nella lista pagamenti ("Con Ritenuta d'Acconto" e "Operazioni Stornate") sono diventate interattive. Cliccandole, applicano o rimuovono istantaneamente il filtro corrispondente sulla tabella dati sottostante (`has_ritenuta` o `stato=stornato`), velocizzando la ricerca in elenchi molto corposi.
- **Allineamento Globale UI Dashboard**: L'interfaccia interattiva delle card statistiche (Smart Stats) è stata estesa alle viste "Fatture Passive" e "Incassi Rate", garantendo totale coerenza visiva e logica. Le card per filtrare "Fatture Aperte", "Da Ratificare" (Fatture) e "Stornati" (Incassi) adottano ora lo stesso design system con highlight ring attivi (`ring-2`) e gestiscono dinamicamente lo state di disabilitazione.
- **Integrazione Audit Ratifica in Dettaglio Scrittura**: Inclusa la visibilità delle note di audit (Art. 1135 c.c.) all'interno della vista di dettaglio della Scrittura Contabile, permettendo ai revisori di vedere l'intero ciclo di vita e la giustificazione legale dell'approvazione spesa.
- **Infrastruttura Documentale PDF Nativa**: Installata e integrata la libreria `mpdf/mpdf` per la generazione lato server di documenti PDF complessi (senza dipendenze esterne come Node o Chrome).
- **Distinta di Pagamento Fornitore (PDF)**: Creazione di layout e stili master per PDF (su formato A4) e implementazione del download della Distinta di Pagamento, completa di causale, totali e informazioni sul bonifico parlante.
- **Visualizzazione Dettaglio Incasso Rate**: Aggiunta la vista in sola lettura per esplorare analiticamente la composizione di un incasso rata, evidenziando se pagato tramite versamento contanti/bonifico o compensazione del credito.
- **Ottimizzazione Tooltip Tabelle**: Sostituiti i tooltip testuali nativi con HoverCard interattivi (Shadcn) per una lettura immediata e ricca del dettaglio importi nelle tabelle Fatture Passive e Pagamenti Fornitori.
- **Stampe Scadenziario e Riparti (PDF)**: Rilasciata la suite di stampe ufficiali per i piani rate (Scadenziario / Prospetto Rate) e per il piano dei conti (Distinta Preventivo e Ripartizione Spese). I layout includono intestazioni con riferimenti legali e design "printer-friendly" (su formati A4 Portrait/Landscape).
- **Scelta Multipla Aggregazione PDF**: L'amministratore ora può decidere dinamicamente dal menu a discesa se esportare il Prospetto Rate raggruppandolo "Per Condòmino", "Per Unità Immobiliare", o combinando entrambe le viste in un unico documento PDF multi-pagina. I totali del documento "Per Condòmino" riflettono esattamente la logica della UI aggregando automaticamente la somma degli immobili appartenenti allo stesso proprietario.
- **Test di Integrità e Quadratura PDF**: Integrata una test suite completa (Pest) dedicata ai controller di stampa che garantisce incroci perfetti: ogni importo mostrato sui PDF (es. calcolo totale da preventivo, esclusione di conti tecnici, raggruppamento anagrafiche) deve combaciare rigorosamente con i totali presenti a database, bloccando preventivamente eventuali disallineamenti di stampa.
- **Fix Sicurezza URL Firmati**: Sostituito l'indirizzo email con l'ID utente nei link crittografati per inviti e reset password, risolvendo definitivamente un errore 403 causato dalla decodifica automatica del carattere `@` da parte di browser e client email in Laravel 11.
- **Miglioramento UX Scadenza Link**: Estesa la validità dei link per l'accettazione degli inviti e per la creazione della password da 60 minuti a 3 giorni, offrendo più tempo agli utenti per completare la registrazione.
- **Ottimizzazione UI Piano dei Conti**: Raggruppati i pulsanti di stampa (Distinta Base e Ripartizione) all'interno di un unico menu a tendina "Stampe" per preservare lo spazio e mantenere il layout pulito su schermi piccoli.

### Refactoring & Ottimizzazioni
- **PSR-4 Compliance Exceptions**: Eseguito un refactoring architetturale delle eccezioni di dominio dei pagamenti. Diviso il macro-file `Exceptions.php` in 10 file di eccezione singoli e rimosso l'autoload manuale da `composer.json`, risolvendo in modo definitivo i warning dell'autoloader e rispettando gli standard PSR-4.

### Corretto
- **Ambiguità Visiva tra Originali e Storni**: Risolta la confusione causata dalla sovrapposizione visiva degli stati. I pagamenti che sono stati annullati mostrano ora il badge "Originale Stornato" barrato, mentre i nuovi movimenti di compensazione (le operazioni di storno vere e proprie) mostrano il badge "Storno Confermato". Solo sui normali pagamenti confermati appare l'opzione "Storna pagamento" nel dropdown.
- **Workflow Modale di Successo**: Dopo la registrazione di un pagamento (PagamentoNew), il pulsante "Torna all'elenco" reindirizza ora correttamente alla lista dei pagamenti (`gestionale.pagamenti-fornitori.index`) invece di rimandare all'elenco fatture, garantendo continuità operativa.
- **Hardening UI su Testi Estesi**: Risolto un bug di rendering nella vista Scrittura Contabile (`Show.vue`) che causava l'overflow e la sovrapposizione del layout in presenza di stringhe senza spazi lunghe (es. IBAN) o di testi descrittivi prolissi (note di audit). Aggiunti container con troncamento, interruzione parola e scrollbar verticale limitato in altezza (`max-h-32 overflow-y-auto`).
- **Allineamento UI Documenti**: Uniformato il design delle pagine di caricamento e modifica documenti fornitori (`DocumentiNew.vue`, `DocumentiEdit.vue`) allo standard del gestionale, sostituendo i layout sparsi con componenti coerenti (`AppLayout`, `PageHeaderGuide`).
- **Ottimizzazione Tabelle Documenti**: Risolto un `RangeError` sul formato date in ambiente di produzione, aggiunta la traduzione mancante dei badge di visibilità (Pubblico/Privato) e rinominata la colonna 'Data' in 'Caricato il' per maggiore chiarezza per l'utente finale (`columns.ts`).
- **Visualizzazione Allegati Fatture Esistenti**: Corretto un problema nella vista Dettaglio Fattura (`FatturaShow.vue`) che impediva la corretta visualizzazione del nome e della dimensione dei documenti allegati a causa di proprietà disallineate col modello dati.
- **Upload Allegati Nuove Fatture**: Risolto un bug critico in fase di registrazione nuova fattura (`FatturaRegisterNew.vue`) che causava la perdita del documento allegato durante l'invio del modulo a causa di una conversione JSON che distruggeva l'oggetto File prima del passaggio al backend.
- **Autocompilazione Pagamenti da Inbox Operativa**: Risolto un problema per cui cliccando 'Risolvi' su un task di pagamento dalla Inbox Operativa il modulo di pagamento non veniva precompilato. Il sistema ora deduce automaticamente il fornitore a partire dalla fattura passata nell'URL (`PagamentoFornitoreController`).

---

### [1.9.1-beta.6] - Storno Pagamenti e Ledger Immutabile
### Aggiunto
- **Storno Pagamenti (Ledger Immutabile)**: Modulo completo (backend e UI) per l'annullamento di pagamenti errati o respinti (es. insoluti bancari). Il sistema garantisce l'integrità contabile registrando una scrittura inversa append-only, riaprendo automaticamente le fatture coinvolte e ripristinando la cassa, senza cancellare record storici.
- **Storni Cross-Esercizio**: Gestione intelligente degli storni su bilanci chiusi. Se l'esercizio del pagamento originale è chiuso, il sistema non permette la modifica retroattiva ma registra l'operazione di storno nell'esercizio corrente aperto, salvaguardando i saldi storici consolidati.
- **Sincronizzazione Action Inbox e Pagamenti**: Implementato un nuovo listener (`SyncScadenziarioWithPagamento`) che collega la registrazione e lo storno dei pagamenti ai task amministrativi dell'Inbox. La registrazione di un pagamento ora segna automaticamente come completato il task "Pagare fornitore", rimuovendolo dalle urgenze dell'amministratore, mentre uno storno lo riapre immediatamente, ripristinandone la priorità. 
- **UX Ottimizzata Action Inbox**: Risolvendo il task di pagamento dall'Inbox, l'utente viene reindirizzato automaticamente al modulo di registrazione pagamento pre-compilato, azzerando i tempi di ricerca e garantendo un'esperienza fluida.
- **Test Automatici Contabili**: Aggiunta un'ampia suite di test automatici per le logiche di storno avanzato, inclusi storni di pagamenti cumulativi multi-fattura e storni complessi compensati con Note di Credito (netting), garantendo quadratura perfetta DARE/AVERE, oltre a test automatizzati per il ciclo di vita dei task nell'Admin Inbox.

### Corretto
- **Compatibilità SQL Strict Mode (`ONLY_FULL_GROUP_BY`)**: Risolto l'errore 1055 nel modulo Treasury Guardian che bloccava la dashboard su server MySQL 8.0 o configurazioni strict. Il refactoring elimina i `GROUP BY` manuali, delegando il calcolo delle allocazioni a Laravel tramite aggregazioni Eloquent (`withSum`).

---

### [1.9.1-beta.5] - Treasury Guardian Widget MVP
### Aggiunto
- **Treasury Guardian Widget MVP**: Implementato il nuovo widget predittivo di tesoreria nella dashboard. Il sistema calcola automaticamente la proiezione dello scoperto di liquidità a 30 giorni, fornendo una classificazione del rischio (Verde, Giallo, Rosso) basata sulle fatture in scadenza e le rate emesse.
- **Call-to-Action Dinamiche (Smart UX)**: Le azioni suggerite si adattano ora al contesto di cassa. Il widget suggerisce di "Emettere Nuove Rate" in caso di esposizione al rischio senza incassi attesi, e di "Verificare o Sollecitare Incassi" se ci sono versamenti potenzialmente non registrati, con descrizioni leggibili (multi-line).

### Corretto
- **Quadratura Liquidità e Saldi Iniziali**: Risolto un disallineamento tra il calcolo del widget e il bilancio di verifica. Il motore ora somma correttamente il `saldo_iniziale` di cassa ai movimenti contabili di liquidità.
- **Calcolo Esatto degli Incassi Attesi**: Corretto l'algoritmo di stima degli incassi (rate in arrivo). Il sistema ora estrae esclusivamente i movimenti in AVERE (pagamenti ricevuti) evitando sovrastime derivanti dall'emissione in partita doppia, garantendo una stima predittiva perfetta al centesimo.
- **Statistiche Ritenute d'Acconto**: Corretto un problema statistico nella dashboard dei pagamenti che manteneva a zero il conteggio delle ritenute d'acconto. Il sistema calcola ora la ritenuta proporzionalmente al momento della registrazione del pagamento.
- **Action Inbox per Piani Straordinari & Sync**: Esteso il supporto della Action Inbox ai Piani Rate Straordinari (generazione immediata task di emissione rate e verifica incassi) e risolto un bug che non eliminava i vecchi eventi in caso di rigenerazione totale di un piano rate approvato.

---

### [1.9.1-beta.4] - Smart Error Handling Pagamenti
### Aggiunto
- **Smart Error Handling Pagamenti**: Nuovi modali intelligenti e contestuali per la gestione delle eccezioni di dominio durante il pagamento fornitori.
- **Audit Trail Responsabilità**: Tracciamento obbligatorio delle note di override per decisioni critiche (es. scoperto di conto, overpayment) ai sensi dell'art. 1129 c.c.
- **Sentinelle di Partita Doppia**: Controlli rigorosi e informativi su allocazioni inconsistenti e violazione del tetto contanti (D.Lgs. 231/2007).

### Corretto
- **Fix calcolo capienza Cassa**: Risolto un bug critico nel backend che ignorava il `saldo_iniziale` della Cassa nel calcolo del saldo corrente per il controllo fondi.

---

### [1.9.1-beta.3] — Dettaglio Fattura & Flusso Pagamento Rapido

> Aggiunta pagina di dettaglio fattura con visualizzazione completa di voci, importi, scadenze, documenti allegati, audit trail per l'Art. 1135 c.c., e possibilità di procedere immediatamente al pagamento.

### Funzionalità — Dettaglio Fattura

- **Pagina Dettaglio Fattura Passiva:** Aggiunta la vista dedicata per ispezionare tutti gli estremi della fattura. È presente il riepilogo documenti, importi (imponibile/iva), scadenza, badge stato approvazione e stato pagamento. Mostra il dettaglio delle righe contabilizzate, incrociando i capitoli di spesa del piano dei conti.
- **Audit Trail Ratifica Assembleare:** Se la fattura è stata approvata in seguito a uno "sforo motivato" (Art. 1135 c.c.), la pagina di dettaglio espone ora una sezione di Audit Trail (banner in evidenza) con autore, orario di approvazione e nota verbale.
- **Flusso "Paga Ora":** Un pulsante verde in corrispondenza dei badge ("Paga Fattura") consente di saltare immediatamente alla pagina di registrazione pagamento, auto-selezionando il fornitore e marcando l'intera fattura per il saldo in un solo click, con caricamento istantaneo delle pendenze residue.
- **Ritenute d'Acconto:** Aggiunta una nota riepilogativa nel dettaglio fattura se il compenso è soggetto a ritenuta d'acconto, incluse le specifiche dell'aliquota (%) e del tributo assegnato.
- **Approvazione base:** Possibilità di passare lo stato da "Da Approvare" ad "Approvata" direttamente dal menu azioni riga (per fatture interne che non costituiscono sforo motivato), permettendone il rapido sblocco per il saldo.

---

### [1.9.1-beta.2] — Ratifica Assembleare Sforo Motivato & Legal Compliance

> Implementa il flusso di approvazione legale per le fatture registrate con sforo motivato (Art. 1135 c.c.),
> rendendo il ciclo passivo completamente operativo e conforme per gli studi di amministrazione professionale.

### Funzionalità — Ratifica Assembleare Sforo Motivato

- **Nuovo endpoint `POST /fatture/{fattura}/approva-sforo`:** Aggiunto metodo `approvaSforo()` in `FatturaPassivaController` che gestisce la transizione legale `sforo_motivato → approvata`. Il metodo include guard di stato, validazione note (max 1000 caratteri) e salvataggio automatico dell'audit trail in `dati_extra.ratifica_assembleare` (note, timestamp ISO8601, ID autore).

- **Audit trail permanente:** Ogni ratifica salva in `dati_extra`: data e ora dell'approvazione, ID dell'utente che ha confermato, e note libere con riferimento alla delibera assembleare. Il log server (`laravel.log`) registra ogni transizione per tracciabilità completa.

- **Bottone "Approva sforo" inline in Pagina Pagamento Fornitori:** Le fatture in sforo motivato mostrano nella riga pendenze un bottone arancione "Approva sforo". Al click si apre una modale di ratifica (`ConfirmDialog`) con contesto legale Art. 1135, riepilogo della fattura in oggetto, e campo note facoltativo. Alla conferma, lo stato cambia istantaneamente e le pendenze vengono ricaricate senza cambiare pagina — la fattura diventa selezionabile per il pagamento.

- **Voce "Ratifica Assembleare" nel menu azioni Lista Fatture:** Aggiunta al componente `DataTableRowActions.vue` una voce arancione nel dropdown `⋯`, visibile esclusivamente per fatture con `stato_approvazione === 'sforo_motivato'`. Apre lo stesso modale di ratifica con identico audit trail.

- **Tooltip professionale sfondo nero (reka-ui/shadcn):** Il badge "⚠ Ratifica richiesta" nella pagina Pagamento Fornitori è ora avvolto dal componente `Tooltip` di reka-ui con sfondo nero e freccia, che spiega all'amministratore il motivo legale del blocco (`Art. 1135 c.c.`) e le istruzioni per procedere. Sostituisce il tooltip nativo OS-level che era grezzo e non in linea con lo stile del gestionale.

### Motivazione Legale

> Le fatture con sforo motivato rappresentano spese urgenti sostenute oltre il budget deliberato dall'assemblea. L'Art. 1135 c.c. obbliga l'amministratore a convocare l'assemblea per ratificare formalmente la spesa prima del pagamento. Il blocco precedente era corretto ma silenzioso — senza comunicazione e senza via d'uscita dall'interfaccia, generando confusione operativa e richieste di supporto. Questa release documenta il blocco, spiega il perché e fornisce lo strumento per risolverlo, proteggendo legalmente l'amministratore.

---

### [1.9.1-beta.1] — Registro Pagamenti Fornitori, Statistiche Incassi & Hardening UI/UX

### Funzionalità — Registro Pagamenti Fornitori (Nuovo Modulo)

- **Nuovo Controller & Risorsa Backend:** Creato `PagamentoFornitoreController` e implementata `PagamentoFornitoreResource` per esporre e formattare i dati dei pagamenti verso i fornitori, completi di impaginazione e statistiche.
- **Nuova Interfaccia Registro Uscite:** Sviluppata la vista `PagamentoRegisterList.vue` con il componente dedicato `PagamentiDataTable.vue`. Colonne: Fornitore, Conto Addebito, Data Pagamento, Metodo & Importo, Stato.
- **Statistiche Finanziarie in Tempo Reale:** Tre card analitiche nell'header del registro:
  1. **Uscite Totali** — Somma totale delle uscite registrate nell'esercizio corrente.
  2. **Ritenute d'Acconto** — Conteggio dei pagamenti soggetti a ritenuta per il monitoraggio degli F24.
  3. **Operazioni Stornate** — Conteggio delle transazioni annullate o stornate.
- **Filtri Avanzati:** Pannello di controllo `DataTableToolbar.vue` con ricerca testuale debouncata dei fornitori e menu di selezione per metodo di pagamento (Bonifico, Assegno, Contanti, MAV, ecc.).

### Funzionalità — Statistiche in Lista Incassi Rate

- **Widget di Riepilogo Incassi:** Estese le card statistiche alla schermata `IncassoRateList.vue`:
  1. **Incassi Totali** — Conteggio complessivo degli incassi registrati sul condominio.
  2. **Incassato Mese** — Totale delle operazioni andate a buon fine nel mese solare corrente.
  3. **Incassi Stornati** — Numero di operazioni stornate o annullate.
- **Backend Integration:** Aggiornato `IncassoRateController@index` per calcolare queste statistiche in tempo reale tramite query ottimizzate.

### Hardening — Compatibilità Database PHP 8.5

- **Adattamento `PDO::MYSQL_ATTR_SSL_CA` per PHP 8.5:** In PHP 8.5 la costante `PDO::MYSQL_ATTR_SSL_CA` è stata spostata nel nuovo namespace dedicato `\Pdo\Mysql::ATTR_SSL_CA`. Aggiornato `config/database.php` per entrambe le connessioni `mysql` e `mariadb` con un controllo adattivo a runtime (`PHP_VERSION_ID >= 80500`) che seleziona automaticamente la costante corretta, mantenendo la retrocompatibilità con PHP 8.4 e precedenti.

### Bugfix & Hardening TypeScript

- **Risoluzione Casing Conflict macOS:** Risolto bug bloccante del compilatore TypeScript per discrepanze maiuscole/minuscole tra `DataTable.vue` e `Datatable.vue` dovute alla cache del file system macOS. La cartella dei componenti è stata rinominata in `pagamenti_fornitori`.
- **Fix SelectItem Empty Value (Shadcn UI):** Risolto crash a runtime di `SelectItem` (Shadcn/Radix-vue) che vietava stringhe vuote `""` come valore. Introdotto il valore speciale `"all"` per l'opzione "Tutti i metodi", mappato correttamente a stringa vuota nella chiamata API.
- **Fix Firma Evento `onMetodoChange`:** Risolto errore di digitazione `Type '(val: string) => void' is not assignable to type '(value: AcceptableValue) => any'` in `DataTableToolbar.vue`.
- **Fix GuideItem `colorVariant`:** Sostituito il colore non supportato `"rose"` con `"slate"` in `PagamentoRegisterList.vue` per conformarsi ai vincoli del tipo unione accettato da `PageHeaderGuide`.

---

## [1.9.0] — Accounting Intelligence Core

> **Stable release.**
> Introduce il motore contabile avanzato: ancoraggio atomico dei piani rate,
> dashboard di audit in tempo reale, gestione sopravvenienze passive,
> ripartizione mista ad personam, ciclo passivo completo e conformità Art. 1130-bis c.c.
>
> 27 beta release (beta.3 → beta.29) prima della stable.

---

### [1.9.0-beta.29] — Piano Rate Engine Fixes & Snapshot Architecture

#### Bugfix — Calcolo Totale Piano Rate (Filtro Snapshot)

**Problema:** Se la struttura del piano dei conti veniva popolata in un momento successivo (es. tramite la migrazione automatica della v1.9), il filtro snapshot escludeva completamente interi capitoli di spesa perché tutti i suoi sottoconti risultavano creati dopo il piano rate. Totale rate inferiore al preventivo (es. 4.610 € anziché 9.600 €).

**Soluzione:** Aggiunto un fallback in `CalcoloQuoteService`: se il filtro snapshot esclude tutti i figli ma esiste un importo già congelato (override) nella pivot `piano_rate_capitoli`, il sistema usa tutti i figli correnti per distribuire l'importo corretto, preservando la quadratura senza gonfiare il preventivo.

#### Ottimizzazione — Deep Eager Loading Motore di Calcolo

**Problema:** `CalcoloQuoteService` caricava le relazioni in modo superficiale. Durante la discesa ricorsiva nei sottoconti, Laravel eseguiva il lazy loading delle tabelle millesimali per ogni singola voce. Con `preventLazyLoading(true)` generava un Fatal Error; in alternativa causava un elevato numero di query (N+1 problem).

**Soluzione:** Implementato il Deep Eager Loading (`sottoconti.tabelleMillesimali...`) direttamente all'avvio del calcolo, riducendo drasticamente le query e prevenendo crash.

#### Hardening — Fallback Divisione Equa (Penny-Perfect)

**Problema:** Se un capitolo padre aveva sottoconti con budget totale pari a 0 (struttura creata manualmente o non ancora valorizzata), l'importo congelato del padre veniva ignorato silenziosamente.

**Soluzione:** Se il budget totale dei figli è zero, l'importo del padre viene distribuito in parti uguali tra i figli, garantendo che l'intero budget allocato venga sempre ripartito.

#### Architettura — Snapshot Puro per i Capitoli Orfani

**Problema (debito tecnico):** `SyncOrphanChaptersAction` inseriva i nuovi capitoli orfani nella pivot con importo `NULL`, forzando il motore a leggere il valore "live" dal preventivo e rompendo il principio di immutabilità necessario per la corretta chiusura dell'esercizio.

**Soluzione:** Durante la sincronizzazione, il sistema esegue una somma ricorsiva del preventivo effettivo (filtrando i conti tecnici) e salva il valore esatto nella pivot, congelandolo definitivamente.

---

### [1.9.0-beta.28] — Migration Resilience & Collation Fix (Hosting Condivisi)

#### Hardening — Pattern Idempotente `cleanupPartialMigration`

**Problema:** Su hosting condivisi (Netsons, SiteGround, Aruba) con PHP-FPM o su ambiente Windows, il `max_execution_time` può interrompere una migration `ALTER TABLE` a metà. La successiva esecuzione trovava colonne parzialmente create e crashava con `Duplicate column name` o `Can't DROP ... check it exists`.

**Soluzione:** Il pattern `cleanupPartialMigration` rende ogni migration auto-riparante: prima di aggiungere colonne, verifica e rimuove quelle orfane lasciate dall'esecuzione precedente.

**File modificati (3):**

- **`2026_03_16_223813_add_fornitore_and_description_to_saldi_table`** — Guard `information_schema.STATISTICS` prima di ogni `dropIndex('idx_saldi_condominio_fornitore')`, sia nel `cleanupPartialMigration()` che nel `down()`.
- **`2026_03_27_160203_add_mastri_costo_e_ripara_voci_orfane`** — Refactoring da N+1 a `DB::join()`. Aggiunto `set_time_limit(0)`. Loop `Condominio` convertito da `all()` a `lazy()` per eliminare il rischio OOM.
- **`2026_04_19_072947_hardening_legale_e_tracciabilita_fatture`** — Già conforme; nessuna modifica. È la migration di riferimento che ha ispirato il fix della saldi.

#### Bugfix Critico — Collation Mismatch MySQL (Error 1267)

**Problema:** Il dashboard di produzione su Netsons (MySQL 5.7/8.0 su `utf8mb3_general_ci`) crashava con `SQLSTATE[HY000]: General error: 1267 Illegal mix of collations` ad ogni caricamento.

**Causa radice:** `JSON_UNQUOTE(JSON_EXTRACT(meta, '$.type'))` restituisce una stringa con la collation della connessione (`utf8mb3_general_ci`), mentre i letterali stringa PHP confrontati ereditavano la collation della colonna `meta` (`utf8mb3_unicode_ci`). Entrambe le sorgenti avevano lo stesso livello di coercizione (`COERCIBLE`), quindi MySQL non poteva risolvere il conflitto autonomamente.

**Soluzione:** Wrapping sistematico di tutti i risultati `JSON_UNQUOTE` con `CONVERT(... USING utf8mb4)`.

**File modificati (3):**

- **`RecurrenceService`** — Tutti i confronti `where('meta->type', ...)` convertiti in `whereRaw("CONVERT(JSON_UNQUOTE(...) USING utf8mb4) = ?", [...])`. Sostituito `where('meta->requires_action', true)` con `whereJsonContains`.
- **`InboxService`** — Aggiunto `CONVERT(... USING utf8mb4)` ai confronti JSON in `getCounts()`.
- **`PianoRateResource`** — Refactoring del `whereRaw` nella clausola `has_saldi`: separati in due `whereRaw` distinti dentro un `orWhere(closure)`.

**File non modificati (già sicuri):** Tutti i Listener, i Controller (`DashboardController`, `SituazioneDebitoriaController`, ecc.), `Evento` e `GeneratePianoRateAction` usano `whereJsonContains` o `where('meta->...')` — immuni al problema di collation.

---

### [1.9.0-beta.27] — Tabelle Millesimali Multi-Coefficiente & Copertura Straordinaria Granulare

#### Funzionalità — Gestione Multi-Tabella con Coefficienti Controllati

**Problema:** Era possibile associare più tabelle millesimali a una voce di spesa, ma il coefficiente restava bloccato al 100% senza possibilità di modifica. Impossibile gestire scenari reali come "50% Tabella Generale + 50% Tabella Scale".

**File modificati (7):**

- **`AssociaTabellaController`** — Blocco hard: `somma_coefficienti_esistenti + nuovo_coefficiente ≤ 100`. In caso di violazione la richiesta viene rigettata con il residuo disponibile.
- **`AggiornaTabellaController`** *(nuovo)* — Controller `PUT` per modificare `coefficiente` di un'associazione esistente. Applica lo stesso blocco hard escludendo la riga corrente.
- **`routes/gestionale.php`** — Aggiunta route `PUT esercizi/{esercizio}/.../aggiorna-tabella/{tabella}`.
- **`DettaglioConto.vue`** — Barra visiva della somma coefficienti (arancione se parziale, verde se 100%). Bottone "Aggiungi" disabilitato con tooltip quando la somma raggiunge il 100%.
- **`ModalAssociaTabella.vue`** — Supporto dual-mode (crea / modifica). Badge "max X% disponibile" sul campo coefficiente.
- **`Index.vue`** — Istanza `ModalAssociaTabella` condivisa. Callback `gestisciTabella` smista su `router.post` o `router.put` in base al flag `_isEdit`.

#### Bugfix — Copertura Piani Straordinari Tracciabile e Collegabile

**Problema:** La riga "Analisi Copertura" relativa ai piani rate straordinari veniva generata come fallback generico senza `piano_rate_id`, rendendo impossibile il collegamento diretto al piano.

**Causa radice:** `BudgetCoverageService` Step 3 calcola la copertura straordinaria attraverso `piano_rate_fatture → righe_fattura → conto_id`, ma questa copertura non lascia traccia in `piano_rate_capitoli`. Il gap rimaneva inesplicato e veniva tappato dal fallback.

**File modificati (3):**

- **`PianoContiController::show()`** — Costruisce `$pianiStraordinariMap`: mappa `conto_id → [{id, nome, stato, importo}]` granulare per piano.
- **`ContoResource`** — Produce una riga per ogni piano straordinario con `piano_rate_id` reale; cade nel fallback solo per dati storici privi di `importo_collegato`.
- **`DettaglioConto.vue`** — Il nome del piano è ora un `<InertiaLink>` cliccabile quando `item.piano_rate_id` è valorizzato.

**Invarianti garantiti:** Nessuna migration necessaria.

---

### [1.9.0-beta.26] — Piano Rate Snapshot Engine

#### Bugfix Critico — Isolamento Temporale dei Piani Rate

**Problema:** L'aggiunta di una nuova voce di spesa come sottoconto di un capitolo già incluso in un piano rate attivo causava l'inclusione automatica e silenziosa della nuova voce nel piano esistente.

**Causa radice:** Il sistema non aveva il concetto di "snapshot temporale". I sottoconti venivano sempre letti dinamicamente dalla relazione Eloquent al momento del calcolo.

**File modificati (4):**

- **`CalcoloQuoteService`** — Sottoconti filtrati per `created_at <= piano_rate.created_at` prima di distribuire proporzionalmente il budget.
- **`BudgetCoverageService`** — STEP 1 di `calcolaCoperturaReale()`: il push-down del budget applica lo stesso filtro temporale.
- **`PianoRateController::store`** — I nuovi piani rate salvano in `piano_rate_capitoli` gli ID delle foglie esistenti al momento della creazione.
- **`PianoRateResource`** — La serializzazione di `figli_names` e il calcolo di `importo_originale` applicano lo stesso snapshot temporale.

**Invarianti garantiti:** Nessuna migration necessaria.

---

### [1.9.0-beta.25] — ERP Accounting Engine & Reverse Ledger

#### Architettura — Il Filtro Invertitore (Note di Credito)

- **Paradigma "Write-Then-Reverse":** Il core di `FatturaPassivaService` per le Note di Credito abbandona la logica ibrida basata su moltiplicatori matematici. La Partita Doppia viene generata sempre come per una fattura passiva standard (valori assoluti positivi). Un "Filtro Invertitore" finale capovolge chirurgicamente i segni (DARE↔AVERE).

#### Sicurezza — Il Guardiano Contabile

- **Double-Entry Validator:** Un istante prima di finalizzare il `DB::transaction`, il sistema calcola la somma esatta di DARE e AVERE. Qualsiasi sbilancio blocca fisicamente la transazione (Rollback totale) e scrive un log `CRITICAL` con User ID, importi e differenza.

#### Bugfix — Compliance Fiscale e Fondi

- **Storno Ritenute d'Acconto:** Risolto bug fiscale critico che escludeva il calcolo della ritenuta d'acconto durante la generazione delle Note di Credito.
- **Integrità Reportistica Fondi:** Le Note di Credito registrano l'utilizzo dei Fondi con segno negativo: `1.000 € (Fattura) + (−1.000 €) (Storno) = 0 €`.
- **Garbage Collection Conti Fantasma:** `FatturaPassivaController@destroy` distrugge automaticamente i "Conti Imprevisto" orfani creati dalle sopravvenienze.

#### Testing

- **Test Suite Alignment (100% Pass Rate):** Aggiornati `DashboardFinancialTest` e `BudgetCoverageServiceTest`.
- **Agnostic Migrations per SQLite:** Le migration storiche tollerano le esecuzioni su SQLite in RAM per i test Pest, eseguendo le query raw esclusivamente in ambiente MySQL/MariaDB.

```bash
php artisan test --filter="Scenario|fattura|nota di credito|fondo|mista"
```

---

### [1.9.0-beta.24] — Historical Debt Management & Financial UI

#### Funzionalità — UI Finanziaria Avanzata (Widget Double Lock)

Pannello di registrazione delle fatture pregresse con tre Card analitiche indipendenti:

1. **Quadratura (Scarto Economico):** Differenza tra totale fattura e debito storico a bilancio. Blocca il salvataggio se non quadra (rosso).
2. **Liquidità Arretrati (Deficit Finanziario):** Confronto tra debito storico e capienza della Rata 0. Avviso informativo non bloccante (ambra).
3. **Impatto Cassa (Netto Bancario):** Proiezione esatta del saldo di conto corrente post-operazione.

#### Funzionalità — Precisione Operativa & Legale

- **Calcolo Bonifico Netto:** La card "Impatto Cassa" scorporo le Ritenute d'Acconto, mostrando l'esatto importo del bonifico netto da disporre in banca.
- **Filtro Conti Liquidi:** Il menu "Conto Addebito" mostra esclusivamente Banche, Poste, Cassa Contanti.
- **Prescrizione Quinquennale:** Se la "Data di origine del debito" supera i 5 anni, scatta un alert rosso "Rischio Prescrizione" (Art. 2948 c.c.).

---

### [1.9.0-beta.23] — Dashboard Intelligence & Clean Ledger

#### Funzionalità — Dashboard & Deficit Operativo

- **Scoperto Operativo Reale:** Il box allerta ("Mancano € X") somma solo le spese scoperte che richiedono l'emissione di rate, ignorando i fondi avanzati in altri capitoli stagni.
- **Pulizia Cognitiva:** Rimosso il widget ridondante "Fatture in sospeso" dalla vista principale.

#### Funzionalità — Audit Spese Scoperte (Modale "Financial X-Ray")

- **Separazione Semantica:** "Fatture in sospeso" (Imprevisti e Art. 63) separate dagli "Sforamenti Budget Preventivo".
- **Esploso Fattura (Line-Level Breakdown):** Le fatture miste fuori budget mostrano il dettaglio riga per riga (Parte comune vs Art. 63 con indicazione dell'unità).
- **Smart Routing:** Deep-Link al wizard Piano Rate con auto-popolamento (`?tipo=straordinario&origine=dashboard&gestione_id=...&fatture[]=...`).

#### Funzionalità — Piano dei Conti (Clean Ledger UI)

- **Separazione Visiva Albero dei Conti:** "Preventivo deliberato" (modificabile) vs "Sopravvenienze e imprevisti" (sola lettura).
- **Sdoppiamento Totali:** Header con due badge distinti (es. *Preventivo: € 5.000* | *Sopravv: € 134*).
- **Badge Legale Art. 1130-bis c.c.:** Banner ambra nel dettaglio delle voci tecniche.

#### Bugfix — Core Logic & Type Hardening

- **Inertia.js FormData Sanitization:** Risolto perdita dati di `immobile_id`. Introdotto `form.transform` con casting rigoroso in `Number` o `null`.
- **Race Condition Inertia/URL:** Gli ID fatture vengono ora salvati in una ref dedicata durante `onMounted`, sopravvivendo alla riscrittura URL del router.
- **Prevenzione Falsi Positivi Booleani:** La query SQL intercetta correttamente i fallback numerici (`0`) delle colonne booleane (`is_rateizzata`) su MySQL/SQLite.

#### Backend — Hardening Legale & Tracciabilità

- **Migrazione Unificata:** `is_tecnico` su `conti`; `origine_tipo`, `stato_legale`, `stato_legale_aggiornato_at`, `riga_fattura_id`, `voce_id` su `rate_quote`; `is_rateizzata` su `righe_fattura`; `contesto_creazione` su `piani_rate`. Include data migration retroattiva (D1–D5).
- **Scope `visibili()` su Model Conto:** Filtra `is_tecnico=false`. Applicato su `FetchCapitoliContiController`, `FetchCapitoliPerGestioneController` e `PianoRateController::store()`.
- **Euristica `origine_tipo` / `stato_legale`:** `GenerateRateQuotesAction` popola automaticamente `condominiale` vs `ad_personam` e `certo` vs `contestabile`.
- **Semaforo Dashboard (`is_rateizzata`):** `true` alla creazione del piano straordinario, `false` alla cancellazione.
- **Contesto Creazione Piano Rate:** Enum `contesto_creazione` — `preventivo_iniziale` / `integrazione_dashboard` / `libero_manuale`.

---

### [1.9.0-beta.22] — Fund Governance & Audit-Ready Resources

#### Funzionalità — Governance Patrimoniale (Legal Compliance)

- **Motore a Regole Giuridiche:** Il sistema mappa la natura giuridica del fondo (`sottotipo_fondo`: Generico, Vincolato per Lavori, Accantonamento TFR, Morosità).
- **Audit Trail e Sblocco in Deroga:** I fondi vincolati nascono bloccati di default. `is_override_assemblea` richiede obbligatoriamente gli estremi della delibera o della giustificazione legale.
- **Single Source of Truth:** `is_utilizzabile_per_imprevisti` è calcolato dinamicamente dal Modello Eloquent.

#### Funzionalità — Enterprise Data Table (Risorse e Fondi)

- **Allineamento Matematico:** Colonna saldi con `tabular-nums` e allineamento a destra.
- **Semantica degli Stati:** "Libero" (Verde), "Vincolato" (Rosso), "Sbloccato in deroga" (Viola).
- **Smart Truncation & Type Hardening:** Troncamento a 40 caratteri. Sostituiti i cast `any` con interfacce TypeScript rigorose (`TipoCassa`, `SottotipoFondo`).

---

### [1.9.0-beta.21] — The Financial X-Ray & Single Source of Truth

#### Funzionalità — Spaccato Finanziario Trasparente (Tenant Wallet UX)

- **Pannello "X-Ray" (Sheet):** Interfaccia a scorrimento laterale con lo "scontrino matematico" di ogni condòmino.
- **Scomposizione Dinamica dei Debiti:** Quote millesimali ordinarie, spese private dirette (Art. 63) e saldi pregressi.
- **Raggruppamento per Immobile:** Divide i calcoli per ogni singola unità immobiliare.

#### Funzionalità — UI/UX

- **Azioni Contestuali (Dropdown Menu):** Bottone spaccato integrato in un menu a tre puntini nella colonna "Saldo".
- **Correzione Penny-Perfect (Fallback Zero-Quota):** Se i comproprietari hanno quote millesimali a zero, il sistema applica automaticamente una divisione equa (es. 50%/50%).

---

### [1.9.0-beta.20] — The Extraordinary Engine & Polymorphic UI

#### Funzionalità — Piani Rate Straordinari (Il Bivio & Art. 1135 c.c.)

- **Architettura a Doppio Binario:**
  1. **Ordinario** — Basato sul bilancio preventivo.
  2. **Straordinario** — Slegato dal preventivo, si alimenta dal "Carrello Fatture".
- **Scudo Legale Obbligatorio:** `CreatePianoRateRequest` richiede "Delibera Assembleare" o "Urgenza" con gli estremi.

#### Funzionalità — Polimorfismo Contabile & Dashboard Intelligence

- **Polimorfismo delle Risorse:** `PianoRateResource` maschera le fatture straordinarie come capitoli di spesa. Tutto l'ecosistema frontend continua a funzionare senza duplicazioni.
- **Widget "Sforo Recuperato":** Badge blu **"INTEGRATO — Sforo Recuperato"** quando una sopravvenienza viene finanziata.
- **Smart Push-Down Straordinario:** `BudgetCoverageService` (Step 3) inietta la copertura direttamente nel nodo dell'Albero dei Conti, colorando la barra al 100% (Smeraldo).

#### Funzionalità — Motore Penny-Perfect & Ripartizione Mista

- **Supporto Fatture Miste:** Spese comuni (millesimi) + addebiti personali diretti (`immobile_id`) nella stessa fattura.
- **Quadratura Frazionale Assoluta:** I resti decimali vengono assorbiti sulle prime rate (es. 3 rate da 6,62 € e 9 da 6,60 €). Corrispondenza al centesimo con il documento fiscale garantita.

---

### [1.9.0-beta.19] — The Triple Recovery Strategy & Reserve Fund Engine

#### Funzionalità — Gestione Intelligente Sforamenti (Il Tridente)

Tre strategie distinte e mutualmente esclusive per gli sforamenti di budget (Art. 1135 c.c.):

1. **Attesa Conguaglio** — Debito "silenzioso" da richiedere a chiusura esercizio.
2. **Rata Integrativa** — Allarme attivo verso l'emissione di un piano rate straordinario.
3. **Fondo di Riserva** — Assorbe istantaneamente lo sforo attingendo a un fondo patrimoniale preesistente.

#### Funzionalità — Integrazione Contabile Fondi

- **Automazione Partita Doppia:** L'utilizzo del Fondo Riserva genera scritture contabili reali nel Libro Giornale: **AVERE** sul mastro Cassa/Fondo, **DARE** sul mastro Sopravvenienze.

#### Funzionalità — Dashboard & Visual Intelligence 2.0

- 🟢 **Verde Smeraldo [Coperto da Fondo]** — Spesa già neutralizzata finanziariamente.
- 🟣 **Indaco [Sforo Autorizzato]** — Sforo destinato al conguaglio di fine anno.
- 🟠 **Ambra [Emetti Rate]** — Spesa che richiede azione immediata.
- **Stato "Bilancio Integrato":** Se tutte le spese scoperte hanno una strategia assegnata, il widget mostra **INTEGRATO**.

#### Funzionalità — Risorse e Cassa (Real-Time Balancing)

- **Calcolo Saldo Dinamico:** `Iniziale + DARE − AVERE` ricalcolato in tempo reale dal Libro Giornale, eliminando discrepanze tra gestione e contabilità pura.

---

### [1.9.0-beta.18] — Mixed Allocation & Dynamic Ledger

#### Funzionalità — Ripartizione Mista e Addebiti Personali

- **Spaccatura Fattura (Line-Level Splitting):** Un singolo documento fiscale può essere suddiviso in infinite righe di dettaglio, ciascuna con logica di ripartizione indipendente.
- **Addebito Diretto su Unità (`immobile_id`):** Una riga di spesa può essere assegnata a una singola unità. Il motore ignora i millesimi e addebita il 100% al proprietario interessato.
- **Fine delle Tabelle Fittizie:** Eliminata la necessità di creare tabelle millesimali finte (es. 1000/1000 su un singolo condomino) per le spese ad personam.

#### Funzionalità — Sopravvenienze Passive

- **Gestione Imprevisti "On-the-Fly":** Interruttore "⚡ Spesa imprevista" su ogni riga della fattura. Il sistema dirotta l'importo su "Sopravvenienze Passive" nel Libro Giornale.
- **Bilanci Trasparenti (Art. 1130-bis c.c.):** Le spese d'emergenza non inquinano i capitoli ordinari. Nel consuntivo l'assemblea vede una voce separata per tutti gli imprevisti.

#### Hardening — Database Fortification & UI Safety

- **Filtro "Fortezza" sul Piano dei Conti:** Blocco bidirezionale (Frontend + Backend) che impedisce la registrazione su Macro-Capitoli (nodi padre) o su voci orfane.
- **Backend Hard-Lock:** `FatturaPassivaService` lancia un'eccezione bloccante se rileva un tentativo su un conto privo di Mastro in Partita Doppia.

---

### [1.9.0-beta.17] — Legal Guardian & UI Precision

#### Funzionalità — Conformità Legale (Gate Legale Art. 1135 c.c.)

- **Workflow di Approvazione Blindato:** Blocco normativo che impedisce di rendere esecutivo un Piano Rate senza delibera formale.
- **Modale Delibera Assembleare:** La transizione "Bozza" → "Approvato" richiede Data Delibera, Numero Verbale e Note.
- **Audit Trail:** Tracciamento automatico di `approvato_il` e `approvato_da_user_id`.
- **Badge Legale Visivo:** Indicatore semantico nell'intestazione del Piano Rate (icona a martelletto).
- **Ripristino Sicuro:** Il ritorno allo stato "Bozza" cancella automaticamente i dati della delibera e l'audit trail.

#### Ottimizzazione — Smart Sync & Backend

- **Filtro Zero-Importo:** Il sistema esclude automaticamente i capitoli a 0,00 €, prevenendo falsi allarmi di sincronizzazione.
- **Pulsante Azione Dinamico:** Arancione "Sincronizza" se ci sono nuove voci scoperte, standard "Ricalcola" altrimenti.

#### Bugfix — UX & Radix UI

- **Fix Posizionamento HoverCard:** Risolto bug di "salto" a coordinate `0,0`. Forzato l'ancoraggio con `side="bottom"`.
- **Sincronizzazione Stato UI:** Se l'utente annulla l'inserimento della delibera, lo switch Vue torna istantaneamente allo stato reale del database.

---

### [1.9.0-beta.16] — Accounting Intelligence & Precision

#### Funzionalità — Motore Finanziario in Centesimi (MoneyHelper)

- Rimosso l'uso dei float nativi PHP per i calcoli finanziari. Integrata la classe `MoneyHelper` in tutto il ciclo di incasso.

#### Funzionalità — Gestione Debiti Pregressi e Double Lock

- **Meccanismo Double Lock:** Quadratura perfetta tra competenza economica, situazione patrimoniale e liquidità reale.
- **5 scenari gestiti:** Copertura Totale · Crisi di Liquidità · Proiettile Vagante (Sopravvenienza) · Copertura Mista (Split) · Fondo di Riserva.

#### Funzionalità — Ordinamento Visivo a Cascata (Waterfall)

- I movimenti **DARE** (Addebiti) precedono visivamente quelli in **AVERE** (Incassi), garantendo una curva del saldo priva di "falsi rossi".

#### Bugfix

- **Race Condition "NON PAGATA":** `ricalcolaStato()` spostata per eseguire *dopo* l'effettivo `attach()` dei pagamenti.
- **Sbilanciamento Incassi Misti:** Introdotto il controllo `$budgetCashCents` in `StoreIncassoRateAction` per impedire alla scrittura di cassa di consumare debito virtuale.

---

### [1.9.0-beta.15] — Tenant Experience & UI

- **Smart Wallet (Salvadanaio Condòmino):** Design "Digital Wallet" con breakdown matematico trasparente (costo rata, credito applicato, nuovo totale).
- **Credito Puro (Zero-Payment):** Card blu per le rate che generano credito netto. Istruzioni per il bonifico e pulsanti di pagamento nascosti automaticamente.
- **Sincronizzazione Dinamica UI:** L'area condòmini reagisce istantaneamente a qualsiasi azione dell'amministratore (incasso, storno, annullamento emissione).

---

### [1.9.0-beta.14] — Accounting Engine & Sync (Ciclo Attivo)

- **Motore di Storno "Self-Healing":** Fotografia preventiva dei soggetti coinvolti, inversione della partita doppia e ripristino chirurgico del debito sulle quote originali.
- **Onboarding Silenzioso:** Risolto bug critico nel rilascio delle "Rate Silenziose": il sistema filtra i pagamenti esclusivamente per la singola anagrafica.
- **Prevenzione Falsi Positivi JSON:** Il flag `is_emitted` viene interpretato correttamente a prescindere dal tipo di dato (booleano, intero o stringa).

---

### [1.9.0-beta.13] — Bugfixes & Ottimizzazioni

- **Fix Popup di Storno:** Corretto importo "€ 0,00" nel dialog di conferma. Il frontend riconverte correttamente i decimali in centesimi.
- **Fix Vue Warnings:** Aggiunta la prop `esercizi` mancante nel controller lista incassi.
- **Protezione Query Relazionali:** Sostituiti i fragili `whereIn` su campi JSON nidificati con costrutti logici multi-tipo più robusti.

---

### [1.9.0-beta.12] — Tenant Experience & Payment Loops

- **Debito Pregresso non "Scaduto":** La Rata 0 usa un design ambra dedicato con la dicitura "Debito Pregresso" invece del badge rosso "Scaduta".
- **Positive Feedback Loop (Rata Saldata):** Badge verde "Pagamento Ricevuto" e box trionfale "Rata Saldata" dopo la registrazione dell'incasso.
- **Self-Healing Loop (Pagamenti Rifiutati):** Se l'amministratore rifiuta una segnalazione, la modale riattiva i controlli con il bottone "Ho ri-effettuato il pagamento (Segnala di nuovo)".
- **Smart Visibility Bypass:** Risolto falso positivo che manteneva bloccati i pulsanti di pagamento con "Pagamento non ancora attivo".

---

### [1.9.0-beta.11] — Time-Travel Accounting (Debito Esercizio Precedente)

- **Caricamento Fatture Pregresse:** Registrazione di fatture datate in anni passati senza inquinare il bilancio dell'anno in corso.
- **Smart Date Check:** L'interfaccia Vue riconosce automaticamente le fatture di esercizi chiusi e mostra lo "Scudo Giallo" (Debito Esercizio Precedente).
- **Esenzione Budget Attiva:** Attivando "Debito Pregresso", il sistema disinnesca l'allarme "Sforamento Budget".
- **Partita Doppia Invisibile:** `FatturaPassivaService` devia gli importi sul conto **"Fondo Passate Gestioni"** invece dei capitoli ordinari.
- **Badge `[Archive Pregresso]`** nella Data Table principale delle fatture passive.

---

### [1.9.0-beta.10] — Silent Emission & Inbox Zero

#### Funzionalità — Emissione Silenziosa

- **Toggle "Rendi visibile e invia notifiche":** Emissione "in incognito" — scritture contabili generate, condòmini non notificati. Essenziale per caricare massivamente pagamenti pregressi senza generare allarmi.
- **Pulsante "Pubblica Nascoste":** Compare automaticamente solo se ci sono rate congelate. Sblocca la visibilità globale e invia tutte le notifiche in un colpo solo.
- **HoverCard Context-Aware:** Spiega dinamicamente lo stato del piano (Bozza, Approvato, Bloccato) e le azioni possibili.

#### Funzionalità — Inbox Zero

- **Uccisione Mirata (`verifica_pagamento`):** Quando un incasso porta a zero il debito di un condòmino, il promemoria di verifica viene cancellato all'istante.
- **Uccisione Globale (`controllo_incassi`):** Se il pagamento completa il 100% dell'incasso dell'intera rata, il task generico di controllo viene rimosso automaticamente.
- **Cache Buster Integrato:** Il badge numerico rosso sulla campanella sparisce all'istante.

---

### [1.9.0-beta.9] — Tenant Wallet UX & Smart Intent Sync

#### Funzionalità — Smart Intent Sync (Ponte Condòmino-Admin)

- **Il Salvadanaio:** Widget interattivo che calcola se il credito è sufficiente a coprire l'intera rata o se è necessaria un'integrazione tramite bonifico.
- **Dichiarazione di Compensazione:** Il condòmino notifica la volontà di usare il credito (es. "Salda con il credito" o "Ho pagato la differenza").
- **Inbox Admin Contestuale:** L'evento generato esplicita testualmente l'intento (es. *"Il condòmino ha richiesto di usare 100 € del suo salvadanaio, aspetta un bonifico di 12,48 €"*).
- **Guida Operativa Visiva:** `IncassoRateNew` rileva l'intento di compensazione tramite `intent_usa_credito` nell'URL e mostra un Alert Giallo strategico.

#### Bugfix

- **Lazy Loading Saldi Pregressi:** `SyncScadenziarioWithPianoRate` interroga il database per i crediti su Rata 0 solo se `metodo_distribuzione === 'rata_zero'`.
- **Bugfix "Paradosso Arretrati":** L'alert arretrati compare ora solo sulle vere rate a debito, non sulle Rata 0 a credito.

---

### [1.9.0-beta.8] — Smart Wallet & Payment Intelligence

#### Funzionalità — Smart Wallet

- **Single Source of Truth:** Importi nominali esatti allineati a PDF e App condòmini.
- **Pulsante "Compensa Credito":** Se il credito supera la rata, il sistema preleva solo l'esatto importo necessario (Smart Withdrawal), mantenendo il resto nel salvadanaio.
- **Anteprima Scrittura Dinamica:** Mostra diciture specifiche (es. *"Credito rimanente nel salvadanaio: € 88,00"*) prima di salvare.

#### Funzionalità — UI/UX Maschera Incassi

- **Feedback Cromatico:** Verde sgargiante per crediti, rosso acceso per debiti pregressi urgenti.
- **Smart Truncation & Hover Text:** Nomi compressi automaticamente con tooltip per l'elenco completo dei comproprietari.
- **Filtro "Mostra solo scadute":** Include le rate che scadono nella giornata odierna. Mantiene sempre "appuntata" la Rata 0.
- **Input Protections:** I campi importo si disabilitano quando la rata è a zero o è un credito puro.

---

### [1.9.0-beta.7] — Visual Harmony & Smart Filters

#### UI/UX — Design System

- **Widget Guide Contestuali (`PageHeaderGuide`):** Header con breadcrumbs e card informative dinamiche in tutti i moduli operativi.
- **Statistiche Semantiche (Pastel Design):** Colori semantici per urgenza (Rosso, Ambra, Smeraldo, Blu, Violetto, Rosa).
- **Tabelle "Card Style":** DataTables in contenitori `rounded-2xl` con `shadow-sm`.

#### Funzionalità — Smart Filters & Backend

- **Filtro "Condominio" Persistente:** Selettore a tendina con stato salvato dopo il ricaricamento.
- **Dynamic Clear Button:** Compare solo se è applicato almeno un criterio di ricerca.
- **Backend Query Fix:** Aggiunte istruzioni SQL mancanti in `SegnalazioneService` e `DocumentoService` (`whereIn` per 1:N, `whereHas` per N:N).

---

### [1.9.0-beta.6] — Active Budget Guardian & UI Refinements

#### Funzionalità — Active Budget Guardian

- **Allarmi Gerarchici:**
  1. 🔴 **Disallineamento** — Piano rate da ricalcolare urgentemente.
  2. 🟠 **Voci Orfane** — Nuove spese a preventivo non assegnate a nessun piano.
- **Azione Diretta:** I banner di allarme includono pulsanti operativi (es. "Apri", "Analizza Voci").

#### UI/UX — Piano dei Conti

- **Total Budget Badge:** Badge dinamico nell'header con la somma totale del preventivo.
- **Smart Edit Modal:** Integra selettori "Fornitore Suggerito" e "Natura Spesa (Fiscale)". Box informativi contestuali per Hard Lock vs Soft Lock.

---

### [1.9.0-beta.5] — Smart Waterfall & Transparent Ledger

#### Funzionalità — Smart Waterfall Logic

- **Pianificazione Intelligente Saldi:** I crediti/debiti pregressi su più immobili vengono distribuiti a cascata, evitando "rate negative".
- **Incassi Cumulativi Automatici:** `StoreIncassoRateAction` scompone automaticamente un singolo bonifico globale, saldando le quote in ordine progressivo.

#### Funzionalità — Estratto Conto: Transparent Ledger

- **Matematica Inviolabile:** La tabella dei movimenti mostra esclusivamente gli importi puri in Dare/Avere.
- **UI/UX Esplicativa:** Scritte dinamiche (es. *"👉 Include recupero debito pregresso: € 100"*).
- **Graceful Fallback:** Le rate generate con la v1.8 (prive di snapshot JSON) vengono elaborate in modalità standard per totale retrocompatibilità.

---

### [1.9.0-beta.4] — Visual Intelligence & Dashboard Audit

#### Funzionalità — Visual Intelligence (Smart Radar)

- **Semantic Fund Tracking:** 🎯 Diretta · ↳ Da Capitolo · 📈 Spostamento (Viola) · 🔀 Mista.
- **Gestione "Overbudget Sano":** Distinzione tra Eccedenza Critica (Rosso) ed Extra Budget Gestito (Viola).
- **Badge "Squircle":** Design `rounded-md` con icone Lucide.

#### Funzionalità — Core Logic & Dashboard

- **Smart Dashboard Reconciliation:** Se il Delta Globale è a pareggio (tolleranza < 5 €), la modale "Audit spese scoperte" viene soppressa e compare il widget verde "Bilancio Allineato".
- **Equal Deficit Distribution:** I fondi del padre vengono distribuiti equamente tra i figli in deficit matematico.

#### Refactoring

- **Currency Composable:** `useCurrencyFormatter.ts` centralizza la logica di formattazione monetaria.
- **CSS Cleanup:** Rimossa la sezione `<style>` legacy (300+ righe) dai componenti di dettaglio conto.

---

### [1.9.0-beta.3] — Penny Perfect & Smart Push-Down

#### Funzionalità — Accounting Core Intelligence: Evolution

- **Frazionamento Voci di Spesa (Partial Budgeting):** Inclusione nei Piani Rate di solo una quota parte dell'importo totale (es. acconto di 400 € su 1.000 €). Il sistema traccia il "Residuo Disponibile" per i piani successivi.
- **Algoritmo "Penny Perfect":** Il motore `CalcoloQuoteService` garantisce che la somma delle quote corrisponda al 100% dell'importo al centesimo. Il resto decimale viene assorbito dall'ultimo beneficiario.
- **Logica "Smart Folder Push-Down":** Se si assegna un importo forzato a un Capitolo Padre, il sistema calcola automaticamente il rapporto proporzionale e "spinge" l'override sui sottoconti figli.
- **Piani Integrativi (No-Duplicate Balance):** Distinzione tra "Primo Piano" (applica i saldi pregressi) e "Piani Integrativi" (solo nuove spese).
- **Sposta Spesa (Budget Reallocation):** Spostamento quote di budget tra voci della stessa gestione. Audit Trail nella tabella `budget_movements`. History Popover con la genesi dell'importo (es. *Originale 300 € − 100 € spostati = Attuale 200 €*). Protezione bidirezionale contro la rimozione di voci coinvolte in uno spostamento pendente.

#### Bugfix

- **Correzione Ricorsione Override:** L'importo del padre non si somma più erroneamente a quello dei figli nel calcolo dei piani parziali.
- **Fix Totali Widget Copertura:** La risorsa API legge correttamente gli importi parziali dalla tabella pivot.
- **Mass Assignment Protection:** Aggiunti `saldo_applicato` e `nota_saldo` al modello `Gestione`.

---

### [1.9.0] — Base Release *(Accounting Intelligence Core)*

#### Funzionalità — Accounting Core Intelligence

- **Ancoraggio Atomico & Gerarchico:** I piani rate vengono collegati a specifici capitoli di spesa tramite tabella pivot. Auto-popolamento per i piani globali. Selettore capitoli con logica Padre/Figlio.
- **Collision Detection (Anti-Double Billing):** Il sistema impedisce matematicamente di inserire la stessa voce di spesa in due piani rate attivi contemporaneamente.
- **Double-Lock Strategy:** Protezione saldo applicato + Hard-Lock a livello Controller per impedire duplicazioni su altre gestioni.
- **Dashboard Audit & Copertura:** Widget "Semaforo Contabile" — Preventivo vs Pianificato in tempo reale con segnalazione delle voci "Orfane".
- **Sincronizzazione Intelligente (Smart Sync):** Workflow guidato per integrare le voci orfane nei piani rate esistenti.
- **Blocco Cancellazione Preventivo:** Protezione in `ContoController` per impedire l'eliminazione di voci ancorate a piani attivi.

#### Funzionalità — System & Hosting Compatibility

- **Database Flexibility:** Supporto charset diversi da `utf8mb4` (compatibilità legacy MySQL/Altervista).
- **Hosting Condiviso & HTTPS:** Logica per forzare HTTPS e gestire i reverse proxies (`TRUSTED_PROXIES`).
- **Gestione Cron Job Remoti:** Attivazione processi pianificati tramite chiamata HTTP esterna sicura con token cifrato.
- **Configurazione SMTP via UI:** Configurazione server di posta direttamente da pannello, senza editare `.env`.

#### Bugfix

- **CRITICO — Cross-Condominium Pollution:** Risolto bug grave nel calcolo degli arretrati che aggregava erroneamente i debiti dello stesso proprietario su condomini diversi.
- **Duplicazione Saldi:** Risolto problema che impegnava irreversibilmente il saldo alla creazione del piano rate.
- **Pulizia Rate Orfane:** Logica automatica per ignorare rate collegate a piani cancellati o gestioni obsolete.
- **Validazione Obbligatoria Tabelle:** Il campo "Tabella Millesimale" è ora obbligatorio per ogni voce di spesa.

---

## [1.8.0] — The Smart Assistant Update

> Cambio di paradigma: Kondomanager passa da semplice archivio dati ad **Assistente Proattivo**.
> La nuova Smart Activity Inbox genera e suggerisce scadenze in modo intelligente.
> Introdotti gli aggiornamenti frontend per hosting condivisi senza accesso alla console.

### Funzionalità — Core & Automazione

- **Smart Activity Inbox:** Il nuovo motore eventi trasforma il calendario in un assistente virtuale. Il sistema genera e suggerisce eventi collegati alla generazione e ai pagamenti delle rate per una gestione proattiva delle scadenze.
- **Aggiornamenti Automatici da Frontend:** Aggiornamento di Kondomanager direttamente dal pannello di amministrazione, senza accedere alla console del server. Dedicato agli utenti dell'installazione guidata.
- **Condominio di Default al Login:** Nelle impostazioni generali è possibile impostare un condominio da aprire automaticamente al login. Personalizzabile per ogni utente (admin o collaboratore).

### Funzionalità — Contabilità & Gestione

- **Gestione Fornitori:** Modulo completo per la creazione e gestione delle anagrafiche fornitori.
- **Casse del Condominio:** Creazione e gestione delle risorse finanziarie e delle casse condominiali.
- **Emissione Rate Evoluta (Capitoli di Spesa):** Possibilità di emettere rate parziali o mirate selezionando specifici capitoli di spesa (es. generare rate solo per "Scala A").
- **Piani Rate Multipli:** Ogni gestione mantiene un singolo piano dei conti ma può supportare più piani rate.
- **Registrazione Pagamento Rate:** Nuova interfaccia dedicata per la registrazione rapida dei pagamenti.
- **Ottimizzazione Incassi Multi-gestione:** Supporto per pagamenti che coprono più gestioni, con riconciliazione virtuale visibile nei report.
- **Estratto Conto:** Visualizzazione dell'estratto conto direttamente nell'anagrafica del condòmino.
- **Statistiche Dashboard:** Nuovi moduli statistici nella home page amministratore.

### Funzionalità — Internazionalizzazione

Aggiunto il supporto completo per **Inglese** e **Portoghese**:

- Impostazioni Generali e interfaccia Frontend.
- Modulo Comunicazioni in Bacheca.
- Modulo Autenticazione e Registrazione.
- Notifiche Email transazionali.
- Modulo Documenti/Archivio.
- Modulo Segnalazioni Guasti.

### Funzionalità — DevOps

- **Supporto Docker:** Guida ufficiale e file di configurazione per il deploy tramite Docker. *(Thanks @k3ntinhu)*

### Miglioramenti

- **Nuovo Menu "Rubrica":** La voce "Anagrafiche" diventa "Rubrica" con menu a tendina per accesso rapido a Condòmini e Fornitori.
- **Visualizzazione Permessi Rapida:** Le tabelle Utenti e Ruoli mostrano direttamente i permessi associati nelle colonne.
- **Gestione Intelligente Permessi:** Migliorata la logica di assegnazione e revoca permessi durante la modifica di Utente o Ruolo.
- **Smart Associazione Immobili:** Il menu a tendina mostra solo le anagrafiche presenti nel condominio ma non ancora associate a quell'immobile specifico, prevenendo duplicazioni.
- **Filtro Preventivi nel Piano dei Conti:** Il controller mostra solo le gestioni che non hanno ancora un preventivo associato.
- **Integrazione Widget Eventi:** Il widget eventi nella dashboard è collegato alla Smart Activity Inbox.
- **UX Piani Rate:** Migliorata la visualizzazione e le funzioni operative nella gestione piani rate.

### Bugfix

- **Valori Negativi:** Risolto bug che impediva l'inserimento di valori negativi nelle maschere di input delle anagrafiche associate all'immobile (utile per conguagli o crediti pregressi).
- **Registrazione Utenti Invitati:** Risolto problema che impediva agli utenti invitati via email di completare la registrazione con la registrazione pubblica disabilitata.
- **Sicurezza Password:** Implementato controllo per impedire il riutilizzo della password corrente durante il cambio password. *(Thanks @borghiste — Issue #30)*

---
