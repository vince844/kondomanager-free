<script setup lang="ts">

import { computed } from "vue";
import { Head, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import FornitoreLayout from '@/layouts/fornitori/FornitoreLayout.vue';
import DataTable from '@/components/fornitori/anagrafiche/DataTable.vue';
import { createColumns } from '@/components/fornitori/anagrafiche/columns'
import Alert from "@/components/Alert.vue";
import type { Flash } from '@/types/flash';
import type { Fornitore } from '@/types/fornitori';

const props = defineProps<{
  fornitore: Fornitore;
}>()

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

</script>

<template>
  <AppLayout>
    <Head title="Elenco rappresentanti fornitore" />

    <FornitoreLayout>

      <div v-if="flashMessage" class="py-3">
          <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>

      <div class="container mx-auto p-0">
        <DataTable 
          :columns="createColumns(props.fornitore)"
          :data="props.fornitore.referenti" 
        />
      </div>

    </FornitoreLayout>
  </AppLayout>
</template>
