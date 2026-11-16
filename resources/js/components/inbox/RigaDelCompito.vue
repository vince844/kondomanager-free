<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Una riga dell'elenco dell'Action inbox. Da `xl` le colonne fisse «Compito», «Condominio», «Scadenza», «Prossima azione» e il menu «⋯» (la griglia
 * `COLONNE_DELLA_RIGA`, la stessa dell'intestazione); sotto, una riga sotto l'altra: il titolo, la data, la prossima azione con il menu accanto.
 *
 * Il titolo è quello salvato senza il nome del condominio ripetuto, e sotto la riga con la rata, la cifra o la fattura (dal server); la priorità di una
 * segnalazione in un cartellino, in minuscolo. Il condominio su due righe al più. La data è rossa per gli scaduti, con «da N giorni»; per una segnalazione
 * «aperta il …», con i giorni in grigio: non ha una scadenza. La prossima azione è quella del tipo (dal server, `TipiDeiCompiti`): un collegamento alla sua pagina, o «Segna come fatto», che apre
 * la risposta «Fatto» del menu. Il menu «⋯» è quello di sempre (`MenuDelCompito`); senza «Modifica scadenza in agenda» non c'è, e «Segna come fatto» è spento.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import {
    ArrowDownToLine, ArrowRight, ArrowUpFromLine, Banknote, Building2, CalendarDays, Check, ChartPie, Landmark, MessageSquare, Paperclip, Receipt, TrendingDown,
    Users, Wrench,
} from 'lucide-vue-next';
import MenuDelCompito from '@/components/inbox/MenuDelCompito.vue';
import { aspettoDelTipo, eDelSito, testoDellaData, testoQuando, type RigaCompito } from '@/lib/inbox/actionInbox';
import type { VoceDelCompito } from '@/lib/inbox/risposteDelCompito';

const props = defineProps<{ riga: RigaCompito; puoChiudere: boolean }>();
const emit = defineEmits<{ (e: 'rispondi', voce: VoceDelCompito): void }>();

const ICONE = {
    emissione: ArrowUpFromLine, incassi: ArrowDownToLine, bonifico: Banknote, fattura: Receipt, f24: Landmark, quote: ChartPie, deficit: TrendingDown,
    assemblea: Users, segnalazione: Wrench, commento: MessageSquare, allegato: Paperclip, scadenza: CalendarDays,
} as const;

const aspetto = computed(() => aspettoDelTipo(props.riga.type));
const icona = computed(() => ICONE[aspetto.value.icona as keyof typeof ICONE] ?? CalendarDays);
const scaduto = computed(() => props.riga.gruppo === 'scaduti');
const segnalazione = computed(() => props.riga.gruppo === 'segnalazioni');
const PULSANTE = 'inline-flex h-8 min-w-0 flex-1 items-center justify-center gap-1 whitespace-nowrap rounded-md border bg-white px-3 text-xs font-bold shadow-sm transition-colors dark:bg-slate-900 xl:w-full';
</script>

<template>
    <div data-testid="riga-compito"
        class="group grid grid-cols-[2.25rem_minmax(0,1fr)] items-center gap-x-3 gap-y-2 border-b border-slate-100 px-5 py-3 hover:bg-slate-50/70 dark:border-slate-800 dark:hover:bg-slate-800/40 xl:grid-cols-[2.25rem_minmax(0,1fr)_10rem_7.5rem_11.5rem_2rem]">
        <div class="flex h-9 w-9 items-center justify-center rounded-lg" :class="aspetto.colore">
            <component :is="icona" class="h-4 w-4" />
        </div>

        <!-- Il titolo, la priorità, la riga sotto (al telefono anche il condominio), perché il compito è tornato. -->
        <div class="min-w-0">
            <div class="flex min-w-0 items-center gap-2">
                <p class="truncate text-sm font-semibold text-slate-900 dark:text-white" :title="riga.title">{{ riga.titolo }}</p>
                <span v-if="riga.priorita" data-testid="priorita"
                    class="shrink-0 rounded-md border px-1.5 py-px text-xs font-semibold"
                    :class="riga.priorita === 'urgente'
                        ? 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300'
                        : 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300'">
                    {{ riga.priorita }}
                </span>
            </div>
            <p class="truncate text-xs text-slate-500 dark:text-slate-400">
                {{ riga.sotto }}<span class="xl:hidden">{{ riga.sotto ? ' · ' : '' }}{{ riga.condominio }}</span>
            </p>
            <p v-if="riga.risposta?.tornato" class="mt-0.5 text-xs text-slate-600 dark:text-slate-300">{{ riga.risposta.tornato }}</p>
        </div>

        <!-- Il condominio per intero su due righe al più, e intero nel suggerimento. -->
        <div class="hidden min-w-0 items-start gap-1.5 text-sm text-slate-700 dark:text-slate-200 xl:flex" data-testid="condominio">
            <Building2 class="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400" />
            <span class="line-clamp-2 break-words" :title="riga.condominio">{{ riga.condominio }}</span>
        </div>

        <div class="col-start-2 flex items-baseline gap-2 xl:col-start-auto xl:block">
            <!-- Una segnalazione non ha una scadenza: il giorno in cui è stata aperta, e i giorni in grigio (l'allarme lo dà la priorità). -->
            <p data-testid="data" class="tabular-nums" :class="[segnalazione ? 'text-xs font-medium' : 'text-sm font-semibold',
                scaduto ? 'text-red-700 dark:text-red-400' : 'text-slate-800 dark:text-slate-200']">{{ testoDellaData(riga) }}</p>
            <p class="text-xs" :class="scaduto ? 'text-red-600 dark:text-red-400' : 'text-slate-500 dark:text-slate-400'">{{ testoQuando(riga.giorni) }}</p>
        </div>

        <!-- La prossima azione e il menu: al telefono in fila sotto il titolo, da xl nelle loro colonne. -->
        <div class="col-start-2 flex items-center gap-2 xl:contents">
            <!-- La prossima azione è il collegamento più usato: con Inertia e il prefetch; un indirizzo di un'altra origine resta un collegamento normale. -->
            <component :is="eDelSito(riga.azione.url) ? Link : 'a'" v-if="riga.azione.url" :href="riga.azione.url" v-bind="eDelSito(riga.azione.url) ? { prefetch: true } : {}"
                data-testid="prossima-azione" :class="PULSANTE"
                class="border-slate-200 text-slate-800 group-hover:border-indigo-300 group-hover:text-indigo-700 dark:border-slate-700 dark:text-slate-100 dark:group-hover:text-indigo-300">
                <span class="truncate">{{ riga.azione.testo }}</span>
                <ArrowRight class="h-3.5 w-3.5 shrink-0" />
            </component>
            <button v-else type="button" data-testid="prossima-azione" :disabled="!puoChiudere" :class="PULSANTE"
                :title="puoChiudere ? undefined : 'Serve il permesso «Modifica scadenza in agenda».'"
                class="border-slate-200 text-slate-600 enabled:group-hover:border-indigo-300 enabled:group-hover:text-indigo-700 disabled:opacity-60 dark:border-slate-700 dark:text-slate-300"
                @click="emit('rispondi', 'fatto')">
                <Check class="h-3.5 w-3.5 shrink-0" />
                <span class="truncate">{{ riga.azione.testo }}</span>
            </button>
            <div class="flex shrink-0 justify-end">
                <MenuDelCompito v-if="puoChiudere" :compito="riga" grande @scegli="(v) => emit('rispondi', v)" />
                <span v-else class="hidden w-8 xl:block"></span>
            </div>
        </div>
    </div>
</template>
