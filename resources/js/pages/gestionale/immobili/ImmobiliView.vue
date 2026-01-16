<script setup lang="ts">

import { computed } from "vue";
import { Head, Link, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import ImmobileLayout from '@/layouts/gestionale/ImmobileLayout.vue';
import Alert from "@/components/Alert.vue";
import { usePermission } from "@/composables/permissions";
import { List, Pencil } from 'lucide-vue-next';
import type { BreadcrumbItem } from '@/types';
import type { Flash } from '@/types/flash';
import type { Immobile } from '@/types/gestionale/immobili';
import type { Building } from '@/types/buildings';

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
  { title: props.immobile.nome, href: generatePath('gestionale/:condominio/immobili/:immobile', { condominio: props.condominio.id, immobile: props.immobile.id }) },
]);

const truncate = (text: string, length: number = 120) => {
  return text.length > length ? `${text.slice(0, length)}...` : text;
};

</script>

<template>
  <GestionaleLayout :breadcrumbs="breadcrumbs">
    <Head title="Dettagli immobile" />

    <ImmobileLayout>

      <div v-if="flashMessage" class="py-3">
        <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>

      <!-- Action buttons -->
      <div class="flex flex-col lg:flex-row lg:justify-end gap-2 w-full">
        <Link
          as="button"
          :href="generatePath('gestionale/:condominio/immobili/:immobile/edit', { condominio: props.condominio.id, immobile: props.immobile.id })"
          class="w-full lg:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90"
        >
          <Pencil class="w-4 h-4" />
          <span>Modifica</span>
        </Link>

        <Link
          as="button"
          :href="generatePath('gestionale/:condominio/immobili', { condominio: props.condominio.id })"
          class="w-full lg:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90"
        >
          <List class="w-4 h-4" />
          <span>Immobili</span>
        </Link>
      </div>

      <div class="container mx-auto p-0">
        <div class="bg-card mb-6 grid grid-cols-1 md:grid-cols-2 gap-6 rounded-lg border p-6 text-sm mt-4">

          <!-- Left block -->
          <div class="space-y-6 pr-6 border-r">
            <div class="border-b pb-2 mb-8">
              <h3 class="text-lg font-bold">{{immobile.nome}}</h3>
              <p class="text-muted-foreground text-sm ">
                {{ truncate(immobile.descrizione, 80) }}
              </p>
            </div>
    
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <!-- Column 1 -->
              <div class="space-y-3">
                <div class="flex items-center gap-2">
                  <span class="text-muted-foreground font-semibold w-24">Tipologia:</span>
                  <div class="inline-flex items-center rounded-md border px-2.5 py-0.5 font-medium shadow-sm text-xs">
                    {{ immobile.tipologia.nome }}
                  </div>
                </div>

                <div class="flex items-center gap-2">
                  <span class="text-muted-foreground font-semibold w-24">Palazzina:</span>
                  <div>{{ immobile.palazzina?.name ?? '-' }}</div>
                </div>

                <div class="flex items-center gap-2">
                  <span class="text-muted-foreground font-semibold w-24">Scala:</span>
                  <div>{{ immobile.scala?.name ?? '-' }}</div>
                </div>
              </div>

              <!-- Column 2 -->
              <div class="space-y-3">
                <div class="flex items-center gap-2">
                  <span class="text-muted-foreground font-semibold w-24">Interno:</span>
                  <div>{{ immobile.interno }}</div>
                </div>

                <div class="flex items-center gap-2">
                  <span class="text-muted-foreground font-semibold w-24">Piano:</span>
                  <div>{{ immobile.piano ?? '-' }}</div>
                </div>

                <div class="flex items-center gap-2">
                  <span class="text-muted-foreground font-semibold w-24">Superficie:</span>
                  <div>{{ immobile.superficie ? immobile.superficie + ' m²' : '-' }}</div>
                </div>

                <div class="flex items-center gap-2">
                  <span class="text-muted-foreground font-semibold w-24">Numero vani:</span>
                  <div>{{ immobile.numero_vani ?? '-' }}</div>
                </div>
              </div>
            </div>
          </div>

          <!-- Right block -->
          <div class="space-y-6 ">

            <div class="border-b pb-2 mb-8">
              <h3 class="text-lg font-bold">Dati catastali</h3>
              <p class="text-muted-foreground text-sm ">
                Di seguito i dati catastali registrati per questo immobile.
              </p>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <!-- Column 3 -->
              <div class="space-y-3">
                <div class="flex items-center gap-2">
                  <span class="text-muted-foreground font-semibold w-30">Comune catasto:</span>
                  <div>{{ immobile.comune_catasto ?? '-' }}</div>
                </div>

                <div class="flex items-center gap-2">
                  <span class="text-muted-foreground font-semibold w-30">Codice catasto:</span>
                  <div>{{ immobile.codice_catasto ?? '-'}}</div>
                </div>

                <div class="flex items-center gap-2">
                  <span class="text-muted-foreground font-semibold w-30">Sezione:</span>
                  <div>{{ immobile.sezione_catasto ?? '-' }}</div>
                </div>
              </div>

              <!-- Column 4 -->
              <div class="space-y-3">
                <div class="flex items-center gap-2">
                  <span class="text-muted-foreground font-semibold w-24">Foglio:</span>
                  <div>{{ immobile.foglio_catasto ?? '-' }}</div>
                </div>

                <div class="flex items-center gap-2">
                  <span class="text-muted-foreground font-semibold w-24">Particella:</span>
                  <div>{{ immobile.particella_catasto ?? '-' }}</div>
                </div>

                <div class="flex items-center gap-2">
                  <span class="text-muted-foreground font-semibold w-24">Subalterno:</span>
                  <div>{{ immobile.subalterno_catasto ?? '-' }}</div>
                </div>
              </div>
            </div>
          </div>

        </div>
      </div>

      <div class="bg-card mb-2 rounded-lg border p-6 text-sm">
        <!-- Notes Section -->
        <div class="border-b pb-2 mb-4">
          <span class="text-lg font-bold">Note registrate</span>
        </div>

        <div class="text-sm text-gray-700"> 
          {{ immobile.note ? immobile.note : 'Nessuna nota inserita per questo immobile.' }}
        </div>
      </div>

    </ImmobileLayout>
  </GestionaleLayout>
</template>
