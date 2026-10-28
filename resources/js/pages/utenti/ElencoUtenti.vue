<script setup lang="ts">

import { computed, ref } from "vue";
import DataTable from '@/components/users/DataTable.vue';
import UtentiStats from '@/components/users/UtentiStats.vue';
import { columns } from '@/components/users/columns';
import { useTabellaServer } from '@/composables/useTabellaServer';
import { Head, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import UtentiLayout from '@/layouts/utenti/Layout.vue';
import Alert from "@/components/Alert.vue";
import { trans } from 'laravel-vue-i18n';
import type { BreadcrumbItem } from '@/types';
import type { FiltriElencoUtenti, StatisticheUtenti, User } from '@/types/users';
import type { Flash } from '@/types/flash';

defineProps<{
  users: User[],
  meta: {
    current_page: number,
    per_page: number,
    last_page: number,
    total: number
  },
  stats: StatisticheUtenti
}>()

const page = usePage<{ flash: { message?: Flash } }>();

const flashMessage = computed(() => page.props.flash.message);

const { filtra } = useTabellaServer(() => route('utenti.index'));

// Un clic su una card sostituisce i filtri dell'elenco con il suo (Coda 398). La barra legge i filtri dal server
// quando nasce: a richiesta finita la tabella si ricrea e la barra mostra ciò che l'elenco mostra davvero.
const generazioneTabella = ref(0);

function applicaFiltro(filtri: FiltriElencoUtenti) {
  filtra({ ...filtri }, () => { generazioneTabella.value++; });
}

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: trans('impostazioni.label.settings'), href: '/impostazioni' },
  { title: trans('users.header.list_users_head'), href: '#' },
]);

</script>

<template>
    
    <AppLayout :breadcrumbs="[]">
        <Head :title="trans('users.header.list_users_head')" />

        <UtentiLayout
            :page-title="trans('users.header.list_users_head')"
            :page-subtitle="trans('users.layout.heading_description')"
            :breadcrumbs="breadcrumbs"
        >

            <div v-if="flashMessage" class="py-4">
                <Alert :message="flashMessage.message" :type="flashMessage.type" />
            </div>

            <div class="container mx-auto">
                <UtentiStats :stats="stats" class="mb-6" @set-filter="applicaFiltro" />

                <DataTable :key="generazioneTabella" :columns="columns" :data="users" :meta="meta"/>
            </div>

        </UtentiLayout>
    </AppLayout>

</template>
