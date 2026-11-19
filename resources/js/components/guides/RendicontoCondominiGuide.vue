<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La guida della scheda «Condòmini» del rendiconto, nella finestra che si apre di lato: i due elenchi alla data del rendiconto, le posizioni per
 * persona e unità con le righe che le legano alla situazione patrimoniale, i crediti per anzianità con le fasce, le annate e l'ultima lettera, le stampe
 * e i dati personali. Ogni frase dice ciò che la pagina e il server fanno oggi; per le persone di una successione lo scaduto di questa pagina (il giornale,
 * con il defunto) e quello di «Rate scadute» (con gli eredi) non sono lo stesso, e la guida dice perché (decisione 493 (12), giro delle correzioni della beta.12).
 */
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { ChevronRight, Clock, List, ShieldCheck, Users } from 'lucide-vue-next';

defineProps<{ open: boolean }>();
defineEmits(['update:open']);
</script>

<template>
    <Sheet :open="open" @update:open="$emit('update:open', $event)">
        <SheetContent class="sm:max-w-2xl overflow-y-auto w-full sm:w-[600px] p-0" data-testid="guida-rendiconto-condomini">
            <div class="px-6 py-8">
                <SheetHeader class="mb-8">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="p-2 bg-blue-100 text-blue-700 rounded-lg dark:bg-blue-900 dark:text-blue-300">
                            <Users class="w-6 h-6" />
                        </div>
                        <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: i condòmini nel rendiconto</SheetTitle>
                    </div>
                    <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
                        Quanto deve ogni persona alla data del rendiconto, e da quanto tempo: le posizioni e i crediti per anzianità.
                    </SheetDescription>
                </SheetHeader>

                <div class="space-y-8 text-sm text-slate-700 dark:text-slate-300">
                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><List class="w-4 h-4" /> Due elenchi</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«Posizioni» e «Crediti per anzianità», uno per volta. Sono alla data del rendiconto: per l'esercizio aperto oggi, e cambiano a ogni lettura; per uno finito, la data di fine.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Gli importi: positivo vuol dire che la persona deve, negativo che ha un credito.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Gli elenchi sono a pagine, con «Precedenti» e «Successive»; i totali sono di tutte le righe. Un clic sul nome apre l'estratto conto della persona.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Users class="w-4 h-4" /> Le posizioni</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Per persona e unità, quanto deve alla data: «Ordinarie dell'esercizio», «Straordinarie dell'esercizio», «Esercizi precedenti», «Crediti sulle rate» con il segno meno, «Totale». Quando servono, anche «Esercizi successivi», «Su rate non emesse» e «Anticipi». Ci sono le righe con almeno un importo diverso da zero.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>L'esercizio di una rata è quello del suo piano. Con il pregresso spalmato nelle rate, la riga dice che contiene anche una parte di pregresso.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Gli anticipi sono un debito del condominio verso la persona: il totale non li conta.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Sotto la tabella, due righe legano le posizioni ai «Crediti verso i condòmini» della situazione patrimoniale e al conto «Anticipi ricevuti dai condòmini». Una differenza è in rosso; il controllo «I crediti tornano con le posizioni», nella scheda «Controlli», dice dove cercare.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Clock class="w-4 h-4" /> I crediti per anzianità</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Una riga per rata non pagata: la persona, l'unità, la rata con il suo piano, l'importo, la scadenza e il ritardo. In testa i totali delle rate scadute per fascia: 1–30 giorni, 31–90 giorni, oltre 90 giorni.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Lo scaduto è quello di «Rate scadute» alla stessa data, tranne per le persone di una successione (qui sotto). Il credito di una persona non si toglie dalle sue rate: è nelle posizioni.</span></li>
                                <li class="flex gap-2" data-testid="successione-due-scaduti"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>Le persone di una successione.</strong> Quando il debito di chi è morto passa agli eredi, qui e nelle posizioni resta a nome del defunto, come nel giornale e nella situazione patrimoniale: la sua rata è ancora aperta, finché non la compensi con il credito che gli dà il piano degli eredi. «Rate scadute» invece lo mostra sugli eredi, con «ereditato», perché sono loro a doverlo pagare e a ricevere i solleciti e gli interessi; e ce lo lascia finché i suoi interessi corrono, anche dopo l'emissione della rata nuova che lo porta. Per esempio: Ettore muore e la sua rata di aprile, € 300,00, passa a Elena e Marco; qui resta € 300,00 a nome di Ettore, in «Rate scadute» ci sono € 150,00 a Elena e € 150,00 a Marco. Lo scaduto di questa pagina segue il giornale; quello di «Rate scadute», chi deve pagare.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Due annate per rata: «Annata della scadenza», l'esercizio che contiene la scadenza, e «Annata del piano», l'esercizio di competenza del piano. L'art. 1129 c. 9 c.c. si legge nei due modi, e qui ci sono tutte e due.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«In ordine di scaduto» mette prima chi deve di più; «In ordine di persona» mette le persone in fila. Per ogni persona le rate sono per scadenza.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Per un esercizio finito, «Versato dopo» e «Resta oggi»: quanto è entrato sulla rata dopo la data, al netto degli storni, e quanto resta oggi.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«Ultima lettera partita o spedita»: una lettera solo stampata, senza la spedizione registrata, non c'è. Un clic apre la corrispondenza, nell'estratto conto della persona.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><ShieldCheck class="w-4 h-4" /> Le stampe e i dati personali</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Ogni elenco ha la sua «Stampa». Nel fascicolo i due elenchi sono la parte «Condòmini: posizioni e crediti per anzianità»; la colonna delle lettere c'è solo se scegli «Mostra l'ultima lettera di sollecito» nella «Sintesi».</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Le posizioni e i crediti dicono chi deve e quanto: sono per i condòmini e per l'assemblea. Non vanno esposti in bacheca né girati ad altri, nemmeno in una chat.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>In un condominio molto grande le rate non pagate possono essere più di quante il server riesce a leggere per questa pagina: la pagina lo dice.</span></li>
                            </ul>
                        </div>
                    </section>
                </div>
            </div>
        </SheetContent>
    </Sheet>
</template>
