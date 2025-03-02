<script setup lang="ts">

import { h } from 'vue';
import DataTable from '@/components/DataTable.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { ArrowUpDown, UserPlus} from 'lucide-vue-next';
import type { ColumnDef } from '@tanstack/vue-table';
import type { Payment } from '@/types/payments';
import type { BreadcrumbItem } from '@/types';

defineProps(['users']);

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Elenco utenti',
        href: '/utenti',
    },
];

const roleClasses: Record<string, string> = {
  amministratore: "bg-red-400 text-white",
  collaboratore: "bg-blue-500 text-white",
  fornitore: "bg-green-500 text-white",
  utente: "bg-yellow-500 text-black",
};

const columns: ColumnDef<Payment>[] = [
  
  {
    accessorKey: 'name',
    header: 'Nome e cognome',
    cell: ({ row }) => h('div', { class: 'capitalize' }, row.getValue('name')),
  },
  {
    accessorKey: 'email',
    header: ({ column }) => {
      return h(Button, {
        variant: 'ghost',
        class: 'p-0',
        onClick: () => column.toggleSorting(column.getIsSorted() === 'asc'),
      }, () => ['Indirizzo email', h(ArrowUpDown, { class: 'ml-2 h-4 w-4' })])
    }, 
    cell: ({ row }) => h('div', { class: 'lowercase' }, row.getValue('email')),
  },
  {
    accessorKey: 'roles',
    header: 'Ruolo utente',
    cell: ({ getValue }) => {
      const roles = getValue() as string[];
      return h(
        "div",
        { class: "flex gap-2" }, 
        roles.map((role) =>
          h("span", { class: `px-2 py-1 rounded text-xs font-medium capitalize ${roleClasses[role] || "bg-gray-400 text-white"}` }, role)
        )
      );
    }
    
  },

  {
    id: 'actions',
    enableHiding: false,
   
  }
]

</script>

<template>

  <Head title="Elenco utenti" />

  <AppLayout :breadcrumbs="breadcrumbs">

    <div class="px-4 py-6">
      
      <Heading title="Elenco utenti" description="Di seguito la tabella con l'elenco di tutti gli utenti registrati" />
      
          <Button class="ml-auto hidden h-8 lg:flex" >
            <UserPlus class="w-4 h-4" />
            <Link :href="route('utenti.create')">Nuovo utente</Link>
          </Button>

      <div class="container py-3 mx-auto">
        <DataTable :columns="columns" :data="users" />
      </div>

    </div>
  </AppLayout> 

</template>