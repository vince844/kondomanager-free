<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La guida della scheda «Sintesi» del rendiconto, nella finestra che si apre di lato: che cosa mostra, da dove vengono le cifre, il fascicolo, e le
 * versioni del rendiconto (fermarlo per l'assemblea, registrare l'approvazione, l'avviso dopo l'approvazione, eliminare una versione mai approvata),
 * che si spiegano qui perché il riquadro «Il rendiconto per l'assemblea» sta in questa scheda. I controlli, il conguaglio e la nota si dicono in breve,
 * con il rimando alla loro scheda. Ogni frase dice ciò che la pagina e il server fanno oggi.
 */
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Archive, ChevronRight, Eye, FileDown, History, Scale, Stamp, Trash2, Waypoints } from 'lucide-vue-next';

defineProps<{ open: boolean }>();
defineEmits(['update:open']);
</script>

<template>
    <Sheet :open="open" @update:open="$emit('update:open', $event)">
        <SheetContent class="sm:max-w-2xl overflow-y-auto w-full sm:w-[600px] p-0" data-testid="guida-rendiconto-sintesi">
            <div class="px-6 py-8">
                <SheetHeader class="mb-8">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="p-2 bg-blue-100 text-blue-700 rounded-lg dark:bg-blue-900 dark:text-blue-300">
                            <Scale class="w-6 h-6" />
                        </div>
                        <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: la sintesi del rendiconto</SheetTitle>
                    </div>
                    <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
                        Il rendiconto in una pagina, il fascicolo per l'assemblea, e le versioni: fermarle, approvarle, eliminarle.
                    </SheetDescription>
                </SheetHeader>

                <div class="space-y-8 text-sm text-slate-700 dark:text-slate-300">
                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Eye class="w-4 h-4" /> Che cosa mostra</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>Lo stato del rendiconto</strong>, sotto le schede: «Bozza: le cifre cambiano a ogni registrazione.», poi «Fermato per l'assemblea il» giorno, poi «Approvato il» giorno della delibera. La stessa riga è in ogni scheda; dalle altre schede «Le versioni nella Sintesi» porta qui.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Se un'assemblea ha respinto, rinviato, revocato o annullato una delibera che riguarda una versione del rendiconto (per esempio il rendiconto respinto), la scheda lo dice in testa con la frase della delibera e il collegamento all'assemblea. Sono frasi: nessun conto cambia da solo.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>I controlli</strong>: in una riga quanti tornano, quanti non tornano e quanti sono da leggere, poi un controllo per riga. Un clic sul nome apre quel controllo nella scheda «Controlli». Sotto, una riga fissa dice che cosa i controlli non verificano.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>Quattro riquadri</strong>: «Deliberato con le rate», con il preventivo sotto; «Costi dell'esercizio», con la percentuale e la sua base; «Quote pagate», su quelle emesse; «Liquidità al» giorno della lettura.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>Il riquadro «Conguaglio»</strong>: la cifra del condominio, con le spese, le spese personali non chieste e le quote dei piani. «Apri la scheda» porta al conguaglio. In un condominio grande la Sintesi non lo calcola, e il riquadro dice di aprire la scheda.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>Le fasce</strong>: le commissioni e gli storni senza voce e le fatture contestate, cioè le spese che non stanno in nessun capitolo. Accanto, «Assegna la voce», «Vedi l'elenco» o «Apri il riepilogo».</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Il prospetto della gestione»</strong>: il preventivo, il deliberato, le quote dei piani, le quote pagate, i costi, e il risultato: le quote registrate in questo esercizio meno i costi. Il preventivo: le spese previste, meno le entrate da terzi.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Rettifica di esercizi precedenti»</strong>, quando c'è: La spesa di una sola unità contata due volte nelle rate di un esercizio chiuso si toglie in questo esercizio: non è una quota chiesta qui, quindi sta in una riga sua, e il risultato la conta.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Waypoints class="w-4 h-4" /> Da dove vengono le cifre</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>La Sintesi non somma niente di suo: ogni cifra viene da un'altra scheda. Il deliberato, le quote, i costi, il risultato e le fasce dal «Riepilogo finanziario»; la liquidità dalla «Situazione patrimoniale»; l'esito dai «Controlli»; il riquadro del conguaglio dalla scheda «Conguaglio». Con più gestioni le cifre sono sommate.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>La liquidità è quella registrata nella contabilità, non il saldo che dice la banca. Se scrivi il saldo dell'estratto conto della banca nella «Nota sintetica», il controllo della banca confronta le due cifre.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Quando ci sono, i «Contributi addebitati a singole persone» hanno una riga loro nel prospetto: stanno fra le quote registrate, non fra le quote dei piani. Il «Riepilogo finanziario» li mostra con gli interessi e le spese versati dai condòmini.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><FileDown class="w-4 h-4" /> Il fascicolo</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Scarica il fascicolo»</strong>, in alto, mette le schede in un PDF solo. Le parti si scelgono con le spunte ed escono sempre nello stesso ordine: la nota sintetica, il riepilogo, la situazione patrimoniale, la cassa, i condòmini, il conguaglio, il registro di contabilità, i controlli. In testa c'è un frontespizio con l'esito dei controlli e l'indice.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«Il riepilogo con il dettaglio per spesa» aggiunge una riga per fattura o movimento.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«Mostra l'ultima lettera di sollecito», con la parte dei condòmini, aggiunge la colonna delle lettere ai crediti per anzianità. Il fascicolo va a tutti i condòmini: la casella è spenta ogni volta che apri la pagina.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>La scelta delle parti resta su questo computer. Ogni parte si stampa anche da sola, dalla sua scheda.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Senza la nota sintetica il frontespizio lo dice ai condòmini; «Scrivi la nota» porta alla scheda.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Il fascicolo si apre in un'altra scheda del browser. Con molti condòmini può volerci più di un minuto; se è troppo grande per il server, lì trovi quale parte togliere.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Il fascicolo ha sempre le cifre di oggi. Dopo l'approvazione, il rendiconto approvato è il PDF della versione approvata, nel riquadro «Il rendiconto per l'assemblea».</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Archive class="w-4 h-4" /> Fermare il rendiconto per l'assemblea</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Ferma per l'assemblea»</strong>, nel riquadro «Il rendiconto per l'assemblea» in fondo alla pagina, conserva le cifre di oggi e il PDF del fascicolo, con le parti scelte: è il PDF che mandi ai condòmini con la convocazione. Dopo, le schede cambiano a ogni registrazione; la versione fermata no.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Prima di fermare, la finestra dice che cosa manca alla nota sintetica: si ferma lo stesso. La nota si conserva com'è quel giorno; cambiarla dopo non cambia la versione.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Con molti condòmini può volerci più di un minuto. Se il fascicolo è troppo grande per farlo in una volta, la versione nasce con le cifre senza il PDF: conservi le parti una per una, con i pulsanti «Conserva», o carichi il PDF che hai mandato, con «Carica il PDF che hai mandato».</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«Per quale assemblea»: con un'assemblea in preparazione che ha il punto del rendiconto di questo esercizio, la versione si lega a quel punto ed entra nel fascicolo dell'assemblea. Senza, la versione si ferma lo stesso.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>La riga della versione legata dice in quale assemblea e se la convocazione è partita: «In assemblea il 15/12, punto 1 · convocazione spedita il 03/12».</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Ogni versione ha il suo numero e i suoi PDF da riaprire. Una versione fermata dice il giorno, l'ora e chi l'ha fermata: il programma sa quando è stata fermata, non quando è stata presentata all'assemblea.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Stamp class="w-4 h-4" /> Registrare l'approvazione</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Dopo l'assemblea, <strong>«Registra l'approvazione»</strong> sulla versione fermata che l'assemblea ha votato. Scrivi la «Data della delibera», che non può essere dopo oggi, il «Numero del verbale», e se vuoi il verbale e una nota.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«L'assemblea ha approvato anche lo stato di riparto (il conguaglio per persona)?»: «Sì» o «No», come dice il verbale. Nessuna delle due è scelta prima: il pulsante resta spento finché non rispondi e non scrivi la data.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Si registra ciò che dice il verbale. Lo stato dell'esercizio non cambia, e niente si blocca.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Se le cifre sono cambiate dal giorno della versione, la finestra lo dice. Puoi registrare l'approvazione di quella versione, che è quella votata, o fermarne una nuova con le cifre di oggi e approvare quella.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Se la nota sintetica della versione era incompleta, la finestra lo ricorda: l'approvazione si registra lo stesso.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Una seconda approvazione dello stesso esercizio fa la prima «sostituita»: resta nell'elenco, con il suo PDF.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><History class="w-4 h-4" /> Dopo l'approvazione</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Se dopo l'approvazione cambiano registrazioni che le schede leggono, ogni scheda lo dice in testa, con «Vedi che cosa è cambiato». Il rendiconto approvato resta quello del PDF.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Qui, nel riquadro, l'avviso dice le cifre di allora e quelle di oggi. «Vedi che cosa è cambiato» elenca le registrazioni nuove, cambiate o tolte, e per persona il conguaglio e il saldo finale, approvati e di oggi: un incasso cambia il saldo finale, non il conguaglio.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Perché è cambiato lo sai tu: il programma non lo giudica. Niente si blocca; se vuoi, «Ferma una versione nuova».</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>In un condominio grande il confronto delle cifre non si fa da sé, perché chiede tempo: «Confronta con la versione approvata».</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Il saldo dell'estratto conto della banca, scritto nella nota, non conta nel confronto: non è una registrazione.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Trash2 class="w-4 h-4" /> Eliminare una versione</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Una versione fermata e mai approvata si elimina con «Elimina la versione». Il PDF conservato se ne va con lei: se l'hai mandato ai condòmini, tienine una copia. Le schede del rendiconto non cambiano.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Una versione approvata, o sostituita, resta: è la delibera dell'assemblea.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Una versione allegata a una convocazione già partita non si elimina: è il rendiconto che i condòmini hanno ricevuto.</span></li>
                            </ul>
                        </div>
                    </section>
                </div>
            </div>
        </SheetContent>
    </Sheet>
</template>
