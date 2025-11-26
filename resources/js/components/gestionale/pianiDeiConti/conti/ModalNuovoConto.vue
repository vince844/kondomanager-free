<script setup lang="ts">

import { useForm } from '@inertiajs/vue3'
import { ref, watch } from 'vue'
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
import MoneyInput from '@/components/MoneyInput.vue'
import type { TabellaDropdown } from '@/types/gestionale/tabelle'

interface Emits {
  (e: 'update:show', value: boolean): void
  (e: 'success'): void
}

interface Props {
  show: boolean
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
  tabella_millesimale_id: '' as string | number,
  percentuale_proprietario: 100,
  percentuale_inquilino: 0,
  percentuale_usufruttuario: 0,
  isCapitolo: false,
  isSottoConto: false,
})

watch(isCapitolo, (val) => {
  if (val) {
    isSottoConto.value = false
    form.parent_id = null
  }
  form.isCapitolo = val 
})

watch(isSottoConto, (val) => {
  if (val) {
    isCapitolo.value = false
  }
  form.isSottoConto = val 
})

const closeModal = () => {
  emit('update:show', false)
  form.reset()
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

  form.post(route('admin.gestionale.esercizi.piani-conti.conti.store', {
    condominio: props.condominioId,
    esercizio: props.esercizioId,
    pianoConto: props.pianoContoId 
  }), {
    preserveScroll: true,
    onSuccess: () => {
      form.reset()
      isCapitolo.value = false
      isSottoConto.value = false
      emit('success')
      closeModal()
    },
    onError: (errors) => {
      console.error('Errore nella creazione della voce di spesa:', errors)
      form.reset()
      isCapitolo.value = false
      isSottoConto.value = false
      closeModal()
    }
  })
}
</script>

<template>
  <Dialog v-model:open="props.show" @update:open="closeModal">
    <DialogContent class="sm:max-w-[650px]">
      <DialogHeader>
        <DialogTitle>Nuova voce di spesa o capitolo</DialogTitle>
      </DialogHeader>

      <div class="grid gap-4 py-4 overflow-y-auto px-6">
        <div class="flex flex-col justify-between h-[60dvh]">

          <form @submit.prevent="submit" class="space-y-4 mt-4">
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

            <div class="flex items-center gap-6 pb-2">
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

            <!-- È un capitolo -->
            <div class="flex items-center justify-between">
              <Label for="isCapitolo">È un capitolo di spesa?</Label>
              <Switch id="isCapitolo" v-model="isCapitolo" :disabled="isSottoConto" />
            </div>

            <!-- È un sotto-conto -->
            <div class="flex items-center justify-between">
              <Label for="isSottoConto">È un sotto-conto di spesa?</Label>
              <Switch id="isSottoConto" v-model="isSottoConto" :disabled="isCapitolo" />
            </div>

            <!-- Se è sotto-conto -->
            <div v-if="isSottoConto">
              <Label>Capitolo padre</Label>
              <v-select
                :options="capitoli"
                label="nome"
                v-model="form.parent_id"
                placeholder="Seleziona capitolo padre"
                :reduce="(c: CapitoloDropdown) => c.id"
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
                v-model="form.tabella_millesimale_id"
                placeholder="Seleziona tabella millesimale"
                :reduce="(t: TabellaDropdown) => t.id"
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
                <Input type="number" v-model="form.percentuale_proprietario" placeholder="100" />
              </div>
              <div>
                <Label>Inquilino %</Label>
                <Input type="number" v-model="form.percentuale_inquilino" placeholder="0" />
              </div>
              <div>
                <Label>Usufruttuario %</Label>
                <Input type="number" v-model="form.percentuale_usufruttuario" placeholder="0" />
              </div>
            </div>

            <div>
              <Label for="note">Note</Label>
              <Textarea id="note" v-model="form.note" placeholder="Note opzionali..." />
            </div>

            <DialogFooter class="flex justify-end space-x-2 mt-6">
              <Button type="button" variant="outline" @click="closeModal">Annulla</Button>
              <Button type="submit" :disabled="form.processing">
                {{ form.processing ? 'Salvataggio...' : 'Salva' }}
              </Button>
            </DialogFooter>
          </form>

        </div>
      </div>
    </DialogContent>
  </Dialog>
</template>

<style src="vue-select/dist/vue-select.css"></style>