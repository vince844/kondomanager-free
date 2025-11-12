<script setup lang="ts">
import { ref, computed } from "vue";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";
import { Card, CardHeader, CardTitle, CardContent } from "@/components/ui/card";
import { Button } from "@/components/ui/button";

const props = defineProps({
  condominio: Object,
  esercizio: Object,
  pianoRate: Object,
  quotePerAnagrafica: Array,
  quotePerImmobile: Array,
});

const tab = ref("anagrafica");

// Euro
const euro = (value: number) =>
  (value / 100).toLocaleString("it-IT", { style: "currency", currency: "EUR" });

// Data compatta
const formatShortDate = (dateStr: string) =>
  new Date(dateStr).toLocaleDateString("it-IT", { day: "numeric", month: "short" }).replace(" ", "\u00A0");

// Colonne rata
const rateColumns = computed(() => Array.from({ length: props.pianoRate.numero_rate }, (_, i) => i + 1));

// Pre-mappa rate
const quotePerAnagraficaWithMap = computed(() =>
  props.quotePerAnagrafica.map((item: any) => ({
    ...item,
    rateMap: Object.fromEntries(item.rate.map((r: any) => [r.numero, r])),
  }))
);

const quotePerImmobileWithMap = computed(() =>
  props.quotePerImmobile.map((item: any) => ({
    ...item,
    rateMap: Object.fromEntries(item.rate.map((r: any) => [r.numero, r])),
  }))
);

const currentData = computed(() =>
  tab.value === "anagrafica" ? quotePerAnagraficaWithMap.value : quotePerImmobileWithMap.value
);

const totaleGenerale = computed(() => {
  const data = tab.value === "anagrafica" ? props.quotePerAnagrafica : props.quotePerImmobile;
  return data.reduce((sum: number, i: any) => sum + i.totale, 0);
});
</script>

<template>
  <div class="flex-1 space-y-4 p-8 pt-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <div>
        <h2 class="text-3xl font-bold tracking-tight">{{ pianoRate.nome }}</h2>
        <p class="text-sm text-muted-foreground">
          {{ pianoRate.gestione }} • {{ new Date(pianoRate.data_inizio).toLocaleDateString() }} • {{ pianoRate.numero_rate }} rate
        </p>
      </div>
      <Button as-child variant="outline" size="sm">
        <Link
          :href="route('admin.gestionale.esercizi.piani-rate.show', [condominio.id, esercizio.id, pianoRate.id])"
          class="flex items-center gap-2"
        >
          <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
          </svg>
          Torna indietro
        </Link>
      </Button>
    </div>

    <!-- Tabs -->
    <Tabs v-model="tab" default-value="anagrafica" class="space-y-4">
      <TabsList>
        <TabsTrigger value="anagrafica">Anagrafica</TabsTrigger>
        <TabsTrigger value="immobile">Immobile</TabsTrigger>
      </TabsList>

      <!-- Tab Content -->
      <TabsContent :value="tab" class="space-y-4">
        <!-- Mini Cards Riepilogo -->
        <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
          <Card>
            <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle class="text-sm font-medium">Totale Rate</CardTitle>
              <svg class="h-4 w-4 text-muted-foreground" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1" />
              </svg>
            </CardHeader>
            <CardContent>
              <div class="text-2xl font-bold">{{ euro(totaleGenerale) }}</div>
              <p class="text-xs text-muted-foreground">Totale da incassare</p>
            </CardContent>
          </Card>

          <Card>
            <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle class="text-sm font-medium">Rate Pagate</CardTitle>
              <svg class="h-4 w-4 text-muted-foreground" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
            </CardHeader>
            <CardContent>
              <div class="text-2xl font-bold">
                {{ currentData.filter(i => i.rate.every(r => r.stato === 'pagata')).length }}
              </div>
              <p class="text-xs text-muted-foreground">Completamente saldate</p>
            </CardContent>
          </Card>

          <Card>
            <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle class="text-sm font-medium">Rate in Corso</CardTitle>
              <svg class="h-4 w-4 text-muted-foreground" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
            </CardHeader>
            <CardContent>
              <div class="text-2xl font-bold">
                {{ currentData.filter(i => i.rate.some(r => r.stato !== 'pagata')).length }}
              </div>
              <p class="text-xs text-muted-foreground">Con rate da pagare</p>
            </CardContent>
          </Card>

          <Card>
            <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle class="text-sm font-medium">Prossima Scadenza</CardTitle>
              <svg class="h-4 w-4 text-muted-foreground" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
              </svg>
            </CardHeader>
            <CardContent>
              <div class="text-2xl font-bold">
                {{ 
                  currentData
                    .flatMap(i => i.rate.filter(r => r.stato !== 'pagata'))
                    .sort((a, b) => new Date(a.scadenza).getTime() - new Date(b.scadenza).getTime())[0]
                    ? new Date(
                        currentData
                          .flatMap(i => i.rate.filter(r => r.stato !== 'pagata'))
                          .sort((a, b) => new Date(a.scadenza).getTime() - new Date(b.scadenza).getTime())[0]
                          .scadenza
                      ).toLocaleDateString('it-IT', { day: 'numeric', month: 'short' })
                    : '—'
                }}
              </div>
              <p class="text-xs text-muted-foreground">Prima rata in sospeso</p>
            </CardContent>
          </Card>
        </div>

        <!-- Tabella Rate -->
        <Card>
          <CardHeader>
            <CardTitle>
              {{ tab === 'anagrafica' ? 'Quote per Anagrafica' : 'Quote per Immobile' }}
            </CardTitle>
          </CardHeader>
          <CardContent class="p-0">
            <div class="overflow-x-auto">
              <table class="w-full text-sm">
                <thead>
                  <tr class="border-b bg-muted/50 text-muted-foreground">
                    <th class="text-left px-6 py-3 font-medium sticky left-0 bg-muted/50 z-10">
                      {{ tab === 'anagrafica' ? 'Anagrafica' : 'Immobile' }}
                    </th>
                    <th v-for="n in rateColumns" :key="n" class="text-center px-3 py-3 font-medium min-w-[110px]">
                      Rata {{ n }}
                    </th>
                    <th class="text-right px-6 py-3 font-medium sticky right-0 bg-muted/50 z-10">Totale</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="item in currentData" :key="tab === 'anagrafica' ? item.anagrafica.id : item.immobile.id" class="border-b hover:bg-muted/30">
                    <td class="px-6 py-4 font-medium sticky left-0 bg-background z-10 border-r">
                      {{ tab === 'anagrafica' ? item.anagrafica.nome : item.immobile.nome }}
                    </td>

                    <!-- Mini Card Rata -->
                    <td v-for="n in rateColumns" :key="n" class="px-3 py-2">
                      <template v-if="item.rateMap?.[n]">
                        <div class="group relative mx-auto w-full max-w-[100px]">
                          <!-- Tooltip -->
                          <div class="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 hidden group-hover:block bg-popover text-popover-foreground border rounded-md shadow-md p-2 text-xs z-50 whitespace-nowrap">
                            <div class="font-medium">Rata {{ n }}</div>
                            <div>Scadenza: {{ new Date(item.rateMap[n].scadenza).toLocaleDateString('it-IT') }}</div>
                            <div class="text-muted-foreground">{{ item.rateMap[n].stato === 'pagata' ? 'Pagata' : 'Da pagare' }}</div>
                          </div>

                          <!-- Card -->
                          <div
                            :class="{
                              'bg-emerald-50 text-emerald-700 border-emerald-200': item.rateMap[n].stato === 'pagata',
                              'bg-amber-50 text-amber-700 border-amber-200': item.rateMap[n].stato !== 'pagata'
                            }"
                            class="flex flex-col items-center justify-center rounded-md border p-2 text-center transition-colors"
                          >
                            <div class="text-xs font-bold">{{ euro(item.rateMap[n].importo) }}</div>
                            <div class="text-[10px] opacity-75 mt-0.5">{{ formatShortDate(item.rateMap[n].scadenza) }}</div>
                            <div class="text-[9px] mt-1 font-medium">
                              {{ item.rateMap[n].stato === 'pagata' ? 'PAGATA' : 'DA PAGARE' }}
                            </div>
                          </div>
                        </div>
                      </template>
                      <span v-else class="block text-center text-muted-foreground/30 text-xs">—</span>
                    </td>

                    <td class="px-6 py-4 text-right font-bold sticky right-0 bg-background z-10 border-l"
                        :class="tab === 'anagrafica' ? 'text-primary' : 'text-emerald-600'">
                      {{ euro(item.totale) }}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </CardContent>
        </Card>

        <!-- Vuoto -->
        <div v-if="!currentData.length" class="text-center py-12 text-muted-foreground">
          <p>Nessuna quota trovata.</p>
        </div>
      </TabsContent>
    </Tabs>
  </div>
</template>