<script setup lang="ts" generic="TData">
import { ref, computed } from 'vue'
import { watchDebounced } from '@vueuse/core'
import { usePage, Link } from '@inertiajs/vue3'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { Search, X, Plus } from 'lucide-vue-next'
import type { Table } from '@tanstack/vue-table'
import { usePermission } from '@/composables/permissions'
import { useTabellaServer } from '@/composables/useTabellaServer'
import { Select, SelectContent, SelectGroup, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import type { Building } from '@/types/buildings'

defineProps<{
  table: Table<TData>
}>()

const page = usePage<{
  condominio: Building
  filters: any
  stati: { value: string; label: string }[]
}>()
const { generateRoute } = usePermission()
const condominioId = computed(() => page.props.condominio.id)

// Inizializzazione filtri dall'URL
const globalFilter = ref(page.props.filters?.search || '')
const selectedMetodo = ref(page.props.filters?.metodo_pagamento || 'all')
const selectedStato = ref(page.props.filters?.stato || 'all')
const dataDa = ref(page.props.filters?.data_da || '')
const dataA = ref(page.props.filters?.data_a || '')

const metodiOpzioni = [
  { value: 'bonifico', label: 'Bonifico' },
  { value: 'assegno', label: 'Assegno' },
  { value: 'contanti', label: 'Contanti' },
  { value: 'f24', label: 'Modello F24' },
]

const { filtra } = useTabellaServer(() =>
  route(generateRoute('gestionale.pagamenti-fornitori.index'), { condominio: condominioId.value }),
)

// Osservatore con debounce che scatta quando cambia search, metodo, stato o intervallo date
watchDebounced(
  [globalFilter, selectedMetodo, selectedStato, dataDa, dataA],
  () => {
    filtra({
      search: globalFilter.value || null,
      metodo_pagamento: selectedMetodo.value && selectedMetodo.value !== 'all' ? selectedMetodo.value : null,
      stato: selectedStato.value && selectedStato.value !== 'all' ? selectedStato.value : null,
      data_da: dataDa.value || null,
      data_a: dataA.value || null,
    })
  },
  { debounce: 300 }
)

const isFiltered = computed(() =>
  globalFilter.value.length > 0
  || (selectedMetodo.value !== '' && selectedMetodo.value !== 'all')
  || (selectedStato.value !== '' && selectedStato.value !== 'all')
  || dataDa.value.length > 0
  || dataA.value.length > 0
)

const resetFilters = () => {
  globalFilter.value = ''
  selectedMetodo.value = 'all'
  selectedStato.value = 'all'
  dataDa.value = ''
  dataA.value = ''
}
</script>

<template>
  <div class="flex items-center justify-between w-full">

    <div class="flex items-center space-x-2">
      <!-- Ricerca libera -->
      <div class="relative">
        <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-slate-400">
          <Search class="h-4 w-4" />
        </div>
        <Input
          placeholder="Cerca fornitore o causale..."
          v-model="globalFilter"
          class="pl-9 h-8 w-[200px] lg:w-[250px]"
        />
      </div>

      <!-- Filtro Metodo -->
      <div class="w-40 lg:w-48">
        <Select v-model="selectedMetodo">
          <SelectTrigger class="w-full bg-white h-8">
            <SelectValue placeholder="Tutti i metodi" />
          </SelectTrigger>
          <SelectContent>
            <SelectGroup>
              <SelectItem value="all">Tutti i metodi</SelectItem>
              <SelectItem v-for="opzione in metodiOpzioni" :key="opzione.value" :value="opzione.value">
                {{ opzione.label }}
              </SelectItem>
            </SelectGroup>
          </SelectContent>
        </Select>
      </div>

      <!-- Filtro Stato -->
      <div class="w-40 lg:w-48">
        <Select v-model="selectedStato">
          <SelectTrigger class="w-full bg-white h-8">
            <SelectValue placeholder="Tutti gli stati" />
          </SelectTrigger>
          <SelectContent>
            <SelectGroup>
              <SelectItem value="all">Tutti gli stati</SelectItem>
              <SelectItem v-for="opzione in page.props.stati" :key="opzione.value" :value="opzione.value">
                {{ opzione.label }}
              </SelectItem>
            </SelectGroup>
          </SelectContent>
        </Select>
      </div>

      <!-- Intervallo date -->
      <Input type="date" v-model="dataDa" class="h-8 w-[140px] text-xs" title="Data pagamento da" />
      <Input type="date" v-model="dataA" class="h-8 w-[140px] text-xs" title="Data pagamento a" />

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

    <!-- Pulsante Nuovo (convertito a Link per usare il look del secondo file) -->
    <Link
      :href="route(generateRoute('gestionale.pagamenti-fornitori.create'), { condominio: condominioId })"
      class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-slate-900 dark:bg-slate-700 border border-slate-800 shadow-sm text-xs font-medium text-white hover:bg-slate-800 dark:hover:bg-slate-600 transition-colors"
    >
      <Plus class="w-3.5 h-3.5 text-green-500" />
      Nuovo pagamento
    </Link>

  </div>
</template>
