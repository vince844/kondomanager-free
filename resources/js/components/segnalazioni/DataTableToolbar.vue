<script setup lang="ts">

import type { Table } from '@tanstack/vue-table'
import type { Segnalazione } from '@/types/segnalazioni'
import { Input } from '@/components/ui/input'
import { computed } from 'vue'
import { Button } from '@/components/ui/button'
import { Link } from '@inertiajs/vue3'
import { UserPlus} from 'lucide-vue-next';

interface DataTableToolbarProps {
  table: Table<Segnalazione>
}

const props = defineProps<DataTableToolbarProps>()

const isFiltered = computed(() => props.table.getState().columnFilters.length > 0)
</script>

<template>
  <div class="flex items-center justify-between w-full mb-3">
    <!-- Left Section: Input -->
    <div class="flex items-center space-x-2">
      <Input
        placeholder="Filtra per oggetto..."
        :model-value="(table.getColumn('subject')?.getFilterValue() as string) ?? ''"
        class="h-8 w-[150px] lg:w-[250px]"
        @input="table.getColumn('subject')?.setFilterValue($event.target.value)"
      />
    </div>

    <!-- Right Section: Button (force it to the right) -->
    <Button class="hidden h-8 lg:flex ml-auto">
      <UserPlus class="w-4 h-4" />
      <Link :href="route('admin.segnalazioni.create')">Nuova segnalazione</Link>
    </Button>
  </div>
</template>