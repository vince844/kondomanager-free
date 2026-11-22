<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La scelta dell'imposta di bollo di una ricevuta, nella forma delle finestre: il titolo con, a destra e in grigio, un dato che vale per questo gruppo;
 * quattro scelte uguali attaccate in una barra, con le parole corte («Non dovuta», «Da assolvere», «Contrassegno», «Virtuale»), quella accesa in nero;
 * sul telefono due per riga; sotto la barra la frase che il chiamante vuole. È un gruppo a scelta singola (si scorre con le frecce). Non tiene il
 * valore: lo dicono i chiamanti con `stato` e `modo`, e ogni scelta scrive lo stato e, per le due assolte, il modo in cui è assolta (`scegli`; il modo
 * è nullo per le altre due, che non lo toccano).
 */
import { computed } from 'vue';
import { RadioGroupItem } from 'reka-ui';
import { RadioGroup } from '@/components/ui/radio-group';
import { SCELTE_BOLLO, idSceltaBollo } from '@/lib/gestionale/incassi/ricevute';

const props = withDefaults(defineProps<{
    stato: string | null;
    modo: string | null;
    /** Il dato a destra del titolo, in grigio. */
    nota?: string | null;
    /** La frase sotto il titolo. */
    frase?: string | null;
    /** Il nome che legge chi usa uno strumento di lettura: due gruppi nella stessa finestra devono avere nomi diversi. */
    nome?: string;
}>(), { nota: null, frase: null, nome: 'Imposta di bollo' });

const emit = defineEmits<{ (e: 'scegli', scelta: { stato: string; modo: string | null }): void }>();

const acceso = computed(() => idSceltaBollo(props.stato, props.modo));

function scegli(id: unknown) {
    const s = SCELTE_BOLLO.find((x) => x.id === id);
    if (s) emit('scegli', { stato: s.stato, modo: s.modo });
}
</script>

<template>
    <div data-testid="scelta-bollo" data-forma="barra" class="space-y-1.5">
        <div class="flex flex-wrap items-baseline justify-between gap-x-3">
            <p class="text-[13px] font-medium">Imposta di bollo</p>
            <span v-if="nota" class="ml-auto text-right text-xs text-muted-foreground">{{ nota }}</span>
        </div>
        <!-- Le righe fra le scelte sono il fondo grigio che si vede nello spazio di un pixel: tengono anche le due righe del telefono. -->
        <RadioGroup :model-value="acceso" :aria-label="nome"
            class="grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-slate-200 bg-slate-200 sm:grid-cols-4 dark:border-slate-700 dark:bg-slate-700" @update:model-value="scegli">
            <RadioGroupItem
                v-for="s in SCELTE_BOLLO"
                :key="s.id"
                :value="s.id"
                :class="[
                    'flex min-h-8 w-full cursor-pointer items-center justify-center px-2 py-1.5 text-center text-xs leading-snug transition-colors',
                    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring',
                    acceso === s.id
                        ? 'bg-slate-900 font-semibold text-white dark:bg-white dark:text-slate-900'
                        : 'bg-white font-medium text-slate-600 hover:bg-slate-50 hover:text-slate-900 dark:bg-slate-950 dark:text-slate-300 dark:hover:bg-slate-900',
                ]"
            >
                {{ s.corta }}
            </RadioGroupItem>
        </RadioGroup>
        <p v-if="frase" class="text-xs text-muted-foreground">{{ frase }}</p>
    </div>
</template>
