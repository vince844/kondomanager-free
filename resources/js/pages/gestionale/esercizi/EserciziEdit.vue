<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">

import { computed } from 'vue';
import { Link, Head, useForm } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import { usePermission } from "@/composables/permissions";
import { Button } from '@/components/ui/button';
import { List, Plus, LoaderCircle} from 'lucide-vue-next';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Textarea } from '@/components/ui/textarea';
import { Separator } from '@/components/ui/separator';
import { useDateConverter } from '@/composables/useDateConverter';
import vSelect from "vue-select";
import VueDatePicker from '@vuepic/vue-datepicker';
import '@vuepic/vue-datepicker/dist/main.css';
import type { Building } from '@/types/buildings';
import type { Esercizio } from '@/types/gestionale/esercizi';
import type { BreadcrumbItem } from '@/types';

const props = defineProps<{
  condominio: Building;
  esercizio: Esercizio;
  /** Lo stato del rendiconto dell'esercizio: in bozza, fermato per l'assemblea, approvato. */
  stato_rendiconto?: { stato: 'bozza' | 'presentata' | 'approvata'; frase: string };
}>()

const { generatePath, generateRoute } = usePermission();
const { toBackend } = useDateConverter();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, href: '#' },
  { title: 'esercizi', href: generatePath('gestionale/:condominio/esercizi', { condominio: props.condominio.id }) },
  { title: props.esercizio.nome, href: '#' },
  { title: 'modifica esercizio', href: '#' },
]);

type DocumentType = {
  label: string;
  id: string;
};

const stati = [
  {
      label: 'Aperto',
      id: 'aperto',
  },
  {
      label: "Chiuso",
      id: 'chiuso',
  }
];

const form = useForm({
  nome: props.esercizio.nome,
  descrizione: props.esercizio.descrizione,
  note: props.esercizio.note,
  data_inizio: props.esercizio.data_inizio,
  data_fine: props.esercizio.data_fine,
  stato: props.esercizio.stato,
});

const submit = () => {

    form.data_inizio = toBackend(form.data_inizio);
    form.data_fine   = toBackend(form.data_fine);

    form.put(route(...generateRoute('gestionale.esercizi.update', { condominio: props.condominio.id, esercizio: props.esercizio.id })), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
        }
    });
};

</script>

<template>

    <Head title="Modifica esercizio" />

    <GestionaleLayout :breadcrumbs="breadcrumbs">

        <div class="px-4 py-6">

            <div class="w-full shadow ring-1 ring-black/5 md:rounded-lg p-4">
                <section class="w-full">

                    <p v-if="props.stato_rendiconto" class="mb-3 text-[12.5px] text-slate-600 dark:text-slate-300" data-test="stato-rendiconto">
                        Il rendiconto: {{ props.stato_rendiconto.frase }}
                        <Link :href="generatePath('gestionale/:condominio/esercizi/:esercizio/rendiconto', { condominio: props.condominio.id, esercizio: props.esercizio.id })" class="ml-1 font-semibold underline">Apri il rendiconto</Link>
                    </p>

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
                            :href="generatePath('gestionale/:condominio/esercizi', { condominio: props.condominio.id })"
                            class="w-full lg:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90"
                        >
                            <List class="w-4 h-4" />
                            <span>Esercizi</span>
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
                            <Label for="tipologia">Stato</Label>
                            <v-select 
                                :options="stati" 
                                label="label" 
                                class="block w-full"
                                v-model="form.stato"
                                placeholder="Stato dell'esercizio"
                                @update:modelValue="form.clearErrors('stato')" 
                                :reduce="(d: DocumentType) => d.id"
                            />
                            <InputError :message="form.errors.stato" />
                            </div>

                            <div class="sm:col-span-2">
                                <Label for="data_inizio">Data inizio</Label>
                                <VueDatePicker
                                    v-model="form.data_inizio"
                                    class="w-full"
                                    format="dd/MM/yyyy"
                                    locale="it"
                                    :enable-time-picker="false"
                                    @update:modelValue="form.clearErrors('data_inizio')" 
                                    auto-apply
                                    placeholder="Data inizio"
                                />
                                <InputError :message="form.errors.data_inizio" />
                            </div>

                            <div class="sm:col-span-2">
                                <Label for="data_fine">Data fine</Label>
                                <VueDatePicker
                                    v-model="form.data_fine"
                                    class="w-full"
                                    format="dd/MM/yyyy"
                                    locale="it"
                                    :enable-time-picker="false"
                                    @update:modelValue="form.clearErrors('data_fine')"
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
                            </div>

                            <InputError :message="form.errors.note" />
                    
                        </div>

                        </div>

                    </form>
                    
                </section>
            </div>

        </div>

    </GestionaleLayout>

  </template>

  <style src="vue-select/dist/vue-select.css"></style>