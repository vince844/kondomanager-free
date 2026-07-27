<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { watchDebounced } from '@vueuse/core'
import { router, usePage } from '@inertiajs/vue3'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Search, X } from 'lucide-vue-next'
import type { Table } from '@tanstack/vue-table'
import { usePermission } from '@/composables/permissions'
import type { Building } from '@/types/buildings'
import type { Esercizio } from '@/types/gestionale/esercizi'
import type { ScritturaRow } from './columns'
import { STATO_LABELS } from './columns'

defineProps<{
  table: Table<ScritturaRow>
}>()

const page = usePage<{
  condominio: Building
  esercizio: Esercizio
  tipiMovimento: { value: string; label: string }[]
  stati: string[]
  filters: {
    search?: string
    tipo_movimento?: string
    stato?: string
    data_da?: string
    data_a?: string
  }
}>()
const { generateRoute } = usePermission()
const condominioId = computed(() => page.props.condominio.id)
const esercizioId = computed(() => page.props.esercizio.id)

const search = ref(page.props.filters?.search || '')
const tipoMovimento = ref(page.props.filters?.tipo_movimento || '')
const stato = ref(page.props.filters?.stato || '')
const dataDa = ref(page.props.filters?.data_da || '')
const dataA = ref(page.props.filters?.data_a || '')

const applyFilters = () => {
  const params: Record<string, string | number> = { page: 1 }

  if (search.value) params.search = search.value
  if (tipoMovimento.value) params.tipo_movimento = tipoMovimento.value
  if (stato.value) params.stato = stato.value
  if (dataDa.value) params.data_da = dataDa.value
  if (dataA.value) params.data_a = dataA.value

  router.get(
    route(generateRoute('gestionale.esercizi.scritture.index'), { condominio: condominioId.value, esercizio: esercizioId.value }),
    params,
    { preserveState: true, replace: true, preserveScroll: true }
  )
}

watchDebounced(search, applyFilters, { debounce: 300 })
watch([tipoMovimento, stato, dataDa, dataA], applyFilters)

const isFiltered = computed(() =>
  !!(search.value || tipoMovimento.value || stato.value || dataDa.value || dataA.value)
)

const resetFilters = () => {
  search.value = ''
  tipoMovimento.value = ''
  stato.value = ''
  dataDa.value = ''
  dataA.value = ''
}
</script>

<template>
  <div class="flex flex-wrap items-center gap-2 w-full">

    <!-- Ricerca libera -->
    <div class="relative">
      <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-slate-400">
        <Search class="h-4 w-4" />
      </div>
      <Input
        placeholder="Cerca protocollo o causale..."
        v-model="search"
        class="pl-9 h-8 w-[200px] lg:w-[240px]"
      />
    </div>

    <!-- Tipo movimento -->
    <Select v-model="tipoMovimento">
      <SelectTrigger class="h-8 w-[170px] text-xs style-chooser">
        <SelectValue placeholder="Tipo movimento" />
      </SelectTrigger>
      <SelectContent position="popper" :style="{ width: 'var(--reka-select-trigger-width)' }" class="max-h-[260px]">
        <SelectItem v-for="tipo in page.props.tipiMovimento" :key="tipo.value" :value="tipo.value">
          {{ tipo.label }}
        </SelectItem>
      </SelectContent>
    </Select>

    <!-- Stato -->
    <Select v-model="stato">
      <SelectTrigger class="h-8 w-[140px] text-xs style-chooser">
        <SelectValue placeholder="Stato" />
      </SelectTrigger>
      <SelectContent position="popper" :style="{ width: 'var(--reka-select-trigger-width)' }">
        <SelectItem v-for="s in page.props.stati" :key="s" :value="s" class="capitalize">
          {{ STATO_LABELS[s] ?? s }}
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
      @click="resetFilters"
      class="h-8 px-2 lg:px-3 text-slate-500 hover:text-slate-700"
    >
      <X class="h-4 w-4 mr-1 lg:mr-2" />
      <span class="hidden lg:inline">Azzera filtri</span>
      <span class="inline lg:hidden">Azzera</span>
    </Button>

  </div>
</template>
