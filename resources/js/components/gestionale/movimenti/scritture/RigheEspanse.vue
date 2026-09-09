<script setup lang="ts">
/**
 * Il pannello che compare sotto una riga del Libro Giornale quando la si espande.
 *
 * Stessa tabella dare/avere di Show.vue:485-566 (conto, dettaglio, dare, avere) — il malinteso da
 * cui è nato `docs/registri_contabili.md` era proprio che questa vista esistesse solo nel
 * dettaglio: qui compare senza cambiare pagina. Niente footer né badge di quadratura: quelli
 * restano un buon motivo per aprire il dettaglio vero, non vanno duplicati qui.
 */
import { computed } from 'vue';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Landmark, FileText } from 'lucide-vue-next';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import type { RigaScritturaRow } from './columns';

const props = defineProps<{
  righe: RigaScritturaRow[];
}>();

const { euro } = useCurrencyFormatter();

// Stessa regola di presentazione di Show.vue: prima le righe in DARE, poi in AVERE.
const righeOrdinate = computed(() => {
  return [...props.righe].sort((a, b) => {
    if (a.tipo_riga === 'dare' && b.tipo_riga === 'avere') return -1;
    if (a.tipo_riga === 'avere' && b.tipo_riga === 'dare') return 1;
    return 0;
  });
});
</script>

<template>
  <div class="rounded-md border border-slate-200 bg-white overflow-hidden">
    <Table>
      <TableHeader>
        <TableRow class="hover:bg-transparent bg-slate-50/50">
          <TableHead class="w-1/3 font-bold text-xs">Conto contabile</TableHead>
          <TableHead class="font-bold text-xs">Dettaglio</TableHead>
          <TableHead class="text-right font-bold text-xs text-emerald-700 w-32">Dare</TableHead>
          <TableHead class="text-right font-bold text-xs text-blue-700 w-32">Avere</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        <TableRow v-for="riga in righeOrdinate" :key="riga.id" class="hover:bg-slate-50/50 transition-colors">
          <TableCell>
            <div class="flex flex-col gap-0.5">
              <span class="text-xs font-bold text-slate-800">{{ riga.conto?.nome || 'Conto non specificato' }}</span>
              <span v-if="riga.conto?.codice" class="text-[10px] text-slate-400 tabular-nums">{{ riga.conto.codice }}</span>
            </div>
          </TableCell>
          <TableCell>
            <div class="flex flex-col gap-0.5">
              <span v-if="riga.cassa" class="text-xs text-slate-500 flex items-center gap-1">
                <Landmark class="w-3 h-3 text-slate-400" />{{ riga.cassa.nome }}
              </span>
              <span v-if="riga.voce_spesa" class="text-xs text-slate-500 flex items-center gap-1">
                <FileText class="w-3 h-3 text-slate-400" />{{ riga.voce_spesa.nome }}
              </span>
              <span v-if="riga.note" class="text-xs text-slate-400 italic">{{ riga.note }}</span>
              <span v-if="!riga.cassa && !riga.voce_spesa && !riga.note" class="text-xs text-slate-400 italic">
                Nessun dettaglio aggiuntivo
              </span>
            </div>
          </TableCell>
          <TableCell class="text-right">
            <span v-if="riga.tipo_riga === 'dare'" class="font-black text-xs text-emerald-700">{{ euro(riga.importo) }}</span>
          </TableCell>
          <TableCell class="text-right">
            <span v-if="riga.tipo_riga === 'avere'" class="font-black text-xs text-blue-700">{{ euro(riga.importo) }}</span>
          </TableCell>
        </TableRow>
      </TableBody>
    </Table>
  </div>
</template>
