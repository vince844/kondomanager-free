<script setup lang="ts">

import { computed, ref } from 'vue'
import { watchDebounced } from '@vueuse/core'
import { Link, usePage } from '@inertiajs/vue3'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { CircleCheck, CircleX, Settings, UserPlus, X } from 'lucide-vue-next';
import DataTableFacetedFilter from '@/components/users/DataTableFacetedFilter.vue';
import { usePermission } from "@/composables/permissions";
import { useTabellaServer } from '@/composables/useTabellaServer';
import { trans } from 'laravel-vue-i18n';
import type { Table } from '@tanstack/vue-table'
import type { User } from '@/types/users'

const { hasPermission } = usePermission();

interface Opzione {
  value: string
  label: string
  icon?: unknown
}

const { table } = defineProps<{
  table: Table<User>
}>();

const page = usePage();

const nameFilter = ref('')

// I ruoli non sono una costante: l'amministratore può crearne di suoi, quindi l'elenco arriva
// dal server insieme alla pagina.
const ruoliDisponibili = computed<Opzione[]>(
  () => (page.props.ruoliDisponibili as Opzione[] | undefined) ?? []
)

// Gli stati invece sono due e non cambiano: è una colonna calcolata da `suspended_at`, non un campo.
const statiDisponibili = computed<Opzione[]>(() => [
  { value: 'attivo', label: 'users.table.active', icon: CircleCheck },
  { value: 'sospeso', label: 'users.table.suspended', icon: CircleX },
])

const ruoliColumn = table.getColumn('roles')
const statoColumn = table.getColumn('suspended_at')

const ruoliFilter = computed(() => {
  const val = ruoliColumn?.getFilterValue()
  return Array.isArray(val) ? val : []
})

const statoFilter = computed(() => {
  const val = statoColumn?.getFilterValue()
  return Array.isArray(val) ? val : []
})

const { filtra } = useTabellaServer(() => route('utenti.index'));

// Debounce search input (300ms delay)
watchDebounced(
  [nameFilter, ruoliFilter, statoFilter],
  ([name, roles, stato]) => {
    // Ogni filtro va passato anche quando è vuoto: `null` significa **togli**. La richiesta
    // riparte da ciò che c'è nell'URL, quindi un filtro omesso resterebbe quello di prima e non
    // si potrebbe più svuotare. Per i filtri a scelta multipla il vuoto è l'array senza elementi.
    filtra({
      name: name || null,
      roles: roles.length > 0 ? roles : null,
      stato: stato.length > 0 ? stato : null,
    })
  },
  { debounce: 300 }
)

const rimuoviFiltri = () => {
  nameFilter.value = ''
  ruoliColumn?.setFilterValue(undefined)
  statoColumn?.setFilterValue(undefined)
}

</script>

<template>
  <div class="flex flex-col gap-3 w-full mb-3 lg:flex-row lg:items-center lg:justify-between">
    <!-- Left Section: Input + filtri -->
    <div class="flex flex-col gap-2 lg:flex-row lg:items-center">
      <Input
         :placeholder="trans('users.table.filter')"
        v-model="nameFilter"
        class="h-8 w-full sm:w-[200px] lg:w-[250px]"
      />

      <div class="flex flex-col gap-2 lg:flex-row lg:items-center">
        <DataTableFacetedFilter
          v-if="ruoliColumn"
          :column="ruoliColumn"
          :title="trans('users.table.role')"
          :options="ruoliDisponibili"
          :isLoading="false"
          @update:filter="() => {}"
          class="w-full lg:w-auto"
        />

        <DataTableFacetedFilter
          v-if="statoColumn"
          :column="statoColumn"
          :title="trans('users.table.status')"
          :options="statiDisponibili"
          :isLoading="false"
          @update:filter="() => {}"
          class="w-full lg:w-auto"
        />

        <Button
          v-if="nameFilter || ruoliFilter.length || statoFilter.length"
          variant="ghost"
          class="h-8 px-2 lg:px-3"
          @click="rimuoviFiltri"
        >
          {{ trans('users.table.clear_all_filters') }}
          <X class="ml-2 h-4 w-4" />
        </Button>
      </div>
    </div>

    <!-- Right Section: Buttons -->
    <div class="flex flex-col gap-2 w-full sm:flex-row sm:justify-end">
      <Button 
        class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-slate-900 dark:bg-slate-700 border border-slate-800 shadow-sm text-xs font-medium text-white hover:bg-slate-800 dark:hover:bg-slate-600 transition-colors"
        v-if="hasPermission(['Crea utenti'])"
        as="a"
        :href="route('utenti.create')"
      >
        <UserPlus class="w-3.5 h-3.5"/>
        <span>{{ trans('users.actions.new_user') }}</span>
      </Button>
    </div>
  </div>

</template>
