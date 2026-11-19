<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Decisioni 493 e 494: le otto scelte sugli interessi del debito ereditato, ognuna con l'interruttore, la riga «Adesso: …» che dice che cosa fa la scelta di
 * oggi, e la «i» con il caso di Ettore Fabbri. La stessa lista in «Recupero dei crediti» (le regole del condominio) e nella successione («Cambia per questa
 * successione»). I testi arrivano dal server (`RegoleDegliEredi::perLaPagina`); qui si dicono e si cambiano. Senza `modificabile` gli interruttori sono spenti.
 */
import { Info } from 'lucide-vue-next';
import { Switch } from '@/components/ui/switch';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { adessoDellaScelta, type SceltaDegliEredi, type VoceInteressiEredi } from '@/lib/gestionale/addebiti/interessiDegliEredi';

const props = defineProps<{ voci: VoceInteressiEredi[]; modelValue: Record<SceltaDegliEredi, boolean>; modificabile?: boolean; prefisso?: string }>();
const emit = defineEmits<{ (e: 'update:modelValue', v: Record<SceltaDegliEredi, boolean>): void }>();

const cambia = (chiave: SceltaDegliEredi, valore: boolean) => emit('update:modelValue', { ...props.modelValue, [chiave]: valore });
const id = (chiave: string) => `${props.prefisso ?? 'eredi'}-${chiave}`;
</script>

<template>
    <ul class="divide-y divide-slate-100 dark:divide-slate-800/60" data-testid="interessi-degli-eredi">
        <li v-for="v in voci" :key="v.chiave" class="flex items-start justify-between gap-3 py-3" :data-testid="`scelta-${v.chiave}`">
            <div class="min-w-0 flex-1 space-y-1">
                <p class="flex items-center gap-1.5 text-sm font-medium text-slate-900 dark:text-slate-100">
                    <label :for="id(v.chiave)">{{ v.titolo }}</label>
                    <TooltipProvider :delay-duration="200">
                        <Tooltip>
                            <TooltipTrigger as-child>
                                <span tabindex="0" role="img" :aria-label="v.esempio" data-testid="esempio"
                                    class="inline-flex items-center text-slate-400 hover:text-slate-600 dark:text-slate-500 dark:hover:text-slate-300 cursor-help rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400">
                                    <Info class="w-3.5 h-3.5" />
                                </span>
                            </TooltipTrigger>
                            <TooltipContent class="max-w-80 text-xs leading-snug"><p>{{ v.esempio }}</p></TooltipContent>
                        </Tooltip>
                    </TooltipProvider>
                </p>
                <p class="text-xs leading-snug text-slate-600 dark:text-slate-400" data-testid="adesso">{{ adessoDellaScelta(v, modelValue[v.chiave]) }}</p>
            </div>
            <Switch :id="id(v.chiave)" class="mt-0.5 shrink-0" :model-value="modelValue[v.chiave]" :disabled="!modificabile"
                :data-testid="`interruttore-${v.chiave}`" @update:model-value="(x: boolean) => cambia(v.chiave, x)" />
        </li>
    </ul>
</template>
