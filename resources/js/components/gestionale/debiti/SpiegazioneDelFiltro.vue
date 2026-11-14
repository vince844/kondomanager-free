<script setup lang="ts">
/**
 * L'icona «i» accanto al nome di un filtro: al passaggio del mouse, o arrivandoci con il tasto Tab, la frase che dice a che cosa serve. Sta
 * dentro l'etichetta del filtro: il clic sull'icona non deve spuntare la casella né aprire il menu, e l'icona non è un pulsante, così i
 * pulsanti dell'etichetta restano quelli del filtro. Va dentro un `TooltipProvider`.
 */
import { Info } from 'lucide-vue-next';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';

defineProps<{ testo: string }>();
</script>

<template>
    <Tooltip>
        <TooltipTrigger as-child>
            <span
                tabindex="0"
                role="img"
                :aria-label="testo"
                data-testid="spiegazione-filtro"
                class="inline-flex items-center text-slate-400 hover:text-slate-600 dark:text-slate-500 dark:hover:text-slate-300 cursor-help rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                @click.prevent
            >
                <Info class="w-3.5 h-3.5" />
            </span>
        </TooltipTrigger>
        <TooltipContent class="max-w-72 text-xs leading-snug"><p>{{ testo }}</p></TooltipContent>
    </Tooltip>
</template>
