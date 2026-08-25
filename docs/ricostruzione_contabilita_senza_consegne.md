# Ricostruire un condominio senza passaggio di consegne

<!-- verifica-documentazione -->
> **Stato:** verificato sul codice il **23/08/2026**, **riverificato a video la sera stessa su
> `1.10.0-beta.75`** percorrendo l'intero ciclo su un condominio creato da zero dall'interfaccia.
> Le affermazioni nascono da un'analisi a cinque agenti con verifica avversariale su `saldi iniziali`,
> `contributi_versati`, `fatture pregresse`, `esercizi/movimenti` e `piani rate`.
>
> ⚠️ **Il §7 è stato riscritto dopo la beta.75**, che ha cambiato dove finisce il pregresso a libro
> giornale: la prima stesura descriveva il difetto come comportamento corrente, ed è rimasta vera
> mezza giornata. Ora descrive il comportamento corretto, con il difetto in nota storica.
>
> **Provato da test eseguiti**: `RicostruzionePregressoRataZeroTest` (6 verdi) e
> `DebitoPregressoImportoMascheratoTest` (4 verdi). Dove un'affermazione è dedotta dalla sola
> lettura, è marcata *(letto, non eseguito)*.
>
> ⚠️ **Il §7-bis è una controindicazione attiva, ma da metà**: dalla **beta.76** la stampa «Riparto
> per Capitolo» è corretta, la gemella «per Tabella» **no**. Va letto prima di consegnare un riparto
> a un'assemblea.
<!-- /verifica-documentazione -->

**Origine:** un amministratore iscritto alla demo, agosto 2026. Condominio autogestito di quattro unità,
amministratore revocato ai primi di agosto **senza consegne**, contenzioso in corso. Deve ricostruire la
contabilità dal 2022: circa 300 operazioni, nessun file esportato dal vecchio gestionale, solo estratti
conto e fatture su carta.

Non è un caso isolato: è la forma più dura del passaggio di consegne, ed è il momento esatto in cui un
amministratore cerca un programma nuovo. L'importatore non lo copre — legge i quattro report di Danea, e
qui non c'è niente da cui importare.

---

## 1. La domanda che decide tutto

**Il passato serve come posizione o come racconto?**

| | **Posizione** | **Racconto** |
| :--- | :--- | :--- |
| Cosa si porta dentro | dove siamo oggi: chi deve, chi è a credito, quanto c'è in banca | ogni fattura, ogni pagamento, ogni incasso, anno per anno |
| Quante righe | poche decine | ~300 |
| Ore dentro il gestionale | 1-2 giornate | 35-50 ore, 5-7 giornate piene |
| Cosa serve a monte | sapere quanto deve ciascuno al giorno dell'ingresso | la stessa ricostruzione su carta, in più dettagliata |

Il lavoro *su carta* — leggere quattro anni di estratti conto e stabilire chi ha versato cosa — va fatto
in tutti e due i casi. È il lavoro *dentro il gestionale* che cambia di un ordine di grandezza.

**La raccomandazione: percorso posizione, con un'eccezione.** L'esercizio in corso — da gennaio alla
revoca — conviene ricostruirlo davvero: è l'anno di cui il nuovo referente risponde in assemblea, i
documenti ci sono e le operazioni sono poche. Gli anni chiusi restano una posizione per unità.

Le tre ragioni per cui il racconto integrale è sconsigliato stanno al §8. La prima pesa più delle altre:
**non c'è modo di registrare un'entrata che non sia una rata.**

---

## 2. Il caso di prova

`Condominio Via Aurelia 12` — Roma, quattro unità, autogestito. Ingresso in KondoManager al **01/01/2026**.

Ricostruito dagli estratti conto 2022-2025:

| Unità | Titolare | Millesimi | Posizione al 31/12/2025 |
| :--- | :--- | ---: | ---: |
| Int. 1 | Rossi | 250 | € 450,00 a debito |
| Int. 2 | Bianchi | 300 | € 600,00 a debito |
| Int. 3 | Verdi | 200 | € 300,00 a debito |
| Int. 4 | Neri | 250 | € 450,00 a debito |
| | | **1000** | **€ 1.800,00** |

Saldo del conto corrente al 31/12/2025: **€ 2.150,00**. Fatture ancora scoperte: una, dell'idraulico, da
**€ 380,00**. Preventivo 2026 deliberato: **€ 1.600,00**, cioè € 400,00 a unità.

Il segno segue la convenzione di casa: **positivo = debito del condòmino, negativo = credito.**

---

## 3. Il percorso, passo per passo

### Passo 1 — L'esercizio, e uno solo

`Gestionale → {condominio} → Esercizi`. Si crea **solo il 2026**, stato «aperto». Gli anni 2022-2025 non
si creano: non servono, e ogni esercizio in più è un posto in cui i dati possono finire per sbaglio.

> **Invariante:** al più un esercizio «aperto» per condominio. Un secondo aperto viene rifiutato in
> validazione (`CreateEsercizioRequest.php:51-73`). Un esercizio **chiuso** si riapre, ma solo se nessun
> altro è aperto (`UpdateEsercizioRequest.php:53-84`) *(letto, non eseguito)*.

### Passo 2 — La gestione e il piano dei conti

`Esercizi → {esercizio} → Gestioni`, poi `Piani dei conti`. Gestione ordinaria 2026 con i capitoli di
spesa a preventivo.

> Senza piano dei conti e senza `data_inizio` la creazione del piano rate si ferma con eccezione
> (`PianoRateCreatorService.php:23-35`). La gestione appartiene al **condominio**, non all'esercizio:
> il legame è il pivot `esercizio_gestione`.

### Passo 3 — Unità, persone, millesimi

`Struttura → Immobili / Anagrafiche`, poi `Struttura → Tabelle millesimali`. Ogni capitolo di spesa va
collegato ad almeno una tabella.

> **⚠️ La trappola più costosa dell'intero percorso.** Un millesimo lasciato **vuoto** genera lo scoperto
> `millesimo_non_compilato` e blocca la generazione del piano finché non si accetta con una motivazione;
> e se si accetta, quell'unità **non compare affatto nel piano, nemmeno a zero**: la sua quota la pagano
> le altre (`MillesimoNonCompilatoTest.php:211-229`). Un millesimo scritto **zero**, invece, non avvisa
> mai: è la convenzione per «non partecipa» (`CalcoloQuoteService.php:915-918`).
>
> **Regola operativa: se non lo sai, lascia vuoto. Non scrivere zero.** Il vuoto ti avvisa, lo zero no.

### Passo 4 — La cassa, e non prima dell'esercizio

`Gestionale → Casse`. Cassa di tipo «banca», `saldo_iniziale` = **€ 2.150,00**, il saldo reale
dell'estratto conto al 01/01/2026.

La creazione registra a giornale l'apertura — DARE cassa / AVERE «Fondo Passate Gestioni» — e azzera la
colonna: il dato si sposta dalla colonna al giornale, mai in due posti insieme.

> **⚠️ Irreversibile.** L'apertura si aggancia al **primo esercizio per `data_inizio`** e ne eredita la
> data (`RegistraAperturaCassaAction.php:66-68`). Una volta registrata, `saldo_iniziale` è congelato:
> qualunque valore inviato in modifica viene ignorato (`UpdateCassaAction.php:53-58`), non esiste storno
> per il tipo di movimento «apertura», e la cassa non è più eliminabile. **Un saldo di apertura sbagliato
> resta sbagliato per sempre.** Si controlla due volte prima di salvare.

### Passo 5 — I saldi iniziali dei condòmini

`Struttura → Saldi Iniziali`. Dentro l'accordion della gestione, per ciascuna unità, «Aggiungi debito»
o «Aggiungi credito». Quattro righe.

Due modi di intestare il pregresso:

- **personale** (anagrafica valorizzata): il debito segue la **persona**;
- **solidale** (anagrafica vuota, art. 63 disp. att. c.c.): il debito segue l'**unità**, e verrà messo a
  carico dei titolari di diritto reale.

Quando i nomi non sono certi — ed è il caso tipico di chi non ha ricevuto le consegne — **il solidale è
la scelta giusta**: dice la verità su ciò che si sa, cioè che la posizione appartiene all'unità.

> L'esercizio non è scegliibile: `store()` usa sempre l'esercizio corrente
> (`SaldoInizialeController.php:111,:123`). **Nessun campo data, nessun anno di competenza, e la
> descrizione non è compilabile da questa pagina.** Il pregresso resta un numero netto: € 450,00, non
> «€ 300,00 del 2022 e € 150,00 del 2023».

### Passo 6 — I debiti verso i fornitori

`Fornitori → {fornitore} → Situazione debitoria`. Una riga da **€ 380,00** per l'idraulico.

> È l'**unica** porta d'ingresso per un debito verso fornitore: la pagina dei Saldi Iniziali non lo sa
> fare. A database finisce nella stessa colonna dei saldi dei condòmini ma con **importo negativo** e
> `fornitore_id` valorizzato (`FornitoreSituazioneDebitoriaController.php:79-81`).
>
> **⚠️** Sommare `saldo_iniziale` senza filtrare `fornitore_id` non dà un numero che significhi qualcosa:
> nella stessa colonna convivono due famiglie con due convenzioni di segno diverse.

### Passo 7 — La fattura pregressa ancora aperta

`Fatture → Registra documento`, con la spunta **«Debito esercizio precedente»**. Solo le fatture con un
residuo da pagare: quelle già saldate non si registrano, sono già dentro il saldo di cassa del passo 4.

> La retrodatazione del documento è libera, ma `data_registrazione` è sempre `now()` e il protocollo usa
> l'anno corrente: una fattura del 2025 riceve `FTP-2026-NNNNN`. **La fattura pregressa non è mai
> modificabile**: l'unica via è lo storno (`FatturaPassivaService.php:590-592`).
>
> **⚠️** Nella modale della spesa imprevista, su una pregressa la strada pulita è **«Delibera
> assembleare»**. «Intervento d'urgenza» marca la fattura come sforo motivato con importo zero, e «Usa
> fondo riserva» genera una copertura da € 0,00 che il giroconto di conferma rifiuta.

### Passo 8 — Il piano rate con la Rata 0

`Esercizi → 2026 → Piani rate → nuovo`. Si selezionano i capitoli a preventivo, il numero di rate, e per
il pregresso si lascia il metodo predefinito: **«Crea rata separata (Rata 0 — Consigliata)»**.

Il piano che ne esce sul caso di prova:

| | Rata 0 (pregresso) | Rata 1 (preventivo) | Totale |
| :--- | ---: | ---: | ---: |
| Rossi | € 450,00 | € 400,00 | € 850,00 |
| Bianchi | € 600,00 | € 400,00 | € 1.000,00 |
| Verdi | € 300,00 | € 400,00 | € 700,00 |
| Neri | € 450,00 | € 400,00 | € 850,00 |
| **Totale** | **€ 1.800,00** | **€ 1.600,00** | **€ 3.400,00** |

> Ogni fetta di spesa non attribuibile è uno «scoperto» che **ferma** la generazione finché non si accetta
> esplicitamente con una nota di almeno 10 caratteri (`GeneratePianoRateAction.php:186-222`). Un piano con
> `numero_rate = 1` produce **due** rate: la Rata 0 e la Rata 1, con la stessa scadenza.

### Passo 9 — Controllare la stampa giusta

> **⚠️ Le due stampe di riparto trattano il pregresso in due modi diversi.** `RipartoTabelleService`
> manda ogni residuo nella pseudo-colonna «addebito diretto» (`:293-298`); `RipartoCapitoliService` lo
> appoggia sul capitolo dove il soggetto pesa di più (`:329-337`). Lo stesso piano produce due PDF che
> raccontano due distribuzioni diverse dello stesso pregresso.
>
> **Agli atti va la stampa per tabelle.** Consegnarle entrambe all'assemblea significa consegnare una
> contraddizione.

### Passo 10 — Approvare ed emettere

Approvazione del piano, poi emissione. Da qui in poi i saldi assorbiti **non sono più correggibili**: la
soglia di immutabilità è `haRateEmesse() || haIncassiRegistrati()`. Prima di quella soglia si corregge
liberamente; dopo, non si modifica, non si cancella e non si sblocca a mano.

**Prima di emettere si controlla tutto.** È l'ultimo momento in cui si può.

### Passo 11 — Da qui in avanti

Gestione ordinaria: fatture, pagamenti, incassi con le date reali. E, per il solo 2026 già trascorso, la
ricostruzione dei movimenti da gennaio ad agosto dentro questo stesso esercizio.

---

## 7. Cosa finisce davvero a libro giornale *(provato da test)*

`tests/Feature/Gestionale/RicostruzionePregressoRataZeroTest.php` — 6 test verdi sul caso di prova,
più la verifica a video dell'intero ciclo sul condominio «Via Ostiense 40» il 23/08/2026.

**Emettendo il piano nascono due scritture, e la contropartita non è la stessa.** Il condòmino deve
l'intera quota, quindi il DARE su `crediti_condomini` è sempre l'importo pieno. Cambia ciò che gli
sta di fronte:

| | DARE | AVERE |
| :--- | :--- | :--- |
| Componente deliberata per l'anno | `crediti_condomini` | `gestione_rate` |
| Riporto da esercizi precedenti (la Rata 0) | `crediti_condomini` | `passate_gestioni` |

`passate_gestioni` è lo stesso conto con cui l'apertura di cassa porta dentro una posizione
anteriore: il pregresso non è un provento dell'anno, e non deve gonfiare il deliberato. Sul caso di
prova il 2026 dichiara **€ 1.600,00 di Gestione Rate**, cioè esattamente il preventivo approvato,
con i € 1.800,00 di riporto su un conto suo.

**A incasso completo il conto Crediti torna esattamente a zero**: l'incasso è simmetrico, non resta
né un credito fantasma né un saldo a credito. Un riporto **negativo** — il condòmino che arriva a
credito — si scrive nel verso opposto senza sbilanciare la scrittura.

> ⚠️ **Fino alla beta.74 non era così, ed è utile sapere perché.** L'emissione avrebbe dovuto
> registrare la sola `regole_calcolo.importi.quota_pura_gestione`, ma quel ramo era **codice morto**:
> il Model ha `'regole_calcolo' => 'json'` fra i casts, quindi l'attributo torna un array, si prendeva
> il ramo `(object) $array` — cast **superficiale** — e `isset($json->importi->…)` era sempre falso.
> Veniva registrato l'importo pieno su `gestione_rate`, riporto compreso: il 2026 risultava aver
> chiamato **€ 3.400,00** invece di € 1.600,00. Invisibile sulle rate ordinarie, dove i due numeri
> coincidono; visibile solo sulla Rata 0. **Corretto nella beta.75.**

### Il bilancio del caso di prova

Percorso completo — apertura cassa, saldi iniziali, debito fornitore, fattura pregressa, già
versato, piano con Rata 0, emissione, incasso — verificato a video:

| Conto | Saldo |
| :--- | ---: |
| Conto corrente condominiale | € 3.000,00 |
| Crediti verso Condòmini | € 2.550,00 |
| Debiti v/Fornitori | −€ 380,00 |
| Fondo Passate Gestioni | −€ 3.570,00 |
| Gestione Rate | −€ 1.600,00 |
| **Sbilancio** | **€ 0,00** |

Il conto corrente combacia con l'estratto conto, lo stato patrimoniale quadra, e **Gestione Rate
resta il deliberato dell'anno**.

---

## 7-bis. ⚠️ Delle due stampe di riparto, per ora usa quella «per Capitolo»

*(Coda 76 — chiusa nella beta.76. Coda 77 — **aperta**)*

Entrambe le stampe ricostruiscono la matrice dagli importi **lordi** dei capitoli e poi la
riallineano alle quote emesse con un «residuo». In quel residuo finiscono **due grandezze di segno
opposto** — il pregresso, che aumenta il dovuto, e il già versato, che lo diminuisce — e quando
convivono si annullano.

Misurato sul caso di prova (€ 1.800,00 di pregresso, € 2.000,00 di già versato su € 5.000,00 di
lavori più € 1.600,00 di amministrazione), **prima** della beta.76:

- la stampa **per capitoli** mostrava «Rifacimento facciata € 4.800,00» — né il budget
  (€ 5.000,00) né quanto veniva chiesto (€ 3.000,00) — e il pregresso non compariva da nessuna parte;
- la gemella **per tabelle** mostrava la tabella al lordo (€ 6.600,00) e «addebito diretto» a
  **−€ 200,00**.

I gran totali tornavano entrambi (€ 6.400,00), ed è ciò che rendeva il difetto invisibile a un
controllo di quadratura.

### ✅ «Riparto per Capitolo» — corretta dalla beta.76

Il capitolo torna a valere il deliberato e le due grandezze hanno una colonna ciascuna:

```
Rifacimento facciata      € 5.000,00
Amministrazione           € 1.600,00
Già versato              −€ 2.000,00
Saldi precedenti          € 1.800,00
─────────────────────────────────────
Totale                    € 6.400,00
```

È la stampa da portare in assemblea quando ci sono arretrati o versamenti anticipati.

### ⛔ «Riparto per Tabella» — ancora da correggere (Coda 77)

Lì il residuo continua a finire nella colonna degli **addebiti diretti**, che però ha già un
contenuto proprio: le spese di una sola unità. Le due cose si sommano e producono un terzo numero
che non è nessuna delle due. Misurato: con € 400,00 di balcone a carico dell'interno 1 e € 500,00
già versati, stampa **«Addebito diretto −€ 100,00»** — e in assemblea si legge «meno cento euro»
proprio al condòmino che ha avuto l'intervento a suo carico.

Finché la Coda 77 non è chiusa, per il pregresso fanno fede la stampa **per capitoli**, il **libro
giornale** e lo **stato patrimoniale**.

---

## 8. Perché non il racconto integrale

**(1) Le entrate che non sono rate non si registrano.** La regolazione immediata è monodirezionale per
costruzione — DARE capitolo / AVERE cassa, versi cablati, importo obbligatoriamente positivo
(`RegistraRegolazioneImmediataAction.php:126-141`). I tipi che coprirebbero il resto — `RIMBORSO_CONDOMINO`,
`INCASSO_DIVERSO`, `RIMBORSO_ASSICURATIVO`, `STRALCIO_CREDITO` — esistono nell'enum ma **non hanno alcun
produttore nel codice**. Un rimborso assicurativo, un contributo, un interesse attivo del 2023 non hanno
dove essere registrati. Su quattro anni di estratti conto qualcosa del genere c'è quasi sempre, e la cassa
non tornerà mai.

**(2) Per incassare serve un piano rate.** Ogni riga di incasso deve puntare a una `RataQuote` esistente:
ricostruire cinque anni di versamenti significa ricostruire prima cinque anni di piani rate con i loro
riparti, **anche quando la delibera originale non è disponibile**. In un contenzioso in corso, fabbricare
riparti plausibili ma mai deliberati è un rischio, non un vantaggio.

**(3) La cronologia di registrazione sarebbe comunque finta.** `data_registrazione` è `now()` quasi
ovunque; il libro giornale ordina proprio su quella e l'elenco Incassi ci filtra sopra. Trecento incassi
ricostruiti si accatastano tutti sotto la data di lavorazione. Il valore probatorio resta nei documenti
cartacei, non nella loro reimmissione.

**Non esiste una prima nota libera in partita doppia.** Il libro giornale è esposto in sola lettura: ogni
scrittura passa da uno stampo tipizzato — fattura, pagamento, incasso, regolazione immediata, giroconto,
F24, apertura cassa. Un fatto che non rientra in nessuno stampo non è registrabile.

---

## 9. Cosa resta da provare a mano

**Provato a video il 23/08/2026** e quindi tolto da questo elenco: l'intero percorso dei passi 1-11
su un condominio creato da zero (`Via Ostiense 40`), compresi il caricamento del debito verso
fornitore, la fattura pregressa agganciata a quel debito, il già versato con la scelta della
liquidità, l'emissione e l'incasso. Stato patrimoniale quadrato a ogni passaggio.

Resta da provare:

1. **La riapertura di un esercizio chiuso** quando nessun altro è aperto. Il meccanismo è leggibile ma
   nessun test lo prova: i test esistenti provano solo il **divieto** di riaprire quando ce n'è già uno
   aperto. È il perno della sequenza chiudi/riapri, e quindi del percorso «racconto».
2. **Emissione o inserimento saldi senza alcun esercizio aperto.** Il codice dereferenzia l'esercizio
   senza guardia e l'incasso ha una firma tipizzata non-nullable: il risultato atteso è un errore fatale,
   non un messaggio di dominio. Da verificare se qualche middleware intercetta.
3. **Tabelle millesimali cambiate nel tempo.** `Tabella` ha `data_inizio`/`data_fine`, ma `QuotaTabella`
   ha una sola riga per (tabella, immobile), senza versionamento. Non è verificato se il motore filtri le
   tabelle per periodo di validità. Decisivo solo per il racconto integrale.
4. **Storno di una fattura pregressa con eccedenza.** La lettura dice che la contropartita finisce su
   `passate_gestioni` invece di girare il costo su `sopravvenienze_passive`. Derivato, non eseguito.

### Due obblighi del modulo che il percorso incontra e che non sono difetti

Emersi provando a video, vale la pena saperli prima: l'**indirizzo di residenza** è obbligatorio
sull'anagrafica (ci vanno le convocazioni) e la **data di inizio competenza** lo è sull'associazione
persona↔unità.

---

## 10. Cosa questo caso dice al prodotto

Quattro mancanze che il caso mette in fila, in ordine di quanto pesano su chi entra:

1. **Un'entrata che non sia una rata.** È il blocco duro. I casi enum ci sono già; manca il produttore.
2. **L'import da foglio generico.** `GenericCsvDriver` è dichiarato inesistente e collocato in 1.10.1.
   Chi non ha consegne non ha un file Danea: ha, al massimo, un foglio suo.
3. **La causale sul saldo iniziale.** La colonna `descrizione` esiste ma la pagina non la scrive. In un
   contenzioso, «€ 450,00» senza spiegazione è un numero che qualcuno contesterà.
4. **Una quadratura d'insieme del wallet.** Nulla lega la somma dei saldi iniziali alla cassa e ai debiti
   verso fornitori: si può registrare una fotografia internamente incoerente senza che niente lo segnali.

---

## Documenti collegati

`architettura_saldi_iniziali.md` · `gestione_debiti_pregressi.md` · `import_migrazione_dati.md` ·
`fondo_accantonato_e_quadratura_sp.md` · `subentro_e_competenza_temporale.md` · `roadmap.md`
