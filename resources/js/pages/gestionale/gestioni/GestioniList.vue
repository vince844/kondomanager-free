<script setup lang="ts">

import { computed } from "vue";
import { Head, usePage, Link } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import DataTable from '@/components/gestionale/gestioni/DataTable.vue';
import { getColumns } from '@/components/gestionale/gestioni/columns';
import Alert from "@/components/Alert.vue";
import { usePermission } from "@/composables/permissions";
import CondominioDropdown from "@/components/CondominioDropdown.vue";
import { Plus } from 'lucide-vue-next';
import type { BreadcrumbItem } from '@/types';
import type { Flash } from '@/types/flash';
import type { Gestione } from '@/types/gestionale/gestioni';
import type { Building } from '@/types/buildings';
import type { Esercizio } from "@/types/gestionale/esercizi";
import type { PaginationMeta } from '@/types/pagination';

const props = defineProps<{
  condominio: Building;
  esercizio: Esercizio | null;
  condomini: Building[];
  gestioni: Gestione[];
  meta: PaginationMeta;
}>()

const { generatePath, generateRoute } = usePermission();

const columns = computed(() => getColumns(props.condominio));

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, component: "condominio-dropdown" } as any,
  { title: 'elenco gestioni', href: '#' },
]);

</script>

<template>

  <Head title="Elenco gestioni" />

  <GestionaleLayout :breadcrumbs="breadcrumbs">

    <template #breadcrumb-condominio>
      <CondominioDropdown :condominio="props.condominio" :condomini="props.condomini" />
    </template>
  
    <div class="px-4 py-6">
      <div class="w-full shadow ring-1 ring-black/5 md:rounded-lg p-4">
        <section class="w-full">

          <div v-if="flashMessage" class="py-3">
              <Alert :message="flashMessage.message" :type="flashMessage.type" />
          </div>

          <!-- Messaggio quando non c'è esercizio aperto -->
          <div v-if="!esercizio" class="py-8">
            <div class="text-center">
       
              <h2 class="text-xl font-semibold text-gray-900 mb-2">
                Nessun esercizio ancora aperto
              </h2>
              
              <p class="text-gray-600 max-w-2xl mx-auto mb-8">
                Non è presente alcun esercizio contabile aperto per <strong>{{ condominio.nome }}</strong>. 
                Per visualizzare e gestire le gestioni, è necessario prima aprire un esercizio contabile.
              </p>

              <div class="flex flex-col sm:flex-row gap-4 justify-center items-center">
                <Link
                    as="button"
                     :href="route(generateRoute('gestionale.esercizi.create'), { condominio: props.condominio.id })"
                    class="w-full lg:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90"
                >
                    <Plus class="w-4 h-4" />
                    <span>Crea nuovo esercizio</span>
                </Link>
              </div>

            </div>
          </div>

          <div v-else class="container mx-auto p-0">
            <DataTable :columns="columns" :data="props.gestioni" :meta="props.meta" :condominio="props.condominio"/>
          </div>

        </section>
      </div>
    </div>

  </GestionaleLayout>
</template>
