<script setup lang="ts">

import { ref } from 'vue'
import { watchDebounced } from '@vueuse/core'
import { router } from '@inertiajs/vue3'
import type { Table } from '@tanstack/vue-table'
import type { User } from '@/types/users'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Link } from '@inertiajs/vue3';
import { UserPlus } from 'lucide-vue-next';
import { usePermission } from "@/composables/permissions";

const { hasPermission, hasRole } = usePermission();

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
  <div class="flex items-center justify-between w-full mb-3">
    <!-- Left Section: Input -->
    <div class="flex items-center space-x-2">
      <div class="flex items-center space-x-2">
        <Input
          placeholder="Filtra per nome..."
          v-model="nameFilter"
          class="h-8 w-[150px] lg:w-[250px]"
        />
      </div>
    </div>

    <Button
      v-if="hasPermission(['Crea utenti'])"
      as="a"
      :href="route('utenti.create')"
      class="hidden h-8 lg:flex ml-auto items-center gap-2"
    >
      <UserPlus class="w-4 h-4" />
      <span>Crea</span>
    </Button>

  </div>
</template>
