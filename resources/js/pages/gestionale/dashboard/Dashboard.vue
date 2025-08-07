<script setup lang="ts">

import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { usePermission } from "@/composables/permissions";
import { Permission } from '@/enums/Permission';
import type { BreadcrumbItem } from '@/types';

const { generateRoute, hasPermission } = usePermission();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard/pippo',
    },
];

const props = defineProps<{ 
/*   segnalazioni: Segnalazione[]; 
  comunicazioni: Comunicazione[]; 
  documenti: Documento[]; 
  eventi: Evento[];  */
}>()

</script>

<template>
    <Head title="Dashboard gestionale" />

    <GestionaleLayout :breadcrumbs="breadcrumbs">

        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
     
            pagina del gestionale

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

        </div>
    </GestionaleLayout>
</template>
