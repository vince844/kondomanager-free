<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
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
    Archive,
    Calendar as CalendarIcon,
    Check,
    ChevronLeft,
    ChevronRight,
    ChevronsLeft,
    ChevronsRight,
    Download,
    ExternalLink,
    Files,
    Info,
    MoreHorizontal,
    SearchX,
    ShieldCheck,
    Trash2,
    X,
} from 'lucide-vue-next';
import { parseDate, type DateValue } from '@internationalized/date';
import { trans, wTrans } from 'laravel-vue-i18n';
import type { AllegatiFiltri, AllegatoRigaPagina } from '@/types/allegati';

interface PaginatedAllegati {
    data: AllegatoRigaPagina[];
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
    allegati: PaginatedAllegati;
    filtri: AllegatiFiltri;
    condomini: CondominioOption[];
}

const props = defineProps<Props>();
const page = usePage();
const flashMessage = computed(() => (page.props.flash as { message?: { message: string; type: string } })?.message);

// Array vuoto per forzare l'header in modalità compatta, allineato agli altri elenchi
const breadcrumbs: never[] = [];

const pageGuides = computed(() => [
    {
        title: trans('allegati.guides.overview_title'),
        description: trans('allegati.guides.overview_desc'),
        icon: Files,
        colorVariant: 'blue' as const,
    },
    {
        title: trans('allegati.guides.moderation_title'),
        description: trans('allegati.guides.moderation_desc'),
        icon: ShieldCheck,
        colorVariant: 'amber' as const,
    },
    {
        title: trans('allegati.guides.export_title'),
        description: trans('allegati.guides.export_desc'),
        icon: Archive,
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
    dal: props.filtri?.dal || '',
    al: props.filtri?.al || '',
});

const selezionati = ref<number[]>([]);
const rigaDaEliminare = ref<AllegatoRigaPagina | null>(null);
const mostraConfermaSingola = ref(false);
const mostraConfermaBlocco = ref(false);
const azioneInCorso = ref(false);
const apertoDal = ref(false);
const apertoAl = ref(false);

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
    if (statoFiltro.dal !== '') {
        params.dal = statoFiltro.dal;
    }
    if (statoFiltro.al !== '') {
        params.al = statoFiltro.al;
    }
    return params;
}

const urlZip = computed(() => {
    return route('admin.allegati.zip', parametriFiltro());
});

const etichettaStato = computed(() => {
    if (statoFiltro.stato === 'in_attesa') return wTrans('allegati.filters.status_pending').value;
    if (statoFiltro.stato === 'pubblicato') return wTrans('allegati.filters.status_published').value;
    return wTrans('allegati.filters.status_all').value;
});

const etichettaTipo = computed(() => {
    if (statoFiltro.tipo === 'segnalazioni') return wTrans('allegati.filters.type_segnalazioni').value;
    if (statoFiltro.tipo === 'comunicazioni') return wTrans('allegati.filters.type_comunicazioni').value;
    return wTrans('allegati.filters.type_all').value;
});

const etichettaCondominio = computed(() => {
    if (statoFiltro.condominio_id !== 'tutti') {
        const trovato = props.condomini.find((c) => String(c.id) === statoFiltro.condominio_id);
        if (trovato) return trovato.nome;
    }
    return wTrans('allegati.filters.condominio_all').value;
});

const haFiltriAttivi = computed(
    () =>
        statoFiltro.stato !== 'tutti' ||
        statoFiltro.tipo !== 'tutti' ||
        statoFiltro.condominio_id !== 'tutti' ||
        statoFiltro.autore.trim() !== '' ||
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
    const inUso = props.allegati.per_page;
    return consentite.includes(inUso) ? consentite : [...consentite, inUso].sort((a, b) => a - b);
});

function cambiaPerPage(nuovo: number | string) {
    const perPage = Number(nuovo);
    selezionati.value = [];
    router.get(
        route('admin.allegati.index'),
        {
            ...parametriFiltro(),
            per_page: perPage,
            page: 1,
        },
        {
            preserveState: true,
            preserveScroll: true,
        }
    );
}

function vaiAPagina(pagina: number) {
    if (pagina < 1 || pagina > props.allegati.last_page || pagina === props.allegati.current_page) {
        return;
    }
    selezionati.value = [];
    router.get(
        route('admin.allegati.index'),
        {
            ...parametriFiltro(),
            per_page: props.allegati.per_page,
            page: pagina,
        },
        {
            preserveState: true,
            preserveScroll: true,
        }
    );
}

function applicaFiltri() {
    selezionati.value = [];
    router.get(
        route('admin.allegati.index'),
        {
            ...parametriFiltro(),
            per_page: props.allegati.per_page,
            page: 1,
        },
        {
            preserveState: true,
            preserveScroll: true,
        }
    );
}

function azzeraFiltri() {
    statoFiltro.stato = 'tutti';
    statoFiltro.tipo = 'tutti';
    statoFiltro.condominio_id = 'tutti';
    statoFiltro.autore = '';
    statoFiltro.dal = '';
    statoFiltro.al = '';
    applicaFiltri();
}

const righeSelezionate = computed(() =>
    props.allegati.data.filter((r) => selezionati.value.includes(r.id))
);

const tuttiSelezionati = computed(
    () =>
        props.allegati.data.length > 0 &&
        props.allegati.data.every((r) => selezionati.value.includes(r.id))
);

const puoApprovareSelezionati = computed(
    () =>
        righeSelezionate.value.length > 0 &&
        righeSelezionate.value.every((r) => r.puo_approvare && r.stato === 'in_attesa')
);

const puoEliminareSelezionati = computed(
    () =>
        righeSelezionate.value.length > 0 &&
        righeSelezionate.value.every((r) => r.puo_eliminare)
);

function alternaTutti(valore: boolean | 'indeterminate') {
    if (valore === true) {
        selezionati.value = props.allegati.data.map((r) => r.id);
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

function formattaDimensione(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1).replace('.', ',')} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(2).replace('.', ',')} MB`;
}

function formattaData(iso: string | null): string {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return iso;
    return d.toLocaleDateString('it-IT', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function approvaSingolo(riga: AllegatoRigaPagina) {
    azioneInCorso.value = true;
    router.post(
        route('admin.allegati.approva', riga.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                azioneInCorso.value = false;
            },
        }
    );
}

function approvaInBlocco() {
    if (!puoApprovareSelezionati.value) return;
    azioneInCorso.value = true;
    router.post(
        route('admin.allegati.approva-in-blocco'),
        { ids: selezionati.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                selezionati.value = [];
            },
            onFinish: () => {
                azioneInCorso.value = false;
            },
        }
    );
}

function chiediEliminaSingolo(riga: AllegatoRigaPagina) {
    rigaDaEliminare.value = riga;
    mostraConfermaSingola.value = true;
}

function confermaEliminaSingolo() {
    if (!rigaDaEliminare.value) return;
    const id = rigaDaEliminare.value.id;
    azioneInCorso.value = true;
    router.delete(route('admin.allegati.destroy', id), {
        preserveScroll: true,
        onSuccess: () => {
            selezionati.value = selezionati.value.filter((x) => x !== id);
        },
        onFinish: () => {
            azioneInCorso.value = false;
            mostraConfermaSingola.value = false;
            rigaDaEliminare.value = null;
        },
    });
}

function confermaEliminaInBlocco() {
    if (!puoEliminareSelezionati.value) return;
    azioneInCorso.value = true;
    router.post(
        route('admin.allegati.elimina-in-blocco'),
        { ids: selezionati.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                selezionati.value = [];
            },
            onFinish: () => {
                azioneInCorso.value = false;
                mostraConfermaBlocco.value = false;
            },
        }
    );
}
</script>

<template>
    <Head :title="trans('allegati.header.list_head')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="px-6 py-8 space-y-6">
            <PageHeaderGuide
                :page-title="trans('allegati.header.list_title')"
                :page-subtitle="trans('allegati.header.list_description')"
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
                                <div class="space-y-1">
                                    <label class="text-xs font-medium text-muted-foreground">{{ trans('allegati.filters.status') }}</label>
                                    <Select v-model="statoFiltro.stato" @update:model-value="applicaFiltri">
                                        <SelectTrigger class="w-full h-8 text-xs">
                                            <SelectValue>{{ etichettaStato }}</SelectValue>
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="tutti">{{ trans('allegati.filters.status_all') }}</SelectItem>
                                            <SelectItem value="in_attesa">{{ trans('allegati.filters.status_pending') }}</SelectItem>
                                            <SelectItem value="pubblicato">{{ trans('allegati.filters.status_published') }}</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div class="space-y-1">
                                    <label class="text-xs font-medium text-muted-foreground">{{ trans('allegati.filters.type') }}</label>
                                    <Select v-model="statoFiltro.tipo" @update:model-value="applicaFiltri">
                                        <SelectTrigger class="w-full h-8 text-xs">
                                            <SelectValue>{{ etichettaTipo }}</SelectValue>
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="tutti">{{ trans('allegati.filters.type_all') }}</SelectItem>
                                            <SelectItem value="segnalazioni">{{ trans('allegati.filters.type_segnalazioni') }}</SelectItem>
                                            <SelectItem value="comunicazioni">{{ trans('allegati.filters.type_comunicazioni') }}</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div class="space-y-1">
                                    <label class="text-xs font-medium text-muted-foreground">{{ trans('allegati.filters.condominio') }}</label>
                                    <Select v-model="statoFiltro.condominio_id" @update:model-value="applicaFiltri">
                                        <SelectTrigger class="w-full h-8 text-xs">
                                            <SelectValue>{{ etichettaCondominio }}</SelectValue>
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="tutti">{{ trans('allegati.filters.condominio_all') }}</SelectItem>
                                            <SelectItem
                                                v-for="c in condomini"
                                                :key="c.id"
                                                :value="String(c.id)"
                                            >
                                                {{ c.nome }}
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div class="space-y-1">
                                    <div class="flex items-center gap-1.5">
                                        <label class="text-xs font-medium text-muted-foreground">{{ trans('allegati.filters.author') }}</label>
                                        <HoverCard>
                                            <HoverCardTrigger as-child>
                                                <button type="button" class="text-slate-400 hover:text-primary outline-none cursor-pointer">
                                                    <Info class="w-3.5 h-3.5" />
                                                </button>
                                            </HoverCardTrigger>
                                            <HoverCardContent class="w-80 p-4 bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-800 shadow-xl">
                                                <h4 class="text-sm font-bold mb-1.5">{{ trans('allegati.filters.author') }}</h4>
                                                <p class="text-xs text-slate-500 dark:text-slate-400 leading-relaxed">{{ trans('allegati.filters.author_tooltip') }}</p>
                                            </HoverCardContent>
                                        </HoverCard>
                                    </div>
                                    <Input
                                        v-model="statoFiltro.autore"
                                        type="text"
                                        :placeholder="trans('allegati.filters.author_placeholder')"
                                        class="h-8 text-xs"
                                    />
                                </div>

                                <div class="space-y-1">
                                    <label class="text-xs font-medium text-muted-foreground">{{ trans('allegati.filters.date_from') }}</label>
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
                                    <label class="text-xs font-medium text-muted-foreground">{{ trans('allegati.filters.date_to') }}</label>
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
                                    {{ trans('allegati.filters.reset') }}
                                </Button>

                                <a
                                    :href="urlZip"
                                    class="inline-flex h-8 items-center justify-center gap-2 px-3 py-2 rounded-lg bg-slate-900 dark:bg-slate-700 border border-slate-800 shadow-sm text-xs font-medium text-white hover:bg-slate-800 dark:hover:bg-slate-600 transition-colors"
                                >
                                    <Archive class="w-3.5 h-3.5 text-emerald-400" />
                                    <span>{{ trans('allegati.actions.download_zip') }}</span>
                                </a>
                            </div>
                        </div>

                        <!-- Barra azioni in blocco -->
                        <div
                            v-if="selezionati.length > 0"
                            class="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-muted/40 px-4 py-2.5"
                        >
                            <span class="text-sm font-medium tabular-nums">
                                {{ selezionati.length }} selezionati
                            </span>

                            <div class="flex flex-wrap items-center gap-2">
                                <Button
                                    v-if="puoApprovareSelezionati"
                                    type="button"
                                    size="sm"
                                    :disabled="azioneInCorso"
                                    @click="approvaInBlocco"
                                >
                                    <Check class="h-4 w-4 mr-1.5" />
                                    {{ trans('allegati.actions.approve_selected') }}
                                </Button>

                                <Button
                                    v-if="puoEliminareSelezionati"
                                    type="button"
                                    variant="destructive"
                                    size="sm"
                                    :disabled="azioneInCorso"
                                    @click="mostraConfermaBlocco = true"
                                >
                                    <Trash2 class="h-4 w-4 mr-1.5" />
                                    {{ trans('allegati.actions.delete_selected') }}
                                </Button>
                            </div>
                        </div>

                        <!-- Tabella allegati -->
                        <div v-if="allegati.data.length > 0" class="border rounded-md">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead class="w-10">
                                            <Checkbox
                                                :model-value="tuttiSelezionati"
                                                @update:model-value="alternaTutti"
                                            />
                                        </TableHead>
                                        <TableHead>{{ trans('allegati.table.name') }}</TableHead>
                                        <TableHead>{{ trans('allegati.table.element') }}</TableHead>
                                        <TableHead class="whitespace-nowrap">{{ trans('allegati.table.condominio') }}</TableHead>
                                        <TableHead class="whitespace-nowrap">
                                            <div class="inline-flex items-center gap-1.5">
                                                <span>{{ trans('allegati.table.author') }}</span>
                                                <HoverCard>
                                                    <HoverCardTrigger as-child>
                                                        <button type="button" class="text-slate-400 hover:text-primary outline-none cursor-pointer">
                                                            <Info class="w-3.5 h-3.5" />
                                                        </button>
                                                    </HoverCardTrigger>
                                                    <HoverCardContent class="w-80 p-4 bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-800 shadow-xl font-normal">
                                                        <h4 class="text-sm font-bold mb-1.5 text-slate-900 dark:text-slate-100">{{ trans('allegati.table.author') }}</h4>
                                                        <p class="text-xs text-slate-500 dark:text-slate-400 leading-relaxed">{{ trans('allegati.filters.author_tooltip') }}</p>
                                                    </HoverCardContent>
                                                </HoverCard>
                                            </div>
                                        </TableHead>
                                        <TableHead class="whitespace-nowrap">{{ trans('allegati.table.date') }}</TableHead>
                                        <TableHead class="whitespace-nowrap">{{ trans('allegati.table.status') }}</TableHead>
                                        <TableHead class="text-right w-12">{{ trans('allegati.table.actions') }}</TableHead>
                                    </TableRow>
                                </TableHeader>

                                <TableBody>
                                    <TableRow
                                        v-for="riga in allegati.data"
                                        :key="riga.id"
                                        :data-state="selezionati.includes(riga.id) ? 'selected' : undefined"
                                    >
                                        <TableCell>
                                            <Checkbox
                                                :model-value="selezionati.includes(riga.id)"
                                                @update:model-value="(val) => alternaRiga(riga.id, val)"
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <div class="flex flex-col gap-0.5">
                                                <span class="font-medium break-words">{{ riga.nome }}</span>
                                                <span class="text-xs text-muted-foreground tabular-nums">
                                                    {{ formattaDimensione(riga.dimensione) }}
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
                                                            ? trans('allegati.filters.type_segnalazioni')
                                                            : trans('allegati.filters.type_comunicazioni')
                                                    }}
                                                </span>
                                            </div>
                                        </TableCell>
                                        <TableCell class="text-xs text-muted-foreground whitespace-nowrap">
                                            {{ riga.condomini.join(', ') }}
                                        </TableCell>
                                        <TableCell class="text-xs text-muted-foreground whitespace-nowrap">
                                            {{ riga.autore }}
                                        </TableCell>
                                        <TableCell class="text-xs text-muted-foreground tabular-nums whitespace-nowrap">
                                            {{ formattaData(riga.caricato_il) }}
                                        </TableCell>
                                        <TableCell class="whitespace-nowrap">
                                            <Badge
                                                v-if="riga.stato === 'in_attesa'"
                                                variant="secondary"
                                                class="bg-amber-500/15 text-amber-700 dark:text-amber-300 border-amber-500/30 text-xs whitespace-nowrap"
                                            >
                                                {{ trans('allegati.pending_badge') }}
                                            </Badge>
                                            <Badge v-else variant="outline" class="text-xs whitespace-nowrap">
                                                {{ trans('allegati.published_badge') }}
                                            </Badge>
                                        </TableCell>
                                        <TableCell class="text-right w-12">
                                            <DropdownMenu>
                                                <DropdownMenuTrigger as-child>
                                                    <Button
                                                        variant="ghost"
                                                        class="w-8 h-8 p-0"
                                                        :aria-label="trans('allegati.table.actions')"
                                                    >
                                                        <MoreHorizontal class="w-4 h-4" />
                                                    </Button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end">
                                                    <DropdownMenuLabel>{{ trans('allegati.table.actions') }}</DropdownMenuLabel>

                                                    <DropdownMenuItem as-child>
                                                        <a
                                                            :href="riga.url_apri"
                                                            target="_blank"
                                                            rel="noopener"
                                                            class="flex items-center gap-2 cursor-pointer"
                                                        >
                                                            <ExternalLink class="w-4 h-4 text-xs" />
                                                            {{ trans('allegati.actions.open') }}
                                                        </a>
                                                    </DropdownMenuItem>

                                                    <DropdownMenuItem as-child>
                                                        <a
                                                            :href="riga.url_scarica"
                                                            class="flex items-center gap-2 cursor-pointer"
                                                        >
                                                            <Download class="w-4 h-4 text-xs" />
                                                            {{ trans('allegati.actions.download') }}
                                                        </a>
                                                    </DropdownMenuItem>

                                                    <DropdownMenuItem
                                                        v-if="riga.puo_approvare && riga.stato === 'in_attesa'"
                                                        :disabled="azioneInCorso"
                                                        class="flex items-center gap-2 cursor-pointer"
                                                        @click="approvaSingolo(riga)"
                                                    >
                                                        <Check class="w-4 h-4 text-xs text-emerald-600 dark:text-emerald-400" />
                                                        {{ trans('allegati.actions.approve') }}
                                                    </DropdownMenuItem>

                                                    <DropdownMenuSeparator v-if="riga.puo_eliminare" />

                                                    <DropdownMenuItem
                                                        v-if="riga.puo_eliminare"
                                                        :disabled="azioneInCorso"
                                                        class="flex items-center gap-2 cursor-pointer text-destructive focus:text-destructive"
                                                        @click="chiediEliminaSingolo(riga)"
                                                    >
                                                        <Trash2 class="w-4 h-4 text-xs" />
                                                        {{ trans('allegati.actions.delete') }}
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
                                    <component :is="haFiltriAttivi ? SearchX : Files" class="w-8 h-8" />
                                </EmptyMedia>
                                <EmptyTitle>
                                    {{
                                        haFiltriAttivi
                                            ? trans('allegati.empty_filtered_title')
                                            : trans('allegati.empty')
                                    }}
                                </EmptyTitle>
                                <EmptyDescription>
                                    {{
                                        haFiltriAttivi
                                            ? trans('allegati.empty_filtered_description')
                                            : trans('allegati.empty_description')
                                    }}
                                </EmptyDescription>
                            </EmptyHeader>
                        </Empty>

                        <!-- Riepilogo e paginazione stile altre tabelle -->
                        <div v-if="allegati.data.length > 0" class="flex items-center justify-end py-4 space-x-2">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-2 lg:space-x-2">
                                    <div class="flex items-center space-x-2">
                                        <!-- Selettore righe per pagina -->
                                        <Select
                                            :model-value="String(allegati.per_page)"
                                            @update:model-value="cambiaPerPage"
                                        >
                                            <SelectTrigger class="h-8 w-[70px]">
                                                <SelectValue :placeholder="String(allegati.per_page)" />
                                            </SelectTrigger>
                                            <SelectContent side="top">
                                                <SelectItem
                                                    v-for="pageSize in opzioniPerPage"
                                                    :key="pageSize"
                                                    :value="String(pageSize)"
                                                >
                                                    {{ pageSize }}
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <!-- Controlli paginazione -->
                                    <div class="flex items-center space-x-2">
                                        <Button
                                            variant="outline"
                                            class="hidden w-8 h-8 p-0 lg:flex"
                                            :disabled="allegati.current_page === 1"
                                            @click="vaiAPagina(1)"
                                        >
                                            <span class="sr-only">Vai alla prima pagina</span>
                                            <ChevronsLeft class="w-4 h-4" />
                                        </Button>

                                        <Button
                                            variant="outline"
                                            class="w-8 h-8 p-0"
                                            :disabled="allegati.current_page === 1"
                                            @click="vaiAPagina(allegati.current_page - 1)"
                                        >
                                            <span class="sr-only">Vai alla pagina precedente</span>
                                            <ChevronLeft class="w-4 h-4" />
                                        </Button>

                                        <Button
                                            variant="outline"
                                            class="w-8 h-8 p-0"
                                            :disabled="allegati.current_page === allegati.last_page"
                                            @click="vaiAPagina(allegati.current_page + 1)"
                                        >
                                            <span class="sr-only">Vai alla prossima pagina</span>
                                            <ChevronRight class="w-4 h-4" />
                                        </Button>

                                        <Button
                                            variant="outline"
                                            class="hidden w-8 h-8 p-0 lg:flex"
                                            :disabled="allegati.current_page === allegati.last_page"
                                            @click="vaiAPagina(allegati.last_page)"
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

        <!-- Conferma eliminazione singola -->
        <Dialog v-model:open="mostraConfermaSingola">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ trans('allegati.confirm_delete.title') }}</DialogTitle>
                    <DialogDescription>
                        {{
                            trans('allegati.confirm_delete.description', {
                                nome: rigaDaEliminare?.nome ?? '',
                            })
                        }}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2">
                    <Button type="button" variant="outline" @click="mostraConfermaSingola = false">
                        {{ trans('allegati.actions.cancel') }}
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        :disabled="azioneInCorso"
                        @click="confermaEliminaSingolo"
                    >
                        {{ trans('allegati.actions.delete') }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Conferma eliminazione in blocco -->
        <Dialog v-model:open="mostraConfermaBlocco">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ trans('allegati.confirm_delete.bulk_title') }}</DialogTitle>
                    <DialogDescription>
                        {{ trans('allegati.confirm_delete.bulk_description') }}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2">
                    <Button type="button" variant="outline" @click="mostraConfermaBlocco = false">
                        {{ trans('allegati.actions.cancel') }}
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        :disabled="azioneInCorso"
                        @click="confermaEliminaInBlocco"
                    >
                        {{ trans('allegati.actions.delete_selected') }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>

