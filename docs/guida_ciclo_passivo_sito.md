# Capitolo «Ciclo passivo» per il sito ufficiale — progetto

> **Stato al 03/08/2026: PROGETTATO, NON SCRITTO — ma non più bloccato.** Nessuna pagina esiste ancora sul sito.
> La ricognizione nel codice è fatta e verificata. L'attesa era per il divieto muto sull'«Elimina»: **corretto nella beta.34**, quindi la pagina 8 si può scrivere e fotografare.
> Rivisto il 03/08/2026 su 1.10.0-beta.36: corretto il condominio in cui vivono i dati di esempio — vedi la nota in «Dati di esempio pronti».

## Perché si aspettava la beta.34 *(vincolo sciolto il 02/08/2026)*

La beta.34 conteneva la correzione del **divieto muto sull'«Elimina» della fattura passiva** (vedi `roadmap.md`, Traccia A). Quella fix cambia la tabella fatture: la voce passa da «sparisce senza spiegare» a «visibile, disabilitata, con il motivo nel tooltip».

Era esattamente ciò che la pagina 8 deve mostrare. Scriverla e fotografarla prima avrebbe significato consegnare una guida che insegna a **indovinare** quale dei sette divieti si stia applicando, obsoleta il giorno del rilascio.

**Ora è fatta**, quindi lo screenshot della pagina 8 va preso sul comportamento nuovo: voce presente, disabilitata, con il motivo e il rimedio. Nei dati di sviluppo il caso migliore da fotografare è una fattura **aperta** e comunque bloccata (piano rate con rate emesse, o più scritture collegate): è quella che prima mostrava «Elimina» attivo e poi si vedeva rifiutare dal server.

Le pagine 1-7 non dipendono da quella fix: le maschere di registrazione e pagamento la beta.34 non le tocca.

---

## Semantica verificata nel codice — non riderivarla

Questa è la parte costosa da ricostruire. Ogni voce è stata letta nel sorgente il 31/07/2026.

### Spesa fuori preventivo — tre strategie, tre conseguenze

`StoreFatturaRequest:74` → `in:conguaglio_fine_anno,rata_integrativa,fondo_riserva`, più una motivazione di **almeno 10 caratteri** obbligatoria.

| strategia | cosa ne deriva |
| :--- | :--- |
| `conguaglio_fine_anno` | lo sforo resta e si assorbe nel conguaglio finale |
| `rata_integrativa` | vedi sotto: **riscrive il preventivo della voce** |
| `fondo_riserva` | attinge a un fondo esistente, `fondo_patrimoniale_id` obbligatorio |

**`rata_integrativa` non è un'etichetta.** `FatturaPassivaService:226-300`: per ogni voce della fattura che ha *già versato* registrato, alza `conto->importo` al costo reale **subito**. È lo stesso passo che `CalcoloQuoteService::guardiaSovraFinanziamentoGiaVersato()` pretenderebbe comunque prima di generare un piano rate su quella voce. Il commento nel sorgente: *«scegliere rata integrativa per uno sforo reale implica già che questo sia il vero costo dell'opera»*.

Ogni innalzamento è registrato in `dati_extra.rata_integrativa_bump` **con l'importo precedente**, perché lo storno lo ripristini esatto (`StornoFatturaController:188-199`). Ricalcolarlo a fresco dalle righe residue **non** è equivalente: azzererebbe il budget *deliberato*, che può essere più alto del semplice speso.

### Approvazione sforo = ratifica assembleare

`FatturaPassivaController::approvaSforo:876`. Una fattura in sforo nasce `stato_approvazione = 'sforo_motivato'`; l'approvazione la porta a `approvata` e scrive in `dati_extra.ratifica_assembleare` **chi ha ratificato, quando, con quali note**.

È il ciclo dell'art. 1135: l'amministratore anticipa per necessità, l'assemblea ratifica dopo, il gestionale conserva la prova.

### Debito di esercizio precedente — quattro coperture

`is_pregresso` attiva `data_competenza_originaria`, `imponibile_pregresso` e `coperture` (obbligatorie).

`StoreFatturaRequest:100` → `in:rata_0,sopravvenienza,fondo_riserva,saldo_patrimoniale`, ognuna con importo e nota.

**La copertura da fondo, se confermata, crea un giroconto vivo nel giornale.** Da quel momento la fattura non si può né eliminare (`FatturaPassivaController:287`) né stornare (`StornoFatturaController:52`): due guardie distinte. Prima va stornato il giroconto.

### Spesa sul singolo immobile (art. 63) — esce dal preventivo

`FatturaPassivaService:66-104`. Assegnando una riga a un `immobile_id`, **`conto_id` viene forzato a `null`**. Commento nel sorgente: *«forziamo a null per evitare che sporchi il preventivo comune»*.

Conseguenza: la spesa **non entra nel piano dei conti, non entra nel preventivo, non viene ripartita per millesimi**. Si addebita a quel condòmino.

Coerente con la correzione della beta.30 (le spese ad personam non si sommano più alla spesa condominiale): stessa logica, due punti diversi del sistema.

Sotto-caso distinto nel codice: **spesa privata imprevista** (immobile + sopravvenienza) → nessun conto creato; **spesa comune imprevista** → conto dinamico creato.

### Sopravvenienza — l'origine decisionale è tracciabilità legale

`origine_decisionale` ∈ {`assemblea`, `urgenza`} finisce **nella descrizione del conto dinamico**: *«Origine delibera: …»* (`FatturaPassivaService:941-956`). Serve a rispondere mesi dopo alla domanda «perché esiste questa voce che nessuno aveva deliberato».

Il log legale porta anche: `nome_voce`, `data_assemblea`, `is_ordinario`, `richiede_copertura`, `tipo_ripartizione`, `tabella_millesimale_id`, e le percentuali proprietario/inquilino/usufruttuario.

### Pagamento fornitore — molto più di «segna come pagata»

`StorePagamentoFornitoreRequest`. Funzioni non documentate da nessuna parte, né in-app né sul sito:

- **allocazioni**: un pagamento copre **più fatture**, ognuna anche **parzialmente**
- **bonifico parlante** con `tipo_detrazione` e `beneficiari_detrazione[].codice_fiscale`
- commissioni bancarie e ritenuta separate dall'importo
- **quattro guardie con forzatura tracciata**, ognuna con `nota_override`: `allow_overdraft` (scoperto di cassa), `allow_overpayment` (eccedenza), `iban_confermato_manualmente`, `conferma_duplicato_verificato`
- `idempotency_key` contro il doppio invio
- viste **pendenze** e **distinta PDF**

### Eliminare una fattura — sette condizioni

**Aggiornato il 02/08/2026 (beta.34): il difetto è corretto, il capitolo si può scrivere.**

Le sette condizioni non stanno più srotolate nel controller: vivono in `FatturaPassiva::motivoBloccoEliminazione()`, che restituisce il messaggio o `null` se la fattura è eliminabile. Lo stesso metodo è usato da `destroy()` per decidere e dall'elenco (`index()`, via `append('motivo_blocco_eliminazione')`) per spiegare — quindi il menu non può più promettere un'operazione che il server nega.

Nella tabella la voce «Elimina» ora è **sempre presente**: attiva quando si può, altrimenti disabilitata con l'etichetta «Elimina — non consentito» e il motivo nel tooltip. Ogni messaggio contiene la **via d'uscita** (storna il giroconto, riporta il piano in bozza, storna il pagamento, usa lo Storno), che è il punto: la segnalazione del forum descriveva ansia, e l'ansia nasceva dal divieto senza rimedio, non dal divieto.

Per la guida del sito significa che si può scrivere «se non puoi eliminare, l'app ti dice perché e cosa fare» invece di insegnare a indovinare quale dei sette motivi si stia applicando.

L'elenco completo con i numeri di riga d'origine resta in `roadmap.md`, beta.34.

Lo **storno è reversibile**, e non è scritto da nessuna parte: la nota di credito generata è a sua volta «aperta», quindi eliminabile, e la sua eliminazione riporta la fattura allo stato ricalcolato dai pagamenti reali. È coperto da test.

---

## Struttura del capitolo — 8 pagine, un caso concreto ciascuna

Precedente coerente: il capitolo Installazione ne ha sei.

| # | pagina | caso che apre |
| :--- | :--- | :--- |
| 1 | Registrare una fattura passiva | L'idraulico ripara una perdita: 780 € su Manutenzione Idraulica |
| 2 | Spesa fuori preventivo | Ascensore preventivato 3.000 €, fattura 3.450 €: le tre strade e cosa vede l'assemblea |
| 3 | Debito di un esercizio precedente | Subentro a gennaio, a marzo arriva la fattura del giardiniere di ottobre |
| 4 | Spese del singolo condòmino (art. 63) | Serratura dell'interno 7: la spesa sparisce dal preventivo, ed è voluto |
| 5 | Ritenuta d'acconto | Professionista con ritenuta e artigiano senza: escluderla richiede un motivo scritto |
| 6 | Pagare il fornitore | Un bonifico che salda tre fatture, una a metà, più il bonifico parlante |
| 7 | Piccole spese senza fattura | Il fabbro di domenica, 45 € in contanti |
| 8 | Correggere un errore | Hai sbagliato l'importo: elimini o storni? Dipende solo da chi l'ha già visto |

Il caso apre, la spiegazione segue, la conseguenza contabile chiude.

## Vincoli di scrittura

**Non duplicare le guide in-app.** Esistono già `FatturaRegistrazioneGuide.vue` e `RegolazioneImmediataGuide.vue`, e la prima copre pregresso, art. 63, spesa imprevista e capitolo insufficiente. La divisione:

- **guida in-app** → *«sto compilando adesso: cosa metto in questo campo»*
- **guida sul sito** → *«perché il gestionale me lo chiede, cosa succede dopo in contabilità, cosa vedrà l'assemblea»*

**Dati per gli screenshot.** Serve un condominio dedicato — deciso: **crearlo dall'interfaccia con Playwright**, non da seeder o tinker, così passa dalle validazioni vere e non tocca un file del repository. Attenzione: i due checkout condividono lo stesso database MySQL `kondomanager-free`, quindi va concordato con chi sta lavorando prima di popolarlo.

**Wiring** come da `flusso_di_lavoro_rilascio.md`: nuova sezione nella sidebar di *tutte* le pagine `docs/*.html` del sito, voci in `sitemap.xml`, canonical auto-referenziale su ogni pagina nuova.


---

## Dati di esempio pronti — 02/08/2026

Condominio **#29 «Condominio Demo Foto»** (esercizio 42, gestione 36, piano conti 30). Popolato via `FatturaPassivaService::registraFattura()`, cioè dallo stesso codice del modulo — non con insert diretti. Script idempotente in `scratchpad/seed_guida.php`.

> ⚠️ **Correzione del 03/08/2026.** Questa riga diceva «#28 Condominio Demo KM (esercizio 41, gestione 35, piano conti 29)». Quei quattro numeri sono coerenti fra loro e appartengono davvero a Demo KM, ma le fatture `GUIDA-*` **non stanno lì**: sono tutte e sette su Demo Foto, verificato a database. Con ogni probabilità i dati sono stati ricreati sul condominio delle foto dopo la stesura, e la riga non è stata aggiornata. Su Demo KM oggi restano solo le quattro voci di preventivo, ancora ancorate ai conti 141 e 142.

Voci di spesa aggiunte, tutte ancorate a un conto contabile (**senza `conto_contabile_id` la registrazione fallisce**: «Manca l'ancoraggio in Partita Doppia»):

| codice | voce | preventivo | ancoraggio |
| :--- | :--- | ---: | :--- |
| A.1 | Manutenzione idraulica | 1.500 € | 6001 Costi per Servizi |
| A.2 | Manutenzione ascensore | 3.000 € | 6001 |
| A.3 | Cura del giardino | 800 € | 6001 |
| B.1 | Compensi professionali | 1.200 € | 6002 Compensi Professionisti |

Fatture registrate: `GUIDA-01` (ordinaria 780 €), `GUIDA-04` (ad personam 145 €), `GUIDA-05` (professionista 1.000 €), `GUIDA-06A/B` (giardino 320 e 280 €, per il pagamento multiplo).

### Confermato dal vivo

**L'art. 63 funziona come documentato.** `GUIDA-04` ha `conto_id = NULL` e `immobile_id = 200`: la spesa esce dal preventivo comune. Prova indiretta ma decisiva: è l'unica fattura che si è registrata **prima** che le voci avessero l'ancoraggio contabile, proprio perché il suo `conto_id` viene annullato e l'ancoraggio non serve.

### Ritenuta d'acconto — risolto il 02/08/2026

**La ritenuta dipende dal fornitore, non dalla spesa.** È il punto che ribalta l'intuizione e che la pagina 5 deve dire per primo: non è il tipo di voce («compensi professionali») a determinarla, ed è inutile cercarla nella fattura.

`RitenutaService::calcola()`, in quest'ordine:

1. `fornitore->regime_forfetario` → **esclusa**, motivo `FORFETARIO`
2. `!fornitore->soggetto_ritenuta` **oppure** `applica_ritenuta = false` → **non applicata**
3. altrimenti: `tipo_ritenuta` valorizzato → regime nuovo; assente → regime legacy con `aliquota_ritenuta`

Conseguenza operativa: **il flag nel modulo può solo disattivare, mai attivare.** Se un fornitore non è configurato come soggetto a ritenuta, nessuna spunta la applica — va corretta l'anagrafica fornitore. È la risposta alla domanda «ho dimenticato la ritenuta, dove la aggiungo?»: non nella fattura, nel fornitore.

Il default è `applica_ritenuta ?? !isNotaCredito` (`FatturaPassivaService:117`): su una fattura normale la ritenuta si applica **salvo diverso avviso**, sulla nota di credito no.

**I sei regimi** (`config/fiscale.php`, con data di decorrenza):

| tipo | aliquota | base imponibile |
| :--- | ---: | ---: |
| `appalto_4` | 4% | 100% |
| `lavoro_autonomo_20` | 20% | 100% |
| `provvigioni_base_50` | 23% | 50% |
| `provvigioni_base_20` | 23% | 20% |
| `non_residente_30` | 30% | 100% |
| `lavoro_dipendente` | 0% | 100% |

**Escludere richiede un motivo tracciato**, fra: `bonifico_parlante`, `forfetario`, `fuori_campo`, `posa_accessoria`, `override_manuale` (quest'ultimo con note obbligatorie).

**Per riga**, `concorre_base_ritenuta = false` toglie la riga dalla base — contributo cassa professionale, rimborsi art. 15, posa accessoria. Righe senza il flag concorrono, per non alterare le fatture registrate prima.

**Verificato sui dati di esempio** dopo aver configurato correttamente i fornitori:

| fattura | fornitore | esito |
| :--- | :--- | :--- |
| `GUIDA-01` | Mario Rossi Impianti — `appalto_4` | 780 × 4% = **31,20 €** |
| `GUIDA-04` | stesso fornitore, spesa ad personam | 145 × 4% = **5,80 €** |
| `GUIDA-05` | Edil Facciate — `lavoro_autonomo_20` | 1.000 × 20% = **200,00 €** |
| `GUIDA-06A/B` | Ditta pulizia — non soggetta | **nessuna ritenuta** |

Nota sui dati: `Edil Facciate Srl` (#11) è stata configurata il 02/08/2026 come soggetta a ritenuta con regime `lavoro_autonomo_20`, altrimenti il caso 5 non si vedeva.
