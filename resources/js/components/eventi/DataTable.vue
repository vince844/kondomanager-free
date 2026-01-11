<script setup lang="ts" generic="TData, TValue">

import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { FlexRender, getCoreRowModel, useVueTable, getSortedRowModel } from '@tanstack/vue-table';
import { valueUpdater } from '@/lib/utils';
import DataTablePagination from '@/components/DataTablePagination.vue';
import DataTableToolbar from '@/components/eventi/DataTableToolbar.vue';
import { usePermission } from '@/composables/permissions';
import type { ColumnDef, SortingState } from '@tanstack/vue-table';
import type { Evento } from '@/types/eventi';

const props = defineProps<{
  columns: ColumnDef<Evento, any>[],
  data: Evento[],
  meta: {
    current_page: number,
    per_page: number,
    last_page: number,
    total: number
  }
}>()

const { generateRoute } = usePermission()
const sorting = ref<SortingState>([])
const isPending = ref(false) 

const table = useVueTable({
  get data() {
    return props.data ?? []
  },
  get columns() {
    return props.columns ?? []
  },
  pageCount: props.meta.last_page,
  state: {
    pagination: {
      pageIndex: props.meta.current_page - 1,
      pageSize: props.meta.per_page,
    },
    get sorting() {
      return sorting.value
    },
  },
  manualPagination: true,
  onPaginationChange: (updater) => {
  if (isPending.value) return
  isPending.value = true

  const nextPage = typeof updater === 'function'
    ? updater(table.getState().pagination).pageIndex + 1
    : updater.pageIndex + 1

  const nextPageSize = typeof updater === 'function'
    ? updater(table.getState().pagination).pageSize
    : updater.pageSize

  const currentQuery = new URLSearchParams(window.location.search)
  const queryParams: Record<string, any> = {}

  for (const [key, value] of currentQuery.entries()) {
    // Filter out duplicate pagination if present
    if (key !== 'page' && key !== 'per_page') {
      queryParams[key] = value
    }
  }

  queryParams.page = nextPage
  queryParams.per_page = nextPageSize

  router.get(route(generateRoute('eventi.index')), queryParams, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
    onFinish: () => {
      isPending.value = false
    },
  })
},
  onSortingChange: updaterOrValue => valueUpdater(updaterOrValue, sorting),
  getCoreRowModel: getCoreRowModel(),
  getSortedRowModel: getSortedRowModel(),

})

</script>

<template>
    <div class="flex items-center">
      <DataTableToolbar :table="table" />
    </div>
  
  <div class="border rounded-md">
    <Table>
      <TableHeader>
        <TableRow v-for="headerGroup in table.getHeaderGroups()" :key="headerGroup.id">
          <TableHead v-for="header in headerGroup.headers" :key="header.id">
            <FlexRender
              v-if="!header.isPlaceholder" :render="header.column.columnDef.header"
              :props="header.getContext()"
            />
          </TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        <template v-if="table.getRowModel().rows?.length">
          <TableRow
            v-for="row in table.getRowModel().rows" :key="row.id"
            :data-state="row.getIsSelected() ? 'selected' : undefined"
          >
            <TableCell v-for="cell in row.getVisibleCells()" :key="cell.id">
              <FlexRender :render="cell.column.columnDef.cell" :props="cell.getContext()" />
            </TableCell>
          </TableRow>
        </template>
        <template v-else>
          <TableRow>
            <TableCell :colspan="columns.length" class="h-24 text-center">
              {{ trans('eventi.table.nessum_risultato_trovato') }}
            </TableCell>
          </TableRow>
        </template>
      </TableBody>
    </Table>
  </div>
  <div class="flex items-center justify-end py-4 space-x-2">
    <DataTablePagination :table="table" :meta="props.meta" />
  </div>
  
</template>