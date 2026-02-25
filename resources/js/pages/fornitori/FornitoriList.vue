<script setup lang="ts">
import { computed, onMounted, watch } from "vue";
import { usePage, Head } from "@inertiajs/vue3";
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue'; // Il nostro componente standard
import DataTable from '@/components/fornitori/DataTable.vue';
import { columns } from '@/components/fornitori/columns';
import Alert from "@/components/Alert.vue";
import { trans } from 'laravel-vue-i18n';

// Icone Lucide per i Fornitori
import { Truck, ShieldCheck, PlusCircle } from 'lucide-vue-next';

import type { Flash } from '@/types/flash';
import type { Fornitore } from '@/types/fornitori';
import type { BreadcrumbItem } from '@/types';

defineProps<{ 
  fornitori: Fornitore[],
  meta: {
    current_page: number,
    per_page: number,
    last_page: number,
    total: number
  } 
}>()

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

// Array vuoto se vuoi l'header compatto, oppure riempilo per i breadcrumbs
const breadcrumbs: BreadcrumbItem[] = [];

// Definiamo le guide usando lo schema del componente PageHeaderGuide
const pageGuides = computed(() => [
  {
    title: trans('fornitori.guides.portfolio_title'),
    description: trans('fornitori.guides.portfolio_desc'),
    icon: Truck,
    colorVariant: 'blue' as const
  },
  {
    title: trans('fornitori.guides.compliance_title'),
    description: trans('fornitori.guides.compliance_desc'),
    icon: ShieldCheck,
    colorVariant: 'amber' as const
  },
  {
    title: trans('fornitori.guides.management_title'),
    description: trans('fornitori.guides.management_desc'),
    icon: PlusCircle,
    colorVariant: 'emerald' as const
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
  <Head :title="trans('fornitori.header.list_fornitori_title')" />

  <AppLayout :breadcrumbs="breadcrumbs">
    <div class="px-6 py-8 space-y-6">
      
      <PageHeaderGuide
        :page-title="trans('fornitori.header.list_fornitori_title')"
        :page-subtitle="trans('fornitori.header.list_fornitori_description')"
        :guides="pageGuides"
        :breadcrumbs="breadcrumbs"
        :video-url="null" 
      />
    
      <div class="w-full">
        <section class="w-full">
          <div v-if="flashMessage" class="py-3"> 
            <Alert :message="flashMessage.message" :type="flashMessage.type" />
          </div>

          <div class="border border-slate-200 dark:border-slate-800 rounded-2xl bg-white dark:bg-slate-950 overflow-hidden shadow-sm p-4 mt-2">
            <DataTable :columns="columns" :data="fornitori" :meta="meta" />
          </div>
        </section>
      </div>

    </div>
  </AppLayout> 
</template>