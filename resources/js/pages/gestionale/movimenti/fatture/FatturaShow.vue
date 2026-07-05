<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { usePermission } from "@/composables/permissions";
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import Alert from '@/components/Alert.vue';
import type { BreadcrumbItem } from '@/types';
import type { Flash } from '@/types/flash';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { ShieldCheck, FileText, Download, Building2, Calendar, FileSignature, Landmark, ArrowLeft, Stamp, Tags, Paperclip } from "lucide-vue-next";
import { useDateConverter } from '@/composables/useDateConverter';

const props = defineProps<{
    condominio: any;
    condomini: any;
    esercizio: any;
    fattura: any;
    utenteRatifica: string | null;
}>();

const { euro } = useCurrencyFormatter();
const { generateRoute, generatePath } = usePermission();
const { toItalian } = useDateConverter();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: props.condominio.nome, href: '#' },
    { title: 'Fatture e Uscite', href: route(generateRoute('gestionale.fatture.index'), { condominio: props.condominio.id }) },
    { title: `Dettaglio Fattura ${props.fattura.numero_documento}`, href: '#' },
]);

// Stato Approvazione
const isSforoRatificato = computed(() => props.fattura.stato_approvazione === 'approvata' && props.fattura.dati_extra?.ratifica_assembleare);
const statoApprovazioneVariant = computed(() => {
    switch (props.fattura.stato_approvazione) {
        case 'approvata': return 'bg-emerald-100 text-emerald-800 border-emerald-300';
        case 'sforo_motivato': return 'bg-orange-100 text-orange-800 border-orange-300';
        case 'da_approvare': return 'bg-slate-200 text-slate-800 border-slate-300';
        default: return 'bg-slate-100 text-slate-600 border-slate-200';
    }
});

const getStatoApprovazioneLabel = () => {
    if (isSforoRatificato.value) return 'Approvata (Ratificata)';
    switch (props.fattura.stato_approvazione) {
        case 'approvata': return 'Approvata';
        case 'sforo_motivato': return 'Sforo Motivato (Da Ratificare)';
        case 'da_approvare': return 'Da Approvare';
        default: return props.fattura.stato_approvazione;
    }
};

// Stato Pagamento
const statoPagamentoVariant = computed(() => {
    switch (props.fattura.stato_pagamento) {
        case 'pagata': return 'bg-emerald-100 text-emerald-800 border-emerald-300';
        case 'parziale': return 'bg-amber-100 text-amber-800 border-amber-300';
        case 'aperta': return 'bg-blue-100 text-blue-800 border-blue-300';
        case 'stornata': return 'bg-slate-100 text-slate-500 border-slate-300 line-through';
        default: return 'bg-slate-100 text-slate-600 border-slate-200';
    }
});

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

const executeDownload = (documentoId: number) => {
    window.location.href = route(generateRoute('gestionale.fatture.download'), {
        condominio: props.condominio.id,
        fattura: props.fattura.id,
        documento: documentoId
    });
};

</script>

<template>
    <Head :title="`Dettaglio fattura ${props.fattura.numero_documento}`" />

    <GestionaleLayout>
        
        <div class="px-6 py-8 space-y-6">
            <PageHeaderGuide 
                pageTitle="Dettaglio fattura" 
                :pageSubtitle="`Fattura n. ${props.fattura.numero_documento} del Fornitore ${props.fattura.fornitore?.ragione_sociale || 'Sconosciuto'}`"
                icon="FileText"
                :guides="[]"
                :breadcrumbs="(breadcrumbs as any)"
                :condominio="(props.condominio as any)"
                :condomini="(props.condomini as any)"
            >
            <template #actions>
                <Button 
                    variant="outline" 
                    @click="router.visit(route(generateRoute('gestionale.fatture.index'), { condominio: condominio.id }))"
                    class="h-9 gap-2 shadow-sm font-medium"
                >
                    <ArrowLeft class="w-4 h-4" /> Torna all'elenco
                </Button>
            </template>
            </PageHeaderGuide>

            <div v-if="flashMessage">
                <Alert :message="flashMessage.message" :type="flashMessage.type" />
            </div>

            <div class="w-full">
                <section class="w-full space-y-6">

            <!-- AUDIT TRAIL RATIFICA ASSEMBLEARE (Se presente) -->
            <div v-if="isSforoRatificato" class="bg-gradient-to-r from-orange-50 to-amber-50 dark:from-orange-950/30 dark:to-amber-950/20 border border-orange-200 dark:border-orange-800/50 rounded-2xl p-6 shadow-sm flex flex-col md:flex-row items-start md:items-center gap-5">
                <div class="w-14 h-14 shrink-0 bg-orange-100 dark:bg-orange-900/50 rounded-full flex items-center justify-center border-4 border-orange-50/50 dark:border-orange-900/30">
                    <ShieldCheck class="w-7 h-7 text-orange-600 dark:text-orange-400" />
                </div>
                <div class="flex-1 space-y-1">
                    <h3 class="font-black text-orange-900 dark:text-orange-200 text-lg flex items-center gap-2">
                        Ratifica Assembleare <Badge class="bg-orange-200 text-orange-800 hover:bg-orange-200 border-none px-2 py-0.5 text-[10px] uppercase tracking-wider font-bold">Art. 1135 c.c.</Badge>
                    </h3>
                    <p class="text-sm text-orange-800/80 dark:text-orange-300/80 leading-relaxed max-w-3xl">
                        Questa spesa urgente ha superato il preventivo ed è stata registrata originariamente come <strong>sforo motivato</strong>.
                        È stata formalmente ratificata dall'assemblea per sbloccarne il pagamento.
                    </p>
                </div>
                <div class="shrink-0 bg-white/60 dark:bg-slate-900/40 rounded-xl p-4 border border-orange-100 dark:border-orange-900/30 min-w-[280px]">
                    <div class="grid grid-cols-1 gap-2 text-xs">
                        <div class="flex justify-between items-center border-b border-orange-100/50 dark:border-orange-900/30 pb-2">
                            <span class="text-orange-600/70 dark:text-orange-400/70 font-medium uppercase tracking-wider text-[10px]">Ratificata il</span>
                            <span class="font-bold text-orange-900 dark:text-orange-200">{{ new Date(props.fattura.dati_extra.ratifica_assembleare.approvato_il).toLocaleString('it-IT', { dateStyle: 'short', timeStyle: 'short' }) }}</span>
                        </div>
                        <div class="flex justify-between items-center border-b border-orange-100/50 dark:border-orange-900/30 pb-2">
                            <span class="text-orange-600/70 dark:text-orange-400/70 font-medium uppercase tracking-wider text-[10px]">Utente Audit</span>
                            <span class="font-bold text-orange-900 dark:text-orange-200">{{ utenteRatifica || 'Sconosciuto' }}</span>
                        </div>
                        <div class="pt-1">
                            <span class="block text-orange-600/70 dark:text-orange-400/70 font-medium uppercase tracking-wider text-[10px] mb-1">Riferimento Verbale / Note</span>
                            <span class="text-orange-900 dark:text-orange-200 font-medium bg-white/50 dark:bg-slate-900/50 p-2 rounded block italic border border-orange-50 dark:border-orange-900/20">
                                {{ props.fattura.dati_extra.ratifica_assembleare.note || 'Nessuna nota inserita.' }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- HEADER FATTURA E IMPORTI -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                
                <!-- Anagrafica e Documento -->
                <Card class="md:col-span-2 shadow-sm border-slate-200 dark:border-slate-800">
                    <CardHeader class="pb-4">
                        <CardTitle class="flex items-center gap-2 text-lg text-slate-800 dark:text-slate-100">
                            <Building2 class="w-5 h-5 text-slate-400" />
                            Dati Documento
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-6">
                            <div class="space-y-1">
                                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Fornitore</p>
                                <p class="text-sm font-bold text-slate-800 dark:text-slate-200 line-clamp-2">
                                    {{ props.fattura.fornitore?.ragione_sociale || 'N/D' }}
                                </p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Numero Doc.</p>
                                <p class="text-sm font-bold text-slate-800 dark:text-slate-200">{{ props.fattura.numero_documento }}</p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Data Doc.</p>
                                <p class="text-sm font-bold text-slate-800 dark:text-slate-200 flex items-center gap-1.5">
                                    <Calendar class="w-3.5 h-3.5 text-slate-400" />
                                    {{ props.fattura.data_documento ? new Date(props.fattura.data_documento).toLocaleDateString('it-IT') : 'N/D' }}
                                </p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Scadenza</p>
                                <p class="text-sm font-bold flex items-center gap-1.5" :class="new Date(props.fattura.data_scadenza) < new Date() && props.fattura.stato_pagamento !== 'pagata' ? 'text-rose-600' : 'text-slate-800 dark:text-slate-200'">
                                    <Calendar class="w-3.5 h-3.5" :class="new Date(props.fattura.data_scadenza) < new Date() && props.fattura.stato_pagamento !== 'pagata' ? 'text-rose-500' : 'text-slate-400'" />
                                    {{ props.fattura.data_scadenza ? new Date(props.fattura.data_scadenza).toLocaleDateString('it-IT') : 'N/D' }}
                                </p>
                            </div>
                            <div v-if="props.fattura.dati_extra?.fiscal?.ritenuta_details" class="space-y-1 sm:col-span-4 mt-2 pt-4 border-t border-slate-100 dark:border-slate-800/50">
                                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Ritenuta d'Acconto</p>
                                <p class="text-sm font-bold text-slate-800 dark:text-slate-200">
                                    Soggetto a ritenuta del {{ props.fattura.dati_extra.fiscal.ritenuta_details.aliquota }}% 
                                    <span class="text-slate-500 font-normal ml-1">(Tributo: {{ props.fattura.dati_extra.fiscal.ritenuta_details.codice_tributo }})</span>
                                </p>
                            </div>
                        </div>
                        
                        <div class="mt-6 flex flex-wrap gap-2">
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-[11px] font-bold uppercase tracking-wider border shadow-sm" :class="statoApprovazioneVariant">
                                <Stamp class="w-3.5 h-3.5" />
                                {{ getStatoApprovazioneLabel() }}
                            </span>
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-[11px] font-bold uppercase tracking-wider border shadow-sm" :class="statoPagamentoVariant">
                                <Landmark class="w-3.5 h-3.5" />
                                {{ props.fattura.stato_pagamento === 'pagata' ? 'Saldato (Pagata)' : (props.fattura.stato_pagamento === 'aperta' ? 'Da Pagare (Aperta)' : 'Pagamento: ' + props.fattura.stato_pagamento) }}
                            </span>
                            <Button 
                                v-if="props.fattura.stato_pagamento !== 'pagata' && props.fattura.stato_approvazione === 'approvata'"
                                @click="router.visit(route(generateRoute('gestionale.pagamenti-fornitori.create'), { condominio: props.condominio.id, fornitore_id: props.fattura.fornitore_id, fattura_id: props.fattura.id }))"
                                class="h-7 px-3 text-xs gap-1.5 shadow-sm font-medium bg-emerald-600 hover:bg-emerald-700 text-white border-none rounded-md"
                            >
                                <Landmark class="w-3 h-3" /> Paga Fattura
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                <!-- Totali -->
                <Card class="bg-slate-50 dark:bg-slate-900/50 border-slate-200 dark:border-slate-800 shadow-sm">
                    <CardHeader class="pb-2">
                        <CardTitle class="text-sm font-black uppercase tracking-wider text-slate-500">Riepilogo Importi</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="flex justify-between items-center text-sm">
                            <span class="text-slate-500 font-medium">Imponibile</span>
                            <span class="font-bold text-slate-800 dark:text-slate-200">{{ euro(props.fattura.importo_imponibile) }}</span>
                        </div>
                        <div class="flex justify-between items-center text-sm">
                            <span class="text-slate-500 font-medium">Imposta (IVA)</span>
                            <span class="font-bold text-slate-800 dark:text-slate-200">{{ euro(props.fattura.importo_iva) }}</span>
                        </div>
                        <div class="h-px bg-slate-200 dark:bg-slate-800 w-full my-2"></div>
                        <div class="flex justify-between items-end">
                            <span class="text-xs font-black uppercase tracking-wider text-slate-500">Totale Documento</span>
                            <span class="text-2xl font-black text-slate-900 dark:text-white leading-none tracking-tight">{{ euro(props.fattura.totale_documento) }}</span>
                        </div>
                    </CardContent>
                </Card>

            </div>

            <!-- RIGHE FATTURA E DOCUMENTI -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                
                <Card class="lg:col-span-2 shadow-sm border-slate-200 dark:border-slate-800 overflow-hidden">
                    <CardHeader class="bg-slate-50/50 dark:bg-slate-900/20 border-b border-slate-100 dark:border-slate-800">
                        <CardTitle class="flex items-center gap-2 text-base text-slate-800 dark:text-slate-100">
                            <Tags class="w-4 h-4 text-slate-400" /> Dettaglio Righe
                        </CardTitle>
                    </CardHeader>
                    <div class="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow class="hover:bg-transparent">
                                    <TableHead class="w-1/2">Descrizione</TableHead>
                                    <TableHead class="text-right">Imponibile</TableHead>
                                    <TableHead class="text-right">IVA</TableHead>
                                    <TableHead class="text-right font-bold text-slate-800 dark:text-slate-200">Totale</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow v-for="riga in props.fattura.righe" :key="riga.id">
                                    <TableCell>
                                        <div class="font-medium text-sm text-slate-800 dark:text-slate-200 mb-1">{{ riga.descrizione }}</div>
                                        <div v-if="riga.conto" class="text-[10px] text-slate-500 flex items-center gap-1.5">
                                            <span class="w-1.5 h-1.5 rounded-full bg-slate-300"></span>
                                            {{ riga.conto.parent?.nome }} &rsaquo; {{ riga.conto.nome }}
                                        </div>
                                    </TableCell>
                                    <TableCell class="text-right text-slate-600">{{ euro(riga.importo_imponibile) }}</TableCell>
                                    <TableCell class="text-right text-slate-600">{{ euro(riga.importo_iva) }}</TableCell>
                                    <TableCell class="text-right font-bold text-slate-800 dark:text-slate-200">{{ euro(riga.importo_imponibile + riga.importo_iva) }}</TableCell>
                                </TableRow>
                                <TableRow v-if="!props.fattura.righe?.length">
                                    <TableCell colspan="4" class="text-center text-slate-500 py-8">Nessuna riga di dettaglio presente.</TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </div>
                </Card>

                <Card class="shadow-sm border-slate-200 dark:border-slate-800">
                    <CardHeader class="pb-4">
                        <CardTitle class="flex items-center gap-2 text-base text-slate-800 dark:text-slate-100">
                            <Paperclip class="w-4 h-4 text-slate-400" /> Allegati
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div v-if="props.fattura.documenti?.length" class="space-y-3">
                            <div v-for="doc in props.fattura.documenti" :key="doc.id" class="flex items-center justify-between p-3 rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-900/50 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors group">
                                <div class="flex items-center gap-3 overflow-hidden">
                                    <div class="w-8 h-8 rounded bg-red-100 dark:bg-red-900/30 flex items-center justify-center shrink-0">
                                        <FileSignature class="w-4 h-4 text-red-600 dark:text-red-400" />
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-sm font-bold text-slate-800 dark:text-slate-200 truncate">{{ doc.name }}</p>
                                        <p class="text-[10px] text-slate-500 uppercase tracking-wider mt-0.5">{{ doc.file_size ? (doc.file_size / 1024).toFixed(1) + ' KB' : 'PDF' }}</p>
                                    </div>
                                </div>
                                <Button size="icon" variant="ghost" class="shrink-0 h-8 w-8 text-slate-400 group-hover:text-primary" @click="executeDownload(doc.id)" title="Scarica Documento">
                                    <Download class="w-4 h-4" />
                                </Button>
                            </div>
                        </div>
                        <div v-else class="text-center py-8">
                            <FileText class="w-8 h-8 text-slate-200 mx-auto mb-2" />
                            <p class="text-sm text-slate-500">Nessun documento allegato.</p>
                        </div>
                    </CardContent>
                </Card>

            </div>
                </section>
            </div>
        </div>
    </GestionaleLayout>
</template>
