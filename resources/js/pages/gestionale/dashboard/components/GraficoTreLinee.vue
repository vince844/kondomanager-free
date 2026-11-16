<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Il grafico del cassetto «Tesoreria: che cosa puoi fare», con Chart.js, caricato con un import dinamico solo quando il cassetto si apre: tre linee a gradini
 * (l'eccezione alla serie sola): la simulazione piena in indaco con il rosso sotto zero, «Se pagano tutti» tratteggiata verde, «Senza le
 * rate» tratteggiata grigia; i fatti scritti sul grafico (le fatture e le rate del giorno, il recupero). La legenda è nella pagina. I colori dal tema, e il
 * grafico si ridisegna al cambio fra chiaro e scuro. I calcoli in `resources/js/lib/gestionale/tesoreria/azioniConsigliate.ts`.
 */
import { computed } from 'vue';
import { Line } from 'vue-chartjs';
import { CategoryScale, Chart, Filler, LinearScale, LineController, LineElement, PointElement, Tooltip } from 'chart.js';
import {
    datiDelGraficoGrande, fattiDelGrafico, opzioniDelGraficoGrande, pluginDeiFatti, type ColoriDelGraficoGrande,
} from '@/lib/gestionale/tesoreria/azioniConsigliate';
import type { SaldoPrevisto } from '@/lib/gestionale/tesoreria/saldoPrevisto';
import { coloreDelTema, conAlfa, scuro, useTemaDelGrafico } from '@/lib/gestionale/tesoreria/temaDelGrafico';

Chart.register(LineController, LineElement, PointElement, LinearScale, CategoryScale, Filler, Tooltip);

const props = defineProps<{ saldoPrevisto: SaldoPrevisto; recuperato: number }>();

function colori(): ColoriDelGraficoGrande {
    const buio = scuro();
    const rosso = coloreDelTema('--destructive', buio ? 'rgb(239, 68, 68)' : 'rgb(220, 38, 38)');
    const linea = buio ? 'rgb(129, 140, 248)' : 'rgb(79, 70, 229)';

    return {
        linea,
        zona: conAlfa(rosso, buio ? 0.25 : 0.16),
        zonaSopra: conAlfa(linea, buio ? 0.12 : 0.06),
        verde: buio ? 'rgb(52, 211, 153)' : 'rgb(16, 185, 129)',
        grigio: buio ? 'rgb(100, 116, 139)' : 'rgb(148, 163, 184)',
        testo: coloreDelTema('--muted-foreground', buio ? 'rgb(148, 163, 184)' : 'rgb(100, 116, 139)'),
        griglia: coloreDelTema('--border', buio ? 'rgb(51, 65, 85)' : 'rgb(226, 232, 240)'),
        zero: coloreDelTema('--foreground', buio ? 'rgb(241, 245, 249)' : 'rgb(15, 23, 42)'),
        sfondo: buio ? 'rgb(30, 41, 59)' : 'rgb(255, 255, 255)',
        bordo: buio ? 'rgb(51, 65, 85)' : 'rgb(226, 232, 240)',
        scritta: buio ? 'rgb(226, 232, 240)' : 'rgb(51, 65, 85)',
    };
}

const tema = useTemaDelGrafico();
const tavolozza = computed(() => {
    void tema.value;

    return colori();
});
const dati = computed(() => datiDelGraficoGrande(props.saldoPrevisto, props.recuperato, tavolozza.value));
const opzioni = computed(() => opzioniDelGraficoGrande(props.saldoPrevisto, tavolozza.value));
// I fatti seguono il cursore: il pezzo li legge quando disegna.
const fatti = computed(() => fattiDelGrafico(props.saldoPrevisto, props.recuperato));
const plugins = [pluginDeiFatti(() => fatti.value, () => tavolozza.value)];
</script>

<template>
    <Line :key="tema" :data="dati as any" :options="opzioni as any" :plugins="plugins as any" aria-hidden="true" />
</template>
