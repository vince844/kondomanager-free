<script setup lang="ts" generic="TData, TValue">

import { ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { FlexRender, getCoreRowModel, useVueTable, getSortedRowModel } from '@tanstack/vue-table';
import { valueUpdater } from '@/lib/utils';
import DataTablePagination from '@/components/DataTablePagination.vue';
import DataTableToolbar from '@/components/gestionale/immobili/DataTableToolbar.vue';
import { usePermission } from "@/composables/permissions";
import TableEmptyState from '@/components/gestionale/TableEmptyState.vue';
import { Building2 } from 'lucide-vue-next';
import type { ColumnDef, SortingState } from '@tanstack/vue-table';
import type { Immobile } from '@/types/gestionale/immobili';
import type { Building } from '@/types/buildings';

const props = defineProps<{
  columns: ColumnDef<Immobile, any>[],
  data: Immobile[],
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

    // ⚠️ **I filtri viaggiano con la pagina.** Senza, cambiare pagina li perdeva: si filtrava
    // «da collegare», si andava alla pagina 2 e tornava l'elenco intero mentre il selettore
    // continuava a dichiarare il filtro attivo. È la forma peggiore di un difetto di filtro —
    // non dice «non ho trovato niente», dice il falso — e su un condominio da 67 unità, dove
    // quel filtro è l'unico modo per vedere cosa manca, arriva sempre alla seconda pagina.
    //
    // La fonte è la stessa che riempie il selettore: i filtri validati che il controller
    // rimanda indietro, non uno stato locale da tenere allineato a mano.
    const filtriAttivi = (usePage<{ filters?: Record<string, string | null> }>().props.filters ?? {});

    router.get(route(generateRoute('gestionale.immobili.index'), { condominio: props.condominio.id}), {
      ...Object.fromEntries(Object.entries(filtriAttivi).filter(([, v]) => v !== null && v !== '')),
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
    :icon="Building2"
    title="Nessuna unità immobiliare"
    description="Questo condominio non ha ancora unità immobiliari. Inizia aggiungendo il primo appartamento, box o negozio: da qui passano i millesimi, i saldi e il riparto delle spese."
    media-class="bg-sky-50/50 dark:bg-sky-900/20 text-sky-500"
  />
  <div v-if="table.getRowModel().rows?.length" class="flex items-center justify-end py-4 space-x-2">
    <DataTablePagination :table="table" :meta="props.meta" />
  </div>
  
</template>