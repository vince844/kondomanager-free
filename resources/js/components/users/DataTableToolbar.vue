<script setup lang="ts">

import { ref } from 'vue'
import { watchDebounced } from '@vueuse/core'
import { Link } from '@inertiajs/vue3'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Settings, UserPlus } from 'lucide-vue-next';
import { usePermission } from "@/composables/permissions";
import { useTabellaServer } from '@/composables/useTabellaServer';
import { trans } from 'laravel-vue-i18n';
import type { Table } from '@tanstack/vue-table'
import type { User } from '@/types/users'

const { hasPermission } = usePermission();

interface DataTableToolbarProps {
  table: Table<User>
}

const nameFilter = ref('')

const { filtra } = useTabellaServer(() => route('utenti.index'));

// Debounce search input (300ms delay)
watchDebounced(
  nameFilter,
  (newValue) => {
    // Il filtro svuotato va passato come `null`, non omesso: la richiesta riparte da ciò che c'è
    // nell'URL, e un filtro omesso resterebbe quello di prima.
    filtra({ name: newValue || null })
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
        class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-slate-900 dark:bg-slate-700 border border-slate-800 shadow-sm text-xs font-medium text-white hover:bg-slate-800 dark:hover:bg-slate-600 transition-colors"
        v-if="hasPermission(['Crea utenti'])"
        as="a"
        :href="route('utenti.create')"
      >
        <UserPlus class="w-3.5 h-3.5"/>
        <span>{{ trans('users.actions.new_user') }}</span>
      </Button>
    </div>
  </div>

</template>
