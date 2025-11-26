<script setup lang="ts">

import { useForm } from '@inertiajs/vue3'
import { ref, watch, nextTick } from 'vue'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Button } from '@/components/ui/button'
import InputError from '@/components/InputError.vue'
import { useTabelle } from '@/composables/useTabelle'
import vSelect from 'vue-select'
import type { TabellaDropdown } from '@/types/gestionale/tabelle'
import type { Conto } from '@/types/gestionale/conti'

interface Emits {
  (e: 'update:show', value: boolean): void
  (e: 'success', data: any): void
}

interface Props {
  show: boolean
  conto: Conto | null
  condominioId: string
}

const props = defineProps<Props>()
const emit = defineEmits<Emits>()

const { tabelle, isLoading: isLoadingTabelle, fetchTabelle } = useTabelle()

const form = useForm({
  tabella_millesimale_id: null as number | null,
  coefficiente: 100, 
  percentuale_proprietario: 100,
  percentuale_inquilino: 0,
  percentuale_usufruttuario: 0,
})

// Carica le tabelle quando il modal si apre
watch(() => props.show, (newVal) => {
  if (newVal) {
    fetchTabelle(props.condominioId)
    form.reset()
    form.coefficiente = 100
    form.percentuale_proprietario = 100
    form.percentuale_inquilino = 0
    form.percentuale_usufruttuario = 0

    nextTick(() => {
      document.getElementById('coefficiente')?.focus()
    })
  }
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

const submit = () => {
  if (!props.conto) return

  // Validazioni
  const errors = []

  // Verifica che il coefficiente sia valido
  if (!form.coefficiente || form.coefficiente < 0 || form.coefficiente > 100) {
    errors.push('Il coefficiente deve essere compreso tra 0 e 100')
    form.setError('coefficiente', 'Il coefficiente deve essere compreso tra 0 e 100')
  }

  // Verifica che la somma delle percentuali sia 100
  const sommaPercentuali = form.percentuale_proprietario + form.percentuale_inquilino + form.percentuale_usufruttuario
  if (sommaPercentuali !== 100) {
    errors.push('La somma delle percentuali deve essere 100%')
    form.setError('percentuale_proprietario', 'La somma delle percentuali deve essere 100%')
  }

  if (errors.length > 0) {
    return
  }

  emit('success', form.data())
  closeModal()
}
</script>

<template>
  <Dialog v-model:open="props.show" @update:open="closeModal">
    <DialogContent class="sm:max-w-[500px]">
      <DialogHeader>
        <DialogTitle>Associa tabella millesimale</DialogTitle>
      </DialogHeader>

      <form @submit.prevent="submit" class="space-y-4 mt-4">
        <!-- Tabella millesimale -->
        <div>
          <Label for="tabella" class="required">Tabella millesimale</Label>
          <v-select
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
              <div class="flex items-center">
                <span>{{ option.nome }}</span>
              </div>
            </template>
          </v-select>
          <InputError :message="form.errors.tabella_millesimale_id" />
        </div>

        <!-- Coefficiente di attribuzione -->
        <div>
          <Label for="coefficiente" class="required">Coefficiente di attribuzione</Label>
          <div class="relative">
            <Input 
              id="coefficiente"
              type="number" 
              v-model="form.coefficiente" 
              placeholder="100" 
              min="0" 
              max="100" 
              class="pr-12"
            />
            <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
              <span class="text-gray-500">%</span>
            </div>
          </div>
          <p class="text-xs text-gray-500 mt-1">
            Percentuale della spesa attribuita a questa tabella (0-100%)
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
                type="number" 
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
                type="number" 
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
                type="number" 
                v-model="form.percentuale_usufruttuario" 
                placeholder="0" 
                min="0" 
                max="100" 
              />
            </div>
          </div>
          <div class="flex justify-between items-center mt-2">
            <p class="text-xs text-gray-500">
              Totale: {{ form.percentuale_proprietario + form.percentuale_inquilino + form.percentuale_usufruttuario }}%
            </p>
            <Button 
              v-if="form.percentuale_proprietario + form.percentuale_inquilino + form.percentuale_usufruttuario !== 100"
              type="button" 
              variant="outline" 
              size="sm"
              class="text-xs"
              @click="form.percentuale_proprietario = 100; form.percentuale_inquilino = 0; form.percentuale_usufruttuario = 0;"
            >
              Reset
            </Button>
          </div>
          <InputError :message="form.errors.percentuale_proprietario" />
        </div>

        <DialogFooter class="flex justify-end space-x-2 mt-6">
          <Button type="button" variant="outline" @click="closeModal">Annulla</Button>
          <Button type="submit" :disabled="form.processing">
            {{ form.processing ? 'Salvataggio...' : 'Associa' }}
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
  border-color: #d1d5db !important; /* grigio chiaro, puoi cambiarlo */
}
</style>