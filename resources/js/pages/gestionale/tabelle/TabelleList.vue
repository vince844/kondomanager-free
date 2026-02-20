<script setup lang="ts">

import { computed } from "vue";
import { Head, usePage } from "@inertiajs/vue3";
import GestionaleLayout from "@/layouts/GestionaleLayout.vue";
import DataTable from "@/components/gestionale/tabelle/DataTable.vue";
import { getColumns } from "@/components/gestionale/tabelle/columns";
import Alert from "@/components/Alert.vue";
import { usePermission } from "@/composables/permissions";
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { PieChart, Scale, TableProperties } from 'lucide-vue-next';
import type { Flash } from "@/types/flash";
import type { Tabella } from "@/types/gestionale/tabelle";
import type { Building } from "@/types/buildings";
import type { PaginationMeta } from "@/types/pagination";

const props = defineProps<{
  condominio: Building;
  condomini: Building[];
  tabelle: Tabella[];
  meta: PaginationMeta;
}>();

const { generatePath } = usePermission();

const columns = computed(() => getColumns(props.condominio));

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
    title: 'Validazione Automatica',
    description: 'Il sistema integra un validatore di coerenza che controlla in tempo reale che la somma dei millesimi di ogni tabella sia esattamente 1000.',
    icon: Scale,
    colorVariant: 'emerald' as const
  },
  {
    title: 'Ripartizione Mista',
    description: 'Crea tabelle specifiche per scale, ascensori, riscaldamento o box per gestire le ripartizioni di spesa più complesse in futuro.',
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
        page-subtitle="Configura i parametri di ripartizione del condominio. Il validatore di coerenza ti assisterà per evitare errori matematici."
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