<script setup lang="ts">
/**
 * Il campo «Pertinenza di» — il legame fra un box e il suo appartamento.
 *
 * ## Il campo c'è sempre, e questa è la decisione che conta
 *
 * L'idea di partenza era mostrarlo solo per le tipologie di categoria `pertinenza`. È sbagliata, e
 * il motivo è concreto: `Magazzino` e `Deposito` sono legittimamente pertinenza **o** unità
 * autonoma — un deposito che serve un appartamento è l'una cosa, un magazzino affittato a
 * un'impresa è l'altra — e la classificazione delle tipologie è per sua natura approssimativa.
 * Legando il campo alla categoria, **una classificazione imprecisa renderebbe la funzione
 * irraggiungibile**: l'amministratore con un magazzino che è davvero pertinenza non potrebbe
 * dichiararlo, e non capirebbe perché.
 *
 * La categoria decide quindi solo **quanto il campo è in evidenza**: aperto su box, cantina e
 * posto auto, dove è la cosa che si sta per fare; ripiegato dietro una riga di invito sulle altre,
 * dove è l'eccezione. Nessuna unità resta senza strada.
 *
 * ## Il collegamento è descrittivo, e va detto sotto il campo
 *
 * Non in guida: sotto il campo. La beta.50 ha dovuto riscrivere sette testi che promettevano il
 * subentro, e `kondomanager:verifica-titolarita` esiste per elencare chi si era fidato. Qui la
 * frase è a una riga di distanza dal gesto che la richiede.
 *
 * ## Le due forme del legame
 *
 * Il principale è un'unità **di questo condominio** (`pertinenza_di_immobile_id`), oppure sta
 * fuori e si scrive in chiaro (`pertinenza_di_esterna`). La seconda non è un ripiego: l'art. 9
 * co. 5 della L. 122/1989 consente di cedere un parcheggio solo con contestuale destinazione a
 * pertinenza di un'altra unità **nello stesso comune**, che può stare in un altro condominio.
 * Senza quel campo l'amministratore lascerebbe vuoto, che è l'informazione opposta.
 *
 * Le due sono alternative: scegliendo l'una si azzera l'altra.
 */
import { computed, ref } from 'vue';
import vSelect from 'vue-select';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Link2, Info } from 'lucide-vue-next';

type UnitaPrincipale = { id: number; etichetta: string };

const props = defineProps<{
  /** Le unità che possono fare da principale: già filtrate dal controller. */
  unitaPrincipali: UnitaPrincipale[];
  /** La categoria della tipologia scelta: decide solo l'evidenza, mai la disponibilità. */
  categoriaTipologia?: string | null;
  erroreImmobile?: string;
  erroreEsterna?: string;
}>();

const idPrincipale = defineModel<number | null>('immobileId', { default: null });
const esterna = defineModel<string | null>('esterna', { default: null });

/** Su una tipologia tipicamente pertinenziale il campo è già aperto: è ciò che si sta per fare. */
const tipicamentePertinenza = computed(() => props.categoriaTipologia === 'pertinenza');

const apertoAMano = ref(false);
const aperto = computed(() =>
  tipicamentePertinenza.value
  || apertoAMano.value
  || idPrincipale.value !== null
  || modoEsterna.value
);

/** L'opzione che apre il caso Tognoli. `id: -1` non può collidere con un id vero. */
const FUORI_CONDOMINIO = -1;
const opzioni = computed<UnitaPrincipale[]>(() => [
  ...props.unitaPrincipali,
  { id: FUORI_CONDOMINIO, etichetta: 'Unità fuori da questo condominio…' },
]);

/**
 * ⚠️ **Lo stato «principale fuori dal condominio» è esplicito, e non si deduce dal testo.**
 *
 * La prima stesura lo ricavava da `esterna.value` non vuoto. Non funziona, e il modo in cui non
 * funziona è istruttivo: scegliendo l'opzione, il testo parte come stringa vuota — che è **falsy**
 * — quindi il getter tornava subito a «Nessuna» e il campo di testo non compariva mai. Lo stato
 * esisteva solo *dopo* aver scritto qualcosa, e non c'era modo di scrivere. Trovato guardando la
 * pagina, non leggendo il codice.
 *
 * Con un flag a parte, «ho scelto fuori ma non ho ancora scritto» è uno stato rappresentabile, che
 * è esattamente ciò che serve a un modulo in compilazione.
 */
const modoEsterna = ref(!!esterna.value);

/**
 * La casella lavora su stringhe, la colonna vuole `null` quando è vuota. Il ponte sta qui e non nel
 * chiamante: una stringa vuota salvata al posto di `null` è un legame «dichiarato e vuoto», che è
 * uno stato che non esiste.
 */
const esternaTesto = computed({
  get: () => esterna.value ?? '',
  set: (v: string) => { esterna.value = v.trim() === '' ? null : v; },
});

const scelta = computed({
  get: () => (modoEsterna.value ? FUORI_CONDOMINIO : idPrincipale.value),
  set: (v: number | null) => {
    if (v === FUORI_CONDOMINIO) {
      modoEsterna.value = true;
      idPrincipale.value = null;
      return;
    }
    // Le due colonne sono alternative: scegliendo un'unità interna — o svuotando il campo — la
    // forma esterna si azzera, testo compreso. Restassero entrambe, sarebbero due principali.
    modoEsterna.value = false;
    esterna.value = null;
    idPrincipale.value = v;
  },
});
</script>

<template>
  <div class="sm:col-span-6">
    <!--
      Lo stato ripiegato non è un campo nascosto: è una riga che invita, con lo stesso peso
      tipografico delle etichette accanto. Un campo che non si vede e non si annuncia è un campo
      che non esiste.
    -->
    <button
      v-if="!aperto"
      type="button"
      @click="apertoAMano = true"
      class="inline-flex items-center gap-2 text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200 transition-colors"
    >
      <Link2 class="w-4 h-4" />
      Questa unità è pertinenza di un'altra?
    </button>

    <template v-else>
      <Label for="pertinenza_di">Pertinenza di</Label>
      <v-select
        input-id="pertinenza_di"
        class="mt-1 bg-white dark:bg-slate-950 text-sm"
        :options="opzioni"
        label="etichetta"
        :reduce="(u: UnitaPrincipale) => u.id"
        v-model="scelta"
        placeholder="Nessuna — è un'unità principale"
      />
      <InputError :message="erroreImmobile" />

      <!--
        Il campo libero compare solo quando serve, e con il segnaposto che dice **cosa** scrivere:
        «un'altra unità» non aiuta nessuno, l'indirizzo con i dati catastali sì.
      -->
      <div v-if="modoEsterna" class="mt-3">
        <Label for="pertinenza_esterna">Qual è l'unità principale</Label>
        <Input
          id="pertinenza_esterna"
          v-model="esternaTesto"
          placeholder="es. Via Roma 14, int. 5 — foglio 12, particella 340, sub 7"
          class="mt-1 bg-white dark:bg-slate-950"
        />
        <InputError :message="erroreEsterna" />
        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
          Serve per i parcheggi vincolati, che alla vendita vanno destinati a un'unità nello stesso
          comune — anche in un altro condominio (art. 9, L. 122/1989).
        </p>
      </div>

      <!--
        ⚠️ La frase che impedisce la prima delusione. Chi collega un box a un appartamento si
        aspetta che qualcosa cambi nei conti: non cambia niente, ed è giusto così — ma va detto
        qui, non in una guida che si legge una volta.
      -->
      <p class="mt-2 flex items-start gap-1.5 text-xs text-slate-500 dark:text-slate-400">
        <Info class="w-3.5 h-3.5 mt-0.5 shrink-0" />
        <span>Il collegamento è descrittivo: millesimi, riparto e rate del box restano suoi.</span>
      </p>
    </template>
  </div>
</template>
