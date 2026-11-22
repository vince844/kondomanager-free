<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La striscia della rete, sopra la barra della costituzione (scena 2 della bozza delle scene della sala): dice sempre a che punto è il lavoro. «In linea» è
 * una riga grigia che non chiede attenzione; gli altri stati si colorano e dicono il numero dei gesti. Lo stato lo sceglie `statoDellaStriscia()`
 * (`lib/senzaRete/striscia.ts`); i pulsanti li gestisce la pagina: «Rientra» (la sessione scaduta), «Scegli» (due versioni), «Stampa il foglio» (il browser
 * che non tiene il lavoro).
 */
import { computed } from 'vue';
import { Cloud, CloudOff, GitCompareArrows, KeyRound, Loader2, Printer, ShieldAlert } from 'lucide-vue-next';
import type { StatoDellaStriscia } from '@/lib/senzaRete/striscia';

const props = withDefaults(
    defineProps<{
        stato: StatoDellaStriscia;
        inCoda: number;
        invio?: { fatti: number; di: number } | null;
        dispositivo?: 'computer' | 'telefono' | 'tablet';
        /** «Il voto di Romano Ida al punto 4.» */
        conflitto?: string | null;
        /** Senza risposta perché il server non risponde (non la rete di questo dispositivo). */
        serverFermo?: boolean;
    }>(),
    { invio: null, dispositivo: 'computer', conflitto: null, serverFermo: false },
);
defineEmits<{ (e: 'rientra'): void; (e: 'scegli'): void; (e: 'stampa-foglio'): void }>();

const gesti = (n: number) => (n === 1 ? '1 gesto salvato' : `${n} gesti salvati`);
const qui = computed(() => `su questo ${props.dispositivo}`);

const testo = computed((): { titolo: string; dettaglio: string | null } => {
    switch (props.stato) {
        case 'senza_rete':
            return {
                titolo: props.inCoda === 0 ? `${props.serverFermo ? 'Il server non risponde' : 'Senza rete'}: i gesti si salvano ${qui.value}.`
                    : `${props.serverFermo ? 'Il server non risponde' : 'Senza rete'}: ${gesti(props.inCoda)} ${qui.value}.`,
                dettaglio: props.serverFermo ? 'partono da soli quando il server torna' : 'partono da soli quando torna la rete',
            };
        case 'invio':
            return { titolo: 'Sto mandando i gesti salvati…', dettaglio: props.invio ? `${props.invio.fatti} di ${props.invio.di}` : null };
        case 'sessione_scaduta':
            return { titolo: props.inCoda === 0 ? 'La sessione è scaduta: rientra.' : `La sessione è scaduta: rientra; ${props.inCoda === 1 ? 'il gesto in coda parte' : `i ${props.inCoda} gesti in coda partono`} da soli.`, dettaglio: null };
        case 'non_puoi':
            return { titolo: 'I gesti non partono: per segnare in sala serve «Gestisce le assemblee».', dettaglio: props.inCoda > 0 ? `${gesti(props.inCoda)} ${qui.value}` : null };
        case 'due_versioni':
            return { titolo: 'Due versioni: scegli.', dettaglio: props.conflitto };
        case 'non_tiene':
            return { titolo: 'Questo browser non tiene il lavoro senza rete.', dettaglio: 'aperta lo stesso' };
        default:
            return { titolo: 'In linea', dettaglio: 'tutto sul server' };
    }
});

const colore = computed(() => ({
    in_linea: 'border-slate-200 bg-slate-50 text-slate-600 dark:border-slate-800 dark:bg-slate-900/60 dark:text-slate-300',
    senza_rete: 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200',
    invio: 'border-sky-200 bg-sky-50 text-sky-900 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-200',
    sessione_scaduta: 'border-rose-200 bg-rose-50 text-rose-900 dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-200',
    non_puoi: 'border-rose-200 bg-rose-50 text-rose-900 dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-200',
    due_versioni: 'border-violet-200 bg-violet-50 text-violet-900 dark:border-violet-800 dark:bg-violet-950/40 dark:text-violet-200',
    non_tiene: 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200',
})[props.stato]);

const icona = computed(() => ({ in_linea: Cloud, senza_rete: CloudOff, invio: Loader2, sessione_scaduta: KeyRound, non_puoi: ShieldAlert, due_versioni: GitCompareArrows,
    non_tiene: ShieldAlert })[props.stato]);
const pulsante = 'shrink-0 rounded-md border border-current/30 bg-white/70 px-2.5 py-1 text-xs font-medium hover:bg-white dark:bg-slate-900/60 dark:hover:bg-slate-900';
</script>

<template>
    <div
        role="status"
        aria-live="polite"
        data-testid="striscia-della-rete"
        :data-stato="stato"
        class="flex flex-wrap items-center gap-x-2.5 gap-y-1 rounded-md border px-3 py-1.5 text-sm"
        :class="colore"
    >
        <component :is="icona" class="h-4 w-4 shrink-0" :class="{ 'animate-spin': stato === 'invio' }" aria-hidden="true" />
        <span class="min-w-0 font-medium" data-testid="striscia-titolo">{{ testo.titolo }}</span>
        <span v-if="testo.dettaglio" class="min-w-0 text-xs opacity-80" data-testid="striscia-dettaglio">{{ testo.dettaglio }}</span>
        <span class="ml-auto" />
        <button v-if="stato === 'sessione_scaduta'" type="button" :class="pulsante" data-testid="striscia-rientra" @click="$emit('rientra')">Rientra</button>
        <button v-else-if="stato === 'due_versioni'" type="button" :class="pulsante" data-testid="striscia-scegli" @click="$emit('scegli')">Scegli</button>
        <button v-else-if="stato === 'non_tiene'" type="button" :class="pulsante" class="inline-flex items-center gap-1" data-testid="striscia-foglio" @click="$emit('stampa-foglio')">
            <Printer class="h-3.5 w-3.5" aria-hidden="true" />Stampa il foglio
        </button>
    </div>
</template>
