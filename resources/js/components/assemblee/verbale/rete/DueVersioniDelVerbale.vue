<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Le due versioni di una parte del verbale (N6, scena 4 della bozza approvata dell'editor; decisione 519 (4)): un altro dispositivo ha scritto la stessa
 * parte. Niente si sovrascrive: l'avviso lo dice con chi e quando («La discussione del punto 4 è stata cambiata anche dal tablet di Marco Galli, alle
 * 20:24»), e «Confronta le due versioni» le mette affiancate, in sola lettura, con «Tieni questa» sotto ognuna e «Uniscile», che apre l'editor su una con
 * l'altra accanto, da cui copiare. Finché la scelta è aperta la parte non si scrive e la bozza non si stampa.
 *
 * La finestra segue lo standard delle finestre: ancorata in alto, il contenuto che scorre dentro, i gruppi con il titolo piccolo e grigio, il riepilogo
 * fisso nel piede accanto ai pulsanti, il lavoro in corso come un segno piccolo nel riepilogo. Il testo in sola lettura lo disegna chi monta il pezzo
 * (lo spazio `testo`, con il documento); senza, il testo semplice, paragrafo per paragrafo. Mai HTML.
 */
import { computed, ref } from 'vue';
import { GitCompareArrows, Loader2 } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { testo, type NodoDelVerbale } from '@/lib/assemblee/verbale/struttura';
import { chiEQuando, type DueVersioniDelTesto } from '@/lib/senzaRete/verbale';

const props = withDefaults(defineProps<{ due: DueVersioniDelTesto; inCorso?: boolean; puoScegliere?: boolean; fuso?: string }>(),
    { inCorso: false, puoScegliere: true, fuso: 'Europe/Rome' });
const emit = defineEmits<{ (e: 'scegli', scelta: 'questa' | 'quella', versioneId: number): void; (e: 'uniscile', due: DueVersioniDelTesto): void }>();

const aperta = ref(false);
const titoloGruppo = 'text-xs font-semibold text-slate-500 dark:text-slate-400';
const righe = (d: NodoDelVerbale) => testo(d).split('\n').filter((r) => r.trim() !== '');
const versioni = computed(() => [
    { chiave: 'arrivata' as const, scelta: 'questa' as const, chi: chiEQuando(props.due.arrivata, props.fuso), documento: props.due.arrivata.documento },
    { chiave: 'adesso' as const, scelta: 'quella' as const, chi: chiEQuando(props.due.adesso, props.fuso), documento: props.due.adesso.documento },
]);

function uniscile() {
    emit('uniscile', props.due);
    aperta.value = false;
}
</script>

<template>
    <div class="flex flex-col gap-2 rounded-md border border-violet-200 bg-violet-50 px-3 py-2.5 text-sm text-violet-950 sm:flex-row sm:items-start
        dark:border-violet-800 dark:bg-violet-950/40 dark:text-violet-100" data-testid="due-versioni-del-verbale">
        <GitCompareArrows class="mt-0.5 hidden h-4 w-4 shrink-0 sm:block" aria-hidden="true" />
        <p class="min-w-0 flex-1" data-testid="due-versioni-frase">{{ due.frase }}</p>
        <Button size="sm" variant="outline" class="shrink-0" data-testid="confronta-le-due-versioni" @click="aperta = true">Confronta le due versioni</Button>
    </div>

    <Dialog :open="aperta" @update:open="aperta = $event">
        <DialogContent class="top-[5vh] flex max-h-[90vh] translate-y-0 flex-col gap-0 overflow-hidden p-0 sm:max-w-[960px]" data-testid="finestra-confronto"
            @open-auto-focus.prevent>
            <DialogHeader class="border-b border-slate-100 px-5 pt-4 pb-3 pr-12 text-left dark:border-slate-800">
                <DialogTitle>{{ due.nome }}: due versioni</DialogTitle>
                <DialogDescription>Non sovrascrivo niente: scegli tu quale tenere, o le unisci.</DialogDescription>
            </DialogHeader>
            <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4" data-testid="corpo-confronto">
                <div class="grid gap-4 sm:grid-cols-2">
                    <section v-for="v in versioni" :key="v.chiave" class="flex min-w-0 flex-col gap-2" :data-testid="'versione-' + v.chiave">
                        <h3 :class="titoloGruppo">{{ v.chi }}</h3>
                        <div class="min-h-24 rounded-md border border-slate-200 bg-white p-3 text-sm leading-relaxed text-slate-800 dark:border-slate-700 dark:bg-slate-900
                            dark:text-slate-100" data-testid="testo-in-sola-lettura">
                            <slot name="testo" :documento="v.documento">
                                <p v-for="(riga, i) in righe(v.documento)" :key="i" class="mb-1.5 break-words last:mb-0">{{ riga }}</p>
                                <p v-if="righe(v.documento).length === 0" class="text-slate-500 dark:text-slate-400">Vuota.</p>
                            </slot>
                        </div>
                        <Button v-if="puoScegliere" class="w-full" :variant="v.chiave === 'arrivata' ? 'default' : 'outline'" :disabled="inCorso"
                            :data-testid="'tieni-' + v.chiave" @click="emit('scegli', v.scelta, due.versione_id)">Tieni questa</Button>
                    </section>
                </div>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2.5 border-t border-slate-200 bg-slate-50 px-5 py-3 dark:border-slate-800 dark:bg-slate-900/60"
                data-testid="piede-confronto">
                <p class="flex min-w-[200px] flex-1 items-center gap-2 text-[13px] text-slate-600 dark:text-slate-300" role="status" data-testid="riepilogo-confronto">
                    <span v-if="inCorso" class="inline-flex shrink-0"><Loader2 class="h-3.5 w-3.5 animate-spin" aria-hidden="true" /></span>
                    <span class="min-w-0">Quella che non tieni resta fra le versioni: niente si perde.</span>
                </p>
                <div class="ml-auto flex flex-wrap justify-end gap-2">
                    <Button variant="outline" @click="aperta = false">Chiudi</Button>
                    <Button v-if="puoScegliere" variant="outline" :disabled="inCorso" data-testid="uniscile" @click="uniscile">Uniscile</Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
