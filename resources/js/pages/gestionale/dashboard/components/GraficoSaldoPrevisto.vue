<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Il grafico del saldo previsto, con Chart.js: caricato solo nella dashboard del condominio, con un import dinamico dalla card «Saldo
 * previsto». Si registrano solo i pezzi che servono (la linea, i punti, le due scale, il riempimento sotto lo zero, il riquadro al passaggio del mouse; nessuna
 * legenda). Una linea sola, quella dello scenario scelto nella card. I colori si leggono dal tema quando si disegna, e il grafico si ridisegna
 * quando il tema passa fra chiaro e scuro. I dati e le opzioni vengono da `resources/js/lib/gestionale/tesoreria/saldoPrevisto.ts`, provati a parte.
 */
import { computed } from 'vue';
import { Line } from 'vue-chartjs';
import { CategoryScale, Chart, Filler, LinearScale, LineController, LineElement, PointElement, Tooltip } from 'chart.js';
import { SCENARIO_DI_SERIE, datiDelGrafico, opzioniDelGrafico, type ColoriDelGrafico, type SaldoPrevisto, type Scenario } from '@/lib/gestionale/tesoreria/saldoPrevisto';
import { coloreDelTema, conAlfa, scuro, useTemaDelGrafico } from '@/lib/gestionale/tesoreria/temaDelGrafico';

Chart.register(LineController, LineElement, PointElement, LinearScale, CategoryScale, Filler, Tooltip);

const props = withDefaults(defineProps<{ saldoPrevisto: SaldoPrevisto; scenario?: Scenario }>(), { scenario: SCENARIO_DI_SERIE });

function colori(): ColoriDelGrafico {
    const buio = scuro();
    const rosso = coloreDelTema('--destructive', buio ? 'rgb(239, 68, 68)' : 'rgb(220, 38, 38)');

    return {
        // La linea è indaco, neutra: il verde direbbe «va bene» anche sotto lo zero; il rosso è della zona e del primo giorno sotto zero.
        linea: buio ? 'rgb(129, 140, 248)' : 'rgb(79, 70, 229)',
        rosso,
        zona: conAlfa(rosso, buio ? 0.25 : 0.15),
        testo: coloreDelTema('--muted-foreground', buio ? 'rgb(148, 163, 184)' : 'rgb(100, 116, 139)'),
        griglia: coloreDelTema('--border', buio ? 'rgb(51, 65, 85)' : 'rgb(226, 232, 240)'),
        zero: coloreDelTema('--foreground', buio ? 'rgb(241, 245, 249)' : 'rgb(15, 23, 42)'),
        sfondo: coloreDelTema('--card', buio ? 'rgb(15, 23, 42)' : 'rgb(255, 255, 255)'),
    };
}

// Il tema del momento: cambia quando la pagina passa fra chiaro e scuro, e con lui la chiave del grafico, che si ridisegna con i colori nuovi.
const tema = useTemaDelGrafico();
const tavolozza = computed(() => {
    void tema.value;

    return colori();
});
const dati = computed(() => datiDelGrafico(props.saldoPrevisto, tavolozza.value, props.scenario));
const opzioni = computed(() => opzioniDelGrafico(props.saldoPrevisto, tavolozza.value, props.scenario));
</script>

<template>
    <Line :key="tema" :data="dati as any" :options="opzioni as any" aria-hidden="true" />
</template>
