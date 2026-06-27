# v1.10.0 — Tabelle Millesimali: Tipo Manuale & Scope Filtering

> Documento di scope e decisioni architetturali.
> Redatto prima dell'implementazione per consolidare le scelte e il loro razionale.
> Stato: **Approvato, pronto per implementazione**

---

## 1. Contesto e motivazione

La gestione delle spese di scale e ascensori ex art. 1124 c.c. è uno dei punti di maggiore attrito nell'amministrazione condominiale italiana. La norma impone una ripartizione mista:

- **50%** in ragione del valore delle unità immobiliari (millesimi di proprietà — "Tabella A")
- **50%** in misura proporzionale all'altezza di ciascun piano dal suolo ("Tabella B")

La giurisprudenza (Cass. 432/2007) estende il criterio anche alle spese di pulizia e illuminazione delle scale, non solo alla manutenzione.

Diversi casi reali raccolti da forum di amministratori hanno confermato che:

1. Molti condomini, specialmente pre-1980, hanno **solo la tabella generale di proprietà** depositata, e l'amministratore deve derivare le tabelle scale/ascensore.
2. Gli amministratori competenti **calcolano i millesimi di altezza a mano** (Excel o calcolo manuale) e arrivano con i numeri già pronti.
3. Quello che manca non è un calcolatore, ma **uno strumento che accetti tabelle a quote relative e le combini correttamente** con la tabella di proprietà.

---

## 2. Decisione architetturale chiave

Il sistema **NON** deve essere un calcolatore di millesimi (non è un CAD per geometri). Deve essere un **gestionale amministrativo** che:

- accetta tabelle con valori finali già calcolati;
- le combina tramite voci di spesa pesate;
- ripartisce le fatture al centesimo.

### I tre layer (già parzialmente esistenti)

```
Layer 1 — Tabelle elementari
   Millesimi/quote finali per ogni immobile.
   Input manuale. Nessun calcolo automatico.

Layer 2 — Voci di spesa pesate
   Pivot `conto_tabella_millesimale` (conto_id, tabella_id, coefficiente).
   Una voce combina N tabelle con pesi percentuali.
   GIÀ ESISTENTE E FUNZIONANTE.

Layer 3 — Motore di ripartizione
   CalcoloQuoteService::distribuisciSuTabelle()
   Formula: quota[i] = importo × Σ_tab (coeff_tab/100 × valore[i]/Σ valori_tab)
   Penny-perfect algorithm per arrotondamenti.
   GIÀ ESISTENTE E FUNZIONANTE.
```

La verifica del `CalcoloQuoteService` ha confermato che Layer 2 e Layer 3 sono già implementati correttamente:
gestiscono multi-tabella pesata, immobili assenti in una tabella, comproprietà, ripartizione
proprietario/inquilino con fallback, e arrotondamenti al centesimo.

**Conseguenza**: la v1.10.0 deve solo aggiungere il tipo di tabella mancante (`manuale`) + disciplina UX.
Nessuna modifica al motore.

---

## 3. Perimetro della v1.10.0

### IN SCOPE

1. **Migration**: aggiunta del valore `manuale` all'enum `tipo` della tabella `tabelle`.

2. **Frontend `TabelleNew.vue`**: aggiunta opzione "Manuale" alla dropdown tipologie.

3. **Frontend `QuoteList.vue`**:
   - Scope filtering automatico: gli immobili selezionabili sono filtrati per `scala_id` / `palazzina_id`
     della tabella, con toggle "Mostra tutti gli immobili del condominio" per gestire eccezioni
     (es. box che partecipa a una scala non sua).
   - Avviso (non bloccante) quando si include un immobile fuori scope.
   - Contatore live "Totale: X" sempre visibile.
   - Colonna "% effettiva" calcolata in tempo reale (essenziale per tipo `manuale`).
   - Per tipi millesimali classici (standard, ascensore, lastrico): warning giallo non bloccante
     se il totale devia da 1000 oltre soglia (±0,5).

4. **Frontend form conto (voce di spesa)**: warning non bloccante se Σ coefficienti delle tabelle
   collegate ≠ 100. Messaggio: "I coefficienti sommano a X% — verifica che sia intenzionale."

5. **Pulizia dropdown tipologie**: rimuovere o disabilitare (badge "Coming soon") le opzioni
   `acqua` e `riscaldamento`, oggi presenti ma incomplete, per evitare uso scorretto.

### Semantica della tabella manuale

Quote relative arbitrarie. Il motore calcola `valore / Σ valori`. Non è richiesto che la somma
faccia 100 o 1000. Copre:
- parti uguali (1 per tutti)
- per capita (numero residenti)
- quote deliberate in assemblea
- millesimi di altezza ex art. 1124 (inseriti già calcolati)
- qualsiasi criterio proporzionale a singolo parametro

### Backend
Zero modifiche al `CalcoloQuoteService`. Eventuale validazione leggera nella request del form conto.

### Stima
2–3 giorni di lavoro pulito.

---

## 4. ESPLICITAMENTE FUORI SCOPE

| Elemento | Dove va | Perché non ora |
|---|---|---|
| Motore calcolo automatico ex art. 1124 | Eventuale v1.11, probabilmente mai | Gli amministratori calcolano a mano e portano i numeri pronti. Rischio legale alto. |
| `coefficiente_altezza` su anagrafica immobile | Solo se si fa il motore di calcolo | Codice morto senza il motore che lo consuma |
| Helper "pre-compila dal piano" | Eventuale v1.12 | 2 ore di lavoro, comodità non critica |
| Tabella **acqua** completa | v1.15 — Water Metering | Richiede quota fissa + consumo + sfrido + letture periodiche. La tabella manuale copre solo la parte proporzionale. |
| Tabella **riscaldamento** completa | Modulo termico (post water-metering) | Richiede norma UNI 10200, fabbisogni termici, contabilizzatori |
| Tipo `scale` nell'enum | Mai | Matematicamente identico a `standard`. Sarebbe solo etichetta estetica. |
| Pivot many-to-many `immobile_scala` | Solo se emerge il bisogno | Il sistema già include qualsiasi immobile in qualsiasi tabella via righe `quote`. Toggle "mostra tutti" come valvola. |
| Versionamento storico tabelle | Futuro (modulo chiusura esercizio) | Da pensare, non urgente. Per ora check "ha già generato rate_quote?" prima di modifiche destructive. |
| Concetto di pertinenze | Modulo assemblee / bollettazione | Il caso "box che non paga le scale" si risolve con scope tabelle, non con pertinenze |

---

## 5. Casi d'uso di riferimento (dai forum)

### Caso A — Due tabelle per scala (50/50)
Amministratore con Tab A (proprietà) e Tab B (altezze) per ogni scala.
**Soluzione**: una voce di spesa per scala, ognuna con le due tabelle al 50%.
Già possibile con Layer 2+3; richiede solo il tipo `manuale` per la Tab B se non già calcolata.

### Caso B — Solo tabella generale (condominio imperfetto)
Amministratore con la sola Tab A. Deve derivare la Tab B altezze.
**Soluzione**: crea Tab B come tabella `manuale` inserendo i millesimi di altezza
(es. calcolati come altezza_dal_suolo × 1000 / Σ altezze). Poi voce 50/50.

### Caso C — Condominio del 1974 (formule diverse per spesa)
Regolamento richiama art. 1124 per ricostruzione, ma la pulizia segue Cass. 432/2007 (100% altezza).
**Soluzione**: stesse tabelle elementari, voci diverse:
- "Ricostruzione scale" → 50% Tab A + 50% Tab Altezze
- "Pulizia scale" → 100% Tab Altezze

### Caso D — Nettuno (multi-scala, esclusioni per spesa)
80 appartamenti, 2 palazzine, 4 scale ciascuna, box con accessi differenziati.
**Soluzione**: una tabella scale per ogni scala (scope per `scala_id`), immobili esclusi
semplicemente assenti dalle `quote` della tabella. Toggle "mostra tutti" per i box condivisi.

### Caso E — Esempio reale di tabella altezze (validazione)
Amministratore ha consegnato una tabella altezze normalizzata a 1000:
interrato 7,16 / primo 23,64 / secondo 47,28 / ... / sesto 141,83 (totale 1000).
Interrato con coefficiente convenzionale 1 m per uso minimo.
**Conferma**: questo è esattamente l'input che il tipo `manuale` accetta. L'amministratore
copia i 13 valori, li collega alla voce ascensore al 50% con Tab A. Fatto.

---

## 6. Acceptance Criteria

La release è pronta quando:

- [ ] È possibile creare una tabella di tipo `manuale` dal form `TabelleNew`.
- [ ] Nel form quote di una tabella manuale, l'amministratore inserisce valori liberi e vede in
      tempo reale la % effettiva di ogni riga e il totale.
- [ ] Una tabella scopata (con `scala_id` o `palazzina_id`) filtra di default gli immobili
      selezionabili, con toggle per mostrarli tutti.
- [ ] Un avviso non bloccante segnala immobili inclusi fuori scope.
- [ ] Le tabelle millesimali classiche mostrano un warning se il totale devia da 1000.
- [ ] Il form conto avvisa (non blocca) se i coefficienti delle tabelle collegate non sommano a 100.
- [ ] `acqua` e `riscaldamento` non sono più selezionabili (o badge "Coming soon").
- [ ] **Test end-to-end**: Tab A standard + Tab B manuale + voce "Pulizia scale" 50/50
      → fattura €100 → quote corrette al centesimo, piano terra paga solo metà valore se quota
      altezza = 0 / assente.
- [ ] Nessuna regressione sul `CalcoloQuoteService` esistente (i piani rate ordinari e straordinari
      continuano a funzionare).

---

## 7. Sequenza implementativa

1. **Migration** enum `tipo` → aggiungere `manuale`. Verificare impatto su dati esistenti (nessuno,
   è solo un valore aggiuntivo).
2. **`TabelleNew.vue`** → aggiungere `manuale` alla dropdown; rimuovere/disabilitare `acqua` e
   `riscaldamento`.
3. **`QuoteList.vue`** → scope filter + toggle; contatore live; colonna % effettiva; warning totale ≠ 1000.
4. **Form conto** → warning Σ coefficienti ≠ 100 (frontend; eventuale validazione soft in request).
5. **Test** → eseguire lo scenario end-to-end + verifica non-regressione.
6. **Changelog + manuale utente** → documentare la nuova tipologia e la procedura art. 1124.
7. **Risposta/articolo pubblico** → blog Kondomanager con la procedura completa (riuso del testo
   già preparato).

---

## 8. Note di comunicazione (forum / blog)

- La tabella manuale **non** è il modulo acqua/riscaldamento. È ripartizione proporzionale a singolo
  criterio. Comunicarla correttamente per evitare aspettative errate.
- Sul forum dove la promozione è vietata: contribuire nel merito (competenza condominiale, no menzione
  del software) per accumulare i post necessari a sbloccare i DM, poi contattare il moderatore in privato.
- Il contenuto promozionale completo va sul blog di Kondomanager / LinkedIn / canali propri.

---

*Fine documento. Aggiornare se emergono nuove decisioni durante l'implementazione.*