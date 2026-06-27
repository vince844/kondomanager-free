# Registrazione a Regolazione Immediata — Spec Tecnica Interna

> Stato: proposta · Target consigliato: **v1.10.0** (sibling di Giroconti / Cash Statements)
> Origine: spunto dal forum (thread "fattura → fornitore → banca troppo pesante")
> Rev. 2: origine via `RegistrazioneType` (Backed Enum già esistente, non un nuovo enum) · separazione origine / causale

---

## 1. Obiettivo

Permettere la registrazione diretta di un movimento economico-finanziario
(**costo → banca/cassa** in scrittura unica) senza aprire una partita fornitore,
per tutti i fatti amministrativi che nascono e si estinguono nello stesso momento:
bollette, imposte di bollo, commissioni bancarie, addebiti automatici, F24
(quando non è estinzione di un debito già rilevato), piccole spese.

Obiettivo collaterale: **libro giornale leggibile** e prerequisito per una
riconciliazione bancaria pulita (la riga "commissione 2,50" si imputa
costo → banca senza inventare un fornitore fittizio "BANCA").

Non sostituisce il flusso fattura → debito fornitore → pagamento. Lo **affianca**.

---

## 2. Orientamento sulla partita doppia

La registrazione contestuale è ineccepibile: è normale prima nota a regolazione
immediata. Una sola scrittura:

```
DARE   Sottoconto costo (es. Energia elettrica)   100
AVERE  Banca / Cassa                               100
```

Il punto architetturale: **questa non è una nuova entità, è una `Scrittura`
che nasce senza una `Fattura` a monte.** Il principio ledger-centrico già
stabilisce che il giornale è la verità immutabile; una registrazione diretta è
semplicemente una scrittura a cui nessuna fattura punta:

- nessuna riga nel pivot `fattura_scrittura`;
- nessuno `stato_pagamento` da ricalcolare (non esiste fattura di cui tracciare il pagamento);
- `Scrittura.esercizio_id` = periodo operativo del libro, come sempre.

---

## 3. Perché NON costruire un `MovimentoContabile` unificato

La proposta del forum di fondere "fattura" e "pagamento" in un'unica entità con
flag `genera_partita` è seducente ma **reinventa, in forma inferiore, ciò che
esiste già**.

| Esigenza | Soluzione del forum | Soluzione già presente in KondoManager |
|---|---|---|
| Movimento contabile atomico | `MovimentoContabile` nuovo | `Scrittura` (già universale) |
| Apertura debito opzionale | `genera_partita` bool | presenza/assenza riga pivot `fattura_scrittura` |
| Competenza vs cassa | `stato_pagamento` enum | separazione `Fattura.esercizio_id` (competenza) vs `Scrittura.esercizio_id` (operativo) |

Fondere le due entità **collasserebbe** la separazione competenza/operativo, cioè
proprio il meccanismo che consente i pagamenti a cavallo d'anno (competenza 2025,
pagamento 2026). La `Scrittura` è già il primitivo; la `Fattura` è opzionale a
monte. La regolazione immediata è il caso naturale "zero fatture".

**Regola di progetto: non introdurre `MovimentoContabile`. Esporre un nuovo
percorso di origine della `Scrittura`.**

---

## 4. Modello dati

Nessuna nuova tabella centrale. Servono due dimensioni distinte sulla
`Scrittura` — provenienza tecnica e significato amministrativo.

### 4.1 Origine (provenienza tecnica) — riusa l'enum esistente, non crearne uno nuovo

Il feedback è corretto sul principio: niente stringhe sparse, serve un Backed
Enum. **Ma prima di scriverne uno nuovo, allineamento reale:** esiste già
`RegistrazioneType` (l'enum che alimenta `HasProtocolNumber`, con casi tipo
`RegolazioneImmediata`, `StornoPagamentoFornitore`). Con ogni probabilità
*l'origine È già quell'enum* — il feedback proponeva `ScritturaOrigine` senza
sapere che `RegistrazioneType` esiste.

- **Scelta consigliata (un solo enum):** persisti `RegistrazioneType` sulla
  `Scrittura`. Il codice protocollo (`RIM`, `STO`…) e il filtro di provenienza
  diventano due usi derivati dello *stesso* enum. Eviti un secondo enum parallelo
  — cioè proprio la proliferazione che il feedback vuole scongiurare.
- Introduci un `ScritturaOrigine` *separato* solo se la granularità di
  `RegistrazioneType` si rivelasse troppo fine per il filtraggio. In quel caso
  `origine` = raggruppamento di valori `RegistrazioneType`, con mapping esplicito.

```php
// migration su scritture
$table->string('registrazione_type')->index();
// model: 'registrazione_type' => RegistrazioneType::class (cast nativo)
```

> **Significato blindato (importante):** `RegistrazioneType` identifica il *flusso
> applicativo* che ha originato la `Scrittura` — provenienza e driver del codice
> protocollo. **Non** rappresenta la causale amministrativa (§4.2) **né** uno stato
> contabile/workflow. Da non riusare nel tempo come macro-causale, categoria
> gestionale o stato di pagamento: per quegli scopi esistono campi dedicati.

**Parti minimale e cresci.** Non riversare subito nell'enum tutta la lista
ipotetica del feedback (cash_statement, import_bancario, assestamento, chiusura,
apertura, storno, riparto, rettifica…): diversi di quegli item sono in realtà
*causali* o *tipi protocollo*, non origini — e lo storno hai già deciso di
gestirlo come scrittura inversa con protocollo `STO`. Aggiungi un caso quando il
flusso che lo genera esiste davvero.

### 4.2 Causale (significato amministrativo) — flessibile, NON un enum

Giusta la separazione del feedback: `origine` (come nasce la scrittura) ≠
`causale` (cosa significa: utenza, commissione_bancaria, bollo,
trasferimento_cassa). **Ma la causale non va modellata come Backed Enum:** le
causali condominiali sono aperte e crescono senza limite, un enum rigido sarebbe
lo strumento sbagliato. Riusa il meccanismo `causale`/`descrizione` già presente
sulle scritture, al più con un vocabolario controllato (lookup).

> Regola pratica: **enum** dove l'insieme è piccolo e *guida comportamento*
> (origine → policy, codice protocollo); **testo/lookup** dove l'insieme è grande
> e *descrive* (causale).

### 4.3 Comportamento

Una regolazione immediata produce **una sola** `Scrittura` con due righe
(DARE costo / AVERE banca), `RegistrazioneType::RegolazioneImmediata`, una
causale amministrativa, nessuna `Fattura`, nessuna riga `fattura_scrittura`,
nessuno `stato_pagamento`.

---

## 5. Fornitore come tag analitico (opzionale)

Per soddisfare il "fornitore facoltativo" senza aprire partita:

- campo `anagrafica_id` (o `fornitore_id`) **nullable** sulla `Scrittura` di
  regolazione immediata, usato **solo come tag analitico** per reportistica;
- **non** movimenta il mastrino Debiti v/Fornitori;
- **non** genera scadenziario né esposizione.

Così "ho pagato ENEL" resta interrogabile in reportistica, ma a costo zero sul
partitario.

---

## 6. Guard rail (blocchi obbligatori)

La regolazione immediata va **vietata per costruzione** dove serve la struttura
del debito. Nota: per il condominio la cautela "IVA detraibile" del forum è
quasi irrilevante (il condominio è consumatore finale, l'IVA è costo, non si
detrae). I vincoli veri sono:

1. **Ritenuta d'acconto** — il condominio è sostituto d'imposta (4% art. 25-ter
   sugli appalti; ritenute ordinarie sui professionisti). Va spezzato
   netto-al-fornitore vs ritenuta-all'Erario → richiede partita. **Vietato.**
2. **Split payment PA** — richiede partita. **Vietato.**
3. **Scadenziario / controllo esposizione fornitori** — se serve tracciare il
   debito nel tempo. **Vietato.**
4. **F24 che estingue un debito già rilevato** (es. ritenute operate) — il debito
   esiste già; va chiuso contro quel debito, non imputato a costo. **Vietato.**

Implementazione nello stile `Exceptions.php` esistente:

```php
// in Exceptions.php (poi aggiunto al "files" autoload di composer.json)
class RegolazioneImmediataNonAmmessaException extends \DomainException {}
```

Validazione fail-fast nel service, con `Log::warning` al punto di uscita prima
del throw (coerente con i pattern di diagnostica già adottati).

---

## 7. Numerazione / causale / protocollo

La scrittura entra comunque nella numerazione progressiva del libro giornale.
Il codice protocollo deriva dallo stesso `RegistrazioneType` di §4.1 (non è la
`causale` amministrativa, vedi §4.2). Sul trait `HasProtocolNumber` (ricorda:
estrarre `.value` dal Backed Enum prima del `match`) aggiungere il caso:

```php
RegistrazioneType::RegolazioneImmediata => 'RIM',
```

(Sigla `RIM` indicativa — allineala alla tua convenzione, accanto a `STO`.)

---

## 8. Flusso UI

Aggiungere un punto d'ingresso esplicito. **Evitare il termine "salta
fornitore"**; usare **"Registrazione a regolazione immediata"** (oppure
"Pagamento contestuale"): difendibile contabilmente e professionale.

Campi minimi:

- data
- descrizione / causale
- sottoconto costo (DARE)
- banca / cassa (AVERE)
- importo
- fornitore (opzionale, solo tag analitico)

Conferma → genera la `Scrittura`. Nessuno step "pagamento" successivo.

---

## 9. Casi di test (Pest)

Convenzioni esistenti: helper condivisi in `GestionaleTestHelpers.php` con
`require_once`; SQLite in-memory; enum compatibili (`'attivo'`/`'crediti'`);
`Event::fake()` dove i listener richiedono record DB; nessun nuovo DB di test.

Copertura minima:

1. Regolazione immediata genera **una sola** scrittura quadrata (DARE = AVERE).
2. **Nessuna** riga in `fattura_scrittura`; **nessuno** `stato_pagamento`.
3. `registrazione_type` valorizzato a `RegistrazioneType::RegolazioneImmediata` (non esiste più un campo `origine`).
4. `anagrafica_id` valorizzato → **nessun** movimento su Debiti v/Fornitori.
5. Guard rail: ritenuta / split payment → `RegolazioneImmediataNonAmmessaException`.
6. La scrittura prende numero progressivo di giornale e **codice protocollo** `RIM` (derivato da `RegistrazioneType`, non è la causale — §7).
7. Esercizio chiuso → `FiscalYearClosedException` sulla scrittura (regola invariata).
8. Storno: scrittura inversa (giornale immutabile), pivot negativo non applicabile
   (non c'è pivot) → verificare che lo storno generi solo l'inversa.

---

## 10. Quando lavorarci

**Raccomandazione: v1.10.0, come sibling di Giroconti e Cash Statements.**

Motivo: Giroconti e Cash Statements condividono *lo stesso primitivo* — una
`Scrittura` diretta senza `Fattura`. Un giroconto è banca → cassa; una
regolazione immediata è costo → banca. Stesso percorso di origine, stesso campo
`origine`, stessa UI di prima nota, stessi pattern di test. Costruirli insieme
evita di toccare due volte lo stesso codice.

**Perché NON in v1.9.1:** la v1.9.1 è *pagamento di fatture* via
`PagamentoFornitoreService`, sostanzialmente completa con Pest a verde.
La regolazione immediata è prima nota diretta che una fattura non la genera mai:
superficie d'implementazione distinta (nuovo flusso, guard rail, test). Riaprire
1.9.1 introdurrebbe rischio di regressione su un beta quasi chiuso.

**Pull-forward minimo (opzionale):** se vuoi il quick win durante il beta dei
pagamenti, è possibile anticipare una versione minimale (solo §4.3 + §6 + §7,
senza persistere `registrazione_type` sulla scrittura, distinguendo per assenza
di pivot) in una v1.9.x successiva. Costo: dovrai poi aggiungere il campo in
v1.10.0 con una migration di backfill. Sconsigliato se non urgente.

**Fuori perimetro (hanno già casa in roadmap):**

- *Movimenti ricorrenti automatici* (ENEL mensile, canone ascensore…) → v1.22
  Fatture Avanzate (Recurring). Non mescolare con il primitivo base.
- *Auto-categorizzazione da import estratto conto* → payment foundation / v1.16
  Treasury & bank reconciliation. La regolazione immediata è il **target** su cui
  l'import imputerà le righe, non l'import stesso.

---

## 11. Cosa NON fare — promemoria

- ❌ Non introdurre `MovimentoContabile` unificato (vedi §3).
- ❌ Non fondere `Fattura` e `Scrittura`.
- ❌ Non permettere regolazione immediata con ritenuta / split payment (§6).
- ❌ Non usare soft-delete per gli storni (giornale immutabile, scrittura inversa).
- ❌ Non scrivere `stato_pagamento` sulla scrittura (non esiste fattura).
- ❌ Non creare un `ScritturaOrigine` parallelo a `RegistrazioneType` senza prima
  verificare l'overlap (§4.1).

**Da monitorare (non ora):** con il moltiplicarsi delle origini "Scrittura senza
Fattura" potrebbe in futuro emergere un oggetto di provenienza polimorfico
(`ScritturaSource` / source aggregate). Per v1.10 **non serve**: persistere
`RegistrazioneType` è la soluzione correttamente minimalista.

---

## 12. Sintesi in una riga

La regolazione immediata **non è un nuovo motore**: è la `Scrittura` che già hai,
esposta tramite un percorso di origine diretto, con fornitore opzionale come tag
e guard rail su ritenuta/split payment. Costruirla con Giroconti in v1.10.0.