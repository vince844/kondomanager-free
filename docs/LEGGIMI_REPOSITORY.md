# Questa cartella è un repository a sé

Aperto il **21/08/2026**, aprendo la beta.64.

## Perché esiste

`docs/` contiene 47 documenti interni per oltre 33.000 righe: la roadmap, il documento di processo,
le analisi, i piani di lavoro. Fino a oggi vivevano **solo** come file su due Mac, senza storia,
senza `diff`, senza possibilità di recupero. Il repository pubblico del prodotto li esclude di
proposito — regola invertita in `.gitignore`, righe 33-37 — e quell'esclusione è giusta e resta.

Ma «fuori dal repository pubblico» era diventato «fuori da qualunque repository», e sono due cose
diverse che nessuno aveva mai distinto.

Il 21/08/2026 sono successe due cose nello stesso pomeriggio:

1. Una sessione ha scritto in `roadmap.md` la **Coda 64** mentre un'altra copiava la propria
   `roadmap.md` da TEST verso la cartella ufficiale. Non si sono sovrascritte **per una questione
   di orario**: la scrittura è arrivata dopo la copia. Al contrario, il lavoro sarebbe sparito
   senza lasciare traccia e senza che nessuno potesse accorgersene.
2. Ricostruendo una modifica si è dovuto rinunciare a verificare cosa un documento **dicesse
   prima**, perché quella storia non esisteva da nessuna parte.

## Cosa NON sta qui dentro

I dieci file che il repository pubblico traccia già — `changelog.md`, le guide Docker, Synology e
Plesk — sono in `.gitignore` qui. Tenerli in due repository vorrebbe dire due storie per lo stesso
file, e prima o poi due versioni diverse senza che nessuno se ne accorga. **La loro fonte di verità
resta il repository pubblico.**

## Come sono collegate le due cartelle

L'origine è il repository **privato** `vince844/kondomanager-docs` su GitHub, quindi la storia non
vive su questo Mac: se il disco si rompe, resta.

    TEST      /Users/vincenzo/Desktop/kondomanager-free/docs            ─┐
                                                                         ├─► github.com/vince844/kondomanager-docs (privato)
    UFFICIALE /Users/vincenzo/Desktop/KondoManager/kondomanager-free/docs ─┘

⚠️ **Perché non nel repository del sito**, che pure è già privato: il suo `Dockerfile` fa
`COPY . /var/www/html/`, quindi è un **artefatto di pubblicazione** — tutto ciò che è committato e
non escluso dal `.dockerignore` finisce nella document root del server web. Metterci dentro
strategia commerciale, analisi dei concorrenti e piani di prezzo vorrebbe dire tenerli fuori
dall'internet aperto con **una riga di configurazione**.

Si allineano con `git push` e `git pull`, **non più con `cp`**. La differenza che conta non è la
comodità: è che una scrittura concorrente ora produce un **conflitto visibile** invece di una
sovrascrittura silenziosa.

## Due avvertenze

⚠️ **`git clean -fdx` qui dentro cancella i dieci file pubblici**, perché per questo repository
sono file ignorati. Non lanciarlo in questa cartella.

⚠️ **«Privato» è un fatto da verificare, non da ricordare.** Controllato il 21/08/2026 con una
richiesta anonima: `https://api.github.com/repos/vince844/kondomanager-docs` risponde **404** e
anche il `raw` di `roadmap.md` risponde 404. Se un giorno qualcuno rendesse pubblico il
repository, quei due comandi risponderebbero 200 — e sono l'unico modo di accorgersene.
