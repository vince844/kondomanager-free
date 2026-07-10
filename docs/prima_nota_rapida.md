# Prima Nota Rapida — Guida di design

**Stato:** accettata — pronta per implementazione · **Origine:** feedback beta (email + mockup Excel del tester) · **Ultimo aggiornamento:** luglio 2026
**v3:** risolte le domande aperte → §13 Decisioni. Import FatturaPA: modulo separato con roadmap propria, fuori dallo scope di questa guida.

---

## 1. Contesto

Tre segnali indipendenti dello stesso tester convergono sullo stesso bisogno:

1. Inserimento **dal lordo** per oneri bancari, postali, scontrini, imposta di bollo (feedback punto 2).
2. Un movimento unico che generi documento + pagamento in un colpo solo (feedback punto 4).
3. Un mockup Excel di maschera "prima nota" universale, con nota esplicita: *"per inserire un pagamento imposta di bollo ci metto pochi secondi; con KM mi pare molto più lungo"*.

Il modello mentale di chi arriva dai gestionali storici (Danea, gestionali contabili classici) è la **prima nota**: si ragiona per registrazioni, non per navigazione documento → pagamento. Per questi utenti — esattamente il profilo di chi migra verso Kondomanager — il tempo di inserimento di un'operazione semplice è la metrica di adozione più visibile.

**Posizionamento:** il paradigma tastiera va accolto perché è memoria muscolare consolidata; la differenziazione non sta nel rifiutare la maschera, ma in ciò che la maschera *vede e impara* intorno alla digitazione (§6, §7). Veloce come i legacy a parità di tasti premuti; migliore in tutto il resto.

## 2. Decisione strategica: feature additiva, non refactoring

La Prima Nota Rapida è una **facciata** sopra il pipeline documenti/pagamenti esistente. Nessuna modifica al motore contabile in partita doppia, al waterfall finanziario, ai riparti. I flussi esistenti restano il percorso canonico e completo; la prima nota è un acceleratore per i casi ad alta frequenza e bassa complessità.

Motivazioni:

- **Zero regressioni.** La vista compone service transazionali già collaudati; il lavoro fatto finora non si tocca.
- **Invarianti contabili preservate.** Ogni registrazione produce documenti e scritture reali, pienamente visibili a tutti i moduli a valle (§6).
- **Rollout de-rischiato.** Esposizione graduale per fasi e per voce di menu; gli utenti esistenti non vedono cambiare nulla finché non la aprono.
- **Reversibilità.** Se la UX non convince, si ritira la vista senza toccare dati né architettura.

**Anti-goal esplicito:** mai scritture libere in partita doppia che bypassano il layer documenti. La prima nota è una porta d'ingresso diversa allo stesso pipeline, non un pipeline parallelo.

## 3. Specifica della maschera

Vista unica, nome utente-facing **"Prima nota"** con sottotitolo *"registrazione rapida"* (§13, D1). Il campo **Operazione** pilota la visibilità dei campi e decide quale pipeline invocare al salvataggio.

| Campo | Tipo | Note |
|---|---|---|
| Operazione | select | primo campo, focus iniziale (vedi §4) |
| Data | date | default: oggi |
| Descrizione | text | |
| Sottoconto | combobox con ricerca | piano dei conti spese; proposto dai default per fornitore (§7) |
| Conto di pagamento | combobox | conti di tesoreria (banca/cassa) — **non** fornitori |
| Fornitore | combobox | percipiente ai fini CU; **facoltativo (nullable)** per spese semplici, **obbligatorio se ritenuta > 0** |
| Numero registrazione | auto | progressivo per esercizio, sola lettura |
| Importo totale (lordo) | decimal | |
| Imponibile / IVA | decimal | visibili solo per operazioni fattura |
| % ritenuta | select 0 / 4 / 20 | proposta dal profilo fornitore, **sempre modificabile** |
| Codice tributo | select 1019 / 1020 / 1040 | coerenza suggerita con la %, non imposta |
| Importo ritenuta | decimal | calcolato ma **sempre editabile** (beni significativi, casi limite) |
| Netto a pagare | display | lordo − ritenuta |
| Allegato | drag-drop / file | scontrino o ricevuta (PDF/foto), facoltativo; diventa allegato del documento |

Pulsanti: **Salva e nuovo** (Invio), **Salva ed esci**, **Annulla** (Esc).

I due campi distinti del mockup del tester — chi paga vs. chi percepisce — sono corretti e vanno mantenuti: "Conto di pagamento" (tesoreria) e "Fornitore" (rilevante per CU/ritenute) sono concetti diversi.

## 4. Tipi di operazione e mappatura release

**Fase 1 — v1.9.2 "Giroconti & Prima Nota":**

- **Spesa semplice / scontrino / bollo.** Documento minimo che nasce già pagato. Solo lordo, niente IVA né ritenuta obbligatorie. Fornitore facoltativo. È il caso che ha originato tutto.
- **Ricevuta fattura.** Crea il documento con IVA/ritenuta, nessun pagamento contestuale.
- **Pagata fattura.** Picker delle fatture aperte del fornitore selezionato, autofill importi, pagamento via waterfall.
- **Giroconto** tra conti di tesoreria. La dipendenza si dissolve: il service giroconti nasce nella stessa release e la prima nota ne è la UX naturale.
- **Incluso: Duplicate Detector inline** (§6) — la protezione nasce insieme al rischio che la modalità salva-e-nuovo introduce.

**Fase 2 — v1.9.6 (Dashboard Intelligence) e successive:**

- **Incasso** (rata / condòmino) e **Addebito personale** (ad personam).
- **Ripeti ultima / ricorrenze proposte** (§7).
- **Segnali intelligence estesi in-form**: copertura preventivo, saldo tesoreria (§6) — dove Tier 1 era già destinato.

**Fase 3 — v1.13 (Auto-Reconciliation):**

- **Registrazione assistita da estratto conto** (§7).

**Fallback dichiarato:** se v1.9.2 si gonfia oltre il sostenibile, la prima nota scivola in una v1.9.2.1 dedicata, senza toccare il resto della serie.

## 5. UX tastiera

Requisito primario del tester, e il vero moltiplicatore di produttività:

- **Tab** segue l'ordine visivo dei campi; le combobox (reka-ui/shadcn) non devono intrappolare il focus.
- **Invio** sull'ultimo campo (o Ctrl+Invio ovunque) = *salva e nuovo*: toast di conferma, reset del form, focus sul primo campo, progressivo che avanza. Chi carica cinquanta scontrini vive di questo.
- **Esc** = annulla, con conferma solo se il form è sporco.
- Combobox: ricerca per iniziali, apertura con freccia giù, selezione con Invio.

## 6. Moduli intelligenti nel flusso di inserimento

**La domanda architetturale: la registrazione veloce bypassa i moduli intelligenti? No, per costruzione.** La prima nota scrive documenti e scritture reali nello stesso pipeline: Semaforo Contabile/Finanziario, copertura preventivo, Liquidity Forecast e Bilanciatore Fondi leggono dal ledger e vedono ogni registrazione come qualsiasi altra (garanzia verificata dal test 6, §12). Nessun punto cieco.

**La risposta vera però è l'inversa: l'intelligence entra nel form, al momento dell'inserimento.** È qui che KM smette di copiare i legacy e li supera — la prima nota classica è veloce perché è cieca; questa è veloce *e vigile*:

- **Duplicate Detector inline** (v1.9.2, nasce con la maschera). Due livelli, sempre non bloccanti: **forte** — stesso fornitore + stesso numero documento nell'esercizio; **standard** — stesso fornitore (o stesso sottoconto, se il fornitore è assente) + importo lordo identico al centesimo + data entro ±7 giorni. Nessuna tolleranza sull'importo: i doppi inserimenti sono copie esatte, il fuzzy matching produce solo rumore. La finestra di ±7 giorni tiene fuori le ricorrenze mensili legittime (canoni, utenze a importo fisso). Banner con link alla registrazione esistente; parametri fissi in Fase 1, configurabili solo se il beta lo chiederà.
- **Copertura preventivo in linea** (v1.9.6). Alla selezione di sottoconto + importo, segnale se la spesa sfora il preventivo di capitolo (*"supera il preventivo del 12%"*). Nei legacy lo scopri al rendiconto; qui lo vedi mentre registri.
- **Tesoreria consapevole** (v1.9.6). Warning pre-salvataggio se il pagamento porta il conto sotto zero; il toast post-salvataggio mostra il saldo aggiornato: *"Registrato n. 48 · Saldo Banca Intesa: €4.230"*.
- **Ritenute → scadenzario automatico.** Il debito verso l'erario nasce corretto dal giorno uno (pipeline documenti), con la sua scadenza (16 del mese successivo); l'aggregazione F24 per codice tributo arriva con v1.9.7. Toast: *"Ritenuta €40 (1019) → F24 entro 16/08"*. Per un utente che ragiona in 1019/1040, ogni inserimento veloce costruisce automaticamente il quadro F24.

**Filosofia trasversale: warn + justify, mai hard-block** (coerente con `adr-scoperto-quote-orfane.md`). Segnali inline, mai modali: l'intelligence non deve costare un solo tasto in più a chi sa quello che sta facendo.

## 7. Differenziatori: oltre la prima nota legacy

- **Default che imparano.** Alla selezione del fornitore si propongono ultimo sottoconto, ultimo conto di pagamento, profilo ritenuta e ultima aliquota IVA. Alla quarta bolletta CEA su "Manutenzione estintori" si confermano solo importo e data. Implementazione: **lookup al volo sull'ultima registrazione non stornata** del fornitore (§13, D5) — la maschera diventa più veloce con l'uso, cosa che nessun legacy fa.
- **Undo nel toast.** *"Registrato n. 48 — Annulla"*: entro l'evento di lock (§10) l'annullamento è pulito e atomico. La velocità ha una rete di sicurezza; nei legacy correggere significa andare a caccia della riga sbagliata.
- **Riepilogo di sessione.** In modalità salva-e-nuovo, contatore in testata (*"12 registrazioni · €340,00"*) e recap compatto a fine sessione: gli errori del batch si vedono a fine batch, non a fine esercizio.
- **Allegato al volo.** Drag-drop dello scontrino sul form: il paper trail nasce insieme alla registrazione, non resta in un cassetto. (Estrazione automatica dei campi dall'allegato: fuori scope, §11.)
- **Ripeti ultima / ricorrenze proposte** (Fase 2). Duplica l'ultima registrazione con data aggiornata; sinergia con Deadline Alerts: *"il bollo trimestrale di solito arriva a inizio mese — registrarlo?"*.
- **Registrazione assistita da estratto conto** (Fase 3, Tier 3). Flusso invertito: si parte dal movimento bancario importato e KM propone la registrazione precompilata (data, importo, conto di pagamento), lasciando all'utente solo la classificazione del sottoconto. Aggancio naturale ad Auto-Reconciliation (v1.13): a regime, la prima nota manuale diventa il fallback, non il percorso principale.

## 8. Traduzioni di modello (dal gestionale legacy a KM)

Il mockup va accolto nella UX ma tradotto nel modello dati corretto, senza dire "no" all'utente:

- **"Banca e cassa come fornitori"** → in KM sono conti di tesoreria. Stessa UX (combobox), etichetta "Conto di pagamento", anagrafica fornitori pulita.
- **Ritenuta = proprietà dell'operazione, non del fornitore.** Il flag "soggetto a ritenuta" sul fornitore è solo un default proposto; % e importo restano sempre editabili in registrazione. Caso CEA estintori: manutenzione (appalto → 4%) e vendita estintore (cessione → nessuna ritenuta) dallo stesso fornitore. Il vincolo di unicità sul codice fiscale resta: niente fornitori duplicati.
- **Documento semplice vs. fattura.** Bolli e scontrini si inseriscono dal lordo; le fatture richiedono l'imponibile (base ritenuta e CU).
- **Beni significativi: fuori scope della prima nota.** Si gestiscono nel flusso fattura completo con righe multi-aliquota (*rappresentare* lo split 10/22 già fatto dal fornitore, mai calcolarlo). Nella prima nota basta l'importo ritenuta editabile per coprire i casi limite.

## 9. Impatti tecnici

- Nuova vista Vue (es. `PrimaNota.vue`) + **service orchestratore sottile** che compone i service esistenti in un'unica transazione DB (documento + eventuale pagamento contestuale). Rollback totale su errore a metà.
- **Endpoint di supporto in-form**: check duplicati (debounced, query su indice fornitore/data/importo) e lookup default per fornitore **calcolato al volo** (§13, D5), nessuna tabella nuova.
- **Fornitore nullable** sui documenti minimi (§13, D3). Invarianti a protezione: ritenuta > 0 ⇒ fornitore obbligatorio (validazione + test 12); gli scope CU/certificazioni sono `whereNotNull` per costruzione; nelle viste, la controparte di una spesa senza fornitore è il conto di pagamento (*"Commissioni — Banca Intesa"*). Blast radius contenuto: solo i documenti minimi, che nascono con questa feature, possono essere senza fornitore — il pregresso non è toccato.
- **Numerazione**: progressivo per condominio/esercizio assegnato dentro la transazione, con protezione dalla concorrenza (niente buchi né duplicati).
- **Storno**: annulla atomicamente la coppia documento + pagamento; l'azione "Annulla" nel toast invoca lo stesso storno (con controllo permessi). Le coppie di storno restano nascoste di default nelle viste, coerentemente con la policy di lock del §10.
- **Esposizione**: voce di menu dedicata "Prima nota"; eventualmente dietro setting nelle prime release.

## 10. Interazioni con gli altri punti del feedback beta

La prima nota non vive da sola; il ciclo di feedback ha prodotto altri interventi correlati, tutti additivi:

- **Default IVA 22%** → memorizzare l'ultima aliquota usata per fornitore (assorbito dai default che imparano, §7). Fix che vale anche per il flusso completo.
- **Saldi iniziali condòmini** → serve un punto d'ingresso esplicito nel setup del condominio; il tester non ha trovato da solo la strada dei debiti pregressi. Stesso tema di fondo: riduzione dell'attrito.
- **Immutabilità libro giornale** → policy *lock-event*: modifica/annullamento liberi con audit log (chi/cosa/quando) fino all'evento di lock (approvazione rendiconto o riconciliazione); storno formale solo dopo. Nessun obbligo normativo di immutabilità assoluta per il condominio (art. 1130-bis richiede cronologia e trasparenza, non divieto di correzione pre-approvazione). La prima nota — incluso l'undo nel toast — eredita questa policy.
- **"Correggi documento" guidato** → sgancia i pagamenti collegati prima della modifica. Risolve il caso reale del bollo inserito con IVA errata e pagato parzialmente.

## 11. Non-obiettivi

- Non sostituisce i flussi completi (fatture multi-riga, beni significativi, allocazioni miste, piani rate).
- Nessuna scrittura libera in partita doppia.
- Nessun calcolo automatico dei beni significativi.
- Nessuna modifica al motore di riparto o al waterfall.
- Niente OCR/estrazione automatica dagli allegati e niente inserimento in linguaggio naturale in Fase 1: prima la base solida, poi le evoluzioni.

## 12. Test (Pest)

1. Creazione atomica documento + pagamento; rollback completo su errore a metà transazione.
2. Storno atomico della coppia.
3. Coerenza % ritenuta / codice tributo (4 → 1019/1020, 20 → 1040) come suggerimento, con importo editabile che prevale sul calcolato.
4. Spesa semplice senza fornitore: creazione, viste (controparte = conto di pagamento), esclusione da CU.
5. Progressivo di numerazione sotto concorrenza.
6. La spesa registrata da prima nota è indistinguibile a valle: Semaforo Contabile/Finanziario, copertura preventivo e forecast la vedono come qualsiasi altra.
7. Modalità "pagata fattura": il picker mostra solo fatture aperte del fornitore; il pagamento passa dal waterfall.
8. Duplicate Detector: warning forte su fornitore + numero documento; warning standard su importo esatto ±7 giorni; mai bloccante; nessun falso positivo su ricorrenza mensile a importo fisso (30 giorni).
9. Default per fornitore: la registrazione successiva propone sottoconto, conto di pagamento e profilo ritenuta dell'ultima non stornata.
10. Undo dal toast = storno atomico, negato dopo l'evento di lock.
11. Registrazione con ritenuta genera il debito erariale con scadenza corretta (16 del mese successivo).
12. Invariante: ritenuta > 0 senza fornitore → la validazione respinge il salvataggio.

## 13. Decisioni

- **D1 — Nome: "Prima nota"**, sottotitolo *"registrazione rapida"*. È il termine che il mercato target conosce e cerca (memoria muscolare dei migranti, keyword organica); il sottotitolo copre chi non viene dai legacy. "Registrazione rapida" da solo è generico e non parla all'amministratore.
- **D2 — Roadmap: v1.9.2 diventa "Giroconti & Prima Nota"** (Fase 1 + modalità giroconto + duplicate check inline). Tema coerente (movimenti), la dipendenza giroconto si dissolve in-release, e il tester riceve la feature a ciclo beta caldo. Segnali intelligence estesi → v1.9.6; aggregazione F24 → v1.9.7; registrazione assistita → v1.13. Fallback: v1.9.2.1 dedicata se la release si gonfia.
- **D3 — Fornitore: nullable**, non anagrafica di sistema. Il record sintetico richiede più codice speciale (protezione da edit/delete, esclusione da liste, CU, report) di quanto null-handling ne risparmi, e il suo failure mode è comunque un flag-check dimenticato. Invarianti compensativi in §9; blast radius limitato ai documenti minimi nuovi.
- **D4 — Criteri duplicati: due livelli, non bloccanti.** Forte: fornitore + numero documento nello stesso esercizio. Standard: fornitore (o sottoconto) + importo lordo esatto al centesimo + data ±7 giorni. Zero tolleranza sull'importo; ±7 giorni esclude le ricorrenze mensili. Parametri fissi in Fase 1 (niente config UI finché il beta non la chiede).
- **D5 — Default per fornitore: lookup al volo**, ultima registrazione non stornata. Alla scala di un'installazione KM la cache persistita è ottimizzazione prematura più bug di invalidazione (storno che deve retrocedere i default, ecc.). Upgrade path a costo zero: se il beta mostra il caso "eccezione che sporca il default" (la vendita CEA dopo dieci manutenzioni), si passa alla moda sulle ultime 5 cambiando solo la query.