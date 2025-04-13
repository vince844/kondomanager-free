<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import type { Segnalazione } from '@/types/segnalazioni';
import { ref } from 'vue';
import { CircleArrowDown, CircleArrowRight, CircleArrowUp, CircleAlert } from 'lucide-vue-next';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import SegnalazioniList from '@/components/segnalazioni/SegnalazioniList.vue';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];

const props = defineProps<{ 
  segnalazioni: Segnalazione[]; 
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
                <CardHeader>
                    <CardTitle>Overview</CardTitle>
                </CardHeader>
                <CardContent class="pl-2">
                    <Overview />
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
                            :href="route('admin.segnalazioni.index')"
                            prefetch
                            class="inline-block px-2 py-1 font-bold text-white bg-gray-800 rounded hover:bg-gray-700 text-xs transition-colors"
                        >
                            Visualizza tutte
                        </Link>
                        </div>
                    </CardHeader>

                    <CardContent>
                        <SegnalazioniList 
                            :segnalazioni="segnalazioni" 
                            :priorityIcons="priorityIcons" 
                            :routeName="'admin.segnalazioni.show'"
                        />
                    </CardContent>
                </Card>

            </div>

        </div>
    </AppLayout>
</template>
