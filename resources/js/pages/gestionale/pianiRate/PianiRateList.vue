<script setup lang="ts">
import { computed } from "vue";
import { Head, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import DataTable from '@/components/gestionale/pianiRate/DataTable.vue';
import Alert from "@/components/Alert.vue";
import { usePermission } from "@/composables/permissions";
import { createColumns } from '@/components/gestionale/pianiRate/columns';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { CalendarDays, Layers, Coins } from 'lucide-vue-next';
import { trans } from 'laravel-vue-i18n';
import type { Flash } from '@/types/flash';
import type { PianoRate } from '@/types/gestionale/piani-rate';
import type { Building } from '@/types/buildings';
import type { PaginationMeta } from '@/types/pagination';
import type { Esercizio } from "@/types/gestionale/esercizi";

const props = defineProps<{
  condominio: Building;
  condomini: Building[];
  esercizio: Esercizio;
  esercizi: Esercizio[];
  pianiRate: PianoRate[];
  meta: PaginationMeta;
}>()

const { generatePath } = usePermission();
const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);


// Breadcrumbs testuali per il nuovo componente Header
const headerBreadcrumbs = computed(() => [
  { title: trans('gestionale.piani_rate.breadcrumbs.management'), href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: trans('gestionale.piani_rate.breadcrumbs.rate_plans') }
]);

// Configurazione della guida per i Piani Rate
const pageGuides = computed(() => [
  {
    title: trans('gestionale.piani_rate.guides.issue_installments_title'),
    description: trans('gestionale.piani_rate.guides.issue_installments_description'),
    icon: CalendarDays,
    colorVariant: 'blue' as const
  },
  {
    title: trans('gestionale.piani_rate.guides.integrative_plans_title'),
    description: trans('gestionale.piani_rate.guides.integrative_plans_description'),
    icon: Layers,
    colorVariant: 'amber' as const
  },
  {
    title: trans('gestionale.piani_rate.guides.previous_balances_title'),
    description: trans('gestionale.piani_rate.guides.previous_balances_description'),
    icon: Coins,
    colorVariant: 'emerald' as const
  }
]);
</script>

<template>
  <Head :title="trans('gestionale.piani_rate.head_title')" />

  <GestionaleLayout>
  
    <div class="px-6 py-8 space-y-3">
      
      <PageHeaderGuide
        :page-title="trans('gestionale.piani_rate.page_title')"
        :page-subtitle="trans('gestionale.piani_rate.page_subtitle')"
        :guides="pageGuides"
        :breadcrumbs="headerBreadcrumbs"
        :video-url="null /* 'https://youtube.com/...' */"
        :condominio="props.condominio"
        :condomini="props.condomini"
        :esercizio="props.esercizio"
        :esercizi="props.esercizi"
      >
      </PageHeaderGuide>

      <div class="w-full">
        <section class="w-full space-y-4">
          
          <div v-if="flashMessage">
              <Alert :message="flashMessage.message" :type="flashMessage.type" />
          </div>

          <div class="border border-slate-200 dark:border-slate-800 rounded-2xl bg-white dark:bg-slate-950 overflow-hidden shadow-sm p-4">
             <DataTable 
                :columns="createColumns(props.condominio, props.esercizio)" 
                :meta="props.meta" 
                :condominio="props.condominio"
                :data="props.pianiRate"
              />
          </div>

        </section>
      </div>

    </div>

  </GestionaleLayout>
</template>
