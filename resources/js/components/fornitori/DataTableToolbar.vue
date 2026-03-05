<script setup lang="ts">

import { ref } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { router, Link } from '@inertiajs/vue3';
import { Input } from '@/components/ui/input';
import { Plus } from 'lucide-vue-next';
import { usePermission } from "@/composables/permissions";
import { Permission }  from "@/enums/Permission";
import type { Table } from '@tanstack/vue-table';
import type { Fornitore } from '@/types/fornitori';

interface DataTableToolbarProps {
  table: Table<Fornitore>
}

const ragioneSocialeFilter = ref('')
const { hasPermission, generateRoute } = usePermission();

// Debounce search input (300ms delay)
watchDebounced(
  ragioneSocialeFilter,
  (newValue) => {
    // Reset filters if empty, otherwise filter
    router.get(
      route(generateRoute('fornitori.index')),
      newValue
        ? { ragione_sociale: newValue, page: 1 }
        : { page: 1 }, // Clear the filter
      {
        preserveState: true,
        replace: true,
      }
    )
  },
  { debounce: 300 }
)

</script>

<template>
  <div class="flex items-center justify-between w-full mb-3">
    <!-- Left Section: Input -->
    <div class="flex items-center space-x-2">
        <Input
          placeholder="Filtra per ragione sociale..."
          v-model="ragioneSocialeFilter"
          class="h-8 w-[150px] lg:w-[250px]"
        />
    </div>

    <!-- Right Section: Button (force it to the right) -->
    <Link 
      as="button"
      v-if="hasPermission([Permission.CREATE_USERS])"
      :href="route(generateRoute('fornitori.create'))" 
      class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-slate-900 dark:bg-slate-700 border border-slate-800 shadow-sm text-xs font-medium text-white hover:bg-slate-800 dark:hover:bg-slate-600 transition-colors"
    >
      <Plus class="w-3.5 h-3.5" />
      <span>Crea fornitori</span>
    </Link>

  </div>
</template>
