<script setup lang="ts">

import { Input } from '@/components/ui/input'
import { computed } from 'vue'
import { Settings } from 'lucide-vue-next';
import { Link } from '@inertiajs/vue3'
import type { Table } from '@tanstack/vue-table'
import type { Permission } from '@/types/permissions'

interface DataTableToolbarProps {
  table: Table<Permission>
}

const props = defineProps<DataTableToolbarProps>()

const isFiltered = computed(() => props.table.getState().columnFilters.length > 0)
</script>

<template>
  <div class="flex items-center justify-between w-full mb-3">
    <!-- Left Section: Input -->
    <div class="flex items-center space-x-2">
      <Input
        placeholder="Filtra per nome..."
        :model-value="(table.getColumn('name')?.getFilterValue() as string) ?? ''"
        class="h-8 w-[150px] lg:w-[250px]"
        @input="table.getColumn('name')?.setFilterValue($event.target.value)"
      />
    </div>

    <div class="flex flex-col gap-2 w-full sm:flex-row sm:justify-end">
      <Link
          as="button"
          :href="'/impostazioni'"
          class="h-8 w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-white hover:bg-primary/90"
        >
          <Settings class="w-4 h-4" />
          <span>Impostazioni</span>
      </Link>
    </div>

  </div>
</template>
