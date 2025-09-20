<script setup lang="ts">

import DataTable from '@/components/permissions/DataTable.vue';
import { columns } from '@/components/permissions/columns';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import UtentiLayout from '@/layouts/utenti/Layout.vue';
import type { Permission } from '@/types/permissions';
import type { BreadcrumbItem } from '@/types';

defineProps<{ permissions: Permission[] }>();

const breadcrumbs: BreadcrumbItem[] = [
  { title: 'Impostazioni', href: '/impostazioni' },
  { title: 'utenti', href: '/utenti' },
  { title: 'permessi', href: '#' },
];

</script>

<template>
    
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Elenco ruoli registrati" />

        <UtentiLayout>

            <div class="container mx-auto">
                <DataTable :columns="columns" :data="permissions" />
            </div>

        </UtentiLayout>
    </AppLayout>
</template>
