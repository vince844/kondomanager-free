<script setup lang="ts">
import { computed } from "vue";
import { Head, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import DataTable from '@/components/gestionale/pianiRate/DataTable.vue';
import Alert from "@/components/Alert.vue";
import { usePermission } from "@/composables/permissions";
import { createColumns } from '@/components/gestionale/pianiRate/columns';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { CalendarDays, Layers, Coins } from 'lucide-vue-next';
import type { Flash } from '@/types/flash';
import type { PianoRate } from '@/types/gestionale/piani-rate';
import type { Building } from '@/types/buildings';
import type { PaginationMeta } from '@/types/pagination';
import type { Esercizio } from "@/types/gestionale/esercizi";

const props = defineProps<{
  condominio: Building;
  condomini: Building[];
  esercizio: Esercizio;
  esercizi: Esercizio[];
  pianiRate: PianoRate[];
  meta: PaginationMeta;
}>()

const { generatePath } = usePermission();
const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);


// Breadcrumbs testuali per il nuovo componente Header
const headerBreadcrumbs = computed(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: 'Piani Rate' }
]);

// Configurazione della guida per i Piani Rate
const pageGuides = [
  {
    title: 'Emissione Rate',
    description: 'Pianifica le scadenze per la riscossione delle quote. Puoi creare piani rateizzati per la gestione ordinaria o per fondi straordinari.',
    icon: CalendarDays,
    colorVariant: 'blue' as const
  },
  {
    title: 'Piani Integrativi',
    description: 'Se devi richiedere fondi aggiuntivi in corso d\'anno, crea un piano rate integrativo e associalo al Piano dei Conti esistente.',
    icon: Layers,
    colorVariant: 'amber' as const
  },
  {
    title: 'Gestione Saldi Pregressi',
    description: 'Consigliamo di isolare il "Saldo Iniziale" dell\'anno precedente in una rata separata. Questa best practice è fondamentale per gestire i subentri in modo impeccabile.',
    icon: Coins,
    colorVariant: 'emerald' as const
  }
];
</script>

<template>
  <Head title="Elenco piani rate" />

  <GestionaleLayout>
  
    <div class="px-6 py-8 space-y-3">
      
      <PageHeaderGuide
        page-title="Piani rate"
        page-subtitle="Configura i flussi di incasso. Definisci il numero di rate, le scadenze e gli importi da richiedere ai condòmini."
        :guides="pageGuides"
        :breadcrumbs="headerBreadcrumbs"
        :video-url="null /* 'https://youtube.com/...' */"
        :condominio="props.condominio"
        :condomini="props.condomini"
        :esercizio="props.esercizio"
        :esercizi="props.esercizi"
      >
      </PageHeaderGuide>

      <div class="w-full">
        <section class="w-full space-y-4">
          
          <div v-if="flashMessage">
              <Alert :message="flashMessage.message" :type="flashMessage.type" />
          </div>

          <div class="border border-slate-200 dark:border-slate-800 rounded-2xl bg-white dark:bg-slate-950 overflow-hidden shadow-sm p-4">
             <DataTable 
                :columns="createColumns(props.condominio, props.esercizio)" 
                :meta="props.meta" 
                :condominio="props.condominio"
                :data="props.pianiRate"
              />
          </div>

        </section>
      </div>

    </div>

  </GestionaleLayout>
</template>