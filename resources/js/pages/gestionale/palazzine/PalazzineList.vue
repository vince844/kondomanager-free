<script setup lang="ts">

import { computed } from "vue";
import { Head, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import StrutturaLayout from '@/layouts/gestionale/StrutturaLayout.vue';
import DataTable from '@/components/gestionale/palazzine/DataTable.vue';
import { getColumns } from '@/components/gestionale/palazzine/columns';
import Alert from "@/components/Alert.vue";
import { usePermission } from "@/composables/permissions";
import type { BreadcrumbItem } from '@/types';
import type { Flash } from '@/types/flash';
import type { Palazzina } from '@/types/gestionale/palazzine';
import type { Building } from '@/types/buildings';
import type { PaginationMeta } from '@/types/pagination';


const props = defineProps<{
  condominio: Building;
  palazzine: Palazzina[];
  meta: PaginationMeta;
}>()

const { generatePath } = usePermission();

const columns = computed(() => getColumns(props.condominio));

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, href: '#' },
  { title: 'palazzine', href: '#' },
]);

</script>

<template>
  <GestionaleLayout :breadcrumbs="breadcrumbs">
    <Head title="Elenco palazzine" />

    <StrutturaLayout>

      <div v-if="flashMessage" class="py-3">
          <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>

      <div class="container mx-auto p-0">
        <DataTable :columns="columns" :data="props.palazzine" :meta="props.meta" :condominio="props.condominio"/>
      </div>

    </StrutturaLayout>
  </GestionaleLayout>
</template>
