<script setup lang="ts">

import { ref, computed } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Plus } from 'lucide-vue-next';
import { usePermission } from "@/composables/permissions";
import DataTableFacetedFilter from '@/components/comunicazioni/DataTableFacetedFilter.vue';
import { priorityConstants } from '@/lib/comunicazioni/constants';
import { trans } from 'laravel-vue-i18n';
import { Permission }  from "@/enums/Permission";
import type { Table } from '@tanstack/vue-table';
import type { Comunicazione } from '@/types/comunicazioni';

const { generateRoute, hasPermission } = usePermission();

const { table } = defineProps<{
  table: Table<Comunicazione>
}>()

const priorityColumn = table.getColumn('priority')
const nameFilter = ref('')

const priorityFilter = computed(() => {
  const val = priorityColumn?.getFilterValue()
  return Array.isArray(val) ? val : []
})

watchDebounced(
  [nameFilter, priorityFilter],
  ([subject, priority]) => {
    const params: Record<string, any> = { page: 1 }

    if (subject) params.subject = subject
    if (priority.length > 0) params.priority = priority

    router.get(
      route(generateRoute('comunicazioni.index')),
      params,
      {
        preserveState: true,
        replace: true,
        preserveScroll: true,
        onSuccess: () => {
          if (!subject && priority.length === 0) {
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
  <div class="flex items-center justify-between w-full mb-3 mt-4">
    <!-- Left Section: Input -->
    <div class="flex items-center space-x-2">

      <Input
        :placeholder="trans('comunicazioni.table.filter_by_title')"
        v-model="nameFilter"
        class="h-8 w-[150px] lg:w-[250px]"
      />

      <div class="flex flex-col gap-2 lg:flex-row lg:items-center">
        <DataTableFacetedFilter
          v-if="priorityColumn"
          :column="priorityColumn"
          :title="trans('comunicazioni.table.priority')"
          :options="priorityConstants"
          :isLoading="false"
          @update:filter="() => {}"
          class="w-full lg:w-auto"
        />

      </div>
        
    </div>

    <Button
      v-if="hasPermission([Permission.CREATE_COMUNICAZIONI])"
      as="a"
      :href="route(generateRoute('comunicazioni.create'))"
      class="hidden h-8 lg:flex ml-auto items-center gap-2"
    >
      <Plus class="w-4 h-4" />
      <span>{{ trans('comunicazioni.actions.new_communication') }}</span>
    </Button>

  </div>
</template>
