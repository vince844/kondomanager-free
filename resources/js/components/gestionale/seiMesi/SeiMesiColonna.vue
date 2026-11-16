<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Sei mesi per agire» nella colonna di destra dell'Action inbox, per tutti i condomini o per quello scelto: le righe da seguire adesso (la data passata o
 * entro 60 giorni), o la frase verde quando non ce ne sono; i rendiconti da portare in assemblea con l'azione del caso; in fondo «N condomini più avanti, il
 * primo il …» e «Apri l'elenco», che apre il cassetto con la lista intera. Niente scorrimento dentro il riquadro, e la regola delle 8 vale per la card intera:
 * fino a 8 righe in tutto (le righe da seguire e i rendiconti), oltre 5 in tutto e «e altre N», che apre il cassetto.
 *
 * Lo stato arriva dalla pagina, che chiede i condomini uno alla volta (`useSeiMesiDelloStudio`): mentre la fila va avanti, «Calcolo i condomini: 12 su 40…».
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Skeleton } from '@/components/ui/skeleton';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { ArrowRight, CircleCheck, Hourglass, Info, Loader2, PanelRightOpen } from 'lucide-vue-next';
import { usePermission } from '@/composables/permissions';
import { classiDelColore, testoGiorni } from '@/lib/gestionale/debiti/seiMesiPerAgire';
import {
    FRASE_NIENTE_ADESSO, FRASE_SUGGERIMENTO_STUDIO, SOTTOTITOLO_RENDICONTI, TITOLO_COLONNA, descrizioneDellaColonna, euroTesto, finoAOttoInDue, giorno,
    rendicontiDelloStudio, righeAdesso, righeAvanti, righeDelloStudio, testoAzioneRendiconto, testoCaricamento, testoEsercizio, testoNonCalcolati, testoPersone,
    testoPiuAvanti, testoRendiconto, type CondominioBreve, type StatoDelloStudio,
} from '@/lib/gestionale/debiti/seiMesiDelloStudio';

const props = defineProps<{ stato: StatoDelloStudio; condominio: string | null }>();
defineEmits<{ (e: 'apri-elenco'): void }>();

const { generateRoute } = usePermission();

const righe = computed(() => righeDelloStudio(props.stato.esiti));
// La regola delle 8 per la card intera: le righe da seguire e i rendiconti insieme.
const dueListe = computed(() => finoAOttoInDue(righeAdesso(righe.value), rendicontiDelloStudio(props.stato.esiti)));
const adesso = computed(() => dueListe.value[0]);
const avanti = computed(() => righeAvanti(righe.value));
const rendiconti = computed(() => dueListe.value[1]);
const nonCalcolati = computed(() => [...props.stato.esiti.filter((e) => e.troppe).map((e) => e.condominio.nome), ...props.stato.daRiprovare.map((c) => c.nome)]);
const niente = computed(() => !props.stato.inCorso && !adesso.value.mostrate.length);

const alRiquadro = (r: { condominio: CondominioBreve; chiave: string }) => `${route(generateRoute('gestionale.index'), { condominio: r.condominio.id })}#${r.chiave}`;
const altre = (n: number, una: string, tante: string) => (n === 1 ? `e un'altra ${una}` : `e altre ${n} ${tante}`);
</script>

<template>
    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900" data-testid="sei-mesi-colonna">
        <div class="border-b border-slate-100 bg-slate-50/60 px-5 py-3.5 dark:border-slate-800 dark:bg-slate-800/40">
            <div class="flex items-center gap-2">
                <Hourglass class="h-4 w-4 shrink-0 text-amber-600" />
                <h2 class="text-xs font-bold uppercase tracking-widest text-slate-700 dark:text-slate-200">{{ TITOLO_COLONNA }}</h2>
                <TooltipProvider>
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <span tabindex="0" role="img" :aria-label="FRASE_SUGGERIMENTO_STUDIO"
                                class="inline-flex shrink-0 cursor-help rounded text-slate-400 hover:text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400">
                                <Info class="h-3.5 w-3.5" />
                            </span>
                        </TooltipTrigger>
                        <TooltipContent side="bottom" class="max-w-sm"><p class="text-xs">{{ FRASE_SUGGERIMENTO_STUDIO }}</p></TooltipContent>
                    </Tooltip>
                </TooltipProvider>
            </div>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ descrizioneDellaColonna(condominio) }}</p>
        </div>

        <div class="space-y-3 px-3 py-3">
            <div v-if="stato.inCorso" class="space-y-2 px-2">
                <p class="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400" aria-live="polite">
                    <Loader2 class="h-3.5 w-3.5 animate-spin" /> {{ testoCaricamento(stato.fatti, stato.totale) }}
                </p>
                <Skeleton v-if="!adesso.mostrate.length" class="h-12 w-full rounded-lg" />
            </div>

            <!-- Da seguire adesso. -->
            <p v-if="niente" class="flex items-center gap-2 px-2 text-sm text-emerald-700 dark:text-emerald-400"><CircleCheck class="h-4 w-4 shrink-0" /> {{ FRASE_NIENTE_ADESSO }}</p>
            <ul v-if="adesso.mostrate.length" class="space-y-2">
                <li v-for="r in adesso.mostrate" :key="`${r.condominio.id}-${r.chiave}`" data-testid="riga-colonna"
                    class="rounded-lg border border-slate-200 px-3 py-2.5 dark:border-slate-800" :class="classiDelColore(r.colore).sfondo">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <Link :href="alRiquadro(r)" class="block truncate text-sm font-bold text-slate-900 hover:underline dark:text-white">{{ r.condominio.nome }}</Link>
                            <p class="text-xs text-slate-500 dark:text-slate-400">{{ testoEsercizio(r) }} · {{ testoPersone(r.quante) }} · {{ euroTesto(r.cifra) }}</p>
                        </div>
                        <div class="shrink-0 text-right">
                            <p class="text-sm font-bold tabular-nums" :class="classiDelColore(r.colore).testo">{{ giorno(r.data) }}</p>
                            <p class="text-xs" :class="classiDelColore(r.colore).testo">{{ testoGiorni(r.giorni) }}</p>
                        </div>
                    </div>
                    <Link :href="alRiquadro(r)"
                        class="mt-2 inline-flex h-8 w-full items-center justify-center gap-1 rounded-md border border-slate-200 bg-white text-xs font-bold text-slate-800 hover:border-indigo-300 hover:text-indigo-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:hover:text-indigo-300">
                        Apri le persone <ArrowRight class="h-3.5 w-3.5" />
                    </Link>
                </li>
            </ul>
            <button v-if="adesso.mancano.length" type="button" class="px-2 text-xs font-bold text-indigo-600 hover:underline dark:text-indigo-400" @click="$emit('apri-elenco')">
                {{ altre(adesso.mancano.length, 'riga da seguire', 'righe da seguire') }}
            </button>

            <!-- Il rendiconto in assemblea. -->
            <template v-if="rendiconti.mostrate.length">
                <p class="px-2 pt-1 text-[11px] font-bold uppercase tracking-widest text-slate-400">{{ SOTTOTITOLO_RENDICONTI }}</p>
                <ul class="space-y-2">
                    <li v-for="r in rendiconti.mostrate" :key="`${r.condominio.id}-${r.chiave}`" data-testid="rendiconto-colonna"
                        class="rounded-lg border border-slate-200 px-3 py-2.5 dark:border-slate-800"
                        :class="r.colore === 'passata' ? 'bg-red-50/40 dark:bg-red-950/20' : ''">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <Link :href="alRiquadro(r)" class="block truncate text-sm font-bold text-slate-900 hover:underline dark:text-white">{{ r.condominio.nome }}</Link>
                                <p class="text-xs text-slate-500 dark:text-slate-400">{{ testoRendiconto(r) }} · {{ r.breve.toLowerCase() }}</p>
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="text-sm font-bold tabular-nums" :class="r.colore === 'passata' ? 'text-red-700 dark:text-red-400' : classiDelColore(r.colore).testo">{{ giorno(r.data) }}</p>
                                <p class="text-xs" :class="r.colore === 'passata' ? 'text-red-600 dark:text-red-400' : classiDelColore(r.colore).testo">{{ testoGiorni(r.giorni) }}</p>
                            </div>
                        </div>
                        <Link :href="r.url" data-testid="azione-rendiconto"
                            class="mt-2 inline-flex h-8 w-full items-center justify-center gap-1 rounded-md border bg-white text-xs font-bold dark:bg-slate-900"
                            :class="r.colore === 'passata' ? 'border-red-200 text-red-700 hover:bg-red-50 dark:border-red-800 dark:text-red-300' : 'border-slate-200 text-slate-800 hover:border-indigo-300 dark:border-slate-700 dark:text-slate-100'">
                            {{ testoAzioneRendiconto(r) }} <ArrowRight class="h-3.5 w-3.5" />
                        </Link>
                    </li>
                </ul>
                <button v-if="rendiconti.mancano.length" type="button" class="px-2 text-xs font-bold text-indigo-600 hover:underline dark:text-indigo-400" @click="$emit('apri-elenco')">
                    {{ rendiconti.mancano.length === 1 ? 'e un altro rendiconto' : `e altri ${rendiconti.mancano.length} rendiconti` }}
                </button>
            </template>

            <p v-if="!stato.inCorso && nonCalcolati.length" class="px-2 text-xs text-amber-700 dark:text-amber-300">{{ testoNonCalcolati(nonCalcolati) }}</p>
        </div>

        <div class="flex items-center justify-between gap-2 border-t border-slate-100 px-5 py-3 dark:border-slate-800">
            <p class="min-w-0 text-xs text-slate-500 dark:text-slate-400" data-testid="piu-avanti">
                {{ testoPiuAvanti(avanti) || (stato.inCorso ? '' : 'Nessuna data più avanti.') }}
            </p>
            <button type="button" data-testid="apri-elenco" class="inline-flex shrink-0 items-center gap-1 whitespace-nowrap text-xs font-bold text-indigo-600 hover:underline dark:text-indigo-400"
                @click="$emit('apri-elenco')">
                Apri l'elenco <PanelRightOpen class="h-3.5 w-3.5" />
            </button>
        </div>
    </section>
</template>
