<script setup lang="ts">

import { Link, usePage } from '@inertiajs/vue3';
import { Plus, List } from 'lucide-vue-next';
import { usePermission } from "@/composables/permissions";
import { Permission }  from "@/enums/Permission";
import type { Table } from '@tanstack/vue-table';
import type { Anagrafica } from '@/types/anagrafiche';
import type { Fornitore } from '@/types/fornitori';

interface DataTableToolbarProps {
  table: Table<Anagrafica>
}

const { hasPermission, generateRoute, generatePath } = usePermission();
const page = usePage<{ fornitore: Fornitore}>()

</script>

<template>
  <div class="flex items-center justify-between w-full mb-3 space-x-2">

    <Link 
      as="button"
      v-if="hasPermission([Permission.CREATE_USERS])"
      :href="route(generateRoute('fornitori.anagrafiche.create'), { fornitore: page.props.fornitore.id })" 
      class="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90 order-last lg:order-none lg:ml-auto"
    >
      <Plus class="w-4 h-4" />
      <span>Associa</span>
    </Link>

    <Link
      as="button"
      :href="generatePath('fornitori')"
      class="w-full lg:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90"
    >
      <List class="w-4 h-4" />
      <span>Fornitori</span>
    </Link>

  </div>
</template>
