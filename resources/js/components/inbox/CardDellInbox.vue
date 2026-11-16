<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Una delle cinque card dell'Action inbox: l'etichetta maiuscola e l'icona, il numero, la frase sotto, il piede con la freccia. È un pulsante: un clic filtra
 * l'elenco (o, per «Sei mesi», apre il cassetto); la card scelta come filtro ha il bordo e `aria-pressed`. Uno zero è grigio; con `rossa` il numero, l'etichetta e
 * il piede sono rossi (gli scaduti, i sei mesi da seguire).
 */
import { numero as cifra } from '@/lib/inbox/actionInbox';
import type { Component } from 'vue';
import { ArrowRight } from 'lucide-vue-next';

defineProps<{
    etichetta: string;
    icona: Component;
    numero: number;
    frase: string;
    piede: string;
    /** Il numero e il piede rossi, quando il numero non è zero. */
    rossa?: boolean;
    scelta?: boolean;
}>();
defineEmits<{ (e: 'scegli'): void }>();
</script>

<template>
    <button
        type="button"
        :aria-pressed="scelta ? 'true' : 'false'"
        class="group relative flex h-full flex-col overflow-hidden rounded-xl border bg-white text-left shadow-sm transition-shadow hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400 dark:bg-slate-900"
        :class="scelta
            ? (rossa && numero > 0 ? 'border-red-300 ring-2 ring-red-100 dark:border-red-800 dark:ring-red-950' : 'border-indigo-300 ring-2 ring-indigo-100 dark:border-indigo-700 dark:ring-indigo-950')
            : 'border-slate-200 dark:border-slate-800'"
        @click="$emit('scegli')"
    >
        <div class="flex-1 px-4 pt-3.5 pb-3">
            <div class="flex items-center justify-between gap-2">
                <p class="text-[11px] font-bold uppercase tracking-widest" :class="rossa && numero > 0 ? 'text-red-600 dark:text-red-400' : 'text-slate-400'">{{ etichetta }}</p>
                <component :is="icona" class="h-4 w-4 shrink-0" :class="rossa && numero > 0 ? 'text-red-400' : 'text-slate-300 dark:text-slate-600'" />
            </div>
            <p data-testid="numero" class="mt-1.5 text-3xl font-bold tabular-nums"
                :class="numero === 0 ? 'text-slate-300 dark:text-slate-600' : rossa ? 'text-red-600 dark:text-red-400' : 'text-slate-900 dark:text-white'">
                {{ cifra(numero) }}
            </p>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ frase }}</p>
        </div>
        <div class="flex items-center justify-between border-t px-4 py-2 text-xs"
            :class="rossa && numero > 0
                ? 'border-red-100 bg-red-50/60 text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300'
                : 'border-slate-100 bg-slate-50/60 text-slate-500 dark:border-slate-800 dark:bg-slate-800/40 dark:text-slate-400'">
            {{ piede }}
            <ArrowRight class="h-3 w-3 transition-transform group-hover:translate-x-0.5" />
        </div>
    </button>
</template>
