# Note tecniche e decisioni — Kondomanager

Raccolta di vincoli di design, decisioni e note di conformità che **non** sono feature
con una versione assegnata (quelle stanno nella roadmap), ma promemoria da tenere
presenti quando si tocca l'area relativa.

---

## Conformità normativa

### Ripartizione spese in condominio parziale — Cass. civ. Sez. II, ord. n. 1095/2026

**Principio.** Le spese per parti comuni che servono solo alcuni condòmini (es. colonna
di scarico di una verticale) vanno ripartite *in proporzione ai millesimi* tra i soli
beneficiari (art. 1123, comma 1, c.c.), **mai in parti uguali**. L'assenza di tabelle
millesimali parziali non legittima il riparto "per teste": si usano i millesimi generali
ricalcolati sul gruppo (semplice operazione aritmetica — per ciascuno: i suoi millesimi
diviso la somma dei millesimi del gruppo). Il riparto in parti uguali è lecito solo se
previsto da regolamento contrattuale o deliberato all'unanimità (deroga ai criteri legali).

**Come lo gestisce Kondomanager — già conforme da v1.9.5.** Si crea una tabella
millesimale dedicata con le sole unità beneficiarie e, come valore, i loro millesimi
generali *grezzi*. Non serve riproporzionare a 1000 manualmente:
`CalcoloQuoteService::distribuisciSuTabelle()` normalizza in automatico —
`valore / sommaValori` (somma effettiva dei valori in tabella) e, a valle,
`peso / pesoTotale` — poi `distribuisciImporto()` ripartisce l'intero importo.
Il motore esegue quindi da sé l'operazione aritmetica richiesta dalla Cassazione.
Vale sia per il motore ordinario sia per quello straordinario (stesso core di distribuzione).

**Evoluzione — v1.9.10 (Tables Infrastructure).** Comando "Genera tabella parziale dai
millesimi generali per le unità selezionate": seleziona i beneficiari → il sistema eredita
i loro millesimi generali. Implementa letteralmente il principio, toglie il calcolo manuale
e impedisce all'amministratore di creare per errore una tabella egualitaria (il caso che
l'ordinanza sanziona). Possibile argomento autentico per forum/changelog.

---

### Stampe — blocco dati amministratore configurabile (L. 4/2013 · art. 1129 · art. 71-bis disp. att.)

**Principio.** Non hardcodare nelle stampe diciture come "professione esercitata ai sensi
della L. 4/2013": valgono solo per chi opera come professionista sotto quel regime. Una
dicitura fissa taglia fuori l'**amministratore-condòmino** (che ex art. 71-bis disp. att. non
ha nemmeno l'obbligo di formazione quando è nominato tra i condòmini dello stabile) e le
**società di servizi**.

**Decisione di design.** Rendere il blocco con i dati dell'amministratore in fondo alle
stampe **completamente configurabile** — testo libero o campi strutturati impostati una volta.
Così il professionista ci mette riferimento L. 4/2013, P.IVA e polizza; il
condòmino-amministratore solo i suoi dati; la società la propria ragione sociale. Nessuna
formula imposta dal sistema.

**Conformità.** Coerente con l'art. 1129 c.c. (dati anagrafici/professionali e luogo di
conservazione dei registri da indicare). Si aggancia naturalmente alle **Stampe Essenziali**.

---

### Libro giornale — immutabilità *a soglia*, non assoluta (art. 1130 n. 7 c.c.)

**Quadro normativo.** Il registro di contabilità (art. 1130 n. 7) è documento obbligatorio:
annotazione cronologica dei movimenti **entro 30 giorni**. L'estratto conto bancario ne è
semmai il *supporto*, non il sostituto (l'idea "i 30 giorni li assolve l'estratto conto",
diffusa nei corsi, è interpretazione difendibile ma non pacifica — unica parte eventualmente
da verificare con un legale, ma non cambia la conclusione tecnica).

**Punto chiave.** Nessuna norma impone che il giornale *del software* sia tecnicamente
immodificabile. L'obbligo è **tenere** il registro e **annotare in tempo**, non il divieto di
correggere. L'immutabilità è quindi una **scelta di integrità**, non un vincolo di legge.

**Decisione di design — blocco a soglia.** Le scritture sono liberamente modificabili finché
sono **"aperte"** (bozza / non registrate, gestione aperta, riga non riconciliata né inclusa
in un bilancio approvato). Una volta **registrate/bloccate** o a **gestione chiusa**, diventano
immutabili e si corregge **via storno**. Si protegge l'integrità dove serve davvero — storico,
riconciliato, approvato — togliendo l'attrito sui refusi di giornata (oggi un errore appena
digitato richiede storno + riscrittura, pura perdita di tempo).

**Coerenza interna.** Il Treasury Guardian deriva la liquidità *dal giornale* ("il libro è la
verità"): motivo in più per blindare i dati **finalizzati**, non per bloccare la correzione di
un errore fresco.

---

### Bilancio approvato — niente riapertura, correzione via sopravvenienza (art. 1137 · art. 2948 c.c.)

**Principio.** Un bilancio **approvato dall'assemblea non si riapre per riscriverlo**: la
delibera ha effetti, e riscrivere a ritroso farebbe saltare la catena degli esercizi
successivi e i riparti già addebitati.

**Meccanismo corretto.** Ciò che emerge dopo si sistema **nell'esercizio in corso** con una
**sopravvenienza** (attiva o passiva). L'errore "salta fuori" e si corregge nell'anno corrente,
non rifacendo la storia; le scritture degli esercizi nel frattempo restano valide — ed è
proprio per questo che non si riapre.

**Impugnazione.** I 30 giorni dell'art. 1137 riguardano l'**annullabilità**; un vizio che
emerge molto dopo (es. due anni) reggerebbe solo come **nullità** (senza termine). Anche se il
giudice annulla e l'assemblea ridelibera, la gestione resta **"in avanti"** (conguagli,
sopravvenienze), **non** con la riapertura letterale del periodo a DB.

**Stato.** L'architettura sopravvenienze (attiva/passiva in doppia partita) è la base corretta.
Gli edge case — in particolare gli effetti di un annullamento giudiziale — vanno disegnati con
cura nel **Year End Master**.

---

## Guardrail UX da implementare

- **Modalità "parti uguali" (se mai introdotta).** Segnalare in interfaccia che il riparto
  in parti uguali è legittimo *solo* con regolamento contrattuale o delibera all'unanimità
  (deroga ai criteri legali ex art. 1123 c.c.). Serve a non indurre l'amministratore
  nell'errore sanzionato da Cass. 1095/2026.

---

## Architettura tabelle — ufficiali vs ripartizioni di calcolo

*(rilevante quando si sviluppa la Tables Infrastructure — vedi nota versioni in fondo alla sezione)*

**Distinzione da introdurre.** Separare due concetti che oggi nel modello dati coincidono:

- **Tabelle ufficiali del condominio** — la generale e quelle allegate al regolamento
  (scale, ascensore, riscaldamento). Stabili e governate: si toccano solo nei casi
  dell'art. 69 disp. att. c.c. (errore, o mutamenti che alterano oltre 1/5 il valore
  proporzionale anche di una sola unità). Approvazione/revisione a maggioranza qualificata
  (art. 1136, c. 2) quando applicano i criteri legali — Cass. SS.UU. 18477/2010; unanimità
  solo per le tabelle convenzionali che derogano ai criteri.
- **Ripartizioni di calcolo** — derivate, per spese a uso parziale e una tantum. Non sono
  tabelle ufficiali e non vanno trattate (né governate) come tali.

**Approccio corretto per le spese a uso parziale** (es. colonna di scarico — Cass. 1095/2026):
non creare una nuova tabella ufficiale, ma derivare il riparto di *quella* spesa dai
millesimi generali già esistenti, ristretti alle unità interessate, a livello di spesa/piano.
Perché:

- non introduce alcun criterio nuovo → pura applicazione aritmetica dell'art. 1123, c. 1.
  L'approvazione delle tabelle è una presa d'atto, non la fonte dell'obbligazione
  (SS.UU. 18477/2010): quindi **niente nuova tabella e niente delibera ad hoc** — l'assemblea
  approva spesa e rendiconto, il riparto proporzionale tra i beneficiari si applica per legge;
- tiene pulite le tabelle ufficiali, che non vanno popolate di un oggetto per ogni spesa
  una tantum.

**Stato attuale (v1.9.5).** La via odierna — creare una tabella parziale con i millesimi dei
beneficiari — dà i numeri giusti ma lascia un oggetto-tabella permanente. Target: trattare la
ripartizione parziale come *derivata* (comando "genera dai millesimi generali sulle unità
selezionate"), distinta dalle tabelle ufficiali nell'UI e nel modello dati.

> **Nota versioni.** Questo è lavoro sull'area *tabelle* → sulla roadmap è la
> **v1.9.10 (Tables Infrastructure)**, non la v1.10.0 (che è la migrazione dell'installer in
> `Kondomanager\Installer`). Confermare dove agganciarlo.

---

## Validatore Coerenza Millesimi — fail-fast multilivello (Tier 1)

*(È l'automazione base, priorità assoluta Tier 1; sulla roadmap sta nell'area v1.9.x. Qui sono
fissate le decisioni di design e i guardrail; lo scheduling resta in roadmap. Il precursore
leggero — totale in fase di inserimento — è anticipabile senza attendere la feature completa.)*

**Filosofia.** Approccio **fail-fast** per prevenire gli errori silenziosi, lasciando sempre
un'uscita di sicurezza **tracciata**. Cinque fronti:

1. **Fronte Operativo (Emissione Rate).** Blocco di sistema in fase di emissione rate se le
   tabelle non quadrano: precisione matematica garantita *prima* di generare movimenti
   contabili. È il gate duro, posto al punto di non ritorno.
2. **Fronte Interfaccia (Dashboard).** Widget visivo in dashboard che segnala subito le
   incongruenze, prima di arrivare alle operazioni critiche. *Precursore leggero anticipabile:*
   totale corrente + scarto già durante l'inserimento ("998/1000 — mancano 2",
   "1003/1000 — eccesso di 3").
3. **Fronte Eccezioni (Forzatura Tracciata).** Override manuale per bypassare il blocco nei
   casi eccezionali (emergenze, tabelle storiche consolidate che sfuggono alla perfezione
   matematica). Ogni forzatura **registrata nei log**. Coerente con la linea "advisory con
   override" del progetto; la forzatura **richiede una nota/motivazione** (stesso pattern della
   nota obbligatoria già adottato altrove, es. Piano Rate «Annullato»), non solo un click.
4. **Fronte Gestione Dati.** "Griglia Valori" per editing massivo e veloce dei millesimi +
   import da **Excel**. Si lega al comando "Genera tabella parziale dai millesimi generali"
   della Tables Infrastructure (v1.9.10).
5. **Fronte Diagnostico.** Log diagnostici precisi per individuare la **riga/quota esatta** che
   fa sballare il calcolo.

> **Attenzione al concetto di "quadrare".** Il riferimento dipende dal **tipo di tabella**: la
> tabella di proprietà generale valida su **1000**; le tabelle speciali (scale, riscaldamento,
> coefficienti) possono avere un totale diverso o basato su coefficienti. La verifica va
> parametrizzata per tipo tabella, altrimenti genera **falsi positivi** proprio dove serve di più.

**Connessione normativa.** Blindare la coerenza dei millesimi presidia anche l'art. 1123 c.c. e
Cass. 1095/2026 (riparto proporzionale corretto tra i beneficiari) — vedi *Conformità
normativa*.

**Obiettivo.** Integrità dei dati contabili blindata, senza paralizzare il lavoro
dell'amministratore.

---

## Conservazione dati e portabilità

- **Tutti gli esercizi restano in database, per scelta.** Continuità storica, confronti anno
  su anno, consultazione a distanza di anni e copertura della conservazione documentale
  (cfr. prescrizione quinquennale ex art. 2948 c.c. sui contributi, su cui poggia la
  *Sentinella della Prescrizione*). L'amministratore **non è costretto a cancellare nulla**.
- **Esportazione/backup sempre possibili** a fine esercizio, in parallelo. Backup di base
  sempre gratuito; automazione, schedulazione e storage remoto col **Backup Pro**.
- **Filosofia.** Niente lock-in, formati aperti: i dati sono dell'amministratore e li porta via
  quando vuole.

---

## Note varie

_(da popolare)_