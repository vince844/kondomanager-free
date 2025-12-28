<script setup lang="ts">

import { ref, computed } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { router, usePage, Link } from '@inertiajs/vue3';
import { Input } from '@/components/ui/input';
import { Plus, X } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { usePermission } from "@/composables/permissions";
import type { Table } from '@tanstack/vue-table';
import type { Building } from '@/types/buildings';

const props = defineProps<{ table: Table<any> }>();
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
  <div class="flex items-center justify-between w-full mb-4">
    <div class="flex items-center space-x-2 flex-1">
      <div class="relative w-full max-w-sm">
          <Input
            placeholder="Cerca protocollo, anagrafica..."
            v-model="globalFilter"
            class="h-9 w-[200px] lg:w-[300px]"
          />
          <Button v-if="isFiltered" @click="resetFilter" variant="ghost" size="icon" class="absolute right-0 top-0 h-9 w-9 text-muted-foreground hover:text-foreground">
              <X class="h-4 w-4" />
          </Button>
      </div>
    </div>

    <Button as-child>
        <Link :href="route(generateRoute('gestionale.movimenti-rate.create'), { condominio: condominioId })">
            <Plus class="w-4 h-4 mr-2" />
            Registra Incasso
        </Link>
    </Button>
  </div>
</template>