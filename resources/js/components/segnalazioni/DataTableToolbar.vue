<script setup lang="ts">

import { ref, computed } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { router, Link } from '@inertiajs/vue3';
import { Input } from '@/components/ui/input';
import { BellPlus } from 'lucide-vue-next';
import DataTableFacetedFilter from '@/components/segnalazioni/DataTableFacetedFilter.vue';
import { priorityConstants, statoConstants } from '@/lib/segnalazioni/constants';
import { usePermission } from "@/composables/permissions";
import { Permission }  from "@/enums/Permission";
import type { Table } from '@tanstack/vue-table';
import type { Segnalazione } from '@/types/segnalazioni';

const { generateRoute, hasPermission } = usePermission();

// Change this to allow table reset when filter cleared
const { table } = defineProps<{
  table: Table<Segnalazione>
}>()

// Read current priority filter from column state
const priorityColumn = table.getColumn('priority')
const statoColumn = table.getColumn('stato')

const subjectFilter = ref('')

const priorityFilter = computed(() => {
  const val = priorityColumn?.getFilterValue()
  return Array.isArray(val) ? val : []
})

const statoFilter = computed(() => {
  const val = statoColumn?.getFilterValue()
  return Array.isArray(val) ? val : []
})

watchDebounced(
  [subjectFilter, priorityFilter, statoFilter],
  ([subject, priority, stato]) => {
    const params: Record<string, any> = { page: 1 }

    if (subject) params.subject = subject
    if (priority.length > 0) params.priority = priority
    if (stato.length > 0) params.stato = stato

    router.get(
      route('admin.segnalazioni.index'),
      params,
      {
        preserveState: true,
        replace: true,
        preserveScroll: true,
        onSuccess: () => {
          if (!subject && priority.length === 0 && stato.length === 0) {
            table.reset()
          }
        }
      }
    )
  },
  { debounce: 300 }
)

</script>

<template>
  <div class="flex flex-col gap-2 w-full mb-3 mt-4 lg:flex-row lg:items-center lg:justify-between">
    <!-- Input + Filters Container -->
    <div class="flex flex-col gap-2 lg:flex-row lg:items-center lg:gap-4">
      <!-- Search Input (Full width on mobile, inline on desktop) -->
      <Input
        placeholder="Filtra per titolo..."
        v-model="subjectFilter"
        class="h-8 w-full lg:w-[250px]"
      />

      <!-- Filters Stack (Mobile) / Row (Desktop) -->
      <div class="flex flex-col gap-2 lg:flex-row lg:items-center">
        <DataTableFacetedFilter
          v-if="priorityColumn"
          :column="priorityColumn"
          title="Priorità"
          :options="priorityConstants"
          :isLoading="false"
          @update:filter="() => {}"
          class="w-full lg:w-auto"
        />

        <DataTableFacetedFilter
          v-if="statoColumn"
          :column="statoColumn"
          title="Stato"
          :options="statoConstants"
          :isLoading="false"
          @update:filter="() => {}"
          class="w-full lg:w-auto"
        />
      </div>
    </div>

    <!-- Crea Button (Mobile: Below | Desktop: Far Right) -->
    <Link 
      as="button"
      v-if="hasPermission([Permission.CREATE_SEGNALAZIONI])"
      :href="route(generateRoute('segnalazioni.create'))" 
      class="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90 order-last lg:order-none lg:ml-auto"
    >
      <BellPlus class="w-4 h-4" />
      <span>Crea</span>
    </Link>

  </div>
</template>
