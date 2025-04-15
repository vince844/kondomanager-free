<script setup lang="ts">

import {Head, useForm, Link, usePage } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import Heading from '@/components/Heading.vue';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Checkbox } from '@/components/ui/checkbox';
import InputError from '@/components/InputError.vue';
import { Textarea } from '@/components/ui/textarea';
import { LoaderCircle, Plus, List } from 'lucide-vue-next';
import vSelect from "vue-select";
import { Separator } from '@/components/ui/separator';
import type { Building } from '@/types/buildings';
import type { Anagrafica } from '@/types/anagrafiche';
import type { PriorityType, PublishedType } from '@/types/segnalazioni';
import { priorityConstants, publishedConstants } from '@/lib/segnalazioni/constants';
import '@vuepic/vue-datepicker/dist/main.css';
import axios from 'axios';

const props = defineProps<{
  condomini: Building[];
  anagrafiche: Anagrafica[];
}>();  

const anagraficheOptions = ref<Anagrafica[]>([]);

const form = useForm({
    subject: '',
    description: '',
    priority: '',
    stato: '',
    condomini_ids: [],
    can_comment: true as boolean,
    is_featured: false as boolean,
    is_published: true,
    anagrafiche: []
});

const fetchAnagrafiche = async (condomini_ids: number[]) => {
  try {
    const response = await axios.get('/fetch-anagrafiche', {
      params: { condomini_ids },
    });
    console.log('Anagrafiche data:', response.data);

    form.anagrafiche = []; // clear selected items
    anagraficheOptions.value = response.data.map((item: { id: number, nome: string }) => ({
      id: item.id,
      nome: item.nome,
    }));
  } catch (error) {
    console.error('Error fetching anagrafiche:', error);
  }
};

watch(() => form.condomini_ids, fetchAnagrafiche);

const submit = () => {
    form.post(route("admin.comunicazioni.store"), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
        }
    });
};

</script>

<template>

    <Head title="Crea nuova comunicazione" />
  
    <AppLayout >
  
      <div class="px-4 py-6">
        
        <Heading title="Crea comunicazione" description="Compila il seguente modulo per la creazione di una nuova comunicazione per la bacheca del condominio" />

            <form class="space-y-2" @submit.prevent="submit">

                <!-- Container for buttons (wraps buttons for alignment) -->
                <div class="flex flex-col lg:flex-row lg:justify-end space-y-2 lg:space-y-0 lg:space-x-2 items-start lg:items-center">

                    <!-- Button for "Crea Segnalazione" -->
                    <Button :disabled="form.processing" class="lg:flex h-8 w-full lg:w-auto">
                        <Plus class="w-4 h-4" v-if="!form.processing" />
                        <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                        Salva
                    </Button>

                    <!-- Button for "Elenco Segnalazioni" -->
                    <Button type="button" class="lg:flex h-8 w-full lg:w-auto">
                        <List class="w-4 h-4" />
                        <Link prefetch :href="route('admin.comunicazioni.index')" class="block lg:inline">
                        Elenco
                        </Link>
                    </Button>

                </div>

                <!-- Two-column layout (3:1 ratio) -->
                <div class="grid grid-cols-1 lg:grid-cols-4 gap-3 ">

                    <!-- Main Card (3/4 width) -->
                    <div class="col-span-1 lg:col-span-3 mt-3">
                        <div class="bg-white dark:bg-muted rounded shadow-sm p-6 space-y-4 border">
                            
                            <!--  subject field -->
                            <div class="mt-2 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                                <div class="sm:col-span-3">
                                    <Label for="subject">Oggetto comunicazione</Label>
                                    <Input 
                                        id="subject" 
                                        class="mt-1 block w-full"
                                        v-model="form.subject" 
                                        v-on:focus="form.clearErrors('subject')"
                                        placeholder="Oggetto comunicazione" 
                                    />
                                    
                                    <InputError :message="form.errors.subject" />
                        
                                </div>
                                
                            </div> 

                            <div class="mt-2 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                                <div class="sm:col-span-6">
                                    <Label for="description">Descrizione comunicazione</Label>
                                    <Textarea 
                                        id="description" 
                                        class="mt-1 block w-full min-h-[320px]"
                                        v-model="form.description" 
                                        v-on:focus="form.clearErrors('description')"
                                        placeholder="Descrizione comunicazione" 
                                    />
                                    
                                    <InputError :message="form.errors.description" />
                        
                                </div>
                                
                            </div> 

                        </div>
                    </div>

                    <!-- Side Card (1/4 width) -->
                    <div class="col-span-1 mt-3">
                        <div class="bg-white dark:bg-muted rounded shadow-sm p-3 border">

                            <div class="grid grid-cols-1 sm:grid-cols-6">
                                <div class="sm:col-span-6">
                                    <Label for="stato">Stato pubblicazione</Label>

                                    <v-select 
                                        id="stato" 
                                        :options="publishedConstants" 
                                        label="label" 
                                        v-model="form.is_published"
                                        placeholder="Stato pubblicazione"
                                        @update:modelValue="form.clearErrors('is_published')" 
                                        :reduce="(is_published: PublishedType) => is_published.value"
                                    />

                                    <InputError :message="form.errors.is_published" />
                        
                                </div>
                            </div>

                            <div class="pt-3 grid grid-cols-1 sm:grid-cols-6">
                                <div class="sm:col-span-6">
                                    <Label for="priority">Priorità comunicazione</Label>

                                    <v-select 
                                        id="priority" 
                                        :options="priorityConstants" 
                                        label="label" 
                                        v-model="form.priority"
                                        placeholder="Priorità segnalazione"
                                        @update:modelValue="form.clearErrors('priority')" 
                                        :reduce="(priority: PriorityType) => priority.value"
                                    >
                                    <!-- Dropdown list items -->
                                    <template #option="{ label, icon }">
                                        <div class="flex items-center gap-2">
                                        <component :is="icon" class="w-4 h-4 text-muted-foreground" />
                                        <span>{{ label }}</span>
                                        </div>
                                    </template>

                                    <!-- Selected option display -->
                                    <template #selected-option="{ label, icon }">
                                        <div class="flex items-center gap-2">
                                        <component :is="icon" class="w-4 h-4 text-muted-foreground" />
                                        <span>{{ label }}</span>
                                        </div>
                                    </template>
                                    </v-select>

                                    <InputError :message="form.errors.priority" />
                        
                                </div>
                            </div>

                            <div class="pt-3 grid grid-cols-1 sm:grid-cols-6">
                                <div class="sm:col-span-6">
                                    <Label for="condomini">Condomini</Label>

                                    <v-select 
                                        multiple
                                        :options="condomini" 
                                        label="nome" 
                                        v-model="form.condomini_ids"
                                        placeholder="Condomini"
                                        @update:modelValue="form.clearErrors('condomini_ids')" 
                                        :reduce="(condomini: Building) => condomini.id"
                                    />

                                    <InputError :message="form.errors.condomini_ids" />
                        
                                </div>
                            </div>

                            <div class="pt-3 grid grid-cols-1 sm:grid-cols-6">
                            <div class="sm:col-span-6">
                                <Label for="anagrafiche">Anagrafiche</Label>

                                <v-select
                                    multiple
                                    id="anagrafiche"
                                    :options="anagraficheOptions"
                                    label="nome"
                                    v-model="form.anagrafiche"
                                    placeholder="Anagrafiche"
                                    @update:modelValue="form.clearErrors('anagrafiche')"
                                    :reduce="(anagrafica: Anagrafica) => anagrafica.id"
                                    :disabled="form.condomini_ids.length === 0"
                                />

                                <InputError :message="form.errors.anagrafiche" />
                            </div>
                            </div>

                            <Separator class="my-4" />

                            <div class=" grid grid-cols-1 sm:grid-cols-6">
                                <div class="flex items-center space-x-2 sm:col-span-6">
                                    <Checkbox 
                                        class="size-4" 
                                        :checked="form.can_comment"
                                        v-model="form.can_comment" 
                                        id="can_comment" 
                                        @update:checked="(val) => form.can_comment = val" 
                                     />
                                    <label
                                        for="comments"
                                        class="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70"
                                        >
                                        Permetti commenti comunicazione
                                    </label>
                                </div>
                            </div>

                            <div class="pt-4 grid grid-cols-1 sm:grid-cols-6">
                                <div class="flex items-center space-x-2 sm:col-span-6">
                                    <Checkbox 
                                        class="size-4" 
                                        :checked="form.is_featured"
                                        v-model="form.is_featured" 
                                        id="is_featured" 
                                        @update:checked="(val) => form.is_featured = val" 
                                     />
                                    <label
                                        for="comments"
                                        class="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70"
                                        >
                                        Metti comunicazione in evidenza
                                    </label>
                                </div>
                            </div>
                            
                        </div>
                    </div>
                
                </div>

            </form>
      </div>
    </AppLayout> 
  
  </template>

<style src="vue-select/dist/vue-select.css"></style>