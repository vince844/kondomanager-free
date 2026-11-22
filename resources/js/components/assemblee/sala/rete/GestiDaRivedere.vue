<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * I gesti da rivedere (decisione 519: niente si perde): quelli che il server non ha accettato, o che non sono partiti perché dipendevano da un gesto non
 * accettato. Restano su questo computer, con l'ora e il perché, finché non si sceglie: «Rimanda» li rimette in coda nell'ordine (dopo aver corretto, per
 * esempio aperto l'assemblea), «Togli» ne toglie uno, dopo averlo chiesto. Le frasi si mostrano come testo, mai come HTML: ci sono i nomi delle persone.
 */
import { computed, ref } from 'vue';
import { RotateCcw } from 'lucide-vue-next';
import type { DaRivedere } from '@/lib/senzaRete/coda';
import { descriviIlGesto, ora } from '@/lib/senzaRete/sala';
import type { SalaDellAssemblea } from '@/types/assemblee';

const props = withDefaults(defineProps<{ gesti: DaRivedere[]; sala: SalaDellAssemblea | null; dispositivo?: 'computer' | 'telefono' | 'tablet'; fuso?: string;
    puoGestire?: boolean }>(), { dispositivo: 'computer', fuso: 'Europe/Rome', puoGestire: true });
const emit = defineEmits<{ (e: 'rimanda'): void; (e: 'togli', gesto: string): void }>();

const righe = computed(() => props.gesti.map((d) => {
    const g = descriviIlGesto(d.voce, props.sala);
    return { gesto: d.voce.gesto, ora: ora(d.voce.segnato_il, props.fuso), cosa: `${g.cosa.charAt(0).toUpperCase()}${g.cosa.slice(1)}${g.chi ? ' di ' + g.chi : ''}`
        + `${g.punto !== null ? ' al punto ' + g.punto : ''}`, perche: d.messaggio ?? 'non accettato' };
}));
const titolo = computed(() => (props.gesti.length === 1 ? `1 gesto non accettato resta su questo ${props.dispositivo}: rivedilo.`
    : `${props.gesti.length} gesti non accettati restano su questo ${props.dispositivo}: rivedili.`));
/** Il gesto di cui si chiede «Togli?». */
const inForse = ref<string | null>(null);
const pulsante = 'shrink-0 rounded-md border border-current/30 bg-white/70 px-2.5 py-1 text-xs font-medium hover:bg-white dark:bg-slate-900/60 dark:hover:bg-slate-900';
</script>

<template>
    <div v-if="gesti.length" class="space-y-1.5 rounded-md border border-rose-200 bg-rose-50 px-3 py-2.5 text-sm text-rose-950 dark:border-rose-800 dark:bg-rose-950/40
        dark:text-rose-100" data-testid="da-rivedere">
        <div class="flex flex-wrap items-center gap-2">
            <RotateCcw class="h-4 w-4 shrink-0" aria-hidden="true" />
            <p class="min-w-0 flex-1 font-medium">{{ titolo }}</p>
            <button v-if="puoGestire" type="button" :class="pulsante" data-testid="rimanda" @click="emit('rimanda')">Rimanda</button>
        </div>
        <p class="text-xs">Correggi prima ciò che il perché dice (per esempio apri l'assemblea), poi «Rimanda»: ripartono nell'ordine, con l'ora di allora.</p>
        <ul class="space-y-1 text-xs">
            <li v-for="r in righe" :key="r.gesto" class="flex flex-wrap items-center justify-between gap-2" data-testid="da-rivedere-riga">
                <span class="min-w-0 break-words">{{ r.ora }} · {{ r.cosa }}: {{ r.perche }}</span>
                <span v-if="puoGestire" class="flex shrink-0 items-center gap-1.5">
                    <template v-if="inForse === r.gesto">
                        <span>Si toglie da questo {{ dispositivo }}, e non si rimanda più.</span>
                        <button type="button" :class="pulsante" data-testid="togli-conferma" @click="emit('togli', r.gesto); inForse = null">Togli</button>
                        <button type="button" :class="pulsante" @click="inForse = null">Tienilo</button>
                    </template>
                    <button v-else type="button" :class="pulsante" data-testid="togli" @click="inForse = r.gesto">Togli</button>
                </span>
            </li>
        </ul>
    </div>
</template>
