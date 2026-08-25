<script setup lang="ts">

import { ref, computed } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { router, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Plus, Calendar as CalendarIcon , X} from 'lucide-vue-next';
import { usePermission } from '@/composables/permissions';
import { Permission }  from "@/enums/Permission";
import { useCategorieEventi } from '@/composables/useCategorieEventi';
import { useTabellaServer } from '@/composables/useTabellaServer';
import DataTableFacetedFilter from '@/components/eventi/DataTableFacetedFilter.vue';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { RangeCalendar } from '@/components/ui/range-calendar';
import { getLocalTimeZone, DateFormatter } from '@internationalized/date';
import type { Table } from '@tanstack/vue-table';
import type { Evento } from '@/types/eventi';

const df = new DateFormatter('it-IT', { dateStyle: 'short' })
const { generateRoute, hasPermission } = usePermission()
const { categorie, isLoading, loadCategorie } = useCategorieEventi()
const { table } = defineProps<{ table: Table<Evento> }>()
const nameFilter = ref<string>('')
const dateRange = ref<any>({ start: undefined, end: undefined })
const categoriaColumn = table.getColumn('categoria')

const categoriaFilter = computed(() => {
  const val = categoriaColumn?.getFilterValue()
  return Array.isArray(val) ? val : []
})

const handleOpenDropdown = () => {
  loadCategorie()
}

const convertCalendarDateToString = (date: any): string | undefined => {
  // Defensive: date can be undefined
  if (!date) return undefined
  // Convert to JS Date using getLocalTimeZone
  const jsDate = typeof date.toDate === 'function' ? date.toDate(getLocalTimeZone()) : new Date(date)
  return jsDate.toISOString().split('T')[0]
}

const { filtra } = useTabellaServer(() => route(generateRoute('eventi.index')))

watchDebounced(
  [nameFilter, categoriaFilter, dateRange],
  ([title, category_id, range]) => {
    // ⚠️ `category_id` è a selezione multipla e viaggia come array. Un array **vuoto** va passato
    // come `null`: `[]` è truthy in JavaScript, quindi `category_id || null` non basterebbe, e un
    // array vuoto che arrivasse fino all'indirizzo resterebbe lì come filtro attivo su niente.
    filtra({
      title: title || null,
      category_id: category_id.length > 0 ? category_id : null,
      date_from: range?.start ? convertCalendarDateToString(range.start) ?? null : null,
      date_to: range?.end ? convertCalendarDateToString(range.end) ?? null : null,
    })
  },
  { debounce: 300 }
)

const clearAllFilters = () => {
  nameFilter.value = ''
  dateRange.value = { start: undefined, end: undefined }
  categoriaColumn?.setFilterValue(undefined)

  router.get(route(generateRoute('eventi.index')), { page: 1 }, {
    preserveState: true,
    replace: true,
    preserveScroll: true,
  })
}

const clearDateFilter = () => {
  dateRange.value = { start: undefined, end: undefined }

  // Re-trigger the watcher by forcing new object reference and update route params
  router.get(route(generateRoute('eventi.index')), {
    title: nameFilter.value || undefined,
    category_id: categoriaFilter.value.length > 0 ? categoriaFilter.value : undefined,
    date_from: undefined,
    date_to: undefined,
    page: 1
  }, {
    preserveState: true,
    replace: true,
    preserveScroll: true,
  })
}

const formattedRange = computed(() => {
  const startDate = dateRange.value.start?.toDate ? dateRange.value.start.toDate(getLocalTimeZone()) : (dateRange.value.start ? new Date(dateRange.value.start) : undefined)
  const endDate = dateRange.value.end?.toDate ? dateRange.value.end.toDate(getLocalTimeZone()) : (dateRange.value.end ? new Date(dateRange.value.end) : undefined)

  if (startDate && endDate) {
    return `${df.format(startDate)} - ${df.format(endDate)}`
  } else if (startDate) {
    return df.format(startDate)
  }
  return 'Seleziona periodo'
})

</script>

<template>
  <div class="flex flex-col gap-2 w-full mb-3 lg:flex-row lg:items-center lg:justify-between">
    <div class="flex flex-col gap-2 lg:flex-row lg:items-center lg:gap-4">
      <!-- Search and Category Filters -->
      <div class="flex items-center space-x-2">
        <Input
          placeholder="Filtra per nome..."
          v-model="nameFilter"
          class="h-8 w-[150px] lg:w-[250px]"
        />

        <Popover>
          <PopoverTrigger as-child>
            <Button
              variant="outline"
              class="h-8 justify-start text-left font-normal w-[260px]"
            >
              <CalendarIcon class="mr-2 h-4 w-4" />
              {{ formattedRange }}
            </Button>
          </PopoverTrigger>
          <PopoverContent class="w-auto p-0">

            <RangeCalendar
              v-model="dateRange"
              initial-focus
              :number-of-months="2"
            />
            <div class="p-2 border-t flex justify-end">
              <Button variant="outline" size="sm" @click="clearDateFilter">
                Cancella
              </Button>
            </div>
          </PopoverContent>
        </Popover>

        <DataTableFacetedFilter
          v-if="categoriaColumn"
          :column="categoriaColumn"
          title="Categoria"
          :options="categorie"
          :isLoading="isLoading"
          @open="handleOpenDropdown"
          @update:filter="() => {}"
        />

        <Button
          variant="outline"
          size="sm"
          @click="clearAllFilters"
        >

         <X />
          Resetta tutti i filtri
        </Button>
      </div>

    </div>

    <!-- Create Button -->
    <Link
      v-if="hasPermission([Permission.CREATE_EVENTS])"
      as="button"
      :href="route(generateRoute('eventi.create'))"
      class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-slate-900 dark:bg-slate-700 border border-slate-800 shadow-sm text-xs font-medium text-white hover:bg-slate-800 dark:hover:bg-slate-600 transition-colors"
    >
      <Plus class="w-3.5 h-3.5 text-green-500" />
      <span>Crea scandenza</span>
    </Link>
  </div>
</template>
