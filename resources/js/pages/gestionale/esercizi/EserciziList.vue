<script setup lang="ts">

import { computed } from "vue";
import { Head, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import DataTable from '@/components/gestionale/esercizi/DataTable.vue';
import { getColumns } from '@/components/gestionale/esercizi/columns';
import Alert from "@/components/Alert.vue";
import { usePermission } from "@/composables/permissions";
import { trans } from 'laravel-vue-i18n';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { CalendarRange, ArrowRightLeft, Lock } from 'lucide-vue-next';
import type { Flash } from '@/types/flash';
import type { Esercizio } from '@/types/gestionale/esercizi';
import type { Building } from '@/types/buildings';
import type { PaginationMeta } from '@/types/pagination';

const props = defineProps<{
  condominio: Building;
  condomini: Building[];
  esercizi: Esercizio[];
  meta: PaginationMeta;
}>()

const { generatePath } = usePermission();

const columns = computed(() => getColumns(props.condominio));

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

// Breadcrumbs testuali per il componente Header
const headerBreadcrumbs = computed(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: 'Elenco Esercizi' }
]);

// Configurazione della guida specifica per gli Esercizi Contabili
const pageGuides = [
  {
    title: 'Ciclo Contabile',
    description: 'L\'Esercizio definisce l\'anno contabile (es. 2024). È il contenitore master che raggruppa tutte le gestioni ordinarie e straordinarie del periodo.',
    icon: CalendarRange,
    colorVariant: 'blue' as const
  },
  {
    title: 'Transizione Fluida',
    description: 'Il sistema ti permette di gestire i mesi a cavallo d\'anno senza interruzioni operative sui pagamenti e sulle fatture.',
    icon: ArrowRightLeft,
    colorVariant: 'emerald' as const
  },
  {
    title: 'Chiusura Automatizzata',
    description: 'Il wizard di fine anno (in arrivo prossimamente) si occuperà di creare il nuovo esercizio e calcolare in automatico i saldi pregressi (Rata 0).',
    icon: Lock, // L'icona del lucchetto qui è perfetta perché suggerisce che la funzione è "bloccata/protetta" per ora
    colorVariant: 'amber' as const
  }
];
</script>

<template>
  <Head :title="trans('gestionale.list_pages.esercizi.head_title')" />

  <GestionaleLayout>

    <div class="px-6 py-8 space-y-4"> 

      <PageHeaderGuide
        :page-title="trans('gestionale.list_pages.esercizi.page_title')"
        :page-subtitle="trans('gestionale.list_pages.esercizi.page_subtitle')"
        :guides="pageGuides"
        :breadcrumbs="headerBreadcrumbs"
        :video-url="null /* TODO: Inserire URL YouTube 'Come gestire gli anni contabili' */"
        :condominio="props.condominio"
        :condomini="props.condomini"
      />
        
      <div class="w-full">
        <section class="w-full">
          <div v-if="flashMessage" class="py-3">
              <Alert :message="flashMessage.message" :type="flashMessage.type" />
          </div>

          <div class="border border-slate-200 dark:border-slate-800 rounded-2xl bg-white dark:bg-slate-950 overflow-hidden shadow-sm p-4">
            <DataTable 
              :columns="columns" 
              :data="props.esercizi" 
              :meta="props.meta" 
              :condominio="props.condominio"
            />
          </div>

        </section>
      </div>

    </div>
  </GestionaleLayout>
</template>
