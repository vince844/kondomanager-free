<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * I due grafici dell'Action inbox con Chart.js, caricati solo qui (import dinamico dalla pagina): «Da quanto aspettano», a barre per età con la cifra sopra, e
 * «Per tipo», a barre orizzontali con la cifra accanto. Si registrano solo i pezzi che servono (le barre, le due scale, il riquadro al passaggio del mouse;
 * nessuna legenda). I colori si leggono dal tema quando si disegna, e il grafico si ridisegna quando il tema passa fra chiaro e scuro, come il saldo previsto
 * della tesoreria. Un clic su una barra la sceglie: la pagina filtra l'elenco. I dati e le opzioni vengono da `resources/js/lib/inbox/actionInbox.ts`.
 */
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Bar } from 'vue-chartjs';
import { BarController, BarElement, CategoryScale, Chart, LinearScale, Tooltip } from 'chart.js';
import { FASCE_DEL_GRAFICO, datiEta, datiTipi, opzioniEta, opzioniTipi, pluginCifre, type ColoriDeiGrafici, type Grafici } from '@/lib/inbox/actionInbox';

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Tooltip);

const props = defineProps<{
    grafico: 'eta' | 'tipi';
    grafici: Grafici;
    /** La barra scelta come filtro (la fascia o il tipo), o nessuna. */
    scelta: string | null;
}>();
const emit = defineEmits<{ (e: 'scegli', chiave: string): void }>();

const scuro = () => typeof document !== 'undefined' && document.documentElement.classList.contains('dark');

/** Il colore di una variabile del tema, come lo risolve il browser («rgb(…)»); senza, il colore di riserva. */
function coloreDelTema(variabile: string, riserva: string): string {
    if (typeof document === 'undefined' || typeof getComputedStyle === 'undefined') return riserva;
    const prova = document.createElement('span');
    prova.style.display = 'none';
    prova.style.color = `var(${variabile})`;
    document.body.appendChild(prova);
    const colore = getComputedStyle(prova).color;
    prova.remove();

    return /^rgba?\(/.test(colore) ? colore : riserva;
}

function colori(): ColoriDeiGrafici {
    const buio = scuro();

    return {
        // Il rosso degli scaduti, l'indaco di ciò che arriva, il grigio dei tipi: come le card e le righe.
        rosso: 'rgb(239, 68, 68)',
        rossoChiaro: buio ? 'rgb(185, 28, 28)' : 'rgb(248, 113, 113)',
        indaco: buio ? 'rgb(129, 140, 248)' : 'rgb(99, 102, 241)',
        indacoChiaro: buio ? 'rgb(99, 102, 241)' : 'rgb(129, 140, 248)',
        grigio: buio ? 'rgb(100, 116, 139)' : 'rgb(148, 163, 184)',
        testo: coloreDelTema('--muted-foreground', buio ? 'rgb(148, 163, 184)' : 'rgb(100, 116, 139)'),
        griglia: coloreDelTema('--border', buio ? 'rgb(51, 65, 85)' : 'rgb(226, 232, 240)'),
        cifre: coloreDelTema('--foreground', buio ? 'rgb(226, 232, 240)' : 'rgb(15, 23, 42)'),
    };
}

// Il tema del momento: cambia quando la pagina passa fra chiaro e scuro, e con lui la chiave del grafico, che si ridisegna con i colori nuovi.
const tema = ref(0);
let ultimo = scuro();
let osservatore: MutationObserver | null = null;
onMounted(() => {
    if (typeof MutationObserver === 'undefined') return;
    osservatore = new MutationObserver(() => {
        if (scuro() !== ultimo) {
            ultimo = scuro();
            tema.value++;
        }
    });
    osservatore.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
});
onUnmounted(() => osservatore?.disconnect());

const tavolozza = computed(() => {
    void tema.value;

    return colori();
});

/** La chiave della barra: la fascia per età, il tipo per tipo. */
function chiaveDi(indice: number): string | null {
    return props.grafico === 'eta' ? FASCE_DEL_GRAFICO[indice] ?? null : props.grafici.tipi[indice]?.tipo ?? null;
}
const scegli = (indice: number) => {
    const chiave = chiaveDi(indice);
    if (chiave) emit('scegli', chiave);
};

const dati = computed(() => (props.grafico === 'eta' ? datiEta(props.grafici, tavolozza.value, props.scelta) : datiTipi(props.grafici, tavolozza.value, props.scelta)));
const opzioni = computed(() => (props.grafico === 'eta' ? opzioniEta(props.grafici, tavolozza.value, scegli) : opzioniTipi(props.grafici, tavolozza.value, scegli)));
const plugins = computed(() => [pluginCifre(props.grafico === 'tipi', tavolozza.value.cifre)]);
</script>

<template>
    <Bar :key="tema" :data="dati as any" :options="opzioni as any" :plugins="plugins as any" aria-hidden="true" class="cursor-pointer" />
</template>
