<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * L'avviso del browser prima di aprire la sala (decisione 519 (2), scena 3 della bozza delle scene della sala):
 *
 * - **`non_tiene`** (sembra una finestra privata, o il deposito non si apre): una finestra che ferma l'apertura e chiede una scelta: «Copia l'indirizzo» per
 *   riaprirla in una finestra normale, «Stampa il foglio della sala», «Apri lo stesso». Aperta lo stesso, la striscia lo ricorda per tutta la sera.
 * - **`non_promette`** (il browser tiene i dati ma non promette di non cancellarli): una nota leggera, che non ferma.
 * - **`tiene`**: niente.
 *
 * In più, **su un indirizzo senza https** (il server dell'ufficio, `http://192.168.1.20`; `localhost` vale come sicuro) una nota prima di aprire: il browser
 * non lascia tenere la pagina, quindi lì la sala non si riapre senza rete; ciò che si segna resta su questo computer e parte quando la rete torna, e il foglio
 * della sala stampato è il ripiego.
 */
import { ref } from 'vue';
import { Copy, Info, Printer } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import type { TenutaDelBrowser } from '@/lib/senzaRete/persistenza';

const props = withDefaults(defineProps<{ tenuta: TenutaDelBrowser | null; open?: boolean; foglio?: string | null; indirizzo?: string; sicuro?: boolean }>(), {
    open: true,
    foglio: null,
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
        dark:bg-amber-950/40 dark:text-amber-100" data-testid="nota-indirizzo">
        <Info class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
        <ul class="list-disc space-y-0.5 pl-4">
            <li>Su questo indirizzo (senza https) la sala non si riapre senza rete.</li>
            <li>Ciò che segni resta su questo computer e parte quando la rete torna.</li>
            <li>Tieni il foglio della sala stampato: è il ripiego se la pagina si chiude.</li>
        </ul>
    </div>
    <Dialog v-if="tenuta === 'non_tiene'" :open="open" @update:open="$emit('update:open', $event)">
        <DialogContent class="max-h-[100dvh] overflow-y-auto sm:max-w-[520px]" data-testid="avviso-finestra-privata" @open-auto-focus.prevent>
            <DialogHeader>
                <DialogTitle>Questo browser non tiene il lavoro della sala</DialogTitle>
                <DialogDescription>Sembra una finestra privata: quando si chiude, cancella tutto.</DialogDescription>
            </DialogHeader>
            <ul class="list-disc space-y-1 pl-5 text-sm text-slate-700 dark:text-slate-200">
                <li>Con la rete va tutto bene: ogni gesto arriva subito al server.</li>
                <li>Se la rete cade e la finestra si chiude, presenze e voti segnati senza rete si perdono.</li>
                <li>In una finestra normale il lavoro resta sul disco, anche se il computer si spegne.</li>
            </ul>
            <div class="space-y-1 text-sm">
                <p class="font-medium text-slate-800 dark:text-slate-100">Che cosa fare</p>
                <ul class="list-disc space-y-1 pl-5 text-slate-700 dark:text-slate-200">
                    <li>Copia l'indirizzo, chiudi questa finestra, incollalo in una finestra normale.</li>
                    <li>Oppure stampa il foglio della sala e tienilo a portata di mano.</li>
                </ul>
            </div>
            <p v-if="copiato" class="text-sm text-emerald-700 dark:text-emerald-400" data-testid="indirizzo-copiato">{{ copiato }}</p>
            <div class="flex flex-col-reverse gap-2 border-t border-slate-100 pt-3 sm:flex-row sm:justify-end dark:border-slate-800">
                <Button variant="outline" data-testid="apri-lo-stesso" @click="apriLoStesso">Apri lo stesso</Button>
                <Button v-if="foglio" variant="outline" as="a" :href="foglio" target="_blank" rel="noopener" data-testid="stampa-il-foglio">
                    <Printer class="mr-1 h-4 w-4" aria-hidden="true" />Stampa il foglio della sala
                </Button>
                <Button data-testid="copia-indirizzo" @click="copia"><Copy class="mr-1 h-4 w-4" aria-hidden="true" />Copia l'indirizzo</Button>
            </div>
        </DialogContent>
    </Dialog>
    <div v-else-if="tenuta === 'non_promette'" class="flex items-start gap-2 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700 dark:border-slate-700 dark:bg-slate-900/60 dark:text-slate-200"
        data-testid="nota-del-browser">
        <Info class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
        <p>Questo browser tiene il lavoro, ma non promette di non cancellarlo se il disco si riempie. Per la sala di stasera basta. Per esserne sicuro, tieni il
            foglio della sala.</p>
    </div>
</template>
