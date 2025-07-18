<script setup lang="ts">

import { Head, useForm, Link } from '@inertiajs/vue3';
import { watch, onMounted } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import vSelect from "vue-select";
import type { Anagrafica } from '@/types/anagrafiche';
import type { Permission } from '@/types/permissions';
import type { Role } from '@/types/roles';
import type { User } from '@/types/users';
import type { BreadcrumbItem } from '@/types';
import { LoaderCircle } from 'lucide-vue-next';
import UtentiLayout from '@/layouts/utenti/Layout.vue';
import { Separator } from '@/components/ui/separator';

const props = defineProps<{
  user: User;
  permissions: Permission[];
  anagrafiche: Anagrafica[];
  roles: Role[];
}>();  

const breadcrumbs: BreadcrumbItem[] = [
  {
      title: 'Nuovo utente',
      href: '/utenti/create',
  }
];

const form = useForm({
    name:  props.user?.name,
    email: props.user?.email, 
    roles: props.user?.roles[0]?.id,
    permissions: props.user?.permissions.map(permission => permission.id) || [],
    anagrafica: props.user?.anagrafica?.id,
});

onMounted(() => {
    form.permissions = props.user?.permissions.map(permission => permission.id) || [];
    form.roles = props.user?.roles[0]?.id;
    form.anagrafica = props.user?.anagrafica?.id;
})

watch(
    () => props.user,
    () => {
      form.permissions = props.user?.permissions.map(permission => permission.id) || [],
      form.roles = props.user?.roles[0]?.id,
      form.anagrafica = props.user?.anagrafica?.id
    }
) 

const submit = () => {
    form.put(route("utenti.update", {id: props.user.id} ), {
        preserveScroll: true
    });
};

</script>

<template>

    <Head title="Modifica utente" />
  
    <AppLayout :breadcrumbs="breadcrumbs">

        <UtentiLayout>

            <form class="" @submit.prevent="submit">
                <div class="">
                    <!--  Name field -->
                    <div class="mt-2 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                        <div class="sm:col-span-3">
                            <Label for="name">Nome e cognome</Label>
                            <Input 
                            id="name" 
                            class="mt-1 block w-full"
                                v-model="form.name" 
                                v-on:focus="form.clearErrors('name')"
                                autocomplete="name" 
                                placeholder="Nome e cognome" 
                            />
                            
                            <InputError :message="form.errors.name" />
                
                        </div>

                        <div class="sm:col-span-3">
                            <Label for="email">Indirizzo email</Label>
                            <Input 
                            id="email" 
                            class="mt-1 block w-full"
                                v-model="form.email" 
                                v-on:focus="form.clearErrors('email')"
                                autocomplete="email" 
                                placeholder="Indirizzo email" 
                            />
                            
                            <InputError class="mt-2" :message="form.errors.email" />
                
                        </div>
                    </div>

                    <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                        <!--  Role field -->
                        <div class="sm:col-span-3">

                            <Label for="ruolo">Ruolo utente</Label>

                            <v-select 
                            :options="roles" 
                            label="name" 
                            v-model="form.roles"
                            :reduce="(option: Role) => option.id"
                            placeholder="Seleziona ruolo utente"
                            />

                        </div>

                        <div class="sm:col-span-3">

                            <Label for="ruolo">Associa anagrafica</Label>

                            <v-select 
                            :options="anagrafiche" 
                            label="nome" 
                            v-model="form.anagrafica"
                            :reduce="(option: Anagrafica) => option.id"
                            placeholder="Seleziona anagrafica"
                            /> 

                            <InputError class="mt-2" :message="form.errors.anagrafica" />

                        </div>
                        
                    </div>

                    <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                
                        <div class="sm:col-span-6">

                            <Label for="pemissions">Permessi utente</Label>

                            <v-select 
                            multiple
                            :options="permissions" 
                            label="name" 
                            v-model="form.permissions"
                            :reduce="(option: Permission) => option.id"
                            placeholder="Seleziona permessi utente"
                            />

                        </div>

                    </div>

                </div>

                <div class="pt-5">
                    <div class="flex">

                        <Button :disabled="form.processing">
                            <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                            Modifica utente
                        </Button>

                    </div>
                </div>
            </form>

            <Separator class="my-6 " />

            <div class="sm:flex sm:items-center">
                <div class="sm:flex-auto">
                    <h1 class="text-xl font-semibold text-gray-900">
                        Permessi utente
                    </h1>
                    <p class="mt-2 text-sm text-gray-700">
                        Di seguito una lista di tutti i permessi già associati all'utente
                    </p>
                </div>
            </div>
            <div class="flex flex-col scroll-region pt-4">
                <div class="-my-2 -mx-4 overflow-x-auto sm:-mx-6 lg:-mx-8">
                    <div class="inline-block min-w-full py-2 align-middle md:px-6 lg:px-8">
                        <div class="overflow-hidden shadow ring-1 ring-black/5 md:rounded-lg" >
                            <table class="min-w-full divide-y divide-gray-300">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Nome</th>
                                        <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Descrizione</th>
                                        <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Azioni</th>
                                    </tr>
                                </thead>

                                <tr class="px-6 py-4 text-sm text-gray-800 text-center" v-if="!user.permissions.length">
                                    <td class="px-6 py-4 text-sm text-gray-800 text-center" colspan="5">Nessun permesso associato a questo utente</td>
                                </tr>
                    
                                <tbody class="divide-y divide-gray-200 bg-white">
                                
                                    <tr v-for="userPermission in user.permissions" :key="userPermission.id">
                                        <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-800 font-bold">{{ userPermission.name }}</td>
                                        <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-800">{{ userPermission.description }}</td>
                                        <td class="relative whitespace-nowrap py-4 pl-3 pr-4 text-sm font-medium sm:pr-6 space-x-4">
                                            <Link :href="route('users.permissions.destroy', [user.id, userPermission.id])" method="delete" class="text-red-500 hover:text-red-900 mr-2">Revoca permesso</Link>
                                        </td> 
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

        </UtentiLayout>

    </AppLayout> 
  
  </template>

<style src="vue-select/dist/vue-select.css"></style>