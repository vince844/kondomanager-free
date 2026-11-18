<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Decisione 454: nella successione con l'arretrato agli eredi, l'accordo di rientro del defunto che la successione chiude. Per gli interessi del defunto
 * l'accordo che reggeva il giorno prima del decesso resta in regola fino al decesso, di serie: i giorni dell'accordo restano fuori dalla sua proposta, e dal
 * decesso non ne maturano altri. Con «vale come una decadenza» tornano i giorni dell'accordo. Il menu c'è solo se almeno un accordo reggeva; la frase la
 * scrive il server con l'anteprima, che si rifà quando la scelta cambia.
 */
import { computed } from 'vue';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { Info } from 'lucide-vue-next';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import type { AccordoDelDefunto, SceltaAccordoDelDefunto } from '@/types/gestionale/passaggi';

const props = defineProps<{ accordi: AccordoDelDefunto[]; defunto: string; frase: string | null | undefined; modelValue: SceltaAccordoDelDefunto }>();
const emit = defineEmits<{ (e: 'update:modelValue', v: SceltaAccordoDelDefunto): void }>();

const SCELTE: Array<{ valore: SceltaAccordoDelDefunto; nome: string }> = [
    { valore: 'in_regola', nome: 'resta in regola fino al decesso' },
    { valore: 'decadenza', nome: 'vale come una decadenza' },
];

const valoreDellaScelta = (s: { valore: SceltaAccordoDelDefunto }) => s.valore;
const scegli = (v: SceltaAccordoDelDefunto) => emit('update:modelValue', v);
const inRegola = computed(() => props.accordi.some((a) => a.in_regola));
const esempio = computed(() => `Per esempio: ${props.defunto} aveva un accordo di rientro e lo rispettava. Con «resta in regola fino al decesso» la proposta degli `
    + `interessi di ${props.defunto} lascia fuori i giorni dell'accordo, e dal decesso non ne conta altri; con «vale come una decadenza» tornano i giorni dell'accordo, `
    + 'come quando un accordo decade. Resta una proposta: diventa un addebito solo se la registri.');
</script>

<template>
    <div v-if="accordi.length" class="space-y-1.5 rounded-lg border border-slate-200 bg-white px-3.5 py-2.5 dark:border-slate-700 dark:bg-slate-950" data-testid="accordo-del-defunto">
        <template v-if="inRegola">
            <p class="flex items-center gap-1.5 text-sm font-semibold text-slate-900 dark:text-slate-100">
                L'accordo di rientro del defunto
                <TooltipProvider :delay-duration="200">
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <span tabindex="0" role="img" :aria-label="esempio" data-testid="info-accordo-del-defunto"
                                class="inline-flex items-center text-slate-400 hover:text-slate-600 dark:text-slate-500 dark:hover:text-slate-300 cursor-help rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400">
                                <Info class="w-3.5 h-3.5" />
                            </span>
                        </TooltipTrigger>
                        <TooltipContent class="max-w-80 text-xs leading-snug"><p>{{ esempio }}</p></TooltipContent>
                    </Tooltip>
                </TooltipProvider>
            </p>
            <div class="menu-accordo-del-defunto w-[300px] max-w-full">
                <v-select
                    :model-value="modelValue"
                    :options="SCELTE"
                    label="nome"
                    :reduce="valoreDellaScelta"
                    :searchable="false"
                    :clearable="false"
                    input-id="accordo-del-defunto"
                    class="w-full bg-white dark:bg-slate-950 text-sm"
                    @update:model-value="scegli"
                />
            </div>
        </template>
        <p v-if="frase" class="text-xs text-slate-600 dark:text-slate-400 leading-snug" data-testid="frase-accordo-del-defunto">{{ frase }}</p>
    </div>
</template>
