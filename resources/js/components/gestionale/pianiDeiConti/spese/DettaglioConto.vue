<!-- components/gestionale/pianiDeiConti/spese/DettaglioConto.vue -->
<script setup lang="ts">
import { Edit, Trash2, Euro, FileText } from 'lucide-vue-next'
import type { Conto } from '@/types/gestionale/conti'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'

interface Props {
  conto: Conto | null
}

interface Emits {
  (e: 'elimina', conto: Conto): void
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

// Elimina conto
const eliminaConto = () => {
  if (props.conto && confirm(`Sei sicuro di voler eliminare "${props.conto.nome}"?`)) {
    emit('elimina', props.conto)
  }
}

// Verifica se è un capitolo
const isCapitolo = (conto: Conto) => {
  return conto.importo === 0 && conto.sottoconti && conto.sottoconti.length > 0
}
</script>

<template>
  <div class="dettaglio-conto">
    <Card v-if="!props.conto" class="text-center">
      <CardContent class="pt-6">
        <FileText class="w-12 h-12 mx-auto mb-3 text-muted-foreground" />
        <CardDescription class="text-lg font-medium">Nessuna voce selezionata</CardDescription>
        <CardDescription class="text-sm mt-1">
          Seleziona una voce dall'elenco per visualizzare i dettagli
        </CardDescription>
      </CardContent>
    </Card>

    <div v-else class="space-y-6">
      <!-- Header con azioni -->
      <Card>
        <CardHeader class="flex flex-row items-start justify-between space-y-0 pb-4">
          <div class="space-y-1">
            <CardTitle class="text-xl">{{ props.conto.nome }}</CardTitle>
            <div class="flex items-center gap-2">
              <Badge 
                :variant="props.conto.tipo === 'spesa' ? 'destructive' : 'default'"
              >
                {{ props.conto.tipo === 'spesa' ? 'Spesa' : 'Entrata' }}
              </Badge>
              <Badge 
                v-if="isCapitolo(props.conto)" 
                variant="secondary"
              >
                Capitolo
              </Badge>
            </div>
          </div>
          <div class="flex gap-2">
            <Button variant="outline" size="sm">
              <Edit class="w-4 h-4 mr-1" />
              Modifica
            </Button>
            <Button variant="outline" size="sm" @click="eliminaConto">
              <Trash2 class="w-4 h-4 mr-1" />
              Elimina
            </Button>
          </div>
        </CardHeader>
      </Card>

      <!-- Informazioni principali -->
      <Card>
        <CardHeader>
          <CardTitle class="text-lg">Informazioni</CardTitle>
        </CardHeader>
        <CardContent class="space-y-4">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="text-sm font-medium text-muted-foreground">Importo</label>
              <p class="text-lg font-semibold mt-1 flex items-center gap-1">
                <Euro class="w-4 h-4" />
                {{ formatImporto(props.conto.importo) }}
              </p>
            </div>
            <div>
              <label class="text-sm font-medium text-muted-foreground">Tipo</label>
              <p class="mt-1 capitalize">{{ props.conto.tipo }}</p>
            </div>
          </div>

          <!-- Descrizione -->
          <div v-if="props.conto.descrizione">
            <label class="text-sm font-medium text-muted-foreground">Descrizione</label>
            <p class="mt-1 whitespace-pre-wrap text-sm">{{ props.conto.descrizione }}</p>
          </div>

          <!-- Note -->
          <div v-if="props.conto.note">
            <label class="text-sm font-medium text-muted-foreground">Note</label>
            <p class="mt-1 whitespace-pre-wrap text-sm">{{ props.conto.note }}</p>
          </div>
        </CardContent>
      </Card>

      <!-- Sottoconti -->
      <Card v-if="props.conto.sottoconti && props.conto.sottoconti.length > 0">
        <CardHeader>
          <CardTitle class="text-lg">
            Sottoconti
            <Badge variant="secondary" class="ml-2">
              {{ props.conto.sottoconti.length }}
            </Badge>
          </CardTitle>
        </CardHeader>
        <CardContent>
          <div class="space-y-2">
            <div 
              v-for="sottoconto in props.conto.sottoconti"
              :key="sottoconto.id"
              class="flex justify-between items-center p-3 bg-muted rounded-lg border"
            >
              <div class="flex items-center gap-2">
                <FileText class="w-4 h-4 text-muted-foreground" />
                <span class="font-medium text-sm">{{ sottoconto.nome }}</span>
              </div>
              <div class="flex items-center gap-2">
                <Badge 
                  :variant="sottoconto.tipo === 'spesa' ? 'destructive' : 'default'"
                  class="text-xs"
                >
                  {{ sottoconto.tipo === 'spesa' ? 'Uscita' : 'Entrata' }}
                </Badge>
                <span class="text-sm font-medium">
                  {{ formatImporto(sottoconto.importo) }}
                </span>
              </div>
            </div>
          </div>
        </CardContent>
      </Card>
    </div>
  </div>
</template>