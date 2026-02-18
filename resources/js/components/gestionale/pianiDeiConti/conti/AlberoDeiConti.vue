<script setup lang="ts">

import { Folder, FolderOpen, FileText, Lock } from 'lucide-vue-next'
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty'
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter'
import type { Conto } from '@/types/gestionale/conti'

interface Props {
  conti: Conto[]
}

interface Emits {
  (e: 'seleziona', conto: Conto): void
}

const props = defineProps<Props>()
const emit = defineEmits<Emits>()
const { euro } = useCurrencyFormatter()

const selezionaConto = (conto: Conto) => {
  emit('seleziona', conto)
}

const hasSottoconti = (conto: Conto) => {
  return conto.sottoconti && conto.sottoconti.length > 0
}

const isCapitolo = (conto: Conto) => {
  return (conto.importo === '€ 0,00' || conto.importo === '0,00') && hasSottoconti(conto)
}

// ─── NUOVA LOGICA COLORI SINCRONIZZATA CON IL DETTAGLIO ───

// 1. Colore della barra (Background)
const getBarColor = (conto: Conto) => {
  const status = conto.stato_copertura
  
  if (status === 'over') {
    // Se c'è uno spostamento, è "Extra Budget" (Viola), altrimenti è Eccedenza (Rosso)
    const hasShift = conto.dettaglio_copertura?.some(d => d.is_shifted)
    return hasShift ? 'bg-purple-500' : 'bg-red-500'
  }

  switch (status) {
    case 'full': return 'bg-emerald-500'
    case 'partial': return 'bg-blue-500'
    default: return 'bg-gray-200'
  }
}

// 2. Colore del testo (Text)
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
    <div v-if="props.conti.length === 0" class="text-center py-8 text-muted-foreground">
      <Empty class="border border-dashed">
        <EmptyHeader class="max-w-lg">
          <EmptyMedia variant="icon">
            <FolderOpen/>
          </EmptyMedia>
          <EmptyTitle>Nessuna voce di spesa creata</EmptyTitle>
          <EmptyDescription>
            Crea la prima voce di spesa per iniziare a creare il tuo piano dei conti.
          </EmptyDescription>
        </EmptyHeader>
      </Empty>
    </div>
    
    <div v-else class="space-y-0">
      <div
        v-for="conto in props.conti"
        :key="conto.id"
        class="conto-item"
      >
        <div 
          class="flex flex-col py-2 px-3 hover:bg-muted rounded cursor-pointer transition-colors border-b"
          @click="selezionaConto(conto)"
        >
          <div class="flex items-center gap-2">
            <div class="w-6"></div>

            <Folder v-if="isCapitolo(conto)" class="w-4 h-4 text-indigo-500" />
            <FileText v-else class="w-4 h-4 text-gray-400" />

            <div class="flex-1 truncate text-sm font-medium">
              <span v-if="conto.codice" class="text-xs text-gray-500 mr-2">[{{ conto.codice }}]</span>
              <span :class="{'font-bold': isCapitolo(conto)}">{{ conto.nome }}</span>
            </div>

            <div class="flex items-center gap-2">
              <Lock v-if="conto.has_rate_emesse" class="w-3 h-3 text-amber-500" title="Bloccato da rate emesse" />
              
              <span 
                v-if="!isCapitolo(conto)" 
                class="text-sm font-medium"
                :class="conto.tipo === 'spesa' ? 'text-gray-900' : 'text-green-600'"
              >
                {{ conto.importo }} 
              </span>
            </div>
          </div>

          <div v-if="!isCapitolo(conto) && conto.percentuale_copertura !== undefined" class="mt-2 pl-12 pr-4">
              
              <div class="flex items-center gap-2">
                <div class="flex-1 h-2 bg-gray-100 rounded-full overflow-hidden border border-gray-200">
                  <div 
                    class="h-full rounded-full transition-all duration-500"
                    :class="getBarColor(conto)"
                    :style="{ width: `${Math.min(conto.percentuale_copertura || 0, 100)}%` }"
                  ></div>
                </div>
              </div>

              <div class="flex justify-between items-center mt-1">
                <span class="text-[9px] text-gray-400 uppercase tracking-wider font-semibold">
                  Copertura
                </span>
                <div class="flex items-center gap-1 text-[10px]">
                  
                  <span :class="getTextColor(conto)">
                     {{ euro(conto.impegnato || 0) }}
                  </span>
                  
                  <span class="text-gray-400">/</span>
                  
                  <span class="text-gray-600 font-medium">
                      {{ conto.importo }}
                  </span>
                </div>
              </div>

              <div v-if="conto.stato_copertura === 'partial' && (conto.impegnato || 0) == 0" class="text-[9px] text-amber-600 mt-0.5">
                ⚠ Nessun fondo diretto. Verifica capitolo padre.
              </div>

          </div>
        </div>

        <div 
          v-if="hasSottoconti(conto)" 
          class="sottoconti border-l-2 border-muted ml-6 border-b"
        >
          <AlberoDeiConti 
            :conti="conto.sottoconti || []" 
            @seleziona="selezionaConto"
          />
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.conto-item:last-child {
  border-bottom: none;
}
.sottoconti > div:last-child {
  border-bottom: none;
}
</style>