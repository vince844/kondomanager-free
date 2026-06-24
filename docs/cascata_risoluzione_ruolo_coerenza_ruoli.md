# Spec — Cascata di risoluzione ruolo + Coerenza-ruoli

**Target:** v1.10 (sul meccanismo per-tabella esistente) · esteso in v1.11 (override per-immobile)
**Stato:** pronto per l'implementazione
**Origine:** feedback beta-tester (usufrutto) + thread forum amministratore (%Bilancio, ruoli)
**Documenti collegati:** `piano-evoluzione-anagrafica-motore-riparto.md` (architettura), `roadmap-kondomanager.md`

Questo spec copre le due feature citate esplicitamente:
1. **Cascata di risoluzione del ruolo** — perché l'ordinaria finisca sull'usufruttuario (non sul nudo proprietario) quando manca l'inquilino, su *qualsiasi* composizione, senza override manuale.
2. **Coerenza-ruoli (quota scoperta)** — perché una quota non sparisca mai in silenzio quando nessun soggetto è risolvibile.

---

## 1. Problema

`CalcoloQuoteService::distribuisciSuTabelle()`, dopo aver scelto il ruolo dal coefficiente di tabella (`$rip->soggetto`), cerca le anagrafiche attive di quel ruolo sull'immobile. Quando il ruolo è assente, il **fallback attuale è piatto**: ricade sempre sul `proprietario`.

```php
// Rule Engine Livello 3: Fallback legale al proprietario  (ATTUALE)
if ($anagrafiche->isEmpty() && in_array($rip->soggetto, ['inquilino', 'usufruttuario'])) {
    $anagrafiche = $immobile->anagrafiche
        ->where('pivot.attivo', true)
        ->where('pivot.tipologia', 'proprietario');
}
```

**Bug.** Unità con nudo proprietario + usufruttuario, **senza** inquilino; tabella ordinaria con coefficiente sull'inquilino. Il motore non trova l'inquilino e ricade sul `proprietario` (= nudo proprietario). Ma l'ordinaria è dell'**usufruttuario** (art. 1004 c.c.). Paga il soggetto sbagliato. Si manifesta solo quando coincidono: usufrutto + niente inquilino + coefficiente sull'inquilino — esattamente il caso sollevato dal tester.

---

## 2. Feature 1 — Cascata di risoluzione del ruolo

### 2.1 Comportamento

Quando il ruolo richiesto è assente sull'immobile, si scende lungo una catena coerente con la **natura economica** della spesa:

- **Asse godimento** (ordinaria/consumi — art. 1004): `inquilino → comodatario → usufruttuario → proprietario`
- **Asse capitale** (straordinaria — art. 1005): `nuda proprietà → proprietario` *(classe unica)*

Si parte dal ruolo **successivo** a quello richiesto e si scende finché si trovano soggetti. `proprietario` è il terminale di entrambe le catene.

**Invariante che la cascata garantisce:** un coefficiente su `inquilino` per una tabella ordinaria è corretto su *tutte* le composizioni — inquilino se c'è, altrimenti usufruttuario, altrimenti proprietario — impostato una volta sola.

**Default per natura, non fiscale.** La cascata implementa la regola del Codice (godimento vs capitale). Il coefficiente fiscale dell'usufrutto (es. 30/70 per età, DPR 131/86) è un'eccezione pattuita → si esprime come override per-immobile (v1.11), non come default.

### 2.2 Modifica al codice

Sostituire il blocco di fallback in `distribuisciSuTabelle()` con:

```php
// Rule Engine Livello 3: Risoluzione a cascata del ruolo (catena per natura)
if ($anagrafiche->isEmpty() && $rip->soggetto !== 'proprietario') {
    $catenaGodimento = ['inquilino', 'comodatario', 'usufruttuario', 'proprietario'];
    $catenaCapitale  = ['nuda_proprietario', 'proprietario'];

    $catena = in_array($rip->soggetto, $catenaCapitale, true)
        ? $catenaCapitale
        : $catenaGodimento;

    $start     = array_search($rip->soggetto, $catena, true);
    $candidati = $start === false ? $catena : array_slice($catena, $start + 1);

    foreach ($candidati as $ruoloFallback) {
        $anagrafiche = $immobile->anagrafiche
            ->where('pivot.attivo', true)
            ->where('pivot.tipologia', $ruoloFallback);

        if ($anagrafiche->isNotEmpty()) {
            Log::debug("distribuisciSuTabelle: ruolo '{$rip->soggetto}' assente su immobile "
                . "ID={$immobile->id}, risolto a cascata su '{$ruoloFallback}'.");
            break;
        }
    }
}
```

**Note:**
- Il blocco copre ora *tutti* i ruoli non-`proprietario` (prima solo `inquilino`/`usufruttuario`): include `comodatario` e `nuda_proprietario`.
- **Forward-compatibile:** se i ruoli `comodatario`/`nuda_proprietario` non sono ancora nell'enum (arrivano in Fase 1), la `where` restituisce vuoto e la catena prosegue — nessun errore.
- Il warning anti-orfano *successivo* (`if ($anagrafiche->isEmpty()) { Log::warning(...); continue; }`) resta e diventa il gancio della Feature 2.
- **Trasparenza:** la risoluzione va esposta nell'anteprima riparto (vedi §4) e congelata nel riparto generato.

### 2.3 Test (Pest) — rosso-poi-verde

File: `tests/Feature/Riparto/CascataRuoloRipartoTest.php`. Ogni caso costruisce un immobile, una tabella con coefficiente su un ruolo, genera il riparto e asserisce **chi** paga.

| # | Composizione immobile | Tabella (coeff.) | Atteso | Stato pre-fix |
|---|---|---|---|---|
| 1 | proprietario + usufruttuario | usufruttuario 100% | usufruttuario | 🟢 (conferma cablaggio) |
| 2 | proprietario(=nudo) + usufruttuario, **no inquilino** | inquilino 100% (ordinaria) | **usufruttuario** | 🔴 → 🟢 (il bug) |
| 3 | proprietario + inquilino | inquilino 100% (ordinaria) | inquilino | 🟢 |
| 4 | solo proprietario | inquilino 100% (ordinaria) | proprietario (catena esaurita) | 🟢 |
| 5 | proprietario(=nudo) + usufruttuario | proprietario 100% (straordinaria) | proprietario (nudo) | 🟢 |
| 6 | proprietario + comodatario, no inquilino | inquilino 100% (ordinaria) | comodatario | 🔴 → 🟢 *(dopo enum Fase 1)* |
| 7 | nessuna anagrafica attiva risolvibile | inquilino 100% | nessuna quota + **warning** | (Feature 2) |
| 8 | due comproprietari `quota` 50/50 | proprietario 100% | split 50/50 | 🟢 (regressione within-role) |

Il caso **#2** è la prova della correttezza: rosso prima della modifica (ricade su proprietario), verde dopo.

---

## 3. Feature 2 — Coerenza-ruoli (quota scoperta)

### 3.1 Comportamento

Quando un coefficiente punta a un ruolo e **nessun** soggetto è risolvibile su un immobile (catena esaurita), la quota **non sparisce in silenzio**: il sistema segnala l'importo scoperto e lascia decidere l'amministratore. Advisory + override + nota — lo stesso pattern del Validatore Coerenza Millesimi (`immutabilita`/Piano Rate «Annullato»).

**Distinzione da non perdere.** Questo controllo è al livello dello *split per ruolo* ("questo coefficiente ha un destinatario reale su questo immobile?"), si corregge sulle anagrafiche o accettando la cascata. È **fratello, non identico**, alla Coerenza Millesimi (livello *tabella*: "somma valori = totale dichiarato?"). Stesso modulo, trigger diversi. Non fonderli.

**Cosa NON segnalare.** Una cascata che risolve correttamente (inquilino assente → proprietario su unità in piena proprietà) è normale: nessun warning, altrimenti l'amministratore impara a ignorarlo. Si segnala **solo** la quota effettivamente scoperta (catena esaurita).

### 3.2 Implementazione

- Il generatore (`calcolaPerGestione` / `calcolaDaFattureStraordinarie`) **raccoglie** le quote scoperte in una struttura, oltre a loggarle: `{ immobile_id, conto_id, tabella_id, ruolo_richiesto, importo_scoperto_cents }`. I gancio sono i `Log::warning` già presenti ("nessuna anagrafica attiva con ruolo…", "nessun peso calcolato per conto…").
- Il risultato della generazione espone l'elenco scoperti (non solo log) per UI e validazione.
- **Anteprima riparto:** semaforo + righe scoperte evidenziate, con importo. Blocco morbido: l'amministratore può procedere fornendo una **nota obbligatoria** (perché si accetta lo scoperto / la ricaduta).
- **Radar Salute Contabile** (widget dashboard, v1.10): detector coerenza-ruoli accanto al validatore millesimi e al detector duplicati. Nascosto se tutto OK, semaforo se ci sono scoperti.

### 3.3 Test

Caso #7 della tabella sopra + varianti: assert che (a) la quota scoperta compare nell'elenco con l'importo corretto, (b) nessun centesimo viene assegnato erroneamente, (c) la generazione non lancia eccezione ma segnala.

---

## 4. Anteprima con risoluzione esplicita (trasparenza)

Requisito trasversale alle due feature e vincolante per la filosofia ledger: **prima** di generare, l'anteprima mostra per ogni quota il **soggetto risolto** (chi paga davvero, dopo cascata/override) e gli eventuali scoperti. L'esito è **congelato** nel riparto generato. Nessuna attribuzione "magica" a runtime: quello che l'amministratore legge è quello che viene addebitato. (In v1.11 lo stesso vale con gli override per-immobile.)

---

## 5. Sequenziamento dei lavori

### Tier 1 — Sblocco immediato (v1.10, meccanismo per-tabella)
1. **Copy fix** `pageGuides` nella pagina di associazione anagrafica: includere "Usufruttuario" (oggi "(Proprietario o Inquilino)"). *(1 riga.)*
2. **Cascata** in `distribuisciSuTabelle` (§2.2). *(~15 righe, sostituzione di blocco.)*
3. **Pest** `CascataRuoloRipartoTest` (§2.3): casi 1–5, 8. Il #2 rosso-poi-verde valida la correttezza.

### Tier 2 — Coerenza visibile (v1.10)
4. Raccolta strutturata delle quote scoperte dal generatore (§3.2).
5. Anteprima riparto con risoluzione esplicita + scoperti (§4).
6. Radar Salute Contabile: detector coerenza-ruoli.
7. Override + nota obbligatoria sullo scoperto.

### Tier 3 — Modello pieno (v1.11)
8. Enum ruoli `nuda_proprietario`, `comodatario` *(in realtà v1.10 Fase 1; abilita i casi #6 e l'asse capitale completo)*.
9. Override per-immobile `quote_tabella_ripartizioni` + distribuzione `quota_bilancio` rinormalizzata entro il ruolo + validazione anti-orfano completa.

### Follow-up noto (priorità bassa)
- `addebitaDiretto` (attribuzione diretta a immobile su riga fattura) addebita sempre al `proprietario`, ignorando natura e usufrutto. È il percorso di override manuale: difendibile, ma da rivedere quando si tocca quel ramo.

---

## 6. Decisioni da confermare

1. **`%Bilancio` entro il ruolo** (non sull'intero immobile) — con l'amministratore proponente. Vedi §2.3 del piano: copre i suoi tre casi (comproprietari, minorenni, villetta), coincide con la sua intuizione nel caso mono-ruolo, diverge solo sul multi-ruolo.
2. **`comodatario` come ruolo distinto** — proposto sì (detrazione 36% "diritto personale di godimento" + generalità richiesta). Inerte nella cascata finché non aggiunto.
3. **Aliquote detrazione 2026** — da verificare prima del seed di produzione (il seed 2025 è confermato).