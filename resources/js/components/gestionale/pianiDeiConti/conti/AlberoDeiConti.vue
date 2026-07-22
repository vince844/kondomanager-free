<script setup lang="ts">
import { computed } from 'vue' 
import { Folder, FolderOpen, FileText, Lock, Plus } from 'lucide-vue-next'
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty'
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter'
import type { Conto } from '@/types/gestionale/conti'

interface Props {
  conti: Conto[]
  isParentLocked?: boolean 
  selectedId?: number | string | null
}

const props = withDefaults(defineProps<Props>(), {
  isParentLocked: false,
  selectedId: null
})

interface Emits {
  (e: 'seleziona', conto: Conto): void
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

const contiOrdinati = computed(() => {
  return [...props.conti].sort((a, b) => {
    const aIsCap = isCapitolo(a)
    const bIsCap = isCapitolo(b)
    if (aIsCap && !bIsCap) return -1
    if (!aIsCap && bIsCap) return 1
    return a.nome.localeCompare(b.nome)
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

            <div class="flex-1 truncate text-sm font-medium">
              <span v-if="conto.codice" class="text-xs text-slate-400 mr-1.5">[{{ conto.codice }}]</span>
              <span class="transition-colors" :class="{
                'font-bold text-slate-950': isCapitolo(conto),
                'text-slate-800': !isCapitolo(conto) && props.selectedId === conto.id,
                'text-slate-600': !isCapitolo(conto) && props.selectedId !== conto.id
              }">
                {{ conto.nome }}
              </span>
            </div>

            <div class="flex items-center gap-1.5">
              <Lock v-if="!isCapitolo(conto) && conto.has_rate_emesse" class="w-3 h-3 text-amber-500" />
              <span v-if="!isCapitolo(conto)" class="text-sm font-medium" :class="conto.tipo === 'spesa' ? 'text-slate-900' : 'text-green-600'">
                {{ conto.importo }} 
              </span>
            </div>
          </div>

          <div v-if="!isCapitolo(conto) && conto.percentuale_copertura !== undefined" class="mt-1 pl-6 pr-2">
            <div class="flex-1 h-1.5 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden">
              <div class="h-full rounded-full transition-all duration-500" :class="getBarColor(conto)" :style="{ width: `${Math.min(conto.percentuale_copertura || 0, 100)}%` }"></div>
            </div>
            <div class="flex justify-between items-center mt-0.5">
              <span class="text-[9px] text-slate-400 uppercase font-semibold">Copertura</span>
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
              @seleziona="selezionaConto"
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