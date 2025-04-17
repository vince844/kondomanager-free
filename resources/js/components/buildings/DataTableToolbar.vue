<script setup lang="ts">

import { ref } from 'vue'
import { watchDebounced } from '@vueuse/core'
import { router } from '@inertiajs/vue3'
import type { Table } from '@tanstack/vue-table'
import type { Building } from '@/types/buildings'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Link } from '@inertiajs/vue3';
import { HousePlus } from 'lucide-vue-next';

interface DataTableToolbarProps {
  table: Table<Building>
}

const nameFilter = ref('')

// Debounce search input (300ms delay)
watchDebounced(
  nameFilter,
  (newValue) => {
    // Reset filters if empty, otherwise filter
    router.get(
      route('condomini.index'),
      newValue
        ? { nome: newValue, page: 1 }
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

    <!-- Right Section: Button (force it to the right) -->
    <Button class="hidden h-8 lg:flex ml-auto">
      <HousePlus class="w-4 h-4" />
      <Link :href="route('condomini.create')">Crea</Link>
    </Button>
  </div>
</template>
