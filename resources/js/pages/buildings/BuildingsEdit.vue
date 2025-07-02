<script setup lang="ts">

import { Link, Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import Heading from '@/components/Heading.vue';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Separator } from '@/components/ui/separator';
import { Textarea } from '@/components/ui/textarea';
import { List, Plus, LoaderCircle} from 'lucide-vue-next';
import type { BreadcrumbItem } from '@/types';
import type { Building } from '@/types/buildings';

const props = defineProps<{ building: Building }>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Nuovo condominio',
        href: '/condomini/create',
    },
];

const form = useForm({
    id: props.building.id,
    nome: props.building.nome,
    indirizzo: props.building.indirizzo,
    email: props.building.email,
    note: props.building.note,
    codice_fiscale: props.building.codice_fiscale,
    comune_catasto: props.building.comune_catasto,
    codice_catasto: props.building.codice_catasto,
    sezione_catasto: props.building.sezione_catasto,
    foglio_catasto: props.building.foglio_catasto,
    particella_catasto: props.building.particella_catasto,
});

const submit = () => {
    form.put(route("condomini.update", {id: props.building.id}), {
        preserveScroll: true,
        onFinish: () => form.reset(),
    });
};

</script>

<template>

    <Head title="Modifica condominio" />
  
    <AppLayout :breadcrumbs="breadcrumbs">
  
      <div class="px-4 py-6">
        
        <Heading title="Modifica condominio" description="Compila il seguente modulo per aggiornare o modificarte i dati del condominio" />

            <div class="mt-3 flex flex-col">
                <div class="-my-2 -mx-4 sm:-mx-6 lg:-mx-8">
                    <div class="inline-block min-w-full py-2 align-middle md:px-6 lg:px-8">
                   
                        <form class="space-y-2" @submit.prevent="submit">

                            <div class="flex flex-col lg:flex-row lg:justify-end items-start lg:items-center space-y-2 lg:space-y-0 lg:space-x-2">

                              <Button :disabled="form.processing" class="lg:flex h-8 w-full lg:w-auto">
                                <Plus class="w-4 h-4" v-if="!form.processing" />
                                <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                                Salva
                              </Button>

                              <Link 
                                as="button"
                                :href="route('condomini.index')" 
                                class="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90 w-full lg:w-auto"
                              >
                                <List class="w-4 h-4" />
                                <span>Elenco</span>
                              </Link>

                            </div>

                            <div class="bg-white dark:bg-muted rounded shadow-sm p-3 space-y-4 border mt-3" >

                              <div class="pt-3">
                                  <h3 class="text-lg font-medium leading-6 text-gray-900">Dati anagrafici</h3>
                                  <p class="mt-1 text-sm text-gray-500">Di seguito è possibile modificare o aggiornare i dati anagrafici del condominio</p>
                              </div>

                              <Separator class="my-4" />

                              <div class="pt-3">
                                  
                                  <!--  Name field -->
                                  <div class="mt-2 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                                      <div class="sm:col-span-3">
                                        <Label for="nome">Denominazione</Label>
                                        <Input 
                                          id="nome" 
                                          class="mt-1 block w-full"
                                          v-model="form.nome" 
                                          v-on:focus="form.clearErrors('nome')"
                                          placeholder="Denominazione" 
                                        />
                                        
                                        <InputError :message="form.errors.nome" />
                            
                                      </div>
                                  </div> 

                                  <!--  Indirizzo field -->
                                  <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                                      <div class="sm:col-span-6">
                                        <Label for="indirizzo">Indirizzo</Label>
                                        <Input 
                                          id="indirizzo" 
                                          class="mt-1 block w-full"
                                          v-model="form.indirizzo" 
                                          v-on:focus="form.clearErrors('indirizzo')"
                                          placeholder="Indirizzo del condominio" 
                                        />
                                        
                                        <InputError class="mt-2" :message="form.errors.indirizzo" />
                            
                                      </div>
                                  </div>

                                  <!--  Codice fiscale field -->
                                  <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                                      <div class="sm:col-span-3">
                                        <Label for="codice_fiscale">Codice fiscale</Label>
                                        <Input 
                                          id="codice_fiscale" 
                                          class="mt-1 block w-full"
                                          v-model="form.codice_fiscale" 
                                          v-on:focus="form.clearErrors('codice_fiscale')"
                                          placeholder="Codice fiscale" 
                                        />
                                        
                                        <InputError class="mt-2" :message="form.errors.codice_fiscale" />
                            
                                      </div>

                                      <div class="sm:col-span-3">
                                        <Label for="email">Indirizzo email</Label>
                                        <Input 
                                          id="email" 
                                          class="mt-1 block w-full"
                                          v-model="form.email" 
                                          v-on:focus="form.clearErrors('email')"
                                          placeholder="Indirizzo email" 
                                        />
                                        
                                        <InputError class="mt-2" :message="form.errors.email" />
                            
                                      </div>
                                  </div>

                                  <!--  Note -->
                                  <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                                      <div class="sm:col-span-6">
                                          <Label for="note">Note</Label>
                                          <Textarea 
                                              id="note" 
                                              placeholder="Inserisci una nota qui" 
                                              v-model="form.note" 
                                              v-on:focus="form.clearErrors('note')"
                                          />
                                      </div>

                                      <InputError :message="form.errors.note" />
                            
                                  </div>
                              
                              </div>

                              <div class="pt-3">
                                  <h3 class="text-lg font-medium leading-6 text-gray-900">Dati catastali</h3>
                                  <p class="mt-1 text-sm text-gray-500">Di seguito è possibile specificare i dati catastali del condominio</p>
                              </div>

                              <Separator class="my-4" />

                              <div class="pt-3 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                                <!-- Comune catasto -->
                                <div class="sm:col-span-4">
                                  <Label for="name">Comune</Label>
                                  <Input 
                                    id="comune_catasto" 
                                    class="mt-1 block w-full"
                                      v-model="form.comune_catasto" 
                                      v-on:focus="form.clearErrors('comune_catasto')"
                                      placeholder="Comune catasto" 
                                  />
                                  
                                  <InputError :message="form.errors.comune_catasto" />
                        
                                </div>
                                <!-- Codice catasto -->
                                <div class="sm:col-span-2">
                                  <Label for="name">Codice</Label>
                                  <Input 
                                    id="codice_catasto" 
                                    class="mt-1 block w-full"
                                      v-model="form.codice_catasto" 
                                      v-on:focus="form.clearErrors('codice_catasto')"
                                      placeholder="Codice catasto" 
                                  />
                                  
                                  <InputError :message="form.errors.codice_catasto" />
                                </div>
                              </div>

                              <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                                <!-- Sezione catasto -->
                                <div class="sm:col-span-2">
                                  <Label for="name">Sezione</Label>
                                  <Input 
                                    id="sezione_catasto" 
                                    class="mt-1 block w-full"
                                      v-model="form.sezione_catasto" 
                                      v-on:focus="form.clearErrors('sezione_catasto')"
                                      placeholder="Sezione catasto" 
                                  />
                                  
                                  <InputError :message="form.errors.sezione_catasto" />
                                </div>
                                <!-- Foglio catasto -->
                                <div class="sm:col-span-2">
                                  <Label for="name">Foglio</Label>
                                  <Input 
                                    id="foglio_catasto" 
                                    class="mt-1 block w-full"
                                      v-model="form.foglio_catasto" 
                                      v-on:focus="form.clearErrors('foglio_catasto')"
                                      placeholder="Foglio catasto" 
                                  />
                                  
                                  <InputError :message="form.errors.foglio_catasto" />
                                </div>
                                <!-- Particella catasto -->
                                <div class="sm:col-span-2">
                                  <Label for="name">Particella</Label>
                                  <Input 
                                    id="particella_catasto" 
                                    class="mt-1 block w-full"
                                    v-model="form.particella_catasto" 
                                    v-on:focus="form.clearErrors('particella_catasto')"
                                    placeholder="Particella catasto" 
                                  />
                                  
                                  <InputError :message="form.errors.particella_catasto" />
                                </div>
                              </div>

                            </div>
                                
                      <!--        <div class="pt-5">
                                <div class="flex">

                                  <Button :class="{ 'opacity-25': form.processing }" :disabled="form.processing">Modifica condomino</Button>

                                </div>
                            </div> -->
                        </form>
                      
                    </div>
                </div>
            </div> 
      </div>
    </AppLayout> 
  
</template>