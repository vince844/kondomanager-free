# DESIGN DEFINITIVO — Modulo Ritenute d'Acconto e Deleghe F24 (KondoManager v1.10)

<!-- verifica-documentazione -->
> **Stato:** Parzialmente implementato (Fase 1 **e gran parte della Fase 3** in produzione, più la stampa del modulo cartaceo che stava nella Fase 6; Fase 2 e Fasi 4-6 per il resto da fare) — verificato il 31/07/2026 su 1.10.0-beta.32, aggiornato il 03/08/2026 su 1.10.0-beta.38 e il 04/08/2026 su 1.10.0-beta.39
> Il modello dati fiscale, il motore di calcolo e le decisioni normative sono attendibili e già in codice; le sezioni §3 (scritture S1-S9), §4 (sigillo) e §8 (difetti da correggere) descrivono uno stato del repository superato dalla beta.21 e vanno riverificate riga per riga prima di essere usate come specifica.
>
> **Cosa è entrato con la beta.38 (03/08/2026), fuori dall'ordine previsto dal §5.** La Fase 3 è stata costruita **senza** la Fase 2, che il piano dava per prerequisito: è possibile perché `righe_f24` non ha riferimenti in uscita verso `ritenute_operate`, quindi il documento della delega sta in piedi da solo. In produzione ci sono ora `deleghe_f24`, `righe_f24` e una pivot `riga_f24_pagamento` (che tiene il legame delega→pagamenti finché `ritenute_operate` non esiste), `PlafondRitenuteService` e `CalendarioFiscaleService`, le tre azioni genera/conferma/storna, la UI e la guida in-app. **M5 è quindi fatto; M1, M4 e M6 no.**
>
> **Cosa NON è entrato, e va saputo prima di leggere il §3.** Il fatto generatore della ritenuta è ancora la **registrazione della fattura**, non il pagamento: il conto 2202 si accende come prima e la Fase 2 resta interamente da fare. La conseguenza è che il saldo istantaneo del 2202 include ritenute su fatture non ancora pagate. Il modulo F24 costruisce però le deleghe **dai pagamenti**, quindi mese di riferimento e scadenza sono già quelli giusti secondo l'art. 25-ter.
>
> **Difetti §8:** restano aperti solo il **7** (parzialmente: la validazione dell'importo al pagamento è fatta, l'override motivato no) e nulla d'altro — 1, 2, 3, 6, 9 chiusi nella beta.21, 4, 5 e 8 verificati chiusi il 03/08/2026.
>
> **Deroga allo storno S7:** la riclassifica del versato su `1403 crediti_erario_ritenute` **non è implementata** perché quel conto non esiste (è M6, Fase 2). Lo storno del versamento riporta indietro la scrittura e riapre il debito; se l'Erario ha davvero incassato, il recupero va gestito a mano.
<!-- /verifica-documentazione -->

---

## (1) SINTESI IN 5 RIGHE

Il fatto generatore della ritenuta si sposta dalla **registrazione fattura** al **pagamento** (art. 25-ter: "all'atto del pagamento"), con una colonna discriminante che fa convivere il regime legacy senza riscrivere lo storico. Nasce `ritenute_operate`, che è l'**esplosione analitica della riga AVERE 2202** — non un secondo libro — ancorata alla singola riga di scrittura e presidiata da un invariante di riconciliazione verificabile da CLI. `deleghe_f24` + `righe_f24` producono la scrittura `PAGAMENTO_F24` (DARE 2202 / AVERE banca), primo produttore reale di un case enum esistente da mesi senza produttori. Il sigillo per LedgerGuard è `deleghe_f24.stato = 'versata'`, denormalizzato su `pagamenti_fornitori.ritenute_versate_at` come cache testata, con la regola non negoziabile: **rettifica vietata, storno sempre ammesso** con riclassifica del versato su un nuovo conto `1403 crediti_erario_ritenute`. Un comando di backfill obbligatorio allinea il saldo 2202 pregresso, altrimenti il conto non si chiude sui condomìni già installati.

---

## (2) MODELLO DATI DEFINITIVO

### 2.1 Principio guida

Tre livelli, mai confusi:
- **Ledger** (`scritture_contabili` / `righe_scritture`): la verità contabile. Nessun modulo la duplica.
- **Registro fiscale** (`ritenute_operate`): la dimensione fiscale che una riga di giornale non può portare (percipiente, codice tributo, mese/anno di riferimento, scomposizione CU). È **derivato e riconciliabile**, mai autonomo.
- **Documenti** (`deleghe_f24`, `righe_f24`): oggetti conservabili 10 anni con quietanza e protocollo.

Tutto il resto (plafond, scadenze, accumulatori) è **funzione pura**, non tabella.

---

### 2.2 ENUM (namespace `App\Enums\Fiscale`)

Regola di progetto rispettata: enum solo dove l'insieme è piccolo **e guida comportamento**; testo/lookup/config dove descrive.

**`TipoRitenuta: string`** — sei casi, tutti con comportamento distinto:
```php
case APPALTO_4            = 'appalto_4';             // art. 25-ter — 4% — plafond 500 — CU 'W'
case LAVORO_AUTONOMO_20   = 'lavoro_autonomo_20';    // art. 25   — 20% — plafond 100 — CU 'A'
case PROVVIGIONI_BASE_50  = 'provvigioni_base_50';   // art. 25-bis — 23% su 50% = 11,5% — plafond 100
case PROVVIGIONI_BASE_20  = 'provvigioni_base_20';   // 23% su 20% = 4,6% — richiede dichiarazione percipiente
case NON_RESIDENTE_30     = 'non_residente_30';      // 30% a titolo d'IMPOSTA — CU punto 10
case LAVORO_DIPENDENTE    = 'lavoro_dipendente';     // cod. 1001 — plafond MENSILE_SEMPRE (art. 23 escluso dalla soglia 100)
```
Metodi: `aliquota(CarbonInterface $data): float`, `percentualeBase(): float`, `codiceTributo(NaturaPercipiente $n): string`, `plafond(): PlafondRitenuta`, `titolo(): TitoloRitenuta`, `causaleCU(): ?string`, `riferimentoNormativo(CarbonInterface $data): string`.

> **Perché `LAVORO_DIPENDENTE` esiste ma con `MENSILE_SEMPRE`**: il motore paghe è fuori scope, ma se un domani qualcuno lo aggancia deve trovare il plafond già corretto. La facoltà di rinvio dei 100 € riguarda **solo** gli artt. 25 e 25-bis: applicarla all'art. 23 sarebbe un versamento tardivo sanzionabile. Il case esiste proprio per blindare quella regola.

> **Perché `PROVVIGIONI_*` hanno plafond `SOGLIA_100`**: l'art. 9 c. 4 D.Lgs. 1/2024 copre artt. 25 **e 25-bis**. Lasciarle senza plafond (come faceva P3) produce comportamento indefinito.

**`NaturaPercipiente: string`** → `PERSONA_FISICA_IRPEF`, `SOCIETA_PERSONE_IRPEF`, `SOGGETTO_IRES`, `ENTE_NON_COMMERCIALE`.
`TipoRitenuta::codiceTributo()` è **l'unico punto del sistema** in cui 1019/1020/1040/1001 vengono decisi. Il campo `fornitori.codice_tributo` odierno diventa derivato read-only, con override motivato.

**`PlafondRitenuta`** → `SOGLIA_500_TRE_FINESTRE`, `SOGLIA_100_ANNUALE`, `MENSILE_SEMPRE`.
**`TitoloRitenuta`** → `ACCONTO`, `IMPOSTA` (decide punto 9 vs punto 10 in CU).
**`StatoRitenuta`** → `DA_VERSARE`, `IN_DELEGA`, `VERSATA`, `ANNULLATA`.
**`StatoDelegaF24`** → `BOZZA`, `CONFERMATA`, `VERSATA`, `ANNULLATA`.
**`CanaleF24`** → `CARTACEO`, `HOME_BANKING`, `F24_WEB`, `F24_ONLINE`, `INTERMEDIARIO`, con `ammetteCompensazione(): bool` (guida un blocco reale: con righe a credito solo canali telematici dal 1/7/2024).
**`MotivoEsclusioneRitenuta`** → `BONIFICO_PARLANTE`, `FORFETARIO`, `FUORI_CAMPO`, `POSA_ACCESSORIA`, `OVERRIDE_MANUALE`. Guida il messaggio in UI e la presenza/assenza del record.
**`NaturaRigaRitenuta`** → `IMPONIBILE`, `CASSA_PROFESSIONALE`, `RIVALSA_INPS_GS`, `RIMBORSO_ART15`, `RIMBORSO_PIE_LISTA`, `BOLLO_RIADDEBITATO`, `FORNITURA_CON_POSA`. Guida il **default** (non il valore forzato) di `concorre_base_ritenuta`.

**`TipoMovimentoContabile`** → si aggiunge il solo case `STORNO_PAGAMENTO_F24 = 'storno_pagamento_f24'`.

<!-- rettifica -->
> ⚠️ **Non è più vero — verificato il 31/07/2026 su 1.10.0-beta.32.** Il match è già stato esteso: i due prefissi esistono.
> *Prova:* app/Traits/HasProtocolNumber.php:40 ('pagamento_f24' => 'F24') e :41 ('storno_pagamento_f24' => 'STO'); il default => 'SCR' è a :43 ma non li intercetta più.
<!-- /rettifica -->

<!-- rettifica -->
> ⚠️ **Non è più vero — verificato il 31/07/2026 su 1.10.0-beta.32.** Entrambi sono già in produzione dalla beta.21.
> *Prova:* app/Enums/TipoMovimentoContabile.php:22 (docblock «Aggiunto in v1.10.0-beta.21: storno_pagamento_f24»), :58 (case STORNO_PAGAMENTO_F24), :135 (già dentro isEntrataCassa()). PAGAMENTO_F24 è a :55 e :121 dentro isUscitaCassa(), come il design dice.
<!-- /rettifica -->
**Verificato: nessuna DDL necessaria** — la colonna `tipo_movimento` è VARCHAR(50) dalla migration `2026_05_20_062512_upgrade_scritture_contabili` (la `2026_03_13_055103` che elenca il vecchio ENUM è superata). `PAGAMENTO_F24` è già in `isUscitaCassa()`; `STORNO_PAGAMENTO_F24` va aggiunto a `isEntrataCassa()`.

**`HasProtocolNumber::generateProtocolNumber()`** → due case nel match: `'pagamento_f24' => 'F24'`, `'storno_pagamento_f24' => 'STO'`. Oggi `pagamento_f24` cade nel `default => 'SCR'`: è un difetto già presente e va corretto a prescindere.

---

### 2.3 CONFIG — `config/fiscale.php` (nessuna migrazione, versionabile in git)

```php
'codici_tributo' => [
    '1019' => ['descrizione' => '...', 'sezione' => 'erario'],
    '1020' => [...], '1040' => [...], '1001' => [...],
    '1038' => ['soppresso_dal' => '2017-01-01'],       // non selezionabile per nuovi versamenti
    '8947' => ['sanzione' => true, 'auto_precompila' => false],
    '8948' => ['sanzione' => true, 'auto_precompila' => false],
    '8949' => ['sanzione' => true, 'auto_precompila' => false],
],
'aliquote' => [   // storicizzate: [regime][] => ['dal','al','aliquota','base','titolo','norma']
    'appalto_4'          => [['dal' => '2007-01-01', 'aliquota' => 4.0,  'base' => 100]],
    'lavoro_autonomo_20' => [['dal' => '1973-09-29', 'aliquota' => 20.0, 'base' => 100]],
    'provvigioni_base_50'=> [['dal' => '2007-01-01', 'aliquota' => 23.0, 'base' => 50]],
    'provvigioni_base_20'=> [['dal' => '2007-01-01', 'aliquota' => 23.0, 'base' => 20]],
    'non_residente_30'   => [['dal' => '1973-09-29', 'aliquota' => 30.0, 'base' => 100]],
],
'plafond' => ['appalto_4' => 50000, 'lavoro_autonomo_20' => 10000,
              'provvigioni_base_50' => 10000, 'provvigioni_base_20' => 10000,
              'non_residente_30' => 10000, 'lavoro_dipendente' => null],  // null = mensile sempre
'riferimenti_normativi' => [
    'appalto_4' => [['fino_al' => '2026-12-31', 'testo' => 'art. 25-ter DPR 600/1973'],
                    ['dal' => '2027-01-01',     'testo' => 'art. 40 D.Lgs. 33/2025']],
],
'festivita_nazionali' => ['01-01','01-06','04-25','05-01','06-02','08-15','11-01','12-08','12-25','12-26'],
'interessi_legali' => [['dal'=>'2024-01-01','tasso'=>2.5], ['dal'=>'2025-01-01','tasso'=>2.0],
                       ['dal'=>'2026-01-01','tasso'=>1.6]],
'mese_riferimento_cumulo' => 'righe_distinte',   // default raccomandato, vedi §7
```

> **Perché config e non tabelle** (contro P2, che ne creava tre): codici tributo, festività, tassi legali e riferimenti normativi **descrivono**, non guidano. Sono dati globali, non per-condominio, e la loro storia sta già nel version control. Tre tabelle in meno, tre seeder in meno, zero rischio di installazione con lookup non popolata. L'unica cosa che serve è un **fail-fast**: se `aliquote` non ha una riga valida alla data, il calcolo si ferma (vedi guard rail).

---

### 2.4 MIGRAZIONI — l'elenco definitivo (**tutte da segnalare esplicitamente nel changelog**)

> Regola di progetto: ogni modifica al DB va dichiarata. Sono **sei migrazioni**, di cui una itera su tutti i condomìni e una esegue backfill contabile.

---

**【M1】 `add_ritenuta_rilevazione_to_fatture_passive`**
```php
$t->string('ritenuta_rilevata_a', 20)->default('pagamento')->after('importo_ritenuta')
  ->comment('registrazione = regime legacy (2202 acceso alla fattura) | pagamento = nuovo regime');
$t->bigInteger('ritenuta_prevista')->default(0)->after('ritenuta_rilevata_a')
  ->comment('Stima NON contabile per UI e scadenzario. Non genera mai righe di scrittura.');
// backfill: UPDATE fatture_passive SET ritenuta_rilevata_a = 'registrazione';  (tutte le esistenti)
```
**Motivazione**: è l'unico modo onesto di spostare il momento di rilevazione senza riscrivere lo storico contabile. `PagamentoFornitoreService` legge questa colonna per decidere se creare la riga AVERE 2202.

**⚠️ Nota di semantica obbligatoria** (errore rilevato dal panel su P3): `fatture_passive.importo_ritenuta` assume due significati a seconda del regime. Non si deprecha e basta: **ogni consumatore di `importo_ritenuta` deve essere audit-ato in questa PR** e deve leggere `ritenuta_rilevata_a`. Test dedicato che lo verifica.

---

**【M2】 `add_regime_fiscale_to_fornitori`**
```php
$t->string('tipo_ritenuta', 30)->nullable();               // TipoRitenuta
$t->string('natura_percipiente', 30)->nullable();          // NaturaPercipiente → pilota 1019/1020
$t->boolean('residente_fiscale')->default(true);
$t->boolean('regime_forfetario')->default(false);
$t->date('forfetario_dichiarato_il')->nullable();
$t->string('forfetario_riferimento', 255)->nullable();     // estremi documento conservato
$t->boolean('provvigioni_base_ridotta')->default(false);
$t->date('provvigioni_dichiarazione_il')->nullable();
```
Backfill euristico **non distruttivo**, solo dove `soggetto_ritenuta = true`:
`perc_ritenuta = 4` → `APPALTO_4`; `= 20` → `LAVORO_AUTONOMO_20`; `codice_tributo = '1019'` → `PERSONA_FISICA_IRPEF`; `'1020'` → `SOGGETTO_IRES`; altrimenti **NULL**.

**Motivazione**: l'aliquota dipende dalla natura del percipiente, non dal tipo di spesa; il codice tributo dipende da IRPEF vs IRES; il forfetario azzera la ritenuta ma richiede la dichiarazione conservata. `codice_tributo` resta come override motivato, non più come testo libero obbligatorio.

**⚠️ Correzione dell'errore rilevato su P2**: `natura_percipiente = NULL` **non blocca** subito il pagamento. In v1.10 produce un **warning bloccante con override motivato**; il blocco duro entra in v1.11, dopo una release di rodaggio. I dati reali hanno codici tributo misti e incoerenti: un blocco immediato paralizzerebbe il flusso pagamenti il giorno dopo l'aggiornamento.

---

**【M3】 `add_base_ritenuta_to_righe_fatture`**
```php
$t->boolean('concorre_base_ritenuta')->default(true);
$t->string('natura_riga_ritenuta', 30)->nullable();   // NaturaRigaRitenuta — imposta il DEFAULT, editabile
```
**Motivazione**: la base della ritenuta 20% non coincide con l'imponibile IVA (contributo integrativo cassa **escluso anche dal punto 4 CU**, rivalsa INPS GS **inclusa**, rimborsi art. 15 esclusi); per gli appalti va esclusa la fornitura con posa accessoria. Serve un flag **per riga**. Risolve anche il difetto attuale per cui righe ad personam e sopravvenienze entrano indiscriminatamente in base (`FatturaPassivaService.php:55-62`).

---

**【M4】 `create_ritenute_operate_table`** — il registro fiscale
```php
$t->id();
$t->uuid('uuid')->unique();
$t->foreignId('condominio_id')->constrained('condomini')->restrictOnDelete();
$t->foreignId('scrittura_contabile_id')->constrained()->restrictOnDelete();  // ancoraggio al ledger
$t->foreignId('riga_scrittura_id')->nullable()->constrained('righe_scritture')->restrictOnDelete();
$t->foreignId('pagamento_fornitore_id')->constrained('pagamenti_fornitori')->restrictOnDelete();
$t->foreignId('fattura_passiva_id')->nullable()->constrained()->restrictOnDelete();  // NULL: prestazione occasionale
$t->foreignId('fornitore_id')->constrained()->restrictOnDelete();
$t->foreignId('esercizio_id')->constrained()->restrictOnDelete();
$t->foreignId('riga_f24_id')->nullable()->constrained('righe_f24')->nullOnDelete();
$t->foreignId('ritenuta_padre_id')->nullable()->constrained('ritenute_operate');   // riga negativa di storno

$t->string('tipo_ritenuta', 30);
$t->string('natura_percipiente', 30);          // SNAPSHOT
$t->string('titolo', 20);                      // acconto|imposta — SNAPSHOT
$t->char('codice_tributo', 4);

// ── scomposizione CU: campi DISTINTI e non derivabili a posteriori ──
$t->bigInteger('imponibile_lordo');            // CU punto 4 — netto IVA E netto cassa integrativa
$t->bigInteger('cassa_professionale')->default(0);   // fuori dalla base E fuori dal punto 4
$t->bigInteger('rivalsa_inps_gs')->default(0);       // DENTRO la base
$t->bigInteger('somme_non_soggette_conv')->default(0); // CU punto 5 (non residenti, regime convenzionale)
$t->string('codice_somme_non_soggette', 2)->nullable(); // CU punto 6
$t->bigInteger('altre_somme_non_soggette')->default(0); // CU punto 7
$t->bigInteger('base_imponibile');             // CU punto 8 = 4 − 5 − 7
$t->decimal('aliquota', 5, 2);
$t->decimal('perc_base', 5, 2)->default(100);
$t->bigInteger('importo');                     // CU punto 9 (acconto) o 10 (imposta). Negativo = storno
$t->bigInteger('iva')->default(0);             // mai in base — serve al quadro AC
$t->bigInteger('ritenuta_terzi')->default(0);  // 11% operata dalla banca su bonifico parlante (informativa)

$t->date('data_operazione');                   // = data_pagamento — FATTO GENERATORE
$t->unsignedTinyInteger('mese_riferimento');   // congelato da data_operazione
$t->unsignedSmallInteger('anno_riferimento');  // congelato da data_operazione
$t->string('stato', 20);                       // StatoRitenuta
$t->string('motivo_esclusione', 30)->nullable();
$t->json('fornitore_snapshot');                // CF, p.iva, denominazione, regime, natura — immutabile
$t->string('riferimento_normativo', 160);      // congelato alla data (switch 2027)
$t->text('note')->nullable();
$t->timestamps();

$t->unique(['pagamento_fornitore_id','fattura_passiva_id','tipo_ritenuta'], 'ro_pag_fat_tipo_uq');
$t->index(['condominio_id','stato','data_operazione']);
$t->index(['condominio_id','anno_riferimento','mese_riferimento','codice_tributo']); // aggregazione F24
$t->index(['fornitore_id','anno_riferimento']);                                       // CU e quadro AC
```

**Perché esiste, dato che il ledger ha già la riga 2202**: la riga di scrittura porta un solo importo e nessuna dimensione fiscale. F24 aggrega per `(codice_tributo, mese, anno)`; la CU per `(percipiente, anno, causale)` e vuole imponibile lordo, cassa, rivalsa e somme non soggette **separati**. Nessuna di queste dimensioni è ricostruibile dal giornale.

**Perché non è un secondo libro**: `riga_scrittura_id` la ancora alla riga specifica, e vale l'invariante `Σ ritenute_operate.importo per scrittura === importo della riga AVERE 2202`. È l'esplosione di una riga, ed è **dimostrabile** (§6).

**Perché quattro campi CU distinti e non uno solo** (errore rilevato su P3): il contributo integrativo alla cassa professionale non è una "somma non soggetta" del punto 7 — va escluso **anche dal punto 4**. Aggregarlo gonfia il punto 4 e falsa il punto 7. Un campo unico "contributo previdenziale" produce errori sistematici perché la rivalsa INPS GS va nella direzione opposta (concorre).

**⚠️ Ridondanza eliminata** (errore rilevato su P2 e P3): `stato` e `riga_f24_id` non devono essere due sorgenti dello stesso fatto. Regola vincolante: `stato` è **derivato e mantenuto in transazione** con `riga_f24_id`, e un test asserisce l'equivalenza `stato === VERSATA ⟺ riga_f24_id IS NOT NULL AND delega.stato = 'versata'`.

---

**【M5】 `create_deleghe_f24_and_righe_f24_tables`**

`deleghe_f24` — **snellita rispetto a P3**: fuori tutte le colonne anagrafico-fiscali senza consumatore in v1.10 (firmatario, codice carica, intermediario, IBAN addebito, tipo modello). Entrano in v1.11 con il modulo dichiarativo, dove avranno un consumatore. Ripetere l'antipattern del `codice_tributo` odierno — colonna descrittiva senza consumatori — è esattamente ciò che questo design rifiuta.

```php
$t->id(); $t->uuid('uuid')->unique();
$t->foreignId('condominio_id')->constrained()->restrictOnDelete();
$t->foreignId('esercizio_id')->constrained()->restrictOnDelete();       // esercizio della data_versamento
$t->foreignId('scrittura_contabile_id')->nullable()->unique()->constrained()->restrictOnDelete();
$t->foreignId('delega_padre_id')->nullable()->constrained('deleghe_f24'); // storni / split
$t->string('stato', 20)->default('bozza');
$t->string('plafond', 30);                      // quale accumulatore ha generato la delega
$t->string('canale', 20)->nullable();
$t->date('data_scadenza');                      // calcolata, editabile
$t->date('data_versamento')->nullable();
$t->foreignId('conto_corrente_id')->nullable()->constrained('conti_contabili');
$t->foreignId('cassa_id')->nullable()->constrained('casse');
$t->string('cf_contribuente', 16);              // SNAPSHOT: CF del CONDOMINIO (guard rail)
$t->string('denominazione_contribuente', 255);
$t->bigInteger('totale_debito')->default(0);
$t->bigInteger('totale_credito')->default(0);
$t->bigInteger('saldo')->default(0);            // >= 0 sempre
$t->char('nota_st', 1)->nullable();             // 'T'|'U' — CONGELATA alla conferma
$t->string('protocollo_telematico', 40)->nullable();
$t->date('data_quietanza')->nullable();
$t->string('quietanza_path', 500)->nullable();
$t->string('quietanza_hash', 64)->nullable();
$t->text('motivo_annullamento')->nullable();
$t->uuid('idempotency_key')->nullable()->unique();
$t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
$t->timestamps();                                // ← NESSUN softDeletes
$t->index(['condominio_id','stato','data_scadenza']);
```

> **Niente `softDeletes`** (errore rilevato su P3): la spec vieta il soft-delete per gli storni e il giornale è append-only. Su `ScritturaContabile` il trait è retrocompatibilità pre-v1.9.1, non un pattern da estendere. Una delega F24 va conservata 10 anni: l'annullamento è uno **stato**.

`righe_f24` — **tabella, non JSON** (mi discosto qui da P1):
```php
$t->id();
$t->foreignId('delega_f24_id')->constrained('deleghe_f24')->cascadeOnDelete();
$t->unsignedTinyInteger('ordine');              // 1..6
$t->string('sezione', 20)->default('erario');
$t->char('codice_tributo', 4);
$t->string('numero_certificazione', 16)->nullable();
$t->char('rateazione_mese_rif', 4);             // STRINGA: '0006'. Mai un intero.
$t->char('anno_riferimento', 4);
$t->bigInteger('importo_debito')->default(0);
$t->bigInteger('importo_credito')->default(0);
$t->bigInteger('interessi_ravvedimento')->default(0);  // cumulati in F24, esposti a parte nel 770 ST p.8
$t->timestamps();
$t->unique(['delega_f24_id','ordine']);
// CHECK ((importo_debito > 0 AND importo_credito = 0) OR (importo_credito > 0 AND importo_debito = 0))
```
**Perché tabella e non snapshot JSON**: serve una FK vera da `ritenute_operate.riga_f24_id` (è il legame che rende il sigillo interrogabile senza spacchettare JSON), serve il CHECK XOR a livello di storage, e il quadro ST del 770 aggrega per riga. Il criterio di P1 ("promuovi a tabella quando servirà il join") è corretto ma il join serve **subito**.

`rateazione_mese_rif` come `CHAR(4)`: il tracciato vuole 4 caratteri eterogenei ('00'+MM, '0101', sigle provincia). Modellarlo come intero costringe a ri-serializzare male in export.

---

**【M6】 `add_seal_and_system_accounts`** — *migration che itera su tutti i condomìni*
```php
// a) cache del sigillo
Schema::table('pagamenti_fornitori', fn($t) =>
    $t->timestamp('ritenute_versate_at')->nullable()->after('importo_ritenuta')
      ->comment('CACHE derivabile da deleghe_f24.stato. Verità = deleghe_f24. Derivabilità testata.'));
Schema::table('pagamenti_fornitori', fn($t) =>
    $t->index(['condominio_id','ritenute_versate_at']));

// b) due conti di sistema, stesso pattern di 2026_03_27_161304
//    set_time_limit(0) + Condominio::lazy() + firstOrCreate su (condominio_id, ruolo)
//    1403  crediti_erario_ritenute   ATTIVO/CREDITI  'Crediti v/Erario per Ritenute'
//    6004  oneri_tributari           COSTO/COSTI     'Sanzioni e Interessi Tributari'
```
**`1403`**: accoglie la riclassifica della ritenuta versata su un pagamento poi stornato, evitando che 2202 (categoria "debiti") vada in saldo negativo. È il conto che rende possibile la scrittura correttiva, e senza il quale il sigillo diventa un vicolo cieco.
**`6004`**: sanzioni e interessi da ravvedimento **non transitano mai da 2202**, altrimenti il conto Erario porta un residuo strutturale che nessuna riconciliazione può spiegare.
`CondominioService::ensureDefaultConti()` va aggiornato in parallelo per i condomìni futuri.

⚠️ Questa migration gira dentro `SystemUpgradeController` via richiesta HTTP: `set_time_limit(0)` e `cleanupPartialMigration` obbligatori, come nella `2026_03_27_161304`.

---

**【CMD】 `kondo:backfill-ritenute-storiche`** — *non è una migration, è un comando idempotente con `--dry-run` di default*

**Obbligatorio.** Senza, il saldo 2202 pregresso resta orfano e inestinguibile per sempre (è il buco più grave che il panel ha rilevato in P3: le fatture legacy non generano `ritenute_operate`, quindi non possono entrare in nessuna delega).

| Caso | Azione |
|---|---|
| Fattura con ritenuta **non pagata** | Scrittura di rettifica `DARE 2202 / AVERE 2201` per l'intera ritenuta. Il debito torna al fornitore, dove deve stare finché non si paga. |
| Fattura **parzialmente pagata** | Rettifica `DARE 2202 / AVERE 2201` per la sola **quota non maturata**, + `ritenute_operate` retroattive pro-quota sui pagamenti eseguiti. *(correzione dell'errore rilevato su P2, che trattava le parziali come pagate)* |
| Fattura **integralmente pagata** | Nessuna rettifica contabile; `ritenute_operate` retroattive con `data_operazione = pagamenti_fornitori.data_pagamento`, `riga_f24_id = NULL` → entrano nella prima delega utile. |
| Fattura **stornata** con ritenuta | Rettifica `DARE 2202 / AVERE 2201` (chiude sia il residuo su 2202 sia il DARE fantasma su 2201 generato da `StornoFatturaController.php:85`). |
| Task Inbox F24 orfani | Chiusi, non cancellati. |

Output: report di riconciliazione saldo 2202 prima/dopo per condominio, con evidenza delle **ritenute arretrate scadute** — che vanno accompagnate dal calcolo del ravvedimento (v1.11) o da una nota che rimanda al consulente.

> **Migrazioni da dichiarare nel changelog v1.10**: M1 (fatture_passive), M2 (fornitori + backfill euristico), M3 (righe_fatture), M4 (ritenute_operate), M5 (deleghe_f24 + righe_f24), M6 (pagamenti_fornitori + due conti di sistema con iterazione su tutti i condomìni). Più il comando `kondo:backfill-ritenute-storiche`, che **emette scritture contabili di rettifica**. Nota di backup consigliato prima dell'aggiornamento.

---

## (3) SCRITTURE DI PARTITA DOPPIA — IL CICLO COMPLETO

### S1 — Registrazione fattura passiva (nuovo regime)
`tipo_movimento: FATTURA_ACQUISTO` · protocollo `FTP`
```
DARE   6001/6002/capitolo di costo ......... imponibile per riga
DARE   1401 iva_acquisti ................... IVA (se detraibile/scorporata)
AVERE  2201 debiti_fornitori ............... TOTALE DOCUMENTO (LORDO)
── nessuna riga su 2202 ──
```
`ritenuta_prevista` viene calcolata e salvata: è un dato di UI e di scadenzario, non contabile.

**Perché il lordo su 2201**: il condominio è debitore dell'intero corrispettivo; la ritenuta è una *modalità di estinzione* (paga 96 al fornitore e 4 allo Stato **per suo conto**), non una riduzione del debito. Nel regime attuale il partitario fornitore mostra 96 mentre la fattura dice 100 e non riconcilia con l'estratto conto fornitore.

> ### ⚠️ CONSEGUENZA OBBLIGATORIA E NON NEGOZIABILE — la semantica di `netto_a_pagare`
> È l'errore più grave che il panel ha rilevato, e nessuna proposta l'aveva scritto per intero.
> `FatturaPassiva::residuo()` (`:174-179`), `validaOverpayment` / `OverpaymentException` (`PagamentoFornitoreService:863-880`), `ricalcolaStatoFattura` (`:852-889`), `TreasuryGuardianService:53`, `SyncScadenziarioWithFattura:50-59` e il contatore `importo_da_pagare` (`FatturaPassivaController:84`) sono **tutti** ancorati a `netto_a_pagare`.

<!-- rettifica -->
> ⚠️ **Non è più vero — verificato il 31/07/2026 su 1.10.0-beta.32.** L'elenco dei consumatori è sostanzialmente corretto e ancora attuale (il refactoring non è stato fatto), ma metà dei riferimenti di riga non regge più e uno omette il path reale. Da riverificare prima di aprire la Fase 2, non da usare come checklist alla lettera.
> *Prova:* CORRETTI: app/Models/Gestionale/FatturaPassiva.php:174-180 (getResiduoAttribute), app/Listeners/Gestionale/SyncScadenziarioWithFattura.php:50 e :59. DERIVATI: TreasuryGuardianService sta in app/Services/Treasury/ (non Gestionale/) e usa netto_a_pagare a :57, non :53; il contatore importo_da_pagare è a app/Http/Controllers/Gestionale/Movimenti/FatturaPassivaController.php:108, non :84; OverpaymentException è lanciata a app/Services/Gestionale/PagamentoFornitoreService.php:489, non in :863-880. In app/ ci sono 23 occorrenze di netto_a_pagare.
<!-- /rettifica -->
> Se 2201 è accreditato del lordo (1220) ma le allocazioni restano sul netto (1180), la scrittura di pagamento **sbilancia di 40** e `DoubleEntryValidator::validateOrFail` fallisce. Se si alloca il lordo, scatta la guard di overpayment su ogni fattura con ritenuta.
> **Nel nuovo regime `netto_a_pagare` diventa "lordo a pagare"** e vanno riscritti in blocco: `residuo()`, la guard di overpayment, `ricalcolaStatoFattura`, il netting note di credito, il TreasuryGuardian e lo scadenziario. Questo è il vero costo della fase breaking e va messo a preventivo esplicitamente.

### S2 — Pagamento fornitore (nuovo regime)
`tipo_movimento: PAGAMENTO_FORNITORE` · protocollo `PAG`
```
DARE   2201 debiti_fornitori ............... totaleSuFatture (quota LORDA allocata)
DARE   6003 spese_bancarie ................. commissioni (se > 0)
AVERE  2202 debiti_erario_ritenute ......... ritenuta operata          ← RIGA NUOVA
       note: "Ritenuta {tipo->label()} {aliquota}% — {fornitore} — cod. {codiceTributo}"
AVERE  conto corrente (cassa_id) ........... totalePagamento − ritenuta + commissioni
AVERE  2201 debiti_fornitori ............... netting note di credito (invariato)
```
Quadratura: `totaleSuFatture + commissioni = ritenuta + (totalePagamento − ritenuta + commissioni) + totaleSuNC`, con `totaleSuFatture = totalePagamento + totaleSuNC` → Δ = 0. ✓

La nota di riga riporta l'**aliquota reale**, non la stringa hardcoded `"Ritenuta d'acconto 4% fattura fornitore"` di `FatturaPassivaService:387`.

Contestualmente il listener crea 1..N record `ritenute_operate` (uno per fattura allocata × regime), con `riga_scrittura_id` = id della riga AVERE 2202.

**Calcolo** (`RitenutaService::calcola`), pro-quota su pagamento parziale con **denominatore LORDO** e **residuo sull'ultima allocazione** (per non lasciare centesimi orfani su 2202 — deriva rilevata dal panel su P1).

### S3 — Bonifico parlante (detrazione edilizia)
```
DARE   2201 debiti_fornitori ............... lordo
AVERE  conto corrente ...................... lordo
```
Nessuna riga 2202. Record `ritenute_operate` con `importo = 0`, `motivo_esclusione = BONIFICO_PARLANTE`, `ritenuta_terzi` valorizzato a fini informativi e di quadro AC.
**Oggi questo caso è rotto**: 2202 è già stato acceso alla registrazione fattura e nessun flusso lo ripulisce.

### S4 — Fornitore forfetario
Come S3: nessuna riga 2202, record con `importo = 0` e `motivo_esclusione = FORFETARIO`. Il record serve comunque per il quadro AC (soglia 258,23 €) e per gli anni ante-2024.

### S5 — Versamento F24
`tipo_movimento: PAGAMENTO_F24` · protocollo `F24-2026-00001` · esercizio = quello di `data_versamento`
```
DARE   2202 debiti_erario_ritenute ......... Σ importi ritenute incluse
AVERE  conto corrente (cassa_id) ........... delega.saldo
```
Con ravvedimento (v1.11):
```
DARE   2202 debiti_erario_ritenute ......... ritenuta originaria
DARE   6004 oneri_tributari ................ interessi + sanzione ridotta
AVERE  conto corrente (cassa_id) ........... totale versato
```
Gli interessi sono cumulati nella riga F24 1019/1020 ma restano in `righe_f24.interessi_ravvedimento`, perché il quadro ST li vuole esposti al punto 8. La sanzione è riga F24 separata e **mai precompilata** (§7).

### S6 — Storno pagamento PRIMA del versamento
Mirror puro invariato (`PagamentoFornitoreService:750-757`):
```
DARE   conto corrente ...................... (era AVERE)
DARE   2202 debiti_erario_ritenute ......... (era AVERE) — il debito Erario si estingue da solo
AVERE  2201 debiti_fornitori ............... (era DARE)
```
+ riga negativa in `ritenute_operate` (`ritenuta_padre_id` valorizzato, `importo = −X`), originale → `ANNULLATA`. Se la ritenuta è `IN_DELEGA` (bozza): rimossa dalla delega e totali ricalcolati — la bozza non è un fatto giuridico.

### S7 — Storno pagamento DOPO il versamento ★ IL CASO DURO ★
**Lo storno non viene mai vietato.** Il mirror produce un DARE su 2202 che porterebbe il conto in negativo; una **seconda scrittura** riclassifica:
```
tipo_movimento: GIROCONTO · scrittura_padre_id = scrittura di storno
DARE   1403 crediti_erario_ritenute ........ importo versato in eccesso
AVERE  2202 debiti_erario_ritenute ......... stesso importo
causale: "Riclassifica ritenuta già versata con F24 del {data} prot. {protocollo}"
```
La scrittura F24 **resta intatta**: l'F24 è stato realmente presentato e non va mai riscritto.
Richiesta conferma esplicita `conferma_ritenuta_gia_versata` + motivo, loggata come override strutturato (pattern `nota_override`). Il credito su 1403 non viene compensato automaticamente: visto di conformità sopra 5.000 € e canale telematico obbligatorio sono fuori scope.

> ⚠️ **Nota sull'overload semantico di `scrittura_padre_id`** (rilevato dal panel): oggi il campo lega le scritture inverse di storno. Qui lega anche un giroconto derivato. Va aggiunto un discriminante — la relazione si legge sempre insieme a `tipo_movimento` — e va documentato nel docblock del modello, altrimenti chi domani interroga "gli storni" trova anche le riclassifiche.

### S8 — Storno del versamento F24
`tipo_movimento: STORNO_PAGAMENTO_F24` · protocollo `STO` · `scrittura_padre_id` = scrittura F24
```
AVERE  2202 debiti_erario_ritenute ......... importo originario
DARE   conto corrente (cassa_id) ........... importo originario
```
+ ritenute tornano `DA_VERSARE`, `riga_f24_id = NULL`, delega → `ANNULLATA` (mai deleted_at), `ritenute_versate_at = NULL` sui pagamenti.
**Vietato se `data_quietanza IS NOT NULL`**: con la quietanza acquisita la correzione passa da ravvedimento o compensazione, non da un'inversione contabile.

### S9 — Cavallo d'esercizio: caso ORDINARIO, non eccezione
Le ritenute di dicembre si versano entro il 16 gennaio: ogni anno il ciclo attraversa l'esercizio. Il design lo assorbe senza casi speciali perché (a) la scrittura F24 vive nell'esercizio di `data_versamento`; (b) `mese_riferimento` e `anno_riferimento` derivano da `ritenute_operate.data_operazione`, **mai** dalla delega, quindi una delega può legittimamente contenere righe di due anni; (c) il saldo 2202 al 31/12 rappresenta correttamente il debito residuo.
*Verificato: nel repo non esiste un motore di riporto dei saldi patrimoniali e i saldi si calcolano cumulativi senza filtro esercizio — il design non dipende da quel motore.*

**ANTI-guard rail vincolante**: la chiusura d'esercizio **non deve essere bloccata** da ritenute non versate. Solo un warning in checklist, e solo per scadenze **già superate**.

---

## (4) IL SIGILLO PER LEDGERGUARD

`LedgerGuard` non esiste nel branch (grep a vuoto su `app/` e `docs/`). Quindi questo modulo **fornisce un dato, non progetta un'interfaccia altrui**, e blinda l'invariante localmente in modo che regga anche se il refactor slitta di due release.

### Tre livelli
1. **Verità**: `deleghe_f24.stato = 'versata'` + `data_versamento` + `protocollo_telematico` + `quietanza_path`. Persistente, non soggetto a GC, legato a un documento con obbligo di conservazione decennale. Non è un task: è il fatto.
2. **Legame**: `ritenute_operate.riga_f24_id → righe_f24 → deleghe_f24`.
3. **Cache**: `pagamenti_fornitori.ritenute_versate_at`, indicizzata, valorizzata da `ConfermaVersamentoF24Action` e azzerata dallo storno. È dichiaratamente cache, e un test asserisce che coincide **sempre** con la query derivata.

### API proposta (da conciliare quando LedgerGuard atterra)
```php
// nuovo motivo accanto a ESERCIZIO_CHIUSO / RATE_EMESSE / RISCONTRO_BANCARIO / ANZIANITA_30GG
MotivoSigillo::RITENUTA_VERSATA
// payload azionabile, non un booleano muto:
// { delega_f24_id, data_versamento, protocollo_telematico, importo, codice_tributo, mese_rif, anno_rif }
```
Messaggio all'utente: *"Non rettificabile: la ritenuta di € 240,00 è stata versata con F24 del 16/06/2026, prot. 12345678901234567_000001."*

### ★ La regola che nessuno deve sbagliare: rettifica ≠ storno
- **Rettifica** (modifica in place della scrittura) → **VIETATA**. Riscrivere il passato renderebbe l'F24 presentato incoerente con il libro giornale.
- **Storno** (aggiunta di un fatto nuovo) → **SEMPRE AMMESSO**, con conferma esplicita e riclassifica S7.

Un guard che tratta i due casi allo stesso modo lascia il condominio con un pagamento errato **incorreggibile per sempre**. È il difetto che ha fatto scartare l'enforcement di P1 e il `LedgerSealedException` di P2.

### Campi immutabili sotto sigillo
Su pagamento con `ritenute_versate_at IS NOT NULL`: immutabili `importo_ritenuta`, `importo_lordo`, `importo_netto` e **`data_pagamento`** — quest'ultima determina mese e anno di riferimento di una riga F24 già presentata. Restano modificabili `riferimento_bancario`, `causale_bonifico`, `note_override`: nessuna rilevanza fiscale.

### Sigillo sulla scrittura F24 stessa
Sigillata quando `stato = 'versata'` **e** `data_quietanza IS NOT NULL`. In stato `confermata` senza quietanza resta modificabile: l'F24 può essere stato rifiutato dalla banca.

### Interazione con gli altri sigilli
Sono in OR e non si annullano. Il caso interessante è l'inverso: un pagamento di 5 giorni fa la cui ritenuta è già stata versata (soglia 500 scattata) è sigillato **prima** dei 30 giorni. È corretto — il perimetro dell'amministratore è stato varcato verso l'Agenzia delle Entrate.

### Migrazione del surrogato Inbox
Il task `meta.type = 'versamento_ritenuta'` smette di essere per-pagamento e diventa **per (condominio, scadenza, plafond)**: *"F24 ritenute art. 25-ter — scadenza 16/06/2026 — residuo € 1.240,00"*. Si chiude da `ConfermaVersamentoF24Action`, si riapre allo storno. I task esistenti vengono **chiusi** da una migration di dati, non cancellati.

---

## (5) PIANO DI RILASCIO

### **FASE 1 — Anagrafica e calcolo** *(v1.10, ~1 settimana, autonoma, zero impatto contabile)*
Enum, `config/fiscale.php`, M2 + M3, `RitenutaService` con esclusioni (bonifico parlante, forfetario, fuori campo, posa accessoria) e test puri, UI anagrafica fornitore e riga fattura.
**Rilasciabile da sola**: migliora subito la qualità del dato senza toccare il ledger.

### **FASE 2 — Spostamento della rilevazione** *(v1.10, ~2 settimane) ★ FASE CRITICA ★*
M1, M4, M6, la riscrittura di `netto_a_pagare` come base di allocazione con tutti i consumatori, nuove scritture S1/S2/S3/S4, `RiconciliazioneRitenuteService` + comando artisan, `kondo:backfill-ritenute-storiche`.
Da fare con la suite del ciclo passivo verde a ogni commit. Qui vive tutto il rischio.

### **FASE 3 — Modulo F24 e sigillo** *(v1.10, ~1,5 settimane)*
M5, `PlafondRitenuteService` + `CalendarioFiscaleService`, `GeneraDelegaF24Action` (con **split automatico** oltre 6 righe), `ConfermaVersamentoF24Action`, `StornaVersamentoF24Action`, `RiclassificaRitenutaVersataAction`, fix di `SyncF24WithPagamento` (iscrizione a `PagamentoStornato` + task per scadenza), guard rail, UI: scadenzario, registro ritenute, wizard F24, widget cruscotto con semaforo di riconciliazione.

**→ Fine v1.10: il conto 2202 si apre e si chiude, il sigillo esiste, l'invariante è sorvegliato.**

### **FASE 4 — Ravvedimento** *(v1.11)*
Interessi legali pro-rata a cavallo d'anno, sanzione su 6004, wizard con formula esplicitata, codice sanzione mai precompilato.

### **FASE 5 — CU e 770** *(v1.11/v1.12)*
`certificazioni_uniche` + pivot, `adempimenti_dichiarativi`, `amministratori_incarichi`, colonne anagrafico-fiscali su `deleghe_f24` (firmatario, intermediario, IBAN), `QuadroSTBuilder`, `QuadroACBuilder`, stampe e tracciamento consegna.
**I dati che le alimentano sono già congelati dalla Fase 2**: la scomposizione CU è persistita fin dal primo pagamento, quindi rimandare il modulo non pregiudica nulla.

### **FASE 6 — Telematico** *(oltre)*
Tracciato F24 record A/M/V, compensazione crediti 1627/1628, ~~stampa modello cartaceo conforme~~.

> ⚠️ **La stampa del modello cartaceo è uscita con la beta.39 (04/08/2026), fuori da questa fase.** Stava qui insieme al tracciato telematico e alla compensazione, e per associazione ha ereditato il loro *«oltre»*: dalla Fase 5 sembrava dipendere anche lei dalle colonne anagrafico-fiscali (firmatario, intermediario, IBAN), e sulla scorta di questo la beta.38 aveva dichiarato in cinque punti — changelog, guida in-app, `DelegaF24`, `prospetto.ts`, sito — che il modulo cartaceo non era ancora producibile.
>
> Verificato sul modulo pubblicato dall'Agenzia (MOD. F24 – 2013 – EURO), casella per casella: **quei campi sul foglio non esistono**. Sono del tracciato telematico e dei dichiarativi. L'unico IBAN dell'F24 Ordinario è la casella facoltativa «Autorizzo addebito su conto corrente», che compila il contribuente, e la sezione «Estremi del versamento» porta stampato sopra che spetta a banca/poste/agente della riscossione. Quel che il foglio chiede era già tutto persistito: `cf_contribuente` e `denominazione_contribuente` sulla delega, la sezione Erario in `righe_f24`, il domicilio fiscale in `condomini`. Zero migrazioni. Vedi `ModelloF24Service`.
>
> **La lezione, che vale oltre l'F24:** raggruppare in una fase cose che si somigliano *nell'esito* — «tutti i modi di produrre un F24 per l'esterno» — e non nei *prerequisiti*, fa ereditare a quella più economica il costo della più cara. Le due voci rimaste qui dipendono davvero dalla Fase 2 e dalle colonne della Fase 5; la stampa non dipendeva da nulla.

---

## (6) CASI DI TEST PEST ESSENZIALI

Convenzione: Pest, `uses(RefreshDatabase::class)`, `require_once __DIR__ . '/GestionaleTestHelpers.php'`.

**`RitenutaCalcoloTest.php`**
- 4% su imponibile netto IVA, fornitore appalto IRPEF → cod. 1019; stesso fornitore IRES → 1020
- professionista → 20% e cod. 1040, mai 4%
- **★ pagamento di 50,00 € → ritenuta 2,00 €** — anti-regressione contro le soglie divulgative 200/500
- forfetario → 0 + `motivo_esclusione`, nessuna riga 2202; forfetario senza `forfetario_dichiarato_il` → warning bloccante
- bonifico parlante → 0, `ritenuta_terzi` valorizzato, nessuna riga 2202
- cassa professionale esclusa dalla base **e dal punto 4**; rivalsa INPS GS **inclusa**; IVA mai in base
- riga con `concorre_base_ritenuta = false` esclusa; provvigioni base 50% e 20%; non residente 30% a titolo d'imposta → valorizza punto 10, non punto 9
- aliquota mancante in config alla data → eccezione, mai default silenzioso

**`RitenutaLedgerTest.php`** ★
- fattura 1.000 + IVA 220, ritenuta 40 → AVERE 2201 = **1.220**, nessuna riga 2202
- pagamento integrale → DARE 2201 1.220 / AVERE 2202 40 / AVERE banca 1.180, quadra
- pagamento parziale al 50% → ritenuta 20, pro-quota sul **lordo**
- **tre pagamenti parziali 393/393/394 → Σ ritenute === 40 esatti**, residuo sull'ultima allocazione, nessun centesimo orfano
- fattura legacy (`ritenuta_rilevata_a = 'registrazione'`) → il pagamento NON crea la riga 2202
- nota di credito per fornitore soggetto a ritenuta → **nessuna riga 2202 spuria** (bug attuale del filtro invertitore)
- allocazione: `residuo()` e la guard di overpayment ragionano sul lordo, nessuna `OverpaymentException` spuria

**`RiconciliazioneRitenuteTest.php`** ★ L'INVARIANTE ★
- dopo ogni scenario (fattura → pagamento → delega → versamento → storno → riclassifica) `saldoLedger(2202) === residuoFiscale`
- **una riga 2202 inserita a mano senza `ritenute_operate` fa FALLIRE la verifica**
- cavallo d'anno: ritenute dic-2026 versate il 16/01/2027 → al 31/12 saldo = residuo, al 31/01 entrambi a zero
- le fatture legacy sono escluse e contate come "residuo storico non riconciliabile"
- `ritenute_operate.stato === VERSATA ⟺ riga_f24_id valorizzato e delega versata` (nessuna doppia verità)

**`PlafondRitenuteTest.php`**
- cumulo 480 € a maggio → 16/06, nota `U`; cumulo 520 € a maggio → 16/06, nota `T`
- ritenute di dicembre → 16/01 a prescindere dall'importo; la finestra [gen-mag] si azzera al versamento
- **la soglia 500 è del CONDOMINIO**: 3 fornitori da 200 € → soglia raggiunta; **1019 + 1020 cumulano**
- le ritenute art. 25 non concorrono al plafond 500 → accumulatore separato a 100 €, data-limite unica 16/12
- **provvigioni art. 25-bis → plafond 100, non "nessun plafond"**
- lavoro dipendente → mensile sempre, mai soglia 100
- `plafond_mode = 'mensile'` → sempre il 16, nessun avviso di anomalia
- 16/06/2024 domenica → 17/06; **16/08 Ferragosto → primo giorno lavorativo**; Pasquetta
- nota ST **congelata**: cambiare i dati dopo la conferma non la altera

**`GeneraF24Test.php`**
- 3 ritenute stesso tributo/mese → 1 riga; 1019 e 1020 stesso mese → 2 righe
- **7 combinazioni → split automatico in 2 deleghe**, righe integre, mai spezzate
- `rateazione_mese_rif === '0006'` (4 caratteri, stringa); `anno_riferimento` da `data_operazione`
- saldo < 0 → eccezione; debito e credito sulla stessa riga → eccezione (+ test del CHECK DB)
- riga a credito + canale CARTACEO → eccezione
- `cf_contribuente` = CF amministratore → rifiuto

**`F24VersamentoLedgerTest.php`**
- scrittura `PAGAMENTO_F24`, protocollo `F24-2026-00001` (non `SCR`)
- DARE 2202 = AVERE banca = saldo delega, `DoubleEntryValidator` passa
- **★ il saldo 2202 torna a ZERO** dopo fattura → pagamento → F24
- idempotency key: doppia POST → una sola delega
- versamento su esercizio chiuso → `FiscalYearClosedException`

**`RitenutaStornoTest.php`** ★ I CASI DURI ★
- storno prima del versamento → DARE 2202 dal mirror, saldo a zero, riga negativa, task Inbox **chiuso**
- storno di ritenuta IN_DELEGA bozza → rimossa, totali ricalcolati
- storno dopo il versamento senza conferma → eccezione; **con conferma → 3 scritture** (pagamento, storno, giroconto), 2202 = 0, 1403 = importo, **la scrittura F24 è INTATTA** (assert riga per riga)
- storno del versamento F24 → ritenute tornano DA_VERSARE, `ritenute_versate_at = NULL`
- delega quietanzata → storno vietato

**`RitenutaSigilloTest.php`**
- delega in bozza → nessun sigillo; delega versata → sigillo con payload
- `ritenute_versate_at` coincide sempre con la query derivata
- **sigillo attivo → rettifica vietata MA storno ammesso**
- `data_pagamento` immutabile sotto sigillo; `riferimento_bancario` modificabile
- **il sigillo sopravvive alla cancellazione dell'Evento Inbox**
- pagamento di 5 giorni fa con ritenuta versata → sigillato prima dei 30 giorni

**`BackfillRitenuteStoricheTest.php`**
- non pagata → rettifica DARE 2202 / AVERE 2201; **parziale → rettifica solo sulla quota non maturata**
- pagata → nessuna rettifica, `ritenute_operate` retroattive
- fattura stornata → chiude sia il residuo 2202 sia il DARE fantasma su 2201
- idempotente: seconda esecuzione, zero nuove scritture

**Da riscrivere**: `FatturaLifecycleTest.php:246-252` (oggi asserisce che 2202 resta 4000 come comportamento *atteso*), `SyncF24WithPagamentoTest.php` (task da per-pagamento a per-scadenza), `StornoPagamentoControllerTest.php` (casi con ritenuta), `EnumsTest.php:23`.

---

## (7) DECISIONI CHE RESTANO ALL'UTENTE

| # | Decisione | **Raccomandazione e perché** |
|---|---|---|
| 1 | **Mese di riferimento nel versamento cumulativo** | **Righe distinte per mese di effettuazione**, campo editabile per riga con tooltip. Non esiste prassi ufficiale AdE. La riga-per-mese è la lettura più aderente alla regola generale (mese/anno individuano il periodo del debito) ed è quella che il consulente può sempre accorpare; il contrario non è vero. Rendere la scelta un default di config, mai una costante. |
| 2 | **Plafond o versamento mensile sempre** | **Default: plafond a soglia.** È la facoltà pensata per ridurre gli F24 di un condominio piccolo. Ma esporre il toggle per condominio: alcuni amministratori preferiscono la cadenza fissa, e versare in anticipo sotto soglia è **legittimo e non va mai segnalato come errore**. |
| 3 | **Bollo di 2 € riaddebitato nella base ritenuta** | **Escluso di default**, opzione per includerlo. L'inclusione è dottrina prevalente, non prassi: nessun documento AdE la afferma per la ritenuta d'acconto, e dal 1/1/2025 l'art. 54 c.2 lett. b) TUIR complica il quadro. Il default deve essere quello che non espone il condominio a una rivalsa contestabile. |
| 4 | **Codice sanzione da ravvedimento su 1019/1020** | **Mai precompilare.** La Ris. 18/E/2023 non cita 1019/1020 e la ritenuta 25-ter colpisce redditi d'impresa, categoria assente da tutte e tre le denominazioni 8947/8948/8949. Campo obbligatorio a input manuale, delega non confermabile finché è vuoto, banner esplicito che la corrispondenza non è confermata da prassi. |
| 5 | **Sanzioni e interessi: a carico della gestione o dell'amministratore** | **Default: gestione (6004)**, con opzione per addebitarli all'amministratore (`DARE crediti_diversi / AVERE banca` per la quota sanzione). È una decisione assembleare, non contabile: il software offre entrambe le strade e traccia la scelta con nota. |
| 6 | **Pagamento in contanti a fornitore soggetto a ritenuta** | **Blocco con override motivato**, non blocco duro. L'art. 25-ter c. 3 impone il c/c intestato al condominio con sanzione ex art. 11 D.Lgs. 471/1997, ma esistono situazioni di fatto (piccola cassa, arretrati) in cui vietare del tutto significherebbe far registrare il pagamento fuori dal software — che è peggio. |
| 7 | **`natura_percipiente` mancante** | **v1.10: warning bloccante con override. v1.11: blocco duro.** I dati reali hanno codici tributo misti; un blocco immediato paralizzerebbe i pagamenti il giorno dopo l'aggiornamento. |
| 8 | **Compensazione crediti (1403 / 1627-1628)** | **Non automatizzare.** Il credito resta su 1403 e va portato al consulente: sopra 5.000 € serve il visto di conformità e l'F24 con compensazione va solo su canale telematico. Il software predispone la colonna a credito e il guard rail sul canale, senza motore. |
| 9 | **Quadro AC** | Esporre come **export** ("elenco fornitori e forniture per anno solare, per cassa"), non come dichiarazione. È un adempimento **dell'amministratore nella sua dichiarazione personale**, non del condominio: va detto in UI o genera aspettative sbagliate. |

---

## (8) COSA IL CODICE ATTUALE SBAGLIA — DA CORREGGERE A PRESCINDERE

Questi nove punti sono difetti già presenti, indipendenti dalla scelta architetturale. Vanno chiusi anche se il modulo F24 slittasse.

1. **`applica_ritenuta` non è validato in `StoreFatturaRequest`** e il controller passa `$request->validated()` (`FatturaPassivaController:202-203`): la chiave viene scartata e vale sempre il default `?? true`. Dalla UI **non si può** registrare una fattura senza ritenuta per un fornitore soggetto. → aggiungere alle regole.

<!-- rettifica -->
> ⚠️ **Non è più vero — verificato il 31/07/2026 su 1.10.0-beta.32.** Difetto chiuso in beta.21: la chiave è validata sia in creazione sia in modifica, con motivo obbligatorio quando disattivata.
> *Prova:* app/Http/Requests/Gestionale/Movimenti/StoreFatturaRequest.php:59 ('applica_ritenuta' => 'nullable|boolean') e :62 (required_if:applica_ritenuta,false su motivo_esclusione_ritenuta); stesso schema in UpdateFatturaRequest.php:42,:45. Test: tests/Feature/Gestionale/RitenutaSezione8Test.php:15.
<!-- /rettifica -->

2. **Storno fattura (`StornoFatturaController:85`)**: forza `applica_ritenuta = false`, quindi l'originale accredita 2201 del netto e 2202 della ritenuta, mentre la NC di storno addebita 2201 dell'intero totale e non tocca 2202. Ogni scrittura quadra da sola — `DoubleEntryValidator` non intercetta nulla — ma restano un **DARE fantasma su 2201** e un **AVERE residuo su 2202**. → propagare `applica_ritenuta` dall'originale, con test dedicato (oggi assente).

<!-- rettifica -->
> ⚠️ **Non è più vero — verificato il 31/07/2026 su 1.10.0-beta.32.** Lo storno propaga già dall'originale, e il test dedicato esiste. Anche il numero di riga è cambiato (85 → 118).
> *Prova:* app/Http/Controllers/Gestionale/Movimenti/StornoFatturaController.php:118 ('applica_ritenuta' => $fattura->importo_ritenuta > 0), con commento esplicativo a :115 e :125. Test: tests/Feature/Gestionale/RitenutaSezione8Test.php:46 e :271 (lo storno usa l'importo originale, non ricalcola sull'anagrafica attuale).
<!-- /rettifica -->

3. **Nota di credito con fornitore soggetto a ritenuta**: il backend calcola comunque la ritenuta (`FatturaPassivaService:106-118`) e il filtro invertitore (`:421-427`) produce un **DARE su 2202 spurio**, mentre il frontend mostra ritenuta = 0 (`FatturaRegisterNew.vue:211`). L'importo salvato diverge da quello mostrato. → sopprimere il calcolo sulle NC lato backend + test di non-regressione.

<!-- rettifica -->
> ⚠️ **Non è più vero — verificato il 31/07/2026 su 1.10.0-beta.32.** Il calcolo è stato soppresso sulle NC: il default è «non applicare», salvo scelta esplicita.
> *Prova:* app/Services/Gestionale/FatturaPassivaService.php:117 ($richiestaApplicazioneRitenuta = $data['applica_ritenuta'] ?? ! $isNotaCredito) e :714 in aggiornamento; StoreFatturaRequest.php:217 replica la stessa regola. Test: tests/Feature/Gestionale/RitenutaSezione8Test.php:87.
<!-- /rettifica -->

4. **`SyncF24WithPagamento` non è iscritto a `PagamentoStornato`** (`subscribe()` a `:38-49`): dopo uno storno il task Inbox "F24 Ritenuta" resta aperto e segnala un versamento non più dovuto, chiudibile solo a mano.

5. **Il calcolo della scadenza F24 gestisce solo sabato/domenica** (`:86-88`): nessun calendario di festività. Il 16/08 e il lunedì dell'Angelo vengono proposti come scadenze valide.

6. **Nota di riga hardcoded `"Ritenuta d'acconto 4% fattura fornitore"`** (`FatturaPassivaService:387`) mentre l'aliquota reale viene da `fornitore->perc_ritenuta`: fuorviante per il 20% e per ogni aliquota diversa dal 4%.

<!-- rettifica -->
> ⚠️ **Non è più vero — verificato il 31/07/2026 su 1.10.0-beta.32.** Il flag per riga esiste, è persistito, è esposto in UI e la base lo rispetta. Il riferimento di riga è comunque obsoleto: a :50-70 c'è ora il loop imponibile/IVA, non il calcolo della base ritenuta.
> *Prova:* database/migrations/2026_07_22_090100_add_base_ritenuta_to_righe_fattura_table.php (concorre_base_ritenuta default true + natura_riga_ritenuta); app/Services/Gestionale/RitenutaService.php:59 (baseImponibile esclude le righe con flag false); UI: resources/js/pages/gestionale/movimenti/fatture/FatturaRegisterEdit.vue:990 (checkbox), FatturaRegisterNew.vue:275. Test: RitenutaSezione8Test.php:137.
<!-- /rettifica -->

<!-- rettifica -->
> ⚠️ **Non è più vero — verificato il 31/07/2026 su 1.10.0-beta.32.** La nota è ora generata dal calcolo, con aliquota reale; alla riga 387 di quel file c'è la create() della ScritturaContabile, non la stringa.
> *Prova:* app/Services/Gestionale/RitenutaService.php:145 (docblock: «Nota della riga AVERE 2202: aliquota reale, mai "4%" fisso (design §8 punto 6)»); app/Services/Gestionale/FatturaPassivaService.php:509 usa 'note' => $ritenutaCalcolo->nota. Test: RitenutaSezione8Test.php:114.
<!-- /rettifica -->

7. **Il pro-quota scatta solo con `$importoRitenuta === 0`** (`PagamentoFornitoreService:284`): un client che invia esplicitamente 0 per una fattura con ritenuta ottiene comunque il calcolo automatico. In `aggiornaPagamento` (`:450`, `:573`) l'importo è preso pari pari dalla request **senza alcuna validazione di coerenza** con le fatture allocate. Finché era uno snapshot era un fastidio; nel nuovo modulo diventa l'importo che finisce in F24 e nel sigillo. → validare.

8. **`HasProtocolNumber` manda `pagamento_f24` nel `default => 'SCR'`**: il primo F24 nascerebbe con protocollo di scrittura generica.

<!-- rettifica -->
> ⚠️ **Non è più vero — verificato il 03/08/2026 su 1.10.0-beta.37.** I due prefissi esistono e sono corretti, quindi il primo F24 nascerà già con il suo protocollo.
> *Prova:* app/Traits/HasProtocolNumber.php:40 (`'pagamento_f24' => 'F24'`) e :41 (`'storno_pagamento_f24' => 'STO'`).
<!-- /rettifica -->

9. **La base imponibile include tutte le righe indiscriminatamente** (`FatturaPassivaService:55-62`): righe ad personam, sopravvenienze, forniture con posa accessoria, contributo integrativo cassa. Il sistema calcola oggi un importo fiscalmente sbagliato — e senza il flag per riga qualunque modulo di versamento lo trasformerebbe in un F24 sbagliato.

> **Ordine imposto**: i punti 1, 2, 3 e 9 sono **prerequisiti P0** della Fase 2. Senza di essi l'invariante di riconciliazione parte già sbilanciato su ogni condominio che abbia stornato una fattura o emesso una nota di credito, e il "canarino" lampeggerebbe rosso dal primo giorno senza che nulla di nuovo sia rotto.
