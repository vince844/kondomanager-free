<script setup lang="ts">
import { computed } from "vue";
import { Head, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import StrutturaLayout from '@/layouts/gestionale/StrutturaLayout.vue';
import DataTable from '@/components/gestionale/palazzine/DataTable.vue';
import { getColumns } from '@/components/gestionale/palazzine/columns';
import Alert from "@/components/Alert.vue";
import { usePermission } from "@/composables/permissions";
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Building, PieChart, Layers } from 'lucide-vue-next';
import type { Flash } from '@/types/flash';
import type { Palazzina } from '@/types/gestionale/palazzine';
import type { Building as BuildingType } from '@/types/buildings';
import type { PaginationMeta } from '@/types/pagination';

const props = defineProps<{
  condominio: BuildingType;
  condomini: BuildingType[];
  palazzine: Palazzina[];
  meta: PaginationMeta;
}>()

const { generatePath } = usePermission();

const columns = computed(() => getColumns(props.condominio));

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

// Breadcrumbs testuali per il nuovo componente Header
const headerBreadcrumbs = computed(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: 'Struttura', href: '#' },
  { title: 'Elenco Palazzine' }
]);

// Configurazione della guida per le Palazzine
const pageGuides = [
  {
    title: 'Complessi Multipli',
    description: 'Gestisci super-condomini suddividendoli in palazzine, scale o blocchi indipendenti per mantenere l\'anagrafica ordinata.',
    icon: Building,
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

    <div class="px-6 py-8 space-y-4">
      
      <PageHeaderGuide
        page-title="Gestione palazzine"
        page-subtitle="Definisci i blocchi fisici che compongono il condominio. Una struttura ben organizzata semplifica le ripartizioni millesimali."
        :guides="pageGuides"
        :breadcrumbs="headerBreadcrumbs"
        :video-url="null /* 'https://youtube.com/...' */"
        :condominio="props.condominio"
        :condomini="props.condomini"
      >
      </PageHeaderGuide>

      <div class="w-full">
        <StrutturaLayout>

          <div class="container mx-auto p-0 mt-4">
            
            <div v-if="flashMessage">
                <Alert :message="flashMessage.message" :type="flashMessage.type" />
            </div>

            <div>
              <DataTable 
                :columns="columns" 
                :data="props.palazzine" 
                :meta="props.meta" 
                :condominio="props.condominio"
              />
            </div>

          </div>

        </StrutturaLayout>
      </div>

    </div>

  </GestionaleLayout>
</template>