<script setup lang="ts">

import { computed } from "vue";
import { Head, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import DataTable from '@/components/gestionale/immobili/DataTable.vue';
import { getColumns } from '@/components/gestionale/immobili/columns';
import Alert from "@/components/Alert.vue";
import { usePermission } from "@/composables/permissions";
import CondominioDropdown from "@/components/CondominioDropdown.vue";
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Home, UsersRound, Link as LinkIcon } from 'lucide-vue-next';
import type { Flash } from '@/types/flash';
import type { Immobile } from '@/types/gestionale/immobili';
import type { Building } from '@/types/buildings';
import type { PaginationMeta } from '@/types/pagination';

const props = defineProps<{
  condominio: Building;
  condomini: Building[];
  immobili: Immobile[];
  meta: PaginationMeta;
}>()

const { generatePath } = usePermission();

const columns = computed(() => getColumns(props.condominio));

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

// Breadcrumbs testuali per il componente Header
const headerBreadcrumbs = computed(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: 'Unità Immobiliari' }
]);

// Configurazione della guida
const pageGuides = [
  {
    title: 'Anagrafica Unità',
    description: 'Registra i dati catastali e le informazioni principali degli appartamenti, box e locali commerciali che compongono il condominio.',
    icon: Home,
    colorVariant: 'blue' as const
  },
  {
    title: 'Gestione Subentri',
    description: 'Tieni traccia dei cambi di proprietà o inquilino. Il sistema gestirà le successioni calcolando i saldi di competenza per ogni periodo.',
    icon: UsersRound,
    colorVariant: 'amber' as const
  },
  {
    title: 'Associazione Tabelle',
    description: 'Ogni unità sarà successivamente collegata alle tabelle millesimali per permettere al motore contabile di ripartire correttamente le spese.',
    icon: LinkIcon,
    colorVariant: 'emerald' as const
  }
];
</script>

<template>
  <Head title="Elenco immobili" />

  <GestionaleLayout>

    <template #breadcrumb-condominio>
      <CondominioDropdown :condominio="props.condominio" :condomini="props.condomini" />
    </template>

    <div class="px-6 py-8 space-y-4">
      
      <PageHeaderGuide
        page-title="Unità Immobiliari"
        page-subtitle="Gestisci l'anagrafica fisica del condominio. Aggiungi le unità immobiliari e preparati ad assegnare i soggetti (proprietari e inquilini)."
        :guides="pageGuides"
        :breadcrumbs="headerBreadcrumbs"
        :video-url="null /* 'https://youtube.com/...' */"
        :condominio="props.condominio"
        :condomini="props.condomini"
      />

      <div class="w-full">
        <section class="w-full space-y-4">
          <div v-if="flashMessage">
              <Alert :message="flashMessage.message" :type="flashMessage.type" />
          </div>

          <div class="border border-slate-200 dark:border-slate-800 rounded-2xl bg-white dark:bg-slate-950 overflow-hidden shadow-sm p-4">
            <DataTable 
              :columns="columns" 
              :data="props.immobili" 
              :meta="props.meta" 
              :condominio="props.condominio"
            />
          </div>

        </section>
      </div>

    </div>
  </GestionaleLayout>
</template>