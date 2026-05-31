<script setup lang="ts">

import { computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import MovimentiLayout from '@/layouts/gestionale/MovimentiLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import DataTable from '@/components/gestionale/movimenti/fatture/DataTable.vue'; 
import { createColumns } from '@/components/gestionale/movimenti/fatture/columns';
import { usePermission } from '@/composables/permissions';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { FileText, Clock, AlertTriangle, Euro } from 'lucide-vue-next';
import Alert from "@/components/Alert.vue";
import type { Building } from '@/types/buildings';
import type { Flash } from '@/types/flash';

const props = defineProps<{
    condominio: Building;
    condomini:  Building[];
    fatture:    { data: any[]; meta: any };
    stats:      { totale_aperte: number; totale_sfori: number; importo_da_pagare: number };
    filters:    { stato_pagamento?: string; stato_approvazione?: string; search?: string };
}>();

const { generatePath, generateRoute } = usePermission();
const { euro } = useCurrencyFormatter();
const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

const headerBreadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Movimenti' },
    { title: 'Fatture Passive' },
]);

const pageGuides = [
    { title: 'Ciclo Passivo', description: 'Registra le fatture con controllo automatico del budget.', icon: FileText, colorVariant: 'blue' as const },
    { title: 'Sfori Motivati', description: 'Le fatture che superano il preventivo richiedono ratifica.', icon: AlertTriangle, colorVariant: 'amber' as const },
    { title: 'Scadenzario', description: 'Ogni fattura genera scadenze di pagamento.', icon: Clock, colorVariant: 'emerald' as const },
];

const sforiFilterActive = computed(() => props.filters.stato_approvazione === 'sforo_motivato');

// Toggle filtro sfori — click attiva, secondo click rimuove
const filterSfori = () => {

    router.get(
        route(generateRoute('gestionale.fatture.index'), { condominio: props.condominio.id }),
        sforiFilterActive.value ? {} : { stato_approvazione: 'sforo_motivato' },
        {
            preserveScroll: true,
            preserveState: true,
        }
    );
};

</script>

<template>
    <Head title="Fatture passive" />
    <GestionaleLayout>
        <div class="px-6 py-8 space-y-3">
            <PageHeaderGuide
                page-title="Fatture passive"
                page-subtitle="Gestisci il ciclo passivo del condominio. Registra le fatture dei fornitori con controllo budget integrato e scadenzario automatico."
                :guides="pageGuides"
                :breadcrumbs="(headerBreadcrumbs as any)"
                :condominio="(props.condominio as any)"
                :condomini="(props.condomini as any)"
            />

            <div class="w-full">
                <section class="w-full space-y-4">
                    <MovimentiLayout>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">

                            <!-- Card: Fatture Aperte -->
                            <button 
                                @click="router.visit(route(generateRoute('gestionale.fatture.index'), { condominio: props.condominio.id, stato_pagamento: filters.stato_pagamento === 'aperta' ? undefined : 'aperta' }), { preserveState: true })"
                                class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm flex items-center gap-4 hover:border-amber-300 hover:ring-2 hover:ring-amber-100 transition-all text-left outline-none"
                                :class="filters.stato_pagamento === 'aperta' ? 'ring-2 ring-amber-500 border-amber-500' : ''"
                            >
                                <div class="bg-amber-50 p-2.5 rounded-lg border border-amber-100">
                                    <Clock class="w-5 h-5 text-amber-600" />
                                </div>
                                <div class="flex-1">
                                    <p class="text-xs text-slate-500 font-medium uppercase tracking-wider">Fatture Aperte</p>
                                    <p class="text-2xl font-black text-slate-900">{{ stats.totale_aperte }}</p>
                                </div>
                            </button>

                            <!-- Card: Importo da Pagare (solo visivo) -->
                            <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm flex items-center gap-4">
                                <div class="bg-red-50 p-2.5 rounded-lg border border-red-100">
                                    <Euro class="w-5 h-5 text-red-600" />
                                </div>
                                <div>
                                    <p class="text-xs text-slate-500 font-medium uppercase tracking-wider">Importo da Pagare</p>
                                    <p class="text-2xl font-black text-slate-900">{{ euro(stats.importo_da_pagare) }}</p>
                                </div>
                            </div>

                            <!-- Card: Da Ratificare (toggle filtro) -->
                            <button 
                                @click="filterSfori"
                                class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm flex items-center gap-4 transition-all text-left outline-none hover:opacity-100 hover:border-orange-300 hover:ring-2 hover:ring-orange-100 cursor-pointer"
                                :class="sforiFilterActive ? 'ring-2 ring-orange-500 border-orange-500 opacity-100' : 'opacity-80'"
                            >
                                <div class="bg-slate-100 p-2.5 rounded-lg border border-slate-200" :class="stats.totale_sfori > 0 || sforiFilterActive ? 'bg-orange-50 border-orange-100' : ''">
                                    <AlertTriangle class="w-5 h-5 text-slate-500" :class="stats.totale_sfori > 0 || sforiFilterActive ? 'text-orange-600' : ''" />
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <p class="text-xs text-slate-500 font-medium uppercase tracking-wider">Da Ratificare</p>
                                    </div>
                                    <p class="text-2xl font-black text-slate-900" :class="stats.totale_sfori > 0 || sforiFilterActive ? 'text-orange-700' : ''">
                                        {{ stats.totale_sfori }}
                                    </p>
                                </div>
                            </button>

                        </div>

                        <div v-if="flashMessage" class="mb-3">
                            <Alert :message="flashMessage.message" :type="flashMessage.type" />
                        </div>

                        <div>
                            <DataTable 
                                :columns="createColumns(props.condominio.id)"
                                :data="props.fatture.data"
                                :meta="props.fatture.meta || props.fatture" 
                                :condominio="props.condominio"
                            />
                        </div>

                    </MovimentiLayout>
                </section>
            </div>
        </div>
    </GestionaleLayout>
</template>