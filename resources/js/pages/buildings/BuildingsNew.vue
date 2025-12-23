<script setup lang="ts">

import { Link, Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { List, Plus, LoaderCircle} from 'lucide-vue-next';
import Heading from '@/components/Heading.vue';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Separator } from '@/components/ui/separator';
import { Textarea } from '@/components/ui/textarea';
import { trans } from 'laravel-vue-i18n';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
  {
      title: 'Elenco condomini',
      href: '/condomini',
  }
];

const form = useForm({
    nome: '',
    indirizzo: '',
    email: '',
    note: '',
    codice_fiscale: '',
    comune_catasto: '',
    codice_catasto: '',
    sezione_catasto: '',
    foglio_catasto: '',
    particella_catasto: '',

});

const submit = () => {
    form.post(route("condomini.store"), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
        }
    });
};

</script>

<template>

  <Head :title="trans('condomini.header.new_building_head')" />

  <AppLayout :breadcrumbs="breadcrumbs">

    <div class="px-4 py-6">
      
       <Heading 
          :title="trans('condomini.header.new_building_title')" 
          :description="trans('condomini.header.new_building_description')" 
        />

      <form class="space-y-2" @submit.prevent="submit">

        <!-- Action buttons -->
        <div class="flex flex-col lg:flex-row lg:justify-end gap-2 w-full">
          <Button :disabled="form.processing" class="h-8 w-full lg:w-auto">
            <Plus class="w-4 h-4" v-if="!form.processing" />
            <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
            {{trans('condomini.actions.save_building')}}
          </Button>

          <Link
            as="button"
            :href="route('condomini.index')"
            class="w-full lg:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90"
          >
            <List class="w-4 h-4" />
            <span>
              {{ trans('condomini.actions.list_buildings') }}
            </span>
          </Link>
        </div>

        <div class="bg-white dark:bg-muted rounded shadow-sm p-3 space-y-4 border mt-3" >

          <div class="pt-3">
            <h3 class="text-lg font-medium leading-6 text-gray-900">{{ trans('condomini.header.building_info_heading') }}</h3>
            <p class="mt-1 text-sm text-gray-500">{{ trans('condomini.header.building_info_description') }}</p>
          </div>

          <Separator class="my-4" />

          <!--  Name field -->
          <div class="mt-2 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
              <div class="sm:col-span-3">
                <Label for="nome">{{ trans('condomini.label.name') }}</Label>
                <Input 
                  id="nome" 
                  class="mt-1 block w-full"
                    v-model="form.nome" 
                    v-on:focus="form.clearErrors('nome')"
                    :placeholder="trans('condomini.placeholder.name')" 
                />
                
                <InputError :message="form.errors.nome" />
      
              </div>
          </div> 

          <!--  Indirizzo field -->
          <div class="grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
              <div class="sm:col-span-6">
                <Label for="indirizzo">{{ trans('condomini.label.address') }}</Label>
                <Input 
                  id="indirizzo" 
                  class="mt-1 block w-full"
                    v-model="form.indirizzo" 
                    v-on:focus="form.clearErrors('indirizzo')"
                    :placeholder="trans('condomini.placeholder.address')" 
                />
                
                <InputError class="mt-2" :message="form.errors.indirizzo" />
      
              </div>
          </div>

          <!--  Codice fiscale field -->
          <div class="grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
              <div class="sm:col-span-3">
                <Label for="codice_fiscale">{{ trans('condomini.label.tax_code') }}</Label>
                <Input 
                  id="codice_fiscale" 
                  class="mt-1 block w-full"
                    v-model="form.codice_fiscale" 
                    v-on:focus="form.clearErrors('codice_fiscale')"
                    :placeholder="trans('condomini.placeholder.tax_code')" 
                />
                
                <InputError class="mt-2" :message="form.errors.codice_fiscale" />
      
              </div>

              <div class="sm:col-span-3">
                <Label for="email">{{ trans('condomini.label.email') }}</Label>
                <Input 
                  id="email" 
                  class="mt-1 block w-full"
                    v-model="form.email" 
                    v-on:focus="form.clearErrors('email')"
                    :placeholder="trans('condomini.placeholder.email')" 
                />
                
                <InputError class="mt-2" :message="form.errors.email" />
      
              </div>
          </div>

          <!--  Note -->
          <div class="grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
              <div class="sm:col-span-6">
                  <Label for="note">{{ trans('condomini.label.notes') }}</Label>
                  <Textarea 
                      id="note" 
                      :placeholder="trans('condomini.placeholder.notes')" 
                      v-model="form.note" 
                      v-on:focus="form.clearErrors('note')"
                  />
              </div>

              <InputError :message="form.errors.note" />
    
          </div>
            
          <div class="pt-5">
            <h3 class="text-lg font-medium leading-6 text-gray-900">{{ trans('condomini.header.building_registry_heading') }}</h3>
            <p class="mt-1 text-sm text-gray-500">{{ trans('condomini.header.building_registry_description') }}</p>
          </div>

          <Separator class="my-4" />

          <div class="grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
            <!-- Comune catasto -->
            <div class="sm:col-span-4">
              <Label for="comune_catasto">{{ trans('condomini.label.municipality') }}</Label>
              <Input 
                id="comune_catasto" 
                class="mt-1 block w-full"
                  v-model="form.comune_catasto" 
                  v-on:focus="form.clearErrors('comune_catasto')"
                  :placeholder="trans('condomini.placeholder.municipality')" 
              />
              
              <InputError :message="form.errors.comune_catasto" />
    
            </div>
            <!-- Codice catasto -->
            <div class="sm:col-span-2">
              <Label for="codice_catasto">{{ trans('condomini.label.municipality_code') }}</Label>
              <Input 
                id="codice_catasto" 
                class="mt-1 block w-full"
                  v-model="form.codice_catasto" 
                  v-on:focus="form.clearErrors('codice_catasto')"
                  :placeholder="trans('condomini.placeholder.municipality_code')" 
              />
              
              <InputError :message="form.errors.codice_catasto" />
            </div>
          </div>

          <div class="grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
            <!-- Sezione catasto -->
            <div class="sm:col-span-2">
              <Label for="sezione_catasto">{{ trans('condomini.label.section') }}</Label>
              <Input 
                id="sezione_catasto" 
                class="mt-1 block w-full"
                  v-model="form.sezione_catasto" 
                  v-on:focus="form.clearErrors('sezione_catasto')"
                  :placeholder="trans('condomini.placeholder.section')" 
              />
              
              <InputError :message="form.errors.sezione_catasto" />
            </div>
            <!-- Foglio catasto -->
            <div class="sm:col-span-2">
              <Label for="foglio_catasto">{{ trans('condomini.label.sheet') }}</Label>
              <Input 
                id="foglio_catasto" 
                class="mt-1 block w-full"
                  v-model="form.foglio_catasto" 
                  v-on:focus="form.clearErrors('foglio_catasto')"
                  :placeholder="trans('condomini.placeholder.sheet')" 
              />
              
              <InputError :message="form.errors.foglio_catasto" />
            </div>
            <!-- Particella catasto -->
            <div class="sm:col-span-2">
              <Label for="particella_catasto">{{ trans('condomini.label.parcel') }}</Label>
              <Input 
                id="particella_catasto" 
                class="mt-1 block w-full"
                v-model="form.particella_catasto" 
                v-on:focus="form.clearErrors('particella_catasto')"
                :placeholder="trans('condomini.placeholder.parcel')" 
              />
              
              <InputError :message="form.errors.particella_catasto" />
            </div>
          </div>
              
        </div>

      </form>

      </div>

  </AppLayout> 
  
 </template>