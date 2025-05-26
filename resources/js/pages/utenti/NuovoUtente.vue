<script setup lang="ts">

import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import vSelect from "vue-select";
import type { Anagrafica } from '@/types/anagrafiche';
import type { Permission } from '@/types/permissions';
import type { Role } from '@/types/roles';
import type { BreadcrumbItem } from '@/types';
import { LoaderCircle } from 'lucide-vue-next';
import UtentiLayout from '@/layouts/utenti/Layout.vue';
import { Separator } from '@/components/ui/separator';

const props = defineProps<{
  roles: Role[];
  permissions: Permission[];
  anagrafiche: Anagrafica[];
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
    roles: '',
    permissions: [],
    anagrafica: '',
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

        <UtentiLayout>

            <form @submit.prevent="submit">

              <div>
                    <h3 class="text-lg font-medium leading-6 text-gray-900">Nuovo utente</h3>
                    <p class="mt-1 text-sm text-gray-500">
                        Di seguito è possibile creare un nuovo utente, puoi assegnare un ruolo, un'anagrafica e dei permessi specifici per questo utente</p>
                </div>

                <Separator class="my-4 mt-4" />

                <div class="py-4">
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
                            :reduce="(option: Role) => option.id"
                            placeholder="Seleziona ruolo utente"
                          />

                          <InputError class="mt-2" :message="form.errors.roles" />

                        </div>

                        <!--  Permissions field -->
                        <div class="sm:col-span-3">

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

                    <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                        <!-- Anagrafiche field -->
                        <div class="sm:col-span-3">

                          <Label for="ruolo">Associa anagrafica</Label>

                          <v-select 
                            :options="anagrafiche" 
                            label="nome" 
                            v-model="form.anagrafica"
                            :reduce="(option: Anagrafica) => option.id"
                            @update:modelValue="form.clearErrors('anagrafica')" 
                            placeholder="Seleziona anagrafica"
                          />

                          <InputError class="mt-2" :message="form.errors.anagrafica" />

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

        </UtentiLayout>

    </AppLayout> 
  
  </template>

<style src="vue-select/dist/vue-select.css"></style>