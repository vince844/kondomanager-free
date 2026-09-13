<script setup lang="ts">
import { ref } from 'vue';
import { useTabellaServer } from '@/composables/useTabellaServer';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { FlexRender, getCoreRowModel, useVueTable } from '@tanstack/vue-table';
import { Empty, EmptyHeader, EmptyMedia, EmptyTitle, EmptyDescription } from '@/components/ui/empty';
import DataTablePagination from '@/components/DataTablePagination.vue';
import { usePermission } from "@/composables/permissions";
import { BookOpen, ChevronRight } from 'lucide-vue-next';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import DataTableToolbar from './DataTableToolbar.vue';
import RigaEspansa from './RigaEspansa.vue';
import type { ColumnDef } from '@tanstack/vue-table';
import type { Building } from '@/types/buildings';
import type { Esercizio } from '@/types/gestionale/esercizi';
import type { MastrinoRow } from './columns';

const props = defineProps<{
  columns: ColumnDef<MastrinoRow>[],
  data: MastrinoRow[],
  condominio: Building,
  esercizio: Esercizio,
  contoId: number,
  /** In centesimi: il saldo del conto il giorno prima del periodo (D21.2). */
  riporto: number,
  giornoPrima: string,
  /** Righe del periodo intero, prima dei filtri: distingue «periodo vuoto» da «filtro senza esito». */
  totaleRighe: number,
  periodo: { dal: string; al: string; stato: string },
  meta: {
    current_page: number,
    per_page: number,
    last_page: number,
    total: number
  }
}>()

const { generateRoute } = usePermission();
const { euro } = useCurrencyFormatter();
// Nessun ordinamento a schermo: un mastrino è cronologico, e il saldo progressivo
// perderebbe senso su un ordine diverso.
const { suPaginazione } = useTabellaServer(() =>
  route(generateRoute('gestionale.esercizi.conti.movimenti'), {
    condominio: props.condominio.id,
    esercizio: props.esercizio.id,
    contoContabile: props.contoId,
  }));

const table = useVueTable({
  get data() { return props.data ?? [] },
  get columns() { return props.columns ?? [] },
  pageCount: props.meta.last_page,
  state: {
    pagination: {
      pageIndex: props.meta.current_page - 1,
      pageSize: props.meta.per_page,
    },
  },
  manualPagination: true,
  onPaginationChange: updater => {
    const stato = table.getState().pagination
    const p = typeof updater === 'function' ? updater(stato) : updater
    suPaginazione(p.pageIndex + 1, p.pageSize, stato.pageSize)
  },
  getCoreRowModel: getCoreRowModel(),
})

const expandedIds = ref<Set<number>>(new Set());
const isExpanded = (id: number) => expandedIds.value.has(id);
const toggleExpanded = (id: number) => {
  if (expandedIds.value.has(id)) {
    expandedIds.value.delete(id);
  } else {
    expandedIds.value.add(id);
  }
};

const formatData = (iso: string) => {
  const [anno, mese, giorno] = iso.split('-');
  return `${giorno}/${mese}/${anno}`;
};
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center">
      <DataTableToolbar />
    </div>

    <div class="rounded-md border bg-white overflow-x-auto">
      <Table class="table-fixed w-full min-w-[1000px] text-[13px]">
        <TableHeader>
          <TableRow v-for="headerGroup in table.getHeaderGroups()" :key="headerGroup.id" class="bg-gray-50/50">
            <TableHead class="w-6 pl-2 pr-0" />
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
          <!-- La riga di riporto sta in testa alla PRIMA pagina soltanto: è il punto da cui il
               saldo parte, non un movimento, e non ha numero. Sulle pagine successive il saldo
               della prima riga mostrata porta già dentro tutto ciò che precede. -->
          <TableRow v-if="meta.current_page === 1" class="bg-slate-50/70 hover:bg-slate-50/70 italic text-slate-500">
            <TableCell class="w-6 pl-2 pr-0 py-2.5" />
            <TableCell class="px-4 py-2.5" />
            <TableCell class="px-4 py-2.5 tabular-nums">{{ formatData(giornoPrima) }}</TableCell>
            <TableCell class="px-4 py-2.5" colspan="4">{{ periodo.stato === 'futuro' ? 'Riporto — saldo del conto a oggi: l\'esercizio non è ancora cominciato' : 'Riporto — saldo del conto all\'inizio del periodo' }}</TableCell>
            <TableCell class="px-4 py-2.5 text-right tabular-nums font-bold not-italic" :class="riporto < 0 ? 'text-rose-600' : 'text-slate-700'">{{ euro(riporto) }}</TableCell>
          </TableRow>
          <template v-for="row in table.getRowModel().rows" :key="row.id">
            <TableRow
              class="hover:bg-gray-50/50 transition-colors cursor-pointer"
              @click="toggleExpanded(row.original.id)"
            >
              <TableCell class="w-6 pl-2 pr-0 py-3">
                <button
                  type="button"
                  class="inline-flex items-center justify-center rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                  :aria-expanded="isExpanded(row.original.id)"
                  :aria-label="isExpanded(row.original.id) ? 'Chiudi il dettaglio' : 'Apri il dettaglio'"
                  @click.stop="toggleExpanded(row.original.id)"
                >
                  <ChevronRight
                    class="w-4 h-4 text-slate-400 transition-transform"
                    :class="{ 'rotate-90': isExpanded(row.original.id) }"
                  />
                </button>
              </TableCell>
              <TableCell
                v-for="cell in row.getVisibleCells()"
                :key="cell.id"
                class="px-4 py-3"
                :style="{ width: cell.column.getSize() + 'px' }"
              >
                <FlexRender :render="cell.column.columnDef.cell" :props="cell.getContext()" />
              </TableCell>
            </TableRow>
            <TableRow v-if="isExpanded(row.original.id)" class="bg-slate-50/40 hover:bg-slate-50/40">
              <TableCell :colspan="row.getVisibleCells().length + 1" class="px-4 py-3">
                <RigaEspansa :riga="row.original" />
              </TableCell>
            </TableRow>
          </template>
        </TableBody>
      </Table>

      <Empty v-if="table.getRowModel().rows?.length === 0" class="py-10 bg-slate-50/50 border-t">
        <EmptyHeader class="max-w-4xl">
          <EmptyMedia variant="icon" class="bg-violet-50/50 dark:bg-violet-900/20 text-violet-500">
            <BookOpen class="w-8 h-8" />
          </EmptyMedia>
          <EmptyTitle>{{ totaleRighe > 0 ? 'Nessuna riga corrisponde ai filtri' : 'Nessun movimento su questo conto' }}</EmptyTitle>
          <!-- Due stati diversi, due frasi: con un filtro senza esito il saldo del conto NON è il
               riporto — è quello dichiarato in testa, alla sua data — e ripeterlo qui con un altro
               numero metteva due saldi sullo stesso schermo. Revisione della beta.26. -->
          <EmptyDescription>
            <template v-if="totaleRighe > 0">
              Nel periodo questo conto ha {{ totaleRighe }} {{ totaleRighe === 1 ? 'movimento' : 'movimenti' }}: nessuno passa i filtri scelti. Il saldo dichiarato in testa resta quello vero alla sua data. <br>
              Modifica i filtri o azzerali.
            </template>
            <template v-else>
              Nel periodo non c'è nessuna riga: il saldo resta quello del riporto. <br>
              Scegli un altro conto o cambia esercizio.
            </template>
          </EmptyDescription>
        </EmptyHeader>
      </Empty>
    </div>

    <div v-if="table.getRowModel().rows?.length > 0" class="flex items-center justify-end">
      <DataTablePagination :table="table" :meta="props.meta" />
    </div>
  </div>
</template>
