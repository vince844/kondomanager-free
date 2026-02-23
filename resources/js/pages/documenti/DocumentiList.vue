<script setup lang="ts">
import { computed, onMounted, watch } from 'vue';
import { usePage, Head } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import DataTable from '@/components/documenti/DataTable.vue';
import DocumentiStats from '@/components/documenti/DocumentiStats.vue';
import Alert from '@/components/Alert.vue';
import { columns } from '@/components/documenti/columns';
import { trans } from 'laravel-vue-i18n';

// Icone per il modulo documenti
import { FileText, FolderOpen, ShieldCheck } from 'lucide-vue-next';

import type { Flash } from '@/types/flash';
import type { Documento, Stats } from '@/types/documenti';
import type { PaginationMeta } from '@/types/pagination';

defineProps<{
  documenti: Documento[],
  stats: Stats,
  meta: PaginationMeta
}>();

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

// Array vuoto per forzare l'header in modalità compatta
const breadcrumbs: never[] = [];

// Guide reattive per i documenti
const pageGuides = computed(() => [
  {
    title: trans('documenti.guides.storage_title'),
    description: trans('documenti.guides.storage_desc'),
    icon: FileText,
    colorVariant: 'blue' as const
  },
  {
    title: trans('documenti.guides.organization_title'),
    description: trans('documenti.guides.organization_desc'),
    icon: FolderOpen,
    colorVariant: 'amber' as const
  },
  {
    title: trans('documenti.guides.privacy_title'),
    description: trans('documenti.guides.privacy_desc'),
    icon: ShieldCheck,
    colorVariant: 'emerald' as const
  }
]);

// Scroll in alto automatico quando compare un messaggio flash
const scrollToTop = () => window.scrollTo({ top: 0, behavior: 'smooth' });

onMounted(() => {
  if (flashMessage.value) scrollToTop();
});

watch(flashMessage, (newValue) => {
  if (newValue) scrollToTop();
});
</script>

<template>
  <Head :title="trans('documenti.header.list_documents_head')" />

  <AppLayout :breadcrumbs="breadcrumbs">
    <div class="px-6 py-8 space-y-6">
      
      <PageHeaderGuide
        :page-title="trans('documenti.header.list_documents_title')"
        :page-subtitle="trans('documenti.header.list_documents_description')"
        :guides="pageGuides"
        :breadcrumbs="breadcrumbs"
        :video-url="null"
      />

      <DocumentiStats :stats="stats" />

      <div class="w-full">
        <section class="w-full">
          <div v-if="flashMessage" class="py-3">
            <Alert :message="flashMessage.message" :type="flashMessage.type" />
          </div>

          <div class="border border-slate-200 dark:border-slate-800 rounded-2xl bg-white dark:bg-slate-950 overflow-hidden shadow-sm p-4 mt-2">
             <DataTable :columns="columns" :data="documenti" :meta="meta"/>
          </div>
        </section>
      </div>

    </div>
  </AppLayout>
</template>