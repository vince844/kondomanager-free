<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { usePermission } from "@/composables/permissions";
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { 
  AlertTriangle, CheckCircle2, ArrowRight, X, Wallet, 
  Info, Lightbulb, LayoutDashboard, Zap, ShieldAlert, Inbox 
} from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';

import type { Building } from '@/types/buildings';

const props = defineProps<{
  condominio: Building;
  condomini: Building[];
  esercizio: any;
  esercizi: any[]; // Assicurati che arrivino dal controller
  copertura: {
    preventivo: number;
    pianificato: number;
    scoperto: number;
    delta: number; 
    percentuale: number;
    is_completo: boolean;
    orfani: Array<{ 
        id: number; 
        nome: string; 
        importo: number;
        gestione: string 
    }>;
    scoperto_count: number;
  } | null;
}>()

const { generatePath } = usePermission();
const { euro } = useCurrencyFormatter();
const showOrphansModal = ref(false);

// GUIDA STRATEGICA CON TERZO BLOCCO INBOX
const pageGuides = [
  {
    title: 'Bilancio Sotto Controllo',
    description: 'Il Validatore Budget monitora in tempo reale se le rate generate coprono tutte le spese preventivate.',
    icon: Zap,
    colorVariant: 'blue' as const
  },
  {
    title: 'Alert Operativi',
    description: 'Il sistema ti segnala anomalie fiscali o mancanze di fondi prima che diventino problemi contabili.',
    icon: ShieldAlert,
    colorVariant: 'amber' as const
  },
  {
    title: 'Inbox Condominiale',
    description: 'Gestisci le richieste e i task specifici di questo fabbricato. Le azioni rapide ti permettono di rispondere subito ai condòmini.',
    icon: Inbox,
    colorVariant: 'emerald' as const
  }
];

// LA TUA LOGICA ORIGINALE (NON TOCCATA)
const statoCopertura = computed(() => {
    if (!props.copertura) return 'loading';
    const delta = props.copertura.delta;
    if (delta > 5) return 'deficit';
    if (delta < -5) return 'surplus';
    return 'aligned';
});

const tooltipStato = computed(() => {
    switch (statoCopertura.value) {
        case 'deficit': return "Attenzione: Le rate emesse non coprono tutte le spese previste. Rischio buco di bilancio.";
        case 'surplus': return "Nota: L'importo richiesto ai condomini supera il preventivo spese.";
        case 'aligned': return "Ottimo! Le rate coprono perfettamente il preventivo di spesa.";
        default: return "Stato calcolo copertura.";
    }
});

const suggerimentoOperativo = computed(() => {
    if (statoCopertura.value === 'deficit') {
        if (props.copertura?.scoperto_count && props.copertura.scoperto_count > 0) {
            return "Hai voci di spesa non associate. Aggiungile a un piano rate esistente o creane uno nuovo.";
        } else {
            return "Il preventivo è aumentato. Vai nel Piano Rate e clicca 'Ricalcola' per aggiornare le rate. Se le rate sono già emesse, crea una nuova voce di spesa per la differenza.";
        }
    }
    if (statoCopertura.value === 'surplus') {
        return "Stai incassando più del necessario. Verifica se ci sono arrotondamenti eccessivi o voci duplicate nei piani rate.";
    }
    return null;
});
</script>

<template>
    <Head title="Dashboard gestionale" />

    <GestionaleLayout :breadcrumbs="[]">
        <div class="px-6 py-8 space-y-6">
            
            <PageHeaderGuide
                page-title="Dashboard gestionale"
                page-subtitle="Monitora la salute contabile e fiscale del condominio in tempo reale."
                :guides="pageGuides"
                :breadcrumbs="[]" 
                :condominio="props.condominio"
                :condomini="props.condomini"
                :esercizio="props.esercizio"
                :esercizi="props.esercizi"
            />

            <div class="grid auto-rows-min gap-4 md:grid-cols-3">
                
                <div v-if="copertura" class="relative flex flex-col justify-between overflow-hidden rounded-xl border border-sidebar-border/70 bg-white dark:bg-slate-900 shadow-sm transition-all hover:shadow-md group">
        
                    <div class="absolute -right-6 -top-6 text-slate-50 dark:text-slate-800/50 pointer-events-none transition-colors group-hover:text-slate-100 dark:group-hover:text-slate-800">
                        <Wallet class="h-32 w-32 opacity-50" />
                    </div>

                    <div class="p-5 relative z-10">
                        <div class="flex items-center justify-between mb-4">
                            <div class="flex items-center gap-1.5">
                                <LayoutDashboard class="w-4 h-4 text-slate-400" /> <h3 class="text-xs font-bold uppercase tracking-widest text-slate-500">Copertura bilancio</h3>
                                <TooltipProvider>
                                    <Tooltip>
                                        <TooltipTrigger><Info class="w-3.5 h-3.5 text-slate-300 hover:text-primary cursor-help" /></TooltipTrigger>
                                        <TooltipContent side="right"><p class="text-xs max-w-[200px]">Confronto tra spese e rate generate.</p></TooltipContent>
                                    </Tooltip>
                                </TooltipProvider>
                            </div>
                            
                            <TooltipProvider>
                                <Tooltip>
                                    <TooltipTrigger>
                                        <div class="flex items-center gap-1.5 px-2 py-1 rounded-full text-[10px] font-bold border"
                                            :class="{
                                                'bg-amber-50 text-amber-700 border-amber-100': statoCopertura === 'deficit',
                                                'bg-blue-50 text-blue-700 border-blue-100': statoCopertura === 'surplus',
                                                'bg-emerald-50 text-emerald-700 border-emerald-100': statoCopertura === 'aligned'
                                            }">
                                            <span class="flex h-1.5 w-1.5 rounded-full" :class="{'bg-amber-500 animate-pulse': statoCopertura === 'deficit', 'bg-blue-500': statoCopertura === 'surplus', 'bg-emerald-500': statoCopertura === 'aligned'}"></span>
                                            <span v-if="statoCopertura === 'deficit'">INCOMPLETO</span>
                                            <span v-else-if="statoCopertura === 'surplus'">ECCEDENZA</span>
                                            <span v-else>ALLINEATO</span>
                                        </div>
                                    </TooltipTrigger>
                                    <TooltipContent><p class="text-xs">{{ tooltipStato }}</p></TooltipContent>
                                </Tooltip>
                            </TooltipProvider>
                        </div>
                        
                        <div class="grid grid-cols-2 gap-4 mb-4">
                            <div>
                                <p class="text-[10px] text-slate-400 uppercase font-semibold">Totale preventivo</p>
                                <p class="text-lg font-black text-slate-700 dark:text-slate-200">{{ euro(copertura.preventivo) }}</p>
                            </div>
                            <div class="text-right">
                                <p class="text-[10px] text-slate-400 uppercase font-semibold">Pianificato (Rate)</p>
                                <p class="text-lg font-black text-slate-900 dark:text-white" :class="{'text-blue-600': statoCopertura === 'surplus'}">{{ euro(copertura.pianificato) }}</p>
                            </div>
                        </div>

                        <div class="relative h-2 w-full bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden mb-4">
                            <div class="h-full transition-all duration-1000 ease-in-out" :class="{'bg-amber-500': statoCopertura === 'deficit', 'bg-blue-500': statoCopertura === 'surplus', 'bg-emerald-500': statoCopertura === 'aligned'}" :style="{ width: Math.min(copertura.percentuale, 100) + '%' }"></div>
                        </div>

                        <div v-if="statoCopertura === 'deficit'" class="bg-amber-50/50 dark:bg-amber-900/10 border border-amber-100 dark:border-amber-900/30 rounded-lg p-3">
                            <div class="flex justify-between items-center mb-2">
                                <span class="text-[10px] font-bold text-amber-700 uppercase flex items-center gap-1"><AlertTriangle class="w-3 h-3" /> Mancano {{ euro(copertura.delta) }}</span>
                                <span class="text-[9px] text-amber-600/70" v-if="copertura.scoperto_count > 0">{{ copertura.scoperto_count }} voci scoperte</span>
                            </div>
                            <div class="text-[10px] text-slate-600 dark:text-slate-400 leading-tight mb-2 border-l-2 border-amber-300 pl-2">{{ suggerimentoOperativo }}</div>
                            <div v-if="copertura.orfani.length > 0" class="space-y-1 mt-2 pt-2 border-t border-amber-200/50">
                                <div v-for="item in copertura.orfani.slice(0, 2)" :key="item.id" class="flex justify-between items-center text-[10px] text-slate-600 dark:text-slate-400">
                                    <span class="truncate max-w-[120px]">{{ item.nome }}</span>
                                    <span class="font-mono">{{ euro(item.importo) }}</span>
                                </div>
                            </div>
                        </div>

                        <div v-else-if="statoCopertura === 'surplus'" class="bg-blue-50/50 dark:bg-blue-900/10 border border-blue-100 dark:border-blue-900/30 rounded-lg p-3 flex flex-col justify-center text-blue-700">
                            <div class="flex items-center gap-2 mb-1"><Lightbulb class="w-4 h-4" /> <span class="text-xs font-bold uppercase">Suggerimento</span></div>
                            <p class="text-[10px] opacity-90 leading-tight">{{ suggerimentoOperativo }}</p>
                        </div>

                        <div v-else class="bg-emerald-50/50 dark:bg-emerald-900/10 border border-emerald-100 dark:border-emerald-900/30 rounded-lg p-3 flex items-center justify-center gap-2 text-emerald-700 font-bold text-xs"><CheckCircle2 class="w-4 h-4" /> Bilancio perfettamente bilanciato</div>
                    </div>

                    <div class="mt-auto border-t p-3 bg-slate-50/50 dark:bg-slate-800/50 flex justify-end">
                        <Button v-if="copertura.orfani.length > 0" @click="showOrphansModal = true" variant="ghost" size="sm" class="text-amber-600 hover:text-amber-700 font-bold uppercase text-[10px]">Analizza voci <ArrowRight class="w-3 h-3 ml-1" /></Button>
                        <Link v-else :href="generatePath('gestionale/:condominio/esercizi/:esercizio/piani-rate', { condominio: condominio.id, esercizio: esercizio.id })"><Button variant="ghost" size="sm" class="text-slate-500 font-bold uppercase text-[10px]">Vai ai piani rate</Button></Link>
                    </div>
                </div>

                <div class="relative aspect-video flex flex-col items-center justify-center rounded-xl border border-dashed border-slate-300 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-900/50 p-6 text-center">
                    <ShieldAlert class="w-6 h-6 text-slate-400 mb-2" />
                    <span class="text-[10px] font-bold uppercase text-slate-400">Modulo Fiscale in arrivo</span>
                    <p class="text-[9px] text-slate-400 mt-1 italic">Gestione F24 e CU (V. 1.9)</p>
                </div>
                <div class="relative aspect-video flex flex-col items-center justify-center rounded-xl border border-dashed border-slate-300 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-900/50 p-6 text-center">
                    <Inbox class="w-6 h-6 text-slate-400 mb-2" />
                    <span class="text-[10px] font-bold uppercase text-slate-400">Modulo Fornitori in arrivo</span>
                    <p class="text-[9px] text-slate-400 mt-1 italic">Gestione flussi e scadenze</p>
                </div>

            </div>
        </div>

        <Transition enter-active-class="transition duration-200 ease-out" enter-from-class="opacity-0 scale-95" enter-to-class="opacity-100 scale-100" leave-active-class="transition duration-150 ease-in" leave-from-class="opacity-100 scale-100" leave-to-class="opacity-0 scale-95">
            <div v-if="showOrphansModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/40 backdrop-blur-sm p-4" @click.self="showOrphansModal = false">
                <div class="w-full max-w-lg overflow-hidden rounded-2xl bg-white dark:bg-slate-900 shadow-2xl">
                    <div class="flex items-center justify-between border-b p-6">
                        <div>
                            <h3 class="text-lg font-black text-slate-900 dark:text-white">Audit spese scoperte</h3>
                            <p class="text-xs text-slate-500 uppercase font-bold tracking-tight">Voci del preventivo senza copertura</p>
                        </div>
                        <button @click="showOrphansModal = false" class="rounded-full p-2 text-slate-400 hover:bg-slate-100 transition-colors">
                            <X class="w-5 h-5" /> </button>
                    </div>
                    <div class="p-6">
                        <div class="space-y-3 max-h-[300px] overflow-y-auto pr-2 custom-scrollbar">
                            <div v-for="orfano in copertura?.orfani" :key="orfano.id" class="flex justify-between items-center p-4 border rounded-xl bg-slate-50 dark:bg-slate-800/50">
                                <div class="flex items-center gap-3">
                                    <div class="bg-amber-100 dark:bg-amber-900/30 p-2 rounded-lg text-amber-600"><Wallet class="w-4 h-4" /></div>
                                    <div>
                                        <p class="text-sm font-black text-slate-800 dark:text-slate-200">{{ orfano.nome }}</p>
                                        <p class="text-[10px] font-bold uppercase text-slate-400">{{ orfano.gestione }}</p>
                                    </div>
                                </div>
                                <span class="text-sm font-mono font-black">{{ euro(orfano.importo) }}</span>
                            </div>
                        </div>
                        <div class="mt-8 flex gap-3">
                            <Button variant="outline" class="flex-1 font-bold text-xs uppercase h-10" @click="showOrphansModal = false">Chiudi</Button>
                            <Link :href="generatePath('gestionale/:condominio/esercizi/:esercizio/piani-rate', { condominio: condominio.id, esercizio: esercizio.id })" class="flex-1"><Button class="w-full font-bold text-xs uppercase h-10 bg-amber-600">Risolvi</Button></Link>
                        </div>
                    </div>
                </div>
            </div>
        </Transition>
    </GestionaleLayout>
</template>