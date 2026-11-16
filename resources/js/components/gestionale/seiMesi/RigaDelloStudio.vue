<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Una riga dei sei mesi per agire nel cassetto dell'Action inbox: un esercizio di un condominio con qualcuno da seguire. Con lo spazio (il contenitore largo
 * almeno 42rem) le colonne fisse «Data», «Condominio», «Non versato», «Stato», «Prossima azione» e la freccia; più stretto, una riga sotto l'altra.
 *
 * «Apri le persone →» e il nome del condominio portano al riquadro del condominio, aperto sulla riga, dove si sceglie la prossima azione di ogni persona. La
 * freccia apre il dettaglio: le persone da seguire con la cifra di questo esercizio (il nome porta all'estratto conto), chi è chiuso, la risposta.
 */
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { ArrowRight, ChevronDown, ChevronRight } from 'lucide-vue-next';
import { usePermission } from '@/composables/permissions';
import { classiDelColore, testoGiorni } from '@/lib/gestionale/debiti/seiMesiPerAgire';
import {
    COLONNE_PERSONE_STUDIO, euroTesto, finoAOtto, giorno, statoDellaRiga, testoChiuseStudio, testoCompitoDal, testoEsercizio, testoOltrePersone, testoPersone,
    type ConIlCondominio, type RigaDelloStudio,
} from '@/lib/gestionale/debiti/seiMesiDelloStudio';

const props = defineProps<{ riga: ConIlCondominio<RigaDelloStudio> }>();

const { generateRoute } = usePermission();
const aperta = ref(false);
const colore = computed(() => classiDelColore(props.riga.colore));
const stato = computed(() => statoDellaRiga(props.riga));
const persone = computed(() => finoAOtto(props.riga.persone));

/** Il riquadro del condominio, aperto sulla riga: l'ancora è l'`id` della riga nel riquadro. */
const alRiquadro = computed(() => `${route(generateRoute('gestionale.index'), { condominio: props.riga.condominio.id })}#${props.riga.chiave}`);
const estrattoConto = (anagrafica: number) => route(generateRoute('gestionale.anagrafiche.estratto-conto'), { condominio: props.riga.condominio.id, anagrafica });
</script>

<template>
    <li :data-chiave="`${riga.condominio.id}-${riga.chiave}`" data-testid="riga-studio"
        class="rounded-lg border border-slate-200 dark:border-slate-800" :class="riga.risposta?.stato === 'non_serve' ? 'opacity-70' : ''">
        <div class="flex flex-col gap-2 px-3 py-2.5 @2xl:grid @2xl:grid-cols-[6rem_minmax(0,1fr)_6.5rem_8rem_9.5rem_1.5rem] @2xl:items-center @2xl:gap-2">
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 @2xl:contents">
                <div class="flex items-baseline gap-2 @2xl:block">
                    <p class="text-sm font-bold tabular-nums text-slate-800 dark:text-slate-100">{{ giorno(riga.data) }}</p>
                    <p class="text-xs" :class="colore.testo" data-testid="giorni">{{ testoGiorni(riga.giorni) }}</p>
                </div>
                <div class="min-w-0">
                    <Link :href="alRiquadro" data-testid="vai-al-riquadro" class="block truncate text-sm font-semibold text-slate-900 hover:underline dark:text-white"
                        :title="riga.condominio.nome">{{ riga.condominio.nome }}</Link>
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ testoEsercizio(riga) }}</p>
                </div>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-2 @2xl:contents">
                <p class="tabular-nums @2xl:text-right" data-testid="non-versato">
                    <span class="text-sm font-bold text-slate-900 dark:text-white">{{ euroTesto(riga.cifra) }}</span>
                    <span class="ml-1.5 text-xs text-slate-500 dark:text-slate-400 @2xl:ml-0 @2xl:block">{{ testoPersone(riga.quante) }}</span>
                </p>
                <div class="min-w-0">
                    <Badge v-if="stato" variant="outline" class="whitespace-nowrap text-xs font-medium"
                        :class="stato === 'compito aperto'
                            ? 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-300'
                            : 'border-slate-200 bg-white text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300'">{{ stato }}</Badge>
                    <p v-else-if="riga.colore === 'lontana'" class="text-xs text-slate-500 dark:text-slate-400" data-testid="compito-dal">{{ testoCompitoDal(riga) }}</p>
                </div>
                <div class="flex w-full items-center gap-1 @2xl:contents">
                    <Link :href="alRiquadro" data-testid="azione-riga"
                        class="inline-flex h-8 flex-1 items-center justify-center gap-1 whitespace-nowrap rounded-md border border-slate-200 bg-white px-3 text-xs font-bold text-slate-800 shadow-sm hover:border-indigo-300 hover:text-indigo-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:hover:text-indigo-300 @2xl:w-full">
                        Apri le persone <ArrowRight class="h-3.5 w-3.5" />
                    </Link>
                    <button type="button" :aria-label="aperta ? 'Chiudi la riga' : 'Apri la riga'" data-testid="apri-riga"
                        class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800 dark:hover:text-slate-200"
                        @click="aperta = !aperta">
                        <ChevronDown v-if="aperta" class="h-4 w-4" />
                        <ChevronRight v-else class="h-4 w-4" />
                    </button>
                </div>
            </div>
        </div>

        <!-- Il dettaglio: le persone da seguire con la cifra di questo esercizio, la più alta prima; chi è chiuso; la risposta. -->
        <div v-if="aperta" class="border-t border-slate-100 dark:border-slate-800" data-testid="persone-studio">
            <div class="hidden grid-cols-[minmax(0,1fr)_8rem] gap-2 whitespace-nowrap px-3 pt-2 text-[11px] font-bold uppercase tracking-widest text-slate-400 @2xl:grid">
                <span>{{ COLONNE_PERSONE_STUDIO[0] }}</span>
                <span class="text-right">{{ COLONNE_PERSONE_STUDIO[1] }}</span>
            </div>
            <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                <li v-for="p in persone.mostrate" :key="p.anagrafica_id" data-testid="persona-studio" class="grid grid-cols-[minmax(0,1fr)_8rem] items-center gap-2 px-3 py-2">
                    <Link :href="estrattoConto(p.anagrafica_id)" class="min-w-0 truncate text-sm font-medium text-slate-900 hover:underline dark:text-slate-100">{{ p.nome }}</Link>
                    <span class="text-right text-sm font-bold tabular-nums text-slate-900 dark:text-white">{{ euroTesto(p.cifra) }}</span>
                </li>
            </ul>
            <div class="space-y-1 border-t border-slate-100 bg-slate-50/50 px-3 py-2 text-xs text-slate-600 dark:border-slate-800 dark:bg-slate-800/30 dark:text-slate-300">
                <p v-if="persone.mancano.length">{{ testoOltrePersone(persone.mancano) }}</p>
                <p v-if="testoChiuseStudio(riga.chiuse)">{{ testoChiuseStudio(riga.chiuse) }}</p>
                <p v-if="riga.compito_tornato">{{ riga.compito_tornato }}</p>
                <p v-if="riga.risposta?.frase">{{ riga.risposta.frase }}</p>
                <p class="text-slate-500 dark:text-slate-400">La prossima azione per ogni persona la scegli nel riquadro del condominio.</p>
            </div>
        </div>
    </li>
</template>
