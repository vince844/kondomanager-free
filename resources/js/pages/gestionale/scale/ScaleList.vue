<script setup lang="ts">
import { computed } from "vue";
import { Head, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import StrutturaLayout from '@/layouts/gestionale/StrutturaLayout.vue';
import DataTable from '@/components/gestionale/scale/DataTable.vue';
import { getColumns } from '@/components/gestionale/scale/columns';
import Alert from "@/components/Alert.vue";
import { usePermission } from "@/composables/permissions";
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';

// Icone mirate per la gestione delle scale e ripartizioni
import { ListTree, ArrowUpDown, PieChart } from 'lucide-vue-next';

import type { Flash } from '@/types/flash';
import type { Scala } from '@/types/gestionale/scale';
import type { Building } from '@/types/buildings';
import type { PaginationMeta } from '@/types/pagination';

const props = defineProps<{
  condominio: Building;
  condomini: Building[];
  scale: Scala[];
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
  { title: 'Elenco Scale' }
]);

// Configurazione della guida per le Scale
const pageGuides = [
  {
    title: 'Suddivisione Interna',
    description: 'Raggruppa le unità immobiliari in base alla rampa di scale o all\'ingresso, creando un albero strutturale chiaro e ordinato.',
    icon: ListTree,
    colorVariant: 'blue' as const
  },
  {
    title: 'Ripartizioni Ascensore',
    description: 'La divisione in scale è un pre-requisito vitale per poter applicare correttamente le tabelle millesimali per pulizia e manutenzione ascensore.',
    icon: ArrowUpDown,
    colorVariant: 'emerald' as const
  },
  {
    title: 'Isolamento Spese',
    description: 'Permette di addebitare spese di riparazione specifiche (es. sostituzione plafoniere o citofoni) esclusivamente ai condòmini della singola scala.',
    icon: PieChart,
    colorVariant: 'amber' as const
  }
];
</script>

<template>
  <Head title="Elenco scale" />

  <GestionaleLayout>

    <div class="px-6 py-8 space-y-4">
      
      <PageHeaderGuide
        page-title="Gestione scale"
        page-subtitle="Definisci gli ingressi e le rampe di scale. Questa organizzazione è essenziale per le ripartizioni millesimali parziali."
        :guides="pageGuides"
        :breadcrumbs="headerBreadcrumbs"
        :video-url="null /* 'https://youtube.com/...' */"
        :condominio="props.condominio"
        :condomini="props.condomini"
      >
      </PageHeaderGuide>

      <div class="w-full">
        <StrutturaLayout>

          <div class="container mx-auto">
            
            <div v-if="flashMessage">
                <Alert :message="flashMessage.message" :type="flashMessage.type" />
            </div>

            <div class="border border-slate-200 dark:border-slate-800 rounded-2xl bg-white dark:bg-slate-950 overflow-hidden shadow-sm p-4">
              <DataTable 
                :columns="columns" 
                :data="props.scale" 
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