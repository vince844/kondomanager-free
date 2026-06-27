# Immutabilità a Soglia — Modifica Sotto-Soglia e Storno Automatico

> **Progetto:** KondoManager
> **Ambito:** Contabilità · Scritture · Pagamenti Fornitori · Incassi Rate
> **Stato:** proposta di design — regole di business consolidate (pre-implementazione)
> **Revisione:** rev. 2 — integrata la review architetturale (principio della controparte esterna, catena di correzione, audit log a due livelli, granularità delle attribuzioni, soglia importo su fatture parzialmente pagate).
> **Principio di riferimento:** *doppia presentazione, una sola verità* — il libro giornale (ledger append-only in partita doppia) è l'unica fonte di verità; i report sono proiezioni.

---

## 1. Problema e obiettivo

Stornare manualmente ogni refuso e re-registrare la scrittura corretta è percepito dagli amministratori come una perdita di tempo, soprattutto per errori di digitazione su movimenti appena inseriti. Allo stesso tempo l'integrità del libro giornale immutabile è il fondamento di KondoManager e non è negoziabile.

Questo documento definisce un modello di **immutabilità a soglia**: la correzione è agevole *sotto* una soglia di integrità, mentre *oltre* la soglia resta obbligatorio lo storno o la sopravvenienza.

### 1.1 Il chiarimento che sblocca il problema

Argomento ricorrente (corsi AMACI): *"l'obbligo di registrazione entro 30 giorni ex art. 1130 n.7 è di fatto assolto dall'estratto conto, dato che tutto deve muoversi per banca (art. 1129)"*.

- **Vero per la cassa.** L'estratto conto è un documento immutabile prodotto da un terzo e ancora **importo / data / conto** dei movimenti di liquidità.
- **Falso per l'attribuzione.** La banca vede *"€500 in uscita"*, non vede che sono manutenzione ascensore, fornitore X, tabella scale B; né che un incasso è di Rossi e non di Bianchi. **Partitario, voce, tabella e riparto vivono solo nel software** — e sono esattamente l'oggetto del contenzioso condominiale.

**Conseguenza di design:** non aboliamo l'immutabilità, ne **spostiamo il confine**.

- La **cassa** si blinda alla **riconciliazione con la banca**.
- L'**attribuzione** si blinda alla **chiusura dell'esercizio** (quando il riparto è deliberato).
- Prima di tali soglie la modifica è concessa, ma **sempre tracciata**.

---

## 2. Meccanismo centrale: storno-sotto-il-cofano

L'utente **non modifica mai direttamente** una scrittura già registrata. L'azione "Modifica" è zucchero sintattico sopra l'operazione contabile corretta:

1. genera la **scrittura di storno** (inversa: segni opposti, pivot `fattura_scrittura` negativa);
2. genera la **nuova scrittura** corretta;
3. il tutto in **un'unica transazione atomica**.

### 2.1 Tracciamento della catena di correzione

Per legare le versioni non bastano i riferimenti immediati: con catene lunghe (A → B → C → D dopo mesi) servirebbe un traversing ricorsivo per sapere qual è la versione viva. Si aggiungono quindi un identificativo di lineage e un marcatore della versione effettiva:

- `storno_di_id` / `corretta_da_id` → legami **immediati** tra le due scritture di una singola correzione;
- `correction_chain_id` → UUID assegnato alla **scrittura radice** ed ereditato da tutta la catena (storno e nuova inclusi);
- `is_corrente` → flag che marca l'**unica versione economicamente valida** della catena.

Risultato in **O(1)**, senza ricorsione: `WHERE correction_chain_id = X` restituisce l'intera storia; `is_corrente = true` la versione effettiva.

### 2.2 Effetti

- ledger **append-only al 100%**: nessuna mutazione in-place, nessun soft-delete (coerente con *storno, non cancellazione*);
- l'utente **non vede il rumore**: in presentazione si mostrano solo le scritture `is_corrente` (*doppia presentazione, una sola verità*), con un toggle "Mostra correzioni" per la catena completa;
- la **catena di ricalcolo** (somme pivot → `stato_pagamento` → Treasury Guardian → partitario) è quella che già gestisce gli storni: **l'idempotenza ci copre**.

### 2.3 Eccezione — refuso transitorio (bozza non registrata)

Se la scrittura **non è ancora a ledger** (es. fattura in stato *Bozza/Da Approvare* senza scrittura di competenza, o movimento in fase di inserimento non confermato), la modifica è **in-place diretta**: non c'è nulla a valle, quindi non serve storno.

---

## 3. Le soglie di blocco (Hard-Lock)

### 3.1 Principio organizzante: la controparte esterna

Una soglia diventa hard-lock quando **una controparte esterna indipendente ha già acquisito il dato.** In KondoManager le controparti esterne sono **tre**:

| Controparte esterna | Dato acquisito | Soglia generata |
|---|---|---|
| **Banca** | importo, data, conto (il fatto-cassa) | Riconciliazione |
| **Agenzia delle Entrate** | base della ritenuta versata | F24 pagato |
| **Assemblea** | il riparto deliberato | Chiusura esercizio |

Questo principio genera non solo *quali* soglie esistono, ma anche la loro **granularità a livello di campo**: ogni controparte ha acquisito solo certi dati, quindi blocca solo quelli. La banca ha acquisito la cassa → la riconciliazione blocca solo la cassa; l'assemblea ha acquisito il riparto → la chiusura blocca anche l'attribuzione. È anche il criterio per decidere **ogni soglia futura**.

### 3.2 Tabella delle soglie

Oltre queste soglie la modifica agevole **non** è disponibile: serve storno manuale o sopravvenienza.

| Soglia | Cosa blocca | Via di correzione |
|---|---|---|
| **Esercizio chiuso** | Tutto (importo **e** attribuzione): riparto deliberato, partitario cristallizzato | Sopravvenienza (attiva/passiva) nell'esercizio aperto corrente — **mai** riapertura del chiuso |
| **Movimento riconciliato in banca** | Solo i fatti-cassa: **importo, data, conto**. L'attribuzione resta correggibile secondo la sua granularità (§6.5) | Storno manuale del movimento di cassa |
| **F24 pagato** (pagamento con ritenuta) | Importo e dati che impattano la ritenuta | Ravvedimento / F24 integrativo / conguaglio nel periodo successivo |

### 3.3 Corollario: lock a livello di campo

La riconciliazione **non** blocca l'intera scrittura, ma **solo ciò che la banca ha verificato** (importo/data/conto). L'attribuzione (anagrafica, voce) non è mai stata vista dalla banca, quindi resta modificabile anche su movimento riconciliato. È **solo la chiusura esercizio** a bloccare anche l'attribuzione, perché lì la controparte è l'assemblea, che ha acquisito il riparto.

---

## 4. Regole campo per campo (per il codice)

**Legenda meccanismo:**
**In-place** = modifica diretta (bozza) · **Storno-auto** = storno + nuova trasparente · **Hard-lock** = bloccato (richiede storno manuale o sopravvenienza) · **Warning** = consentito con avviso e conseguenza esplicita.

### 4.1 Fattura Passiva (il debito)

| Condizione | Importo / competenza | Metadati (note, allegati) | Meccanismo |
|---|---|---|---|
| Bozza/Da Approvare · esercizio aperto · 0 pagamenti · nessuna competenza a ledger | Consentita | Consentita | **In-place** |
| Registrata (competenza a ledger) · esercizio aperto · 0 pagamenti | Consentita | Consentita | **Storno-auto** della competenza |
| Con pagamenti collegati (anche parziali) | Consentita via storno-auto **se** `nuovo_importo >= totale_allocato`, altrimenti → nota di credito (vedi nota) | Consentita | **Storno-auto** competenza con guardia |
| Approvata con ratifica sforo (art. 1135) | **Warning** → torna *Da Ratificare* se cambia importo/fornitore/natura | Consentita (note non rilevanti) | **Warning + revert ratifica** |
| Esercizio chiuso | Bloccata | Bloccata | **Sopravvenienza** |
| Inclusa in CU/770 generato | Bloccata sui dati fiscalmente rilevanti | Bloccata | **Hard-lock** |

> **Importo su fattura parzialmente pagata.** Non si blocca a priori. Se la fattura corretta resta `>= totale_allocato` ai pagamenti (es. 1.000 → 950 con 400 già pagati: 950 ≥ 400), la correzione è coerente: storno della competenza + nuova competenza, il residuo si ricalcola. Si blocca **solo se** `nuovo_importo < totale_allocato`, perché lì si genera un credito verso il fornitore — altro evento economico, da gestire con nota di credito, non una correzione. *Implementazione `// TODO`*: correggere l'imponibile di una fattura già parzialmente pagata impone di ricalcolare lo split imponibile/IVA e l'eventuale ritenuta sui pagamenti già effettuati; finché il caso d'uso non lo giustifica vale il **blocco conservativo** (annullare prima i pagamenti).

### 4.2 Pagamento Fornitore (uscita di cassa)

`tipo_movimento = 'pagamento_fornitore'` — DARE Debiti / AVERE Banca

| Condizione | Importo / data / conto | Attribuzione | Meccanismo |
|---|---|---|---|
| Appena inserito · esercizio aperto · non riconciliato · senza ritenuta o F24 non generato | Consentita | Consentita | **Storno-auto** + ricalcolo pivot e `stato_pagamento` fatture |
| Riconciliato in banca | Bloccata | Leggera: diretta · Riallocazione fattura: con **soft warning** (§6.5) | **Field-lock** cassa + storno-auto della pivot |
| Con ritenuta · F24 generato non pagato | **Warning** (rigenera l'F24) | Consentita | **Storno-auto** + rigenerazione F24 |
| Con ritenuta · F24 pagato | Bloccata | Bloccata sui dati che impattano la ritenuta | **Hard-lock** (soglia 3) |
| Esercizio chiuso | Bloccata | Bloccata | **Sopravvenienza** |

### 4.3 Incasso Rata (entrata di cassa)

| Condizione | Importo / data / conto | Attribuzione (`anagrafica_id`, rata) | Meccanismo |
|---|---|---|---|
| Appena inserito · esercizio aperto · non riconciliato | Consentita | Consentita | **Storno-auto** + ricalcolo partitario |
| Riconciliato in banca | Bloccata | **Consentita** (es. Rossi → Bianchi, attribuzione leggera) | **Field-lock** cassa |
| Esercizio chiuso | Bloccata | Bloccata (riparto deliberato) | **Sopravvenienza** |

> **Nota UX (caso ad alto valore):** la correzione dell'attribuzione su incasso riconciliato — bonifico di Rossi registrato per errore su Bianchi — si fa cambiando `anagrafica_id` **senza toccare importo/data**, quindi senza rompere la quadratura bancaria. Sempre registrata in audit log (§5).

---

## 5. Audit: due livelli complementari

Il tracciamento ha **due livelli distinti e complementari**, non sovrapponibili.

**Livello contabile — il ledger.** Racconta la *verità economica*: cosa è cambiato e quando, attraverso la catena di storno (`correction_chain_id`, `is_corrente`). È append-only e immutabile.

**Livello operativo — l'audit log applicativo.** Racconta la *verità operativa*, che **non è un dato contabile e non va infilato nelle scritture**: `utente_id`, IP, user-agent, motivazione della correzione, azione/schermata di origine, timestamp. Inquinare le scritture con questi metadati sarebbe un errore.

Il ledger dice *"1.000 → storno → 1.200"*; l'audit log dice *"l'ha fatto Mario Rossi il 14/03 dalle Fatture Passive, motivazione: refuso importo"*. **Servono entrambi.**

> **Riuso:** probabilmente **non serve una tabella nuova**. Il modello `Evento` polimorfico esistente (`eventable_type` / `eventable_id`) è il posto naturale per agganciare i metadati operativi alla scrittura corretta, con un `EventoTipo` dedicato (es. `correzione_scrittura`).

L'audit complessivo — ledger + audit log — è ciò che rende i conti difendibili in assemblea e in causa, **soprattutto sulle attribuzioni, che la banca non copre**.

---

## 6. Casi particolari

### 6.1 Sforo motivato e ratifica (art. 1135)

La ratifica assembleare autorizza una **specifica cifra** di spesa eccedente il preventivo. Distinzione **materiale vs immateriale**:

- cambio di **importo, fornitore, natura della spesa, link alla delibera** → la ratifica non copre più: **warning esplicito + ritorno automatico a *Da Ratificare***;
- cambio di **sola nota descrittiva** → ratifica intatta, modifica tracciata.

Soft warning, **non** hard-block (filosofia *Fiscal Sentinel*): riportare in ratifica è un'operazione pesante (richiede una nuova assemblea), quindi non va innescata su modifiche immateriali.

### 6.2 F24 e ritenute — graduazione

- F24 **non generato** → pagamento modificabile, ricalcola la ritenuta;
- F24 **generato ma non pagato** → modificabile **con warning**, obbligo di rigenerare l'F24;
- F24 **pagato** → **hard-lock**: il denaro è uscito verso l'Agenzia delle Entrate (controparte esterna, transazione irreversibile); correzione solo via ravvedimento/integrativo.

### 6.3 Periodi chiusi → sopravvenienze

Errore scoperto dopo la chiusura: **non si riapre l'esercizio**, si registra una sopravvenienza (attiva/passiva) nell'esercizio aperto corrente. La chiusura esercizio resta un **confine duro**. Il soft-edit non deve in alcun modo costituire un percorso alternativo che la aggiri.

### 6.4 Flag di riconciliazione

`is_riconciliato` (+ `riconciliato_at`, eventuale riferimento riga estratto conto) come **annotazione** sulla scrittura di cassa: **non modifica DARE/AVERE**, quindi **non viola l'append-only**.

- **Fase attuale:** spunta **manuale** — l'amministratore confronta con l'estratto PDF/cartaceo.
- **Fase futura:** il motore di riconciliazione (formati CBI/MT940 — BancoPosta, Fineco, Intesa) setta lo stesso flag a codice.

Il **flag è il contratto**; il matching automatico è implementazione. `// TODO(riconciliazione-auto)`

### 6.5 Granularità delle attribuzioni

Dopo la riconciliazione non tutte le attribuzioni hanno lo stesso peso, ma la distinzione **non** è "leggera vs forte" in due secchi rigidi: dipende dal fatto che il cambio **propaghi o no su un'altra soglia** (in pratica, quella fiscale). Tre livelli:

- **Leggera** — `anagrafica_id`, note, descrizione, centro di costo interno. Cambio diretto via storno-auto, nessun attrito. *(Es. incasso di Rossi assegnato a Bianchi: importo/data invariati, la quadratura bancaria non si rompe.)*
- **Riallocazione** — pagamento spostato da una fattura all'altra. **Non** tocca il fatto-cassa (importo/data/banca invariati: cambia la pivot, non la scrittura di cassa), quindi resta consentita via storno-auto della pivot, ma con **soft warning** perché cambia lo `stato_pagamento` di due fatture.
- **Propagante** — il cambio investe la ritenuta di un F24 **già pagato**. Qui scatta la **soglia 3** (hard-lock): l'unico caso in cui interviene davvero una controparte esterna.

Coerente con il principio §3.1 e con la filosofia *Fiscal Sentinel* (warning + override dove serve il giudizio dell'amministratore, hard-lock solo dove c'è una controparte esterna).

---

## 7. Cosa NON fare (anti-pattern)

- Mutazione in-place di una scrittura già registrata (rompe l'append-only e perde l'audit).
- Soft-delete di scritture o pagamenti.
- Qualsiasi percorso che permetta di editare un esercizio chiuso senza passare da sopravvenienza.
- Bloccare l'**intera** scrittura alla riconciliazione (l'attribuzione deve restare correggibile secondo §6.5).
- Trattare la **riallocazione** di un pagamento come hard-lock quando non tocca alcuna controparte esterna: basta storno-auto + warning.
- Permettere la modifica dell'importo di un pagamento il cui F24 è già pagato.
- Bloccare a priori l'importo di una fattura parzialmente pagata che resta `>= totale_allocato` (il blocco serve solo sotto il già pagato).
- Infilare metadati operativi (utente, IP, user-agent) **dentro le scritture**: appartengono all'audit log applicativo.
- Modifica silenziosa di una fattura ratificata (se materiale, deve invalidare la ratifica).

---

## 8. Note implementative

- Lo storno-auto è una **singola azione idempotente** in transazione; in caso di errore, rollback completo.
- Dopo lo storno-auto, ricalcolo di: somme pivot `fattura_scrittura` per `tipo` → `stato_pagamento` (Pagata/Parziale/Da pagare), Treasury Guardian, partitario del condòmino.
- **Catena di correzione:** `correction_chain_id` (UUID di lineage) + `is_corrente`, accanto a `storno_di_id` / `corretta_da_id`. La presentazione netta filtra `is_corrente`; l'audit espande la catena.
- **Audit log operativo:** valutare il riuso del modello `Evento` polimorfico (+ `EventoTipo` `correzione_scrittura`) invece di una tabella nuova.
- **Soglia parziali:** regola `nuovo_importo >= totale_allocato`; sotto → nota di credito. Implementazione `// TODO` (ricalcolo imponibile/IVA/ritenuta su fattura già pagata); intanto blocco conservativo.
- `RegistrazioneType` resta l'asse di **origine** della scrittura (provenienza applicativa), **da non confondere** con la causale amministrativa: lo storno-auto eredita l'origine della scrittura corretta.
- Le soglie sono valutate da **un unico servizio** (`MutabilityPolicy` o equivalente) — `$policy->canEdit($movimento)` — interrogabile sia dalla UI (per abilitare/disabilitare "Modifica" e mostrare il motivo del blocco) sia dal backend (enforcement). **Singola fonte di verità sulle regole.**
- **Verificare contro lo schema reale** i nomi effettivi dei campi prima dell'implementazione (`is_riconciliato`, `correction_chain_id`, `is_corrente`, riferimenti pivot, ecc.).

---

## 9. Perché è meglio degli altri gestionali

La maggior parte dei gestionali italiani lavora su **registri movimenti liberamente editabili**, senza ledger append-only: comodi per il refuso, ma privi di tracciabilità e quindi deboli in contenzioso. Con questo modello KondoManager ottiene **entrambe** le cose — la stessa agilità di correzione nella finestra sicura **e** un libro giornale immutabile e auditabile.

Il razionale è il principio della **controparte esterna** (§3.1): si blinda esattamente ciò che un terzo indipendente — banca, Agenzia delle Entrate, assemblea — ha già acquisito, e nulla di più. Questo dà difendibilità legale senza rigidità inutile: precisamente la posizione che il miglior gestionale condominiale open source deve occupare sul piano **contabile, economico, legale e di pratica amministrativa**.

---

## 10. Piano di Transizione (Migrazione futura al modello storno-auto)

Essendo l'implementazione della v1.9.1 basata su un modello CRUD tradizionale (mutazione in-place distruttiva) per ragioni di rilascio, la migrazione futura (es. v1.9.2 o v2.0) al modello descritto in questo documento sarà facilitata dai seguenti fattori:

### 10.1 La migrazione del Database sarà additiva
Per introdurre le novità della guida, in futuro si dovrà solo fare una migrazione Laravel molto semplice, che non toccherà i dati esistenti ma aggiungerà le colonne di lineage:

```php
$table->uuid('correction_chain_id')->nullable();
$table->boolean('is_corrente')->default(true); // Fondamentale: i dati vecchi diventano automaticamente 'correnti'
$table->foreignId('storno_di_id')->nullable();
```
Impostando `is_corrente` a `true` di default, tutto il pregresso della 1.9.1 funzionerà al volo (i record vecchi diventeranno automaticamente la "versione viva"). Non ci sarà corruzione storica perché il DB attuale non contiene errori tracciati.

### 10.2 Laravel Global Scopes sulle letture
La preoccupazione più grande passando a un modello in cui i dati non si cancellano più è l'impatto sulle query di lettura. In Laravel, sarà sufficiente aggiungere un Global Scope al modello `ScritturaContabile`:

```php
protected static function booted()
{
    static::addGlobalScope('corrente', function (Builder $builder) {
        $builder->where('is_corrente', true);
    });
}
```
Con poche righe, tutte le dashboard, i bilanci e i partitari continueranno a funzionare ignorando magicamente il "rumore" degli storni.

### 10.3 Il refactoring sarà localizzato
Il passaggio logico richiederà di rimettere mano quasi esclusivamente ai Service di modifica (es. `FatturaPassivaService::aggiornaFattura` e `PagamentoFornitoreService::aggiornaPagamento`), trasformando le `delete()` in `insert()` di segno opposto. Il resto dell'app non richiederà variazioni strutturali.

---

## Storico revisioni

- **rev. 3** — Aggiunto piano di transizione per facilitare la migrazione futura dal modello CRUD v1.9.1 al modello ledger-centric definitivo (§10).
- **rev. 2** — Integrata la review architetturale: principio della controparte esterna come razionale delle soglie (§3.1); catena di correzione `correction_chain_id` + `is_corrente` (§2.1, §8); audit log operativo separato dal ledger, su modello `Evento` (§5); granularità delle attribuzioni leggera/riallocazione/propagante (§6.5, §4.2); soglia importo su fattura parzialmente pagata `>= totale_allocato` con implementazione differita (§4.1, §8).
- **rev. 1** — Versione iniziale: storno-sotto-il-cofano, soglie esercizio/riconciliazione/F24, field-level lock, regole campo per campo, MutabilityPolicy.