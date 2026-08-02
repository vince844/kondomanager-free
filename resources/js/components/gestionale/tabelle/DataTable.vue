<script setup lang="ts" generic="TData, TValue">

import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { FlexRender, getCoreRowModel, useVueTable, getSortedRowModel } from '@tanstack/vue-table';
import { valueUpdater } from '@/lib/utils';
import DataTablePagination from '@/components/DataTablePagination.vue';
import DataTableToolbar from '@/components/gestionale/tabelle/DataTableToolbar.vue';
import { usePermission } from "@/composables/permissions";
import TableEmptyState from '@/components/gestionale/TableEmptyState.vue';
import { Table2 } from 'lucide-vue-next';
import type { ColumnDef, SortingState } from '@tanstack/vue-table';
import type { Tabella } from '@/types/gestionale/tabelle';
import type { Building } from '@/types/buildings';

const props = defineProps<{
  columns: ColumnDef<Tabella, any>[],
  data: Tabella[],
  condominio: Building 
  meta: {
    current_page: number,
    per_page: number,
    last_page: number,
    total: number
  }
}>()

const { generateRoute } = usePermission();
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
  onPaginationChange: updater => {

    // Prevent concurrent requests
    if (isPending.value) return 
    
    isPending.value = true
    
    const nextPage = typeof updater === 'function'
      ? updater(table.getState().pagination).pageIndex
      : updater.pageIndex;

    const nextPageSize = table.getState().pagination.pageSize;

    router.get(route(generateRoute('gestionale.tabelle.index'), { condominio: props.condominio.id}), {
      page: nextPage + 1,
      per_page: nextPageSize,
    }, {
      preserveState: true,
      preserveScroll: true,
      replace: true,
      onFinish: () => {
        isPending.value = false
      }
    });
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
  
  <div v-if="table.getRowModel().rows?.length" class="border rounded-md">
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
        <TableRow
          v-for="row in table.getRowModel().rows" :key="row.id"
          :data-state="row.getIsSelected() ? 'selected' : undefined"
        >
          <TableCell v-for="cell in row.getVisibleCells()" :key="cell.id">
            <FlexRender :render="cell.column.columnDef.cell" :props="cell.getContext()" />
          </TableCell>
        </TableRow>
      </TableBody>
    </Table>
  </div>

  <TableEmptyState
    v-else
    :icon="Table2"
    title="Nessuna tabella millesimale"
    description="Non è ancora stata creata nessuna tabella millesimale. Senza almeno una tabella i capitoli di spesa non possono essere ripartiti fra le unità."
    media-class="bg-violet-50/50 dark:bg-violet-900/20 text-violet-500"
  />
  <div v-if="table.getRowModel().rows?.length" class="flex items-center justify-end py-4 space-x-2">
    <DataTablePagination :table="table" :meta="props.meta" />
  </div>
  
</template>