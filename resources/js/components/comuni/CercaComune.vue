<script setup lang="ts">
/**
 * Il pulsante di ricerca accanto al campo del Comune catastale.
 *
 * ## Il campo resta libero, e questo è un aiuto
 *
 * Non è una tendina e non sostituisce il campo: chi sa cosa scrivere continua a scriverlo. La
 * ragione sta nella coda ㊹ — i Comuni si fondono e cambiano nome, e un elenco caricato una volta
 * fra due anni suggerisce codici di comuni che non esistono più. Se il Comune manca o è cambiato,
 * il campo accanto è ancora lì.
 *
 * ## Perché la provincia compare sempre nei risultati
 *
 * Sulla fonte ISTAT ci sono **cinque denominazioni ripetute** su comuni diversi — Samone, Livo,
 * Peglio, Castro, San Teodoro — con codici catastali diversi. Senza la provincia sotto il nome, chi
 * sceglie sta tirando a indovinare, e il programma gli avrebbe suggerito il codice sbagliato.
 *
 * ## Perché la data della fonte è scritta in fondo
 *
 * È la condizione a cui questo aiuto è stato accettato, non un ornamento: un elenco che non dice a
 * quando è aggiornato invecchia in silenzio e chi lo legge si fida lo stesso.
 *
 * ## I tre stati del vuoto, che la revisione ha imposto di separare
 *
 * La prima stesura ne aveva **uno solo**, e raccontava ogni fallimento come «questo Comune non
 * esiste» — sessione scaduta compresa. Ora sono tre e dicono cose diverse:
 *
 * 1. **errore** — la richiesta non è arrivata a destinazione: si dice così, e si offre la via a mano;
 * 2. **elenco vuoto** — la tabella non è stata popolata su questa installazione: è un problema
 *    dell'installazione, non del Comune cercato;
 * 3. **nessun risultato** — la ricerca ha funzionato e non ha trovato niente.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle,
} from '@/components/ui/dialog';
import { Search, LoaderCircle, MapPin, AlertCircle } from 'lucide-vue-next';

type ComuneTrovato = {
  codice_catasto: string;
  nome: string;
  provincia: string;
  sigla: string;
  altra_lingua: string | null;
};

const emit = defineEmits<{
  (e: 'scelto', comune: ComuneTrovato): void;
}>();

const aperto = ref(false);
const testo = ref('');
const risultati = ref<ComuneTrovato[]>([]);
const totale = ref(0);
const aggiornatoAl = ref<string | null>(null);
const inCorso = ref(false);
const cercatoAlmenoUnaVolta = ref(false);
/** Separato dalla lista vuota: un errore di rete non è «il Comune non esiste». */
const errore = ref<string | null>(null);

let attesa: ReturnType<typeof setTimeout> | undefined;
/** L'ultima richiesta partita: le risposte possono tornare fuori ordine e vincerebbe la più lenta. */
let ultima = 0;

const cerca = async (q: string) => {
  if (q.trim().length < 2) {
    risultati.value = [];
    totale.value = 0;
    errore.value = null;
    cercatoAlmenoUnaVolta.value = false;
    return;
  }

  const mia = ++ultima;
  inCorso.value = true;
  errore.value = null;

  try {
    const risposta = await fetch(`/comuni/cerca?q=${encodeURIComponent(q)}`, {
      headers: { Accept: 'application/json' },
    });

    if (!risposta.ok) throw new Error(String(risposta.status));

    const dati = await risposta.json();

    // Una risposta vecchia non deve sovrascrivere una più recente.
    if (mia !== ultima) return;

    risultati.value = dati.comuni ?? [];
    totale.value = dati.totale ?? 0;
    aggiornatoAl.value = dati.aggiornato_al ?? null;
    cercatoAlmenoUnaVolta.value = true;
  } catch {
    if (mia !== ultima) return;
    risultati.value = [];
    totale.value = 0;
    errore.value = trans('comuni.error');
    cercatoAlmenoUnaVolta.value = true;
  } finally {
    if (mia === ultima) inCorso.value = false;
  }
};

watch(testo, (q) => {
  if (attesa) clearTimeout(attesa);
  attesa = setTimeout(() => cerca(q), 250);
});

// La finestra è modale e copre la pagina, quindi navigare via mentre si cerca è difficile — ma un
// timer armato che scatta su un componente distrutto resta un errore silenzioso in console, e
// disarmarlo costa una riga.
onBeforeUnmount(() => {
  if (attesa) clearTimeout(attesa);
  ultima++;
});

const apri = () => {
  aperto.value = true;
  testo.value = '';
  risultati.value = [];
  totale.value = 0;
  errore.value = null;
  inCorso.value = false;
  cercatoAlmenoUnaVolta.value = false;
};

const scegli = (c: ComuneTrovato) => {
  emit('scelto', c);
  aperto.value = false;
};

/**
 * La tabella non è stata popolata: è un problema dell'installazione, non del Comune cercato.
 * Si riconosce perché il server non ha nemmeno una data della fonte da dichiarare.
 */
const elencoVuoto = computed(
  () => cercatoAlmenoUnaVolta.value && !errore.value && totale.value === 0 && aggiornatoAl.value === null,
);

const troppiRisultati = computed(() => totale.value > risultati.value.length);

const dataItaliana = (iso: string | null) => {
  if (!iso) return null;
  const [a, m, g] = iso.split('-');
  return `${g}/${m}/${a}`;
};
</script>

<template>
  <Button
    type="button"
    variant="outline"
    size="icon"
    class="shrink-0"
    :title="trans('comuni.button_title')"
    @click="apri"
  >
    <Search class="h-4 w-4" />
    <span class="sr-only">{{ trans('comuni.button_label') }}</span>
  </Button>

  <Dialog v-model:open="aperto">
    <DialogContent class="sm:max-w-lg">
      <DialogHeader>
        <DialogTitle>{{ trans('comuni.dialog_title') }}</DialogTitle>
        <DialogDescription>{{ trans('comuni.dialog_description') }}</DialogDescription>
      </DialogHeader>

      <div class="space-y-3">
        <Input
          v-model="testo"
          autofocus
          :placeholder="trans('comuni.placeholder')"
          class="bg-white dark:bg-slate-950"
        />

        <div class="min-h-[8rem] max-h-72 overflow-y-auto rounded-md border border-input">
          <div v-if="inCorso" class="flex items-center gap-2 p-4 text-sm text-muted-foreground">
            <LoaderCircle class="h-4 w-4 animate-spin" />
            {{ trans('comuni.searching') }}
          </div>

          <p v-else-if="testo.trim().length < 2" class="p-4 text-sm text-muted-foreground">
            {{ trans('comuni.min_chars') }}
          </p>

          <!-- 1. La richiesta non è arrivata: si dice, e non si dà la colpa al Comune.
               Il rosso è misurato in tutti e due i temi, non scelto a occhio: `text-destructive` dà
               3,76 su fondo chiaro (sotto la soglia 4,5) e `text-red-600` da solo dà 4,1 su fondo
               scuro. La coppia sta a 4,83 e 7,16. È la lezione della ㊳: o si misurano entrambi i
               temi, o non si tocca il colore. -->
          <p
            v-else-if="errore"
            class="flex items-start gap-2 p-4 text-sm text-red-600 dark:text-red-400"
          >
            <AlertCircle class="mt-0.5 h-4 w-4 shrink-0" />
            <span>{{ errore }}</span>
          </p>

          <!-- 2. L'elenco non è stato caricato su questa installazione. -->
          <p v-else-if="elencoVuoto" class="p-4 text-sm text-muted-foreground">
            {{ trans('comuni.empty_list') }}
          </p>

          <!-- 3. La ricerca ha funzionato e non ha trovato niente. -->
          <p
            v-else-if="cercatoAlmenoUnaVolta && risultati.length === 0"
            class="p-4 text-sm text-muted-foreground"
          >
            {{ trans('comuni.not_found') }}
          </p>

          <ul v-else class="divide-y divide-border">
            <li v-for="c in risultati" :key="c.codice_catasto">
              <button
                type="button"
                class="flex w-full items-center gap-3 px-3 py-2 text-left hover:bg-muted"
                @click="scegli(c)"
              >
                <MapPin class="h-4 w-4 shrink-0 text-muted-foreground" />
                <span class="min-w-0 flex-1">
                  <span class="block truncate text-sm font-medium">
                    {{ c.nome }}
                    <span v-if="c.altra_lingua" class="font-normal text-muted-foreground">
                      / {{ c.altra_lingua }}
                    </span>
                  </span>
                  <!-- La provincia distingue i cinque nomi ripetuti: senza, si sceglie a caso. -->
                  <span class="block truncate text-xs text-muted-foreground">
                    {{ c.provincia }} ({{ c.sigla }})
                  </span>
                </span>
                <span class="shrink-0 text-sm tabular-nums">{{ c.codice_catasto }}</span>
              </button>
            </li>
          </ul>
        </div>

        <!-- Il taglio a venti va dichiarato: «Castel» dà 193 comuni, e chi cerca «Castelvetro»
             senza vederlo conclude che non esista. -->
        <p v-if="troppiRisultati" class="text-xs text-muted-foreground">
          {{ trans('comuni.truncated', { mostrati: String(risultati.length), totale: String(totale) }) }}
        </p>

        <p v-if="aggiornatoAl" class="text-xs text-muted-foreground">
          {{ trans('comuni.source_date', { data: dataItaliana(aggiornatoAl) ?? '' }) }}
        </p>
      </div>
    </DialogContent>
  </Dialog>
</template>
