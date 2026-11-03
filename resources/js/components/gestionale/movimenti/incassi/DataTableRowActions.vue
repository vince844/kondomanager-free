<script setup lang="ts">

import { ref, computed } from 'vue'
import { router } from "@inertiajs/vue3"
import axios from 'axios'
import { ATTESA_ANTEPRIMA_MS, confermaDelloStorno, importoDaStornare as centesimiDaStornare, type AnteprimaStorno, type StatoAnteprimaStorno } from '@/lib/gestionale/incassi/confermaStorno'
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger, DropdownMenuSeparator } from '@/components/ui/dropdown-menu'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { usePermission } from "@/composables/permissions";
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { RotateCcw, MoreHorizontal, Eye, Printer, AlertTriangle } from 'lucide-vue-next'

const props = defineProps<{
  incasso: any,
  condominioId: number
}>()

const { generateRoute } = usePermission();
const { euro } = useCurrencyFormatter();
const isAlertOpen = ref(false)
const isStorning = ref(false)

// L'avviso della Coda 209 (1.11.0-beta.49, decisione 75.5): se la parte in più dell'incasso è già stata usata o rimborsata,
// lo storno fa ricomparire un debito, e la conferma lo dice prima. Lo misura il server. Rilievo S3 della Fase 1-bis: finché
// l'anteprima non arriva la conferma resta disabilitata, e se fallisce la conferma dice che la verifica non c'è stata
// (`confermaDelloStorno`), invece di tacere.
const anteprima = ref<AnteprimaStorno | null>(null)
const statoAnteprima = ref<StatoAnteprimaStorno>('in_attesa')
const conferma = computed(() => confermaDelloStorno(statoAnteprima.value, anteprima.value))

function handleStorno() {
  anteprima.value = null
  statoAnteprima.value = 'in_attesa'
  isAlertOpen.value = true
  // Con un limite di attesa (LT-7): una risposta che non arriva diventa «fallita», e la conferma lo dice.
  axios.get(route(generateRoute('gestionale.movimenti-rate.storno.anteprima'), {
      condominio: props.condominioId,
      scrittura: props.incasso.id
  }), { timeout: ATTESA_ANTEPRIMA_MS })
    .then((risposta) => {
      anteprima.value = risposta.data as AnteprimaStorno
      statoAnteprima.value = 'arrivata'
    })
    .catch(() => {
      anteprima.value = null
      statoAnteprima.value = 'fallita'
    })
}

// I centesimi interi del server (`importo_totale_cents`): gli euro di `importo_totale_raw` rimoltiplicati per 100 erano una
// seconda conversione, in virgola mobile.
const importoDaStornare = computed(() => centesimiDaStornare(props.incasso));

function confirmStorno() {
  if (isStorning.value || !conferma.value.confermabile) return
  isStorning.value = true

  router.post(route(generateRoute('gestionale.movimenti-rate.storno'), { 
      condominio: props.condominioId, 
      scrittura: props.incasso.id 
  }), {}, {
    preserveScroll: true,
    onSuccess: () => isAlertOpen.value = false,
    onFinish: () => isStorning.value = false
  })
}

const printRicevuta = () => {
    // Apri una nuova finestra per la stampa (da implementare rotta backend)
    // window.open(route(generateRoute('gestionale.incassi.stampa'), { ... }), '_blank');
    alert("Funzionalità stampa ricevuta in arrivo!");
}
</script>

<template>
  <DropdownMenu>
    <DropdownMenuTrigger as-child>
      <Button variant="ghost" class="h-8 w-8 p-0 data-[state=open]:bg-muted">
        <span class="sr-only">Apri menu</span>
        <MoreHorizontal class="h-4 w-4 text-muted-foreground" />
      </Button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end" class="w-[160px]">
      <DropdownMenuLabel class="text-xs font-normal text-muted-foreground">Azioni su Prot. {{ incasso.numero_protocollo }}</DropdownMenuLabel>
      
      <DropdownMenuItem @click="router.visit(route(generateRoute('gestionale.movimenti-rate.show'), { condominio: condominioId, scrittura: incasso.id }))">
        <Eye class="w-4 h-4 mr-2" /> Dettagli
      </DropdownMenuItem>
      
      <DropdownMenuItem @click="printRicevuta">
        <Printer class="w-4 h-4 mr-2" /> Stampa Ricevuta
      </DropdownMenuItem>

      <DropdownMenuSeparator />
      
      <DropdownMenuItem 
        v-if="incasso.stato !== 'annullata'" 
        @click="handleStorno" 
        class="text-red-600 focus:text-red-600 focus:bg-red-50"
      >
        <RotateCcw class="w-4 h-4 mr-2" /> Storna
      </DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>

  <ConfirmDialog
    v-model:modelValue="isAlertOpen"
    title="Storno Movimento"
    confirmText="Sì, Annulla Incasso"
    variant="destructive"
    :loading="isStorning"
    :disabled="!conferma.confermabile"
    @confirm="confirmStorno"
  >
    <span class="block">
      Stai per annullare l'incasso protocollo #{{ incasso.numero_protocollo || incasso.id }} di {{ euro(importoDaStornare) }}.
      Le rate torneranno «da pagare» per il condòmino. Continuare?
    </span>
    <span v-if="statoAnteprima === 'in_attesa'" class="mt-3 block text-xs text-muted-foreground">
      Si verifica se la parte in più di questo incasso è già stata usata o rimborsata…
    </span>
    <span v-if="conferma.avviso" class="mt-3 flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
      <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
      <span>{{ conferma.avviso }}</span>
    </span>
  </ConfirmDialog>
</template>