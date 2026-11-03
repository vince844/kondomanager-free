<script setup lang="ts">
/**
 * Il pulsante di ricerca accanto al campo «Codice ATECO».
 *
 * ## Il campo resta libero, e questo è un aiuto
 *
 * Non è una tendina e non sostituisce il campo: chi sa cosa scrivere continua a scriverlo. La
 * ragione è la stessa dei Comuni — una classificazione invecchia per revisioni, e un elenco caricato
 * una volta, fra due anni, non conterrebbe i codici di quella dopo. Se il codice manca o è cambiato,
 * il campo accanto è ancora lì.
 *
 * ## Perché sotto ogni risultato c'è da dove viene
 *
 * «Costruzione di opere idrauliche» e «Installazione di impianti idraulici» stanno in due divisioni
 * diverse e cercando «idraulic» escono insieme: senza la riga della gerarchia si sceglie a caso fra
 * due codici che significano lavori diversi. La stessa ragione per cui la ricerca dei Comuni mostra
 * la provincia.
 *
 * ## Perché in fondo c'è la revisione e non una data
 *
 * È la condizione a cui questo tipo di aiuto era stato accettato, tradotta in ciò che l'ATECO
 * dichiara di sé. Nel file ISTAT una data **non esiste** — verificata cella per cella su entrambi i
 * fogli — perché l'ATECO non cambia in continuazione come i Comuni: cambia per revisione, e
 * l'identità del dato è il nome della revisione.
 *
 * ## I tre stati del vuoto, tenuti separati
 *
 * Come sui Comuni, e per la lezione già pagata lì: raccontare ogni fallimento come «questo codice
 * non esiste» è falso per due casi su tre.
 *
 * 1. **errore** — la richiesta non è arrivata: si dice così, e si offre la via a mano;
 * 2. **elenco vuoto** — la classificazione non è stata caricata su questa installazione: è un
 *    problema dell'installazione, non del codice cercato;
 * 3. **nessun risultato** — la ricerca ha funzionato e non ha trovato niente.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle,
} from '@/components/ui/dialog';
import { Search, LoaderCircle, Tag, AlertCircle } from 'lucide-vue-next';

type CodiceTrovato = {
  codice: string;
  titolo: string;
  livello: string;
  gerarchia: string | null;
};

const emit = defineEmits<{
  (e: 'scelto', codice: CodiceTrovato): void;
}>();

const aperto = ref(false);
const testo = ref('');
const risultati = ref<CodiceTrovato[]>([]);
const totale = ref(0);
const versione = ref<string | null>(null);
const inCorso = ref(false);
const cercatoAlmenoUnaVolta = ref(false);
/** Separato dalla lista vuota: un errore di rete non è «il codice non esiste». */
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
    const risposta = await fetch(`/ateco/cerca?q=${encodeURIComponent(q)}`, {
      headers: { Accept: 'application/json' },
    });

    if (!risposta.ok) throw new Error(String(risposta.status));

    const dati = await risposta.json();

    // Una risposta vecchia non deve sovrascrivere una più recente.
    if (mia !== ultima) return;

    risultati.value = dati.codici ?? [];
    totale.value = dati.totale ?? 0;
    versione.value = dati.versione ?? null;
    cercatoAlmenoUnaVolta.value = true;
  } catch {
    if (mia !== ultima) return;
    risultati.value = [];
    totale.value = 0;
    errore.value = trans('ateco.error');
    cercatoAlmenoUnaVolta.value = true;
  } finally {
    if (mia === ultima) inCorso.value = false;
  }
};

watch(testo, (q) => {
  if (attesa) clearTimeout(attesa);
  attesa = setTimeout(() => cerca(q), 250);
});

// Un timer armato che scatta su un componente distrutto resta un errore silenzioso in console, e
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

const scegli = (c: CodiceTrovato) => {
  emit('scelto', c);
  aperto.value = false;
};

/**
 * La tabella non è stata popolata: è un problema dell'installazione, non del codice cercato.
 * Si riconosce perché il server non ha nemmeno una revisione da dichiarare.
 */
const elencoVuoto = computed(
  () => cercatoAlmenoUnaVolta.value && !errore.value && totale.value === 0 && versione.value === null,
);

const troppiRisultati = computed(() => totale.value > risultati.value.length);
</script>

<template>
  <Button
    type="button"
    variant="outline"
    size="icon"
    class="shrink-0"
    :title="trans('ateco.button_title')"
    @click="apri"
  >
    <Search class="h-4 w-4" />
    <span class="sr-only">{{ trans('ateco.button_label') }}</span>
  </Button>

  <Dialog v-model:open="aperto">
    <DialogContent class="sm:max-w-lg">
      <DialogHeader>
        <DialogTitle>{{ trans('ateco.dialog_title') }}</DialogTitle>
        <DialogDescription>{{ trans('ateco.dialog_description') }}</DialogDescription>
      </DialogHeader>

      <!-- ⚠️ `min-w-0` non è ornamento: `DialogContent` è una **griglia**, e un figlio di griglia ha
           `min-width: auto`. Senza, la riga della gerarchia — che ha `truncate`, cioè
           `white-space: nowrap` — invece di troncarsi allarga il contenuto oltre il riquadro:
           misurato, 975px dentro un dialogo da 512, con i codici finiti fuori dallo schermo. -->
      <div class="min-w-0 space-y-3">
        <Input
          v-model="testo"
          autofocus
          :placeholder="trans('ateco.placeholder')"
          class="bg-white dark:bg-slate-950"
        />

        <div class="min-h-[8rem] max-h-72 overflow-y-auto overflow-x-hidden rounded-md border border-input">
          <div v-if="inCorso" class="flex items-center gap-2 p-4 text-sm text-muted-foreground">
            <LoaderCircle class="h-4 w-4 animate-spin" />
            {{ trans('ateco.searching') }}
          </div>

          <p v-else-if="testo.trim().length < 2" class="p-4 text-sm text-muted-foreground">
            {{ trans('ateco.min_chars') }}
          </p>

          <!-- 1. La richiesta non è arrivata: si dice, e non si dà la colpa al codice.
               La coppia di rossi è quella misurata sui Comuni: `text-red-600` più la variante scura
               sta sopra la soglia di contrasto in entrambi i temi. -->
          <p
            v-else-if="errore"
            class="flex items-start gap-2 p-4 text-sm text-red-600 dark:text-red-400"
          >
            <AlertCircle class="mt-0.5 h-4 w-4 shrink-0" />
            <span>{{ errore }}</span>
          </p>

          <!-- 2. La classificazione non è stata caricata su questa installazione. -->
          <p v-else-if="elencoVuoto" class="p-4 text-sm text-muted-foreground">
            {{ trans('ateco.empty_list') }}
          </p>

          <!-- 3. La ricerca ha funzionato e non ha trovato niente. -->
          <p
            v-else-if="cercatoAlmenoUnaVolta && risultati.length === 0"
            class="p-4 text-sm text-muted-foreground"
          >
            {{ trans('ateco.not_found') }}
          </p>

          <ul v-else class="divide-y divide-border">
            <li v-for="c in risultati" :key="c.codice">
              <button
                type="button"
                class="flex w-full items-start gap-3 px-3 py-2 text-left hover:bg-muted"
                @click="scegli(c)"
              >
                <Tag class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
                <span class="min-w-0 flex-1">
                  <span class="block text-sm font-medium leading-snug">{{ c.titolo }}</span>
                  <!-- Da dove viene: senza, due titoli simili di divisioni diverse sono
                       indistinguibili. -->
                  <span v-if="c.gerarchia" class="block truncate text-xs text-muted-foreground">
                    {{ c.gerarchia }}
                  </span>
                </span>
                <span class="shrink-0 text-right">
                  <span class="block text-sm tabular-nums">{{ c.codice }}</span>
                  <!-- Il livello si stampa, e non è ornamento: sulla visura camerale c'è il codice a
                       **sei cifre**, cioè la sottocategoria. Dirlo aiuta a scegliere quella giusta
                       quando categoria e sottocategoria compaiono entrambe. -->
                  <span class="block text-[11px] text-muted-foreground">{{ c.livello }}</span>
                </span>
              </button>
            </li>
          </ul>
        </div>

        <!-- Il taglio a venti va dichiarato: «impianti» dà molti più codici, e chi non lo sa
             conclude che il suo non ci sia. -->
        <p v-if="troppiRisultati" class="text-xs text-muted-foreground">
          {{ trans('ateco.truncated', { mostrati: String(risultati.length), totale: String(totale) }) }}
        </p>

        <p v-if="versione" class="text-xs text-muted-foreground">
          {{ trans('ateco.source_version', { versione: versione }) }}
        </p>
      </div>
    </DialogContent>
  </Dialog>
</template>
