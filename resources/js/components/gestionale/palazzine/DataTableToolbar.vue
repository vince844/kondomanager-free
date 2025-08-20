<script setup lang="ts">

import { ref } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { router, usePage, Link } from '@inertiajs/vue3';
import { Input } from '@/components/ui/input';
import { Plus } from 'lucide-vue-next';
import { usePermission } from "@/composables/permissions";
import type { Table } from '@tanstack/vue-table';
import type { Palazzina } from '@/types/gestionale/palazzine';
import type { Building } from '@/types/buildings';

const { generateRoute } = usePermission();

const { table } = defineProps<{
  table: Table<Palazzina>
}>()

const page = usePage<{
  condominio: Building;
}>();

const nameFilter = ref('')

watchDebounced(
  [nameFilter],
  ([name]) => {
    const params: Record<string, any> = { page: 1 }

    if (name) params.name = name

    router.get(
      route(generateRoute('gestionale.palazzine.index')),
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
    <!-- Left Section: Input -->
    <div class="flex items-center space-x-2">
      <div class="flex items-center space-x-2">
        <Input
          placeholder="Filtra per nome..."
          v-model="nameFilter"
          class="h-8 w-[150px] lg:w-[250px]"
        />

      </div>
    </div>

    <Link
      :href="route('admin.gestionale.palazzine.create', { condominio: page.props.condominio.id })"
      class="hidden h-8 lg:flex ml-auto items-center gap-2 rounded-md shadow px-3 bg-primary text-white hover:bg-primary/90 transition"
      prefetch
    >
      <Plus class="w-4 h-4" />
      <span>Crea</span>
    </Link>

  </div>
</template>
