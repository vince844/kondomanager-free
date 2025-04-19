<script setup lang="ts">
import { computed, onMounted, watch, ref } from "vue";
import { usePage, Head } from "@inertiajs/vue3";
import DataTable from '@/components/segnalazioni/DataTable.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Heading from '@/components/Heading.vue';
import { columns } from '@/components/segnalazioni/columns';
import SegnalazioniStats from '@/components/segnalazioni/SegnalazioniStats.vue';
import Alert from "@/components/Alert.vue";
import type { BreadcrumbItem } from '@/types';
import type { Flash } from '@/types/flash';
import type { Segnalazione } from '@/types/segnalazioni';

defineProps<{ 
  segnalazioni: Segnalazione[], 
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
const statsSegnalazioniContainerRef = ref()
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

  if (statsSegnalazioniContainerRef.value) {
    observer.observe(statsSegnalazioniContainerRef.value)
  }
})

</script>

<template>
  <Head title="Elenco segnalazioni guasto" />

  <AppLayout :breadcrumbs="breadcrumbs">
    <div class="px-4 py-6">
      <Heading title="Elenco segnalazioni guasto" description="Di seguito la tabella con l'elenco di tutte le segnalazioni guasto registrate" />

      <div ref="statsSegnalazioniContainerRef">
        <SegnalazioniStats ref="statsRef" />
      </div>
          
      <Transition name="fade">
        <div v-if="flashMessage" class="py-4"> 
          <Alert :message="flashMessage.message" :type="flashMessage.type" />
        </div>
      </Transition>
     
      <div class="container mx-auto">
    
       <DataTable :columns="columns()" :data="segnalazioni" :meta="meta" /> 
   
      </div> 
    </div>
  </AppLayout> 
</template>