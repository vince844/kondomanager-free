<script setup lang="ts">

import { ref } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { usePage, Link } from '@inertiajs/vue3';
import { Input } from '@/components/ui/input';
import { Plus } from 'lucide-vue-next';
import { usePermission } from '@/composables/permissions';
import { useTabellaServer } from '@/composables/useTabellaServer';
import type { Table } from '@tanstack/vue-table';
import type { Palazzina } from '@/types/gestionale/palazzine';
import type { Building } from '@/types/buildings';

// Props
const props = defineProps<{ table: Table<Palazzina> }>();

// Page props
const page = usePage<{ condominio: Building }>();

// Permissions / routes
const { generateRoute } = usePermission();

// Filters
const nameFilter = ref('')

// Server-side table wiring
const { filtra } = useTabellaServer(() =>
  route(generateRoute('gestionale.palazzine.index'), { condominio: page.props.condominio.id }),
)

// Watch filters with debounce
watchDebounced(
  [nameFilter],
  () => {
    filtra(
      {
        name: nameFilter.value || null,
      },
      () => {
        if (!nameFilter.value) props.table.reset()
      },
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
      :href="route(generateRoute('gestionale.palazzine.create'), { condominio: page.props.condominio.id })"
      class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-slate-900 dark:bg-slate-700 border border-slate-800 shadow-sm text-xs font-medium text-white hover:bg-slate-800 dark:hover:bg-slate-600 transition-colors"
      prefetch
    >
      <Plus class="w-3.5 h-3.5" />
      <span>Crea palazzina</span>
    </Link>

  </div>
</template>
