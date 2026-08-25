# Kondomanager v1.12 — Anagrafica Fornitore (DNA Fiscale)

<!-- verifica-documentazione -->
> **Stato:** Contiene affermazioni false — verificato il 31/07/2026 su 1.10.0-beta.32
> Usabile come specifica funzionale della v1.12 (nulla di questo documento è ancora costruito: nessuna delle colonne, tabelle o classi citate esiste); NON usare la sezione "Future-Proofing" come descrizione dello stato attuale — `RegimeFiscaleResolver` non esiste e il ledger non è immutabile prima del sigillo — e riallineare le parti su ritenute/CU/F24 a `docs/design/f24_ritenute_design.md`, già implementato nella 1.10 con un modello diverso.
<!-- /verifica-documentazione -->

### Specifica Tecnica Definitiva

> **Documento di riferimento** per lo sviluppo della versione 1.12.
> Contiene la proposta originale dell'amministratore, le correzioni tecniche concordate, le risposte di allineamento, l'architettura implementativa definitiva e le note di scalabilità futura.

---

## Indice

1. [Obiettivo](#obiettivo)
2. [Sezione 1 — Informazioni Principali](#sezione-1--informazioni-principali)
3. [Sezione 2 — Recapiti e Sede](#sezione-2--recapiti-e-sede)
4. [Sezione 3 — Fatturazione e Pagamenti](#sezione-3--fatturazione-e-pagamenti)
5. [Sezione 4 — Dati Societari](#sezione-4--dati-societari)
6. [Logica UI Dinamica](#logica-ui-dinamica)
7. [Correzioni e Allineamenti Tecnici](#correzioni-e-allineamenti-tecnici)
8. [Architettura del Modello Dati](#architettura-del-modello-dati)
9. [Versioning del Regime Fiscale](#versioning-del-regime-fiscale)
10. [Test Coverage — Reverse Charge](#test-coverage--reverse-charge)
11. [Fasi di Implementazione](#fasi-di-implementazione)
12. [Moduli Intelligenti](#moduli-intelligenti)
13. [Future-Proofing e Considerazioni di Scalabilità](#future-proofing-e-considerazioni-di-scalabilità)
14. [Note Aperte e Decisioni Differite](#note-aperte-e-decisioni-differite)

---

## Obiettivo

Trasformare l'anagrafica fornitore da semplice archivio statico a **"DNA fiscale" del sistema**: un motore di regole dinamico che riduce gli errori di input (poka-yoke) e alimenta automaticamente compliance fiscale, fatturazione elettronica e tesoreria.

Il principio cardine: se l'anagrafica è solida, l'intero castello della contabilità, degli adempimenti e della tesoreria sta in piedi da solo.

```
Anagrafica (regole) → Input (validazione) → Contabilità (scritture auto) → Adempimenti (CU / 770 / F24)
```

---

## Sezione 1 — Informazioni Principali

| Campo | Tipo | Note |
|---|---|---|
| Ragione sociale | Testo | Obbligatorio |
| Referente principale | Menu a tendina | Legato a contatti interni/esterni |
| Partita IVA | Alfanumerico (11 cifre) | Validazione algoritmica in tempo reale (checksum) |
| Codice Fiscale | Alfanumerico (11 o 16 caratteri) | Validazione formale caratteri; critico per evitare scarti SDI |
| Note aggiuntive interne | Area di testo | Ridimensionata rispetto alla versione attuale |

---

## Sezione 2 — Recapiti e Sede

| Campo | Tipo | Note |
|---|---|---|
| Indirizzo e civico | Testo unico | Necessario per tracciato XML fattura elettronica |
| CAP / Comune / Prov. | Testo con autocompletamento | Database CAP integrato |
| Telefono fisso / Cellulare / Fax | Numerico | Contatti rapidi |
| Email ordinaria | Testo | Validazione standard |
| Email PEC | Testo | Validazione formato PEC |
| Sito internet | URL | — |
| Codice Destinatario SDI | Alfanumerico rigido 7 caratteri | **Nuovo campo.** Priorità assoluta sull'instradamento fattura elettronica |
| Checkbox "Invia fatture in formato P.A." | Booleano | **Nuovo campo.** Forza tracciato XML PA con Split Payment e campi CIG/CUP |

### Logica SDI / PEC

```
Se Codice Destinatario SDI ≠ vuoto e ≠ 0000000
    → usa Codice Destinatario come canale di recapito
Altrimenti
    → usa Email PEC come canale sussidiario
```

> ⚠️ Il codice `XXXXXXX` indica fornitore estero: in questo caso si applicano regole specifiche distinte dal fallback PEC.

---

## Sezione 3 — Fatturazione e Pagamenti

### Campo chiave: Regime Fiscale

Menu a tendina che pilota il comportamento di tutti gli altri campi della sezione.

**Opzioni ufficiali Agenzia delle Entrate:**

| Codice | Descrizione |
|---|---|
| RF01 | Ordinario |
| RF02 | Contribuenti minimi (art. 1, c.96-117, L.244/2007) — *disattiva IVA/Ritenuta* |
| RF04 | Agricoltura e attività connesse e pesca |
| RF05 | Vendita sali e tabacchi |
| RF06 | Commercio dei fiammiferi |
| RF07 | Editoria |
| RF08 | Gestione servizi di telefonia pubblica |
| RF09 | Rivendita documenti di trasporto pubblico e di sosta |
| RF10 | Intrattenimenti, giochi e altre attività |
| RF11 | Agenzie viaggi e turismo |
| RF12 | Agriturismo |
| RF13 | Vendite a domicilio |
| RF14 | Rivendita beni usati, oggetti d'arte, antiquariato |
| RF15 | Agenzie di vendite all'asta |
| RF16 | IVA per cassa (art. 32-bis, D.L. 83/2012) |
| RF17 | IVA per cassa P.A. |
| RF18 | Altro |
| RF19 | Forfettario (art.1, c.54-89, L.190/2014) — *disattiva IVA/Ritenuta* |
| RF20 | Regime transfrontaliero di Franchigia IVA (Dir. UE 2020/285) |

### Aliquota IVA di default

- Menu a tendina legato alle aliquote configurate nel sistema (22%, 10%, 4%, Esente, Non Imponibile, ecc.)
- **Se Regime = RF19 (Forfettario) o RF02 (Minimi)**: campo bloccato automaticamente su natura `N2.2` — "Non soggetto IVA art. 1 c.54-89 L.190/2014"

> ⚠️ **Correzione tecnica concordata**: nel tracciato XML non basta indicare "esente". Serve il codice **Natura** corretto. Mappatura obbligatoria:
>
> | Regime | Natura XML |
> |---|---|
> | RF19 Forfettario | N2.2 |
> | RF02 Minimi | N2.2 |
> | Esente art. 10 | N4 |
> | Non imponibile art. 8 | N3.x |
> | Reverse Charge | N6.x (vedi sotto) |

### Natura IVA per Reverse Charge (N6.x)

Campo aggiuntivo `natura_iva_default` attivo quando il regime lo prevede. **Default sovrascrivibile a livello di singola fattura.**

| Codice | Descrizione | Frequenza condominiale |
|---|---|---|
| N6.7 | Prestazioni comparto edile, pulizie, installazione impianti, completamento edifici | ⭐ **Predominante** |
| N6.3 | Subappalti settore edile | Raro (condominio = committente finale) |
| N6.1 | Cessioni di rottami e altri materiali di recupero | Raro ma possibile |
| N6.2 / N6.4 / N6.5 / N6.6 / N6.8 / N6.9 | Altri casi | Non rilevanti — esclusi dal dropdown |

> **Confermato dall'amministratore**: N6.7 copre il 95%+ dei casi condominiali. N6.3 e N6.1 inclusi come opzioni selezionabili ma non come default.

### Soggetto a Ritenuta d'Acconto

- **Visibile e cliccabile** solo se Regime Fiscale prevede la ritenuta (es. RF01 Ordinario)
- **Nascosto/disattivato** se RF19 Forfettario o RF02 Minimi
- Se attivato, espone a cascata: **Causale CU** e **Codice tributo F24**

#### Causale CU

> ⚠️ **Correzione tecnica concordata**: usare i codici ufficiali del tracciato CU, non descrizioni libere. **Default sovrascrivibile per fattura.**

| Codice | Descrizione | Rilevanza condominiale |
|---|---|---|
| A | Prestazioni di lavoro autonomo rientranti nell'esercizio di arte o professione abituale | Alta (Architetto, Avvocato, Commercialista, Geometra) |
| M | Prestazioni di lavoro autonomo non esercitate abitualmente | Alta (Consulente occasionale) |
| W | Compensi corrisposti da condomini (art. 25 L.413/91) | **Alta** (portieri, pulizie, giardinieri) |
| V | Associazioni sportive dilettantistiche | Bassa |
| Q | Provvigioni | Media |

#### Codice Tributo F24

> ⚠️ **Correzione tecnica concordata**: il codice tributo dipende dal tipo di compenso, non dall'anagrafica fornitore. Impostarlo come **default suggerito sovrascrivibile a livello di singola fattura**.

| Codice | Descrizione |
|---|---|
| 1019 | Provvigioni agenti, mediatori |
| 1020 | Provvigioni agenti monomandatari |
| 1040 | Compensi lavoro autonomo professionale |
| 1628 | Condomini — compensi ex art. 25 L.413/91 |
| 6782 | Lavoro dipendente |
| 8948 | Prestazioni sportive dilettantistiche |

### Ritenuta Previdenziale

> ⚠️ **Correzione tecnica concordata**: distinguere obbligatoriamente tra:
> - **Rivalsa INPS Gestione Separata 4%** → si *aggiunge* al netto (non è una ritenuta, non riduce il netto da pagare)
> - **Enasarco** → trattenuta che *riduce* il netto da pagare

| Campo | Tipo | Note |
|---|---|---|
| Soggetto a ritenuta previdenziale | Checkbox | Mostrato in base a Categoria × Regime Fiscale |
| Percentuale | Numerico | Es. 4% INPS, 8,50% Enasarco |
| Tipo cassa | Menu a tendina | INPS Gestione Separata / Enasarco / Casse Professionali |
| Applica R.A. su ritenuta previdenziale | Booleano | Calcola RA sul lordo comprensivo di cassa o solo sull'imponibile puro |

### Altri flag fiscali

| Campo | Tipo | Note |
|---|---|---|
| Inserire nei riepiloghi fiscali 770/Unico | Booleano | Agganciamento export Modello 770 |
| Inserire in elenco clienti/fornitori | Booleano | Inclusione comunicazioni massive IVA |
| **Scissione pagamenti** | Booleano | **Nuovo campo.** Indipendente dal flag PA — art. 17-ter si applica anche a soggetti privati controllati da PA |

### IBAN e Pagamenti

> **Architettura confermata**: tabella `fornitore_iban` con lista di IBAN e flag `is_default`. Soluzione a due slot (principale + secondario) scartata perché limitante per fornitori strutturati.

| Campo | Tipo | Note |
|---|---|---|
| Lista IBAN | Tabella relazionale | Più IBAN per fornitore; uno impostato come principale |
| Modalità di pagamento | Menu a tendina | Bonifico bancario, RI.BA, ecc. |
| Scadenza (Giorni) | Numerico + tendina | 30/60/90 gg D.F. o D.F. F.M. |

#### Sicurezza IBAN — Double Lock e Audit Log

Ogni modifica a qualsiasi IBAN deve:
1. Generare un **log inalterabile** (chi ha modificato, cosa, quando) via `spatie/laravel-activitylog`
2. Attivare **workflow a due step**: operatore propone → supervisore approva
3. Inviare **notifica email automatica** a ogni variazione

---

## Sezione 4 — Dati Societari

| Campo | Tipo | Note |
|---|---|---|
| Iscrizione CCIAA / Data iscrizione | Testo + Data | — |
| Capitale sociale | Numerico valutario | — |
| Codice ATECO | Alfanumerico | Tracciamento attività |
| Iscrizione Albo/Ordine professionisti | Testo numerico | — |
| Certificazione ISO | Booleano | — |

### Categoria Fornitore (Multi-select con Tag)

Componente multi-select: selezione multipla, ogni voce appare come tag rimovibile.

**Categorie standard:**

> Amministratore, Antennista, Antincendio, Architetto, Ascensorista, Assicurazione, Autospurgo, Avvocato, Caldaista, Cancello Elettrico, Commercialista, Consulente, Controllo Estintori, Copisteria, Disinfestazione, Elettricista, Emergenze, Ente certificatore, Fabbro, Falegname, Ferramenta, Fornitura, Fornitura elettrica, Fornitura idrica, Fornitura metano, Generico, Geometra, Giardiniere, Idraulico, Imbianchino

**Logica condizionale incrociata:**

```
Se categoria ∈ {Architetto, Avvocato, Commercialista, Consulente, Geometra}
    E Regime Fiscale non inibisce i campi ritenuta
    → preimposta automaticamente blocco dati professionali (Sezione 3)
```

### DURC e Compliance Documentale

> **Architettura confermata**: salvo **data di emissione** + **scadenza calcolata automaticamente** a 120 giorni. Il campo scadenza rimane **manualmente sovrascrivibile** per eccezioni documentali.

| Campo | Tipo | Comportamento |
|---|---|---|
| Verifica scadenze DURC | Checkbox + Data emissione | Scadenza auto-calcolata a +120 gg; overridable |
| Data scadenza DURC | Data | Calcolata automaticamente; editabile manualmente |
| Data Scadenza Visura Camerale | Data | Alert interno se documento supera 6 mesi |

### Frontaliere Svizzero

| Opzione | Descrizione |
|---|---|
| NO | — |
| Non definito / Non pertinente | — |
| SI — attiva casella "Luogo di attinenza" | CU con casella attiva |
| SI — non attivare casella "Luogo di attinenza" | CU senza casella |

---

## Logica UI Dinamica

Il campo **Regime Fiscale** è il "direttore d'orchestra". Le combinazioni principali:

```
RF19 Forfettario
    → Aliquota IVA: bloccata su N2.2
    → Soggetto a ritenuta d'acconto: nascosto
    → Ritenuta previdenziale: nascosta
    → Natura IVA RC: non applicabile

RF01 Ordinario + categoria professionale
    → Aliquota IVA: selezionabile
    → Soggetto a ritenuta d'acconto: visibile e attivabile
    → Se ritenuta attiva: mostra Causale CU + Codice tributo F24
    → Ritenuta previdenziale: visibile se combinazione categoria × regime lo prevede

RF01 Ordinario + natura N6.x
    → Natura IVA RC: selezionabile (N6.7 default per fornitori edili)
    → FatturaPassivaService genera doppia scrittura: IVA acquisti in dare + IVA vendite in avere
```

> Implementazione: la logica risiede in un service dedicato `RegimeFiscaleResolver` (testabile in isolamento), non sparsa nei componenti Vue. La UI consulta il resolver per sapere quali campi mostrare/abilitare.

---

## Correzioni e Allineamenti Tecnici

Riepilogo delle correzioni concordate con l'amministratore prima del congelamento delle spec.

| # | Punto | Problema originale | Soluzione concordata |
|---|---|---|---|
| 1 | Natura IVA forfettari | Usava "esente" generico | Mappare regime → codice Natura XML (N2.2, N4, N3.x, N6.x) |
| 2 | Codice tributo F24 | Fisso sull'anagrafica | Default suggerito, sovrascrivibile per fattura |
| 3 | Causale CU | Descrizioni libere non standard | Codici ufficiali CU: A, M, W, Q (vedi tabella) |
| 4 | Ritenuta previdenziale | Confusa con rivalsa | Distinguere rivalsa INPS 4% da trattenuta Enasarco |
| 5 | Split Payment | Solo flag PA | Flag "Scissione pagamenti" indipendente dal flag PA |
| 6 | DURC | Solo data scadenza | Data emissione + scadenza auto (+120 gg) + override manuale |
| 7 | IBAN multipli | Campo singolo | Tabella `fornitore_iban` con flag `is_default` |
| 8 | Reverse Charge | Generica "giroconto UI" | Modifica a `FatturaPassivaService`: doppia scrittura su N6.x |
| 9 | Riassunto architetturale | RC nel Pillar 1 (UI) | RC appartiene al Pillar 2 (service layer contabile) |

---

## Architettura del Modello Dati

### Tabelle nuove o modificate

```sql
-- Modifica tabella fornitori (attributi anagrafici di base + default fiscali correnti)
ALTER TABLE fornitori ADD COLUMN regime_fiscale VARCHAR(4);        -- es. RF01, RF19
ALTER TABLE fornitori ADD COLUMN natura_iva_default VARCHAR(5);    -- es. N6.7, N2.2
ALTER TABLE fornitori ADD COLUMN aliquota_iva_default_id INT;
ALTER TABLE fornitori ADD COLUMN soggetto_ritenuta_acconto BOOLEAN;
ALTER TABLE fornitori ADD COLUMN causale_cu VARCHAR(2);            -- A, M, W, Q...
ALTER TABLE fornitori ADD COLUMN codice_tributo_f24 VARCHAR(4);    -- 1040, 1628...
ALTER TABLE fornitori ADD COLUMN soggetto_ritenuta_prev BOOLEAN;
ALTER TABLE fornitori ADD COLUMN tipo_cassa_prev ENUM('inps_gs','enasarco','cassa_prof');
ALTER TABLE fornitori ADD COLUMN perc_ritenuta_prev DECIMAL(5,2);
ALTER TABLE fornitori ADD COLUMN ra_su_prev BOOLEAN;
ALTER TABLE fornitori ADD COLUMN flag_770 BOOLEAN;
ALTER TABLE fornitori ADD COLUMN flag_elenco_iva BOOLEAN;
ALTER TABLE fornitori ADD COLUMN scissione_pagamenti BOOLEAN;
ALTER TABLE fornitori ADD COLUMN flag_pa BOOLEAN;
ALTER TABLE fornitori ADD COLUMN codice_destinatario_sdi VARCHAR(7);
ALTER TABLE fornitori ADD COLUMN frontaliere_ch ENUM('no','nd','si_attiva','si_no_attiva');
ALTER TABLE fornitori ADD COLUMN durc_data_emissione DATE;
ALTER TABLE fornitori ADD COLUMN durc_data_scadenza DATE;          -- calcolata + overridable
ALTER TABLE fornitori ADD COLUMN visura_data_scadenza DATE;
ALTER TABLE fornitori ADD COLUMN anagrafica_completata BOOLEAN DEFAULT FALSE;

-- Nuova tabella IBAN (lista)
CREATE TABLE fornitore_iban (
    id INT PRIMARY KEY AUTO_INCREMENT,
    fornitore_id INT NOT NULL,
    iban VARCHAR(27) NOT NULL,
    is_default BOOLEAN DEFAULT FALSE,
    created_by INT,
    created_at TIMESTAMP,
    -- Double lock
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    approved_by INT,
    approved_at TIMESTAMP
);

-- Nuova tabella categorie fornitore (multi-select)
CREATE TABLE fornitore_categorie (
    fornitore_id INT NOT NULL,
    categoria VARCHAR(50) NOT NULL,
    PRIMARY KEY (fornitore_id, categoria)
);
```

### Impatto su FatturaPassivaService

Quando `fornitore.natura_iva_default IN ('N6.1', 'N6.3', 'N6.7')`:

```php
// Logica Reverse Charge in FatturaPassivaService
if ($fornitore->isReverseCharge()) {
    // Scrittura normale (costo)
    $this->creaScrittura($fattura, 'competenza', $importoNetto, $contoSpesa);

    // Giroconto IVA acquisti (dare)
    $this->creaScrittura($fattura, 'rc_iva_acquisti', $importoIva, $contoIvaAcquisti);

    // Giroconto IVA vendite (avere) — netto zero
    $this->creaScrittura($fattura, 'rc_iva_vendite', $importoIva, $contoIvaVendite);
}
```

<!-- rettifica -->
> ⚠️ **Non è più vero — verificato il 31/07/2026 su 1.10.0-beta.32.** Né la colonna né il metodo esistono; il reverse charge non è modellato in nessun punto del codice.
> *Prova:* grep -rn "natura_iva_default" app/ database/ resources/js/ = 0; grep -rn "isReverseCharge" app/ = 0; grep -rn "reverse_charge" app/ database/migrations/ = 1 solo risultato, un commento in app/Services/Gestionale/PagamentoFornitoreService.php:1364.
<!-- /rettifica -->

> ⚠️ Questa modifica richiede test specifici di quadratura — vedi sezione [Test Coverage — Reverse Charge](#test-coverage--reverse-charge).

---

## Versioning del Regime Fiscale

> ⚠️ **Gap critico identificato in revisione**: il regime fiscale di un fornitore può cambiare nel tempo (es. passaggio da Forfettario a Ordinario). Le fatture storiche devono essere registrate secondo il regime *in vigore al momento della registrazione*, non secondo l'ultimo regime impostato.

### Modello dati

```sql
-- Tabella versionata dei profili fiscali del fornitore
CREATE TABLE fornitore_fiscal_profile_versions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    fornitore_id INT NOT NULL,
    valid_from DATE NOT NULL,
    valid_to DATE NULL,                -- NULL = versione corrente
    regime_fiscale VARCHAR(4) NOT NULL,
    natura_iva_default VARCHAR(5),
    soggetto_ritenuta_acconto BOOLEAN,
    causale_cu VARCHAR(2),
    codice_tributo_f24 VARCHAR(4),
    soggetto_ritenuta_prev BOOLEAN,
    tipo_cassa_prev ENUM('inps_gs','enasarco','cassa_prof'),
    perc_ritenuta_prev DECIMAL(5,2),
    ra_su_prev BOOLEAN,
    scissione_pagamenti BOOLEAN,
    created_by INT,
    created_at TIMESTAMP,
    INDEX idx_fornitore_validita (fornitore_id, valid_from, valid_to)
);
```

### Logica operativa

```
Quando FatturaPassivaService registra una fattura con data documento D:
    1. Lookup fornitore_fiscal_profile_versions
       WHERE fornitore_id = X
       AND valid_from <= D
       AND (valid_to IS NULL OR valid_to >= D)
    2. Usa il profilo trovato per la registrazione contabile
    3. Memorizza il fiscal_profile_version_id sulla fattura per audit
```

### Modifica del profilo fiscale

- Quando un operatore modifica il regime fiscale di un fornitore:
  1. Si chiude la versione corrente (`valid_to = data_modifica - 1 giorno`)
  2. Si crea una nuova versione (`valid_from = data_modifica`, `valid_to = NULL`)
- I campi "default correnti" su `fornitori` riflettono sempre la versione attiva (per performance UI), ma non sono fonte autoritativa per la contabilità storica.

> **Beneficio**: una CU rigenerata nel 2027 per un fornitore che era forfettario nel 2024 e ordinario dal 2025 in poi produce dati corretti per entrambi gli anni.

---

## Test Coverage — Reverse Charge

Il Reverse Charge ha implicazioni contabili profonde (quadratura, deducibilità, integrità partita doppia). I test devono essere aggressivi e coprire tutti gli scenari reali.

### Casi di test obbligatori per Fase B

| # | Scenario | Verifica |
|---|---|---|
| 1 | RC ordinario singola fattura (N6.7) | Quadratura: imponibile + IVA dare/avere = costo netto |
| 2 | RC con nota credito totale | Le 3 scritture originali si annullano simmetricamente |
| 3 | RC con nota credito parziale | Quadratura proporzionale, partita doppia integra |
| 4 | RC misto (parte RC + parte ordinaria nella stessa fattura) | Doppia logica nello stesso documento, quadratura per riga |
| 5 | RC + ritenuta d'acconto (raro ma possibile) | Calcolo netto coerente: costo - ritenuta + 0 IVA |
| 6 | RC con cambio regime fiscale tra emissione e pagamento | Usa il profilo fiscale `valid_at(data_documento)` |
| 7 | Quadratura bilancio post-RC | Saldo IVA acquisti = saldo IVA vendite a fine periodo |
| 8 | RC su fattura in valuta estera | Conversione applicata uniformemente alle 3 scritture |
| 9 | Doppia registrazione RC stessa fattura (duplicato) | Bloccata da constraint applicativo |
| 10 | RC con allocazione multi-condominio | Quadratura per condominio + globale |

### Approccio

- Pest feature test isolati per ciascuno scenario
- SQLite in-memory con `Event::fake()` per i listener collaterali
- Asserzioni esplicite sui saldi `dare` e `avere` per ogni scrittura
- Test di quadratura aggregata: `SUM(dare) == SUM(avere)` dopo ogni transazione

---

## Fasi di Implementazione

### Fase A — Anagrafica *(anticipabile, CRUD/UI)*

- Nuovi campi su tabella `fornitori` (dati anagrafici, SDI, PEC, ATECO, CCIAA)
- Componente multi-select categorie con tag (Vue 3)
- Validatori in tempo reale: P.IVA (checksum), CF (16 caratteri), IBAN (MOD 97)
- Tabella `fornitore_iban` con double lock e audit log
- **Tabella `fornitore_fiscal_profile_versions`** con seed della versione iniziale per ogni fornitore
- Script migrazione legacy: `regime_fiscale = 'RF01'` come default, `anagrafica_completata = false`, soft warning al primo utilizzo

### Fase B — Contabilità *(richiede test estesi)*

- Menu Regime Fiscale con logica UI dinamica (`RegimeFiscaleResolver` service)
- Mappatura regime → codice Natura IVA (tabella lookup)
- Campo `natura_iva_default` per Reverse Charge (N6.7, N6.3, N6.1)
- Modifica `FatturaPassivaService`: doppia scrittura automatica su N6.x
- Lookup del profilo fiscale versionato in fase di registrazione fattura
- Flag Scissione Pagamenti indipendente dal flag PA
- **Test suite RC completa** (10 scenari obbligatori — vedi sezione test)

### Fase C — Ritenute & Adempimenti *(richiede test)*

- Causali CU con codici ufficiali (A, M, W, Q, ecc.)
- Codice tributo F24 come default sovrascrivibile per fattura
- Ritenuta previdenziale: distinzione rivalsa INPS vs trattenuta Enasarco
- Checkbox "Applica R.A. su ritenuta previdenziale"
- Flag 770/Unico e flag elenco IVA
- Export massivo CU e Modello 770
- Test Pest: calcolo netto con rivalsa, calcolo netto con Enasarco, F24 mensile

### Fase D — Compliance e Sicurezza *(richiede Fase A)*

- DURC: data emissione + scadenza auto (+120 gg) + override manuale + alert visivo
- Visura Camerale: alert se documento > 6 mesi
- Frontaliere svizzero: logica CU con/senza casella "Luogo di attinenza"
- Audit log IBAN via `spatie/laravel-activitylog`
- Double lock su modifiche IBAN: workflow proposta → approvazione supervisore + email notification

---

## Moduli Intelligenti

Da sviluppare **post v1.12**, in ordine di priorità:

### 1. Fiscal Sentinel *(dipende da Fase B)*
Validation layer su `FatturaPassivaService`. Incrocia profilo fiscale fornitore con dati fattura in ingresso.

- Forfettario con IVA addebitata → **soft warning + override con permesso elevato** (non hard block)
- Professionista senza ritenuta → warning
- Natura IVA incongruente con regime → warning

### 2. Compliance Health Badge *(dipende da Fase D)*
Semaforo verde/giallo/rosso visibile nella lista fornitori.

- 🟢 Verde: DURC valido, Visura < 6 mesi, anagrafica completa, CF/PIVA validi
- 🟡 Giallo: uno o più documenti in scadenza imminente
- 🔴 Rosso: DURC scaduto, anagrafica incompleta, CF/PIVA non validi

### 3. Smart Ledger Suggester *(dipende da Fase A)*
Suggerisce il conto contabile in input fattura.

- Logica: `categoria_fornitore × voce_deliberata × condominio → conto_id`
- Partenza rule-based, evoluzione frequency-based
- Attenzione: il piano dei conti è strutturato per capitoli di preventivo deliberato — il mapping non è solo categoria → conto generico

### 4. Treasury Guardian *(dipende da Fase D)*
Freeze automatico mandato di pagamento su DURC scaduto.

- Blocco con **override doppia firma** + log obbligatorio del motivo (non hard block assoluto)
- Motivo: fornitore in monopolio (es. unica ditta ascensori) non può essere bloccato senza eccezione

### 5. RC Auto-entry *(dipende da Fase B)*
Proposta automatica doppia scrittura IVA su fatture XML con natura N6.x.

### 6. Fiscal Debt Predictor *(dipende da Fase C consolidata)*
Previsione F24 mensile per codice tributo.

- Formula: `SUM(importo_ritenuta) GROUP BY codice_tributo, mese_scadenza`
- Deadline versamento: 16 del mese successivo
- Affidabile solo dopo Fase C completata e consolidata

---

## Future-Proofing e Considerazioni di Scalabilità

Questa sezione raccoglie osservazioni emerse in revisione architettura su evoluzioni potenziali del sistema. **Nessuna di queste modifiche è richiesta in v1.12**: sono opzioni da valutare *quando emergerà una necessità concreta*, non refactor preventivi.

### Rule Engine dichiarativo

**Stato attuale**: la logica fiscale (regime → comportamenti UI/contabili) risiede in `RegimeFiscaleResolver` come service class testabile.

<!-- rettifica -->
> ⚠️ **Non è più vero — verificato il 31/07/2026 su 1.10.0-beta.32.** La classe non esiste da nessuna parte nel codice: non esiste né il resolver né alcuna logica regime→comportamento.
> *Prova:* grep -rn "RegimeFiscaleResolver" su app/ resources/js/ database/ tests/ routes/ = 0 risultati. Nessun file in app/Services/ con quel nome (ls app/Services/Gestionale/).
<!-- /rettifica -->

**Possibile evoluzione**: spostare le regole in una tabella/file JSON dichiarativo (`fiscal_rules`) che mappa condizioni a effetti. Esempio:

```json
{
  "rule": "forfettario_no_iva",
  "when": { "regime_fiscale": "RF19" },
  "then": {
    "aliquota_iva": "lock_to_N2.2",
    "ritenuta_acconto": "hide",
    "ritenuta_prev": "hide"
  }
}
```

**Quando ha senso**: se le regole diventano >20-30 e/o cambiano spesso, e/o se più sviluppatori lavorano sul progetto. Per un single-developer con regole stabili (le norme fiscali italiane cambiano raramente), il service class è più manutenibile.

### Bounded Contexts (DDD)

**Stato attuale**: modello a service classes con responsabilità separate (`FatturaPassivaService`, `RegimeFiscaleResolver`, `CalcoloQuoteService`).

<!-- rettifica -->
> ⚠️ **Non è più vero — verificato il 31/07/2026 su 1.10.0-beta.32.** Due delle tre classi esistono, la terza no. L'elenco fa credere al lettore che esista già un layer fiscale separato.
> *Prova:* app/Services/Gestionale/FatturaPassivaService.php e app/Services/CalcoloQuoteService.php esistono; "RegimeFiscaleResolver" = 0 risultati su app/.
<!-- /rettifica -->

**Possibile evoluzione**: separazione esplicita in tre bounded context:
1. **Master Data**: anagrafica pura (fornitori, condòmini, immobili)
2. **Fiscal Policy Engine**: regole fiscali versionate (regime → IVA → ritenute → CU/F24)
3. **Accounting Execution**: scritture contabili e adempimenti

**Quando ha senso**: a partire da un team di 3+ sviluppatori, o quando si vuole esporre il Fiscal Policy Engine come microservizio riusabile da altri prodotti.

### Event Sourcing per scritture contabili

**Stato attuale**: scritture contabili immutabili in `scritture_contabili` con storni come scritture inverse (già pattern event-friendly).

<!-- rettifica -->
> ⚠️ **Non è più vero — verificato il 31/07/2026 su 1.10.0-beta.32.** Le scritture NON sono immutabili nella finestra pre-sigillo: modificare o cancellare una fattura fa hard-delete delle scritture e le ricrea. Lo storno inverso è il pattern solo quando la fattura è bloccata da `motivoBloccoModifica()`.
> *Prova:* app/Services/Gestionale/FatturaPassivaService.php:653-656 (`$scrittura->righe()->delete(); $scrittura->forceDelete();` dentro `aggiornaFattura`); stesso pattern in app/Http/Controllers/Gestionale/Movimenti/FatturaPassivaController.php:419, app/Http/Controllers/Gestionale/Movimenti/StornoFatturaController.php:92 e app/Http/Controllers/Gestionale/PianiRate/EmissioneRateController.php:150,303.
<!-- /rettifica -->

**Possibile evoluzione**: event sourcing completo per il dominio contabile — ogni scrittura come evento, ricostruzione dei saldi tramite replay.

**Quando ha senso**: solo se emergono requisiti specifici di temporal querying ("qual era il saldo al 31/12/2023 visto dal sistema il 15/01/2024?") oppure di replay per simulazioni. Altrimenti è over-engineering: il modello attuale a scritture immutabili già garantisce auditabilità.

### Moduli Intelligenti come listener event-driven

**Stato attuale**: i 6 moduli intelligenti previsti sono service classes invocati esplicitamente.

**Possibile evoluzione**: trasformarli in event listener (es. `FatturaRegistrata` → `FiscalSentinelValidator`, `PagamentoApprovato` → `TreasuryGuardian`).

**Quando ha senso**: dopo l'MVP, quando si verifica che più moduli reagiscono allo stesso evento. Per ora il service-class diretto è più semplice da testare e debuggare.

### Principio guida

> **Non over-engineerizzare il futuro a costo del presente.** Il modello attuale è coerente con la scala del progetto (single developer, ~poche centinaia di utenti previsti). Ogni evoluzione di questa sezione va attivata da un *trigger reale* (scaling, team growth, requirement specifico), non da un'aspirazione architetturale astratta.

---

## Note Aperte e Decisioni Differite

| # | Nota | Target |
|---|---|---|
| 1 | Filtro `is_tecnico=false` nel PDF preventivo: applicare `->visibili()` nelle query stampe | Lavori stampe |
| 2 | `rate_quote.riga_fattura_id` / `voce_id` NULL in v1.9: by design | v1.11 |
| 3 | RID/SDD passivo (addebiti diretti) | v1.16 Riconciliazione |
| 4 | SDI/XML fatturazione elettronica attiva | Post v1.14, on-demand |
| 5 | Pacchetto suggerito per validazione CF/PIVA/IBAN: `italia/codice-fiscale` o equivalente Laravel | Fase A |
| 6 | Pacchetto audit log: `spatie/laravel-activitylog` | Fase A/D |

---

*Documento generato il 13 maggio 2026 — versione 1.1*
*Modifiche rispetto a v1.0: aggiunto versioning regime fiscale, sezione test coverage RC, sezione future-proofing*
*Prossimo aggiornamento: dopo inizio Fase A o nuove indicazioni dell'amministratore*
