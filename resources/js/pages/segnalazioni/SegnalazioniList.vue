<script setup lang="ts">

import { computed } from "vue";
import { usePage, Head } from "@inertiajs/vue3";
import DataTable from '@/components/segnalazioni/DataTable.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Heading from '@/components/Heading.vue';
import { columns } from '@/components/segnalazioni/columns';
import Alert from "@/components/Alert.vue";
import SegnalazioniStats from '@/components/segnalazioni/SegnalazioniStats.vue';
import type { Flash } from '@/types/flash';
import type { Segnalazione, Stats } from '@/types/segnalazioni';
import type { PaginationMeta } from '@/types/pagination';

defineProps<{ 
  segnalazioni: Segnalazione[], 
  stats: Stats,
  meta: PaginationMeta
}>()

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

</script>

<template>
  <Head title="Elenco segnalazioni guasto" />

  <AppLayout>
    <div class="px-4 py-6">
      <Heading title="Elenco segnalazioni guasto" description="Di seguito la tabella con l'elenco di tutte le segnalazioni guasto registrate" />

      <SegnalazioniStats :stats="stats" />
          
      <div v-if="flashMessage" class="py-4">
        <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>
     
      <div class="container mx-auto">
        <DataTable :columns="columns" :data="segnalazioni" :meta="meta"/>
      </div> 
    </div>
  </AppLayout> 
</template>