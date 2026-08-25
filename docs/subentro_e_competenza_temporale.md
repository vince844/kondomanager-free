# Subentro e competenza temporale delle spese — progetto

<!-- verifica-documentazione -->
> **Stato: progetto approvato e collocato in roadmap, NON implementato — riverificato il 12/08/2026 su 1.10.0-beta.50, ampliato il 15/08/2026 su 1.10.0-beta.51, A7 aggiornato il 16/08/2026 su 1.10.0-beta.56.**
> ⚠️ **Eccezione: il blocco A è uscito con la beta.50** — i sette testi falsi, il comando di diagnosi
> `kondomanager:verifica-titolarita`, il riallineamento della stampa e i due campi morti. Vedi i
> riquadri ✅ nelle sezioni relative.
> ⚠️ **Seconda eccezione: la causa di A7 è chiusa dalla beta.56** — l'importazione non aggiunge più
> titolarità a un'unità su cui il file e l'archivio non concordano. Le quattro righe anomale restano
> nel database di prova: la bonifica è un gesto separato e non fatto.
> ⚠️ **Aggiunte del 15/08/2026, nate dalla domanda «cosa succede se il proprietario di un box lo
> vende o lo affitta»:** i difetti **A6** (le due stampe divergono dopo una dissociazione, misurato
> 100000 contro 40000 centesimi), **A7** (quattro anomalie di titolarità già nel database) e **A8**
> (l'enum `soggetto` senza `nuda_proprietario`, la sigla mancante, la validazione delle date), la
> nota che **A2 è mezzo chiuso nella beta.51** mentre la roadmap lo alloca ancora intero, e la
> **correzione del criterio di competenza in B2**, che è doppio e non unico. L'interfaccia di B2 è
> progettata nel §6 di [`pertinenze_vendita_locazione.md`](pertinenze_vendita_locazione.md).
> Nessuna riga di questo documento descrive codice esistente, tranne la sezione «Il problema», che è
> un accertamento verificato sul codice alla stessa data. Le fasi, le colonne e i servizi nominati da
> «Blocco B» in poi non esistono. Se questo documento viene letto dopo che una fase è uscita,
> l'intestazione va aggiornata: un documento che mente costa più di un documento che manca.
>
> **Le dieci decisioni sono state prese il 12/08/2026** e stanno in §8. Le voci corrispondenti sono
> scritte in `roadmap.md`: coda ⑰ (1.10), «Le due copie del riparto che restano» (1.10.1), «Il subentro:
> il tempo entra nel calcolo» (1.11). Se una decisione cambia, si cambia **qui** e si aggiorna là.
<!-- /verifica-documentazione -->

**Origine.** Domanda di un amministratore sul forum, 12/08/2026, con due scenari concreti (in fondo,
§Appendice A). L'indagine sul codice ha confermato che il gestionale non risolve nessuno dei due, e
che l'interfaccia promette il contrario.

**Metodo.** Tre progetti indipendenti da priori diversi (rischio minimo · modello dei dati ·
dominio giuridico), nove giudizi incrociati (amministratore · manutentore · revisore contabile),
sintesi dal vincitore con gli innesti dei runner-up. È il metodo già usato il 03/08/2026 per la
politica del pregresso. Punteggi: modello 24, dominio 21, minimo 20.

**Versioni interessate.** 1.10 (blocco A), 1.10.1 (B1), 1.11 (B2, B3, B4).

---

## 1. Il problema — accertato sul codice il 12/08/2026

### 1.1 Il motore di riparto è atemporale

Quando il motore deve decidere a chi addebitare la quota di un'unità guarda **due sole colonne**:
`anagrafica_immobile.attivo` e `anagrafica_immobile.tipologia`. Le colonne `data_inizio` (NOT NULL) e
`data_fine` (nullable) esistono dalla creazione della tabella, vengono scritte, vengono mostrate in
elenco nella colonna «Competenza» — e **non le legge nessun calcolo**.

I punti che risolvono il soggetto:

| File | Righe | Cosa fa |
| :--- | :--- | :--- |
| `CalcoloQuoteService.php` | `:515` | `addebitaDiretto()` — spesa ad personam |
| `CalcoloQuoteService.php` | `:821`, `:839` | `distribuisciSuTabelle()` — motore rate, ruolo richiesto e cascata di ripiego |
| `RipartoTabelleService.php` | `:517`, `:544` | stampa del riparto per tabella |
| `RipartoCapitoliService.php` | `:476`, `:491` | stampa del riparto per capitoli |
| `GenerateSaldiAction.php` | `:231` | attribuzione del saldo solidale |
| `SituazioneDebitoriaController.php` | `:90` | situazione debitoria |

Inoltre tre punti leggono la pivot **senza filtrare nemmeno su `attivo`**: `PianoRateController.php:726`,
`IncassoRateService.php:179`, `StoreIncassoRateAction.php:366`. I primi due fanno `->value('tipologia')`
senza `orderBy`. **Oggi non sono un difetto**, perché sono chiavati su `(anagrafica_id, immobile_id)` e
la guardia 1 di `ValidatesImmobileAnagraficaPivot` impedisce alla stessa persona di avere due righe sulla
stessa unità: la riga è sempre una. Lo diventano **esattamente quando B2 rimuove quella guardia**. Il
terzo usa `exists()` ed è indifferente all'ordine in ogni caso.

La firma stessa del motore non ha una dimensione temporale:

```php
CalcoloQuoteService::calcolaPerGestione(Gestione $gestione, ?PianoRate $pianoRate = null, bool $soloLettura = false)
```

Una ricerca di `data_riferimento|allaData|asOf` su `app/` dà zero risultati. Non esiste alcun
pro-rata per giorni, in nessun punto del progetto.

### 1.2 Il flag `attivo` funziona ed è irraggiungibile

In lettura è onorato, e c'è un test che lo prova
(`tests/Feature/Gestionale/SaldoSolidaleRuoloTest.php:256`, «un occupante non attivo non partecipa»).
In scrittura no: `ImmobileAnagraficaController.php:135` e `:222` lo scrivono `true` fisso,
`updateExistingPivot()` non lo tocca, nessuna FormRequest lo valida, la parola non compare nelle
pagine Vue delle anagrafiche. Non esiste observer, job schedulato o comando che lo spenga alla
scadenza di `data_fine` (`app/Observers` non esiste). L'unica scrittura a `false` in tutto il
progetto è su un conto di cassa.

**È mezza soluzione del subentro già scritta, già testata, e chiusa a chiave.**

### 1.3 La storia non è nemmeno registrabile

`ValidatesImmobileAnagraficaPivot.php:29-45` rifiuta se la somma delle quote per tipologia supera
100, **senza filtrare né su `attivo` né su `data_fine`**. Il vecchio proprietario chiuso al 30/04 ha
ancora quota 100: il nuovo al 100 porta il totale a 200 e la richiesta si ferma. Restano due uscite,
entrambe sbagliate:

- **cancellare il vecchio** (`detach`, `ImmobileAnagraficaController.php:274`) → la storia sparisce e
  il 100 % della spesa va al nuovo;
- **spezzare le quote** (50/50, 33/67) → il motore le legge come comproprietà **contemporanea** e
  applica quella proporzione a *ogni* spesa dell'esercizio.

La guardia 1 dello stesso trait impedisce inoltre alla stessa persona di avere due periodi distinti
sulla stessa unità: chi vende e ricompra non è rappresentabile.

### 1.4 La fattura non ha un periodo di competenza

`fatture_passive` ha `data_documento`, `data_scadenza`, `esercizio_id`. Nient'altro. Esiste
`dati_extra.competenza = {dal, al}`: inizializzata in `FatturaRegisterNew.vue:176`, tipizzata in
`resources/js/types/gestionale/fatture.ts:54`, **persistita** da `FatturaPassivaService.php:189` —
e senza un solo input a video e senza un solo lettore in tutto `app/`. Un campo morto già in colonna.

`esercizio_id` è il primo esercizio con `stato = 'aperto'` (`HasEsercizio.php:45`), non derivato
dalla data documento, e nessuna guardia verifica che la data documento cada dentro l'esercizio.
L'unica leva temporale esistente ha la granularità dell'**esercizio**: se
`data_documento < esercizio.data_inizio` il frontend spunta da solo `is_pregresso`
(`FatturaRegisterNew.vue:391`) e la spesa esce dal budget corrente verso le passate gestioni.

### 1.5 L'interfaccia promette ciò che il motore non fa

Sette punti, verificati con `grep -rni "ubentr" resources/js/pages/gestionale/immobili/`:

| File | Riga | Testo |
| :--- | :--- | :--- |
| `AnagraficheNew.vue` | `:275-282` | «il sistema saprà esattamente quando interrompere l'addebito delle rate e come calcolare i riparti» |
| `AnagraficheNew.vue` | `:63` | «far subentrare automaticamente i nuovi soggetti» |
| `AnagraficheEdit.vue` | `:223` | «il sistema interromperà gli addebiti a questa data» |
| `AnagraficheEdit.vue` | `:60` | stessa promessa nella descrizione della card |
| `ImmobiliList.vue` | `:47-48` | «Gestione Subentri … calcolando i saldi di competenza per ogni periodo» |
| `AnagraficheList.vue` | `:50` | «Storico Subentri» — falso due volte: non c'è lettura, e lo storico non è registrabile (§1.3) |
| `ImmobiliView.vue` | `:150` | «comunicazioni di subentro» — non esistono e non sono in nessuna fase |

Da leggere prima di intervenire, perché **potrebbero essere veri**: `SaldiGuide.vue:104`,
`SaldiList.vue:64` (riparto del saldo solidale fra titolari di diritto reale), `PianoRateGuide.vue:110`,
`PianiRateNew.vue:820` e `:1295` (Rata 0 separata e riparto manuale del saldo solidale). Sono
meccanismi che esistono davvero.

### 1.6 Cosa il gestionale sa già fare

- **Il saldo solidale.** Un saldo con `anagrafica_id` NULL e `immobile_id` valorizzato viene attribuito
  a chi ha un diritto reale attivo alla generazione del piano, con catena per natura della gestione
  (`RuoloAnagraficaImmobile::catenaSaldoSolidale()`: straordinaria → nuda proprietà, proprietario;
  ordinaria → usufruttuario, proprietario). Esiste anche un **riparto manuale** del saldo solidale
  (`saldi_config[].ripartizioni[]`) deciso alla creazione del piano. È l'unica valvola manuale, e
  agisce solo sui pregressi.
- **Il dato del subentro, già importato.** Danea scrive il pro-rata dentro la colonna del ruolo del
  riparto consuntivo: `ex Pr 336 gg` su chi esce, `Pr 29 gg` su chi entra. `RipartoConsuntivoParser`
  lo riconosce (`SUFFISSO_GIORNI`) e lo **scarta**, emettendo l'avviso
  `riparto.titolarita_cambiata_in_corso_anno` che dichiara il limite all'amministratore.
- **La data della delibera.** `piani_rate.data_delibera_assemblea` esiste dalla migrazione
  `2026_03_22_075117`, è `required_if:approvato,true` in `PianoRateController:648`, ed è già
  valorizzata in produzione.

### 1.7 Cosa NON esiste

La solidarietà dell'art. 63 co. 4 disp. att. c.c. — l'acquirente obbligato in solido col venditore per
i contributi dell'anno in corso e del precedente — non è modellata in nessuna forma: non esiste una
struttura con **due obbligati** sullo stesso debito.

---

## 2. Il principio che ordina tutto

Tre domande diverse che oggi il gestionale confonde in una:

1. **Chi deve** — la ripartizione. Si risolve con il periodo di titolarità e la competenza della spesa.
2. **A chi posso chiedere** — la solidarietà dell'art. 63 co. 4. Si mostra come nota calcolata, non
   genera mai una quota a carico dell'acquirente.
3. **Cosa devo solo documentare** — il conguaglio fra due conduttori successivi (art. 9 L. 392/1978).
   Il condominio non è parte: produce i numeri, non arbitra.

Da questa separazione discende la dimensione di tutto il resto. Modellare il punto 2 come debito a due
obbligati toccherebbe `rate_quote`, incassi, situazione debitoria, solleciti e stampe: **resta fuori**.

---

## 3. Blocco A — difetti che esistono già, si correggono comunque

Nessuna di queste voci dipende dal subentro. Tutte in **1.10 (beta in corso)**, nessuna migrazione,
coerenti con il principio «i difetti restano nella release che li crea».

### A1 — Le sette promesse false

Si riscrivono i testi di §1.5. Il testo sostitutivo **non deve** dire «chiudi la vecchia riga e
censisci la nuova»: oggi non si può (§1.3). Deve dire che le date si registrano, che il riparto non le
legge, e che finché non le legge un subentro va gestito a mano. `ImmobiliView.vue:150` si **toglie**,
non si riscrive: le comunicazioni di subentro non sono in nessuna fase.

Migrazioni: nessuna. Costo: **0,5 giornate** più `npx vite build`.
Test: due prove vitest sui testi delle card, verifica a video su `http://127.0.0.1:8001`.

### A2 — La terza copia della cascata dei ruoli

`RipartoCapitoliService.php:474-497` è la **terza** implementazione della cascata, riscritta a mano e
sopravvissuta alla beta.49, che aveva unificato le altre due:

- liste cablate `['inquilino','usufruttuario','proprietario']` e `['proprietario']`;
- la condizione `&& $rip->soggetto !== 'proprietario'`, caduta altrove con la beta.43;
- `array_slice($catena, $start + 1)`, che su un `soggetto` fuori catalogo butta via il terminale legale;
- soprattutto `if ($anagrafiche->isEmpty()) continue;` dove le altre due copie **tracciano lo scoperto**.

Conseguenza a video: su un'unità con nuda proprietà e usufrutto **l'importo evapora dalla stampa per
capitoli mentre il motore lo ha addebitato**. Motore e stampa danno due numeri diversi — esattamente il
difetto che la beta.49 dichiara di aver chiuso.

Correzione: sostituire le liste con `RuoloAnagraficaImmobile::catenaRipiego()` e aggiungere il
tracciamento dello scoperto.

Migrazioni: nessuna. Costo: **1 giornata** (1,5 se propagare la struttura degli scoperti in quel
servizio richiede lavoro).
Test: estensione di `tests/Feature/Riparto/ConcordanzaMotoreStampaTest.php` alla stampa per capitoli,
più il caso del ruolo fuori catalogo.

### A3 — La riga stampata che non somma alle sue colonne

`RipartoTabelleService.php:262-290`: il totale di riga viene da `rate_quote`, le celle di colonna
vengono ricalcolate dal vivo dalla pivot (`:517`, `:544`), e il riallineamento di sicurezza è guardato
da `if ($residuo !== 0 && $peseTot > 0.0)`.

Se il soggetto è sparito dalla pivot dopo la generazione — oggi basta un `detach` o un cambio di
`tipologia` — tutte le celle valgono 0, `$peseTot` vale 0.0, il riallineamento **non scatta**, e il
documento stampa una riga con un totale e colonne a zero: `tot_per_tabella` non somma più a
`gran_totale`, cioè cade l'invariante che quel file dichiara a `:278` come **garanzia legale**.

Correzione: quando `$peseTot === 0.0`, l'intero residuo va nella pseudo-colonna `'diretto'`, che il
resto del codice tratta già come una tabella qualunque. Onesto («non attribuibile a una tabella») e le
due invarianti del documento restano vere.

Migrazioni: nessuna. Costo: **0,5 giornate**.
Test: genero un piano, faccio `detach` di un'anagrafica, ristampo, le colonne sommano ancora al gran
totale.

### A4 — Il comando di diagnosi, prima e non dopo

`php artisan km:verifica-titolarita`, sul modello di `VerificaSaldiSolidaliCommand`. Elenca per
condominio:

- le righe con `data_fine` valorizzata — chi si è fidato del testo di §1.5 e sta addebitando il
  venditore da mesi senza saperlo;
- le righe con `attivo = false`, che **non partecipano già** al riparto e che nessuna interfaccia può
  riaccendere;
- le coppie (immobile, tipologia) con più di una riga e somma quote ≠ 100 — i subentri rappresentati
  come comproprietà 50/50;
- le coppie con somma quote > 100, che possono esistere in banca dati perché non c'è alcun indice
  unico su `anagrafica_immobile`.

**Deve uscire con la 1.10, insieme ai testi corretti.** Chi legge «il riparto non usa ancora queste
date» deve poter sapere subito dove è già stato danneggiato.

Migrazioni: nessuna. Costo: **1 giornata**.
Test: un dataset con le quattro anomalie; il comando le trova tutte e non ne inventa.

### A5 — Due segnalazioni da non far marcire

> ✅ **Fatte entrambe nella beta.50 (12/08/2026).** Le due righe qui sotto descrivono lo stato
> *prima* di quell'intervento e si conservano perché contengono l'accertamento; il codice non è
> più così. `PianoRate` non ha più `data_inizio` in `$fillable` né nei `$casts`, e la `Resource`
> legge `created_at` **mantenendo la chiave `data_inizio` in uscita** — crearla avrebbe cambiato
> ciò che il frontend riceve. Le tre scritture di `tipologie_spese` sono state tolte; la colonna
> resta finché non cade con le migrazioni della 1.11, come dice §8.

- **`piani_rate.data_inizio` non esiste come colonna.** È in `$fillable` (`PianoRate.php:40`), nei
  `$casts` (`:59`) ed è letta da `PianoRateResource:49`. Verificato sullo schema reale: `piani_rate` ha
  26 colonne e quella non c'è. Oggi il difetto è mascherato dal fallback `?? $this->created_at`; un
  mass-assign con quella chiave fallirebbe in SQL. Da sistemare **prima** che B2 costruisca su quella
  tabella.
- **`anagrafica_immobile.tipologie_spese`** è scritta dal controller da `validated()`, non è validata
  da nessuna FormRequest — quindi è sempre `NULL` (verificato sul database: 60 righe, zero
  valorizzate) — e non è mai letta. Stessa malattia di `dati_extra.competenza`. Decisione in §8.

### ✅ A6 — Le due stampe divergono dopo una dissociazione *(chiuso nella beta.52)*

> ✅ **Corretto il 15/08/2026.** `RipartoCapitoliService` ricostruisce le righe dei soggetti
> presenti in `rate_quote` e porta il loro importo nella pseudo-colonna «Fuori riparto»; il
> dettaglio per capitolo non è ricostruibile perché `regole_calcolo` non lo conserva, e
> inventarlo su un foglio d'assemblea sarebbe stato peggio del difetto.
>
> ⚠️ **La correzione ha fatto emergere un difetto più vecchio che la mascherava**, trovato
> dalla revisione avversariale prima del rilascio: un soggetto **attaccato** dopo la
> generazione delle rate teneva il proprio lordo ricalcolato dal vivo, perché il
> riallineamento era guardato per-chiave (`?? null`) invece che per-matrice. Quel lordo
> fantasma era **compensato** dall'assenza della riga dell'orfano: i due errori si annullavano
> e il gran totale tornava per caso. Aggiungendo la riga dell'orfano si sono sommati —
> € 1.600,00 dove le rate emesse valevano € 1.000,00. Corretto con
> `?? (empty($totaliReali) ? null : 0)`, e presidiato da due test: il subentro **fatto per
> intero** (stacco *e riattacco*, che i primi tre test non facevano) e `verificaConcordanza()`
> puntata sulla matrice per capitoli — prima confrontava le due stampe fra loro, che regge
> finché sbagliano insieme.

> ⚠️ **È il difetto più grave di questo documento, e nella stesura del 12/08/2026 non c'era.**
> Non perché nessuno avesse visto lo scenario: A3 lo descrive per intero e lo ha chiuso nella
> beta.50. Il difetto è che lo ha chiuso **su una stampa sola**, e A2 descrive un percorso diverso
> allo stesso sintomo sulla gemella. I due si sfiorano e non si incrociano mai — ed è esattamente
> nell'incrocio che il difetto vive.

`RipartoTabelleService` costruisce le righe iterando `$totaliReali`, cioè le quote realmente emesse
in `rate_quote` (`:196-207`, ciclo a `:227`). `RipartoCapitoliService` le costruisce da
`$importiAssegnati`, cioè dai pesi **ricalcolati dal vivo sulla pivot** (`:198`), e usa
`$totaliReali` solo per riallineare righe che esistono già (`:217`).

Un soggetto dissociato dopo la generazione ha le sue quote in `rate_quote` e non ha più pesi:
**compare nella stampa per tabelle e sparisce da quella per capitoli.** Non a zero — proprio
assente, quindi le colonne quadrano fra loro e nessuna invariante interna se ne accorge.

**La misura, sulla fixture che esiste già.** `scenarioNudaProprieta()` produce un gran totale di
100000 centesimi, e il test «un titolare staccato dopo la generazione non rompe la quadratura del
documento» (`ConcordanzaMotoreStampaTest.php:241`) asserisce che il soggetto staccato vale 60000.
Dissociando e stampando per capitoli restano **40000**: sei euro su dieci fuori dal foglio che va
in assemblea.

**Perché nessun test lo prende.** I due test rilevanti esistono e non si incrociano: quello a
`:241` dissocia ma verifica **solo** `RipartoTabelleService`; quello a `:306` confronta i gran
totali delle due stampe ma **non** dissocia. La combinazione non è coperta.

Si arriva qui con il rimedio che gli amministratori usano oggi per il subentro, come dice il
commento del test stesso: genero le rate, stacco il vecchio proprietario, ristampo.

**Correzione:** `RipartoCapitoliService` deve partire dai soggetti presenti in `rate_quote` come fa
la gemella, non dai soli pesi vivi. `ConcordanzaMotoreStampaTest` si estende con il caso incrociato
— dissociazione **più** confronto fra le due stampe.

### A7 — Quattro anomalie di titolarità già nel database *(accertate il 15/08/2026 — ✅ causa corretta nella beta.56, righe non bonificate)*

> ✅ **La causa è chiusa dalla beta.56** (coda ㉔, opzione b): quando il file e l'archivio non
> concordano su chi è collegato a un'unità, il livello salta quell'unità e la elenca fra le cose da
> controllare, invece di aggiungere righe fino al 200 %. Corretto nella stessa beta anche il caso
> opposto: una titolarità **cessata** con la stessa terna faceva scartare la riga del file in
> silenzio, lasciando l'unità senza titolare attivo.
>
> **Le quattro righe restano dove sono, di proposito.** Stanno in condomìni di prova, valgono come
> dataset e cancellarle avrebbe tolto le tracce senza chiudere la causa — che ora è chiusa
> altrove. La bonifica, se si vorrà, è un gesto separato e successivo.

> ⚠️ **Riletto a fondo il 15/08/2026: la diagnosi della prima stesura era sbagliata, e la causa
> vera è più grave.** Stava scritto che gli immobili 227 e 228 fossero «subentri registrati come
> comproprietà» e che «costassero denaro adesso». Non è così su nessuno dei due punti: le quattro
> anomalie stanno tutte in condomìni di prova — 33 «Fixture», 31 «Condominio Collaudo 46», 29
> «Condominio Demo Foto» — quindi non c'è denaro vero in gioco. Ma **non sono subentri: sono
> importazioni ripetute**, ed è un difetto dell'importatore.

Verificate con query dirette sul database condiviso, non dedotte:

- **Immobili 227 e 228**: tre righe `proprietario` ciascuno, somma quote **200,00**. Le date di
  creazione delle righe raccontano cosa è successo. Sull'immobile 227 le due righe separate —
  `RUSSO LUCA` 50 e `COSTA SILVIA` 50 — nascono il 06/08 alle 18:52 (batch 2), e la riga
  congiunta `RUSSO LUCA / COSTA SILVIA` al 100 nasce alle 19:41 (batch 4). Sul 228 è l'inverso:
  prima la congiunta, poi le due separate il 07/08 alle 04:29 (batch 5). Il condominio 33 ha
  ricevuto **tre importazioni** e **due unità su 11** hanno, sullo stesso ruolo, somma quote diversa da 100.

  **La guardia di idempotenza c'è e non può bastare.** `LivelloTitolarita.php:149-160` confronta
  la tripla `(immobile, anagrafica, ruolo)` e salta ciò che esiste già: impedisce di duplicare la
  *stessa* riga, e funziona. Ma alla seconda importazione l'anagrafica è **diversa** — la coppia
  come soggetto unico contro i due coniugi separati sono tre `anagrafica_id` distinti — quindi la
  tripla non collide, l'inserimento passa, e l'unità finisce al 200 %. **Nessun punto
  dell'importatore guarda la somma delle quote già presenti sull'unità.**

  ⚠️ **Perché è grave e non è un caso di laboratorio.** Reimportare un file corretto è *il* gesto
  normale di una migrazione: l'amministratore vede che i coniugi sono entrati come soggetto unico,
  spacchetta la coppia nel file e reimporta aspettandosi che la correzione sostituisca il dato.
  Invece i due dati convivono. L'importatore è la voce di punta della 1.10 e la base della
  migrazione assistita del SaaS: un difetto che si manifesta al secondo tentativo è il difetto
  peggiore che possa avere.

  ⚠️ **Una correzione è stata scritta nella beta.52 e ritirata dalla revisione avversariale**, con
  quattro reperti «alta»: la decisione non era raggiungibile da nessuna schermata e l'API la
  rifiutava, non riparava il caso del condominio 33 (il file corretto è un *sottoinsieme* e non
  faceva scattare il conflitto), `sostituisci` avrebbe ricreato la somma 200 perché il parser non
  legge la quota, e cancellava senza reinserire in presenza di una riga spenta. **Il difetto resta
  aperto**, con la voce ㉔ in `roadmap.md` che elenca cosa serve davvero.
- **Immobile 204**: unico titolare `proprietario` con quota 50,00. Riceve comunque il 100 %, perché
  `pivot.quota` è un peso relativo fra soggetti dello stesso ruolo e non una riduzione della quota
  dell'unità. Chi ha scritto 50 pensando «metà» ha ottenuto l'addebito intero.
- **Immobile 202**: riga `inquilino` a quota 0,00, attiva. `CalcoloQuoteService:872-874` la salta con
  `continue` **senza** tracciarla come peso scoperto, mentre a `:848-863` lo scoperto viene tracciato.
  Il peso evapora in silenzio.

**Nessuno dei quattro è un dato di produzione**, e la bonifica non è urgente: i tre condomìni sono
di prova. Ciò che va fatto non è ripulire quelle righe — è chiudere il difetto dell'importatore
che le ha prodotte, altrimenti la stessa forma ricomparirà sulla prima migrazione vera. Le righe
di prova, semmai, servono come dataset del test che dimostrerà la correzione.

Resta valido il rilievo dell'immobile 204, che è una cosa diversa e più piccola: una quota di 50
su un titolare unico non è un errore del programma, è un dato che l'amministratore ha scritto
pensando «metà» e che il motore legge come «tutto», perché `pivot.quota` è un peso relativo fra
soggetti dello stesso ruolo. Il difetto lì è che il programma non lo dice.

### ⚠️ A8 — `conto_tabella_ripartizioni.soggetto` non conosce la nuda proprietà *(parzialmente chiuso nella beta.52)*

> ✅ **Chiusi nella beta.52:** la sigla `nuda_proprietario` in `RipartoCapitoliService`,
> `after_or_equal:data_inizio` in `UpdateImmobileAnagraficaRequest`, `min:0|max:100` su `quota`
> in entrambe le Request.
>
> ⚠️ **Resta aperto l'enum**, che è una migrazione e va con B2. E resta aperto un difetto
> gemello trovato dalla revisione: **la mappa dell'ordinamento** dei ruoli in
> `RipartoCapitoliService` non ha `nuda_proprietario` e lo manda in fondo, dopo l'inquilino,
> mentre la gemella lo mette accanto al proprietario — due documenti della stessa assemblea
> elencano gli stessi soggetti in ordine diverso. La sigla è stata allineata e la mappa a 200
> righe di distanza no: è esattamente il mezzo allineamento che questa beta denunciava.

`SHOW COLUMNS` restituisce `enum('proprietario','inquilino','usufruttuario')`. L'enum PHP
`RuoloAnagraficaImmobile` ha **quattro** casi dalla beta.43, e `nuda_proprietario` non è scrivibile
su questa colonna: un coefficiente che debba nominare il nudo proprietario non è registrabile.
Diventa bloccante nel momento in cui B2 chiede di nominare il soggetto giusto in un periodo.

Nello stesso perimetro, e più piccolo: la mappa delle sigle di `RipartoCapitoliService:124-128` non
ha `nuda_proprietario` e ricade su `strtoupper(substr(..., 0, 1))`, cioè **«N»**, una sigla che la
legenda del documento non contiene. La gemella `RipartoTabelleService:219-223` è già corretta, con
il commento che spiega perché: la correzione è stata applicata a un servizio solo.

E in `UpdateImmobileAnagraficaRequest:36` manca `after_or_equal:data_inizio` su `data_fine`, che
invece `CreateImmobileAnagraficaRequest:47` ha: **in modifica si salva una data di fine anteriore
alla data di inizio.** `quota` è validata `required|numeric` senza `min` né `max`, ed è il modo in
cui le anomalie di A7 sono entrate.

**Totale blocco A: 3 giornate, zero migrazioni, zero cambi al motore di calcolo** — più A6, A7 e A8,
accertati il 15/08/2026 e non stimati.

> ⚠️ **A2 è mezzo chiuso, e la roadmap non lo sa.** A2 prescrive due cose: sostituire le liste
> scritte a mano con `RuoloAnagraficaImmobile::catenaRipiego()`, e aggiungere il tracciamento dello
> scoperto. **La prima è uscita nella beta.51** — `RipartoCapitoliService:499` usa già
> `catenaRipiego()`, con il commento che lo spiega. La seconda no: `:508` è un `continue` nudo, e
> la parola «scoperto» non compare nel file, mentre la gemella ha `$pesoScopertoTotale` a `:489` e
> `:572-574`. La voce di 1.10.1 in `roadmap.md` alloca ancora A2 per intero: chi la aprirà
> pianificherà cinque giornate su un blocco che ne vale metà.

---

## 4. Blocco B — la funzione nuova

### 4.1 La trappola da evitare

È il risultato più importante del panel. Tutte e tre le proposte avevano una fase intermedia che apriva
la **registrazione** di due periodi consecutivi *prima* dell'aritmetica che li divide. Tre giudici su
nove, indipendentemente, l'hanno chiamata difetto fatale per la stessa ragione:

> due titolari al 100 % si normalizzano a `sommaQuote = 200` e prendono **il 50 % ciascuno**, identico
> per un rogito di gennaio e uno di dicembre, con la firma del sistema sotto.

È **peggio** del difetto attuale, che almeno addebita una volta sola. E in mezzo alle due release c'è
un consuntivo che va in assemblea.

**Regola: nulla che renda registrabili due periodi esce prima del pro-rata che li divide.**

### 4.2 Decisioni di dominio

**D1. Il criterio è la competenza, cioè quando il costo matura.** La data documento è solo il default
proposto; la data di pagamento non entra mai nel riparto e continua a servire alla cassa.

**D2. Una data puntuale è un periodo lungo un giorno.** Un solo primitivo,
`PeriodoCompetenza [dal, al]`; il caso della delibera è quello degenere `dal = al`. Due meccanismi
separati sarebbero due copie della stessa aritmetica, che in questo progetto divergono: è già successo
e il conto lo ha pagato la beta.49.

**D3. Per lo straordinario decide la delibera, e la data c'è già.** Cascata di risoluzione del
periodo: competenza dichiarata sulla fattura o sulla copertura → `piani_rate.data_delibera_assemblea`
→ periodo del capitolo → periodo della gestione → periodo dell'esercizio. **Il gradino usato si mostra
e si congela.**

**D4. Il periodo decide chi paga, non in quale esercizio va il costo.** Una fattura a cavallo resta
interamente nell'esercizio in cui è registrata. È il paletto che tiene questa funzione fuori da ratei e
risconti, e va scritto nella maschera, non in una guida.

**D5. La data (o il periodo) di riferimento è una proprietà del riparto, non del soggetto.** Si
propone, si mostra in anteprima, si può cambiare, e si congela in
`regole_calcolo.parametri.titolarita_alla` insieme al gradino della cascata e ai giorni conteggiati per
titolare. Fra due anni la risposta a «perché questa rata è intestata a lui» sta nel documento, non in
una regola che nel frattempo è cambiata.

**D6. La stampa rilegge la data congelata e non ricalcola mai un default.** Seconda metà obbligatoria:
**il filtro temporale non può mai escludere dalla stampa un soggetto presente in `rate_quote`**. La
data congelata da sola non basta, perché la pivot può essere modificata dopo la generazione.

**D7. Regola dell'apertura ereditata.** `data_fine` filtra sempre. `data_inizio` filtra **solo se**
sulla stessa coppia (immobile, tipologia) esiste una riga chiusa che la precede. Una riga senza
predecessore chiuso è aperta da sempre. *(È il compromesso che risolve il disaccordo n. 1, §6.)*

**D8. Il pro-rata si applica solo quando nel periodo c'è davvero un cambio di titolarità**, e in tutti
gli altri casi l'espressione eseguita è letteralmente `quota / somma_quote`, senza fattori che si
semplificano. È una garanzia di identità in virgola mobile, non un'ottimizzazione: va scritta come
uscita anticipata dell'unica funzione e commentata come tale.

**D9. Le rate emesse non si toccano mai.** Il subentro tardivo si corregge con una coppia di righe in
`saldi` sulla stessa gestione e sullo stesso immobile — credito all'uscente, debito all'entrante, somma
esattamente zero — che il piano successivo assorbe. Il conguaglio si calcola sulla **competenza**, mai
sullo stato di pagamento: se l'uscente non ha pagato, la sua morosità resta sua.

**D10. `attivo` resta una condizione AND dentro il risolutore**, non viene letto da nessun'altra parte,
non riceve interfaccia, e la sua rimozione si programma per la 2.0. Non si spegne prima:
`SaldoSolidaleRuoloTest:256` asserisce che un occupante non attivo non partecipa, e in banca dati ci
sono righe che ci contano.

**D11. Il condominio non arbitra fra due conduttori.** Verso il condominio paga il proprietario; il
gestionale produce il prospetto con cui il proprietario fa il conguaglio, dichiarando in testa che
divide per giorni di conduzione e che dove c'è contabilizzazione del calore il risultato è un altro.

### 4.3 B1 — Il risolutore unico, senza un solo cambio di comportamento

**Versione: 1.10.1.** *(Vedi decisione 2 in §8: potrebbe diventare la mossa di apertura della 1.11.)*

Nasce `App\Services\Riparto\RisolutoreTitolari`, con due forme sulla stessa regola:

- `attiviAlla(Collection $anagrafiche, PeriodoCompetenza $p)` per i punti in memoria — il motore
  lavora su collection eager-loaded, non su query;
- `vincolaQuery(Builder $q, PeriodoCompetenza $p)` per i punti che usano `DB::table`;

più un test che le fa rispondere identico sullo stesso dataset. **In questa fase il risolutore risponde
esattamente come oggi**: `attivo === true` e nient'altro.

**L'inventario dei punti è di 18, non di sei.** Nessuna delle tre proposte li aveva tutti, e la prima
stesura ne conteneva cinque **falsi positivi** — `attivo` su `piani_rate`, `conti_contabili`, `conti` e
`immobili`, non sulla pivot. Elenco riverificato il 12/08/2026 con
`grep -rn "anagrafica_immobile\|pivot\.attivo" app/`:

*Riparto e attribuzione — il cuore, tutti filtrano su `attivo`:*

```
CalcoloQuoteService.php:515   (addebitaDiretto), :821, :839
RipartoTabelleService.php:517, :544
RipartoCapitoliService.php:476, :491
GenerateSaldiAction.php:231
```

*Lettura del ruolo e contorno — **gli ultimi tre non filtrano nemmeno su `attivo`***:

```
SituazioneDebitoriaController.php:90
PianoRateController.php:726
StoreIncassoRateAction.php:366
IncassoRateService.php:179
```

*Importatore e diagnosi:*

```
LivelloTitolarita.php:150, :234
LivelloSaldi.php:417
UnitaConTitolare.php:42
TitolaritaDeiSaldi.php:39
VerificaSaldiSolidaliCommand.php:218
```

**Sui tre punti senza filtro su `attivo`, la formulazione precisa conta.** Sono chiavati su
`(anagrafica_id, immobile_id)` e oggi la guardia 1 di `ValidatesImmobileAnagraficaPivot` garantisce che
la riga sia una sola: **non sono un difetto attuale**, e due comproprietari non li scalfiscono perché
sono due `anagrafica_id` diversi. `PianoRateController:726` e `IncassoRateService:179` fanno però
`->value('tipologia')` senza `orderBy`, quindi diventano non deterministici **nel momento esatto in cui
B2 rimuove quella guardia** per permettere alla stessa persona due periodi successivi.
`StoreIncassoRateAction:366` usa `exists()` e non è mai toccato.

Vanno quindi sistemati in **B1**, non in B2: quando la guardia cade devono già essere deterministici,
altrimenti il difetto nasce insieme alla funzione che lo espone.

Nella stessa fase, e solo come impianto:

- `calcolaPerGestione()` prende `?PeriodoCompetenza $periodo = null` **in coda e nullabile** — ha 49
  punti di chiamata fra `app/` e `tests/`, un parametro obbligatorio raddoppierebbe il costo.
  `calcolaDaFattureStraordinarie()` lo prende esplicitamente, perché è il secondo entry point pubblico
  e non condivide lo stato d'istanza.
- `GenerateRateQuotesAction` scrive `regole_calcolo.parametri.titolarita_alla` accanto ai parametri che
  già scrive. Chiave additiva: i consumatori dello snapshot leggono sottochiavi specifiche e nessuno
  itera, ma vanno riverificati tutti e nove — incluso `PianoRateResource:71` e `:75`, che interrogano in
  `JSON_EXTRACT` e non sono protetti da un accessor.
- `RipartoTabelleService` legge quella chiave invece di derivare un default, e non esclude mai un
  soggetto presente in `rate_quote` (D6).
- Le relazioni `belongsToMany` di `Immobile` (`:82-95`) e `Anagrafica` espongono `id` in `withPivot` e
  usano un modello pivot dedicato `App\Models\TitolaritaImmobile` con i cast sulle date. Oggi non c'è
  né l'uno né l'altro, e senza `updateExistingPivot($anagraficaId, …)` le rotte `edit()` e `destroy()`
  continuerebbero a lavorare **per persona** invece che per periodo.

**Migrazioni: nessuna.** Le colonne esistono dalla creazione della tabella; `id` c'è già e va solo
esposto.
**Costo: 4 giornate.**
**Cancello di rilascio:** il riparto è identico al centesimo su tutta la suite. Se una fixture cambia
di un centesimo, la fase non esce.

### 4.4 B2 — Il tempo entra nel calcolo, e la registrazione si apre insieme

**Versione: 1.11, dentro il Motore Riparto Unificato.** Un solo blocco, per la ragione di §4.1.

> ⚠️ **Correzione del 15/08/2026 — il criterio di competenza è doppio, non unico.** Questo blocco,
> nella stesura del 12/08/2026, assume un solo criterio e un solo pro-rata. È sbagliato, e l'errore
> è quantificabile.
>
> Per le spese **straordinarie** — innovazioni, manutenzione straordinaria, ristrutturazioni —
> l'obbligazione sorge con la delibera che approva opere e prezzo, e quella delibera è costitutiva
> anche se i lavori si eseguono dopo il rogito (Cass. civ. sez. VI-2 ord. 22 giugno 2017 n. 15547;
> Cass. civ. sez. II ord. 10 settembre 2020 n. 18793; Cass. civ. 30 agosto 2025 n. 24236, in
> continuità con Cass. n. 25839/2019, che precisa che rileva la delibera *attuativa* e non quelle
> preparatorie).
>
> Per le spese **ordinarie** no: l'obbligazione sorge nel momento in cui si compie l'attività
> gestionale che le giustifica, e l'approvazione del preventivo **non è costitutiva** — serve a
> vagliare la congruità della previsione (Cass. civ. sez. II 3 dicembre 2010 n. 24654; Cass. civ.
> sez. II ord. 3 agosto 2022 n. 24069). La distinzione è affermata testualmente proprio
> nell'ordinanza che si cita di solito a sostegno del criterio unico, **Cass. civ. sez. VI-2 28
> aprile 2021 n. 11199**: obbligato è chi è proprietario al momento dell'**esecuzione** per le
> spese ordinarie, chi lo era al momento della **delibera** per straordinarie e innovazioni.
>
> **Il numero.** Esercizio solare, preventivo ordinario approvato il 15 gennaio, rogito il 30
> giugno, gestione ordinaria da € 120.000,00. Con un criterio unico agganciato a
> `piani_rate.data_delibera_assemblea` l'intero esercizio resta al venditore: **€ 60.000,00
> attribuiti alla persona sbagliata**. E siccome il conguaglio è una coppia di righe in `saldi`,
> l'errore viene cristallizzato in contabilità invece di restare un'anteprima correggibile.
>
> **Conseguenze su questo blocco.** Il conguaglio non ha una forma sola: pro rata per giorni
> sull'ordinario, **funzione a gradino** sulla data della delibera attuativa sullo straordinario.
> Un'unica coppia pro-rata su una gestione che contiene entrambi produce un numero giusto per metà.
> Non serve un parametro «criterio delibera / criterio esecuzione», che trasformerebbe una regola
> di diritto in una preferenza dell'utente: serve che la regola sia doppia e agganciata alla natura
> della gestione, e che resti possibile dichiarare un periodo di competenza diverso sulla singola
> fattura o copertura, perché la diversa convenzione fra le parti è ammessa e capita.
>
> **E le date rilevanti sono tre, non due:** la data dell'atto, che decide chi deve; la data di
> trasmissione della copia autentica del titolo, che è l'unica che libera il venditore verso il
> condominio (art. 63 co. 5 disp. att. c.c.) e non coincide quasi mai col rogito; la data della
> delibera o dell'attività gestionale, che decide a quale dei due la spesa compete.

> 📐 **L'interfaccia di questo blocco è progettata, e sta altrove.** Il §6 di
> [`pertinenze_vendita_locazione.md`](pertinenze_vendita_locazione.md) contiene la progettazione
> completa dell'operazione — «Registra passaggio» invece di «Modifica associazione», una sola data
> chiesta con il giorno prima calcolato a video, il pannello «Cosa cambierà» con i quattro blocchi,
> lo storico come frasi e non come righe di database, il cancello che scatta solo se ci sono rate
> emesse — con i testi definitivi e i file Vue indicati. **Non è una funzione parallela: è
> l'interfaccia di B2**, ed è nata dalla domanda sulle pertinenze solo perché il box venduto da solo
> è il caso tipico del subentro. Va letta insieme a questo blocco, e chi realizza B2 non deve
> riprogettarla.

- **Il pro-rata.** Peso = `quota × giorni di sovrapposizione` fra il periodo del titolare e quello
  della spesa, normalizzato sulla somma degli stessi prodotti, con l'uscita anticipata di D8.
  `MoneyHelper::ripartisciPerQuote()` e `distribuisciImporto()` restano **intatti**: il pro-rata entra
  nei pesi, non nell'aritmetica dei centesimi.
- **`ValidatesImmobileAnagraficaPivot`.** La guardia 2 somma le quote **per giorno** invece che in
  assoluto; la guardia 1 («questa anagrafica è già collegata a questo immobile») diventa divieto di
  **periodi sovrapposti per la stessa persona con lo stesso ruolo** — così l'inquilino che compra e il
  venditore che ricompra diventano registrabili.
- **Operazione «Registra subentro»** sull'unità: chi esce, chi entra, con che ruolo, da che data. In una
  transazione chiude il periodo precedente al giorno prima, ne apre uno nuovo, e — se ci sono rate già
  emesse — propone la coppia di righe a somma zero di D9, **mostrando gli importi prima di scrivere**.
  Il calcolo vive in `App\Support\ProRataTemporis`, chiamato sia qui sia dal motore: una sola copia,
  decisa adesso che la seconda non esiste ancora.
- **L'operazione scrive un evento datato con un autore.** Oggi `saldi` non ha `created_by` e `origine`
  è un ENUM MySQL a tre valori (`manuale`, `importato`, `automatico`): una riga di subentro sarebbe
  indistinguibile da un saldo iniziale digitato a mano, e `SaldoInizialeController::update()` /
  `::destroy()` permettono di toccarne una sola finché `is_applicato` è false. L'invariante «la coppia
  somma zero» va **presidiata**, non dichiarata. Lo standard di casa esiste già su `piani_rate`
  (`approvato_da_user_id`, `approvato_il`).
- **Competenza a video.** `dati_extra.competenza` diventa una coppia di colonne vere, con travaso una
  tantum dal JSON. Due modi nell'interfaccia — «costo maturato dal … al …» e «spesa deliberata il …»,
  che scrive la stessa data nei due campi — e un testo che dice **quando** si compila, altrimenti è un
  campo che si salta col tab.
- **Anteprima con cancello.** Quando la risoluzione temporale **ha cambiato un destinatario**, la
  generazione non prosegue senza spunta e nota, sullo schema di `accetta_scoperti` (nota di almeno 10
  caratteri, congelata su `piani_rate`). Attenzione al costo: `ScopertiNonAccettatiException` scatta
  **solo** se ci sono scoperti, e un'anteprima generale del riparto non esiste — l'unica in codice è
  `anteprimaSolidale`. Il cancello va costruito, non riusato.

**Migrazioni, una per una.** *(1.11 non ha il vincolo del backup; da dichiarare in apertura del
changelog, e tutte da aggiungere al dataset di `UpgradeMigrationsRerunTest`.)*

1. `fatture_passive.competenza_dal`, `competenza_al` — date nullable, guardia `Schema::hasColumn`
   separata, più il travaso da `dati_extra->competenza` dove valorizzato.
2. `piano_rate_capitoli.competenza_dal`, `competenza_al` — date nullable.
3. `subentri` — tabella nuova: immobile, anagrafica uscente, anagrafica entrante, ruolo, decorrenza,
   utente, timestamp, nota. Guardie separate per colonna e per foreign key.
4. `saldi.subentro_id` — FK nullable verso `subentri`, che lega le due righe della coppia e impedisce di
   cancellarne una sola.
5. `anagrafica_immobile` — indice **non** unico su `(immobile_id, tipologia, data_inizio)`. Nessun
   indice unico: la storicizzazione lo esclude.

Nessun backfill obbligatorio: nullo significa «scendi al gradino successivo della cascata», che è il
comportamento di oggi.

**Costo: 12-14 giornate.** Non sono le 5-6 stimate dalle proposte. Il grosso non è il pro-rata, che è un
rapporto fra due conteggi di giorni: è che la rotta è legata all'anagrafica (`edit()` recupera con
`->where('anagrafica_id', …)->first()`, `destroy()` fa `detach($anagrafica->id)`), che
`SituazioneDebitoriaController:93` fa `->where('attivo', true)->first()` senza `orderBy` e diventa
**non deterministico** appena le righe sono due, e che va dimostrato che i piani esistenti si
rigenerano identici. Il cancello dell'anteprima vale 2 giornate da solo.

### 4.5 B3 — Il prospetto oneri accessori per periodo

**Versione: 1.11**, subito dopo B2, indipendente da tutto il resto.

La risposta allo scenario 2, e **non è un riparto**: un documento per unità che elenca le spese
ripartite con soggetto `inquilino` nel periodo, divise per periodo di conduzione, che il proprietario
porta al conguaglio. Sola lettura, nessuna invariante nuova nel motore, nessuna migrazione. In testa
dichiara il criterio usato e il limite della contabilizzazione calore.

È la parte che costa meno e che si userà più spesso, perché il cambio inquilino è più frequente della
compravendita. Non può uscire prima di B2 solo perché senza due periodi registrabili non ha due periodi
da dividere.

**Costo: 2,5 giornate** (4 con la versione per l'intero condominio).

### 4.6 B4 — Le decorrenze dall'importatore

**Versione: 1.11, coda importatore.**

`RipartoConsuntivoParser` riconosce già `ex Pr 336 gg` e `Pr 29 gg` con `SUFFISSO_GIORNI` e li butta.
Da quei due numeri si ricava la data del passaggio e la si scrive in `LivelloTitolarita`, che oggi
inserisce una riga sola con `data_inizio` all'inizio dell'esercizio e `attivo = true`.

La data va marcata come **stimata dal conteggio giorni**, non letta da un atto: un dato ricavato che si
spaccia per dichiarato è peggio di un dato mancante. Quando i due conteggi non sommano ai giorni
dell'esercizio, l'importatore **non indovina**: importa il titolare attuale senza decorrenza e lo
dichiara nel rapporto. Vanno riscritti l'avviso e la voce di `CatalogoControlli`, che oggi dicono che il
subentro non è gestito.

**Migrazioni: nessuna. Costo: 2 giornate.**

---

## 5. Invarianti scrivibili come test

**Non regressione**

1. Con `data_fine` nulla ovunque, il riparto è identico al centesimo a quello precedente, su una fixture
   con centesimi dispari. *Cancello di rilascio di B1 e B2.*
2. Con le colonne di competenza a nullo, il riparto coincide con quello della fase precedente.
3. Un ruolo fuori catalogo ricade sul terminale legale in **tutte e tre** le stampe e non fa sparire un
   importo.

**Identità e determinismo**

4. `attiviAlla()` e `vincolaQuery()` restituiscono lo stesso insieme sullo stesso dataset.
5. Un periodo `[d, d]` dà lo stesso risultato della risoluzione puntuale a `d`.
6. L'ordine di iterazione dei titolari è parte del risultato: `data_inizio`, poi `anagrafica_id`. La
   somma in virgola mobile non è associativa e due ordini danno due ultimi centesimi diversi.

**Titolarità**

7. `data_fine` anteriore al riferimento esclude; nulla non esclude mai.
8. `data_inizio` esclude **solo** se sulla stessa coppia (immobile, tipologia) esiste una riga chiusa che
   la precede; altrimenti è inerte (D7).
9. **Scenario 1 per intero:** delibera 01/03, subentro 01/05, fattura 01/08 pagata 01/09 → l'intero
   importo al venditore, zero all'acquirente, **e nessuna configurazione produce 50/50**.
10. **Scenario 2 per intero:** competenza 01/10-01/05, subentro inquilino 01/06 → l'intero importo al
    vecchio inquilino.
11. Venditore chiuso al 30/04 e acquirente al 100 % dal 01/05 passano la validazione; due periodi
    sovrapposti oltre 100 in un giorno no, e il messaggio nomina la sovrapposizione.
12. La stessa persona può avere due periodi non sovrapposti sullo stesso immobile.
13. Un subentro esattamente a metà periodo su un importo dispari chiude al centesimo con la regola dei
    resti maggiori già in uso.

**Documento**

14. Motore e **tutte e tre** le stampe escludono lo stesso insieme e arrotondano bit-identico.
    `ConcordanzaMotoreStampaTest` si **estende**, non si affianca.
15. La ristampa di un piano generato prima di un subentro registrato dopo è identica al centesimo. Il
    test deve **chiudere una `data_fine` fra generazione e ristampa**, altrimenti verifica il caso che
    non succede mai e passa verde per la ragione sbagliata.
16. Le colonne del documento sommano sempre al gran totale, anche quando un soggetto presente in
    `rate_quote` non è più nella pivot (A3).
17. `regole_calcolo.parametri` contiene la data o il periodo usato, il gradino della cascata e i giorni
    conteggiati per titolare, e coincide con quanto mostrato in anteprima.

**Denaro**

18. Il totale ripartito **per conto** non cambia al variare del numero di titolari successivi.
19. La coppia di righe generata da un subentro somma esattamente zero, e nessuna delle due è cancellabile
    o modificabile da sola.
20. Il conguaglio si calcola sulla competenza e mai sullo stato di pagamento: con `importo_pagato = 0`
    sull'uscente, la sua morosità resta sua.
21. Un subentro registrato dopo l'emissione non modifica alcuna riga di `rate_quote`.
22. Un inquilino non compare mai fra i destinatari di un saldo solidale né di un addebito diretto.
23. Rieseguire le cinque migrazioni di B2 è un no-op strutturale, senza doppio travaso.

### L'invariante che è stato falsificato e va tolto

> «€ 1.000,00 su un'unità restano € 1.000,00 spezzati fra uno, due o tre titolari.»

Verificato su `CalcoloQuoteService::distribuisciImporto()` (`:1160`): il largest-remainder è
**unico su tutto il conto**, con chiavi `anagrafica_id|immobile_id`. Spezzare il peso di un'unità in due
chiavi cambia i `floor`, la somma delle basi e l'ordinamento dei resti, quindi **può spostare un
centesimo su un'altra unità dello stesso conto**. Il totale del conto quadra sempre.

L'invariante corretto è il n. 18. Quello per unità si otterrebbe solo **annidando** la ripartizione,
che significa toccare il primitivo di arrotondamento — vedi decisione 7 in §8.

---

## 6. Dove i giudici erano in disaccordo, e chi prevale

**1. `data_inizio` filtra o no.** «Minimo» dice mai: sui dati reali `data_inizio` va da 2024-11-01 a
2026-07-27 su 60 righe, cioè è il giorno del **censimento**, non la decorrenza del diritto; usarla come
apertura escluderebbe titolari veri e manderebbe le loro quote a scoperto. I giudici di «modello» e
«dominio» dimostrano però che senza filtro sull'apertura lo scenario 1 produce 50/50.
→ **Prevale la regola dell'apertura ereditata (D7)**, che li accontenta entrambi: su un'unità con una
sola riga il comportamento è identico a oggi (60 righe su 60), su un'unità con un subentro registrato
l'apertura filtra. Nessuna bonifica dei dati, nessun preventivo che svuota mezzo palazzo.

**2. Quando si apre la registrazione dei periodi.** «Modello» in 1.10.1 senza pro-rata, «dominio» in
1.10.1 col pro-rata solo nei saldi, «minimo» non la apre affatto.
→ **Prevale la fusione: registrazione e pro-rata nella stessa release.** L'amministratore e il
manutentore, su proposte diverse, hanno chiamato «difetto fatale» la stessa cosa (§4.1).

**3. Dove sta la data della delibera.** «Dominio» propone `gestioni.data_delibera`, «modello» un campo
nuovo sulla fattura.
→ **Prevale `piani_rate.data_delibera_assemblea`, che esiste già.** `gestioni` è unica per
`(condominio_id, nome)`, attraversa più esercizi e ha già `data_inizio`/`data_fine` mai lette: una
gestione «Straordinaria» con due lavori deliberati in mesi diversi non può avere una data sola. Un campo
sulla fattura farebbe ridigitare la stessa data su progettista, direzione lavori, sicurezza, impresa,
SAL e collaudo. Si evita una colonna e si sbaglia meno.

**4. Anteprima informativa o bloccante.** Il principio di trasparenza chiede di mostrare; due giudici
osservano che un avviso in un elenco si scorre via.
→ **Prevale il cancello**: spunta e nota quando la risoluzione temporale ha cambiato un destinatario.
Non è pesantezza — produce una giustificazione scritta e congelata, che è quello che serve in assemblea
sei mesi dopo. E va contata come lavoro, perché quel canale oggi non copre questo caso.

**5. Che fine fa `attivo`.** «Modello» dice di smettere di leggerlo, «minimo» e «dominio» di tenerlo.
→ **Prevale tenerlo come AND dentro il risolutore**, senza interfaccia, con rimozione programmata alla
2.0. Smettere di leggerlo farebbe **rientrare** nel riparto le righe con `attivo = false`, contro un test
che asserisce il contrario.

**6. Priorità del prospetto oneri accessori.** Un giudice lo vuole subito dopo i testi, la proposta lo
mette settimo.
→ **Prevale subito dopo B2**, primo momento tecnicamente possibile. Ma è staccato da tutto: se
qualcos'altro slitta, esce lo stesso.

**7. Quanto vale l'invariante penny-perfect.** «Modello» lo scrive per unità, il revisore lo falsifica
sul codice.
→ **Prevale il revisore** (§5, ultimo paragrafo).

---

## 7. Cosa resta fuori

- **La solidarietà dell'art. 63 co. 4 come debito a due obbligati.** La nota calcolata dalle date compare
  nella situazione debitoria e nell'estratto conto dell'unità, ma nessuna quota viene intestata
  all'acquirente e nessun sollecito lo raggiunge in automatico. Va detto che la nota si **ricalcola**
  dalle date e non è un'evidenza congelata: se serve in un decreto ingiuntivo deve nascere da un evento
  datato, cioè da `subentri` (B2), e solo per i subentri registrati da lì in avanti.
- **La riassegnazione di un saldo già scritto a un'altra anagrafica**, cioè la voce di
  `docs/roadmap.md:1165`. Questo progetto chiude la parte «valorizzare le decorrenze», **non** la parte
  «sostituire il saldo solidale con la riassegnazione vera». Va scritto nel changelog, non lasciato
  dedurre.
- **Ratei e risconti fra esercizi** (D4).
- **La contabilizzazione del calore (UNI 10200)**, che non compare in una riga di codice. Il pro-rata
  divide per tempo, non per consumi, e non è un sostituto.
- **La competenza per singola riga di fattura.** Si dichiara per fattura; una fattura con due periodi si
  spezza in due.
- **La derivazione dell'esercizio dalla data documento.** `HasEsercizio:45` continua ad assegnare il primo
  esercizio aperto; si aggiunge solo un avviso non bloccante quando la data documento cade fuori.
  Cambiarla tocca ogni registrazione.
- **Il ramo «restituisci invece di compensare»** per l'uscente che aveva già pagato: la compensazione
  riusa il flusso della beta.46, la restituzione è un movimento di cassa e va progettata a parte. Vedi
  decisione 6.
- **L'estratto conto del condòmino per periodo di titolarità**, che è la richiesta prevedibile appena i
  periodi esisteranno.
- **`nuda_proprietario` nell'ENUM di `conto_tabella_ripartizioni.soggetto`**: buco reale (tre valori
  contro i quattro dell'enum PHP) ma ortogonale, da sanare quando si tocca quella tabella insieme alla
  conversione a VARCHAR richiesta dal principio 10.
- **Nessun test legge i PDF.** `ConcordanzaMotoreStampaTest` confronta i pesi, non il foglio. A2 e B2
  aggiungono righe alla stampa senza che nulla dimostri che compaiano sul documento. O si accetta, o è
  una voce di lavoro a sé.

---

## 8. Decisioni — prese il 12/08/2026

> Vincenzo ha delegato l'organizzazione dei lavori chiedendo che sia documentata e motivata. Queste
> sono le dieci risposte, con il ragionamento. Restano decisioni sue: se una non convince, si cambia
> qui e si aggiorna la voce di roadmap.

### Il criterio che le ordina tutte

**La 1.10 riceve solo ciò che è già rotto. Nessuna funzione nuova.**

Tre ragioni che convergono:

1. **La 1.10 è l'unico aggiornamento senza backup automatico.** È scritto nella roadmap e vale più di
   qualunque considerazione di comodo: ogni migrazione evitabile lì è un rischio evitato. B2 ne porta
   cinque.
2. **La 1.10 si chiama «The Core Engine Release».** Una release sul motore di calcolo che esce con una
   **terza copia divergente** della cascata dei ruoli non merita quel nome. A2 non è un costo che si
   aggiunge alla 1.10: è un debito che quella release ha contratto e che deve pagare prima di chiudere.
   Vale anche per A3, che rompe un'invariante che il codice stesso dichiara «garanzia legale».
3. **La catena a valle non ha margine.** 1.18 entro settembre, 2.0 a ottobre, SaaS a gennaio. Aggiungere
   12-14 giornate alla 1.10 sposta tutto di due settimane per una funzione che non ha una scadenza:
   l'amministratore che l'ha chiesta fa quel conto a mano da 35 anni.

Il contrario — infilare B2 nella 1.10 per non farlo aspettare — è il modo per ottenerlo fatto male. Il
difetto fatale di §4.1 non nasce dalla fretta di chi progetta, nasce dalla fretta di chi rilascia.

---

**1. Il blocco A si divide: A1, A3, A4, A5 in 1.10 — A2 resta dove la roadmap l'ha già messo.**
⚠️ **Sì, ma non «tutto intero».**

La prima stesura diceva «blocco A tutto in 1.10». È stata corretta il 12/08/2026 dopo aver riletto la
tabella dei blocchi del rilascio: la riga **⑩** (`roadmap.md:98`) dichiara già chiusa la famiglia della
cascata nella beta.49 e colloca esplicitamente il resto — «*restano il netting del già-versato e la
terza copia (`RipartoCapitoliService`) → 1.10.1*». **A2 era già stato deciso, e in 1.10.1.** Riportarlo
in 1.10 senza dirlo sarebbe stato scavalcare una decisione presa, che è esattamente ciò che questa
roadmap si sforza di non fare.

Quindi:

**In 1.10 — 2,5 giornate, zero migrazioni:**
- **A1**, perché è l'unica voce che sta **mentendo a un utente in questo momento**. Sette testi dicono
  che il sistema interrompe gli addebiti alla data di uscita. Non è debito tecnico: è un amministratore
  che, mentre leggi questa riga, sta addebitando il venditore credendo il contrario.
- **A4**, e non dopo A1 ma **insieme**: chi legge «il riparto non usa ancora queste date» deve poter
  sapere *subito* se si è fidato per mesi. Un avviso senza lo strumento per misurarne il danno è metà
  lavoro.
- **A3**, mezza giornata, perché rompe un'invariante che il codice stesso chiama «garanzia legale» in
  un documento che va in assemblea, ed è **raggiungibile proprio dal rimedio che gli amministratori
  usano oggi** per il subentro: genero il piano, stacco il vecchio proprietario, ristampo. Mezza
  giornata per non consegnare un prospetto le cui colonne non sommano al totale è un affare.
- **A5**, mezza giornata, i due campi morti (vedi decisioni 8 e 9).

**In 1.10.1:**
- **A2**, dove la ⑩ l'ha già collocato, in compagnia del netting del già-versato che è della stessa
  famiglia. Costa una giornata piena, e il suo innesco — un'unità con nuda proprietà **e** usufrutto e
  un coefficiente sul proprietario — è registrabile solo dalla beta.43, quindi la popolazione colpita è
  piccola e nuova. Se durante la 1.10 emerge un condominio reale in quella configurazione, sale di
  release: è il criterio della roadmap, non una preferenza.

**2. B1 va in 1.10.1.** ✅ **1.10.1, non 1.11.**
La ragione non è il costo, è la **diagnosticabilità del cancello**. B1 e B2 hanno lo stesso cancello di
rilascio — «riparto identico al centesimo» — ma per due motivi opposti: B1 perché *non deve* cambiare
nulla, B2 perché deve cambiare solo dove c'è un subentro. Se escono insieme e una fixture si sposta di
un centesimo, non si sa quale dei due l'ha spostato, e si passano giornate a bisezionare un refactor da
18 punti contro un'aritmetica nuova. Separandoli, B1 esce con un cancello binario (nulla è cambiato) e
B2 può dare il risolutore per corretto.

Secondo motivo, più stretto: `PianoRateController:726` e `IncassoRateService:179` diventano non
deterministici **nel momento in cui B2 rimuove la guardia 1**. Devono essere già deterministici quando
quella guardia cade, altrimenti il difetto nasce insieme alla funzione che lo espone.

Rischio da presidiare: 1.10.1 è già «calendario rate fasi 2-3 + coda importatore». La collisione è bassa
(B1 tocca il risolutore, il calendario tocca le scadenze) ma va verificata prima di partire.

**3. B2 sta tutto in 1.11, e fino ad allora il subentro resta a mano.** ✅ **Sì.**
È la decisione che costa di più da accettare e quella su cui il panel è stato più netto: aprire la
registrazione dei periodi prima dell'aritmetica che li divide produce un 50/50 silenzioso, credibile,
firmato dal sistema e **peggio del difetto attuale**. Non esiste una mezza B2. Quello che si può fare
subito per l'amministratore che aspetta è dirgli la verità (A1) e dargli lo strumento per sapere dove è
già stato colpito (A4).

**4. Anteprima: cancello.** ✅ **Cancello, con spunta e nota.**
Vale 2 giornate e le vale tutte. Il principio di trasparenza del progetto («ogni risoluzione automatica
va mostrata prima e congelata dopo») è già dichiarato vincolante, e un avviso in un elenco non lo
soddisfa: quello che serve sei mesi dopo, in assemblea, è una **giustificazione scritta con una data e
un autore**. Scatta solo quando la risoluzione temporale ha cambiato un destinatario — negli altri casi
non si vede.

**5. Nessuna colonna `criterio_subentro`.** ❌ **No.**
La cascata di D3 risolve entrambi gli scenari senza: lo straordinario è un periodo lungo un giorno,
l'ordinario un periodo vero. Il principio «l'ultima decisione è dell'amministratore» qui è già
rispettato — l'amministratore *decide* dichiarando la competenza, che è la stessa scelta espressa nel
posto giusto. Aggiungere la colonna sarebbe una facoltà che nessuno ha chiesto, su una superficie in più
da mantenere. E resta aggiungibile in seguito senza dolore, perché nascerebbe nullable.

**6. Niente restituzione in B2, solo compensazione.** ❌ **No, resta fuori.**
Il caso è stato sollevato da un giudice, non da un amministratore reale, e il criterio di casa è
esplicito: *una voce senza richiedente non è una voce urgente*. Inoltre la restituzione è un movimento
di cassa, con un profilo di rischio diverso dalla compensazione, che invece riusa il flusso della
beta.46. Va registrata fra le richieste da valutare, con la nota che al primo amministratore che la
chiede entra in B2 per +1,5 giornate.

**7. Si accetta lo spostamento di un centesimo fra unità dello stesso conto, e si dichiara.** ✅
**Si accetta.**
Annidare la ripartizione significa toccare `distribuisciImporto()`, cioè la funzione più pericolosa del
progetto, per un effetto che **non rompe nessuna quadratura**: il totale del conto quadra sempre, e a
spostarsi è a quale unità tocca l'ultimo centesimo. Tre giornate più la riverifica dell'intera suite
penny-perfect, per una proprietà estetica. Due obblighi in cambio: va scritto nel changelog di B2 con
parole comprensibili, e va scritto **un test che fissa il comportamento attuale**, perché ciò che non è
pinnato viene «corretto» da qualcuno fra un anno.

**8. `anagrafica_immobile.tipologie_spese`: si toglie.** ✅ **Togli, in due tempi.**
`NULL` su tutte le 60 righe, nessun validatore, nessun lettore. In **1.10** si tolgono le tre scritture
del controller (zero migrazioni): il campo smette di fingere. In **1.11**, insieme alle altre cinque
migrazioni, si elimina la colonna. Non si tiene «per quando servirà»: quel bisogno — limitare un
soggetto a certe voci — ha già un progetto diverso e migliore in
`evoluzione_anagrafica_e_motore_riparto.md` (`in_bilancio`, `quota_bilancio`).

**9. `piani_rate.data_inizio`: si tolgono le tre righe, non si crea la colonna.** ✅ **Rimozione.**
La colonna non è mai esistita, quindi `PianoRateResource:49` restituisce da sempre `created_at` tramite
il fallback: **crearla cambierebbe ciò che il frontend riceve oggi**, che è l'opposto di una correzione.
Si toglie da `$fillable` e da `$casts`, e nella Resource si legge `created_at` esplicitamente
**mantenendo la chiave `data_inizio` in uscita**, perché il frontend ci si appoggia. Va in 1.10 col
blocco A, zero migrazioni.

**10. La rimozione di `attivo` si fissa alla 2.0 e si scrive in roadmap adesso.** ✅ **Sì.**
Con una motivazione che oggi non c'era: **è B2 a renderlo ridondante**, perché il periodo dice già tutto
ciò che il flag diceva. Fissare la data serve a impedire che nel frattempo qualcuno gli costruisca
sopra un'interfaccia «perché è lì». Fino ad allora resta una condizione AND dentro il risolutore, letta
e mai scritta (D10).

### Riepilogo per versione

| Versione | Cosa entra | Giornate | Migrazioni |
| :--- | :--- | ---: | :--- |
| **1.10** | A1 testi · A3 riallineamento stampa · A4 comando diagnosi · A5 due campi morti | **2,5** | nessuna |
| **1.10.1** | A2 terza copia della cascata *(già collocata dalla ⑩)* · B1 risolutore unico su 18 punti | **5** | nessuna |
| **1.11** | B2 il tempo entra nel calcolo · B3 prospetto oneri accessori · B4 decorrenze dall'import · drop colonna `tipologie_spese` | **17-19** | ⚠️ 6 |
| **2.0** | Rimozione di `attivo` dalla pivot | da stimare | 1 |

**Alla 1.10 si aggiungono 2,5 giornate e nessuna migrazione.** È il punto che rende la decisione
sostenibile: la release non cresce di una funzione, paga un debito e dice la verità su ciò che non fa
ancora.

---

## Appendice A — I due scenari dell'amministratore

**Scenario 1.** Esercizio 01/01-31/12. Spesa straordinaria deliberata il 01/03/26. Subentro nuovo
proprietario il 01/05/26. Fattura del 01/08/26, pagata il 01/09/26. La spesa è giuridicamente tutta del
vecchio proprietario.

*Oggi:* l'esito dipende solo da com'è messa l'anagrafica al momento in cui si genera il piano rate.
Piano generato prima del subentro → il venditore, ed è definitivo (le quote si congelano). Vecchio
cancellato e piano generato dopo → 100 % all'acquirente. Entrambi censiti con quote spezzate → 50/50 su
*ogni* spesa dell'esercizio. La strada che funziona è **emettere il piano rate della straordinaria alla
delibera**, non alla fattura.

**Scenario 2.** Esercizio 01/10-30/09. Gas riscaldamento € 1.000,00 maturati dal 01/10 al 01/05.
Subentro nuovo inquilino il 01/06/26. Il costo è tutto del vecchio inquilino.

*Oggi:* manca la primitiva. I € 1.000,00 entrano nell'esercizio in blocco e vanno per intero a chi
risulta `inquilino` attivo al momento del calcolo.

---

## Appendice B — Le voci scritte in `docs/roadmap.md` il 12/08/2026

Le voci sono già state inserite. Questa appendice registra **dove**, così che una rilettura futura
possa verificare che i due documenti non abbiano divergito:

- **coda ⑰** nella sezione «La coda — senza numeri» → il blocco 1.10 (A1, A3, A4, A5);
- **v1.10.1** → A2 (che la riga ⑩ della tabella dei blocchi aveva già collocato lì) e B1;
- **v1.11**, dentro «Motore Riparto Unificato» → B2, B3, B4;
- **v2.0** → rimozione di `attivo`;
- la riga `:1165` in «Richieste ricevute e NON pianificate» **resta dov'è** con un rimando a questo
  documento: il progetto chiude la parte «valorizzare le decorrenze», **non** la riassegnazione di un
  saldo già scritto (§7).

Testo di riferimento, se una voce dovesse essere ricostruita:

```markdown
### Subentro e competenza temporale *(progettato il 12/08/2026 — vedi `subentro_e_competenza_temporale.md`)*

Richiedente registrato: amministratore sul forum, 12/08/2026, con due scenari verificati sul codice.
Il motore di riparto è atemporale — `anagrafica_immobile.data_inizio`/`data_fine` sono scritte, mostrate
e mai lette — e sette testi dell'interfaccia promettono il contrario.

- **1.10 — 2,5 giornate, zero migrazioni.** Correzione dei sette testi falsi; riallineamento della riga
  stampata quando `$peseTot === 0.0`; comando `km:verifica-titolarita`; due campi morti.
- **1.10.1 — A2 + B1, 5 giornate, zero migrazioni.** Unificazione della terza copia della cascata in
  `RipartoCapitoliService:474-497` (già collocata qui dalla ⑩) e `RisolutoreTitolari` unico su 18 punti
  di chiamata, a comportamento invariato. Cancello: riparto identico al centesimo.
- **1.11 — B2, 12-14 giornate, ⚠️ cinque migrazioni.** Pro-rata temporis, periodi registrabili,
  operazione «Registra subentro», competenza sulla fattura come colonne vere, anteprima con cancello.
  Registrazione e pro-rata **nella stessa release**: aprire la prima senza il secondo produce un 50/50
  silenzioso, peggio del difetto attuale.
- **1.11 — B3, 2,5 giornate.** Prospetto oneri accessori per periodo (il conguaglio fra due conduttori
  successivi, che il condominio documenta e non arbitra).
- **1.11 — B4, 2 giornate.** Decorrenze dal `N gg` di Danea, già riconosciuto e scartato da
  `RipartoConsuntivoParser`.

Dieci decisioni aperte in §8 del documento di progetto.
```
