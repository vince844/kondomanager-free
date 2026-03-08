<script setup lang="ts">
import { ref, computed } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { router, Link } from '@inertiajs/vue3';
import { Input } from '@/components/ui/input';
import { Plus, List, X } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import DataTableFacetedFilter from '@/components/documenti/DataTableFacetedFilter.vue';
import { usePermission } from "@/composables/permissions";
import { Permission } from '@/enums/Permission';
import { useCategorieDocumenti } from '@/composables/useCategorieDocumenti';
import { trans } from 'laravel-vue-i18n';
import type { Table } from '@tanstack/vue-table';
import type { Documento } from '@/types/documenti';

// IMPORTAZIONE FILTRO CONDOMINI
import { useCondomini } from '@/composables/useCondomini';

const { generateRoute, hasPermission } = usePermission();
const { categorie, isLoading, loadCategorie } = useCategorieDocumenti();

const { table } = defineProps<{
  table: Table<Documento>
}>();

// Read current filters from column state
const categoriaColumn = table.getColumn('categoria');
const condominioColumn = table.getColumn('condomini'); // Assicurati che l'accessorKey in columns.ts sia 'condomini'

// LOGICA DROPDOWN CONDOMINI
const { condomini, isLoading: isLoadingCondomini, loadCondomini } = useCondomini();
const handleOpenCondomini = () => {
  loadCondomini();
};

const handleOpenDropdown = () => {
  loadCategorie();
};

const nameFilter = ref('');

const categoriaFilter = computed(() => {
  const val = categoriaColumn?.getFilterValue();
  return Array.isArray(val) ? val : [];
});

const condominioFilter = computed(() => {
  const val = condominioColumn?.getFilterValue();
  return Array.isArray(val) ? val : [];
});

watchDebounced(
  [nameFilter, categoriaFilter, condominioFilter],
  ([name, category_id, condominio_id]) => {
    const params: Record<string, any> = { page: 1 };

    if (name) params.name = name;
    if (category_id.length > 0) params.category_id = category_id;
    if (condominio_id.length > 0) params.condominio_id = condominio_id;

    router.get(
      route(generateRoute('documenti.index')),
      params,
      {
        preserveState: true,
        replace: true,
        preserveScroll: true,
        onSuccess: () => {
          if (!name && category_id.length === 0 && condominio_id.length === 0) {
            table.reset();
          }
        }
      }
    );
  },
  { debounce: 300 }
);

const clearAllFilters = () => {
  nameFilter.value = '';
  categoriaColumn?.setFilterValue(undefined);
  condominioColumn?.setFilterValue(undefined);

  router.get(route(generateRoute('documenti.index')), { page: 1 }, {
    preserveState: true,
    replace: true,
    preserveScroll: true,
  });
};
</script>

<template>
<div class="flex flex-col gap-2 w-full mb-3 lg:flex-row lg:items-center lg:justify-between">
  
  <div class="flex flex-col gap-2 lg:flex-row lg:items-center lg:gap-4">
    <Input
      :placeholder="trans('documenti.table.filter_by')"
      v-model="nameFilter"
      class="h-8 w-full lg:w-[250px]"
    />

    <div class="flex flex-col gap-2 lg:flex-row lg:items-center">
      <DataTableFacetedFilter
        v-if="categoriaColumn"
        :column="categoriaColumn"
        :title="trans('documenti.table.category')"
        :options="categorie"
        :isLoading="isLoading"
        @open="handleOpenDropdown"
        @update:filter="() => {}"
        class="w-full lg:w-auto"
      />

      <DataTableFacetedFilter
        v-if="condominioColumn"
        :column="condominioColumn"
        :title="trans('documenti.table.buildings')"
        :options="condomini"
        :isLoading="isLoadingCondomini"
        @open="handleOpenCondomini"
        @update:filter="() => {}"
        class="w-full lg:w-auto"
      />

      <Button
        v-if="nameFilter || categoriaFilter.length || condominioFilter.length"
        variant="ghost"
        size="sm"
        @click="clearAllFilters"
        class="h-8 px-2 lg:px-3 text-slate-500 hover:bg-slate-100"
      >
        <X class="w-4 h-4 mr-2" />
        {{ trans('documenti.table.clear_all_filters') }}
      </Button>
    </div>
  </div>

  <div class="flex items-center space-x-2 mt-2 lg:mt-0 ml-auto">
    <Link 
      as="button"
      v-if="hasPermission([Permission.CREATE_ARCHIVE_DOCUMENTS])"
      :href="route(generateRoute('documenti.create'))" 
      class="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground hover:bg-primary/90 h-8"
    >
      <Plus class="w-4 h-4" />
      <span>{{ trans('documenti.actions.new_document') }}</span>
    </Link>

    <Link 
      as="button"
      :href="route(generateRoute('categorie.index'))" 
      class="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground hover:bg-primary/90 h-8"
    >
      <List class="w-4 h-4" />
      <span>{{ trans('documenti.actions.list_categories') }}</span>
    </Link>
  </div>
</div>
</template>
