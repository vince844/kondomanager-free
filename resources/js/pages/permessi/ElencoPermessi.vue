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
            <div class="flex flex-col shadow ring-1 ring-black ring-opacity-5 md:rounded-lg p-2">

                <div class="container mx-auto">
                    <DataTable :columns="columns" :data="permissions" />
                </div>
 
            </div>

        </UtentiLayout>
    </AppLayout>
</template>
