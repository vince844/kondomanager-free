<script setup lang="ts">

import { ref } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { router, Link } from '@inertiajs/vue3';
import { Input } from '@/components/ui/input';
import { Plus } from 'lucide-vue-next';
import { usePermission } from "@/composables/permissions";
import { Permission } from "@/enums/Permission";
import { trans } from 'laravel-vue-i18n';
import type { Table } from '@tanstack/vue-table';
import type { Building } from '@/types/buildings';

interface DataTableToolbarProps {
  table: Table<Building>
}

const nameFilter = ref('')

const { generateRoute, hasPermission } = usePermission();

// Debounce search input (300ms delay)
watchDebounced(
  nameFilter,
  (newValue) => {
    // Reset filters if empty, otherwise filter
    router.get(
      route('condomini.index'),
      newValue
        ? { nome: newValue, page: 1 }
        : { page: 1 }, 
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
      class="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90 order-last lg:order-none lg:ml-auto"
    >
      <Plus class="w-4 h-4" />
      <span>{{ trans('condomini.actions.new_building') }}</span>
    </Link>
    
  </div>
</template>
