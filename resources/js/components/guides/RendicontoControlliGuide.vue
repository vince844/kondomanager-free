<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La guida della scheda «Controlli» del rendiconto, nella finestra che si apre di lato: che cosa sono i controlli e che cosa non verificano, gli esiti,
 * i quindici controlli, il saldo della banca (le due cifre che confronta: quella registrata nel programma e quella che dice la banca, scritta dall'
 * amministratore nella nota sintetica), le possibili cause di una differenza con la correzione consigliata, «Ignora» con la nota, le registrazioni oltre
 * il confine dell'esercizio e la stampa. Ogni frase dice ciò che la pagina e il server fanno oggi.
 */
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { CalendarRange, ChevronRight, CircleHelp, EyeOff, Landmark, ListChecks, Printer, Search, Signpost } from 'lucide-vue-next';

defineProps<{ open: boolean }>();
defineEmits(['update:open']);
</script>

<template>
    <Sheet :open="open" @update:open="$emit('update:open', $event)">
        <SheetContent class="sm:max-w-2xl overflow-y-auto w-full sm:w-[600px] p-0" data-testid="guida-rendiconto-controlli">
            <div class="px-6 py-8">
                <SheetHeader class="mb-8">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="p-2 bg-blue-100 text-blue-700 rounded-lg dark:bg-blue-900 dark:text-blue-300">
                            <ListChecks class="w-6 h-6" />
                        </div>
                        <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: i controlli del rendiconto</SheetTitle>
                    </div>
                    <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
                        Quindici controlli con i numeri del programma, il saldo della banca con le possibili cause di una differenza, e le registrazioni oltre
                        il confine dell'esercizio.
                    </SheetDescription>
                </SheetHeader>

                <div class="space-y-8 text-sm text-slate-700 dark:text-slate-300">
                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><CircleHelp class="w-4 h-4" /> Che cosa sono</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Uguaglianze e conteggi con i numeri del programma, non un semaforo. Ogni riga ha il nome del controllo, l'esito, le due cifre e che cosa confronta, e una nota che dice che cosa ha trovato.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Verificano che i numeri della contabilità tornino fra loro e, se scrivi nella nota sintetica il saldo che dice la banca, che tornino con quel saldo. Non verificano che ogni fattura e ogni entrata sia stata registrata.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Ogni numero viene dalla scheda che lo mostra: i controlli confrontano, non calcolano cifre loro. Il programma non dice mai che il rendiconto va bene.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Signpost class="w-4 h-4" /> Gli esiti</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«torna»: le cifre coincidono.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«non torna»: due cifre della contabilità che dovrebbero coincidere non coincidono, e la riga del controllo dice perché.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«da leggere»: il controllo non può dire da solo se le cifre vanno bene. Lo spieghi tu, in assemblea e nella nota sintetica.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«non calcolato»: il confronto non si fa, e la riga dice perché. Per esempio il controllo della banca, finché il saldo che dice la banca non è scritto nella nota sintetica.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Quando c'è qualcosa da fare, sotto la riga c'è «Come rimediare:», con il collegamento alla pagina dove si fa.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Un controllo che non torna o è da leggere ha il suo fatto nella «Nota sintetica», nella parte «La gestione dell'anno»: con la tua spiegazione si stampa. Il controllo della banca no: la sua spiegazione si scrive sotto il saldo della banca, accanto alle cifre, e si stampa con loro.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><ListChecks class="w-4 h-4" /> I quindici controlli</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>Della situazione patrimoniale e della cassa</strong>: «Partita doppia», «Quadratura patrimoniale», «Raccordo fra cassa e competenza», «Vincoli e casse fondo», «Liquidità e cassa», «Casse reali sotto zero».</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>Fra le schede</strong>: «Registrazioni oltre il confine dell'esercizio», «I costi tornano con il riepilogo», «I crediti tornano con le posizioni», «Voci e gestione delle fatture».</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>Del conguaglio per persona</strong>: «Il conguaglio divide le spese dell'esercizio», «Ogni unità ha la sua parte», «Le quote tornano con il riparto», «Le spese personali tornano con le anticipazioni». Questa scheda li calcola sempre; nella «Sintesi» di un condominio grande possono essere «non calcolato».</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>Della banca</strong>: «Il saldo della banca e la contabilità».</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Nella «Sintesi» c'è l'esito di tutti, e un clic sul nome porta qui, al suo controllo.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Landmark class="w-4 h-4" /> Il saldo della banca</h3>
                        <p class="mb-3">
                            «Il saldo della banca e la contabilità» confronta, per ogni conto in banca e alla stessa data, due cifre:
                        </p>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>Il saldo registrato nel programma</strong>: quello che risulta dai movimenti scritti sul conto fino a quella data, gli stessi movimenti che vedi nel registro di contabilità e nella scheda «Cassa». Con i fondi, che stanno sullo stesso conto corrente, la riga dice le due parti: sul conto e nei fondi.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>Il saldo che dice la banca</strong>, scritto sull'estratto conto della banca che la banca ti manda: su carta, in PDF o nella banca online. Il programma non legge i documenti della banca: quel saldo lo scrivi tu nella «Nota sintetica», con la sua data. Accanto a «non calcolato», «Scrivi il saldo della banca» apre la nota sul campo del saldo di quel conto.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Se le due cifre coincidono, il saldo registrato corrisponde a quello della banca. Se sono diverse, può mancare un movimento, o essercene uno da correggere, o la banca può non averne ancora uno: il controllo propone dove cercare.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Finché il saldo che dice la banca non è scritto, il controllo è «non calcolato». Senza un conto in banca torna: non c'è niente da confrontare. Con una differenza è «da leggere», non «non torna».</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>L'estratto conto della banca non è l'estratto conto di un condòmino, cioè la sua posizione: quello si apre dal nome della persona, per esempio nella scheda «Condòmini».</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Search class="w-4 h-4" /> Le possibili cause di una differenza</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Sotto ogni conto con una differenza, «Può dipendere da» e le cause che il programma trova nei movimenti del conto: un movimento registrato che la banca non aveva ancora; un movimento che sembra registrato due volte; un movimento con lo stesso importo su un altro conto; le spese della banca non registrate; un movimento con la data dopo quella del saldo della banca; con più conti in banca, i fondi aperti con un saldo loro.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Di ogni tipo al più tre cause, le più vicine alla data del saldo della banca. I movimenti stornati e gli storni non contano, e nemmeno i giroconti fra il conto e i suoi fondi: il denaro resta sullo stesso conto.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Accanto a ogni causa, la correzione consigliata: apre l'azione che il programma ha già, su quel movimento o già compilata. Per esempio «Registra la spesa», «Modifica la data del pagamento», «Storna l'incasso registrato due volte», «Apri la registrazione». La confermi tu: il programma non corregge niente da solo.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Se il programma non trova una causa con quell'importo, la riga lo dice: confronta l'estratto conto della banca con il registro di contabilità, riga per riga, con «Apri il registro di contabilità».</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><EyeOff class="w-4 h-4" /> Ignora, con una nota</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«Ignora» chiede perché, per esempio «assegno non ancora incassato». La nota è obbligatoria; «Ignora, con questa nota» la scrive.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>La causa ignorata non si propone più. Resta scritta accanto al controllo, con il giorno, chi l'ha ignorata e la nota; «Riapri» la rimette fra le cause.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Le cause ignorate e riaperte restano anche nel registro della nota sintetica.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><CalendarRange class="w-4 h-4" /> Oltre il confine dell'esercizio</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«Registrazioni oltre il confine dell'esercizio»: le registrazioni con la data del fatto in un esercizio e scritte in un altro, nei due versi. Verso A, scritte qui con la data fuori; verso B, con la data di questo esercizio, scritte in un altro.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>In rosso quelle che cambiano che cosa il riepilogo per competenza conta; da leggere quelle che spostano solo denaro oltre il confine.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Il programma non sposta le registrazioni da un esercizio all'altro: una registrazione va nell'esercizio aperto quando la scrivi. Per questo ce ne sono quasi ogni anno, e l'elenco dice dove stanno. Da qui non si corregge niente.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>La tabella per tipo, con un clic su un tipo per filtrare; i filtri del verso e del colore; l'elenco a pagine. «Apri nel Libro Giornale» apre la registrazione nel giornale dell'esercizio in cui sta.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Printer class="w-4 h-4" /> La stampa</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«Stampa», in alto, fa il foglio dei controlli. Nel fascicolo i controlli sono l'ultima parte, e il frontespizio ha il loro esito.</span></li>
                            </ul>
                        </div>
                    </section>
                </div>
            </div>
        </SheetContent>
    </Sheet>
</template>
