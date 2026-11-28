<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Il riquadro «Le letture di fine anno» nel dettaglio di una voce con più tabelle: per ogni tabella la lettura del suo sottocontatore, oppure la
 * percentuale, oppure «per differenza» dal contatore generale. Il conguaglio divide la spesa con queste percentuali; le rate restano quelle del
 * preventivo, quindi il riquadro resta attivo anche con il piano approvato. In un esercizio chiuso si legge e basta: niente campi evidenziati, niente
 * riepilogo di ciò che manca.
 *
 * Le letture si chiedono al server quando si apre la voce (una alla volta, non con la pagina); il calcolo in pagina (`calcolaLetture`) è lo stesso
 * del server, che decide al salvataggio.
 */
import { computed, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { Gauge } from 'lucide-vue-next'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { calcolaLetture, numeroItaliano, percento, type ModoLettura, type RigaLettura } from '@/lib/gestionale/pianiConti/lettureDiFineAnno'

interface TabellaLetta {
  tabella_id: number
  nome: string
  preventivo: number
  modo: ModoLettura
  lettura: number | null
  percentuale: number | null
  consuntivo: number | null
}

interface Letture {
  esercizio_chiuso: boolean
  data_fine: string | null
  salvate: boolean
  da_riscrivere: boolean
  unita: string
  generale: number | null
  differenza_lettura: number | null
  tabelle: TabellaLetta[]
}

const props = defineProps<{
  condominioId: number
  esercizioId: number
  pianoContoId: number
  contoId: number
}>()

const MODI: Array<{ valore: ModoLettura; etichetta: string }> = [
  { valore: 'lettura', etichetta: 'Lettura' },
  { valore: 'percentuale', etichetta: 'Percentuale' },
  { valore: 'differenza', etichetta: 'Per differenza' },
]

const dati = ref<Letture | null>(null)
const erroreDiLettura = ref<string | null>(null)
const erroriDelServer = ref<string[]>([])
const salvataggio = ref(false)
const unita = ref('kWh')
const generale = ref('')
const righe = ref<Array<{ tabella_id: number; nome: string; preventivo: number; modo: ModoLettura; valore: string }>>([])

const url = computed(() =>
  `/admin/gestionale/${props.condominioId}/esercizi/${props.esercizioId}/piani-conti/${props.pianoContoId}/conti/${props.contoId}/letture-di-fine-anno`)

/** Un numero scritto all'italiana: «10.000» è diecimila, «2,5» due e mezzo. */
const numero = (testo: string): number | null => {
  const pulito = testo.trim().replace(/\s/g, '').replace(/\./g, '').replace(',', '.')
  if (pulito === '') return null
  const n = Number(pulito)

  return Number.isFinite(n) ? n : NaN
}
const scritto = (v: number | null) => v === null ? '' : numeroItaliano(v)

const riempi = (d: Letture) => {
  dati.value = d
  unita.value = d.unita || 'kWh'
  generale.value = scritto(d.generale)
  righe.value = d.tabelle.map(t => ({
    tabella_id: t.tabella_id, nome: t.nome, preventivo: t.preventivo, modo: t.modo,
    valore: t.modo === 'lettura' ? scritto(t.lettura) : t.modo === 'percentuale' ? scritto(t.percentuale) : '',
  }))
  erroriDelServer.value = []
}

const carica = async () => {
  erroreDiLettura.value = null
  try {
    const risposta = await fetch(url.value, { headers: { Accept: 'application/json' } })
    if (!risposta.ok) throw new Error(`HTTP ${risposta.status}`)
    riempi(await risposta.json())
  } catch {
    dati.value = null
    erroreDiLettura.value = 'Non è stato possibile leggere le letture di fine anno di questa voce.'
  }
}

watch(() => props.contoId, carica, { immediate: true })

const righeDelCalcolo = computed<RigaLettura[]>(() => righe.value.map(r => ({
  tabella_id: r.tabella_id, nome: r.nome, modo: r.modo,
  lettura: r.modo === 'lettura' ? numero(r.valore) : null,
  percentuale: r.modo === 'percentuale' ? numero(r.valore) : null,
})))

const esito = computed(() => calcolaLetture(numero(generale.value), righeDelCalcolo.value, unita.value))
const usaIlGenerale = computed(() => righe.value.some(r => r.modo === 'lettura'))
const sola = computed(() => !!dati.value?.esercizio_chiuso)
const giorno = computed(() => {
  const d = dati.value?.data_fine
  return d ? `${d.slice(8, 10)}/${d.slice(5, 7)}` : 'fine anno'
})

/**
 * I campi ancora da scrivere li dice il calcolo: si evidenziano loro, e in fondo c'è una riga sola con quanti sono; gli altri errori restano per intero. In un
 * esercizio chiuso non si scrive più: niente di tutto questo.
 */
const mancanti = computed(() => sola.value ? [] : esito.value.mancanti)
const mancaIlGenerale = computed(() => mancanti.value.some(m => m.tabella_id === null))
const mancaLaRiga = (r: { tabella_id: number }) => mancanti.value.some(m => m.tabella_id === r.tabella_id)
const altriErrori = computed(() => sola.value ? [] : esito.value.errori.filter(e => !esito.value.mancanti.some(m => m.frase === e)))

/** La riga sola sotto i campi da scrivere, con il consiglio che vale per il caso. */
const riepilogoDeiMancanti = computed(() => {
  const quanti = mancanti.value.length
  if (quanti === 0) return ''
  if (mancanti.value.every(m => m.tabella_id === null)) return 'Manca la lettura del contatore generale: scrivila nel campo evidenziato.'
  const daScrivere = quanti === 1 ? 'Manca 1 valore: scrivi il campo evidenziato' : `Mancano ${quanti} valori: scrivi i campi evidenziati`
  // «Per differenza» si consiglia solo se nessuna tabella lo è già: al più una.
  if (righe.value.some(r => r.modo === 'differenza')) return `${daScrivere}.`

  return `${daScrivere}, o scegli «Per differenza» per ${quanti === 1 ? 'quella tabella' : 'una tabella'}.`
})

const salva = () => {
  if (esito.value.errori.length > 0 || sola.value) return
  salvataggio.value = true
  router.put(url.value, {
    unita: unita.value,
    generale: usaIlGenerale.value ? numero(generale.value) : null,
    tabelle: righeDelCalcolo.value.map(r => ({ tabella_id: r.tabella_id, modo: r.modo, lettura: r.lettura, percentuale: r.percentuale })),
  }, {
    preserveScroll: true,
    preserveState: true,
    onSuccess: () => carica(),
    onError: (errori: Record<string, string>) => { erroriDelServer.value = Object.values(errori) },
    onFinish: () => { salvataggio.value = false },
  })
}

const togli = () => {
  if (sola.value) return
  salvataggio.value = true
  router.delete(url.value, {
    preserveScroll: true,
    preserveState: true,
    onSuccess: () => carica(),
    onError: (errori: Record<string, string>) => { erroriDelServer.value = Object.values(errori) },
    onFinish: () => { salvataggio.value = false },
  })
}

const annulla = () => { if (dati.value) riempi(dati.value) }
</script>

<template>
  <Card class="mt-3" data-testid="letture-di-fine-anno">
    <CardHeader class="flex flex-row items-center justify-between space-y-0 p-3 border-b bg-indigo-50/30 dark:bg-indigo-900/10 border-indigo-100/50 dark:border-indigo-900/50">
      <CardTitle class="text-sm font-bold uppercase tracking-wider text-indigo-900 dark:text-indigo-300 flex items-center gap-2">
        <Gauge class="w-4 h-4 text-indigo-600" /> Le letture di fine anno
      </CardTitle>
      <!-- In un esercizio chiuso non c'è niente da scrivere: il badge dice solo come stanno le cose. -->
      <Badge v-if="dati && sola && (!dati.salvate || dati.da_riscrivere)" variant="outline" class="rounded-md text-xs text-muted-foreground">
        {{ dati.salvate ? 'Non usate' : 'Non scritte' }}
      </Badge>
      <Badge v-else-if="dati && !dati.salvate" variant="outline" class="rounded-md text-xs bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-900/20 dark:text-amber-300 dark:border-amber-800">
        Da scrivere prima del rendiconto
      </Badge>
      <Badge v-else-if="dati && dati.da_riscrivere" variant="outline" class="rounded-md text-xs bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-900/20 dark:text-amber-300 dark:border-amber-800">
        Da riscrivere
      </Badge>
      <Badge v-else-if="dati" variant="outline" class="rounded-md text-xs bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-900/20 dark:text-emerald-300 dark:border-emerald-800">
        Salvate
      </Badge>
    </CardHeader>

    <CardContent class="p-3 space-y-3">
      <p class="text-xs text-muted-foreground">{{ dati && sola && !dati.salvate
        ? 'Le letture di fine anno di questa voce non sono state scritte: la spesa si divide con le percentuali del preventivo.'
        : 'Il conguaglio divide la spesa con queste percentuali, non con quelle del preventivo. Le rate non cambiano.' }}</p>

      <p v-if="erroreDiLettura" class="text-xs text-red-600 dark:text-red-400">{{ erroreDiLettura }}</p>

      <template v-if="dati">
        <p v-if="dati.da_riscrivere" class="text-xs text-amber-700 dark:text-amber-300">
          Le tabelle della voce sono cambiate dopo le letture: {{ sola ? 'il' : 'finché non le riscrivi il' }} conguaglio usa le percentuali del preventivo.
        </p>
        <p v-if="sola" class="text-xs text-muted-foreground">L'esercizio è chiuso: le letture si leggono e non si cambiano.</p>

        <!-- Contatore generale -->
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-muted/30 p-3 text-xs">
          <div class="flex items-center gap-2">
            <span class="font-semibold">Contatore generale</span>
            <div class="inline-flex overflow-hidden rounded-md border" role="group" aria-label="Unità di misura">
              <button v-for="u in ['kWh', 'm³']" :key="u" type="button" :disabled="sola" class="px-2 py-1 text-xs"
                      :class="unita === u ? 'bg-primary text-primary-foreground' : 'bg-background'" @click="unita = u">{{ u }}</button>
            </div>
          </div>
          <label class="flex items-center gap-2 whitespace-nowrap text-muted-foreground">Al {{ giorno }}
            <input v-model="generale" inputmode="decimal" :disabled="sola || !usaIlGenerale" aria-label="Lettura del contatore generale"
                   :placeholder="mancaIlGenerale ? 'da scrivere' : ''" :class="mancaIlGenerale ? 'border-amber-400 bg-amber-50 dark:bg-amber-950/30' : 'bg-background'"
                   class="w-24 rounded-md border px-2 py-1 text-right text-xs tabular-nums text-foreground disabled:opacity-60" />
          </label>
        </div>

        <!-- Una scheda per tabella: il nome per intero, il modo su una riga sola, la cifra a destra -->
        <div v-for="r in righe" :key="r.tabella_id" class="space-y-2 rounded-lg border p-3 text-xs">
          <p class="font-semibold">{{ r.nome }}</p>
          <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="inline-flex overflow-hidden whitespace-nowrap rounded-md border" role="group" :aria-label="`Come si legge ${r.nome}`">
              <button v-for="m in MODI" :key="m.valore" type="button" :disabled="sola" class="border-r px-2.5 py-1 text-xs last:border-r-0"
                      :class="r.modo === m.valore ? 'bg-primary text-primary-foreground' : 'bg-background'" @click="r.modo = m.valore">{{ m.etichetta }}</button>
            </div>
            <span v-if="r.modo === 'differenza'" class="inline-block w-24 rounded-md border bg-muted/40 px-2 py-1 text-right tabular-nums text-muted-foreground">
              {{ esito.differenzaLettura === null ? '—' : numeroItaliano(esito.differenzaLettura) }}
            </span>
            <span v-else class="inline-flex items-center gap-1">
              <input v-model="r.valore" inputmode="decimal" :disabled="sola" :aria-label="`${r.modo === 'lettura' ? 'Lettura' : 'Percentuale'} di ${r.nome}`"
                     :placeholder="mancaLaRiga(r) ? 'da scrivere' : ''" :class="mancaLaRiga(r) ? 'border-amber-400 bg-amber-50 dark:bg-amber-950/30' : 'bg-background'"
                     class="w-24 rounded-md border px-2 py-1 text-right text-xs tabular-nums disabled:opacity-60" />
              <span v-if="r.modo === 'percentuale'" class="text-muted-foreground">%</span>
            </span>
          </div>
          <p class="flex justify-between text-muted-foreground">
            <span>Preventivo <span class="tabular-nums">{{ percento(r.preventivo) }}</span></span>
            <span>Consuntivo <span class="font-semibold tabular-nums text-foreground">{{ esito.percentuali[r.tabella_id] === undefined ? '—' : percento(esito.percentuali[r.tabella_id]) }}</span></span>
          </p>
        </div>

        <div class="space-y-3 border-t pt-3">
          <div class="space-y-1 text-xs">
            <p v-if="esito.errori.length === 0" class="text-muted-foreground">
              Somma 100 % ·
              <span class="text-emerald-700 dark:text-emerald-400">{{ usaIlGenerale ? 'le letture tornano con il contatore generale' : 'le percentuali tornano' }}</span>
            </p>
            <p v-else-if="riepilogoDeiMancanti" class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-amber-800 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-300">
              {{ riepilogoDeiMancanti }}
            </p>
            <p v-for="e in altriErrori" :key="e" class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-amber-800 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-300">{{ e }}</p>
            <p v-for="e in erroriDelServer" :key="`s-${e}`" class="text-red-600 dark:text-red-400">{{ e }}</p>
          </div>
          <div v-if="!sola" class="flex flex-wrap justify-end gap-2">
            <Button v-if="dati.salvate" type="button" variant="ghost" size="sm" class="w-32" :disabled="salvataggio" @click="togli">Togli le letture</Button>
            <Button type="button" variant="outline" size="sm" class="w-32" :disabled="salvataggio" @click="annulla">Annulla</Button>
            <Button type="button" size="sm" class="w-32" :disabled="salvataggio || esito.errori.length > 0" @click="salva">Salva le letture</Button>
          </div>
        </div>

        <details class="text-xs">
          <summary class="cursor-pointer text-primary">Come funziona</summary>
          <ul class="mt-2 list-disc pl-5 space-y-1 text-muted-foreground">
            <li>Una lettura per tabella, oppure la percentuale scritta.</li>
            <li>Al più una tabella «per differenza»: il generale meno le altre.</li>
            <li>Le rate emesse restano; la differenza va nel conguaglio.</li>
            <li>Si scrivono una volta, a fine anno, prima del rendiconto.</li>
          </ul>
        </details>
      </template>
    </CardContent>
  </Card>
</template>
