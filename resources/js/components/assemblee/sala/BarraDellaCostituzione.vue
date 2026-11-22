<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La barra della costituzione (scena 1 della bozza approvata della sala), in alto per tutta l'assemblea: le teste presenti su quelle del condominio (quante
 * per delega), i millesimi presenti su 1.000, «Costituita in seconda convocazione» o «Non costituita», che cosa serve e che cosa manca, la convocazione della
 * seduta. Prima dell'apertura dice i totali e che cosa serve: i presenti si contano dall'apertura. Mentre è aperto un punto di una sola scala (o palazzina)
 * aggiunge la seconda riga con il conto della parte: grigia quando basta, gialla quando non basta, e decide il presidente (scena 10b).
 */
import { computed } from 'vue';
import { BADGE, CLASSI_DEL_BADGE } from '@/lib/assemblee/assemblee';
import { condomini, costituzione as contoDellaCostituzione, millesimi, millesimiPerEccesso, type Costituzione } from '@/lib/assemblee/sala/conti';
import type { ChiusuraDellaSala, ConvocazioneSeduta, PerimetroDelPunto } from '@/types/assemblee';

const props = defineProps<{
    /** Il conto sul posto; null prima dell'apertura. */
    conto: Costituzione | null;
    convocazione: ConvocazioneSeduta;
    totaleTeste: number;
    totaleValore: number;
    presentiTeste: number;
    presentiValore: number;
    perDelega: number;
    /** Il punto aperto di una sola parte del condominio, con il suo conto. */
    punto?: { numero: number; perimetro: PerimetroDelPunto } | null;
    /** A sala chiusa: com'è finita (l'ora, la convocazione, se era costituita, i presenti un attimo prima della chiusura); null altrimenti. */
    chiusura?: ChiusuraDellaSala | null;
}>();

const seconda = computed(() => props.convocazione === 'seconda');
const nomeDellaConvocazione = computed(() => (seconda.value ? 'seconda convocazione' : 'prima convocazione'));
const chiusa = computed(() => props.conto === null && !!props.chiusura);
const daAprire = computed(() => props.conto === null && !props.chiusura);
const soglia = computed(() => contoDellaCostituzione(props.convocazione, props.totaleTeste, props.totaleValore, 0, 0));
const totaleMillesimi = computed(() => millesimi(props.totaleValore).replace(/,00$/, ''));

const nomeDellaChiusura = computed(() => (props.chiusura?.convocazione === 'seconda' ? 'seconda convocazione' : 'prima convocazione'));
const dellaChiusura = computed(() => `Chiusa alle ${props.chiusura!.alle} · ${props.chiusura!.costituita ? 'costituita' : 'non costituita'} in ${nomeDellaChiusura.value}`);

// Il nome segue il numero che gli sta davanti: «1 condòmino», «0 su 1 condòmino in sala», «3 su 4 condòmini in sala» (rilievo C44).
const teste = computed(() => (chiusa.value ? `${props.chiusura!.teste} su ${condomini(props.chiusura!.totale_teste)} in sala` : daAprire.value ? condomini(props.totaleTeste)
    : `${props.presentiTeste} su ${condomini(props.totaleTeste)} in sala${props.perDelega > 0 ? ` (${props.perDelega} per delega)` : ''}`));
const valore = computed(() => (chiusa.value ? `${millesimi(props.chiusura!.valore)} millesimi su ${millesimi(props.chiusura!.totale_valore).replace(/,00$/, '')}` : daAprire.value ? `${millesimi(props.totaleValore)} millesimi` : `${millesimi(props.presentiValore)} millesimi su ${totaleMillesimi.value}`));
const stato = computed(() => {
    if (chiusa.value) return dellaChiusura.value;
    if (daAprire.value) return `Da aprire in ${nomeDellaConvocazione.value}`;

    return props.conto!.costituita ? `Costituita in ${nomeDellaConvocazione.value}` : `Non costituita in ${nomeDellaConvocazione.value}`;
});
const servono = computed(() => {
    if (chiusa.value) return `${dellaChiusura.value} con ${props.chiusura!.teste} su ${props.chiusura!.totale_teste} e ${millesimi(props.chiusura!.valore)} millesimi.`;
    const s = soglia.value;
    const base = `In ${nomeDellaConvocazione.value} servono ${condomini(s.servono_teste)} e ${millesimiPerEccesso(s.servono_valore)} millesimi`;
    if (daAprire.value) return `${base}: la sala li conta dall'apertura.`;

    return props.conto!.costituita ? `${base}.` : `${base}: ${props.conto!.mancano ?? ''}.`;
});
const colore = computed(() => (chiusa.value ? (props.chiusura!.costituita ? CLASSI_DEL_BADGE.verde : CLASSI_DEL_BADGE.ambra) : daAprire.value ? CLASSI_DEL_BADGE.neutro : props.conto!.costituita ? CLASSI_DEL_BADGE.verde : CLASSI_DEL_BADGE.ambra));

const parte = computed(() => {
    if (!props.punto) return null;
    const p = props.punto.perimetro;
    const nome = p.nome.charAt(0).toLowerCase() + p.nome.slice(1);

    return { testo: `Punto ${props.punto.numero}, ${nome}: ${p.costituzione.barra} · ${p.costituzione.servono}`, basta: p.costituzione.costituita };
});
</script>

<template>
    <section class="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm dark:border-slate-800 dark:bg-slate-950" data-testid="barra-della-costituzione"
        aria-label="La costituzione dell'assemblea">
        <div class="flex flex-wrap items-center gap-x-5 gap-y-1.5">
            <p class="text-base font-bold text-slate-900 dark:text-white" data-testid="barra-teste">{{ teste }}</p>
            <p class="text-sm font-semibold text-slate-700 dark:text-slate-200" data-testid="barra-valore">{{ valore }}</p>
            <span :class="[BADGE, colore, 'py-0.5']" data-testid="barra-stato">{{ stato }}</span>
            <span class="ml-auto inline-flex overflow-hidden rounded-md border border-slate-200 text-xs dark:border-slate-700" aria-label="La convocazione">
                <span class="px-2 py-0.5" :class="!seconda ? 'bg-slate-900 font-semibold text-white dark:bg-white dark:text-slate-900' : 'text-slate-500 dark:text-slate-400'">Prima</span>
                <span class="px-2 py-0.5" :class="seconda ? 'bg-slate-900 font-semibold text-white dark:bg-white dark:text-slate-900' : 'text-slate-500 dark:text-slate-400'">Seconda</span>
            </span>
        </div>
        <p class="mt-1 text-xs text-slate-600 dark:text-slate-400" data-testid="barra-servono">{{ servono }}</p>
        <div v-if="parte" class="mt-2 flex flex-wrap items-center gap-2 rounded-lg border px-3 py-1.5 text-xs"
            :class="parte.basta ? 'border-slate-200 bg-slate-50 text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300'
                : 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200'" data-testid="barra-scala">
            <span class="min-w-0 break-words">{{ parte.testo }}</span>
            <span :class="[BADGE, parte.basta ? CLASSI_DEL_BADGE.neutro : CLASSI_DEL_BADGE.ambra]">{{ parte.basta ? 'Basta' : 'Non bastano · decide il presidente' }}</span>
        </div>
    </section>
</template>
