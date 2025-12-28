<script setup lang="ts">

import { ref, computed } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { router, usePage, Link } from '@inertiajs/vue3';
import { Input } from '@/components/ui/input';
import { Plus } from 'lucide-vue-next';
import { usePermission } from '@/composables/permissions';
import type { Table } from '@tanstack/vue-table';
import type { Immobile } from '@/types/gestionale/immobili';
import type { Building } from '@/types/buildings';

// Props
const props = defineProps<{ table: Table<Immobile> }>();

// Page props
const page = usePage<{ condominio: Building }>();

// Permissions / routes
const { generateRoute } = usePermission();

// Filters
const nameFilter = ref('')

// Computed params for router
const filterParams = computed(() => {
  const params: Record<string, any> = { page: 1 }
  if (nameFilter.value) params.nome = nameFilter.value
  return params
})

// Watch filters with debounce
watchDebounced(
  [nameFilter],
  () => {
    router.get(
      route(generateRoute('gestionale.immobili.index'), { condominio: page.props.condominio.id }),
      filterParams.value,
      {
        preserveState: true,
        replace: true,
        preserveScroll: true,
        onSuccess: () => {
          if (!nameFilter.value) props.table.reset()
        }
      }
    )
  },
  { debounce: 300 }
)
</script>

<template>
  <div class="flex items-center justify-between w-full mb-3">
    
    <div class="flex items-center space-x-2">
      <div class="flex items-center space-x-2">
        <Input
          placeholder="Filtra per nome..."
          v-model="nameFilter"
          class="h-8 w-[150px] lg:w-[250px]"
        />

      </div>
    </div>

    <Link
      :href="route(generateRoute('gestionale.immobili.create'), { condominio: page.props.condominio.id })"
      class="hidden h-8 lg:flex ml-auto items-center gap-2 rounded-md shadow px-3 bg-primary text-white hover:bg-primary/90 transition"
      prefetch
    >
      <Plus class="w-4 h-4" />
      <span>Crea</span>
    </Link>
    

  </div>
</template>
