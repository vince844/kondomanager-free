<script setup lang="ts">

import { computed } from "vue";
import { Head, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import FornitoreLayout from '@/layouts/fornitori/FornitoreLayout.vue';
import DataTable from '@/components/fornitori/documenti/DataTable.vue';
import { createColumns } from '@/components/fornitori/documenti/columns'
import Alert from "@/components/Alert.vue";
import { usePermission } from "@/composables/permissions";
import type { Flash } from '@/types/flash';
import type { Fornitore } from '@/types/fornitori';
import type { Documento } from '@/types/documenti';
import type { PaginationMeta } from '@/types/pagination';

const props = defineProps<{
  fornitore: Fornitore;
  documenti: Documento[],
  meta: PaginationMeta
}>()
 
const { generatePath } = usePermission();

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

/* const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, href: '#' },
  { title: 'immobili', href: generatePath('gestionale/:condominio/immobili', { condominio: props.condominio.id }) },
  { title: props.immobile.nome, href: generatePath('gestionale/:condominio/immobili/:immobile', { condominio: props.condominio.id, immobile: props.immobile.id }) },
  { title: 'documenti', href: '#' },
]); */

</script>

<template>

  <AppLayout>
    <Head title="Elenco documenti immobile" />

    <FornitoreLayout>

      <div v-if="flashMessage" class="py-3">
          <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>

      <div class="container mx-auto p-0">
        <DataTable 
          :columns="createColumns(props.fornitore)" 
          :data="props.documenti" 
          :fornitore="props.fornitore" 
          :meta="meta" 
        />
      </div> 

    </FornitoreLayout>
  </AppLayout>
</template>
