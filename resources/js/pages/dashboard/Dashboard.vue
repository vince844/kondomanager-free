<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { House, TriangleAlert, CalendarClock, HardDrive } from 'lucide-vue-next';
import SegnalazioniList from '@/components/segnalazioni/SegnalazioniList.vue';
import ComunicazioniList from '@/components/comunicazioni/ComunicazioniList.vue';
import DocumentiList from '@/components/documenti/DocumentiList.vue';
import EventiList from '@/components/eventi/EventiList.vue';
import BuildingsDropdown from '@/components/BuildingsDropdown.vue';
import { usePermission } from "@/composables/permissions";
import { Permission } from '@/enums/Permission';
import type { Segnalazione } from '@/types/segnalazioni';
import type { Comunicazione } from '@/types/comunicazioni';
import type { Documento } from '@/types/documenti';
import type { Evento } from '@/types/eventi';
import type { BreadcrumbItem } from '@/types';

const { generateRoute, hasPermission } = usePermission();

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

// Navigate to segnalazioni with "aperta" and "in lavorazione" filter
const navigateToOpenSegnalazioni = () => {
    if (hasPermission([Permission.VIEW_SEGNALAZIONI])) {
        router.visit(route(generateRoute('segnalazioni.index'), {
            stato: ['aperta', 'in lavorazione']
        }));
    }
};

// Navigate to eventi with date filter for next 7 days
const navigateToUpcomingEventi = () => {
    if (hasPermission([Permission.VIEW_EVENTS])) {

        const today = new Date();
        const sevenDaysLater = new Date();

        sevenDaysLater.setDate(today.getDate() + 7);
        
        const dateFrom = today.toISOString().split('T')[0];
        const dateTo = sevenDaysLater.toISOString().split('T')[0];
        
        router.visit(route(generateRoute('eventi.index'), {
            date_from: dateFrom,
            date_to: dateTo
        }));

    }
};

// Navigate to condomini list
const navigateToCondomini = () => {
    router.visit(route(('condomini.index')));
};

// Navigate to documenti list
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
            <div class="flex justify-end mb-2 md:justify-end">
                <div class="w-full md:w-auto">
                    <BuildingsDropdown class="w-full md:w-auto" />
                </div>
            </div>

            <!-- Statistics Cards - 4 cards (clickable) -->
            <div class="grid auto-rows-min gap-4 md:grid-cols-2 lg:grid-cols-4">
                <!-- Condomini Card (clickable) -->
                <Card 
                    class="cursor-pointer hover:bg-accent transition-colors"
                    @click="navigateToCondomini"
                >
                    <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle class="text-md font-semibold">
                            Condomini registrati
                        </CardTitle>
                        <House class="w-8 h-8 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">{{ stats.total_condomini }}</div>
                    </CardContent>
                </Card>

                <!-- Segnalazioni Aperte Card (clickable with filter) -->
                <Card 
                    class="cursor-pointer hover:bg-accent transition-colors"
                    :class="{ 'opacity-50 cursor-not-allowed': !hasPermission([Permission.VIEW_SEGNALAZIONI]) }"
                    @click="navigateToOpenSegnalazioni"
                >
                    <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle class="text-md font-semibold">
                            Segnalazioni aperte
                        </CardTitle>
                        <TriangleAlert class="w-8 h-8 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">{{ stats.segnalazioni_aperte }}</div>
                    </CardContent>
                </Card>

                <!-- Scadenze Imminenti Card (clickable with date filter) -->
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

                <!-- Storage Card (clickable) -->
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

            <!-- Rest of the dashboard content -->
            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-2">
                <Card class="w-full">
                    <CardHeader class="p-3 ml-3">
                        <div class="flex items-center justify-between">
                            <div>
                                <CardTitle class="text-lg">
                                    Ultime comunicazioni
                                </CardTitle>
                                <CardDescription>
                                    Elenco delle ultime comunicazioni in bacheca
                                </CardDescription>
                            </div>

                            <Link
                                :href="route(generateRoute('comunicazioni.index'))"
                                v-if="hasPermission([Permission.VIEW_COMUNICAZIONI])"
                                class="inline-block px-2 py-1 font-bold text-white bg-gray-800 rounded hover:bg-gray-700 text-xs transition-colors"
                            >
                                Visualizza tutte
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
                            <span class="font-medium">Non hai permessi sufficienti per visualizzare le comunicazioni!</span>
                        </div>
                    </CardContent>
                </Card>

                <Card class="w-full">
                    <CardHeader class="p-3 ml-3">
                        <div class="flex items-center justify-between">
                            <div>
                                <CardTitle class="text-lg">
                                    Ultime segnalazioni
                                </CardTitle>
                                <CardDescription>
                                    Elenco delle ultime segnalazioni guasto inviate
                                </CardDescription>
                            </div>

                            <Link
                                :href="route(generateRoute('segnalazioni.index'))"
                                v-if="hasPermission([Permission.VIEW_SEGNALAZIONI])"
                                class="inline-block px-2 py-1 font-bold text-white bg-gray-800 rounded hover:bg-gray-700 text-xs transition-colors"
                            >
                                Visualizza tutte
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
                            <span class="font-medium">Non hai permessi sufficienti per visualizzare le segnalazioni!</span>
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