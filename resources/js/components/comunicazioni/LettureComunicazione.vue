<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { trans } from 'laravel-vue-i18n';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import type { LetturePannello, RigaLetturaPannello } from '@/types/comunicazioni';

defineOptions({
    name: 'LettureComunicazione',
});

const props = defineProps<{
    letture: LetturePannello;
}>();

const soloChiManca = ref(false);
const pagina = ref(1);
const RIGHE_PER_PAGINA = 25;

const righeFiltrate = computed<RigaLetturaPannello[]>(() => {
    const elenco = props.letture?.righe ?? [];
    if (!soloChiManca.value) {
        return elenco;
    }
    return elenco.filter((r) => r.manca);
});

const totalePagine = computed(() => {
    return Math.max(1, Math.ceil(righeFiltrate.value.length / RIGHE_PER_PAGINA));
});

const righePaginata = computed<RigaLetturaPannello[]>(() => {
    const inizio = (pagina.value - 1) * RIGHE_PER_PAGINA;
    return righeFiltrate.value.slice(inizio, inizio + RIGHE_PER_PAGINA);
});

function toggleFiltro() {
    soloChiManca.value = !soloChiManca.value;
    pagina.value = 1;
}

watch(() => props.letture, () => {
    pagina.value = 1;
});

function etichettaStato(riga: RigaLetturaPannello): string {
    if (riga.stato === 'presa_visione') {
        return trans('comunicazioni.readings.status_acknowledged', {
            date: riga.presa_visione?.il ?? '',
            time: riga.presa_visione?.alle ?? '',
        });
    }
    if (riga.stato === 'aperta') {
        return trans('comunicazioni.readings.status_opened', {
            date: riga.aperta?.il ?? '',
            time: riga.aperta?.alle ?? '',
        });
    }
    if (riga.stato === 'senza_accesso') {
        return trans('comunicazioni.readings.status_no_access');
    }
    if (riga.stato === 'non_confermata') {
        return trans('comunicazioni.readings.status_not_acknowledged');
    }
    if (riga.stato === 'non_aperta') {
        return trans('comunicazioni.readings.status_not_opened');
    }
    return riga.stato;
}
</script>

<template>
    <Card data-riquadro="letture" class="shadow-none">
        <CardHeader class="pb-3">
            <div class="space-y-1">
                <CardTitle class="text-base font-semibold text-slate-900 dark:text-slate-100">
                    {{ trans('comunicazioni.readings.title') }}
                </CardTitle>
                <p class="text-xs text-muted-foreground">
                    {{ trans('comunicazioni.readings.no_legal_value') }}
                </p>
            </div>
        </CardHeader>
        <CardContent class="pt-2 space-y-4">
            <div v-if="!props.letture?.attive">
                <p data-letture="come-attivarle" class="text-sm text-muted-foreground">
                    {{ trans('comunicazioni.readings.how_to_enable') }}
                </p>
            </div>

            <template v-else>
                <div data-letture="riassunto" class="flex flex-wrap items-center gap-4 text-sm text-slate-700 dark:text-slate-300">
                    <div
                        v-if="props.letture.registra_aperture || (props.letture.riassunto && props.letture.riassunto.aperte > 0)"
                        data-letture="riassunto-aperte"
                        class="tabular-nums font-medium"
                    >
                        {{ trans('comunicazioni.readings.summary_opened', { count: props.letture.riassunto?.aperte ?? 0, total: props.letture.riassunto?.destinatari ?? 0 }) }}
                    </div>

                    <div
                        v-if="props.letture.chiedi_presa_visione || (props.letture.riassunto && props.letture.riassunto.prese_visione > 0)"
                        data-letture="riassunto-prese-visione"
                        class="tabular-nums font-medium"
                    >
                        {{ trans('comunicazioni.readings.summary_acknowledged', { count: props.letture.riassunto?.prese_visione ?? 0, total: props.letture.riassunto?.destinatari ?? 0 }) }}
                    </div>

                    <div
                        v-if="props.letture.riassunto && props.letture.riassunto.senza_accesso > 0"
                        data-letture="riassunto-senza-accesso"
                        class="tabular-nums font-medium"
                    >
                        {{ trans('comunicazioni.readings.summary_no_access', { count: props.letture.riassunto.senza_accesso }) }}
                    </div>
                </div>

                <p
                    v-if="!props.letture.registra_aperture && props.letture.riassunto && props.letture.riassunto.aperte > 0"
                    data-letture="aperture-spente"
                    class="text-xs text-amber-700 dark:text-amber-400"
                >
                    {{ trans('comunicazioni.readings.tracking_off_note') }}
                </p>

                <p
                    v-if="props.letture.riassunto && props.letture.riassunto.destinatari === 0"
                    data-letture="nessun-destinatario"
                    class="text-sm text-muted-foreground"
                >
                    {{ trans('comunicazioni.readings.no_recipients') }}
                </p>

                <div v-else class="space-y-3">
                    <div class="flex items-center justify-between gap-4">
                        <button
                            type="button"
                            data-filtro="chi-manca"
                            :aria-pressed="soloChiManca ? 'true' : 'false'"
                            @click="toggleFiltro"
                            class="min-h-10 px-3 py-2 text-xs font-medium rounded-md border transition-colors cursor-pointer"
                            :class="soloChiManca
                                ? 'bg-slate-900 text-white border-slate-900 dark:bg-slate-100 dark:text-slate-900 dark:border-slate-100'
                                : 'bg-white dark:bg-slate-950 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-900'"
                        >
                            {{ trans('comunicazioni.readings.filter_missing') }}
                        </button>
                    </div>

                    <p
                        v-if="soloChiManca && righeFiltrate.length === 0"
                        data-letture="nessuno-manca"
                        class="text-sm text-muted-foreground py-2"
                    >
                        {{ trans('comunicazioni.readings.nobody_missing') }}
                    </p>

                    <div
                        v-else-if="righeFiltrate.length > 0"
                        class="divide-y divide-slate-100 dark:divide-slate-800 border border-slate-200 dark:border-slate-800 rounded-md"
                    >
                        <div
                            v-for="riga in righePaginata"
                            :key="riga.anagrafica_id"
                            data-lettura-riga
                            :data-stato="riga.stato"
                            class="flex items-center justify-between px-3.5 py-2.5 text-xs"
                        >
                            <span class="font-medium text-slate-900 dark:text-slate-100">
                                {{ riga.nome }}
                            </span>
                            <span class="text-muted-foreground tabular-nums">
                                {{ etichettaStato(riga) }}
                            </span>
                        </div>
                    </div>

                    <div
                        v-if="righeFiltrate.length > 25"
                        data-letture="pagine"
                        class="flex items-center justify-between gap-2 pt-2"
                    >
                        <button
                            type="button"
                            data-letture="pagina-precedente"
                            :disabled="pagina === 1"
                            @click="pagina--"
                            class="min-h-10 px-3 py-2 text-xs rounded-md border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 text-slate-700 dark:text-slate-300 disabled:opacity-50 disabled:cursor-not-allowed hover:bg-slate-50 dark:hover:bg-slate-900 transition-colors cursor-pointer"
                        >
                            {{ trans('comunicazioni.readings.previous') }}
                        </button>
                        <span data-letture="pagina" class="text-xs text-muted-foreground tabular-nums">
                            {{ trans('comunicazioni.readings.page', { page: pagina, pages: totalePagine }) }}
                        </span>
                        <button
                            type="button"
                            data-letture="pagina-successiva"
                            :disabled="pagina === totalePagine"
                            @click="pagina++"
                            class="min-h-10 px-3 py-2 text-xs rounded-md border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 text-slate-700 dark:text-slate-300 disabled:opacity-50 disabled:cursor-not-allowed hover:bg-slate-50 dark:hover:bg-slate-900 transition-colors cursor-pointer"
                        >
                            {{ trans('comunicazioni.readings.next') }}
                        </button>
                    </div>
                </div>
            </template>
        </CardContent>
    </Card>
</template>

