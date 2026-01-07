<script setup lang="ts">

import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3';
import { Send, Settings } from 'lucide-vue-next';
import type { Table } from '@tanstack/vue-table'
import type { Invito } from '@/types/inviti'

interface DataTableToolbarProps {
  table: Table<Invito>
}

const props = defineProps<DataTableToolbarProps>()

const isFiltered = computed(() => props.table.getState().columnFilters.length > 0)
</script>

<template>
  <div class="flex items-center justify-between w-full mb-3">
    <!-- Left Section: Input -->
    <div class="flex items-center space-x-2">
      <Input
        placeholder="Filtra per email..."
        :model-value="(table.getColumn('email')?.getFilterValue() as string) ?? ''"
        class="h-8 w-[150px] lg:w-[250px]"
        @input="table.getColumn('email')?.setFilterValue($event.target.value)"
      />
    </div>

    <div class="flex flex-col gap-2 w-full sm:flex-row sm:justify-end">
      <!-- Right Section: Button (force it to the right) -->
      <Button class="hidden h-8 lg:flex ml-auto">
        <Send class="w-4 h-4" />
        <Link :href="route('inviti.create')">Nuovo invito</Link>
      </Button>

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
