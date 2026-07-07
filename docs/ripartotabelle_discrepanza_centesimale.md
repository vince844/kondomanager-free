# RipartoTabelleService — Discrepanza Centesimale e Roadmap Fix
**Data:** 2026-06-28  
**Versione attuale:** v1.9.1  
**Stato:** RISOLTO il 2026-07-06 — vedi appendice "Risoluzione definitiva" in fondo  
**File coinvolti:** `app/Services/RipartoTabelleService.php`  
**Documento di riferimento:** `tests/fixtures/T12/16Preventivo.pdf`
---
## Contesto
Il `RipartoTabelleService` costruisce la struttura dati per la stampa
"Riparto Bilancio per Tabella × Soggetto". Usa un approccio **row-first**:
parte dai totali reali per soggetto (da `rate_quote.importo`) e li
distribuisce proporzionalmente tra le colonne del PDF usando i pesi
delle tabelle millesimali.
Questo approccio garantisce che la colonna `TOTALE SOGG.` nel PDF sia
bit-per-bit identica all'importo addebitato in contabilità — la cifra
legalmente rilevante per il condomino.
---
## Il Problema — Spostamento di Centesimi tra Colonne
### Sintomo osservato (Condominio T12, beta.10)
Il preventivo di Leonardo Gadotti prevede:
```
CT Centrale termica:  € 2.350,00
RI Riscaldamento:    €15.300,00
SC Scale:             € 3.100,00
AM Amministrative:    € 3.750,00
CO Condominio:        € 4.350,00
                    ──────────
TOTALE:              €28.850,00
```
Il PDF Kondomanager mostrava:
```
RISCALDAMENTO:  €15.299,96  ← 4 centesimi mancanti
GENERALE:       €22.755,05  ← 4 centesimi in eccesso (rispetto all'atteso €22.755,01)
```
Leonardo ha segnalato anche che in GENERALE, dove tutti gli appartamenti
hanno la stessa quota (1/6), i valori assoluti diminuivano di riga in
riga: 71, 69, 68, 64, 64.
### Causa meccanica — ordine di iterazione del penny-perfect
Nel loop di distribuzione per tabella, l'ultimo elemento riceve il resto
dell'arrotondamento (`importoTotale - $assegnato`):
```php
foreach ($tabIds as $idx => $tabId) {
    if ($idx === $nTab - 1) {
        // Ultimo: assorbe il resto (penny-perfect)
        $importiPerTab[$tabId] = $importoTotale - $assegnato;
    } else {
        $q = (int) round($importoTotale * ($pesPerTab[$tabId] / $peseTot));
        $importiPerTab[$tabId] = $q;
        $assegnato += $q;
    }
}
```
L'ordine di `$tabIds` dipende dall'ordine di inserimento in `$weights`,
che dipende dall'ordine di processamento dei conti nel piano dei conti.
In T12, GENERALE è l'ultima tabella nell'iterazione → assorbe tutti i
±1 cent di arrotondamento di RISCALDAMENTO e ASCENSORE.
Con 6 soggetti × piccoli arrotondamenti per soggetto = accumulo di 4
centesimi su RISCALDAMENTO che migrano su GENERALE.
### Perché i valori uguali in GENERALE "diminuiscono di riga in riga"
Questo è un effetto separato ma correlato. Con quote uguali (1/6), ci si
aspetterebbe lo stesso importo assoluto per ogni soggetto in GENERALE.
In realtà variano perché il service usa il totale reale di ogni soggetto
come base:
```
importo_GENERALE_soggetto = importo_totale_soggetto × (peso_GENERALE / peso_totale_soggetto)
```
Chi ha millesimi di RISCALDAMENTO alti (es. Coletti 194,119) ha un
`peso_totale_soggetto` più alto → il rapporto `peso_GENERALE / peso_totale`
è più basso → l'importo assoluto in GENERALE è più basso, anche con gli
stessi millesimi generali.
Esempio su T12:
```
Nardelli (RISC 161,191): peso_RISC alto → GENERALE = €2.091,69
Coletti  (RISC 194,119): peso_RISC molto alto → GENERALE = €2.091,64
```
Questa variazione è strutturale nell'approccio row-first. Non è un bug
— è la conseguenza di come il calcolo è strutturato.
---
## Distinzione Architetturale: Row-First vs Column-First
### Kondomanager — Row-First (approccio attuale)
```
rate_quote.importo → totale_soggetto → distribuisce per tabella
```
**Garanzia:** `TOTALE SOGG.` nel PDF = importo addebitato in contabilità,
bit-per-bit. La cifra legalmente rilevante è sempre esatta.
**Trade-off:** i totali di colonna per tabella possono differire di
±qualche cent rispetto al preventivo.
### Danea — Column-First
```
preventivo_tabella → distribuisce per soggetto → somma per riga
```
**Garanzia:** i totali di colonna battono esattamente con il preventivo.
**Trade-off:** le righe per soggetto possono differire di ±1 cent
dall'importo effettivamente addebitato in contabilità.
### Quale è più corretto?
Kondomanager. La cifra che il condomino deve pagare è quella azionabile
legalmente in caso di morosità (art. 63 disp. att. c.c.). Deve essere
esatta. Le colonne del riparto per tabella sono un documento di
trasparenza — mostrano come è composto il debito, ma non sono la cifra
azionabile. Nessun tribunale ha mai contestato un decreto ingiuntivo
per uno scostamento di centesimi nella colonna RISCALDAMENTO di un
documento di bilancio preventivo.
---
## Fix Pianificato — v1.10
### Fase 1 — Quick Win (una riga)
Ordinare `$tabIds` per peso crescente prima del loop penny-perfect.
Il resto dell'arrotondamento va alla tabella con peso maggiore
(RISCALDAMENTO), dove è meno visibile e proporzionalmente irrilevante.
```php
// In buildMatrice(), prima del loop di distribuzione:
$tabIds = array_keys($pesPerTab);
usort($tabIds, fn($a, $b) => $pesPerTab[$a] <=> $pesPerTab[$b]);
```
**Risultato:** RISCALDAMENTO mostra €15.300,00 invece di €15.299,96.
Il "diminuisce di riga in riga" in GENERALE rimane ma si riduce perché
il resto non migra più su GENERALE.
**Costo:** una riga. Nessun rischio di regressione sui totali soggetto
(sono sempre da `rate_quote`, non coinvolti).

> **Aggiornamento implementativo (2026-07-01):** la Fase 1 è stata applicata
> in `app/Services/RipartoTabelleService.php` (`buildMatrice()`), insieme a un
> arrotondamento di precisione separato (`round($totAbs * $w, 8)` in
> `CalcoloQuoteService::distribuisciImporto()` e sul rapporto peso/pesoTotale
> in `RipartoTabelleService`) introdotto durante la migrazione a PHP 8.4.
> Verificato con `RipartoEsattoTest`: dopo l'aggiunta dell'`usort`, il totale
> RISCALDAMENTO sul caso T12 esce a **€15.299,99** (1 cent di scostamento),
> leggermente diverso dall'esatto €15.300,00 osservato prima dell'ordinamento
> con il solo fix di arrotondamento. Il test passa comunque perché usa una
> tolleranza di ±2 cent (`toBeBetween(1529998, 1530002)`) e non l'uguaglianza
> esatta originariamente suggerita in questo documento — la tolleranza è
> stata mantenuta deliberatamente perché i due fix (arrotondamento di
> precisione + ordinamento) non compongono in modo da garantire zero
> scostamento in ogni combinazione di dati — resta valida solo la Fase 2
> per una garanzia strutturale di esattezza colonna-per-colonna.

### Fase 2 — Approccio Ibrido (v1.10, architetturale)
Ristrutturare `RipartoTabelleService` per calcolare i valori per colonna
direttamente per voce di preventivo, invece di fare reverse-engineering
dai totali reali.
**Algoritmo target:**
```
Per ogni conto foglia con importo nel piano:
  Per ogni tabella collegata:
    importo_tabella_conto = importo × coeff/100
    Per ogni immobile:
      peso = millesimi_immobile / tot_millesimi_tabella
      Per ogni soggetto (con cascata ruolo):
        importo_soggetto_tabella_conto = importo_tabella_conto × peso × quota_soggetto
        → accumula in matrice[tabella][soggetto]
  
  Penny-perfect per conto: distribuzione esatta sull'importo della voce
```
**Risultato:** i totali di colonna batteranno esattamente con il
preventivo. Il "diminuisce di riga in riga" scompare. I totali per
soggetto resteranno leggermente diversi dai `rate_quote` (differenza
di ±qualche cent), ma la colonna `TOTALE SOGG.` verrà calcolata come
somma delle colonne → battono con il preventivo, non con la contabilità.
**Trade-off accettabile:** per un documento di bilancio preventivo
(non un estratto conto), la coerenza con il preventivo è preferibile
alla coerenza con i saldi contabili. I due documenti hanno scopi diversi.
**Dipendenza:** richiede che `piano_rate.snapshot_at` sia implementato
(v1.10 Piano Rate Snapshot Architecture) per evitare divergenze post-generazione.

> **Stato (2026-07-01):** non ancora avviata. Verificato che `snapshot_at`
> non esiste in nessuna migration/model del progetto — la dipendenza
> dichiarata sopra non è ancora soddisfatta. Da riprendere solo dopo aver
> implementato lo snapshot, oppure accettando esplicitamente il rischio di
> divergenza post-generazione come debito tecnico temporaneo.
---
## Test di Regressione
Il test `RipartoEsattoTest.php` scritto durante la beta.10 con i dati
reali di T12 è il test di riferimento per entrambi i fix.
Dopo la Fase 1, aggiornare il test con:
```php
expect($matrice['tot_per_tabella'][$riscTabId])->toBe(1530000); // €15.300,00 esatti
```
Dopo la Fase 2, aggiungere:
```php
// Ogni totale di colonna deve battere con il preventivo
expect($matrice['tot_per_tabella'][$ctTabId])->toBe(235000);   // CT €2.350,00
expect($matrice['tot_per_tabella'][$riscTabId])->toBe(1530000); // RI €15.300,00
expect($matrice['tot_per_tabella'][$scTabId])->toBe(310000);   // SC €3.100,00
```

> **Nota (2026-07-01):** l'asserzione esatta suggerita sopra per la Fase 1
> (`toBe(1530000)`) NON è stata applicata al test — con i dati reali di T12
> e il fix attuale il valore osservato è 1529999, non 1530000. Il test
> mantiene la tolleranza `toBeBetween(1529998, 1530002)` fino a quando la
> Fase 2 non garantirà l'esattezza colonna-per-colonna in modo strutturale.
---
## Documento di Riferimento T12
Il file `tests/fixtures/T12/16Preventivo.pdf` (Bilancio preventivo
01/11/2025 - 31/10/2026, Condominio T12, Leonardo Gadotti) contiene
i valori target per ogni colonna del riparto:
| Capitolo             | Importo atteso |
|----------------------|----------------|
| AM Amministrative    | €3.750,00      |
| CO Condominio        | €4.350,00      |
| CT Centrale termica  | €2.350,00      |
| RI Riscaldamento     | €15.300,00     |
| SC Scale             | €3.100,00      |
| **TOTALE**           | **€28.850,00** |
Nota: il riparto Danea aggrega AM + CO + parte di SC in "GENERALE" e
"PROPRIETÀ" — la struttura delle tabelle millesimali determina come
questi capitoli vengono raggruppati nelle colonne del PDF.
---
## Note per lo Sviluppo v1.10
- La Fase 1 può essere rilasciata indipendentemente, anche come patch
  della v1.9.x se Leonardo o altri beta-tester segnalano il problema.
- La Fase 2 va coordinata con l'implementazione di `snapshot_at` per
  garantire coerenza tra la data di generazione del piano e i dati usati
  dalla stampa.
- Dopo la Fase 2, aggiornare l'ADR in `docs/architecture/` per
  documentare il cambio da row-first a ibrido per la stampa riparto.
- Il `CalcoloQuoteService` rimane row-first — la modifica riguarda solo
  la stampa PDF, non il motore di calcolo delle rate.

---
## APPENDICE — Risoluzione definitiva (2026-07-06)

### La scoperta chiave
`CalcoloQuoteService` applica GIÀ il metodo del resto più grande (Hamilton)
**per singolo conto** (`distribuisciImporto()`): al momento della generazione
delle rate esiste quindi una matrice intera esatta in cui sia le righe
(totali per soggetto = `rate_quote`) sia le colonne (budget per conto)
tornano perfettamente. Il difetto era SOLO nella stampa:
`RipartoTabelleService` scartava quella matrice e la ricostruiva
ridistribuendo i totali di riga sui pesi float, scaricando il resto
sull'ultima tabella (v1.9.1: ultima registrata, anche a peso zero → il
famoso "€0,01 su TUNNEL a chi non c'entra" segnalato da Gadotti su PAR).

### Il fix implementato (variante della Fase 2, senza i suoi rischi)
`RipartoTabelleService` ora ricostruisce le celle CONTO PER CONTO con lo
stesso identico algoritmo del motore rate (pesi identici, cascata ruolo
identica incl. `nuda_proprietario`, decurtazione scoperti identica, copia
1:1 di `distribuisciImporto()`). Risultato:
- **Colonne esatte**: ogni tabella somma al budget dei suoi conti; i
  centesimi di resto restano DENTRO i partecipanti del conto.
- **Righe esatte**: ogni totale soggetto coincide con `rate_quote` perché
  le allocazioni sono le stesse che hanno generato le rate.
- **Fallback di sicurezza**: se i dati sono cambiati dopo la generazione
  (o per quote extra: saldi/conguagli), il residuo di riga viene
  riallineato sulla tabella a peso maggiore del soggetto — la garanzia
  legale (riga = rate_quote) vale INCONDIZIONATAMENTE. Questo elimina la
  dipendenza da `piano_rate.snapshot_at` che bloccava la Fase 2 originale.

`CalcoloQuoteService` NON è stato toccato.

### Validazione
`tests/Feature/Riparto/RipartoCondominioParRealeTest.php` ricostruisce il
condominio PAR reale (44 unità, 8 tabelle, 23 conti, dati estratti dal DB
dell'amministratore) e verifica: i 44 totali `rate_quote` riprodotti al
centesimo; ogni colonna esatta al budget (ACQUA FISSO 2.200,00 — era
2.199,96; TUNNEL 800,00 — era 800,04); i 4 centesimi di ACQUA FISSO
spalmati sui partecipanti (4×104,77 + 17×104,76); TUNNEL di Telch Mario
(non partecipante) = 0 — era €0,01. Suite completa verde.

### Nota storica
Il fix "Fase 1" (usort per peso) e il successivo tentativo Hamilton
row-first sono superati: su dati reali PAR producevano risultati identici
tra loro e non risolvevano i totali di colonna. I relativi unit test sono
stati sostituiti dal test PAR completo.
