# Restyling 2.0 — Direzione design

<!-- verifica-documentazione -->
> **Stato:** Direzione di design non implementata — verificato il 31/07/2026 su 1.10.0-beta.32
> La diagnosi del codice attuale (§«Perché oggi sembra un sito web») è verificata e utilizzabile; palette, token, shell, StatusChip e area condòmino sono proposte per la 2.0 e non esistono nel repository.
<!-- /verifica-documentazione -->

> **Stato**: ragionamento e mockup approvati concettualmente, nessuna implementazione avviata.
> **Piano**: si completa la 1.10 e si prosegue con le feature fino alla **1.18** (suite report e stampe);
> la nuova veste grafica esce con la **versione 2.0, obiettivo ottobre 2026**.
> **Mockup**: [restyling_2.0_mockups.html](restyling_2.0_mockups.html) (aprire nel browser, self-contained)
> — pubblicati anche come artifact privato su claude.ai.

## Perché oggi "sembra un sito web" — diagnosi

Tre cause, tutte verificate nel codice, nessuna dovuta a cattiva esecuzione: sono i default dello
starter kit mai sostituiti.

1. **Zero colore nel sistema.** `resources/css/app.css` è la palette *neutral* di shadcn intatta:
   tutte le variabili a saturazione 0%, primary quasi-nero (`hsl(0 0% 9%)`). Ogni bottone è nero,
   nessun colore appartiene a Kondomanager. Il logo è un placeholder ("Km" in Arial su quadrato nero).
2. **Layout da sito, non da app.** Tutto passa da `AppHeaderLayout`: barra orizzontale +
   contenuto centrato `md:max-w-7xl`. **Il vero ladro di spazio è il cap `max-w-7xl`**, non la
   posizione del menu: su un 1600px si perdono ~320px di margini. La sidebar shadcn esiste già in
   `components/ui/sidebar` ma non è mai stata collegata (residuo starter kit in `AppSidebar.vue`,
   con link a laravel/vue-starter-kit).
3. **I numeri non comandano.** Celle `p-4`, importi senza `tabular-nums` né allineamento a destra,
   micro-etichette uppercase 9–10px ovunque, colori semantici (amber/emerald/red) hardcodati
   pagina per pagina.

## Direzione colore: «Ottanio»

Un solo accento su neutri freddi; il bianco resta solo per le card, il piano di pagina scende di
un gradino (profondità senza bordi).

| Token | Hex | Uso |
|---|---|---|
| Inchiostro sidebar/barra | `#13232B` | shell di navigazione |
| Piano di pagina | `#F2F5F6` | sfondo contenuto |
| Carta (card) | `#FFFFFF` | superfici widget/tabelle |
| Accento | `#0E7D86` | azione primaria, voce attiva, link |
| Accento attivo / dark | `#2BB3BD` | highlight su fondo scuro |
| Semantici | `#16A34A` / `#D97706` / `#B91C1C` | ok / attenzione / errore (come token, non per pagina) |

Alternative valutate: Oltremare `#1D4ED8` (istituzionale, prevedibile), Bosco `#3D7A50` (calda,
meno finanziaria). **Consigliata: Ottanio** (raro nei gestionali italiani, tutti blu).

Grafici (palette validata per contrasto e daltonismo con il validatore dataviz):
serie su fondo chiaro `#0891B2` + `#EB6834`; su fondo scuro (`#1A2028`) `#1BA0AA` + `#E2622E`.

Regole trasversali: `font-variant-numeric: tabular-nums` + allineamento a destra per tutti gli
importi; scala tipografica unica 12/13/15/18/24; componente unico `StatusChip` al posto dei colori
inline; righe tabella dense (`py-2`), header sticky, riga totali.

## Decisioni per area

### Amministratore — due aree, una sola shell
«Social» e gestionale restano due aree (lavori diversi) ma con **navigazione unica** a gruppi:
Panoramica / Comunità (comunicazioni, segnalazioni, documenti, agenda, anagrafiche) /
Contabilità («Apri gestionale…» → scelta condominio → contesto). L'app smette di sembrare due
software incollati. Il contesto condominio+esercizio vive fisso nella shell.

### Sidebar vs menu in alto — decisione rimandabile, colore no
Entrambe le strade funzionano una volta tolto `max-w-7xl` (vedi mockup di entrambe).
Limite del menu in alto: con 9 voci è già pieno, non scala con i moduli futuri.
**Opzione scelta da esplorare: impostazione nelle impostazioni generali** per far scegliere
all'amministratore. Fattibile a costo contenuto perché ogni pagina passa da due soli wrapper
(`AppLayout.vue`, `GestionaleLayout.vue`); prerequisito: estrarre le voci di nav da
`GestionaleHeader.vue` in una config condivisa. Il toggle fa anche da **feature flag di rollout**
(default vecchio, nuovo in prova). Costo onesto: due shell = doppio collaudo di ogni voce futura.

### Condomino — una pagina sola, la rata al centro
Oggi `UserDashboard.vue` **non mostra la situazione pagamenti** (solo liste). La nuova area
personale: hero «La tua prossima rata» (importo, scadenza, N di M pagate, "sei in regola",
«Come pagare»), card situazione/segnalazioni/prossimo appuntamento, comunicazioni, documenti
utili come pillole. Layout **centrato ~880px, menu in alto a 5 voci, niente sidebar**: per chi
consulta, il layout "da sito" è quello giusto. La divisione interna social/gestionale non deve
trasparire. Mobile-first (hero full-width, card impilate, hamburger).

**Requisiti emersi da approfondire (pagina «Pagamenti» del condomino, ancora da mockuppare):**
- visione dettagliata delle rate: elenco completo con stato (pagata/in scadenza/scaduta),
  importi, ricevute/quietanze, dettaglio riparto per rata, storico versamenti;
- pagare facilmente: istruzioni chiare (IBAN copiabile, causale precompilata), «segnala
  pagamento»; pagamento online (PagoPA/PSP) come possibile integrazione 2.x, da valutare a parte.

### Widget dashboard — regole anti-sballamento
1. Altezza governata: liste top 3–5 + «Tutti →», oltre → scroll interno con `max-height`
   (pattern già usato dal widget eventi, `max-h-[420px]`); testi lunghi in `line-clamp`.
2. La griglia non si fida delle card: `align-items: start`, righe indipendenti; mai altezze
   vincolate sulle card di lista.
3. Lo stato vuoto è disegnato, con la stessa altezza minima dello stato pieno.

### Cruscotto gestionale
I widget esistono già (`pages/gestionale/dashboard`): il validatore budget diventa l'eroe a tutta
larghezza (barra segmentata pianificato/integrative/scoperto + suggerimento operativo); tesoreria
con i fondi come partizioni della cassa (convenzione beta.19); due liste separate: «Inbox» (cosa
chiedono gli altri) e «Da sistemare» (cosa chiede la contabilità).

### Guide nelle pagine (PageHeaderGuide)
Le card-guida escono dal flusso: diventano bottone «Guida» nell'header (link alla docs) +
contenuti negli **empty state** (l'unico momento in cui l'utente vuole essere guidato).
⚠️ `PageHeaderGuide.vue` contiene anche breadcrumb e switcher condominio/esercizio: si sfila la
sezione card, **non** si elimina il componente.

## Strategia di rilascio (decisa)

- **1.10 → 1.18**: solo feature (fino alla suite report e stampe). La grafica non rallenta lo sviluppo.
- **Raccomandazione chiave**: adottare **subito i token** (Fase A, 1–2 gg, retrocompatibile) così
  tutto ciò che nasce tra 1.11 e 1.18 — inclusa la suite report — è già sui token e la 2.0 non
  dovrà ridipingere features appena uscite.
- **2.0 (ottobre 2026)**: shell nuova + dashboard + area condomino = il salto visivo, con il
  numero di versione che lo giustifica.

## Tempi stimati (spalmabili, nessuna migrazione DB)

| Blocco | Stima |
|---|---|
| Fase A — token, tabular-nums, StatusChip, pulizia starter kit | 1–2 gg |
| Config di navigazione condivisa | ½–1 g |
| Shell sidebar + impostazione layout | 3–5 gg |
| Restyling menu in alto full-width | 1–2 gg |
| Cruscotto gestionale | 3–5 gg |
| Dashboard social + area condomino | 2–4 gg |
| Rifiniture e regressioni (dark, responsive) | 2–3 gg |
| **Totale realistico** | **≈ 3 settimane** |

## Rischi e contromisure

| Rischio | Contromisura |
|---|---|
| Colori hardcodati (`slate/amber/emerald`) che stonano sul nuovo piano | bonifica meccanica per modulo, guidata dai token |
| Doppia shell = doppio collaudo | config nav unica + toggle come feature flag (default vecchio) |
| vue-select / datepicker fuori tema (già oggi patchati con `!important` in custom.css) | accettarlo per la 2.0; migrazione a combobox/calendar shadcn post-release |
| Perfezionismo che allunga i tempi | time-box: Fase A subito, una sola shell rifinita per la 2.0, il resto in 2.0.x |

<!-- rettifiche-non-ancorate -->

## ⚠️ Rettifiche non ancorate (31/07/2026)

Correzioni verificate sul codice che non è stato possibile agganciare a una riga precisa di questo documento. Valgono per l'intero testo.

- **Il documento afferma:** «importi senza tabular-nums né allineamento a destra» — presentato come diagnosi generale dello stato del codice.
  **Realtà:** Vero come tendenza, falso come affermazione assoluta: tabular-nums è già usato in una ventina di file, incluse pagine contabili centrali. La diagnosi va riformulata come 'uso non sistematico', altrimenti la bonifica della Fase A parte da un censimento sbagliato.
  *Prova:* 47 occorrenze di 'tabular-nums' in resources/js/, fra cui resources/js/pages/gestionale/movimenti/scritture/List.vue, resources/js/pages/gestionale/contributi/ContributiList.vue, resources/js/pages/gestionale/movimenti/pagamenti/PagamentoShow.vue, resources/js/components/gestionale/casse/columns.ts, resources/js/pages/gestionale/dashboard/Dashboard.vue.

<!-- /rettifiche-non-ancorate -->
