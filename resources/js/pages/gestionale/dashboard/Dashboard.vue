<script setup lang="ts">

import { computed, ref } from 'vue';
import { Head, Link, InfiniteScroll } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { usePermission } from "@/composables/permissions";
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { AlertTriangle, CheckCircle2, ArrowRight, X, Wallet, Info, Lightbulb, LayoutDashboard, Zap, ShieldAlert, Inbox, TriangleAlert, CalendarClock, Loader2 } from 'lucide-vue-next';
import type { Building } from '@/types/buildings';

const props = defineProps<{
  condominio: Building;
  condomini: Building[];
  esercizio: any;
  esercizi: any[];
  copertura: {
    preventivo: number;
    pianificato: number;
    scoperto: number;
    delta: number; 
    percentuale: number;
    is_completo: boolean;
    orfani: Array<{ id: number; nome: string; importo: number; gestione: string }>;
    scoperto_count: number;
  } | null;
  // --- NUOVA PROP PER I PIANI DISALLINEATI ---
  pianiDisallineati?: Array<{ id: number; nome: string; gestione: string; delta: number }>;
  inboxTasks?: {
    total: number;
    data: Array<{
      id: number;
      title: string;
      description: string;
      action_url: string | null;
      status: string;
      context: { anagrafica_nome: string | null }
    }>;
  };
}>()

const { generatePath } = usePermission();
const { euro } = useCurrencyFormatter();
const showOrphansModal = ref(false);

const pageGuides = [
  { title: 'Bilancio Sotto Controllo', description: 'Il Validatore Budget monitora in tempo reale se le rate generate coprono tutte le spese preventivate.', icon: Zap, colorVariant: 'blue' as const },
  { title: 'Alert Operativi', description: 'Il sistema ti segnala anomalie fiscali o mancanze di fondi prima che diventino problemi contabili.', icon: ShieldAlert, colorVariant: 'amber' as const },
  { title: 'Inbox Condominiale', description: 'Gestisci le richieste e i task specifici di questo fabbricato. Le azioni rapide ti permettono di rispondere subito ai condòmini.', icon: Inbox, colorVariant: 'emerald' as const }
];

const statoCopertura = computed(() => {
    if (!props.copertura) return 'loading';
    
    // 🚨 PRIORITÀ ASSOLUTA: Se c'è un piano disallineato
    if (props.pianiDisallineati && props.pianiDisallineati.length > 0) {
        return 'misaligned';
    }

    const delta = props.copertura.delta;
    if (delta > 5) return 'deficit';
    if (delta < -5) return 'surplus';
    return 'aligned';
});

const tooltipStato = computed(() => {
    switch (statoCopertura.value) {
        case 'misaligned': return "URGENTE: Uno o più piani rate non sono più allineati con il preventivo.";
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
        return "Stai incassando più del necessario. Verifica se ci sono arrotondamenti eccessivi o voci duplicate nei piani rate. Se hai modificato il preventivo, ricorda di ricalcolare le rate per allineare tutto.";
    }
    return null;
});

// Calcolo intelligente della percentuale testuale
const percentualeFormattata = computed(() => {
    if (!props.copertura || props.copertura.preventivo === 0) return '0.0';

    // Ricalcoliamo con i decimali esatti
    const rawPct = (props.copertura.pianificato / props.copertura.preventivo) * 100;

    // SCENARIO 1 & 2: Deficit (Non deve MAI mostrare 100.0)
    if (statoCopertura.value === 'deficit' || statoCopertura.value === 'misaligned') {
        if (rawPct >= 99.9) return '99.9'; // Forza a 99.9 se manca anche solo 1 centesimo
        return rawPct.toFixed(1);
    }

    // SCENARIO 4: Surplus (Può superare il 100%)
    if (statoCopertura.value === 'surplus') {
        return rawPct.toFixed(1); 
    }

    // SCENARIO 3: Allineato perfetto
    return '100.0';
});

// Calcolo larghezza grafica della barra (massimo 100%)
const larghezzaBarra = computed(() => {
    if (!props.copertura || props.copertura.preventivo === 0) return '0%';
    const rawPct = (props.copertura.pianificato / props.copertura.preventivo) * 100;
    return Math.min(rawPct, 100) + '%';
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

            <div class="grid gap-6 md:grid-cols-3 lg:grid-cols-12 items-start">
                
                <div class="md:col-span-1 lg:col-span-4 flex flex-col gap-6">
                    
                    <div v-if="copertura" class="relative flex flex-col justify-between overflow-hidden rounded-xl border border-sidebar-border/70 bg-white dark:bg-slate-900 shadow-sm transition-all hover:shadow-md group">
                        <div class="absolute -right-6 -top-6 text-slate-50 dark:text-slate-800/50 pointer-events-none transition-colors group-hover:text-slate-100 dark:group-hover:text-slate-800">
                            <Wallet class="h-32 w-32 opacity-50" />
                        </div>
                        <div class="p-5 relative z-10">
                            <div class="flex items-center justify-between mb-4">
                                <div class="flex items-center gap-1.5">
                                    <LayoutDashboard class="w-4 h-4 text-slate-400" />
                                    <h3 class="text-xs font-bold uppercase tracking-widest text-slate-500">Copertura bilancio</h3>
                                    <TooltipProvider>
                                        <Tooltip>
                                            <TooltipTrigger><Info class="w-3.5 h-3.5 text-slate-300 hover:text-primary cursor-help" /></TooltipTrigger>
                                            <TooltipContent side="right"><p class="text-xs max-w-[200px]">Confronto tra preventivo e spese allocate nei piani rate (esclusi saldi pregressi).</p></TooltipContent>
                                        </Tooltip>
                                    </TooltipProvider>
                                </div>
                                <TooltipProvider>
                                    <Tooltip>
                                        <TooltipTrigger>
                                            <div class="flex items-center gap-1.5 px-2 py-1 rounded-full text-[10px] font-bold border cursor-help transition-colors"
                                                :class="{
                                                    'bg-red-50 text-red-700 border-red-100 hover:bg-red-100': statoCopertura === 'misaligned',
                                                    'bg-amber-50 text-amber-700 border-amber-100 hover:bg-amber-100': statoCopertura === 'deficit',
                                                    'bg-blue-50 text-blue-700 border-blue-100 hover:bg-blue-100': statoCopertura === 'surplus',
                                                    'bg-emerald-50 text-emerald-700 border-emerald-100 hover:bg-emerald-100': statoCopertura === 'aligned'
                                                }">
                                                <span class="flex h-1.5 w-1.5 rounded-full" 
                                                    :class="{'bg-red-500 animate-pulse': statoCopertura === 'misaligned', 'bg-amber-500 animate-pulse': statoCopertura === 'deficit', 'bg-blue-500': statoCopertura === 'surplus', 'bg-emerald-500': statoCopertura === 'aligned'}"></span>
                                                <span v-if="statoCopertura === 'misaligned'">DISALLINEATO</span>
                                                <span v-else-if="statoCopertura === 'deficit'">INCOMPLETO</span>
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
                                    <p class="text-lg font-black text-slate-900 dark:text-white" :class="{'text-blue-600': statoCopertura === 'surplus', 'text-red-600': statoCopertura === 'misaligned'}">{{ euro(copertura.pianificato) }}</p>
                                </div>
                            </div>

                            <div class="mb-4">
                                <div class="flex justify-between items-center mb-1">
                                    <span class="text-[9px] font-semibold uppercase text-slate-400">Copertura</span>
                                    
                                    <span class="text-[10px] font-bold tabular-nums"
                                        :class="{'text-red-600': statoCopertura === 'misaligned', 'text-amber-600': statoCopertura === 'deficit', 'text-blue-600': statoCopertura === 'surplus', 'text-emerald-600': statoCopertura === 'aligned'}">
                                        {{ percentualeFormattata }}%
                                    </span>
                                </div>
                                <div class="relative h-2.5 w-full bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
                                    
                                    <div class="h-full transition-all duration-1000 ease-in-out"
                                        :class="{'bg-red-500': statoCopertura === 'misaligned', 'bg-amber-500': statoCopertura === 'deficit', 'bg-blue-500': statoCopertura === 'surplus', 'bg-emerald-500': statoCopertura === 'aligned'}"
                                        :style="{ width: larghezzaBarra }">
                                    </div>
                                </div>
                            </div>

                            <div v-if="statoCopertura === 'misaligned'" class="bg-red-50/80 dark:bg-red-900/10 border border-red-200 dark:border-red-900/30 rounded-lg p-3">
                                <div class="flex items-center gap-2 mb-2 pb-2 border-b border-red-200/50">
                                    <ShieldAlert class="w-4 h-4 text-red-600" />
                                    <span class="text-[10px] font-black text-red-700 uppercase tracking-widest">
                                        Ricalcolo Necessario
                                    </span>
                                </div>
                                
                                <p class="text-[10px] text-red-800/80 dark:text-red-400 leading-tight mb-3">
                                    Hai modificato il preventivo spese, ma le rate generate non sono più aggiornate. Ricalcola i seguenti piani:
                                </p>

                                <div class="space-y-1.5">
                                    <div v-for="piano in props.pianiDisallineati" :key="piano.id" 
                                         class="flex items-center justify-between bg-white dark:bg-slate-800 p-2 rounded border border-red-100 dark:border-red-800">
                                        <div class="flex flex-col">
                                            <span class="text-[10px] font-bold text-slate-700 dark:text-slate-200 truncate max-w-[120px]" :title="piano.nome">{{ piano.nome }}</span>
                                            <span class="text-[9px] font-medium text-red-600">Delta: {{ piano.delta > 0 ? '+' : '' }}{{ euro(piano.delta) }}</span>
                                        </div>
                                        <Link :href="generatePath('gestionale/:condominio/esercizi/:esercizio/piani-rate/:pianoRate', { condominio: condominio.id, esercizio: esercizio.id, pianoRate: piano.id })">
                                            <Button size="sm" class="h-6 text-[9px] px-2 bg-red-600 hover:bg-red-700 text-white font-bold">
                                                Apri
                                            </Button>
                                        </Link>
                                    </div>
                                </div>
                            </div>

                            <div v-else-if="statoCopertura === 'deficit'" class="bg-amber-50/50 dark:bg-amber-900/10 border border-amber-100 dark:border-amber-900/30 rounded-lg p-3">
                                <div class="flex justify-between items-center mb-2">
                                    <span class="text-[10px] font-bold text-amber-700 uppercase flex items-center gap-1">
                                        <AlertTriangle class="w-3 h-3" /> Mancano {{ euro(copertura.delta) }}
                                    </span>
                                    <span class="text-[9px] text-amber-600/70" v-if="copertura.scoperto_count > 0">{{ copertura.scoperto_count }} voci scoperte</span>
                                </div>
                                <div class="text-[10px] text-slate-600 dark:text-slate-400 leading-tight mb-2 border-l-2 border-amber-300 pl-2">
                                    {{ suggerimentoOperativo }}
                                </div>
                                <div v-if="copertura.orfani.length > 0" class="space-y-1 mt-2 pt-2 border-t border-amber-200/50">
                                    <div v-for="item in copertura.orfani.slice(0, 2)" :key="item.id" class="flex justify-between items-center text-[10px] text-slate-600 dark:text-slate-400">
                                        <span class="truncate max-w-[120px]">{{ item.nome }}</span>
                                        <span class="font-mono">{{ euro(item.importo) }}</span>
                                    </div>
                                </div>
                            </div>

                            <div v-else-if="statoCopertura === 'surplus'" class="bg-blue-50/50 dark:bg-blue-900/10 border border-blue-100 dark:border-blue-900/30 rounded-lg p-3 flex flex-col justify-center text-blue-700">
                                <div class="flex items-center gap-2 mb-1"><Lightbulb class="w-4 h-4" /><span class="text-xs font-bold uppercase">Suggerimento</span></div>
                                <p class="text-[10px] opacity-90 leading-tight">{{ suggerimentoOperativo }}</p>
                            </div>

                            <div v-else class="bg-emerald-50/50 dark:bg-emerald-900/10 border border-emerald-100 dark:border-emerald-900/30 rounded-lg p-3 flex items-center justify-center gap-2 text-emerald-700 font-bold text-xs">
                                <CheckCircle2 class="w-4 h-4" /> Bilancio perfettamente bilanciato
                            </div>
                        </div>

                        <div class="mt-auto border-t border-slate-100 dark:border-slate-800 px-4 py-3 bg-slate-50/50 dark:bg-slate-800/50 flex items-center justify-between">
                        <span class="text-[10px] text-slate-400 font-medium">
                            {{ statoCopertura === 'misaligned' ? 'Azione critica' : statoCopertura === 'deficit' ? 'Azione richiesta' : statoCopertura === 'surplus' ? 'Verifica consigliata' : 'Tutto in ordine' }}
                        </span>
                        
                        <Button
                            v-if="copertura.orfani.length > 0 && statoCopertura !== 'misaligned'"
                            @click="showOrphansModal = true"
                            variant="outline"
                            size="sm"
                            class="h-7 text-[10px] font-bold uppercase border-amber-200 text-amber-600 hover:bg-amber-50 hover:border-amber-300 gap-1.5"
                        >
                            Analizza voci <ArrowRight class="w-3 h-3" />
                        </Button>
                        <Link v-else :href="generatePath('gestionale/:condominio/esercizi/:esercizio/piani-rate', { condominio: condominio.id, esercizio: esercizio.id })">
                            <Button
                                size="sm"
                                class="h-7 text-[10px] font-bold uppercase gap-1.5"
                                :variant="statoCopertura === 'surplus' ? 'outline' : 'default'"
                                :class="{'border-blue-200 text-blue-600 hover:bg-blue-50 hover:border-blue-300': statoCopertura === 'surplus', 'bg-red-600 hover:bg-red-700 text-white': statoCopertura === 'misaligned'}"
                            >
                                {{ statoCopertura === 'deficit' || statoCopertura === 'misaligned' ? 'Gestisci piani rate' : 'Vai ai piani rate' }}
                                <ArrowRight class="w-3 h-3" />
                            </Button>
                        </Link>
                    </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="aspect-[4/3] flex flex-col items-center justify-center rounded-xl border border-dashed border-slate-300 dark:border-slate-700 bg-slate-50/50 p-4 text-center">
                            <ShieldAlert class="w-5 h-5 text-slate-400 mb-2" />
                            <span class="text-[9px] font-bold uppercase text-slate-400 tracking-tighter">Fiscale</span>
                            <span class="text-[8px] text-slate-300 dark:text-slate-600 mt-0.5">In arrivo</span>
                        </div>
                        <div class="aspect-[4/3] flex flex-col items-center justify-center rounded-xl border border-dashed border-slate-300 dark:border-slate-700 bg-slate-50/50 p-4 text-center">
                            <Inbox class="w-5 h-5 text-slate-400 mb-2" />
                            <span class="text-[9px] font-bold uppercase text-slate-400 tracking-tighter">Fornitori</span>
                            <span class="text-[8px] text-slate-300 dark:text-slate-600 mt-0.5">In arrivo</span>
                        </div>
                    </div>
                </div>

                <div class="md:col-span-2 lg:col-span-8">
                    <div v-if="inboxTasks" class="bg-white dark:bg-slate-900 border border-sidebar-border/70 rounded-xl overflow-hidden shadow-sm flex flex-col h-[430px]">
                        
                        <div class="p-5 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between bg-slate-50/50 dark:bg-slate-900/50 shrink-0">
                            <div class="flex items-center gap-2">
                                <Inbox class="w-4 h-4 text-slate-400" />
                                <h3 class="text-xs font-bold uppercase tracking-widest text-slate-500">Inbox Operativa</h3>
                            </div>
                            <Badge v-if="inboxTasks.total > 0" variant="destructive" class="font-bold rounded-md text-[10px]">{{ inboxTasks.total }} ATTIVITÀ</Badge>
                        </div>

                        <div class="flex-1 overflow-y-auto custom-scrollbar p-2">
                            <template v-if="inboxTasks.data && inboxTasks.data.length > 0">
                                <InfiniteScroll data="inboxTasks" preserve-url>
                                    <ul role="list" class="space-y-2">
                                        <li v-for="task in inboxTasks.data" :key="task.id"
                                            class="p-4 rounded-lg border transition-all group"
                                            :class="task.status === 'expired'
                                                ? 'border-y-transparent border-r-transparent bg-red-100/20 dark:bg-red-900/10'
                                                : 'border-transparent hover:border-slate-200 dark:hover:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800/50'"
                                        >
                                            <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-4">
                                                <div class="flex items-start gap-3 flex-1">
                                                    <div class="mt-1 shrink-0 text-slate-400">
                                                        <TriangleAlert v-if="task.status === 'expired'" class="w-5 h-5 text-red-500" />
                                                        <CalendarClock v-else class="w-5 h-5" />
                                                    </div>
                                                    <div class="flex-1">
                                                        <h3 class="text-sm font-bold text-slate-900 dark:text-white" :class="{'text-red-700 dark:text-red-400': task.status === 'expired'}">{{ task.title }}</h3>
                                                        <p class="text-[13px] text-slate-500 dark:text-slate-400 line-clamp-2 mt-1 leading-relaxed pr-6">{{ task.description }}</p>
                                                        <div class="flex items-center gap-3 mt-2 text-[10px] font-bold uppercase text-slate-400">
                                                            <span v-if="task.status === 'expired'" class="text-red-500 flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full bg-red-500 animate-pulse"></span> SCADUTO</span>
                                                            <span v-else class="text-emerald-500 flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> DA FARE</span>
                                                            <span v-if="task.context.anagrafica_nome">• {{ task.context.anagrafica_nome }}</span>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="shrink-0">
                                                    <a v-if="task.action_url" :href="task.action_url" 
                                                        class="inline-flex items-center justify-center h-7 px-3 text-xs font-bold transition-colors rounded-lg border"
                                                        :class="task.status === 'expired' 
                                                            ? 'border-red-200 text-red-600 hover:bg-red-50 dark:border-red-800 dark:text-red-400 dark:hover:bg-red-900/20' 
                                                            : 'border-slate-200 text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800'">
                                                        Risolvi <ArrowRight class="w-3 h-3 ml-1.5" />
                                                    </a>
                                                </div>
                                            </div>
                                        </li>
                                    </ul>
                                    <template #loading>
                                        <div class="py-6 flex items-center justify-center text-slate-400"><Loader2 class="w-5 h-5 animate-spin" /></div>
                                    </template>
                                </InfiniteScroll>
                            </template>
                            
                            <div v-else class="h-full flex flex-col items-center justify-center p-12 text-center">
                                <CheckCircle2 class="w-12 h-12 text-emerald-500/20 mb-4" />
                                <h3 class="text-sm font-black text-slate-400 uppercase tracking-widest">Inbox Vuota</h3>
                            </div>
                        </div>
                    </div>
                </div> 

            </div>
        </div>

        <Transition enter-active-class="transition duration-200 ease-out" enter-from-class="opacity-0 scale-95" enter-to-class="opacity-100 scale-100" leave-active-class="transition duration-150 ease-in" leave-from-class="opacity-100 scale-100" leave-to-class="opacity-0 scale-95">
            <div v-if="showOrphansModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/40 backdrop-blur-sm p-4" @click.self="showOrphansModal = false">
                <div class="w-full max-w-lg overflow-hidden rounded-2xl bg-white dark:bg-slate-900 shadow-2xl">
                    <div class="flex items-center justify-between border-b p-6">
                        <div>
                            <h3 class="text-lg font-black text-slate-900 dark:text-white">Audit spese scoperte</h3>
                            <p class="text-xs text-slate-500 uppercase font-bold tracking-tight">Elenco voci del preventivo non coperte o parzialmente scoperte</p>
                        </div>
                        <button @click="showOrphansModal = false" class="rounded-full p-2 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                            <X class="w-5 h-5" />
                        </button>
                    </div>
                    <div class="p-6">
                        <div class="bg-blue-50 border border-blue-100 rounded-lg p-3 mb-4 text-xs text-blue-700">
                            <strong class="font-bold block mb-1">Cosa fare?</strong>
                            Queste voci esistono nel piano dei conti ma non sono state assegnate a nessun piano rate.
                            Vai nella sezione <strong>piani rate</strong>, entra in un piano (o creane uno nuovo) e usa la funzione <strong>"Sincronizza"</strong> o <strong>"Aggiungi voce"</strong>.
                        </div>
                        <div class="space-y-3 max-h-[300px] overflow-y-auto pr-2 custom-scrollbar">
                            <div v-for="orfano in copertura?.orfani" :key="orfano.id" class="group flex justify-between items-center p-4 border rounded-xl bg-slate-50 dark:bg-slate-800/50 hover:border-amber-200 dark:hover:border-amber-900/50 transition-all">
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
                            <Link :href="generatePath('gestionale/:condominio/esercizi/:esercizio/piani-rate', { condominio: condominio.id, esercizio: esercizio.id })" class="flex-1">
                                <Button class="w-full font-bold text-xs uppercase h-10 bg-amber-600 hover:bg-amber-700">Risolvi</Button>
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </Transition>
    </GestionaleLayout>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar { width: 4px; }
.custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }
.dark .custom-scrollbar::-webkit-scrollbar-thumb { background: #334155; }
</style>