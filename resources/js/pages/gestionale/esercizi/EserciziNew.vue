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
import vSelect from "vue-select";
import VueDatePicker from '@vuepic/vue-datepicker';
import '@vuepic/vue-datepicker/dist/main.css';
import type { Building } from '@/types/buildings';
import type { BreadcrumbItem } from '@/types';

const props = defineProps<{
  condominio: Building;
}>()

const { generatePath, generateRoute } = usePermission();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, href: '#' },
  { title: 'esercizi', href: generatePath('gestionale/:condominio/esercizi', { condominio: props.condominio.id }) },
  { title: 'crea esercizio', href: '#' },
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
  nome: '',
  descrizione: '',
  note: '',
  data_inizio: '',
  data_fine: '',
  stato: 'aperto',
});

const submit = () => {
    form.post(route(...generateRoute('gestionale.esercizi.store', { condominio: props.condominio.id })), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
        }
    });
};

</script>

<template>

    <Head title="Crea nuovo esercizio" />

    <GestionaleLayout :breadcrumbs="breadcrumbs">

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