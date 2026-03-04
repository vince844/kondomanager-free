<script setup lang="ts" generic="TData, TValue">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { FlexRender, getCoreRowModel, useVueTable, getSortedRowModel } from '@tanstack/vue-table';
import { valueUpdater } from '@/lib/utils';
import DataTablePagination from '@/components/DataTablePagination.vue';
import DataTableToolbar from '@/components/gestionale/pianiRate/DataTableToolbar.vue';
import { usePermission } from "@/composables/permissions";

// Import componenti Empty e icone
import { Empty, EmptyHeader, EmptyMedia, EmptyTitle, EmptyDescription } from '@/components/ui/empty';
import { CalendarDays, ArrowRight } from 'lucide-vue-next';

import type { ColumnDef, SortingState } from '@tanstack/vue-table';
import type { PianoRate } from '@/types/gestionale/piani-rate';
import type { Building } from '@/types/buildings';

const props = defineProps<{
  columns: ColumnDef<PianoRate, any>[],
  data: PianoRate[],
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
    if (isPending.value) return 
    isPending.value = true
    
    const nextPage = typeof updater === 'function'
      ? updater(table.getState().pagination).pageIndex
      : updater.pageIndex;

    const nextPageSize = table.getState().pagination.pageSize;

    router.get(route(generateRoute('gestionale.conti.index'), { condominio: props.condominio.id}), {
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

  <Empty v-else class="border border-dashed py-12">
    <EmptyHeader class="max-w-4xl">
      <EmptyMedia variant="icon" class="bg-indigo-50/50 dark:bg-indigo-900/20 text-indigo-500">
        <CalendarDays class="w-8 h-8" />
      </EmptyMedia>
      <EmptyTitle>Nessun piano rate trovato</EmptyTitle>
      <EmptyDescription>
        Non sono ancora stati generati piani rate per questo esercizio. <br>
        Se hai già creato un piano dei conti, inizia cliccando su "Crea piano rate" per definire le scadenze delle rate e i relativi importi.
      </EmptyDescription>
    </EmptyHeader>
  </Empty>

  <div v-if="table.getRowModel().rows?.length" class="flex items-center justify-end py-4 space-x-2">
    <DataTablePagination :table="table" :meta="props.meta" />
  </div>
</template>