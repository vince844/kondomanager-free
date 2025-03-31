<script setup lang="ts">

import { Link, Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { List} from 'lucide-vue-next';
import Heading from '@/components/Heading.vue';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import vSelect from "vue-select";
import type { Building } from '@/types/buildings';
import type { Permission } from '@/types/permissions';
import type { Role } from '@/types/roles';
import type { BreadcrumbItem } from '@/types';
import { LoaderCircle } from 'lucide-vue-next';

const props = defineProps<{
  roles: Role[];
  permissions: Permission[];
  buildings: Building[];
}>();  

const breadcrumbs: BreadcrumbItem[] = [
  {
      title: 'Nuovo utente',
      href: '/utenti/create',
  }
];

const form = useForm({
    name: '',
    email: '',
/*     password: '',
    password_confirmation: '', */
    roles: [],
    permissions: [],
    buildings: [],
});

const submit = () => {
    form.post(route("utenti.store"), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
        }
    });
};

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

            <div class="mt-3 flex flex-col">
                <div class="-my-2 -mx-4 sm:-mx-6 lg:-mx-8">
                    <div class="inline-block min-w-full py-2 align-middle md:px-6 lg:px-8">
                        <div class="shadow ring-1 ring-black ring-opacity-5 md:rounded-lg" >
                            <form class="space-y-2 p-2" @submit.prevent="submit">
                                <div class="pt-2">
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
                                    </div>

                                    <!--  Email field -->
                                    <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
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
                                            placeholder="Seleziona ruolo utente"
                                          />

                                        </div>

                                        <!--  Permissions field -->
                                        <div class="sm:col-span-3">

                                          <Label for="pemissions">Permessi utente</Label>

                                          <v-select 
                                            multiple
                                            :options="permissions" 
                                            label="name" 
                                            v-model="form.permissions"
                                            placeholder="Seleziona permessi utente"
                                          />

                                        </div>
                                    </div>

                                    <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                                        <!--  Condomini field -->
                                        <div class="sm:col-span-3">

                                          <Label for="ruolo">Condomini</Label>

                                          <v-select 
                                            multiple
                                            :options="buildings" 
                                            label="nome" 
                                            v-model="form.buildings"
                                            :reduce="(option: Building) => option.id"
                                            placeholder="Seleziona condomini"
                                          />

                                        </div>

                                    </div>

                                </div>

                                <div class="pt-5">
                                    <div class="flex">

                                      <Button :disabled="form.processing">
                                          <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                                          Crea utente
                                      </Button>

                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div> 
  
      </div>
    </AppLayout> 
  
  </template>

<style src="vue-select/dist/vue-select.css"></style>