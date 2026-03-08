<script setup lang="ts">
import { computed, reactive, onMounted, watch } from 'vue';
import { usePage, Head, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import DataTable from '@/components/eventi/DataTable.vue';
import EventiStats from '@/components/eventi/EventiStats.vue';
import Alert from '@/components/Alert.vue';
import { columns } from '@/components/eventi/columns';
import { usePermission } from "@/composables/permissions";
import { trans } from 'laravel-vue-i18n';

// Icone mirate per la sezione agenda/eventi
import { CalendarDays, BellRing, ListChecks } from 'lucide-vue-next';

import type { Flash } from '@/types/flash';
import type { Evento, Stats } from '@/types/eventi';
import type { PaginationMeta } from '@/types/pagination';

const props = defineProps<{
  eventi: Evento[],
  stats: Stats,
  meta: PaginationMeta,
  filters: Record<string, any>
}>();

const { generateRoute } = usePermission();
const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

// Gestione Filtri
const filters = reactive({ ...props.filters });

function setFilter(range: { date_from: string; date_to: string }) {
  filters.date_from = range.date_from;
  filters.date_to = range.date_to;
  filters.page = 1;

  router.get(route(generateRoute('eventi.index')), filters, {
    preserveScroll: true,
    preserveState: true,
  });
}

// Array vuoto per forzare l'header in modalità compatta
const breadcrumbs: never[] = [];

// Guide reattive per l'agenda
const pageGuides = computed(() => [
  {
    title: trans('eventi.guides.calendar_title'),
    description: trans('eventi.guides.calendar_desc'),
    icon: CalendarDays,
    colorVariant: 'blue' as const
  },
  {
    title: trans('eventi.guides.reminders_title'),
    description: trans('eventi.guides.reminders_desc'),
    icon: BellRing,
    colorVariant: 'amber' as const
  },
  {
    title: trans('eventi.guides.tracking_title'),
    description: trans('eventi.guides.tracking_desc'),
    icon: ListChecks,
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
  <Head :title="trans('eventi.header.list_events_head')" />

  <AppLayout :breadcrumbs="breadcrumbs">
    <div class="px-6 py-8 space-y-6">
      
      <PageHeaderGuide
        :page-title="trans('eventi.header.list_events_title')"
        :page-subtitle="trans('eventi.header.list_events_description')"
        :guides="pageGuides"
        :breadcrumbs="breadcrumbs"
        :video-url="null"
      />

      <EventiStats :stats="stats" @setFilter="setFilter" />

      <div class="w-full">
        <section class="w-full">
          <div v-if="flashMessage" class="py-3">
            <Alert :message="flashMessage.message" :type="flashMessage.type" />
          </div>

          <div class="border border-slate-200 dark:border-slate-800 rounded-2xl bg-white dark:bg-slate-950 overflow-hidden shadow-sm p-4 mt-2">
            <DataTable :columns="columns" :data="eventi" :meta="meta"/>
          </div>
        </section>
      </div>
      
    </div>
  </AppLayout>
</template>
