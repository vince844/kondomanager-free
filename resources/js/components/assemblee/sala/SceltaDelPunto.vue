<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * I punti della sala, sopra il voto (decisione 664 (3), rilievo C50).
 *
 * - **Fino a sei punti**: i pulsanti «Punto N», con il badge del loro esito.
 * - **Oltre**: un selettore su una riga, «‹», il menu di scelta («Punto 3 di 15 · Pulizia delle grondaie», troncato con i puntini; nelle voci ogni punto con
 *   il suo esito, lo stesso badge dei pulsanti), «›». Le frecce vanno al punto prima e dopo, spente al primo e all'ultimo. Al telefono lo stesso, a tutta
 *   larghezza.
 */
import { computed } from 'vue';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { BADGE, CLASSI_DEL_BADGE, coloreDellEsito } from '@/lib/assemblee/assemblee';
import type { PuntoDellaSala } from '@/types/assemblee';
import { parolaDellEsito } from './sala';

/** Fino a quanti punti bastano i pulsanti. */
const PULSANTI_FINO_A = 6;

const props = defineProps<{ punti: PuntoDellaSala[]; modelValue: number | null }>();
const emit = defineEmits<{ (e: 'update:modelValue', id: number): void }>();

const conIPulsanti = computed(() => props.punti.length <= PULSANTI_FINO_A);
const posto = computed(() => props.punti.findIndex((p) => p.id === props.modelValue));
const scegli = (id: number) => emit('update:modelValue', id);
function sposta(di: number) {
    const p = props.punti[posto.value + di];
    if (p) scegli(p.id);
}

const freccia = 'inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-slate-200 bg-white text-slate-700 shadow-sm hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800';
</script>

<template>
    <nav v-if="conIPulsanti" class="flex flex-wrap gap-1.5" aria-label="I punti dell'ordine del giorno" data-testid="punti">
        <button v-for="p in punti" :key="p.id" type="button" class="inline-flex h-8 items-center gap-1.5 rounded-md border px-2.5 text-xs font-medium"
            :class="p.id === modelValue ? 'border-slate-900 bg-slate-900 text-white dark:border-white dark:bg-white dark:text-slate-900'
                : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200'"
            :aria-current="p.id === modelValue ? 'step' : undefined" :data-testid="`punto-${p.numero}`" @click="scegli(p.id)">
            Punto {{ p.numero }}
            <span v-if="p.esito" :class="[BADGE, CLASSI_DEL_BADGE[coloreDellEsito(p.esito)]]">{{ parolaDellEsito(p.esito) }}</span>
        </button>
    </nav>
    <nav v-else class="flex w-full flex-nowrap items-center gap-1.5" aria-label="I punti dell'ordine del giorno" data-testid="selettore-dei-punti">
        <button type="button" :class="freccia" :disabled="posto <= 0" aria-label="Il punto prima" data-testid="punto-prima" @click="sposta(-1)">
            <ChevronLeft class="h-4 w-4" aria-hidden="true" />
        </button>
        <v-select class="min-w-0 flex-1 text-sm" :model-value="modelValue" :options="punti" label="testo" :reduce="(p: PuntoDellaSala) => p.id" :clearable="false"
            :searchable="false" data-testid="menu-dei-punti" @update:model-value="(id: number) => scegli(id)">
            <template #selected-option="p">
                <span class="block min-w-0 truncate" data-testid="punto-scelto">Punto {{ p.numero }} di {{ punti.length }} · {{ p.testo }}</span>
            </template>
            <template #option="p">
                <span class="flex min-w-0 items-center justify-between gap-2" :data-punto="p.numero">
                    <span class="min-w-0 truncate">Punto {{ p.numero }} · {{ p.testo }}</span>
                    <span v-if="p.esito" :class="[BADGE, CLASSI_DEL_BADGE[coloreDellEsito(p.esito)], 'shrink-0']">{{ parolaDellEsito(p.esito) }}</span>
                </span>
            </template>
        </v-select>
        <button type="button" :class="freccia" :disabled="posto < 0 || posto >= punti.length - 1" aria-label="Il punto dopo" data-testid="punto-dopo" @click="sposta(1)">
            <ChevronRight class="h-4 w-4" aria-hidden="true" />
        </button>
    </nav>
</template>
