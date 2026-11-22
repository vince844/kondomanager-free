<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Chi scrive (scena 13 della bozza delle scene; la nota approvata «Il verbale è aperto anche da Marco Galli»): scrive una persona alla volta, gli altri
 * leggono il testo che cambia. Chi legge vede «Andrea Costa sta scrivendo il verbale.» e «Chiedi di scrivere»; chi scrive riceve la richiesta con «Lascia
 * scrivere Marco», «Continuo io» e il conto dei 60 secondi. Senza risposta, se chi scrive non ha scritto in quel minuto passa a chi chiede, se no la
 * richiesta scade e chi chiede lo vede. È un aiuto, non una serratura: senza rete si scrive lo stesso, e restano due versioni.
 */
import { computed } from 'vue';
import { fraseDiChiScrive } from '@/lib/assemblee/verbale/pagina';
import { PULSANTE } from './finestra';
import type { StatoDiChiScrive } from '@/types/verbale';

const props = defineProps<{ stato: StatoDiChiScrive | null; numeroDelPunto?: number | null; puoGestire: boolean }>();
const emit = defineEmits<{ chiedi: []; rispondi: [risposta: 'lascia' | 'continuo'] }>();

const frase = computed(() => fraseDiChiScrive(props.stato, props.numeroDelPunto ?? null));
const scriveUnAltro = computed(() => !!props.stato?.scrive && !props.stato.scrive.sono_io);
const richiestaPerMe = computed(() => props.stato?.richiesta?.per_me ? props.stato.richiesta : null);
const miaInAttesa = computed(() => props.stato?.richiesta?.mia ? props.stato.richiesta : null);
const esito = computed(() => props.stato?.esito_della_richiesta ?? null);
const secondi = (s: number) => `${Math.floor(Math.max(0, s) / 60)}:${String(Math.max(0, s) % 60).padStart(2, '0')}`;
const riquadro = 'flex flex-col gap-2 rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-950 sm:flex-row sm:items-center sm:justify-between dark:border-sky-900 dark:bg-sky-950/30 dark:text-sky-100';
</script>

<template>
    <div v-if="frase || richiestaPerMe || esito" class="space-y-2" data-testid="chi-scrive">
        <div v-if="richiestaPerMe" :class="riquadro" data-testid="richiesta-per-me">
            <span><b class="font-semibold">{{ richiestaPerMe.nome }} chiede di scrivere.</b> Se lasci, salvo prima il tuo testo: poi scrive lui, e tu leggi.
                <span class="text-xs">Aspetto la tua risposta ancora {{ secondi(richiestaPerMe.secondi) }}.</span></span>
            <span class="flex gap-2">
                <button type="button" :class="PULSANTE" data-testid="lascia-scrivere" @click="emit('rispondi', 'lascia')">Lascia scrivere {{ richiestaPerMe.nome.split(' ')[0] }}</button>
                <button type="button" :class="PULSANTE" data-testid="continuo-io" @click="emit('rispondi', 'continuo')">Continuo io</button>
            </span>
        </div>
        <div v-else-if="scriveUnAltro" :class="riquadro" data-testid="scrive-un-altro">
            <span><b class="font-semibold">{{ frase }}</b>
                <span class="block text-xs">{{ miaInAttesa ? `Aspetto la risposta ancora ${secondi(miaInAttesa.secondi)}.` : 'Lo vedi mentre cambia. Per scrivere, chiedilo.' }}</span>
                <span v-if="esito && esito.esito !== 'lasciato'" class="block text-xs" data-testid="esito-della-richiesta">{{ esito.esito === 'continua'
                    ? `${esito.di} continua a scrivere. Puoi chiederlo di nuovo fra un minuto.` : `${esito.di} non ha risposto, e sta scrivendo. La richiesta è scaduta.` }}</span>
            </span>
            <button v-if="puoGestire && !miaInAttesa" type="button" :class="PULSANTE" data-testid="chiedi-di-scrivere" @click="emit('chiedi')">
                {{ esito && esito.esito === 'scaduta' ? 'Chiedi di nuovo' : 'Chiedi di scrivere' }}</button>
        </div>
        <p v-else-if="esito?.esito === 'lasciato'" class="text-xs text-slate-600 dark:text-slate-400" data-testid="adesso-scrivi-tu">Adesso scrivi tu. {{ esito.di }} legge:
            il suo testo è salvato.</p>
        <p v-else-if="frase" class="text-xs text-slate-600 dark:text-slate-400" data-testid="aperto-anche-da">{{ frase }} Mentre scrivi tu, lo vede in sola lettura; se vuole
            scrivere, te lo chiede.</p>
    </div>
</template>
