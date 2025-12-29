<script setup lang="ts">
import { ref, computed } from "vue";
import { Head, Link, router } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import { usePermission } from "@/composables/permissions";
import { useDateConverter } from '@/composables/useDateConverter';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import Heading from '@/components/Heading.vue';
import { 
  Tooltip, 
  TooltipContent, 
  TooltipProvider, 
  TooltipTrigger 
} from '@/components/ui/tooltip';
import { 
  List, 
  CheckCircle2, 
  AlertCircle, 
  Clock, 
  Ban, 
  PieChart, 
  Coins,
  RotateCw,
  Info
} from "lucide-vue-next";
import type { BreadcrumbItem } from '@/types';
import type { Building } from "@/types/buildings";
import type { Esercizio } from "@/types/gestionale/esercizi";

const props = defineProps<{
  condominio: Building;
  esercizio: Esercizio;
  pianoRate: any,
  quotePerAnagrafica: any[],
  quotePerImmobile: any[],
}>()

const { generatePath, generateRoute } = usePermission();
const { toItalian } = useDateConverter();
const { euro } = useCurrencyFormatter();

const tab = ref<"anagrafica" | "immobile">("anagrafica");
const showOnlyCredits = ref(false);

const today = new Date();
today.setHours(0, 0, 0, 0);

// --- RICALCOLO ---
const handleRecalculate = () => {
    if (!confirm('Sei sicuro di voler ricalcolare il piano rate? Le rate attuali verranno cancellate e ricreate.')) {
        return;
    }
    router.post(route(generateRoute('gestionale.esercizi.piani-rate.regenerate'), { 
        condominio: props.condominio.id, 
        esercizio: props.esercizio.id,
        pianoRate: props.pianoRate.id 
    }), {}, { preserveScroll: true });
};

// --- HELPER DI STILE ---
const getRataStyle = (rata: any) => {
  const scaduta = new Date(rata.scadenza) < new Date() && rata.stato === 'da_pagare';
  if (rata.stato === 'annullata') return { container: 'bg-gray-50 border-gray-200 text-gray-400 opacity-60', text: 'line-through decoration-gray-400', icon: Ban, label: 'Annullata' };
  if (rata.importo < 0 || rata.stato === 'credito') return { container: 'bg-blue-50 border-blue-200 text-blue-700', text: 'font-bold', icon: Coins, label: 'Credito' };
  if (rata.stato === 'pagata') return { container: 'bg-emerald-50 border-emerald-200 text-emerald-700', text: 'font-bold', icon: CheckCircle2, label: 'Saldata' };
  if (rata.stato === 'parzialmente_pagata') return { container: 'bg-amber-50 border-amber-300 text-amber-800 ring-1 ring-amber-100/50', text: 'font-bold', icon: PieChart, label: 'Parziale' };
  if (scaduta) return { container: 'bg-white border-red-300 text-red-700 shadow-sm', text: 'font-bold', icon: AlertCircle, label: 'Scaduta' };
  return { container: 'bg-white border-gray-200 text-gray-500 hover:border-gray-300', text: '', icon: Clock, label: 'In attesa' };
};

const getResiduoTooltip = (rata: any) => {
    if(rata.stato === 'pagata') return `Pagato il ${rata.data_pagamento ? toItalian(rata.data_pagamento) : '-'}`;
    if(rata.stato === 'parzialmente_pagata') {
        const pagato = rata.importo_pagato ?? 0; 
        const residuo = (rata.importo ?? 0) - pagato;
        return `Versati: ${euro(pagato)} | Restano: ${euro(residuo)}`;
    }
    return null;
}

const immobileDettagli = (immobile: any) => {
  if (!immobile) return "";
  const interno = immobile.interno ?? "-";
  const piano = immobile.piano ?? "-";
  return `Int. ${interno} • Piano ${piano}`;
};

const isReady = computed(() => props.pianoRate?.numero_rate > 0 && (Array.isArray(props.quotePerAnagrafica) || Array.isArray(props.quotePerImmobile)));

const rateColumns = computed(() => {
  if (!isReady.value) return [];
  const src = tab.value === "anagrafica" ? props.quotePerAnagrafica : props.quotePerImmobile;
  if (!Array.isArray(src)) return [];
  return Array.from({ length: props.pianoRate.numero_rate }, (_, i) => {
    const numero = i + 1;
    const sample = src.find((item: any) => item.rate?.some((r: any) => r.numero === numero));
    const scadenza = sample?.rate?.find((r: any) => r.numero === numero)?.scadenza;
    return { numero, scadenza: scadenza ? new Date(scadenza) : null };
  });
});

const dataWithMap = computed(() => {
  if (!isReady.value) return [];
  const src = tab.value === "anagrafica" ? props.quotePerAnagrafica : props.quotePerImmobile;
  if (!Array.isArray(src)) return [];
  return src.map((item: any) => {
    const rate = item.rate || [];
    const rateMap = Object.fromEntries(rate.map((r: any) => [r.numero, r]));
    let scadute = 0; let versato = 0;
    
    rate.forEach((r: any) => {
      const importo = r.importo ?? 0;
      const scadenzaTime = new Date(r.scadenza).setHours(0, 0, 0, 0);
      const isScaduta = scadenzaTime <= today.getTime();
      
      if (r.stato === "pagata") versato += importo;
      else if (r.stato === "parzialmente_pagata") versato += (r.importo_pagato ?? 0);
      
      if (isScaduta && r.stato !== 'pagata' && r.stato !== 'annullata' && r.stato !== 'credito') {
        if(r.stato === 'parzialmente_pagata') scadute += (importo - (r.importo_pagato ?? 0));
        else scadute += importo;
      }
    });

    const creditiRiga = rate.filter((r: any) => r.importo < 0).reduce((sum: number, r: any) => sum + Math.abs(r.importo), 0);
    const totaleRate = rate.reduce((sum: number, r: any) => sum + (r.importo ?? 0), 0);
    const saldoNetto = totaleRate - versato; 

    return { 
        ...item, 
        rateMap, 
        scaduteRiga: scadute, 
        versatoRiga: versato, 
        creditiRiga, 
        totaleRate, 
        totale: saldoNetto 
    };
  });
});

const currentData = computed(() => {
  const data = dataWithMap.value;
  return showOnlyCredits.value ? data.filter((i: any) => i.creditiRiga > 0) : data;
});

const aggregates = computed(() => {
  if (!isReady.value) return { totaleGenerale: 0, totaliPerRata: [], totaleRateScadute: 0, totaleVersato: 0, creditiTotali: 0, totaleTeorico: 0, daIncassareTotale: 0 };
  const src = tab.value === "anagrafica" ? props.quotePerAnagrafica : props.quotePerImmobile;
  const perRata = Array(props.pianoRate.numero_rate).fill(0);
  let scadute = 0; let versato = 0; let crediti = 0; let totaleTeorico = 0;
  
  (src || []).forEach((item: any) => {
    (item.rate || []).forEach((r: any) => {
      const importo = r.importo ?? 0;
      const scadenzaTime = new Date(r.scadenza).setHours(0, 0, 0, 0);
      const isScaduta = scadenzaTime <= today.getTime();
      perRata[r.numero - 1] += importo;
      totaleTeorico += importo;
      if (r.stato === "pagata") versato += importo;
      else if (r.stato === "parzialmente_pagata") versato += (r.importo_pagato ?? 0);
      if (isScaduta && r.stato !== 'pagata' && r.stato !== 'annullata' && r.stato !== 'credito') {
         if(r.stato === 'parzialmente_pagata') scadute += (importo - (r.importo_pagato ?? 0));
         else scadute += importo;
      }
      if (importo < 0) crediti += Math.abs(importo);
    });
  });
  const daIncassareTotale = totaleTeorico - versato;
  return { totaleGenerale: daIncassareTotale, totaliPerRata: perRata, totaleRateScadute: scadute, totaleVersato: versato, creditiTotali: crediti, totaleTeorico, daIncassareTotale };
});

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, href: '#' },
  { title: 'Piani Rate', href: generatePath('gestionale/:condominio/esercizi/:esercizio/piani-rate', { condominio: props.condominio.id, esercizio: props.esercizio.id }) },
  { title: 'Dettaglio', href: '#' },
]);
</script>

<template>
  <Head title="Dettaglio piano rate" />

  <GestionaleLayout :breadcrumbs="breadcrumbs">
    <div class="px-4 py-6">
      <div class="w-full shadow ring-1 ring-black/5 md:rounded-lg p-4 bg-white">
        <section class="w-full space-y-6">

          <Heading 
            :title="`Piano rate: ${props.pianoRate.nome}`" 
            description="Situazione aggiornata delle rate e dei pagamenti."
          />

          <Tabs v-model="tab" class="space-y-6">

            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 w-full">
              <TabsList class="grid w-full sm:w-[400px] grid-cols-2 bg-muted p-1 rounded-lg">
                  <TabsTrigger value="anagrafica">Per anagrafica</TabsTrigger>
                  <TabsTrigger value="immobile">Per immobile</TabsTrigger>
              </TabsList>

              <div class="flex items-center gap-2 w-full sm:w-auto">
                  <button 
                      @click="handleRecalculate"
                      class="inline-flex items-center justify-center gap-2 rounded-md border border-gray-200 bg-white px-4 py-2 h-9 w-full sm:w-auto text-sm font-medium text-gray-700 hover:bg-gray-50 hover:text-primary transition-colors shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20"
                      title="Ricalcola importi e quote"
                  >
                      <RotateCw class="w-4 h-4" />
                      <span class="hidden sm:inline">Ricalcola</span>
                      <span class="sm:hidden">Ricalcola</span>
                  </button>

                  <Link 
                      :href="route(generateRoute('gestionale.esercizi.piani-rate.index'), { condominio: props.condominio.id, esercizio: props.esercizio.id  })" 
                      class="inline-flex items-center justify-center gap-2 rounded-md bg-primary text-sm font-medium text-white px-4 py-2 h-9 w-full sm:w-auto hover:bg-primary/90 transition-colors shadow-sm whitespace-nowrap"
                  >
                      <List class="w-4 h-4" />
                      <span>Lista</span>
                  </Link>
              </div>
            </div>

            <div v-if="isReady" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3">
                <Card class="bg-white shadow-sm border">
                    <CardHeader class="p-4 pb-2"><CardTitle class="text-xs uppercase text-gray-400 tracking-wider">Totale Piano</CardTitle></CardHeader>
                    <CardContent class="p-4 pt-0 text-xl font-bold text-gray-900">{{ euro(aggregates.totaleTeorico) }}</CardContent>
                </Card>
                
                <Card class="bg-red-50/40 shadow-sm border-red-100 relative group">
                    <CardHeader class="p-4 pb-2 flex flex-row items-center justify-between space-y-0">
                        <CardTitle class="text-xs uppercase text-red-400 tracking-wider">Da Incassare</CardTitle>
                        <TooltipProvider>
                            <Tooltip>
                                <TooltipTrigger>
                                    <Info class="w-3 h-3 text-red-300 hover:text-red-500 transition-colors cursor-help" />
                                </TooltipTrigger>
                                <TooltipContent>
                                    <p class="text-xs">Include rate scadute e <strong>debiti pregressi</strong>.</p>
                                    <p class="text-xs text-muted-foreground">Non sottrae i crediti.</p>
                                </TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                    </CardHeader>
                    <CardContent class="p-4 pt-0 text-xl font-bold text-red-600">{{ euro(aggregates.totaleRateScadute) }}</CardContent>
                </Card>

                <Card class="bg-emerald-50/40 shadow-sm border-emerald-100">
                    <CardHeader class="p-4 pb-2"><CardTitle class="text-xs uppercase text-emerald-400 tracking-wider">Incassato</CardTitle></CardHeader>
                    <CardContent class="p-4 pt-0 text-xl font-bold text-emerald-600">{{ euro(aggregates.totaleVersato) }}</CardContent>
                </Card>
                
                <Card class="bg-blue-50/40 shadow-sm border-blue-100">
                    <CardHeader class="p-4 pb-2"><CardTitle class="text-xs uppercase text-blue-400 tracking-wider">Crediti (Anticipi)</CardTitle></CardHeader>
                    <CardContent class="p-4 pt-0 text-xl font-bold text-blue-600">{{ aggregates.creditiTotali > 0 ? euro(aggregates.creditiTotali) : "—" }}</CardContent>
                </Card>
                
                <Card class="bg-gray-50 shadow-sm border">
                    <CardHeader class="p-4 pb-2"><CardTitle class="text-xs uppercase text-gray-500 tracking-wider">Saldo Netto</CardTitle></CardHeader>
                    <CardContent class="p-4 pt-0 text-xl font-bold" 
                        :class="aggregates.totaleGenerale > 0.01 ? 'text-red-600' : (aggregates.totaleGenerale < -0.01 ? 'text-blue-600' : 'text-emerald-600')">
                        {{ euro(aggregates.totaleGenerale) }}
                    </CardContent>
                </Card>
            </div>

            <div v-if="isReady" class="flex flex-wrap gap-4 text-xs text-gray-500 items-center bg-gray-50 p-3 rounded-lg border border-dashed border-gray-200">
                <span class="font-bold uppercase tracking-wider text-[10px] text-gray-400 mr-2">Legenda:</span>
                
                <div class="flex items-center gap-1.5"><CheckCircle2 class="w-3.5 h-3.5 text-emerald-600" /> <span class="text-emerald-700 font-medium">Saldata</span></div>
                <div class="flex items-center gap-1.5"><PieChart class="w-3.5 h-3.5 text-amber-600" /> <span class="text-amber-700 font-medium">Parziale</span></div>
                <div class="flex items-center gap-1.5"><AlertCircle class="w-3.5 h-3.5 text-red-600" /> <span class="text-red-700 font-medium">Scaduta</span></div>
                <div class="flex items-center gap-1.5"><Clock class="w-3.5 h-3.5 text-gray-400" /> <span>In Scadenza</span></div>
                <div class="flex items-center gap-1.5"><Coins class="w-3.5 h-3.5 text-blue-600" /> <span class="text-blue-700 font-medium">Credito</span></div>
                
                <div class="h-4 w-px bg-gray-300 mx-2 hidden sm:block"></div>
                
                <div class="flex items-center gap-1.5">
                    <div class="w-2.5 h-2.5 rounded-full bg-red-500 shadow-sm ring-1 ring-black/5"></div>
                    <span class="text-red-600 font-medium">Saldo Debito</span>
                </div>
                <div class="flex items-center gap-1.5">
                    <div class="w-2.5 h-2.5 rounded-full bg-blue-500 shadow-sm ring-1 ring-black/5"></div>
                    <span class="text-blue-600 font-medium">Saldo Credito</span>
                </div>
            </div>

            <TabsContent :value="tab" class="mt-0 space-y-6">
              <template v-if="isReady">
                <div class="overflow-x-auto border rounded-lg shadow-sm">
                  <table class="w-full text-sm border-collapse bg-white whitespace-nowrap min-w-[1000px]">
                    <thead class="sticky top-0 bg-white z-20 shadow-sm">
                      <tr class="border-b bg-gray-50/80 text-gray-500">
                        <th class="text-left px-6 py-3 sticky left-0 bg-gray-50 z-30 min-w-[250px] font-semibold uppercase text-xs tracking-wider shadow-[2px_0_5px_-2px_rgba(0,0,0,0.1)]">
                          {{ tab === "anagrafica" ? "Anagrafica" : "Immobile" }}
                        </th>
                        <th v-for="col in rateColumns" :key="col.numero" class="text-center px-4 py-3 min-w-[100px]">
                          <div class="font-semibold text-gray-700">Rata {{ col.numero }}</div>
                          <div class="text-[10px] opacity-75 font-normal">{{ col.scadenza ? toItalian(col.scadenza) : "—" }}</div>
                        </th>
                        <th class="text-right px-4 py-3 bg-red-50/20 text-red-600 border-l border-red-100 min-w-[100px]">Scadute</th>
                        <th class="text-right px-4 py-3 bg-emerald-50/20 text-emerald-600 min-w-[100px]">Versato</th>
                        <th class="text-right px-4 py-3 min-w-[100px]">Crediti</th>
                        <th class="text-right px-4 py-3 min-w-[100px] text-xs">Tot. Rate</th>
                        <th class="text-right px-6 py-3 sticky right-0 bg-gray-50 z-30 text-xs shadow-[-2px_0_5px_-2px_rgba(0,0,0,0.1)]">Saldo</th>
                      </tr>
                    </thead>

                    <tbody class="divide-y divide-gray-100">
                      <tr v-for="item in currentData" 
                          :key="tab === 'anagrafica' ? item.anagrafica.id : item.immobile.id"
                          class="hover:bg-gray-50 transition-colors group"
                      >
                        <td class="px-6 py-4 font-medium sticky left-0 bg-white group-hover:bg-gray-50 z-10 border-r border-gray-100 align-top shadow-[2px_0_5px_-2px_rgba(0,0,0,0.05)]">
                          <div v-if="tab === 'anagrafica'">
                            <!-- <div class="font-semibold text-gray-900">{{ item.anagrafica.nome }}</div> -->
                            <Link 
                              :href="route('admin.gestionale.anagrafiche.estratto-conto', { 
                                  condominio: props.condominio.id, 
                                  anagrafica: item.anagrafica.id 
                              })"
                              class="font-semibold text-primary hover:text-primary/80 hover:underline cursor-pointer transition-colors block"
                              title="Vedi estratto conto e storico pagamenti"
                            >
                              {{ item.anagrafica.nome }}
                            </Link>
                            <div class="text-xs text-muted-foreground mt-0.5">{{ item.anagrafica.indirizzo }}</div>
                          </div>
                          <div v-else>
                            <div class="font-semibold text-gray-900">{{ item.immobile.nome }}</div>
                            <div class="text-xs text-muted-foreground mt-0.5">{{ immobileDettagli(item.immobile) }}</div>
                          </div>
                        </td>

                        <td v-for="col in rateColumns" :key="col.numero" class="px-3 py-2 text-center align-middle">
                          <template v-if="item.rateMap?.[col.numero]">
                            <TooltipProvider>
                                <Tooltip :delayDuration="200">
                                    <TooltipTrigger as-child>
                                        <div 
                                            :class="getRataStyle(item.rateMap[col.numero]).container"
                                            class="relative flex flex-col items-center justify-center rounded-lg border p-1.5 h-[52px] w-[80px] mx-auto transition-all cursor-help"
                                        >
                                            <div class="flex items-center gap-1" :class="getRataStyle(item.rateMap[col.numero]).text">
                                                <component :is="getRataStyle(item.rateMap[col.numero]).icon" v-if="getRataStyle(item.rateMap[col.numero]).icon" class="w-3 h-3 opacity-60" />
                                                <span class="text-xs">{{ euro(item.rateMap[col.numero].importo) }}</span>
                                            </div>
                                            <div v-if="item.rateMap[col.numero].stato === 'parzialmente_pagata'" class="absolute -top-1.5 right-0 bg-amber-100 text-[8px] px-1 rounded-sm text-amber-700 font-bold border border-amber-200 shadow-sm">
                                                PARZ.
                                            </div>
                                            
                                            <div v-if="tab === 'anagrafica' && col.numero === 1 && item.saldo_iniziale" 
                                                class="absolute -top-1 -right-1 rounded-full w-2.5 h-2.5 shadow-sm ring-1 ring-white"
                                                :class="item.saldo_iniziale > 0 ? 'bg-red-500' : 'bg-blue-500'"
                                            ></div>

                                            <div v-if="tab === 'immobile' && col.numero === 1">
                                                <div v-if="item.totale_debiti > 0 && item.totale_crediti === 0"
                                                    class="absolute -top-1 -right-1 rounded-full w-2.5 h-2.5 bg-red-500 shadow-sm ring-1 ring-white"
                                                ></div>
                                                
                                                <div v-if="item.totale_crediti < 0 && item.totale_debiti === 0"
                                                    class="absolute -top-1 -right-1 rounded-full w-2.5 h-2.5 bg-blue-500 shadow-sm ring-1 ring-white"
                                                ></div>

                                                <div v-if="item.totale_debiti > 0 && item.totale_crediti < 0" class="absolute -top-1 -right-1 flex gap-0.5">
                                                    <div class="rounded-full w-2.5 h-2.5 bg-blue-500 shadow-sm ring-1 ring-white"></div>
                                                    <div class="rounded-full w-2.5 h-2.5 bg-red-500 shadow-sm ring-1 ring-white"></div>
                                                </div>
                                            </div>

                                        </div>
                                    </TooltipTrigger>
                                    
                                    <TooltipContent side="top">
                                        <div class="text-xs text-center">
                                            <p class="font-bold mb-1">{{ getRataStyle(item.rateMap[col.numero]).label }}</p>
                                            
                                            <div v-if="tab === 'anagrafica' && col.numero === 1 && item.saldo_iniziale !== 0" class="mb-1 pb-1 border-b border-white/20">
                                                <p>Incluso Saldo Iniziale:</p>
                                                <p :class="item.saldo_iniziale > 0 ? 'text-red-300' : 'text-blue-300'">
                                                    {{ item.saldo_iniziale > 0 ? 'Debito: ' : 'Credito: ' }} 
                                                    {{ euro(Math.abs(item.saldo_iniziale)) }}
                                                </p>
                                            </div>

                                            <div v-if="tab === 'immobile' && col.numero === 1 && (item.totale_debiti > 0 || item.totale_crediti < 0)" class="mb-1 pb-1 border-b border-white/20 text-left">
                                                <p class="font-semibold text-center mb-1">Saldi Pregressi:</p>
                                                
                                                <div v-if="item.totale_debiti > 0" class="text-red-300 whitespace-nowrap flex justify-between gap-2">
                                                    <span>Debiti:</span>
                                                    <span>{{ euro(item.totale_debiti) }}</span>
                                                </div>
                                                
                                                <div v-if="item.totale_crediti < 0" class="text-blue-300 whitespace-nowrap flex justify-between gap-2">
                                                    <span>Crediti:</span>
                                                    <span>{{ euro(Math.abs(item.totale_crediti)) }}</span>
                                                </div>

                                                <div v-if="item.totale_debiti > 0 && item.totale_crediti < 0" class="text-[10px] opacity-80 mt-1 text-center font-medium border-t border-white/10 pt-1">
                                                    <span v-if="(item.totale_debiti + item.totale_crediti) === 0" class="text-emerald-300">
                                                        Compensazione Totale (0€)
                                                    </span>
                                                    <span v-else-if="(item.totale_debiti + item.totale_crediti) > 0" class="text-red-200">
                                                        Residuo Debito: {{ euro(item.totale_debiti + item.totale_crediti) }}
                                                    </span>
                                                    <span v-else class="text-blue-200">
                                                        Residuo Credito: {{ euro(Math.abs(item.totale_debiti + item.totale_crediti)) }}
                                                    </span>
                                                </div>
                                            </div>

                                            <p v-if="getResiduoTooltip(item.rateMap[col.numero])">{{ getResiduoTooltip(item.rateMap[col.numero]) }}</p>
                                            <p class="text-[10px] text-gray-400 mt-1">Scadenza: {{ toItalian(item.rateMap[col.numero].scadenza) }}</p>
                                        </div>
                                    </TooltipContent>
                                </Tooltip>
                            </TooltipProvider>
                          </template>
                          <span v-else class="text-gray-200 text-xs">—</span>
                        </td>

                        <td class="px-4 py-2 text-right text-amber-600 font-medium text-xs bg-red-50/10 border-l border-red-50">{{ euro(item.scaduteRiga) }}</td>
                        <td class="px-4 py-2 text-right text-emerald-600 font-medium text-xs bg-emerald-50/10 border-l border-emerald-50">{{ euro(item.versatoRiga) }}</td>
                        <td class="px-4 py-2 text-right text-blue-600 font-medium text-xs">{{ item.creditiRiga > 0 ? euro(item.creditiRiga) : "—" }}</td>
                        <td class="px-4 py-2 text-right font-medium text-xs text-gray-700">{{ euro(item.totaleRate) }}</td>
                        
                        <td class="px-6 py-4 text-right font-bold sticky right-0 bg-white group-hover:bg-gray-50 z-10 border-l shadow-[-2px_0_5px_-2px_rgba(0,0,0,0.05)]"
                            :class="{
                                'text-red-600': item.totale > 0.01,
                                'text-blue-600': item.totale < -0.01,
                                'text-emerald-600': Math.abs(item.totale) <= 0.01
                            }"
                        >
                          <div class="flex flex-col items-end">
                              <span>{{ euro(Math.abs(item.totale)) }}</span>
                              <span v-if="item.totale > 0.01" class="text-[9px] uppercase opacity-70">Deve</span>
                              <span v-else-if="item.totale < -0.01" class="text-[9px] uppercase opacity-70">Credito</span>
                              <span v-else class="text-[9px] uppercase opacity-70">Ok</span>
                          </div>
                        </td>
                      </tr>
                    </tbody>

                    <tfoot class="sticky bottom-0 bg-white z-30 shadow-[0_-2px_5px_rgba(0,0,0,0.05)]">
                      <tr class="border-t-2 border-muted bg-gray-50 font-bold text-gray-700">
                        <td class="px-6 py-3 sticky left-0 bg-gray-50 z-40 border-r shadow-[2px_0_5px_-2px_rgba(0,0,0,0.05)]">TOTALE</td>
                        <td v-for="col in rateColumns" :key="col.numero" class="text-center px-3 py-3">
                          {{ euro(aggregates.totaliPerRata[col.numero - 1] ?? 0) }}
                        </td>
                        <td class="px-4 py-3 text-right text-amber-600 bg-red-50/20 border-l border-red-100">{{ euro(aggregates.totaleRateScadute) }}</td>
                        <td class="px-4 py-3 text-right text-emerald-600 bg-emerald-50/20 border-l border-emerald-100">{{ euro(aggregates.totaleVersato) }}</td>
                        <td class="px-4 py-3 text-right text-blue-600">{{ euro(aggregates.creditiTotali) }}</td>
                        <td class="px-4 py-3 text-right text-gray-700">{{ euro(aggregates.totaleTeorico) }}</td>
                        
                        <td class="px-6 py-3 text-right sticky right-0 bg-gray-50 z-40 border-l shadow-[-2px_0_5px_-2px_rgba(0,0,0,0.05)]"
                            :class="{
                                'text-red-600': aggregates.totaleGenerale > 0.01,
                                'text-blue-600': aggregates.totaleGenerale < -0.01,
                                'text-emerald-600': Math.abs(aggregates.totaleGenerale) <= 0.01
                            }"
                        >
                          {{ euro(Math.abs(aggregates.totaleGenerale)) }}
                          <div class="text-[9px] font-normal opacity-75">
                              {{ aggregates.totaleGenerale > 0.01 ? 'DEBITO TOT.' : (aggregates.totaleGenerale < -0.01 ? 'CREDITO TOT.' : 'PAREGGIO') }}
                          </div>
                        </td>
                      </tr>
                    </tfoot>
                  </table>
                </div>
              </template>

              <div v-else class="text-center py-12 text-muted-foreground bg-gray-50 rounded-lg border border-dashed">
                <p v-if="!props.pianoRate">Caricamento dati...</p>
                <p v-else>{{ showOnlyCredits ? "Nessun credito da rimborsare." : "Nessuna quota trovata." }}</p>
              </div>
            </TabsContent>
          </Tabs>

        </section>
      </div>
    </div>
  </GestionaleLayout>
</template>

<style scoped>
/* Scrollbar più sottile e moderna */
.overflow-x-auto::-webkit-scrollbar {
    height: 8px;
}
.overflow-x-auto::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 4px;
}
.overflow-x-auto::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 4px;
}
.overflow-x-auto::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

table {
  border-collapse: separate; 
  border-spacing: 0;
}
</style>