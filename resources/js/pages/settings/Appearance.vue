<script setup lang="ts">

import { Head } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Impostazioni aspetto',
        href: '/settings/appearance',
    },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Impostazioni aspetto" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall title="Impostazioni aspetto" description="Aggiorna le impostazioni di aspetto dell'applicazione" />
                <AppearanceTabs />
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
