<script setup lang="ts">

import { ref, computed } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { router, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Plus } from 'lucide-vue-next';
import { usePermission } from "@/composables/permissions";
import DataTableFacetedFilter from '@/components/eventi/DataTableFacetedFilter.vue';
import { Permission }  from "@/enums/Permission";
import { useCategorieEventi } from '@/composables/useCategorieEventi';
import type { Table } from '@tanstack/vue-table';
import type { Evento } from '@/types/eventi';

const { generateRoute, hasPermission } = usePermission();
const { categorie, isLoading, loadCategorie } = useCategorieEventi()

// Change this to allow table reset when filter cleared
const { table } = defineProps<{
  table: Table<Evento>
}>()

// Read current priority filter from column state
/* const priorityColumn = table.getColumn('priority') */

const nameFilter = ref('')
const categoriaColumn = table.getColumn('categoria')

const categoriaFilter = computed(() => {
  const val = categoriaColumn?.getFilterValue()
  return Array.isArray(val) ? val : []
})

const handleOpenDropdown = () => {
  loadCategorie()
}

/* const priorityFilter = computed(() => {
  const val = priorityColumn?.getFilterValue()
  return Array.isArray(val) ? val : []
})
 */
watchDebounced(
  [nameFilter, categoriaFilter],
  ([title, category_id]) => {
    const params: Record<string, any> = { page: 1 }

    if (title) params.title = title
    if (category_id.length > 0) params.category_id = category_id

    router.get(
      route(generateRoute('eventi.index')),
      params,
      {
        preserveState: true,
        replace: true,
        preserveScroll: true,
        onSuccess: () => {
          if (!title && category_id.length === 0) {
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
    <!-- Left Section: Input -->
    <div class="flex items-center space-x-2">
      <div class="flex items-center space-x-2">
        <Input
          placeholder="Filtra per nome..."
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

<!--          <div class="flex flex-col gap-2 lg:flex-row lg:items-center">
          <DataTableFacetedFilter
            v-if="priorityColumn"
            :column="priorityColumn"
            title="Priorità"
            :options="priorityConstants"
            :isLoading="false"
            @update:filter="() => {}"
            class="w-full lg:w-auto"
          />

        </div> -->

      </div>
    </div>

    <Link
      as="button"
      :href="route(generateRoute('eventi.create'))"
      class="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90 order-last lg:order-none lg:ml-auto"
    >
      <Plus class="w-4 h-4" />
      <span>Crea</span>
    </Link>

  </div>
</template>
