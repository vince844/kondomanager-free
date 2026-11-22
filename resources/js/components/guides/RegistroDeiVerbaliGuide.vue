<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La guida del registro dei verbali, nel cassetto che si apre di lato con «Guida»: che cosa ci va (art. 1130 n. 7 c.c.), il registro della scala, da dove
 * parte il numero, le righe annullate, il libro di carta e perché tutti e due, nomina e revoca. A schema, una cosa per riga, con accanto nel sorgente la riga
 * di codice che la prova (`prova: file:riga «testo»`).
 */
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { BookMarked, BookOpen, FileStack, Hash, UserCheck, ChevronRight } from 'lucide-vue-next';

defineProps<{ open: boolean }>();
defineEmits(['update:open']);
</script>

<template>
    <Sheet :open="open" @update:open="$emit('update:open', $event)">
        <SheetContent class="w-full overflow-y-auto p-0 sm:w-[600px] sm:max-w-2xl" data-testid="guida-registro">
            <div class="px-6 py-8">
                <SheetHeader class="mb-8">
                    <div class="mb-2 flex items-center gap-3">
                        <div class="rounded-lg bg-blue-100 p-2 text-blue-700 dark:bg-blue-900 dark:text-blue-300"><BookMarked class="h-6 w-6" /></div>
                        <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: il registro dei verbali</SheetTitle>
                    </div>
                    <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
                        Il registro che l'amministratore tiene (art. 1130 n. 7 c.c.): i verbali, le annotazioni, e accanto il registro di nomina e revoca.
                    </SheetDescription>
                </SheetHeader>

                <div class="space-y-8 text-sm text-slate-700 dark:text-slate-300">
                    <section>
                        <h3 class="mb-3 flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white"><BookOpen class="h-4 w-4" /> Che cosa ci va</h3>
                        <ul class="space-y-2 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-800/50">
                            <li class="flex gap-2"><!-- prova: resources/js/pages/gestionale/assemblee/RegistroDeiVerbali.vue:105 «i verbali delle assemblee, le mancate costituzioni e le dichiarazioni (art. 1130 n. 7 c.c.)» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>I verbali delle assemblee, le mancate costituzioni e le brevi dichiarazioni (art. 1130 n. 7 c.c.), per data.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/pages/gestionale/assemblee/RegistroDeiVerbali.vue:165 «x.numero ?? '—'» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Un'assemblea deserta si annota senza numero: «Mancata costituzione».</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/pages/gestionale/assemblee/RegistroDeiVerbali.vue:61 «x.tipo === 'a_mano' ? 'viola'» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Una riunione registrata a mano entra con il numero scritto sul verbale di carta: «A mano».</span></li>
                            <li class="flex gap-2"><!-- prova: app/Services/Assemblee/Verbale/RegistroDeiVerbali.php:193 «->orWhere('stato', StatoAssemblea::REGISTRATA_A_MANO->value)» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Anche l'assemblea preparata nel programma e tenuta su carta: il numero lo propone la finestra, dopo l'ultimo, e non torna più.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/pages/gestionale/assemblee/RegistroDeiVerbali.vue:120 «data-testid="scegli-il-registro"» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Le assemblee della sola scala hanno il loro registro, con la loro numerazione: lo scegli in alto.</span></li>
                        </ul>
                    </section>
                    <section>
                        <h3 class="mb-3 flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white"><Hash class="h-4 w-4" /> Da dove parte il numero</h3>
                        <ul class="space-y-2 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-800/50">
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/verbale/registro/DaDoveParte.vue:65 «Continua il libro di carta</button>» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>La prima volta il registro chiede da dove parte: «Continua il libro di carta», con l'ultimo numero, o «Comincia dal n. 1».</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/verbale/registro/DaDoveParte.vue:85 «Lo cambi finché non registri il primo verbale; dopo, il numero va solo avanti.» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Lo cambi finché non registri il primo verbale; dopo, il numero va solo avanti.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/pages/gestionale/assemblee/RegistroDeiVerbali.vue:170 «annullato il {{ giorno(x.annullato.il) }}» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Una registrazione annullata resta nel registro, «Annullato», con il perché: il suo numero non torna.</span></li>
                        </ul>
                    </section>
                    <section>
                        <h3 class="mb-3 flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white"><FileStack class="h-4 w-4" /> Il libro di carta</h3>
                        <ul class="space-y-2 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-800/50">
                            <li class="flex gap-2"><!-- prova: resources/js/pages/gestionale/assemblee/RegistroDeiVerbali.vue:179 «data-testid="stampa-la-pagina"» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Per ogni verbale e ogni annotazione «Stampa la pagina» fa la pagina da incollare nel libro, da firmare a cavallo.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/pages/gestionale/assemblee/RegistroDeiVerbali.vue:180 «data-testid="segna-incollata"» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>«Segna incollata nel libro» è un promemoria: non ferma niente. Il numero della pagina del libro lo scrivi a mano.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/pages/gestionale/assemblee/RegistroDeiVerbali.vue:156 «Il registro continua il libro di carta» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Perché tutti e due: l'art. 1130 n. 7 c.c. dice “anche con modalità informatizzate” dopo il registro di contabilità; per i verbali non lo dice in modo espresso.</span></li>
                        </ul>
                    </section>
                    <section>
                        <h3 class="mb-3 flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white"><UserCheck class="h-4 w-4" /> Nomina e revoca</h3>
                        <ul class="space-y-2 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-800/50">
                            <li class="flex gap-2"><!-- prova: resources/js/pages/gestionale/assemblee/RegistroDeiVerbali.vue:198 «data-testid="scrivi-la-nomina-in-corso"» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Il registro di nomina e revoca è uno, del condominio. La prima volta chiede la nomina in corso: chi amministra oggi, da quando, da dove viene.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/pages/gestionale/assemblee/RegistroDeiVerbali.vue:205 «data-testid="correggi-la-riga"» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Registrato un verbale con una nomina approvata, il programma propone la riga: controlla il nome con «Correggi», poi «Conferma la riga».</span></li>
                            <li class="flex gap-2"><!-- prova: app/Services/Assemblee/Verbale/RegistroDiNominaERevoca.php:149 «public static function nominatoInSala» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Il nome viene da quello detto in sala al voto del punto di nomina; se non c'è, da chi amministra oggi.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/pages/gestionale/assemblee/RegistroDeiVerbali.vue:227 «Finché non la confermi, una riga proposta non va nella stampa.» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Finché non la confermi, una riga proposta non va nella stampa del registro.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/pages/gestionale/assemblee/RegistroDeiVerbali.vue:219 «data-testid="annulla-la-riga"» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Una riga sbagliata si annulla con il perché: non si cancella.</span></li>
                        </ul>
                    </section>
                    <section>
                        <h3 class="mb-3 text-lg font-bold text-slate-900 dark:text-white">Un caso</h3>
                        <p class="rounded-xl border border-slate-200 p-4 dark:border-slate-800">Il libro di Via Roma è arrivato al n. 41. Registri il n. 42 nel programma, stampi le sue pagine e
                            le incolli dopo il 41.</p>
                    </section>
                </div>
            </div>
        </SheetContent>
    </Sheet>
</template>
