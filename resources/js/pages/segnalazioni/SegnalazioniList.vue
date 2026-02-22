<script setup lang="ts">
import { computed, onMounted, watch } from "vue";
import { usePage, Head } from "@inertiajs/vue3";
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import DataTable from '@/components/segnalazioni/DataTable.vue';
import SegnalazioniStats from '@/components/segnalazioni/SegnalazioniStats.vue';
import Alert from "@/components/Alert.vue";
import { columns } from '@/components/segnalazioni/columns';
import { trans } from 'laravel-vue-i18n';
import { TriangleAlert, Wrench, ShieldCheck } from 'lucide-vue-next';
import type { Flash } from '@/types/flash';
import type { Segnalazione, Stats } from '@/types/segnalazioni';
import type { PaginationMeta } from '@/types/pagination';

defineProps<{
  segnalazioni: Segnalazione[],
  stats: Stats,
  meta: PaginationMeta
}>();

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

const breadcrumbs: never[] = [];

const pageGuides = computed(() => [
  {
    title: trans('segnalazioni.guides.reports_title'),
    description: trans('segnalazioni.guides.reports_desc'),
    icon: TriangleAlert,
    colorVariant: 'blue' as const,
  },
  {
    title: trans('segnalazioni.guides.workflow_title'),
    description: trans('segnalazioni.guides.workflow_desc'),
    icon: Wrench,
    colorVariant: 'emerald' as const,
  },
  {
    title: trans('segnalazioni.guides.control_title'),
    description: trans('segnalazioni.guides.control_desc'),
    icon: ShieldCheck,
    colorVariant: 'amber' as const,
  },
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
  <Head :title="trans('segnalazioni.header.list_tickets_head')" />

  <AppLayout :breadcrumbs="breadcrumbs">
    <div class="px-6 py-8 space-y-6">
      <PageHeaderGuide
        :page-title="trans('segnalazioni.header.list_tickets_title')"
        :page-subtitle="trans('segnalazioni.header.list_tickets_description')"
        :guides="pageGuides"
        :breadcrumbs="breadcrumbs"
        :video-url="null"
      />

      <SegnalazioniStats :stats="stats" />

      <div class="w-full">
        <section class="w-full">
          <div v-if="flashMessage" class="py-3">
            <Alert :message="flashMessage.message" :type="flashMessage.type" />
          </div>

          <div class="border border-slate-200 dark:border-slate-800 rounded-2xl bg-white dark:bg-slate-950 overflow-hidden shadow-sm p-4 mt-2">
            <DataTable :columns="columns" :data="segnalazioni" :meta="meta" />
          </div>
        </section>
      </div>
    </div>
  </AppLayout>
</template>
