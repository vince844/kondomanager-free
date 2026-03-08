<script setup lang="ts">

import { computed } from "vue";
import { Head, usePage, Link } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import DataTable from '@/components/gestionale/gestioni/DataTable.vue'; 
import { createColumns } from '@/components/gestionale/gestioni/columns'
import Alert from "@/components/Alert.vue";
import { usePermission } from "@/composables/permissions";
import { trans } from 'laravel-vue-i18n';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Network, Split, Infinity } from 'lucide-vue-next';
import type { Flash } from '@/types/flash';
import type { Gestione } from '@/types/gestionale/gestioni';
import type { Building } from '@/types/buildings';
import type { Esercizio } from "@/types/gestionale/esercizi";
import type { PaginationMeta } from '@/types/pagination';

const props = defineProps<{
  condominio: Building;
  esercizio: Esercizio;
  esercizi: Esercizio[],
  condomini: Building[];
  gestioni: Gestione[];
  meta: PaginationMeta;
}>()

const { generatePath } = usePermission();
const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

// Breadcrumbs testuali per il componente Header
const headerBreadcrumbs = computed(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: 'Elenco Gestioni' }
]);

const pageGuides = [
  {
    title: 'Gerarchia Flessibile',
    description: 'Un singolo Esercizio può contenere infinite Gestioni. L\'Ordinaria funge da contenitore principale per l\'anno in corso.',
    icon: Network,
    colorVariant: 'blue' as const
  },
  {
    title: 'Tipologie di Spesa',
    description: 'Separa i fondi in modo netto. Crea gestioni separate per Lavori Straordinari o Riscaldamento.',
    icon: Split,
    colorVariant: 'emerald' as const
  },
  {
    title: 'Durata Senza Limiti',
    description: 'Le gestioni Straordinarie possono avere solo una data di inizio e rimanere aperte su più esercizi contabili.',
    icon: Infinity,
    colorVariant: 'amber' as const
  }
];
</script>

<template>
  <Head :title="trans('gestionale.list_pages.gestioni.head_title')" />

  <GestionaleLayout>

    <div class="px-6 py-8 space-y-4"> 

      <PageHeaderGuide
        :page-title="trans('gestionale.list_pages.gestioni.page_title')"
        :page-subtitle="trans('gestionale.list_pages.gestioni.page_subtitle')"
        :guides="pageGuides"
        :breadcrumbs="headerBreadcrumbs"
        :video-url="null /* 'https://youtube.com/...' */"
        :condominio="props.condominio"
        :condomini="props.condomini"
        :esercizio="props.esercizio"
        :esercizi="props.esercizi"
      />
        
      <div class="w-full">
        <section class="w-full">
          <div v-if="flashMessage" class="py-3">
              <Alert :message="flashMessage.message" :type="flashMessage.type" />
          </div>

          <div class="border border-slate-200 dark:border-slate-800 rounded-2xl bg-white dark:bg-slate-950 overflow-hidden shadow-sm p-4">
             <DataTable 
                :columns="createColumns(props.condominio, esercizio)" 
                :meta="props.meta" 
                :condominio="props.condominio"
                :data="props.gestioni"
              />
          </div>
        </section>
      </div>

    </div>
  </GestionaleLayout>
</template>
