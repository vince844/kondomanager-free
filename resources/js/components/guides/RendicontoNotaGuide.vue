<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La guida della scheda «Nota sintetica» del rendiconto, nella finestra che si apre di lato: che cos'è e le sue cinque parti, i fatti che il programma
 * propone e che l'amministratore tiene, toglie o riscrive, le cause degli scostamenti e le spiegazioni dei controlli, il saldo che dice la banca (scritto
 * qui dall'amministratore, che il controllo della banca confronta con il saldo registrato), gli elenchi e la dichiarazione, la copia e «Genera la nota»,
 * il registro, e che cosa succede quando si salva, si ferma e si approva. Ogni frase dice ciò che la pagina e il server fanno oggi.
 */
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { ChevronRight, Copy, FileText, History, Landmark, ListChecks, MessageSquareText, Save, ScrollText } from 'lucide-vue-next';

defineProps<{ open: boolean }>();
defineEmits(['update:open']);
</script>

<template>
    <Sheet :open="open" @update:open="$emit('update:open', $event)">
        <SheetContent class="sm:max-w-2xl overflow-y-auto w-full sm:w-[600px] p-0" data-testid="guida-rendiconto-nota">
            <div class="px-6 py-8">
                <SheetHeader class="mb-8">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="p-2 bg-blue-100 text-blue-700 rounded-lg dark:bg-blue-900 dark:text-blue-300">
                            <FileText class="w-6 h-6" />
                        </div>
                        <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: la nota sintetica</SheetTitle>
                    </div>
                    <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
                        La nota sintetica esplicativa del rendiconto: i fatti che il programma propone, il tuo testo, gli elenchi, la dichiarazione e il saldo
                        che dice la banca.
                    </SheetDescription>
                </SheetHeader>

                <div class="space-y-8 text-sm text-slate-700 dark:text-slate-300">
                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><FileText class="w-4 h-4" /> Che cos'è</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>La nota sintetica esplicativa è una delle tre parti del rendiconto, con il registro di contabilità e il riepilogo finanziario: dice la gestione, i rapporti in corso e le questioni pendenti.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>La nota è tua. Il programma propone fatti presi dai conti, con la cifra e la scheda da cui vengono; il testo di ogni parte lo scrivi tu. Il programma non dice che la nota è completa: lo sai tu.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Cinque parti, sempre nello stesso ordine: «I criteri del rendiconto», «La gestione dell'anno», «I rapporti in corso», «Le questioni pendenti», «Altre informazioni».</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Nel fascicolo la nota si stampa subito dopo il frontespizio. «Stampa la nota», in fondo, la stampa da sola.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><ListChecks class="w-4 h-4" /> I fatti proposti</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Nessun fatto si stampa finché non lo tieni. Per ogni fatto: «Tieni», «Togli», o «Riscrivi» con le tue parole e «Usa la mia frase». «Rimetti fra i proposti» e «Torna alla frase del programma» tornano indietro.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Sotto ogni fatto la pagina dice se si stampa, e da quale scheda viene la cifra: un clic apre quella scheda.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«Tieni tutti i fatti proposti», in cima, e «Tieni tutti i fatti di questa parte» li tengono in un clic.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Un fatto tenuto si stampa con le cifre di oggi. Se l'hai riscritto e oggi il programma direbbe altro, la pagina lo dice, con la frase di oggi.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Un fatto tenuto che oggi il programma non propone più non si stampa, e la pagina lo dice. In ogni parte, «Che cosa il programma non propone in questa parte, e perché».</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>I fatti sono scritti per i condòmini, senza i nomi delle persone.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><MessageSquareText class="w-4 h-4" /> Le cause e le spiegazioni</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Il fatto delle voci con lo scostamento più grande, preso dal riepilogo finanziario, ha accanto a ogni voce il posto per la causa: si stampa accanto alla cifra.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Un controllo che non torna o è da leggere è un fatto: con la tua spiegazione si stampa, anche senza «Tieni»; senza, no. I controlli sono nella scheda «Controlli». Il controllo della banca non ha un fatto suo: lo spieghi sotto il saldo della banca.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>I testi dello studio: «Usa un testo dello studio…» lo aggiunge in fondo al campo, e lo puoi cambiare. «Salva come testo dello studio» lo conserva, per questo condominio o per tutti; lo può fare chi gestisce le impostazioni generali.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Landmark class="w-4 h-4" /> Il saldo della banca</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Nella parte «La gestione dell'anno», sotto «Il saldo secondo la banca (dal suo estratto conto)», c'è una riga per ogni conto in banca. Il programma non legge i documenti della banca: il saldo che leggi sull'estratto conto che ti manda la banca, di solito alla fine dell'esercizio, lo scrivi tu, nei campi «Data del saldo» e «Saldo secondo la banca».</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Dal controllo della banca, «Scrivi il saldo della banca» apre questa pagina sul campo del saldo di quel conto.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Accanto c'è il saldo registrato in KondoManager alla stessa data, quello dei movimenti scritti sul conto. Se le due cifre coincidono la pagina dice «Coincidono.»; se no, dice la differenza e da che parte è.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Con una differenza, scrivi la spiegazione: si stampa accanto alle cifre. È la spiegazione del controllo della banca: si scrive solo qui. «Le possibili cause, nei controlli» apre il controllo della banca, con le possibili cause, la correzione consigliata e «Ignora»; c'è dopo che hai salvato la nota.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Il saldo che scrivi si stampa anche senza «Tieni»: «Togli» per non stamparlo.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>L'estratto conto della banca non è l'estratto conto di un condòmino, cioè la sua posizione.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><ScrollText class="w-4 h-4" /> Gli elenchi e la dichiarazione</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Contratti, polizze e verifiche periodiche»</strong>, nella parte «I rapporti in corso»: una riga ciascuno, con «Aggiungi una riga». Sotto, i fornitori che hanno fatturato nell'esercizio, da mettere nell'elenco con «Aggiungi all'elenco»: è un aiuto, perché una fattura non dice che il contratto è in corso. Un contratto scaduto prima della fine dell'esercizio la pagina lo dice; la stampa no.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>I dipendenti: «Il condominio non ha dipendenti», o «Ci sono:» con chi e con quale contratto.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>La dichiarazione</strong>, nella parte «Le questioni pendenti»: per le cause in corso, le delibere impugnate e i sinistri aperti, «Non ce ne sono» o «Ci sono:», con quali. Il programma non sa delle cause: il silenzio non vuol dire che non ce ne sono.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Altri debiti certi»</strong>, che non sono fatture, con «Aggiungi un debito»: compaiono anche nella «Situazione patrimoniale», sotto la riga dei debiti, fuori dal giornale e dai totali.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Da decidere in assemblea»</strong>: le cose che scrivi tu, e quelle dei fatti tenuti. Con un'assemblea in preparazione che ha il punto del rendiconto di questo esercizio, la stampa mette al loro posto i punti del suo ordine del giorno.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Copy class="w-4 h-4" /> Copiare e generare</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«Copia dall'anno prima» e «Copia da un altro condominio…» portano i testi, gli elenchi e la dichiarazione: mai i fatti, che sono dei conti di quest'anno, e mai il saldo della banca. Prima vedi che cosa arriva e scegli, poi «Copia nella nota».</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Da un altro condominio i contratti arrivano senza importo e scadenza; i debiti, le cose da decidere e la dichiarazione sono di quel palazzo, e di serie non si copiano.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>La dichiarazione e i dipendenti copiati non si stampano finché non li confermi, con «Confermo la dichiarazione» e «Confermo».</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Genera la nota»</strong>, in un clic: tiene tutti i fatti che il programma propone oggi, non quelli che hai tolto, e copia dall'anno prima i testi delle parti vuote, i contratti e i debiti certi se la nota non ne ha. La dichiarazione e i dipendenti copiati restano da confermare. Non scrive niente di suo.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Dopo, la finestra dice che cosa manca ancora. È una tua scelta, e la nota resta tua: puoi fermarla così.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Con modifiche da salvare «Genera la nota» resta spento: parte dalla nota salvata.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><History class="w-4 h-4" /> Il registro</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Ogni «Genera la nota» lascia una riga nel registro: chi, quando, che cosa mancava, e la tua nota se l'hai scritta nella finestra. Anche le cause della differenza con la banca ignorate o riaperte nella scheda «Controlli» lasciano una riga.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«Il registro della nota», in cima, lo apre, la riga più recente prima. Le righe restano anche se cambi la nota dopo.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Save class="w-4 h-4" /> Salvare, fermare, approvare</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«Salva la nota», in fondo. Finché non salvi, la pagina tiene la bozza e in cima dice «Non ancora salvata.».</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>In cima, circa quante parole e quante pagine ha la nota che si stamperebbe.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«Da completare in questa nota» dice che cosa manca: puoi fermarla e approvarla lo stesso. Le finestre di «Ferma per l'assemblea» e dell'approvazione, nella «Sintesi», lo ripetono.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Quando la nota manca, il frontespizio del fascicolo lo dice ai condòmini.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«Ferma per l'assemblea» non genera la nota: conserva la nota com'è quel giorno. Cambiarla dopo non cambia la versione fermata.</span></li>
                            </ul>
                        </div>
                    </section>
                </div>
            </div>
        </SheetContent>
    </Sheet>
</template>
