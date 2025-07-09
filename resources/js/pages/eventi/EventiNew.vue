<script setup lang="ts">

import { Head, useForm, Link } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import Heading from '@/components/Heading.vue';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Textarea } from '@/components/ui/textarea';
import { LoaderCircle, Plus, List } from 'lucide-vue-next';
import vSelect from "vue-select";
import axios from 'axios';
import { usePermission } from "@/composables/permissions";
import type { Building } from '@/types/buildings';
import type { Anagrafica } from '@/types/anagrafiche';
import type { CategoriaEvento } from '@/types/categorie-eventi';


const { generatePath, generateRoute } = usePermission();

const props = defineProps<{
  condomini: Building[];
  anagrafiche: Anagrafica[];
  categorie: CategoriaEvento[];
}>();

const anagraficheOptions = ref<Anagrafica[]>([]);

const frequencies = [
  { label: 'Giornaliera', value: 'daily' },
  { label: 'Settimanale', value: 'weekly' },
  { label: 'Mensile', value: 'monthly' },
  { label: 'Annuale', value: 'yearly' },
];

const weekdays = [
  { label: 'Luned\u00ec', value: 'MO' },
  { label: 'Marted\u00ec', value: 'TU' },
  { label: 'Mercoled\u00ec', value: 'WE' },
  { label: 'Gioved\u00ec', value: 'TH' },
  { label: 'Venerd\u00ec', value: 'FR' },
  { label: 'Sabato', value: 'SA' },
  { label: 'Domenica', value: 'SU' },
];

const form = useForm({
  title: '',
  description: '',
  start_time: '',
  end_time: '',
  note: '',
  recurrence_frequency: null,
  recurrence_interval: 1,
  recurrence_by_day: [],
  recurrence_until: null,
  category_id: '',
  condomini_ids: [],
  anagrafiche: []
});

const fetchAnagrafiche = async (condomini_ids: number[]) => {
  try {
    const response = await axios.get(generatePath('fetch-anagrafiche'), {
      params: { condomini_ids },
    });
    form.anagrafiche = [];
    anagraficheOptions.value = response.data.map((item: { id: number, nome: string }) => ({
      id: item.id,
      nome: item.nome,
    }));
  } catch (error) {
    console.error('Error fetching anagrafiche:', error);
  }
};

watch(() => form.condomini_ids, fetchAnagrafiche);

const submit = () => {
  form.post(route(generateRoute('eventi.store')), {
    preserveScroll: true,
    onSuccess: () => form.reset()
  });
};
</script>

<template>
  <Head title="Crea nuovo evento" />

  <AppLayout>
    <div class="px-4 py-6">
      <Heading title="Crea scadenza in agenda" description="Compila il seguente modulo per la creazione di una nuova scadenza" />

      <form class="space-y-2" @submit.prevent="submit">
        <div class="flex justify-end gap-2">
          <Button :disabled="form.processing">
            <Plus v-if="!form.processing" class="w-4 h-4" />
            <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
            Salva
          </Button>
          <Link :href="route('admin.comunicazioni.index')" class="inline-flex items-center bg-primary text-white px-3 py-1.5 rounded">
            <List class="w-4 h-4" /> Elenco
          </Link>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-4 gap-3">
          <!-- Main Form -->
          <div class="lg:col-span-3 space-y-4 bg-white p-4 border rounded">

            <div>
              <Label for="title">Oggetto</Label>
              <Input id="title" v-model="form.title" @focus="form.clearErrors('title')" />
              <InputError :message="form.errors.title" />
            </div>

            <div>
              <Label for="description">Descrizione</Label>
              <Textarea id="description" v-model="form.description" class="min-h-[120px]" @focus="form.clearErrors('description')" />
              <InputError :message="form.errors.description" />
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div>
                <Label>Inizio</Label>
                <Input type="datetime-local" v-model="form.start_time" />
                <InputError :message="form.errors.start_time" />
              </div>
              <div>
                <Label>Fine</Label>
                <Input type="datetime-local" v-model="form.end_time" />
                <InputError :message="form.errors.end_time" />
              </div>
            </div>

            <div>
              <Label>Ricorrenza</Label>
              <div class="grid grid-cols-2 gap-4">
                <v-select :options="frequencies" label="label" v-model="form.recurrence_frequency" :reduce="opt => opt.value" placeholder="Frequenza" />
                <Input type="number" min="1" v-model="form.recurrence_interval" placeholder="Intervallo" />
              </div>

              <div class="mt-4">
                <Label>Giorni specifici</Label>
                <div class="grid grid-cols-3 gap-2 mt-2">
                  <div v-for="day in weekdays" :key="day.value" class="flex items-center gap-2">
                    <input
                      type="checkbox"
                      :id="day.value"
                      :value="day.value"
                      v-model="form.recurrence_by_day"
                    />
                    <label :for="day.value">{{ day.label }}</label>
                  </div>
                </div>
              </div>

              <div class="mt-4">
                <Label>Ripeti fino al</Label>
                <Input type="datetime-local" v-model="form.recurrence_until" />
              </div>

                  <!--  Note -->
              <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                <div class="sm:col-span-6">
                  <Label for="note">Note aggiuntive</Label>
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
          </div>

          <!-- Side Form -->
          <div class="space-y-4 bg-white p-4 border rounded">

            <div>
              <Label>Categorie</Label>

                  <v-select
                    :options="categorie"
                    label="name"
                    v-model="form.category_id"
                    :reduce="(option: CategoriaEvento) => option.id"
                    placeholder="Seleziona categoria"
                    class="flex-1"
                    @update:modelValue="form.clearErrors('category_id')" 
                  />

              <InputError :message="form.errors.category_id" />
            </div>

            <div>
              <Label>Condomini</Label>
              <v-select 
                multiple 
                :options="condomini" 
                label="nome" 
                v-model="form.condomini_ids" 
                :reduce="(option: Building) => option.id"
                placeholder="Seleziona condomini" 
              />
              <InputError :message="form.errors.condomini_ids" />
            </div>

            <div>
              <Label>Anagrafiche</Label>
              <v-select 
                multiple 
                :options="anagraficheOptions" 
                label="nome" 
                v-model="form.anagrafiche" 
                :reduce="(option: Anagrafica) => option.id"
                :disabled="form.condomini_ids.length === 0" 
                placeholder="Seleziona anagrafiche" 
              />
              <InputError :message="form.errors.anagrafiche" />
            </div>
          </div>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<style src="vue-select/dist/vue-select.css"></style>
