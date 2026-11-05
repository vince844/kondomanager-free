<script setup lang="ts">
/**
 * La scelta dell'imposta di bollo di una ricevuta: il titolo con, a destra e in grigio, un dato che vale per questo gruppo; sotto la frase che il
 * chiamante vuole; poi quattro pulsanti in fila, uno solo acceso («Non dovuta», «Da assolvere», «Assolta: contrassegno», «Assolta: virtuale»).
 * Larghi quanto il loro testo, ognuno su una riga («Assolta: contrassegno» non va a capo); sul telefono due per riga. È un gruppo a scelta singola (si scorre con le frecce). Non tiene il valore: lo dicono i
 * chiamanti con `stato` e `modo`, e ogni pulsante scrive lo stato e, per le due «Assolta», il modo in cui è assolta (`scegli`; il modo è nullo
 * per le altre due, che non lo toccano).
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
    <div data-testid="scelta-bollo" class="space-y-2">
        <div class="flex flex-wrap items-baseline justify-between gap-x-3">
            <p class="font-medium">Imposta di bollo</p>
            <span v-if="nota" class="ml-auto text-right text-xs text-muted-foreground">{{ nota }}</span>
        </div>
        <p v-if="frase" class="text-xs text-muted-foreground">{{ frase }}</p>
        <RadioGroup :model-value="acceso" :aria-label="nome" class="grid grid-cols-2 gap-1.5 sm:grid-cols-[repeat(4,auto)]" @update:model-value="scegli">
            <RadioGroupItem
                v-for="s in SCELTE_BOLLO"
                :key="s.id"
                :value="s.id"
                :class="[
                    'flex min-h-9 w-full cursor-pointer items-center justify-center rounded-md border px-2 py-1.5 text-center text-xs font-semibold leading-snug transition-colors sm:whitespace-nowrap',
                    'ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2',
                    acceso === s.id
                        ? 'border-primary/40 bg-primary/5 text-primary'
                        : 'border-slate-200 bg-white text-slate-500 hover:border-slate-300 hover:text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400',
                ]"
            >
                {{ s.etichetta }}
            </RadioGroupItem>
        </RadioGroup>
    </div>
</template>
