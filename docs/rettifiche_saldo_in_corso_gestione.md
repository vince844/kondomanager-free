# Rettifiche di saldo in corso di gestione — nota per la roadmap

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

La tabella sopra è, in sostanza, la domanda a cui questo documento prova a rispondere:
**cosa succede se serve inserire una correzione dopo che il lucchetto principale è già
scattato, per un motivo che non ha nulla a che fare con l'apertura originaria?**

---

## Una proposta, da validare

Non una soluzione definitiva — un punto di partenza per quando la roadmap arriverà a
toccare questo tema (probabilmente in coda a v1.11 Recupero Crediti, o come apripista di
v1.17 Year End Master, vedi sezione Collocazione).

`v1.10_rateazione_origine.md` ha già introdotto la distinzione tra origini
**contabilizzanti** (`preventivo`, `straordinario`, `fondo` — generano scrittura di
emissione) e **di sola pianificazione** (`saldo_iniziale`, `conguaglio` — il credito esiste
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

Non ancora assegnata a una versione. Candidati naturali:

- **v1.11 — Recupero Crediti**: già tratta scoperti pregressi e situazioni di correzione
  post-hoc (vedi `scoperto-quote-senza-destinatario.md`); una rettifica manuale è
  concettualmente vicina.
- **v1.17 — Year End Master**: se si decide di introdurre `rettifica` insieme a
  `conguaglio` come parte dello stesso lavoro sul ciclo di chiusura/apertura, per
  coerenza di design (stesso sviluppatore, stesso contesto mentale, un solo giro di
  migrazione sull'enum `OrigineQuota`).

Da decidere in fase di pianificazione, non qui.

---

## Riferimenti

- Il caso concreto che ha originato questa nota (dettagli del forum, non riportati qui
  per riservatezza) è nella cronologia di supporto — chiedere a Vincenzo se serve
  ricostruirlo.
- `app/Services/Gestionale/SaldoEsercizioService.php` — implementazione attuale del lock.
- `app/Actions/PianoRate/GeneratePianoRateAction.php` righe 173-187 — punto in cui il lock
  decide se includere o meno la Rata 0 saldi, senza mai bloccare la generazione del piano.
