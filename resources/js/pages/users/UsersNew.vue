<script setup lang="ts">

import { Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import type { BreadcrumbItem } from '@/types';
import { Button } from '@/components/ui/button';
import { List} from 'lucide-vue-next';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Nuovo utente',
        href: '/utenti/create',
    },
];

</script>


<template>

    <Head title="Crea nuovo utente" />
  
    <AppLayout :breadcrumbs="breadcrumbs">
  
      <div class="px-4 py-6">
        
        <Heading title="Crea utente" description="Compila il seguente modulo per la creazione di un nuovo utente" />
        
            <Button class="ml-auto hidden h-8 lg:flex">
              <List class="w-4 h-4" />
              <Link :href="route('utenti.index')">Elenco utenti</Link>
            </Button>
  
        <div class="container py-3 mx-auto">
            qui la creazione di un nuovo utente
     <!--      <DataTable :columns="columns" :data="users" /> -->
        </div>
  
      </div>
    </AppLayout> 
  
  </template>