# Guida — Layer di Reporting & Viste
### Dal ledger immutabile ai documenti tradizionali (e oltre)

> Documento di lavoro. Separa ciò che è **già in roadmap** da ciò che propongo come **nuovo**, e tiene fermo un principio unico di presentazione. Le decisioni marcate come *aperte* vanno validate contro il codice reale e pesate contro la disciplina di scope prima di diventare impegni.

---

## 1. Obiettivo e contesto

La domanda nasce da un punto concreto: un amministratore abituato ad altri gestionali si aspetta certi **report e viste tradizionali**, e potrebbe sentirsi spaesato davanti alle nostre viste in tempo reale. La forza di KondoManager — il consuntivo e lo stato patrimoniale come proiezione viva del ledger — non è in conflitto con quella familiarità: va vestita nel linguaggio che l'amministratore già conosce.

Questo documento definisce un layer di reporting che fa due cose insieme:

1. **parla la lingua dei gestionali tradizionali**, per abbassare la barriera di chi migra (es. da Danea);
2. **sfrutta il ledger immutabile** per offrire viste e garanzie che i gestionali tradizionali, per come sono costruiti, non possono dare.

L'obiettivo finale è che il reporting diventi un *motivo per scegliere* KondoManager, non solo una casella da spuntare.

---

## 2. Principio guida: doppia presentazione, una sola verità

Una sola fonte di verità: il giornale immutabile in partita doppia. Sopra di esso, **due contratti di presentazione**:

- **Vista viva (read-model).** Proiezione sempre aggiornata del ledger alla data di oggi. Cambia quando cambia il ledger. Serve alla gestione: *«dove siamo adesso».*
- **Documento di periodo (snapshot congelato).** Fotografia di un esercizio, congelata alla chiusura/delibera, immutabile e riproducibile. Serve alla norma e all'assemblea: *«cosa è stato deliberato».*

Le viste tradizionali (consuntivo, preventivo, riparto, stato patrimoniale) sono **skin di presentazione** sopra questi due contratti, non un secondo percorso dati. Nessuna ricostruzione manuale, nessun rischio di disallineamento.

> **Regola pratica:** un report non contiene mai un numero che non sia derivabile da una scrittura.

---

## 3. Viste tradizionali — continuità per chi arriva da altri gestionali

Chi migra si aspetta tabulati precisi. Offrirli come skin abbassa la barriera e dà fiducia. Set minimo:

**Rendiconto consuntivo classico (art. 1130‑bis c.c.).** Le componenti attese: registro di contabilità, riepilogo finanziario, nota sintetica esplicativa, stato patrimoniale, più il riparto. → modulo Year End (v1.17), alimentato dal ledger.

**Preventivo classico con riparto per condòmino.** Preventivo per voci/capitoli + quota per unità + piano rate. → flusso preventivo già attivo; impaginazione classica nel layer stampe.

**Bilancio comparativo preventivo / consuntivo.** Per voce: preventivato vs consuntivato vs scostamento. È uno dei tabulati più richiesti in assemblea. → read‑model (per la versione *live* vedi §4.2).

**Tabella di riparto.** Per condòmino e per tabella: millesimi/quota, addebiti, versamenti, saldo, con il dettaglio della tabella applicata (ordinaria, scale/ascensore ex art. 1124 c.c.). → motore di riparto unificato (Livello 1 + Livello 2).

**Registro di contabilità / prima nota.** Il giornale in formato leggibile e stampabile. → diretto dal ledger.

**Stato patrimoniale in formato classico.** Disponibilità, crediti v/condòmini, debiti v/fornitori, fondi. → Stato Patrimoniale operativo (v1.10).

**Scadenzario.** Rate in entrata e fornitori da pagare. → dalle scadenze esistenti.

**Estratto conto condòmino.** Posizione del singolo: quote, versamenti, saldo, per voce e per documento. → per la versione *in tempo reale* vedi §4.2.

> Tutte queste viste sono **presentazione**. La sostanza (numeri, riparti) viene dal ledger e dal motore di riparto; lo skin tradizionale è impaginazione + raggruppamenti familiari.

---

## 4. Posizionamento competitivo — dove ci distinguiamo (e dove no)

> **Aggiornamento dopo verifica sul mercato.** Buona parte del reporting "classico" è ormai standard di categoria. Danea Domustudio (usato da oltre 10.000 studi) genera già consuntivo e preventivo 1130‑bis, il **bilancio comparativo preventivo‑consuntivo**, i bilanci stampabili in qualsiasi momento, la situazione economico‑patrimoniale, lo split proprietario/inquilino e il fiscale (CU, 770). Conseguenza: il consuntivo formale e la "vista viva" comparativa **non sono tratti distintivi**, sono *baseline da raggiungere* per essere competitivi. Non rivendicare l'esclusiva su comparativo, drill‑down o "tempo reale": chi conosce il mercato se ne accorgerebbe.

Il vantaggio reale e verificato è altrove, ed è la bussola delle priorità:

- **Open source — differenziatore numero uno.** KondoManager è di fatto l'unico gestionale condominiale italiano a codice aperto: gratuito, ispezionabile, senza lock‑in, personalizzabile. Il resto del mercato (Domustudio, Buffetti, PIGC, Millesimo) è proprietario e a pagamento.
- **Integrità architetturale.** Il ledger immutabile rende la contabilità corretta e tracciabile *per costruzione*, non come funzione aggiunta: un errore si corregge con uno storno, mai riscrivendo il passato.
- **Stack moderno ed estendibile.** Layer fiscale modulare (italiano feature‑flagged), apertura all'internazionalizzazione (versione spagnola), UI moderna.

Il nostro "meglio" quindi non è *«le stesse funzioni, fatte meglio»* — su ampiezza un leader maturo oggi ci supera — ma *«le stesse funzioni, con un'integrità e una libertà che un software proprietario non può offrire»*. Le sezioni che seguono vanno lette in questa chiave: funzioni da realizzare bene, alcune realmente distintive (ciò che nasce dall'immutabilità), altre semplicemente dovute.

### 4.1 Il vantaggio strutturale del ledger immutabile

Qui sta la parte realmente distintiva: tratti che nascono dal giornale immutabile e che un gestionale a contabilità editabile fatica a garantire allo stesso modo.

**Tracciabilità nativa — «spiega questo numero».** Ogni importo, in ogni report, è cliccabile e risale alle scritture sottostanti, fino a fattura/pagamento. Nei gestionali con report denormalizzati o ricompilati a mano questa catena si spezza; qui è nativa, perché il report *è* una proiezione del giornale. Valore enorme in assemblea e nei contenziosi: nessun numero è un'affermazione, ogni numero è una prova. Lato lettura il costo è contenuto. → valorizzabile nella Reporting Suite (v1.18), abilitato dai read‑model (v1.10).

**Reporting point‑in‑time — la macchina del tempo.** Poiché il giornale è append‑only e immutabile, si può ricostruire stato patrimoniale e situazione economica a una **data qualsiasi del passato** (*«com'era al giorno dell'assemblea?»*, *«qual era la cassa al 31/12?»*). I gestionali che sovrascrivono non possono. Si realizza con un read‑model filtrato per data sulle scritture.

**Documenti formali riproducibili e versionati.** Un consuntivo approvato viene congelato (snapshot del valore al close) e resta riproducibile **identico per sempre**, anche se la contabilità prosegue. Si può sempre ristampare *esattamente* ciò che l'assemblea ha deliberato. Pochi gestionali lo garantiscono dopo modifiche successive. Si appoggia alla logica di versioning già prevista. → Year End (v1.17).

### 4.2 Trasparenza verso il condòmino — meno contenzioso

**Scostamento preventivo ↔ consuntivo in tempo reale.** È la versione *utile* del «consuntivo in tempo reale»: non un documento formale, ma un cruscotto vivo che mostra, per voce, quanto si è speso rispetto al preventivato, con segnalazione delle voci in tendenza di sforamento. Si appoggia a `BudgetCoverageService` + ledger. Strumento gestionale **proattivo**, non reattivo. → read‑model (v1.10).

**Riparto explainer.** Per ogni condòmino, *perché* la sua quota è quella: tabella applicata, peso/millesimi, base di calcolo, eventuale ripartizione per ruolo (proprietario/inquilino/usufruttuario). Affronta la causa numero uno delle liti: non capire il proprio riparto. Si appoggia al motore di riparto unificato (Livello 1 + Livello 2). → con il completamento del motore (v1.11).

**Estratto conto condòmino in tempo reale.** Vista personale sempre aggiornata: dovuto, versato, saldo, scomposto per voce e per documento. Riduce le richieste *«quanto devo?»* e prepara il terreno a Ricevute Attive (v1.14) e al modulo Comunicazioni (v1.21).

**Nota sintetica esplicativa assistita.** La norma chiede una nota; il software **non la scrive al posto dell'amministratore**, ma gli mette davanti i fatti da commentare: scostamenti rilevanti, sopravvenienze, voci anomale. *Fact‑surfacing*, non generazione di testo giuridico. (Attenzione a escludere voci tecniche e sopravvenienze dalle viste destinate ai condòmini: `->visibili()` nelle query e filtro `is_tecnico = false`.) → Reporting Suite (v1.18).

### 4.3 Tesoreria e salute contabile — già in roadmap, da valorizzare nel reporting

**Bilanciatore Fondi.** Distingue saldo contabile da copertura liquida, con morosità per immobile e logica «quota segue immobile». Pochi gestionali separano cassa e competenza con questa pulizia. → v1.10.

**Liquidity Forecast / Treasury Guardian.** Proiezione della cassa in avanti su rate in entrata e fornitori da pagare: tesoreria predittiva, rara nel settore. La pagina di dettaglio del Treasury Guardian è già prevista in v1.18 (drill‑down del widget, riuso del servizio e del DTO esistenti). → widget v1.10, dettaglio v1.18.

**Radar Salute Contabile / Credit Enforcer.** Indicatori di salute in tempo reale (morosità, copertura, anomalie). → v1.10.

### 4.4 Workflow ed export

**Packet assembleare in un clic.** Generazione dell'intero set documentale per l'assemblea (convocazione, preventivo, consuntivo, riparti, nota). → si lega a Comunicazioni (v1.21); valutare uno stub anticipato che assembla i PDF già disponibili.

**Export con provenienza — pronto per il revisore.** Ogni vista esportabile in CSV/Excel portando con sé la data *«al»* e la provenienza dei dati. Pensato per commercialista/revisore. → layer stampe/reporting.

---

## 5. Mappatura sulla roadmap esistente

| Funzionalità | Release | Stato |
|---|---|---|
| Stato Patrimoniale operativo (vista viva) | v1.10 | in roadmap |
| Cash Statements (read‑model) | v1.10 | in roadmap |
| Treasury Guardian · Radar Salute Contabile · Credit Enforcer · Liquidity Forecast | v1.10 | in roadmap |
| Bilanciatore Fondi | v1.10 | in roadmap |
| Scostamento preventivo ↔ consuntivo *live* | v1.10 | **nuovo / da valutare** |
| Drill‑down «spiega questo numero» | v1.18 (abilitato da read‑model v1.10) | **nuovo / da valutare** |
| Reporting point‑in‑time | v1.10 → v1.18 | **nuovo / da valutare** |
| Riparto explainer + tabella di riparto classica | v1.11 (motore unificato) | parz. in roadmap |
| Rendiconto consuntivo classico (1130‑bis) | v1.17 | in roadmap |
| Documenti formali riproducibili / versionati | v1.17 | in roadmap |
| Nota sintetica assistita | v1.18 | **nuovo / da valutare** |
| Estratto conto condòmino | v1.14 / v1.21 | parz. in roadmap |
| Packet assembleare | v1.21 | parz. in roadmap |
| Export con provenienza | layer stampe | **da valutare** |

---

## 6. Sequenziamento consigliato

**Fase 1 — subito, nel layer read‑model della v1.10.** Tutto ciò che è *vista viva* e a basso costo perché legge il ledger: stato patrimoniale operativo, scostamento preventivo/consuntivo live, cruscotti di salute, Bilanciatore Fondi — più le skin tradizionali sui dati vivi (riparto, prima nota, bilancio comparativo). Qui si consegna gran parte del **valore gestionale percepito** (*«dove siamo adesso»*) molto prima del modulo di chiusura.

**Fase 2 — modulo Year End (v1.17).** I documenti *formali e congelati*: rendiconto consuntivo classico 1130‑bis, snapshot riproducibile, chiusura esercizio. Qui conta l'immutabilità, non la reattività.

**Fase 3 — Reporting Suite (v1.18).** Drill‑down «spiega questo numero», point‑in‑time, nota assistita, dettaglio Treasury Guardian, export avanzati. È il layer che trasforma i dati in strumento di analisi — e diventa argomento di vendita.

**Trasversale.** Riparto explainer (con v1.11), estratto conto condòmino (con v1.14), packet assembleare (con v1.21).

> **Principio di sequenza:** disaccoppiare la *vista viva* (leggera, presto) dal *documento formale* (pesante, al suo posto nella sequenza). Non anticipare il documento formale solo per «avere il consuntivo»: il valore gestionale arriva prima con i read‑model.

---

## 7. Vincoli e note di attenzione

**Immutabilità dei documenti formali.** Dopo la delibera il consuntivo non cambia: storno = scrittura inversa, mai modifica retroattiva. Lo snapshot al close è la garanzia.

**Niente testo legale auto‑generato.** La nota sintetica resta responsabilità dell'amministratore; il software fornisce *fatti*, non prosa giuridica.

**Sopravvenienze e voci tecniche.** Escluderle dalle viste destinate ai condòmini e dai PDF di preventivo: `->visibili()` nelle query, filtro `is_tecnico = false`.

**Competenza vs cassa.** Lo scostamento e il consuntivo ragionano per competenza; i cruscotti di tesoreria per cassa. Tenere i due piani **distinti ed etichettati**, per non confondere «speso» con «pagato».

**Performance dei read‑model.** Le viste vive sono query sul giornale: man mano che cresce, valutare proiezioni materializzate/cache per stato patrimoniale e point‑in‑time, senza intaccare l'append‑only.

**Coerenza terminologica.** `origine` (provenienza tecnica) ≠ `causale` (significato amministrativo): i report non devono mescolare i due piani.

---

## 8. Idee aggiuntive da valutare (aperte)

Proposte da pesare contro la disciplina di scope. Marcate con un mio giudizio iniziale.

**Confronto pluriennale per voce.** Trend di spesa di una voce su più esercizi (lo storico è già nel ledger). Basso costo, alto valore in assemblea. → *probabile sì.*

**«Cosa è cambiato dall'ultima assemblea».** Diff della situazione tra due date/deliberazioni; si appoggia al point‑in‑time. → *da valutare dopo il point‑in‑time.*

**Pezze giustificative collegate in linea.** Da ogni riga di report, apertura dell'allegato (fattura/ricevuta): estende l'allegato ricevuta già previsto. Forte in assemblea. → *sì, se l'archiviazione allegati è matura.*

**Cruscotto di portafoglio (multi‑condominio) per l'amministratore.** KPI normalizzati (morosità %, copertura fondi, giorni medi d'incasso, spesa per voce per millesimo) confrontabili tra i condomìni gestiti. Differenziante per studi strutturati. → *attenzione a privacy e scope; rischio over‑engineering se prematuro.*

**Verbale di approvazione con timestamp.** Alla delibera del consuntivo, registrazione datata che «sigilla» lo snapshot; si lega a delibera + immutabilità. → *sì, estensione naturale del freeze.*

**Vista «pronto per il commercialista».** Esportazione strutturata pensata per chi tiene la contabilità fiscale dello studio. → *da valutare con il layer fiscale modulare.*

---

*Base di lavoro: il fondamento è il principio della doppia presentazione (§2). Il differenziatore numero uno è l'open source (§4); il cuore tecnico della distintività è l'integrità del ledger immutabile (§4.1). Il reporting classico — consuntivo, preventivo, comparativo — è baseline da raggiungere, non un vanto. Le voci di §8 restano aperte.*