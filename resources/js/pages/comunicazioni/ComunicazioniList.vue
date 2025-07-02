<script setup lang="ts">

import { computed } from 'vue';
import { usePage, Head } from '@inertiajs/vue3';
import DataTable from '@/components/comunicazioni/DataTable.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Heading from '@/components/Heading.vue';
import { columns } from '@/components/comunicazioni/columns';
import Alert from '@/components/Alert.vue';
import ComunicazioniStats from '@/components/comunicazioni/ComunicazioniStats.vue';
import type { Flash } from '@/types/flash';
import type { Comunicazione, Stats } from '@/types/comunicazioni';
import type { PaginationMeta } from '@/types/pagination';

defineProps<{
  comunicazioni: Comunicazione[],
  stats: Stats,
  meta: PaginationMeta
}>()

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

</script>

<template>
  <Head title="Elenco comunicazioni bacheca" />

  <AppLayout>
    <div class="px-4 py-6">
      <Heading
        title="Elenco comunicazioni bacheca"
        description="Di seguito la tabella con l'elenco di tutte le comunicazioni salvate nella bacheca del condominio"
      />

      <ComunicazioniStats :stats="stats" />

      <div v-if="flashMessage" class="py-4">
        <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>

      <div class="container mx-auto">
        <DataTable :columns="columns" :data="comunicazioni" :meta="meta"/>
      </div>
    </div>
  </AppLayout>
</template>
