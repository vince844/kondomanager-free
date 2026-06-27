<script setup lang="ts">
import { computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow, TableFooter } from '@/components/ui/table';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { usePermission } from '@/composables/permissions';
import {
    BookOpen, ArrowLeft, Calendar, Building2, Landmark, FileText,
    CircleCheckBig, CircleX, RotateCcw, CreditCard, Banknote,
    ArrowUpRight, ArrowDownLeft, Link2, Shield, Info, ChevronRight,
    Hash, Clock, Layers, ShieldCheck
} from 'lucide-vue-next';
import type { Building } from '@/types/buildings';

// ── Props ────────────────────────────────────────────────────────────────────
const props = defineProps<{
    condominio: Building;
    condomini: Building[];
    scrittura: {
        id: number;
        data_registrazione: string | null;
        data_competenza: string | null;
        numero_protocollo: string | null;
        causale: string | null;
        descrizione: string | null;
        tipo_movimento: string | null;
        tipo_movimento_label: string | null;
        stato: string | null;
        note: string | null;
        created_at: string | null;
        esercizio: { id: number; nome: string } | null;
        gestione: { id: number; nome: string } | null;
        righe: Array<{
            id: number;
            tipo_riga: 'dare' | 'avere';
            importo: number;
            note: string | null;
            conto: { id: number; codice: string | null; nome: string } | null;
            cassa: { id: number; nome: string } | null;
            voce_spesa: { id: number; nome: string } | null;
        }>;
        totale_dare: number;
        totale_avere: number;
        is_quadrata: boolean;
        padre: { id: number; causale: string | null; tipo_movimento_label: string | null } | null;
        figlie: Array<{ id: number; causale: string | null; tipo_movimento_label: string | null; created_at: string | null }>;
        pagamento_fornitore: {
            id: number;
            importo_lordo: number;
            importo_netto: number;
            importo_ritenuta: number;
            importo_commissione: number;
            metodo_pagamento: string | null;
            data_pagamento: string | null;
            iban_beneficiario: string | null;
            causale_bonifico: string | null;
            stato: string | null;
            stato_label: string | null;
            bonifico_parlante: boolean;
            fornitore: { id: number; ragione_sociale: string } | null;
            conto_corrente: { id: number; nome: string } | null;
        } | null;
        fatture: Array<{
            id: number;
            numero_documento: string | null;
            data_documento: string | null;
            tipo_documento: string | null;
            importo_allocato: number;
            tipo_allocazione: string | null;
            dati_extra?: any;
            stato_approvazione?: string;
        }>;
    };
}>();

const { euro } = useCurrencyFormatter();
const { generateRoute, generatePath } = usePermission();

// ── Breadcrumbs ──────────────────────────────────────────────────────────────
const breadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Movimenti' },
    { title: `Scrittura #${props.scrittura.id}` },
]);

// ── Helpers ──────────────────────────────────────────────────────────────────
const tipoMovimentoBadge = computed(() => {
    const tipo = props.scrittura.tipo_movimento;
    if (!tipo) return { class: 'bg-slate-100 text-slate-600 border-slate-200', icon: BookOpen };

    if (tipo.includes('storno')) return { class: 'bg-rose-50 text-rose-700 border-rose-200', icon: RotateCcw };
    if (tipo.includes('pagamento')) return { class: 'bg-blue-50 text-blue-700 border-blue-200', icon: CreditCard };
    if (tipo.includes('incasso')) return { class: 'bg-emerald-50 text-emerald-700 border-emerald-200', icon: Banknote };
    if (tipo.includes('fattura') || tipo.includes('nota_credito')) return { class: 'bg-amber-50 text-amber-700 border-amber-200', icon: FileText };

    return { class: 'bg-slate-50 text-slate-600 border-slate-200', icon: BookOpen };
});

const righeOrdinate = computed(() => {
    // Mostra prima le righe in DARE, poi in AVERE
    return [...props.scrittura.righe].sort((a, b) => {
        if (a.tipo_riga === 'dare' && b.tipo_riga === 'avere') return -1;
        if (a.tipo_riga === 'avere' && b.tipo_riga === 'dare') return 1;
        return 0;
    });
});

const metodoPagamentoLabel = computed(() => {
    const map: Record<string, string> = {
        'bonifico': 'Bonifico',
        'contanti': 'Contanti',
        'assegno': 'Assegno',
        'rid': 'RID / SDD',
        'carta': 'Carta',
    };
    return map[props.scrittura.pagamento_fornitore?.metodo_pagamento ?? ''] ?? props.scrittura.pagamento_fornitore?.metodo_pagamento ?? '';
});

const tipoAllocazioneLabel = (tipo: string | null) => {
    const map: Record<string, string> = {
        'pagamento': 'Pagamento',
        'compensazione': 'Compensazione NC',
        'competenza': 'Competenza',
    };
    return map[tipo ?? ''] ?? tipo ?? '';
};

const navigaAllaScrittura = (scritturaId: number) => {
    router.visit(route(generateRoute('gestionale.scritture.show'), {
        condominio: props.condominio.id,
        scrittura: scritturaId,
    }));
};

const navigaAllaFattura = (fatturaId: number) => {
    router.visit(route(generateRoute('gestionale.fatture.show'), {
        condominio: props.condominio.id,
        fattura: fatturaId,
    }));
};

const tornaPagamenti = () => {
    router.visit(route(generateRoute('gestionale.pagamenti-fornitori.index'), {
        condominio: props.condominio.id,
    }));
};

// ── Audit ────────────────────────────────────────────────────────────────────
const sforoRatificatoAudit = computed(() => {
    if (!props.scrittura.fatture) return null;
    for (const f of props.scrittura.fatture) {
        if (f.stato_approvazione === 'approvata' && f.dati_extra?.ratifica_assembleare) {
            return f.dati_extra.ratifica_assembleare;
        }
    }
    return null;
});
</script>

<template>
    <Head :title="`Scrittura #${scrittura.id} — ${scrittura.tipo_movimento_label}`" />

    <GestionaleLayout>
        <div class="px-6 py-8 space-y-6">

            <!-- ─── HEADER ──────────────────────────────────────────────── -->
            <PageHeaderGuide
                page-title="Dettaglio scrittura contabile"
                :page-subtitle="scrittura.causale || 'Scrittura del Libro Giornale'"
                :guides="[]"
                :breadcrumbs="(breadcrumbs as any)"
                :condominio="(props.condominio as any)"
                :condomini="(props.condomini as any)"
            >
                <template #actions>
                    <Button
                        variant="outline"
                        @click="tornaPagamenti"
                        class="h-9 gap-2 shadow-sm font-medium"
                    >
                        <ArrowLeft class="w-4 h-4" /> Torna ai pagamenti
                    </Button>
                </template>
            </PageHeaderGuide>

            <div class="w-full">
                <section class="w-full space-y-6">

                    <!-- ─── BANNER QUADRATURA ────────────────────────────── -->
                    <div
                        v-if="!scrittura.is_quadrata"
                        class="bg-gradient-to-r from-rose-50 to-red-50 border border-rose-200 rounded-2xl p-5 flex items-center gap-4 shadow-sm"
                    >
                        <div class="w-12 h-12 shrink-0 bg-rose-100 rounded-full flex items-center justify-center border-4 border-rose-50/50">
                            <CircleX class="w-6 h-6 text-rose-600" />
                        </div>
                        <div>
                            <h3 class="font-black text-rose-900 text-base">⚠ Scrittura non quadrata</h3>
                            <p class="text-sm text-rose-700/80 mt-0.5">
                                Totale DARE ({{ euro(scrittura.totale_dare) }}) ≠ Totale AVERE ({{ euro(scrittura.totale_avere) }}).
                                Questa anomalia indica un possibile errore contabile. Contattare l'assistenza.
                            </p>
                        </div>
                    </div>

                    <!-- ─── STORNO BANNER (se è figlia di un'altra) ──────── -->
                    <div
                        v-if="scrittura.padre"
                        class="bg-gradient-to-r from-amber-50 to-orange-50 border border-amber-200 rounded-2xl p-5 flex items-center gap-4 shadow-sm"
                    >
                        <div class="w-12 h-12 shrink-0 bg-amber-100 rounded-full flex items-center justify-center border-4 border-amber-50/50">
                            <RotateCcw class="w-6 h-6 text-amber-600" />
                        </div>
                        <div class="flex-1">
                            <h3 class="font-black text-amber-900 text-base">Movimento di storno</h3>
                            <p class="text-sm text-amber-700/80 mt-0.5">
                                Questa scrittura inverte e annulla i valori registrati nella scrittura originale
                                <button @click="navigaAllaScrittura(scrittura.padre!.id)" class="font-bold text-amber-800 hover:text-amber-950 underline underline-offset-2">
                                    #{{ scrittura.padre!.id }}
                                </button>
                                ({{ scrittura.padre!.tipo_movimento_label }}).
                            </p>
                        </div>
                    </div>

                    <!-- ─── STORNATA BANNER (se ha figlie di storno) ─────── -->
                    <div
                        v-if="scrittura.figlie?.length > 0"
                        class="bg-gradient-to-r from-slate-50 to-gray-50 border border-slate-200 rounded-2xl p-5 flex items-center gap-4 shadow-sm"
                    >
                        <div class="w-12 h-12 shrink-0 bg-slate-100 rounded-full flex items-center justify-center border-4 border-slate-50/50">
                            <RotateCcw class="w-6 h-6 text-slate-500" />
                        </div>
                        <div class="flex-1">
                            <h3 class="font-black text-slate-700 text-base">Attenzione: movimento annullato</h3>
                            <p class="text-sm text-slate-500 mt-0.5">I valori di questa scrittura sono stati stornati dalle seguenti registrazioni:</p>
                            <div class="mt-2 space-y-1">
                                <button
                                    v-for="figlia in scrittura.figlie"
                                    :key="figlia.id"
                                    @click="navigaAllaScrittura(figlia.id)"
                                    class="flex items-center gap-2 text-sm text-slate-600 hover:text-slate-900 transition-colors group"
                                >
                                    <ChevronRight class="w-3.5 h-3.5 text-slate-400 group-hover:text-slate-600 transition-colors" />
                                    <span class="font-bold">Storno #{{ figlia.id }}</span>
                                    <span class="text-slate-400">—</span>
                                    <span>{{ figlia.tipo_movimento_label }}</span>
                                    <span v-if="figlia.created_at" class="text-xs text-slate-400">({{ figlia.created_at }})</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- ─── AUDIT TRAIL RATIFICA ASSEMBLEARE (Se presente in una fattura collegata) ─── -->
                    <div v-if="sforoRatificatoAudit" class="bg-gradient-to-r from-orange-50 to-amber-50 border border-orange-200 rounded-2xl p-5 flex flex-col md:flex-row items-start md:items-center gap-5 shadow-sm">
                        <div class="w-12 h-12 shrink-0 bg-orange-100 rounded-full flex items-center justify-center border-4 border-orange-50/50">
                            <ShieldCheck class="w-6 h-6 text-orange-600" />
                        </div>
                        <div class="flex-1 space-y-1">
                            <h3 class="font-black text-orange-900 text-base flex items-center gap-2">
                                Ratifica assembleare (Sforo Motivato)
                                <Badge class="bg-orange-200 text-orange-800 hover:bg-orange-200 border-none px-2 py-0.5 text-[10px] uppercase tracking-wider font-bold">Art. 1135 c.c.</Badge>
                            </h3>
                            <p class="text-sm text-orange-800/80 leading-relaxed max-w-3xl">
                                Questa scrittura è collegata al pagamento di una spesa urgente che aveva superato il preventivo originario ed è stata formalmente ratificata dall'assemblea per poterne sbloccare il pagamento.
                            </p>
                        </div>
                        <div class="shrink-0 w-full md:w-auto md:max-w-sm bg-white/60 rounded-xl p-4 border border-orange-100 min-w-[280px]">
                            <div class="grid grid-cols-1 gap-2 text-xs">
                                <div class="flex justify-between items-center border-b border-orange-100/50 pb-2">
                                    <span class="text-orange-600/70 font-medium uppercase tracking-wider text-[10px]">Ratificata il</span>
                                    <span class="font-bold text-orange-900">{{ new Date(sforoRatificatoAudit.approvato_il).toLocaleString('it-IT', { dateStyle: 'short', timeStyle: 'short' }) }}</span>
                                </div>
                                <div class="pt-1">
                                    <span class="block text-orange-600/70 font-medium uppercase tracking-wider text-[10px] mb-1">Riferimento Verbale / Note</span>
                                    <span class="text-orange-900 font-medium bg-white/50 p-2 rounded-md block italic border border-orange-50 break-words max-h-32 overflow-y-auto">
                                        {{ sforoRatificatoAudit.note || 'Nessuna nota inserita.' }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ─── GRID: INFO + CONTESTO ────────────────────────── -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

                        <!-- Card: Informazioni Generali -->
                        <Card class="md:col-span-2 shadow-sm border-slate-200">
                            <CardHeader class="pb-4">
                                <CardTitle class="flex items-center gap-2 text-lg text-slate-800">
                                    <BookOpen class="w-5 h-5 text-slate-400" />
                                    Informazioni generali
                                </CardTitle>
                                <CardDescription v-if="scrittura.descrizione" class="mt-1">
                                    {{ scrittura.descrizione }}
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                                    <div class="space-y-1 min-w-0">
                                        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Tipo movimento</p>
                                        <span
                                            class="inline-flex items-center gap-1.5 px-2 py-1 rounded-md text-[11px] font-bold uppercase tracking-wider border max-w-full"
                                            :class="tipoMovimentoBadge.class"
                                        >
                                            <component :is="tipoMovimentoBadge.icon" class="w-3.5 h-3.5 shrink-0" />
                                            <span class="truncate">{{ scrittura.tipo_movimento_label }}</span>
                                        </span>
                                    </div>
                                    <div class="space-y-1">
                                        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Data registrazione</p>
                                        <p class="text-sm font-bold text-slate-800 flex items-center gap-1.5">
                                            <Calendar class="w-3.5 h-3.5 text-slate-400" />
                                            {{ scrittura.data_registrazione || 'N/D' }}
                                        </p>
                                    </div>
                                    <div class="space-y-1">
                                        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Data competenza</p>
                                        <p class="text-sm font-bold text-slate-800 flex items-center gap-1.5">
                                            <Calendar class="w-3.5 h-3.5 text-slate-400" />
                                            {{ scrittura.data_competenza || 'N/D' }}
                                        </p>
                                    </div>
                                    <div class="space-y-1">
                                        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Protocollo</p>
                                        <p class="text-sm font-bold text-slate-800 flex items-center gap-1.5">
                                            <Hash class="w-3.5 h-3.5 text-slate-400" />
                                            {{ scrittura.numero_protocollo || '—' }}
                                        </p>
                                    </div>
                                </div>

                                <!-- Causale -->
                                <div v-if="scrittura.causale" class="mt-6 pt-4 border-t border-slate-100 space-y-1">
                                    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Causale</p>
                                    <p class="text-sm text-slate-700 leading-relaxed bg-slate-50 rounded-lg p-3 border border-slate-100">
                                        {{ scrittura.causale }}
                                    </p>
                                </div>

                                <!-- Note -->
                                <div v-if="scrittura.note" class="mt-4 space-y-1">
                                    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Note</p>
                                    <p class="text-sm text-slate-600 italic">{{ scrittura.note }}</p>
                                </div>
                            </CardContent>
                        </Card>

                        <!-- Card: Contesto Contabile -->
                        <Card class="bg-slate-50 border-slate-200 shadow-sm">
                            <CardHeader class="pb-2">
                                <CardTitle class="text-sm font-black uppercase tracking-wider text-slate-500">
                                    Contesto
                                </CardTitle>
                            </CardHeader>
                            <CardContent class="space-y-4">
                                <div class="flex justify-between items-center text-sm">
                                    <span class="text-slate-500 font-medium flex items-center gap-1.5">
                                        <Layers class="w-3.5 h-3.5" /> Esercizio
                                    </span>
                                    <span class="font-bold text-slate-800">{{ scrittura.esercizio?.nome || 'N/D' }}</span>
                                </div>
                                <div class="flex justify-between items-center text-sm">
                                    <span class="text-slate-500 font-medium flex items-center gap-1.5">
                                        <Building2 class="w-3.5 h-3.5" /> Gestione
                                    </span>
                                    <span class="font-bold text-slate-800">{{ scrittura.gestione?.nome || 'N/D' }}</span>
                                </div>
                                <div class="h-px bg-slate-200 w-full my-2"></div>
                                <div class="flex justify-between items-center text-sm">
                                    <span class="text-slate-500 font-medium flex items-center gap-1.5">
                                        <Shield class="w-3.5 h-3.5" /> Stato
                                    </span>
                                    <Badge class="bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-50 text-[10px] uppercase tracking-wider font-bold">
                                        {{ scrittura.stato || 'registrata' }}
                                    </Badge>
                                </div>
                                <div class="flex justify-between items-center text-sm">
                                    <span class="text-slate-500 font-medium flex items-center gap-1.5">
                                        <Clock class="w-3.5 h-3.5" /> Registrata il
                                    </span>
                                    <span class="font-medium text-slate-600 text-xs">{{ scrittura.created_at || 'N/D' }}</span>
                                </div>
                                <div class="flex justify-between items-center text-sm">
                                    <span class="text-slate-500 font-medium flex items-center gap-1.5">
                                        <Hash class="w-3.5 h-3.5" /> ID Scrittura
                                    </span>
                                    <span class="font-mono text-xs text-slate-500">#{{ scrittura.id }}</span>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    <!-- ─── PARTITA DOPPIA (LEDGER) ──────────────────────── -->
                    <Card class="shadow-sm border-slate-200 overflow-hidden">
                        <CardHeader class="bg-slate-50/50 border-b border-slate-100">
                            <CardTitle class="flex items-center gap-2 text-base text-slate-800">
                                <BookOpen class="w-4.5 h-4.5 text-slate-400" />
                                Partita Doppia
                                <span
                                    v-if="scrittura.is_quadrata"
                                    class="inline-flex items-center gap-1 ml-2 text-[10px] font-bold px-2 py-0.5 rounded-md uppercase tracking-wider bg-emerald-50 text-emerald-700 border border-emerald-200"
                                >
                                    <CircleCheckBig class="w-3 h-3" /> Quadrata
                                </span>
                                <span
                                    v-else
                                    class="inline-flex items-center gap-1 ml-2 text-[10px] font-bold px-2 py-0.5 rounded-md uppercase tracking-wider bg-rose-50 text-rose-700 border border-rose-200"
                                >
                                    <CircleX class="w-3 h-3" /> Non quadrata
                                </span>
                            </CardTitle>
                        </CardHeader>
                        <div class="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow class="hover:bg-transparent bg-gray-50/50">
                                        <TableHead class="w-10 text-center font-bold text-slate-400">#</TableHead>
                                        <TableHead class="w-1/3 font-bold">Conto Contabile</TableHead>
                                        <TableHead class="font-bold">Dettaglio</TableHead>
                                        <TableHead class="text-right font-bold text-emerald-700 w-36">DARE</TableHead>
                                        <TableHead class="text-right font-bold text-blue-700 w-36">AVERE</TableHead>
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
                                            <div class="flex flex-col gap-0.5">
                                                <span v-if="riga.cassa" class="text-xs text-slate-500 flex items-center gap-1">
                                                    <Landmark class="w-3 h-3 text-slate-400" />
                                                    {{ riga.cassa.nome }}
                                                </span>
                                                <span v-if="riga.voce_spesa" class="text-xs text-slate-500 flex items-center gap-1">
                                                    <FileText class="w-3 h-3 text-slate-400" />
                                                    {{ riga.voce_spesa.nome }}
                                                </span>
                                                <span v-if="riga.note" class="text-xs text-slate-400 italic">
                                                    {{ riga.note }}
                                                </span>
                                                <span v-if="!riga.cassa && !riga.voce_spesa && !riga.note" class="text-xs text-slate-300">
                                                    —
                                                </span>
                                            </div>
                                        </TableCell>
                                        <TableCell class="text-right">
                                            <span
                                                v-if="riga.tipo_riga === 'dare'"
                                                class="font-black text-sm text-emerald-700"
                                            >
                                                {{ euro(riga.importo) }}
                                            </span>
                                        </TableCell>
                                        <TableCell class="text-right">
                                            <span
                                                v-if="riga.tipo_riga === 'avere'"
                                                class="font-black text-sm text-blue-700"
                                            >
                                                {{ euro(riga.importo) }}
                                            </span>
                                        </TableCell>
                                    </TableRow>
                                </TableBody>
                                <!-- Footer con totali -->
                                <TableFooter>
                                    <TableRow class="bg-slate-50 hover:bg-slate-50 border-t-2 border-slate-200">
                                        <TableCell colspan="3" class="text-right">
                                            <span class="text-xs font-black uppercase tracking-wider text-slate-500">Totali</span>
                                        </TableCell>
                                        <TableCell class="text-right">
                                            <span class="font-black text-base text-emerald-700">{{ euro(scrittura.totale_dare) }}</span>
                                        </TableCell>
                                        <TableCell class="text-right">
                                            <span class="font-black text-base text-blue-700">{{ euro(scrittura.totale_avere) }}</span>
                                        </TableCell>
                                    </TableRow>
                                </TableFooter>
                            </Table>
                        </div>
                    </Card>

                    <!-- ─── DOCUMENTI COLLEGATI ──────────────────────────── -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                        <!-- Card: Pagamento Fornitore -->
                        <Card v-if="scrittura.pagamento_fornitore" class="shadow-sm border-slate-200">
                            <CardHeader class="pb-4">
                                <CardTitle class="flex items-center gap-2 text-base text-slate-800">
                                    <CreditCard class="w-4.5 h-4.5 text-slate-400" />
                                    Pagamento fornitore
                                </CardTitle>
                                <CardDescription v-if="scrittura.pagamento_fornitore.fornitore">
                                    {{ scrittura.pagamento_fornitore.fornitore.ragione_sociale }}
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div class="space-y-1">
                                        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Importo Lordo</p>
                                        <p class="text-lg font-black text-slate-900">{{ euro(scrittura.pagamento_fornitore.importo_lordo) }}</p>
                                    </div>
                                    <div class="space-y-1">
                                        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Netto Bonificato</p>
                                        <p class="text-lg font-black text-emerald-700">{{ euro(scrittura.pagamento_fornitore.importo_netto) }}</p>
                                    </div>
                                    <div v-if="scrittura.pagamento_fornitore.importo_ritenuta > 0" class="space-y-1">
                                        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Ritenuta d'Acconto</p>
                                        <p class="text-sm font-bold text-slate-700">{{ euro(scrittura.pagamento_fornitore.importo_ritenuta) }}</p>
                                    </div>
                                    <div v-if="scrittura.pagamento_fornitore.importo_commissione > 0" class="space-y-1">
                                        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Commissioni Bancarie</p>
                                        <p class="text-sm font-bold text-slate-700">{{ euro(scrittura.pagamento_fornitore.importo_commissione) }}</p>
                                    </div>
                                </div>

                                <div class="h-px bg-slate-100 my-4"></div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div class="space-y-1">
                                        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Metodo</p>
                                        <p class="text-sm font-bold text-slate-700 uppercase">{{ metodoPagamentoLabel }}</p>
                                    </div>
                                    <div class="space-y-1">
                                        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Data Pagamento</p>
                                        <p class="text-sm font-bold text-slate-700">{{ scrittura.pagamento_fornitore.data_pagamento || 'N/D' }}</p>
                                    </div>
                                    <div v-if="scrittura.pagamento_fornitore.conto_corrente" class="space-y-1">
                                        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Conto Addebito</p>
                                        <p class="text-sm font-bold text-slate-700 flex items-center gap-1.5">
                                            <Building2 class="w-3.5 h-3.5 text-slate-400" />
                                            {{ scrittura.pagamento_fornitore.conto_corrente.nome }}
                                        </p>
                                    </div>
                                    <div class="space-y-1">
                                        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Stato</p>
                                        <div class="flex min-w-0">
                                            <Badge
                                                :class="scrittura.pagamento_fornitore.stato === 'confermato'
                                                    ? 'bg-emerald-50 text-emerald-700 border-emerald-200 hover:bg-emerald-50'
                                                    : 'bg-slate-100 text-slate-500 border-slate-200 hover:bg-slate-100'"
                                                class="text-[10px] uppercase tracking-wider font-bold border truncate max-w-full"
                                            >
                                                {{ scrittura.pagamento_fornitore.stato_label }}
                                            </Badge>
                                        </div>
                                    </div>
                                </div>

                                <!-- IBAN e Causale Bonifico -->
                                <div v-if="scrittura.pagamento_fornitore.iban_beneficiario" class="mt-4 pt-4 border-t border-slate-100 space-y-3">
                                    <div class="space-y-1">
                                        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">IBAN Beneficiario</p>
                                        <p class="text-xs font-mono text-slate-600 bg-slate-50 rounded-md p-2 border border-slate-100 break-all">
                                            {{ scrittura.pagamento_fornitore.iban_beneficiario }}
                                        </p>
                                    </div>
                                    <div v-if="scrittura.pagamento_fornitore.causale_bonifico" class="space-y-1">
                                        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 flex items-center gap-1">
                                            Causale Bonifico
                                            <Badge v-if="scrittura.pagamento_fornitore.bonifico_parlante" class="bg-indigo-50 text-indigo-600 border-indigo-200 hover:bg-indigo-50 text-[8px] uppercase tracking-wider font-bold border ml-1">
                                                Parlante
                                            </Badge>
                                        </p>
                                        <p class="text-xs text-slate-600 bg-slate-50 rounded-md p-2 border border-slate-100 leading-relaxed">
                                            {{ scrittura.pagamento_fornitore.causale_bonifico }}
                                        </p>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        <!-- Card: Fatture Collegate -->
                        <Card v-if="scrittura.fatture?.length > 0" class="shadow-sm border-slate-200 overflow-hidden">
                            <CardHeader class="bg-slate-50/50 border-b border-slate-100 pb-4">
                                <CardTitle class="flex items-center gap-2 text-base text-slate-800">
                                    <FileText class="w-4.5 h-4.5 text-slate-400" />
                                    Fatture Collegate
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
                                            <TableHead class="text-right font-bold">Allocato</TableHead>
                                            <TableHead class="w-10"></TableHead>
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
                                                <span class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-md"
                                                    :class="fattura.tipo_allocazione === 'compensazione'
                                                        ? 'bg-cyan-50 text-cyan-700 border border-cyan-200'
                                                        : 'bg-slate-50 text-slate-600 border border-slate-200'"
                                                >
                                                    {{ tipoAllocazioneLabel(fattura.tipo_allocazione) }}
                                                </span>
                                            </TableCell>
                                            <TableCell class="text-right">
                                                <span class="font-black text-sm" :class="fattura.importo_allocato < 0 ? 'text-rose-600' : 'text-slate-900'">
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

                        <!-- Placeholder se nessun documento collegato -->
                        <Card v-if="!scrittura.pagamento_fornitore && scrittura.fatture?.length === 0" class="shadow-sm border-slate-200 lg:col-span-2">
                            <CardContent class="py-12 text-center">
                                <Info class="w-8 h-8 text-slate-200 mx-auto mb-3" />
                                <p class="text-sm font-medium text-slate-500">Nessun documento collegato a questa scrittura.</p>
                                <p class="text-xs text-slate-400 mt-1">Questa scrittura potrebbe essere stata generata da un processo automatico.</p>
                            </CardContent>
                        </Card>

                    </div>

                </section>
            </div>
        </div>
    </GestionaleLayout>
</template>
