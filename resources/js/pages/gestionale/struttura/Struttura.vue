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
  { title: 'dettagli condominio', href: '#' },
]);

</script>

<template>
  <Head title="Dashboard gestionale" />

  <GestionaleLayout :breadcrumbs="breadcrumbs">

    <template #breadcrumb-condominio>
      <CondominioDropdown :condominio="props.condominio" :condomini="props.condomini" />
    </template>

    <StrutturaLayout>
          
      <div class="container mx-auto p-0">
        <div class="bg-card mb-6 grid grid-cols-1 md:grid-cols-2 gap-6 rounded-lg border p-6 text-sm mt-4">

          <!-- Left block -->
          <div class="space-y-6 pr-6 border-r">
            <div class="border-b pb-2 mb-8">
              <h3 class="text-lg font-bold">{{condominio.nome}}</h3>
              <p class="text-muted-foreground text-sm ">
                Di seguito i dettagli registrati per il condominio {{ condominio.nome }}
              </p> 
            </div>
    
            <div class="grid grid-cols-1 md:grid-cols-1 gap-12">
              <!-- Column 1 -->
              <div class="space-y-3">
                <div class="flex items-center gap-2">
                  <span class="text-muted-foreground font-semibold w-36">Indirizzo:</span>
                  <div class="capitalize">{{ condominio.indirizzo }}</div>
                </div>

                <div class="flex items-center gap-2">
                  <span class="text-muted-foreground font-semibold w-36">Codice fiscale:</span>
                  <div>{{ condominio.codice_fiscale ?? '-' }}</div>
                </div>

                <div class="flex items-center gap-2">
                  <span class="text-muted-foreground font-semibold w-36">Codice identificativo:</span>
                  <div>{{ condominio.codice_identificativo ?? '-' }}</div>
                </div>
              </div>

            </div>
          </div>

          <!-- Right block -->
          <div class="space-y-6 ">

            <div class="border-b pb-2 mb-8">
              <h3 class="text-lg font-bold">Dati catastali</h3>
              <p class="text-muted-foreground text-sm ">
                Di seguito i dati catastali registrati per il condominio {{ condominio.nome }}
              </p>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <!-- Column 3 -->
              <div class="space-y-3">
                <div class="flex items-center gap-2">
                  <span class="text-muted-foreground font-semibold w-30">Comune catasto:</span>
                  <div>{{ condominio.comune_catasto ?? '-' }}</div>
                </div>

                <div class="flex items-center gap-2">
                  <span class="text-muted-foreground font-semibold w-30">Codice catasto:</span>
                  <div>{{ condominio.codice_catasto ?? '-'}}</div>
                </div>

                <div class="flex items-center gap-2">
                  <span class="text-muted-foreground font-semibold w-30">Sezione catasto:</span>
                  <div>{{ condominio.sezione_catasto ?? '-' }}</div>
                </div>
              </div>

              <!-- Column 4 -->
              <div class="space-y-3">
                <div class="flex items-center gap-2">
                  <span class="text-muted-foreground font-semibold w-24">Foglio:</span>
                  <div>{{ condominio.foglio_catasto ?? '-' }}</div>
                </div>

                <div class="flex items-center gap-2">
                  <span class="text-muted-foreground font-semibold w-24">Particella:</span>
                  <div>{{ condominio.particella_catasto ?? '-' }}</div>
                </div>
                
              </div>
            </div>
          </div>

        </div>
      </div>

      <div class="bg-card mb-2 rounded-lg border p-6 text-sm">
        <div class="border-b pb-2 mb-4">
          <h3 class="text-lg font-bold">Note registrate</h3>
        </div>

        <p class="text-sm text-gray-700">
          {{ condominio.note || 'Nessuna nota inserita per questo condominio.' }}
        </p>
      </div>

    </StrutturaLayout>
   
  </GestionaleLayout>
</template>
