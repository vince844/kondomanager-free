<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La scritta accanto agli strumenti dell'editor (N4, scena 3 della bozza approvata dell'editor): dice sempre a che punto è il testo. «Salvato alle 20:14 ·
 * tutto sul server» è grigia e non chiede attenzione; «Salvataggio… · pochi secondi» ha il segno piccolo del lavoro in corso; «Non salvato: senza
 * connessione. Lo tengo qui, nel browser. · 3 modifiche in attesa» è arancio; «Di nuovo in linea» è verde; la sessione scaduta ha «Rientra». Le frasi le
 * compone `scrittaDelSalvataggio()` (`lib/senzaRete/verbale.ts`).
 */
import { computed } from 'vue';
import { Cloud, CloudOff, KeyRound, Loader2, ShieldAlert, Wifi } from 'lucide-vue-next';
import type { ScrittaDelSalvataggio } from '@/lib/senzaRete/verbale';

const props = defineProps<{ scritta: ScrittaDelSalvataggio }>();
defineEmits<{ (e: 'rientra'): void }>();

const colore = computed(() => ({
    salvato: 'text-slate-500 dark:text-slate-400',
    salvataggio: 'text-sky-700 dark:text-sky-300',
    senza_rete: 'text-amber-800 dark:text-amber-300',
    di_nuovo_in_linea: 'text-emerald-700 dark:text-emerald-400',
    sessione_scaduta: 'text-rose-700 dark:text-rose-300',
    non_puoi: 'text-rose-700 dark:text-rose-300',
})[props.scritta.stato]);
const icona = computed(() => ({ salvato: Cloud, salvataggio: Loader2, senza_rete: CloudOff, di_nuovo_in_linea: Wifi, sessione_scaduta: KeyRound,
    non_puoi: ShieldAlert })[props.scritta.stato]);
</script>

<template>
    <div role="status" aria-live="polite" class="flex min-w-0 flex-wrap items-center gap-x-1.5 gap-y-0.5 text-xs" :class="colore" data-testid="stato-del-salvataggio"
        :data-stato="scritta.stato">
        <component :is="icona" class="h-3.5 w-3.5 shrink-0" :class="{ 'animate-spin': scritta.stato === 'salvataggio' }" aria-hidden="true" />
        <span class="font-medium" data-testid="salvataggio-titolo">{{ scritta.titolo }}</span>
        <span v-if="scritta.dettaglio" class="opacity-80" data-testid="salvataggio-dettaglio"> · {{ scritta.dettaglio }}</span>
        <button v-if="scritta.stato === 'sessione_scaduta'" type="button" class="rounded-md border border-current/30 px-2 py-0.5 font-medium hover:bg-rose-50 dark:hover:bg-rose-950/40"
            data-testid="salvataggio-rientra" @click="$emit('rientra')">Rientra</button>
    </div>
</template>
