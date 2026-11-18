<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import FormCommento from '@/components/commenti/FormCommento.vue';
import { useConfermaEliminazione } from '@/composables/useConfermaEliminazione';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    HoverCard,
    HoverCardContent,
    HoverCardTrigger,
} from '@/components/ui/hover-card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Calendar } from '@/components/ui/calendar';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import Alert from '@/components/Alert.vue';
import {
    Calendar as CalendarIcon,
    Check,
    ChevronLeft,
    ChevronRight,
    ChevronsLeft,
    ChevronsRight,
    Eye,
    EyeOff,
    ExternalLink,
    Info,
    MessageSquare,
    MessageSquareReply,
    MoreHorizontal,
    Pencil,
    SearchX,
    ShieldCheck,
    Trash2,
    X,
} from 'lucide-vue-next';
import { parseDate, type DateValue } from '@internationalized/date';
import { trans, transChoice } from 'laravel-vue-i18n';
import type { CommentiFiltri, CommentoRigaPagina } from '@/types/commentiPagina';

interface PaginatedCommenti {
    data: CommentoRigaPagina[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
}

interface CondominioOption {
    id: number;
    nome: string;
}

interface Props {
    commenti: PaginatedCommenti;
    filtri: CommentiFiltri;
    condomini: CondominioOption[];
}

const props = defineProps<Props>();
const page = usePage();
const flashMessage = computed(() => (page.props.flash as { message?: { message: string; type: string } })?.message);

// Standard 16 (la potenza di Inertia): i filtri, la paginazione e le azioni aggiornano l'elenco con una visita parziale,
// senza ricaricare il resto della pagina.
const PROPS_ELENCO = ['commenti', 'filtri'];
const PROPS_DOPO_AZIONE = ['commenti', 'flash'];

// Array vuoto per forzare l'header in modalità compatta, allineato agli altri elenchi
const breadcrumbs: never[] = [];

const pageGuides = computed(() => [
    {
        title: trans('commenti_pagina.guides.overview_title'),
        description: trans('commenti_pagina.guides.overview_desc'),
        icon: MessageSquare,
        colorVariant: 'blue' as const,
    },
    {
        title: trans('commenti_pagina.guides.moderation_title'),
        description: trans('commenti_pagina.guides.moderation_desc'),
        icon: ShieldCheck,
        colorVariant: 'amber' as const,
    },
    {
        title: trans('commenti_pagina.guides.replies_title'),
        description: trans('commenti_pagina.guides.replies_desc'),
        icon: MessageSquareReply,
        colorVariant: 'emerald' as const,
    },
]);

const scrollToTop = () => window.scrollTo({ top: 0, behavior: 'smooth' });

onMounted(() => {
    if (flashMessage.value) scrollToTop();
});

watch(flashMessage, (newValue) => {
    if (newValue) scrollToTop();
});

const statoFiltro = reactive({
    stato: props.filtri?.stato || 'tutti',
    tipo: props.filtri?.tipo || 'tutti',
    condominio_id: props.filtri?.condominio_id ? String(props.filtri.condominio_id) : 'tutti',
    autore: props.filtri?.autore || '',
    // Un elemento solo (la segnalazione o la comunicazione), da «Modera» dell'Action inbox: si vede come un'etichetta e si toglie da sé.
    elemento: (props.filtri?.elemento ? Number(props.filtri.elemento) : null) as number | null,
    dal: props.filtri?.dal || '',
    al: props.filtri?.al || '',
});

const selezionati = ref<number[]>([]);
const mostraConfermaBlocco = ref<'elimina' | 'nascondi' | null>(null);
const azioneInCorso = ref(false);
const apertoDal = ref(false);
const apertoAl = ref(false);

// Le conferme della riga: il dato sopravvive alla chiusura della finestra (vedi `useConfermaEliminazione`).
const eliminazione = useConfermaEliminazione<CommentoRigaPagina>();
const occultamento = useConfermaEliminazione<CommentoRigaPagina>();

// Le finestre «Rispondi» e «Modifica».
const rigaRisposta = ref<CommentoRigaPagina | null>(null);
const apertaRisposta = ref(false);
const rigaModifica = ref<CommentoRigaPagina | null>(null);
const apertaModifica = ref(false);
const formModifica = useForm({ corpo: '' });
const MAX_CARATTERI = 5000;

function parseIsoDate(iso: string): DateValue | undefined {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(iso)) return undefined;
    try {
        return parseDate(iso);
    } catch {
        return undefined;
    }
}

function formattaDataBreve(iso: string): string {
    const parti = iso.split('-');
    if (parti.length !== 3) return iso;
    return `${parti[2]}/${parti[1]}/${parti[0]}`;
}

function selezionaDal(valore: DateValue | undefined) {
    statoFiltro.dal = valore ? valore.toString() : '';
    apertoDal.value = false;
    applicaFiltri();
}

function selezionaAl(valore: DateValue | undefined) {
    statoFiltro.al = valore ? valore.toString() : '';
    apertoAl.value = false;
    applicaFiltri();
}

function parametriFiltro(): Record<string, string | number> {
    const params: Record<string, string | number> = {};
    if (statoFiltro.stato && statoFiltro.stato !== 'tutti') {
        params.stato = statoFiltro.stato;
    }
    if (statoFiltro.tipo && statoFiltro.tipo !== 'tutti') {
        params.tipo = statoFiltro.tipo;
    }
    if (statoFiltro.condominio_id && statoFiltro.condominio_id !== 'tutti') {
        params.condominio_id = Number(statoFiltro.condominio_id);
    }
    if (statoFiltro.autore.trim() !== '') {
        params.autore = statoFiltro.autore.trim();
    }
    if (statoFiltro.elemento !== null) {
        params.elemento = statoFiltro.elemento;
    }
    if (statoFiltro.dal !== '') {
        params.dal = statoFiltro.dal;
    }
    if (statoFiltro.al !== '') {
        params.al = statoFiltro.al;
    }
    return params;
}

// I menu dei filtri e delle righe per pagina sono `v-select` (decisione 392): le voci con il valore e la scritta.
type Voce = { valore: string; etichetta: string };
const valoreDellaVoce = (v: Voce) => v.valore;
const vociStato = computed<Voce[]>(() => [
    { valore: 'tutti', etichetta: trans('commenti_pagina.filters.status_all') },
    { valore: 'in_attesa', etichetta: trans('commenti_pagina.filters.status_pending') },
    { valore: 'pubblicato', etichetta: trans('commenti_pagina.filters.status_published') },
    { valore: 'nascosto', etichetta: trans('commenti_pagina.filters.status_hidden') },
]);
const vociTipo = computed<Voce[]>(() => [
    { valore: 'tutti', etichetta: trans('commenti_pagina.filters.type_all') },
    { valore: 'segnalazioni', etichetta: trans('commenti_pagina.filters.type_segnalazioni') },
    { valore: 'comunicazioni', etichetta: trans('commenti_pagina.filters.type_comunicazioni') },
]);
const vociCondominio = computed<Voce[]>(() => [
    { valore: 'tutti', etichetta: trans('commenti_pagina.filters.condominio_all') },
    ...props.condomini.map((c) => ({ valore: String(c.id), etichetta: c.nome })),
]);

function scegliFiltro(campo: 'stato' | 'tipo' | 'condominio_id', valore: string | null) {
    statoFiltro[campo] = valore ?? 'tutti';
    applicaFiltri();
}

// L'elemento scelto (da «Modera» dell'Action inbox) è una coppia con il suo tipo: il server lo vuole con il tipo, e con un altro
// tipo sarebbe un altro elemento (una segnalazione e una comunicazione possono avere lo stesso numero). Cambiando «Tipo»
// l'etichetta si toglie; subito, prima che la visita parta.
watch(
    () => statoFiltro.tipo,
    (nuovo, vecchio) => {
        if (nuovo !== vecchio) statoFiltro.elemento = null;
    },
    { flush: 'sync' }
);

const haFiltriAttivi = computed(
    () =>
        statoFiltro.stato !== 'tutti' ||
        statoFiltro.tipo !== 'tutti' ||
        statoFiltro.condominio_id !== 'tutti' ||
        statoFiltro.autore.trim() !== '' ||
        statoFiltro.elemento !== null ||
        statoFiltro.dal !== '' ||
        statoFiltro.al !== ''
);

watchDebounced(
    () => statoFiltro.autore,
    () => {
        applicaFiltri();
    },
    { debounce: 300 }
);

const opzioniPerPage = computed<number[]>(() => {
    const consentite = page.props.paginazione?.consentite ?? [10, 15, 25, 50, 100];
    const inUso = props.commenti.per_page;
    return consentite.includes(inUso) ? consentite : [...consentite, inUso].sort((a, b) => a - b);
});
const vociPerPage = computed<Voce[]>(() => opzioniPerPage.value.map((n) => ({ valore: String(n), etichetta: String(n) })));

function vaiAllElenco(extra: Record<string, number>) {
    selezionati.value = [];
    router.get(
        route('admin.commenti.index'),
        {
            ...parametriFiltro(),
            ...extra,
        },
        {
            preserveState: true,
            preserveScroll: true,
            only: PROPS_ELENCO,
        }
    );
}

function cambiaPerPage(nuovo: number | string) {
    vaiAllElenco({ per_page: Number(nuovo), page: 1 });
}

function vaiAPagina(pagina: number) {
    if (pagina < 1 || pagina > props.commenti.last_page || pagina === props.commenti.current_page) {
        return;
    }
    vaiAllElenco({ per_page: props.commenti.per_page, page: pagina });
}

function applicaFiltri() {
    vaiAllElenco({ per_page: props.commenti.per_page, page: 1 });
}

function azzeraFiltri() {
    statoFiltro.stato = 'tutti';
    statoFiltro.tipo = 'tutti';
    statoFiltro.condominio_id = 'tutti';
    statoFiltro.autore = '';
    statoFiltro.elemento = null;
    statoFiltro.dal = '';
    statoFiltro.al = '';
    applicaFiltri();
}

const righeSelezionate = computed(() =>
    props.commenti.data.filter((r) => selezionati.value.includes(r.id))
);

const tuttiSelezionati = computed(
    () =>
        props.commenti.data.length > 0 &&
        props.commenti.data.every((r) => selezionati.value.includes(r.id))
);

// Un pulsante in blocco si accende se la regola concede l'azione ad almeno uno dei commenti scelti: il server applica la
// regola a ognuno e dice quanti ne ha fatti e quanti no.
const puoApprovareSelezionati = computed(() => righeSelezionate.value.some((r) => r.puo_approvare));
const puoNascondereSelezionati = computed(() => righeSelezionate.value.some((r) => r.puo_nascondere));
const puoMostrareSelezionati = computed(() => righeSelezionate.value.some((r) => r.puo_mostrare));
const puoEliminareSelezionati = computed(() => righeSelezionate.value.some((r) => r.puo_eliminare));

function alternaTutti(valore: boolean | 'indeterminate') {
    if (valore === true) {
        selezionati.value = props.commenti.data.map((r) => r.id);
    } else {
        selezionati.value = [];
    }
}

function alternaRiga(id: number, valore: boolean | 'indeterminate') {
    if (valore === true) {
        if (!selezionati.value.includes(id)) {
            selezionati.value = [...selezionati.value, id];
        }
    } else {
        selezionati.value = selezionati.value.filter((x) => x !== id);
    }
}

// «Scritto il»: il server manda il giorno e l'ora di Roma (`aaaa-mm-gg hh:mm`), qui si scrivono all'italiana con i soli numeri,
// senza passare da un istante: il browser di chi guarda da un altro fuso non li sposta.
function formattaData(valore: string | null): string {
    if (!valore) return '';
    const parti = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec(valore);
    if (!parti) return valore;
    const [, anno, mese, giorno, ore, minuti] = parti;
    return ore ? `${giorno}/${mese}/${anno} ${ore}:${minuti}` : `${giorno}/${mese}/${anno}`;
}

// -- Le azioni sulla riga: le rotte sono quelle che usa la scheda, così le notifiche sono le stesse ---------------------

function approvaSingolo(riga: CommentoRigaPagina) {
    azioneInCorso.value = true;
    router.post(
        route('admin.commenti.approva', riga.id),
        {},
        {
            preserveScroll: true,
            only: PROPS_DOPO_AZIONE,
            onFinish: () => {
                azioneInCorso.value = false;
            },
        }
    );
}

function confermaNascondiSingolo(riga: CommentoRigaPagina) {
    router.post(
        route('admin.commenti.modera', riga.id),
        {},
        {
            preserveScroll: true,
            only: PROPS_DOPO_AZIONE,
            onFinish: () => occultamento.conclusa(),
        }
    );
}

function mostraDiNuovoSingolo(riga: CommentoRigaPagina) {
    azioneInCorso.value = true;
    router.post(
        route('admin.commenti.mostra', riga.id),
        {},
        {
            preserveScroll: true,
            only: PROPS_DOPO_AZIONE,
            onFinish: () => {
                azioneInCorso.value = false;
            },
        }
    );
}

function confermaEliminaSingolo(riga: CommentoRigaPagina) {
    router.delete(route('admin.commenti.destroy', riga.id), {
        preserveScroll: true,
        only: PROPS_DOPO_AZIONE,
        onSuccess: () => {
            selezionati.value = selezionati.value.filter((x) => x !== riga.id);
        },
        onFinish: () => eliminazione.conclusa(),
    });
}

function apriRisposta(riga: CommentoRigaPagina) {
    rigaRisposta.value = riga;
    apertaRisposta.value = true;
}

function chiudiRisposta() {
    apertaRisposta.value = false;
}

function apriModifica(riga: CommentoRigaPagina) {
    rigaModifica.value = riga;
    formModifica.reset();
    formModifica.clearErrors();
    formModifica.corpo = riga.testo;
    apertaModifica.value = true;
}

function salvaModifica() {
    const riga = rigaModifica.value;
    if (!riga || !formModifica.corpo.trim() || formModifica.corpo.length > MAX_CARATTERI) return;
    formModifica.patch(route('admin.commenti.update', riga.id), {
        preserveScroll: true,
        only: PROPS_DOPO_AZIONE,
        onSuccess: () => {
            // La finestra è una sola per tutte le righe: la risposta chiude la finestra della sua riga, non quella riaperta su un'altra
            // mentre la richiesta viaggiava.
            if (rigaModifica.value?.id === riga.id) {
                apertaModifica.value = false;
            }
        },
    });
}

// L'errore del campo vale per il testo che l'ha avuto: scrivendo, si toglie.
watch(
    () => formModifica.corpo,
    () => {
        if (formModifica.errors.corpo) formModifica.clearErrors('corpo');
    }
);

// -- Le azioni in blocco: una rotta per azione, che applica la regola commento per commento -----------------------------

function azioneInBlocco(nomeRotta: string) {
    azioneInCorso.value = true;
    router.post(
        route(nomeRotta),
        { ids: selezionati.value },
        {
            preserveScroll: true,
            only: PROPS_DOPO_AZIONE,
            onSuccess: () => {
                selezionati.value = [];
            },
            onFinish: () => {
                azioneInCorso.value = false;
                mostraConfermaBlocco.value = null;
            },
        }
    );
}

function approvaInBlocco() {
    if (!puoApprovareSelezionati.value) return;
    azioneInBlocco('admin.commenti.approva-in-blocco');
}

function confermaNascondiInBlocco() {
    if (!puoNascondereSelezionati.value) return;
    azioneInBlocco('admin.commenti.nascondi-in-blocco');
}

function mostraDiNuovoInBlocco() {
    if (!puoMostrareSelezionati.value) return;
    azioneInBlocco('admin.commenti.mostra-in-blocco');
}

function confermaEliminaInBlocco() {
    if (!puoEliminareSelezionati.value) return;
    azioneInBlocco('admin.commenti.elimina-in-blocco');
}
</script>

<template>
    <Head :title="trans('commenti_pagina.header.list_head')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="px-6 py-8 space-y-6">
            <PageHeaderGuide
                :page-title="trans('commenti_pagina.header.list_title')"
                :page-subtitle="trans('commenti_pagina.header.list_description')"
                :guides="pageGuides"
                :breadcrumbs="breadcrumbs"
                :video-url="null"
            />

            <div class="w-full">
                <section class="w-full">
                    <div v-if="flashMessage" class="py-3">
                        <Alert :message="flashMessage.message" :type="flashMessage.type" />
                    </div>

                    <div class="border border-slate-200 dark:border-slate-800 rounded-2xl bg-white dark:bg-slate-950 overflow-hidden shadow-sm p-4 mt-2 space-y-4">
                        <!-- Barra filtri e azione ZIP -->
                        <div class="flex flex-col gap-3 w-full lg:flex-row lg:items-end lg:justify-between">
                            <form
                                class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-2.5 items-end flex-1"
                                @submit.prevent="applicaFiltri"
                            >
                                <div class="space-y-1 menu-commenti" data-testid="filtro-stato">
                                    <label class="text-xs font-medium text-muted-foreground">{{ trans('commenti_pagina.filters.status') }}</label>
                                    <v-select
                                        :model-value="statoFiltro.stato"
                                        :options="vociStato"
                                        label="etichetta"
                                        :reduce="valoreDellaVoce"
                                        :searchable="false"
                                        :clearable="false"
                                        :aria-label="trans('commenti_pagina.filters.status')"
                                        @update:model-value="(v: string | null) => scegliFiltro('stato', v)"
                                    />
                                </div>

                                <div class="space-y-1 menu-commenti" data-testid="filtro-tipo">
                                    <label class="text-xs font-medium text-muted-foreground">{{ trans('commenti_pagina.filters.type') }}</label>
                                    <v-select
                                        :model-value="statoFiltro.tipo"
                                        :options="vociTipo"
                                        label="etichetta"
                                        :reduce="valoreDellaVoce"
                                        :searchable="false"
                                        :clearable="false"
                                        :aria-label="trans('commenti_pagina.filters.type')"
                                        @update:model-value="(v: string | null) => scegliFiltro('tipo', v)"
                                    />
                                </div>

                                <div class="space-y-1 menu-commenti" data-testid="filtro-condominio">
                                    <label class="text-xs font-medium text-muted-foreground">{{ trans('commenti_pagina.filters.condominio') }}</label>
                                    <v-select
                                        :model-value="statoFiltro.condominio_id"
                                        :options="vociCondominio"
                                        label="etichetta"
                                        :reduce="valoreDellaVoce"
                                        :clearable="false"
                                        :aria-label="trans('commenti_pagina.filters.condominio')"
                                        @update:model-value="(v: string | null) => scegliFiltro('condominio_id', v)"
                                    >
                                        <template #no-options>{{ trans('commenti_pagina.filters.condominio_none') }}</template>
                                    </v-select>
                                </div>

                                <div class="space-y-1">
                                    <div class="flex items-center gap-1.5">
                                        <label class="text-xs font-medium text-muted-foreground">{{ trans('commenti_pagina.filters.author') }}</label>
                                        <HoverCard>
                                            <HoverCardTrigger as-child>
                                                <button type="button" class="text-slate-400 hover:text-primary outline-none cursor-pointer">
                                                    <Info class="w-3.5 h-3.5" />
                                                </button>
                                            </HoverCardTrigger>
                                            <HoverCardContent class="w-80 p-4 bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-800 shadow-xl">
                                                <h4 class="text-sm font-bold mb-1.5">{{ trans('commenti_pagina.filters.author') }}</h4>
                                                <p class="text-xs text-slate-500 dark:text-slate-400 leading-relaxed">{{ trans('commenti_pagina.filters.author_tooltip') }}</p>
                                            </HoverCardContent>
                                        </HoverCard>
                                    </div>
                                    <Input
                                        v-model="statoFiltro.autore"
                                        type="text"
                                        :placeholder="trans('commenti_pagina.filters.author_placeholder')"
                                        class="h-8 text-xs"
                                    />
                                </div>

                                <div class="space-y-1">
                                    <label class="text-xs font-medium text-muted-foreground">{{ trans('commenti_pagina.filters.date_from') }}</label>
                                    <Popover v-model:open="apertoDal">
                                        <PopoverTrigger as-child>
                                            <button
                                                type="button"
                                                class="flex h-8 w-full items-center justify-between rounded-md border border-input bg-background px-3 py-1 text-xs ring-offset-background focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 text-start tabular-nums"
                                                :class="!statoFiltro.dal && 'text-muted-foreground'"
                                            >
                                                <span class="truncate">{{ statoFiltro.dal ? formattaDataBreve(statoFiltro.dal) : 'gg/mm/aaaa' }}</span>
                                                <CalendarIcon class="h-3.5 w-3.5 opacity-50 shrink-0 ml-1.5" />
                                            </button>
                                        </PopoverTrigger>
                                        <PopoverContent class="w-auto p-0" align="start">
                                            <Calendar
                                                :model-value="parseIsoDate(statoFiltro.dal)"
                                                locale="it-IT"
                                                initial-focus
                                                @update:model-value="selezionaDal"
                                            />
                                        </PopoverContent>
                                    </Popover>
                                </div>

                                <div class="space-y-1">
                                    <label class="text-xs font-medium text-muted-foreground">{{ trans('commenti_pagina.filters.date_to') }}</label>
                                    <Popover v-model:open="apertoAl">
                                        <PopoverTrigger as-child>
                                            <button
                                                type="button"
                                                class="flex h-8 w-full items-center justify-between rounded-md border border-input bg-background px-3 py-1 text-xs ring-offset-background focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 text-start tabular-nums"
                                                :class="!statoFiltro.al && 'text-muted-foreground'"
                                            >
                                                <span class="truncate">{{ statoFiltro.al ? formattaDataBreve(statoFiltro.al) : 'gg/mm/aaaa' }}</span>
                                                <CalendarIcon class="h-3.5 w-3.5 opacity-50 shrink-0 ml-1.5" />
                                            </button>
                                        </PopoverTrigger>
                                        <PopoverContent class="w-auto p-0" align="start">
                                            <Calendar
                                                :model-value="parseIsoDate(statoFiltro.al)"
                                                locale="it-IT"
                                                initial-focus
                                                @update:model-value="selezionaAl"
                                            />
                                        </PopoverContent>
                                    </Popover>
                                </div>
                            </form>

                            <div class="flex items-center gap-2 shrink-0 lg:ml-2">
                                <Button
                                    v-if="haFiltriAttivi"
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    class="h-8 px-2.5 text-xs text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800"
                                    @click="azzeraFiltri"
                                >
                                    <X class="h-3.5 w-3.5 mr-1" />
                                    {{ trans('commenti_pagina.filters.reset') }}
                                </Button>

                            </div>
                        </div>

                        <!-- L'elemento scelto da «Modera» dell'Action inbox: un'etichetta che si toglie da sé. -->
                        <div v-if="statoFiltro.elemento !== null" class="flex flex-wrap items-center gap-2" data-testid="filtro-elemento">
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 rounded-md border border-indigo-200 bg-indigo-50 px-2 py-0.5 text-xs font-semibold text-indigo-700 hover:bg-indigo-100 dark:border-indigo-900 dark:bg-indigo-950/40 dark:text-indigo-300"
                                :aria-label="trans('commenti_pagina.filters.element_remove')"
                                @click="statoFiltro.elemento = null; applicaFiltri()"
                            >
                                {{ trans('commenti_pagina.filters.element', { nome: props.filtri?.elemento_nome || '#' + statoFiltro.elemento }) }}
                                <X class="h-3 w-3" />
                            </button>
                        </div>

                        <!-- Barra azioni in blocco -->
                        <div
                            v-if="selezionati.length > 0"
                            data-barra-blocco
                            class="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-muted/40 px-4 py-2.5"
                        >
                            <span class="text-sm font-medium tabular-nums">
                                {{ transChoice('commenti_pagina.actions.selected', selezionati.length, { count: String(selezionati.length) }) }}
                            </span>

                            <div class="flex flex-wrap items-center gap-2">
                                <Button
                                    v-if="puoApprovareSelezionati"
                                    data-azione-blocco="approva"
                                    type="button"
                                    size="sm"
                                    :disabled="azioneInCorso"
                                    @click="approvaInBlocco"
                                >
                                    <Check class="h-4 w-4 mr-1.5" />
                                    {{ trans('commenti_pagina.actions.approve_selected') }}
                                </Button>

                                <Button
                                    v-if="puoNascondereSelezionati"
                                    data-azione-blocco="nascondi"
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    :disabled="azioneInCorso"
                                    @click="mostraConfermaBlocco = 'nascondi'"
                                >
                                    <EyeOff class="h-4 w-4 mr-1.5" />
                                    {{ trans('commenti_pagina.actions.hide_selected') }}
                                </Button>

                                <Button
                                    v-if="puoMostrareSelezionati"
                                    data-azione-blocco="mostra"
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    :disabled="azioneInCorso"
                                    @click="mostraDiNuovoInBlocco"
                                >
                                    <Eye class="h-4 w-4 mr-1.5" />
                                    {{ trans('commenti_pagina.actions.show_again_selected') }}
                                </Button>

                                <Button
                                    v-if="puoEliminareSelezionati"
                                    data-azione-blocco="elimina"
                                    type="button"
                                    variant="destructive"
                                    size="sm"
                                    :disabled="azioneInCorso"
                                    @click="mostraConfermaBlocco = 'elimina'"
                                >
                                    <Trash2 class="h-4 w-4 mr-1.5" />
                                    {{ trans('commenti_pagina.actions.delete_selected') }}
                                </Button>
                            </div>
                        </div>

                        <!-- Tabella commenti -->
                        <div v-if="commenti.data.length > 0" class="border rounded-md">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead class="w-10">
                                            <Checkbox
                                                :model-value="tuttiSelezionati"
                                                @update:model-value="alternaTutti"
                                            />
                                        </TableHead>
                                        <TableHead>{{ trans('commenti_pagina.table.comment') }}</TableHead>
                                        <TableHead>{{ trans('commenti_pagina.table.element') }}</TableHead>
                                        <TableHead class="whitespace-nowrap">{{ trans('commenti_pagina.table.condominio') }}</TableHead>
                                        <TableHead class="whitespace-nowrap">
                                            <div class="inline-flex items-center gap-1.5">
                                                <span>{{ trans('commenti_pagina.table.author') }}</span>
                                                <HoverCard>
                                                    <HoverCardTrigger as-child>
                                                        <button type="button" class="text-slate-400 hover:text-primary outline-none cursor-pointer">
                                                            <Info class="w-3.5 h-3.5" />
                                                        </button>
                                                    </HoverCardTrigger>
                                                    <HoverCardContent class="w-80 p-4 bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-800 shadow-xl font-normal">
                                                        <h4 class="text-sm font-bold mb-1.5 text-slate-900 dark:text-slate-100">{{ trans('commenti_pagina.table.author') }}</h4>
                                                        <p class="text-xs text-slate-500 dark:text-slate-400 leading-relaxed">{{ trans('commenti_pagina.filters.author_tooltip') }}</p>
                                                    </HoverCardContent>
                                                </HoverCard>
                                            </div>
                                        </TableHead>
                                        <TableHead class="whitespace-nowrap">{{ trans('commenti_pagina.table.date') }}</TableHead>
                                        <TableHead class="whitespace-nowrap">{{ trans('commenti_pagina.table.status') }}</TableHead>
                                        <TableHead class="text-right w-12">{{ trans('commenti_pagina.table.actions') }}</TableHead>
                                    </TableRow>
                                </TableHeader>

                                <TableBody>
                                    <TableRow
                                        v-for="riga in commenti.data"
                                        :key="riga.id"
                                        data-riga-commento
                                        :data-state="selezionati.includes(riga.id) ? 'selected' : undefined"
                                    >
                                        <TableCell>
                                            <Checkbox
                                                :model-value="selezionati.includes(riga.id)"
                                                @update:model-value="(val) => alternaRiga(riga.id, val)"
                                            />
                                        </TableCell>
                                        <TableCell class="min-w-[16rem] max-w-md">
                                            <div class="flex flex-col gap-0.5">
                                                <span class="line-clamp-2 break-words" :title="riga.testo">{{ riga.testo }}</span>
                                                <span v-if="riga.modificato" class="text-xs text-muted-foreground">
                                                    {{ trans('commenti_pagina.edited') }}
                                                </span>
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <div class="flex flex-col gap-0.5">
                                                <Link
                                                    :href="riga.url_elemento"
                                                    class="font-medium text-primary hover:underline"
                                                >
                                                    {{ riga.elemento }}
                                                </Link>
                                                <span class="text-xs text-muted-foreground">
                                                    {{
                                                        riga.tipo === 'segnalazioni'
                                                            ? trans('commenti_pagina.filters.type_segnalazioni')
                                                            : trans('commenti_pagina.filters.type_comunicazioni')
                                                    }}
                                                </span>
                                            </div>
                                        </TableCell>
                                        <TableCell class="text-xs text-muted-foreground whitespace-nowrap">
                                            {{ riga.condominio }}
                                        </TableCell>
                                        <TableCell class="text-xs text-muted-foreground whitespace-nowrap">
                                            {{ riga.autore }}
                                        </TableCell>
                                        <TableCell class="text-xs text-muted-foreground tabular-nums whitespace-nowrap">
                                            {{ formattaData(riga.creato_il) }}
                                        </TableCell>
                                        <TableCell class="whitespace-nowrap">
                                            <Badge
                                                v-if="riga.stato === 'in_attesa'"
                                                variant="secondary"
                                                class="bg-amber-500/15 text-amber-700 dark:text-amber-300 border-amber-500/30 text-xs whitespace-nowrap"
                                            >
                                                {{ trans('commenti_pagina.pending_badge') }}
                                            </Badge>
                                            <Badge
                                                v-else-if="riga.stato === 'nascosto'"
                                                variant="secondary"
                                                class="bg-slate-500/15 text-slate-700 dark:text-slate-300 border-slate-500/30 text-xs whitespace-nowrap"
                                            >
                                                {{ trans('commenti_pagina.hidden_badge') }}
                                            </Badge>
                                            <Badge v-else variant="outline" class="text-xs whitespace-nowrap">
                                                {{ trans('commenti_pagina.published_badge') }}
                                            </Badge>
                                        </TableCell>
                                        <TableCell class="text-right w-12">
                                            <DropdownMenu>
                                                <DropdownMenuTrigger as-child>
                                                    <Button
                                                        variant="ghost"
                                                        class="w-8 h-8 p-0"
                                                        :aria-label="trans('commenti_pagina.table.actions')"
                                                    >
                                                        <MoreHorizontal class="w-4 h-4" />
                                                    </Button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end">
                                                    <DropdownMenuLabel>{{ trans('commenti_pagina.table.actions') }}</DropdownMenuLabel>

                                                    <DropdownMenuItem as-child>
                                                        <Link
                                                            :href="riga.url_commenti"
                                                            class="flex items-center gap-2 cursor-pointer"
                                                        >
                                                            <ExternalLink class="w-4 h-4 text-xs" />
                                                            {{ trans('commenti_pagina.actions.open_in_card') }}
                                                        </Link>
                                                    </DropdownMenuItem>

                                                    <DropdownMenuItem
                                                        v-if="riga.puo_rispondere"
                                                        :disabled="azioneInCorso"
                                                        class="flex items-center gap-2 cursor-pointer"
                                                        @click="apriRisposta(riga)"
                                                    >
                                                        <MessageSquareReply class="w-4 h-4 text-xs" />
                                                        {{ trans('commenti_pagina.actions.reply') }}
                                                    </DropdownMenuItem>

                                                    <DropdownMenuItem
                                                        v-if="riga.puo_modificare"
                                                        :disabled="azioneInCorso"
                                                        class="flex items-center gap-2 cursor-pointer"
                                                        @click="apriModifica(riga)"
                                                    >
                                                        <Pencil class="w-4 h-4 text-xs" />
                                                        {{ trans('commenti_pagina.actions.edit') }}
                                                    </DropdownMenuItem>

                                                    <DropdownMenuItem
                                                        v-if="riga.puo_approvare"
                                                        :disabled="azioneInCorso"
                                                        class="flex items-center gap-2 cursor-pointer"
                                                        @click="approvaSingolo(riga)"
                                                    >
                                                        <Check class="w-4 h-4 text-xs text-emerald-600 dark:text-emerald-400" />
                                                        {{ trans('commenti_pagina.actions.approve') }}
                                                    </DropdownMenuItem>

                                                    <DropdownMenuItem
                                                        v-if="riga.puo_nascondere"
                                                        :disabled="azioneInCorso"
                                                        class="flex items-center gap-2 cursor-pointer"
                                                        @click="occultamento.chiedi(riga)"
                                                    >
                                                        <EyeOff class="w-4 h-4 text-xs" />
                                                        {{ trans('commenti_pagina.actions.hide') }}
                                                    </DropdownMenuItem>

                                                    <DropdownMenuItem
                                                        v-if="riga.puo_mostrare"
                                                        :disabled="azioneInCorso"
                                                        data-azione-riga="mostra"
                                                        class="flex items-center gap-2 cursor-pointer"
                                                        @click="mostraDiNuovoSingolo(riga)"
                                                    >
                                                        <Eye class="w-4 h-4 text-xs" />
                                                        {{ trans('commenti_pagina.actions.show_again') }}
                                                    </DropdownMenuItem>

                                                    <DropdownMenuSeparator v-if="riga.puo_eliminare" />

                                                    <DropdownMenuItem
                                                        v-if="riga.puo_eliminare"
                                                        :disabled="azioneInCorso"
                                                        class="flex items-center gap-2 cursor-pointer text-destructive focus:text-destructive"
                                                        @click="eliminazione.chiedi(riga)"
                                                    >
                                                        <Trash2 class="w-4 h-4 text-xs" />
                                                        {{ trans('commenti_pagina.actions.delete') }}
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        </div>

                        <Empty v-else class="border border-dashed py-12">
                            <EmptyHeader class="max-w-4xl">
                                <EmptyMedia
                                    variant="icon"
                                    :class="
                                        haFiltriAttivi
                                            ? 'bg-slate-100/70 dark:bg-slate-800/50 text-slate-500'
                                            : 'bg-indigo-50/50 dark:bg-indigo-900/20 text-indigo-500'
                                    "
                                >
                                    <component :is="haFiltriAttivi ? SearchX : MessageSquare" class="w-8 h-8" />
                                </EmptyMedia>
                                <EmptyTitle>
                                    {{
                                        haFiltriAttivi
                                            ? trans('commenti_pagina.empty_filtered_title')
                                            : trans('commenti_pagina.empty')
                                    }}
                                </EmptyTitle>
                                <EmptyDescription>
                                    {{
                                        haFiltriAttivi
                                            ? trans('commenti_pagina.empty_filtered_description')
                                            : trans('commenti_pagina.empty_description')
                                    }}
                                </EmptyDescription>
                            </EmptyHeader>
                        </Empty>

                        <!-- Riepilogo e paginazione stile altre tabelle -->
                        <div v-if="commenti.data.length > 0" class="flex items-center justify-end py-4 space-x-2">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-2 lg:space-x-2">
                                    <div class="flex items-center space-x-2 menu-commenti w-[84px]" data-testid="righe-per-pagina">
                                        <!-- Selettore righe per pagina -->
                                        <v-select
                                            :model-value="String(commenti.per_page)"
                                            :options="vociPerPage"
                                            label="etichetta"
                                            :reduce="valoreDellaVoce"
                                            :searchable="false"
                                            :clearable="false"
                                            class="w-full"
                                            :aria-label="trans('commenti_pagina.per_page')"
                                            @update:model-value="(v: string) => cambiaPerPage(v)"
                                        />
                                    </div>

                                    <!-- Controlli paginazione -->
                                    <div class="flex items-center space-x-2">
                                        <Button
                                            variant="outline"
                                            class="hidden w-8 h-8 p-0 lg:flex"
                                            :disabled="commenti.current_page === 1"
                                            @click="vaiAPagina(1)"
                                        >
                                            <span class="sr-only">Vai alla prima pagina</span>
                                            <ChevronsLeft class="w-4 h-4" />
                                        </Button>

                                        <Button
                                            variant="outline"
                                            class="w-8 h-8 p-0"
                                            :disabled="commenti.current_page === 1"
                                            @click="vaiAPagina(commenti.current_page - 1)"
                                        >
                                            <span class="sr-only">Vai alla pagina precedente</span>
                                            <ChevronLeft class="w-4 h-4" />
                                        </Button>

                                        <Button
                                            variant="outline"
                                            class="w-8 h-8 p-0"
                                            :disabled="commenti.current_page === commenti.last_page"
                                            @click="vaiAPagina(commenti.current_page + 1)"
                                        >
                                            <span class="sr-only">Vai alla prossima pagina</span>
                                            <ChevronRight class="w-4 h-4" />
                                        </Button>

                                        <Button
                                            variant="outline"
                                            class="hidden w-8 h-8 p-0 lg:flex"
                                            :disabled="commenti.current_page === commenti.last_page"
                                            @click="vaiAPagina(commenti.last_page)"
                                        >
                                            <span class="sr-only">Vai all'ultima pagina</span>
                                            <ChevronsRight class="w-4 h-4" />
                                        </Button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>

        <!-- Rispondi: il modulo dei commenti già aperto, nella discussione del commento -->
        <Dialog v-model:open="apertaRisposta">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ trans('commenti_pagina.reply.title') }}</DialogTitle>
                    <DialogDescription>
                        {{ trans('commenti_pagina.reply.description', { elemento: rigaRisposta?.elemento ?? '' }) }}
                    </DialogDescription>
                </DialogHeader>
                <FormCommento
                    v-if="rigaRisposta && rigaRisposta.rispondi"
                    :key="rigaRisposta.id"
                    :can-comment="true"
                    :can-create="true"
                    :url="rigaRisposta.rispondi.url"
                    :dati="rigaRisposta.rispondi.dati"
                    :chiave="String(rigaRisposta.id)"
                    :aperto-di-serie="true"
                    :ricarica="PROPS_DOPO_AZIONE"
                    @inviato="chiudiRisposta"
                    @annullato="chiudiRisposta"
                />
            </DialogContent>
        </Dialog>

        <!-- Modifica: il testo del commento -->
        <Dialog v-model:open="apertaModifica">
            <DialogContent>
                <form @submit.prevent="salvaModifica" class="space-y-4">
                    <DialogHeader>
                        <DialogTitle>{{ trans('commenti_pagina.edit.title') }}</DialogTitle>
                        <DialogDescription>
                            {{ trans('commenti_pagina.edit.description', { elemento: rigaModifica?.elemento ?? '' }) }}
                        </DialogDescription>
                    </DialogHeader>
                    <div class="space-y-1">
                        <label for="testo-commento" class="text-xs font-medium text-muted-foreground">
                            {{ trans('commenti_pagina.edit.label') }}
                        </label>
                        <textarea
                            id="testo-commento"
                            v-model="formModifica.corpo"
                            name="corpo"
                            rows="5"
                            class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-ring"
                        />
                        <div class="flex items-center justify-between">
                            <span
                                class="text-xs tabular-nums"
                                :class="formModifica.corpo.length > MAX_CARATTERI ? 'text-red-500 font-semibold' : 'text-muted-foreground'"
                            >
                                {{ formModifica.corpo.length }}/{{ MAX_CARATTERI }}
                            </span>
                            <p v-if="formModifica.errors.corpo" class="text-xs text-red-500">{{ formModifica.errors.corpo }}</p>
                        </div>
                    </div>
                    <DialogFooter class="gap-2">
                        <Button type="button" variant="outline" @click="apertaModifica = false">
                            {{ trans('commenti_pagina.actions.cancel') }}
                        </Button>
                        <Button
                            type="submit"
                            :disabled="formModifica.processing || !formModifica.corpo.trim() || formModifica.corpo.length > MAX_CARATTERI"
                        >
                            {{ trans('commenti_pagina.actions.save') }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Conferma eliminazione singola: `model-value` è l'interruttore, non il dato (vedi `useConfermaEliminazione`) -->
        <ConfirmDialog
            :model-value="eliminazione.confermaAperta.value"
            :title="trans('commenti_pagina.confirm_delete.title')"
            :description="trans('commenti_pagina.confirm_delete.description', { autore: eliminazione.daEliminare.value?.autore ?? '' })"
            :confirm-text="trans('commenti_pagina.actions.delete')"
            :cancel-text="trans('commenti_pagina.actions.cancel')"
            variant="destructive"
            :loading="eliminazione.inCorso.value"
            @update:model-value="eliminazione.suCambioApertura"
            @confirm="eliminazione.conferma(confermaEliminaSingolo)"
        />

        <!-- Conferma «Nascondi» singolo -->
        <ConfirmDialog
            :model-value="occultamento.confermaAperta.value"
            :title="trans('commenti_pagina.confirm_hide.title')"
            :description="trans('commenti_pagina.confirm_hide.description', { autore: occultamento.daEliminare.value?.autore ?? '' })"
            :confirm-text="trans('commenti_pagina.actions.hide')"
            :cancel-text="trans('commenti_pagina.actions.cancel')"
            variant="warning"
            :loading="occultamento.inCorso.value"
            @update:model-value="occultamento.suCambioApertura"
            @confirm="occultamento.conferma(confermaNascondiSingolo)"
        />

        <!-- Conferma in blocco -->
        <Dialog :open="mostraConfermaBlocco !== null" @update:open="(v: boolean) => { if (!v) mostraConfermaBlocco = null; }">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {{
                            mostraConfermaBlocco === 'nascondi'
                                ? trans('commenti_pagina.confirm_hide.bulk_title')
                                : trans('commenti_pagina.confirm_delete.bulk_title')
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        {{
                            mostraConfermaBlocco === 'nascondi'
                                ? trans('commenti_pagina.confirm_hide.bulk_description')
                                : trans('commenti_pagina.confirm_delete.bulk_description')
                        }}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2">
                    <Button type="button" variant="outline" @click="mostraConfermaBlocco = null">
                        {{ trans('commenti_pagina.actions.cancel') }}
                    </Button>
                    <Button
                        v-if="mostraConfermaBlocco === 'nascondi'"
                        data-conferma-blocco="nascondi"
                        type="button"
                        :disabled="azioneInCorso"
                        @click="confermaNascondiInBlocco"
                    >
                        {{ trans('commenti_pagina.actions.hide_selected') }}
                    </Button>
                    <Button
                        v-else
                        data-conferma-blocco="elimina"
                        type="button"
                        variant="destructive"
                        :disabled="azioneInCorso"
                        @click="confermaEliminaInBlocco"
                    >
                        {{ trans('commenti_pagina.actions.delete_selected') }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>

<style>
/* I menu della pagina «Commenti» (decisione 392): alti come i campi accanto (`h-8`), con il testo piccolo della barra dei filtri,
   e leggibili nel tema scuro, come i filtri di «Rate scadute». Solo qui: gli altri menu del programma restano come sono. */
.menu-commenti .v-select {
    --vs-font-size: 0.75rem;
    --vs-line-height: 1.25;
    --vs-border-radius: 0.375rem;
    --vs-border-color: var(--input);
    --vs-controls-color: rgb(100 116 139);
    background: transparent;
}
.menu-commenti .vs__dropdown-toggle {
    min-height: 2rem;
    padding: 0;
    align-items: center;
    box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05);
}
.menu-commenti .vs__selected-options {
    align-items: center;
    padding-left: 6px;
}
/* Il testo al centro del campo: niente margine in alto né lo spazio che `custom.css` dà alla ricerca. */
.menu-commenti .vs__selected,
.menu-commenti .vs__search {
    margin: 0 !important;
    padding-top: 0 !important;
    padding-bottom: 0 !important;
}
.menu-commenti .vs__search,
.menu-commenti .vs__search::placeholder {
    font-size: 0.75rem !important;
}
.menu-commenti .vs__actions {
    padding: 0 8px 0 4px;
}
.menu-commenti .vs__open-indicator {
    transform: scale(0.75);
}
.menu-commenti .vs--open .vs__open-indicator {
    transform: rotate(180deg) scale(0.75);
}
.menu-commenti .vs__dropdown-menu {
    font-size: 0.75rem;
    max-height: 18rem;
}
.dark .menu-commenti .v-select {
    --vs-dropdown-bg: rgb(15 23 42);
    --vs-dropdown-color: rgb(226 232 240);
    --vs-dropdown-option-color: rgb(226 232 240);
    --vs-selected-color: rgb(226 232 240);
    --vs-search-input-color: rgb(226 232 240);
    --vs-controls-color: rgb(148 163 184);
    --vs-border-color: var(--input);
}
/* Nel tema scuro lo sfondo dei campi di testo del programma (`dark:bg-input/30`), perché i menu non sembrino un'altra cosa. */
.dark .menu-commenti .vs__dropdown-toggle {
    background: color-mix(in oklab, var(--input) 30%, transparent);
}
/* `custom.css` evidenzia l'opzione con un grigio chiarissimo e il testo nero: nel tema scuro diventava una barra bianca. */
.dark .menu-commenti .vs__dropdown-option--highlight {
    background: rgb(30 41 59) !important;
    color: rgb(241 245 249) !important;
}
</style>
