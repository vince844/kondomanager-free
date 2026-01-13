<script setup lang="ts">

import { ref, computed } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { router, Link } from '@inertiajs/vue3';
import { Input } from '@/components/ui/input';
import { Plus, List } from 'lucide-vue-next';
import DataTableFacetedFilter from '@/components/documenti/DataTableFacetedFilter.vue';
import { usePermission } from "@/composables/permissions";
import { Permission } from '@/enums/Permission';
import { useCategorieDocumenti } from '@/composables/useCategorieDocumenti';
import { trans } from 'laravel-vue-i18n';
import type { Table } from '@tanstack/vue-table';
import type { Documento } from '@/types/documenti';

const { generateRoute, hasPermission } = usePermission();
const { categorie, isLoading, loadCategorie } = useCategorieDocumenti()

// Change this to allow table reset when filter cleared
const { table } = defineProps<{
  table: Table<Documento>
}>()

const nameFilter = ref('')
const categoriaColumn = table.getColumn('categoria')

const categoriaFilter = computed(() => {
  const val = categoriaColumn?.getFilterValue()
  return Array.isArray(val) ? val : []
})

const handleOpenDropdown = () => {
  loadCategorie()
}

watchDebounced(
  [nameFilter, categoriaFilter],
  ([name, category_id]) => {
    const params: Record<string, any> = { page: 1 }

    if (name) params.name = name
    if (category_id.length > 0) params.category_id = category_id

    router.get(
      route(generateRoute('documenti.index')),
      params,
      {
        preserveState: true,
        replace: true,
        preserveScroll: true,
        onSuccess: () => {
          if (!name && category_id.length === 0) {
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
  <!-- Left: Filters -->
  <div class="flex items-center space-x-2">
    <!-- Subject Filter -->
    <Input
      :placeholder="trans('documenti.table.filter_by')"
      v-model="nameFilter"
      class="h-8 w-[150px] lg:w-[250px]"
    />

    <DataTableFacetedFilter
      v-if="categoriaColumn"
      :column="categoriaColumn"
      :title="trans('documenti.table.category')"
      :options="categorie"
      :isLoading="isLoading"
      @open="handleOpenDropdown"
      @update:filter="() => {}"
    />
  </div>

  <!-- Right: Action buttons -->
  <div class="flex items-center space-x-2">
    <Link 
      as="button"
      v-if="hasPermission([Permission.CREATE_ARCHIVE_DOCUMENTS])"
      :href="route(generateRoute('documenti.create'))" 
      class="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90"
    >
      <Plus class="w-4 h-4" />
      <span>{{ trans('documenti.actions.new_document') }}</span>
    </Link>

    <Link 
      as="button"
      :href="route(generateRoute('categorie.index'))" 
      class="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90"
    >
      <List class="w-4 h-4" />
      <span>{{ trans('documenti.actions.list_categories') }}</span>
    </Link>
  </div>
</div>


</template>
