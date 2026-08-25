<script setup lang="ts">
import { computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import Alert from '@/components/Alert.vue';
import type { Flash } from '@/types/flash';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow, TableFooter } from '@/components/ui/table';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { usePermission } from '@/composables/permissions';
import {
    ArrowLeft, Calendar, Building2, Landmark, FileText,
    CreditCard, Banknote, BookOpen, ArrowUpRight, RotateCcw,
    CircleCheckBig, Hash, Printer
} from 'lucide-vue-next';
import type { Building } from '@/types/buildings';

// ── Props ─────────────────────────────────────────────────────────────────────
const props = defineProps<{
    condominio: Building;
    condomini: Building[];
    esercizio: any;
    pagamento: {
        id: number;
        data_pagamento: string | null;
        data_pagamento_fmt: string | null;
        metodo_pagamento: string | null;
        stato: string | null;
        importo_lordo: number;
        importo_netto: number;
        importo_ritenuta: number;
        importo_commissione: number;
        bonifico_parlante: boolean;
        tipo_detrazione: string | null;
        is_storno: boolean;
        scrittura_contabile_id: number | null;
        fornitore: { id: number; ragione_sociale: string } | null;
        fornitore_snapshot: any;
        conto_corrente: { id: number; nome: string; iban: string | null } | null;
        scrittura: { id: number; descrizione: string } | null;
    };
    scrittura: {
        id: number;
        numero_protocollo: string | null;
        causale: string | null;
        tipo_movimento: string | null;
        data_registrazione: string | null;
        righe: Array<{
            id: number;
            tipo_riga: 'dare' | 'avere';
            importo: number;
            note: string | null;
            conto: { id: number; codice: string | null; nome: string } | null;
        }>;
        fatture: Array<{
            id: number;
            numero_documento: string | null;
            data_documento: string | null;
            tipo_documento: string | null;
            importo_allocato: number;
            tipo_allocazione: string | null;
        }>;
    } | null;
}>();

const { euro } = useCurrencyFormatter();
const { generateRoute, generatePath } = usePermission();
const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

// ── Breadcrumbs ───────────────────────────────────────────────────────────────
const breadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Pagamenti fornitori', href: route(generateRoute('gestionale.pagamenti-fornitori.index'), { condominio: props.condominio.id }) },
    { title: `Pagamento #${props.pagamento.id}` },
]);

// ── Helpers ───────────────────────────────────────────────────────────────────
const ragioneSociale = computed(() =>
    props.pagamento.fornitore?.ragione_sociale
    ?? props.pagamento.fornitore_snapshot?.ragione_sociale
    ?? 'Fornitore eliminato'
);

const metodoPagamentoLabel = computed(() => {
    const map: Record<string, string> = {
        'bonifico': 'Bonifico',
        'contanti': 'Contanti',
        'assegno': 'Assegno',
        'rid': 'RID / SDD',
        'carta': 'Carta',
    };
    return map[props.pagamento.metodo_pagamento ?? ''] ?? props.pagamento.metodo_pagamento ?? '—';
});

const statoBadge = computed(() => {
    const stato = props.pagamento.stato;
    if (stato === 'confermato') return 'bg-emerald-50 text-emerald-700 border-emerald-200 hover:bg-emerald-50';
    if (stato === 'stornato')  return 'bg-rose-50 text-rose-700 border-rose-200 hover:bg-rose-50';
    return 'bg-slate-100 text-slate-500 border-slate-200 hover:bg-slate-100';
});

const righeOrdinate = computed(() => {
    if (!props.scrittura?.righe) return [];
    return [...props.scrittura.righe].sort((a, b) => {
        if (a.tipo_riga === 'dare' && b.tipo_riga === 'avere') return -1;
        if (a.tipo_riga === 'avere' && b.tipo_riga === 'dare') return 1;
        return 0;
    });
});

const totaleDare = computed(() => righeOrdinate.value.filter(r => r.tipo_riga === 'dare').reduce((s, r) => s + r.importo, 0));
const totaleAvere = computed(() => righeOrdinate.value.filter(r => r.tipo_riga === 'avere').reduce((s, r) => s + r.importo, 0));

const tipoAllocazioneLabel = (tipo: string | null) => {
    const map: Record<string, string> = {
        'pagamento': 'Pagamento',
        'compensazione': 'Compensazione NC',
    };
    return map[tipo ?? ''] ?? tipo ?? '—';
};

const navigaAllaFattura = (fatturaId: number) => {
    router.visit(route(generateRoute('gestionale.fatture.show'), {
        condominio: props.condominio.id,
        fattura: fatturaId,
    }));
};

const navigaAllaScrittura = () => {
    if (!props.scrittura) return;
    router.visit(route(generateRoute('gestionale.scritture.show'), {
        condominio: props.condominio.id,
        scrittura: props.scrittura.id,
    }));
};

const scaricaDistinta = () => {
    window.open(
        route(generateRoute('gestionale.pagamenti-fornitori.distinta'), {
            condominio: props.condominio.id,
            pagamento: props.pagamento.id,
        }),
        '_blank'
    );
};
</script>

<template>
    <Head :title="`Pagamento #${pagamento.id} — ${ragioneSociale}`" />

    <GestionaleLayout>
        <div class="px-6 py-8 space-y-6">

            <!-- ─── HEADER ──────────────────────────────────────────────────── -->
            <PageHeaderGuide
                page-title="Dettaglio pagamento fornitore"
                :page-subtitle="ragioneSociale"
                :guides="[]"
                :breadcrumbs="(breadcrumbs as any)"
                :condominio="(props.condominio as any)"
                :condomini="(props.condomini as any)"
            >
                <template #actions>
                    <Button
                        variant="outline"
                        @click="scaricaDistinta"
                        class="h-9 gap-2 shadow-sm font-medium"
                    >
                        <Printer class="w-4 h-4" /> Distinta PDF
                    </Button>
                    <Button
                        variant="outline"
                        @click="router.visit(route(generateRoute('gestionale.pagamenti-fornitori.index'), { condominio: props.condominio.id }))"
                        class="h-9 gap-2 shadow-sm font-medium"
                    >
                        <ArrowLeft class="w-4 h-4" /> Torna ai pagamenti
                    </Button>
                </template>
            </PageHeaderGuide>

            <div v-if="flashMessage">
                <Alert :message="flashMessage.message" :type="flashMessage.type" />
            </div>

            <!-- ─── BANNER STORNO ───────────────────────────────────────────── -->
            <div
                v-if="pagamento.is_storno || pagamento.stato === 'stornato'"
                class="bg-gradient-to-r from-rose-50 to-red-50 border border-rose-200 rounded-2xl p-5 flex items-center gap-4 shadow-sm"
            >
                <div class="w-12 h-12 shrink-0 bg-rose-100 rounded-full flex items-center justify-center border-4 border-rose-50/50">
                    <RotateCcw class="w-6 h-6 text-rose-600" />
                </div>
                <div>
                    <h3 class="font-black text-rose-900 text-base">
                        {{ pagamento.is_storno ? 'Questo è un movimento di storno' : 'Pagamento stornato' }}
                    </h3>
                    <p class="text-sm text-rose-700/80 mt-0.5">
                        {{ pagamento.is_storno
                            ? 'Questo record rappresenta la scrittura inversa che ha annullato un pagamento precedente.'
                            : 'Questo pagamento è stato annullato tramite storno. Le fatture collegate sono state riaperte.' }}
                    </p>
                </div>
            </div>

            <div class="space-y-6">

                <!-- ─── GRID PRINCIPALE: Riepilogo + Metodo ──────────────────── -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

                    <!-- Card: Riepilogo importi -->
                    <Card class="md:col-span-2 shadow-sm border-slate-200">
                        <CardHeader class="pb-4">
                            <CardTitle class="flex items-center gap-2 text-base text-slate-800">
                                <CreditCard class="w-4 h-4 text-slate-400" />
                                Riepilogo importi
                                <Badge
                                    :class="statoBadge"
                                    class="ml-auto text-[10px] uppercase tracking-wider font-bold border"
                                >
                                    {{ pagamento.stato === 'confermato' ? 'Confermato'
                                     : pagamento.stato === 'stornato' ? 'Stornato'
                                     : pagamento.stato ?? 'N/D' }}
                                </Badge>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-6">
                                <div class="space-y-1">
                                    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Importo lordo</p>
                                    <p class="text-2xl font-black text-slate-900 tabular-nums">{{ euro(pagamento.importo_lordo) }}</p>
                                </div>
                                <div class="space-y-1">
                                    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Netto bonificato</p>
                                    <p class="text-2xl font-black text-emerald-700 tabular-nums">{{ euro(pagamento.importo_netto) }}</p>
                                </div>
                                <div v-if="pagamento.importo_ritenuta > 0" class="space-y-1">
                                    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Ritenuta d'acconto</p>
                                    <p class="text-base font-bold text-amber-700 tabular-nums">{{ euro(pagamento.importo_ritenuta) }}</p>
                                    <p class="text-[10px] text-slate-400">F24 da versare</p>
                                </div>
                                <div v-if="pagamento.importo_commissione > 0" class="space-y-1">
                                    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Commissioni</p>
                                    <p class="text-base font-bold text-slate-600 tabular-nums">{{ euro(pagamento.importo_commissione) }}</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- Card: Dettagli operazione -->
                    <Card class="bg-slate-50 border-slate-200 shadow-sm">
                        <CardHeader class="pb-2">
                            <CardTitle class="text-sm font-black uppercase tracking-wider text-slate-500">Dettagli operazione</CardTitle>
                        </CardHeader>
                        <CardContent class="space-y-4">
                            <div class="flex justify-between items-center text-sm">
                                <span class="text-slate-500 font-medium flex items-center gap-1.5">
                                    <Calendar class="w-3.5 h-3.5" /> Data pagamento
                                </span>
                                <span class="font-bold text-slate-800">{{ pagamento.data_pagamento_fmt || '—' }}</span>
                            </div>
                            <div class="flex justify-between items-center text-sm">
                                <span class="text-slate-500 font-medium flex items-center gap-1.5">
                                    <Banknote class="w-3.5 h-3.5" /> Metodo
                                </span>
                                <span class="font-bold text-slate-800">{{ metodoPagamentoLabel }}</span>
                            </div>
                            <div v-if="pagamento.conto_corrente" class="flex justify-between items-center text-sm">
                                <span class="text-slate-500 font-medium flex items-center gap-1.5">
                                    <Building2 class="w-3.5 h-3.5" /> Conto addebito
                                </span>
                                <span class="font-bold text-slate-800">{{ pagamento.conto_corrente.nome }}</span>
                            </div>
                            <div class="h-px bg-slate-200 w-full" />
                            <div class="flex justify-between items-center text-sm">
                                <span class="text-slate-500 font-medium flex items-center gap-1.5">
                                    <Hash class="w-3.5 h-3.5" /> ID pagamento
                                </span>
                                <span class="font-mono text-xs text-slate-500">#{{ pagamento.id }}</span>
                            </div>
                            <!-- Link alla scrittura contabile -->
                            <div v-if="scrittura" class="flex justify-between items-start text-sm">
                                <span class="text-slate-500 font-medium flex items-center gap-1.5">
                                    <BookOpen class="w-3.5 h-3.5" /> Scrittura
                                </span>
                                <button
                                    @click="navigaAllaScrittura"
                                    class="font-bold text-blue-600 hover:text-blue-800 flex items-center gap-1 transition-colors"
                                >
                                    #{{ scrittura.id }}
                                    <ArrowUpRight class="w-3 h-3" />
                                </button>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <!-- ─── PARTITA DOPPIA ──────────────────────────────────────── -->
                <Card v-if="scrittura && righeOrdinate.length > 0" class="shadow-sm border-slate-200 overflow-hidden">
                    <CardHeader class="bg-slate-50/50 border-b border-slate-100">
                        <CardTitle class="flex items-center gap-2 text-base text-slate-800">
                            <BookOpen class="w-4 h-4 text-slate-400" />
                            Partita doppia — {{ scrittura.numero_protocollo ?? `#${scrittura.id}` }}
                            <span class="inline-flex items-center gap-1 ml-2 text-[10px] font-bold px-2 py-0.5 rounded-md uppercase tracking-wider bg-emerald-50 text-emerald-700 border border-emerald-200">
                                <CircleCheckBig class="w-3 h-3" /> Quadrata
                            </span>
                        </CardTitle>
                    </CardHeader>
                    <div class="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow class="hover:bg-transparent bg-gray-50/50">
                                    <TableHead class="w-8 text-center font-bold text-slate-400">#</TableHead>
                                    <TableHead class="font-bold">Conto contabile</TableHead>
                                    <TableHead class="font-bold">Note</TableHead>
                                    <TableHead class="text-right font-bold text-emerald-700 w-32">DARE</TableHead>
                                    <TableHead class="text-right font-bold text-blue-700 w-32">AVERE</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow
                                    v-for="(riga, index) in righeOrdinate"
                                    :key="riga.id"
                                    class="hover:bg-gray-50/50 transition-colors"
                                >
                                    <TableCell class="text-center">
                                        <span class="text-[10px] font-bold text-slate-400">{{ index + 1 }}</span>
                                    </TableCell>
                                    <TableCell>
                                        <div class="flex flex-col gap-0.5">
                                            <span class="text-sm font-bold text-slate-800">
                                                {{ riga.conto?.nome || 'Conto non specificato' }}
                                            </span>
                                            <span v-if="riga.conto?.codice" class="text-[10px] text-slate-400 font-mono">
                                                {{ riga.conto.codice }}
                                            </span>
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <span class="text-xs text-slate-400 italic">{{ riga.note || '—' }}</span>
                                    </TableCell>
                                    <TableCell class="text-right">
                                        <span v-if="riga.tipo_riga === 'dare'" class="font-black text-sm text-emerald-700 tabular-nums">
                                            {{ euro(riga.importo) }}
                                        </span>
                                    </TableCell>
                                    <TableCell class="text-right">
                                        <span v-if="riga.tipo_riga === 'avere'" class="font-black text-sm text-blue-700 tabular-nums">
                                            {{ euro(riga.importo) }}
                                        </span>
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                            <TableFooter>
                                <TableRow class="bg-slate-50 hover:bg-slate-50 border-t-2 border-slate-200">
                                    <TableCell colspan="3" class="text-right">
                                        <span class="text-xs font-black uppercase tracking-wider text-slate-500">Totali</span>
                                    </TableCell>
                                    <TableCell class="text-right">
                                        <span class="font-black text-base text-emerald-700 tabular-nums">{{ euro(totaleDare) }}</span>
                                    </TableCell>
                                    <TableCell class="text-right">
                                        <span class="font-black text-base text-blue-700 tabular-nums">{{ euro(totaleAvere) }}</span>
                                    </TableCell>
                                </TableRow>
                            </TableFooter>
                        </Table>
                    </div>
                </Card>

                <!-- ─── FATTURE COLLEGATE ───────────────────────────────────── -->
                <Card v-if="scrittura?.fatture?.length" class="shadow-sm border-slate-200 overflow-hidden">
                    <CardHeader class="bg-slate-50/50 border-b border-slate-100 pb-4">
                        <CardTitle class="flex items-center gap-2 text-base text-slate-800">
                            <FileText class="w-4 h-4 text-slate-400" />
                            Fatture saldate con questo pagamento
                            <span class="ml-auto text-xs font-bold text-slate-400 bg-slate-100 rounded-full px-2 py-0.5">
                                {{ scrittura.fatture.length }}
                            </span>
                        </CardTitle>
                    </CardHeader>
                    <div class="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow class="hover:bg-transparent">
                                    <TableHead class="font-bold">Documento</TableHead>
                                    <TableHead class="font-bold">Tipo</TableHead>
                                    <TableHead class="text-right font-bold">Importo allocato</TableHead>
                                    <TableHead class="w-8" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow
                                    v-for="fattura in scrittura.fatture"
                                    :key="fattura.id"
                                    class="hover:bg-blue-50/30 transition-colors group cursor-pointer"
                                    @click="navigaAllaFattura(fattura.id)"
                                >
                                    <TableCell>
                                        <div class="flex flex-col gap-0.5">
                                            <span class="text-sm font-bold text-slate-800 group-hover:text-blue-700 transition-colors">
                                                {{ fattura.numero_documento || `#${fattura.id}` }}
                                            </span>
                                            <span v-if="fattura.data_documento" class="text-[10px] text-slate-400">
                                                {{ fattura.data_documento }}
                                            </span>
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <span
                                            class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-md border"
                                            :class="fattura.tipo_allocazione === 'compensazione'
                                                ? 'bg-cyan-50 text-cyan-700 border-cyan-200'
                                                : 'bg-slate-50 text-slate-600 border-slate-200'"
                                        >
                                            {{ tipoAllocazioneLabel(fattura.tipo_allocazione) }}
                                        </span>
                                    </TableCell>
                                    <TableCell class="text-right">
                                        <span
                                            class="font-black text-sm tabular-nums"
                                            :class="fattura.importo_allocato < 0 ? 'text-rose-600' : 'text-slate-900'"
                                        >
                                            {{ euro(fattura.importo_allocato) }}
                                        </span>
                                    </TableCell>
                                    <TableCell>
                                        <ArrowUpRight class="w-3.5 h-3.5 text-slate-300 group-hover:text-blue-500 transition-colors" />
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </div>
                </Card>

                <!-- Conto corrente IBAN (se bonifico) -->
                <Card
                    v-if="pagamento.conto_corrente?.iban"
                    class="shadow-sm border-slate-200"
                >
                    <CardHeader class="pb-3">
                        <CardTitle class="flex items-center gap-2 text-sm text-slate-700">
                            <Landmark class="w-4 h-4 text-slate-400" /> Conto di addebito
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p class="text-xs font-mono text-slate-600 bg-slate-50 rounded-md p-3 border border-slate-100 break-all">
                            {{ pagamento.conto_corrente.iban }}
                        </p>
                    </CardContent>
                </Card>

            </div>
        </div>
    </GestionaleLayout>
</template>
