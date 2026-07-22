<script setup lang="ts">

import { ref, computed } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { router, Link } from '@inertiajs/vue3';
import { Input } from '@/components/ui/input';
import { Plus } from 'lucide-vue-next';
import { usePermission } from "@/composables/permissions";
import DataTableFacetedFilter from '@/components/comunicazioni/DataTableFacetedFilter.vue';
import { priorityConstants } from '@/lib/comunicazioni/constants';
import { trans } from 'laravel-vue-i18n';
import { Permission }  from "@/enums/Permission";
import type { Table } from '@tanstack/vue-table';
import type { Comunicazione } from '@/types/comunicazioni';

// IMPORTAZIONE FILTRO CONDOMINI
import { useCondomini } from '@/composables/useCondomini';

const { generateRoute, hasPermission } = usePermission();

const { table } = defineProps<{
  table: Table<Comunicazione>
}>()

// Filtro Priorità
const priorityColumn = table.getColumn('priority')
const priorityFilter = computed(() => {
  const val = priorityColumn?.getFilterValue()
  return Array.isArray(val) ? val : []
})

// LOGICA DROPDOWN CONDOMINI
const { condomini, isLoading: isLoadingCondomini, loadCondomini } = useCondomini()
const condominioColumn = table.getColumn('condomini') // Deve combaciare con l'accessorKey in columns.ts

const handleOpenCondomini = () => {
  loadCondomini()
}
const condominioFilter = computed(() => {
  const val = condominioColumn?.getFilterValue()
  return Array.isArray(val) ? val : []
})

// Filtro Testo
const nameFilter = ref('')

// DEBOUNCE AGGIORNATO (Ora osserva 3 filtri)
watchDebounced(
  [nameFilter, priorityFilter, condominioFilter],
  ([subject, priority, condominio_id]) => {
    const params: Record<string, any> = { page: 1 }

    if (subject) params.subject = subject
    if (priority.length > 0) params.priority = priority
    if (condominio_id.length > 0) params.condominio_id = condominio_id

    router.get(
      route(generateRoute('comunicazioni.index')),
      params,
      {
        preserveState: true,
        replace: true,
        preserveScroll: true,
        onSuccess: () => {
          if (!subject && priority.length === 0 && condominio_id.length === 0) {
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
  <div class="flex items-center justify-between w-full mb-3">
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

        <DataTableFacetedFilter
          v-if="condominioColumn"
          :column="condominioColumn"
          :title="trans('comunicazioni.table.buildings')"
          :options="condomini"
          :isLoading="isLoadingCondomini"
          @open="handleOpenCondomini"
          @update:filter="() => {}"
          class="w-full lg:w-auto"
        />
      </div>

    </div>

    <Link
      as="button"
      v-if="hasPermission([Permission.CREATE_COMUNICAZIONI])"
      :href="route(generateRoute('comunicazioni.create'))"
      class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-slate-900 dark:bg-slate-700 border border-slate-800 shadow-sm text-xs font-medium text-white hover:bg-slate-800 dark:hover:bg-slate-600 transition-colors"
    >
      <Plus class="w-3.5 h-3.5 text-green-500" />
      <span>{{ trans('comunicazioni.actions.new_communication') }}</span>
    </Link>

  </div>
</template>
