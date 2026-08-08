<script setup lang="ts">
import { computed, ref, onMounted, onUnmounted } from 'vue';
import { Head, Link, InfiniteScroll, router, useForm } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { usePermission } from "@/composables/permissions";
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { AlertTriangle, CheckCircle2, ArrowRight, X, Wallet, Info, Lightbulb, LayoutDashboard, Zap, ShieldAlert, Inbox, TriangleAlert, CalendarClock, Loader2, XCircle, TrendingDown, User, ArrowDownToLine, ArrowUpFromLine, Banknote, MessageSquare, Wrench, BookOpen } from 'lucide-vue-next';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import TreasuryGuardianWidget from './components/TreasuryGuardianWidget.vue';
import ControlliPostImportWidget from '@/pages/gestionale/dashboard/components/ControlliPostImportWidget.vue';
import CreditiDaCompensareWidget from './components/CreditiDaCompensareWidget.vue';
import type { Building } from '@/types/buildings';
import type { Esercizio } from '@/types/gestionale/esercizi';

const props = defineProps<{
  condominio: Building;
  condomini: Building[];
  esercizio: Esercizio;    
  esercizi: Esercizio[];   
  copertura: {
    preventivo: number;
    pianificato: number;
    virtuale: number;
    addebiti_personali: number; 
    uscite_totali: number;      
    scoperto: number;
    delta: number; 
    percentuale: number;
    is_completo: boolean;
    has_sforo: boolean;
    has_scoperti_documentati?: boolean;
    orfani: Array<{ 
        id: number; 
        nome: string; 
        importo: number; 
        is_sforo?: boolean;
        strategia: string;
        gestione: string;
        gestione_id?: number;
    }>;
    scoperto_count: number;
  } | null;
  pianiDisallineati?: Array<{ 
    id: number; 
    nome: string; 
    gestione: string; 
    delta: number 
  }>;
  fattureScoperte?: Array<{
    id: number;
    numero: string;
    fornitore: string;
    totale_scoperto: number;
    gestione_id: number | null;
    strategia: string;
    righe: Array<{
        id: number;
        tipo: string;
        importo: number;
        descrizione: string;
        dettaglio: string;
    }>;
  }>;
  inboxTasks?: {
    total: number;
    data: Array<{
      id: number;
      type: string;
      title: string;
      description: string;
      action_url: string | null;
      status: string;
      days_pending?: number;
      context: { anagrafica_nome: string | null }
    }>;
  };
  widgets?: Record<string, any>;
}>()

const { generatePath } = usePermission();
const { euro } = useCurrencyFormatter();
const showOrphansModal = ref(false);

const pageGuides = [
  { title: 'Bilancio Sotto Controllo', description: 'Il Validatore Budget monitora in tempo reale se le rate generate coprono tutte le spese preventivate.', icon: Zap, colorVariant: 'blue' as const },
  { title: 'Alert Operativi', description: 'Il sistema ti segnala anomalie fiscali o mancanze di fondi prima che diventino problemi contabili.', icon: ShieldAlert, colorVariant: 'amber' as const },
  { title: 'Inbox Condominiale', description: 'Gestisci le richieste e i task specifici di questo fabbricato. Le azioni rapide ti permettono di rispondere subito ai condòmini.', icon: Inbox, colorVariant: 'emerald' as const }
];

type StatoCopertura = 'loading' | 'misaligned' | 'deficit' | 'documented' | 'surplus' | 'integrated' | 'aligned';

// FIX: Il backend ora invia il pianificato GIA' PURIFICATO dalle spese private.
const pianificatoCondiviso = computed(() => {
    return props.copertura ? props.copertura.pianificato : 0;
});

// FIX: Usiamo direttamente il delta esatto calcolato dal backend per la barra globale
const deltaReale = computed(() => {
    return props.copertura ? props.copertura.delta : 0;
});

// NUOVO: Calcoliamo lo "Scoperto Operativo" (la somma esatta dei buchi reali da finanziare, ignorando i surplus)
const totaleScopertoOperativo = computed(() => {
    if (!props.copertura || !props.copertura.orfani) return 0;
    
    return props.copertura.orfani
        .filter(o => !['conguaglio', 'fondo_riserva'].includes(o.strategia))
        .reduce((sum, o) => sum + Number(o.importo), 0);
});

const statoCopertura = computed<StatoCopertura>(() => {
    if (!props.copertura) return 'loading';
    if (props.pianiDisallineati && props.pianiDisallineati.length > 0) return 'misaligned';

    // Il Deficit ora scatta se c'è anche solo 1 euro di buco operativo reale, ignorando eventuali surplus di altre voci
    if (totaleScopertoOperativo.value > 0) {
        // Se il buco è già stato documentato consapevolmente dall'admin, non mostrare allarme
        // ATTENZIONE: vale solo se non ci sono nuove voci intere da finanziare (scoperto_count === 0)
        if (props.copertura.has_scoperti_documentati && props.copertura.scoperto_count === 0) return 'documented';
        return 'deficit';
    }

    if (props.copertura.orfani.some(o => ['conguaglio', 'fondo_riserva'].includes(o.strategia))) return 'integrated';
    
    if (deltaReale.value < -500) return 'surplus';  
    
    return 'aligned'; 
});

const tooltipStato = computed(() => {
    switch (statoCopertura.value) {
        case 'misaligned':  return "URGENTE: Uno o più piani rate non sono più allineati con il preventivo.";
        case 'deficit':     return "Attenzione: Le rate emesse non coprono il fabbisogno reale. Rischio mancanza liquidità.";
        case 'documented':  return "Fabbisogno scoperto documentato: l'amministratore ha registrato la motivazione. Verificare le anagrafiche mancanti.";
        case 'surplus':     return "Stai incassando più del fabbisogno reale (preventivo + fatture). Verifica arrotondamenti.";
        case 'integrated':  return "Ottimo lavoro. Hai emesso rate integrative o usato fondi sufficienti a coprire gli sforamenti di budget registrati.";
        case 'aligned':     return "Il piano rate copre esattamente il preventivo di spesa originale.";
        default: return "";
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
    if (statoCopertura.value === 'documented') {
        return "Il fabbisogno scoperto è stato registrato con motivazione. Censisci l'anagrafica mancante: l'importo sarà recuperato a conguaglio o tramite addebito manuale.";
    }
    if (statoCopertura.value === 'surplus') {
        return "Stai incassando più del necessario. Verifica se ci sono arrotondamenti eccessivi o voci duplicate nei piani rate. Se hai modificato il preventivo, ricorda di ricalcolare le rate per allineare tutto.";
    }
    return null;
});

const percentualeFormattata = computed(() => {
    if (!props.copertura || props.copertura.preventivo === 0) return '0.0';
    const totaleCoperto = pianificatoCondiviso.value + (props.copertura.virtuale || 0);
    const rawPct = (totaleCoperto / props.copertura.preventivo) * 100;

    if (statoCopertura.value === 'deficit' || statoCopertura.value === 'documented' || statoCopertura.value === 'misaligned') {
        if (rawPct >= 99.9) return '99.9'; 
        return rawPct.toFixed(1);
    }
    if (statoCopertura.value === 'surplus') return rawPct.toFixed(1); 
    return '100.0';
});

const larghezzaBarra = computed(() => {
    if (!props.copertura || props.copertura.preventivo === 0) return '0%';
    const totaleCoperto = pianificatoCondiviso.value + (props.copertura.virtuale || 0);
    const rawPct = (totaleCoperto / props.copertura.preventivo) * 100;
    return Math.min(rawPct, 100) + '%';
});

const completeTask = (taskId: number) => {
    router.patch(route('admin.inbox.complete', { task: taskId }), {}, { preserveScroll: true });
};

const isRejectModalOpen = ref(false);
const taskToReject = ref<any>(null);
const rejectForm = useForm({ reason: '' });

const openRejectModal = (task: any) => {
    taskToReject.value = task;
    rejectForm.reason = ''; 
    rejectForm.clearErrors();
    isRejectModalOpen.value = true;
};

const closeRejectModal = () => {
    isRejectModalOpen.value = false;
    setTimeout(() => taskToReject.value = null, 300); 
};

const confirmReject = () => {
    if (!taskToReject.value) return;
    rejectForm.post(route('admin.inbox.reject', taskToReject.value.id), {
        preserveScroll: true,
        onSuccess: () => closeRejectModal(),
    });
};

// Altezza di Inbox Operativa agganciata a quella reale di Copertura Bilancio.
// CSS puro (grid items-stretch) non basta: fa crescere il piu' corto per
// raggiungere il piu' alto, mai il contrario — se Inbox ha molte attivita'
// reali, sfonderebbe qualunque limite. Misuriamo Copertura Bilancio col
// ResizeObserver e capiamo Inbox a quell'altezza, con scroll interno.
const coperturaCardRef = ref<HTMLElement | null>(null);
const coperturaHeight = ref<number | null>(null);
let coperturaResizeObserver: ResizeObserver | null = null;

onMounted(() => {
    if (!coperturaCardRef.value) return;
    coperturaResizeObserver = new ResizeObserver((entries) => {
        coperturaHeight.value = entries[0].contentRect.height;
    });
    coperturaResizeObserver.observe(coperturaCardRef.value);
});

onUnmounted(() => {
    coperturaResizeObserver?.disconnect();
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

            <div class="grid gap-3 md:grid-cols-3 lg:grid-cols-12 items-stretch">

                <div class="md:col-span-1 lg:col-span-4 flex flex-col gap-6">
                    
                    <div v-if="copertura" ref="coperturaCardRef" class="relative flex flex-col justify-between overflow-hidden rounded-xl border border-sidebar-border/70 bg-white dark:bg-slate-900 shadow-sm transition-all hover:shadow-md group">
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
                                            <TooltipContent side="right"><p class="text-xs max-w-[200px]">Rapporto tra il fabbisogno condiviso del condominio e le coperture attive (rate e fondi).</p></TooltipContent>
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
                                                    'bg-slate-50 text-slate-600 border-slate-200 hover:bg-slate-100': statoCopertura === 'documented',
                                                    'bg-blue-50 text-blue-700 border-blue-100 hover:bg-blue-100': statoCopertura === 'surplus',
                                                    'bg-indigo-50 text-indigo-700 border-indigo-100 hover:bg-indigo-100': statoCopertura === 'integrated',
                                                    'bg-emerald-50 text-emerald-700 border-emerald-100 hover:bg-emerald-100': statoCopertura === 'aligned'
                                                }">
                                                <span class="flex h-1.5 w-1.5 rounded-full" 
                                                    :class="{'bg-red-500 animate-pulse': statoCopertura === 'misaligned', 'bg-amber-500 animate-pulse': statoCopertura === 'deficit', 'bg-slate-400': statoCopertura === 'documented', 'bg-blue-500': statoCopertura === 'surplus', 'bg-indigo-500': statoCopertura === 'integrated', 'bg-emerald-500': statoCopertura === 'aligned'}"></span>
                                                <span v-if="statoCopertura === 'misaligned'">Disallineato</span>
                                                <span v-else-if="statoCopertura === 'deficit'">Fabbisogno scoperto</span>
                                                <span v-else-if="statoCopertura === 'documented'">Scoperto documentato</span>
                                                <span v-else-if="statoCopertura === 'surplus'">Eccedenza</span>
                                                <span v-else-if="statoCopertura === 'integrated'">Integrato</span>
                                                <span v-else>Allineato</span>
                                            </div>
                                        </TooltipTrigger>
                                        <TooltipContent><p class="text-xs">{{ tooltipStato }}</p></TooltipContent>
                                    </Tooltip>
                                </TooltipProvider>
                            </div>

                            <div class="mb-5">
                                <div class="grid grid-cols-2 gap-3 mb-3">
    
                                    <div class="bg-slate-50/50 dark:bg-slate-800/30 p-2.5 rounded-xl border border-slate-100 dark:border-slate-800 flex flex-col justify-center">
                                        <div class="flex items-center gap-1.5 mb-1.5">
                                            <p class="text-[9px] text-slate-400 uppercase font-black tracking-widest">Fabbisogno Condiviso</p>
                                            <TooltipProvider>
                                                <Tooltip>
                                                    <TooltipTrigger><Info class="w-3 h-3 text-slate-300" /></TooltipTrigger>
                                                    <TooltipContent><p class="text-xs max-w-[200px]">Fabbisogno reale condominiale (preventivo + sfori autorizzati). Esclude categoricamente le spese ad personam.</p></TooltipContent>
                                                </Tooltip>
                                            </TooltipProvider>
                                        </div>
                                        <p class="text-base font-black text-slate-700 dark:text-slate-200 leading-none">
                                            {{ euro(copertura.preventivo) }}
                                        </p>
                                    </div>
                                    
                                    <div class="bg-slate-50/50 dark:bg-slate-800/30 p-2.5 rounded-xl border border-slate-100 dark:border-slate-800 flex flex-col justify-center">
    
                                        <div class="flex items-center gap-1.5 mb-1.5">
                                            <p class="text-[9px] text-slate-400 uppercase font-black tracking-widest">Coperture Attive</p>
                                            <TooltipProvider>
                                                <Tooltip>
                                                    <TooltipTrigger><Info class="w-3 h-3 text-slate-300" /></TooltipTrigger>
                                                    <TooltipContent>
                                                        <p class="text-xs max-w-[200px]">Somma delle rate emesse e degli stanziamenti extra (fondi o conguaglio) deliberati per coprire il fabbisogno.</p>
                                                    </TooltipContent>
                                                </Tooltip>
                                            </TooltipProvider>
                                        </div>

                                        <div class="space-y-2">
                                            <div class="flex justify-between items-center gap-1.5 w-full">
                                                <p class="text-[9px] text-slate-500 uppercase font-bold truncate">Rate</p>
                                                <p class="text-sm font-black text-slate-900 dark:text-white shrink-0 whitespace-nowrap leading-none" 
                                                :class="{'text-blue-600': statoCopertura === 'surplus', 'text-red-600': statoCopertura === 'misaligned'}">
                                                    {{ euro(copertura.pianificato) }}
                                                </p>
                                            </div>
                                            
                                            <div v-if="copertura.virtuale > 0" class="flex justify-between items-center gap-1.5 w-full pt-2 border-t border-slate-200 dark:border-slate-700/50">
                                                <div class="flex items-center gap-1 min-w-0">
                                                    <p class="text-[9px] text-emerald-600/90 uppercase font-bold truncate">Fondi/Extra</p>
                                                    <TooltipProvider>
                                                        <Tooltip>
                                                            <TooltipTrigger as-child>
                                                                <Info class="w-3 h-3 text-emerald-500/60 cursor-help outline-none shrink-0" />
                                                            </TooltipTrigger>
                                                            <TooltipContent>
                                                                <p class="text-xs">Importo coperto tramite l'utilizzo di fondi di riserva o stanziato per il conguaglio di fine anno.</p>
                                                            </TooltipContent>
                                                        </Tooltip>
                                                    </TooltipProvider>
                                                </div>
                                                <p class="text-xs font-black text-emerald-600 shrink-0 whitespace-nowrap leading-none">
                                                    + {{ euro(copertura.virtuale) }}
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-4">
                                    <div class="flex justify-between items-center mb-1.5 px-0.5">
                                        <span class="text-[8px] font-black uppercase text-slate-400 tracking-widest">Avanzamento Coperture Condivise</span>
                                        <span class="text-[10px] font-bold tabular-nums"
                                            :class="{'text-red-600': statoCopertura === 'misaligned', 'text-amber-600': statoCopertura === 'deficit', 'text-blue-600': statoCopertura === 'surplus', 'text-indigo-600': statoCopertura === 'integrated', 'text-emerald-600': statoCopertura === 'aligned'}">
                                            {{ percentualeFormattata }}%
                                        </span>
                                    </div>
                                    <div class="relative h-2 w-full bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden shadow-inner">
                                        <div class="h-full transition-all duration-1000 ease-in-out"
                                            :class="{'bg-red-500': statoCopertura === 'misaligned', 'bg-amber-500': statoCopertura === 'deficit', 'bg-blue-500': statoCopertura === 'surplus', 'bg-indigo-500': statoCopertura === 'integrated', 'bg-emerald-500': statoCopertura === 'aligned'}"
                                            :style="{ width: larghezzaBarra }">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-4 pt-4 border-t border-dashed border-slate-200 dark:border-slate-800">
                                <h4 class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2.5">Proiezione Uscite (Mastro C/C)</h4>
                                
                                <div class="space-y-1">
                                    <div class="flex justify-between items-center px-1">
                                        <span class="text-[10px] font-medium text-slate-500">Fatturato condiviso</span>
                                        <span class="text-[10px] font-bold text-slate-600">
                                            {{ euro(copertura.uscite_totali - copertura.addebiti_personali) }}
                                        </span>
                                    </div>
                                    
                                    <div v-if="copertura.addebiti_personali > 0" class="flex justify-between items-center bg-amber-50/50 dark:bg-amber-900/10 px-2 py-1.5 rounded-md border border-amber-100 dark:border-amber-800/50 mt-1.5">
                                        <div class="flex items-center gap-1.5">
                                            <User class="w-3 h-3 text-amber-500" />
                                            <span class="text-[9px] font-bold text-amber-700 dark:text-amber-500 uppercase tracking-wide">Addebiti Diretti (Art. 63)</span>
                                            <TooltipProvider>
                                                <Tooltip>
                                                    <TooltipTrigger><Info class="w-3 h-3 text-amber-600/70" /></TooltipTrigger>
                                                    <TooltipContent><p class="text-xs">Spese fatturate al condominio ma addebitate esclusivamente ai singoli proprietari.</p></TooltipContent>
                                                </Tooltip>
                                            </TooltipProvider>
                                        </div>
                                        <span class="text-[10px] font-black text-amber-600">+ {{ euro(copertura.addebiti_personali) }}</span>
                                    </div>
                                    
                                    <div class="flex justify-between items-center pt-2 mt-2 border-t border-slate-100 dark:border-slate-800 px-1">
                                        <div class="flex items-center gap-1.5">
                                            <span class="text-[10px] font-black uppercase text-slate-800 dark:text-slate-200 tracking-wider">Totale da pagare (Fornitori)</span>
                                            <TooltipProvider>
                                                <Tooltip>
                                                    <TooltipTrigger><Info class="w-3 h-3 text-slate-300" /></TooltipTrigger>
                                                    <TooltipContent><p class="text-xs max-w-[200px]">Totale delle uscite lorde attese sul conto corrente. Questo importo corrisponde esattamente al totale indicato nel Piano dei Conti.</p></TooltipContent>
                                                </Tooltip>
                                            </TooltipProvider>
                                        </div>
                                        <span class="text-sm font-black text-slate-900 dark:text-white">{{ euro(copertura.uscite_totali) }}</span>
                                    </div>
                                </div>
                            </div>

                            <div v-if="statoCopertura === 'misaligned'" class="bg-red-50/80 dark:bg-red-900/10 border border-red-200 dark:border-red-900/30 rounded-lg p-3 mt-4">
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

                            <div v-else-if="statoCopertura === 'deficit'" class="bg-amber-50/50 dark:bg-amber-900/10 border border-amber-100 dark:border-amber-900/30 rounded-lg p-3 mt-4">
                                <div class="flex justify-between items-center mb-2">
                                    <span class="text-[10px] font-bold text-amber-700 uppercase flex items-center gap-1">
                                        <AlertTriangle class="w-3 h-3" /> Fabbisogno scoperto: {{ euro(totaleScopertoOperativo) }}
                                    </span>
                                    <span class="text-[9px] text-amber-600/70" v-if="copertura.scoperto_count > 0">{{ copertura.scoperto_count }} voci</span>
                                </div>
                                <div class="text-[10px] text-slate-600 dark:text-slate-400 leading-tight mb-2 border-l-2 border-amber-300 pl-2">
                                    {{ suggerimentoOperativo }}
                                </div>
                            </div>

                            <div v-else-if="statoCopertura === 'documented'" class="bg-slate-50/80 dark:bg-slate-800/40 border border-slate-200 dark:border-slate-700 rounded-lg p-3 mt-4">
                                <div class="flex items-center gap-2 mb-2">
                                    <BookOpen class="w-3.5 h-3.5 text-slate-500" />
                                    <span class="text-[10px] font-bold text-slate-600 uppercase tracking-wide">Scoperto documentato</span>
                                </div>
                                <div class="text-[10px] text-slate-600 dark:text-slate-400 leading-tight border-l-2 border-slate-300 pl-2">
                                    {{ suggerimentoOperativo }}
                                </div>
                            </div>

                            <div v-else-if="statoCopertura === 'surplus'" class="bg-blue-50/50 dark:bg-blue-900/10 border border-blue-100 dark:border-blue-900/30 rounded-lg p-3 flex flex-col justify-center text-blue-700 mt-4">
                                <div class="flex items-center gap-2 mb-1"><Lightbulb class="w-4 h-4" /><span class="text-xs font-bold uppercase">Verifica Eccedenza</span></div>
                                <p class="text-[10px] opacity-90 leading-tight">Stai incassando più del fabbisogno reale. Se non è voluto (es. arrotondamenti), verifica i piani rate.</p>
                            </div>

                            <div v-else-if="statoCopertura === 'integrated'" class="bg-indigo-50/50 dark:bg-indigo-900/10 border border-indigo-100 dark:border-indigo-900/30 rounded-lg p-3 flex flex-col justify-center text-indigo-700 mt-4">
                                <div class="flex items-center gap-2 mb-1"><CheckCircle2 class="w-4 h-4" /><span class="text-xs font-bold uppercase">Sforo Recuperato</span></div>
                                <p class="text-[10px] opacity-90 leading-tight">Ottimo lavoro. Hai integrato il piano rate coprendo con successo gli extra costi registrati in contabilità.</p>
                            </div>
                        </div>

                        <div class="mt-auto border-t border-slate-100 dark:border-slate-800 px-4 py-3 bg-slate-50/50 dark:bg-slate-800/50 flex items-center justify-between">
                        <span class="text-[10px] text-slate-400 font-medium">
                            {{ statoCopertura === 'misaligned' ? 'Azione critica' : statoCopertura === 'deficit' ? 'Azione richiesta' : statoCopertura === 'documented' ? 'Ricorda di recuperarla' : statoCopertura === 'surplus' ? 'Verifica consigliata' : 'Tutto in ordine' }}
                        </span>
                        
                        <Button
                            v-if="copertura.orfani.length > 0 && statoCopertura !== 'misaligned' && statoCopertura !== 'documented'"
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
                                :variant="statoCopertura === 'surplus' || statoCopertura === 'integrated' ? 'outline' : 'default'"
                                :class="{'border-blue-200 text-blue-600 hover:bg-blue-50 hover:border-blue-300': statoCopertura === 'surplus', 'border-indigo-200 text-indigo-600 hover:bg-indigo-50 hover:border-indigo-300': statoCopertura === 'integrated', 'bg-red-600 hover:bg-red-700 text-white': statoCopertura === 'misaligned'}"
                            >
                                {{ statoCopertura === 'deficit' || statoCopertura === 'misaligned' || statoCopertura === 'documented' ? 'Gestisci piani rate' : 'Vai ai piani rate' }}
                                <ArrowRight class="w-3 h-3" />
                            </Button>
                        </Link>
                    </div>
                </div>
            </div>

                <div class="md:col-span-2 lg:col-span-8 flex flex-col gap-3 min-h-0">
                    <div
                        v-if="inboxTasks"
                        class="bg-white dark:bg-slate-900 border border-sidebar-border/70 rounded-xl overflow-hidden shadow-sm flex flex-col min-h-[430px]"
                        :style="coperturaHeight ? { height: coperturaHeight + 'px' } : {}"
                    >

                        <div class="p-5 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between bg-slate-50/50 dark:bg-slate-900/50 shrink-0">
                            <div class="flex items-center gap-2">
                                <Inbox class="w-4 h-4 text-slate-400" />
                                <h3 class="text-xs font-bold uppercase tracking-widest text-slate-500">Inbox Operativa</h3>
                            </div>
                            <Badge v-if="inboxTasks.total > 0" variant="destructive" class="font-bold rounded-md text-[10px]">{{ inboxTasks.total }} ATTIVITÀ</Badge>
                        </div>

                        <!-- min-h-0 e' essenziale: senza, un figlio flex con
                             overflow-y-auto non scrolla mai, cresce e basta,
                             perche' il default CSS e' min-height:auto (si adatta
                             al contenuto). Bug invisibile con Inbox vuota,
                             evidente con molte attivita' reali. -->
                        <div class="flex-1 min-h-0 overflow-y-auto custom-scrollbar p-2">
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
                                                        <ArrowDownToLine v-else-if="task.type === 'controllo_incassi'" class="w-5 h-5 text-purple-500" />
                                                        <ArrowUpFromLine v-else-if="task.type === 'emissione_rata'" class="w-5 h-5 text-blue-500" />
                                                        <Banknote v-else-if="task.type === 'verifica_pagamento'" class="w-5 h-5 text-amber-500" />
                                                        <MessageSquare v-else-if="task.type === 'commento'" class="w-5 h-5 text-indigo-500" />
                                                        <Wrench v-else-if="task.type === 'segnalazione_guasto'" class="w-5 h-5 text-orange-500" />
                                                        <CalendarClock v-else class="w-5 h-5" />
                                                    </div>
                                                    <div class="flex-1">
                                                        <h3 class="text-sm font-bold text-slate-900 dark:text-white" :class="{'text-red-700 dark:text-red-400': task.status === 'expired'}">{{ task.title }}</h3>
                                                        <p class="text-[13px] text-slate-500 dark:text-slate-400 line-clamp-2 mt-1 leading-relaxed pr-6">{{ task.description }}</p>
                                                        <div class="flex items-center gap-3 mt-2 text-[10px] font-bold uppercase text-slate-400">
                                                            <span v-if="task.type === 'verifica_pagamento'" class="text-amber-500 flex items-center gap-1">
                                                                <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span> DA VERIFICARE
                                                            </span>
                                                            <span v-else-if="task.status === 'expired'" class="text-red-500 flex items-center gap-1">
                                                                <span class="w-1.5 h-1.5 rounded-full bg-red-500 animate-pulse"></span> SCADUTO {{ (task.days_pending ?? 0) > 0 ? `DA ${task.days_pending ?? 0} GIORN${(task.days_pending ?? 0) === 1 ? 'O' : 'I'}` : '' }}
                                                            </span>
                                                            <span v-else class="text-emerald-500 flex items-center gap-1">
                                                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> DA FARE
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="shrink-0 flex items-center gap-2">
    
                                                    <template v-if="task.type === 'verifica_pagamento'">
                                                        <button @click="openRejectModal(task)" title="Rifiuta segnalazione"
                                                            class="inline-flex items-center justify-center w-7 h-7 rounded-md border border-slate-200 text-slate-400 bg-white hover:text-red-600 hover:border-red-200 hover:bg-red-50 shadow-sm transition-all dark:bg-slate-800 dark:border-slate-700">
                                                            <XCircle class="w-4 h-4" />
                                                        </button>
                                                        
                                                        <a v-if="task.action_url" :href="task.action_url" 
                                                        class="inline-flex items-center justify-center h-7 px-3 text-xs font-bold transition-all rounded-md bg-white border border-slate-200 text-slate-700 shadow-sm hover:border-emerald-300 hover:text-emerald-700 hover:bg-emerald-50 dark:bg-slate-800 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-emerald-900/30 dark:hover:text-emerald-400">
                                                            Registra <ArrowRight class="w-3.5 h-3.5 ml-1.5" />
                                                        </a>
                                                    </template>

                                                    <template v-else>
                                                        <button @click="completeTask(task.id)" title="Segna come completato"
                                                            class="inline-flex items-center justify-center w-7 h-7 rounded-md border border-slate-200 text-slate-400 bg-white hover:text-emerald-600 hover:border-emerald-200 hover:bg-emerald-50 shadow-sm transition-all dark:bg-slate-800 dark:border-slate-700">
                                                            <CheckCircle2 class="w-4 h-4" />
                                                        </button>

                                                        <a v-if="task.action_url" :href="task.action_url" 
                                                            class="inline-flex items-center justify-center h-7 px-3 text-xs font-bold transition-all rounded-md bg-white border shadow-sm dark:bg-slate-800"
                                                            :class="task.status === 'expired' 
                                                                ? 'border-red-200 text-red-600 hover:bg-red-50 hover:border-red-300 dark:border-red-800 dark:text-red-400 dark:hover:bg-red-900/30' 
                                                                : 'border-slate-200 text-slate-700 hover:bg-slate-50 hover:text-indigo-700 hover:border-indigo-300 dark:border-slate-700 dark:text-slate-200 dark:hover:text-indigo-400'">
                                                            Risolvi <ArrowRight class="w-3.5 h-3.5 ml-1.5" />
                                                        </a>
                                                    </template>
                                                    
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
                                <h3 class="text-sm font-black text-slate-400 uppercase tracking-widest">Inbox vuota</h3>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Riga widget a piena larghezza, stesso rapporto 1/3 : 2/3 della
                     riga sopra (Copertura Bilancio : Inbox = 4:8). Crediti a
                     sinistra (piu' stretto, contenuto breve), Tesoreria a destra
                     (piu' denso). Se manca uno dei due, l'altro prende tutta la riga. -->
                <!--
                    Sopra gli altri due: dopo una migrazione è la cosa più urgente che ci sia, e
                    sparisce da sola quando l'ultima voce si chiude.
                -->
                <!-- La griglia è a 3 colonne da md e a 12 da lg: servono entrambi gli span,
                     altrimenti su schermo medio il riquadro si stringe a un terzo. -->
                <ControlliPostImportWidget
                    v-if="widgets?.controlli_post_import"
                    :controlli="widgets.controlli_post_import"
                    class="md:col-span-3 lg:col-span-12"
                />

                <div class="lg:col-span-12 grid grid-cols-1 lg:grid-cols-3 gap-3 items-stretch">
                    <CreditiDaCompensareWidget
                        v-if="widgets?.crediti_da_compensare"
                        :crediti="widgets.crediti_da_compensare"
                        class="lg:col-span-1"
                        :class="{ 'lg:col-span-3': !widgets?.treasury_guardian }"
                    />
                    <TreasuryGuardianWidget
                        v-if="widgets?.treasury_guardian"
                        :treasury="widgets.treasury_guardian"
                        class="lg:col-span-2"
                        :class="{ 'lg:col-span-3': !widgets?.crediti_da_compensare }"
                    />
                </div>

            </div>
        </div>

        <Transition enter-active-class="transition duration-200 ease-out" enter-from-class="opacity-0 scale-95" enter-to-class="opacity-100 scale-100" leave-active-class="transition duration-150 ease-in" leave-from-class="opacity-100 scale-100" leave-to-class="opacity-0 scale-95">
            <div v-if="showOrphansModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/40 backdrop-blur-sm p-4" @click.self="showOrphansModal = false">
                <div class="w-full max-w-4xl overflow-hidden rounded-2xl bg-white dark:bg-slate-900 shadow-2xl flex flex-col max-h-[85vh]">
                    <div class="flex items-center justify-between border-b p-6 shrink-0">
                        <div>
                            <h3 class="text-lg font-black text-slate-900 dark:text-white">Audit spese scoperte</h3>
                            <p class="text-xs text-slate-500 uppercase font-bold tracking-tight">Dettaglio fatture in sospeso e sforo budget</p>
                        </div>
                        <button @click="showOrphansModal = false" class="rounded-full p-2 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                            <X class="w-5 h-5" />
                        </button>
                    </div>
                    
                    <div class="p-6 flex-1 overflow-y-auto custom-scrollbar bg-slate-50/30 dark:bg-slate-900/50">
                        <div class="bg-blue-50 border border-blue-100 rounded-lg p-3 mb-5 text-xs text-blue-700">
                            <strong class="font-bold block mb-1">Cosa fare?</strong>
                            Queste spese non sono coperte dalle rate attualmente emesse. Clicca su "Finanzia Spesa" per creare automaticamente un piano rate straordinario (oppure integrativo per i conguagli) che includa queste voci.
                        </div>

                        <div v-if="fattureScoperte && fattureScoperte.length > 0" class="mb-8">
                            <h4 class="text-xs font-black text-slate-400 uppercase tracking-widest mb-3 flex items-center gap-2">
                                <AlertTriangle class="w-3.5 h-3.5 text-amber-500" /> Fatture in sospeso (Imprevisti / Art. 63)
                            </h4>
                            
                            <div class="space-y-4">
                                <div v-for="fat in fattureScoperte" :key="'fat-'+fat.id" class="bg-white dark:bg-slate-800 border border-amber-200/60 dark:border-amber-900/30 rounded-xl overflow-hidden shadow-sm">
                                    <div class="bg-amber-50/50 dark:bg-amber-900/10 px-5 py-3 border-b border-amber-100 dark:border-amber-900/20 flex justify-between items-center">
                                        <div>
                                            <div class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ fat.fornitore }}</div>
                                             <div class="flex items-center gap-2 mt-0.5">
                                                <span class="text-xs text-slate-500 font-medium">Doc. {{ fat.numero }}</span>
                                                <span v-if="fat.strategia === 'rata_integrativa'" 
                                                    class="px-1.5 py-0.5 rounded text-[9px] font-bold uppercase bg-amber-600 text-white">
                                                    Rata integrativa
                                                </span>
                                                <span v-else-if="fat.strategia === 'conguaglio_fine_anno'" 
                                                    class="px-1.5 py-0.5 rounded text-[9px] font-bold uppercase bg-indigo-600 text-white">
                                                    A conguaglio
                                                </span>
                                                <span v-else-if="fat.strategia === 'fondo_riserva'" 
                                                    class="px-1.5 py-0.5 rounded text-[9px] font-bold uppercase bg-emerald-600 text-white">
                                                    Fondo riserva
                                                </span>
                                            </div>
                                        </div>
                                        <div class="text-sm font-black text-amber-600 bg-amber-50 px-2.5 py-1 rounded-md border border-amber-200">
                                            {{ euro(fat.totale_scoperto) }}
                                        </div>
                                    </div>
                                    
                                    <div class="p-5">
                                        <div class="space-y-3 pl-3 border-l-2 border-amber-200/60">
                                            <div v-for="riga in fat.righe" :key="riga.id" class="flex justify-between items-start text-xs">
                                                <div class="flex items-start gap-2">
                                                    <div class="mt-0.5">
                                                        <User v-if="riga.tipo === 'ad_personam'" class="w-3.5 h-3.5 text-indigo-500" />
                                                        <LayoutDashboard v-else class="w-3.5 h-3.5 text-amber-500" />
                                                    </div>
                                                    <div>
                                                        <span class="font-bold text-slate-700 dark:text-slate-300">{{ riga.descrizione }}</span><br>
                                                        <span class="text-slate-500 text-[10px]">{{ riga.dettaglio }}</span>
                                                    </div>
                                                </div>
                                                <span class="font-mono text-slate-600 font-bold bg-slate-50 px-2 py-0.5 rounded border">{{ euro(riga.importo) }}</span>
                                            </div>
                                        </div>
                                        
                                        <div class="mt-4 flex justify-end items-center">
                                            <span v-if="fat.strategia === 'conguaglio_fine_anno' || fat.strategia === 'fondo_riserva'" 
                                                class="text-[10px] font-bold text-slate-400 mr-auto">
                                                {{ fat.strategia === 'fondo_riserva' 
                                                    ? 'Coperta dal fondo patrimoniale — nessuna rata richiesta' 
                                                    : 'Verrà calcolata automaticamente a fine esercizio' }}
                                            </span>
                                            <Link v-else :href="generatePath('gestionale/:condominio/esercizi/:esercizio/piani-rate/create', { condominio: condominio.id, esercizio: esercizio.id }) + `?tipo=straordinario&origine=dashboard&gestione_id=${fat.gestione_id}&fatture[]=${fat.id}`">
                                                <Button size="sm" class="h-8 text-[10px] uppercase font-bold bg-amber-600 hover:bg-amber-700 text-white shadow-sm">
                                                    Finanzia spesa <ArrowRight class="w-3.5 h-3.5 ml-1.5" />
                                                </Button>
                                            </Link>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div v-if="copertura?.orfani && copertura.orfani.filter(o => !o.nome.includes('Imprevisto -')).length > 0">
                            <h4 class="text-xs font-black text-slate-400 uppercase tracking-widest mb-3 flex items-center gap-2">
                                <TrendingDown class="w-3.5 h-3.5 text-rose-500" /> Sforamenti Budget Preventivo
                            </h4>
                            
                            <div class="space-y-3">
                                <div v-for="orfano in copertura.orfani.filter(o => !o.nome.includes('Imprevisto -'))" :key="orfano.id"
                                    class="bg-white dark:bg-slate-800 rounded-xl overflow-hidden shadow-sm border"
                                    :class="{
                                        'border-indigo-200/60': orfano.strategia === 'conguaglio',
                                        'border-emerald-200/60': orfano.strategia === 'fondo_riserva',
                                        'border-rose-200/60': !['conguaglio', 'fondo_riserva'].includes(orfano.strategia)
                                    }">
                                    
                                    <!-- Header card -->
                                    <div class="px-5 py-3 border-b flex justify-between items-center"
                                        :class="{
                                            'bg-indigo-50/50 border-indigo-100': orfano.strategia === 'conguaglio',
                                            'bg-emerald-50/50 border-emerald-100': orfano.strategia === 'fondo_riserva',
                                            'bg-rose-50/50 border-rose-100': !['conguaglio', 'fondo_riserva'].includes(orfano.strategia)
                                        }">
                                        <div>
                                            <div class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ orfano.nome }}</div>
                                            <div class="flex items-center gap-2 mt-0.5">
                                                <span class="text-xs text-slate-500 font-medium">{{ orfano.gestione }}</span>
                                                <span v-if="orfano.strategia === 'fondo_riserva'" 
                                                    class="px-1.5 py-0.5 rounded text-[9px] font-bold uppercase bg-emerald-600 text-white">
                                                    Fondo Riserva
                                                </span>
                                                <span v-else-if="orfano.strategia === 'conguaglio'" 
                                                    class="px-1.5 py-0.5 rounded text-[9px] font-bold uppercase bg-indigo-600 text-white">
                                                    A Consuntivo
                                                </span>
                                                <span v-else-if="orfano.strategia === 'rata_integrativa'" 
                                                    class="px-1.5 py-0.5 rounded text-[9px] font-bold uppercase bg-amber-600 text-white">
                                                    Rata Integrativa
                                                </span>
                                                <span v-else 
                                                    class="px-1.5 py-0.5 rounded text-[9px] font-bold uppercase bg-rose-600 text-white">
                                                    Sforo Aperto
                                                </span>
                                            </div>
                                        </div>
                                        <div class="text-sm font-black px-2.5 py-1 rounded-md border"
                                            :class="{
                                                'text-indigo-600 bg-indigo-50 border-indigo-200': orfano.strategia === 'conguaglio',
                                                'text-emerald-600 bg-emerald-50 border-emerald-200': orfano.strategia === 'fondo_riserva',
                                                'text-rose-600 bg-rose-50 border-rose-200': !['conguaglio', 'fondo_riserva'].includes(orfano.strategia)
                                            }">
                                            {{ euro(orfano.importo) }}
                                        </div>
                                    </div>

                                    <div class="p-5">
                                        <div class="flex items-start justify-between gap-4">
                                            <p class="text-sm text-slate-500 leading-relaxed border-l-2 pl-3"
                                                :class="{
                                                    'border-indigo-200 text-indigo-600/70': orfano.strategia === 'conguaglio',
                                                    'border-emerald-200 text-emerald-600/70': orfano.strategia === 'fondo_riserva',
                                                    'border-amber-200': orfano.strategia === 'rata_integrativa',
                                                    'border-rose-200': !['conguaglio', 'fondo_riserva', 'rata_integrativa'].includes(orfano.strategia)
                                                }">
                                                <template v-if="orfano.strategia === 'rata_integrativa'">
                                                    La spesa ha superato il preventivo. Emetti una rata integrativa per recuperare la differenza.
                                                </template>
                                                <template v-else-if="orfano.strategia === 'conguaglio'">
                                                    Verrà conguagliata a fine esercizio nel rendiconto consuntivo.
                                                </template>
                                                <template v-else-if="orfano.strategia === 'fondo_riserva'">
                                                    Coperta dal fondo di riserva patrimoniale. Nessuna azione richiesta.
                                                </template>
                                                <template v-else>
                                                    Voce senza copertura attiva. Delibera un piano rate per finanziare questo importo.
                                                </template>
                                            </p>

                                            <Link v-if="!['conguaglio', 'fondo_riserva'].includes(orfano.strategia)" 
                                                :href="generatePath('gestionale/:condominio/esercizi/:esercizio/piani-rate/create', { condominio: condominio.id, esercizio: esercizio.id }) + `?tipo=ordinario&origine=dashboard&gestione_id=${orfano.gestione_id ?? ''}`">
                                                <Button size="sm" class="h-8 text-[10px] uppercase font-bold bg-rose-600 hover:bg-rose-700 text-white shadow-sm shrink-0">
                                                    Gestisci sforo <ArrowRight class="w-3.5 h-3.5 ml-1.5" />
                                                </Button>
                                            </Link>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="p-5 border-t bg-white dark:bg-slate-900 shrink-0 flex justify-end">
                        <Button variant="outline" class="font-bold text-xs uppercase px-8 h-10" @click="showOrphansModal = false">Chiudi</Button>
                    </div>
                </div>
            </div>
        </Transition>

        <Dialog v-model:open="isRejectModalOpen">
            <DialogContent class="sm:max-w-[450px]">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2 text-red-600">
                        <AlertTriangle class="w-5 h-5" />
                        Rifiuta segnalazione
                    </DialogTitle>
                    <DialogDescription>
                        Stai per rifiutare il pagamento segnalato da 
                        <span class="font-bold text-slate-900">{{ taskToReject?.context?.anagrafica_nome || 'Condòmino' }}</span>.
                        <strong> Attenzione: questa azione sarà irreversibile.</strong>
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4 py-4">
                    <div class="grid gap-2">
                        <Label htmlFor="reason" class="text-slate-900">
                            Motivazione (visibile all'utente)
                        </Label>
                        <Textarea 
                            id="reason" 
                            v-model="rejectForm.reason" 
                            placeholder="Es: Bonifico non trovato nell'estratto conto..." 
                            class="resize-none min-h-[100px]"
                            :class="{'border-red-500 focus-visible:ring-red-500': rejectForm.errors.reason}"
                        />
                        <p v-if="rejectForm.errors.reason" class="text-[11px] text-red-600 font-medium">
                            {{ rejectForm.errors.reason }}
                        </p>
                    </div>
                </div>

                <DialogFooter>
                    <Button variant="outline" @click="isRejectModalOpen = false" :disabled="rejectForm.processing">
                        Annulla
                    </Button>
                    <Button 
                        variant="destructive" 
                        @click="confirmReject" 
                        :disabled="rejectForm.processing || !rejectForm.reason"
                    >
                        <Loader2 v-if="rejectForm.processing" class="w-4 h-4 mr-2 animate-spin" />
                        Conferma rifiuto
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

    </GestionaleLayout>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar { width: 4px; }
.custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }
.dark .custom-scrollbar::-webkit-scrollbar-thumb { background: #334155; }
</style>