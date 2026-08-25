<script setup lang="ts">
import { computed } from 'vue';
import { usePage, Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import DataTable from '@/components/documenti/categories/DataTable.vue';
import { columns } from '@/components/documenti/categories/columns';
import Alert from '@/components/Alert.vue';
import { trans } from 'laravel-vue-i18n';
import { usePermission } from '@/composables/permissions';
import { FolderTree, FileText, ArrowLeft, Search } from 'lucide-vue-next';
import type { Flash } from '@/types/flash';
import type { Categoria } from '@/types/categorie';
import type { PaginationMeta } from '@/types/pagination';
import type { BreadcrumbItem } from '@/types';

const props = defineProps<{
  categorie: Categoria[],
  meta: PaginationMeta
}>()

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

const { generateRoute } = usePermission();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  {
      title: trans('documenti.breadcrumbs.list'), 
      href: route(generateRoute('documenti.index'))
  },
  {
      title: trans('documenti.header.list_categories_head'),
      href: '#',
  }
]);

const pageGuides = computed(() => [
  {
    title: trans('documenti.guides.categories_org_title'),
    description: trans('documenti.guides.categories_org_desc'),
    icon: FolderTree,
    colorVariant: 'blue' as const
  },
  {
    title: trans('documenti.guides.categories_assoc_title'),
    description: trans('documenti.guides.categories_assoc_desc'),
    icon: FileText,
    colorVariant: 'emerald' as const
  },
  {
    title: trans('documenti.guides.categories_search_title'),
    description: trans('documenti.guides.categories_search_desc'),
    icon: Search,
    colorVariant: 'amber' as const
  }
]);
</script>

<template>
  <Head :title="trans('documenti.header.list_categories_head')"/>

  <AppLayout>
    <div class="px-6 py-8 space-y-6">
      
      <PageHeaderGuide
        :page-title="trans('documenti.header.list_categories_title')"
        :page-subtitle="trans('documenti.header.list_categories_description')"
        :breadcrumbs="breadcrumbs"
        :guides="pageGuides"
      >
        <template #actions>
            <Link 
                :href="route(generateRoute('documenti.index'))" 
                class="inline-flex items-center justify-center gap-2 rounded-md bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm text-sm font-medium text-slate-700 dark:text-slate-300 px-3 py-1.5 h-8 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors"
            >
                <ArrowLeft class="w-4 h-4 text-slate-500" />
                <span>{{ trans('documenti.actions.categories.back_to_documents') }}</span>
            </Link>
        </template>
      </PageHeaderGuide>

      <div v-if="flashMessage">
        <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>

      <div class="border border-slate-200 dark:border-slate-800 rounded-2xl bg-white dark:bg-slate-950 overflow-hidden shadow-sm p-4 mt-2">
         <DataTable :columns="columns" :data="categorie" :meta="meta"/>
      </div>

    </div>
  </AppLayout>
</template>