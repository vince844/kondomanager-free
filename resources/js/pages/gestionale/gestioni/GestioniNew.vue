<script setup lang="ts">

import { computed } from 'vue';
import { Link, Head, useForm } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import { usePermission } from "@/composables/permissions";
import CondominioDropdown from '@/components/CondominioDropdown.vue';
import EsercizioDropdown from '@/components/EsercizioDropdown.vue';
import Heading from '@/components/Heading.vue';
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
import type { BreadcrumbItem } from '@/types';
import type { DropdownType } from '@/types/dropdown';
import { Esercizio } from '@/types/gestionale/esercizi';

const props = defineProps<{
  condominio: Building;
  condomini: Building[];
  esercizio: Esercizio
}>()

const { generatePath, generateRoute } = usePermission();
const { toBackend } = useDateConverter();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, component: "condominio-dropdown" } as any,
  { title: 'gestioni', href: generatePath('gestionale/:condominio/esercizi/:esercizio/gestioni', { condominio: props.condominio.id, esercizio: props.esercizio.id }) },
  { title: 'crea gestione', href: '#' },
]);

const tipologie = [
  {
      label: 'Ordinaria',
      id: 'ordinaria',
  },
  {
      label: "Straordinaria",
      id: 'straordinaria',
  }
];

const form = useForm({
  nome: '',
  descrizione: '',
  note: '',
  data_inizio: '',
  data_fine: '',
  tipo: 'ordinaria',
});

const submit = () => {

    form.data_inizio = toBackend(form.data_inizio);
    form.data_fine   = toBackend(form.data_fine);

    form.post(route(...generateRoute('gestionale.esercizi.gestioni.store', { condominio: props.condominio.id, esercizio: props.esercizio.id })), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
        }
    });
};

</script>

<template>

    <Head title="Crea nuova gestione" />

    <GestionaleLayout :breadcrumbs="breadcrumbs">

        <template #breadcrumb-condominio>
            <CondominioDropdown :condominio="props.condominio" :condomini="props.condomini" />
        </template>

        <div class="px-4 py-6">

            <Heading 
                :title="`Crea nuova gestione per l'esercizio - ${props.esercizio.nome.toLowerCase()}`" 
                :description="`Compila il seguente modulo per la creazione di una nuova gestione per il corrente esercizio aperto - ${props.esercizio.nome.toLowerCase()}`"
            />

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
                            :href="generatePath('gestionale/:condominio/esercizi/:esercizio/gestioni', { condominio: props.condominio.id, esercizio: props.esercizio.id })"
                            class="w-full lg:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90"
                        >
                            <List class="w-4 h-4" />
                            <span>Gestioni</span>
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
                                label="label" 
                                class="block w-full"
                                v-model="form.tipo"
                                placeholder="Tipologia gestione"
                                @update:modelValue="form.clearErrors('tipo')" 
                                :reduce="(d: DropdownType) => d.id"
                            />
                            <InputError :message="form.errors.tipo" />
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
                                    @update:modelValue="form.clearErrors('data_inizio')" 
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
                                    @update:modelValue="form.clearErrors('data_fine')"
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