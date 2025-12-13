<script setup lang="ts">

import { Head, useForm, Link } from '@inertiajs/vue3';
import { watch, onMounted } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import UtentiLayout from '@/layouts/utenti/Layout.vue';
import { LoaderCircle, Info } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import vSelect from "vue-select"
import { Checkbox } from '@/components/ui/checkbox';
import { Tooltip, TooltipTrigger, TooltipContent } from '@/components/ui/tooltip';
import { Separator } from '@/components/ui/separator';
import type { Permission } from '@/types/permissions';
import type { Role } from '@/types/roles';
import { type BreadcrumbItem } from '@/types';

const props = defineProps<{
  role: Role;
  permissions: Permission[];
}>(); 

const breadcrumbs: BreadcrumbItem[] = [
  { title: 'Impostazioni', href: '/impostazioni' },
  { title: 'utenti', href: '/utenti' },
  { title: 'ruoli', href: '/ruoli' },
  { title: 'modifica ruolo', href: '#' },
];

const form = useForm({
    name:  props.role?.name,
    description:  props.role?.description,
    permissions: props.role?.permissions.map(permission => permission.id) || [],
    accessAdmin: props.role?.permissions.some(permission => permission.name === 'Accesso pannello amministratore') || false, 
});

onMounted(() => {
    form.permissions = props.role?.permissions.map(permission => permission.id) || []
});

watch(
    () => props.role,
    () => form.permissions = props.role?.permissions.map(permission => permission.id) || []
);

const submit = () => {
    form.put(route("ruoli.update", {id: props.role.id} ), {
        preserveScroll: true
    });
};

</script>

<template>
    
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Crea nuovo ruolo" />

        <UtentiLayout>
          
            <form class="" @submit.prevent="submit">
                <div class="">
                    <!--  Name field -->
                    <div class="mt-2 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                        <div class="sm:col-span-3">
                            <Label for="name">Nome</Label>
                            <Input 
                                id="name" 
                                class="mt-1 block w-full"
                                v-model="form.name" 
                                v-on:focus="form.clearErrors('name')"
                                autocomplete="name" 
                                placeholder="Inserisci un nome per il ruolo" 
                            />
                            
                            <InputError :message="form.errors.name" />
                        </div>

                        <div class="sm:col-span-3">
                            <Label for="name">Descrizione</Label>
                            <Input 
                                id="name" 
                                class="mt-1 block w-full"
                                v-model="form.description" 
                                v-on:focus="form.clearErrors('description')"
                                autocomplete="name" 
                                placeholder="Inserisci a descrizione per il ruolo" 
                            />
                            
                            <InputError :message="form.errors.description" />
                        </div>

                    </div>

                    <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                    
                        <!--  Permissions field -->
                        <div class="sm:col-span-6">

                        <Label for="pemissions">Permessi</Label>

                        <v-select 
                            multiple
                            :options="permissions" 
                            label="name" 
                            v-model="form.permissions"
                            :reduce="(option: Permission) => option.id"
                            placeholder="Seleziona permessi ruolo"
                        />

                        </div>
                    </div>

                </div>

                <div class="mb-4">
                    <label class="flex items-center space-x-2 mt-6">
                        <Checkbox v-model="form.accessAdmin" />
                        <span class="font-medium">Dai accessso al layout amministratore</span>

                        <!-- Tooltip with icon -->
                        <Tooltip>
                            <TooltipTrigger as-child>
                            <Info class="w-4 h-4 text-muted-foreground cursor-pointer" />
                            </TooltipTrigger>
                            <TooltipContent side="right">
                            Se selezionata questa opzione permette di mostrare il layout amministratore per il nuovo ruolo creato.
                            </TooltipContent>
                        </Tooltip>
                    </label>
                </div>

                <div class="pt-5">
                    <div class="flex">

                    <Button :disabled="form.processing">
                        <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                        Modifica ruolo
                    </Button>

                    </div>
                </div>
            </form>

            <Separator class="my-6 " />
 
            <div class="sm:flex sm:items-center">
                <div class="sm:flex-auto">
                    <h1 class="text-xl font-semibold text-gray-900">
                        Permessi ruolo
                    </h1>
                    <p class="mt-2 text-sm text-gray-700">
                        Di seguito una lista di tutti i permessi già associati al ruolo
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

                                <tr class="px-6 py-4 text-sm text-gray-800 text-center" v-if="!role.permissions.length">
                                    <td class="px-6 py-4 text-sm text-gray-800 text-center" colspan="5">Nessun permesso associato a questo ruolo</td>
                                </tr>
                                <tbody class="divide-y divide-gray-200 bg-white">
                                    <tr v-for="rolePermission in role.permissions" :key="rolePermission.id">
                                        <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-800 font-bold">{{ rolePermission.name }}</td>
                                        <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-800">{{ rolePermission.description }}</td>
                                        <td class="relative whitespace-nowrap py-4 pl-3 pr-4 text-sm font-medium sm:pr-6 space-x-4">
                                            <Link :href="route('ruoli.permissions.destroy', [role.id, rolePermission.id])" method="delete" class="text-red-500 hover:text-red-900 mr-2">Revoca</Link>
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
