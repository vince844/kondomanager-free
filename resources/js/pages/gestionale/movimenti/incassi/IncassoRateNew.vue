<script setup lang="ts">
import { ref, watch, computed, onMounted, nextTick } from 'vue'; 
import { useForm, Head } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { 
    AlertCircle, CheckCircle2, Calculator, RotateCcw, 
    User, Building, ArrowRight, FileText, Receipt, 
    ArrowRightLeft, Info 
} from 'lucide-vue-next';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter'; 
import { usePermission } from "@/composables/permissions";
import { usePaymentDistribution } from '@/composables/usePaymentDistribution';
import { useDebitiLoader } from '@/composables/useDebitiLoader';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import type { Rata } from '@/types/gestionale/rata'; 
import MoneyInput from '@/components/MoneyInput.vue';
import InputError from '@/components/InputError.vue';

const props = defineProps<{
    condominio: any;
    risorse: any[];
    condomini: any[];
    immobili: any[];
    gestioni: any[];
}>();

const { euro } = useCurrencyFormatter({ fromCents: false }); 
const { generateRoute } = usePermission();

const moneyOptions = ref({
    prefix: '€ ',              
    suffix: '',              
    thousands: '.',          
    decimal: ',',          
    precision: 2,            
    allowBlank: false,
    masked: true 
});

const { 
    rawRateList, 
    loadingRate, 
    mode,
    isScaduta,
    setPriorityRataId,
    getRateListByGestione,
    getTotalAllocato,
    getTotaleDebito,
    getBilancioFinale,
    distributeGreedy,
    calculateExcess,
    onManualChange,
    resetAllocation: resetAllocationComposable,
    pagaTutto: pagaTuttoComposable,
    pagaScadute: pagaScaduteComposable,
    syncFormData
} = usePaymentDistribution();

const { fetchDebiti: fetchDebitiAPI } = useDebitiLoader();

const searchMode = ref<'persona' | 'immobile'>('persona');
const selectedImmobileId = ref<number | null>(null);

const form = useForm({
    pagante_id: null as number | null,
    cassa_id: null as number | null,
    gestione_id: null as number | null,
    data_pagamento: new Date().toISOString().substring(0, 10),
    importo_totale: '' as string | number, 
    descrizione: '',
    dettaglio_pagamenti: [] as any[],
    eccedenza: 0,
    related_task_id: null as number | null,
});

const rateList = ref<Rata[]>([]);

// TRADUTTORE VALUTA -> NUMERO
const parseResiduoQuota = (value: string | number) => {
    if (typeof value === 'number') return value;
    if (!value) return 0;
    let str = String(value).replace('€', '').trim();
    if (str.includes(',')) {
        str = str.replace(/\./g, '');
        str = str.replace(',', '.');
    }
    return parseFloat(str.replace(/[^\d.-]/g, '')) || 0;
};

// RILEVATORE SALDI COMPENSATI
const hasSaldoMisto = (r: any) => {
    if (Math.abs(parseResiduoQuota(r.residuo)) < 0.01 && r.dettaglio_quote && r.dettaglio_quote.length > 1) {
        const hasDebito = r.dettaglio_quote.some((q: any) => parseResiduoQuota(q.residuo) > 0);
        const hasCredito = r.dettaglio_quote.some((q: any) => parseResiduoQuota(q.residuo) < 0);
        return hasDebito && hasCredito;
    }
    return false;
};

// RILEVATORE RATA ZERO / SALDO PREGRESSO
const isRataZero = (r: any) => {
    const desc = (r.descrizione || '').toLowerCase();
    return desc.includes('saldo') || desc.includes('rata 0') || desc.includes('pregresso');
};

const importoNumerico = computed(() => parseResiduoQuota(form.importo_totale));

const totalAllocato = computed(() => {
    return form.dettaglio_pagamenti.reduce((sum, p) => sum + p.importo, 0);
});

const totaleDebito = computed(() => getTotaleDebito(rateList.value));
const bilancioFinale = computed(() => getBilancioFinale(totaleDebito.value, importoNumerico.value));
const showOnlyOverdue = ref(false);

// RILEVATORE PRESENZA SALDO IN LISTA
const hasSaldoPregressoInLista = computed(() => {
    return rateList.value.some(r => isRataZero(r));
});

// ANTEPRIMA CONTABILE PULITA
const previewContabile = computed(() => {
    const pagamenti = form.dettaglio_pagamenti;
    const importo = importoNumerico.value;
    const eccedenza = form.eccedenza;

    if (importo <= 0) {
        return { hasData: false, righe: [], anticipo: 0, allocato_rate: 0, totale_versato: 0 };
    }

    const righePagate = pagamenti.map(p => {
        const r = rateList.value.find(rate => rate.id === p.rata_id);
        if (!r) return null;
        
        const baseRata = parseResiduoQuota(r.residuo_originale || r.importo_totale || r.residuo);
        const residuoDopoPagamento = baseRata - p.importo;
        
        return {
            id: r.id,
            descrizione: r.descrizione,
            pagato: p.importo,
            status: (residuoDopoPagamento <= 0.01) ? 'SALDATA' : 'PARZIALE',
            residuo_futuro: Math.max(0, residuoDopoPagamento),
            tipo: 'normale'
        };
    }).filter(Boolean) as any[];

    return {
        hasData: righePagate.length > 0 || importo > 0,
        totale_versato: importo,
        allocato_rate: totalAllocato.value,
        anticipo: eccedenza,
        righe: righePagate
    };
});

const fetchDebiti = async (params: { anagrafica_id?: number | null; immobile_id?: number | null }) => {
    loadingRate.value = true;
    try {
        const result = await fetchDebitiAPI(
            (name: string, params: any) => route(generateRoute(name), params),
            props.condominio.id,
            params,
            isScaduta
        );
        rawRateList.value = result as Rata[];
        if (importoNumerico.value > 0) runDistribution();
    } finally {
        loadingRate.value = false;
    }
};

const runDistribution = async () => { 
    await nextTick(); 
    if (mode.value === 'auto') distributeAuto(); 
    else calculateExcessOnly(); 
};

const distributeAuto = () => { 
    form.eccedenza = distributeGreedy(rateList.value, importoNumerico.value); 
    rateList.value = [...rateList.value]; 
    syncForm(); 
};

const handleManualChange = (rata: any, val: string | number) => { 
    onManualChange(rata, val.toString()); 
    calculateExcessOnly(); 
    syncForm(); 
};

const calculateExcessOnly = () => { form.eccedenza = calculateExcess(rateList.value, importoNumerico.value); };
const toggleMode = () => { mode.value = mode.value === 'auto' ? 'manual' : 'auto'; if (mode.value === 'auto') distributeAuto(); };

const resetAllocation = () => {
    // 1. Azzeriamo l'input dell'importo totale
    form.importo_totale = '';

    // 2. Eseguiamo il reset logico (mette i da_pagare a 0 e passa in Manuale)
    resetAllocationComposable(rateList.value);

    // 3. CHIRURGICO: Forziamo il refresh della lista per svuotare i MoneyInput in tabella
    rateList.value = [...rateList.value];

    // 4. Aggiorniamo i calcoli di eccedenza e il payload del form
    calculateExcessOnly(); 
    syncForm(); 
};

const pagaTutto = () => { 
    const somma = pagaTuttoComposable(rateList.value); 
    form.importo_totale = somma; 
    calculateExcessOnly(); 
    syncForm(); 
};

const pagaScadute = async () => { 
    // 1. Cambiamo la modalità
    mode.value = 'manual'; 
    
    // 2. Eseguiamo il calcolo nel composable
    const somma = pagaScaduteComposable(rateList.value); 
    
    // 3. Aggiorniamo l'importo totale nel form
    form.importo_totale = somma; 
    
    // TRUCCO CHIRURGICO: Forziamo il refresh della lista per far apparire i numeri
    rateList.value = [...rateList.value];
    
    await nextTick();
    calculateExcessOnly(); 
    syncForm(); 
};

const syncForm = () => { form.dettaglio_pagamenti = syncFormData(rateList.value); };

const toggleSearchMode = (newMode: 'persona' | 'immobile') => {
    if (searchMode.value !== newMode) {
        searchMode.value = newMode;
        rawRateList.value = []; 
        selectedImmobileId.value = null; 
        form.pagante_id = null; 
        form.importo_totale = ''; 
    }
};

const submit = () => {
    const payload = form.transform((data) => ({
        ...data,
        importo_totale: importoNumerico.value
    }));
    payload.post(route(generateRoute('gestionale.movimenti-rate.store'), props.condominio.id), {
        preserveScroll: true,
        onSuccess: () => {
            rawRateList.value = [];
            form.reset();
            mode.value = 'auto';
            searchMode.value = 'persona';
            selectedImmobileId.value = null;
        }
    });
};

watch(() => form.pagante_id, (newVal) => { if (searchMode.value === 'persona' && newVal) fetchDebiti({ anagrafica_id: newVal }); });
watch(selectedImmobileId, (newVal) => { if (searchMode.value === 'immobile' && newVal) fetchDebiti({ immobile_id: newVal }); });
watch(importoNumerico, () => { runDistribution(); });

watch([rawRateList, () => form.gestione_id, showOnlyOverdue], () => {
    // 1. Prendiamo la lista base filtrata per gestione
    let list = getRateListByGestione(form.gestione_id);

    // 2. Se il toggle è attivo, filtriamo solo le scadute
    // Manteniamo sempre visibile la Rata 0 (Saldo Pregresso) perché è il punto di partenza
    if (showOnlyOverdue.value) {
        list = list.filter(r => isScaduta(r.data_scadenza || r.scadenza_human) || isRataZero(r));
    }

    rateList.value = list;
    runDistribution();
}, { deep: true, immediate: true });

onMounted(async () => {
    const params = new URLSearchParams(window.location.search);
    const taskId = params.get('related_task_id');
    const prefillAnagrafica = params.get('prefill_anagrafica_id');
    const prefillImporto = params.get('prefill_importo');
    const prefillDesc = params.get('prefill_descrizione');
    const prefillRataId = params.get('prefill_rata_id');
    if (taskId) form.related_task_id = parseInt(taskId);
    if (prefillDesc) form.descrizione = prefillDesc;
    if (prefillRataId) setPriorityRataId(parseInt(prefillRataId)); else setPriorityRataId(null);
    if (prefillImporto) form.importo_totale = parseFloat(prefillImporto);
    if (prefillAnagrafica) form.pagante_id = parseInt(prefillAnagrafica);
});
</script>

<template>
    <Head title="Registra Incasso" />
  
    <GestionaleLayout>
        <div class="space-y-4 w-full mx-auto px-4 py-4 h-[calc(100vh-100px)] flex flex-col">
            
            <div class="flex items-center justify-between shrink-0">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Nuovo incasso rate</h1>
                    <p class="text-sm text-muted-foreground">Registrazione incasso per il pagamento delle rate condominiali</p>
                </div>
                <Badge variant="outline" class="font-mono bg-white">{{ new Date().toLocaleDateString() }}</Badge>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-3 flex-1 min-h-0">
                
                <div class="lg:col-span-4 h-full flex flex-col bg-white rounded-xl border shadow-sm overflow-hidden">
                    <div class="p-4 flex-1 overflow-y-auto space-y-4">
                        
                        <div class="space-y-2">
                            <Label class="text-[11px] uppercase text-slate-500 font-bold tracking-wider mb-1 block">Cerca debiti per anagrafica o immobile</Label>
                            <div class="grid grid-cols-2 gap-1 bg-slate-100 p-1 rounded-lg">
                                <button @click="toggleSearchMode('persona')" class="flex items-center justify-center py-1 text-xs font-medium rounded-md transition-all" :class="searchMode === 'persona' ? 'bg-white text-primary shadow-sm font-bold' : 'text-slate-500 hover:text-slate-700'">
                                    <User class="w-3.5 h-3.5 mr-1.5"/> Persona
                                </button>
                                <button @click="toggleSearchMode('immobile')" class="flex items-center justify-center py-1 text-xs font-medium rounded-md transition-all" :class="searchMode === 'immobile' ? 'bg-white text-primary shadow-sm font-bold' : 'text-slate-500 hover:text-slate-700'">
                                    <Building class="w-3.5 h-3.5 mr-1.5"/> Immobile
                                </button>
                            </div>

                            <div class="mt-3">
                                <Label class="text-[11px] uppercase text-slate-500 font-bold tracking-wider mb-1 block">
                                    {{ searchMode === 'persona' ? 'Seleziona anagrafica' : 'Seleziona unità immobiliare' }}
                                </Label>

                                <v-select 
                                    v-if="searchMode === 'persona'"
                                    :options="condomini" 
                                    v-model="form.pagante_id" 
                                    label="nome" 
                                    :reduce="(c: any) => c.id" 
                                    class="w-full bg-white text-sm" 
                                    placeholder="Cerca per nome o cognome..."
                                >
                                    <template #option="{ nome, indirizzo, codice_fiscale }">
                                        <div class="flex flex-col py-0.5">
                                            <span class="font-medium text-sm text-slate-800">{{ nome }}</span>
                                            <span class="text-[11px] text-slate-400 truncate">{{ indirizzo || codice_fiscale || 'Nessun dettaglio aggiuntivo' }}</span>
                                        </div>
                                    </template>
                                </v-select>

                                <div v-else class="space-y-3">
                                    <v-select 
                                        :options="immobili" 
                                        v-model="selectedImmobileId" 
                                        :getOptionLabel="(i: any) => `Int. ${i.interno}${i.descrizione ? ' - ' + i.descrizione : ''}`"
                                        :reduce="(i: any) => i.id" 
                                        class="w-full bg-white text-sm" 
                                        placeholder="Cerca per interno o descrizione..."
                                    >
                                        <template #option="{ interno, descrizione, nome }">
                                            <div class="flex flex-col py-0.5">
                                                <span class="font-medium text-sm text-slate-800">
                                                    Interno {{ interno }} 
                                                    <span v-if="descrizione" class="text-slate-500 font-normal">- {{ descrizione }}</span>
                                                </span>
                                                <span class="text-[11px] text-slate-400 truncate">{{ nome || 'Unità immobiliare' }}</span>
                                            </div>
                                        </template>
                                    </v-select>

                                    <div>
                                        <Label class="text-[11px] uppercase text-slate-500 font-bold tracking-wider mb-1 block">Intestatario della ricevuta</Label>
                                        <v-select 
                                            :options="condomini" 
                                            v-model="form.pagante_id" 
                                            label="nome" 
                                            :reduce="(c: any) => c.id" 
                                            class="w-full bg-white text-sm" 
                                            placeholder="A chi intesterai il pagamento?"
                                        >
                                            <template #option="{ nome, indirizzo, codice_fiscale }">
                                                <div class="flex flex-col py-0.5">
                                                    <span class="font-medium text-sm text-slate-800">{{ nome }}</span>
                                                    <span class="text-[11px] text-slate-400 truncate">{{ indirizzo || codice_fiscale || 'Nessun dettaglio aggiuntivo' }}</span>
                                                </div>
                                            </template>
                                        </v-select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="space-y-3">
                            <Label class="text-[11px] uppercase text-slate-500 font-bold tracking-wider mb-1 block">Importo versato</Label>
                            <MoneyInput 
                                id="importo_totale"
                                v-model="form.importo_totale" 
                                :money-options="moneyOptions"
                                :lazy="false"
                                class="h-10 text-lg font-bold font-mono shadow-sm focus:ring-2 focus:ring-primary/20 border-slate-200 w-full rounded-md border bg-background px-3 py-2" 
                                placeholder="0,00" 
                            />
                        </div>

                        <div class="space-y-3">
                            <div>
                                <Label class="text-[11px] uppercase text-slate-500 font-bold tracking-wider mb-1 block">Risorsa finanziaria</Label>
                                <v-select 
                                    :options="risorse" 
                                    v-model="form.cassa_id" 
                                    label="nome" 
                                    :reduce="(c: any) => c.id" 
                                    class="w-full bg-white text-sm shadow-sm" 
                                    placeholder="Dove versi i soldi?"
                                >
                                    <template #option="{ nome, tipo, conto_corrente }">
                                        <div class="flex flex-col py-0.5">
                                            <div class="flex items-center justify-between gap-2">
                                                <span class="font-bold text-sm text-slate-800">{{ nome }}</span>
                                                <span class="text-[9px] uppercase font-black px-1.5 py-0.5 rounded bg-slate-100 text-slate-500 border border-slate-200">{{ tipo }}</span>
                                            </div>
                                            <span v-if="conto_corrente?.iban" class="text-[10px] text-slate-400 font-mono mt-0.5">{{ conto_corrente.iban }}</span>
                                        </div>
                                    </template>
                                </v-select>
                                <InputError :message="form.errors.cassa_id" class="mt-1" />
                            </div>
                            
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <Label class="text-[11px] uppercase text-slate-500 font-bold tracking-wider mb-1 block">Data versamento</Label>
                                    <Input type="date" v-model="form.data_pagamento" class="h-9 text-sm border-slate-200 shadow-sm"/>
                                    <InputError :message="form.errors.data_pagamento" class="mt-1" />
                                </div>         
                                <div>
                                    <Label class="text-[11px] uppercase text-slate-500 font-bold tracking-wider mb-1 block">Causale</Label>
                                    <div class="relative">
                                        <FileText class="w-3.5 h-3.5 absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400"/>
                                        <Input placeholder="Es. Bonifico" v-model="form.descrizione" class="pl-8 h-9 text-sm shadow-sm border-slate-200"/>
                                    </div>
                                </div>
                            </div>
                            
                            <div>
                                <Label class="text-[11px] uppercase text-slate-500 font-bold tracking-wider mb-1 block">Gestione (filtro)</Label>
                                <v-select 
                                    :options="gestioni" 
                                    v-model="form.gestione_id" 
                                    label="nome" 
                                    :reduce="(g: any) => g.id" 
                                    class="w-full bg-slate-50 text-sm focus-within:bg-white transition-colors" 
                                    placeholder="Tutte (automatica)"
                                >
                                    <template #option="{ nome, tipo }">
                                        <div class="flex items-center justify-between py-0.5">
                                            <span class="text-sm text-slate-700">{{ nome }}</span>
                                            <span v-if="tipo" class="text-[9px] uppercase font-bold px-1.5 py-0.5 rounded bg-slate-200 text-slate-500">{{ tipo }}</span>
                                        </div>
                                    </template>
                                </v-select>
                            </div>
                        </div>
                    </div>

                    <div class="p-4 bg-slate-50 border-t border-slate-200 shrink-0">
                        <div class="flex justify-between items-center text-xs mb-3 px-1">
                            <span class="text-slate-500 uppercase tracking-wider font-semibold">Totale allocato:</span>
                            <span class="font-bold text-slate-800 text-sm">{{ euro(totalAllocato) }}</span>
                        </div>
                        <Button @click="submit" :disabled="form.processing || importoNumerico <= 0 || !form.pagante_id" class="w-full h-11 bg-emerald-600 hover:bg-emerald-500 text-white font-bold shadow-md shadow-emerald-600/20 transition-all text-sm">
                            <CheckCircle2 class="w-4 h-4 mr-2" /> Conferma incasso
                        </Button>
                    </div>
                </div>

                <div class="lg:col-span-8 h-full flex flex-col gap-4 overflow-hidden">
                    
                    <div class="bg-white rounded-xl border shadow-sm flex flex-col flex-1 min-h-0 overflow-hidden">
                        <div class="p-3 border-b bg-gray-50 flex justify-between items-center shrink-0">
                            <div class="flex items-center gap-3">
                                <h3 class="font-semibold text-gray-900 text-sm">Ripartizione debito</h3>
                                <Badge v-if="rateList.length" variant="secondary" class="bg-white border text-gray-600 text-[10px]">{{ rateList.length }} Rate</Badge>
                            </div>
                            <div v-if="rateList.length" class="flex items-center gap-2 text-xs">
                                <span class="text-gray-500">Debito Totale:</span>
                                <span class="font-bold text-gray-900 mr-2">{{ euro(totaleDebito) }}</span>
                                <ArrowRight class="w-3 h-3 text-gray-300" />
                                <div class="flex items-center gap-1 px-2 py-0.5 rounded border transition-colors shadow-sm" :class="bilancioFinale.class">
                                    <span class="font-medium">{{ bilancioFinale.label }}</span>
                                    <span class="font-bold">{{ euro(bilancioFinale.value) }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="px-3 py-2 border-b bg-gray-50/50 flex justify-between items-center shrink-0">
                            <div class="flex items-center gap-4">
                                <div class="flex items-center bg-white border rounded-md px-1 py-0.5 h-7 shadow-sm cursor-pointer select-none" @click="toggleMode">
                                    <div class="px-2 py-0.5 rounded text-[10px] font-bold transition-all uppercase" :class="mode === 'auto' ? 'bg-primary text-white shadow-sm' : 'text-gray-400 hover:text-gray-600'">Automatico</div>
                                    <div class="px-2 py-0.5 rounded text-[10px] font-bold transition-all uppercase" :class="mode === 'manual' ? 'bg-primary text-white shadow-sm' : 'text-gray-400 hover:text-gray-600'">Manuale</div>
                                </div>

                                <div class="flex items-center gap-2 ml-2 border-l pl-4">
                                    <input 
                                        type="checkbox" 
                                        id="toggle-scadute" 
                                        v-model="showOnlyOverdue" 
                                        class="w-3.5 h-3.5 text-primary border-gray-300 rounded focus:ring-primary cursor-pointer"
                                    >
                                    <label for="toggle-scadute" class="text-[10px] font-bold uppercase text-slate-500 cursor-pointer select-none">
                                        Mostra scadute
                                    </label>
                                </div>
                            </div>
                            <div class="flex items-center gap-2" v-if="rateList.length">
                                <Button size="sm" variant="ghost" @click="resetAllocation" class="h-7 text-xs text-gray-500 hover:text-red-600 hover:bg-red-50 px-2"><RotateCcw class="w-3 h-3 mr-1"/> Resetta</Button>
                                <div class="h-3 w-px bg-gray-300 mx-1"></div>
                                <Button size="sm" variant="outline" @click="pagaScadute" class="h-7 text-xs bg-white border-gray-200 text-gray-600">Paga scadute</Button>
                                <Button size="sm" variant="outline" @click="pagaTutto" class="h-7 text-xs bg-white border-gray-200 text-gray-600">Paga tutto</Button>
                            </div>
                        </div>

                        <div class="flex-1 overflow-y-auto custom-scrollbar">
                            <div v-if="rateList.length > 0 && !hasSaldoPregressoInLista" class="bg-emerald-50/80 border-b border-emerald-100 px-3 py-2 flex items-center text-emerald-700 shrink-0">
                                <CheckCircle2 class="w-4 h-4 mr-2 text-emerald-500" />
                                <span class="text-[11px] font-medium">Situazione pregressa regolare. Procedi con l'incasso delle rate ordinarie.</span>
                            </div>

                            <table v-if="rateList.length" class="w-full text-sm border-collapse">
                                <thead class="bg-white sticky top-0 z-10 shadow-sm text-[10px] uppercase text-gray-400 font-bold tracking-wider">
                                    <tr>
                                        <th class="p-3 pl-4 text-left bg-gray-50/95 backdrop-blur border-b border-gray-100">Scadenza</th>
                                        <th class="p-3 text-left bg-gray-50/95 backdrop-blur border-b border-gray-100">Rata / Intestatario</th>
                                        <th class="p-3 text-right bg-gray-50/95 backdrop-blur border-b border-gray-100">Residuo</th>
                                        <th class="p-3 pr-4 text-right bg-gray-50/95 backdrop-blur border-b border-gray-100 w-[140px]">Importo</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-50">
                                    <tr v-for="r in rateList" :key="r.id" class="transition-colors group" :class="[r.da_pagare > 0 ? 'bg-emerald-50/20' : 'hover:bg-gray-50', parseResiduoQuota(r.residuo) < 0 ? 'bg-blue-50/30' : '']">
                                        <td class="p-3 pl-4 align-top">
                                            <div class="flex flex-col">
                                                <span class="font-mono text-xs font-medium text-gray-600">{{ r.scadenza_human }}</span>
                                                <span v-if="r.is_emitted === false" class="mt-1 inline-flex items-center text-[9px] font-bold text-amber-600 bg-amber-50 border border-amber-200 px-1.5 py-0.5 rounded w-fit uppercase tracking-tighter" title="Questa rata non è stata ancora emessa contabilmente">
                                                    <AlertCircle class="w-2.5 h-2.5 mr-1" /> No emissione
                                                </span>
                                                <span v-if="r.scaduta && parseResiduoQuota(r.residuo) > 0" class="text-[9px] text-red-500 font-bold uppercase mt-1 flex items-center bg-red-50 w-fit px-1 rounded">
                                                    <AlertCircle class="w-2.5 h-2.5 mr-1"/> Scaduta
                                                </span>
                                            </div>
                                        </td>
                                        <td class="p-3 align-top">
                                            <div class="text-xs font-bold text-gray-800 mb-0.5">{{ r.descrizione }}</div>
    
                                            <div class="text-[11px] text-blue-600 font-medium flex items-center gap-1 max-w-[200px]" :title="r.intestatari_full || r.intestatario">
                                                <User class="w-3 h-3 opacity-70 shrink-0"/> 
                                                <span class="truncate cursor-help">{{ r.intestatario }}</span>
                                            </div>
                                            
                                            <div class="text-[10px] text-gray-400 mt-0.5 flex flex-wrap items-center gap-1">
                                                <span>{{ r.gestione }}</span>
                                                <div v-if="r.dettaglio_quote && r.dettaglio_quote.length > 0">
                                                    <TooltipProvider :delayDuration="0">
                                                        <Tooltip>
                                                            <TooltipTrigger as-child>
                                                                <div class="ml-1 inline-flex items-center cursor-help"><Info class="w-3 h-3 text-blue-400" /></div>
                                                            </TooltipTrigger>
                                                            
                                                            <TooltipContent side="bottom" class="bg-slate-900 border-slate-700 text-slate-200 p-4 w-80 shadow-2xl rounded-lg z-[100]">
                                                                <div class="text-[10px] font-bold text-slate-400 mb-3 uppercase tracking-wider border-b border-slate-700 pb-1 text-center">
                                                                    <span v-if="parseResiduoQuota(r.residuo) < 0">Dettaglio Credito</span>
                                                                    <span v-else>Analisi Debito</span>
                                                                </div>
                                                                <ul class="space-y-4">
                                                                    <li v-for="(dett, idx) in r.dettaglio_quote" :key="idx" class="text-[11px]">
                                                                        <div class="font-bold text-white mb-1.5 pb-0.5 border-b border-slate-700/50 flex items-center justify-between">
                                                                            <div class="flex items-center gap-1.5">
                                                                                <Building class="w-3 h-3 text-slate-500"/> 
                                                                                <span>{{ dett.unita }}</span>
                                                                            </div>
                                                                            
                                                                            <div class="text-[10px] text-blue-300 flex items-center justify-end gap-1 max-w-[150px]" :title="dett.anagrafica">
                                                                                <User class="w-2.5 h-2.5 opacity-70 shrink-0"/> 
                                                                                <span class="truncate">{{ dett.anagrafica }}</span>
                                                                                
                                                                                <span v-if="dett.ruolo" class="ml-0.5 bg-blue-950 text-blue-200 text-[8px] font-black px-1.5 py-0.5 rounded border border-blue-800 leading-none shrink-0" :title="dett.ruolo === 'P' ? 'Proprietario' : (dett.ruolo === 'I' ? 'Inquilino' : 'Usufruttuario')">
                                                                                    {{ dett.ruolo }}
                                                                                </span>
                                                                            </div>
                                                                        </div>
                                                                        
                                                                        <div>
                                                                            <div class="flex justify-between items-center pl-2 mb-1">
                                                                                <span class="text-slate-400">Quota rata:</span>
                                                                                <span class="font-mono font-medium text-slate-200">+ {{ euro(Math.abs(dett.componente_spesa)) }}</span>
                                                                            </div>
                                                                            <div class="flex justify-between items-center pl-2 pt-1 border-t border-slate-800">
                                                                                <span class="text-[10px] text-slate-500 font-bold uppercase">Totale:</span>
                                                                                <span class="font-mono font-bold" :class="parseResiduoQuota(dett.residuo) < 0 ? 'text-emerald-500' : 'text-orange-400'">{{ euro(parseResiduoQuota(dett.residuo)) }}</span>
                                                                            </div>
                                                                        </div>
                                                                    </li>
                                                                </ul>
                                                                <div class="border-t-2 border-slate-600 pt-2 mt-3 flex justify-between items-center bg-slate-800/50 p-2 rounded -mx-2">
                                                                    <span class="text-xs font-bold text-white uppercase tracking-wide">Netto Rata:</span>
                                                                    <div class="text-right">
                                                                        <span class="font-mono font-bold text-sm" :class="parseResiduoQuota(r.residuo) < 0 ? 'text-emerald-400' : 'text-white'">
                                                                            {{ euro(r.residuo) }}
                                                                        </span>
                                                                    </div>
                                                                </div>
                                                            </TooltipContent>
                                                        </Tooltip>
                                                    </TooltipProvider>
                                                </div>
                                                <span v-else>• {{ r.unita }}</span>
                                            </div>
                                        </td>
                                        
                                        <td class="p-3 text-right align-top">
                                            <template v-if="hasSaldoMisto(r)">
                                                <div class="flex flex-col items-end">
                                                    <span class="font-mono text-sm font-bold text-red-600">{{ euro(r.residuo) }}</span>
                                                    <div class="mt-1 flex flex-col items-end gap-1">
                                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold bg-red-50 text-red-600 border border-red-200 shadow-sm" title="Attenzione: questa voce contiene un debito e un credito che si annullano a vicenda.">
                                                            <AlertCircle class="w-3 h-3 mr-1" /> SALDO MISTO
                                                        </span>
                                                        <span class="text-[9px] text-slate-500 font-medium">Seleziona l'anagrafica</span>
                                                    </div>
                                                </div>
                                            </template>
                                            
                                            <template v-else>
                                                <div v-if="parseResiduoQuota(r.residuo) < 0" class="flex flex-col items-end pt-1">
                                                    <span class="font-mono text-sm font-bold text-emerald-600">{{ euro(r.residuo) }}</span>
                                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold bg-emerald-50 text-emerald-600 border border-emerald-200 mt-1 uppercase">
                                                        {{ isRataZero(r) ? 'CREDITO PREGRESSO' : 'CREDITO' }}
                                                    </span>
                                                </div>
                                                
                                                <div v-else class="flex flex-col items-end pt-1">
                                                    <span class="font-mono text-sm" :class="isRataZero(r) ? 'font-bold text-red-600' : 'font-semibold text-slate-700'">
                                                        {{ euro(r.residuo) }}
                                                    </span>
                                                    
                                                    <div v-if="isRataZero(r) && parseResiduoQuota(r.residuo) > 0" class="mt-1">
                                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold bg-red-50 text-red-600 border border-red-200">
                                                            DEBITO PREGRESSO
                                                        </span>
                                                    </div>
                                                    
                                                    <div v-if="parseResiduoQuota(r.residuo) > 0 && parseResiduoQuota(r.residuo) < r.importo_totale && !isRataZero(r)" class="mt-1">
                                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold bg-amber-50 text-amber-600 border border-amber-200" :title="'Importo originale: ' + euro(r.importo_totale)">
                                                            <RotateCcw class="w-2.5 h-2.5 mr-1" /> PARZIALE
                                                        </span>
                                                    </div>
                                                </div>
                                            </template>
                                        </td>
                                        <td class="p-3 pr-4 text-right align-top w-[140px]">
                                            <MoneyInput 
                                                v-if="parseResiduoQuota(r.residuo) > 0" 
                                                :id="'rata_' + r.id"
                                                v-model="r.da_pagare" 
                                                @update:modelValue="handleManualChange(r, $event)" 
                                                :disabled="mode === 'auto'" 
                                                :money-options="moneyOptions"
                                                :lazy="false"
                                                class="text-right font-bold h-8 text-sm font-mono transition-all rounded-md border px-2 py-1 w-full outline-none focus:ring-2 focus:ring-primary/20" 
                                                :class="[
                                                    r.da_pagare > 0 ? 'border-emerald-500 bg-white ring-1 ring-emerald-500/20 text-emerald-700' : 'border-slate-200 bg-transparent hover:border-slate-300 text-slate-800',
                                                    mode === 'auto' ? 'opacity-70 cursor-not-allowed bg-slate-50' : 'bg-white'
                                                ]" 
                                                placeholder="0,00" 
                                            />
                                            <div v-else class="text-xs text-slate-400 italic flex items-center justify-end h-8 opacity-80 pr-2">Non pagabile</div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <div v-else class="flex flex-col items-center justify-center h-full text-slate-400 space-y-4 py-12 bg-slate-50/30">
                                <div class="p-4 bg-white rounded-full shadow-sm border border-slate-100"><ArrowRightLeft class="w-8 h-8 opacity-20" /></div>
                                <div class="text-center">
                                    <p class="font-medium text-sm text-slate-500">Nessuna rata visualizzata</p>
                                    <p class="text-xs text-slate-400 mt-1">Seleziona un condomino o un immobile per iniziare</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="bg-slate-900 text-white rounded-xl border border-slate-700 shadow-sm flex flex-col h-[200px] shrink-0 overflow-hidden">
                        <div class="p-3 border-b border-slate-700 flex justify-between items-center bg-slate-800/50 shrink-0">
                            <h3 class="font-semibold text-sm flex items-center">
                                <Receipt class="w-4 h-4 mr-2 text-emerald-400"/> Anteprima registrazione
                            </h3>
                            <div class="flex gap-4 text-xs">
                                <div class="flex items-center gap-1.5">
                                    <span class="text-slate-400">Allocato:</span>
                                    <span class="font-bold text-emerald-400">{{ euro(totalAllocato) }}</span>
                                </div>
                                <div v-if="form.eccedenza > 0" class="flex items-center gap-1.5">
                                    <span class="text-slate-400">Eccedenza:</span>
                                    <span class="font-bold text-blue-400">{{ euro(form.eccedenza) }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="flex-1 overflow-y-auto p-4 custom-scrollbar-dark">
                            <div v-if="previewContabile.hasData" class="space-y-2">
                                <div v-for="riga in previewContabile.righe" :key="riga.id" class="flex justify-between items-start text-xs border-b border-slate-800 pb-2 last:border-0 last:pb-0">
                                    <div class="flex-1 mr-4">
                                        <div class="font-medium text-slate-200">{{ riga.descrizione }}</div>
                                        <div v-if="riga.status === 'PARZIALE'" class="mt-0.5 text-amber-500 text-[10px] font-bold">
                                            Resta da pagare: {{ euro(riga.residuo_futuro) }}
                                        </div>
                                    </div>
                                    <div class="text-right shrink-0">
                                        <div class="font-mono font-bold text-white">{{ euro(riga.pagato) }}</div>
                                        <span v-if="riga.status === 'SALDATA'" class="text-[9px] text-emerald-500 uppercase font-bold tracking-wider">Saldata</span>
                                        <span v-else class="text-[9px] text-amber-500 uppercase font-bold tracking-wider">Parziale</span>
                                    </div>
                                </div>

                                <div v-if="previewContabile.anticipo > 0" class="flex justify-between items-center pt-2 text-xs border-t border-slate-700 mt-2">
                                    <div class="text-blue-400 font-medium">Anticipo / Eccedenza</div>
                                    <div class="font-mono font-bold text-blue-400">+ {{ euro(previewContabile.anticipo) }}</div>
                                </div>
                            </div>

                            <div v-else class="flex flex-col items-center justify-center h-full text-slate-600 text-xs gap-2">
                                <Receipt class="w-8 h-8 opacity-20" />
                                <p>Inserisci un importo per vedere l'anteprima</p>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </GestionaleLayout>
</template>

<style scoped>
:deep(.style-chooser .vs__dropdown-toggle) {
    border-color: #e2e8f0;
    padding: 2px 0;
    border-radius: 6px;
    background-color: white;
    min-height: 36px;
}
:deep(.style-chooser .vs__selected) { font-size: 0.8rem; }

.custom-scrollbar::-webkit-scrollbar { width: 5px; }
.custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
.custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

.custom-scrollbar-dark::-webkit-scrollbar { width: 5px; }
.custom-scrollbar-dark::-webkit-scrollbar-track { background: #1e293b; }
.custom-scrollbar-dark::-webkit-scrollbar-thumb { background: #475569; border-radius: 10px; }
.custom-scrollbar-dark::-webkit-scrollbar-thumb:hover { background: #64748b; }
</style>