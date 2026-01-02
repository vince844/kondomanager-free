# Feature: Registrazione Incasso Rata (Ciclo Attivo)

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
| `pagamenti`              | Testata dell’incasso. Campi: `anagrafica_id`, `importo`, `data_pagamento`, `mezzo`, `note`, `eccedenza` (opzionale) |
| `pagamento_rata` (pivot) | Lega il pagamento alle rate. Campi: `pagamento_id`, `rata_id`, `importo` (quanto di questo pagamento copre questa rata) |

---

## 3. Note Importanti e Raccomandazioni Tecniche

### 3.1 Gestione del Saldo Iniziale

Il **Saldo Iniziale** (debito da esercizi precedenti) deve essere trattato come una rata ordinaria nell’interfaccia utente.

**Soluzione consigliata:**
- Nel frontend, generare la lista delle rate da pagare con una query che includa anche il Saldo Iniziale tramite `UNION`.
- Assegnare al Saldo Iniziale un **ID virtuale** (es. valore negativo come `-1` o `-999`).
- Nell’Action, riconoscere questo ID speciale e aggiornare il campo debito storico sull’anagrafica invece di una rata reale.

### 3.2 Raccomandazioni Tecniche

- **Centesimi obbligatori**: Converti tutti gli importi in interi (centesimi) prima dei calcoli e confronti usando `round($val * 100)`. Questo evita errori di virgola mobile.
- **Transazionalità garantita**: L’intera operazione è avvolta in `DB::transaction()` → se un passo fallisce, nulla viene registrato (coerenza assoluta).
- **Eccedenza futura**: Evolvi il campo `eccedenza` in un vero credito scalabile automaticamente sulle rate successive (crea tabella dedicata `crediti_condomini`).
- **Performance**: I campi `importo_pagato` e `stato` sulla tabella `rate` sono denormalizzati per letture veloci (pratica accettata e consigliata in questo contesto).
- **Sicurezza**: Validazione rigorosa dell’importo allocato vs totale versato.

---

**Questo modulo è solido, sicuro e perfettamente allineato alle esigenze operative degli amministratori di condominio italiani.**