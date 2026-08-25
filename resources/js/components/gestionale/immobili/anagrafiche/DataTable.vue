<script setup lang="ts" generic="TData, TValue">

import { FlexRender, getCoreRowModel, useVueTable } from '@tanstack/vue-table'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import TableEmptyState from '@/components/gestionale/TableEmptyState.vue';
import { Contact } from 'lucide-vue-next';
import type { AnagraficaWithPivot } from '@/types/anagrafiche'
import type { ColumnDef } from '@tanstack/vue-table'

const props = defineProps<{
  columns: ColumnDef<AnagraficaWithPivot, any>[],
  data: AnagraficaWithPivot[],
}>()

const table = useVueTable({
  get data() { return props.data },
  get columns() { return props.columns },
  getCoreRowModel: getCoreRowModel(),
})
</script>

<template>

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
    :icon="Contact"
    title="Nessun soggetto associato"
    description="A questa unità non è ancora associato nessun proprietario o inquilino. Finché resta senza soggetti, le sue quote di spesa non possono essere intestate a nessuno."
    media-class="bg-indigo-50/50 dark:bg-indigo-900/20 text-indigo-500"
  />
</template>