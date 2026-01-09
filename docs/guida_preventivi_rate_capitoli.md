# Guida Utente: Nuova Struttura Preventivi e Rateizzazione

**Versione:** 1.0  
**Data:** 01 Gennaio 2026  
**Contesto:** Gestionale Condominiale Enterprise  

---

## Introduzione

Con questa evoluzione del sistema, la gestione dei preventivi e della rateizzazione diventa **più flessibile e potente**, mantenendo una **stretta integrità contabile**.

**Le novità principali sono:**

- **Vincolo 1-a-1** tra Gestione (Ordinaria/Straordinaria) e Piano dei Conti: un solo preventivo per gestione.
- **Introduzione dei Capitoli di spesa**: raggruppamento logico delle voci di costo (es. per Scala o Fabbricato).
- **Possibilità di creare più Piani Rate** per la stessa Gestione.
- **Emissione di rate parziali** (Scenario Supercondominio) selezionando solo determinati Capitoli.

Queste modifiche permettono di gestire casi reali complessi (es. lavori straordinari che riguardano solo una scala) **senza ricorrere a gestioni separate fittizie**.

---

## Concetti Chiave

1. **Esercizio**  
   Il contenitore temporale (es. “Esercizio 2025”).  
   Un condominio ha un solo esercizio ordinario attivo alla volta.

2. **Gestione**  
   Il contenitore giuridico/funzionale del bilancio. Esempi:  
   - Ordinaria  
   - Straordinaria Facciata  
   - Straordinaria Tetto  

   Ogni Gestione rappresenta un “bilancio separato” con la propria contabilità, come previsto dalla normativa.

3. **Piano dei Conti (Preventivo)**  
   - **Unico per Gestione** (vincolo 1-a-1).  
   - Contiene tutte le voci di spesa previste.  
   - **Integrità**: il sistema impedisce di creare preventivi duplicati per la stessa gestione.

4. **Capitoli di Spesa**  
   Raggruppamento gerarchico all’interno del Piano dei Conti. Esempi:  
   - Spese Generali  
   - Scala A  
   - Scala B  
   - Ascensore  

   Permettono di suddividere le spese in sotto-insiemi significativi, fondamentali per la ripartizione mirata.

5. **Piani Rate**  
   - **Molteplici per Gestione** (N-a-1).  
   - Ogni Piano Rate definisce come e quando chiedere i contributi ai condòmini.  
   - **Novità**: è possibile selezionare solo determinati Capitoli per creare rate mirate.

   **Esempi pratici:**  
   - Piano Rate 1: “Rate Ordinarie” → include tutti i Capitoli (Generali + Scala A + Scala B).  
   - Piano Rate 2: “Lavori Scala A” → include solo il Capitolo “Scala A” (gli altri condòmini pagheranno 0 €).

---

## Flusso Operativo Consigliato

### Step 1: Creare il Piano dei Conti
1. Vai in **Gestioni** → seleziona una Gestione (es. Ordinaria 2025).
2. Se non esiste già un preventivo → clicca **“Crea Preventivo”**.
3. Inserisci le voci di spesa e **assegna un Capitolo** a ciascuna (es. “Pulizie” sotto “Spese Generali”).
4. Salva.  
   Il sistema bloccherà automaticamente la creazione di ulteriori preventivi per questa gestione.

### Step 2: Creare i Piani Rate
1. Nella stessa Gestione → sezione **Piani Rate** → **“Nuovo Piano Rate”**.
2. Assegna un nome significativo (es. “Rate Ordinarie Complete”, “Contributo Lavori Scala A”).
3. Seleziona i **Capitoli** da includere:  
   - **Tutti** → per la classica gestione ordinaria.  
   - **Selezione parziale** → per emettere rate solo su specifici centri di costo (scenario Supercondominio).
4. Definisci numero di rate, data prima scadenza e eventuali arrotondamenti.
5. Il sistema genera automaticamente le rate, calcolando i millesimi **solo sulle voci dei Capitoli selezionati**.

### Step 3: Registrare Incassi
- La registrazione incassi ora supporta **pagamenti che coprono rate di gestioni diverse**.
- L’importo viene allocato correttamente alle rate corrispondenti.
- La reportistica di quadratura gestisce automaticamente la riconciliazione.

---

## Vantaggi della Nuova Struttura

| Vantaggio              | Descrizione                                                                 |
|------------------------|-----------------------------------------------------------------------------|
| **Integrità contabile**    | Vincolo 1-a-1 Piano Conti → nessuna duplicazione o ambiguità sui preventivi |
| **Flessibilità reale**     | Rate parziali per lavori su singola scala o fabbricato (Supercondominio)    |
| **Chiarezza**              | Bollettini distinti per spese ordinarie vs straordinarie specifiche         |
| **Prevenzione errori**     | L’interfaccia impedisce duplicati e guida la selezione dei Capitoli         |
| **Precisione**             | Calcolo matematico sui centesimi con gestione automatica degli arrotondamenti |

---

**Benvenuto nella nuova era della gestione condominiale: più flessibile, più precisa e sempre rigorosamente contabile.**