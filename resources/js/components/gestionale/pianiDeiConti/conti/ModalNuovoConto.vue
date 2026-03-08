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
  condominioId: number
  esercizioId: number
  pianoContoId: number
  fornitori?: Array<{ id: number, ragione_sociale: string }> 
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
  codice: '', 
  default_fornitore_id: null as number | null, 
  tipo_spesa: 'standard', 
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
      isCapitolo.value = false
      isSottoConto.value = false
    }
  })
}
</script>

<template>
  <Dialog v-model:open="props.show" @update:open="closeModal">
    <DialogContent class="sm:max-w-[700px]"> <DialogHeader>
        <DialogTitle>Nuova voce di spesa o capitolo</DialogTitle>
      </DialogHeader>

      <div class="grid gap-4 py-4 overflow-y-auto px-6 max-h-[70vh]">
          <form @submit.prevent="submit" class="space-y-4 mt-2">
            <input type="hidden" v-model="form.isCapitolo" />
            <input type="hidden" v-model="form.isSottoConto" />

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
              <Textarea id="descrizione" v-model="form.descrizione" placeholder="Descrizione..." class="mt-1" />
            </div>

            <div class="flex items-center gap-6 pb-2">
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
                 <Label for="isCapitolo" class="cursor-pointer">È un capitolo di spesa?</Label>
                 <Switch id="isCapitolo" v-model="isCapitolo" :disabled="isSottoConto" />
               </div>
               <div class="flex items-center justify-between">
                 <Label for="isSottoConto" class="cursor-pointer">È un sotto-conto di spesa?</Label>
                 <Switch id="isSottoConto" v-model="isSottoConto" :disabled="isCapitolo" />
               </div>
            </div>

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
                class="mt-1"
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
                      <option v-for="f in props.fornitori" :key="f.id" :value="f.id">
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
              <Label for="importo">Importo Preventivato</Label>
              <MoneyInput
                id="importo"
                v-model="form.importo"
                :money-options="moneyOptions"
                :lazy="true" 
                placeholder="0,00"
                class="mt-1"
                @focus="form.clearErrors('importo')"
              />
              <InputError :message="form.errors.importo" />
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
                class="mt-1"
              >
              </v-select>
              <InputError :message="form.errors.tabella_millesimale_id" />
            </div>

            <div v-if="!isCapitolo" class="grid grid-cols-3 gap-4 bg-gray-50 p-3 rounded-md">
              <div>
                <Label class="text-xs">Proprietario %</Label>
                <Input type="number" v-model="form.percentuale_proprietario" placeholder="100" class="h-8 mt-1" />
              </div>
              <div>
                <Label class="text-xs">Inquilino %</Label>
                <Input type="number" v-model="form.percentuale_inquilino" placeholder="0" class="h-8 mt-1" />
              </div>
              <div>
                <Label class="text-xs">Usufruttuario %</Label>
                <Input type="number" v-model="form.percentuale_usufruttuario" placeholder="0" class="h-8 mt-1" />
              </div>
            </div>

            <div>
              <Label for="note">Note</Label>
              <Textarea id="note" v-model="form.note" placeholder="Note opzionali..." class="mt-1" />
            </div>

            <DialogFooter class="flex justify-end space-x-2 mt-6">
              <Button type="button" variant="outline" @click="closeModal">Annulla</Button>
              <Button type="submit" :disabled="form.processing">
                {{ form.processing ? 'Salvataggio...' : 'Salva' }}
              </Button>
            </DialogFooter>
          </form>
      </div>
    </DialogContent>
  </Dialog>
</template>

<style src="vue-select/dist/vue-select.css"></style>