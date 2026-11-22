<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Il cuore di «Come conto»: la settimana giorno per giorno dalla spedizione all'arrivo (che cosa non conta, i giorni di posta, il giorno in cui arriva), il
 * riquadro che dice se arriva in tempo (verde, con il margine) o tardi (arancio, con il giorno in cui arriverebbe), e la riga delle feste che non contano,
 * quando ce ne sono. Lo stesso contenuto nella card accanto alla data e, al telefono, nel cassetto della «i»; al telefono i giorni vanno uno sotto l'altro.
 */
import { AlertTriangle, Check } from 'lucide-vue-next';
import { classiNota } from './aiuti';
import type { EntroQuandoSpedire, GiornoDelConto } from './tipi';

defineProps<{ conto: NonNullable<EntroQuandoSpedire['conto']>; telefono?: boolean }>();

const classiDelGiorno: Record<GiornoDelConto['tipo'], string> = {
    no: 'border-slate-200 bg-white text-slate-500 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-400',
    po: 'border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-300',
    ar: 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300',
    tardi: 'border-orange-200 bg-orange-50 text-orange-800 dark:border-orange-900 dark:bg-orange-950/40 dark:text-orange-300',
};
</script>

<template>
    <div class="flex flex-col gap-2.5 text-sm text-slate-700 dark:text-slate-300" data-testid="conto-della-settimana">
        <div :class="telefono ? 'flex flex-col gap-1' : 'grid grid-cols-[repeat(auto-fill,minmax(64px,1fr))] gap-1.5'">
            <div
                v-for="g in conto.giorni"
                :key="g.giorno"
                class="rounded-lg border text-xs leading-snug"
                :class="[classiDelGiorno[g.tipo], telefono ? 'flex items-center justify-between gap-3 px-2.5 py-1.5' : 'px-1 py-1.5 text-center']"
            >
                <span class="font-semibold" :class="telefono ? '' : 'block'">{{ g.giorno }}</span>
                <span :class="telefono ? '' : 'block'">{{ g.nota }}</span>
            </div>
        </div>
        <div :class="classiNota(conto.esito.in_tempo ? 'em' : 'or')" data-testid="esito-conto">
            <Check v-if="conto.esito.in_tempo" class="mt-0.5 h-3.5 w-3.5 shrink-0" />
            <AlertTriangle v-else class="mt-0.5 h-3.5 w-3.5 shrink-0" />
            <p class="text-xs"><b class="font-semibold">{{ conto.esito.titolo }}</b>{{ conto.esito.frase }}</p>
        </div>
        <p v-if="conto.sotto" class="text-xs text-slate-500 dark:text-slate-400">{{ conto.sotto }}</p>
    </div>
</template>
