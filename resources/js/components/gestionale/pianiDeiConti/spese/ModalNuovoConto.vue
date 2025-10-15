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
import vSelect from 'vue-select'
import type { Conto } from '@/types/gestionale/conti'
import type { Tabella } from '@/types/gestionale/tabelle'

interface Props {
  show: boolean
  contiDisponibili: Conto[]
  tabelle: Tabella[]
  condominioId: number
  esercizioId: number
  contoId: number
}

interface Emits {
  (e: 'update:show', value: boolean): void
  (e: 'success'): void
}

const props = defineProps<Props>()
const emit = defineEmits<Emits>()

const isCapitolo = ref(false)
const isSottoConto = ref(false)

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
  isCapitolo: false, // ← AGGIUNGI QUESTI CAMPI
  isSottoConto: false, // ← AGGIUNGI QUESTI CAMPI
})

// Watchers per sincronizzare i valori con il form
watch(isCapitolo, (val) => {
  if (val) {
    isSottoConto.value = false
    form.parent_id = null
  }
  form.isCapitolo = val // ← SINCRONIZZA CON IL FORM
})

watch(isSottoConto, (val) => {
  if (val) {
    isCapitolo.value = false
  }
  form.isSottoConto = val // ← SINCRONIZZA CON IL FORM
})

// Reset del form quando il modal viene chiuso
watch(() => props.show, (newVal) => {
  if (!newVal) {
    resetForm()
  }
})

const submit = () => {
  // DEBUG: verifica i dati prima dell'invio
  console.log('Dati che stanno per essere inviati:', {
    ...form.data(),
    isCapitolo: isCapitolo.value,
    isSottoConto: isSottoConto.value
  })

  form.post(route('admin.gestionale.esercizi.conti.spese.store', {
    condominio: props.condominioId,
    esercizio: props.esercizioId,
    conto: props.contoId
  }), {
    preserveScroll: true,
    onSuccess: () => {
      resetForm()
      emit('success')
      emit('update:show', false)
    }
  })
}

const resetForm = () => {
  form.reset()
  isCapitolo.value = false
  isSottoConto.value = false
  // Reimposta anche i valori nel form
  form.isCapitolo = false
  form.isSottoConto = false
}

const closeModal = () => {
  emit('update:show', false)
}
</script>

<template>
  <Dialog v-model:open="props.show" @update:open="closeModal">
    <DialogContent class="sm:max-w-[650px]">
      <DialogHeader>
        <DialogTitle>Nuova voce di spesa o capitolo</DialogTitle>
      </DialogHeader>

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
          <Label>Conto padre</Label>
          <v-select
            :options="props.contiDisponibili"
            label="nome"
            v-model="form.parent_id"
            placeholder="Seleziona conto padre"
            :reduce="(c: Conto) => c.id"
          />
          <InputError :message="form.errors.parent_id" />
        </div>

        <!-- Campi specifici solo se NON è capitolo -->
        <div v-if="!isCapitolo">
          <Label for="importo">Importo</Label>
          <Input
            id="saldo" 
            v-model="form.importo" 
            placeholder="0,00"
            v-on:focus="form.clearErrors('importo')"
          />
          <InputError :message="form.errors.importo" />
        </div>

        <div v-if="!isCapitolo">
          <Label>Tabella millesimale</Label>
          <v-select
            :options="props.tabelle"
            label="nome"
            v-model="form.tabella_millesimale_id"
            placeholder="Seleziona tabella millesimale"
            :reduce="(t: Tabella) => t.id"
          />
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
          <Button type="submit" :disabled="form.processing">Salva</Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>

<style src="vue-select/dist/vue-select.css"></style>