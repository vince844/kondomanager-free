<script setup lang="ts">

import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
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

const props = defineProps<{ 
  segnalazioni: Segnalazione[]; 
  comunicazioni: Comunicazione[]; 
  documenti: Documento[]; 
  eventi: Evento[]; 
}>()

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

            <div class="grid auto-rows-min gap-4 md:grid-cols-3">
                <div class="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <PlaceholderPattern />
                </div>
                <div class="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <PlaceholderPattern />
                </div>
                <div class="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <PlaceholderPattern />
                </div>
            </div>

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
                                Elenco delle ultime segnalazioni guasto
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
                                Elenco degli ultimi documenti in archivio
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
