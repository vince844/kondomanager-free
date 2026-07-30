<script setup lang="ts">
import { computed } from 'vue'
import { Folder, FolderOpen, FileText, Lock, History, Plus, AlertTriangle } from 'lucide-vue-next'
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty'
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip'
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter'
import type { Conto } from '@/types/gestionale/conti'

interface Props {
  conti: Conto[]
  isParentLocked?: boolean
  selectedId?: number | string | null
  /** 'nome' (default storico) oppure 'codice'. Deve restare allineato a App\Support\OrdinamentoConti. */
  ordinamento?: 'nome' | 'codice'
}

const props = withDefaults(defineProps<Props>(), {
  isParentLocked: false,
  selectedId: null,
  ordinamento: 'nome'
})

interface Emits {
  (e: 'seleziona', conto: Conto): void
  (e: 'apriMovimenti', conto: Conto): void
}

const emit = defineEmits<Emits>()
const { euro } = useCurrencyFormatter()

const selezionaConto = (conto: Conto) => {
  emit('seleziona', conto)
}

const hasSottoconti = (conto: Conto) => {
  return conto.sottoconti && conto.sottoconti.length > 0
}

// Fatto esplicito e persistito lato server — mai indovinato da importo/parent_id
// (bug "voce a zero perde la tabella millesimale": una voce non ancora
// budgettizzata, importo a zero, veniva scambiata per un capitolo).
const isCapitolo = (conto: Conto) => {
  return !!conto.is_capitolo
}

// Confronto naturale: "A.2" prima di "A.10", "999" prima di "1020". Con il semplice
// localeCompare accadrebbe il contrario, ed è il tipo di dettaglio che fa sembrare
// l'ordinamento rotto. Stessa regola di App\Support\OrdinamentoConti lato server.
const collatore = new Intl.Collator('it', { numeric: true, sensitivity: 'base' })

// Le voci senza codice vanno in coda, raggruppate e ordinate per nome fra loro,
// invece di sparpagliarsi in mezzo alle altre come farebbe un campo vuoto.
const chiaveOrdinamento = (conto: Conto): string => {
  if (props.ordinamento === 'nome') return conto.nome

  const codice = (conto.codice ?? '').trim()
  return codice === '' ? `2_${conto.nome}` : `1_${codice}`
}

const contiOrdinati = computed(() => {
  return [...props.conti].sort((a, b) => {
    // I capitoli restano in cima al loro livello anche ordinando per codice: è la
    // struttura dell'albero, non una preferenza di visualizzazione.
    const aIsCap = isCapitolo(a)
    const bIsCap = isCapitolo(b)
    if (aIsCap && !bIsCap) return -1
    if (!aIsCap && bIsCap) return 1

    return collatore.compare(chiaveOrdinamento(a), chiaveOrdinamento(b))
  })
})

const getBarColor = (conto: Conto) => {
  const status = conto.stato_copertura
  if (status === 'over') {
    const hasShift = conto.dettaglio_copertura?.some(d => d.is_shifted)
    return hasShift ? 'bg-purple-500' : 'bg-red-500'
  }
  switch (status) {
    case 'full': return 'bg-emerald-500'
    case 'partial': return 'bg-blue-500'
    default: return 'bg-gray-200'
  }
}

// beta.30 — lo speso reale è un fatto DIVERSO dalla copertura da piano rate:
// una voce può essere coperta al 100% e non aver ancora speso nulla, o essere
// scoperta pur avendo già pagato. Il confronto è col preventivo vero
// (budget_originale_raw), non con `importo_raw`: quest'ultimo viene gonfiato allo
// speso dal controller quando lo sforo è già avvenuto, e darebbe sempre "resta 0".
const spesoInfo = (conto: Conto) => {
  const speso = conto.speso_raw ?? 0
  const preventivo = conto.budget_originale_raw ?? conto.importo_raw ?? 0
  const differenza = preventivo - speso
  // Una voce senza preventivo non "sfora": non ha un budget da superare. Sono le
  // sopravvenienze e gli imprevisti, che nascono fuori preventivo e stanno già in una
  // sezione dedicata che lo dichiara. Marcarle tutte in rosso sarebbe rumore, e
  // toglierebbe forza al rosso dove indica uno sforo vero.
  const isSforo = differenza < 0 && preventivo > 0

  return {
    speso,
    preventivo,
    hasSpesa: speso !== 0,
    isSforo,
    eccedenza: Math.abs(differenza),
    // Separatore decimale col punto, coerente con il badge "Copertura X%" del
    // pannello di dettaglio: le percentuali sono valori numerici, non importi
    // monetari, e nel prodotto seguono una convenzione loro.
    percentuale: isSforo
      ? Math.round((Math.abs(differenza) / preventivo) * 1000) / 10
      : null,
  }
}

const getTextColor = (conto: Conto) => {
  const status = conto.stato_copertura
  if (status === 'over') {
    const hasShift = conto.dettaglio_copertura?.some(d => d.is_shifted)
    return hasShift ? 'text-purple-600 font-bold' : 'text-red-600 font-bold'
  }
  switch (status) {
    case 'full': return 'text-emerald-600 font-bold'
    case 'partial': return 'text-blue-600 font-bold'
    default: return 'text-gray-600 font-medium'
  }
}
</script>

<template>
  <div class="albero-conti">
    <div v-if="props.conti.length === 0" class="text-center py-4 text-muted-foreground">
      <Empty class="border border-dashed">
        <EmptyHeader class="max-w-lg">
          <EmptyMedia variant="icon"><FolderOpen/></EmptyMedia>
          <EmptyTitle>Nessuna voce di spesa</EmptyTitle>
          <EmptyDescription>Crea la prima voce per iniziare.</EmptyDescription>
        </EmptyHeader>
      </Empty>
    </div>
    
    <div v-else class="space-y-0">
      <div v-for="conto in contiOrdinati" :key="conto.id" class="conto-item">
        <div 
          class="flex flex-col py-2 px-3 cursor-pointer transition-all border-b border-transparent relative"
          :class="{ 
            // STATO SELEZIONATO: Sfondo pieno, anello discreto, arrotondato SOLO a destra
            'bg-slate-100 dark:bg-slate-800 ring-1 ring-slate-200 dark:ring-slate-700 rounded-r-xl rounded-l-none': props.selectedId === conto.id,
            // STATO NON SELEZIONATO: Trasparente, nessun hover
            'bg-transparent': props.selectedId !== conto.id 
          }"
          @click="selezionaConto(conto)"
        >
          <div class="flex items-center gap-2">
            <Folder v-if="isCapitolo(conto)" 
                    class="w-4 h-4 transition-colors" 
                    :class="props.selectedId === conto.id ? 'text-slate-950' : 'text-slate-500'" />
            
            <FileText v-else 
                      class="w-4 h-4 transition-colors" 
                      :class="props.selectedId === conto.id ? 'text-slate-700' : 'text-slate-400'" />

            <div class="flex-1 truncate text-sm font-medium flex items-center gap-1.5">
              <span v-if="conto.codice" class="text-xs text-slate-400 mr-1.5">[{{ conto.codice }}]</span>
              <span class="truncate transition-colors" :class="{
                'font-bold text-slate-950': isCapitolo(conto),
                'text-slate-800': !isCapitolo(conto) && props.selectedId === conto.id,
                'text-slate-600': !isCapitolo(conto) && props.selectedId !== conto.id
              }">
                {{ conto.nome }}
              </span>
              <History v-if="!isCapitolo(conto) && conto.richiede_gia_versato"
                        class="w-3.5 h-3.5 text-indigo-500 shrink-0"
                        title="Voce da esercizio precedente — richiede il già versato" />
            </div>

            <div class="flex items-center gap-1.5">
              <Lock v-if="!isCapitolo(conto) && conto.has_rate_emesse" class="w-3 h-3 text-amber-500" />

              <template v-if="!isCapitolo(conto)">
                <!-- Preventivo a zero mostra "—", non "€ 0,00": una sopravvenienza non
                     ha un budget di zero euro, non ha proprio un budget. Lo zero si
                     legge come valore (o come dato mancante), il trattino come "non
                     previsto" — stesso criterio già usato per il consuntivo assente. -->
                <span class="w-24 text-right text-sm shrink-0"
                      :class="spesoInfo(conto).preventivo === 0
                        ? 'text-slate-300'
                        : (conto.tipo === 'spesa' ? 'text-slate-500' : 'text-green-600')">
                  {{ spesoInfo(conto).preventivo === 0 ? '—' : euro(spesoInfo(conto).preventivo) }}
                </span>

                <span v-if="!spesoInfo(conto).hasSpesa" class="w-24 text-right text-sm text-slate-300 shrink-0">—</span>

                <TooltipProvider v-else :delay-duration="150">
                  <Tooltip>
                    <TooltipTrigger as-child>
                      <button
                        type="button"
                        class="w-24 text-right text-sm shrink-0 flex items-center justify-end gap-1 cursor-pointer rounded-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-slate-400"
                        :class="spesoInfo(conto).isSforo
                          ? 'font-bold text-red-600 hover:text-red-700'
                          : 'font-semibold text-slate-900 dark:text-slate-100 hover:text-indigo-600'"
                        @click.stop="emit('apriMovimenti', conto)"
                      >
                        <AlertTriangle v-if="spesoInfo(conto).isSforo" class="w-3 h-3 shrink-0" />
                        {{ euro(spesoInfo(conto).speso) }}
                      </button>
                    </TooltipTrigger>
                    <!-- Il tooltip ha sfondo scuro (bg-foreground): il testo secondario
                         va schiarito partendo da `background`, non da muted-foreground,
                         che è pensato per fondi chiari e qui risulta illeggibile. -->
                    <TooltipContent class="text-center">
                      <div v-if="spesoInfo(conto).isSforo" class="font-semibold">
                        Sfora di {{ euro(spesoInfo(conto).eccedenza) }}<span v-if="spesoInfo(conto).percentuale !== null"> ({{ spesoInfo(conto).percentuale }}%)</span>
                      </div>
                      <div class="text-background/75">Clicca per i movimenti</div>
                    </TooltipContent>
                  </Tooltip>
                </TooltipProvider>
              </template>
            </div>
          </div>

          <div v-if="!isCapitolo(conto) && conto.percentuale_copertura !== undefined" class="mt-1 pl-6 pr-[200px]">
            <div class="flex-1 h-1.5 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden">
              <div class="h-full rounded-full transition-all duration-500" :class="getBarColor(conto)" :style="{ width: `${Math.min(conto.percentuale_copertura || 0, 100)}%` }"></div>
            </div>
            <div class="flex justify-between items-center mt-0.5">
              <span class="text-[9px] text-slate-400 uppercase font-semibold">Coperto da piano rate</span>
              <div class="flex items-center gap-0.5 text-[10px]">
                <span :class="getTextColor(conto)">{{ euro(conto.impegnato || 0) }}</span>
                <span class="text-slate-400">/</span>
                <span class="text-slate-600 dark:text-slate-400 font-medium">{{ conto.importo }}</span>
              </div>
            </div>

          </div>
        </div>

        <div v-if="isCapitolo(conto)">
          <div v-if="hasSottoconti(conto)" class="sottoconti border-l border-slate-200 dark:border-slate-800 ml-4">
            <AlberoDeiConti
              :conti="conto.sottoconti || []"
              :selected-id="props.selectedId"
              :is-parent-locked="false"
              :ordinamento="props.ordinamento"
              @seleziona="selezionaConto"
              @apri-movimenti="emit('apriMovimenti', $event)"
            />
          </div>
          <div v-else class="ml-8 py-2 pr-4 border-l border-dashed border-slate-200">
            <p class="text-[10px] text-slate-400 italic flex items-center gap-1.5"><Plus class="w-3 h-3" /> 
              Nessun sottoconto.
            </p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>