# Stampa "Riparto Bilancio per Tabella e Soggetto" — Guida di Stile

**File template:** `resources/views/pdf/gestionale/riparto_tabelle.blade.php`
**Layout base condiviso:** `resources/views/pdf/base.blade.php` (header condominio, footer con nota legale + numerazione pagine)
**Generazione:** `PianoRatePrintController::ripartoTabelle()` → `PdfService` (mPDF)
**Dati:** `RipartoTabelleService::buildMatrice()` — vedi `docs/ripartotabelle_discrepanza_centesimale.md` per le garanzie numeriche

---

## Layout attuale (v1.10-beta)

### Formato pagina
- Fino a 5 tabelle millesimali: **A4 landscape**
- Oltre 5 tabelle: **A3 landscape**
- Margini: top 32, left/right 8 (header mPDF con nome condominio)

### Spezzamento su più pagine (chunking)
Se il piano coinvolge più di **8 tabelle millesimali**, il template le divide
in blocchi da massimo 8 (`array_chunk($tabelle, 8)`): ogni blocco è una
tabella completa con tutte le righe/soggetti e le colonne fisse ripetute,
su pagine successive con intestazione "(Pagina N di M)". Le colonne fisse
(App., Condòmino, Ruolo, TOTALE SOGG., % TOT., TOT. IMMOB.) compaiono in
OGNI blocco.

> Storia: fino alla v1.10-beta.7 il limite era 6 tabelle/blocco e il PAR
> (8 tabelle) usciva su 2 blocchi. Con le colonne di servizio ristrette
> (2026-07, feedback Gadotti) 8 tabelle stanno in un unico blocco A3-L,
> come nelle stampe storiche v1.9.1.

### Font adattivo (dipende dal numero di tabelle del blocco corrente)
| n. tabelle | base | small | tiny |
|---|---|---|---|
| ≤ 5 | 7pt | 6pt | 5.5pt |
| 6–8 | 6.5pt | 5.5pt | 5pt |
| > 8 | 6pt | 5pt | 4.5pt |

### Larghezze colonne fisse (% pagina)
| Colonna | ≤ 5 tabelle | > 5 tabelle | note |
|---|---|---|---|
| App. | 3,5% | 3,5% | numero a 2 cifre |
| Condòmino / Soggetto | 22% | 18% | nomi lunghi, resta generosa |
| Ruolo | 3% | 3% | solo sigla P/I/U |
| TOTALE SOGG. | 7% | 7% | "€ 1.234,56" |
| % TOT. | 3,5% | 3,5% | "4,98%" |
| TOT. IMMOB. | 7% | 7% | "€ 1.234,56" |
| **Residuo per le tabelle** | **54%** | **58%** | |

Ogni tabella millesimale occupa `residuo / n` con split interno 44% quota
(mill./quote/mc) e 56% importo.

---

## Feedback amministratore (Gadotti, 2026-07) — IMPLEMENTATO

> "Le colonne (ruolo, totale sogg., % tot. e tot. immob.) potrebbero essere
> molto più strette in modo tale da aumentare la dimensione carattere."

Implementato il 2026-07-07 (valori nelle tabelle sopra):
- colonne di servizio ristrette: App. 5→3,5 / Ruolo 5→3 / % TOT. 5→3,5 /
  TOTALE SOGG. 8-10→7 / TOT. IMMOB. 8-10→7 (recupero ~7-11 punti %);
- font alzato di mezzo punto nei casi >5 tabelle (6→6.5pt base per 6-8);
- chunk 6→8 tabelle/blocco: il PAR torna su blocco unico.

Verificato rigenerando il PDF PAR con dati reali (44 unità × 8 tabelle):
nessun a-capo negli importi, paginazione allineata alla stampa storica.

**Cosa NON toccare in futuri interventi di layout:**
- il footer condiviso (`base.blade.php`) — già compatibile con tutte le
  stampe, confermato dall'amministratore;
- `RipartoTabelleService` — le garanzie numeriche (colonne = budget,
  righe = rate_quote) sono indipendenti dal layout;
- prima di ogni rilascio: rigenerare il PDF PAR con lo script di fixture
  (dati reali) e confrontare visivamente con la stampa precedente.

---

## Convenzioni grafiche in uso (non cambiare senza motivo)

- Palette navy (`#1e3a5f` brand, `#dce6f1` header, `#edf2f8` colonne evidenziate)
- Accent colorato a sinistra del nome per ruolo (blu=P, verde=I, arancio=U)
- Righe alternate `#f7f9fb`, separatore 3px tra immobili
- Quota millesimale stampata una volta per immobile (rowspan sui soggetti)
- Celle senza partecipazione: "—" su fondo `#fafafa`
- Barra riepilogo Proprietari/Inquilini in testa (solo se più ruoli presenti)
