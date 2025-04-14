<script setup lang="ts">
import type { Table } from '@tanstack/vue-table'
import type { Building } from '@/types/buildings'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { computed } from 'vue'
import { HousePlus } from 'lucide-vue-next';
import { Link } from '@inertiajs/vue3'


interface DataTableToolbarProps {
  table: Table<Building>
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
        :model-value="(table.getColumn('nome')?.getFilterValue() as string) ?? ''"
        class="h-8 w-[150px] lg:w-[250px]"
        @input="table.getColumn('nome')?.setFilterValue($event.target.value)"
        id="filter" 
      />
    </div>

    <!-- Right Section: Button (force it to the right) -->
    <Button class="hidden h-8 lg:flex ml-auto">
      <HousePlus class="w-4 h-4" />
      <Link :href="route('condomini.create')">Crea</Link>
    </Button>
  </div>
</template>
