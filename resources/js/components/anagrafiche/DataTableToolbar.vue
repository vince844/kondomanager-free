<script setup lang="ts">

import { ref } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { router, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { UserPlus } from 'lucide-vue-next';
import { usePermission } from "@/composables/permissions";
import type { Table } from '@tanstack/vue-table';
import type { Anagrafica } from '@/types/anagrafiche';

interface DataTableToolbarProps {
  table: Table<Anagrafica>
}

const nomeFilter = ref('')
const { hasPermission } = usePermission();

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
        <Input
          placeholder="Filtra per nome..."
          v-model="nomeFilter"
          class="h-8 w-[150px] lg:w-[250px]"
        />
    </div>

    <!-- Right Section: Button (force it to the right) -->
    <Link 
      as="button"
      v-if="hasPermission(['Crea utenti'])"
      :href="route('admin.anagrafiche.create')" 
      class="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90 order-last lg:order-none lg:ml-auto"
    >
      <UserPlus class="w-4 h-4" />
      <span>Crea</span>
    </Link>

  </div>
</template>
