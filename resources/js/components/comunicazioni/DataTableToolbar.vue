<script setup lang="ts">

import { ref, computed } from 'vue'
import { watchDebounced } from '@vueuse/core'
import { router } from '@inertiajs/vue3'
import type { Table } from '@tanstack/vue-table'
import type { Comunicazione } from '@/types/comunicazioni'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { BellPlus } from 'lucide-vue-next'
import DataTableFacetedFilter from './DataTableFacetedFilter.vue'
import { priorityConstants } from '@/lib/comunicazioni/constants'
import { usePermission } from "@/composables/permissions";
import { useComunicazioni } from '@/composables/useComunicazioni';

const { hasPermission } = usePermission();
const { setComunicazioni, comunicazioni, meta } = useComunicazioni();

interface DataTableToolbarProps {
  table: Table<Comunicazione>
}

const props = defineProps<DataTableToolbarProps>()

const subjectFilter = ref('')

// ✅ Read current priority filter from column state
const priorityColumn = props.table.getColumn('priority')
const priorityFilter = computed(() => {
  const val = priorityColumn?.getFilterValue()
  return Array.isArray(val) ? val : []
})

// ✅ Watch both filters and send to backend
watchDebounced(
  [subjectFilter, priorityFilter],
  ([subject, priority]) => {
    const params: Record<string, any> = {
      page: 1, // Reset to page 1 when filters change
    }

    if (subject) params.subject = subject
    if (priority.length > 0) params.priority = priority

    // Fetch new data from the backend
    router.get(route('admin.comunicazioni.index'), params, {
      preserveState: true,
      replace: true,
      onSuccess: (page) => {
        // Update the `comunicazioni` and `meta` data after fetching from backend
        setComunicazioni(page.props.comunicazioni, page.props.meta);
      }
    });
  },
  { debounce: 300 }
)

</script>

<template>
  <div class="flex items-center justify-between w-full mb-3 mt-4">
    <div class="flex items-center space-x-2">
      <!-- Subject Filter -->
      <Input
        placeholder="Filtra per titolo..."
        v-model="subjectFilter"
        class="h-8 w-[150px] lg:w-[250px]"
      />

      <!-- Priority Filter -->
      <DataTableFacetedFilter
        v-if="priorityColumn"
        :column="priorityColumn"
        title="Priorità"
        :options="priorityConstants"
        :isLoading="false"
        @update:filter="() => {}" 
      />
    </div>

    <!-- Create Button -->
    <Button
      v-if="hasPermission(['Crea comunicazioni'])"
      as="a"
      :href="route('admin.comunicazioni.create')"
      class="hidden h-8 lg:flex ml-auto items-center gap-2"
    >
      <BellPlus class="w-4 h-4" />
      <span>Crea</span>
    </Button>
  </div>

</template>
