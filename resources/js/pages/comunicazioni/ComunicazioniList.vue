<script setup lang="ts">

import { computed } from 'vue';
import { usePage, Head } from '@inertiajs/vue3';
import DataTable from '@/components/comunicazioni/DataTable.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Heading from '@/components/Heading.vue';
import { columns } from '@/components/comunicazioni/columns';
import Alert from '@/components/Alert.vue';
import ComunicazioniStats from '@/components/comunicazioni/ComunicazioniStats.vue';
import { trans } from 'laravel-vue-i18n';
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
  <Head :title="trans('comunicazioni.header.list_communications_head')" />

  <AppLayout>
    <div class="px-4 py-6">
      
      <Heading
        :title="trans('comunicazioni.header.list_communications_title')" 
        :description="trans('comunicazioni.header.list_communications_description')" 
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
