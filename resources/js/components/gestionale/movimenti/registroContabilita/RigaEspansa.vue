<script setup lang="ts">
/**
 * Il pannello che compare sotto una riga del registro di contabilità quando la si espande —
 * stesso idioma del Libro Giornale (RigheEspanse.vue), ma qui non c'è una partita doppia da
 * mostrare: la riga È già un movimento di cassa completo.
 *
 * Mostra quindi solo ciò che la riga NON dice, e una cosa che la tabella non potrebbe dire:
 * il **saldo della singola cassa** dopo questo movimento. La colonna «Saldo» è complessiva su
 * tutte le casse reali — il registro di legge è un documento unico, non uno per conto (§2 di
 * docs/registri_contabili.md) — ma con due conti reali quel numero non risponde alla domanda
 * «e su questo conto quanto c'era?». Qui sì, senza appesantire ogni riga.
 */
import { Link, usePage } from '@inertiajs/vue3';
import { Landmark, Clock, FileText, ArrowUpRight } from 'lucide-vue-next';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { usePermission } from '@/composables/permissions';
import { COLORI_STATO, STATO_LABELS, badgeBase } from '../scritture/columns';
import type { Building } from '@/types/buildings';
import type { RegistroRow } from './columns';

const props = defineProps<{
  riga: RegistroRow;
}>();

const { euro } = useCurrencyFormatter();
const { generateRoute } = usePermission();
const page = usePage<{ condominio: Building }>();

/**
 * Il link alla scrittura in partita doppia vive qui e non in una colonna: come colonna era
 * l'ottava, e su uno schermo normale si raggiungeva solo scorrendo la tabella di lato.
 */
const linkScrittura = () => route(generateRoute('gestionale.scritture.show'), {
  condominio: page.props.condominio.id,
  scrittura: props.riga.scrittura_id,
});

const formatData = (iso: string) => {
  const [anno, mese, giorno] = iso.split('-');
  return `${giorno}/${mese}/${anno}`;
};

// Con il segno: positivo = annotato dopo il movimento (il caso della norma), negativo =
// movimento postdatato. Il valore assoluto qui nascondeva il verso e faceva dire «73 giorni
// dopo» anche a un'annotazione fatta prima — e in quel caso il flag dei trenta giorni non
// deve scattare, perché non c'è ritardo.
const giorniDiRitardo = () => {
  const movimento = new Date(props.riga.data).getTime();
  const annotazione = new Date(props.riga.data_annotazione).getTime();
  return Math.round((annotazione - movimento) / 86400000);
};

const testoAnnotazione = () => {
  const g = giorniDiRitardo();
  if (g === 0) return 'Annotato il giorno stesso del movimento';
  if (g < 0) return `Annotato ${-g} giorni prima della data del movimento`;
  return `${g} giorni dopo il movimento${props.riga.oltre_trenta_giorni ? ' — oltre i trenta previsti dalla norma' : ''}`;
};
</script>

<template>
  <div class="rounded-md border border-slate-200 bg-white p-4">
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">

      <div class="flex flex-col gap-1 sm:border-r sm:border-slate-100 sm:pr-4">
        <span class="text-[10px] uppercase tracking-wider text-slate-400 font-bold">
          Saldo di questa cassa
        </span>
        <span
          class="text-lg font-bold tabular-nums"
          :class="riga.saldo_cassa_progressivo < 0 ? 'text-rose-600' : 'text-slate-800'"
        >
          {{ euro(riga.saldo_cassa_progressivo) }}
        </span>
        <span class="text-[11px] text-slate-500 flex items-center gap-1.5">
          <Landmark class="w-3 h-3 text-slate-400" />{{ riga.cassa }}, dopo questo movimento
        </span>
      </div>

      <div class="flex flex-col gap-1 sm:border-r sm:border-slate-100 sm:pr-4">
        <span class="text-[10px] uppercase tracking-wider text-slate-400 font-bold">
          Annotazione
        </span>
        <span class="text-sm text-slate-700 tabular-nums flex items-center gap-1.5">
          <Clock class="w-3.5 h-3.5 text-slate-400" />{{ formatData(riga.data_annotazione) }}
        </span>
        <span class="text-[11px]" :class="riga.oltre_trenta_giorni ? 'text-amber-600 font-semibold' : 'text-slate-500'">
          {{ testoAnnotazione() }}
        </span>
      </div>

      <div class="flex flex-col gap-1">
        <span class="text-[10px] uppercase tracking-wider text-slate-400 font-bold">Stato</span>
        <!-- Lo stato «vero» del pannello segue `stornata`, non `stato`: per giroconti,
             regolazioni e F24 stornati il DB lascia l'originale `registrata`, e il pannello
             direbbe «Registrata» sotto una riga che dice «Stornata». -->
        <span :class="`${badgeBase} w-fit ${riga.stornata ? COLORI_STATO.annullata : (COLORI_STATO[riga.stato] ?? COLORI_STATO.bozza)}`">
          {{ riga.stornata ? STATO_LABELS.annullata : (STATO_LABELS[riga.stato] ?? riga.stato) }}
        </span>
        <span v-if="riga.nota" class="text-[11px] text-slate-500 flex items-start gap-1.5 mt-1">
          <FileText class="w-3 h-3 text-slate-400 shrink-0 mt-0.5" />{{ riga.nota }}
        </span>
        <Link
          :href="linkScrittura()"
          class="inline-flex items-center gap-1 text-[11px] font-semibold text-primary hover:underline mt-1 w-fit"
        >
          Vedi la scrittura in partita doppia
          <ArrowUpRight class="w-3 h-3" />
        </Link>
      </div>

    </div>
  </div>
</template>
