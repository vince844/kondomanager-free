<script setup lang="ts">

import { ref, computed } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { router, Link } from '@inertiajs/vue3';
import { Input } from '@/components/ui/input';
import { Plus, List, X } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import DataTableFacetedFilter from '@/components/documenti/DataTableFacetedFilter.vue';
import { usePermission } from "@/composables/permissions";
import { useTabellaServer } from '@/composables/useTabellaServer';
import { reidratraFiltri } from '@/composables/useReidratazioneFiltri';
import { parametroStato } from '@/lib/documenti/filtro-stato';
import { Permission } from '@/enums/Permission';
import { useCategorieDocumenti } from '@/composables/useCategorieDocumenti';
import { publishedConstants } from '@/lib/documenti/constants';
import { trans } from 'laravel-vue-i18n';
import type { Table } from '@tanstack/vue-table';
import type { Documento } from '@/types/documenti';

// IMPORTAZIONE FILTRO CONDOMINI
import { useCondomini } from '@/composables/useCondomini';

const { generateRoute, hasPermission } = usePermission();
const { categorie, isLoading, loadCategorie } = useCategorieDocumenti();

const { table, filters } = defineProps<{
  table: Table<Documento>,
  /**
   * I filtri **già applicati dal server**, così come tornano dal controller.
   *
   * ⚠️ Senza questa prop la barra nasceva vuota anche su una pagina filtrata. Finché nessuno
   * costruiva a mano un indirizzo con `category_id` il difetto era latente; dalla beta.62 il nome
   * di una categoria è un link che porta esattamente lì, e una pagina filtrata che non lo
   * dichiara — e che perde il filtro al primo tocco sulla barra — sarebbe stata una trappola
   * costruita apposta. È la domanda del perimetro di raggiungibilità: *cosa diventa raggiungibile
   * che prima non lo era?*
   */
  filters?: { name?: string | null, category_id?: number[] | null, condominio_id?: number[] | null, is_published?: boolean[] | null }
}>();

// Read current filters from column state
// ⚠️ La colonna si chiama `categorie` dalla 1.11.0-beta.10: con il nome vecchio
// `getColumn` restituisce `undefined` e il filtro smette di funzionare **in silenzio**.
const categoriaColumn = table.getColumn('categorie');
const condominioColumn = table.getColumn('condomini');

/**
 * ⚠️ **Lo stato si filtra, non si ordina** (1.11.0-beta.10).
 *
 * Ordinare per due soli valori mette in cima tutti i pubblici o tutti i privati, e lascia gli altri
 * in fondo dove nessuno li guarda: è un filtro fatto male. Il filtro vero mostra quello che serve e
 * nasconde il resto, e dice a schermo che lo sta facendo.
 *
 * Le opzioni sono **due e note**, quindi non si caricano da nessuna parte: `publishedConstants` è la
 * stessa fonte che disegna la colonna e le tendine dei moduli.
 */
const statoColumn = table.getColumn('is_published');

const opzioniStato = computed(() =>
  publishedConstants.map((s) => ({ label: trans(s.label), value: String(s.value), icon: s.icon }))
);

const statoFilter = computed(() => {
  const valore = statoColumn?.getFilterValue();
  return Array.isArray(valore) ? valore : [];
});

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

/*
 * La reidratazione, e **deve stare prima del `watchDebounced` qui sotto** — la ragione è scritta
 * per esteso in `useReidratazioneFiltri.ts`, insieme al motivo per cui vive in un file a sé e non
 * qui dentro: dentro il componente il test non poteva chiamarla, e ne provava una copia.
 */
reidratraFiltri(filters, nameFilter, categoriaColumn, condominioColumn, statoColumn);

/*
 * ⚠️ **Reidratare il valore non basta: senza le opzioni la pillola non ha un nome da scrivere.**
 *
 * Gli elenchi di categorie e condomìni si caricano solo all'apertura del menu (`@open`), che è
 * giusto: sono due richieste che sulla maggioranza delle visite non servono. Ma arrivando con un
 * filtro **già applicato** — dalla beta.62 è quello che fa il nome di una categoria nell'elenco
 * categorie — le opzioni sono ancora vuote, e la pillola sa di essere accesa senza sapere su cosa:
 * accanto a «Categoria» resta uno spazio muto invece di «Verbali».
 *
 * Segnalato da Vincenzo guardando la pagina, dopo che la revisione l'aveva classificato «bassa» e
 * io l'avevo rimandato: è la conferma della regola di questo progetto per cui una schermata si
 * guarda, non si deduce. Un filtro che non dice **su cosa** filtra è mezzo passo dal filtro che non
 * dice di esistere, cioè dal difetto che la reidratazione esiste per togliere.
 *
 * Il carico avviene **solo** quando quel filtro è davvero attivo, quindi la visita normale
 * all'elenco non paga niente.
 */
if (filters?.category_id?.length) {
  loadCategorie();
}

if (filters?.condominio_id?.length) {
  loadCondomini();
}

const { filtra } = useTabellaServer(() => route(generateRoute('documenti.index')));

watchDebounced(
  [nameFilter, categoriaFilter, condominioFilter, statoFilter],
  ([name, category_id, condominio_id, is_published]) => {
    // Ogni filtro che può essere vuoto viaggia come `null`, mai omesso: la richiesta riparte da ciò
    // che c'è nell'URL, e un filtro omesso resterebbe quello di prima. Per i filtri sfaccettati
    // (categoria, condomìni) il «vuoto» è la lista senza elementi.
    const filtri: Record<string, any> = {
      name: name || null,
      category_id: category_id.length > 0 ? category_id : null,
      condominio_id: condominio_id.length > 0 ? condominio_id : null,
      // ⚠️ La conversione sta in `lib/documenti/filtro-stato.ts` insieme alla sua gemella in
      // entrata: le stringhe della tabella tornano booleani veri per il server, e le due direzioni
      // sono provate **insieme**, con un giro completo. Scritta qui come espressione, la metà
      // d'andata non era guardata da nessun test — ed è il difetto che ha aperto quel file.
      is_published: parametroStato(is_published as string[]),
    };

    filtra(filtri, () => {
      if (!name && category_id.length === 0 && condominio_id.length === 0 && is_published.length === 0) {
        table.reset();
      }
    });
  },
  { debounce: 300 }
);

const clearAllFilters = () => {
  nameFilter.value = '';
  categoriaColumn?.setFilterValue(undefined);
  condominioColumn?.setFilterValue(undefined);
  statoColumn?.setFilterValue(undefined);

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
        v-if="statoColumn"
        :column="statoColumn"
        :title="trans('documenti.table.status')"
        :options="opzioniStato"
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
      class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-slate-900 dark:bg-slate-700 border border-slate-800 shadow-sm text-xs font-medium text-white hover:bg-slate-800 dark:hover:bg-slate-600 transition-colors"
    >
      <Plus class="w-3.5 h-3.5 text-green-500" />
      <span>{{ trans('documenti.actions.new_document') }}</span>
    </Link>

    <Link
      as="button"
      :href="route(generateRoute('categorie.index'))"
      class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-slate-900 dark:bg-slate-700 border border-slate-800 shadow-sm text-xs font-medium text-white hover:bg-slate-800 dark:hover:bg-slate-600 transition-colors"
    >
      <List class="w-3.5 h-3.5 text-blue-400" />
      <span>{{ trans('documenti.actions.list_categories') }}</span>
    </Link>
  </div>
</div>
</template>
