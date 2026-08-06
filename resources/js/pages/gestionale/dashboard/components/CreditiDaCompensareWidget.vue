<script setup lang="ts">
import { Wallet, ArrowRight } from 'lucide-vue-next';

defineProps<{
    crediti: Array<{
        anagrafica_id: number;
        nome: string;
        totale_formatted: string;
        /** Quanto di quel credito è davvero spendibile sulle rate aperte, in centesimi. */
        compensabile_cents: number;
        /** Se la riga porta da qualche parte: c'è un bersaglio, oppure c'è debito su un'altra gestione. */
        azionabile: boolean;
        compensabile_formatted: string;
        /** La frase che dice quale rata copre, o che non c'è niente da coprire. */
        copre: string;
        rata_bersaglio_id: number | null;
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
                <!-- Cliccabile solo se porta da qualche parte. Non basta «ha un bersaglio»:
                     quando il credito sta su un'altra gestione il consiglio non lo propone —
                     serve la spunta dell'amministratore — ma la frase gli dice che si può
                     fare, e togliergli il link lo manderebbe in un vicolo cieco. Chi non ha
                     davvero niente da fare resta in elenco, in grigio: il credito esiste e va
                     saputo, semplicemente non c'è niente da cliccare. -->
                <component
                    :is="c.azionabile ? 'a' : 'div'"
                    v-for="c in crediti"
                    :key="c.anagrafica_id"
                    :href="c.azionabile ? c.url : undefined"
                    class="block px-2.5 py-2 rounded-lg border transition-colors group/row"
                    :class="c.azionabile
                        ? 'bg-blue-50/50 dark:bg-blue-900/10 border-blue-100 dark:border-blue-900/30 hover:bg-blue-50 dark:hover:bg-blue-900/20'
                        : 'bg-slate-50/60 dark:bg-slate-800/30 border-slate-100 dark:border-slate-800 cursor-default'"
                >
                    <span class="flex items-center justify-between gap-2">
                        <span class="text-xs font-medium text-slate-700 dark:text-slate-300 truncate">{{ c.nome }}</span>
                        <span class="flex items-center gap-1 shrink-0">
                            <span class="text-xs font-black text-blue-600 dark:text-blue-400">{{ c.totale_formatted }}</span>
                            <ArrowRight v-if="c.azionabile" class="w-3 h-3 text-blue-400 opacity-0 group-hover/row:opacity-100 transition-opacity" />
                        </span>
                    </span>

                    <!-- La riga che trasforma un importo in un'indicazione. Quando non c'è
                         niente da coprire il tono cambia: è un'informazione, non un invito. -->
                    <span
                        class="block text-[10px] leading-tight mt-0.5"
                        :class="c.compensabile_cents > 0
                            ? 'text-blue-700/80 dark:text-blue-300/80'
                            : 'text-slate-400 dark:text-slate-500 italic'"
                    >{{ c.copre }}</span>
                </component>
            </div>

            <p class="text-[9px] text-slate-400 mt-3 leading-tight">
                Le righe cliccabili aprono "Nuovo incasso" già puntato sulla rata che il credito copre. Quelle in grigio non hanno nulla da compensare.
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
