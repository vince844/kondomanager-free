<script setup lang="ts" generic="TData, TValue">

import { ref } from 'vue'
import { useTabellaServer } from '@/composables/useTabellaServer'
import { router } from '@inertiajs/vue3'
import type { ColumnDef, SortingState } from '@tanstack/vue-table'
import { Table,TableBody,TableCell,TableHead,TableHeader,TableRow } from '@/components/ui/table'
import { FlexRender, getCoreRowModel, useVueTable } from '@tanstack/vue-table'
import DataTablePagination from '@/components/DataTablePagination.vue'
import DataTableToolbar from '@/components/fornitori/DataTableToolbar.vue'
import type { Fornitore } from '@/types/fornitori'

const props = defineProps<{
  columns: ColumnDef<Fornitore, any>[],
  data: Fornitore[],
  meta: {
    current_page: number,
    per_page: number,
    last_page: number,
    total: number
  },
  // Attraversano solo: chi le usa e' la barra dei filtri.
  categorie?: Array<{ id: number; name: string }>,
  filters?: { ragione_sociale?: string | null; categoria_id?: number[] | null }
}>()

const { inCorso, ordinamento, suPaginazione, suOrdinamento } =
  useTabellaServer(() => route('admin.fornitori.index'));

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
      return ordinamento.value
    },
  },
  manualPagination: true,
  // Senza questo la libreria ordina le righe che ha, cioè la pagina visibile.
  manualSorting: true,
  onPaginationChange: updater => {
    const stato = table.getState().pagination
    const p = typeof updater === 'function' ? updater(stato) : updater
    suPaginazione(p.pageIndex + 1, p.pageSize, stato.pageSize)
  },
  onSortingChange: suOrdinamento,
  getCoreRowModel: getCoreRowModel(),
})

</script>

<template>
    <div class="flex items-center">
      <DataTableToolbar :table="table" :categorie="props.categorie" :filters="props.filters" />
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
              Nessun risultato trovato
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