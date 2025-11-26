<script setup lang="ts">

import { computed } from "vue";
import { Head, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import DataTable from '@/components/gestionale/pianiDeiConti/DataTable.vue';
import Alert from "@/components/Alert.vue";
import { usePermission } from "@/composables/permissions";
import CondominioDropdown from "@/components/CondominioDropdown.vue";
import EsercizioDropdown from "@/components/EsercizioDropdown.vue";
import { createColumns } from '@/components/gestionale/pianiDeiConti/columns'
import type { BreadcrumbItem } from '@/types';
import type { Flash } from '@/types/flash';
import type { PianoDeiConti } from '@/types/gestionale/piani-dei-conti';
import type { Building } from '@/types/buildings';
import type { PaginationMeta } from '@/types/pagination';
import type { Esercizio } from "@/types/gestionale/esercizi";

const props = defineProps<{
  condominio: Building;
  condomini: Building[];
  esercizio: Esercizio;
  esercizi: Esercizio[],
  pianiDeiConti: PianoDeiConti[];
  meta: PaginationMeta;
}>()

const { generatePath } = usePermission();

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, component: "condominio-dropdown" } as any,
  { title: props.esercizio?.nome, component: "esercizio-dropdown" } as any,
  { title: 'elenco piani conti', href: '#' },
]);

</script>

<template>

  <Head title="Elenco piani conti" />

  <GestionaleLayout :breadcrumbs="breadcrumbs">

    <template #breadcrumb-condominio>
      <CondominioDropdown :condominio="props.condominio" :condomini="props.condomini" />
    </template>

      <template #breadcrumb-esercizio>
        <EsercizioDropdown
            :condominio="props.condominio"
            :esercizio="props.esercizio"
            :esercizi="props.esercizi"
        />
    </template> 
  
    <div class="px-4 py-6">
      <div class="w-full shadow ring-1 ring-black/5 md:rounded-lg p-4">
        <section class="w-full">

          <div v-if="flashMessage" class="py-3">
              <Alert :message="flashMessage.message" :type="flashMessage.type" />
          </div>

          <div class="container mx-auto p-0">
             <DataTable 
                :columns="createColumns(props.condominio, esercizio)" 
                :meta="props.meta" 
                :condominio="props.condominio"
                :data="props.pianiDeiConti"
              />
          </div>

        </section>
      </div>
    </div>

  </GestionaleLayout>
</template>
