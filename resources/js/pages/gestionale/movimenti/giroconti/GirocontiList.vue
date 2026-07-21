<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import MovimentiLayout from '@/layouts/gestionale/MovimentiLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import GirocontiGuide from '@/components/guides/GirocontiGuide.vue';
import DataTable from '@/components/gestionale/movimenti/giroconti/Datatable.vue'
import { createColumns } from '@/components/gestionale/movimenti/giroconti/columns';
import { usePermission } from '@/composables/permissions';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { Repeat2, RotateCcw, CheckCircle, AlertTriangle, ChevronDown, Loader2 } from 'lucide-vue-next';
import Alert from "@/components/Alert.vue";
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import type { GirocontoRow } from '@/components/gestionale/movimenti/giroconti/columns';
import type { Building } from '@/types/buildings';
import type { Esercizio } from '@/types/gestionale/esercizi';
import type { Flash } from '@/types/flash';

interface RigaFondo {
    conto: string;
    tipo_riga: string;
    /** Importo in CENTESIMI. */
    importo: number;
}

interface RiallineamentoItem {
    scrittura_id: number;
    protocollo: string | null;
    data: string | null;
    causale: string | null;
    righe_fondo: RigaFondo[];
    /** Importo netto sul fondo in CENTESIMI. */
    importo_netto_fondo: number;
}

interface PaginationMeta {
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
}

const props = defineProps<{
    condominio: Building;
    condomini:  Building[];
    esercizio:  Esercizio;
    giroconti:  { data: GirocontoRow[]; meta: PaginationMeta };
    stats:      { giroconti_attivi: number; stornati: number; coperture_confermate: number };
    filters:    { search?: string };
    riallineamento: RiallineamentoItem[];
}>();

const { generatePath, generateRoute } = usePermission();
const { euro } = useCurrencyFormatter();
const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

const headerBreadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Movimenti' },
    { title: 'Giroconti' },
]);

const pageGuides = [
    { title: 'Spostamenti fra casse', description: 'Accantona sul fondo, libera liquidità o ridestina somme fra i fondi del condominio.', icon: Repeat2, colorVariant: 'blue' as const },
    { title: 'Coperture da fondo', description: 'Il giroconto fondo → banca è l\'atto che conferma la copertura di una fattura dal fondo.', icon: CheckCircle, colorVariant: 'amber' as const },
    { title: 'Storni', description: 'Annulla un giroconto errato con una scrittura inversa, senza cancellare nulla.', icon: RotateCcw, colorVariant: 'slate' as const },
];

// ── Riallineamento scritture storiche ───────────────────────────────────────
const showGuide = ref(false);
const isRiallineamentoListOpen = ref(false);
const isRiallineaModalOpen = ref(false);
const isRiallineaProcessing = ref(false);

const confirmRiallinea = () => {
    router.post(
        route(generateRoute('gestionale.giroconti.riallinea'), { condominio: props.condominio.id }),
        {},
        {
            preserveScroll: true,
            onStart: () => { isRiallineaProcessing.value = true; },
            onFinish: () => {
                isRiallineaProcessing.value = false;
                isRiallineaModalOpen.value = false;
            },
        }
    );
};

</script>

<template>
    <Head title="Giroconti" />
    <GestionaleLayout>
        <div class="px-6 py-8 space-y-3">
            <PageHeaderGuide
                page-title="Giroconti"
                page-subtitle="Sposta la liquidità fra le casse del condominio, conferma le coperture da fondo e gestisci gli storni contabili."
                :guides="pageGuides"
                :breadcrumbs="(headerBreadcrumbs as any)"
                :condominio="(props.condominio as any)"
                :condomini="(props.condomini as any)"
            >
                <template #actions>
                    <Button variant="outline" size="sm" class="bg-white gap-2 text-violet-700 hover:bg-violet-50 hover:text-violet-800 border-violet-200" @click="showGuide = true">
                        <Repeat2 class="w-4 h-4" />
                        Guida completa
                    </Button>
                </template>
            </PageHeaderGuide>

            <div class="w-full">
                <section class="w-full space-y-4">
                    <MovimentiLayout>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">

                            <!-- Card: Giroconti attivi -->
                            <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm flex items-center gap-4">
                                <div class="bg-violet-50 p-2.5 rounded-lg border border-violet-100">
                                    <Repeat2 class="w-5 h-5 text-violet-600" />
                                </div>
                                <div>
                                    <p class="text-xs text-slate-500 font-medium uppercase tracking-wider">Giroconti attivi</p>
                                    <p class="text-2xl font-black text-slate-900">{{ stats.giroconti_attivi }}</p>
                                </div>
                            </div>

                            <!-- Card: Stornati -->
                            <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm flex items-center gap-4">
                                <div class="bg-slate-100 p-2.5 rounded-lg border border-slate-200">
                                    <RotateCcw class="w-5 h-5 text-slate-500" />
                                </div>
                                <div>
                                    <p class="text-xs text-slate-500 font-medium uppercase tracking-wider">Stornati</p>
                                    <p class="text-2xl font-black text-slate-900">{{ stats.stornati }}</p>
                                </div>
                            </div>

                            <!-- Card: Coperture confermate -->
                            <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm flex items-center gap-4">
                                <div class="bg-emerald-50 p-2.5 rounded-lg border border-emerald-100">
                                    <CheckCircle class="w-5 h-5 text-emerald-600" />
                                </div>
                                <div>
                                    <p class="text-xs text-slate-500 font-medium uppercase tracking-wider">Coperture confermate</p>
                                    <p class="text-2xl font-black text-slate-900">{{ stats.coperture_confermate }}</p>
                                </div>
                            </div>

                        </div>

                        <div v-if="flashMessage" class="mb-3">
                            <Alert :message="flashMessage.message" :type="flashMessage.type" />
                        </div>

                        <!-- Card riallineamento scritture storiche sui fondi -->
                        <div v-if="props.riallineamento.length > 0" class="mb-6 bg-amber-50 border border-amber-200 rounded-xl p-4">
                            <div class="flex items-start gap-3">
                                <div class="bg-amber-100 p-2 rounded-lg border border-amber-200 shrink-0">
                                    <AlertTriangle class="w-5 h-5 text-amber-600" />
                                </div>
                                <div class="flex-1 min-w-0">
                                    <h3 class="text-sm font-bold text-amber-900">Scritture storiche da riallineare</h3>
                                    <p class="text-xs text-amber-700 mt-1 leading-relaxed">
                                        Prima di questa versione le coperture da fondo scrivevano righe direttamente sul conto del fondo.
                                        Queste {{ props.riallineamento.length }} scritture vanno neutralizzate con rettifiche automatiche:
                                        i saldi dei fondi torneranno a riflettere i soli giroconti.
                                    </p>

                                    <Collapsible v-model:open="isRiallineamentoListOpen" class="mt-3">
                                        <CollapsibleTrigger as-child>
                                            <button type="button" class="inline-flex items-center gap-1 text-xs font-semibold text-amber-800 hover:text-amber-900">
                                                <ChevronDown class="w-3.5 h-3.5 transition-transform" :class="{ 'rotate-180': isRiallineamentoListOpen }" />
                                                {{ isRiallineamentoListOpen ? 'Nascondi le scritture' : `Mostra le ${props.riallineamento.length} scritture` }}
                                            </button>
                                        </CollapsibleTrigger>
                                        <CollapsibleContent>
                                            <ul class="mt-2 space-y-1">
                                                <li
                                                    v-for="item in props.riallineamento"
                                                    :key="item.scrittura_id"
                                                    class="flex items-center justify-between gap-3 bg-white/60 border border-amber-100 rounded-md px-3 py-1.5"
                                                >
                                                    <div class="flex items-center gap-2 min-w-0">
                                                        <span class="text-[10px] font-black uppercase tracking-wider text-amber-700 whitespace-nowrap">{{ item.protocollo ?? '—' }}</span>
                                                        <span class="text-[10px] text-amber-600 whitespace-nowrap">{{ item.data ?? '—' }}</span>
                                                        <span class="text-xs text-amber-800 truncate" :title="item.causale ?? ''">{{ item.causale ?? '—' }}</span>
                                                    </div>
                                                    <span class="text-xs font-bold text-amber-900 whitespace-nowrap">{{ euro(item.importo_netto_fondo) }}</span>
                                                </li>
                                            </ul>
                                        </CollapsibleContent>
                                    </Collapsible>
                                </div>
                                <Button
                                    @click="isRiallineaModalOpen = true"
                                    class="shrink-0 h-8 text-xs bg-amber-600 hover:bg-amber-700 text-white"
                                >
                                    Genera le rettifiche
                                </Button>
                            </div>
                        </div>

                        <div>
                            <DataTable
                                :columns="createColumns(props.condominio.id)"
                                :data="props.giroconti.data"
                                :meta="props.giroconti.meta"
                                :condominio="props.condominio"
                            />
                        </div>

                    </MovimentiLayout>
                </section>
            </div>
        </div>

        <!-- Modale conferma riallineamento -->
        <Dialog v-model:open="isRiallineaModalOpen">
            <DialogContent class="sm:max-w-[440px]">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2 text-amber-600">
                        <AlertTriangle class="w-5 h-5" />
                        Genera le rettifiche
                    </DialogTitle>
                    <DialogDescription>
                        Verranno create {{ props.riallineamento.length }} scritture di rettifica nel libro giornale.
                        L'operazione non modifica le scritture esistenti.
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter>
                    <Button variant="outline" @click="isRiallineaModalOpen = false" :disabled="isRiallineaProcessing">
                        Annulla
                    </Button>
                    <Button
                        class="bg-amber-600 hover:bg-amber-700 text-white"
                        @click="confirmRiallinea"
                        :disabled="isRiallineaProcessing"
                    >
                        <Loader2 v-if="isRiallineaProcessing" class="w-4 h-4 mr-2 animate-spin" />
                        Conferma
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <GirocontiGuide v-model:open="showGuide" />
    </GestionaleLayout>
</template>
