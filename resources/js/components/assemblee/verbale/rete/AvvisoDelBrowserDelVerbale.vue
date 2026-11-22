<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * L'avviso del browser prima di aprire l'editor del verbale (N7; decisione 519 (2), come la sala):
 *
 * - **`non_tiene`** (sembra una finestra privata, o il deposito non si apre): una finestra che ferma l'apertura e chiede una scelta: «Copia l'indirizzo»
 *   per riaprire il verbale in una finestra normale, o «Apri lo stesso». Aperto lo stesso, il testo arriva al server finché c'è la rete.
 * - **`non_promette`**: una nota leggera, che non ferma.
 * - **`tiene`**: niente.
 *
 * Su un indirizzo senza https (il server dell'ufficio) una nota: la pagina non si riapre senza rete, ma il testo resta su questo computer e parte quando la
 * rete torna. La finestra segue lo standard delle finestre: ancorata in alto, il riepilogo e i pulsanti nel piede.
 */
import { ref } from 'vue';
import { Copy, Info } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import type { TenutaDelBrowser } from '@/lib/senzaRete/persistenza';

const props = withDefaults(defineProps<{ tenuta: TenutaDelBrowser | null; open?: boolean; indirizzo?: string; sicuro?: boolean }>(), {
    open: true,
    indirizzo: () => globalThis.location?.href ?? '',
    sicuro: () => globalThis.isSecureContext !== false,
});
const emit = defineEmits<{ (e: 'apri-lo-stesso'): void; (e: 'update:open', v: boolean): void }>();

const copiato = ref<string | null>(null);
async function copia() {
    try {
        await globalThis.navigator.clipboard.writeText(props.indirizzo);
        copiato.value = 'Indirizzo copiato: chiudi questa finestra e incollalo in una finestra normale.';
    } catch {
        copiato.value = `Copialo a mano: ${props.indirizzo}`;
    }
}
function apriLoStesso() {
    emit('apri-lo-stesso');
    emit('update:open', false);
}
</script>

<template>
    <div v-if="!sicuro" class="mb-2 flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-950 dark:border-amber-800
        dark:bg-amber-950/40 dark:text-amber-100" data-testid="verbale-nota-indirizzo">
        <Info class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
        <ul class="list-disc space-y-0.5 pl-4">
            <li>Su questo indirizzo (senza https) il verbale non si riapre senza rete.</li>
            <li>Ciò che scrivi resta su questo computer e parte quando la rete torna.</li>
        </ul>
    </div>
    <Dialog v-if="tenuta === 'non_tiene'" :open="open" @update:open="$emit('update:open', $event)">
        <DialogContent class="top-[5vh] flex max-h-[90vh] translate-y-0 flex-col gap-0 overflow-hidden p-0 sm:max-w-[520px]" data-testid="verbale-avviso-finestra-privata"
            @open-auto-focus.prevent>
            <DialogHeader class="border-b border-slate-100 px-5 pt-4 pb-3 pr-12 text-left dark:border-slate-800">
                <DialogTitle>Questo browser non tiene il testo del verbale</DialogTitle>
                <DialogDescription>Sembra una finestra privata: quando si chiude, cancella tutto.</DialogDescription>
            </DialogHeader>
            <div class="min-h-0 flex-1 space-y-3 overflow-y-auto px-5 py-4 text-sm">
                <section class="space-y-1.5">
                    <h3 class="text-xs font-semibold text-slate-500 dark:text-slate-400">Che cosa succede</h3>
                    <ul class="list-disc space-y-1 pl-5 text-slate-700 dark:text-slate-200">
                        <li>Con la rete va tutto bene: il testo arriva al server pochi secondi dopo che smetti di scrivere.</li>
                        <li>Se la rete cade e la finestra si chiude, ciò che hai scritto senza rete si perde.</li>
                        <li>In una finestra normale il testo resta sul disco, anche se il computer si spegne.</li>
                    </ul>
                </section>
                <p v-if="copiato" class="text-sm text-emerald-700 dark:text-emerald-400" data-testid="verbale-indirizzo-copiato">{{ copiato }}</p>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2.5 border-t border-slate-200 bg-slate-50 px-5 py-3 dark:border-slate-800 dark:bg-slate-900/60">
                <p class="min-w-[200px] flex-1 text-[13px] text-slate-600 dark:text-slate-300" role="status">Copia l'indirizzo e riaprilo in una finestra normale.</p>
                <div class="ml-auto flex flex-wrap justify-end gap-2">
                    <Button variant="outline" data-testid="verbale-apri-lo-stesso" @click="apriLoStesso">Apri lo stesso</Button>
                    <Button data-testid="verbale-copia-indirizzo" @click="copia"><Copy class="mr-1 h-4 w-4" aria-hidden="true" />Copia l'indirizzo</Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
    <div v-else-if="tenuta === 'non_promette'" class="flex items-start gap-2 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700
        dark:border-slate-700 dark:bg-slate-900/60 dark:text-slate-200" data-testid="verbale-nota-del-browser">
        <Info class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
        <p>Questo browser tiene il testo, ma non promette di non cancellarlo se il disco si riempie. Per la sera dell'assemblea basta.</p>
    </div>
</template>
