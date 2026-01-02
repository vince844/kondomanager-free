<script setup lang="ts">

import { watch, onMounted, computed } from "vue";
import { Link, Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { List, LoaderCircle, Plus } from 'lucide-vue-next';
import Heading from '@/components/Heading.vue';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Separator } from '@/components/ui/separator';
import { Textarea } from '@/components/ui/textarea';
import VueDatePicker from '@vuepic/vue-datepicker';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import '@vuepic/vue-datepicker/dist/main.css'
import vSelect from "vue-select";
import { trans } from 'laravel-vue-i18n';
import { usePermission } from '@/composables/permissions';
import type { BreadcrumbItem } from '@/types';
import type { Building } from '@/types/buildings';
import type { Anagrafica, DocumentType } from '@/types/anagrafiche'; 

const props = defineProps<{
  anagrafica: Anagrafica;
  condomini: Building[];
}>();  

const { generateRoute } = usePermission();

const form = useForm({
    nome:  props.anagrafica?.nome,
    indirizzo: props.anagrafica?.indirizzo, 
    codice_fiscale: props.anagrafica?.codice_fiscale, 
    luogo_nascita: props.anagrafica?.luogo_nascita,
    data_nascita: props.anagrafica?.data_nascita,
    numero_documento: props.anagrafica?.numero_documento, 
    tipologia_documento: props.anagrafica?.tipologia_documento, 
    scadenza_documento: props.anagrafica?.scadenza_documento, 
    email: props.anagrafica?.email, 
    email_secondaria: props.anagrafica?.email_secondaria, 
    pec: props.anagrafica?.pec, 
    telefono: props.anagrafica?.telefono, 
    cellulare: props.anagrafica?.cellulare, 
    note: props.anagrafica?.note, 
    condomini:  props.anagrafica?.condomini.map(condominio => condominio.id) || [],
});

onMounted(() => {
  form.condomini = props.anagrafica?.condomini.map(condominio => condominio.id) || []
})

watch(
    () => props.anagrafica,
    () => {
      form.condomini = props.anagrafica?.condomini.map(condominio => condominio.id) || []
    }
) 

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Modifica anagrafica',
        href: 'admin/anagrafiche/edit',
    },
];

const documents = computed(() => [
  {
    label: trans('anagrafiche.label.passport'),
    id: 'passport',
  },
  {
    label: trans('anagrafiche.label.id_card'),
    id: 'id_card',
  }
]);

const submit = () => {
    form.put(route(generateRoute('anagrafiche.update'), {id: props.anagrafica.id}), {
        preserveScroll: true
    });
};

</script>

<template>

  <Head :title="trans('anagrafiche.header.edit_resident_head')" />

  <AppLayout :breadcrumbs="breadcrumbs">

    <div class="px-4 py-6">
      
      <Heading 
        :title="trans('anagrafiche.header.edit_resident_title')"
        :description="trans('anagrafiche.header.edit_resident_description')"
      />

      <div class="mt-3 flex flex-col">
        <div class="-my-2 -mx-4 sm:-mx-6 lg:-mx-8">
          <div class="inline-block min-w-full py-2 align-middle md:px-6 lg:px-8">
            <form class="space-y-2" @submit.prevent="submit">

              <!-- Action buttons -->
              <div class="flex flex-col lg:flex-row lg:justify-end gap-2 w-full">
                <Button :disabled="form.processing" class="h-8 w-full lg:w-auto">
                  <Plus class="w-4 h-4" v-if="!form.processing" />
                  <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                  {{trans('anagrafiche.actions.save_resident')}}
                </Button>

                <Link
                  as="button"
                  :href="route(generateRoute('anagrafiche.index'))"
                  class="w-full lg:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90"
                >
                  <List class="w-4 h-4" />
                  <span>{{trans('anagrafiche.actions.list_residents')}}</span>
                </Link>
              </div>

              <div class="bg-white dark:bg-muted rounded shadow-sm p-3 space-y-4 border mt-3" >

                <div class="pt-3">
                  <h3 class="text-lg font-medium leading-6 text-gray-900">{{trans('anagrafiche.header.resident_info_heading')}}</h3>
                  <p class="mt-1 text-sm text-gray-500">{{trans('anagrafiche.header.resident_info_description')}}</p>
                </div>

                <Separator class="my-4" />

                <div class="pt-3">
                    
                    <div class="mt-2 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                        <div class="sm:col-span-3">
                          <Label for="nome">{{trans('anagrafiche.label.name')}}</Label>
                          <Input 
                            id="nome" 
                            class="mt-1 block w-full"
                              v-model="form.nome" 
                              v-on:focus="form.clearErrors('nome')"
                              :placeholder="trans('anagrafiche.placeholder.name')" 
                          />
                          
                          <InputError :message="form.errors.nome" />
                
                        </div>
                    </div> 

                    <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                        <div class="sm:col-span-6">
                          <Label for="indirizzo">{{trans('anagrafiche.label.address')}}</Label>
                          <Input 
                            id="indirizzo" 
                            class="mt-1 block w-full"
                            v-model="form.indirizzo" 
                            v-on:focus="form.clearErrors('indirizzo')"
                            :placeholder="trans('anagrafiche.placeholder.address')" 
                          />
                          
                          <InputError class="mt-2" :message="form.errors.indirizzo" />
                
                        </div>
                    </div>

                    <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                        <div class="sm:col-span-2">
                          <Label for="telefono">{{trans('anagrafiche.label.telephone')}}</Label>
                          <Input 
                            id="telefono" 
                            class="mt-1 block w-full"
                            v-model="form.telefono" 
                            v-on:focus="form.clearErrors('telefono')"
                            :placeholder="trans('anagrafiche.placeholder.telephone')"  
                          />
                          
                          <InputError class="mt-2" :message="form.errors.telefono" />
                
                        </div>

                        <div class="sm:col-span-2">
                          <Label for="cellulare">{{trans('anagrafiche.label.mobile')}}</Label>
                          <Input 
                            id="cellulare" 
                            class="mt-1 block w-full"
                            v-model="form.cellulare" 
                            v-on:focus="form.clearErrors('cellulare')"
                            :placeholder="trans('anagrafiche.placeholder.mobile')"  
                          />
                          
                          <InputError class="mt-2" :message="form.errors.cellulare" />
                
                        </div>

                    </div>

                    <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                        <div class="sm:col-span-2">
                          <Label for="email">{{trans('anagrafiche.label.primary_email')}}</Label>
                          <Input 
                            id="email" 
                            class="mt-1 block w-full"
                            v-model="form.email" 
                            v-on:focus="form.clearErrors('email')"
                            :placeholder="trans('anagrafiche.placeholder.primary_email')" 
                          />
                          
                          <InputError class="mt-2" :message="form.errors.email" />
                
                        </div>

                        <div class="sm:col-span-2">
                          <Label for="email_secondaria">{{trans('anagrafiche.label.secondary_email')}}</Label>
                          <Input 
                            id="email_secondaria" 
                            class="mt-1 block w-full"
                            v-model="form.email_secondaria" 
                            v-on:focus="form.clearErrors('email_secondaria')"
                            :placeholder="trans('anagrafiche.placeholder.secondary_email')"  
                          />
                          
                          <InputError class="mt-2" :message="form.errors.email_secondaria" />
                
                        </div>

                        <div class="sm:col-span-2">
                          <Label for="pec">{{trans('anagrafiche.label.pec')}}</Label>
                          <Input 
                            id="pec" 
                            class="mt-1 block w-full"
                            v-model="form.pec" 
                            v-on:focus="form.clearErrors('pec')"
                            :placeholder="trans('anagrafiche.placeholder.pec')" 
                          />
                          
                          <InputError class="mt-2" :message="form.errors.pec" />
                
                        </div>
                    </div>

                    <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                        <div class="sm:col-span-2">
                          <Label for="tipologia_documento">{{trans('anagrafiche.label.document_type')}}</Label>

                          <v-select 
                            :options="documents" 
                            label="label" 
                            v-model="form.tipologia_documento"
                            :placeholder="trans('anagrafiche.placeholder.document_type')"
                            :reduce="(document: DocumentType) => document.id"
                          />
                
                        </div>

                        <div class="sm:col-span-2">
                          <Label for="numero_documento">{{trans('anagrafiche.label.document_number')}}</Label>
                          <Input 
                            id="numero_documento" 
                            class="mt-1 block w-full"
                            v-model="form.numero_documento" 
                            v-on:focus="form.clearErrors('numero_documento')"
                            :placeholder="trans('anagrafiche.placeholder.document_number')" 
                          />
                          
                          <InputError class="mt-2" :message="form.errors.numero_documento" />
                
                        </div>

                        <div class="sm:col-span-2">
                          <Label for="scadenza_documento">{{trans('anagrafiche.label.document_expiry')}}</Label>

                          <VueDatePicker 
                            v-model="form.scadenza_documento" 
                            format="dd/MM/yyyy" 
                            locale="it"
                            :enable-time-picker="false"
                            position="left"
                            auto-position="bottom"
                            :action-row="{ showSelect: false, showCancel: false, }"
                            auto-apply
                            :placeholder="trans('anagrafiche.placeholder.document_expiry')" 
                            class="block w-full py-1" 
                          />

                          <InputError class="mt-2" :message="form.errors.scadenza_documento || ''" />
                
                        </div>
                    </div>

                    <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                        <div class="sm:col-span-2">
                          
                          <Label for="codice_fiscale">{{trans('anagrafiche.label.fiscal_code')}}</Label>

                          <Input 
                            id="codice_fiscale" 
                            class="mt-1 block w-full"
                            v-model="form.codice_fiscale" 
                            v-on:focus="form.clearErrors('codice_fiscale')"
                            :placeholder="trans('anagrafiche.placeholder.fiscal_code')" 
                          />
                          
                          <InputError class="mt-2" :message="form.errors.codice_fiscale" />
                
                        </div>

                        <div class="sm:col-span-2">
                          <Label for="luogo_nascita">{{trans('anagrafiche.label.birth_place')}}</Label>
                          <Input 
                            id="luogo_nascita" 
                            class="mt-1 block w-full"
                            v-model="form.luogo_nascita" 
                            v-on:focus="form.clearErrors('luogo_nascita')"
                            :placeholder="trans('anagrafiche.placeholder.birth_place')"  
                          />
                          
                          <InputError class="mt-2" :message="form.errors.luogo_nascita" />
                
                        </div>

                        <div class="sm:col-span-2">
                          <Label for="scadenza_documento">{{trans('anagrafiche.label.birthday')}}</Label>

                          <VueDatePicker 
                            v-model="form.data_nascita" 
                            format="dd/MM/yyyy" 
                            locale="it"
                            :enable-time-picker="false"
                            position="left"
                            auto-position="bottom"
                            :action-row="{ showSelect: false, showCancel: false, }"
                            auto-apply
                            :placeholder="trans('anagrafiche.placeholder.birthday')"
                            class="block w-full py-1" 
                          />

                          <InputError class="mt-2" :message="form.errors.data_nascita || ''" />
                
                        </div>
                    </div>

                    <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                        <!--  Condomini field -->
                        <div class="sm:col-span-2">

                            <div class="flex items-center text-sm font-medium mb-1 gap-x-2">
                              <Label for="buildings">{{trans('anagrafiche.label.buildings')}}</Label>

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
                                        {{ trans('anagrafiche.tooltip.buildings_header') }}
                                    </h4>
                                    <p class="text-sm">
                                        {{ trans('anagrafiche.tooltip.buildings_description') }}
                                    </p>
                                    </div>
                                </div>
                                </HoverCardContent>
                              </HoverCard>
                            </div>

                            <v-select 
                              multiple
                              :options="condomini" 
                              label="nome" 
                              v-model="form.condomini" 
                              :reduce="(option: Building) => option.id"
                              :placeholder="trans('anagrafiche.placeholder.buildings')" 
                            />

                            <InputError class="mt-2" :message="form.errors.condomini" />

                        </div>

                    </div>

                    <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                        <div class="sm:col-span-6">
                            <Label for="note">{{trans('anagrafiche.label.notes')}}</Label>
                            <Textarea 
                                id="note" 
                                :placeholder="trans('anagrafiche.placeholder.notes')"  
                                v-model="form.note" 
                                v-on:focus="form.clearErrors('note')"
                            />
                        </div>

                        <InputError :message="form.errors.note" />
              
                    </div>
                  
                </div>

              </div>
              
            </form>
          </div>
        </div>
      </div> 

    </div>
  </AppLayout> 

</template>

<style src="vue-select/dist/vue-select.css"></style>