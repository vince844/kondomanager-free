<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { ArrowRight, BellRing, FolderCog, Wallet } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    azione: {
        tipo: string;
        label: string;
        descrizione: string;
        impattoCents: number;
        route: string;
        routeParams?: Record<string, any>;
    };
    isPrimary?: boolean;
    livello?: 'verde' | 'giallo' | 'rosso';
}>();

const { euro } = useCurrencyFormatter();

const iconComponent = computed(() => {
    switch (props.azione.tipo) {
        case 'sollecito': return BellRing;
        case 'gestione_pregressi': return FolderCog;
        default: return Wallet;
    }
});

const resolvedRoute = computed(() => {
    try {
        return route(props.azione.route, props.azione.routeParams ?? {});
    } catch {
        return null;
    }
});
</script>

<template>
    <component
        :is="resolvedRoute ? Link : 'div'"
        :href="resolvedRoute ?? undefined"
        class="flex items-center gap-3 p-2.5 rounded-lg border transition-all group/row hover:shadow-sm"
        :class="isPrimary && livello !== 'verde'
            ? 'border-amber-200 bg-amber-50/30 dark:bg-amber-900/10 hover:border-amber-300 hover:bg-amber-50'
            : 'border-slate-200 dark:border-slate-700 hover:border-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800/50'"
    >
        <div class="shrink-0 w-7 h-7 rounded-md flex items-center justify-center"
             :class="isPrimary && livello !== 'verde'
                ? 'bg-amber-100 text-amber-600'
                : 'bg-slate-100 dark:bg-slate-800 text-slate-500'">
            <component :is="iconComponent" class="w-3.5 h-3.5" />
        </div>

        <div class="flex-1 min-w-0" :title="azione.descrizione">
            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-700 dark:text-slate-300 truncate">
                {{ azione.label }}
            </p>
            <p class="text-[9px] text-slate-500 dark:text-slate-400 leading-tight mt-0.5 line-clamp-2">
                {{ azione.descrizione }}
            </p>
        </div>

        <div v-if="azione.impattoCents > 0" class="shrink-0 text-right">
            <span class="text-[9px] text-slate-400 uppercase font-bold block leading-none">Impatto</span>
            <span class="text-xs font-black text-emerald-600 leading-none">
                +{{ euro(azione.impattoCents) }}
            </span>
        </div>

        <ArrowRight class="w-3.5 h-3.5 text-slate-400 group-hover/row:text-slate-600 transition-colors shrink-0" />
    </component>
</template>
