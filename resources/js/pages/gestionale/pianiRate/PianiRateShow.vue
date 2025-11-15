<script setup lang="ts">

import { ref, computed } from "vue";
import { Head } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import { usePermission } from "@/composables/permissions";
import { useDateConverter } from '@/composables/useDateConverter';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";
import { Card, CardHeader, CardTitle, CardContent } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Link } from "@inertiajs/vue3";
import { Filter } from "lucide-vue-next";
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

const { generatePath } = usePermission();
const { toItalian } = useDateConverter();
const { euro } = useCurrencyFormatter();

const tab = ref<"anagrafica" | "immobile">("anagrafica");
const showOnlyCredits = ref(false);

const today = new Date();
today.setHours(0, 0, 0, 0);

// Dettagli immobile
const immobileDettagli = (immobile: any) => {
  if (!immobile) return "";
  const interno = immobile.interno ?? "-";
  const piano = immobile.piano ?? "-";
  const superficie = immobile.superficie ? immobile.superficie + " m²" : "-";
  return `Interno: ${interno} | Piano: ${piano} | Sup: ${superficie}`;
};

// READY
const isReady = computed(
  () =>
    props.pianoRate?.numero_rate > 0 &&
    (Array.isArray(props.quotePerAnagrafica) ||
      Array.isArray(props.quotePerImmobile))
);

// COLONNE RATE + SCADENZE
const rateColumns = computed(() => {
  if (!isReady.value) return [];
  const src = tab.value === "anagrafica" ? props.quotePerAnagrafica : props.quotePerImmobile;
  if (!Array.isArray(src)) return [];

  return Array.from({ length: props.pianoRate.numero_rate }, (_, i) => {
    const numero = i + 1;
    const sample = src.find((item: any) =>
      item.rate?.some((r: any) => r.numero === numero)
    );
    const scadenza = sample?.rate?.find((r: any) => r.numero === numero)?.scadenza;
    return { numero, scadenza: scadenza ? new Date(scadenza) : null };
  });
});

// MAPPATURA RIGHE – allineata al gestionale
const dataWithMap = computed(() => {
  if (!isReady.value) return [];
  const src = tab.value === "anagrafica" ? props.quotePerAnagrafica : props.quotePerImmobile;
  if (!Array.isArray(src)) return [];

  return src.map((item: any) => {
    const rate = item.rate || [];
    const rateMap = Object.fromEntries(rate.map((r: any) => [r.numero, r]));

    let scadute = 0;
    let versato = 0;

    rate.forEach((r: any) => {
      const importo = r.importo ?? 0;
      const scadenzaTime = new Date(r.scadenza).setHours(0, 0, 0, 0);
      const isScaduta = scadenzaTime <= today.getTime();

      // Versato = somma importi pagati (sempre positivi)
      if (r.stato === "pagata") {
        versato += importo;
      }

      // Scadute = somma NETTA di tutte le rate scadute (anche crediti)
      if (isScaduta) {
        scadute += importo;
      }
    });

    // Solo per visualizzare i crediti (valore assoluto)
    const creditiRiga = rate
      .filter((r: any) => r.importo < 0)
      .reduce((sum: number, r: any) => sum + Math.abs(r.importo), 0);

    // Totale rate = somma NETTA di tutte le rate (come nel gestionale)
    const totaleRate = rate.reduce(
      (sum: number, r: any) => sum + (r.importo ?? 0),
      0
    );

    // Da incassare = scadute nette − versato (minimo 0)
    const daIncassareRiga = Math.max(scadute - versato, 0);

    return {
      ...item,
      rateMap,
      scaduteRiga: scadute,
      versatoRiga: versato,
      creditiRiga,
      totaleRate,
      daIncassareRiga,
      totale: daIncassareRiga,
    };
  });
});

// FILTRO SOLO CREDITI
const currentData = computed(() => {
  const data = dataWithMap.value;
  return showOnlyCredits.value
    ? data.filter((i: any) => i.creditiRiga > 0)
    : data;
});

// AGGREGATI TOTALI – stessa logica del gestionale
const aggregates = computed(() => {
  if (!isReady.value) {
    return {
      totaleGenerale: 0,
      totaliPerRata: [] as number[],
      totaleRateScadute: 0,
      totaleVersato: 0,
      creditiTotali: 0,
      totaleTeorico: 0,
      daIncassareTotale: 0,
    };
  }

  const src = tab.value === "anagrafica" ? props.quotePerAnagrafica : props.quotePerImmobile;
  const perRata = Array(props.pianoRate.numero_rate).fill(0);

  let scadute = 0;
  let versato = 0;
  let crediti = 0;
  let totaleTeorico = 0;

  (src || []).forEach((item: any) => {
    (item.rate || []).forEach((r: any) => {
      const importo = r.importo ?? 0;
      const scadenzaTime = new Date(r.scadenza).setHours(0, 0, 0, 0);
      const isScaduta = scadenzaTime <= today.getTime();

      // per rata: somma reale (anche negativa)
      perRata[r.numero - 1] += importo;

      // Totale teorico (= totale netto di tutte le rate dell'anno)
      totaleTeorico += importo;

      // Versato
      if (r.stato === "pagata") {
        versato += importo;
      }

      // Scadute NETTE (anche crediti)
      if (isScaduta) {
        scadute += importo;
      }

      // Crediti solo per visualizzazione
      if (importo < 0) {
        crediti += Math.abs(importo);
      }
    });
  });

  // 🔥 Da incassare totale = scadute nette − versato
  const daIncassareTotale = Math.max(scadute - versato, 0);

  return {
    totaleGenerale: daIncassareTotale,
    totaliPerRata: perRata,
    totaleRateScadute: scadute,
    totaleVersato: versato,
    creditiTotali: crediti,
    totaleTeorico,
    daIncassareTotale,
  };
});

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, href: '#' },
  { title: 'piani rate', href: generatePath('gestionale/:condominio/esercizi/:esercizio/piani-rate', { condominio: props.condominio.id, esercizio: props.esercizio.id }) },
  { title: 'dettaglio rate', href: '#' },
]);
</script>


<template>

  <Head title="Dettaglio piano rate" />

  <GestionaleLayout :breadcrumbs="breadcrumbs">

    <div class="px-4 py-6">
      <div class="w-full shadow ring-1 ring-black/5 md:rounded-lg p-4">
        <section class="w-full">

          <div class="flex-1 space-y-6 pt-6">
            <!-- HEADER -->
            <div class="flex items-center justify-between">
              <div>
                <h2 class="text-3xl font-bold tracking-tight">
                  {{ props.pianoRate?.nome || "Caricamento..." }}
                </h2>
                <p class="text-sm text-muted-foreground">
                  {{ props.pianoRate?.gestione || "" }} •
                  {{
                    props.pianoRate?.data_inizio
                      ? new Date(props.pianoRate.data_inizio).toLocaleDateString("it-IT")
                      : ""
                  }}
                  • {{ props.pianoRate?.numero_rate || 0 }} rate
                </p>
              </div>
              <div class="flex gap-2">
                <Button variant="outline" size="sm" @click="showOnlyCredits = !showOnlyCredits">
                  <Filter class="h-4 w-4 mr-2" />
                  {{ showOnlyCredits ? "Tutte" : "Solo Crediti" }}
                </Button>
                <Button as-child variant="outline" size="sm">
                  <Link
                    :href="route('admin.gestionale.esercizi.piani-rate.show', [condominio.id, esercizio.id, pianoRate.id])"
                    class="flex items-center gap-2"
                  >
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M10 19l-7-7m0 0l7-7m-7 7h18"
                      />
                    </svg>
                    Indietro
                  </Link>
                </Button>
              </div>
            </div>

            <!-- TABS -->
            <Tabs v-model="tab" class="space-y-4">
              <TabsList>
                <TabsTrigger value="anagrafica">Anagrafica</TabsTrigger>
                <TabsTrigger value="immobile">Immobile</TabsTrigger>
              </TabsList>

              <TabsContent :value="tab" class="space-y-6">
                <!-- RIEPILOGO -->
                <div v-if="isReady" class="grid gap-4 md:grid-cols-2 lg:grid-cols-5">
                  <Card>
                    <CardHeader class="pb-2">
                      <CardTitle class="text-sm font-medium">Totale Rate</CardTitle>
                    </CardHeader>
                    <CardContent>
                      <div class="text-2xl font-bold">
                        {{ euro(aggregates.totaleTeorico) }}
                      </div>
                      <p class="text-xs text-muted-foreground">
                        Somma teorica delle rate (solo importi positivi)
                      </p>
                    </CardContent>
                  </Card>

                  <Card>
                    <CardHeader class="pb-2">
                      <CardTitle class="text-sm font-medium">Rate Scadute</CardTitle>
                    </CardHeader>
                    <CardContent>
                      <div class="text-2xl font-bold text-amber-600">
                        {{ euro(aggregates.totaleRateScadute) }}
                      </div>
                      <p class="text-xs text-muted-foreground">
                        Non ancora pagate e già scadute
                      </p>
                    </CardContent>
                  </Card>

                  <Card>
                    <CardHeader class="pb-2">
                      <CardTitle class="text-sm font-medium">Versato</CardTitle>
                    </CardHeader>
                    <CardContent>
                      <div class="text-2xl font-bold text-emerald-600">
                        {{ euro(aggregates.totaleVersato) }}
                      </div>
                      <p class="text-xs text-muted-foreground">Importi incassati</p>
                    </CardContent>
                  </Card>

                  <Card>
                    <CardHeader class="pb-2">
                      <CardTitle class="text-sm font-medium">Crediti</CardTitle>
                    </CardHeader>
                    <CardContent>
                      <div class="text-2xl font-bold text-red-600">
                        {{ aggregates.creditiTotali > 0 ? euro(aggregates.creditiTotali) : "—" }}
                      </div>
                      <p class="text-xs text-muted-foreground">Da rimborsare</p>
                    </CardContent>
                  </Card>

                  <Card>
                    <CardHeader class="pb-2">
                      <CardTitle class="text-sm font-medium">Totale Netto</CardTitle>
                    </CardHeader>
                    <CardContent>
                      <div class="text-2xl font-bold text-emerald-700">
                        {{ euro(aggregates.totaleGenerale) }}
                      </div>
                      <p class="text-xs text-muted-foreground">
                        Scadute − Versato − Crediti (min 0)
                      </p>
                    </CardContent>
                  </Card>
                </div>

                <!-- TABELLA -->
                <Card v-if="isReady">
                  <CardHeader>
                    <CardTitle>
                      {{
                        tab === "anagrafica"
                          ? "Dettaglio rate per anagrafica"
                          : "Dettaglio rate per immobile"
                      }}
                    </CardTitle>
                  </CardHeader>
                  <CardContent class="p-0">
                    <div class="overflow-auto max-h-[70vh]">
                      <table class="w-full text-sm border-collapse">
                        <!-- HEADER STICKY -->
                        <thead class="sticky top-0 bg-background z-30 shadow-sm">
                          <tr class="border-b bg-muted/50 text-muted-foreground">
                            <th
                              class="text-left px-6 py-3 sticky left-0 bg-background z-40 min-w-[250px]"
                            >
                              {{ tab === "anagrafica" ? "Anagrafica" : "Immobile" }}
                            </th>
                            <th
                              v-for="col in rateColumns"
                              :key="col.numero"
                              class="text-center px-3 py-3"
                            >
                              <div>Rata {{ col.numero }}</div>
                              <div class="text-xs opacity-75">
                                {{
                                  col.scadenza
                                    ? toItalian(col.scadenza)
                                    : "—"
                                }}
                              </div>
                            </th>
                            <th class="text-right px-4 py-3">Scadute</th>
                            <th class="text-right px-4 py-3">Versato</th>
                            <th class="text-right px-4 py-3">Crediti</th>
                            <th class="text-right px-4 py-3 text-xs">
                              Tot. Rate
                            </th>
                            <th
                              class="text-right px-6 py-3 sticky right-0 bg-background z-40 text-xs"
                            >
                              Tot. Netto
                            </th>
                          </tr>
                        </thead>

                        <!-- BODY -->
                        <tbody>
                          <tr
                            v-for="item in currentData"
                            :key="
                              tab === 'anagrafica'
                                ? item.anagrafica.id
                                : item.immobile.id
                            "
                            class="border-b hover:bg-muted/30"
                          >
                            <td
                              class="px-6 py-4 font-medium sticky left-0 bg-background z-10 border-r align-top min-w-[250px]"
                            >
                              <div v-if="tab === 'anagrafica'">
                                {{ item.anagrafica.nome }}
                              </div>
                              <div v-else>
                                <div class="font-semibold">
                                  {{ item.immobile.nome }}
                                </div>
                                <div class="text-xs text-muted-foreground mt-0.5">
                                  {{ immobileDettagli(item.immobile) }}
                                </div>
                              </div>
                              <Badge
                                v-if="item.creditiRiga > 0"
                                variant="destructive"
                                class="ml-0 mt-1 text-[10px]"
                              >
                                CREDITO
                              </Badge>
                            </td>

                            <td
                              v-for="col in rateColumns"
                              :key="col.numero"
                              class="px-3 py-2 text-center"
                            >
                              <template v-if="item.rateMap?.[col.numero]">
                                <div
                                  :class="{
                                    'bg-emerald-50 text-emerald-700 border-emerald-200':
                                      item.rateMap[col.numero].stato === 'pagata' &&
                                      item.rateMap[col.numero].importo > 0,
                                    'bg-amber-50 text-amber-700 border-amber-200':
                                      item.rateMap[col.numero].stato !== 'pagata' &&
                                      item.rateMap[col.numero].importo > 0,
                                    'bg-red-50 text-red-700 border-red-200':
                                      item.rateMap[col.numero].importo < 0
                                  }"
                                  class="flex flex-col items-center justify-center rounded-md border p-2"
                                >
                                  <div
                                    class="text-xs font-bold"
                                    :class="
                                      item.rateMap[col.numero].importo < 0
                                        ? 'text-red-600'
                                        : ''
                                    "
                                  >
                                    {{ euro(item.rateMap[col.numero].importo) }}
                                  </div>
                                  <div class="text-[10px] opacity-75 mt-0.5">
                                    {{ toItalian(item.rateMap[col.numero].scadenza) }}
                                  </div>
                                </div>
                              </template>
                              <span v-else class="text-muted-foreground/30 text-xs"
                                >—</span
                              >
                            </td>

                            <td
                              class="px-4 py-2 text-right text-amber-600 font-medium text-xs"
                            >
                              {{ euro(item.scaduteRiga) }}
                            </td>
                            <td
                              class="px-4 py-2 text-right text-emerald-600 font-medium text-xs"
                            >
                              {{ euro(item.versatoRiga) }}
                            </td>
                            <td
                              class="px-4 py-2 text-right text-red-600 font-medium text-xs"
                            >
                              {{ item.creditiRiga > 0 ? euro(item.creditiRiga) : "—" }}
                            </td>
                            <td
                              class="px-4 py-2 text-right font-medium text-xs text-gray-700"
                            >
                              {{ euro(item.totaleRate) }}
                            </td>
                            <td
                              class="px-6 py-4 text-right font-bold sticky right-0 bg-background z-10 border-l text-emerald-700"
                            >
                              {{ euro(item.totale) }}
                            </td>
                          </tr>
                        </tbody>

                        <!-- FOOTER STICKY -->
                        <tfoot class="sticky bottom-0 bg-background z-30 shadow-sm">
                          <tr
                            class="border-t-2 border-muted bg-muted/40 font-bold"
                          >
                            <td
                              class="px-6 py-3 sticky left-0 bg-background z-40"
                            >
                              TOTALE
                            </td>
                            <td
                              v-for="col in rateColumns"
                              :key="col.numero"
                              class="text-center px-3 py-3"
                            >
                              {{ euro(aggregates.totaliPerRata[col.numero - 1] ?? 0) }}
                            </td>
                            <td class="px-4 py-3 text-right text-amber-600">
                              {{ euro(aggregates.totaleRateScadute) }}
                            </td>
                            <td class="px-4 py-3 text-right text-emerald-600">
                              {{ euro(aggregates.totaleVersato) }}
                            </td>
                            <td class="px-4 py-3 text-right text-red-600">
                              {{ euro(aggregates.creditiTotali) }}
                            </td>
                            <td class="px-4 py-3 text-right text-gray-700">
                              {{ euro(aggregates.totaleTeorico) }}
                            </td>
                            <td
                              class="px-6 py-3 text-right sticky right-0 bg-background z-40 text-emerald-700"
                            >
                              {{ euro(aggregates.totaleGenerale) }}
                              <div class="text-xs opacity-75">Tot. netto</div>
                            </td>
                          </tr>
                          <tr class="bg-muted/30 text-xs text-muted-foreground">
                            <td colspan="100%" class="px-6 py-2 text-center italic">
                              Il <strong>Totale netto</strong> è calcolato come:
                              <em>Scadute − Versato − Crediti</em> (valore minimo 0
                              per ogni riga)
                            </td>
                          </tr>
                        </tfoot>
                      </table>
                    </div>
                  </CardContent>
                </Card>

                <!-- STATO VUOTO / CARICAMENTO -->
                <div v-else class="text-center py-12 text-muted-foreground">
                  <p v-if="!props.pianoRate">Caricamento dati...</p>
                  <p v-else>
                    {{ showOnlyCredits ? "Nessun credito da rimborsare." : "Nessuna quota trovata." }}
                  </p>
                </div>
              </TabsContent>
            </Tabs>
          </div>

        </section>
      </div>
    </div>

  </GestionaleLayout>
</template>

<style scoped>
thead,
tfoot {
  background-color: hsl(var(--background));
}

table {
  border-collapse: separate;
  border-spacing: 0;
}
</style>
