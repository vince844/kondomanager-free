<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
// Quanti commenti e quanti allegati ha una comunicazione o una segnalazione, nella riga dell'elenco (decisioni 441 e 453). I numeri li manda il
// server (`contatori`): nel portale sono quelli che la scheda mostra al condòmino, nel pannello il totale. Il browser non conta.
import { transChoice } from 'laravel-vue-i18n';
// Accanto agli allegati l'icona della voce «Allegati» del menu (decisione 463).
import { MessageSquare, Files } from 'lucide-vue-next';

export interface ContatoriData {
  commenti: number;
  allegati: number;
  in_attesa: number;
  // Decisione 463: ciò che aspetta, diviso fra commenti e allegati (`in_attesa` è la somma, per chi la usa ancora).
  commenti_in_attesa?: number;
  allegati_in_attesa?: number;
}

defineProps<{
  contatori?: ContatoriData;
  // Il pannello mostra anche ciò che aspetta l'approvazione: la riga del portale no.
  mostraInAttesa?: boolean;
}>();
</script>

<template>
  <span v-if="contatori" data-contatori-elenco class="inline-flex flex-wrap items-center gap-x-4 gap-y-1 text-xs tabular-nums">
    <span
      data-contatore="commenti"
      class="inline-flex items-center gap-1"
      :class="contatori.commenti === 0 ? 'text-gray-400 dark:text-slate-500' : 'text-gray-700 dark:text-slate-200'"
    >
      <MessageSquare class="w-3.5 h-3.5 shrink-0" aria-hidden="true" />
      {{ transChoice('commenti.conta_commenti', contatori.commenti, { count: String(contatori.commenti) }) }}
    </span>
    <span
      data-contatore="allegati"
      class="inline-flex items-center gap-1"
      :class="contatori.allegati === 0 ? 'text-gray-400 dark:text-slate-500' : 'text-gray-700 dark:text-slate-200'"
    >
      <Files class="w-3.5 h-3.5 shrink-0" aria-hidden="true" />
      {{ transChoice('commenti.conta_allegati', contatori.allegati, { count: String(contatori.allegati) }) }}
    </span>
    <!-- Ciò che aspetta dice che cosa è, in un'etichetta piccola; la scritta resta a 12 px (decisione 463) -->
    <span
      v-if="mostraInAttesa && (contatori.commenti_in_attesa ?? 0) > 0"
      data-contatore="commenti_da_approvare"
      class="inline-flex items-center rounded-md bg-amber-500/15 px-1.5 py-0 leading-5 text-amber-700 dark:text-amber-300"
    >{{ transChoice('commenti.commenti_da_approvare', contatori.commenti_in_attesa ?? 0, { count: String(contatori.commenti_in_attesa ?? 0) }) }}</span>
    <span
      v-if="mostraInAttesa && (contatori.allegati_in_attesa ?? 0) > 0"
      data-contatore="allegati_da_approvare"
      class="inline-flex items-center rounded-md bg-amber-500/15 px-1.5 py-0 leading-5 text-amber-700 dark:text-amber-300"
    >{{ transChoice('commenti.allegati_da_approvare', contatori.allegati_in_attesa ?? 0, { count: String(contatori.allegati_in_attesa ?? 0) }) }}</span>
  </span>
</template>
