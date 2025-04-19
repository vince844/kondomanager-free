<script setup lang="ts">

import { computed, onMounted, watch, ref } from "vue";
import { usePage, Head } from "@inertiajs/vue3";
import DataTable from '@/components/comunicazioni/DataTable.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Heading from '@/components/Heading.vue';
import { columns } from '@/components/comunicazioni/columns';
import Alert from "@/components/Alert.vue";
import type { BreadcrumbItem } from '@/types';
import type { Flash } from '@/types/flash';
import type { Comunicazione } from '@/types/comunicazioni';
import ComunicazioniStats from '@/components/comunicazioni/ComunicazioniStats.vue';

defineProps<{ 
  comunicazioni: Comunicazione[], 
  meta: {
    current_page: number,
    per_page: number,
    last_page: number,
    total: number
  }
}>()

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);
const statsRef = ref()
const statsContainerRef = ref()
const hasLoaded = ref(false)

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

onMounted(() => {
  const observer = new IntersectionObserver(([entry]) => {
    if (entry.isIntersecting && !hasLoaded.value) {
      statsRef.value?.loadStats?.()
      hasLoaded.value = true
      observer.disconnect()
    }
  })

  if (statsContainerRef.value) {
    observer.observe(statsContainerRef.value)
  }
})

</script>

<template>
  <Head title="Elenco comunicazioni bacheca" />

  <AppLayout :breadcrumbs="breadcrumbs">
    <div class="px-4 py-6">
      <Heading title="Elenco comunicazioni bacheca" description="Di seguito la tabella con l'elenco di tutte le comunicazioni in bacheca registrate" />

      <div ref="statsContainerRef">
        <ComunicazioniStats ref="statsRef" />
      </div>
          
      <div v-if="flashMessage" class="py-4"> 
        <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>
     
      <div class="container mx-auto">
    
       <DataTable :columns="columns()" :data="comunicazioni" :meta="meta" /> 
   
      </div> 
    </div>
  </AppLayout> 
</template>