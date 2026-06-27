<script setup lang="ts">

import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router, usePage, InfiniteScroll } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { House, TriangleAlert, CalendarClock, HardDrive, Bell, ArrowRight, AlertCircle, Mail, X, Check, Loader2, Heart } from 'lucide-vue-next';
import SegnalazioniList from '@/components/segnalazioni/SegnalazioniList.vue';
import ComunicazioniList from '@/components/comunicazioni/ComunicazioniList.vue';
import DocumentiList from '@/components/documenti/DocumentiList.vue';
import EventiList from '@/components/eventi/EventiList.vue';
import BuildingsDropdown from '@/components/BuildingsDropdown.vue';
import { usePermission } from "@/composables/permissions";
import { Permission } from '@/enums/Permission';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, onMounted } from 'vue';
import type { Segnalazione } from '@/types/segnalazioni';
import type { Comunicazione } from '@/types/comunicazioni';
import type { Documento } from '@/types/documenti';
import type { Evento } from '@/types/eventi';
import type { BreadcrumbItem } from '@/types';

const { generateRoute, hasPermission } = usePermission();
const page = usePage();

const inboxCount = computed(() => (page.props as any).inbox_count || 0);

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

interface DashboardStats {
    total_condomini: number;
    segnalazioni_aperte: number;
    scadenze_imminenti: number;
    storage: {
        used_bytes: number;
        used_formatted: string;
        total_files: number;
        total_bytes?: number;
    };
}

const props = defineProps<{
    stats: DashboardStats;
    segnalazioni: Segnalazione[];
    comunicazioni: Comunicazione[];
    documenti: Documento[];
    eventi: {          
        total: number;
        data: Evento[];
    };
}>()

// ─── LOGICA NEWSLETTER AVANZATA ───
const showNewsletterBanner = ref(false);
const isSubscribing = ref(false);
const isSuccess = ref(false);

// ─── LOGICA PATREON BANNER ───
const showPatreonBanner = ref(false);

const userEmail = computed(() => (page.props.auth as any).user?.email);
const isDemo = computed(() => (page.props as any).is_demo || false);

onMounted(() => {
    // Rimuoviamo la vecchia chiave se esiste per evitare conflitti
    localStorage.removeItem('hide_kondomanager_newsletter');

    // Leggi la data di scadenza dal localStorage
    const hideUntil = localStorage.getItem('hide_kondomanager_newsletter_until');
    
    // Controlla se il banner deve restare nascosto
    const shouldHide = hideUntil && Date.now() < parseInt(hideUntil);

    if (!isDemo.value && !shouldHide) {
        showNewsletterBanner.value = true;
    }

    // --- Patreon ---
    const patreonHideUntil = localStorage.getItem('hide_kondomanager_patreon_until');
    const shouldHidePatreon = patreonHideUntil && Date.now() < parseInt(patreonHideUntil);

    if (!isDemo.value && !shouldHidePatreon) {
        showPatreonBanner.value = true;
    }
});

const closeBanner = () => {
    showNewsletterBanner.value = false;
    
    // Nascondi per 30 giorni (Snooze)
    const thirtyDaysInMs = 30 * 24 * 60 * 60 * 1000;
    const hideUntilDate = Date.now() + thirtyDaysInMs;
    localStorage.setItem('hide_kondomanager_newsletter_until', hideUntilDate.toString());
};

const closePatreonBanner = () => {
    showPatreonBanner.value = false;
    
    // Nascondi per 30 giorni (Snooze)
    const thirtyDaysInMs = 30 * 24 * 60 * 60 * 1000;
    const hideUntilDate = Date.now() + thirtyDaysInMs;
    localStorage.setItem('hide_kondomanager_patreon_until', hideUntilDate.toString());
};

const subscribe = () => {
    isSubscribing.value = true;

    router.post(route('admin.newsletter.subscribe'), {}, {
        onSuccess: () => {
            isSuccess.value = true;
            
            // Nascondi per 10 anni (Chiusura definitiva)
            const tenYearsInMs = 10 * 365 * 24 * 60 * 60 * 1000;
            localStorage.setItem('hide_kondomanager_newsletter_until', (Date.now() + tenYearsInMs).toString());
            
            // Attendi 2.5 secondi per far leggere il messaggio e chiudi dolcemente
            setTimeout(() => {
                showNewsletterBanner.value = false;
            }, 2500);
        },
        onFinish: () => {
            isSubscribing.value = false;
        },
        preserveScroll: true
    });
};
// ──────────────────────────────────

const storagePercent = computed(() => {
    if (!props.stats.storage.total_bytes) return null;
    return Math.min(Math.round((props.stats.storage.used_bytes / props.stats.storage.total_bytes) * 100), 100);
});

const navigateToOpenSegnalazioni = () => {
    if (hasPermission([Permission.VIEW_SEGNALAZIONI])) {
        router.visit(route(generateRoute('segnalazioni.index'), { stato: ['aperta', 'in lavorazione'] }));
    }
};

const navigateToUpcomingEventi = () => {
    if (hasPermission([Permission.VIEW_EVENTS])) {
        const today = new Date();
        const sevenDaysLater = new Date();
        sevenDaysLater.setDate(today.getDate() + 7);
        router.visit(route(generateRoute('eventi.index'), {
            date_from: today.toISOString().split('T')[0],
            date_to: sevenDaysLater.toISOString().split('T')[0]
        }));
    }
};

const navigateToCondomini = () => router.visit(route('condomini.index'));
const navigateToDocumenti = () => {
    if (hasPermission([Permission.VIEW_ARCHIVE_DOCUMENTS])) {
        router.visit(route(generateRoute('documenti.index')));
    }
};
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="px-6 py-8 space-y-6">

            <!-- ── TOP BAR ── -->
            <div class="flex flex-col-reverse md:flex-row justify-between items-stretch md:items-center gap-3">
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400">Pannello di controllo</p>
                    <h1 class="text-xl font-black text-slate-800 dark:text-slate-100 tracking-tight">Dashboard comunicazioni</h1>
                </div>
                <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 w-full md:w-auto sm:justify-end">
                    <BuildingsDropdown class="w-full sm:w-auto" />
                    <Link :href="route('admin.inbox')" class="w-full sm:w-auto">
                        <Button
                            variant="outline"
                            size="sm"
                            class="w-full sm:w-auto h-9 text-[10px] font-bold uppercase tracking-wide gap-2 transition-all"
                            :class="{
                                'border-red-200 bg-red-50 text-red-700 hover:bg-red-100 dark:bg-red-900/20 dark:border-red-800 dark:text-red-400': inboxCount > 0,
                            }"
                        >
                            <div class="relative">
                                <Bell class="w-3.5 h-3.5" :class="{ 'animate-pulse': inboxCount > 0 }" />
                                <span v-if="inboxCount > 0" class="absolute -top-1 -right-1 h-1.5 w-1.5 rounded-full bg-red-500"></span>
                            </div>
                            Action inbox
                            <Badge v-if="inboxCount > 0" variant="destructive" class="h-4 px-1 text-[9px] font-black rounded-sm">
                                {{ inboxCount }}
                            </Badge>
                        </Button>
                    </Link>
                </div>
            </div>
            <!-- ── BANNERS CONTAINER ── -->
            <div class="flex flex-col gap-3">
                <!-- ── NEWSLETTER BANNER ── -->
            <div v-if="showNewsletterBanner" class="relative overflow-hidden rounded-xl bg-gradient-to-r from-blue-700 to-indigo-800 p-3 pr-12 md:pr-14 shadow-sm flex flex-col xl:flex-row items-start xl:items-center justify-between gap-5 transition-all">
                <div class="absolute -right-10 -top-10 opacity-10 pointer-events-none">
                    <Mail class="w-40 h-40" />
                </div>
                
                <div class="flex items-center gap-4 relative z-10">
                    <div class="bg-white/20 p-2.5 rounded-lg shrink-0">
                        <Mail class="w-6 h-6 text-white" />
                    </div>
                    <div>
                        <h3 class="font-bold text-white text-sm">Resta un passo avanti e tieniti aggiornato</h3>
                        <p class="text-blue-100 text-xs mt-0.5 max-w-2xl">
                            Iscriviti alla newsletter per ricevere in anteprima gli aggiornamenti tecnici e l'accesso ai nuovi moduli e tutte le novità sullo sviluppo futuro di Kondomanager.
                        </p>
                        <p class="text-blue-200/80 text-[10px] mt-2 font-medium">
                            Le comunicazioni verranno inviate all'indirizzo: <strong class="text-white">{{ userEmail }}</strong>
                        </p>
                    </div>
                </div>
                
                <div class="flex flex-col items-start xl:items-end gap-2 relative z-10 w-full xl:w-auto shrink-0">
                    <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                        
                        <button 
                            @click="subscribe" 
                            :disabled="isSubscribing || isSuccess"
                            class="px-4 py-2 rounded-md text-xs h-8 whitespace-nowrap font-bold transition-all flex items-center justify-center min-w-[140px]"
                            :class="isSuccess ? 'bg-emerald-400 text-emerald-950' : 'bg-white text-blue-800 hover:bg-blue-50 disabled:opacity-50'"
                        >
                            <span v-if="isSubscribing" class="animate-pulse">Attivazione...</span>
                            <span v-else-if="isSuccess" class="flex items-center gap-1.5">
                                <Check class="w-3.5 h-3.5" /> Fatto!
                            </span>
                            <span v-else>Attiva notifiche</span>
                        </button>

                    </div>
                    <p class="text-[9px] text-white text-left xl:text-right max-w-xs leading-tight">
                        Cliccando su "Attiva Notifiche", accetti di ricevere aggiornamenti. <br>Puoi annullare l'iscrizione in qualsiasi momento.
                    </p>
                </div>

                <button @click="closeBanner" class="absolute top-2 right-2 p-1.5 text-white/50 hover:text-white transition-colors rounded-md hover:bg-white/10 z-20">
                    <X class="w-4 h-4" />
                </button>
            </div>
            <!-- ───────────────────────── -->

            <!-- ── PATREON BANNER ── -->
            <div v-if="showPatreonBanner" class="relative overflow-hidden rounded-xl bg-gradient-to-r from-orange-500/10 via-red-500/10 to-rose-500/10 border border-orange-200/50 dark:border-orange-500/20 p-3 pr-12 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 transition-all hover:shadow-sm">
                
                <div class="absolute -right-12 -top-12 opacity-[0.04] dark:opacity-10 text-orange-600 dark:text-orange-400 pointer-events-none">
                    <Heart class="w-52 h-52" />
                </div>

                <div class="flex items-center gap-3 relative z-10">
                    <div class="bg-orange-500/20 p-2 rounded-lg shrink-0">
                        <Heart class="w-6 h-6 text-orange-600 dark:text-orange-400" />
                    </div>
                    <div>
                        <h3 class="font-bold text-slate-800 dark:text-slate-200 text-sm">Unisciti a chi ha deciso di contribuire!</h3>
                        <div class="text-slate-600 dark:text-slate-400 text-xs mt-0.5 space-y-1">
                            <p>Kondomanager cresce grazie alla sua community. Sostieni il software libero e open source.</p>
                            <p class="text-[10px] text-slate-500 italic">
                                Nota: se preferisci fare una donazione "una tantum" invece dell'abbonamento mensile, puoi attivare un abbonamento e cancellarlo subito dopo l'iscrizione.
                            </p>
                        </div>
                    </div>
                </div>
                <a href="https://www.patreon.com/KondoManager" target="_blank" class="shrink-0 w-full sm:w-auto relative z-10">
                    <Button size="sm" class="h-8 text-xs bg-orange-600 hover:bg-orange-700 text-white font-bold gap-1.5 w-full sm:w-auto border-none shadow-sm">
                        Supportaci su Patreon <ArrowRight class="w-3.5 h-3.5" />
                    </Button>
                </a>
                
                <button @click="closePatreonBanner" class="absolute top-2 right-2 p-1.5 text-slate-400 hover:text-slate-600 dark:text-slate-500 dark:hover:text-slate-300 transition-colors rounded-md hover:bg-slate-200/50 dark:hover:bg-slate-700/50 z-20">
                    <X class="w-4 h-4" />
                </button>
            </div>
            </div>
            <!-- ───────────────────────── -->

            <!-- ── KPI CARDS ── -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">

                <div
                    class="relative flex flex-col justify-between overflow-hidden rounded-xl border border-sidebar-border/70 bg-white dark:bg-slate-900 shadow-sm transition-all hover:shadow-md cursor-pointer group"
                    @click="navigateToCondomini"
                >
                    <div class="absolute -right-5 -top-5 text-slate-100 dark:text-slate-800 pointer-events-none">
                        <House class="h-24 w-24" />
                    </div>
                    <div class="p-5 relative z-10">
                        <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-3">Condomini registrati</p>
                        <p class="text-3xl font-black text-slate-700 dark:text-slate-200">{{ stats.total_condomini }}</p>
                    </div>
                    <div class="border-t border-slate-100 dark:border-slate-800 px-4 py-2.5 bg-slate-50/50 dark:bg-slate-800/50 flex items-center justify-between">
                        <span class="text-[10px] text-slate-400 font-medium">Tutti i fabbricati</span>
                        <ArrowRight class="w-3 h-3 text-slate-300 group-hover:text-slate-500 group-hover:translate-x-0.5 transition-all" />
                    </div>
                </div>

                <div
                    class="relative flex flex-col justify-between overflow-hidden rounded-xl border bg-white dark:bg-slate-900 shadow-sm transition-all hover:shadow-md cursor-pointer group"
                    :class="stats.segnalazioni_aperte > 0
                        ? 'border-amber-200 dark:border-amber-900/40'
                        : 'border-sidebar-border/70'"
                    @click="navigateToOpenSegnalazioni"
                >
                    <div class="absolute -right-5 -top-5 pointer-events-none transition-colors" :class="stats.segnalazioni_aperte > 0 ? 'text-amber-100 dark:text-amber-900/30' : 'text-slate-100 dark:text-slate-800'">
                        <TriangleAlert class="h-24 w-24" />
                    </div>
                    <div class="p-5 relative z-10">
                        <div class="flex items-center gap-2 mb-3">
                            <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400">Segnalazioni aperte</p>
                            <span v-if="stats.segnalazioni_aperte > 0" class="flex h-1.5 w-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                        </div>
                        <p class="text-3xl font-black" :class="stats.segnalazioni_aperte > 0 ? 'text-amber-600 dark:text-amber-500' : 'text-slate-700 dark:text-slate-200'">
                            {{ stats.segnalazioni_aperte }}
                        </p>
                    </div>
                    <div class="border-t border-t px-4 py-2.5 flex items-center justify-between" :class="stats.segnalazioni_aperte > 0 ? 'border-amber-100 dark:border-amber-900/30 bg-amber-50/50 dark:bg-amber-900/10' : 'border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/50'">
                        <span class="text-[10px] font-medium" :class="stats.segnalazioni_aperte > 0 ? 'text-amber-600/80' : 'text-slate-400'">
                            {{ stats.segnalazioni_aperte > 0 ? 'Azione richiesta' : 'Nessuna segnalazione' }}
                        </span>
                        <ArrowRight class="w-3 h-3 text-slate-300 group-hover:text-slate-500 group-hover:translate-x-0.5 transition-all" />
                    </div>
                </div>

                <div
                    class="relative flex flex-col justify-between overflow-hidden rounded-xl border border-sidebar-border/70 bg-white dark:bg-slate-900 shadow-sm transition-all hover:shadow-md cursor-pointer group"
                    @click="navigateToUpcomingEventi"
                >
                    <div class="absolute -right-5 -top-5 text-slate-100 dark:text-slate-800 pointer-events-none">
                        <CalendarClock class="h-24 w-24" />
                    </div>
                    <div class="p-5 relative z-10">
                        <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-3">Scadenze imminenti</p>
                        <p class="text-3xl font-black text-slate-700 dark:text-slate-200">{{ stats.scadenze_imminenti }}</p>
                    </div>
                    <div class="border-t border-slate-100 dark:border-slate-800 px-4 py-2.5 bg-slate-50/50 dark:bg-slate-800/50 flex items-center justify-between">
                        <span class="text-[10px] text-slate-400 font-medium">Prossimi 7 giorni</span>
                        <ArrowRight class="w-3 h-3 text-slate-300 group-hover:text-slate-500 group-hover:translate-x-0.5 transition-all" />
                    </div>
                </div>

                <div
                    class="relative flex flex-col justify-between overflow-hidden rounded-xl border border-sidebar-border/70 bg-white dark:bg-slate-900 shadow-sm transition-all hover:shadow-md cursor-pointer group"
                    @click="navigateToDocumenti"
                >
                    <div class="absolute -right-5 -top-5 text-slate-100 dark:text-slate-800 pointer-events-none">
                        <HardDrive class="h-24 w-24" />
                    </div>
                    <div class="p-5 relative z-10 space-y-2">
                        <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400">Archiviazione</p>
                         <p class="text-3xl font-black text-slate-700 dark:text-slate-200">
                            {{ stats.storage.used_formatted.split(' ')[0] }}
                            <span class="text-lg font-bold text-slate-400 dark:text-slate-500 ml-0.5">{{ stats.storage.used_formatted.split(' ')[1] }}</span>
                        </p>
                        <div v-if="storagePercent !== null">
                            <div class="flex justify-between items-center mb-1">
                                <span class="text-[9px] text-slate-400 font-semibold uppercase">Utilizzo</span>
                                <span class="text-[10px] font-bold tabular-nums text-slate-500">{{ storagePercent }}%</span>
                            </div>
                            <div class="h-1.5 w-full bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
                                <div
                                    class="h-full rounded-full transition-all duration-700"
                                    :class="{
                                        'bg-red-500': storagePercent > 85,
                                        'bg-amber-500': storagePercent > 65 && storagePercent <= 85,
                                        'bg-emerald-500': storagePercent <= 65
                                    }"
                                    :style="{ width: storagePercent + '%' }"
                                />
                            </div>
                        </div>
                        <p class="text-[10px] text-slate-400">{{ stats.storage.total_files }} file archiviati</p>
                    </div>
                    <div class="border-t border-slate-100 dark:border-slate-800 px-4 py-2.5 bg-slate-50/50 dark:bg-slate-800/50 flex items-center justify-between">
                        <span class="text-[10px] text-slate-400 font-medium">Archivio documenti</span>
                        <ArrowRight class="w-3 h-3 text-slate-300 group-hover:text-slate-500 group-hover:translate-x-0.5 transition-all" />
                    </div>
                </div>

            </div>

            <!-- ── WIDGET GRID ── -->
            <div class="grid gap-4 md:grid-cols-2">

                <!-- Comunicazioni -->
                <div class="rounded-xl border border-sidebar-border/70 bg-white dark:bg-slate-900 shadow-sm overflow-hidden">
                    <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/50">
                        <div class="min-w-0 flex-1 mr-3">
                            <h2 class="text-xs font-bold uppercase tracking-widest text-slate-500 truncate">
                                {{ trans('comunicazioni.header.widget_communications_title') }}
                            </h2>
                            <p class="text-[10px] text-slate-400 mt-0.5 truncate">
                                {{ trans('comunicazioni.header.widget_communications_description') }}
                            </p>
                        </div>
                        <Link v-if="hasPermission([Permission.VIEW_COMUNICAZIONI])" :href="route(generateRoute('comunicazioni.index'))" class="shrink-0">
                            <Button size="sm" class="h-7 text-[10px] font-bold uppercase gap-1.5">
                                <span class="hidden sm:inline">{{ trans('comunicazioni.actions.view_all_communications') }}</span>
                                <ArrowRight class="w-3 h-3" />
                            </Button>
                        </Link>
                    </div>
                    <div class="p-2">
                        <ComunicazioniList
                            v-if="hasPermission([Permission.VIEW_COMUNICAZIONI])"
                            :comunicazioni="comunicazioni"
                            :routeName="'comunicazioni.show'"
                        />
                        <div v-else class="flex items-center gap-2 px-4 py-6 text-xs text-slate-400">
                            <AlertCircle class="w-4 h-4 shrink-0" />
                            {{ trans('comunicazioni.dialogs.no_view_permission') }}
                        </div>
                    </div>
                </div>

                <!-- Segnalazioni -->
                <div
                    class="rounded-xl border bg-white dark:bg-slate-900 shadow-sm overflow-hidden"
                    :class="stats.segnalazioni_aperte > 0 ? 'border-amber-200 dark:border-amber-900/40' : 'border-sidebar-border/70'"
                >
                    <div
                        class="flex items-center justify-between px-5 py-4 border-b bg-slate-50/50 dark:bg-slate-800/50"
                        :class="stats.segnalazioni_aperte > 0 ? 'border-amber-100 dark:border-amber-900/30' : 'border-sidebar-border/70'"
                    >
                        <div class="min-w-0 flex-1 mr-3">
                            <div class="flex items-center gap-2">
                                <h2 class="text-xs font-bold uppercase tracking-widest text-slate-500 truncate">
                                    {{ trans('segnalazioni.header.widget_tickets_title') }}
                                </h2>
                                <span v-if="stats.segnalazioni_aperte > 0" class="flex h-1.5 w-1.5 rounded-full bg-amber-500 animate-pulse shrink-0"></span>
                            </div>
                            <p class="text-[10px] text-slate-400 mt-0.5 truncate">
                                {{ trans('segnalazioni.header.widget_tickets_description') }}
                            </p>
                        </div>
                        <Link v-if="hasPermission([Permission.VIEW_SEGNALAZIONI])" :href="route(generateRoute('segnalazioni.index'))" class="shrink-0">
                            <Button size="sm" class="h-7 text-[10px] font-bold uppercase gap-1.5">
                                <span class="hidden sm:inline">{{ trans('segnalazioni.actions.view_all_tickets') }}</span>
                                <ArrowRight class="w-3 h-3" />
                            </Button>
                        </Link>
                    </div>
                    <div class="p-2">
                        <SegnalazioniList
                            v-if="hasPermission([Permission.VIEW_SEGNALAZIONI])"
                            :segnalazioni="segnalazioni"
                            :routeName="'segnalazioni.show'"
                        />
                        <div v-else class="flex items-center gap-2 px-4 py-6 text-xs text-slate-400">
                            <AlertCircle class="w-4 h-4 shrink-0" />
                            {{ trans('segnalazioni.dialogs.no_view_permission') }}
                        </div>
                    </div>
                </div>

                <!-- Documenti -->
                <div class="rounded-xl border border-sidebar-border/70 bg-white dark:bg-slate-900 shadow-sm overflow-hidden">
                    <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/50">
                        <div class="min-w-0 flex-1 mr-3">
                            <h2 class="text-xs font-bold uppercase tracking-widest text-slate-500 truncate">Ultimi documenti</h2>
                            <p class="text-[10px] text-slate-400 mt-0.5 truncate">Elenco degli ultimi documenti in archivio caricati</p>
                        </div>
                        <Link v-if="hasPermission([Permission.VIEW_ARCHIVE_DOCUMENTS])" :href="route(generateRoute('documenti.index'))" class="shrink-0">
                            <Button size="sm" class="h-7 text-[10px] font-bold uppercase gap-1.5">
                                <span class="hidden sm:inline">Visualizza tutti</span>
                                <ArrowRight class="w-3 h-3" />
                            </Button>
                        </Link>
                    </div>
                    <div class="p-2">
                        <DocumentiList
                            v-if="hasPermission([Permission.VIEW_ARCHIVE_DOCUMENTS])"
                            :documenti="documenti"
                        />
                        <div v-else class="flex items-center gap-2 px-4 py-6 text-xs text-slate-400">
                            <AlertCircle class="w-4 h-4 shrink-0" />
                            Non hai permessi sufficienti per visualizzare documenti in archivio!
                        </div>
                    </div>
                </div>

                <!-- Scadenze agenda -->
                <div class="rounded-xl border border-sidebar-border/70 bg-white dark:bg-slate-900 shadow-sm overflow-hidden">
                    <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/50">
                        <div class="min-w-0 flex-1 mr-3">
                            <h2 class="text-xs font-bold uppercase tracking-widest text-slate-500 truncate">Prossime scadenze in agenda</h2>
                            <p class="text-[10px] text-slate-400 mt-0.5 truncate">Elenco delle scadenze nei prossimi giorni</p>
                        </div>
                        <Link v-if="hasPermission([Permission.VIEW_EVENTS])" :href="route(generateRoute('eventi.index'))" class="shrink-0">
                            <Button size="sm" class="h-7 text-[10px] font-bold uppercase gap-1.5">
                                <span class="hidden sm:inline">Visualizza tutte</span>
                                <ArrowRight class="w-3 h-3" />
                            </Button>
                        </Link>
                    </div>
                    <div class="p-2 max-h-[420px] overflow-y-auto custom-scrollbar">
                        <template v-if="hasPermission([Permission.VIEW_EVENTS])">
                            <InfiniteScroll data="eventi" preserve-url>
                                <EventiList :eventi="eventi.data" />
                                <template #loading>
                                    <div class="py-6 flex items-center justify-center text-slate-400">
                                        <Loader2 class="w-5 h-5 animate-spin" />
                                    </div>
                                </template>
                            </InfiniteScroll>
                        </template>
                        <div v-else class="flex items-center gap-2 px-4 py-6 text-xs text-slate-400">
                            <AlertCircle class="w-4 h-4 shrink-0" />
                            Non hai permessi sufficienti per visualizzare le scadenze in agenda!
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </AppLayout>
</template>