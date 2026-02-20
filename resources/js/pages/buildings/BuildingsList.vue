<script setup lang="ts">

import { computed } from "vue";
import { usePage, Head } from "@inertiajs/vue3";
import DataTable from '@/components/buildings/DataTable.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { columns } from '@/components/buildings/columns';
import Alert from "@/components/Alert.vue";
import { trans } from 'laravel-vue-i18n';
import { Building2, MousePointerClick, FolderPlus } from 'lucide-vue-next';
import type { BreadcrumbItem } from '@/types';
import type { Flash } from '@/types/flash';
import type { Building } from '@/types/buildings';

defineProps<{ 
  buildings: Building[], 
  meta: {
    current_page: number,
    per_page: number,
    last_page: number,
    total: number
  }
}>()

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

const pageGuides = computed(() => [
  {
    title: trans('condomini.guides.portfolio_title'),
    description: trans('condomini.guides.portfolio_desc'),
    icon: Building2,
    colorVariant: 'blue' as const
  },
  {
    title: trans('condomini.guides.quick_access_title'),
    description: trans('condomini.guides.quick_access_desc'),
    icon: MousePointerClick,
    colorVariant: 'emerald' as const
  },
  {
    title: trans('condomini.guides.new_acquisitions_title'),
    description: trans('condomini.guides.new_acquisitions_desc'),
    icon: FolderPlus,
    colorVariant: 'amber' as const
  }
]);

</script>

<template>
  <Head :title="trans('condomini.header.list_buildings_head')" />

  <AppLayout :breadcrumbs="[]"> <div class="px-6 py-8 space-y-4">
      
      <PageHeaderGuide
        :page-title="trans('condomini.header.list_buildings_title')" 
        :page-subtitle="trans('condomini.header.list_buildings_description')"
        :guides="pageGuides"
        :breadcrumbs="[]"
        :video-url="null"
      />

      <div class="w-full">
        <section class="w-full">
          <div v-if="flashMessage" class="py-4">
            <Alert :message="flashMessage.message" :type="flashMessage.type" />
          </div>

          <div class="border border-slate-200 dark:border-slate-800 rounded-2xl bg-white dark:bg-slate-950 overflow-hidden shadow-sm p-4">
            <DataTable :columns="columns" :data="buildings" :meta="meta" /> 
          </div>
        </section>
      </div>

    </div>
  </AppLayout> 
</template>