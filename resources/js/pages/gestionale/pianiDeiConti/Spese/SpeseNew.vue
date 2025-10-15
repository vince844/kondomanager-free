<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import GestionaleLayout from '@/layouts/GestionaleLayout.vue'
import { usePermission } from '@/composables/permissions'
import { Button } from '@/components/ui/button'
import { List, Plus } from 'lucide-vue-next'
import type { BreadcrumbItem } from '@/types'
import type { Building } from '@/types/buildings'
import type { Esercizio } from '@/types/gestionale/esercizi'
import type { PianoDeiConti } from '@/types/gestionale/piani-dei-conti'
import type { Conto } from '@/types/gestionale/conti'
import type { Tabella } from '@/types/gestionale/tabelle'

// Importa i componenti
import AlberoDeiConti from '@/components/gestionale/pianiDeiConti/spese/AlberoDeiConti.vue'
import DettaglioConto from '@/components/gestionale/pianiDeiConti/spese/DettaglioConto.vue'
import ModalNuovoConto from '@/components/gestionale/pianiDeiConti/spese/ModalNuovoConto.vue'

const props = defineProps<{
  condominio: Building
  esercizio: Esercizio
  tabelle: Tabella[]
  conto: PianoDeiConti
  conti: Conto[]
  contiDisponibili: Conto[]
}>()

const { generatePath } = usePermission()

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, component: 'condominio-dropdown' } as any,
  { title: 'Gestione spese', href: '#' }
])

const showModal = ref(false) // CORRETTO: showModal invece di showDialog
const contoSelezionato = ref<Conto | null>(null)

// Seleziona un conto
const selezionaConto = (conto: Conto) => {
  contoSelezionato.value = conto
}

// Elimina conto
const eliminaConto = (conto: Conto) => {
  if (confirm(`Sei sicuro di voler eliminare "${conto.nome}"?`)) {
    router.delete(route('admin.gestionale.esercizi.conti.spese.destroy', {
      condominio: props.condominio.id,
      esercizio: props.esercizio.id,
      conto: props.conto.id,
      spesa: conto.id
    }), {
      preserveScroll: true,
      onSuccess: () => {
        contoSelezionato.value = null
      }
    })
  }
}

// Handler per il successo della creazione
const onContoCreato = () => {
  // Qui puoi aggiungere logica aggiuntiva dopo la creazione
  console.log('Conto creato con successo!')
}
</script>

<template>
  <Head title="Gestione spese" />

  <GestionaleLayout :breadcrumbs="breadcrumbs">
    <div class="px-4 py-6">
      <div class="flex justify-between items-center mb-6">
        <div>
          <h2 class="text-2xl font-bold text-gray-900">Gestione spese</h2>
          <p class="text-gray-600 mt-1">{{ props.conto.nome }}</p>
        </div>

        <div class="flex gap-2">
          <Button @click="showModal = true" class="h-9">
            <Plus class="w-4 h-4" />
            <span class="ml-1">Aggiungi voce di spesa</span>
          </Button>

          <Link
            as="button"
            :href="generatePath('gestionale/:condominio/esercizi/:esercizio/conti', { condominio: props.condominio.id, esercizio: props.esercizio.id })"
            class="inline-flex items-center justify-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary/90"
          >
            <List class="w-4 h-4" />
            <span>Piani dei conti</span>
          </Link>
        </div>
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
          <div class="p-6">
            <DettaglioConto 
              :conto="contoSelezionato" 
              @elimina="eliminaConto"
            />
          </div>
        </div>
      </div>
    </div>

    <!-- Modal per creare nuovo conto -->
    <!-- CORRETTO: usa showModal invece di showDialog -->
    <ModalNuovoConto
      :show="showModal"
      :conti-disponibili="props.contiDisponibili"
      :tabelle="props.tabelle"
      :condominio-id="props.condominio.id"
      :esercizio-id="props.esercizio.id"
      :conto-id="props.conto.id"
      @update:show="showModal = $event"
      @success="onContoCreato"
    />
  </GestionaleLayout>
</template>