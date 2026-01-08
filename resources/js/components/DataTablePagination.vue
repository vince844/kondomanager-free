<script setup lang="ts">

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
            <SelectValue :placeholder="`${table.getState().pagination.pageSize}`" />
          </SelectTrigger>
          <SelectContent side="top">
            <SelectItem v-for="pageSize in [15, 20, 30, 40, 50]" :key="pageSize" :value="pageSize">
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
