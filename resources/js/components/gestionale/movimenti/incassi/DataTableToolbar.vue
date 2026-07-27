<script setup lang="ts">

import { ref, computed, watch } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { router, usePage, Link } from '@inertiajs/vue3';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Search, Plus, X } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { usePermission } from "@/composables/permissions";
import type { Table } from '@tanstack/vue-table';
import type { Building } from '@/types/buildings';

defineProps<{ table: Table<any> }>();
const page = usePage<{
  condominio: Building;
  stati: string[];
  filters: { search?: string; stato?: string; data_da?: string; data_a?: string };
}>();
const { generateRoute } = usePermission();
const condominioId = computed(() => page.props.condominio.id);

const globalFilter = ref(page.props.filters?.search || '')
const stato = ref(page.props.filters?.stato || '')
const dataDa = ref(page.props.filters?.data_da || '')
const dataA = ref(page.props.filters?.data_a || '')

const applyFilters = () => {
  const params: Record<string, any> = { page: 1 }
  if (globalFilter.value) params.search = globalFilter.value
  if (stato.value) params.stato = stato.value
  if (dataDa.value) params.data_da = dataDa.value
  if (dataA.value) params.data_a = dataA.value

  router.get(
    route(generateRoute('gestionale.movimenti-rate.index'), { condominio: condominioId.value }),
    params,
    {
      preserveState: true,
      replace: true,
      preserveScroll: true,
    }
  )
}

watchDebounced(globalFilter, applyFilters, { debounce: 300 })
watch([stato, dataDa, dataA], applyFilters)

// 'annullata' è il valore reale in DB (StornoIncassoRateAction), ma per
// l'amministratore "Stornata" è il termine corretto: è lui che ha cliccato
// "Storna" per arrivarci.
const statoLabels: Record<string, string> = { annullata: 'Stornata' }
const statoLabel = (s: string) => statoLabels[s] ?? s

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
        <SelectContent position="popper" :style="{ width: 'var(--reka-select-trigger-width)' }">
          <SelectItem v-for="s in page.props.stati" :key="s" :value="s" class="capitalize">
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
</template>
