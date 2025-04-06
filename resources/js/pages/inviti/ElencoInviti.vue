<script setup lang="ts">

import { computed } from "vue";
import DataTable from '@/components/inviti/DataTable.vue';
import { columns } from '@/components/inviti/columns';
import { Head, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import UtentiLayout from '@/layouts/utenti/Layout.vue';
import type { Invito } from '@/types/inviti';
import type { Flash } from '@/types/flash';
import Alert from "@/components/Alert.vue";

defineProps<{ inviti: Invito[] }>();

// Extract `$page` props with proper typing
const page = usePage<{ flash: { message?: Flash } }>();

// Computed property to safely access flash messages
const flashMessage = computed(() => page.props.flash.message);


</script>

<template>
    
    <AppLayout>
        <Head title="Elenco utenti registrati" />

        <UtentiLayout>
            <div class="flex flex-col shadow ring-1 ring-black ring-opacity-5 md:rounded-lg p-2">

                <div v-if="flashMessage" class="py-4">
                    <Alert :message="flashMessage.message" :type="flashMessage.type" />
                </div>

                <div class="container mx-auto">
                    <DataTable :columns="columns" :data="inviti" />
                </div>

            </div>

        </UtentiLayout>
    </AppLayout>
</template>
