<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Il punto «Stato di esecuzione delle delibere precedenti» (scena 11 della bozza della tappa), sotto il punto dell'ordine del giorno e sotto la proposta:
 * due gruppi, prima le delibere ancora da eseguire di sempre (con ciò che resta da fare), poi quelle eseguite dall'ultima ordinaria (con il giorno). L'elenco
 * è quello del server, fermo al giorno in cui si prepara: l'avviso e il verbale portano le stesse righe. Le righe dicono la frase pubblica del punto, mai un
 * nome; la stampa si apre dal collegamento, quando c'è.
 */
import { computed } from 'vue';
import { giornoItaliano } from '@/lib/giorno';
import type { ElencoDelloStatoDiEsecuzione, RigaDelloStatoDiEsecuzione } from '@/types/assemblee';

const props = withDefaults(defineProps<{ elenco: ElencoDelloStatoDiEsecuzione; stampa?: string | null }>(), { stampa: null });

/** «Assemblea straordinaria del 22/10/2026, punto 1 · Rifacimento della facciata: crea il piano straordinario, entro il 15/05/2027». */
function rigaAperta(r: RigaDelloStatoDiEsecuzione): string {
    const daFare = r.cose.filter((c) => c.stato === 'da_fare')
        .map((c) => `${c.testo.charAt(0).toLowerCase()}${c.testo.slice(1)}${c.entro_il ? `, entro il ${giornoItaliano(c.entro_il)}` : ''}`);

    return `${r.assemblea}${r.punto ? `, punto ${r.punto}` : ''} · ${r.testo}${daFare.length ? `: ${daFare.join('; ')}` : ''}`;
}
const rigaEseguita = (r: RigaDelloStatoDiEsecuzione) => `${r.assemblea}${r.punto ? `, punto ${r.punto}` : ''} · ${r.testo}: eseguita il ${giornoItaliano(r.eseguita_il)}`;
const gruppi = computed(() => [
    { chiave: 'aperte', titolo: `Ancora da eseguire · ${props.elenco.aperte.length}`, righe: props.elenco.aperte.map(rigaAperta) },
    { chiave: 'eseguite', titolo: `${props.elenco.ultima_ordinaria ? `Eseguite dall'ultima ordinaria, il ${giornoItaliano(props.elenco.ultima_ordinaria)}` : 'Eseguite'} · ${props.elenco.eseguite.length}`,
        righe: props.elenco.eseguite.map(rigaEseguita) },
].filter((g) => g.righe.length > 0));
</script>

<template>
    <div class="mt-2 space-y-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 dark:border-slate-800 dark:bg-slate-900/40" data-testid="stato-di-esecuzione">
        <div v-for="g in gruppi" :key="g.chiave" class="space-y-1" data-testid="gruppo-dello-stato">
            <p class="text-xs font-semibold text-slate-500 dark:text-slate-400">{{ g.titolo }}</p>
            <ul class="space-y-0.5 text-xs text-slate-700 dark:text-slate-300">
                <li v-for="(r, i) in g.righe" :key="i" class="break-words">{{ r }}</li>
            </ul>
        </div>
        <p class="text-xs text-slate-500 dark:text-slate-400" data-testid="fermo-al">L'elenco si ferma al {{ giornoItaliano(elenco.al) }}: l'avviso e il verbale portano le stesse righe.
            <a v-if="stampa" :href="stampa" target="_blank" rel="noopener" class="font-medium underline underline-offset-2" data-testid="stampa-stato">La stampa</a></p>
    </div>
</template>
