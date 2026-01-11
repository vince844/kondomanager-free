<script setup lang="ts">

import { trans } from 'laravel-vue-i18n';
import { computed, reactive } from 'vue';
import { usePage, Head, router } from '@inertiajs/vue3';
import DataTable from '@/components/eventi/DataTable.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Heading from '@/components/Heading.vue';
import { columns } from '@/components/eventi/columns';
import Alert from '@/components/Alert.vue';
import EventiStats from '@/components/eventi/EventiStats.vue';
import { usePermission } from "@/composables/permissions";
import type { Flash } from '@/types/flash';
import type { Evento, Stats } from '@/types/eventi';
import type { PaginationMeta } from '@/types/pagination';

const props = defineProps<{
  eventi: Evento[],
  stats: Stats,
  meta: PaginationMeta,
  filters: Record<string, any>
}>()

const { generateRoute } = usePermission();
const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

const filters = reactive({ ...props.filters });

function setFilter(range: { date_from: string; date_to: string }) {
  filters.date_from = range.date_from;
  filters.date_to = range.date_to;
  filters.page = 1;

  router.get(route(generateRoute('eventi.index')), filters, {
    preserveScroll: true,
    preserveState: true,
  });
}

</script>

<template>
  <Head :title="trans('eventi.header.elenco_scadenze_agenda')" />

  <AppLayout>
    <div class="px-4 py-6">
      <Heading
        :title="trans('eventi.header.elenco_scadenze_agenda')"
        :description="trans('eventi.header.description')"
      />

       <EventiStats :stats="stats" @setFilter="setFilter" />

      <div v-if="flashMessage" class="py-4">
        <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>

      <div class="container mx-auto">
        <DataTable :columns="columns" :data="eventi" :meta="meta"/>
      </div>
    </div>
  </AppLayout>
</template>
