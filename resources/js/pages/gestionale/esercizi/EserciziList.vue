<script setup lang="ts">

import { computed } from "vue";
import { Head, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import DataTable from '@/components/gestionale/esercizi/DataTable.vue';
import { getColumns } from '@/components/gestionale/esercizi/columns';
import Alert from "@/components/Alert.vue";
import { usePermission } from "@/composables/permissions";
import type { BreadcrumbItem } from '@/types';
import type { Flash } from '@/types/flash';
import type { Esercizio } from '@/types/gestionale/esercizi';
import type { Building } from '@/types/buildings';
import type { PaginationMeta } from '@/types/pagination';

const props = defineProps<{
  condominio: Building;
  esercizi: Esercizio[];
  meta: PaginationMeta;
}>()

const { generatePath } = usePermission();

const columns = computed(() => getColumns(props.condominio));

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, href: '#' },
  { title: 'esercizi', href: '#' },
]);

</script>

<template>
  <GestionaleLayout :breadcrumbs="breadcrumbs">
    <Head title="Elenco esercizi" />

      <div class="px-4 py-6">
        <div class="w-full shadow ring-1 ring-black/5 md:rounded-lg p-4">
          <section class="w-full">

            <div v-if="flashMessage" class="py-3">
                <Alert :message="flashMessage.message" :type="flashMessage.type" />
            </div>

            <div class="container mx-auto p-0">
              <DataTable :columns="columns" :data="props.esercizi" :meta="props.meta" :condominio="props.condominio"/>
            </div>

          </section>
        </div>
      </div>

  </GestionaleLayout>
</template>
