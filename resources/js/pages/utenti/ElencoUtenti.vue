<script setup lang="ts">

import { computed } from "vue";
import DataTable from '@/components/users/DataTable.vue';
import { columns } from '@/components/users/columns';
import { Head, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import UtentiLayout from '@/layouts/utenti/Layout.vue';
import type { BreadcrumbItem } from '@/types';
import type { User } from '@/types/users';
import type { Flash } from '@/types/flash';
import Alert from "@/components/Alert.vue";

defineProps<{
  users: User[],
  meta: {
    current_page: number,
    per_page: number,
    last_page: number,
    total: number
  }
}>()

const page = usePage<{ flash: { message?: Flash } }>();

const flashMessage = computed(() => page.props.flash.message);

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Utenti registrati',
        href: '/utenti',
    },
];

</script>

<template>
    
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Elenco utenti" />

        <UtentiLayout>

            <div v-if="flashMessage" class="py-4">
                <Alert :message="flashMessage.message" :type="flashMessage.type" />
            </div>

            <div class="container mx-auto">
                <DataTable :columns="columns" :data="users" :meta="meta"/>
            </div>

        </UtentiLayout>
    </AppLayout>

</template>
