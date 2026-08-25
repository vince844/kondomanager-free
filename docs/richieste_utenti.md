# Registro delle richieste degli utenti

<!-- verifica-documentazione -->
> **Stato: registro, non analisi.** Aperto il **22/08/2026** su **1.10.0-beta.70**.
> Non descrive codice: registra **chi ha chiesto cosa, con quali parole, e dove è finita**.
> Le richieste elencate fino al 22/08/2026 sono state **ricostruite da `roadmap.md` e dal forum**,
> non da un archivio che non esisteva: dove le parole testuali mancano è scritto, e dove
> l'attribuzione è incerta è scritto anche quello. Da oggi si scrive **quando la richiesta
> arriva**, non quando qualcuno se ne ricorda.
<!-- /verifica-documentazione -->

---

## Perché esiste

Due guasti reali, e sono di due tipi diversi.

**Il primo: una richiesta è sparita.** A fine luglio 2026 era stato deciso di anticipare il
rendiconto ex art. 1130-bis e la Prima Nota **su richiesta di un amministratore**. L'audit del
03/08 ha scoperto che quella promessa non compariva più in nessuna sezione della roadmap: non era
stata respinta, non era stata rimandata, era semplicemente evaporata. La roadmap la registra ancora
oggi come **«RICHIESTA CADUTA, RITROVATA DALL'AUDIT»**, e l'amministratore che l'aveva chiesta non
è mai stato avvisato.

**Il secondo: una richiesta non si ritrova.** Il 22/08/2026 una richiesta importante — la modifica
manuale degli importi delle rate — è arrivata per **mail privata**, da un amministratore che non
vuole scrivere sul forum. La mail non si trova più. Il contenuto è stato ricostruito a memoria, che
per una richiesta d'utente è la cosa peggiore che possa succedere: **le parole testuali sono il
dato**, e una parafrasi le perde.

Il forum protegge dal secondo guasto e non dal primo. Questo registro protegge da entrambi, a
condizione che ci si scriva dentro.

---

## Come si usa

**Una riga per richiesta, scritta il giorno in cui arriva.** Cinque campi: data, canale,
richiedente, **le parole testuali**, disposizione.

**Le parole testuali non sono un vezzo, sono il campo che vale.** Due precedenti, entrambi
verificabili nella roadmap:

- «*solitamente non creo una rata a parte per il conguaglio*» — questa frase, nella prima riga
  della segnalazione, è quella che ha sciolto il caso del conguaglio. La prima analisi l'aveva
  saltata e aveva concluso «è un problema di scoperta, digli di guardare meglio il menu». Era
  falso, e la risposta che ne sarebbe uscita sarebbe stata sbagliata nel merito e paternalistica
  nel tono.
- «*a prescindere dal preventivo*» — quattro parole che separano una funzione da mezza giornata da
  una che tocca la partita doppia. Una parafrasi come «modifica manuale delle rate» le perde
  entrambe dentro la stessa etichetta.

**L'analisi non sta qui.** Qui sta il fatto; l'analisi sta in `roadmap.md` e nei documenti di
progetto, e questo registro ci punta.

**Le domande fatte al richiedente si scrivono, e si scrive se hanno avuto risposta.** È il campo
che mancava: il 03/08/2026 sono state fatte tre domande dirette a un amministratore, la roadmap ha
tenuto due voci **⛔ bloccate in attesa della sua risposta**, e la risposta non è mai arrivata.
Nessuno se n'era accorto perché non c'era un posto dove guardare.

---

## Il registro

| Data | Canale | Richiedente | Richiesta, in breve | Dove sta | Stato |
| :--- | :--- | :--- | :--- | :--- | :--- |
| fine 07/2026 | ? | amministratore non identificato | Anticipare rendiconto 1130-bis e Prima Nota | roadmap, «Richieste ricevute e NON pianificate» | ⚠️ **caduta, ritrovata dall'audit del 03/08, tuttora senza destinazione** |
| 31/07/2026 | forum | Riccardo | Il lucchetto sui saldi non si riapre cancellando il piano | roadmap, In coda ② | ✅ motore corretto in beta.32, residuo di interfaccia in coda |
| 31/07/2026 | forum | Riccardo | Conguaglio: debiti in prima rata **e insieme** crediti trattati in un altro modo | roadmap, v1.11 «quarto metodo» | 🟡 in progetto |
| 31/07/2026 | forum | Riccardo | Credito «scalato man mano fino a esaurimento» alla generazione del piano | roadmap, In coda ⑤ | ⚠️ **domanda fatta il 03/08, mai risposta** |
| 31/07/2026 | forum | Riccardo | Compensazione fra unità dello stesso soggetto all'incasso | roadmap, In coda ⑤ livello 1 | ✅ funzionava già; regia rilasciata in beta.46 |
| 31/07/2026 | forum | Riccardo | «Riallineare i saldi fra le unità **a fine anno**» | roadmap, voce dedicata + v1.11 | ⚠️ **due domande fatte il 03/08, mai risposte** — sciolta il 22/08 senza di lui |
| 31/07/2026 | forum | Riccardo | Segno +/− configurabile nelle impostazioni | roadmap, «Respinte» | ⛔ respinta, motivazione inviata sul forum il 03/08 |
| 31/07/2026 | forum | @fresco | Riga di riepilogo del proprietario nella stampa (modello Millesimo) | roadmap, v1.11 stampe | 🟡 in lista |
| 31/07/2026 | forum | @fresco | Colonne stampa piano rate: preventivo \| saldi \| rate | roadmap, v1.11 stampe | 🟡 in lista |
| 03/08/2026 | forum | *critica generica* | Prodotto «troppo strict» | roadmap, principio «l'ultima decisione è dell'amministratore» | ✅ diventata principio di design |
| 04/08/2026 | forum | amministratore del «punto 6» | **Percentuali sul preventivo per rata** (50 / 25 / 25) | roadmap, v1.11 | 🟡 in lista *(⚠️ migrazione)* |
| 04/08/2026 | forum | amministratore del «punto 6» | Scadenze arbitrarie per rata | `calendario_rate.md`, Fase 2 | 🟡 1.10.1 |
| ~08/2026 | forum | Riccardo | La ritenuta sembra pagata insieme alla fattura (era: manca l'F24) | roadmap, F24 | ✅ diagnosi corretta, F24 in lavorazione |
| ~08/2026 | forum | Riccardo | Inserire il **totale fattura** invece di imponibile + IVA | roadmap, «NON pianificate» | 🟡 decisione di prodotto aperta |
| ~08/2026 | forum | Riccardo | Override manuale dell'importo della ritenuta in registrazione | roadmap, «NON pianificate» | ⛔ respinta con motivazione (tracciabilità della base) |
| ~08/2026 | forum | @fresco | Ritenuta calcolata solo su parte dell'imponibile | — | ✅ esisteva già dalla beta.21 |
| ~08/2026 | forum | Riccardo + @fresco | Pagamenti, incassi e giroconti non si possono eliminare, solo stornare | roadmap, In coda ④ | 🟡 1.10.1, dipende dalla soglia di cristallizzazione |
| ~08/2026 | forum | Riccardo | Lo storno genera una nota di credito che il fornitore non ha mai emesso | roadmap, In coda ④ | 🟡 riconosciuto come difetto |
| ~08/2026 | forum | *ritenute* | Opzione «versa sempre mensilmente» | roadmap, «NON pianificate» | 🟡 da fare, non da valutare |
| 12/08/2026 | forum | amministratore | Subentro in corso d'anno: due scenari con date | `subentro_e_competenza_temporale.md` | 🟡 1.10.1 + v1.11 |
| **22/08/2026** | **mail privata** | **amministratore non identificato — mail non ritrovata** | **Modifica manuale delle rate: arrotondamento, e rata fissa «a prescindere dal preventivo». Più: date delle rate inserite a mano** | roadmap, v1.11 | 🟡 **vedi sotto: sono due famiglie, non una richiesta** |
| **23/08/2026** | **mail privata** | **amministratore dei file Danea** *(nome noto a Vincenzo, da scrivere qui)* | **Un foglio Excel da compilare e dare in pasto a KM, invece dell'export** | `modelli_import_manuale.md`, v1.10.1 | 🟡 **già progettato: cinque fogli, già provati su una chiusura reale** |
| **23/08/2026** | **mail privata** | **amministratore dei file Danea** | **DIFETTO — la colonna di una tabella millesimale nel riparto non vale il deliberato** (preventivo € 1.000, colonna € 1.214,36) | `RipartoTabelleService.php`, riga 276 | 🔴 **confermato sui suoi file, non ancora corretto** |
| **23/08/2026** | **mail privata** | **amministratore dei file Danea** | **DIFETTO — nel PDF dello scadenziario i crediti spariscono dalle celle ma restano nei totali** | `_tabella_scadenziario.blade.php`, riga 54 | 🔴 **confermato, correzione di una riga** |
| **23/08/2026** | **mail privata** | **amministratore dei file Danea** | Dove debba atterrare il pregresso: tutto in «Addebito diretto», o spartito fra le colonne? | — | ⚠️ **domanda fatta il 23/08, in attesa di risposta** |

*Nota sulle date «~08/2026»: i tre thread del forum su F24, ritenute ed eliminazione documenti non
hanno una data registrata in roadmap. Vanno datati rileggendo il forum — è il tipo di buco che
questo registro esiste per non riprodurre.*

---

## Le parole testuali, dove le abbiamo

### Riccardo — 31/07/2026, forum

Sul riallineamento fra unità dello stesso soggetto:

> «*Altra funzione molto utile nel caso in cui uno stesso soggetto avesse più unità immobiliari
> sarebbe il bilanciamento dei saldi tra le stesse. Esempio: se **a fine anno** una sta a credito e
> una a debito riallinearle.*»

Sul conguaglio, la frase che ha sciolto il caso:

> «*solitamente non creo una rata a parte per il conguaglio*»

### Amministratore del «punto 6» — 04/08/2026, forum

> «*sarebbe ottimo se si potesse indicare la percentuale sulle rate oltre che la data: rata 1
> consuntivo + 50% preventivo scadenza 01/03/2026, rata 2 25% preventivo scadenza 15/05/2026,
> rata 3 25% preventivo scadenza 30/08/2026*»

Sulle scadenze:

> «*adesso abbiamo ogni specifico giorno del mese o ripartizione ogni due mesi*»

### Amministratore dei file Danea — 23/08/2026, mail privata

È l'amministratore che a **giugno 2026** ha mandato i quattro export Danea del suo condominio.
Su quei file è stato costruito l'importatore, e da quei file è uscito il difetto delle righe
`ex Pr 336 gg` — la compravendita a metà anno — che nessuna fixture aveva. Il commento in
`RipartoConsuntivoParser.php:46` («*trovato sul riparto vero di un condominio di quindici
unità*») parla del suo riparto.

Il 23/08/2026 ha importato quel condominio in Kondomanager, ha fatto un preventivo di prova e
ha mandato le stampe. **Nome da scrivere qui:** è la stessa lacuna della riga del 22/08, e
questo registro esiste per non riprodurla.

Sul foglio da compilare a mano:

> «*Sono sempre più convinto che vada fornito una tabella excel da dare in pasto a KM ove gli
> utenti copiano e incollano i dati … altrimenti è un casino (quanto meno per me che ho la
> manualità di una zolletta di zucchero).*»

Sul riparto del preventivo:

> «*Ho fatto un preventivo con amministrazione 1.000 €… un casino (sempre per colpa della
> manualità da zolletta)….riparto preventivo … bah…La quota di Taccala è di 1.277 su un totale
> di 1.214. amministrazione diventa 1.214…*»

Sul pregresso e sui titolari cessati — **quattro parole che hanno sciolto il caso**:

> «*addebito diretto sarebbe il saldo esercizio precedente 3.850,95 . al posto di 4065,19
> **degli ex non riportati**.*»

Sulla rata zero:

> «*Però non mi piace che nel prospetto rate la rata 0 che dovrebbe essere il saldo, i crediti
> non sono riportati, es barasan Albarta ha un credito di 203,72 (non riportato) ma si riporta
> quanto da versare in I rata ed il credito finale*»
>
> «*qui mi blocco … inutile andare avanti*»

**Il numero che ha chiuso il secondo caso.** Il suo esempio è verificabile in tre punti
indipendenti, e tornano tutti: nel suo file Danea la riga 16 (ruolo `Pr`) ha **Saldo finale
203,72**, cioè un credito, perché aveva versato più della sua quota; Kondomanager lo scrive a
database come **−203,72**, con il segno giusto; e nella stampa **−203,72 + 51,91 = −151,81**, che
è esattamente il totale di riga che lui vede. La cella è vuota, il totale la conta: la riga non
torna in orizzontale, e non c'è modo di ricontrollarla a mano.

**«Qui mi blocco» non è una lamentela, è una misura.** Ha smesso di provare perché non poteva
più fidarsi dei numeri sotto gli occhi. Su un prospetto che va in assemblea è la reazione giusta.

**Perché «degli ex non riportati» è il campo che vale.** L'analisi era partita dall'ipotesi che
i € 214,36 fossero «i saldi di chi ha millesimi in quella tabella». Era impreciso, e la
formulazione sarebbe finita nella risposta. Incrociando i suoi file veri: i quattro titolari
cessati valgono **€ 271,23**, i proprietari attuali **€ 485,59**, e **485,59 − 271,23 = 214,36**
al centesimo. Il difetto scatta *perché* ci sono dei subentri — in un condominio senza
compravendite a metà anno non si vedrebbe. L'aveva individuato lui, senza vedere il codice.

### Amministratore non identificato — 22/08/2026, mail privata

⚠️ **Mail non ritrovata al momento della registrazione.** Testo riportato a memoria da Vincenzo,
quindi da sostituire con l'originale appena salta fuori:

> «*Una cosa che secondo me potrebbe essere utile è la modifica manuale delle rate, così da poter
> modificare ogni rata ed arrotondarla o creare una rata fissa per ogni condòmino se così viene
> deciso in assemblea **a prescindere dal preventivo**, diversi gestionali che ho testato compreso
> quello che utilizzo lo fanno.*»
>
> «*e anche la possibilità di creare le rate con le date manualmente*»

---

## Le domande fatte e mai risposte

Tre, tutte a Riccardo, tutte poste sul forum il **03/08/2026** dentro risposte lunghe e verificate
sul codice. Nessuna ha avuto replica. Il 21/08/2026 Riccardo ha scritto che aspetta il
completamento del prodotto prima di riprendere, quindi **queste risposte non arriveranno da lui** e
le voci che le aspettavano non vanno lasciate in attesa.

| Domanda | Cosa bloccava | Come si scioglie senza di lui |
| :--- | :--- | :--- |
| «Con debiti in prima rata più crediti a scalare sei coperto al 100%, o vuoi decidere tu gli importi tipo 50-30-20?» | L'editor di percentuali | **Sciolta da altri richiedenti**: l'amministratore del «punto 6» il 04/08 e quello del 22/08 la chiedono entrambi, con parole testuali |
| «Dopo il riallineamento le due righe per unità restano col netto accanto, o spariscono entrambe?» | La forma del riallineamento fra unità | **Sciolta il 22/08**: si fa la strada 1 (le due righe restano, più il riepilogo), che la roadmap stessa dichiara «non può essere sbagliata» — e se un giorno servisse la seconda, questa ne diventa la schermata di conferma |
| «Il credito in eccesso lo vuoi compensato o restituito?» | `RIMBORSO_CONDOMINO` e `STRALCIO_CREDITO`, enum dichiarati e mai implementati | **Resta aperta**, ma non blocca niente: nessuna delle due esiste oggi, e la strada 1 non ne ha bisogno |

**La lezione, che vale oltre questo caso.** Una richiesta di funzione da chi non usa il prodotto
non è un requisito: è un'ipotesi. Il segnale che la distingue è se il richiedente **rimette
qualcosa dentro** — risponde a una domanda, prova una build, manda un file. Quando la domanda resta
senza risposta per settimane, la voce non va tenuta bloccata: va decisa sul fondamento più
prudente, e riaperta se e quando qualcuno torna con un caso concreto.

---

## La richiesta del 22/08/2026 sono due richieste

Va scritto qui perché è l'esempio migliore di cosa costa una parafrasi. «Modifica manuale delle
rate» sembra una funzione; sono due, e i costi differiscono di un ordine di grandezza. La linea che
le separa è **una sola proprietà del codice**, verificata il 22/08/2026 su 1.10.0-beta.70.

**Il fatto tecnico.** In `GenerateRateQuotesAction:34-45` la divisione è `calcolaFettina`:
`intdiv` più il resto spalmato sulle **prime** rate. La somma delle fettine è esattamente il totale
— `n × base + resto = totale`, e vale anche sui negativi, perché `intdiv` in PHP tronca verso lo
zero e il segno del resto segue quello del totale. Quella fettina è `quota_pura_gestione`
(`GenerateRateQuotesAction:172` e `:240`), ed è l'unica cosa che finisce a giornale
(`EmissioneRateController:114-119`, con le righe `<= 0` scartate).

### Famiglia A — la somma per condòmino non cambia

Percentuali per rata (50/25/25), arrotondamento della singola rata, tutto il conguaglio
sull'ultima. Sono **lo stesso lavoro**: sostituire «vettore uniforme cablato» con «vettore fornito
dal piano, default uniforme».

Qualunque vettore che sommi allo stesso totale è **indistinguibile da quello attuale per tutto ciò
che sta a valle**: stesso importo a giornale per condòmino sull'arco del piano, stesso estratto
conto, stesso incasso, stessi consumatori dello snapshot.

⚠️ **Qui non si applica il vincolo che la roadmap dichiara bloccante.** La nota dice che un importo
digitato a mano «non ha un posto dove andare, ne ha due con significato contabile opposto —
`quota_pura_gestione` o `saldo_usato`». È vero **solo se l'interfaccia è una casella dove si scrive
il numero finale**. Se l'interfaccia è una **ripartizione del preventivo**, l'ambiguità non nasce:
il numero digitato è un peso, `saldo_usato` non viene toccato, e il conguaglio continua sul suo
binario. La conseguenza pratica è che la scelta di interfaccia **è** la decisione contabile: non si
offre un importo finale editabile, si offre o una ripartizione del preventivo (famiglia A) o un
importo deliberato esplicito (famiglia B).

### Famiglia B — la somma per condòmino cambia

Una sola richiesta, ed è la frase «*rata fissa per ogni condòmino se così viene deciso in assemblea
**a prescindere dal preventivo***». € 100 × 12 = € 1.200 contro una quota da preventivo di
€ 1.450: il totale emesso non è più la quota.

Contabilmente è difendibile — l'assemblea ha deliberato di chiedere € 1.200, quindi a giornale
vanno € 1.200 di crediti verso condòmini, e i € 250 diventano esigibili con l'approvazione del
consuntivo: è un **acconto deliberato**, non un ammanco. Il lavoro vero non è la scrittura, è il
contorno: il piano deve sapere che non copre il preventivo, e il cruscotto sforo, le stampe e il
rendiconto non devono leggere quel divario come un buco.

**Questa richiesta non era registrata da nessuna parte prima del 22/08/2026.** Verificato con
`grep` su tutta la cartella: «rata fissa» e «a prescindere dal preventivo» non comparivano in
nessun documento.

### Cosa dicono insieme, e perché conta più della somma delle parti

Date arbitrarie, percentuali sul preventivo, rata fissa: sono la stessa frase detta tre volte da
tre amministratori diversi. **Il piano rate lo delibera l'assemblea, e il gestionale deve poterlo
trascrivere.**

Oggi KondoManager lo *genera* dal preventivo e all'amministratore lascia il giorno del mese e la
frequenza. È più grave della somma delle tre voci per una ragione già scritta nella roadmap in un
altro contesto: **lo scadenziario ha valenza legale (art. 1135 c.c.)**. Se non può coincidere con
la delibera, documenta qualcosa che l'assemblea non ha approvato, e l'amministratore deve
disobbedire o all'assemblea o al software.

Il principio «l'ultima decisione è dell'amministratore» qui è più stretto del solito: non è
nemmeno una sua facoltà, è un **obbligo di esecuzione** di una delibera altrui.

⚠️ **E tocca l'importatore, che è il punto che sposta la priorità.** Un amministratore che importa
un condominio a metà anno ha una delibera in corso: date decise, importi decisi, magari rate fisse.
Se KondoManager sa solo rigenerare un piano uniforme dal preventivo, le rate del condominio
importato **non coincidono con quello che i condòmini hanno già ricevuto**. Cioè: la trascrizione
manuale del piano non è una comodità, è un **prerequisito perché l'importatore sia usabile fuori
dalla finestra del primo gennaio** — vedi [`import_migrazione_dati.md`](import_migrazione_dati.md).

*Sequenza proposta il 22/08/2026, **da confermare con Vincenzo**: famiglia A più date manuali
insieme, come una griglia sola «data + peso per rata» con le modalità attuali come preset che la
precompilano; famiglia B dopo, come beta a sé. La roadmap era già arrivata da sola a suggerire la
griglia unica — «costruirli separati significa reimparare tre volte gli stessi vincoli».*
