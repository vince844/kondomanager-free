<script setup lang="ts">
import { computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import MovimentiLayout from '@/layouts/gestionale/MovimentiLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import DataTable from '@/components/gestionale/movimenti/pagamenti/Datatable.vue'
import { createColumns } from '@/components/gestionale/movimenti/pagamenti/columns';
import { usePermission } from '@/composables/permissions';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { CreditCard, RotateCcw, AlertTriangle, Euro } from 'lucide-vue-next';
import Alert from "@/components/Alert.vue";
import type { Building } from '@/types/buildings';
import type { Flash } from '@/types/flash';

const props = defineProps<{
    condominio: Building;
    condomini:  Building[];
    pagamenti:  { data: any[]; meta: any };
    stats:      { totale_pagato_mese: number; totale_stornati: number; totale_ritenute: number };
    filters:    { search?: string; metodo_pagamento?: string; has_ritenuta?: boolean | string; stato?: string };
}>();

const { generatePath, generateRoute } = usePermission();
const { euro } = useCurrencyFormatter();
const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

const headerBreadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Movimenti' },
    { title: 'Registro Pagamenti Fornitori' },
]);

const pageGuides = [
    { title: 'Registro Uscite', description: 'Tutte le disposizioni di pagamento eseguite verso i fornitori.', icon: CreditCard, colorVariant: 'blue' as const },
    { title: 'Antifrode & Fisco', description: 'Monitoraggio IBAN, ritenute e bonifici parlanti per le detrazioni.', icon: AlertTriangle, colorVariant: 'amber' as const },
    { title: 'Storni', description: 'Gestisci gli annullamenti e recupera le fatture stornate.', icon: RotateCcw, colorVariant: 'slate' as const },
];

</script>

<template>
    <Head title="Registro pagamenti" />
    <GestionaleLayout>
        <div class="px-6 py-8 space-y-3">
            <PageHeaderGuide
                page-title="Registro pagamenti"
                page-subtitle="Consulta lo storico dei pagamenti ai fornitori, scarica le distinte e gestisci gli storni contabili."
                :guides="pageGuides"
                :breadcrumbs="(headerBreadcrumbs as any)"
                :condominio="(props.condominio as any)"
                :condomini="(props.condomini as any)"
            />

            <div class="w-full">
                <section class="w-full space-y-4">
                    <MovimentiLayout>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">

                            <!-- Card: Pagato questo mese -->
                            <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm flex items-center gap-4">
                                <div class="bg-blue-50 p-2.5 rounded-lg border border-blue-100">
                                    <Euro class="w-5 h-5 text-blue-600" />
                                </div>
                                <div>
                                    <p class="text-xs text-slate-500 font-medium uppercase tracking-wider">Pagato nel mese</p>
                                    <p class="text-2xl font-black text-slate-900">{{ euro(stats.totale_pagato_mese) }}</p>
                                </div>
                            </div>

                            <!-- Card: Pagamenti con Ritenuta -->
                            <button 
                                @click="router.visit(route(generateRoute('gestionale.pagamenti-fornitori.index'), { condominio: props.condominio.id, has_ritenuta: filters.has_ritenuta ? undefined : true }), { preserveState: true })"
                                class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm flex items-center gap-4 hover:border-cyan-300 hover:ring-2 hover:ring-cyan-100 transition-all text-left outline-none"
                                :class="filters.has_ritenuta ? 'ring-2 ring-cyan-500 border-cyan-500' : ''"
                            >
                                <div class="bg-cyan-50 p-2.5 rounded-lg border border-cyan-100">
                                    <CreditCard class="w-5 h-5 text-cyan-600" />
                                </div>
                                <div class="flex-1">
                                    <p class="text-xs text-slate-500 font-medium uppercase tracking-wider">Con Ritenuta d'Acconto</p>
                                    <p class="text-2xl font-black text-slate-900">{{ stats.totale_ritenute }}</p>
                                </div>
                            </button>

                            <!-- Card: Pagamenti Stornati -->
                            <button 
                                @click="router.visit(route(generateRoute('gestionale.pagamenti-fornitori.index'), { condominio: props.condominio.id, stato: filters.stato === 'stornato' ? undefined : 'stornato' }), { preserveState: true })"
                                class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm flex items-center gap-4 hover:opacity-100 hover:border-slate-400 hover:ring-2 hover:ring-slate-100 transition-all text-left outline-none"
                                :class="filters.stato === 'stornato' ? 'ring-2 ring-slate-400 border-slate-400 opacity-100' : 'opacity-80'"
                            >
                                <div class="bg-slate-100 p-2.5 rounded-lg border border-slate-200">
                                    <RotateCcw class="w-5 h-5 text-slate-500" />
                                </div>
                                <div class="flex-1">
                                    <p class="text-xs text-slate-500 font-medium uppercase tracking-wider">Operazioni Stornate</p>
                                    <p class="text-2xl font-black text-slate-900">{{ stats.totale_stornati }}</p>
                                </div>
                            </button>

                        </div>

                        <div v-if="flashMessage" class="mb-3">
                            <Alert :message="flashMessage.message" :type="flashMessage.type" />
                        </div>

                        <div>
                            <DataTable 
                                :columns="createColumns(props.condominio.id)"
                                :data="props.pagamenti.data"
                                :meta="props.pagamenti.meta || props.pagamenti" 
                                :condominio="props.condominio"
                            />
                        </div>

                    </MovimentiLayout>
                </section>
            </div>
        </div>
    </GestionaleLayout>
</template>
