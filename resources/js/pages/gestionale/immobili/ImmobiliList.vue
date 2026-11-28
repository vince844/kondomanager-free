<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">

import { computed } from "vue";
import { Head, Link, usePage } from '@inertiajs/vue3';
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
import type { RigaAQuotaZero } from '@/types/gestionale/passaggi';
import type { Building } from '@/types/buildings';
import type { PaginationMeta } from '@/types/pagination';

const props = defineProps<{
  condominio: Building;
  condomini: Building[];
  immobili: Immobile[];
  meta: PaginationMeta;
  /** Le righe di proprietà chiuse a quota 0 che un altro proprietario segue: nei loro giorni nessuno paga per l'unità. Vuoto se non ce ne sono. */
  righeAQuotaZero?: RigaAQuotaZero[];
}>()

const righe = computed(() => props.righeAQuotaZero ?? []);
const unaSola = computed(() => righe.value.length === 1);
const titoloRiquadro = computed(() => unaSola.value ? '1 riga di proprietà chiusa con quota 0 %' : `${righe.value.length} righe di proprietà chiuse con quota 0 %`);
const codaRiquadro = computed(() => unaSola.value
  ? ', seguita da un altro proprietario: nei suoi giorni nessuno paga per quell\'unità.'
  : ', seguite da un altro proprietario: nei loro giorni nessuno paga per quelle unità.');

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
    title: 'Anagrafica immobile',
    description: 'Registra i dati catastali e le informazioni principali degli appartamenti, box e locali commerciali che compongono il condominio.',
    icon: Home,
    colorVariant: 'blue' as const
  },
  {
    title: 'Date di competenza',
    description: 'Le date di inizio e fine di ogni titolare entrano nel riparto: chi esce paga fino al suo ultimo giorno, chi entra dal primo. Una riga di proprietà chiusa a quota 0 % lascia i suoi giorni senza pagante: se compare nel riquadro sopra l\'elenco, aprila dallo storico. Un cambio di proprietà o inquilino si registra da «Registra passaggio».',
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
        page-subtitle="Gestisci l'anagrafica fisica del condominio. Aggiungi le unità immobiliari e preparati ad assegnare i soggetti."
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

          <!-- Le righe di proprietà chiuse a quota 0: solo se ci sono. Nessuna correzione da qui: ogni riga porta allo storico dell'unità, dove «Correggi questa riga» apre la modifica. -->
          <div v-if="righe.length" data-testid="righe-a-quota-zero" class="border border-slate-200 dark:border-slate-800 rounded-2xl bg-white dark:bg-slate-950 shadow-sm p-4 space-y-3">
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-[13px] text-amber-900 dark:border-amber-800/50 dark:bg-amber-900/10 dark:text-amber-200">
              <strong>{{ titoloRiquadro }}</strong>{{ codaRiquadro }}
            </div>
            <div class="overflow-x-auto">
              <table class="w-full text-[13px]">
                <thead>
                  <tr class="text-left text-xs font-semibold text-slate-500 dark:text-slate-400">
                    <th class="px-2 py-1.5 whitespace-nowrap">Unità</th>
                    <th class="px-2 py-1.5 whitespace-nowrap">Persona</th>
                    <th class="px-2 py-1.5 whitespace-nowrap">Periodo</th>
                    <th class="px-2 py-1.5 whitespace-nowrap text-right">Giorni che nessuno paga</th>
                    <th class="px-2 py-1.5"></th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                  <tr v-for="riga in righe" :key="riga.riga_id" class="text-slate-700 dark:text-slate-300">
                    <td class="px-2 py-1.5 whitespace-nowrap font-medium text-slate-900 dark:text-slate-100">{{ riga.unita }}</td>
                    <td class="px-2 py-1.5">{{ riga.persona }}</td>
                    <td class="px-2 py-1.5 whitespace-nowrap">{{ riga.periodo }}</td>
                    <td class="px-2 py-1.5 text-right tabular-nums">{{ riga.giorni }}</td>
                    <td class="px-2 py-1.5 text-right whitespace-nowrap">
                      <Link :href="riga.url_storico" class="text-indigo-600 dark:text-indigo-400 hover:underline">Apri lo storico</Link>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <details class="text-[13px] text-slate-600 dark:text-slate-400">
              <summary class="cursor-pointer text-indigo-600 dark:text-indigo-400">Perché ci sono</summary>
              <ul class="mt-1.5 list-disc space-y-1 pl-5">
                <li>Nelle versioni fino alla 1.10 il programma non accettava due proprietari al 100 % sulla stessa unità, anche in periodi diversi.</li>
                <li>Per andare avanti si abbassava a 0 % chi aveva venduto.</li>
                <li>Oggi il controllo è giorno per giorno: la quota giusta si può rimettere.</li>
              </ul>
            </details>
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