<script setup lang="ts">

import { computed } from 'vue'
import { AlertTriangle, Percent, Edit, Trash2, FileText, Link, Plus, PieChart, Info, TrendingUp, ArrowRight, ArrowDownCircle, ArrowUpCircle, Folder, CheckCircle, AlertCircle, CircleDashed, CornerDownRight, Target, GitMerge, ShieldAlert, User, Wallet, Folders } from 'lucide-vue-next'
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty'
import { Item, ItemActions, ItemContent, ItemDescription, ItemTitle } from "@/components/ui/item"
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip'
import type { Conto } from '@/types/gestionale/conti'

interface Props {
  conto: Conto | null
}

interface Emits {
  (e: 'elimina', conto: Conto): void
  (e: 'select', conto: Conto): void
  (e: 'modifica', conto: Conto): void
  (e: 'aggiungi-tabella', conto: Conto): void 
  (e: 'rimuovi-tabella', payload: { conto: Conto, tabellaId: number }): void 
}

const props = defineProps<Props>()
const emit = defineEmits<Emits>()
const { euro } = useCurrencyFormatter()

const aggiungiTabella = () => { if (props.conto) emit('aggiungi-tabella', props.conto) }
const rimuoviTabella = (tabella: any) => { if (props.conto) emit('rimuovi-tabella', { conto: props.conto, tabellaId: tabella.id }) }
const eliminaConto = () => { if (props.conto) emit('elimina', props.conto) }
const modificaConto = () => { if (props.conto) emit('modifica', props.conto) }
const selectSottoconto = (sottoconto: Conto) => { emit('select', sottoconto) }

const isCapitolo = (conto: Conto) => {
  const importoZero = ['€ 0,00', '0,00', '€0,00', '0,00€'].some(v => conto.importo.includes(v))
  return conto.parent_id === null && importoZero
}

const getTabelleAssociate = () => props.conto?.tabelle_millesimali?.map(tm => ({
    id: tm.tabella_id,
    nome: tm.tabella?.nome ?? 'Tabella non trovata',
    coefficiente: tm.coefficiente,
    ripartizioni: tm.ripartizioni || []
})) || []

const getRipartizioniPerTabella = (tabellaId: number) => getTabelleAssociate().find(t => t.id === tabellaId)?.ripartizioni || []

const getPercentualeSoggetto = (tabellaId: number, soggetto: string) => {
  const ripartizione = getRipartizioniPerTabella(tabellaId).find(r => r.soggetto === soggetto)
  return ripartizione ? ripartizione.percentuale : 0
}

const statusColorClass = computed(() => {
  const stato = props.conto?.stato_copertura
  const dettagli = props.conto?.dettaglio_copertura || []
  
  if (stato === 'over') {
    const hasShift = dettagli.some(d => d.is_shifted)
    if (hasShift) return 'bg-purple-600'
    return 'bg-red-600'
  }

  switch (stato) {
    case 'full': return 'bg-emerald-500'
    case 'partial': return 'bg-blue-500'
    default: return 'bg-gray-300'
  }
})

</script>

<template>
  <div class="dettaglio-conto px-1">

    <div v-if="!props.conto" class="flex flex-col items-center justify-center min-h-[360px] text-muted-foreground">
      <Empty class="border border-dashed">
        <EmptyHeader class="max-w-lg">
          <EmptyMedia variant="icon">
            <FileText class="w-10 h-10 text-muted-foreground" />
          </EmptyMedia>
          <EmptyTitle>Nessuna voce selezionata</EmptyTitle>
          <EmptyDescription>
            Seleziona una voce di spesa dall'elenco per visualizzarne i dettagli
          </EmptyDescription>
        </EmptyHeader>
      </Empty>
    </div>

    <div v-else class="space-y-3">

      <!-- BANNER VOCE TECNICA (Sopravvenienza) -->
      <div v-if="props.conto.is_tecnico" 
          class="bg-amber-50 border border-amber-200 rounded-xl p-4 flex items-start gap-3">
        <AlertTriangle class="w-5 h-5 text-amber-600 shrink-0 mt-0.5" />
        <div>
          <h4 class="font-bold text-amber-900 text-sm">Voce tecnica — Sopravvenienza passiva</h4>
          <p class="text-xs text-amber-700/80 mt-1 leading-relaxed">
            Generata automaticamente dalla registrazione di una fattura fuori preventivo (Art. 1130-bis c.c.). 
            Non modificabile direttamente. Non finanziabile tramite piano ordinario.
          </p>
        </div>
      </div>

      <Card>
        <CardHeader class="flex flex-row items-start justify-between space-y-0">
          <div class="space-y-1 flex-1 min-w-0">
            <div class="flex items-center gap-2">
               <Badge variant="outline" v-if="props.conto.codice" class="text-xs text-muted-foreground">
                 {{ props.conto.codice }}
               </Badge>
               <CardTitle class="text-xl truncate" :title="props.conto.nome">
                 {{ props.conto.nome }}
               </CardTitle>
            </div>
            
            <div class="flex flex-wrap gap-2 pt-1">
              <Badge v-if="!isCapitolo(props.conto)" variant="outline" 
                class="gap-1.5 rounded-md px-2.5"
                :class="props.conto.tipo === 'spesa' ? 'bg-red-50 text-red-700 border-red-200' : 'bg-emerald-50 text-emerald-700 border-emerald-200'">
                <ArrowDownCircle v-if="props.conto.tipo === 'spesa'" class="w-3.5 h-3.5" />
                <ArrowUpCircle v-else class="w-3.5 h-3.5" />
                {{ props.conto.tipo === 'spesa' ? 'Spesa' : 'Entrata' }}
              </Badge>

              <Badge v-if="isCapitolo(props.conto)" variant="secondary" class="gap-1.5 rounded-md px-2.5">
                <Folder class="w-3.5 h-3.5" /> Capitolo
              </Badge>

              <Badge v-if="!isCapitolo(props.conto) && props.conto.stato_copertura" variant="outline" 
                class="gap-1.5 rounded-md px-2.5"
                :class="{
                  'bg-emerald-50 text-emerald-700 border-emerald-200': props.conto.stato_copertura === 'full',
                  'bg-blue-50 text-blue-700 border-blue-200': props.conto.stato_copertura === 'partial',
                  'bg-red-50 text-red-700 border-red-200': props.conto.stato_copertura === 'over',
                }">
                <CheckCircle v-if="props.conto.stato_copertura === 'full'" class="w-3.5 h-3.5" />
                <AlertCircle v-else-if="props.conto.stato_copertura === 'over'" class="w-3.5 h-3.5" />
                <CircleDashed v-else class="w-3.5 h-3.5" />
                Copertura {{ props.conto.percentuale_copertura }}%
              </Badge>
            </div>
          </div>

          <div class="flex gap-2 ml-4">
            <Button variant="outline" size="sm" @click="modificaConto">
              <Edit class="w-4 h-4 mr-2" /> Modifica
            </Button>
            <Button variant="ghost" size="icon" class="text-destructive hover:text-destructive hover:bg-destructive/10" @click="eliminaConto">
              <Trash2 class="w-4 h-4" />
            </Button>
          </div>
        </CardHeader>
      </Card>

      <Card>
        <CardHeader class="p-3 border-b bg-indigo-50/30 dark:bg-indigo-900/10 border-indigo-100/50 dark:border-indigo-900/50">
           <CardTitle class="text-sm font-bold uppercase tracking-wider text-indigo-900 dark:text-indigo-300 flex items-center gap-2">
            <Info class="w-4 h-4 text-indigo-600" /> Informazioni
          </CardTitle>
        </CardHeader>
        <CardContent class="grid gap-4 p-3">
          <div v-if="!isCapitolo(props.conto)" class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="space-y-1">
              <label class="text-xs font-medium text-muted-foreground uppercase">Importo</label>
              <p class="text-lg font-bold text-foreground">{{ props.conto.importo }}</p>
            </div>
            <div v-if="props.conto.fornitore_nome" class="space-y-1">
              <label class="text-xs font-medium text-muted-foreground uppercase">Fornitore suggerito</label>
              <p class="text-sm font-medium">{{ props.conto.fornitore_nome }}</p>
            </div>
          </div>
          
          <div class="space-y-1">
            <label class="text-xs font-medium text-muted-foreground uppercase">Descrizione</label>
            <p class="text-sm text-foreground/90 whitespace-pre-wrap leading-relaxed">
              {{ props.conto.descrizione || 'Nessuna descrizione disponibile' }}
            </p>
          </div>
          
          <div class="space-y-1">
            <label class="text-xs font-medium text-muted-foreground uppercase">Note</label>
            <p class="text-sm text-muted-foreground whitespace-pre-wrap leading-relaxed">
              {{ props.conto.note || 'Nessuna nota disponibile' }}
            </p>
          </div>
        </CardContent>
      </Card>

      <Card v-if="!isCapitolo(props.conto) && props.conto.importo_raw" class="mt-3">
        <CardHeader class="p-3 border-b bg-indigo-50/30 dark:bg-indigo-900/10 border-indigo-100/50 dark:border-indigo-900/50">
           <CardTitle class="text-sm font-bold uppercase tracking-wider text-indigo-900 dark:text-indigo-300 flex items-center gap-2">
            <PieChart class="w-4 h-4 text-indigo-600" /> Analisi copertura
          </CardTitle>
        </CardHeader>
        
        <CardContent class="p-3 space-y-6">
          
          <div>
            <div class="space-y-2 mb-4">
              <div class="flex justify-between text-sm">
                <span class="font-medium text-muted-foreground">Impegnato / Preventivato</span>
                <span class="font-bold">
                  {{ euro(props.conto.impegnato || 0) }} 
                  <span class="text-muted-foreground font-normal">/ {{ props.conto.importo }}</span>
                </span>
              </div>
              <div class="h-2 w-full bg-secondary rounded-full overflow-hidden">
                <div 
                  class="h-full transition-all duration-500 rounded-full"
                  :class="statusColorClass"
                  :style="{ width: `${Math.min(props.conto.percentuale_copertura || 0, 100)}%` }"
                ></div>
              </div>
            </div>

            <div class="flex flex-wrap items-center gap-3 px-1">
              <div class="flex items-center gap-1.5">
                <div class="w-2.5 h-2.5 rounded-full bg-blue-500"></div>
                <span class="text-[10px] text-muted-foreground font-medium uppercase tracking-wide">Parziale</span>
              </div>
              <div class="flex items-center gap-1.5">
                <div class="w-2.5 h-2.5 rounded-full bg-emerald-500"></div>
                <span class="text-[10px] text-muted-foreground font-medium uppercase tracking-wide">Coperto</span>
              </div>
              <div class="flex items-center gap-1.5">
                <div class="w-2.5 h-2.5 rounded-full bg-purple-600"></div>
                <span class="text-[10px] text-muted-foreground font-medium uppercase tracking-wide">Extra Budget (Spostamento)</span>
              </div>
              <div class="flex items-center gap-1.5">
                <div class="w-2.5 h-2.5 rounded-full bg-red-600"></div>
                <span class="text-[10px] text-muted-foreground font-medium uppercase tracking-wide">Eccedenza</span>
              </div>
            </div>
          </div>

          <div v-if="props.conto.dettaglio_copertura && props.conto.dettaglio_copertura.length > 0" class="rounded-md border">
             <Table>
              <TableHeader>
                <TableRow class="hover:bg-transparent">
                  <TableHead class="h-9">Piano rate</TableHead>
                  <TableHead class="h-9">Fonte</TableHead>
                  <TableHead class="h-9 text-right">Quota</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                <TableRow v-for="(item, idx) in props.conto.dettaglio_copertura" :key="idx">
                  <TableCell class="font-medium py-3">
                    <div class="flex flex-col gap-1">
                      <div class="flex items-center gap-3 group">
                        
                        <TooltipProvider :delay-duration="100">
                          <Tooltip>
                            <TooltipTrigger as-child>
                              <div class="relative flex items-center justify-center shrink-0 w-2 h-2 cursor-help">
                                <span 
                                  v-if="item.stato !== 'approvato'"
                                  class="absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-20"
                                ></span>
                                <span 
                                  class="relative inline-flex rounded-full h-2 w-2 border shadow-sm"
                                  :class="item.stato === 'approvato' 
                                    ? 'bg-emerald-500 border-emerald-600' 
                                    : 'bg-amber-400 border-amber-500'"
                                ></span>
                              </div>
                            </TooltipTrigger>
                            <TooltipContent side="top" class="text-[10px] font-bold uppercase tracking-wider px-2 py-1">
                              Piano {{ item.stato === 'approvato' ? 'Approvato' : 'in Bozza' }}
                            </TooltipContent>
                          </Tooltip>
                        </TooltipProvider>

                        <span class="truncate max-w-[200px] text-sm text-indigo-950 dark:text-slate-100 font-bold tracking-tight group-hover:text-primary transition-colors">
                          {{ item.piano }}
                        </span>
                      </div>
                      
                      <p v-if="item.is_shifted || item.note" class="text-[10px] text-muted-foreground font-normal pl-5 max-w-[220px] truncate italic" :title="item.note || ''">
                        {{ item.note }}
                      </p>
                    </div>
                  </TableCell>
                  
                  <TableCell class="py-3">
                    <Badge v-if="item.is_shifted" variant="outline" class="bg-purple-50 text-purple-700 border-purple-200 rounded-md gap-1">
                      <TrendingUp class="w-3 h-3" /> Spostamento
                    </Badge>
                    <Badge v-else-if="item.fonte === 'indiretta'" variant="outline" class="bg-amber-50 text-amber-700 border-amber-200 rounded-md gap-1">
                      <CornerDownRight class="w-3 h-3" /> Da Capitolo
                    </Badge>
                    <Badge v-else-if="item.fonte === 'mista'" variant="outline" class="bg-blue-50 text-blue-700 border-blue-200 rounded-md gap-1">
                      <GitMerge class="w-3 h-3" /> Mista
                    </Badge>
                    <Badge v-else variant="secondary" class="rounded-md gap-1 text-gray-700">
                      <Target class="w-3 h-3" /> Diretta
                    </Badge>
                  </TableCell>
                  
                  <TableCell class="text-right py-3 font-medium">
                    {{ euro(item.importo) }}
                  </TableCell>
                </TableRow>
              </TableBody>
            </Table>
          </div>
          
          <div v-else class="flex items-center gap-2 text-sm text-muted-foreground bg-muted/50 p-3 rounded-md">
            <Info class="w-4 h-4" />
            Nessun piano rate sta finanziando questa spesa al momento.
          </div>
        </CardContent>
      </Card>

      <Card v-if="(props.conto?.addebiti_personali?.length || 0) > 0 || (props.conto?.strategie_sforo?.length || 0) > 0" class="mt-3 overflow-hidden border-indigo-100 dark:border-indigo-900/30">
        
        <CardHeader class="p-3 border-b bg-indigo-50/30 dark:bg-indigo-900/10 border-indigo-100/50 dark:border-indigo-900/50">
          <CardTitle class="text-sm font-bold uppercase tracking-wider text-indigo-900 dark:text-indigo-300 flex items-center gap-2">
            <ShieldAlert class="w-4 h-4 text-indigo-600" /> Audit & Deviazioni 
          </CardTitle>
        </CardHeader>

        <CardContent class="p-3 space-y-6 pt-4">
          
          <div v-if="(props.conto?.addebiti_personali?.length || 0) > 0">
            <h4 class="text-[11px] font-bold uppercase tracking-widest text-slate-500 mb-2.5 flex items-center gap-1.5">
              <User class="w-3.5 h-3.5 text-slate-400" /> Composizione reale spesa
            </h4>
            
            <div class="rounded-md border">
              <Table>
                <TableHeader>
                  <TableRow class="hover:bg-transparent bg-slate-50 dark:bg-slate-900">
                    <TableHead class="h-9">Destinazione</TableHead>
                    <TableHead class="h-9">Metodo</TableHead>
                    <TableHead class="h-9 text-right">Importo</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  <TableRow v-for="(addebito, index) in props.conto?.addebiti_personali" :key="index" 
                      :class="addebito.tipo === 'condominiale' ? 'bg-blue-50/30 hover:bg-blue-50/50' : ''">
                    <TableCell class="py-3 font-medium text-slate-700 dark:text-slate-300">
                      <div class="flex items-center gap-2">
                        <Badge v-if="addebito.tipo === 'condominiale'" variant="outline" class="text-[9px] rounded-md bg-blue-100 text-blue-700 border-blue-200">Condominio</Badge>
                        <Badge v-else variant="outline" class="text-[9px] rounded-md bg-amber-100 text-amber-700 border-amber-200">Privato</Badge>
                        {{ addebito.immobile }}
                      </div>
                    </TableCell>
                    <TableCell class="py-3 text-slate-600">
                        <div class="truncate max-w-[110px] xl:max-w-[160px] cursor-help" :title="addebito.proprietario">
                            {{ addebito.proprietario }}
                        </div>
                    </TableCell>
                    <TableCell class="py-3 text-right font-bold" :class="addebito.tipo === 'condominiale' ? 'text-blue-700' : 'text-slate-900 dark:text-slate-100'">
                      {{ euro(addebito.importo) }}
                    </TableCell>
                  </TableRow>
                </TableBody>
              </Table>
            </div>
          </div>

          <div v-if="(props.conto?.strategie_sforo?.length || 0) > 0">
            <h4 class="text-[11px] font-bold uppercase tracking-widest text-slate-500 mb-2.5 flex items-center gap-1.5">
              <Wallet class="w-3.5 h-3.5 text-slate-400" /> Gestione Sfori Budget
            </h4>
            <div class="space-y-2">
              <div v-for="(sforo, idx) in props.conto?.strategie_sforo" :key="idx" 
                  class="flex items-start justify-between p-3 border rounded-lg bg-slate-50/50 dark:bg-slate-900/50 shadow-sm border-l-4"
                  :class="{
                    'border-l-emerald-500': sforo.strategia === 'fondo_riserva',
                    'border-l-indigo-500': sforo.strategia === 'conguaglio_fine_anno',
                    'border-l-amber-500': sforo.strategia !== 'fondo_riserva' && sforo.strategia !== 'conguaglio_fine_anno'
                  }">
                
                <div class="flex-1 min-w-0 pr-3">
                  <div class="flex items-center gap-2 mb-1.5">
                    <Badge v-if="sforo.strategia === 'fondo_riserva'" class="text-[9px] rounded-md font-black bg-emerald-600 text-white uppercase border-transparent">Coperto da Fondo</Badge>
                    <Badge v-else-if="sforo.strategia === 'conguaglio_fine_anno'" class="text-[9px] rounded-md font-black bg-indigo-600 text-white uppercase border-transparent">A Consuntivo</Badge>
                    <Badge v-else class="text-[9px] font-black bg-amber-600 text-white uppercase rounded-md border-transparent">Richiede Rate</Badge>
                  </div>
                  <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">
                    {{ sforo.motivazione }}
                  </p>
                </div>
                
                <div class="shrink-0 text-right self-center">
                  <span class="text-sm font-black" :class="sforo.strategia === 'fondo_riserva' ? 'text-emerald-600' : 'text-indigo-600'">
                    {{ euro(sforo.importo) }}
                  </span>
                </div>
              </div>
            </div>
          </div>

        </CardContent>
      </Card>

      <Card v-if="!isCapitolo(props.conto)">

        <CardHeader class="flex flex-row items-center justify-between space-y-0 p-3 border-b bg-indigo-50/30 dark:bg-indigo-900/10 border-indigo-100/50 dark:border-indigo-900/50">
          <CardTitle class="text-sm font-bold uppercase tracking-wider text-indigo-900 dark:text-indigo-300 flex items-center gap-2">
            <Percent class="w-4 h-4 text-indigo-600" /> Ripartizione ordinaria
            <Badge variant="outline" class="px-2 h-5 rounded-md bg-white dark:bg-slate-900 text-indigo-700 border-indigo-200 dark:border-indigo-800">
              {{ getTabelleAssociate().length }}
            </Badge>
          </CardTitle>
          <Button variant="outline" size="sm" class="h-7 text-xs" @click="aggiungiTabella">
            <Plus class="w-3.5 h-3.5 mr-1" /> Aggiungi
          </Button>
        </CardHeader>

        <CardContent class="p-2">
          <div v-if="getTabelleAssociate().length === 0" class="text-center py-6 text-muted-foreground border border-dashed rounded-md">
            <Link class="w-8 h-8 mx-auto mb-2 opacity-50" />
            <p class="text-sm">Nessuna tabella associata</p>
          </div>

          <div v-else class="rounded-md border">
            <Table>
              <TableHeader>
                <TableRow class="hover:bg-transparent">
                  <TableHead class="h-9">Nome</TableHead>
                  <TableHead class="h-9 text-center">Coeff.</TableHead>
                  <TableHead class="h-9 text-center">Prop.</TableHead>
                  <TableHead class="h-9 text-center">Inq.</TableHead>
                  <TableHead class="h-9 text-center">Usuf.</TableHead>
                  <TableHead class="h-9 w-[50px]"></TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                <TableRow v-for="tabella in getTabelleAssociate()" :key="tabella.id">
                  <TableCell class="font-medium py-2">{{ tabella.nome }}</TableCell>
                  <TableCell class="text-center py-2">
                    <Badge variant="outline" class="rounded-md">{{ tabella.coefficiente }}%</Badge>
                  </TableCell>
                  <TableCell class="text-center py-2">
                    <Badge :variant="getPercentualeSoggetto(tabella.id, 'proprietario') > 0 ? 'default' : 'outline'" class="rounded-md w-12 justify-center">
                      {{ getPercentualeSoggetto(tabella.id, 'proprietario') }}%
                    </Badge>
                  </TableCell>
                  <TableCell class="text-center py-2">
                    <Badge :variant="getPercentualeSoggetto(tabella.id, 'inquilino') > 0 ? 'default' : 'outline'" class="rounded-md w-12 justify-center">
                      {{ getPercentualeSoggetto(tabella.id, 'inquilino') }}%
                    </Badge>
                  </TableCell>
                  <TableCell class="text-center py-2">
                    <Badge :variant="getPercentualeSoggetto(tabella.id, 'usufruttuario') > 0 ? 'default' : 'outline'" class="rounded-md w-12 justify-center">
                      {{ getPercentualeSoggetto(tabella.id, 'usufruttuario') }}%
                    </Badge>
                  </TableCell>
                  <TableCell class="text-right py-2">
                    <Button variant="ghost" size="icon" class="h-8 w-8 text-destructive hover:bg-destructive/10" @click="rimuoviTabella(tabella)">
                      <Trash2 class="w-4 h-4" />
                    </Button>
                  </TableCell>
                </TableRow>
              </TableBody>
            </Table>
          </div>
        </CardContent>
      </Card>

      <Card v-if="props.conto.sottoconti && props.conto.sottoconti.length > 0" class="mt-3 overflow-hidden border-indigo-100 dark:border-indigo-900/30">
        
        <CardHeader class="p-3 border-b bg-indigo-50/30 dark:bg-indigo-900/10 border-indigo-100/50 dark:border-indigo-900/50 flex flex-row items-center justify-between space-y-0">
          <CardTitle class="text-sm font-bold uppercase tracking-wider text-indigo-900 dark:text-indigo-300 flex items-center gap-2">
            <Folders class="w-4 h-4 text-indigo-600" /> Sottoconti
          </CardTitle>
          <Badge variant="outline" class="px-2 h-5 rounded-md bg-white dark:bg-slate-900 text-indigo-700 border-indigo-200 dark:border-indigo-800">
            {{ props.conto.sottoconti.length }}
          </Badge>
        </CardHeader>

        <CardContent class="p-3 pt-4 grid gap-2.5">
          <div
            v-for="sottoconto in props.conto.sottoconti"
            :key="sottoconto.id"
            class="group cursor-pointer"
            @click="selectSottoconto(sottoconto)"
          >
            <Item class="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-xl hover:border-indigo-300 dark:hover:border-indigo-700 hover:shadow-md hover:bg-indigo-50/30 dark:hover:bg-indigo-900/20 transition-all duration-200">
              
              <ItemContent class="flex-1 min-w-0 pr-4">
                <ItemTitle class="text-sm font-bold text-slate-700 dark:text-slate-200 group-hover:text-indigo-700 dark:group-hover:text-indigo-400 transition-colors truncate">
                  {{ sottoconto.nome }}
                </ItemTitle>
                <ItemDescription v-if="sottoconto.descrizione" class="line-clamp-1 text-[11px] text-slate-500 mt-0.5">
                  {{ sottoconto.descrizione }}
                </ItemDescription>
              </ItemContent>
              
              <ItemActions class="shrink-0 flex items-center gap-2">
                <span class="text-sm font-black tabular-nums" 
                      :class="sottoconto.tipo === 'spesa' ? 'text-slate-900 dark:text-slate-100' : 'text-emerald-600'">
                  {{ sottoconto.importo }}
                </span>
                <ArrowRight class="w-4 h-4 text-indigo-400 opacity-0 -ml-2 group-hover:opacity-100 group-hover:ml-0 transition-all duration-300" />
              </ItemActions>

            </Item>
          </div>
        </CardContent>
      </Card>

    </div>
  </div>
</template>

<style scoped>
</style>