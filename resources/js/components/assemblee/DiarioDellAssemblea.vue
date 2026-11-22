<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Il «Diario» dell'assemblea (§3 P3, §6.4; scena 3 della bozza del registro, decisione 518): il registro delle modifiche sulle cose dell'assemblea insieme
 * agli invii, per giorno, dalla più recente: l'ora, chi, la frase (gli invii senza badge: la frase dice già che cosa è, e il filtro «Invii» li separa).
 * I filtri «Tutto | Modifiche | Invii» e «Mostra altre» (trenta per volta, con il cursore) sono visite parziali del solo `diario`, che il server manda
 * differito.
 *
 * Due forme: nella pagina della riunione registrata a mano, il riquadro con il titolo, come prima; nel cassetto di lato della pagina dell'assemblea
 * (`CassettoDelDiario`, la scena 3 della bozza dei documenti dei punti e del diario) senza riquadro, con i filtri in una barra larga quanto il cassetto, il
 * giorno di oggi detto «Oggi», e chi sotto la frase.
 */
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { giornoDiRoma } from '@/components/assemblee/sala/sala';
import { righePerGiorno, type DiarioDellaPagina } from '@/lib/assemblee/pagine';
import { giornoItaliano } from '@/lib/giorno';
import type { RigaDelDiario } from '@/types/assemblee';

const props = withDefaults(defineProps<{ diario: DiarioDellaPagina | null | undefined; titolo?: string; forma?: 'pagina' | 'cassetto' }>(),
    { titolo: '', forma: 'pagina' });

type Filtro = 'tutto' | 'modifiche' | 'invii';
const filtro = ref<Filtro>('tutto');
const righe = ref<RigaDelDiario[]>([]);
const caricamento = ref(false);
let aggiungi = false;

watch(() => props.diario, (d) => {
    if (!d) return;
    righe.value = aggiungi ? [...righe.value, ...d.righe] : [...d.righe];
    aggiungi = false;
}, { immediate: true });

function leggi(dopo: string | null) {
    caricamento.value = true;
    aggiungi = dopo !== null;
    router.reload({
        only: ['diario'],
        data: { diario_filtro: filtro.value, diario_dopo: dopo ?? undefined },
        preserveScroll: true,
        onFinish: () => { caricamento.value = false; },
    });
}

function scegli(f: Filtro) {
    if (filtro.value === f) return;
    filtro.value = f;
    leggi(null);
}

const nelCassetto = computed(() => props.forma === 'cassetto');
const giorni = computed(() => righePerGiorno(righe.value));
/**
 * Il giorno di oggi, come lo scrive il server («20/11/2026»): nel cassetto si legge «Oggi, 20/11/2026». Il giorno di Roma, dove il server scrive i giorni
 * del diario: con il computer su un altro fuso, dopo la mezzanotte di Roma il giorno del computer è ancora ieri (rilievo C54).
 */
const oggi = computed(() => giornoItaliano(giornoDiRoma()));
const nomeDelGiorno = (giorno: string) => (nelCassetto.value && giorno === oggi.value ? `Oggi, ${giorno}` : giorno);
const filtri: { valore: Filtro; nome: string }[] = [{ valore: 'tutto', nome: 'Tutto' }, { valore: 'modifiche', nome: 'Modifiche' }, { valore: 'invii', nome: 'Invii' }];
</script>

<template>
    <!-- Nel cassetto: senza riquadro, i filtri in una barra a tre scelte larghe uguali, chi sotto la frase. -->
    <div v-if="nelCassetto" class="px-5 pb-5" data-testid="diario">
        <div class="mt-4 grid grid-cols-3 overflow-hidden rounded-lg border border-slate-200 dark:border-slate-700" role="group" aria-label="Che cosa mostrare"
            data-testid="filtri-diario">
            <button v-for="(f, i) in filtri" :key="f.valore" type="button" class="h-8 text-xs transition-colors"
                :class="[filtro === f.valore ? 'bg-slate-900 font-semibold text-white dark:bg-white dark:text-slate-900' : 'text-slate-700 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800',
                    i > 0 ? 'border-l border-slate-200 dark:border-slate-700' : '']"
                :aria-pressed="filtro === f.valore" @click="scegli(f.valore)">{{ f.nome }}</button>
        </div>

        <p v-if="!diario" class="py-6 text-center text-sm text-slate-500 dark:text-slate-400">Un momento…</p>
        <p v-else-if="righe.length === 0" class="py-6 text-center text-sm text-slate-500 dark:text-slate-400" data-testid="diario-vuoto">
            Ancora niente: il diario comincia con la preparazione.
        </p>
        <template v-else>
            <section v-for="g in giorni" :key="g.giorno">
                <h4 class="mt-4 mb-1 text-xs font-semibold text-slate-500 dark:text-slate-400" data-testid="giorno-diario">{{ nomeDelGiorno(g.giorno) }}</h4>
                <ul>
                    <li v-for="(r, i) in g.righe" :key="`${r.istante}-${i}`" class="flex gap-2.5 border-t border-slate-100 py-2 text-[13px] first:border-t-0 dark:border-slate-800"
                        data-testid="riga-diario">
                        <span class="w-11 shrink-0 pt-px text-xs tabular-nums text-slate-500 dark:text-slate-400" data-testid="ora">{{ r.ora }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="break-words text-slate-900 dark:text-slate-100" data-testid="frase">{{ r.frase }}</span>
                            <span class="block text-xs text-slate-500 dark:text-slate-400" data-testid="chi">{{ r.chi }}</span>
                        </span>
                    </li>
                </ul>
            </section>
        </template>
        <p v-if="diario && diario.altre" class="mt-3">
            <button type="button" class="text-xs font-medium text-sky-700 hover:underline disabled:opacity-60 dark:text-sky-400" :disabled="caricamento"
                data-testid="mostra-altre-diario" @click="leggi(diario.dopo)">
                {{ caricamento ? 'Arrivano…' : 'Mostra altre' }}
            </button>
        </p>
    </div>

    <section v-else class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950" data-testid="diario">
        <header class="flex flex-wrap items-start justify-between gap-2 border-b border-slate-100 px-4 py-3 dark:border-slate-800">
            <div>
                <h3 class="text-sm font-semibold text-slate-900 dark:text-white">{{ titolo }}</h3>
                <p class="text-xs text-slate-500 dark:text-slate-400">Ogni gesto, con chi l'ha fatto e quando. Nessuno lo può modificare o cancellare.</p>
            </div>
            <div class="inline-flex rounded-md border border-slate-200 p-0.5 dark:border-slate-700" role="group" aria-label="Che cosa mostrare" data-testid="filtri-diario">
                <button v-for="f in filtri" :key="f.valore" type="button" class="h-7 rounded px-2.5 text-xs font-medium"
                    :class="filtro === f.valore ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'text-slate-600 dark:text-slate-300'"
                    :aria-pressed="filtro === f.valore" @click="scegli(f.valore)">{{ f.nome }}</button>
            </div>
        </header>

        <p v-if="!diario" class="px-4 py-6 text-center text-sm text-slate-500">Un momento…</p>
        <p v-else-if="righe.length === 0" class="px-4 py-6 text-center text-sm text-slate-500 dark:text-slate-400" data-testid="diario-vuoto">
            Ancora niente: il diario comincia con la preparazione.
        </p>
        <div v-else class="divide-y divide-slate-100 dark:divide-slate-800">
            <div v-for="g in giorni" :key="g.giorno" class="px-4 py-2.5">
                <p class="mb-1.5 text-xs font-semibold text-slate-500 dark:text-slate-400">{{ g.giorno }}</p>
                <ul class="space-y-1.5">
                    <li v-for="(r, i) in g.righe" :key="`${r.istante}-${i}`" class="grid grid-cols-[44px_minmax(0,1fr)] gap-x-3 text-sm sm:grid-cols-[44px_150px_minmax(0,1fr)]"
                        data-testid="riga-diario">
                        <span class="tabular-nums text-slate-500 dark:text-slate-400">{{ r.ora }}</span>
                        <span class="truncate text-slate-700 sm:block dark:text-slate-300" :class="{ 'col-start-2 sm:col-start-auto': true }">{{ r.chi }}</span>
                        <span class="col-start-2 break-words text-slate-900 sm:col-start-auto dark:text-slate-100">
                            {{ r.frase }}
                        </span>
                    </li>
                </ul>
            </div>
        </div>
        <div v-if="diario && diario.altre" class="border-t border-slate-100 bg-slate-50 px-4 py-2.5 text-xs font-semibold text-slate-500 dark:border-slate-800 dark:bg-slate-900/60 dark:text-slate-400">
            <button type="button" class="underline" :disabled="caricamento" data-testid="mostra-altre-diario" @click="leggi(diario.dopo)">
                {{ caricamento ? 'Arrivano…' : 'Mostra altre' }}
            </button>
        </div>
    </section>
</template>
