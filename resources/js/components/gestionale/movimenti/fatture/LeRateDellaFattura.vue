<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Coda 689, il riquadro «Le rate» della pagina della fattura (la scena 9 della bozza approvata con la decisione 623): quanto resta fuori dai piani e
 * «Metti a rate questa spesa», che apre il nuovo piano sulla scheda giusta con tutto già scelto. Il pulsante c'è solo se resta qualcosa da mettere a rate;
 * quando la spesa è tutta nei piani, al suo posto «Apri il piano».
 */
import { Link } from '@inertiajs/vue3';
import { CalendarRange } from 'lucide-vue-next';
import type { SpesaDaMettereARate } from '@/lib/gestionale/fatture/mettiARate';


defineProps<{ spesa: SpesaDaMettereARate }>();
</script>

<template>
    <!-- Come le altre teste (decisione 652): la frase a sinistra, a destra sulla stessa riga il pulsante piccolo e chiaro; al telefono sotto, a tutta riga. -->
    <div class="flex flex-col gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2.5 sm:flex-row sm:items-center sm:justify-between dark:border-slate-700 dark:bg-slate-950"
        data-testid="le-rate-della-fattura">
        <div class="min-w-0">
            <span class="block text-xs font-semibold text-slate-500 dark:text-slate-400">Le rate</span>
            <span class="block text-sm font-medium text-slate-900 dark:text-white" data-testid="frase-delle-rate">{{ spesa.frase }}</span>
        </div>
        <Link v-if="spesa.url && spesa.fuori > 0" :href="spesa.url"
            class="inline-flex h-7 w-full shrink-0 items-center justify-center gap-1.5 rounded-md border border-slate-200 bg-white px-2.5 text-xs font-medium text-slate-700 hover:bg-slate-50 sm:w-auto dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800"
            data-testid="metti-a-rate">
            <CalendarRange class="h-3.5 w-3.5" /> Metti a rate questa spesa
        </Link>
        <Link v-else-if="spesa.piano" :href="spesa.piano.url"
            class="inline-flex h-7 w-full shrink-0 items-center justify-center rounded-md border border-slate-200 bg-white px-2.5 text-xs font-medium text-slate-700 hover:bg-slate-50 sm:w-auto dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800"
            data-testid="apri-il-piano">Apri il piano «{{ spesa.piano.nome }}»</Link>
    </div>
</template>
