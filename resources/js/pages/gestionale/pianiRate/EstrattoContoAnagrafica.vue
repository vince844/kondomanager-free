<script setup lang="ts">

import { computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import PageHeaderGuide, { type GuideItem } from '@/components/PageHeaderGuide.vue';
import { usePermission } from "@/composables/permissions";
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { useDateConverter } from '@/composables/useDateConverter';
import { ArrowLeft, Printer, Mail, Wallet, ArrowDownCircle, ArrowUpCircle, Building2, Landmark,FileText, Banknote, HelpCircle, RotateCcw, CheckCircle2, AlertCircle, PieChart, Coins, Info, XCircle, ChevronDown } from 'lucide-vue-next';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { useUrlPrecedente } from '@/composables/useUrlPrecedente';
import type { Building } from '@/types/buildings';
import type { Anagrafica } from '@/types/anagrafiche';
import type { Esercizio } from '@/types/gestionale/esercizi';

const props = defineProps<{
  condominio: Building;
  esercizio: Esercizio;
  anagrafica: Anagrafica & { immobili: any[] };
  timeline: any[];
  stats: {
    totale_addebiti: string;
    totale_versamenti: string;
    saldo_finale: string;
    saldo_raw: number;
    saldo_iniziale: string;
    saldo_iniziale_raw: number;
    credito_disponibile: string;
    credito_disponibile_raw: number;
    credito_per_gestione: Array<{ gestione_id: number | null; gestione_nome: string; importo_cents: number; importo_formatted: string }>;
    /** Quanto di quel credito è davvero spendibile sulle rate aperte, e su quale. */
    compensabile: string;
    compensabile_raw: number;
    compensabile_frase: string;
    compensabile_rata_id: number | null;
  };
}>();

const { euro } = useCurrencyFormatter({ fromCents: false }); 
const { toItalian } = useDateConverter();
const { generatePath, generateRoute } = usePermission();
const { urlPrecedente } = useUrlPrecedente();

const goBack = () => {
    if (window.history.length > 1) window.history.back();
    else router.visit(generatePath('gestionale/:condominio/piani-rate', { condominio: props.condominio.id }));
};

const printPDF = () => {
    const url = route('admin.gestionale.anagrafiche.estratto-conto.print', {
        condominio: props.condominio.id,
        anagrafica: props.anagrafica.id,
    });
    window.open(url, '_blank');
};

const formatIndirizzoImmobile = (immobile: any) => {
    // ⚠️ Ripasso della beta.58: il PDF di questa stessa pagina era stato corretto e la card no.
    // Con l'interno facoltativo restava «Int. » da solo — e due unità così erano indistinguibili
    // proprio nella schermata da cui si stampa il documento che le nomina.
    // Non si può leggere `immobile.etichetta`: il modello non ha `$appends` e la risorsa non
    // espone l'accessore, quindi la stessa regola va ripetuta qui in una riga.
    let base = immobile.interno ? `Int. ${immobile.interno}` : (immobile.nome || 'Unità');
    if (immobile.piano) base += ` - P. ${immobile.piano}`;
    return base;
};

// beta.30: leggeva `window.history.state.back`, convenzione di Vue Router che
// Inertia v3 non popola — il ramo era sempre falso e si tornava sempre al
// fallback. La provenienza reale ora arriva da useUrlPrecedente().
const backUrlString = computed(() => {
    const precedente = urlPrecedente();
    if (precedente) {
        return precedente;
    }
    // Fallback alla lista dei piani rate dell'esercizio corrente
    return route('admin.gestionale.esercizi.piani-rate.index', { 
        condominio: props.condominio.id, 
        esercizio: props.esercizio.id 
    });
});

/**
 * Il link «Compensa»: porta l'anagrafica e la rata che il credito copre, così la pagina di
 * incasso si apre già puntata. Non porta `intent_usa_credito`, che dichiarerebbe una richiesta
 * del condòmino: qui a muoversi è l'amministratore.
 */
const urlCompensazione = computed(() => {
    const params = new URLSearchParams({
        prefill_anagrafica_id: String(props.anagrafica.id),
    });
    if (props.stats.compensabile_rata_id) {
        params.set('prefill_rata_id', String(props.stats.compensabile_rata_id));
    }
    return `${route(generateRoute('gestionale.movimenti-rate.create'), { condominio: props.condominio.id })}?${params}`;
});

const breadcrumbs = computed(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: 'Piani Rate', href: backUrlString.value },
  { title: `EC: ${props.anagrafica.nome}`, href: '#' },
]);

const pageGuides: GuideItem[] = [
  {
    title: 'Saldo Iniziale',
    description: 'Il debito o credito ereditato dall\'esercizio precedente. Un importo in rosso indica un debito residuo, in verde un credito a tuo favore.',
    icon: Landmark,
    colorVariant: 'slate'
  },
  {
    title: 'Addebiti',
    description: 'Tutte le rate emesse a carico del condòmino e gli eventuali ripristini di debito.',
    icon: Banknote,
    colorVariant: 'amber'
  },
  {
    title: 'Pagamenti',
    description: 'Tutti gli incassi registrati e le compensazioni usate per raggiungere il saldo finale.',
    icon: Wallet,
    colorVariant: 'emerald'
  }
];

const saldoColorClass = computed(() => {
    if (props.stats.saldo_raw > 0) return 'text-red-600';
    if (props.stats.saldo_raw < 0) return 'text-emerald-600';
    return 'text-gray-600';
});

const saldoInizialeColorClass = computed(() => {
    if (props.stats.saldo_iniziale_raw > 0) return 'text-red-600'; 
    if (props.stats.saldo_iniziale_raw < 0) return 'text-emerald-600'; 
    return 'text-gray-600';
});

const getStatoConfig = (stato: string | null) => {
    if (!stato) return { label: '', class: '', icon: null };

    switch(stato) {
        case 'pagata': 
            return { label: 'PAGATA', class: 'bg-emerald-50 text-emerald-700 border-emerald-200', icon: CheckCircle2 };
        case 'credito': 
            return { label: 'COMPENSATA', class: 'bg-indigo-50 text-indigo-700 border-indigo-200', icon: Coins };
        case 'parzialmente_pagata': 
        case 'partial': // Aggiunto alias di sicurezza (se usato nel DB)
            return { label: 'PARZIALE', class: 'bg-amber-50 text-amber-700 border-amber-200', icon: PieChart };
        case 'da_pagare': 
        case 'pending': // Aggiunto alias
            return { label: 'NON PAGATA', class: 'bg-red-50 text-red-700 border-red-200', icon: AlertCircle };
        case 'credito_puro': 
            return { label: 'CREDITO', class: 'bg-blue-50 text-blue-700 border-blue-200', icon: ArrowDownCircle };
        case 'stornato': // GESTIONE DELLO STORNO
            return { label: 'STORNATA', class: 'bg-slate-100 text-slate-600 border-slate-300', icon: XCircle };
        default: 
            return { label: '', class: '', icon: null };
    }
};

const getImportoStyle = (riga: any) => {
    if (riga.tipo_riga === 'dare') {
         // FIX: Se è uno storno, stile più tenue per non sembrare una vera rata
         if (riga.tipo_icona === 'rotate-ccw') {
             return 'text-slate-600 font-semibold line-through decoration-slate-300 decoration-2';
         }
         return 'text-red-600 font-medium';
    } else if (riga.tipo_riga === 'avere') {
         return 'text-emerald-600 font-bold font-mono text-sm bg-emerald-50 px-2 py-0.5 rounded-md border border-emerald-100';
    }
    
    const isCredito = riga.dettagli?.some((d: any) => d.type === 'rata' && d.status === 'credito');
    if (isCredito) return 'text-blue-600 font-bold';
    
    return 'text-gray-900 font-medium';
};
</script>

<template>
    <Head :title="`EC - ${anagrafica.nome}`" />

    <GestionaleLayout>
        
        <div class="px-6 py-8 space-y-6">
            <PageHeaderGuide
                :page-title="`Estratto Conto: ${anagrafica.nome}`"
                :page-subtitle="`Situazione contabile dettagliata, saldo progressivo e storico movimenti. CF: ${anagrafica.codice_fiscale}`"
                :guides="pageGuides"
                :breadcrumbs="breadcrumbs"
                :condominio="condominio"
                :esercizio="esercizio"
                :backUrl="backUrlString"
                backText="Indietro"
            >
                <template #actions>
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button variant="outline" class="h-8 px-3 border-slate-200 text-slate-700 bg-white hover:bg-slate-50 shadow-sm shrink-0 gap-2">
                                <Printer class="w-4 h-4" />
                                <span class="hidden sm:inline">Stampe</span>
                                <ChevronDown class="w-3 h-3 opacity-60" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-56 shadow-xl rounded-xl border-slate-100 p-1.5">
                            <DropdownMenuLabel class="text-[10px] text-slate-400 uppercase tracking-widest px-2 py-1.5 font-bold">
                                Documenti Ufficiali
                            </DropdownMenuLabel>
                            <DropdownMenuSeparator class="bg-slate-100" />
                            <DropdownMenuItem 
                                @click="printPDF"
                                class="cursor-pointer flex items-center gap-2.5 px-2 py-2 rounded-lg hover:bg-indigo-50 focus:bg-indigo-50 text-slate-700">
                                <FileText class="w-3.5 h-3.5 text-indigo-500" />
                                <div>
                                    <div class="text-xs font-medium">Estratto conto</div>
                                    <div class="text-[10px] text-slate-400">PDF riepilogativo per anagrafica</div>
                                </div>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </template>
            </PageHeaderGuide>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            <div class="lg:col-span-8 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <div class="rounded-lg border bg-gray-50/50 shadow-sm border-gray-200">
                    <div class="flex flex-row items-center justify-between p-4 pb-2">
                        <h3 class="text-xs font-medium uppercase text-muted-foreground tracking-wider">Saldo iniziale</h3>
                        <Landmark class="h-4 w-4 text-gray-400" />
                    </div>
                    <div class="p-4 pt-0">
                        <div class="text-xl font-bold" :class="saldoInizialeColorClass">{{ stats.saldo_iniziale }}</div>
                        <p class="text-[10px] uppercase font-bold mt-1 tracking-wide" :class="saldoInizialeColorClass">
                            {{ stats.saldo_iniziale_raw > 0 ? 'A DEBITO' : (stats.saldo_iniziale_raw < 0 ? 'A CREDITO' : 'PAREGGIO') }}
                        </p>
                    </div>
                </div>
                <div class="rounded-lg border bg-card text-card-foreground shadow-sm border-gray-200">
                    <div class="flex flex-row items-center justify-between p-4 pb-2">
                        <h3 class="text-xs font-medium uppercase text-muted-foreground tracking-wider">Totale addebiti</h3>
                        <ArrowDownCircle class="h-4 w-4 text-red-500" />
                    </div>
                    <div class="p-4 pt-0">
                        <div class="text-xl font-bold text-gray-900">{{ stats.totale_addebiti }}</div>
                        <p class="text-[10px] text-muted-foreground mt-1">Rate emesse</p>
                    </div>
                </div>
                <div class="rounded-lg border bg-card text-card-foreground shadow-sm border-gray-200">
                    <div class="flex flex-row items-center justify-between p-4 pb-2">
                        <h3 class="text-xs font-medium uppercase text-muted-foreground tracking-wider">Totale Versato</h3>
                        <ArrowUpCircle class="h-4 w-4 text-emerald-500" />
                    </div>
                    <div class="p-4 pt-0">
                        <div class="text-xl font-bold text-emerald-600">{{ stats.totale_versamenti }}</div>
                        <p class="text-[10px] text-muted-foreground mt-1">Incassi registrati</p>
                    </div>
                </div>
                <div class="rounded-lg border shadow-sm border-gray-200" :class="{'bg-red-50 border-red-200': stats.saldo_raw > 0, 'bg-emerald-50 border-emerald-200': stats.saldo_raw < 0, 'bg-white': stats.saldo_raw === 0}">
                    <div class="flex flex-row items-center justify-between p-4 pb-2">
                        <h3 class="text-xs font-medium uppercase tracking-wider" :class="stats.saldo_raw > 0 ? 'text-red-500' : 'text-emerald-500'">Saldo Finale</h3>
                        <Wallet class="h-4 w-4" :class="stats.saldo_raw > 0 ? 'text-red-500' : 'text-emerald-500'" />
                    </div>
                    <div class="p-4 pt-0">
                        <div class="text-2xl font-bold" :class="saldoColorClass">{{ stats.saldo_finale }}</div>
                        <p class="text-[10px] uppercase font-bold mt-1 tracking-wide" :class="stats.saldo_raw > 0 ? 'text-red-600' : 'text-emerald-600'">
                            {{ stats.saldo_raw > 0 ? 'DA VERSARE' : (stats.saldo_raw < 0 ? 'A CREDITO' : 'PAREGGIO') }}
                        </p>
                    </div>
                </div>
                <div class="rounded-lg border bg-card text-card-foreground shadow-sm border-gray-200">
                    <div class="flex flex-row items-center justify-between p-4 pb-2">
                        <h3 class="text-xs font-medium uppercase text-muted-foreground tracking-wider">Credito disponibile</h3>
                        <Coins class="h-4 w-4 text-blue-500" />
                    </div>
                    <div class="p-4 pt-0">
                        <div class="flex items-center gap-1.5">
                            <div class="text-xl font-bold" :class="stats.credito_disponibile_raw > 0 ? 'text-blue-600' : 'text-gray-400'">{{ stats.credito_disponibile }}</div>
                            <TooltipProvider v-if="stats.credito_per_gestione.length > 1" :delayDuration="0">
                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <Info class="w-3.5 h-3.5 text-blue-400 cursor-help" />
                                    </TooltipTrigger>
                                    <TooltipContent side="bottom" class="bg-slate-900 border-slate-700 text-slate-200 p-3 shadow-2xl rounded-lg z-[100]">
                                        <div class="text-[10px] font-bold text-slate-400 mb-2 uppercase tracking-wider border-b border-slate-700 pb-1">Spaccato per gestione</div>
                                        <ul class="space-y-1">
                                            <li v-for="g in stats.credito_per_gestione" :key="g.gestione_id ?? 0" class="flex justify-between gap-4 text-[11px]">
                                                <span class="text-slate-300">{{ g.gestione_nome }}</span>
                                                <span class="font-bold text-blue-400">{{ g.importo_formatted }}</span>
                                            </li>
                                        </ul>
                                    </TooltipContent>
                                </Tooltip>
                            </TooltipProvider>
                        </div>
                        <!-- Non basta dire quanto credito c'è: senza sapere quale rata copre,
                             l'amministratore deve andarselo a cercare in un elenco. Lo spaccato
                             per gestione resta nel tooltip qui sopra, dove serve a chi lo cerca. -->
                        <p v-if="stats.credito_disponibile_raw > 0" class="text-[10px] text-muted-foreground mt-1 leading-tight">
                            {{ stats.compensabile_frase }}
                            <a
                                v-if="stats.compensabile_rata_id"
                                :href="urlCompensazione"
                                class="text-blue-600 hover:text-blue-700 underline underline-offset-2 font-medium"
                            >Compensa</a>
                        </p>
                        <p v-else class="text-[10px] text-muted-foreground mt-1">Nessun credito attivo</p>
                    </div>
                </div>
            </div>
            <div class="lg:col-span-4 flex flex-col h-full">
                <div class="rounded-lg border bg-card text-card-foreground h-full flex flex-col shadow-sm border-gray-200">
                    <div class="flex flex-col gap-y-1.5 p-4 pb-2 border-b bg-gray-50/50">
                        <h3 class="tracking-tight text-sm font-semibold flex items-center gap-2">
                            <Building2 class="w-4 h-4 text-gray-500" /> Unità immobiliari
                            <Badge variant="secondary" class="ml-auto text-[10px] h-5">{{ anagrafica.immobili.length }}</Badge>
                        </h3>
                    </div>
                    <div class="p-0 flex-1 overflow-hidden">
                        <div class="overflow-y-auto p-4 space-y-4 max-h-[280px]"> 
                            <div v-for="immobile in anagrafica.immobili" :key="immobile.id" class="flex items-start space-x-3 pb-3 border-b last:border-0 last:pb-0">
                                <div class="bg-primary/10 p-1.5 rounded-md mt-0.5 shrink-0"><Building2 class="w-3.5 h-3.5 text-primary" /></div>
                                <div>
                                    <p class="font-bold text-gray-800 text-xs">{{ formatIndirizzoImmobile(immobile) }}</p>
                                    <div class="mt-1.5 flex flex-wrap gap-1.5">
                                        <Badge variant="secondary" class="text-[9px] px-1.5 h-4">{{ immobile.pivot.tipologia }}</Badge>
                                        <Badge variant="outline" class="text-[9px] px-1.5 h-4 text-gray-500">{{ immobile.pivot.quota }}%</Badge>
                                    </div>
                                </div>
                            </div>
                            <div v-if="anagrafica.immobili.length === 0" class="text-center py-8 text-muted-foreground text-xs italic">Nessuna unità associata.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap gap-4 text-xs text-gray-500 items-center bg-gray-50/80 p-3 rounded-lg border border-dashed border-gray-200">
            <span class="font-bold uppercase tracking-wider text-[10px] text-gray-400 mr-1">Legenda:</span>
            <div class="flex items-center gap-1.5"><div class="w-5 h-5 rounded-full bg-white border border-gray-200 flex items-center justify-center text-gray-500 shadow-sm"><FileText class="w-3 h-3" /></div><span>Emissione</span></div>
            <div class="flex items-center gap-1.5"><div class="w-5 h-5 rounded-full bg-emerald-100 border border-emerald-200 flex items-center justify-center text-emerald-600 shadow-sm"><Banknote class="w-3 h-3" /></div><span>Incasso</span></div>
            <div class="flex items-center gap-1.5"><div class="w-5 h-5 rounded-full bg-blue-50 border border-blue-200 flex items-center justify-center text-blue-600 shadow-sm"><RotateCcw class="w-3 h-3" /></div><span>Storno</span></div>
            <div class="h-4 w-px bg-gray-300 mx-2 hidden sm:block"></div>
            <div class="flex items-center gap-1.5"><CheckCircle2 class="w-3.5 h-3.5 text-emerald-600" /> <span class="text-emerald-700 font-medium">Saldata</span></div>
            <div class="flex items-center gap-1.5"><PieChart class="w-3.5 h-3.5 text-amber-600" /> <span class="text-amber-700 font-medium">Parziale</span></div>
            <div class="flex items-center gap-1.5"><AlertCircle class="w-3.5 h-3.5 text-red-600" /> <span class="text-red-700 font-medium">Non Pagata</span></div>
            <div class="flex items-center gap-1.5"><Coins class="w-3.5 h-3.5 text-blue-600" /> <span class="text-blue-700 font-medium">Credito</span></div>
        </div>

        <div class="rounded-lg border bg-card text-card-foreground shadow-sm border-gray-200">
            <div class="flex flex-col gap-y-1.5 p-4 pb-3 border-b bg-gray-50/30">
                <div class="flex items-center justify-between">
                    <h3 class="tracking-tight text-base font-semibold">Movimenti contabili</h3>
                    <div class="text-xs text-muted-foreground">{{ esercizio.nome }}</div>
                </div>
            </div>
            <div class="p-0">
                <div class="relative w-full overflow-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="text-xs text-gray-500 uppercase bg-gray-50/50 border-b">
                            <tr>
                                <th class="px-4 py-3 w-[50px] text-center">Tipo</th>
                                <th class="px-4 py-3 w-[120px]">Data</th>
                                <th class="px-4 py-3">Descrizione</th>
                                <th class="px-4 py-3 text-right text-gray-700 w-[140px]">Addebiti <span class="block text-[9px] text-gray-400 font-normal normal-case">(Dare)</span></th>
                                <th class="px-4 py-3 text-right text-gray-700 w-[140px]">Pagamenti <span class="block text-[9px] text-gray-400 font-normal normal-case">(Avere)</span></th>
                                <th class="px-4 py-3 text-right bg-gray-50 text-gray-800 w-[160px]">Saldo</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            
                            <tr class="bg-yellow-50/30">
                                <td class="px-4 py-4 text-center">
                                    <div class="w-8 h-8 rounded-full bg-yellow-100 flex items-center justify-center mx-auto text-yellow-600 shadow-sm border border-yellow-200"><Landmark class="w-4 h-4" /></div>
                                </td>
                                <td class="px-4 py-4 text-sm font-medium text-gray-700">{{ toItalian(esercizio.data_inizio) }}</td>
                                <td class="px-4 py-4 font-semibold text-gray-800 text-sm">Saldo iniziale esercizio</td>
                                <td class="px-4 py-4 text-right text-gray-300">-</td>
                                <td class="px-4 py-4 text-right text-gray-300">-</td>
                                <td class="px-4 py-4 text-right font-bold" :class="saldoInizialeColorClass">{{ stats.saldo_iniziale }}</td>
                            </tr>

                            <tr v-if="timeline.length === 0">
                                <td colspan="6" class="px-6 py-12 text-center text-muted-foreground">Nessun movimento registrato.</td>
                            </tr>

                            <tr v-for="riga in timeline" :key="riga.id" class="hover:bg-gray-50 transition-colors group">
                                <td class="px-4 py-3 align-top">
                                    <div class="flex justify-center mt-1">
                                        <div v-if="riga.tipo_icona === 'bill'" class="w-8 h-8 rounded-full bg-white flex items-center justify-center text-gray-500 border border-gray-200 shadow-sm"><FileText class="w-4 h-4" /></div>
                                        <div v-else-if="riga.tipo_icona === 'payment'" class="w-8 h-8 rounded-full bg-emerald-100 flex items-center justify-center text-emerald-600 border border-emerald-200 shadow-sm"><Banknote class="w-4 h-4" /></div>
                                        <div v-else-if="riga.tipo_icona === 'landmark'" class="w-8 h-8 rounded-full bg-yellow-50 flex items-center justify-center text-yellow-600 border border-yellow-200 shadow-sm"><Landmark class="w-4 h-4" /></div>
                                        <div v-else-if="riga.tipo_icona === 'rotate-ccw'" class="w-8 h-8 rounded-full bg-blue-50 flex items-center justify-center text-blue-600 border border-blue-200 shadow-sm"><RotateCcw class="w-4 h-4" /></div>
                                        <div v-else class="w-8 h-8 rounded-full bg-gray-50 flex items-center justify-center text-gray-500 border border-gray-200 shadow-sm"><HelpCircle class="w-4 h-4" /></div>
                                    </div>
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <div class="text-sm font-medium text-gray-700">{{ riga.data }}</div>
                                    <div v-if="riga.protocollo" class="mt-1"><Badge variant="outline" class="text-[9px] px-1 h-4 font-mono text-gray-400 border-gray-200">{{ riga.protocollo }}</Badge></div>
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="font-semibold text-gray-800 text-sm">{{ riga.descrizione }}</span>
                                        <Badge v-if="riga.gestione" variant="secondary" class="text-[9px] h-4 px-1.5 bg-gray-100 text-gray-500 font-normal">{{ riga.gestione }}</Badge>
                                    </div>
                                    <p v-if="riga.note" class="text-xs text-blue-600 italic mb-1">Note: {{ riga.note }}</p>
                                    <div v-if="riga.dettagli && riga.dettagli.length > 0" class="flex flex-col gap-1 mt-1">
                                        <div v-for="(item, index) in riga.dettagli" :key="index" class="flex items-center flex-wrap gap-2">
                                            
                                            <span class="text-[11px] text-gray-500 flex items-center gap-1.5">
                                                <span class="w-1 h-1 rounded-full bg-gray-300 shrink-0"></span> {{ item.text }}
                                            </span>
                                            
                                            <span v-if="item.status" class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded border text-[9px] font-bold uppercase tracking-wider" :class="getStatoConfig(item.status).class">
                                                <component :is="getStatoConfig(item.status).icon" class="w-3 h-3" />
                                                <span>{{ getStatoConfig(item.status).label }}</span>
                                            </span>
                                        </div>
                                    </div>
                                </td>
                                
                                <td class="px-4 py-3 text-right align-top">
                                    <div v-if="riga.dare > 0" class="flex items-center justify-end gap-1.5">
                                        
                                        <span :class="getImportoStyle(riga)">{{ euro(riga.dare / 100) }}</span>
                                        
                                       <TooltipProvider v-if="riga.breakdown" :delayDuration="0">
                                            <Tooltip>
                                                <TooltipTrigger as-child>
                                                    <div class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-blue-50 text-blue-500 hover:bg-blue-100 hover:text-blue-600 transition-colors cursor-help shadow-sm border border-blue-100">
                                                        <Info class="w-3 h-3" />
                                                    </div>
                                                </TooltipTrigger>
                                                <TooltipContent side="right" class="bg-slate-900 border-slate-700 text-slate-200 p-4 w-80 shadow-xl rounded-lg z-50">
                                                    
                                                    <div class="text-[10px] font-bold text-slate-400 mb-3 uppercase tracking-wider border-b border-slate-700 pb-1 flex justify-between">
                                                        <span>
                                                            {{ 
                                                              riga.breakdown.type === 'incasso' ? 'Dettaglio Incasso' : 
                                                              (riga.breakdown.type === 'storno' ? 'Dettaglio Storno' : 'Dettaglio Addebito') 
                                                            }}
                                                            <span v-if="riga.breakdown.immobile !== 'Generico'">(Int. {{ riga.breakdown.immobile }})</span>
                                                        </span>
                                                    </div>

                                                    <div class="space-y-2 text-xs">
                                                        <div class="flex justify-between items-center text-slate-400">
                                                            <span class="flex items-center gap-1">
                                                                <div class="w-1.5 h-1.5 rounded-full" :class="riga.breakdown.start < 0 ? 'bg-emerald-500' : (riga.breakdown.start > 0 ? 'bg-red-500' : 'bg-gray-500')"></div>
                                                                <span>Saldo precedente:</span>
                                                            </span>
                                                            <span class="font-mono">{{ euro(riga.breakdown.start) }}</span>
                                                        </div>

                                                        <div class="flex justify-between items-center text-white">
                                                            <span class="pl-2.5">
                                                                Movimento in {{ riga.breakdown.type === 'incasso' ? 'Avere' : 'Dare' }}:
                                                            </span>
                                                            <span class="font-mono font-bold" :class="riga.breakdown.type === 'storno' ? 'text-slate-400 line-through' : ''">
                                                                {{ riga.breakdown.type === 'incasso' ? '-' : '+' }} {{ euro(riga.breakdown.cost) }}
                                                            </span>
                                                        </div>

                                                        <template v-if="riga.breakdown.type === 'emissione' && riga.breakdown.saldo_usato && riga.breakdown.saldo_usato !== 0">
                                                            <div class="my-1.5 pl-2.5 border-l-2 border-slate-600 ml-1 py-0.5 space-y-1 text-[11px]">
                                                                <div class="flex justify-between items-center text-slate-300">
                                                                    <span class="italic text-slate-400">Quota pura:</span>
                                                                    <span class="font-mono">{{ euro(riga.breakdown.cost) }}</span>
                                                                </div>
                                                                <div class="flex justify-between items-center text-slate-300">
                                                                    <span class="italic text-slate-400">
                                                                        {{ riga.breakdown.saldo_usato > 0 ? '+ Recupero Debito:' : '- Sconto Credito:' }}
                                                                    </span>
                                                                    <span class="font-mono">{{ euro(riga.breakdown.saldo_usato) }}</span>
                                                                </div>
                                                                <div class="flex justify-between items-center font-bold text-white pt-1">
                                                                    <span>Da pagare per questa quota:</span>
                                                                    <span class="font-mono text-amber-400">{{ euro(riga.breakdown.totale_richiesto) }}</span>
                                                                </div>
                                                            </div>
                                                        </template>
                                                        <div class="border-t border-slate-700 my-2 pt-2">
                                                            <div class="flex justify-between items-center font-bold text-sm">
                                                                <span class="text-white">Nuovo saldo progressivo:</span>
                                                                <span class="font-mono" :class="riga.breakdown.end < 0 ? 'text-emerald-400' : (riga.breakdown.end > 0 ? 'text-red-400' : 'text-white')">
                                                                    {{ euro(riga.breakdown.end) }}
                                                                </span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </TooltipContent>
                                            </Tooltip>
                                        </TooltipProvider>
                                    </div>
                                    <span v-else class="text-gray-200 text-xs">-</span>
                                </td>

                                <td class="px-4 py-3 text-right align-top">
                                    <span v-if="riga.avere > 0" class="text-emerald-600 font-bold font-mono text-sm bg-emerald-50 px-2 py-0.5 rounded-md border border-emerald-100">{{ euro(riga.avere / 100) }}</span>
                                    <span v-else class="text-gray-200 text-xs">-</span>
                                </td>
                                
                                <td class="px-4 py-3 text-right align-top bg-gray-50/30 group-hover:bg-gray-100/50 border-l border-gray-100">
                                    <span class="font-mono font-bold text-sm" :class="riga.saldo > 0 ? 'text-red-600' : (riga.saldo < 0 ? 'text-emerald-600' : 'text-gray-400')">{{ euro(riga.saldo / 100) }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        </div>
    </GestionaleLayout>
</template>

<style scoped>
.overflow-y-auto::-webkit-scrollbar { width: 4px; }
.overflow-y-auto::-webkit-scrollbar-track { background: transparent; }
.overflow-y-auto::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 4px; }
.overflow-y-auto::-webkit-scrollbar-thumb:hover { background: #cbd5e1; }
</style>
