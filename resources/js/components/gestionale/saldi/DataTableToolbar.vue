<script setup lang="ts">
import { ref, computed } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { router, usePage } from '@inertiajs/vue3';
import { Input } from '@/components/ui/input';
import { usePermission } from '@/composables/permissions';
import type { Table } from '@tanstack/vue-table';
import type { ImmobileConSaldi } from '@/types/gestionale/saldi';
import type { Building } from '@/types/buildings';

const props = defineProps<{ table: Table<ImmobileConSaldi> }>();

const page = usePage<{ condominio: Building }>();
const { generateRoute } = usePermission();

const nameFilter = ref('');

const filterParams = computed(() => {
  const params: Record<string, any> = { page: 1 }
  if (nameFilter.value) params.nome = nameFilter.value // Backend deve intercettare 'nome' per filtrare
  return params
});

watchDebounced(
  [nameFilter],
  () => {
    router.get(
      route(generateRoute('gestionale.saldi.index'), { condominio: page.props.condominio.id }),
      filterParams.value,
      {
        preserveState: true,
        replace: true,
        preserveScroll: true,
        onSuccess: () => {
          if (!nameFilter.value) props.table.reset()
        }
      }
    )
  },
  { debounce: 300 }
)
</script>

<template>
  <div class="flex items-center justify-between w-full mb-3">
    
    <div class="flex items-center space-x-2">
      <div class="flex items-center space-x-2">
        <Input
          placeholder="Cerca immobile (es. int 1)..."
          v-model="nameFilter"
          class="h-8 w-[150px] lg:w-[250px]"
        />
      </div>
    </div>

    </div>
</template>