<script setup lang="ts">
import { computed, onMounted, watch } from 'vue';
import { usePage, Head } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import DataTable from '@/components/comunicazioni/DataTable.vue';
import ComunicazioniStats from '@/components/comunicazioni/ComunicazioniStats.vue';
import Alert from '@/components/Alert.vue';
import { columns } from '@/components/comunicazioni/columns';
import { trans } from 'laravel-vue-i18n';
import { Mail, BellRing, History } from 'lucide-vue-next';
import type { Flash } from '@/types/flash';
import type { Comunicazione, Stats } from '@/types/comunicazioni';
import type { PaginationMeta } from '@/types/pagination';

defineProps<{
  comunicazioni: Comunicazione[],
  stats: Stats,
  meta: PaginationMeta
}>();

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

const breadcrumbs: never[] = [];

const pageGuides = computed(() => [
  {
    title: trans('comunicazioni.guides.tracking_title'),
    description: trans('comunicazioni.guides.tracking_desc'),
    icon: Mail,
    colorVariant: 'blue' as const
  },
  {
    title: trans('comunicazioni.guides.priority_title'),
    description: trans('comunicazioni.guides.priority_desc'),
    icon: BellRing,
    colorVariant: 'amber' as const
  },
  {
    title: trans('comunicazioni.guides.history_title'),
    description: trans('comunicazioni.guides.history_desc'),
    icon: History,
    colorVariant: 'slate' as const
  }
]);

const scrollToTop = () => window.scrollTo({ top: 0, behavior: 'smooth' });

onMounted(() => {
  if (flashMessage.value) scrollToTop();
});

watch(flashMessage, (newValue) => {
  if (newValue) scrollToTop();
});
</script>

<template>
  <Head :title="trans('comunicazioni.header.list_communications_head')" />

  <AppLayout :breadcrumbs="breadcrumbs">
    <div class="px-6 py-8 space-y-6">
      <PageHeaderGuide
        :page-title="trans('comunicazioni.header.list_communications_title')"
        :page-subtitle="trans('comunicazioni.header.list_communications_description')"
        :guides="pageGuides"
        :breadcrumbs="breadcrumbs"
        :video-url="null"
      />

      <ComunicazioniStats :stats="stats" />

      <div class="w-full">
        <section class="w-full">
          <div v-if="flashMessage" class="py-3">
            <Alert :message="flashMessage.message" :type="flashMessage.type" />
          </div>

          <div class="border border-slate-200 dark:border-slate-800 rounded-2xl bg-white dark:bg-slate-950 overflow-hidden shadow-sm p-4 mt-2">
            <DataTable :columns="columns" :data="comunicazioni" :meta="meta" />
          </div>
        </section>
      </div>
    </div>
  </AppLayout>
</template>
