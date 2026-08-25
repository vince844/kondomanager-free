# Feature: Registrazione Incasso Rata (Ciclo Attivo)

<!-- verifica-documentazione -->
> **Stato:** Contiene affermazioni false — verificato il 31/07/2026 su 1.10.0-beta.32, riverificato il 01/08/2026 su 1.10.0-beta.33, riverificato il 05/08/2026 su 1.10.0-beta.46
> Documento da archiviare: lo schema dati che descrive (tabelle `pagamenti` e `pagamento_rata`, saldo iniziale con ID virtuale) non è mai esistito in questa forma; conserva valore solo l'elenco degli obiettivi funzionali del §1.
> La beta.46 ha cambiato due comportamenti visibili della pagina di incasso — il banner «Richiesta di compensazione», che era invertito, e il pulsante «Usa credito» sulle righe a saldo misto, che prima non compariva — ma **nessuna riga di questo documento è diventata falsa per questo**: il documento si ferma allo schema dati e agli obiettivi, e la schermata non la descrive. Le rettifiche già presenti sono state ricontrollate una per una e valgono ancora tutte.
> ⚠️ Il §3.2 contiene **due ricette da non seguire**: `round($val * 100)` sugli importi (riproduce il bug del ×100 corretto nella beta.32) e `rate.importo_pagato` (colonna inesistente). Vedi le rettifiche in linea.
<!-- /verifica-documentazione -->

**Versione:** 1.0.0  
**Data:** 01 Gennaio 2026  
**Contesto:** Gestionale Condominiale Enterprise  

---

## 1. Descrizione Funzionale

Questa funzionalità permette all’amministratore di registrare un pagamento ricevuto da un condòmino (o inquilino).

**Obiettivi principali:**
- Registrare un importo totale ricevuto, con data e mezzo di pagamento.
- Allocare l’importo a una o più rate specifiche selezionate dall’utente.
- Gestire **pagamenti parziali** (la rata rimane aperta, ma con debito residuo ridotto).
- Gestire **eccedenze** (se il condòmino paga più del dovuto → l’eccesso diventa “Anticipo” o “Credito a scalare”).
- **Requisito speciale**: Il **Saldo Iniziale** (debito proveniente da esercizi precedenti) deve apparire nell’elenco delle rate da pagare e poter essere saldato esattamente come una rata ordinaria.

---

## 2. Struttura Database (Relazioni Coinvolte)

| Tabella                  | Descrizione                                                                                                   |
|--------------------------|---------------------------------------------------------------------------------------------------------------|
| `rate`                   | Scadenze emesse. Campi principali: `importo_totale`, `importo_pagato`, `stato` (`da_pagare`, `parziale`, `pagata`) |

<!-- rettifica -->
> ⚠️ **Non è più vero — verificato il 31/07/2026 su 1.10.0-beta.32.** `rate` non ha `importo_pagato`, e il suo `stato` è `bozza`/`emessa`/`chiusa` (stato di emissione, non di pagamento). `importo_pagato` e lo stato di pagamento (`da_pagare`, `parzialmente_pagata`, `pagata`, `annullata`, `credito`) stanno su `rate_quote`, cioè al livello del singolo condòmino. La confusione fra i due livelli è l'errore centrale del documento.
> *Prova:* database/migrations/2025_11_05_093311_create_rate_table.php:22-23; database/migrations/2025_11_05_093418_create_rate_quote_table.php:20-21.
<!-- /rettifica -->
| `pagamenti`              | Testata dell’incasso. Campi: `anagrafica_id`, `importo`, `data_pagamento`, `mezzo`, `note`, `eccedenza` (opzionale) |

<!-- rettifica -->
> ⚠️ **Non è più vero — verificato il 31/07/2026 su 1.10.0-beta.32.** La pivot reale è `quota_scrittura`, e lega la QUOTA del singolo condòmino (`rate_quota_id`) alla scrittura (`scrittura_contabile_id`), non il pagamento alla rata. I campi sono `importo_pagato` e `data_pagamento`.
> *Prova:* grep -rn "pagamento_rata" su app/ database/ = 0 risultati; database/migrations/2025_12_26_105535_create_quota_scrittura_table.php:16-19; app/Models/Gestionale/RataQuote.php:63-73 (la relazione `pagamenti()` punta a `quota_scrittura`).
<!-- /rettifica -->

<!-- rettifica -->
> ⚠️ **Non è più vero — verificato il 31/07/2026 su 1.10.0-beta.32.** Non esiste nessuna tabella `pagamenti`. La testata dell'incasso è una `ScritturaContabile` con `tipo_movimento = incasso_rata`. L'unica tabella di pagamenti è `pagamenti_fornitori`, che riguarda il ciclo passivo.
> *Prova:* grep -rn "Schema::create('pagament" su database/migrations/ = un solo risultato, database/migrations/2026_05_20_062652_create_pagamenti_fornitori_table.php:36; app/Actions/Gestionale/Movimenti/StoreIncassoRateAction.php crea una ScritturaContabile, non un record `pagamenti`.
<!-- /rettifica -->
| `pagamento_rata` (pivot) | Lega il pagamento alle rate. Campi: `pagamento_id`, `rata_id`, `importo` (quanto di questo pagamento copre questa rata) |

---

## 3. Note Importanti e Raccomandazioni Tecniche

### 3.1 Gestione del Saldo Iniziale

Il **Saldo Iniziale** (debito da esercizi precedenti) deve essere trattato come una rata ordinaria nell’interfaccia utente.

**Soluzione consigliata:**
- Nel frontend, generare la lista delle rate da pagare con una query che includa anche il Saldo Iniziale tramite `UNION`.

<!-- rettifica -->
> ⚠️ **Non è più vero — verificato il 31/07/2026 su 1.10.0-beta.32.** Il saldo iniziale è una vera riga di `rate_quote` con `tipo = 'saldo_iniziale'`, quindi si incassa come qualsiasi altra quota, senza ID virtuali né UNION. Non esiste nessun campo "debito storico" sull'anagrafica.
> *Prova:* database/migrations/2026_02_18_230829_add_tipo_and_esercizio_origine_to_rate_quote_table.php:21 (colonna `tipo`, commento «ordinaria, saldo_iniziale, conguaglio, straordinaria»); uso in app/Http/Resources/Gestionale/PianiRate/PianoRateResource.php:67; grep -rn "debito_storico" su app/ database/ resources/js/ = 0 risultati; app/Services/Gestionale/IncassoRateService.php:126 tratta la quota saldo_iniziale come quota normale.
<!-- /rettifica -->
- Assegnare al Saldo Iniziale un **ID virtuale** (es. valore negativo come `-1` o `-999`).
- Nell’Action, riconoscere questo ID speciale e aggiornare il campo debito storico sull’anagrafica invece di una rata reale.

### 3.2 Raccomandazioni Tecniche

<!-- rettifica -->
> ⚠️ **Pericolosa — verificato il 01/08/2026 su 1.10.0-beta.33.** Questa raccomandazione, seguita alla lettera, **riproduce un bug che è già costato una beta**. Nel codice gli importi sono in centesimi già appena passato il confine HTTP: la conversione si fa **una volta sola**, con `MoneyHelper::toCents()`, che è anche l'unico punto capace di leggere le stringhe mascherate del frontend (`"1.200,50"`). Un `round($val * 100)` applicato a un valore già convertito lo moltiplica una seconda volta: è esattamente ciò che faceva `GenerateSaldiAction`, dove un riparto manuale di 250,00 € finiva addebitato come 25.000,00 €. Corretto nella beta.32.
> *Prova:* app/Helpers/MoneyHelper.php:7 (`toCents()`); app/Http/Controllers/Gestionale/PianiRate/PianoRateController.php:395 (conversione, una sola volta, al confine di ingresso); app/Actions/PianoRate/GenerateSaldiAction.php (commento «CENTESIMI, non euro. Il chiamante ha già convertito con MoneyHelper::toCents» al posto del vecchio `* 100`).
> **La regola vera:** convertire al confine di ingresso e mai più. Se un valore arriva dal DB, da un modello o da un'altra Action, è **già** in centesimi.
<!-- /rettifica -->
- **Centesimi obbligatori**: Converti tutti gli importi in interi (centesimi) prima dei calcoli e confronti usando `round($val * 100)`. Questo evita errori di virgola mobile.
- **Transazionalità garantita**: L’intera operazione è avvolta in `DB::transaction()` → se un passo fallisce, nulla viene registrato (coerenza assoluta).
- **Eccedenza futura**: Evolvi il campo `eccedenza` in un vero credito scalabile automaticamente sulle rate successive (crea tabella dedicata `crediti_condomini`).

<!-- rettifica -->
> ⚠️ **Non è più vero — verificato il 31/07/2026 su 1.10.0-beta.32.** Il credito scalabile esiste già, ma con un altro nome: tabella `crediti_residui` (+ `CreditoService`). `crediti_condomini` nel codice non è una tabella: è un `ruolo` del piano dei conti contabile. Il documento manda l'autore a costruire una cosa fatta e a chiamarla come un'altra cosa esistente.
> *Prova:* database/migrations/2025_11_11_053936_create_crediti_residui_table.php; app/Services/Gestionale/CreditoService.php; `crediti_condomini` compare solo come `->where('ruolo', 'crediti_condomini')` (app/Services/Gestionale/FatturaPassivaService.php:373, app/Actions/Gestionale/Movimenti/StoreIncassoRateAction.php:54).
<!-- /rettifica -->
<!-- rettifica -->
> ⚠️ **Non è vero — verificato il 01/08/2026 su 1.10.0-beta.33.** La tabella `rate` **non ha** un campo `importo_pagato`, e il suo `stato` non è uno stato di pagamento: è lo stato di *emissione* (`bozza` / `emessa` / `chiusa`). I campi denormalizzati per letture veloci stanno su `rate_quote`, cioè sulla quota del singolo condòmino. Chi scrive una query su `rate.importo_pagato` non ottiene un numero sbagliato: ottiene un errore SQL.
> *Prova:* database/migrations/2025_11_05_093311_create_rate_table.php:22-23 (solo `importo_totale` e `enum stato ['bozza','emessa','chiusa']`); database/migrations/2025_11_05_093418_create_rate_quote_table.php:20 (`importo_pagato` su `rate_quote`).
<!-- /rettifica -->
- **Performance**: I campi `importo_pagato` e `stato` sulla tabella `rate` sono denormalizzati per letture veloci (pratica accettata e consigliata in questo contesto).
- **Sicurezza**: Validazione rigorosa dell’importo allocato vs totale versato.

---

**Questo modulo è solido, sicuro e perfettamente allineato alle esigenze operative degli amministratori di condominio italiani.**
