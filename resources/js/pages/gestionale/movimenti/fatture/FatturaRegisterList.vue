<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import MovimentiLayout from '@/layouts/gestionale/MovimentiLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { usePermission } from '@/composables/permissions';
import {
    FileText, Plus, Search, Clock, AlertTriangle,
    Euro, ChevronRight, Receipt, Construction
} from 'lucide-vue-next';
import type { Building } from '@/types/buildings';

// ---------------------------------------------------------------------------
// Props
// ---------------------------------------------------------------------------
const props = defineProps<{
    condominio: Building;
    condomini:  Building[];
    fatture:    { data: any[]; meta: any };
    stats:      { totale_aperte: number; totale_sfori: number; importo_da_pagare: number };
    filters:    { stato_pagamento?: string; stato_approvazione?: string; search?: string };
}>();

const { generatePath } = usePermission();
const page = usePage<{ flash: { message?: any } }>();

// ---------------------------------------------------------------------------
// Breadcrumbs e guide
// ---------------------------------------------------------------------------
const headerBreadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Movimenti' },
    { title: 'Fatture Passive' },
]);

const pageGuides = [
    {
        title:        'Ciclo Passivo',
        description:  'Registra le fatture dei fornitori con controllo automatico del budget approvato dall\'assemblea e della liquidità disponibile.',
        icon:         FileText,
        colorVariant: 'blue' as const,
    },
    {
        title:        'Sfori Motivati',
        description:  'Le fatture che superano il preventivo assembleare vengono contrassegnate con motivazione obbligatoria e richiedono ratifica in assemblea.',
        icon:         AlertTriangle,
        colorVariant: 'amber' as const,
    },
    {
        title:        'Scadenzario Automatico',
        description:  'Ogni fattura genera automaticamente una scadenza di pagamento nel tuo calendario e, se prevista, una scadenza per il versamento della ritenuta F24.',
        icon:         Clock,
        colorVariant: 'emerald' as const,
    },
];

// ---------------------------------------------------------------------------
// Filtri
// ---------------------------------------------------------------------------
const searchQuery = ref(props.filters.search || '');

const applySearch = () => {
    router.get(
        route('gestionale.fatture.index', props.condominio.id),
        { search: searchQuery.value },
        { preserveState: true, replace: true }
    );
};

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
const formatEuro = (centesimi: number) =>
    new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(centesimi / 100);

const statoPagamentoBadge = (stato: string) => {
    const map: Record<string, string> = {
        aperta:    'bg-amber-100 text-amber-700',
        pagata:    'bg-emerald-100 text-emerald-700',
        parziale:  'bg-blue-100 text-blue-700',
    };
    return map[stato] ?? 'bg-slate-100 text-slate-600';
};

const statoApprovazioneBadge = (stato: string) => {
    const map: Record<string, string> = {
        approvata:      'bg-emerald-100 text-emerald-700',
        da_approvare:   'bg-slate-100 text-slate-600',
        contestata:     'bg-red-100 text-red-700',
        sforo_motivato: 'bg-orange-100 text-orange-700',
    };
    return map[stato] ?? 'bg-slate-100 text-slate-600';
};

const statoApprovazioneLebel = (stato: string) => {
    const map: Record<string, string> = {
        approvata:      'Approvata',
        da_approvare:   'Da approvare',
        contestata:     'Contestata',
        sforo_motivato: 'Sforo motivato',
    };
    return map[stato] ?? stato;
};
</script>

<template>
    <Head title="Fatture Passive" />
    <GestionaleLayout>
        <div class="px-6 py-8 space-y-3">

            <PageHeaderGuide
                page-title="Fatture Passive"
                page-subtitle="Gestisci il ciclo passivo del condominio. Registra le fatture dei fornitori con controllo budget integrato e scadenzario automatico."
                :guides="pageGuides"
                :breadcrumbs="headerBreadcrumbs"
                :video-url="null"
                :condominio="props.condominio"
                :condomini="props.condomini"
            />

            <div class="w-full">
                <section class="w-full space-y-4">
                    <MovimentiLayout>

                        <!-- ------------------------------------------------ -->
                        <!-- STATS CARDS                                        -->
                        <!-- ------------------------------------------------ -->
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">

                            <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm flex items-center gap-4">
                                <div class="bg-amber-100 p-2.5 rounded-lg">
                                    <Clock class="w-5 h-5 text-amber-600" />
                                </div>
                                <div>
                                    <p class="text-xs text-slate-500 font-medium">Fatture Aperte</p>
                                    <p class="text-2xl font-bold text-slate-900">{{ stats.totale_aperte }}</p>
                                </div>
                            </div>

                            <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm flex items-center gap-4">
                                <div class="bg-red-100 p-2.5 rounded-lg">
                                    <Euro class="w-5 h-5 text-red-600" />
                                </div>
                                <div>
                                    <p class="text-xs text-slate-500 font-medium">Importo da Pagare</p>
                                    <p class="text-2xl font-bold text-slate-900">{{ formatEuro(stats.importo_da_pagare) }}</p>
                                </div>
                            </div>

                            <div
                                class="border rounded-xl p-4 shadow-sm flex items-center gap-4 cursor-pointer transition-all"
                                :class="stats.totale_sfori > 0
                                    ? 'bg-orange-50 border-orange-200 hover:bg-orange-100'
                                    : 'bg-white border-slate-200'"
                            >
                                <div :class="stats.totale_sfori > 0 ? 'bg-orange-100' : 'bg-slate-100'" class="p-2.5 rounded-lg">
                                    <AlertTriangle :class="stats.totale_sfori > 0 ? 'text-orange-600' : 'text-slate-400'" class="w-5 h-5" />
                                </div>
                                <div>
                                    <p class="text-xs text-slate-500 font-medium">Da Ratificare in Assemblea</p>
                                    <p class="text-2xl font-bold" :class="stats.totale_sfori > 0 ? 'text-orange-700' : 'text-slate-900'">
                                        {{ stats.totale_sfori }}
                                    </p>
                                </div>
                            </div>

                        </div>

                        <!-- ------------------------------------------------ -->
                        <!-- TOOLBAR                                            -->
                        <!-- ------------------------------------------------ -->
                        <div class="flex items-center justify-between gap-4 mb-4">
                            <div class="flex items-center gap-2 flex-1 max-w-sm">
                                <div class="relative flex-1">
                                    <Search class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
                                    <Input
                                        v-model="searchQuery"
                                        placeholder="Cerca per numero o fornitore..."
                                        class="pl-9"
                                        @keyup.enter="applySearch"
                                    />
                                </div>
                                <Button variant="outline" size="sm" @click="applySearch">Cerca</Button>
                            </div>

                            <Link :href="route('admin.gestionale.fatture.create', condominio.id)">
                                <Button class="bg-blue-600 hover:bg-blue-700 text-white font-bold gap-2">
                                    <Plus class="w-4 h-4" />
                                    Nuova Fattura
                                </Button>
                            </Link>
                        </div>

                        <!-- ------------------------------------------------ -->
                        <!-- LISTA FATTURE (se presenti)                        -->
                        <!-- ------------------------------------------------ -->
                        <div v-if="fatture.data.length > 0" class="space-y-2">
                            <div
                                v-for="fattura in fatture.data"
                                :key="fattura.id"
                                class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm hover:shadow-md hover:border-slate-300 transition-all cursor-pointer flex items-center justify-between gap-4"
                                @click="router.visit(route('gestionale.fatture.show', [condominio.id, fattura.id]))"
                            >
                                <div class="flex items-center gap-4 flex-1 min-w-0">
                                    <div class="bg-slate-100 p-2.5 rounded-lg shrink-0">
                                        <Receipt class="w-5 h-5 text-slate-500" />
                                    </div>
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <span class="font-bold text-sm text-slate-900 truncate">
                                                {{ fattura.fornitore?.ragione_sociale ?? 'N/D' }}
                                            </span>
                                            <span class="text-xs text-slate-400 font-mono">
                                                n. {{ fattura.numero_documento }}
                                            </span>
                                        </div>
                                        <div class="flex items-center gap-2 mt-1 flex-wrap">
                                            <span class="text-[10px] text-slate-400">
                                                {{ new Date(fattura.data_documento).toLocaleDateString('it-IT') }}
                                            </span>
                                            <span class="text-[10px] text-slate-300">•</span>
                                            <span class="text-[10px] text-slate-400">
                                                Scad. {{ new Date(fattura.data_scadenza).toLocaleDateString('it-IT') }}
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex items-center gap-3 shrink-0">
                                    <!-- Badge stato approvazione -->
                                    <span
                                        class="text-[10px] font-bold px-2 py-1 rounded-full"
                                        :class="statoApprovazioneBadge(fattura.stato_approvazione)"
                                    >
                                        {{ statoApprovazioneLebel(fattura.stato_approvazione) }}
                                    </span>

                                    <!-- Badge stato pagamento -->
                                    <span
                                        class="text-[10px] font-bold px-2 py-1 rounded-full capitalize"
                                        :class="statoPagamentoBadge(fattura.stato_pagamento)"
                                    >
                                        {{ fattura.stato_pagamento }}
                                    </span>

                                    <!-- Importo -->
                                    <span class="font-bold text-sm font-mono text-slate-900 min-w-[80px] text-right">
                                        {{ formatEuro(fattura.netto_a_pagare) }}
                                    </span>

                                    <ChevronRight class="w-4 h-4 text-slate-300" />
                                </div>
                            </div>

                            <!-- Paginazione -->
                            <div v-if="fatture.meta?.last_page > 1" class="flex justify-center gap-2 pt-4">
                                <Button
                                    v-for="page in fatture.meta.last_page"
                                    :key="page"
                                    variant="outline"
                                    size="sm"
                                    :class="{ 'bg-blue-600 text-white border-blue-600': page === fatture.meta.current_page }"
                                    @click="router.visit(route('gestionale.fatture.index', condominio.id) + '?page=' + page)"
                                >
                                    {{ page }}
                                </Button>
                            </div>
                        </div>

                        <!-- ------------------------------------------------ -->
                        <!-- CARD PLACEHOLDER (lista vuota)                    -->
                        <!-- ------------------------------------------------ -->
                        <div
                            v-else
                            class="border-2 border-dashed border-slate-200 rounded-2xl bg-slate-50/50 p-12 text-center"
                        >
                            <div class="flex flex-col items-center gap-4 max-w-sm mx-auto">
                                <div class="bg-blue-100 p-4 rounded-2xl">
                                    <Construction class="w-8 h-8 text-blue-500" />
                                </div>
                                <div>
                                    <h3 class="font-bold text-slate-800 text-lg">
                                        Nessuna fattura registrata
                                    </h3>
                                    <p class="text-sm text-slate-500 mt-1">
                                        Registra la prima fattura passiva del condominio.
                                        Il sistema controllerà automaticamente il budget
                                        e genererà le scadenze di pagamento.
                                    </p>
                                </div>

                                <!-- Funzionalità future -->
                                <div class="w-full bg-white border border-slate-200 rounded-xl p-4 text-left space-y-2 mt-2">
                                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-3">
                                        In sviluppo
                                    </p>
                                    <div v-for="item in [
                                        'Importazione fattura XML (FatturaPA)',
                                        'Lista fatture con filtri avanzati',
                                        'Registrazione pagamento con riconciliazione',
                                        'Export per commercialista',
                                        'Dashboard sfori da ratificare',
                                    ]" :key="item" class="flex items-center gap-2 text-xs text-slate-500">
                                        <div class="w-1.5 h-1.5 rounded-full bg-blue-300 shrink-0" />
                                        {{ item }}
                                    </div>
                                </div>

                                <Link :href="route('admin.gestionale.fatture.create', condominio.id)" class="w-full">
                                    <Button class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold gap-2">
                                        <Plus class="w-4 h-4" />
                                        Registra Prima Fattura
                                    </Button>
                                </Link>
                            </div>
                        </div>

                    </MovimentiLayout>
                </section>
            </div>
        </div>
    </GestionaleLayout>
</template>