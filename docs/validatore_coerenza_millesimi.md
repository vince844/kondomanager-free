# Validatore Coerenza Millesimi — la guardia

<!-- verifica-documentazione -->
> ## ⛔ RIDIMENSIONATO L'11/08/2026 — della beta.48 esce **solo il totale a video**
>
> **Decisione di Vincenzo, e la motivazione vale più della decisione.** La domanda era: *«sei
> sicuro che non ci stiamo complicando la vita? Non ho visto mai nessun gestionale fare
> questo»*. È corretta, e la parte che regge peggio è **il blocco all'emissione**, per tre
> ragioni che questo stesso documento aveva già in pancia senza trarne la conseguenza:
>
> 1. **Il motore non perde soldi.** Normalizza sulla somma reale (§1), quindi una tabella a 900
>    ripartisce comunque il 100% della spesa. Il difetto è un *riparto diverso da quello
>    approvato*, grave ma non un buco di cassa. Bloccare l'emissione per questo contraddice il
>    principio del progetto: *«dove la legge concede una facoltà, la scelta è
>    dell'amministratore»* — e le tabelle le approva l'assemblea, non il gestionale.
> 2. **Un riferimento che si può lasciare vuoto verrà lasciato vuoto.** Se dichiararlo espone a
>    un blocco e non dichiararlo no, la scelta razionale è non dichiararlo: si sarebbe costruito
>    un apparato che nessuno accende.
> 3. **I dati veri lo dicevano già** (§2): gli export di Danea contengono tabelle da 1550, 1100,2
>    e 16. Nemmeno il gestionale di provenienza pretendeva il 1000.
>
> **Cosa è stato costruito e resta in produzione:** il totale dei millesimi a video in
> `QuoteList.vue`, che somma le righe mentre si digita e **non giudica** — nessun confronto con
> 1000, nessun colore, nessuno scarto. Sette test in `QuoteList.test.ts`, metà dei quali fissano
> ciò che la pagina *non* deve dire.
>
> **Cosa è stato costruito e poi rimosso**, con il rollback della migrazione sul database
> condiviso: la colonna `tabelle.totale_riferimento` e il suo backfill, `CoerenzaMillesimiService`
> con `StatoCoerenzaMillesimi`, `Tabella::sommaValoriRiparto()`, il campo nel form della tabella e
> i quindici test relativi. Erano verdi e funzionanti — la ragione della rimozione non è tecnica.
>
> **Cosa NON è stato toccato dal ridimensionamento**, perché sono correzioni di difetto e non
> parte del Validatore: i tre scarti silenziosi (§7), il badge disallineato sul saldo solidale e
> il ÷100 sugli scoperti (§7.3-bis). Restano nella beta.48.
>
> **Il resto di questo documento resta valido come progetto**, e l'analisi che contiene è la
> parte che vale: la scoperta che il motore si auto-normalizza (§1), il conteggio delle quindici
> tabelle reali (§2) e la separazione fra guardia e diagnosi (§4 e §6). Se un giorno un
> amministratore chiede il controllo, si riparte da qui invece che da capo — e la prima domanda
> da rifargli è quella di Vincenzo.

<!-- verifica-documentazione -->
> **Stato: NON IMPLEMENTATO — progetto del 10/08/2026, ridimensionato l'11/08/2026.**
> Niente di quanto segue esiste in codice: `tabelle` ha 18 colonne e `totale_riferimento` non è
> fra queste (verificato con `Schema::getColumnListing`). È l'**ultima migrazione che la 1.10
> deve**.
> Le sezioni «Cosa fa il motore oggi» e «I dati veri» descrivono invece codice e database
> **esistenti**, verificati il 10/08/2026 su 1.10.0-beta.47, e sono la base su cui il progetto
> poggia: se cambiano, il progetto va rifatto.
> La specifica precedente viveva **in due posti**: `roadmap.md` (righe «Perché il Validatore entra
> nella 1.10» e Traccia B), che dice quattro cose in due frasi, e soprattutto
> [`note_tecniche_e_decisioni.md:260`](note_tecniche_e_decisioni.md) — «Validatore Coerenza
> Millesimi, fail-fast multilivello (Tier 1)», del 26/07/2026, che è il ragionamento vero: cinque
> fronti, i casi reali del 997 e del 1001, e la separazione **«due controlli distinti, da non
> fondere in uno»** che questo documento riprende ai §4 e §6. Dove le due fonti divergono, vale
> quella e non questa — tranne dove il codice le smentisce entrambe, e allora è detto.
>
> **Scope approvato da Vincenzo il 10/08/2026** per la beta.48: i §4-§7 per intero, la diagnosi
> nella forma leggera del §6, i tre scarti silenziosi del §7. Fuori: la griglia massiva e l'import
> Excel (Fronte 4 → Iniziativa A, v1.11) e il widget di dashboard (Fronte 2 → dopo).
<!-- /verifica-documentazione -->

---

## 1. Cosa fa il motore oggi, e perché cambia la domanda

`CalcoloQuoteService::distribuisciSuTabelle` **normalizza sulla somma reale**, non su mille:

```php
$sommaValori    = (float) $quote->sum('valore');          // :667
$weightImmobile = $weightCoeff * ($valore / $sommaValori); // :688
```

Ne segue il fatto che decide tutto il resto del documento: **una tabella che somma a 900 invece
che a 1000 distribuisce comunque il 100% della spesa.** Non si perde un centesimo, la partita
doppia quadra, il totale incassato è esatto, e nessun controllo contabile esistente ha niente da
segnalare.

Quindi la guardia **non serve a evitare che spariscano dei soldi**. La prima stesura della voce in
roadmap lasciava credere il contrario ed è la ragione per cui valeva la pena aprirla.

### Il difetto vero: la normalizzazione nasconde l'errore perfettamente

Un'unità che manca dalla tabella — mai inserita, o cancellata dopo — fa scendere la somma. Il
motore rinormalizza, e succede questo:

| | Prima | Dopo la sparizione dell'int. 4 (100 millesimi) |
| :--- | ---: | ---: |
| somma della tabella | 1000 | 900 |
| spesa da ripartire | € 9.000,00 | € 9.000,00 |
| quota di chi ha 100 mill. | € 900,00 | **€ 1.000,00** |
| quota dell'int. 4 | € 900,00 | **€ 0,00** |
| totale addebitato | € 9.000,00 | € 9.000,00 |

Il totale non si muove. Gli altri pagano al posto suo, ciascuno l'11% in più, e **l'unico segnale
possibile è che la somma della tabella non è più quella dichiarata**. Non esiste altro posto da
cui accorgersene: non la cassa, non il giornale, non il bilancio.

È la stessa forma dei difetti di questo ciclo — la beta.44 (due risposte alla stessa domanda), la
beta.45 (una guardia in un verso solo) — ma con un aggravante suo: qui **non c'è nessuna guardia
da correggere**, perché il dato contro cui confrontare non esiste. Va creato, ed è la colonna.

---

## 2. I dati veri, che smontano la soluzione ovvia

La soluzione ovvia è confrontare con 1000. Sul database di sviluppo, al 10/08/2026, ci sono **15
tabelle** e questa è la loro somma reale:

| Condominio | Tabella | Partecipanti | Somma |
| :--- | :--- | ---: | ---: |
| Via roma, Condominio test, Demo KM, Demo Foto | 5 tabelle | 1-3 | 1000 |
| Fixture | idraulico | 4 | 1000 |
| Fixture | AMMINISTRAZIONE / ASSICURAZIONE / MANUT. ORDINARIA | 6 | **424,02** |
| Fixture | ASCENSORE E SCALE | 6 | **437,66** |
| Fixture | Cassete postali | 6 | **6** |
| **Le Terrazze** | ASCENSORE | 6 | 1000 |
| **Le Terrazze** | PROPRIETA GENERALE | 16 | **1100,2** |
| **Le Terrazze** | SCALE | 13 | **1550** |
| **Le Terrazze** | CASSETTE POSTALI | 16 | **16** |

**Nove tabelle su quindici non sommano a 1000**, e non sono rotte:

- «Cassette postali» vale **1 per unità**: è una ripartizione a parti uguali, scritta con `quota =
  millesimi` perché è l'unità di misura predefinita. Corretta.
- «SCALE» somma a 1550 su 13 unità: è la tabella di un amministratore vero, così com'era nel suo
  gestionale precedente. Il motore la normalizza e il riparto esce giusto.
- Le tre da 424,02 sono tabelle **parziali** normalizzate su una scala diversa da 1000.

Quattro di queste sono di **«Le Terrazze», il condominio importato con la beta.47**. Un blocco
contro 1000 renderebbe il prodotto inusabile al primo amministratore che abbiamo fatto entrare,
sulla funzione di punta della release, il giorno stesso.

> **Il criterio della roadmap era già giusto** — *«mai contro 1000 fisso, sempre contro il
> riferimento dichiarato per quella tabella»* — ma la sua motivazione era diversa e più debole:
> `tabelle.quota` ammette `persone`, `kwatt`, `mtcubi`, `quote`, e per quelle 1000 non significa
> niente. I dati dicono che il problema si presenta **anche dentro `millesimi`**, che è il caso
> che sembrava sicuro.

### La parola esiste già, e non va inventata una seconda

L'importatore della beta.47 ragiona già su queste due forme e ha un vocabolario in catalogo:

- `tabella.parziale` — *«Una tabella parziale è un modo normale di ripartire: solo tu sai se
  quelle unità dovevano parteciparvi»*
- `tabella.parti_uguali` — *«Ripartire in parti uguali può essere voluto: nessuna query può
  distinguerlo da un millesimo sbagliato»*

E `CanonicalTabella` porta scritta la dottrina: **l'assenza non è zero**, e una tabella parziale è
normalizzata sui suoi partecipanti. La guardia deve **riusare** questi concetti, non coniarne di
paralleli. È la prima lezione della beta.47, applicata subito.

---

## 3. La colonna

```php
$table->decimal('totale_riferimento', 12, 5)->nullable()->after('numero_decimali');
```

- **Stessa precisione di `quote_tabella.valore`** (`decimal(12,5)`), così una somma di valori è
  rappresentabile esatta e il confronto non introduce un errore proprio.
- **Nullable, e senza `default`.** «Non dichiarato» è uno stato reale e deve restare distinguibile
  da «dichiarato 1000». Un `default(1000)` renderebbe le due cose la stessa cosa per sempre, e il
  giorno dopo nessuno saprebbe più quali riferimenti ha scelto un umano.

### Il backfill: la somma attuale, non 1000

```
UPDATE tabelle SET totale_riferimento = (somma dei valori delle sue quote)
WHERE totale_riferimento IS NULL
```

arrotondata a `numero_decimali`, **una riga di log per tabella** (id, nome, quota, partecipanti,
somma scritta) come prescrive `CLAUDE.md` per le migrazioni che toccano dati esistenti.

**Perché la somma attuale.** Dichiara *«quello che hai oggi è il tuo riferimento»* e trasforma la
guardia in un **rilevatore di scostamento**, che è esattamente il difetto che la
normalizzazione nasconde. Costo di questa scelta: zero falsi allarmi il primo giorno, nessuna
installazione si trova bloccata su un'operazione che ieri funzionava.

**Quello che il backfill NON fa, e va scritto dentro la migrazione** perché al prossimo giro
qualcuno non la «migliori»: non accorge una tabella già sbagliata oggi. Se all'int. 4 manca il
millesimo da prima dell'aggiornamento, il backfill scrive 900 e benedice l'errore. Quel caso è
lavoro della diagnosi (§6), non della guardia — e sono due mestieri diversi, uno meccanico e uno
di giudizio.

### Le tabelle nuove, invece, nascono con 1000

`note_tecniche_e_decisioni.md` prescrive *«default 1000 per la generale, ma
impostabile/confermabile su 997, 1001, 1002…»*. **Non è in contrasto con il backfill: sono due
domande diverse**, e il documento del 26/07 non le separava perché il backfill non era ancora un
problema.

- **Tabella nuova, `quota = millesimi`:** il campo nasce a 1000, modificabile. C'è un umano che la
  sta creando e che può smentirlo subito.
- **Tabella nuova, `quota` diversa:** nasce vuoto. Per `persone`, `kwatt`, `mtcubi` non esiste un
  valore convenzionale, e proporne uno sarebbe inventare.
- **Tabella esistente al momento della migrazione:** la somma attuale. Non c'è nessuno a cui
  chiedere, e scrivere 1000 su «SCALE» di Le Terrazze — che somma a 1550 ed è corretta —
  significherebbe dichiarare uno scarto di 550 su una tabella sana.

**Idempotenza — il dettaglio che conta.** La guardia `WHERE totale_riferimento IS NULL` non è
formale: senza, una riesecuzione della migrazione **ribenedirebbe** una tabella nel frattempo
andata alla deriva, cancellando in silenzio la correzione dell'amministratore. Più la guardia
`hasColumn` e la riga nel dataset di `UpgradeMigrationsRerunTest`.

---

## 4. Il controllo

Un servizio solo, `CoerenzaMillesimiService`, con un metodo:

```php
public function verifica(Tabella $tabella): EsitoCoerenza
```

**Non un `bool`.** È la lezione della beta.45 — un booleano su un'operazione con più modi di
finire è un canale che perde. Gli esiti sono quattro, e **due non sono errori**:

| Esito | Significato | È un errore? |
| :--- | :--- | :--- |
| `NON_DICHIARATO` | `totale_riferimento` è `null` | **no** — silenzioso per la guardia, visibile alla diagnosi |
| `COERENTE` | \|somma − riferimento\| ≤ tolleranza | **no** |
| `SCOSTAMENTO` | oltre tolleranza; porta somma, riferimento, delta e i partecipanti | sì — è il caso che blocca |
| `TABELLA_VUOTA` | nessuna quota, o tutti i valori ≤ 0 | sì, ma con un rimedio diverso |

`TABELLA_VUOTA` è separato perché oggi il motore la salta con un `Log::warning` e un `continue`
(`:657` e `:669`): la spesa **non si distribuisce attraverso quella tabella** e nessuno lo vede.
È un fratello della coda ⑨ e va nominato, non collassato dentro «scostamento».

### L'insieme confrontato deve essere quello del motore

Il servizio **non deve scrivere una query propria**. Deve chiedere la stessa cosa che chiede
`distribuisciSuTabelle`: la somma dei `valore` di tutte le quote, senza filtro su `escluso`,
saltando i valori ≤ 0.

> ⚠️ **`escluso` è una colonna dichiarata e mai applicata.** Verificato nella beta.47 e riverificato
> oggi: compare in `$fillable` e in nessun consumatore. Finché resta così, guardia e motore
> concordano per caso. Il giorno che qualcuno la implementa nel motore e non nella guardia, le due
> metà divergono e ricomincia la beta.44.
>
> **La contromisura costa una riga adesso:** un metodo `Tabella::sommaValoriRiparto()` chiamato
> *sia* dalla guardia *sia* da `distribuisciSuTabelle`, al posto del `$quote->sum('valore')`
> attuale. Chi un giorno implementerà `escluso` lo farà in un posto solo e non potrà sbagliare.

### La tolleranza

**Una unità dell'ultimo decimale dichiarato** — `10^(-numero_decimali)` — e **non** scalata sul
numero di partecipanti.

Il motivo: i valori li digita l'amministratore e devono tornare per costruzione, quindi in teoria
la tolleranza sarebbe zero; serve solo ad assorbire i valori *calcolati* (importati, o derivati
dalle superfici) arrotondati per unità. Una tolleranza che cresce con i partecipanti si mangerebbe
un errore di battitura da mezzo millesimo su una tabella di sedici unità, che è precisamente il
caso da prendere.

⚠️ **È l'unico numero di questo progetto che non deriva da un fatto verificato.** Va provato su un
caso reale prima del rilascio e, se si rivela stretto, corretto qui insieme al perché.

---

## 5. Dove gira, e cosa blocca

Due punti, **un servizio solo**. La regola non può avere due implementazioni: è la beta.44.

### 5.1 Alla generazione — avvisa, non blocca

In `GeneratePianoRateAction`, accanto agli scoperti. L'esito viaggia con il piano e la schermata
nomina le tabelle in scostamento, con il delta e il collegamento alla tabella.

**Perché non blocca qui.** Alla generazione il piano non l'ha ancora visto nessuno e la correzione
costa poco; un secondo dialogo bloccante accanto a quello degli scoperti renderebbe la generazione
ostile senza aggiungere protezione — la protezione vera è al passo dopo. Ma l'avviso deve essere
**eseguibile**: nomina la tabella, dice di quanto, e ci porta. Un avviso che dice solo «attenzione»
è la diagnosi senza cura della beta.45.

### 5.2 All'emissione — blocca

In `EmissioneRateController::store`, **dopo** il controllo su `StatoPianoRate::APPROVATO` e prima
della transazione. È il punto che la roadmap indica ed è quello giusto: l'emissione trasforma le
quote in debiti verso i condòmini.

**Quali tabelle si controllano: solo quelle che quel piano usa davvero** — le tabelle collegate ai
conti dei capitoli del piano, non tutte quelle del condominio. Bloccare un'emissione per una
tabella che non partecipa sarebbe un blocco senza causa, e insegnerebbe a forzare per abitudine.

### 5.3 La forzatura: un evento, non una colonna

Il precedente sono gli scoperti: `piani_rate.nota_scoperti` **più** un task in inbox
(`EventoTipo::SCOPERTO_DOCUMENTATO`). Qui propongo **solo la seconda metà**, con un case nuovo:

```php
case COERENZA_MILLESIMI_FORZATA = 'coerenza_millesimi_forzata';
```

Nessuna migrazione: `eventi.tipo` è `varchar(60)` e `meta` è `json` — verificato.

Tre ragioni, in ordine di peso:

1. **La nota appartiene all'atto di emissione, non al piano.** Si emette per `rate_ids`, quindi più
   volte sullo stesso piano: una colonna sul piano non saprebbe dire *quale* emissione è stata
   forzata. È l'argomento che decide.
2. Un evento porta **chi, quando, perché e il contesto** nativamente; una colonna di testo porta
   solo il testo — tanto che il precedente degli scoperti ha dovuto affiancarle comunque un task.
3. La 1.10 deve aggiungere il minor numero possibile di colonne su tabelle vive.
   `totale_riferimento` è necessaria perché è **un dato**; la forzatura è **un fatto**, e i fatti
   qui hanno già una casa.

La nota è **obbligatoria** e con lunghezza minima, come per gli scoperti. Il testo dell'evento deve
dire cosa è stato forzato e di quanto, non «l'amministratore ha proceduto».

---

## 6. La diagnosi: senza, la guardia benedice gli errori di oggi

Il backfill scrive come riferimento la somma attuale. Se quella somma è già sbagliata, la guardia
tacerà per sempre. Serve quindi un secondo pezzo, **non bloccante**, nella pagina delle tabelle:
segnala le tabelle il cui riferimento **backfillato** merita un'occhiata.

Il criterio non è «diverso da 1000» — i dati del §2 lo escludono. È:

- `quota = millesimi` **e** riferimento diverso da 1000 **e** la tabella non è parziale né a parti
  uguali secondo i criteri che l'importatore usa già.

Le due forme che l'importatore sa già riconoscere si dichiarano come tali e **non** si segnalano,
con le stesse parole del catalogo: «è una tabella parziale», «è una ripartizione a parti uguali».

L'azione offerta è una sola e non è «correggi i millesimi»: è **dichiarare il riferimento giusto**.
Se l'amministratore porta il riferimento a 1000 su una tabella che somma a 900, da quel momento la
guardia parla — e gli dice quale unità manca.

> Questo pezzo è ciò che rende la guardia utile alle installazioni **esistenti** invece che solo a
> quelle future. Senza, la beta.48 consegna un meccanismo che protegge da domani e non dice niente
> su ieri — e «ieri» sono tutti i dati che gli utenti hanno adesso.

### 6.1 Il totale a video mentre si digita — la superficie che conta di più

*Proposta di Vincenzo, 10/08/2026, ed è la collocazione giusta della diagnosi.*

`QuoteList.vue` — la pagina dove si assegnano gli immobili a una tabella — **non ha nessun totale**:
verificato oggi con `grep`, zero occorrenze di `totale`, `somma` o `reduce`. È editing riga per
riga, esattamente come l'audit del 26/07/2026 aveva rilevato, e da allora non è cambiato.

Va aggiunto lì un **totale corrente con lo scarto rispetto al riferimento**, nella forma che
`note_tecniche_e_decisioni.md` chiama già «precursore leggero anticipabile»:

```
Totale: 998 / 1000  —  mancano 2
Totale: 1003 / 1000 —  eccesso di 3
Totale: 1550 / 1550 —  quadra
```

**Perché vale più della diagnosi in elenco.** L'elenco dice che una tabella è incoerente *dopo*;
questo lo dice **nel momento in cui il refuso viene battuto**, che è l'unico momento in cui
correggerlo non costa niente. La diagnosi del §6 resta, ma serve a un'altra domanda — «quali delle
mie tabelle guardare» — mentre questa risponde a «quello che sto scrivendo torna?».

Tre vincoli, e il primo è una trappola già pagata su questa identica pagina:

- ⚠️ **Non deve dire «deve fare 1000».** Il testo di guida di `TabelleList.vue` sosteneva che il
  sistema *«controlla in tempo reale che la somma dei millesimi di ogni tabella sia esattamente
  1000»* — falso allora e in contraddizione con la decisione di design; corretto il 26/07/2026. Un
  totale a video che riaccende quella frase riporterebbe indietro la pagina di un anno. Il
  confronto è **sempre contro il riferimento dichiarato**, e quando il riferimento è `null` si
  mostra il solo totale, senza scarto e senza giudizio.
- **Il totale si somma sulle righe a schermo, non si richiede al server.** È il valore che
  l'amministratore sta digitando, non quello salvato: deve muoversi mentre scrive, o non serve.
- **Non blocca il salvataggio.** È un indicatore, non un gate: il gate è all'emissione. Una pagina
  di data entry che rifiuta di salvare uno stato intermedio è impossibile da usare — si compila una
  tabella una riga alla volta, e per definizione le righe intermedie non quadrano.

---

## 7. I tre scarti silenziosi, e i due controlli che il Validatore doveva fare

> **Sezione riaperta il 10/08/2026 da una domanda di Vincenzo**, che ricordava il Validatore come
> un controllo *prima della creazione del piano* su due cose: che gli immobili delle tabelle
> collegate a una voce di spesa abbiano un'anagrafica, e che quelle tabelle abbiano degli immobili.
> Il ricordo era esatto, e una delle due **non è coperta da niente**.

### 7.1 «Gli immobili hanno un'anagrafica?» — coperto

Quando la cascata del ruolo si esaurisce su un immobile, `distribuisciSuTabelle` accumula il peso
in `$pesiScoperti` (`:733`), lo scoperto viene registrato con `ruolo_richiesto`, la generazione si
blocca e chiede la motivazione scritta. Esiste dalla v1.9.1, esteso dalla cascata della beta.43.
**Funziona: non va rifatto.**

### 7.2 «Le tabelle hanno degli immobili?» — non coperto, e sparisce tutto

Se una tabella collegata al capitolo non ha quote, `:661` fa `continue`. Se quella era l'unica
tabella del conto, si arriva a `:765`:

```php
if (empty($weights) && empty($pesiScoperti)) {
    Log::warning("... Importo {$importoConto} centesimi NON distribuito.
                  Causa probabile: tabelle millesimali vuote o anagrafiche mancanti.");
    return;   // ← nudo
}
```

Nessuno scoperto, nessuna eccezione, nessun blocco. **Il messaggio di log nomina esattamente le
due cause di questa sezione**: chi l'ha scritto aveva capito il problema e ha lasciato un `return`.

Stessa forma per `$sommaValori <= 0` (`:669`) e per `$coeff <= 0` (`:653`).

### 7.3 Il quadro completo

| Caso | Riga | Stato |
| :--- | :--- | :--- |
| Capitolo senza **nessuna** tabella collegata | `:621` | ✅ coperto — beta.32/33, `motivo = 'conto_senza_tabella'` |
| Capitolo con **importo forzato dal piano**, senza tabelle né sottoconti | `:569` | ❌ **coda ⑨** |
| Capitolo con tabelle, ma **tutte vuote o a somma zero** | `:765` | ❌ **trovato il 10/08/2026** |

Il terzo è raggiungibile proprio dai piani rate, e per la stessa ragione del secondo: un piano
forza sempre l'importo dei suoi capitoli, quindi entra in `distribuisciSuTabelle`, e se la tabella
collegata non ha immobili l'importo evapora. La correzione ha la **stessa forma** della coda ⑨ —
registrare lo scoperto invece di uscire — e riusa lo stesso meccanismo: si scrivono insieme.

Il motivo dello scoperto va **distinto**, perché il rimedio è diverso e la lezione della beta.45
dice di non collassare esiti con vie d'uscita diverse:

- `conto_senza_tabella` — collega una tabella al capitolo *(esiste)*
- `tabella_senza_immobili` — assegna gli immobili alla tabella *(nuovo)*

### 7.3-bis Il difetto trovato correggendo — gli scoperti si vedevano divisi per cento

*Trovato il 10/08/2026 dal primo test di `ScopertoWarning.vue`, che non ne aveva nessuno.*

`useCurrencyFormatter` ha `fromCents: true` come **default**: converte da sé. La schermata degli
scoperti chiamava `euro(scoperto.importo / 100)`, dividendo una seconda volta. Uno scoperto di
€ 24.741,60 compariva come **€ 247,42** — nella finestra che chiede all'amministratore di
accettare per iscritto un importo non ripartibile.

Preesistente, non introdotto dalla beta.48. È sopravvissuto perché la finestra si vedeva quasi
mai — e la correzione della coda ⑨ la rende **comune**, il che lo trasformava da difetto dormiente
in difetto quotidiano.

**Perimetro verificato, come chiede la lezione della beta.36** (*«prima di correggere una
duplicazione, cercarne le altre copie»*): gli altri chiamanti che pre-dividono —
`PianiRateNew.vue` ed `EstrattoContoAnagrafica.vue` — istanziano `useCurrencyFormatter({ fromCents:
false })` e sono **corretti**. Il difetto era in un file solo, su due righe.

È il gemello esatto del ×100 della beta.36: là una moltiplicazione al posto di una divisione sul
confine di uscita, qui una divisione di troppo. Stessa causa: una conversione scritta a mano
accanto a una funzione che la faceva già.

### 7.4 L'avviso preventivo viene dopo, e non è arbitrario

La roadmap chiede anche «l'avviso quando un capitolo non ha tabelle collegate», destinazione
dell'avviso della beta.33 — un badge sui capitoli del piano il cui conto ha zero tabelle, o le ha
vuote.

Va scritto **dopo** la 7.2 e la coda ⑨, non prima: finché il motore continua a ignorare quei due
casi, l'avviso segnalerebbe un problema che un secondo dopo non produce nessuna conseguenza. È la
diagnosi senza cura della beta.45, al contrario — la cura prima, poi l'avviso che evita di
arrivarci.

---

## 7-bis. La stampa del riparto è rimasta indietro — fuori scope, ma va detto

*Trovato il 10/08/2026 cercando il `/1000` cablato. Non entra nella beta.48: è un difetto suo e
merita una voce sua.*

Due cose, nello stesso punto.

**Il `/1000` cablato non esiste.** `note_tecniche_e_decisioni.md` chiudeva la sezione dicendo *«se
mai comparisse un `/1000` cablato da qualche parte, sarebbe un bug latente proprio per questi casi
— da cercare e rimuovere»*. Cercato in `CalcoloQuoteService` e in `RipartoTabelleService`: **zero
occorrenze**. Quella preoccupazione si può chiudere.

**Ma la cascata del ruolo, sì.** [`RipartoTabelleService:479`](../app/Services/RipartoTabelleService.php)
ha ancora `&& $rip->soggetto !== 'proprietario'` — la condizione che la **beta.43 ha tolto dal
motore** — e le due catene scritte a mano inline (`$catenaGodimento`, `$catenaCapitale`) invece di
`RuoloAnagraficaImmobile::catenaRiparto()`. Il commento sopra dice «cascata ruolo (identica a
CalcoloQuoteService)», e non lo è più da undici giorni.

**Effetto.** Su un'unità con nuda proprietà e usufrutto e nessun `proprietario` attivo — caso
registrabile dalla beta.43 — il motore risolve la cascata e **addebita il nudo proprietario**,
mentre la stampa entra nel ramo `$anagrafiche->isEmpty()` e la tratta come **scoperta, a zero**. Il
riparto che si porta in assemblea non coincide con quello che è stato addebitato.

È la beta.44 in forma pura (la stessa domanda con due risposte in due posti) più la prima lezione
della beta.47 (una regola di dominio riscritta invece che chiamata). La correzione è sostituire le
due catene inline con la chiamata all'enum e togliere la condizione: poche righe, ma va con il suo
test di concordanza fra motore e stampa, ed è quel test il vero lavoro.

---

## 8. Cosa NON entra

- **Modifica massiva di `totale_riferimento` da griglia** → Iniziativa A, v1.11.
- **Tipo `manuale` e le altre funzioni sulle tabelle** → Iniziativa A, v1.11. Sono comodità; questa
  è una guardia.
- **Correzione retroattiva dei piani già emessi.** La guardia agisce da qui in avanti. Un piano
  emesso su una tabella incoerente resta com'è: rifarlo è una rettifica contabile, non una
  validazione.
- **`escluso`** → si lascia dov'è; si costruisce solo il metodo condiviso che impedirà la
  divergenza il giorno in cui verrà implementata.
- **La cascata della stampa** (§7-bis) → voce propria in roadmap. Toccarla qui dentro
  significherebbe mettere mano al motore di stampa nella beta che riscrive il motore di riparto, e
  la concordanza fra i due andrebbe verificata due volte invece che una.
- **Il widget di dashboard** (Fronte 2 di `note_tecniche_e_decisioni.md`) → dopo. Il §6.1 è il
  «precursore leggero» che quel documento stesso dichiara anticipabile.

---

## 9. I test, e cosa dichiarano di non coprire

- Migrazione: idempotenza (due esecuzioni), backfill che **non** sovrascrive un riferimento già
  valorizzato, riga nel dataset di rerun.
- Servizio: i quattro esiti, uno per test, con i due «non errori» asseriti come tali.
- Tolleranza: sul confine — `10^-numero_decimali` esatto passa, un'unità oltre no.
- Emissione: blocco su scostamento reale, passaggio con forzatura e nota, **rifiuto della
  forzatura senza nota**, evento creato con il contesto giusto.
- Emissione: una tabella incoerente **che il piano non usa** non blocca. È il test che protegge
  dalla forzatura per abitudine.
- Concordanza motore/guardia: una tabella la cui somma cambia deve muovere *entrambi* — è il test
  che si accenderà il giorno in cui qualcuno implementa `escluso` in un posto solo.
- **Gli scarti silenziosi (§7), un test per ciascuno, scritti PRIMA della correzione** — nascono da
  una segnalazione, quindi vale la Fase 1 del flusso: il test che fallisce dimostra che il difetto
  c'era. Servono: capitolo con override e nessuna tabella (coda ⑨), capitolo con una tabella senza
  immobili, capitolo con una tabella a somma zero. In tutti e tre l'asserzione è la stessa e non è
  «esce un errore»: è che **la somma delle quote generate uguaglia l'importo dei capitoli**, che è
  la proprietà che oggi si rompe in silenzio.
- Il totale a video (§6.1): un test di componente su `QuoteList.vue`, con il caso `riferimento =
  null` — dove non deve comparire nessuno scarto. È la pagina che una volta prometteva un controllo
  inesistente, e il test serve a non riprometterlo.

**Cosa questi test non coprono, seconda lacuna dichiarata:** nessuno esercita la **concordanza fra
il motore e la stampa** del riparto. È il difetto del §7-bis, e resta scoperto per scelta — ma
quando quella voce verrà fatta, il test da scrivere è esattamente questo, ed è il motivo per cui
vale più della correzione che lo accompagna.

**Cosa questi test non coprono, dichiarato adesso che la lacuna è in mente:** nessuno esercita la
guardia su `quota` diverso da `millesimi` (`persone`, `kwatt`, `mtcubi`, `quote`). Il meccanismo è
lo stesso per costruzione — il riferimento è dichiarato, non dedotto — ma non c'è un caso a
database su cui provarlo, e finché non c'è quella riga resta un ragionamento, non una prova.
