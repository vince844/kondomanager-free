<script setup lang="ts">

import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import UtentiLayout from '@/layouts/utenti/Layout.vue';
import { type BreadcrumbItem } from '@/types';
import type { Permission } from '@/types/permissions';
import { LoaderCircle } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import vSelect from "vue-select";
import { Separator } from '@/components/ui/separator';

defineProps<{ permissions: Permission[] }>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Nuovo ruolo',
        href: '/ruoli',
    },
];

const form = useForm({
    name: '',
    description: '',
    permissions: []
});

const submit = () => {
    form.post(route("ruoli.store"), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
        }
    });
};

</script>

<template>
    
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Crea nuovo ruolo" />

        <UtentiLayout>
          
                <div class="mt-3 flex flex-col">
                    <div class="-my-2 -mx-4 sm:-mx-6 lg:-mx-8">
                        <div class="inline-block min-w-full align-middle md:px-6 lg:px-8">
                            <div class="shadow ring-1 ring-black ring-opacity-5 md:rounded-lg" >

                                <form class="space-y-2 p-2" @submit.prevent="submit">
                                    <div class="pt-3">
                                        <h3 class="text-lg font-medium leading-6 text-gray-900">Crea nuovo ruolo</h3>
                                        <p class="mt-1 text-sm text-gray-500">
                                        Di seguito è possibile creare un nuovo ruolo e selezionare i permessi ad esso associati</p>
                                    </div>

                                    <Separator class="my-4" />

                                    <div class="py-4">
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

                                    <div class="pt-5">
                                        <div class="flex">

                                        <Button :disabled="form.processing">
                                            <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                                            Crea ruolo
                                        </Button>

                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div> 

        </UtentiLayout>
    </AppLayout>
</template>

<style src="vue-select/dist/vue-select.css"></style>
