# 📖 Kondomanager Master Document: Gestione Debiti Pregressi e Double Lock

Questo documento definisce l'architettura logica e contabile per la gestione dei "Debiti Pregressi" (fatture ereditate da esercizi precedenti). Il sistema utilizza un meccanismo di validazione a doppio livello (**Double Lock**) per garantire la perfetta quadratura tra competenza economica (conto economico), situazione patrimoniale e liquidità reale (cassa).

---

## I 5 Scenari Definitivi (Tutti Gestiti)

L'interfaccia "Input Fatture 2.0" e il Service di backend sono progettati per intercettare e risolvere automaticamente le seguenti 5 casistiche reali.

### 1. Lo Scenario Ideale (Copertura Totale)
Il caso in cui la contabilità passata e la liquidità presente sono in perfetto allineamento.
* **Situazione:** Fattura pregressa di 1.000€. Rata 0 deliberata di 1.000€. Cassa disponibile di 1.000€.
* **Cosa fa il sistema:** Doppio semaforo 🟢 **Verde**. Il sistema registra il debito in Avere e lo chiude con la Rata 0 in Dare. Niente inquina il consuntivo del nuovo esercizio.

### 2. La Crisi di Liquidità (Il Semaforo Giallo)
Il caso in cui il bilancio è formalmente corretto, ma i condòmini sono morosi e mancano i fondi materiali per il bonifico.
* **Situazione:** Fattura pregressa di 1.000€. Rata 0 deliberata di 1.000€. Cassa disponibile di soli 300€.
* **Cosa fa il sistema:** Semaforo Contabile 🟢 **Verde** (tutto ok per la legge), ma Semaforo Finanziario 🟡 **Giallo** (alert cassa). Il Widget suggerisce il sollecito mirato ai morosi della Rata 0 o permette la registrazione di un pagamento parziale.

### 3. Il Proiettile Vagante (Zero Copertura)
Il caso della fattura "dimenticata" nel vecchio bilancio o arrivata in estremo ritardo, senza alcuna provvista approvata.
* **Situazione:** Fattura pregressa di 1.000€, ma dimenticata nel vecchio bilancio. Nessuna Rata 0 disponibile.
* **Cosa fa il sistema:** Semaforo 🔴 **Rosso**. Il sistema blocca il salvataggio patrimoniale e obbliga l'amministratore a cliccare su *"Converti in Sopravvenienza"*. Il debito vecchio scompare dallo Stato Patrimoniale e diventa formalmente una nuova spesa corrente dell'anno in corso.

### 4. Lo Split (Copertura Mista)
Il caso ibrido: la fattura è parzialmente coperta dai saldi ereditati, ma una quota risulta scoperta.
* **Situazione:** Fattura pregressa di 1.000€. Rata 0 disponibile solo per 700€.
* **Cosa fa il sistema:** L'interfaccia si sdoppia. Il Service spacca la Partita Doppia con precisione chirurgica: 700€ vengono scaricati sui saldi pregressi (patrimoniale), mentre i restanti 300€ vengono inseriti come spesa corrente (Sopravvenienza nel conto economico).

### 5. Il Salvagente (Fondo di Riserva)
Il caso in cui manca la Rata 0, ma l'amministratore decide di attingere a un accantonamento pregresso per non pesare sul nuovo bilancio.
* **Situazione:** Fattura pregressa senza copertura diretta, ma esiste un Fondo TFR o un Fondo Riserva capiente.
* **Cosa fa il sistema:** L'amministratore seleziona il fondo dal Widget. Il Service storna i soldi dalla passività del fondo selezionato, estinguendo il debito senza toccare il riparto spese corrente dei condòmini.