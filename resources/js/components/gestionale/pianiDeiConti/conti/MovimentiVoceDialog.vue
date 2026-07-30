<script setup lang="ts">
import { ref, watch } from 'vue'
import { Link } from '@inertiajs/vue3'
import { ExternalLink, Loader2, AlertCircle } from 'lucide-vue-next'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter'
import type { Conto } from '@/types/gestionale/conti'

interface Movimento {
  id: number
  scrittura_id: number
  data: string | null
  causale: string | null
  descrizione: string | null
  protocollo: string | null
  tipo_movimento: string
  tipo_movimento_label: string
  stato: string
  controparte: string | null
  importo: number
}

interface Props {
  open: boolean
  conto: Conto | null
  condominioId: number | string
  esercizioId: number | string
}

const props = defineProps<Props>()
const emit = defineEmits<{ (e: 'update:open', value: boolean): void }>()

const { euro } = useCurrencyFormatter()

const movimenti = ref<Movimento[]>([])
const totale = ref(0)
const troncato = ref(false)
const limite = ref(0)
const caricamento = ref(false)
const errore = ref<string | null>(null)

const formattaData = (data: string | null) => {
  if (!data) return '—'
  const d = new Date(data)
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString('it-IT')
}

const carica = async () => {
  if (!props.conto) return

  caricamento.value = true
  errore.value = null
  movimenti.value = []

  try {
    const url = `/admin/gestionale/${props.condominioId}/esercizi/${props.esercizioId}/voci/${props.conto.id}/movimenti`
    const risposta = await fetch(url, { headers: { Accept: 'application/json' } })

    if (!risposta.ok) throw new Error(`HTTP ${risposta.status}`)

    const dati = await risposta.json()
    movimenti.value = dati.movimenti ?? []
    totale.value = dati.totale ?? 0
    troncato.value = dati.troncato ?? false
    limite.value = dati.limite ?? 0
  } catch {
    errore.value = 'Non è stato possibile caricare i movimenti di questa voce.'
  } finally {
    caricamento.value = false
  }
}

// Si carica all'apertura, non al montaggio: la modale esiste sempre nel DOM ma i
// movimenti si chiedono solo per la voce su cui l'amministratore ha davvero cliccato.
watch(() => props.open, (aperta) => { if (aperta) carica() })
</script>

<template>
  <Dialog :open="props.open" @update:open="emit('update:open', $event)">
    <DialogContent class="sm:max-w-2xl">
      <DialogHeader>
        <DialogTitle>
          <span v-if="props.conto?.codice" class="text-muted-foreground font-normal">[{{ props.conto.codice }}]</span>
          {{ props.conto?.nome }}
        </DialogTitle>
        <DialogDescription>
          Fatture registrate e regolazioni immediate che compongono il consuntivo di questa voce.
        </DialogDescription>
      </DialogHeader>

      <!-- Il riepilogo è qui, non solo nel tooltip: chi naviga da tastiera o da
           touch il tooltip non lo riceve mai. -->
      <div v-if="props.conto" class="grid grid-cols-3 gap-2 text-sm border rounded-lg p-3 bg-slate-50 dark:bg-slate-900/50">
        <div>
          <div class="text-[10px] uppercase text-muted-foreground font-semibold">Preventivo</div>
          <div class="font-medium">{{ euro(props.conto.budget_originale_raw ?? props.conto.importo_raw ?? 0) }}</div>
        </div>
        <div>
          <div class="text-[10px] uppercase text-muted-foreground font-semibold">Consuntivo</div>
          <div class="font-medium">{{ euro(props.conto.speso_raw ?? 0) }}</div>
        </div>
        <div>
          <div class="text-[10px] uppercase text-muted-foreground font-semibold">Differenza</div>
          <div class="font-bold" :class="(props.conto.speso_raw ?? 0) > (props.conto.budget_originale_raw ?? props.conto.importo_raw ?? 0) ? 'text-red-600' : 'text-emerald-600'">
            {{ euro(Math.abs((props.conto.budget_originale_raw ?? props.conto.importo_raw ?? 0) - (props.conto.speso_raw ?? 0))) }}
          </div>
        </div>
      </div>

      <div v-if="caricamento" class="flex items-center justify-center gap-2 py-10 text-sm text-muted-foreground">
        <Loader2 class="w-4 h-4 animate-spin" /> Caricamento movimenti…
      </div>

      <div v-else-if="errore" class="flex items-start gap-2 py-6 px-3 text-sm text-red-600">
        <AlertCircle class="w-4 h-4 mt-0.5 shrink-0" /> {{ errore }}
      </div>

      <div v-else-if="movimenti.length === 0" class="py-10 text-center text-sm text-muted-foreground">
        Nessun movimento registrato su questa voce.
      </div>

      <div v-else class="max-h-[50vh] overflow-y-auto -mx-1 px-1">
        <table class="w-full text-sm">
          <thead class="text-[10px] uppercase text-muted-foreground border-b">
            <tr>
              <th class="text-left font-semibold py-2">Data</th>
              <th class="text-left font-semibold py-2">Causale</th>
              <th class="text-left font-semibold py-2">Tipo</th>
              <th class="text-right font-semibold py-2">Importo</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="m in movimenti" :key="m.id" class="border-b last:border-0 hover:bg-slate-50 dark:hover:bg-slate-900/50">
              <td class="py-2.5 whitespace-nowrap text-muted-foreground">{{ formattaData(m.data) }}</td>
              <td class="py-2.5 pr-2">
                <!-- Link Inertia, non <a href>: la pagina di dettaglio scrittura
                     ricava l'"Indietro" da window.history.state.back, che un
                     caricamento completo azzererebbe — si tornerebbe al Libro
                     Giornale invece che qui. -->
                <Link :href="`/admin/gestionale/${props.condominioId}/scritture/${m.scrittura_id}`"
                      class="text-slate-900 dark:text-slate-100 hover:text-indigo-600 hover:underline inline-flex items-center gap-1">
                  {{ m.causale || '—' }}
                  <ExternalLink class="w-3 h-3 shrink-0 opacity-50" />
                </Link>
                <div v-if="m.controparte" class="text-xs text-muted-foreground">{{ m.controparte }}</div>
              </td>
              <td class="py-2.5">
                <span class="text-[10px] px-1.5 py-0.5 rounded border"
                      :class="m.importo < 0
                        ? 'bg-amber-50 text-amber-700 border-amber-200'
                        : 'bg-slate-50 text-slate-600 border-slate-200'">
                  {{ m.tipo_movimento_label }}
                </span>
              </td>
              <td class="py-2.5 text-right font-medium whitespace-nowrap"
                  :class="m.importo < 0 ? 'text-amber-700' : 'text-slate-900 dark:text-slate-100'">
                {{ m.importo < 0 ? '−' : '' }}{{ euro(Math.abs(m.importo)) }}
              </td>
            </tr>
          </tbody>
          <tfoot class="border-t-2">
            <tr>
              <td colspan="3" class="py-2.5 text-right text-xs uppercase font-semibold text-muted-foreground">Totale</td>
              <td class="py-2.5 text-right font-bold whitespace-nowrap">{{ euro(totale) }}</td>
            </tr>
          </tfoot>
        </table>

        <p v-if="troncato" class="flex items-start gap-1.5 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-md p-2 mt-3">
          <AlertCircle class="w-3.5 h-3.5 mt-px shrink-0" />
          <span>
            Elenco limitato ai {{ limite }} movimenti più recenti. Il totale qui sopra resta
            quello completo della voce; per l'elenco integrale usa il Libro Giornale.
          </span>
        </p>

        <p class="text-xs text-muted-foreground pt-3">
          Gli storni compaiono con importo negativo: sono parte della storia che spiega il totale, non righe da nascondere.
        </p>
      </div>
    </DialogContent>
  </Dialog>
</template>
