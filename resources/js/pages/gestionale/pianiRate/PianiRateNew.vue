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
import { Plus, LoaderCircle, List, AlertTriangle, CheckCircle, Wallet, Ban, Info} from 'lucide-vue-next'
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import vSelect from 'vue-select'
import axios from 'axios';
import { usePermission } from '@/composables/permissions'
import type { Building } from '@/types/buildings'
import type { Esercizio } from '@/types/gestionale/esercizi'
import type { Gestione } from '@/types/gestionale/gestioni'

// Interfacce allineate al Backend
interface Capitolo {
  id: number;
  nome: string;
  disabled: boolean;
  importo_totale: number; 
  residuo: number;        
  note?: string;
}

interface CapitoloDettaglio {
  id: number;
  nome: string;
  importo_totale: number;
  residuo: number;
  importo_da_usare: number | undefined; 
  note: string;
}

const props = defineProps<{
  condominio: Building
  esercizio: Esercizio
  gestioni: Gestione[]
  saldoInfo: {
    saldo: number
    applicabile: boolean
    motivo: string
    gestione_utilizzatrice?: any
    is_primo_anno?: boolean
    has_movimenti?: boolean 
  }
}>()

const { generateRoute, generatePath } = usePermission()

const showRecurrence = ref(false)
const capitoliDisponibili = ref<Capitolo[]>([]);
const isLoadingCapitoli = ref(false);
const capitoliDettaglio = ref<CapitoloDettaglio[]>([]);

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

const form = useForm({
  gestione_id: '',
  nome: '',
  descrizione: '',
  metodo_distribuzione: null,
  numero_rate: 12,
  giorno_scadenza: 5,
  note: '',
  genera_subito: true,
  recurrence_enabled: false,
  recurrence_frequency: 'MONTHLY',
  recurrence_interval: 1,
  recurrence_by_day: [] as string[], 
  capitoli_ids: [] as number[],
  capitoli_config: [] as any[], 
})

// --- HELPER FUNCTIONS ---
const formatMoney = (val: number) => {
  return new Intl.NumberFormat('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(val);
}

const rimuoviCapitolo = (id: number) => {
  form.capitoli_ids = form.capitoli_ids.filter(cid => cid !== id);
}

const totaleSelezionatoFormatted = computed(() => {
  const tot = capitoliDettaglio.value.reduce((acc, curr) => {
     const val = curr.importo_da_usare || 0;
     return acc + Number(val);
  }, 0);
  return formatMoney(tot);
});

// [MODIFICA] Logica robusta per mostrare il dropdown anche se saldo è 0 ma ci sono movimenti
const mostraDistribuzioneSaldo = computed(() => {
  if (props.saldoInfo.has_movimenti !== undefined) {
      return props.saldoInfo.applicabile && props.saldoInfo.has_movimenti;
  }
  return props.saldoInfo.applicabile && props.saldoInfo.saldo !== 0;
});

// --- LOGICA DI CARICAMENTO ---
watch(() => form.gestione_id, async (newGestioneId) => {
  form.capitoli_ids = [];
  capitoliDettaglio.value = [];
  capitoliDisponibili.value = [];
  
  if (!newGestioneId) return;

  isLoadingCapitoli.value = true;
  try {
    const response = await axios.get(route('admin.gestionale.fetch-capitoli-gestione', {
      condominio: props.condominio.id
    }), { params: { gestione_id: newGestioneId } });
    
    capitoliDisponibili.value = response.data;
  } catch (error) {
    console.error("Errore caricamento capitoli:", error);
  } finally {
    isLoadingCapitoli.value = false;
  }
});

watch(showRecurrence, (enabled) => {
  form.recurrence_enabled = enabled
  if (!enabled) form.recurrence_by_day = []
})

const usingByDay = computed(() =>
  showRecurrence.value &&
  Array.isArray(form.recurrence_by_day) &&
  form.recurrence_by_day.length > 0
)

const toggleDay = (dayValue: string) => {
  const index = form.recurrence_by_day.indexOf(dayValue);
  if (index > -1) {
    form.recurrence_by_day.splice(index, 1);
  } else {
    form.recurrence_by_day.push(dayValue);
  }
}

// --- SINCRONIZZAZIONE SELECT -> DETTAGLIO ---
watch(() => form.capitoli_ids, (newIds) => {
  capitoliDettaglio.value = capitoliDettaglio.value.filter(c => newIds.includes(c.id));
  
  newIds.forEach(id => {
    if (!capitoliDettaglio.value.find(c => c.id === id)) {
      const capOriginale = capitoliDisponibili.value.find(c => c.id === id);
      
      if (capOriginale) {
        const residuo = capOriginale.residuo ?? 0;
        const importoDefault = residuo > 0 ? residuo : 0;

        capitoliDettaglio.value.push({
          id: id,
          nome: capOriginale.nome,
          importo_totale: capOriginale.importo_totale ?? 0,
          residuo: residuo,
          importo_da_usare: importoDefault, 
          note: ''
        });
      }
    }
  });
});

const submit = () => {
  form.capitoli_config = capitoliDettaglio.value.map(c => ({
    id: c.id,
    importo: c.importo_da_usare,
    note: c.note
  }));

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
    <div class="px-4 py-6 w-full">
      <div class="w-full shadow ring-1 ring-black/5 md:rounded-lg p-6 bg-white dark:bg-slate-900">
        
        <form @submit.prevent="submit" class="space-y-6">
          
          <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 border-b pb-4">
            <div>
              <h1 class="text-xl font-bold">Nuovo piano rate</h1>
              <p class="text-sm text-gray-500">Nuovo piano rate per {{ esercizio.nome }}</p>
            </div>
            <div class="flex gap-2">
              <Button :disabled="form.processing" class="h-9">
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
                class="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-white shadow-sm"
              >
                <List class="w-4 h-4" />
                Elenco piani
              </Link>
            </div>
          </div>

          <div v-if="!saldoInfo.is_primo_anno">
            <div v-if="saldoInfo.applicabile" 
                 class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
              <div class="flex items-start gap-3">
                <CheckCircle class="w-5 h-5 text-blue-600 shrink-0" />
                <div>
                  <h4 class="font-bold text-blue-900 dark:text-blue-100 text-sm">Saldi anagrafiche iniziali disponibili</h4>
                  <p class="text-xs text-blue-700 dark:text-blue-300 mt-1">
                    <span v-if="saldoInfo.saldo === 0">
                      Rilevati saldi a debito/credito nelle anagrafiche che verranno applicati.
                    </span>
                    <span v-else>
                      {{ saldoInfo.motivo }}
                    </span>
                  </p>
                </div>
              </div>
            </div>

            <div v-else 
                 class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg p-4">
              <div class="flex items-start gap-3">
                <AlertTriangle class="w-5 h-5 text-amber-600 shrink-0" />
                <div>
                  <h4 class="font-bold text-amber-900 dark:text-amber-100 text-sm">Saldi anagrafiche non disponibili</h4>
                  <p class="text-xs text-amber-700 dark:text-amber-300 mt-1">{{ saldoInfo.motivo }}</p>
                </div>
              </div>
            </div>
          </div>

          <div class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div class="space-y-2">
                <Label for="nome" class="text-sm font-semibold">Nome piano rate</Label>
                <Input id="nome" class="w-full" v-model="form.nome" placeholder="Es. Ordinario 2026" />
                <InputError :message="form.errors.nome" />
              </div>

              <div class="space-y-2">
                <Label for="gestione_id" class="text-sm font-semibold">Gestione</Label>
                <v-select
                  class="w-full v-select-custom"
                  id="gestione_id"
                  :options="gestioni"
                  label="nome"
                  v-model="form.gestione_id"
                  :reduce="g => g.id"
                  placeholder="Seleziona gestione"
                />
                <InputError :message="form.errors.gestione_id" />
              </div>
            </div>

            <div class="space-y-2">
              <Label for="descrizione" class="text-sm font-semibold text-gray-600 dark:text-gray-400">
                Descrizione / Note interne
              </Label>
              <Textarea 
                id="descrizione" 
                class="w-full min-h-[80px] resize-none" 
                v-model="form.descrizione" 
                placeholder="Aggiungi dettagli sul criterio di riparto o note per l'ufficio..."
              />
            </div>
          </div>

          <div class="bg-gray-50 dark:bg-slate-800/50 p-4 rounded-lg border relative transition-all duration-300">
            <div class="flex justify-between items-center mb-2">
                <Label :class="{ 'opacity-50': !form.gestione_id }">Filtra per capitoli di spesa (Opzionale)</Label>
                <span v-if="capitoliDettaglio.length > 0" class="text-xs bg-primary/10 text-primary px-2 py-1 rounded font-medium">
                    {{ capitoliDettaglio.length }} voci selezionate
                </span>
            </div>
            
            <div class="relative">
              <v-select
                  multiple
                  v-model="form.capitoli_ids"
                  :options="capitoliDisponibili"
                  label="nome"
                  :reduce="c => c.id"
                  :disabled="!form.gestione_id || isLoadingCapitoli"
                  :selectable="(option: Capitolo) => !option.disabled" 
                  :placeholder="!form.gestione_id ? 'Seleziona prima una gestione...' : 'Includi tutto il bilancio'"
                  class="w-full v-select-custom"
              >
                  <template #spinner>
                      <LoaderCircle v-if="isLoadingCapitoli" class="w-4 h-4 animate-spin mr-2" />
                  </template>
                  
                  <template #option="option: Capitolo">
                      <div :class="{ 'opacity-50 grayscale': option.disabled }" class="flex justify-between w-full items-center py-1">
                          <div class="flex flex-col">
                              <span :class="{ 'font-bold text-gray-800': option.nome.startsWith('[') }">
                                  {{ option.nome }}
                              </span>
                              <span class="text-[10px] text-gray-500">
                                  Totale: € {{ formatMoney(option.importo_totale || 0) }}
                              </span>
                          </div>
                          
                          <div class="flex items-center">
                              <span v-if="option.disabled" class="flex items-center gap-1 text-[10px] bg-red-100 text-red-600 px-2 py-0.5 rounded-full font-bold uppercase ml-2 border border-red-200">
                                  <Ban class="w-3 h-3"/> Esaurito
                              </span>
                              <span v-else class="flex items-center gap-1 text-[10px] bg-green-100 text-green-700 px-2 py-0.5 rounded-full font-bold ml-2 border border-green-200">
                                  <Wallet class="w-3 h-3"/> Disp: € {{ formatMoney(option.residuo || 0) }}
                              </span>
                          </div>
                      </div>
                  </template>

                  <template #selected-option="option: Capitolo">
                    <div class="flex items-center">
                        <span :class="{ 'font-bold': option.nome.startsWith('[') }">
                            {{ option.nome }}
                        </span>
                    </div>
                </template>
              </v-select>

            </div>
            <p class="text-[11px] text-gray-500 mt-2 italic flex items-center gap-1">
                <span class="w-2 h-2 rounded-full bg-red-500 inline-block"></span> Voci esaurite
                <span class="w-2 h-2 rounded-full bg-green-500 inline-block ml-2"></span> Voci disponibili
            </p>
          </div>

          <div v-if="capitoliDettaglio.length > 0" class="mt-4 border border-gray-200 rounded-lg overflow-hidden animate-in fade-in slide-in-from-top-2 duration-300 shadow-sm">
            <div class="bg-gray-50 px-4 py-3 border-b border-gray-200 flex justify-between items-center">
              <span class="text-xs font-bold text-gray-700 uppercase tracking-wider flex items-center gap-2">
                  <List class="w-4 h-4"/> Ripartizione Budget
              </span>
              <span class="text-sm font-mono font-bold text-primary bg-white px-3 py-1 rounded border shadow-sm">
                  Totale: € {{ totaleSelezionatoFormatted }}
              </span>
            </div>
            
            <div class="divide-y divide-gray-100 max-h-[350px] overflow-y-auto bg-white">
              <div v-for="(cap, index) in capitoliDettaglio" :key="cap.id" class="p-3 hover:bg-gray-50 transition-colors group">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                  
                  <div class="flex-1 min-w-0">
                    <div class="font-medium text-sm text-gray-900 truncate" :title="cap.nome">{{ cap.nome }}</div>
                    <div class="text-xs text-gray-500 mt-1 flex flex-wrap gap-2 items-center">
                      <span class="bg-gray-100 px-1.5 py-0.5 rounded text-gray-600">Tot: € {{ formatMoney(cap.importo_totale) }}</span>
                      <span class="text-gray-300">|</span>
                      <span :class="{'text-green-700 bg-green-50': cap.residuo > 0, 'text-red-600 bg-red-50': cap.residuo <= 0}" class="px-1.5 py-0.5 rounded font-medium">
                        Residuo: € {{ formatMoney(cap.residuo) }}
                      </span>
                    </div>
                  </div>

                  <div class="flex items-center gap-2 shrink-0">
                    
                    <div class="w-32">
                      <Label class="sr-only">Importo</Label>
                      <div class="relative">
                        <span class="absolute left-2 top-1/2 -translate-y-1/2 text-gray-400 text-xs font-bold">€</span>
                        <Input 
                          type="number" 
                          step="0.01" 
                          v-model="cap.importo_da_usare" 
                          class="pl-6 h-9 text-right text-sm font-mono font-bold transition-all"
                          :max="cap.residuo"
                          :class="{
                              'border-red-500 ring-red-200 bg-red-50 text-red-700': (cap.importo_da_usare || 0) > cap.residuo,
                              'border-green-500 ring-green-100': (cap.importo_da_usare || 0) > 0 && (cap.importo_da_usare || 0) <= cap.residuo
                          }"
                        />
                      </div>
                    </div>

                    <div class="w-40 sm:w-56">
                      <Label class="sr-only">Note</Label>
                      <Input 
                        v-model="cap.note" 
                        placeholder="Es. Quota fissa..." 
                        class="h-9 text-xs"
                      />
                    </div>
                    
                    <button @click="rimuoviCapitolo(cap.id)" type="button" class="text-gray-400 hover:text-red-500 hover:bg-red-50 p-2 rounded-full transition-all">
                      <span class="sr-only">Rimuovi</span>
                      <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                      </svg>
                    </button>
                  </div>
                </div>
                
                <div v-if="(cap.importo_da_usare || 0) > cap.residuo" class="text-[11px] text-red-600 mt-2 flex items-center gap-1 font-medium bg-red-50 p-1.5 rounded animate-pulse">
                  <AlertTriangle class="w-3 h-3"/> Attenzione: L'importo supera il residuo disponibile (€ {{ formatMoney(cap.residuo) }}).
                </div>
              </div>
            </div>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-3 gap-6 items-end mb-4">
            
            <div class="relative">
              <Label>Numero rate</Label>
              <Input v-model.number="form.numero_rate" class="mt-1 w-full" />
              <InputError :message="form.errors.numero_rate" class="absolute -bottom-6 left-0 text-[11px]" />
            </div>
            
            <div v-if="!usingByDay" class="relative">
              <Label>Giorno scadenza</Label>
              <Input v-model.number="form.giorno_scadenza" class="mt-1 w-full" />
            </div>
   
            <div v-if="mostraDistribuzioneSaldo" class="relative">
              <div class="flex items-center gap-2">
                <Label>
                  Distribuzione saldo iniziale
                </Label>

                <HoverCard>
                    <HoverCardTrigger as-child>
                      <button type="button" class="cursor-pointer">
                        <Info class="w-4 h-4 text-muted-foreground" />
                      </button>
                    </HoverCardTrigger>
                    <HoverCardContent class="w-96 z-50 p-4 shadow-xl border-blue-100">
                      <div class="space-y-3 text-sm">
                        <h4 class="font-bold text-slate-800 flex items-center gap-2 border-b pb-2">
                          <Wallet class="w-4 h-4 text-blue-600"/> Gestione saldi pregressi
                        </h4>
                        
                        <div class="space-y-2">
                          <p class="text-xs text-slate-600">
                            <strong class="text-emerald-700">Crea rata separata (Saldi iniziali):</strong> <br>
                            <span class="opacity-90">Opzione consigliata. Crea una rata iniziale dedicata solo ai debiti/crediti dell'anno precedente. Fondamentale per la massima trasparenza e per gestire correttamente i subentri.</span>
                          </p>
                          
                          <p class="text-xs text-slate-600">
                            <strong class="text-slate-700">Aggiungi alla prima rata:</strong> <br>
                            <span class="opacity-90">Somma i vecchi debiti alla Rata 1 del nuovo preventivo. Metodo tradizionale, ma rende difficile per il condomino distinguere la competenza.</span>
                          </p>
                          
                          <p class="text-xs text-slate-600">
                            <strong class="text-slate-700">Spalma su tutte le rate:</strong> <br>
                            <span class="opacity-90">Divide il debito/credito in parti uguali su tutte le rate dell'anno. Sconsigliato perché maschera l'importo reale della gestione corrente.</span>
                          </p>
                        </div>
                      </div>
                    </HoverCardContent>
                </HoverCard>
              </div>

              <v-select
                :options="[
                  {label: 'Crea una rata separata (Consigliato)', value: 'rata_zero'},
                  {label: 'Aggiungi alla prima rata (Classico)', value: 'prima_rata'}, 
                  {label: 'Spalma su tutte le rate', value: 'tutte_rate'}
                ]"
                class="mt-1 w-full v-select-custom"
                v-model="form.metodo_distribuzione"
                :reduce="opt => opt.value"
                :clearable="false"
                @update:modelValue="form.clearErrors('metodo_distribuzione')" 
                placeholder="Seleziona modalità di distribuzione"
              />
              <InputError :message="form.errors.metodo_distribuzione" class="absolute -bottom-6 left-0 text-[11px]" />
            </div>
   
          </div>

          <Separator class="my-2 mt-10" />

          <div class="flex flex-col sm:flex-row gap-6 pt-4">
            <div class="flex items-center gap-2">
              <Checkbox id="genera_subito" v-model="form.genera_subito" />
              <Label for="genera_subito" class="cursor-pointer font-medium">Genera subito le rate</Label>
            </div>

            <div class="flex items-center gap-2">
              <Checkbox id="recurrenceToggle" v-model="showRecurrence" />
              <Label for="recurrenceToggle" class="cursor-pointer font-medium text-primary">Ricorrenza automatica avanzata</Label>
            </div>
          </div>

          <div v-if="showRecurrence" class="bg-blue-50/50 dark:bg-blue-900/10 p-5 rounded-lg border border-blue-200 space-y-4 animate-in slide-in-from-top-2 duration-200">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <Label>Frequenza</Label>
                <v-select :options="frequencies" v-model="form.recurrence_frequency" :reduce="o => o.value" class="mt-1 bg-white v-select-custom" />
              </div>
              <div>
                <Label>Intervallo</Label>
                <Input min="1" v-model="form.recurrence_interval" class="mt-1 w-full bg-white" />
              </div>
            </div>

            <div>
                <Label class="mb-3 block text-xs font-bold uppercase tracking-wider text-gray-500">
                  Giorni della settimana
                </Label>
                <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-3">
                  <div 
                    v-for="day in weekdays" 
                    :key="day.value" 
                    class="flex items-center gap-2 bg-white dark:bg-slate-900 p-3 rounded-lg border shadow-sm hover:border-primary/50 transition-colors cursor-pointer"
                    @click="toggleDay(day.value)"
                  >
                    <Checkbox 
                      :id="'day-' + day.value" 
                      :checked="form.recurrence_by_day.includes(day.value)"
                      @update:checked="toggleDay(day.value)"
                    />
                    <label 
                      :for="'day-' + day.value" 
                      class="text-xs font-medium cursor-pointer select-none"
                    >
                      {{ day.label }}
                    </label>
                  </div>
                </div>
                <InputError :message="form.errors.recurrence_by_day" class="mt-2" />
              </div>
            </div>

          <div class="pt-2 border-t">
            <Label for="note">Note aggiuntive per il documento</Label>
            <Textarea id="note" v-model="form.note" class="mt-1 w-full" />
          </div>

        </form>
      </div>
    </div>
  </GestionaleLayout>
</template>

<style src="vue-select/dist/vue-select.css"></style>