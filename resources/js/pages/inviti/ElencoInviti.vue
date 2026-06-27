<script setup lang="ts">

import { computed } from "vue";
import DataTable from '@/components/inviti/DataTable.vue';
import { columns } from '@/components/inviti/columns';
import { Head, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Alert from "@/components/Alert.vue";
import UtentiLayout from '@/layouts/utenti/Layout.vue';
import { trans } from 'laravel-vue-i18n';
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
    
    <AppLayout :breadcrumbs="[]">
        <Head :title="trans('users.header.list_invites_head')" />

        <UtentiLayout
            :page-title="trans('users.header.list_invites_head')"
            :page-subtitle="trans('users.header.list_invites_description')"
            :breadcrumbs="breadcrumbs"
        >

            <div v-if="flashMessage" class="py-4">
                <Alert :message="flashMessage.message" :type="flashMessage.type" />
            </div>

            <div class="container mx-auto">
                <DataTable :columns="columns" :data="inviti" />
            </div>

        </UtentiLayout>
    </AppLayout>
</template>
