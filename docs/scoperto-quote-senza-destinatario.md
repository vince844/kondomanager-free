# Gestione Quote "Scoperte" (Senza Destinatario)

## Contesto e Problema
Durante la generazione di un Piano Rate Ordinario (preventivo), il motore di riparto calcola le quote spesa per ogni unità immobiliare (`immobile`). 
Tuttavia, può accadere che un'unità non abbia alcuna anagrafica attiva o ruolo idoneo in quel momento (es. unità invenduta, disabitata, in attesa di rogito). 
Questo genera una "quota orfana" che non può essere rateizzata a nessun soggetto. Il motore si ferma, in quanto l'importo totale delle rate emesse non coprirebbe il fabbisogno del preventivo.

In passato, l'amministratore era costretto a inserire un'anagrafica fittizia (es. "Proprietà Costruttore" o "Condominio") per far quadrare i conti, inquinando sia i saldi che il registro anagrafico.

## Decisione: Forzatura Consapevole (v1.9.1)
Abbiamo scelto di permettere all'amministratore di "forzare" la generazione del piano rate accettando il deficit, subordinandolo all'inserimento di una **motivazione esplicita e tracciabile**.

### Flusso implementato:
1. **Gatekeeper:** `GeneratePianoRateAction` rileva l'anomalia e lancia l'eccezione silenziosa `ScopertiNonAccettatiException`.
2. **UI Bloccante:** Il frontend intercetta l'eccezione e mostra un banner che richiede una giustificazione testuale (>10 caratteri).
3. **Persistenza:** Il backend salva la giustificazione in `piani_rate.nota_scoperti`. L'importo della quota orfana **NON** viene persistito come riga contabile, poiché non è associabile ad un debitore reale.
4. **Task Inbox:** Viene creato un Task di sistema assegnato all'admin, persistente finché non chiuso manualmente, per evitare che la quota venga dimenticata.
5. **Widget Copertura:** La dashboard rileva la `nota_scoperti` e pone lo stato della gestione in `documented` (QUOTA APERTA), silenziando i falsi allarmi contabili.

## Il Recupero Contabile della Quota Orfana
Se il piano rate viene "Emesso" prima che l'anagrafica mancante venga censita, il piano non è più ricalcolabile. Come si richiede l'importo al nuovo proprietario?

**Alternativa scartata (Anti-Pattern): Creare un Piano Rate Straordinario.**
I piani straordinari (Art. 1135 c.c.) in Kondomanager finanziano *Fatture Passive* (costi reali verso fornitori). Creare un piano straordinario per sanare un buco di *Preventivo* costringerebbe l'amministratore a registrare una finta fattura passiva, inquinando i bilanci con costi inesistenti.

**La Soluzione Contabile Corretta (Pattern Adottato):**
1. **Recupero a Conguaglio (Automatico e Naturale):** Nessuna azione manuale. In sede di Rendiconto (consuntivo), il motore calcola la spesa reale per l'intero esercizio. Il nuovo proprietario riceve la sua quota di competenza. Avendo versamenti = 0 (poiché le rate preventive non gli sono state emesse), il suo conguaglio genererà l'esatto debito corrispondente al "buco", mettendolo in pari.
2. **Addebito Manuale / Rata ad Personam (Roadmap):** Qualora il condominio necessiti di liquidità immediata (senza poter attendere il consuntivo), verrà implementata la possibilità di emettere un "Addebito Manuale" slegato dai costi fornitore, esigibile istantaneamente.

## Sviluppi Futuri (v1.11)
1. **Tabella dedicata `scoperti_pregressi`:** Oltre alla `nota_scoperti`, il sistema salverà in una nuova tabella i metadati (importo, ID immobile, gestione) per automatizzare i promemoria di recupero.
2. **Chiusura Automatica Task Inbox:** Non appena il motore rileverà l'emissione dell'addebito manuale riparatore per l'unità coinvolta, o la chiusura della gestione a conguaglio, il task `SCOPERTO_DOCUMENTATO` verrà archiviato in automatico.
