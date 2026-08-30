<script setup lang="ts">
import { computed } from "vue";
import { Head, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import FornitoreLayout from '@/layouts/fornitori/FornitoreLayout.vue';
import DataTable from '@/components/fornitori/anagrafiche/DataTable.vue';
import { createColumns } from '@/components/fornitori/anagrafiche/columns'
import Alert from "@/components/Alert.vue";
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { usePermission } from "@/composables/permissions";
import { Users, Phone, Key } from 'lucide-vue-next';
import type { Flash } from '@/types/flash';
import type { Fornitore } from '@/types/fornitori';
import type { BreadcrumbItem } from '@/types';

const props = defineProps<{
  fornitore: Fornitore;
}>()

const { generatePath, generateRoute } = usePermission();

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

const breadcrumbs: BreadcrumbItem[] = [
  { title: 'Fornitori', href: route(generateRoute('fornitori.index')) },
  { title: props.fornitore.ragione_sociale, href: generatePath('fornitori/:fornitore', { fornitore: props.fornitore.id }) },
  { title: 'Rappresentanti', href: '#' }
];

const pageGuides = [
  {
    title: 'Rappresentanti aziendali',
    description: 'Gestisci i contatti diretti dell\'azienda. Questi saranno i tuoi interlocutori per richieste e interventi.',
    icon: Users,
    colorVariant: 'blue' as const
  },
  {
    title: 'Amministrazione',
    description: 'Puoi definire contatti specifici per questioni contabili e amministrative.',
    icon: Phone,
    colorVariant: 'amber' as const
  },
  {
    title: 'Accesso Portale',
    description: 'Verifica quali rappresentanti hanno le credenziali per accedere al sistema e interagire in autonomia.',
    icon: Key,
    colorVariant: 'emerald' as const
  }
];

</script>

<template>
  <AppLayout>
    <Head title="Elenco rappresentanti fornitore" />

    <div class="px-6 py-8 space-y-4">
      <div v-if="flashMessage" class="mb-6">
        <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>

      <PageHeaderGuide
        :page-title="`Rappresentanti: ${fornitore.ragione_sociale}`"
        page-subtitle="Gestisci i rappresentanti di questo fornitore e il ruolo di ciascuno"
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
              :data="props.fornitore.referenti ?? []" 
            />
          </div>

        </FornitoreLayout>
      </div>
    </div>
  </AppLayout>
</template>
