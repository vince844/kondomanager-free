<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Il compito dei sei mesi (o dei 180 giorni del rendiconto) nell'Inbox operativa del condominio, nella forma dei compiti di oggi: la clessidra (ambra o rossa
 * come la data), il titolo (rosso con la data passata, come i compiti scaduti), la descrizione, il cartellino maiuscolo con il punto colorato. A destra il passo
 * («Apri le persone →», che porta alla riga del riquadro; per il rendiconto «Apri il rendiconto →») e il pulsante quadrato «⋯» con le risposte. Non è un
 * evento dell'agenda: si calcola a ogni apertura con il riquadro.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { usePermission } from '@/composables/permissions';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { ArrowRight, Hourglass, MoreHorizontal } from 'lucide-vue-next';
import { giorno } from '@/lib/gestionale/debiti/rateScadute';
import { cartellinoDelCompito, descrizioneDelCompito, titoloDelCompito, type Compito, type RichiestaDiRisposta } from '@/lib/gestionale/debiti/seiMesiPerAgire';

const props = defineProps<{ compito: Compito; puoRispondere: boolean }>();
const emit = defineEmits<{ (e: 'rispondi', r: RichiestaDiRisposta): void }>();

const { generateRoute } = usePermission();
const indirizzoAgenda = computed(() => route(generateRoute('eventi.index')));
const passata = computed(() => props.compito.giorni < 0);
const rendiconto = computed(() => props.compito.argomento === 'rendiconto');
const coloreIcona = computed(() => (passata.value ? 'text-red-500' : 'text-amber-500'));

function rispondi(tipo: RichiestaDiRisposta['tipo']) {
    emit('rispondi', { tipo, argomento: props.compito.argomento, esercizio: props.compito.esercizio, data: props.compito.data, persone: props.compito.tutte ?? [] });
}
</script>

<template>
    <li class="p-4 rounded-lg border transition-all"
        :class="passata ? 'border-y-transparent border-r-transparent bg-red-100/20 dark:bg-red-900/10' : 'border-transparent bg-amber-50/30 hover:border-slate-200 dark:bg-amber-900/5 dark:hover:border-slate-700'"
        :data-testid="`compito-${compito.chiave}`">
        <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-4">
            <div class="flex items-start gap-3 flex-1 min-w-0">
                <div class="mt-1 shrink-0">
                    <Hourglass class="w-5 h-5" :class="coloreIcona" data-testid="icona-compito" />
                </div>
                <div class="flex-1 min-w-0">
                    <h3 class="text-sm font-bold text-slate-900 dark:text-white" :class="{ 'text-red-700 dark:text-red-400': passata }">{{ titoloDelCompito(compito) }}</h3>
                    <p class="text-[13px] text-slate-500 dark:text-slate-400 mt-1 leading-relaxed pr-6">{{ descrizioneDelCompito(compito) }}</p>
                    <p v-if="compito.tornato" class="mt-1 text-xs text-slate-600 dark:text-slate-300">{{ compito.tornato }}</p>
                    <div class="flex flex-wrap items-center gap-3 mt-2 text-xs font-bold uppercase text-slate-400">
                        <span class="flex items-center gap-1" :class="passata ? 'text-red-500' : 'text-amber-600'">
                            <span class="w-1.5 h-1.5 rounded-full" :class="passata ? 'bg-red-500 animate-pulse' : 'bg-amber-500'"></span> {{ cartellinoDelCompito(compito.giorni) }}
                        </span>
                        <Link v-if="rendiconto && compito.agenda" :href="indirizzoAgenda" data-testid="in-agenda" title="Apri l'agenda"
                            class="normal-case font-medium text-emerald-700 hover:underline dark:text-emerald-400">In agenda il {{ giorno(compito.agenda.giorno) }}</Link>
                    </div>
                </div>
            </div>

            <div class="shrink-0 flex items-center gap-2">
                <a :href="rendiconto ? compito.url : `#${compito.ancora}`" data-testid="apri-persone"
                    class="inline-flex items-center justify-center h-7 px-3 text-xs font-bold transition-all rounded-md bg-white border shadow-sm dark:bg-slate-800"
                    :class="passata
                        ? 'border-red-200 text-red-600 hover:bg-red-50 hover:border-red-300 dark:border-red-800 dark:text-red-400 dark:hover:bg-red-900/30'
                        : 'border-slate-200 text-slate-700 hover:bg-slate-50 hover:text-indigo-700 hover:border-indigo-300 dark:border-slate-700 dark:text-slate-200 dark:hover:text-indigo-400'">
                    {{ rendiconto ? 'Apri il rendiconto' : 'Apri le persone' }} <ArrowRight class="w-3.5 h-3.5 ml-1.5" />
                </a>
                <DropdownMenu v-if="puoRispondere">
                    <DropdownMenuTrigger as-child>
                        <button type="button" title="Le risposte" aria-label="Le risposte" data-testid="menu-risposte"
                            class="inline-flex items-center justify-center w-7 h-7 rounded-md border border-slate-200 text-slate-400 bg-white hover:text-slate-700 hover:border-slate-300 hover:bg-slate-50 shadow-sm transition-all dark:bg-slate-800 dark:border-slate-700 dark:hover:text-slate-200">
                            <MoreHorizontal class="w-4 h-4" />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-56">
                        <DropdownMenuItem class="text-xs" @select="rispondi('fatto')">Fatto, con una nota</DropdownMenuItem>
                        <DropdownMenuItem class="text-xs" @select="rispondi('non_serve')">Non serve, con il motivo</DropdownMenuItem>
                        <DropdownMenuItem class="text-xs" @select="rispondi('ricordamelo')">Ricordamelo il…</DropdownMenuItem>
                        <template v-if="rendiconto">
                            <DropdownMenuSeparator />
                            <DropdownMenuItem class="text-xs" @select="rispondi('in_agenda')">
                                {{ compito.agenda ? `In agenda il ${giorno(compito.agenda.giorno)}` : 'Mettilo in agenda' }}
                            </DropdownMenuItem>
                        </template>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </div>
    </li>
</template>
