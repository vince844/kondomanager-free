# Pagamento Fatture v1.9.1 — Guida di Sviluppo

> **Stato documento:** Specifica architetturale finale, pre-implementazione  
> **Versione target:** Kondomanager v1.9.1 (anticipa v1.10)  
> **Stack:** Laravel + Vue 3 + Inertia + Tailwind + MySQL 8.0.33

---

## 1. Contesto e obiettivi

### 1.1 Cosa stiamo costruendo

Il modulo **Pagamento Fatture** chiude il ciclo passivo: dopo aver registrato la fattura (v1.9), permettiamo all'amministratore di registrare il pagamento, gestendo casi reali della contabilità condominiale italiana — pagamenti parziali, netting con note di credito, controllo liquidità, bonifico parlante per detrazioni fiscali.

### 1.2 Perché v1.9.1 e non v1.10

La registrazione fattura senza pagamento è monca: il sistema "sa" che ci sono debiti aperti ma non li può chiudere. Tutta la dashboard liquidità, gli scadenziari e il rifactor F24 richiedono questo modulo. Anticipare a v1.9.1 sblocca tutto.

### 1.3 Vincoli architetturali fondamentali

- **Partita doppia pura**: niente shortcut, niente colonne ridondanti sulle fatture
- **Paradigma ledger-centric**: il giornale contabile è la verità immutabile, non la fattura. Le validazioni di immutabilità (es. esercizio chiuso) agiscono sulla **scrittura**, non sulla fattura
- **Stato materializzato come read model**: `stato_pagamento` è una cache persistita derivata dalla pivot — documentato come tale, tracciato con `ultimo_ricalcolo_pagamento_at` e `versione_allocazioni`
- **Backed Enums PHP + VARCHAR**: tutto il vocabolario di dominio definito via PHP Backed Enums, persistito come `VARCHAR(50)` con cast Eloquent. Niente `ENUM` MySQL su tabelle che evolveranno (evita ALTER TABLE su tabelle grandi)
- **Domain Exceptions**: niente `\Exception` generiche — eccezioni di dominio specifiche (`OverpaymentException`, `FiscalYearClosedException`, `InsufficientFundsException`, ecc.)
- **Idempotency**: ogni scrittura contabile può avere un `idempotency_key` UUID per retry sicuri (frontend, webhook PSD2 futuri, import bancari)
- **Snapshot anagrafica**: i dati fornitore al momento del pagamento sono congelati in un JSON snapshot per sopravvivere a cambi anagrafici futuri
- **Audit trail completo**: ogni movimento, anche di correzione, è una scrittura in più (mai soft-delete)
- **Importi monetari come `signed BIGINT` cents**: invariante globale di dominio. Range max ~92 quadrilioni € (più che sufficiente)
- **Database invariato**: nessuna nuova istanza, solo migrazioni additive
- **Compatibilità SQLite test**: ogni migration MySQL-specific guardata da `if (DB::getDriverName() === 'mysql')`

---

## 2. Le 4 decisioni architetturali

### Decisione 1 — Quadratura della pivot nel netting: **Opzione B**

Nel caso netting (Fattura 1.000 + NC 200 → bonifico 800):

```
Record 1: fattura_id=42, alloc=800, tipo=pagamento
Record 2: fattura_id=42, alloc=200, tipo=compensazione
Record 3: fattura_id=43 (NC), alloc=200, tipo=compensazione
```

**Invariante d'oro**: `Σ(importo_allocato WHERE tipo='pagamento') = uscita_di_cassa_della_scrittura`

Vantaggi:
- Reportistica "Pagato vs Compensato" diretta
- Storno selettivo del solo bonifico (caso insoluto bancario) lascia la NC compensata
- Audit trail più trasparente per il commercialista

### Decisione 2 — Nome stato: **`parziale`**

ENUM finale di `stato_pagamento` su `fatture_passive`: `aperta | parziale | pagata`.

### Decisione 3 — Meccanismo storno: **scrittura inversa + pivot negativi**

Mai soft-delete. Lo storno è una **nuova scrittura contabile** con DARE/AVERE invertiti rispetto all'originale, e nuovi record pivot con `importo_allocato` negativo.

Esempio:
```
Pagamento (scrittura #100):
  pivot: fattura_id=42, alloc=+800, tipo='pagamento'

Storno (scrittura #150 → linkata a #100):
  pivot: fattura_id=42, alloc=-800, tipo='pagamento'
  
Risultato: SUM(allocato) = 0 → fattura torna 'aperta'
```

### Decisione 4 — Comando reconcile da subito

`php artisan kondomanager:fatture-ricalcola-stati` — safety net per casi di dirty data, eseguibile post-migration, post-restore, post-debug.

### Decisione 5 — Esercizio chiuso: validazione ledger-centric

La validazione di "esercizio chiuso" agisce **sull'esercizio della scrittura contabile che si sta creando**, NON sull'esercizio della fattura.

Questo è il paradigma ledger-centric: il giornale è immutabile, la fattura no.

Esempio legittimo:
- Fattura: `esercizio_id = 2025` (competenza)
- Esercizio 2025 chiuso a febbraio 2026
- A marzo 2026 arriva ricevuta bonifico → scrittura pagamento con `esercizio_id = 2026`
- ✅ **Consentito**: stiamo registrando nel ledger aperto 2026, non toccando il 2025 chiuso

L'eccezione si lancia se e solo se l'utente prova a creare una scrittura **dentro** un esercizio chiuso (operazione che violerebbe l'immutabilità del giornale già consolidato).

→ `FiscalYearClosedException` sempre su `$data['esercizio_id']`, mai su `$fattura->esercizio_id`.

### Decisione 6 — Overpayment è blocco, non warning

Se `totale_allocato > netto_a_pagare`, il sistema **lancia `OverpaymentException`** e fa rollback.

Bypassabile solo con flag esplicito `allow_overpayment: true` nell'input, che richiede conferma utente nella UI e viene loggato nell'audit.

Il pagamento oltre il dovuto **non è mai accidentale**: significa duplicazione, errore operatore, anticipo non registrato, o NC mancante. Tutti casi che meritano un blocco esplicito.

### Decisione 7 — Backed Enums PHP + VARCHAR, non ENUM MySQL

Tutti gli enum di dominio sono **PHP Backed Enums**, persistiti come `VARCHAR(50)` con cast Eloquent. Niente `ENUM` MySQL su tabelle critiche.

Razionale:
- Il dominio evolverà molto (v1.12 aggiunge causali CU/codici tributo, v1.16 metodi RID/SDD, ecc.)
- ALTER TABLE su `ENUM` di tabelle grandi è lento e rischioso
- Backed Enums portano type-safety, metodi helper, refactoring sicuro

Vedi sezione 4.5 per i Backed Enums definiti.

---

## 3. Casi d'uso supportati in MVP

| # | Caso | Esempio |
|---|------|---------|
| 1 | Pagamento totale fattura singola | Pago FT-123 da 500€ |
| 2 | Pagamento parziale | FT 1.000€, pago 400€, residuo 600€ |
| 3 | Bonifico cumulativo | Pago 5 fatture stesso fornitore con 1 bonifico |
| 4 | Netting fattura + NC | FT 1.000 + NC 200 → bonifico 800 |
| 5 | Pagamenti multipli su stessa fattura | 3 pagamenti parziali successivi |
| 6 | Bonifico parlante detrazione fiscale | Lavori straordinari ristrutturazione |
| 7 | Storno pagamento (insoluto) | Bonifico tornato indietro |
| 8 | Pagamento contanti < 5.000€ | Cassa contanti per piccola spesa |
| 9 | Pagamento con commissioni bancarie | SEPA estero con 5€ commissione |
| 10 | Controllo capienza pre-conferma | Avviso se importo > saldo conto |

**Casi rimandati a v1.9.2**: acconti, anticipi amministratore, assegni, RID/SDD, NC > Fattura, data_valuta separata, abbuoni passivi.

---

## 4. Schema database

### 4.1 Estensione tabella esistente `fattura_scrittura`

La pivot esiste già dal v1.9 ciclo passivo. Verifichiamo/aggiungiamo:

```sql
fattura_scrittura
├── id                       BIGINT UNSIGNED AUTO_INCREMENT
├── fattura_passiva_id       BIGINT UNSIGNED FK → fatture_passive
├── scrittura_contabile_id   BIGINT UNSIGNED FK → scritture_contabili
├── tipo                     ENUM('competenza','pagamento','compensazione') NOT NULL
├── importo_allocato         BIGINT NOT NULL  -- centesimi, può essere NEGATIVO
├── created_at, updated_at
├── INDEX(fattura_passiva_id, tipo)
└── INDEX(scrittura_contabile_id)
```

**Note:**
- `importo_allocato` è `BIGINT` signed (non unsigned!) per supportare gli storni
- L'ENUM `tipo` deve includere `compensazione` (verifica nello schema attuale)

### 4.2 Nuova tabella `pagamenti_fornitori`

Estende la `scrittura_contabile` con metadati documentali del pagamento:

```sql
pagamenti_fornitori
├── id                           BIGINT UNSIGNED AUTO_INCREMENT
├── uuid                         CHAR(36) UNIQUE NOT NULL    -- ID pubblico (privacy/export/PSD2/webhook)
├── scrittura_contabile_id       BIGINT UNSIGNED FK UNIQUE  -- 1:1 vincolante
├── fornitore_id                 BIGINT UNSIGNED FK
├── conto_corrente_id            BIGINT UNSIGNED FK → conti_contabili (banca/cassa)
├── 
├── data_pagamento               DATE NOT NULL
├── metodo_pagamento             VARCHAR(50) NOT NULL    -- cast MetodoPagamento::class
├── iban_destinatario            VARCHAR(34) NULL        -- snapshot dalla fattura
├── causale_bonifico             TEXT                    -- auto-generata, modificabile
├── riferimento_bancario         VARCHAR(50) NULL        -- CRO/TRN bonifico
├── 
├── -- Snapshot anagrafica fornitore (audit fiscale storico)
├── fornitore_snapshot           JSON NOT NULL           -- {ragione_sociale,piva,cf,iban,indirizzo}
├── 
├── -- Bonifico Parlante (detrazioni fiscali)
├── bonifico_parlante            BOOLEAN DEFAULT false
├── tipo_detrazione              VARCHAR(50) NULL        -- cast TipoDetrazione::class
├── beneficiari_detrazione       JSON NULL               -- {schema_version, metodo, tabella_base, beneficiari[...], motivo_override}
├── 
├── importo_commissioni          BIGINT DEFAULT 0        -- centesimi
├── 
├── -- Storno
├── stato                        VARCHAR(50) DEFAULT 'confermato'  -- cast StatoPagamentoFornitore::class
├── scrittura_storno_id          BIGINT UNSIGNED NULL FK → scritture_contabili
├── motivo_storno                TEXT NULL
├── 
├── -- Audit
├── user_id                      BIGINT UNSIGNED FK → users
├── created_at, updated_at
├── INDEX(fornitore_id, data_pagamento)
└── INDEX(stato)
```

### 4.2bis Estensioni alla tabella `scritture_contabili`

Aggiungere alle migration:

```sql
scritture_contabili (modifiche additive)
├── idempotency_key              CHAR(36) NULL UNIQUE    -- UUID per retry/import/webhook
└── -- tipo_movimento: NESSUN ALTER ENUM. Cambiarlo in VARCHAR(50) in migration dedicata
                                 -- cast: TipoMovimentoContabile::class
```

### 4.2ter Estensioni alla tabella `fatture_passive`

Aggiungere alle migration:

```sql
fatture_passive (modifiche additive)
├── ultimo_ricalcolo_pagamento_at  TIMESTAMP NULL          -- audit del read model
├── versione_allocazioni            BIGINT UNSIGNED DEFAULT 0  -- change counter
├── inconsistenza_pagamento         BOOLEAN DEFAULT FALSE      -- flag detection anomalia
└── ultimo_errore_ricalcolo         TEXT NULL                  -- descrizione anomalia
```

**Cosa è (e cosa NON è) `versione_allocazioni`:**

- ✅ **Change counter**: incrementato ad ogni pivot insert/update/storno
- ✅ **Cache invalidation token**: per dashboard, projections future, sync realtime
- ✅ **Debug aid**: nel reconcile aiuta a capire quante mutazioni ha avuto una fattura
- ❌ **NON è optimistic concurrency control**: per esserlo davvero servirebbe `WHERE versione_allocazioni = X` in tutte le UPDATE sensibili, con retry su mismatch. Non lo implementiamo in v1.9.1 (la pessimistic lock con `lockForUpdate` copre già la concorrenza)

Se in futuro servirà vero optimistic locking (es. UI multi-utente che modifica simultaneamente la stessa fattura), questa colonna è già pronta per essere usata in quel modo. Per ora resta come **change counter / cache invalidation token**.

Queste colonne documentano che `stato_pagamento` è un **read model materializzato**, non una colonna autoritativa. Aiutano debug, cache invalidation e future projections.

### 4.3 Conti contabili richiesti nel piano

Verificare che il PianoContiSeeder generi questi conti standard per ogni condominio:

- **Banca** (patrimoniale attivo, ruolo='cassa_liquida')
- **Cassa Contanti** (patrimoniale attivo, ruolo='cassa_liquida')
- **Debiti v/Fornitori** (patrimoniale passivo, ruolo='debiti_fornitori')
- **Spese Bancarie** (economico costo, per commissioni)

### 4.4 Backed Enums PHP (vocabolario di dominio)

Tutto il dominio enumerativo è definito come **PHP 8.1+ Backed Enum** in `app/Enums/`. Niente `ENUM` MySQL: persistenza come `VARCHAR(50)` con cast Eloquent.

#### `TipoMovimentoContabile`

Il più importante. Progettato per scalare fino a v1.16 senza ALTER TABLE.

```php
namespace App\Enums;

enum TipoMovimentoContabile: string
{
    // Ciclo passivo (v1.9 + v1.9.1)
    case FATTURA_ACQUISTO              = 'fattura_acquisto';
    case PAGAMENTO_FORNITORE           = 'pagamento_fornitore';
    case STORNO_PAGAMENTO_FORNITORE    = 'storno_pagamento_fornitore';
    
    // Ciclo attivo
    case INCASSO_RATA                  = 'incasso_rata';
    case EMISSIONE_RATA                = 'emissione_rata';
    
    // Movimenti tecnici
    case GIROCONTO                     = 'giroconto';
    case RETTIFICA                     = 'rettifica';
    case APERTURA                      = 'apertura';
    case CHIUSURA                      = 'chiusura';
    
    // Futuri (v1.10+)
    case ACCANTONAMENTO                = 'accantonamento';
    case RIPARTO                       = 'riparto';
    case F24                           = 'f24';
    case RICONCILIAZIONE_BANCARIA      = 'riconciliazione_bancaria';
}
```

#### `MetodoPagamento`

```php
enum MetodoPagamento: string
{
    case BONIFICO = 'bonifico';
    case CONTANTI = 'contanti';
    case ASSEGNO  = 'assegno';
    case RID_SDD  = 'rid_sdd';
    case ALTRO    = 'altro';
}
```

#### `StatoPagamentoFattura`

```php
enum StatoPagamentoFattura: string
{
    case APERTA   = 'aperta';
    case PARZIALE = 'parziale';
    case PAGATA   = 'pagata';
}
```

**Volutamente minimale**: stati come `scaduta`, `in_contenzioso`, `sospesa` sono di workflow, non di saldo, e vivono in altri campi.

#### `StatoPagamentoFornitore`

```php
enum StatoPagamentoFornitore: string
{
    case CONFERMATO = 'confermato';
    case STORNATO   = 'stornato';
}
```

#### `TipoAllocazioneFattura`

Il `tipo` della pivot `fattura_scrittura`.

```php
enum TipoAllocazioneFattura: string
{
    case COMPETENZA      = 'competenza';
    case PAGAMENTO       = 'pagamento';
    case COMPENSAZIONE   = 'compensazione';
    
    // Future: ACCONTO, ABBUONO, RITENUTA
}
```

#### `TipoDetrazione`

Con metodo helper integrato per evitare switch sparsi nel codice.

```php
enum TipoDetrazione: string
{
    case RISTRUTTURAZIONE = 'ristrutturazione';
    case ECOBONUS         = 'ecobonus';
    case SISMABONUS       = 'sismabonus';
    case SUPERBONUS       = 'superbonus';
    case ALTRO            = 'altro';

    public function riferimentoNormativo(): string
    {
        return match($this) {
            self::RISTRUTTURAZIONE => 'art. 16-bis DPR 917/1986',
            self::ECOBONUS         => 'art. 14 DL 63/2013',
            self::SISMABONUS       => 'art. 16 DL 63/2013',
            self::SUPERBONUS       => 'art. 119 DL 34/2020',
            self::ALTRO            => 'Normativa specifica',
        };
    }
    
    public function descrizione(): string
    {
        return match($this) {
            self::RISTRUTTURAZIONE => 'Ristrutturazione edilizia',
            self::ECOBONUS         => 'Ecobonus / Riqualificazione energetica',
            self::SISMABONUS       => 'Sismabonus',
            self::SUPERBONUS       => 'Superbonus',
            self::ALTRO            => 'Altra detrazione',
        };
    }
}
```

#### Strategia di migration per `tipo_movimento`

L'attuale `scritture_contabili.tipo_movimento` è probabilmente `ENUM(...)`. Migration di conversione:

```php
if (DB::getDriverName() === 'mysql') {
    Schema::table('scritture_contabili', function (Blueprint $t) {
        $t->string('tipo_movimento', 50)->change();
    });
}
```

Da qui in avanti l'enum vive in PHP, il DB è agnostico, gli ALTER ENUM scompaiono dal radar.

---

## 5. Modelli Eloquent

### 5.1 `PagamentoFornitore`

```php
class PagamentoFornitore extends Model
{
    protected $casts = [
        'data_pagamento' => 'date',
        'bonifico_parlante' => 'boolean',
        'beneficiari_detrazione' => 'array',
        'importo_commissioni' => 'integer',
    ];

    // Relazioni
    public function scrittura()       => belongsTo(ScritturaContabile::class);
    public function scritturaStorno() => belongsTo(ScritturaContabile::class, 'scrittura_storno_id');
    public function fornitore()       => belongsTo(Fornitore::class);
    public function contoCorrente()   => belongsTo(ContoContabile::class, 'conto_corrente_id');
    public function user()            => belongsTo(User::class);
    public function documenti()       => morphMany(Documento::class, 'documentable');
    
    // Fatture toccate (attraverso pivot)
    public function fatture()
    {
        return $this->scrittura->fatture(); // delegato
    }
    
    // Scope
    public function scopeConfermati($q) => $q->where('stato', 'confermato');
}
```

### 5.2 `FatturaPassiva` (estensione)

```php
class FatturaPassiva extends Model
{
    // Relazione belongsToMany via pivot
    public function scritture()
    {
        return $this->belongsToMany(ScritturaContabile::class, 'fattura_scrittura')
            ->using(FatturaScrittura::class)
            ->withPivot('tipo', 'importo_allocato')
            ->withTimestamps();
    }

    // Accessor: somma allocata SOLO da pagamenti e compensazioni.
    // ESCLUDE tipo='competenza' che rappresenta la registrazione iniziale della fattura
    // (il debito che si sta chiudendo, non un'allocazione di pagamento).
    public function getTotaleAllocatoAttribute(): int
    {
        return (int) $this->scritture()
            ->wherePivotIn('tipo', [
                TipoAllocazioneFattura::PAGAMENTO->value,
                TipoAllocazioneFattura::COMPENSAZIONE->value,
            ])
            ->sum('fattura_scrittura.importo_allocato');
    }

    // Accessor: residuo
    public function getResiduoAttribute(): int
    {
        return $this->netto_a_pagare - $this->totale_allocato;
    }

    // Scope per liste operative
    public function scopeAperte($q)         => $q->where('stato_pagamento', 'aperta');
    public function scopeConResiduo($q)     => $q->whereIn('stato_pagamento', ['aperta','parziale']);
    public function scopePagabili($q)       => $q->where('stato_approvazione', 'approvata')
                                                  ->whereIn('stato_pagamento', ['aperta','parziale']);
}
```

> **⚠️ Invariante critica del calcolo allocazione**
>
> Il `totale_allocato` su una fattura **NON deve mai includere** le righe pivot con `tipo='competenza'`. Quel tipo rappresenta la registrazione contabile del debito al momento della fattura (è la fattura stessa che entra nel ledger), non un movimento di chiusura.
>
> Ogni `SUM(importo_allocato)` nel codice del modulo DEVE filtrare:
> ```sql
> WHERE tipo IN ('pagamento', 'compensazione')
> ```
> 
> Questa invariante vale in: `getTotaleAllocatoAttribute`, `ricalcolaStatoFattura`, `RicalcolaStatiFatture` (comando reconcile), eventuali report o dashboard di residui.

### 5.3 `FatturaScrittura` (Pivot Model)

```php
class FatturaScrittura extends Pivot
{
    public $incrementing = true;
    
    protected $casts = [
        'importo_allocato' => 'integer',
    ];
    
    // Validazione: tipo deve matchare il tipo_movimento della scrittura?
    // No, lasciamo flessibilità — la coerenza è imposta dal service
}
```

---

## 6. `PagamentoFornitoreService`

### 6.1 Interfaccia pubblica

```php
class PagamentoFornitoreService
{
    public function registraPagamento(array $data): PagamentoFornitore;
    public function stornaPagamento(PagamentoFornitore $p, string $motivo): PagamentoFornitore;
    public function ricalcolaStatoFattura(FatturaPassiva $f): void;
    public function generaCausaleBonifico(FatturaPassiva $f, bool $parlante = false): string;
    public function trovaNoteCreditoCompensabili(int $fornitore_id, int $condominio_id): Collection;
    public function verificaCapienza(int $conto_id, int $importo_cents): array;
}
```

### 6.2 `registraPagamento` — algoritmo dettagliato

**Input atteso:**

```php
[
    'fornitore_id' => 12,
    'condominio_id' => 5,
    'esercizio_id' => 8,
    'conto_corrente_id' => 33,
    'data_pagamento' => '2026-05-10',
    'metodo_pagamento' => 'bonifico',
    'iban_destinatario' => 'IT60X0542811101000000123456',
    'allocazioni' => [
        ['fattura_id' => 42, 'tipo' => 'pagamento', 'importo_allocato_cents' => 80000],
        ['fattura_id' => 42, 'tipo' => 'compensazione', 'importo_allocato_cents' => 20000],
        ['fattura_id' => 43, 'tipo' => 'compensazione', 'importo_allocato_cents' => 20000],
    ],
    'importo_commissioni_cents' => 0,
    'bonifico_parlante' => false,
    'tipo_detrazione' => null,
    'beneficiari_detrazione' => null,
    'causale_bonifico' => null, // null → autogenerata
]
```

**Algoritmo:**

```
DB::transaction(function () use ($data) {

    // 0. LOCK PESSIMISTICO SU FATTURE (anti race-condition + anti-deadlock)
    //    Acquisisce SELECT ... FOR UPDATE su tutte le fatture toccate.
    //    Impedisce che due richieste concorrenti calcolino il residuo sullo stesso valore.
    //    
    //    IMPORTANTE: orderBy('id') prima di lockForUpdate() per LOCK ORDERING DETERMINISTICO.
    //    Senza ordinamento esplicito, due transazioni che lockano le stesse fatture
    //    in ordine inverso causano deadlock MySQL.
    $fattureIds = collect($data['allocazioni'])
        ->pluck('fattura_id')->unique()->sort()->values();
        
    $fatture = FatturaPassiva::whereIn('id', $fattureIds)
        ->orderBy('id')           // <-- CRITICO: ordine deterministico
        ->lockForUpdate()
        ->get()
        ->keyBy('id');

    // 1. VALIDAZIONI PRELIMINARI (sui dati ora blindati dal lock)
    //    - Esercizio scrittura aperto (NON l'esercizio della fattura, principio ledger-centric)
    //    - Tutte le fatture stesso fornitore, stesso condominio
    //    - Tutte stato_approvazione='approvata'
    //    - Σ allocato per fattura ≤ residuo della fattura  → altrimenti OverpaymentException
    //    - Antiriciclaggio: contanti ≥ 5000 → IllegalCashAmountException
    //    - Capienza conto: se sotto-zero senza allow_overdraft → InsufficientFundsException
    //    - Idempotency: se idempotency_key già esistente, restituisce pagamento esistente
    $this->validaInput($data, $fatture);

    // 2. CHECK IDEMPOTENCY (early return)
    //    Pattern production-grade: check + try/catch su UNIQUE violation.
    //    Senza il try/catch, due request simultanee con stessa key passano entrambe il SELECT
    //    prima dell'INSERT, e la seconda esplode su violazione UNIQUE.
    if ($key = $data['idempotency_key'] ?? null) {
        $esistente = ScritturaContabile::where('idempotency_key', $key)->first();
        if ($esistente) {
            return $esistente->pagamentoFornitore;
        }
    }

    // 3. CALCOLO IMPORTI CHIAVE
    //    Per ogni allocazione: classifica per TIPO_DOCUMENTO della fattura puntata.
    //    Una fattura è un DEBITO (DARE per chiudere); una NC è un CREDITO (AVERE per consumare).
    $totalePagamento = 0;       // somma allocato tipo='pagamento' (sempre cash out)
    $totaleSuFatture = 0;       // somma allocato (qualsiasi tipo) su righe pivot che puntano a fatture
    $totaleSuNC = 0;            // somma allocato (qualsiasi tipo) su righe pivot che puntano a NC
    
    foreach ($data['allocazioni'] as $alloc) {
        $fattura = $fatture[$alloc['fattura_id']];
        $importo = $alloc['importo_allocato_cents'];
        
        if ($alloc['tipo'] === 'pagamento') {
            $totalePagamento += $importo;
        }
        
        if ($fattura->tipo_documento === 'fattura') {
            $totaleSuFatture += $importo;
        } else { // nota_credito
            $totaleSuNC += $importo;
        }
    }
    
    $uscitaCassa = $totalePagamento + $data['importo_commissioni_cents'];

    // 4. CREA SCRITTURA CONTABILE
    //    Convenzione modulo: `scritture_contabili.importo` per pagamenti = USCITA DI CASSA,
    //    NON il totale movimentato (che nel netting differisce dalla cassa).
    //    Per le metriche contabili autoritative usare SEMPRE: SUM(righe_scrittura.importo)
    //    raggruppato per conto. La colonna `importo` di testata è un'etichetta di magnitude,
    //    non la fonte di verità contabile. Documentato anche in sezione 7.5.
    $scrittura = ScritturaContabile::create([
        'condominio_id'     => $data['condominio_id'],
        'esercizio_id'      => $data['esercizio_id'],  // LEDGER (non quello della fattura)
        'tipo_movimento'    => TipoMovimentoContabile::PAGAMENTO_FORNITORE,
        'data_registrazione'=> $data['data_pagamento'],
        'importo'           => $uscitaCassa,            // cash out (vedi convenzione sopra)
        'descrizione'       => "Pagamento fornitore #{$data['fornitore_id']}",
        'idempotency_key'   => $data['idempotency_key'] ?? Str::uuid()->toString(),
    ]);

    // 5. CREA RIGHE SCRITTURA (PARTITA DOPPIA CORRETTA — anche per netting)
    //
    //    Regola di quadratura:
    //    Lato DARE:
    //      - Debiti v/Fornitori = totaleSuFatture     (chiusura debito generato dalle FT)
    //      - Spese Bancarie     = commissioni         (se > 0)
    //    Lato AVERE:
    //      - Banca              = uscitaCassa         (pagamento + commissioni)
    //      - Debiti v/Fornitori = totaleSuNC          (consumo del credito generato dalle NC)
    //
    //    Verifica: DARE = totaleSuFatture + commissioni
    //             AVERE = uscitaCassa + totaleSuNC = totalePagamento + commissioni + totaleSuNC
    //    Quadra perché: totaleSuFatture = totalePagamento + (compensazioni allocate su FT)
    //                  totaleSuNC      = compensazioni allocate su NC = compensazioni su FT
    //                  → DARE - AVERE = 0  ✓
    
    // DARE Debiti v/Fornitori (chiude debito dalle fatture)
    if ($totaleSuFatture > 0) {
        $scrittura->righe()->create([
            'conto_contabile_id' => $contoDebiti->id,
            'tipo_riga'          => 'dare',
            'importo'            => $totaleSuFatture,
        ]);
    }
    
    // DARE Spese Bancarie (commissioni)
    if ($data['importo_commissioni_cents'] > 0) {
        $scrittura->righe()->create([
            'conto_contabile_id' => $contoSpeseBancarie->id,
            'tipo_riga'          => 'dare',
            'importo'            => $data['importo_commissioni_cents'],
        ]);
    }
    
    // AVERE Banca (uscita cash + commissioni)
    if ($uscitaCassa > 0) {
        $scrittura->righe()->create([
            'conto_contabile_id' => $data['conto_corrente_id'],
            'tipo_riga'          => 'avere',
            'importo'            => $uscitaCassa,
        ]);
    }
    
    // AVERE Debiti v/Fornitori (consumo credito dalle NC)
    if ($totaleSuNC > 0) {
        $scrittura->righe()->create([
            'conto_contabile_id' => $contoDebiti->id,
            'tipo_riga'          => 'avere',
            'importo'            => $totaleSuNC,
        ]);
    }
    
    // Verifica programmatica della quadratura (fail-fast)
    $this->verificaQuadraturaScrittura($scrittura);

    // 6. CREA RECORD PIVOT + INCREMENTA versione_allocazioni
    foreach ($data['allocazioni'] as $alloc) {
        FatturaScrittura::create([
            'fattura_passiva_id'       => $alloc['fattura_id'],
            'scrittura_contabile_id'   => $scrittura->id,
            'tipo'                     => TipoAllocazioneFattura::from($alloc['tipo']),
            'importo_allocato'         => $alloc['importo_allocato_cents'],
        ]);
    }
    
    // Increment optimistic-concurrency counter su tutte le fatture toccate
    FatturaPassiva::whereIn('id', $fattureIds)->increment('versione_allocazioni');

    // 7. CREA RECORD PAGAMENTO FORNITORE (1:1 con scrittura) + SNAPSHOT FORNITORE
    $fornitore = Fornitore::findOrFail($data['fornitore_id']);
    
    $pagamento = PagamentoFornitore::create([
        'scrittura_contabile_id' => $scrittura->id,
        'fornitore_id'           => $data['fornitore_id'],
        'conto_corrente_id'      => $data['conto_corrente_id'],
        'data_pagamento'         => $data['data_pagamento'],
        'metodo_pagamento'       => MetodoPagamento::from($data['metodo_pagamento']),
        'iban_destinatario'      => $data['iban_destinatario'] ?? null,
        'causale_bonifico'       => $data['causale_bonifico'] 
            ?? $this->generaCausaleBonifico(/* ... */),
        
        // SNAPSHOT immutabile per audit fiscale storico (versionato per evoluzione)
        'fornitore_snapshot'     => [
            'schema_version'  => 1,
            'snapshot_at'     => now()->toIso8601String(),
            'ragione_sociale' => $fornitore->ragione_sociale,
            'partita_iva'     => $fornitore->partita_iva,
            'codice_fiscale'  => $fornitore->codice_fiscale,
            'iban'            => $fornitore->iban,
            'indirizzo'       => $fornitore->indirizzo,
            // In v1.12 (DNA Fiscale) lo schema evolverà a v2 con:
            //   pec, regime_fiscale, split_payment, reverse_charge, nazione_iso
            // Il versioning permette di leggere snapshot vecchi senza migrazione dati.
        ],
        
        'bonifico_parlante'      => $data['bonifico_parlante'] ?? false,
        'tipo_detrazione'        => $data['tipo_detrazione'] 
            ? TipoDetrazione::from($data['tipo_detrazione']) 
            : null,
        'beneficiari_detrazione' => $data['beneficiari_detrazione'] ?? null,
        'importo_commissioni'    => $data['importo_commissioni_cents'] ?? 0,
        'stato'                  => StatoPagamentoFornitore::CONFERMATO,
        'user_id'                => auth()->id(),
    ]);

    // 8. RICALCOLA STATO FATTURE TOCCATE
    foreach ($fatture as $fattura) {
        $this->ricalcolaStatoFattura($fattura);
    }

    // 9. EVENTI (dispatched AFTER commit per non tenere il lock durante listener pesanti)
    event(new PagamentoRegistrato($pagamento));

    return $pagamento;
});
```

**Note operative sulla concorrenza:**

- **Eventi dopo il commit**: i listener (es. `InvalidaLiquidityForecastCache`) possono essere pesanti. Per non tenere il lock pessimistico più del necessario, usare `event()->afterCommit()` di Laravel oppure listener `ShouldQueue` con `public bool $afterCommit = true;`. Il pagamento è già committato quando l'evento parte.
- **Gestione deadlock**: se due transazioni acquisiscono lock su fatture diverse in ordine inverso, MySQL fa rollback automatico di una. L'app può fare retry con back-off (1-3 tentativi su `Illuminate\Database\DeadlockException`). Per MVP non lo implementiamo — singolo utente, rischio basso — ma vale la pena saperlo per il futuro multi-utente.
- **Lock sul conto corrente**: NON serve. Il saldo conto è un aggregato di scritture, non un valore singolo. La `verificaCapienza` è un check UX, non un constraint hard. Bloccare il conto sarebbe over-engineering.
- **⚠️ SQLite e `lockForUpdate()`**: SQLite **ignora silenziosamente** `lockForUpdate()` perché ha un modello di locking diverso (file-level lock). **Tutti i test di concorrenza scritti contro SQLite in-memory sono falsi positivi** sulla correttezza del lock pessimistico. La concurrency correctness va verificata o:
   - In test dedicati contro MySQL (CI tier separato o `@requires mysql`)
   - Manualmente in staging con due tab aperte
   - Tramite property-based test che simula la race condition (race scheduling forzato in PHP, vedi sez. 13.6)
   
   I test SQLite normali restano validi per la *logica* del modulo (quadratura, stato_pagamento, storno) ma non per la *concorrenza*. Documentare questa limitazione esplicitamente nel README dei test.

**Wrapper esterno per race-condition su `idempotency_key`** (PRODUCTION-GRADE):

Il check idempotency interno (step 2 dell'algoritmo) non basta da solo. Due request simultanee con la stessa key possono passare entrambe il SELECT prima che una completi l'INSERT, e la seconda esplode sulla UNIQUE constraint.

Il pattern completo wrappa l'intera chiamata:

```php
public function registraPagamento(array $data): PagamentoFornitore
{
    try {
        return DB::transaction(function () use ($data) {
            // ... algoritmo completo (step 0-9) ...
        });
    } catch (QueryException $e) {
        // Se l'errore è un UNIQUE violation su idempotency_key,
        // significa che un'altra request ha vinto la corsa: rifai il lookup.
        if ($this->isIdempotencyKeyViolation($e, $data['idempotency_key'] ?? null)) {
            $esistente = ScritturaContabile::where(
                'idempotency_key', 
                $data['idempotency_key']
            )->firstOrFail();
            return $esistente->pagamentoFornitore;
        }
        throw $e;
    }
}

private function isIdempotencyKeyViolation(QueryException $e, ?string $key): bool
{
    if (! $key) return false;
    // MySQL: errno 1062 = duplicate entry for key
    // SQLite: SQLSTATE 23000 con stringa 'UNIQUE constraint failed'
    return str_contains($e->getMessage(), 'idempotency_key')
        && (($e->errorInfo[1] ?? null) === 1062 || ($e->errorInfo[0] ?? null) === '23000');
}
```

Questo rende il check idempotency veramente production-grade contro race condition.

### 6.3 `stornaPagamento` — algoritmo (con supporto storno cross-esercizio Variante B1)

**Firma:**

```php
public function stornaPagamento(
    PagamentoFornitore $pagamento,
    string $motivo,
    ?int $esercizioId = null   // opzionale: se non passato, calcolato automaticamente
): PagamentoFornitore
```

**Logica di scelta esercizio (paradigma ledger-centric):**

```
Se esercizio originale APERTO:
   → storno usa $esercizioOriginale (simmetria perfetta DARE/AVERE)
   
Se esercizio originale CHIUSO:
   → storno usa $esercizioId (se passato) oppure l'esercizio corrente aperto del condominio
   → flag storno_cross_esercizio = true
   → causale arricchita con riferimento all'esercizio originale
```

**Algoritmo:**

```
try {
    DB::transaction(function () use ($pagamento, $motivo, $esercizioId) {
        
        // 1. CHECK STATO
        if ($pagamento->stato === StatoPagamentoFornitore::STORNATO) {
            throw new PagamentoGiaStornatoException(
                "Pagamento #{$pagamento->id} risulta già stornato."
            );
        }

        $scritturaOriginale = $pagamento->scrittura;
        $esercizioOriginale = $scritturaOriginale->esercizio;
        
        // 2. SCELTA ESERCIZIO TARGET (Variante B1)
        $crossEsercizio = false;
        
        if ($esercizioOriginale->is_closed) {
            // Cross-esercizio: cerca esercizio aperto target
            $esercizioTarget = $esercizioId
                ? Esercizio::findOrFail($esercizioId)
                : $this->trovaEsercizioCorrenteAperto($scritturaOriginale->condominio_id);
            
            if ($esercizioTarget->is_closed) {
                throw new FiscalYearClosedException(
                    "Nessun esercizio aperto disponibile per lo storno cross-esercizio."
                );
            }
            
            $crossEsercizio = true;
        } else {
            // Storno normale: stesso esercizio dell'originale
            $esercizioTarget = $esercizioOriginale;
        }

        // 3. CAUSALE ARRICCHITA SE CROSS-ESERCIZIO
        $causaleStorno = $crossEsercizio
            ? "Storno pagamento #{$pagamento->id} per {$motivo}. " .
              "Pagamento originario registrato nell'esercizio {$esercizioOriginale->nome}."
            : "Storno pagamento #{$pagamento->id} — Motivo: {$motivo}";

        // 4. CREA SCRITTURA INVERSA (nell'esercizio target)
        $scritturaStorno = ScritturaContabile::create([
            'condominio_id'      => $scritturaOriginale->condominio_id,
            'esercizio_id'       => $esercizioTarget->id,   // ← esercizio scelto sopra
            'tipo_movimento'     => TipoMovimentoContabile::STORNO_PAGAMENTO_FORNITORE,
            'data_registrazione' => now()->toDateString(),
            'importo'            => $scritturaOriginale->importo,
            'descrizione'        => $causaleStorno,
            'idempotency_key'    => Str::uuid()->toString(),
        ]);

        // 5. RIGHE INVERSE (DARE ↔ AVERE)
        //    Variante B1: nello storno cross-esercizio, l'effetto è "riapertura debito fornitore"
        //    nell'esercizio corrente. La fattura torna in stato 'aperta'/'parziale'
        //    e riappare nel workflow operativo dell'admin.
        foreach ($scritturaOriginale->righe as $riga) {
            $scritturaStorno->righe()->create([
                'conto_contabile_id' => $riga->conto_contabile_id,
                'tipo_riga'          => $riga->tipo_riga === 'dare' ? 'avere' : 'dare',
                'importo'            => $riga->importo,
            ]);
        }

        // 6. PIVOT NEGATIVI (mirror dell'originale)
        foreach ($scritturaOriginale->righePivot as $rigaPivot) {
            FatturaScrittura::create([
                'fattura_passiva_id'     => $rigaPivot->fattura_passiva_id,
                'scrittura_contabile_id' => $scritturaStorno->id,
                'tipo'                   => $rigaPivot->tipo,
                'importo_allocato'       => -$rigaPivot->importo_allocato, // NEGATIVO
            ]);
        }

        // 7. AGGIORNA PAGAMENTO ORIGINALE
        $pagamento->update([
            'stato'                  => StatoPagamentoFornitore::STORNATO,
            'scrittura_storno_id'    => $scritturaStorno->id,
            'motivo_storno'          => $motivo,
            'storno_cross_esercizio' => $crossEsercizio,
            'esercizio_storno_id'    => $esercizioTarget->id,
        ]);

        // 8. INCREMENT versione_allocazioni + RICALCOLA STATO FATTURE
        FatturaPassiva::whereIn('id', $pagamento->fatture->pluck('id'))
            ->increment('versione_allocazioni');
        
        foreach ($pagamento->fatture as $f) {
            $this->ricalcolaStatoFattura($f);  // robusto (non lancia eccezioni)
        }

        // 9. EVENTI
        event(new PagamentoStornato($pagamento, $motivo, $crossEsercizio));

        return $pagamento->fresh();
    });
} catch (QueryException $e) {
    // Idempotency safety net come in registraPagamento
    throw $e;
}
```

**Modifiche schema per supportare cross-esercizio:**

```sql
pagamenti_fornitori (aggiungere)
├── storno_cross_esercizio   BOOLEAN DEFAULT FALSE
└── esercizio_storno_id      BIGINT UNSIGNED NULL FK → esercizi
```

**Perché Variante B1 (riapertura debito) e non B2 (sopravvenienze pure):**

- ✅ La fattura torna in stato `aperta`/`parziale` → riappare automaticamente in Liquidity Forecast, scadenziario, lista pagamenti pendenti
- ✅ Workflow operativo naturale: l'admin riprova il pagamento come se l'insoluto fosse un evento corrente
- ✅ Niente nuovi conti contabili da introdurre (no "Sopravvenienze attive/passive" nel piano standard)
- ✅ Compatibilità totale con tutti i meccanismi esistenti
- ⚠️ Pragmaticamente non purissimo dal punto di vista civilistico: il debito riappare nell'esercizio corrente "come se fosse nascente lì". Per condomini è il trade-off corretto. Sistemi enterprise IFRS (B2 con sopravvenienze) si possono valutare in v1.17 (Reporting Suite) come modalità opzionale.

**Causale automatica esempio:**

> "Storno pagamento #123 per insoluto bancario. Pagamento originario registrato nell'esercizio 2025."

Questo permette all'admin (e al revisore) di capire immediatamente la natura dell'evento.

### 6.4 `ricalcolaStatoFattura`

**Principio architetturale**: questa funzione è **detection sistemica**, non validazione runtime. Deve essere **robusta** contro dirty data — non interrompere il reconcile al primo problema. Le eccezioni di violazione (`OverpaymentException`) vivono SOLO in `validaInput()`, eseguito *prima* della creazione pivot.

```php
public function ricalcolaStatoFattura(FatturaPassiva $fattura): void
{
    // FILTRO CRITICO: solo pagamento e compensazione, MAI competenza.
    // La riga di competenza è la registrazione iniziale della fattura (il debito stesso),
    // non un'allocazione che chiude il debito.
    $totale = (int) DB::table('fattura_scrittura')
        ->where('fattura_passiva_id', $fattura->id)
        ->whereIn('tipo', [
            TipoAllocazioneFattura::PAGAMENTO->value,
            TipoAllocazioneFattura::COMPENSAZIONE->value,
        ])
        ->sum('importo_allocato');
    
    $netto = $fattura->netto_a_pagare;
    $inconsistente = false;
    $errore = null;
    
    // Detection (non blocco): se totale > netto qualcosa è andato storto a monte.
    // Il validaInput() avrebbe dovuto intercettare PRIMA della creazione pivot.
    // Qui logghiamo critical e marchiamo per intervento manuale, ma NON throwiamo:
    // il reconcile deve poter completare anche su DB sporco per riportarlo coerente.
    if ($totale > $netto) {
        Log::critical("Fattura {$fattura->id}: overpayment rilevato in ricalcolo " .
            "(totale={$totale}, netto={$netto}). Possibile dirty data — verificare manualmente.");
        $inconsistente = true;
        $errore = "Overpayment: totale {$totale} > netto {$netto}";
        // Lo stato resta 'parziale' come fallback ragionevole
        $stato = StatoPagamentoFattura::PARZIALE;
    } else {
        $stato = match(true) {
            $totale <= 0        => StatoPagamentoFattura::APERTA,
            $totale < $netto    => StatoPagamentoFattura::PARZIALE,
            $totale === $netto  => StatoPagamentoFattura::PAGATA,
        };
    }
    
    // Update atomico: stato + audit fields (flag tecnici separati dall'enum di dominio)
    $fattura->update([
        'stato_pagamento'               => $stato,
        'ultimo_ricalcolo_pagamento_at' => now(),
        'inconsistenza_pagamento'       => $inconsistente,
        'ultimo_errore_ricalcolo'       => $errore,
    ]);
}
```

**Distinzione architetturale chiara:**

| Contesto | Comportamento su `totale > netto` |
|----------|-----------------------------------|
| `validaInput()` (runtime, prima della scrittura) | Lancia `OverpaymentException` → blocco hard |
| `ricalcolaStatoFattura()` (detection sistemica, reconcile) | Log critical + flag `inconsistenza_pagamento=true` |

**Campi tecnici aggiuntivi su `fatture_passive`** (oltre a `ultimo_ricalcolo_pagamento_at` e `versione_allocazioni`):

```sql
fatture_passive (modifiche additive — aggiornate)
├── ultimo_ricalcolo_pagamento_at  TIMESTAMP NULL
├── versione_allocazioni            BIGINT UNSIGNED DEFAULT 0  -- change counter / cache invalidation
├── inconsistenza_pagamento         BOOLEAN DEFAULT FALSE      -- flag tecnico per anomalia rilevata
└── ultimo_errore_ricalcolo         TEXT NULL                  -- descrizione anomalia (per debug)
```

Il dominio funzionale (`stato_pagamento`) resta pulito; gli stati tecnici stanno a parte.

**Nota semantica sullo stato `pagata` per le Note di Credito**:

Quando una NC raggiunge `totale_allocato = netto_a_pagare`, lo stato passa a `pagata`. Semanticamente una NC non viene "pagata" ma "**compensata**" o "**utilizzata**". Per evitare esplosione di stati e mantenere la logica unificata, il modulo usa lo stesso enum `StatoPagamentoFattura` per FT e NC, con questa convenzione:

> Per le note di credito, il valore `pagata` significa **"interamente compensata/utilizzata"**.

La UI deve presentare il valore in modo coerente con il tipo documento:
- FT con stato=pagata → badge "Pagata" 
- NC con stato=pagata → badge "Compensata" o "Utilizzata"

Internamente il valore enum è lo stesso; il rendering UI lo traduce per il contesto.

### 6.5 `generaCausaleBonifico`

**Causale standard:**
```
"Pagamento FT {numero} del {data} - {ragione_sociale_condominio}"
```

**Causale bonifico parlante (detrazione fiscale):**
```
"Bonifico relativo a [tipo_detrazione_descrizione] - Art. [riferimento_normativo] - 
Beneficiari CF: [lista_cf] - P.IVA destinatario: [piva_fornitore] - 
FT {numero} del {data}"
```

**Riferimenti normativi per tipo detrazione:**
- `ristrutturazione` → "art. 16-bis DPR 917/1986"
- `ecobonus` → "art. 14 DL 63/2013"
- `sismabonus` → "art. 16 DL 63/2013"
- `superbonus` → "art. 119 DL 34/2020"

### 6.6 `trovaNoteCreditoCompensabili`

```php
public function trovaNoteCreditoCompensabili(
    int $fornitore_id, 
    int $condominio_id
): Collection {
    return FatturaPassiva::where('fornitore_id', $fornitore_id)
        ->where('condominio_id', $condominio_id)
        ->where('tipo_documento', 'nota_credito')
        ->whereIn('stato_pagamento', ['aperta', 'parziale'])
        ->where('stato_approvazione', 'approvata')
        ->get();
}
```

### 6.7 `verificaCapienza`

```php
public function verificaCapienza(int $conto_id, int $importo_cents): array
{
    $saldo = $this->saldoCorrente($conto_id);
    $dopoPagamento = $saldo - $importo_cents;
    
    return [
        'ok' => $dopoPagamento >= 0,
        'saldo_attuale_cents' => $saldo,
        'saldo_dopo_cents' => $dopoPagamento,
        'scopertura_cents' => $dopoPagamento < 0 ? abs($dopoPagamento) : 0,
    ];
}
```

### 6.8 Domain Exceptions

Tutte le eccezioni del modulo vivono in `app/Exceptions/Pagamenti/` ed estendono una base `PagamentoException` per facilitare il catching globale.

```php
namespace App\Exceptions\Pagamenti;

abstract class PagamentoException extends \DomainException {}

// Overpayment: somma allocato supera il residuo della fattura
class OverpaymentException extends PagamentoException {}

// Tentativo di registrare/stornare in esercizio chiuso
class FiscalYearClosedException extends PagamentoException {}

// Saldo conto insufficiente, blocco senza override
class InsufficientFundsException extends PagamentoException {}

// Limite antiriciclaggio (5.000€ contanti)
class IllegalCashAmountException extends PagamentoException {}

// Tentativo di stornare un pagamento già stornato
class PagamentoGiaStornatoException extends PagamentoException {}

// Fattura non approvata (stato_approvazione != 'approvata')
class FatturaNonApprovataException extends PagamentoException {}

// IBAN destinatario diverso dall'IBAN storico dell'anagrafica (require conferma)
class IbanDiscrepanzaException extends PagamentoException {}

// Pagamento duplicato rilevato (entro 7 giorni)
class PagamentoDuplicatoException extends PagamentoException {}

// Allocazioni con fornitori/condomini diversi nella stessa transazione
class AllocazioniInconsistentiException extends PagamentoException {}
```

**Vantaggi rispetto a `\Exception` generiche:**
- UX migliorata: la UI può discriminare per tipo e mostrare messaggi specifici
- API REST pulite: ogni eccezione mappa su un HTTP status code coerente
- Telemetry leggibile: i log filtrabili per tipo di errore di dominio
- Test più robusti: `expectException(OverpaymentException::class)` invece di match sui messaggi
- Handler globale Laravel può registrare a Sentry/Bugsnag con tag specifici

**Mapping HTTP suggerito:**

| Eccezione | HTTP |
|-----------|------|
| `OverpaymentException`, `FatturaNonApprovataException`, `AllocazioniInconsistentiException` | 422 Unprocessable Entity |
| `FiscalYearClosedException`, `IllegalCashAmountException` | 403 Forbidden |
| `InsufficientFundsException` | 409 Conflict |
| `PagamentoGiaStornatoException` | 409 Conflict |
| `IbanDiscrepanzaException`, `PagamentoDuplicatoException` | 409 Conflict (con flag override) |

---

## 7. Quadratura partita doppia — esempi numerici

### 7.1 Caso: pagamento puro singola fattura

Fattura 1.000€, pagamento totale.

```
Scrittura:
  DARE  Debiti v/Fornitori   1.000
  AVERE Banca                1.000
  TOTALE: 1.000 = 1.000 ✓

Pivot:
  fattura_id=42, alloc=+1000, tipo='pagamento'

Verifica invariante:
  Σ(alloc tipo='pagamento') = 1.000 = uscita_cassa ✓
```

### 7.2 Caso: netting fattura + NC

Fattura 1.000€, NC 200€, bonifico 800€.

```
Scrittura:
  DARE  Debiti v/Fornitori   1.000   (chiude la fattura per intero)
  AVERE Banca                  800   (uscita cassa effettiva)
  AVERE Debiti v/Fornitori     200   (chiude la NC, "consuma" il credito)
  TOTALE DARE: 1.000   TOTALE AVERE: 1.000 ✓

Pivot:
  fattura_id=42 (FT), alloc=+800,  tipo='pagamento'
  fattura_id=42 (FT), alloc=+200,  tipo='compensazione'
  fattura_id=43 (NC), alloc=+200,  tipo='compensazione'

Verifica invariante:
  Σ(alloc tipo='pagamento') = 800 = uscita_cassa ✓
  
Stato:
  FT: SUM(alloc) = 1.000 = netto → 'pagata'
  NC: SUM(alloc) = 200 = netto → 'pagata'
```

**Nota importante**: la riga AVERE Debiti v/Fornitori per la NC compensa contabilmente la NC stessa che, in fase di registrazione, ha generato un movimento DARE Debiti (la NC inverte la fattura). In questo modo il saldo netto del conto Debiti dopo l'operazione di netting è zero per quel fornitore.

### 7.3 Caso: storno

Storno del pagamento 1.000€ del caso 7.1.

```
Scrittura storno (#150):
  DARE  Banca                1.000   (ripristina cassa)
  AVERE Debiti v/Fornitori   1.000   (riapre il debito)

Pivot storno:
  fattura_id=42, alloc=-1.000, tipo='pagamento'

Stato fattura:
  SUM(alloc) = +1.000 + (-1.000) = 0 → 'aperta'
```

### 7.4 Caso: pagamento con commissioni bancarie

Fattura 500€, bonifico SEPA estero, commissioni 5€.

```
Scrittura:
  DARE  Debiti v/Fornitori     500
  DARE  Spese Bancarie           5
  AVERE Banca                  505
  TOTALE: 505 = 505 ✓

Pivot:
  fattura_id=44, alloc=+500, tipo='pagamento'

Verifica invariante:
  Σ(alloc tipo='pagamento') = 500
  uscita_cassa - commissioni = 505 - 5 = 500 ✓
```

### 7.5 Convenzione su `scritture_contabili.importo` (importante)

Il campo `importo` di testata su `scritture_contabili` può sembrare "il totale del movimento" ma nel caso netting **diverge** dalla magnitudo economica:

| Caso | `importo` (testata) | Magnitudo economica reale |
|------|---------------------|---------------------------|
| Pagamento puro 1.000€ | 1.000 | 1.000 |
| Netting FT 1.000 + NC 200 → bonifico 800 | **800** (cash) | 1.000 (debito chiuso) |
| Pagamento con commissioni 500+5 | **505** (cash) | 505 |

**Convenzione definitiva del modulo:**

> `scritture_contabili.importo` per tipo `pagamento_fornitore` = **USCITA DI CASSA** (cash out totale, commissioni incluse).
>
> **Non è il totale DARE/AVERE della scrittura.** Per quello si usa sempre `SUM(righe_scrittura.importo)` raggruppato per conto.

**Implicazioni:**
- Per report di cash flow: usare `scritture_contabili.importo` con filtro `tipo_movimento`
- Per report economici autoritative: aggregare sempre dalle `righe_scrittura`
- Per quadratura partita doppia: confrontare DARE vs AVERE sulle righe, mai sulla testata
- In v1.16 (Treasury) la convenzione resta valida e diventa l'agganciamento naturale per cash flow forecasting

In una versione futura (post-v1.13) si può valutare di **deprecare semanticamente** la colonna `importo` di testata in favore dell'aggregazione righe, ma per MVP la convenzione esplicita è sufficiente e riduce join inutili in dashboard hot path.

---

## 8. Bonifico Parlante (compliance fiscale)

### 8.1 Quando attivarlo

Per **lavori straordinari** che danno diritto a detrazione fiscale ai condòmini:
- Ristrutturazione edilizia (50%)
- Ecobonus (50% / 65% / 90%)
- Sismabonus (50% / 70% / 80%)
- Superbonus (110% / 90%)

Senza bonifico parlante, **i condòmini perdono il diritto alla detrazione**.

### 8.2 Dati obbligatori nella causale

1. Riferimento normativo (vedi 6.5)
2. Codice fiscale di **ogni** condòmino beneficiario
3. P.IVA o CF del fornitore destinatario
4. Numero e data fattura

### 8.3 Generazione automatica beneficiari

Quando l'admin attiva il flag `bonifico_parlante` e seleziona `tipo_detrazione`, il sistema:

1. Recupera la **tabella millesimi** del condominio
2. Per ogni anagrafica con quota > 0, calcola la quota di pertinenza del lavoro
3. Compila `beneficiari_detrazione` JSON (versionato per evoluzione futura):

```json
{
  "schema_version": 1,
  "metodo": "automatico",
  "tabella_base": "millesimi_generali",
  "generato_il": "2026-05-10T10:30:00Z",
  "beneficiari": [
    {"anagrafica_id": 101, "codice_fiscale": "RSSMRA80A01H501Z", "millesimi": 250, "quota_percentuale": 25.00},
    {"anagrafica_id": 102, "codice_fiscale": "VRDLGI75B15F205X", "millesimi": 300, "quota_percentuale": 30.00}
  ]
}
```

In caso di override manuale (vedi sezione 8.5), il JSON include anche:

```json
{
  "schema_version": 1,
  "metodo": "manuale",
  "tabella_base": "millesimi_generali",
  "modificato_da_user_id": 1,
  "modificato_il": "2026-05-10T10:35:00Z",
  "motivo_override": "Lavori scala A — esclusi condòmini scala B",
  "beneficiari": [ ... ]
}
```

Lo `schema_version: 1` permette evoluzioni future del payload (es. v2 in v1.12 con riferimento normativo dettagliato, percentuali frazionarie tra comproprietari, ecc.) senza migrazione distruttiva degli snapshot storici.

4. La causale assemblata cita tutti i CF (limite tecnico: causali bancarie ~140 caratteri → se troppo lungo, si genera **distinta tecnica allegata** PDF e in causale si mette "vedi distinta allegata prot. XXX")

### 8.4 Ritenuta 8% A.d.E.

Per legge, la banca trattiene automaticamente l'8% sul bonifico parlante e lo versa all'Agenzia delle Entrate. Il fornitore riceve quindi (importo - 8%). Questa ritenuta è **diversa** dalla ritenuta d'acconto sul lavoro autonomo, e va tracciata separatamente. Per MVP v1.9.1: **non gestiamo la contabilizzazione della ritenuta 8%** (resta sul cedolino del fornitore), ma il sistema deve **avvisare l'admin** che l'importo bonificato sarà ridotto del 8%.

---

## 9. Eventi e listener

### 9.1 Nuovo evento `PagamentoRegistrato`

```php
class PagamentoRegistrato
{
    public function __construct(public PagamentoFornitore $pagamento) {}
}
```

**Listener attivati:**
- `InvalidaLiquidityForecastCache` → trigger ricalcolo dashboard
- `SyncScadenziarioWithPagamento` → chiude la scadenza fattura (con riferimento al pagamento)
- `SyncF24WithPagamento` → **se la fattura aveva ritenuta**, crea evento F24 entro il 16 del mese successivo al pagamento

### 9.2 Evento `PagamentoStornato`

Listener:
- `InvalidaLiquidityForecastCache`
- `RiapriScadenziarioPagamento` → ripristina scadenza
- `AnnullaF24SeNonAncoraVersato` → se F24 non ancora pagato dall'admin, cancella evento

### 9.3 Rifactor critico: F24 al pagamento

**Stato attuale (v1.9):** `SyncScadenziarioWithFattura` crea evento F24 alla **registrazione fattura**.  
**Problema fiscale:** La ritenuta matura al **pagamento**, non alla registrazione.  
**Conseguenza pratica:** Se la fattura non viene mai pagata, l'F24 è errato.

**Refactor:**
1. Rimuovere logica F24 da `SyncScadenziarioWithFattura`
2. Aggiungere `SyncF24WithPagamento` che, al `PagamentoRegistrato`:
   - Verifica se la fattura aveva `importo_ritenuta > 0`
   - Calcola la quota ritenuta proporzionale al pagato (se parziale)
   - Crea evento F24 con scadenza = giorno 16 del mese successivo
3. Migration di pulizia: eventi F24 esistenti vanno rivisti caso per caso (verificare con script)

---

## 10. Validation rules

### 10.1 `PagamentoFornitoreCreateRequest`

```php
public function rules(): array
{
    return [
        'fornitore_id' => ['required', 'exists:fornitori,id'],
        'conto_corrente_id' => ['required', 'exists:conti_contabili,id'],
        'data_pagamento' => ['required', 'date', 'before_or_equal:today'],
        'metodo_pagamento' => ['required', Rule::in(['bonifico','contanti','assegno','rid_sdd','altro'])],
        'iban_destinatario' => ['nullable', 'required_if:metodo_pagamento,bonifico', 'string', 'max:34'],
        
        'allocazioni' => ['required', 'array', 'min:1'],
        'allocazioni.*.fattura_id' => ['required', 'exists:fatture_passive,id'],
        'allocazioni.*.tipo' => ['required', Rule::in(['pagamento','compensazione'])],
        'allocazioni.*.importo_allocato_cents' => ['required', 'integer', 'min:1'],
        
        'importo_commissioni_cents' => ['integer', 'min:0'],
        
        'bonifico_parlante' => ['boolean'],
        'tipo_detrazione' => [
            'nullable',
            'required_if:bonifico_parlante,true',
            Rule::in(['ristrutturazione','ecobonus','sismabonus','superbonus','altro'])
        ],
        'beneficiari_detrazione' => ['nullable', 'required_if:bonifico_parlante,true', 'array'],
        'beneficiari_detrazione.*.codice_fiscale' => ['required_with:beneficiari_detrazione', 'string'],
        
        'causale_bonifico' => ['nullable', 'string', 'max:1000'],
        'motivo_storno' => ['nullable', 'string', 'max:500'],
    ];
}
```

### 10.2 Validazioni aggiuntive nel service

Implementate dentro `validaInput()` perché coinvolgono coerenza cross-field:

1. **Esercizio scrittura aperto** (paradigma ledger-centric):
   ```php
   $esercizio = Esercizio::findOrFail($data['esercizio_id']);
   if ($esercizio->is_closed) {
       throw new FiscalYearClosedException(
           "Impossibile creare scritture nell'esercizio chiuso '{$esercizio->nome}'."
       );
   }
   ```
   **Importante**: NON controllare l'esercizio della fattura. Una fattura di competenza 2025 può essere pagata legittimamente nel 2026 anche se il 2025 è chiuso.

2. **Allocazioni coerenti**: tutte le fatture stesso `fornitore_id`, stesso `condominio_id`, altrimenti `AllocazioniInconsistentiException`

3. **Approvazione**: tutte con `stato_approvazione = 'approvata'`, altrimenti `FatturaNonApprovataException`

4. **No overpayment**: per ogni fattura, Σ allocato ≤ residuo. Altrimenti `OverpaymentException` (bypass solo con `allow_overpayment: true` esplicito + audit log)

5. **Antiriciclaggio**: se `metodo='contanti'` e Σ pagamento ≥ 5.000€ → `IllegalCashAmountException`

6. **Capienza conto**: se `verificaCapienza()['ok'] === false` e nessun flag `allow_overdraft` → `InsufficientFundsException`

7. **IBAN discrepanza** (Sentinella Anti-Frode): se `iban_destinatario` ≠ `fornitore.iban` corrente → `IbanDiscrepanzaException` (bypass con flag `iban_confermato_manualmente: true`)

8. **Duplicato recente** (scoring-based, non blocco grossolano): il detector calcola un *risk score* su base segnali, non blocca semplicemente "stessa fattura entro 7 giorni" (che genererebbe falsi positivi su pagamenti parziali successivi legittimi).

   **Segnali e pesi:**
   
   | Segnale | Peso |
   |---------|------|
   | Stessa fattura + **stesso importo allocato** | ALTO |
   | Stessa fattura + stesso `riferimento_bancario` (CRO/TRN) | **FORTISSIMO** |
   | Stessa fattura + entro 24h | ALTO |
   | Stessa fattura + entro 7 giorni | MEDIO |
   | Stesso IBAN destinatario + stesso importo + entro 24h | MEDIO |
   
   **Regole di output:**
   - Score sopra soglia "fortissima" (es. stesso CRO/TRN) → `PagamentoDuplicatoException` (blocco hard, richiede override)
   - Score sopra soglia "alta" → `PossibilePagamentoDuplicatoException` (warning UI, conferma esplicita)
   - Sotto soglia → pass
   
   **MVP semplificato per v1.9.1**: implementiamo solo la regola **"stessa fattura + stesso importo allocato + entro 24h"** come warning UI. Questo cattura il caso "doppio click" o "tab duplicata" senza generare falsi positivi sui pagamenti parziali successivi. Lo scoring completo arriva con v1.16 (Treasury).

9. **Storno solo su confermato**: in `stornaPagamento()`, se `$pagamento->stato === STORNATO` → `PagamentoGiaStornatoException`

---

## 11. UX flow

### 11.1 Schermata "Carrello pagamenti"

**Step 1 — Selezione fornitore + condominio**

L'admin sceglie il condominio e il fornitore. Il sistema mostra:
- Tutte le **fatture aperte/parziali** del fornitore (filtri: scadenza, importo)
- Tutte le **NC aperte** del fornitore (banner di alert se presenti)

**Step 2 — Selezione fatture/NC**

Checkbox per ogni riga. Calcoli live:
- Subtotale fatture selezionate
- Subtotale NC selezionate
- **Netto da bonificare = fatture - NC**
- Per ogni fattura, possibilità di **sovrascrivere l'importo** (pagamento parziale)

**Step 3 — Configurazione pagamento**

- Conto corrente da addebitare (default: quello preferito sulla fattura)
- Data pagamento
- Metodo (default bonifico)
- IBAN (precompilato dall'anagrafica fornitore, modificabile)
- Causale (autogenerata, modificabile)

**Step 4 — Flag avanzati**

- Bonifico parlante (collapse) → tipo detrazione + beneficiari calcolati
- Commissioni bancarie (campo opzionale)

**Step 5 — Conferma**

Banner con:
- Saldo attuale conto
- Importo da addebitare
- **Saldo dopo pagamento** (rosso se sotto-zero)
- Pulsante "Conferma" (disabilitato se sotto-zero senza override esplicito)

### 11.2 Schermata "Storico pagamenti fornitore"

Lista pagamenti registrati con:
- Stato (confermato / stornato)
- Importo, data, metodo
- Allocazioni (lista fatture toccate)
- Bottone "Storna" con form modale per `motivo_storno`

---

## 12. Comando Artisan reconcile

> Il comando delega tutto il calcolo dello stato a `PagamentoFornitoreService::ricalcolaStatoFattura()`, che applica il filtro `tipo IN ('pagamento','compensazione')` (esclude `competenza`). Nessuna duplicazione di logica.

```php
// app/Console/Commands/RicalcolaStatiFatture.php
class RicalcolaStatiFatture extends Command
{
    protected $signature = 'kondomanager:fatture-ricalcola-stati 
                            {--condominio= : Limita a un condominio specifico}
                            {--esercizio= : Limita a un esercizio specifico}
                            {--dry-run : Mostra solo discrepanze senza salvare}';

    public function handle(PagamentoFornitoreService $service)
    {
        $query = FatturaPassiva::query();
        if ($this->option('condominio')) $query->where('condominio_id', $this->option('condominio'));
        if ($this->option('esercizio'))  $query->where('esercizio_id', $this->option('esercizio'));
        
        $discrepanze = [];
        $query->chunk(500, function ($fatture) use ($service, &$discrepanze) {
            foreach ($fatture as $f) {
                $statoOriginale = $f->stato_pagamento;
                
                if (! $this->option('dry-run')) {
                    $service->ricalcolaStatoFattura($f);
                    $f->refresh();
                }
                
                $statoNuovo = /* calcolo identico al service */;
                
                if ($statoOriginale !== $statoNuovo) {
                    $discrepanze[] = [
                        'fattura_id' => $f->id,
                        'numero' => $f->numero_documento,
                        'vecchio' => $statoOriginale,
                        'nuovo' => $statoNuovo,
                        'residuo' => $f->residuo,
                    ];
                }
            }
        });

        if (empty($discrepanze)) {
            $this->info('✅ Nessuna discrepanza rilevata.');
            return;
        }

        $this->table(
            ['ID', 'Numero', 'Vecchio Stato', 'Nuovo Stato', 'Residuo (cents)'],
            $discrepanze
        );
        $this->warn(count($discrepanze) . ' fatture con discrepanza' . 
            ($this->option('dry-run') ? ' (dry-run, nessuna modifica salvata).' : ' (corrette).'));
    }
}
```

---

## 13. Strategia di testing (Pest)

### 13.1 Test fondamentali (`PagamentoFornitoreServiceTest.php`)

```
✓ test_pagamento_totale_fattura_singola
✓ test_pagamento_parziale_aggiorna_stato_parziale
✓ test_due_pagamenti_parziali_chiudono_la_fattura
✓ test_bonifico_cumulativo_su_fatture_diverse_stesso_fornitore
✓ test_netting_fattura_con_nota_credito_quadratura_corretta
✓ test_netting_invariante_pagamento_uguale_uscita_cassa
✓ test_storno_pagamento_riapre_la_fattura
✓ test_storno_pagamento_parziale_riporta_stato_aperta
✓ test_storno_di_pagamento_gia_stornato_lancia_eccezione
✓ test_pagamento_con_commissioni_bancarie_quadra
✓ test_calcolo_stato_fattura_con_overpayment_logga_warning
```

### 13.2 Test compliance fiscale

```
✓ test_pagamento_contanti_oltre_5000_lancia_eccezione_antiriciclaggio
✓ test_pagamento_contanti_4999_passa
✓ test_bonifico_parlante_genera_causale_con_riferimento_normativo
✓ test_bonifico_parlante_include_codici_fiscali_beneficiari
✓ test_bonifico_parlante_richiede_tipo_detrazione
```

### 13.3 Test integrazione eventi

```
✓ test_pagamento_dispatch_PagamentoRegistrato_event
✓ test_pagamento_invalida_cache_liquidity_forecast
✓ test_pagamento_chiude_scadenza_pagamento
✓ test_pagamento_con_ritenuta_crea_evento_F24
✓ test_storno_F24_se_non_ancora_versato
```

### 13.4 Test del comando reconcile

```
✓ test_reconcile_corregge_stato_inconsistente
✓ test_reconcile_dry_run_non_modifica_db
✓ test_reconcile_filtro_condominio
```

### 13.5 Setup helper test

Estendere il `setupContabile()` esistente in `tests/Feature/Gestionale/` per fornire conti standard pre-configurati (Banca, Debiti v/Fornitori, Spese Bancarie). Mantenere `Event::fake([...])` per evitare side effect dei listener nei test unitari del service.

### 13.6 Property-based invariant tests (sistemici)

Oltre ai test unitari su casi specifici, scriviamo **test di invariante** che generano scenari randomici e verificano proprietà globali del modulo:

```
test_invariante_quadratura_partita_doppia
   → genera N scenari random (importi, allocazioni, NC, commissioni)
   → per ogni scrittura creata: Σ DARE === Σ AVERE (sempre)

test_invariante_cash_equals_pagamento_allocato
   → genera N scenari random con netting
   → per ogni scrittura: Σ(pivot WHERE tipo='pagamento') == importo_uscita_cassa

test_invariante_stato_pagamento_derivato_corretto
   → genera sequenze random di pagamenti + storni
   → dopo ogni operazione: stato_pagamento coerente con SUM(allocato filtered)

test_invariante_storno_riporta_aperta
   → genera pagamento totale + storno completo
   → fattura sempre in stato 'aperta' dopo storno

test_invariante_pivot_tipo_competenza_intoccato
   → genera scenari con pagamenti/storni
   → la riga pivot tipo='competenza' della fattura non viene mai modificata
```

Approccio: usare `faker` per generare input randomici, ripetere ciascun test 50-100 volte, fail-fast su prima violazione di invariante.

### 13.7 Limitazioni note dei test

- **Concorrenza su SQLite**: i test di `lockForUpdate()` su SQLite in-memory passano sempre ma non testano la lock realmente (SQLite ignora la clause). Per validare la concorrenza vera servono test MySQL dedicati (`@requires mysql` o tier CI separato).
- **Eventi**: usare `Event::fake()` nei test del service per isolare la logica. Test integrazione eventi vanno in test class separati.

---

## 14. Cosa è fuori scope (rimandato a v1.9.2)

| Caso | Razionale rinvio |
|------|------------------|
| Acconti fornitori | Richiede nuovo conto "Crediti v/Fornitori per Acconti" + giroconto compensazione |
| NC > Fattura (compensazione pura senza cassa) | Caso edge raro, complica la quadratura |
| Anticipi amministratore | Anagrafica admin come "fornitore fittizio" + workflow rimborso |
| Pagamento con assegno | Data emissione vs data addebito + rischio rimbalzo |
| RID/SDD passivi | Si integra meglio con riconciliazione bancaria v1.16 |
| `data_valuta` separata | Servirà per liquidità reale, ma MVP usa `data_pagamento` |
| Abbuoni passivi | Caso minore, conto dedicato + flag |
| Distinta PDF rifinita | MVP genera CSV/lista esportabile |
| **Refactor: estrarre `PagamentoAggregationResult` (Value Object)** | Centralizzare i totali (`totalePagamento`, `totaleSuFatture`, `totaleSuNC`, `uscitaCassa`, `quadratura`) in un VO immutabile. Da fare quando si aggiungono nuovi tipi di allocazione (acconti, abbuoni, ritenute) — altrimenti il service esplode. In v1.9.1 i calcoli sono ancora gestibili inline. |
| **Money Value Object** (v1.10+) | Introdurre `Money::fromCents()` con operazioni `add()`, `subtract()`, `percentage()` per evitare "integer soup" quando le operazioni monetarie cresceranno (v1.10 accantonamenti, v1.11 riparti, v1.12 ritenute). Per MVP `signed BIGINT cents` è sufficiente. |
| **Modalità storno rigoroso (Variante B2)** | In v1.17 (Reporting Suite) valutare modalità opzionale "sopravvenienze pure" per condomini con audit revisore formale. Richiede nuovo conto "Sopravvenienze attive/passive" + logica di "debito storico insoluto". |

---

## 15. Checklist implementazione

> **Sequenza importante**: si parte dal vocabolario di dominio (Backed Enums + Domain Exceptions) **prima** delle migration. Questo evita di congelare naming o granularità che poi si trascinano per anni.

### Fase 0 — Vocabolario di dominio (FIRST)
- [ ] Backed Enum `TipoMovimentoContabile` (con tutti i casi v1.9-v1.16)
- [ ] Backed Enum `MetodoPagamento`
- [ ] Backed Enum `StatoPagamentoFattura`
- [ ] Backed Enum `StatoPagamentoFornitore`
- [ ] Backed Enum `TipoAllocazioneFattura`
- [ ] Backed Enum `TipoDetrazione` (con metodo `riferimentoNormativo()` e `descrizione()`)
- [ ] Domain Exceptions: `PagamentoException` base + 9 sottoclassi specifiche
- [ ] Test unitari sugli enum (cases, valori, metodi helper)

### Fase 1 — Fondamenta DB e modelli

> **⚠️ Prerequisito tecnico Laravel**: la conversione di colonna `ENUM` → `VARCHAR` via `->change()` richiede su molte versioni di Laravel il pacchetto `doctrine/dbal`. Prima della prima migration di conversione, eseguire:
> ```bash
> composer require doctrine/dbal
> ```
> In alternativa, usare migration raw SQL (`DB::statement('ALTER TABLE ...')`) e gestire SQLite separatamente con guard `if (DB::getDriverName() === 'mysql')`.

- [ ] Migration: conversione `scritture_contabili.tipo_movimento` da ENUM a `VARCHAR(50)`
- [ ] Migration: aggiunta `scritture_contabili.idempotency_key` UUID UNIQUE NULL
- [ ] Migration: verifica `fattura_scrittura` (campo `tipo` VARCHAR(50), `importo_allocato` BIGINT **signed**)
- [ ] Migration: tabella `pagamenti_fornitori` (con `fornitore_snapshot` JSON)
- [ ] Migration: aggiunta `fatture_passive.ultimo_ricalcolo_pagamento_at` + `versione_allocazioni`
- [ ] Migration: indici performance (fornitore_id+data_pagamento, scrittura_id, ecc.)
- [ ] Aggiornamento PianoContiSeeder per conto **Spese Bancarie** standard
- [ ] Modello `PagamentoFornitore` con relazioni + casts Enum
- [ ] Modello `FatturaScrittura` (Pivot) con cast `TipoAllocazioneFattura`
- [ ] Estensione `FatturaPassiva` con accessor `totale_allocato`, `residuo`, scope, cast `StatoPagamentoFattura`
- [ ] Estensione `ScritturaContabile` con cast `TipoMovimentoContabile` + relazione `pagamentoFornitore`

### Fase 2 — Service e logica core
- [ ] `PagamentoFornitoreService::validaInput` (con tutte le 9 validazioni cross-field)
- [ ] `PagamentoFornitoreService::registraPagamento` (con lockForUpdate + orderBy + quadratura corretta netting)
- [ ] `PagamentoFornitoreService::stornaPagamento`
- [ ] `PagamentoFornitoreService::ricalcolaStatoFattura` (con blocco overpayment + timestamp)
- [ ] `PagamentoFornitoreService::generaCausaleBonifico` (standard + parlante via `TipoDetrazione::riferimentoNormativo()`)
- [ ] `PagamentoFornitoreService::trovaNoteCreditoCompensabili`
- [ ] `PagamentoFornitoreService::verificaCapienza`
- [ ] `PagamentoFornitoreService::verificaQuadraturaScrittura` (fail-fast su scrittura non bilanciata)
- [ ] Eventi `PagamentoRegistrato`, `PagamentoStornato` (con `afterCommit` per listener pesanti)

### Fase 3 — Test Pest (target: tutto verde su SQLite in-memory)
- [ ] Test fondamentali (13.1): pagamenti totali, parziali, multipli, netting, storni
- [ ] Test compliance fiscale (13.2): contanti 5k, bonifico parlante, beneficiari
- [ ] Test integrazione eventi (13.3): cache invalidation, scadenziario, F24
- [ ] Test concorrenza: simulazione race condition (con `lockForUpdate` deve passare)
- [ ] Test domain exceptions: ogni eccezione viene lanciata nei casi attesi
- [ ] Test quadratura netting: la scrittura quadra in tutti i casi (fattura sola, netting, commissioni)
- [ ] Test esercizio chiuso: blocco su scrittura, NON su fattura riferita

### Fase 4 — Listener e rifactor F24
- [ ] `InvalidaLiquidityForecastCache` (con `$afterCommit = true`)
- [ ] `SyncScadenziarioWithPagamento`
- [ ] `SyncF24WithPagamento`
- [ ] **Refactor**: rimozione logica F24 da `SyncScadenziarioWithFattura`
- [ ] Test di regressione su scadenziario

### Fase 5 — Validation e Controller
- [ ] `PagamentoFornitoreCreateRequest` (con Rule::enum per ogni enum)
- [ ] `PagamentoFornitoreStornoRequest`
- [ ] `PagamentoFornitoreController` (store, storno, index, show)
- [ ] Exception handler globale per mapping Domain Exception → HTTP status
- [ ] Routes Inertia
- [ ] Policy/Authorization

### Fase 6 — Frontend Vue (Payment Sentinel)
- [ ] Pagina `Pagamenti/Create.vue` (carrello)
- [ ] Widget `SimulatoreLiquidita.vue` (saldo live, overdraft alert)
- [ ] Widget `RadarNoteCredito.vue` (auto-netting suggester)
- [ ] Widget `SentinellaIban.vue` (anti-frode, confronto vs anagrafica)
- [ ] Widget `DetectorPagamentoDuplicato.vue` (alert 7gg)
- [ ] Componente `CalcolatriceLive.vue` (subtotali, netto)
- [ ] Componente `BonificoParlanteForm.vue` (con override manuale beneficiari)
- [ ] Componente `ConfermaPagamento.vue` (capienza, riepilogo)
- [ ] Smart sort fatture aperte (scadenza/importo)
- [ ] Auto-fill IBAN da anagrafica
- [ ] Pagina `Pagamenti/Index.vue` (storico)
- [ ] Modale `StornaPagamentoModal.vue` con `motivo_storno` obbligatorio

### Fase 7 — Comando e tooling
- [ ] Comando `kondomanager:fatture-ricalcola-stati` (con --dry-run, --condominio, --esercizio)
- [ ] Test del comando

### Fase 8 — Integrazione finale
- [ ] Test end-to-end del flusso completo (carrello → scrittura → ricalcolo stato → forecast)
- [ ] Esecuzione `kondomanager:fatture-ricalcola-stati --dry-run` su DB esistente
- [ ] Verifica idempotency con retry simulato
- [ ] Aggiornamento documentazione utente
- [ ] Release notes v1.9.1

---

## Appendice — Roadmap di contesto

- **v1.9.1** (questo documento) — Pagamento Fatture MVP
- **v1.9.2** — Pagamento Fatture Avanzato (acconti, anticipi admin, assegni, abbuoni)
- **v1.10** — Foundation Release (accantonamenti, bilanciatore fondi, dashboard intelligence)
- **v1.11** — Recupero Crediti + motore riparto unificato
- **v1.12** — DNA Fiscale Fornitore (Reverse Charge, Split Payment, ritenute, DURC)
- **v1.16** — Treasury & Cash Flow (riconciliazione bancaria, RID/SDD passivi)

---

**Fine documento.**