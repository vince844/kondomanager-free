<script setup lang="ts">

import { computed } from 'vue';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import { Head } from '@inertiajs/vue3';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import { usePermission } from "@/composables/permissions";
import type { BreadcrumbItem } from '@/types';
import type { Building } from '@/types/buildings';

const props = defineProps<{
  condominio: Building;
}>()

const { generatePath } = usePermission();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    {title: props.condominio.nome, href: '#' }
]);

</script>

<template>
    <Head title="Dashboard gestionale" />

    <GestionaleLayout :breadcrumbs="breadcrumbs">

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

        </div>
    </GestionaleLayout>
</template>
