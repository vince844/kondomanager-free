<script setup lang="ts">
/**
 * Il pannello sotto una riga del mastrino — stesso idioma della Prima nota (RigaEspansa.vue) e
 * del Libro Giornale (RigheEspanse.vue). Mostra ciò che la riga non dice: le **righe sorelle**
 * della stessa scrittura — la contropartita vera, anche quando sono più di una (D21.4) —, la
 * data di registrazione, lo stato, la nota e il rimando alla scrittura in partita doppia.
 */
import { Link, usePage } from '@inertiajs/vue3';
import { Clock, FileText, ArrowUpRight, Landmark } from 'lucide-vue-next';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { usePermission } from '@/composables/permissions';
import { COLORI_STATO, STATO_LABELS, badgeBase } from '../scritture/columns';
import type { Building } from '@/types/buildings';
import type { MastrinoRow } from './columns';

const props = defineProps<{ riga: MastrinoRow }>();

const { euro } = useCurrencyFormatter();
const { generateRoute } = usePermission();
const page = usePage<{ condominio: Building }>();

const linkScrittura = () => route(generateRoute('gestionale.scritture.show'), {
  condominio: page.props.condominio.id,
  scrittura: props.riga.scrittura_id,
});

const formatData = (iso: string) => {
  const [anno, mese, giorno] = iso.split('-');
  return `${giorno}/${mese}/${anno}`;
};
</script>

<template>
  <div class="rounded-md border border-slate-200 bg-white p-4">
    <div class="grid grid-cols-1 lg:grid-cols-[1fr_260px] gap-4">

      <div class="flex flex-col gap-1.5 lg:border-r lg:border-slate-100 lg:pr-4 min-w-0">
        <span class="text-[10px] uppercase tracking-wider text-slate-400 font-bold">
          Contropartita — le altre righe della scrittura
        </span>
        <table v-if="riga.sorelle && riga.sorelle.length > 0" class="w-full text-[12px]">
          <thead>
            <tr class="text-[10px] uppercase tracking-wider text-slate-400">
              <th class="text-left font-semibold py-1 pr-3">Conto</th>
              <th class="text-right font-semibold py-1 px-2 w-28">Dare</th>
              <th class="text-right font-semibold py-1 pl-2 w-28">Avere</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="s in riga.sorelle" :key="s.id" class="border-b border-slate-100 last:border-0">
              <td class="py-1 pr-3 text-slate-700">
                <span class="text-slate-400 tabular-nums mr-2">{{ s.codice }}</span>{{ s.conto }}
                <span v-if="s.cassa" class="text-slate-400 text-[11px]"> · {{ s.cassa }}</span>
              </td>
              <td class="py-1 px-2 text-right tabular-nums whitespace-nowrap w-28">{{ s.dare !== null ? euro(s.dare) : '' }}</td>
              <td class="py-1 pl-2 text-right tabular-nums whitespace-nowrap w-28">{{ s.avere !== null ? euro(s.avere) : '' }}</td>
            </tr>
          </tbody>
        </table>
        <span v-else class="text-[11px] text-slate-400 italic">Nessuna riga sorella: la scrittura ha questa sola riga.</span>
        <span v-if="riga.cassa" class="text-[11px] text-slate-500 flex items-center gap-1.5">
          <Landmark class="w-3 h-3 text-slate-400" />Questa riga muove la cassa «{{ riga.cassa }}»
        </span>
      </div>

      <div class="flex flex-col gap-1">
        <span class="text-[10px] uppercase tracking-wider text-slate-400 font-bold">Registrazione</span>
        <span class="text-sm text-slate-700 tabular-nums flex items-center gap-1.5">
          <Clock class="w-3.5 h-3.5 text-slate-400" />{{ formatData(riga.data_registrazione) }}
        </span>
        <span :class="`${badgeBase} w-fit mt-1 ${riga.stornata ? COLORI_STATO.annullata : (COLORI_STATO[riga.stato] ?? COLORI_STATO.bozza)}`">
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
