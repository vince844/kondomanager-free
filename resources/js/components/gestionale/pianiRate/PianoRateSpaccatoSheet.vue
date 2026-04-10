<script setup lang="ts">
import { computed } from 'vue';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import { Building2, ReceiptText, User, Wallet, Info } from 'lucide-vue-next';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';

const props = defineProps<{
    open: boolean;
    anagraficaData: any | null;
}>();

const emit = defineEmits(['update:open']);

const { euro } = useCurrencyFormatter();

// Qui avviene la magia: raggruppiamo tutte le quote di tutte le rate per Immobile
const spaccatoPerImmobile = computed(() => {
    if (!props.anagraficaData || !props.anagraficaData.rate) return [];

    const gruppi = new Map();

    props.anagraficaData.rate.forEach((rata: any) => {
        rata.dettaglio_quote.forEach((quota: any) => {
            const immId = quota.immobile_id || 'condominio';
            const immNome = quota.immobile_nome;

            if (!gruppi.has(immId)) {
                gruppi.set(immId, {
                    id: immId,
                    nome: immNome,
                    totale: 0,
                    quote_ordinarie: 0,
                    quote_saldo: 0,
                });
            }

            const gruppo = gruppi.get(immId);
            gruppo.totale += quota.importo;
            
            if (quota.tipo === 'saldo_iniziale') {
                gruppo.quote_saldo += quota.importo;
            } else {
                gruppo.quote_ordinarie += quota.importo;
            }
        });
    });

    return Array.from(gruppi.values());
});

const totaleGenerale = computed(() => {
    if (!props.anagraficaData) return 0;
    return props.anagraficaData.rate.reduce((acc: number, rata: any) => acc + rata.importo, 0);
});
</script>

<template>
    <Sheet :open="props.open" @update:open="emit('update:open', $event)">
        <SheetContent class="w-full sm:max-w-md overflow-y-auto border-l-0 sm:border-l sm:rounded-l-2xl shadow-2xl p-0">
            
            <div class="bg-slate-50 dark:bg-slate-900/50 p-6 border-b border-slate-100 dark:border-slate-800">
                <SheetHeader class="text-left space-y-4">
                    <div>
                        <SheetTitle class="flex items-center gap-2 text-xl font-black text-slate-800 dark:text-slate-100">
                            <ReceiptText class="w-5 h-5 text-indigo-600" />
                            Spaccato Finanziario
                        </SheetTitle>
                        <SheetDescription class="text-xs uppercase tracking-widest font-bold mt-1">
                            Dettaglio calcolo debiti
                        </SheetDescription>
                    </div>

                    <div v-if="anagraficaData" class="flex items-center justify-between bg-white dark:bg-slate-950 p-4 rounded-xl border border-slate-200 dark:border-slate-800 shadow-sm">
                        <div class="flex items-center gap-3">
                            <div class="p-2 bg-indigo-50 dark:bg-indigo-900/30 text-indigo-600 rounded-lg">
                                <User class="w-5 h-5" />
                            </div>
                            <div class="flex flex-col">
                                <span class="text-xs font-bold text-slate-400 uppercase tracking-widest">Condòmino</span>
                                <span class="font-black text-slate-900 dark:text-white truncate max-w-[150px]">{{ anagraficaData.anagrafica.nome }}</span>
                            </div>
                        </div>
                        <div class="text-right flex flex-col items-end">
                            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-0.5">Totale Dovuto</span>
                            <span class="text-xl font-black text-red-600">{{ euro(totaleGenerale) }}</span>
                        </div>
                    </div>
                </SheetHeader>
            </div>

            <div class="p-6 space-y-6">
                <div v-for="immobile in spaccatoPerImmobile" :key="immobile.id" 
                     class="border border-slate-200 dark:border-slate-800 rounded-xl overflow-hidden bg-white dark:bg-slate-950 shadow-sm relative">
                    
                    <div class="absolute left-0 top-0 bottom-0 w-1 bg-indigo-500"></div>

                    <div class="bg-slate-50/50 dark:bg-slate-900/30 p-3 pl-4 border-b border-slate-100 dark:border-slate-800 flex justify-between items-center">
                        <div class="flex items-center gap-2">
                            <Building2 class="w-4 h-4 text-slate-400" />
                            <h4 class="text-sm font-bold text-slate-700 dark:text-slate-200">{{ immobile.nome }}</h4>
                        </div>
                        <span class="text-sm font-black text-slate-900 dark:text-white">{{ euro(immobile.totale) }}</span>
                    </div>

                    <div class="p-4 space-y-3">
                        
                        <div v-if="immobile.quote_ordinarie !== 0" class="flex justify-between items-center text-sm">
                            <div class="flex items-center gap-2 text-slate-600 dark:text-slate-400">
                                <div class="w-1.5 h-1.5 rounded-full bg-slate-300"></div>
                                Quota Ordinaria / Ad Personam
                                <TooltipProvider>
                                    <Tooltip>
                                        <TooltipTrigger><Info class="w-3 h-3 text-slate-300 cursor-help" /></TooltipTrigger>
                                        <TooltipContent><p class="text-xs max-w-[200px]">Include sia le spese ripartite a millesimi, sia eventuali spese fatturate direttamente sull'immobile (Art. 63).</p></TooltipContent>
                                    </Tooltip>
                                </TooltipProvider>
                            </div>
                            <span class="font-mono font-medium">{{ euro(immobile.quote_ordinarie) }}</span>
                        </div>

                        <div v-if="immobile.quote_saldo !== 0" class="flex justify-between items-center text-sm">
                            <div class="flex items-center gap-2 text-amber-600 dark:text-amber-500">
                                <div class="w-1.5 h-1.5 rounded-full bg-amber-400"></div>
                                Saldo Pregresso Applicato
                            </div>
                            <span class="font-mono font-medium text-amber-600">{{ euro(immobile.quote_saldo) }}</span>
                        </div>

                    </div>
                </div>
                
                <div class="bg-indigo-50/50 dark:bg-indigo-900/10 p-4 rounded-xl border border-indigo-100 dark:border-indigo-800/50 text-xs text-indigo-700 dark:text-indigo-300 flex gap-3 leading-relaxed">
                    <Info class="w-4 h-4 shrink-0 mt-0.5" />
                    <p>Questo pannello mostra la composizione matematica del debito aggregando tutte le rate del piano. Eventuali discrepanze di 1 o 2 centesimi sono dovute agli arrotondamenti per quadratura contabile.</p>
                </div>
            </div>

        </SheetContent>
    </Sheet>
</template>