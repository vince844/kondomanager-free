<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import RicevutaDialog from '@/components/gestionale/movimenti/incassi/RicevutaDialog.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { frasePerErrore, testoConfermaInviaDiNuovo, testoConfermaRiprova, testoEmissione, testoLimiteRiprova, testoRestantiRiprova, testoRiepilogo, TIPI_INDIRIZZO, type Emissione } from '@/lib/gestionale/incassi/ricevute';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import { usePermission } from "@/composables/permissions";
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import type { BreadcrumbItem } from '@/types';
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { ArrowLeft, Landmark, FileText, Banknote, Coins, RotateCcw, Mail, Printer } from "lucide-vue-next";
import { giornoItaliano } from '@/lib/giorno';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';

const props = defineProps<{
    condominio: any;
    condomini: any;
    esercizio: any;
    incasso: any;
    incassoFormatted: any;
    utenteCreatore: string | null;
    utenteStornatore: string | null;
    /** A che cosa va il versamento e chi l'ha dichiarato (1.12.0-beta.3): il testo lo compone il server. */
    imputazione?: { codice: string | null; testo: string; nota: string | null };
    /** Lo storno, dalla rettifica e dall'incasso: le date come `aaaa-mm-gg`, la natura e il suo testo. */
    storno?: { stornato_il: string | null; vale_dal: string | null; protocollo: string | null; natura: string | null; natura_testo: string } | null;
    emissioni?: Array<Emissione & { id: number; tipo_indirizzo: string | null; in_coda_da_tempo: boolean; manda_a: string | null }>;
    da_riprovare?: number;
    /**
     * Decisione 538: chi ha pagato davvero, composto dal server. Per il versamento di un erede sul debito ereditato l'erede, con sotto «erede, sul debito
     * di …»; per gli altri la posizione, con il ruolo e «Versato da» quando c'è.
     */
    ha_pagato?: { nome: string; sotto: string | null; versato_da: string | null };
    /** Decisione 538: il totale diviso fra il debito ereditato e le rate di chi ha pagato, in centesimi; null senza una parte sul debito ereditato. */
    spaccato?: { sul_debito_ereditato: number; sulle_sue_rate: number } | null;
    /** Decisione 539 (4): a chi è intestata la ricevuta del versamento di un erede, se non è lui («Eredi di Massimo Bagnara»); null di serie. */
    ricevuta_intestata_a?: string | null;
}>();

const { euro: euroDaCentesimi } = useCurrencyFormatter();
const annullato = computed(() => props.incasso.stato === 'annullata');
/** Senza la lettura del server (una pagina di prima), la posizione come prima. */
const haPagato = computed(() => props.ha_pagato ?? {
    nome: props.incassoFormatted?.pagante?.lista_completa || 'Sconosciuto',
    sotto: props.incassoFormatted?.pagante?.ruolo ?? null,
    versato_da: props.incassoFormatted?.pagante?.versato_da ?? null,
});

// La ricevuta (1.12.0-beta.2): le sue emissioni, la finestra per stamparla o inviarla, «Invia di nuovo» e «Riprova le non partite».
const isRicevutaOpen = ref(false);
const esitoRicevuta = ref<string | null>(null);
const emissioni = computed(() => props.emissioni ?? []);
// «Riprova» solo se il server trova un invio da rimandare: uno non partito e poi partito allo stesso indirizzo non conta.
const nonPartite = computed(() => (props.da_riprovare ?? 0) > 0);
const ricarica = () => router.reload({ only: ['emissioni', 'da_riprovare'] });

// «Invia di nuovo» e «Riprova» mandano un'email con un clic: prima una conferma breve, con l'indirizzo e lo stato del bollo.
const conferma = ref<{ aperta: boolean; testo: string; azione: () => void }>({ aperta: false, testo: '', azione: () => {} });
const chiedi = (testo: string, azione: () => void) => { conferma.value = { aperta: true, testo, azione }; };
const confermaInviaDiNuovo = (e: Emissione & { id: number }) => chiedi(testoConfermaInviaDiNuovo(e), () => inviaDiNuovo(e.id));
const confermaRiprova = () => chiedi(testoConfermaRiprova(props.da_riprovare ?? 0), riprova);

async function inviaDiNuovo(id: number) {
    esitoRicevuta.value = null;
    try {
        const r = await axios.post(route(generateRoute('gestionale.ricevute.invia-di-nuovo'), { condominio: props.condominio.id, invio: id }));
        esitoRicevuta.value = testoRiepilogo(r.data);
    } catch (e: any) {
        esitoRicevuta.value = frasePerErrore(e, 'Invio non riuscito. Riprova.');
    }
    ricarica();
}

async function riprova() {
    esitoRicevuta.value = null;
    try {
        const r = await axios.post(route(generateRoute('gestionale.ricevute.riprova'), { condominio: props.condominio.id }), { incassi: [props.incasso.id] });
        esitoRicevuta.value = [testoRiepilogo(r.data), testoRestantiRiprova(r.data), testoLimiteRiprova(r.data)].filter(Boolean).join(' ');
    } catch (e: any) {
        esitoRicevuta.value = frasePerErrore(e, 'Invio non riuscito. Riprova.');
    }
    ricarica();
}

const { generateRoute, generatePath } = usePermission();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: props.condominio.nome, href: '#' },
    { title: 'Incassi Rate', href: route(generateRoute('gestionale.movimenti-rate.index'), { condominio: props.condominio.id }) },
    { title: `Dettaglio incasso ${props.incasso.numero_protocollo}`, href: '#' },
]);

// 'annullata' è il valore reale in DB (StornoIncassoRateAction). 'confermato' e
// 'stornato' non sono mai esistiti come valori di stato: questo switch non ha
// mai davvero distinto un incasso attivo da uno stornato.
const statoVariant = computed(() => annullato.value
    ? 'bg-rose-100 text-rose-800 border-rose-300 dark:bg-rose-950/40 dark:text-rose-300 dark:border-rose-800'
    : 'bg-emerald-100 text-emerald-800 border-emerald-300 dark:bg-emerald-950/40 dark:text-emerald-300 dark:border-emerald-800');

// Il totale versato da solo non basta a capire se una rata è stata saldata
// con denaro reale o con credito pregresso (specialmente a cassa zero, dove
// l'importo è correttamente € 0,00 ma non è affatto ovvio perché).
// La via per correggere l'imputazione: solo per una dichiarazione («indicata», «scelta») o per «fuori ordine, non dichiarata», e mai su un
// incasso già annullato. «Secondo l'ordine» e «compensazione» sono un calcolo, non una dichiarazione.
const mostraViaPerCorreggere = computed(() => props.incasso.stato !== 'annullata'
    && ['indicata_dal_versante', 'scelta_amministratore', 'fuori_ordine'].includes(props.imputazione?.codice ?? ''));

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
    <Head :title="`Dettaglio incasso ${props.incasso.numero_protocollo}`" />

    <GestionaleLayout>

        <div class="px-4 py-6 space-y-6 sm:px-6 sm:py-8">
            <PageHeaderGuide
                pageTitle="Dettaglio incasso"
                :pageSubtitle="utenteCreatore ? `Protocollo ${props.incasso.numero_protocollo} — Registrato da ${utenteCreatore}` : `Protocollo ${props.incasso.numero_protocollo}`"
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

            <!-- Lo storno, se c'è: chi, quando, da quando vale e perché. Le date dal server come aaaa-mm-gg (il giorno dello storno nel calendario
                 dell'amministratore, quello da cui vale), la natura dall'incasso, chi ha stornato dalla rettifica (1.12.0-beta.3). -->
            <div v-if="annullato" data-testid="riquadro-storno" class="rounded-xl border border-rose-200 bg-rose-50 p-4 shadow-sm dark:border-rose-800/50 dark:bg-rose-950/30 sm:p-5">
                <div class="flex items-start gap-3">
                    <RotateCcw class="mt-0.5 h-5 w-5 shrink-0 text-rose-600 dark:text-rose-400" />
                    <div class="min-w-0 flex-1">
                        <h3 class="text-base font-semibold text-rose-900 dark:text-rose-200">Incasso stornato</h3>
                        <p class="mt-1 text-sm leading-relaxed text-rose-800 dark:text-rose-300">
                            Le rate che copriva sono tornate da pagare, e lo storno ha tolto l'importo dal conto di accredito.
                        </p>
                    </div>
                </div>
                <div class="mt-3 grid grid-cols-1 divide-y divide-rose-100 rounded-lg border border-rose-100 bg-white/70 text-sm dark:divide-rose-900/40 dark:border-rose-900/40 dark:bg-slate-900/40">
                    <div class="flex items-start justify-between gap-3 px-3 py-2">
                        <span class="text-rose-700 dark:text-rose-300">Stornato il</span>
                        <span class="font-medium text-rose-900 dark:text-rose-100">{{ giornoItaliano(storno?.stornato_il) || '—' }}</span>
                    </div>
                    <div class="flex items-start justify-between gap-3 px-3 py-2">
                        <span class="text-rose-700 dark:text-rose-300">Vale dal</span>
                        <span class="font-medium text-rose-900 dark:text-rose-100">{{ giornoItaliano(storno?.vale_dal) || '—' }}</span>
                    </div>
                    <div class="flex items-start justify-between gap-3 px-3 py-2">
                        <span class="shrink-0 text-rose-700 dark:text-rose-300">Perché</span>
                        <span class="text-right font-medium text-rose-900 dark:text-rose-100">{{ storno?.natura_testo ?? 'Natura non dichiarata' }}</span>
                    </div>
                    <div class="flex items-start justify-between gap-3 px-3 py-2">
                        <span class="text-rose-700 dark:text-rose-300">Stornato da</span>
                        <span class="font-medium text-rose-900 dark:text-rose-100">{{ utenteStornatore || 'non indicato' }}</span>
                    </div>
                </div>
            </div>

            <!-- LA RICEVUTA: le sue emissioni, dove l'amministratore già guarda -->
            <Card class="shadow-sm border-slate-200 dark:border-slate-800">
                <CardHeader class="pb-3 flex flex-row flex-wrap items-center justify-between gap-2">
                    <CardTitle class="flex items-center gap-2 text-base text-slate-800 dark:text-slate-100">
                        <FileText class="w-5 h-5 text-slate-400" /> Ricevuta
                    </CardTitle>
                    <div class="flex flex-wrap gap-2">
                        <Button v-if="nonPartite && props.incasso.stato !== 'annullata'" variant="outline" size="sm" @click="confermaRiprova">
                            <Mail class="w-4 h-4 mr-2" /> Riprova le non partite
                        </Button>
                        <Button variant="outline" size="sm" @click="isRicevutaOpen = true">
                            <Printer class="w-4 h-4 mr-2" /> Stampa o invia
                        </Button>
                    </div>
                </CardHeader>
                <CardContent class="space-y-2 text-sm">
                    <p v-if="emissioni.length === 0" class="text-slate-500 dark:text-slate-400">Mai emessa.</p>
                    <div v-for="e in emissioni" :key="e.id" class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 dark:border-slate-800 pb-2 last:border-0">
                        <span :class="e.stato === 'non_partita' ? 'text-rose-700 dark:text-rose-300' : 'text-slate-700 dark:text-slate-300'">
                            {{ testoEmissione(e) }}<template v-if="e.tipo_indirizzo"> ({{ TIPI_INDIRIZZO[e.tipo_indirizzo] ?? e.tipo_indirizzo }})</template>
                            <span v-if="e.errore_completo" class="block break-words text-xs text-slate-500 dark:text-slate-400">Dal server: {{ e.errore_completo }}</span>
                            <span v-if="e.in_coda_da_tempo" class="block text-xs text-amber-700 dark:text-amber-300">
                                In coda da più di un'ora: di solito vuol dire che sul server non gira il servizio che manda le email in coda. Chi gestisce
                                l'installazione controlla che sia attivo (SCHEDULE_QUEUE_WORKER nel file .env, o un processo queue:work).
                            </span>
                        </span>
                        <!-- Di un incasso annullato si rimanda solo una ricevuta che era partita; senza un indirizzo a cui mandarla (la persona non ce l'ha più) il pulsante non c'è. -->
                        <Button v-if="e.canale === 'email' && e.manda_a && (props.incasso.stato !== 'annullata' || e.stato === 'inviata')" variant="ghost" size="sm" class="h-7 text-xs" @click="confermaInviaDiNuovo(e)">
                            Invia di nuovo
                        </Button>
                    </div>
                    <p v-if="esitoRicevuta" class="rounded-md border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/60 p-2 text-xs text-slate-700 dark:text-slate-200">{{ esitoRicevuta }}</p>
                </CardContent>
            </Card>
            <RicevutaDialog v-model:open="isRicevutaOpen" :condominio-id="props.condominio.id" :incasso-id="props.incasso.id" @emessa="ricarica" />
            <ConfirmDialog v-model="conferma.aperta" title="Mandare di nuovo?" :description="conferma.testo" confirm-text="Manda" @confirm="conferma.azione()" />

            <!-- Decisione 538: in alto chi ha pagato davvero e, a destra, il totale con lo spaccato e lo stato; sotto gli estremi del versamento, una riga
                 per voce con le righe divisorie intere. -->
            <Card class="shadow-sm border-slate-200 dark:border-slate-800">
                <CardContent class="p-0">
                    <div class="flex flex-col gap-4 p-4 sm:flex-row sm:items-start sm:justify-between sm:p-6">
                        <div data-testid="ha-pagato" class="min-w-0 space-y-1">
                            <p class="text-sm text-slate-500 dark:text-slate-400">Ha pagato</p>
                            <p class="break-words text-lg font-semibold text-slate-900 dark:text-slate-100">{{ haPagato.nome }}</p>
                            <p v-if="haPagato.sotto" class="text-sm text-slate-600 dark:text-slate-300">{{ haPagato.sotto }}</p>
                            <!-- Coda 167: il versamento l'ha fatto un'altra persona, per conto di questa posizione. -->
                            <p v-if="haPagato.versato_da" class="text-sm text-sky-700 dark:text-sky-300">Versato da {{ haPagato.versato_da }}</p>
                        </div>
                        <div data-testid="totale-versamento" class="w-full shrink-0 rounded-lg border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-900/50 sm:w-72">
                            <div class="flex items-center justify-between gap-2">
                                <p class="text-sm text-slate-500 dark:text-slate-400">Totale del versamento</p>
                                <span data-testid="stato-incasso" class="inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-medium" :class="statoVariant">
                                    {{ annullato ? 'Annullato' : 'Registrato' }}
                                </span>
                            </div>
                            <p class="mt-1 text-2xl font-bold" :class="annullato ? 'text-slate-400 line-through dark:text-slate-500' : 'text-emerald-700 dark:text-emerald-400'">
                                {{ props.incassoFormatted.importo_totale_formatted }}
                            </p>
                            <!-- Solo con una parte sul debito ereditato (decisione 538): altrimenti il totale soltanto. -->
                            <dl v-if="props.spaccato" data-testid="spaccato" class="mt-3 space-y-1 border-t border-slate-200 pt-3 text-sm dark:border-slate-700">
                                <div class="flex justify-between gap-3">
                                    <dt class="text-slate-600 dark:text-slate-300">Sul debito ereditato</dt>
                                    <dd class="font-medium text-slate-900 dark:text-slate-100">{{ euroDaCentesimi(props.spaccato.sul_debito_ereditato) }}</dd>
                                </div>
                                <div class="flex justify-between gap-3">
                                    <dt class="text-slate-600 dark:text-slate-300">Sulle sue rate</dt>
                                    <dd class="font-medium text-slate-900 dark:text-slate-100">{{ euroDaCentesimi(props.spaccato.sulle_sue_rate) }}</dd>
                                </div>
                            </dl>
                            <p v-if="modalitaVersamento === 'credito'" class="mt-2 flex items-center gap-1 text-xs text-blue-700 dark:text-blue-300">
                                <Coins class="h-3.5 w-3.5" /> Saldato con il credito pregresso
                            </p>
                            <p v-else-if="modalitaVersamento === 'misto'" class="mt-2 flex items-center gap-1 text-xs text-indigo-700 dark:text-indigo-300">
                                <Coins class="h-3.5 w-3.5" /> Denaro e credito pregresso
                            </p>
                        </div>
                    </div>

                    <dl class="divide-y divide-slate-100 border-t border-slate-100 text-sm dark:divide-slate-800 dark:border-slate-800">
                        <div class="grid gap-1 px-4 py-3 sm:grid-cols-[12rem_1fr] sm:gap-4 sm:px-6">
                            <dt class="text-slate-500 dark:text-slate-400">Data del versamento</dt>
                            <dd class="text-slate-900 dark:text-slate-100">{{ giornoItaliano(props.incassoFormatted.data_competenza ?? props.incasso.data_competenza) || '—' }}</dd>
                        </div>
                        <div class="grid gap-1 px-4 py-3 sm:grid-cols-[12rem_1fr] sm:gap-4 sm:px-6">
                            <dt class="text-slate-500 dark:text-slate-400">Conto di accredito</dt>
                            <dd v-if="props.incassoFormatted.cassa_nome === 'N/D' && modalitaVersamento === 'credito'" class="flex items-center gap-1.5 text-blue-700 dark:text-blue-300">
                                <Coins class="h-4 w-4 shrink-0" /> Nessuno: saldato tutto con il credito
                            </dd>
                            <dd v-else class="flex items-center gap-1.5 text-slate-900 dark:text-slate-100">
                                <Landmark class="h-4 w-4 shrink-0 text-slate-400" /> {{ props.incassoFormatted.cassa_nome }}
                            </dd>
                        </div>
                        <!-- A che cosa va il versamento (decisioni 217 e 218). Un incasso non si modifica: una dichiarazione sbagliata si corregge
                             stornando e registrando di nuovo. -->
                        <div class="grid gap-1 px-4 py-3 sm:grid-cols-[12rem_1fr] sm:gap-4 sm:px-6">
                            <dt class="text-slate-500 dark:text-slate-400">Imputazione</dt>
                            <dd class="space-y-1">
                                <p class="text-slate-900 dark:text-slate-100" data-testid="imputazione">
                                    {{ props.imputazione?.testo ?? 'Non dichiarata' }}<template v-if="props.imputazione?.nota">: {{ props.imputazione.nota }}</template>
                                </p>
                                <!-- Solo dove c'è una dichiarazione da correggere, e non su un incasso già annullato: la pagina non ha lo storno, che sta
                                     nel menu della riga dell'elenco degli incassi. -->
                                <p v-if="mostraViaPerCorreggere" class="text-xs text-slate-500 dark:text-slate-400">Se la dichiarazione non è giusta: nell'elenco degli incassi, dal menu della riga, «Storna» con «Registrazione sbagliata», poi registralo di nuovo con la data del versamento.</p>
                            </dd>
                        </div>
                        <div class="grid gap-1 px-4 py-3 sm:grid-cols-[12rem_1fr] sm:gap-4 sm:px-6">
                            <dt class="text-slate-500 dark:text-slate-400">Causale</dt>
                            <dd class="break-words text-slate-900 dark:text-slate-100">{{ props.incasso.causale || 'Nessuna causale' }}</dd>
                        </div>
                        <!-- Decisione 539 (4): la ricevuta del versamento di un erede, quando non è intestata a lui (la scelta di serie). -->
                        <div v-if="props.ricevuta_intestata_a" data-testid="ricevuta-intestata-a" class="grid gap-1 px-4 py-3 sm:grid-cols-[12rem_1fr] sm:gap-4 sm:px-6">
                            <dt class="text-slate-500 dark:text-slate-400">Ricevuta intestata a</dt>
                            <dd class="break-words text-slate-900 dark:text-slate-100">{{ props.ricevuta_intestata_a }}</dd>
                        </div>
                    </dl>
                </CardContent>
            </Card>

            <!-- Le rate coperte dal versamento -->
            <Card class="shadow-sm border-slate-200 dark:border-slate-800 overflow-hidden">
                <CardHeader class="border-b border-slate-100 bg-slate-50/50 py-4 dark:border-slate-800 dark:bg-slate-900/20">
                    <CardTitle class="flex items-center gap-2 text-base text-slate-800 dark:text-slate-100">
                        <Coins class="h-4 w-4 text-slate-400" /> Rate coperte
                    </CardTitle>
                </CardHeader>
                <div class="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow class="hover:bg-transparent">
                                <TableHead>Rata</TableHead>
                                <TableHead>Unità</TableHead>
                                <TableHead>Scadenza</TableHead>
                                <TableHead>Come è coperta</TableHead>
                                <TableHead class="text-right font-semibold text-slate-800 dark:text-slate-200">Importo coperto</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="(rata, index) in props.incassoFormatted.dettagli_rate" :key="index">
                                <TableCell>
                                    <div class="text-sm font-medium text-slate-800 dark:text-slate-200">Rata {{ rata.numero }}</div>
                                </TableCell>
                                <TableCell>
                                    <div class="text-sm text-slate-600 dark:text-slate-300">Int. {{ rata.immobile || 'N/D' }}</div>
                                </TableCell>
                                <TableCell class="text-slate-600 dark:text-slate-300">{{ rata.scadenza }}</TableCell>
                                <TableCell>
                                    <span v-if="rata.tipo === 'contanti'" class="inline-flex items-center gap-1.5 rounded-md border border-emerald-200 bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">
                                        <Banknote class="h-3.5 w-3.5" /> Denaro versato
                                    </span>
                                    <span v-else class="inline-flex items-center gap-1.5 rounded-md border border-blue-200 bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800 dark:border-blue-800 dark:bg-blue-950/40 dark:text-blue-300">
                                        <Coins class="h-3.5 w-3.5" /> Credito pregresso
                                    </span>
                                    <!-- Coda 167 (R6): la rata di chi ha versato che riceve la parte in più. Dopo la coppia v-if/v-else, non
                                         in mezzo: in mezzo il v-else si agganciava a questo div e ogni rata in contanti mostrava anche
                                         «Credito pregresso» (reperto S3 del rigiro della Fase 1-bis). «Sulla rata di»: la parte in più
                                         riduce una rata ancora da pagare, o diventa credito se è già pagata (S16). -->
                                    <div v-if="rata.credito_di" class="mt-1 text-xs text-sky-700 dark:text-sky-300">Parte in più, sulla rata di {{ rata.credito_di }}</div>
                                </TableCell>
                                <TableCell class="text-right font-semibold text-slate-800 dark:text-slate-200">{{ rata.importo_formatted }}</TableCell>
                            </TableRow>
                            <TableRow v-if="!props.incassoFormatted.dettagli_rate?.length">
                                <TableCell colspan="5" class="py-8 text-center text-slate-500 dark:text-slate-400">Nessuna rata coperta da questo incasso.</TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>
            </Card>

                </section>
            </div>
        </div>
    </GestionaleLayout>
</template>
