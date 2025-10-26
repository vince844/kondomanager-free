<script setup lang="ts">

import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import GestionaleLayout from '@/layouts/GestionaleLayout.vue'
import { usePermission } from '@/composables/permissions'
import { Button } from '@/components/ui/button'
import { List, Plus } from 'lucide-vue-next'
import Alert from "@/components/Alert.vue";
import ModalNuovoConto from '@/components/gestionale/pianiDeiConti/conti/ModalNuovoConto.vue'
import ModalModificaConto from '@/components/gestionale/pianiDeiConti/conti/ModalModificaConto.vue'
import AlberoDeiConti from '@/components/gestionale/pianiDeiConti/conti/AlberoDeiConti.vue'
import DettaglioConto from '@/components/gestionale/pianiDeiConti/conti/DettaglioConto.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import ModalAssociaTabella from '@/components/gestionale/pianiDeiConti/conti/ModalAssociaTabella.vue'
import type { BreadcrumbItem } from '@/types'
import type { Building } from '@/types/buildings'
import type { Esercizio } from '@/types/gestionale/esercizi'
import type { PianoDeiConti } from '@/types/gestionale/piani-dei-conti'
import type { Conto } from '@/types/gestionale/conti'
import type { Flash } from '@/types/flash';

const props = defineProps<{
  condominio: Building
  esercizio: Esercizio
  pianoConti: PianoDeiConti
  conti: Conto[]
}>()

const { generatePath } = usePermission()
const showModalNew = ref(false)
const showModalEdit = ref(false)
const showModalDelete = ref(false)
const contoSelezionato = ref<Conto | null>(null)
const contoDaEliminare = ref<Conto | null>(null)
const tabellaDaRimuovere = ref<number | null>(null)
const showModalAssociaTabella = ref(false)
const showModalRimuoviTabella = ref(false)
const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, href: '#' },
  { title: props.esercizio.nome, href: '#' },
  { title: 'Piani dei conti', href: generatePath('gestionale/:condominio/esercizi/:esercizio/piani-conti', { condominio: props.condominio.id, esercizio: props.esercizio.id }) },
  { title: props.pianoConti.nome, href: '#' }
])

// Funzione ricorsiva per trovare un conto o sottoconto per ID
function trovaContoInArray(conti: Conto[], id: number): Conto | null {
  for (const conto of conti) {
    if (conto.id === id) return conto;
    if (conto.sottoconti && conto.sottoconti.length) {
      const sottoconto = trovaContoInArray(conto.sottoconti, id);
      if (sottoconto) return sottoconto;
    }
  }
  return null;
}

// Watch per aggiornare contoSelezionato quando i conti cambiano
watch(
  () => props.conti,
  (newConti) => {
    if (contoSelezionato.value) {
      const contoAggiornato = trovaContoInArray(newConti, contoSelezionato.value.id);
      if (contoAggiornato) {
        contoSelezionato.value = contoAggiornato;
      } else {
        contoSelezionato.value = null;
      }
    }
  },
  { deep: true }
);

// Seleziona un conto
const selezionaConto = (conto: Conto) => {
  contoSelezionato.value = conto
}

// Apre il modal di modifica
const modificaConto = (conto: Conto) => {
  contoSelezionato.value = conto
  showModalEdit.value = true
}

// Apre il dialog di conferma eliminazione
const confermaEliminazione = (conto: Conto) => {
  contoDaEliminare.value = conto
  showModalDelete.value = true
}

// Apre il dialog di conferma rimozione tabella
const confermaRimozioneTabella = (payload: { conto: Conto, tabellaId: number }) => {
  contoSelezionato.value = payload.conto
  tabellaDaRimuovere.value = payload.tabellaId
  showModalRimuoviTabella.value = true
}

const eliminaConto = () => {
  if (!contoDaEliminare.value) return
  
  router.delete(route('admin.gestionale.esercizi.piani-conti.conti.destroy', {
    condominio: props.condominio.id,
    esercizio: props.esercizio.id,
    pianoConto: props.pianoConti.id,
    conto: contoDaEliminare.value.id
  }), {
    preserveScroll: true,
    onSuccess: () => {
      contoSelezionato.value = null
      contoDaEliminare.value = null
      showModalDelete.value = false
    },
    onError: () => {
      showModalDelete.value = false
    }
  })
}

// Chiude il dialog di conferma
const annullaEliminazione = () => {
  contoDaEliminare.value = null
  showModalDelete.value = false
}

const annullaRimozioneTabella = () => {
  tabellaDaRimuovere.value = null
  showModalRimuoviTabella.value = false
}

// Gestisce il successo della modifica
const onModificaSuccess = () => {
  showModalEdit.value = false
}

// Gestione eventi dal DettaglioConto
const onAggiungiTabella = (conto: Conto) => {
  contoSelezionato.value = conto
  showModalAssociaTabella.value = true
}

// Metodo per associare una tabella
const associaTabella = (dati: any) => {
  
  if (!contoSelezionato.value) {
    console.error('Nessun conto selezionato')
    return
  }

  router.post(route('admin.gestionale.esercizi.piani-conti.conti.associa-tabella', {
    condominio: props.condominio.id,
    esercizio: props.esercizio.id,
    pianoConto: props.pianoConti.id,
    conto: contoSelezionato.value.id
  }), dati, {
    preserveScroll: true,
    onSuccess: () => {
      showModalAssociaTabella.value = false
    },
    onError: (errors) => {
      console.error('Errore durante l\'associazione della tabella:', errors)
    }
  })
}

// Rimuove una tabella associata
const rimuoviTabella = () => {
  console.log('Rimuovi tabella con ID:', tabellaDaRimuovere.value)
  if (!contoSelezionato.value || !tabellaDaRimuovere.value) return

  router.delete(route('admin.gestionale.esercizi.piani-conti.conti.dissocia-tabella', {
    condominio: props.condominio.id,
    esercizio: props.esercizio.id,
    pianoConto: props.pianoConti.id,
    conto: contoSelezionato.value.id,
    tabella: tabellaDaRimuovere.value
  }), {
    preserveScroll: true,
    onSuccess: () => {
      showModalRimuoviTabella.value = false
      tabellaDaRimuovere.value = null
    },
    onError: (errors) => {
      console.error('Errore durante la dissociazione della tabella:', errors)
    }
  })
}

</script>

<template>
  <Head title="Gestione conto" />

  <GestionaleLayout :breadcrumbs="breadcrumbs">
    <div class="px-4 py-6">
      <div class="flex justify-between items-center mb-6">
        <div>
          <h2 class="text-2xl font-bold text-gray-900">Gestione spese</h2>
          <p class="text-gray-600 mt-1">{{ props.pianoConti.nome }}</p>
        </div>
   
        <div class="flex gap-2">
            <Button @click="showModalNew = true" class="h-9">
              <Plus class="w-4 h-4" />
              <span class="ml-1">Aggiungi voce di spesa</span>
            </Button>

            <Link
              as="button"
              :href="generatePath('gestionale/:condominio/esercizi/:esercizio/piani-conti', { condominio: props.condominio.id, esercizio: props.esercizio.id })"
              class="inline-flex items-center justify-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary/90"
            >
              <List class="w-4 h-4" />
              <span>Piani dei conti</span>
            </Link>
          </div>
        </div>

        <div v-if="flashMessage" class="py-3">
          <Alert :message="flashMessage.message" :type="flashMessage.type" />
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        
          <!-- Colonna sinistra: Albero dei conti -->
          <div class="bg-white border border-gray-200 rounded-lg shadow-sm">
            <div class="p-4 border-b border-gray-200">
              <h3 class="text-lg font-semibold text-gray-900">Elenco conti e sottoconti</h3>
            </div>
            <div class="p-4 max-h-[600px] overflow-y-auto"> 
              <AlberoDeiConti
                :conti="props.conti" 
                @seleziona="selezionaConto"
              />
            </div>
          </div>

          <!-- Colonna destra: Dettagli conto selezionato -->
          <div class="bg-white border border-gray-200 rounded-lg shadow-sm">
            <div class="p-4 border-b border-gray-200">
              <h3 class="text-lg font-semibold text-gray-900">Dettagli voce selezionata</h3>
            </div>
            <div class="p-4">
            <DettaglioConto
                :conto="contoSelezionato" 
                @elimina="confermaEliminazione" 
                @modifica="modificaConto"
                @aggiungi-tabella="onAggiungiTabella"
                @rimuovi-tabella="confermaRimozioneTabella"
              />
            </div>
          </div>
        </div>
      </div>

     <!-- Modal per creare nuovo conto -->
    <ModalNuovoConto
      :show="showModalNew"
      :condominio-id="props.condominio.id"
      :esercizio-id="props.esercizio.id"
      :piano-conto-id="props.pianoConti.id"
      @update:show="showModalNew = $event"
    />

    <!-- Modal per associare nuove tabelle -->
    <ModalAssociaTabella
      :show="showModalAssociaTabella"
      :conto="contoSelezionato"
      :condominio-id="props.condominio.id"
      @update:show="showModalAssociaTabella = $event"
      @success="associaTabella"
    />

    <!-- Modal per modificare conto -->
    <ModalModificaConto
      :show="showModalEdit"
      :conto="contoSelezionato"
      :condominio-id="props.condominio.id"
      :esercizio-id="props.esercizio.id"
      :piano-conto-id="props.pianoConti.id"
      @update:show="showModalEdit = $event"
      @success="onModificaSuccess"
    />

    <!-- Dialog di conferma rimozione tabella -->
    <ConfirmDialog
      v-model:modelValue="showModalRimuoviTabella"
      title="Rimuovi tabella associata"
      description="Sei sicuro di voler rimuovere questa tabella millesimale dal conto?"
      confirm-text="Rimuovi"
      cancel-text="Annulla"
      variant="destructive"
      @confirm="rimuoviTabella"
      @cancel="annullaRimozioneTabella"
    />

    <ConfirmDialog
      v-model:modelValue="showModalDelete"
      title="Sei sicuro di voler eliminare"
      description="Questa azione non è reversibile. Il conto verrà eliminato permanentemente."
      confirm-text="Elimina"
      cancel-text="Annulla"
      variant="destructive"
      @confirm="eliminaConto"
      @cancel="annullaEliminazione"
    />

  </GestionaleLayout>
</template>