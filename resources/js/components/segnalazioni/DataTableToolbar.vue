<script setup lang="ts">
import { ref, computed } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { router, Link } from '@inertiajs/vue3';
import { Input } from '@/components/ui/input';
import { Plus, X } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import DataTableFacetedFilter from '@/components/segnalazioni/DataTableFacetedFilter.vue';
import { priorityConstants, statoConstants } from '@/lib/segnalazioni/constants';
import { usePermission } from "@/composables/permissions";
import { Permission }  from "@/enums/Permission";
import { trans } from 'laravel-vue-i18n';

// IMPORTAZIONE FILTRO CONDOMINI
import { useCondomini } from '@/composables/useCondomini';

import type { Table } from '@tanstack/vue-table';
import type { Segnalazione } from '@/types/segnalazioni';

const { generateRoute, hasPermission } = usePermission();

const { table } = defineProps<{
  table: Table<Segnalazione>
}>();

// Read current filters from column state
const priorityColumn = table.getColumn('priority');
const statoColumn = table.getColumn('stato');
const condominioColumn = table.getColumn('condominio'); // Assicurati che accessorKey sia 'condominio' in columns.ts

// LOGICA DROPDOWN CONDOMINI
const { condomini, isLoading: isLoadingCondomini, loadCondomini } = useCondomini();
const handleOpenCondomini = () => {
  loadCondomini();
};

const subjectFilter = ref('');

const priorityFilter = computed(() => {
  const val = priorityColumn?.getFilterValue();
  return Array.isArray(val) ? val : [];
});

const statoFilter = computed(() => {
  const val = statoColumn?.getFilterValue();
  return Array.isArray(val) ? val : [];
});

const condominioFilter = computed(() => {
  const val = condominioColumn?.getFilterValue();
  return Array.isArray(val) ? val : [];
});

// Watcher combinato per tutti i filtri (Testo, Priorità, Stato, Condominio)
watchDebounced(
  [subjectFilter, priorityFilter, statoFilter, condominioFilter],
  ([subject, priority, stato, condominio_id]) => {
    const params: Record<string, any> = { page: 1 };

    if (subject) params.subject = subject;
    if (priority.length > 0) params.priority = priority;
    if (stato.length > 0) params.stato = stato;
    if (condominio_id.length > 0) params.condominio_id = condominio_id;

    router.get(
      route(generateRoute('segnalazioni.index')),
      params,
      {
        preserveState: true,
        replace: true,
        preserveScroll: true,
        onSuccess: () => {
          if (!subject && priority.length === 0 && stato.length === 0 && condominio_id.length === 0) {
            table.reset();
          }
        }
      }
    );
  },
  { debounce: 300 }
);

const clearAllFilters = () => {
  subjectFilter.value = '';
  statoColumn?.setFilterValue(undefined);
  priorityColumn?.setFilterValue(undefined);
  condominioColumn?.setFilterValue(undefined);

  router.get(route(generateRoute('segnalazioni.index')), { page: 1 }, {
    preserveState: true,
    replace: true,
    preserveScroll: true,
  });
};
</script>

<template>
  <div class="flex flex-col gap-2 w-full mb-3 mt-4 lg:flex-row lg:items-center lg:justify-between">
    <div class="flex flex-col gap-2 lg:flex-row lg:items-center lg:gap-4">
      
      <Input
        :placeholder="trans('segnalazioni.table.filter_by_title')"
        v-model="subjectFilter"
        class="h-8 w-full lg:w-[250px]"
      />

      <div class="flex flex-col gap-2 lg:flex-row lg:items-center">
        
        <DataTableFacetedFilter
          v-if="priorityColumn"
          :column="priorityColumn"
          :title="trans('segnalazioni.table.priority')"
          :options="priorityConstants"
          :isLoading="false"
          @update:filter="() => {}"
          class="w-full lg:w-auto"
        />

        <DataTableFacetedFilter
          v-if="statoColumn"
          :column="statoColumn"
          :title="trans('segnalazioni.table.status')"
          :options="statoConstants"
          :isLoading="false"
          @update:filter="() => {}"
          class="w-full lg:w-auto"
        />

        <DataTableFacetedFilter
          v-if="condominioColumn"
          :column="condominioColumn"
          :title="trans('segnalazioni.table.buildings')"
          :options="condomini"
          :isLoading="isLoadingCondomini"
          @open="handleOpenCondomini"
          @update:filter="() => {}"
          class="w-full lg:w-auto"
        />

        <Button
          v-if="subjectFilter || priorityFilter.length || statoFilter.length || condominioFilter.length"
          variant="ghost"
          size="sm"
          @click="clearAllFilters"
          class="h-8 px-2 lg:px-3 text-slate-500 hover:bg-slate-100"
        >
          <X class="w-4 h-4 mr-2" />
          {{trans('segnalazioni.table.clear_all_filters')}}
        </Button>
      </div>
    </div>

    <Link 
      as="button"
      v-if="hasPermission([Permission.CREATE_SEGNALAZIONI])"
      :href="route(generateRoute('segnalazioni.create'))" 
      class="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90 order-last lg:order-none lg:ml-auto h-8"
    >
      <Plus class="w-4 h-4" />
      <span>{{ trans('segnalazioni.actions.new_ticket') }}</span>
    </Link>

  </div>
</template>
