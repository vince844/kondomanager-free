<script setup lang="ts">

import { computed } from "vue";
import { Head, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import FornitoreLayout from '@/layouts/fornitori/FornitoreLayout.vue';
import DataTable from '@/components/fornitori/documenti/DataTable.vue';
import { createColumns } from '@/components/fornitori/documenti/columns'
import Alert from "@/components/Alert.vue";
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { usePermission } from "@/composables/permissions";
import { FileText, ShieldCheck, UploadCloud } from 'lucide-vue-next';
import type { Flash } from '@/types/flash';
import type { Fornitore } from '@/types/fornitori';
import type { Documento } from '@/types/documenti';
import type { PaginationMeta } from '@/types/pagination';
import type { BreadcrumbItem } from '@/types';

const props = defineProps<{
  fornitore: Fornitore;
  documenti: Documento[];
  meta: PaginationMeta;
}>()
 
const { generatePath, generateRoute } = usePermission();

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Fornitori', href: route(generateRoute('fornitori.index')) },
  { title: props.fornitore.ragione_sociale, href: generatePath('fornitori/:fornitore', { fornitore: props.fornitore.id }) },
  { title: 'Documenti', href: '#' }
]);

const pageGuides = [
  {
    title: 'Archivio Documentale',
    description: 'Gestisci tutti i documenti relativi al fornitore come contratti, preventivi e certificazioni.',
    icon: FileText,
    colorVariant: 'blue' as const
  },
  {
    title: 'Validità e Scadenze',
    description: 'Tieni sotto controllo le scadenze di documenti importanti per la compliance.',
    icon: ShieldCheck,
    colorVariant: 'amber' as const
  },
  {
    title: 'Condivisione',
    description: 'Carica e condividi in modo sicuro i documenti necessari per la gestione.',
    icon: UploadCloud,
    colorVariant: 'emerald' as const
  }
];

</script>

<template>

  <AppLayout>
    <Head title="Documenti fornitore" />

    <div class="px-6 py-8 space-y-4">
      <div v-if="flashMessage" class="mb-6">
        <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>

      <PageHeaderGuide
        :page-title="`Documenti: ${fornitore.ragione_sociale}`"
        page-subtitle="Gestisci i documenti allegati per questo fornitore"
        :guides="pageGuides"
        :breadcrumbs="breadcrumbs"
        :video-url="null"
        :back-url="generatePath('fornitori/:fornitore', { fornitore: fornitore.id })"
        back-text="Torna ai dettagli"
      />

      <div class="w-full">
        <FornitoreLayout>

          <div class="border border-slate-200 dark:border-slate-800 rounded-2xl bg-white dark:bg-slate-950 overflow-hidden shadow-sm p-4">
            <DataTable 
              :columns="createColumns(props.fornitore)" 
              :data="props.documenti" 
              :fornitore="props.fornitore" 
              :meta="meta" 
            />
          </div>

        </FornitoreLayout>
      </div>
    </div>
  </AppLayout>
</template>
