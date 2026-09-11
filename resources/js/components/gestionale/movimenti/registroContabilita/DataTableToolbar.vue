<script setup lang="ts">
import { ref, computed } from 'vue'
import { watchDebounced } from '@vueuse/core'
import { usePage } from '@inertiajs/vue3'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { Search, X } from 'lucide-vue-next'
import { usePermission } from '@/composables/permissions'
import { useTabellaServer } from '@/composables/useTabellaServer'
import type { Building } from '@/types/buildings'
import type { Esercizio } from '@/types/gestionale/esercizi'

const page = usePage<{
  condominio: Building
  esercizio: Esercizio
  filters: { search?: string; data_da?: string; data_a?: string }
}>()
const { generateRoute } = usePermission()
const condominioId = computed(() => page.props.condominio.id)
const esercizioId = computed(() => page.props.esercizio.id)

const search = ref(page.props.filters?.search || '')
const dataDa = ref(page.props.filters?.data_da || '')
const dataA = ref(page.props.filters?.data_a || '')

const { filtra } = useTabellaServer(() =>
  route(generateRoute('gestionale.esercizi.registro-contabilita.index'), { condominio: condominioId.value, esercizio: esercizioId.value }),
)

const applyFilters = () => {
  filtra({
    search: search.value || null,
    data_da: dataDa.value || null,
    data_a: dataA.value || null,
  })
}

watchDebounced(search, applyFilters, { debounce: 300 })
watchDebounced([dataDa, dataA], applyFilters, { debounce: 100 })

const isFiltered = computed(() => !!(search.value || dataDa.value || dataA.value))

const resetFilters = () => {
  search.value = ''
  dataDa.value = ''
  dataA.value = ''
}
</script>

<template>
  <div class="flex flex-wrap items-center gap-2 w-full">
    <div class="relative">
      <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-slate-400">
        <Search class="h-4 w-4" />
      </div>
      <Input
        placeholder="Cerca protocollo o descrizione..."
        v-model="search"
        class="pl-9 h-8 w-[220px] lg:w-[260px]"
      />
    </div>

    <Input type="date" v-model="dataDa" class="h-8 w-[140px] text-xs" title="Data effettiva da" />
    <Input type="date" v-model="dataA" class="h-8 w-[140px] text-xs" title="Data effettiva a" />

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
