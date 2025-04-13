<script setup lang="ts">
import type { Table } from '@tanstack/vue-table'
import type { Segnalazione } from '@/types/segnalazioni'
import { Input } from '@/components/ui/input'
import { computed } from 'vue'
import { Button } from '@/components/ui/button'
import { Link } from '@inertiajs/vue3'
import { BellPlus, X } from 'lucide-vue-next';
import DataTableFacetedFilter from './DataTableFacetedFilter.vue'
import { priorityConstants, statoConstants } from '@/lib/segnalazioni/constants';
import { usePermission } from "@/composables/permissions";

const { hasPermission } = usePermission();

interface DataTableToolbarProps {
  table: Table<Segnalazione>
  condominioOptions: { label: string; value: string }[]; 
}

const props = defineProps<DataTableToolbarProps>()

// Compute whether the table is filtered
const isFiltered = computed(() => props.table.getState().columnFilters.length > 0) 

</script>

<template>
  <div class="flex items-center justify-between w-full mb-3 mt-4">
    <!-- Left Section: Input and Filters -->
    <div class="flex items-center space-x-2">
      <Input
        placeholder="Filtra per titolo..."
        :model-value="(table.getColumn('subject')?.getFilterValue() as string) ?? ''"
        class="h-8 w-[150px] lg:w-[250px]"
        @input="table.getColumn('subject')?.setFilterValue($event.target.value)"
      />

      <DataTableFacetedFilter
        v-if="table.getColumn('stato')"
        :column="table.getColumn('stato')"
        title="Stato"
        :options="statoConstants"
      />

      <DataTableFacetedFilter
        v-if="table.getColumn('priority')"
        :column="table.getColumn('priority')"
        title="Priorità"
        :options="priorityConstants"
      />

       <!-- Add Condominio Filter -->
       <DataTableFacetedFilter
        v-if="table.getColumn('condominio')"
        :column="table.getColumn('condominio')"
        title="Condominio"
        :options="props.condominioOptions"  
      />
  
      <Button
        v-if="isFiltered"
        variant="ghost"
        class="h-8 px-2 lg:px-3"
        @click="table.resetColumnFilters()"
      >
        Resetta filtri
        <X class="ml-2 h-4 w-4" />
      </Button>
    </div>

    <!-- Right Section: Button -->
    <Button v-if="hasPermission(['Crea segnalazioni'])" class="hidden h-8 lg:flex ml-auto">
      <BellPlus class="w-4 h-4" />
      <Link :href="route('admin.segnalazioni.create')">Crea</Link>
    </Button>
  </div>
</template>