<script setup lang="ts">

import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import vSelect from "vue-select";
import type { Building } from '@/types/buildings';
import { LoaderCircle } from 'lucide-vue-next';
import UtentiLayout from '@/layouts/utenti/Layout.vue';
import { TagsInput, TagsInputInput, TagsInputItem, TagsInputItemDelete, TagsInputItemText } from '@/components/ui/tags-input'

const props = defineProps<{
  buildings: Building[];
}>();  

const form = useForm({
    emails: [] as string[],
    buildings: [],
});

const submit = () => {
    form.post(route("inviti.store"), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
        }
    });
};

</script>

<template>

    <Head title="Crea nuovo invito" />
  
    <AppLayout>

        <UtentiLayout>

            <div class="mt-3 flex flex-col">
                <div class="-my-2 -mx-4 sm:-mx-6 lg:-mx-8">
                    <div class="inline-block min-w-full align-middle md:px-6 lg:px-8">
                        <div class="shadow ring-1 ring-black ring-opacity-5 md:rounded-lg" >
                            <form class="space-y-2 p-2" @submit.prevent="submit">
                                <div class="">
                                    <!--  Email field -->
                                    <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                                        <div class="sm:col-span-3">
                                          <Label for="email">Indirizzo email</Label>

                                            <TagsInput v-model="form.emails" class="w-full">
                                                <TagsInputItem v-for="item in form.emails" :key="item" :value="item">
                                                <TagsInputItemText />
                                                <TagsInputItemDelete @click="form.emails = form.emails.filter(email => email !== item)" />
                                                </TagsInputItem>
                                                <TagsInputInput placeholder="Inserisci un indirizzo email" />
                                            </TagsInput>

                                          <InputError class="mt-2" :message="form.errors.emails" />
                               
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
                                            :reduce="(option: Building) => option.codice_identificativo"
                                            placeholder="Seleziona condomini"
                                          />

                                          <InputError class="mt-2" :message="form.errors.buildings" />

                                        </div>

                                    </div>

                                </div>

                                <div class="pt-5">
                                    <div class="flex">

                                      <Button :disabled="form.processing">
                                          <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                                          Invia invito
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