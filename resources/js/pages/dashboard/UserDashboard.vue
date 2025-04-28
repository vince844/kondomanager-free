<script setup lang="ts">

import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { type BreadcrumbItem } from '@/types';
import type { Segnalazione } from '@/types/segnalazioni';
import type { Comunicazione } from '@/types/comunicazioni';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import { CircleArrowDown, CircleArrowRight, CircleArrowUp, CircleAlert } from 'lucide-vue-next';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import SegnalazioniList from '@/components/segnalazioni/SegnalazioniList.vue';
import ComunicazioniList from '@/components/comunicazioni/ComunicazioniList.vue';
import { usePermission } from "@/composables/permissions";

const { hasPermission } = usePermission();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];

const props = defineProps<{ 
  segnalazioni: Segnalazione[]; 
  comunicazioni: Comunicazione[];
}>()

const priorityIcons = {
  bassa: CircleArrowDown,
  media: CircleArrowRight,
  alta: CircleArrowUp,
  urgente: CircleAlert,
}

</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
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
                    <CardHeader class="p-3">
                        <div class="flex items-center justify-between">
                            <div>
                                <CardTitle class="text-lg">Ultime comunicazioni</CardTitle>
                                <CardDescription>
                                Elenco delle ultime comunicazioni in bacheca
                                </CardDescription>
                            </div>

                            <Link
                                :href="route('user.comunicazioni.index')"
                                v-if="hasPermission(['Visualizza comunicazioni'])"
                                class="inline-block px-2 py-1 font-bold text-white bg-gray-800 rounded hover:bg-gray-700 text-xs transition-colors"
                            >
                                Visualizza tutte
                            </Link>
                            </div>
                    </CardHeader>
                    <CardContent v-if="hasPermission(['Visualizza comunicazioni'])">
                        <ComunicazioniList 
                                :comunicazioni="comunicazioni" 
                                :priorityIcons="priorityIcons" 
                                :routeName="'user.comunicazioni.show'"
                            />
                    </CardContent>

                    <CardContent v-else>
                        <div class="p-4 mt-1 text-sm text-gray-800 rounded-lg bg-gray-50 dark:bg-gray-800 dark:text-gray-300" role="alert">
                            <span class="font-medium">Non hai permessi sufficienti per visualizzare le comunicazioni!</span>
                        </div>
                    </CardContent>
                </Card>

                <Card class="w-full">
                    <CardHeader class="p-3">
                        <div class="flex items-center justify-between">
                        <div>
                            <CardTitle class="text-lg">Ultime segnalazioni</CardTitle>
                            <CardDescription>
                            Elenco delle ultime segnalazioni guasto
                            </CardDescription>
                        </div>

                        <Link
                            :href="route('user.segnalazioni.index')"
                            v-if="hasPermission(['Visualizza segnalazioni'])"
                            class="inline-block px-2 py-1 font-bold text-white bg-gray-800 rounded hover:bg-gray-700 text-xs transition-colors"
                        >
                            Visualizza tutte
                        </Link>
                        </div>
                    </CardHeader>

                    <CardContent v-if="hasPermission(['Visualizza segnalazioni'])">
                        <SegnalazioniList 
                            :segnalazioni="segnalazioni" 
                            :priorityIcons="priorityIcons" 
                            :routeName="'user.segnalazioni.show'"
                        />
                    </CardContent>

                    <CardContent v-else>
                        <div class="p-4 mt-1 text-sm text-gray-800 rounded-lg bg-gray-50 dark:bg-gray-800 dark:text-gray-300" role="alert">
                            <span class="font-medium">Non hai permessi sufficienti per visualizzare le segnalazioni!</span>
                        </div>
                    </CardContent>
                </Card>

            </div>

        </div>
    </AppLayout>
</template>
