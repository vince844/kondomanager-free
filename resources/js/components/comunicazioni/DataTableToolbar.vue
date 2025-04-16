<script setup lang="ts">

import type { Table } from '@tanstack/vue-table';
import type { Comunicazione } from '@/types/comunicazioni';
import { Input } from '@/components/ui/input';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { X, ListPlus } from 'lucide-vue-next';
import DataTableFacetedFilter from './DataTableFacetedFilter.vue'
import { priorityConstants } from '@/lib/comunicazioni/constants';
import { usePermission } from "@/composables/permissions";
import axios from 'axios';

const { hasPermission, hasRole } = usePermission();

interface DataTableToolbarProps {
  table: Table<Comunicazione>
}

const props = defineProps<DataTableToolbarProps>()

// Compute whether the table is filtered
const isFiltered = computed(() => props.table.getState().columnFilters.length > 0) 
const isLoading = ref(false)

// Compute the base URL for different roles (admin, user, manager, etc.)
const rolePrefix = computed(() => {
    if (hasRole(['amministratore'])) {
        return 'admin';
    } else {
        return 'user';
    }
});

const condomini = ref<{ label: string; value: string }[]>([])


const loadCondominiOptions = async () => {
  if (condomini.value.length) return
  isLoading.value = true
  console.log('isLoading after setting true:', isLoading.value);
  try {
    const { data } = await axios.get(route('condomini.options'))
    condomini.value = data
  } catch (error) {
    console.error('Failed to load condomini options', error)
  } finally {
    isLoading.value = false
    console.log('isLoading after setting false:', isLoading.value);
  }
}

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
        id="filter"
      />

      <DataTableFacetedFilter
        v-if="table.getColumn('priority')"
        :column="table.getColumn('priority')"
        title="Priorità"
        :options="priorityConstants"
        :isLoading="isLoading"
      />

      <DataTableFacetedFilter
        v-if="table.getColumn('condomini')"
        :column="table.getColumn('condomini')"
        title="Condominio"
        :options="condomini"
        @open="loadCondominiOptions"
        :isLoading="isLoading" 
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
    <Button
      v-if="hasPermission(['Crea comunicazioni'])"
      as="a"
      :href="route(`${rolePrefix}.comunicazioni.create`)"
      class="hidden h-8 lg:flex ml-auto items-center gap-2"
    >
      <ListPlus class="w-4 h-4" />
      <span>Crea</span>
    </Button>
  </div>
</template>