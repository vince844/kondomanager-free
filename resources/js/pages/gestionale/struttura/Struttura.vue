<script setup lang="ts">

import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import StrutturaLayout from '@/layouts/gestionale/StrutturaLayout.vue';
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import { usePermission } from "@/composables/permissions";
import CondominioDropdown from "@/components/CondominioDropdown.vue";
import type { Building } from "@/types/buildings";

const props = defineProps<{
  condominio: Building;
  condomini: Building[];
}>();

const { generatePath } = usePermission();

// Condominio data
const condominio = computed<Building>(() => props.condominio);

// Breadcrumbs
const breadcrumbs = computed(() => [
  { title: 'Gestionale', href:generatePath('gestionale/:condominio', { condominio: condominio.value.id }) },
  { title: props.condominio.nome, component: "condominio-dropdown" } as any,
  { title: 'struttura', href: '#' },
]);
</script>


<template>
  <Head title="Dashboard gestionale" />

  <GestionaleLayout :breadcrumbs="breadcrumbs">

    <template #breadcrumb-condominio>
      <CondominioDropdown :condominio="props.condominio" :condomini="props.condomini" />
    </template>

    <StrutturaLayout>
        pagina struttura

    </StrutturaLayout>
   
  </GestionaleLayout>
</template>
