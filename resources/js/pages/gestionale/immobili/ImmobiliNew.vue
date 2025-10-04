<script setup lang="ts">

import { computed } from 'vue';
import { Link, Head, useForm } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import { usePermission } from "@/composables/permissions";
import CondominioDropdown from '@/components/CondominioDropdown.vue';
import { Button } from '@/components/ui/button';
import { List, Plus, LoaderCircle} from 'lucide-vue-next';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Textarea } from '@/components/ui/textarea';
import { Separator } from '@/components/ui/separator';
import vSelect from "vue-select";
import type { Building } from '@/types/buildings';
import type { BreadcrumbItem } from '@/types';
import type { Palazzina } from '@/types/gestionale/palazzine';
import type { Scala } from '@/types/gestionale/scale';
import type { TipologiaImmobile } from '@/types/gestionale/tipologie-immobili';

const props = defineProps<{
  condominio: Building;
  condomini: Building[];
  palazzine: Palazzina[];
  scale: Scala[];
  tipologie: TipologiaImmobile[]
}>()

const { generatePath, generateRoute } = usePermission();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, component: "condominio-dropdown" } as any,
  { title: 'immobili', href: generatePath('gestionale/:condominio/immobili', { condominio: props.condominio.id }) },
  { title: 'crea immobile', href: '#' },
]);

const form = useForm({
  nome: '',
  descrizione: '',
  note: '',
  comune_catasto: '',
  codice_catasto: '',
  sezione_catasto: '',
  foglio_catasto: '',
  particella_catasto: '',
  subalterno_catasto: '',
  interno: '',
  piano: '',
  superficie: '',
  numero_vani: '',
  palazzina_id: '',
  scala_id: '',
  tipologia_id: '',
});

const submit = () => {
    form.post(route(...generateRoute('gestionale.immobili.store', { condominio: props.condominio.id })), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
        }
    });
};

</script>

<template>

    <Head title="Crea nuovo immobile" />

    <GestionaleLayout :breadcrumbs="breadcrumbs">

      <template #breadcrumb-condominio>
        <CondominioDropdown :condominio="props.condominio" :condomini="props.condomini" />
      </template>

      <div class="px-4 py-6">
        <div class="w-full shadow ring-1 ring-black/5 md:rounded-lg p-4">
          <section class="w-full">

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
                  :href="generatePath('gestionale/:condominio/immobili', { condominio: props.condominio.id })"
                  class="w-full lg:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90"
                >
                  <List class="w-4 h-4" />
                  <span>Immobili</span>
                </Link>
              </div>

              <Separator class="my-4" />

              <div class="bg-white dark:bg-muted rounded space-y-4 mt-3" >

                <div class="mt-2 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                    <div class="sm:col-span-3">
                      <Label for="nome">Nome</Label>
                      <Input 
                        id="nome" 
                        class="mt-1 block w-full"
                          v-model="form.nome" 
                          v-on:focus="form.clearErrors('nome')"
                          placeholder="Nome" 
                      />
                      
                      <InputError :message="form.errors.nome" />
            
                    </div>
                </div> 

                <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                  <div class="sm:col-span-6">
                    <Label for="indirizzo">Descrizione</Label>
                    <Input 
                      id="descrizione" 
                      class="mt-1 block w-full"
                        v-model="form.descrizione" 
                        v-on:focus="form.clearErrors('descrizione')"
                        placeholder="Descrizione" 
                    />
                    
                    <InputError class="mt-2" :message="form.errors.descrizione" />
          
                  </div>
                </div>

                <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
    
                  <div class="sm:col-span-2">
                    <Label for="tipologia">Tipologia</Label>
                    <v-select 
                        :options="tipologie" 
                        label="nome" 
                        class="mt-1 block w-full"
                        v-model="form.tipologia_id"
                        placeholder="Tipologia immobile"
                        @update:modelValue="form.clearErrors('tipologia_id')" 
                        :reduce="(tipologia: TipologiaImmobile) => tipologia.id"
                    />
                    <InputError :message="form.errors.tipologia_id" />
                  </div>

                  <div class="sm:col-span-2">
                    <Label for="palazzina">Palazzina</Label>
                      <v-select 
                          :options="condominio.palazzine" 
                          label="name" 
                          class="mt-1 block w-full"
                          v-model="form.palazzina_id"
                          placeholder="Associa ad una palazzina"
                          @update:modelValue="form.clearErrors('palazzina_id')" 
                          :reduce="(palazzina: Palazzina) => palazzina.id"
                      />
                    <InputError :message="form.errors.palazzina_id" />
                  </div>

                  <div class="sm:col-span-2">
                    <Label for="scala">Scala</Label>
                    <v-select 
                        :options="condominio.scale" 
                        label="name" 
                        class="mt-1 block w-full"
                        v-model="form.scala_id"
                        placeholder="Associa ad una scala"
                        @update:modelValue="form.clearErrors('scala_id')" 
                        :reduce="(scala: Scala) => scala.id"
                    />
                    <InputError :message="form.errors.scala_id" />
                  </div>
                  
                </div>

                <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-8">
    
                  <div class="sm:col-span-2">
                    <Label for="interno">Interno</Label>
                    <Input 
                      id="interno" 
                      class="mt-1 block w-full"
                      v-model="form.interno" 
                      v-on:focus="form.clearErrors('interno')"
                      placeholder="Interno" 
                    />
                    <InputError :message="form.errors.interno" />
                  </div>

                  <div class="sm:col-span-2">
                    <Label for="piano">Piano</Label>
                    <Input 
                      id="foglio_catasto" 
                      class="mt-1 block w-full"
                      v-model="form.piano" 
                      v-on:focus="form.clearErrors('piano')"
                      placeholder="Piano" 
                    />
                    <InputError :message="form.errors.piano" />
                  </div>

                  <div class="sm:col-span-2">
                    <Label for="superficie">Superficie</Label>
                    <Input 
                      id="superficie" 
                      class="mt-1 block w-full"
                      v-model="form.superficie" 
                      v-on:focus="form.clearErrors('superficie')"
                      placeholder="Superficie" 
                    />
                    <InputError :message="form.errors.superficie" />
                  </div>

                  <div class="sm:col-span-2">
                    <Label for="numero_vani">Numero vani</Label>
                    <Input 
                      id="numero_vani" 
                      class="mt-1 block w-full"
                      v-model="form.numero_vani" 
                      v-on:focus="form.clearErrors('numero_vani')"
                      placeholder="Numero vani" 
                    />
                    <InputError :message="form.errors.numero_vani" />
                  </div>
                </div>

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

                <div>
                  <h3 class="text-lg font-medium leading-6 text-gray-900">Dati catastali</h3>
                  <p class="mt-1 text-sm text-gray-500">Di seguito è possibile specificare i dati catastali dell'immobile</p>
                </div>
                
                <Separator class="my-4" />

                <div class="pt-3 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-4">
                  <!-- Comune catasto (3/4) -->
                  <div class="sm:col-span-3">
                    <Label for="comune_catasto">Comune</Label>
                    <Input 
                      id="comune_catasto" 
                      class="mt-1 block w-full"
                      v-model="form.comune_catasto" 
                      v-on:focus="form.clearErrors('comune_catasto')"
                      placeholder="Comune catasto" 
                    />
                    <InputError :message="form.errors.comune_catasto" />
                  </div>

                  <!-- Codice catasto (1/4) -->
                  <div class="sm:col-span-1">
                    <Label for="codice_catasto">Codice</Label>
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

                <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-8">

                  <div class="sm:col-span-2">
                    <Label for="sezione_catasto">Sezione</Label>
                    <Input 
                      id="sezione_catasto" 
                      class="mt-1 block w-full"
                      v-model="form.sezione_catasto" 
                      v-on:focus="form.clearErrors('sezione_catasto')"
                      placeholder="Sezione catasto" 
                    />
                    <InputError :message="form.errors.sezione_catasto" />
                  </div>
                  
                  <div class="sm:col-span-2">
                    <Label for="foglio_catasto">Foglio</Label>
                    <Input 
                      id="foglio_catasto" 
                      class="mt-1 block w-full"
                      v-model="form.foglio_catasto" 
                      v-on:focus="form.clearErrors('foglio_catasto')"
                      placeholder="Foglio catasto" 
                    />
                    <InputError :message="form.errors.foglio_catasto" />
                  </div>

                  <div class="sm:col-span-2">
                    <Label for="particella_catasto">Particella</Label>
                    <Input 
                      id="particella_catasto" 
                      class="mt-1 block w-full"
                      v-model="form.particella_catasto" 
                      v-on:focus="form.clearErrors('particella_catasto')"
                      placeholder="Particella catasto" 
                    />
                    <InputError :message="form.errors.particella_catasto" />
                  </div>

                  <div class="sm:col-span-2">
                    <Label for="subalterno_catasto">Subalterno</Label>
                    <Input 
                      id="subalterno_catasto" 
                      class="mt-1 block w-full"
                      v-model="form.subalterno_catasto" 
                      v-on:focus="form.clearErrors('subalterno_catasto')"
                      placeholder="Subalterno catasto" 
                    />
                    <InputError :message="form.errors.subalterno_catasto" />
                  </div>
                </div>

              </div>

            </form>

          </section>
        </div>
      </div>

    </GestionaleLayout>

  </template>

  <style src="vue-select/dist/vue-select.css"></style>