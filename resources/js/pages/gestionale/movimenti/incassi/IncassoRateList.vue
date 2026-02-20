<script setup lang="ts">
import { computed } from "vue";
import { Head, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import MovimentiLayout from '@/layouts/gestionale/MovimentiLayout.vue';
import DataTable from '@/components/gestionale/movimenti/incassi/DataTable.vue'; 
import { createColumns } from '@/components/gestionale/movimenti/incassi/columns';
import { usePermission } from "@/composables/permissions";
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Wallet, Coins, CheckCircle } from 'lucide-vue-next';

// 1. IMPORTIAMO I TIPI CORRETTI
import type { Building } from '@/types/buildings';
import type { Esercizio } from "@/types/gestionale/esercizi";

// 2. AGGIUNGIAMO ESERCIZIO E TIPIZZIAMO CONDOMINIO
const props = defineProps<{
  condominio: Building;
  condomini: Building[];
  esercizio: Esercizio;
  esercizi: Esercizio[];
  movimenti: { data: any[], meta: any }; // Dati paginati
  filters: any;
}>();

const { generatePath } = usePermission();

const headerBreadcrumbs = computed(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: 'Movimenti' },
  { title: 'Incassi Rate' }
]);

const pageGuides = [
  {
    title: 'Registrazione Rapida',
    description: 'Registra gli incassi delle rate ordinarie e straordinarie. Il sistema calcola in automatico il residuo da saldare per ogni condòmino.',
    icon: Wallet,
    colorVariant: 'blue' as const
  },
  {
    title: 'Pagamenti Parziali',
    description: 'Puoi registrare tranquillamente incassi parziali. Il debito residuo rimarrà aperto e tracciato per i futuri solleciti.',
    icon: Coins,
    colorVariant: 'amber' as const
  },
  {
    title: 'Saldare i Pregressi',
    description: 'Utilizza l\'interfaccia per intercettare e saldare velocemente la quota relativa al "Saldo Iniziale" (il debito dell\'anno precedente).',
    icon: CheckCircle,
    colorVariant: 'emerald' as const
  }
];
</script>

<template>
  <Head title="Elenco Incassi" />

  <GestionaleLayout>

    <div class="px-6 py-8 space-y-3">
      
      <PageHeaderGuide
        page-title="Incassi pagamenti rate"
        page-subtitle="Registra i pagamenti dei condòmini. Tieni traccia delle rate versate, gestisci i pagamenti parziali e monitora la liquidità in entrata."
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
          
          <MovimentiLayout>
            <div>
                <DataTable 
                    :columns="createColumns(props.condominio.id)"
                    :data="props.movimenti.data"
                    :meta="props.movimenti.meta || props.movimenti" 
                    :condominio="props.condominio"
                />
            </div>
          </MovimentiLayout>

        </section>
      </div>

    </div>

  </GestionaleLayout>
</template>