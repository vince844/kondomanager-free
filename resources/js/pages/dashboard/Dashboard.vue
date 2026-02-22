<script setup lang="ts">

import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { House, TriangleAlert, CalendarClock, HardDrive, Bell, ArrowRight, AlertCircle } from 'lucide-vue-next';
import SegnalazioniList from '@/components/segnalazioni/SegnalazioniList.vue';
import ComunicazioniList from '@/components/comunicazioni/ComunicazioniList.vue';
import DocumentiList from '@/components/documenti/DocumentiList.vue';
import EventiList from '@/components/eventi/EventiList.vue';
import BuildingsDropdown from '@/components/BuildingsDropdown.vue';
import { usePermission } from "@/composables/permissions";
import { Permission } from '@/enums/Permission';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
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
    eventi: Evento[];
}>();

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
                <div class="flex items-center gap-3">
                    <Link :href="route('admin.inbox')">
                        <Button
                            variant="outline"
                            size="sm"
                            class="h-9 text-[10px] font-bold uppercase tracking-wide gap-2 transition-all"
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
                    <BuildingsDropdown />
                </div>
            </div>

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
                        <p class="text-3xl font-black text-slate-700 dark:text-slate-200">{{ stats.storage.used_formatted }}</p>
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
                        <div>
                            <h2 class="text-xs font-bold uppercase tracking-widest text-slate-500">
                                {{ trans('comunicazioni.header.widget_communications_title') }}
                            </h2>
                            <p class="text-[10px] text-slate-400 mt-0.5">
                                {{ trans('comunicazioni.header.widget_communications_description') }}
                            </p>
                        </div>
                        <Link v-if="hasPermission([Permission.VIEW_COMUNICAZIONI])" :href="route(generateRoute('comunicazioni.index'))">
                            <Button size="sm" class="h-7 text-[10px] font-bold uppercase gap-1.5">
                                {{ trans('comunicazioni.actions.view_all_communications') }}
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
                        <div>
                            <div class="flex items-center gap-2">
                                <h2 class="text-xs font-bold uppercase tracking-widest text-slate-500">
                                    {{ trans('segnalazioni.header.widget_tickets_title') }}
                                </h2>
                                <span v-if="stats.segnalazioni_aperte > 0" class="flex h-1.5 w-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                            </div>
                            <p class="text-[10px] text-slate-400 mt-0.5">
                                {{ trans('segnalazioni.header.widget_tickets_description') }}
                            </p>
                        </div>
                        <Link v-if="hasPermission([Permission.VIEW_SEGNALAZIONI])" :href="route(generateRoute('segnalazioni.index'))">
                            <Button size="sm"  class="h-7 text-[10px] font-bold uppercase gap-1.5">
                                {{ trans('segnalazioni.actions.view_all_tickets') }}
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
                        <div>
                            <h2 class="text-xs font-bold uppercase tracking-widest text-slate-500">Ultimi documenti</h2>
                            <p class="text-[10px] text-slate-400 mt-0.5">Elenco degli ultimi documenti in archivio caricati</p>
                        </div>
                        <Link v-if="hasPermission([Permission.VIEW_ARCHIVE_DOCUMENTS])" :href="route(generateRoute('documenti.index'))">
                            <Button size="sm" class="h-7 text-[10px] font-bold uppercase gap-1.5">
                                Visualizza tutti <ArrowRight class="w-3 h-3" />
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
                        <div>
                            <h2 class="text-xs font-bold uppercase tracking-widest text-slate-500">Prossime scadenze in agenda</h2>
                            <p class="text-[10px] text-slate-400 mt-0.5">Elenco delle scadenze nei prossimi giorni</p>
                        </div>
                        <Link v-if="hasPermission([Permission.VIEW_EVENTS])" :href="route(generateRoute('eventi.index'))">
                            <Button size="sm" class="h-7 text-[10px] font-bold uppercase gap-1.5">
                                Visualizza tutte <ArrowRight class="w-3 h-3" />
                            </Button>
                        </Link>
                    </div>
                    <div class="p-2">
                        <EventiList
                            v-if="hasPermission([Permission.VIEW_EVENTS])"
                            :eventi="eventi"
                        />
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