<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * L'etichetta di un campo delle caselle con la «i» accanto, che al passaggio del mouse (o con il tasto Tab) mostra un esempio: gli standard delle pagine,
 * una «i» con un esempio accanto alle scelte. Senza esempio, l'etichetta da sola.
 */
import { Info } from 'lucide-vue-next';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';

defineProps<{ per?: string; testo: string; esempio?: string }>();
</script>

<template>
    <div class="mb-1.5 flex items-center gap-1.5">
        <label :for="per" class="text-sm font-medium text-slate-700 dark:text-slate-300">{{ testo }}</label>
        <TooltipProvider v-if="esempio" :delay-duration="200">
            <Tooltip>
                <TooltipTrigger as-child>
                    <span tabindex="0" role="img" :aria-label="esempio" :data-testid="per ? `info-${per}` : undefined"
                        class="inline-flex cursor-help items-center rounded text-slate-400 hover:text-slate-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400 dark:text-slate-500 dark:hover:text-slate-300">
                        <Info class="h-3.5 w-3.5" />
                    </span>
                </TooltipTrigger>
                <TooltipContent class="max-w-80 text-xs leading-snug"><p>{{ esempio }}</p></TooltipContent>
            </Tooltip>
        </TooltipProvider>
    </div>
</template>
