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

// Icone mirate per la sezione segnalazioni/guasti
import { Wrench, ShieldAlert, CheckSquare } from 'lucide-vue-next';

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

// Array vuoto per forzare l'header in modalità compatta
const breadcrumbs: never[] = [];

// Guide reattive per le segnalazioni
const pageGuides = computed(() => [
  {
    title: trans('segnalazioni.guides.management_title'),
    description: trans('segnalazioni.guides.management_desc'),
    icon: Wrench,
    colorVariant: 'blue' as const
  },
  {
    title: trans('segnalazioni.guides.priority_title'),
    description: trans('segnalazioni.guides.priority_desc'),
    icon: ShieldAlert,
    colorVariant: 'amber' as const
  },
  {
    title: trans('segnalazioni.guides.resolution_title'),
    description: trans('segnalazioni.guides.resolution_desc'),
    icon: CheckSquare,
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
            <DataTable :columns="columns" :data="segnalazioni" :meta="meta"/>
          </div> 
        </section>
      </div>
    </div>
  </AppLayout> 
</template>