<script setup lang="ts">
import { Wallet, ArrowRight } from 'lucide-vue-next';

defineProps<{
    crediti: Array<{
        anagrafica_id: number;
        nome: string;
        totale_formatted: string;
        url: string;
    }>;
}>();
</script>

<template>
    <div class="relative flex flex-col overflow-hidden rounded-xl border border-sidebar-border/70 bg-white dark:bg-slate-900 shadow-sm transition-all hover:shadow-md">
        <div class="p-5">
            <div class="flex items-center justify-between mb-3">
                <div class="flex items-center gap-1.5">
                    <Wallet class="w-4 h-4 text-blue-500" />
                    <h3 class="text-xs font-bold uppercase tracking-widest text-slate-500">Crediti da compensare</h3>
                </div>
                <span class="text-[10px] font-bold text-slate-400">{{ crediti.length }}</span>
            </div>

            <!-- Altezza massima fissa + scroll interno: la card non cresce
                 senza limite quando i condòmini con credito sono molti. -->
            <div class="space-y-1.5 max-h-[240px] overflow-y-auto custom-scrollbar pr-1">
                <a
                    v-for="c in crediti"
                    :key="c.anagrafica_id"
                    :href="c.url"
                    class="flex items-center justify-between gap-2 px-2.5 py-2 rounded-lg bg-blue-50/50 dark:bg-blue-900/10 border border-blue-100 dark:border-blue-900/30 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-colors group/row"
                >
                    <span class="text-xs font-medium text-slate-700 dark:text-slate-300 truncate">{{ c.nome }}</span>
                    <span class="flex items-center gap-1 shrink-0">
                        <span class="text-xs font-black text-blue-600 dark:text-blue-400">{{ c.totale_formatted }}</span>
                        <ArrowRight class="w-3 h-3 text-blue-400 opacity-0 group-hover/row:opacity-100 transition-opacity" />
                    </span>
                </a>
            </div>

            <p class="text-[9px] text-slate-400 mt-3 leading-tight">
                Clicca su un condòmino per aprire "Nuovo incasso" con anagrafica precompilata e compensare il credito.
            </p>
        </div>
    </div>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar { width: 4px; }
.custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }
.dark .custom-scrollbar::-webkit-scrollbar-thumb { background: #334155; }
</style>
