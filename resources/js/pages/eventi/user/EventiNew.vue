<script setup lang="ts">

import { Head, useForm, Link } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import Heading from '@/components/Heading.vue';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import { Textarea } from '@/components/ui/textarea';
import { LoaderCircle, Plus, List, Info } from 'lucide-vue-next';
import vSelect from "vue-select";
import { usePermission } from "@/composables/permissions";
import type { Building } from '@/types/buildings';
import type { CategoriaEvento } from '@/types/categorie-eventi';

const { generateRoute } = usePermission();

const props = defineProps<{
  condomini: Building[];
  categorie: CategoriaEvento[];
}>();

const showRecurrence = ref(false);

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
});

watch(showRecurrence, (enabled) => {
  if (!enabled) {
    form.recurrence_frequency = null;
    form.recurrence_interval = 1;
    form.recurrence_by_day = [];
    form.recurrence_until = null;
  }
});

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
      <Heading title="Crea scadenza in agenda" description="Compila il seguente modulo per la creazione di una nuova scadenza per l'agenda condominiale" />

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
                :href="route(generateRoute('eventi.index'))"
                class="w-full lg:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90"
            >
                <List class="w-4 h-4" />
                <span>Elenco</span>
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

            <div class="flex items-center space-x-2">
              <Checkbox class="size-4"  id="recurrenceToggle" v-model="showRecurrence" />
              <Label for="recurrenceToggle">Imposta evento ricorrente</Label>
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
                          Evento ricorrente
                      </h4>
                      <p class="text-sm">
                          Quando viene selezionata questa opzione verrano abilitati i campi per la configurazione della ricorrenza dell'evento.
                      </p>
                  </div>
                </div>
                </HoverCardContent>
              </HoverCard>

            </div>

            <div v-if="showRecurrence">
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

                 <InputError :message="form.errors.recurrence_until" />
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
                @update:modelValue="form.clearErrors('condomini_ids')" 
                placeholder="Seleziona condomini" 
              />
              <InputError :message="form.errors.condomini_ids" />
            </div>

          </div>

        </div>

      </form>
    </div>
  </AppLayout>
</template>

<style src="vue-select/dist/vue-select.css"></style>
