<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';

import { computed } from 'vue';
import { usePage, Head } from '@inertiajs/vue3';
import DataTable from '@/components/documenti/categories/DataTable.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Heading from '@/components/Heading.vue';
import { columns } from '@/components/documenti/categories/columns';
import Alert from '@/components/Alert.vue';
import type { Flash } from '@/types/flash';
import type { Categoria } from '@/types/categorie';
import type { PaginationMeta } from '@/types/pagination';

defineProps<{
  categorie: Categoria[],
  meta: PaginationMeta
}>()

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

</script>

<template>
  <Head :title="trans('documenti.header.elenco_categorie_archivio_documenti')" />

  <AppLayout>
    <div class="px-4 py-6">
      <Heading
        :title="trans('documenti.header.elenco_categorie_archivio_documenti')"
        description="Di seguito la tabella con l'elenco di tutte le categorie documenti dell'archivio del condominio"
      />

      <div v-if="flashMessage" class="py-4">
        <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>

      <div class="container mx-auto">
         <DataTable :columns="columns" :data="categorie" :meta="meta"/>
      </div>
    </div>
  </AppLayout>
</template>
