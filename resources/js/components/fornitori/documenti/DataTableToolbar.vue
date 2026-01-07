<script setup lang="ts">

import { ref } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { router, Link, usePage} from '@inertiajs/vue3';
import { Input } from '@/components/ui/input';
import { Plus, List } from 'lucide-vue-next';
import { usePermission } from "@/composables/permissions";
import { Permission } from '@/enums/Permission';
import type { Table } from '@tanstack/vue-table';
import type { Documento } from '@/types/documenti';
import type { Fornitore } from '@/types/fornitori';

const { generateRoute, hasPermission, generatePath } = usePermission();

// Change this to allow table reset when filter cleared
const { table } = defineProps<{
  table: Table<Documento>
}>()

// Page props
const page = usePage<{ fornitore: Fornitore }>()

const nameFilter = ref('')

watchDebounced(
  [nameFilter],
  ([name]) => {
    const params: Record<string, any> = { page: 1 }

    if (name) params.name = name

    router.get(
      route(generateRoute('fornitori.documenti.index'), { fornitore: page.props.fornitore.id }),
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

  <!-- Right: Action buttons -->
  <div class="flex items-center space-x-2">
    <Link 
      as="button"
      v-if="hasPermission([Permission.CREATE_ARCHIVE_DOCUMENTS])"
       :href="route(generateRoute('fornitori.documenti.create'), { fornitore: page.props.fornitore.id })"
      class="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90"
    >
      <Plus class="w-4 h-4" />
      <span>Crea</span>
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
</div>


</template>
