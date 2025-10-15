<script setup lang="ts">
import { Edit, Trash2, Euro, FileText, Link } from 'lucide-vue-next'
import type { Conto } from '@/types/gestionale/conti'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'

interface Props {
  conto: Conto | null
}

interface Emits {
  (e: 'elimina', conto: Conto): void
  (e: 'select', conto: Conto): void // Added for selecting sub-accounts
}

const props = defineProps<Props>()
const emit = defineEmits<Emits>()

// Format amount in euros
const formatImporto = (importo: number) =>
  new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR', minimumFractionDigits: 2 }).format(importo / 100)

// Delete account
const eliminaConto = () => {
  if (props.conto && confirm(`Sei sicuro di voler eliminare "${props.conto.nome}"?`)) {
    emit('elimina', props.conto)
  }
}

// Check if account is a chapter
const isCapitolo = (conto: Conto) => conto.importo === 0 && conto.sottoconti?.length > 0

// Get associated tables
const getTabelleAssociate = () =>
  props.conto?.tabelle_millesimali?.map(tm => ({
    ...tm.tabella,
    pivot: { coefficiente: tm.coefficiente },
    ripartizioni: tm.ripartizioni || []
  })) || []

// Get distributions for a table
const getRipartizioniPerTabella = (tabellaId: string | number) =>
  getTabelleAssociate().find(t => t.id === Number(tabellaId))?.ripartizioni || []

// Get percentage for a subject
const getPercentualeSoggetto = (tabellaId: string | number, soggetto: string) => {
  const ripartizione = getRipartizioniPerTabella(tabellaId).find(r => r.soggetto === soggetto)
  return ripartizione ? parseFloat(ripartizione.percentuale) || 0 : 0
}

// Select sub-account
const selectSottoconto = (sottoconto: Conto) => {
  emit('select', sottoconto)
}

// Debug distributions
const debugRipartizioniTabelle = () => {
  console.log('=== DEBUG RIPARTIZIONI TABELLE ===')
  getTabelleAssociate().forEach((t, i) =>
    console.log(`Tabella ${i}: ${t.nome}, Ripartizioni:`, t.ripartizioni, `Coefficiente: ${t.pivot?.coefficiente}`)
  )
  console.log('==================================')
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
      <!-- Debug Button -->
      <Button @click="debugRipartizioniTabelle" variant="outline" size="sm">
        Debug Ripartizioni Tabelle
      </Button>

      <!-- Header with actions -->
      <Card>
        <CardHeader class="flex flex-row items-start justify-between space-y-0 pb-4">
          <div class="space-y-1">
            <CardTitle class="text-xl">{{ props.conto.nome }}</CardTitle>
            <div class="flex items-center gap-2">
              <Badge :variant="props.conto.tipo === 'spesa' ? 'destructive' : 'default'">
                {{ props.conto.tipo === 'spesa' ? 'Spesa' : 'Entrata' }}
              </Badge>
              <Badge v-if="isCapitolo(props.conto)" variant="secondary">Capitolo</Badge>
            </div>
          </div>
          <div class="flex gap-2">
            <Button variant="outline" size="sm">
              <Edit class="w-4 h-4 mr-1" /> Modifica
            </Button>
            <Button variant="outline" size="sm" @click="eliminaConto">
              <Trash2 class="w-4 h-4 mr-1" /> Elimina
            </Button>
          </div>
        </CardHeader>
      </Card>

      <!-- Main information -->
      <Card>
        <CardHeader>
          <CardTitle class="text-lg">Informazioni</CardTitle>
        </CardHeader>
        <CardContent class="space-y-4">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="text-sm font-medium text-muted-foreground">Importo</label>
              <p class="text-lg font-semibold mt-1 flex items-center gap-1">
                <Euro class="w-4 h-4" /> {{ formatImporto(props.conto.importo) }}
              </p>
            </div>
            <div>
              <label class="text-sm font-medium text-muted-foreground">Tipo</label>
              <p class="mt-1 capitalize">{{ props.conto.tipo }}</p>
            </div>
          </div>
          <div v-if="props.conto.descrizione">
            <label class="text-sm font-medium text-muted-foreground">Descrizione</label>
            <p class="mt-1 whitespace-pre-wrap text-sm">{{ props.conto.descrizione }}</p>
          </div>
          <div v-if="props.conto.note">
            <label class="text-sm font-medium text-muted-foreground">Note</label>
            <p class="mt-1 whitespace-pre-wrap text-sm">{{ props.conto.note }}</p>
          </div>
        </CardContent>
      </Card>

      <!-- Associated tables -->
      <Card>
        <CardHeader>
          <CardTitle class="text-lg">
            Tabelle Millesimali Associate
            <Badge variant="secondary" class="ml-2">{{ getTabelleAssociate().length }}</Badge>
          </CardTitle>
        </CardHeader>
        <CardContent>
          <div v-if="getTabelleAssociate().length === 0" class="text-center py-8 text-muted-foreground">
            <Link class="w-12 h-12 mx-auto mb-3 text-muted-foreground/50" />
            <p class="text-sm">Nessuna tabella associata</p>
            <p class="text-xs text-muted-foreground mt-1">
              Questo conto non ha tabelle millesimali associate
            </p>
          </div>
          <Table v-else>
            <TableHeader>
              <TableRow>
                <TableHead>Nome</TableHead>
                <TableHead class="text-center">Coeff.</TableHead>
                <TableHead class="text-center">Prop.</TableHead>
                <TableHead class="text-center">Inq.</TableHead>
                <TableHead class="text-center">Usuf.</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow v-for="tabella in getTabelleAssociate()" :key="tabella.id">
                <TableCell class="font-medium">{{ tabella.nome }}</TableCell>
                <TableCell class="text-center">
                  <Badge variant="outline">{{ tabella.pivot?.coefficiente || 100 }}%</Badge>
                </TableCell>
                <TableCell class="text-center">
                  <Badge :variant="getPercentualeSoggetto(tabella.id, 'proprietario') > 0 ? 'default' : 'outline'">
                    {{ getPercentualeSoggetto(tabella.id, 'proprietario') }}%
                  </Badge>
                </TableCell>
                <TableCell class="text-center">
                  <Badge :variant="getPercentualeSoggetto(tabella.id, 'inquilino') > 0 ? 'default' : 'outline'">
                    {{ getPercentualeSoggetto(tabella.id, 'inquilino') }}%
                  </Badge>
                </TableCell>
                <TableCell class="text-center">
                  <Badge :variant="getPercentualeSoggetto(tabella.id, 'usufruttuario') > 0 ? 'default' : 'outline'">
                    {{ getPercentualeSoggetto(tabella.id, 'usufruttuario') }}%
                  </Badge>
                </TableCell>
              </TableRow>
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      <!-- Sub-accounts -->
      <Card v-if="props.conto.sottoconti && props.conto.sottoconti.length > 0">
        <CardHeader>
          <CardTitle class="text-lg">
            Sottoconti
            <Badge variant="secondary" class="ml-2">{{ props.conto.sottoconti.length }}</Badge>
          </CardTitle>
        </CardHeader>
        <CardContent>
          <div class="space-y-2">
            <div
              v-for="sottoconto in props.conto.sottoconti"
              :key="sottoconto.id"
              class="flex justify-between items-center p-3 bg-muted rounded-lg border cursor-pointer hover:bg-muted/80"
              @click="selectSottoconto(sottoconto)"
            >
              <div class="flex items-center gap-2">
                <FileText class="w-4 h-4 text-muted-foreground" />
                <span class="font-medium text-sm">{{ sottoconto.nome }}</span>
              </div>
              <div class="flex items-center gap-2">
                <Badge :variant="sottoconto.tipo === 'spesa' ? 'destructive' : 'default'" class="text-xs">
                  {{ sottoconto.tipo === 'spesa' ? 'Uscita' : 'Entrata' }}
                </Badge>
                <span class="text-sm font-medium">{{ formatImporto(sottoconto.importo) }}</span>
              </div>
            </div>
          </div>
        </CardContent>
      </Card>
    </div>
  </div>
</template>