<script setup lang="ts">

import type { Table } from '@tanstack/vue-table'
import type { Anagrafica } from '@/types/anagrafiche'
import { Input } from '@/components/ui/input'
import { computed } from 'vue'

interface DataTableToolbarProps {
  table: Table<Anagrafica>
}

const props = defineProps<DataTableToolbarProps>()

const isFiltered = computed(() => props.table.getState().columnFilters.length > 0)
</script>

<template>
  <div class="flex items-center justify-between">
    <div class="flex flex-1 items-center space-x-2 mb-3">
      <Input
        placeholder="Filtra per nome..."
        :model-value="(table.getColumn('nome')?.getFilterValue() as string) ?? ''"
        class="h-8 w-[150px] lg:w-[250px]"
        @input="table.getColumn('nome')?.setFilterValue($event.target.value)"
      />
    </div>
  </div>
</template>