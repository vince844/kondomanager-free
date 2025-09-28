<script setup lang="ts">

import AppLayout from '@/layouts/gestionale/GestionaleHeaderLayout.vue';
import type { BreadcrumbItemType } from '@/types';
import { Toaster } from '@/components/ui/toast'

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        
        <template #breadcrumb-condominio>
            <slot name="breadcrumb-condominio" />
        </template>

        <slot />
        <Toaster />
    </AppLayout>
</template>
