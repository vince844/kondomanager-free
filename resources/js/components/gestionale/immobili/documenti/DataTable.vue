<script setup lang="ts" generic="TData, TValue">

import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { FlexRender, getCoreRowModel, useVueTable, getSortedRowModel } from '@tanstack/vue-table';
import { valueUpdater } from '@/lib/utils';
import DataTablePagination from '@/components/DataTablePagination.vue';
import DataTableToolbar from '@/components/gestionale/immobili/documenti/DataTableToolbar.vue';
import { usePermission } from "@/composables/permissions";
import TableEmptyState from '@/components/gestionale/TableEmptyState.vue';
import { Files } from 'lucide-vue-next';
import type { Documento } from '@/types/documenti';
import type { Immobile } from '@/types/gestionale/immobili';
import type { Building } from '@/types/buildings';
import type { ColumnDef, SortingState } from '@tanstack/vue-table';

const props = defineProps<{
  columns: ColumnDef<Documento, any>[],
  data: Documento[],
  condominio: Building,
  immobile: Immobile
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

     router.get(route(generateRoute('gestionale.immobili.documenti.index'), { condominio: props.condominio.id, immobile: props.immobile.id}), {
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
    :icon="Files"
    title="Nessun documento"
    description="A questa unità non è ancora stato allegato nessun documento: rogiti, planimetrie, visure, contratti di locazione."
    media-class="bg-slate-100/70 dark:bg-slate-800/50 text-slate-500"
  />
  <div v-if="table.getRowModel().rows?.length" class="flex items-center justify-end py-4 space-x-2">
    <DataTablePagination :table="table" :meta="props.meta" />
  </div>
  
</template>