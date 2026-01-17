<script setup lang="ts">
    
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button'; // Import Button
import { Badge } from '@/components/ui/badge';   // Import Badge
import { House, TriangleAlert, CalendarClock, HardDrive, Bell } from 'lucide-vue-next'; // Import Bell
import SegnalazioniList from '@/components/segnalazioni/SegnalazioniList.vue';
import ComunicazioniList from '@/components/comunicazioni/ComunicazioniList.vue';
import DocumentiList from '@/components/documenti/DocumentiList.vue';
import EventiList from '@/components/eventi/EventiList.vue';
import BuildingsDropdown from '@/components/BuildingsDropdown.vue';
import { usePermission } from "@/composables/permissions";
import { Permission } from '@/enums/Permission';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue'; // Import Computed
import type { Segnalazione } from '@/types/segnalazioni';
import type { Comunicazione } from '@/types/comunicazioni';
import type { Documento } from '@/types/documenti';
import type { Evento } from '@/types/eventi';
import type { BreadcrumbItem } from '@/types';

const { generateRoute, hasPermission } = usePermission();
const page = usePage();

// Recuperiamo il conteggio inbox globale
const inboxCount = computed(() => (page.props as any).inbox_count || 0);

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];

interface DashboardStats {
    total_condomini: number;
    segnalazioni_aperte: number;
    scadenze_imminenti: number;
    storage: {
        used_bytes: number;
        used_formatted: string;
        total_files: number;
    };
}

const props = defineProps<{ 
    stats: DashboardStats;
    segnalazioni: Segnalazione[]; 
    comunicazioni: Comunicazione[]; 
    documenti: Documento[]; 
    eventi: Evento[]; 
}>();

// ... (Le tue funzioni di navigazione rimangono uguali) ...
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

const navigateToCondomini = () => router.visit(route(('condomini.index')));

const navigateToDocumenti = () => {
    if (hasPermission([Permission.VIEW_ARCHIVE_DOCUMENTS])) {
        router.visit(route(generateRoute('documenti.index')));
    }
};
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
            
            <div class="flex flex-col-reverse md:flex-row justify-end items-stretch md:items-center gap-3 mb-2">
                
                <Link :href="route('admin.inbox')" class="w-full md:w-auto">
                    <Button 
                        variant="outline" 
                        class="w-full md:w-auto flex items-center gap-2 justify-center transition-all"
                        :class="{
                            'border-red-200 bg-red-50 text-red-700 hover:bg-red-100 hover:text-red-800 dark:bg-red-900/20 dark:border-red-800 dark:text-red-400': inboxCount > 0,
                            'hover:bg-slate-100': inboxCount === 0
                        }"
                    >
                        <div class="relative">
                            <Bell class="w-4 h-4" :class="{'animate-pulse': inboxCount > 0}" />
                            <span v-if="inboxCount > 0" class="absolute -top-1 -right-1 h-2 w-2 rounded-full bg-red-600"></span>
                        </div>
                        
                        <span class="font-medium">Action inbox</span>
                        
                        <Badge 
                            v-if="inboxCount > 0" 
                            variant="destructive" 
                            class="ml-1 h-5 px-1.5 min-w-[1.25rem]"
                        >
                            {{ inboxCount }}
                        </Badge>
                    </Button>
                </Link>

                <div class="w-full md:w-auto">
                    <BuildingsDropdown class="w-full md:w-auto" />
                </div>
            </div>

            <div class="grid auto-rows-min gap-4 md:grid-cols-2 lg:grid-cols-4">
                <Card 
                    class="cursor-pointer hover:bg-accent transition-colors"
                    @click="navigateToCondomini"
                >
                    <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle class="text-md font-semibold">
                            {{ trans('dashboard.condomini_registrati') }}
                        </CardTitle>
                        <House class="w-8 h-8 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">{{ stats.total_condomini }}</div>
                    </CardContent>
                </Card>

                <Card 
                    class="cursor-pointer hover:bg-accent transition-colors"
                    :class="{ 'opacity-50 cursor-not-allowed': !hasPermission([Permission.VIEW_SEGNALAZIONI]) }"
                    @click="navigateToOpenSegnalazioni"
                >
                    <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle class="text-md font-semibold">
                           {{ trans('segnalazioni.stats.open_tickets') }}
                        </CardTitle>
                        <TriangleAlert class="w-8 h-8 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">{{ stats.segnalazioni_aperte }}</div>
                    </CardContent>
                </Card>

                <Card 
                    class="cursor-pointer hover:bg-accent transition-colors"
                    :class="{ 'opacity-50 cursor-not-allowed': !hasPermission([Permission.VIEW_EVENTS]) }"
                    @click="navigateToUpcomingEventi"
                >
                    <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle class="text-md font-semibold">
                            Scadenze imminenti
                        </CardTitle>
                        <CalendarClock class="w-8 h-8 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">{{ stats.scadenze_imminenti }}</div>
                    </CardContent>
                </Card>

                <Card 
                    class="cursor-pointer hover:bg-accent transition-colors"
                    :class="{ 'opacity-50 cursor-not-allowed': !hasPermission([Permission.VIEW_ARCHIVE_DOCUMENTS]) }"
                    @click="navigateToDocumenti"
                >
                    <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle class="text-md font-semibold">
                            Spazio archiviazione
                        </CardTitle>
                        <HardDrive class="w-8 h-8 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">{{ stats.storage.used_formatted }}</div>
                    </CardContent>
                </Card>
            </div>

            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-2">
                <Card class="w-full">
                    <CardHeader class="p-3 ml-3">
                        <div class="flex items-center justify-between">
                            <div>
                                <CardTitle class="text-lg">
                                    {{trans('comunicazioni.header.widget_communications_title')}}
                                </CardTitle>
                                <CardDescription>
                                     {{trans('comunicazioni.header.widget_communications_description')}}
                                </CardDescription>
                            </div>

                            <Link
                                :href="route(generateRoute('comunicazioni.index'))"
                                v-if="hasPermission([Permission.VIEW_COMUNICAZIONI])"
                                class="inline-block px-2 py-1 font-bold text-white bg-gray-800 rounded hover:bg-gray-700 text-xs transition-colors"
                            >
                                {{trans('comunicazioni.actions.view_all_communications')}}
                            </Link>
                        </div>
                    </CardHeader>
                    <CardContent v-if="hasPermission([Permission.VIEW_COMUNICAZIONI])">
                        <ComunicazioniList 
                            :comunicazioni="comunicazioni" 
                            :routeName="'comunicazioni.show'"
                        />
                    </CardContent>

                    <CardContent v-else>
                        <div class="p-4 mt-1 text-sm text-gray-800 rounded-lg bg-gray-50 dark:bg-gray-800 dark:text-gray-300" role="alert">
                            <span class="font-medium">{{ trans('comunicazioni.dialogs.no_view_permission') }}</span>
                        </div>
                    </CardContent>
                </Card>

                <Card class="w-full">
                    <CardHeader class="p-3 ml-3">
                        <div class="flex items-center justify-between">
                            <div>
                                <CardTitle class="text-lg">
                                    {{trans('segnalazioni.header.widget_tickets_title')}}
                                </CardTitle>
                                <CardDescription>
                                    {{trans('segnalazioni.header.widget_tickets_description')}}
                                </CardDescription>
                            </div>

                            <Link
                                :href="route(generateRoute('segnalazioni.index'))"
                                v-if="hasPermission([Permission.VIEW_SEGNALAZIONI])"
                                class="inline-block px-2 py-1 font-bold text-white bg-gray-800 rounded hover:bg-gray-700 text-xs transition-colors"
                            >
                                {{trans('segnalazioni.actions.view_all_tickets')}}
                            </Link>
                        </div>
                    </CardHeader>

                    <CardContent v-if="hasPermission([Permission.VIEW_SEGNALAZIONI])">
                        <SegnalazioniList 
                            :segnalazioni="segnalazioni" 
                            :routeName="'segnalazioni.show'"
                        />
                    </CardContent>

                    <CardContent v-else>
                        <div class="p-4 mt-1 text-sm text-gray-800 rounded-lg bg-gray-50 dark:bg-gray-800 dark:text-gray-300" role="alert">
                            <span class="font-medium">{{ trans('segnalazioni.dialogs.no_view_permission') }}</span>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-2">
                <Card class="w-full">
                    <CardHeader class="p-3 ml-3">
                        <div class="flex items-center justify-between">
                            <div>
                                <CardTitle class="text-lg">Ultimi documenti</CardTitle>
                                <CardDescription>
                                    Elenco degli ultimi documenti in archivio caricati
                                </CardDescription>
                            </div>

                            <Link
                                :href="route(generateRoute('documenti.index'))"
                                v-if="hasPermission([Permission.VIEW_ARCHIVE_DOCUMENTS])"
                                class="inline-block px-2 py-1 font-bold text-white bg-gray-800 rounded hover:bg-gray-700 text-xs transition-colors"
                            >
                                Visualizza tutti
                            </Link>
                        </div>
                    </CardHeader>
                    <CardContent v-if="hasPermission([Permission.VIEW_ARCHIVE_DOCUMENTS])">
                        <DocumentiList 
                            :documenti="documenti" 
                        />
                    </CardContent>

                    <CardContent v-else>
                        <div class="p-4 mt-1 text-sm text-gray-800 rounded-lg bg-gray-50 dark:bg-gray-800 dark:text-gray-300" role="alert">
                            <span class="font-medium">Non hai permessi sufficienti per visualizzare documenti in archivio!</span>
                        </div>
                    </CardContent>
                </Card>

                <Card class="w-full">
                    <CardHeader class="p-3 ml-3">
                        <div class="flex items-center justify-between">
                            <div>
                                <CardTitle class="text-lg">Prossime scadenze in agenda</CardTitle>
                                <CardDescription>
                                    Elenco delle scadenze in agenda nei prossimi giorni
                                </CardDescription>
                            </div>

                            <Link
                                :href="route(generateRoute('eventi.index'))"
                                v-if="hasPermission([Permission.VIEW_EVENTS])"
                                class="inline-block px-2 py-1 font-bold text-white bg-gray-800 rounded hover:bg-gray-700 text-xs transition-colors"
                            >
                                Visualizza tutte
                            </Link>
                        </div>
                    </CardHeader>
                    <CardContent v-if="hasPermission([Permission.VIEW_EVENTS])">
                        <EventiList
                            :eventi="eventi" 
                        />
                    </CardContent>

                    <CardContent v-else>
                        <div class="p-4 mt-1 text-sm text-gray-800 rounded-lg bg-gray-50 dark:bg-gray-800 dark:text-gray-300" role="alert">
                            <span class="font-medium">Non hai permessi sufficienti per visualizzare le scadenze in agenda!</span>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>