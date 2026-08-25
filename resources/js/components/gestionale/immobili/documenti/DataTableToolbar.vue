<script setup lang="ts">

import { ref } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { usePage} from '@inertiajs/vue3';
import { Input } from '@/components/ui/input';
import { usePermission } from "@/composables/permissions";
import { useTabellaServer } from '@/composables/useTabellaServer';
import type { Table } from '@tanstack/vue-table';
import type { Documento } from '@/types/documenti';
import type { Immobile } from '@/types/gestionale/immobili';
import type { Building } from '@/types/buildings';

const { generateRoute, hasPermission, generatePath } = usePermission();

// Change this to allow table reset when filter cleared
const { table } = defineProps<{
  table: Table<Documento>
}>()

// Page props
const page = usePage<{ condominio: Building; immobile: Immobile }>()

const nameFilter = ref('')

const { filtra } = useTabellaServer(() =>
  route(generateRoute('gestionale.immobili.documenti.index'), { condominio: page.props.condominio.id, immobile: page.props.immobile.id }),
)

watchDebounced(
  [nameFilter],
  ([name]) => {
    filtra(
      { name: name || null },
      () => {
        if (!name) {
          table.reset()
        }
      },
    )
  },
  { debounce: 300 }
)

</script>

<template>
<div class="flex items-center justify-between w-full mb-3">
  <!-- Left: Filters -->
  <div class="flex items-center space-x-2">
    <!-- Subject Filter -->
    <Input
      placeholder="Filtra per titolo..."
      v-model="nameFilter"
      class="h-8 w-[150px] lg:w-[250px]"
    />

  </div>

</div>

</template>
