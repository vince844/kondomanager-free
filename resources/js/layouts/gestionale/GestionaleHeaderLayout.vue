<script setup lang="ts">

import AppContent from '@/components/AppContent.vue';
import AppHeader from '@/components/GestionaleHeader.vue';
import AppShell from '@/components/AppShell.vue';
import type { BreadcrumbItemType } from '@/types';
import Footer from '@/components/Footer.vue';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppShell class="flex-col">
        <AppHeader :breadcrumbs="breadcrumbs">
            <template #breadcrumb-condominio>
                <slot name="breadcrumb-condominio" />
            </template>
            <template #breadcrumb-esercizio>
                <slot name="breadcrumb-esercizio" />
            </template>
        </AppHeader>
        <AppContent>
            <slot />
        </AppContent>
        <Footer />
    </AppShell>
</template>
