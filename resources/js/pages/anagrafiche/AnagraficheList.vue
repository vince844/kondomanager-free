<script setup lang="ts">

import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";
import DataTable from '@/components/anagrafiche/DataTable.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { UserPlus} from 'lucide-vue-next';
import { columns } from '@/components/anagrafiche/columns';
import Alert from "@/components/Alert.vue";
import type { BreadcrumbItem } from '@/types';
import type { Flash } from '@/types/flash';
import type { Anagrafica } from '@/types/anagrafiche';

defineProps<{ anagrafiche: Anagrafica[] }>()

// Extract `$page` props with proper typing
const page = usePage<{ flash: { message?: Flash } }>();

// Computed property to safely access flash messages
const flashMessage = computed(() => page.props.flash.message);

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Elenco anagrafiche',
        href: '/anagrafiche',
    },
];

</script>

<template>

  <Head title="Elenco anagrafiche" />

  <AppLayout :breadcrumbs="breadcrumbs">

    <div class="px-4 py-6">
      
      <Heading title="Elenco anagrafiche" description="Di seguito la tabella con l'elenco di tutte le anagrafiche registrate" />
      
          <Button class="ml-auto hidden h-8 lg:flex" >
            <UserPlus class="w-4 h-4" />
            <Link :href="route('anagrafiche.create')">Nuova anagrafica</Link>
          </Button>

          <div v-if="flashMessage" class="py-4">
            <Alert :message="flashMessage.message" :type="flashMessage.type" />
          </div>

      <div class="container py-3 mx-auto">
        <DataTable :columns="columns" :data="anagrafiche" />
      </div>

    </div>
  </AppLayout> 

</template>