<script setup lang="ts">
import { computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import { usePermission } from "@/composables/permissions";
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import type { BreadcrumbItem } from '@/types';
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { ArrowLeft, Landmark, Calendar, User, FileText, Banknote, Coins, RotateCcw } from "lucide-vue-next";

const props = defineProps<{
    condominio: any;
    condomini: any;
    esercizio: any;
    incasso: any;
    incassoFormatted: any;
    utenteCreatore: string | null;
    utenteStornatore: string | null;
}>();

const { generateRoute, generatePath } = usePermission();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: props.condominio.nome, href: '#' },
    { title: 'Incassi Rate', href: route(generateRoute('gestionale.movimenti-rate.index'), { condominio: props.condominio.id }) },
    { title: `Dettaglio Incasso ${props.incasso.numero_protocollo}`, href: '#' },
]);

const statoVariant = computed(() => {
    switch (props.incasso.stato) {
        case 'confermato': return 'bg-emerald-100 text-emerald-800 border-emerald-300';
        case 'stornato': return 'bg-rose-100 text-rose-800 border-rose-300 line-through';
        default: return 'bg-slate-100 text-slate-600 border-slate-200';
    }
});

// Il totale versato da solo non basta a capire se una rata è stata saldata
// con denaro reale o con credito pregresso (specialmente a cassa zero, dove
// l'importo è correttamente € 0,00 ma non è affatto ovvio perché).
const modalitaVersamento = computed(() => {
    const righe = props.incassoFormatted.dettagli_rate || [];
    const haCredito = righe.some((r: any) => r.tipo === 'credito');
    const haContanti = righe.some((r: any) => r.tipo === 'contanti');

    if (haCredito && !haContanti) return 'credito';
    if (haCredito && haContanti) return 'misto';
    return null;
});

</script>

<template>
    <Head :title="`Dettaglio Incasso ${props.incasso.numero_protocollo}`" />

    <GestionaleLayout>

        <div class="px-6 py-8 space-y-6">
            <PageHeaderGuide
                pageTitle="Dettaglio Incasso"
                :pageSubtitle="`Protocollo ${props.incasso.numero_protocollo} — Registrato da ${utenteCreatore || 'Sistema'}`"
                icon="Banknote"
                :guides="[]"
                :breadcrumbs="(breadcrumbs as any)"
                :condominio="(props.condominio as any)"
                :condomini="(props.condomini as any)"
            >
            <template #actions>
                <Button
                    variant="outline"
                    @click="router.visit(route(generateRoute('gestionale.movimenti-rate.index'), { condominio: condominio.id }))"
                    class="h-9 gap-2 shadow-sm font-medium"
                >
                    <ArrowLeft class="w-4 h-4" /> Torna agli incassi
                </Button>
            </template>
            </PageHeaderGuide>

            <div class="w-full">
                <section class="w-full space-y-6">

            <!-- AUDIT TRAIL STORNO (Se presente) -->
            <div v-if="props.incasso.stato === 'stornato'" class="bg-gradient-to-r from-rose-50 to-red-50 dark:from-rose-950/30 dark:to-red-950/20 border border-rose-200 dark:border-rose-800/50 rounded-2xl p-6 shadow-sm flex flex-col md:flex-row items-start md:items-center gap-5">
                <div class="w-14 h-14 shrink-0 bg-rose-100 dark:bg-rose-900/50 rounded-full flex items-center justify-center border-4 border-rose-50/50 dark:border-rose-900/30">
                    <RotateCcw class="w-7 h-7 text-rose-600 dark:text-rose-400" />
                </div>
                <div class="flex-1 space-y-1">
                    <h3 class="font-black text-rose-900 dark:text-rose-200 text-lg flex items-center gap-2">
                        Movimento Stornato <Badge class="bg-rose-200 text-rose-800 hover:bg-rose-200 border-none px-2 py-0.5 text-[10px] uppercase tracking-wider font-bold">Annullato</Badge>
                    </h3>
                    <p class="text-sm text-rose-800/80 dark:text-rose-300/80 leading-relaxed max-w-3xl">
                        Questo incasso è stato annullato contabilmente. Le rate ad esso collegate sono state ripristinate nello scadenziario come "Da pagare". L'importo in Cassa/Banca è stato stornato.
                    </p>
                </div>
                <div class="shrink-0 bg-white/60 dark:bg-slate-900/40 rounded-xl p-4 border border-rose-100 dark:border-rose-900/30 min-w-[280px]">
                    <div class="grid grid-cols-1 gap-2 text-xs">
                        <div class="flex justify-between items-center border-b border-rose-100/50 dark:border-rose-900/30 pb-2">
                            <span class="text-rose-600/70 dark:text-rose-400/70 font-medium uppercase tracking-wider text-[10px]">Stornato il</span>
                            <span class="font-bold text-rose-900 dark:text-rose-200">{{ new Date(props.incasso.updated_at).toLocaleString('it-IT', { dateStyle: 'short', timeStyle: 'short' }) }}</span>
                        </div>
                        <div class="flex justify-between items-center border-b border-rose-100/50 dark:border-rose-900/30 pb-2">
                            <span class="text-rose-600/70 dark:text-rose-400/70 font-medium uppercase tracking-wider text-[10px]">Operatore</span>
                            <span class="font-bold text-rose-900 dark:text-rose-200">{{ utenteStornatore || 'Sconosciuto' }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- HEADER INCASSO -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

                <!-- Dati Principali -->
                <Card class="md:col-span-2 shadow-sm border-slate-200 dark:border-slate-800">
                    <CardHeader class="pb-4">
                        <CardTitle class="flex items-center gap-2 text-lg text-slate-800 dark:text-slate-100">
                            <User class="w-5 h-5 text-slate-400" />
                            Estremi Versamento
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-6">
                            <div class="space-y-1 sm:col-span-2">
                                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Soggetto Pagante</p>
                                <p class="text-sm font-bold text-slate-800 dark:text-slate-200">
                                    {{ props.incassoFormatted.pagante.lista_completa || 'Sconosciuto' }}
                                </p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Ruolo</p>
                                <p class="text-sm font-bold text-slate-800 dark:text-slate-200">{{ props.incassoFormatted.pagante.ruolo }}</p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Data Operazione</p>
                                <p class="text-sm font-bold text-slate-800 dark:text-slate-200 flex items-center gap-1.5">
                                    <Calendar class="w-3.5 h-3.5 text-slate-400" />
                                    {{ new Date(props.incasso.data_competenza).toLocaleDateString('it-IT') }}
                                </p>
                            </div>
                            <div class="space-y-1 sm:col-span-2 mt-2 pt-4 border-t border-slate-100 dark:border-slate-800/50">
                                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Conto di Accredito</p>
                                <p v-if="props.incassoFormatted.cassa_nome === 'N/D' && modalitaVersamento === 'credito'" class="text-sm font-bold text-blue-600 dark:text-blue-400 flex items-center gap-1.5">
                                    <Coins class="w-4 h-4 text-blue-500" />
                                    Nessuno — saldato interamente con credito
                                </p>
                                <p v-else class="text-sm font-bold text-slate-800 dark:text-slate-200 flex items-center gap-1.5">
                                    <Landmark class="w-4 h-4 text-slate-400" />
                                    {{ props.incassoFormatted.cassa_nome }}
                                </p>
                            </div>
                            <div class="space-y-1 sm:col-span-2 mt-2 pt-4 border-t border-slate-100 dark:border-slate-800/50">
                                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Causale Contabile</p>
                                <p class="text-sm text-slate-700 dark:text-slate-300 italic">
                                    {{ props.incasso.causale || 'Nessuna causale specificata' }}
                                </p>
                            </div>
                        </div>

                        <div class="mt-6 flex flex-wrap gap-2">
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-[11px] font-bold uppercase tracking-wider border shadow-sm" :class="statoVariant">
                                <FileText class="w-3.5 h-3.5" />
                                {{ props.incasso.stato === 'stornato' ? 'Annullato' : 'Registrato' }}
                            </span>
                        </div>
                    </CardContent>
                </Card>

                <!-- Totali -->
                <Card class="bg-slate-50 dark:bg-slate-900/50 border-slate-200 dark:border-slate-800 shadow-sm flex flex-col justify-center">
                    <CardHeader class="pb-2 text-center">
                        <CardTitle class="text-sm font-black uppercase tracking-wider text-slate-500">Totale Versamento</CardTitle>
                    </CardHeader>
                    <CardContent class="text-center">
                        <div class="flex flex-col justify-center items-center py-4 gap-1">
                            <span class="text-4xl font-black" :class="props.incasso.stato === 'stornato' ? 'text-slate-400 line-through' : 'text-emerald-600 dark:text-emerald-400'">
                                {{ props.incassoFormatted.importo_totale_formatted }}
                            </span>
                            <span v-if="modalitaVersamento === 'credito'" class="inline-flex items-center gap-1 text-xs font-medium text-blue-600 dark:text-blue-400">
                                <Coins class="w-3.5 h-3.5" /> Saldato con credito pregresso
                            </span>
                            <span v-else-if="modalitaVersamento === 'misto'" class="inline-flex items-center gap-1 text-xs font-medium text-indigo-600 dark:text-indigo-400">
                                <Coins class="w-3.5 h-3.5" /> Contanti + credito pregresso
                            </span>
                        </div>
                    </CardContent>
                </Card>

            </div>

            <!-- RIGHE FATTURA E DOCUMENTI -->
            <div class="grid grid-cols-1 gap-6">

                <Card class="shadow-sm border-slate-200 dark:border-slate-800 overflow-hidden">
                    <CardHeader class="bg-slate-50/50 dark:bg-slate-900/20 border-b border-slate-100 dark:border-slate-800 flex flex-row items-center justify-between">
                        <CardTitle class="flex items-center gap-2 text-base text-slate-800 dark:text-slate-100">
                            <Coins class="w-4 h-4 text-slate-400" /> Spaccato Copertura Rate
                        </CardTitle>
                    </CardHeader>
                    <div class="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow class="hover:bg-transparent">
                                    <TableHead>Rata</TableHead>
                                    <TableHead>Unità</TableHead>
                                    <TableHead>Scadenza Originale</TableHead>
                                    <TableHead>Metodo di Copertura</TableHead>
                                    <TableHead class="text-right font-bold text-slate-800 dark:text-slate-200">Importo Coperto</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow v-for="(rata, index) in props.incassoFormatted.dettagli_rate" :key="index">
                                    <TableCell>
                                        <div class="font-medium text-sm text-slate-800 dark:text-slate-200">Rata {{ rata.numero }}</div>
                                    </TableCell>
                                    <TableCell>
                                        <div class="text-sm text-slate-600">Int. {{ rata.immobile || 'N/D' }}</div>
                                    </TableCell>
                                    <TableCell class="text-slate-600">{{ rata.scadenza }}</TableCell>
                                    <TableCell>
                                        <span v-if="rata.tipo === 'contanti'" class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider bg-emerald-100 text-emerald-800 border border-emerald-200">
                                            <Banknote class="w-3 h-3" /> Cash / Bonifico
                                        </span>
                                        <span v-else class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider bg-blue-100 text-blue-800 border border-blue-200">
                                            <Coins class="w-3 h-3" /> Credito Pregresso
                                        </span>
                                    </TableCell>
                                    <TableCell class="text-right font-bold text-slate-800 dark:text-slate-200">{{ rata.importo_formatted }}</TableCell>
                                </TableRow>
                                <TableRow v-if="!props.incassoFormatted.dettagli_rate?.length">
                                    <TableCell colspan="5" class="text-center text-slate-500 py-8">Nessun dettaglio rata presente per questo incasso.</TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </div>
                </Card>

            </div>
                </section>
            </div>
        </div>
    </GestionaleLayout>
</template>
