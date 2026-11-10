<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { debounce } from 'lodash'; 
import {
    Mail,
    Search,
    CheckCircle2,
    FileText,
    AlertCircle,
    Activity,
    RotateCcw,
    Calendar as CalendarIcon,
} from 'lucide-vue-next';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import { Calendar } from '@/components/ui/calendar';
import PageHeaderGuide, { type GuideItem } from '@/components/PageHeaderGuide.vue';
import { parseDate } from '@internationalized/date';
import { trans } from 'laravel-vue-i18n';
import type { BreadcrumbItem } from '@/types';

// Props
const props = withDefaults(defineProps<{
    mailLogs: any;
    filters?: {
        search?: string;
        status?: string;
        mailer?: string;
        date_from?: string;
        date_to?: string;
        per_page?: number;
    };
    availableMailers?: string[];
}>(), {
    filters: () => ({ search: '', status: 'tutti', mailer: 'tutti', date_from: '', date_to: '', per_page: 10 }),
    availableMailers: () => [],
});

// Stato filtri
const search = ref(props.filters.search || '');
const status = ref(props.filters.status || 'tutti');
const mailer = ref(props.filters.mailer || 'tutti');
const dateFrom = ref(props.filters.date_from || '');
const dateTo = ref(props.filters.date_to || '');
const perPage = ref(String(props.filters.per_page || 10));

const apertoDal = ref(false);
const apertoAl = ref(false);

function applyFilters() {
    router.get(route('logs.index'), {
        search: search.value || undefined,
        status: status.value !== 'tutti' ? status.value : undefined,
        mailer: mailer.value !== 'tutti' ? mailer.value : undefined,
        date_from: dateFrom.value || undefined,
        date_to: dateTo.value || undefined,
        per_page: perPage.value !== '10' ? perPage.value : undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

const handleSearch = debounce(() => {
    applyFilters();
}, 300);

watch(search, () => {
    handleSearch();
});

function selezionaDal(val: any) {
    dateFrom.value = val ? val.toString() : '';
    apertoDal.value = false;
    applyFilters();
}

function selezionaAl(val: any) {
    dateTo.value = val ? val.toString() : '';
    apertoAl.value = false;
    applyFilters();
}

function cambioStato(val: any) {
    status.value = val;
    applyFilters();
}

function cambioMailer(val: any) {
    mailer.value = val;
    applyFilters();
}

function cambioPerPage(val: any) {
    perPage.value = val;
    applyFilters();
}

const haFiltriAttivi = computed(() => {
    return (
        search.value.trim() !== '' ||
        status.value !== 'tutti' ||
        mailer.value !== 'tutti' ||
        dateFrom.value !== '' ||
        dateTo.value !== '' ||
        perPage.value !== '10'
    );
});

function azzeraFiltri() {
    search.value = '';
    status.value = 'tutti';
    mailer.value = 'tutti';
    dateFrom.value = '';
    dateTo.value = '';
    perPage.value = '10';
    applyFilters();
}

function parseIsoDate(iso: string) {
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

const etichettaStato = computed(() => {
    if (status.value === 'sent') return 'Inviata';
    if (status.value === 'logged') return 'Non partita';
    if (status.value === 'failed') return 'Fallita';
    return 'Tutti gli stati';
});

const etichettaMailer = computed(() => {
    if (mailer.value !== 'tutti') return mailer.value.toUpperCase();
    return 'Tutti i driver';
});

// Breadcrumbs e Guide
const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    {
        title: trans('impostazioni.label.settings'),
        href: '/impostazioni',
    },
    {
        title: 'Audit & Logs',
        href: '/logs',
    },
]);

const pageGuides = computed<GuideItem[]>(() => [
    {
        title: 'Registro invii',
        description: 'Traccia tutte le email generate dal programma verso condòmini e collaboratori.',
        icon: Mail,
        colorVariant: 'blue' as const,
    },
    {
        title: 'Diagnosi invii falliti',
        description: 'Se un\'email non parte o il server rifiuta la connessione, il motivo è indicato sotto il destinatario.',
        icon: AlertCircle,
        colorVariant: 'amber' as const,
    },
    {
        title: 'Stato di consegna',
        description: 'Verifica se i messaggi sono stati inviati (sent) o solo registrati su log in assenza di server SMTP.',
        icon: CheckCircle2,
        colorVariant: 'emerald' as const,
    },
]);

// Helper Formattazione Data
const formatDate = (dateString: string) => {
    if (!dateString) return '-';
    return new Date(dateString).toLocaleString('it-IT', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit'
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="[]">
        <Head title="Audit & Logs" />

        <div class="px-4 py-6 space-y-6">
            <PageHeaderGuide
                page-title="Audit & Logs"
                page-subtitle="Il registro delle email inviate dal programma, con il motivo di quelle non partite."
                :icon="Activity"
                :guides="pageGuides"
                :breadcrumbs="breadcrumbs"
                back-url="/impostazioni"
                :back-text="trans('impostazioni.label.settings')"
                :video-url="null"
            />

            <!-- Scheda e barra filtri -->
            <div class="border border-slate-200 dark:border-slate-800 rounded-2xl bg-white dark:bg-slate-950 overflow-hidden shadow-sm p-4 space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <nav class="inline-flex items-center space-x-2 shadow-sm ring-1 ring-black/5 md:rounded-lg p-1.5 bg-slate-50 dark:bg-card w-full sm:w-auto overflow-x-auto">
                        <!-- Una sola scheda finché «Attività Sistema» non ha dati (Coda 465): è quella aperta. -->
                        <Button
                            variant="ghost"
                            class="justify-start gap-2 bg-white dark:bg-slate-900 shadow-sm"
                        >
                            <Mail class="h-4 w-4 text-primary" />
                            Email Logs
                            <Badge variant="secondary" class="ml-1 h-5 px-1.5 text-[10px] pointer-events-none tabular-nums">
                                {{ props.mailLogs.total }}
                            </Badge>
                        </Button>
                    </nav>

                    <Button
                        v-if="haFiltriAttivi"
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="h-8 px-2.5 text-xs text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 self-end sm:self-auto gap-1.5"
                        @click="azzeraFiltri"
                    >
                        <RotateCcw class="w-3.5 h-3.5" />
                        <span>Azzera filtri</span>
                    </Button>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-2.5 items-end pt-2 border-t border-slate-100 dark:border-slate-800">
                    <!-- Destinatario o oggetto -->
                    <div class="space-y-1">
                        <label class="text-xs font-medium text-muted-foreground">Destinatario o oggetto</label>
                        <div class="relative w-full">
                            <Search class="absolute left-2.5 top-2 h-4 w-4 text-muted-foreground" />
                            <Input 
                                v-model="search" 
                                placeholder="Cerca..." 
                                class="pl-8 h-8 text-xs bg-white dark:bg-gray-950" 
                            />
                        </div>
                    </div>

                    <!-- Stato -->
                    <div class="space-y-1">
                        <label class="text-xs font-medium text-muted-foreground">Stato</label>
                        <Select :model-value="status" @update:model-value="cambioStato">
                            <SelectTrigger class="w-full h-8 text-xs">
                                <SelectValue>{{ etichettaStato }}</SelectValue>
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="tutti">Tutti gli stati</SelectItem>
                                <SelectItem value="sent">Inviata</SelectItem>
                                <SelectItem value="logged">Non partita (log)</SelectItem>
                                <SelectItem value="failed">Fallita</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <!-- Driver / Mailer -->
                    <div class="space-y-1">
                        <label class="text-xs font-medium text-muted-foreground">Driver</label>
                        <Select :model-value="mailer" @update:model-value="cambioMailer">
                            <SelectTrigger class="w-full h-8 text-xs">
                                <SelectValue>{{ etichettaMailer }}</SelectValue>
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="tutti">Tutti i driver</SelectItem>
                                <SelectItem value="smtp">SMTP</SelectItem>
                                <SelectItem value="log">LOG</SelectItem>
                                <SelectItem value="sendmail">SENDMAIL</SelectItem>
                                <template v-for="m in props.availableMailers" :key="m">
                                    <SelectItem v-if="!['smtp', 'log', 'sendmail'].includes(m.toLowerCase())" :value="m">
                                        {{ m.toUpperCase() }}
                                    </SelectItem>
                                </template>
                            </SelectContent>
                        </Select>
                    </div>

                    <!-- Dal -->
                    <div class="space-y-1">
                        <label class="text-xs font-medium text-muted-foreground">Dal</label>
                        <Popover v-model:open="apertoDal">
                            <PopoverTrigger as-child>
                                <button
                                    type="button"
                                    class="flex h-8 w-full items-center justify-between rounded-md border border-input bg-background px-3 py-1 text-xs ring-offset-background focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 text-start tabular-nums"
                                    :class="!dateFrom && 'text-muted-foreground'"
                                >
                                    <span class="truncate">{{ dateFrom ? formattaDataBreve(dateFrom) : 'gg/mm/aaaa' }}</span>
                                    <CalendarIcon class="h-3.5 w-3.5 opacity-50 shrink-0 ml-1.5" />
                                </button>
                            </PopoverTrigger>
                            <PopoverContent class="w-auto p-0" align="start">
                                <Calendar
                                    :model-value="parseIsoDate(dateFrom)"
                                    locale="it-IT"
                                    initial-focus
                                    @update:model-value="selezionaDal"
                                />
                            </PopoverContent>
                        </Popover>
                    </div>

                    <!-- Al -->
                    <div class="space-y-1">
                        <label class="text-xs font-medium text-muted-foreground">Al</label>
                        <Popover v-model:open="apertoAl">
                            <PopoverTrigger as-child>
                                <button
                                    type="button"
                                    class="flex h-8 w-full items-center justify-between rounded-md border border-input bg-background px-3 py-1 text-xs ring-offset-background focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 text-start tabular-nums"
                                    :class="!dateTo && 'text-muted-foreground'"
                                >
                                    <span class="truncate">{{ dateTo ? formattaDataBreve(dateTo) : 'gg/mm/aaaa' }}</span>
                                    <CalendarIcon class="h-3.5 w-3.5 opacity-50 shrink-0 ml-1.5" />
                                </button>
                            </PopoverTrigger>
                            <PopoverContent class="w-auto p-0" align="start">
                                <Calendar
                                    :model-value="parseIsoDate(dateTo)"
                                    locale="it-IT"
                                    initial-focus
                                    @update:model-value="selezionaAl"
                                />
                            </PopoverContent>
                        </Popover>
                    </div>
                </div>
            </div>

            <!-- Tabella log e paginazione -->
            <div class="w-full shadow ring-1 ring-black/5 md:rounded-lg p-4 bg-white dark:bg-card">
                <section class="w-full space-y-4">
                    <div class="rounded-md border">
                        <Table>
                            <TableHeader>
                                <TableRow class="bg-muted/50">
                                    <TableHead class="w-[60px] text-center">Stato</TableHead>
                                    <TableHead>Destinatario</TableHead>
                                    <TableHead class="hidden md:table-cell">Oggetto</TableHead>
                                    <TableHead class="hidden lg:table-cell">Driver</TableHead>
                                    <TableHead class="text-right whitespace-nowrap">Data Invio</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow v-for="log in mailLogs.data" :key="log.id" class="hover:bg-muted/50">
                                    <TableCell class="text-center p-2">
                                        <div v-if="log.status === 'sent'" class="inline-flex items-center justify-center w-8 h-8 rounded-full text-green-600">
                                            <CheckCircle2 class="w-4 h-4" />
                                        </div>
                                        <!-- Senza un server di posta (trasporto log) il messaggio non è partito: lo dice il registro, come la guida della posta. -->
                                        <div v-else-if="log.status === 'logged'" data-non-partita
                                             title="Nessun server di posta configurato: il messaggio è stato solo registrato, non è partito."
                                             class="inline-flex flex-col items-center justify-center rounded-full text-blue-600">
                                            <FileText class="w-4 h-4" />
                                            <span class="text-[10px] leading-tight">Non partita</span>
                                        </div>
                                        <div v-else class="inline-flex items-center justify-center w-8 h-8 rounded-full text-red-600">
                                            <AlertCircle class="w-4 h-4" />
                                        </div>
                                    </TableCell>
                                    <TableCell class="font-medium text-sm">
                                        {{ log.recipient }}
                                        <!-- Coda 121: il motivo di un invio fallito, sotto il destinatario (si legge anche con «Oggetto» e «Driver» nascosti). -->
                                        <div v-if="log.error_message" data-motivo-invio :title="log.error_message"
                                             class="mt-0.5 max-w-[420px] break-words text-xs font-normal text-red-600 dark:text-red-400">
                                            {{ log.error_message }}
                                        </div>
                                    </TableCell>
                                    <TableCell class="hidden md:table-cell text-sm max-w-[250px]">
                                        <div class="truncate" :title="log.subject">{{ log.subject }}</div>
                                    </TableCell>
                                    <TableCell class="hidden lg:table-cell">
                                        <Badge variant="outline" class="font-mono text-[10px] uppercase">{{ log.mailer }}</Badge>
                                    </TableCell>
                                    <TableCell class="text-right text-xs text-muted-foreground whitespace-nowrap tabular-nums">
                                        {{ formatDate(log.sent_at) }}
                                    </TableCell>
                                </TableRow>
                                
                                <TableRow v-if="mailLogs.data.length === 0">
                                    <TableCell colspan="5" class="h-32 text-center text-muted-foreground">
                                        <div class="flex flex-col items-center gap-2">
                                            <Mail class="w-8 h-8 opacity-20" />
                                            <span>Nessun log email trovato</span>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </div>

                    <!-- Paginazione e righe per pagina -->
                    <div class="flex flex-col sm:flex-row items-center justify-between gap-4 pt-2">
                        <div class="flex items-center gap-3 text-xs text-muted-foreground">
                            <span v-if="mailLogs.links && mailLogs.links.length > 3">
                                Pag. {{ mailLogs.current_page }} di {{ mailLogs.last_page }}
                            </span>
                            <span v-if="mailLogs.total !== undefined" class="tabular-nums">
                                ({{ mailLogs.total }} {{ mailLogs.total === 1 ? 'email' : 'email' }})
                            </span>
                            <div class="flex items-center gap-1.5 ml-2">
                                <span>Righe:</span>
                                <Select :model-value="perPage" @update:model-value="cambioPerPage">
                                    <SelectTrigger class="h-7 w-16 text-xs tabular-nums">
                                        <SelectValue>{{ perPage }}</SelectValue>
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="10">10</SelectItem>
                                        <SelectItem value="25">25</SelectItem>
                                        <SelectItem value="50">50</SelectItem>
                                        <SelectItem value="100">100</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div v-if="mailLogs.links && mailLogs.links.length > 3" class="flex gap-1">
                            <!-- Coda 464: «precedente» e «successiva» si riconoscono dalla posizione, perché l'etichetta arriva nella lingua dell'applicazione
                                 (e con le entità HTML); senza un indirizzo (prima e ultima pagina) non sono un collegamento. -->
                            <Button 
                                v-for="(link, i) in mailLogs.links" :key="i"
                                :as="link.url ? Link : 'button'" 
                                :href="link.url ?? undefined" 
                                :variant="link.active ? 'default' : 'outline'"
                                size="icon"
                                class="h-8 w-8"
                                :disabled="!link.url"
                                v-html="i === 0 ? '<' : i === mailLogs.links.length - 1 ? '>' : link.label"
                            />
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
