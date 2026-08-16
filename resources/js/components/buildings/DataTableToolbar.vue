<script setup lang="ts">

import { ref } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { Link } from '@inertiajs/vue3';
import { Input } from '@/components/ui/input';
import { Plus } from 'lucide-vue-next';
import { usePermission } from "@/composables/permissions";
import { useTabellaServer } from '@/composables/useTabellaServer';
import { Permission } from "@/enums/Permission";
import { trans } from 'laravel-vue-i18n';
import type { Table } from '@tanstack/vue-table';
import type { Building } from '@/types/buildings';

interface DataTableToolbarProps {
  table: Table<Building>
}

defineProps<DataTableToolbarProps>();

const nameFilter = ref('')

const { hasPermission } = usePermission();

// Una sola richiesta che porta tutto: filtri, pagina, righe per pagina, ordinamento
const { filtra } = useTabellaServer(() => route('condomini.index'))

// Debounce search input (300ms delay)
watchDebounced(
  nameFilter,
  (newValue) => {
    // Reset filters if empty, otherwise filter
    filtra({
      nome: newValue || null,
    })
  },
  { debounce: 300 }
)

</script>

<template>
  <div class="flex items-center justify-between w-full mb-3">
    <!-- Left Section: Input -->
    <div class="flex items-center space-x-2">
      <div class="flex items-center space-x-2">
        <Input
          :placeholder="trans('condomini.table.filter_by_name')"
          v-model="nameFilter"
          class="h-8 w-[150px] lg:w-[250px]"
        />
      </div>
    </div>

    <Link
      as="button"
      v-if="hasPermission([Permission.CREATE_CONDOMINI])"
      :href="route('condomini.create')"
      class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-slate-900 dark:bg-slate-700 border border-slate-800 shadow-sm text-xs font-medium text-white hover:bg-slate-800 dark:hover:bg-slate-600 transition-colors"
    >
      <Plus class="w-3.5 h-3.5 text-green-500" />
      <span>{{ trans('condomini.actions.new_building') }}</span>
    </Link>

  </div>
</template>
