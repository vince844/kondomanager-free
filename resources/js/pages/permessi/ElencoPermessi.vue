<script setup lang="ts">

import { computed } from "vue";
import DataTable from '@/components/permissions/DataTable.vue';
import { columns } from '@/components/permissions/columns';
import { Head, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import UtentiLayout from '@/layouts/utenti/Layout.vue';
import { type BreadcrumbItem } from '@/types';
import type { Permission } from '@/types/permissions';
import type { Flash } from '@/types/flash';
import Alert from "@/components/Alert.vue";

defineProps<{ permissions: Permission[] }>();


</script>

<template>
    
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Elenco ruoli registrati" />

        <UtentiLayout>
            <div class="flex flex-col shadow ring-1 ring-black ring-opacity-5 md:rounded-lg p-2">

                <div class="container mx-auto">
                    <DataTable :columns="columns" :data="permissions" />
                </div>
 
            </div>

        </UtentiLayout>
    </AppLayout>
</template>
