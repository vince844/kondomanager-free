<script setup lang="ts">

import { Head, useForm, Link } from '@inertiajs/vue3'
import { ref, watch, computed } from 'vue'
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import Heading from '@/components/Heading.vue'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import InputError from '@/components/InputError.vue'
import { Checkbox } from '@/components/ui/checkbox'
import { HoverCard, HoverCardTrigger, HoverCardContent } from '@/components/ui/hover-card'
import { Plus, LoaderCircle, Info, List } from 'lucide-vue-next'
import vSelect from 'vue-select'
import axios from 'axios'
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

// Ricorrenze
const showRecurrence = ref(false)

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
  tipo: 'ordinario',
  metodo_calcolo: 'tabella',
  numero_rate: 12,
  giorno_scadenza: 5,
  data_inizio: '',
  note: '',

  // RRULE
  recurrence_enabled: false,
  recurrence_frequency: 'MONTHLY',
  recurrence_interval: 1,
  recurrence_by_day: [],
  recurrence_until: ''
})

watch(showRecurrence, (enabled) => {
  form.recurrence_enabled = enabled
  if (!enabled) {
    form.recurrence_by_day = []
    form.recurrence_until = ''
  }
})

const submit = () => {
  form.post(route(generateRoute('esercizi.piani-rate.store'), {
    condominio: props.condominio.id,
    esercizio: props.esercizio.id
  }), {
    preserveScroll: true,
    onSuccess: () => form.reset()
  })
}
</script>

<template>
  <Head title= "Crea nuovo piano rate"  />

  <GestionaleLayout>
    <div class="px-4 py-6 max-w-6xl mx-auto">
      <Heading
        :title="`Nuovo piano rate - ${condominio.nome}`" 
        description="Definisci il piano rate per una specifica gestione, con eventuale ricorrenza automatica."
      />

      <form @submit.prevent="submit" class="space-y-4">
        <!-- Buttons -->
        <div class="flex flex-col lg:flex-row lg:justify-end gap-2">
          <Button :disabled="form.processing" class="h-8 w-full lg:w-auto">
            <Plus v-if="!form.processing" class="w-4 h-4" />
            <LoaderCircle v-else class="w-4 h-4 animate-spin" />
            <span>Salva piano rate</span>
          </Button>

          <Link
            as="button"
            :href="generatePath('gestionale/:condominio/esercizi/:esercizio/piani-rate', { condominio: props.condominio.id, esercizio: props.esercizio.id })"
            class="inline-flex items-center justify-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90 w-full lg:w-auto"
          >
            <List class="w-4 h-4" />
            <span>Elenco piani</span>
          </Link>
        </div>

        <!-- Main Form -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
          <!-- Colonna sinistra -->
          <div class="lg:col-span-2 space-y-4 bg-white p-4 border rounded">
            <!-- Gestione -->
            <div>
              <Label for="gestione_id">Gestione</Label>
              <v-select
                id="gestione_id"
                :options="gestioni"
                label="nome"
                v-model="form.gestione_id"
                :reduce="(opt: Gestione) => opt.id"
                placeholder="Seleziona gestione"
              />
              <InputError :message="form.errors.gestione_id" />
            </div>

            <!-- Nome -->
            <div>
              <Label for="nome">Nome piano rate</Label>
              <Input id="nome" v-model="form.nome" placeholder="Es. Piano Ordinario 2025" />
              <InputError :message="form.errors.nome" />
            </div>

            <!-- Descrizione -->
            <div>
              <Label for="descrizione">Descrizione</Label>
              <Textarea id="descrizione" v-model="form.descrizione" class="min-h-[100px]" />
              <InputError :message="form.errors.descrizione" />
            </div>

            <!-- Tipo e Metodo -->
            <div class="grid grid-cols-2 gap-3">
              <div>
                <Label>Tipo gestione</Label>
                <v-select
                  :options="[
                    { label: 'Ordinario', value: 'ordinario' },
                    { label: 'Straordinario', value: 'straordinario' },
                    { label: 'Anticipo', value: 'anticipo' },
                    { label: 'Conguaglio', value: 'conguaglio' }
                  ]"
                  label="label"
                  v-model="form.tipo"
                  :reduce="(opt: {label: string, value: string}) => opt.value"
                />
                <InputError :message="form.errors.tipo" />
              </div>

              <div>
                <Label>Metodo di calcolo</Label>
                <v-select
                  :options="[
                    { label: 'Proporzionale', value: 'proporzionale' },
                    { label: 'Per Tabella', value: 'tabella' },
                    { label: 'Manuale', value: 'manuale' }
                  ]"
                  label="label"
                  v-model="form.metodo_calcolo"
                  :reduce="(opt: {label: string, value: string}) => opt.value"
                />
                <InputError :message="form.errors.metodo_calcolo" />
              </div>
            </div>

            <!-- Numero rate e giorno -->
            <div class="grid grid-cols-2 gap-3">
              <div>
                <Label>Numero rate</Label>
                <Input type="number" min="1" max="24" v-model="form.numero_rate" />
                <InputError :message="form.errors.numero_rate" />
              </div>
              <div>
                <Label>Giorno scadenza</Label>
                <Input type="number" min="1" max="31" v-model="form.giorno_scadenza" />
                <InputError :message="form.errors.giorno_scadenza" />
              </div>
            </div>

            <!-- Data inizio -->
            <div>
              <Label>Data inizio gestione rate</Label>
              <Input type="date" v-model="form.data_inizio" />
              <InputError :message="form.errors.data_inizio" />
            </div>

            <!-- Note -->
            <div>
              <Label>Note</Label>
              <Textarea v-model="form.note" placeholder="Note interne o aggiuntive" />
            </div>

            <!-- Ricorrenza -->
            <div class="mt-6 flex items-center gap-2">
              <Checkbox id="recurrenceToggle" v-model:checked="showRecurrence" />
              <Label for="recurrenceToggle">Imposta ricorrenza automatica (RRULE)</Label>
              <HoverCard>
                <HoverCardTrigger as-child>
                  <Info class="w-4 h-4 text-muted-foreground cursor-pointer" />
                </HoverCardTrigger>
                <HoverCardContent class="w-80">
                  <p class="text-sm">
                    Se attivi questa opzione, potrai generare automaticamente le date di scadenza delle rate
                    secondo una regola di ricorrenza (es. ogni 5 del mese).
                  </p>
                </HoverCardContent>
              </HoverCard>
            </div>

            <div v-if="showRecurrence" class="mt-4 space-y-4">
              <div class="grid grid-cols-2 gap-3">
                <div>
                  <Label>Frequenza</Label>
                  <v-select
                    :options="frequencies"
                    label="label"
                    v-model="form.recurrence_frequency"
                    :reduce="(opt: any) => opt.value"
                  />
                </div>
                <div>
                  <Label>Intervallo</Label>
                  <Input type="number" min="1" v-model="form.recurrence_interval" />
                </div>
              </div>

              <div>
                <Label>Giorni specifici</Label>
                <div class="grid grid-cols-3 gap-2 mt-2">
                  <div v-for="day in weekdays" :key="day.value" class="flex items-center gap-2">
                    <input type="checkbox" :id="day.value" :value="day.value" v-model="form.recurrence_by_day" />
                    <label :for="day.value">{{ day.label }}</label>
                  </div>
                </div>
              </div>

              <div>
                <Label>Ripeti fino al</Label>
                <Input type="date" v-model="form.recurrence_until" />
              </div>
            </div>
          </div>

          <!-- Colonna destra -->
          <div class="space-y-4 bg-white p-4 border rounded">
            <div class="flex items-center gap-2">
              <Info class="w-4 h-4 text-muted-foreground" />
              <p class="text-sm text-muted-foreground">
                Dopo aver salvato, potrai generare automaticamente le rate e visualizzarle per ogni anagrafica o immobile.
              </p>
            </div>
          </div>
        </div>
      </form>
    </div>
  </GestionaleLayout>
</template>

<style src="vue-select/dist/vue-select.css"></style>
