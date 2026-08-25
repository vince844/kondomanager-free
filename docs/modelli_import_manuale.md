# Modelli di import manuale — i cinque fogli

<!-- verifica-documentazione -->
> **Stato:** Non implementato — proposta scritta l'08/08/2026 su 1.10.0-beta.47.
> Nessuno dei cinque fogli esiste: non c'è un `ManualDriver`, non c'è un template scaricabile, e il livello dei capitoli di spesa non esiste né come parser né come committer (§7).
> Quello che **è** stato fatto è la verifica sperimentale del §8: i fogli sono stati compilati con una chiusura reale e dati in pasto al motore, che ha ricostruito il consuntivo al centesimo. La proposta poggia su quella misura, non su una stima.
<!-- /verifica-documentazione -->

**Destinazione:** v1.10.1, insieme all'archivio storico e ai profili di mappatura — decisione del 06/08/2026, [`import_migrazione_dati.md`](import_migrazione_dati.md) §16.2.2.
**Origine:** analisi di una chiusura d'esercizio reale redatta a mano in Excel, agosto 2026.

---

## 1. Perché cinque e non sette

[`import_migrazione_dati.md`](import_migrazione_dati.md) §8 fissa la regola «un template per livello». Applicata alla lettera oggi darebbe **sette** modelli, uno per ciascun livello implementato — condominio, esercizi, soggetti, unità, titolarità, tabelle, saldi — due dei quali sarebbero file Excel per contenere sei campi in croce.

I livelli sono la giusta unità di misura per il **committer**, che ha bisogno di dipendenze dichiarate e prerequisiti verificati uno a uno sul database. Non lo sono per **l'amministratore che compila**, per il quale l'unica grandezza che conta è quanti elenchi deve riempire.

L'equivalenza «un livello = un template» va quindi rotta: i livelli restano sette, i fogli sono cinque più una copertina.

Il numero non è arbitrario ed è la parte verificata di questa proposta: sono esattamente gli elenchi che sono bastati al motore per ricostruire una chiusura completa da 25 unità (§8).

> **Il confronto con il concorrente, detto onestamente.** Nexia Home ha dodici livelli, ciascuno col suo template. Quattro dei dodici — transazioni, rate, fatture, acqua — sono **storia e operatività**, non lo stato da cui si parte: da noi lo storico entra come archivio, senza che nessuno lo trascriva. Presentare «cinque contro dodici» senza dire questo sarebbe la stessa scorrettezza che al §0.3 rimproveriamo a loro.

## 2. I cinque fogli

### 0 · copertina

Non è un elenco da compilare: sono sei campi in testa al pacchetto. Assorbe i livelli `condominio` ed `esercizi`.

| campo | note |
| :--- | :--- |
| `condominio` | obbligatorio |
| `codice_fiscale` | facoltativo; se manca lo segnala il controllo post-import |
| `indirizzo` | facoltativo |
| `esercizio` | etichetta, p.es. `2014` o `2024/2025` |
| `data_inizio` · `data_fine` | delimitano l'esercizio |

### 1 · unità

| colonna | obbligatoria | note |
| :--- | :---: | :--- |
| `unita` | sì | la chiave che tutti gli altri fogli richiamano: `B1/1`, `A/3` |
| `palazzina` · `scala` | no | vuote su un condominio a corpo unico |
| `interno` · `piano` | no | |
| `tipo` | no | default `appartamento` |

La chiave la sceglie l'amministratore e non deve seguire un formato nostro: gli altri quattro fogli la ripetono così com'è.

### 2 · persone e titolarità

**Una riga per titolare, non per unità.** È l'unico modo in cui le comproprietà e i doppi ruoli stanno nel foglio senza colonne che si moltiplicano.

| colonna | obbligatoria | note |
| :--- | :---: | :--- |
| `unita` | sì | deve esistere nel foglio 1 |
| `nome` | sì | |
| `codice_fiscale` | no | |
| `ruolo` | sì | `proprietario`, `inquilino`, `usufruttuario`, `nuda_proprietario` |
| `quota_pct` | no | default 100; le quote di uno stesso ruolo sulla stessa unità devono sommare a 100 |
| `email` · `telefono` | no | |

### 3 · tabelle millesimali

**Il numero di colonne lo decide l'amministratore**: ogni colonna oltre la prima è una tabella. È la stessa forma del report `anagrafica_millesimi`, e per la stessa ragione — i nomi delle tabelle non si possono scrivere in anticipo.

Tre righe di metadati sopra l'intestazione, perché sulle colonne dinamiche non c'è altro posto dove metterle:

```
# tabella                A - Generali   GPL fattura 1   Addebiti personali
# tipo quota             millesimi      mtcubi          quote
# totale di riferimento  1000           (vuoto)         (vuoto)
unita                    A - Generali   GPL fattura 1   Addebiti personali
B1/1                     44,65          42,28
B1/2                     44,24          42,28           8,25
…
# TOTALE DI CONTROLLO    999,96         1678,73         43,50
```

Il **totale di riferimento** è il campo `totale_riferimento` del Validatore Coerenza Millesimi (v1.10, [`note_tecniche_e_decisioni.md`](note_tecniche_e_decisioni.md)). Lasciarlo vuoto significa **pesi relativi**: la tabella non ha un totale a cui tendere e nessuno scarto va segnalato. Vedi §5.

### 4 · capitoli di spesa con gli importi

È il foglio senza il quale il motore non parte, ed è quello che oggi non ha dove atterrare (§7).

| colonna | obbligatoria | note |
| :--- | :---: | :--- |
| `capitolo` | sì | il nome che gli dà l'amministratore |
| `importo` | sì | dell'esercizio, competenza — **non** solo il pagato. Vedi §6 sui negativi |
| `tabella` | sì | dev'essere una delle colonne del foglio 3 |
| `a_carico_di` | no | default `proprietario` |

Assorbe i due livelli che nel concorrente sono separati, piano dei conti e budget: per una chiusura sono la stessa riga vista due volte.

### 5 · saldi di apertura

**L'unico foglio con conseguenze legali:** da qui nascono morosità, solleciti e decreti ingiuntivi. Vale la quadratura obbligatoria del §11 — scarto diverso da zero, niente entra.

| colonna | obbligatoria | note |
| :--- | :---: | :--- |
| `unita` | sì | |
| `persona` | no | **vuota = saldo solidale sull'unità**, art. 63 disp. att. c.c. — è la forma per un debito che segue la casa e non la persona |
| `importo` | sì | convenzione Kondomanager: **positivo = debito, negativo = credito** |
| `causale` | no | testo libero; utile quando la stessa unità ha più posizioni aperte |

Più righe per la stessa unità sono normali e vanno accettate: una chiusura reale porta in dote posizioni distinte — conguaglio dell'esercizio, morosità pregresse, quote di lavori straordinari — che non vanno sommate a monte, perché appartengono a gestioni diverse.

## 3. Le regole del copia-incolla

Sono la parte che decide se un modello viene usato o abbandonato al primo tentativo.

**L'area d'incollo dev'essere contigua, senza formule e senza celle unite, e partire da una riga fissa.** Non conta che l'intestazione sia a riga 1 — il foglio 3 ne ha quattro sopra i dati e va bene lo stesso. Conta che un `Ctrl+V` di una colonna non incontri niente che si rompa.

**Mai un totale in mezzo ai dati.** Nel riparto da cui nasce questa proposta le righe «TOTALI» di palazzina stanno *dentro* l'elenco delle unità, alle righe 16, 23, 30 e 37, e il totale complessivo alla 39. Chi seleziona il blocco e incolla si porta dentro cinque righe fantasma i cui millesimi sono sottototali. Da qui due obblighi simmetrici: il modello tiene i propri totali fuori dall'area dati, e **l'importatore deve riconoscere e scartare quelle righe quando arrivano lo stesso** — perché arriveranno.

**Una colonna, un dato.** Nessuna colonna che contenga «nome e interno», nessuna che cambi significato a metà.

## 4. I totali di controllo

Ogni foglio chiude con una riga `# TOTALE DI CONTROLLO`, e l'import **rifiuta il foglio se non torna**. Sui saldi la regola esiste già (§11); qui si estende a unità, persone, ogni colonna delle tabelle e capitoli.

Il motivo è specifico del copia-incolla. Il rischio numero uno non è il dato sbagliato: è **la riga persa nella selezione**, che è silenziosa, plausibile e non si vede mai — un elenco di 24 unità invece di 25 sembra un elenco di 24 unità. Il totale di controllo è l'unica difesa, e va scritto dall'amministratore prima di incollare, non calcolato da noi dopo.

È lo stesso principio del §17.5: il numero di verifica sta **dentro** il file che l'amministratore carica, e un controllo scala dove un servizio umano non scala.

## 5. Il totale di riferimento, e perché deve poter restare vuoto

Il Validatore Coerenza Millesimi (v1.10) ha già deciso la cosa giusta: mai contro 1000 fisso, sempre contro il riferimento dichiarato per quella tabella. Questa proposta aggiunge una possibilità che quel design non nomina — **il riferimento assente**.

Convenzioni osservate sui file esaminati:

| somma | significato | riferimento |
| :--- | :--- | :--- |
| 1000,00 | tabella generale che chiude | 1000 |
| 999,96 | tabella generale con deficit sparso su valori a due decimali | 1000, con avviso |
| 2000 | tabella parziale normalizzata a 1000 **per scala** | 2000 |
| numero di persone | acqua ripartita per occupanti | il totale degli occupanti |
| 289 · 244 · 506 | **pesi grezzi**: nessun totale, solo proporzioni | **nessuno** |

L'ultima riga è il caso nuovo: non è una tabella che *dovrebbe* fare 1000 e non ci arriva, è una tabella che non ha un totale, e per la quale qualunque scarto segnalato è rumore.

> ⚠️ **Conseguenza sulla migrazione della 1.10, mentre è ancora da scrivere.** La colonna `totale_riferimento` su `tabelle` dovrebbe nascere **nullable**, con `NULL` = pesi relativi. Costa zero adesso. Se nasce `NOT NULL DEFAULT 1000`, la v1.11 — che prevede già le «tabelle manuali con quote relative» — dovrà cambiarla con una seconda migrazione su una tabella viva, che è esattamente ciò che il criterio di ordinamento della roadmap cerca di evitare.

**Il riferimento va proposto, non preimpostato.** Un default a 1000 sarebbe sbagliato in quattro casi su cinque. La regola di proposta si scrive quasi da sola: somma vicinissima a un tondo → proponi il tondo e mostra lo scarto; somma tonda multipla con sottototali per scala → proponi il multiplo; somma senza struttura riconoscibile → non proporre niente e chiedi. È il pattern dell'anteprima: il sistema propone, l'amministratore smentisce.

**Base di evidenza, dichiarata:** i file esaminati provengono tutti dalla scrivania di **un solo amministratore**. Bastano a stabilire che queste cinque convenzioni esistono; non dicono nulla sulla loro frequenza nel mercato.

## 6. Il capitolo negativo — decisione da prendere

La chiusura usata per la verifica ha un capitolo **negativo**: acqua, −€ 439,54, un credito verso il fornitore portato in detrazione delle spese dell'esercizio. Non è una stranezza: è come si contabilizza una partita che ridurrà una spesa già stanziata.

Il motore non sa esprimerlo. [`CalcoloQuoteService.php:583`](../app/Services/CalcoloQuoteService.php):

```php
$importoConto = in_array($tipo, ['spesa', 'uscita'])
    ? abs($importoLordo)
    : -abs($importoLordo);
```

Il segno dell'importo viene scartato: decide solo il **tipo** del conto. Un capitolo negativo su un conto di tipo `spesa` viene quindi **addebitato** ai condòmini invece che accreditato, e l'errore vale il doppio dell'importo. Nella verifica del §8 questo ha prodotto € 12.158,19 distribuiti invece di € 11.279,11 — scarto € 879,08, cioè 2 × € 439,54.

Il disegno è coerente con sé stesso — il tipo porta il segno, `importo` è un modulo — e l'`abs()` è voluto, non un lapsus. Restano però due problemi:

1. **un capitolo che riduce le spese non ha rappresentazione**;
2. se arriva lo stesso viene ribaltato **in silenzio**. La validazione non lo ferma: `CreateContoRequest` e `UpdateContoRequest` dichiarano `'importo' => 'required|string'`, senza vincolo di segno. *(Se la maschera a video permetta di digitare il meno non è stato verificato: quanto sopra riguarda motore e regole di validazione.)*

**Decisione per il foglio 4:** rifiutare gli importi negativi con un messaggio che dice **come** esprimere quel caso, invece di accettarli. Compensare in silenzio è precisamente ciò che fa un foglio di calcolo, ed è come si finisce con una riga «arrotondamenti» in fondo a un rendiconto.

Resta aperto **dove** vada rappresentato un credito che riduce un capitolo — se come conto di tipo diverso, come nota di credito, o come rettifica. Va deciso prima di disegnare il foglio, perché il messaggio di rifiuto deve poter indicare una strada.

## 7. Il buco: i capitoli non hanno dove atterrare

Il §20 dice che «il template Excel compilabile a mano è la serializzazione human-facing dello stesso canonico». **Oggi non è vero per il foglio che serve di più.**

Verificato sul codice l'08/08/2026:

- `ReportType::BilancioConsuntivo` esiste e dichiara di produrre «struttura dei capitoli di spesa» — [`ReportType.php`](../app/Services/Import/ReportType.php);
- **non esiste** `BilancioConsuntivoParser`: in `Services/Import/Parser/` ce ne sono quattro, e quello non c'è;
- **non esiste** un livello dei capitoli: in `Services/Import/Livelli/` ce ne sono sette, nessuno dei quali scrive conti.

È coerente con la linea di taglio della 1.10, che si ferma a titolarità, tabelle e saldi. Ma significa che il canonico **non arriva ai capitoli**, e quindi che il foglio 4 chiede all'amministratore un elenco che nessun committer sa scrivere.

### ✅ Non è più una biforcazione — aggiornato il 23/08/2026

Fino a oggi questo paragrafo diceva: «*va deciso prima di disegnare i fogli: o si estende il canonico con un livello dei capitoli, o il modello promette qualcosa che non ha destinazione*». Era formulato come una **scelta**, e per questo il §9 lo metteva al primo posto con la nota «blocca il resto».

**La scelta non c'è.** Guardando il file `Bilancio consuntivo per conto` di un export vero — mandato da un amministratore a giugno 2026, lo stesso su cui è nato il `DaneaDriver` — risulta che non è un foglio che l'amministratore si costruisce: è **una stampa standard del programma**, con la stessa testata delle altre (denominazione, esercizio, periodo) e una forma a due livelli:

| dove | cosa |
| :--- | :--- |
| colonna A | il **capitolo** (es. una voce di primo livello), su una riga sua |
| colonna B | i **sottoconti** del capitolo, uno per riga |
| colonna C | l'**importo** del sottoconto |
| colonna D | il **totale** del capitolo |

Cioè esattamente la forma che serve al livello mancante, in un tracciato fisso e riconoscibile dalla testata — lo stesso appiglio che usa già `RipartoConsuntivoParser`.

**Quindi la strada è una sola e non è una decisione: è lavoro.** Si estende il canonico con un livello dei capitoli e si scrive `BilancioConsuntivoParser`, della stessa famiglia dei quattro che esistono. Il foglio 4 del modello manuale diventa la serializzazione di quel canonico, come il §20 promette per tutti gli altri.

**Cosa resta davvero da decidere** (e sono cose più piccole): come rappresentare un capitolo negativo (§6), e cosa fare delle righe di totale in mezzo ai dati (§3). Nessuna delle due blocca il disegno dei fogli.

*Nota di metodo: questa correzione è arrivata perché un amministratore ha sbattuto contro il buco — dopo l'importazione si è dovuto creare il preventivo a mano — e la verifica è stata guardare **la struttura** del suo file, non i suoi dati. Vedi la regola sul mascheramento in [`flusso_di_lavoro_rilascio.md`](flusso_di_lavoro_rilascio.md).*

## 8. La verifica

Il metodo, perché la proposta valga come misura e non come opinione.

Una chiusura d'esercizio reale — 25 unità su 4 palazzine, tabella generale a 999,96, tre fatture a consumo, addebiti personali, gestione straordinaria pluriennale — è stata serializzata nei cinque fogli. Poi un banco di prova indipendente ha letto **soltanto i cinque file**, senza accesso alla sorgente, e ha ricostruito la chiusura chiamando `GeneratePianoRateAction` e `RipartoTabelleService` su sqlite in memoria.

Esito:

| | |
| :--- | ---: |
| totali di controllo verificati | 9 su 9 |
| da ripartire, dal foglio 4 | € 11.279,11 |
| distribuito ai condòmini | € 11.279,11 |
| gran totale della matrice di stampa | € 11.279,11 |
| conguaglio (dovuto − versato) | € 4.841,12 |

Le stesse cifre a cui si arriva per una strada completamente diversa, partendo dai prospetti originali. **I cinque fogli bastano.**

Due risultati collaterali, entrambi utili:

- la tabella generale con riferimento dichiarato 1000 e somma 999,96 ha prodotto un **avviso e non un blocco**, che è il comportamento prescritto dal Validatore — esercitato qui per la prima volta su dati veri, e su un caso in cui il blocco avrebbe fermato un riparto **matematicamente corretto** (il motore normalizza sul totale effettivo e distribuisce comunque il 100%);
- le tabelle a consumo, con riferimento vuoto, non hanno prodotto rumore. È la conferma pratica che il campo deve essere nullable.

Il capitolo negativo del §6 è emerso proprio qui: al primo giro la ricostruzione divergeva di € 879,08.

## 9. Cosa resta aperto

1. ~~**Il livello dei capitoli** (§7) — estendere il canonico, o ridisegnare il foglio 4. Blocca il resto.~~ **Non è più una scelta, dal 23/08/2026** (§7): il `Bilancio consuntivo per conto` è una stampa standard con un tracciato fisso a due livelli, quindi si estende il canonico e si scrive il parser. Resta da fare, non da decidere — e non blocca il disegno dei fogli.
2. **La rappresentazione di un capitolo negativo** (§6) — serve una strada da indicare nel messaggio di rifiuto.
3. **`totale_riferimento` nullable** (§5) — da decidere prima che la migrazione della 1.10 venga scritta, non dopo.
4. **Il formato di consegna** — `.xlsx` con i fogli in un unico file, o cinque file in uno ZIP. Il §8 prevede lo ZIP per livello; con cinque fogli un file solo è probabilmente meglio, ma va provato su un amministratore vero prima di sceglierlo.
5. **Le righe di totale in mezzo ai dati** (§3) — il riconoscimento e lo scarto vanno specificati, non lasciati al buon senso del parser.

---

## Riferimenti

- [`import_migrazione_dati.md`](import_migrazione_dati.md) — §8 canonico e template, §11 quadratura dei saldi, §16.2.2 linea di taglio, §17.5 il numero di verifica dentro la stampa, §20 la scala della migrazione
- [`note_tecniche_e_decisioni.md`](note_tecniche_e_decisioni.md) — Validatore Coerenza Millesimi, cinque fronti e totale di riferimento
- [`roadmap.md`](roadmap.md) — v1.10 (guardia del Validatore) e v1.10.1 (template manuale)
- `app/Services/Import/` — livelli, parser e classi canoniche esistenti
