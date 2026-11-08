<script setup lang="ts">

import { ref, computed, watch } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { usePage, Link } from '@inertiajs/vue3';
import { useTabellaServer } from '@/composables/useTabellaServer';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Search, Plus, X, FileText } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { usePermission } from "@/composables/permissions";
import type { Table } from '@tanstack/vue-table';
import type { Building } from '@/types/buildings';

defineProps<{ table: Table<any> }>();
const page = usePage<{
  condominio: Building;
  stati: string[];
  filters: { search?: string; stato?: string; data_da?: string; data_a?: string };
  ricevute_mancanti?: { numero: number; dal: string };
}>();
const { generateRoute } = usePermission();
const condominioId = computed(() => page.props.condominio.id);

const globalFilter = ref(page.props.filters?.search || '')
const stato = ref(page.props.filters?.stato || '')
const dataDa = ref(page.props.filters?.data_da || '')
const dataA = ref(page.props.filters?.data_a || '')

const { filtra } = useTabellaServer(() =>
  route(generateRoute('gestionale.movimenti-rate.index'), { condominio: condominioId.value }),
)

const applyFilters = () => {
  filtra({
    search: globalFilter.value || null,
    stato: stato.value || null,
    data_da: dataDa.value || null,
    data_a: dataA.value || null,
  })
}

watchDebounced(globalFilter, applyFilters, { debounce: 300 })
watch([stato, dataDa, dataA], applyFilters)

// 'annullata' è il valore reale in DB (StornoIncassoRateAction), ma per
// l'amministratore "Stornata" è il termine corretto: è lui che ha cliccato
// "Storna" per arrivarci.
const statoLabels: Record<string, string> = { annullata: 'Stornata', fuori_ordine: 'Fuori ordine, non dichiarata' }
// Maiuscola solo all'inizio: «Fuori ordine, non dichiarata», non «Fuori Ordine, Non Dichiarata».
const statoLabel = (s: string) => statoLabels[s] ?? (s.charAt(0).toUpperCase() + s.slice(1))

const isFiltered = computed(() => !!(globalFilter.value || stato.value || dataDa.value || dataA.value))
const resetFilter = () => {
  globalFilter.value = ''
  stato.value = ''
  dataDa.value = ''
  dataA.value = ''
}
</script>

<template>
  <div class="flex flex-wrap items-center justify-between gap-2 w-full">

    <div class="flex flex-wrap items-center gap-2">

      <!-- Ricerca libera -->
      <div class="relative">
        <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-slate-400">
          <Search class="h-4 w-4" />
        </div>
        <Input
          placeholder="Filtra per nome..."
          v-model="globalFilter"
          class="pl-9 h-8 w-[200px] lg:w-[250px]"
        />
      </div>

      <!-- Stato -->
      <Select v-model="stato">
        <SelectTrigger class="h-8 w-[140px] text-xs style-chooser">
          <SelectValue placeholder="Stato" />
        </SelectTrigger>
        <SelectContent position="popper" :style="{ minWidth: 'var(--reka-select-trigger-width)' }">
          <SelectItem v-for="s in page.props.stati" :key="s" :value="s" class="whitespace-nowrap">
            {{ statoLabel(s) }}
          </SelectItem>
        </SelectContent>
      </Select>

      <!-- Intervallo date -->
      <Input type="date" v-model="dataDa" class="h-8 w-[140px] text-xs" title="Data registrazione da" />
      <Input type="date" v-model="dataA" class="h-8 w-[140px] text-xs" title="Data registrazione a" />

      <!-- Reset -->
      <Button
        v-if="isFiltered"
        variant="ghost"
        @click="resetFilter"
        class="h-8 px-2 lg:px-3 text-slate-500 hover:text-slate-700"
      >
        <X class="h-4 w-4 mr-1 lg:mr-2" />
        <span class="hidden lg:inline">Azzera filtri</span>
        <span class="inline lg:hidden">Azzera</span>
      </Button>

    </div>

    <div class="flex flex-wrap items-center gap-2">
    <!-- Le ricevute (1.12.0-beta.2): la pagina si apre sulle ricevute mai emesse dei versamenti recenti. -->
    <Button as-child variant="outline">
        <Link
          :href="route(generateRoute('gestionale.ricevute.index'), { condominio: condominioId })"
          class="inline-flex items-center gap-2 px-3 py-2 h-auto text-xs font-medium"
        >
          <FileText class="w-3.5 h-3.5" />
          Ricevute
          <!-- Contate come la pagina che si apre: i versamenti dal primo del mese scorso, e lo dice. -->
          <span v-if="(page.props.ricevute_mancanti?.numero ?? 0) > 0" class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-800">
            {{ page.props.ricevute_mancanti!.numero }} senza ricevuta dal {{ page.props.ricevute_mancanti!.dal.split('-').reverse().join('/') }}
          </span>
        </Link>
    </Button>

    <!-- L'incasso da terzi: l'affitto dell'antenna, gli interessi, un rimborso. Dopo la registrazione si torna qui. -->
    <Button as-child variant="outline">
        <Link
          :href="route(generateRoute('gestionale.incassi-da-terzi.create'), { condominio: condominioId, da: 'incassi' })"
          class="inline-flex items-center gap-2 px-3 py-2 h-auto text-xs font-medium"
          data-azione="altro-incasso"
        >
          <Plus class="w-3.5 h-3.5 text-emerald-600" />
          Altro incasso
        </Link>
    </Button>

    <Button as-child>
        <Link
          :href="route(generateRoute('gestionale.movimenti-rate.create'), { condominio: condominioId })"
          class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-slate-900 dark:bg-slate-700 border border-slate-800 shadow-sm text-xs font-medium text-white hover:bg-slate-800 dark:hover:bg-slate-600 transition-colors"
        >
          <Plus class="w-3.5 h-3.5 text-green-500" />
          Registra incasso
        </Link>
    </Button>
    </div>
  </div>
</template>
