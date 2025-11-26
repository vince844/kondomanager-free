<script setup lang="ts">

import { useForm } from '@inertiajs/vue3'
import { ref, watch, computed } from 'vue'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Button } from '@/components/ui/button'
import InputError from '@/components/InputError.vue'
import { useCapitoliConti, type CapitoloDropdown } from '@/composables/useCapitoliConti'
import vSelect from 'vue-select'
import MoneyInput from '@/components/MoneyInput.vue'
import type { Conto } from '@/types/gestionale/conti'

interface Emits {
  (e: 'update:show', value: boolean): void
  (e: 'success'): void
}

interface Props {
  show: boolean
  conto: Conto | null
  condominioId: string
  esercizioId: number
  pianoContoId: number
}

const props = defineProps<Props>()
const emit = defineEmits<Emits>()

const isCapitolo = ref(false)
const isSottoConto = ref(false)
const { capitoli, isLoading: isLoadingCapitoli, fetchCapitoliConti } = useCapitoliConti()

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
    // Carica immediatamente i dati per i dropdown
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

// Funzione per estrarre il valore numerico dall'importo formattato
const extractNumericValue = (importoFormattato: string): number => {
  if (!importoFormattato) return 0
  
  // Rimuovi il simbolo € e gli spazi, poi sostituisci la virgola con il punto
  const numericString = importoFormattato
    .replace('€', '')
    .replace(/\s/g, '')
    .replace(/\./g, '') // Rimuovi i separatori delle migliaia
    .replace(',', '.') // Converti la virgola decimale in punto
  
  return parseFloat(numericString) || 0
}

// Calcola se il conto è un capitolo basandosi sui dati reali
const isContoCapitolo = computed(() => {
  if (!props.conto) return false
  
  // Controlla se l'importo è zero (considerando sia il formato stringa che numero)
  const importoNumerico = extractNumericValue(props.conto.importo)
  const hasZeroImporto = importoNumerico === 0
  
  // Controlla se ha sottoconti
  const hasSottoconti = props.conto.sottoconti && props.conto.sottoconti.length > 0
  
  // È un capitolo se ha importo zero E ha sottoconti
  // OPPURE se non ha parent_id (non è un sotto-conto) e ha importo zero
  return (hasZeroImporto && hasSottoconti) || (hasZeroImporto && !props.conto.parent_id)
})

// Inizializza il form quando il conto cambia
watch(() => props.conto, (newConto) => {
  if (newConto) {
    
    form.nome = newConto.nome
    form.tipo = newConto.tipo
    form.descrizione = newConto.descrizione || ''
    form.note = newConto.note || ''
    form.parent_id = newConto.parent_id
    
    // Imposta i flag basati sui dati reali del conto
    isCapitolo.value = isContoCapitolo.value
    isSottoConto.value = !!newConto.parent_id
    form.isCapitolo = isContoCapitolo.value
    form.isSottoConto = !!newConto.parent_id
    
    // Gestione importo (solo se non è capitolo)
    if (!isContoCapitolo.value) {
      // Se non è un capitolo, mostra l'importo formattato così com'è
      form.importo = newConto.importo
    } else {
      form.importo = ''
    }
  }
}, { immediate: true })

// Watch per sincronizzare i flag con il form
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
      console.error('Errore nella modifica della voce di spesa:', errors)
      console.error('Form data sent:', form.data())
    },
  })
}
</script>

<template>
  <Dialog v-model:open="props.show" @update:open="closeModal">
    <DialogContent class="sm:max-w-[650px]">
      <DialogHeader>
        <DialogTitle>Modifica voce di spesa</DialogTitle>
      </DialogHeader>

      <div class="grid gap-4 py-4 overflow-y-auto px-6">
        <div class="flex flex-col justify-between h-[60dvh]">

          <form v-if="props.conto" @submit.prevent="submit" class="space-y-4 mt-4">
            <!-- Campi hidden per isCapitolo e isSottoConto -->
            <input type="hidden" v-model="form.isCapitolo" />
            <input type="hidden" v-model="form.isSottoConto" />

            <!-- Nome -->
            <div>
              <Label for="nome">Nome</Label>
              <Input id="nome" v-model="form.nome" placeholder="Es. Spese ascensore" />
              <InputError :message="form.errors.nome" />
            </div>

            <!-- Descrizione e note -->
            <div>
              <Label for="descrizione">Descrizione</Label>
              <Textarea id="descrizione" v-model="form.descrizione" placeholder="Descrizione..." />
            </div>

            <div v-if="!isCapitolo" class="flex items-center gap-6 pb-2">
              <Label class="font-medium">Tipo di spesa</Label>
              <div class="flex items-center gap-2">
                <input type="radio" id="spesa" value="spesa" v-model="form.tipo" />
                <Label for="spesa">Spesa (uscita)</Label>
              </div>
              <div class="flex items-center gap-2">
                <input type="radio" id="entrata" value="entrata" v-model="form.tipo" />
                <Label for="entrata">Entrata</Label>
              </div>
            </div>

            <!-- Se è sotto-conto -->
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
                <template #no-options>
                  <div class="text-sm text-gray-500 p-2">
                    {{ isLoadingCapitoli ? 'Caricamento capitoli...' : 'Nessun capitolo disponibile' }}
                  </div>
                </template>
                <template #option="option">
                  <div class="flex items-center">
                    <span>{{ option.nome }}</span>
                  </div>
                </template>
              </v-select>
              <InputError :message="form.errors.parent_id" />
            </div>

            <!-- Campi specifici solo se NON è capitolo -->
            <div v-if="!isCapitolo">
              <Label for="importo">Importo</Label>
              <MoneyInput
                id="importo"
                v-model="form.importo"
                :money-options="moneyOptions"
                :lazy="true" 
                placeholder="0,00"
                @focus="form.clearErrors('importo')"
              />
              <InputError :message="form.errors.importo" />
              <p class="text-xs text-gray-500 mt-1">
                Inserisci l'importo nel formato italiano (es. 1.234,56)
              </p>
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