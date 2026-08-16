<script setup lang="ts">

import { ref, computed } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { Link } from '@inertiajs/vue3';
import { Input } from '@/components/ui/input';
import { Plus } from 'lucide-vue-next';
import { usePermission } from "@/composables/permissions";
import { useTabellaServer } from '@/composables/useTabellaServer';
import { Permission } from "@/enums/Permission";
import { trans } from 'laravel-vue-i18n';
import DataTableFacetedFilter from '@/components/documenti/DataTableFacetedFilter.vue';
import { useCondomini } from '@/composables/useCondomini';
import type { Table } from '@tanstack/vue-table';
import type { Anagrafica } from '@/types/anagrafiche';

const props = defineProps<{
  table: Table<Anagrafica>
}>()

const { hasPermission, generateRoute } = usePermission();

// LOGICA DROPDOWN CONDOMINI
const { condomini, isLoading, loadCondomini } = useCondomini()
const condominioColumn = props.table.getColumn('condomini')

const handleOpenDropdown = () => {
  loadCondomini()
}

// LOGICA FILTRI ATTIVI
const nomeFilter = ref('')
const condominioFilter = computed(() => {
  const val = condominioColumn?.getFilterValue()
  return Array.isArray(val) ? val : []
})

// DEBOUNCE E ROUTING SERVER-SIDE
const { filtra } = useTabellaServer(() => route(generateRoute('anagrafiche.index')))

watchDebounced(
  [nomeFilter, condominioFilter],
  ([nome, condominio_id]) => {
    // Ogni filtro che può essere vuoto viaggia come `null`, mai omesso: la richiesta riparte da ciò
    // che c'è nell'URL, e un filtro omesso resterebbe quello di prima. Per il filtro sfaccettato
    // dei condomìni il «vuoto» è la lista senza elementi.
    const filtri: Record<string, any> = {
      nome: nome || null,
      condominio_id: condominio_id.length > 0 ? condominio_id : null,
    }

    filtra(filtri, () => {
      if (!nome && condominio_id.length === 0) {
        props.table.reset()
      }
    })
  },
  { debounce: 300 }
)
</script>

<template>
  <div class="flex items-center justify-between w-full mb-3">
    <div class="flex items-center space-x-2">
        <Input
          :placeholder="trans('anagrafiche.table.filter')"
          v-model="nomeFilter"
          class="h-8 w-[150px] lg:w-[250px]"
        />

        <DataTableFacetedFilter
          v-if="condominioColumn"
          :column="condominioColumn"
          title="Condominio"
          :options="condomini"
          :isLoading="isLoading"
          @open="handleOpenDropdown"
          @update:filter="() => {}"
        />
    </div>

    <Link
      as="button"
      v-if="hasPermission([Permission.CREATE_USERS])"
      :href="route(generateRoute('anagrafiche.create'))"
      class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-slate-900 dark:bg-slate-700 border border-slate-800 shadow-sm text-xs font-medium text-white hover:bg-slate-800 dark:hover:bg-slate-600 transition-colors"
    >
      <Plus class="w-3.5 h-3.5 text-green-500" />
      <span>{{ trans('anagrafiche.actions.new_resident') }}</span>
    </Link>
  </div>
</template>
