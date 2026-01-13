<script setup lang="ts">

import { computed } from 'vue';
import { usePage, Head } from '@inertiajs/vue3';
import DataTable from '@/components/documenti/DataTable.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Heading from '@/components/Heading.vue';
import { columns } from '@/components/documenti/columns';
import Alert from '@/components/Alert.vue';
import DocumentiStats from '@/components/documenti/DocumentiStats.vue';
import { trans } from 'laravel-vue-i18n';
import type { Flash } from '@/types/flash';
import type { Documento, Stats } from '@/types/documenti';
import type { PaginationMeta } from '@/types/pagination';

defineProps<{
  documenti: Documento[],
  stats: Stats,
  meta: PaginationMeta
}>()

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

</script>

<template>
  <Head :title="trans('documenti.header.list_documents_head')" />

  <AppLayout>
    <div class="px-4 py-6">
      <Heading
        :title="trans('documenti.header.list_documents_title')"
        :description="trans('documenti.header.list_documents_description')"
      />

      <DocumentiStats :stats="stats" />

      <div v-if="flashMessage" class="py-4">
        <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>

      <div class="container mx-auto">
         <DataTable :columns="columns" :data="documenti" :meta="meta"/>
      </div>
    </div>
  </AppLayout>
</template>
