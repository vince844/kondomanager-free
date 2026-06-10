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

## Note varie

_(da popolare)_