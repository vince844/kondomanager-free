<script setup lang="ts">

import { ref } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { router, Link } from '@inertiajs/vue3';
import { Input } from '@/components/ui/input';
import { Plus, List } from 'lucide-vue-next';
import { usePermission } from "@/composables/permissions";
import type { Table } from '@tanstack/vue-table';
import type { Categoria } from '@/types/categorie';

const { generateRoute } = usePermission();

// Change this to allow table reset when filter cleared
const { table } = defineProps<{
  table: Table<Categoria>
}>()

const nameFilter = ref('')


watchDebounced(
  [nameFilter],
  ([name]) => {
    const params: Record<string, any> = { page: 1 }

    if (name) params.name = name

    router.get(
      route(generateRoute('categorie.index')),
      params,
      {
        preserveState: true,
        replace: true,
        preserveScroll: true,
        onSuccess: () => {
          if (!name) {
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
  <div class="flex items-center justify-between w-full mb-3 mt-4">
    <div class="flex items-center space-x-2">
      <!-- Subject Filter -->
      <Input
        placeholder="Filtra per titolo..."
        v-model="nameFilter"
        class="h-8 w-[150px] lg:w-[250px]"
      />

    </div>
    
    <div class="flex items-center space-x-2">
      <Link 
        as="button"
        :href="route(generateRoute('categorie.create'))" 
        class="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90 order-last lg:order-none lg:ml-auto"
      >
        <Plus class="w-4 h-4" />
        <span>Crea</span>
      </Link>

      <Link 
        as="button"
        :href="route(generateRoute('documenti.index'))" 
        class="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90 order-last lg:order-none lg:ml-auto"
      >
        <List class="w-4 h-4" />
        <span>Documenti</span>
      </Link>
    </div>

  </div>

</template>
