<script setup lang="ts">
import { computed } from "vue";
import { Head, usePage, Link } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import ImmobileLayout from '@/layouts/gestionale/ImmobileLayout.vue';
import DataTable from '@/components/gestionale/immobili/documenti/DataTable.vue';
import { createColumns } from '@/components/gestionale/immobili/documenti/columns'
import Alert from "@/components/Alert.vue";
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { usePermission } from "@/composables/permissions";
import { Files, ShieldCheck, Share2, UploadCloud, List } from 'lucide-vue-next';
import { trans } from 'laravel-vue-i18n';
import type { BreadcrumbItem } from '@/types';
import type { Flash } from '@/types/flash';
import type { Building } from '@/types/buildings';
import type { Immobile } from '@/types/gestionale/immobili';
import type { Documento } from '@/types/documenti';
import type { PaginationMeta } from '@/types/pagination';

const props = defineProps<{
  condominio: Building;
  immobile: Immobile;
  documenti: Documento[];
  meta: PaginationMeta;
}>()
 
const { generatePath, generateRoute } = usePermission();

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: trans('gestionale.list_pages.immobili.breadcrumbs.management'), href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, href: '#' },
  { title: trans('gestionale.list_pages.immobili.breadcrumbs.list'), href: generatePath('gestionale/:condominio/immobili', { condominio: props.condominio.id }) },
  { title: props.immobile.nome, href: generatePath('gestionale/:condominio/immobili/:immobile', { condominio: props.condominio.id, immobile: props.immobile.id }) },
  { title: trans('gestionale.list_pages.immobili.documents.breadcrumb'), href: '#' },
]);

const pageGuides = computed(() => [
  {
    title: trans('gestionale.list_pages.immobili.documents.guides.dossier_title'),
    description: trans('gestionale.list_pages.immobili.documents.guides.dossier_description'),
    icon: Files,
    colorVariant: 'blue' as const
  },
  {
    title: trans('gestionale.list_pages.immobili.documents.guides.security_title'),
    description: trans('gestionale.list_pages.immobili.documents.guides.security_description'),
    icon: ShieldCheck,
    colorVariant: 'emerald' as const
  },
  {
    title: trans('gestionale.list_pages.immobili.documents.guides.sharing_title'),
    description: trans('gestionale.list_pages.immobili.documents.guides.sharing_description'),
    icon: Share2,
    colorVariant: 'amber' as const
  }
]);
</script>

<template>
  <Head :title="trans('gestionale.list_pages.immobili.documents.head_title')" />

  <GestionaleLayout>
    <div class="px-6 py-8 space-y-4">

      <PageHeaderGuide
        :page-title="trans('gestionale.list_pages.immobili.documents.page_title')"
        :page-subtitle="trans('gestionale.list_pages.immobili.documents.page_subtitle_named', { name: props.immobile.nome })"
        :guides="pageGuides"
        :breadcrumbs="breadcrumbs"
      >
        <template #actions>
          <div class="flex items-center gap-2">
            <Link
              :href="generatePath('gestionale/:condominio/immobili', { condominio: props.condominio.id })"
              class="inline-flex h-8 items-center justify-center gap-2 rounded-md bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 px-4 text-[10px] font-bold uppercase tracking-widest text-slate-700 dark:text-slate-300 shadow-sm hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors"
            >
              <List class="w-3.5 h-3.5" />
              <span>{{ trans('gestionale.list_pages.immobili.breadcrumbs.list') }}</span>
            </Link>

            <Link
              :href="route(generateRoute('gestionale.immobili.documenti.create'), { condominio: props.condominio.id, immobile: props.immobile.id })"
              class="inline-flex h-8 items-center justify-center gap-2 rounded-md shadow px-4 bg-primary text-[10px] font-bold uppercase tracking-widest text-primary-foreground hover:bg-primary/90 transition-colors"
            >
              <UploadCloud class="w-3.5 h-3.5" />
              <span>{{ trans('gestionale.list_pages.immobili.documents.actions.upload_file') }}</span>
            </Link>
          </div>
        </template>
      </PageHeaderGuide>

      <ImmobileLayout>

        <div v-if="flashMessage" class="py-3">
            <Alert :message="flashMessage.message" :type="flashMessage.type" />
        </div>

        <div class="container mx-auto p-0 mt-2">
          <DataTable 
            :columns="createColumns(props.condominio, props.immobile)" 
            :data="props.documenti" 
            :meta="props.meta" 
            :condominio="props.condominio"
            :immobile="props.immobile"
          />
        </div> 

      </ImmobileLayout>

    </div>
  </GestionaleLayout>
</template>
