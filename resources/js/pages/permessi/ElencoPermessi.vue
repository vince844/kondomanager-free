<script setup lang="ts">

import DataTable from '@/components/permissions/DataTable.vue';
import { columns } from '@/components/permissions/columns';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import UtentiLayout from '@/layouts/utenti/Layout.vue';
import type { Permission } from '@/types/permissions';

defineProps<{ permissions: Permission[] }>();

</script>

<template>
    
    <AppLayout>
        <Head title="Elenco ruoli registrati" />

        <UtentiLayout>

            <div class="container mx-auto">
                <DataTable :columns="columns" :data="permissions" />
            </div>

        </UtentiLayout>
    </AppLayout>
</template>
