<script setup lang="ts">
import { computed, onMounted, watch } from "vue";
import { usePage, Head } from "@inertiajs/vue3";
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import DataTable from '@/components/anagrafiche/DataTable.vue';
import { columns } from '@/components/anagrafiche/columns';
import Alert from "@/components/Alert.vue";
import { trans } from 'laravel-vue-i18n';
import { Users, Link as LinkIcon, ShieldCheck } from 'lucide-vue-next';
import type { Flash } from '@/types/flash';
import type { Anagrafica } from '@/types/anagrafiche';
import type { BreadcrumbItem } from '@/types';

defineProps<{ 
  anagrafiche: Anagrafica[],
  meta: {
    current_page: number,
    per_page: number,
    last_page: number,
    total: number
  } 
}>()

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

// Usiamo il tipo corretto come nel modello fornitori
const breadcrumbs: BreadcrumbItem[] = [];

// Guide reattive per le traduzioni
const pageGuides = computed(() => [
  {
    title: trans('anagrafiche.guides.centralized_book_title'),
    description: trans('anagrafiche.guides.centralized_book_desc'),
    icon: Users,
    colorVariant: 'blue' as const
  },
  {
    title: trans('anagrafiche.guides.building_link_title'),
    description: trans('anagrafiche.guides.building_link_desc'),
    icon: LinkIcon,
    colorVariant: 'emerald' as const
  },
  {
    title: trans('anagrafiche.guides.fiscal_data_title'),
    description: trans('anagrafiche.guides.fiscal_data_desc'),
    icon: ShieldCheck,
    colorVariant: 'amber' as const
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
  <Head :title="trans('anagrafiche.header.list_residents_head')" />

  <AppLayout :breadcrumbs="breadcrumbs">
    <div class="px-6 py-8 space-y-6">
      
      <PageHeaderGuide
        :page-title="trans('anagrafiche.header.list_residents_title')"
        :page-subtitle="trans('anagrafiche.header.list_residents_description')"
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
            <DataTable :columns="columns" :data="anagrafiche" :meta="meta" />
          </div>
        </section>
      </div>

    </div>
  </AppLayout> 
</template>