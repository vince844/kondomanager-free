<script setup lang="ts" generic="TData, TValue">

import { ref } from 'vue';
import { useTabellaServer } from '@/composables/useTabellaServer';
import { router, usePage } from '@inertiajs/vue3';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { FlexRender, getCoreRowModel, useVueTable } from '@tanstack/vue-table';
import DataTablePagination from '@/components/DataTablePagination.vue';
import DataTableToolbar from '@/components/gestionale/gestioni/DataTableToolbar.vue';
import { usePermission } from "@/composables/permissions";
import TableEmptyState from '@/components/gestionale/TableEmptyState.vue';
import { Layers } from 'lucide-vue-next';
import type { ColumnDef, SortingState } from '@tanstack/vue-table';
import type { Gestione } from '@/types/gestionale/gestioni';
import type { Building } from '@/types/buildings';

const props = defineProps<{
  columns: ColumnDef<Gestione, any>[],
  data: Gestione[],
  condominio: Building 
  meta: {
    current_page: number,
    per_page: number,
    last_page: number,
    total: number
  }
}>()

const { generateRoute } = usePermission();
const page = usePage<{ esercizio: { id: number } }>();

/*
 * ⚠️ **`gestionale.esercizi.gestioni.index`, non `gestionale.gestioni.index`.**
 *
 * Il secondo nome non è mai esistito: le gestioni stanno **annidate sotto l'esercizio**, e le
 * uniche sette rotte registrate lo dicono. Siccome `route()` sta dentro una funzione — viene
 * chiamata quando si cambia pagina o si ordina, non al montaggio — la tabella si disegnava senza
 * un lamento e moriva con un errore JavaScript al primo clic sulla paginazione.
 *
 * È la terza volta che questo progetto trova una tabella che «non paginava affatto»: le altre due
 * — piani rate e piani dei conti — sono state corrette nella beta.54, e la lezione scritta allora
 * dice che *una funzione che nessuno ha mai esercitato non è funzionante finché non si dimostra
 * il contrario*. Da questa beta la classe ha la sua guardia:
 * `tests/Feature/System/NomiDiRottaCheNonEsistonoTest.php`.
 *
 * L'esercizio si legge da `usePage()` come fa già la barra dei filtri accanto
 * (`DataTableToolbar.vue:29`): la prop non c'è, e aggiungerla vorrebbe dire cambiare anche la
 * pagina che monta la tabella per un dato che è già a portata di mano.
 */
const { inCorso, ordinamento, suPaginazione, suOrdinamento } =
  useTabellaServer(() => route(generateRoute('gestionale.esercizi.gestioni.index'), {
    condominio: props.condominio.id,
    esercizio: page.props.esercizio.id,
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
    :icon="Layers"
    title="Nessuna gestione"
    description="Non è ancora stata creata nessuna gestione per questo esercizio. Le gestioni separano ordinaria e straordinaria, come richiesto dall'art. 1130-bis c.c."
    media-class="bg-emerald-50/50 dark:bg-emerald-900/20 text-emerald-500"
  />
  <div v-if="table.getRowModel().rows?.length" class="flex items-center justify-end py-4 space-x-2">
    <DataTablePagination :table="table" :meta="props.meta" />
  </div>
  
</template>