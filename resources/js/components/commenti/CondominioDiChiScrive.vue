<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
import { computed } from 'vue';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';

// Il condominio di chi scrive, accanto al nome, nella discussione unica di una comunicazione a più condomìni (decisione 458 (b)).
// Lo manda il server, solo allo studio: il primo è il condominio del commento, gli altri (chi ha unità in più condomìni fra i
// destinatari) seguono per nome. Un nome solo e «+N», tutti nel suggerimento; il nome si tronca, la riga non va a capo per questo.
const props = defineProps<{
  condomini: string[];
}>();

const altri = computed(() => props.condomini.length - 1);
</script>

<template>
  <span data-condominio-autore class="flex min-w-0 items-center gap-1 text-xs text-slate-500 dark:text-slate-400">
    <span aria-hidden="true" class="shrink-0">·</span>
    <TooltipProvider>
      <Tooltip :delay-duration="300">
        <TooltipTrigger as-child>
          <span tabindex="0" class="flex min-w-0 items-center gap-1 cursor-default rounded-sm outline-none focus-visible:ring-2 focus-visible:ring-slate-300 dark:focus-visible:ring-slate-600">
            <span data-condominio-nome class="truncate max-w-[9rem]">{{ condomini[0] }}</span>
            <span v-if="altri > 0" data-condominio-altri class="shrink-0 tabular-nums">+{{ altri }}</span>
          </span>
        </TooltipTrigger>
        <TooltipContent side="top" :side-offset="5">
          <p v-for="(nome, indice) in condomini" :key="indice" class="text-xs">{{ nome }}</p>
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  </span>
</template>
