<script setup lang="ts">

import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";
import DataTable from '@/components/buildings/DataTable.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { HousePlus } from 'lucide-vue-next';
import { columns } from '@/components/buildings/columns';
import Alert from "@/components/Alert.vue";
import type { BreadcrumbItem } from '@/types';
import type { Flash } from '@/types/flash';
import type { Building } from '@/types/buildings';

defineProps<{ buildings: Building[] }>()

// Extract `$page` props with proper typing
const page = usePage<{ flash: { message?: Flash } }>();

// Computed property to safely access flash messages
const flashMessage = computed(() => page.props.flash.message);

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Elenco condomini',
        href: '/condomini',
    },
];

</script>

<template>

  <Head title="Elenco utenti" />

  <AppLayout :breadcrumbs="breadcrumbs">

    <div class="px-4 py-6">
      
      <Heading title="Elenco condomini" description="Di seguito la tabella con l'elenco di tutti i condomini registrati" />
      
          <Button class="ml-auto hidden h-8 lg:flex" >
            <HousePlus class="w-4 h-4" />
            <Link :href="route('condomini.create')">Nuovo condominio</Link>
          </Button>

          <div v-if="flashMessage" class="py-4">
            <Alert :message="flashMessage.message" :type="flashMessage.type" />
          </div>

      <div class="container py-3 mx-auto">
        <DataTable :columns="columns" :data="buildings" /> 
      </div>

    </div>
  </AppLayout> 

</template>