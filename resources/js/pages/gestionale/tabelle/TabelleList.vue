<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">

import { computed, h } from "vue";
import { Head, usePage, Link } from "@inertiajs/vue3";
import GestionaleLayout from "@/layouts/GestionaleLayout.vue";
import DataTable from "@/components/gestionale/tabelle/DataTable.vue";
import { getColumns } from "@/components/gestionale/tabelle/columns";
import Alert from "@/components/Alert.vue";
import { usePermission } from "@/composables/permissions";
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { PieChart, Scale, TableProperties, Plus } from 'lucide-vue-next';
import type { Flash } from "@/types/flash";
import type { Tabella } from "@/types/gestionale/tabelle";
import type { Building } from "@/types/buildings";
import type { PaginationMeta } from "@/types/pagination";
import { BADGE, CLASSI_DEL_BADGE } from '@/lib/assemblee/assemblee';

const props = defineProps<{
  condominio: Building;
  condomini: Building[];
  tabelle: Tabella[];
  meta: PaginationMeta;
  /** Le tabelle segnate «di un gruppo» (Coda 734): il badge accanto al nome. */
  gruppi?: number[];
}>();

const { generatePath, generateRoute } = usePermission();

// Il badge «Di un gruppo» accanto al nome della tabella segnata (Coda 734): la colonna del nome resta quella di sempre, con il badge a fianco.
const columns = computed(() => getColumns(props.condominio).map((c) => {
  if (!('accessorKey' in c) || c.accessorKey !== 'nome' || typeof c.cell !== 'function') return c;
  const cella = c.cell;
  return { ...c, cell: (ctx: Parameters<typeof cella>[0]) => (props.gruppi ?? []).includes(ctx.row.original.id)
    ? h('div', { class: 'flex items-start gap-2' }, [cella(ctx), h('span', { class: [BADGE, CLASSI_DEL_BADGE.blu, 'mt-2'], 'data-testid': 'badge-di-un-gruppo' }, 'Di un gruppo')])
    : cella(ctx) };
}));

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

// Breadcrumbs testuali per il nuovo componente Header
const headerBreadcrumbs = computed(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: 'Tabelle Millesimali' }
]);

// Configurazione della guida
const pageGuides = [
  {
    title: 'Motore di Riparto',
    description: 'Le tabelle millesimali definiscono le regole matematiche di suddivisione delle spese tra le unità immobiliari del condominio.',
    icon: PieChart,
    colorVariant: 'blue' as const
  },
  {
    title: 'Riparto sul Totale Reale',
    description: 'Il motore ripartisce sempre il 100% della spesa sul totale effettivo dei millesimi della tabella — anche quando non è esattamente 1000 (unità rimosse, arrotondamenti approvati in assemblea): nessun euro perso o duplicato.',
    icon: Scale,
    colorVariant: 'emerald' as const
  },
  {
    title: 'Ripartizione Mista',
    description: 'Crea tabelle specifiche per scale, ascensori, riscaldamento o lastrico solare per gestire le ripartizioni di spesa più complesse.',
    icon: TableProperties,
    colorVariant: 'amber' as const
  }
];
</script>

<template>
  <Head title="Elenco tabelle" />

  <GestionaleLayout>

    <div class="px-6 py-8 space-y-4">
      
      <PageHeaderGuide
        page-title="Tabelle millesimali"
        page-subtitle="Configura i parametri di ripartizione del condominio."
        :guides="pageGuides"
        :breadcrumbs="headerBreadcrumbs"
        :video-url="null /* 'https://youtube.com/...' */"
        :condominio="props.condominio"
        :condomini="props.condomini"
      />

      <div class="w-full">
        <section class="w-full space-y-4">
          <div v-if="flashMessage">
            <Alert :message="flashMessage.message" :type="flashMessage.type" />
          </div>

          <div class="border border-slate-200 dark:border-slate-800 rounded-2xl bg-white dark:bg-slate-950 overflow-hidden shadow-sm p-4">
            <DataTable
              :columns="columns"
              :data="props.tabelle"
              :meta="props.meta"
              :condominio="props.condominio"
            />
          </div>
        </section>
      </div>
    </div>
  </GestionaleLayout>
</template>