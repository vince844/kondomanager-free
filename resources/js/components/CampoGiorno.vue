<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Il campo del giorno del programma, al posto di quello del browser (`<input type="date">`, che ha la forma e la lingua del browser): un pulsante con il
 * giorno «gg/mm/aaaa» e l'icona del calendario, che apre il calendario in italiano, lo stesso dei filtri degli allegati e dei commenti.
 *
 * - `v-model`: il giorno come `aaaa-mm-gg`, o vuoto. Si mostra 14/12/2026 dai numeri della data, senza passare da un'ora: nessun giorno prima o dopo per il
 *   fuso. Il giorno scelto arriva dal calendario come data senza ora, e si scrive con i suoi numeri.
 * - `min` e `max` (facoltativi, `aaaa-mm-gg`): i giorni fuori sono spenti nel calendario. «Non dopo oggi» è `:max="oggi"`.
 * - `svuotabile`: la «×» accanto al giorno lo toglie, per i giorni facoltativi. Spento (`disabled`), il calendario non si apre e la «×» non c'è.
 * - `id`, `aria-label` e `data-testid` vanno al pulsante; `class` al contenitore, che di serie è largo quanto chi lo contiene e alto 32 pixel. Accanto a un
 *   campo alto 36 pixel (l'ora, un `v-select` senza `menu-campo`, un pulsante) `class="h-9 text-sm"` lo mette in riga. Il contenitore è uno `span`: il
 *   campo può stare dentro un `<label>`, come quello del browser, e un clic sull'etichetta apre il calendario.
 * - Tastiera: Invio o lo spazio sul pulsante aprono il calendario, le frecce si muovono fra i giorni, Invio sceglie, Esc chiude e torna al pulsante.
 * - Senza un giorno il calendario apre sul mese di oggi nel fuso di Roma, o sul mese di `min` o di `max` quando oggi è fuori.
 */
import { computed, ref, useAttrs } from 'vue';
import { parseDate, today, type DateValue } from '@internationalized/date';
import { Calendar as IconaDelCalendario, X } from 'lucide-vue-next';
import { Calendar } from '@/components/ui/calendar';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { giornoDelCalendario, giornoItaliano } from '@/lib/giorno';
import { cn } from '@/lib/utils';

defineOptions({ inheritAttrs: false });

const props = withDefaults(defineProps<{
    modelValue: string | null | undefined;
    id?: string;
    ariaLabel?: string;
    dataTestid?: string;
    disabled?: boolean;
    min?: string;
    max?: string;
    svuotabile?: boolean;
}>(), { id: undefined, ariaLabel: undefined, dataTestid: undefined, disabled: false, min: undefined, max: undefined, svuotabile: false });
const emit = defineEmits<{ 'update:modelValue': [giorno: string] }>();
const attrs = useAttrs();

const aperto = ref(false);

/** Una data senza ora dal testo `aaaa-mm-gg`; nessuna con un testo vuoto o sbagliato. */
function dataDelCalendario(iso: string | null | undefined): DateValue | undefined {
    if (!iso || !/^\d{4}-\d{2}-\d{2}$/.test(iso.slice(0, 10))) return undefined;
    try {
        return parseDate(iso.slice(0, 10));
    } catch {
        return undefined;
    }
}

const giorno = computed(() => dataDelCalendario(props.modelValue));
const minimo = computed(() => dataDelCalendario(props.min));
const massimo = computed(() => dataDelCalendario(props.max));
const mostrato = computed(() => (giorno.value ? giornoItaliano(props.modelValue) : 'gg/mm/aaaa'));
const conLaX = computed(() => props.svuotabile && !!giorno.value && !props.disabled);

/** Il mese che il calendario apre senza un giorno: oggi a Roma, dentro i limiti. */
const meseDaAprire = computed<DateValue>(() => {
    const oggi = today('Europe/Rome');
    if (minimo.value && oggi.compare(minimo.value) < 0) return minimo.value;
    if (massimo.value && oggi.compare(massimo.value) > 0) return massimo.value;

    return oggi;
});

function scegli(valore: DateValue | undefined) {
    const iso = giornoDelCalendario(valore);
    if (iso) emit('update:modelValue', iso);
    aperto.value = false;
}
</script>

<template>
    <span :class="cn('relative flex h-8 w-full min-w-0 text-xs', attrs.class as string | undefined)">
        <Popover v-model:open="aperto">
            <PopoverTrigger as-child>
                <button
                    :id="id"
                    type="button"
                    :disabled="disabled"
                    :aria-label="ariaLabel"
                    :data-testid="dataTestid"
                    :class="cn(
                        'flex h-full w-full min-w-0 items-center justify-between rounded-md border border-input bg-background px-3 text-start tabular-nums',
                        'ring-offset-background focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60',
                        'dark:border-slate-700 dark:bg-slate-950',
                        giorno ? 'text-slate-900 dark:text-slate-100' : 'text-muted-foreground dark:text-slate-400',
                        conLaX && 'pr-9',
                    )"
                >
                    <span class="truncate">{{ mostrato }}</span>
                    <IconaDelCalendario class="ml-1.5 h-3.5 w-3.5 shrink-0 opacity-50" />
                </button>
            </PopoverTrigger>
            <PopoverContent class="w-auto p-0 dark:border-slate-700 dark:bg-slate-950" align="start" data-testid="calendario-del-giorno">
                <Calendar
                    :model-value="giorno"
                    :default-placeholder="giorno ?? meseDaAprire"
                    :min-value="minimo"
                    :max-value="massimo"
                    locale="it-IT"
                    initial-focus
                    prevent-deselect
                    @update:model-value="scegli"
                />
            </PopoverContent>
        </Popover>
        <button
            v-if="conLaX"
            type="button"
            class="absolute right-1.5 top-1/2 inline-flex h-6 w-6 -translate-y-1/2 items-center justify-center rounded text-slate-400 hover:bg-slate-100 hover:text-slate-700 focus:outline-none focus:ring-2 focus:ring-ring dark:text-slate-500 dark:hover:bg-slate-800 dark:hover:text-slate-200"
            aria-label="Togli il giorno"
            title="Togli il giorno"
            :data-testid="dataTestid ? `${dataTestid}-togli` : undefined"
            @click="emit('update:modelValue', '')"
        >
            <X class="h-3.5 w-3.5" />
        </button>
    </span>
</template>
