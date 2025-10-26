<script setup lang="ts">

import { useForm, router } from '@inertiajs/vue3'
import { ref, watch, computed, onMounted } from 'vue'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Button } from '@/components/ui/button'
import { Switch } from '@/components/ui/switch'
import InputError from '@/components/InputError.vue'
import { useTabelle } from '@/composables/useTabelle'
import { useCapitoliConti, type CapitoloDropdown } from '@/composables/useCapitoliConti'
import vSelect from 'vue-select'
import type { TabellaDropdown } from '@/types/gestionale/tabelle'
import type { Conto } from '@/types/gestionale/conti'
import MoneyInput from '@/components/MoneyInput.vue'

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
const { tabelle, isLoading: isLoadingTabelle, fetchTabelle } = useTabelle()
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
  tabella_millesimale_id: null as number | null,
  percentuale_proprietario: 100,
  percentuale_inquilino: 0,
  percentuale_usufruttuario: 0,
  isCapitolo: false,
  isSottoConto: false,
})

// Carica i dati quando il modal si apre
watch(() => props.show, (newVal) => {
  if (newVal && props.conto) {
    // Carica immediatamente i dati per i dropdown
    fetchTabelle(props.condominioId)
    fetchCapitoliConti(props.condominioId, props.pianoContoId)
  }
})

// Funzione per trovare l'oggetto tabella per ID
const findTabellaById = (id: number | null) => {
  if (!id) return null
  return tabelle.value.find(t => t.id === id) || null
}

// Funzione per trovare l'oggetto capitolo per ID
const findCapitoloById = (id: number | null) => {
  if (!id) return null
  return capitoli.value.find(c => c.id === id) || null
}

// Computed per gli oggetti selezionati (per mostrare i nomi nei dropdown)
const selectedTabella = computed({
  get: () => findTabellaById(form.tabella_millesimale_id),
  set: (val: TabellaDropdown | null) => {
    form.tabella_millesimale_id = val ? val.id : null
  }
})

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
    
    // Gestisci tabelle millesimali (solo se non è capitolo)
    if (!isContoCapitolo.value && newConto.tabelle_millesimali && newConto.tabelle_millesimali.length > 0) {
      const tabella = newConto.tabelle_millesimali[0]
      form.tabella_millesimale_id = tabella.tabella.id
      
      // Imposta le percentuali dalle ripartizioni
      if (tabella.ripartizioni && tabella.ripartizioni.length > 0) {
        tabella.ripartizioni.forEach(ripartizione => {
          if (ripartizione.soggetto === 'proprietario') {
            form.percentuale_proprietario = ripartizione.percentuale
          } else if (ripartizione.soggetto === 'inquilino') {
            form.percentuale_inquilino = ripartizione.percentuale
          } else if (ripartizione.soggetto === 'usufruttuario') {
            form.percentuale_usufruttuario = ripartizione.percentuale
          }
        })
      }
    } else {
      // Reset dei valori se non ci sono tabelle associate
      form.tabella_millesimale_id = null
      form.percentuale_proprietario = 100
      form.percentuale_inquilino = 0
      form.percentuale_usufruttuario = 0
    }
  }
}, { immediate: true })

// Watch per sincronizzare i flag con il form
watch(isCapitolo, (val) => {
  if (val) {
    isSottoConto.value = false
    form.parent_id = null
    form.importo = '' // Pulisci l'importo per i capitoli
    form.tabella_millesimale_id = null // Pulisci la tabella per i capitoli
    form.percentuale_proprietario = 0 // Resetta le percentuali per i capitoli
    form.percentuale_inquilino = 0
    form.percentuale_usufruttuario = 0
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
  resetForm()
}

const resetForm = () => {
  form.reset()
  isCapitolo.value = false
  isSottoConto.value = false
}

const onDropdownTabelleOpen = () => {
  if (tabelle.value.length === 0) {
    fetchTabelle(props.condominioId)
  }
}

const onDropdownCapitoliOpen = () => {
  if (capitoli.value.length === 0) {
    fetchCapitoliConti(props.condominioId, props.pianoContoId)
  }
}

const submit = () => {
  if (!props.conto) return

  // Prepara i dati per l'invio
  const formData = {
    ...form.data(),
    // Converti l'importo in centesimi solo se non è un capitolo e c'è un importo
    importo: !isCapitolo.value && form.importo 
      ? Math.round(extractNumericValue(form.importo) * 100)
      : 0,
    // Assicurati che parent_id sia null se non è un sotto-conto
    parent_id: isSottoConto.value ? form.parent_id : null,
    // Assicurati che tabella_millesimale_id sia null se è un capitolo
    tabella_millesimale_id: isCapitolo.value ? null : form.tabella_millesimale_id,
    // Resetta le percentuali se è un capitolo
    percentuale_proprietario: isCapitolo.value ? 0 : form.percentuale_proprietario,
    percentuale_inquilino: isCapitolo.value ? 0 : form.percentuale_inquilino,
    percentuale_usufruttuario: isCapitolo.value ? 0 : form.percentuale_usufruttuario,
  }

  const routeParams = {
    condominio: props.condominioId,
    esercizio: props.esercizioId,
    pianoConto: props.pianoContoId,
    conto: props.conto.id
  }

  form.put(route('admin.gestionale.esercizi.piani-conti.conti.update', routeParams), {
    preserveScroll: true,
    data: formData,
    onSuccess: () => {
      resetForm()
      emit('success')
      closeModal()
    },
    onError: (errors) => {
      console.error('Errore nella modifica della voce di spesa:', errors)
    }
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

            <div v-if="!isCapitolo">
              <Label>Tabella millesimale</Label>
              <v-select
                :options="tabelle"
                label="nome"
                v-model="selectedTabella"
                placeholder="Seleziona tabella millesimale"
                :reduce="(t: TabellaDropdown) => t"
                @open="onDropdownTabelleOpen"
                :loading="isLoadingTabelle"
                :clearable="true"
              >
                <template #no-options>
                  <div class="text-sm text-gray-500 p-2">
                    {{ isLoadingTabelle ? 'Caricamento tabelle...' : 'Nessuna tabella disponibile' }}
                  </div>
                </template>
                <template #option="option">
                  <div class="flex items-center">
                    <span>{{ option.nome }}</span>
                  </div>
                </template>
              </v-select>
              <InputError :message="form.errors.tabella_millesimale_id" />
            </div>

            <!-- Percentuali -->
            <div v-if="!isCapitolo" class="grid grid-cols-3 gap-4 mt-4">
              <div>
                <Label>Proprietario %</Label>
                <Input 
                  type="number" 
                  v-model="form.percentuale_proprietario" 
                  placeholder="100" 
                  min="0" 
                  max="100" 
                />
              </div>
              <div>
                <Label>Inquilino %</Label>
                <Input 
                  type="number" 
                  v-model="form.percentuale_inquilino" 
                  placeholder="0" 
                  min="0" 
                  max="100" 
                />
              </div>
              <div>
                <Label>Usufruttuario %</Label>
                <Input 
                  type="number" 
                  v-model="form.percentuale_usufruttuario" 
                  placeholder="0" 
                  min="0" 
                  max="100" 
                />
              </div>
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