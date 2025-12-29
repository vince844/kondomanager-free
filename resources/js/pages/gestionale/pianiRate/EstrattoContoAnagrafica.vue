<script setup lang="ts">
import { computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import { usePermission } from "@/composables/permissions";
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { 
  ArrowLeft, Printer, Mail, Wallet, 
  ArrowDownCircle, ArrowUpCircle, Building2, Landmark,
  FileText, Banknote, HelpCircle, // Icone Azioni
  CheckCircle2, AlertCircle, PieChart, Coins // Icone Stati
} from 'lucide-vue-next';
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
  };
}>();

const { generatePath } = usePermission();

const goBack = () => {
    if (window.history.length > 1) window.history.back();
    else router.visit(generatePath('gestionale/:condominio/piani-rate', { condominio: props.condominio.id }));
};

const formatMoney = (cents: number, allowZero: boolean = false) => {
    if (cents === 0 && !allowZero) return '-';
    return new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(cents / 100);
};

// NUOVA FUNZIONE PER FORMATTARE LA DATA (GG/MM)
const formatDate = (dateString: string) => {
    if (!dateString) return '-';
    // Aggiunto year: 'numeric' per ottenere gg/mm/aaaa
    return new Date(dateString).toLocaleDateString('it-IT', { 
        day: '2-digit', 
        month: '2-digit', 
        year: 'numeric' 
    });
};

const formatIndirizzoImmobile = (immobile: any) => {
    let base = `Int. ${immobile.interno}`; 
    if (immobile.piano) base += ` - P. ${immobile.piano}`;
    return base;
};

const breadcrumbs = computed(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: 'Piani Rate', href: '#' },
  { title: `EC: ${props.anagrafica.nome}`, href: '#' },
]);

// Helper Colori Saldi
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

// Helper Configurazione Stati (Icona + Colori)
const getStatoConfig = (stato: string) => {
    switch(stato) {
        case 'pagata': 
            return { label: 'SALDATA', class: 'bg-emerald-50 text-emerald-700 border-emerald-200', icon: CheckCircle2 };
        case 'parzialmente_pagata': 
            return { label: 'PARZIALE', class: 'bg-amber-50 text-amber-700 border-amber-200', icon: PieChart };
        case 'da_pagare': 
            return { label: 'NON PAGATA', class: 'bg-red-50 text-red-700 border-red-200', icon: AlertCircle };
        case 'credito': 
            return { label: 'CREDITO', class: 'bg-blue-50 text-blue-700 border-blue-200', icon: Coins };
        default: 
            return { label: '', class: '', icon: null };
    }
};
</script>

<template>
    <Head :title="`EC - ${anagrafica.nome}`" />

    <GestionaleLayout :breadcrumbs="breadcrumbs">
        
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-6">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-gray-900 flex items-center gap-2">
                    {{ anagrafica.nome }}
                    <Badge variant="outline" class="font-mono font-normal text-xs">{{ anagrafica.codice_fiscale }}</Badge>
                </h1>
                <p class="text-sm text-gray-500 mt-1 flex items-center gap-2">
                    <Mail class="w-3 h-3" /> {{ anagrafica.email || 'Nessuna email' }}
                    <span class="text-gray-300">|</span> Esercizio: {{ esercizio.nome }}
                </p>
            </div>
            <div class="flex gap-2">
                <Button variant="outline" size="sm" @click="goBack"><ArrowLeft class="w-4 h-4 mr-2" /> Indietro</Button>
                <Button variant="outline" size="sm"><Printer class="w-4 h-4 mr-2" /> Stampa PDF</Button>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 mb-8">
            <div class="lg:col-span-8 grid grid-cols-1 sm:grid-cols-2 gap-4">
                
                <Card class="bg-gray-50/50 shadow-sm border-gray-200">
                    <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-1 p-4">
                        <CardTitle class="text-xs font-medium uppercase text-muted-foreground tracking-wider">Saldo iniziale</CardTitle>
                        <Landmark class="h-4 w-4 text-gray-400" />
                    </CardHeader>
                    <CardContent class="p-4 pt-0">
                        <div class="text-xl font-bold" :class="saldoInizialeColorClass">{{ stats.saldo_iniziale }}</div>
                        <p class="text-[10px] uppercase font-bold mt-1 tracking-wide" :class="saldoInizialeColorClass">
                            {{ stats.saldo_iniziale_raw > 0 ? 'A DEBITO' : (stats.saldo_iniziale_raw < 0 ? 'A CREDITO' : 'PAREGGIO') }}
                        </p>
                    </CardContent>
                </Card>

                <Card class="shadow-sm border-gray-200">
                    <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-1 p-4">
                        <CardTitle class="text-xs font-medium uppercase text-muted-foreground tracking-wider">Totale addebiti</CardTitle>
                        <ArrowDownCircle class="h-4 w-4 text-red-500" />
                    </CardHeader>
                    <CardContent class="p-4 pt-0">
                        <div class="text-xl font-bold text-gray-900">{{ stats.totale_addebiti }}</div>
                        <p class="text-[10px] text-muted-foreground mt-1">Rate emesse</p>
                    </CardContent>
                </Card>

                <Card class="shadow-sm border-gray-200">
                    <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-1 p-4">
                        <CardTitle class="text-xs font-medium uppercase text-muted-foreground tracking-wider">Totale Versato</CardTitle>
                        <ArrowUpCircle class="h-4 w-4 text-emerald-500" />
                    </CardHeader>
                    <CardContent class="p-4 pt-0">
                        <div class="text-xl font-bold text-emerald-600">{{ stats.totale_versamenti }}</div>
                        <p class="text-[10px] text-muted-foreground mt-1">Incassi registrati</p>
                    </CardContent>
                </Card>

                <Card class="shadow-sm" :class="{'bg-red-50 border-red-200': stats.saldo_raw > 0, 'bg-emerald-50 border-emerald-200': stats.saldo_raw < 0, 'bg-white border-gray-200': stats.saldo_raw === 0}">
                    <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-1 p-4">
                        <CardTitle class="text-xs font-medium uppercase tracking-wider" :class="stats.saldo_raw > 0 ? 'text-red-500' : 'text-emerald-500'">Saldo Finale</CardTitle>
                        <Wallet class="h-4 w-4" :class="stats.saldo_raw > 0 ? 'text-red-500' : 'text-emerald-500'" />
                    </CardHeader>
                    <CardContent class="p-4 pt-0">
                        <div class="text-2xl font-bold" :class="saldoColorClass">{{ stats.saldo_finale }}</div>
                        <p class="text-[10px] uppercase font-bold mt-1 tracking-wide" :class="stats.saldo_raw > 0 ? 'text-red-600' : 'text-emerald-600'">
                            {{ stats.saldo_raw > 0 ? 'DA VERSARE' : (stats.saldo_raw < 0 ? 'A CREDITO' : 'PAREGGIO') }}
                        </p>
                    </CardContent>
                </Card>
            </div>

            <div class="lg:col-span-4 flex flex-col h-full">
                <Card class="h-full flex flex-col shadow-sm border-gray-200">
                    <CardHeader class="p-4 pb-2 border-b bg-gray-50/50">
                        <CardTitle class="text-sm font-semibold flex items-center gap-2">
                            <Building2 class="w-4 h-4 text-gray-500" /> Unità immobiliari
                            <Badge variant="secondary" class="ml-auto text-[10px] h-5">{{ anagrafica.immobili.length }}</Badge>
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="p-0 flex-1 overflow-hidden">
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
                    </CardContent>
                </Card>
            </div>
        </div>

        <div class="flex flex-wrap gap-4 text-xs text-gray-500 items-center bg-gray-50/80 p-3 rounded-lg border border-dashed border-gray-200">
            <span class="font-bold uppercase tracking-wider text-[10px] text-gray-400 mr-1">Legenda:</span>
            
            <div class="flex items-center gap-1.5" title="Riga Emissione">
                <div class="w-5 h-5 rounded-full bg-white border border-gray-200 flex items-center justify-center text-gray-500 shadow-sm">
                    <FileText class="w-3 h-3" />
                </div>
                <span>Emissione</span>
            </div>
            
            <div class="flex items-center gap-1.5" title="Riga Incasso">
                <div class="w-5 h-5 rounded-full bg-emerald-100 border border-emerald-200 flex items-center justify-center text-emerald-600 shadow-sm">
                    <Banknote class="w-3 h-3" />
                </div>
                <span>Incasso</span>
            </div>

            <div class="h-4 w-px bg-gray-300 mx-2 hidden sm:block"></div>

            <div class="flex items-center gap-1.5">
                <CheckCircle2 class="w-3.5 h-3.5 text-emerald-600" /> <span class="text-emerald-700 font-medium">Saldata</span>
            </div>
            <div class="flex items-center gap-1.5">
                <PieChart class="w-3.5 h-3.5 text-amber-600" /> <span class="text-amber-700 font-medium">Parziale</span>
            </div>
            <div class="flex items-center gap-1.5">
                <AlertCircle class="w-3.5 h-3.5 text-red-600" /> <span class="text-red-700 font-medium">Non Pagata</span>
            </div>
            <div class="flex items-center gap-1.5">
                <Coins class="w-3.5 h-3.5 text-blue-600" /> <span class="text-blue-700 font-medium">Credito</span>
            </div>
        </div>

        <Card class="shadow-sm border-gray-200">
            <CardHeader class="p-4 pb-3 border-b bg-gray-50/30">
                <div class="flex items-center justify-between">
                    <CardTitle class="text-base font-semibold">Movimenti contabili</CardTitle>
                    <div class="text-xs text-muted-foreground">{{ esercizio.nome }}</div>
                </div>
            </CardHeader>
            <CardContent class="p-0">
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
                                    <div class="w-8 h-8 rounded-full bg-yellow-100 flex items-center justify-center mx-auto text-yellow-600 shadow-sm border border-yellow-200">
                                        <Landmark class="w-4 h-4" />
                                    </div>
                                </td>
                                
                                <td class="px-4 py-4 text-sm font-medium text-gray-700">
                                    {{ formatDate(esercizio.data_inizio) }}
                                </td>

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
                                        
                                        <div v-if="riga.tipo_icona === 'bill'" class="w-8 h-8 rounded-full bg-white flex items-center justify-center text-gray-500 border border-gray-200 shadow-sm">
                                            <FileText class="w-4 h-4" />
                                        </div>
                                        
                                        <div v-else-if="riga.tipo_icona === 'payment'" class="w-8 h-8 rounded-full bg-emerald-100 flex items-center justify-center text-emerald-600 border border-emerald-200 shadow-sm">
                                            <Banknote class="w-4 h-4" />
                                        </div>
                                        
                                        <div v-else-if="riga.tipo_icona === 'landmark'" class="w-8 h-8 rounded-full bg-yellow-50 flex items-center justify-center text-yellow-600 border border-yellow-200 shadow-sm">
                                            <Landmark class="w-4 h-4" />
                                        </div>
                                        
                                        <div v-else class="w-8 h-8 rounded-full bg-blue-50 flex items-center justify-center text-blue-500 border border-blue-100 shadow-sm">
                                            <HelpCircle class="w-4 h-4" />
                                        </div>
                                    </div>
                                </td>

                                <td class="px-4 py-3 align-top">
                                    <div class="text-sm font-medium text-gray-700">{{ riga.data }}</div>
                                    <div v-if="riga.protocollo" class="mt-1">
                                        <Badge variant="outline" class="text-[9px] px-1 h-4 font-mono text-gray-400 border-gray-200">{{ riga.protocollo }}</Badge>
                                    </div>
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
                                                <span class="w-1 h-1 rounded-full bg-gray-300 shrink-0"></span> 
                                                {{ item.text }}
                                            </span>

                                            <span v-if="item.status" 
                                                  class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded border text-[9px] font-bold uppercase tracking-wider"
                                                  :class="getStatoConfig(item.status).class">
                                                
                                                <component :is="getStatoConfig(item.status).icon" class="w-3 h-3" />
                                                {{ getStatoConfig(item.status).label }}
                                            </span>

                                        </div>
                                    </div>
                                </td>

                                <td class="px-4 py-3 text-right align-top">
                                    <span v-if="riga.dare > 0" class="text-red-600 font-medium">{{ formatMoney(riga.dare) }}</span>
                                    <span v-else class="text-gray-200 text-xs">-</span>
                                </td>

                                <td class="px-4 py-3 text-right align-top">
                                    <span v-if="riga.avere > 0" class="text-emerald-600 font-bold font-mono text-sm bg-emerald-50 px-2 py-0.5 rounded-md border border-emerald-100">{{ formatMoney(riga.avere) }}</span>
                                    <span v-else class="text-gray-200 text-xs">-</span>
                                </td>

                                <td class="px-4 py-3 text-right align-top bg-gray-50/30 group-hover:bg-gray-100/50 border-l border-gray-100">
                                    <span class="font-mono font-bold text-sm" :class="riga.saldo > 0 ? 'text-red-600' : (riga.saldo < 0 ? 'text-emerald-600' : 'text-gray-400')">
                                        {{ formatMoney(riga.saldo, true) }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </CardContent>
        </Card>

    </GestionaleLayout>
</template>

<style scoped>
/* Scrollbar */
.overflow-y-auto::-webkit-scrollbar { width: 4px; }
.overflow-y-auto::-webkit-scrollbar-track { background: transparent; }
.overflow-y-auto::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 4px; }
.overflow-y-auto::-webkit-scrollbar-thumb:hover { background: #cbd5e1; }
</style>