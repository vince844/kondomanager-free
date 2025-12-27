<script setup lang="ts" generic="TData, TValue">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { FlexRender, getCoreRowModel, useVueTable, getSortedRowModel } from '@tanstack/vue-table';
import { valueUpdater } from '@/lib/utils';
import DataTablePagination from '@/components/DataTablePagination.vue';
import DataTableToolbar from '@/components/gestionale/movimenti/incassi/DataTableToolbar.vue';
import { usePermission } from "@/composables/permissions";
import type { ColumnDef, SortingState } from '@tanstack/vue-table';
import type { Building } from '@/types/buildings';
import type { Incasso } from '@/types/gestionale/movimenti';

const props = defineProps<{
  columns: ColumnDef<Incasso, any>[],
  data: Incasso[],
  condominio: Building,
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
  get data() { return props.data ?? [] },
  get columns() { return props.columns ?? [] },
  pageCount: props.meta.last_page,
  state: {
    pagination: {
      pageIndex: props.meta.current_page - 1,
      pageSize: props.meta.per_page,
    },
    get sorting() { return sorting.value },
  },
  manualPagination: true,
  onPaginationChange: updater => {
    if (isPending.value) return 
    isPending.value = true
    
    const nextPage = typeof updater === 'function'
      ? updater(table.getState().pagination).pageIndex
      : updater.pageIndex;

    const nextPageSize = table.getState().pagination.pageSize;

    // Gestione corretta della rotta con prefisso admin
    router.get(route(generateRoute('gestionale.movimenti-rate.index'), { condominio: props.condominio.id }), {
      page: nextPage + 1,
      per_page: nextPageSize,
    }, {
      preserveState: true,
      preserveScroll: true,
      replace: true,
      onFinish: () => { isPending.value = false }
    });
  },
  onSortingChange: updaterOrValue => valueUpdater(updaterOrValue, sorting),
  getCoreRowModel: getCoreRowModel(),
  getSortedRowModel: getSortedRowModel(),
})
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center">
      <DataTableToolbar :table="table" />
    </div>
    
    <div class="rounded-md border bg-white">
      <Table>
        <TableHeader>
          <TableRow v-for="headerGroup in table.getHeaderGroups()" :key="headerGroup.id" class="bg-gray-50/50">
            <TableHead v-for="header in headerGroup.headers" :key="header.id" class="px-4">
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
              class="hover:bg-gray-50/50 transition-colors"
            >
              <TableCell v-for="cell in row.getVisibleCells()" :key="cell.id" class="px-4 py-3">
                <FlexRender :render="cell.column.columnDef.cell" :props="cell.getContext()" />
              </TableCell>
            </TableRow>
          </template>
          <template v-else>
            <TableRow>
              <TableCell :colspan="columns.length" class="h-24 text-center text-muted-foreground">
                <div class="flex flex-col items-center justify-center gap-2">
                  <p>Nessun movimento trovato</p>
                </div>
              </TableCell>
            </TableRow>
          </template>
        </TableBody>
      </Table>
    </div>
    
    <div class="flex items-center justify-end">
      <DataTablePagination :table="table" :meta="props.meta" />
    </div>
  </div>
</template>