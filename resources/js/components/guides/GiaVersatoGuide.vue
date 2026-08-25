<script setup lang="ts">
/**
 * La guida in-app dell'interruttore «già versato» e dell'elenco che apre.
 *
 * ## Perché esiste (beta.70)
 *
 * ⚠️ **Su domanda di Vincenzo** — *«abbiamo spiegato nella guida a cosa serve questa opzione?»*.
 * La risposta, misurata su tutte e diciannove le guide del progetto, era **no**: `SaldiGuide` la
 * nomina una volta di sfuggita parlando d'altro, la pagina del piano dei conti ha due guide che non
 * la citano, la pagina «Già versato» non ha nessun pannello, e `PianoRateGuide` non la nomina
 * benché sia là che l'effetto si vede.
 *
 * L'unica spiegazione esistente era la riga sotto l'interruttore. Ma quell'interruttore apre una
 * funzione intera — una pagina, un elenco per unità, un modale — e cambia il risultato del riparto.
 *
 * ## Le due cose che nessuno poteva sapere
 *
 * Sono il motivo per cui questa guida non è un adempimento:
 *
 * 1. **La copertura è per immobile, non per soggetto.** Su una voce ripartita fra proprietario e
 *    inquilino il versamento viene tolto dal lordo dell'unità **prima** della spaccatura fra i due:
 *    un versamento del solo proprietario finisce per scontare anche l'inquilino. È una decisione
 *    presa e dichiarata (D8 in `docs/fondo_accantonato_e_quadratura_sp.md`, «segnalare, non
 *    correggere»), non un difetto — e va detta a chi la subisce.
 * 2. **La quota non scende mai sotto zero.** Chi ha versato più della sua parte non ottiene un
 *    credito automatico: l'eccedenza viene rilevata e finisce come attività nell'Inbox, con
 *    l'importo per unità.
 *
 * ## Aggiornata nella beta.75
 *
 * Aggiunta la scheda «Le due scelte» e agganciata la guida anche alla pagina di **dettaglio**
 * (`ContributiEdit.vue`), dove prima non c'era: la guida esisteva solo sull'elenco e sul piano
 * dei conti, mentre le due decisioni che contano — natura del versamento e stato della liquidità —
 * si prendono proprio lì. La terza opzione di liquidità («già nel saldo di apertura») nasce con
 * questa beta: senza di lei chi apriva la cassa dall'estratto conto si vedeva contare i soldi due
 * volte, e la guida non aveva modo di avvisarlo.
 *
 * ⚠️ Ogni affermazione di questo file è stata verificata sul codice il 22/08/2026, in
 * `CalcoloQuoteService::nettingGiaVersato()`, `ContributoVersatoController`, `ContoController` e
 * nella migrazione che ha introdotto la colonna. Se cambia uno di quei comportamenti, questo file
 * va corretto insieme.
 */
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { AlertTriangle, HandCoins, Info, Users } from 'lucide-vue-next';

defineProps<{ open: boolean }>();
defineEmits(['update:open']);
</script>

<template>
    <Sheet :open="open" @update:open="$emit('update:open', $event)">
        <SheetContent class="w-full overflow-y-auto p-0 sm:w-[600px] sm:max-w-2xl">
            <div class="px-6 py-8">
                <SheetHeader class="mb-8">
                    <div class="mb-2 flex items-center gap-3">
                        <div class="rounded-lg bg-teal-100 p-2 text-teal-700 dark:bg-teal-900 dark:text-teal-300">
                            <HandCoins class="h-6 w-6" />
                        </div>
                        <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: già versato</SheetTitle>
                    </div>
                    <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
                        A cosa serve l'interruttore, cosa cambia nel riparto, e due comportamenti
                        che è meglio conoscere prima di usarlo.
                    </SheetDescription>
                </SheetHeader>

                <Tabs default-value="cosa" class="w-full">
                    <TabsList class="mb-6 grid w-full grid-cols-3">
                        <TabsTrigger value="cosa">A cosa serve</TabsTrigger>
                        <TabsTrigger value="riparto">Nel riparto</TabsTrigger>
                        <TabsTrigger value="decisioni">Le due scelte</TabsTrigger>
                        <TabsTrigger value="attenzione">Da sapere</TabsTrigger>
                    </TabsList>

                    <!-- ── A COSA SERVE ────────────────────────────────────────── -->
                    <TabsContent value="cosa" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
                        <section>
                            <h3 class="mb-3 flex items-center gap-2 font-bold text-slate-900 dark:text-slate-100">
                                <Info class="h-4 w-4 text-teal-600" /> Il caso che risolve
                            </h3>
                            <p>
                                Stai preventivando una spesa che i condòmini hanno <strong>già cominciato a
                                pagare</strong> prima che questa contabilità esistesse — un lavoro deliberato
                                l'anno scorso con acconti già raccolti, un fondo messo da parte a mano, una
                                colletta gestita col vecchio programma.
                            </p>
                            <p class="mt-2">
                                Se metti la spesa a preventivo senza dire niente, il riparto la chiede
                                <strong>tutta un'altra volta</strong>. Se non la metti, il preventivo non
                                quadra con il rendiconto. L'interruttore serve a dire la terza cosa: la spesa
                                è quella, ma una parte è già in cassa.
                            </p>
                        </section>

                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">Come si usa, in tre passi</h3>
                            <ol class="ml-5 list-decimal space-y-1.5">
                                <li>Accendi l'interruttore sulla voce di spesa.</li>
                                <li>La voce compare nell'elenco <strong>«Già versato»</strong>, dove registri
                                    quanto ha versato <strong>ciascuna unità</strong>.</li>
                                <li>Generi il piano rate: a ogni unità viene chiesto solo il residuo.</li>
                            </ol>
                            <p class="mt-2 text-xs text-slate-500">
                                Si registra per <strong>unità immobiliare</strong>, non per persona: è il
                                dettaglio da cui discende tutto il resto di questa guida.
                            </p>
                        </section>

                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">Non è un saldo iniziale</h3>
                            <p>
                                I saldi iniziali sono la <strong>fotografia complessiva</strong> del dare e
                                avere di un'unità quando la contabilità comincia. Il già versato è legato a
                                <strong>una voce di spesa precisa</strong>, e serve a scontare quella e solo
                                quella. Se hai già caricato i saldi iniziali, quella cifra è dentro il saldo:
                                registrarla anche qui la conterebbe due volte.
                            </p>
                        </section>

                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">Un capitolo non può averlo</h3>
                            <p>
                                L'interruttore vale solo sulle voci che portano un importo. Su un capitolo —
                                che raggruppa e basta — viene spento automaticamente: il denaro si registra
                                dove sta la spesa.
                            </p>
                        </section>
                    </TabsContent>

                    <!-- ── NEL RIPARTO ─────────────────────────────────────────── -->
                    <TabsContent value="riparto" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">In che ordine avvengono le cose</h3>
                            <p>
                                Il motore prima <strong>ripartisce la spesa intera</strong> secondo la tabella
                                millesimale, poi <strong>sottrae</strong> a ciascuna unità quello che ha già
                                versato. Non è la stessa cosa che ripartire il residuo: la quota di ognuno
                                resta quella che gli spetta, e lo sconto è personale.
                            </p>
                            <p class="mt-2 text-xs text-slate-500">
                                Un esempio: spesa € 10.000,00, due unità a 500 millesimi ciascuna, una ha già
                                versato € 3.000,00. Le quote restano € 5.000,00 a testa, e a chi ha versato
                                viene chiesto € 2.000,00.
                            </p>
                        </section>

                        <div class="flex items-start gap-3 rounded-lg bg-amber-50 p-4 dark:bg-amber-900/20">
                            <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
                            <div class="space-y-2 text-xs text-amber-900 dark:text-amber-200">
                                <p>
                                    <strong>La quota non scende mai sotto zero.</strong> Se un'unità ha versato
                                    più della sua parte, l'eccedenza <strong>non diventa un credito
                                    automatico</strong>: le viene chiesto zero, e la differenza ti arriva come
                                    attività nell'Inbox, con l'importo unità per unità.
                                </p>
                                <p>
                                    È denaro dei condòmini che sta da qualche parte: va deciso cosa farne —
                                    restituirlo, o registrarlo come credito su un'altra gestione — e il
                                    programma non lo fa al posto tuo.
                                </p>
                            </div>
                        </div>

                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">Il preventivo non cambia</h3>
                            <p>
                                La voce resta a bilancio per il suo importo pieno: è quello che la spesa costa.
                                A cambiare è solo <strong>quanto viene chiesto adesso</strong>. È il motivo per
                                cui il piano rate può sommare meno del preventivo senza che ci sia niente di
                                sbagliato.
                            </p>
                        </section>
                    </TabsContent>

                    <!-- ── DA SAPERE ───────────────────────────────────────────── -->
                    <TabsContent value="decisioni" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
                        <p>
                            La pagina di dettaglio ti chiede <strong>due cose che il programma non può
                            dedurre</strong>: che natura hanno quei soldi, e dove si trovano oggi. Sbagliarle
                            non produce un errore — produce un bilancio che non torna, mesi dopo.
                        </p>

                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">1. Fondo deliberato o rate già riscosse?</h3>
                            <p class="mb-2">
                                <strong>Fondo deliberato:</strong> l'assemblea ha costituito un fondo per
                                quell'opera (art. 1135 c.c.). Le somme hanno un <strong>vincolo di
                                destinazione</strong> e non possono essere spese per altro senza una nuova
                                delibera. Il programma lo fa valere: non ti lascia appoggiarle su una cassa
                                liberamente utilizzabile per gli imprevisti.
                            </p>
                            <p>
                                <strong>Rate già riscosse:</strong> somme incassate e non ancora spese, senza
                                un fondo deliberato. Restano conguagliabili a fine gestione.
                            </p>
                            <div class="mt-3 flex items-start gap-3 rounded-lg bg-slate-50 p-4 dark:bg-slate-800/50">
                                <Info class="mt-0.5 h-5 w-5 shrink-0 text-slate-500" />
                                <p class="text-xs text-slate-600 dark:text-slate-400">
                                    La distinzione la stabilisce la <strong>delibera</strong>, non il software.
                                    Attribuire un vincolo che l'assemblea non ha deliberato — o ignorarne uno
                                    esistente — è un problema legale, non contabile.
                                </p>
                            </div>
                        </section>

                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">2. Dove sono quei soldi, oggi?</h3>
                            <p class="mb-3">
                                Il riparto non se ne occupa — a lui basta sapere quanto è già stato versato.
                                Ma quei soldi <strong>esistono anche fuori dal riparto</strong>, e la risposta
                                decide se il programma deve scriverli in cassa o no. Si risponde una volta sola.
                            </p>
                            <ul class="ml-5 list-disc space-y-2">
                                <li>
                                    <strong>Sono ancora fermi, mai spesi.</strong> Sono su un conto del
                                    condominio e il programma non li ha ancora visti: li accredita subito sulla
                                    cassa che scegli, come farebbe con un saldo di apertura.
                                </li>
                                <li>
                                    <strong>Sono fermi, e già nel saldo di apertura.</strong> Sono in banca, ma
                                    il saldo di apertura che hai inserito nella cassa <strong>li comprende
                                    già</strong> — è il caso di chi parte dall'estratto conto, cioè quasi
                                    sempre. Qui il programma registra solo il vincolo e <strong>non scrive
                                    nulla</strong>: accreditarli li conterebbe due volte.
                                </li>
                                <li>
                                    <strong>Sono già stati spesi come acconto.</strong> Sono usciti verso il
                                    fornitore prima di KondoManager. Non c'è liquidità da registrare, e il
                                    debito verso quel fornitore va verificato a mano quando registrerai la
                                    fattura: potrebbe essere già scontato dell'acconto, oppure no.
                                </li>
                            </ul>
                            <div class="mt-3 flex items-start gap-3 rounded-lg bg-amber-50 p-4 dark:bg-amber-900/20">
                                <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
                                <p class="text-xs text-amber-900 dark:text-amber-200">
                                    <strong>La domanda da farsi è una sola:</strong> il saldo che ho scritto
                                    aprendo la cassa comprendeva già questi soldi? Se l'hai preso dall'estratto
                                    conto, la risposta è sì — e la scelta giusta è la seconda.
                                </p>
                            </div>
                        </section>
                    </TabsContent>

                    <TabsContent value="attenzione" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
                        <div class="flex items-start gap-3 rounded-lg bg-amber-50 p-4 dark:bg-amber-900/20">
                            <Users class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
                            <div class="space-y-2 text-xs text-amber-900 dark:text-amber-200">
                                <p>
                                    <strong>Su una voce divisa fra proprietario e inquilino, lo sconto vale per
                                    tutti e due.</strong> Il versamento è registrato sull'<strong>unità</strong>,
                                    e viene tolto dal totale dell'unità <strong>prima</strong> che questo venga
                                    diviso fra i due ruoli.
                                </p>
                                <p>
                                    In pratica: se ha versato solo il proprietario, l'inquilino si trova la
                                    quota ridotta anche lui, in proporzione. Non è un errore di calcolo — è
                                    una conseguenza del fatto che il dato è per unità — ma se nel tuo caso i
                                    due versamenti vanno tenuti distinti, questa non è la strada.
                                </p>
                            </div>
                        </div>

                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">Spegnere l'interruttore non cancella niente</h3>
                            <p>
                                Se hai già registrato dei versamenti e poi spegni l'interruttore, la voce
                                <strong>resta comunque nell'elenco</strong> e i dati restano dove sono.
                                L'interruttore decide cosa <em>compare</em>, non cosa esiste: una voce con
                                versamenti registrati non sparisce mai per una casella tolta.
                            </p>
                        </section>

                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">Quando non serve</h3>
                            <ul class="ml-5 list-disc space-y-1.5">
                                <li>Se la spesa non è ancora stata pagata da nessuno: è un preventivo normale.</li>
                                <li>Se il denaro è su un <strong>fondo</strong> del condominio e non è stato
                                    versato per quella voce: là si usano i fondi e i giroconti.</li>
                                <li>Se stai caricando la situazione di partenza di un condominio che arriva da
                                    un altro gestionale: quelli sono i <strong>saldi iniziali</strong>.</li>
                            </ul>
                        </section>
                    </TabsContent>
                </Tabs>
            </div>
        </SheetContent>
    </Sheet>
</template>
