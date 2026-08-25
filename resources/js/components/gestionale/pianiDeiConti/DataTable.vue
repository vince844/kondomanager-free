<script setup lang="ts" generic="TData, TValue">

import { ref } from 'vue';
import { useTabellaServer } from '@/composables/useTabellaServer';
import { router, usePage } from '@inertiajs/vue3';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { FlexRender, getCoreRowModel, useVueTable } from '@tanstack/vue-table';
import DataTablePagination from '@/components/DataTablePagination.vue';
import DataTableToolbar from '@/components/gestionale/pianiDeiConti/DataTableToolbar.vue';
import { usePermission } from "@/composables/permissions";
import TableEmptyState from '@/components/gestionale/TableEmptyState.vue';
import { ListTree } from 'lucide-vue-next';
import type { ColumnDef, SortingState } from '@tanstack/vue-table';
import type { PianoDeiConti } from '@/types/gestionale/piani-dei-conti';
import type { Building } from '@/types/buildings';

const props = defineProps<{
  columns: ColumnDef<PianoDeiConti, any>[],
  data: PianoDeiConti[],
  condominio: Building 
  meta: {
    current_page: number,
    per_page: number,
    last_page: number,
    total: number
  }
}>()

const { generateRoute } = usePermission();
const pagina = usePage<{ esercizio: { id: number } }>();

/**
 * ⚠️ **La rotta era `gestionale.conti.index`, che non esiste.** Ziggy lancia sui nomi che non
 * conosce, quindi ogni cambio di pagina e ogni cambio di righe su l'elenco dei piani dei conti moriva
 * in un errore JavaScript e la tabella non si muoveva. Il difetto è precedente alla beta.54 — la
 * riga vecchia aveva lo stesso nome — ed è emerso solo ora perché è la prima volta che qualcuno
 * verifica che quelle due tabelle paginino davvero.
 *
 * Serve anche l'esercizio: l'indice vive sotto `/{condominio}/esercizi/{esercizio}/…`, e con il
 * solo condominio Ziggy non saprebbe comporre l'indirizzo.
 */
const { inCorso, ordinamento, suPaginazione, suOrdinamento } = useTabellaServer(() =>
  route(generateRoute('gestionale.esercizi.piani-conti.index'), {
    condominio: props.condominio.id,
    esercizio: pagina.props.esercizio.id,
  }));

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
    :icon="ListTree"
    title="Nessun piano dei conti"
    description="Non è ancora stato creato nessun piano dei conti. È l'elenco dei capitoli di spesa su cui si costruiscono il preventivo e il piano rate."
    media-class="bg-amber-50/50 dark:bg-amber-900/20 text-amber-500"
  />
  <div v-if="table.getRowModel().rows?.length" class="flex items-center justify-end py-4 space-x-2">
    <DataTablePagination :table="table" :meta="props.meta" />
  </div>
  
</template>