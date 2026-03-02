# ADR-001 — Architettura Saldi Iniziali (v1.9)

**Data:** 2026
**Stato:** Approvato
**Modulo:** Gestionale → Struttura → Saldi Iniziali

## Contesto
Kondomanager deve permettere agli amministratori condominiali di configurare la situazione contabile di partenza (debiti e crediti pregressi) quando:
1. Aprono un nuovo esercizio.
2. Migrano da un altro gestionale (PIGC, Danea Domustudio, Excel, ecc.).
3. Gestiscono subentri nel corso dell'anno.

---

## Decisioni prese

### 1. `anagrafica_id` è nullable

| Valore | Significato | Caso d'uso |
| :--- | :--- | :--- |
| **Valorizzato** | **Saldo personale** — legato a quel soggetto specifico | Debito noto di Mario Rossi; spesa *ad personam*; migrazione da gestionale con nomi definiti. |
| **NULL** | **Saldo solidale** — legato all'immobile | PDF di migrazione senza nomi; debito solidale Art. 63; situazione ancora da definire. |

**Perché non solo NULL (modello immobile-centrico):**
L'amministratore che migra da un altro gestionale ha spesso in mano un PDF con i nomi. Forzarlo ad astrarre tutto sull'immobile aumenta drasticamente l'attrito di onboarding e fa perdere un'informazione già disponibile.

**Perché non solo valorizzato (anagrafica obbligatoria):**
Non tutti i PDF di migrazione hanno i nomi. Bloccare l'inserimento senza anagrafica renderebbe la migrazione impossibile per una quota significativa di amministratori.

**Riferimento normativo:**
**Art. 63 disp. att. c.c.** — *Chi subentra nei diritti di un condomino è obbligato solidalmente per i contributi dell'anno in corso e dell'anno precedente.* Il saldo solidale sull'immobile è la rappresentazione corretta di un debito che "segue la casa".

### 2. Constraint univoco con colonna sentinella
MySQL non supporta *partial unique index* agili. La soluzione adottata per impedire duplicati di saldi solidali è una colonna virtuale `anagrafica_id_key` gestita a livello DB:
* Se `anagrafica_id` è valorizzato $\rightarrow$ `anagrafica_id_key = anagrafica_id`
* Se `anagrafica_id` è NULL $\rightarrow$ `anagrafica_id_key = 0`

**Constraint:** `UNIQUE(esercizio_id, gestione_id, immobile_id, anagrafica_id_key)`
Questa struttura garantisce:
✅ Al massimo un saldo solidale per (esercizio, gestione, immobile).
✅ Al massimo un saldo personale per (esercizio, gestione, immobile, anagrafica).
✅ Blindatura applicativa aggiuntiva nel Controller tramite `store()`.

### 3. `saldo_finale` rimosso
Il saldo finale non va **mai** persistito nel DB, va sempre calcolato al volo:
`Saldo Finale = Saldo Iniziale + Rate Emesse - Incassi Registrati`
Mantenerlo esporrebbe il sistema a disallineamenti garantiti.

### 4. Valori monetari in centesimi (bigInteger)
`saldo_iniziale` è un `bigInteger` che rappresenta centesimi (`10000` = €100,00). 
Evita floating point errors nei calcoli di ripartizione pro-quota. La conversione euro $\leftrightarrow$ centesimi avviene esclusivamente nei layer Frontend e Output.

### 5. Saldi separati per gestione
Ogni saldo è vincolato a una `gestione_id` specifica (Ordinaria, Straordinaria). Rispetta l'Art. 1130-bis c.c. (separazione dei fondi) e garantisce un audit trail perfetto.

---

## Flusso completo: dalla migrazione al subentro

**ONBOARDING (migrazione da altro gestionale)**
* **Admin ha nomi** $\rightarrow$ Saldo con `anagrafica_id` valorizzato (*"Rossi deve €500 per Ordinaria"*).
* **Admin non ha nomi** $\rightarrow$ Saldo solidale (`anagrafica_id = NULL`) (*"Int. 1A deve €500"*).

↓

**GENERAZIONE PIANO RATE**
* **Saldo con anagrafica_id** $\rightarrow$ Genera "Rata 0" intestata a quella persona.
* **Saldo solidale (NULL)** $\rightarrow$ 
    1. Cerca proprietari attivi sull'immobile (`tipo_rapporto = 'proprietario'`, `attivo = true`).
    2. Distribuisce pro-quota (Art. 1123 c.c.).
    3. Applica "Adjust Remainder" (Compensazione scarto centesimi).
    4. *Proprietari assenti* $\rightarrow$ ALERT BLOCCANTE.

↓

**SUBENTRO (Rossi vende a Verdi)**
1. Rata 0 di Rossi non pagata.
2. Sistema applica Art. 63 (Solidarietà del subentrante).
3. Storna Rata 0 a Rossi e Genera nuova Rata 0 a Verdi.
4. **Audit trail perfetto:** Rossi $\rightarrow$ Storno $\rightarrow$ Verdi.

---

## Regole di distribuzione pro-quota (Generatore Rate)
```php
// Riferimento: Art. 1123 c.c. + Art. 63 disp. att. c.c.
$saldo = $saldoSolidale->saldo_iniziale;
$sommaAssegnata = 0;

// Caso A: Quote presenti sul pivot
if ($totaleQuote > 0) {
    foreach ($proprietari as $index => $proprietario) {
        $percentuale = $proprietario->pivot->quota / $totaleQuote;
        $importo = (int) round($saldo * $percentuale);
        
        // Adjust Remainder (Ultimo condomino assorbe lo scarto per quadratura)
        if ($index === $proprietari->count() - 1) {
            $importo = $saldo - $sommaAssegnata;
        }
        $sommaAssegnata += $importo;
        // → Genera Rata 0
    }
} 
// Caso B: Quote assenti (Fallback legale)
else {
    $importoPerTesta = (int) round($saldo / $proprietari->count());
    // Log: "Warning: Immobile senza quote, ripartizione in parti uguali applicata."
    // Applica logic Adjust Remainder su ultimo proprietario
}