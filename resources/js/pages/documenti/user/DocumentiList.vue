<script setup lang="ts">

import { Head } from "@inertiajs/vue3";
import AppLayout from "@/layouts/AppLayout.vue";
import Heading from "@/components/Heading.vue";
import DocumentiListCards from '@/components/documenti/DocumentiListCards.vue';
import type { Categoria } from '@/types/categorie';
import type { Documento } from '@/types/documenti';

defineProps<{
  categoria: Categoria;
  documenti: Documento[];
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
    </div>

    <div class="grid gap-4 md:grid-cols-1 lg:grid-cols-4">
      <DocumentiListCards
        v-for="documento in categoria.documenti"
        :key="documento.id"
        :documento="documento"
      />
    </div>
   
  </AppLayout>
</template>