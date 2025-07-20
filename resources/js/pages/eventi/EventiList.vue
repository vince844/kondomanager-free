<script setup lang="ts">

import { computed } from 'vue';
import { usePage, Head } from '@inertiajs/vue3';
import DataTable from '@/components/eventi/DataTable.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Heading from '@/components/Heading.vue';
import { columns } from '@/components/eventi/columns';
import Alert from '@/components/Alert.vue';
import EventiStats from '@/components/eventi/EventiStats.vue';
import type { Flash } from '@/types/flash';
import type { Evento, Stats } from '@/types/eventi';
import type { PaginationMeta } from '@/types/pagination';

defineProps<{
  eventi: Evento[],
  stats: Stats,
  meta: PaginationMeta
}>()

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

</script>

<template>
  <Head title="Elenco scadenze agenda" />

  <AppLayout>
    <div class="px-4 py-6">
      <Heading
        title="Elenco scadenze in agenda"
        description="Di seguito la tabella con l'elenco di tutte le prossime scadenze nell'agenda del condominio"
      />

      <EventiStats :stats="stats" />

      <div v-if="flashMessage" class="py-4">
        <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>

      <div class="container mx-auto">
        <DataTable :columns="columns" :data="eventi" :meta="meta"/>
      </div>
    </div>
  </AppLayout>
</template>
