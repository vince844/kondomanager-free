<script setup lang="ts">
import { Edit, Trash2, FileText, Link, Plus } from 'lucide-vue-next'
import type { Conto } from '@/types/gestionale/conti'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty'
import { Item, ItemActions, ItemContent, ItemDescription, ItemTitle } from "@/components/ui/item"

interface Props {
  conto: Conto | null
}

interface Emits {
  (e: 'elimina', conto: Conto): void
  (e: 'select', conto: Conto): void
  (e: 'modifica', conto: Conto): void
  (e: 'aggiungi-tabella', conto: Conto): void 
  (e: 'rimuovi-tabella', payload: { conto: Conto, tabellaId: number }): void // CAMBIATO: tabellaId invece di tabella
}

const props = defineProps<Props>()
const emit = defineEmits<Emits>()

// Aggiungi tabella
const aggiungiTabella = () => {
  if (props.conto) {
    emit('aggiungi-tabella', props.conto)
  }
}

// Rimuovi tabella - CORRETTO: passa solo l'ID
const rimuoviTabella = (tabella: any) => {
  if (props.conto) {
    console.log('Rimozione tabella - ID:', tabella.id); // Debug
    emit('rimuovi-tabella', { 
      conto: props.conto, 
      tabellaId: tabella.id // Passa solo l'ID
    })
  }
}

// Delete account
const eliminaConto = () => {
  if (props.conto) {
    emit('elimina', props.conto)
  }
}

// Modifica conto
const modificaConto = () => {
  if (props.conto) {
    emit('modifica', props.conto) 
  }
}

// Check if account is a chapter
/* const isCapitolo = (conto: Conto) => {
  const importoZero = conto.importo === '€0,00' || conto.importo === '0,00€' || conto.importo.includes('0,00')
  return importoZero || (!!conto.sottoconti && conto.sottoconti.length > 0)
} */

const isCapitolo = (conto: Conto) => {
  const importoZero = 
    conto.importo === '€ 0,00' || 
    conto.importo === '0,00' || 
    conto.importo === '€0,00' || 
    conto.importo === '0,00€' ||
    conto.importo.includes('0,00')

  const haSottoconti = conto.sottoconti && conto.sottoconti.length > 0

  return importoZero && haSottoconti
}

// Get associated tables
const getTabelleAssociate = () => {
  return props.conto?.tabelle_millesimali?.map(tm => ({
    id: tm.tabella_id,
    nome: tm.tabella?.nome ?? 'Tabella non trovata',
    coefficiente: tm.coefficiente,
    ripartizioni: tm.ripartizioni || []
  })) || []
}

// Get distributions for a table
const getRipartizioniPerTabella = (tabellaId: number) => {
  return getTabelleAssociate().find(t => t.id === tabellaId)?.ripartizioni || []
}

// Get percentage for a subject
const getPercentualeSoggetto = (tabellaId: number, soggetto: string) => {
  const ripartizione = getRipartizioniPerTabella(tabellaId).find(r => r.soggetto === soggetto)
  return ripartizione ? ripartizione.percentuale : 0
}

// Select sub-account
const selectSottoconto = (sottoconto: Conto) => {
  emit('select', sottoconto)
}
</script>

<template>
  <div class="dettaglio-conto">
    <div v-if="!props.conto" class="py-8 text-muted-foreground">  
      <Empty class="border border-dashed">
        <EmptyHeader class="max-w-lg">
          <EmptyMedia variant="icon">
            <FileText/>
          </EmptyMedia>
          <EmptyTitle>Nessuna voce di spesa selezionata</EmptyTitle>
          <EmptyDescription>
            Seleziona una voce di spesa dall'elenco per visualizzarne i dettagli
          </EmptyDescription>
        </EmptyHeader>
      </Empty>
    </div>

    <div v-else class="space-y-6">
      <!-- Header with actions -->
      <Card>
        <CardHeader class="flex flex-row items-start justify-between space-y-0 pb-4">
          <div class="space-y-1 flex-1 min-w-0">
            <CardTitle class="text-lg truncate" :title="props.conto.nome">
              {{ props.conto.nome }}
            </CardTitle>
            <div class="flex items-center gap-2 flex-wrap">
              <Badge v-if="!isCapitolo(props.conto)" 
                    :class="(props.conto.tipo) === 'spesa' 
                        ? 'bg-red-500 text-white border-red-500 rounded' 
                        : 'bg-green-600 text-white border-green-600 rounded'">
                  {{ (props.conto.tipo) === 'spesa' ? 'Spesa' : 'Entrata' }}
              </Badge>
              <Badge v-if="isCapitolo(props.conto)" variant="secondary" class="rounded">Capitolo di spesa</Badge>
            </div>
          </div>
          <div class="flex gap-2 flex-shrink-0 ml-4">
            <Button variant="outline" size="sm" @click="modificaConto">
              <Edit class="w-4 h-4 mr-1" /> Modifica
            </Button>
            <Button variant="outline" size="sm" @click="eliminaConto">
              <Trash2 class="w-4 h-4 mr-1" /> Elimina
            </Button>
          </div>
        </CardHeader>
      </Card>

      <!-- Main information (non-chapters only) -->
      <Card >
        <CardHeader>
          <CardTitle class="text-lg">Informazioni</CardTitle>
        </CardHeader>
        <CardContent class="space-y-4">
          <div v-if="!isCapitolo(props.conto)" class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="text-sm font-medium text-muted-foreground">Importo</label>
              <p class="text-lg font-semibold mt-1 flex items-center gap-1">
                {{ props.conto.importo }}
              </p>
            </div>
          </div>
          <div>
            <label class="text-sm font-medium text-muted-foreground">Descrizione</label>
            <p class="mt-1 whitespace-pre-wrap text-sm">{{ props.conto.descrizione || 'Nessuna descrizione disponibile' }}</p>
          </div>
          <div>
            <label class="text-sm font-medium text-muted-foreground">Note</label>
            <p class="mt-1 whitespace-pre-wrap text-sm">{{ props.conto.note || 'Nessuna nota disponibile' }}</p>
          </div>
        </CardContent>
      </Card>

      <!-- Associated tables (non-chapters only) -->
      <Card v-if="!isCapitolo(props.conto)">
        <CardHeader>
          <CardTitle class="text-lg flex items-center justify-between">
            <div class="flex items-center">
              Tabelle millesimali associate
              <Badge variant="default" class="ml-2 text-xs py-0 px-1.5">{{ getTabelleAssociate().length }}</Badge>
            </div>
            <Button variant="outline" size="sm" @click="aggiungiTabella">
              <Plus class="w-4 h-4 mr-1" /> Aggiungi
            </Button>
          </CardTitle>
        </CardHeader>
        <CardContent>
          <div v-if="getTabelleAssociate().length === 0" class="text-muted-foreground">  
            <Empty class="border border-dashed">
              <EmptyHeader class="max-w-lg">
                <EmptyMedia variant="icon">
                  <Link/>
                </EmptyMedia>
                <EmptyTitle>Nessuna tabella associata</EmptyTitle>
                <EmptyDescription>
                  Questo conto non ha tabelle millesimali associate
                </EmptyDescription>
              </EmptyHeader>
            </Empty>
          </div>

          <Table v-else>
            <TableHeader>
              <TableRow>
                <TableHead>Nome</TableHead>
                <TableHead class="text-center">Coeff.</TableHead>
                <TableHead class="text-center">Prop.</TableHead>
                <TableHead class="text-center">Inq.</TableHead>
                <TableHead class="text-center">Usuf.</TableHead>
                <TableHead class="text-center">Azioni</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow v-for="tabella in getTabelleAssociate()" :key="tabella.id">
                <TableCell class="font-medium">{{ tabella.nome }}</TableCell>
                <TableCell class="text-center">
                  <Badge variant="outline">{{ tabella.coefficiente }}%</Badge>
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
                <TableCell class="text-center">
                  <Button variant="ghost" size="sm" @click="rimuoviTabella(tabella)">
                    <Trash2 class="w-4 h-4 text-red-500" />
                  </Button>
                </TableCell>
              </TableRow>
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      <!-- Sub-accounts -->
      <Card v-if="props.conto.sottoconti && props.conto.sottoconti.length > 0">
        <CardHeader>
          <CardTitle class="text-lg flex items-center">
              Sottoconti
              <Badge variant="default" class="ml-2 text-xs py-0 px-1.5">{{ props.conto.sottoconti.length }}</Badge>
          </CardTitle>
        </CardHeader>
        <CardContent>
          <div class="space-y-2">
            <div
              v-for="sottoconto in props.conto.sottoconti"
              :key="sottoconto.id"
              @click="selectSottoconto(sottoconto)"
            >
                <Item variant="muted">
                  <ItemContent>
                    <ItemTitle>{{ sottoconto.nome }}</ItemTitle>
                    <ItemDescription>
                      {{ sottoconto.descrizione }}
                    </ItemDescription>
                  </ItemContent>
                  <ItemActions :class="sottoconto.tipo === 'spesa' ? 'text-red-600' : 'text-green-600'">
                    {{ sottoconto.importo }}
                  </ItemActions>
                </Item>
            </div>
          </div>
        </CardContent>
      </Card>
    </div>
  </div>
</template>