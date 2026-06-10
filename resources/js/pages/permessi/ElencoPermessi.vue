<script setup lang="ts">

import DataTable from '@/components/permissions/DataTable.vue';
import { columns } from '@/components/permissions/columns';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import UtentiLayout from '@/layouts/utenti/Layout.vue';
import { trans } from 'laravel-vue-i18n';
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
    
    <AppLayout :breadcrumbs="[]">
        <Head :title="trans('users.header.list_permissions_head')" />

        <UtentiLayout
            :page-title="trans('users.header.list_permissions_head')"
            :page-subtitle="trans('users.header.list_permissions_description')"
            :breadcrumbs="breadcrumbs"
        >

            <div class="container mx-auto">
                <DataTable :columns="columns" :data="permissions" />
            </div>

        </UtentiLayout>
    </AppLayout>
</template>
