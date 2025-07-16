<script setup lang="ts">

import { ref, computed } from 'vue'
import { watchDebounced } from '@vueuse/core'
import { router, Link } from '@inertiajs/vue3'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Plus, Calendar as CalendarIcon } from 'lucide-vue-next'
import { usePermission } from '@/composables/permissions'
import { useCategorieEventi } from '@/composables/useCategorieEventi'
import DataTableFacetedFilter from '@/components/eventi/DataTableFacetedFilter.vue'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { RangeCalendar } from '@/components/ui/range-calendar'
import { CalendarDate, getLocalTimeZone, DateFormatter } from '@internationalized/date'
import type { Table } from '@tanstack/vue-table'
import type { Evento } from '@/types/eventi'

const df = new DateFormatter('it-IT', { dateStyle: 'short' })

const { generateRoute, hasPermission } = usePermission()
const { categorie, isLoading, loadCategorie } = useCategorieEventi()

const { table } = defineProps<{ table: Table<Evento> }>()

const nameFilter = ref('')
const dateRange = ref<{ start?: CalendarDate; end?: CalendarDate }>({})
const categoriaColumn = table.getColumn('categoria')

const categoriaFilter = computed(() => {
  const val = categoriaColumn?.getFilterValue()
  return Array.isArray(val) ? val : []
})

const handleOpenDropdown = () => {
  loadCategorie()
}

const convertCalendarDateToString = (date?: CalendarDate): string | undefined => {
  return date ? date.toDate(getLocalTimeZone()).toISOString().split('T')[0] : undefined
}

const getCurrentQuery = () => {
  const urlParams = new URLSearchParams(window.location.search)
  const query: Record<string, any> = {}
  for (const [key, value] of urlParams.entries()) {
    query[key] = value
  }
  return query
}

watchDebounced(
  [nameFilter, categoriaFilter, dateRange],
  ([title, category_id, range]) => {
    const currentQuery = getCurrentQuery()
    const params: Record<string, any> = {
      ...currentQuery,
      page: 1, // reset to first page on filter
    }

    if (title) params.title = title
    else delete params.title

    if (category_id.length > 0) params.category_id = category_id
    else delete params.category_id

    if (range?.start)
      params.date_from = convertCalendarDateToString(range.start)
    else delete params.date_from

    if (range?.end)
      params.date_to = convertCalendarDateToString(range.end)
    else delete params.date_to

    router.get(route('admin.eventi.index'), params, {
      preserveState: true,
      replace: true,
      preserveScroll: true,
    })
  },
  { debounce: 300 }
)

const clearAllFilters = () => {
  nameFilter.value = ''
  dateRange.value = {}
  categoriaColumn?.setFilterValue(undefined)

  router.get(route('admin.eventi.index'), {
    page: 1,
  }, {
    preserveState: true,
    replace: true,
    preserveScroll: true,
  })
}

// Modify your clearDateFilter method
const clearDateFilter = () => {
  dateRange.value = {}

  // Re-trigger the watcher by forcing a new object reference
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
  const start = dateRange.value.start?.toDate(getLocalTimeZone())
  const end = dateRange.value.end?.toDate(getLocalTimeZone())

  if (start && end) {
    return `${df.format(start)} - ${df.format(end)}`
  } else if (start) {
    return df.format(start)
  }
  return 'Seleziona periodo'
})

</script>

<template>
  <div class="flex items-center justify-between w-full mb-3">
    <!-- Left Section: Filters -->
    <div class="flex flex-col space-y-2 w-full lg:flex-row lg:items-center lg:space-y-0 lg:space-x-2">
      <!-- Search and Category Filters -->
      <div class="flex items-center space-x-2">
        <Input
          placeholder="Filtra per nome..."
          v-model="nameFilter"
          class="h-8 w-[150px] lg:w-[250px]"
        />

        <DataTableFacetedFilter
          v-if="categoriaColumn"
          :column="categoriaColumn"
          title="Categoria"
          :options="categorie"
          :isLoading="isLoading"
          @open="handleOpenDropdown"
          @update:filter="() => {}"
        />
      </div>

      <!-- Date Range Filter -->
      <div class="flex items-center space-x-2">
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
              @update:start-value="start => dateRange.start = start"
              @update:end-value="end => dateRange.end = end"
            />
            <div class="p-2 border-t flex justify-end">
              <Button variant="outline" size="sm" @click="clearDateFilter">
                Cancella
              </Button>
            </div>
          </PopoverContent>
        </Popover>

        <Button
          variant="outline"
          size="sm"
          @click="clearAllFilters"
        >
          Resetta tutti i filtri
        </Button>
      </div>
    </div>

    <!-- Create Button -->
    <Link
      as="button"
      :href="route(generateRoute('eventi.create'))"
      class="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90 order-last lg:order-none lg:ml-auto"
    >
      <Plus class="w-4 h-4" />
      <span>Crea</span>
    </Link>
  </div>
</template>
