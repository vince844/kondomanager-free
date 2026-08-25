# Rilascio di una versione stabile — il sito e il canale di aggiornamento

<!-- verifica-documentazione -->
> **Stato:** Descrive il processo concordato — scritto il **22/08/2026** su `1.10.0-beta.66`, in
> preparazione della **1.10.0** finale. Nasce staccando la **Fase 7** da
> [`flusso_di_lavoro_rilascio.md`](flusso_di_lavoro_rilascio.md), dove stava a riga 2636 di 2720,
> cioè in fondo al documento che descrive il processo delle *beta*: il punto che si legge di meno,
> per una procedura che si esegue una volta ogni due mesi e che tocca **ogni installazione al
> mondo**.
>
> Tutti i numeri citati qui sono stati misurati il 22/08/2026 con i comandi che stanno accanto a
> ciascuno. **Si rimisurano, non si ricordano**: dove c'è un comando, vale il comando.
>
> Questo documento **non sostituisce** `flusso_di_lavoro_rilascio.md`, che resta l'unico documento
> di processo: se una regola qui contraddice quella, vale quella. Questo copre il solo passaggio
> beta → stabile.
>
> ➕ **Aggiornato lo stesso giorno, 22/08/2026, dopo aver costruito le due prime pagine della 1.10.0**
> — `landing/versione-1.10.0.html` e la sezione `v1.10.0` di `docs/changelog.html`. Da quel lavoro
> nascono il **§4-bis** (le sei trappole che il modello della landing precedente porta dentro, tutte
> mute: nessuna dà errore) e la decisione registrata nel **§5** sulla forma della sezione di
> changelog. Cifre misurate scrivendo: **13 marcatori `BETA-1.10` in 9 file**, più **quattro file**
> che dicono la stessa cosa senza marcatore; **256 righe in 87 file** contengono «1.9.1», di cui 173
> nella forma `v1.9.1`; **14** estensioni per PhpSpreadsheet contro le 6 dichiarate nel manifest;
> **86** indirizzi in `sitemap.xml`, diventati 87 con la voce della landing.
<!-- /verifica-documentazione -->

**Principio:** una versione stabile non è una beta più grande. È l'unico momento in cui si tocca il
**grilletto** che fa comparire il pulsante «aggiorna ora» su ogni installazione esistente. Da lì in
poi non si può più correggere in silenzio: quello che è sbagliato lo vede chi aggiorna, e lo vede
tutto insieme.

---

## 1. Il grilletto

Il file `packages/latest.json` del sito **è il canale di aggiornamento del prodotto**. Ogni
installazione interroga `https://kondomanager.com/packages/latest.json` — l'indirizzo è cablato in
`app/Services/UpdateService.php` — confronta `latest_stable` con la propria `config('app.version')`
e, se è più vecchia, propone l'aggiornamento.

> ⚠️ **`latest_stable` è l'ultima riga che si tocca, mai la prima.** Cambiarlo aggiorna ogni
> installazione esistente. Non ci va mai una beta, e non ci va una versione il cui pacchetto non sia
> già caricato, verificato e con il suo `hash`.

Il contratto del manifest, letto dal codice:

| campo | a cosa serve |
| :--- | :--- |
| `latest_stable` | la versione che fa scattare l'aggiornamento |
| `releases[]` | il dettaglio, cercato **per `version` corrispondente a `latest_stable`**: se manca la voce, l'aggiornamento non parte |
| `hash` | SHA256 del pacchetto, **obbligatorio**: senza, `UpdateService` rifiuta l'aggiornamento automatico |
| `requirements.php` | **è il cancello**: il bridge installato lo usa come soglia e respinge chi sta sotto |
| `size`, `date`, `stability`, `url`, `description`, `notes_url`, `exclude[]` | il resto del dettaglio |

### La regola che costa di più, e che non è evidente

**L'aggiornatore che gira è quello della versione che si lascia, non quello che si pubblica.** Chi è
sulla 1.9.1 aggiorna con il codice della 1.9.1: ogni correzione fatta all'updater durante il ciclo
governa il salto *successivo*, non questo. Ne seguono due conseguenze operative:

- il bridge va **collaudato deliberatamente**, perché durante le beta non lo esegue nessuno
  (dettaglio e scenari in [`aggiornamento_universale.md`](aggiornamento_universale.md) §6 e §8);
- se il codice nuovo corregge un difetto dell'aggiornamento, quel difetto **colpisce comunque** chi
  aggiorna adesso: va scritto nelle note di rilascio, non solo corretto.

---

## 2. L'ordine è vincolato

Non è una lista di cose da spuntare in ordine libero. Quattro archi sono obbligatori, e ognuno ha
già prodotto un guasto quando è stato invertito:

```
changelog di prodotto  →  docs/changelog.html del sito  →  notes_url
        pacchetto caricato  →  hash calcolato  →  releases[]  →  latest_stable
```

- **`releases[]` prima di `latest_stable`.** In quest'ordine: se si aggiorna prima `latest_stable`,
  per il tempo che passa fra i due salvataggi le installazioni vedono una versione nuova **senza il
  suo dettaglio**, e l'aggiornamento non parte.
- **`docs/changelog.html` del sito prima di pubblicare `notes_url`.** Altrimenti l'utente clicca su
  note di rilascio che non parlano della versione che sta installando.
- **L'hash si calcola sul file caricato**, non sul file locale: se i due divergono, l'aggiornamento
  automatico viene rifiutato da tutti insieme.
- **Il tag git prima del pacchetto**, così che il pacchetto sia ricostruibile da un punto nominato.

---

## 3. Le quattro categorie del lavoro sul sito

È la distinzione che risponde alla domanda *«il sito lo aggiorniamo adesso o dopo?»*. Non ha una
risposta sola, perché il lavoro non è tutto della stessa specie.

| | categoria | quando si fa | perché |
| :--- | :--- | :--- | :--- |
| **(a)** | **Dipende dal numero di versione** — `latest.json`, i badge, `docs/changelog.html`, le occorrenze editoriali della versione vecchia | **solo dopo il tag**, in un colpo solo | l'ordine è imposto dal §2 e `latest_stable` è il grilletto |
| **(b)** | **È falso adesso e sarà falso al contrario dopo** — i riquadri «questa pagina descrive la 1.10, quella che scarichi è la 1.9.1» | **il giorno del rilascio** | indifferente all'ordine, ma va fatto tutto insieme o restano pagine che si contraddicono fra loro |
| **(c)** | **Fa danno adesso** — requisiti dichiarati più alti di quelli veri, avvisi mancanti sul pulsante «aggiorna ora» | **subito, senza aspettare** | ogni giorno che passa respinge qualcuno che potrebbe installare |
| **(d)** | **Non dipende dal numero** — landing della versione, articolo di annuncio, copertina OG, consolidamento del changelog nelle tre lingue | **prima, quando c'è tempo** | è la metà del lavoro, e il giorno del rilascio non c'è spazio per scriverla |

**La conseguenza pratica:** si comincia dal sito **prima** del rilascio, ma solo su (c) e (d). Fare
(a) in anticipo pubblica una bugia; rimandare (d) al giorno del rilascio è il motivo per cui i
rilasci slittano.

> ⚠️ **«Solo dopo il tag» vale per la pubblicazione, non per il commit — e sono due cose diverse
> quando il repository è privato e il deploy è un passo separato.** Corretto il 22/08/2026: se il
> commit su GitHub e il deploy sul server sono due azioni distinte nel tempo (qui: commit quando è
> pronto, deploy il giorno del rilascio), la categoria (a) si può **scrivere e committare in
> anticipo** — resta invisibile finché non c'è il deploy. La sola cosa che non si può anticipare è
> ciò che dipende da un fatto che non esiste ancora: l'hash di un pacchetto non costruito, un
> pulsante che punta a uno shortlink non ancora ripuntato. Quello si scrive quando il fatto esiste,
> a prescindere da quando si fa il commit.
>
> **Esempio reale:** il pulsante «Scarica l'installer» di `installation-wizard.html` punta allo
> shortlink `https://kondomanager.short.gy/km-installer`, che oggi serve ancora il pacchetto della
> 1.9.1. L'**etichetta** («v1.10.0») si è potuta scrivere e committare il 22/08/2026, prima del
> rilascio, perché il repository resta privato fino al deploy. Il **bersaglio dello shortlink**
> resta bloccato fino a mercoledì: va ripuntato al pacchetto vero **nello stesso deploy** in cui la
> pagina va online, altrimenti per la finestra fra i due l'etichetta mente.

C'è una quinta categoria che si tocca **mai**: la **storia**. `docs/changelog.html` e
`landing/versione-<vecchia>.html` contengono il numero di versione precedente perché *parlano di
quella versione*. Una sostituzione meccanica su tutto il repository le riscrive e le rende false.

---

## 4. Prima del giorno — quello che si può fare in anticipo

- [ ] **La landing della versione.** `landing/versione-<versione>.html`, sul modello della
      precedente. Verificato il 22/08/2026: la cartella contiene `versione-1.9.0.html` e
      `versione-1.9.1.html`, quest'ultima da 125 KB con JSON-LD, sezione novità, FAQ e CTA. Se si
      decide di uscire **senza** landing, va scritto come decisione: è la differenza fra due giorni
      di lavoro e zero.
      ```bash
      ls landing/
      ```
- [ ] **I link entranti alla landing** vanno aggiornati insieme a lei, o puntano ancora alla
      precedente: al 22/08/2026 sono in `index.html`, `gestionale-condominio-contatti.html` e
      `la-nostra-filosofia.html`.
      ```bash
      grep -rn "landing/versione-" --include="*.html" . | grep -v "^./landing/"
      ```
- [ ] **L'articolo di annuncio** e la sua card in `blog.html`.
- [ ] **La copertina OG** — `src/genera-og.sh` con `src/og-template.html`.
- [ ] **La riga in `sitemap.xml`** per ogni pagina nuova. Al 22/08/2026 il file dichiara **86**
      indirizzi: `grep -c "<loc>" sitemap.xml`.
- [ ] **Il consolidamento del changelog nelle tre lingue.** Vedi §5, «la decisione che viene prima
      dello script»: è la voce più sottostimata di tutte, e non dipende dal numero di versione.
- [ ] **La sezione «cosa arriva nella prossima versione»** della homepage, se esiste: alla vigilia
      di un rilascio elenca come *pianificate* funzioni che sono già uscite.

---

## 4-bis. Costruire la landing dal modello — le trappole del modello stesso

**Scritto il 22/08/2026, costruendo `landing/versione-1.10.0.html` da `versione-1.9.1.html`.** La
landing precedente è il modello giusto, ma porta dentro sei difetti che nessuno aveva mai visto
perché **nessuno di essi dà errore**. Vanno tolti nella pagina nuova, non ereditati.

- [ ] ⛔ **La versione non si sostituisce con una regex sul numero nudo.** Nella 1.9.1 una
      sostituzione «1.9» → «1.9.0» ha prodotto `clamp(1.9.0rem,3.5vw,2.8rem)` alla riga 1116: un
      valore CSS invalido, quindi la dimensione del titolo delle FAQ ricade sul predefinito. È in
      produzione da mesi e non se n'era accorto nessuno. Il rischio della 1.10 è peggiore, perché
      «1.10» contiene «1.1» e i dati `path d="…"` degli SVG impacchettano i decimali senza
      separatore (`c0-1.1.9-2`): una sostituzione cieca romperebbe **le icone**, in modo invisibile
      a chi non le guarda. **Si scrive la pagina a mano.** Se proprio si sostituisce, il pattern va
      ancorato al contesto (`versione 1.9.1`, `v1.9.1`, `-1.9.1.html`), mai il numero nudo.
      ```bash
      grep -nE 'clamp\([^)]*\)' landing/versione-<versione>.html
      grep -rnE '[0-9]+\.[0-9]+\.[0-9]+(rem|em|px|vw|vh|s|%|ms)' --include="*.html" --include="*.css" .
      ```
- [ ] **`@keyframes fadeUp` non esiste** né in `assets/css/kondo.css` né nello `<style>` della
      landing: le sei animazioni d'ingresso dell'hero sono **inerti**. La definizione corretta è già
      scritta in cinque pagine del sito (per esempio `la-nostra-filosofia.html`): si copia quella
      riga, non se ne inventano i valori.
- [ ] **Il menu su telefono non si apre.** Il bottone `#mobileBtn` c'è e il menu nasce `hidden`, ma
      nelle landing manca il gestore del clic. Sta in `index.html` e si copia da lì. È il difetto
      del modello che incontra più gente: metà del traffico è da telefono.
- [ ] **Le risposte lunghe delle FAQ vengono tagliate in silenzio.** `.faq-body.open` ha
      `max-height:400px` con `overflow:hidden`: su uno schermo da 375px una risposta di quattrocento
      caratteri supera quel tetto e finisce a metà, senza errore e senza indizio. Nella pagina nuova
      va alzato (1200px va bene), **e la pagina va aperta a 375px con tutte le FAQ aperte** prima di
      consegnarla.
- [ ] **Le landing non hanno il blocco `FAQPage`** nello structured data, mentre 33 altre pagine del
      sito ce l'hanno. Si aggiunge, copiando la forma da `index.html`. ⚠️ **Si scrivono prima le FAQ
      visibili e poi si genera il blocco da quelle**, mai il contrario: è così che le due copie
      restano allineate. Mancano anche `og:site_name` e `twitter:description`, presenti in 39 file.
- [ ] ⛔ **Non copiare `aggregateRating` dalla home** (`ratingValue` 5, `reviewCount` 1,
      autoassegnato): su una pagina di prodotto è markup di recensione fabbricato, ed è la categoria
      che prende azioni manuali da Google.
- [ ] **I numeri d'effetto vanno controllabili.** La 1.9.1 dichiara «500+ download totali», ripreso
      dalla 1.9.0 e aggiornato l'ultima volta da uno script che sostituiva «400+» con «500+»: è
      l'unico numero della pagina che nessuno può verificare, su un sito la cui tesi è *«non devi
      fidarti, puoi controllare»*. O si trova il dato vero, o la statistica esce. Stessa famiglia:
      la 1.9.1 dichiara **tre** conteggi diversi delle proprie novità (50+, 50+, 42+) mentre gli
      item della lista sono 82.
- [ ] **Lo strumento di conformità esiste già e va usato:**
      ```bash
      ./src/verifica-pagina.sh landing/versione-<versione>.html landing/versione-<precedente>.html
      ```
      Con il secondo argomento elenca i blocchi di testo **ancora identici al modello**: sono quelli
      che la clonazione ha portato dentro e nessuno ha riscritto.
- [ ] **I controlli di pubblicazione si fanno sul testo visibile, non sul file.** Cercare `**` o i
      backtick sul sorgente dà venti falsi allarmi che stanno dentro i commenti HTML e i commenti
      CSS. Si toglie prima `<!--…-->`, `<script>` e `<style>`, poi si cerca.

### Le sezioni della homepage che parlano di una versione

⚠️ **Trovate il 22/08/2026, e sono due, non una.**

La prima era una **roadmap con barra di avanzamento** — «in sviluppo 1 feature, pianificato 2, in
progettazione 2» — che alla vigilia del rilascio elencava come *da fare* cinque funzioni **tutte
già uscite**: il backup nella beta.11, i giroconti nella beta.19, il totale dei millesimi nella
beta.48, il modulo stampe addirittura nella 1.9.1, cioè nella versione che la sezione stessa
dichiarava in produzione. Un commento dentro una delle carte raccontava che **lo stesso errore era
già stato fatto a giugno e corretto solo a metà**.

La seconda erano 170 righe che vendevano la versione precedente come «Versione 1.9.1 — ora
disponibile», con le sue funzioni elencate una per una.

**La correzione non è aggiornare i numeri: è togliere la dipendenza dalla versione.** Le due sezioni
hanno cambiato mestiere il 22/08/2026:

- la roadmap è diventata **«che cosa c'è nella versione»** — senza stati, senza percentuali, senza
  date, con un rimando alla landing per l'elenco completo. È l'unica sezione della home che nomina
  una versione, e quindi l'unica da toccare al rilascio;
- la sezione delle funzioni ha **perso il numero di versione**: descrive che cosa il programma fa, e
  quelle funzioni restano al loro posto a ogni rilascio. Il suo rimando finale porta alla pagina
  delle funzioni, non a quella della versione.

> **La regola generale.** Una sezione che nomina una versione va manutenuta a ogni rilascio; una che
> descrive un comportamento no. Se il contenuto è vero indipendentemente dal numero, **il numero non
> va scritto**. Una roadmap su una pagina pubblica è una promessa che nessuno rilegge: invecchia da
> sola, in silenzio, e questa lo ha fatto due volte.

⚠️ **Attenzione a non buttare il ruolo insieme alla cornice.** Togliendo il numero di versione dalla
sezione delle funzioni si è persa anche la cosa che quella sezione faceva ed era giusta: il
**riassunto della versione nuova con il pulsante alla landing, subito sotto l'hero**. Il rimedio non
è rimettere il numero là: è **spostare in quella posizione la sezione della versione**, che quel
mestiere lo fa già. Ordine finale della home: hero → che cosa c'è in questa versione (con il rimando
alla landing) → che cosa fa il programma (senza numeri) → il resto.

- [ ] **Il badge di versione in nav e footer: 122 occorrenze in 80 file**, e ce ne sono altre **7 in
      6 file** con l'attributo `class` andato a capo, che una sostituzione su stringa esatta **non
      trova**. Servono due passate, la seconda con un'espressione regolare ancorata al contenitore:
      ```bash
      grep -rc 'bg-indigo-600 text-white">v<vecchia><' --include="*.html" . | grep -v ":0"
      ```
      ⚠️ Il badge si cambia **ovunque**, anche nelle pagine che parlano di versioni vecchie: è la
      cornice del sito e mostra sempre l'ultima stabile. Quello che non si tocca è il **contenuto**
      di quelle pagine, dove il numero è storia. Al 22/08/2026 fuori dai badge restano **44
      occorrenze in 17 file**, tutte da leggere una per una: sedici sono nell'annuncio della 1.9.1 e
      vanno lasciate.
- [ ] **Lo sweep si può fare in anticipo**, se il sito si pubblica tutto in una volta: mercoledì
      resta solo ciò che dipende dal pacchetto. Va fatto **solo** se nessuno dei file pubblica prima
      del tag.

- [ ] **Le altre cose datate della home**, da controllare nello stesso passaggio:
      `softwareVersion` nel JSON-LD, `dateModified` (era fermo al 2025), le risposte delle FAQ che
      aprono con «con la versione 1.9 sì», la statistica «versione attuale» dell'hero, i due
      rimandi alla landing (corpo e footer) e i badge di nav e footer.
- [ ] ⚠️ **Le FAQ della home vanno rigenerate dallo schermo, non ritoccate.** Al 22/08/2026 il
      blocco `FAQPage` dichiarava **tre** domande contro le **cinque** visibili, con risposte
      riscritte a mano e divergenti dal testo della pagina. Rigenerate dalle cinque vere.
- [ ] ⛔ **Un titolo di card non si aggiorna senza aggiornare l'articolo.** In `blog.html` una card
      annunciava «il Modulo Commenti in arrivo» con il badge «In Arrivo» per una funzione uscita
      nella 1.9.1: corretti allineandoli al titolo vero dell'articolo. Ne resta uno di proposito —
      «Perché la v1.8 cambierà il tuo modo di amministrare» — perché è il **titolo dell'articolo
      stesso**, e cambiarlo solo nella card creerebbe la divergenza card/articolo descritta nel
      §4-ter.

---

## 4-ter. L'articolo di annuncio — e perché il precedente è l'anti-modello

**Scritto il 22/08/2026, costruendo l'articolo della 1.10.0.** L'annuncio della versione precedente
sembra il modello ovvio. Non lo è: su quaranta pagine del blog è **l'unica**, insieme allo stub di
redirect, senza il riquadro «articoli correlati» (38 su 40 ce l'hanno), ed è una delle cinque senza
la sezione «Continua a leggere» (35 su 40). Chi lo clona eredita le due lacune.

- [ ] ⛔ **`src/verifica-pagina.sh` dichiarava quel file «conforme alle sorelle».** Non perché
      sbagliasse, ma perché nel dizionario dei marcatori quei due blocchi **non c'erano**: lo
      strumento contava ciò che sapeva cercare. Corretto il 22/08/2026 aggiungendo
      `'articoli correlati': '<!-- Related -->'` e `'continua a leggere': 'MORE ARTICLES'`. Con le
      soglie reali (38/39 e 35/39) entrambe superano il 75 % e ora il vecchio annuncio **fallisce**,
      come deve. È la lezione generale: uno strumento che non trova niente non sta dicendo che va
      tutto bene, sta dicendo che non ha guardato lì.
- [ ] **Costruire l'articolo su un pezzo recente, non sull'annuncio precedente.** Il registro del
      blog è cambiato: gli annunci di versione fino a maggio 2026 usano Title Case all'inglese
      («Nuove Funzionalità», «Bug Fixes», «Testing & Qualità»), superlativi e sezioni «Roadmap».
      Un pezzo narrativo recente ha invece tutti i blocchi e il registro giusto.
- [ ] 🚨 **Niente sezione «Roadmap: cosa ci aspetta ora?».** L'annuncio della 1.9.1 chiudeva
      scrivendo che la versione successiva si sarebbe concentrata *esclusivamente* sulla chiusura
      d'esercizio e sul rendiconto. Non è andata così — quel lavoro sta in v1.17 — e la frase è
      rimasta pubblicata per due mesi a dire il falso. **È il precedente che giustifica la regola**,
      e va raccontato nell'articolo invece che ripetuto.
- [ ] **Quello che nessuno script controlla sono i quattro blocchi di coda**: il sommario laterale,
      il testo di condivisione, il riquadro finale e l'occhiello. Sono i punti dove sopravvive il
      testo del modello. Misurato il 22/08/2026 sul blog: **tre** articoli condividono su X il
      titolo di un altro articolo, **uno** ha il sommario laterale con otto voci su otto morte e
      porta anche l'occhiello di un altro pezzo. Il canonical invece è corretto in 40 file su 40:
      il rischio reale non è l'indirizzo, è **il testo che dice la cosa giusta dell'articolo
      sbagliato**.
      ```bash
      # ogni href="#…" deve corrispondere a un id presente nella stessa pagina
      python3 - <<'EOF'
      import re, glob
      for f in glob.glob('blog/*.html') + glob.glob('docs/*.html'):
          s = open(f, encoding='utf-8').read()
          ids = set(re.findall(r'\sid="([^"]+)"', s))
          rotte = set(re.findall(r'href="#([^"]+)"', s)) - ids
          if rotte: print(f, sorted(rotte))
      EOF
      # il testo di condivisione deve coincidere con l'h1 della propria pagina
      grep -o 'intent/tweet?text=[^&]*' blog/<file>.html
      ```
- [ ] 🚨 **Prima di scegliere l'angolo, guardare che cosa il blog ha già.** Alla prima stesura
      l'articolo della 1.10.0 era stato impostato sul momento in cui un condominio entra nel
      gestionale — un angolo con una SERP libera e parole chiave disgiunte dalle altre pagine, ma
      **su un tema già coperto da due pagine nostre**, la guida in `docs/` e l'articolo del blog
      sulla migrazione. Un annuncio di versione deve annunciare la versione: la funzione di punta
      diventa uno dei paragrafi, non la cornice. La ricerca di parole chiave dice dove c'è spazio su
      Google, non se lo spazio è già occupato da noi: sono due controlli diversi e vanno fatti
      tutti e due.
      ```bash
      # che cosa il blog dice già su questo tema, prima di scegliere la cornice
      grep -ril "<argomento>" blog/ docs/ | xargs -I{} sh -c 'printf "%-58s %s\n" "{}" "$(grep -m1 -oP "(?<=<title>).*?(?=</title>)" {})"'
      ```
- [ ] ⛔ **Un blocco copiato per intervallo di righe va verificato sul bilanciamento dei tag.**
      Prendendo il riquadro della newsletter da un articolo esistente con un intervallo largo cinque
      righe di troppo, sono entrati l'apertura tronca del blocco successivo e **due `div` mai
      chiusi**. La pagina si vedeva bene, il browser chiudeva da sé, `verifica-pagina.sh` la
      dichiarava conforme e nessun controllo diceva niente. Il confronto con le pagine sorelle lo
      rende evidente in un colpo:
      ```bash
      python3 - <<'EOF'
      from html.parser import HTMLParser
      import glob
      VOID = {'meta','link','img','br','hr','input','line','path','polyline',
              'rect','circle','ellipse','polygon','use','source'}
      class V(HTMLParser):
          def __init__(s): super().__init__(); s.stack=[]; s.err=[]
          def handle_starttag(s,t,a):
              if t not in VOID: s.stack.append(t)
          def handle_endtag(s,t):
              if s.stack and s.stack[-1]==t: s.stack.pop()
              elif t in s.stack:
                  while s.stack and s.stack[-1]!=t: s.err.append(s.stack.pop())
                  s.stack.pop()
      for f in sorted(glob.glob('blog/*.html') + glob.glob('docs/*.html') + glob.glob('landing/*.html')):
          v = V(); v.feed(open(f, encoding='utf-8').read())
          if v.err: print(f, 'squilibrati:', v.err[:6])
      EOF
      ```
      Le sorelle danno **zero**: qualunque numero diverso da zero è farina della pagina nuova.
- [ ] **Pubblicare un articolo tocca quattro file, non uno**: `blog/<slug>.html`, la card in
      `blog.html`, la voce in `sitemap.xml` e la copertina in `assets/img/`. La homepage **non**
      elenca articoli e non va toccata; `blog/index.html` è uno stub `noindex` di redirect.
- [ ] **La card «in evidenza» è una sola.** L'annuncio nuovo la prende, e quello della versione
      precedente **scende in griglia**: sono due modifiche, non una, e la seconda si dimentica.
- [ ] **I badge di versione in nav e footer dell'articolo restano quelli dell'ultima stabile**
      finché il rilascio non è pubblicato: viaggiano con gli altri 83 file, non a mano.
- [ ] **Il conteggio delle beta non si fa con `grep -c`.** Otto beta del ciclo 1.10 hanno più di un
      commit, quindi contare le righe dà un numero gonfiato; e due numeri esistono nel changelog
      senza avere un commit proprio. Il numero difendibile è quello dell'ultima beta:
      ```bash
      git log --oneline | grep -oE '1\.10\.0-beta\.[0-9]+' | sort -t. -k4 -n -u | tail -1
      ```
      Meglio ancora: **non dare un conteggio esatto in un testo pubblicato**, perché fra la
      scrittura e la pubblicazione escono altre beta.

---

## 4-quater. Le pagine di funzione — e perché non stanno nella landing di versione

**Scritto il 22/08/2026, costruendo `importazione-dati-condominio.html`.**

- [ ] 🚨 **Una landing di versione non deve tenere le parole chiave di una funzione.** Verificato
      con ricerche vere: cercando «kondomanager 1.9.1» la landing di quella versione **non compare**,
      e non compare nemmeno cercando il proprio slug. A prendersi la query di versione è un
      **articolo di blog su una singola funzione**. Le landing di versione non stanno diventando
      obsolete: non hanno mai posizionato. Quindi le chiavi di funzione parcheggiate là sono
      capitale immobilizzato, e vanno spostate su una pagina **permanente**, in radice come le altre
      verticali. La landing torna sull'unica query che le appartiene: «\<prodotto\> \<versione\>»,
      navigazionale, di chi ha già il software e vuole sapere che cosa contiene e come si aggiorna.
- [ ] **La mappa degli intenti, una pagina per intento.** Prima di scrivere una pagina nuova su un
      tema che il sito già tocca, elencare che cosa c'è: *prodotto* (che cosa fa — la pagina di
      funzione), *procedura* (come si fa — `docs/`), *decisione* (mi conviene — il blog),
      *notizia* (che cosa è uscito — l'annuncio), *versione* (che cosa contiene questa release).
      Cinque intenti, cinque pagine, nessuna sovrapposizione.
      ```bash
      grep -ril "<argomento>" blog/ docs/ *.html | xargs -I{} sh -c 'printf "%-56s %s\n" "{}" "$(grep -m1 -oP "(?<=<title>).*?(?=</title>)" {})"'
      ```
- [ ] ⛔ **Ogni affermazione di prodotto va verificata sul codice, non sul dossier.** In questo giro
      due analisi indipendenti hanno prodotto due affermazioni **false** che sarebbero finite su una
      pagina pubblica: che l'importazione accetti «file Excel per gli altri gestionali» (il codice
      riconosce solo una sorgente) e che un'importazione confermata **non** si possa annullare
      (il codice dice il contrario a metà: l'annullamento non ha una scadenza ma una condizione —
      però **il comando che lo esegue non esiste ancora**, e va detto così). Una pagina di funzione
      che mente è più costosa di una pagina che manca.
- [ ] **Una pagina nuova nasce orfana se non entra nel footer.** Misurato: sul sito c'è già una
      verticale linkata da **3 file soli**, contro le 53 delle sorelle, e la differenza è esattamente
      la voce nel footer «Prodotto». Il blocco esiste in **51 file** con **quattro** forme di `href`
      diverse (`../`, nessun prefisso, `/`, e una variante ridotta): una sostituzione su stringa
      esatta ne prende una manciata. Serve un'espressione regolare che catturi il prefisso e lo
      riusi, più un controllo finale che ogni link risolva a un file esistente.
      ```bash
      grep -rl "<slug-nuovo>" --include="*.html" . | wc -l     # dev'essere ~50, non ~5
      ```

### I riquadri beta si trovano solo per una parte con il marcatore

**Rimossi il 22/08/2026: tredici riquadri con il marcatore, più cinque senza.** Il grep su
`BETA-1.10` dà un numero reale ma parziale. Trovati altrove, con lo stesso contenuto e la stessa
urgenza, ma senza nessun commento sopra:

- una **card sidebar** intera («Versione trattata», badge «beta», bottone «Guida
  all'installazione», link **«Avvisami quando esce la 1.10 →»** verso la newsletter — obsoleto due
  volte, perché promette una data e perché quella data è passata);
- un **badge nell'hero** («v1.10 in beta · Importazione dati»);
- il **corpo di un callout** («Una precisazione sulle versioni: quello che si scarica oggi dal sito
  è la 1.9.1…»), diverso dal riquadro ambra standard e quindi invisibile a quel pattern;
- una **CTA principale** con lo stesso link «Avvisami quando esce» al posto del solito «Accedi alla
  demo gratuita»;
- una **risposta FAQ**, duplicata fra il testo a schermo e il `FAQPage` nel JSON-LD, con la frase
  «se stai usando la versione che si scarica oggi dal sito, il comportamento vecchio c'è ancora».

```bash
grep -rniE "in beta\b|si scarica oggi dal sito|avvisami quando esce|quella che scarichi.*1\.9\.1" \
  --include="*.html" . | grep -v "corretto in beta\.\|scritto in beta\."
```
L'esclusione finale toglie i riferimenti storici legittimi («corretto in beta.17») che restano
veri per sempre e non vanno toccati.

⛔ **Una CTA era clonata da un altro articolo e non se n'era accorto nessuno.** La CTA finale di
`condomino-che-spariva-dal-riparto.html` (sui millesimi vuoti) era identica, parola per parola, a
quella di `ordinamento-tabelle-solo-dieci-righe-visibili.html` (sull'ordinamento): stesso testo
«imposta 50 righe, ordina una colonna», stesso link a `docs/settings.html`. Il rilascio della 1.10
è stata l'occasione in cui qualcuno l'ha finalmente letta per intero — prima il riquadro ambra
sopra copriva la stessa area visiva e distraeva dal resto. **Rimuovere un riquadro beta è anche il
momento di rileggere tutto quello che gli sta intorno**, non solo il riquadro stesso.

### Le statistiche «vere» invecchiano anche loro, e una era già falsa prima del rilascio

**Trovato il 22/08/2026 su `la-nostra-filosofia.html`, sezione «Chi c'è dietro le quinte».** Tre
numeri presentati come dati veri di GitHub — non come copy — e uno era sbagliato **indipendentemente
dal rilascio**: «2+ anni attivo» contro un primo commit del 26/02/2025, cioè **544 giorni, 1,49
anni**. Non è una svista di forma: questa pagina esiste apposta per dire *«non devi fidarti, puoi
controllare»*, ed è il tipo di numero gonfiato che il resto del sito evita.

```bash
git log --reverse --format="%ad" --date=short | head -1   # primo commit
git rev-list --count main                                  # commit totali
git tag | grep -v "sync-i18n" | wc -l                       # release vere, esclusi i tag non di rilascio
```

- [ ] **Non è un controllo da fare una volta.** Ogni numero «vero» su una pagina statica smette di
      esserlo al primo commit successivo. Se una pagina dichiara anni/commit/release come fatti
      verificabili, il comando che li produce va **ri-eseguito a ogni rilascio**, non ricordato.
      Corretti il 22/08/2026: 1+ anno (era 2+), 450+ commit (era 300+, reale 467), 12+ release
      (era 10+, reale 12 tag). Più un badge di versione duplicato in un secondo riquadro statistiche
      sulla stessa pagina, sceso sotto il radar del primo sweep perché non è un badge di nav/footer.
- [ ] **Lo stesso link «Novità v‹vecchia›» può stare in più footer.** Lo sweep dei badge di versione
      copre nav e footer per pattern esatto, ma un link testuale come «Novità v1.9.1 →
      landing/versione-1.9.1.html» è un'altra stringa: trovato duplicato su **due pagine di radice**
      diverse (`gestionale-condominio-contatti.html` e `la-nostra-filosofia.html`), non aggiornato
      dallo sweep automatico perché non è un badge pillola.

### Il sottomenu nella nav: perché non si fa

⛔ **`update_nav.py` non è uno strumento sicuro, e rilanciarlo oggi farebbe un danno misurato.**
Lo script legge la nav da `index.html` e la riscrive in tutte le pagine aggiustando i `../`. Simulato
a secco il 22/08/2026: **89 file riscritti**, e le **35 pagine `docs/` perderebbero 237.251
caratteri**, perché su quelle il menu mobile sta *dentro* `<nav>` e contiene l'intero albero delle
guide. `genera_sidebar_docs.py` non ripara, perché cerca un'ancora che a quel punto non c'è più.

In più: non esiste nessun JS condiviso (il toggle mobile è inline in 89 pagine), le classi
`group-hover:block|visible|flex` **non sono compilate** in `kondo.css`, la nav ha già 7 voci con
`hidden md:flex` e un'ottava non ci sta a 1280 px, e su touch una tendina hover-only consuma il primo
tap.

> **La regola:** per una pagina sola non si tocca una nav duplicata in 91 file. Le verticali si
> agganciano dove il sito già le aggancia: la pagina catalogo delle funzioni, la card in home, il
> footer «Prodotto», e le pagine sorelle sul tema. Se un giorno le pagine di funzione diventano
> quattro o cinque, il sottomenu si valuta **con lo script riparato prima**.

### Una pagina di funzione senza immagini non regge il confronto

**Osservato il 22/08/2026 guardando `nexiahome.it/landing`**, che sul nostro stesso tema — la
migrazione dello storico — ha la pagina in evidenza, con schermate, esempi e video. La nostra prima
stesura era tutta testo.

- [ ] **Le immagini si fotografano, non si disegnano.** `src/scatta-app.sh` esiste per questo e usa
      l'utente di servizio con le credenziali in `.env.shot`, escluso da git. La regola del progetto
      è esplicita — *«screenshot reali, mai mockup»* — e vale anche quando lo strumento non arriva:
      allora si allarga lo strumento, non si disegna l'immagine.
      ```bash
      ./src/scatta-app.sh <percorso-app> <nome-file> [larghezza] [altezza] [ancora] [scala]
      ```
- [ ] 🚨 **Guardare ogni scatto prima di pubblicarlo, per due cose che il testo non ha.**
      1. **Dati personali.** La schermata di conferma dell'importazione mostrava nomi e cognomi dei
         condòmini di un condominio di collaudo: su una pagina pubblica leggono come persone vere,
         a prescindere da dove vengono. Quello scatto è stato scartato e sostituito.
      2. **Promesse al futuro dentro l'immagine.** Il primo scatto conteneva un riquadro «in arrivo»
         del prodotto: la regola che vieta il futuro nel testo vale identica in figura, e nessun grep
         la trova. Si rifà lo scatto più corto, scegliendo l'altezza che taglia il riquadro.
- [ ] **Le misure dichiarate devono coincidere con il file.** Rifacendo uno scatto cambia l'altezza,
      e gli attributi `width`/`height` restano quelli vecchi: il browser riserva lo spazio sbagliato
      e la pagina salta al caricamento.
      ```bash
      sips -g pixelWidth -g pixelHeight assets/img/docs/<nome>.png
      ```
- [ ] **`loading="lazy"` e `decoding="async"` su ogni figura.** Sul sito al 22/08/2026 le cinque
      immagini di `funzioni-gestionale-condominio.html` non ne hanno nessuna.

- [ ] ⚠️ **`src/verifica-pagina.sh` non è un cancello per le pagine di radice.** La soglia è «ce
      l'hanno i tre quarti delle sorelle», e in radice le sorelle sono 13 file eterogenei: dei suoi
      undici marcatori ne restano attivi **due** (canonical e JSON-LD). `og:image` è al 53 % e il
      footer al 69 %, quindi **non vengono controllati**: una pagina senza copertina e senza footer
      passerebbe come «conforme». Per una verticale la lista si spunta a mano: og:image e
      twitter:image, footer, canonical auto-referenziale, `@graph` con `SoftwareApplication` +
      `BreadcrumbList` + `FAQPage`, FAQ a schermo identiche allo schema, bilanciamento dei tag.

---

## 5. Il changelog — la decisione viene prima dello script

Il changelog vive in **tre posti diversi**, con tre formati e tre destinatari, e nessuno dei tre si
ricava automaticamente dagli altri:

| dove | forma | chi lo legge |
| :--- | :--- | :--- |
| `docs/changelog.md` (repository dei documenti) | narrativo, una voce per beta | noi |
| `resources/data/changelogs/{it,en,pt}/<versione>.json` (prodotto) | chiave `features`, frasi divulgative | chi ha appena aggiornato, nella schermata post-aggiornamento |
| `docs/changelog.html` (sito) | HTML, una sezione per versione | chi arriva da `notes_url` |

- [ ] ⚠️ **Il file JSON della versione stabile va creato a mano, e senza di lui la schermata
      post-aggiornamento mostra un testo generico.** Il prodotto lo cerca **per nome**, derivato da
      `config('app.version')`. Verificato il 22/08/2026: nelle tre lingue esistono i file di tutte
      le beta e `1.9.1.json`, e **non esiste `1.10.0.json`**. Non è un difetto: nessuna beta lo
      crea, va scritto al rilascio.
      ```bash
      ls resources/data/changelogs/it/ | grep -v beta
      ```
- [ ] **La decisione, che è il lavoro vero:** cosa si pubblica delle N beta? Tutte, una sintesi, o
      dieci frasi?
      > ✅ **Deciso il 22/08/2026 per la 1.10.0: raggruppate per argomento, non per beta.** La pagina
      > delle versioni precedenti elenca ogni beta con il suo dettaglio — undici beta, 535 righe, in
      > lingua da programmatore (`TS2740`, «race condition», nomi di componenti). Con 66 beta la
      > stessa forma dava tremila righe. Le tre ragioni: quella pagina è il bersaglio di `notes_url`,
      > cioè si apre **dal pulsante «note di rilascio» dentro il gestionale**, e chi la legge sta
      > decidendo se aggiornare adesso; il dettaglio beta per beta **non si perde**, perché vive nei
      > file di `resources/data/changelogs/` che il prodotto mostra dopo l'aggiornamento; e la lingua
      > tecnica del modello è un difetto che moltiplicare per sei avrebbe solo aggravato.
      > Forma adottata: riepilogo della stabile, riquadro ambra sul backup e sui requisiti, poi dieci
      > `cl-section` tematiche con i badge Aggiunto/Migliorato/Risolto/Hardening. Al 22/08/2026 `docs/changelog.md` ha **66 voci** `## [1.10.0-beta.N]` su 4.534
      righe. Da quella decisione discendono i tre JSON, `docs/changelog.html` e l'array `changelog`
      dentro `latest.json` — che nella 1.9.1 sono **dieci frasi**, non undici beta.
- [ ] ⛔ **`parse_changelog.py` non si rilancia: si riscrive.** Verificato il 22/08/2026: è uno
      script one-shot con il blocco HTML della v1.9.1 **scritto dentro riga per riga**, e legge le
      voci al livello `### [1.9.1-beta`. Il changelog della 1.10 usa `## [1.10.0-beta.N]`. Chi lo
      lancia com'è non ottiene un errore: ottiene la pagina della 1.9.1.
- [ ] **I tre passi meccanici sulla pagina del sito**, che si dimenticano perché non danno errore:
      la sezione nuova va inserita **prima** di quella della versione precedente; il tag
      `<span class="version-tag">Latest</span>` va **spostato** (dev'essercene uno solo in pagina);
      e la voce va aggiunta all'indice laterale «Versioni», dove la classe `active` si sposta
      insieme al tag.
      ```bash
      grep -c 'version-tag">Latest' docs/changelog.html   # dev'essere 1
      ```
- [ ] ⚠️ **Gli script Python del sito e `changelog.md` non sono versionati** (`*.py` e
      `/changelog.md` sono in `.gitignore`): vivono su una macchina sola. Se quella macchina si
      perde, si perde il modo di generare la pagina.

---

## 6. Il giorno del rilascio — in ordine

### 6.1 Codice

- [ ] Versione in `config/app.php` portata a quella stabile. È **l'unico punto** che la dichiara:
      tutto il resto legge `config('app.version')`.
      ```bash
      grep -n "'version'" config/app.php
      ```
- [ ] Le due suite verdi **in TEST e in ufficiale**, non solo in TEST:
      ```bash
      php -d memory_limit=2G vendor/bin/pest
      npm test
      ```
- [ ] `npx vite build`, e verificare che `public/build/` sia aggiornato. ⚠️ **`/public/build` è in
      `.gitignore`**: gli asset compilati non sono nel repository ma **devono stare nel pacchetto**,
      altrimenti l'installazione aggiornata serve un manifest che non esiste.
- [ ] Port in ufficiale (Fase 3 del flusso di lavoro) e **commit di Vincenzo**.
- [ ] **Il tag.** `git tag v<versione>` — con la `v` attaccata al numero.
      ⚠️ La serie esistente contiene un refuso, `v.1.9.0` con il punto di troppo, e la 1.9.0 corretta
      **non esiste come tag**. Conta perché ogni misura del salto successivo si fa con
      `git diff v<precedente>..HEAD`: un tag scritto male fa partire il confronto dal posto
      sbagliato.
      ```bash
      git tag | sort -V
      ```

### 6.2 Pacchetto e manifest

- [ ] Costruire il pacchetto in `packages/installer_km_v<versione>.zip`.
      ⚠️ **Lo script che lo costruisce vive fuori da entrambi i repository e fuori da git**
      (`~/Desktop/KondoManager/Utilities/Installer/`). Va aperto e letto **prima** del giorno del
      rilascio, non durante: decide se il pacchetto contiene le dipendenze risolte con `install` o
      con `update`, e quindi se le librerie che escono sono quelle collaudate.
- [ ] Calcolare SHA256 e dimensione **del file caricato**:
      ```bash
      shasum -a 256 packages/installer_km_v<versione>.zip
      wc -c < packages/installer_km_v<versione>.zip
      ```
- [ ] **Aggiornare anche l'installer standalone**, che ha versione, URL e hash del pacchetto
      cablati dentro, e una propria soglia `MIN_PHP_VERSION`. Vive nella stessa cartella fuori da
      git: se resta indietro, chi installa da zero prende una beta vecchia.
- [ ] `requirements.php` verificato **contro quello che il codice richiede davvero**, non contro
      quello che il sito dichiara:
      ```bash
      grep -n '"php"' composer.json          # la fonte
      grep -n '"php"' packages/latest.json   # il cancello
      ```
      ✅ **Misurato il 25/08/2026, e i due dubbi che questa voce sollevava sono sciolti.**

      **Il numero.** Non `^8.4` come dice `composer.json`, ma **`8.4.1`**: la fonte vera è
      `composer.lock`, dove tutto Symfony chiede `>=8.4.1`. `composer.json` dichiara le intenzioni,
      `composer.lock` è ciò che è davvero installato — e sul cancello vale il secondo.

      **Il messaggio è comprensibile e arriva al momento giusto**, verificato leggendo il codice
      della versione **vecchia** (`git show v1.9.1:app/Services/UpdateService.php`): il controllo
      c'è già lì, con lo stesso testo, e sta **prima di qualunque scrittura di file**. Chi è su 8.2
      legge *«PHP 8.4.1 richiesto. Versione attuale: 8.2.x — Aggiorna PHP sul tuo hosting prima di
      procedere.»* e resta sulla sua versione funzionante, senza deploy a metà. Resta da scrivere la
      riga nelle note di rilascio che spieghi **come** si alza PHP sui pannelli più diffusi.

      ⚠️ **E c'era un terzo cancello che questa voce non nominava: `config/installer.php`**, quello
      dell'installazione da zero. Diceva `8.4.0` e una lista di estensioni diversa dalle altre due.
      Corretto nella **beta.77**, insieme al ripiego di `UpdateService`.

      🚨 **Il ritrovamento più grosso non era la versione: era `gd`.** Non compariva in **nessuna**
      delle tre liste, e lo pretendono `mpdf` e `phpoffice/phpspreadsheet` — il motore di ogni PDF
      del programma. Un hosting senza `gd` superava l'installer e poi falliva su **ogni stampa**.
      Mancavano anche `intl` e `mbstring`. La lista giusta, misurata sui soli pacchetti di runtime
      escludendo quelle che un PHP standard garantisce sempre: **`bcmath`, `fileinfo`, `gd`, `intl`,
      `mbstring`, `zip`**.

      Da qui in poi il conto non si rifà a mano: `tests/Feature/System/RequisitiDichiaratiTest.php`
      lo ricalcola da `composer.lock` a ogni esecuzione della suite, in **tutti e due i versi** —
      lascia passare chi sta sotto, oppure respinge chi andrebbe bene.
- [ ] `requirements.extensions` allineate a quelle che le librerie pretendono. Il numero si legge al
      momento del rilascio, non qui:
      ```bash
      python3 -c "import json;d=json.load(open('vendor/phpoffice/phpspreadsheet/composer.json'));print(sorted(k for k in d['require'] if k.startswith('ext-')))"
      ```
      Misurato il 22/08/2026: **quattordici** estensioni per PhpSpreadsheet, contro le **sei**
      dichiarate oggi in `latest.json`.
- [ ] Nuova voce in `releases[]`, **poi** `latest_stable`. Mai il contrario (§2).

### 6.3 Sito

- [ ] **I riquadri beta.** Il marcatore si cerca così:
      ```bash
      grep -rn "BETA-<versione>" . --exclude-dir=node_modules --exclude-dir=.git
      ```
      🚨 **Il marcatore non basta, ed è il reperto più utile di questo documento.** Misurato il
      22/08/2026: **13 marcatori in 9 file**, ma una ricerca *per frase* trova le stesse
      affermazioni in **quattro file che non hanno nessun marcatore**
      (`docs/gestione-saldi-iniziali-condominio.html`,
      `blog/libro-giornale-quadratura-kondomanager.html`,
      `blog/diagnosi-senza-cura-stato-patrimoniale.html`,
      `blog/cambiare-gestionale-condominiale-migrare-dati.html`). Vanno usate **tutt'e due**:
      ```bash
      grep -rniE "quella che scarichi|quella che si scarica|ultima versione pubblicata|disponibil[ei] dalla versione|oggi in beta|in arrivo con la" . --exclude-dir=node_modules --exclude-dir=.git
      grep -rn "in beta" --include="*.html" . --exclude-dir=node_modules
      ```
      Misurato il 22/08/2026: la sola espressione «in beta» compare **18 volte in 10 file**. Tre di
      quelle occorrenze sono riferimenti storici da lasciare stare («corretto in beta.17»,
      «scritto in beta.25»): i riquadri veri da rovesciare sono **una quindicina**, contro i 13
      marcatori. Il marcatore è un aiuto, non il perimetro.
- [ ] ⚠️ **Dentro un file marcato, il marcatore copre il riquadro e non il resto della pagina.**
      In `blog/condomino-che-spariva-dal-riparto.html` il marcatore sta a riga 456, ma la stessa
      affermazione compare a riga **646** nel testo visibile, a riga **70** dentro il **JSON-LD
      delle FAQ**, e a riga **728** nella CTA finale. Correggere solo il testo visibile lascia
      Google a servire la risposta vecchia. **Ogni correzione visibile ha un gemello nei dati
      strutturati: si cercano insieme.**
- [ ] **I badge di versione** in nav e footer. Misurato il 22/08/2026: **256 righe in 87 file**
      contengono `1.9.1`, di cui **173** nella forma `v1.9.1`. La sostituzione è meccanica **tranne**
      dove il numero è storia (§3): `docs/changelog.html` e `landing/versione-1.9.1.html` non si
      toccano.
- [ ] **I requisiti pubblici** in `docs/installation-wizard.html`, che durante la beta erano rimasti
      indietro di proposito. Vanno allineati a `requirements` del manifest, **negli stessi giorni**:
      una pagina che dichiara PHP 8.4 mentre il manifest offre 8.2 respinge chi potrebbe installare.
- [ ] **Il numero di versione sul pulsante di download.**
- [ ] **Niente promesse al futuro.** È l'unica categoria che nessuno script corregge da solo:
      ```bash
      grep -rniE "in arrivo|arriverà|sarà disponibile|prossime settimane" blog/ docs/ *.html
      ```
- [ ] Dopo aver toccato classi Tailwind: `npm run build:css`.
- [ ] Pubblicazione del sito, e **solo a quel punto** `latest_stable`.

---

## 7. Il collaudo che non si può saltare

È la voce che più probabilmente fa slittare una data, perché è **tempo di calendario su ambienti da
preparare**, non ore di lavoro. Gli scenari stanno in
[`aggiornamento_universale.md`](aggiornamento_universale.md) §6: installazione da zero, aggiornamento
manuale, aggiornamento automatico, ciascuno anche con document root su `public/`, più un hosting con
`max_execution_time` basso, più la prova di rollback. Ognuno vuole una installazione **della versione
precedente**, vera e con dati.

- [ ] 🚨 **Cronometrare le migrazioni su un database di dimensione realistica.** Il salto applica
      tutte le migrazioni del ciclo in un colpo solo, e quelle che lavorano sui **dati** (backfill,
      riclassifiche, semine) non si comportano come un `ALTER` su tabella vuota. Se la sequenza
      supera il timeout dell'hosting, il sintomo è una migrazione interrotta a metà **su tutte le
      installazioni insieme**. Una prova cronometrata su copia costa poche ore e toglie l'unico
      rischio non recuperabile.
- [ ] Verificare che ogni migrazione del ciclo sia nel dataset di
      `tests/Feature/System/UpgradeMigrationsRerunTest.php`.
- [ ] Se il salto è **senza backup automatico** — condizione dichiarata in
      [`roadmap.md`](roadmap.md) per il percorso 1.9.1 → 1.10 — dirlo nelle note di rilascio e
      suggerire il backup manuale. Non è una cautela: è l'informazione che permette a chi aggiorna
      di scegliere.

---

## 8. Dopo

- [ ] Da un'installazione della versione precedente, verificare che il pulsante «aggiorna ora»
      compaia davvero e porti al pacchetto giusto.
- [ ] Aprire `notes_url` e controllare che parli della versione appena pubblicata.
- [ ] Rileggere la homepage: dichiara la versione nuova come «attuale in produzione»?
- [ ] **Aggiornare questo documento** con quello che si è scoperto — vedi §9.

---

## 9. Le lacune note, al 22/08/2026

Sono aperte. Chi le chiude le sposta da qui alla sezione che le riguarda, con la data.

| | lacuna | conseguenza |
| :--- | :--- | :--- |
| 1 | **Lo script che costruisce il pacchetto non è mai stato letto**, e vive fuori da git | non si sa se il pacchetto esce con le dipendenze collaudate; se la cartella si perde, si perde il canale di installazione |
| 2 | **L'installer standalone vive fuori da git** e ha versione, URL, hash e soglia PHP cablati | resta indietro in silenzio: al 22/08/2026 punta ancora a un pacchetto della `beta.63` |
| 3 | **La creazione degli asset compilati non è scritta in nessuna checklist** | il passo esiste solo nella memoria di chi lancia il build |
| 4 | **`parse_changelog.py` è cablato sulla versione precedente** e non è versionato | va riscritto a ogni rilascio, e da una macchina sola |
| 5 | **Il refuso `v.1.9.0` nella serie dei tag**, e la 1.9.0 senza tag corretto | i confronti fra versioni partono dal punto sbagliato |
| 6 | **Nessuno ha stimato quante installazioni restano sotto la soglia PHP** | il cancello si alza al buio |
| 7 | **Lo spagnolo è offerto nell'installer ma di fatto non tradotto** (`lang/es/gestionale.php` quasi identico all'inglese) | una lingua promessa che non c'è |

---

## 10. Perché questo documento esiste in questa forma

Tre regole di progetto convergono qui, e vale la pena scriverle una volta:

1. **Una procedura che si esegue di rado va scritta come se chi la esegue non l'avesse mai vista.**
   La Fase 7 stava in coda a un documento di 2.720 righe sul processo delle beta: chi rilascia non
   ci arriva leggendo, ci arriva cercando.
2. **Un numero scritto in un documento invecchia; un comando no.** Ogni cifra qui ha accanto il
   comando che la rimisura, ed è il comando ad avere ragione. È la stessa regola per cui
   `flusso_di_lavoro_rilascio.md` dichiara «non verificabili per costruzione» le righe che contano
   se stesse.
3. **Un documento che mente costa più di un documento che manca.** Quindi: quello che non è stato
   verificato sta in §9 dichiarato come non verificato, non nel corpo travestito da fatto.
