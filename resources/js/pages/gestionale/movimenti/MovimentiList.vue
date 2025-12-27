<script setup lang="ts">

import { computed } from "vue";
import { Head, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import MovimentiLayout from '@/layouts/gestionale/MovimentiLayout.vue';
import Alert from "@/components/Alert.vue";
import CondominioDropdown from "@/components/CondominioDropdown.vue";
import { usePermission } from "@/composables/permissions";
import type { BreadcrumbItem } from '@/types';
import type { Flash } from '@/types/flash';
import type { Building } from '@/types/buildings';

const props = defineProps<{
  condominio: Building;
  condomini: Building[];
}>()

const { generatePath } = usePermission();

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, component: "condominio-dropdown" } as any,
  { title: 'movimenti', href: '#' },

]);


</script>

<template>

  <Head title="Movimenti" />

  <GestionaleLayout :breadcrumbs="breadcrumbs">
    
    <template #breadcrumb-condominio>
      <CondominioDropdown :condominio="props.condominio" :condomini="props.condomini" />
    </template> 

    <MovimentiLayout>

      <div v-if="flashMessage" class="py-3">
        <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>

      <!-- Action buttons -->
      <div class="flex flex-col lg:flex-row lg:justify-end gap-2 w-full">

      </div>

      <div class="container mx-auto p-0">

      </div>

    </MovimentiLayout>

  </GestionaleLayout>
  
</template>
