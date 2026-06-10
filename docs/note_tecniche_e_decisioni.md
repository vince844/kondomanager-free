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

## Note varie

_(da popolare)_