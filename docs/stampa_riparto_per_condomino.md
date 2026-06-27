# Stampa Riparto Personale per Condòmino

Specifica tecnica per l'estensione della stampa **Riparto per Tabella × Soggetto**
con due varianti destinate al singolo condòmino.

> **Prerequisito:** La stampa base "Prospetto Completo" (Riparto per Tabella) è già
> implementata e funzionante da v1.9.x. Questa spec descrive le due estensioni pianificate
> per la suite stampe v1.21.

---

## Contesto e motivazione

Il **Prospetto Completo** (già esistente) è il documento per l'amministratore e
l'assemblea: mostra tutte le unità × tutte le tabelle in formato landscape.

Manca il documento destinato al **singolo condòmino** — quello che risponde alla
domanda più frequente che ogni amministratore riceve:

> *"Ma perché pago esattamente questa cifra? Perché il mio vicino paga diverso?"*

Nessun gestionale condominiale italiano invia ai condòmini una spiegazione
dettagliata per tabella. Farlo è un differenziatore reale: riduce le telefonate
all'amministratore e costruisce fiducia.

---

## Documento — Estratto Personale per Tabella

### Struttura della pagina (formato A4 Portrait)

```
┌─────────────────────────────────────────────────────────────────┐
│  [Header condominio — ereditato da base.blade.php]              │
├─────────────────────────────────────────────────────────────────┤
│  ESTRATTO RIPARTO PERSONALE                                     │
│  Esercizio 2025/2026 · Piano rate: "Piano Ordinario 2026"      │
│  Delibera del 12/11/2025 · Verbale n. 3                        │
├──────────────────┬──────────────────────────────────────────────┤
│  Int. 5          │  ROSSI Mario                                 │
│  Piano 3         │  Proprietario                                │
│                  │  Via Roma 12, int. 5 — Milano               │
├──────────────────┴──────────────────────────────────────────────┤
│  Come è calcolata la tua quota                                  │
├─────────────────────┬────────────┬────────────┬────────────────┤
│  Tabella            │  Tua quota │  Tot. tab. │  Tuo importo   │
├─────────────────────┼────────────┼────────────┼────────────────┤
│  Centrale Termica   │ 156,000 ‰  │ 1.000 ‰    │  € 983,20      │
│  Ascensore FM       │   3 quota  │  12 quot.  │  €  83,41      │
│  Giroscale          │   1 quota  │   6 quot.  │  € 350,00      │
│  Proprietà          │ 156,000 ‰  │ 1.000 ‰    │  € 366,67      │
│  Riscaldamento      │ 192,393 ‰  │ 1.000 ‰    │ € 2.943,61     │
├─────────────────────┴────────────┴────────────┼────────────────┤
│  TOTALE ANNUO                                 │  € 5.285,20    │
│  La tua quota sul totale del condominio: 18,9%│                │
├───────────────────────────────────────────────┴────────────────┤
│  Piano di pagamento                                             │
├───────────────────┬────────────────────┬───────────────────────┤
│  Rata             │  Scadenza           │  Importo              │
├───────────────────┼────────────────────┼───────────────────────┤
│  1ª Rata          │  01/01/2026         │  € 1.321,30           │
│  2ª Rata          │  01/04/2026         │  € 1.321,30           │
│  3ª Rata          │  01/07/2026         │  € 1.321,30           │
│  4ª Rata          │  01/10/2026         │  € 1.321,30           │
├───────────────────┴────────────────────┴───────────────────────┤
│  Firma amministratore (se impostata nelle Impostazioni)         │
└─────────────────────────────────────────────────────────────────┘
```

### Elementi chiave del design

- **Linguaggio semplice**: evitare tecnicismi dove possibile.
  - "Centrale Termica — la tua quota è 156 millesimi su 1000" invece di "CTM coeff. 100%"
- **Colonna "Tot. tab."**: mostra il denominatore (es. 1000 ‰ o 12 quote) così il condòmino
  capisce il proprio peso relativo senza ambiguità
- **% sul totale condominio**: un numero solo che risponde a "sono io il primo a pagare?"
- **Piano di pagamento integrato**: le rate già emesse con scadenza e importo
- **Separazione proprietario/inquilino**: se l'unità ha sia proprietario che inquilino,
  il documento mostra la quota di ciascuno (due sezioni nella stessa pagina o pagine separate)

---

## Opzione A — PDF Multi-pagina (un file, tutte le unità)

### Comportamento

Un unico PDF landscape/portrait con **una sezione per ogni (immobile × soggetto)**,
separata da `page-break-after: always`.

L'amministratore scarica un file solo, lo stampa e distribuisce fisicamente
foglio per foglio (o lo archivia come unico documento).

### Route

```
GET esercizi/{esercizio}/piani-rate/{pianoRate}/print-riparto-personale
    → name: esercizi.piani-rate.print-riparto-personale
```

Nessun parametro aggiuntivo. Genera sempre tutte le unità.

### Controller

`PianoRatePrintController::ripartoPersonale()` — variante del metodo `ripartoTabelle()`:
- Stessa chiamata a `RipartoTabelleService::buildMatrice()`
- Template diverso: `pdf.gestionale.riparto_personale`
- Formato: A4 Portrait
- `margin_top`: 35 (lascia spazio all'header mPDF standard)

### Template Blade

`resources/views/pdf/gestionale/riparto_personale.blade.php`

```blade
@foreach($righe as $immobileId => $rigaImmobile)
    @foreach($rigaImmobile['soggetti'] as $anagraficaId => $soggetto)
        <div style="page-break-after: always;">
            {{-- Sezione personale per questo soggetto su questo immobile --}}
            ...
        </div>
    @endforeach
@endforeach
```

### UI — Pulsante

Nel dropdown della stampa (o accanto al pulsante "Riparto per Tabella" già esistente),
aggiungere un secondo bottone o voce dropdown:

```
[TableProperties] Riparto per Tabella  ▾
    ├── Prospetto completo (tutti)     ← quello attuale
    └── Estratto per condòmino (A4)   ← Opzione A
```

Alternativa più pulita: trasformare il pulsante "Riparto per Tabella" in dropdown
con le due voci, stile del dropdown "Stampa Scadenziario" già esistente.

---

## Opzione B — ZIP con PDF individuali (un file per condòmino)

### Comportamento

Una chiamata HTTP che restituisce un archivio `.zip` contenente un PDF per ogni
soggetto del piano rate, nominato in modo leggibile:

```
riparto_2026_Int01_Rossi_Mario.pdf
riparto_2026_Int01_Ruatti_Inquilino.pdf
riparto_2026_Int03_Murrja_Edmir.pdf
...
```

L'amministratore scarica lo zip, apre un client email, allega il file corretto
a ogni condòmino. Nella v1.22+ (Communication Suite) questo step sarà automatizzato.

### Dipendenze

- `ext-zip` PHP (disponibile su PHP 8.2+, verificare che sia abilitato sull'hosting)
- Loop su `$righe` generando un `Mpdf` per ogni soggetto, output `'S'` (stringa),
  aggiunta a `ZipArchive`

### Route

```
GET esercizi/{esercizio}/piani-rate/{pianoRate}/download-riparto-zip
    → name: esercizi.piani-rate.download-riparto-zip
    → Content-Type: application/zip
    → Content-Disposition: attachment; filename="riparto_{pianoRate->nome}_{date}.zip"
```

### Considerazioni performance

Su un condominio con 20 unità e 30 soggetti, generare 30 PDF in sequenza può
richiedere 5–15 secondi. Opzioni:

1. **Sincrono con timeout esteso** (sufficiente per condomìni piccoli/medi)
2. **Job in background + notifica** quando il file è pronto (raccomandato per >50 soggetti)
   — si aggancia all'infrastruttura job queue già presente

### UI — Pulsante

```
[TableProperties] Riparto per Tabella  ▾
    ├── Prospetto completo (tutti)       ← quello attuale
    ├── Estratto per condòmino (A4)      ← Opzione A
    └── Scarica ZIP individuale          ← Opzione B
```

---

## Note implementative comuni

### Dati del piano di pagamento nell'estratto

L'estratto personale deve mostrare anche il piano rate (rate, scadenze, importi)
per quel soggetto. I dati sono già disponibili via:

```php
$pianoRate->load(['rate.rateQuote' => fn($q) => $q
    ->where('anagrafica_id', $anagraficaId)
    ->where('immobile_id', $immobileId)
]);
```

### Cascata proprietario/inquilino su stessa pagina vs pagine separate

Se un appartamento ha proprietario + inquilino, ci sono due approcci:
- **Pagine separate** (una per P, una per I): più semplice, ognuno vede solo il suo
- **Sezioni nella stessa pagina**: mostra entrambi, utile quando l'amministratore
  consegna fisicamente al proprietario il documento di entrambi

**Default raccomandato**: pagine separate (meno ambiguità, privacy dei dati).
Aggiungere un parametro `?split=false` per la variante unificata se richiesto.

### Firma

Il blocco firma (da `PrintSettings::firma_stampe_path`) va inserito in fondo
all'ultima sezione di ogni pagina, non in fondo all'intero PDF. In `base.blade.php`
il blocco firma è già gestito — verificare che con `page-break-after` rimanga
associato alla pagina corretta (potrebbe richiedere di includerlo nel loop
invece di lasciarlo al layout base).

### Internazionalizzazione delle label

Le label di questo documento ("la tua quota", "piano di pagamento", ecc.) vanno
nei file `lang/it/pdf.php` (da creare se non esiste) per preparare la futura
internazionalizzazione della suite stampe.

---

## Priorità e sequenza suggerita

1. **Opzione A** — prima, perché non richiede gestione ZIP né code di background.
   Stimata: 2–3 sessioni di lavoro.

2. **Opzione B** — dopo, condizionata alla disponibilità di `ext-zip` sull'hosting
   target. Se si decide per il job asincrono, richiede anche la UI di notifica
   ("Il file è pronto, scaricalo") che si aggancia all'Inbox operativa già esistente.
   Stimata: 3–5 sessioni.

3. **Invio email automatico** — v1.22 Communication Suite. L'Opzione B produce
   già i file pronti per l'invio; la v1.22 aggiungerà solo il trigger email.

---

## File coinvolti (quando si implementa)

### Nuovi
- `app/Http/Controllers/Gestionale/PianiRate/PianoRatePrintController.php`
  → aggiungere `ripartoPersonale()` e `downloadRipartoZip()`
- `resources/views/pdf/gestionale/riparto_personale.blade.php` — template estratto
- `lang/it/pdf.php` — label localizzabili

### Modificati
- `routes/gestionale.php` — 2 nuove route
- `resources/js/pages/gestionale/pianiRate/PianiRateShow.vue`
  → trasformare il pulsante "Riparto per Tabella" in dropdown a 3 voci

### Nessuna migration necessaria
Tutti i dati necessari sono già presenti in `rate_quote`, `quote_tabella`,
`conto_tabella_millesimale`. Nessun nuovo campo a DB.
