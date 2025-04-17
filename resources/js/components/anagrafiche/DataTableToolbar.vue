<script setup lang="ts">

import { ref } from 'vue'
import { watchDebounced } from '@vueuse/core'
import { router } from '@inertiajs/vue3'
import type { Table } from '@tanstack/vue-table'
import type { Anagrafica } from '@/types/anagrafiche'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Link } from '@inertiajs/vue3';
import { UserPlus } from 'lucide-vue-next';

interface DataTableToolbarProps {
  table: Table<Anagrafica>
}

const nomeFilter = ref('')

// Debounce search input (300ms delay)
watchDebounced(
  nomeFilter,
  (newValue) => {
    // Reset filters if empty, otherwise filter
    router.get(
      route('admin.anagrafiche.index'),
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
          v-model="nomeFilter"
          class="h-8 w-[150px] lg:w-[250px]"
        />
      </div>
    </div>

    <!-- Right Section: Button (force it to the right) -->
    <Button class="hidden h-8 lg:flex ml-auto">
      <UserPlus class="w-4 h-4" />
      <Link :href="route('admin.anagrafiche.create')">Crea</Link>
    </Button>
  </div>
</template>
