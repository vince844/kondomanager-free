<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Il lavoro ritrovato del verbale (N5, scena 4 della bozza approvata dell'editor): riaperta la pagina sullo stesso computer, le modifiche che non erano
 * partite partono da sole, e qui si dice quante, di quando, che cosa, e quando sono arrivate, con «Vedi che cosa è partito». Senza rete, «partono da sole
 * quando torna la rete». Le frasi le compone `fraseDelLavoroRitrovato()` (`lib/senzaRete/verbale.ts`); si mostrano come testo, mai come HTML.
 */
import { computed, ref } from 'vue';
import { ChevronDown, History, X } from 'lucide-vue-next';
import { ora } from '@/lib/senzaRete/sala';
import { type LavoroRitrovatoDelVerbale, nomeDellaParte } from '@/lib/senzaRete/verbale';

const props = withDefaults(
    defineProps<{
        ritrovato: (LavoroRitrovatoDelVerbale & { frasi: { titolo: string; righe: string[] } }) | null;
        punti?: { punto_id: number; numero: number }[];
        fuso?: string;
    }>(),
    { punti: () => [], fuso: 'Europe/Rome' },
);
defineEmits<{ (e: 'chiudi'): void }>();

const aperto = ref(false);
const parola = { applicato: 'arrivata', doppio: 'arrivata (era già arrivata)', in_conflitto: 'due versioni', rifiutato: 'non accettata' } as const;
const elenco = computed(() => (props.ritrovato?.esiti ?? []).filter((e) => e.tipo === 'testo').map((e) => {
    const nome = nomeDellaParte(String((e.voce.dati as { parte?: string }).parte ?? ''), props.punti);
    return { gesto: e.gesto, ora: ora(e.voce.segnato_il, props.fuso), cosa: `${nome.charAt(0).toUpperCase()}${nome.slice(1)}`, esito: parola[e.esito] };
}));
const conflitti = computed(() => (props.ritrovato?.esiti ?? []).some((e) => e.esito === 'in_conflitto'));
</script>

<template>
    <div v-if="ritrovato" class="rounded-md border px-3 py-2.5 text-sm" data-testid="verbale-ritrovato"
        :class="conflitti ? 'border-violet-200 bg-violet-50 text-violet-950 dark:border-violet-800 dark:bg-violet-950/40 dark:text-violet-100'
            : 'border-sky-200 bg-sky-50 text-sky-950 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-100'">
        <div class="flex items-start gap-2">
            <History class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            <div class="min-w-0 flex-1 space-y-1">
                <p class="font-medium" data-testid="verbale-ritrovato-titolo">{{ ritrovato.frasi.titolo }}</p>
                <p v-for="(riga, i) in ritrovato.frasi.righe" :key="i" class="text-xs" data-testid="verbale-ritrovato-riga">{{ riga }}</p>
                <button v-if="ritrovato.finito && elenco.length > 0" type="button" class="inline-flex items-center gap-1 text-xs font-medium underline-offset-2 hover:underline"
                    :aria-expanded="aperto" data-testid="verbale-vedi-che-cosa-e-partito" @click="aperto = !aperto">
                    Vedi che cosa è partito <ChevronDown class="h-3.5 w-3.5 transition-transform" :class="{ 'rotate-180': aperto }" aria-hidden="true" />
                </button>
                <ul v-if="aperto" class="space-y-0.5 text-xs" data-testid="verbale-che-cosa-e-partito">
                    <li v-for="e in elenco" :key="e.gesto">{{ e.ora }} · {{ e.cosa }}: {{ e.esito }}</li>
                </ul>
            </div>
            <button type="button" class="shrink-0 rounded p-0.5 opacity-70 hover:opacity-100" aria-label="Chiudi" data-testid="verbale-ritrovato-chiudi" @click="$emit('chiudi')">
                <X class="h-4 w-4" aria-hidden="true" />
            </button>
        </div>
    </div>
</template>
