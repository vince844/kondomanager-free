<script setup lang="ts">
import { ref } from 'vue';
import { useTabellaServer } from '@/composables/useTabellaServer';
import { router } from '@inertiajs/vue3';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { FlexRender, getCoreRowModel, useVueTable } from '@tanstack/vue-table';
import { Empty, EmptyHeader, EmptyMedia, EmptyTitle, EmptyDescription } from '@/components/ui/empty';
import DataTablePagination from '@/components/DataTablePagination.vue';
import DataTableToolbar from './DataTableToolbar.vue';
import { usePermission } from "@/composables/permissions";
import { ScrollText } from 'lucide-vue-next';
import type { ColumnDef, SortingState } from '@tanstack/vue-table';
import type { Building } from '@/types/buildings';
import type { Esercizio } from '@/types/gestionale/esercizi';
import type { ScritturaRow } from './columns';

const props = defineProps<{
  columns: ColumnDef<ScritturaRow>[],
  data: ScritturaRow[],
  condominio: Building,
  esercizio: Esercizio,
  meta: {
    current_page: number,
    per_page: number,
    last_page: number,
    total: number
  }
}>()

const { generateRoute } = usePermission();
const { inCorso, ordinamento, suPaginazione, suOrdinamento } =
  useTabellaServer(() => route(generateRoute('gestionale.esercizi.scritture.index'), { condominio: props.condominio.id, esercizio: props.esercizio.id, }));

const table = useVueTable({
  get data() { return props.data ?? [] },
  get columns() { return props.columns ?? [] },
  pageCount: props.meta.last_page,
  state: {
    pagination: {
      pageIndex: props.meta.current_page - 1,
      pageSize: props.meta.per_page,
    },
    get sorting() { return ordinamento.value },
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
  <div class="space-y-4">
    <div class="flex items-center">
      <DataTableToolbar :table="table" />
    </div>

    <div class="rounded-md border bg-white overflow-hidden">

      <Table v-if="table.getRowModel().rows?.length > 0" class="table-fixed w-full">
        <TableHeader>
          <TableRow v-for="headerGroup in table.getHeaderGroups()" :key="headerGroup.id" class="bg-gray-50/50">
            <TableHead
              v-for="header in headerGroup.headers"
              :key="header.id"
              class="px-4"
              :style="{ width: header.getSize() + 'px' }"
            >
              <FlexRender
                v-if="!header.isPlaceholder"
                :render="header.column.columnDef.header"
                :props="header.getContext()"
              />
            </TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow
            v-for="row in table.getRowModel().rows" :key="row.id"
            :data-state="row.getIsSelected() ? 'selected' : undefined"
            class="hover:bg-gray-50/50 transition-colors"
          >
            <TableCell
              v-for="cell in row.getVisibleCells()"
              :key="cell.id"
              class="px-4 py-3"
              :style="{ width: cell.column.getSize() + 'px' }"
            >
              <FlexRender :render="cell.column.columnDef.cell" :props="cell.getContext()" />
            </TableCell>
          </TableRow>
        </TableBody>
      </Table>

      <Empty v-else class="py-12 bg-slate-50/50">
        <EmptyHeader class="max-w-4xl">
          <EmptyMedia variant="icon" class="bg-violet-50/50 dark:bg-violet-900/20 text-violet-500">
            <ScrollText class="w-8 h-8" />
          </EmptyMedia>
          <EmptyTitle>Nessuna scrittura trovata</EmptyTitle>
          <EmptyDescription>
            Non ci sono scritture contabili che corrispondono ai criteri. <br>
            Modifica i filtri di ricerca o cambia esercizio.
          </EmptyDescription>
        </EmptyHeader>
      </Empty>

    </div>

    <div v-if="table.getRowModel().rows?.length > 0 && props.meta.last_page > 1" class="flex items-center justify-end">
      <DataTablePagination :table="table" :meta="props.meta" />
    </div>
  </div>
</template>
