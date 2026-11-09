<script setup lang="ts">
/**
 * Le carte dello studio, nelle impostazioni delle stampe: l'elenco delle carte con «Prova la carta», la carta nuova e la modifica, e «Quale carta
 * per quale documento». Una carta è a campi (lo studio e il condominio li scrive il programma, con il logo o senza) o con la carta intestata (lo studio
 * è stampato sulla carta, un PDF di una o due pagine o un'immagine A4, con i quattro margini dove la carta lascia lo spazio al testo); in tutte e due
 * il colore dei titoli e delle righe (nullo: i colori di sempre di ogni stampa). Ognuno dei cinque gruppi di documenti ha la sua carta, o la
 * predefinita; «La stessa per tutti» mette la stessa carta nei cinque. Una carta intestata appena caricata chiede di essere provata. Una carta che va
 * a campi tiene il file della carta intestata, salvo «Togli la carta intestata».
 *
 * Dopo una richiesta Inertia tiene la pagina e le dà le props nuove: le carte e i menu le leggono dal vivo, e la scelta per gruppo si rilegge
 * quando quella salvata cambia. Il messaggio di una richiesta delle carte esce qui, accanto a quello che si è fatto (la pagina resta dove è), e
 * `richiesta` dice alla pagina di non scriverlo anche in cima.
 */
import { computed, reactive, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import vSelect from 'vue-select'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import Alert from '@/components/Alert.vue'

interface Margini {
  alto: number
  basso: number
  sinistro: number
  destro: number
}
interface Carta {
  id: number
  nome: string
  aspetto: string
  mostra_logo: boolean
  colore: string | null
  predefinita: boolean
  usata_da: string[]
  carta_tipo?: string | null
  carta_pagine?: number | null
  margini?: Margini | null
  carta_illeggibile?: string | null
}
interface Gruppo {
  valore: string
  nome: string
  esempi: string
}

const props = defineProps<{
  carte: Carta[]
  cartaPerGruppo: Record<string, number | null>
  gruppi: Gruppo[]
  /** Il file più grande della carta intestata, già scritto per chi legge («10 MB»), dal server. */
  limiteCarta?: string
}>()

const emit = defineEmits<{ richiesta: [] }>()

interface Messaggio {
  type: 'success' | 'error' | 'warning' | 'info'
  message: string
}

/** Il valore di una scelta nei menu: l'id della carta, o «predefinita» per la carta predefinita (nullo). */
const PREDEFINITA = 'predefinita'
const valore = (id: number | null) => (id === null ? PREDEFINITA : String(id))
const id = (v: string) => (v === PREDEFINITA ? null : Number(v))

const predefinita = computed(() => props.carte.find((c) => c.predefinita) ?? null)

/** Le scelte dei menu dei gruppi: la predefinita con il suo nome intero, poi le carte. */
interface Scelta {
  valore: string
  etichetta: string
}
const sceltePerGruppo = computed<Scelta[]>(() => [
  { valore: PREDEFINITA, etichetta: `La carta predefinita${predefinita.value ? ` («${predefinita.value.nome}»)` : ''}` },
  ...props.carte.map((c) => ({ valore: String(c.id), etichetta: c.nome })),
])
const scelteStessaPerTutti = computed<Scelta[]>(() => [{ valore: '', etichetta: 'Una carta per ogni gruppo' }, ...sceltePerGruppo.value])
const riduci = (s: Scelta) => s.valore

// La scelta per gruppo, com'è arrivata: rimandata senza toccare niente è la stessa.
const scelteDa = (perGruppo: Record<string, number | null>) =>
  Object.fromEntries(props.gruppi.map((g) => [g.valore, valore(perGruppo[g.valore] ?? null)]))
const scelte = reactive<Record<string, string>>(scelteDa(props.cartaPerGruppo))

// La scelta salvata cambia (salvata da qui, o una carta scelta che non c'è più): i menu la rileggono. Un rifiuto rimanda la stessa scelta salvata, e
// i menu restano come li ha lasciati chi sceglieva.
watch(
  () => JSON.stringify(scelteDa(props.cartaPerGruppo)),
  () => Object.assign(scelte, scelteDa(props.cartaPerGruppo)),
)

/** Il messaggio dell'ultima richiesta delle carte, e dove esce: accanto alle carte o sotto la scelta per gruppo. */
const messaggio = ref<Messaggio | null>(null)
const doveIlMessaggio = ref<'carte' | 'scelte'>('carte')
const giroDelMessaggio = ref(0)
const leggiIlMessaggio = (dove: 'carte' | 'scelte') => (pagina: any) => {
  messaggio.value = (pagina?.props?.flash?.message as Messaggio | null | undefined) ?? null
  doveIlMessaggio.value = dove
  giroDelMessaggio.value++
}

const erroriScelte = ref<Record<string, string>>({})

/** «La stessa per tutti»: la carta dei cinque gruppi quando è la stessa; vuota quando i gruppi ne hanno di diverse. */
const stessaPerTutti = computed({
  get: () => {
    const tutte = Object.values(scelte)
    return tutte.length > 0 && tutte.every((v) => v === tutte[0]) ? tutte[0] : ''
  },
  set: (v: string) => {
    if (v === '') return
    for (const g of props.gruppi) scelte[g.valore] = v
  },
})

const salvaScelte = () => {
  emit('richiesta')
  router.put(route('impostazioni.stampe.carte.gruppi'), {
    carta_per_gruppo: Object.fromEntries(props.gruppi.map((g) => [g.valore, id(scelte[g.valore])])),
  }, {
    preserveScroll: true,
    onSuccess: (pagina: any) => {
      erroriScelte.value = {}
      leggiIlMessaggio('scelte')(pagina)
    },
    onError: (e: Record<string, string>) => {
      erroriScelte.value = e
      messaggio.value = null
    },
  })
}

// La carta nuova o da cambiare.
const COLORE_DI_PARTENZA = '#1e3a5f'
/** I margini proposti per una carta intestata nuova, in mm: si cambiano guardando la carta con «Prova la carta». */
const MARGINI_DI_PARTENZA: Margini = { alto: 45, basso: 25, sinistro: 20, destro: 20 }
const LATI = [
  { lato: 'alto', etichetta: 'Alto' },
  { lato: 'basso', etichetta: 'Basso' },
  { lato: 'sinistro', etichetta: 'Sinistro' },
  { lato: 'destro', etichetta: 'Destro' },
] as const
const aperto = ref(false)
const modulo = reactive({
  id: null as number | null, nome: '', mostra_logo: true, conColore: false, colore: COLORE_DI_PARTENZA, predefinita: false,
  aspetto: 'campi', file: null as File | null, margini: { ...MARGINI_DI_PARTENZA } as Margini, togliCarta: false,
})
const errori = ref<Record<string, string>>({})
/** La carta intestata già salvata della carta che si cambia, detta in parole («PDF di 2 pagine»), anche se la carta è tornata a campi; null se non c'è. */
const cartaSalvata = ref<string | null>(null)

/** Che cosa è il file della carta intestata di una carta, in parole: «PDF di 2 pagine», «immagine A4»; null se non c'è. Una carta tornata a campi lo tiene. */
const fileDellaCarta = (c: Carta): string | null => {
  if (!c.carta_tipo) return null
  if (c.carta_tipo === 'pdf') return c.carta_pagine === 1 ? 'PDF di una pagina' : `PDF di ${c.carta_pagine} pagine`
  return 'immagine A4'
}
/** La carta intestata di una carta che la usa, in parole; null per una carta a campi. */
const comeECaricata = (c: Carta): string | null => (c.aspetto === 'carta' ? fileDellaCarta(c) : null)

const nuova = () => {
  Object.assign(modulo, { id: null, nome: '', mostra_logo: true, conColore: false, colore: COLORE_DI_PARTENZA, predefinita: false,
    aspetto: 'campi', file: null, margini: { ...MARGINI_DI_PARTENZA }, togliCarta: false })
  cartaSalvata.value = null
  errori.value = {}
  aperto.value = true
}

const modifica = (c: Carta) => {
  Object.assign(modulo, { id: c.id, nome: c.nome, mostra_logo: c.mostra_logo, conColore: c.colore !== null, colore: c.colore ?? COLORE_DI_PARTENZA, predefinita: c.predefinita,
    aspetto: c.aspetto === 'carta' ? 'carta' : 'campi', file: null, margini: { ...(c.margini ?? MARGINI_DI_PARTENZA) }, togliCarta: false })
  cartaSalvata.value = fileDellaCarta(c)
  errori.value = {}
  aperto.value = true
}

const scegliIlFile = (e: Event) => {
  const input = e.target as HTMLInputElement
  modulo.file = input.files && input.files.length > 0 ? input.files[0] : null
}

/**
 * La carta appena salvata con un file nuovo: la sua riga chiede di provarla. Una carta nuova si riconosce dal nome (unico). Una carta che le stampe usano
 * già (la predefinita, o scelta per un gruppo) esce così da subito.
 */
const daProvare = ref<{ id: number | null; nome: string } | null>(null)
const vaProvata = (c: Carta) => daProvare.value !== null
  && (daProvare.value.id !== null ? daProvare.value.id === c.id : c.nome.toLowerCase() === daProvare.value.nome.toLowerCase())
const giaUsata = (c: Carta) => c.predefinita || c.usata_da.length > 0

const salvaCarta = () => {
  const dati: Record<string, any> = { nome: modulo.nome, mostra_logo: modulo.mostra_logo, colore: modulo.conColore ? modulo.colore : null, predefinita: modulo.predefinita,
    aspetto: modulo.aspetto }
  // Il file della carta intestata si toglie solo da una carta che va a campi, e solo se lo si chiede.
  if (modulo.aspetto === 'campi' && modulo.togliCarta && cartaSalvata.value !== null) dati.togli_carta_intestata = true
  if (modulo.aspetto === 'carta') {
    // Un margine cancellato resta vuoto: il server chiede di scriverlo.
    for (const { lato } of LATI) {
      const scritto = modulo.margini[lato] as number | string | null
      dati[`margine_${lato}`] = scritto === '' || scritto === null ? null : Number(scritto)
    }
  }
  const conFile = modulo.aspetto === 'carta' && modulo.file !== null
  const salvata = { id: modulo.id, nome: modulo.nome.trim().replace(/\s+/g, ' ') }
  const opzioni = {
    preserveScroll: true,
    onSuccess: (pagina: any) => {
      aperto.value = false
      daProvare.value = conFile ? salvata : null
      leggiIlMessaggio('carte')(pagina)
    },
    onError: (e: Record<string, string>) => { errori.value = e },
  }
  emit('richiesta')
  // Un file va con un modulo: per la carta che si cambia, con il metodo detto nel modulo (il server lo legge come un PUT).
  if (conFile) {
    const conIlFile = { ...dati, carta: modulo.file }
    if (modulo.id === null) {
      router.post(route('impostazioni.stampe.carte.store'), conIlFile, { ...opzioni, forceFormData: true })
    } else {
      router.post(route('impostazioni.stampe.carte.update', { carta: modulo.id }), { ...conIlFile, _method: 'put' }, { ...opzioni, forceFormData: true })
    }
  } else if (modulo.id === null) {
    router.post(route('impostazioni.stampe.carte.store'), dati, opzioni)
  } else {
    router.put(route('impostazioni.stampe.carte.update', { carta: modulo.id }), dati, opzioni)
  }
}

/** L'indirizzo della prova della carta: il PDF si apre in una scheda nuova. */
const indirizzoDellaProva = (c: Carta) => route('impostazioni.stampe.carte.prova', { carta: c.id })

const elimina = (c: Carta) => {
  if (window.confirm(`Eliminare la carta «${c.nome}»?`)) {
    emit('richiesta')
    router.delete(route('impostazioni.stampe.carte.destroy', { carta: c.id }), { preserveScroll: true, onSuccess: leggiIlMessaggio('carte') })
  }
}
</script>

<template>
  <div class="space-y-6">
    <section class="space-y-3" data-testid="carte-dello-studio">
      <Alert v-if="messaggio && doveIlMessaggio === 'carte'" :key="giroDelMessaggio" :message="messaggio.message" :type="messaggio.type" />
      <div>
        <h3 class="text-sm font-medium text-gray-700 dark:text-gray-200">Le carte dello studio</h3>
        <p class="text-sm text-muted-foreground">
          Una carta dice come esce la testata delle stampe: a campi, con lo studio scritto dal programma, con il logo o senza; o con la carta intestata dello
          studio sotto le stampe verticali (le orizzontali hanno la testata a campi della stessa carta). In tutte e due il colore dei titoli e delle righe; i
          caratteri restano quelli di sempre. La carta predefinita la usano i documenti per cui non ne scegli un'altra. «Prova la carta» apre un PDF con dati
          d'esempio (un condominio, un destinatario e cifre inventati): una lettera e una pagina orizzontale con la testata della carta.
        </p>
      </div>

      <ul class="divide-y rounded-lg border">
        <li v-for="c in carte" :key="c.id" class="flex flex-wrap items-center gap-3 p-3" :data-testid="`carta-${c.id}`">
          <div class="min-w-0 flex-1 space-y-0.5">
            <div class="flex items-center gap-2">
              <span class="text-sm font-medium">{{ c.nome }}</span>
              <span v-if="c.predefinita" class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">Predefinita</span>
            </div>
            <div class="flex flex-wrap items-center gap-x-3 text-xs text-muted-foreground">
              <span>{{ comeECaricata(c) ? `Carta intestata: ${comeECaricata(c)}` : 'A campi' }}</span>
              <span>{{ c.mostra_logo ? (c.aspetto === 'carta' ? 'Con il logo (sulle orizzontali)' : 'Con il logo') : 'Senza il logo' }}</span>
              <span v-if="c.colore" class="inline-flex items-center gap-1">
                <span class="inline-block h-3 w-3 rounded-sm border" :style="{ backgroundColor: c.colore }"></span>{{ c.colore }}
              </span>
              <span v-else>I colori di sempre</span>
              <span v-if="c.usata_da.length > 0">Usata per: {{ c.usata_da.join(', ') }}</span>
            </div>
            <p v-if="c.carta_illeggibile" class="text-xs text-red-600" data-testid="carta-illeggibile">{{ c.carta_illeggibile }}</p>
            <p v-if="vaProvata(c)" class="text-xs text-amber-700 dark:text-amber-400" data-testid="da-provare">
              {{ giaUsata(c) ? 'La carta intestata è caricata, e le stampe che la usano escono già così: aprila con' : 'La carta intestata è caricata: prima di usarla, aprila con' }}
              <a :href="indirizzoDellaProva(c)" target="_blank" rel="noopener" class="font-medium underline">Prova la carta</a>
              e guarda dove cadono il condominio, il testo e il piede; se serve, cambia i margini.
            </p>
          </div>
          <div class="flex items-center gap-2">
            <a :href="indirizzoDellaProva(c)" target="_blank" rel="noopener"
              class="inline-flex h-8 items-center rounded-md border px-3 text-sm hover:bg-accent" data-testid="prova-carta">Prova la carta</a>
            <Button type="button" variant="outline" size="sm" data-testid="modifica-carta" @click="modifica(c)">Modifica</Button>
            <Button v-if="!c.predefinita" type="button" variant="outline" size="sm" class="text-red-600" data-testid="elimina-carta" @click="elimina(c)">Elimina</Button>
          </div>
        </li>
      </ul>

      <Button v-if="!aperto" type="button" variant="outline" size="sm" data-testid="nuova-carta" @click="nuova">Nuova carta</Button>

      <div v-if="aperto" class="space-y-3 rounded-lg border p-4" data-testid="modulo-carta">
        <label class="block space-y-1">
          <span class="block text-xs text-slate-500 dark:text-slate-400">Nome della carta</span>
          <Input v-model="modulo.nome" class="h-9 text-sm" data-testid="modulo-nome" />
          <span v-if="errori.nome" class="block text-sm text-red-500">{{ errori.nome }}</span>
        </label>
        <div class="space-y-1">
          <span class="block text-xs text-slate-500 dark:text-slate-400">Aspetto</span>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="modulo.aspetto" type="radio" value="campi" data-testid="aspetto-campi" />
            A campi: lo studio e il condominio li scrive il programma
          </label>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="modulo.aspetto" type="radio" value="carta" data-testid="aspetto-carta" />
            Carta intestata: lo studio è stampato sulla carta, sotto le stampe verticali
          </label>
        </div>
        <div v-if="modulo.aspetto === 'carta'" class="space-y-3 rounded-md border border-dashed p-3" data-testid="modulo-carta-intestata">
          <div class="space-y-1">
            <span class="block text-xs text-slate-500 dark:text-slate-400">La carta intestata</span>
            <p v-if="cartaSalvata" class="text-sm">Caricata: {{ cartaSalvata }}. Scegli un file solo per cambiarla.</p>
            <input type="file" accept="application/pdf,image/jpeg" class="block text-sm" data-testid="modulo-file-carta" @change="scegliIlFile" />
            <p class="text-xs text-muted-foreground">
              Un PDF di una o due pagine A4 verticali (la prima pagina, e quella delle pagine seguenti), o un'immagine A4 in JPG larga almeno 1.240 pixel{{
                limiteCarta ? `, fino a ${limiteCarta}` : '' }}. Un PDF che il programma non legge si carica come immagine JPG.
            </p>
            <span v-if="errori.carta" class="block text-sm text-red-500">{{ errori.carta }}</span>
          </div>
          <div class="space-y-1">
            <span class="block text-xs text-slate-500 dark:text-slate-400">I margini della carta, in mm: dove la carta lascia lo spazio al testo</span>
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
              <label v-for="m in LATI" :key="m.lato" class="space-y-1">
                <span class="block text-xs">{{ m.etichetta }}</span>
                <Input v-model.number="modulo.margini[m.lato]" type="number" min="0" step="0.5" class="h-9 text-sm" :data-testid="`margine-${m.lato}`" />
                <span v-if="errori[`margine_${m.lato}`]" class="block text-xs text-red-500">{{ errori[`margine_${m.lato}`] }}</span>
              </label>
            </div>
            <p class="text-xs text-muted-foreground">
              I margini sinistro e destro fino a 50 mm; l'alto e il basso insieme fino a 150 mm. Il programma scrive il condominio in cima allo spazio libero, il
              piede subito sopra il margine basso; nelle lettere il destinatario resta a 112 mm dal bordo del foglio, per la busta a finestra.
            </p>
          </div>
          <p class="text-xs text-muted-foreground">
            Sulle stampe orizzontali, e quando la carta non si legge, lo studio lo scrive il programma con i dati di «Lo studio, in testa alle stampe»: senza il
            nome non c'è.
          </p>
        </div>
        <label v-if="modulo.aspetto === 'campi' && cartaSalvata" class="flex items-center gap-2 text-sm" data-testid="togli-carta-intestata">
          <input v-model="modulo.togliCarta" type="checkbox" />
          Togli la carta intestata caricata ({{ cartaSalvata }}): il file si cancella quando salvi. Senza la spunta resta, per tornare alla carta intestata
          senza caricarla di nuovo.
        </label>
        <label class="flex items-center gap-2 text-sm">
          <input v-model="modulo.mostra_logo" type="checkbox" data-testid="modulo-logo" />
          Con il logo dello studio, accanto al nome{{ modulo.aspetto === 'carta' ? ' (nelle stampe orizzontali, che hanno la testata a campi)' : '' }}
        </label>
        <div class="space-y-1">
          <label class="flex items-center gap-2 text-sm">
            <input v-model="modulo.conColore" type="checkbox" data-testid="modulo-con-colore" />
            Un colore per i titoli e le righe (senza, ogni stampa ha i colori di sempre)
          </label>
          <div v-if="modulo.conColore" class="flex items-center gap-2">
            <input v-model="modulo.colore" type="color" class="h-8 w-12 rounded border" data-testid="modulo-colore" />
            <span class="text-xs text-muted-foreground">{{ modulo.colore }} — il corpo e le cifre restano del colore di sempre</span>
          </div>
          <span v-if="errori.colore" class="block text-sm text-red-500">{{ errori.colore }}</span>
        </div>
        <label class="flex items-center gap-2 text-sm">
          <input v-model="modulo.predefinita" type="checkbox" :disabled="modulo.id !== null && predefinita?.id === modulo.id" data-testid="modulo-predefinita" />
          La carta predefinita, per i documenti senza una carta scelta
        </label>
        <span v-if="errori.predefinita" class="block text-sm text-red-500">{{ errori.predefinita }}</span>
        <div class="flex gap-2">
          <Button type="button" size="sm" data-testid="salva-carta" @click="salvaCarta">Salva la carta</Button>
          <Button type="button" variant="outline" size="sm" @click="aperto = false">Annulla</Button>
        </div>
      </div>
    </section>

    <section class="space-y-3" data-testid="carta-per-documento">
      <div>
        <h3 class="text-sm font-medium text-gray-700 dark:text-gray-200">Quale carta per quale documento</h3>
        <p class="text-sm text-muted-foreground">
          Ogni gruppo di documenti esce con la sua carta; senza una scelta, con la carta predefinita. Il modello F24 non ha carta: è dell'Agenzia delle Entrate.
        </p>
      </div>
      <p v-if="erroriScelte.carta_per_gruppo" class="text-sm text-red-500" data-testid="errore-carte-per-documento">{{ erroriScelte.carta_per_gruppo }}</p>
      <div class="grid gap-3">
        <div class="grid gap-1 sm:grid-cols-[1fr_24rem] sm:items-center" data-testid="stessa-per-tutti">
          <span class="text-sm font-medium">La stessa per tutti</span>
          <v-select v-model="stessaPerTutti" :options="scelteStessaPerTutti" label="etichetta" :reduce="riduci" :clearable="false"
            :searchable="false" class="carta-menu bg-white text-sm dark:bg-slate-950" />
        </div>
        <div v-for="g in gruppi" :key="g.valore" class="grid gap-1 sm:grid-cols-[1fr_24rem] sm:items-center" :data-testid="`gruppo-${g.valore}`">
          <span>
            <span class="block text-sm">{{ g.nome }}</span>
            <span class="block text-xs text-muted-foreground">{{ g.esempi }}</span>
          </span>
          <div>
            <v-select v-model="scelte[g.valore]" :options="sceltePerGruppo" label="etichetta" :reduce="riduci" :clearable="false"
              :searchable="false" class="carta-menu bg-white text-sm dark:bg-slate-950" />
            <span v-if="erroriScelte[`carta_per_gruppo.${g.valore}`]" class="mt-1 block text-sm text-red-500">{{ erroriScelte[`carta_per_gruppo.${g.valore}`] }}</span>
          </div>
        </div>
      </div>
      <Button type="button" size="sm" data-testid="salva-carte-per-documento" @click="salvaScelte">Salva la scelta delle carte</Button>
      <Alert v-if="messaggio && doveIlMessaggio === 'scelte'" :key="giroDelMessaggio" :message="messaggio.message" :type="messaggio.type" />
    </section>
  </div>
</template>

<style src="vue-select/dist/vue-select.css"></style>

<style scoped>
/* I colori del menu seguono il tema, come il fondo (bianco, e scuro nel tema scuro): il nome scelto e le scelte della lista aperta. */
.carta-menu {
  --vs-selected-color: var(--foreground);
  --vs-dropdown-bg: var(--background);
  --vs-dropdown-option-color: var(--foreground);
  --vs-controls-color: var(--muted-foreground);
}

/* Il nome intero della carta, nel menu e nella lista aperta: la scelta va a capo invece di tagliarsi o di uscire dalla colonna. */
.carta-menu :deep(.vs__selected),
.carta-menu :deep(.vs__dropdown-option) {
  white-space: normal;
}
</style>
