<script setup lang="ts">

import { Head, useForm, Link } from '@inertiajs/vue3'
import { ref, watch, computed } from 'vue'
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import InputError from '@/components/InputError.vue'
import { Checkbox } from '@/components/ui/checkbox'
import { Separator } from '@/components/ui/separator';
import { Info, Plus, LoaderCircle, List } from 'lucide-vue-next'
import vSelect from 'vue-select'
import axios from 'axios';
import { usePermission } from '@/composables/permissions'
import type { Building } from '@/types/buildings'
import type { Esercizio } from '@/types/gestionale/esercizi'
import type { Gestione } from '@/types/gestionale/gestioni'

const props = defineProps<{
  condominio: Building
  esercizio: Esercizio
  gestioni: Gestione[]
}>()

const { generateRoute, generatePath } = usePermission()

// Toggle ricorrenza
const showRecurrence = ref(false)
// Stato
const capitoliDisponibili = ref([]);
const isLoadingCapitoli = ref(false);

const frequencies = [
  { label: 'Mensile', value: 'MONTHLY' },
  { label: 'Settimanale', value: 'WEEKLY' },
  { label: 'Annuale', value: 'YEARLY' }
]

const weekdays = [
  { label: 'Lunedì', value: 'MO' },
  { label: 'Martedì', value: 'TU' },
  { label: 'Mercoledì', value: 'WE' },
  { label: 'Giovedì', value: 'TH' },
  { label: 'Venerdì', value: 'FR' },
  { label: 'Sabato', value: 'SA' },
  { label: 'Domenica', value: 'SU' }
]

// Form
const form = useForm({
  gestione_id: '',
  nome: '',
  descrizione: '',
  metodo_distribuzione: 'prima_rata',
  numero_rate: 12,
  giorno_scadenza: 5,
  note: '',
  genera_subito: true,
  recurrence_enabled: false,
  recurrence_frequency: 'MONTHLY',
  recurrence_interval: 1,
  recurrence_by_day: [],
  capitoli_ids: [],
})

// Watcher: Quando cambia gestione_id, carica i capitoli
watch(() => form.gestione_id, async (newGestioneId) => {
  // 1. Reset
  form.capitoli_ids = [];
  capitoliDisponibili.value = [];
  
  if (!newGestioneId) return;

  // 2. Fetch
  isLoadingCapitoli.value = true;
  try {
    const response = await axios.get(route('admin.gestionale.fetch-capitoli-gestione', {
      condominio: props.condominio.id
    }), {
      params: { gestione_id: newGestioneId }
    });
    
    capitoliDisponibili.value = response.data;
    
  } catch (error) {
    console.error("Errore caricamento capitoli:", error);
  } finally {
    isLoadingCapitoli.value = false;
  }
});

// Sincronizzazione ricorrenza
watch(showRecurrence, (enabled) => {
  form.recurrence_enabled = enabled

  if (!enabled) {
    form.recurrence_by_day = []
  }
})

// Se si usano BYDAY → ignora giorno_scadenza
const usingByDay = computed(() =>
  showRecurrence.value &&
  Array.isArray(form.recurrence_by_day) &&
  form.recurrence_by_day.length > 0
)

const submit = () => {

  form.post(route(...generateRoute(
    'gestionale.esercizi.piani-rate.store',
    { condominio: props.condominio.id, esercizio: props.esercizio.id }
  )), {
    preserveScroll: true,
    onSuccess: () => form.reset()
  })

}
</script>

<template>
  <Head title="Crea nuovo piano rate" />

  <GestionaleLayout>

    <div class="px-4 py-6">

      <div class="w-full shadow ring-1 ring-black/5 md:rounded-lg p-4">

        <section class="w-full">
          <form class="space-y-2" @submit.prevent="submit">

            <!-- Action buttons -->
            <div class="flex flex-col lg:flex-row lg:justify-end gap-2 w-full">
              <Button :disabled="form.processing" class="h-8 w-full lg:w-auto">
                <Plus class="w-4 h-4" v-if="!form.processing" />
                <LoaderCircle v-else class="h-4 w-4 animate-spin" />
                Salva piano rate
              </Button>

              <Link
                as="button"
                :href="generatePath('gestionale/:condominio/esercizi/:esercizio/piani-rate', {
                  condominio: condominio.id,
                  esercizio: esercizio.id
                })"
                class="w-full lg:w-auto inline-flex items-center justify-center gap-2 rounded-md 
                       bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90"
              >
                <List class="w-4 h-4" />
                <span>Elenco piani</span>
              </Link>
            </div>

            <Separator class="my-4" />

            <!-- FORM BOX -->
            <div class="bg-white dark:bg-muted rounded space-y-6 mt-3 p-4">

              <!-- Nome + Gestione -->
              <div class="grid grid-cols-1 sm:grid-cols-6 gap-y-6 gap-x-4">
                <div class="sm:col-span-3">
                  <Label for="nome">Nome piano rate</Label>
                  <Input
                    id="nome"
                    class="mt-1 block w-full"
                    v-model="form.nome"
                    placeholder="Es. Piano rate ordinario"
                  />
                  <InputError :message="form.errors.nome" />
                </div>

                <div class="sm:col-span-3">
                  <Label for="gestione_id">Gestione</Label>
                  <v-select
                    class="mt-1 block w-full"
                    id="gestione_id"
                    :options="gestioni"
                    label="nome"
                    v-model="form.gestione_id"
                    placeholder="Seleziona gestione"
                    :reduce="(g: Gestione) => g.id"
                  />
                  <InputError :message="form.errors.gestione_id" />
                </div>
              </div>

              <div class="sm:col-span-6">
                  <Label>Filtra per Capitoli di Spesa (Opzionale)</Label>
                  <div v-if="isLoadingCapitoli" class="text-sm text-gray-500 flex items-center gap-2">
                      <LoaderCircle class="w-3 h-3 animate-spin" /> Caricamento voci di spesa...
                  </div>
                  
                  <v-select
                      v-else
                      multiple
                      v-model="form.capitoli_ids"
                      :options="capitoliDisponibili"
                      label="nome"
                      :reduce="c => c.id"
                      placeholder="Lascia vuoto per includere TUTTE le spese della gestione"
                      class="mt-1 block w-full"
                  >
                      <template #no-options>
                          Nessun capitolo di spesa trovato per questa gestione.
                      </template>
                  </v-select>
                  
                  <p class="text-[11px] text-gray-500 mt-1">
                      Seleziona specifiche voci (es. "Scala A", "Riscaldamento") se vuoi creare un piano rate parziale.
                      Se lasci vuoto, verrà creato un piano unico per l'intera gestione.
                  </p>
                  <InputError :message="form.errors.capitoli_ids" />
              </div>

              <!-- Descrizione -->
              <div>
                <Label for="descrizione">Descrizione</Label>
                <Textarea
                  id="descrizione"
                  class="mt-1 block w-full min-h-[100px]"
                  v-model="form.descrizione"
                />
                <InputError :message="form.errors.descrizione" />
              </div>

              <!-- Info timeline -->
              <div class="bg-blue-50 p-3 rounded border border-blue-200 flex items-start gap-2">
                <Info class="w-4 h-4 text-blue-600 mt-1" />
                <p class="text-sm text-blue-700">
                  Le rate partiranno automaticamente dalla <strong>data di inizio della gestione selezionata</strong>.
                </p>
              </div>
 
              <div class="grid grid-cols-1 sm:grid-cols-6 gap-y-6 gap-x-4">
                <!-- Distribuzione -->
                <div class="sm:col-span-3">
                  <Label>Distribuzione saldo iniziale</Label>
                  <v-select
                    class="mt-1 block w-full"
                    :options="[
                      { label: 'Tutto sulla prima rata', value: 'prima_rata' },
                      { label: 'Distribuito su tutte le rate', value: 'tutte_rate' }
                    ]"
                    v-model="form.metodo_distribuzione"
                    :reduce="(opt) => opt.value"
                  />
                  <InputError :message="form.errors.metodo_distribuzione" />
                </div>
              </div>

              <!-- Numero rate + giorno scadenza -->
              <div class="grid grid-cols-1 sm:grid-cols-6 gap-y-6 gap-x-4">

                <div class="sm:col-span-3">
                  <Label>Numero rate</Label>
                  <Input v-model.number="form.numero_rate" class="mt-1 block w-full" />
                  <InputError :message="form.errors.numero_rate" />
                </div>

                <div class="sm:col-span-3" v-if="!usingByDay">
                  <Label>Giorno scadenza</Label>
                  <Input v-model.number="form.giorno_scadenza" class="mt-1 block w-full" />
                  <InputError :message="form.errors.giorno_scadenza" />
                </div>
              </div>

              <!-- Genera Subito -->
              <div class="flex items-center gap-2">
                <Checkbox id="genera_subito" v-model="form.genera_subito" />
                <Label for="genera_subito">Genera subito il piano rate dopo il salvataggio</Label>
              </div>

              <!-- Ricorrenza -->
              <div class="space-y-4">

                <div class="flex items-center gap-2">
                  <Checkbox id="recurrenceToggle" v-model="showRecurrence" />
                  <Label for="recurrenceToggle">Ricorrenza automatica avanzata</Label>
                </div>

                <div v-if="showRecurrence" class="space-y-4">

                  <div class="grid grid-cols-1 sm:grid-cols-6 gap-4">
                    <div class="sm:col-span-3">
                      <Label>Frequenza</Label>
                      <v-select
                        :options="frequencies"
                        v-model="form.recurrence_frequency"
                        :reduce="o => o.value"
                        class="mt-1 block w-full"
                      />
                    </div>

                    <div class="sm:col-span-3">
                      <Label>Intervallo</Label>
                      <Input type="number" min="1" v-model="form.recurrence_interval" class="mt-1 block w-full" />
                    </div>
                  </div>

                  <!-- Giorni specifici -->
                  <div>
                    <Label>Giorni specifici</Label>
                    <div class="grid grid-cols-3 gap-2 mt-2">
                      <div v-for="day in weekdays" :key="day.value" class="flex items-center gap-2">
                        <input type="checkbox" :value="day.value" v-model="form.recurrence_by_day" />
                        <label>{{ day.label }}</label>
                      </div>
                    </div>
                    <InputError :message="form.errors.recurrence_by_day" />
                  </div>

                </div>
              </div>

              <!-- Note -->
              <div class="border-t pt-4">
                <Label for="note">Note aggiuntive</Label>
                <Textarea id="note" v-model="form.note" class="mt-1 block w-full" />
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
