<script setup lang="ts">

import { computed, onMounted, watch } from 'vue';
import { usePage, Head } from '@inertiajs/vue3';
import DataTable from '@/components/comunicazioni/DataTable.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Heading from '@/components/Heading.vue';
import { columns } from '@/components/comunicazioni/columns';
import Alert from '@/components/Alert.vue';
import { useComunicazioni } from '@/composables/useComunicazioni';
import ComunicazioniStats from '@/components/comunicazioni/ComunicazioniStats.vue';
import type { BreadcrumbItem } from '@/types';
import type { Flash } from '@/types/flash';
import type { Comunicazione, Stats } from '@/types/comunicazioni';
import type { PaginationMeta } from '@/types/pagination';

defineProps<{ 
  comunicazioni: Comunicazione[], 
  stats: Stats,
  meta: PaginationMeta
}>()

const page = usePage<{
  comunicazioni: Comunicazione[],
  meta: PaginationMeta,
  flash: { message?: Flash }
}>();

const flashMessage = computed(() => page.props.flash.message);
const { setComunicazioni, comunicazioni, meta: tableMeta } = useComunicazioni();

setComunicazioni(page.props.comunicazioni, page.props.meta);

const breadcrumbs: BreadcrumbItem[] = [
  { title: 'Elenco comunicazioni', href: '/comunicazioni' },
];

const scrollToTop = () => window.scrollTo({ top: 0, behavior: 'smooth' });

onMounted(() => {
  if (flashMessage.value) scrollToTop();
});

watch(flashMessage, (newVal) => {
  if (newVal) scrollToTop();
});

</script>

<template>
  <Head title="Elenco comunicazioni bacheca" />

  <AppLayout :breadcrumbs="breadcrumbs">
    <div class="px-4 py-6">
      <Heading
        title="Elenco comunicazioni bacheca"
        description="Di seguito la tabella con l'elenco di tutte le comunicazioni in bacheca registrate"
      />

      <ComunicazioniStats :stats="stats" />

      <div v-if="flashMessage" class="py-4">
        <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>

      <div class="container mx-auto">
        <DataTable :columns="columns()" :data="comunicazioni" :meta="tableMeta" />
      </div>
    </div>
  </AppLayout>
</template>
