<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La lista «Sei mesi per agire» di tutti i condomini (o del condominio scelto), nel cassetto dell'Action inbox: tre sezioni, «Da seguire adesso» (le righe
 * con la data passata o entro 60 giorni), «Rendiconto in assemblea» e «Più avanti», le date più vicine prima fra tutti i condomini, con la regola delle 8 in
 * ogni sezione («Mostra tutte»). Ogni riga ha «Apri le persone →» scritto per intero e il nome del condominio, che portano al riquadro del condominio; la
 * freccia apre le persone, che portano all'estratto conto. Il rendiconto ha l'azione del caso («Apri il rendiconto →» o «Registra l'approvazione →»).
 *
 * I condomini li chiede la pagina, uno alla volta (`useSeiMesiDelloStudio`): qui arrivano lo stato e i condomini già calcolati, e mentre la fila va avanti le
 * righe grigie e «Calcolo i condomini: 12 su 40…». Tutto arriva dal server già deciso, come nel riquadro: qui non si ricalcola niente e non si scrive niente.
 */
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Skeleton } from '@/components/ui/skeleton';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { ArrowRight, CircleCheck, Info, Loader2, RotateCw } from 'lucide-vue-next';
import { usePermission } from '@/composables/permissions';
import RigaDelloStudio from '@/components/gestionale/seiMesi/RigaDelloStudio.vue';
import { classiDelColore, testoGiorni } from '@/lib/gestionale/debiti/seiMesiPerAgire';
import {
    COLONNE_STUDIO, FRASE_NIENTE_ADESSO_CASSETTO, FRASE_NON_VERSATO_STUDIO, FRASE_RENDICONTI, FRASE_VUOTO_STUDIO, SEZIONE_ADESSO, SOTTOTITOLO_RENDICONTI,
    TUTTE_FINO_A, finoAOtto, giorno, rendicontiDelloStudio, righeAdesso, righeAvanti, righeDelloStudio, statoDellaRiga, testoAzioneRendiconto, testoCaricamento,
    testoNonCalcolati, testoOltreRendiconti, testoOltreRighe, testoRendiconto, titoloPiuAvanti, type CondominioBreve, type StatoDelloStudio,
} from '@/lib/gestionale/debiti/seiMesiDelloStudio';

const props = defineProps<{ stato: StatoDelloStudio }>();
defineEmits<{ (e: 'riprova'): void }>();

const { generateRoute } = usePermission();

const righe = computed(() => righeDelloStudio(props.stato.esiti));
const adesso = computed(() => righeAdesso(righe.value));
const avanti = computed(() => righeAvanti(righe.value));
const rendiconti = computed(() => rendicontiDelloStudio(props.stato.esiti));
const troppe = computed(() => props.stato.esiti.filter((e) => e.troppe).sort((a, b) => a.condominio.nome.localeCompare(b.condominio.nome, 'it')));
const vuoto = computed(() => !props.stato.inCorso && !righe.value.length && !rendiconti.value.length && !troppe.value.length && !props.stato.daRiprovare.length);

// La regola delle 8, in ogni sezione: «Mostra tutte» allunga la sezione.
const tutteAdesso = ref(false);
const tutteAvanti = ref(false);
const tuttiIRendiconti = ref(false);
const vistaAdesso = computed(() => finoAOtto(adesso.value, tutteAdesso.value));
const vistaAvanti = computed(() => finoAOtto(avanti.value, tutteAvanti.value));
const vistaRendiconti = computed(() => finoAOtto(rendiconti.value, tuttiIRendiconti.value));

const alRiquadro = (r: { condominio: CondominioBreve; chiave: string }) => `${route(generateRoute('gestionale.index'), { condominio: r.condominio.id })}#${r.chiave}`;
const elencoPerData = (c: CondominioBreve) => route(generateRoute('gestionale.rate-scadute.index'), { condominio: c.id, ordine: 'sei_mesi' });

const ETICHETTA = 'mb-2 text-[11px] font-bold uppercase tracking-widest text-slate-400';
</script>

<template>
    <div class="@container space-y-5" data-testid="lista-sei-mesi">
        <!-- Mentre la fila va avanti: le righe grigie e a che punto è. -->
        <div v-if="stato.inCorso" class="space-y-2" data-testid="caricamento">
            <p class="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400" aria-live="polite">
                <Loader2 class="h-3.5 w-3.5 animate-spin" /> {{ testoCaricamento(stato.fatti, stato.totale) }}
            </p>
            <Skeleton v-for="n in 3" :key="n" class="h-14 w-full rounded-lg" data-testid="riga-grigia" />
        </div>

        <div v-if="vuoto" class="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50/50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/20 dark:text-emerald-300">
            <CircleCheck class="h-4 w-4 shrink-0" /> {{ FRASE_VUOTO_STUDIO }}
        </div>

        <template v-else>
            <!-- Da seguire adesso: la data passata o entro 60 giorni. -->
            <section data-testid="sezione" data-sezione="adesso">
                <p :class="ETICHETTA">{{ SEZIONE_ADESSO }}</p>
                <div v-if="!adesso.length && !stato.inCorso"
                    class="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50/50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/20 dark:text-emerald-300">
                    <CircleCheck class="h-4 w-4 shrink-0" /> {{ FRASE_NIENTE_ADESSO_CASSETTO }}
                </div>
                <template v-if="adesso.length">
                    <div class="hidden gap-2 whitespace-nowrap px-3 pb-1.5 text-[11px] font-bold uppercase tracking-widest text-slate-400 @2xl:grid @2xl:grid-cols-[6rem_minmax(0,1fr)_6.5rem_8rem_9.5rem_1.5rem]"
                        data-testid="intestazione-studio">
                        <span data-testid="colonna">{{ COLONNE_STUDIO[0] }}</span>
                        <span data-testid="colonna">{{ COLONNE_STUDIO[1] }}</span>
                        <span class="flex items-center justify-end gap-1">
                            <span data-testid="colonna">{{ COLONNE_STUDIO[2] }}</span>
                            <TooltipProvider>
                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <span tabindex="0" role="img" :aria-label="FRASE_NON_VERSATO_STUDIO"
                                            class="inline-flex cursor-help rounded text-slate-300 hover:text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400">
                                            <Info class="h-3.5 w-3.5" />
                                        </span>
                                    </TooltipTrigger>
                                    <TooltipContent side="top" class="max-w-xs normal-case tracking-normal"><p class="text-xs font-normal">{{ FRASE_NON_VERSATO_STUDIO }}</p></TooltipContent>
                                </Tooltip>
                            </TooltipProvider>
                        </span>
                        <span data-testid="colonna">{{ COLONNE_STUDIO[3] }}</span>
                        <span data-testid="colonna">{{ COLONNE_STUDIO[4] }}</span>
                        <span></span>
                    </div>
                    <ul class="space-y-1.5">
                        <RigaDelloStudio v-for="r in vistaAdesso.mostrate" :key="`${r.condominio.id}-${r.chiave}`" :riga="r" />
                    </ul>
                    <div v-if="adesso.length > TUTTE_FINO_A" class="mt-2 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500 dark:text-slate-400" data-testid="fondo-righe">
                        <span>{{ vistaAdesso.mancano.length ? testoOltreRighe(vistaAdesso.mancano) : '' }}</span>
                        <button type="button" class="font-bold text-indigo-600 hover:underline dark:text-indigo-400" data-testid="mostra-tutte" @click="tutteAdesso = !tutteAdesso">
                            {{ tutteAdesso ? 'Mostra meno' : 'Mostra tutte' }}
                        </button>
                    </div>
                </template>
            </section>

            <!-- I condomini con troppe rate scadute per calcolare qui, e quelli che non hanno risposto. -->
            <div v-for="e in troppe" :key="e.condominio.id" data-testid="troppe"
                class="flex flex-col gap-2 rounded-lg border border-slate-200 bg-slate-50/50 p-3 text-xs text-slate-600 dark:border-slate-700 dark:bg-slate-800/30 dark:text-slate-300 @lg:flex-row @lg:items-center @lg:justify-between">
                <p><span class="font-bold text-slate-800 dark:text-slate-100">{{ e.condominio.nome }}</span> · {{ e.troppe!.frase }}</p>
                <Link :href="elencoPerData(e.condominio)" class="shrink-0 font-bold text-indigo-600 hover:underline dark:text-indigo-400">Apri l'elenco per data dei sei mesi →</Link>
            </div>
            <div v-if="!stato.inCorso && stato.daRiprovare.length"
                class="flex flex-col gap-2 rounded-lg border border-amber-200 bg-amber-50/50 p-3 text-xs text-amber-800 dark:border-amber-800 dark:bg-amber-900/10 dark:text-amber-300 @lg:flex-row @lg:items-center @lg:justify-between">
                <p>{{ testoNonCalcolati(stato.daRiprovare.map((c) => c.nome)) }}</p>
                <button type="button" class="inline-flex shrink-0 items-center gap-1 font-bold hover:underline" data-testid="riprova" @click="$emit('riprova')">
                    <RotateCw class="h-3.5 w-3.5" /> Riprova
                </button>
            </div>

            <!-- Il rendiconto in assemblea: con l'azione del caso. -->
            <section data-testid="sezione" data-sezione="rendiconti">
                <div class="mb-2 flex items-center gap-1.5">
                    <p class="text-[11px] font-bold uppercase tracking-widest text-slate-400">{{ SOTTOTITOLO_RENDICONTI }}</p>
                    <TooltipProvider>
                        <Tooltip>
                            <TooltipTrigger as-child>
                                <span tabindex="0" role="img" :aria-label="FRASE_RENDICONTI"
                                    class="inline-flex cursor-help rounded text-slate-300 hover:text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400">
                                    <Info class="h-3.5 w-3.5" />
                                </span>
                            </TooltipTrigger>
                            <TooltipContent side="bottom" class="max-w-sm"><p class="text-xs">{{ FRASE_RENDICONTI }}</p></TooltipContent>
                        </Tooltip>
                    </TooltipProvider>
                </div>
                <p v-if="!rendiconti.length && !stato.inCorso" class="text-xs text-slate-500 dark:text-slate-400">Nessun rendiconto da portare in assemblea entro 60 giorni.</p>
                <ul class="space-y-1.5">
                    <li v-for="r in vistaRendiconti.mostrate" :key="`${r.condominio.id}-${r.chiave}`" :data-chiave="`${r.condominio.id}-${r.chiave}`" data-testid="rendiconto-studio"
                        class="flex flex-col gap-2 rounded-lg border border-slate-200 px-3 py-2.5 dark:border-slate-800 @2xl:grid @2xl:grid-cols-[6rem_minmax(0,1fr)_auto] @2xl:items-center"
                        :class="[classiDelColore(r.colore).sfondo, r.risposta?.stato === 'non_serve' ? 'opacity-70' : '']">
                        <div class="flex items-baseline gap-2 @2xl:block">
                            <p class="text-sm font-bold tabular-nums" :class="r.colore === 'passata' ? 'text-red-700 dark:text-red-400' : 'text-slate-800 dark:text-slate-100'">{{ giorno(r.data) }}</p>
                            <p class="text-xs" :class="classiDelColore(r.colore).testo">{{ testoGiorni(r.giorni) }}</p>
                        </div>
                        <div class="min-w-0">
                            <div class="flex min-w-0 items-center gap-2">
                                <Link :href="alRiquadro(r)" data-testid="vai-al-riquadro" class="truncate text-sm font-semibold text-slate-900 hover:underline dark:text-white">{{ r.condominio.nome }}</Link>
                                <Badge v-if="statoDellaRiga(r)" variant="outline" class="shrink-0 whitespace-nowrap border-amber-200 bg-amber-50 text-xs font-medium text-amber-800 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-300">{{ statoDellaRiga(r) }}</Badge>
                            </div>
                            <p class="text-xs text-slate-500 dark:text-slate-400" :title="r.frase">{{ testoRendiconto(r) }} · {{ r.breve }}</p>
                        </div>
                        <Link :href="r.url" data-testid="azione-riga"
                            class="inline-flex h-8 items-center justify-center gap-1 whitespace-nowrap rounded-md border px-3 text-xs font-bold shadow-sm"
                            :class="r.colore === 'passata'
                                ? 'border-red-200 bg-white text-red-700 hover:bg-red-50 dark:border-red-800 dark:bg-slate-900 dark:text-red-300'
                                : 'border-slate-200 bg-white text-slate-800 hover:border-indigo-300 hover:text-indigo-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100'">
                            {{ testoAzioneRendiconto(r) }} <ArrowRight class="h-3.5 w-3.5" />
                        </Link>
                    </li>
                </ul>
                <div v-if="rendiconti.length > TUTTE_FINO_A" class="mt-2 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500 dark:text-slate-400" data-testid="fondo-rendiconti">
                    <span>{{ vistaRendiconti.mancano.length ? testoOltreRendiconti(vistaRendiconti.mancano) : '' }}</span>
                    <button type="button" class="font-bold text-indigo-600 hover:underline dark:text-indigo-400" @click="tuttiIRendiconti = !tuttiIRendiconti">
                        {{ tuttiIRendiconti ? 'Mostra meno' : 'Mostra tutti' }}
                    </button>
                </div>
            </section>

            <!-- Più avanti: le date oltre i 60 giorni. -->
            <section data-testid="sezione" data-sezione="avanti">
                <p :class="ETICHETTA">{{ avanti.length ? titoloPiuAvanti(avanti) : 'Più avanti' }}</p>
                <p v-if="!avanti.length && !stato.inCorso" class="text-xs text-slate-500 dark:text-slate-400">Nessuna data più avanti.</p>
                <ul class="space-y-1.5">
                    <RigaDelloStudio v-for="r in vistaAvanti.mostrate" :key="`${r.condominio.id}-${r.chiave}`" :riga="r" />
                </ul>
                <div v-if="avanti.length > TUTTE_FINO_A" class="mt-2 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500 dark:text-slate-400" data-testid="fondo-righe">
                    <span>{{ vistaAvanti.mancano.length ? testoOltreRighe(vistaAvanti.mancano) : '' }}</span>
                    <button type="button" class="font-bold text-indigo-600 hover:underline dark:text-indigo-400" data-testid="mostra-tutte" @click="tutteAvanti = !tutteAvanti">
                        {{ tutteAvanti ? 'Mostra meno' : 'Mostra tutte' }}
                    </button>
                </div>
            </section>
        </template>
    </div>
</template>
