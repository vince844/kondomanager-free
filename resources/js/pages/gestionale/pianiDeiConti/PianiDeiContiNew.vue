<script setup lang="ts">

import { computed } from 'vue';
import { Link, Head, useForm } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import { usePermission } from "@/composables/permissions";
import CondominioDropdown from '@/components/CondominioDropdown.vue';
import EsercizioDropdown from "@/components/EsercizioDropdown.vue";
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
import type { Gestione } from '@/types/gestionale/gestioni';
import type { Esercizio } from "@/types/gestionale/esercizi";

const props = defineProps<{
  condominio: Building;
  esercizio: Esercizio;
  esercizi: Esercizio[],
  condomini: Building[];
  gestioni: Gestione[]
}>()

const { generatePath, generateRoute } = usePermission();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, component: "condominio-dropdown" } as any,
  { title: props.esercizio?.nome, component: "esercizio-dropdown" } as any,
  { title: 'crea piano conti', href: '#' },
]);

const form = useForm({
  nome: '',
  descrizione: '',
  note: '',
  gestione_id: ''
});

const submit = () => {
    
    form.post(route(...generateRoute('gestionale.esercizi.conti.store', { condominio: props.condominio.id, esercizio: props.esercizio.id })), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
        }
    });
};

</script>

<template>

    <Head title="Crea nuovo piano conti" />

    <GestionaleLayout :breadcrumbs="breadcrumbs">

        <template #breadcrumb-condominio>
            <CondominioDropdown :condominio="props.condominio" :condomini="props.condomini" />
        </template>

        <template #breadcrumb-esercizio>
            <EsercizioDropdown
                :condominio="props.condominio"
                :esercizio="props.esercizio"
                :esercizi="props.esercizi"
            />
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
                            :href="generatePath('gestionale/:condominio/conti', { condominio: props.condominio.id })"
                            class="w-full lg:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90"
                        >
                            <List class="w-4 h-4" />
                            <span>Piani dei conti</span>
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

                              <div class="sm:col-span-3">
                                 <Label for="gestione">Gestione</Label>

                                <v-select 
                                    :options="gestioni" 
                                    label="nome" 
                                     class="mt-1 block w-full"
                                    v-model="form.gestione_id"
                                    placeholder="Seleziona una gestione"
                                    @update:modelValue="form.clearErrors('gestione_id')" 
                                    :reduce="(gestioni: Gestione) => gestioni.id"
                                />

                                <InputError :message="form.errors.gestione_id" />
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