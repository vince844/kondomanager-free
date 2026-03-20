<script setup lang="ts">

import { useForm } from '@inertiajs/vue3'
import { ref, watch, computed } from 'vue'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Button } from '@/components/ui/button'
import { Switch } from '@/components/ui/switch'
import InputError from '@/components/InputError.vue'
import { useCapitoliConti, type CapitoloDropdown } from '@/composables/useCapitoliConti'
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter'
import vSelect from 'vue-select'
import MoneyInput from '@/components/MoneyInput.vue'
import { Lock, AlertTriangle, Info } from 'lucide-vue-next'
import type { Conto } from '@/types/gestionale/conti'

interface Emits {
  (e: 'update:show', value: boolean): void
  (e: 'success'): void
}

interface Props {
  show: boolean
  conto: Conto | null
  condominioId: number
  esercizioId: number
  pianoContoId: number
  // Aggiunto per il menu a tendina
  fornitori?: Array<{ id: number, ragione_sociale: string }> 
  
}

const props = defineProps<Props>()
const emit = defineEmits<Emits>()

const isCapitolo = ref(false)
const isSottoConto = ref(false)
const { capitoli, isLoading: isLoadingCapitoli, fetchCapitoliConti } = useCapitoliConti()
const { euro } = useCurrencyFormatter()

const moneyOptions = ref({
  prefix: '',              
  suffix: '',              
  thousands: '.',          
  decimal: ',',          
  precision: 2,            
  allowBlank: false,
  masked: true 
})

const form = useForm({
  nome: '',
  codice: '', // Aggiunto
  default_fornitore_id: null as number | null, // Aggiunto
  tipo_spesa: 'standard', // Aggiunto
  tipo: 'spesa' as 'spesa' | 'entrata',
  importo: '',
  descrizione: '',
  note: '',
  parent_id: null as number | null,
  isCapitolo: false,
  isSottoConto: false,
})

// Carica i dati quando il modal si apre
watch(() => props.show, (newVal) => {
  if (newVal && props.conto) {
    fetchCapitoliConti(props.condominioId, props.pianoContoId)
  }
})

// Funzione per trovare l'oggetto capitolo per ID
const findCapitoloById = (id: number | null) => {
  if (!id) return null
  return capitoli.value.find(c => c.id === id) || null
}

const selectedCapitolo = computed({
  get: () => findCapitoloById(form.parent_id),
  set: (val: CapitoloDropdown | null) => {
    form.parent_id = val ? val.id : null
  }
})

const extractNumericValue = (importoFormattato: string): number => {
  if (!importoFormattato) return 0
  const numericString = importoFormattato
    .replace('€', '')
    .replace(/\s/g, '')
    .replace(/\./g, '') 
    .replace(',', '.') 
  return parseFloat(numericString) || 0
}

const isContoCapitolo = computed(() => {
  if (!props.conto) return false
  const importoNumerico = extractNumericValue(props.conto.importo)
  const hasZeroImporto = importoNumerico === 0
  const hasSottoconti = props.conto.sottoconti && props.conto.sottoconti.length > 0
  return (hasZeroImporto && hasSottoconti) || (hasZeroImporto && !props.conto.parent_id)
})

// *** NUOVA COMPUTED PROPERTY PER IL BLOCCO ***
const isImportoLocked = computed(() => {
  // @ts-ignore
  return props.conto?.has_rate_emesse === true;
})

const hasSottoconti = computed(() => {
  return (props.conto?.sottoconti?.length ?? 0) > 0
})

watch(() => props.conto, (newConto) => {
  if (newConto) {
    form.nome = newConto.nome
    form.codice = newConto.codice || '' // Assegna il codice esistente
    form.default_fornitore_id = newConto.default_fornitore_id || null // Assegna il fornitore esistente
    form.tipo_spesa = newConto.tipo_spesa || 'standard' // Assegna il tipo spesa esistente
    form.tipo = newConto.tipo
    form.descrizione = newConto.descrizione || ''
    form.note = newConto.note || ''
    form.parent_id = newConto.parent_id
    
    isCapitolo.value = isContoCapitolo.value
    isSottoConto.value = !!newConto.parent_id
    form.isCapitolo = isContoCapitolo.value
    form.isSottoConto = !!newConto.parent_id
    
    if (!isContoCapitolo.value) {
      form.importo = newConto.importo
    } else {
      form.importo = ''
    }
  }
}, { immediate: true })

watch(isCapitolo, (val) => {
  if (val) {
    isSottoConto.value = false
    form.parent_id = null
    form.importo = ''
  }
  form.isCapitolo = val 
})

watch(isSottoConto, (val) => {
  if (val) {
    isCapitolo.value = false
    form.isCapitolo = false
  }
  form.isSottoConto = val 
})

const closeModal = () => {
  emit('update:show', false)
}

const resetForm = () => {
  form.reset()
  isCapitolo.value = false
  isSottoConto.value = false
}

const onDropdownCapitoliOpen = () => {
  if (capitoli.value.length === 0) {
    fetchCapitoliConti(props.condominioId, props.pianoContoId)
  }
}

const submit = () => {
  if (!props.conto) return

  const routeParams = {
    condominio: props.condominioId,
    esercizio: props.esercizioId,
    pianoConto: props.pianoContoId,
    conto: props.conto.id,
  }

  form.transform((data) => ({
    ...data,
    importo: isCapitolo.value ? 0 : data.importo,
    parent_id: isSottoConto.value ? data.parent_id : null,
  })).put(route('admin.gestionale.esercizi.piani-conti.conti.update', routeParams), {
    preserveScroll: true,
    onSuccess: () => {
      resetForm()
      emit('success')
      closeModal()
    },
    onError: (errors) => {
      console.error('Errore nella modifica:', errors)
    },
  })
}
</script>

<template>
  <Dialog :open="props.show" @update:open="(val) => !val && closeModal()">
    <DialogContent class="sm:max-w-[750px]" @openAutoFocus="(e: Event) => e.preventDefault()">
      <DialogHeader>
        <DialogTitle>Modifica voce di spesa</DialogTitle>
      </DialogHeader>

      <div class="grid gap-4 py-4 overflow-y-auto px-6 max-h-[70vh]">
        <div class="flex flex-col justify-between">

          <form v-if="props.conto" @submit.prevent="submit" class="space-y-4 mt-4">
            <input type="hidden" v-model="form.isCapitolo" />
            <input type="hidden" v-model="form.isSottoConto" />

            <div v-if="!isCapitolo && (isImportoLocked || (props.conto?.impegnato ?? 0) > 0)" 
                 class="bg-blue-50/80 border border-blue-200 rounded-lg p-4 space-y-3 mb-6">
                 
                <div class="flex items-start gap-3">
                    <div class="bg-blue-100 p-1.5 rounded-full shrink-0 mt-0.5">
                        <Info class="w-4 h-4 text-blue-700" />
                    </div>
                    
                    <div class="text-sm text-blue-900">
                        <strong>Vincoli sull'importo:</strong>
                        
                        <span v-if="isImportoLocked" class="block mt-1 text-blue-800/80">
                            L'importo di questa voce è attualmente bloccato perché è collegato a un piano rate già approvato o con rate emesse. 
                            <br><br>
                            <strong>Come risolvere:</strong> Per modificare questa cifra, devi prima annullare le rate emesse o gestire l'eccedenza tramite il modulo <em>"Sposta Budget"</em>.
                        </span>
                        
                        <span v-else-if="(props.conto?.impegnato ?? 0) > 0" class="block mt-1 text-blue-800/80">
                            Hai già inserito questa spesa in un piano rate per un totale di <strong>{{ euro(props.conto?.impegnato ?? 0) }}</strong>. 
                            Per garantire la coerenza contabile, non puoi ridurre l'importo totale al di sotto di questa soglia.
                            <br><br>
                            <strong>Come risolvere:</strong> Se desideri inserire una cifra inferiore, vai nel modulo <em>"Piani Rate"</em> dove hai impegnato l'importo e rimuovi la quota assegnata a questa voce. Dopodiché, potrai abbassare l'importo qui.
                        </span>
                    </div>
                </div>
            </div>
            <div class="grid grid-cols-4 gap-4">
               <div class="col-span-1">
                  <Label for="codice">Codice</Label>
                  <Input id="codice" v-model="form.codice" placeholder="A.1" class="mt-1" />
               </div>
               <div class="col-span-3">
                  <Label for="nome">Nome Voce</Label>
                  <Input id="nome" v-model="form.nome" placeholder="Es. Pulizia Scale" class="mt-1" required />
                  <InputError :message="form.errors.nome" />
               </div>
            </div>

            <div>
              <Label for="descrizione">Descrizione</Label>
              <Textarea id="descrizione" v-model="form.descrizione" placeholder="Descrizione..." />
            </div>

            <div v-if="!isCapitolo" class="flex items-center gap-6 pb-2">
              <Label class="font-medium">Tipo di movimento</Label>
              <div class="flex items-center gap-2">
                <input type="radio" id="spesa" value="spesa" v-model="form.tipo" />
                <Label for="spesa">Spesa (uscita)</Label>
              </div>
              <div class="flex items-center gap-2">
                <input type="radio" id="entrata" value="entrata" v-model="form.tipo" />
                <Label for="entrata">Entrata</Label>
              </div>
            </div>

            <div class="flex flex-col gap-3 border-y border-gray-100 py-3">
              <div class="flex items-center justify-between">
                <Label for="editIsCapitolo" class="cursor-pointer">
                  {{ trans('gestionale.list_pages.piani_conti.show.new_entry_modal.labels.is_expense_chapter') }}
                </Label>
                <Switch
                  id="editIsCapitolo"
                  v-model="isCapitolo"
                  :disabled="isSottoConto || hasSottoconti"
                />
              </div>
              <div class="flex items-center justify-between">
                <Label for="editIsSottoConto" class="cursor-pointer">
                  {{ trans('gestionale.list_pages.piani_conti.show.new_entry_modal.labels.is_expense_subaccount') }}
                </Label>
                <Switch id="editIsSottoConto" v-model="isSottoConto" :disabled="isCapitolo" />
              </div>
            </div>

            <div v-if="isSottoConto">
              <Label>Capitolo padre</Label>
              <v-select
                :options="capitoli"
                label="nome"
                v-model="selectedCapitolo"
                placeholder="Seleziona capitolo padre"
                :reduce="(c: CapitoloDropdown) => c"
                @open="onDropdownCapitoliOpen"
                :loading="isLoadingCapitoli"
                :clearable="true"
              >
              </v-select>
              <InputError :message="form.errors.parent_id" />
            </div>

            <div v-if="!isCapitolo" class="bg-slate-50 p-4 rounded-md border border-slate-200 grid grid-cols-2 gap-4">
                <div>
                   <Label for="fornitore" class="text-xs font-semibold uppercase text-slate-500">Fornitore Suggerito</Label>
                   <select 
                      id="fornitore"
                      v-model="form.default_fornitore_id"
                      class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm mt-1 focus:ring-2 focus:ring-ring"
                   >
                      <option :value="null">-- Nessuno --</option>
                      <option v-for="f in (props.fornitori || [])" :key="f.id" :value="f.id">
                        {{ f.ragione_sociale }}
                      </option>
                   </select>
                   <p class="text-[10px] text-slate-500 mt-1">Verrà precompilato nelle fatture.</p>
                </div>

                <div>
                   <Label for="tipo_spesa" class="text-xs font-semibold uppercase text-slate-500">Natura Spesa (Fiscale)</Label>
                   <select 
                      id="tipo_spesa"
                      v-model="form.tipo_spesa"
                      class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm mt-1 focus:ring-2 focus:ring-ring"
                   >
                      <option value="standard">Standard (Beni/Servizi)</option>
                      <option value="professionista">Professionista (Rit. Acconto)</option>
                      <option value="lavori">Lavori Edili (Bonus/Ristr.)</option>
                      <option value="utenza">Utenza (Luce/Gas/Acqua)</option>
                   </select>
                </div>
            </div>

            <div v-if="!isCapitolo">
              <div class="flex justify-between items-center mb-1">
                <Label for="importo">Importo Preventivato</Label>
                <div v-if="isImportoLocked" class="flex items-center text-xs text-amber-600 bg-amber-50 px-2 py-1 rounded border border-amber-200">
                  <Lock class="w-3 h-3 mr-1" />
                  Bloccato da rate emesse o piano rate approvato
                </div>
              </div>
              
              <div class="relative">
                <MoneyInput
                  id="importo"
                  v-model="form.importo"
                  :money-options="moneyOptions"
                  :lazy="true" 
                  placeholder="0,00"
                  @focus="form.clearErrors('importo')"
                  :disabled="isImportoLocked" 
                  :class="{'opacity-60 bg-gray-100 cursor-not-allowed': isImportoLocked}"
                />
              </div>
              
              <InputError :message="form.errors.importo" />
            </div>

            <div>
              <Label for="note">Note</Label>
              <Textarea id="note" v-model="form.note" placeholder="Note opzionali..." />
            </div>

            <DialogFooter class="flex justify-end space-x-2 mt-6">
              <Button type="button" variant="outline" @click="closeModal">Annulla</Button>
              <Button type="submit" :disabled="form.processing">
                {{ form.processing ? 'Salvataggio...' : 'Salva modifiche' }}
              </Button>
            </DialogFooter>
          </form>

        </div>
      </div>
    </DialogContent>
  </Dialog>
</template>

<style src="vue-select/dist/vue-select.css"></style>