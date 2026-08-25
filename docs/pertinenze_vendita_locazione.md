<!-- verifica-documentazione -->
> **Stato:** Implementato in parte — il solo legame, **rilasciato nella 1.10.0-beta.53 (16/08/2026)**; tutto ciò che riguarda il *tempo* resta progettazione.
> **Esiste** il campo «Pertinenza di» come deciso in D6: chiave esterna `immobili.pertinenza_di_immobile_id` più `pertinenza_di_esterna` per il caso Tognoli. `immobile_pertinenza` e il model `Pertinenza` sono stati rimossi nella stessa beta, come la decisione prevedeva.
> ⛔ **Non esistono** le date sul legame — D6 le prevedeva e sono state deliberatamente rimandate al primo calcolo che le legga, vedi D4 — né l'operazione di passaggio di titolarità, né il pannello «Cosa cambierà», né lo storico, né il pro-rata. La pivot `anagrafica_immobile` ha ancora `data_inizio`/`data_fine` scritte e mai lette da nessun calcolo. Il §6 è **l'interfaccia del blocco B2 della 1.11**, non una funzione delle pertinenze: chi lo legge come descrizione di ciò che il programma fa oggi sbaglia.
<!-- /verifica-documentazione -->

# Pertinenze: cosa succede quando il box si vende o si affitta

## 1. La domanda, e perché non è piccola

La domanda di partenza era: «cosa succede se il proprietario di un box auto decide di affittarlo o venderlo?». La risposta breve è che non succede quasi niente al box e succede quasi tutto al resto del programma. Il legame di pertinenza resta ciò che la ricerca precedente aveva stabilito — presentazione, nessun millesimo, nessun riparto, nessun quorum — ma la domanda, posta seriamente, non è una domanda sulle pertinenze: è una domanda sul tempo, e il tempo in KondoManager oggi non esiste dentro il motore di riparto.

La cosa più importante che questa ricerca ha trovato, però, è un'altra, ed è una correzione a una conclusione che stava per essere scritta come regola unica: **il criterio con cui si decide chi paga non è uno solo, è doppio, e dipende dalla natura della spesa.** Per le spese straordinarie — innovazioni, manutenzione straordinaria, ristrutturazioni — l'obbligazione sorge con la delibera assembleare che approva l'esecuzione dei lavori definendone opere e prezzo, e quella delibera ha valore costitutivo anche se le opere sono eseguite dopo il rogito (Cass. civ. sez. VI-2, ord. 22 giugno 2017 n. 15547; Cass. civ. sez. II, ord. 10 settembre 2020 n. 18793; Cass. civ. 20 marzo 2025 n. 7490; Cass. civ. 30 agosto 2025 n. 24236, in continuità con Cass. n. 25839/2019, che precisa che rileva la delibera *attuativa* e non quelle preparatorie). Per le spese **ordinarie** di gestione, invece, l'obbligazione sorge nel momento in cui si compie l'attività gestionale che le giustifica, e l'approvazione del preventivo non è costitutiva: serve a vagliare la congruità della previsione (Cass. civ. sez. II, 3 dicembre 2010 n. 24654; Cass. civ. sez. II, ord. 3 agosto 2022 n. 24069, per cui l'obbligo di contribuire deriva dalla concreta attuazione dell'attività di manutenzione e non dalla previa approvazione e ripartizione). La distinzione è affermata testualmente proprio nell'ordinanza che viene di solito citata a sostegno del criterio unico, Cass. civ. sez. VI-2, 28 aprile 2021 n. 11199: obbligato è chi è proprietario al momento dell'esecuzione per le spese ordinarie, chi lo era al momento della delibera per straordinarie e innovazioni.

Il numero sbagliato che ne deriverebbe è misurabile. Condominio con esercizio solare, preventivo ordinario approvato il 15 gennaio, rogito il 30 giugno. Se si adottasse `piani_rate.data_delibera_assemblea` come unica data di competenza, l'intero esercizio ordinario resterebbe al venditore; con la regola vera, l'ordinario del secondo semestre compete all'acquirente. Su un condominio da € 120.000,00 di gestione ordinaria significa attribuire € 60.000,00 alla persona sbagliata — e siccome il conguaglio proposto è una coppia di righe in `saldi` che somma a zero, l'errore verrebbe cristallizzato in contabilità invece che restare un'anteprima da correggere.

La seconda conseguenza di questa biforcazione è che il conguaglio non può avere una sola forma. Per l'ordinario la ripartizione pro rata temporis è un'approssimazione accettabile, perché la competenza segue l'erogazione. Per lo straordinario è falsa: l'intero importo resta a chi era proprietario alla data della delibera attuativa, e il conguaglio è una funzione a gradino, non una proporzione. Un motore che scrivesse un'unica coppia pro-rata su una gestione che contiene sia ordinario sia straordinario produrrebbe un numero giusto per metà.

La terza cosa che la domanda porta con sé riguarda le date, ed è la ragione per cui questo documento non può essere scritto senza toccare il documento gemello `docs/subentro_e_competenza_temporale.md`. Le date rilevanti non sono una ma tre, e nessun gestionale osservato le distingue: la **data dell'atto**, che decide chi deve; la **data di trasmissione all'amministratore della copia autentica del titolo**, che è l'unica cosa che libera il venditore verso il condominio (art. 63 co. 5 disp. att. c.c.) e che non coincide quasi mai col rogito; e la **data della delibera o dell'attività gestionale**, che decide a quale dei due la spesa compete.

## 2. La vendita

Gli scenari che seguono sono in ordine di frequenza reale su un parco condomini medio, non in ordine di interesse giuridico.

### 2.1 Appartamento e box venduti insieme al medesimo acquirente

È il caso ordinario. L'art. 818 co. 1 c.c. dispone che gli atti e i rapporti giuridici che hanno per oggetto la cosa principale comprendono anche le pertinenze, «se non è diversamente disposto», e la pertinenza si trasferisce anche se non menzionata nell'atto (Cass. civ. sez. II, ord. 23 agosto 2019 n. 21656). Il legame pertinenziale sopravvive, perché resta l'identità del proprietario. Verso il condominio l'acquirente subentra su entrambe le unità.

Il programma deve proporre l'estensione del passaggio alle pertinenze collegate, mostrando la nota che l'art. 818 co. 1 c.c. le fa seguire salvo diversa disposizione dell'atto. Deve però proporla **con la casella non spuntata**, e qui va corretta una conclusione che sia la ricerca sulla vendita sia quella sull'interfaccia avevano dato per buona (spunta preselezionata). L'art. 818 co. 1 c.c. è una regola di interpretazione dell'atto *fra le parti*, non una regola di prova per l'amministratore. Verso il condominio conta l'art. 63 co. 5 disp. att. c.c.: se il titolo consegnato menziona solo l'appartamento, una casella preselezionata scrive in anagrafe un trasferimento del box privo di titolo, libera un soggetto che la legge tiene ancora obbligato e addebita rate a un soggetto contro cui l'amministratore non ha titolo. In KondoManager non è cosmetico: `rate_quote.immobile_id`, `contributi_versati.immobile_id` (NOT NULL, cascade, dichiarata in migrazione «chiave di netting») e `saldi.immobile_id` sono tutti agganciati all'unità, quindi cambiare l'anagrafica sul box sposta il destinatario delle rate *e* del pregresso. La casella la spunta l'operatore, non il programma.

Cosa non deve fare: trasferire le pertinenze in silenzio, e rifiutarsi di registrare un trasferimento della sola unità principale con la pertinenza che resta al venditore, che è precisamente la deroga che l'art. 818 co. 1 c.c. ammette.

### 2.2 Rogito a metà esercizio, con rate del piano già emesse

L'emissione della rata non crea l'obbligazione. La rata è un calendario di pagamento: una rata con scadenza successiva al rogito può coprire spese ordinarie già erogate prima, o una straordinaria deliberata prima. Derivare la competenza dalla scadenza della rata produce un conguaglio sbagliato ogni volta che la cadenza delle rate non coincide con la maturazione della spesa, cioè quasi sempre.

Verso il condominio operano l'art. 63 co. 4 disp. att. c.c. — «chi subentra nei diritti di un condomino è obbligato solidalmente con questo al pagamento dei contributi relativi all'anno in corso e a quello precedente», limite biennale inderogabile e riferito ai contributi dell'unità acquistata — e il co. 5, per cui il cedente resta obbligato in solido fino alla trasmissione della copia autentica del titolo. Nei rapporti interni la morosità pregressa del venditore resta sua, e l'acquirente che paga in forza della solidarietà ha regresso integrale (Cass. civ. sez. II, 28 aprile 2021 n. 11199): la solidarietà rafforza il credito del condominio, non sposta il peso economico.

L'«anno in corso e quello precedente» si intende come periodo annuale costituito dall'esercizio della gestione condominiale, non necessariamente coincidente con l'anno solare (Cass. civ. sez. VI-2, ord. 22 marzo 2017 n. 7395, rel. Scarpa). Questo definisce il contenitore, non il fatto da collocarci dentro: il test di appartenenza al biennio ha due chiavi, la data della delibera attuativa per lo straordinario e il momento in cui l'attività gestionale è stata compiuta per l'ordinario (Cass. civ. sez. VI, ord. 25 gennaio 2018 n. 1847). Senza questa precisazione la nota di solidarietà è implementabile in due modi che danno importi diversi.

Il programma deve: lasciare intatte le rate già emesse; calcolare il conguaglio sulla **competenza** e mai sullo stato di pagamento; registrarlo con una coppia di righe in `saldi` sulla stessa gestione e sullo stesso immobile, credito all'uscente e debito all'entrante, che sommano esattamente a zero — ma con due algoritmi distinti, pro rata temporis per le voci ordinarie e a gradino sulla data di delibera per le voci straordinarie. Deve esporre nell'estratto conto dell'unità una nota di solidarietà calcolata, distinguendo in stampa «quota di competenza» da «a chi posso chiedere».

Non deve: rigenerare o stornare le rate emesse; intestare quote all'acquirente in nome della solidarietà; inviargli solleciti automatici per la morosità del venditore; azzerare la morosità dell'uscente compensandola col conguaglio.

### 2.3 Il rogito è avvenuto, la copia autentica del titolo non è arrivata

L'art. 63 co. 5 disp. att. c.c. è l'unico atto che chiude l'esposizione del venditore verso il condominio, ed è diverso e successivo al rogito. Sul piano dell'anagrafe vale invece l'art. 1130 n. 6 c.c.: ogni variazione dei dati va comunicata all'amministratore in forma scritta entro sessanta giorni, e in caso di inerzia l'amministratore la richiede con raccomandata e, decorsi trenta giorni, acquisisce le informazioni addebitandone il costo ai responsabili.

Le due norme non si sommano, e va corretto un ragionamento che era circolato nella ricerca sul legame: il mancato deposito della copia autentica **non** è un motivo per dubitare del cambio di titolare né per rinviare la registrazione. L'art. 63 co. 5 disciplina soltanto la persistenza della solidarietà del cedente; non dice nulla su chi debba risultare titolare nel registro e non proroga il termine dell'art. 1130 n. 6, che è un obbligo del condòmino e non un permesso di disallineamento. L'amministratore che rinvia la registrazione continua ad addebitare il solo cedente e perde la solidarietà dell'acquirente per l'anno in corso e il precedente.

La conseguenza software è netta: registrare subito il nuovo titolare, e tenere separato un dato «copia autentica del titolo ricevuta il …», nullable, dal quale dipende se il cedente risulta ancora solidale. Due date distinte sul trasferimento, `data_atto` e `data_ricezione_titolo`. Finché la seconda è vuota, sull'unità compare l'indicazione che il venditore resta obbligato ex art. 63 co. 5 disp. att. c.c.

### 2.4 Lavori straordinari deliberati prima del rogito ed eseguiti dopo

Vale il criterio della delibera attuativa esposto in apertura. `piani_rate.data_delibera_assemblea` è la data di competenza dello straordinario e va etichettata nella maschera «delibera che approva i lavori e il prezzo», non genericamente «delibera». Quando su un capitolo esistono più delibere, la scelta di quale sia l'attuativa è dell'amministratore: il programma la registra e la motiva, non la deduce. Il gradino di risoluzione usato va congelato in `regole_calcolo.parametri`, insieme alla data.

Non serve un parametro di configurazione «criterio delibera / criterio esecuzione», che sarebbe un'opzione a scelta dell'utente su una materia che non è discrezionale. Serve invece che la regola sia **doppia e agganciata alla natura della gestione**, e che sia possibile dichiarare sulla fattura o sulla copertura un periodo di competenza diverso dal default, perché la diversa convenzione fra le parti è ammessa e capita.

Non deve: usare la data della fattura, del SAL o del pagamento per lo straordinario; usare la data di approvazione del riparto, che non rileva.

### 2.5 Morosità pregressa e recupero verso chi ha venduto

Perfezionato il trasferimento non è più possibile ottenere contro l'alienante il decreto ingiuntivo immediatamente esecutivo dell'art. 63 co. 1 disp. att. c.c., che presuppone la qualità di condomino: resta il ricorso ordinario ex art. 633 c.p.c., privo di provvisoria esecutorietà (Cass. civ. sez. VI-2, ord. 22 giugno 2017 n. 15547). Cass. civ. 20 marzo 2025 n. 7490 riconosce inoltre al nuovo proprietario la possibilità di opporre in sede esecutiva la propria estraneità personale al debito.

Va però corretta la lettura di Cass. civ. sez. II, ord. 3 agosto 2022 n. 24069, che era stata usata come scudo dell'ex condomino: la Corte afferma che l'alienante resta obbligato per le spese di gestione maturate mentre era proprietario **anche se il consuntivo è approvato dopo la vendita**, che la delibera di approvazione del rendiconto ha valore ricognitivo ed è idoneo elemento probatorio anche verso l'ex condomino, e che questi non può limitarsi a eccepire la non vincolatività della delibera ma deve contestare le singole voci di spesa. Un avviso che dicesse all'amministratore «l'ex condomino non è vincolato dalle delibere successive» lo dissuaderebbe da un recupero disponibile.

Il programma deve legare le posizioni debitorie alla terna (anagrafica, immobile, esercizio) e non alla sola anagrafica, così che vendere il box non trascini la morosità dell'appartamento. Sulla generazione dei solleciti deve mostrare il perimetro esatto e, se il soggetto non è più titolare di quell'unità alla data, cambiare il testo del documento segnalando che la via è il monitorio ordinario. Deve consentire l'imputazione esplicita di un incasso a un debito indicato: anni di gestione e delibere diverse sono rapporti obbligatori distinti e il debitore conserva il diritto di designazione, mentre in mancanza si applicano i criteri dell'art. 1193 c.c.

### 2.6 Vendita del solo box, separatamente dall'appartamento

L'art. 818 co. 2 c.c. consente che le pertinenze formino oggetto di separati atti giuridici: la vendita è pienamente valida e fa venir meno il vincolo pertinenziale, perché l'art. 817 c.c. richiede che i due beni appartengano al medesimo proprietario (Cass. civ. sez. II, 21 luglio 2021 n. 20911; Cass. civ. n. 13742/2019, non riverificata su fonte primaria). Non serve una dichiarazione espressa e formale: l'atto dispositivo separato basta (Cass. civ. sez. II, 26 maggio 2004 n. 10147; Cass. civ. sez. II, 18 maggio 1994 n. 4832). La cessazione non è però opponibile ai terzi che abbiano anteriormente acquistato diritti sulla cosa principale (art. 818 co. 3 c.c.).

Millesimi, tabelle, riparto e saldi non cambiano. Cambiano il titolare del box e, se l'acquirente è un esterno, il numero delle teste.

Il programma deve registrare il nuovo titolare sul solo immobile «box» con una decorrenza, e proporre — non eseguire — la chiusura del legame con una **data di cessazione**, spiegando che il presupposto soggettivo dell'art. 817 c.c. è venuto meno. Non deve cancellare il legame senza traccia, non deve cancellare l'immobile né riassegnarne i saldi, non deve toccare tabelle millesimali o rate già emesse.

### 2.7 Il box è venduto a un altro condòmino dello stesso stabile

Il vincolo verso l'appartamento del venditore si scioglie e può ricostituirsi verso un'unità dell'acquirente, se questi la destina a servizio di essa: è un atto di volontà, non una conseguenza del possesso di due unità. Il numero dei condòmini non aumenta, perché chi possiede più unità nello stesso edificio conta per una sola testa (Cass. civ. sez. II, ord. 12 novembre 2020 n. 25558); cambiano i millesimi che quella testa esprime.

Va però corretta la formula «una testa per anagrafica» come se fosse un attributo stabile. L'art. 67 co. 6 e 7 disp. att. c.c. attribuisce all'usufruttuario il voto negli affari di ordinaria amministrazione e di semplice godimento delle cose e dei servizi comuni, e al nudo proprietario il voto nelle innovazioni, ricostruzioni e manutenzioni straordinarie. Sulla stessa unità la testa cambia soggetto a seconda dell'ordine del giorno: non esiste un unico numero di teste del condominio, ne esistono due insiemi. Un box in usufrutto è esattamente il caso in cui questo morde.

Sul lato contabile va aggiunto ciò che manca: l'art. 67 ultimo comma disp. att. c.c. stabilisce che nudo proprietario e usufruttuario rispondono **solidalmente** dei contributi verso il condominio. Una colonna «a chi posso chiedere» alimentata dalla cascata dei ruoli del motore — che seleziona un solo soggetto — mostrerebbe un obbligato invece di due.

### 2.8 Il box è venduto a un esterno, che diventa condomino solo per il box

Il proprietario del solo posto auto è condomino quando il bene è in rapporto di accessorietà strutturale e funzionale con le parti comuni. La pronuncia che si cita di solito è Cass. civ. sez. II, ord. n. 884/2018, che le fonti consultate collocano al **16 gennaio 2018** e non al 25 gennaio: la data va corretta prima che finisca in una guida. E va corretta anche la portata: la decisione riguardava posti auto *scoperti* e la Corte è arrivata alla qualità di condomino attraverso il titolo, il regolamento e le tabelle millesimali di quel complesso, cioè attraverso un accertamento in concreto dell'accessorietà ex art. 1117 c.c. Non è una regola astratta per cui chiunque possieda un box in un edificio è condomino: se il box sta in un corpo di fabbrica autonomo, senza parti comuni con l'edificio, la conclusione può essere diversa.

Il perimetro delle spese non è comunque quello pieno: l'art. 1123 co. 2 c.c. gradua secondo l'uso e il co. 3 pone a carico del solo gruppo interessato le spese delle parti destinate a servire una parte soltanto dell'edificio.

Il programma deve creare l'anagrafica, collegarla al box con decorrenza, includere il nuovo condomino negli elenchi di convocazione e nel conteggio delle teste, e segnalare i capitoli in cui il box compare con quota zero o non compare affatto, perché è lì che nasce lo scoperto silenzioso. Non deve iscriverlo d'ufficio a tutte le tabelle né escluderlo d'ufficio da alcuna, e non deve filtrare i convocandi per tipologia di immobile.

### 2.9 Il box è un parcheggio soggetto a vincolo di legge

Qui la ricerca precedente aveva collassato due regimi in uno e attribuito la nullità a quello sbagliato. L'art. 9 L. 24 marzo 1989 n. 122 contiene due regole distinte:

- i parcheggi realizzati **ai sensi del comma 1** — dai proprietari di immobili, nel sottosuolo o nei locali al piano terreno, da destinare a pertinenza — possono essere trasferiti, nel testo del co. 5 sostituito dall'art. 10 D.L. 9 febbraio 2012 n. 5 conv. L. 4 aprile 2012 n. 35, «solo con contestuale destinazione del parcheggio trasferito a pertinenza di altra unità immobiliare sita nello stesso comune», anche in deroga al titolo edilizio e ai successivi atti convenzionali. Per questi la cessione separata **è ammessa** con contestuale ridestinazione, e la sanzione testuale di nullità che colpiva la cessione separata è caduta con quella riforma;
- i parcheggi realizzati **ai sensi del comma 4** — su aree comunali o nel loro sottosuolo, in diritto di superficie, previa convenzione — non possono essere ceduti separatamente dall'unità a cui sono legati, e i relativi atti sono **nulli**, salvo espressa previsione nella convenzione col comune o autorizzazione del comune.

È il secondo il caso in cui il box davvero non si muove, ed è quello che un catalogo a tre voci (nessuno / Tognoli / 41-sexies) non contempla: un parcheggio su area comunale finirebbe classificato «Tognoli» e il programma chiederebbe l'unità di nuova destinazione invece di segnalare che serve il comune.

Esiste poi un terzo e un quarto regime. L'art. 41-sexies L. 17 agosto 1942 n. 1150 pone uno standard urbanistico e non un vincolo pertinenziale soggettivo, e i parcheggi eccedenti lo standard sono liberamente trasferibili (Cass. civ. Sez. Un., 15 giugno 2005 n. 12793; nello stesso senso Cass. civ. sez. II, 3 febbraio 2012 n. 1664, per cui il costruttore può riservarseli o cederli a terzi). Il co. 2 aggiunto dall'art. 12 co. 9 L. 28 novembre 2005 n. 246 esclude vincoli pertinenziali e diritti d'uso ed è norma innovativa e non retroattiva, applicabile alle costruzioni successive al 16 dicembre 2005; per i fabbricati anteriori la posizione secondo cui il vincolo produce un diritto reale d'uso con nullità parziale della clausola di cessione separata, sostituita di diritto ex art. 1419 co. 2 c.c., è riportata da fonti secondarie specializzate ed è **controversa**.

Il programma può portare un attributo di regime urbanistico sull'immobile con default «da verificare» — mai una presunzione — e può mostrare un promemoria testuale al momento della vendita del solo box. Non deve classificare il regime da solo: la distinzione fra parcheggio dentro e oltre lo standard richiede un calcolo volumetrico che il gestionale non possiede, e la distinzione fra comma 1 e comma 4 sta nella convenzione col comune. Non deve dichiarare nullo alcunché, e non deve scrivere «in difetto l'atto è nullo» su un parcheggio comma 1, che oggi non ha appiglio testuale. E non deve mai bloccare la registrazione: l'amministratore registra un fatto avvenuto, e il controllo l'ha già fatto il notaio.

Il caso Tognoli comma 1 ha però un effetto strutturale sul campo: il vincolo non si estingue, si **sposta** su un'unità dello stesso comune, che può stare in un altro condominio o non essere gestita dal programma. Il campo «Pertinenza di» deve quindi avere due forme, un puntatore interno al condominio e una descrizione esterna in testo libero con documento allegato. Un campo che accetta solo il puntatore costringe l'amministratore a lasciarlo vuoto, cioè a dire il falso.

### 2.10 Il fondo speciale ex art. 1135 co. 1 n. 4 c.c. e il fondo cassa

La questione è **controversa** e il programma non deve prendere posizione. Va però corretto il modo in cui il conflitto era stato descritto.

Sul fondo cassa ordinario, quello che le fonti secondarie riferiscono di Cass. civ. n. 17036/2016 (testo integrale non consultato) non è che nasca un credito dell'acquirente, ma che, in mancanza di diverso accordo, la somma resta **acquisita al condominio**: il venditore non può chiederne la restituzione all'amministratore, avendo perso ogni ragione di credito, e l'acquirente non deve rimborsargli nulla. Nasce l'assenza di un credito del venditore, non un credito dell'acquirente. Mostrare quella giacenza come posta a favore dell'acquirente nell'attestazione o nel fascicolo di consegna esporrebbe una partita inesistente, che nel modello di questo progetto — fondi come partizioni attive del c/c, saldi da `SaldoCassaService` — non ha nemmeno contropartita.

Sul fondo speciale per lavori non eseguiti, Trib. Palermo 25 maggio 2022 n. 2252 fonda la restituzione sull'art. 2033 c.c.: venuta meno la causa debendi perché i lavori deliberati non sono stati eseguiti, il pagamento diventa indebito oggettivo e chi ha versato può ripetere. È un'azione di ripetizione, **non** un effetto subordinato a una delibera di revoca del fondo: quel requisito, che pure circola nelle rassegne, non risulta dalla sentenza. Scrivere in interfaccia «per la restituzione serve una delibera assembleare» farebbe negare all'amministratore una restituzione dovuta.

Il programma mostra la giacenza attribuibile all'unità, la data della delibera che l'ha costituita, e la nota che la sorte di quella somma è materia di accordo fra le parti. Nessun giroconto automatico, nessuna riattribuzione, nessuna seconda cassa.

### 2.11 Detrazioni fiscali in corso

L'art. 16-bis co. 8 D.P.R. 917/1986 stabilisce che in caso di vendita dell'unità sulla quale sono stati eseguiti gli interventi la detrazione non utilizzata è trasferita per i rimanenti periodi d'imposta all'acquirente persona fisica, **salvo diverso accordo delle parti**, che può risultare dall'atto o da scrittura privata autenticata.

Qui va corretto un criterio che era stato dato per buono e che è sbagliato. Il titolare **alla data del bonifico** non è il criterio di attribuzione della rata. L'Agenzia delle Entrate, sulla propria pagina istituzionale «Quando si trasferisce la detrazione», e la Circolare n. 95/E del 12 maggio 2000, punto 2.1.14, individuano il soggetto che possedeva l'immobile **al 31 dicembre** dell'anno cui la rata si riferisce. La data del bonifico dell'amministratore individua l'*anno d'imposta* cui la spesa si riferisce, non la *persona* cui la rata di quell'anno spetta. Esempio: bonifico il 10 marzo, rogito il 5 settembre. Un prospetto costruito sul titolare alla data del bonifico indicherebbe il venditore; la rata di quell'anno spetta per intero all'acquirente, e con dieci rate residue l'errore si propaga su tutto il piano.

Due aggravanti. In caso di costituzione di usufrutto la detrazione residua resta al **nudo** proprietario, il che rompe l'equivalenza fra «titolare» e «chi gode del bene». E il secondo requisito noto per le parti comuni — che la detrazione competa nei limiti della quota effettivamente versata dal condòmino al condominio entro il termine di presentazione della dichiarazione, prassi consolidata dalla Circolare 122/E del 1° giugno 1999 par. 4.8 alla Circolare 7/E del 25 giugno 2021 — è un **limite di importo**, non un criterio soggettivo, e va tenuto su una colonna diversa.

Il programma produce un prospetto: per ciascun bonifico dell'amministratore, data, importo, quota imputata all'unità, e — in colonna separata — la quota effettivamente versata dal condòmino con la sua data. L'attribuzione della rata la fa il contribuente nella propria dichiarazione. Il programma non decide a chi spetta, non applica percentuali, non simula risparmi fiscali, e ricorda nel fascicolo di consegna che l'atto può contenere un accordo diverso.

### 2.12 La comunicazione all'Agenzia delle Entrate per la precompilata

L'amministratore in carica al 31 dicembre trasmette entro il 16 marzo i dati sugli interventi nelle parti comuni (D.M. MEF 1° dicembre 2016 e provvedimenti attuativi; il provvedimento prot. n. 50559/2026 del 10 febbraio 2026 aggiorna le specifiche allegate al provvedimento n. 19969 del 27 gennaio 2017). L'omessa, tardiva o errata comunicazione comporta una sanzione riferita a 100 euro per comunicazione con massimo di 50.000 euro, e non fa perdere la detrazione.

Va corretta l'idea che il Flag unità immobiliare si derivi dal legame di pertinenza. Nelle FAQ dell'Agenzia per i professionisti il valore A è «unità abitativa con eventuali relative pertinenze» e il valore B è «unità non abitativa»: il discrimine primario è la natura abitativa o non abitativa dell'unità, cioè un dato catastale. Il legame decide un'altra cosa — se la pertinenza ha un record proprio o viene assorbita in quello dell'unità principale, e quanto vale il campo con il numero delle pertinenze autonomamente accatastate incrementato di uno. Derivare il flag dal legame dà il valore giusto per caso sul box sciolto e sbagliato su tutto il resto: un negozio o un ufficio che partecipa alla spesa è B pur non essendo pertinenza di nessuno.

Va anche segnalato un rischio interno: `tipologie_immobili` in questo repository porta già una colonna `categoria` con valore `pertinenza` su Box, Cantina, Posto auto e Garage. Non è l'informazione abitativo/non abitativo e non va usata come tale.

Sul resto delle specifiche tecniche il documento deve essere onesto: **non verificato**. L'Allegato 1 al provvedimento 50559/2026 non è estraibile dal PDF pubblicato, che è privo di livello di testo. Le formulazioni sul record di dettaglio per soggetto, sul Flag Pagamento e sulla Tipologia del soggetto restano senza riscontro su fonte primaria, e con esse la ricostruzione secondo cui una vendita a metà anno produrrebbe due record di dettaglio per la stessa unità. Va verificata con il software di controllo formale prima di implementarla.

Resta però il punto che riguarda questo documento: se e quando quella regola sarà confermata, il flag e il conteggio andranno derivati dallo stato del legame **alla data rilevante per l'anno comunicato**, non dallo stato odierno. Ed è il primo motivo tecnico per cui il legame deve essere datato.

### 2.13 Frazionamento del box per venderlo

Qui va corretta la conclusione precedente, che collocava il frazionamento fuori dall'art. 69 disp. att. c.c. Il testo dice il contrario: il co. 1 stabilisce che i valori proporzionali possono essere rettificati o modificati **all'unanimità**; il co. 2 ammette la maggioranza dell'art. 1136 co. 2 c.c. in due soli casi, e il secondo menziona espressamente l'ipotesi in cui, per mutate condizioni di una parte dell'edificio in conseguenza di sopraelevazione, incremento di superfici o **incremento o diminuzione delle unità immobiliari**, il valore proporzionale anche di una sola unità risulti alterato per più di un quinto.

Il frazionamento è quindi già dentro l'orizzonte dell'art. 69. Spezzare i millesimi di un'unità in due righe è una modifica della tabella millesimale e, sotto la soglia del quinto, la strada non è la maggioranza ma l'unanimità: non è un atto che l'amministratore compia da solo in anagrafe. Il controllo di invarianza della somma è necessario ma non sufficiente, perché la ripartizione interna fra le due nuove unità è una stima, e una stima nessuno l'ha approvata.

Il programma può offrire uno sdoppiamento che crei la seconda unità, chieda la ripartizione dei millesimi in ciascuna tabella e blocchi finché la somma non torna identica; ma deve pretendere il riferimento all'atto che ha approvato la nuova ripartizione, e conservare l'unità originaria come chiusa e non cancellata. Una tabella scritta senza titolo regge tutti i riparti successivi.

## 3. La locazione

### 3.1 Il conduttore è debitore verso il condominio?

No, e il codice del progetto ha ragione. L'amministratore ha diritto di riscuotere i contributi ex artt. 1123 c.c. e 63 disp. att. c.c. «direttamente ed esclusivamente da ciascun condomino», restando esclusa un'azione diretta verso i conduttori, anche per le spese di riscaldamento e anche se i conduttori hanno votato ai sensi dell'art. 10 L. 392/1978 (Cass. civ. 14 luglio 1988 n. 4606; Cass. civ. 12 gennaio 1994 n. 246; Cass. civ. 13 gennaio 1995 n. 384; Cass. civ. 3 agosto 2007 n. 17039; Cass. civ. 24 giugno 2008 n. 17201). L'obbligazione del conduttore ha natura di rimborso verso il solo locatore, si cumula col canone, ed è sanzionata con la risoluzione ex art. 5 L. 392/1978 quando la morosità supera l'ammontare di due mensilità. Il patto con cui il conduttore si obbliga a pagare direttamente all'amministratore non costituisce accollo opponibile al condominio, e la prassi del pagamento diretto non genera obbligazioni (Cass. civ. 13 settembre 2006 n. 19650).

La motivazione scritta in `RuoloAnagraficaImmobile::titolariDiDirittoReale()` — «l'inquilino non c'è: il suo rapporto è con il locatore (gli oneri accessori dell'art. 9 L. 392/1978), non con il condominio, che non ha titolo per escuterlo» — è quindi **confermata**, e andrebbe rafforzata con il contrasto: l'art. 67 co. 6, 7 e ultimo comma disp. att. c.c. costruisce per l'usufruttuario un regime di voto e una solidarietà espressa verso il condominio; per il conduttore nulla di simile esiste. La differenza fra usufruttuario e inquilino non è di grado ma di natura.

Due precisazioni, però, contro un divieto troppo largo. La prima: l'accollo ex art. 1273 c.c. al quale il creditore **aderisce** rende la stipulazione irrevocabile in suo favore, ed è la ragione per cui Cass. 19650/2006 deve precisare che il patto *in sé* non basta. Un blocco strutturale che vieti a qualsiasi riga di portare il nome del conduttore chiuderebbe anche il caso lecito. La seconda: il prospetto degli oneri accessori ha bisogno esattamente di quel dato per esistere. La scomposizione della quota deve conservare l'identità del conduttore sulla riga **come dato non esigibile**, non cancellarla.

Cosa fa oggi il programma, e cosa va detto senza abbellirlo: l'inquilino entra nel riparto quando un coefficiente lo nomina (`conto_tabella_ripartizioni.soggetto = 'inquilino'`, alimentato dal campo «Inquilino %» sui conti), e in quel caso `GenerateRateQuotesAction` scrive `rate_quote.anagrafica_id` con la sua anagrafica, `SyncScadenziarioWithPianoRate` gli crea l'avviso di pagamento e la notifica, ed egli compare in situazione debitoria come qualunque proprietario. È una scelta dell'amministratore espressa nel coefficiente, non un automatismo del programma; ma il documento che ne esce non dice mai che verso il condominio l'obbligato è un altro, ed è quello il difetto.

### 3.2 Il perimetro normativo: tre regimi, non uno

È l'errore di fondo che ha generato quasi tutti gli altri, e va scritto una volta per tutte. Esistono tre perimetri:

1. **locazione abitativa** (L. 9 dicembre 1998 n. 431, e per quanto non abrogato il Titolo I Capo I della L. 27 luglio 1978 n. 392): qui vivono l'art. 9 sugli oneri accessori e l'art. 10 sul voto del conduttore;
2. **locazione ad uso diverso ex art. 27 L. 392/1978**: durata 6+6, e per il rinvio dell'art. 41 si applicano anche gli artt. da 7 a 11, quindi l'art. 9 e l'art. 10;
3. **locazione atipica del box a un privato**: artt. 1571 ss. c.c., durata e canone liberi, nessun onere accessorio legale, nessun termine di due mesi, nessuna tabella dell'Allegato D, nessun diritto di voto.

Il confine fra il secondo e il terzo non è la natura del bene ma l'uso: se il box è locato a un artigiano, a un commerciante o a un professionista ed è funzionalmente collegato alla sua attività, anche come semplice deposito, si applica l'art. 27, e il collegamento funzionale può nascere anche da un'iniziativa unilaterale del conduttore accompagnata da protratta tolleranza del locatore. In quel caso il conduttore del box **ha** il diritto di voto dell'art. 10.

Ne discende la correzione più concreta di questa sezione: **non si emette un prospetto intestato «ex art. 9 L. 392/1978» su un box locato a un privato.** Quella norma sta nel Capo I del Titolo I e si estende all'uso diverso solo per il rinvio dell'art. 41. Fuori dai due perimetri c'è solo ciò che le parti hanno scritto nel contratto. Un prospetto che citi l'art. 9 su un contratto atipico fa dire al programma che una norma si applica quando non si applica, e fa partire un termine che non esiste.

Il perimetro va quindi portato come **campo esplicito sulla riga di titolarità**: è la variabile da cui dipendono la tabella applicabile, la derogabilità dei default e l'esistenza stessa del prospetto.

### 3.3 Il prospetto degli oneri accessori, dove è dovuto

L'art. 9 co. 1 L. 392/1978 pone interamente a carico del conduttore, salvo patto contrario, le spese di pulizia, funzionamento e ordinaria manutenzione dell'ascensore, fornitura di acqua, energia elettrica, riscaldamento e condizionamento, spurgo di pozzi neri e latrine, fornitura di altri servizi comuni; il co. 2 pone le spese di portineria al 90 per cento, salvo misura inferiore convenuta. Il co. 3 impone il pagamento entro due mesi dalla richiesta e riconosce al conduttore, prima di pagare, il diritto all'indicazione specifica delle spese con la menzione dei criteri di ripartizione, e inoltre a prendere visione dei documenti giustificativi. Una distinta non analitica giustifica l'omesso pagamento (Cass. civ. 8 aprile 1981 n. 1989); il locatore non deve trasmettere le pezze giustificative ma tenerle a disposizione (Cass. civ. 4 giugno 1998 n. 5485), e dal 2013 il conduttore ha comunque accesso diretto ai documenti presso l'amministratore in ogni tempo (art. 1130-bis co. 1 c.c.) e al sito condominiale come «avente diritto» (art. 71-ter disp. att. c.c.).

Sulla decorrenza dei due mesi vanno corrette tre cose. I due mesi decorrono dalla **ricezione** della richiesta, che è dichiarazione recettizia e soggiace alla presunzione di conoscenza dell'art. 1335 c.c., non dalla sua emissione. Legittimato a formularla è il **locatore**: la richiesta dell'amministratore mette in mora il conduttore solo se il locatore gli ha conferito mandato in tal senso. E il periodo fra la richiesta della distinta da parte del conduttore e il suo invio è di **sospensione** dello spatium solvendi, che riprende sommandosi al tempo già trascorso, non di azzeramento né di blocco indefinito; scaduti inutilmente i due mesi senza che il conduttore si attivi, la mora è automatica (Cass. civ. 3 ottobre 1997 n. 9669).

Un contatore che facesse partire i due mesi dall'emissione del prospetto da parte dell'amministratore attribuirebbe a quest'ultimo un potere che non ha e produrrebbe una data di mora falsa — cioè la soglia oltre la quale scatta la risoluzione.

Sulla forfettizzazione va rovesciata la conclusione precedente. Cass. civ. n. 12718/1997 fondava la nullità della clausola forfettaria su due pilastri, la specificità dell'art. 9 e il divieto di vantaggi al locatore dell'art. 79 L. 392/1978; ma l'art. 14 co. 4 L. 431/1998 ha abrogato l'art. 79 «limitatamente alle locazioni abitative». Nel contratto abitativo a canone libero la clausola forfettaria è oggi **lecita**. La nullità sopravvive dove l'art. 79 è ancora vivo, cioè nelle locazioni ad uso diverso ex art. 27, e di fatto nei contratti concordati, dove l'art. 4 del D.M. Infrastrutture-Economia 16 gennaio 2017 impone l'Allegato D. La regola software è quindi invertita: il campo forfettario serve, vincolato al regime del contratto, e negarlo impedisce di rappresentare un contratto valido.

Per la stessa ragione vanno declassati da divieti a **default derogabili** due punti: il compenso dell'amministratore non rientra fra gli oneri che *l'art. 9* pone a carico del conduttore (Cass. civ. 3 giugno 1991 n. 6216), quindi non è dovuto in mancanza di patto, ma il patto contrario è oggi valido nell'abitativo; e Cass. civ. 14 gennaio 2005 n. 680 riguarda il servizio inesistente — contributo per il riscaldamento chiesto a unità prive di radiatori, dove manca il sinallagma — non un generico «servizio non prestato».

Resta invece contrastato l'onere probatorio in giudizio: Cass. civ. 29 marzo 2004 n. 6202 alleggerisce il locatore (bastano i rendiconti approvati, spetta al conduttore specificare le contestazioni), mentre Cass. civ. 1 aprile 2004 n. 6403 e Cass. civ. 28 settembre 2010 n. 20348 pongono su di lui l'onere di provare esistenza, ammontare e criteri di ripartizione. La conseguenza software — conservare il prospetto con lo snapshot dei coefficienti e dei millesimi usati, senza ricalcolarlo al volo su dati nel frattempo cambiati — è **rafforzata** dal contrasto, non giustificata da un principio pacifico.

Sull'Allegato D al D.M. 16 gennaio 2017, letto nel testo pubblicato in G.U. n. 62 del 15 marzo 2017: le dodici sezioni non comprendono un'autorimessa, ma nella sezione «Parti comuni» esiste una voce specificamente da autorimessa e assegnata al conduttore, «tassa occupazione suolo pubblico per passo carrabile». La sezione «Impianto antincendio» ha quattro righe — installazione e sostituzione dell'impianto e acquisto estintori al locatore, manutenzione ordinaria e ricarica, ispezioni e collaudi al conduttore — e **non** ha la riga «adeguamento a leggi e regolamenti», che invece esiste in «Ascensore» e in «Impianti di riscaldamento». Classificare l'adeguamento antincendio dell'autorimessa come installazione o sostituzione è un'interpretazione ragionevole, non una lettura della tabella: va nella stessa categoria della porta basculante comune e della ventilazione, cioè «non previsto in tabella», con rinvio dell'art. 4 del decreto alle leggi vigenti e agli usi locali.

Il modello dati ha quindi bisogno di **tre** stati per la mappatura conto → soggetto: locatore, conduttore, non previsto in tabella. Il terzo obbliga l'amministratore a scegliere e registra che la scelta è sua.

### 3.4 Locazione separata del box e sorte del vincolo

La locazione separata è espressamente ammessa dall'art. 818 co. 2 c.c. e **non** scioglie il vincolo, perché l'art. 817 co. 2 c.c. guarda alla proprietà: «la destinazione può essere effettuata dal proprietario della cosa principale o da chi ha un diritto reale sulla medesima». Il conduttore non può né costituire né sciogliere una pertinenza. Il legame va quindi agganciato all'unità immobiliare e non alla riga di titolarità: sopravvive a ogni cambio di inquilino.

Quando appartamento e box, dello stesso proprietario e nello stesso edificio, sono locati allo stesso conduttore per le esigenze abitative, si discute se operi una presunzione di pertinenzialità che estenda al box la disciplina abitativa. La materia non è divisa in due orientamenti ma in quattro, e la pronuncia che oggi li riordina è Cass. civ. sez. III, 7 novembre 2019 n. 28615, che censisce: presunzione senza elemento soggettivo (Cass. 10080/1998, 370/1997, 1931/1994); necessità dell'elemento soggettivo (Cass. 869/2015, 12254/1998); presunzione iuris tantum bilanciata (Cass. 638/2007, 2026/1985, 1528/1984); necessità di una novazione contrattuale espressa (Cass. 6412/1985). L'etichetta «iuris tantum» appartiene propriamente solo al terzo gruppo. La stessa pronuncia introduce una fonte che non va persa: l'art. 26 co. 5 L. 28 febbraio 1985 n. 47 qualifica *ex lege* pertinenze delle costruzioni gli spazi di parcheggio dell'art. 18 L. 6 agosto 1967 n. 765, ai sensi e per gli effetti degli artt. 817-819 c.c. Dove ricorre quel vincolo, l'estensione al box del regime locativo dell'appartamento è automatica e inderogabile, non presuntiva.

Il programma può proporre il consolidamento dei prospetti quando il conduttore è lo stesso, ma non deve mai dichiarare che la pertinenzialità sussiste, né dedurla da indirizzo, proprietario o conduttore comune.

Che la locazione separata di un box vincolato ex L. 122/1989 possa configurare violazione del vincolo pubblicistico di destinazione è affermato in fonti divulgative ma **non verificato**: non risultano pronunce, e la giurisprudenza penale ribadisce che la norma è speciale e derogatoria, non estensibile per analogia (Cass. pen. sez. III n. 6738/2018; Cass. pen. n. 1488/2018). Il programma non prende posizione.

### 3.5 Anagrafe, comunicazioni e locazioni brevi

Il registro di anagrafe dell'art. 1130 n. 6 c.c. contiene generalità, codice fiscale e residenza o domicilio dei titolari di diritti reali e di **diritti personali di godimento**, i dati catastali di ciascuna unità e i dati sulle condizioni di sicurezza delle parti comuni. Tre campi per il conduttore: non il canone, non il contratto, non la composizione del nucleo familiare. Il soggetto obbligato a comunicare è il condòmino, non il conduttore, e il sollecito va sempre indirizzato al proprietario. Il procedimento dell'art. 1130 n. 6 — sessanta giorni, raccomandata, ulteriori trenta giorni, acquisizione d'ufficio con addebito ai responsabili — è scritto nella norma con due termini e un addebito, ed è automatizzabile riga per riga.

Va corretto l'aggancio all'art. 13 L. 431/1998. Quell'obbligo di documentata comunicazione al conduttore e all'amministratore, entro sessanta giorni dalla registrazione, riguarda i contratti di locazione di immobili adibiti **ad uso abitativo**: per un box locato separatamente non si applica mai, e non perché manchi la registrazione. L'unico canale per il box è l'art. 1130 n. 6 c.c., ed è a quello che va agganciato il contatore.

Va corretta anche la regola sulle locazioni brevi. L'esonero da registrazione non viene dall'art. 4 D.L. 24 aprile 2017 n. 50 — che definisce le locazioni brevi ai fini della cedolare secca e riguarda i soli immobili abitativi — ma dall'art. 2-bis della Tariffa Parte Seconda allegata al D.P.R. 26 aprile 1986 n. 131, che esonera i contratti non formati per atto pubblico o scrittura autenticata «di durata non superiore a trenta giorni complessivi **nell'anno**», salva la registrazione in caso d'uso. Il limite si computa cumulando tutti i rapporti dell'anno con lo stesso conduttore (Circolare Agenzia delle Entrate n. 12 del 16 gennaio 1998), non contratto per contratto. Uno stato «locata a rotazione» che spegnesse il censimento produrrebbe un dato falso non appena lo stesso conduttore superasse i trenta giorni cumulati: la soglia va sull'accumulo per conduttore.

### 3.6 Successione di conduttori a metà esercizio

Fra conduttori non esiste alcuna solidarietà: l'art. 63 co. 4 e 5 disp. att. c.c. lega chi subentra nel diritto reale, non il detentore. Ciascuno risponde verso il locatore per il proprio periodo. Va però tolto l'appoggio testuale che era stato usato: l'art. 55 L. 392/1978 disciplina la sanatoria giudiziale della morosità e parla di oneri accessori «maturati fino a quella data» come perimetro processuale, non come criterio di imputazione temporale fra conduttori; e nella dottrina che lo cita quell'aggettivo serve semmai a sostenere il pagamento anticipato sul preventivo. La conclusione operativa — filtrare su `data_inizio` e `data_fine` e ripartire pro rata fra conduttori successivi — regge da sé sul sinallagma della locazione.

Il pro-rata sul conduttore è il caso **più semplice** del pro-rata sul proprietario, perché non ha né solidarietà né biennio né gradino della delibera. Ed è il più frequente, perché gli inquilini cambiano più spesso dei proprietari.

### 3.7 Assemblea e verbale

L'art. 10 L. 392/1978 dà al conduttore il diritto di voto in luogo del proprietario nelle delibere su spese e modalità di gestione dei servizi di riscaldamento e condizionamento, e il diritto di intervenire senza voto sulle delibere di modificazione degli altri servizi comuni. La norma è eccezionale e non suscettibile di interpretazione estensiva (Cass. civ. 27 agosto 1986 n. 5238), e la legittimazione a impugnare è limitata alle stesse materie (Cass. civ. 18 agosto 1993 n. 8755; Cass. civ. 23 gennaio 2012 n. 869). Restano fuori disattivazione e soppressione del servizio.

Un chiarimento di fonte, perché l'errore è circolato: il voto del conduttore sul riscaldamento sta nell'art. 10 L. 392/1978; l'art. 67 co. 6 disp. att. c.c. riguarda l'usufruttuario. Le due norme non vanno confuse, ma la conseguenza pratica è la stessa e va detta: una regola «il conduttore non compare mai nei quorum» è sbagliata in diritto, e un'autorimessa con impianto di ventilazione o riscaldamento centralizzato ricade esattamente nella materia dell'art. 10 quando il contratto è abitativo o rientra nell'art. 27.

Se l'amministratore debba convocare direttamente il conduttore è **questione aperta**: la tesi tradizionale (Cass. civ. 3 settembre 1982 n. 4802; Cass. civ. 3 ottobre 2005 n. 19308) vuole l'avviso al proprietario, con l'onere di informare il conduttore e, in difetto, il rifiuto di rimborsare i maggiori oneri; la tesi opposta valorizza la sostituzione di «condomini» con «aventi diritto» negli artt. 1136 co. 7 c.c. e 66 co. 3 disp. att. c.c. operata dalla L. 220/2012, letti con l'anagrafe dell'art. 1130 n. 6. Non risulta una pronuncia di legittimità successiva alla riforma. Il programma rende facile la strada prudente e registra la scelta a verbale; non sostituisce d'ufficio il conduttore al proprietario nei quorum.

Sul verbale va corretta l'istruzione precedente. L'amministratore **non** è tenuto a comunicare il verbale al conduttore assente, mentre **deve** comunicarlo al condòmino assente anche se all'assemblea ha partecipato soltanto il conduttore: è al condòmino che la comunicazione fa decorrere il termine di trenta giorni per l'impugnazione ex art. 1137 co. 2 c.c. Un modulo che segmentasse il verbale per destinatario esporrebbe l'amministratore proprio sul punto che si voleva presidiare.

### 3.8 Comodato

Il comodatario è titolare di un diritto personale di godimento e va nel registro di anagrafe come il conduttore. Nei rapporti interni l'art. 1808 co. 1 c.c. esclude il rimborso delle spese sostenute per servirsi della cosa e il co. 2 riconosce il rimborso delle sole spese straordinarie necessarie e urgenti; non si applica il reticolo dell'art. 9 L. 392/1978, che parla di conduttore. L'estensione al comodatario del voto dell'art. 10 è sostenuta in prassi ma **non verificata**: la norma nomina il conduttore ed è dichiarata eccezionale.

Serve un campo «titolo del godimento» (locazione / comodato) accanto al ruolo, e il comodatario non va incluso per default negli elenchi di convocazione.

### 3.9 Morosità del proprietario su unità locata

L'art. 63 co. 3 disp. att. c.c. consente all'amministratore, in caso di mora protratta per un semestre, di sospendere il condòmino moroso dalla fruizione dei servizi comuni suscettibili di godimento separato. È una **facoltà**, e su un'unità locata colpisce di fatto un conduttore incolpevole, che resta creditore del locatore per l'idoneità del bene all'uso convenuto (art. 1575 c.c.). Il programma segnala nella scheda del moroso che sull'unità risulta un inquilino attivo, e nient'altro: non calcola il semestre, non propone la sospensione come passo successivo del flusso di sollecito, non comunica nulla al conduttore.

## 4. Il destino del legame di pertinenza

### 4.1 Quando si rompe, quando sopravvive

Sopravvive: alla locazione del box, alla locazione dell'appartamento, a conduttori diversi sui due beni, al cambio di conduttore, al comodato, alla successione che porta entrambi i beni agli stessi eredi nelle stesse quote, e — con la riserva che segue — alla costituzione di usufrutto sul solo box.

Si rompe: con la vendita, la donazione, il decreto di trasferimento all'asta, il trasferimento contenuto in un accordo di separazione consensuale o di divorzio congiunto (valido senza successivo atto notarile: il verbale d'udienza ha forma di atto pubblico ex art. 2699 c.c. ed è titolo per la trascrizione ex art. 2657 c.c., Cass. civ. Sez. Un. 29 luglio 2021 n. 21761), e con la divisione ereditaria che assegna i due beni a soggetti diversi.

Sull'usufrutto costituito sul solo box la conclusione va lasciata dichiaratamente **non verificata**, e va tolto l'appoggio all'art. 819 c.c., che era citato al contrario: quella norma dispone che la destinazione «non pregiudica i diritti *preesistenti*» dei terzi sulla cosa accessoria, e riguarda quindi i diritti già esistenti quando la destinazione viene impressa, non un usufrutto costituito dopo. La conclusione — la proprietà non si separa, quindi il requisito soggettivo regge — si regge sull'art. 817 co. 2 c.c. e su Cass. civ. 9 maggio 2005 n. 9563 e Cass. civ. sez. VI, 17 ottobre 2017 n. 24432, che richiedono la proprietà dei due beni in capo al medesimo soggetto.

Sulla comunione ereditaria in attesa di divisione va tenuto lo stato di attesa: la divisione ha effetto dichiarativo e retroattivo al momento dell'apertura (art. 757 c.c.), quindi un legame rotto dal programma in quella finestra potrebbe risultare rotto in un periodo in cui non lo è mai stato. Va invece corretta la ricostruzione delle obbligazioni: per i contributi maturati **prima** dell'apertura, gli eredi rispondono verso i creditori personalmente in proporzione della propria quota (artt. 752 e 754 c.c.); per quelli maturati **dopo** sono comproprietari della stessa unità e verso il condominio la solidarietà si presume (art. 1294 c.c.). Il numero della pronuncia che di solito si cita su quest'ultimo punto resta **non verificato** — Cass. 9 gennaio 2017 n. 199 riguarda in realtà la parziarietà delle obbligazioni assunte dall'amministratore verso i terzi — ma il principio si regge sull'art. 1294 c.c.

### 4.2 La regola che il programma deve seguire

Non rompere mai da solo. Non per prudenza generica, ma perché il vincolo nasce da due fatti che il programma non vede: la destinazione materiale durevole di un bene al servizio di un altro e la volontà, espressa o tacita, di chi ha la disponibilità giuridica di entrambi (art. 817 c.c.; Cass. civ. sez. VI, 17 ottobre 2017 n. 24432; Cass. civ. 9563/2005). Quello che il programma vede — due titolari diversi — è un indizio sul requisito soggettivo, non un fatto sul vincolo.

La rileva, la segnala, mostra i fatti su cui la segnalazione si basa, e offre tre uscite: conferma, dichiarazione di cessazione con data, rinvio. È lo stesso comportamento di `VerificaTitolaritaCommand`, uscito con la beta.50: diagnosticare e riferire, non correggere. Un secondo meccanismo con una filosofia diversa insegnerebbe all'utente che il programma a volte corregge da solo.

Due correzioni sulla forma delle uscite. La prima: la conferma va scritta simmetrica alla cessazione. Se «dichiarato cessato il …» è preferibile a «vincolo estinto» perché l'amministratore non pronuncia qualificazioni giuridiche, allora anche «confermo il legame» va sostituito da «risulta ancora pertinenza — dichiarazione dell'amministratore del …»: l'art. 817 co. 2 c.c. riserva la destinazione al proprietario o al titolare di un diritto reale, e l'amministratore non ha alcun potere di costituire o accertare un vincolo. La seconda: la segnalazione «da verificare» **non** va stampata dentro il registro di anagrafe. Il contenuto del registro è tipizzato dall'art. 1130 n. 6 c.c. e non comprende il legame di pertinenza; stamparci una contestazione implicita alla titolarità di una persona, fondata su un indizio non concludente, è un atto della gestione che circola. Va su un allegato separato dell'amministratore, che può accompagnare il fascicolo di consegna.

Va inoltre tolto dall'elenco dei falsi positivi il caso della comunione legale fra coniugi con una sola unità intestata a uno solo. Se l'acquisto è avvenuto in comunione legale entrambi i coniugi sono contitolari ex art. 177 lett. a) c.c. a prescindere dall'intestazione nel registro: la divergenza è un **vero positivo su un fatto diverso**, e dice che l'anagrafe è incompleta. Classificarla come rumore insegna a ignorare proprio la segnalazione che indica un errore — e in KondoManager un contitolare mancante è un numero sbagliato, perché il motore somma `pivot.quota` su tutte le righe attive e la pivot non ha alcun indice unique.

### 4.3 Storicizzare: la conclusione precedente va ribaltata

La ricerca precedente concludeva che non serve storicizzare la validità del legame e che basta un diario delle modifiche. Messa alla prova, la conclusione non regge. Tre ragioni.

**Prima.** L'art. 818 co. 3 c.c. rende la cessazione un fatto con un prima e un dopo opponibile: non è opponibile ai terzi che abbiano *anteriormente* acquistato diritti sulla cosa principale. La data che conta è quella in cui la cessazione è avvenuta, non quella in cui l'amministratore l'ha dichiarata. Un diario di dichiarazioni non risponde alla domanda «era pertinenza al 31/12/2025?».

**Seconda.** La conclusione «il legame non muove alcun numero nemmeno in campo fiscale» era fondata sulla circolare AdE n. 30/E del 22 dicembre 2020, quesito 4.4.4, che per il massimale sulle **parti comuni** conta le pertinenze come unità (4 unità abitative + 4 pertinenze = ×8): lì il legame è davvero irrilevante, basta contare le unità censite. Ma la risposta a interpello n. 765 del 9 novembre 2021 — che rettifica la risposta n. 568 del 30 agosto 2021 — stabilisce che sull'unità **privata** il massimale si riferisce al singolo immobile e alle sue pertinenze unitariamente considerate, anche se accatastate separatamente: quel numero dipende esattamente dal sapere quale unità è pertinenza di quale. La formulazione corretta non è «il legame non muove alcun numero», ma **«KondoManager oggi non calcola quel numero»**. E il progetto ha già una superficie fiscale operativa (`App\Enums\TipoDetrazione`, bonifico parlante su `pagamenti_fornitori`, `MotivoEsclusioneRitenuta`): la distanza fra «non lo calcoliamo» e «lo calcoliamo» è una release, non un'era. A questo si aggiunge il campo con il numero delle pertinenze autonomamente accatastate nella comunicazione per la precompilata, che vuole lo stato del legame all'anno di riferimento.

**Terza.** Il motore è oggi atemporale sulla pivot `anagrafica_immobile`, ma `docs/subentro_e_competenza_temporale.md` progetta il filtro per data. Un campo pertinenza privo di date di validità sarebbe l'unico oggetto atemporale in un modello che sta diventando temporale — la stessa asimmetria che quel documento denuncia.

**Decisione proposta:** il legame porta due date, `dal` e `cessato_il`, entrambe nullable, anche se nessun calcolo le legge il giorno in cui vengono aggiunte. Più il diario delle modifiche, che serve a un'altra domanda (chi ha dichiarato cosa e quando) e non la sostituisce. La distinzione fra la data in cui la cessazione è avvenuta secondo il titolo e la data in cui l'amministratore l'ha dichiarata va mantenuta: la prima sta sul legame, la seconda nel diario.

Va infine registrato un difetto strutturale che nessuna delle ricerche aveva rilevato e che ho verificato nel DDL: in `immobile_pertinenza` **entrambe** le foreign key sono in cascade verso `immobili`, quindi cancellare il box cancella il legame senza lasciare traccia. Qualunque modellazione datata deve sopravvivere alla chiusura dell'unità, non seguirne la sorte.

## 5. Cosa fa oggi KondoManager

### 5.1 Cosa è assente

Il campo «Pertinenza di» non esiste in nessuna interfaccia. Esistono, dal 07/08/2025, la tabella `immobile_pertinenza` (`database/migrations/2025_08_07_133836_create_immobile_pertinenza_table.php`), il model `app/Models/Pertinenza.php` e le relazioni `Immobile::pertinenze()` e `Immobile::immobiliPrincipali()` in `app/Models/Immobile.php:97-118`. Nessun controller, nessuna rotta, nessuna vista, nessun test, nessun seeder vi fa riferimento: è codice morto. La forma è anche diversa da quella decisa in ricerca — molti-a-molti con `quota_possesso decimal(5,2) default 100.00` e `unique(immobile_id, pertinenza_id)` — quindi consente a un box di essere pertinenza di due unità e non impedisce catene di profondità maggiore di uno.

Non esiste alcun prospetto oneri accessori: `grep -rni "conduttore|oneri accessori|392/1978"` su `app/` restituisce solo commenti e il parser dell'import. Non esiste alcuna operazione di passaggio di titolarità: il solo percorso è `ImmobileAnagraficaController` con `create/store/edit/update/destroy`, e `destroy()` fa `detach()`, cioè cancella la riga.

Non esistono `RisolutoreTitolari`, `PeriodoCompetenza`, `ProRataTemporis`, una tabella `subentri` o una chiave `titolarita_alla`: i blocchi B1-B4 di `docs/subentro_e_competenza_temporale.md` non sono scritti.

### 5.2 Cosa è rotto

**Il motore non legge le date della pivot.** Verificato riga per riga: `CalcoloQuoteService.php:820-822` e `838-840`, `RipartoTabelleService.php:538-540` e `565-567`, `RipartoCapitoliService.php:476-477` e `502-503` filtrano tutti e soli su `pivot.attivo` e `pivot.tipologia`. Una ricerca di `data_riferimento|allaData|asOf|proRata` su `app/` dà zero risultati. Non esiste pro-rata per giorni in nessun punto del progetto.

**La conseguenza numerica di una vendita a metà esercizio, misurata.** Unità con `data_fine = 2026-03-15` registrata sul venditore, spesa di € 1.000,00 su millesimi 600/400: il venditore riceve € 600,00, cioè il 100 per cento della quota dell'unità. La data di fine è puramente documentale, e non produce né avviso né voce di log. Se l'amministratore censisce anche l'acquirente per «tenere la storia», il risultato è **50/50 esatto — € 300,00 e € 300,00 — identico per un rogito di gennaio e per uno di dicembre**. Registrare due periodi è d'altronde impossibile dall'interfaccia, perché la guardia 2 di `app/Traits/ValidatesImmobileAnagraficaPivot.php:29-45` somma le quote per tipologia senza filtrare né su `attivo` né su `data_fine` e rifiuta a 200; ma l'importatore scrive con `DB::table()->insertGetId()` in `app/Services/Import/Livelli/LivelloTitolarita.php:150-176` senza passare dal trait, e sul database non esiste alcun indice unique (`SHOW INDEX` conferma: solo PRIMARY e i due indici di foreign key). Sul database reale ci sono già **due unità, id 227 e 228, con tre righe `proprietario` ciascuna e somma quote 200,00**: il subentro rappresentato come comproprietà esiste in produzione.

Da ricordare anche che `pivot.quota` è un peso relativo fra soggetti dello stesso ruolo, non una riduzione della quota dell'unità: una sola riga con quota 50 riceve comunque il 100 per cento. Un amministratore che scrive 50 pensando «metà» ottiene l'addebito intero. E una riga con quota 0 viene saltata con `continue` senza essere tracciata come peso scoperto (`CalcoloQuoteService.php:872-874` contro il tracciamento a `:848-863`): esiste già in produzione sull'immobile 202, ruolo inquilino.

**Le due stampe divergono dopo una dissociazione.** È il difetto più rilevante emerso e non è scritto in alcun documento. `RipartoTabelleService` costruisce le righe da `rate_quote` (`:198-207`) e ha il riallineamento aggiunto nella beta.50 (`:282-300`); `RipartoCapitoliService` costruisce le righe dai pesi ricalcolati dal vivo sulla pivot (`:133-171`, ciclo `:198`) e usa `rate_quote` solo per riallineare righe già esistenti (`:217`), quindi un soggetto dissociato non produce riga. Misurato sullo stesso piano: gran totale per tabelle 100000 centesimi, per capitoli 40000. **Sei euro su dieci spariscono dal documento per capitoli**, e nessun test lo copre. È raggiungibile proprio dal rimedio che gli amministratori usano oggi per il subentro — genero, dissocio il venditore, ristampo. L'azione «Dissocia» in `DataTableRowActions.vue:41-64` è una conferma generica, senza alcun avviso su rate emesse, saldi o documenti già stampati.

**Coerenza dei documenti.** L'estratto conto resta intestato alla persona dopo la dissociazione, ma il pannello «Unità immobiliari» legge la pivot e mostra «Nessuna unità associata» mentre il ledger continua a elencare le rate di quell'unità (`EstrattoContoAnagraficaController.php:30-33` e `:91-115`; `EstrattoContoAnagrafica.vue:311-327`). Il documento si contraddice.

**Validazione.** `UpdateImmobileAnagraficaRequest.php:30-37` non impone `after_or_equal:data_inizio` su `data_fine`, mentre `CreateImmobileAnagraficaRequest.php:36-48` lo fa: in modifica si salva una data di fine anteriore alla data di inizio. `quota` è validata `required|numeric` senza `min` né `max`.

**Enum disallineati.** La migrazione originale della pivot (`2025_08_06_124129`) dichiara `enum('tipologia', ['proprietario','usufruttuario','inquilino'])`, e `conto_tabella_ripartizioni.soggetto` è un ENUM a tre valori che non contiene `nuda_proprietario`, contro i quattro dell'enum PHP. Diventa rilevante appena un coefficiente deve nominare il soggetto giusto in un periodo.

**Sigle di ruolo.** `RipartoCapitoliService.php:124-128` non contiene `nuda_proprietario` nella mappa delle sigle, che diventa «N», mentre `RipartoTabelleService.php:218-223` ha la mappa corretta. E `RipartoCapitoliService` non ha alcuna nozione di peso scoperto: quando la cascata si esaurisce l'importo viene scartato con `continue` (`:508`). L'unificazione della cascata su `catenaRipiego()` è entrata nel working tree della beta.51 in tutti e tre i servizi, ma senza il tracciamento dello scoperto in `RipartoCapitoliService`.

### 5.3 Cosa funziona

Il netting del già versato è l'unico punto del progetto in cui il subentro è modellato esplicitamente ed è modellato bene: la migrazione `2026_07_24_120000_create_contributi_versati_table.php` dichiara alle righe 17-21 che la chiave di netting è `immobile_id`, obbligatorio, e non l'anagrafica, «perché il contributo è della unità: se l'unità viene venduta, il nuovo proprietario eredita la copertura». `anagrafica_id` resta come traccia di chi ha versato, nullable, e non entra nel calcolo.

Le rate emesse non sono più ricalcolabili: `PianoRateGenerationController.php:45-63` blocca il ricalcolo se esiste un incasso o una scrittura contabile di emissione. È il comportamento voluto.

La cascata dei ruoli vive in un solo posto, `RuoloAnagraficaImmobile`, e la motivazione dell'esclusione dell'inquilino è scritta nel codice con la fonte. `catenaSaldoSolidale()` distingue l'asse ordinario (usufruttuario, art. 1004 c.c.) da quello straordinario (nudo proprietario, art. 1005 c.c.) e dichiara nel commento che verso il condominio i due rispondono in solido ex art. 67 ultimo comma disp. att. c.c.: è già l'impostazione giusta, e va estesa alla colonna «a chi posso chiedere».

I testi dell'interfaccia sulle date di competenza sono stati corretti nella beta.50 (`AnagraficheNew.vue:282`, `AnagraficheEdit.vue:223`): oggi dichiarano che il riparto non legge le date. Fino alla beta.49 sette testi promettevano il contrario, e `kondomanager:verifica-titolarita` elenca chi si è fidato di quelle promesse.

## 6. L'interfaccia

**Il principio guida, in una frase: il programma non deduce nulla che l'amministratore non abbia dichiarato, e prima di scrivere mostra che cosa cambierà e a chi resterà l'obbligo.**

Tutti i testi che seguono sono definitivi: maiuscole solo a inizio frase, € prima dell'importo.

### 6.1 Il campo «Pertinenza di»

Etichetta identica a quella del leader di mercato, perché un amministratore che arriva da Danea la riconosce senza leggere.

**In scheda** (`resources/js/pages/gestionale/immobili/ImmobiliView.vue`, card «Informazioni generali», riga a tutta larghezza sotto «Superficie», stesse classi delle altre etichette). Se collegato, valore come link Inertia con icona `Link2`: «Interno 3 — Scala A (Bianchi Anna)», e sotto, in grigio piccolo: «Il collegamento è descrittivo: millesimi, riparto e rate del box restano suoi.» Se non collegato, valore «—» e accanto un pulsante piatto in maiuscoletto spaziato «Collega a un'unità». Nessun colore d'allarme, nessuna icona di avviso.

Sull'unità principale, riga «Pertinenze» con i box e le cantine come chip cliccabili. Se non ce ne sono, la riga non compare.

**In form** (`ImmobiliEdit.vue` e `ImmobiliNew.vue`, card «Ubicazione e Tipologia»): un select «Pertinenza di» accanto a «Tipologia», con segnaposto «Nessuna — è un'unità principale». Profondità uno: le opzioni escludono l'unità stessa e le unità che sono già pertinenze di altro. Sotto, una seconda opzione: «Unità fuori da questo condominio», che apre un campo di testo libero — via, comune, dati catastali — perché il caso Tognoli esiste e altrimenti l'amministratore lascia il campo vuoto, che è l'informazione opposta.

**In elenco** (`resources/js/components/gestionale/immobili/columns.ts`, cella «Unità Immobiliare»): non una colonna nuova, un secondo rigo sotto il nome, «↳ pertinenza di Int. 3» in grigio chiaro, oppure «↳ pertinenza non collegata» in grigio chiarissimo corsivo. Non il «?» che usa Domustudio, che è ambiguo: non si capisce se «non collegata» o «non applicabile». Sull'unità principale, un chip «+2 pertinenze».

**Filtro** (`DataTableToolbar.vue` con `DataTableFacetedFilter.vue`, già presenti): voce «Pertinenze» con «Tutte» · «Solo unità principali» · «Da collegare». È il modo di fare la bonifica quando si ha voglia, senza che il programma la chieda.

Il legame non collegato non entra in `kondomanager:verifica-titolarita`, non entra nella dashboard dei controlli, non blocca alcun salvataggio, e non è mai rosso o ambra.

File nuovo: `resources/js/components/gestionale/immobili/PertinenzaField.vue`.

### 6.2 L'avviso di titolari divergenti

Riquadro ambra chiaro con icona `Info`, mai `AlertTriangle`, mai toast, mai `InputError`, mai bloccante. Tre punti di comparsa.

**Nel dialogo di collegamento**, appena scelta l'unità e non al salvataggio:

> «L'interno 3 è di Bianchi Anna, il box 12 di Rossi Mario. Puoi collegarli lo stesso — il programma non lo impedisce — ma di solito una pertinenza appartiene allo stesso proprietario dell'unità principale (art. 817 c.c.).»

Il pulsante resta abilitato: «puoi collegarli lo stesso» è la parte che conta, perché dice all'amministratore che non sta per essere fermato.

**In scheda**, quando la divergenza è già in banca dati:

> «Questa pertinenza ha un proprietario diverso dall'unità a cui è collegata. Nessun calcolo ne risente: il collegamento è descrittivo.»

con link «Rivedi il collegamento», che apre le tre uscite: «Risulta ancora pertinenza — lo dichiaro oggi» · «Dichiaro cessata la pertinenza il …» · «Decido dopo». Sotto, i fatti che hanno prodotto la segnalazione: titolari delle due unità con ruolo e date, e la data a cui il confronto si riferisce. Ogni scelta va nel diario con utente, data e nota libera.

**Nel riepilogo di condominio**, raggiungibile solo dal filtro «Da collegare»:

> «3 pertinenze hanno un proprietario diverso dall'unità principale. Non è un errore: capita quando un box viene venduto da solo. Serve solo a decidere se il collegamento è ancora giusto.»

File nuovo: `resources/js/components/gestionale/immobili/AvvisoTitolariDivergenti.vue`, con una prop `contesto: 'anteprima' | 'scheda' | 'dialogo'`.

### 6.3 L'operazione «Registra passaggio»

Non «Modifica associazione». La lezione più netta viene da fuori settore: in SAP HR il pulsante «Change» sovrascrive la storia e «Copy» la conserva, e gli utenti sbagliano da vent'anni. Qui il verbo che conserva deve essere primario e visivamente dominante; «Modifica associazione» resta nel menu secondario con la descrizione «Correggi un dato sbagliato, senza cambiare il titolare»; «Dissocia» deve dire cosa distrugge.

Voce nel menu azioni dell'unità e pulsante primario in testa a `AnagraficheList.vue`, con sotto-menu di quattro voci, perché la prima domanda decide tutto il resto:

- «Vendita o donazione (cambia il proprietario)»
- «Inizio locazione (entra un inquilino)»
- «Fine locazione (esce l'inquilino)»
- «Usufrutto (costituzione o estinzione)»

Pagina: `resources/js/pages/gestionale/immobili/anagrafiche/PassaggioNew.vue`, dentro `ImmobileLayout`, con `PageHeaderGuide` e tre schede guida: «Chi esce, chi entra» · «Da quando» · «Cosa cambia nei conti». Layout a tre colonne, due di modulo e una sticky di anteprima.

Ordine dei campi:

1. **Chi esce** — non un select vuoto: la lista dei titolari attuali con radio, già selezionato se è uno solo. Ogni riga: pillola del ruolo, nome, codice fiscale, quota, «dal 03/03/2019».
2. **Data dell'atto** — un campo solo. Sotto, calcolata in tempo reale: «Rossi Mario risulterà titolare fino al 30 aprile 2026 compreso. Bianchi Anna dal 1 maggio 2026.» Il programma calcola il giorno prima, non lo chiede: chiedere due date è l'errore documentato nei concorrenti italiani.
3. **Chi entra** — select sulle anagrafiche del condominio, più «Crea nuova anagrafica» in un dialogo inline, senza lasciare la pagina.
4. **Quota** — precompilata con la quota di chi esce, non con 100, in sola lettura finché non si spunta «La quota cambia».
5. **Ruolo** — precompilato con quello di chi esce, modificabile.
6. **Pertinenze** — se l'unità ha pertinenze collegate, una card con le caselle **non spuntate**: «Applica lo stesso passaggio anche a: ☐ Box 12 ☐ Cantina 7», con la frase: «L'art. 818 co. 1 c.c. fa seguire le pertinenze all'unità principale, salvo diversa disposizione dell'atto. Spunta solo ciò che il titolo che hai in mano comprende: verso il condominio conta il titolo, non la presunzione.»
7. **Documento** — «Estremi dell'atto» in testo libero, ed etichetta neutra «titolo di provenienza», non «rogito notarile»: il verbale di separazione omologato è titolo per la trascrizione, e il decreto di trasferimento all'asta pure. Più la casella «Ho ricevuto copia autentica del titolo» con la sua data, che è l'unico campo del modulo con un effetto giuridico diretto (art. 63 co. 5 disp. att. c.c.).
8. **Nota** — libera.

Dopo la conferma, avviso verde: «Passaggio registrato. Bianchi Anna è titolare di Interno 3 dal 1 maggio 2026.» e due azioni al posto del ritorno all'elenco: «Scarica l'attestazione dello stato dei pagamenti» (art. 1130 n. 9 c.c., la chiede sempre il notaio) e «Aggiorna l'anagrafe condominiale» (art. 1130 n. 6 c.c.).

File nuovi: `PassaggioNew.vue`, `components/gestionale/immobili/PassaggioPertinenzeCard.vue`, `components/guides/PassaggioProprietaGuide.vue`.

### 6.4 Il pannello «Cosa cambierà»

Card sticky a destra, bordo tratteggiato, sullo schema visivo che l'amministratore ha già imparato con `ScopertoWarning.vue` e `ImportAnteprima.vue`. Quattro blocchi, tutti al futuro, calcolati dal backend con debounce e mai lato client.

**1. Anagrafica.** «Rossi Mario risulterà titolare fino al 30 aprile 2026. Bianchi Anna dal 1 maggio 2026, come proprietario al 100 %.» Sotto, le pertinenze incluse.

**2. Rate già emesse.** Il blocco che nessun concorrente mostra prima. Tre esiti:

- «Nessuna rata emessa su questa unità. Non c'è niente da conguagliare.»
- Con rate emesse: tabella minima «Rata 3 · scad. 30/06/2026 · € 412,00 · intestata a Rossi Mario», poi il conguaglio, **separato per natura**: «Le rate già emesse non si toccano. Il conguaglio verrà proposto come due righe di saldo che sommano a zero, sulla gestione Ordinaria 2026: credito € 274,67 a Rossi Mario, debito € 274,67 a Bianchi Anna. La quota ordinaria è divisa in proporzione ai giorni. La quota straordinaria non è divisa: € 1.200,00 restano interamente a Rossi Mario, perché l'assemblea ha approvato i lavori e il prezzo il 12 febbraio 2026, quando l'unità era sua (art. 63 disp. att. c.c.; Cass. civ. 30 agosto 2025 n. 24236).»
- Con morosità anteriori: «Rossi Mario ha € 380,00 scaduti e non pagati. Restano suoi: il conguaglio si calcola sulla competenza, non sui pagamenti.»

**3. Chi resta obbligato.** Riquadro ambra:

> «Bianchi Anna risponde in solido con Rossi Mario per i contributi di questa unità relativi all'esercizio 2026 e all'esercizio 2025 (art. 63 co. 4 disp. att. c.c.). Il programma non le intesta nulla: la nota resta qui e nella situazione debitoria dell'unità, e chi paga in forza della solidarietà ha regresso integrale verso il venditore.»

E, se la casella della copia autentica non è spuntata:

> «Finché non ricevi copia autentica del titolo, Rossi Mario resta obbligato verso il condominio (art. 63 co. 5 disp. att. c.c.). Registra pure il passaggio: l'anagrafe si aggiorna sulla comunicazione scritta del condòmino (art. 1130 n. 6 c.c.), il titolo serve a un altro effetto.»

**4. Cosa non cambia.** Sempre presente, quattro righe:

> «Millesimi: invariati. Tabelle millesimali: invariate. Teste in assemblea: si contano per persona, e il numero cambia solo se il venditore non possiede altre unità in questo condominio. Pertinenze: il collegamento è descrittivo, non sposta importi.»

**Il cancello.** Se il passaggio tocca rate già emesse o cambia un destinatario, il pulsante resta disabilitato finché non si spunta «Ho letto cosa cambierà» e non si scrive una nota di almeno dieci caratteri, congelata insieme all'operazione. Se non tocca nulla di emesso, il pulsante è attivo subito: un cancello che scatta sempre è un cancello che nessuno legge. Se il backend non risponde, il pannello dice «Non riesco a calcolare le conseguenze: riprova» e il pulsante resta disabilitato — mai mostrare zero.

File nuovo: `resources/js/components/gestionale/immobili/AnteprimaPassaggio.vue`.

### 6.5 Lo storico della titolarità

Due livelli.

**Livello 1**, sempre visibile in `AnagraficheList.vue`: la tabella mostra **solo** i titolari attuali. Sopra, una riga: «Oggi, 15 agosto 2026 · 1 proprietario · 1 inquilino». È la stessa riga che, quando il riparto avrà una data di riferimento, diventerà il posto dove cambiarla — il pattern di Oracle HRMS, che mostra la data di riferimento nella barra del titolo quando è diversa da oggi, esiste perché guardare dati storici credendoli attuali è l'errore tipico. Se ci sono stati passaggi, accanto compare un pulsante con icona `History`, «Storico (2 passaggi)», lo stesso trigger di `BudgetHistoryPopover.vue`.

**Livello 2**, un pannello laterale (`components/ui/sheet`), titolo «Chi ha avuto questa unità». Righe raggruppate per tipo di diritto e scritte come frasi:

```
Proprietà
●———   Bianchi Anna     dal 1 maggio 2026 · in corso
●——●   Rossi Mario      dal 3 marzo 2019 al 30 aprile 2026 · 7 anni
        atto notaio Verdi, rep. 12345 · copia autentica ricevuta il 6 maggio 2026

Locazione
●——●   Verdi Luca       dal 1 gennaio 2024 al 31 dicembre 2025 · 2 anni
```

Date a parole, mai «01/05/2026 → 31/12/9999». Durata calcolata, mai giorni. Nessuna colonna «attivo», nessun id, nessuna intestazione `data_inizio`. Il dettaglio tecnico dentro un accordion che si apre solo se richiesto. È il tracciato della visura catastale storica, che l'amministratore sa già leggere.

In `ImmobiliView.vue`, una riga sola: «Titolare dal 1 maggio 2026», con il link che apre lo stesso pannello.

Non mettere i periodi chiusi nella stessa tabella degli attivi con un suffisso «(ex)», come fa Domustudio: è il modo più veloce per intestare una rata a chi ha venduto.

File nuovo: `resources/js/components/gestionale/immobili/TitolaritaSheet.vue`.

### 6.6 La locazione

Stessa pagina, tipo «Inizio locazione», con tre differenze.

La riga in cima diventa informativa e non selezionabile: «Il proprietario resta Bianchi Anna. La locazione si aggiunge, non sostituisce.» È l'errore più comune e va tolto prima che si compia.

Nel pannello: «Verso il condominio continua a rispondere il proprietario. All'inquilino verranno addebitate solo le voci di spesa il cui coefficiente indica “inquilino”.» E, calcolata sul condominio reale, la riga che evita la telefonata: «Nessuna voce di spesa è oggi intestata all'inquilino: registrare la locazione non cambierà nessun importo.»

Se l'unità è un box, il testo dipende dal **regime del contratto**, che va chiesto (abitativo · uso diverso ex art. 27 L. 392/1978 · locazione atipica artt. 1571 ss. c.c. · comodato):

- locazione atipica: «Verso il condominio risponde il proprietario. Il rimborso delle spese si regola nel contratto: sul box locato a un privato non si applicano né la ripartizione dell'art. 9 L. 392/1978 né il diritto di voto dell'art. 10.»
- uso diverso ex art. 27: «Il conduttore ha diritto di voto sulle spese e sulle modalità di gestione del riscaldamento e del condizionamento (art. 10 L. 392/1978, richiamato dall'art. 41), e la ripartizione degli oneri accessori segue l'art. 9.»

Il campo data di fine, se compilato: «La data di fine è una scadenza, non un automatismo: il programma non chiude la locazione da solo.»

Nella fine locazione, terza opzione esplicita: «Nessuno — l'unità resta sfitta».

**Distinzione nelle liste.** Ordine fisso in `anagrafiche/columns.ts`: prima i titolari di diritto reale, poi gli occupanti, con un separatore leggero e due etichette che spiegano la differenza meglio di due colori — «Chi risponde verso il condominio» e «Occupanti». In `immobili/columns.ts` manca del tutto il titolare: si vedono palazzina, catasto e metri quadri, e non chi ci abita. Va aggiunta una colonna «Titolare»: riga 1 il proprietario in grassetto, riga 2 in grigio piccolo «locato a Verdi Luca» oppure «sfitto».

**Colore del nudo proprietario.** `anagrafiche/columns.ts` non ha un `case 'nuda_proprietario'` e quel ruolo cade nel grigio di default, cioè nello stesso colore di un ruolo sconosciuto; stessa cosa in `ScopertoWarning.vue`. Va estratto `resources/js/components/gestionale/immobili/BadgeRuolo.vue` con quattro colori — proprietario blu, nudo proprietario ambra, usufruttuario viola, inquilino verde — usato dai tre punti. È una correzione da 1.10 e non richiede il motore.

## 7. Le decisioni proposte

**D1 — Il criterio di competenza è doppio, agganciato alla natura della gestione: delibera attuativa per lo straordinario, periodo di svolgimento dell'attività per l'ordinario.**
Scartata: la regola unica su `data_delibera_assemblea`, perché contraddetta da Cass. 24654/2010, 11199/2021 e 24069/2022 e perché su un ordinario da € 120.000,00 con rogito a metà anno sposta € 60.000,00. Scartata anche l'opzione configurabile «criterio delibera / criterio esecuzione», che trasformerebbe una regola di diritto in una preferenza dell'utente. Resta possibile dichiarare un periodo di competenza diverso sulla singola fattura o copertura, per la diversa convenzione fra le parti.

**D2 — Il conguaglio ha due forme: pro rata temporis per l'ordinario, a gradino sulla data di delibera per lo straordinario. Sempre una coppia di righe in `saldi` a somma zero, sulla stessa gestione e sullo stesso immobile.**
Scartata: la coppia unica pro-rata, che è falsa per la parte straordinaria. Scartata la riscrittura delle rate emesse, che sono crediti già iscritti verso soggetti nominati (decisione D9 del documento sul subentro, confermata).

**D3 — Due date sul trasferimento: `data_atto` e `data_ricezione_titolo`, la seconda nullable.**
Scartata: la data unica, che libererebbe il venditore troppo presto in ogni report. Scartato il blocco della registrazione in mancanza dell'atto, che violerebbe l'art. 1130 n. 6 c.c. e farebbe perdere la solidarietà dell'art. 63 co. 4.

**D4 — Il legame di pertinenza *avrà* due date, `dal` e `cessato_il`, più un diario delle dichiarazioni — ma non nella beta che introduce il campo.**
Scartata: la conclusione precedente «non serve storicizzare», smentita dall'art. 818 co. 3 c.c., dalla risposta AdE 765/2021 sul massimale dell'unità privata e dall'asimmetria con un motore che sta diventando temporale. Scartata anche l'ipotesi opposta, un legame con validità piena e versioni: costa più di quanto serva finché nessun calcolo lo legge.

⚠️ **Questa decisione, nella sua prima stesura, contraddiceva il §8 di questo stesso documento** — «ogni nuova data deve nascere con il suo lettore, o non nascere» — perché prescriveva le due date «anche se nessun calcolo le legge il giorno in cui vengono aggiunte». Le date sono *giuste*, e il §8 è la regola di casa che ha già prodotto quattro famiglie di date scritte e mai lette: `tabelle.data_inizio/data_fine`, la pivot `esercizio_gestione`, `fatture_passive.dati_extra.competenza` e le date di `anagrafica_immobile`, che sono costate la beta.50 in testi da riscrivere.

La contraddizione si scioglie nel tempo, non nel merito. **Il campo `pertinenza_di_immobile_id` nasce senza date**, perché senza date è già utile e nessuno gli attribuisce una capacità che non ha. Le due date arrivano **insieme al primo lettore**, che sarà il calcolo del massimale sull'unità privata o la comunicazione all'Agenzia delle Entrate. Il costo di quella scelta è una seconda migrazione su una colonna viva, ed è il costo minore: aggiungere due colonne nullable a una tabella che ha già il legame è un'operazione senza backfill e senza rischio, mentre una data scritta per anni e mai letta diventa un dato di cui nessuno sa più la provenienza.

Fino ad allora la cessazione si registra nel diario delle dichiarazioni, che risponde a «chi ha dichiarato cosa e quando» e **non** a «era pertinenza al 31/12/2025?». La seconda domanda resta senza risposta, ed è corretto che il documento lo dica invece di far credere il contrario.

*(Se Vincenzo decide diversamente — date subito, eccezione dichiarata al §8 — va scritto **qui** e va scritto **là**, perché due documenti che divergono sulla stessa regola sono il difetto che questa nota esiste per evitare.)*

**D5 — Le pertinenze si propongono nel passaggio con caselle non spuntate.**
Scartata: la spunta preselezionata, proposta da due ricerche su tre, perché l'art. 818 co. 1 c.c. vale fra le parti dell'atto e verso il condominio conta il titolo trasmesso (art. 63 co. 5). Una casella preselezionata è un automatismo silenzioso per l'utente medio, e sposta rate, saldi e netting.

**D6 — Struttura del legame: una chiave esterna nullable su `immobili` (`pertinenza_di_immobile_id`) più i campi di data, e rimozione di `immobile_pertinenza` e del model `Pertinenza` nella stessa beta.**
Scartata: l'adozione del pivot esistente, che è molti-a-molti (un box pertinenza di due unità), porta una `quota_possesso` appesa a un legame che per definizione non produce numeri — la stessa dinamica di `tipologie_spese`, scritta per anni e mai letta — e ha entrambe le foreign key in cascade, quindi non sopravvive alla chiusura dell'unità. Scartata la convivenza delle due forme: due rappresentazioni dello stesso fatto sono la premessa dei campi morti già trovati nella beta.50.

**D7 — Il legame interno resta confinato al singolo condominio; il caso Tognoli si rappresenta come destinazione esterna descrittiva, testo libero più documento allegato.**
Scartato: il legame che attraversa due condomini gestiti dallo stesso studio, che farebbe uscire dati da un fascicolo condominiale a un altro con conseguenze su permessi, stampe e fascicolo di consegna.

**D8 — Il perimetro normativo del contratto è un campo esplicito sulla riga di titolarità (abitativo · uso diverso art. 27 · atipico artt. 1571 ss. · comodato).**
Scartata: la regola «box uguale nessun onere accessorio», che sbaglia sul box locato a un artigiano o a un professionista. È il campo da cui dipendono tabella applicabile, derogabilità dei default, esistenza del prospetto e diritto di voto.

**D9 — La mappatura conto → soggetto ha tre stati: locatore, conduttore, non previsto in tabella.**
Scartati i due stati, perché l'Allegato D non ha una sezione autorimessa e per porta basculante, ventilazione e adeguamento antincendio non esiste una riga: il terzo stato obbliga l'amministratore a scegliere e registra che la scelta è sua.

**D10 — Il regime urbanistico del parcheggio è un attributo facoltativo con default «da verificare» e quattro valori: nessuno · L. 122/1989 art. 9 co. 1 · L. 122/1989 art. 9 co. 4 (area comunale in diritto di superficie) · standard art. 41-sexies ante 16/12/2005.**
Scartato: il catalogo a tre voci che collassava comma 1 e comma 4, cioè il caso in cui la cessione separata è ammessa con ridestinazione e quello in cui gli atti sono nulli salvo autorizzazione del comune. Scartata anche la deduzione automatica dall'anno di costruzione o dalla tipologia. Il campo esiste solo se compare almeno in un punto dove qualcuno lo legge — il pannello «Cosa cambierà» — altrimenti va tolto.

**D11 — Il prospetto delle detrazioni elenca i bonifici dell'amministratore e le quote versate dal condòmino; non attribuisce le rate.**
Scartata: l'attribuzione al titolare alla data del bonifico, smentita dalla Circolare AdE 95/E del 12 maggio 2000 punto 2.1.14 e dalla pagina istituzionale «Quando si trasferisce la detrazione», che guardano al possesso al 31 dicembre. Il programma non decide a chi spetta: la legge concede alle parti la facoltà di derogare.

**D12 — Il legame non entra in nessuno dei tre servizi di riparto, in nessuna forma, nemmeno come cascata di ripiego.**
Scartata ogni ipotesi di far pesare la pertinenza sul riparto: le spese dell'autorimessa gravano sui titolari dei box tramite la tabella dedicata (art. 1123 co. 2 e 3 c.c.), non tramite il legame.

## 8. Cosa non si fa, e perché

**Non si rompe un legame di pertinenza in automatico.** Il vincolo nasce da una destinazione materiale e da una volontà che il programma non vede. La divergenza di titolari è un indizio sul requisito soggettivo, e in almeno quattro casi frequenti — comunione ereditaria pendente con effetto retroattivo della divisione (art. 757 c.c.), usufrutto costituito sul solo box, anagrafe non aggiornata dentro i sessanta giorni dell'art. 1130 n. 6, comunione legale non registrata — la conclusione automatica sarebbe sbagliata o riguarderebbe un fatto diverso.

**Non si blocca mai la registrazione di un fatto avvenuto.** Né per mancanza del titolo, né per vincolo urbanistico, né per divergenza di titolari. L'amministratore registra ciò che è successo; il controllo di validità dell'atto l'ha fatto il notaio.

**Non si dichiara la nullità di nulla, e non si dice all'amministratore quale regime urbanistico si applichi.** La distinzione fra parcheggio dentro e oltre lo standard richiede un calcolo volumetrico; quella fra comma 1 e comma 4 della legge Tognoli sta nella convenzione col comune; l'applicazione nel tempo della novella del 2012 ai parcheggi realizzati prima è affermata dalle fonti consultate ma non l'ho trovata affermata da una pronuncia con estremi. Un gestionale che desse un parere su questo darebbe un parere legale che non può dare.

**Non si gestiscono i contratti di locazione.** Niente durate minime, niente rinnovi automatici, niente chiusura automatica alla scadenza, niente canone in anagrafica. L'art. 1130 n. 6 c.c. chiede tre dati sul conduttore — generalità, codice fiscale, residenza o domicilio — e un'anagrafica che ne chieda di più raccoglie dati che non le spettano.

**Non si emette nulla intestato al conduttore verso il condominio, e non si propone la sospensione dei servizi.** L'art. 63 co. 3 disp. att. c.c. è una facoltà, e su unità locata colpisce un incolpevole: dove la legge concede una facoltà, la scelta è dell'amministratore, non del programma.

**Non si stampa la segnalazione «da verificare» dentro il registro di anagrafe.** Il contenuto del registro è tipizzato e il registro circola. Il dubbio va su un allegato dell'amministratore.

**Non si invia nulla all'Agenzia delle Entrate in automatico**, e non si implementa la logica dei record di dettaglio finché le specifiche non sono verificate sul software di controllo formale.

**Non si scrive «l'ex condomino non è vincolato dalle delibere successive»** in nessun testo di interfaccia: Cass. 24069/2022 dice che l'alienante resta obbligato per le spese maturate mentre era proprietario anche se il consuntivo è approvato dopo, e che la delibera ricognitiva è prova anche contro di lui. Si scrive invece che, perfezionato il trasferimento, la via non è più il decreto immediatamente esecutivo dell'art. 63 co. 1 ma il ricorso ordinario.

**Non si aggiunge una quinta famiglia di date scritte e mai lette.** Il progetto ne ha già quattro: `tabelle.data_inizio/data_fine`, la pivot `esercizio_gestione`, `fatture_passive.dati_extra.competenza` e le date della pivot `anagrafica_immobile`. Ogni nuova data deve nascere con il suo lettore, o non nascere.

## 9. Ordine di realizzazione

La dipendenza va detta senza ambiguità, perché è la domanda che decide il piano.

**Le pertinenze descrittive si possono fare prima, e indipendentemente.** Campo «Pertinenza di» con le sue due date, sottoriga nell'elenco unità, chip sull'unità principale, filtro «Da collegare», avviso di titolari divergenti nelle tre forme, forma esterna per il caso Tognoli, `BadgeRuolo.vue` con l'ambra per il nudo proprietario, ordine e separatore in `anagrafiche/columns.ts`, colonna «Titolare» in `immobili/columns.ts`, riga «Titolare dal …» in `ImmobiliView.vue`. Niente di tutto questo tocca il motore, perché il legame non entra in nessun calcolo. Può uscire nella 1.10.

**Le pertinenze operative dipendono dal pro-rata, e non possono uscire prima.** La ragione è aritmetica e verificata: la guardia 2 di `ValidatesImmobileAnagraficaPivot` rifiuta due periodi consecutivi, e se due righe entrano per altra via il motore le legge come comproprietà contemporanea e produce **50/50 esatto, identico per un rogito di gennaio e per uno di dicembre**. Rendere registrabili due periodi prima dell'aritmetica che li divide significa consegnare un numero sbagliato con la firma del sistema. Quindi `PassaggioNew.vue`, `AnteprimaPassaggio.vue`, `TitolaritaSheet.vue`, il cancello e la coppia di righe di conguaglio escono con il blocco B2 del documento sul subentro, cioè con la 1.11.

**Prima di entrambe, però, va chiuso un difetto che è già in produzione e che il rimedio corrente degli amministratori raggiunge ogni volta.** La divergenza fra le due stampe dopo una dissociazione — gran totale 100000 centesimi per tabelle contro 40000 per capitoli sullo stesso piano — è lo stesso difetto che A3 ha chiuso per una stampa sola nella beta.50, ancora aperto sulla gemella, e non è coperto da alcun test. La beta.51 sta già toccando `RipartoCapitoliService`: è lì che va chiuso, insieme al tracciamento del peso scoperto e alla sigla mancante del nudo proprietario in `:124-128`.

Sequenza proposta:

1. **1.10, subito** — divergenza fra le due stampe dopo la dissociazione, tracciamento dello scoperto in `RipartoCapitoliService`, sigla `nuda_proprietario`, `after_or_equal` mancante in `UpdateImmobileAnagraficaRequest`, `min`/`max` su `quota`, avviso sulla dissociazione quando esistono rate emesse, `BadgeRuolo.vue`.
2. **1.10** — decisione strutturale sul legame (D6: chiave esterna datata, rimozione del pivot e del model), campo «Pertinenza di» e tutta la parte descrittiva dell'interfaccia, comando di verifica dei legami divergenti costruito dentro `VerificaTitolaritaCommand` e non accanto.
3. **1.10 o 1.10.1** — bonifica dei dati: le quattro anomalie di quota già rilevate (immobile 202 con quota 0, immobile 204 con quota 50 su titolare unico, immobili 227 e 228 con somma 200), che sono subentri registrati come comproprietà e costano denaro oggi.
4. **1.11 (blocco B2)** — filtro temporale nel motore, `RisolutoreTitolari`, pro-rata doppio (giorni per l'ordinario, gradino per lo straordinario), indice unique sulla terna limitato al periodo di validità — che va aggiunto **insieme** al filtro temporale, altrimenti rifiuta una successione legittima di conduttori.
5. **1.11** — operazione «Registra passaggio», pannello «Cosa cambierà», pannello storico, conguaglio, `data_ricezione_titolo`, nota di solidarietà per coppia (anagrafica, immobile).
6. **1.11 o dopo** — prospetto oneri accessori, limitato ai perimetri in cui l'art. 9 L. 392/1978 si applica; pro-rata fra conduttori successivi, che è il caso più semplice e potrebbe anche precedere il resto.
7. **Non in roadmap** — prospetto detrazioni, comunicazione precompilata, campo del regime urbanistico se il pannello che lo legge non esce.

## 10. Questioni aperte

### Decisioni di Vincenzo

- **Struttura del legame.** La proposta è D6, chiave esterna datata e rimozione di `immobile_pertinenza` e del model `Pertinenza`. La tabella è già in produzione, quindi la scelta ha un costo di migrazione in entrambi i sensi, e va presa prima di scrivere una riga di interfaccia.
- **Dove chiudere la divergenza fra le due stampe.** È un difetto della beta.51, che sta toccando proprio quel file, o va in coda alla 1.10.1 con il resto del blocco A? Il criterio di progetto — i difetti restano nella release che li crea — suggerisce la prima.
- **Il campo del regime urbanistico del parcheggio.** Nessun calcolo lo legge e il programma non può verificarlo: rischia di essere il terzo campo morto dopo `tipologie_spese` e `dati_extra.competenza`. Tenerlo solo se esce insieme al pannello che lo mostra.
- **Come si riconosce una tipologia pertinenziale.** `tipologie_immobili` ha una colonna `categoria` con valore `pertinenza`, che però non è l'informazione abitativo/non abitativo richiesta dalla precompilata. Decidere se aggiungere un flag dedicato o mostrare il campo «Pertinenza di» sempre come facoltativo — la seconda non richiede migrazioni.
- **Il conguaglio proposto è modificabile a mano?** Una coppia modificabile è più utile e più pericolosa: l'invariante «somma zero» va presidiata, non dichiarata.
- **Il passaggio in blocco su più unità non collegate fra loro** (stesso proprietario che vende tre unità): seconda strada dall'elenco unità con selezione multipla, o si accetta di ripetere l'operazione? La prima moltiplica per tre la superficie dell'anteprima.
- **La costituzione di usufrutto** va trattata come voce a sé nel sotto-menu, con due soggetti invece di uno, o come vendita con ruolo diverso? La prima è più chiara, la seconda costa meno.
- **Quale colonna cede il posto a «Titolare» in `ImmobiliList`.** Da decidere guardando la pagina reale su `http://127.0.0.1:8001`, non a tavolino.

### Verifiche sui dati

- Le quattro anomalie di quota già rilevate: gli immobili 227 e 228 con tre righe `proprietario` e somma 200,00 sono subentri o vere comproprietà? È esattamente il terzo segnale che `kondomanager:verifica-titolarita` cerca.
- L'immobile 202 con riga inquilino a quota 0: dato sporco da bonificare o difetto del motore da presidiare con un tracciamento? Oggi il peso evapora senza essere segnalato come scoperto.
- Le 60 righe della pivot hanno `data_inizio` fra il 2024-11-01 e il 2026-07-27, ma per le righe importate quella è la data del censimento e non la decorrenza del diritto (`LivelloTitolarita.php:150-176`). Prima di accendere il filtro temporale va deciso cosa farne: un dato ricavato che si presenta come dichiarato è peggio di un dato mancante, e se compare deve portare l'etichetta «data stimata dall'importazione».
- Non ho verificato se esista un percorso che, dissociando l'ultimo titolare di un'unità, la lasci senza alcun soggetto e mandi silenziosamente a scoperto ogni sua quota nel piano successivo, né il comportamento a video del cancello `accetta_scoperti` in quel caso.

### Non verificato

- **Le specifiche tecniche della comunicazione all'Agenzia delle Entrate.** L'Allegato 1 al provvedimento prot. n. 50559/2026 del 10 febbraio 2026 non è estraibile: il PDF pubblicato è privo di livello di testo. Restano senza riscontro su fonte primaria le regole sul record di dettaglio per soggetto, sul Flag Pagamento e sulla Tipologia del soggetto, e con esse la ricostruzione secondo cui una vendita a metà anno produca due record per la stessa unità. Non è inoltre accertato con quale criterio vada compilato il numero delle pertinenze quando il legame cessa in corso d'anno: all'inizio, alla fine, o alla data del bonifico. È la domanda che decide l'algoritmo.
- **Cass. civ. n. 17036/2016** sul fondo cassa: nota solo attraverso fonti divulgative, testo integrale non consultato. Prima di scrivere qualunque testo di interfaccia che la citi va letta la motivazione, perché il conflitto con Trib. Palermo 2252/2022 potrebbe essere apparente (fondo cassa ordinario contro fondo speciale vincolato a un'opera determinata) o reale.
- **Cass. civ. n. 13742/2019** sul requisito soggettivo: non riverificata.
- **La forma di Cass. 25558/2020** (sentenza o ordinanza) non è stata verificata sul provvedimento; le rassegne la riportano come sentenza della sez. II.
- **La giurisprudenza tributaria** che valorizzerebbe il dato fattuale rispetto alle risultanze catastali: il commento che la riporta non indica numero, sezione e data. Non va usata come contrappeso a una posizione dell'Agenzia.
- **L'usufrutto costituito sulla sola pertinenza**: nessuna giurisprudenza specifica trovata. La conclusione proposta si ricava dall'art. 817 co. 2 c.c. e da Cass. 9563/2005 e 24432/2017.
- **La solidarietà dei coeredi comproprietari verso il condominio** per i contributi maturati dopo l'apertura della successione: il principio si regge sull'art. 1294 c.c., il numero della pronuncia no.
- **L'applicazione nel tempo della novella Tognoli del 2012** ai parcheggi realizzati prima del 10 febbraio 2012: le fonti consultate danno per applicabile il nuovo regime, ma non l'ho trovato affermato da una pronuncia con estremi.
- **La locazione separata di un box Tognoli** a soggetto estraneo all'unità principale: nessuna pronuncia trovata.
- **L'estensione al comodatario del voto dell'art. 10 L. 392/1978**: sostenuta in prassi, non trovata in giurisprudenza; la norma nomina il conduttore ed è dichiarata eccezionale (Cass. 5238/1986).
- **La convocazione diretta del conduttore dopo la L. 220/2012**: non risulta una pronuncia di legittimità successiva alla riforma. La tesi tradizionale (Cass. 4802/1982) è anteriore alla novella.
- **Il calcolo dei quorum quando i conduttori votano** in luogo dei proprietari, in particolare quando un condòmino ha locato più unità: ricostruzione dottrinale, senza copertura normativa né massima specifica.
- **La solidarietà dell'art. 63 co. 4 su una pertinenza priva di millesimi propri**, cioè inclusa in quelli dell'unità principale: in quel caso non esiste un contributo separabile «relativo all'unità acquistata», e il perimetro della solidarietà è indeterminato. Nessuna giurisprudenza trovata.
- **La qualificazione dell'adeguamento antincendio dell'autorimessa** nell'Allegato D: la sezione «Impianto antincendio» non ha la riga «adeguamento a leggi e regolamenti», che esiste invece in «Ascensore» e in «Impianti di riscaldamento». Ricade in «non previsto in tabella».