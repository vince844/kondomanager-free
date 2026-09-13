<script setup lang="ts">
import { ref, computed } from 'vue'
import { watchDebounced } from '@vueuse/core'
import { router, usePage } from '@inertiajs/vue3'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { Select, SelectContent, SelectGroup, SelectItem, SelectLabel, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Search, X } from 'lucide-vue-next'
import { usePermission } from '@/composables/permissions'
import { useTabellaServer } from '@/composables/useTabellaServer'
import type { Building } from '@/types/buildings'
import type { Esercizio } from '@/types/gestionale/esercizi'

interface ContoSelettore { id: number; codice: string; nome: string; tipo: string; righe: number }

const page = usePage<{
  condominio: Building
  esercizio: Esercizio
  conto: { id: number }
  conti: ContoSelettore[]
  periodo: { dal: string; al: string }
  filters: { search?: string; data_da?: string; data_a?: string; da?: string }
}>()
const { generateRoute } = usePermission()
const condominioId = computed(() => page.props.condominio.id)
const esercizioId = computed(() => page.props.esercizio.id)

const search = ref(page.props.filters?.search || '')
const dataDa = ref(page.props.filters?.data_da || '')
const dataA = ref(page.props.filters?.data_a || '')

const { filtra } = useTabellaServer(() =>
  route(generateRoute('gestionale.esercizi.conti.movimenti'), {
    condominio: condominioId.value,
    esercizio: esercizioId.value,
    contoContabile: page.props.conto.id,
  }),
)

const applyFilters = () => {
  filtra({
    search: search.value || null,
    data_da: dataDa.value || null,
    data_a: dataA.value || null,
  })
}

watchDebounced(search, applyFilters, { debounce: 300 })
watchDebounced([dataDa, dataA], applyFilters, { debounce: 100 })

const isFiltered = computed(() => !!(search.value || dataDa.value || dataA.value))

const resetFilters = () => {
  search.value = ''
  dataDa.value = ''
  dataA.value = ''
}

/**
 * Il selettore del conto (D21.1): si salta da un conto all'altro senza tornare allo Stato
 * patrimoniale. Cambia l'indirizzo, non un filtro — il conto è nel percorso, e i filtri di
 * ricerca non seguono, perché una ricerca su «Rossi» nel conto banca non ha senso sul conto
 * fornitori. Le foglie sono raggruppate per tipo, con il numero di righe nel periodo accanto.
 */
const TIPI: Record<string, string> = { attivo: 'Attivo', passivo: 'Passivo', costo: 'Costi', ricavo: 'Ricavi' }
const gruppi = computed(() => {
  const per: Record<string, ContoSelettore[]> = {}
  for (const c of page.props.conti) (per[c.tipo] ??= []).push(c)
  return Object.keys(TIPI).filter(t => per[t]?.length).map(t => ({ tipo: t, label: TIPI[t], conti: per[t] }))
})
const contoSelezionato = ref(String(page.props.conto.id))
const cambiaConto = (valore: unknown) => {
  const id = valore == null ? '' : String(valore)
  if (!id || Number(id) === page.props.conto.id) return
  router.visit(route(generateRoute('gestionale.esercizi.conti.movimenti'), {
    condominio: condominioId.value,
    esercizio: esercizioId.value,
    contoContabile: id,
    // Solo la provenienza segue il cambio di conto: i filtri no (vedi sopra).
    ...(page.props.filters?.da ? { da: page.props.filters.da } : {}),
  }))
}
</script>

<template>
  <div class="flex flex-wrap items-center gap-2 w-full">
    <Select v-model="contoSelezionato" @update:model-value="cambiaConto">
      <SelectTrigger class="h-8 w-[280px] text-xs style-chooser" aria-label="Scegli il conto">
        <SelectValue placeholder="Conto" />
      </SelectTrigger>
      <SelectContent position="popper" class="max-h-[320px] min-w-[320px]">
        <SelectGroup v-for="g in gruppi" :key="g.tipo">
          <SelectLabel class="text-[10px] uppercase tracking-wider text-slate-400">{{ g.label }}</SelectLabel>
          <SelectItem v-for="c in g.conti" :key="c.id" :value="String(c.id)">
            <span class="text-slate-400 tabular-nums">{{ c.codice }}</span> {{ c.nome }}
            <span v-if="c.righe > 0" class="text-slate-400 ml-1">· {{ c.righe }}</span>
          </SelectItem>
        </SelectGroup>
      </SelectContent>
    </Select>

    <div class="relative">
      <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-slate-400">
        <Search class="h-4 w-4" />
      </div>
      <Input
        placeholder="Cerca protocollo, descrizione o controparte..."
        v-model="search"
        class="pl-9 h-8 w-[220px] lg:w-[260px]"
      />
    </div>

    <!-- Dentro il periodo (D21.5): fuori di lì il riporto non è il saldo a quella data, e il
         controller scarta comunque un filtro che cade tutto fuori. -->
    <Input type="date" v-model="dataDa" :min="page.props.periodo.dal" :max="page.props.periodo.al" class="h-8 w-[140px] text-xs" title="Data del movimento da" />
    <Input type="date" v-model="dataA" :min="page.props.periodo.dal" :max="page.props.periodo.al" class="h-8 w-[140px] text-xs" title="Data del movimento a" />

    <Button
      v-if="isFiltered"
      variant="ghost"
      @click="resetFilters"
      class="h-8 px-2 lg:px-3 text-slate-500 hover:text-slate-700"
    >
      <X class="h-4 w-4 mr-1 lg:mr-2" />
      <span class="hidden lg:inline">Azzera filtri</span>
      <span class="inline lg:hidden">Azzera</span>
    </Button>
  </div>
</template>
