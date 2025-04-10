<script setup lang="ts">
import { computed, onMounted, watch } from "vue";
import { usePage } from "@inertiajs/vue3";
import DataTable from '@/components/segnalazioni/DataTable.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { columns } from '@/components/segnalazioni/columns';
import Alert from "@/components/Alert.vue";
import type { BreadcrumbItem } from '@/types';
import type { Flash } from '@/types/flash';
import type { Segnalazione } from '@/types/segnalazioni';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Tabs, TabsContent } from '@/components/ui/tabs';
import { CircleArrowUp, CircleArrowRight, CircleArrowDown, CircleAlert } from 'lucide-vue-next';

const props = defineProps<{ 
  segnalazioni: Segnalazione[]; 
  condominioOptions: []; // Add this prop here
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

const countByPriority = computed(() => {
  const counts = {
    bassa: 0,
    media: 0,
    alta: 0,
    urgente: 0,
  }

  for (const s of props.segnalazioni) {
    switch (s.priority) {
      case 'bassa': counts.bassa++; break;
      case 'media': counts.media++; break;
      case 'alta': counts.alta++; break;
      case 'urgente': counts.urgente++; break;
    }
  }

  return counts
})

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

      <Tabs default-value="overview" class="space-y-4 mb-4">
        
        <TabsContent value="overview" class="space-y-4">
          <div class="grid gap-4 md:grid-cols-1 lg:grid-cols-4">
            <Card>
              <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                <CardTitle class="text-sm font-medium">
                  Priorità bassa
                </CardTitle>
                <CircleArrowDown class="w-5 h-5 text-muted-foreground" />
              </CardHeader>
              <CardContent>
                <div class="text-2xl font-bold">
                 + {{ countByPriority.bassa }}
                </div>
              </CardContent>
            </Card>
            
            <Card>
              <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                <CardTitle class="text-sm font-medium">
                  Priorità media
                </CardTitle>
                <CircleArrowRight class="w-5 h-5 text-muted-foreground" />
              </CardHeader>
              <CardContent>
                <div class="text-2xl font-bold">
                 + {{ countByPriority.media }}
                </div>
              </CardContent>
            </Card>
            
            <Card>
              <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                <CardTitle class="text-sm font-medium">
                  Priorità alta
                </CardTitle>
                <CircleArrowUp class="w-5 h-5 text-muted-foreground" />
              </CardHeader>
              <CardContent>
                <div class="text-2xl font-bold">
                 + {{ countByPriority.alta }}
                </div>
              </CardContent>
            </Card>
            
            <Card>
              <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                <CardTitle class="text-sm font-medium">
                  Priorità urgente
                </CardTitle>
                <CircleAlert class="w-5 h-5 text-muted-foreground" />
              </CardHeader>
              <CardContent>
                <div class="text-2xl font-bold">
                 + {{ countByPriority.urgente }}
                </div>
              </CardContent>
            </Card>
          </div>

        </TabsContent>
      </Tabs>
    
      <div v-if="flashMessage" class="py-4"> 
        <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>
     
      <div class="container mx-auto">
        <DataTable :columns="columns()" :data="segnalazioni" :condominioOptions="condominioOptions" />
      </div> 
    </div>
  </AppLayout> 
</template>