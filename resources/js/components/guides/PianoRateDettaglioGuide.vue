<script setup lang="ts">
/**
 * La guida in-app del dettaglio piano rate — la pagina che un amministratore apre più spesso di
 * qualunque altra, e che fino alla beta.73 non aveva **nessuna** spiegazione oltre le tre card
 * riassuntive in testa (Verifica dati / Approvazione / Stato incassi).
 *
 * ⚠️ **Ogni comportamento descritto qui è stato letto nel codice**, non ricordato:
 * `PianiRateShow.vue`, i controller in `app/Http/Controllers/Gestionale/PianiRate/` e
 * `CalcoloQuoteService`/`RataQuote` per la parte sul credito. Dove il componente aveva già scritto
 * la spiegazione in un `HoverCard` (lo switch, i pulsanti, l'emissione), questa guida la riprende
 * — è la fonte più affidabile perché l'ha scritta chi ha costruito la funzione.
 *
 * Il colore del credito (blu) qui dentro copre **due casi**, ed è il punto toccato dalla Coda 69:
 * il saldo iniziale a importo negativo e lo strapagamento — chi ha versato più della sua quota.
 * Prima della beta.73 la pagina riconosceva solo il primo caso.
 */
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
  AlertTriangle, Wallet, Gavel, RotateCw, ArrowRightLeft, Printer, Coins,
  CheckCircle2, PieChart, Clock, Ban, ReceiptText, Trash2, Info, Lock,
} from 'lucide-vue-next';

defineProps<{ open: boolean }>();
defineEmits(['update:open']);
</script>

<template>
    <Sheet :open="open" @update:open="$emit('update:open', $event)">
        <SheetContent class="w-full overflow-y-auto p-0 sm:w-[640px] sm:max-w-2xl">
            <div class="px-6 py-8">
                <SheetHeader class="mb-8">
                    <div class="mb-2 flex items-center gap-3">
                        <div class="rounded-lg bg-indigo-100 p-2 text-indigo-700 dark:bg-indigo-900 dark:text-indigo-300">
                            <Wallet class="h-6 w-6" />
                        </div>
                        <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: dettaglio piano rate</SheetTitle>
                    </div>
                    <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
                        Cosa fa ogni pulsante, cosa significa ogni colore, e cosa succede davvero
                        quando emetti, ricalcoli o rimuovi qualcosa.
                    </SheetDescription>
                </SheetHeader>

                <Tabs default-value="stato" class="w-full">
                    <TabsList class="mb-6 grid w-full grid-cols-4">
                        <TabsTrigger value="stato">Stato e voci</TabsTrigger>
                        <TabsTrigger value="emissione">Emissione</TabsTrigger>
                        <TabsTrigger value="crediti">Colori e crediti</TabsTrigger>
                        <TabsTrigger value="stampe">Stampe e altro</TabsTrigger>
                    </TabsList>

                    <!-- ══════════════════════ STATO E VOCI ══════════════════════ -->
                    <TabsContent value="stato" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
                        <section>
                            <h3 class="mb-2 flex items-center gap-2 font-bold text-slate-900 dark:text-slate-100">
                                <Gavel class="h-4 w-4 text-emerald-600" /> Bozza e Approvato
                            </h3>
                            <p>
                                L'interruttore in alto ha due stati, e la differenza non è estetica:
                            </p>
                            <ul class="ml-5 mt-2 list-disc space-y-1.5">
                                <li><strong>Bozza</strong> — il piano è ancora modificabile e <strong>non ha
                                    generato nessun movimento contabile</strong>. Puoi correggere capitoli,
                                    ricalcolare, aggiungere o togliere voci liberamente.</li>
                                <li><strong>Approvato</strong> — passa da qui solo dopo aver registrato i dati
                                    della delibera assembleare (data, numero di verbale opzionale, note). Il
                                    piano si "congela" e diventa possibile emettere le rate in contabilità.</li>
                            </ul>
                            <p class="mt-2 text-xs text-slate-500">
                                Tornare in Bozza dopo l'approvazione è possibile solo se <strong>nessuna
                                rata è stata ancora emessa</strong>. Se lo è, l'interruttore si blocca: prima
                                vanno annullate le emissioni con la freccia circolare che compare passando
                                sull'intestazione della colonna.
                            </p>
                        </section>

                        <div class="flex items-start gap-3 rounded-lg bg-amber-50 p-4 dark:bg-amber-900/20">
                            <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
                            <div class="space-y-1 text-xs text-amber-900 dark:text-amber-200">
                                <p><strong>«Disallineato: ricalcola!»</strong> significa che qualcuno ha
                                modificato un preventivo o una tabella millesimale dopo che questo piano è
                                stato generato. Le quote mostrate non corrispondono più ai dati attuali finché
                                non premi <strong>Ricalcola</strong>.</p>
                            </div>
                        </div>

                        <section>
                            <h3 class="mb-2 flex items-center gap-2 font-bold text-slate-900 dark:text-slate-100">
                                <RotateCw class="h-4 w-4 text-slate-500" /> Ricalcola / Sincronizza
                            </h3>
                            <p>
                                Rigenera le quote di ogni condòmino in base ai millesimi e ai preventivi
                                <strong>attuali</strong>. Il pulsante cambia nome in <strong>Sincronizza</strong>
                                quando esistono voci di spesa "orfane" (scoperte da un'altra pagina) che questo
                                piano può includere: in quel caso ti viene chiesto quali aggiungere.
                            </p>
                            <p class="mt-2">
                                È <strong>bloccato</strong> in due casi, e per lo stesso motivo — proteggere
                                movimenti già scritti: se sono già stati registrati incassi, o se qualche rata
                                è già stata emessa in contabilità. Il messaggio sul pulsante dice quale dei due.
                            </p>
                        </section>

                        <div class="flex items-start gap-3 rounded-lg bg-amber-50 p-4 dark:bg-amber-900/20">
                            <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
                            <div class="space-y-2 text-xs text-amber-900 dark:text-amber-200">
                                <p><strong>Se «Ricalcola» trova qualcosa che non sa gestire da solo</strong>,
                                si ferma prima di scrivere niente e apre un pannello con l'elenco esatto dei
                                problemi. Sono di due tipi, e le conseguenze sono diverse se procedi comunque:</p>
                                <p>— <strong>importo non ripartibile</strong> (manca il soggetto o la tabella
                                millesimale su una voce): quell'importo <strong>non entra</strong> nel piano;</p>
                                <p>— <strong>unità senza millesimo</strong> in una tabella: quell'unità
                                <strong>sparisce</strong> dal piano e la sua quota viene ridistribuita sugli
                                altri condòmini.</p>
                                <p>Il pannello propone un link diretto a dove si corregge ciascun problema
                                (Anagrafiche o Millesimi). Per procedere comunque devi scrivere una nota di
                                almeno 10 caratteri su cosa hai deciso — resta registrata sul piano ed è quella
                                che vedi nel box «Questo piano rate contiene quote non assegnate» in cima alla
                                pagina.</p>
                            </div>
                        </div>

                        <section>
                            <h3 class="mb-2 flex items-center gap-2 font-bold text-slate-900 dark:text-slate-100">
                                <ArrowRightLeft class="h-4 w-4 text-indigo-600" /> Sposta spesa
                            </h3>
                            <p>
                                Sposta quanto <strong>questo piano</strong> finanzia di un capitolo verso un
                                altro — non il preventivo della voce, che resta quello che era. Serve per
                                un'emergenza: il fabbro va pagato oggi, e sposti la copertura da una voce che
                                aveva margine (es. «Pulizie») a quella nuova («Cancello»), senza ricalcolare
                                tutto il piano o aspettare la prossima emissione.
                            </p>
                            <p class="mt-2">
                                Non puoi spostare più di quanto la voce sorgente ha <strong>davvero
                                disponibile</strong>: se ha già fatture registrate, il margine si riduce di
                                quell'importo, e il messaggio d'errore te lo dice in euro — non solo il pivot
                                di questo piano, anche lo speso reale del libro giornale.
                            </p>
                        </section>

                        <div class="flex items-start gap-3 rounded-lg bg-amber-50 p-4 dark:bg-amber-900/20">
                            <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
                            <div class="space-y-1 text-xs text-amber-900 dark:text-amber-200">
                                <p><strong>Il recupero non è automatico.</strong> La voce che ha ceduto fondi
                                resta scoperta di quell'importo — nessun «conguaglio di fine anno» la sistema
                                da sola, quella funzione non esiste ancora nel programma. Il residuo compare
                                come voce da finanziare la prossima volta che ricalcoli questo piano o ne crei
                                uno nuovo, con l'etichetta <strong>«Da Sposta Spesa»</strong> che ricorda da
                                dove viene. Se non generi mai quel piano successivo, quel denaro non verrà
                                mai richiesto a nessuno.</p>
                            </div>
                        </div>

                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">La sezione «Copertura spese»</h3>
                            <p>Ogni voce inclusa nel piano porta un'etichetta che ne descrive lo stato:</p>
                            <ul class="ml-5 mt-2 list-disc space-y-1.5 text-xs">
                                <li><strong>Gruppo</strong> — è un capitolo che raggruppa più sottoconti.</li>
                                <li><strong>Integra</strong> — ha ricevuto fondi da un'altra voce con «Sposta
                                    spesa», e li ha ancora: uno storno completo spegne questa etichetta.</li>
                                <li><strong>Parziale</strong> — l'importo incluso è inferiore al preventivo
                                    originale della voce.</li>
                                <li><strong>Standard</strong> — nessuna delle precedenti: la voce entra per
                                    l'intero preventivo.</li>
                            </ul>
                            <p class="mt-2">
                                Il cestino accanto a ogni voce la rimuove dal piano — e <strong>ricalcola
                                immediatamente</strong> le rate di tutti i condòmini. È disabilitato se il
                                piano è già approvato o se ci sono incassi registrati; e se la voce ha un
                                <strong>saldo netto</strong> diverso da zero verso altre voci di questo piano
                                (ha ceduto o ricevuto con «Sposta spesa» più di quanto le sia poi tornato
                                indietro), va prima riportata a zero con lo <strong>«Storna»</strong> —
                                l'icona dell'orologio accanto all'importo apre lo storico dei movimenti, e
                                ognuno ha il proprio pulsante per restituire esattamente quella cifra. La voce
                                rimossa torna disponibile fra le «orfane» e si può reintrodurre in seguito con
                                «Sincronizza».
                            </p>
                        </section>

                        <p class="text-xs italic text-slate-400">
                            Se apri un piano molto vecchio può comparire da solo un avviso «Aggiornamento
                            necessario»: è stato generato con una versione precedente del motore e va
                            rigenerato per abilitare l'emissione. Non cambia nessun importo, aggiunge solo i
                            dettagli di calcolo che le versioni più recenti registrano per trasparenza.
                        </p>
                    </TabsContent>

                    <!-- ══════════════════════ EMISSIONE ══════════════════════ -->
                    <TabsContent value="emissione" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
                        <section>
                            <h3 class="mb-2 flex items-center gap-2 font-bold text-slate-900 dark:text-slate-100">
                                <Wallet class="h-4 w-4 text-emerald-600" /> Selezionare ed emettere
                            </h3>
                            <p>
                                Con il piano <strong>Approvato</strong>, spunta le rate da emettere (le
                                caselle compaiono nell'intestazione di ogni colonna) oppure usa <strong>«Seleziona
                                tutte»</strong>. Solo le rate non ancora emesse sono selezionabili. Il pulsante
                                <strong>«Emetti»</strong> apre un modulo dove scegli la data di registrazione,
                                una causale personalizzata (opzionale — altrimenti il sistema usa una dicitura
                                standard) e se rendere subito visibili le rate ai condòmini.
                            </p>
                        </section>

                        <div class="flex items-start gap-3 rounded-lg bg-slate-50 p-4 dark:bg-slate-900/40">
                            <Info class="mt-0.5 h-5 w-5 shrink-0 text-indigo-500" />
                            <div class="space-y-1 text-xs text-slate-600 dark:text-slate-300">
                                <p><strong>Emissione «silenziosa».</strong> Disattiva «Rendi visibile e invia
                                notifiche» se prima devi caricare a mano dei pagamenti pregressi (es. un
                                allineamento da un vecchio Excel): le rate vengono generate in contabilità ma
                                restano nascoste ai condòmini finché non premi <strong>«Pubblica nascoste»</strong>,
                                che compare in toolbar quando ce n'è almeno una in questo stato e invia le
                                notifiche a quel punto.</p>
                            </div>
                        </div>

                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">Annullare un'emissione</h3>
                            <p>
                                Passa il mouse sull'intestazione di una rata già emessa: compare una freccia
                                circolare. Annulla la scrittura contabile e riporta la rata in Bozza — ma
                                <strong>si blocca se ci sono già incassi registrati</strong> su quella rata: va
                                prima rimosso l'incasso.
                            </p>
                        </section>

                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">Le cinque card in alto</h3>
                            <ul class="ml-5 list-disc space-y-1.5 text-xs">
                                <li><strong>Totale Piano</strong> — la somma di tutte le rate, a valore
                                    teorico.</li>
                                <li><strong>Da Incassare</strong> — rate scadute e debiti pregressi.
                                    <strong>Non sottrae i crediti</strong>: un condòmino con un anticipo pesa
                                    comunque per intero finché la sua rata non è formalmente pagata o
                                    compensata.</li>
                                <li><strong>Incassato</strong> — il denaro davvero entrato in cassa, incluso
                                    quanto versato in eccedenza.</li>
                                <li><strong>Crediti (Anticipi)</strong> — vedi la scheda «Colori e crediti»:
                                    somma sia i saldi iniziali negativi sia gli strapagamenti.</li>
                                <li><strong>Saldo Netto</strong> — quanto resta dovuto (rosso) o quanto il
                                    condominio deve ai condòmini nel complesso (blu), al netto di tutto.</li>
                            </ul>
                        </section>
                    </TabsContent>

                    <!-- ══════════════════════ COLORI E CREDITI ══════════════════════ -->
                    <TabsContent value="crediti" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
                        <section>
                            <h3 class="mb-3 font-bold text-slate-900 dark:text-slate-100">Cosa significa il colore di ogni rata</h3>
                            <ul class="space-y-2.5">
                                <li class="flex items-start gap-2.5">
                                    <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded border border-gray-200 bg-white text-gray-400"><Clock class="h-3 w-3" /></span>
                                    <span><strong class="text-slate-800 dark:text-slate-100">In attesa</strong> — non ancora scaduta, nulla versato.</span>
                                </li>
                                <li class="flex items-start gap-2.5">
                                    <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded border border-red-300 bg-white text-red-600"><AlertTriangle class="h-3 w-3" /></span>
                                    <span><strong class="text-slate-800 dark:text-slate-100">Scaduta</strong> — la data è passata e non risulta pagata.</span>
                                </li>
                                <li class="flex items-start gap-2.5">
                                    <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded border border-amber-300 bg-amber-50 text-amber-700"><PieChart class="h-3 w-3" /></span>
                                    <span><strong class="text-slate-800 dark:text-slate-100">Parziale</strong> — è stato versato qualcosa, ma meno del dovuto.</span>
                                </li>
                                <li class="flex items-start gap-2.5">
                                    <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded border border-emerald-300 bg-emerald-50 text-emerald-700"><CheckCircle2 class="h-3 w-3" /></span>
                                    <span><strong class="text-slate-800 dark:text-slate-100">Saldata</strong> — pagata per l'esatto importo dovuto.</span>
                                </li>
                                <li class="flex items-start gap-2.5">
                                    <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded border border-blue-300 bg-blue-50 text-blue-700"><Coins class="h-3 w-3" /></span>
                                    <span><strong class="text-slate-800 dark:text-slate-100">Credito</strong> — vedi sotto: sono <strong>due casi diversi</strong> con lo stesso colore.</span>
                                </li>
                                <li class="flex items-start gap-2.5">
                                    <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded border border-gray-200 bg-gray-50 text-gray-400"><Ban class="h-3 w-3" /></span>
                                    <span><strong class="text-slate-800 dark:text-slate-100">Annullata</strong> — l'emissione è stata annullata, la rata non conta più.</span>
                                </li>
                            </ul>
                        </section>

                        <div class="flex items-start gap-3 rounded-lg bg-blue-50 p-4 dark:bg-blue-900/20">
                            <Coins class="mt-0.5 h-5 w-5 shrink-0 text-blue-600" />
                            <div class="space-y-2 text-xs text-blue-900 dark:text-blue-200">
                                <p><strong>Il credito ha due origini, non una.</strong></p>
                                <p><strong>1. Saldo iniziale o anticipo</strong> — una quota registrata a
                                importo negativo fin dall'origine: un pregresso a favore del condòmino.</p>
                                <p><strong>2. Strapagamento</strong> — un condòmino che ha versato <strong>più
                                della sua quota</strong>: la rata risulta comunque «pagata» (il dovuto è
                                coperto), ma l'eccedenza resta a suo credito e compare qui, nella card
                                «Crediti (Anticipi)» e nel totale di riga. Riconosciuto dalla beta.73 — prima
                                la pagina vedeva solo il primo caso, e un'eccedenza versata restava invisibile
                                sia nel totale crediti sia nell'incassato dichiarato.</p>
                                <p class="text-blue-700/80 dark:text-blue-300/80">In entrambi i casi il credito
                                si spende con «Usa credito» al prossimo incasso di quel condòmino.</p>
                            </div>
                        </div>

                        <section>
                            <h3 class="mb-2 flex items-center gap-2 font-bold text-slate-900 dark:text-slate-100">
                                <ReceiptText class="h-4 w-4 text-indigo-500" /> Spaccato finanziario
                            </h3>
                            <p>
                                Dal menu con i tre puntini a fine riga, apre un pannello con il dettaglio
                                completo di quel condòmino: la quota ordinaria e, se presenti, i debiti o
                                crediti pregressi che compongono ogni singola rata — gli stessi numeri che
                                vedi al passaggio del mouse su ogni rata, qui riuniti in una vista sola.
                            </p>
                        </section>

                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">I due puntini colorati sull'angolo di una rata</h3>
                            <p class="text-xs">
                                Quando una rata combina più componenti (una quota ordinaria e un pregresso),
                                un puntino <span class="font-semibold text-red-600">rosso</span> segnala un
                                debito residuo, uno <span class="font-semibold text-blue-600">blu</span> un
                                credito residuo, ed entrambi insieme una compensazione parziale fra i due — il
                                dettaglio esatto è nel tooltip della rata.
                            </p>
                        </section>
                    </TabsContent>

                    <!-- ══════════════════════ STAMPE E ALTRO ══════════════════════ -->
                    <TabsContent value="stampe" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
                        <section>
                            <h3 class="mb-2 flex items-center gap-2 font-bold text-slate-900 dark:text-slate-100">
                                <Printer class="h-4 w-4 text-slate-600" /> Stampe PDF
                            </h3>
                            <p class="mb-2">Il menu «Stampe PDF» produce cinque documenti diversi:</p>
                            <ul class="ml-5 list-disc space-y-1.5 text-xs">
                                <li><strong>Scadenziario per condòmino</strong> — una riga per intestatario,
                                    con tutte le sue rate.</li>
                                <li><strong>Scadenziario per unità</strong> — una riga per immobile.</li>
                                <li><strong>Entrambi</strong> — i due prospetti in un unico PDF.</li>
                                <li><strong>Riparto per Tabella</strong> — le spese raggruppate per tabella
                                    millesimale, con i coefficienti usati.</li>
                                <li><strong>Riparto per Capitolo</strong> — le spese calcolate capitolo per
                                    capitolo, con l'importo esatto imputato a ciascuno.</li>
                            </ul>
                            <p class="mt-3">
                                Nel «Riparto per Capitolo» puoi trovare due colonne che compaiono
                                <strong>solo se servono</strong>, cioè solo nei condomìni che hanno saldi di
                                apertura o contributi già versati:
                            </p>
                            <ul class="ml-5 mt-1.5 list-disc space-y-1.5 text-xs">
                                <li><strong>Già versato</strong> — quanto l'unità aveva già corrisposto verso
                                    quelle voci, che viene scomputato dal dovuto. È in negativo, e segue
                                    l'unità e non la persona (art. 63 disp. att. c.c.). Le colonne dei
                                    capitoli restano al <strong>deliberato</strong>: la facciata continua a
                                    valere quanto ha approvato l'assemblea, e lo sconto si legge a parte.</li>
                                <li><strong>Saldi precedenti</strong> — i saldi delle gestioni chiuse, che non
                                    appartengono a nessuna voce del preventivo corrente.</li>
                            </ul>
                            <p class="mt-2">
                                Può comparire anche <strong>«Fuori riparto»</strong>: è denaro addebitato a un
                                soggetto che i millesimi non spiegano più — tipicamente un condòmino staccato
                                dall'unità dopo aver generato le rate. Se la vedi, quella riga merita un
                                controllo.
                            </p>
                        </section>

                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">«Per anagrafica» e «Per immobile»</h3>
                            <p>
                                Le due schede in alto raggruppano la stessa tabella in due modi: per
                                <strong>persona</strong> (utile quando un condòmino possiede più unità, o più
                                persone comproprietari di una) o per <strong>immobile</strong>. I totali di
                                riepilogo non cambiano, cambia solo come sono sommati per riga.
                            </p>
                        </section>

                        <section>
                            <h3 class="mb-2 flex items-center gap-2 font-bold text-slate-900 dark:text-slate-100">
                                <Lock class="h-4 w-4 text-slate-500" /> Il nome sottolineato di un condòmino
                            </h3>
                            <p>
                                È un collegamento diretto al suo <strong>estratto conto</strong>: storico
                                completo dei pagamenti e della situazione debitoria, non solo di questo piano.
                            </p>
                        </section>

                        <div class="flex items-start gap-3 rounded-lg bg-amber-50 p-4 dark:bg-amber-900/20">
                            <Trash2 class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
                            <div class="space-y-1 text-xs text-amber-900 dark:text-amber-200">
                                <p><strong>Rimuovere una voce dal piano non è reversibile con un clic.</strong>
                                Il totale del piano diminuisce subito e <strong>tutte</strong> le rate di
                                <strong>tutti</strong> i condòmini vengono ricalcolate — anche se il difetto
                                riguardava una sola unità. Se la voce era un gruppo, tutti i suoi sottoconti
                                vengono rimossi insieme a lei.</p>
                            </div>
                        </div>
                    </TabsContent>
                </Tabs>
            </div>
        </SheetContent>
    </Sheet>
</template>
