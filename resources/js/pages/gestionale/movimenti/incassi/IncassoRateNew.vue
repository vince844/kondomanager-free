<script setup lang="ts">
import { ref, watch, computed } from 'vue';
import { useForm, Head } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { 
    AlertCircle, 
    CheckCircle2, 
    Calculator, 
    RotateCcw, 
    Wallet, 
    User, 
    Building, 
    ArrowRight,
    Euro,
    FileText,
    Receipt
} from 'lucide-vue-next';
import axios from 'axios';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';

import { useFormat } from '@/composables/useFormat';
import { usePermission } from "@/composables/permissions";

const props = defineProps<{
    condominio: any;
    risorse: any[];
    condomini: any[];
    immobili: any[];
    gestioni: any[];
}>();

const { formatCurrency } = useFormat();
const { generateRoute } = usePermission();

const mode = ref<'auto' | 'manual'>('auto');
const searchMode = ref<'persona' | 'immobile'>('persona');
const selectedImmobileId = ref<number | null>(null);

// STATO DEI DATI
const rawRateList = ref<any[]>([]); // Contiene TUTTI i dati scaricati dal server
const loadingRate = ref(false);

const form = useForm({
    pagante_id: null,
    cassa_id: null,
    gestione_id: null, // Se null = Automatica (tutte), se ID = Filtra per gestione
    data_pagamento: new Date().toISOString().substring(0, 10),
    importo_totale: 0,
    descrizione: '',
    dettaglio_pagamenti: [] as any[],
    eccedenza: 0,
});

// --- SMART FILTER: Rate List Filtrata ---
const rateList = computed(() => {
    // Se non ho selezionato una gestione specifica, mostro tutto
    if (!form.gestione_id) {
        return rawRateList.value;
    }
    // Altrimenti filtro solo le rate di quella gestione
    return rawRateList.value.filter(r => r.gestione_id === form.gestione_id);
});

// --- HELPERS & COMPUTED ---
const isScaduta = (data: string | null) => {
    if (!data) return false;
    return new Date(data) < new Date(new Date().toDateString());
};

const totalAllocato = computed(() =>
    rateList.value.reduce((sum, r) => sum + (parseFloat(r.da_pagare) || 0), 0)
);

const totaleDebito = computed(() => 
    rateList.value.reduce((sum, r) => sum + (parseFloat(r.residuo) || 0), 0)
);

const bilancioFinale = computed(() => {
    const debito = totaleDebito.value;
    const versato = form.importo_totale || 0;
    const differenza = debito - versato;

    if (differenza > 0.01) {
        return { label: 'Residuo:', value: differenza, class: 'text-red-600 bg-red-50 border-red-200' };
    } else if (differenza < -0.01) {
        return { label: 'Credito:', value: Math.abs(differenza), class: 'text-emerald-600 bg-emerald-50 border-emerald-200' };
    } else {
        return { label: 'Saldo:', value: 0, class: 'text-gray-600 bg-gray-50 border-gray-200' };
    }
});

const previewContabile = computed(() => {
    const rateCoinvolte = rateList.value.filter(r => r.selezionata && r.da_pagare > 0);

    return {
        hasData: form.importo_totale > 0,
        totale_versato: form.importo_totale,
        allocato_rate: totalAllocato.value,
        anticipo: form.eccedenza,
        righe: rateCoinvolte.map(r => {
            const residuoDopoPagamento = r.residuo - r.da_pagare;
            return {
                id: r.id,
                descrizione: r.descrizione,
                pagato: r.da_pagare,
                status: residuoDopoPagamento < 0.01 ? 'SALDATA' : 'PARZIALE',
                residuo_futuro: Math.max(0, residuoDopoPagamento)
            };
        })
    };
});

// --- API FETCH ---
const fetchDebiti = async (params: { anagrafica_id?: number | null, immobile_id?: number | null }) => {
    if (!params.anagrafica_id && !params.immobile_id) {
        rawRateList.value = [];
        return;
    }
    loadingRate.value = true;
    try {
        const url = route(generateRoute('gestionale.situazione-debitoria'), {
            condominio: props.condominio.id,
            ...params
        });
        const res = await axios.get(url);
        
        // Salviamo i dati grezzi
        rawRateList.value = res.data.rate.map((r: any) => ({
            ...r,
            da_pagare: 0,
            selezionata: false,
            scaduta: isScaduta(r.data_scadenza ?? null),
        }));

        if (form.importo_totale > 0) runDistribution();
    } finally { loadingRate.value = false; }
};

// --- WATCHERS ---
watch(() => form.pagante_id, (newVal) => {
    if (searchMode.value === 'persona') fetchDebiti({ anagrafica_id: newVal });
});

watch(selectedImmobileId, (newVal) => {
    if (searchMode.value === 'immobile') fetchDebiti({ immobile_id: newVal });
});

watch(() => form.importo_totale, () => {
    runDistribution();
});

// Watcher Filtro Gestione: Resetta selezioni se cambio filtro
watch(() => form.gestione_id, () => {
    // 1. Resetta tutte le selezioni precedenti (per evitare di pagare rate ora nascoste)
    rawRateList.value.forEach(r => {
        r.da_pagare = 0;
        r.selezionata = false;
    });
    // 2. Ricalcola la distribuzione sul nuovo set visibile
    if (form.importo_totale > 0) runDistribution();
});

const toggleSearchMode = (newMode: 'persona' | 'immobile') => {
    searchMode.value = newMode;
    rawRateList.value = []; // Pulisci lista grezza
    selectedImmobileId.value = null;
};

// --- DISTRIBUZIONE (Lavora su rateList, che è già filtrata) ---
const runDistribution = () => {
    mode.value === 'auto' ? distributeGreedy() : calculateExcessOnly();
};

const distributeGreedy = () => {
    let budget = parseFloat(String(form.importo_totale)) || 0;
    
    // Iteriamo solo sulla lista VISIBILE (filtrata)
    rateList.value.forEach(r => {
        const allocabile = Math.min(budget, r.residuo);
        r.da_pagare = parseFloat(allocabile.toFixed(2));
        r.selezionata = r.da_pagare > 0;
        budget -= r.da_pagare;
        if (budget < 0.01) budget = 0;
    });

    form.eccedenza = parseFloat(budget.toFixed(2));
    syncForm();
};

const onManualChange = (rata: any, val: string) => {
    if (mode.value === 'auto') return;
    let amount = parseFloat(val) || 0;
    if (amount > rata.residuo) amount = rata.residuo;
    rata.da_pagare = amount;
    rata.selezionata = amount > 0;
    calculateExcessOnly();
    syncForm();
};

const calculateExcessOnly = () => {
    const tot = parseFloat(String(form.importo_totale)) || 0;
    const alloc = rateList.value.reduce((s, r) => s + (parseFloat(r.da_pagare) || 0), 0);
    form.eccedenza = Math.max(0, parseFloat((tot - alloc).toFixed(2)));
};

const toggleMode = () => {
    mode.value = mode.value === 'auto' ? 'manual' : 'auto';
    if (mode.value === 'auto') distributeGreedy();
};

// --- AZIONI RAPIDE ---
const resetAllocation = () => {
    mode.value = 'manual';
    rateList.value.forEach(r => {
        r.da_pagare = 0;
        r.selezionata = false;
    });
    calculateExcessOnly();
    syncForm();
};

const pagaTutto = () => {
    mode.value = 'manual';
    let somma = 0;
    rateList.value.forEach(r => {
        r.da_pagare = r.residuo;
        r.selezionata = true;
        somma += r.residuo;
    });
    form.importo_totale = parseFloat(somma.toFixed(2));
    calculateExcessOnly();
    syncForm();
};

const pagaScadute = () => {
    mode.value = 'manual';
    let somma = 0;
    rateList.value.forEach(r => {
        if (r.scaduta) {
            r.da_pagare = r.residuo;
            r.selezionata = true;
            somma += r.residuo;
        } else {
            r.da_pagare = 0;
            r.selezionata = false;
        }
    });
    form.importo_totale = parseFloat(somma.toFixed(2));
    calculateExcessOnly();
    syncForm();
};

const syncForm = () => {
    // Sincronizza solo le rate visibili e selezionate
    form.dettaglio_pagamenti = rateList.value
        .filter(r => r.selezionata && r.da_pagare > 0)
        .map(r => ({ rata_id: r.id, importo: r.da_pagare }));
};

const submit = () => {
    form.post(route(generateRoute('gestionale.movimenti-rate.store'), props.condominio.id), {
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
</script>

<template>
    <Head title="Registra Incasso" />

    <GestionaleLayout>
        <div class="space-y-4 max-w-7xl mx-auto px-4 py-4 h-[calc(100vh-100px)] flex flex-col">
            
            <div class="flex items-center justify-between shrink-0">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Nuovo Incasso</h1>
                    <p class="text-sm text-muted-foreground">Registrazione pagamento rate condominiali</p>
                </div>
                <Badge variant="outline" class="font-mono bg-white">{{ new Date().toLocaleDateString() }}</Badge>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 flex-1 min-h-0">
                
                <div class="lg:col-span-4 h-full flex flex-col bg-white rounded-xl border shadow-sm overflow-hidden">
                    <div class="p-5 flex-1 overflow-y-auto space-y-5 custom-scrollbar">
                        
                        <div class="space-y-3">
                            <Label class="text-[11px] uppercase text-gray-500 font-bold tracking-wider">Cerca debiti per</Label>
                            <div class="grid grid-cols-2 gap-1 bg-gray-100 p-1 rounded-lg">
                                <button @click="toggleSearchMode('persona')" class="flex items-center justify-center py-1.5 text-xs font-medium rounded-md transition-all" :class="searchMode === 'persona' ? 'bg-white text-primary shadow-sm font-bold' : 'text-gray-500 hover:text-gray-700'">
                                    <User class="w-3.5 h-3.5 mr-1.5"/> Persona
                                </button>
                                <button @click="toggleSearchMode('immobile')" class="flex items-center justify-center py-1.5 text-xs font-medium rounded-md transition-all" :class="searchMode === 'immobile' ? 'bg-white text-primary shadow-sm font-bold' : 'text-gray-500 hover:text-gray-700'">
                                    <Building class="w-3.5 h-3.5 mr-1.5"/> Immobile
                                </button>
                            </div>

                            <div v-if="searchMode === 'persona'">
                                <v-select :options="condomini" v-model="form.pagante_id" label="label" :reduce="c => c.id" class="style-chooser" placeholder="Seleziona condomino..."/>
                            </div>

                            <div v-else>
                                <v-select :options="immobili" v-model="selectedImmobileId" label="label" :reduce="i => i.id" class="style-chooser mb-3" placeholder="Seleziona unità..."/>
                                <Label class="text-emerald-700 font-bold text-xs">Intestatario Ricevuta</Label>
                                <v-select :options="condomini" v-model="form.pagante_id" label="label" :reduce="c => c.id" class="style-chooser" placeholder="Chi versa i soldi?"/>
                            </div>
                        </div>

                        <hr class="border-gray-100">

                        <div>
                            <Label class="text-[11px] uppercase text-gray-500 font-bold tracking-wider mb-2 block">Importo Versato</Label>
                            <div class="relative group">
                                <div class="absolute left-0 top-0 bottom-0 w-9 flex items-center justify-center bg-gray-50 border-r border-gray-200 rounded-l-md group-focus-within:bg-primary/5 group-focus-within:border-primary/30 transition-colors">
                                    <Euro class="w-4 h-4 text-gray-400 group-focus-within:text-primary"/>
                                </div>
                                <Input
                                    type="number" step="0.01" min="0" v-model="form.importo_totale"
                                    class="pl-11 h-10 text-lg font-bold font-mono shadow-sm focus:ring-2 focus:ring-primary/20 border-gray-200"
                                    placeholder="0.00"
                                />
                            </div>
                        </div>

                        <div class="space-y-3">
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <Label class="text-xs font-medium text-gray-600 mb-1 block">Risorsa</Label>
                                    <select v-model="form.cassa_id" class="w-full border border-gray-200 rounded-md px-2 bg-white h-9 text-xs focus:ring-2 focus:ring-primary/20 focus:border-primary">
                                        <option :value="null">Seleziona...</option>
                                        <option v-for="c in risorse" :key="c.id" :value="c.id">{{ c.nome }}</option>
                                    </select>
                                </div>
                                <div>
                                    <Label class="text-xs font-medium text-gray-600 mb-1 block">Data</Label>
                                    <div class="relative">
                                        <Input type="date" v-model="form.data_pagamento" class="h-9 text-xs pr-2"/>
                                    </div>
                                </div>
                            </div>
                            
                            <div>
                                <Label class="text-xs font-medium text-gray-600 mb-1 block">Causale</Label>
                                <div class="relative">
                                    <FileText class="w-3.5 h-3.5 absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400"/>
                                    <Input placeholder="Es. Bonifico saldo 2024" v-model="form.descrizione" class="pl-8 h-9 text-xs"/>
                                </div>
                            </div>
                            
                            <div>
                                <Label class="text-xs font-medium text-gray-400 mb-1 block">Gestione (Filtro)</Label>
                                <select v-model="form.gestione_id" class="w-full border border-gray-200 rounded-md px-2 text-xs bg-gray-50 text-gray-500 h-9 focus:bg-white transition-colors">
                                    <option :value="null">Tutte (Automatica)</option>
                                    <option v-for="g in gestioni" :key="g.id" :value="g.id">{{ g.nome }}</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="p-5 bg-gray-50 border-t border-gray-200 shrink-0">
                        <div class="flex justify-between items-center text-xs mb-3 px-1">
                            <span class="text-gray-500">Totale Allocato:</span>
                            <span class="font-bold text-gray-800">{{ formatCurrency(totalAllocato) }}</span>
                        </div>

                        <Button 
                            @click="submit" 
                            :disabled="form.processing || form.importo_totale <= 0 || !form.pagante_id"
                            class="w-full h-11 bg-emerald-600 hover:bg-emerald-500 text-white font-bold shadow-md shadow-emerald-600/10 transition-all text-sm"
                        >
                            <CheckCircle2 class="w-4 h-4 mr-2" /> Conferma Incasso
                        </Button>
                    </div>
                </div>

                <div class="lg:col-span-8 h-full flex flex-col gap-4 overflow-hidden">
                    
                    <div class="bg-white rounded-xl border shadow-sm flex flex-col flex-1 min-h-0 overflow-hidden">
                        
                        <div class="p-3 border-b bg-gray-50 flex justify-between items-center shrink-0">
                            <div class="flex items-center gap-3">
                                <h3 class="font-semibold text-gray-900 text-sm">Ripartizione Debito</h3>
                                <Badge v-if="rateList.length" variant="secondary" class="bg-white border text-gray-600 text-[10px]">
                                    {{ rateList.length }} Rate
                                </Badge>
                            </div>
                            
                            <div v-if="rateList.length" class="flex items-center gap-2 text-xs">
                                <span class="text-gray-500">Debito:</span>
                                <span class="font-bold text-gray-900 mr-2">{{ formatCurrency(totaleDebito) }}</span>
                                <ArrowRight class="w-3 h-3 text-gray-300" />
                                <div class="flex items-center gap-1 px-2 py-0.5 rounded border transition-colors shadow-sm" :class="bilancioFinale.class">
                                    <span class="font-medium">{{ bilancioFinale.label }}</span>
                                    <span class="font-bold">{{ formatCurrency(bilancioFinale.value) }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="px-3 py-2 border-b bg-gray-50/50 flex justify-between items-center shrink-0">
                            <div class="flex items-center gap-2">
                                <div class="flex items-center bg-white border rounded-md px-1 py-0.5 h-7 shadow-sm cursor-pointer select-none" @click="toggleMode">
                                    <div class="px-2 py-0.5 rounded text-[10px] font-bold transition-all uppercase" :class="mode === 'auto' ? 'bg-primary text-white shadow-sm' : 'text-gray-400 hover:text-gray-600'">Auto</div>
                                    <div class="px-2 py-0.5 rounded text-[10px] font-bold transition-all uppercase" :class="mode === 'manual' ? 'bg-primary text-white shadow-sm' : 'text-gray-400 hover:text-gray-600'">Manual</div>
                                </div>
                            </div>
                            <div class="flex items-center gap-2" v-if="rateList.length">
                                <Button size="sm" variant="ghost" @click="resetAllocation" class="h-7 text-xs text-gray-500 hover:text-red-600 hover:bg-red-50 px-2"><RotateCcw class="w-3 h-3 mr-1"/> Reset</Button>
                                <div class="h-3 w-px bg-gray-300 mx-1"></div>
                                <Button size="sm" variant="outline" @click="pagaScadute" class="h-7 text-xs bg-white border-gray-200 text-gray-600"><Calculator class="w-3 h-3 mr-1.5"/> Scadute</Button>
                                <Button size="sm" variant="outline" @click="pagaTutto" class="h-7 text-xs bg-white border-gray-200 text-gray-600">Paga tutto</Button>
                            </div>
                        </div>

                        <div class="flex-1 overflow-y-auto custom-scrollbar">
                            <table v-if="rateList.length" class="w-full text-sm border-collapse">
                                <thead class="bg-white sticky top-0 z-10 shadow-sm text-[10px] uppercase text-gray-400 font-bold tracking-wider">
                                    <tr>
                                        <th class="p-3 pl-4 text-left bg-gray-50/95 backdrop-blur border-b border-gray-100">Scadenza</th>
                                        <th class="p-3 text-left bg-gray-50/95 backdrop-blur border-b border-gray-100">Rata / Intestatario</th>
                                        <th class="p-3 text-right bg-gray-50/95 backdrop-blur border-b border-gray-100">Residuo</th>
                                        <th class="p-3 pr-4 text-right bg-gray-50/95 backdrop-blur border-b border-gray-100 w-[130px]">Importo</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-50">
                                    <tr v-for="r in rateList" :key="r.id" class="transition-colors group" :class="r.da_pagare > 0 ? 'bg-emerald-50/20' : 'hover:bg-gray-50'">
                                        <td class="p-3 pl-4 align-top">
                                            <div class="flex flex-col">
                                                <span class="font-mono text-xs font-medium text-gray-600">{{ r.scadenza_human }}</span>
                                                <span v-if="r.scaduta" class="text-[9px] text-red-500 font-bold uppercase mt-1 flex items-center bg-red-50 w-fit px-1 rounded"><AlertCircle class="w-2.5 h-2.5 mr-1"/> Scaduta</span>
                                            </div>
                                        </td>
                                        <td class="p-3 align-top">
                                            <div class="text-xs font-bold text-gray-800 mb-0.5">{{ r.descrizione }}</div>
                                            <div class="text-[11px] text-blue-600 font-medium flex items-center gap-1">
                                                <User class="w-3 h-3 opacity-70"/> 
                                                {{ r.intestatario }}
                                                <span v-if="r.tipologia" class="text-gray-400 font-normal ml-1 border-l border-gray-300 pl-1">
                                                    {{ r.tipologia }}
                                                </span>
                                            </div>
                                            <div class="text-[10px] text-gray-400 mt-0.5">{{ r.gestione }} • {{ r.unita }}</div>
                                        </td>
                                        <td class="p-3 text-right align-top">
                                            <span class="font-mono text-xs font-medium text-gray-500">{{ formatCurrency(r.residuo) }}</span>
                                        </td>
                                        <td class="p-3 pr-4 text-right align-top">
                                            <Input type="number" v-model="r.da_pagare" @input="onManualChange(r, $event.target.value)" :disabled="mode==='auto'" class="text-right font-bold h-8 text-xs font-mono transition-all" :class="r.da_pagare > 0 ? 'border-emerald-500 bg-white ring-1 ring-emerald-500/20 text-emerald-700' : 'bg-transparent border-transparent group-hover:border-gray-200'" placeholder="0.00"/>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <div v-else class="flex flex-col items-center justify-center h-full text-gray-400 space-y-4 py-12 bg-gray-50/30">
                                <div class="p-4 bg-white rounded-full shadow-sm border border-gray-100"><ArrowRightLeft class="w-8 h-8 opacity-20" /></div>
                                <div class="text-center">
                                    <p class="font-medium text-sm text-gray-500">Nessuna rata visualizzata</p>
                                    <p class="text-xs text-gray-400 mt-1">Seleziona un condomino o un immobile per iniziare</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="bg-slate-900 text-white rounded-xl border border-slate-700 shadow-sm flex flex-col h-[200px] shrink-0 overflow-hidden">
                        <div class="p-3 border-b border-slate-700 flex justify-between items-center bg-slate-800/50 shrink-0">
                            <h3 class="font-semibold text-sm flex items-center">
                                <Receipt class="w-4 h-4 mr-2 text-emerald-400"/> Anteprima Registrazione
                            </h3>
                            <div class="flex gap-4 text-xs">
                                <div>
                                    <span class="text-slate-400 mr-2">Allocato:</span>
                                    <span class="font-bold text-emerald-400">{{ formatCurrency(totalAllocato) }}</span>
                                </div>
                                <div v-if="form.eccedenza > 0">
                                    <span class="text-slate-400 mr-2">Eccedenza:</span>
                                    <span class="font-bold text-blue-400">{{ formatCurrency(form.eccedenza) }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="flex-1 overflow-y-auto p-4 custom-scrollbar-dark">
                            <div v-if="previewContabile.hasData" class="space-y-2">
                                <div v-for="riga in previewContabile.righe" :key="riga.id" class="flex justify-between items-start text-xs border-b border-slate-800 pb-2 mb-2 last:border-0">
                                    <div class="flex-1 mr-4">
                                        <div class="text-slate-200 font-medium">{{ riga.descrizione }}</div>
                                        <div v-if="riga.status === 'PARZIALE'" class="mt-0.5 flex items-center text-amber-500 text-[10px] font-bold">
                                            Resta da pagare: {{ formatCurrency(riga.residuo_futuro) }}
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="font-mono font-bold text-white">{{ formatCurrency(riga.pagato) }}</div>
                                        <span v-if="riga.status === 'SALDATA'" class="text-[9px] text-emerald-500 uppercase font-bold tracking-wider">Saldata</span>
                                        <span v-else class="text-[9px] text-amber-500 uppercase font-bold tracking-wider">Parziale</span>
                                    </div>
                                </div>

                                <div v-if="previewContabile.anticipo > 0" class="flex justify-between items-center pt-2 text-xs">
                                    <div class="text-blue-400 font-medium">Anticipo / Eccedenza</div>
                                    <div class="font-mono font-bold text-blue-400">+ {{ formatCurrency(previewContabile.anticipo) }}</div>
                                </div>
                            </div>
                            <div v-else class="flex flex-col items-center justify-center h-full text-slate-600 text-xs">
                                <Receipt class="w-8 h-8 opacity-20 mb-2" />
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
    border-color: #e5e7eb;
    padding: 2px 0;
    border-radius: 6px;
    background-color: white;
    min-height: 36px;
}
:deep(.style-chooser .vs__selected) {
    font-size: 0.8rem;
}
/* Light Scrollbar */
.custom-scrollbar::-webkit-scrollbar { width: 5px; }
.custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
.custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

/* Dark Scrollbar for Preview */
.custom-scrollbar-dark::-webkit-scrollbar { width: 5px; }
.custom-scrollbar-dark::-webkit-scrollbar-track { background: #1e293b; }
.custom-scrollbar-dark::-webkit-scrollbar-thumb { background: #475569; border-radius: 10px; }
.custom-scrollbar-dark::-webkit-scrollbar-thumb:hover { background: #64748b; }
</style>