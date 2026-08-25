<script setup lang="ts">

import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { type Table } from '@tanstack/vue-table'
import { ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight } from 'lucide-vue-next'
import { Button } from '@/components/ui/button'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'

interface PaginationMeta {
  current_page: number
  per_page: number
  last_page: number
  total: number
}

interface DataTablePaginationProps<TData> {
  table: Table<TData>
  meta: PaginationMeta
}

const { table, meta } = defineProps<DataTablePaginationProps<any>>()

const pagina = usePage<{ paginazione?: { consentite?: number[] } }>()

/**
 * I valori offerti dal menu, dal server e non scritti qui.
 *
 * ⚠️ **Il valore in uso ci deve stare dentro.** Il menu offriva `[15,20,30,40,50]` mentre il
 * predefinito era 10: il selettore mostrava «10» come segnaposto e nel menu quel numero non
 * c'era, quindi chi passava a 50 non aveva più modo di tornare indietro. La riga qui sotto è la
 * garanzia che la cosa non si ripeta anche se le due liste divergessero di nuovo.
 */
const opzioni = computed<number[]>(() => {
  const consentite = pagina.props.paginazione?.consentite ?? [10, 15, 20, 30, 40, 50, 100]
  const inUso = meta.per_page

  return consentite.includes(inUso) ? consentite : [...consentite, inUso].sort((a, b) => a - b)
})

const handlePageChange = (newPage: number) => {
  if (meta.current_page === newPage) return
  table.setPageIndex(newPage - 1)
}
</script>

<template>
  <div class="flex items-center justify-between">
    <div class="flex items-center space-x-2 lg:space-x-2">
      <div class="flex items-center space-x-2">
        <!-- Page Size Selector -->
        <Select
          v-model="table.getState().pagination.pageSize" 
          @update:model-value="(value) => {
            table.setPageSize(Number(value));
            table.setPageIndex(0); // Reset to first page when page size changes
          }"
        >
          <SelectTrigger class="h-8 w-[70px]">
            <!-- Il numero mostrato è quello con cui il server ha davvero paginato, non lo stato
                 locale della libreria: due copie dello stesso dato divergono, e la prima a
                 mentire è sempre quella che l'utente vede. -->
            <SelectValue :placeholder="`${meta.per_page}`" />
          </SelectTrigger>
          <SelectContent side="top">
            <SelectItem v-for="pageSize in opzioni" :key="pageSize" :value="pageSize">
              {{ pageSize }}
            </SelectItem>
          </SelectContent>
        </Select>
      </div>

      <!-- Pagination info -->
<!--       <div class="flex w-[100px] items-center justify-center text-sm font-medium">
        Pagina {{ meta.current_page }} di {{ meta.last_page }}
      </div>
 -->
      <!-- Pagination controls -->
      <div class="flex items-center space-x-2">
        <Button
          variant="outline"
          class="hidden w-8 h-8 p-0 lg:flex"
          :disabled="meta.current_page === 1"
          @click="handlePageChange(1)"
        >
          <span class="sr-only">Vai alla prima pagina</span>
          <ChevronsLeft class="w-4 h-4" />
        </Button>
        <Button
          variant="outline"
          class="w-8 h-8 p-0"
          :disabled="meta.current_page === 1"
          @click="handlePageChange(meta.current_page - 1)"
        >
          <span class="sr-only">Vai alla pagina precedente</span>
          <ChevronLeft class="w-4 h-4" />
        </Button>
        <Button
          variant="outline"
          class="w-8 h-8 p-0"
          :disabled="meta.current_page === meta.last_page"
          @click="handlePageChange(meta.current_page + 1)"
        >
          <span class="sr-only">Vai alla prossima pagina</span>
          <ChevronRight class="w-4 h-4" />
        </Button>
        <Button
          variant="outline"
          class="hidden w-8 h-8 p-0 lg:flex"
          :disabled="meta.current_page === meta.last_page"
          @click="handlePageChange(meta.last_page)"
        >
          <span class="sr-only">Vai all'ultima pagina</span>
          <ChevronsRight class="w-4 h-4" />
        </Button>
      </div>
    </div>
  </div>
</template>
