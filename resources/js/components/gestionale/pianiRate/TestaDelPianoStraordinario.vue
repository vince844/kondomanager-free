<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La testa del piano straordinario: due righe che il piano ricorda.
 *
 * - **Il fondo speciale** (decisione 622 (2), la scena 3 della bozza approvata): «Fondo speciale: € 9.000,00 chiesti su € 14.500,00 deliberati.», con le
 *   parole della legge (art. 1135 n. 4 c.c.). La riga arancio non blocca, non ha «Ignora», e sparisce da sola quando i piani arrivano ai lavori.
 * - **I lavori urgenti** (Coda 688, la scena 13): «Urgenza», il giorno dei lavori («Lavori ordinati il 02/03/2027 · decide chi paga, non quanto»), il motivo
 *   come l'ha scritto l'amministratore (testo, mai HTML), e la prima assemblea a cui si riferisce.
 */
import { AlertTriangle, Zap } from 'lucide-vue-next';
import { BADGE, CLASSI_DEL_BADGE } from '@/lib/assemblee/assemblee';
import type { FondoSpecialeDelPiano, UrgenzaDelPiano } from '@/lib/gestionale/pianiRate/testaDelPiano';

defineProps<{ fondo?: FondoSpecialeDelPiano | null; urgenza?: UrgenzaDelPiano | null }>();
</script>

<template>
    <section v-if="urgenza" class="space-y-1.5 rounded-xl border border-slate-200 bg-white px-4 py-3.5 text-sm dark:border-slate-800 dark:bg-slate-950" data-testid="urgenza-del-piano">
        <p class="flex flex-wrap items-center gap-2">
            <span :class="[BADGE, CLASSI_DEL_BADGE.ambra]"><Zap class="mr-1 h-3 w-3" /> Urgenza</span>
            <span :class="[BADGE, CLASSI_DEL_BADGE.verde]">Approvato</span>
            <span class="text-slate-700 dark:text-slate-200">{{ urgenza.frase }}</span>
        </p>
        <p v-if="urgenza.giorno" class="font-medium text-slate-900 dark:text-white" data-testid="giorno-dei-lavori">
            Lavori ordinati il {{ urgenza.giorno }} · il giorno decide chi paga, non quanto
        </p>
        <p v-if="urgenza.motivo" class="break-words text-slate-700 dark:text-slate-300" data-testid="motivo-dell-urgenza">Motivo: «{{ urgenza.motivo }}»</p>
        <p class="text-xs text-slate-600 dark:text-slate-400">{{ urgenza.assemblea }}</p>
    </section>

    <section v-if="fondo" class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
        role="status" data-testid="fondo-speciale">
        <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
        <div class="min-w-0 space-y-1">
            <p class="text-sm font-medium">{{ fondo.frase }}</p>
            <p class="text-xs">{{ fondo.legge }}</p>
        </div>
    </section>
</template>
