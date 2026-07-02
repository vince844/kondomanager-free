# Fix Eccedenza da Incasso Anticipato — Guida di Investigazione

> **Stato documento:** Investigazione pre-fix — in attesa di conferma amministratore e audit dati.
> Nessuna decisione implementativa è stata presa. Non procedere con modifiche di codice finché le sezioni 3 e 4 non sono chiuse.
> **Origine:** Ticket di supporto, condomino risultato "a credito" con rate emesse successivamente ancora `NON PAGATA`.

---

## 1. Sintomo riportato

Un condomino ha versato più di quanto dovuto in un dato momento: alcune rate non erano ancora state emesse. L'amministratore ha poi emesso le rate mancanti (es. Rata n.3/4/5 di "Manutenzione ordinaria ascensore"), ma queste risultano ancora `NON PAGATA` nell'estratto conto, nonostante il saldo totale del condomino sia a credito (verde).

## 2. Root cause confermata (analisi del codice)

Il saldo totale mostrato in estratto conto **è corretto**. Il problema è che nel sistema esistono due meccanismi di credito distinti e non comunicanti:

### 2.1 Credito da "Saldo Iniziale" (funzionante)

- Generato da `GenerateSaldiAction::execute(PianoRate $pianoRate, Gestione $gestione, ...)` (`app/Actions/PianoRate/GenerateSaldiAction.php:16`).
- Crea un vero record in `rate_quote` con `tipo = 'saldo_iniziale'` e `importo < 0`, agganciato a una "rata zero" di **una specifica `PianoRate`/`Gestione`**.
- Il bottone **"Usa credito"** in `IncassoRateNew.vue` lo trova e lo consuma tramite la sezione "SCRITTURA 2 — COMPENSAZIONE CREDITO" di `StoreIncassoRateAction` (righe 176-287).
- La query di compensazione (righe ~189-208) cerca la quota credito per `rata_id` esplicito passato dal frontend, con fallback su `tipo = 'saldo_iniziale' AND importo < 0`.

### 2.2 Eccedenza da incasso anticipato (il caso del ticket — NON riutilizzabile)

- In `StoreIncassoRateAction` (righe 160-174), quando un pagamento supera tutte le quote esistenti selezionate, il residuo (`$eccedenzaFinaleCents`) viene registrato **solo come `RigaScrittura`** sul conto `anticipi_condomini`:
  ```php
  $scritturaIncasso->righe()->create([
      'conto_contabile_id' => $contoAnticipi->id,
      'anagrafica_id'      => $validated['pagante_id'],
      'tipo_riga'          => 'avere',
      'importo'            => $eccedenzaFinaleCents,
      'note'               => 'Anticipo / Eccedenza',
  ]);
  ```
- **Non viene creato nessun record in `rate_quote`.** È un movimento di partita doppia corretto (per questo il saldo è giusto) ma privo di un "contenitore" agganciabile a una rata futura.
- Quando le nuove rate vengono emesse, nessun meccanismo esistente collega questo saldo del conto `anticipi_condomini` alle nuove `RataQuote`. Il motore di compensazione lato UI (`IncassoRateNew.vue`, righe ~129-131, 228-231) cerca solo `RataQuote` con `tipo = 'saldo_iniziale'`.

### 2.3 Vincolo strutturale importante (non emerso nell'analisi esterna ricevuta)

`rate_quote.rata_id` è **NOT NULL**, con FK verso `rate` (`database/migrations/2025_11_05_093418_create_rate_quote_table.php:16`). Qualunque `RataQuote` — incluso un ipotetico record "eccedenza" — deve appartenere a **una `Rata` di una `PianoRate`/`Gestione` specifica**. Il credito da saldo iniziale infatti non è "dell'anagrafica in generale": è dell'anagrafica **in quella gestione**.

Questo significa che una soluzione che si limiti a "creare una RataQuote negativa di tipo eccedenza" deve anche rispondere a: **a quale gestione/piano rate appartiene l'eccedenza?** Se il versamento anticipato è avvenuto nel contesto di una gestione e le nuove rate emesse appartengono a un'**altra** gestione (ipotesi plausibile: nello screenshot del ticket compaiono sia rate di "Manutenzione ordinaria ascensore" sia di "ORDINARIO 2026", che sembrano due piani rate distinti), il problema non si risolve con una singola RataQuote scoped a un piano — servirebbe o un contenitore "credito libero" cross-gestione, oppure un meccanismo di trasferimento tra piani.

### 2.4 Rischio operativo collaterale

Se i solleciti di morosità o la vista "Situazione debitoria" leggono lo stato per-rata (`NON PAGATA`) invece del saldo netto per anagrafica, un condomino in credito potrebbe ricevere un sollecito di pagamento. Da verificare separatamente (`SituazioneDebitoriaController`).

### 2.5 Ipotesi "emissione silenziosa" — verificata e in gran parte esclusa

Ipotesi iniziale: l'amministratore avrebbe dovuto emettere le rate in modalità "silenziosa" (`EmissioneRateController::store`, flag `invia_notifiche=false`) prima di registrare il pagamento, per poi pubblicarle in un secondo momento.

Verifica nel codice:

- L'endpoint che alimenta la schermata "Nuovo Incasso" (`SituazioneDebitoriaController::__invoke`) interroga `RataQuote` filtrando solo su `importo > importo_pagato OR importo < 0` e su `anagrafica_id`/`immobile_id`. **Non esiste alcun filtro su stato di emissione, `scrittura_contabile_id` o visibilità dell'evento.** Una rata già generata ma non ancora emessa (né pubblicamente né in modalità silenziosa) sarebbe comunque comparsa come debito selezionabile in "Nuovo Incasso".
- L'emissione (silenziosa o pubblica) in `EmissioneRateController::store` non crea le `RataQuote`: le trova già esistenti (`Rata::with('rateQuote')->where('piano_rate_id', ...)`) e si limita a generare la scrittura contabile in Prima Nota e a gestire la visibilità/notifica dell'evento verso il condomino.
- `GeneratePianoRateAction::execute()` genera **tutte** le rate e le relative `RataQuote` di un piano in un solo passaggio (non incrementalmente).

**Conclusione:** l'emissione silenziosa non è la leva che avrebbe risolto il caso, perché non è quello che determina se una rata è "pagabile" in Nuovo Incasso. La spiegazione più probabile, coerente con la generazione "tutto insieme" del piano, è che **il piano rate di "Manutenzione ordinaria ascensore" (o quantomeno le rate n.3/4/5) non fosse ancora stato generato/approvato affatto** al momento del versamento — non semplicemente "generato ma non emesso". In tal caso l'eccedenza era inevitabile con qualunque azione l'amministratore avesse compiuto in quel momento, perché non esisteva alcun debito a cui agganciare il pagamento.

Questo va comunque confermato con l'amministratore (vedi domanda aggiornata in §3).

### 2.6 Ipotesi alternativa — "tesoretto" cross-gestione (ordinaria → straordinaria ascensore)

Ipotesi alternativa emersa rileggendo il ticket: il condomino ha accumulato l'eccedenza pagando più del dovuto sulla gestione **ordinaria**, e l'amministratore vorrebbe usare quel credito per coprire le rate della gestione **straordinaria ascensore** (due piani rate distinti, coerente con quanto visibile nello screenshot originale: "Manutenzione ordinaria ascensore" vs "ORDINARIO 2026").

Verifica nel codice: la tabella `conti_contabili` (`database/migrations/2025_12_17_212839_create_conti_contabili_table.php`) **non ha colonna `gestione_id`** — è scoped solo per `condominio_id`. Il conto `anticipi_condomini` è quindi unico per condominio, condiviso tra tutte le gestioni. A livello di partita doppia, l'eccedenza registrata lì per un'anagrafica non è "vincolata" a nessuna gestione specifica: è già, di fatto, un credito condominio-wide.

Questo cambia la valutazione delle opzioni in §5:

- Se lo scenario cross-gestione è quello reale (e se è prassi accettabile per l'amministratore/condominio), l'**Opzione 1** (leggere il saldo del conto `anticipi_condomini` per anagrafica) è naturalmente cross-gestione senza sforzo aggiuntivo — è già come è modellato il conto.
- L'**Opzione 2** (RataQuote negativa) è invece intrinsecamente legata a una `rata_id` → un `piano_rate` → **una** gestione (vincolo NOT NULL, §2.3). Supportare il consumo cross-gestione richiederebbe una logica aggiuntiva (o più RataQuote di credito, una per gestione, difficile da tenere sincronizzate con un'unica eccedenza contabile).

**Attenzione — non è solo una questione tecnica.** Gestione ordinaria e straordinaria vengono normalmente tenute separate nel rendiconto condominiale per la trasparenza verso l'assemblea (ogni gestione straordinaria ha tipicamente una propria delibera/budget approvato). Compensare un credito dell'ordinaria contro un debito della straordinaria potrebbe essere corretto per il portafoglio del condomino, ma discutibile come prassi di rendicontazione. Va confermato con l'amministratore non solo se è questo che è successo, ma se è effettivamente ciò che vuole fare e se è una pratica contabile che adotta normalmente.

### 2.7 Scoperta collaterale — il sistema già permette di mescolare gestioni in un unico incasso (nessuna validazione)

Verifica di codice fatta per capire se il caso §2.6 fosse anche solo *possibile* oggi:

- `SituazioneDebitoriaController::__invoke` (righe 21-25) filtra i debiti solo per `condominio_id` + anagrafica/immobile — **non per gestione**.
- `usePaymentDistribution.ts::getRateListByGestione` (righe 61-66, lato frontend) filtra per gestione **solo se l'operatore seleziona esplicitamente una gestione** nel dropdown di "Nuovo Incasso". Se non la seleziona (comportamento di default), la lista mostra **le rate di tutte le gestioni mescolate insieme**, senza alcun avviso.
- `StoreIncassoRateAction::execute` (righe 59-71): nessuna validazione impedisce un payload con `rata_id` di gestioni diverse nello stesso incasso. Il `gestione_id` scritto sulla `ScritturaContabile` di testata viene preso **solo dalla prima quota selezionata** — se altre righe dello stesso incasso appartengono a un'altra gestione, quella scrittura non risulta correttamente attribuibile nei report per-gestione (rendiconto, situazione di cassa) per quell'altra gestione.

**Conseguenza per questo ticket:** questa scoperta *restringe* la spiegazione più probabile dell'eccedenza, invece di confermare l'ipotesi cross-gestione come necessaria. Se le rate n.3/4/5 fossero esistite già al momento del versamento — in qualunque gestione, ordinaria o straordinaria — sarebbero comunque comparse come selezionabili insieme a quelle dell'ordinaria nello stesso incasso, perché il sistema non le esclude mai per motivi di gestione. Quindi l'eccedenza implica quasi certamente che quelle `RataQuote` non esistessero affatto in quel momento (piano non ancora generato, §2.5) — non che esistessero ma in una gestione "irraggiungibile" dall'incasso.

**Problema indipendente da tracciare separatamente:** il mescolamento cross-gestione senza validazione né avviso è già oggi il comportamento di default, non un'ipotesi futura. Vale la pena aprire un item a parte (fuori dallo scope di questo fix) per decidere se: (a) bloccare esplicitamente il mix di gestioni diverse in un unico incasso, oppure (b) supportarlo esplicitamente correggendo l'attribuzione `gestione_id` sulla scrittura (es. una `ScritturaContabile` per gestione coinvolta, o un campo che tracci tutte le gestioni toccate).

---

## 3. Domande da porre all'amministratore (bloccanti prima di procedere)

1. **[Decisiva]** Quando hai registrato il versamento originale, il piano rate "Manutenzione ordinaria ascensore" era già stato generato/approvato (anche solo con le rate n.3/4/5 non ancora scadute o emesse), oppure lo hai generato/approvato **dopo** aver registrato il pagamento? In altre parole: nella schermata "Nuovo Incasso" di allora, comparivano già queste rate come debiti selezionabili, oppure non esistevano proprio? (Nota: verificato in §2.7 che il sistema mostra e permette di incassare insieme rate di gestioni diverse senza distinzione — quindi se le rate fossero esistite in una qualsiasi gestione sarebbero state comunque selezionabili. Questo rende quasi certa la risposta "non esistevano ancora", ma va confermata.)
2. Come hai registrato originariamente il versamento del condomino? (Nuovo Incasso → quali rate avevi selezionato all'epoca, quale importo hai inserito)
3. Il versamento è avvenuto in un'unica soluzione o in più tranche? Con quali date?
4. A prescindere da cosa sia successo: vorresti, **in generale**, poter usare un credito maturato sulla gestione ordinaria per pagare rate della gestione straordinaria (o viceversa)? È una prassi che segui/vorresti seguire abitualmente, o preferisci che le due gestioni restino sempre separate anche a costo di lasciare eccedenze/crediti "in sospeso" per gestione?
5. Al momento della registrazione del pagamento, il sistema ti ha segnalato l'eccedenza/anticipo (messaggio o riepilogo)?
6. Nome condominio e nominativo/ID del condomino, per poter verificare i dati direttamente (scritture contabili coinvolte).

## 4. Audit dati da fare prima di decidere il fix

Query di sola lettura, da eseguire su un ambiente con dump/replica dei dati di produzione (non modificare nulla):

- Quante `RigaScrittura` esistono con `conto_contabile_id` = conto `anticipi_condomini` e `avere > 0` non ancora "consumate" da una `RigaScrittura` di segno opposto sullo stesso conto/anagrafica? Serve per capire se il caso è isolato o sistemico su altri condomini/clienti.
- Per ciascuna di queste, verificare se esistono rate `NON PAGATA` emesse **dopo** la data della scrittura di eccedenza per la stessa anagrafica, nella stessa gestione e in gestioni diverse.
- Se il fenomeno è diffuso, la soluzione deve includere una migrazione dei dati esistenti (creazione retroattiva delle RataQuote di compensazione), non solo un fix per i nuovi incassi.

## 5. Opzioni di fix (nessuna ancora scelta)

### Opzione 1 — Leggere anche il saldo del conto `anticipi_condomini`

Estendere il calcolo del "credito disponibile" per un'anagrafica sommando algebricamente le `RataQuote` di tipo `saldo_iniziale` e il saldo residuo non consumato sul conto `anticipi_condomini`.

- **Pro:** nessuna duplicazione di dati; la contabilità pura resta l'unica fonte di verità per l'eccedenza.
- **Contro:** il "credito spendibile" diventa un aggregato calcolato da due fonti eterogenee (tabella operativa + partitario contabile); più pesante da mantenere coerente, specie con lo scoping per gestione (vedi §2.3) — bisogna decidere se il saldo `anticipi_condomini` è condominio-wide o va filtrato per gestione, e la scrittura originale non porta oggi `gestione_id`/`rata_id` espliciti sulla riga di eccedenza.

### Opzione 2 — Materializzare l'eccedenza come `RataQuote` negativa (`tipo = 'eccedenza'`)

Quando si rileva il residuo in `StoreIncassoRateAction` (righe 160-174), oltre alla scrittura contabile, creare anche una `RataQuote` negativa, analoga al saldo iniziale.

- **Pro:** massima coerenza con il motore di compensazione esistente; `IncassoRateNew.vue` cambia poco (deve solo includere anche `tipo = 'eccedenza'` nella query del credito).
- **Contro:**
  - Richiede di scegliere/creare una "rata contenitore" per l'eccedenza in **una specifica gestione** (vincolo NOT NULL di `rata_id`, §2.3) — non è ovvio quale gestione, se il versamento non era riferito a nessuna rata futura specifica.
  - Richiede logica di storno speculare (scrittura contabile + RataQuote) in caso di rimborso reale al condomino, o di consumo parziale su più incassi successivi.
  - La colonna `tipo` su `rate_quote` è già un semplice `string` con default `'ordinaria'` (migration `2026_02_18_230829`, valori attuali: `ordinaria, saldo_iniziale, conguaglio, straordinaria`) — aggiungere `'eccedenza'` non richiede migration di schema, solo coerenza applicativa.

### Considerazioni trasversali (da entrambe le opzioni)

- Il modulo Pagamento Fatture (`docs/pagamenti_fatture.md`) ha già fissato convenzioni di progetto per casi simili: **audit trail solo additivo (mai soft-delete)**, **eccezioni di dominio specifiche** invece di `\Exception` generiche, **idempotency key** sui movimenti generati da retry. Il fix di questo ticket dovrebbe seguire le stesse convenzioni per coerenza architetturale.
- Va deciso esplicitamente il comportamento se l'eccedenza copre solo **parzialmente** le nuove rate emesse (caso probabile: eccedenza minore del totale delle rate nuove).

## 6. Prossimi passi

1. Ottenere risposte alle domande §3 dall'amministratore.
2. Eseguire l'audit di sola lettura §4 per capire la portata del problema.
3. Solo dopo, tornare su questo documento per chiudere la decisione tra Opzione 1/2 (o una terza via emersa dai dati) e pianificare l'implementazione + eventuale migrazione dati storici.

## Appendice A — Query di sola lettura da far eseguire al cliente (se tecnico)

L'amministratore in questione potrebbe essere tecnico e avere accesso a phpMyAdmin (installazione self-hosted). Se è disposto a farlo, queste due query **SELECT, di sola lettura** (nessuna modifica ai dati) rispondono direttamente alla Domanda Decisiva #1 (§3.1) e localizzano esattamente dove è finita l'eccedenza. Vanno eseguite nel database dell'installazione, sostituendo i due placeholder `NOME_CONDOMINIO` e `NOME_CONDOMINO` (il `LIKE` è case-insensitive di default su MySQL/MariaDB, bastano anche pochi caratteri).

**Query A — Quando sono nate le rate rispetto al piano, per ogni gestione del condomino:**

```sql
SELECT
    g.tipo             AS gestione_tipo,
    g.nome              AS gestione_nome,
    pr.nome             AS piano_nome,
    pr.created_at       AS piano_creato_il,
    r.numero_rata,
    r.data_scadenza,
    r.stato             AS rata_stato,
    r.created_at        AS rata_creata_il,
    rq.importo / 100.0        AS quota_dovuta_eur,
    rq.importo_pagato / 100.0 AS quota_pagata_eur,
    rq.stato            AS quota_stato,
    rq.tipo             AS quota_tipo
FROM rate_quote rq
JOIN rate r        ON r.id = rq.rata_id
JOIN piani_rate pr ON pr.id = r.piano_rate_id
JOIN gestioni g    ON g.id = pr.gestione_id
JOIN anagrafiche a ON a.id = rq.anagrafica_id
JOIN condomini c   ON c.id = pr.condominio_id
WHERE c.nome LIKE '%NOME_CONDOMINIO%'
  AND a.nome LIKE '%NOME_CONDOMINO%'
ORDER BY g.nome, r.numero_rata;
```

**Query B — Gli incassi registrati per quel condomino e dove sono finite le righe (in particolare l'eccedenza sul conto `anticipi_condomini`):**

```sql
SELECT
    sc.id                AS scrittura_id,
    sc.data_registrazione,
    sc.causale,
    g2.nome              AS scrittura_gestione_nome,
    rs.tipo_riga,
    rs.importo / 100.0   AS importo_eur,
    cc.ruolo             AS conto_ruolo,
    cc.nome              AS conto_nome,
    rs.rata_id,
    rs.note
FROM righe_scritture rs
JOIN scritture_contabili sc ON sc.id = rs.scrittura_id
JOIN conti_contabili cc     ON cc.id = rs.conto_contabile_id
LEFT JOIN gestioni g2       ON g2.id = sc.gestione_id
JOIN anagrafiche a          ON a.id = rs.anagrafica_id
JOIN condomini c            ON c.id = sc.condominio_id
WHERE c.nome LIKE '%NOME_CONDOMINIO%'
  AND a.nome LIKE '%NOME_CONDOMINO%'
  AND sc.tipo_movimento = 'incasso_rata'
ORDER BY sc.data_registrazione, rs.id;
```

**Come leggere i risultati:**

- In Query A, confronta `piano_creato_il`/`rata_creata_il` con la data dell'incasso originale (Query B, `data_registrazione` della scrittura con causale del versamento). Se l'incasso è **precedente** alla creazione della rata/piano per "Manutenzione ordinaria ascensore", la Domanda Decisiva #1 è confermata: quelle `RataQuote` non esistevano ancora al momento del pagamento.
- In Query B, cerca la riga con `conto_ruolo = 'anticipi_condomini'` e `note` = "Anticipo / Eccedenza": quella è l'eccedenza. Il campo `scrittura_gestione_nome` sulla sua scrittura (testata) dice sotto quale gestione è stata registrata — utile per la Domanda #4 (cross-gestione).
