<script setup lang="ts">
import { computed } from "vue";
import { Head, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import MovimentiLayout from '@/layouts/gestionale/MovimentiLayout.vue';
import DataTable from '@/components/gestionale/movimenti/incassi/DataTable.vue'; 
import { createColumns } from '@/components/gestionale/movimenti/incassi/columns';
import { usePermission } from "@/composables/permissions";
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Wallet, Coins, CheckCircle, RotateCcw } from 'lucide-vue-next';

import type { Building } from '@/types/buildings';
import type { Esercizio } from "@/types/gestionale/esercizi";

const props = defineProps<{
  condominio: Building;
  condomini: Building[];
  esercizio: Esercizio;
  esercizi: Esercizio[];
  movimenti: { data: any[], meta: any };
  stats: { totale_incassi: number; incassi_mese: number; stornati: number };
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
        :breadcrumbs="(headerBreadcrumbs as any)"
        :condominio="(props.condominio as any)"
        :condomini="(props.condomini as any)"
        :esercizio="props.esercizio"
        :esercizi="props.esercizi"
      />

      <div class="w-full">
        <section class="w-full space-y-4">
          <MovimentiLayout>

            <!-- ─── STATS ──────────────────────────────────────────── -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">

              <!-- Card: Totale Incassi -->
              <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm flex items-center gap-4">
                <div class="bg-blue-100 p-2.5 rounded-lg border border-blue-200">
                  <Wallet class="w-5 h-5 text-blue-600" />
                </div>
                <div>
                  <p class="text-xs text-slate-500 font-medium uppercase tracking-wider">Totale Incassi</p>
                  <p class="text-2xl font-black text-slate-900">{{ stats.totale_incassi }}</p>
                </div>
              </div>

              <!-- Card: Incassi Questo Mese -->
              <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm flex items-center gap-4">
                <div class="bg-emerald-100 p-2.5 rounded-lg border border-emerald-200">
                  <CheckCircle class="w-5 h-5 text-emerald-600" />
                </div>
                <div>
                  <p class="text-xs text-slate-500 font-medium uppercase tracking-wider">Incassi Questo Mese</p>
                  <p class="text-2xl font-black text-slate-900">{{ stats.incassi_mese }}</p>
                </div>
              </div>

              <!-- Card: Stornati -->
              <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm flex items-center gap-4">
                <div class="bg-rose-50 p-2.5 rounded-lg border border-rose-100">
                  <RotateCcw class="w-5 h-5 text-rose-600" />
                </div>
                <div>
                  <p class="text-xs text-slate-500 font-medium uppercase tracking-wider">Stornati</p>
                  <p class="text-2xl font-black text-slate-900">{{ stats.stornati }}</p>
                </div>
              </div>

            </div>
            <!-- ─────────────────────────────────────────────────────── -->

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