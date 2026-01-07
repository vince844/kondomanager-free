<script setup lang="ts">

import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import vSelect from "vue-select";
import { LoaderCircle } from 'lucide-vue-next';
import UtentiLayout from '@/layouts/utenti/Layout.vue';
import { TagsInput, TagsInputInput, TagsInputItem, TagsInputItemDelete, TagsInputItemText } from '@/components/ui/tags-input';
import { Separator } from '@/components/ui/separator';
import type { BreadcrumbItem } from '@/types';
import type { Building } from '@/types/buildings';

const props = defineProps<{
  buildings: Building[];
}>();  

const breadcrumbs: BreadcrumbItem[] = [
  { title: 'Impostazioni', href: '/impostazioni' },
  { title: 'utenti', href: '/utenti' },
  { title: 'inviti', href: '/inviti' },
  { title: 'invita utenti', href: '#' },
];

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

const addCurrentInput = (event: Event) => {
  const input = event.target as HTMLInputElement;
  const value = input.value.trim();
  
  if (value && !form.emails.includes(value)) {
    form.emails = [...form.emails, value];
    input.value = '';
  }
};

</script>

<template>

    <Head title="Crea nuovo invito" />
  
    <AppLayout :breadcrumbs="breadcrumbs">

        <UtentiLayout>

            <form class="" @submit.prevent="submit">

                <div class="">
                    <h3 class="text-lg font-medium leading-6 text-gray-900">Invita utenti a registrarsi</h3>
                    <p class="mt-1 text-sm text-gray-500">
                        Di seguito è possibile inviare un invito per registrarsi sul portale. Inserisci gli indirizzi email e seleziona i condomini ai quali associarli, questi riceveranno una email con le istruzioni per completare la registrazione
                    </p>
                </div>

                <Separator class="my-4" />

                <div class="py-4">
                    <!--  Email field -->
                    <div class="mt-2 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                        <div class="sm:col-span-3">
                            <Label for="email">Indirizzi email</Label>

                            <TagsInput v-model="form.emails" class="w-full">
                                <TagsInputItem v-for="item in form.emails" :key="item" :value="item">
                                    <TagsInputItemText />
                                    <TagsInputItemDelete @click="form.emails = form.emails.filter(email => email !== item)" />
                                </TagsInputItem>
                                <TagsInputInput 
                                    placeholder="Inserisci un indirizzo email" 
                                    @blur="addCurrentInput"
                                    @keydown.enter="addCurrentInput"
                                />
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

        </UtentiLayout>

    </AppLayout> 
  
  </template>

<style src="vue-select/dist/vue-select.css"></style>