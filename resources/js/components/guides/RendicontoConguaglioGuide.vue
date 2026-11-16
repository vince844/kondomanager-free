<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La guida della scheda «Conguaglio» del rendiconto, nella finestra che si apre di lato: la proposta di conguaglio per persona e unità, come si legge una
 * riga, le gestioni e le uscite che nessuno paga con l'addebito a una persona, le stampe e i fogli per i condòmini (spiegati qui, dove si fanno), e il
 * conguaglio nelle altre schede. Ogni frase dice ciò che la pagina e il server fanno oggi.
 */
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { ChevronRight, Layers, Mail, Rows3, Scale, Waypoints } from 'lucide-vue-next';

defineProps<{ open: boolean }>();
defineEmits(['update:open']);
</script>

<template>
    <Sheet :open="open" @update:open="$emit('update:open', $event)">
        <SheetContent class="sm:max-w-2xl overflow-y-auto w-full sm:w-[600px] p-0" data-testid="guida-rendiconto-conguaglio">
            <div class="px-6 py-8">
                <SheetHeader class="mb-8">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="p-2 bg-blue-100 text-blue-700 rounded-lg dark:bg-blue-900 dark:text-blue-300">
                            <Scale class="w-6 h-6" />
                        </div>
                        <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: il conguaglio</SheetTitle>
                    </div>
                    <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
                        La proposta di conguaglio per persona e unità, le spese che nessuno paga, e il foglio di ogni persona da stampare o mandare per email.
                    </SheetDescription>
                </SheetHeader>

                <div class="space-y-8 text-sm text-slate-700 dark:text-slate-300">
                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Scale class="w-4 h-4" /> Una proposta</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Per persona e unità, in ogni gestione: le spese dell'esercizio che le toccano, più le spese personali non ancora chieste, meno le quote dei piani.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>È una proposta, da approvare dall'assemblea: il programma la calcola e la stampa, non la registra.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Per l'esercizio aperto è a oggi, e cambia a ogni registrazione; per uno finito è alla fine dell'esercizio. La riga in testa dice anche quando è stato calcolato.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Rows3 class="w-4 h-4" /> Come si legge una riga</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«Conguaglio» è «Spese dell'esercizio» più «Spese personali non chieste» meno «Quote dei piani».</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«Saldo finale» è la «Posizione sulle rate», come nell'estratto conto della persona, più i saldi «Fuori dalle rate», più il conguaglio.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Il segno più vuol dire da versare, il segno meno a credito.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Ogni spesa è divisa per competenza, fra chi era titolare nei giorni a cui si riferisce, con le tabelle e i millesimi della voce. Sotto l'unità ci sono i giorni di chi l'ha avuta per una parte dell'anno; con più ruoli sulla stessa unità, i giorni di ogni ruolo.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Ogni voce è divisa fino al centesimo, e i pochi centesimi che restano vanno a chi ne aveva la frazione più grande: per questo non c'è una riga di arrotondamenti.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Una persona con più unità ha anche la riga del suo totale.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Quando un piano dell'esercizio ha rate non ancora emesse, il saldo finale non le comprende: accanto c'è quanto si aggiunge quando il piano le emette.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«Non disponibile», con il perché, quando il programma non ha la cifra: i totali non contano quelle righe. Sotto la riga ci sono anche le note dei passaggi.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Le righe sono a pagine; «Il condominio», in fondo, ha i totali di tutte.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Layers class="w-4 h-4" /> Le gestioni e le uscite che nessuno paga</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Le righe sono divise per gestione. Per un'opera straordinaria ancora in corso la riga dice lo speso e il chiesto: il conguaglio si propone quando l'opera è finita, e senza la data di fine c'è «Scrivi la data di fine». Un'opera finita in un esercizio prima ha il conguaglio nel suo rendiconto; se qui ha spese scritte dopo la fine, c'è «Apri la gestione».</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Le uscite che nessuno paga»</strong>: le spese che il programma non ha potuto dividere, voce per voce, con il perché e che cosa fare. Il nome della voce apre il suo piano dei conti; «Apri il riepilogo» apre il riepilogo finanziario. Ci sono anche le spese fuori dai capitoli.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Le spese di giorni senza un titolare si possono addebitare a una persona: «Addebita a una persona» apre la finestra dell'addebito con quello che resta. Il programma propone la persona, l'importo e la causale; scegli tu e registri. Gli addebiti già fatti sono scritti accanto, con a chi e quanto.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Sotto la tabella, una riga dice che cosa diventano i crediti e i debiti verso i condòmini se l'assemblea approva e il conguaglio si registra.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Mail class="w-4 h-4" /> Le stampe e i fogli per i condòmini</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«Conguaglio per persona» stampa la proposta di tutte le persone. «Consuntivo per tabella» stampa, per ogni unità, i millesimi e la parte della spesa di ogni tabella.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>Il foglio di ogni persona</strong>: le sue unità, voce per voce, il conguaglio e il saldo, con la firma. «il suo foglio», sotto il nome, apre quello di una persona.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Per molti fogli insieme scegli «Tutte le persone», «Con il conguaglio a debito» o «Con un indirizzo email», e se vuoi «solo le persone di questa pagina». Poi «Scarica i fogli», in un archivio. La riga dice quante persone sono scelte; se sono troppe per un archivio lo dice, e scegli le persone di una pagina dell'elenco.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«Invia per email» manda il foglio di ogni persona scelta, in PDF, ai suoi indirizzi. Prima di inviare vedi l'anteprima: chi riceve il foglio, a quali indirizzi, e chi non ha un indirizzo, con il suo foglio da scaricare.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Accanto a ogni persona c'è l'ultimo invio del suo foglio, con «il PDF del foglio» partito. Un foglio non partito si rimanda con «Invia di nuovo, con le cifre di oggi».</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Le note dei passaggi e gli avvisi sono per te: stanno solo in questa scheda, e le stampe per i condòmini non li hanno.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Waypoints class="w-4 h-4" /> Il conguaglio nelle altre schede</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Quattro controlli leggono il conguaglio: «Il conguaglio divide le spese dell'esercizio», «Ogni unità ha la sua parte», «Le quote tornano con il riparto», «Le spese personali tornano con le anticipazioni». Sono nella scheda «Controlli».</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Nella «Sintesi» il riquadro «Conguaglio» ha la cifra del condominio. Quando registri l'approvazione del rendiconto, la Sintesi chiede se l'assemblea ha approvato anche lo stato di riparto, cioè questo conguaglio per persona.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Gli interessi e le spese addebitati a singole persone, quando sono versati, riducono le spese da dividere: il «Riepilogo finanziario» li mostra.</span></li>
                            </ul>
                        </div>
                    </section>
                </div>
            </div>
        </SheetContent>
    </Sheet>
</template>
