<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { router, usePage, Link } from '@inertiajs/vue3';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Search, Plus, Zap, X } from 'lucide-vue-next';
import { usePermission } from "@/composables/permissions";
import type { Table } from '@tanstack/vue-table';
import type { Building } from '@/types/buildings';

defineProps<{ table: Table<any> }>();
const page = usePage<{
  condominio: Building
  statiPagamento: { value: string; label: string }[]
  filters: {
    search?: string
    stato_pagamento?: string
    stato_approvazione?: string
    data_da?: string
    data_a?: string
  }
}>();
const { generateRoute } = usePermission();
const condominioId = computed(() => page.props.condominio.id);

const globalFilter = ref(page.props.filters?.search || '');
const statoPagamento = ref(page.props.filters?.stato_pagamento || '');
const dataDa = ref(page.props.filters?.data_da || '');
const dataA = ref(page.props.filters?.data_a || '');

const filterParams = computed(() => {
  const params: Record<string, any> = { page: 1 }
  if (globalFilter.value) params.search = globalFilter.value
  if (statoPagamento.value) params.stato_pagamento = statoPagamento.value
  if (dataDa.value) params.data_da = dataDa.value
  if (dataA.value) params.data_a = dataA.value

  // Mantieni eventuali filtri attivi (es. sforo_motivato cliccato dalla card)
  if (page.props.filters?.stato_approvazione) {
      params.stato_approvazione = page.props.filters.stato_approvazione;
  }
  return params
})

const applyFilters = () => {
  router.get(
    route(generateRoute('gestionale.fatture.index'), { condominio: condominioId.value }),
    filterParams.value,
    { preserveState: true, replace: true, preserveScroll: true }
  )
}

watchDebounced(globalFilter, applyFilters, { debounce: 300 })
watch([statoPagamento, dataDa, dataA], applyFilters)

const isFiltered = computed(() =>
  !!(globalFilter.value || statoPagamento.value || dataDa.value || dataA.value || page.props.filters?.stato_approvazione)
)

const resetFilters = () => {
  globalFilter.value = ''
  statoPagamento.value = ''
  dataDa.value = ''
  dataA.value = ''

  router.get(
    route(generateRoute('gestionale.fatture.index'), { condominio: condominioId.value }),
    { page: 1 },
    { preserveState: true, replace: true, preserveScroll: true }
  )
}

</script>

<template>
  <div class="flex flex-wrap items-center justify-between w-full gap-2">

    <div class="flex flex-wrap items-center gap-2">

      <!-- Ricerca libera -->
      <div class="relative">
        <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-slate-400">
          <Search class="h-4 w-4" />
        </div>
        <Input
          placeholder="Filtra per numero o fornitore..."
          v-model="globalFilter"
          class="pl-9 h-8 w-[200px] lg:w-[250px]"
        />
      </div>

      <!-- Stato pagamento -->
      <Select v-model="statoPagamento">
        <SelectTrigger class="h-8 w-[170px] text-xs style-chooser">
          <SelectValue placeholder="Stato pagamento" />
        </SelectTrigger>
        <SelectContent position="popper" :style="{ width: 'var(--reka-select-trigger-width)' }">
          <SelectItem v-for="stato in page.props.statiPagamento" :key="stato.value" :value="stato.value">
            {{ stato.label }}
          </SelectItem>
        </SelectContent>
      </Select>

      <!-- Intervallo data documento -->
      <Input type="date" v-model="dataDa" class="h-8 w-[140px] text-xs" title="Data documento da" />
      <Input type="date" v-model="dataA" class="h-8 w-[140px] text-xs" title="Data documento a" />

      <!-- Reset -->
      <Button
        v-if="isFiltered"
        variant="ghost"
        @click="resetFilters"
        class="h-8 px-2 lg:px-3 text-slate-500 hover:text-slate-700"
      >
        <X class="h-4 w-4 mr-1 lg:mr-2" />
        <span class="hidden lg:inline">Azzera filtri</span>
        <span class="inline lg:hidden">Azzera</span>
      </Button>

    </div>

    <div class="flex items-center gap-2">
      <!-- La regolazione immediata è il fratello minore della fattura (costo → banca
           senza partita fornitore): vive qui accanto, non più nella barra movimenti. -->
      <Link
        :href="route(generateRoute('gestionale.regolazioni-immediate.create'), { condominio: condominioId })"
        class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-slate-900 dark:bg-slate-700 border border-slate-800 dark:border-slate-700 shadow-sm text-xs text-white font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-800 dark:hover:bg-slate-800 transition-colors"
      >
        <Zap class="w-3.5 h-3.5 text-amber-500" />
        Regolazione immediata
      </Link>

      <Link
        :href="route(generateRoute('gestionale.fatture.create'), { condominio: condominioId })"
        class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-slate-900 dark:bg-slate-700 border border-slate-800 shadow-sm text-xs font-medium text-white hover:bg-slate-800 dark:hover:bg-slate-600 transition-colors"
      >
        <Plus class="w-3.5 h-3.5 text-green-500" />
        Nuova fattura
      </Link>
    </div>

  </div>
</template>
