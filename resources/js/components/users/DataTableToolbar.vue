<script setup lang="ts">

import { ref } from 'vue'
import { watchDebounced } from '@vueuse/core'
import { router, Link } from '@inertiajs/vue3'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Settings, UserPlus } from 'lucide-vue-next';
import { usePermission } from "@/composables/permissions";
import { trans } from 'laravel-vue-i18n';
import type { Table } from '@tanstack/vue-table'
import type { User } from '@/types/users'

const { hasPermission } = usePermission();

interface DataTableToolbarProps {
  table: Table<User>
}

const nameFilter = ref('')

// Debounce search input (300ms delay)
watchDebounced(
  nameFilter,
  (newValue) => {
    // Reset filters if empty, otherwise filter
    router.get(
      route('utenti.index'),
      newValue
        ? { name: newValue, page: 1 }
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
  <div class="flex flex-col gap-3 w-full mb-3 lg:flex-row lg:items-center lg:justify-between">
    <!-- Left Section: Input -->
    <div class="flex items-center space-x-2">
      <Input
         :placeholder="trans('users.table.filter')"
        v-model="nameFilter"
        class="h-8 w-full sm:w-[200px] lg:w-[250px]"
      />
    </div>

    <!-- Right Section: Buttons -->
    <div class="flex flex-col gap-2 w-full sm:flex-row sm:justify-end">
      <Button 
        class="h-8 w-full sm:w-auto"
        v-if="hasPermission(['Crea utenti'])"
        as="a"
        :href="route('utenti.create')"
      >
        <UserPlus class="w-4 h-4"/>
        <span>{{ trans('users.actions.new_user') }}</span>
      </Button>

      <Link
        as="button"
        :href="'/impostazioni'"
        class="h-8 w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-white hover:bg-primary/90"
      >
        <Settings class="w-4 h-4" />
        <span>{{ trans('impostazioni.label.settings') }}</span>
      </Link>
    </div>
  </div>

</template>
