<script setup lang="ts">

import { computed } from "vue";
import { Head, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import ImmobileLayout from '@/layouts/gestionale/ImmobileLayout.vue';
import DataTable from '@/components/gestionale/immobili/anagrafiche/DataTable.vue';
import { columns } from '@/components/gestionale/immobili/anagrafiche/columns';
import Alert from "@/components/Alert.vue";
import { usePermission } from "@/composables/permissions";
import type { BreadcrumbItem } from '@/types';
import type { Flash } from '@/types/flash';
import type { Building } from '@/types/buildings';
import type { Immobile } from '@/types/gestionale/immobili';

const props = defineProps<{
  condominio: Building;
  immobile: Immobile;
}>()
 
const { generatePath } = usePermission();

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, href: '#' },
  { title: 'immobili', href: generatePath('gestionale/:condominio/immobili', { condominio: props.condominio.id }) },
  { title: 'anagrafiche immobile', href: '#' },
]);

</script>

<template>
  <GestionaleLayout :breadcrumbs="breadcrumbs">
    <Head title="Elenco immobili" />

    <ImmobileLayout>

      <div v-if="flashMessage" class="py-3">
          <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>

      <div class="container mx-auto p-0">
        <DataTable :columns="columns" :data="props.immobile.anagrafiche"/>
      </div>

    </ImmobileLayout>
  </GestionaleLayout>
</template>
