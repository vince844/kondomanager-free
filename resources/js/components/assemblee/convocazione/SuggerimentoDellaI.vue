<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La «i» (l'icona `Info`, come nel resto del programma) della convocazione con il suggerimento del programma (`ui/tooltip`), al posto del `title` del browser, che compare dopo un secondo fermi e mai
 * al tocco: qui il suggerimento compare subito al passaggio del mouse, arrivandoci con il tasto Tab e al tocco sul telefono (un secondo tocco, o un tocco
 * fuori, lo chiude). Il testo è anche l'etichetta per chi legge lo schermo.
 */
import { ref } from 'vue';
import { Info } from 'lucide-vue-next';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';

withDefaults(defineProps<{ testo: string; testid?: string }>(), { testid: undefined });

const aperto = ref(false);
</script>

<template>
    <TooltipProvider :delay-duration="0">
        <Tooltip v-model:open="aperto" disable-closing-trigger>
            <TooltipTrigger as-child>
                <button
                    type="button"
                    class="group inline-flex shrink-0 cursor-help items-center justify-center rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                    :aria-label="testo"
                    :data-testid="testid"
                    @click.prevent="aperto = !aperto"
                ><Info class="h-3.5 w-3.5 text-slate-400 transition-colors group-hover:text-indigo-500 dark:text-slate-500 dark:group-hover:text-indigo-400" aria-hidden="true" /></button>
            </TooltipTrigger>
            <TooltipContent class="max-w-72 text-xs leading-snug" data-testid="suggerimento"><p>{{ testo }}</p></TooltipContent>
        </Tooltip>
    </TooltipProvider>
</template>
