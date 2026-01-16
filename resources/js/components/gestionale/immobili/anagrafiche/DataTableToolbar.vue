<script setup lang="ts">

import { usePage, Link } from '@inertiajs/vue3';
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
  <div class="flex flex-col lg:flex-row items-start lg:items-center justify-between w-full mb-4 gap-3">

    <div class="flex flex-wrap gap-3 text-xs text-gray-500 items-center bg-gray-50 px-3 rounded-lg border border-dashed border-gray-200 shadow-sm w-full lg:w-auto justify-center lg:justify-start h-auto py-2 lg:h-9 lg:py-0">
        <span class="font-bold uppercase tracking-wider text-[10px] text-gray-400 mr-1">Legenda:</span>
        
        <div class="flex items-center gap-1.5">
            <div class="w-2.5 h-2.5 rounded-full bg-red-500 shadow-sm ring-1 ring-black/5"></div>
            <span class="text-red-600 font-medium">Debito</span>
        </div>
        
        <div class="flex items-center gap-1.5">
            <div class="w-2.5 h-2.5 rounded-full bg-blue-500 shadow-sm ring-1 ring-black/5"></div>
            <span class="text-blue-600 font-medium">Credito</span>
        </div>

        <div class="flex items-center gap-1.5">
            <div class="w-2.5 h-2.5 rounded-full bg-emerald-500 shadow-sm ring-1 ring-black/5"></div>
            <span class="text-emerald-600 font-medium">Saldato</span>
        </div>
    </div>

    <div class="flex items-center gap-2 w-full lg:w-auto justify-end">
      
      <Link
        :href="route(generateRoute('gestionale.immobili.anagrafiche.create'), { condominio: page.props.condominio.id, immobile: page.props.immobile.id })"
        class="inline-flex h-9 items-center justify-center gap-2 rounded-md shadow px-4 bg-primary text-sm font-medium text-white hover:bg-primary/90 transition focus:outline-none focus:ring-2 focus:ring-primary/20"
        prefetch
      >
        <Plus class="w-4 h-4" />
        <span>Associa</span>
      </Link> 

      <Link
        as="button"
        :href="generatePath('gestionale/:condominio/immobili', { condominio: page.props.condominio.id })"
        class="inline-flex h-9 items-center justify-center gap-2 rounded-md bg-secondary border border-input px-4 text-sm font-medium text-secondary-foreground shadow-sm hover:bg-secondary/80"
      >
        <List class="w-4 h-4" />
        <span>Immobili</span>
      </Link>

    </div>

  </div>
</template>