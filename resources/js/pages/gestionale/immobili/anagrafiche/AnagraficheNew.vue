<script setup lang="ts">

import { computed } from 'vue';
import { Link, Head, useForm } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import ImmobileLayout from '@/layouts/gestionale/ImmobileLayout.vue';
import { usePermission } from "@/composables/permissions";
import { Button } from '@/components/ui/button';
import { List, Plus, LoaderCircle} from 'lucide-vue-next';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Textarea } from '@/components/ui/textarea';
import { Separator } from '@/components/ui/separator';
import VueDatePicker from '@vuepic/vue-datepicker';
import '@vuepic/vue-datepicker/dist/main.css';
import vSelect from "vue-select";
import type { Building } from '@/types/buildings';
import type { BreadcrumbItem } from '@/types';
import type { Immobile } from '@/types/gestionale/immobili';
import type { Anagrafica } from '@/types/anagrafiche';

const props = defineProps<{
  condominio: Building;
  immobile: Immobile;
  anagrafiche: Anagrafica[];
}>()

const { generatePath, generateRoute } = usePermission();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, href: '#' },
  { title: 'immobili', href: generatePath('gestionale/:condominio/immobili', { condominio: props.condominio.id }) },
  { title: props.immobile.nome, href: generatePath('gestionale/:condominio/immobili/:immobile', { condominio: props.condominio.id, immobile: props.immobile.id }) },
  { title: 'associa anagrafica', href: '#' },
]);

type tipologia = {
  label: string;
  id: string;
};

const tipologia = [
  {
      label: 'Proprietario',
      id: 'proprietario',
  },
  {
      label: "Inquilino",
      id: 'inquilino',
  },
  {
      label: "Usufruttuario",
      id: 'usufruttuario',
  }
];

const form = useForm({
  tipologia: '',
  data_inizio: '',
  data_fine: '',
  quota: '',
  note: '',
  anagrafica_id: '',

});

const submit = () => {
    form.post(route(...generateRoute('gestionale.immobili.anagrafiche.store', { condominio: props.condominio.id, immobile: props.immobile.id })), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
        }
    });
};

</script>

<template>

    <Head title="Associa anagrafica immobile" />

    <GestionaleLayout :breadcrumbs="breadcrumbs">

      <ImmobileLayout>

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
                :href="generatePath('gestionale/:condominio/immobili/:immobile/anagrafiche', { condominio: props.condominio.id, immobile: props.immobile.id })"
                class="w-full lg:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90"
              >
                <List class="w-4 h-4" />
                <span>Elenco</span>
              </Link>
            </div>

            <Separator class="my-4" />

            <div class="bg-white dark:bg-muted rounded space-y-4 mt-3" >

              <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
  
                <div class="sm:col-span-2">
                  <Label for="tipologia">Tipologia</Label>
                  <v-select
                    class="w-full"
                    :options="tipologia"
                    label="label"
                    v-model="form.tipologia"
                    :reduce="(d: tipologia) => d.id"
                    placeholder="Seleziona tipologia"
                  />
                  <InputError :message="form.errors.tipologia" />
                </div>

              </div>

              <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                <div class="sm:col-span-4">
                  <Label for="tipologia">Anagrafica</Label>
                  <v-select
                    class="w-full"
                    :options="anagrafiche"
                    v-model="form.anagrafica_id"
                    :reduce="(d: Anagrafica) => d.id"
                    label="nome"
                    placeholder="Seleziona anagrafica"
                  >
                    <!-- Dropdown options: stacked layout -->
                    <!-- @ts-ignore -->
                    <template #option="{ nome, indirizzo }">
                      <div class="flex flex-col">
                        <span class="font-medium">{{ nome }}</span>
                        <span class="text-sm text-gray-500">{{ indirizzo }}</span>
                      </div>
                    </template>

                    <!-- Selected option: single-line layout -->
                    <!-- @ts-ignore -->
                    <template #selected-option="{ nome, indirizzo }">
                      <div class="flex items-center gap-2">
                        <span class="font-medium">{{ nome }}</span>
                        <span class="text-gray-500 text-sm">– {{ indirizzo }}</span>
                      </div>
                    </template>
                  </v-select>

                  <InputError :message="form.errors.anagrafica_id" />
                </div>

                <div class="sm:col-span-2">
                    <Label for="quota">Quota</Label>
                    <Input
                        id="quota" 
                        placeholder="Quota anagrafica" 
                        v-model="form.quota" 
                        v-on:focus="form.clearErrors('quota')"
                    />

                    <InputError :message="form.errors.quota" />
                </div>

              </div>

              <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                <div class="sm:col-span-3">
                  <Label for="data_nascita">Data inizio</Label>
                  <VueDatePicker
                    v-model="form.data_inizio"
                    class="w-full"
                    format="dd/MM/yyyy"
                    locale="it"
                    :enable-time-picker="false"
                    auto-apply
                    placeholder="Data inizio"
                  />
                  <InputError :message="form.errors.data_inizio" />
                </div>

                <div class="sm:col-span-3">
                  <Label for="data_nascita">Data fine</Label>
                  <VueDatePicker
                    v-model="form.data_fine"
                    class="w-full"
                    format="dd/MM/yyyy"
                    locale="it"
                    :enable-time-picker="false"
                    auto-apply
                    placeholder="Data fine"
                  />
                  <InputError :message="form.errors.data_fine" />
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

                    <InputError :message="form.errors.note" />
                  </div>

              </div>

            </div>

          </form>

      </ImmobileLayout>

    </GestionaleLayout>

  </template>

  <style src="vue-select/dist/vue-select.css"></style>