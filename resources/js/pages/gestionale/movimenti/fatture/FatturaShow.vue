<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { usePermission } from "@/composables/permissions";
import { useConfermaEliminazione } from '@/composables/useConfermaEliminazione';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import Alert from '@/components/Alert.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import type { BreadcrumbItem } from '@/types';
import type { Flash } from '@/types/flash';
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from "@/components/ui/tooltip";
import { ShieldCheck, FileText, Download, Building2, Calendar, FileSignature, Landmark, Banknote, ArrowLeft, Stamp, Tags, Paperclip, Repeat2, CircleCheckBig, Trash2, Upload, Lock } from "lucide-vue-next";

const props = defineProps<{
    condominio: any;
    condomini: any;
    esercizio: any;
    fattura: any;
    utenteRatifica: string | null;
    motivoBloccoModifica: string | null;
}>();

const { euro } = useCurrencyFormatter();
const { generateRoute, generatePath } = usePermission();

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

const page = usePage<{ flash: { message?: Flash }; errors: Record<string, string> }>();
const flashMessage = computed(() => page.props.flash.message);

// ⚠️ Prima di questa correzione (revisione avversariale, beta.12) un upload
// respinto da StoreFatturaDocumentoRequest — file troppo grande, formato non
// ammesso — tornava con `errors.file` valorizzato e `flash.message` a null:
// la pagina non mostrava letteralmente niente, e l'amministratore non poteva
// sapere se il pulsante fosse rotto o l'allegato fosse davvero salito.
const erroreAllegato = computed(() => page.props.errors?.file);

// ── Coperture da fondo (beta.19) ─────────────────────────────────────────────
// Una copertura 'pianificata' aspetta il giroconto di conferma: finché non
// arriva, il fondo non è stato toccato. Una 'confermata' porta con sé il
// protocollo GIR della scrittura che l'ha resa reale.
const copertureFondo = computed(() => {
    // Fattura stornata: il debito non esiste più, nessuna copertura da mostrare.
    if (props.fattura.stato_pagamento === 'stornata' || props.fattura.dati_extra?.is_stornata) return [];

    return ((props.fattura.coperture ?? []) as any[]).filter(
        c => c.tipo_copertura === 'fondo_riserva' && Number(c.importo) > 0
    );
});
const coperturePianificate = computed(() => copertureFondo.value.filter(c => c.stato === 'pianificata'));
const copertureConfermate  = computed(() => copertureFondo.value.filter(c => c.stato === 'confermata'));

// La stessa verità del server, non più una sua approssimazione: la prop arriva da
// `FatturaPassivaService::motivoBloccoModifica()`, che è **anche** ciò che
// `destroyDocumento()` interroga prima di cancellare un allegato.
//
// ⚠️ **Prima di questa correzione (03/09/2026) il perimetro qui era più stretto di
// quello vero, e la differenza era visibile all'utente.** La copia locale elencava
// quattro condizioni (pagata/parziale, stornata, pregressa, sforo da ratificare); il
// server ne ha dieci — mancavano esercizio chiuso, coperture di sopravvenienza,
// copertura dal fondo, sforo già ratificato in assemblea, piano rate emesso o
// approvato. In quei sei casi il cestino appariva **attivo**: si cliccava, si
// confermava un'eliminazione «definitiva», e il server la rifiutava con un flash. Ora
// il pulsante è spento esattamente quando l'operazione è vietata, e il motivo mostrato
// è quello che il server userebbe.
const fatturaCongelata = computed(() => !!props.motivoBloccoModifica);

const executeDownload = (documentoId: number) => {
    window.location.href = route(generateRoute('gestionale.fatture.download'), {
        condominio: props.condominio.id,
        fattura: props.fattura.id,
        documento: documentoId
    });
};

// ── Allegati (Coda 102, 1.11.0-beta.12) ──────────────────────────────────────
// Non passa più dal modulo di Modifica: qui non c'è nessuna guardia contabile,
// perché un allegato non è un fatto contabile — consentito anche a esercizio
// chiuso o su una fattura stornata.
const documentoInput = ref<HTMLInputElement | null>(null);
const uploadingDocumento = ref(false);

const apriSelezioneFile = () => documentoInput.value?.click();

const caricaDocumento = (e: Event) => {
    const file = (e.target as HTMLInputElement).files?.[0];
    if (!file) return;

    const formData = new FormData();
    formData.append('file', file);

    uploadingDocumento.value = true;
    router.post(
        route(generateRoute('gestionale.fatture.documenti.store'), {
            condominio: props.condominio.id,
            fattura: props.fattura.id,
        }),
        formData,
        {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => {
                uploadingDocumento.value = false;
                if (documentoInput.value) documentoInput.value.value = '';
            },
        }
    );
};

// ⚠️ Non un `confirm()` nativo + router.delete diretto: vedi
// useConfermaEliminazione.ts per il difetto che il pattern con un ref singolo
// (dato + interruttore insieme) introduce in modo silenzioso.
const eliminazioneDocumento = useConfermaEliminazione<{ id: number }>();

const eliminaDocumento = () => {
    eliminazioneDocumento.conferma((documento) => {
        router.delete(
            route(generateRoute('gestionale.fatture.documenti.destroy'), {
                condominio: props.condominio.id,
                fattura: props.fattura.id,
                documento: documento.id,
            }),
            {
                // ⚠️ Niente `preserveScroll` qui, ed è una scelta contro un difetto misurato.
                // `fatturaCongelata` lato client è un'approssimazione di
                // `motivoBloccoModifica()` lato server, che blocca in più casi — per esempio una
                // fattura aperta e approvata ma già dentro un piano rate emesso. In quei casi il
                // cestino è attivo, la finestra di conferma si apre, e il server risponde con un
                // `back()` e un messaggio d'errore che l'`<Alert>` disegna **in cima alla
                // pagina**: restando ancorati alla card degli allegati, in fondo, il rifiuto non
                // si vedeva e sembrava che il clic non avesse fatto niente. Tornando in cima il
                // messaggio si legge. La cura vera è far viaggiare il motivo col dato, come fa
                // già `motivoBloccoEliminazione` sulle fatture: è una voce di roadmap, non una
                // riga da improvvisare qui.
                onFinish: () => eliminazioneDocumento.conclusa(),
            }
        );
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

            <!-- COPERTURE DA FONDO IN ATTESA DI CONFERMA (beta.19) -->
            <div v-for="cop in coperturePianificate" :key="'cop-p-' + cop.id"
                class="bg-gradient-to-r from-violet-50 to-indigo-50 dark:from-violet-950/30 dark:to-indigo-950/20 border border-violet-200 dark:border-violet-800/50 rounded-2xl p-6 shadow-sm flex flex-col md:flex-row items-start md:items-center gap-5">
                <div class="w-14 h-14 shrink-0 bg-violet-100 dark:bg-violet-900/50 rounded-full flex items-center justify-center border-4 border-violet-50/50 dark:border-violet-900/30">
                    <Repeat2 class="w-7 h-7 text-violet-600 dark:text-violet-400" />
                </div>
                <div class="flex-1 space-y-1">
                    <h3 class="font-black text-violet-900 dark:text-violet-200 text-lg">
                        Copertura dal fondo in attesa di conferma — {{ euro(cop.importo) }}
                    </h3>
                    <p class="text-sm text-violet-800/80 dark:text-violet-300/80 leading-relaxed max-w-3xl">
                        Lo sforamento di questa fattura è coperto dal fondo di riserva, ma il fondo
                        <strong>non è ancora stato toccato</strong>: si decurta solo alla registrazione
                        del giroconto di conferma, che comparirà nel libro giornale.
                    </p>
                </div>
                <Button
                    class="shrink-0 h-10 gap-2 bg-violet-600 hover:bg-violet-700 text-white font-bold shadow-sm"
                    @click="router.visit(route(generateRoute('gestionale.giroconti.create'), { condominio: condominio.id, copertura_id: cop.id }))"
                >
                    <Repeat2 class="w-4 h-4" /> Conferma con giroconto
                </Button>
            </div>

            <!-- COPERTURE DA FONDO CONFERMATE (beta.19) -->
            <div v-for="cop in copertureConfermate" :key="'cop-c-' + cop.id"
                class="bg-gradient-to-r from-emerald-50 to-teal-50 dark:from-emerald-950/30 dark:to-teal-950/20 border border-emerald-200 dark:border-emerald-800/50 rounded-2xl p-6 shadow-sm flex flex-col md:flex-row items-start md:items-center gap-5">
                <div class="w-14 h-14 shrink-0 bg-emerald-100 dark:bg-emerald-900/50 rounded-full flex items-center justify-center border-4 border-emerald-50/50 dark:border-emerald-900/30">
                    <CircleCheckBig class="w-7 h-7 text-emerald-600 dark:text-emerald-400" />
                </div>
                <div class="flex-1 space-y-1">
                    <h3 class="font-black text-emerald-900 dark:text-emerald-200 text-lg">
                        Coperta dal fondo — {{ euro(cop.importo) }}
                        <Badge v-if="cop.scrittura_giroconto" class="ml-1 bg-emerald-200 text-emerald-800 hover:bg-emerald-200 border-none px-2 py-0.5 text-[10px] uppercase tracking-wider font-bold">
                            {{ cop.scrittura_giroconto.numero_protocollo }}
                        </Badge>
                    </h3>
                    <p class="text-sm text-emerald-800/80 dark:text-emerald-300/80 leading-relaxed max-w-3xl">
                        Il giroconto di conferma è registrato: il fondo è stato decurtato e la liquidità
                        è tornata sul conto corrente. Puoi procedere al pagamento con il flusso ordinario.
                    </p>
                </div>
                <div class="shrink-0 flex flex-col sm:flex-row gap-2">
                    <Button
                        v-if="cop.scrittura_giroconto"
                        variant="outline"
                        class="h-10 gap-2 font-bold shadow-sm"
                        @click="router.visit(route(generateRoute('gestionale.scritture.show'), { condominio: condominio.id, scrittura: cop.scrittura_giroconto.id }))"
                    >
                        <FileText class="w-4 h-4" /> Vedi scrittura
                    </Button>
                    <Button
                        class="h-10 gap-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold shadow-sm"
                        @click="router.visit(route(generateRoute('gestionale.pagamenti-fornitori.create'), { condominio: condominio.id }))"
                    >
                        <Banknote class="w-4 h-4" /> Procedi al pagamento
                    </Button>
                </div>
            </div>

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
                                class="h-7 px-3 text-[11px] gap-1.5 shadow-sm font-bold uppercase tracking-wider bg-emerald-600 hover:bg-emerald-700 text-white border-none rounded-md"
                            >
                                <!-- ⚠️ `Banknote` e non `Landmark`: nel prodotto `Landmark` è la banca
                                     come istituzione, mentre l'azione di pagare ha già la sua icona in
                                     F24Show.vue:510 («Registra versamento»). Allineata anche
                                     «Procedi al pagamento» più in alto: è la stessa azione, e due
                                     icone diverse per la stessa cosa nella stessa pagina si notano.
                                     Resta `Landmark` sul badge dello stato di pagamento, che non è
                                     un'azione ma un fatto. -->
                                <Banknote class="w-3 h-3" /> Paga fattura
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
                    <CardHeader class="pb-4 flex flex-row items-center justify-between">
                        <CardTitle class="flex items-center gap-2 text-base text-slate-800 dark:text-slate-100">
                            <Paperclip class="w-4 h-4 text-slate-400" /> Allegati
                        </CardTitle>
                        <Button size="sm" variant="outline" class="h-8 text-xs gap-1.5" :disabled="uploadingDocumento" @click="apriSelezioneFile">
                            <Upload class="w-3.5 h-3.5" /> {{ uploadingDocumento ? 'Caricamento...' : 'Allega' }}
                        </Button>
                        <input ref="documentoInput" type="file" class="hidden" accept=".pdf,.xml,.p7m,.jpg,.jpeg,.png" @change="caricaDocumento" />
                    </CardHeader>
                    <CardContent>
                        <p v-if="erroreAllegato" class="text-xs text-rose-600 bg-rose-50 dark:bg-rose-950/30 border border-rose-200 dark:border-rose-900 rounded-lg px-3 py-2 mb-3">
                            {{ erroreAllegato }}
                        </p>
                        <!-- ⚠️ Il motivo è della FATTURA, non del singolo allegato: una nota
                             sola sopra l'elenco, sempre visibile — non un tooltip che si vede
                             solo passandoci sopra col mouse (e mai su un touch screen), e non
                             ripetuta per ogni riga se i documenti sono più d'uno. Stessa
                             filosofia del menu di riga dell'elenco fatture, che scrive
                             «Elimina — non consentito» come testo, non lo affida a un title. -->
                        <p v-if="fatturaCongelata && props.fattura.documenti?.length" class="flex items-start gap-2 text-xs text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900 rounded-lg px-3 py-2 mb-3">
                            <Lock class="w-3.5 h-3.5 shrink-0 mt-0.5" />
                            <!-- Il motivo esatto, non un «non modificabile» generico che valeva
                                 identico per una fattura già pagata e per una con lo sforo ancora
                                 da ratificare — segnalato da Vincenzo il 03/09/2026. -->
                            <span>{{ props.motivoBloccoModifica }} Gli allegati restano scaricabili ma non si possono eliminare.</span>
                        </p>
                        <!-- 213px = 3 righe da 63px + 2 spazi da 12px (space-y-3): esattamente 3
                             allegati visibili, dal 4° in poi si scorre. -->
                        <div v-if="props.fattura.documenti?.length" class="space-y-3 max-h-[213px] overflow-y-auto custom-scrollbar pr-1">
                            <div v-for="doc in props.fattura.documenti" :key="doc.id" class="flex items-center justify-between p-3 rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-900/50 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors group">
                                <div class="flex items-center gap-3 overflow-hidden">
                                    <div class="w-8 h-8 rounded bg-red-100 dark:bg-red-900/30 flex items-center justify-center shrink-0">
                                        <FileSignature class="w-4 h-4 text-red-600 dark:text-red-400" />
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-sm font-bold text-slate-800 dark:text-slate-200 truncate">{{ doc.name }}</p>
                                        <!-- ⚠️ La data per esteso, non «tre giorni fa»: su un allegato
                                             contabile serve sapere QUANDO è entrato, e una data relativa
                                             non si confronta con quelle della fattura qui accanto. Stesso
                                             formato del resto della pagina (`toLocaleDateString('it-IT')`). -->
                                        <p class="text-[10px] text-slate-500 uppercase tracking-wider mt-0.5">
                                            {{ doc.file_size ? (doc.file_size / 1024).toFixed(1) + ' KB' : 'PDF' }}
                                            <span v-if="doc.created_at" class="text-slate-400">
                                                &middot; {{ new Date(doc.created_at).toLocaleDateString('it-IT') }}
                                            </span>
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-center shrink-0">
                                    <!-- ⚠️ Stesso fumetto dell'applicazione del pulsante accanto, non il
                                         `title` nativo: due icone affiancate che si comportano in due modi
                                         diversi (grafica e ritardo del sistema operativo contro quelli
                                         dell'app) si notano. E «Scarica documento» in forma di frase: la
                                         maiuscola a metà era Title Case all'inglese. -->
                                    <TooltipProvider>
                                        <Tooltip>
                                            <TooltipTrigger as-child>
                                                <Button size="icon" variant="ghost" class="h-8 w-8 text-slate-400 group-hover:text-primary" @click="executeDownload(doc.id)" aria-label="Scarica documento">
                                                    <Download class="w-4 h-4" />
                                                </Button>
                                            </TooltipTrigger>
                                            <TooltipContent class="text-xs">Scarica documento</TooltipContent>
                                        </Tooltip>
                                    </TooltipProvider>
                                    <TooltipProvider>
                                        <Tooltip>
                                            <TooltipTrigger as-child>
                                                <!-- ⚠️ Il trigger dell'hover è lo span, non il Button: un pulsante
                                                     con l'attributo `disabled` non riceve gli eventi del mouse in
                                                     nessun browser, quindi un tooltip agganciato lì non si aprirebbe
                                                     mai proprio nel caso — congelata — in cui serve di più.
                                                     ⚠️ Ma `tabindex` solo quando serve: a cestino attivo lo span
                                                     sarebbe una fermata di TAB in più per ogni allegato, che non
                                                     fa niente — il pulsante dentro è già raggiungibile da solo. -->
                                                <span class="inline-block" :tabindex="fatturaCongelata ? 0 : -1">
                                                    <Button size="icon" variant="ghost" class="h-8 w-8 text-slate-400 hover:text-rose-600 disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:text-slate-400" :disabled="fatturaCongelata" @click="eliminazioneDocumento.chiedi(doc)" :aria-label="fatturaCongelata ? 'Elimina documento — non consentito su una fattura non più modificabile' : 'Elimina documento'">
                                                        <Lock v-if="fatturaCongelata" class="w-4 h-4" />
                                                        <Trash2 v-else class="w-4 h-4" />
                                                    </Button>
                                                </span>
                                            </TooltipTrigger>
                                            <!-- Il motivo esatto arriva da motivoBloccoModifica() — sono
                                                 quattro condizioni diverse (pagata o parziale, stornata,
                                                 pregressa, sforo da ratificare) e «un fatto contabile
                                                 ormai chiuso» ne descriveva solo una, dicendo il falso
                                                 sulle altre tre. Segnalato da Vincenzo il 03/09/2026. -->
                                            <TooltipContent v-if="fatturaCongelata" class="text-xs max-w-72">
                                                {{ props.motivoBloccoModifica }} Per correggere un allegato sbagliato puoi caricarne uno nuovo accanto a questo.
                                            </TooltipContent>
                                            <TooltipContent v-else class="text-xs">
                                                Elimina documento
                                            </TooltipContent>
                                        </Tooltip>
                                    </TooltipProvider>
                                </div>
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

        <ConfirmDialog
            :model-value="eliminazioneDocumento.confermaAperta.value"
            title="Eliminare questo documento?"
            description="Il file viene rimosso dal disco e la riga sparisce dagli allegati: l'operazione non è reversibile."
            variant="destructive"
            :loading="eliminazioneDocumento.inCorso.value"
            @update:model-value="eliminazioneDocumento.suCambioApertura"
            @confirm="eliminaDocumento"
        />
    </GestionaleLayout>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar { width: 4px; }
.custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }
/* ⚠️ La quarta regola: senza questa la barra resta chiara su fondo scuro. Il blocco era stato
   copiato da Dashboard.vue e ActionInbox.vue prendendone tre su quattro. */
.dark .custom-scrollbar::-webkit-scrollbar-thumb { background: #334155; }
</style>
