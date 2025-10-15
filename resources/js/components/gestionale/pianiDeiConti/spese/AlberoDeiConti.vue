<!-- components/gestionale/pianiDeiConti/spese/AlberoDeiConti.vue -->
<script setup lang="ts">
import { Folder, FileText } from 'lucide-vue-next'
import type { Conto } from '@/types/gestionale/conti'
import { Badge } from '@/components/ui/badge'

interface Props {
  conti: Conto[]
}

interface Emits {
  (e: 'seleziona', conto: Conto): void
}

const props = defineProps<Props>()
const emit = defineEmits<Emits>()

// Funzione per formattare l'importo in euro
const formatImporto = (importo: number) => {
  return new Intl.NumberFormat('it-IT', {
    style: 'currency',
    currency: 'EUR',
    minimumFractionDigits: 2
  }).format(importo / 100)
}

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
  return conto.importo === 0 && hasSottoconti(conto)
}
</script>

<template>
  <div class="albero-conti">
    <div v-if="props.conti.length === 0" class="text-center py-8 text-muted-foreground">
      <Folder class="w-12 h-12 mx-auto mb-3 text-muted-foreground/50" />
      <p class="text-sm">Nessun conto creato</p>
      <p class="text-xs text-muted-foreground mt-1">Crea il primo conto per iniziare</p>
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
          <Folder v-if="isCapitolo(conto)" class="w-4 h-4 text-muted-foreground" />
          <FileText v-else class="w-4 h-4 text-muted-foreground" />

          <!-- Nome conto -->
          <span class="text-sm font-medium flex-1 truncate">{{ conto.nome }}</span>

          <!-- Badge tipo -->
          <Badge 
            :variant="conto.tipo === 'spesa' ? 'destructive' : 'default'"
            class="text-xs"
          >
            {{ conto.tipo === 'spesa' ? 'Uscita' : 'Entrata' }}
          </Badge>

          <!-- Importo (solo se non è capitolo) -->
          <span 
            v-if="!isCapitolo(conto) && conto.importo > 0" 
            class="text-sm font-medium text-foreground ml-2"
          >
            {{ formatImporto(conto.importo) }}
          </span>
          <span v-else-if="isCapitolo(conto)" class="text-sm text-muted-foreground ml-2">
            Capitolo
          </span>
        </div>

        <!-- Sottoconti (sempre visibili se esistono) -->
        <div 
          v-if="hasSottoconti(conto)" 
          class="sottoconti border-l-2 border-muted ml-6"
        >
          <div
            v-for="sottoconto in conto.sottoconti"
            :key="sottoconto.id"
            class="flex items-center gap-2 py-2 hover:bg-muted/50 rounded cursor-pointer transition-colors border-b"
            :style="{ paddingLeft: '24px' }"
            @click="selezionaConto(sottoconto)"
          >
            <!-- Icona sottoconto -->
            <FileText class="w-4 h-4 text-muted-foreground" />

            <!-- Nome sottoconto -->
            <span class="text-sm font-medium flex-1 truncate">{{ sottoconto.nome }}</span>

            <!-- Badge tipo -->
            <Badge 
              :variant="sottoconto.tipo === 'spesa' ? 'destructive' : 'default'"
              class="text-xs"
            >
              {{ sottoconto.tipo === 'spesa' ? 'Uscita' : 'Entrata' }}
            </Badge>

            <!-- Importo sottoconto -->
            <span 
              v-if="sottoconto.importo > 0" 
              class="text-sm font-medium text-foreground ml-2"
            >
              {{ formatImporto(sottoconto.importo) }}
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