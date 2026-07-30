# Guida Utente: Nuova Struttura Preventivi e Rateizzazione

**Versione:** 1.1 (aggiornata v1.9.23)  
**Data:** Aprile 2026  
**Contesto:** Gestionale Condominiale Enterprise  

---

## Introduzione

Con questa evoluzione del sistema, la gestione dei preventivi e della rateizzazione diventa **più flessibile e potente**, mantenendo una **stretta integrità contabile**.

**Le novità principali sono:**

- **Vincolo 1-a-1** tra Gestione (Ordinaria/Straordinaria) e Piano dei Conti: un solo preventivo per gestione.
- **Introduzione dei Capitoli di spesa**: raggruppamento logico delle voci di costo (es. per Scala o Fabbricato).
- **Possibilità di creare più Piani Rate** per la stessa Gestione.
- **Emissione di rate parziali** (Scenario Supercondominio) selezionando solo determinati Capitoli.
- **Piani Rate Straordinari** per finanziare fatture impreviste o addebiti ad personam (Art. 1135 c.c.).
- **Separazione visiva Preventivo / Sopravvenienze** nel Piano dei Conti (Art. 1130-bis c.c.).
- **Dashboard operativa** con alert in tempo reale su spese scoperte e azione diretta.

Queste modifiche permettono di gestire casi reali complessi (es. lavori straordinari che riguardano solo una scala, fatture impreviste, addebiti misti comune/privato) **senza ricorrere a gestioni separate fittizie**.

---

## Concetti Chiave

1. **Esercizio**  
   Il contenitore temporale (es. "Esercizio 2025").  
   Un condominio ha un solo esercizio ordinario attivo alla volta.

2. **Gestione**  
   Il contenitore giuridico/funzionale del bilancio. Esempi:  
   - Ordinaria  
   - Straordinaria Facciata  
   - Straordinaria Tetto  

   Ogni Gestione rappresenta un "bilancio separato" con la propria contabilità, come previsto dalla normativa.

3. **Piano dei Conti (Preventivo)**  
   - **Unico per Gestione** (vincolo 1-a-1).  
   - Contiene tutte le voci di spesa previste.  
   - **Integrità**: il sistema impedisce di creare preventivi duplicati per la stessa gestione.
   - **Separazione visiva**: l'albero dei conti mostra in due sezioni distinte il "Preventivo deliberato" e le "Sopravvenienze e imprevisti", con totali separati.

4. **Capitoli di Spesa**  
   Raggruppamento gerarchico all'interno del Piano dei Conti. Esempi:  
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
   - Piano Rate 1: "Rate Ordinarie" → include tutti i Capitoli (Generali + Scala A + Scala B).  
   - Piano Rate 2: "Lavori Scala A" → include solo il Capitolo "Scala A" (gli altri condòmini pagheranno 0 €).

6. **Tipi di Piano Rate**  
   - **Ordinario**: finanzia le voci del preventivo deliberato in assemblea. Può essere globale (tutte le voci) o parziale (solo alcuni capitoli).
   - **Straordinario (Art. 1135 c.c.)**: finanzia fatture impreviste o addebiti ad personam non presenti a preventivo. Richiede obbligatoriamente un'autorizzazione legale (delibera assembleare o urgenza documentata).

7. **Sopravvenienze e Imprevisti**  
   Quando si registra una fattura fuori preventivo, il sistema crea automaticamente una voce tecnica nel Piano dei Conti, marcata come "sopravvenienza". Queste voci:
   - Appaiono in una sezione separata dell'albero conti ("Sopravvenienze e imprevisti")
   - Non alterano il totale del preventivo deliberato in assemblea
   - Non sono selezionabili nel piano rate ordinario
   - Vengono finanziate esclusivamente tramite piani rate straordinari

---

## Flusso Operativo Consigliato

### Step 1: Creare il Piano dei Conti
1. Vai in **Gestioni** → seleziona una Gestione (es. Ordinaria 2025).
2. Se non esiste già un preventivo → clicca **"Crea Preventivo"**.
3. Inserisci le voci di spesa e **assegna un Capitolo** a ciascuna (es. "Pulizie" sotto "Spese Generali").
4. Salva.  
   Il sistema bloccherà automaticamente la creazione di ulteriori preventivi per questa gestione.

### Step 2: Creare i Piani Rate (Ordinari)
1. Nella stessa Gestione → sezione **Piani Rate** → **"Nuovo Piano Rate"**.
2. Assegna un nome significativo (es. "Rate Ordinarie Complete", "Contributo Lavori Scala A").
3. Seleziona i **Capitoli** da includere:  
   - **Tutti** → per la classica gestione ordinaria.  
   - **Selezione parziale** → per emettere rate solo su specifici centri di costo (scenario Supercondominio).
4. Definisci numero di rate, giorno di scadenza e eventuali arrotondamenti.
5. Il sistema genera automaticamente le rate, calcolando i millesimi **solo sulle voci dei Capitoli selezionati**.

> **Nota sulle date delle rate (agg. 2026-07-29).** Oggi puoi scegliere il **giorno del
> mese** di scadenza, non il mese di partenza: la prima rata cade sempre nel mese di
> inizio della gestione. Il campo "data prima scadenza" (con calendario manuale e
> modifica delle date già generate) è pianificato — vedi [`calendario_rate.md`](calendario_rate.md).

### Step 2-bis: Gestire Spese Impreviste (Piano Straordinario)

Quando arriva una fattura non prevista a bilancio (es. guasto urgente, multa, intervento d'emergenza), il flusso è diverso:

1. **Registra la fattura** con il flag "Sopravvenienza" attivo. Se la spesa riguarda un singolo immobile, seleziona anche l'unità immobiliare destinataria.
2. **Il sistema segnala automaticamente** la spesa scoperta nel widget Dashboard con l'alert "Sotto Copertura".
3. **Dalla Dashboard** → clicca "Analizza voci" → nella modale trovi la fattura con il dettaglio riga per riga (parte comune vs addebito personale).
4. **Clicca "Finanzia spesa"**: il sistema ti reindirizza al wizard di creazione Piano Rate con la fattura già pre-selezionata nel carrello.
5. **Compila lo Scudo Legale** (obbligatorio): scegli tra "Delibera Assembleare" o "Urgenza Art. 1135 c.c." e inserisci la motivazione.
6. **Salva**: il sistema genera le rate, calcolando automaticamente le quote millesimali per la parte comune e l'addebito diretto al proprietario per la parte ad personam.

> **Nota:** Puoi anche aggiungere altre fatture scoperte al carrello prima di salvare. Un singolo piano straordinario può finanziare più fatture contemporaneamente.

### Step 3: Registrare Incassi
- La registrazione incassi ora supporta **pagamenti che coprono rate di gestioni diverse**.
- L'importo viene allocato correttamente alle rate corrispondenti.
- La reportistica di quadratura gestisce automaticamente la riconciliazione.

---

## Vantaggi della Nuova Struttura

| Vantaggio                      | Descrizione                                                                 |
|--------------------------------|-----------------------------------------------------------------------------|
| **Integrità contabile**        | Vincolo 1-a-1 Piano Conti → nessuna duplicazione o ambiguità sui preventivi |
| **Flessibilità reale**         | Rate parziali per lavori su singola scala o fabbricato (Supercondominio)    |
| **Chiarezza**                  | Bollettini distinti per spese ordinarie vs straordinarie specifiche         |
| **Prevenzione errori**         | L'interfaccia impedisce duplicati e guida la selezione dei Capitoli         |
| **Precisione**                 | Calcolo matematico sui centesimi con gestione automatica degli arrotondamenti |
| **Trasparenza Art. 1130-bis**  | Sopravvenienze separate dal preventivo — l'assemblea vede esattamente cosa era previsto e cosa no |
| **Audit Trail Legale**         | Ogni piano straordinario traccia autorizzazione, motivazione e timestamp di approvazione |
| **Dashboard Operativa**        | Alert in tempo reale su spese scoperte con azione diretta (deep-link al wizard) |

---

**Benvenuto nella nuova era della gestione condominiale: più flessibile, più precisa e sempre rigorosamente contabile.**