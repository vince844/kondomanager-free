<script setup lang="ts">
import { computed, onMounted, watch } from "vue";
import { usePage, Head, WhenVisible } from "@inertiajs/vue3";
import DataTable from '@/components/comunicazioni/DataTable.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Heading from '@/components/Heading.vue';
import { columns } from '@/components/comunicazioni/columns';
import Alert from "@/components/Alert.vue";
import type { BreadcrumbItem } from '@/types';
import type { Flash } from '@/types/flash';
import type { Comunicazione } from '@/types/comunicazioni';
import ComunicazioniStats from '@/components/comunicazioni/ComunicazioniStats.vue';

const props = defineProps<{ 
  comunicazioni: Comunicazione[]; 
 /*  condominioOptions: [];  */
}>()

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

const breadcrumbs: BreadcrumbItem[] = [
  {
    title: 'Elenco comunicazioni',
    href: '/comunicazioni',
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
  <Head title="Elenco comunicazioni bacheca" />

  <AppLayout :breadcrumbs="breadcrumbs">
    <div class="px-4 py-6">
      <Heading title="Elenco comunicazioni bacheca" description="Di seguito la tabella con l'elenco di tutte le comunicazioni in bacheca registrate" />

      <ComunicazioniStats :comunicazioni="comunicazioni" /> 
  
      <div v-if="flashMessage" class="py-4"> 
        <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>
     
      <div class="container mx-auto">
    
       <DataTable :columns="columns()" :data="comunicazioni"  /> 
   
      </div> 
    </div>
  </AppLayout> 
</template>