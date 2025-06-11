<script setup lang="ts">

import { ref, computed } from 'vue'
import { watchDebounced } from '@vueuse/core'
import { router, Link } from '@inertiajs/vue3'
import { Input } from '@/components/ui/input'
import { BellPlus } from 'lucide-vue-next'
import DataTableFacetedFilter from '@/components/documenti/DataTableFacetedFilter.vue'
import { usePermission } from "@/composables/permissions";
import { useDocumenti } from '@/composables/useDocumenti';
import type { Table } from '@tanstack/vue-table'
import type { Documento } from '@/types/documenti'

const { hasPermission } = usePermission();
const { setDocumenti, documenti, meta } = useDocumenti();

interface DataTableToolbarProps {
  table: Table<Documento>
}

const props = defineProps<DataTableToolbarProps>()

/* type DocumentiPageProps = {
  documenti: Documento[]
  meta: any // Replace with a specific type if available
} */

const nameFilter = ref('')

// ✅ Watch both filters and send to backend
watchDebounced(
  [nameFilter],
  ([name]) => {
    const params: Record<string, any> = {
      page: 1, // Reset to page 1 when filters change
    }

    if (name) params.name = name

    // Fetch new data from the backend
    router.get(route('admin.documenti.index'), params, {
      preserveState: true,
      replace: true,
      onSuccess: (page) => {
        // Update the `documenti` and `meta` data after fetching from backend
        /* setDocumenti(page.props.documenti, page.props.meta); */
        const props = (page.props as unknown) as { documenti: Documento[]; meta: any }
        setDocumenti(props.documenti, props.meta)
      }
    });
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

    <Link 
      as="button"
      v-if="hasPermission(['Crea documenti'])"
      :href="route('admin.documenti.create')" 
      class="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90 order-last lg:order-none lg:ml-auto"
    >
      <BellPlus class="w-4 h-4" />
      <span>Crea</span>
    </Link>

  </div>

</template>
