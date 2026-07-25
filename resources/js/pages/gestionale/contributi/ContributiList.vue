<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Input } from '@/components/ui/input';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { usePermission } from '@/composables/permissions';
import type { Building } from '@/types/buildings';
import { Wallet, Search, ArrowRight, CheckCircle2, Circle } from 'lucide-vue-next';

interface Voce {
  id: number;
  nome: string;
  gestione: string | null;
  gestione_tipo: string | null;
  importo_cents: number;
  coperto_cents: number;
  unita_coperte: number;
}

const props = defineProps<{
  condominio: Building;
  voci: Voce[];
}>();

const { euro } = useCurrencyFormatter({ fromCents: true });
const { generatePath } = usePermission();

const search = ref('');

const filtrate = computed(() => {
  const q = search.value.toLowerCase().trim();
  if (!q) return props.voci;
  return props.voci.filter(v =>
    v.nome.toLowerCase().includes(q) || (v.gestione ?? '').toLowerCase().includes(q)
  );
});

const percentuale = (v: Voce) =>
  v.importo_cents > 0 ? Math.min(100, Math.round((v.coperto_cents / v.importo_cents) * 100)) : 0;

const headerBreadcrumbs = computed(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: 'Struttura', href: '#' },
  { title: 'Già versato', href: '#' },
]);

const guide = [
  {
    title: 'Quando serve',
    description: 'Se il condominio arriva da un altro gestionale portandosi un accantonamento già raccolto, registralo qui: il riparto chiederà solo la differenza.',
    icon: Wallet,
    colorVariant: 'emerald' as const,
  },
];
</script>

<template>
  <Head title="Già versato" />

  <GestionaleLayout>
    <div class="px-6 py-8 space-y-6">

      <PageHeaderGuide
        page-title="Già versato per voce di spesa"
        page-subtitle="Quanto i condòmini hanno versato prima che la spesa venga ripartita"
        :guides="guide"
        :breadcrumbs="headerBreadcrumbs"
        :condominio="condominio"
      />

      <div class="relative max-w-sm">
        <Search class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
        <Input v-model="search" placeholder="Cerca una voce di spesa..." class="pl-9 h-10" />
      </div>

      <div class="bg-white border rounded-2xl overflow-hidden">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-[10px] uppercase tracking-wide text-slate-400 border-b bg-slate-50/60">
              <th class="text-left px-6 py-3 font-bold">Voce di spesa</th>
              <th class="text-right px-3 py-3 font-bold">Importo</th>
              <th class="text-left px-6 py-3 font-bold w-56">Già versato</th>
              <th class="px-6 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="v in filtrate" :key="v.id" class="hover:bg-slate-50/60">
              <td class="px-6 py-3">
                <div class="font-semibold text-slate-800 text-xs">{{ v.nome }}</div>
                <div v-if="v.gestione" class="text-[10px] text-slate-400 mt-0.5">
                  {{ v.gestione }}
                </div>
              </td>
              <td class="px-3 py-3 text-right text-xs tabular-nums text-slate-600">
                {{ euro(v.importo_cents) }}
              </td>
              <td class="px-6 py-3">
                <div v-if="v.coperto_cents > 0">
                  <div class="flex items-center gap-2 mb-1">
                    <CheckCircle2 class="w-3.5 h-3.5 text-emerald-500 shrink-0" />
                    <span class="text-xs font-bold text-emerald-700 tabular-nums">
                      {{ euro(v.coperto_cents) }}
                    </span>
                    <span class="text-[10px] text-slate-400">
                      su {{ v.unita_coperte }} {{ v.unita_coperte === 1 ? 'unità' : 'unità' }}
                    </span>
                  </div>
                  <div class="h-1.5 bg-slate-100 rounded-full overflow-hidden">
                    <div class="h-full bg-emerald-500 rounded-full transition-all"
                      :style="{ width: percentuale(v) + '%' }"></div>
                  </div>
                </div>
                <div v-else class="flex items-center gap-2 text-[11px] text-slate-400">
                  <Circle class="w-3.5 h-3.5 shrink-0" />
                  Nessun versamento registrato
                </div>
              </td>
              <td class="px-6 py-3 text-right">
                <Link
                  :href="generatePath('gestionale/:condominio/contributi/:conto', {
                    condominio: condominio.id, conto: v.id })"
                  class="inline-flex items-center gap-1.5 text-xs font-bold text-indigo-600 hover:gap-2.5 transition-all">
                  Gestisci <ArrowRight class="w-3.5 h-3.5" />
                </Link>
              </td>
            </tr>
            <tr v-if="filtrate.length === 0">
              <td colspan="4" class="px-6 py-12 text-center text-slate-400 text-sm">
                Nessuna voce di spesa trovata
              </td>
            </tr>
          </tbody>
        </table>
      </div>

    </div>
  </GestionaleLayout>
</template>
