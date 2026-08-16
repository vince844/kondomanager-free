<script setup lang="ts">
/**
 * Il ruolo di un soggetto su un'unità: proprietario, nudo proprietario, usufruttuario, inquilino.
 *
 * Colori, etichette e ordinamento vivono in `@/lib/gestionale/ruoli-immobile`, che è la fonte
 * unica — questo componente è solo la loro forma a schermo. Prima la stessa mappa era scritta a
 * mano in tre punti e nessuno dei tre era d'accordo con gli altri: `nuda_proprietario`, che è
 * registrabile dalla beta.43, viaggiava per metà dell'interfaccia con il grigio dei ruoli
 * sconosciuti.
 */
import { computed } from 'vue';
import { coloreRuolo, etichettaRuolo } from '@/lib/gestionale/ruoli-immobile';

const props = defineProps<{
  ruolo?: string | null;
  /** `sm` per le tabelle fitte, `md` per le schede. */
  taglia?: 'sm' | 'md';
  /**
   * `chiaro` per i pannelli che **non invertono** con il tema — `ScopertoWarning` è tutto a tema
   * chiaro, e lì le varianti scure producono testo illeggibile su fondo bianco.
   */
  tema?: 'auto' | 'chiaro';
}>();

const colore = computed(() => coloreRuolo(props.ruolo, props.tema ?? 'auto'));
const etichetta = computed(() => etichettaRuolo(props.ruolo));
const dimensione = computed(() => props.taglia === 'md'
  ? 'px-2 py-1 text-[10px]'
  : 'px-1.5 py-0.5 text-[9px]');
</script>

<template>
  <span
    v-if="ruolo"
    class="inline-flex items-center rounded-md uppercase tracking-widest font-bold whitespace-nowrap"
    :class="[colore, dimensione]"
  >
    {{ etichetta }}
  </span>
</template>
