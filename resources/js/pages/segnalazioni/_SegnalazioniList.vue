<script setup lang="ts">
import { computed, onMounted, watch } from "vue";
import { usePage, Head } from "@inertiajs/vue3";
import DataTable from '@/components/segnalazioni/DataTable.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Heading from '@/components/Heading.vue';
import { columns } from '@/components/segnalazioni/columns';
import Alert from "@/components/Alert.vue";
import type { BreadcrumbItem } from '@/types';
import type { Flash } from '@/types/flash';
import type { Segnalazione } from '@/types/segnalazioni';
import SegnalazioniStats from '@/components/segnalazioni/SegnalazioniStats.vue';

const props = defineProps<{ 
  segnalazioni: Segnalazione[]; 
  condominioOptions: []; 
}>()

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

const breadcrumbs: BreadcrumbItem[] = [
  {
    title: 'Elenco anagrafiche',
    href: '/anagrafiche',
  },
];

const scrollToTop = () => window.scrollTo({ top: 0, behavior: 'smooth' });

onMounted(() => {
  if (flashMessage.value) {
    scrollToTop();
  }
});

watch(flashMessage, (newValue) => {
  if (newValue) {
    scrollToTop();
  }
});
</script>

<template>
  <Head title="Elenco segnalazioni guasto" />

  <AppLayout :breadcrumbs="breadcrumbs">
    <div class="px-4 py-6">
      <Heading title="Elenco segnalazioni guasto" description="Di seguito la tabella con l'elenco di tutte le segnalazioni guasto registrate" />

      <SegnalazioniStats :segnalazioni="segnalazioni" />
  
      <div v-if="flashMessage" class="py-4"> 
        <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>
     
      <div class="container mx-auto">
        <DataTable :columns="columns()" :data="segnalazioni" :condominioOptions="condominioOptions" />
      </div> 
    </div>
  </AppLayout> 
</template>