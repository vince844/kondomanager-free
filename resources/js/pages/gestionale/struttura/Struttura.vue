<script setup lang="ts">
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import StrutturaLayout from '@/layouts/gestionale/StrutturaLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { usePermission } from "@/composables/permissions";
import { FileText, MapPin, ClipboardList } from 'lucide-vue-next';
import type { Building } from "@/types/buildings";

const props = defineProps<{
  condominio: Building;
  condomini: Building[];
}>();

const { generatePath } = usePermission();

// Condominio data
const condominio = computed<Building>(() => props.condominio);

// Breadcrumbs testuali per il nuovo componente Header
const headerBreadcrumbs = computed(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: 'Struttura', href: '#' },
  { title: 'Dettagli Fabbricato' }
]);

// Configurazione della guida per l'Anagrafica
const pageGuides = [
  {
    title: 'Dati Fiscali',
    description: 'Mantieni sempre aggiornato il Codice Fiscale. È il dato fondamentale per la generazione delle Certificazioni Uniche (CU) e dei modelli F24.',
    icon: FileText,
    colorVariant: 'blue' as const
  },
  {
    title: 'Dati Catastali',
    description: 'I dati catastali (Foglio, Particella, Sezione) sono necessari per le pratiche di detrazione fiscale sui lavori straordinari.',
    icon: MapPin,
    colorVariant: 'emerald' as const
  },
  {
    title: 'Note Operative',
    description: 'Usa le note per appuntare informazioni cruciali per i fornitori: codici di accesso, posizione dei contatori o orari della portineria.',
    icon: ClipboardList,
    colorVariant: 'amber' as const
  }
];
</script>

<template>
  <Head title="Dettagli condominio" />

  <GestionaleLayout>

    <div class="px-6 py-8 space-y-4">

      <PageHeaderGuide
        page-title="Dettagli fabbricato"
        page-subtitle="Consulta le informazioni generali, i dati catastali e le note operative. Assicurati che i dati fiscali siano sempre aggiornati."
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
            
            <div class="bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800/80 rounded-xl shadow-sm overflow-hidden mb-6">
              
              <div class="grid grid-cols-1 md:grid-cols-2 divide-y md:divide-y-0 md:divide-x divide-slate-100 dark:divide-slate-800/60">
                
                <div class="p-6 lg:p-8">
                  <div class="mb-5">
                    <h3 class="text-base font-bold text-slate-900 dark:text-white">{{ condominio.nome }}</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Dettagli anagrafici e fiscali del fabbricato.</p> 
                  </div>

                  <div class="flex flex-col gap-2">
                    <div class="text-[13px] py-0.5">
                      <span class="font-bold text-slate-900 dark:text-white">Indirizzo:</span>
                      <span class="text-slate-700 dark:text-slate-300 capitalize ml-1">{{ condominio.indirizzo || '-' }}</span>
                    </div>

                    <div class="text-[13px] py-0.5 flex items-center flex-wrap">
                      <span class="font-bold text-slate-900 dark:text-white mr-2">Codice Fiscale:</span>
                      <span class="font-mono text-slate-700 dark:text-slate-300 bg-slate-100 dark:bg-slate-800 px-1.5 py-0.5 rounded">{{ condominio.codice_fiscale ?? '-' }}</span>
                    </div>

                    <div class="text-[13px] py-0.5">
                      <span class="font-bold text-slate-900 dark:text-white">Cod. Identificativo:</span>
                      <span class="text-slate-700 dark:text-slate-300 ml-1">{{ condominio.codice_identificativo ?? '-' }}</span>
                    </div>
                  </div>
                </div>

                <div class="p-6 lg:p-8 bg-slate-50/50 dark:bg-slate-900/20">
                  <div class="mb-5">
                    <h3 class="text-base font-bold text-slate-900 dark:text-white">Riferimenti Catastali</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Dati necessari per pratiche e detrazioni.</p>
                  </div>
                  
                  <div class="flex flex-col gap-2">
                    <div class="text-[13px] py-0.5">
                      <span class="font-bold text-slate-900 dark:text-white">Comune Catasto:</span>
                      <span class="text-slate-700 dark:text-slate-300 ml-1">{{ condominio.comune_catasto ?? '-' }}</span>
                    </div>

                    <div class="text-[13px] py-0.5">
                      <span class="font-bold text-slate-900 dark:text-white">Codice Catasto:</span>
                      <span class="text-slate-700 dark:text-slate-300 ml-1">{{ condominio.codice_catasto ?? '-'}}</span>
                    </div>

                    <div class="text-[13px] py-0.5">
                      <span class="font-bold text-slate-900 dark:text-white">Sezione:</span>
                      <span class="text-slate-700 dark:text-slate-300 ml-1">{{ condominio.sezione_catasto ?? '-' }}</span>
                    </div>

                    <div class="text-[13px] py-0.5">
                      <span class="font-bold text-slate-900 dark:text-white">Foglio:</span>
                      <span class="text-slate-700 dark:text-slate-300 ml-1">{{ condominio.foglio_catasto ?? '-' }}</span>
                    </div>

                    <div class="text-[13px] py-0.5">
                      <span class="font-bold text-slate-900 dark:text-white">Particella:</span>
                      <span class="text-slate-700 dark:text-slate-300 ml-1">{{ condominio.particella_catasto ?? '-' }}</span>
                    </div>
                  </div>
                </div>

              </div>
            </div>

            <div class="bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800/80 rounded-xl shadow-sm p-6 lg:p-8">
              <h3 class="text-base font-bold text-slate-900 dark:text-white mb-4">Note Operative</h3>
              <p class="text-sm text-slate-600 dark:text-slate-400 leading-relaxed whitespace-pre-wrap" :class="{'italic text-slate-400 dark:text-slate-500': !condominio.note}">
                {{ condominio.note || 'Nessuna nota inserita per questo condominio.' }}
              </p>
            </div>

          </div>

        </StrutturaLayout>
      </div>
    </div>
   
  </GestionaleLayout>
</template>