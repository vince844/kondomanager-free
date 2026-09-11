<script setup lang="ts">
import { ref } from 'vue';
import { useTabellaServer } from '@/composables/useTabellaServer';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { FlexRender, getCoreRowModel, useVueTable } from '@tanstack/vue-table';
import { Empty, EmptyHeader, EmptyMedia, EmptyTitle, EmptyDescription } from '@/components/ui/empty';
import DataTablePagination from '@/components/DataTablePagination.vue';
import { usePermission } from "@/composables/permissions";
import { Landmark, ChevronRight } from 'lucide-vue-next';
import DataTableToolbar from './DataTableToolbar.vue';
import RigaEspansa from './RigaEspansa.vue';
import type { ColumnDef } from '@tanstack/vue-table';
import type { Building } from '@/types/buildings';
import type { Esercizio } from '@/types/gestionale/esercizi';
import type { RegistroRow } from './columns';

const props = defineProps<{
  columns: ColumnDef<RegistroRow>[],
  data: RegistroRow[],
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
// Nessun ordinamento a schermo: il registro è cronologico per legge, e il saldo
// progressivo perderebbe senso su un ordine diverso — vedi RegistroContabilitaService.
const { suPaginazione } = useTabellaServer(() =>
  route(generateRoute('gestionale.esercizi.registro-contabilita.index'), {
    condominio: props.condominio.id,
    esercizio: props.esercizio.id,
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

// Stesso idioma del Libro Giornale (vedi Datatable.vue delle scritture): un `<tr>` in più con
// `colspan`, non il modello di espansione di TanStack — qui il pannello non è una sotto-riga
// della libreria, è testo descrittivo che non serve al calcolo del saldo progressivo.
const expandedIds = ref<Set<number>>(new Set());
const isExpanded = (id: number) => expandedIds.value.has(id);
const toggleExpanded = (id: number) => {
  if (expandedIds.value.has(id)) {
    expandedIds.value.delete(id);
  } else {
    expandedIds.value.add(id);
  }
};
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center">
      <DataTableToolbar />
    </div>

    <!-- `overflow-x-auto` e non `overflow-hidden`: con nove colonne dichiarate a larghezza fissa
         (vedi columns.ts) uno schermo stretto taglierebbe le ultime invece di lasciarle
         raggiungere. Qui la tabella scorre dentro il proprio riquadro, la pagina no.

         `text-[13px]` è una deroga locale al 14px delle altre tabelle, e sta solo qui: nove
         colonne al corpo normale mandano a capo descrizione e controparte, e un registro che
         serve a essere consultato a colpo d'occhio non regge righe alte tre linee. Chiesto da
         Vincenzo guardando la pagina accanto al Libro Giornale, che di colonne ne ha meno. -->
    <div class="rounded-md border bg-white overflow-x-auto">
      <Table v-if="table.getRowModel().rows?.length > 0" class="table-fixed w-full min-w-[1050px] text-[13px]">
        <TableHeader>
          <TableRow v-for="headerGroup in table.getHeaderGroups()" :key="headerGroup.id" class="bg-gray-50/50">
            <!-- Colonna del chevron: fuori dal modello TanStack, stesso motivo del Libro
                 Giornale — è solo il segnale visivo che la riga si espande. -->
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
          <template v-for="row in table.getRowModel().rows" :key="row.id">
            <TableRow
              class="hover:bg-gray-50/50 transition-colors cursor-pointer"
              @click="toggleExpanded(row.original.id)"
            >
              <TableCell class="w-6 pl-2 pr-0 py-3">
                <!-- Un pulsante vero, non un'icona: la riga si apre col mouse cliccando ovunque,
                     ma da tastiera serve un elemento focalizzabile. Tolta la colonna azioni, non
                     ne restava nessuno — trovato dalla revisione della beta.24. `.stop` perché
                     il clic sul pulsante non deve risalire alla riga e richiudere il pannello. -->
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

      <Empty v-else class="py-12 bg-slate-50/50">
        <EmptyHeader class="max-w-4xl">
          <EmptyMedia variant="icon" class="bg-violet-50/50 dark:bg-violet-900/20 text-violet-500">
            <Landmark class="w-8 h-8" />
          </EmptyMedia>
          <EmptyTitle>Nessun movimento trovato</EmptyTitle>
          <EmptyDescription>
            Non ci sono entrate o uscite reali che corrispondono ai criteri. <br>
            Modifica i filtri di ricerca o cambia esercizio.
          </EmptyDescription>
        </EmptyHeader>
      </Empty>
    </div>

    <!-- Sempre visibile quando ci sono righe, non solo con due pagine: qui dentro vive anche il
         selettore «righe per pagina», e su un registro che si consulta a colpo d'occhio quello
         serve pure con una pagina sola. Stessa convenzione di Esercizi, Piani rate e Tabelle. -->
    <div v-if="table.getRowModel().rows?.length > 0" class="flex items-center justify-end">
      <DataTablePagination :table="table" :meta="props.meta" />
    </div>
  </div>
</template>
