<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La guida della scheda «Riepilogo finanziario» del rendiconto, nella finestra che si apre di lato: le spese per competenza, le colonne, le entrate da
 * terzi, gli interessi e le spese versati dai condòmini e i contributi addebitati a singole persone (spiegati qui, dove hanno le loro righe), le quote,
 * ciò che sta fuori dai capitoli, le righe che legano le cifre, la stampa e il legame con la nota sintetica. Ogni frase dice ciò che la pagina e il server
 * fanno oggi.
 */
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { BookOpenCheck, ChevronRight, Columns3, Link2, Printer, ReceiptText, Users } from 'lucide-vue-next';

defineProps<{ open: boolean }>();
defineEmits(['update:open']);
</script>

<template>
    <Sheet :open="open" @update:open="$emit('update:open', $event)">
        <SheetContent class="sm:max-w-2xl overflow-y-auto w-full sm:w-[600px] p-0" data-testid="guida-rendiconto-riepilogo">
            <div class="px-6 py-8">
                <SheetHeader class="mb-8">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="p-2 bg-blue-100 text-blue-700 rounded-lg dark:bg-blue-900 dark:text-blue-300">
                            <BookOpenCheck class="w-6 h-6" />
                        </div>
                        <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: il riepilogo finanziario</SheetTitle>
                    </div>
                    <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
                        Le spese e le entrate dell'esercizio per capitolo e per voce, le quote dei condòmini e il risultato, con le righe che legano le cifre.
                    </SheetDescription>
                </SheetHeader>

                <div class="space-y-8 text-sm text-slate-700 dark:text-slate-300">
                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><BookOpenCheck class="w-4 h-4" /> Per competenza</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Le spese di questo esercizio, pagate o no, per gestione e per capitolo. Il consuntivo è quello del giornale; il pagato e il da pagare sono alla data scritta in testa.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Per l'esercizio aperto la data è oggi, e le cifre cambiano a ogni lettura; per uno finito è la sua data di fine.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Un clic sul nome di un capitolo apre le sue voci, e sotto le fatture e i movimenti, una riga ciascuno, con il pagato e il da pagare.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Per una gestione straordinaria c'è anche il cumulato dell'opera.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Columns3 class="w-4 h-4" /> Le colonne delle spese</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Preventivo»</strong>: l'importo della voce. Quando la gestione passa all'esercizio dopo, la voce porta di solito il preventivo nuovo, e la colonna dice «nel verbale dell'assemblea».</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Deliberato con le rate»</strong>: quanto i piani approvati dell'esercizio chiedono per la voce. Dice «non disponibile» quando il programma non ha il riparto dei piani, e «ricostruito» quando lo rifà dai piani; l'asterisco segna una voce divisa con gli importi di oggi. Una frase sotto le tabelle dice perché.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Consuntivo»</strong>: la spesa dell'esercizio. <strong>«Anno prima»</strong>: la spesa dell'esercizio prima; vuota, la frase in testa dice perché. <strong>«Scostamento»</strong>: il consuntivo meno il deliberato con le rate; per una gestione straordinaria non c'è.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Pagato»</strong> e <strong>«Da pagare»</strong>: delle fatture di questo esercizio. Il pagato di una fattura su più voci, pagata in parte, è diviso fra le voci in proporzione al loro costo. Sotto, le ritenute da versare e il pagato in più.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>I debiti verso i fornitori della «Situazione patrimoniale» sono di tutti gli esercizi, alla data: possono essere diversi dal da pagare di qui.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Users class="w-4 h-4" /> Le entrate e le quote</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Entrate da terzi»</strong>: il preventivo, l'incassato e l'anno prima. Riducono le spese, come il piano riduce già le quote. Accanto a un'entrata non ancora incassata, «Registra l'incasso».</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Interessi e spese versati dai condòmini»</strong>: gli interessi di mora e le spese addebitati a singole persone, quando sono versati, sulla loro voce. Non sono entrate da terzi: sono del condominio da quando sono versati, e riducono le spese da dividere.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Quote dei condòmini»</strong>, per gestione: «Quote ordinarie», «Quote straordinarie», «Esercizi precedenti». Quando ci sono, anche «Contributi addebitati a singole persone» e «Spese di una sola unità chieste a una persona». Queste due righe non hanno un deliberato.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Le colonne: «Deliberate», «Emesse», «Pagate», «Non pagate» con le scadute sotto, «Crediti sulle rate», «Credito riportato». Il credito riportato da un esercizio prima non ha ancora chiuso nessuna quota, e non è fra le pagate.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Le spese di una sola unità chieste con le rate stanno in una riga loro, dopo il totale delle spese: non sono di nessuna voce. Quelle non ancora chieste sono nella scheda «Conguaglio».</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><ReceiptText class="w-4 h-4" /> Fuori dai capitoli</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«Fatture contestate»: stanno fuori dai capitoli finché sono contestate.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«Commissioni senza voce»: non stanno in nessun capitolo. «Assegna la voce» apre l'elenco dove la voce si dà.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«Costi non classificati»: righe di costo senza voce.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Le fatture di un anno passato registrate come pregresse non sono costi di questo esercizio, tranne la parte non coperta dai saldi iniziali, se c'è.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Link2 class="w-4 h-4" /> Il risultato e le righe che legano le cifre</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Risultato»</strong>: le quote registrate in questo esercizio meno i costi.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Quando c'è, la rettifica di esercizi precedenti: La spesa di una sola unità contata due volte nelle rate di un esercizio chiuso si toglie in questo esercizio: non è una quota chiesta qui, quindi sta in una riga sua, e il risultato la conta.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>La prima riga sotto lega i costi del giornale alle spese per capitolo meno le entrate, più le commissioni senza voce, le fatture contestate e i costi non classificati. Se resta una differenza, la riga la dice: si guarda nel Libro Giornale. Il controllo «I costi tornano con il riepilogo», nella scheda «Controlli», rifà questa riga.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>La seconda lega le quote dei piani alle quote registrate: i contributi addebitati a singole persone, le rate emesse in un altro esercizio, i debiti riportati dentro le rate.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>La terza dice perché le quote pagate e i versamenti del registro di contabilità possono essere diversi: per esempio una compensazione non passa dalla cassa.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Le non pagate sono lorde, come in «Rate scadute»: i crediti sulle rate stanno accanto.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Printer class="w-4 h-4" /> La stampa e la nota</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Stampa»</strong>, in alto: «Riepilogo finanziario», per capitolo con le entrate da terzi, le quote e il risultato; «Con il dettaglio per spesa», con in più una riga per fattura o movimento.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Nella «Nota sintetica» il programma propone le cinque voci con lo scostamento più grande. La causa di ogni scostamento la scrivi tu, nella nota, e si stampa accanto alla cifra.</span></li>
                            </ul>
                        </div>
                    </section>
                </div>
            </div>
        </SheetContent>
    </Sheet>
</template>
