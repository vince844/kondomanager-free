<script setup lang="ts">

import { computed } from "vue";
import DataTable from '@/components/roles/DataTable.vue';
import { columns } from '@/components/roles/columns';
import { Head, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import UtentiLayout from '@/layouts/utenti/Layout.vue';
import { type BreadcrumbItem } from '@/types';
import type { Role } from '@/types/roles';
import type { Flash } from '@/types/flash';
import Alert from "@/components/Alert.vue";

defineProps<{ roles: Role[] }>();

// Extract `$page` props with proper typing
const page = usePage<{ flash: { message?: Flash } }>();

// Computed property to safely access flash messages
const flashMessage = computed(() => page.props.flash.message);

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Ruoli registrati',
        href: '/ruoli',
    },
];

</script>

<template>
    
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Elenco ruoli registrati" />

        <UtentiLayout>
         
            <div v-if="flashMessage" class="py-4">
                <Alert :message="flashMessage.message" :type="flashMessage.type" />
            </div> 

            <div class="container mx-auto">
                <DataTable :columns="columns" :data="roles" />
            </div>

        </UtentiLayout>
    </AppLayout>
</template>
