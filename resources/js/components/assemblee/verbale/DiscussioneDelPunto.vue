<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «La discussione» di un punto (scena 1 della bozza dell'editor e della bozza delle scene del verbale, decisione 595 (4)): sotto il voto del punto nella
 * sala, e nella pagina «Il verbale», che un segretario può tenere su un altro computer. Il segretario scrive ciò che si è detto; voti, nomi e millesimi li
 * mette il programma. Il testo esce con `scrivi` a ogni cambio, e chi usa il pezzo decide quando mandarlo (pochi secondi dopo la pausa, almeno ogni 30
 * secondi). Un testo che arriva dal server mentre qui si scrive non sovrascrive quello di questo computer.
 */
import { ref, watch } from 'vue';
import EditorDelVerbale from './EditorDelVerbale.vue';
import TestoDelVerbale from './TestoDelVerbale.vue';
import { canonico, type NodoDelVerbale } from '@/lib/assemblee/verbale/struttura';

const props = withDefaults(defineProps<{
    numero: number;
    documento: NodoDelVerbale | null;
    soloLettura?: boolean;
    scritta?: { testo: string; dettaglio: string } | null;
    /** Le tre righe d'aiuto sotto l'editor (nella sala); nella pagina del verbale bastano una volta in testa. */
    conAiuto?: boolean;
}>(), { soloLettura: false, scritta: null, conAiuto: true });
const emit = defineEmits<{ scrivi: [documento: NodoDelVerbale] }>();

/** Il testo di questo computer: quello del server finché qui non si scrive; dopo, quello del server entra solo dieci secondi dopo l'ultimo tocco. */
const testo = ref<NodoDelVerbale | null>(props.documento);
let ultimoTocco = 0;
watch(() => props.documento, (arrivato) => {
    if (!arrivato || Date.now() - ultimoTocco < 10000) return;
    if (testo.value && canonico(testo.value) === canonico(arrivato)) return;
    testo.value = arrivato;
});
function cambiato(d: NodoDelVerbale) {
    ultimoTocco = Date.now();
    testo.value = d;
    emit('scrivi', d);
}
</script>

<template>
    <div class="space-y-1.5" data-testid="la-discussione" :data-punto="numero">
        <p class="text-xs font-semibold text-slate-500 dark:text-slate-400">La discussione</p>
        <TestoDelVerbale v-if="soloLettura" :documento="testo" vuoto="La discussione non è ancora scritta."
            class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 dark:border-slate-800 dark:bg-slate-900/40" />
        <EditorDelVerbale v-else :model-value="testo" :scritta="scritta" :etichetta="`La discussione del punto ${numero}`" @update:model-value="cambiato" />
        <ul v-if="conAiuto && !soloLettura" class="space-y-0.5 text-xs text-slate-500 dark:text-slate-400" data-testid="aiuto-della-discussione">
            <li>Voti, nomi e millesimi li mette il programma: qui solo ciò che si è detto.</li>
            <li>Si salva pochi secondi dopo che smetti di scrivere, e almeno ogni 30 secondi; senza rete resta sul computer.</li>
            <li>La stessa discussione si legge, e si scrive, nella pagina «Il verbale».</li>
        </ul>
    </div>
</template>
