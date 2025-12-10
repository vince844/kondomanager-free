<script setup lang="ts">

import { ref, computed } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { router, usePage, Link } from '@inertiajs/vue3';
import { Input } from '@/components/ui/input';
import { Plus, List } from 'lucide-vue-next';
import { usePermission } from '@/composables/permissions';
import type { Table } from '@tanstack/vue-table';
import type { Immobile } from '@/types/gestionale/immobili';
import type { Building } from '@/types/buildings';
import type { AnagraficaWithPivot } from '@/types/anagrafiche'

// Props
const props = defineProps<{ table: Table<AnagraficaWithPivot> }>();

// Page props
const page = usePage<{ condominio: Building; immobile: Immobile }>()

// Permissions / routes
const { generateRoute, generatePath } = usePermission();

</script>

<template>
  <div class="flex items-center justify-between w-full mb-3 space-x-2">

    <Link
      :href="route(generateRoute('gestionale.immobili.anagrafiche.create'), { condominio: page.props.condominio.id, immobile: page.props.immobile.id })"
      class="hidden h-8 lg:flex ml-auto items-center gap-2 rounded-md shadow px-3 bg-primary text-white hover:bg-primary/90 transition"
      prefetch
    >
      <Plus class="w-4 h-4" />
      <span>Associa</span>
    </Link> 

    <Link
      as="button"
      :href="generatePath('gestionale/:condominio/immobili', { condominio: page.props.condominio.id })"
      class="w-full lg:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90"
    >
      <List class="w-4 h-4" />
      <span>Immobili</span>
    </Link>
   
  </div>
</template>
