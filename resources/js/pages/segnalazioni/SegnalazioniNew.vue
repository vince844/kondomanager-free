<script setup lang="ts">

import {Head, useForm, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import Heading from '@/components/Heading.vue';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Checkbox } from '@/components/ui/checkbox';
import InputError from '@/components/InputError.vue';
import { Textarea } from '@/components/ui/textarea';
import { LoaderCircle, Plus, List, Info } from 'lucide-vue-next';
import vSelect from "vue-select";
import { Separator } from '@/components/ui/separator';
import { priorityConstants, statoConstants, publishedConstants } from '@/lib/segnalazioni/constants';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import { usePermission } from "@/composables/permissions";
import type { Building } from '@/types/buildings';
import type { Anagrafica } from '@/types/anagrafiche';
import type { PriorityType, StatoType, PublishedType } from '@/types/segnalazioni';
import '@vuepic/vue-datepicker/dist/main.css';

const props = defineProps<{
  condomini: Building[];
  anagrafiche: Anagrafica[];
}>();  

const { generateRoute } = usePermission();

const form = useForm({
    subject: '',
    description: '',
    priority: '',
    stato: '',
    condominio_id: '',
    can_comment: false as boolean,
    is_featured: false as boolean,
    is_published: true,
    anagrafiche: [],
});

const submit = () => {
    form.post(route(generateRoute('segnalazioni.store')), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
        }
    });
};

</script>

<template>

    <Head title="Crea nuova segnalazione" />
  
    <AppLayout >
  
      <div class="px-4 py-6">
        
        <Heading title="Crea segnalazione guasto" description="Compila il seguente modulo per la creazione di una nuova segnalazione guasto" />

        <form class="space-y-2" @submit.prevent="submit">

            <!-- Action buttons -->
            <div class="flex flex-col lg:flex-row lg:justify-end gap-2 w-full">
                <Button :disabled="form.processing" class="h-8 w-full lg:w-auto">
                    <Plus class="w-4 h-4" v-if="!form.processing" />
                    <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                    Salva
                </Button>

                <Link
                    as="button"
                    :href="route(generateRoute('segnalazioni.index'))"
                    class="w-full lg:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90"
                >
                    <List class="w-4 h-4" />
                    <span>Elenco</span>
                </Link>
            </div>

            <!-- Two-column layout (3:1 ratio) -->
            <div class="grid grid-cols-1 lg:grid-cols-4 gap-3 ">

                <!-- Main Card (3/4 width) -->
                <div class="col-span-1 lg:col-span-3 mt-3">
                    <div class="bg-white dark:bg-muted rounded shadow-sm p-3 space-y-4 border">
                        
                        <div class="mt-2 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                            <div class="sm:col-span-3">
                                <Label for="nome">Oggetto segnalazione</Label>
                                <Input 
                                    id="subject" 
                                    class="mt-1 block w-full"
                                    v-model="form.subject" 
                                    v-on:focus="form.clearErrors('subject')"
                                    placeholder="Oggetto segnalazione" 
                                />
                                
                                <InputError :message="form.errors.subject" />
                    
                            </div>
                        </div> 

                        <div class="mt-2 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                            <div class="sm:col-span-6">
                                <Label for="nome">Descrizione segnalazione</Label>
                                <Textarea 
                                    id="description" 
                                    class="mt-1 block w-full min-h-[320px]"
                                    v-model="form.description" 
                                    v-on:focus="form.clearErrors('description')"
                                    placeholder="Descrizone segnalazione" 
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

                                <div class="flex items-center text-sm font-medium mb-1 gap-x-2">
                                    <Label for="stato">Stato pubblicazione</Label>

                                    <HoverCard>
                                        <HoverCardTrigger as-child>
                                        <button type="button" class="cursor-pointer">
                                            <Info class="w-4 h-4 text-muted-foreground" />
                                        </button>
                                        </HoverCardTrigger>
                                        <HoverCardContent class="w-80">
                                        <div class="flex justify-between space-x-4">
                                            <div class="space-y-1">
                                            <h4 class="text-sm font-semibold">
                                                Stato pubblicazione
                                            </h4>
                                            <p class="text-sm">
                                                Scegli se rendere visibile la segnalazione nella bacheca o mantenerla nascosta.
                                            </p>
                                            </div>
                                        </div>
                                        </HoverCardContent>
                                    </HoverCard>
                                </div>

                                <v-select 
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

                                <div class="flex items-center text-sm font-medium mb-1 gap-x-2">
                                    <Label for="stato">Priorità segnalazione</Label>

                                    <HoverCard>
                                        <HoverCardTrigger as-child>
                                        <button type="button" class="cursor-pointer">
                                            <Info class="w-4 h-4 text-muted-foreground" />
                                        </button>
                                        </HoverCardTrigger>
                                        <HoverCardContent class="w-80">
                                        <div class="flex justify-between space-x-4">
                                            <div class="space-y-1">
                                            <h4 class="text-sm font-semibold">
                                               Priorità segnalazione
                                            </h4>
                                            <p class="text-sm">
                                                Seleziona il livello di priorità con cui questa segnalazione deve essere trattata.
                                                Le priorità possono influenzare la visibilità o l'urgenza nella bacheca.
                                            </p>
                                            </div>
                                        </div>
                                        </HoverCardContent>
                                    </HoverCard>
                                </div>

                                <v-select 
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

                                <div class="flex items-center text-sm font-medium mb-1 gap-x-2">
                                    <Label for="stato">Stato segnalazione</Label>

                                    <HoverCard>
                                        <HoverCardTrigger as-child>
                                        <button type="button" class="cursor-pointer">
                                            <Info class="w-4 h-4 text-muted-foreground" />
                                        </button>
                                        </HoverCardTrigger>
                                        <HoverCardContent class="w-80">
                                        <div class="flex justify-between space-x-4">
                                            <div class="space-y-1">
                                            <h4 class="text-sm font-semibold">
                                               Stato segnalazione
                                            </h4>
                                            <p class="text-sm">
                                                Lo stato indica a che punto è la segnalazione: <strong>Aperta</strong> se è appena stata inviata, <strong>In lavorazione</strong> se qualcuno ci sta lavorando, <strong>Chiusa</strong> se è stata risolta.
                                            </p>
                                            </div>
                                        </div>
                                        </HoverCardContent>
                                    </HoverCard>
                                </div>

                                <v-select 
                                    :options="statoConstants" 
                                    label="label" 
                                    v-model="form.stato"
                                    placeholder="Stato segnalazione"
                                    @update:modelValue="form.clearErrors('stato')" 
                                    :reduce="(stato: StatoType) => stato.value"
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

                                <InputError :message="form.errors.stato" />
                    
                            </div>
                        </div>

                        <div class="pt-3 grid grid-cols-1 sm:grid-cols-6">
                            <div class="sm:col-span-6">
                                <Label for="condomini">Condominio</Label>

                                <v-select 
                                    :options="condomini" 
                                    label="nome" 
                                    v-model="form.condominio_id"
                                    placeholder="Condominio"
                                    @update:modelValue="form.clearErrors('condominio_id')" 
                                    :reduce="(condominio: Building) => condominio.id"
                                />

                                <InputError :message="form.errors.condominio_id" />
                    
                            </div>
                        </div>

                        <div class="pt-3 grid grid-cols-1 sm:grid-cols-6">
                            <div class="sm:col-span-6">
                                <Label for="condomini">Anagrafiche</Label>

                                <v-select 
                                    multiple
                                    :options="anagrafiche" 
                                    label="nome" 
                                    v-model="form.anagrafiche"
                                    placeholder="Anagrafiche"
                                    @update:modelValue="form.clearErrors('anagrafiche')" 
                                    :reduce="(anagrafica: Anagrafica) => anagrafica.id"
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
                                    Permetti commenti
                                </label>

                                <HoverCard>
                                    <HoverCardTrigger as-child>
                                        <button type="button" class="cursor-pointer">
                                            <Info class="w-4 h-4 text-muted-foreground" />
                                        </button>
                                    </HoverCardTrigger>
                                    <HoverCardContent class="w-80">
                                    <div class="flex justify-between space-x-4">
                                        <div class="space-y-1">
                                            <h4 class="text-sm font-semibold">
                                                Commenti segnalazione
                                            </h4>
                                            <p class="text-sm">
                                                Quando viene selezionata questa opzione verrano abilitati i commenti per questa segnalazione
                                            </p>
                                        </div>
                                    </div>
                                    </HoverCardContent>
                                </HoverCard>
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
                                    Segnalazione in evidenza
                                </label>

                                <HoverCard>
                                    <HoverCardTrigger as-child>
                                    <button type="button" class="cursor-pointer">
                                        <Info class="w-4 h-4 text-muted-foreground" />
                                    </button>
                                    </HoverCardTrigger>
                                    <HoverCardContent class="w-80 z-50">
                                    <div class="flex justify-between space-x-4">
                                        <div class="space-y-1">
                                        <h4 class="text-sm font-semibold">Metti in evidenza</h4>
                                        <p class="text-sm">
                                            Quando viene selezionata questa opzione, la segnalazione verrà messa in evidenza e comparirà sempre in cima all'elenco delle segnalazioni.
                                        </p>
                                        </div>
                                    </div>
                                    </HoverCardContent>
                                </HoverCard>

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