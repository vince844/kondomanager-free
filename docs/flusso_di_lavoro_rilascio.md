# Flusso di lavoro di una beta

<!-- verifica-documentazione -->
> **Stato:** Descrive il processo concordato — scritto il 31/07/2026 su 1.10.0-beta.32, riletto e corretto il 01/08/2026 su 1.10.0-beta.33, poi il 02/08/2026 su 1.10.0-beta.34 e su 1.10.0-beta.35, poi il 03/08/2026 su 1.10.0-beta.36, su 1.10.0-beta.37 e su 1.10.0-beta.38, poi il 04/08/2026 su 1.10.0-beta.39, su 1.10.0-beta.40 e su 1.10.0-beta.41, poi il 05/08/2026 su 1.10.0-beta.42, poi il 05/08/2026 su 1.10.0-beta.43, su 1.10.0-beta.44, su 1.10.0-beta.45 e su **1.10.0-beta.46**, poi il **06/08/2026 in apertura della beta.47**, poi il **10/08/2026 in apertura della beta.48** — con le lezioni di *chiusura* della beta.47 e il ricontrollo di tutte le cifre citate (guide in-app, pagine del sito, condomìni demo, versione in sviluppo): **erano invecchiate tutte e quattro** —, poi l'**11/08/2026 in apertura della beta.49**, con le quattro lezioni della beta.48 e le cifre riverificate (15 guide, quattro condomìni demo, `latest_stable` ancora 1.9.1: tutte confermate), poi il **12/08/2026 in apertura della beta.50**, con le cinque lezioni della beta.49, le due dell'apertura stessa e le cifre riverificate: **due erano invecchiate nella beta.49 stessa** — le guide in-app sono **16** (aggiunta `IncassoRateGuide`) e le pagine `docs/` del sito **32** (aggiunta la guida sull'incasso); `latest_stable` resta 1.9.1, confermato —, poi il **15/08/2026 in apertura della beta.52**, con le **tre regole sulla documentazione** nate dai cinque guasti dell'indagine sulle pertinenze (Fase 2) e la regola su `roadmap.md` nella ricerca preliminare (Fase 0.2). Cifre riverificate: guide in-app **16, confermate** (`ls resources/js/components/guides/*.vue`); condomìni costruiti a scopo **quattro, confermati** (Demo KM, Demo Foto, Vuoto Verifica, Collaudo 46, su 9 totali a database); **invecchiata una**: i documenti in `docs/` erano dichiarati **52** e sono **57** per 27.730 righe, corretto in Fase 2. `latest_stable` non è verificabile da TEST — vive nel repository del sito — e resta dichiarato 1.9.1 senza riscontro in questa rilettura —, poi il **15/08/2026 in apertura della beta.53**, con le **quattro lezioni della beta.52** (Fase 1) e la riverifica delle cifre: guide in-app **16, confermate**; documenti in `docs/` **57 per 27.967 righe**, cioè 237 righe in più della beta.52 — la conta va rifatta, non ricordata. `latest_stable` sul sito è **1.9.1, verificato online il 15/08/2026** sulla homepage, che dichiara «versione attuale in produzione: KondoManager v1.9.1», poi il **16/08/2026 in apertura della beta.54**, con le **tre lezioni della beta.53** più le quattro nate chiudendo il sito (riquadro di versione, wiring, font sotto-dichiarato, conformità di pagina) e le due sui limiti dello strumento di verifica. Cifre riverificate: guide in-app **16, confermate**; documenti in `docs/` **57 per 28.589 righe**, cioè 622 in più della beta.53 — tutte di processo, nessun documento nuovo. Sul sito: **33** pagine in `docs/` (erano 32: aggiunta la guida sulle pertinenze) e **38** articoli in `blog/` (erano 36: pertinenze e verifica del riparto). `latest_stable` resta **1.9.1**, poi il **16/08/2026 in apertura della beta.55**, con le **cinque lezioni della beta.54** (Fase 1), ricavate dal changelog e dal diff perché quella beta è stata lavorata da un'altra sessione. Cifre riverificate: guide in-app **16 in apertura, 17 alla chiusura** (la beta.55 aggiunge `UtentiGuide.vue`); documenti in `docs/` **58 per 29.680 righe** — un documento in più (`utenti_sospensione_privilegi_e_titolarita.md`) e 1.091 righe in più della .54. Sul sito: `docs/` **33, confermate**, `blog/` **39** (uno in più, l'articolo della .54). `latest_stable` **1.9.1**, riletto in `packages/latest.json`. Versione in `config/app.php`: **1.10.0-beta.54**, poi il **16/08/2026 in apertura della beta.56**, con le **cinque lezioni della beta.55** (Fase 1) e la regola delle **tre viste** nella verifica a video. Cifre riverificate: guide in-app **17** (aggiunta `UtentiGuide.vue`); documenti in `docs/` **58**; sul sito `docs/` **33** e `blog/` **39**, con **sette** riquadri `BETA-1.10` da togliere alla stabile (tre sono in `users.html`, scritti nella beta.55), poi il **17/08/2026 in apertura della beta.57**, con le **sei lezioni della beta.56** (Fase 1) — quattro delle quali non vengono dal difetto ma da come lo si è verificato — e la sezione «Guardare a video uno stato che nei dati non c'è». Cifre riverificate una per una, **e tre erano cambiate**: documenti in `docs/` **55 vivi** più **3 archiviati** (erano dichiarati 58 in un solo mucchio: l'archivio della beta.56 ha diviso il numero in due, e la riga che li contava è stata corretta in Fase 2); righe **30.336**, cioè 656 più della .56, tutte di roadmap e di questo documento; **cinque code nuove** ㉘–㉜ più la ㉝, nate dalla riconciliazione del 17/08 e non da una beta. Confermate: guide in-app **17**; sito `docs/` **33** e `blog/` **39**; **sette** riquadri `BETA-1.10`; `latest_stable` **1.9.1**, riletto in `packages/latest.json`. Versione in `config/app.php` all'apertura: **1.10.0-beta.56**, poi il **19/08/2026 in apertura della beta.59**, con **due giri di lezioni arretrati**: la Fase 0.3 non è stata eseguita né aprendo la beta.58 né chiudendo la .57, e questo documento non nominava la .58 se non nella Fase 3. Cifre riverificate una per una, **e cinque erano invecchiate**: «due delle **quattordici** guide» sei righe sotto la riga che ne dichiara diciassette (corretto); documenti in `docs/` dichiarati **55 per 30.000 righe** e sono 55 per **31.154** (+818 dalla .57); «lo sviluppo è a **1.10.0-beta.50**» nella Fase 7 (corretto in .58); la sidebar del sito «**30** al 06/08/2026» ed è 33; «**sette** riquadri `BETA-1.10`» — sette sono i **file**, i riquadri sono **9** (`users.html` ne ha 3). Confermate: guide in-app **17**; documenti vivi **55** più 3 archiviati; sito `docs/` **33** e `blog/` **39**; `latest_stable` **1.9.1**, riletto in `packages/latest.json`. Versione in `config/app.php` all'apertura: **1.10.0-beta.58**, poi il **19/08/2026 in apertura della beta.60**, con le **cinque lezioni della beta.59** — due delle quali nate dal difetto «alta» che la revisione ha trovato **a suite verde** (il seeder che non girava in aggiornamento). Cifre riverificate una per una, **e quattro erano invecchiate**: righe in `docs/` dichiarate **31.154** e sono **31.954** su **56** documenti (uno nuovo da un'altra sessione, `guida_tabelle_millesimali_sito.md`); versione in sviluppo dichiarata **beta.58** ed è la **.59**; riquadri `BETA-1.10` dichiarati **quattro** nel corpo e sono **nove in sette file** — la correzione stava nell'intestazione dalla .59 e non era mai scesa nel corpo, che è il guasto che questo documento denuncia da sé; e un file del sito citato con un **nome che non esiste** (`importare-dati-altro-gestionale-condominio.html`). Confermate: guide in-app **17**; sito `docs/` **33** e `blog/` **39**; `latest_stable` **1.9.1**; comuni a database **7.894**. Le due righe che dichiarano righe e versione sono state marcate come **non verificabili per costruzione**: si leggono col comando, non qui., poi il **19/08/2026 in apertura della beta.61**, con le **sette lezioni della beta.60** — cinque delle quali non vengono dal difetto ma da come lo si è preso, e una l'ha trovata la ricognizione di questa apertura rileggendo le guardie che la .60 aveva appena scritto: **tutte e tre si svuotano senza che un test diventi rosso**. Cifre riverificate una per una, **e cinque erano invecchiate**: righe in `docs/` dichiarate **31.954** e sono **32.255** su 56 documenti vivi più **4** archiviati (la riga ne dichiarava 3); pagine `docs/` del sito dichiarate **33** e sono **34** (`tabelle-millesimali-condominio.html`, da un'altra sessione, già committata); riquadri `BETA-1.10` dichiarati **nove in sette file** e sono **dieci in otto**; la riga che dava `importare-dati-altro-gestionale.html` e `stato-patrimoniale-non-quadra.html` **fuori dalla sidebar**, che oggi è falsa; e il comando anti-collisione dei codici della coda, che leggeva un intervallo di caratteri ormai pieno. Confermate: guide in-app **17**; `blog/` **39**; `latest_stable` **1.9.1** (campo `latest_stable` in `packages/latest.json`, che vive nel, poi il **23/08/2026 in apertura della beta.72**, con la lezione «il port è un istante, il lavoro no» aggiunta alla Fase 0.1: la .71 risultava portata e committata (`4142f1b0`) e il lavoro sugli stessi file era proseguito in TEST dopo il commit — 202 righe su tre file più i tre changelog, salvate dal `diff` fra cartelle e portate prima di risincronizzare. È la variante *dentro* il perimetro della beta del caso .36, che era *fuori*. Cifre riverificate, **e tutte e tre erano invecchiate**: guide in-app dichiarate **17** e sono **20**; documenti in `docs/` dichiarati **56 vivi** e sono **60**, per **36.618** righe contro le 32.255 della .61 — cioè 4.363 in più mai contate. Archiviati **4, confermati**. Le cifre del sito (pagine `docs/`, articoli `blog/`, riquadri `BETA-1.10`, `latest_stable`) **non sono state riverificate in questa apertura**: vivono nel repository del sito, e dichiararle senza averle lette è il difetto che questo documento denuncia
repository del sito e non qui: scriverlo come riferimento `file:riga` lo farebbe risultare rotto al
verificatore, che scandisce solo questo progetto); comuni a database **7.894**. Versione in `config/app.php` all'apertura: **1.10.0-beta.60**. ⚠️ Nota d'ambiente: questa apertura ha perso a metà giro il permesso macOS di lettura su `~/Desktop` — quattro reperti sono caduti per non verificabilità e sono stati rimisurati dopo il ripristino, non buttati.
, poi il **20/08/2026 in apertura della beta.62**, con le **sei lezioni della beta.61** — le prime due nate non da ciò che quella beta ha corretto ma da **dove ha smesso di cercare**: nove rotte morte chiuse in un file solo mentre ce n'erano diciassette in cinque, e una correzione di maggio applicata a uno dei due controller gemelli. Entrambe sono tornate dal forum come segnalazioni nuove. Cifre riverificate una per una, **e nove erano invecchiate**: guide in-app dichiarate **17** e sono **18** (`QuoteMillesimiGuide.vue`, aggiunta dalla .61 stessa) — e il numero era sbagliato in **due** punti a sei righe di distanza, che è il guasto che questo documento denuncia da sé; codici della coda dichiarati **49** e sono **50**, con `㉑` **non più libero** (speso dalla voce «Chi paga il centesimo di resto»), quindi **l'alfabeto è finito**; riquadri `BETA-1.10` dichiarati **dodici in otto file** e sono **tredici in nove**; elenchi senza stato vuoto dichiarati **sette** e sono **due**; estensioni di PhpSpreadsheet dichiarate **dodici** e sono **quattordici**; e quattro riferimenti che non reggono più — `StoreIncassoRateAction:281-297` (oggi è l'eccedenza di cassa, il ramo comproprietario è `:357-377`), un nome di test **mai esistito** (`la_rata_che_netta_a_zero_espone_comunque_il_suo_credito`), tre righe di `TraduzioniNonSiCongelanoTest` scivolate di due, e la riga che dava i documenti interni esclusi in `.git/info/exclude` mentre stanno in **`.gitignore`** con la regola invertita `docs/*`. Chiuse come superate: la coda ⑫ e la coda ⑬ (beta.49), il ripiego su `codice_immobile` (beta.59), la cattura per tipo di `IncassoRateController` (beta.49) e la regola sul pagante che pretendeva `anagrafica_condominio` (beta.49). Confermate: documenti in `docs/` **56 vivi** più **4 archiviati**; pagine `docs/` del sito **34**; `latest_stable` **1.9.1**; comuni a database **7.894**. Versione in `config/app.php` all'apertura: **1.10.0-beta.61**., poi il **21/08/2026 in apertura della beta.64**, con **due giri di lezioni arretrati** — la Fase 0.3 non è stata eseguita né chiudendo la .62 né aprendo la .63, e le sezioni si fermavano alla beta.61. È la **seconda volta** che succede (la prima fu la .57/.58), quindi non si è riscritta la regola: si è costruita una **guardia strutturale**, `tests/Feature/System/FlussoDiLavoroNonRestaIndietroTest.php`, che pretende la sezione delle lezioni della beta precedente e la menzione della versione in sviluppo nell'intestazione. Il motivo per cui lo strumento che c'era non poteva vederlo è misurato: `kondomanager:verifica-documentazione` ordina i documenti per età **assoluta** e mostra i dieci più vecchi, un elenco dominato da «31 beta fa» — questo documento, anche quando è indietro, sta a «1 beta fa» e non ci entra mai. È l'unico documento la cui età accettabile è **zero**, misurato col metro degli altri cinquantacinque. Cifre riverificate una per una, **e tre erano invecchiate**: pagine `docs/` del sito dichiarate **34** e sono **35**; articoli in `blog/` dichiarati **39** e sono **40** (entrambi dal percorso «Piano rate» di un'altra sessione); righe in `docs/` dichiarate **32.255** e sono **33.530**. Confermate: guide in-app **18**; documenti in `docs/` **56 vivi** più **4 archiviati**; `latest_stable` **1.9.1**, riletto in `packages/latest.json`; riquadri `BETA-1.10` **13 in 9 file**; comuni a database **7.894**; codici della coda **50**, con l'alfabeto cerchiato **finito** — le code nuove sono numeriche dalla 51 in poi; condomìni costruiti a scopo **quattro** (Demo KM, Demo Foto, Vuoto Verifica, Collaudo 46) su **nove** a database. Dichiarata **non misurabile come scritta** la cifra «elenchi senza stato vuoto: due»: non nomina il perimetro, e i due conteggi possibili danno risposte opposte — dei **sette elenchi nominati in roadmap** ne restano **zero senza stato vuoto** (sei ce l'hanno, «saldi» non usa quel componente), mentre su **tutti** i `DataTable.vue` del progetto sono **17 su 27**. Va riscritta col perimetro o tolta. Cifra nuova introdotta dalla .63: **13** modelli di stampa in `resources/views/pdf/`. Versione in `config/app.php` all'apertura: **1.10.0-beta.63**., poi il **22/08/2026 in apertura della beta.65**. ⚠️ **Le lezioni della beta.64 erano già scritte prima di questa apertura**, messe giù chiudendola invece che aprendo la successiva: è la prima volta che succede, ed è il verso giusto — una lezione scritta il giorno in cui la si impara è più precisa di una ricostruita due giorni dopo dal changelog. Cinque delle sei non vengono da ciò che la beta ha corretto ma dalle **guardie scritte in quella beta che non mordevano**, tre in un giro solo. Cifre riverificate una per una, **e due erano invecchiate**: documenti in `docs/` dichiarati **56 vivi** e sono **58** (`LEGGIMI_REPOSITORY.md`, scritto aprendo il repository dei documenti, e `diagnosi_struttura_conti_web.md`, da un'altra sessione); righe in `docs/` dichiarate **33.530** e sono **34.275**. Confermate: guide in-app **18**; documenti archiviati **4**; modelli di stampa PDF **13**; comuni a database **7.894**; pagine `docs/` del sito **35**; articoli in `blog/` **40**; `latest_stable` **1.9.1**; riquadri `BETA-1.10` **13 in 9 file**. Cifra nuova introdotta dalla .64: **14** tipi di notifica dichiarati in `config/notifications.php`, di cui dodici visibili a un condòmino. Versione in `config/app.php` all'apertura: **1.10.0-beta.64**. Poi il **22/08/2026 in chiusura della beta.66**, con le lezioni della .66 scritte lo stesso giorno — è la seconda volta di fila che si scrivono chiudendo invece che aprendo la successiva, e resta il verso giusto. Cifre riverificate una per una, **e una era invecchiata**: righe in `docs/` dichiarate **34.275** e sono **34.710**. Confermate: documenti vivi in `docs/` **58**; documenti archiviati **4**; guide in-app **18**; modelli di stampa PDF **13**; comuni a database **7.894**; pagine `docs/` del sito **35**; articoli in `blog/` **40**; riquadri `BETA-1.10` **13 in 9 file**; tipi di notifica **14** (12 comuni + 2 di amministrazione). `latest_stable` non è verificabile da TEST. Cifra nuova introdotta dalla .66: **159 rotte del gestionale su 160** vincolate al condominio del loro indirizzo. Versione in `config/app.php` alla chiusura: **1.10.0-beta.66**. ➕ **22/08/2026, stesso giorno: la Fase 7 è stata spostata in [`rilascio_sito_versione_stabile.md`](rilascio_sito_versione_stabile.md)** e qui resta un rimando. Motivo misurato: la procedura del rilascio stabile stava a riga 2636 di 2720, in coda al documento che descrive il processo delle beta — si esegue una volta ogni due mesi e tocca ogni installazione al mondo, ed era nel punto che si legge di meno. Nel trasferirla sono state aggiunte sette cose che qui non c'erano e che la ricognizione del 22/08 ha trovato: il file di changelog nelle tre lingue (`resources/data/changelogs/{it,en,pt}/<versione>.json`, **assente** per la 1.10.0), il tag git (mai nominato, e la serie ha il refuso `v.1.9.0`), la riscrittura di `parse_changelog.py` (cablato sulla 1.9.1), i riquadri beta **senza marcatore** (quattro file che il grep su `BETA-1.10` non vede), i gemelli nel JSON-LD, il cancello di `requirements.php` (`composer.json` chiede `^8.4`, il manifest dichiara `8.2.0`) e il collaudo cronometrato delle migrazioni. Documenti in `docs/` ora **59 per 35.182 righe**, letti col comando. Poi il **22/08/2026 in chiusura della beta.67**, terza volta di fila che le lezioni si scrivono chiudendo — e questa volta la Fase 0.3 stessa è stata corretta, perché il suo corpo diceva ancora «aprendo» mentre l'intestazione dichiarava il contrario da tre giri. Cifre riverificate una per una, **e tre erano invecchiate**: documenti vivi in `docs/` dichiarati **58** e sono **59**; righe in `docs/` dichiarate **34.710** e sono **35.124**; guide in-app dichiarate **18** e sono **19** (`PagamentoFornitoreGuide.vue`, scritta dalla .67 stessa). Confermate: documenti archiviati **4**; modelli di stampa PDF **13**; comuni a database **7.894**; rotte del gestionale vincolate **159 su 160**. Cifra nuova introdotta dalla .67: **17** moduli di calcolo in `resources/js/lib/`, coperti da vitest. Versione in `config/app.php` alla chiusura: **1.10.0-beta.67**. Poi il **22/08/2026 in chiusura della beta.68**. Cifre riverificate: righe in `docs/` dichiarate **35.124** e sono **35426**; **confermate** documenti vivi **59**, guide in-app **19**, documenti archiviati **4**, modelli di stampa PDF **13**, comuni a database **7.894**. Versione in `config/app.php` alla chiusura: **1.10.0-beta.68**. Poi il **22/08/2026 in chiusura della beta.69**. Cifre riverificate: righe in `docs/` **35629**; confermate documenti vivi **59**, guide in-app **19**, archiviati **4**, modelli PDF **13**, comuni **7.894**. Versione in `config/app.php` alla chiusura: **1.10.0-beta.69**. Poi il **22/08/2026 in chiusura della beta.70**. Cifre riverificate: righe in docs/ **35799**; guide in-app **20** (aggiunta GiaVersatoGuide.vue). Confermate: documenti vivi **59**, archiviati **4**, modelli PDF **13**, comuni **7.894**. Versione alla chiusura: **1.10.0-beta.70**. Poi il **23/08/2026 in chiusura della beta.71**, la prima che tocca il database dalla .64: una colonna su condomini per marcare quello dimostrativo. Cifre riverificate: righe in docs/ **36424**; guide in-app **20**; migrazioni **139**; condomini a database **10**, di cui quattro costruiti a scopo. Versione alla chiusura: **1.10.0-beta.71**. Poi il **23/08/2026 in apertura della beta.73**, con le lezioni della beta.72 (lavorata da un'altra sessione, ricavate dal changelog e verificate contro il codice) e le cifre riverificate: righe in `docs/` dichiarate **36424** e sono **36769** (+345, tutte la Coda 69/70 di questa apertura); documenti vivi **60**, archiviati **4**, guide in-app **20**, migrazioni **139**, condomini a database **10** — tutti confermati, nessuno invecchiato. Versione all'apertura: **1.10.0-beta.72**. Poi il **23/08/2026 in apertura della beta.75**, con le **quattro lezioni delle beta.73 e .74** — la prima delle quali non viene da quelle beta ma da **dove hanno smesso**: la correzione del residuo era entrata in uno solo dei due servizi gemelli di riparto, e il commento dell'altro continuava a citarlo come modello. Cifre riverificate una per una con il comando, **e cinque erano invecchiate**: guide in-app dichiarate **20** e sono **21**; documenti vivi in `docs/` dichiarati **60** e sono **61** (`ricostruzione_contabilita_senza_consegne.md`, scritto in questa apertura); righe in `docs/` dichiarate **36769** e sono **37529** (+760); migrazioni dichiarate **139** e sono **140**; articoli in `blog/` dichiarati **40** e sono **41**. Confermate: documenti archiviati **4**; modelli di stampa PDF **13**; pagine `docs/` del sito **35**; `latest_stable` **1.9.1**, riletto in `packages/latest.json`; riquadri `BETA-1.10` **9 file**. ⚠️ **Questa apertura ha eseguito la Fase 0 in ritardo**, dopo aver già scritto il codice: il lavoro era iniziato da una richiesta di supporto e ci è scivolato dentro. Il costo misurato è stato una diagnosi sbagliata sullo stato dei commit (lezione 4) e nessun danno, perché il `diff` fra cartelle ha poi mostrato che l'unico file unico in TEST era il proprio. Versione all'apertura: **1.10.0-beta.75**. Poi il **24/08/2026 in apertura della beta.76**, con
le **sei lezioni della beta.75** — la sesta nata non dal lavoro della .75 ma da questa apertura: la
**Coda 70 era chiusa** dalla .75 senza che changelog né roadmap lo dicessero, e la .76 nasceva per
correggere un servizio che calcola giusto. ⚠️ **Questa apertura ha eseguito la Fase 0.3 a mano
invece che con gli agenti**: l'API di Anthropic ha risposto `529 Overloaded` a undici sottoagenti su
undici, in tre tentativi, gli ultimi due con **zero token e zero letture** — non erano partiti. La
rilettura del documento è quindi **meno esaustiva del solito** e va rifatta con gli agenti quando
possibile; la mappa del codice per la Coda 76, invece, è stata verificata a mano riferimento per
riferimento, e la revisione avversariale di Fase 1-bis è stata **rinviata di proposito** invece di
farla a metà, su decisione di Vincenzo: la .76 tocca un documento d'assemblea. Cifre riverificate una
per una, **e due erano invecchiate**: righe in `docs/` dichiarate **37529** e sono **37828**;
condomìni a database dichiarati **10** e sono **11** (`Via Ostiense 40`, costruito per il test a
video della .75 — da cancellare). Confermate col comando: guide in-app **21**; documenti vivi in
`docs/` **61**; archiviati **4**; migrazioni **140**; modelli di stampa PDF **13** (⚠️ si contano
**ricorsivamente**: `resources/views/pdf/` ha due file in radice e undici in due sottocartelle, e un
`ls` non ricorsivo ne conta 2); comuni a database **7.894**. Cifra **dotata di perimetro** invece di
essere dichiarata invecchiata: i moduli di calcolo in `resources/js/lib/` sono **19** file `.ts`, di
cui **17** di calcolo e **2** di sole costanti (`gestionale/tabelle/constants.ts`,
`segnalazioni/constants.ts`) — la cifra 17 è giusta, il perimetro non era scritto. ⚠️ **Una cifra dichiarata «non misurabile» che invece lo era.** Il 24/08 avevo scritto che i
riquadri `BETA-1.10` non si potevano contare «perché la cartella del sito non è su questa macchina»:
falso. Il sito è in `~/Desktop/KondoManager/kondomanager-website`, e io avevo cercato in
`~/Desktop/kondomanager-website`. Misurati: **13 occorrenze in 9 file**. Un `ls` che non trova non
prova che una cosa non esista: prova che non sta lì. «Non misurabile» è una risposta che chiude una
domanda, e chiuderla per sbaglio costa più che lasciarla aperta.

⚠️ **E quel 13 era comunque sbagliato, per una seconda ragione, scoperta un'ora dopo.** Un'altra
sessione stava lavorando allo stesso file e ne ha trovati **18**: tredici col marcatore e **cinque
senza** — una card sidebar intera, un badge nell'hero, il corpo di un callout, una CTA «Avvisami
quando esce», una risposta FAQ duplicata nel JSON-LD. **Un `grep` su un marcatore dà un numero reale
e parziale**, e la parzialità non si vede: il numero ha l'aria di essere la risposta. Quando si conta
qualcosa che *qualcuno ha marcato a mano*, la domanda successiva è sempre «e chi non l'ha marcato?».
Il comando che li prende tutti sta in `rilascio_sito_versione_stabile.md`.

⚠️ **Due sessioni sullo stesso file nella stessa ora.** Il repository `docs` ha reso la cosa
visibile — i loro due commit erano lì al `git log` — ed è esattamente il guadagno per cui è nato:
prima si sarebbero sovrascritte in silenzio. Ma la lezione operativa è un'altra e va aggiunta al
port: **`git -C docs pull` va fatto anche in TEST prima di scrivere in roadmap**, non solo in
ufficiale al momento del port. Una voce di roadmap scritta senza aver fatto pull può essere falsa
nel momento in cui la si scrive, e la mia lo era.
Versione all'apertura: **1.10.0-beta.75**.

Poi il **24/08/2026 in tarda serata, aprendo la beta.77**, con le **sette lezioni della beta.76** —
una giornata in cui ho introdotto **quattro difetti** e nessuno l'ho trovato scrivendo: li hanno
trovati il PDF vero, due passate avversariali e la Fase 0.2 del giorno dopo. La lezione n. 7 è la più
costosa: una premessa comoda («le stampe non istanziano il motore») ha guidato l'architettura della
.76 ed è finita in tre documenti prima che qualcuno aprisse il file della gemella, dove il motore
viene istanziato da diverse beta. ⚠️ Fase 0.3 di nuovo **a mano**, come la .76: non per il 529
questa volta, ma perché la finestra era un'ora. Cifre **non** riverificate in questa apertura — si
rifanno alla .78, e finché non lo sono valgono quelle del 24/08 mattina.
Versione all'apertura: **1.10.0-beta.76**.
> È l'unico documento di processo: se una regola qui contraddice un altro documento, vale questa.
> Si rilegge e si corregge a **ogni** beta: vedi Fase 0.3.
<!-- /verifica-documentazione -->

**Principio:** si sviluppa e si verifica nella cartella TEST; in ufficiale ci arriva solo ciò che è già verde, **già passato dalla revisione**, già raccontato nel changelog e già coerente con la documentazione. L'ordine conta: si rivede prima di raccontare, si racconta prima di portare. Il commit lo fa sempre Vincenzo.

---

## Le due cartelle

| | Percorso | Chi ci scrive |
| :--- | :--- | :--- |
| **TEST** | `/Users/vincenzo/Desktop/kondomanager-free` | Claude, durante tutto lo sviluppo |
| **Ufficiale** | `/Users/vincenzo/Desktop/KondoManager/kondomanager-free` | Claude solo nel passo di port; Vincenzo committa |

Condividono lo **stesso database MySQL** (`kondomanager-free`). Una migrazione lanciata da TEST risulta già applicata in ufficiale: `migrate` dirà *"Nothing to migrate"* ed è corretto — si verifica con `migrate:status`, non si rilancia.

---

## Fase 0 — Prima di cominciare

Due controlli che costano cinque minuti e ne fanno risparmiare parecchi.

### 0.1 — Risincronizzare TEST con l'ufficiale

Dopo che Vincenzo ha committato la beta precedente, la cartella TEST resta indietro di uno o più commit **anche quando i contenuti sono identici**, perché in TEST non si committa mai. L'effetto collaterale è insidioso: `git status` in TEST mostra impilate le modifiche di tutte le beta passate, e non si capisce più cosa appartenga alla beta corrente. È esattamente ciò che serve sapere al momento del port.

I due checkout condividono lo stesso remote e lo stesso branch, quindi la risincronizzazione è pulita:

```bash
git status                                  # deve mostrare solo lavoro già portato, o niente
git fetch origin
git reset --hard origin/<branch>            # es. origin/v1.10.0-beta
```

- [ ] Prima di resettare, verificare con un `diff` fra le due cartelle che in TEST **non ci sia nulla di unico**: `reset --hard` scarta il lavoro non committato. Se c'è qualcosa da salvare, `git diff <file> > patch` e riapplicarlo dopo.

**`git status` non basta a rispondere alla domanda, e nella beta.36 stava per costare due file.** `status` dice cosa è cambiato rispetto al commit locale, non cosa esiste in TEST e non in ufficiale: se la beta precedente è già committata di là, le stesse modifiche compaiono in `status` di qua come se fossero lavoro corrente, e il lavoro davvero unico ci si mimetizza in mezzo. La domanda si risponde confrontando le due **cartelle**:

```bash
diff -rq --exclude=.git --exclude=node_modules --exclude=vendor --exclude=docs \
     . /Users/vincenzo/Desktop/KondoManager/kondomanager-free
```

Nella beta.36 questo ha fatto emergere due file di test scritti in una sessione precedente e mai portati (`resources/js/pages/system/upgrade/Confirm.test.ts` e `tests/Feature/Gestionale/EstrattoContoCreditoDisponibileTest.php`), più le due dipendenze `@vue/test-utils` e `jsdom` in `package.json` e l'ambiente `jsdom` in `vitest.config.ts`.

**Il port è un istante, il lavoro no: una beta già portata e già committata può avere un seguito che nessuno riporta.** *(Verificato il 23/08/2026, aprendo la beta successiva alla .71.)* Il caso della beta.36 era lavoro **fuori** dal perimetro della beta — la variante insidiosa è quella **dentro**. La .71 era stata portata e committata (`4142f1b0`), e il lavoro sugli stessi file era proseguito in TEST dopo il commit: 202 righe su tre file, fra cui la correzione delle anagrafiche orfane (184 su 215 dopo una ventina di cicli crea/rimuovi), i quattro fornitori demo al posto di uno, e un test intero che documenta un difetto di produzione — `fakerphp/faker` è in `require-dev`, il pacchetto si costruisce con `--no-dev`, quindi il pulsante «crea condominio di esempio» funzionava solo sulle macchine di sviluppo. Anche i tre changelog erano più ricchi e descrivevano un'interfaccia diversa da quella committata.

Qui `git status` mente due volte invece di una: dice che quei file sono «modificati» come tutti gli altri residui della beta portata, e la loro unicità non ha nessun segno distintivo. **Il fatto che una beta risulti committata in ufficiale non dice nulla su quanto di essa sia stato portato**: la domanda si risponde solo col `diff` fra cartelle, e va posta anche — soprattutto — quando la beta precedente sembra chiusa.

- [ ] **Attenzione a cosa `reset --hard` porta via davvero.** Un file *untracked* sopravvive — ma solo se non esiste anche nel commit di destinazione: se c'è, viene sovrascritto senza avvisare. È il caso di `vitest.config.ts`, che in TEST era untracked ma in ufficiale era già committato. La regola pratica: copiare in scratchpad **tutto** ciò che il diff fra cartelle ha segnalato, tracciato o no, e rimetterlo dopo il reset.
- [ ] I documenti interni in `docs/` sono gitignored: il reset non li tocca. Le cinque guide pubbliche invece sì — se una correzione non è ancora finita in un commit, il reset la cancella.
- [ ] ⚠️ **`docs/changelog.md` è gitignorato nel repository dei documenti: push e pull non lo portano.**
      *(Imparato il 23/08/2026, chiudendo la beta.75: il changelog era stato scritto in TEST, i
      documenti erano stati committati e pushati, l'ufficiale aveva fatto `pull` — e in ufficiale il
      changelog della .75 **non c'era**. Se ne è accorto Vincenzo, non il processo.)*
      Da quando `docs/` è diventato un repository a sé, la regola «i documenti si allineano con
      push/pull» è vera per tutti tranne che per questo file, che `.gitignore:7` esclude. Il
      changelog è quindi **un file di prodotto travestito da documento**: si copia a mano, come il
      codice. Ed è insidioso perché `git status` in `docs/` non lo elenca fra i modificati — il
      posto dove si andrebbe a cercarlo tace.
      ```bash
      cp /Users/vincenzo/Desktop/kondomanager-free/docs/changelog.md \
         /Users/vincenzo/Desktop/KondoManager/kondomanager-free/docs/changelog.md
      ```
      Il `diff` fra le cartelle `docs/` qui sotto lo vede: **è l'unico controllo che lo prende**, e
      va eseguito anche quando il `pull` è andato a buon fine.

- [ ] **Confrontare anche `docs/` fra le due cartelle, qui e non solo in Fase 3.** Il `diff` qui sopra ha `--exclude=docs`, quindi per costruzione non può vedere la divergenza dei documenti interni; il controllo esiste in Fase 3, ma è l'ultimo passo di una beta, cioè quello che si salta quando si va di fretta. All'apertura della beta.51 il confronto ha trovato l'ufficiale **fermo all'11/08/2026**: `roadmap.md` 1650 righe contro 2049, `flusso_di_lavoro_rilascio.md` 817 contro 968, `subentro_e_competenza_temporale.md` assente del tutto. Due beta di divergenza che nessuno aveva segnalato.
  ```bash
  diff -rq /Users/vincenzo/Desktop/kondomanager-free/docs \
           /Users/vincenzo/Desktop/KondoManager/kondomanager-free/docs
  ```
  Essendo fuori da git il reset **non li tocca**, quindi il confronto si può fare prima o dopo indifferentemente. La cartella di riferimento è **TEST**, dove i documenti si scrivono: se l'ufficiale è indietro, si allinea copiando da TEST. Un documento vecchio che sembra buono costa più di un documento che manca.
- [ ] Dopo il reset, `git diff` in TEST deve mostrare **solo** il lavoro della beta che sta iniziando. È questo che rende il port di Fase 3 una copia secca invece di un'archeologia.

### 0.1-bis — L'utente di servizio degli screenshot

**Spostato qui il 06/08/2026, dopo la terza violazione della stessa regola.** La riga «si cancella lo stesso giorno in cui è creato» sta in fondo alla sezione delle immagini (Fase 5), cioè nel punto del documento che si rilegge di meno. È stata violata nella beta.42, nella beta.45 e di nuovo adesso: all'apertura della beta.47 `shot@kondomanager.local` (id 81) è **ancora attivo** — un account amministratore su un database **condiviso dai due checkout**.

Tre fallimenti della stessa regola non sono tre distrazioni: sono un promemoria messo nel posto sbagliato. Il controllo si sposta quindi dove viene letto per forza, cioè all'apertura di ogni beta:

```bash
php artisan tinker --execute="echo \App\Models\User::where('email','like','%shot%')->pluck('email')->toJson();"
```

- [ ] Se esce qualcosa, **chiedere prima di cancellare**: può essere in uso da un lavoro sul sito ancora aperto (è successo nella beta.45, con due sessioni in parallelo). La cancellazione va **intestata a qualcuno**, non fatta di slancio.
- [ ] La cancellazione è `forceDelete`. Al 06/08/2026 il model `User` **non** usa SoftDeletes — `withTrashed()` solleva `BadMethodCallException` — quindi un `delete()` è già definitivo.

> ✅ **Deciso da Vincenzo il 06/08/2026: `shot@kondomanager.local` (id 81) resta.** Non è più una violazione da segnalare: è un **account di servizio permanente** per le verifiche a video, e serve proprio perché il login su `127.0.0.1:8001` non passa da Claude. La regola «si cancella lo stesso giorno» resta valida per gli utenti creati **ad hoc** da uno script di screenshot; questo non lo è più.
>
> Quel che va tenuto presente, e vale la pena scriverlo una volta invece di riscoprirlo: è un **amministratore** su un database che i due checkout condividono. Il rischio è confinato al database di sviluppo — non esiste su nessuna installazione di un cliente e non viaggia con il pacchetto di rilascio, perché gli utenti stanno a database e non nel repository. Se un giorno quel database venisse copiato altrove, è la prima riga da togliere.
>
> Il controllo qui sopra resta, ma cambia domanda: non più *«c'è ancora?»* bensì *«ce ne sono di **altri**, creati da uno script e mai ripuliti?»*.

### 0.1-ter — **Alla prima beta dell'anno**: la fonte ISTAT dei Comuni

Solo a gennaio e febbraio, e per una ragione di calendario: le fusioni di comuni **decorrono per
legge dal 1° gennaio**, e ISTAT pubblica l'elenco aggiornato poco dopo (l'ultima volta il 26/02, con
il foglio datato 21/02). Il resto dell'anno questo passo si salta.

```bash
php artisan kondomanager:verifica-fonte-comuni
```

Costa una richiesta HEAD e uno scaricamento da 1,2 MB, e risponde confrontando la data che **ISTAT
dichiara nel nome del foglio** con quella dell'elenco in uso. Se c'è di nuovo, stampa i due comandi
per rigenerare il file spedito.

- [ ] ⛔ **Il comando non è pianificato, e non va pianificato.** L'elenco viaggia dentro il codice
      proprio perché nessuna installazione debba dipendere dalla rete: metterlo nello scheduler
      rimetterebbe dentro dalla finestra la dipendenza che il disegno esclude. C'è un test che
      verifica che `routes/console.php` non lo contenga.
- [ ] Se la fonte è più fresca, il file si rigenera **con un comando**, non a mano:
      `kondomanager:aggiorna-comuni --da=comuni.xlsx --scrivi-file`. Misurato: **74 MB** di picco e
      due secondi, e gira anche a `memory_limit = 128M`.
- [ ] Dopo averlo rigenerato, `NumeriDellaFonteTest` dirà quali numeri raccontati sono cambiati: è
      il presidio dei cinque numeri che la beta.59 aveva sparso in prosa. **Si aggiornano i testi,
      non il test.**

⚠️ **Perché la data si legge dal nome del foglio e non dal `last-modified`.** Misurato il
19/08/2026: l'intestazione HTTP dice 26/02, il foglio dice 21/02 — cinque giorni di scarto, e sono
due cose diverse. Peggio: il `last-modified` **non è stabile fra client**, `curl` e Guzzle ne
ricevono due valori a due secondi di distanza. Un controllo costruito su quell'intestazione griderebbe
al lupo.

### 0.2 — Cercare le guide che esistono già

Prima di progettare qualsiasi cosa, controllare se in `docs/` c'è già una specifica o una decisione presa sull'argomento. Su questo progetto è successo più volte di ritrovare a lavoro iniziato un documento che aveva già deciso tutto — o peggio, di mettere in roadmap qualcosa che era già implementato.

- [ ] `grep -ril "<argomento>" docs/` — e leggere l'intestazione di stato dei documenti trovati.
- [ ] **Fidarsi dell'intestazione, non del corpo**: `Non implementato` significa che il contenuto è una specifica valida, non una descrizione del prodotto. `Contiene affermazioni false` significa che le note ⚠️ nel testo dicono quali righe non credere.
- [ ] Se il documento non ha intestazione di stato, non è stato verificato: verificarlo contro il codice prima di usarlo per decidere.
- [ ] **`roadmap.md` si cerca sempre, e per prima** — `grep -n -i "<argomento>" docs/roadmap.md`. Non è uno dei documenti: è quello dove stanno le decisioni **già prese**, comprese le voci ⛔ *bloccate con motivazione* e le voci già chiuse in una beta precedente.

> ⚠️ **Aggiunto il 15/08/2026, dopo che è successo due volte nella stessa indagine.** Una ricerca approfondita sulle pertinenze ha proposto come fase prioritaria «incasso e avviso cumulativi per soggetto», dichiarandola *«vale più della funzione oggetto di questo documento»*. In `roadmap.md` la stessa materia era già analizzata più a fondo, con l'accertamento che **l'aggregazione per soggetto esiste già** (`EstrattoContoAnagraficaController::buildLedger()` somma i saldi della persona senza filtro sull'immobile), che la riga di riepilogo era **già allocata alla v1.11**, e che il resto era **bloccato di proposito** perché una delle sue letture rompe l'art. 63 disp. att. c.c. Nella stessa indagine, la voce di 1.10.1 allocava per intero un blocco (A2) che la beta.51 aveva già chiuso a metà.
>
> In entrambi i casi il codice era stato letto con precisione e la roadmap no. **Un'analisi che ignora le decisioni già prese non è un'analisi in più: è una decisione presa due volte con due esiti diversi**, e la seconda arriva con l'autorevolezza di essere la più recente. Il costo non è il lavoro rifatto — è che la voce vecchia resta in roadmap e qualcuno la pianifica.
>
> Vale anche al contrario: quando una beta chiude *parte* di una voce, la voce va aggiornata nello stesso passaggio. Se il changelog dice che è stato fatto e la roadmap dice che è da fare, ha ragione il changelog e la roadmap sta mentendo.

### 0.3 — Rileggere e correggere **questo** documento

Non consultarlo: **rileggerlo**, e correggere ciò che la beta precedente ha reso falso. Sono cinque minuti, e fa parte della beta esattamente come il changelog.

> ### ⚠️ Le lezioni si scrivono **chiudendo** la beta, non aprendo la successiva
>
> **Corretto il 22/08/2026, ed è una correzione del corpo che l'intestazione aspettava da tre giri.**
> Questa fase sta nella Fase 0 — *apertura* — e per trentaquattro beta le lezioni sono state scritte
> lì, cioè ricostruite il giorno dopo dal changelog e dal diff. Dalla beta.64 si scrivono chiudendo,
> ed è successo tre volte di fila (.64, .65, .66): **una lezione scritta il giorno in cui la si
> impara è più precisa di una ricostruita due giorni dopo.** L'intestazione lo dichiarava «il verso
> giusto» già dalla .65; questo paragrafo diceva ancora il contrario.
>
> ⚠️ **Non è pedanteria, ed è il guasto che questo documento denuncia da sé:** intestazione corretta
> e corpo no è esattamente la forma con cui un documento comincia a mentire — già successo con le
> guide in-app dichiarate 17 a sei righe di distanza dalla riga che ne dichiarava 18.
>
> **Quindi, in pratica:**
>
> - **Chiudendo la beta N** si scrivono le lezioni della N, si riverificano le cifre
>   dell'intestazione e si committa `docs/`. È il momento in cui si sa ancora *perché* si è fatta
>   una cosa in un modo.
> - **Aprendo la beta N+1** resta la rilettura dall'inizio alla fine — che è un'altra cosa dallo
>   scrivere: serve a trovare ciò che è invecchiato, non a raccontare.
> - La guardia `FlussoDiLavoroNonRestaIndietroTest` copre entrambi i versi, perché pretende la
>   sezione della beta **precedente** a quella in `config/app.php`: scritta chiudendo la N, il
>   controllo è già soddisfatto quando la N+1 comincia.

La prova che serve è questo documento stesso: dopo **una sola** beta di utilizzo conteneva già un'affermazione falsa (una «Gestione Demo Screenshot» che non esiste in `resources/`, `database/`, `app/` né a DB) e un esempio invecchiato (la card "Dati Blindati", nel frattempo corretta). È il documento che si chiude dicendo *«un documento che mente costa più di un documento che manca»*: se scivola lui, non c'è più niente che tenga in riga gli altri.

- [ ] Rileggerlo dall'inizio alla fine — non cercare la sezione che serve e basta.
- [ ] Ogni fatto verificabile (percorsi, nomi di file, dati demo, testi UI citati, numeri di versione) va **controllato**, non ricordato.
- [ ] Le lezioni della beta appena chiusa si scrivono qui, con il *perché*, non solo la regola — e si scrivono **chiudendola**, vedi il riquadro qui sopra. Aprendo la successiva si controlla solo che ci siano.
- [ ] Aggiornare la data nell'intestazione di stato.
- [ ] Sincronizzare la cartella ufficiale: dal **21/08/2026** `docs/` è un repository a sé — `git -C docs add -A && git -C docs commit && git -C docs push`, poi `git -C docs pull` nell'ufficiale. **Non più `cp`**: vedi `docs/LEGGIMI_REPOSITORY.md`.
- [ ] ⚠️ **Il commit di `docs/` lo fa Claude, ed è l'unica eccezione.** Deciso il 21/08/2026. Il repository dei documenti è **privato** (`vince844/kondomanager-docs`), quindi non vale la ragione per cui i commit del prodotto li fa Vincenzo — che è un repository pubblico e la riservatezza su come è fatto il lavoro. I commit del **codice** restano suoi, tutti, come sempre. Un documento aggiornato e non committato è un documento che esiste su una macchina sola, cioè il problema che questo repository è nato per togliere: si committa **nello stesso passaggio** in cui lo si scrive, non a fine beta.

## Fase 1 — Sviluppo in TEST

- [ ] Codice e test nella cartella TEST.
- [ ] Se il bug nasce da una segnalazione: **prima il test che fallisce**, poi il fix. È il test che dimostra che il bug c'era davvero.
- [ ] Se la beta tocca il database:
  - [ ] migrazione **idempotente** (guardie separate per colonna e per foreign key: su MySQL sono due statement distinti);
  - [ ] aggiunta al dataset di `tests/Feature/System/UpgradeMigrationsRerunTest.php`;
  - [ ] se modifica dati esistenti, ogni decisione va a **log** — una riparazione non ispezionabile non è riparabile a mano.
- [ ] Suite completa verde: `php -d memory_limit=2G vendor/bin/pest` (non `artisan test`, ignora `-d memory_limit`).
- [ ] Se la beta tocca calcoli in `resources/js/lib/`, anche `npm test` (vitest) verde.
- [ ] Se la modifica si vede a schermo, **guardarla davvero**, e in **tre viste**: desktop chiaro, mobile, scuro — vedi sotto.

### Verificare a video: usare `127.0.0.1`, non il dominio `.test`

Il dominio locale `kondomanager-free.test` (ServBay) è soggetto ad approvazione per ogni singola azione: gli strumenti del browser lì **non funzionano**, e non è una questione di login. La strada che funziona è il server di sviluppo di Laravel su localhost:

```bash
php artisan serve --port=8001    # dalla cartella TEST
```

La configurazione è già in `.claude/launch.json` (`kondomanager-test`, porta 8001). Se la porta è già occupata da un `artisan serve` avviato prima, va bene: basta aprire il browser direttamente su `http://127.0.0.1:8001`.

- [ ] **Il login su `127.0.0.1:8001` lo fa Vincenzo**: la sessione del dominio `.test` non vale su questa origine, e le credenziali non passano da Claude.
- [ ] Dopo il login la navigazione è libera: lettura pagina, click, screenshot.
- [ ] Il database è lo stesso dei due checkout, quindi i dati reali ci sono già.

**Nota per chi legge i risultati:** una modifica al frontend richiede `npx vite build` prima di essere visibile, perché la pagina è servita dal manifest compilato e non da Vite in watch.

### La verifica a video sono **tre** viste, non una

**Scritto il 16/08/2026 nella beta.55, su domanda di Vincenzo** — *«come si comporta la barra su
mobile? forse nel flusso di lavoro dobbiamo anche aggiungere di controllare sempre il layout per
mobile e anche il layout dark»*. Aveva ragione: fino a qui «guardarla davvero» voleva dire
guardarla **su un portatile, in chiaro**, che è una vista su tre. Le altre due si rompono in
silenzio, perché nessuno ci passa mai per caso.

- [ ] **Desktop, chiaro** — è quella che si guarda comunque.
- [ ] **Mobile**, con il viewport a 375 px e la pagina **ricaricata** dopo il ridimensionamento:
      alcuni comportamenti si decidono al caricamento e non al resize.
- [ ] **Scuro**, che sul progetto è `prefers-color-scheme` più la classe `dark` sull'elemento
      radice. Non serve rileggere tutta la pagina: bastano **gli elementi che la beta ha aggiunto**,
      perché il resto era già stato guardato quando è nato.

**Cosa cercare su mobile, in una riga:** il corpo della pagina **non deve scorrere in orizzontale**.
Un contenuto largo — una tabella, un diagramma — scorre **dentro il suo contenitore**, e quella è
la forma corretta; se invece scorre la pagina intera, la colpa è di un elemento che sfora e il
difetto si vede solo lì. Il controllo è una riga, e risponde con un booleano invece che con
un'impressione:

```javascript
document.documentElement.scrollWidth > document.documentElement.clientWidth   // true = difetto
```

**Cosa cercare in scuro:** i colori scritti a mano senza la variante `dark:`. Si riconoscono perché
spariscono — testo grigio chiaro su fondo chiaro-scuro — o perché urlano, tipo un fondo bianco
rimasto acceso in mezzo a una pagina scura.

⚠️ **E si misurano, non si guardano — regola scritta il 17/08/2026, dopo che Vincenzo ha dovuto
chiedermelo.** *«Quando fai il controllo visivo sullo stile dark non riesci a vedere questi difetti
cromatici?»*. No: gli screenshot che leggo sono ridimensionati, e un testo a contrasto **1,55**
su fondo scuro sembra un grigio di servizio invece di un difetto. L'occhio perdona, il numero no —
esattamente come sul mobile, dove la regola non è «sembra stretto» ma `scrollWidth > clientWidth`.
Lo scuro non aveva il suo numero. Adesso ce l'ha, e si legge in una volta sola:

```javascript
(() => {
  const lum = (c) => { const [r,g,b] = c.match(/\d+(\.\d+)?/g).slice(0,3).map(Number)
    .map(v => { v/=255; return v <= 0.03928 ? v/12.92 : Math.pow((v+0.055)/1.055, 2.4); });
    return 0.2126*r + 0.7152*g + 0.0722*b; };
  const ratio = (a,b) => { const [l1,l2] = [lum(a), lum(b)].sort((x,y)=>y-x); return ((l1+0.05)/(l2+0.05)).toFixed(2); };
  const fondo = (el) => { let e = el; while (e) { const bg = getComputedStyle(e).backgroundColor;
    if (bg && !bg.startsWith('rgba(0, 0, 0, 0')) return bg; e = e.parentElement; } return 'rgb(255,255,255)'; };
  return [...document.querySelectorAll('input, textarea, .vs__selected, .vs__search, label, td, th')]
    .filter(el => !el.closest('.sr-only'))   // i testi per lo screen reader non si vedono: non contano
    .map(el => ({ el: el.tagName + '.' + String(el.className).split(' ')[0],
                  contrasto: +ratio(getComputedStyle(el).color, fondo(el)) }))
    .filter(x => x.contrasto < 4.5);
})()
```

⛔ **E una correzione di contrasto non si fa da sola — scritto la sera del 17/08/2026, dopo un
ripristino.** Misurare è necessario e non basta: sistemare il testo di un componente dentro una pagina
che ha le superfici sbagliate **peggiora la percezione anche quando migliora il numero**, perché il
campo corretto risalta come una toppa chiara e l'occhio legge il contrasto fra il campo e la scheda,
non quello fra testo e fondo. La correzione è arrivata a video, Vincenzo l'ha guardata e ha chiesto di
tornare indietro — con ragione. **Regola: o si correggono superficie e testo insieme, sulla pagina
intera, o si misura e si lascia scritto.** Il numero serve a decidere *quando* intervenire, non
autorizza a intervenire un pezzo alla volta.

**La soglia è 4,5** (WCAG AA per testo normale; 3,0 basta sopra i 18pt o in grassetto sopra i 14pt).
Quello che l'elenco restituisce sono difetti, non sfumature. Due avvertenze imparate subito:

- **Guardare anche la superficie, non solo il testo.** Nello stesso giro è emerso che i campi
  `vue-select` hanno `background-color: rgba(0,0,0,0)` e bordo al 26% di opacità, mentre gli `input`
  nativi hanno superficie e bordo pieni: il contrasto del testo non lo dice, ma il campo «sembra un
  buco» accanto agli altri. Confrontare `backgroundColor` e `borderColor` di un componente di
  libreria con quelli di un campo nativo della stessa pagina è il secondo controllo, e costa una riga.
- **I colori delle librerie non seguono il tema.** `vue-select` porta i suoi (`#333` sul valore
  selezionato) e le nostre personalizzazioni in `resources/css/custom.css` sono scritte con colori
  fissi da tema chiaro. Funzionano in chiaro e spariscono in scuro: sono il primo posto dove guardare.

> **Misurato nella beta.55, e serve come metro:** la barra di sezione portata in alto si dispone su
> due righe a 375 px grazie a `flex-wrap` (nessuno sforo), la tabella utenti è larga 1007 px e
> **scorre dentro il suo riquadro** con il corpo pagina fermo, e in scuro tanto la tabella quanto
> la guida nuova restano leggibili. Tre viste, tre scatti, cinque minuti.

### Guardare a video uno stato che nei dati non c'è

*Scritto nella beta.56.* Metà delle cose da verificare non sono nel database di sviluppo: un avviso
scatta solo su un conflitto che lì non esiste. Le due tentazioni sono **fabbricare i dati a mano**
— e poi rimetterli a posto, che è il gesto in cui si sbaglia — oppure **copiare il testo in una
schermata finta**, che verifica la copia e non il programma.

La forma che funziona costa dieci righe: si esegue **il codice vero** dentro una transazione che
si annulla, si tiene solo il suo risultato, e si scrive quel risultato in **una riga sola** —
tipicamente il rapporto di un lotto — che si cancella dopo aver guardato.

```php
DB::beginTransaction();
try { $esito = (new LivelloX)->commit($ctx); } finally { DB::rollBack(); }
// poi si persiste solo il rapporto, e a video si legge il testo che spedisce la beta
```

Due proprietà che le alternative non hanno: a video compare **esattamente** la stringa che il
codice produce — se domani cambia, cambia lo scatto — e sul database resta una riga sola, di cui si
conosce l'identificativo. Il controllo finale non è «mi pare a posto»: si riconta ciò che la pagina
dichiara (*«13 cose ancora da sistemare»*) e si verifica che le entità di prova non siano
sopravvissute al rollback.


### Due controlli imparati nella beta.34

**Se un test non si riesce a scrivere, sospetta lo schema — non riscrivere il test.** Il difetto dell'avviso di pagamento richiedeva due saldi della stessa persona su due gestioni. Il test non partiva: violazione di vincolo. La tentazione è aggirarlo e andare avanti; aggirandolo si sarebbe perso che **lo schema dei test era più severo di quello di produzione** (un indice `UNIQUE` rimosso su MySQL e sopravvissuto su SQLite). Uno schema di prova più severo del vero non protegge: nasconde, perché rende non scrivibili proprio i test dei casi che in produzione accadono.

**Prima di costruire una voce di roadmap, cerca chi l'ha chiesta.** Lo sdoppiamento di `metodo_distribuzione` era in beta.34 da tempo. Cercandone l'origine — segnalazioni forum, `docs/`, changelog — non è emerso **nessun richiedente**: era una comodità plausibile, entrata in roadmap e mai più discussa. Costava una migrazione sull'unico salto senza backup. Una voce senza richiedente non è una voce urgente:

```bash
grep -ril "<parola chiave della voce>" docs/ && git log --oneline --all --grep="<parola>"
```

Se non esce niente, la domanda da fare a Vincenzo è «chi l'ha chiesta?», non «la faccio adesso?».

### Il controllo imparato nella beta.35

**Quando un numero è calcolato due volte, in due linguaggi, il test deve fissare entrambi i lati.** Il netto da pagare della fattura passiva viveva in due posti: i centesimi interi di `FatturaPassivaService` e un'anteprima TypeScript che sommava float. Divergevano di un centesimo, e il difetto è arrivato dal forum perché *nessuna delle due parti era sbagliata da sola* — solo insieme.

Da qui tre regole:

- Un'anteprima che il server ricalcolerà non è codice libero: **deve ricalcare operazione per operazione il calcolo autoritativo**, compresi gli arrotondamenti intermedi che sembrano irrilevanti (l'IVA per riga, la base ridotta della ritenuta). Scriverlo nei commenti, citando il file PHP, è parte del fix.
- Il test va scritto **da tutti e due i lati**: `resources/js/lib/gestionale/fatture/totali.test.ts` fissa l'anteprima, `tests/Feature/Gestionale/TotaliFatturaArrotondamentoTest.php` fissa il riferimento del server. Se domani cambia il PHP, è il secondo che si accende e ricorda che esiste il primo.
- Prima di correggere una duplicazione, **cercarne le altre copie**: lo stesso calcolo era identico in `FatturaRegisterNew.vue` e `FatturaRegisterEdit.vue`, e correggerne una sola avrebbe lasciato il bug vivo nella pagina da cui era arrivato lo screenshot.

Da questa beta esiste anche una suite JavaScript, che prima non c'era:

```bash
npm test          # vitest run — copre i moduli di calcolo in resources/js/lib/
```

Non sostituisce Pest: sono due suite indipendenti e vanno verdi entrambe prima del port.

### I due controlli imparati nella beta.36

**Un modulo che espone una sola direzione della conversione fabbrica il bug successivo.** `money.ts` aveva `euroToCents` e non il contrario. Chi doveva precompilare la casella delle commissioni in `PagamentoEdit.vue` partendo dai centesimi del database non ha trovato niente da chiamare, se l'è scritta a mano — e l'ha scritta al rovescio, moltiplicando per 100 dei centesimi. 2,50 € si riaprivano come 25.000,00 € e tornavano a database moltiplicati per diecimila.

Il ×100 della beta.32 e questo sono lo stesso errore visto da due lati: là una moltiplicazione di troppo sul confine di ingresso, qua una moltiplicazione al posto di una divisione sul confine di uscita. La convenzione in `CLAUDE.md` («la conversione avviene una volta sola, al confine») copre il primo caso e non nomina il secondo, che è altrettanto frequente perché **ogni form di modifica ha un confine di uscita**.

- Quando si trova una conversione scritta a mano, non correggerla sul posto e basta: guardare se **la funzione che sarebbe servita esiste**. Se manca, il posto giusto del fix è il modulo, non la riga.
- Le due direzioni si scrivono e si documentano **insieme**, una accanto all'altra: sono `euroToCents` e `centsToEuro` in `resources/js/lib/gestionale/money.ts`.

**Un difetto che entra in partita doppia da entrambi i lati è invisibile al validatore di quadratura.** Le commissioni gonfiate producevano un DARE su «spese bancarie» e un AVERE sulla banca della stessa identica cifra sbagliata: `DoubleEntryValidator` non aveva niente da segnalare, e la scrittura era formalmente perfetta. La rete di sicurezza del modulo contabile controlla l'*equilibrio*, non la *grandezza*.

- [ ] Un test su un importo non si accontenta mai di `assertQuadraturaPerfetta()`: deve guardare **quanto** vale la riga, non solo che i due lati coincidano. Vedi `PagamentoCommissioniRoundTripTest.php`, che dopo la quadratura verifica l'uscita di cassa e il DARE spese bancarie uno per uno.
- Corollario per le segnalazioni: se un difetto di importo è arrivato dal forum ed è passato indenne fra i validatori, chiedersi **da quale parte era simmetrico**. È lì che di solito si nasconde.

**Dove si scrive il test quando il difetto vive nell'inizializzazione di un form.** Non in un modulo di `lib/`: l'errore stava dentro `useForm({...})` nel `<script setup>`, e una funzione pura estratta a posteriori l'avrebbe testata *dopo* averla già corretta. Si monta il componente, come fa `Confirm.test.ts` per la schermata di aggiornamento, e si legge il valore che arriva fino alla prop del `MoneyInput` — cioè il numero che l'amministratore si vede scritto nella casella. Due trappole già pagate:

- Gli `stubs` di Vue Test Utils **non intercettano un componente importato in `<script setup>`**: lì i componenti sono riferimenti risolti nello scope del modulo, non nomi cercati in un registro. Per `<Head>` di Inertia serve `vi.mock('@inertiajs/vue3', …)` con `importOriginal`, così `useForm` resta autentico.
- `route()` di Ziggy va messo su `globalThis` e non fra i `mocks`, perché il componente lo chiama anche fuori dal template (i breadcrumb, il `back-url`).

### I due controlli imparati nella beta.37

**Prima di credere a una segnalazione, verifica che il difetto sia RAGGIUNGIBILE.** Il segno ribaltato delle note di credito era vero sul codice, ma sarebbe bastata una di due cose a renderlo innocuo: una guardia che vietasse di modificare le note di credito (`motivoBloccoModifica()` ne ha nove, nessuna sul tipo di documento), o una validazione `min:0` sugli importi (`UpdateFatturaRequest` valida `required|numeric`, senza minimo). Erano due possibili falsificatori dell'ipotesi, e vanno cercati **prima** di scrivere il fix, non dopo.

Il modo per non dimenticarlo è mettere la raggiungibilità **dentro la suite**, non nella conversazione: in `NotaCreditoModificaSegnoTest.php` c'è un test che si chiama *«la nota di credito è davvero modificabile: nessuna guardia la ferma»*. Se domani qualcuno aggiunge quella guardia, quel test si accende e dice che il fix accanto ha smesso di servire a qualcosa — informazione che altrimenti nessuno recupererebbe.

Il metodo, in tre domande, da fare in quest'ordine su ogni segnalazione da audit:

1. Il codice fa davvero quello che l'ipotesi dice? *(leggere, e rifare i conti a mano)*
2. Ci si arriva? *(guardie, permessi, validazione, stati che escludono il caso)*
3. Cosa lo avrebbe dovuto intercettare, e perché non l'ha fatto? *(se la risposta è «niente», il difetto è più grave di come è stato descritto)*

**Un fix che riallinea due pagine cambia comportamento visibile: dirlo nel changelog.** Portando gli importi delle note di credito in valore assoluto, il controllo di sforo budget ha cominciato a contarle come spese — cosa che nella pagina di *registrazione* faceva già, perché lì i numeri sono positivi da sempre. Non è una regressione: è la modifica che si allinea alla registrazione. Ma per l'amministratore è un avviso nuovo su una schermata che prima taceva, e va scritto nel changelog **nella beta che lo introduce**, sotto una voce sua — altrimenti arriva come un difetto.

- [ ] Quando la correzione consiste nel far comportare A come B, chiedersi **cos'altro dipendeva da quella differenza**. Qui era il budget; la domanda vale per ogni riallineamento.
- [ ] Se la differenza scoperta è a sua volta discutibile *in entrambe* le pagine, non correggerla di slancio nella beta in corso: è un'altra decisione, va in roadmap con il suo perché. Allargare il fix è il modo più comune di trasformare una correzione in una regressione.

### I tre controlli imparati nella beta.38

**Prima di seguire un piano, verifica cosa di quel piano è già fatto.** Il design dell'F24 dichiarava sei fasi in ordine, con la Fase 3 dipendente dalla Fase 2. Leggendo lo schema è emerso che `righe_f24` **non ha riferimenti in uscita** verso `ritenute_operate`: la dipendenza era nel documento, non nei dati. Costruire la Fase 3 da sola era possibile — e ha evitato di infilare nella release senza backup automatico la fase che il design stesso marca *«qui vive tutto il rischio»*.

- [ ] Un ordine dichiarato in un documento di design è un'ipotesi, non un vincolo: la prova sta nelle **dipendenze reali fra tabelle e classi**.
- [ ] Se una fase si può staccare, dirlo nel documento con il *perché* — altrimenti al giro dopo qualcuno riapplica l'ordine originale.

**Quando la UI si discosta dalle altre, la discontinuità si paga più dell'estetica.** Lo scadenzario F24 era nato come lista di schede: gerarchia dell'urgenza, blocco calendario, barra colorata. Più leggibile della tabella, e sbagliato lo stesso — perché costringeva a reimparare dove si ordina, dove si filtra, dove si clicca. Portato a `DataTable` come tutte le altre, l'urgenza è diventata una colonna e non si è perso niente.

- [ ] Prima di disegnare un elenco nuovo, guardare `admin/eventi` e i `Datatable` esistenti: il default è **conformarsi**.
- [ ] Un componente su misura si giustifica solo se la tabella rende *impossibile* qualcosa, non se la rende meno bella.

**Le costanti di dominio non si riscrivono nei testi dell'interfaccia.** La guida della pagina F24 diceva «soglia € 500» e «€ 100» battuti a mano, mentre i valori veri vivono in `config/fiscale.php` e li usa il motore di calcolo. Due copie della stessa cifra: il giorno che il legislatore la cambia, la schermata continua a raccontare il numero vecchio mentre il sistema ne calcola un altro — e nessuno se ne accorge, perché la pagina è convincente.

- [ ] Ogni numero di legge mostrato a schermo deve **arrivare** dalla sua unica fonte, non essere ridigitato accanto.
- [ ] Vale anche per le frasi di sistema salvate a database: la prima versione di questa beta scriveva il motivo della scadenza come testo nel record, e correggere una parola lasciava le righe già create con la versione vecchia. Un **codice** nel dato, la frase nell'interfaccia.

### I due controlli imparati nella beta.39

**Prima di scrivere «non si può ancora fare», guarda la fonte.** La beta.38 aveva dichiarato che il modello F24 cartaceo non era producibile perché servivano campi rimandati alla 1.11 — firmatario, codice carica, intermediario, IBAN di addebito. Quella frase non era stata verificata contro il modulo dell'Agenzia: era stata **dedotta dalla compagnia** in cui la voce si trovava nel documento di design, che la elencava nella Fase 6 insieme al tracciato telematico. Aprendo il modulo, casella per casella, quei campi sul foglio non ci sono: sono del telematico e dei dichiarativi. Il lavoro è costato una giornata e **zero migrazioni**.

Il difetto non è aver sbagliato la stima: è che la stima è diventata un fatto. Una volta scritta, la frase è stata replicata in **cinque punti** — changelog, guida in-app, due docblock nel codice, sito — e in nessuno di quei cinque è stata rimessa in discussione, perché sembrava già decisa altrove. Chi l'ha letta ha trovato una motivazione precisa e circostanziata, che è esattamente ciò che rende una frase falsa difficile da smontare.

- [ ] Un rinvio motivato da un **elenco di prerequisiti** va verificato sulla fonte prima di essere scritto, non dopo che qualcuno lo contesta. È lo stesso principio della beta.38 («un ordine dichiarato in un documento di design è un'ipotesi»), applicato al *costo* invece che alla *sequenza*.
- [ ] Attenzione a come un documento di progettazione raggruppa: una fase che mette insieme lavori simili **nell'esito** — «tutti i modi di produrre un F24 per l'esterno» — e non nei **prerequisiti** fa ereditare a quello più economico il costo del più caro.
- [ ] Quando una motivazione di rinvio si rivela falsa, correggerla in **tutti** i posti in cui è stata copiata. Il grep è l'unico modo di ritrovarli: qui erano `grep -rn "modello ministeriale\|Fase 6"` su `app/`, `resources/js/`, `docs/` e la cartella del sito.

**Una stampa a impaginazione fissa si verifica contando le pagine, non guardando che «esce».** Il modulo F24 ha nove fasce e trentadue righe di caselle: le prime misure lo mandavano a **due pagine per copia**, e un F24 su due fogli non è un F24. Il PDF si generava senza errori, nessun test si accendeva, e a video la prima pagina sembrava perfetta.

- [ ] Per un documento che *deve* stare in una pagina, la verifica è `$mpdf->page` contro il numero di fogli attesi — un numero, non un'impressione.
- [ ] Le misure vanno lette come vincolo: se serve recuperare spazio, si tolgono decimi di millimetro dalle altezze ricorrenti (le caselle, le fasce), non si accorcia il contenuto. Un modulo con una sezione in meno è un modulo diverso.

### Il controllo imparato nella beta.40

**Quando un difetto emerge da un caso, chiediti qual è la sua classe — e correggi quella.** Nella beta.38 un test aveva scoperto che una ritenuta di ottobre seguita da una del 10 dicembre veniva trascinata al 16 gennaio. È stato corretto **quel caso**: dicembre si separa prima di accumulare. La regola generale — «il gruppo si chiude se la prossima ritenuta cade dopo la data-limite» — è rimasta sbagliata, e la **finestra gemella di giugno** ha continuato a produrre versamenti tardivi per un ciclo intero, finché non l'ha trovata una verifica sistematica contro i testi dell'Agenzia.

Il difetto era invisibile per tre motivi che vale la pena riconoscere altrove:

- **serviva una combinazione, non un dato**: una scadenza sola era sempre corretta, sbagliava solo la coppia «pagamento prima» + «pagamento nella prima metà del mese di chiusura»;
- **il test che sarebbe servito somigliava molto a uno che c'era già**. Esisteva «marzo e settembre non stanno nella stessa delega», e passava — perché settembre cade *dopo* il 16 giugno. Mancava la mezza mensilità fra il 1° e il 15;
- **la correzione precedente aveva reso il codice più convincente**, non più giusto: il commento accanto al partizionamento di dicembre spiegava bene un caso, e leggendolo si aveva l'impressione che il problema fosse stato affrontato.

- [ ] Dopo aver corretto un caso, cercare i **gemelli**: altre finestre, altri mesi, altri rami dello stesso `match`, l'altra direzione della stessa conversione. Se il fix è un `if` in più accanto alla regola invece che dentro la regola, è un indizio che la classe è ancora aperta.
- [ ] Quando la correzione consiste nel cambiare una condizione, scrivere anche i test **che non devono cambiare comportamento**: qui tre controprove verificano che due ritenute della stessa finestra restino in una delega sola. Sostituire un versamento tardivo con una raffica di deleghe inutili non sarebbe stata una correzione.
- [ ] Le date di confine vanno esercitate **sul confine**: il 16 giugno, non il 20. È il posto dove le regole di calendario si rompono, ed è quello che nessuno prova a mano.

**Una suite verde non dice che il dominio è coperto: dice che è coperto quello che qualcuno ha pensato di scrivere.** Trentaquattro test sul modulo F24, e nessuno toccava quella mezza mensilità. La verifica che l'ha trovata non partiva dal codice ma **dalla fonte normativa**, regola per regola, pretendendo per ognuna il `file:riga` che la implementa — ed è un esercizio che conviene rifare su ogni modulo che tocca adempimenti con scadenze.

### I due controlli imparati nella beta.41

**Un enum dichiarato e mai applicato è peggio di un enum mancante.** `MotivoEsclusioneRitenuta::BONIFICO_PARLANTE` esisteva, con tanto di etichetta corretta; la casella sul pagamento esisteva, validata, salvata ed esposta; il tipo di detrazione e i beneficiari pure. Mancava una riga: il calcolo della ritenuta quel flag non lo leggeva. Chi apriva il codice trovava un modulo che *sembrava* completo, ed è esattamente il motivo per cui il difetto è sopravvissuto a due beta.

- [ ] Quando si trova un enum o una colonna che descrive un comportamento, cercare **chi lo consuma**: `grep -rn "<NomeEnum>" app/ --include='*.php'` escludendo la sua dichiarazione. Zero chiamanti su un caso di dominio non è codice morto innocuo — è una funzione promessa e non mantenuta.
- [ ] Vale anche al contrario: prima di aggiungere una colonna, chiedersi se il dato si **deriva**. Qui il motivo dell'esclusione non è stato persistito — un pagamento con la spunta e ritenuta a zero *è* quell'esclusione — e si è evitata una migrazione e una fonte di divergenza.

**Una guardia stretta va progettata insieme ai casi in cui deve stare zitta, o blocca proprio il comportamento corretto.** La guardia di coerenza della beta.38 rifiuta un importo di ritenuta che non corrisponde alle fatture pagate. Giusto — ma la schermata di modifica rimanda il valore già salvato, che dopo un cambio di spunta è per costruzione quello vecchio: la guardia avrebbe rifiutato il salvataggio, e l'amministratore avrebbe visto un errore incomprensibile dopo aver toccato una casella.

La prima versione della sospensione era **a metà**: copriva la spunta messa e non quella tolta. L'ha trovata un test, non un ragionamento.

- [ ] Quando una guardia va sospesa, la condizione non è «lo stato è X» ma **«la scelta è cambiata»** — e va verificata in *entrambi* i versi. Un test per verso, sempre.
- [ ] Il criterio per sospendere dev'essere **dichiarativo nel nome**: qui il parametro si chiama `$ritenutaRideterminata`, non `$bonificoParlante`, perché il motivo per cui la guardia tace è che il server ha ricalcolato — non che una casella sia spuntata.

### I tre controlli imparati nella beta.42

**Un `??` sul confine di scrittura spegne il default dello schema, e nessuno dei due mente da solo.** Il giorno di scadenza delle rate nasceva al **1** del mese mentre la migrazione dichiara `integer('giorno_scadenza')->default(5)`. Il colpevole era una riga sola in `PianoRateCreatorService`: `$data['giorno_scadenza'] ?? 1`. Siccome l'`insert` riempiva sempre la colonna, il `default(5)` non ha mai avuto occasione di applicarsi — il database era pronto a dire cinque e non gli è mai stato chiesto.

Il difetto è insidioso perché **il valore vero del prodotto non era in nessuno dei due posti in cui si va a cercarlo**: chi leggeva la migrazione trovava 5, chi leggeva il form trovava una casella vuota, e il piano usciva con 1. Nessuno dei due documenti era falso: era falso il pezzo di codice in mezzo, che nessuno legge perché sembra idraulica.

- [ ] Quando lo schema dichiara un `default`, il codice di creazione **non deve fornire un valore di ripiego proprio**: o si omette la chiave e decide il database, o la costante è **una sola** e la usano entrambi. Qui la conclusione è stata `PianoRateCreatorService::GIORNO_SCADENZA_PREDEFINITO`, richiamata anche da chi legge.
- [ ] Il test che serve non è sul valore, è sul **silenzio**: creare l'entità *senza* quella chiave e verificare che esca il default dichiarato. È l'unico modo di accorgersi che un `??` sta parlando al posto dello schema.
- [ ] Vale per ogni default nascosto, non solo per le colonne: i valori iniziali di `useForm`, i parametri di default delle funzioni, i `?:`. Un default che nessuno ha scritto in una specifica **è comunque una decisione di prodotto** — e questa l'aveva presa una riga di idraulica.

**Una chiave di idempotenza che contiene un dato mutabile è corretta finché quel dato non si muove.** Il controllo anti-duplicato del promemoria a calendario chiudeva anche sulla **data** della rata, mentre la rata è già identificata dal suo numero. Per un anno è stato indifferente, perché le scadenze non si spostavano. Questa beta le ha rese spostabili: senza la correzione, ogni spostamento avrebbe lasciato in calendario l'evento vecchio accanto al nuovo — non un errore di calcolo, una moltiplicazione di righe.

- [ ] Quando una beta rende **modificabile** qualcosa che prima era fisso, cercare tutti i posti che quel campo lo usano come *identità*: chiavi di idempotenza, indici unici, `firstOrCreate`, confronti di deduplicazione, cache key. La domanda è «cosa dava per scontato che questo non cambiasse?».
- [ ] È il complemento della lezione della beta.40 sui gemelli: là si cercano gli **altri casi della stessa regola**, qui si cercano gli **altri lettori dello stesso dato**.

**Le Fasi 5 e 6 possono slittare di un giorno — la Fase 4 no, e questo le lascia orfane.** La beta.42 è stata committata la sera del 04/08 con guida, articolo e social ancora da fare; il giorno dopo, riaprendo, la traccia di cosa mancasse esisteva solo perché era stata scritta a parte. Il commit chiude la beta e `git status` torna pulito: da quel momento niente nel repository ricorda che il sito è indietro.

- [ ] Se la Fase 5 non si chiude nella stessa sessione della Fase 4, **scriverlo prima di committare** — nel changelog no (è per gli utenti), ma in un punto che si rilegge all'apertura della beta dopo.
- [ ] **L'utente di servizio degli screenshot va cancellato lo stesso giorno in cui è creato.** La riga di cleanup sta in fondo alla sezione delle immagini, cioè nel punto che si rilegge di meno; quando il lavoro sul sito attraversa due giornate non la rilegge nessuno. Verificato il 05/08/2026: `shot@kondomanager.local` era ancora attivo a database il giorno dopo l'uso. È un account amministratore sul database **condiviso dai due checkout**, non un residuo estetico.

### I quattro controlli imparati nella beta.43

**Rendere rappresentabile un valore che prima non lo era apre un buco dove quel valore non c'era.** La migrazione che ha reso scrivibile il ruolo `nuda_proprietario` ha rotto, nello stesso istante, il riparto delle spese straordinarie sulle unità con usufrutto: una guardia nel motore dava per scontato che quel ruolo non potesse esistere, e con il ruolo registrato non trovava più nessuno da addebitare. Il difetto non stava nel codice nuovo — stava in codice vecchio che era corretto **solo finché il database non poteva rappresentare quel caso**.

È il complemento esatto della lezione della beta.42: là si era reso **mutabile** un dato che stava fermo, qui **rappresentabile** un valore che era assente. Stessa domanda in entrambi i casi, da farsi prima di scrivere la migrazione:

- [ ] **«Chi dava per scontato che questo non esistesse?»** Si cerca fra le guardie, i `match` senza il caso, i fallback che partono dal presupposto che una lista sia esaustiva, e i `default` scritti quando i valori possibili erano meno.
- [ ] Il difetto è stato trovato togliendo lo `->skip()` a test fermi da un ciclo. **Uno `skip` con la condizione scritta dentro è un test che si accende da solo quando la condizione cade**: vale la pena spendere una riga sul *perché* è saltato, invece del solo «non implementato». Quelli della cascata dicevano «`nuda_proprietario` non ancora in enum», ed è per questo che è bastato rileggerli.

**Un fallimento che assomiglia a una risposta è peggio di un errore.** L'endpoint che alimenta la modale dei saldi rispondeva **500** per una closure a cui mancava una variabile nel `use`. A schermo però non compariva un errore: compariva *«Nessun saldo pregresso per questa gestione»* — una frase plausibile, che un amministratore avrebbe creduto. L'ha trovato l'occhio, non la suite, perché quell'endpoint non aveva test.

- [ ] Quando una schermata ha uno **stato vuoto legittimo**, verificare che non sia lo stesso messaggio che comparirebbe se la chiamata fallisse. Se lo è, il fallimento è invisibile per costruzione.
- [ ] Gli endpoint che alimentano una modale o un pannello hanno bisogno di un test come le pagine: non avere UI propria non li rende meno percorsi.

**Il controllo delle tracce va eseguito prima del port, non dopo il commit.** In questa beta ha pescato un `CLAUDE.md` citato dentro un commento del codice. Non era distrazione: era il riferimento naturale mentre si spiegava una convenzione, ed è esattamente il modo in cui una traccia finisce in un repository pubblico. Il comando è in Fase 4 da sempre; la correzione è **quando** si lancia.

```bash
git grep -il "claude\|anthropic" -- . | grep -v "^\.claude"
```

- [ ] Va fatto **in TEST prima di copiare**, così l'ufficiale non lo vede mai. Farlo dopo significa correggere due cartelle invece di una — e se il commit è già partito, non correggerlo affatto.

**Quando si apre un'area, il suo documento di design è una lista di lavori già scritta.** La beta.43 non ha progettato quasi niente: l'ADR dei saldi iniziali descriveva dal 2026 tre comportamenti — filtra i titolari, ripartisci al centesimo, blocca se non c'è nessuno — e le rettifiche del 31/07 certificavano una per una che **nessuno dei tre esisteva in codice**. Il lavoro è stato far fare al codice quello che il documento diceva già.

- [ ] Prima di progettare in un'area, leggere le **rettifiche** del suo documento: sono difetti verificati, con la prova allegata, che qualcuno ha già trovato e nessuno ha ancora corretto. È il posto con il miglior rapporto fra costo e resa dopo il grep dei simboli spariti.
- [ ] Quando una rettifica viene chiusa, **lasciarla dov'è con il ✅ accanto** invece di riscrivere il paragrafo: la storia di come ci si è arrivati vale più della versione pulita, e la prossima persona capisce perché quel codice ha quella forma.

*Trappola minore, ma costa mezz'ora ogni volta:* `expectsOutputToContain` concatenato **consuma le asserzioni una riga di output per volta e in ordine**, quindi verificare due frasi che stanno sulla stessa riga fallisce anche quando l'output è giusto. Per i test dei comandi conviene leggere l'output intero con `Artisan::output()` e verificarlo in un colpo solo.

### I due controlli imparati nella beta.44

**Una regola corretta in un solo strato non è una regola corretta.** La beta.33 aveva spostato la soglia del blocco dei saldi e l'aveva coperta con venticinque test — tutti sul motore. Il pannello continuava a decidere su una colonna che significa un'altra cosa, e le due metà sono rimaste divergenti per **undici beta** senza che niente si accendesse: il server accettava una correzione mentre l'interfaccia mostrava il lucchetto.

Il difetto non era in nessuna delle due parti prese da sola. Era nel fatto che la stessa domanda — *questo saldo si può ancora correggere?* — avesse **due risposte in due posti**.

- [ ] Quando si sposta una soglia, un criterio o una definizione, cercare **chi altro risponde alla stessa domanda**: `grep` del nome della colonna vecchia in `resources/js/`, non solo in `app/`. Se il frontend legge un campo grezzo dove il backend usa un predicato, la divergenza è già lì e aspetta solo il caso giusto.
- [ ] Il segnale che è successo: un testo dell'interfaccia che **consiglia un'azione che non produce effetto**. Nella beta.44 la modale diceva «annulla le emissioni e il saldo torna modificabile» — vero sul server, invisibile all'utente. Un consiglio ineseguibile è quasi sempre una regola implementata a metà, non un errore di copy.

**Il primo test di un componente ripaga entro la giornata.** `SaldiDetailPanel.test.ts` non esisteva, ed è il motivo per cui il difetto qui sopra è sopravvissuto: la divergenza fra interfaccia e motore era **invisibile alla suite per costruzione**. Scritto il file, ha trovato subito un errore introdotto mentre lo si scriveva — un avviso finito dentro un `<Transition>`, che accetta un figlio solo.

- [ ] **`npx vite build` non è un controllo di correttezza del template**: quel `<Transition>` malformato compilava senza una parola. Il compilatore che protesta è quello del runtime, cioè quello che gira sotto vitest.
- [ ] **`v-show` lascia il nodo nel DOM.** Un avviso nascosto con `v-show` resta nel testo della pagina e per un lettore di schermo: per un contenuto che *non deve esserci* si usa `v-if`. Il test se ne accorge subito, la pagina no.
- [ ] Quando un componente decide cosa mostrare a partire da un dato del payload, il test va scritto **da entrambi i lati**: il payload lato server e il rendering lato client. È la regola della beta.35 applicata a un booleano invece che a un importo.

### I tre controlli imparati nella beta.45

**Un'azione con un solo chiamante è una funzione che il prodotto non ha.** `RegistraAperturaCassaAction` era scritta, transazionale, e gestiva perfino il conto scoperto invertendo i versi della scrittura. Era invocabile da **un posto solo**: la creazione della cassa. Il riquadro di quadratura sapeva diagnosticare lo sbilancio, nominarne la causa e linkare la pagina giusta — e in quella pagina non c'era niente da cliccare.

È la lezione della beta.41 sull'enum dichiarato e mai applicato, spostata di un gradino: là mancava **il lettore** del dato, qui manca **il punto di ingresso** della funzione. Lo stesso `grep` le trova entrambe.

- [ ] Per ogni Action, Service o comando che *ripara* qualcosa, contare i chiamanti: `grep -rn "<NomeAction>" app resources/js` meno la dichiarazione. Uno solo, e per giunta su una creazione, significa che la riparazione non è raggiungibile da chi ha il problema.
- [ ] **La regola che ne esce vale per ogni controllo che segnala:** prima di considerarlo pronto, rispondere alla domanda *«e adesso questa persona cosa clicca?»*. Una diagnosi senza cura non è un lavoro a metà — lascia l'amministratore **peggio di prima**, perché ora sa di avere un problema e non ha niente da farne. Nella beta.45 la scorciatoia che restava era eliminare il conto corrente: semaforo verde, soldi ancora in banca, contabilità che non lo sa più.

**Il gemello di un difetto corretto in un verso solo sopravvive a chi l'ha corretto.** La guardia sul saldo di apertura era già stata sistemata in una beta precedente per il verso *«il campo resta modificabile dopo che l'apertura è a giornale»* — riscriverlo contava il saldo due volte. Il verso opposto — *«il campo si scrive quando l'apertura non c'è ancora»* — è rimasto aperto **undici versioni**, con lo stesso campo, lo stesso metodo e lo stesso effetto sul bilancio.

La beta.40 aveva già scritto qui la regola «dopo aver corretto un caso, cerca i gemelli». Non è stata applicata, e questo dice qualcosa sulla forma della regola: cercare i gemelli funziona quando si cerca un *altro caso* (un altro mese, un altro ramo del `match`), ed è molto più difficile quando il gemello è la **direzione opposta della stessa guardia**, perché a leggerla sembra già completa.

- [ ] Davanti a una guardia su un campo, chiedersi sempre le due domande separatamente: *cosa succede se lo scrivo quando non dovrei?* e *cosa succede se non lo scrivo quando dovrei?* Sono due difetti, e correggerne uno rende l'altro **meno** visibile, non più.
- [ ] Un commento che spiega bene un caso è un segnale di rischio, non di sicurezza: rende il codice convincente. Vale la stessa nota della beta.40.

**Un `bool` di ritorno su un'operazione che può fallire per cinque motivi è un canale che perde.** L'azione tornava `false` per cinque ragioni diverse — importo a zero, conto contabile mancante, esercizio assente, contropartita assente, apertura già presente — e ne scriveva una sola in un file di log che nessuno apre. Chi cliccava vedeva la pagina ricaricarsi senza niente.

Sostituito con un enum di esito, sono emersi due casi che **non** erano fallimenti: importo a zero e apertura già registrata. Prima avevano la faccia dell'errore, ed è la parte peggiore: un messaggio d'errore su uno stato corretto insegna a diffidare anche di quelli veri.

- [ ] Quando un metodo torna `bool` e i rami che portano al `false` sono più di due, il tipo di ritorno è l'informazione che manca all'interfaccia. Un enum di esito costa dieci righe e trasporta la via d'uscita fino allo schermo.
- [ ] Separare, dentro l'enum, gli esiti **riusciti** da quelli **già a posto**: `riuscita()` e `giaAPosto()` sono due domande diverse, e collassarle in `!fallito` è il modo in cui torna l'errore su uno stato corretto.

*Trappola minore, ma cieca per metà:* la query della diagnosi filtrava le casse con `saldo_iniziale > 0`, quindi un conto **scoperto** — saldo di apertura negativo, caso che l'azione gestisce esplicitamente — non veniva mai segnalato. Un filtro di segno dentro un controllo diagnostico nasconde una metà dei casi senza mai sembrare rotto: la condizione giusta è `!= 0`.

*Nota sull'utente di servizio degli screenshot:* la regola della beta.42 («si cancella lo stesso giorno in cui è creato») è stata violata di nuovo, e stavolta per un motivo legittimo — due sessioni in parallelo stavano usando lo stesso account per il sito. Quando succede, la cancellazione va **intestata a qualcuno**: chi chiude per ultimo il lavoro sul sito, non chi ha creato l'utente.

### I due controlli imparati nella beta.46

**Rendere cliccabile qualcosa che prima non lo era apre un ramo del motore che nessuno aveva mai percorso.** La riga a «saldo misto» mostrava «N/D»: nessun pulsante, nessuna strada. Aggiungerne uno ha reso raggiungibile con il mouse il ramo comproprietario di `StoreIncassoRateAction:357-377` — quello che sposta denaro fra due persone *(la riga diceva `:281-297`, corretta il 20/08/2026: quelle righe oggi sono l'eccedenza di cassa. Il verificatore non lo pesca, perché l'intervallo esiste comunque — è il caso che questo documento descrive alla voce «il livello che non deve stare in prosa»)* — e, sull'altro verso, una `RuntimeException` non catturata, cioè una pagina 500 con la distribuzione fatta a mano buttata via. Quale dei due capitasse dipendeva **dall'ordine di inserimento di due righe a database**.

È la terza volta che questa famiglia di lezioni si ripresenta: la beta.42 aveva reso **mutabile** un dato fermo, la beta.43 **rappresentabile** un valore assente, questa **raggiungibile** un ramo morto. La domanda è sempre la stessa e va fatta prima di scrivere:

- [ ] **«Chi dava per scontato che qui non si potesse arrivare?»** Si cercano le eccezioni non catturate a valle, i rami scritti per un caso deliberato che ora diventa accidentale, e le guardie che si fidano di un filtro applicato altrove.
- [ ] **Una schermata ha più modalità della modalità in cui la si sta guardando.** Qui il difetto viveva solo nella ricerca **per immobile**, dove il gruppo raccoglie le quote di tutti i comproprietari; nella ricerca per persona non era riproducibile. Il test nuovo copriva la modalità sicura.
- [ ] Quando la correzione dipende da un dato che il payload non porta — qui `anagrafica_id` sulla quota — il ripiego prudente è **non offrire**, non indovinare. Su denaro altrui non esiste un default ragionevole.

**Un test che asserisce su una chiave sola del risultato lascia passare la divergenza su tutte le altre.** `CompensabileCreditoTest::il_credito_di_una_rata_mista_va_su_un_altra_rata_non_su_se_stesso`
(`tests/Feature/Gestionale/CompensabileCreditoTest.php:279`) costruiva esattamente lo stato sbagliato e verificava solo `origini[]`, mai `rate_coperte[]`: il consiglio nominava come bersaglio una rata su cui nessun salvataggio è costruibile, e la suite restava verde. Il test non era debole per distrazione — era stato scritto per dimostrare *una* cosa, e ha dimostrato solo quella.

- [ ] Quando un metodo restituisce una struttura, il test che ne fissa una parte deve dire **cosa lascia scoperto**, o coprire l'intera forma. Un `assertSame` su una chiave in mezzo a sei è una copertura che *sembra* copertura.
- [ ] Il segnale d'allarme: un test il cui nome promette più di quanto il corpo verifichi. Qui il nome diceva «espone comunque il suo credito», e il corpo non guardava dove quel credito sarebbe finito.

### La lezione dell'apertura della beta.47

**Un documento che descrive materiale esterno si degrada in silenzio fra una sessione e l'altra.** Il capitolo Danea di `import_migrazione_dati.md` descriveva gli export con precisione — dieci colonne, ruoli scritti per esteso, un report «fuori scope» — ed era **sbagliato su tutti e tre i punti**: l'elenco unità ha 33 colonne, i ruoli sono codici a due lettere (`Pr`/`Co`/`Us`), e il report escluso è in realtà la fonte dei capitoli di spesa. Era stato scritto leggendo i file veri in una sessione precedente, e riscritto **a memoria** in quelle dopo.

Il difetto non è la memoria: è che **la pagina non distingueva una descrizione verificata da una ricordata**. Anzi peggiorava, perché era circostanziata — ed è esattamente ciò che rende una descrizione falsa difficile da mettere in dubbio (stessa dinamica della beta.39, dove una stima era diventata un fatto e si era propagata in cinque punti).

L'intestazione `<!-- verifica-documentazione -->` risolve questo problema per il **codice**: dice quando il documento è stato confrontato con il repository. Non lo risolve per le **fonti esterne** — export di altri gestionali, moduli dell'Agenzia, pagine di concorrenti — che non si possono grep-are.

- [ ] Una sezione che descrive materiale esterno dichiara in testa **dove si trova quel materiale** e **quando è stata confrontata con esso**. Senza il percorso, la prossima sessione non può rifare la verifica; senza la data, non sa che serve.
- [ ] Il materiale esterno con dati personali (gli export Danea contengono CF, indirizzi, email di condòmini reali) **resta fuori dal repository**: nel repo vanno solo fixture anonimizzate. Il documento dice dove sta l'originale, non lo incorpora — e non ne cita i dati come esempi.

### I quattro controlli imparati chiudendo la beta.47

*La lezione qui sopra è dell'**apertura**; queste vengono dalla chiusura, e la prima è quella che vale per ogni beta futura, non solo per l'importatore.*

**Una regola di dominio che il codice nuovo riscrive invece di chiamare torna sbagliata, e torna sbagliata in silenzio.** Chi risponde verso il condominio è chi ha un diritto reale — proprietà, nuda proprietà, usufrutto — e la regola vive in un posto solo dalla beta.43: `RuoloAnagraficaImmobile::titolariDiDirittoReale()`. L'importazione se l'era riscritta a mano, includendo il conduttore: un'unità con il solo inquilino risultava **a posto**, sarebbe rimasta fuori da ogni riparto, e il controllo automatico diceva verde.

È il terzo verso di una famiglia già scritta qui due volte — la beta.41 (l'enum dichiarato e mai **letto**), la beta.45 (l'azione senza **chiamanti**) — e questo è il più insidioso dei tre, perché la funzione esiste **ed è chiamata da altri**: nessun `grep` sui chiamanti la segnala, il modulo sembra sano da tutte le parti.

- [ ] Quando si scrive codice nuovo che deve decidere **chi paga, chi risponde, chi ha diritto**, la prima domanda non è «come si scrive» ma **«dove sta già scritta?»**. Il segnale d'allarme è una regola di dominio espressa due volte con parole diverse: un `whereIn` con la lista battuta a mano accanto a un enum che la stessa lista ce l'ha.
- [ ] Il grep che la trova non è sul nome della funzione — quello non c'è, è il punto — ma sui **valori**: `grep -rn "usufruttuario" app/` fa emergere ogni posto che ha riscritto l'elenco invece di chiederlo.

**Il gate dei prerequisiti appartiene all'orchestratore, non al passo.** Tre livelli dell'importazione scrivevano prima di sapere se dovevano fermarsi, e lasciavano in archivio le righe di un'operazione dichiarata non riuscita. Al tentativo successivo quelle righe risultavano «già presenti» e la schermata chiedeva decisioni di deduplica **che nessuno aveva mai posto** — cioè il fallimento si travestiva da conflitto, che è la forma peggiore, perché invita a risolverlo invece che a rifarlo.

Un passo che controlla i propri prerequisiti li controlla finché qualcuno non ne aggiunge uno e dimentica quel controllo. Nella forma corretta il singolo livello **non può** scavalcare il gate perché non ce l'ha in mano: sta in `ImportRunner`, con `PrerequisitoMancante` come esito tipizzato.

- [ ] Per ogni catena di passi che scrivono, chiedersi: *se il passo 5 fallisce, cosa resta a database dei passi 1-4?* Se la risposta è «dipende da come ciascuno si comporta», il gate è nel posto sbagliato.
- [ ] Un'operazione fallita deve lasciare l'archivio **come l'ha trovato**, o il tentativo successivo non è un ritentativo: è un caso nuovo, più difficile del primo.

**Un formato esterno si prova sui file veri, e i difetti che escono non sono i casi limite: sono i casi comuni.** Il motore aveva ~100 test e 120 verdi su fixture costruite a tavolino. Sugli export reali di due condomìni sono usciti subito due difetti, ed erano **il caso più frequente che esista** — la compravendita a metà anno, che Danea scrive nel ruolo come `ex Pr 336 gg`: il confronto era esatto su `ex Pr`, quindi la riga non risultava cessata e l'importazione si fermava con un errore irrisolvibile.

Le fixture le scrive chi ha capito il formato, e contengono quello che ha capito. Il valore anomalo che nessuno ha immaginato sta solo nel file vero.

- [ ] Prima di dichiarare finito un parser, farlo girare su **almeno due** esemplari reali di provenienza diversa, e mettere in suite i difetti che escono. I file veri restano **fuori dal repository** — contengono dati personali — ma la fixture anonimizzata che riproduce la forma ci entra.
- [ ] Il conteggio dei test verdi non è una misura di copertura del formato: `~100 test` e `due difetti al primo file vero` sono coesistiti nella stessa giornata.

**Una correzione fatta in TEST fuori dal perimetro della beta non entra nel port, e il reset di Fase 0 la cancella.** *(Verificato oggi, 10/08/2026, aprendo la beta.48.)* Durante la beta.47 il controllo delle maiuscole aveva trovato tre casi: l'h1 di un articolo del sito e «Fattura Non Ancora Approvata» nei due dialoghi dei pagamenti. Le due correzioni al gestionale sono state fatte in TEST l'08/08 e **non sono mai arrivate in ufficiale**, perché la Fase 3 porta «il delta della beta corrente» e quei due file la beta.47 non li toccava. Sono sopravvissute solo perché il `diff` fra cartelle della Fase 0.1 le ha viste.

- [ ] Una correzione di passaggio — un refuso, una maiuscola, un simbolo € posposto — su un file **che la beta non tocca** va portata subito, o annotata dove si rilegge. Il `git status` di TEST non la distingue dal resto e il port la salta.
- [ ] È il complemento della lezione della beta.42 sulle Fasi 5 e 6 orfane: là si perdeva il lavoro *dopo* il commit, qui si perde *durante* il port. In entrambi i casi la causa è la stessa — la traccia esiste solo in una cartella che sta per essere azzerata.

### I quattro controlli imparati nella beta.48

**La revisione avversariale rende di più quando è tutto verde, ed è proprio allora che sembra
inutile.** La beta.48 arrivava alla Fase 1-bis con 1191 test Pest e 124 vitest verdi, la verifica
a video fatta e tre difetti già corretti. La revisione ne ha trovati altri **tre, tutti scritti
quel giorno**, e il primo era il peggiore della beta: una guardia nuova che sarebbe arrivata
all'amministratore come **pagina 500**, buttando via la distribuzione appena fatta a mano.

Le suite verdi non dicono che il codice è giusto: dicono che fa quello che qualcuno ha pensato di
verificare. Su codice appena scritto, chi ha pensato le verifiche è la stessa persona che ha
scritto il codice, con le stesse premesse addosso.

- [ ] La revisione si fa **anche** — soprattutto — quando non si sospetta niente. Se si salta
      perché «è tutto verde», si salta esattamente nel caso in cui serve.
- [ ] Le tre lenti che hanno prodotto i reperti erano quelle con una domanda stretta: *chi altro
      risponde alla stessa domanda?*, *cosa diventa raggiungibile?*, *dove un numero cambia unità
      di misura?*. Nessuna diceva «cerca bug».

**Una guardia nuova nasce con la sua eccezione dedicata, o non arriva a destinazione.**
*(Vero fino alla beta.49, che ha chiuso la classe: oggi `IncassoRateController.php:198` cattura la
**famiglia** `IncassoNonRegistrabileException`, astratta con cinque sottoclassi. La lezione resta
perché descrive come ci si arriva.)* Fino ad allora `IncassoRateController::store()` catturava
**per tipo**: una `RuntimeException` generica lo attraversava e diventava una pagina 500 — e questo progetto lo ha già pagato nella beta.43, con un
commento nel controller che lo racconta a tre righe di distanza dal punto in cui l'ho rifatto.

- [ ] Prima di sollevare un'eccezione da un'Action, **leggere cosa cattura il chiamante**. Una
      guardia vale quanto la sua consegna: fermare l'operazione e perdere il lavoro dell'utente
      non è proteggerlo.
- [ ] Se le eccezioni generiche in quell'Action sono più di una, non correggere solo la propria:
      **è una classe, non un caso**. Vedi la coda ⑬ in roadmap, aperta per le tre sorelle
      preesistenti e **chiusa nella beta.49** (`docs/roadmap.md:886`) con una base comune di eccezioni.

**Correggere un difetto rende comune un ramo raro, e il ramo raro ha i suoi difetti.** La
correzione della coda ⑨ ha fatto comparire la finestra degli scoperti dove prima non compariva
quasi mai. Dentro quella finestra c'erano due difetti dormienti: gli importi **divisi per cento**
(€ 24.741,60 mostrato come € 247,42) e le righe senza immobile rese come «Immobile #» con un link
morto. Nessuno dei due l'ha introdotto la beta: li ha resi quotidiani.

È la lezione del «perimetro di raggiungibilità» della beta.46 spostata di un passo: là si
aggiungeva un **comando** nuovo, qui si è solo cambiata la **frequenza**.

- [ ] Quando una correzione fa comparire più spesso una schermata, quella schermata va riletta
      come se fosse nuova: chi l'ha scritta l'ha pensata per un caso che capitava di rado.
- [ ] Il segnale: una finestra che l'utente vedrà ora ogni settimana e prima vedeva una volta
      l'anno. Vale anche per i messaggi, i badge e i rami di `match`.

**Costruire prima di aver deciso costa più di quanto sembri, e la domanda giusta è già scritta
qui.** Il Validatore Coerenza Millesimi è stato progettato, migrato, testato — colonna, backfill,
servizio, quindici test verdi — e poi **ridimensionato a una riga di totale a video**, su una
domanda di Vincenzo che si poteva fare il primo giorno: *«non ho visto mai nessun gestionale fare
questo»*.

La beta.34 aveva già scritto la regola: *«prima di costruire una voce di roadmap, cerca chi
l'ha chiesta»*. Il Validatore era in roadmap dal 26/07 senza un richiedente — solo un ragionamento
interno, per quanto buono — e nessuno ha rifatto quella domanda prima di aprire l'editor.

- [ ] Per una voce **senza richiedente**, la prima verifica non è tecnica: *cosa fa il resto del
      mestiere?* Se nessun concorrente lo fa, o è un vantaggio o è un'idea che qualcun altro ha
      già scartato — e vale la pena sapere quale prima di costruirla.
- [ ] Il lavoro non è sprecato se l'analisi resta: il progetto è conservato con in testa **il
      motivo per cui non lo facciamo**, così chi lo riaprirà parte dalle misure e non da capo.
- [ ] Segnale che si stava esagerando: la correzione giusta ha **cancellato** quella scritta il
      giorno prima. Quando una modifica elimina codice recente invece di aggiungersene, di solito
      quel codice rispondeva alla domanda sbagliata.

### I cinque controlli imparati nella beta.49

**Alla terza volta si chiude la classe, non il caso.** Lo stesso difetto — un rifiuto di dominio
che esce come pagina 500 perché il controller cattura *per tipo* — è stato corretto nella beta.43,
poi nella beta.48, ogni volta per la singola guardia in lavorazione. Alla beta.49 ce n'erano
ancora tre. Il difetto non era «manca un `catch`»: era che **chi scrive la guardia successiva non
ha modo di accorgersene**, perché la 500 si vede solo eseguendo quel ramo, che è raro per
costruzione.

- [ ] Quando una correzione somiglia a una già fatta, chiedersi se la forma si ripete. Se sì, il
      lavoro è cambiare la forma, non fare la correzione una terza volta.
- [ ] Il presidio non è un commento: è un test. Quello che ha chiuso la voce legge il **sorgente**
      del file e fallisce se ricompare un `throw` generico. Un commento c'era già, e diceva il
      falso da undici giorni.

**Un rifiuto che il server pronuncia e la schermata non mostra vale quanto un rifiuto non
pronunciato** — anzi peggio, perché nei registri risulta gestito. Chiudendo la voce è emerso che
la schermata di incasso mostrava gli errori **solo** per due campi su tutti quelli possibili, e
che il pagamento fornitore ne gestiva dieci chiavi su undici. Le guardie «corrette» nelle due beta
precedenti non si erano mai viste.

- [ ] Una correzione lato server non è finita finché non si è verificato **dove** il messaggio
      compare a schermo. Il posto può non esistere.
- [ ] **Il verso in cui si sbaglia va scelto.** Un elenco *di inclusione* di chiavi d'errore è
      giusto il giorno che lo scrivi e muto alla prima chiave nuova. Rovesciarlo in *esclusione* —
      mostro tutto ciò che non ha già un posto suo — sbaglia mostrando due volte, non perdendo.

**Il presidio che non presidia.** Il piano per unificare motore e stampa del riparto prevedeva di
verificare l'additività lanciando il golden master. Il revisore ha mostrato che quel test **non ha
un solo conto multi-tabella**: l'unica aritmetica nuova non l'avrebbe mai eseguita, e sarebbe
restato verde con lo split completamente rotto.

- [ ] Prima di appoggiarsi a un test come rete, verificare che **esegua davvero** il codice che si
      sta per cambiare. «C'è un golden master» non è una risposta.
- [ ] E poi provare che morda: perturbare la cosa giusta e vedere il rosso. Sullo split è bastato
      invertire l'assegnazione del centesimo residuo.

**Il test che passa per il motivo sbagliato, due volte nello stesso giorno.** `toContain` di Pest è
**variadico**: il messaggio diagnostico passato come secondo argomento diventa un secondo termine
da cercare. E un'asserzione su `assertSessionHasErrors` passava anche quando il rifiuto arrivava
dalla validazione invece che dalla guardia.

- [ ] Il controllo negativo non è facoltativo: si disattiva la correzione e si guarda il test
      cadere. Oggi ha salvato quattro test su cinque gruppi.
- [ ] Le asserzioni con messaggio diagnostico si scrivono con le funzioni PHPUnit
      (`assertStringContainsString`, `assertNotSame`), che il messaggio lo accettano davvero.

**La verifica a video trova cose che i test sulla struttura dati non vedono.** Il PDF con la
colonna nuova mostrava «quote» e un totale «0» per una colonna che millesimi non ne ha. E dopo
aver tolto i campi morti dalle tabelle, la scheda in testa alla pagina continuava a prometterli.
Entrambi invisibili ai test, entrambi evidenti al primo sguardo.

- [ ] Dopo una modifica che cambia *cosa* si vede, guardarlo. I test dicono che i numeri sono
      giusti, non che la pagina non mente.

### La lezione dell'apertura della beta.50

**Il `diff` fra cartelle della Fase 0.1 non si sostituisce con `git diff origin/<branch>`.** In
apertura della beta.50 è stata usata la variante con git: sembra equivalente e non lo è, perché
non vede i file che esistono in una cartella e non nell'altra senza essere tracciati. Eseguito
dopo, quello prescritto ha segnalato quattro `lang/php_*.json` presenti solo in ufficiale —
generati, quindi innocui, ma è esattamente la classe di cosa che nella beta.36 stava per costare
due file.

- [ ] Il comando sta scritto per esteso nella 0.1: si copia, non si improvvisa una variante.

**Una sostituzione di testo può mordere dentro un'altra riga.** Togliendo tre scritture identiche
a indentazioni diverse, la riga a 16 spazi è **una sottostringa** di quella a 20: la prima
sostituzione ha colpito anche le altre due, lasciando la riga successiva disallineata. Nessun dato
perso, ma solo perché il diff è stato riletto.

- [ ] Dopo una sostituzione multipla, leggere il `git diff` riga per riga. Il conteggio delle
      occorrenze rimosse è il primo segnale: se non torna con quanto ci si aspettava, è successo
      qualcosa d'altro.

### Le quattro lezioni della beta.52

**1. «Verificato» va detto della cosa, non della query.** Tre errori nella stessa beta, tutti della
stessa famiglia: ho misurato *qualcosa* e ho riportato il risultato come se avesse misurato *la
cosa*.

- `scrollWidth === clientWidth` per dire «l'indirizzo non è troncato». È vero e irrilevante:
  `scrollWidth` è un intero e **non conta lo sbalzo dei glifi corsivi**, che con `overflow: hidden`
  vengono rasati. L'ultima lettera era tagliata di 1,65 px, e la verifica programmatica diceva che
  andava tutto bene mentre l'occhio di Vincenzo vedeva giusto.
- `lang/it.json` letto come «il file generato» per concludere che i file di lingua PHP non
  arrivano al frontend. È il **sorgente** dei testi in stile JSON; il generato è
  `public/build/assets/php_<locale>-*.js`. La conclusione era falsa, ci ho costruito sopra una
  modifica al controller e una segnalazione di difetto inesistente. **La prova che la smontava era
  a un clic:** una pagina che usa `trans()` con una chiave PHP ed è sempre stata tradotta.
- `GROUP BY immobile_id` per contare le unità con somma quote diversa da 100. Il perimetro della
  regola è `(immobile, tipologia)`: raggruppando per solo immobile, un'unità con proprietario al
  100 e inquilino al 100 risulta «200 %», che è legittimo. Quattro anomalie dichiarate, due vere.

- [ ] Prima di scrivere «verificato», rileggere la query o il selettore e chiedersi **quale
      domanda risponde davvero**. Se la risposta non coincide con la domanda che si voleva porre,
      non è una verifica: è un'altra misura.
- [ ] Quando la conclusione è **negativa e generale** («X non è possibile», «non arriva al
      frontend»), cercare il controesempio più economico prima di scriverla. Se una cosa del genere
      fosse vera, di solito qualcosa nel prodotto sarebbe già rotto in modo visibile — e non lo è.

**2. Togliere una compensazione senza togliere la causa peggiora le cose.** La colonna «Fuori
riparto» ha aggiunto la riga del titolare staccato, che mancava. Ma sulla stessa stampa esisteva
già un secondo difetto speculare — un soggetto *attaccato* dopo la generazione teneva un lordo
fantasma — e i due si annullavano: il gran totale tornava **per caso**. Aggiungendo la metà
mancante si sono sommati, e il documento è passato da corretto per coincidenza a sbagliato di
€ 600,00 su € 1.000,00.

- [ ] Quando una correzione aggiunge un pezzo a un calcolo che *tornava*, chiedersi perché tornava
      prima. Se la risposta non è ovvia, il pezzo mancante potrebbe essere due.

**3. Un difetto reale non giustifica una correzione irraggiungibile.** La correzione
all'importatore era motivata da un difetto accertato sui dati, ed è stata **ritirata dalla
revisione** perché chiedeva all'amministratore una decisione che nessuna schermata gli permetteva
di prendere: l'importazione si sarebbe fermata a metà senza via d'uscita. Da «duplica le
titolarità» a «non finisce più». È la terza domanda del perimetro di raggiungibilità — *ho i dati
(e i comandi) per decidere correttamente?* — applicata a valle invece che a monte.

- [ ] Prima di emettere una richiesta di decisione, verificare **dove** l'utente la vedrà e **con
      quali scelte**: l'elenco dei valori ammessi lato server e la schermata che li mostra. Se una
      delle due non contempla la scelta nuova, la funzione non esiste.

**4. Lo stile si prende da ciò che è simile, non da ciò che è vicino.** Due volte nella stessa
pagina: un contatore in `Badge` a pillola accanto a un pulsante squadrato in maiuscoletto, e un
pulsante in maiuscoletto dentro il footer di una modale dove tutto il progetto usa il `Button` in
tondo. In entrambi i casi avevo copiato il trattamento dell'elemento che stava dieci righe sopra.

- [ ] Prima di stilare un elemento nuovo, cercare **lo stesso tipo di elemento altrove** —
      `grep` sul componente, non sguardo alla riga accanto — e verificare se il progetto ha già una
      convenzione. Se ce l'ha e la si viola, l'elemento nuovo si legge come un dialetto.

### Le tre lezioni della beta.53

**1. Una suite verde su SQLite non dice niente su MySQL, e può dire il falso.** La migrazione della
beta.53 usava `dropConstrainedForeignId()` per ripulire uno stato parziale. Su MySQL quello emette
per primo `ALTER TABLE … DROP FOREIGN KEY`, che su un vincolo assente dà **errore 1091** — e lo
stato «colonna presente, vincolo assente» è esattamente l'unico che MySQL sa produrre, perché il
suo DDL non è transazionale. La suite non poteva vederlo: gira su SQLite, dove
`SQLiteGrammar::compileDropForeign()` con le colonne valorizzate **non emette alcuno statement**.
Il drop era un'operazione vuota che passava sempre.

È il rovescio della lezione della beta.34, e la forma peggiore delle due. Lì lo schema di prova era
**più severo** del vero e il test falliva su un problema che in produzione non c'era: fastidioso,
ma rumoroso. Qui è **più permissivo**, e non fallisce mai.

- [ ] Ogni migrazione che tocca **chiavi esterne** va provata una volta su MySQL vero, su un
      database usa e getta, ricostruendo a mano lo stato parziale. Costa cinque minuti.
- [ ] Quando si scrive «su questo driver non serve», scriverlo **come ipotesi da provare**. La mia
      correzione al 1091 conteneva l'affermazione «su SQLite il vincolo se ne va col `dropColumn`»:
      era falsa, la chiave *viene* creata anche lì, e il `dropColumn` nudo lasciava la definizione
      appesa. L'ha presa la suite, non io — perché stavolta SQLite era **più** severo.

**2. Una regola che vieta a valle ciò che offre a monte non è una regola: è una trappola.** La
profondità uno del legame «Pertinenza di» era scritta in tre punti, e tutti e tre guardavano il
**bersaglio**: che l'unità scelta come principale non fosse a sua volta una pertinenza. Nessuno
guardava il **soggetto**, cioè chi sta compilando — così la catena si costruiva partendo dall'altro
capo. E la stessa regola guardava **una sola delle due colonne** che rappresentano il legame, così
il menù dei principali offriva un box già legato via Tognoli.

- [ ] Una regola su una relazione va verificata **da entrambi i capi**, e su **tutte** le colonne
      che la rappresentano. Se il dato ha due forme alternative, ogni filtro che ne nomina una sola
      è incompleto per costruzione.
- [ ] Il menù che propone e la regola che valida devono avere lo stesso predicato. Se divergono,
      il primo è una trappola: l'amministratore sceglie ciò che gli è stato offerto e si prende un
      errore.

**3. I codici della coda in `roadmap.md` si erano scontrati, e nessuno se ne era accorto.** Le
quattro voci collocate il 15/08/2026 avevano riusato ⑱, ⑲ e ⑳, già occupati da voci più vecchie
della stessa coda. Da quel momento «coda ⑲» indicava due cose diverse, e due documenti esterni
puntavano alla voce sbagliata. Rinumerate in ㉒ ㉓ ㉔ ㉕ in Fase 2 della beta.53, con i rimandi.

- [ ] Prima di assegnare un codice nuovo si **contano i caratteri effettivi**, non un intervallo:
      ```bash
      grep -o '[①-⑳㉑-㉟㊱-㊿]' docs/roadmap.md | sort -u | tr -d '\n'
      ```
      Il prossimo libero non è «il successivo dell'ultimo che ricordo».

⚠️ **Corretto il 19/08/2026, aprendo la .61.** Il comando scritto qui era
`grep -o "[Cc]oda [⑴-㉟]" docs/roadmap.md | sort -u`, e aveva due difetti che lo rendevano peggio
che inutile: pretendeva la parola «coda» davanti al simbolo (che spesso non c'è) e leggeva un
intervallo che oggi vede **15 codici su 50**, quindi risponderebbe «il prossimo libero è ㊱» mentre
㊱–㊿ sono tutti occupati. È lo stesso comando scritto per evitare la collisione che la .53 ha
pagato: **una guardia che legge un intervallo invecchia quando l'intervallo si riempie.**

### Le cinque lezioni della beta.54

*Scritte in apertura della beta.55, rileggendo il changelog della .54 e il suo diff. La beta è stata
lavorata da un'altra sessione: queste lezioni sono ricavate da ciò che ha prodotto, non dal racconto
di chi l'ha scritta — vale la pena saperlo, perché una lezione dedotta è più povera di una vissuta.*

**1. Due segnalazioni che sembrano scollegate possono essere lo stesso difetto visto dai due lati, e
il perimetro si misura prima di correggere.** *«Se ho 50 record ma la visualizzazione è filtrata per
10, l'ordinamento si applica soltanto su quei 10»* e *«faccio una ricerca con 40 elementi per pagina
e me ne vengono fuori 10»* arrivavano da due amministratori diversi, su due schermate diverse.
Contando, il perimetro era **26 tabelle** paginate lato server e **zero** che mandassero
l'ordinamento al database: non il difetto di una schermata, una forma sbagliata replicata ovunque.

- [ ] Davanti a una segnalazione su un comportamento **trasversale** — paginazione, ordinamento,
      filtri, permessi — il primo passo non è aprire il file segnalato ma **contare quante schermate
      hanno quella forma**. Il numero decide se il lavoro è una correzione o una bonifica, e nessuna
      delle due si fa con il metodo dell'altra.
- [ ] Due segnalazioni arrivate ravvicinate su schermate diverse meritano la domanda «sono la
      stessa?» **prima** di essere lavorate separatamente.

**2. La correzione di una forma sbagliata va nel punto condiviso, o il chiamante successivo nasce
già sbagliato.** Sei elenchi ordinavano per data qualunque colonna si chiedesse, perché un servizio
comune applicava `ORDER BY created_at DESC` **prima** dell'ordinamento richiesto: ne usciva
`created_at DESC, colonna ASC`, e con date distinte — cioè sempre — il primo criterio decideva da
solo. La correzione non sta nei sei punti: sta nel trait, che ora azzera qualunque ordinamento
preesistente.

- [ ] Quando un servizio condiviso impone un ordine di default, chi chiede un ordine deve
      **sostituirlo**, non accodarcisi. Un `orderBy` che si somma a uno precedente è una risposta a
      metà che si presenta come completa.

**3. Un'intestazione cliccabile che non ordina è una promessa, e va tolta invece che lasciata
inerte.** L'agenda non nasce da una query sola — le occorrenze delle ricorrenze si generano in PHP e
si impaginano dopo — quindi non esiste un `ORDER BY` a cui appoggiarsi, e quattro intestazioni erano
cliccabili a vuoto. Sono state rese non cliccabili: ordinare davvero si può, ma sulla collezione e
prima di impaginarla, ed è una funzione da progettare invece che una riga da aggiungere.

È la famiglia della «diagnosi senza cura» della beta.45 e del «menù che propone ciò che la regola
vieta» della beta.53: **un comando che non fa quello che dice costa più di un comando assente.**

- [ ] Quando una funzione trasversale non è applicabile a una schermata, la si **rimuove da quella
      schermata**. Lasciarla inerte insegna che i comandi del prodotto non sono affidabili — e la
      lezione il lettore la generalizza a tutto il resto.

**4. Lo stesso parametro validato in sei modi diversi non è validato.** `per_page` arrivava senza
alcun limite in quindici richieste, con `max:100` in tre, `max:200` su quattro elenchi dei movimenti,
`between:10,100` sui condomini, `between:1,100` sugli utenti, e nelle deleghe F24 non era validato
affatto: `?per_page=1000000` passava e finiva dritto in un `LIMIT`.

- [ ] Quando lo stesso parametro compare in N request class, la domanda non è «qual è il valore
      giusto» ma **«perché ce ne sono N»**. La lista dei valori ammessi vive in un posto solo
      (`config/pagination.php`), la validazione la legge, e il selettore mostra la stessa lista.
- [ ] Anche qui **il verso in cui si sbaglia va scelto**: un valore fuori lista si **ignora**, non si
      rifiuta. Rifiutarlo avrebbe trasformato un segnalibro `?per_page=200` — legittimo fino al
      giorno prima sugli elenchi dei movimenti — in un errore che rimanda indietro senza spiegare
      niente.

**5. Un ordinamento senza secondo criterio fa comparire una riga due volte e sparire un'altra.**
Ordinando per un campo con valori ripetuti — uno stato, una tipologia — il database non garantisce
l'ordine fra i pari merito e può restituirli in ordine diverso a ogni pagina. Ogni ordinamento porta
con sé la chiave primaria come secondo criterio.

- [ ] `orderBy()` **interpola il nome della colonna**: la lista bianca delle colonne ordinabili non è
      una comodità di interfaccia, è la difesa dall'iniezione. E le colonne che non sono campi —
      «Ubicazione» che monta palazzina, scala e piano, «Soggetti» che contiene un elenco di persone —
      non sono ordinabili per costruzione: ordinarle significherebbe ordinare per *quante* persone
      ci sono.

**Coda: «non paginava affatto» si scopre solo provando a paginare.** Piani rate e piani dei conti
chiamavano una rotta che non esiste, e ogni cambio di pagina moriva in un errore JavaScript. È un
difetto **precedente** a quella beta, emerso solo perché per la prima volta qualcuno ha verificato
che quelle due tabelle paginassero davvero.

- [ ] Una funzione che nessuno ha mai esercitato non è «funzionante finché non si dimostra il
      contrario». Quando una beta tocca una **famiglia** di schermate, la funzione di base va
      esercitata su tutte, non solo su quelle che si stanno modificando.


### Le cinque lezioni della beta.55

*Beta nata da «sospendo un utente e continua a entrare» e finita con otto punti di autorizzazione
chiusi. Le lezioni non sono sulla sospensione: sono su **come si cerca** una classe di difetti.*

**1. Cercare le porte nei due versi: prendersi poteri e toglierli agli altri.** Le prime tre porte
trovate erano tutte del primo tipo — assegnarsi un ruolo, concedersi un permesso, costruirsi un
ruolo su misura. Rovesciando la domanda in *«e per **togliere**?»* ne sono uscite altre cinque, più
insidiose perché non somigliano a una scalata: revocare un permesso a un ruolo, revocarlo a un
utente, togliere la verifica email a un amministratore (che con il middleware `verified` equivale a
chiuderlo fuori), sospendere, reinvitare azzerando la password. **Il danno è lo stesso:
un'installazione governata da chi resta dentro.**

- [ ] Su una superficie di permessi, elencare le azioni in **due colonne** — chi guadagna, chi
      perde — e passarle entrambe. Coprire solo la prima dà la sensazione di aver finito.
- [ ] Il grep non è sul nome del controller ma **sul verbo**: `revokePermissionTo`,
      `syncPermissions`, `assignRole`, `email_verified_at`, `suspended_at`.

**2. Un'azione di governo scritta in un controller dedicato eredita le rotte, non le guardie.**
`RoleController` protegge i quattro ruoli di sistema e lo spiega perfino al frontend con
`is_protected`; il controller che revoca un permesso a un ruolo — file diverso, stessa materia — non
ne sapeva niente, e non aveva alcuna autorizzazione. È il difetto della beta.44 (la stessa domanda
con due risposte in due posti) applicato all'**autorizzazione** invece che a una soglia.

- [ ] Quando si mette una guardia su un'azione, cercare **tutti i punti che compiono quell'azione**,
      non solo quello da cui si è arrivati.

**3. Una guardia dentro un `try/catch` generico è un rifiuto travestito da guasto.** Già scritto per
le eccezioni di dominio nelle beta.43, .48 e .49 — qui la forma era nuova: `Gate::authorize()` e
`abort(403)` **dentro** il `try`, con un `catch (\Exception)` che li trasformava in «errore durante
l'operazione». Succede due volte nella stessa beta, in due file diversi.

- [ ] Le autorizzazioni stanno **fuori** dal blocco che cattura. Se il metodo ha un `catch` generico,
      la guardia va prima, sempre.

**4. Una regola che vieta si scrive sul *cambiamento*, non sullo stato.** La prima versione della
regola sui ruoli privilegiati vietava di **avere** quel ruolo invece che di **concederlo**: un
collaboratore non poteva più salvare la scheda di un altro collaboratore, nemmeno per correggergli
il nome. È la lezione della beta.41 — *la condizione non è «lo stato è X» ma «la scelta è
cambiata»* — e ci sono ricascato quattro beta dopo averla scritta.

- [ ] Ogni guardia su un campo va provata anche nel caso **«salvo senza cambiare quel campo»**. È il
      caso più frequente in assoluto e quello che nessuno prova.

**5. Un test che passa può passare per il motivo sbagliato, e il caso peggiore è quando conferma la
tua tesi.** Il test sul permesso concesso «per nome» risultava verde: sembrava la prova che quella
strada fosse chiusa. Era verde perché la richiesta veniva **rifiutata prima**, su un altro campo, da
una regola scritta male. La strada era aperta e il test la dichiarava chiusa.

- [ ] Su un test che **conferma** ciò che si sperava, guardare l'esito completo — stato, errori,
      stato finale del dato — non solo l'asserzione. Due minuti di `dump()` valgono la differenza
      fra una difesa e la sua illusione.

*Trappola di routing, costata una pagina senza permessi:* una rotta letterale sotto un prefisso già
coperto da `Route::resource` va registrata **prima** della risorsa, o la rotta di dettaglio la
cattura. `utenti/registro` finiva in `UserController::show()` — un metodo vuoto — quindi **200 con
pagina bianca e nessun controllo di permesso**, invece di un 404 che si sarebbe notato.

*Trappola di validazione:* il valore che arriva da un modulo può avere **due forme** — l'id e il
nome — e Spatie le accetta entrambe. Una regola scritta su una sola forma non è una regola: era
sufficiente mandare `roles=amministratore` invece dell'id per aggirarla.


### Le sei lezioni della beta.56

*Beta piccola di codice — sette file — e insolitamente istruttiva, perché quattro delle sei lezioni
non vengono dal difetto che la beta doveva chiudere: vengono da **come lo si è verificato**.*

**1. Un reperto di una correzione ritirata non muore con la correzione: ricompare dall'altro lato.**
La correzione della beta.52 era stata ritirata con quattro reperti «alta». Il quarto — *lettura e
`DELETE` filtrano `attivo = true`, la guardia sulla terna no* — è stato trovato di nuovo nella
revisione della .56, ma **specularmente**: non era la cancellazione a sbagliare, era la scrittura a
non avvenire, e un'unità restava senza titolare attivo in silenzio. Quando una correzione viene
ritirata, i suoi reperti vanno riletti al momento in cui arriva la sostituta, uno per uno: descrivono
un'asimmetria del codice, non un difetto di quella particolare implementazione.

**2. Ciò che calcola su numeri di versione va provato con la versione *successiva*, non con questa.**
`kondomanager:verifica-documentazione` calcolava l'età dei documenti in beta prendendo il numero più
alto citato, qualunque serie fosse. Oggi funzionava. All'apertura della 1.11 ogni documento verificato
nella 1.10 avrebbe avuto età **negativa** — «-51 beta fa» — e il comando esiste proprio per dire
l'età. La prova che l'ha smascherato è una riga: forzare `app.version` alla serie dopo e rileggere
l'uscita. Vale per qualunque cosa confronti versioni, date di rilascio o numeri progressivi.

**3. Un controllo che segnala per sempre la stessa cosa insegna a saltare l'elenco.** Il comando
chiedeva un'intestazione di stato anche a `changelog.md`, che per natura non ce l'avrà mai: un rilievo
che nessuno potrà mai chiudere. Quando si aggiunge un controllo, la domanda da farsi subito dopo è
*«quale voce di questo elenco non sarà mai chiudibile?»* — e o la si esclude con la sua ragione
scritta, o l'elenco intero perde credito. È la stessa dinamica del «grida al lupo 165 volte» che aveva
già affossato la prima versione dello stesso comando.

**4. Una verifica a video non richiede di sporcare i dati.** Vedere l'avviso nuovo voleva dire avere
un conflitto di titolarità che nel database di sviluppo non esiste. Fabbricarlo a mano avrebbe
richiesto di rimetterlo a posto — il gesto in cui si sbaglia. La forma che funziona è nella sezione
«Guardare a video uno stato che nei dati non c'è»: eseguire il codice vero dentro una transazione
annullata, persistere **solo** il rapporto, guardarlo, cancellarlo. Costa dieci righe e a video
compare esattamente la stringa che il codice produce.

**5. La riconciliazione di Fase 2 guarda una beta; l'elenco pubblico invecchia su un altro asse.**
Chiudendo la .56 è bastata una domanda di Vincenzo — *«cosa rimane da sviluppare nella 1.10?»* — per
far emergere che la roadmap pubblicata sul forum prometteva sette blocchi che avevano cambiato
destinazione, e che **questo** documento dava per da fare due voci uscite da tempo (l'annullamento
pagamento con motivazione, uscito nella 1.9.1-beta.6, e i giroconti, usciti nella beta.19). Nessun
controllo automatico poteva vederlo: risolvono tutti i link, citano file che esistono, e mentono.
La riconciliazione beta-per-beta non basta — **serve, ogni tanto, rileggere l'elenco pubblico contro
il codice**, ed è ciò che ha prodotto la sezione «Riconciliazione del 17/08/2026» in `roadmap.md`.

**6. Un'affermazione di un agente su *cosa fa un controllo* va risalita fino alla sua configurazione
prima di ripeterla.** Una verifica ha riportato che il wizard di installazione «mostra quattro lingue
e ne traduce due, quindi chi sceglie spagnolo installa in italiano». Sembrava solido e aveva le prove
giuste in mano — `lang/es/installer.php` davvero non esiste — ma la conclusione era sbagliata: quel
menu non è la lingua del wizard, è `APP_LOCALE`, cioè la lingua dell'**applicazione installata**, e lì
spagnolo e portoghese sono completi. Il difetto vero è molto più stretto (il wizard parla due lingue
su quattro) ed è diventato la coda ㉚. **La prova che serviva era leggere `config/installer.php`**, non
fidarsi della descrizione. Regola: quando un rapporto dice «questo controllo fa X», si apre la
configurazione che quel controllo legge, prima di scriverlo a Vincenzo.

### Le lezioni della beta.57 e della beta.58 — **due giri arretrati**, scritti il 19/08/2026

⚠️ **Prima lezione, ed è su questo documento.** La Fase 0.3 dice «si rilegge e si corregge a **ogni**
beta», e non è stata eseguita né chiudendo la .57 né aprendo la .58: prima di oggi il documento non
nominava mai la beta.58 se non nella Fase 3, e l'ultima sezione di lezioni era quella della .56. Due
beta sono passate senza lasciare traccia qui, e nel frattempo **cinque cifre citate erano invecchiate**
— compresa una che si contraddiceva da sola nel giro di sei righe («due delle *quattordici* guide»
subito sotto la riga che ne dichiarava diciassette).

Il rimedio non è «ricordarsi»: la Fase 0.3 va eseguita **prima** di aprire la Fase 1, perché è l'unico
momento in cui non c'è ancora niente di più urgente da fare.

**1. Un accessore unico che risolve dieci punti li livella verso il basso, e il caso migliore è quello
che perde.** La beta.58 ha centralizzato l'etichetta dell'unità in `Immobile::etichetta` per chiudere
i punti dove l'interno era l'unico identificativo. Applicata ovunque, quella regola ha **tolto
informazione** dove ce n'era di più: lo scadenziario scriveva «Int. 5 (Posto auto 3)» e ha iniziato a
scrivere «Int. 5». È servito un secondo accessore, `etichettaEstesa`.
*Regola:* quando si sostituisce con un helper unico N punti scritti a mano, si guarda **il più ricco**
dei N, non il più povero. Il povero è il difetto da chiudere; il ricco è quello che si rompe.

**2. Rendere facoltativo un campo è rendere *assente* un dato che il codice non ha mai visto assente.**
È la quarta forma della famiglia che questo documento raccoglie da tre beta — rendere qualcosa
mutabile (.42), rappresentabile (.43), raggiungibile (.46) — e ha il grado di sorpresa più alto,
perché non aggiunge uno stato nuovo: **toglie una garanzia tacita**. Il segnale è che il campo compare
in una stringa costruita per concatenazione: `'Int. '.$interno` diventa `'Int. '` e non fallisce mai.
*Regola:* prima di togliere un `required`, cercare le concatenazioni di quel campo, non le sue letture.

**3. Un numero nel changelog va accompagnato dall'elenco che lo prova, o non va scritto.** Il
changelog della .58 dice «in **dieci punti** l'interno era l'unico modo con cui l'unità veniva
nominata», e lo ripete in quattro punti della roadmap. In codice i punti enumerati sono **sei**
(`EtichettaImmobileTest` li elenca), e il docblock di `Immobile` cita un terzo numero,
«ventiquattro», per un'altra cosa. Tre numeri, un solo elenco verificabile.
*Regola:* se un numero entra nel racconto, l'elenco che lo sostiene sta in un test o in un docblock,
e i due numeri coincidono.

**4. La classe di difetto si conta prima di correggere, non alla chiusura.** La coda ㊺ — gli altri sei
caricamenti di documenti rimasti a numero fisso — è stata **misurata chiudendo la beta.58**, cioè
quando la revisione era finita e allargare avrebbe voluto dire rifarla. La regola contraria è già
scritta fra le lezioni della beta.54 ed è stata violata dalla beta successiva.
*Regola operativa che mancava:* la conta della classe si fa **in Fase 1, subito dopo il test che
fallisce**, e il suo risultato decide lo scope. Fatta dopo, produce una voce di roadmap invece di una
correzione.

**5. Un elenco di file costruito da `git status` non contiene i file gitignored, e `docs/` è per il
93% invisibile.** Al port della .58, `roadmap.md` non compariva in `git status` e sarebbe rimasto
indietro se l'elenco fosse stato usato così com'era. Non è l'eccezione di un file:
`git ls-files docs/ | wc -l` dà **10 tracciati su 55**. Quarantacinque documenti — **questo compreso** —
sono invisibili a `git status`, e la copia integrale di `docs/` non è una precauzione: è l'unico
meccanismo che li porta di là.

> ⚠️ **RETTIFICA DEL 21/08/2026 — la conclusione non vale più, la lezione sì.** `docs/` è da oggi un
> repository a sé e si allinea con `push`/`pull`, quindi la copia integrale non è più «l'unico
> meccanismo». **Resta vero il fatto che l'ha generata**: un elenco di file costruito da `git status`
> non contiene i gitignored, e su questo progetto sono ancora la stragrande maggioranza di `docs/`.
> Vale per ogni elenco automatico, non solo per i documenti.

**6. Un test il cui *nome* promette più del corpo passa la revisione, perché la revisione legge il
corpo.** `EtichettaImmobileTest` **aveva** un caso che si chiamava «senza interno e senza nome resta **il
codice**, che è l'ultima rete» e asseriva `'Unità #613'`, cioè l'**id**. Il docblock di
`Immobile::etichetta` dichiara la stessa cosa: «3. il codice, ultima rete». La colonna `codice_immobile`
esiste, è `NOT NULL`, univoca e popolata su tutte le righe (`C16-0002`): il ripiego non era impossibile,
era **mai stato scritto**. ✅ **Chiuso nella beta.59** (coda ㊼): `Immobile::etichetta` legge davvero
`codice_immobile` (`app/Models/Immobile.php:50-54`) e il test asserisce `'C16-0002'`. Il documento elenca già questo segnale d'allarme fra quelli della Fase 1-bis,
e la beta che lo cita lo ha prodotto.

**7. Dalla beta.57: una coda si ritira in Fase 0.2, non dopo aver scritto il codice.** La ㉛ («togliere
i tipi acqua e riscaldamento») è stata ritirata **prima di scrivere una riga**, perché la ricerca
preliminare ha trovato che la beta.50 aveva deciso il contrario e l'aveva annunciato nel changelog
pubblico. È il caso in cui la Fase 0.2 ha ripagato l'intero costo del processo in una sola beta —
e va detto, perché le altre volte quella ricerca sembra un rito.

**8. Dalla beta.57: un intervento visivo che corregge un componente dentro pagine sbagliate si vede
come una toppa.** La ㊳ sul tema scuro è stata scritta e ritirata in mezza giornata: nasceva da un
difetto misurato (contrasto 1,55) ma il campo sistemato risaltava chiaro dentro una pagina rimasta
scura. *Regola già scritta e qui confermata:* o si correggono superficie e testo insieme, sulla pagina
intera, o si misura e si lascia scritto.

### Le lezioni della beta.59 — scritte il 19/08/2026, aprendo la .60

*Beta di una funzione sola — l'aiuto sul Comune catastale — e la revisione ci ha trovato dentro un
difetto «alta» **con la suite verde**. Le prime due lezioni vengono da lì.*

**1. Un dato che vive nel codice e deve arrivare a database vuole un test sul *percorso*, non sul
comando che lo scrive.** L'elenco dei Comuni non sarebbe mai arrivato a chi **aggiorna**: il seeder
era agganciato al solo `DatabaseSeeder`, cioè alla prima installazione, mentre l'aggiornamento passa
da `SystemFinalizer::finalize()`, che per scelta dichiarata non esegue mai `db:seed` intero. Su tutto
il parco già installato la tabella sarebbe rimasta vuota, senza un errore e senza un log.

La suite non se ne accorgeva perché **tutti e tre i file di test nuovi chiamavano il comando a mano
nel `beforeEach`**: provavano il *caricamento* e saltavano la *consegna*.

- [ ] **Checklist, da qui in avanti.** Se una beta introduce un dato di riferimento — un elenco, una
      tabella master, una mappa di permessi, un seeder — allora: (a) c'è una riga in
      `SystemFinalizer::finalize()` che ce lo porta in **aggiornamento**, non solo in installazione;
      (b) c'è un test che parte da **tabella vuota** e chiama `finalize()`, non il comando.

⚠️ La lezione **era già stata pagata** con i permessi nella beta.55, ed è raccontata benissimo — ma
nel **docblock** di `SystemFinalizer`, non qui. Un docblock lo legge chi apre quel file; una
checklist di fase la legge chi apre una beta. Da oggi sta in tutte e due i posti.

**2. Correggere una ricerca «troppo stretta» la rende «troppo larga», e il caso facile è il primo a
cadere.** La ricerca dei Comuni trovava solo per prefisso e non dava «La Spezia» a chi scrive
«Spezia». Allargata alle parole contenute, ha cominciato a trovare quarantadue comuni per «Roma» — e
in ordine alfabetico **Roma non era fra i primi venti**, perché Barbarano Romano e Bassano Romano
vengono prima. *Regola:* quando si allarga un criterio di ricerca, il primo test da scrivere è che
**la corrispondenza esatta resti in cima**, non che i casi nuovi funzionino.

**3. `git status --porcelain` collassa una cartella nuova in una riga, e quella riga non è un file.**
Scritta per esteso in Fase 3, dove serve. Va nominata anche qui perché è la **stessa famiglia** della
lezione 5 della .58 (i file gitignored non compaiono) e della lezione sul `for` che non spezza: *un
elenco costruito da uno strumento risponde alla domanda che quello strumento si pone, non alla tua.*
Le tre stanno in punti diversi del documento e non si citano: **la radice è una sola.**

**4. Un numero misurato su un file di dati si può scrivere in prosa solo finché quel file è
immutabile.** La .59 ha sparso cinque numeri — 2.687 nomi multi-parola, 314 con apostrofo, 1.145
storpiati dal Title Case, 121 bilingui, 7.894 in tutto — fra changelog, docblock e roadmap, **senza
una sola asserzione che li presidi**. Oggi reggono perché il file viaggia col codice e non cambia
mai. La beta.60 gli toglie proprio quella proprietà: dal momento in cui l'elenco si può rigenerare,
quei numeri diventano falsi **in silenzio, con la suite verde**. *Regola:* o il numero si ricava dal
file al momento di scriverlo, o non si scrive.

**5. Una misura di prestazioni fatta una volta sola è il costo del primo accesso, non quello di
regime.** Il docblock di `Comune::scopeCerca` dichiara «4,2 ms contro 0,35»; rimisurato a caldo su
trecento iterazioni è **2,08 contro 0,16**. Il rapporto regge, i valori assoluti sono la metà. Vale
anche a rovescio: nella revisione della .59 due reperti sono caduti proprio perché il costo
dichiarato era una misura a freddo. *Regola:* nessun numero di millisecondi entra in un docblock o in
un reperto senza un riscaldamento e una media.


### Le lezioni della beta.60 — scritte il 19/08/2026, aprendo la .61

*Beta di tre fronti — il tetto ai caricamenti, la firma di stampa, la manutenzione della fonte ISTAT
— più la traduzione delle briciole ordinata a metà lavoro. Cinque delle sei lezioni non vengono dal
difetto ma da **come lo si è preso**, e la sesta l'ha trovata la ricognizione della .61 rileggendo le
guardie che la .60 aveva appena scritto.*

**1. Un `replacer` di Laravel *sostituisce* la sostituzione predefinita, non la affianca.** Il tetto
ai caricamenti aveva bisogno che `:max` in un messaggio di file diventasse «20 MB» invece di
«20480». Registrato il `replacer`, ha cominciato a valere per **tutti** i `max:`, e ogni regola su
stringhe e numeri ha smesso di sostituire il proprio: «il nome non può essere più lungo di :max
caratteri». Un difetto che nessun test vedeva, perché nessun test leggeva un messaggio di `max:` non
di file.

- [ ] Chi registra un `replacer` (o un `extend`, o una macro su una classe del framework) deve
      **rifare a mano ciò che stava sostituendo**, per tutti i rami che non gli interessano. E il
      test che lo presidia è quello sul ramo *innocente*, non su quello nuovo:
      `tests/Feature/Gestionale/LimiteCaricamentoTest.php:296`, «non tocca i `max:` che non
      riguardano file».

**2. Una guardia strutturale nasce cieca proprio sulla forma che la sua stessa beta ha introdotto.**
La prima stesura di `LimiteCaricamentoNonSiScriveAManoTest` riconosceva **zero su cinque** forme
evasive — compresa quella che la .60 aveva appena scritto in `StoreFatturaRequest`, dove `'file'`
sta a una riga e `max:` quattro righe più giù. La guardia era stata provata sulla forma che chi la
scriveva aveva in mente, non su quelle che il repository conteneva già.

- [ ] Una guardia strutturale non è finita finché non ha un **controllo negativo costruito dal
      codice vero della beta stessa**: si reintroduce il difetto nella forma in cui esiste nel
      repository e si verifica che il test diventi rosso. Se il difetto reintrodotto non la fa
      fallire, la guardia non guarda: sta misurando un'altra cosa.

**3. Un autocontrollo che riscrive l'espressione regolare della guardia prova la copia, non la
guardia.** È la lezione più cara della .60, e non l'ha trovata la .60: l'ha trovata la ricognizione
della .61, rileggendo i tre file. Sostituendo **solo** le espressioni di produzione con espressioni
che non possono mai combaciare — `LimiteCaricamentoNelleSchermateTest.php` righe 86 e 111,
`LimiteCaricamentoNonSiScriveAManoTest.php` righe 221-222, `TraduzioniNonSiCongelanoTest.php` riga
129 — **tutte e tre le guardie si svuotano senza che un solo test diventi rosso**, perché i loro
autocontrolli ricopiano l'espressione invece di chiamare la funzione che la usa. Le righe duplicate
sono `tests/Feature/System/LimiteCaricamentoNelleSchermateTest.php:122`, la 123 accanto,
`tests/Feature/System/LimiteCaricamentoNonSiScriveAManoTest.php:269` e
`TraduzioniNonSiCongelanoTest.php:175`.

⚠️ Il divieto era **già scritto**, nel commento della terza guardia (righe 171-172): «Usa la
funzione vera, non una copia della sua espressione regolare: una guardia provata su una copia prova
la copia». È stato scritto e violato nello stesso file, lo stesso giorno.

- [ ] L'autocontrollo di una guardia **chiama la funzione della guardia**. Se per provarla serve
      ricopiarne l'espressione, la funzione va estratta e resa richiamabile: è l'unico modo perché
      svuotare la guardia faccia diventare rosso qualcosa.

**4. Una tolleranza grande quanto lo scarto nasconde esattamente il difetto che dovrebbe prendere.**
`etichetta()` e `regolaMax()` dicevano due numeri diversi per lo stesso tetto — a video «1,8 MB»,
nella regola 1.899 KB, nel messaggio «1,9 MB» — su **209 valori su 299**. Il test che avrebbe dovuto
accorgersene esisteva già e confrontava i due con una tolleranza di **0,1 MB**, che è la misura
esatta dello scarto. Causa: un secondo arrotondamento su un numero già arrotondato.

- [ ] Quando due funzioni devono dire *lo stesso numero*, il test le confronta **su tutta la scala**
      e con una soglia che non copra il difetto che si teme. Una tolleranza scelta «perché così
      passa» è una dichiarazione di resa scritta in linguaggio di test.

**5. Una guardia che grida troppo si spegne, ed è peggio di una che non c'è.** La guardia sulle
traduzioni congelate ha sbagliato due volte prima di funzionare: la prima ritagliava il valore con
un'espressione regolare fino alla `const` successiva e segnalava **85** punti invece di 19; la
seconda non escludeva `computed`, cioè **segnalava il rimedio come difetto** — proponeva di togliere
l'unica forma corretta. Il valore si ritaglia bilanciando le parentesi, non con un'espressione
regolare.

- [ ] Prima di consegnare una guardia nuova: contare **quanti punti segnala oggi**. Se sono molti più
      di quelli che la beta ha corretto, non è una guardia severa — è una guardia sbagliata, e chi la
      erediterà imparerà a ignorarla.

**6. L'inquadratura di uno screenshot è scelta sul campo che si sta correggendo, e per questo non
prova ciò che le sta accanto.** Le briciole non tradotte erano a video da un giorno: la verifica
della .59 su `/condomini/create` aveva scorso fino ai campi catastali, e la briciola era **fuori
inquadratura**. Non è «era davanti agli occhi e non l'ho visto»: è che la vista era stata scelta per
un'altra domanda.

- [ ] Nella verifica a video, **una delle tre viste inquadra la pagina intera**, non l'elemento
      modificato. Serve a rispondere a una domanda che non ci si è ancora posti — l'unica che uno
      screenshot mirato non può prendere.

**7. L'alfabeto dei codici della coda è finito.** In `roadmap.md` ci sono **50 codici distinti** in
uso; `㊿` (U+32BF) è l'**ultimo carattere della serie dei cerchiati** — dopo comincia la serie dei
mesi — e **non ne resta libero nessuno**: `㉑`, l'ultimo, è stato speso dalla voce «Chi paga il
centesimo di resto» (`docs/roadmap.md:49`). Il comando anti-collisione scritto dopo lo scontro della .53
(qui sotto, Fase 0.2) leggeva un intervallo di caratteri che oggi ne vede 14 su 49, e risponderebbe
«il prossimo libero è ㊱» mentre ㊱–㊿ sono tutti occupati. È corretto in questo giro: si contano i
**caratteri effettivi**, non un intervallo.

- [ ] Con `㉑` speso — ed è successo, il 20/08/2026 —, il codice successivo **non esiste**: prima di aprire una voce nuova va deciso
      come si continua — codici a due cifre (`⑴⑴`), una serie diversa, o la numerazione semplice.
      Deciderlo quando serve il codice significa deciderlo di corsa.

### Le lezioni della beta.61 — scritte il 20/08/2026, aprendo la .62

*Beta grossa — la pagina dei millesimi rifatta, nove rotte morte, tre guardie sul condominio — e
la lezione più cara non viene da quello che ha corretto: viene da **dove** ha smesso di cercare.
La beta.62 si è aperta con quattro segnalazioni dal forum, e due erano lavori che la .61 e una
correzione di maggio avevano fatto **a metà**.*

**1. La classe si chiude nel file in cui la si è vista, e resta aperta in tutti gli altri.** La
.61 ha tolto nove rotte che puntavano a metodi inesistenti e rispondevano 500 — tutte in
`routes/gestionale.php`, con un commento che spiega benissimo il difetto e cita la beta.48, che
ne aveva chiuse quattro nello stesso file. Due giorni dopo il forum ne ha portata una **decima**,
in `routes/admin.php`, e la scansione di `Route::getRoutes()` ne ha trovate **diciassette** in
cinque file.

Nessuno dei due giri aveva guardato fuori dal file in lavorazione. Non per distrazione: perché
correggere una rotta *sembra* un intervento locale, mentre la domanda giusta — *quante altre
rotte puntano al vuoto?* — si risponde con un comando che nessuno aveva pensato di scrivere.

- [ ] Quando la correzione consiste nel **togliere** qualcosa che punta al vuoto, il perimetro
      non è il file: è l'elenco completo di ciò che può puntare al vuoto. Se quell'elenco si
      ottiene con un comando, la classe **si chiude con un test**, non con un altro giro di
      correzioni. Alla terza volta non è più una svista: è una forma che si ripete.
- [ ] `php artisan route:list` **non lo dice**: stampa il nome del metodo senza verificarne
      l'esistenza. Uno strumento che non pone la tua domanda risponde comunque, e la risposta
      sembra una conferma. È la stessa radice della lezione 3 della beta.59 (`git status` che
      collassa una cartella) e della lezione 5 della beta.58 (i file gitignored): *un elenco
      costruito da uno strumento risponde alla domanda che quello strumento si pone*.

**2. Una correzione suggerita da chi segnala arriva con il perimetro di chi segnala addosso.**
Nel maggio 2026 un amministratore ha segnalato che i documenti si scaricavano senza estensione e
ha allegato il codice della correzione. Il codice era giusto ed è stato applicato al controller
che lui poteva vedere. Ad agosto la stessa segnalazione è tornata **dal lato condòmino**: il
gemello `Documenti\Utenti\DocumentoController` — copia quasi riga per riga dell'altro — era
rimasto indietro di tre mesi e mezzo.

Chi segnala vede una porta sola, ed è naturale. Il difetto è aver ereditato quel perimetro invece
di rifarlo: la domanda *«questa funzione, in quante copie esiste?»* costava un grep.

- [ ] Una correzione che arriva **già scritta** va accettata volentieri e **ri-perimetrata sempre**:
      il primo passo non è applicarla, è contare i posti che fanno la stessa cosa.
- [ ] Quando i posti sono più di uno, la correzione non va copiata: va **spostata** dove la regola
      può vivere una volta sola. Qui è diventata `Documento::nomeDiScaricamento()`, e il test che
      la presidia è scritto **per rotta** — non per controller — perché è la simmetria fra le due
      porte a essere l'invariante.
- [ ] Il segnale che il perimetro è sbagliato: **chi ha corretto non riesce a riprodurre la
      seconda segnalazione**. Le due dashboard montano lo stesso componente Vue e lo instradano su
      due controller diversi a seconda del ruolo; l'amministratore che prova dalla propria utenza
      vede il file giusto e conclude che chi segnala si sbaglia.

**3. Un obbligo non è una difesa se l'unica via d'uscita è un valore che significa altro.** Il
millesimo era obbligatorio, quindi una casella vuota non si poteva salvare: sembrava una difesa e
convogliava l'errore in `0`, che per il motore significa «questa unità non partecipa» — cioè in
una forma **indistinguibile da una scelta voluta**.

- [ ] Davanti a un `required`, la domanda è *cosa scrive chi il valore non ce l'ha ancora*. Se la
      risposta è un valore che nel dominio significa qualcosa, l'obbligo non impedisce l'errore:
      lo rende irriconoscibile.

**4. Un vincolo che sta per cadere frena anche cose che non c'entrano.** Il cestino di riga era un
`<button>` senza `type`, cioè un «invia»: toglieva la riga *e* faceva partire il salvataggio
dell'intera tabella. Il difetto era più vecchio della beta e non si vedeva **perché il millesimo
obbligatorio bloccava il salvataggio**. Rendendo il valore facoltativo, quel freno accidentale
sarebbe saltato.

È la quinta forma della famiglia che questo documento raccoglie — mutabile (.42), rappresentabile
(.43), raggiungibile (.46), assente (.58) — e la più subdola, perché il difetto liberato **non è
nel codice che si sta toccando**.

- [ ] Prima di togliere un vincolo, chiedersi non solo cosa quel vincolo garantiva, ma **cosa
      stava frenando per caso**. Si cerca fra i comportamenti che oggi non si osservano mai e che
      nessuno ha mai spiegato perché non si osservano.

**5. Un rifiuto che il server pronuncia e la schermata non mostra è un difetto invisibile per
costruzione — e va contato, non stimato.** La segnalazione sui vani diceva *«non dà errori ma non
salva»*. L'errore c'era; non aveva **dove comparire**: la scheda dell'unità valida sedici campi e
ne rende quattro. Misurato su tutto il progetto: **210 chiavi validate su 675** (in 46 request di
modulo) non compaiono da nessuna parte come `form.errors.<chiave>`.

La lezione della beta.49 diceva già *«una correzione lato server non è finita finché non si è
verificato dove il messaggio compare a schermo — il posto può non esistere»*. Quello che mancava
era il **numero**: senza, sembra un caso; con, si vede che è una forma.

- [ ] La conta della classe si fa in Fase 1, subito dopo il test che fallisce, e il suo risultato
      **decide lo scope** (regola della .58, qui applicata: dodici campi corretti sulla schermata
      segnalata, il resto in roadmap con la misura allegata).
- [ ] E decide anche cosa **non** fare: una guardia che pretendesse un `InputError` per ogni campo
      validato ne segnalerebbe duecento, cioè si spegnerebbe al primo giro (lezione 5 della .60).

**6. Il difetto arriva sempre da chi non ha la vista che tu usi per cercarlo.** Tre segnalazioni su
quattro erano invisibili all'amministratore: il download senza estensione lo vede solo il
condòmino; le briciole non tradotte si vedono solo ricaricando la pagina a freddo, non arrivandoci
da un link; il collegamento delle notifiche è rotto per **un ruolo alla volta**, e chi riceve la
mail dell'altro ruolo non la riceve mai.

- [ ] Per ogni funzione che esiste in due aree — amministratore e condòmino — il test si scrive
      **due volte**, una per rotta. Non è ridondanza: è l'unico modo per cui la divergenza fra le
      due copie faccia diventare rosso qualcosa.

### Le lezioni della beta.62 — scritte il 21/08/2026, aprendo la .64 (**un giro arretrato**)

*Quattro segnalazioni dal forum, e tre erano lavori già fatti — ma soltanto dove chi aveva
segnalato poteva vederli. La lezione più utile però non viene dalle segnalazioni: viene da due
difetti che nessuno aveva segnalato e che sono saltati fuori correggendo quelli.*

**1. Chi corregge non riesce a riprodurre, e conclude che chi segnala si sbaglia — anche quando la
differenza è il sistema operativo.** Il download senza estensione ha impiegato **tre mesi e mezzo**
ad arrivare una seconda volta, e non perché nessuno se ne accorgesse: perché **su macOS non si
vede**. macOS indovina il tipo dal contenuto e aggiunge l'estensione da sé; su Windows il file
arriva senza `.pdf` e non si apre.

Il bullet corrispondente della beta.61 diceva la stessa cosa per i **ruoli** (l'amministratore che
prova dalla propria utenza vede il file giusto). Qui la dimensione è un'altra e il documento non la
nominava: **l'ambiente**.

- [ ] «Non riesco a riprodurlo» non è mai una prova che il difetto non ci sia. Prima di chiudere,
      elencare le dimensioni su cui l'ambiente di chi segnala può differire da quello di chi
      corregge: **sistema operativo, browser, lingua, fuso, separatore decimale, ruolo**.
- [ ] Su questo progetto la coppia più pericolosa è **macOS in sviluppo / Windows in produzione**,
      perché è silenziosa in un verso solo — e il verso silenzioso è il nostro.

**2. Un modulo che convalida sedici campi e ne mostra l'errore su quattro non rifiuta un campo:
rifiuta tutto, in silenzio.** La scheda dell'unità controllava sedici campi e aveva il posto per
l'errore su quattro. Chi scriveva `6,5` nei vani non vedeva niente e **perdeva anche le altre
correzioni** fatte nello stesso salvataggio — il piano, la superficie, le note. La segnalazione
diceva *«non dà errori ma non salva il dato»*, ed era la descrizione esatta.

E ce n'era un secondo, mai segnalato da nessuno: la **superficie** scritta all'italiana — `90,5` —
veniva rifiutata nello stesso identico silenzio.

- [ ] Il controllo è un confronto fra due conteggi, e si fa con un comando: **quante regole di
      convalida** ha la Request, e **quanti campi hanno un posto dove mostrare l'errore** nel
      modulo. Se i due numeri non coincidono, il modulo può fallire in silenzio — e non serve
      sapere quale campo: serve sapere che il numero non torna.
- [ ] Un modulo che fallisce in silenzio non perde il campo sbagliato: perde **la sessione di
      lavoro**. È la ragione per cui questo vale più di un errore di convalida qualunque.

**3. Un allargamento di colonna può fallire sui dati dei clienti, che non puoi vedere.** Il numero
di vani è passato da intero a decimale — un allargamento, quindi apparentemente innocuo. Ma la
colonna vecchia **non aveva un massimo**, e `decimal(5,2)` sì: un `1200` scritto anni fa in un
database qualunque avrebbe fermato l'aggiornamento con un `MySQL 1264` a metà migrazione, su una
macchina che non è la nostra e con un messaggio che non dice niente a chi lo legge.

- [ ] Davanti a un cambio di tipo, la domanda non è *«il tipo nuovo regge i valori che intendo
      scriverci»*: è **«qual è il valore peggiore che può già essere nel database di qualcuno»**.
      Il campo vecchio senza vincolo è il caso da temere, non quello con un vincolo largo.
- [ ] Quando la risposta non è dimostrabile — e non lo è mai, perché quei database non li vediamo —
      la migrazione porta con sé un **controllo preventivo che nomina le righe fuori scala** e si
      ferma dicendo quali. Un aggiornamento che si ferma spiegando è recuperabile; uno che si ferma
      con un codice d'errore del database no.

**4. Un'etichetta che promette un ambito va confrontata con la query che la riempie.** I riquadri
sopra l'archivio dicevano «Documenti totali» e «Spazio totale utilizzato» e contavano **solo**
l'archivio. Misurato su un archivio di prova: **sei documenti sul disco e i riquadri ne contavano
due**; circa **11 MB** occupati e i riquadri dichiaravano **414 KB**. La sproporzione non era
casuale — planimetrie e visure, cioè i file pesanti, stanno sulle unità e non in archivio.

- [ ] La parola «totale» in un'etichetta è una **promessa di ambito**, e va verificata contro la
      query, non contro l'intenzione di chi l'ha scritta. Il controllo è un comando solo: contare
      sul disco e confrontare col numero a video.
- [ ] Quando i due numeri non coincidono le strade sono due, e vanno distinte: **allargare la
      query** o **restringere l'etichetta**. Qui l'esclusione dall'elenco aveva una ragione buona —
      l'allegato di una fattura vive sulla fattura — quindi si è ristretta l'etichetta. Cambiare i
      numeri sarebbe stato l'intervento più grande e quello sbagliato.

**5. Rendere reidratabile qualcosa che prima veniva solo scritto trasforma una divergenza latente in
un difetto.** I due filtri della stessa barra emettevano tipi opposti per la stessa cosa —
`categoria.id` come numero, `String(c.id)` come stringa — con un tipo dichiarato che diceva
`string` per entrambi, quindi uno dei due mentiva. Il confronto è un `Set.has()`, che non converte.

La divergenza è stata **invisibile finché i filtri venivano solo scritti dall'interfaccia**: ogni
valore combaciava con sé stesso. Si vede da quando li si è resi reidratabili dal server, che manda
un tipo solo.

- [ ] Quando si aggiunge un giro di andata e ritorno — stato che prima nasceva e moriva nel
      browser e ora viene riletto dal server — **i tipi delle due parti si riverificano**, anche se
      il tipo dichiarato dice che sono uguali. Un tipo dichiarato non è una misura.
- [ ] Il sintomo, se si sbaglia il verso, è **muto**: la pillola resta spenta e l'elenco è giusto.
      Non c'è errore, non c'è log. Va cercato, non aspettato.

### Le lezioni della beta.63 — scritte il 21/08/2026, aprendo la .64

*Beta nata da una segnalazione sulle tabelle millesimali, e ha fatto emergere un difetto sul denaro
che nessun controllo interno poteva vedere. Ma tre dei sei insegnamenti non vengono dal prodotto:
vengono da **difetti introdotti mentre lo si correggeva**, presi tutti dalla revisione avversariale
prima di uscire. È il giro in cui la revisione ha guadagnato il suo costo.*

**1. Un motore che rinormalizza garantisce il totale, non la base — e nessun controllo a valle può
vederlo.** Il riparto trasforma una spesa in quote passando per quattro proporzioni in fila
(conto→tabella, tabella→immobile, ripartizione→ruolo, ruolo→persona) e **rinormalizza i pesi a 1**
alla fine. Qualunque cosa manchi lungo la strada, il totale distribuito torna uguale al preventivo.

Vuol dire che questa famiglia di difetti **non si presenta come «i conti non tornano»**, ma come
denaro addebitato alla persona sbagliata con i conti che tornano perfettamente. È il motivo
strutturale per cui arrivano dal forum uno alla volta invece che dai nostri controlli: solo chi
amministra davvero può accorgersene.

- [ ] In un sottosistema che normalizza a un totale, **nessun controllo sul totale è un controllo**.
      Le prove vanno scritte sugli **ingressi**: la somma dei coefficienti, la presenza dei
      millesimi, l'esistenza di un soggetto. Un test che asserisce «il piano quadra col preventivo»
      è verde per costruzione.
- [ ] Quando si trova un anello rotto di una catena, **si misurano anche gli altri anelli nello
      stesso passaggio**, e se non si chiudono si scrivono in roadmap con la misura. La catena è in
      `docs/roadmap.md` sotto Coda 58: due anelli chiusi nella .63, due misurati e non chiusi.

**2. Un modello di stampa non è esercitato da niente di ciò che si lancia abitualmente.** Un
commento Blade scritto dentro un blocco `@php` ha reso il template del riparto **non compilabile**:
500 su ogni stampa di riparto di ogni condominio — il documento che va in assemblea. È passato
indenne da `npx vite build`, dalla suite intera a **1595 test verdi** e dalla verifica a video,
perché **nessuna delle tre apre un PDF**.

La beta.44 aveva già scritto la gemella (*«`npx vite build` non è un controllo di correttezza del
template»*) per un `<Transition>` malformato. Qui vale per Blade, e con conseguenze peggiori: là il
difetto stava in una schermata, qui in un documento contabile.

- [ ] Fare l'inventario di **cosa nessun comando abituale tocca**. Su questo progetto sono i tredici
      modelli in `resources/views/pdf/`: non hanno una rotta esercitata dai test, non passano da
      Vite, e la verifica a video guarda le schermate. Ora li compila
      `tests/Feature/System/StampeCheSiCompilanoTest.php`.
- [ ] Dentro un blocco `@php` i commenti si scrivono **in PHP** (`/* … */`). Il compilatore estrae
      il blocco prima di compilare i commenti, quindi un `{{-- … --}}` finisce verbatim nel PHP
      generato. Non è una preferenza di stile: è la differenza fra un template che si apre e uno che
      risponde 500.

**3. La verifica a video ha una vista in più, ed è il log.** Verificando le stampe del riparto —
per tutt'altro motivo, cioè accertarsi che si aprissero — è saltato fuori che **sette stampe su
nove** riversano il PDF nell'output invece di restituirlo, e ognuna lascia un'eccezione non
catturata in `storage/logs/laravel.log`. Il documento arriva lo stesso, quindi a video non si vede
niente. È diventata la Coda 63.

- [ ] Alle tre viste della verifica a video se ne aggiunge una quarta: **il log**. Si azzera la
      lunghezza del file prima dell'azione e si legge la coda dopo. Costa un comando e trova cose
      che nessuna schermata mostra, per definizione.
- [ ] Vale soprattutto per ciò che **esce dall'applicazione** — PDF, mail, esportazioni, code — dove
      il successo apparente è la consegna e il difetto sta in quello che è successo intorno.

**4. Un cast collassa una distinzione di stato, e il test che dovrebbe accorgersene usa lo stesso
cast.** La beta.61 aveva appena costruito il terzo stato del millesimo: `null` = «non ancora
compilato», `0` = «non partecipa». Nella .63 la registrazione per la stampa è stata spostata prima
della guardia sul peso e ha cominciato a registrare **anche il `null`** — perché `(float) null` vale
`0.0`.

E il test scritto per presidiare la correzione asseriva `(float) $cella['quota'] === 0.0`: **passava
anche senza la correzione**, per lo stesso identico motivo.

- [ ] Quando un progetto introduce un terzo stato, **elencare i cast su quel valore**: ognuno è un
      punto in cui i tre stati tornano due. `(float) null`, `(int) null`, `?? 0`, `intval()`.
- [ ] Il test su un valore a tre stati asserisce sul valore **grezzo** — `->not->toBeNull()` prima
      di tutto — non sul valore convertito. Un'asserzione dopo il cast prova il cast, non lo stato.

**5. Una soglia si confronta nell'unità in cui il dato è scritto.** La tolleranza sui coefficienti
era espressa in frazione (`0.0005`) e confrontata contro una somma di divisioni per 100. A
**esattamente 99,95** dichiarati — cioè il limite che la costante dichiarava accettabile — l'esito
dipendeva dal rumore in virgola mobile invece che dalla regola.

- [ ] La colonna è `decimal(5,2)`: il confronto si fa in **punti percentuali arrotondati a due
      decimali**, che è l'unità in cui il dato esiste. Convertire prima di confrontare introduce
      cifre che nel dato non c'erano.
- [ ] Ogni soglia vuole **due prove al limite**: una sul valore che deve passare e una sul primo che
      non deve. Senza la coppia, un `>` diventato `>=` non fa diventare rosso niente.

**6. Rendere comune uno stato prima raro fa ereditare tutti i difetti latenti che quello stato
innesca.** La correzione sui coefficienti ha introdotto un caso nuovo: l'importo distribuito **più
piccolo** del preventivo del capitolo. Quel caso esisteva già — per le unità senza intestatario e
le tabelle senza millesimi — ma era raro. Reso comune, ha fatto affiorare un difetto preesistente
nel netting del già-versato: la copertura veniva scomputata solo in proporzione, e sullo scenario
misurato **€ 666,70 venivano richiesti a chi li aveva già versati**.

- [ ] Dopo una correzione che rende **comune** uno stato prima raro, il passo successivo non è il
      changelog: è **cercare chi assume lo stato vecchio**. Qui la domanda era *«chi confronta
      l'importo distribuito con `$conto->importo`?»*, e la risposta era una riga sola.
- [ ] Il difetto preesistente si corregge **nella beta che lo rende raggiungibile**, non in quella
      che lo ha scritto. Il dimagrimento vale per le funzioni nuove, non per ciò che una release
      rende facile da incontrare.

### Le lezioni della beta.64 — scritte il 22/08/2026, chiudendola

*Beta di tre segnalazioni dal forum. Ma cinque delle lezioni non vengono da quello che ha corretto:
vengono dalle **guardie che ho scritto e che non mordevano**. Tre volte in un solo giro, e tutte e
tre le ho prese solo perché ho provato a romperle di proposito.*

**1. Una guardia si prova rompendo la cosa che deve prendere, e la prova va fatta ogni volta.** La
regola *«perturbare la cosa giusta e vedere il rosso»* è scritta in questo documento dalla beta.46.
Nella .64 è servita **tre volte in una beta sola**, e ogni volta la guardia sembrava sana:

- la guardia sulle traduzioni usava `__()`, che **ripiega sulla lingua predefinita**: togliendo
  davvero l'etichetta spagnola restituiva l'inglese e il test restava verde;
- la guardia sui pulsanti «attiva tutte» falliva a leggere `route()` prima di arrivare
  all'asserzione, quindi passava senza provare niente;
- e la stessa guardia, al secondo tentativo, non è guarita mettendo `route` in `global.mocks`,
  perché quello vale per il **template** e la chiamata stava in `<script setup>`.

- [ ] Una guardia nuova non è finita quando diventa verde: è finita quando **l'hai vista rossa**
      rompendo esattamente ciò che dichiara di sorvegliare, e poi ripristinato.
- [ ] Se la sabotatura non la fa fallire, la spiegazione più probabile **non** è che il codice sia
      già a posto: è che la guardia non guardi lì. Cercare prima quella.

**2. Un test verde accanto a degli errori è un test che non ha provato niente.** Vitest ha scritto
**«7 passed» e «5 errors»** nella stessa schermata: un'eccezione non gestita dentro un gestore di
click **non fa fallire il test**, compare solo nel rumore sopra il riepilogo.

- [ ] Leggere la riga `Errors` insieme a `Tests`. Un `Errors` diverso da zero rende sospetto ogni
      verde di quel giro, anche quelli che sembrano non c'entrare.

**3. Le prove girano su SQLite, il prodotto gira su MySQL.** Una guardia sull'esistenza di una chiave
esterna interrogava `information_schema`, che su SQLite non esiste: ha fatto fallire **tutti e 27**
i casi di `UpgradeMigrationsRerunTest`, ventisei dei quali non c'entravano niente con la modifica.

- [ ] Ogni istruzione SQL specifica di un motore va protetta da `DB::getDriverName()`. Il precedente
      è `2026_08_15_100000_add_pertinenza_di_to_immobili`, che lo fa dalla beta.53.
- [ ] Il sintomo è riconoscibile: **falliscono anche i casi che non hai toccato**. Quando succede,
      la causa non è nella modifica ma nell'ambiente.

**4. Alla terza volta la guardia non impone la simmetria, impone la scelta.** «Collegato solo alla
creazione e non alla modifica» era la terza occorrenza (riparto manuale, controllo di capienza,
notifiche). Ma pretendere che ogni `update()` faccia quello che fa `store()` sarebbe stato sbagliato:
ci sono casi in cui non fare niente è giusto.

La guardia scritta pretende che ogni controller che avvisa alla creazione **compaia in una di due
liste** — chi avvisa anche in modifica, e chi di proposito no **con il motivo scritto**.

- [ ] Quando una classe di difetti ha eccezioni legittime, la guardia non deve imporre il
      comportamento: deve **impedire che la decisione non venga presa**. Una lista con dentro una
      voce senza motivo è una voce che nessuno ha deciso.

**5. Una preferenza nuova non si accende d'ufficio: eredita.** Le tre preferenze di notifica nuove
sarebbero nate spente per tutti — una riga che non c'è vale spento — e la funzione sarebbe sembrata
rotta il giorno stesso. Accenderle tutte avrebbe scritto mail nella casella di chi le aveva
esplicitamente rifiutate.

- [ ] Quando si aggiunge un'impostazione che ne affianca una esistente, la migrazione le dà **lo
      stato di quella**, non un valore deciso da noi. Una scelta dell'utente non si ribalta con una
      migrazione.

### Le lezioni della beta.65 — scritte il 22/08/2026, chiudendola

*Beta di due difetti opposti: uno teneva l'amministratore fuori, l'altro lasciava entrare. Ma le
lezioni vengono quasi tutte da come li si è provati — e da tre guardie che, di nuovo, non mordevano
al primo colpo.*

**1. Il difetto dell'aggiornamento l'ha trovato una prova a mano, non la suite.** L'aggiornamento
da una beta.50 rispondeva **500 al login**, e da lì non si andava avanti: la colonna
`last_login_at` non esiste finché non giri le migrazioni, e alla pagina che le lancia si arriva
**dopo** il login. Nessuna delle 1.677 prove automatiche lo vedeva, perché tutte partono da un
database **già migrato**.

- [ ] Prima di pubblicare un pacchetto, **provare l'aggiornamento su una copia** partendo
      dall'ultima versione **pubblicata**, non dalla precedente. Il salto vero è quello: dalla .50
      alla .65 ci sono quindici beta e una dozzina di migrazioni.
- [ ] Distinzione che ha ridotto la ricerca da tutto a una riga: **leggere** una colonna che non
      c'è restituisce `null` e non rompe niente; a rompere sono le **scritture**. Una *tabella* che
      non c'è invece rompe anche in lettura. Sono due famiglie con due perimetri diversi.

**2. «Rilevare meglio» è quasi sempre la correzione sbagliata.** Il `.env` si riscriveva in base a
`HTTP_HOST`, e la tentazione naturale era stringere il confronto — da sottostringa a dominio esatto.
Sarebbe rimasto un rilevamento basato su un dato che manda il client.

La correzione vera è stata trovare un **valore che va bene ovunque** (`PRIVATE_SUBNETS`): a quel
punto non c'è più niente da rilevare, e il ramo si toglie invece di stringerlo.

- [ ] Davanti a un `if` che decide una cosa importante guardando un dato di dubbia provenienza,
      chiedersi prima: *esiste un valore che rende la domanda inutile?* Se sì, quello chiude la
      classe; stringere la condizione chiude solo il caso.

**3. Una prova che fa danno quando il codice è sbagliato non è una prova.** La condizione che tiene
il provider lontano dalle richieste normali si poteva provare solo chiamando `boot()` — ma se la
condizione fosse rotta, `boot()` scriverebbe sul `.env` **vero del progetto**.

- [ ] Quando il codice da provare ha effetti su file o dati reali, il primo passo non è scrivere il
      test: è **estrarre il pezzo** in modo che il test possa dargli un bersaglio finto. Qui è
      bastato far prendere il percorso come argomento — ed è anche il motivo per cui quel difetto
      di sicurezza era vissuto due versioni senza che niente lo segnalasse: **non era provabile**.

**4. Un'asserzione sul testo di un file non è un'asserzione sul suo contenuto.** Tre volte di
seguito i test sono falliti su codice **giusto**, perché cercavano `TRUSTED_PROXIES=*` come stringa
mentre quella stringa compare legittimamente nella **prosa** — nel racconto del difetto e nella
frase che si scrive nel `.env` per spiegare all'amministratore cosa è cambiato.

- [ ] Quando si asserisce su un file di configurazione, ragionare sulle **righe di assegnazione**,
      non sul testo. E quando si asserisce su un sorgente, **togliere prima i commenti**: un test
      che vieta di *nominare* una cosa costringe a spiegarla peggio.

**5. Provare che una guardia morde va fatto sul tratto giusto.** La prova end-to-end sui proxy
usava `config()->set()`, che **scavalca il file di configurazione**: rompendo di proposito il
collegamento in `config/trustedproxy.php` il test restava verde. Copriva metà catena e sembrava
coprirla tutta.

- [ ] La sabotatura va fatta **nel punto che il test dichiara di sorvegliare**. Se rompendo quel
      punto il test resta verde, il test guarda altrove — e va detto nel file, non aggiustato in
      silenzio: qui le due metà sono provate in due modi diversi, e il commento lo dichiara.

**6. Un file rigenerato da uno script è una seconda copia, e diverge.** Lo script di build
riscriveva `.env.example` da zero. La copia generata prometteva *«l'installer lo attiverà se
necessario»* mentre l'installer non ha mai scritto quella variabile: **un'installazione fatta col
wizard non configurava mai i proxy fidati**, e nessuno poteva accorgersene leggendo il repository.

- [ ] Uno script di rilascio **non rigenera** un file versionato: ne attiva una riga. Il principio
      era già scritto nello script stesso per `config/installer.php` — *«non lo rigeneriamo, così la
      build non può divergere dal config reale»* — e non era stato applicato al file accanto.
- [ ] E ciò che lo script modifica va **rimesso a posto dopo**: quello vecchio lasciava quattro file
      modificati nel clone di lavoro, `composer.lock` compreso (479 righe riscritte).

## Fase 1-bis — La revisione avversariale, **prima** di raccontare

Nasce dalla beta.46, dove non esisteva: la revisione è stata lanciata a mano **dopo** la Fase 2 e ha trovato quattro difetti gravi. A quel punto changelog nelle tre lingue, guide in-app e due documenti interni erano già scritti, e descrivevano un comportamento corretto mezz'ora dopo. Documentare prima di rivedere costa il doppio: si scrive, poi si riscrive.

Vale la stessa logica per cui la Fase 2 sta prima del port. Si rivede quando il codice è verde e **prima** che una sola parola lo racconti.

### Le lezioni della beta.66 — scritte il 22/08/2026, chiudendola

*Beta di sicurezza strutturale: legare ogni rotta del gestionale al condominio scritto nel suo
indirizzo. Prevista in due passi e su 88 rotte su 112; uscita in un passo e su 159 su 160. Le
lezioni vengono quasi tutte da quella differenza.*

**1. «Non c'è la colonna» non vuol dire «non c'è la relazione».** L'analisi di apertura dichiarava
impossibili tre coppie — `esercizio > pianoConto`, `esercizio > pianoRate`, `condominio > conto` —
perché il figlio non ha la chiave del padre. Era vera la premessa e sbagliata la conclusione: due si
esprimono con un `belongsToMany` sul pivot in cui **la chiave del figlio non è `id`** ma
`gestione_id`, la terza con un `hasManyThrough`. Dieci minuti di verifica hanno spostato il
risultato da 88 rotte a 159.

- [ ] Prima di dichiarare una relazione inesprimibile, **provarla sui dati veri** e guardare il
      conteggio: una relazione giusta è selettiva (1 su 4, 3 su 8, 7 su 27). Se torna tutto o
      niente, è sbagliata.

**2. Escludere una rotta è più caro di quello che sembra, perché l'esclusione è per rotta e non per
coppia.** `withoutScopedBindings()` spegne il vincolo su **tutte** le coppie di quell'indirizzo. Le
tredici rotte che stavo per escludere per via di `esercizio > pianoConto` avrebbero perso anche
`condominio > esercizio`, che funzionava benissimo. Il costo di un'eccezione non si legge sulla
coppia che dà problemi.

**3. Il primo tentativo è stato indovinare l'elenco delle eccezioni, e ha prodotto 51 prove rosse.**
Avevo escluso «le rotte dei piani conti» leggendo il file; ce n'erano altre dodici, fra cui una
seconda `contributi/{conto}` che il file non mostrava vicino alla prima. La svolta è stata smettere
di leggere e **calcolare** l'elenco: enumerare le rotte, estrarre le coppie, sottrarre quelle
mappate.

- [ ] Quando la correzione è un elenco di eccezioni, **l'elenco si calcola dal programma, non si
      legge dal sorgente**. Un elenco letto è un elenco che dimentica il caso che sta due schermate
      più in basso.

**4. Cinquantuno prove rosse erano un difetto **nei fixture**, non nel prodotto — e vale la pena
distinguere subito.** Nove file costruivano un esercizio senza attaccargli la gestione: uno stato
che il prodotto non sa produrre (`GestioneController@store` e `CondominioService` attaccano sempre;
sui dati veri le gestioni orfane sono **0 su 8**). Finché nessuno leggeva il pivot, la finzione non
si vedeva.

- [ ] Davanti a molte prove rosse tutte insieme, **la prima domanda è se il prodotto può produrre lo
      stato che il test costruisce**. Se non può, il difetto è nel fixture — e correggerlo rende le
      prove più vere, non solo più verdi.

**5. Un test verde può nascondere un 500 che nessun utente ha ancora incontrato.** Scrivendo la
guardia sono saltate fuori cinque rotte `show` generate da `Route::resource` per metodi mai scritti:
su una rotta annidata la firma non accetta `{condominio}`, il dispatcher passa gli argomenti per
posizione e il controller riceve l'id del condominio al posto del figlio. **500 a chiunque digiti
l'indirizzo**, zero pagine che ci linkano. È la terza volta: quattro rimosse nella .48, due nella
.61, cinque qui.

- [ ] Aggiungere alla lista di controllo di fine beta: **`Route::resource` senza `only()` o
      `except()` genera rotte per metodi che non esistono**. Il difetto è silenzioso per definizione
      — nessuna pagina ci linka, quindi nessuno lo segnala.

**6. Una controprova può fallire per l'ambiente, e allora prova la cosa sbagliata.** La prova «il
proprio figlio si apre» chiamava la pagina del piano rate, che usa `JSON_UNQUOTE`: le prove girano
su SQLite e il prodotto su MySQL, quindi rosso per una ragione che non c'entrava. Riscritta sulla
**risoluzione** invece che sulla pagina, prova lo stesso meccanismo e non dipende dal motore.

- [ ] Quando una prova di sicurezza ha bisogno di una controprova positiva, **puntarla al meccanismo
      e non alla schermata**. Una prova che diventa rossa per l'ambiente insegna a ignorarla, ed è
      il modo più veloce per perdere una guardia.

**7. La guardia nuova è stata provata rompendo il codice, e la prima sonda era invalida.** Ho
rinominato una relazione nella mappa (3 rosse, giusto) e provato a spegnere il vincolo su una rotta
— ma `PendingResourceRegistration` non ha `withoutScopedBindings()`, quindi la sonda ha prodotto un
fatale al boot e **12 rosse con zero asserzioni**. Sembrava che la guardia mordesse; stava solo
esplodendo l'applicazione.

- [ ] Una sonda che rompe la guardia deve tornare **rosso con asserzioni**. Zero asserzioni non è
      una guardia che morde: è un'applicazione che non parte, e non dimostra niente.


### Le lezioni della beta.67 — scritte il 22/08/2026, chiudendola

*Beta nata da una riga di un audit su un post del forum: «c'è un pulsante che promette di compensare
e finisce sempre in errore». Si è rivelata due difetti sovrapposti, e una schermata che parlava una
lingua che i suoi utenti non parlano.*

**1. Un difetto visibile può tenerne nascosto uno peggiore, e correggere solo il primo è la mossa
sbagliata.** Il pulsante «compensa automaticamente» falliva sempre, quindi nessuna nota di credito
aveva mai allocazioni — e quindi il fatto che il residuo di una nota fosse calcolato al contrario
non si vedeva. Sistemare la schermata da sola avrebbe trasformato un errore a schermo in tre fatture
chiuse a «pagata» con € 1.220,00 di credito inventato.

- [ ] Quando si sblocca una strada che era chiusa, **cercare cosa c'era in fondo a quella strada e
      non è mai stato percorso**. La roadmap lo diceva già («le due cose vanno fatte insieme, o la
      funzione nuova nasce con il buco dentro»): la Fase 0.2 esiste per trovare frasi come quella.

**2. Due lati verdi non fanno un percorso verde.** C'era un test che provava la compensazione, e
passava: costruiva a mano i tre record che il motore pretende. Il motore era provato, la schermata
era provata, e nel mezzo non c'era niente che confrontasse **cosa spedisce l'una** con **cosa accetta
l'altro**. È la forma di buco più difficile da vedere, perché nessun test è rosso.

- [ ] Per un calcolo che vive di qua e viene consumato di là, servono **gli stessi casi scritti due
      volte**: nel test del modulo che li produce e in quello del motore che li accetta. Se uno dei
      due lati cambia da solo, uno dei due file diventa rosso.

**3. La correzione va nell'accessor, non nei chiamanti — e uno dei chiamanti era la guardia.** Il
residuo sbagliato aveva tre lettori, e in due di questi qualcuno aveva già messo un `abs()` «per
gestire le note di credito». Quel valore assoluto non stava aggirando il problema: **lo faceva
passare**, perché rendeva positiva una cifra gonfiata. Un numero che va letto al contrario a seconda
del tipo di documento è un numero che il prossimo chiamante sbaglia in buona fede.

**4. Lo stesso errore era copiato in un terzo posto, e l'ho trovato solo guardando a video.** Il
pannello «dettaglio allocazioni» — l'ultima cosa che si legge prima di confermare — tipizzava per
documento come faceva il codice rotto: scriveva «Pagamento € 177,00» su una riga che era una
compensazione da cui non usciva un euro. La suite era verde: quel pannello non lo prova nessun test,
e nessuno l'avrebbe scoperto senza premere il pulsante.

- [ ] La verifica a video **non è la conferma di quello che i test dicono già**: è l'unico posto in
      cui si vedono le cose che i test non guardano. Su una beta che corregge un'interfaccia,
      saltarla vuol dire consegnare metà del lavoro.

**5. Le domande di Vincenzo a video hanno prodotto metà del valore di questa beta.** «Smart Router —
Netting 1-Click non significa niente», «cosa succede cliccando?», «cosa vuol dire compensato con
NC?», «serve una guida». Tutte e quattro giuste, e nessuna sarebbe uscita da una suite verde: erano
difetti del **prodotto**, non del codice — la stessa famiglia che la beta.50 aveva già trovato.

- [ ] Quando una beta rimette in funzione una schermata che non funzionava, **guardarla come se
      fosse nuova**: nessuno l'ha mai usata davvero, quindi nessuno ha mai segnalato che i suoi nomi
      non si capiscono.

**6. Una sigla la capisce chi la conosce già.** «FT» e «NC» sono standard nella contabilità
italiana, e questa pagina la usa anche chi amministra due condomìni e non fa il commercialista. La
scelta fra legenda e tooltip si decide sul costo: una legenda occupa spazio **per sempre** per una
cosa che si impara una volta.

**7. Un intervento grafico su una pagina resta su quella pagina — e il confine si misura.** Il
componente `Badge` è `rounded-full` per tutto il gestionale: cambiarlo lì avrebbe cambiato ogni
schermata. Si è sovrascritto **solo qui**. Lo stesso criterio ha lasciato fuori lo sforo orizzontale
dei pulsanti «Approva sforo» su schermo stretto, che è di un'altra funzione: annotato, non corretto.


### Le lezioni della beta.68 — scritte il 22/08/2026, chiudendola

*Beta di una riga sola di codice, nata da un rilievo di un audit su un post pubblico. Le lezioni non
vengono dalla correzione: vengono da come si è deciso **quanto** correggere.*

**1. Un rilievo di un agente si verifica, e la verifica cambia la decisione.** L'audit diceva «la
scheda scollega la seconda tabella». Vero, e riprodotto. Ma la mia prima reazione — «è la famiglia
della .61 e della .63, denaro sulla persona sbagliata con i totali che tornano» — era **sbagliata**:
la guardia della beta.63 registra il pezzo mancante come scoperto, cioè una riga visibile. Il difetto
è perdita di dati, non addebito silenzioso.

- [ ] Prima di classificare un difetto per famiglia, **cercare la rete che una beta precedente
      potrebbe già aver costruito**. Cambia la gravità, e con la gravità la beta di destinazione.

**2. La domanda «1.10 o 1.11?» si scioglie dividendo la voce in due.** Non era una voce sola: la
*distruzione* è un difetto (1.10), la *scheda che sa gestire N tabelle* è una funzione (1.11).
Rispondere «tutto o niente» avrebbe portato o a rimandare un difetto o a infilare una schermata
nuova in una release che si sta chiudendo.

- [ ] Davanti a una voce che sembra costare troppo per la release in corso, **provare a spezzarla in
      «smetti di fare danno» e «fallo bene»**. La prima metà è quasi sempre una rimozione.

**3. Il principio giusto era già scritto nello stesso file, sul ramo accanto.** La conversione di una
voce in capitolo chiede una conferma esplicita prima di cancellare le tabelle, con la motivazione
scritta lì: *«mai un'eliminazione silenziosa dedotta da una transizione»*. Il ramo gemello, dieci
righe sotto, non l'aveva.

- [ ] Quando si trova un difetto in un `if`, **leggere anche gli altri rami dello stesso `if`**: chi
      ha scritto la guardia su uno l'ha pensata per tutti, e si è fermato al primo.

**4. Tacere non basta: se una schermata non sa mostrare una cosa, deve dirlo.** Smettere di
cancellare la seconda tabella lasciava una scheda che mostra **una** tabella su due come se fossero
tutte. La correzione completa è togliere la tendina e scrivere cosa c'è davvero.

**5. Una guardia si può soddisfare rompendo tutto, e serve la controprova che lo escluda.** «La
seconda tabella non sparisce» è verde anche rifiutando ogni salvataggio. Tre delle cinque prove di
questa beta sono controprove: l'importo si salva, il nome si salva, e la voce con una tabella sola si
comporta come prima.

**6. Pest carica tutti i file di test nello stesso spazio dei nomi.** `scenarioLastrico()` esisteva
già — in `CoefficientiSottoIlCentoTest`, la guardia della beta.63 **sullo stesso identico caso** — e
la collisione ha fatto fallire l'intera suite con un errore fatale, non con un test rosso.

- [ ] Prima di scrivere una funzione di supporto in un file di test, `grep -rn "function nome("
      tests/`. E se il nome è già preso **da un test dello stesso dominio**, è un segnale: leggerlo,
      perché probabilmente riguarda il difetto che si sta correggendo.

**7. Verificare a video ha richiesto di scrivere nel database vero, e va fatto in modo reversibile.**
Su nessun condominio esisteva una voce a due tabelle. Ne ho creata una sul condominio demo,
annotando gli id, e l'ho rimossa subito dopo verificando che il conteggio tornasse a zero.

- [ ] Se la verifica a video richiede uno stato che non c'è, **crearlo con un identificativo
      riconoscibile** (`ZZ TEMP …`), annotare gli id, e verificare il ripristino con una conta, non
      a memoria.


### Le lezioni della beta.69 — scritte il 22/08/2026, chiudendola

*Beta sulla catena delle quattro proporzioni. Su richiesta di Vincenzo — «fai prima le analisi con
le sonde e così non facciamo errori» — non è stata scritta **una riga** di correzione prima di aver
misurato. Le sonde hanno smentito due affermazioni mie, e una avrebbe mandato la correzione nel
posto sbagliato.*

**1. Un'analisi vecchia di un giorno può già mentire sul «dove».** La voce diceva: «due porte su tre
non hanno il controllo sulla somma — `AssociaTabellaController` e `AggiornaTabellaController`».
Tutte e tre ce l'hanno. Il buco era nella **quarta** porta, che l'analisi non aveva contato:
`ContoController::update()`.

- [ ] Un'analisi che elenca *quante* cose sono rotte va riverificata **contando di nuovo**, non
      rileggendo. Il numero è la parte che invecchia per prima.

**2. La sonda deve misurare euro, non stato interno — e servono due unità.** La prima versione della
sonda leggeva il totale addebitato. Il totale è **sempre** giusto: è precisamente il punto della
catena. Solo mettendo **due** unità si è visto che € 166,67 si spostavano dall'una all'altra.

- [ ] Per un difetto della famiglia «i conti tornano ma il denaro va alla persona sbagliata», lo
      scenario minimo ha **due soggetti**. Con uno solo il difetto è invisibile per costruzione.

**3. La misura sbagliata è pericolosa quanto l'assenza di misura.** Ho contato «12 unità su 42 con
somma quote ≠ 100» e stavo per riferirlo come difetto. Era priva di senso: la rinormalizzazione
avviene **dentro un ruolo**, e proprietario 100 + inquilino 100 fa 200 legittimamente. Rifatta per
coppia unità-ruolo: **4 su 47**.

- [ ] Prima di riferire una misura, **rileggere il codice che la consuma** e controllare che il
      raggruppamento sia lo stesso. Una somma fatta sul gruppo sbagliato produce un numero vero e
      una conclusione falsa.

**4. Il caso di controllo vale quanto il caso del difetto.** Sapere che «un ruolo a quote zero
sposta denaro» non diceva **cosa dovrebbe fare**. La risposta è arrivata da una sonda in più: lo
stesso caso con il ruolo **del tutto assente**, dove il programma già faceva la cosa giusta. Le due
situazioni sono la stessa, quindi devono dare lo stesso risultato — e questo ha deciso la forma
della correzione, non solo la sua necessità.

- [ ] Quando una sonda mostra un comportamento sbagliato, **cercarne una vicina che mostri quello
      giusto**. Spesso il programma sa già fare la cosa corretta in un caso adiacente, ed è la
      specifica migliore che si possa avere.

**5. La correzione va nel motore, non solo nella porta.** Chiudere `ContoController::update()`
avrebbe lasciato scoperti i dati già scritti e qualunque porta futura. Il motore ora registra la
parte non dichiarata come **scoperto**, che è la stessa forma con cui la beta.63 aveva chiuso
l'anello 1 — e la porta è stata chiusa lo stesso, per fermare l'errore dove lo si commette.

**6. Una guardia scritta su una forma sola vede una forma sola.** Nella beta.64 avevo costruito la
guardia sull'asimmetria `store`/`update`… ma sugli **eventi**. Questo caso era la stessa asimmetria
sulle **validazioni**, e la guardia non poteva vederlo. Ne serve una seconda.

- [ ] Quando si costruisce una guardia su una forma ricorrente, scrivere **cosa NON copre** della
      stessa forma. È la riga che, la volta dopo, evita di credere di essere protetti.

### Le lezioni della beta.70 — scritte il 22/08/2026, chiudendola

*Beta di sola documentazione, nata da una domanda di Vincenzo: «abbiamo spiegato nella guida a cosa
serve questa opzione?».*

**1. La domanda giusta era una sola riga, e la risposta era no.** L'interruttore «già versato» apre
una funzione intera e cambia il riparto; l'unica spiegazione era la descrizione sotto l'interruttore
stesso. Misurato su tutte e diciannove le guide: nessuna lo spiegava.

- [ ] Aggiungere alla lista di fine beta: **un interruttore che apre una funzione intera va cercato
      nelle guide**, non solo nel codice. Una casella con una riga di descrizione sembra spiegata, e
      non lo è.

**2. Scrivere una guida è il modo migliore per scoprire cosa il prodotto non dice.** Preparandola
sono emersi due comportamenti con conseguenze sui soldi che non erano scritti da nessuna parte
tranne che in un commento nel motore: la copertura è **per immobile e non per soggetto** (un
versamento del solo proprietario sconta anche l'inquilino), e la quota **non scende mai sotto zero**
(l'eccedenza non diventa credito, diventa un'attività in Inbox).

- [ ] Quando un comportamento è documentato **solo** in un commento del codice con scritto
      «deciso di segnalare, non correggere», quella è una riga che deve arrivare all'utente. La
      decisione di non correggere è legittima; tenerla nascosta no.

**3. Una guida sta dove serve, e può essere più di un posto.** L'interruttore si accende sulla
pagina del piano dei conti, gli importi si registrano sulla pagina «Già versato». Lo stesso
pannello è montato su tutte e due: chi arriva da una parte non ha visto l'altra.


### Le lezioni della beta.71 — scritte il 23/08/2026, chiudendola

*Beta di una funzione, non di un difetto: il condominio dimostrativo, chiesto da Vincenzo perché chi
installa KondoManager si trova davanti un programma vuoto e non capisce cosa sappia fare.*

**1. Un seeder che scrive a mano produce un prodotto che non esiste — e passare dai service lo
dimostra al primo giro.** La regola era stata scelta in partenza per principio; ha ripagato subito,
scoprendo **tre** stati impossibili che un `DB::table()->insert()` avrebbe prodotto senza un
lamento: una voce di spesa senza aggancio contabile non si può fatturare, il servizio delle fatture
pretende la modalità di pagamento, e un piano rate deve dichiarare quali voci copre.

- [ ] Un seeder di dimostrazione **passa dalle stesse porte dell'utente**. Non è pignoleria: è
      l'unico modo perché invecchi insieme al prodotto invece che contro.

**2. La verifica a video ha trovato ciò che nove prove verdi non vedevano.** Il piano rate non
dichiarava i capitoli coperti: tutti i dati presi uno per uno erano corretti, e il cruscotto mostrava
un allarme rosso «ricalcola» con le coperture allo 0%. **La demo si apriva con un errore proprio a
chi guardava il programma per la prima volta.** Nessun avviso del seeder, nessun test rosso.

- [ ] Per una funzione che serve a **fare impressione**, la prova non è che i dati siano giusti: è
      che la **prima schermata** sia quella giusta. Va guardata, e va guardata per prima.

**3. La metà difficile non era creare, era poter disfare.** I vincoli del database impediscono di
eliminare un condominio con movimenti contabili — giustamente. Una demo che non si toglie è una demo
che nessuno prova. E l'ordine di cancellazione non si deduce a mente: due tentativi sono falliti
prima di andarselo a leggere in `information_schema`.

- [ ] Quando una cancellazione attraversa più tabelle, **l'ordine si chiede allo schema**, non alla
      memoria. Sei vincoli non a cascata, e ognuno ferma tutto.

**4. Una domanda dell'utente vale più di un'ipotesi mia, due volte nello stesso giro.** «Non credo
sia necessario creare due demo» ha eliminato una classe intera di problemi — vincoli di unicità,
progressivi, collisioni. «Perché non un pulsante accanto a *crea condominio* con un modale?» ha
sostituito un pannello tratteggiato che occupava mezza schermata: **la mia versione era invadente, e
lui l'aveva previsto prima di vederla** («già immagino gli amministratori che mi diranno è troppo
invasivo»).

E una terza, la più utile delle tre: *«si può rimuovere anche dal pulsante elimina dentro le azioni?
funziona anche da lì?»*. **No**, e nel modo peggiore: la rotta normale sarebbe fallita sui vincoli e
avrebbe mostrato «ha movimenti contabili e non può essere eliminato» — una frase vera per i
condomini veri e **falsa proprio per quello che il programma dice di poter rimuovere**.

- [ ] Quando si aggiunge qualcosa a una pagina che l'utente usa tutti i giorni, chiedersi **quanto
      spazio si sta prendendo a chi non ne ha bisogno**. La risposta di solito è: troppo.
- [ ] E quando si costruisce una **seconda strada** per fare una cosa, provare la **prima**: quella
      che c'era già continua a esistere, e chi la usa non sa che ne è nata un'altra.

**4-bis. Una demo si giudica schermata per schermata, non a totali.** Vincenzo ha aperto la pagina
delle ritenute e ha chiesto *«come fa l'amministratore a vedere l'esempio dell'F24?»*: era vuota, con
un pulsante arancione «Aggiorna scadenze». **Coerente** — quel pulsante esiste apposta — ma
l'attività in Inbox diceva «versa la ritenuta», si premeva «Risolvi» e si arrivava su una schermata
che dichiarava di non avere niente da versare. Un giro a vuoto sulla prima cosa che uno prova.

- [ ] Per una funzione dimostrativa, **aprire ogni pagina in cui porta**, non solo quella d'arrivo.
      Un'attività che rimanda a una schermata vuota insegna che il programma non funziona.

**4-quater. ⚠️ Ho commesso io il peccato da cui questo seeder esiste per difendersi.** Per emettere
le rate avevo scritto `$piano->rate()->update(['stato' => 'emessa'])`. Le rate *risultavano* emesse,
gli incassi funzionavano, la suite era verde — e **la scrittura contabile non esisteva**: emettere
una rata registra in partita doppia il credito verso i condòmini contro il conto della gestione,
aggiorna gli eventi in agenda e valida la quadratura.

Cioè avevo prodotto **esattamente lo stato che il prodotto non sa produrre**, nel file la cui prima
riga di documentazione dice di non farlo. Se n'è accorto Vincenzo guardando la pagina del piano rate
— *«non mi sembra che le rate siano state emesse, sei sicuro?»* — dopo che io avevo risposto di sì
leggendo la colonna a database.

- [ ] **Un `update()` su una colonna di stato non è mai «fare quella cosa».** Se esiste un pulsante
      che porta a quello stato, quel pulsante fa altre cinque cose. La domanda da farsi è: *cosa
      succede premendolo?* — e la risposta si legge nel controller, non nella colonna.
- [ ] E quando qualcuno chiede «sei sicuro?» su una cosa che ho verificato **a database**, la
      verifica che manca è **a video**. Sono due domande diverse.

**4-quinquies. Una demo si costruisce guardandola, non elencandola.** Il perimetro scritto in testa
al seeder era ragionato e completo sulla carta. Ma è servito che Vincenzo aprisse le pagine una per
una perché venissero fuori i quattro pezzi che mancavano davvero: lo **scadenzario F24** non
calcolato, la **fattura in sforo** che nel piano dei conti si vede solo come un numero rosso, la
**sopravvenienza** che accende la sezione fuori preventivo, e lo **storno** — il principio su cui è
costruito tutto il gestionale, che nessuna schermata mostrava.

- [ ] Il perimetro di una demo va scritto **davanti alle schermate**, non prima. Ogni sezione vuota
      del cruscotto è una funzione che il visitatore non scoprirà mai che esiste.

**4-ter. Il seeder che passa dai service insegna il prodotto anche a chi lo scrive — due volte.**
Volevo stornare il pagamento della fattura in sforo: il seeder ha risposto *«le seguenti fatture non
sono ancora approvate»*. Non era un intoppo da aggirare — era il prodotto che diceva una cosa giusta: **una spesa
fuori budget non si paga finché non è ratificata**. Lo storno ha preso una fattura sua, e la fattura
in sforo è rimasta da ratificare, che è quello che deve mostrare.

La seconda volta è stata `conti.origine_decisionale`: un enum a **due** valori, e il mio terzo veniva
troncato in silenzio da MySQL. Scritto a mano non se ne sarebbe accorto nessuno — sarebbe finito nel
database un valore vuoto, e la voce della sopravvenienza avrebbe perso la sua origine.

**5. `usePage()` fuori da un `computed` è una fotografia.** Catturato una volta, l'oggetto delle
proprietà resta quello vecchio quando Inertia lo sostituisce: rimosso il condominio dimostrativo, il
pulsante continuava a dire «Condominio di esempio» e tornava giusto solo ricaricando a mano.

- [ ] In un componente Vue, `usePage()` va chiamato **dentro** il calcolo che lo usa. È la stessa
      forma della lezione della beta.67 sul pannello che leggeva `pendenze` invece del payload: un
      dato letto una volta e usato dopo.

**6. Una demo con l'Inbox vuota mostra un archivio, non un assistente.** L'ha detto Vincenzo —
*«quella è una chicca di KondoManager»* — e aveva ragione oltre l'estetica: l'Inbox è il posto in cui
il programma dice cosa fare oggi. Ma le attività devono **corrispondere a qualcosa che c'è davvero**:
una che non porta da nessuna parte è peggio di nessuna.

**7. I miei residui di prova finiscono nel database di Vincenzo.** Un condominio dimostrativo creato
prima che esistesse la colonna `is_demo` è rimasto in elenco, e l'ha visto lui prima di me. Le sonde
le ho sempre ripulite; questo non era una sonda, era un giro di sviluppo.

- [ ] Chiudendo una beta che ha scritto sul database vero, **contare cosa è rimasto** e non fidarsi
      del fatto che ogni prova finiva con una rimozione. Le prove fallite non arrivano alla riga
      che ripulisce.


### Le lezioni della beta.73 e della beta.74 — scritte il 23/08/2026, aprendo la beta.75

*Due beta sulla stessa area — il pregresso nelle stampe di riparto — lavorate da un'altra sessione.
Le lezioni sono ricavate dal changelog e **verificate contro il codice**, ed è verificandole che è
uscita la prima, che il changelog della .74 non poteva contenere.*

**1. «Una strada sola, non due» va misurato sui gemelli, non dichiarato nel titolo.** La .74 ha
spostato il residuo del riparto nella pseudo-colonna «addebito diretto» e ha intitolato la
correzione *«una strada sola, non due»*. La strada è rimasta doppia: la correzione è entrata in
`RipartoTabelleService` e **non** in `RipartoCapitoliService`, che non è nemmeno fra i file toccati
dalla beta. Peggio del difetto in sé è ciò che restava scritto nel codice: il commento a
`RipartoCapitoliService` giustificava il vecchio comportamento dicendo che era *«la stessa strategia
già in produzione in RipartoTabelleService»* — una frase vera fino alla .73, che dalla .74 fa
sembrare corretto proprio ciò che era stato appena dichiarato sbagliato.

È la **terza volta** che questa forma si presenta: la .61 l'aveva già annotata come «una correzione
di maggio applicata a uno dei due controller gemelli», e prima ancora c'erano le nove rotte morte
chiuse in un file solo mentre ce n'erano diciassette in cinque. Non serve un'altra regola scritta:
serve, quando si corregge un servizio che ne ha un gemello dichiarato, **eseguire il grep del
concetto** — non del nome del metodo — e chiudere i due insieme o dire nel changelog quale dei due
resta indietro e perché.

**2. Due difetti che si somigliano possono richiedere rimedi opposti, e il test lo dice prima del
ragionamento.** Applicando alla stampa per capitoli la stessa regola della gemella — *il residuo va
sempre fuori riparto* — due test della .52 sono diventati rossi. Il motivo non era un dettaglio:
in quel servizio i residui sono di **due specie**. Quello **positivo** è un addebito in più
(pregresso, conguaglio, titolare cessato) e non appartiene a nessun capitolo; quello **negativo** è
il *lordo fantasma* del subentro — pesi vivi sulla pivot e zero quote emesse — e va riassorbito
esattamente dove è stato gonfiato. Mandandoli entrambi nella pseudo-colonna si annullano fra loro:
misurato, il capitolo restava a € 1.000,00 invece di € 400,00 e «fuori riparto» finiva a zero, con
il gran totale **giusto** a coprire tutto.

La ragione strutturale merita di essere scritta una volta: **le due stampe non sono simmetriche.**
`RipartoTabelleService` costruisce le righe **da** `rate_quote` e un lordo fantasma non lo può
proprio produrre; `RipartoCapitoliService` ricalcola il lordo dai pesi e può. Il commento che
dichiarava la parentela descriveva una somiglianza che vale solo a metà — ed è la seconda volta in
due lezioni che un **commento** è la cosa più sbagliata del file.

**3. Un gran totale corretto non dice niente sulle colonne.** Nella prima stesura sbagliata il
`gran_totale` restava **100000**, esatto, mentre le due colonne erano entrambe sbagliate di 60000 in
direzioni opposte. È la stessa forma del difetto della .52, dove il lordo fantasma era mascherato
dall'assenza della riga dell'orfano e i due errori si annullavano. Il presidio che serve è quello
che la .49 chiama «IL PRESIDIO CHE MANCAVA» — la matrice quadra con `rate_quote`, non con la
gemella — e va guardato **per colonna**, non solo in fondo.

**4. Il `git status` di TEST non risponde alla domanda «cosa è mio», e nemmeno `git log`.** Aprendo
questa beta, `git log` in TEST dava come ultimo commit la **.72** e mostrava venti file modificati:
sembrava che .73 e .74 fossero lavoro non committato da salvare. Erano invece già committate e
**pushate** — TEST era indietro di tre commit e non aveva mai fatto `fetch`, quindi anche
`origin/v1.10.0-beta` era una referenza vecchia. La domanda si risponde in due mosse, e stanno già
in Fase 0.1: il `diff` fra le due **cartelle**, e `git fetch` prima di guardare `origin`. Fatto
questo, l'unico file davvero unico in TEST era quello di questa beta.

### Le lezioni della beta.72 — scritte il 23/08/2026, aprendo la beta.73

*Beta lavorata da un'altra sessione: un dato salvato corrotto da un campo di modifica pre-popolato
con la grandezza sbagliata, trovato inseguendo una segnalazione del forum su un simulatore diverso.*

**1. Un campo di modifica pre-popolato con una grandezza derivata riscrive la grandezza sorgente.**
`PianoContiController` porta in memoria il **fabbisogno** (`max(preventivo, speso)`) per la barra di
copertura — è il numero giusto lì. Ma lo stesso numero finiva anche nel campo «Importo» del modale
di modifica, che al salvataggio scrive il preventivo deliberato. Bastava aprire «Modifica voce» su
una voce in sforo, cambiare una nota qualunque e salvare: il preventivo saliva silenziosamente fino
allo speso. Nessuna delle due guardie esistenti poteva accorgersene — quella elastica scatta quando
l'importo **scende**, quella rigida solo con rate approvate — perché il numero **saliva**, ed è
esattamente il verso che nessuna delle due sorvegliava. Non c'è riparazione automatica: un
preventivo alzato a mano e uno alzato dal difetto sono indistinguibili a posteriori.

- [ ] Quando un pannello di **dettaglio** e un modale di **modifica** condividono la stessa
      variabile per due grandezze diverse (qui: fabbisogno e preventivo), il modale eredita il
      valore sbagliato senza che nessun test sull'update se ne accorga — il controller persiste
      correttamente ciò che riceve, il difetto vive tutto nell'inizializzazione del campo.
- [ ] Le guardie a soglia hanno un **verso**. Prima di fidarsi che una protezione copra un caso,
      chiedersi non solo *quando* scatta ma *in quale direzione* — qui il guaio saliva, e le due
      guardie esistenti guardavano solo chi scende.

**2. Le tre viste hanno pagato ancora, su una pagina che non c'entrava con la segnalazione
originale.** Cercando il buco del consuntivo mancante ne è emerso uno più grave (la lezione 1), e
verificando quella correzione sulle tre viste sono emersi altri due difetti che nessuno aveva
segnalato: su mobile un importo si troncava a «€ 1.537,0» (tre colonne su 375px lasciano 81px a
testa), e in tema scuro il nome di ogni voce era illeggibile — grigio ardesia su fondo quasi nero,
le varianti `dark:` mancanti da sempre su quella pagina.

- [ ] Un difetto **preesistente**, trovato per caso mentre si verifica un'altra correzione sulle tre
      viste, si corregge **nella stessa beta**, non si rimanda: vedi la lezione successiva sul
      perché.

**3. Una correzione fuori dal perimetro della beta, lasciata in TEST, il prossimo reset la
cancella.** Il difetto del tema scuro non era nel perimetro dichiarato di questa beta — era un
effetto collaterale trovato verificando altro. È stato corretto comunque, nello stesso commit,
perché TEST non si committa mai: una correzione rimasta fuori da un commit non sopravvive alla
risincronizzazione di Fase 0.1 della beta successiva, che scarta tutto ciò che non è stato portato.
Rimandarla a «dopo» significa perderla senza che nessuno se ne accorga.

- [ ] Un difetto preesistente scoperto per caso durante la verifica di un'altra correzione: o entra
      nel commit di questa beta, o si scrive **subito** in roadmap con la sua posizione precisa.
      «Lo sistemo dopo» in TEST non è un rinvio, è una cancellazione silenziosa al prossimo reset.

**4. Un'etichetta ambigua vale una segnalazione vera, anche quando il numero sotto era giusto.** La
metà della segnalazione del forum era un equivoco indotto da questo stesso progetto: il campo si
chiamava «Importo» e mostrava il fabbisogno, non il preventivo — un nome che era quello di
un'altra grandezza. Rinominare i due campi («Preventivo» e «Fabbisogno», ciascuno al posto giusto)
non cambia nessun numero, ma è la correzione che chiude la segnalazione tanto quanto il difetto di
corruzione trovato cercandola.

- [ ] Quando una segnalazione sembra un equivoco dell'utente, verificare comunque **cosa vede
      davvero** prima di archiviarla come tale: l'etichetta sbagliata è un difetto reale, anche se
      il dato sottostante non lo è.

### Le sei lezioni della beta.75 — scritte il 24/08/2026, aprendo la beta.76

**1. Un documento scritto la mattina può essere falso il pomeriggio, e nessuna regola lo copriva.**
`ricostruzione_contabilita_senza_consegne.md` è stato scritto il 23/08 leggendo il codice, e
descriveva il pregresso che chiude in AVERE sulla `gestione_rate` dell'esercizio corrente. Era
esatto: era il **difetto**, e la .75 l'ha corretto nel pomeriggio dello stesso giorno. Il documento
è rimasto vero mezza giornata, con l'intestazione di stato perfetta.

Il verificatore non poteva vederlo: ordina per età e quel documento stava a «0 beta». Le tre regole
sulla documentazione del 15/08 non lo coprono nemmeno — parlano di documenti che invecchiano, non di
un documento che la beta stessa supera mentre lo si scrive.

- [ ] Quando una beta **corregge** ciò che un documento appena scritto **descrive**, quel documento
      si rilegge nello stesso passaggio in cui si scrive il changelog. Non alla beta successiva:
      la finestra fra «ho descritto il difetto» e «ho corretto il difetto» è di ore, e in quelle ore
      il documento è la cosa più autorevole e più sbagliata che c'è in `docs/`.

**2. Un'affermazione dedotta dalla lettura non chiude una domanda contabile: la chiude un test.**
Cinque agenti con verifica avversariale avevano concluso, leggendo, che l'emissione della Rata 0 non
produce scrittura contabile. Il test scritto per confermarlo ha provato **l'opposto**: il ramo
`quota_pura_gestione` era codice morto e l'importo **intero** veniva registrato sulla gestione
corrente. La lettura non era superficiale — era la lettura di cinque agenti — e ha sbagliato comunque.

- [ ] Su una questione che sposta denaro fra due conti, la sequenza è: si legge, si formula, **si
      scrive il test**, e solo allora si scrive la conclusione. La lettura serve a sapere *dove*
      guardare, non a decidere.

**3. Una correzione ritirata va ritirata con i suoi test, o il presidio resta a guardia del nulla.**
La correzione al residuo per segno — positivo in «fuori riparto», negativo riassorbito sul capitolo
— migliorava il caso col solo pregresso e **peggiorava** quello con pregresso e già versato insieme.
È stata ritirata prima del rilascio, e con lei i suoi due test.

Il motivo per cui i test non potevano restare: presidiavano un comportamento che dopo il ritiro non
esisteva più. Un test verde su codice tornato indietro non è un presidio, è una trappola per chi
leggerà quel file fra tre mesi.

- [ ] Ritirare una correzione è un risultato, non un fallimento — ma va scritto in roadmap **perché**
      non funzionava, o la si ritenta identica al giro dopo. La voce della Coda 76 lo dice per esteso,
      e l'analisi del 24/08 ha poi mostrato che il motivo era più profondo del previsto: il residuo è
      un intero unico in cui pregresso e già versato sono **già sommati**, quindi il segno classifica
      la somma e non gli addendi.

**4. Una guardia che rimanda alla verifica a video non è una guardia.** Durante la beta era stato
scritto un test `skip`pato con la motivazione «si verifica a video». È la forma esatta della guardia
che si svuota da sé che questo documento denuncia altrove: un test che non gira è un commento con
una sintassi più costosa. È stato riscritto come test vero.

- [ ] Nessun `skip` con motivazione «da verificare a mano». O il test si scrive, o il caso va nella
      lista di ciò che resta da provare — che è un posto onesto, mentre uno `skip` verde no.

**5. `docs/changelog.md` è un file di prodotto travestito da documento.** Vedi Fase 0.1: è
gitignorato nel repository dei documenti, quindi `push`/`pull` non lo portano e `git status` in
`docs/` non lo elenca. Se ne è accorto Vincenzo, non il processo.

**6. Una voce di roadmap può essere chiusa da una beta che non la nominava — e la Fase 0.2 è il solo
posto dove ci si accorge.** *(Imparata aprendo la .76, sul lavoro della .75.)* La **Coda 70** («il
cruscotto non conosce il già versato») era data per aperta in due punti della roadmap. È chiusa dalla
.75: `PianoRateQuoteService::totaleAttesoCents()` ora sottrae il già versato registrato, ed è
l'**unica** fonte del verdetto «Disallineato» — i suoi due consumatori
(`PianoRateController:641`, `DashboardController:277`) chiamano entrambi `eDisallineato()`, e nel
frontend non c'è nessun ricalcolo. La .75 l'ha corretta come effetto collaterale di un'altra
diagnosi, senza nominare la coda né nel changelog né in roadmap.

Il costo evitato è concreto: la .76 nasceva per correggere «lo stesso difetto» in
`BudgetCoverageService`, che invece **calcola giusto**. Là `$pianificato` viene dal pivot al lordo e
`$fabbisognoReale` è `max(conto->importo, spesoReale)` anch'esso al lordo: il delta è 0 e la voce
risulta coperta, che è la risposta corretta alla domanda che quel servizio si fa — *«questa voce è
finanziata?»*. Con € 5.000,00 di preventivo, € 2.000,00 già in cassa e € 3.000,00 da chiedere, la
voce **è** coperta. Correggerlo avrebbe rotto la barra di copertura e il principio che `conti.importo`
è il fabbisogno.

- [ ] Prima di aprire un cantiere su una voce di roadmap, **verificare sul codice che sia ancora
      aperta**, non solo che sia scritta come aperta. La regola gemella esisteva già ed è il
      contrario di questa: *«se il changelog dice che è stato fatto e la roadmap dice che è da fare,
      ha ragione il changelog»*. Qui il changelog non diceva niente, e aveva ragione **il codice**.

### Le sette lezioni della beta.76 — scritte il 24/08/2026, aprendo la beta.77

Questa beta ha introdotto **quattro difetti** in una giornata, tutti miei, e nessuno l'ha trovato
chi scriveva. Le lezioni che seguono sono il conto di quella giornata.

**1. Generare l'artefatto vero su dati veri, prima di dire «fatto».** Il difetto più insidioso della
.76 — un condòmino con arretrati e versamenti che si annullano *esattamente*, che spariva da
entrambe le colonne mentre il totale di riga restava giusto — non l'ha trovato nessun test. L'ha
trovato la **generazione del PDF vero** sul condominio costruito dall'interfaccia per la verifica a
video. Nove test erano verdi.

- [ ] Quando una beta tocca un artefatto (un PDF, una pagina, una scrittura contabile), quell'artefatto
      si **genera e si guarda** prima di chiudere. Costa minuti e non dipende da come si è ragionato.

**2. Uno scenario di test simmetrico è una macchina per non vedere.** I nove test davano a tutte e
quattro le unità **gli stessi importi**: con quote uguali il residuo non è mai zero, quindi il caso
del difetto n. 1 non poteva comparire. Non mancava un test — mancava che i dati fossero diversi fra
loro.

- [ ] Importi **diversi da riga a riga** per default. Se un test ha bisogno della simmetria, ce ne
      vuole un secondo che non ce l'ha.

**3. Una grandezza dedotta per sottrazione va limitata da un fatto registrato.** Il netting si
ricavava con `netting = pregresso − residuo`: algebra **esatta**, e semanticamente falsa. La
differenza non sa *da cosa* nasce, quindi qualunque scarto verso il basso veniva battezzato «già
versato» — il subentro fatto a mano ne produceva uno da € 600,00 in un condominio dove nessuno aveva
versato niente.

- [ ] L'esattezza aritmetica non dice niente sul **significato** degli addendi. Una deduzione va
      sempre confrontata con una fonte che sappia dire *cosa* sia, non solo *quanto*.

**4. La correzione di una revisione non è rivista da quella revisione.** Il codice scritto per
chiudere i quattro reperti della prima passata è arrivato **dopo** che le lenti avevano finito, e
conteneva due difetti nuovi — fra cui una riduzione proporzionale che svuotava capitoli innocenti
(«Amministrazione» stampata € 694,32 contro € 1.600,00 deliberati **e** addebitati). Li ha trovati
una **seconda passata**, chiesta da Vincenzo.

- [ ] Dopo una revisione avversariale che ha prodotto correzioni **non banali**, la revisione non è
      finita: ne serve una seconda, stretta sul solo codice nuovo. Tre o quattro lenti, mezz'ora.
- [ ] Non annunciare mai «nessun difetto introdotto». Dire cosa è verificato, con cosa, e **cosa non
      lo è** — in particolare il codice scritto dopo l'ultima revisione.

**5. Quando nessuna euristica è giusta, il problema non è scegliere l'euristica.** Il resto negativo
va tolto da qualche parte: in proporzione svuota capitoli innocenti, sul capitolo a peso maggiore
azzecca lo scoperto dell'art. 1126 **solo perché lì lo scoperto sta sul capitolo più grosso**. Due
euristiche, due modi di sbagliare. Il segnale non era «trova la terza»: era che `residuo` è **uno
scalare per riga**, e da uno scalare non si ricava a quale capitolo appartenga.

- [ ] Quando due tentativi di indovinare falliscono in modi diversi, fermarsi: manca un dato, non
      un'idea. La .76 è uscita col comportamento pre-esistente e la ricerca del dato è diventata una
      voce di roadmap.

**6. Un `grep` su un marcatore conta chi ha marcato, non il fenomeno.** Vedi la lezione dedicata
poco sopra: i riquadri beta erano 18, non 13, e la parzialità **non si vede** — il numero ha l'aria
di essere la risposta.

**7. Una premessa comoda non diventa vera perché la si è scritta in tre documenti.** «Le stampe non
istanziano il motore» ha guidato l'intera architettura della .76, è finita nel changelog, in roadmap
e in un commento del codice — ed è **falsa per la gemella**, che lo istanzia da diverse beta. Se ne
è accorta la Fase 0.2 della beta successiva, aprendo il file. Il costo: la Coda 78 era stata
progettata come «persistere i registri nelle quote», con migrazione e backfill, mentre la correzione
vera è di poche righe e non tocca il database.

- [ ] Una premessa che **esclude** una strada («non si può fare X») va verificata aprendo il file,
      non ricordata. È il tipo di affermazione che nessuno ricontrolla, perché chiude la domanda.
- [ ] **La presenza di un file non è la presenza del difetto.** Variante della stessa lezione,
      costata il giorno dopo: la coda ㉞ («il `.env` si riscrive da solo leggendo un header») è stata
      dichiarata «ancora aperta» dopo aver verificato che `UpgradePatchServiceProvider` **esistesse**.
      Era chiusa dalla beta.65 da undici giorni, e il file c'è ancora perché la funzione legittima è
      rimasta — è solo cambiato *cosa* scrive. Un `ls` conferma l'esistenza, non il comportamento: si
      apre il file e si legge la riga.
- [ ] E quando la si scrive in un documento, scriverci **come** è stata verificata: «`grep` non trova
      `new CalcoloQuoteService` in `RipartoCapitoliService`» è controllabile, «le stampe non
      istanziano il motore» no — ed era il riassunto sbagliato di quel `grep`, allargato da un
      servizio a due.

### Come si dimensiona

La prima versione di questa revisione ha usato **27 agenti e 4,8 milioni di token**, e 23 sono morti per errori di rete. La forma che regge:

- [ ] **Tre o quattro lenti, una domanda stretta ciascuna**, mirate al raggio d'azione del diff. Non «leggi tutto e trova qualcosa»: le lenti che hanno prodotto i reperti veri erano quelle con una domanda precisa (le unità di misura, la coerenza fra il consiglio e il motore che deve accettarlo).
- [ ] **Nessuna fase di verifica automatica.** I reperti li verifica chi ha scritto il codice, aprendo i file. Costa meno e sbaglia meno.
- [ ] **Un reperto senza verdetto è NON VERIFICATO, non confutato.** Nella beta.46 i verificatori automatici sono morti tutti, e il risultato ha classificato i loro reperti come «scartati» con motivazione vuota. Leggere quella riga al contrario avrebbe fatto uscire la beta con tre difetti gravi dentro.
- [ ] **Contare i reperti prima di leggerli:** `grep -c '^▸' <file dei risultati>`. L'output della beta.46 era troncato in lettura: ne ho triageati 13 su 23, e fra i dieci non letti c'erano due `alta`.

### Il perimetro di raggiungibilità — quando una correzione aggiunge un comando

Il difetto più grave della beta.46 non era un calcolo sbagliato: era un **pulsante nuovo** su una riga che prima non ne aveva. Quel pulsante ha reso percorribile con il mouse un ramo del motore scritto per un caso deliberato, e a seconda dell'ordine di due righe a database produceva una pagina 500 oppure un trasferimento di denaro fra due persone.

Prima di aggiungere un comando dove non c'era, tre domande in quest'ordine. Costano dieci minuti e vanno **scritte**, non pensate:

- [ ] **Cosa diventa raggiungibile che prima non lo era?** Non «cosa fa il mio pulsante», ma dove arriva: si segue la chiamata fino alla prima precondizione o alla prima eccezione a valle. Nella beta.46 il ramo pericoloso era il primo `if` dell'azione chiamata — si vedeva leggendo trenta righe.
- [ ] **In quali modalità della schermata?** Una pagina ha più modalità di quella in cui la si sta guardando: qui erano ricerca per persona / per immobile, distribuzione automatica / manuale, e arrivo-da-un-link. La correzione era sana nella prima di ciascuna coppia e rotta nelle altre. Vanno **elencate** prima di scrivere, perché a difetto trovato si guarda solo quella in cui lo si è visto.
- [ ] **Ho i dati per decidere correttamente?** È il controllo più economico e quello che avrebbe fermato tutto: la decisione «di chi è questo credito» richiede un `anagrafica_id`, e il payload portava solo il *nome*. Un nome non è un'identità. Quando il dato per decidere non c'è, non si indovina: o lo si aggiunge, o non si offre il comando.

### Il punto cieco: i difetti che nessuno segnalerà mai

**Scritta il 21/08/2026, aprendo la beta.64.** Il feedback degli amministratori è, su questo
progetto, lo strumento che ha trovato più difetti veri di qualunque altro — e per una ragione
strutturale, non per fortuna: il motore di riparto **rinormalizza i pesi a 1**, quindi il totale
distribuito torna sempre uguale al preventivo. Nessun controllo interno può vedere un ingresso
mancante, perché i conti quadrano lo stesso (vedi la lezione 1 della beta.63 e la Coda 58).

Ma quello stesso strumento ha un **punto cieco preciso**, ed è il denaro chiesto **in più**.

Il difetto del netting corretto nella beta.63 chiedeva **€ 666,70 già versati** al condòmino che li
aveva versati. Nessuno l'avrebbe mai segnalato: chi riceve una rata la paga, e non ha modo di
sapere che quella cifra conteneva una parte già in cassa. Sul forum arrivano i difetti che
**l'amministratore riesce a vedere** — un errore evidente, un totale che non torna, una schermata
che risponde 500. Un addebito sbagliato di 666 euro con i conti perfettamente quadrati non è fra
quelli.

Vale la pena dirlo chiaro perché ribalta un'impressione comoda: *«tanto se sbagliamo ce lo dicono»*
è vero per l'interfaccia e **falso per il motore contabile**.

- [ ] Il rimedio non è più revisione: è una **sonda**. Pochi scenari di forma reale — un lastrico
      all'art. 1126, un capitolo con del già-versato, una tabella parziale, una comproprietà — con
      il risultato **calcolato a mano** e riverificato a ogni release. Pochi e veri valgono più di
      molti e sintetici: il difetto del netting si vedeva solo mettendo insieme *decurtazione* e
      *già-versato*, due cose che nessuno dei due test da solo copriva.
- [ ] La sonda si scrive **sugli ingressi**, mai sul totale. Un'asserzione «il piano quadra col
      preventivo» è verde per costruzione in un motore che rinormalizza: non è una prova debole, è
      una non-prova.
- [ ] Quando una beta tocca il motore, chiedersi *«questo difetto, se ci fosse, chi lo vedrebbe?»*.
      Se la risposta è «nessuno», la correzione ha bisogno di una sonda e non di un test qualunque.

⚠️ **La Coda 58 è il primo pezzo di questo lavoro e non è finito.** La catena delle quattro
proporzioni è misurata: due anelli chiusi nella beta.63, **due misurati e non chiusi**. Finché
restano aperti, il difetto che li riguarda continuerà a non essere visibile a nessuno — né a noi né
al forum.

### Ogni test dichiara cosa NON copre

Un test scritto insieme a una correzione dimostra che la correzione funziona **nello scenario per cui è stata pensata**. Sullo scenario a cui non si è pensato non dice niente — e sembra invece che dica tutto, perché è verde.

Nella beta.46 il test della riga a saldo misto copriva due unità della **stessa** persona, che è il caso sano; il difetto viveva nel caso con due comproprietari, e la suite restava verde. Un secondo test della stessa beta asseriva su **una chiave sola** di una struttura a sei, e lasciava passare la divergenza su tutte le altre.

- [ ] Ogni file di test nuovo porta una riga che dice **cosa resta scoperto**: quale modalità, quale forma dei dati, quale chiave del risultato. Si scrive mentre si scrive il test, quando la lacuna è ancora in mente — dopo non torna più.
- [ ] Il segnale d'allarme: un test il cui **nome promette più di quanto il corpo verifichi**.

## Fase 2 — Racconto e documentazione, **prima** del port

Questo è il passo che nell'ordine intuitivo viene per ultimo, e va invece qui: portare prima e documentare dopo significa fare il port due volte.

- [ ] `docs/changelog.md` — voce narrativa con titolo, in testa al file.
- [ ] `resources/data/changelogs/{it,en,pt}/<versione>.json` — le tre lingue.
- [ ] Se tocca il database: dirlo **esplicitamente** nel changelog, in apertura.
- [ ] Aggiornare i documenti in `docs/` che descrivono le aree toccate — vedi il metodo qui sotto. Se un documento diventa falso per colpa di questa beta, si corregge **in questa beta**.
- [ ] **Guide dentro il gestionale** — vedi sotto: sono la superficie che l'amministratore legge davvero.
- [ ] Versione in `config/app.php`.
- [ ] **Riconciliazione changelog ↔ roadmap.** Una domanda sola: *quali voci di roadmap ha toccato questa beta, anche solo in parte?* Le voci toccate si aggiornano **adesso**, non alla versione in cui erano collocate.
- [ ] **Le invarianti nuove sono nei test, non solo nei documenti.**
- [ ] **Nessun documento generato entra in `docs/` senza una lettura integrale.**

### Le tre regole scritte il 15/08/2026, dopo cinque guasti nella stessa indagine

Nascono dalla domanda di Vincenzo — «*la documentazione è il nostro cervello, se sbagliamo lì siamo fritti*» — e dalla constatazione che **nessuno dei cinque guasti sarebbe stato preso da un controllo automatico**. La misura completa e la diagnosi stanno nella coda ㉕ di [`roadmap.md`](roadmap.md); qui stanno le tre regole che costano zero.

**1. Un'invariante può stare in un documento solo se il documento nomina il test che la tiene.** Altrimenti si marca esplicitamente ⛔ *«non ancora presidiata»*. Un'invariante scritta in prosa è un'opinione; scritta in un test è un fatto che urla quando smette di essere vero. `ConcordanzaMotoreStampaTest` ha tenuto la stampa per tabelle e ha fallito **solo dove l'invariante non era stata scritta**: la combinazione «dissocia *e* stampa per capitoli» non era di nessuno dei due test esistenti. Il difetto non era nel documento: era nell'invariante mai scesa in un test.

**2. La riconciliazione changelog ↔ roadmap si fa quando la conoscenza è fresca, cioè adesso.** Chiudendo la beta si sa esattamente cosa si è toccato; fra due settimane no. Il guasto che questa regola previene è documentato: il blocco A2 è stato chiuso **a metà** nella beta.51 — le liste scritte a mano sostituite con `catenaRipiego()`, il tracciamento dello scoperto no — e la voce di 1.10.1 ha continuato ad allocarlo per intero. Chi l'avesse aperta avrebbe pianificato cinque giornate su un blocco che ne valeva metà. **Se il changelog dice che è stato fatto e la roadmap dice che è da fare, ha ragione il changelog e la roadmap sta mentendo.**

**3. Un documento generato da una ricerca si legge per intero prima di archiviarlo.** Non si fa `cp` in `docs/` e via. Il documento sulle pertinenze, alla prima stesura, decideva al §7 di mettere due date su un campo e vietava al §8 di aggiungere date senza un lettore: **si contraddiceva da solo**, e la contraddizione è emersa solo leggendolo tutto. Una pagina che si smentisce è peggio di una pagina che manca, perché chi la legge ne applica metà.

### Il livello che non deve stare in prosa

La conoscenza del progetto sta su tre livelli. Le **invarianti** vanno nei test (regola 1). Le **decisioni e il loro perché** vanno in prosa, ed è il loro posto: invecchiano piano, e a codice spostato la ragione resta valida. La **descrizione di come funziona il codice** — «`RipartoCapitoliService:474-497` ha le liste riscritte a mano» — **non va in prosa affatto**: marcisce in una beta, e i riferimenti `file:riga` continuano a risolvere mentre il contenuto di quelle righe è cambiato, quindi nessun controllo se ne accorge.

Il modello che funziona è già nel progetto: [`RuoloAnagraficaImmobile`](../app/Enums/RuoloAnagraficaImmobile.php) porta il ragionamento giuridico con le fonti **dentro il codice**, accanto alla regola che governa. Quella documentazione non può divergere, perché è il codice. La stessa informazione in un `.md` sarebbe già invecchiata due volte.

### Come si trovano i documenti da correggere

«Aggiornare i documenti che descrivono le aree toccate» non è una checklist: è un desiderio. Sono **56 documenti vivi** in `docs/` — più **4 archiviati** — per **32.255 righe** (ricontati il 19/08/2026 aprendo la beta.61; ⚠️ **questa riga non può essere vera a lungo, per costruzione**: si corregge in apertura di beta e la beta stessa la invalida scrivendo altre righe — alla .59 dichiarava 31.154 ed è finita a 31.568 prima ancora del port. Va letta come «l'ordine di grandezza», e ricontata col comando, mai ricordata; erano 30.336 alla .57 e 30.000 tondi quando questa riga è stata scritta chiudendo la beta.56 — si ricontano con `kondomanager:verifica-documentazione`, che è il modo di ricontarli; erano 58 in apertura della .56, 57 per 28.589 alla .54 e 27.967 alla .53 — il calo di tre non è una potatura di righe ma l'**archivio**: tre documenti conclusi sono passati in `docs/archivio/`, dove restano leggibili e smettono di pesare sul giro di rilettura), non si rileggono tutti a ogni beta. Il metodo che funziona parte **dal diff, non dai documenti**.

1. **Elencare cosa la beta ha cambiato davvero**: `git status --porcelain` e `git show --stat` della beta precedente.
2. **Grep dei simboli spariti.** Ogni metodo, colonna, classe, rotta o costante che la beta ha **rimosso o rinominato** va cercato in `docs/`:
   ```bash
   grep -rln "marcaSaldoApplicato\|<altro simbolo rimosso>" docs/
   ```
   Un simbolo che non esiste più nel codice ma vive ancora in un documento è un falso **certo**, non un sospetto: non serve giudizio per riconoscerlo. È il controllo con il miglior rapporto fra costo e rese, e va fatto sempre.
3. **Grep dei concetti toccati**, non solo dei simboli: i documenti sono scritti in italiano, non in PHP. Per il lucchetto dei saldi le parole erano `lucchetto`, `saldo_applicato`, `is_applicato`, `piano_rate_id`, `applica_saldi`.
4. **Leggere l'intestazione di stato** dei documenti trovati prima di correggerli: una specifica marcata `Non implementato` **non è falsa** perché descrive il futuro. Diventa da aggiornare solo se la beta l'ha implementata — o l'ha resa impossibile.
5. **Correggere solo le righe false.** Mai riscrivere il documento: contiene ragionamenti di Vincenzo che non vanno persi.
6. **Aggiornare la data dell'intestazione** dei documenti toccati: `verificato il <data> su <versione>`. Un'intestazione ferma a una versione vecchia è essa stessa un'informazione falsa.

Un documento che descrive un'area che la beta **non** ha toccato non va riletto per scrupolo: rileggere tutto a ogni beta è un proposito che si abbandona alla seconda, e un metodo che si abbandona è peggio di un metodo stretto che si rispetta.

### Quando un documento si archivia, e con quali prove

**Scritto il 16/08/2026, chiudendo la coda ㉕.** Il corpus era arrivato a 58 documenti, e sei di
essi avevano **nella propria intestazione** la sentenza già scritta — «superato», «da archiviare»,
«da non usare» — messa lì dall'audit del 31/07 e mai eseguita. Una decisione presa e non eseguita
è peggio di una non presa: il documento resta in mezzo agli altri e chi lo apre non guarda
l'intestazione.

`docs/archivio/` esiste da oggi. **Non è un cestino**: ci si arriva con tre prove, e si fanno
prima di spostare, non dopo.

- [ ] **Ciò che il documento descrive è concluso**, e la conclusione è scritta altrove: una voce di
      changelog, una guida viva, un documento che lo contiene. *Prova pratica:* se descrive uno
      schema dati, si verifica che le tabelle esistano davvero — `registrazione_incasso_rata.md`
      raccontava `pagamenti` e `pagamento_rata`, che a database **non ci sono mai state**.
- [ ] **Non contiene niente di unico.** Si misura, non si valuta a occhio: le frasi lunghe del
      candidato che non compaiono nella fonte che lo sostituisce. Su `logica_piani_rate.md` erano
      **5 su 78**, e quattro erano la sua stessa intestazione. Se qualcosa di unico c'è, si porta
      nella fonte nuova **prima** di archiviare.
- [ ] **I documenti che lo citavano sono stati ripuntati.** Il controllo dei link di
      `kondomanager:verifica-documentazione` lo verifica subito dopo: zero link rotti, o
      l'archiviazione non è finita.

⚠️ **Il candidato più urgente non è il più vecchio: è quello che insegna una cosa sbagliata.**
`registrazione_incasso_rata.md` conteneva `round($val * 100)` sugli importi — il bug del ×100 che
è costato la beta.32. Un documento superato fa perdere tempo; un documento che insegna un difetto
già pagato lo fa **rifare**.

### Potare, non cancellare: l'avviso deve stare dove si atterra

**Scritto il 16/08/2026, subito dopo l'archivio.** Tre documenti erano *parzialmente* superati: la
ricetta morta, il ragionamento ancora vivo e unico. Archiviarli avrebbe portato via il secondo per
liberarsi del primo.

La potatura giusta non toglie righe: **sposta l'avviso dove la persona arriva davvero.** Chi apre un
documento da un link o da una ricerca atterra a metà pagina, e l'intestazione di stato in cima non
la legge mai — è la forma di guasto che questo progetto continua a subire. Quindi ogni sezione
superata prende in testa un blocco `<!-- rettifica -->` che dice tre cose: **è superata**, **da cosa**,
e **cosa resta valido** lì dentro.

- [ ] Le sezioni che sono state *realizzate* prendono ✅ e il rimando alla beta che le ha chiuse:
      si leggono per capire cosa si voleva, non cosa manca.
- [ ] Le sezioni che sono *ricette da non seguire* prendono ⛔ e la ragione: sono quelle che, senza
      marcatore, qualcuno riapplica.
- [ ] L'intestazione del documento dichiara **la data della potatura**, così il comando la conta
      come una verifica e l'età riparte da lì.

*Costo misurato: tre documenti, sei sezioni marcate, zero righe perse — e l'età dei tre è passata da
23 beta a 0.*

### Le tre superfici della documentazione

Una beta può rendere falsa la documentazione in tre posti diversi, e sono facili da dimenticare in ordine crescente:

1. **`docs/` interni** — li leggi tu per decidere.
2. **Sito ufficiale** — lo legge chi ti sta valutando.
3. **Guide dentro l'applicazione** — le legge **chi sta usando il prodotto in quel momento**, ed è la superficie con l'impatto più immediato e quella che si dimentica per prima.

Le guide in-app vivono in `resources/js/components/guides/` — **diciotto** al 20/08/2026, ricontate chiudendo la beta.61 che ha aggiunto `QuoteMillesimiGuide.vue` (erano diciassette dalla beta.55 con `UtentiGuide.vue`) (erano quindici il 10/08: la beta.47 ha aggiunto `ImportGuide.vue`, la .49 `IncassoRateGuide.vue`), e sono richiamate dall'header delle pagine. Ma **non sono solo lì**: molte pagine hanno testi esplicativi propri — card informative, modali, tooltip — che sono documentazione a tutti gli effetti.

```bash
ls resources/js/components/guides/     # l'elenco vero, prima di dire quali esistono
```

Due delle **diciotto** sono nate dalle ultime beta e sono quelle che le voci di roadmap sui saldi e sul piano rate rendono false più in fretta: `SaldiGuide.vue` (beta.34) e `PianoRateGuide.vue` (beta.42). Se una beta tocca quelle due aree, quei due file vanno riletti **sempre**.

- [ ] Cercare i testi in-app che descrivono il comportamento toccato dalla beta: `grep -rn "<parola chiave>" resources/js/components/guides resources/js/pages`
- [ ] Correggerli nella stessa beta, come si fa con il changelog.

**Due casi reali, dalla beta.32:**

- La modale del lucchetto in `SaldiDetailPanel.vue` dice *«elimina il piano rate e il sistema rimuoverà i lucchetti»*. Era **falso** ogni volta che il piano era stato ricalcolato — la beta.32 l'ha reso vero. Una promessa fatta all'utente dentro l'app, mantenuta con un anno di ritardo.
- La card "Dati Blindati" in `SaldiList.vue` diceva *«i saldi inclusi in un piano rate **emesso** mostreranno un lucchetto»*, mentre il lucchetto scattava alla generazione: descriveva il comportamento che volevamo, non quello che c'era. Corretta nella stessa beta.

Il secondo caso è lo stesso schema del fallback Art. 1123 in `registrazione_fatture_passive.md`: qualcuno ha scritto il comportamento giusto nel posto sbagliato, e nessuno se n'è accorto perché nessuno rileggeva quei testi contro il codice.

## Fase 3 — Port in ufficiale

- [ ] `diff` fra le due cartelle: verificare che il delta sia **solo** quello della beta corrente. I due checkout possono divergere davvero.
- [ ] Il changelog si **appende**, non si sovrascrive alla cieca. Prima di copiarlo intero: `diff ufficiale test | grep -c '^<'` deve dare `0`.
- [ ] Attenzione ai file creati in una beta precedente già committata: lì si aggiunge la riga nuova, non si sovrascrive.
- [ ] Copiare, poi verificare che i file portati siano **identici** all'originale in TEST.
- [ ] **Codice e documentazione viaggiano insieme, nello stesso port.** Portare prima il changelog e poi il codice lascia in ufficiale, per il tempo che passa fra i due, un changelog che racconta codice inesistente — e se lì in mezzo arriva un commit, lo racconta per sempre. Il port è un'operazione sola: o è completo, o non è iniziato.
- [ ] **Allineare anche `docs/`, e dal 21/08/2026 si fa con git.** I documenti interni restano esclusi dal repository **pubblico** — regola invertita `docs/*` in `.gitignore` più quattro eccezioni (righe 33-37: changelog, guide Docker, Synology, Plesk) — ma non sono più fuori da *qualunque* repository: `docs/` è un repository a sé, la cui origine è il repository **privato** `vince844/kondomanager-docs` su GitHub. Si allinea con **`git -C docs push`** da TEST e **`git -C docs pull`** in ufficiale.

  ⚠️ **Il guadagno non è la comodità, è che una scrittura concorrente ora si vede.** Prima due sessioni che scrivevano nello stesso documento si sovrascrivevano in silenzio, e chi perdeva il lavoro non aveva modo di accorgersene: il 21/08/2026 è mancato poco: una `cp` da TEST verso l'ufficiale e la scrittura della Coda 64 da un'altra sessione si sono incrociate a quaranta minuti di distanza, e si sono salvate **solo per l'ordine in cui sono arrivate**. Ora quel caso produce un conflitto. Vedi `docs/LEGGIMI_REPOSITORY.md`.

  *(Questa riga ha già avuto due correzioni: diceva `.git/info/exclude` invece di `.gitignore` — corretta il 20/08/2026 — e diceva di copiare a mano, che dal 21/08/2026 è falso.)*
  ```bash
  diff -rq /Users/vincenzo/Desktop/kondomanager-free/docs \
           /Users/vincenzo/Desktop/KondoManager/kondomanager-free/docs
  ```
  Nessun output = allineati.

  ✅ **Rilanciato il 19/08/2026 chiudendo la beta.59: zero differenze.** Prima della copia divergeva
  il solo `changelog.md`, con **zero** righe `^<`. Confermata la trappola scritta alla .58: `roadmap.md`
  e gli altri 44 documenti gitignored non compaiono in `git status` e si copiano con `cp -R docs/.`.

    ✅ **Rilanciato il 18/08/2026 chiudendo la beta.58: zero differenze.** Prima della copia divergevano
  sei documenti — `changelog.md`, `roadmap.md` e le quattro `docker_local_dev.*.md` — e le righe `^<`
  erano **otto in tutto**, tutte sostituzioni volute della Fase 2 (la destinazione della coda ㊷, la
  riga «In sviluppo», la riga «Non iniziata» e le quattro intestazioni di stato delle guide Docker).
  Nell'ufficiale niente di unico, quindi la copia integrale era sicura.

  ⛔ **Superata il 23/08/2026, e va letta sapendo che oggi è falsa.** Diceva: «`roadmap.md` non
  compare in `git status` perché è gitignored, quindi l'elenco dei file da portare **non lo
  contiene**; va copiato a parte». Era vero finché `docs/` stava fuori da qualunque repository.
  Dal 21/08/2026 `docs/` **è** un repository, e `roadmap.md` è **tracciato al suo interno**
  (verificato: `git -C docs ls-files roadmap.md` lo trova, `git -C docs check-ignore` no). Compare
  quindi regolarmente in `git -C docs status`, e si allinea con `push`/`pull` come tutto il resto:
  **non va copiato a parte, e copiarlo a mano è oggi il modo di sovrascrivere il lavoro di un'altra
  sessione senza vederlo.**

  Resta vera la lezione generale da cui la trappola nasceva: `git status` **del repository del
  prodotto** non vede `docs/`, quindi l'elenco dei file da portare costruito da lì non contiene i
  documenti interni. La differenza è che oggi la risposta è `git -C docs pull`, non `cp`. L'unica
  eccezione è `changelog.md`, che è ignorato nel repository `docs` e **tracciato in quello del
  prodotto**: viaggia con il commit del codice, non con quello dei documenti.

  ✅ **Rilanciato il 16/08/2026 chiudendo la beta.54: zero differenze.** Divergevano quattro
  documenti — `changelog.md`, `roadmap.md`, `aggiornamento_universale.md` e il nuovo
  `utenti_sospensione_privilegi_e_titolarita.md` — e in ogni caso TEST era **avanti**: l'unica riga
  `^<` era la versione precedente di un'intestazione di stato che TEST aveva riverificato.

  ✅ Lanciato il 16/08/2026 chiudendo la beta.53: zero differenze. Prima della copia i due
  alberi divergevano su sette documenti — i sei toccati in Fase 2 più `changelog.md` — e in ogni
  caso TEST era **avanti**: `diff ufficiale test | grep '^<'` restituiva solo righe che la Fase 2
  aveva deliberatamente sostituito. Nell'ufficiale non c'era niente di unico, quindi la copia
  integrale era sicura. Il controllo delle righe `^<` va fatto **prima** di copiare, non dopo: è
  l'unico modo per accorgersi che qualcuno ha scritto in ufficiale.

  ⚠️ **Questa riga diceva «verificato il 02/08/2026: zero differenze» ed è rimasta lì mentre diventava falsa.** All'apertura della beta.51 l'ufficiale era indietro di due beta su tre documenti (vedi 0.1). Il controllo c'era, il passo non è stato eseguito, e la riga di verifica ha continuato a dire il contrario — cioè il documento ha mentito esattamente nel punto in cui prometteva di non farlo. Da qui in poi la data si aggiorna **solo dopo aver rilanciato il comando**, e se il comando non è stato lanciato la data si toglie invece di lasciarla invecchiare.
- [ ] ⚠️ **`git status --porcelain` collassa una cartella interamente non tracciata in UNA riga, e
  quella riga non è un file.** Scoperto nel port della beta.59, che l'ha saltato al primo giro.
  Se una beta introduce una cartella nuova — `app/Http/Controllers/Comuni/`,
  `resources/data/comuni/`, `resources/js/components/comuni/` — `git status` non elenca i file che
  contiene: stampa la cartella, con la barra finale. Un `cp` su quella voce **fallisce** e il port va
  avanti lo stesso, saltando esattamente i file nuovi della funzione principale.

  ```bash
  git status --porcelain | sed 's/^...//' | while IFS= read -r voce; do
    [ -z "$voce" ] && continue
    if [ -d "$voce" ]; then find "$voce" -type f; else echo "$voce"; fi
  done > elenco.txt
  ```

  Nella .59 i tre file saltati erano il controller della ricerca, l'elenco dei Comuni da 855 KB e il
  componente Vue: cioè la funzione, per intero. Il `cp` scriveva tre righe d'errore su stderr, che in
  mezzo all'output non le legge nessuno. **Il controllo che l'ha preso è il passo successivo** — la
  verifica `cmp` file per file — ed è la ragione per cui va fatto sempre, non «quando serve».

- [ ] **Copiare con `while IFS= read -r`, mai con un `for` su una variabile.** Sembra pignoleria e
  invece è la lezione più costosa di questo passo, imparata due volte. La prima: zsh non spezza le
  espansioni non quotate, quindi `for f in $MODIFICATI` tratta l'intero elenco come **un nome solo**
  e non copia niente. La seconda, scoperta aprendo la beta.54: nel port della beta.53 quello stesso
  elenco è finito dentro un `mkdir -p`, e in ufficiale è nata una cartella chiamata come l'intero
  elenco di file, con dentro 51 cartelle vuote annidate. **Git non l'ha mai segnalata** — non traccia
  le cartelle vuote — quindi `git status` era pulito e la sporcizia è rimasta lì una beta intera.
  Il `diff -rq` fra le due cartelle l'ha trovata subito: è il motivo per cui il primo passo di questa
  fase è quel diff e non la copia.
- [ ] Rieseguire la suite **dentro la cartella ufficiale**.

## Fase 4 — Commit

Lo fa Vincenzo, a mano. Claude non committa mai.

### Prima di committare: alzare il numero di versione

- [ ] **`config/app.php`, riga 29.** È l'**unico** posto in cui vive il numero — verificato con
      `grep` su tutto il repository: non è in `package.json`, non è nel manifest del canale di
      aggiornamento, non è duplicato da nessuna parte. Va alzato **nello stesso commit del
      codice**, come ha fatto ogni beta precedente.

  ```bash
  grep -n "'version' =>" config/app.php
  ```

**⚠️ Imparato il 23/08/2026, chiudendo la beta.74.** Il codice era stato scritto, i test erano
verdi, il port era stato fatto e verificato byte per byte, il commit era stato fatto e pushato —
e **il programma continuava a dichiararsi beta.73**, perché quella riga non l'aveva alzata nessuno.
Se ne è accorto Vincenzo scaricando da GitHub e ritrovandosi la versione vecchia.

Due ragioni per cui è sfuggito, ed entrambe si ripeterebbero:

1. **Il port non poteva vederlo.** L'elenco dei file da portare si costruisce dal `diff` fra le
   due cartelle, e `config/app.php` era **identico** nelle due — perché sbagliato in tutte e due.
   Un file che nessuno ha toccato non compare in nessun elenco di differenze.
2. **Questa fase non lo chiedeva.** La Fase 4 parlava solo di tracce dell'assistente. Il passo
   viveva nell'abitudine di chi committava, e l'abitudine non è un controllo.

**La conseguenza non è cosmetica:** le note di versione in-app si cercano per numero
(`resources/data/changelogs/<lingua>/<versione>.json`), quindi un numero fermo significa che il
changelog della versione nuova **non lo vede nessuno**, e che il canale di aggiornamento non
riconosce di avere qualcosa da proporre.

**Se il commit è già stato pushato**, non si riscrive: si fa un commit piccolo a parte per la sola
riga della versione. Un `--amend` costringerebbe a un push forzato, che è più rumoroso della
traccia che rimedia — stessa logica del commit con il trailer già pushato, qui sotto.

**Il repository è pubblico, e l'uso di un assistente non deve essere evidente.** Non è una preferenza estetica: è una scelta su come il progetto si presenta a chi lo valuta.

- [ ] Niente trailer `Co-Authored-By` nei messaggi di commit. È il testo che un assistente propone **per impostazione predefinita**, quindi va tolto prima di committare, non "ricordato se capita". Al 02/08/2026 ce n'è **uno** già pushato, in `2998e577` del 30/07 — vedi sotto.
- [ ] Niente file, cartelle o commenti nel codice che nominino l'assistente. Le esclusioni di `CLAUDE.md` e `.claude/` stanno in **`.git/info/exclude`**, non in `.gitignore`: quest'ultimo è tracciato e si legge su GitHub, quindi la regola stessa sarebbe la spia.
- [ ] Controllo periodico, che costa un secondo:
  ```bash
  git grep -il "claude\|anthropic" -- . ; git log --all --format="%s%n%b" | grep -i "co-authored"
  ```

**Sul commit già pushato.** Toglierlo significa riscrivere la storia (`rebase --exec` o `filter-branch`) e forzare il push: cambiano gli SHA di quel commit e di tutti quelli successivi, si rompono i cloni altrui, e su GitHub il commit vecchio resta raggiungibile per SHA finché non passa la garbage collection. È una decisione di Vincenzo, non un'operazione da fare di slancio: il rimedio è più rumoroso della traccia.

## Fase 5 — Sito ufficiale (solo se la beta lo merita)

Repo separato: `/Users/vincenzo/Desktop/KondoManager/kondomanager-website/` — HTML statico + Tailwind, nessuna cartella TEST, si lavora direttamente sui file.

Com'è fatto (ricontato il **19/08/2026** in apertura della beta.61: **34** pagine in `docs/`, **39** articoli in `blog/`, **96** file HTML in tutto — erano 33/39 alla beta.55, 31/35 il 10/08, 30/34 il 06/08 e 29/29 il 05/08; la pagina in più è `tabelle-millesimali-condominio.html`, scritta da un'altra sessione il 19/08 e già committata). Non sono tutte guide: `index.html`, `changelog.html`, `roles.html`, `settings.html`, `users.html` e le cinque pagine di installazione sono altro. È il numero che conta per la propagazione della sidebar, non per il conteggio delle guide.

*In cinque giorni le tre cifre sono cambiate tre volte, ed è il motivo per cui vanno ricontate e non citate: ogni riga di questo documento che contiene un numero invecchia da sola. Al 10/08/2026 erano invecchiate **tutte** quelle di questa pagina — guide in-app, pagine del sito, versione in sviluppo, condomìni demo.*

| Cartella | Contenuto | Attenzione |
| :--- | :--- | :--- |
| `blog/` | un file per articolo | card da aggiungere in `blog.html` |
| `docs/` | guide utente | la sidebar "Guide Gestionale" è **duplicata su tutte le pagine** (**34** al 19/08/2026, apertura della .61; erano 33 il 16/08 e 30 il 06/08): una guida nuova va propagata ovunque, mobile e desktop. Contarle con `ls docs/*.html \| wc -l` prima di iniziare, non fidarsi di questo numero |
| `social/` | `patreon-<slug>.md` + `facebook-<slug>.md` | una coppia per articolo |
| `assets/img/` | copertine OG 1200×630 | naming `kondomanager-<slug>-og.png` |
| `assets/img/docs/` | screenshot delle guide | naming `<slug>-<sezione>.png` |
| `packages/` | **il canale di rilascio** — vedi Fase 7 | non è marketing: da qui si aggiornano le installazioni |

Deploy: `Dockerfile` con `php:8.2-apache` su Coolify, dietro Cloudflare. Due trappole già pagate: lo schema di `ServerName https://…` **non** viene ereditato dai VirtualHost (va messo dentro `<VirtualHost *:80>` insieme a `UseCanonicalName On`, altrimenti `mod_dir` continua a emettere redirect `http://`), e in `RUN printf` i `\t` restano letterali se non si usa `%b` al posto di `%s`.

**Quando una beta merita di uscire dal changelog:** quando cambia ciò che un amministratore *fa* o *credeva*, non ciò che fa il codice.

| Merita | Non merita |
| :--- | :--- |
| Una funzione nuova con un titolo suo nel changelog | Un refactor, una colonna interna, un rename |
| Un bug che gli utenti stavano subendo senza saperlo | Un refuso, un aggiustamento di testo |
| Un numero sbagliato che finiva su carta | Un fix visibile solo nei log |

Un bugfix può meritare un articolo eccome: la beta.32 (il lucchetto dei saldi) è la storia di un amministratore murato fuori dal proprio wallet, e si racconta meglio di molte funzioni nuove.

Se merita:
- [ ] Guida pratica in `docs/` del sito — how-to con esempio numerico e **screenshot reali** (Playwright su dati veri, mai mockup).
- [ ] **Aggiornare una pagina esistente vuol dire guardare anche le sue immagini.** Vedi «Una pagina aggiornata è una pagina rifotografata» qui sotto.
- [ ] Articolo blog — il "dietro le quinte": perché la funzione è nata, che problema reale risolve, cosa si è rotto per strada.
- [ ] Wiring completo, non solo i file nuovi: card in `blog.html`, link nella sidebar di **tutte** le pagine `docs/*.html` (mobile e desktop), voci in `sitemap.xml`. **Le tre verifiche stanno più sotto, in «Il wiring non è fatto finché non si vede».**
- [ ] **Riquadro di versione, se la funzione non è ancora nella stabile** — vedi «Il sito parla al presente» qui sotto. Vale per la guida **e** per l'articolo.
- [ ] Copertina OG 1200×630 costruita come **pagina HTML nello stile del sito** (stessa palette Sora/DM Sans, gradiente scuro coerente con la categoria dell'articolo) e catturata con Playwright — mai un'immagine generata altrove.

  **Dal 05/08/2026 c'è uno strumento pronto:** `src/og-template.html` + `src/genera-og.sh` nel repo del sito. Una riga:

  ```
  ./src/genera-og.sh <nome-file-senza-estensione> "<riga 1>" "<riga 2>" "<sottotitolo>" ["<categoria>"]
  ```

  Entrambi stanno in `src/`, che `.dockerignore` esclude dal deploy: sono strumenti, non pagine. Il template carica `assets/css/kondo-fonts.css`, quindi la tipografia è **quella vera del sito** senza doverla replicare.

  *Perché la regola esiste:* le prime quattro copertine di quel giorno erano state generate con ImageMagick, che **non legge i `.woff2`** — il risultato era in Arial invece che in Sora. Rifatte con Playwright lo stesso giorno. Se un giorno ti ritrovi a scegliere un font di sistema «che assomiglia», ti sei già allontanato dal processo.

### Una pagina aggiornata è una pagina rifotografata

Scritto il **16/08/2026**, dopo che Vincenzo ha dovuto farlo notare. Chiudendo la beta.54 ho
aggiunto a `docs/settings.html` la sezione dell'impostazione nuova — testo corretto, markup
identico alle sorelle, riquadro di versione al posto giusto — e **non ho toccato le immagini**.
La pagina ha uno screenshot del pannello impostazioni: mostrava cinque voci, e da quel momento
mostrava un pannello in cui l'impostazione appena descritta **non c'è**.

⚠️ **Non è uno screenshot mancante: è uno screenshot che smentisce il testo.** È peggio, perché
una guida senza immagini si legge lo stesso, mentre una guida la cui immagine contraddice il
paragrafo accanto insegna al lettore che le immagini non sono affidabili — su tutto il sito, non
solo lì.

La regola vecchia diceva «guida nuova → screenshot reali», e per una guida **nuova** funziona:
non ci sono immagini, quindi ci si accorge che mancano. Per una pagina **aggiornata** non
funziona, perché le immagini ci sono già e sembrano a posto: la modifica riguarda il testo, e lo
sguardo non arriva mai fino alle figure.

- [ ] Prima di dichiarare fatta una modifica a una pagina di `docs/`, elencare le sue immagini e
      chiedersi, una per una, **se quello che mostrano è ancora vero**:
      ```bash
      grep -o 'src="[^"]*"' docs/<pagina>.html
      ```
- [ ] Un'immagine che ritrae una schermata **cambiata dalla beta** va rifatta, anche se la
      modifica al testo riguarda un'altra sezione. La domanda non è «ho aggiunto un paragrafo che
      chiede una figura?» ma «una figura che c'era è diventata falsa?».
- [ ] Vale anche al contrario: se la beta **toglie** qualcosa da una schermata, l'immagine che
      ancora la mostra è altrettanto sbagliata.
- [ ] Le nuove riprese seguono le regole di «Uniformità»: stesso viewport e stesso
      `deviceScaleFactor` dell'immagine che sostituiscono, altrimenti la pagina si vede a colonne
      di nitidezza diversa. Le dimensioni della vecchia si leggono con
      `sips -g pixelWidth -g pixelHeight <file>` **prima** di rifarla.

**Gli screenshot dichiarano da soli quanto sono vecchi.** Il piè di pagina dell'applicazione porta
la versione — *«KondoManager software open source per il condominio v1.10.0-beta.35»* — e finisce
dentro ogni scatto a pagina intera. È il modo più economico per sapere quali immagini del sito
ritraggono un programma di venti beta fa, senza aprirle una per una:

Senza OCR — `tesseract` non è installato, ImageMagick sì — il modo che funziona è ritagliare la
striscia di fondo di ogni immagine e montarle in un provino unico, che si legge in un colpo solo
invece di aprire cinquanta file:

```bash
mkdir -p /tmp/strisce && rm -f /tmp/strisce/*.png
for f in assets/img/docs/*.png; do
  magick "$f" -gravity South -crop 55%x4%+0+0 +repage -resize 820x "/tmp/strisce/$(basename "$f")"
done
magick montage /tmp/strisce/*.png -tile 1x -geometry +0+3 -background '#eee' /tmp/provino.png
```

⚠️ Il ritaglio al 4% dal fondo prende il piè di pagina solo sulle immagini a **pagina intera**:
quelle ritagliate su un dettaglio mostrano il centro della pagina, e vanno guardate a parte. Il
provino dice quali immagini sono vecchie, non quali sono sbagliate — sono due domande diverse, e
un'immagine di venti beta fa può essere ancora perfettamente vera.

*Lanciato il 16/08/2026: la maggior parte delle immagini di `docs/` riporta ancora
`v1.10.0-beta.35`. Non è di per sé un difetto, ma è la misura di quanto la documentazione visiva
resti indietro se nessuno la guarda mai.*

**Lo strumento c'è, dal 16/08/2026:** `src/scatta-app.sh` nel repo del sito.

```bash
./src/scatta-app.sh <percorso-app> <nome-file> [largh] [alt] [ancora-css] [scala]
./src/scatta-app.sh /impostazioni/generali settings-panel 1256 730 "form"
./src/scatta-app.sh "/admin/gestionale/52/immobili?per_page=20" pertinenze-elenco 1600 1800 "" 1
```

Le credenziali stanno in `.env.shot` (escluso da git), quindi il comando non ne porta nessuna.

⚠️ **Tre cose che il primo uso ha insegnato, tutte a costo di uno scatto sbagliato:**

- **La scala non è uniforme sul sito.** `settings.html` ha immagini a 2x (2512 px di file per 1256
  di viewport), la guida pertinenze a 1x (1600 per 1600). Dentro **una stessa guida** devono
  coincidere, altrimenti le figure escono a nitidezze diverse una sotto l'altra. Si legge la
  sorella con `sips -g pixelWidth` prima di scegliere.
- **L'ancora incornicia scrollando, non ritagliando.** Senza, lo scatto parte dall'alto della
  pagina e mostra intestazione e cartoncini invece del pannello.
- **La schermata va messa nello stato che la guida racconta.** L'elenco unità con dieci righe
  perdeva proprio Box 8, Box 15 e Cantina 16, cioè i tre casi di cui parla la guida delle
  pertinenze: lo stato si impone dall'indirizzo (`?per_page=20`), non si spera che sia quello
  giusto.

Fa il login con l'**utente di servizio** che questa pagina prevede già (§0.1-bis), va alla
schermata, forza le animazioni `.reveal` — senza, metà pagina esce trasparente — e salva in
`assets/img/docs/<nome>.png` a `deviceScaleFactor: 2`. Le misure si passano in pixel CSS, quindi
sono **la metà** di quelle del file: per rifare un'immagine da 2512 × 1310 si passa `1256 655`,
letto prima con `sips -g pixelWidth -g pixelHeight`.

⚠️ **Le credenziali le fornisce chi lancia lo script, per variabile d'ambiente, e non passano da
Claude.** Il browser interno degli strumenti sa navigare e mostrare la pagina ma **non sa salvare
su disco**, e la sessione autenticata vive lì dentro; passare quel cookie a Playwright è
esattamente ciò che non deve accadere. Con lo script il confine è netto: Claude scrive il comando
e sa quale immagine va rifatta, chi ha le credenziali lo esegue.

*Le variabili si passano per ambiente e non come argomenti perché gli argomenti finiscono in `ps`
e nella cronologia della shell.*

### Il sito parla al presente, e la beta non è il presente

Scritto il **16/08/2026**, dopo che l'articolo della beta.53 è uscito senza. La guida della stessa
beta ce l'aveva: il riquadro era stato messo dove me lo ricordavo, non dove serviva.

Chi arriva da Google su una pagina del sito **scarica la versione stabile**, non la beta. Se la
pagina descrive una funzione che nella stabile non c'è, quella pagina mente — ed è lo stesso
difetto che la beta.52 ha dovuto correggere in homepage, dove promettevamo una validazione dei
millesimi che non esiste. Un amministratore l'aveva cercata, non trovata, e aveva scritto sul forum.

- [ ] **Ogni pagina** che descrive una funzione non ancora nella stabile porta un riquadro ambra in
      apertura, che dice tre cose: **quale versione descrive**, **cosa si scarica oggi**, e che la
      beta non è la versione su cui appoggiare un archivio di lavoro.
- [ ] Vale per la **guida in `docs/` e per l'articolo in `blog/`**, non per una sola delle due.
- [ ] Se il resto della pagina vale comunque — perché parla di normativa e non di software — **dirlo
      dentro il riquadro**. Una guida sulle pertinenze resta utile anche a chi usa un altro
      gestionale, e il riquadro non deve farla sembrare inservibile.

⚠️ **Come si incastra con le due regole più vecchie di questa pagina**, che a lettura veloce
sembrano dire il contrario:

- *«Il sito descrive l'ULTIMA UFFICIALE, non la beta in corso»* riguarda i **numeri che l'utente
  userebbe per decidere** — requisiti di PHP, versione del pulsante di download, estensioni. Quelli
  restano quelli della stabile, sempre, e si aggiornano in Fase 7.
- *«Riferimenti di versione nelle guide»* vieta i numeri **sparsi nel testo** e ammette
  l'eccezione di un riquadro dedicato quando chi sta su una versione diversa verrebbe ingannato.

Il riquadro beta **è** quell'eccezione, applicata al caso opposto nel tempo: non «se usi una
versione precedente», ma «se usi quella che si scarica oggi». Il numero sta **solo lì dentro**, e
nel corpo la guida continua a parlare al presente e senza versioni.

**Il riquadro si marca, non si ricorda.** Sopra ogni riquadro va un commento HTML:

```html
<!-- BETA-1.10: da rivedere al rilascio della 1.10 stabile -->
```

Al rilascio della stabile li si trova tutti con un comando, invece di ricordarseli:

```bash
grep -rn "BETA-1.10:" docs/ blog/ index.html
```

⚠️ **Perché un marcatore e non una lista in questo documento.** Una lista invecchia da sola: qualcuno
aggiunge una pagina e non la aggiorna, oppure toglie un riquadro e la riga resta. Il marcatore vive
**dentro il file**: se il riquadro sparisce, sparisce anche lui, e il grep dice la verità senza che
nessuno lo mantenga. ⚠️ **Al 20/08/2026, chiudendo la .61, ne trova tredici, in nove file** — questa
riga ne dichiarava *quattro* dal 16/08, poi *nove in sette* alla .60, poi *dieci in otto*
all'apertura della .61 e *dodici in otto* mezz'ora prima di questa rilettura: `docs/users.html` e
`docs/tabelle-millesimali-condominio.html` ne hanno **tre** ciascuno, poi `docs/settings.html`, le
guide import, incasso e pertinenze, e due articoli del blog. Nove file e tredici riquadri sono due
numeri diversi, e alla stabile vanno tolti **i riquadri**, non i file.
Il numero ha già sbagliato quattro volte, e l'ultima di mezz'ora: **si legge col comando, mai a memoria**
— `grep -rn "BETA-1.10" docs/ blog/ *.html | wc -l`.

### Il wiring non è fatto finché non si vede

Scritto il **16/08/2026**, dopo aver dichiarato fatta una card che sulla pagina non si trovava.

Avevo verificato che il link esistesse — `grep -c` restituiva 1 — e la card **c'era davvero**, in
terza posizione di una griglia cronologica, mentre portava la data più recente. L'avevo inserita
prima di un'ancora scelta a caso invece che in testa. Il controllo misurava l'esistenza; la domanda
era la posizione.

- [ ] La card di un articolo nuovo va **in testa a `#postGrid`**, non prima di un'ancora qualsiasi:
      la griglia è cronologica e l'articolo appena scritto è il più recente. Verificare l'ordine,
      non la presenza:
      ```bash
      python3 - <<'PY'
      import re
      s = open('blog.html').read(); g = s[s.index('id="postGrid"'):]
      for i,(h,d) in enumerate(re.findall(r'<a href="(blog/[^"]+)"[\s\S]{0,2600}?(\d{1,2} \w+ 202\d)', g)[:5], 1):
          print(i, d, h.split('/')[-1])
      PY
      ```
- [ ] Sidebar `docs/`: contare le occorrenze dello slug per pagina, che devono essere **due**
      (desktop e mobile). ⚠️ Il conteggio va fatto **sullo slug del link**, non sullo slug e basta:
      nella pagina nuova lo slug compare anche in `canonical`, `og:url` e JSON-LD, quindi un
      controllo `>= 2` la considera a posto mentre le manca la voce nel menù mobile. È successo il
      16/08/2026.
      ```bash
      for f in docs/*.html; do n=$(grep -c 'href="<slug>.html"' "$f"); [ "$n" -ne 2 ] && echo "$f: $n"; done
      ```
- [ ] Le due varianti della sidebar hanno **markup diverso** — desktop sono `<a class="doc-link">`
      dentro l'`<aside>`, con la spaziatura nel CSS; mobile sono `<a class="block px-3 py-1.5 …">`
      dentro `#mobileMenu` — quindi una sostituzione sola ne prende metà e il controllo di sopra è
      l'unico modo per accorgersene. *(La riga diceva «desktop usa `px-3`, mobile `px-4`»: `px-3` e
      `px-4` stanno **tutt'e due nel menù mobile**, sui due livelli di voce, e il desktop non ha
      nessuna classe `px-`. Corretta il 20/08/2026 leggendo `docs/index.html:43-52` e `:275-276`.)*
- [ ] Se si apre un **capitolo nuovo** nella sidebar, il nome è il contenitore, non il tema della
      prima pagina che ci finisce dentro: le pertinenze sono andate sotto **«Immobili»**, non sotto
      «Anagrafica», perché lì dentro arriveranno anche «creare un immobile» e i soggetti.
- [ ] Guida e articolo si **rimandano a vicenda**: la guida dice cosa fare, l'articolo perché è
      fatto così, e chi arriva sull'una spesso vuole l'altra.

### Un font variabile sotto-dichiarato fa inventare il grassetto al browser

Trovato il **16/08/2026**, partendo da una segnalazione di Vincenzo: nella sidebar della
documentazione il titolo di una guida stava su una riga, ma **andava a capo sulla pagina di quella
stessa guida**, cioè quando la voce era evidenziata.

La causa non era la lunghezza del titolo. `assets/css/kondo-fonts.css` dichiarava DM Sans come font
variabile nell'intervallo `font-weight: 300 500`, mentre `.doc-link.active` chiede **600**. Un peso
fuori dall'intervallo dichiarato non fallisce: il browser **sintetizza** il grassetto ispessendo i
glifi, e il finto grassetto è più largo del peso vero. La voce diventava quindi più larga da attiva
che da inattiva.

⚠️ **La prima diagnosi era sbagliata a metà, e vale la pena saperlo.** Avevo concluso «nessuna regola
deve chiedere pesi sopra 500» e segnalato sette selettori. Guardandoli uno per uno, **tre usavano
Sora**, che è dichiarato `300 800`: quei 700 erano legittimi. Il controllo giusto non è un tetto
unico, è **per famiglia**.

E soprattutto: il file `.woff2` conteneva l'intervallo completo. Misurato rendendo la stessa stringa
dai 100 ai 1000, le larghezze crescono a ogni passo — quindi la dichiarazione **sotto-dichiarava il
font**, e la cura non era abbassare i pesi nelle pagine ma **dichiarare l'intervallo vero in un file
solo**. Con `100 1000`, `.doc-link.active` torna a 600 come da disegno e non sintetizza più niente:
il `<strong>` di tutti i 36 articoli del blog smette di essere un finto grassetto.

- [ ] Prima di abbassare un peso in una pagina, **misurare se il file lo contiene**: si rende la
      stessa stringa a pesi diversi e si confrontano le larghezze. Se crescono, il font ce l'ha e
      il difetto è nella dichiarazione.
- [ ] Il controllo dei pesi si fa **per famiglia**, confrontando con l'intervallo dichiarato in
      `kondo-fonts.css` (oggi: DM Sans `100 1000`, Sora `300 800`, Lora `400 600`).
- [ ] ⚠️ Il sintomo non è «un testo un po' più spesso»: è **un elemento che si sposta o va a capo
      solo in uno stato**. Se una cosa cambia dimensione quando la si evidenzia, il primo sospetto
      è un peso sintetizzato.

**E misurare a video, non a occhio.** La prima idea era «il titolo è troppo lungo, accorciamolo».
Misurando in pagina è emerso che a 13rem andavano a capo **anche altre due voci** che esistevano da
prima. La sidebar è passata da `w-52` a `w-56`: a 14rem stanno su una riga tutte e trentadue, e il
corpo del testo resta a 758 px.

```javascript
// conta le voci di sidebar che occupano più di una riga, alla larghezza corrente
const soglia = document.querySelector('aside .doc-link').getBoundingClientRect().height * 1.5;
[...document.querySelectorAll('aside .doc-link')]
  .filter(x => x.getBoundingClientRect().height > soglia).map(x => x.textContent.trim());
```

### Una pagina nuova deve somigliare alle sue sorelle, e il controllo è uno script

Scritto il **16/08/2026**, dopo che guida e articolo delle pertinenze sono usciti **ciascuno senza
qualcosa**. Vincenzo: *«tutte queste cose dovrebbero essere scritte anche in un flusso di lavoro
così ogni volta non devo ricordartelo»*.

Le due mancanze avevano cause diverse, ed è il motivo per cui una checklist a memoria non basta:

- **L'articolo** aveva perso tag, iscrizione alla newsletter e pulsanti di condivisione. Non me li
  ero dimenticati: li ha portati via la **sostituzione del corpo**, perché stavano dentro
  `<article>` dopo le FAQ e io ho rimpiazzato tutto il blocco.
- **La guida** non aveva la navigazione fra guide in fondo — ma non ce l'aveva **nessuna** delle 33
  pagine. Non era una mancanza della pagina nuova: era una mancanza del sito, che si è vista solo
  guardando la pagina nuova.

Da qui la regola: **non si controlla contro una lista, si controlla contro le pagine che esistono.**

```bash
./src/verifica-pagina.sh docs/mia-guida.html
./src/verifica-pagina.sh blog/mio-articolo.html
```

Lo script confronta la pagina con tutte le sorelle della stessa cartella e segnala ciò che hanno
**almeno tre quarti di loro** e a lei manca: newsletter, tag, condivisione, navigazione fra guide,
indice laterale, canonical, JSON-LD, `og:image`, footer. In più verifica che il canonical sia uno
solo e punti a sé, che non siano rimasti slug del modello in `canonical`/`og:url`, e che ogni FAQ
dichiarata in `FAQPage` esista **anche a schermo**.

⚠️ **Il vantaggio del confronto sulla soglia è che il documento non invecchia.** Il giorno che le
pagine guadagnano un blocco nuovo, lo script inizia a chiederlo da solo appena tre quarti di loro
ce l'hanno — senza che nessuno aggiorni questa pagina.

- [ ] Lanciare lo script su **ogni** pagina nuova, guida o articolo, prima di dichiararla finita.
- [ ] Se segnala qualcosa che manca a **tutte** le pagine (come la navigazione fra guide il
      16/08/2026), la correzione è sul sito intero, non sulla pagina nuova: **uniformare**, non
      aggiungere solo dove si è guardato.
- [ ] L'ordine di lettura delle guide è quello della **sidebar di `docs/index.html`**: è da lì che
      si genera la navigazione precedente/successiva, non da un elenco scritto a mano.

**Lo schema e la pagina divergono in silenzio.** Passando lo script su tutto il sito sono usciti
**due articoli** in cui `FAQPage` prometteva un testo che sulla pagina era stato riscritto: la
pagina era stata rivista, lo schema no. Nessun controllo se ne era accorto, perché entrambi erano
validi presi da soli. La cura è **rigenerare lo schema dal contenuto visibile**, mai il contrario —
la pagina è la versione che le persone leggono.

⚠️ **Attenzione a delimitare il blocco FAQ quando lo si rigenera.** Il primo tentativo ha preso i
`<h3>` di tutta la pagina e ha infilato nello schema quattro card di articoli correlati come se
fossero domande. Il regex va ancorato al contenitore delle FAQ, non al tag.


#### Quello che lo script non può vedere: un blocco giusto che dice la cosa sbagliata

Scritto il **16/08/2026**, guardando a video la pagina che lo script aveva appena dichiarato
conforme.

In fondo all'articolo delle pertinenze c'era il riquadro d'invito alla demo, al posto giusto, con
il markup giusto, dentro il conteggio dei blocchi attesi. Diceva: *«nella demo trovi il libro
giornale con le scritture in dare e avere… puoi fare le tre domande di questo articolo a un sistema
in partita doppia vera»*, e il pulsante «Leggi la guida pratica» portava al piano dei conti. Era il
riquadro dell'articolo da cui avevo clonato la pagina, e con le pertinenze non c'entrava niente.

**Lo script controlla che i pezzi ci siano, non che dicano il vero.** È un limite di categoria, non
un difetto da correggere: `verifica-pagina.sh` confronta strutture, e una struttura corretta piena
di parole sbagliate le passa tutte. Un articolo può superare ogni controllo automatico ed essere
ancora, per metà, l'articolo di qualcun altro.

- [ ] **Aprire la pagina e leggerla**, guida e articolo, prima di dichiararli finiti. Non
      scorrerla: leggere i blocchi che **non si sono scritti**, che sono esattamente quelli
      ereditati dal modello — riquadro finale, sottotitolo dell'occhiello, testo della
      condivisione, titoli dei correlati, descrizione meta.
- [ ] Il sospetto si restringe da sé: **ciò che non si è riscritto è
      ciò che parla ancora del modello.**

**E si può far dire allo script quali sono.** Passandogli come secondo argomento la pagina da
cui la nuova è nata, elenca i blocchi di testo del corpo rimasti **identici**:

```bash
./src/verifica-pagina.sh blog/mio-articolo.html blog/modello-da-cui-e-nato.html
```

Non sa se quei blocchi dicono il vero — sa dire quali nessuno ha toccato, che è l'unica cosa
che serve per sapere dove guardare. Il confronto è limitato al corpo redazionale, fra
`<article>` e `</article>`: prendendo fino a fondo pagina entravano articoli correlati e footer, che
sono uguali per costruzione e sommergevano i residui veri. Sull'articolo delle pertinenze, dopo la
correzione, ne resta **uno solo** ed è il testo della newsletter, che deve restare uguale.

**Il controllo delle FAQ verificava sé stesso, e per giorni non se n'è accorto nessuno.** Confrontava
il testo dello schema con la pagina «senza tag», ottenuta togliendo `<...>` — ma il **contenuto** di
`<script>` non è un tag e sopravviveva alla pulizia. Quindi ogni domanda dichiarata nel JSON-LD
risultava «a schermo» per il solo fatto di essere nel JSON-LD: il controllo passava sempre. L'ho
scoperto scrivendo un articolo con quattro FAQ nello schema, la voce `#faq` nell'indice e **nessuna
sezione visibile**, e vedendolo dichiarare «conforme».

- [ ] Un controllo che non ha mai fallito va messo alla prova **rompendo di proposito** ciò che
      dovrebbe prendere. Due righe: si toglie il blocco e si verifica che lo segnali.
- [ ] Quando si estrae «il testo visibile» di una pagina, il primo passo è togliere `<script>` e
      `<style>` **con il loro contenuto**, non solo i tag.
- [ ] Attenzione a sostituire i tag con uno spazio: `dell'<strong>art. 63</strong>` diventa
      `dell' art. 63` e non combacia più con lo schema, pur essendo lo stesso testo. Il confronto
      va fatto **senza spazi** su entrambi i lati.

Corretto il controllo, sono uscite **nove pagine** in cui lo schema e il testo divergevano davvero:
la pagina era stata riscritta e il JSON-LD no. Riallineate prendendo il testo dalla pagina, con un
metodo che cerca ogni domanda **già dichiarata** e ne sostituisce la sola risposta — così non può
aggiungere né togliere domande, che è l'errore fatto la prima volta.

**E c'è un secondo punto cieco: lo script confronta con le sorelle della stessa cartella.** Se
un'intera sezione del sito ha derivato insieme, le pagine si confermano a vicenda e il controllo
passa. È successo con il **logo**: tutte e 33 le pagine `docs/` mostravano una casetta nel quadrato
del marchio, mentre le altre 51 del sito mostrano «Km». Ogni pagina era coerente con le proprie
sorelle, quindi conforme; il sito no. L'ha vista Vincenzo passando da una sezione all'altra.

- [ ] Per gli elementi che devono essere identici **su tutto il sito** — barra di navigazione,
      logo, footer — il confronto va fatto su tutte le cartelle insieme, non dentro una sola:
      ```bash
      grep -rL 'leading-none">Km<' --include="*.html" . | grep -v redirect
      ```
- [ ] Il sospetto si apre quando **si cambia sezione**, non scorrendo una pagina: `docs/` era
      internamente perfetto da mesi.

⚠️ **Il riquadro finale è il punto in cui questo sbaglio costa di più.** È l'ultima
cosa che si legge, contiene la chiamata all'azione, e se parla di un'altra funzione manda il lettore
sulla guida sbagliata proprio nel momento in cui aveva deciso di provare.


⚠️ **Corretto il 19/08/2026, aprendo la .61.** Questa riga diceva che
`importare-dati-altro-gestionale.html` e `stato-patrimoniale-non-quadra.html` erano fuori dalla
sidebar: **non è più vero**, sono citate da 75 e 74 pagine su 34+ del sito, cioè sono in sidebar
ovunque. Nessuna pagina `docs/` è oggi fuori dalla sequenza. ⚠️ Il controllo va fatto **guardando i
due blocchi di sidebar** — desktop e mobile hanno markup diverso — e non contando le occorrenze del
nome: una pagina citata due volte potrebbe averle entrambe in un link «precedente/successivo».

### Un articolo di prodotto non porta traffico, e va scritto sapendolo

Scritto il **16/08/2026**, rispondendo a una domanda di Vincenzo: *«l'articolo è anche stato scritto
in ottica SEO?»*

La risposta onesta per un «dietro le quinte» è **no, e non può esserlo**: nessuno cerca su Google
«la tabella che avete cancellato». Il titolo di un articolo di prodotto è pensato per chi già ci
segue — forum, changelog, newsletter — e la sua resa si misura in fiducia, non in clic.

Ma una superficie di ricerca ce l'ha comunque, ed è l'unica che vale la pena curare:

- [ ] **Il blocco FAQ.** Lì dentro vanno le domande che la gente digita davvero — «il box paga le
      spese condominiali?», «come faccio a non far pagare le scale al box?» — con risposte
      autonome, che si capiscano fuori dal contesto dell'articolo.
- [ ] **Le FAQ devono esistere anche a schermo.** `FAQPage` in JSON-LD senza il corrispondente
      visibile è markup che promette contenuto assente: nel primo giro della beta.53 lo schema
      portava ancora le tre domande dell'articolo di partenza, che parlavano di partita doppia.
      Il controllo è di due righe e va fatto sempre:
      ```bash
      python3 -c "
      import json,re,sys
      s=open(sys.argv[1]).read()
      d=json.loads(re.search(r'<script type=\"application/ld\+json\">\s*(\{.*?\})\s*</script>', s, re.S).group(1))
      [print('OK ' if q['name'] in s else 'MANCA ', q['name'][:60]) for n in d['@graph'] if n['@type']=='FAQPage' for q in n['mainEntity']]" <file>
      ```
- [ ] **Il traffico lo porta la guida, non l'articolo.** È la guida che va scritta come pagina-problema
      («il box paga le spese condominiali?»), con la funzione come risposta: la regola del rapporto
      due-a-uno si soddisfa lì, non chiedendo all'articolo di prodotto di essere ciò che non è.


### Articoli misti: due di problema per ogni uno di prodotto

Deciso il 05/08/2026, insieme alla strategia SEO fino a gennaio.

I due tipi di articolo fanno cose diverse e non vanno pubblicati in parti uguali.

Gli articoli **sulle novità del prodotto** servono a chi già ti conosce: dimostrano che il progetto è vivo, danno prova sociale, tengono la community. Ma **non portano traffico nuovo** — nessuno cerca su Google una funzione di cui ignora l'esistenza.

Gli articoli **sul problema** portano gente che non ti conosce. Sono quelli che costruiscono il patrimonio: le quattro guide del 05/08/2026 (bilancio che non quadra, consuntivo 1130-bis, conguaglio, tabelle millesimali) funzioneranno ogni settembre per anni.

**Il rapporto è due di problema per ogni uno di prodotto.**

E c'è un modo per farli coincidere, che vale più di entrambi separati: quando rilasci una funzione, invece di scrivere «ora puoi fare X», **scrivi la guida al problema che X risolve e mostra la funzione come risposta**. La beta.42 con le date di scadenza configurabili non diventa «ora scegli tu le date», ma *«quando far scadere le rate condominiali: criteri, vincoli e cosa succede se le sposti»* — che è una query reale, intercetta chi non ti conosce, e mostra la funzione a chi arriva. Un pezzo solo che fa due lavori.

**Regola di scrittura per l'articolo-problema:** se togli ogni menzione di KondoManager e la pagina resta valida e utile — anche a chi usa Danea — è scritta bene. Il prodotto compare solo nel riquadro finale e nella CTA, mai nel corpo. Le quattro guide del 05/08 hanno **zero** occorrenze del nome nel corpo, ed è verificabile con un grep.

### Tre cose imparate pubblicando la guida della beta.38

**Il canonical sbagliato non era un rischio: era già successo, su otto pagine.** Il documento
avvisava della trappola, ma nessuno l'aveva cercata all'indietro. Un `<link rel="canonical">`
vagante prima di `</head>`, copiato di modello in modello, puntava **tutte** le guide del ciclo
passivo a `piano-dei-conti-condominio.html`: Google le trattava da duplicati e con ogni
probabilità non le indicizzava. Il controllo costa un comando e va fatto **su tutto il sito**,
non solo sulla pagina nuova:

```bash
for f in docs/*.html; do n=$(grep -c 'rel="canonical"' "$f"); [ "$n" -ne 1 ] && echo "$f: $n"; done
```

- [ ] Dopo aver creato una pagina da un modello, contare i canonical: devono essere **uno**.
- [ ] Stessa verifica sul JSON-LD e sull'indice laterale: nell'articolo della beta.38 erano
      rimasti il titolo, la descrizione e **tre FAQ** del post di partenza, che parlavano di
      tutt'altro. Si vedono solo aprendo la pagina — nessun controllo automatico li nota.

**La sidebar drifta.** `installation-docker.html` aveva perso l'intero ramo «Ciclo passivo»
dalla sidebar desktop: da lì otto guide erano irraggiungibili. Propagare la voce nuova non
basta, va **verificato il conteggio** su ogni pagina e in entrambe le varianti:

```bash
for f in docs/*.html; do n=$(grep -c '<slug-nuovo>' "$f"); [ "$n" -lt 2 ] && echo "$f: $n"; done
```

**Tailwind: il sintomo non è un colore sbagliato, è un elemento che sparisce.** La regola
«dopo aver toccato classi Tailwind rilancia `npm run build:css`» era già scritta qui, e non è
bastata — perché mancava il seguito utile, cioè *come ci si accorge di averla dimenticata*.

Una classe assente dal CSS compilato **non produce un ripiego**: non genera nulla. Nella card
della beta.38 mancava `from-amber-950`, quindi il gradiente non aveva colore di partenza, la
testata restava **bianca**, e il badge di versione — scritto in `text-white/80` — diventava
bianco su bianco e spariva. Guardando la pagina sembrava che il badge non fosse stato messo,
non che mancasse una classe.

- [ ] Dopo `npm run build:css`, verificare che le classi **nuove** esistano davvero. Attenzione
      alla forma escapata: `md:grid-cols-2` nel file compilato è `md\:grid-cols-2`, e un grep
      letterale non la trova facendo credere che manchi.
- [ ] Verificare anche che la build non abbia **tolto** nulla: se i `content` glob della
      configurazione non coprissero un file, ricompilare eliminerebbe classi usate altrove,
      rompendo pagine che nessuno stava guardando. Il confronto costa un comando:
      `git show HEAD:assets/css/kondo.css` e contare le regole prima e dopo.
- [ ] Se una classe serve solo a una pagina nuova, **preferire quella che il sito usa già**.
      Nella beta.38 la card è passata da `from-amber-950` a `from-indigo-950`, identica alle
      altre: il colore lo porta il badge, che è la parte che deve distinguersi. Meno rischio e
      più coerenza.

**La coda dell'articolo sta DENTRO `<article>`.** Sostituendo il corpo del post in blocco si
perdono, tutti insieme e in silenzio: i **tag**, la **card della newsletter**, i pulsanti di
**condivisione** e il **riquadro finale**. Il testo dell'articolo sembra perfetto e mancano
quattro blocchi che nessun controllo automatico nota.

- [ ] Dopo aver riscritto il corpo, contare i quattro commenti-segnaposto:
      `grep -c '<!-- Tags -->\|<!-- Newsletter -->\|<!-- Share -->\|<!-- CTA Box -->'`
- [ ] **I link di condivisione portano l'URL e il testo dell'articolo precedente.** È lo stesso
      difetto del canonical, in un posto in cui nessuno guarda: verificato il 03/08/2026,
      `annullare-fattura-condominio-eliminare-stornare.html` condivideva su Facebook
      `preventivo-consuntivo-spese-non-contate.html`, cioè un altro articolo. Corretto.
      Il controllo, su tutto il blog:
      ```bash
      for f in blog/*.html; do n=$(basename "$f"); grep -o 'sharer.php?u=[^"]*' "$f" | grep -qv "$n" && echo "$n"; done
      ```

**Le copertine OG: `setContent` non carica i font.** I font del sito sono ospitati in proprio e
il CSS li richiama con percorsi **relativi**: costruendo la copertina con `page.setContent()`
quei percorsi non risolvono e l'immagine esce in un sans di sistema invece che in Sora — e la
differenza si nota solo guardando il PNG. La copertina va **scritta come file nella radice del
sito e servita da lì**, poi cancellata.

- [ ] Costruire la copertina in un contesto a `deviceScaleFactor: 1`, non riscalarla dopo: il
      resto degli scatti è a 2× e il contesto va tenuto separato.
- [ ] Guardare il PNG prima di considerarlo fatto. Il controllo automatico sugli hash dice che
      due immagini sono *diverse*, non che sono *giuste*.

### Immagini: la regola è una sola

**Niente mockup, niente immagini inventate.** Gli screenshot si catturano sull'applicazione vera, con dati reali.

Due strumenti con due mestieri diversi, e vanno usati in quest'ordine:

1. **Browser di Claude su `http://127.0.0.1:8001` — per VERIFICARE.** Dopo il login fatto da Vincenzo si naviga, si clicca e si guarda. Serve a controllare che la schermata dica davvero quello che dovrebbe **prima** di fotografarla: è così che si evita di pubblicare una guida con dentro un difetto. Non produce file: restituisce l'immagine da guardare, non un PNG su disco.
2. **Playwright — per PRODURRE.** Installato nello scratchpad (non è nel repo), con un utente admin temporaneo da cancellare a fine lavoro. È l'unico che scrive i file in `assets/img/docs/`, e l'unico che sa fare pagina intera e dimensioni esatte (le copertine OG a 1200×630).

Fotografare senza aver prima verificato è come scrivere il changelog senza far girare i test: gli screenshot restano online per anni, e uno screenshot sbagliato racconta una bugia più a lungo di un paragrafo sbagliato — nessuno rilegge le immagini.

**I condomìni demo.** Non si fotografa mai un condominio reale: i nomi veri finirebbero online. A DB ce ne sono **quattro** — id 28, 29, 30 e 31, riverificati il 10/08/2026 — creati con gli script nello scratchpad e riutilizzabili (sono idempotenti):

| Condominio | A cosa serve | Stato |
| :--- | :--- | :--- |
| **Condominio Demo KM** | verifiche end-to-end | piano rate **non** emesso: i saldi restano liberi |
| **Condominio Demo Foto** | screenshot | piano rate generato, approvato ed **emesso**: il lucchetto è davvero visibile |
| **Condominio Vuoto Verifica** | stati vuoti degli elenchi | esercizio aperto e **nient'altro**: zero immobili, gestioni, tabelle, conti |
| **Condominio Collaudo 46** *(id 31)* | **verifica, non foto** — casi che nei dati reali non esistono | costruito nella beta.46 per i quattro difetti della coda ⑧: comproprietari con credito di uno e debito dell'altro sulla stessa unità, righe che nettano a zero. ⚠️ **Non ha `anagrafica_condominio`** (vedi sotto), e dalla beta.48 ha una cassa «Banca collaudo» |

Il quarto non è un condominio da fotografare ed è **da conservare**: contiene le configurazioni che servono a riprodurre i difetti dell'area incassi e che costruirle da capo costa mezz'ora ogni volta. Quando una beta tocca quell'area, si parte da lì.

> ⚠️ **Lezione dell'11/08/2026 — ✅ superata dalla beta.49, tenuta qui perché il difetto è
> istruttivo.** Tentando la verifica della guardia ⑧(a) su Collaudo 46, `StoreIncassoRateRequest`
> rifiutava il pagante **prima** di arrivare alla logica che si voleva provare, con un messaggio
> che parlava d'altro («il pagante selezionato non è valido»): pretendeva il pivot
> `anagrafica_condominio`, e gli script popolano solo `anagrafica_immobile`.
>
> - [ ] Dalla beta.49 uno script di prova **può** limitarsi ad `anagrafica_immobile`: la
>       validazione accetta il pivot **oppure** il possesso di un'unità nel condominio
>       (`StoreIncassoRateRequest.php:45-57`). Il pivot resta necessario solo per le persone
>       associate al condominio che **non possiedono niente** — accesso al portale, consiglieri.
> - [ ] Il difetto vero che questo ha fatto emergere non era degli script: anche l'importatore non
>       popolava il pivot, quindi ogni condominio importato era nello stesso stato. Era la coda ⑫
>       in roadmap, blocco di rilascio della 1.10, ✅ **chiusa nella beta.49** — allargando la
>       validazione invece di riparare i dati, che è la scelta che ha risolto anche il caso qui
>       sopra.

I primi tre servono tutti e tre perché sono stati fra loro incompatibili: il primo deve mostrare i saldi modificabili, il secondo deve mostrarli bloccati, il terzo deve non avere niente da mostrare. Fotografare o verificare sul condominio sbagliato significa guardare una schermata che non contiene ciò che si sta cercando — e concludere che funziona.

Il terzo è nato nella beta.34 e resta utile: gli elenchi ancora **senza** stato vuoto sono **due** — i saldi e i piani rate —, mentre gli altri dieci lo hanno dalla beta.34 via `TableEmptyState.vue`. *(Questa riga ne dichiarava sette, compresi sei che nel frattempo l'avevano acquistato: ricontata il 20/08/2026.)* Finché restano quei due, la verifica si ripeterà. Per la variante «la ricerca non ha prodotto nulla» non serve un altro condominio: basta aggiungere `?nome=<qualcosa>` all'URL dell'elenco.

### Uniformità: la trappola che si vede solo a pagina pubblicata

Le immagini della guida sono renderizzate con `w-full`: **vengono tutte stirate alla stessa larghezza di colonna**. Un ritaglio di elemento largo 1684 px messo accanto a una pagina intera da 2880 px viene ingrandito del 70% in più, e si legge come sfocato e "zoomato" — il difetto non si vede nel PNG, si vede solo nella pagina.

- [ ] Tutti gli screenshot di una guida: **stesso viewport, stesso `deviceScaleFactor`, sempre il viewport intero.** Mai mescolare `locator.screenshot()` (ritaglio) e `page.screenshot()` nella stessa guida.
- [ ] Per inquadrare un dettaglio si **scrolla**, non si ritaglia: `el.scrollIntoView({ block: 'center' })`.
- [ ] `scrollIntoViewIfNeeded()` **non scrolla** se l'elemento è già dentro il viewport: due scatti consecutivi escono identici e non te ne accorgi finché non li guardi. Verificare che ogni immagine sia diversa dalle altre, non solo che esista.
- [ ] Controllo finale prima di chiudere: `sips -g pixelWidth -g pixelHeight <tutti i png>` — le dimensioni devono coincidere.
- [ ] Cancellare l'utente admin temporaneo (`forceDelete`, non soft delete).

Vale anche per le copertine: si costruiscono come pagina HTML del sito e si fotografano, così restano coerenti con il resto anche quando la palette cambia.

| Cosa | Dove | Naming |
| :--- | :--- | :--- |
| Copertina OG 1200×630 | `assets/img/` | `kondomanager-<slug>-og.png` |
| Screenshot delle guide | `assets/img/docs/` | `<slug>-<sezione>.png` |

### Il sito descrive l'ULTIMA UFFICIALE, non la beta in corso

**Imparato l'08/08/2026, sbagliando.** Lavorando alla beta.47 ho «corretto» la pagina
`docs/installation-wizard.html` del sito portando i requisiti da PHP 8.2+ a 8.4+ e allungando la
lista delle estensioni con quelle che l'importatore richiede. Sembrava un errore evidente da
sanare: `composer.json` dichiara `^8.4` e l'installer di sviluppo ha `MIN_PHP_VERSION = 8.4.0`.

Era sbagliato, ed è finito in produzione prima che me lo facessero notare.

**Il perché, in una riga:** quella pagina offre l'installer della **1.9.1**, l'ultima ufficiale,
e quell'installer dichiara `MIN_PHP_VERSION = 8.2.0` e controlla sei estensioni, non dodici.
Scrivendo 8.4 il sito diceva a un amministratore su PHP 8.2 che non poteva installare — mentre
l'installer che avrebbe scaricato funziona benissimo sul suo server. Un requisito **più severo
del vero** non è prudenza: è un utente che rinuncia sulla pagina di download.

- [ ] Prima di toccare un requisito sul sito, leggerlo **dentro l'installer che la pagina offre
      davvero**, non dentro il repository di sviluppo:
      ```bash
      unzip -p "<versioni>/v<X.Y.Z>/index-v<X.Y.Z>-beta.<n>.zip" index.php | grep -n "MIN_PHP_VERSION\|requiredExt"
      ```
- [ ] I requisiti della versione in beta si aggiornano nella **Fase 7**, al rilascio ufficiale,
      insieme al numero di versione del pulsante di download. Non prima.
- [ ] Vale per ogni numero della documentazione pubblica, non solo per PHP: versioni di MySQL,
      dimensioni massime, nomi delle voci di menu. **Il sito racconta ciò che l'utente può
      scaricare oggi.**

### Riferimenti di versione nelle guide

Le guide descrivono il **comportamento attuale, al presente, senza numeri di versione**: un numero sparso nel testo invecchia a ogni rilascio e sporca la lettura.

L'eccezione, e va usata solo lì: **quando un comportamento è cambiato e chi sta su una versione vecchia verrebbe ingannato dalla guida.** In quel caso un riquadro dedicato — «Se usi una versione precedente alla X» — che dice cosa succedeva prima, cosa succede ora, e rimanda al changelog. Esempio in `docs/gestione-saldi-iniziali-condominio.html` del sito, sezione del lucchetto.

Il criterio pratico: il riferimento serve a chi legge la guida **e non riconosce il proprio prodotto**. Se nessuno può trovarsi in quella situazione, il numero di versione è rumore.
- [ ] Badge versione: nella card blog = **la beta specifica** che ha introdotto la funzione; nel menu di navigazione = **l'ultima versione ufficiale rilasciata**. Sono due concetti diversi, non vanno mai confusi.

## Fase 6 — Social

Bozze in `social/`, una coppia per articolo: `patreon-<slug>.md` e `facebook-<slug>.md`.

**Claude prepara le bozze, pubblica Vincenzo.** Nessun contenuto esce verso l'esterno senza che sia lui a premere il pulsante.

Taglio diverso per i due canali: Patreon parla a chi sostiene il progetto e regge il dietro le quinte tecnico; Facebook parla ad amministratori che hanno un problema oggi e vuole il beneficio in tre righe.

---

## Fase 7 — Rilascio ufficiale (NON si applica alle beta)

**Spostata il 22/08/2026 in un documento suo: [`rilascio_sito_versione_stabile.md`](rilascio_sito_versione_stabile.md).**

Qui restava in coda a duemilasettecento righe che descrivono il processo delle *beta*, cioè nel punto
del documento che si legge di meno — per una procedura che si esegue una volta ogni due mesi e che
tocca **ogni installazione al mondo**. Chi rilascia non ci arriva leggendo: ci arriva cercando, e
cercando trovava prima le regole delle beta.

Il testo non è stato riassunto, è stato **trasferito e ampliato** con quello che la ricognizione del
22/08/2026 ha trovato e che qui non c'era: la creazione del file di changelog nelle tre lingue, il
tag git, la riscrittura di `parse_changelog.py`, i riquadri beta **senza marcatore**, i gemelli nel
JSON-LD, il cancello di `requirements.php` e il collaudo cronometrato delle migrazioni.

⚠️ **Non riscrivere qui una seconda checklist.** Due elenchi della stessa procedura divergono, e
quello che si legge per primo è quello sbagliato. Se una regola del rilascio stabile va aggiunta,
va aggiunta là.

Quello che resta di competenza di *questo* documento, perché riguarda le beta e non il rilascio:

- il **riquadro di versione** sulle pagine che descrivono funzioni non ancora nella stabile — vedi
  «Il sito parla al presente», qui sopra: si mette durante la beta, si toglie al rilascio;
- il marcatore `<!-- BETA-<versione>: da rivedere al rilascio della <versione> stabile -->` accanto
  a ogni riquadro, che è ciò che li rende ritrovabili;
- la regola che **il sito descrive l'ULTIMA UFFICIALE**: i requisiti pubblici restano quelli della
  stabile per tutta la durata della beta, e si aggiornano al rilascio.

---

## Trappole verificate sul campo

- **Il canonical.** Costruendo una pagina nuova da una esistente resta il `<link rel="canonical">` del modello e Google la tratta da duplicato: non la indicizza mai. È successo con `docs/piano-dei-conti-condominio.html`. Verificare che canonical, `og:url` e `@id` del JSON-LD puntino alla pagina stessa.
- **Il sommario «In questa pagina» si genera, non si copia.** Stessa famiglia del canonical: è l'altra cosa che un modello si porta dietro. Cambiando il corpo cambiano gli `<h2>` e i loro `id`, ma le voci del sommario restano quelle del modello e puntano ad ancore che non esistono più — cliccando non succede niente. Verificato il 02/08/2026: **nove pagine** con il sommario completamente inerte (le otto guide del ciclo passivo più un articolo), sette voci orfane ciascuna. Nessuno se n'era accorto perché un sommario rotto non dà errore: semplicemente non fa nulla.
  **Regola:** ricostruirlo dai titoli veri della pagina, mai riscriverlo a mano.
  ```python
  voci = re.findall(r'<h2 id="([a-z0-9-]+)"[^>]*>(.*?)</h2>', s, re.S)   # sommario = questi, sempre
  ```
  **Controllo prima di pubblicare:** ogni `href="#…"` con classe `toc-link` deve corrispondere a un `id` presente nel documento.
- **`<title>` e `<h1>` hanno due mestieri.** Il title serve al motore di ricerca: la formulazione che la gente digita, sotto i 561 px (~60 caratteri). L'h1 e l'`og:title` parlano a una persona e possono restare narrativi.
- **Niente promesse al futuro.** «In arrivo con la 1.9.1», «sarà disponibile» diventano false al rilascio dopo e fanno sembrare il progetto fermo. A ogni rilascio, cercare sul blog `in arrivo|arriverà|sarà disponibile|prossime settimane|ultimo aggiornamento`: è l'unica categoria che nessuno script corregge da solo.
- **Dopo aver toccato classi Tailwind** nelle pagine del sito va rilanciato `npm run build:css`, o la classe non esiste nel CSS compilato.
- **Il simbolo dell'euro va PRIMA dell'importo: «€ 1.200,00», non «1.200,00 €».** È la forma usata in Italia ed è quella dell'applicazione, quindi guide e prodotto devono coincidere. Sfugge di continuo perché scrivendo di getto viene naturale posporlo. Verificato il 02/08/2026: 77 occorrenze sbagliate in 13 file fra blog, guide e landing, accumulate su mesi.
  **Controllo prima di pubblicare** — cerca gli importi con il simbolo posposto e li corregge senza toccare `<script>` e `<style>`:
  ```python
  rx = re.compile(r'(?<![\w€])(\d[\d.]*(?:,\d+)?)\s*€')   # 1.200,00 €  →  € 1.200,00
  ```
  Da rilanciare a ogni articolo o guida nuova, non solo quando qualcuno se ne accorge.
- **In italiano la maiuscola sta a inizio frase, non a ogni parola.** «Cambiare gestionale senza ribattere i dati», non «Cambiare Gestionale Senza Ribattere i Dati». Vale ovunque compaia del testo per l'utente: `<h1>`, `<h2>`, `<title>`, titoli delle card, etichette, badge, pulsanti, testi dei file di lingua, titoli dei dialoghi. **Vale sia per il sito sia per il gestionale.**
  Sfugge per lo stesso motivo dell'euro: è la forma inglese, si legge tutto il giorno, e scrivendo di getto viene naturale. Ma su una pagina italiana si vede subito e fa sembrare il testo tradotto da un'altra lingua.
  **Eccezione unica:** i titoli delle voci di changelog, che sono deliberatamente narrativi («Il Credito Che Non Si Poteva Spendere») e restano così per coerenza con tutte le versioni precedenti.
  **Controllo prima di pubblicare.** Non serve contare le maiuscole — i nomi propri ne hanno tante e sono giusti. Il segnale certo è **una parola grammaticale maiuscola in mezzo alla frase**: articoli, preposizioni e congiunzioni in italiano non prendono mai la maiuscola tranne dopo un punto.
  ```python
  # «Senza», «Che», «Dei», «Non» a metà titolo = Title Case inglese.
  # Da ignorare se preceduta da . ! ? : ; — - « " ( → lì apre una frase nuova.
  GRAMMATICALI = {'di','da','del','della','dei','degli','delle','il','lo','la','i','gli','le',
                  'un','uno','una','e','ed','o','ma','con','per','tra','fra','in','nel','nella',
                  'su','sul','sulla','a','ad','al','alla','ai','agli','alle','che','chi','non',
                  'si','ne','come','se','quando','dove','perché','senza','dopo','prima','verso',
                  'durante','secondo','tramite','anche','solo','più','ogni','questo','quello'}
  # segnala il titolo se ≥2 di queste compaiono maiuscole non a inizio frase
  ```
  Verificato il 08/08/2026 sull'intero sito e su `lang/it` + `resources/js`: **tre soli casi**, tutti reali e nessun falso allarme — l'h1 dell'articolo sulla migrazione dei dati e «Fattura Non Ancora Approvata» nei due dialoghi dei pagamenti. Il resto era già corretto, quindi il controllo costa poco e va rilanciato per intero, non solo sui file toccati.
  ⚠️ **Coda del 10/08/2026:** i due casi del gestionale erano stati corretti in TEST l'08/08 e **non erano mai stati portati in ufficiale** — la beta.47 non toccava quei file, quindi il port della Fase 3 non li ha visti. Ritrovati dal `diff` fra cartelle della Fase 0.1 e riapplicati dopo il reset; viaggiano con la beta.48. Vedi la quarta lezione della beta.47.
- **Le parole chiave si verificano con una ricerca vera, non si deducono.** Imparato il 09/08/2026 sull'articolo della migrazione da Danea. La revisione aveva già corretto la cosa più grossa — il nome del concorrente non compariva nel corpo — ma le query erano state *dedotte*. Cercandole davvero è emerso che **«passaggio di consegne»** è un grappolo di ricerche molto frequentato, presidiato da siti affermati, ed è esattamente il momento in cui un amministratore ha bisogno di caricare un condominio: l'articolo non lo nominava mai. Stessa cosa per **«subentro»**, che è il termine di mestiere per la compravendita a metà anno — e che il gestionale usa già nelle proprie schermate.
  **Regola:** prima di fissare title e description, cercare le query candidate e guardare *chi risponde già*. Due cose da annotare: le parole che i risultati usano e l'articolo non ha, e i concorrenti che presidiano la query.
  **Il segnale che manca una parola:** il gestionale la usa nell'interfaccia e l'articolo no. Se «subentro» sta nella guida in-app e non nel pezzo, è il pezzo a essere fuori sincrono con il vocabolario del mestiere.
- **I file veri di un utente si mascherano PRIMA di aprirli.** Un export mandato da un
  amministratore per farci provare qualcosa contiene i dati di persone che non ci hanno mai
  scritto: nomi, codici fiscali, indirizzi, e quanto ognuno deve al condominio. Che ce li abbia
  mandati lui non è il consenso di quelle persone, ed è la ragione per cui questi file **non
  entrano nel repository** — regola che c'è già. Manca il resto: cosa succede *mentre* li si guarda.

  **Imparato il 23/08/2026, analizzando la segnalazione dell'amministratore dei file Danea.** I
  file sono rimasti sulla macchina, letti da un processo locale — ma i risultati stampati a
  terminale passano dagli strumenti che si stanno usando, e lì dentro sono finiti il **nome del
  condominio**, l'**indirizzo** e il **codice fiscale**. I nomi delle persone no, ma solo perché
  li aveva pseudonimizzati lui: cioè la protezione è arrivata dalla prudenza dell'utente, non
  dalla nostra procedura.

  **La regola, in due righe.** Prima di aprire un file vero se ne fa una copia mascherata:
  via la testata (denominazione, indirizzo, codice fiscale) e via le colonne dei nomi. Poi si
  lavora **su quella**, e l'originale non lo si apre più.

  **Non è una perdita.** Nel caso del 23/08 tutto ciò che serviva erano ruoli, millesimi e
  importi: i € 214,36 sono stati sciolti sommando quattro righe per ruolo, e il credito di
  € 203,72 è stato ritrovato cercando un importo, non un nome. **Se l'analisi ha bisogno del
  nome di qualcuno, quasi sempre sta guardando la cosa sbagliata.**

  **Nella risposta all'utente, invece, i nomi ci vanno** — sono i suoi, e riconoscere la sua riga
  è ciò che gli fa capire che qualcuno ha davvero guardato. Il mascheramento serve a monte, non a
  valle.

  ```bash
  # struttura sì, contenuto no: intestazioni e forma bastano quasi sempre per capire un tracciato
  # (le prime righe di un export Danea portano denominazione, C.F. e indirizzo: si saltano)
  ```
- **Scrivere una guida leggendo il codice invece che usando il prodotto.** È la trappola più insidiosa perché produce pagine **corrette e incomplete**: si documenta quello che il codice fa, non quello che l'utente vede. Verificato il 02/08/2026 sul capitolo «Ciclo passivo»: la pagina sulla regolazione immediata, scritta dai Service e dalle Request, parlava di «pochi campi tutti necessari». Aprendo la schermata vera c'erano un protocollo assegnato in anticipo, l'anteprima della scrittura DARE/AVERE in tempo reale con indicatore di quadratura, tre riquadri «quando usarla / quando non usarla / effetto contabile» scritti meglio di come li avevo riscritti io, le scorciatoie da tastiera e un pulsante «Registra e nuova». Niente di sbagliato nella pagina — semplicemente il prodotto era più ricco del racconto.
  **L'ordine corretto è: aprire la schermata, guardarla, poi scrivere, poi fotografare.** Il codice serve a verificare *perché* una cosa succede, non a scoprire *cosa* c'è. Se una guida in-app esiste già per quella schermata, leggerla: spesso dice cose che nessun sorgente rivela in modo evidente.
- **Verificare i fatti contro il codice** prima di scrivere — testi esatti della UI, numeri, comportamenti — anche quando si crede di ricordarli dalla sessione stessa.

---

## La regola che tiene insieme tutto

Un documento in `docs/` o descrive codice che esiste, o dichiara in testa che non è implementato, con la data. Un documento che mente costa più di un documento che manca: è la causa del bug del lucchetto orfano, ed è ciò che l'audit del 31/07/2026 ha trovato in 25 documenti su 30.
