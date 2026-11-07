<script setup lang="ts">

import { ref, computed } from 'vue'
import { router } from "@inertiajs/vue3"
import axios from 'axios'
import { ATTESA_ANTEPRIMA_MS, AVVISO_DATA_NON_VERIFICATA, avvisoErroreFuoriEsercizio, confermaDelloStorno, importoDaStornare as centesimiDaStornare, scelteDellaNatura, valeDalDellaNatura, type AnteprimaStorno, type NaturaStorno, type StatoAnteprimaStorno } from '@/lib/gestionale/incassi/confermaStorno'
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger, DropdownMenuSeparator } from '@/components/ui/dropdown-menu'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import RicevutaDialog from './RicevutaDialog.vue'
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

// La natura dello storno (1.12.0-beta.3, decisione 217, D1): la sceglie chi storna, e niente è già scelto. Non si ricorda: alla riapertura
// la finestra riparte vuota, perché è un fatto di quel versamento. Senza, il server non storna un incasso.
const natura = ref<NaturaStorno | null>(null)
const erroreStorno = ref<string | null>(null)
// Le parole delle due nature (decisione 219): per un incasso fatto solo di credito parlano della compensazione, non di un versamento.
const soloCredito = computed(() => props.incasso.importo_totale_cents === 0)
const scelte = computed(() => scelteDellaNatura(props.incasso, anteprima.value))
const avvisoFuoriEsercizio = computed(() => avvisoErroreFuoriEsercizio(anteprima.value, soloCredito.value))

// Il numero della richiesta dell'anteprima: una risposta arrivata dopo che la finestra è stata chiusa e riaperta descrive la finestra di
// prima, e non riscrive quella aperta adesso.
let richiestaAnteprima = 0

function handleStorno() {
  const mia = ++richiestaAnteprima
  anteprima.value = null
  statoAnteprima.value = 'in_attesa'
  natura.value = null
  erroreStorno.value = null
  isAlertOpen.value = true
  // Con un limite di attesa (LT-7): una risposta che non arriva diventa «fallita», e la conferma lo dice.
  axios.get(route(generateRoute('gestionale.movimenti-rate.storno.anteprima'), {
      condominio: props.condominioId,
      scrittura: props.incasso.id
  }), { timeout: ATTESA_ANTEPRIMA_MS })
    .then((risposta) => {
      if (mia !== richiestaAnteprima) return
      anteprima.value = risposta.data as AnteprimaStorno
      statoAnteprima.value = 'arrivata'
    })
    .catch(() => {
      if (mia !== richiestaAnteprima) return
      anteprima.value = null
      statoAnteprima.value = 'fallita'
    })
}

// I centesimi interi del server (`importo_totale_cents`): gli euro di `importo_totale_raw` rimoltiplicati per 100 erano una
// seconda conversione, in virgola mobile.
const importoDaStornare = computed(() => centesimiDaStornare(props.incasso));

function confirmStorno() {
  if (isStorning.value || !conferma.value.confermabile || natura.value === null) return
  isStorning.value = true
  erroreStorno.value = null

  router.post(route(generateRoute('gestionale.movimenti-rate.storno'), { 
      condominio: props.condominioId, 
      scrittura: props.incasso.id 
  }), { natura_storno: natura.value }, {
    preserveScroll: true,
    onSuccess: () => isAlertOpen.value = false,
    // Il rifiuto del server (la natura mancante o non valida) si legge nella finestra, che resta aperta.
    onError: (errori: Record<string, string>) => { erroreStorno.value = errori.natura_storno ?? Object.values(errori)[0] ?? 'Lo storno non è stato fatto.' },
    onFinish: () => isStorning.value = false
  })
}

// La ricevuta (1.12.0-beta.2): una voce sola, «Stampa o invia ricevuta», come «Stampa o invia» nel dettaglio dell'incasso; la finestra ha il
// bollo, la nota, i destinatari e i pulsanti per stampare e per inviare (Vincenzo, 05/11: due voci per la stessa finestra non servono).
const isRicevutaOpen = ref(false)
const apriRicevuta = () => { isRicevutaOpen.value = true }
</script>

<template>
  <DropdownMenu>
    <DropdownMenuTrigger as-child>
      <Button variant="ghost" class="h-8 w-8 p-0 data-[state=open]:bg-muted">
        <span class="sr-only">Apri menu</span>
        <MoreHorizontal class="h-4 w-4 text-muted-foreground" />
      </Button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end" class="w-max min-w-[200px]">
      <DropdownMenuLabel class="text-xs font-normal text-muted-foreground">Azioni su Prot. {{ incasso.numero_protocollo }}</DropdownMenuLabel>
      
      <DropdownMenuItem @click="router.visit(route(generateRoute('gestionale.movimenti-rate.show'), { condominio: condominioId, scrittura: incasso.id }))">
        <Eye class="w-4 h-4 mr-2" /> Dettagli
      </DropdownMenuItem>
      
      <!-- Anche di un incasso annullato: la finestra dice se la ricevuta era già partita per email e si può rimandare. -->
      <DropdownMenuItem class="whitespace-nowrap" @click="apriRicevuta">
        <Printer class="w-4 h-4 mr-2" /> {{ incasso.stato === 'annullata' ? 'Stampa o invia ricevuta (annullato)' : 'Stampa o invia ricevuta' }}
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
    :disabled="!conferma.confermabile || natura === null"
    @confirm="confirmStorno"
  >
    <span class="block">
      Stai per annullare l'incasso protocollo #{{ incasso.numero_protocollo || incasso.id }} di {{ euro(importoDaStornare) }}.
      Le rate torneranno «da pagare» per il condòmino. Continuare?
    </span>
    <!-- Perché lo storni: la scelta è di chi storna, e niente è già scelto. Accanto, da quale data vale lo storno (il giorno dell'amministratore). -->
    <span class="mt-3 block font-medium text-slate-800 dark:text-slate-100">Perché lo storni?</span>
    <span class="mt-1 block space-y-2" role="radiogroup" aria-label="Perché lo storni">
      <label v-for="s in scelte" :key="s.valore" class="flex cursor-pointer items-start gap-2 rounded-md border p-2 text-xs" :class="natura === s.valore ? 'border-red-300 bg-red-50 dark:border-red-800 dark:bg-red-950/30' : 'border-slate-200 dark:border-slate-700'">
        <input v-model="natura" type="radio" name="natura_storno" :value="s.valore" class="mt-0.5" />
        <span>
          <span class="block text-slate-800 dark:text-slate-100">{{ s.titolo }}</span>
          <span v-if="valeDalDellaNatura(anteprima, s.valore)" class="block text-slate-500 dark:text-slate-400">{{ valeDalDellaNatura(anteprima, s.valore) }}</span>
        </span>
      </label>
    </span>
    <span v-if="avvisoFuoriEsercizio" class="mt-3 flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-200">
      <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
      <span>{{ avvisoFuoriEsercizio }}</span>
    </span>
    <span v-if="statoAnteprima === 'fallita'" class="mt-3 block text-xs text-muted-foreground">{{ AVVISO_DATA_NON_VERIFICATA }}</span>
    <span v-if="erroreStorno" class="mt-3 block rounded-md border border-red-200 bg-red-50 p-3 text-xs text-red-700 dark:border-red-800 dark:bg-red-950/30 dark:text-red-200">{{ erroreStorno }}</span>
    <span v-if="statoAnteprima === 'in_attesa'" class="mt-3 block text-xs text-muted-foreground">
      Si verifica se la parte in più di questo incasso è già stata usata o rimborsata…
    </span>
    <span v-if="conferma.avviso" class="mt-3 flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
      <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
      <span>{{ conferma.avviso }}</span>
    </span>
    <!-- La ricevuta già uscita: la persona ha in mano la ricevuta di un versamento che non risulterà più. -->
    <span v-if="anteprima?.ricevuta" class="mt-3 flex items-start gap-2 rounded-md border border-sky-200 bg-sky-50 p-3 text-xs text-sky-800">
      <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0 text-sky-600" />
      <span>{{ anteprima.ricevuta }}</span>
    </span>
  </ConfirmDialog>

  <RicevutaDialog v-model:open="isRicevutaOpen" :condominio-id="condominioId" :incasso-id="incasso.id" />
</template>