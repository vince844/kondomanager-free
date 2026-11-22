<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La guida della pagina «Registro delle modifiche» (del condominio e dello studio), nel cassetto che si apre di lato: che cosa c'è nel registro, i filtri e
 * le pagine, la stampa con la sua finestra (il periodo dal … al …, il conto delle righe, il limite di una stampa sola), la «Storia» di una scheda con
 * «Ripristina», «Verifica l'integrità», chi lo vede. Ogni frase dice ciò che la pagina e il server fanno oggi.
 */
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { ChevronRight, Filter, History, Printer, ShieldCheck, Users } from 'lucide-vue-next';

defineProps<{ open: boolean }>();
defineEmits(['update:open']);

const punti = 'flex gap-2';
</script>

<template>
    <Sheet :open="open" @update:open="$emit('update:open', $event)">
        <SheetContent class="w-full overflow-y-auto p-0 sm:w-[600px] sm:max-w-2xl" data-testid="guida-registro-modifiche">
            <div class="px-6 py-8">
                <SheetHeader class="mb-8">
                    <div class="mb-2 flex items-center gap-3">
                        <div class="rounded-lg bg-blue-100 p-2 text-blue-700 dark:bg-blue-900 dark:text-blue-300"><History class="h-6 w-6" /></div>
                        <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: registro delle modifiche</SheetTitle>
                    </div>
                    <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
                        Chi ha cambiato che cosa e quando, con il valore di prima e quello di dopo. Nessuno lo può modificare o cancellare, neanche l'amministratore.
                    </SheetDescription>
                </SheetHeader>

                <div class="space-y-8 text-sm text-slate-700 dark:text-slate-300">
                    <section>
                        <h3 class="mb-3 flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white"><Filter class="h-4 w-4" /> Le righe e i filtri</h3>
                        <ul class="space-y-2 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-800/50">
                            <li :class="punti"><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Ogni riga dice quando, chi (una persona con il suo ruolo, o «Il programma» per ciò che fa da solo), su che cosa e che cosa è cambiato.</span></li>
                            <li :class="punti"><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Di serie la pagina mostra il mese di oggi. «Chi», «Su che cosa», «Che cosa è successo», «Periodo» e «Cerca» cambiano le righe senza ricaricare la pagina.</span></li>
                            <li :class="punti"><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>In fondo alla tabella le pagine, come negli altri elenchi: quante righe in tutto, «Righe per pagina» (20, 50 o 100), «Pagina 2 di 7» e le frecce. Un filtro cambiato torna alla prima pagina.</span></li>
                            <li :class="punti"><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Una riga con «Annullata dal ripristino del …» è una modifica venuta dopo una copia che è stata ripristinata: resta nel registro, segnata.</span></li>
                        </ul>
                    </section>

                    <section>
                        <h3 class="mb-3 flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white"><Printer class="h-4 w-4" /> La stampa</h3>
                        <ul class="space-y-2 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-800/50">
                            <li :class="punti"><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>«Stampa», accanto a «Registro», apre la finestra con i filtri della pagina già scritti. Cambiarli lì non cambia la pagina.</span></li>
                            <li :class="punti"><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Il periodo va dal … al …: «Questo mese», «L'esercizio» (solo nel registro del condominio), «Dall'inizio», o «Scegli le date». Per esempio: per un'impugnazione basta il mese della delibera; per il passaggio di consegne, l'esercizio intero.</span></li>
                            <li :class="punti"><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Sotto, quante righe usciranno, prima di stampare: «Stamperai 134 righe, dal 01/01/2026 al 31/12/2026».</span></li>
                            <li :class="punti"><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Una stampa sola regge al più 5.000 righe (meno, se il server ha poca memoria). Oltre, la riga arancio lo dice e «Stampa il PDF» resta spento: scegli un periodo più breve, o un esercizio alla volta.</span></li>
                            <li :class="punti"><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Il PDF si apre in una scheda nuova: la carta dello studio, i filtri scelti in testa, il prima e il dopo di ogni dato, e in fondo l'impronta della stampa e quella dell'ultima riga.</span></li>
                        </ul>
                    </section>

                    <section>
                        <h3 class="mb-3 flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white"><History class="h-4 w-4" /> La «Storia» e «Ripristina»</h3>
                        <ul class="space-y-2 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-800/50">
                            <li :class="punti"><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>«Storia» su una riga, o nell'intestazione di una scheda, apre le modifiche di quella scheda dalla più recente, con ogni dato com'era e com'è.</span></li>
                            <li :class="punti"><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>«Ripristina» rimette il valore di prima di un dato: passa dagli stessi controlli della pagina della scheda e diventa una riga del registro, con il tuo nome. Se la scheda non passa i controlli (per esempio una scadenza del documento ormai passata), la finestra lo dice.</span></li>
                            <li :class="punti"><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>In contabilità, nei piani rate e nei millesimi non si ripristina: si cambia dalla scheda, e in contabilità si storna.</span></li>
                        </ul>
                    </section>

                    <section>
                        <h3 class="mb-3 flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white"><ShieldCheck class="h-4 w-4" /> «Verifica l'integrità»</h3>
                        <p>
                            Ogni riga nasce con la firma del programma, fatta con una chiave che sta sul server e non nel database, e porta l'impronta di quella
                            prima. La verifica rilegge la catena e dice «Il registro è integro.» oppure «Una riga non torna.», con la riga cambiata, aggiunta o
                            tolta fuori dal programma e il rapporto da scaricare per chi gestisce il server. Vede ciò che si fa con il solo database; non vede ciò
                            che fa chi ha anche la chiave del server, che può riscrivere tutto. La catena rende visibile una modifica fatta fuori dal programma;
                            non la impedisce.
                        </p>
                    </section>

                    <section>
                        <h3 class="mb-3 flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white"><Users class="h-4 w-4" /> Chi lo vede</h3>
                        <p>
                            Il registro del condominio, quello dello studio, la stampa e la verifica: chi ha il permesso «Vede il registro delle modifiche», di serie
                            l'amministratore. La «Storia» delle schede dei condomini: anche il collaboratore («Vede la storia delle schede»). I condòmini e i fornitori
                            mai.
                        </p>
                    </section>
                </div>
            </div>
        </SheetContent>
    </Sheet>
</template>
