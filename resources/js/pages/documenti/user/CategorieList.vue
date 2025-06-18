<script setup lang="ts">

import { Head } from "@inertiajs/vue3";
import AppLayout from "@/layouts/AppLayout.vue";
import Heading from "@/components/Heading.vue";
import CategorieDocumentiCards from '@/components/documenti/CategorieDocumentiCards.vue';
import { Card, CardContent, CardHeader, CardDescription, CardTitle } from '@/components/ui/card';
import DocumentiList from '@/components/documenti/DocumentiList.vue';
import type { Categoria } from '@/types/categorie';
import type { Documento } from '@/types/documenti';

defineProps<{ 
  categorie: Categoria[], 
  documenti: Documento[]
}>()

</script>

<template>
  <Head title="Elenco categorie archivio" />

  <AppLayout>
    <div class="px-4 py-6">
      <!-- Page Heading -->
      <Heading
        title="Elenco categorie archivio documenti"
        description="Di seguito una lista delle categorie utilizzate per classificare i documenti nell'archivio del condominio."
      />

      <!-- Container -->
      <div class="container mx-auto mt-6">
        <div class="flex flex-col lg:flex-row gap-4 w-full">
          <!-- Left Widget -->
          <Card class="w-full lg:w-2/3 border border-muted rounded-lg shadow-sm">
            <CardContent class="p-3">
              <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                <CategorieDocumentiCards
                  v-for="categoria in categorie"
                  :key="categoria.id"
                  :categoria="categoria"
                />
              </div>
            </CardContent>
          </Card>

          <!-- Right Widget -->
          <Card class="w-full lg:w-1/3 border border-muted rounded-lg shadow-sm">
            <CardHeader class="p-3 ml-3">
              <CardTitle class="text-base font-semibold">Ultimi documenti caricati</CardTitle>
              <CardDescription>
                Elenco degli ultimi documenti in archivio
              </CardDescription>
            </CardHeader>
            <CardContent>

              <DocumentiList
                :documenti="documenti" 
              />
              
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  </AppLayout>
</template>
