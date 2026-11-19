<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Decisione 483: nella successione, sotto la scelta dell'arretrato, gli interessi del defunto dopo il decesso. «Si fermano al decesso»: nella proposta degli
 * interessi del defunto, sulle rate delle unità del passaggio, l'ultimo giorno contato è il giorno prima del decesso; «continuano a suo nome»: la proposta
 * continua a contare i giorni. Di serie la prima con l'arretrato agli eredi, la seconda con l'arretrato a nome del defunto. Il valore è `null` finché
 * l'amministratore non tocca il menu: allora il menu mostra il di serie della strada, e lo segue se la strada cambia; toccato, resta. La frase la scrive il
 * server con l'anteprima, che si rifà quando la scelta cambia.
 */
import { computed } from 'vue';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { Info } from 'lucide-vue-next';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import type { SceltaInteressiDelDefunto } from '@/types/gestionale/passaggi';
import { interessiCheValgono, type StradaDellArretrato } from '@/lib/gestionale/passaggi/interessiDelDefunto';

const props = defineProps<{ strada: StradaDellArretrato; frase: string | null | undefined; modelValue: SceltaInteressiDelDefunto | null }>();
const emit = defineEmits<{ (e: 'update:modelValue', v: SceltaInteressiDelDefunto): void }>();

const SCELTE: Array<{ valore: SceltaInteressiDelDefunto; nome: string }> = [
    { valore: 'al_decesso', nome: 'si fermano al decesso' },
    { valore: 'continuano', nome: 'continuano a suo nome' },
];

const valoreDellaScelta = (s: { valore: SceltaInteressiDelDefunto }) => s.valore;
const scegli = (v: SceltaInteressiDelDefunto) => emit('update:modelValue', v);
const valore = computed(() => interessiCheValgono(props.modelValue, props.strada));
const esempio = 'Per esempio: un condòmino muore il 17/11 e non ha pagato la rata di aprile, € 300,00, scaduta il 30/04. Al 2 % l\'anno, a fine anno, con '
    + '«si fermano al decesso» la proposta dei suoi interessi conta dal 01/05 al 16/11, 200 giorni, € 3,29; con «continuano a suo nome» conta fino al 31/12, '
    + '245 giorni, € 4,03. Di serie la prima con l\'arretrato agli eredi, la seconda con l\'arretrato a nome del defunto. Resta una proposta: diventa un addebito '
    + 'solo se la registri.';
</script>

<template>
    <div class="space-y-1.5 rounded-lg border border-slate-200 bg-white px-3.5 py-2.5 dark:border-slate-700 dark:bg-slate-950" data-testid="interessi-del-defunto">
        <p class="flex items-center gap-1.5 text-sm font-semibold text-slate-900 dark:text-slate-100">
            Gli interessi del defunto dopo il decesso
            <TooltipProvider :delay-duration="200">
                <Tooltip>
                    <TooltipTrigger as-child>
                        <span tabindex="0" role="img" :aria-label="esempio" data-testid="info-interessi-del-defunto"
                            class="inline-flex items-center text-slate-400 hover:text-slate-600 dark:text-slate-500 dark:hover:text-slate-300 cursor-help rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400">
                            <Info class="w-3.5 h-3.5" />
                        </span>
                    </TooltipTrigger>
                    <TooltipContent class="max-w-80 text-xs leading-snug"><p>{{ esempio }}</p></TooltipContent>
                </Tooltip>
            </TooltipProvider>
        </p>
        <div class="menu-interessi-del-defunto w-[300px] max-w-full">
            <v-select
                :model-value="valore"
                :options="SCELTE"
                label="nome"
                :reduce="valoreDellaScelta"
                :searchable="false"
                :clearable="false"
                input-id="interessi-del-defunto"
                class="w-full bg-white dark:bg-slate-950 text-sm"
                @update:model-value="scegli"
            />
        </div>
        <p v-if="frase" class="text-xs text-slate-600 dark:text-slate-400 leading-snug" data-testid="frase-interessi-del-defunto">{{ frase }}</p>
    </div>
</template>
