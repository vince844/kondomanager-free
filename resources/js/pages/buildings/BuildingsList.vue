<script setup lang="ts">

import { computed } from "vue";
import { usePage, Head, Link } from "@inertiajs/vue3";
import DataTable from '@/components/buildings/DataTable.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Heading from '@/components/Heading.vue';
import { columns } from '@/components/buildings/columns';
import Alert from "@/components/Alert.vue";
import { trans } from 'laravel-vue-i18n';
import type { BreadcrumbItem } from '@/types';
import type { Flash } from '@/types/flash';
import type { Building } from '@/types/buildings';

defineProps<{ 
  buildings: Building[], 
  meta: {
    current_page: number,
    per_page: number,
    last_page: number,
    total: number
  }
}>()

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Elenco condomini',
        href: '/condomini',
    },
];

</script>

<template>

  <Head :title="trans('condomini.header.list_buildings_head')" />

  <AppLayout :breadcrumbs="breadcrumbs">

    <div class="px-4 py-6">
      
      <Heading 
        :title="trans('condomini.header.list_buildings_title')" 
        :description="trans('condomini.header.list_buildings_description')"   
      />

      <div v-if="flashMessage" class="py-4">
        <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>

      <div class="container mx-auto">
        <DataTable :columns="columns" :data="buildings" :meta="meta" /> 
      </div>

    </div>
  </AppLayout> 

</template>