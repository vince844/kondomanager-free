<script setup lang="ts">

import { ref, computed } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { router, Link } from '@inertiajs/vue3';
import { Input } from '@/components/ui/input';
import { BellPlus } from 'lucide-vue-next';
import DataTableFacetedFilter from '@/components/documenti/DataTableFacetedFilter.vue';
import { usePermission } from "@/composables/permissions";
import { useDocumenti } from '@/composables/useDocumenti';
import type { Table } from '@tanstack/vue-table';
import type { Documento } from '@/types/documenti';
import { useCategorieDocumenti } from '@/composables/useCategorieDocumenti';

interface DataTableToolbarProps {
  table: Table<Documento>
}

const props = defineProps<DataTableToolbarProps>()
const { hasPermission } = usePermission();
const { setDocumenti, documenti, meta } = useDocumenti();
const { categorie, isLoading, loadCategorie } = useCategorieDocumenti()

const nameFilter = ref('')
const categoriaColumn = props.table.getColumn('categoria')

const categoriaFilter = computed(() => {
  const val = categoriaColumn?.getFilterValue()
  return Array.isArray(val) ? val : []
})

const handleOpenDropdown = () => {
  loadCategorie()
}

// ✅ Watch both filters and send to backend
watchDebounced(
  [nameFilter, categoriaFilter],
  ([name, category_id]) => {
    const params: Record<string, any> = {
      page: 1, // Reset to page 1 when filters change
    }

    if (name) params.name = name
    if (category_id.length > 0) params.category_id = category_id

    // Fetch new data from the backend
    router.get(route('admin.documenti.index'), params, {
      preserveState: true,
      replace: true,
      onSuccess: (page) => {
        // Update the `documenti` and `meta` data after fetching from backend
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

      <DataTableFacetedFilter
        v-if="categoriaColumn"
        :column="categoriaColumn"
        title="Categoria"
        :options="categorie"
        :isLoading="isLoading"
        @open="handleOpenDropdown"
        @update:filter="() => {}"
      />

      <!-- Priority Filter -->
<!--       <DataTableFacetedFilter
        v-if="categoriaColumn"
        :column="categoriaColumn"
        title="Categoria"
        :options="priorityConstants"
        :isLoading="false"
        @update:filter="() => {}" 
      /> -->

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
