<script setup lang="ts">

import { computed } from "vue";
import { Head, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import StrutturaLayout from '@/layouts/gestionale/StrutturaLayout.vue';
import DataTable from '@/components/gestionale/scale/DataTable.vue';
import { getColumns } from '@/components/gestionale/scale/columns';
import Alert from "@/components/Alert.vue";
import { usePermission } from "@/composables/permissions";
import CondominioDropdown from "@/components/CondominioDropdown.vue";
import type { BreadcrumbItem } from '@/types';
import type { Flash } from '@/types/flash';
import type { Scala } from '@/types/gestionale/scale';
import type { Building } from '@/types/buildings';
import type { PaginationMeta } from '@/types/pagination';

const props = defineProps<{
  condominio: Building;
  condomini: Building[];
  scale: Scala[];
  meta: PaginationMeta;
}>()

const { generatePath } = usePermission();

const columns = computed(() => getColumns(props.condominio));

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, component: "condominio-dropdown" } as any,
  { title: 'elenco scale', href: '#' },
]);

</script>

<template>

  <Head title="Elenco scale" />

  <GestionaleLayout :breadcrumbs="breadcrumbs">
    
    <template #breadcrumb-condominio>
      <CondominioDropdown :condominio="props.condominio" :condomini="props.condomini" />
    </template>

    <StrutturaLayout>

      <div v-if="flashMessage" class="py-3">
          <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>

      <div class="container mx-auto p-0">
        <DataTable :columns="columns" :data="props.scale" :meta="props.meta" :condominio="props.condominio"/>
      </div>

    </StrutturaLayout>
  </GestionaleLayout>
</template>
