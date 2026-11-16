<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
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
        <template #breadcrumb-esercizio>
            <slot name="breadcrumb-esercizio" />
        </template>

        <slot />
        <Toaster />
    </AppLayout>
</template>
