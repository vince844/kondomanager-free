<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * L'avviso delle cifre (scena 2 della bozza dell'editor): una cifra della parte «Dati della sala» che non torna con i voti registrati, con «Rimetti 506,40»
 * e «Vai al voto del punto 4»; e, quando un voto è cambiato in sala dopo la generazione, «I dati della sala sono cambiati alle 21:20: aggiorna le parti
 * segnate», nella stessa forma. Finché una cifra non torna, «Stampa la bozza da firmare» chiede prima. Una parte dei voti cambiata a mano senza toccare le
 * cifre (un nome al posto di un altro) è un avviso leggero, che non ferma la stampa: «Nel punto 4 la parte dei voti è cambiata a mano…».
 */
import { AlertTriangle } from 'lucide-vue-next';
import { ora } from '@/lib/assemblee/verbale/pagina';
import { PULSANTE } from './finestra';
import type { CifraCheNonTorna, VerbaleDellaPagina } from '@/types/verbale';

withDefaults(defineProps<{ cifre: CifraCheNonTorna[]; salaCambiata: VerbaleDellaPagina['sala_cambiata']; puoGestire: boolean; urlDellaSala: string;
    aMano?: VerbaleDellaPagina['a_mano'] }>(), { aMano: () => [] });
const emit = defineEmits<{ rimetti: [c: CifraCheNonTorna]; aggiorna: [] }>();
const riquadro = 'space-y-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100';
</script>

<template>
    <div v-if="salaCambiata || cifre.length || aMano.length" class="space-y-2" data-testid="avviso-delle-cifre">
        <div v-if="salaCambiata" :class="riquadro" data-testid="sala-cambiata">
            <p class="flex items-start gap-2 font-medium"><AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                I dati della sala sono cambiati{{ salaCambiata.alle ? ` alle ${ora(salaCambiata.alle)}` : '' }}: aggiorna le parti segnate.</p>
            <p class="text-xs">Si rifanno solo le parti «Dati della sala»; il testo di adesso resta fra le versioni.</p>
            <button v-if="puoGestire" type="button" :class="PULSANTE" data-testid="aggiorna-le-parti" @click="emit('aggiorna')">Aggiorna le parti segnate</button>
        </div>
        <div v-for="c in cifre" :key="`${c.chiave}:${c.occorrenza}`" :class="riquadro" data-testid="cifra-che-non-torna">
            <p class="flex items-start gap-2 font-medium"><AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />Hai cambiato una cifra nei dati della sala.</p>
            <p class="text-xs">{{ c.frase }} Prima di stampare la bozza da firmare il programma te lo chiede: rimetti la cifra dei voti, o correggi il voto in sala.</p>
            <div class="flex flex-col gap-2 sm:flex-row">
                <button v-if="puoGestire" type="button" :class="PULSANTE" data-testid="rimetti" @click="emit('rimetti', c)">Rimetti {{ c.adesso }}</button>
                <a v-if="c.punto !== null" :href="urlDellaSala" :class="PULSANTE">Vai al voto del punto {{ c.punto }}</a>
            </div>
        </div>
        <div v-for="m in aMano" :key="m.chiave" class="space-y-1.5 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-800 dark:border-slate-800 dark:bg-slate-900/40 dark:text-slate-200"
            data-testid="cambiata-a-mano">
            <p class="text-xs">{{ m.frase }}</p>
            <button v-if="puoGestire" type="button" :class="PULSANTE" @click="emit('aggiorna')">Aggiorna le parti segnate</button>
        </div>
    </div>
</template>
