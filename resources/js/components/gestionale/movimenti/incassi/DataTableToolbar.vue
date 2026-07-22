<script setup lang="ts">

import { ref, computed } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { router, usePage, Link } from '@inertiajs/vue3';
import { Input } from '@/components/ui/input';
import { Search, Plus, X } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { usePermission } from "@/composables/permissions";
import type { Table } from '@tanstack/vue-table';
import type { Building } from '@/types/buildings';

defineProps<{ table: Table<any> }>();
const page = usePage<{ condominio: Building }>();
const { generateRoute } = usePermission();
const condominioId = computed(() => page.props.condominio.id);
const globalFilter = ref('')

const filterParams = computed(() => {
  const params: Record<string, any> = { page: 1 }
  if (globalFilter.value) params.search = globalFilter.value
  return params
})

watchDebounced(
  globalFilter,
  () => {
    router.get(
      route(generateRoute('gestionale.movimenti-rate.index'), { condominio: condominioId.value }),
      filterParams.value,
      {
        preserveState: true,
        replace: true,
        preserveScroll: true,
      }
    )
  },
  { debounce: 300 }
)

const isFiltered = computed(() => globalFilter.value.length > 0)
const resetFilter = () => { globalFilter.value = '' }
</script>

<template>
  <div class="flex items-center justify-between w-full">

    <div class="flex items-center space-x-2">

      <!-- Ricerca libera -->
      <div class="relative">
        <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-slate-400">
          <Search class="h-4 w-4" />
        </div>
        <Input
          placeholder="Filtra per nome..."
          v-model="globalFilter"
          class="pl-9 h-8 w-[200px] lg:w-[250px]"
        />
      </div>

      <!-- Reset -->
      <Button
        v-if="isFiltered"
        variant="ghost"
        @click="resetFilter"
        class="h-8 px-2 lg:px-3 text-slate-500 hover:text-slate-700"
      >
        <X class="h-4 w-4 mr-1 lg:mr-2" />
        <span class="hidden lg:inline">Azzera filtri</span>
        <span class="inline lg:hidden">Azzera</span>
      </Button>

    </div>

    <Button as-child>
        <Link
          :href="route(generateRoute('gestionale.movimenti-rate.create'), { condominio: condominioId })"
          class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-slate-900 dark:bg-slate-700 border border-slate-800 shadow-sm text-xs font-medium text-white hover:bg-slate-800 dark:hover:bg-slate-600 transition-colors"
        >
          <Plus class="w-3.5 h-3.5 text-green-500" />
          Registra incasso
        </Link>
    </Button>
  </div>
</template>
