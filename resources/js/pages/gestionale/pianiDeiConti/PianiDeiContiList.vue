<script setup lang="ts">

import { computed } from "vue";
import { Head, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import DataTable from '@/components/gestionale/pianiDeiConti/DataTable.vue';
import Alert from "@/components/Alert.vue";
import { usePermission } from "@/composables/permissions";
import { createColumns } from '@/components/gestionale/pianiDeiConti/columns'
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Layers, Plus, FolderSync } from 'lucide-vue-next';
import type { Flash } from '@/types/flash';
import type { PianoDeiConti } from '@/types/gestionale/piani-dei-conti';
import type { Building } from '@/types/buildings';
import type { PaginationMeta } from '@/types/pagination';
import type { Esercizio } from "@/types/gestionale/esercizi";

const props = defineProps<{
  condominio: Building;
  condomini: Building[];
  esercizio: Esercizio;
  esercizi: Esercizio[],
  pianiDeiConti: PianoDeiConti[];
  meta: PaginationMeta;
}>()

const { generatePath } = usePermission();

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

// Breadcrumbs testuali per il componente Header
const headerBreadcrumbs = computed(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: 'Elenco Piani Conti' }
]);

// Configurazione della guida per QUESTA specifica pagina
const pageGuides = [
  {
    title: 'Struttura 1 a 1',
    description: 'Ogni gestione ha un solo Piano dei Conti. Tutte le voci di spesa di competenza devono risiedere qui.',
    icon: Layers,
    colorVariant: 'blue' as const
  },
  {
    title: 'Piani Integrativi',
    description: 'Per aggiungere nuove spese, aggiorna il piano esistente creando un piano rate integrativo. Non creare un nuovo piano dei conti.',
    icon: Plus,
    colorVariant: 'amber' as const
  },
  {
    title: 'Casi Straordinari',
    description: 'Crea una nuova Gestione (es. Lavori Straordinari) solo se devi gestire fondi e rendicontazioni separati da quella ordinaria.',
    icon: FolderSync,
    colorVariant: 'emerald' as const
  }
];

</script>

<template>
  <Head title="Elenco piani conti" />

  <GestionaleLayout>

    <div class="px-6 py-8 space-y-4">

      <PageHeaderGuide
         page-title="Piani dei conti"
        page-subtitle="Configura la struttura delle voci di spesa (preventivo) per organizzare il budget delle gestioni del condominio."
        :guides="pageGuides"
        :breadcrumbs="headerBreadcrumbs"
        :video-url="null /* 'https://youtube.com/...' */"
        :condominio="props.condominio"
        :condomini="props.condomini"
        :esercizio="props.esercizio"
        :esercizi="props.esercizi"
      />
        
      <div class="w-full">
        <section class="w-full">
          <div v-if="flashMessage" class="py-3">
              <Alert :message="flashMessage.message" :type="flashMessage.type" />
          </div>

          <div class="container mx-auto p-0">
             <div class="border border-slate-200 dark:border-slate-800 rounded-xl bg-white dark:bg-slate-950 overflow-hidden shadow-sm p-3">
                 <DataTable 
                    :key="props.condominio.id + '-' + (props.esercizio?.id || '0')"
                    :columns="createColumns(props.condominio, props.esercizio)" 
                    :meta="props.meta" 
                    :condominio="props.condominio"
                    :data="props.pianiDeiConti"
                  />
             </div>
          </div>
        </section>
      </div>

    </div>
  </GestionaleLayout>
</template>