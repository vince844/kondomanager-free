<script setup lang="ts">
import { computed } from "vue";
import { Head, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import StrutturaLayout from '@/layouts/gestionale/StrutturaLayout.vue';
import DataTable from '@/components/gestionale/scale/DataTable.vue';
import { getColumns } from '@/components/gestionale/scale/columns';
import Alert from "@/components/Alert.vue";
import { usePermission } from "@/composables/permissions";
import { trans } from 'laravel-vue-i18n';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';

// Icone mirate per la gestione delle scale e ripartizioni
import { ListTree, ArrowUpDown, PieChart } from 'lucide-vue-next';

import type { Flash } from '@/types/flash';
import type { Scala } from '@/types/gestionale/scale';
import type { Building } from '@/types/buildings';
import type { PaginationMeta } from '@/types/pagination';

const props = defineProps<{
  condominio: Building;
  condomini: Building[];
  scale: Scala[];
  meta: PaginationMeta;
}>()

const { generatePath } = usePermission();

const columns = computed(() => getColumns(props.condominio));

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

// Breadcrumbs testuali per il nuovo componente Header
const headerBreadcrumbs = computed(() => [
  { title: trans('gestionale.list_pages.scale.breadcrumbs.management'), href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: trans('gestionale.list_pages.scale.breadcrumbs.structure'), href: '#' },
  { title: trans('gestionale.list_pages.scale.breadcrumbs.list') }
]);

// Configurazione della guida per le Scale
const pageGuides = [
  {
    title: trans('gestionale.list_pages.scale.guides.internal_title'),
    description: trans('gestionale.list_pages.scale.guides.internal_description'),
    icon: ListTree,
    colorVariant: 'blue' as const
  },
  {
    title: trans('gestionale.list_pages.scale.guides.elevator_title'),
    description: trans('gestionale.list_pages.scale.guides.elevator_description'),
    icon: ArrowUpDown,
    colorVariant: 'emerald' as const
  },
  {
    title: trans('gestionale.list_pages.scale.guides.isolation_title'),
    description: trans('gestionale.list_pages.scale.guides.isolation_description'),
    icon: PieChart,
    colorVariant: 'amber' as const
  }
];
</script>

<template>
  <Head :title="trans('gestionale.list_pages.scale.head_title')" />

  <GestionaleLayout>

    <div class="px-6 py-8 space-y-4">
      
      <PageHeaderGuide
        :page-title="trans('gestionale.list_pages.scale.page_title')"
        :page-subtitle="trans('gestionale.list_pages.scale.page_subtitle')"
        :guides="pageGuides"
        :breadcrumbs="headerBreadcrumbs"
        :video-url="null /* 'https://youtube.com/...' */"
        :condominio="props.condominio"
        :condomini="props.condomini"
      >
      </PageHeaderGuide>

      <div class="w-full">
        <StrutturaLayout>

          <div class="container mx-auto">
            
            <div v-if="flashMessage">
                <Alert :message="flashMessage.message" :type="flashMessage.type" />
            </div>

            <div class="border border-slate-200 dark:border-slate-800 rounded-2xl bg-white dark:bg-slate-950 overflow-hidden shadow-sm p-4">
              <DataTable 
                :columns="columns" 
                :data="props.scale" 
                :meta="props.meta" 
                :condominio="props.condominio"
              />
            </div>

          </div>

        </StrutturaLayout>
      </div>

    </div>

  </GestionaleLayout>
</template>
