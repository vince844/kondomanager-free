<script setup lang="ts">

import { computed, ref } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { Link, router } from '@inertiajs/vue3';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import DataTableFacetedFilter from '@/components/documenti/DataTableFacetedFilter.vue';
import { Plus, Tags, X } from 'lucide-vue-next';
import { trans } from 'laravel-vue-i18n';
import { usePermission } from "@/composables/permissions";
import { useTabellaServer } from '@/composables/useTabellaServer';
import { Permission }  from "@/enums/Permission";
import type { Table } from '@tanstack/vue-table';
import type { Fornitore } from '@/types/fornitori';

interface DataTableToolbarProps {
  table: Table<Fornitore>
  /** Le categorie per il filtro: arrivano col controller, non si caricano a richiesta. */
  categorie?: Array<{ id: number; name: string }>
  /**
   * I filtri **già applicati dal server**, così come tornano dal controller.
   *
   * ⚠️ Senza questa prop la barra nascerebbe vuota su una pagina filtrata, e il filtro si
   * perderebbe al primo tocco. Dalla 1.11.0-beta.9 il nome di una categoria è un link che porta
   * esattamente qui con `categoria_id` già impostato: una pagina filtrata che non lo dichiara
   * sarebbe una trappola costruita apposta. È la stessa lezione che l'elenco documenti ha
   * imparato nella beta.62.
   */
  filters?: { ragione_sociale?: string | null; categoria_id?: number[] | null }
}

const props = defineProps<DataTableToolbarProps>();

const ragioneSocialeFilter = ref('')
const { hasPermission, generateRoute } = usePermission();

const colonnaCategoria = props.table.getColumn('categoria');

/*
 * ⚠️ **Il componente del filtro parla in stringhe, il server in interi.**
 *
 * `DataTableFacetedFilter` tiene un `Set` di `value` testuali; `categoria_id` a valle è un array
 * di interi validati con `exists`. La conversione si fa qui, in un punto solo: farla a metà — che
 * è il modo naturale di sbagliarla — dà un filtro che si accende a schermo e non filtra niente.
 */
const opzioniCategoria = computed(() =>
  (props.categorie ?? []).map((c) => ({ label: c.name, value: String(c.id) }))
);

const categoriaFilter = computed(() => {
  const valore = colonnaCategoria?.getFilterValue();
  return Array.isArray(valore) ? valore : [];
});

// La reidratazione: si scrive qui invece di riusare `reidratraFiltri`, che ha la firma dei
// documenti — `name`, `category_id`, `condominio_id` — e su questo elenco chiederebbe di
// rinominare i filtri per farceli entrare.
if (props.filters?.ragione_sociale) {
  ragioneSocialeFilter.value = props.filters.ragione_sociale;
}

if (props.filters?.categoria_id?.length) {
  colonnaCategoria?.setFilterValue(props.filters.categoria_id.map(String));
}

const { filtra } = useTabellaServer(() => route(generateRoute('fornitori.index')));

const filtriAttivi = computed(() =>
  ragioneSocialeFilter.value !== '' || categoriaFilter.value.length > 0
);

// Debounce search input (300ms delay)
watchDebounced(
  [ragioneSocialeFilter, categoriaFilter],
  ([ragioneSociale, categorie]) => {
    // Ogni filtro che può essere vuoto viaggia come `null`, mai omesso: la richiesta riparte da ciò
    // che c'è nell'URL, e un filtro omesso resterebbe quello di prima.
    filtra({
      ragione_sociale: ragioneSociale || null,
      categoria_id: categorie.length > 0 ? categorie.map(Number) : null,
    })
  },
  { debounce: 300 }
)

function pulisciFiltri() {
  ragioneSocialeFilter.value = '';
  colonnaCategoria?.setFilterValue(undefined);

  router.get(route(generateRoute('fornitori.index')), { page: 1 }, {
    preserveState: true,
    replace: true,
    preserveScroll: true,
  });
}

</script>

<template>
  <div class="flex items-center justify-between w-full mb-3">
    <!-- Left Section: Input -->
    <div class="flex flex-wrap items-center gap-2">
        <Input
          placeholder="Filtra per ragione sociale..."
          v-model="ragioneSocialeFilter"
          class="h-8 w-[150px] lg:w-[250px]"
        />

        <DataTableFacetedFilter
          v-if="colonnaCategoria"
          :column="colonnaCategoria"
          :title="trans('fornitori.table.category')"
          :options="opzioniCategoria"
        />

        <Button
          v-if="filtriAttivi"
          variant="ghost"
          size="sm"
          class="h-8 px-2"
          @click="pulisciFiltri"
        >
          {{ trans('fornitori.categorie.clear_filters') }}
          <X class="ml-1 w-4 h-4" />
        </Button>
    </div>

    <!-- Right Section: Button (force it to the right) -->
    <div class="flex items-center gap-2">

    <!--
      La porta d'ingresso alle categorie, dalla 1.11.0-beta.9. Senza un collegamento qui la pagina
      esisterebbe solo per chi ne conosce l'URL: è la stessa forma con cui si arriva alle categorie
      dei documenti dall'archivio.
      Nessuna guardia di permesso, come la rotta: chi è entrato in questa schermata ha già superato
      il filtro del pannello di amministrazione, che è lo stesso che protegge quella.
    -->
    <!--
      ⚠️ Stesso stile del pulsante «Categorie» dell'archivio documenti — scuro con l'icona
      colorata — e non una variante chiara. Due pulsanti che portano alla stessa **cosa** in due
      schermate diverse devono avere lo stesso aspetto, altrimenti chi li usa deve impararli due
      volte. *(Allineato il 31/08/2026, chiudendo la beta.10.)*
    -->
    <Link
      as="button"
      :href="route(generateRoute('categorie-fornitore.index'))"
      class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-slate-900 dark:bg-slate-700 border border-slate-800 shadow-sm text-xs font-medium text-white hover:bg-slate-800 dark:hover:bg-slate-600 transition-colors"
    >
      <Tags class="w-3.5 h-3.5 text-blue-400" />
      <span>{{ trans('fornitori.categorie.manage') }}</span>
    </Link>

    <Link
      as="button"
      v-if="hasPermission([Permission.CREATE_USERS])"
      :href="route(generateRoute('fornitori.create'))"
      class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-slate-900 dark:bg-slate-700 border border-slate-800 shadow-sm text-xs font-medium text-white hover:bg-slate-800 dark:hover:bg-slate-600 transition-colors"
    >
      <Plus class="w-3.5 h-3.5 text-green-500" />
      <span>Crea fornitori</span>
    </Link>

    </div>

  </div>
</template>
