<!-- components/gestionale/pianiDeiConti/spese/AlberoDeiConti.vue -->
<script setup lang="ts">

import { Folder, FolderOpen, FileText } from 'lucide-vue-next'
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty'
import type { Conto } from '@/types/gestionale/conti'

interface Props {
  conti: Conto[]
}

interface Emits {
  (e: 'seleziona', conto: Conto): void
}

const props = defineProps<Props>()
const emit = defineEmits<Emits>()

// Seleziona un conto
const selezionaConto = (conto: Conto) => {
  emit('seleziona', conto)
}

// Verifica se un conto ha sottoconti
const hasSottoconti = (conto: Conto) => {
  return conto.sottoconti && conto.sottoconti.length > 0
}

// Verifica se è un capitolo (importo 0 e ha sottoconti)
const isCapitolo = (conto: Conto) => {
  // Ora importo è una stringa, controlla se è "€ 0,00" o simile
  return (conto.importo === '€ 0,00' || conto.importo === '0,00') && hasSottoconti(conto)
}

// Verifica se l'importo è positivo (per il colore)
const hasImportoPositivo = (conto: Conto) => {
  // Controlla se l'importo formattato non è "€ 0,00"
  return conto.importo !== '€ 0,00' && conto.importo !== '0,00'
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
        <!-- Conto principale -->
        <div 
          class="flex items-center gap-2 py-3 hover:bg-muted rounded cursor-pointer transition-colors border-b"
          @click="selezionaConto(conto)"
        >
          <!-- Spaziatura per allineamento -->
          <div class="w-6"></div>

          <!-- Icona conto -->
          <Folder v-if="isCapitolo(conto)" class="w-4 h-4" />
          <Folder v-else class="w-4 h-4 font-bold" />

          <!-- Nome conto -->
          <span class="text-sm font-medium flex-1 truncate font-bold text-md">{{ conto.nome }}</span>

          <!-- Importo (solo se non è capitolo e ha importo positivo) -->
          <span 
            v-if="!isCapitolo(conto) && hasImportoPositivo(conto)" 
            class="text-sm font-medium ml-2"
            :class="conto.tipo === 'spesa' ? 'text-red-600' : 'text-green-600'"
          >
            {{ conto.importo }} 
          </span>
        </div>

        <!-- Sottoconti (sempre visibili se esistono) -->
        <div 
          v-if="hasSottoconti(conto)" 
          class="sottoconti border-l-2 border-muted ml-6 border-b"
        >
          <div
            v-for="sottoconto in conto.sottoconti"
            :key="sottoconto.id"
            class="flex items-center gap-2 py-2 hover:bg-muted/50 rounded cursor-pointer transition-colors border-b"
            :style="{ paddingLeft: '24px' }"
            @click="selezionaConto(sottoconto)"
          >
            <!-- Icona sottoconto -->
            <FileText class="w-4 h-4" />

            <!-- Nome sottoconto -->
            <span class="text-sm font-medium flex-1 truncate">{{ sottoconto.nome }}</span>

            <!-- Importo sottoconto -->
            <span 
              v-if="hasImportoPositivo(sottoconto)" 
              class="text-sm font-medium text-foreground ml-2"
              :class="sottoconto.tipo === 'spesa' ? 'text-red-600' : 'text-green-600'"
            >
              {{ sottoconto.importo }} <!-- Già formattato -->
            </span>
          </div>
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