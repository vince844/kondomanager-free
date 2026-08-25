# Rettifiche di saldo in corso di gestione — nota per la roadmap

<!-- verifica-documentazione -->
> **Stato:** In gran parte superato — verificato il 01/08/2026 su 1.10.0-beta.33
> L'analisi resta giusta e vale la pena leggerla: distingue il **saldo di apertura** (irreversibile, protegge il rendiconto) dalla **rettifica in itinere** (correzione puntuale, lock naturale per riga), ed è la tabella dei due bisogni che ha guidato la beta.33.
> **Potato il 16/08/2026 su 1.10.0-beta.55:** «Una proposta, da validare» e «Collocazione in roadmap» portano ora l'avviso in testa alla sezione. > La proposta operativa non serve più: la beta.32 ha dato un titolare al lucchetto (`saldi.piano_rate_id`) e la beta.33 ne ha spostato la soglia all'emissione e la granularità alla singola riga. Oggi una rettifica in itinere è semplicemente «aggiungi una riga di saldo, è libera, il prossimo piano la assorbe» — senza terza origine `rettifica`, senza nuovo enum. Restano invece false le premesse tecniche già segnalate nelle note ⚠️ del testo.
<!-- /verifica-documentazione -->

**Stato:** Proposta — non approvata, non pianificata su una versione specifica.
**Origine:** discussione di supporto forum (migrazione da altro gestionale, variante su lavori
straordinari già deliberati), luglio 2026.
**Documenti collegati:** [`architettura_saldi_iniziali.md`](architettura_saldi_iniziali.md) (ADR-001),
[`v1.10_rateazione_origine.md`](v1.10_rateazione_origine.md), [`roadmap.md`](roadmap.md) §v1.17 Year End Master.

---

## Il caso che ha fatto emergere il problema

Un amministratore migra un condominio su Kondomanager. Nel vecchio gestionale esiste una
gestione straordinaria per una ristrutturazione deliberata a maggio 2025, con relativo
accantonamento raccolto per millesimi. Segue la procedura di migrazione standard: crea la
gestione straordinaria, imposta il saldo della cassa Banca comprensivo dell'accantonamento
già incassato, inserisce in Saldi Iniziali i crediti/debiti dei singoli condòmini rispetto a
quell'accantonamento, genera un primo piano rate (con Rata 0 sui saldi) — piano che viene
poi effettivamente emesso e incassato.

Mesi dopo arriva la fattura definitiva dei lavori, più alta del deliberato per una variante
in corso d'opera. Per registrare correttamente lo sforamento e generare un piano rate
integrativo sulla sola differenza serve un **secondo piano rate sulla stessa gestione**.

A quel punto emerge il vincolo: `Gestione.saldo_applicato` è un lucchetto **one-shot per
l'intera gestione** (vedi `SaldoEsercizioService::calcolaSaldoApplicabile/marcaSaldoApplicato`).
Una volta che un piano rate ha applicato i saldi, nessun piano rate successivo sulla stessa
gestione può più portare una propria Rata 0 di saldi.

**Verificato che questo non blocca il secondo piano rate**: `GeneratePianoRateAction::execute()`
tratta l'applicazione saldi come un ramo indipendente (`if ($applicare) {...} else { $saldi = []; }`)
— se i saldi non sono applicabili, il piano rate si genera comunque, semplicemente senza
Rata 0. Per il caso concreto (saldi 2025 già chiusi da pagamenti reali) questo è corretto:
non c'è nulla da riaprire.

<!-- rettifica -->
> ⚠️ **Il paragrafo qui sotto è stato superato — verificato il 01/08/2026 su 1.10.0-beta.33.** «Oggi non esiste una via intermedia» era vero quando è stato scritto: la beta.33 quella via l'ha costruita. La correzione si registra come una normale riga di saldo sulla stessa gestione, senza riaprire nulla e senza gestioni parallele. Dettaglio e prove nella rettifica sotto la tabella dei due bisogni.
<!-- /rettifica -->

Il problema reale è un altro, più stretto: **cosa succede se, invece, ci fosse davvero
bisogno di registrare una correzione — un subentro scoperto tardi, un errore trovato dopo —
quando il lucchetto della gestione è già scattato?** Oggi non esiste una via intermedia:
o si riapre l'intera gestione (rischio di doppia applicazione dei saldi già corretti), o si
apre una gestione parallela solo per quello (in contrasto con la logica "Carrello della
Spesa" — un bilancio unico, molteplici piani rate selettivi — già documentata e comunicata
agli utenti).

---

## Perché il lock attuale non è un bug

Vale la pena ribadirlo esplicitamente, perché la tentazione naturale è "allentare" il
lucchetto: **non va allentato**. `v1.10_rateazione_origine.md` §2 lo dice in modo diretto:

> Questo è il punto che evita il bug peggiore: **il saldo iniziale non si ri-emette.**
> Lo stabilisce la scrittura di apertura; spalmarlo su `r1..rn` è *scheduling*, non una
> nuova scrittura.

Un saldo di apertura rappresenta un fatto contabile che accade una volta sola, prima che
qualunque rata parta. `ADR-001` §5 lo lega esplicitamente all'art. 1130-bis c.c.
(separazione dei fondi): il saldo appartiene a quella specifica gestione, punto. Riaprirlo
per una gestione già chiusa da pagamenti reali rischierebbe di far riapplicare due volte
una correzione già risolta — esattamente il "bug peggiore" citato sopra.

Il lock, quindi, sta facendo bene il suo lavoro per lo scopo per cui è nato: proteggere il
**saldo di apertura**. Il problema è che oggi è l'unico strumento disponibile, ed è chiamato
a coprire anche un bisogno diverso.

---

## I due bisogni che oggi condividono un solo meccanismo

| | Saldo di apertura | Rettifica in itinere |
|---|---|---|
| **Quando nasce** | Una volta, prima che la gestione emetta la prima rata | In qualsiasi momento della vita della gestione |
| **Cosa rappresenta** | Il punto di partenza (migrazione, o in futuro l'output della chiusura esercizio precedente) | Una correzione puntuale emersa dopo: subentro non censito, errore scoperto, importo rivisto |
| **Deve essere irreversibile una volta confermato?** | Sì — tutto il rendiconto si costruisce sopra | Solo quella singola riga, non l'intera gestione |
| **Granularità naturale del lock** | Per gestione (`Gestione.saldo_applicato`) | Per singola riga/anagrafica |
| **Meccanismo oggi** | `Saldo` + `saldo_applicato` (ADR-001) | *Non esiste* |

<!-- rettifica -->
> ⚠️ **La riga «Meccanismo oggi» è superata — verificato il 01/08/2026 su 1.10.0-beta.33.** Attenzione a non riportarla in roadmap: descriverebbe come *da fare* un lavoro già rilasciato.
> **Colonna «saldo di apertura»:** il meccanismo non è più `Saldo` + `saldo_applicato`. È `saldi.piano_rate_id` — il lucchetto ha un titolare — letto da `Saldo::eBloccato()`, che delega a `PianoRate::eImmutabile()`. `gestioni.saldo_applicato` sopravvive come derivato ricalcolato da `allineaFlagGestione()`, e come ultimo ripiego per i dati anteriori alla beta.32.
> **Colonna «rettifica in itinere»:** non è più *«Non esiste»*. È una normale riga di saldo aggiunta alla stessa gestione: l'inserimento non ha mai guardato il lucchetto, la riga resta modificabile ed eliminabile finché il piano che la assorbirà non viene emesso, e il blocco è **per singola riga** — esattamente la «granularità naturale» che la riga sopra chiedeva. Nessuna terza origine `rettifica`, nessun enum nuovo.
> *Prova:* app/Models/Saldo.php:82-89; app/Models/Gestionale/PianoRate.php:103-106; app/Http/Controllers/Gestionale/Saldi/SaldoInizialeController.php:61-87 (`store()` non consulta il lucchetto) e :110-146 (`sblocca()` per i lucchetti orfani); app/Services/Gestionale/SaldoEsercizioService.php:115-139; tests/Feature/Gestionale/SaldiSogliaGranularitaTest.php.
<!-- /rettifica -->

La tabella sopra è, in sostanza, la domanda a cui questo documento prova a rispondere:
**cosa succede se serve inserire una correzione dopo che il lucchetto principale è già
scattato, per un motivo che non ha nulla a che fare con l'apertura originaria?**

---

## Una proposta, da validare

<!-- rettifica -->
> ⛔ **La proposta non serve più — potatura del 16/08/2026.** La beta.32 ha dato un titolare al
> lucchetto (`saldi.piano_rate_id`) e la beta.33 ne ha spostato la soglia all'emissione e la
> granularità alla singola riga. Oggi una rettifica in itinere è semplicemente «aggiungi una riga
> di saldo: è libera, e il prossimo piano la assorbe» — **senza** la terza origine `rettifica`,
> **senza** il nuovo enum e senza il resto di questa sezione. Non implementarla.
>
> Quello che invece è servito, e resta la parte viva del documento, è **la distinzione fra saldo di
> apertura e rettifica in itinere** e la tabella dei due bisogni: sono state loro a guidare la
> beta.33.
<!-- /rettifica -->

Non una soluzione definitiva — un punto di partenza per quando la roadmap arriverà a
toccare questo tema (probabilmente in coda a v1.11 Recupero Crediti, o come apripista di
v1.17 Year End Master, vedi sezione Collocazione).

`v1.10_rateazione_origine.md` ha già introdotto la distinzione tra origini
**contabilizzanti** (`preventivo`, `straordinario`, `fondo` — generano scrittura di
emissione) e **di sola pianificazione** (`saldo_iniziale`, `conguaglio` — il credito esiste

<!-- rettifica -->
> ⚠️ **Non è più vero — verificato il 31/07/2026 su 1.10.0-beta.32.** L'unico enum di origine quota del progetto ha quattro casi completamente diversi e nessuno di quei valori. 'conguaglio' non è riservato in alcun enum: esiste solo come stringa libera fra i valori ammessi a commento della colonna string rate_quote.tipo, e come etichetta di strategia in un computed della dashboard. La tassonomia contabilizzanti/pianificazione, che è la premessa su cui poggia l'intera proposta, non ha riscontro in codice.
> *Prova:* app/Enums/OrigineQuota.php:7-10 — CALCOLO_AUTOMATICO='calcolo_automatico', INSERIMENTO_MANUALE='inserimento_manuale', RETTIFICA='rettifica', STORNO='storno'. Unici consumer: app/Actions/PianoRate/GenerateRateQuotesAction.php:77 e :220. 'conguaglio': database/migrations/2026_02_18_230829_add_tipo_and_esercizio_origine_to_rate_quote_table.php:20-26 (colonna string default 'ordinaria', comment «ordinaria, saldo_iniziale, conguaglio, straordinaria») e app/Http/Controllers/Gestionale/Dashboard/DashboardController.php:243 e :330.
<!-- /rettifica -->
già, l'allocazione decide solo la scadenza). La proposta è aggiungere una terza origine
di sola pianificazione:

```php
case Rettifica = 'rettifica'; // pianificazione — correzione puntuale post-apertura
```

Differenze chiave rispetto a `saldo_iniziale`:

- **Lock per riga, non per gestione.** Una rettifica, una volta inclusa in un piano rate
  emesso, si considera "consumata" — ma non tocca in alcun modo lo stato di
  `Gestione.saldo_applicato` né le altre righe. Due rettifiche sulla stessa gestione, in
  momenti diversi, non si ostacolano a vicenda, esattamente come due voci di spesa diverse
  nello stesso carrello.
- **Non richiede riapertura di nulla.** È pensata per convivere con una gestione il cui
  saldo di apertura è già stato applicato — è il caso normale d'uso, non l'eccezione.
- **Tracciata con provenienza esplicita.** Ogni rettifica porta una `descrizione`/`origine`
  testuale obbligatoria (perché non è un dato strutturale come il saldo di apertura, ma una
  correzione ad-hoc: chi l'ha causata, quando, perché — utile in assemblea e per i revisori).

Relazione con `conguaglio` (già riservata nell'enum, in attesa di Year End Master):
`conguaglio` sarà calcolata automaticamente dal motore alla chiusura esercizio;
`rettifica` resta uno strumento manuale, per correzioni puntuali che l'amministratore
scopre nel corso dell'anno, indipendente dal ciclo di chiusura. Sono complementari, non
alternative — una gestione potrebbe usarle entrambe in momenti diversi della sua vita.

---

## Cosa NON cambia

- Lo scoping del saldo di apertura per gestione (ADR-001 §5) resta corretto e non va
  toccato: rimane la base solida su cui costruire tutto il resto.
- Nessuna modifica al comportamento di `GeneratePianoRateAction` per i piani che non usano
  rettifiche: retrocompatibilità totale, stesso principio additivo di
  `v1.10_rateazione_origine.md` §10.

---

## Collocazione in roadmap

<!-- rettifica -->
> ⛔ **Superata:** il lavoro è uscito nelle beta.32 e .33, quindi non c'è niente da collocare.
<!-- /rettifica -->

Non ancora assegnata a una versione. Candidati naturali:

- **v1.11 — Recupero Crediti**: già tratta scoperti pregressi e situazioni di correzione
  post-hoc (vedi `scoperto-quote-senza-destinatario.md`); una rettifica manuale è
  concettualmente vicina.
- **v1.17 — Year End Master**: se si decide di introdurre `rettifica` insieme a
  `conguaglio` come parte dello stesso lavoro sul ciclo di chiusura/apertura, per
  coerenza di design (stesso sviluppatore, stesso contesto mentale, un solo giro di
  migrazione sull'enum `OrigineQuota`).

<!-- rettifica -->
> ⚠️ **Non è più vero — verificato il 31/07/2026 su 1.10.0-beta.32.** Un case Rettifica = 'rettifica' esiste già in OrigineQuota, ma con un significato del tutto diverso da quello proposto (provenienza del dato della quota — generata dal sistema, inserita a mano, rettificata, stornata — non origine di pianificazione). Chi implementasse la proposta alla lettera si troverebbe una collisione di nome su un enum già in uso.
> *Prova:* app/Enums/OrigineQuota.php:9 (case RETTIFICA = 'rettifica') e :18 (label 'Rettifica manuale').
<!-- /rettifica -->

Da decidere in fase di pianificazione, non qui.

---

## Riferimenti

- Il caso concreto che ha originato questa nota (dettagli del forum, non riportati qui
  per riservatezza) è nella cronologia di supporto — chiedere a Vincenzo se serve
  ricostruirlo.
- `app/Services/Gestionale/SaldoEsercizioService.php` — implementazione attuale del lock.
- `app/Actions/PianoRate/GeneratePianoRateAction.php` righe 173-187 — punto in cui il lock
  decide se includere o meno la Rata 0 saldi, senza mai bloccare la generazione del piano.

<!-- rettifiche-non-ancorate -->

## ⚠️ Rettifiche non ancorate (31/07/2026)

Correzioni verificate sul codice che non è stato possibile agganciare a una riga precisa di questo documento. Valgono per l'intero testo.

- **Il documento afferma:** Il metodo marcaSaldoApplicato esiste nel service, e il lock è per gestione.
  **Realtà:** marcaSaldoApplicato non esiste (nessun metodo con quel nome nel service). E soprattutto la premessa è superata dalla beta.32: il lucchetto ha ora un titolare per riga, saldi.piano_rate_id, che si azzera da sé alla cancellazione del piano; il flag di gestione è degradato a ultimo fallback per i piani antecedenti alla beta.32. calcolaSaldoApplicabile esiste ancora ma legge un flag che non è più il meccanismo primario.
  *Prova:* app/Services/Gestionale/SaldoEsercizioService.php — i metodi pubblici sono calcolaSaldoApplicabile (:18), sincronizzaLucchetti (:74), rilasciaLucchetti (:103), allineaFlagGestione (:119); nessun marcaSaldoApplicato. Nuovo meccanismo: database/migrations/2026_07_31_120000_add_piano_rate_id_to_saldi_table.php (colonna + nullOnDelete + riparazione dati); app/Actions/PianoRate/GeneratePianoRateAction.php:266-274 (match con $possiedeSaldi in seconda posizione e $gestione->saldo_applicato come default finale) e :308-318 (sincronizzaLucchetti a nome del piano).

- **Il documento afferma:** Il ramo di decisione sui saldi si trova alle righe 173-187 del file.
  **Realtà:** Riferimento non più valido: a quelle righe oggi c'è la gestione delle quote scoperte (throw di ScopertiNonAccettatiException e persistenza di nota_scoperti). Il ramo saldi è più in basso.
  *Prova:* app/Actions/PianoRate/GeneratePianoRateAction.php:177 (throw new ScopertiNonAccettatiException) e :182 (nota_scoperti); il ramo saldi è a :266-295.

<!-- /rettifiche-non-ancorate -->
