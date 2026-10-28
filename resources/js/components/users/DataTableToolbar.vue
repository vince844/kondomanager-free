<script setup lang="ts">

import { computed, ref } from 'vue'
import { watchDebounced } from '@vueuse/core'
import { Link, usePage } from '@inertiajs/vue3'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { CircleCheck, CircleX, Settings, ShieldCheck, ShieldOff, UserPlus, X } from 'lucide-vue-next';
import DataTableFacetedFilter from '@/components/users/DataTableFacetedFilter.vue';
import { usePermission } from "@/composables/permissions";
import { useTabellaServer } from '@/composables/useTabellaServer';
import { trans } from 'laravel-vue-i18n';
import type { Component } from 'vue'
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

const page = usePage<{ filters?: FiltriDalServer }>();

/**
 * I filtri che il server ha già applicato, come li rimanda dopo averli validati.
 *
 * ⚠️ Sono la fonte dello stato della barra all'apertura (vedi sotto): l'elenco arriva già filtrato
 * quando l'indirizzo li porta (un segnalibro, la pagina ricaricata, una card), e una barra vuota
 * sopra un elenco filtrato mente.
 */
type FiltriDalServer = {
  name?: string | null
  roles?: string[] | null
  stato?: string[] | null
  verifica?: string[] | null
}

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

// La verifica dell'email è un filtro come lo stato: il valore sta in `email_verified_at`, che per la
// barra è una colonna nascosta (lo scudo si vede già nella cella del nome).
const verificaDisponibili = computed<(Opzione & { icon: Component })[]>(() => [
  { value: 'verificato', label: 'users.table.verified', icon: ShieldCheck },
  { value: 'non_verificato', label: 'users.table.unverified', icon: ShieldOff },
])

const ruoliColumn = table.getColumn('roles')
const statoColumn = table.getColumn('suspended_at')
const verificaColumn = table.getColumn('email_verified_at')

/**
 * La barra nasce allineata a ciò che il server ha già applicato: la ricerca, i badge dei filtri e il pulsante
 * «Rimuovi filtri» sono quelli dell'elenco che si vede.
 *
 * ⚠️ **Prima** dell'osservatore che manda i filtri al server, così nasce già allineato e non vede nessun
 * cambiamento: nessuna richiesta di rimbalzo. E non azzera niente: un filtro assente lascia lo stato com'è.
 * Così il primo filtro toccato manda al server anche gli altri, quelli che l'elenco ha già applicato: nessuno si toglie.
 */
const filtriDelServer = page.props.filters
if (filtriDelServer?.name) {
  nameFilter.value = filtriDelServer.name
}
if (filtriDelServer?.roles?.length) {
  ruoliColumn?.setFilterValue(filtriDelServer.roles)
}
if (filtriDelServer?.stato?.length) {
  statoColumn?.setFilterValue(filtriDelServer.stato)
}
if (filtriDelServer?.verifica?.length) {
  verificaColumn?.setFilterValue(filtriDelServer.verifica)
}

const ruoliFilter = computed(() => {
  const val = ruoliColumn?.getFilterValue()
  return Array.isArray(val) ? val : []
})

const statoFilter = computed(() => {
  const val = statoColumn?.getFilterValue()
  return Array.isArray(val) ? val : []
})

const verificaFilter = computed(() => {
  const val = verificaColumn?.getFilterValue()
  return Array.isArray(val) ? val : []
})

const { filtra } = useTabellaServer(() => route('utenti.index'));

// Debounce search input (300ms delay)
watchDebounced(
  [nameFilter, ruoliFilter, statoFilter, verificaFilter],
  ([name, roles, stato, verifica]) => {
    // Ogni filtro va passato anche quando è vuoto: `null` significa **togli**. La richiesta
    // riparte da ciò che c'è nell'URL, quindi un filtro omesso resterebbe quello di prima e non
    // si potrebbe più svuotare. Per i filtri a scelta multipla il vuoto è l'array senza elementi.
    filtra({
      name: name || null,
      roles: roles.length > 0 ? roles : null,
      stato: stato.length > 0 ? stato : null,
      verifica: verifica.length > 0 ? verifica : null,
    })
  },
  { debounce: 300 }
)

const rimuoviFiltri = () => {
  nameFilter.value = ''
  ruoliColumn?.setFilterValue(undefined)
  statoColumn?.setFilterValue(undefined)
  verificaColumn?.setFilterValue(undefined)
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

        <DataTableFacetedFilter
          v-if="verificaColumn"
          :column="verificaColumn"
          :title="trans('users.table.verification')"
          :options="verificaDisponibili"
          :isLoading="false"
          @update:filter="() => {}"
          class="w-full lg:w-auto"
        />

        <Button
          v-if="nameFilter || ruoliFilter.length || statoFilter.length || verificaFilter.length"
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
