<script setup lang="ts">

import { Head } from "@inertiajs/vue3";
import AppLayout from "@/layouts/AppLayout.vue";
import Heading from "@/components/Heading.vue";
import DocumentiListCards from '@/components/documenti/DocumentiListCards.vue';
import type { Categoria } from '@/types/categorie';
import type { Documento } from '@/types/documenti';

defineProps<{
  categoria: Categoria;
  documenti: {
    data: Documento[];
  }
}>();

</script>

<template>
  <Head title="Elenco documenti archivio" />

  <AppLayout>

    <div class="px-4 py-6">
      <Heading
       :title="`Elenco documenti nella categoria ${categoria.name.toLowerCase()}`"
        description="Di seguito una lista delle categorie utilizzate per classificare i documenti nell'archivio del condominio."
      />

      <div class="container mx-auto mt-6">
    
        <div v-if="documenti.data?.length" class="grid gap-4 md:grid-cols-1 lg:grid-cols-4">
          <DocumentiListCards
            v-for="documento in documenti.data"
            :key="documento.id"
            :documento="documento"
          />
        </div>

        <!-- No documents fallback -->
        <div v-else class="p-4 mt-1 text-sm text-gray-800 rounded-lg bg-gray-50 dark:bg-gray-800 dark:text-gray-300" role="alert">
            <span class="font-medium">Nessun documento è stato ancora caricato nella categoria {{ categoria.name.toLowerCase() }}</span>
        </div>

      </div>

    </div>
   
  </AppLayout>
</template>