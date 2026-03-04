<script setup lang="ts">
import { computed } from "vue";
import { Head, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import StrutturaLayout from '@/layouts/gestionale/StrutturaLayout.vue';
import DataTable from '@/components/gestionale/palazzine/DataTable.vue';
import { getColumns } from '@/components/gestionale/palazzine/columns';
import Alert from "@/components/Alert.vue";
import { usePermission } from "@/composables/permissions";
import CondominioDropdown from "@/components/CondominioDropdown.vue";
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Building as BuildingIcon, PieChart, Layers } from 'lucide-vue-next';
import type { Flash } from '@/types/flash';
import type { Palazzina } from '@/types/gestionale/palazzine';
import type { Building } from '@/types/buildings';
import type { PaginationMeta } from '@/types/pagination';

const props = defineProps<{
  condominio: Building;
  condomini: Building[];
  palazzine: Palazzina[];
  meta: PaginationMeta;
}>()

const { generatePath } = usePermission();

const columns = computed(() => getColumns(props.condominio));

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

// Breadcrumbs testuali per il componente Header
const headerBreadcrumbs = computed(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: 'Struttura', href: '#' },
  { title: 'Palazzine' }
]);

// Configurazione della guida
const pageGuides = [
  {
    title: 'Complessi Multipli',
    description: 'Gestisci super-condomini suddividendoli in palazzine, scale o blocchi indipendenti per mantenere l\'anagrafica ordinata.',
    icon: BuildingIcon,
    colorVariant: 'blue' as const
  },
  {
    title: 'Spese Isolate',
    description: 'La suddivisione in palazzine è essenziale per applicare tabelle millesimali specifiche (es. manutenzione tetto per un singolo blocco).',
    icon: PieChart,
    colorVariant: 'emerald' as const
  },
  {
    title: 'Raggruppamento Unità',
    description: 'Ogni palazzina conterrà le proprie unità immobiliari (appartamenti, box), semplificando la ricerca e l\'assegnazione delle quote.',
    icon: Layers,
    colorVariant: 'amber' as const
  }
];
</script>

<template>
  <Head title="Elenco palazzine" />

  <GestionaleLayout>

    <template #breadcrumb-condominio>
      <CondominioDropdown :condominio="props.condominio" :condomini="props.condomini" />
    </template>

    <div class="px-6 py-8 space-y-4">
      
      <PageHeaderGuide
        page-title="Gestione palazzine"
        page-subtitle="Definisci i blocchi fisici che compongono il condominio. Una struttura ben organizzata semplifica le ripartizioni millesimali."
        :guides="pageGuides"
        :breadcrumbs="headerBreadcrumbs"
        :video-url="null /* 'https://youtube.com/...' */"
        :condominio="props.condominio"
        :condomini="props.condomini"
      />

      <div class="w-full">
        <StrutturaLayout>
          <section class="w-full space-y-4">
            
            <div v-if="flashMessage">
                <Alert :message="flashMessage.message" :type="flashMessage.type" />
            </div>

            <div class="border border-slate-200 dark:border-slate-800 rounded-2xl bg-white dark:bg-slate-950 overflow-hidden shadow-sm p-4">
              <DataTable 
                :columns="columns" 
                :data="props.palazzine" 
                :meta="props.meta" 
                :condominio="props.condominio"
              />
            </div>

          </section>
        </StrutturaLayout>
      </div>

    </div>
  </GestionaleLayout>
</template>