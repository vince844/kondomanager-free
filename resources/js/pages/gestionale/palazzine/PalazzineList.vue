<script setup lang="ts">
import { computed } from "vue";
import { Head, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import StrutturaLayout from '@/layouts/gestionale/StrutturaLayout.vue';
import DataTable from '@/components/gestionale/palazzine/DataTable.vue';
import { getColumns } from '@/components/gestionale/palazzine/columns';
import Alert from "@/components/Alert.vue";
import { usePermission } from "@/composables/permissions";
import CondominioDropdown from "@/components/CondominioDropdown.vue";
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Building as BuildingIcon, PieChart, Layers } from 'lucide-vue-next';
import { trans } from 'laravel-vue-i18n';
import type { Flash } from '@/types/flash';
import type { Palazzina } from '@/types/gestionale/palazzine';
import type { Building } from '@/types/buildings';
import type { PaginationMeta } from '@/types/pagination';

const props = defineProps<{
  condominio: Building;
  condomini: Building[];
  palazzine: Palazzina[];
  meta: PaginationMeta;
}>()

const { generatePath } = usePermission();

const columns = computed(() => getColumns(props.condominio));

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

// Breadcrumbs testuali per il componente Header
const headerBreadcrumbs = computed(() => [
  { title: trans('gestionale.list_pages.palazzine.breadcrumbs.management'), href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: trans('gestionale.list_pages.palazzine.breadcrumbs.structure'), href: '#' },
  { title: trans('gestionale.list_pages.palazzine.breadcrumbs.list') }
]);

// Configurazione della guida
const pageGuides = [
  {
    title: trans('gestionale.list_pages.palazzine.guides.multi_title'),
    description: trans('gestionale.list_pages.palazzine.guides.multi_description'),
    icon: BuildingIcon,
    colorVariant: 'blue' as const
  },
  {
    title: trans('gestionale.list_pages.palazzine.guides.isolated_title'),
    description: trans('gestionale.list_pages.palazzine.guides.isolated_description'),
    icon: PieChart,
    colorVariant: 'emerald' as const
  },
  {
    title: trans('gestionale.list_pages.palazzine.guides.units_title'),
    description: trans('gestionale.list_pages.palazzine.guides.units_description'),
    icon: Layers,
    colorVariant: 'amber' as const
  }
];
</script>

<template>
  <Head :title="trans('gestionale.list_pages.palazzine.head_title')" />

  <GestionaleLayout>

    <template #breadcrumb-condominio>
      <CondominioDropdown :condominio="props.condominio" :condomini="props.condomini" />
    </template>

    <div class="px-6 py-8 space-y-4">
      
      <PageHeaderGuide
        :page-title="trans('gestionale.list_pages.palazzine.page_title')"
        :page-subtitle="trans('gestionale.list_pages.palazzine.page_subtitle')"
        :guides="pageGuides"
        :breadcrumbs="headerBreadcrumbs"
        :video-url="null /* 'https://youtube.com/...' */"
        :condominio="props.condominio"
        :condomini="props.condomini"
      />

      <div class="w-full">
        <StrutturaLayout>
          <section class="w-full space-y-4">
            
            <div v-if="flashMessage">
                <Alert :message="flashMessage.message" :type="flashMessage.type" />
            </div>

            <div class="border border-slate-200 dark:border-slate-800 rounded-2xl bg-white dark:bg-slate-950 overflow-hidden shadow-sm p-4">
              <DataTable 
                :columns="columns" 
                :data="props.palazzine" 
                :meta="props.meta" 
                :condominio="props.condominio"
              />
            </div>

          </section>
        </StrutturaLayout>
      </div>

    </div>
  </GestionaleLayout>
</template>
