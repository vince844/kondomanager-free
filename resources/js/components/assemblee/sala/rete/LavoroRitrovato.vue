<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Il lavoro ritrovato (decisione 519 (3), scena 2 della bozza delle scene della sala), in due momenti:
 *
 * - **la sala riaperta senza rete** (il portatile riacceso, la rete ancora giù): fino a che ora sono i dati del server e quanti gesti ha ritrovato; si può
 *   continuare, la barra conta sul posto;
 * - **i gesti ritrovati partiti** (la rete tornata): quanti sono arrivati, quanti hanno due versioni, quanti non sono stati accettati, con le ore e che cosa
 *   erano, e «Vedi che cosa è partito».
 *
 * Le frasi le compone `fraseDeiRitrovati()` (`lib/senzaRete/sala.ts`); qui si mostrano, come testo (mai come HTML: ci sono i nomi delle persone).
 */
import { computed, ref } from 'vue';
import { ChevronDown, History, X } from 'lucide-vue-next';
import type { Ritrovati } from '@/lib/senzaRete/coda';
import { descriviIlGesto, fraseDeiRitrovati, ora } from '@/lib/senzaRete/sala';
import type { SalaDellAssemblea } from '@/types/assemblee';

const props = withDefaults(
    defineProps<{
        ritrovati: Ritrovati | null;
        sala: SalaDellAssemblea | null;
        /** La sala riaperta senza rete: l'ora dei dati del server che ha. */
        riapertaSenzaRete?: { datiFinoAlle: string } | null;
        inCoda?: number;
        dispositivo?: 'computer' | 'telefono' | 'tablet';
        fuso?: string;
    }>(),
    { riapertaSenzaRete: null, inCoda: 0, dispositivo: 'computer', fuso: 'Europe/Rome' },
);
defineEmits<{ (e: 'chiudi'): void }>();

const aperto = ref(false);
const frasi = computed(() => (props.ritrovati ? fraseDeiRitrovati(props.ritrovati, props.sala, props.fuso) : null));
const gesti = (n: number) => (n === 1 ? '1 gesto salvato' : `i ${n} gesti salvati`);
const parola = { applicato: 'arrivato', doppio: 'arrivato (era già arrivato)', in_conflitto: 'due versioni', rifiutato: 'non accettato' } as const;
const elenco = computed(() => (props.ritrovati?.esiti ?? []).map((e) => {
    const g = descriviIlGesto(e.voce, props.sala);
    return { gesto: e.gesto, ora: ora(e.voce.segnato_il, props.fuso), cosa: `${g.cosa.charAt(0).toUpperCase()}${g.cosa.slice(1)}${g.chi ? ' di ' + g.chi : ''}${g.punto !== null ? ' al punto ' + g.punto : ''}`,
        esito: parola[e.esito], messaggio: e.esito === 'rifiutato' ? e.messaggio : null };
}));
const conflitti = computed(() => (props.ritrovati?.esiti ?? []).some((e) => e.esito === 'in_conflitto'));
</script>

<template>
    <div v-if="riapertaSenzaRete || frasi" class="rounded-md border px-3 py-2.5 text-sm"
        :class="conflitti ? 'border-violet-200 bg-violet-50 text-violet-950 dark:border-violet-800 dark:bg-violet-950/40 dark:text-violet-100'
            : 'border-sky-200 bg-sky-50 text-sky-950 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-100'"
        data-testid="lavoro-ritrovato">
        <div class="flex items-start gap-2">
            <History class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            <div class="min-w-0 flex-1 space-y-1">
                <template v-if="riapertaSenzaRete && !(ritrovati?.finito)">
                    <p class="font-medium" data-testid="riaperta-titolo">Ho riaperto la sala senza rete.</p>
                    <ul class="list-disc space-y-0.5 pl-5 text-xs">
                        <li data-testid="riaperta-dati">I dati sono quelli arrivati al server fino alle {{ ora(riapertaSenzaRete.datiFinoAlle, fuso) }}{{ inCoda > 0 ? `, più ${gesti(inCoda)} su questo ${dispositivo}` : '' }}.</li>
                        <li>Puoi continuare: la barra conta sul posto.</li>
                        <li>Quando la rete torna, i gesti partono da soli e vedi quelli fatti dagli altri dispositivi.</li>
                    </ul>
                </template>
                <template v-else-if="frasi">
                    <p class="font-medium" data-testid="ritrovati-titolo">{{ frasi.titolo }}</p>
                    <p v-for="(riga, i) in frasi.righe" :key="i" class="text-xs" data-testid="ritrovati-riga">{{ riga }}</p>
                    <button v-if="ritrovati?.finito" type="button" class="inline-flex items-center gap-1 text-xs font-medium underline-offset-2 hover:underline"
                        :aria-expanded="aperto" data-testid="vedi-che-cosa-e-partito" @click="aperto = !aperto">
                        Vedi che cosa è partito <ChevronDown class="h-3.5 w-3.5 transition-transform" :class="{ 'rotate-180': aperto }" aria-hidden="true" />
                    </button>
                    <ul v-if="aperto" class="space-y-0.5 text-xs" data-testid="che-cosa-e-partito">
                        <li v-for="e in elenco" :key="e.gesto">{{ e.ora }} · {{ e.cosa }}: {{ e.esito }}<template v-if="e.messaggio"> ({{ e.messaggio }})</template></li>
                    </ul>
                </template>
            </div>
            <button type="button" class="shrink-0 rounded p-0.5 opacity-70 hover:opacity-100" aria-label="Chiudi" data-testid="ritrovati-chiudi" @click="$emit('chiudi')">
                <X class="h-4 w-4" aria-hidden="true" />
            </button>
        </div>
    </div>
</template>
