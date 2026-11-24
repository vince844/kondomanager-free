<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
import { computed } from 'vue';
import { router } from '@inertiajs/core';
import { trans } from 'laravel-vue-i18n';
import { CircleCheck } from 'lucide-vue-next';
import type { LetturaPortale } from '@/types/comunicazioni';

// La parte del condòmino della conferma di lettura (Coda 594) sta in questo componente, non nella pagina: una pagina nuova della comunicazione nel
// portale lo monta così com'è, con le due prop (`comunicazioneId` e `lettura`, la prop di `Utenti\ComunicazioneController::show()`), la rotta per
// nome e gli attributi `data-lettura` e `data-azione="presa-visione"` che le prove leggono.
defineOptions({
    name: 'PresaVisione',
});

const props = defineProps<{
    comunicazioneId: number;
    lettura: LetturaPortale;
}>();

const haPresaVisione = computed(() => Boolean(props.lettura?.presa_visione));
const puoConfermare = computed(() => Boolean(props.lettura?.puo_confermare && !haPresaVisione.value));
const haApertura = computed(() => Boolean(props.lettura?.apertura));

const mostraQualcosa = computed(() => haPresaVisione.value || puoConfermare.value || haApertura.value);

function conferma() {
    router.post(
        route('user.comunicazioni.presa-visione', { comunicazione: props.comunicazioneId }),
        {},
        { preserveScroll: true }
    );
}
</script>

<template>
    <div v-if="mostraQualcosa" class="space-y-2">
        <div
            v-if="puoConfermare"
            data-lettura="chiedi"
            class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-3 bg-slate-50 dark:bg-slate-900/50 rounded-lg border border-slate-200 dark:border-slate-800"
        >
            <p class="text-xs text-slate-700 dark:text-slate-300">
                {{ trans('comunicazioni.readings.confirm_hint') }}
            </p>
            <button
                type="button"
                data-azione="presa-visione"
                @click="conferma"
                class="min-h-10 px-4 py-2 text-xs font-medium bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900 rounded-md hover:opacity-90 transition-opacity shrink-0 cursor-pointer"
            >
                {{ trans('comunicazioni.readings.confirm_button') }}
            </button>
        </div>

        <!-- Nota E6 della decisione 313: la conferma data si vede, con la spunta verde (la scena 2 della bozza delle letture) -->
        <div
            v-if="haPresaVisione"
            data-lettura="presa-visione"
            class="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2.5 text-sm font-semibold text-emerald-800 tabular-nums dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300"
        >
            <CircleCheck class="h-[18px] w-[18px] shrink-0" aria-hidden="true" />
            <span>{{ trans('comunicazioni.readings.confirmed_on', { date: props.lettura.presa_visione?.il ?? '', time: props.lettura.presa_visione?.alle ?? '' }) }}</span>
        </div>

        <div
            v-if="haApertura"
            data-lettura="apertura"
            class="text-xs text-muted-foreground tabular-nums"
        >
            {{ trans('comunicazioni.readings.portal_opened', { date: props.lettura.apertura?.il ?? '', time: props.lettura.apertura?.alle ?? '' }) }}
        </div>
    </div>
</template>

