<script setup lang="ts">

import { useForm } from '@inertiajs/vue3'
import { ref, watch, nextTick, computed } from 'vue'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import InputError from '@/components/InputError.vue'
import { useTabelle } from '@/composables/useTabelle'
import vSelect from 'vue-select'
import type { TabellaDropdown } from '@/types/gestionale/tabelle'
import type { Conto } from '@/types/gestionale/conti'

interface TabellaAssociata {
  id: number
  nome: string
  coefficiente: number
  ripartizioni: Array<{ soggetto: string; percentuale: number }>
}

interface Emits {
  (e: 'update:show', value: boolean): void
  (e: 'success', data: any): void
}

interface Props {
  show: boolean
  conto: Conto | null
  condominioId: number
  /** Se valorizzato → modalità MODIFICA */
  tabellaEsistente?: TabellaAssociata | null
  /** Residuo disponibile calcolato dal genitore (solo per modalità CREA) */
  residuoDisponibile?: number
}

const props = defineProps<Props>()
const emit = defineEmits<Emits>()

const { tabelle, isLoading: isLoadingTabelle, fetchTabelle } = useTabelle()

const isEditMode = computed(() => !!props.tabellaEsistente)

const dialogTitle = computed(() =>
  isEditMode.value ? 'Modifica associazione tabella' : 'Associa tabella millesimale'
)

const submitLabel = computed(() =>
  isEditMode.value ? 'Salva modifiche' : 'Associa'
)

// In edit mode il massimo è: (100 - somma_altri) = quello che il backend accetterà
// In create mode il massimo è il residuo passato dal genitore
const maxCoefficiente = computed(() => {
  if (isEditMode.value && props.tabellaEsistente) {
    // Il residuo in edit = residuo disponibile + coefficiente attuale della tabella che stiamo modificando
    // Il genitore passa residuoDisponibile già calcolato senza considerare la tabella corrente
    return (props.residuoDisponibile ?? 100) + props.tabellaEsistente.coefficiente
  }
  return props.residuoDisponibile ?? 100
})

const form = useForm({
  tabella_millesimale_id: null as number | null,
  coefficiente: 100 as number,
  percentuale_proprietario: 100,
  percentuale_inquilino: 0,
  percentuale_usufruttuario: 0,
})

const resetForm = () => {
  if (isEditMode.value && props.tabellaEsistente) {
    const t = props.tabellaEsistente
    form.tabella_millesimale_id    = t.id
    form.coefficiente              = t.coefficiente
    form.percentuale_proprietario  = t.ripartizioni.find(r => r.soggetto === 'proprietario')?.percentuale  ?? 100
    form.percentuale_inquilino     = t.ripartizioni.find(r => r.soggetto === 'inquilino')?.percentuale     ?? 0
    form.percentuale_usufruttuario = t.ripartizioni.find(r => r.soggetto === 'usufruttuario')?.percentuale ?? 0
  } else {
    form.reset()
    // Pre-compila con il residuo disponibile
    form.coefficiente              = props.residuoDisponibile ?? 100
    form.percentuale_proprietario  = 100
    form.percentuale_inquilino     = 0
    form.percentuale_usufruttuario = 0
  }
}

watch(() => props.show, (newVal) => {
  if (newVal) {
    fetchTabelle(props.condominioId)
    resetForm()
    nextTick(() => { document.getElementById('coefficiente')?.focus() })
  }
})

watch(() => props.tabellaEsistente, () => {
  if (props.show) resetForm()
})

const closeModal = () => {
  emit('update:show', false)
  form.reset()
}

const onDropdownTabelleOpen = () => {
  if (tabelle.value.length === 0) fetchTabelle(props.condominioId)
}

// Somma percentuali soggetti
const sommaPercentuali = computed(() =>
  form.percentuale_proprietario + form.percentuale_inquilino + form.percentuale_usufruttuario
)

// Coefficiente fuori range
const coefficienteNonValido = computed(() =>
  !form.coefficiente || form.coefficiente <= 0 || form.coefficiente > maxCoefficiente.value
)

const submit = () => {
  if (!props.conto) return

  if (coefficienteNonValido.value) {
    form.setError('coefficiente', `Il coefficiente deve essere compreso tra 1 e ${maxCoefficiente.value}%`)
    return
  }

  if (sommaPercentuali.value !== 100) {
    form.setError('percentuale_proprietario', 'La somma delle percentuali deve essere 100%')
    return
  }

  emit('success', {
    ...form.data(),
    _tabellaId: isEditMode.value ? props.tabellaEsistente!.id : null,
    _isEdit: isEditMode.value,
  })

  closeModal()
}
</script>

<template>
  <Dialog v-model:open="props.show" @update:open="closeModal">
    <DialogContent class="sm:max-w-[500px]">
      <DialogHeader>
        <DialogTitle>{{ dialogTitle }}</DialogTitle>
      </DialogHeader>

      <form @submit.prevent="submit" class="space-y-4 mt-4">

        <!-- Tabella millesimale -->
        <div>
          <Label for="tabella" class="required">Tabella millesimale</Label>

          <!-- EDIT MODE: nome bloccato -->
          <div v-if="isEditMode"
               class="flex items-center gap-2 px-3 py-2 rounded-md border bg-muted/40 text-sm font-medium text-foreground">
            <span class="flex-1">{{ props.tabellaEsistente?.nome }}</span>
            <span class="text-[10px] font-bold uppercase tracking-wider text-muted-foreground bg-muted px-2 py-0.5 rounded">
              bloccata
            </span>
          </div>

          <!-- CREATE MODE: dropdown -->
          <v-select
            v-else
            id="tabella"
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
              <span>{{ option.nome }}</span>
            </template>
          </v-select>

          <InputError :message="form.errors.tabella_millesimale_id" />
        </div>

        <!-- Coefficiente di attribuzione -->
        <div>
          <div class="flex items-center justify-between mb-1">
            <Label for="coefficiente" class="required">Coefficiente di attribuzione</Label>
            <!-- Badge residuo: visibile solo in create mode o quando ha senso -->
            <Badge
              variant="outline"
              class="text-[10px] px-2 h-5 rounded-md tabular-nums"
              :class="maxCoefficiente === 0
                ? 'bg-red-50 text-red-600 border-red-200'
                : 'bg-emerald-50 text-emerald-700 border-emerald-200'"
            >
              max {{ maxCoefficiente }}% disponibile
            </Badge>
          </div>

          <div class="relative">
            <Input
              id="coefficiente"
              v-model="form.coefficiente"
              placeholder="100"
              min="1"
              :max="maxCoefficiente"
              class="pr-12"
              :class="coefficienteNonValido && form.coefficiente ? 'border-red-400' : ''"
            />
            <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
              <span class="text-gray-500">%</span>
            </div>
          </div>

          <p class="text-xs text-gray-500 mt-1">
            Percentuale della spesa attribuita a questa tabella
            <span class="font-medium">(1–{{ maxCoefficiente }}%)</span>
          </p>
          <InputError :message="form.errors.coefficiente" />
        </div>

        <!-- Ripartizioni per soggetti -->
        <div class="border-t pt-4">
          <Label class="text-sm font-medium mb-3 block">Ripartizione per soggetti</Label>
          <div class="grid grid-cols-3 gap-4">
            <div>
              <Label for="proprietario" class="text-xs">Proprietario %</Label>
              <Input 
                id="proprietario"
                v-model="form.percentuale_proprietario"
                placeholder="100" 
                min="0" 
                max="100" 
              />
            </div>
            <div>
              <Label for="inquilino" class="text-xs">Inquilino %</Label>
              <Input 
                id="inquilino" 
                v-model="form.percentuale_inquilino"
                placeholder="0" 
                min="0" 
                max="100" 
              />
            </div>
            <div>
              <Label for="usufruttuario" class="text-xs">Usufruttuario %</Label>
              <Input 
                id="usufruttuario" 
                v-model="form.percentuale_usufruttuario"
                placeholder="0" 
                min="0" 
                max="100" 
              />
            </div>
          </div>

          <div class="flex justify-between items-center mt-2">
            <p class="text-xs font-medium"
               :class="sommaPercentuali === 100 ? 'text-emerald-600' : 'text-red-500'">
              Totale: {{ sommaPercentuali }}%
              <span v-if="sommaPercentuali === 100"></span>
              <span v-else> (mancano {{ 100 - sommaPercentuali }}%)</span>
            </p>
            <Button
              v-if="sommaPercentuali !== 100"
              type="button" variant="outline" size="sm" class="text-xs"
              @click="form.percentuale_proprietario = 100; form.percentuale_inquilino = 0; form.percentuale_usufruttuario = 0;"
            >
              Reset
            </Button>
          </div>
          <InputError :message="form.errors.percentuale_proprietario" />
        </div>

        <DialogFooter class="flex justify-end space-x-2 mt-6">
          <Button type="button" variant="outline" @click="closeModal">Annulla</Button>
          <Button
            type="submit"
            :disabled="form.processing || coefficienteNonValido || sommaPercentuali !== 100"
          >
            {{ form.processing ? 'Salvataggio...' : submitLabel }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>

<style src="vue-select/dist/vue-select.css"></style>

<style scoped>
.required::after {
  content: " *";
  color: #ef4444;
}
input:focus {
  outline: none !important;
  box-shadow: none !important;
  border-color: #d1d5db !important;
}
</style>