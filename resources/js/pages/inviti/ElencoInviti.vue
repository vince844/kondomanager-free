<script setup lang="ts">

import { computed } from "vue";
import DataTable from '@/components/inviti/DataTable.vue';
import { columns } from '@/components/inviti/columns';
import { Head, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Alert from "@/components/Alert.vue";
import UtentiLayout from '@/layouts/utenti/Layout.vue';
import type { Invito } from '@/types/inviti';
import type { Flash } from '@/types/flash';
import type { BreadcrumbItem } from '@/types';

defineProps<{ 
    inviti: Invito[] 
}>();

// Extract `$page` props with proper typing
const page = usePage<{ flash: { message?: Flash } }>();

// Computed property to safely access flash messages
const flashMessage = computed(() => page.props.flash.message);

const breadcrumbs: BreadcrumbItem[] = [
  { title: 'Impostazioni', href: '/impostazioni' },
  { title: 'utenti', href: '/utenti' },
  { title: 'inviti', href: '#' },
];

</script>

<template>
    
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Elenco utenti registrati" />

        <UtentiLayout>

            <div v-if="flashMessage" class="py-4">
                <Alert :message="flashMessage.message" :type="flashMessage.type" />
            </div>

            <div class="container mx-auto">
                <DataTable :columns="columns" :data="inviti" />
            </div>

        </UtentiLayout>
    </AppLayout>
</template>
